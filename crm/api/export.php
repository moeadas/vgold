<?php
/**
 * Victory Genomics CRM — Data Export API
 * Exports full database: leads, interactions, WhatsApp messages, VoIP calls
 * Formats: CSV, JSON, XLSX (via CSV)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();

// Only admins and sales managers can export
if (!hasRole('Admin') && !hasRole('Sales Manager')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only Admin and Sales Manager can export data']);
    exit;
}

$format = $_GET['format'] ?? 'csv';
$scope  = $_GET['scope'] ?? 'all'; // all, leads, interactions, whatsapp, voip

try {
    $db = Database::getInstance();

    if ($format === 'json') {
        exportJSON($db, $scope);
    } else {
        exportCSV($db, $scope);
    }

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Export error: ' . $e->getMessage()]);
}

// ─────────────────────────────────────
// JSON EXPORT
// ─────────────────────────────────────
function exportJSON($db, $scope) {
    $data = [];

    if ($scope === 'all' || $scope === 'leads') {
        $data['leads'] = $db->query("
            SELECT l.*, u1.full_name as assigned_to_name, u2.full_name as created_by_name
            FROM leads l
            LEFT JOIN users u1 ON l.assigned_to = u1.user_id
            LEFT JOIN users u2 ON l.created_by = u2.user_id
            ORDER BY l.lead_id
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($scope === 'all' || $scope === 'interactions') {
        $data['interactions'] = $db->query("
            SELECT i.*, l.company_name, l.contact_person, u.full_name as user_name
            FROM interactions i
            LEFT JOIN leads l ON i.lead_id = l.lead_id
            LEFT JOIN users u ON i.user_id = u.user_id
            ORDER BY i.interaction_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($scope === 'all' || $scope === 'whatsapp') {
        $data['whatsapp_messages'] = $db->query("
            SELECT wm.*, l.company_name, l.contact_person, u.full_name as user_name
            FROM whatsapp_messages wm
            LEFT JOIN leads l ON wm.lead_id = l.lead_id
            LEFT JOIN users u ON wm.user_id = u.user_id
            ORDER BY wm.created_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($scope === 'all' || $scope === 'voip') {
        $data['voip_calls'] = $db->query("
            SELECT vc.*, l.company_name, l.contact_person, u.full_name as user_name
            FROM voip_calls vc
            LEFT JOIN leads l ON vc.lead_id = l.lead_id
            LEFT JOIN users u ON vc.user_id = u.user_id
            ORDER BY vc.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $filename = 'vg_crm_export_' . date('Y-m-d_His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// ─────────────────────────────────────
// CSV EXPORT (multi-sheet via ZIP)
// ─────────────────────────────────────
function exportCSV($db, $scope) {
    // If exporting all, create a ZIP with multiple CSV files
    if ($scope === 'all') {
        $tmpDir = sys_get_temp_dir() . '/vg_export_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // Leads
        exportTableToCSV($db, $tmpDir . '/leads.csv', "
            SELECT l.lead_id, l.company_name, l.contact_person, l.title_position, l.email, l.phone, l.mobile,
                   l.website, l.country, l.city, l.region, l.address, l.lead_type, l.lead_status, l.lead_source,
                   l.priority, l.facility_type, l.specialization, l.number_of_horses,
                   l.facebook_url, l.instagram_url, l.linkedin_url, l.twitter_url, l.youtube_url,
                   l.notes, u1.full_name as assigned_to, u2.full_name as created_by, l.created_at, l.updated_at
            FROM leads l
            LEFT JOIN users u1 ON l.assigned_to = u1.user_id
            LEFT JOIN users u2 ON l.created_by = u2.user_id
            ORDER BY l.lead_id
        ");

        // Interactions
        exportTableToCSV($db, $tmpDir . '/interactions.csv', "
            SELECT i.interaction_id, l.company_name, l.contact_person, i.interaction_type, i.interaction_date,
                   i.subject, i.notes, i.outcome, u.full_name as user_name, i.created_at
            FROM interactions i
            LEFT JOIN leads l ON i.lead_id = l.lead_id
            LEFT JOIN users u ON i.user_id = u.user_id
            ORDER BY i.interaction_date DESC
        ");

        // WhatsApp Messages
        exportTableToCSV($db, $tmpDir . '/whatsapp_messages.csv', "
            SELECT wm.message_id, l.company_name, l.contact_person, wm.direction, wm.from_number, wm.to_number,
                   wm.message_body, wm.status, wm.media_url, u.full_name as sent_by, wm.sent_at,
                   wm.delivered_at, wm.read_at, wm.created_at
            FROM whatsapp_messages wm
            LEFT JOIN leads l ON wm.lead_id = l.lead_id
            LEFT JOIN users u ON wm.user_id = u.user_id
            ORDER BY wm.created_at ASC
        ");

        // VoIP Calls
        exportTableToCSV($db, $tmpDir . '/voip_calls.csv', "
            SELECT vc.call_id, l.company_name, l.contact_person, vc.direction, vc.from_number, vc.to_number,
                   vc.status, vc.duration_seconds, vc.outcome, vc.notes, u.full_name as agent,
                   vc.started_at, vc.ended_at, vc.created_at
            FROM voip_calls vc
            LEFT JOIN leads l ON vc.lead_id = l.lead_id
            LEFT JOIN users u ON vc.user_id = u.user_id
            ORDER BY vc.created_at DESC
        ");

        // Create tar.gz archive
        $tarFile = sys_get_temp_dir() . '/vg_crm_export_' . date('Y-m-d_His') . '.tar';
        $targzFile = $tarFile . '.gz';

        $phar = new PharData($tarFile);
        foreach (glob($tmpDir . '/*.csv') as $csvFile) {
            $phar->addFile($csvFile, basename($csvFile));
        }
        $phar->compress(Phar::GZ);

        // Remove the uncompressed tar
        @unlink($tarFile);

        // Send tar.gz
        $filename = 'vg_crm_export_' . date('Y-m-d_His') . '.tar.gz';
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($targzFile));
        header('Cache-Control: no-cache');
        readfile($targzFile);

        // Cleanup
        array_map('unlink', glob($tmpDir . '/*.csv'));
        rmdir($tmpDir);
        unlink($targzFile);

    } else {
        // Single scope CSV
        $queries = [
            'leads' => "
                SELECT l.lead_id, l.company_name, l.contact_person, l.title_position, l.email, l.phone, l.mobile,
                       l.website, l.country, l.city, l.region, l.address, l.lead_type, l.lead_status, l.lead_source,
                       l.priority, l.facility_type, l.specialization, l.number_of_horses,
                       l.notes, u1.full_name as assigned_to, u2.full_name as created_by, l.created_at, l.updated_at
                FROM leads l
                LEFT JOIN users u1 ON l.assigned_to = u1.user_id
                LEFT JOIN users u2 ON l.created_by = u2.user_id
                ORDER BY l.lead_id",
            'interactions' => "
                SELECT i.interaction_id, l.company_name, l.contact_person, i.interaction_type, i.interaction_date,
                       i.subject, i.notes, i.outcome, u.full_name as user_name, i.created_at
                FROM interactions i
                LEFT JOIN leads l ON i.lead_id = l.lead_id
                LEFT JOIN users u ON i.user_id = u.user_id
                ORDER BY i.interaction_date DESC",
            'whatsapp' => "
                SELECT wm.message_id, l.company_name, l.contact_person, wm.direction, wm.from_number, wm.to_number,
                       wm.message_body, wm.status, u.full_name as sent_by, wm.sent_at, wm.created_at
                FROM whatsapp_messages wm
                LEFT JOIN leads l ON wm.lead_id = l.lead_id
                LEFT JOIN users u ON wm.user_id = u.user_id
                ORDER BY wm.created_at ASC",
            'voip' => "
                SELECT vc.call_id, l.company_name, l.contact_person, vc.direction, vc.from_number, vc.to_number,
                       vc.status, vc.duration_seconds, vc.outcome, vc.notes, u.full_name as agent,
                       vc.started_at, vc.ended_at, vc.created_at
                FROM voip_calls vc
                LEFT JOIN leads l ON vc.lead_id = l.lead_id
                LEFT JOIN users u ON vc.user_id = u.user_id
                ORDER BY vc.created_at DESC",
        ];

        if (!isset($queries[$scope])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid scope']);
            return;
        }

        $filename = 'vg_crm_' . $scope . '_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');

        $output = fopen('php://output', 'w');
        // Add BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $rows = $db->query($queries[$scope])->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            fputcsv($output, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
    }
}

/**
 * Helper: export a SQL query to a CSV file
 */
function exportTableToCSV($db, $filePath, $sql) {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $fp = fopen($filePath, 'w');
    // BOM for Excel
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    if (!empty($rows)) {
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
    }
    fclose($fp);
}
