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
        $_SESSION['user_id'] = $userId;
        $_SESSION['workspace_id'] = $workspaceId;
        session_regenerate_id(true);
    }

    public static function logout() {
        session_destroy();
        $_SESSION = [];
    }

    public static function check() {
        return isset($_SESSION['user_id']);
    }

    public static function userId() {
        return $_SESSION['user_id'] ?? null;
    }

    public static function workspaceId() {
        return $_SESSION['workspace_id'] ?? null;
    }

    // Original CRM user_id this signed-in account maps to (or null if none).
    public static function crmUserId() {
        return $_SESSION['crm_user_id'] ?? null;
    }

    public static function authProvider() {
        return $_SESSION['auth_provider'] ?? 'password';
    }

    /**
     * Bridge the unified VGold session into the session variables the legacy
     * CRM screens expect ($_SESSION['user_id'|'username'|'email'|'full_name'|
     * 'role']). Called by the /crm/* mount (Phase 3) so CRM pages run unchanged
     * under one shared login. Returns false if the signed-in user has no CRM
     * account linked.
     *
     * NOTE: the CRM's own `user_id` (crm_user_id) — NOT the unified id — is what
     * CRM tables key on, so we expose that as $_SESSION['user_id'] within the
     * CRM context. VGold's own id remains available as $_SESSION['vgold_user_id'].
     */
    public static function bridgeToCrm() {
        if (!self::check()) return false;
        $crmId = self::crmUserId();
        if (!$crmId) return false;
        $u = DB::fetch("SELECT * FROM users WHERE id = ?", [self::userId()]);
        if (!$u) return false;
        $_SESSION['vgold_user_id'] = (int)self::userId();
        $_SESSION['user_id']   = (int)$crmId;             // CRM tables key on this
        $_SESSION['username']  = $u['crm_username'] ?? ($u['email'] ?? '');
        $_SESSION['email']     = $u['email'] ?? '';
        $_SESSION['full_name'] = $u['name'] ?? '';
        $_SESSION['role']      = $u['crm_role'] ?? 'Sales Rep'; // CRM role vocabulary
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