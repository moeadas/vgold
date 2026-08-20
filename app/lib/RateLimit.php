<?php
/**
 * VGo — server-side attempt counters for authentication endpoints.
 *
 * The counters this replaces lived in $_SESSION, keyed by IP. That looked like
 * per-IP rate limiting but was really per-cookie: an attacker who dropped the
 * PHPSESSID on every request got a fresh session with a zeroed counter and could
 * guess passwords without limit. State that the client can reset is not a limit.
 *
 * Rows live in `auth_attempts` and are pruned opportunistically, so no cron is
 * needed (this host has no crontab binary anyway).
 *
 * Failure mode is deliberate: if the table cannot be created — a locked-down
 * grant, a full disk — ensure() returns false and the caller falls back to the
 * old session counter rather than locking every user out of the product. Never
 * let a hardening measure become an outage.
 */
class RateLimit {

    /** Buckets. Keep the strings short; the column is VARCHAR(16). */
    const LOGIN_IP    = 'login_ip';
    const LOGIN_USER  = 'login_user';
    const RESET_IP    = 'reset_ip';

    /** One office shares one NAT address, so the per-IP ceiling is generous and
     *  the tight limit is per-account — that is what an attacker actually needs. */
    const LOGIN_IP_MAX     = 30;
    const LOGIN_USER_MAX   = 5;
    const RESET_IP_MAX     = 10;
    const LOGIN_WINDOW     = 900;   // 15 minutes
    const RESET_WINDOW     = 3600;  // 1 hour

    private static $ready = null;

    /**
     * Create the table if it is missing and confirm the columns are really
     * there. Returns false rather than throwing, so a caller can degrade.
     */
    public static function ensure() {
        if (self::$ready !== null) return self::$ready;
        self::$ready = false;
        try {
            // Login is a hot path and this runs on every attempt, so probe first
            // and only reach for DDL when the table is genuinely missing —
            // CREATE TABLE IF NOT EXISTS still takes a metadata lock each time.
            try {
                DB::fetch("SELECT 1 FROM auth_attempts LIMIT 1");
                self::$ready = true;
                return self::$ready;
            } catch (Throwable $probe) {
                // Not there (or not readable) — fall through and try to create it.
            }
            DB::query(
                "CREATE TABLE IF NOT EXISTS auth_attempts (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    bucket VARCHAR(16) NOT NULL,
                    subject VARCHAR(190) NOT NULL,
                    attempted_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_bucket_subject_time (bucket, subject, attempted_at),
                    KEY idx_time (attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            // MySQL here runs without STRICT_TRANS_TABLES, so a write against a
            // missing column can pass silently. Confirm the shape before trusting it.
            $cols = DB::fetchAll("SHOW COLUMNS FROM auth_attempts");
            $names = array_map(fn($c) => $c['Field'], $cols ?: []);
            foreach (['bucket', 'subject', 'attempted_at'] as $need) {
                if (!in_array($need, $names, true)) return self::$ready;
            }
            self::$ready = true;
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /** The caller's IP, as a bucket subject. */
    public static function ip() {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 190);
    }

    /** Record one failed attempt. */
    public static function hit($bucket, $subject) {
        if (!self::ensure()) return;
        try {
            DB::insert('auth_attempts', [
                'bucket'       => substr((string)$bucket, 0, 16),
                'subject'      => substr((string)$subject, 0, 190),
                'attempted_at' => date('Y-m-d H:i:s'),
            ]);
            self::prune();
        } catch (Throwable $e) { /* a counter must never break a login */ }
    }

    /** Failed attempts for this subject inside the window. */
    public static function count($bucket, $subject, $windowSeconds) {
        if (!self::ensure()) return 0;
        try {
            $row = DB::fetch(
                "SELECT COUNT(*) AS n FROM auth_attempts
                  WHERE bucket = ? AND subject = ? AND attempted_at > ?",
                [
                    substr((string)$bucket, 0, 16),
                    substr((string)$subject, 0, 190),
                    date('Y-m-d H:i:s', time() - (int)$windowSeconds),
                ]
            );
            return (int)($row['n'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }

    public static function exceeded($bucket, $subject, $max, $windowSeconds) {
        return self::count($bucket, $subject, $windowSeconds) >= (int)$max;
    }

    /** Wipe a subject's history — called on a successful sign-in. */
    public static function clear($bucket, $subject) {
        if (!self::ensure()) return;
        try {
            DB::delete('auth_attempts', 'bucket = ? AND subject = ?', [
                substr((string)$bucket, 0, 16),
                substr((string)$subject, 0, 190),
            ]);
        } catch (Throwable $e) { /* ignore */ }
    }

    /** Opportunistic cleanup — roughly one call in twenty does the delete. */
    private static function prune() {
        if (random_int(1, 20) !== 1) return;
        try {
            DB::query("DELETE FROM auth_attempts WHERE attempted_at < ?",
                [date('Y-m-d H:i:s', time() - 86400)]);
        } catch (Throwable $e) { /* ignore */ }
    }
}
