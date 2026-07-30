<?php
// VGo — runtime schema guard.
// SiteGround deploys the code without running SQL migrations, so this class
// idempotently ensures the tables/columns added by Feature batch B (migration 010)
// exist. All operations are guarded and wrapped in try/catch so a live request is
// never broken by a schema hiccup. Runs at most once per request (static flag).
class Schema {
    public static function ensureUnifiedModules() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `user_module_access` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `workspace_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `module_key` VARCHAR(80) NOT NULL,
                `can_access` TINYINT(1) NOT NULL DEFAULT 0,
                `updated_by` INT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `workspace_user_module` (`workspace_id`, `user_id`, `module_key`),
                KEY `user_module_idx` (`user_id`, `module_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            DB::query("CREATE TABLE IF NOT EXISTS `workspace_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `workspace_id` INT NOT NULL,
                `setting_group` VARCHAR(60) NOT NULL,
                `setting_key` VARCHAR(100) NOT NULL,
                `setting_value` TEXT NULL,
                `updated_by` INT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `workspace_group_key` (`workspace_id`, `setting_group`, `setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            self::addColumnIfMissing('tasks', 'source_module', "ALTER TABLE `tasks` ADD COLUMN `source_module` VARCHAR(40) NULL");
            self::addColumnIfMissing('tasks', 'source_record_id', "ALTER TABLE `tasks` ADD COLUMN `source_record_id` INT NULL");
            self::addColumnIfMissing('tasks', 'crm_lead_id', "ALTER TABLE `tasks` ADD COLUMN `crm_lead_id` INT NULL");

            // People engaged on contract who bill VGold monthly. Kept on `users`
            // rather than inferred from auth_provider: whether someone signs in
            // with a password and whether they are allowed to invoice us are two
            // different questions, and conflating them would silently let every
            // external account submit a payable.
            self::addColumnIfMissing('users', 'is_contractor',
                "ALTER TABLE `users` ADD COLUMN `is_contractor` TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {
            error_log('Schema::ensureUnifiedModules: ' . $e->getMessage());
        }
    }

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
            // Task-level link attachments (attach-by-URL on the task page).
            self::addColumnIfMissing('task_files', 'external_url', "ALTER TABLE `task_files` ADD COLUMN `external_url` TEXT NULL");
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

    /** Backup run history, so the Settings panel can show what actually ran. */
    public static function ensureBackups() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `backup_runs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `started_at` DATETIME NOT NULL,
                `finished_at` DATETIME NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'running',
                `run_trigger` VARCHAR(20) NOT NULL DEFAULT 'manual',
                `bytes` BIGINT NULL,
                `file_name` VARCHAR(191) NULL,
                `sha256` CHAR(64) NULL,
                `remote_path` VARCHAR(500) NULL,
                `error` TEXT NULL,
                `details` TEXT NULL,
                KEY `backup_runs_started` (`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('Schema::ensureBackups: ' . $e->getMessage());
        }
    }

    /**
     * Password-reset tokens.
     *
     * Only the SHA-256 of a token is stored, so a leaked database still does not
     * hand anyone a working reset link. Rows are kept after use — they are the
     * audit trail for who reset what, and the rate limiter counts them.
     */
    public static function ensurePasswordResets() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `requested_by` INT NULL,
                `requested_ip` VARCHAR(45) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `token_hash_unq` (`token_hash`),
                KEY `user_created_idx` (`user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('Schema::ensurePasswordResets: ' . $e->getMessage());
        }
    }

    // CRM integration guard — idempotently ensures the users-table linkage
    // columns and the configurable role map exist. The bulk crm_* tables are
    // created by migration 011 at deploy time; this only guards the light,
    // frequently-needed pieces so a live request never breaks.
    public static function ensureCrm() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            self::addColumnIfMissing('users', 'crm_user_id',  "ALTER TABLE `users` ADD COLUMN `crm_user_id` INT NULL AFTER `ms_oid`");
            self::addColumnIfMissing('users', 'crm_role',     "ALTER TABLE `users` ADD COLUMN `crm_role` VARCHAR(32) NULL AFTER `crm_user_id`");
            self::addColumnIfMissing('users', 'crm_username', "ALTER TABLE `users` ADD COLUMN `crm_username` VARCHAR(50) NULL AFTER `crm_role`");
            // Unique key on crm_user_id (guarded — ignore if it already exists).
            try {
                $has = DB::fetch("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uniq_crm_user_id' LIMIT 1");
                if (!$has) DB::query("ALTER TABLE `users` ADD UNIQUE KEY `uniq_crm_user_id` (`crm_user_id`)");
            } catch (\Throwable $e) { /* non-fatal */ }

            DB::query("CREATE TABLE IF NOT EXISTS `crm_role_map` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `crm_role` VARCHAR(32) NOT NULL,
                `vgold_role` ENUM('admin','member') NOT NULL DEFAULT 'member',
                UNIQUE KEY `uniq_crm_role` (`crm_role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Seed default mapping once (INSERT IGNORE keeps admin edits intact).
            DB::query("INSERT IGNORE INTO `crm_role_map` (`crm_role`,`vgold_role`) VALUES
                ('Admin','admin'),('Sales Manager','member'),('Sales Rep','member'),('Viewer','member')");
        } catch (\Throwable $e) {
            error_log('Schema::ensureCrm: ' . $e->getMessage());
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
