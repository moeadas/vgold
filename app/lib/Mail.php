<?php
// VGo Mail — SMTP email sending using raw socket
require_once __DIR__ . '/Crypto.php';
class Mail {
    private static $smtpSettings = null;

    public static function loadSettings($workspaceId) {
        if (self::$smtpSettings !== null) return self::$smtpSettings;
        $settings = DB::fetch("SELECT * FROM smtp_settings WHERE workspace_id = ? AND is_active = 1", [$workspaceId]);
        self::$smtpSettings = $settings ?: null;
        return self::$smtpSettings;
    }

    public static function isConfigured($workspaceId) {
        return self::loadSettings($workspaceId) !== null;
    }

    private static function readResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    }

    private static function sendCmd($socket, $cmd) {
        fwrite($socket, $cmd . "\r\n");
        return self::readResponse($socket);
    }

    public static function send($toEmail, $toName, $subject, $htmlBody, $textBody = '') {
        $wsId = Auth::workspaceId();
        $cfg = self::loadSettings($wsId);
        if (!$cfg) return false;

        $host = $cfg['host'];
        $port = (int)$cfg['port'];
        $username = $cfg['username'];
        $password = Crypto::decrypt($cfg['password']); // encrypted at rest (H6)
        $fromName = $cfg['from_name'] ?: 'VGo';
        $fromEmail = $cfg['from_email'];
        $encryption = $cfg['encryption'] ?: 'ssl';

        // Connect. Verify the server certificate to prevent MITM (M3).
        // 'ssl' (implicit TLS, usually port 465) wraps the socket immediately;
        // 'tls' (STARTTLS, usually port 587) upgrades a plaintext connection after EHLO.
        $useImplicitSsl = ($encryption === 'ssl');
        $useStartTls = ($encryption === 'tls');
        $remote = ($useImplicitSsl ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ]
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) return false;

        // Read greeting (multi-line)
        $greeting = self::readResponse($socket);
        if (strpos($greeting, '220') !== 0) { fclose($socket); return false; }

        // EHLO
        self::sendCmd($socket, 'EHLO vgo.victorygenomics.com');

        // STARTTLS upgrade for explicit-TLS connections
        if ($useStartTls) {
            $tlsResp = self::sendCmd($socket, 'STARTTLS');
            if (strpos($tlsResp, '220') !== 0) { fclose($socket); return false; }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                fclose($socket);
                return false;
            }
            // Re-issue EHLO over the now-encrypted channel
            self::sendCmd($socket, 'EHLO vgo.victorygenomics.com');
        }

        // AUTH LOGIN
        self::sendCmd($socket, 'AUTH LOGIN');
        self::sendCmd($socket, base64_encode($username));
        $authResp = self::sendCmd($socket, base64_encode($password));
        if (strpos($authResp, '235') !== 0) { fclose($socket); return false; }

        // MAIL FROM
        self::sendCmd($socket, "MAIL FROM:<$fromEmail>");
        // RCPT TO
        self::sendCmd($socket, "RCPT TO:<$toEmail>");
        // DATA
        self::sendCmd($socket, 'DATA');

        // Build message
        $boundary = md5(time() . rand());
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: $fromName <$fromEmail>\r\n";
        $headers .= "To: $toName <$toEmail>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";

        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= ($textBody ?: strip_tags($htmlBody)) . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--$boundary--\r\n";

        fwrite($socket, $headers . $body . "\r\n.\r\n");
        $dataResp = self::readResponse($socket);

        self::sendCmd($socket, 'QUIT');
        fclose($socket);

        return strpos($dataResp, '250') === 0;
    }

    public static function sendNotification($userId, $subject, $htmlBody, $type = 'general') {
        $user = DB::fetch("SELECT name, email FROM users WHERE id = ?", [$userId]);
        if (!$user) return false;

        $settings = DB::fetch("SELECT email_notify_pref FROM user_settings WHERE user_id = ?", [$userId]);
        $pref = $settings['email_notify_pref'] ?? 'all';

        if ($pref === 'none') return false;
        if ($pref === 'mentions' && !in_array($type, ['mention', 'message'])) return false;

        $wsId = Auth::workspaceId();
        if (!self::isConfigured($wsId)) return false;

        return self::send($user['email'], $user['name'], $subject, $htmlBody);
    }

    public static function sendToUsers($userIds, $subject, $htmlBody, $type = 'general') {
        $sent = 0;
        foreach ($userIds as $uid) {
            if (self::sendNotification($uid, $subject, $htmlBody, $type)) {
                $sent++;
            }
        }
        return $sent;
    }
}