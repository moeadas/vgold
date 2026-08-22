<?php
/**
 * Sales Dashboard schema (lazy, no migration step).
 *
 * Three tables, created on demand exactly like AccSchema / Schema::ensureCrm():
 *
 *   crm_sales             — the sale ledger the dashboard measures. A row is
 *                           either recorded by hand or mirrored from an
 *                           Accounting invoice (source = 'accounting'), keyed
 *                           idempotently on acc_document_id.
 *   crm_sales_targets     — one target per person per period (month/quarter/
 *                           year). user_id 0 means the whole team.
 *   crm_sales_commission  — the per-person commission rate. Admin-only.
 *
 * ⚠️ Read [[vgold-schema-incident]] before trusting a CREATE here: 21 crm_*
 * tables once came out of a migration with no PRIMARY KEY and no AUTO_INCREMENT,
 * and because this MySQL runs WITHOUT STRICT_TRANS_TABLES the inserts did not
 * error — they silently wrote id 0 over and over. So ensure() does not just
 * create the tables, it VERIFIES that each one really has an auto-increment
 * primary key and reports the answer. Every write path is gated on that answer
 * rather than on "the CREATE didn't throw".
 */
class SalesSchema
{
    const TABLES = ['crm_sales', 'crm_sales_targets', 'crm_sales_commission'];

    /** null = not checked yet, true/false = the verified answer. */
    private static $ok = null;

    /** Create if missing, then verify. Returns false if the tables are unusable. */
    public static function ensure()
    {
        if (self::$ok !== null) return self::$ok;
        self::$ok = false;

        try {
            $eng = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

            DB::query("CREATE TABLE IF NOT EXISTS `crm_sales` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `lead_id` INT NULL,
                `client_name` VARCHAR(200) NOT NULL DEFAULT '',
                `product` VARCHAR(255) NULL,
                `rep_user_id` INT NOT NULL DEFAULT 0,
                `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `collected_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `sale_date` DATE NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'won',
                `commission_rate` DECIMAL(6,3) NOT NULL DEFAULT 0,
                `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `rate_override` TINYINT(1) NOT NULL DEFAULT 0,
                `acc_document_id` INT NULL,
                `source` VARCHAR(16) NOT NULL DEFAULT 'manual',
                `notes` TEXT NULL,
                `created_by` INT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_sale_acc_doc` (`acc_document_id`),
                KEY `idx_sale_rep` (`rep_user_id`),
                KEY `idx_sale_date` (`sale_date`),
                KEY `idx_sale_lead` (`lead_id`),
                KEY `idx_sale_status` (`status`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `crm_sales_targets` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL DEFAULT 0,
                `period_type` VARCHAR(10) NOT NULL DEFAULT 'month',
                `period_start` DATE NOT NULL,
                `target_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `target_deals` INT NOT NULL DEFAULT 0,
                `currency_code` VARCHAR(8) NOT NULL DEFAULT 'USD',
                `notes` VARCHAR(255) NULL,
                `created_by` INT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_target_slot` (`user_id`, `period_type`, `period_start`),
                KEY `idx_target_period` (`period_type`, `period_start`)
            )$eng");

            DB::query("CREATE TABLE IF NOT EXISTS `crm_sales_commission` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `rate` DECIMAL(6,3) NOT NULL DEFAULT 0,
                `basis` VARCHAR(16) NOT NULL DEFAULT 'collected',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `effective_from` DATE NULL,
                `notes` VARCHAR(255) NULL,
                `updated_by` INT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_commission_user` (`user_id`)
            )$eng");

            self::$ok = self::verify();
            if (!self::$ok) error_log('SalesSchema::ensure: tables present but not usable (see verify())');
        } catch (\Throwable $e) {
            error_log('SalesSchema::ensure: ' . $e->getMessage());
            self::$ok = false;
        }
        return self::$ok;
    }

    /**
     * Every table exists AND its `id` is an auto-increment primary key.
     *
     * This is the exact failure the 2026 CRM migration produced, and it is
     * invisible without this check: inserts "succeed", every row lands with
     * id 0, and the feature looks like it silently does nothing.
     */
    public static function verify()
    {
        foreach (self::TABLES as $t) {
            try {
                $col = DB::fetch("SHOW COLUMNS FROM `$t` LIKE 'id'");
            } catch (\Throwable $e) {
                error_log("SalesSchema::verify: $t missing — " . $e->getMessage());
                return false;
            }
            if (!$col) { error_log("SalesSchema::verify: $t has no id column"); return false; }
            if (strtoupper((string)($col['Key'] ?? '')) !== 'PRI') {
                error_log("SalesSchema::verify: $t.id is not the primary key"); return false;
            }
            if (stripos((string)($col['Extra'] ?? ''), 'auto_increment') === false) {
                error_log("SalesSchema::verify: $t.id is not AUTO_INCREMENT"); return false;
            }
        }
        return true;
    }

    /** Machine-readable health, for the diagnostics endpoint and _x.php probes. */
    public static function report()
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $row = ['table' => $t, 'exists' => false, 'pk' => false, 'auto_increment' => false, 'rows' => null];
            try {
                $col = DB::fetch("SHOW COLUMNS FROM `$t` LIKE 'id'");
                if ($col) {
                    $row['exists'] = true;
                    $row['pk'] = strtoupper((string)($col['Key'] ?? '')) === 'PRI';
                    $row['auto_increment'] = stripos((string)($col['Extra'] ?? ''), 'auto_increment') !== false;
                    $row['rows'] = (int)(DB::fetch("SELECT COUNT(*) c FROM `$t`")['c'] ?? 0);
                }
            } catch (\Throwable $e) { $row['error'] = $e->getMessage(); }
            $out[] = $row;
        }
        return $out;
    }
}
