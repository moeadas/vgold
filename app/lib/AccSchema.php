<?php
/**
 * AccSchema — runtime schema guard for the native Accounting & Finance app.
 *
 * SiteGround deploys code without running SQL migrations, so (exactly like
 * Schema::ensureCrm / ensureUnifiedModules) every accounting table is created
 * idempotently on first use. Everything is prefixed `acc_` so it can never
 * collide with VGold's own tables (users, tasks, projects, files…) or with the
 * CRM's `crm_*` tables.
 *
 * Called lazily from AccountingController::boot() — a Workflow or CRM request
 * never pays for it.
 *
 * Money columns are DECIMAL(15,4) to match the source app exactly.
 * Soft-deleted tables carry `deleted_at`; junction/child tables hard-delete.
 */
class AccSchema
{
    /** Bumped when the shipped demo dataset changes. */
    const SEED_VERSION = '1';

    /** Every business table, in child → parent order (safe for bulk wipes). */
    const BUSINESS_TABLES = [
        'acc_document_item_taxes', 'acc_document_items', 'acc_document_totals',
        'acc_document_histories', 'acc_documents',
        'acc_transaction_taxes', 'acc_transactions', 'acc_transfers',
        'acc_reconciliations', 'acc_journal_lines', 'acc_journal_entries',
        'acc_recurrings', 'acc_contact_people', 'acc_contacts',
        'acc_items', 'acc_categories', 'acc_taxes', 'acc_accounts',
        'acc_chart_of_accounts',
    ];

    public static function ensure()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $eng = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

