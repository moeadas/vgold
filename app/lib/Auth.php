<?php
// Simple session-based auth
class Auth {
    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (defined('APP_ENV') && APP_ENV === 'production');
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
        session_destroy();
        $_SESSION = [];
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
    // preserving vgold_user_id as the canonical identity for all VGold APIs.
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
        return DB::fetch("SELECT u.*, wm.role FROM users u JOIN workspace_members wm ON u.id = wm.user_id WHERE u.id = ? AND wm.workspace_id = ?", [self::userId(), self::workspaceId()]);
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
