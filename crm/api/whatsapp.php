<?php
/**
 * Victory Genomics CRM — WhatsApp API Endpoint
 * Handles: send message, templates, chat history, webhook
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/twilio.php';
require_once __DIR__ . '/../includes/notification-helper.php';  // createNotification()

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// ─── Public webhook endpoints (no auth required) ───
if (in_array($action, ['webhook', 'status'])) {
    handleWebhook($action);
    exit;
}

// ─── Authenticated endpoints ───
startSecureSession();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Role-based visibility helper
$_isSalesRep = !hasRole('Sales Manager'); // true for Sales Rep and Viewer

switch ($action) {
    case 'send':
        sendMessage();
        break;
    case 'send_template':
        sendTemplate();
        break;
    case 'chat_history':
        getChatHistory();
        break;
    case 'templates':
        getTemplates();
        break;
    case 'save_template':
        saveTemplate();
        break;
    case 'lead_chats':
        getLeadChats();
        break;
    case 'stats':
        getWhatsAppStats();
        break;
    case 'check_window':
        checkServiceWindow();
        break;
    case 'content_templates':
        getContentTemplates();
        break;
    case 'create_content_template':
        requireRole('Sales Manager');
        createContentTemplate();
        break;
    case 'delete_content_template':
        requireRole('Sales Manager');
        deleteContentTemplate();
        break;
    case 'send_content_template':
        sendContentTemplate();
        break;
    case 'unmatched_messages':
        requireRole('Sales Manager');
        getUnmatchedMessages();
        break;
    case 'link_to_lead':
        requireRole('Sales Manager');
        linkMessageToLead();
        break;
    case 'create_lead_from_message':
        requireRole('Sales Manager');
        createLeadFromMessage();
        break;
    case 'unmatched_chat_history':
        requireRole('Sales Manager');
        getUnmatchedChatHistory();
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ─────────────────────────────────────
// SEND MESSAGE
// ─────────────────────────────────────
function sendMessage() {
    $input = json_decode(file_get_contents('php://input'), true);
    $toNumber   = $input['to_number'] ?? '';
    $body       = trim($input['body'] ?? '');
    $leadId     = intval($input['lead_id'] ?? 0);
    $mediaUrl   = $input['media_url'] ?? null;
    $contentSid = $input['content_sid'] ?? null;   // optional: explicit template

    if (empty($toNumber) || empty($body)) {
        echo json_encode(['success' => false, 'message' => 'Phone number and message are required']);
        return;
    }

    try {
        $twilio = TwilioHelper::getInstance();
        $normalized = TwilioHelper::normalizePhone($toNumber);

        // Resolve the lead/contact name for template variable
        $contactName = null;
        if ($leadId) {
            $db2 = Database::getInstance();
            $lead = $db2->findOne('leads', ['lead_id' => $leadId]);
            $contactName = $lead['contact_person'] ?? null;
        }

        // Send via Twilio (handles 24h window logic internally)
        $message = $twilio->sendWhatsApp($normalized, $body, $mediaUrl, $contentSid, null, $contactName);

        // Log in database
        $messageId = TwilioHelper::logMessage([
            'lead_id'     => $leadId ?: null,
            'user_id'     => getCurrentUserId(),
            'message_sid' => $message->sid,
            'direction'   => 'Outbound',
            'from_number' => $twilio->getWhatsappFromNumber() ?: $twilio->getPhoneNumber(),
            'to_number'   => $normalized,
            'body'        => $body,
            'media_url'   => $mediaUrl,
            'status'      => $message->status ?? 'Sent',
            'sent_at'     => date('Y-m-d H:i:s'),
        ]);

        // Also log as interaction
        if ($leadId) {
            $db = Database::getInstance();
            $db->insert('interactions', [
                'lead_id'          => $leadId,
                'user_id'          => getCurrentUserId(),
                'interaction_type' => 'WhatsApp',
                'interaction_date' => date('Y-m-d H:i:s'),
                'subject'          => 'WhatsApp message to ' . $normalized,
                'notes'            => substr($body, 0, 500),
                'outcome'          => null,
            ]);

            logActivity(getCurrentUserId(), 'WhatsApp Sent', 'WhatsApp', $messageId, "Sent WhatsApp to $normalized");
        }

        echo json_encode([
            'success'    => true,
            'message_id' => $messageId,
            'sid'        => $message->sid,
            'status'     => $message->status,
            'message'    => 'Message sent',
        ]);
    } catch (Exception $e) {
        error_log("WhatsApp send error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to send: ' . $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// SEND TEMPLATE
// ─────────────────────────────────────
function sendTemplate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $templateId = intval($input['template_id'] ?? 0);
    $toNumber   = $input['to_number'] ?? '';
    $leadId     = intval($input['lead_id'] ?? 0);

    if (!$templateId || empty($toNumber)) {
        echo json_encode(['success' => false, 'message' => 'Template ID and phone number are required']);
        return;
    }

    try {
        $db = Database::getInstance();

        // Get template
        $template = $db->findOne('whatsapp_templates', ['template_id' => $templateId]);
        if (!$template) {
            echo json_encode(['success' => false, 'message' => 'Template not found']);
            return;
        }

        // Get lead data for variable replacement
        $lead = $leadId ? $db->findOne('leads', ['lead_id' => $leadId]) : [];
        $currentUser = getCurrentUser();
        $body = TwilioHelper::processTemplate($template['body'], $lead ?: [], $currentUser['full_name']);

        // Send — pass contact name so template variables resolve
        $twilio = TwilioHelper::getInstance();
        $normalized = TwilioHelper::normalizePhone($toNumber);
        $contactName = $lead['contact_person'] ?? null;
        $message = $twilio->sendWhatsApp($normalized, $body, null, null, null, $contactName);

        // Log
        $messageId = TwilioHelper::logMessage([
            'lead_id'     => $leadId ?: null,
            'user_id'     => getCurrentUserId(),
            'message_sid' => $message->sid,
            'direction'   => 'Outbound',
            'from_number' => $twilio->getWhatsappFromNumber() ?: $twilio->getPhoneNumber(),
            'to_number'   => $normalized,
            'body'        => $body,
            'status'      => $message->status ?? 'Sent',
            'template_id' => $templateId,
            'sent_at'     => date('Y-m-d H:i:s'),
        ]);

        if ($leadId) {
            $db->insert('interactions', [
                'lead_id'          => $leadId,
                'user_id'          => getCurrentUserId(),
                'interaction_type' => 'WhatsApp',
                'interaction_date' => date('Y-m-d H:i:s'),
                'subject'          => 'WhatsApp template: ' . $template['name'],
                'notes'            => substr($body, 0, 500),
                'outcome'          => null,
            ]);
            logActivity(getCurrentUserId(), 'WhatsApp Template', 'WhatsApp', $messageId, "Sent template '{$template['name']}' to $normalized");
        }

        echo json_encode([
            'success'    => true,
            'message_id' => $messageId,
            'body'       => $body,
            'message'    => 'Template message sent',
        ]);
    } catch (Exception $e) {
        error_log("WhatsApp template error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed: ' . $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// CHAT HISTORY
// ─────────────────────────────────────
function getChatHistory() {
    global $_isSalesRep;
    $leadId   = intval($_GET['lead_id'] ?? 0);
    $toNumber = $_GET['to_number'] ?? '';
    $limit    = intval($_GET['limit'] ?? 50);

    try {
        $db = Database::getInstance();
        $params = [];
        $where = '1=1';

        if ($leadId) {
            $where = 'wm.lead_id = ?';
            $params[] = $leadId;
        } elseif ($toNumber) {
            $normalized = TwilioHelper::normalizePhone($toNumber);
            $where = '(wm.to_number = ? OR wm.from_number = ?)';
            $params[] = $normalized;
            $params[] = $normalized;
        }

        // Sales reps only see messages they sent or that came to their assigned leads
        if ($_isSalesRep) {
            $userId = getCurrentUserId();
            $where .= ' AND (wm.user_id = ? OR wm.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = ?))';
            $params[] = $userId;
            $params[] = $userId;
        }

        $messages = $db->query("
            SELECT wm.*, u.full_name as user_name
            FROM whatsapp_messages wm
            LEFT JOIN users u ON wm.user_id = u.user_id
            WHERE $where
            ORDER BY wm.created_at ASC
            LIMIT $limit
        ", $params)->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $messages]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// TEMPLATES
// ─────────────────────────────────────
function getTemplates() {
    try {
        $db = Database::getInstance();
        $templates = $db->query(
            "SELECT * FROM whatsapp_templates WHERE is_active = 1 ORDER BY category, name"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $templates]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function saveTemplate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $name     = trim($input['name'] ?? '');
    $category = $input['category'] ?? 'Custom';
    $body     = trim($input['body'] ?? '');

    if (empty($name) || empty($body)) {
        echo json_encode(['success' => false, 'message' => 'Name and body are required']);
        return;
    }

    try {
        $db = Database::getInstance();
        $templateId = $db->insert('whatsapp_templates', [
            'name'       => $name,
            'category'   => $category,
            'body'       => $body,
            'is_active'  => 1,
            'created_by' => getCurrentUserId(),
        ]);

        echo json_encode(['success' => true, 'template_id' => $templateId, 'message' => 'Template saved']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// LEAD CHATS (recent conversations per lead)
// ─────────────────────────────────────
function getLeadChats() {
    global $_isSalesRep;
    try {
        $db = Database::getInstance();
        $params = [];
        $extraWhere = '';

        // Sales reps only see chats for their assigned leads
        if ($_isSalesRep) {
            $extraWhere = ' AND l.assigned_to = ?';
            $params[] = getCurrentUserId();
        }

        $chats = $db->query("
            SELECT l.lead_id, l.company_name, l.contact_person, l.phone, l.mobile,
                   wm.message_body as last_message,
                   wm.direction as last_direction,
                   wm.created_at as last_message_at,
                   wm.user_id as last_sender_id,
                   u.full_name as last_sender_name,
                   (SELECT COUNT(*) FROM whatsapp_messages WHERE lead_id = l.lead_id AND status = 'Received' AND direction = 'Inbound') as unread_count,
                   (SELECT COUNT(*) FROM whatsapp_messages WHERE lead_id = l.lead_id) as message_count
            FROM leads l
            INNER JOIN whatsapp_messages wm ON wm.lead_id = l.lead_id
            LEFT JOIN users u ON wm.user_id = u.user_id
            WHERE wm.message_id = (SELECT MAX(message_id) FROM whatsapp_messages WHERE lead_id = l.lead_id)
            $extraWhere
            ORDER BY wm.created_at DESC
            LIMIT 50
        ", $params)->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $chats]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// STATS
// ─────────────────────────────────────
function getWhatsAppStats() {
    global $_isSalesRep;
    try {
        $db = Database::getInstance();

        if ($_isSalesRep) {
            $userId = getCurrentUserId();
            $userFilter = "AND (wm.user_id = $userId OR wm.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = $userId))";
            $stats = [
                'total_sent'    => $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE direction = 'Outbound' $userFilter")->fetchColumn(),
                'total_received'=> $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE direction = 'Inbound' $userFilter")->fetchColumn(),
                'today_sent'    => $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE direction = 'Outbound' AND DATE(wm.created_at) = CURDATE() $userFilter")->fetchColumn(),
                'delivered'     => $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE status IN ('Delivered','Read') $userFilter")->fetchColumn(),
            ];
        } else {
            $stats = [
                'total_sent'    => $db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction = 'Outbound'")->fetchColumn(),
                'total_received'=> $db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction = 'Inbound'")->fetchColumn(),
                'today_sent'    => $db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE direction = 'Outbound' AND DATE(created_at) = CURDATE()")->fetchColumn(),
                'delivered'     => $db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE status IN ('Delivered','Read')")->fetchColumn(),
            ];
        }
        echo json_encode(['success' => true, 'data' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// CHECK 24h SERVICE WINDOW
// ─────────────────────────────────────
function checkServiceWindow() {
    $phone = $_GET['phone'] ?? '';
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone number required']);
        return;
    }
    $inside = TwilioHelper::isInsideServiceWindow($phone);
    echo json_encode([
        'success'       => true,
        'inside_window' => $inside,
        'note'          => $inside
            ? 'Free-form messages allowed (contact messaged within 24 hours).'
            : 'Outside 24h window — message will be sent using an approved template.',
    ]);
}

// ─────────────────────────────────────
// TWILIO CONTENT TEMPLATES (for Meta/WhatsApp)
// ─────────────────────────────────────

/**
 * List all Twilio Content Templates with their approval status.
 * Returns templates from the Twilio Content API so the UI can show
 * which ones are approved, pending, or rejected.
 */
