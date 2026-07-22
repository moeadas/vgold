<?php
/**
 * VGold one-time production setup / DB seed endpoint.
 *
 * WHY THIS EXISTS: the sandbox that deploys the code cannot reach the SiteGround
 * MySQL (3306 firewalled) nor the site over HTTP (anti-bot captcha on the CI IP).
 * A real admin browser DOES pass the captcha, so this secret-gated endpoint lets
 * the DB be seeded once, from the server itself, using the app's own DB conn.
 *
 * It is IDEMPOTENT and safe to re-run:
 *   1. Applies the VGo base schema + CRM-integration migration (IF NOT EXISTS).
 *   2. Imports the CRM data dump into crm_* tables (INSERT ... the dump is
 *      generated with INSERT statements; re-running is guarded by a marker).
 *   3. Reconciles/links unified users + primary workspace + "CRM" category.
 *   4. Syncs CRM follow-ups -> VGold tasks.
 *
 * SECURITY: requires ?key=<setup secret>. The secret is NOT stored in the repo:
 * create the gitignored config/setup_secret.php on the server containing
 *     <?php return 'a-long-random-hex-string';
 * (e.g. `php -r "echo bin2hex(random_bytes(16));"`). If that file is absent,
 * this endpoint is fully disabled (403). DELETE THIS FILE after a successful
 * run (it self-reminds); deploy.sh excludes it from uploads.
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

$secretFile = dirname(__DIR__) . '/config/setup_secret.php';
$setupSecret = is_file($secretFile) ? (string) (require $secretFile) : '';

$key = (string) ($_GET['key'] ?? '');
if ($setupSecret === '' || strlen($setupSecret) < 16 || !hash_equals($setupSecret, $key)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$root = dirname(__DIR__);
require $root . '/config/app.php';
require $root . '/app/lib/DB.php';

function out($m) { echo $m . "\n"; @ob_flush(); @flush(); }
function step($m) { out("\n==> " . $m); }

$t0 = microtime(true);
out("VGold setup starting — DB=" . DB_NAME . " host=" . DB_HOST . " env=" . APP_ENV);

$pdo = DB::conn();

/* ─────────────────────────────────────────────────────────────────────────
 * 1. SCHEMA — apply base VGo migrations + CRM integration.
 *    We run each *.sql, splitting on ";" at line ends. Migrations use
 *    CREATE TABLE IF NOT EXISTS / INSERT IGNORE where possible; a couple of
 *    ALTERs may error on re-run ("Duplicate column") — those are caught and
 *    treated as already-applied.
 * ──────────────────────────────────────────────────────────────────────── */
function run_sql_file($pdo, $file) {
    if (!is_file($file)) { out("   (skip, missing) " . basename($file)); return; }
    $sql = trim(file_get_contents($file));
    if ($sql === '') { out("   (empty) " . basename($file)); return; }
    // Execute the WHOLE file in one exec() — the mysqlnd PDO driver runs the
    // multi-statement batch server-side exactly like the `mysql` CLI, which
    // correctly handles multi-line CREATE TABLE (inline FKs) and multi-row
    // INSERTs. Splitting on ";" is unsafe and corrupts those statements.
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec($sql);
        // Drain any remaining result sets so the connection stays usable.
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        out("   " . basename($file) . " — applied");
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        // On a re-run, CREATEs without IF NOT EXISTS / dup seeds are expected.
        if (preg_match('/(already exists|Duplicate (column|key|entry)|Multiple primary key)/i', $msg)) {
            out("   " . basename($file) . " — already applied (" . substr($msg, 0, 80) . ")");
        } else {
            out("   ! " . basename($file) . ": " . substr($msg, 0, 200));
        }
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (\Throwable $e2) {}
    }
}

step("1/4 Schema (base migrations + CRM integration)");
$migDir = $root . '/app/migrations';
$order = [
    '001_init.sql','002_notifications.sql','002_seed_data.sql',
    '003_schema_update.sql','003_production_seed.sql',
    '004_multi_user_smtp.sql','004_priorities.sql','005_sharepoint.sql',
    '006_identity.sql','007_chat_reads.sql','008_task_status_and_agenda.sql',
    '009_task_files.sql','010_feature_batch_b.sql','011_crm_integration.sql',
];
foreach ($order as $f) run_sql_file($pdo, $migDir . '/' . $f);

// Migration 010 uses MariaDB-only `ADD COLUMN IF NOT EXISTS`, which SiteGround's
// MySQL rejects (1064). The runtime Schema guard recreates the exact same
// objects with MySQL-portable DDL (CREATE TABLE IF NOT EXISTS + information_schema
// column checks), so we invoke it here to make the DB fully consistent right away
// instead of waiting for the first live request. Both guards are idempotent.
try {
    require_once $root . '/app/lib/Schema.php';
    Schema::ensureFeatureBatchB();
    Schema::ensureCrm();
    out("   runtime schema guards applied (feature-batch-b + crm)");
} catch (\Throwable $e) {
    out("   ! schema guard: " . substr($e->getMessage(), 0, 160));
}

