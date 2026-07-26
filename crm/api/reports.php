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
        "SELECT COALESCE(NULLIF(TRIM(lead_status), ''), 'Unspecified') AS label, COUNT(*) AS value FROM leads GROUP BY label ORDER BY value DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byRegion = $db->query(
        "SELECT COALESCE(NULLIF(TRIM(region), ''), 'Unknown') AS label, COUNT(*) AS value FROM leads GROUP BY label ORDER BY value DESC"
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

    // Leads by type (original "Leads by Type" breakdown)
    $byType = [];
    try {
        $byType = $db->query(
            "SELECT COALESCE(NULLIF(TRIM(lead_type), ''), 'Unspecified') AS label, COUNT(*) AS value
             FROM leads GROUP BY label ORDER BY value DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* column optional */ }

    // Interaction type breakdown (Calls / Emails / Meetings) — original stat cards
    $interactionTypes = ['calls' => 0, 'emails' => 0, 'meetings' => 0];
    try {
        $interactionTypes = $db->query(
            "SELECT SUM(CASE WHEN interaction_type IN ('Call','VoIP Call') THEN 1 ELSE 0 END) calls,
                    SUM(CASE WHEN interaction_type = 'Email' THEN 1 ELSE 0 END) emails,
                    SUM(CASE WHEN interaction_type = 'Meeting' THEN 1 ELSE 0 END) meetings,
                    SUM(CASE WHEN interaction_type = 'WhatsApp' THEN 1 ELSE 0 END) whatsapp
             FROM interactions"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* optional */ }

    // Team performance — per sales agent. Port of the original Reports page's
    // $userPerformance leaderboard (assigned leads, interactions, deals won,
    // win rate). Guarded so a schema difference can't break the whole report.
    $teamPerformance = [];
    try {
        // Scalar subqueries per agent — avoids the cartesian product that a
        // leads+interactions multi-JOIN produces (which inflated deals_won by the
        // interaction count and zeroed the interactions column).
        $rows = $db->query(
            "SELECT u.full_name AS full_name, u.role AS role,
                    (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.user_id) AS assigned_leads,
                    (SELECT COUNT(*) FROM interactions i WHERE i.user_id = u.user_id) AS interactions,
                    (SELECT COUNT(*) FROM leads l WHERE l.assigned_to = u.user_id AND l.lead_status = 'Won') AS deals_won
             FROM users u
             WHERE u.status = 'Active'
             ORDER BY assigned_leads DESC, interactions DESC
             LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $assigned = (int) $r['assigned_leads'];
            $r['win_rate'] = $assigned > 0 ? round(((int) $r['deals_won'] / $assigned) * 100, 1) : 0;
            $teamPerformance[] = $r;
        }
    } catch (\Throwable $e) { /* users table shape optional */ }

    echo json_encode([
        'success' => true,
        'data' => [
            'totals'            => $totals,
            'interactions'      => $interactions,
            'interaction_types' => $interactionTypes,
            'proposals'         => $proposals,
            'by_status'         => $byStatus,
            'by_region'         => $byRegion,
            'by_type'           => $byType,
            'by_month'          => $byMonth,
            'team_performance'  => $teamPerformance,
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
