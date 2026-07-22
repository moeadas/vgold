<?php
/**
 * Victory Genomics CRM - Migration: Google Sheets Pull Model + Lead Source Enum
 *
 * 1. Add sheet_url, sheet_name, last_synced_row columns to webhook_endpoints
 * 2. Add cron_secret to settings for automated sync
 * 3. Expand lead_source enum with Facebook, Instagram, Google Ads
 *
 * Run once:  php migrate_sheets_pull.php
 * Or visit:  https://crm.victorygenomics.com/migrate_sheets_pull.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/migration-guard.php';  // admin/CLI only

$db = Database::getInstance();

$queries = [];

// ── 1. Add Google Sheets pull columns to webhook_endpoints ──────
$queries['Add sheet_url column'] = "
ALTER TABLE `webhook_endpoints`
    ADD COLUMN `sheet_url` VARCHAR(500) DEFAULT NULL COMMENT 'Public Google Sheets URL or ID' AFTER `api_key`,
    ADD COLUMN `sheet_name` VARCHAR(100) DEFAULT NULL COMMENT 'Specific sheet/tab name (default: first sheet)' AFTER `sheet_url`,
    ADD COLUMN `last_synced_row` INT(11) NOT NULL DEFAULT 0 COMMENT 'Last row number successfully synced' AFTER `last_received`
";

// ── 2. Add lead_source enum values: Facebook, Instagram, Google Ads ──
$queries['Expand lead_source enum'] = "
ALTER TABLE `leads`
    MODIFY COLUMN `lead_source` ENUM('Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other') DEFAULT 'Other'
";

// ── 3. Insert cron_secret setting for automated sync ────────────
$queries['Add cron_secret setting'] = "
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`)
VALUES ('sheets_cron_secret', '" . bin2hex(random_bytes(20)) . "', 'text')
";

echo "<pre>\n";
echo "Victory Genomics CRM - Google Sheets Pull Model Migration\n";
echo "==========================================================\n\n";

$success = true;
foreach ($queries as $label => $sql) {
    try {
        $db->query($sql);
        echo "$label: OK\n";
    } catch (Exception $e) {
        $msg = $e->getMessage();
        // Ignore \"Duplicate column\" errors (already migrated)
        if (strpos($msg, 'Duplicate column') !== false) {
            echo "$label: SKIPPED (column already exists)\n";
        } else {
            echo "$label: FAILED - $msg\n";
            $success = false;
        }
    }
}

if ($success) {
    echo "\nMigration completed successfully!\n";

    // Show the cron secret for reference
    $secret = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'sheets_cron_secret'")->fetchColumn();
    echo "\nCron sync URL (set up in SiteGround Cron Jobs):\n";
    echo "  curl -s \"https://crm.victorygenomics.com/api/cron-sync.php?secret=$secret\"\n";
    echo "\nRecommended schedule: every 5-10 minutes.\n";
} else {
    echo "\nMigration completed with errors. Check messages above.\n";
}
echo "</pre>\n";
?>
