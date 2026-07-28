<?php
/**
 * Victory Genomics CRM - Send Email API
 * Sends email via Microsoft Graph API (OAuth2) and logs as interaction.
 * Falls back to SMTP if OAuth2 is not configured.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();
header('Content-Type: application/json');

/** Parse a php.ini shorthand size ("8M", "1G", "512K") into bytes. */
function emailIniBytes($value) {
    $value = trim((string)$value);
    if ($value === '') return 0;
    $unit = strtolower($value[strlen($value) - 1]);
    $num  = (float)$value;
    switch ($unit) {
        case 'g': $num *= 1024; // no break
        case 'm': $num *= 1024; // no break
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

/**
 * The attachment budget that actually applies to this send.
 *
 * Microsoft Graph's sendMail caps the whole request at 4MB and base64 inflates
 * bytes by 4/3, so a Graph sender really only has ~3MB. Advertising 10MB there
 * would just mean a long upload followed by a failed send. PHP's own
 * upload_max_filesize / post_max_size can be lower still, so both are folded in.
 */
function emailAttachmentLimits($usesGraph) {
    $perFile = 10 * 1024 * 1024;
    $total   = 25 * 1024 * 1024;
    if ($usesGraph) { $perFile = 3 * 1024 * 1024; $total = 3 * 1024 * 1024; }

    $upload = emailIniBytes(ini_get('upload_max_filesize'));
    $post   = emailIniBytes(ini_get('post_max_size'));
    if ($upload > 0) $perFile = min($perFile, $upload);
    // Leave headroom for the subject, body and form fields.
    if ($post > 0)   $total   = max(0, min($total, $post - 512 * 1024));
    if ($total > 0)  $perFile = min($perFile, $total);

    return ['per_file' => $perFile, 'total' => $total, 'method' => $usesGraph ? 'graph' : 'smtp'];
}

/** Does this user send through Microsoft Graph rather than SMTP? */
function emailUserUsesGraph($userId) {
    try {
        $db = Database::getInstance();
        $u  = $db->findOne('users', ['user_id' => $userId]);
        return !empty($u['ms_access_token']) && !empty($u['ms_refresh_token']);
    } catch (Exception $e) {
        return false;
    }
}

// GET ?limits=1 — the compose page asks for the real budget before you pick
// files, so the limit shown in the UI is the limit the send will honour.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['limits'])) {
    $cu = getCurrentUser();
    jsonSuccess('ok', ['limits' => emailAttachmentLimits(emailUserUsesGraph($cu['user_id']))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) $data = $_POST;

// A body larger than post_max_size reaches PHP with $_POST and $_FILES both
// empty, which would otherwise surface as a bogus "invalid CSRF token".
if (empty($data) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    jsonError('The message is larger than this server accepts (limit ' . ini_get('post_max_size')
        . '). Remove or shrink an attachment and try again.', 413);
}

// CSRF
$token = $data['csrf_token'] ?? null;
if (!verifyCSRFToken($token)) {
    jsonError('Invalid or expired request token. Please refresh the page.', 403);
}

$leadId   = intval($data['lead_id'] ?? 0);
$to       = trim($data['to'] ?? '');
$subject  = trim($data['subject'] ?? '');
$body     = trim($data['body'] ?? '');
$cc       = trim($data['cc'] ?? '');

if (!$leadId) jsonError('Lead ID is required', 400);
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) jsonError('Valid recipient email is required', 400);
if (!$subject) jsonError('Subject is required', 400);
if (!$body) jsonError('Email body is required', 400);

$currentUser = getCurrentUser();
$userId = $currentUser['user_id'];

/**
 * Convert plain-text URLs into clickable <a> tags.
 * Must be called AFTER htmlspecialchars() and BEFORE nl2br().
 */
function autoLinkUrls($text) {
    // Match http(s) URLs — the text is already HTML-escaped so there are no
    // raw '&' chars; ampersands appear as '&amp;' which we include in the URL.
    return preg_replace(
        '~(https?://[^\s<>"\')]+)~i',
        '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:#0071e3;text-decoration:underline;">$1</a>',
        $text
    );
}

// Get user settings
try {
    $db = Database::getInstance();
    $user = $db->findOne('users', ['user_id' => $userId]);
} catch (Exception $e) {
    jsonError('Error loading user settings: ' . $e->getMessage(), 500);
}

// Get lead info
try {
    $lead = $db->findOne('leads', ['lead_id' => $leadId]);
    if (!$lead) jsonError('Lead not found', 404);
} catch (Exception $e) {
    jsonError('Error loading lead', 500);
}

// Determine send method: Microsoft Graph (OAuth2) or SMTP fallback
$msAccessToken  = $user['ms_access_token'] ?? '';
$msRefreshToken = $user['ms_refresh_token'] ?? '';
$msTokenExpires = $user['ms_token_expires'] ?? '';

// Process file attachments. Done here, after the send method is known, because
// Graph and SMTP have very different size ceilings.
$limits      = emailAttachmentLimits(!empty($msAccessToken) && !empty($msRefreshToken));
$attachments = [];
if (!empty($_FILES['attachments'])) {
    $files     = $_FILES['attachments'];
    $totalSize = 0;
    $count     = is_array($files['name']) ? count($files['name']) : 1;
    $mb        = function ($b) { return round($b / 1048576, 1) . 'MB'; };

    for ($i = 0; $i < $count; $i++) {
        $name  = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $size  = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            jsonError("Attachment '$name' is larger than this server accepts (" . $mb($limits['per_file']) . ' max).', 400);
        }
        if ($error !== UPLOAD_ERR_OK) continue;
        if ($size > $limits['per_file']) {
            jsonError("Attachment '$name' exceeds the " . $mb($limits['per_file']) . ' per-file limit'
                . ($limits['method'] === 'graph' ? ' for Microsoft 365 sending.' : '.'), 400);
        }
        $totalSize += $size;
        if ($totalSize > $limits['total']) {
            jsonError('Total attachment size exceeds the ' . $mb($limits['total']) . ' limit'
                . ($limits['method'] === 'graph' ? ' for Microsoft 365 sending.' : '.'), 400);
        }

        $content = file_get_contents($tmp);
        $attachments[] = [
            'name'    => $name,
            'content' => base64_encode($content),
            'size'    => $size,
            'type'    => mime_content_type($tmp) ?: 'application/octet-stream',
        ];
    }
}

if (!empty($msAccessToken) && !empty($msRefreshToken)) {
    // ===== MICROSOFT GRAPH API =====
    // Check if token is expired and refresh if needed
    if (!empty($msTokenExpires) && strtotime($msTokenExpires) <= time()) {
        $newTokens = refreshMicrosoftToken($msRefreshToken);
        if ($newTokens === false) {
            jsonError('Microsoft token expired. Please reconnect your Office 365 account in Profile > Email Settings.', 401);
        }
        $msAccessToken = $newTokens['access_token'];
        // Update stored tokens
        $db->update('users', [
            'ms_access_token'  => $newTokens['access_token'],
            'ms_refresh_token' => $newTokens['refresh_token'] ?? $msRefreshToken,
            'ms_token_expires' => date('Y-m-d H:i:s', time() + intval($newTokens['expires_in'] ?? 3600)),
        ], ['user_id' => $userId]);
    }

    try {
        $result = sendViaGraph($msAccessToken, $to, $subject, $body, $currentUser['full_name'], $cc, $attachments);
        if ($result !== true) {
            jsonError('Failed to send email: ' . $result, 500);
        }
    } catch (Exception $e) {
        jsonError('Error sending email: ' . $e->getMessage(), 500);
    }
} else {
    // ===== SMTP FALLBACK =====
    $smtpHost  = $user['smtp_host'] ?? '';
    $smtpPort  = intval($user['smtp_port'] ?? 587);
    $smtpEmail = $user['smtp_email'] ?? '';
    $smtpPass  = $user['smtp_password'] ?? '';
    $smtpEnc   = $user['smtp_encryption'] ?? 'tls';

    if (empty($smtpEmail) || empty($smtpPass)) {
        jsonError('Email not configured. Go to Profile > Email Settings to connect your Microsoft Office 365 account.', 400);
    }

    $smtpPass = base64_decode($smtpPass);

    try {
        $result = sendSmtpEmail($smtpHost, $smtpPort, $smtpEmail, $smtpPass, $smtpEnc, $to, $subject, $body, $currentUser['full_name'], $cc, $attachments);
        if ($result !== true) {
            jsonError('Failed to send email: ' . $result, 500);
        }
    } catch (Exception $e) {
        jsonError('Error sending email: ' . $e->getMessage(), 500);
    }
}

// Log as interaction
try {
    $interactionData = [
        'lead_id'          => $leadId,
        'user_id'          => $userId,
        'interaction_type'  => 'Email',
        'interaction_date'  => date('Y-m-d H:i:s'),
        'subject'           => $subject,
        'notes'             => "To: $to" . ($cc ? "\nCc: $cc" : "")
                                . (!empty($attachments) ? "\nAttachments: " . implode(', ', array_column($attachments, 'name')) : "")
                                . "\n\n" . $body,
        'created_at'        => date('Y-m-d H:i:s'),
    ];
    $interactionId = $db->insert('interactions', $interactionData);
    $db->update('leads', ['updated_at' => date('Y-m-d H:i:s')], ['lead_id' => $leadId]);

    $leadName = $lead['company_name'] ?: $lead['contact_person'] ?: 'Lead #' . $leadId;
    logActivity($userId, 'Sent Email', 'Interaction', $interactionId, "Email to $to re: $subject (Lead: $leadName)");

    jsonSuccess('Email sent successfully and logged as interaction', [
        'interaction_id' => $interactionId,
    ]);
} catch (Exception $e) {
    // Email was sent but logging failed
    jsonSuccess('Email sent successfully (but logging failed: ' . $e->getMessage() . ')', []);
}


/**
 * Send email via Microsoft Graph API
 */
function sendViaGraph($accessToken, $toEmail, $subject, $body, $fromName, $cc = '', $attachments = []) {
    // Build recipient list
    $toRecipients = [['emailAddress' => ['address' => $toEmail]]];
    
    $ccRecipients = [];
    if ($cc) {
        $ccParts = array_map('trim', explode(',', $cc));
        foreach ($ccParts as $ccAddr) {
            if (filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
                $ccRecipients[] = ['emailAddress' => ['address' => $ccAddr]];
            }
        }
    }

    // Build the email message
    $message = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => '<html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">'
                    . nl2br(autoLinkUrls(htmlspecialchars($body)))
                    . '</body></html>',
            ],
            'toRecipients' => $toRecipients,
        ],
        'saveToSentItems' => true,  // Saves to Outlook Sent Items
    ];

    if (!empty($ccRecipients)) {
        $message['message']['ccRecipients'] = $ccRecipients;
    }

    // Add attachments (Graph API supports base64 inline attachments up to 3MB each)
    if (!empty($attachments)) {
        $graphAttachments = [];
        foreach ($attachments as $att) {
            $graphAttachments[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $att['name'],
                'contentType'  => $att['type'],
                'contentBytes' => $att['content'], // already base64-encoded
            ];
        }
        $message['message']['attachments'] = $graphAttachments;
    }

    // Send via Microsoft Graph
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://graph.microsoft.com/v1.0/me/sendMail',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($message),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return "Network error: " . $curlError;
    }

    // 202 Accepted = success for sendMail
    if ($httpCode === 202 || $httpCode === 200) {
        return true;
    }

    // Parse error
    $errorData = json_decode($response, true);
    $errorMsg = $errorData['error']['message'] ?? "HTTP $httpCode";
    error_log("Microsoft Graph sendMail error ($httpCode): " . $response);
    return $errorMsg;
}


