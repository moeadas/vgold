<?php
// Simple session-based auth
class Auth {
    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (defined('APP_ENV') && APP_ENV === 'production');

            // Own the session store. On shared hosting the default save_path is a
            // directory we share with every other account on the box, and their PHP
            // garbage-collects it on THEIR session.gc_maxlifetime (SiteGround ships
            // 1440s). That is why a 7-day cookie was still landing on the login
            // screen after ~24 minutes: the cookie survived, the session file did
            // not. A private directory plus our own gc_maxlifetime fixes it.
            // Falls back silently to the platform default if the directory cannot
            // be created or written — never break a request over this.
            $store = dirname(__DIR__, 2) . '/storage/sessions';
            if (!is_dir($store)) @mkdir($store, 0700, true);
            if (is_dir($store) && is_writable($store)) {
                ini_set('session.save_path', $store);
                ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
                ini_set('session.gc_probability', 1);
                ini_set('session.gc_divisor', 100);
            }
            ini_set('session.use_strict_mode', 1);

            ini_set('session.cookie_lifetime', SESSION_LIFETIME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        self::rememberRestore();
    }

    // ===== "Keep me signed in" =====
    // A PHP session is the wrong tool for a months-long login: PHP only emits the
    // session cookie when the session is new, so its expiry never slides forward
    // and the user is dropped a fixed 7 days after signing in. This is the standard
    // selector/validator token instead — the validator is stored only as a hash,
    // rotates on every use (so a stolen cookie is single-use and detectable), and
    // is immune to session garbage collection entirely.
    const REMEMBER_COOKIE = 'vgo_remember';
    const REMEMBER_DAYS   = 90;

