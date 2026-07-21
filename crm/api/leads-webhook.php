<?php
/**
 * Victory Genomics CRM - Leads Webhook API
 * 
 * Secure endpoint for importing leads from Google Sheets (via Apps Script),
 * Meta Lead Ads, Google Ads forms, or any external HTTP source.
 *
 * Authentication: API key passed as query parameter or header.
 * No session/CSRF required — this is a machine-to-machine endpoint.
 *
 * POST /api/leads-webhook.php?key=<API_KEY>
 *   Body: JSON object with lead fields (mapped per endpoint config)
 *
 * POST /api/leads-webhook.php?key=<API_KEY>&action=batch
 *   Body: JSON array of lead objects
 *
 * GET  /api/leads-webhook.php?key=<API_KEY>&action=test
 *   Quick connectivity test (no lead created)
 */

require_once __DIR__ . '/../config/database.php';
// We need TwilioHelper for WhatsApp notifications on new lead assignment
require_once __DIR__ . '/../includes/twilio.php';
require_once __DIR__ . '/../includes/automation-engine.php';

// ─── CORS headers for Apps Script / external callers ──────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle OPTIONS pre-flight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Rate limiting (simple in-memory per-IP) ─────────────────
// Shared hosting doesn't have Redis, so we use a temp file approach
$rateLimitDir = sys_get_temp_dir() . '/vgcrm_webhook_rl';
if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0700, true);

function checkRateLimit($ip, $maxPerMinute = 60) {
    global $rateLimitDir;
    $file = $rateLimitDir . '/' . md5($ip) . '.json';
    $now = time();
    $window = 60; // 1 minute window

    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    // Remove entries older than window
    $data = array_filter($data, function($ts) use ($now, $window) {
        return ($now - $ts) < $window;
    });
    if (count($data) >= $maxPerMinute) {
        return false;
    }
    $data[] = $now;
    file_put_contents($file, json_encode(array_values($data)));
    return true;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkRateLimit($clientIp, 120)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Max 120 requests per minute.']);
    exit;
}

// ─── Extract API key ──────────────────────────────────────────
$apiKey = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
// Strip "Bearer " prefix if present
if ($apiKey && stripos($apiKey, 'Bearer ') === 0) {
    $apiKey = substr($apiKey, 7);
}

if (!$apiKey || strlen($apiKey) < 16) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid API key.']);
    exit;
}

// ─── Look up endpoint by API key ─────────────────────────────
$db = Database::getInstance();

$endpoint = $db->findOne('webhook_endpoints', ['api_key' => $apiKey]);

if (!$endpoint) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key.']);
    exit;
}