/**
 * Refresh Microsoft OAuth2 access token using refresh token
 */
function refreshMicrosoftToken($refreshToken) {
    $tokenUrl = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/token';

    $postData = [
        'client_id'     => MS_CLIENT_ID,
        'client_secret' => MS_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token',
        'scope'         => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read offline_access',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tokenUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['access_token'])) {
        return $data;
    }

    error_log("Microsoft token refresh failed: " . $response);
    return false;
}


/**
 * Send email via raw SMTP socket (fallback when OAuth2 not configured).
 */
function sendSmtpEmail($host, $port, $fromEmail, $password, $encryption, $toEmail, $subject, $body, $fromName, $cc = '', $attachments = []) {
    $timeout = 30;
    $crlf = "\r\n";

    if ($encryption === 'ssl') {
        $sock = @fsockopen('ssl://' . $host, $port, $errno, $errstr, $timeout);
    } else {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
    }

    if (!$sock) {
        return "Could not connect to $host:$port — $errstr ($errno)";
    }

    $readResponse = function() use ($sock) {
        $response = '';
        while ($line = fgets($sock, 4096)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    };

    $sendCmd = function($cmd, $expectedCode) use ($sock, $readResponse, $crlf) {
        fwrite($sock, $cmd . $crlf);
        $resp = $readResponse();
        $code = intval(substr($resp, 0, 3));
        if ($code !== $expectedCode) {
            return "SMTP error ($code): " . trim($resp);
        }
        return true;
    };

    $banner = $readResponse();
    if (intval(substr($banner, 0, 3)) !== 220) {
        fclose($sock);
        return "SMTP banner error: " . trim($banner);
    }

    $r = $sendCmd("EHLO " . gethostname(), 250);
    if ($r !== true) { fclose($sock); return $r; }

    if ($encryption === 'tls') {
        $r = $sendCmd("STARTTLS", 220);
        if ($r !== true) { fclose($sock); return $r; }
        $crypto = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        if (!$crypto) { fclose($sock); return "TLS handshake failed"; }
        $r = $sendCmd("EHLO " . gethostname(), 250);
        if ($r !== true) { fclose($sock); return $r; }
    }

    $r = $sendCmd("AUTH LOGIN", 334);
    if ($r !== true) { fclose($sock); return $r; }
    $r = $sendCmd(base64_encode($fromEmail), 334);
    if ($r !== true) { fclose($sock); return $r; }
    $r = $sendCmd(base64_encode($password), 235);
    if ($r !== true) { fclose($sock); return "Authentication failed. Check email/password."; }

    $r = $sendCmd("MAIL FROM:<$fromEmail>", 250);
    if ($r !== true) { fclose($sock); return $r; }
    $r = $sendCmd("RCPT TO:<$toEmail>", 250);
    if ($r !== true) { fclose($sock); return $r; }

    $ccAddresses = [];
    if ($cc) {
        $ccParts = array_map('trim', explode(',', $cc));
        foreach ($ccParts as $ccAddr) {
            if (filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
                $r = $sendCmd("RCPT TO:<$ccAddr>", 250);
                if ($r !== true) { fclose($sock); return $r; }
                $ccAddresses[] = $ccAddr;
            }
        }
    }

    $r = $sendCmd("DATA", 354);
    if ($r !== true) { fclose($sock); return $r; }

    $mixedBoundary = md5(uniqid(time() . 'mixed'));
    $altBoundary   = md5(uniqid(time() . 'alt'));
    $messageId = '<' . uniqid('vgcrm_', true) . '@' . parse_url(APP_URL, PHP_URL_HOST) . '>';
    $date = date('r');
    $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';

    $headers  = "Date: $date" . $crlf;
    $headers .= "From: $encodedFrom" . $crlf;
    $headers .= "To: <$toEmail>" . $crlf;
    if (!empty($ccAddresses)) {
        $headers .= "Cc: " . implode(', ', array_map(function($a) { return "<$a>"; }, $ccAddresses)) . $crlf;
    }
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=" . $crlf;
    $headers .= "Message-ID: $messageId" . $crlf;
    $headers .= "MIME-Version: 1.0" . $crlf;
    $headers .= "X-Mailer: VictoryGenomicsCRM/2.0" . $crlf;

    $plainBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body));
    $htmlBody = '<html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">'
        . nl2br(autoLinkUrls(htmlspecialchars($body))) . '</body></html>';

    if (!empty($attachments)) {
        // multipart/mixed (contains multipart/alternative + attachments)
        $headers .= "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"" . $crlf;

        $message  = $headers . $crlf;
        $message .= "--$mixedBoundary" . $crlf;
        $message .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"" . $crlf . $crlf;

        // Plain text part
        $message .= "--$altBoundary" . $crlf;
        $message .= "Content-Type: text/plain; charset=UTF-8" . $crlf;
        $message .= "Content-Transfer-Encoding: 8bit" . $crlf . $crlf;
        $message .= $plainBody . $crlf . $crlf;

        // HTML part
        $message .= "--$altBoundary" . $crlf;
        $message .= "Content-Type: text/html; charset=UTF-8" . $crlf;
        $message .= "Content-Transfer-Encoding: 8bit" . $crlf . $crlf;
        $message .= $htmlBody . $crlf . $crlf;
        $message .= "--$altBoundary--" . $crlf . $crlf;

        // Attachment parts
        foreach ($attachments as $att) {
            $message .= "--$mixedBoundary" . $crlf;
            $message .= "Content-Type: " . $att['type'] . "; name=\"" . $att['name'] . "\"" . $crlf;
            $message .= "Content-Disposition: attachment; filename=\"" . $att['name'] . "\"" . $crlf;
            $message .= "Content-Transfer-Encoding: base64" . $crlf . $crlf;
            $message .= chunk_split($att['content'], 76, $crlf);
        }
        $message .= "--$mixedBoundary--" . $crlf;
    } else {
        // No attachments — simple multipart/alternative
        $headers .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"" . $crlf;

        $message  = $headers . $crlf;
        $message .= "--$altBoundary" . $crlf;
        $message .= "Content-Type: text/plain; charset=UTF-8" . $crlf;
        $message .= "Content-Transfer-Encoding: 8bit" . $crlf . $crlf;
        $message .= $plainBody . $crlf . $crlf;
        $message .= "--$altBoundary" . $crlf;
        $message .= "Content-Type: text/html; charset=UTF-8" . $crlf;
        $message .= "Content-Transfer-Encoding: 8bit" . $crlf . $crlf;
        $message .= $htmlBody . $crlf . $crlf;
        $message .= "--$altBoundary--" . $crlf;
    }

    $message = str_replace("\r\n.\r\n", "\r\n..\r\n", $message);
    fwrite($sock, $message);

    $r = $sendCmd(".", 250);
    if ($r !== true) { fclose($sock); return $r; }

    fwrite($sock, "QUIT" . $crlf);
    fclose($sock);
    return true;
}
