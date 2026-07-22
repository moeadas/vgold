<?php
/**
 * Victory Genomics CRM — include-safe mailer.
 *
 * crmSendEmail() sends an HTML email using the workspace SMTP settings from
 * the `settings` table, with a clean fallback chain:
 *   1. PHPMailer (if the library files are present)
 *   2. Raw-socket SMTP (SSL or STARTTLS + AUTH LOGIN) — no dependencies
 *   3. PHP mail() as the last resort
 *
 * This file has NO side effects on include (no session, no auth, no routing),
 * so the automation engine and any API can require_once it safely. It exists
 * because requiring api/email.php for its sendEmailViaSMTP() executed that
 * endpoint's dispatcher and exit()ed the whole request.
 *
 * Returns ['success' => bool, 'error' => string|null].
 */

require_once __DIR__ . '/../config/database.php';

if (!function_exists('crmMailerGetSetting')) {
    function crmMailerGetSetting($key, $default = '') {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $pdo = Database::getInstance()->getConnection();
                foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
                    $cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (\Throwable $e) {
                error_log('crmMailerGetSetting: ' . $e->getMessage());
            }
        }
        $val = $cache[$key] ?? '';
        return ($val !== '' && $val !== null) ? $val : $default;
    }
}

if (!function_exists('crmSendEmail')) {
    function crmSendEmail($to, $subject, $html, $fromName = null, $fromEmail = null, $replyTo = null) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => "Invalid recipient address: $to"];
        }

        $smtpHost = crmMailerGetSetting('smtp_host');
        $smtpPort = intval(crmMailerGetSetting('smtp_port', '465'));
        $smtpUser = crmMailerGetSetting('smtp_username');
        $smtpPass = crmMailerGetSetting('smtp_password');
        $smtpEnc  = strtolower(crmMailerGetSetting('smtp_encryption', 'ssl'));

        // Settings may hold an encrypted value (enc:v1:...) — decrypt when the
        // VGold Crypto helper is available; plaintext passes through unchanged.
        if ($smtpPass !== '' && strpos($smtpPass, 'enc:') === 0) {
            $cryptoFile = dirname(__DIR__, 2) . '/app/lib/Crypto.php';
            if (is_file($cryptoFile)) {
                require_once $cryptoFile;
                try { $smtpPass = Crypto::decrypt($smtpPass); } catch (\Throwable $e) { /* keep as-is */ }
            }
        }

        $fromEmail = $fromEmail ?: crmMailerGetSetting('email_from_address', $smtpUser);
        $fromName  = $fromName  ?: crmMailerGetSetting('email_from_name', 'Victory Genomics');

        // 1) PHPMailer, when the library has been dropped into includes/
        $phpmailerPath = __DIR__ . '/PHPMailer.php';
        if (is_file($phpmailerPath) && $smtpHost !== '') {
            require_once $phpmailerPath;
            require_once __DIR__ . '/SMTP.php';
            require_once __DIR__ . '/Exception.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtpUser;
                $mail->Password   = $smtpPass;
                $mail->SMTPSecure = $smtpEnc;
                $mail->Port       = $smtpPort;
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($fromEmail, $fromName);
                if ($replyTo) $mail->addReplyTo($replyTo);
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html));
                $mail->send();
                return ['success' => true, 'error' => null];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
            }
        }

        // 2) Raw-socket SMTP (dependency-free)
        if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
            $err = crmRawSmtpSend($smtpHost, $smtpPort, $smtpEnc, $smtpUser, $smtpPass,
                                  $fromEmail, $fromName, $to, $subject, $html, $replyTo);
            if ($err === true) return ['success' => true, 'error' => null];
            error_log("crmSendEmail SMTP failed ($to): $err");
            return ['success' => false, 'error' => $err];
        }

        // 3) Last resort: PHP mail()
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        if ($fromEmail) $headers .= "From: " . mb_encode_mimeheader($fromName, 'UTF-8') . " <$fromEmail>\r\n";
        if ($replyTo)   $headers .= "Reply-To: $replyTo\r\n";
        $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $html, $headers);
        return $ok ? ['success' => true, 'error' => null]
                   : ['success' => false, 'error' => 'PHP mail() failed (no SMTP configured)'];
    }
}