    private static function rememberTable() {
        static $done = false;
        if ($done) return true;
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `remember_tokens` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `workspace_id` INT NOT NULL,
                `selector` CHAR(24) NOT NULL,
                `validator_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at` DATETIME NULL,
                `user_agent` VARCHAR(255) NULL,
                UNIQUE KEY `uq_selector` (`selector`),
                KEY `idx_user` (`user_id`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function rememberCookie($value, $expires) {
        if (headers_sent()) return;
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => (defined('APP_ENV') && APP_ENV === 'production'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // Issue a fresh token after a successful sign-in.
    public static function rememberIssue($userId, $workspaceId) {
        if (!class_exists('DB') || !self::rememberTable()) return false;
        try {
            $selector  = bin2hex(random_bytes(12));   // 24 chars
            $validator = bin2hex(random_bytes(32));   // 64 chars
            $expires   = time() + (self::REMEMBER_DAYS * 86400);
            DB::insert('remember_tokens', [
                'user_id'        => (int)$userId,
                'workspace_id'   => (int)$workspaceId,
                'selector'       => $selector,
                'validator_hash' => hash('sha256', $validator),
                'expires_at'     => date('Y-m-d H:i:s', $expires),
                'user_agent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            // Opportunistic cleanup — cheap, indexed, and keeps the table flat.
            DB::query("DELETE FROM remember_tokens WHERE expires_at < NOW()");
            self::rememberCookie($selector . ':' . $validator, $expires);
            $_COOKIE[self::REMEMBER_COOKIE] = $selector . ':' . $validator;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Re-establish a session from the cookie. Called once per request from init().
    public static function rememberRestore() {
        static $tried = false;
        if ($tried || self::check()) return self::check();
        $tried = true;
        $raw = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($raw) || substr_count($raw, ':') !== 1) return false;
        [$selector, $validator] = explode(':', $raw, 2);
        if (strlen($selector) !== 24 || strlen($validator) !== 64) return false;
        if (!class_exists('DB')) return false;

        try {
            $row = DB::fetch(
                "SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()", [$selector]);
            if (!$row) { self::rememberForget(); return false; }

            // Constant-time — never compare a secret with ===.
            if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
                // Known selector, wrong validator: the cookie was copied and replayed.
                // Revoke every token this user holds and force a real sign-in.
                DB::delete('remember_tokens', 'user_id = ?', [(int)$row['user_id']]);
                self::rememberForget();
                return false;
            }

            $u = DB::fetch("SELECT id, is_active FROM users WHERE id = ?", [(int)$row['user_id']]);
            if (!$u || (int)($u['is_active'] ?? 1) === 0) {
                DB::delete('remember_tokens', 'user_id = ?', [(int)$row['user_id']]);
                self::rememberForget();
                return false;
            }

            self::login((int)$row['user_id'], (int)$row['workspace_id']);

            // Rotate the validator on every use and slide the window forward.
            $next    = bin2hex(random_bytes(32));
            $expires = time() + (self::REMEMBER_DAYS * 86400);
            DB::update('remember_tokens', [
                'validator_hash' => hash('sha256', $next),
                'expires_at'     => date('Y-m-d H:i:s', $expires),
                'last_used_at'   => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int)$row['id']]);
            self::rememberCookie($selector . ':' . $next, $expires);
            $_COOKIE[self::REMEMBER_COOKIE] = $selector . ':' . $next;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Drop the cookie and, if present, the row behind it.
    public static function rememberForget() {
        $raw = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (is_string($raw) && strpos($raw, ':') !== false && class_exists('DB')) {
            try { DB::delete('remember_tokens', 'selector = ?', [explode(':', $raw, 2)[0]]); }
            catch (Throwable $e) { /* table may not exist yet */ }
        }
        self::rememberCookie('', time() - 42000);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    public static function login($userId, $workspaceId) {
        unset($_SESSION['vgold_user_id'], $_SESSION['username'], $_SESSION['full_name'], $_SESSION['role']);
        $_SESSION['user_id'] = $userId;
        $_SESSION['workspace_id'] = $workspaceId;
        $user = DB::fetch("SELECT crm_user_id, crm_role, crm_username FROM users WHERE id = ?", [$userId]);
        $_SESSION['crm_user_id'] = $user['crm_user_id'] ?? null;
        $_SESSION['crm_role'] = $user['crm_role'] ?? null;
        $_SESSION['crm_username'] = $user['crm_username'] ?? null;
        session_regenerate_id(true);
    }

    public static function logout() {
        // Before the session goes, so every logout path (including the automatic
        // one in user() when an account is deactivated) revokes the token too.
        self::rememberForget();
        $_SESSION = [];
        // Expire the session cookie client-side (session_destroy alone leaves it).
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check() {
        return isset($_SESSION['vgold_user_id']) || isset($_SESSION['user_id']);
    }

    public static function userId() {
        return $_SESSION['vgold_user_id'] ?? $_SESSION['user_id'] ?? null;
    }

    public static function workspaceId() {
        return $_SESSION['workspace_id'] ?? null;
    }

    public static function crmUserId() {
        if (!empty($_SESSION['crm_user_id'])) return (int)$_SESSION['crm_user_id'];
        $vgoldId = self::userId();
        if (!$vgoldId) return null;
        $user = DB::fetch("SELECT crm_user_id, crm_role, crm_username FROM users WHERE id = ?", [$vgoldId]);
        if (!$user || empty($user['crm_user_id'])) return null;
        $_SESSION['crm_user_id'] = (int)$user['crm_user_id'];
        $_SESSION['crm_role'] = $user['crm_role'] ?? null;
        $_SESSION['crm_username'] = $user['crm_username'] ?? null;
        return (int)$user['crm_user_id'];
    }

    public static function authProvider() {
        return $_SESSION['auth_provider'] ?? 'password';
    }

    // Populate the session vocabulary expected by the original CRM while
    // preserving vgold_user_id as the canonical identity for all VGo APIs.
    public static function bridgeToCrm() {
        if (!self::check()) return false;
        $vgoldId = self::userId();
        $crmId = self::crmUserId();
        if (!$vgoldId) return false;
        $user = DB::fetch("SELECT id, name, email, role, crm_user_id, crm_role, crm_username FROM users WHERE id = ?", [$vgoldId]);
        if (!$user) return false;
        if (!$crmId) {
            $legacy = DB::fetch("SELECT user_id, role, username FROM crm_users WHERE LOWER(email) = LOWER(?) LIMIT 1", [$user['email']]);
            if (!$legacy) {
                $base = preg_replace('/[^a-z0-9._-]/i', '', explode('@', $user['email'])[0] ?? '') ?: ('vgold' . $vgoldId);
                $username = $base;
                $suffix = 0;
                while (DB::fetch("SELECT user_id FROM crm_users WHERE username = ? LIMIT 1", [$username])) {
                    $suffix++;
                    $username = $base . '-' . $vgoldId . ($suffix > 1 ? '-' . $suffix : '');
                }
                $legacyRole = $user['role'] === 'admin' ? 'Admin' : 'Sales Rep';
                $crmId = (int)DB::insert('crm_users', [
                    'username' => substr($username, 0, 50),
                    'email' => substr($user['email'], 0, 100),
                    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                    'full_name' => substr($user['name'], 0, 100),
                    'role' => $legacyRole,
                    'status' => 'Active',
                ]);
                $legacy = ['user_id' => $crmId, 'role' => $legacyRole, 'username' => $username];
            } else {
                $crmId = (int)$legacy['user_id'];
            }
            DB::update('users', [
                'crm_user_id' => $crmId,
                'crm_role' => $legacy['role'],
                'crm_username' => $legacy['username'],
            ], 'id = ?', [$vgoldId]);
            $user['crm_role'] = $legacy['role'];
            $user['crm_username'] = $legacy['username'];
            $_SESSION['crm_user_id'] = $crmId;
            $_SESSION['crm_role'] = $legacy['role'];
        }

        $_SESSION['vgold_user_id'] = (int)$vgoldId;
        $_SESSION['user_id'] = (int)$crmId;
        $_SESSION['username'] = $user['crm_username'] ?: $user['email'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['name'];
        $_SESSION['role'] = $user['crm_role'] ?: 'Sales Rep';
        return true;
    }

    public static function user() {
        if (!self::check()) return null;
        // is_active = 0 means the account was deactivated after this session began;
        // reject it so a disabled/compromised user loses access immediately.
        $u = DB::fetch("SELECT u.*, wm.role FROM users u JOIN workspace_members wm ON u.id = wm.user_id WHERE u.id = ? AND wm.workspace_id = ?", [self::userId(), self::workspaceId()]);
        if ($u && isset($u['is_active']) && (int)$u['is_active'] === 0) {
            self::logout();
            return null;
        }
        return $u;
    }

    public static function requireAuth() {
        self::init();
        if (!self::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    public static function requireAdmin() {
        $user = self::user();
        if (!$user || $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }
}
