<?php
/**
 * Victory Genomics CRM - Google Sheets Sync Engine
 *
 * Fetches public Google Sheets as CSV, parses rows, applies field mapping,
 * deduplicates, and imports new leads. Tracks last synced row to avoid
 * re-importing old data.
 *
 * Called by:
 *   1. Admin UI "Sync Now" button (session-authenticated)
 *   2. Cron endpoint (api/cron-sync.php with secret key)
 *
 * POST /api/sheets-sync.php?action=sync&id=<endpoint_id>   — sync one endpoint
 * POST /api/sheets-sync.php?action=sync_all                — sync all enabled endpoints
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/twilio.php';

// ── Prevent proxy/CDN caching (SiteGround Dynamic Cache / LiteSpeed) ─
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

// ─── Determine auth mode ─────────────────────────────────────
// Mode 1: Session-based (admin UI) — check for CSRF + session
// Mode 2: Cron-based — called from cron-sync.php which already verified secret
$isCronMode = defined('SHEETS_CRON_AUTHENTICATED') && SHEETS_CRON_AUTHENTICATED === true;

if (!$isCronMode) {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    startSecureSession();
    requireLogin();
    requireRole(['Admin']);

    // Verify CSRF for POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        }
    }
}

// ─── Only auto-execute when called directly (not when included) ─
if (!defined('SHEETS_SYNC_INCLUDED')) {
    $db = Database::getInstance();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'sync':
            $endpointId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
            if (!$endpointId) {
                echo json_encode(['success' => false, 'message' => 'Endpoint ID required.']);
                exit;
            }
            $result = syncEndpoint($db, $endpointId);
            echo json_encode($result);
            break;

        case 'sync_all':
            $results = syncAllEndpoints($db);
            echo json_encode(['success' => true, 'results' => $results]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action. Use sync or sync_all.']);
    }
    exit;
}


// ══════════════════════════════════════════════════════════════
//  Core Sync Functions
// ══════════════════════════════════════════════════════════════

/**
 * Sync all enabled endpoints that have a sheet_url configured.
 */