if (!function_exists('crmRawSmtpSend')) {
    /**
     * Minimal SMTP client: implicit SSL or STARTTLS, AUTH LOGIN, one recipient,
     * multipart/alternative (plain + the HTML exactly as supplied).
     * Returns true on success, or an error string.
     */
    function crmRawSmtpSend($host, $port, $encryption, $user, $pass,
                            $fromEmail, $fromName, $to, $subject, $html, $replyTo = null) {
        $crlf = "\r\n";
        $timeout = 30;

        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
        ]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $sock = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) return "Could not connect to $host:$port — $errstr ($errno)";
        stream_set_timeout($sock, $timeout);

        $read = function () use ($sock) {
            $resp = '';
            while ($line = fgets($sock, 4096)) {
                $resp .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $resp;
        };
        $cmd = function ($c, $expect) use ($sock, $read, $crlf) {
            fwrite($sock, $c . $crlf);
            $resp = $read();
            if (intval(substr($resp, 0, 3)) !== $expect) return "SMTP error: " . trim($resp);
            return true;
        };

        if (intval(substr($read(), 0, 3)) !== 220) { fclose($sock); return 'SMTP banner error'; }
        $host_id = function_exists('gethostname') ? gethostname() : 'localhost';
        if (($r = $cmd("EHLO $host_id", 250)) !== true) { fclose($sock); return $r; }

        if ($encryption === 'tls') {
            if (($r = $cmd("STARTTLS", 220)) !== true) { fclose($sock); return $r; }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock); return 'TLS handshake failed';
            }
            if (($r = $cmd("EHLO $host_id", 250)) !== true) { fclose($sock); return $r; }
        }

        if (($r = $cmd("AUTH LOGIN", 334)) !== true) { fclose($sock); return $r; }
        if (($r = $cmd(base64_encode($user), 334)) !== true) { fclose($sock); return $r; }
        if (($r = $cmd(base64_encode($pass), 235)) !== true) { fclose($sock); return 'SMTP authentication failed'; }
        if (($r = $cmd("MAIL FROM:<$fromEmail>", 250)) !== true) { fclose($sock); return $r; }
        if (($r = $cmd("RCPT TO:<$to>", 250)) !== true) { fclose($sock); return $r; }
        if (($r = $cmd("DATA", 354)) !== true) { fclose($sock); return $r; }

        $boundary  = md5(uniqid((string)mt_rand(), true));
        $appHost   = defined('APP_URL') ? (parse_url(APP_URL, PHP_URL_HOST) ?: 'victorygenomics.com') : 'victorygenomics.com';
        $plainBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html));

        $msg  = "Date: " . date('r') . $crlf;
        $msg .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>" . $crlf;
        $msg .= "To: <$to>" . $crlf;
        if ($replyTo) $msg .= "Reply-To: <$replyTo>" . $crlf;
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=" . $crlf;
        $msg .= "Message-ID: <" . uniqid('vgcrm_', true) . "@$appHost>" . $crlf;
        $msg .= "MIME-Version: 1.0" . $crlf;
        $msg .= "X-Mailer: VictoryGenomicsCRM/2.0" . $crlf;
        $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"" . $crlf . $crlf;
        $msg .= "--$boundary" . $crlf;
        $msg .= "Content-Type: text/plain; charset=UTF-8" . $crlf;
        $msg .= "Content-Transfer-Encoding: 8bit" . $crlf . $crlf;
        $msg .= $plainBody . $crlf . $crlf;
        $msg .= "--$boundary" . $crlf;
        $msg .= "Content-Type: text/html; charset=UTF-8" . $crlf;
        $msg .= "Content-Transfer-Encoding: 8bit" . $crlf . $crlf;
        $msg .= $html . $crlf . $crlf;
        $msg .= "--$boundary--" . $crlf;

        // Dot-stuffing
        $msg = preg_replace('/\r\n\./', "\r\n..", $msg);

        fwrite($sock, $msg . $crlf);
        if (($r = $cmd(".", 250)) !== true) { fclose($sock); return $r; }
        $cmd("QUIT", 221);
        fclose($sock);
        return true;
    }
}
