<?php
/**
 * Victory Genomics CRM - Migration: webhook_endpoints table
 * 
 * Creates the webhook_endpoints table for Google Sheets integration.
 * Each row represents one connected Google Sheet (or any external source)
 * that can push leads into the CRM via a secure API endpoint.
 *
 * Run once:  php migrate_webhook_endpoints.php
 * Or visit:  https://crm.victorygenomics.com/migrate_webhook_endpoints.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/migration-guard.php';  // admin/CLI only

$db = Database::getInstance();

$queries = [];

// ── 1. Create webhook_endpoints table ────────────────────
$queries[] = "
CREATE TABLE IF NOT EXISTS `webhook_endpoints` (
  `endpoint_id`    INT(11)      NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(200) NOT NULL COMMENT 'Friendly name, e.g. Meta Ads - Saudi Arabia',
  `api_key`        VARCHAR(64)  NOT NULL COMMENT 'Unique secret key for authenticating requests',
  `assigned_to`    INT(11)      DEFAULT NULL COMMENT 'Default sales manager to assign new leads to',
  `field_mapping`  TEXT         NOT NULL COMMENT 'JSON: maps incoming field names to CRM lead columns',
  `lead_defaults`  TEXT         DEFAULT NULL COMMENT 'JSON: default values for lead_type, lead_source, lead_status, priority, region, country',
  `enabled`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_received`  TIMESTAMP    NULL DEFAULT NULL COMMENT 'Timestamp of last successful lead import',
  `total_imported` INT(11)      NOT NULL DEFAULT 0 COMMENT 'Running count of leads imported via this endpoint',
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`endpoint_id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `assigned_to` (`assigned_to`),
  CONSTRAINT `webhook_assigned_fk` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook endpoints for Google Sheets / external lead imports';
";

// ── 2. Create webhook_log table for auditing imports ───────────
$queries[] = "
CREATE TABLE IF NOT EXISTS `webhook_log` (
  `log_id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `endpoint_id`   INT(11)      NOT NULL,
  `lead_id`       INT(11)      DEFAULT NULL COMMENT 'Created lead ID (null if duplicate/error)',
  `status`        ENUM('created','duplicate','error') NOT NULL DEFAULT 'created',
  `raw_payload`   TEXT         DEFAULT NULL COMMENT 'Original JSON payload received',
  `error_message` VARCHAR(500) DEFAULT NULL,
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `endpoint_id` (`endpoint_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `webhook_log_endpoint_fk` FOREIGN KEY (`endpoint_id`) REFERENCES `webhook_endpoints` (`endpoint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit log for webhook lead imports';
";

echo "<pre>\n";
echo "Victory Genomics CRM - Webhook Endpoints Migration\n";
echo "===================================================\n\n";

$success = true;
foreach ($queries as $i => $sql) {
    try {
        $db->query($sql);
        echo "Query " . ($i + 1) . ": OK\n";
    } catch (Exception $e) {
        echo "Query " . ($i + 1) . ": FAILED - " . $e->getMessage() . "\n";
        $success = false;
    }
}

if ($success) {
    echo "\nMigration completed successfully!\n";
    echo "Tables created: webhook_endpoints, webhook_log\n";
} else {
    echo "\nMigration completed with errors. Check messages above.\n";
}
echo "</pre>\n";
?>