if (intval($endpoint['enabled'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This webhook endpoint is disabled.']);
    exit;
}

// ─── Parse configuration ─────────────────────────────────────
$fieldMapping = json_decode($endpoint['field_mapping'], true) ?: [];
$leadDefaults = json_decode($endpoint['lead_defaults'] ?? '{}', true) ?: [];
$assignedTo   = $endpoint['assigned_to'] ? intval($endpoint['assigned_to']) : null;
$endpointId   = intval($endpoint['endpoint_id']);
$endpointName = $endpoint['name'];

// ─── Route by action ─────────────────────────────────────────
$action = $_GET['action'] ?? 'import';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'test' && $method === 'GET') {
    echo json_encode([
        'success' => true,
        'message' => 'Webhook endpoint is active.',
        'endpoint' => $endpointName,
        'assigned_to' => $assignedTo,
        'field_mapping' => $fieldMapping,
        'lead_defaults' => $leadDefaults,
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST to submit leads.']);
    exit;
}

// ─── Parse incoming JSON body ─────────────────────────────────
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
    // Try form-encoded as fallback
    $payload = $_POST;
}

if (empty($payload)) {
    logWebhook($db, $endpointId, null, 'error', $rawBody, 'Empty or invalid payload', $clientIp);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty or invalid JSON payload.']);
    exit;
}

// ─── Batch mode ───────────────────────────────────────────────
if ($action === 'batch' || (isset($payload[0]) && is_array($payload[0]))) {
    // Array of leads
    $leads = isset($payload[0]) ? $payload : [$payload];
    $results = [];
    $created = 0;
    $duplicates = 0;
    $errors = 0;

    foreach ($leads as $index => $leadData) {
        $result = processLead($db, $leadData, $fieldMapping, $leadDefaults, $assignedTo, $endpointId, $endpointName, $clientIp);
        $results[] = $result;
        if ($result['status'] === 'created') $created++;
        elseif ($result['status'] === 'duplicate') $duplicates++;
        else $errors++;
    }

    echo json_encode([
        'success' => true,
        'message' => "Processed " . count($leads) . " lead(s): $created created, $duplicates duplicates, $errors errors.",
        'summary' => ['created' => $created, 'duplicates' => $duplicates, 'errors' => $errors],
        'results' => $results,
    ]);
    exit;
}

// ─── Single lead import ──────────────────────────────────────
$result = processLead($db, $payload, $fieldMapping, $leadDefaults, $assignedTo, $endpointId, $endpointName, $clientIp);

$httpCode = ($result['status'] === 'created') ? 201 : (($result['status'] === 'duplicate') ? 200 : 400);
http_response_code($httpCode);
echo json_encode($result);
exit;


// ══════════════════════════════════════════════════════════════
//  Core Functions
// ══════════════════════════════════════════════════════════════

/**
 * Process a single lead from the webhook payload.
 * Applies field mapping, validates, deduplicates, inserts, and optionally notifies.
 */
function processLead($db, $incomingData, $fieldMapping, $leadDefaults, $assignedTo, $endpointId, $endpointName, $clientIp) {
    // ── 1. Apply field mapping ───────────────────────────────
    $mapped = applyFieldMapping($incomingData, $fieldMapping);

    // ── 2. Merge with defaults (mapped values take priority) ─
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
        // Use contact_person or a generated placeholder
        $lead['company_name'] = $lead['contact_person']
            ?? ('Webhook Import - ' . date('Y-m-d H:i:s'));
    }

    // ── 4. Validate enums against allowed values ─────────────
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
            $lead[$field] = end($allowed) ?? $leadDefaults[$field] ?? null; // fallback to last (usually 'Other')
        }
    }

    // ── 5. Deduplication check (email OR phone) ──────────────
    $email = trim($lead['email'] ?? '');
    $phone = trim($lead['phone'] ?? '');
    $mobile = trim($lead['mobile'] ?? '');

    $dupLead = null;
    if ($email !== '') {
        $dupLead = $db->query(
            "SELECT lead_id, contact_person, email FROM leads WHERE email = ? LIMIT 1",
            [$email]
        )->fetch(\PDO::FETCH_ASSOC);
    }
    if (!$dupLead && $phone !== '') {
        $normalizedPhone = normalizePhoneForDedup($phone);
        if ($normalizedPhone) {
            $dupLead = $db->query(
                "SELECT lead_id, contact_person, phone FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ? LIMIT 1",
                [$normalizedPhone]
            )->fetch(\PDO::FETCH_ASSOC);
        }
    }
    if (!$dupLead && $mobile !== '') {
        $normalizedMobile = normalizePhoneForDedup($mobile);
        if ($normalizedMobile) {
            $dupLead = $db->query(
                "SELECT lead_id, contact_person, mobile FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', '') = ? LIMIT 1",
                [$normalizedMobile]
            )->fetch(\PDO::FETCH_ASSOC);
        }
    }

    if ($dupLead) {
        logWebhook($db, $endpointId, $dupLead['lead_id'], 'duplicate', json_encode($incomingData), 
            "Duplicate of lead #{$dupLead['lead_id']} ({$dupLead['contact_person']})", $clientIp);
        return [
            'success' => true,
            'status' => 'duplicate',
            'message' => 'Lead already exists.',
            'existing_lead_id' => intval($dupLead['lead_id']),
        ];
    }

    // ── 6. Determine created_by (use assigned_to user or admin #1) ──
    $createdBy = $assignedTo ?: 1; // fall back to admin user_id=1

    // ── 7. Build numeric fields ──────────────────────────────
    $numHorses = isset($lead['number_of_horses']) && $lead['number_of_horses'] !== ''
        ? intval($lead['number_of_horses']) : null;

    // ── 8. Insert into leads table ───────────────────────────
    try {
        $stmt = $db->query("
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
            emptyToNull($lead['contact_person'] ?? null),
            emptyToNull($lead['title_position'] ?? null),
            $lead['region'],
            $lead['country'] ?: '',
            emptyToNull($lead['city'] ?? null),
            emptyToNull($lead['address'] ?? null),
            emptyToNull($lead['phone'] ?? null),
            emptyToNull($lead['mobile'] ?? null),
            emptyToNull($lead['email'] ?? null),
            emptyToNull($lead['website'] ?? null),
            emptyToNull($lead['facebook_url'] ?? null),
            emptyToNull($lead['instagram_url'] ?? null),
            emptyToNull($lead['linkedin_url'] ?? null),
            emptyToNull($lead['twitter_url'] ?? null),
            emptyToNull($lead['youtube_url'] ?? null),
            emptyToNull($lead['specialization'] ?? null),
            emptyToNull($lead['facility_type'] ?? null),
            $numHorses,
            emptyToNull($lead['horse_breed'] ?? null),
            emptyToNull($lead['horse_sex'] ?? null),
            buildNotes($lead, $incomingData, $endpointName),
            $lead['lead_status'],
            $lead['lead_source'],
            $lead['priority'],
            $assignedTo,
            $createdBy,
        ]);

        $pdo = $db->getConnection();
        $leadId = intval($pdo->lastInsertId());

        // Update endpoint counters
        $db->query(
            "UPDATE webhook_endpoints SET last_received = NOW(), total_imported = total_imported + 1 WHERE endpoint_id = ?",
            [$endpointId]
        );

        // Log to activity_log (use system user)
        $leadName = $lead['contact_person'] ?? $lead['company_name'];
        logActivityWebhook($db, $createdBy, 'Webhook Import', 'Lead', $leadId,
            "Lead imported via webhook: $endpointName — $leadName", $clientIp);

        // Log to webhook_log
        logWebhook($db, $endpointId, $leadId, 'created', json_encode($incomingData), null, $clientIp);

        // ── 9. WhatsApp notification if assigned ─────────────
        if ($assignedTo) {
            try {
                TwilioHelper::notifyLeadAssignment(
                    $assignedTo,
                    $leadName ?: 'New Lead',
                    $leadId,
                    'Google Sheets Webhook'
                );
            } catch (\Exception $e) {
                error_log("Webhook WA notify failed: " . $e->getMessage());
            }
        }

        // ── 10. Automation triggers ──────────────────────────
        try {
            $webhookLead = $db->findOne('leads', ['lead_id' => $leadId]);
            $autoCtx = [
                'lead_id' => $leadId,
                'lead'    => $webhookLead,
                'current_user' => ['user_id' => $createdBy, 'full_name' => 'Webhook Import'],
            ];
            fireAutomationTrigger('lead_created', $autoCtx);

            if (!empty($webhookLead['lead_source'])) {
                fireAutomationTrigger('lead_source_match', $autoCtx);
            }
            if ($assignedTo) {
                $autoCtx['new_assigned'] = $assignedTo;
                fireAutomationTrigger('lead_assigned', $autoCtx);
            }
        } catch (\Exception $e) {
            error_log("Webhook automation trigger error: " . $e->getMessage());
        }

        return [
            'success' => true,
            'status' => 'created',
            'message' => 'Lead created successfully.',
            'lead_id' => $leadId,
        ];

    } catch (\Exception $e) {
        error_log("Webhook lead insert error: " . $e->getMessage());
        logWebhook($db, $endpointId, null, 'error', json_encode($incomingData), $e->getMessage(), $clientIp);
        return [
            'success' => false,
            'status' => 'error',
            'message' => 'Failed to create lead: ' . $e->getMessage(),
        ];
    }
}