function syncAllEndpoints($db) {
    $endpoints = $db->query(
        "SELECT endpoint_id, name FROM webhook_endpoints WHERE enabled = 1 AND sheet_url IS NOT NULL AND sheet_url != ''"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $results = [];
    foreach ($endpoints as $ep) {
        $results[] = [
            'endpoint_id' => $ep['endpoint_id'],
            'name'        => $ep['name'],
            'result'      => syncEndpoint($db, intval($ep['endpoint_id'])),
        ];
    }
    return $results;
}

/**
 * Sync a single endpoint: fetch sheet CSV, parse, import new rows.
 */
function syncEndpoint($db, $endpointId) {
    $endpoint = $db->query(
        "SELECT * FROM webhook_endpoints WHERE endpoint_id = ?", [$endpointId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$endpoint) {
        return ['success' => false, 'message' => 'Endpoint not found.'];
    }
    if (intval($endpoint['enabled']) !== 1) {
        return ['success' => false, 'message' => 'Endpoint is disabled.'];
    }
    if (empty($endpoint['sheet_url'])) {
        return ['success' => false, 'message' => 'No Google Sheet URL configured.'];
    }

    // ── 1. Build the CSV export URL ──────────────────────────
    $csvUrl = buildCsvUrl($endpoint['sheet_url'], $endpoint['sheet_name'] ?? null);
    if (!$csvUrl) {
        return ['success' => false, 'message' => 'Invalid Google Sheet URL. Use a standard Google Sheets link.'];
    }

    // ── 2. Fetch CSV from Google ─────────────────────────────
    $csvData = fetchCsv($csvUrl);
    if ($csvData === false) {
        return ['success' => false, 'message' => 'Failed to fetch Google Sheet. Make sure it is set to "Anyone with the link can view".'];
    }

    // ── 3. Parse CSV into rows ───────────────────────────────
    $rows = parseCsv($csvData);
    if (empty($rows) || count($rows) < 2) {
        return ['success' => true, 'message' => 'Sheet is empty or has only headers.', 'created' => 0, 'duplicates' => 0, 'errors' => 0];
    }

    // ── 4. Extract headers (row 0) and data rows ─────────────
    $headers = $rows[0];
    $lastSyncedRow = intval($endpoint['last_synced_row']);
    $totalDataRows = count($rows) - 1; // minus header

    // Only process rows after the last synced position
    $startRow = max(1, $lastSyncedRow + 1); // 1-based (row 1 = first data row after header)

    if ($startRow > $totalDataRows) {
        return ['success' => true, 'message' => 'No new rows to sync.', 'created' => 0, 'duplicates' => 0, 'errors' => 0];
    }

    // ── 5. Parse config ──────────────────────────────────────
    $fieldMapping = json_decode($endpoint['field_mapping'], true) ?: [];
    $leadDefaults = json_decode($endpoint['lead_defaults'] ?? '{}', true) ?: [];
    $assignedTo   = $endpoint['assigned_to'] ? intval($endpoint['assigned_to']) : null;
    $endpointName = $endpoint['name'];

    // ── 6. Process each new row ──────────────────────────────
    $created = 0;
    $duplicates = 0;
    $errors = 0;
    $lastProcessedRow = $lastSyncedRow;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    for ($i = $startRow; $i <= $totalDataRows; $i++) {
        $row = $rows[$i]; // $rows is 0-indexed, row 0=header, row 1=first data

        // Skip completely empty rows
        if (isEmptyRow($row)) {
            $lastProcessedRow = $i;
            continue;
        }

        // Build key-value payload from headers + row
        $payload = [];
        for ($j = 0; $j < count($headers); $j++) {
            $header = trim($headers[$j]);
            if ($header !== '' && isset($row[$j]) && trim($row[$j]) !== '') {
                // Normalize header: lowercase, replace spaces with underscore
                $normalizedHeader = strtolower(preg_replace('/\s+/', '_', $header));
                $payload[$normalizedHeader] = trim($row[$j]);
            }
        }

        if (empty($payload)) {
            $lastProcessedRow = $i;
            continue;
        }

        // Use the processLead function from leads-webhook.php
        $result = processLeadFromSheet($db, $payload, $fieldMapping, $leadDefaults, $assignedTo, $endpointId, $endpointName, $clientIp);

        if ($result['status'] === 'created') $created++;
        elseif ($result['status'] === 'duplicate') $duplicates++;
        else $errors++;

        $lastProcessedRow = $i;
    }

    // ── 7. Update sync tracking ──────────────────────────────
    try {
        // Use direct PDO to avoid any abstraction-layer issues
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE webhook_endpoints SET last_synced_row = :lsr, last_received = NOW() WHERE endpoint_id = :eid"
        );
        $stmt->bindValue(':lsr', $lastProcessedRow, \PDO::PARAM_INT);
        $stmt->bindValue(':eid', $endpointId, \PDO::PARAM_INT);
        $stmt->execute();

        error_log("Sheet sync tracking: endpoint=$endpointId, last_synced_row=$lastProcessedRow, rows_affected=" . $stmt->rowCount());
    } catch (\Exception $e) {
        error_log("Sheet sync tracking UPDATE FAILED: " . $e->getMessage());
    }

    return [
        'success'        => true,
        'message'        => "Synced: $created created, $duplicates duplicates, $errors errors.",
        'created'        => $created,
        'duplicates'     => $duplicates,
        'errors'         => $errors,
        'rows_processed' => $lastProcessedRow - $lastSyncedRow,
    ];
}


// ══════════════════════════════════════════════════════════════
//  Google Sheets CSV Helpers
// ══════════════════════════════════════════════════════════════

/**
 * Convert a Google Sheets URL to a CSV export URL.
 *
 * Supports formats:
 *   https://docs.google.com/spreadsheets/d/SHEET_ID/edit...
 *   https://docs.google.com/spreadsheets/d/SHEET_ID/
 *   SHEET_ID (just the ID)
 */
function buildCsvUrl($sheetUrl, $sheetName = null) {
    $sheetUrl = trim($sheetUrl);

    // Extract sheet ID from URL
    $sheetId = null;

    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sheetUrl, $m)) {
        $sheetId = $m[1];
    } elseif (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $sheetUrl)) {
        // Looks like a bare sheet ID
        $sheetId = $sheetUrl;
    }

    if (!$sheetId) return null;

    $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv";

    if ($sheetName) {
        $url .= '&sheet=' . urlencode($sheetName);
    }

    return $url;
}

