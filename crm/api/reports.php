<?php
/**
 * Victory Genomics CRM — Reports API (native VGold SPA).
 *
 * Aggregated pipeline metrics for the native Reports view. Runs through the
 * unified session (mount.php enforces the crm.reports module) and uses the
 * legacy Database wrapper so the bridge's table rewrite maps bare table names
 * (leads, interactions, proposals) to their crm_* counterparts.
 *
 * GET /crm/api/reports.php  →  { success, data: { totals, interactions, proposals, by_status, by_region, by_month } }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('X-LiteSpeed-Cache-Control: no-cache');

startSecureSession();
requireLogin();

$db = Database::getInstance();

try {
    $totals = $db->query(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN lead_status = 'Won' THEN 1 ELSE 0 END) won,
                SUM(CASE WHEN lead_status = 'Lost' THEN 1 ELSE 0 END) lost
         FROM leads"
    )->fetch(PDO::FETCH_ASSOC);

    $byStatus = $db->query(
        "SELECT lead_status AS label, COUNT(*) AS value FROM leads GROUP BY lead_status ORDER BY value DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byRegion = $db->query(
        "SELECT COALESCE(NULLIF(region, ''), 'Unknown') AS label, COUNT(*) AS value FROM leads GROUP BY region ORDER BY value DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byMonth = $db->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS value
         FROM leads
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY label ORDER BY label ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $interactions = $db->query(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN interaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) last30
         FROM interactions"
    )->fetch(PDO::FETCH_ASSOC);

    $proposals = ['total' => 0, 'accepted' => 0];
    try {
        $proposals = $db->query(
            "SELECT COUNT(*) total, SUM(CASE WHEN status = 'Accepted' THEN 1 ELSE 0 END) accepted FROM proposals"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* proposals table optional */ }

    echo json_encode([
        'success' => true,
        'data' => [
            'totals'       => $totals,
            'interactions' => $interactions,
            'proposals'    => $proposals,
            'by_status'    => $byStatus,
            'by_region'    => $byRegion,
            'by_month'     => $byMonth,
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
