<?php
require_once __DIR__ . '/Crypto.php';

/**
 * Per-user Microsoft Graph mail tokens.
 *
 * Sending a CRM email to a lead goes out of that user's own mailbox, so it needs
 * a DELEGATED token, not the application credentials in config/graph.php (that
 * app only holds Sites.Selected, for the SharePoint backup store). The token is
 * captured during "Sign in with Microsoft" — see AuthController::microsoftCallback —
 * because asking 15 people to visit a settings page and press Connect is a step
 * nobody remembers to take.
 *
 * Tokens are encrypted at rest with Crypto (AES-256-GCM), the same way SMTP
 * passwords are. The columns are added lazily, following the house
 * ensureXTable() pattern, so there is no migration step on deploy.
 */
class MsMail
{
    /** Delegated scopes. offline_access is what yields the refresh token. */
    const SCOPES = 'openid profile email User.Read Mail.Send offline_access';

    public static function ensureColumns()
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $cols = [
            'ms_access_token'    => "ADD COLUMN `ms_access_token` TEXT NULL",
            'ms_refresh_token'   => "ADD COLUMN `ms_refresh_token` TEXT NULL",
            'ms_token_expires'   => "ADD COLUMN `ms_token_expires` DATETIME NULL",
            'ms_connected_email' => "ADD COLUMN `ms_connected_email` VARCHAR(191) NULL",
        ];
        try {
            $have = array_column(DB::fetchAll("SHOW COLUMNS FROM `users`"), 'Field');
            foreach ($cols as $name => $ddl) {
                if (in_array($name, $have, true)) continue;
                try { DB::query("ALTER TABLE `users` $ddl"); }
                catch (\Throwable $e) { error_log('MsMail::ensureColumns ' . $name . ': ' . $e->getMessage()); }
            }
        } catch (\Throwable $e) {
            error_log('MsMail::ensureColumns: ' . $e->getMessage());
        }
    }

    /** Store the token set from a login or refresh. Values arrive as plaintext. */
    public static function store($userId, array $tokens, $connectedEmail = null)
    {
        self::ensureColumns();
        $userId = (int)$userId;
        if (!$userId || empty($tokens['access_token'])) return false;

        $row = [
            'ms_access_token'  => Crypto::encrypt($tokens['access_token']),
            'ms_token_expires' => date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 3600)),
        ];
        // A refresh response often omits refresh_token; keep the one we hold.
        if (!empty($tokens['refresh_token'])) {
            $row['ms_refresh_token'] = Crypto::encrypt($tokens['refresh_token']);
        }
        if ($connectedEmail) $row['ms_connected_email'] = mb_substr((string)$connectedEmail, 0, 191);

        try { DB::update('users', $row, 'id = ?', [$userId]); return true; }
        catch (\Throwable $e) { error_log('MsMail::store: ' . $e->getMessage()); return false; }
    }

    public static function forget($userId)
    {
        self::ensureColumns();
        try {
            DB::update('users', [
                'ms_access_token' => null, 'ms_refresh_token' => null,
                'ms_token_expires' => null, 'ms_connected_email' => null,
            ], 'id = ?', [(int)$userId]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /** Connection state for the Settings card. Never returns token material. */
    public static function status($userId)
    {
        self::ensureColumns();
        try {
            $u = DB::fetch("SELECT ms_refresh_token, ms_token_expires, ms_connected_email
                              FROM users WHERE id = ?", [(int)$userId]);
        } catch (\Throwable $e) { $u = null; }
        if (!$u || empty($u['ms_refresh_token'])) {
            return ['connected' => false, 'email' => null, 'expires_at' => null];
        }
        return [
            'connected'  => true,
            'email'      => $u['ms_connected_email'],
            'expires_at' => $u['ms_token_expires'],
        ];
    }

    /**
     * A usable access token, refreshed if it has expired or is about to.
     * Returns null when the user has never connected or the refresh token has
     * lapsed — callers should fall back rather than fail hard.
     */
    public static function accessToken($userId)
    {
        self::ensureColumns();
        $userId = (int)$userId;
        try {
            $u = DB::fetch("SELECT ms_access_token, ms_refresh_token, ms_token_expires
                              FROM users WHERE id = ?", [$userId]);
        } catch (\Throwable $e) { return null; }
        if (!$u || empty($u['ms_refresh_token'])) return null;

        // 120s of headroom so a token cannot expire mid-request.
        $expires = !empty($u['ms_token_expires']) ? strtotime((string)$u['ms_token_expires']) : 0;
        if (!empty($u['ms_access_token']) && $expires > time() + 120) {
            return Crypto::decrypt($u['ms_access_token']);
        }

        $refresh = Crypto::decrypt($u['ms_refresh_token']);
        if ($refresh === '') return null;
        $new = self::refresh($refresh);
        if (!$new) { return null; }
        self::store($userId, $new);
        return $new['access_token'];
    }

    private static function refresh($refreshToken)
    {
        $cfgFile = dirname(__DIR__, 2) . '/config/graph.php';
        if (!is_file($cfgFile)) return null;
        $cfg = require $cfgFile;

        $params = [
            'client_id'     => $cfg['client_id'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope'         => self::SCOPES,
        ];
        if (($cfg['app_auth'] ?? 'certificate') === 'secret' && !empty($cfg['client_secret'])) {
            $params['client_secret'] = $cfg['client_secret'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $cfg['login_authority'] . '/oauth2/v2.0/token',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $d = json_decode((string)$body, true);
        if (empty($d['access_token'])) {
            error_log('MsMail::refresh failed: ' . substr((string)$body, 0, 400));
            return null;
        }
        return $d;
    }

    /**
     * Send through Graph as the signed-in user. Returns true, or an error string.
     * saveToSentItems puts a copy in the sender's own Outlook Sent Items, which
     * is the whole point of sending per-user rather than from a service mailbox.
     */
    public static function send($accessToken, $to, $subject, $htmlBody, $cc = '', array $attachments = [])
    {
        $addr = function ($csv) {
            $out = [];
            foreach (preg_split('/[;,]+/', (string)$csv) as $e) {
                $e = trim($e);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = ['emailAddress' => ['address' => $e]];
            }
            return $out;
        };
        $msg = ['message' => [
            'subject' => (string)$subject,
            'body'    => ['contentType' => 'HTML', 'content' => (string)$htmlBody],
            'toRecipients' => $addr($to),
        ], 'saveToSentItems' => true];
        if ($cc !== '' && $addr($cc)) $msg['message']['ccRecipients'] = $addr($cc);
        if ($attachments) {
            $msg['message']['attachments'] = array_map(function ($a) {
                return ['@odata.type' => '#microsoft.graph.fileAttachment',
                        'name' => $a['name'], 'contentType' => $a['type'], 'contentBytes' => $a['content']];
            }, $attachments);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://graph.microsoft.com/v1.0/me/sendMail',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($msg),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return 'Network error: ' . $err;
        if ($code === 202 || $code === 200) return true;
        $d = json_decode((string)$resp, true);
        error_log("MsMail::send Graph error ($code): " . substr((string)$resp, 0, 500));
        return $d['error']['message'] ?? ('HTTP ' . $code);
    }
}
