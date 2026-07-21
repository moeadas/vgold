<?php
/**
 * Victory Genomics CRM - Authentication & Security Functions
 * SiteGround compatible (PHP 7.4+ / 8.x)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Start secure session with hardened settings
 */
function startSecureSession() {
    // When running inside the unified VGold shell, the bridge has already
    // started the shared VGold session — do NOT start a second one.
    if (defined('VGOLD_BRIDGE_LOADED')) {
        sendSecurityHeaders();
        return;
    }
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', $isHttps ? 1 : 0);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        session_name(SESSION_NAME);
        session_start();
    }
    // Send security headers on every request
    sendSecurityHeaders();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Require login — redirect to login page if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        // Inside the VGold shell, send unauthenticated users to the unified
        // login (SPA root) instead of the standalone CRM login page.
        if (defined('VGOLD_BRIDGE_LOADED')) {
            header('Location: /');
        } else {
            header('Location: /login.php');
        }
        exit;
    }
}

/**
 * Check user role using hierarchy
 */
function hasRole($requiredRole) {
    if (!isLoggedIn()) return false;

    $roleHierarchy = [
        'Viewer'        => 1,
        'Sales Rep'     => 2,
        'Sales Manager' => 3,
        'Admin'         => 4,
    ];

    $userRole = $_SESSION['role'] ?? 'Viewer';
    $userLevel = $roleHierarchy[$userRole] ?? 0;

    if (is_array($requiredRole)) {
        foreach ($requiredRole as $role) {
            $requiredLevel = $roleHierarchy[$role] ?? 0;
            if ($userLevel >= $requiredLevel) return true;
        }
        return false;
    }

    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
    return $userLevel >= $requiredLevel;
}

/**
 * Require specific role — die with JSON or redirect
 */
function requireRole($requiredRole) {
    if (!hasRole($requiredRole)) {
        if (isApiRequest()) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Access denied']));
        }
        http_response_code(403);
        die('Access denied. You do not have permission to view this page.');
    }
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Authenticate user with rate limiting
 */
function authenticateUser($username, $password) {
    // Simple rate limiting via session
    $now = time();
    $attempts = $_SESSION['login_attempts'] ?? [];
    // Clean old attempts (older than 15 min)
    $attempts = array_filter($attempts, function($ts) use ($now) {
        return ($now - $ts) < 900;
    });

    if (count($attempts) >= 5) {
        return false; // Too many attempts
    }

    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT user_id, username, email, password_hash, full_name, role, status 
        FROM users 
        WHERE username = :username AND status = 'Active'
    ");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        // Update last login
        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $updateStmt->execute([$user['user_id']]);

        // Set session variables
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];

        // Clear login attempts
        unset($_SESSION['login_attempts']);

        logActivity($user['user_id'], 'Login', 'System', null, 'User logged in');
        return true;
    }

    // Record failed attempt
    $attempts[] = $now;
    $_SESSION['login_attempts'] = $attempts;
    return false;
}

/**
 * Logout user
 */
function logoutUser() {
    if (isLoggedIn()) {
        logActivity($_SESSION['user_id'], 'Logout', 'System', null, 'User logged out');
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: /login.php');
    exit;
}

/**
 * Hash password (bcrypt)
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    return $errors;
}

/**
 * Log activity
 */
function logActivity($userId, $action, $entityType, $entityId = null, $details = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details,
            $ipAddress,
            $userAgent ? substr($userAgent, 0, 255) : null,
        ]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * Get current user info from session
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'user_id'   => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'email'     => $_SESSION['email'],
        'full_name' => $_SESSION['full_name'],
        'role'      => $_SESSION['role'],
    ];
}

/**
 * Sanitize input
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Generate CSRF token (per-session, rotated on login)
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF input field
 */
function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Verify CSRF token — works for both POST forms and JSON API requests
 */
