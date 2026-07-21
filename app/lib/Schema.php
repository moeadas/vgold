<?php
// VGo — runtime schema guard.
// SiteGround deploys the code without running SQL migrations, so this class
// idempotently ensures the tables/columns added by Feature batch B (migration 010)
// exist. All operations are guarded and wrapped in try/catch so a live request is
// never broken by a schema hiccup. Runs at most once per request (static flag).
class Schema {
    public static function ensureFeatureBatchB() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            // ---- Tables (CREATE TABLE IF NOT EXISTS is universally supported) ----
            DB::query("CREATE TABLE IF NOT EXISTS `file_folders` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `project_id` INT NOT NULL,
                `parent_folder_id` INT NULL,
                `name` VARCHAR(255) NOT NULL,
                `created_by` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `project_idx` (`project_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            DB::query("CREATE TABLE IF NOT EXISTS `card_orders` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `scope_id` INT NOT NULL DEFAULT 0,
                `order_json` TEXT NOT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `user_scope` (`user_id`, `scope_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            DB::query("CREATE TABLE IF NOT EXISTS `channel_reads` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `channel_id` INT NOT NULL,
                `last_read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `user_channel` (`user_id`, `channel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            DB::query("CREATE TABLE IF NOT EXISTS `comment_feed_reads` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL UNIQUE,
                `last_read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ---- Columns (add only if missing — portable across MariaDB/MySQL) ----
            self::addColumnIfMissing('files', 'folder_id', "ALTER TABLE `files` ADD COLUMN `folder_id` INT NULL AFTER `project_id`");
            self::addColumnIfMissing('files', 'external_url', "ALTER TABLE `files` ADD COLUMN `external_url` TEXT NULL");
            self::addColumnIfMissing('user_settings', 'default_screen', "ALTER TABLE `user_settings` ADD COLUMN `default_screen` VARCHAR(20) NOT NULL DEFAULT 'mytasks'");
            self::addColumnIfMissing('user_settings', 'notify_comments', "ALTER TABLE `user_settings` ADD COLUMN `notify_comments` TINYINT(1) DEFAULT 1");

            // Widen `files.storage` to include 'link' if the enum doesn't already allow it.
            self::ensureStorageEnum();
        } catch (\Throwable $e) {
            // Never break a request over schema maintenance.
            error_log('Schema::ensureFeatureBatchB: ' . $e->getMessage());
        }
    }

    private static function columnExists($table, $column) {
        try {
            $row = DB::fetch(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
                [$table, $column]
            );
            return (bool)$row;
        } catch (\Throwable $e) {
            return true; // assume present on error, so we don't loop on ALTERs
        }
    }

    private static function addColumnIfMissing($table, $column, $ddl) {
        if (!self::columnExists($table, $column)) {
            try { DB::query($ddl); } catch (\Throwable $e) { error_log('Schema add column ' . $table . '.' . $column . ': ' . $e->getMessage()); }
        }
    }

    private static function ensureStorageEnum() {
        try {
            $row = DB::fetch(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'storage' LIMIT 1"
            );
            if ($row && stripos($row['COLUMN_TYPE'], "'link'") === false) {
                DB::query("ALTER TABLE `files` MODIFY COLUMN `storage` ENUM('local','sharepoint','link') NOT NULL DEFAULT 'local'");
            }
        } catch (\Throwable $e) {
            error_log('Schema ensureStorageEnum: ' . $e->getMessage());
        }
    }
}
