<?php
/**
 * Victory Genomics CRM — Integrations settings API (native VGold SPA).
 *
 * Lets the native Settings view read and write the CRM `settings` table keys for
 * Email/SMTP, Twilio/VoIP and WhatsApp — replacing the embedded settings.php
 * iframe. Runs through the unified session (mount.php) and the legacy Database
 * wrapper (bridge rewrites `settings` -> crm_settings).
 *
 * GET  /crm/api/crm-settings.php  -> { success, data:{ settings:{...}, secrets_set:{...} } }
 * POST /crm/api/crm-settings.php  -> { csrf_token, <key>:<value>, ... }  (Admin only)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('X-LiteSpeed-Cache-Control: no-cache');

startSecureSession();
requireLogin();

// Keys this endpoint is allowed to read/write.
$ALLOWED = [
    // Email / SMTP
    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
    'email_from_name', 'email_from_address', 'email_reply_to', 'company_address',
    'email_batch_size', 'email_batch_delay',
    // Twilio / VoIP
    'app_url', 'twilio_account_sid', 'twilio_auth_token', 'twilio_api_key',
    'twilio_api_secret', 'twilio_phone_number', 'twilio_twiml_app_sid',
    'voip_enabled', 'voip_recording_enabled',
    // WhatsApp
    'whatsapp_from_number', 'whatsapp_enabled', 'whatsapp_sandbox_mode',
    'wa_lead_assignment_notify',
];
// Secret keys are never sent back in plaintext; only a "set" flag is returned,
// and a write only updates them when a non-empty value is supplied.
$SECRETS = ['twilio_auth_token', 'twilio_api_secret', 'smtp_password'];
require_once __DIR__ . '/../includes/secrets.php';

$db = Database::getInstance();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        requireRole(['Admin']);
        requireCSRF();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = [];

        $saved = 0;
        foreach ($ALLOWED as $key) {
            if (!array_key_exists($key, $input)) continue;
            $value = (string) $input[$key];
            // Don't overwrite a stored secret with a blank/masked value.
            if (in_array($key, $SECRETS, true) && ($value === '' || $value === '********')) continue;
            // Credentials are encrypted at rest. Everything else is stored
            // as typed; forStorage() only touches the keys in $SECRETS.
            $stored = crmSecretForStorage($key, $value);
            $existing = $db->findOne('settings', ['setting_key' => $key]);
            if ($existing) {
                $db->update('settings', ['setting_value' => $stored], ['setting_key' => $key]);
            } else {
                $db->insert('settings', ['setting_key' => $key, 'setting_value' => $stored]);
            }
            $saved++;
        }
        if (function_exists('logActivity')) {
            @logActivity(getCurrentUserId(), 'Update Settings', 'System', null, 'Updated integration settings (native)');
        }
        echo json_encode(['success' => true, 'saved' => $saved]);
        exit;
    }

    // GET — return current values (secrets masked)
    $all = [];
    try {
        $all = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) { $all = []; }

    $settings = [];
    $secretsSet = [];
    foreach ($ALLOWED as $key) {
        if (in_array($key, $SECRETS, true)) {
            $secretsSet[$key] = !empty($all[$key]);
            $settings[$key] = '';
        } else {
            $settings[$key] = $all[$key] ?? '';
        }
    }
    echo json_encode(['success' => true, 'data' => ['settings' => $settings, 'secrets_set' => $secretsSet]]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
