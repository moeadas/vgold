<?php
/**
 * VGo — password policy and reset tokens.
 *
 * One place for every password rule so the login form, the self-service change,
 * the admin set and the reset link cannot drift apart — they previously
 * disagreed (6 characters in two places, 8 in a third), which rejected valid
 * passwords with a message the UI had not warned about.
 *
 * Tokens: 32 random bytes, handed out once in the email link and stored only as
 * a SHA-256 hash. Single use, one hour, and issuing a new one retires any
 * outstanding tokens for that user.
 */
class PasswordReset {

    const MIN_LENGTH   = 8;
    const TTL_MINUTES  = 60;
    /** Self-service requests allowed per account per hour, before we stop sending. */
    const MAX_PER_HOUR = 5;

    /** Only local-password accounts have a password to reset at all. */
    public static function isPasswordAccount($user) {
        return ($user['auth_provider'] ?? 'password') === 'password';
    }

    /**
     * Validate a candidate password. Returns null when acceptable, otherwise the
     * message to show the person.
     */
    public static function validate($password) {
        $password = (string)$password;
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > 200) {
            return 'Password must be 200 characters or fewer.';
        }
        if (trim($password) === '') {
            return 'Password cannot be only spaces.';
        }
        return null;
    }

    public static function hash($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Issue a reset token for a user and return the raw token — the only time it
     * exists in plaintext. Retires any outstanding tokens first, so a second
     * "reset my password" email invalidates the first link.
     */
    public static function issue($userId, $requestedBy = null) {
        Schema::ensurePasswordResets();
        $now = date('Y-m-d H:i:s');
        DB::query(
            "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
            [$now, (int)$userId]
        );
        $token = bin2hex(random_bytes(32));
        DB::insert('password_resets', [
            'user_id'      => (int)$userId,
            'token_hash'   => hash('sha256', $token),
            'expires_at'   => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
            'requested_by' => $requestedBy ? (int)$requestedBy : null,
            'requested_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
        return $token;
    }

    /**
     * Resolve a raw token to its user, or null when it is unknown, expired or
     * already spent. Never say which — that distinction only helps an attacker.
     */
    public static function resolve($token) {
        $token = (string)$token;
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) return null;
        Schema::ensurePasswordResets();
        $row = DB::fetch(
            "SELECT r.*, u.id AS uid, u.name, u.email, u.auth_provider, u.is_active
               FROM password_resets r JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = ? LIMIT 1",
            [hash('sha256', $token)]
        );
        if (!$row) return null;
        if ($row['used_at'] !== null) return null;
        if (strtotime($row['expires_at']) <= time()) return null;
        if (!(int)$row['is_active']) return null;
        if (!self::isPasswordAccount($row)) return null;
        return $row;
    }

    /** Spend a token and set the new password, in that order. */
    public static function consume($token, $newPassword) {
        $row = self::resolve($token);
        if (!$row) return false;

        // Claim the token before touching the password: if two requests race,
        // only the one that flips used_at gets to proceed.
        DB::query(
            "UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL",
            [date('Y-m-d H:i:s'), (int)$row['id']]
        );
        $claimed = DB::fetch("SELECT used_at FROM password_resets WHERE id = ?", [(int)$row['id']]);
        if (empty($claimed['used_at'])) return false;

        DB::update('users', ['password' => self::hash($newPassword)], 'id = ?', [(int)$row['user_id']]);
        return $row;
    }

    /** Has this account asked for too many resets in the last hour? */
    public static function isThrottled($userId) {
        Schema::ensurePasswordResets();
        $row = DB::fetch(
            "SELECT COUNT(*) c FROM password_resets
              WHERE user_id = ? AND requested_by IS NULL AND created_at > (NOW() - INTERVAL 1 HOUR)",
            [(int)$userId]
        );
        return (int)($row['c'] ?? 0) >= self::MAX_PER_HOUR;
    }

    /** The link that goes in the email. */
    public static function link($token) {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return $base . '/#reset/' . $token;
    }

    /**
     * Send the reset email. Returns true when it went out. Never throws — a mail
     * failure must not tell the caller whether the account exists.
     */
    public static function sendEmail($user, $token, $byAdminName = null) {
        try {
            $link = self::link($token);
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $intro = $byAdminName
                ? htmlspecialchars($byAdminName, ENT_QUOTES, 'UTF-8') . ' has started a password reset for your VGo account.'
                : 'We received a request to reset the password on your VGo account.';

            $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;line-height:1.55">'
                . '<h2 style="font-size:18px;margin:0 0 12px">Reset your VGo password</h2>'
                . '<p>Hi ' . $name . ',</p>'
                . '<p>' . $intro . '</p>'
                . '<p style="margin:22px 0"><a href="' . $safeLink . '" '
                . 'style="background:#8E6B3A;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:700;display:inline-block">'
                . 'Choose a new password</a></p>'
                . '<p style="font-size:13px;color:#666">This link works once and expires in ' . self::TTL_MINUTES . ' minutes. '
                . 'If you did not ask for it you can ignore this email — your current password still works.</p>'
                . '<p style="font-size:12px;color:#888;word-break:break-all">' . $safeLink . '</p>'
                . '</div>';

            $text = "Reset your VGo password\n\n" . strip_tags($intro) . "\n\n" . $link
                . "\n\nThis link works once and expires in " . self::TTL_MINUTES . " minutes.\n";

            return (bool)Mail::send($user['email'], $user['name'] ?? '', 'Reset your VGo password', $html, $text);
        } catch (\Throwable $e) {
            error_log('PasswordReset::sendEmail: ' . $e->getMessage());
            return false;
        }
    }
}
