<?php
/**
 * Victory Genomics CRM - Switch User API
 * Allows admins to impersonate other users without logging in/out.
 * POST /api/switch-user.php
 * Body: { csrf_token, action: "switch"|"switch_back", user_id (for switch) }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireCSRF();

// Accept both form POST and JSON body
$action  = $_POST['action']  ?? null;
$userId  = $_POST['user_id'] ?? null;

if (!$action) {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);
    $action = $data['action']  ?? null;
    $userId = $data['user_id'] ?? null;
}

switch ($action) {
    case 'switch':
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID is required.']);
            exit;
        }
        // Must be admin (or currently impersonating with admin origin)
        $isAdmin = isImpersonating()
            ? (getOriginalAdmin()['role'] === 'Admin')
            : hasRole('Admin');
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only admins can switch users.']);
            exit;
        }
        // If already impersonating, switch back first
        if (isImpersonating()) {
            switchBack();
        }
        $result = switchToUser(intval($userId));
        echo json_encode($result);
        break;

    case 'switch_back':
        $result = switchBack();
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action. Use "switch" or "switch_back".']);
        break;
}
