<?php
/**
 * Victory Genomics CRM — Knowledge Hub API
 * CRUD for link-based resource cards.
 * All users can READ; Sales Manager+ can CREATE/UPDATE/DELETE.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-LiteSpeed-Cache-Control: no-cache');

startSecureSession();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$db = Database::getInstance();

// ── Auto-create table if missing ────────────────────────────────────
try {
    $db->query("SELECT 1 FROM knowledge_hub_cards LIMIT 1");
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "doesn't exist") !== false
        || strpos($e->getMessage(), 'not found') !== false) {
        $db->getConnection()->exec("
            CREATE TABLE IF NOT EXISTS `knowledge_hub_cards` (
                `card_id`     int(11) NOT NULL AUTO_INCREMENT,
                `title`       varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `category`    varchar(100) DEFAULT NULL,
                `url`         varchar(1000) NOT NULL,
                `icon_color`  varchar(30) DEFAULT '#0071e3',
                `sort_order`  int(11) NOT NULL DEFAULT 0,
                `is_active`   tinyint(1) NOT NULL DEFAULT 1,
                `created_by`  int(11) DEFAULT NULL,
                `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`card_id`),
                KEY `idx_active_sort` (`is_active`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        error_log("KnowledgeHub: auto-created knowledge_hub_cards table");

        // Seed the existing "How to Sell" guide as first card
        $db->insert('knowledge_hub_cards', [
            'title'       => 'How to Sell',
            'description' => 'Complete sales routing guide — match the customer to the right product. Covers WGS Premium, VG Enthusiast, Specialist tests, pricing, upselling modules, and cheat sheet.',
            'category'    => 'Sales',
            'url'         => '/pages/knowledge-hub/how-to-sell.html',
            'icon_color'  => '#00B8D9',
            'sort_order'  => 1,
            'is_active'   => 1,
            'created_by'  => getCurrentUserId(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        listCards($db);
        break;
    case 'create':
        requireRole('Sales Manager');
        createCard($db);
        break;
    case 'update':
        requireRole('Sales Manager');
        updateCard($db);
        break;
    case 'delete':
        requireRole('Sales Manager');
        deleteCard($db);
        break;
    case 'reorder':
        requireRole('Sales Manager');
        reorderCards($db);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ── List all active cards ──────────────────────────────────────────
function listCards($db) {
    $cards = $db->query(
        "SELECT c.*, u.full_name as created_by_name
         FROM knowledge_hub_cards c
         LEFT JOIN users u ON c.created_by = u.user_id
         WHERE c.is_active = 1
         ORDER BY c.sort_order ASC, c.created_at ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $cards]);
}

// ── Create a new card ──────────────────────────────────────────────
function createCard($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    $token = $input['csrf_token'] ?? null;
    if (!verifyCSRFToken($token)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh.']);
        return;
    }

    $title = trim($input['title'] ?? '');
    $url   = trim($input['url'] ?? '');
    if ($title === '' || $url === '') {
        echo json_encode(['success' => false, 'message' => 'Title and URL are required.']);
        return;
    }

    // Get next sort order
    $maxSort = $db->query("SELECT COALESCE(MAX(sort_order), 0) FROM knowledge_hub_cards")->fetchColumn();

    $cardId = $db->insert('knowledge_hub_cards', [
        'title'       => $title,
        'description' => trim($input['description'] ?? ''),
        'category'    => trim($input['category'] ?? ''),
        'url'         => $url,
        'icon_color'  => $input['icon_color'] ?? '#0071e3',
        'sort_order'  => intval($maxSort) + 1,
        'is_active'   => 1,
        'created_by'  => getCurrentUserId(),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    logActivity(getCurrentUserId(), 'Created', 'KnowledgeHub', $cardId, "Created resource: $title");
    echo json_encode(['success' => true, 'card_id' => $cardId, 'message' => 'Resource added.']);
}

// ── Update an existing card ────────────────────────────────────────
function updateCard($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    $token = $input['csrf_token'] ?? null;
    if (!verifyCSRFToken($token)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh.']);
        return;
    }

    $cardId = intval($input['card_id'] ?? 0);
    if (!$cardId) {
        echo json_encode(['success' => false, 'message' => 'Card ID required.']);
        return;
    }

    $title = trim($input['title'] ?? '');
    $url   = trim($input['url'] ?? '');
    if ($title === '' || $url === '') {
        echo json_encode(['success' => false, 'message' => 'Title and URL are required.']);
        return;
    }

    $db->update('knowledge_hub_cards', [
        'title'       => $title,
        'description' => trim($input['description'] ?? ''),
        'category'    => trim($input['category'] ?? ''),
        'url'         => $url,
        'icon_color'  => $input['icon_color'] ?? '#0071e3',
    ], ['card_id' => $cardId]);

    logActivity(getCurrentUserId(), 'Updated', 'KnowledgeHub', $cardId, "Updated resource: $title");
    echo json_encode(['success' => true, 'message' => 'Resource updated.']);
}

// ── Soft-delete a card ─────────────────────────────────────────────
function deleteCard($db) {
    $input = json_decode(file_get_contents('php://input'), true);

    $token = $input['csrf_token'] ?? null;
    if (!verifyCSRFToken($token)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh.']);
        return;
    }

    $cardId = intval($input['card_id'] ?? 0);
    if (!$cardId) {
        echo json_encode(['success' => false, 'message' => 'Card ID required.']);
        return;
    }

    $db->update('knowledge_hub_cards', ['is_active' => 0], ['card_id' => $cardId]);
    logActivity(getCurrentUserId(), 'Deleted', 'KnowledgeHub', $cardId, "Removed resource card #$cardId");
    echo json_encode(['success' => true, 'message' => 'Resource removed.']);
}

// ── Reorder cards (drag & drop future use) ─────────────────────────
function reorderCards($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $order = $input['order'] ?? [];
    if (!is_array($order)) {
        echo json_encode(['success' => false, 'message' => 'Order array required.']);
        return;
    }

    $pdo = $db->getConnection();
    $stmt = $pdo->prepare("UPDATE knowledge_hub_cards SET sort_order = ? WHERE card_id = ?");
    foreach ($order as $i => $cardId) {
        $stmt->execute([$i + 1, intval($cardId)]);
    }

    echo json_encode(['success' => true]);
}
