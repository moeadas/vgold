<?php
/**
 * Victory Genomics CRM - Cron Sync Endpoint
 *
 * Authenticated by a secret key stored in the settings table.
 * Set up as a cron job on SiteGround:
 *
 *   curl -s "https://crm.victorygenomics.com/api/cron-sync.php?secret=YOUR_SECRET&_t=$(date+%%s)"
 *
 * IMPORTANT: Include the &_t=$(date+%s) cache-buster to prevent SiteGround proxy cache.
 * Recommended schedule: every hour (or 5-10 minutes for faster sync).
 *
 * This will sync ALL enabled Google Sheet endpoints.
 */

require_once __DIR__ . '/../config/database.php';

// ── Prevent proxy/CDN caching (SiteGround Dynamic Cache / LiteSpeed) ─
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

// ── Verify secret key ────────────────────────────────────────
$secret = $_GET['secret'] ?? '';

if (empty($secret) || strlen($secret) < 10) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid secret.']);
    exit;
}

$db = Database::getInstance();
$storedSecret = $db->query(
    "SELECT setting_value FROM settings WHERE setting_key = 'sheets_cron_secret'"
)->fetchColumn();

if (!$storedSecret || !hash_equals($storedSecret, $secret)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid secret.']);
    exit;
}

// ── Authenticated — include sync engine and run ──────────────
define('SHEETS_CRON_AUTHENTICATED', true);
define('SHEETS_SYNC_INCLUDED', true);

require_once __DIR__ . '/sheets-sync-v2.php';

$results = syncAllEndpoints($db);

echo json_encode([
    'success'   => true,
    'message'   => 'Cron sync completed.',
    'timestamp' => date('Y-m-d H:i:s'),
    'results'   => $results,
]);
?>
