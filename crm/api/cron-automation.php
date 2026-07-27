<?php
/**
 * Victory Genomics CRM — automation scheduler endpoint.
 *
 * Runs every active time-based automation rule once. Safe to call as often as
 * you like: crm_automation_fires makes each rule fire at most once per lead per
 * cycle, so re-running never double-sends.
 *
 * SiteGround Site Tools → Devs → Cron Jobs, every 15 minutes:
 *
 *   curl -s "https://vgold.victorygenomics.com/crm/api/cron-automation.php?secret=YOUR_SECRET&_t=$(date +%s)"
 *
 * The &_t cache-buster matters — LiteSpeed will otherwise serve a cached
 * response and the job will look like it ran when it did not.
 *
 * ?dry=1 reports what would fire without firing anything.
 */

require_once __DIR__ . '/../config/database.php';

// LiteSpeed / SiteGround dynamic cache must not touch this.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

$secret = $_GET['secret'] ?? '';
if (empty($secret) || strlen($secret) < 10) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid secret.']);
    exit;
}

$db = Database::getInstance();
$stored = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'automation_cron_secret'")->fetchColumn();
if (!$stored || !hash_equals($stored, $secret)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid secret.']);
    exit;
}

require_once __DIR__ . '/../includes/automation-scheduler.php';

set_time_limit(300);
$dry = ($_GET['dry'] ?? '') === '1';
$ruleId = isset($_GET['rule_id']) ? (int)$_GET['rule_id'] : 0;

try {
    $result = runAutomationSchedule(['dry' => $dry, 'rule_id' => $ruleId ?: null]);
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('cron-automation: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
