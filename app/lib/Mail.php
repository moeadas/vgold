<?php
// VGo Mail — SMTP email sending using raw socket
require_once __DIR__ . '/Crypto.php';
class Mail {
    private static $cache = [];

    /**
     * Which workspace's SMTP config applies?
     *
     * Public routes — password reset above all — have no session, so
     * Auth::workspaceId() is null there and a workspace-scoped lookup finds
     * nothing. Fall back to the only active configuration; this deployment has
     * a single workspace, and silently dropping the mail is far worse than
     * using it.
     */
    private static function resolveWorkspaceId($workspaceId = null) {
        if ($workspaceId) return (int)$workspaceId;
        if (class_exists('Auth')) {
            $ws = Auth::workspaceId();
            if ($ws) return (int)$ws;
        }
        try {
            $row = DB::fetch("SELECT workspace_id FROM smtp_settings WHERE is_active = 1 ORDER BY workspace_id ASC LIMIT 1");
            if ($row) return (int)$row['workspace_id'];
        } catch (\Throwable $e) { /* fall through */ }
        return 0;
    }

    /**
     * The CRM keeps its own SMTP credentials in crm_settings, and on this
     * deployment those are the ones that were actually filled in — VGold's
     * smtp_settings table was never populated, so every Workflow email
     * (assignments, mentions, password resets) was being dropped on the floor.
     *
     * Rather than require the same credentials be typed twice, fall back to the
     * CRM's. Anything saved in Settings → SMTP still takes precedence.
     */
    private static function crmFallbackConfig() {
        try {
            $rows = DB::fetchAll(
                "SELECT setting_key, setting_value FROM crm_settings
                  WHERE setting_key IN ('smtp_host','smtp_port','smtp_username','smtp_password',
                                        'smtp_encryption','email_from_address','email_from_name')"
            );
        } catch (\Throwable $e) {
            return null;
        }
        $s = [];
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];
        if (empty($s['smtp_host']) || empty($s['smtp_username']) || empty($s['smtp_password'])) return null;

        return [
            'host'       => $s['smtp_host'],
            'port'       => (int)($s['smtp_port'] ?? 465) ?: 465,
            'username'   => $s['smtp_username'],
            // Crypto::decrypt passes plaintext through unchanged, so this covers
            // both the encrypted and the legacy plaintext form.
            'password'   => $s['smtp_password'],
            'from_name'  => $s['email_from_name'] ?: 'VGold',
            'from_email' => $s['email_from_address'] ?: $s['smtp_username'],
            'encryption' => strtolower($s['smtp_encryption'] ?: 'ssl'),
            'source'     => 'crm',
        ];
    }

    public static function loadSettings($workspaceId = null) {
        $wsId = self::resolveWorkspaceId($workspaceId);
        $key = 'ws' . $wsId;
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        $settings = null;
        if ($wsId) {
            try {
                $settings = DB::fetch("SELECT * FROM smtp_settings WHERE workspace_id = ? AND is_active = 1", [$wsId]);
            } catch (\Throwable $e) { $settings = null; }
        }
        if ($settings) {
            $settings['source'] = 'workspace';
        } else {
            $settings = self::crmFallbackConfig();
        }
        return self::$cache[$key] = ($settings ?: null);
    }

    public static function isConfigured($workspaceId = null) {
        return self::loadSettings($workspaceId) !== null;
    }

    /**
     * Where the outgoing mail configuration is coming from, for the Settings
     * screen — so "no emails are arriving" is visible instead of silent.
     */
    public static function status($workspaceId = null) {
        $cfg = self::loadSettings($workspaceId);
        if (!$cfg) return ['configured' => false, 'source' => null, 'host' => null, 'from_email' => null];
        return [
            'configured' => true,
            'source'     => $cfg['source'] ?? 'workspace',
            'host'       => $cfg['host'] ?? null,
            'from_email' => $cfg['from_email'] ?? null,
        ];
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

    public static function send($toEmail, $toName, $subject, $htmlBody, $textBody = '', $workspaceId = null) {
        $cfg = self::loadSettings($workspaceId);
        if (!$cfg) {
            error_log('Mail::send: no SMTP configuration — dropping "' . $subject . '" to ' . $toEmail);
            return false;
        }

        $host = $cfg['host'];
        $port = (int)$cfg['port'];
        $username = $cfg['username'];
        $password = Crypto::decrypt($cfg['password']); // encrypted at rest (H6)
        $fromName = $cfg['from_name'] ?: 'VGold';
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
        if (!$socket) {
            error_log(sprintf('Mail::send: cannot reach %s (%s) %s', $remote, $errno, $errstr));
            return false;
        }

        // Read greeting (multi-line)
        $greeting = self::readResponse($socket);
        if (strpos($greeting, '220') !== 0) {
            error_log('Mail::send: unexpected greeting from ' . $host . ': ' . trim($greeting));
            fclose($socket); return false;
        }

        // EHLO
        self::sendCmd($socket, 'EHLO vgold.victorygenomics.com');

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
            self::sendCmd($socket, 'EHLO vgold.victorygenomics.com');
        }

        // AUTH LOGIN
        self::sendCmd($socket, 'AUTH LOGIN');
        self::sendCmd($socket, base64_encode($username));
        $authResp = self::sendCmd($socket, base64_encode($password));
        if (strpos($authResp, '235') !== 0) {
            error_log('Mail::send: SMTP auth rejected by ' . $host . ' for ' . $username . ': ' . trim($authResp));
            fclose($socket); return false;
        }

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

        $accepted = strpos($dataResp, '250') === 0;
        if (!$accepted) error_log('Mail::send: ' . $host . ' refused the message: ' . trim($dataResp));
        return $accepted;
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
