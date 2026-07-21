<?php
/**
 * Victory Genomics CRM — Notifications API Endpoint
 * Handles: unread count, list, mark-read, mark-all-read
 *
 * NOTE: The createNotification() function lives in includes/notification-helper.php
 * so other files can require_once it without triggering endpoint routing.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notification-helper.php';

header('Content-Type: application/json');
// Bust SiteGround dynamic cache
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-LiteSpeed-Cache-Control: no-cache');

startSecureSession();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? '';
$userId = getCurrentUserId();
$db = Database::getInstance();

switch ($action) {
    case 'unread_count':
        getUnreadCount($db, $userId);
        break;
    case 'list':
        getNotificationsList($db, $userId);
        break;
    case 'mark_read':
        markRead($db, $userId);
        break;
    case 'mark_all_read':
        markAllRead($db, $userId);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

/**
 * Get unread notification count — called by polling every 30s
 */
function getUnreadCount($db, $userId) {
    try {
        $count = $db->query(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
            [$userId]
        )->fetchColumn();
        echo json_encode(['success' => true, 'count' => intval($count)]);
    } catch (\Exception $e) {
        // Table may not exist yet on very first call
        echo json_encode(['success' => true, 'count' => 0]);
    }
}

/**
 * Get recent notifications (last 50)
 */
function getNotificationsList($db, $userId) {
    try {
        $notifications = $db->query(
            "SELECT n.*, l.contact_person, l.company_name
             FROM notifications n
             LEFT JOIN leads l ON n.lead_id = l.lead_id
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC
             LIMIT 50",
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $notifications]);
    } catch (\Exception $e) {
        echo json_encode(['success' => true, 'data' => []]);
    }
}

/**
 * Mark a single notification as read
 */
function markRead($db, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $notifId = intval($input['notification_id'] ?? 0);
    if (!$notifId) {
        echo json_encode(['success' => false, 'message' => 'Notification ID required']);
        return;
    }

    $db->query(
        "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?",
        [$notifId, $userId]
    );

    echo json_encode(['success' => true]);
}

/**
 * Mark all notifications as read
 */
function markAllRead($db, $userId) {
    $db->query(
        "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
        [$userId]
    );

    echo json_encode(['success' => true]);
}