/**
 * Apply field mapping: translate incoming field names to CRM column names.
 *
 * field_mapping JSON example:
 * {
 *   "full_name":       "contact_person",
 *   "email_address":   "email",
 *   "phone_number":    "phone",
 *   "whatsapp":        "mobile",
 *   "company":         "company_name",
 *   "city":            "city",
 *   "country":         "country",
 *   "message":         "notes"
 * }
 *
 * Keys = incoming field names, Values = CRM lead column names.
 */
function applyFieldMapping($data, $mapping) {
    $result = [];

    // First pass: apply explicit mapping
    foreach ($mapping as $incomingField => $crmField) {
        if ($crmField && isset($data[$incomingField]) && $data[$incomingField] !== '') {
            $result[$crmField] = trim($data[$incomingField]);
        }
    }

    // Second pass: pass through any fields that directly match CRM column names
    // (only if not already mapped)
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
 * Build notes string: include any unmapped fields from the original payload
 * so no data is lost, plus the endpoint source tag.
 */
function buildNotes($lead, $originalData, $endpointName) {
    $parts = [];

    // Start with any mapped notes
    if (!empty($lead['notes'])) {
        $parts[] = $lead['notes'];
    }

    // Collect unmapped fields
    $mappedValues = array_values(array_filter(array_map('trim', array_values($lead))));
    $unmapped = [];
    foreach ($originalData as $key => $value) {
        if ($value === '' || $value === null) continue;
        $trimmedVal = trim($value);
        // If this value wasn't mapped to any field, include it
        if (!in_array($trimmedVal, $mappedValues, true)) {
            $unmapped[] = "$key: $trimmedVal";
        }
    }
    if (!empty($unmapped)) {
        $parts[] = "--- Additional Data ---\n" . implode("\n", $unmapped);
    }

    // Tag the source
    $parts[] = "[Imported via: $endpointName @ " . date('Y-m-d H:i:s') . "]";

    return implode("\n\n", $parts);
}

/**
 * Normalize a phone number for deduplication — strip everything non-digit.
 */
function normalizePhoneForDedup($phone) {
    $normalized = preg_replace('/[^0-9+]/', '', $phone);
    // Remove leading + for comparison
    $normalized = ltrim($normalized, '+');
    return $normalized ?: null;
}

/**
 * Convert empty string to null (matches pattern from api/leads.php).
 */
function emptyToNull($value) {
    return (isset($value) && $value !== '') ? $value : null;
}

/**
 * Log a webhook import event.
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
 * Log activity (without requiring auth.php session).
 */
function logActivityWebhook($db, $userId, $action, $entityType, $entityId, $details, $ipAddress) {
    try {
        $db->query(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$userId, $action, $entityType, $entityId, $details, $ipAddress, 'WebhookAPI/1.0']
        );
    } catch (\Exception $e) {
        error_log("Activity log (webhook) error: " . $e->getMessage());
    }
}
?>