/**
 * Fetch CSV content from Google Sheets.
 */
function fetchCsv($url) {
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 30,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header'        => "User-Agent: VictoryGenomicsCRM/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);

    if ($data === false) {
        // Try with curl as fallback
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => 'VictoryGenomicsCRM/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($data === false || $httpCode !== 200) {
                error_log("Sheets CSV fetch failed: HTTP $httpCode for $url");
                return false;
            }
        } else {
            error_log("Sheets CSV fetch failed: file_get_contents failed and curl not available");
            return false;
        }
    }

    // Check for Google's HTML error page (not CSV)
    if (strpos($data, '<!DOCTYPE html>') !== false || strpos($data, '<html') !== false) {
        error_log("Sheets CSV fetch returned HTML instead of CSV - sheet may not be public");
        return false;
    }

    return $data;
}

/**
 * Parse CSV string into array of rows.
 */
function parseCsv($csvData) {
    // Handle BOM
    $csvData = ltrim($csvData, "\xEF\xBB\xBF");

    $rows = [];
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csvData);
    rewind($handle);

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

/**
 * Check if a CSV row is completely empty.
 */
function isEmptyRow($row) {
    foreach ($row as $cell) {
        if (trim($cell) !== '') return false;
    }
    return true;
}


// ══════════════════════════════════════════════════════════════
//  Lead Processing (reuses logic from leads-webhook.php)
// ══════════════════════════════════════════════════════════════

/**
 * Process a single lead from a parsed sheet row.
 */
