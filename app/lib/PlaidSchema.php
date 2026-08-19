<?php
/**
 * Runtime schema guard for the Plaid bank feed.
 *
 * Kept out of AccSchema on purpose: that class asserts a fixed table count and
 * is the thing every Accounting request pays for. These three tables only
 * matter once someone actually connects a bank, so they are created lazily by
 * PlaidController and nothing else changes shape.
 *
 * Plaid does NOT get its own transaction store. Everything lands in the
 * existing acc_bank_imports / acc_bank_lines tables that the statement importer
 * and the reconciliation engine already use, so a Plaid line and a PDF line are
 * the same kind of thing to every screen downstream.
 */
class PlaidSchema
{
    private static $done = false;

    public static function ensure(): bool
    {
        if (self::$done) return true;
        try {
            $eng = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            /**
             * One row per Plaid Item — i.e. per bank login. A single BofA
             * connection covers checking and both cards, so this table stays
             * tiny; the per-account detail lives in acc_plaid_accounts.
             *
             * `access_token` is stored SEALED (see Plaid::sealToken). `cursor`
             * is /transactions/sync's position marker — losing it means a full
             * re-read, not data loss, because dedupe_key makes replays safe.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_bank_connections` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `provider` VARCHAR(16) NOT NULL DEFAULT 'plaid',
                `environment` VARCHAR(16) NOT NULL DEFAULT 'sandbox',
                `item_id` VARCHAR(120) NOT NULL,
                `institution_id` VARCHAR(60) NULL,
                `institution_name` VARCHAR(191) NULL,
                `access_token` TEXT NULL,
                `cursor` TEXT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'active',
                `error_code` VARCHAR(64) NULL,
                `error_message` VARCHAR(500) NULL,
                `consent_expires_at` DATETIME NULL,
                `disconnect_at` DATETIME NULL,
                `last_sync_at` DATETIME NULL,
                `last_sync_status` VARCHAR(500) NULL,
                `last_webhook_at` DATETIME NULL,
                `created_by` INT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY `uniq_acc_conn_item` (`item_id`),
                KEY `acc_conn_status` (`status`)
            )$eng");

            /**
             * A bank account as Plaid sees it, optionally mapped onto one of the
             * acc_accounts rows the ledger already uses.
             *
             * `account_id` NULL means "seen but not mapped" — we deliberately do
             * NOT auto-create ledger accounts, because a stray savings or credit
             * line appearing in the chart of accounts by itself would be worse
             * than an unmapped row sitting in a list.
             *
             * `sync_from` is the cutover date. The three BofA accounts already
             * hold years of PDF-imported history, so pulling Plaid's full 24
             * months would duplicate it. Default is the day after the newest
             * existing bank line for that account.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_plaid_accounts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `connection_id` INT NOT NULL,
                `plaid_account_id` VARCHAR(120) NOT NULL,
                `account_id` INT NULL,
                `name` VARCHAR(191) NULL,
                `official_name` VARCHAR(191) NULL,
                `mask` VARCHAR(20) NULL,
                `type` VARCHAR(40) NULL,
                `subtype` VARCHAR(40) NULL,
                `currency_code` VARCHAR(8) NULL,
                `current_balance` DECIMAL(15,4) NULL,
                `available_balance` DECIMAL(15,4) NULL,
                `sync_from` DATE NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_acc_pa` (`plaid_account_id`),
                KEY `acc_pa_conn` (`connection_id`),
                KEY `acc_pa_acct` (`account_id`)
            )$eng");

            /**
             * Every webhook Plaid sends, verified or not.
             *
             * Kept because the failure modes here are asynchronous and invisible
             * — a signature that stopped verifying, or a PENDING_DISCONNECT
             * nobody acted on, is only diagnosable after the fact if the
             * envelope was recorded when it arrived.
             */
            DB::query("CREATE TABLE IF NOT EXISTS `acc_plaid_events` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `item_id` VARCHAR(120) NULL,
                `webhook_type` VARCHAR(60) NULL,
                `webhook_code` VARCHAR(60) NULL,
                `verified` TINYINT(1) NOT NULL DEFAULT 0,
                `verify_note` VARCHAR(191) NULL,
                `payload` TEXT NULL,
                `result` VARCHAR(500) NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `acc_pe_item` (`item_id`),
                KEY `acc_pe_created` (`created_at`)
            )$eng");

            self::$done = true;
        } catch (\Throwable $e) {
            error_log('PlaidSchema::ensure ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /** True only if all three tables really exist — gate writes on this. */
    public static function ready(): bool
    {
        self::ensure();
        try {
            foreach (['acc_bank_connections','acc_plaid_accounts','acc_plaid_events'] as $t) {
                if (!DB::fetch("SHOW TABLES LIKE '$t'")) return false;
            }
            return true;
        } catch (\Throwable $e) { return false; }
    }
}
