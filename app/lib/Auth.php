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