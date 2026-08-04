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

/**
 * Does this user send through Microsoft Graph rather than workspace SMTP?
 *
 * The tokens live on VGo's `users` table, keyed by VGo's own id. They cannot be
 * read through Database::findOne('users', ...) — the CrmRewritingPDO bridge
 * rewrites the bare name `users` to `crm_users`, which is a different table with
 * a different key. Go straight to DB:: instead.
 */
function emailUserUsesGraph($userId) {
    try {
        require_once __DIR__ . '/../../app/lib/MsMail.php';
        $st = MsMail::status($userId);
        return !empty($st['connected']);
    } catch (Throwable $e) {
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

// $_SESSION['user_id'] is VGo's users.id (Auth::login sets it), so the legacy
// findOne('users', ...) was querying crm_users by the wrong key. Only the lead
// lookup still needs the bridge.
require_once __DIR__ . '/../../app/lib/MsMail.php';
try {
    $db = Database::getInstance();
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

// Determine send method: the user's own mailbox via Graph, else workspace SMTP.
// accessToken() refreshes silently when the stored one has expired.
$msAccessToken = MsMail::accessToken($userId);
$usesGraph     = !empty($msAccessToken);

// Process file attachments. Done here, after the send method is known, because
// Graph and SMTP have very different size ceilings.
$limits      = emailAttachmentLimits($usesGraph);
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

if ($usesGraph) {
    // ===== The user's own Microsoft 365 mailbox =====
    // Lands in their Outlook Sent Items, and replies come back to them.
    try {
        $result = MsMail::send($msAccessToken, $to, $subject, $body, $cc, $attachments);
        if ($result !== true) jsonError('Failed to send email: ' . $result, 500);
    } catch (Throwable $e) {
        jsonError('Error sending email: ' . $e->getMessage(), 500);
    }
} else {
    // ===== Workspace SMTP fallback =====
    // Nobody has per-user SMTP credentials any more (those columns went away in
    // the migration), so fall back to the workspace mail account rather than
    // refusing to send. The message carries the rep's name and their address as
    // Reply-To, so the lead still replies to a person.
    require_once __DIR__ . '/../includes/mailer.php';
    // crmSendEmail() has no attachment support. Say so rather than silently
    // dropping the files the user just picked.
    if (!empty($attachments)) {
        jsonError('Attachments need your own mailbox. Sign out and sign back in with Microsoft, '
            . 'then try again — or send this one without attachments.', 400);
    }
    $replyTo = $currentUser['email'] ?? null;
    $res = crmSendEmail($to, $subject, $body, $currentUser['full_name'] ?? null, null, $replyTo);
    if (empty($res['success'])) {
        $err = is_array($res) ? (string)($res['error'] ?? '') : '';
        jsonError($err !== ''
            ? 'Failed to send email: ' . $err
            : 'Email is not configured yet. Sign out and sign back in with Microsoft to send from your own mailbox.', 500);
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