function getContentTemplates() {
    try {
        $sid   = getenv('TWILIO_ACCOUNT_SID') ?: '';
        $token = getenv('TWILIO_AUTH_TOKEN')   ?: '';
        if (!$sid || !$token) {
            // Try from DB settings
            $twilio = TwilioHelper::getInstance();
            $sid    = $twilio->getAccountSid();
            $token  = $twilio->getAuthToken();
        }

        $ch = curl_init('https://content.twilio.com/v1/Content?PageSize=50');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "$sid:$token",
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            echo json_encode(['success' => false, 'message' => 'Twilio API error (HTTP ' . $code . ')']);
            return;
        }

        $data = json_decode($resp, true);
        $templates = [];

        foreach ($data['contents'] ?? [] as $tpl) {
            // Fetch approval status
            $ch2 = curl_init("https://content.twilio.com/v1/Content/{$tpl['sid']}/ApprovalRequests");
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "$sid:$token",
                CURLOPT_TIMEOUT        => 10,
            ]);
            $approvalResp = curl_exec($ch2);
            curl_close($ch2);
            $approvalData = json_decode($approvalResp, true);
            $waApproval = $approvalData['whatsapp'] ?? [];

            // Extract body from types
            $body = '';
            if (isset($tpl['types']['twilio/text']['body'])) {
                $body = $tpl['types']['twilio/text']['body'];
            }

            $templates[] = [
                'content_sid'      => $tpl['sid'],
                'friendly_name'    => $tpl['friendly_name'],
                'language'         => $tpl['language'] ?? 'en',
                'variables'        => $tpl['variables'] ?? [],
                'body'             => $body,
                'approval_status'  => $waApproval['status'] ?? 'unknown',
                'rejection_reason' => $waApproval['rejection_reason'] ?? '',
                'category'         => $waApproval['category'] ?? '',
                'created_at'       => $tpl['date_created'] ?? '',
            ];
        }

        echo json_encode(['success' => true, 'data' => $templates]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Create a new Twilio Content Template and submit it to Meta for approval.
 * Only Sales Managers and Admins can do this.
 *
 * Input JSON:
 *   name     – template friendly name (lowercase, underscores)
 *   category – UTILITY or MARKETING
 *   body     – template body with {{1}}, {{2}}, … placeholders
 *   variables – { "1": "description", "2": "description", … }
 */
function createContentTemplate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $name      = trim($input['name'] ?? '');
    $category  = strtoupper(trim($input['category'] ?? 'UTILITY'));
    $language  = trim($input['language'] ?? 'en');
    $body      = trim($input['body'] ?? '');
    $variables = $input['variables'] ?? [];

    // Validate language code (Meta WhatsApp supported languages)
    $supportedLanguages = [
        'af','sq','ar','az','bn','bg','ca','zh_CN','zh_HK','zh_TW','hr','cs','da',
        'nl','en','en_GB','en_US','et','fil','fi','fr','ka','de','el','gu','ha',
        'he','hi','hu','id','ga','it','ja','kn','kk','ko','ky_KG','lo','lv','lt',
        'mk','ms','ml','mr','nb','fa','pl','pt_BR','pt_PT','pa','ro','ru','sr',
        'sk','sl','es','es_AR','es_ES','es_MX','sw','sv','ta','te','th','tr',
        'uk','ur','uz','vi','zu'
    ];
    if (!in_array($language, $supportedLanguages)) {
        $language = 'en'; // fallback to English
    }

    if (empty($name) || empty($body)) {
        echo json_encode(['success' => false, 'message' => 'Name and body are required']);
        return;
    }

    // Sanitize name for Twilio (lowercase, underscores, no spaces)
    $safeName = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    $safeName = preg_replace('/_+/', '_', trim($safeName, '_'));

    if (!in_array($category, ['UTILITY', 'MARKETING'])) {
        $category = 'UTILITY';
    }

    try {
        $twilio = TwilioHelper::getInstance();
        $sid    = $twilio->getAccountSid();
        $token  = $twilio->getAuthToken();

        // Step 1: Create the Content Template
        $payload = [
            'friendly_name' => $safeName,
            'language'      => $language,
            'variables'     => (object) $variables,
            'types'         => [
                'twilio/text' => ['body' => $body],
            ],
        ];

        $ch = curl_init('https://content.twilio.com/v1/Content');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "$sid:$token",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($resp, true);

        if ($code !== 201 && $code !== 200) {
            $errMsg = $result['message'] ?? 'Failed to create template';
            echo json_encode(['success' => false, 'message' => "Twilio error: $errMsg"]);
            return;
        }

        $contentSid = $result['sid'];

        // Step 2: Submit for WhatsApp/Meta approval
        $approvalPayload = [
            'name'     => $safeName,
            'category' => $category,
        ];

        $ch2 = curl_init("https://content.twilio.com/v1/Content/$contentSid/ApprovalRequests/whatsapp");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "$sid:$token",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($approvalPayload),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $approvalResp = curl_exec($ch2);
        $approvalCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        $approvalResult = json_decode($approvalResp, true);
        $approvalStatus = $approvalResult['status'] ?? 'unknown';

        // Log activity
        logActivity(getCurrentUserId(), 'Create WA Template', 'WhatsApp', 0,
            "Created Content Template '$safeName' (SID: $contentSid) — approval: $approvalStatus");

        echo json_encode([
            'success'         => true,
            'content_sid'     => $contentSid,
            'name'            => $safeName,
            'approval_status' => $approvalStatus,
            'message'         => "Template created and submitted for Meta approval (status: $approvalStatus).",
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Delete a Twilio Content Template.
 * Removes it from Twilio (and consequently from Meta/WhatsApp).
 * Only Sales Managers and Admins can do this.
 *
 * Input JSON:
 *   content_sid – Twilio Content SID (HXxxx…)
 */
function deleteContentTemplate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $contentSid = trim($input['content_sid'] ?? '');

    if (empty($contentSid)) {
        echo json_encode(['success' => false, 'message' => 'Content SID is required']);
        return;
    }

    // Basic validation: Content SIDs start with HX
    if (strpos($contentSid, 'HX') !== 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Content SID format']);
        return;
    }

    try {
        $twilio = TwilioHelper::getInstance();
        $sid    = $twilio->getAccountSid();
        $token  = $twilio->getAuthToken();

        // Delete via Twilio Content API
        $ch = curl_init("https://content.twilio.com/v1/Content/$contentSid");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_USERPWD        => "$sid:$token",
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 204 = successfully deleted, 200 = also success
        if ($code === 204 || $code === 200) {
            logActivity(getCurrentUserId(), 'Delete WA Template', 'WhatsApp', 0,
                "Deleted Content Template (SID: $contentSid)");

            echo json_encode([
                'success' => true,
                'message' => 'Template deleted successfully.',
            ]);
        } elseif ($code === 404) {
            echo json_encode(['success' => false, 'message' => 'Template not found on Twilio. It may have already been deleted.']);
        } else {
            $result = json_decode($resp, true);
            $errMsg = $result['message'] ?? "Twilio API error (HTTP $code)";
            echo json_encode(['success' => false, 'message' => $errMsg]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Send a Twilio Content Template message to a lead.
 * Uses ContentSid + ContentVariables — works outside the 24h window.
 *
 * Input JSON:
 *   content_sid – Twilio Content SID (HXxxx…)
 *   to_number   – recipient phone
 *   lead_id     – (optional)
 *   variables   – { "1": "value", "2": "value", … }
 */
function sendContentTemplate() {
    $input      = json_decode(file_get_contents('php://input'), true);
    $contentSid = $input['content_sid'] ?? '';
    $toNumber   = $input['to_number'] ?? '';
    $leadId     = intval($input['lead_id'] ?? 0);
    $variables  = $input['variables'] ?? [];

    if (empty($contentSid) || empty($toNumber)) {
        echo json_encode(['success' => false, 'message' => 'Content SID and phone number are required']);
        return;
    }

    try {
        $twilio     = TwilioHelper::getInstance();
        $normalized = TwilioHelper::normalizePhone($toNumber);

        // Resolve display body for logging
        $bodyForLog = '(template message)';
        // Try to get template body from Twilio for logging
        $ch = curl_init("https://content.twilio.com/v1/Content/$contentSid");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $twilio->getAccountSid() . ':' . $twilio->getAuthToken(),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $tplResp = curl_exec($ch);
        curl_close($ch);
        $tplData = json_decode($tplResp, true);
        if (isset($tplData['types']['twilio/text']['body'])) {
            $bodyForLog = $tplData['types']['twilio/text']['body'];
            // Replace variable placeholders for the log
            foreach ($variables as $key => $val) {
                $bodyForLog = str_replace('{{'.$key.'}}', $val, $bodyForLog);
            }
        }

        // Send via Twilio with ContentSid
        $message = $twilio->sendWhatsApp($normalized, $bodyForLog, null, $contentSid, $variables);

        // Log in database
        $messageId = TwilioHelper::logMessage([
            'lead_id'     => $leadId ?: null,
            'user_id'     => getCurrentUserId(),
            'message_sid' => $message->sid,
            'direction'   => 'Outbound',
            'from_number' => $twilio->getWhatsappFromNumber() ?: $twilio->getPhoneNumber(),
            'to_number'   => $normalized,
            'body'        => $bodyForLog,
            'status'      => $message->status ?? 'Sent',
            'sent_at'     => date('Y-m-d H:i:s'),
        ]);

        if ($leadId) {
            $db = Database::getInstance();
            $db->insert('interactions', [
                'lead_id'          => $leadId,
                'user_id'          => getCurrentUserId(),
                'interaction_type' => 'WhatsApp',
                'interaction_date' => date('Y-m-d H:i:s'),
                'subject'          => 'WhatsApp template to ' . $normalized,
                'notes'            => substr($bodyForLog, 0, 500),
                'outcome'          => null,
            ]);
            logActivity(getCurrentUserId(), 'WhatsApp Template', 'WhatsApp', $messageId,
                "Sent Content Template ($contentSid) to $normalized");
        }

        echo json_encode([
            'success'    => true,
            'message_id' => $messageId,
            'sid'        => $message->sid,
            'status'     => $message->status,
            'body'       => $bodyForLog,
            'message'    => 'Template message sent',
        ]);
    } catch (Exception $e) {
        error_log("WhatsApp content template send error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to send: ' . $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// UNMATCHED MESSAGES (Admin/Manager)
// ─────────────────────────────────────

/**
 * Get all inbound messages that have no linked lead (lead_id IS NULL).
 * Admin/Manager only — visible in the "Unmatched" tab.
 */
function getUnmatchedMessages() {
    try {
        $db = Database::getInstance();
        $messages = $db->query("
            SELECT wm.*,
                   (SELECT COUNT(*) FROM whatsapp_messages wm2
                    WHERE wm2.lead_id IS NULL
                      AND wm2.direction = 'Inbound'
                      AND REPLACE(REPLACE(wm2.from_number,'+',''),'-','') = REPLACE(REPLACE(wm.from_number,'+',''),'-','')
                   ) as thread_count
            FROM whatsapp_messages wm
            WHERE wm.lead_id IS NULL
              AND wm.direction = 'Inbound'
            ORDER BY wm.created_at DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Group by sender phone (show latest per sender + count)
        // Keep the best profile_name found across all messages from same sender
        $grouped = [];
        foreach ($messages as $msg) {
            $phone = preg_replace('/[^0-9]/', '', $msg['from_number']);
            $key = substr($phone, -10);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $msg;
            } elseif (empty($grouped[$key]['profile_name']) && !empty($msg['profile_name'])) {
                // Keep the latest message but use profile_name from an older one if the latest is empty
                $grouped[$key]['profile_name'] = $msg['profile_name'];
            }
        }

        echo json_encode(['success' => true, 'data' => array_values($grouped)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Link unmatched messages (by sender phone) to an existing lead.
 * Input: { from_number, lead_id }
 */
function linkMessageToLead() {
    $input = json_decode(file_get_contents('php://input'), true);
    $fromNumber = $input['from_number'] ?? '';
    $leadId     = intval($input['lead_id'] ?? 0);

    if (empty($fromNumber) || !$leadId) {
        echo json_encode(['success' => false, 'message' => 'Phone number and lead ID are required']);
        return;
    }

    try {
        $db = Database::getInstance();

        // Verify lead exists
        $lead = $db->findOne('leads', ['lead_id' => $leadId]);
        if (!$lead) {
            echo json_encode(['success' => false, 'message' => 'Lead not found']);
            return;
        }

        // Match on last 10 digits
        $digits = preg_replace('/[^0-9]/', '', $fromNumber);
        $last10 = substr($digits, -10);

        // Update all unmatched messages from this sender
        $updated = $db->execute("
            UPDATE whatsapp_messages
            SET lead_id = ?
            WHERE lead_id IS NULL
              AND REPLACE(REPLACE(REPLACE(from_number, '+', ''), '-', ''), ' ', '') LIKE ?
        ", [$leadId, '%' . $last10]);

        // Also create an interaction record for the lead
        $db->insert('interactions', [
            'lead_id'          => $leadId,
            'user_id'          => getCurrentUserId(),
            'interaction_type' => 'WhatsApp',
            'interaction_date' => date('Y-m-d H:i:s'),
            'subject'          => 'Linked unmatched WhatsApp messages from ' . $fromNumber,
            'notes'            => "$updated message(s) linked to this lead by " . ($_SESSION['full_name'] ?? 'admin'),
            'outcome'          => 'Neutral',
        ]);

        logActivity(getCurrentUserId(), 'Link WA Message', 'WhatsApp', $leadId,
            "Linked $updated unmatched message(s) from $fromNumber to lead #{$leadId}");

        echo json_encode([
            'success' => true,
            'updated' => $updated,
            'message' => "$updated message(s) linked to {$lead['contact_person']} ({$lead['company_name']})",
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Create a new lead from an unmatched message and link all messages from that sender.
 * Input: { from_number, contact_person, company_name (optional) }
 */
function createLeadFromMessage() {
    $input = json_decode(file_get_contents('php://input'), true);
    $fromNumber    = $input['from_number'] ?? '';
    $contactPerson = trim($input['contact_person'] ?? '');
    $companyName   = trim($input['company_name'] ?? '');

    if (empty($fromNumber) || empty($contactPerson)) {
        echo json_encode(['success' => false, 'message' => 'Phone number and contact name are required']);
        return;
    }

    try {
        $db = Database::getInstance();
        $normalized = TwilioHelper::normalizePhone($fromNumber);

        // Create the lead
        $leadId = $db->insert('leads', [
            'contact_person' => $contactPerson,
            'company_name'   => $companyName ?: null,
            'phone'          => $normalized,
            'lead_type'      => 'Other',
            'source'         => 'WhatsApp Inbound',
            'status'         => 'New Lead',
            'priority'       => 'Medium',
            'assigned_to'    => getCurrentUserId(),
            'created_by'     => getCurrentUserId(),
        ]);

        // Link all unmatched messages from this sender
        $digits = preg_replace('/[^0-9]/', '', $normalized);
        $last10 = substr($digits, -10);

        $updated = $db->execute("
            UPDATE whatsapp_messages
            SET lead_id = ?
            WHERE lead_id IS NULL
              AND REPLACE(REPLACE(REPLACE(from_number, '+', ''), '-', ''), ' ', '') LIKE ?
        ", [$leadId, '%' . $last10]);

        // Log interaction
        $db->insert('interactions', [
            'lead_id'          => $leadId,
            'user_id'          => getCurrentUserId(),
            'interaction_type' => 'WhatsApp',
            'interaction_date' => date('Y-m-d H:i:s'),
            'subject'          => 'New lead created from WhatsApp inbound',
            'notes'            => "Lead auto-created from unmatched WhatsApp message. $updated message(s) linked.",
            'outcome'          => 'Neutral',
        ]);

        logActivity(getCurrentUserId(), 'Create Lead from WA', 'Lead', $leadId,
            "Created lead '$contactPerson' from unmatched WhatsApp ($fromNumber). $updated msgs linked.");

        echo json_encode([
            'success'  => true,
            'lead_id'  => $leadId,
            'linked'   => $updated,
            'message'  => "Lead '$contactPerson' created and $updated message(s) linked.",
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// UNMATCHED CHAT HISTORY (by phone)
// ─────────────────────────────────────

/**
 * Get full chat history for an unmatched phone number.
 * Returns both inbound and outbound messages matching the phone.
 * Used by the Unmatched tab inline chat view.
 */
function getUnmatchedChatHistory() {
    $phone = $_GET['phone'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    if ($limit < 1 || $limit > 200) $limit = 50;

    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Phone number is required']);
        return;
    }

    try {
        $db = Database::getInstance();
        $normalized = TwilioHelper::normalizePhone($phone);
        $digits = preg_replace('/[^0-9]/', '', $normalized);
        $last10 = substr($digits, -10);

        // Get all messages where the from_number OR to_number matches this phone
        // This covers inbound from this number and outbound replies to this number
        $messages = $db->query("
            SELECT wm.*, u.full_name as user_name
            FROM whatsapp_messages wm
            LEFT JOIN users u ON wm.user_id = u.user_id
            WHERE (
                REPLACE(REPLACE(REPLACE(wm.from_number, '+', ''), '-', ''), ' ', '') LIKE ?
                OR REPLACE(REPLACE(REPLACE(wm.to_number, '+', ''), '-', ''), ' ', '') LIKE ?
            )
            ORDER BY wm.created_at ASC
            LIMIT $limit
        ", ['%' . $last10, '%' . $last10])->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $messages]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────
// WEBHOOK HANDLERS
// ─────────────────────────────────────
function handleWebhook($action) {
    switch ($action) {
        case 'webhook':
            // Incoming WhatsApp message
            $from = $_POST['From'] ?? '';
            $to   = $_POST['To'] ?? '';
            $body = $_POST['Body'] ?? '';
            $sid  = $_POST['MessageSid'] ?? '';
            $media = $_POST['MediaUrl0'] ?? null;
            $mediaType = $_POST['MediaContentType0'] ?? null;
            $profileName = trim($_POST['ProfileName'] ?? '');

            // Strip whatsapp: prefix
            $fromNumber = str_replace('whatsapp:', '', $from);
            $toNumber   = str_replace('whatsapp:', '', $to);

            try {
                // Find the lead by phone
                $lead = TwilioHelper::findLeadByPhone($fromNumber);
                $leadId = $lead ? $lead['lead_id'] : null;

                TwilioHelper::logMessage([
                    'lead_id'      => $leadId,
                    'user_id'      => null,
                    'message_sid'  => $sid,
                    'direction'    => 'Inbound',
                    'from_number'  => $fromNumber,
                    'to_number'    => $toNumber,
                    'body'         => $body,
                    'media_url'    => $media,
                    'profile_name' => $profileName ?: null,
                    'status'       => 'Received',
                    'sent_at'      => date('Y-m-d H:i:s'),
                ]);

                if ($leadId) {
                    $db = Database::getInstance();
                    $db->insert('interactions', [
                        'lead_id'          => $leadId,
                        'user_id'          => $lead['assigned_to'] ?? 1,
                        'interaction_type' => 'WhatsApp',
                        'interaction_date' => date('Y-m-d H:i:s'),
                        'subject'          => 'Incoming WhatsApp from ' . $fromNumber,
                        'notes'            => substr($body, 0, 500),
                        'outcome'          => 'Neutral',
                    ]);

                    // ── Notify assigned sales agent of the inbound reply ──
                    $assignedTo = $lead['assigned_to'] ?? null;
                    if ($assignedTo) {
                        $leadLabel = $lead['contact_person'] ?: ($lead['company_name'] ?: 'Lead #' . $leadId);
                        $snippet   = mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body;
                        try {
                            createNotification(
                                intval($assignedTo),
                                'wa_inbound',
                                'New WhatsApp reply from ' . $leadLabel,
                                ($profileName ? $profileName . ': ' : '') . $snippet,
                                '/pages/lead-detail.php?id=' . $leadId . '#wa-tab',
                                $leadId
                            );
                        } catch (\Exception $ne) {
                            error_log("Notification create failed: " . $ne->getMessage());
                        }
                    }
                } else {
                    // Log that this is an unmatched message for admin visibility
                    error_log("WhatsApp UNMATCHED: Inbound from $fromNumber — no matching lead. Message: " . substr($body, 0, 100));

                    // ── Notify all managers/admins about unmatched inbound ──
                    try {
                        $db = Database::getInstance();
                        $managers = $db->query(
                            "SELECT user_id FROM users WHERE role IN ('Admin','Sales Manager') AND status = 'Active'"
                        )->fetchAll(\PDO::FETCH_COLUMN);
                        $snippet = mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body;
                        foreach ($managers as $mgrId) {
                            createNotification(
                                intval($mgrId),
                                'wa_unmatched',
                                'Unmatched WhatsApp from ' . ($profileName ?: $fromNumber),
                                $snippet,
                                '/pages/whatsapp-dashboard.php#unmatched',
                                null
                            );
                        }
                    } catch (\Exception $ne) {
                        error_log("Unmatched notification failed: " . $ne->getMessage());
                    }
                }
            } catch (Exception $e) {
                error_log("WhatsApp webhook error: " . $e->getMessage());
            }

            // Respond with empty TwiML
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
            break;

        case 'status':
            // Message status update
            $sid    = $_POST['MessageSid'] ?? '';
            $status = $_POST['MessageStatus'] ?? '';

            if ($sid) {
                try {
                    $db = Database::getInstance();
                    $statusMap = [
                        'queued'    => 'Queued',
                        'sent'      => 'Sent',
                        'delivered' => 'Delivered',
                        'read'      => 'Read',
                        'failed'    => 'Failed',
                    ];
                    $dbStatus = $statusMap[$status] ?? 'Sent';
                    $updates = ['status' => $dbStatus];

                    if ($status === 'delivered') $updates['delivered_at'] = date('Y-m-d H:i:s');
                    if ($status === 'read') $updates['read_at'] = date('Y-m-d H:i:s');
                    if ($status === 'failed') {
                        $updates['error_code'] = $_POST['ErrorCode'] ?? null;
                        $updates['error_message'] = $_POST['ErrorMessage'] ?? null;
                    }

                    $set = [];
                    $params = [];
                    foreach ($updates as $k => $v) {
                        $set[] = "`$k` = ?";
                        $params[] = $v;
                    }
                    $params[] = $sid;
                    $db->execute("UPDATE whatsapp_messages SET " . implode(', ', $set) . " WHERE twilio_message_sid = ?", $params);
                } catch (Exception $e) {
                    error_log("WhatsApp status webhook error: " . $e->getMessage());
                }
            }
            http_response_code(200);
            echo '<Response/>';
            break;
    }
}
