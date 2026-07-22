<?php
/**
 * Victory Genomics CRM - Webhooks Management API
 * CRUD for webhook_endpoints (Admin only, session-authenticated)
 *
 * GET    ?action=list             — List all webhook endpoints
 * GET    ?action=detail&id=X      — Get single endpoint details
 * GET    ?action=logs&id=X        — Get recent import logs for an endpoint
 * POST   ?action=create           — Create new endpoint
 * POST   ?action=update           — Update existing endpoint
 * POST   ?action=delete           — Delete endpoint
 * POST   ?action=toggle           — Enable/disable endpoint
 * POST   ?action=regenerate_key   — Regenerate API key for an endpoint
 * POST   ?action=reset_sync       — Reset sync position (re-import all rows)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();
requireRole(['Admin']);

header('Content-Type: application/json');

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$currentUser = getCurrentUser();

try {
    switch ($action) {
        case 'list':
            listEndpoints($db);
            break;
        case 'detail':
            getEndpointDetail($db);
            break;
        case 'logs':
            getEndpointLogs($db);
            break;
        case 'create':
            requireCSRF();
            createEndpoint($db, $currentUser);
            break;
        case 'update':
            requireCSRF();
            updateEndpoint($db, $currentUser);
            break;
        case 'delete':
            requireCSRF();
            deleteEndpoint($db, $currentUser);
            break;
        case 'toggle':
            requireCSRF();
            toggleEndpoint($db, $currentUser);
            break;
        case 'regenerate_key':
            requireCSRF();
            regenerateKey($db, $currentUser);
            break;
        case 'reset_sync':
            requireCSRF();
            resetSync($db, $currentUser);
            break;
        default:
            jsonError('Invalid action', 400);
    }
} catch (Exception $e) {
    error_log("Webhooks API Error: " . $e->getMessage());
    jsonError('An error occurred: ' . $e->getMessage(), 500);
}

// ─── List all endpoints ──────────────────────────────────────
function listEndpoints($db) {
    $rows = $db->query("
        SELECT we.*, u.full_name AS assigned_name
        FROM webhook_endpoints we
        LEFT JOIN users u ON we.assigned_to = u.user_id
        ORDER BY we.created_at DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);

    jsonSuccess('Endpoints retrieved', $rows);
}

// ─── Get single endpoint ─────────────────────────────────────
function getEndpointDetail($db) {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required', 400);

    $row = $db->query("
        SELECT we.*, u.full_name AS assigned_name
        FROM webhook_endpoints we
        LEFT JOIN users u ON we.assigned_to = u.user_id
        WHERE we.endpoint_id = ?
    ", [$id])->fetch(\PDO::FETCH_ASSOC);

    if (!$row) jsonError('Endpoint not found', 404);

    jsonSuccess('Endpoint retrieved', $row);
}

// ─── Get import logs for an endpoint ─────────────────────────
function getEndpointLogs($db) {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required', 400);

    $limit = min(intval($_GET['limit'] ?? 50), 200);

    $rows = $db->query("
        SELECT wl.*, l.contact_person, l.email AS lead_email
        FROM webhook_log wl
        LEFT JOIN leads l ON wl.lead_id = l.lead_id
        WHERE wl.endpoint_id = ?
        ORDER BY wl.created_at DESC
        LIMIT $limit
    ", [$id])->fetchAll(\PDO::FETCH_ASSOC);

    jsonSuccess('Logs retrieved', $rows);
}

// ─── Create new endpoint ─────────────────────────────────────
function createEndpoint($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $name = trim($input['name'] ?? '');
    if ($name === '') jsonError('Endpoint name is required.', 400);

    $assignedTo = intval($input['assigned_to'] ?? 0) ?: null;
    $fieldMapping = $input['field_mapping'] ?? '{}';
    $leadDefaults = $input['lead_defaults'] ?? '{}';

    // Validate JSON
    if (is_string($fieldMapping)) {
        $decoded = json_decode($fieldMapping, true);
        if ($decoded === null && $fieldMapping !== '{}') jsonError('Invalid field_mapping JSON.', 400);
    } elseif (is_array($fieldMapping)) {
        $fieldMapping = json_encode($fieldMapping);
    }
    if (is_string($leadDefaults)) {
        $decoded = json_decode($leadDefaults, true);
        if ($decoded === null && $leadDefaults !== '{}') jsonError('Invalid lead_defaults JSON.', 400);
    } elseif (is_array($leadDefaults)) {
        $leadDefaults = json_encode($leadDefaults);
    }

    // Generate unique API key
    $apiKey = generateWebhookApiKey();

    $sheetUrl  = trim($input['sheet_url'] ?? '');
    $sheetName = trim($input['sheet_name'] ?? '') ?: null;

    $endpointId = $db->insert('webhook_endpoints', [
        'name'          => $name,
        'api_key'       => $apiKey,
        'sheet_url'     => $sheetUrl ?: null,
        'sheet_name'    => $sheetName,
        'assigned_to'   => $assignedTo,
        'field_mapping' => $fieldMapping,
        'lead_defaults' => $leadDefaults,
        'enabled'       => 1,
    ]);

    logActivity($currentUser['user_id'], 'Create Webhook', 'System', $endpointId, "Created webhook endpoint: $name");

    jsonSuccess('Webhook endpoint created.', [
        'endpoint_id' => intval($endpointId),
        'api_key'     => $apiKey,
        'webhook_url' => rtrim(APP_URL, '/') . CRM_BASE . '/api/leads-webhook.php?key=' . $apiKey,
    ]);
}

// ─── Update existing endpoint ────────────────────────────────
function updateEndpoint($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $id = intval($input['endpoint_id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required.', 400);

    $existing = $db->findOne('webhook_endpoints', ['endpoint_id' => $id]);
    if (!$existing) jsonError('Endpoint not found.', 404);

    $name = trim($input['name'] ?? '');
    if ($name === '') jsonError('Endpoint name is required.', 400);

    $assignedTo = intval($input['assigned_to'] ?? 0) ?: null;
    $fieldMapping = $input['field_mapping'] ?? '{}';
    $leadDefaults = $input['lead_defaults'] ?? '{}';

    if (is_string($fieldMapping)) {
        $decoded = json_decode($fieldMapping, true);
        if ($decoded === null && $fieldMapping !== '{}') jsonError('Invalid field_mapping JSON.', 400);
    } elseif (is_array($fieldMapping)) {
        $fieldMapping = json_encode($fieldMapping);
    }
    if (is_string($leadDefaults)) {
        $decoded = json_decode($leadDefaults, true);
        if ($decoded === null && $leadDefaults !== '{}') jsonError('Invalid lead_defaults JSON.', 400);
    } elseif (is_array($leadDefaults)) {
        $leadDefaults = json_encode($leadDefaults);
    }

    $sheetUrl  = trim($input['sheet_url'] ?? '');
    $sheetName = trim($input['sheet_name'] ?? '') ?: null;

    $updateData = [
        'name'          => $name,
        'assigned_to'   => $assignedTo,
        'sheet_url'     => $sheetUrl ?: null,
        'sheet_name'    => $sheetName,
        'field_mapping' => $fieldMapping,
        'lead_defaults' => $leadDefaults,
    ];

    // If sheet_url changed, reset sync position
    if (($existing['sheet_url'] ?? '') !== ($sheetUrl ?: null)) {
        $updateData['last_synced_row'] = 0;
    }

    $db->update('webhook_endpoints', $updateData, ['endpoint_id' => $id]);

    logActivity($currentUser['user_id'], 'Update Webhook', 'System', $id, "Updated webhook endpoint: $name");

    jsonSuccess('Webhook endpoint updated.');
}

// ─── Delete endpoint ─────────────────────────────────────────
function deleteEndpoint($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $id = intval($input['endpoint_id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required.', 400);

    $existing = $db->findOne('webhook_endpoints', ['endpoint_id' => $id]);
    if (!$existing) jsonError('Endpoint not found.', 404);

    $db->delete('webhook_endpoints', ['endpoint_id' => $id]);

    logActivity($currentUser['user_id'], 'Delete Webhook', 'System', $id, "Deleted webhook endpoint: {$existing['name']}");

    jsonSuccess('Webhook endpoint deleted.');
}

// ─── Toggle enabled/disabled ─────────────────────────────────
function toggleEndpoint($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $id = intval($input['endpoint_id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required.', 400);

    $existing = $db->findOne('webhook_endpoints', ['endpoint_id' => $id]);
    if (!$existing) jsonError('Endpoint not found.', 404);

    $newEnabled = intval($existing['enabled']) === 1 ? 0 : 1;
    $db->update('webhook_endpoints', ['enabled' => $newEnabled], ['endpoint_id' => $id]);

    $action = $newEnabled ? 'Enabled' : 'Disabled';
    logActivity($currentUser['user_id'], "$action Webhook", 'System', $id, "$action webhook endpoint: {$existing['name']}");

    jsonSuccess("Webhook endpoint $action.", ['enabled' => $newEnabled]);
}

// ─── Regenerate API key ──────────────────────────────────────
function regenerateKey($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $id = intval($input['endpoint_id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required.', 400);

    $existing = $db->findOne('webhook_endpoints', ['endpoint_id' => $id]);
    if (!$existing) jsonError('Endpoint not found.', 404);

    $newKey = generateWebhookApiKey();
    $db->update('webhook_endpoints', ['api_key' => $newKey], ['endpoint_id' => $id]);

    logActivity($currentUser['user_id'], 'Regenerate Webhook Key', 'System', $id, "Regenerated API key for: {$existing['name']}");

    jsonSuccess('API key regenerated. Update your Google Apps Script with the new key.', [
        'api_key'     => $newKey,
        'webhook_url' => rtrim(APP_URL, '/') . CRM_BASE . '/api/leads-webhook.php?key=' . $newKey,
    ]);
}

// ─── Reset sync position ─────────────────────────────────────
function resetSync($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $id = intval($input['endpoint_id'] ?? 0);
    if (!$id) jsonError('Endpoint ID required.', 400);

    $existing = $db->findOne('webhook_endpoints', ['endpoint_id' => $id]);
    if (!$existing) jsonError('Endpoint not found.', 404);

    $db->update('webhook_endpoints', ['last_synced_row' => 0], ['endpoint_id' => $id]);

    logActivity($currentUser['user_id'], 'Reset Sync', 'System', $id, "Reset sync position for: {$existing['name']}");

    jsonSuccess('Sync position reset. Next sync will re-import all rows (duplicates will be skipped).');
}

// ─── Helper: generate a cryptographically secure API key ─────
function generateWebhookApiKey() {
    return bin2hex(random_bytes(32)); // 64-char hex string
}
?>
