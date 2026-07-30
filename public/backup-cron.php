<?php
/**
 * VGold — daily backup, called by SiteGround Site Tools → Cron Jobs.
 *
 *   curl -s "https://vgo.victorygenomics.com/api/backup-cron.php?secret=YOUR_SECRET&_t=$(date +%s)"
 *
 * The &_t cache-buster matters: LiteSpeed will otherwise serve a cached
 * response and the job will look like it ran when it did not.
 *
 * Safe to call more than once a day — it simply takes another backup.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/lib/DB.php';
require_once __DIR__ . '/../app/lib/Schema.php';
require_once __DIR__ . '/../app/lib/CodeVersion.php';
require_once __DIR__ . '/../app/lib/Backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

$secret = (string)($_GET['secret'] ?? '');
if (strlen($secret) < 20) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Missing or invalid secret.']));
}

try {
    $expected = Backup::cronSecret();
} catch (\Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Backup is not initialised: ' . $e->getMessage()]));
}

if (!hash_equals($expected, $secret)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Invalid secret.']));
}

set_time_limit(900);
ignore_user_abort(true);

$run = Backup::run(['trigger' => 'cron']);

$ok = in_array($run['status'] ?? '', ['ok', 'local_only'], true);
http_response_code($ok ? 200 : 500);
echo json_encode([
    'ok'        => $ok,
    'status'    => $run['status'] ?? 'unknown',
    'file'      => $run['file_name'] ?? null,
    'bytes'     => isset($run['bytes']) ? (int)$run['bytes'] : null,
    'remote'    => $run['remote_path'] ?? null,
    'error'     => $run['error'] ?? null,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