            DB::query("CREATE TABLE IF NOT EXISTS `acc_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `skey` VARCHAR(100) NOT NULL,
                `svalue` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_acc_setting` (`skey`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_accounts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(191) NOT NULL,
                `bank_name` VARCHAR(191) NULL,
                `number` VARCHAR(100) NULL,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `opening_balance` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `balance` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `type` VARCHAR(32) NOT NULL DEFAULT 'bank',
                `color` VARCHAR(16) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_accounts_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_taxes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(191) NOT NULL,
                `rate` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `type` VARCHAR(32) NOT NULL DEFAULT 'normal',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_taxes_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_categories` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(191) NOT NULL,
                `type` VARCHAR(32) NOT NULL,
                `color` VARCHAR(16) NOT NULL DEFAULT '#7e6549',
                `parent_id` INT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_categories_type` (`type`),
                KEY `acc_categories_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(191) NOT NULL,
                `sku` VARCHAR(100) NULL,
                `description` TEXT NULL,
                `sale_price` DECIMAL(15,4) NULL,
                `purchase_price` DECIMAL(15,4) NULL,
                `type` VARCHAR(32) NOT NULL DEFAULT 'service',
                `category_id` INT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_items_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_contacts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `type` VARCHAR(32) NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `email` VARCHAR(191) NULL,
                `tax_number` VARCHAR(100) NULL,
                `phone` VARCHAR(64) NULL,
                `address` TEXT NULL,
                `city` VARCHAR(120) NULL,
                `state` VARCHAR(120) NULL,
                `zip_code` VARCHAR(32) NULL,
                `country` VARCHAR(120) NULL,
                `website` VARCHAR(191) NULL,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `category` VARCHAR(120) NULL,
                `crm_lead_id` INT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_contacts_type` (`type`),
                KEY `acc_contacts_crm_lead` (`crm_lead_id`),
                KEY `acc_contacts_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_contact_people` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `contact_id` INT NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `email` VARCHAR(191) NULL,
                `phone` VARCHAR(64) NULL,
                `position` VARCHAR(120) NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_cp_contact` (`contact_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_chart_of_accounts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `type` VARCHAR(32) NOT NULL,
                `side` VARCHAR(16) NOT NULL,
                `balance` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_coa_code` (`code`),
                KEY `acc_coa_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_documents` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `type` VARCHAR(16) NOT NULL,
                `number` VARCHAR(64) NOT NULL,
                `order_number` VARCHAR(100) NULL,
                `status` VARCHAR(32) NOT NULL,
                `issued_at` DATE NOT NULL,
                `due_at` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `paid_amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `contact_id` INT NOT NULL,
                `category_id` INT NULL,
                `notes` TEXT NULL,
                `terms` TEXT NULL,
                `parent_id` INT NULL,
                `attachment_path` VARCHAR(255) NULL,
                `attachment_name` VARCHAR(255) NULL,
                `attachment_mime` VARCHAR(120) NULL,
                `crm_lead_id` INT NULL,
                `task_id` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY `uniq_acc_doc_number` (`number`),
                KEY `acc_doc_type_status` (`type`, `status`),
                KEY `acc_doc_contact` (`contact_id`),
                KEY `acc_doc_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_document_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `document_id` INT NOT NULL,
                `item_id` INT NULL,
                `name` VARCHAR(191) NOT NULL,
                `sku` VARCHAR(100) NULL,
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
                `price` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `total` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_di_document` (`document_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_document_item_taxes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `document_item_id` INT NOT NULL,
                `tax_id` INT NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_dit_item` (`document_item_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_document_totals` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `document_id` INT NOT NULL,
                `code` VARCHAR(32) NULL,
                `name` VARCHAR(191) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_dt_document` (`document_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_document_histories` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `document_id` INT NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `description` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_dh_document` (`document_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_transactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `type` VARCHAR(16) NOT NULL,
                `paid_at` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `account_id` INT NOT NULL,
                `document_id` INT NULL,
                `contact_id` INT NULL,
                `category_id` INT NULL,
                `description` TEXT NULL,
                `payment_method` VARCHAR(64) NULL,
                `reference` VARCHAR(191) NULL,
                `reconciled` TINYINT(1) NOT NULL DEFAULT 0,
                `is_transfer` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_tx_type_date` (`type`, `paid_at`),
                KEY `acc_tx_account` (`account_id`),
                KEY `acc_tx_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_transaction_taxes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `transaction_id` INT NOT NULL,
                `tax_id` INT NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_tt_transaction` (`transaction_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_journal_entries` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `number` VARCHAR(64) NOT NULL,
                `entry_date` DATE NOT NULL,
                `memo` VARCHAR(255) NOT NULL,
                `source` VARCHAR(32) NOT NULL DEFAULT 'manual',
                `status` VARCHAR(32) NOT NULL DEFAULT 'posted',
                `document_id` INT NULL,
                `transaction_id` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY `uniq_acc_je_number` (`number`),
                KEY `acc_je_document` (`document_id`),
                KEY `acc_je_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_journal_lines` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `journal_entry_id` INT NOT NULL,
                `chart_of_account_id` INT NOT NULL,
                `debit` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `credit` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `description` TEXT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_jl_entry` (`journal_entry_id`),
                KEY `acc_jl_coa` (`chart_of_account_id`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_transfers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `transferred_at` DATE NOT NULL,
                `description` VARCHAR(255) NULL,
                `from_account_id` INT NOT NULL,
                `to_account_id` INT NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `expense_transaction_id` INT NULL,
                `income_transaction_id` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_tr_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_reconciliations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `account_id` INT NOT NULL,
                `started_at` DATE NOT NULL,
                `ended_at` DATE NULL,
                `closing_balance` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `reconciled` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_rc_account` (`account_id`),
                KEY `acc_rc_deleted` (`deleted_at`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `acc_recurrings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `recurable_type` VARCHAR(32) NOT NULL,
                `recurable_id` INT NOT NULL,
                `frequency` VARCHAR(32) NOT NULL DEFAULT 'monthly',
                `interval_n` INT NOT NULL DEFAULT 1,
                `started_at` DATETIME NOT NULL,
                `last_ran_at` DATETIME NULL,
                `limit_count` INT NOT NULL DEFAULT 0,
                `limit_by` VARCHAR(16) NOT NULL DEFAULT 'count',
                `limit_date` DATETIME NULL,
                `auto_send` TINYINT(1) NOT NULL DEFAULT 0,
                `occurrences` INT NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_rec_target` (`recurable_type`, `recurable_id`),
                KEY `acc_rec_deleted` (`deleted_at`)
            )$eng");
        } catch (\Throwable $e) {
            error_log('AccSchema::ensure: ' . $e->getMessage());
        }
    }

    /** True when the accounting tables hold no business rows at all. */
    public static function isEmpty()
    {
        try {
            $row = DB::fetch("SELECT
                (SELECT COUNT(*) FROM acc_documents) +
                (SELECT COUNT(*) FROM acc_contacts) +
                (SELECT COUNT(*) FROM acc_accounts) AS n");
            return !$row || (int)$row['n'] === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete all business data. Keeps acc_settings (company profile) unless
     * $includeSettings. Counters are reset so numbering restarts cleanly.
     */
    public static function wipe($includeSettings = false)
    {
        $deleted = [];
        DB::query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::BUSINESS_TABLES as $t) {
                try {
                    $n = DB::query("DELETE FROM `$t`")->rowCount();
                    $deleted[$t] = (int)$n;
                    DB::query("ALTER TABLE `$t` AUTO_INCREMENT = 1");
                } catch (\Throwable $e) {
                    error_log("AccSchema::wipe $t: " . $e->getMessage());
                }
            }
            if ($includeSettings) {
                DB::query('DELETE FROM `acc_settings`');
            } else {
                Acc::setSetting('invoice_next_number', '0001');
                Acc::setSetting('bill_next_number', '0001');
                Acc::setSetting('seed_version', '');
            }
        } finally {
            DB::query('SET FOREIGN_KEY_CHECKS=1');
        }
        return $deleted;
    }
}