/* ─────────────────────────────────────────────────────────────────────────
 * 2. CRM DATA IMPORT — only if crm_leads is empty (marker-based idempotency).
 * ──────────────────────────────────────────────────────────────────────── */
step("2/4 CRM data import");
$haveLeads = 0;
try { $haveLeads = (int) DB::conn()->query("SELECT COUNT(*) FROM crm_leads")->fetchColumn(); }
catch (\Throwable $e) { out("   crm_leads not present yet: " . $e->getMessage()); }

if ($haveLeads > 0) {
    out("   crm_leads already has $haveLeads rows — skipping import (idempotent).");
} else {
    $dump = $root . '/migration/sql/crm_data_import.sql';
    if (!is_file($dump)) {
        out("   !! MISSING $dump — upload it (deploy.sh sql) then re-run.");
    } else {
        out("   importing " . round(filesize($dump)/1048576, 1) . " MB dump ...");
        // The dump is a series of INSERTs (+ SET/normalize prelude). Execute it
        // as a whole via multi-statement to preserve any session settings.
        $sql = file_get_contents($dump);
        try {
            $pdo->exec($sql);
            $n = (int) DB::conn()->query("SELECT COUNT(*) FROM crm_leads")->fetchColumn();
            out("   import done — crm_leads now $n rows");
        } catch (\Throwable $e) {
            out("   ! bulk exec failed (" . substr($e->getMessage(),0,120) . "); retrying statement-by-statement");
            $stmts = preg_split('/;\s*[\r\n]+/', $sql);
            $ok=0;$err=0;
            foreach ($stmts as $s) { $s=trim($s); if($s==='')continue;
                try{$pdo->exec($s);$ok++;}catch(\Throwable $e2){$err++; if($err<=5) out("     ".substr($e2->getMessage(),0,120));}
            }
            $n = (int) DB::conn()->query("SELECT COUNT(*) FROM crm_leads")->fetchColumn();
            out("   import (fallback) ok=$ok err=$err — crm_leads now $n rows");
        }
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * 3. RECONCILE users + workspace + CRM category (reuse the migration script).
 * ──────────────────────────────────────────────────────────────────────── */
step("3/4 Reconcile unified users / workspace / CRM category");
$GLOBALS['argv'] = ['reconcile', '--prune-demo'];   // simulate CLI flags
$argv = $GLOBALS['argv']; $argc = count($argv);
try {
    require $root . '/migration/reconcile_users.php';
} catch (\Throwable $e) {
    out("   ! reconcile: " . $e->getMessage());
}

/* ─────────────────────────────────────────────────────────────────────────
 * 4. Sync CRM follow-ups -> VGold tasks (idempotent, per linked workspace).
 * ──────────────────────────────────────────────────────────────────────── */
step("4/4 Sync CRM follow-ups -> tasks");
try {
    require_once $root . '/app/controllers/CrmSyncController.php';
    $ws = DB::fetch("SELECT w.id FROM workspaces w JOIN users u ON u.id=w.created_by WHERE u.crm_user_id IS NOT NULL ORDER BY w.id ASC LIMIT 1");
    if ($ws) {
        $res = CrmSyncController::runSync((int)$ws['id'], false);
        out("   sync result: " . json_encode($res));
    } else {
        out("   ! no CRM-linked workspace found to sync into");
    }
} catch (\Throwable $e) {
    out("   ! sync: " . $e->getMessage());
}

/* ── Summary ─────────────────────────────────────────────────────────────── */
step("Summary");
foreach ([
    'users'              => "SELECT COUNT(*) FROM users",
    'users linked (crm)' => "SELECT COUNT(*) FROM users WHERE crm_user_id IS NOT NULL",
    'workspaces'         => "SELECT COUNT(*) FROM workspaces",
    'workspace_members'  => "SELECT COUNT(*) FROM workspace_members",
    'crm_leads'          => "SELECT COUNT(*) FROM crm_leads",
    'crm_users'          => "SELECT COUNT(*) FROM crm_users",
    'crm_interactions'   => "SELECT COUNT(*) FROM crm_interactions",
    'crm_role_map'       => "SELECT COUNT(*) FROM crm_role_map",
] as $label => $q) {
    try { out(sprintf("   %-20s %s", $label, DB::conn()->query($q)->fetchColumn())); }
    catch (\Throwable $e) { out(sprintf("   %-20s ERR %s", $label, substr($e->getMessage(),0,80))); }
}

out("\nDone in " . round(microtime(true) - $t0, 1) . "s.");
out("!!! SECURITY: delete this file now  ->  public/setup_vgold.php");