function processLeadFromSheet($db, $incomingData, $fieldMapping, $leadDefaults, $assignedTo, $endpointId, $endpointName, $clientIp) {
    // ── 1. Apply field mapping ───────────────────────────────
    $mapped = applyFieldMapping($incomingData, $fieldMapping);

    // ── 2. Merge with defaults ───────────────────────────────
    $lead = array_merge([
        'lead_type'    => $leadDefaults['lead_type']    ?? 'Other',
        'lead_source'  => $leadDefaults['lead_source']  ?? 'Other',
        'lead_status'  => $leadDefaults['lead_status']  ?? 'New Lead',
        'priority'     => $leadDefaults['priority']     ?? 'Medium',
        'region'       => $leadDefaults['region']        ?? 'Other',
        'country'      => $leadDefaults['country']       ?? '',
    ], $mapped);

    // ── 3. Ensure company_name is not empty (DB NOT NULL) ────
    if (empty($lead['company_name'])) {
        $lead['company_name'] = $lead['contact_person']
            ?? ('Sheet Import - ' . date('Y-m-d H:i:s'));
    }

    // ── 4. Validate enums ────────────────────────────────────
    $validEnums = [
        'lead_type'   => ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'],
        'lead_source' => ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'],
        'lead_status' => ['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'],
        'priority'    => ['Low','Medium','High','Urgent'],
        'region'      => ['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other'],
        'facility_type' => ['Breeding','Racing','Training','Multi-Purpose','Other', null],
    ];

    foreach ($validEnums as $field => $allowed) {
        if (isset($lead[$field]) && !in_array($lead[$field], $allowed, true)) {
            $lead[$field] = $leadDefaults[$field] ?? 'Other';
        }
    }

    // ── 5. Dedup by email or phone ───────────────────────────
    $email = trim($lead['email'] ?? '');
    $phone = trim($lead['phone'] ?? '');
    $mobile = trim($lead['mobile'] ?? '');

    $dupLead = null;
    if ($email !== '') {
        $dupLead = $db->query(
            "SELECT lead_id, contact_person, email FROM leads WHERE email = ? LIMIT 1", [$email]
        )->fetch(\PDO::FETCH_ASSOC);
    }
    if (!$dupLead && $phone !== '') {
        $norm = preg_replace('/[^0-9]/', '', $phone);
        if ($norm) {
            $dupLead = $db->query(
                "SELECT lead_id, contact_person, phone FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ? LIMIT 1",
                [$norm]
            )->fetch(\PDO::FETCH_ASSOC);
        }
    }
    if (!$dupLead && $mobile !== '') {
        $norm = preg_replace('/[^0-9]/', '', $mobile);
        if ($norm) {
            $dupLead = $db->query(
                "SELECT lead_id, contact_person, mobile FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', '') = ? LIMIT 1",
                [$norm]
            )->fetch(\PDO::FETCH_ASSOC);
        }
    }

    if ($dupLead) {
        logWebhook($db, $endpointId, $dupLead['lead_id'], 'duplicate', json_encode($incomingData),
            "Duplicate of lead #{$dupLead['lead_id']} ({$dupLead['contact_person']})", $clientIp);
        return ['success' => true, 'status' => 'duplicate', 'message' => 'Duplicate lead.'];
    }

    // ── 6. Insert lead ───────────────────────────────────────
    $createdBy = $assignedTo ?: 1;
    $numHorses = isset($lead['number_of_horses']) && $lead['number_of_horses'] !== '' ? intval($lead['number_of_horses']) : null;

    try {
        // Build notes
        $notesParts = [];
        if (!empty($lead['notes'])) $notesParts[] = $lead['notes'];

        // Capture unmapped fields
        $mappedValues = array_values(array_filter(array_map('trim', array_values($lead))));
        $unmapped = [];
        foreach ($incomingData as $k => $v) {
            if ($v === '' || $v === null) continue;
            $tv = trim($v);
            if (!in_array($tv, $mappedValues, true)) $unmapped[] = "$k: $tv";
        }
        if (!empty($unmapped)) $notesParts[] = "--- Additional Data ---\n" . implode("\n", $unmapped);
        $notesParts[] = "[Imported via: $endpointName @ " . date('Y-m-d H:i:s') . "]";
        $notes = implode("\n\n", $notesParts);

        $emptyToNull = function($v) { return (isset($v) && $v !== '') ? $v : null; };

        $db->query("
            INSERT INTO leads (
                lead_type, company_name, contact_person, title_position,
                region, country, city, address,
                phone, mobile, email, website,
                facebook_url, instagram_url, linkedin_url, twitter_url, youtube_url,
                specialization, facility_type, number_of_horses, horse_breed, horse_sex,
                notes, lead_status, lead_source, priority,
                assigned_to, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            $lead['lead_type'],
            $lead['company_name'],
            $emptyToNull($lead['contact_person'] ?? null),
            $emptyToNull($lead['title_position'] ?? null),
            $lead['region'],
            $lead['country'] ?: '',
            $emptyToNull($lead['city'] ?? null),
            $emptyToNull($lead['address'] ?? null),
            $emptyToNull($lead['phone'] ?? null),
            $emptyToNull($lead['mobile'] ?? null),
            $emptyToNull($lead['email'] ?? null),
            $emptyToNull($lead['website'] ?? null),
            $emptyToNull($lead['facebook_url'] ?? null),
            $emptyToNull($lead['instagram_url'] ?? null),
            $emptyToNull($lead['linkedin_url'] ?? null),
            $emptyToNull($lead['twitter_url'] ?? null),
            $emptyToNull($lead['youtube_url'] ?? null),
            $emptyToNull($lead['specialization'] ?? null),
            $emptyToNull($lead['facility_type'] ?? null),
            $numHorses,
            $emptyToNull($lead['horse_breed'] ?? null),
            $emptyToNull($lead['horse_sex'] ?? null),
            $notes,
            $lead['lead_status'],
            $lead['lead_source'],
            $lead['priority'],
            $assignedTo,
            $createdBy,
        ]);

        $leadId = intval($db->getConnection()->lastInsertId());

        // Update endpoint counters (use direct PDO for reliability)
        $pdo = $db->getConnection();
        $cntStmt = $pdo->prepare("UPDATE webhook_endpoints SET total_imported = total_imported + 1 WHERE endpoint_id = ?");
        $cntStmt->execute([$endpointId]);

        // Activity log
        $leadName = $lead['contact_person'] ?? $lead['company_name'];
        logActivityWebhook($db, $createdBy, 'Sheet Import', 'Lead', $leadId,
            "Lead imported from Google Sheet: $endpointName — $leadName", $clientIp);

        // Webhook log
        logWebhook($db, $endpointId, $leadId, 'created', json_encode($incomingData), null, $clientIp);

        // WhatsApp notification
        if ($assignedTo) {
            try {
                TwilioHelper::notifyLeadAssignment($assignedTo, $leadName ?: 'New Lead', $leadId, 'Google Sheets Import');
            } catch (\Exception $e) {
                error_log("Sheet sync WA notify failed: " . $e->getMessage());
            }
        }

        return ['success' => true, 'status' => 'created', 'lead_id' => $leadId];

    } catch (\Exception $e) {
        error_log("Sheet sync lead insert error: " . $e->getMessage());
        logWebhook($db, $endpointId, null, 'error', json_encode($incomingData), $e->getMessage(), $clientIp);
        return ['success' => false, 'status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Apply field mapping.
 */
function applyFieldMapping($data, $mapping) {
    $result = [];

    foreach ($mapping as $incomingField => $crmField) {
        if ($crmField && isset($data[$incomingField]) && $data[$incomingField] !== '') {
            $result[$crmField] = trim($data[$incomingField]);
        }
    }

    // Pass through direct matches
    $crmColumns = [
        'lead_type', 'company_name', 'contact_person', 'title_position',
        'region', 'country', 'city', 'address',
        'phone', 'mobile', 'email', 'website',
        'facebook_url', 'instagram_url', 'linkedin_url', 'twitter_url', 'youtube_url',
        'specialization', 'facility_type', 'number_of_horses', 'horse_breed', 'horse_sex',
        'notes', 'lead_status', 'lead_source', 'priority',
    ];

    foreach ($crmColumns as $col) {
        if (!isset($result[$col]) && isset($data[$col]) && $data[$col] !== '') {
            $result[$col] = trim($data[$col]);
        }
    }

    return $result;
}

/**
 * Log to webhook_log table.
 */
function logWebhook($db, $endpointId, $leadId, $status, $rawPayload, $errorMessage, $ipAddress) {
    try {
        $db->query(
            "INSERT INTO webhook_log (endpoint_id, lead_id, status, raw_payload, error_message, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
            [$endpointId, $leadId, $status, $rawPayload ? substr($rawPayload, 0, 65000) : null, $errorMessage, $ipAddress]
        );
    } catch (\Exception $e) {
        error_log("webhook_log insert failed: " . $e->getMessage());
    }
}

/**
 * Log activity without session.
 */
function logActivityWebhook($db, $userId, $action, $entityType, $entityId, $details, $ipAddress) {
    try {
        $db->query(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$userId, $action, $entityType, $entityId, $details, $ipAddress, 'SheetSync/1.0']
        );
    } catch (\Exception $e) {
        error_log("Activity log (sheet sync) error: " . $e->getMessage());
    }
}
?>
