<?php
/**
 * Victory Genomics CRM - Export Leads
 * Fixed SQL column reference (full_name instead of first_name/last_name)
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

startSecureSession();
requireLogin();
requireRole(['Admin', 'Sales Manager']);

$currentUser = getCurrentUser();
$db = Database::getInstance();

try {
    // Fetch all leads — FIXED: users table uses full_name, not first_name/last_name
    $leads = $db->query("
        SELECT 
            l.lead_id, l.company_name, l.contact_person, l.title_position, l.lead_type,
            l.email, l.phone, l.mobile, l.website,
            l.region, l.country, l.city, l.address,
            l.specialization, l.facility_type, l.number_of_horses, l.horse_breed, l.horse_sex,
            l.facebook_url, l.instagram_url, l.linkedin_url, l.twitter_url, l.youtube_url,
            l.notes, l.lead_status, l.lead_source, l.priority,
            l.created_at, l.updated_at,
            u.full_name as assigned_to
        FROM leads l
        LEFT JOIN users u ON l.assigned_to = u.user_id
        ORDER BY l.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($leads)) {
        $_SESSION['error'] = 'No leads found to export.';
        header('Location: settings.php');
        exit;
    }
    
    logActivity($currentUser['user_id'], 'Export Leads', 'Lead', null, "Exported " . count($leads) . " leads to CSV");
    
    $filename = 'leads_export_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    if (!empty($leads)) {
        fputcsv($output, array_keys($leads[0]));
    }
    
    foreach ($leads as $lead) {
        fputcsv($output, $lead);
    }
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
    header('Location: settings.php');
    exit;
}
