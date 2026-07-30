<?php
/**
 * AccSchema — runtime schema guard for the native Accounting & Finance app.
 *
 * SiteGround deploys code without running SQL migrations, so (exactly like
 * Schema::ensureCrm / ensureUnifiedModules) every accounting table is created
 * idempotently on first use. Everything is prefixed `acc_` so it can never
 * collide with VGo's own tables (users, tasks, projects, files…) or with the
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
        'acc_contractor_invoices',
        'acc_bank_lines', 'acc_bank_imports',
        'acc_attachments', 'acc_transaction_splits',
        'acc_document_item_taxes', 'acc_document_items', 'acc_document_totals',
        'acc_document_histories', 'acc_documents',
        'acc_transaction_taxes', 'acc_transactions', 'acc_transfers',
        'acc_reconciliations', 'acc_journal_lines', 'acc_journal_entries',
        'acc_recurrings', 'acc_contact_people', 'acc_contacts',
        'acc_items', 'acc_categories', 'acc_taxes', 'acc_accounts',
        'acc_chart_of_accounts',
    ];

    /** Where uploaded accounting files live — OUTSIDE the web docroot. */
    const ATTACHMENT_DIR = 'uploads/acc_attachments';

    /** Things an attachment can hang off. */
    const ATTACHABLE = ['document', 'reconciliation', 'transaction', 'bank_import', 'contractor_invoice'];

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

            /**
             * Attachments — bank statements on a reconciliation, a supplier PDF on
             * a bill, a remittance advice on a transaction. Polymorphic on
             * (attachable_type, attachable_id); files live outside the docroot and
             * are only ever served through an authorised controller action.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_attachments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `attachable_type` VARCHAR(32) NOT NULL,
                `attachable_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `path` VARCHAR(500) NOT NULL,
                `mime` VARCHAR(120) NULL,
                `size` BIGINT NOT NULL DEFAULT 0,
                `uploaded_by` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `acc_att_target` (`attachable_type`, `attachable_id`)
            )$eng");

            /**
             * Payment adjustments — the gap between the cash that actually moved
             * and the amount a document is settled for (wire fees, early-payment
             * discounts, small write-offs).
             *
             * `amount` is the SIGNED effect on the document's settlement, so
             * settled = SUM(transaction.amount) + SUM(split.amount) with no
             * per-type branching downstream:
             *   invoice + fee      → +25  (received 9,975, credited 10,000)
             *   invoice + discount → +50
             *   bill    + fee      → −25  (paid 1,025, AP settled 1,000)
             *   bill    + discount → +20  (paid 980,   AP settled 1,000)
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_transaction_splits` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `transaction_id` INT NOT NULL,
                `document_id` INT NULL,
                `kind` VARCHAR(24) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `description` VARCHAR(255) NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_ts_transaction` (`transaction_id`),
                KEY `acc_ts_document` (`document_id`)
            )$eng");

            /**
             * Bank statement imports — one row per uploaded file.
             *
             * The mapping actually used is kept with the import, not just the
             * account, so a statement can be re-read (or questioned) later
             * exactly as it was first understood.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_bank_imports` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `account_id` INT NOT NULL,
                `filename` VARCHAR(255) NOT NULL,
                `format` VARCHAR(16) NOT NULL DEFAULT 'csv',
                `statement_start` DATE NULL,
                `statement_end` DATE NULL,
                `closing_balance` DECIMAL(15,4) NULL,
                `total_rows` INT NOT NULL DEFAULT 0,
                `imported_rows` INT NOT NULL DEFAULT 0,
                `duplicate_rows` INT NOT NULL DEFAULT 0,
                `skipped_rows` INT NOT NULL DEFAULT 0,
                `mapping` TEXT NULL,
                `notes` TEXT NULL,
                `uploaded_by` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_bi_account` (`account_id`),
                KEY `acc_bi_deleted` (`deleted_at`)
            )$eng");

            /**
             * One line off a statement. `amount` is SIGNED from the account's
             * point of view: positive is money in.
             *
             * `dedupe_key` plus `occurrence` is what makes re-uploading an
             * overlapping statement safe while still keeping two genuinely
             * identical charges on the same day. See StatementParser::dedupeKey.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_bank_lines` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `import_id` INT NOT NULL,
                `account_id` INT NOT NULL,
                `posted_at` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `description` VARCHAR(500) NULL,
                `payee` VARCHAR(191) NULL,
                `reference` VARCHAR(100) NULL,
                `balance_after` DECIMAL(15,4) NULL,
                `fitid` VARCHAR(120) NULL,
                `dedupe_key` CHAR(40) NOT NULL,
                `occurrence` INT NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `transaction_id` INT NULL,
                `match_confidence` VARCHAR(16) NULL,
                `decided_by` INT NULL,
                `decided_at` DATETIME NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `acc_bl_import` (`import_id`),
                KEY `acc_bl_account_status` (`account_id`, `status`),
                KEY `acc_bl_date` (`posted_at`),
                UNIQUE KEY `uniq_acc_bl_dedupe` (`account_id`, `dedupe_key`, `occurrence`)
            )$eng");

            /**
             * Invoices submitted by contractors from inside VGo.
             *
             * A submission is NOT a bill. It becomes one only when accounting
             * approves it — until then it must not appear in payables, or an
             * unreviewed figure could be paid on its way through.
             *
             * `user_id` is the authenticated submitter and is the only identity
             * this feature trusts. The name printed on the document is recorded
             * for reference but never used to decide who is owed.
             *
             * Note what is absent: no bank account, routing or SWIFT column.
             * Payment details are read off the attached PDF at approval time and
             * never enter the database.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_contractor_invoices` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `contact_id` INT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'submitted',
                `invoice_number` VARCHAR(100) NULL,
                `issued_at` DATE NULL,
                `period_label` VARCHAR(100) NULL,
                `period_start` DATE NULL,
                `period_end` DATE NULL,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `subtotal` DECIMAL(15,4) NULL,
                `tax_total` DECIMAL(15,4) NULL,
                `total` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `notes` TEXT NULL,
                `line_items` TEXT NULL,
                `extraction` TEXT NULL,
                `document_id` INT NULL,
                `attachment_id` INT NULL,
                `submitted_at` DATETIME NULL,
                `decided_at` DATETIME NULL,
                `decided_by` INT NULL,
                `decision_note` TEXT NULL,
                `paid_at` DATETIME NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                KEY `acc_ci_user` (`user_id`, `status`),
                KEY `acc_ci_status` (`status`),
                KEY `acc_ci_document` (`document_id`),
                KEY `acc_ci_deleted` (`deleted_at`)
            )$eng");

            // One vendor record per contractor, so their invoices, payments and
            // history all land on the same payables account year after year.
            self::addColumnIfMissing('acc_contacts', 'user_id',
                "ALTER TABLE `acc_contacts` ADD COLUMN `user_id` INT NULL, ADD KEY `acc_contact_user` (`user_id`)");

            // Sales-agent dimension. Added as ALTERs because both tables predate it.
            self::addColumnIfMissing('acc_documents', 'user_id',
                "ALTER TABLE `acc_documents` ADD COLUMN `user_id` INT NULL, ADD KEY `acc_doc_user` (`user_id`)");
            self::addColumnIfMissing('acc_transactions', 'user_id',
                "ALTER TABLE `acc_transactions` ADD COLUMN `user_id` INT NULL, ADD KEY `acc_tx_user` (`user_id`)");

            /**
             * Bank-feed state on a transaction. QuickBooks distinguishes cleared
             * (C — seen on a statement) from reconciled (R — locked into a
             * finished reconciliation), and so do we: `cleared_at` is set the
             * moment a statement line is matched or added, `reconciliation_id`
             * only when a period is closed. Without the distinction, the tick
             * list on the reconcile screen has nothing to pre-tick.
             */
            self::addColumnIfMissing('acc_transactions', 'bank_line_id',
                "ALTER TABLE `acc_transactions` ADD COLUMN `bank_line_id` INT NULL, ADD KEY `acc_tx_bank_line` (`bank_line_id`)");
            $addedCleared = self::addColumnIfMissing('acc_transactions', 'cleared_at',
                "ALTER TABLE `acc_transactions` ADD COLUMN `cleared_at` DATETIME NULL");
            $addedRecId = self::addColumnIfMissing('acc_transactions', 'reconciliation_id',
                "ALTER TABLE `acc_transactions` ADD COLUMN `reconciliation_id` INT NULL, ADD KEY `acc_tx_reconciliation` (`reconciliation_id`)");
            if ($addedCleared || $addedRecId) self::backfillClearedState();

            // A reconciliation's beginning balance: the ending balance of the
            // last one closed on this account. Stored, not recomputed, so a
            // later edit to an old transaction cannot silently rewrite history.
            self::addColumnIfMissing('acc_reconciliations', 'opening_balance',
                "ALTER TABLE `acc_reconciliations` ADD COLUMN `opening_balance` DECIMAL(15,4) NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {
            error_log('AccSchema::ensure: ' . $e->getMessage());
        }
    }

    private static function columnExists($table, $column)
    {
        try {
            $r = DB::fetch(
                "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            return $r && (int)$r['n'] > 0;
        } catch (\Throwable $e) {
            // Fail closed: pretend it exists rather than retry a failing ALTER forever.
            return true;
        }
    }

    /** @return bool true when the column was actually added by this call. */
    private static function addColumnIfMissing($table, $column, $ddl)
    {
        if (self::columnExists($table, $column)) return false;
        try { DB::query($ddl); return true; }
        catch (\Throwable $e) { error_log("AccSchema add $table.$column: " . $e->getMessage()); return false; }
    }

    /**
     * Carry the old `reconciled` flag into the new cleared/locked pair.
     *
     * Runs exactly once, when the columns are first added. Without it, every
     * transaction reconciled before this release would come back onto the next
     * worksheet unticked — and the beginning balance would then be counted
     * twice, which is the one arithmetic error a reconciliation must not make.
     */
    private static function backfillClearedState()
    {
        try {
            DB::query(
                "UPDATE `acc_transactions` t
                    SET t.cleared_at = COALESCE(t.cleared_at, t.updated_at, t.created_at, NOW()),
                        t.reconciliation_id = COALESCE(t.reconciliation_id, (
                            SELECT r.id FROM `acc_reconciliations` r
                             WHERE r.account_id = t.account_id AND r.deleted_at IS NULL
                               AND r.reconciled = 1 AND r.ended_at >= t.paid_at
                          ORDER BY r.ended_at ASC, r.id ASC LIMIT 1))
                  WHERE t.reconciled = 1 AND t.deleted_at IS NULL"
            );
        } catch (\Throwable $e) {
            error_log('AccSchema::backfillClearedState: ' . $e->getMessage());
        }
    }

    /** Absolute path of the attachment directory, created on demand. */
    public static function attachmentDir()
    {
        $dir = dirname(dirname(__DIR__)) . '/' . self::ATTACHMENT_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
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

        // Unlink the uploaded files before the rows that point at them go away,
        // otherwise the directory silently accumulates orphans forever.
        try {
            $root = dirname(dirname(__DIR__)) . '/';
            foreach (DB::fetchAll("SELECT path FROM acc_attachments") as $a) {
                $p = $root . ltrim((string)$a['path'], '/');
                if (strpos(realpath(dirname($p)) ?: '', realpath(self::attachmentDir()) ?: 'x') === 0 && is_file($p)) {
                    @unlink($p);
                }
            }
        } catch (\Throwable $e) {
            error_log('AccSchema::wipe attachments: ' . $e->getMessage());
        }

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
