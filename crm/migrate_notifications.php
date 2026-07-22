<?php
/**
 * Migration: Create notifications table
 * Run once on production, then delete this file.
 *
 * Usage:  php migrate_notifications.php
 *         — or visit via browser (protected by admin check)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/migration-guard.php';  // admin/CLI only

$db = Database::getInstance()->getConnection();

$queries = [
    "CREATE TABLE IF NOT EXISTS `notifications` (
        `notification_id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id`         int(11) NOT NULL COMMENT 'Recipient user',
        `type`            varchar(50) NOT NULL DEFAULT 'info' COMMENT 'wa_inbound, lead_assigned, system, etc.',
        `title`           varchar(255) NOT NULL,
        `body`            text DEFAULT NULL,
        `link`            varchar(500) DEFAULT NULL COMMENT 'CRM page to navigate to',
        `lead_id`         int(11) DEFAULT NULL,
        `is_read`         tinyint(1) NOT NULL DEFAULT 0,
        `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`notification_id`),
        KEY `idx_user_read` (`user_id`, `is_read`),
        KEY `idx_user_created` (`user_id`, `created_at`),
        KEY `idx_lead` (`lead_id`),
        CONSTRAINT `notif_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
        CONSTRAINT `notif_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$ok = 0;
$fail = 0;
foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        echo "OK: " . substr($sql, 0, 60) . "...\n";
        $ok++;
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\nDone. OK=$ok  FAIL=$fail\n";