function verifyCSRFToken($token = null) {
    if ($token === null) {
        // Try POST, then JSON body
        $token = $_POST['csrf_token'] ?? null;
        if ($token === null) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $token = $data['csrf_token'] ?? null;
        }
    }
    return isset($_SESSION['csrf_token']) && $token !== null && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF — die if invalid
 */
function requireCSRF() {
    if (!verifyCSRFToken()) {
        if (isApiRequest()) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Invalid or expired request token. Please refresh and try again.']));
        }
        http_response_code(403);
        die('Invalid request token. Please go back and try again.');
    }
}

/**
 * Check if current request is an API/JSON request
 */
function isApiRequest() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return (stripos($contentType, 'application/json') !== false)
        || (stripos($accept, 'application/json') !== false)
        || (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
}

/**
 * Check if admin is currently impersonating another user
 */
function isImpersonating() {
    return !empty($_SESSION['impersonate_original_user_id']);
}

/**
 * Get the original admin info when impersonating
 */
function getOriginalAdmin() {
    if (!isImpersonating()) return null;
    return [
        'user_id'   => $_SESSION['impersonate_original_user_id'],
        'username'  => $_SESSION['impersonate_original_username'],
        'full_name' => $_SESSION['impersonate_original_full_name'],
        'role'      => $_SESSION['impersonate_original_role'],
    ];
}

/**
 * Switch to another user (admin-only impersonation)
 */
function switchToUser($targetUserId) {
    if (!hasRole('Admin')) {
        return ['success' => false, 'message' => 'Only admins can switch users.'];
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT user_id, username, email, full_name, role FROM users WHERE user_id = ? AND status = 'Active'");
    $stmt->execute([$targetUserId]);
    $target = $stmt->fetch();

    if (!$target) {
        return ['success' => false, 'message' => 'User not found or inactive.'];
    }

    // Save original admin session (only if not already impersonating)
    if (!isImpersonating()) {
        $_SESSION['impersonate_original_user_id']   = $_SESSION['user_id'];
        $_SESSION['impersonate_original_username']   = $_SESSION['username'];
        $_SESSION['impersonate_original_email']      = $_SESSION['email'];
        $_SESSION['impersonate_original_full_name']  = $_SESSION['full_name'];
        $_SESSION['impersonate_original_role']       = $_SESSION['role'];
    }

    // Switch session to target user
    $_SESSION['user_id']   = $target['user_id'];
    $_SESSION['username']  = $target['username'];
    $_SESSION['email']     = $target['email'];
    $_SESSION['full_name'] = $target['full_name'];
    $_SESSION['role']      = $target['role'];

    logActivity($_SESSION['impersonate_original_user_id'], 'Switch User', 'User', $target['user_id'],
        "Admin switched to user: {$target['full_name']} ({$target['username']})");

    return ['success' => true, 'message' => "Switched to {$target['full_name']}"];
}

/**
 * Switch back to original admin account
 */
function switchBack() {
    if (!isImpersonating()) {
        return ['success' => false, 'message' => 'Not currently impersonating any user.'];
    }

    $targetName = $_SESSION['full_name'];

    // Restore original admin session
    $_SESSION['user_id']   = $_SESSION['impersonate_original_user_id'];
    $_SESSION['username']  = $_SESSION['impersonate_original_username'];
    $_SESSION['email']     = $_SESSION['impersonate_original_email'];
    $_SESSION['full_name'] = $_SESSION['impersonate_original_full_name'];
    $_SESSION['role']      = $_SESSION['impersonate_original_role'];

    // Clear impersonation data
    unset(
        $_SESSION['impersonate_original_user_id'],
        $_SESSION['impersonate_original_username'],
        $_SESSION['impersonate_original_email'],
        $_SESSION['impersonate_original_full_name'],
        $_SESSION['impersonate_original_role']
    );

    logActivity($_SESSION['user_id'], 'Switch Back', 'User', null,
        "Admin switched back from user: $targetName");

    return ['success' => true, 'message' => "Switched back to {$_SESSION['full_name']}"];
}
?>
