<?php
/**
 * Victory Genomics CRM — Automation Migration
 * Creates automation_rules and automation_logs tables.
 * Run once: php migrate_automation.php  (or visit via browser)
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::getInstance()->getConnection();

$statements = [

    // ── automation_rules ─────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS `automation_rules` (
        `rule_id`        INT(11)      NOT NULL AUTO_INCREMENT,
        `name`           VARCHAR(200) NOT NULL,
        `description`    VARCHAR(500) DEFAULT NULL,
        `trigger_type`   VARCHAR(50)  NOT NULL COMMENT 'lead_created, lead_status_changed, lead_assigned, lead_reassigned, lead_source_match, proposal_status_changed',
        `trigger_config` TEXT         DEFAULT NULL COMMENT 'JSON: extra trigger params, e.g. {\"from_status\":\"New Lead\",\"to_status\":\"Contacted\"}',
        `conditions`     TEXT         DEFAULT NULL COMMENT 'JSON array of condition objects: [{\"field\":\"country\",\"operator\":\"equals\",\"value\":\"UAE\"}]',
        `action_type`    VARCHAR(50)  NOT NULL COMMENT 'assign_user, send_email_template, send_whatsapp_template, send_notification_email, change_lead_status, change_priority, log_interaction',
        `action_config`  TEXT         NOT NULL COMMENT 'JSON: action params, e.g. {\"user_id\":5} or {\"template_id\":3}',
        `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
        `run_count`      INT(11)      NOT NULL DEFAULT 0,
        `created_by`     INT(11)      DEFAULT NULL,
        `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`rule_id`),
        KEY `trigger_type` (`trigger_type`),
        KEY `is_active` (`is_active`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Automation rules — triggers, conditions, actions'",

    // ── automation_logs ──────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS `automation_logs` (
        `log_id`        INT(11)      NOT NULL AUTO_INCREMENT,
        `rule_id`       INT(11)      NOT NULL,
        `rule_name`     VARCHAR(200) DEFAULT NULL COMMENT 'Snapshot of rule name at execution time',
        `trigger_type`  VARCHAR(50)  NOT NULL,
        `lead_id`       INT(11)      DEFAULT NULL,
        `proposal_id`   INT(11)      DEFAULT NULL,
        `status`        ENUM('success','failed','skipped') NOT NULL DEFAULT 'success',
        `action_taken`  VARCHAR(500) DEFAULT NULL COMMENT 'Human-readable summary of what was done',
        `error_message` VARCHAR(500) DEFAULT NULL,
        `execution_ms`  INT(11)      DEFAULT NULL COMMENT 'Execution time in milliseconds',
        `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        KEY `rule_id`      (`rule_id`),
        KEY `lead_id`      (`lead_id`),
        KEY `proposal_id`  (`proposal_id`),
        KEY `created_at`   (`created_at`),
        CONSTRAINT `auto_log_rule_fk` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`rule_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Execution log for automation rules'"
];

$ok = 0;
$err = 0;
foreach ($statements as $sql) {
    try {
        $pdo->exec($sql);
        // Extract table name for reporting
        preg_match('/`(\w+)`/', $sql, $m);
        echo "OK: {$m[1]}\n";
        $ok++;
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "\nDone — $ok OK, $err errors.\n";
