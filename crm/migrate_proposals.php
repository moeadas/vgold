<?php
/**
 * Victory Genomics CRM — Proposals Migration
 *
 * Creates the proposals table, adds proposal-related settings, and seeds
 * the initial estimate_next_number.
 *
 * Run once on production:
 *   curl -s "https://crm.victorygenomics.com/migrate_proposals.php"
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/migration-guard.php';  // admin/CLI only

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('X-LiteSpeed-Cache-Control: no-cache');

$db  = Database::getInstance();
$pdo = $db->getConnection();

$results = [];

// ── 1. Create proposals table ───────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proposals (
            proposal_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            estimate_number INT UNSIGNED NOT NULL,
            proposal_date   DATE NOT NULL,
            customer_company VARCHAR(255) DEFAULT NULL,
            contact_name    VARCHAR(255) DEFAULT NULL,
            customer_address TEXT DEFAULT NULL,
            line_items      JSON NOT NULL,
            notes           TEXT DEFAULT NULL,
            accepted_by     VARCHAR(255) DEFAULT NULL,
            accepted_date   DATE DEFAULT NULL,
            total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status          ENUM('Draft','Sent','Accepted','Declined') NOT NULL DEFAULT 'Draft',
            created_by      INT UNSIGNED NOT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_estimate_number (estimate_number),
            INDEX idx_status (status),
            INDEX idx_created_by (created_by),
            INDEX idx_proposal_date (proposal_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = 'OK: proposals table created / already exists';
} catch (Exception $e) {
    $results[] = 'ERROR creating proposals table: ' . $e->getMessage();
}

// ── 2. Seed proposal-related settings ────────────────────
$settingsToSeed = [
    ['proposal_company_name',    'Victory Genomics, INC.',                         'text'],
    ['proposal_company_address', "66 High St, Unit 38\nGuilford, CT 06437 US",     'text'],
    ['proposal_company_phone',   '+18483469499',                                   'text'],
    ['proposal_company_email',   'accounting@victorygenomics.com',                 'text'],
    ['proposal_company_website', 'victorygenomics.com',                            'text'],
    ['proposal_next_number',     '1006',                                           'text'],
];

foreach ($settingsToSeed as [$key, $value, $type]) {
    try {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $exists->execute([$key]);
        if ((int)$exists->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)")
                ->execute([$key, $value, $type]);
            $results[] = "OK: setting '$key' seeded";
        } else {
            $results[] = "SKIP: setting '$key' already exists";
        }
    } catch (Exception $e) {
        $results[] = "ERROR seeding '$key': " . $e->getMessage();
    }
}

echo "Proposals migration results:\n";
echo implode("\n", $results) . "\n";
echo "\nDone. You may delete this file.\n";
