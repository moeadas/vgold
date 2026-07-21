<?php
/**
 * Victory Genomics CRM — Automation API
 * CRUD for automation rules + execution logs.
 *
 * Actions (GET):
 *   list          — all rules (with last-run info)
 *   get           — single rule by id
 *   logs          — execution logs (filterable by rule_id)
 *   meta          — trigger/condition/action options for the UI builder
 *
 * Actions (POST):
 *   create        — new rule
 *   update        — edit rule
 *   toggle        — flip is_active
 *   delete        — remove rule
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/twilio.php';

startSecureSession();
requireLogin();
requireRole('Sales Manager'); // SM + Admin

header('Content-Type: application/json');
// SiteGround no-cache
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-LiteSpeed-Cache-Control: no-cache');

$db  = Database::getInstance();
$pdo = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$currentUser = getCurrentUser();

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'list':  listRules($pdo); break;
            case 'get':   getRule($pdo); break;
            case 'logs':  getLogs($pdo); break;
            case 'meta':  getMeta($pdo); break;
            default:      listRules($pdo);
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $csrf = $input['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Refresh the page.']);
            exit;
        }

        switch ($action) {
            case 'create': createRule($pdo, $input, $currentUser); break;
            case 'update': updateRule($pdo, $input); break;
            case 'toggle': toggleRule($pdo, $input); break;
            case 'delete': deleteRule($pdo, $input); break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("Automation API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ══════════════════════════════════════════════════════════
//  GET handlers
// ══════════════════════════════════════════════════════════

function listRules($pdo) {
    $stmt = $pdo->query("
        SELECT r.*,
               u.full_name AS created_by_name,
               (SELECT COUNT(*) FROM automation_logs WHERE rule_id = r.rule_id) AS log_count,
               (SELECT MAX(created_at) FROM automation_logs WHERE rule_id = r.rule_id AND status = 'success') AS last_success
        FROM automation_rules r
        LEFT JOIN users u ON r.created_by = u.user_id
        ORDER BY r.created_at DESC
    ");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rules]);
}

function getRule($pdo) {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Rule ID required']); return; }

    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name AS created_by_name
        FROM automation_rules r
        LEFT JOIN users u ON r.created_by = u.user_id
        WHERE r.rule_id = ?
    ");
    $stmt->execute([$id]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rule) { echo json_encode(['success' => false, 'message' => 'Rule not found']); return; }

    echo json_encode(['success' => true, 'data' => $rule]);
}

function getLogs($pdo) {
    $ruleId = intval($_GET['rule_id'] ?? 0);
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    $where  = '1=1';
    $params = [];

    if ($ruleId) {
        $where .= ' AND al.rule_id = ?';
        $params[] = $ruleId;
    }

    // Count
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM automation_logs al WHERE {$where}");
    $cStmt->execute($params);
    $total = intval($cStmt->fetchColumn());

    // Fetch
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT al.*, l.contact_person, l.company_name
        FROM automation_logs al
        LEFT JOIN leads l ON al.lead_id = l.lead_id
        WHERE {$where}
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $logs,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $limit),
    ]);
}

/**
 * Return metadata for the UI builder: available triggers, conditions, actions, enums.
 */
function getMeta($pdo) {
    // Fetch active users for assign action
    $users = $pdo->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' ORDER BY full_name")
                 ->fetchAll(PDO::FETCH_ASSOC);

    // Fetch email templates (with subject for preview)
    $emailTemplates = $pdo->query("SELECT template_id, name, subject FROM email_templates ORDER BY name")
                          ->fetchAll(PDO::FETCH_ASSOC);

    // ── Fetch WhatsApp templates from BOTH sources ──────────
    $waTemplates = [];

    // 1) Local DB templates (whatsapp_templates, is_active only)
    try {
        $localWa = $pdo->query("SELECT template_id, name, body, category FROM whatsapp_templates WHERE is_active = 1 ORDER BY name")
                       ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($localWa as $t) {
            $waTemplates[] = [
                'id'    => 'local_' . $t['template_id'],
                'type'  => 'local',
                'template_id' => intval($t['template_id']),
                'name'  => $t['name'],
                'body'  => $t['body'] ?? '',
                'category' => $t['category'] ?? '',
            ];
        }
    } catch (\Exception $e) { /* table may not exist */ }

    // 2) Twilio Content API templates (the same ones shown in WA dashboard)
    try {
        $twilio = TwilioHelper::getInstance();
        $sid    = $twilio->getAccountSid();
        $token  = $twilio->getAuthToken();
        if ($sid && $token) {
            $ch = curl_init('https://content.twilio.com/v1/Content?PageSize=50');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "$sid:$token",
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $data = json_decode($resp, true);
                foreach ($data['contents'] ?? [] as $tpl) {
                    $body = '';
                    if (isset($tpl['types']['twilio/text']['body'])) {
                        $body = $tpl['types']['twilio/text']['body'];
                    }
                    $waTemplates[] = [
                        'id'          => 'twilio_' . $tpl['sid'],
                        'type'        => 'twilio',
                        'content_sid' => $tpl['sid'],
                        'name'        => $tpl['friendly_name'] ?? $tpl['sid'],
                        'body'        => $body,
                        'language'    => $tpl['language'] ?? 'en',
                        'variables'   => $tpl['variables'] ?? [],
                    ];
                }
            }
        }
    } catch (\Exception $e) {
        error_log("Automation meta: failed to load Twilio templates: " . $e->getMessage());
    }

    // Available template variables (for variable picker in UI)
    $templateVars = [
        ['tag' => '{{contact_name}}',  'label' => 'Contact Name',  'desc' => 'Lead contact person'],
        ['tag' => '{{company_name}}',  'label' => 'Company Name',  'desc' => 'Lead company name'],
        ['tag' => '{{email}}',         'label' => 'Email',         'desc' => 'Lead email address'],
        ['tag' => '{{phone}}',         'label' => 'Phone',         'desc' => 'Lead phone number'],
        ['tag' => '{{mobile}}',        'label' => 'Mobile',        'desc' => 'Lead mobile number'],
        ['tag' => '{{country}}',       'label' => 'Country',       'desc' => 'Lead country'],
        ['tag' => '{{region}}',        'label' => 'Region',        'desc' => 'Lead region'],
        ['tag' => '{{lead_type}}',     'label' => 'Lead Type',     'desc' => 'Lead type (Stable, Owner, etc.)'],
        ['tag' => '{{lead_status}}',   'label' => 'Lead Status',   'desc' => 'Current lead status'],
        ['tag' => '{{lead_source}}',   'label' => 'Lead Source',   'desc' => 'Lead source channel'],
        ['tag' => '{{priority}}',      'label' => 'Priority',      'desc' => 'Lead priority level'],
        ['tag' => '{{user_name}}',     'label' => 'Agent Name',    'desc' => 'Current user / agent name'],
    ];

    echo json_encode(['success' => true, 'data' => [
        'triggers' => [
            ['value' => 'lead_created',             'label' => 'New lead created'],
            ['value' => 'lead_status_changed',      'label' => 'Lead status changed'],
            ['value' => 'lead_assigned',             'label' => 'Lead assigned (first time)'],
            ['value' => 'lead_reassigned',           'label' => 'Lead reassigned'],
            ['value' => 'lead_source_match',         'label' => 'Lead from specific source'],
            ['value' => 'proposal_status_changed',   'label' => 'Proposal status changed'],
        ],
        'condition_fields' => [
            ['value' => 'country',      'label' => 'Country',       'type' => 'text'],
            ['value' => 'region',       'label' => 'Region',        'type' => 'enum', 'options' => ['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other']],
            ['value' => 'lead_source',  'label' => 'Lead Source',   'type' => 'enum', 'options' => ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other']],
            ['value' => 'lead_type',    'label' => 'Lead Type',     'type' => 'enum', 'options' => ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other']],
            ['value' => 'priority',     'label' => 'Priority',      'type' => 'enum', 'options' => ['Low','Medium','High','Urgent']],
            ['value' => 'lead_status',  'label' => 'Lead Status',   'type' => 'enum', 'options' => ['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold']],
            ['value' => 'assigned_to',  'label' => 'Assigned To',   'type' => 'user'],
        ],
        'condition_operators' => [
            ['value' => 'equals',       'label' => 'equals'],
            ['value' => 'not_equals',   'label' => 'does not equal'],
            ['value' => 'contains',     'label' => 'contains'],
            ['value' => 'not_contains', 'label' => 'does not contain'],
            ['value' => 'is_empty',     'label' => 'is empty'],
            ['value' => 'is_not_empty', 'label' => 'is not empty'],
            ['value' => 'is',           'label' => 'is (exact)'],
            ['value' => 'is_not',       'label' => 'is not (exact)'],
        ],
        'actions' => [
            ['value' => 'assign_user',              'label' => 'Assign to user',            'config' => ['user_id']],
            ['value' => 'send_email_template',      'label' => 'Send email template',       'config' => ['template_id']],
            ['value' => 'send_whatsapp_template',   'label' => 'Send WhatsApp template',    'config' => ['template_id']],
            ['value' => 'send_notification_email',  'label' => 'Send notification email',   'config' => ['recipient','subject','body','email']],
            ['value' => 'change_lead_status',       'label' => 'Change lead status',        'config' => ['status']],
            ['value' => 'change_priority',          'label' => 'Change priority',           'config' => ['priority']],
            ['value' => 'log_interaction',          'label' => 'Log interaction note',      'config' => ['note']],
        ],
        'lead_statuses'  => ['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'],
        'priorities'     => ['Low','Medium','High','Urgent'],
        'proposal_statuses' => ['Draft','Sent','Accepted','Declined'],
        'users'           => $users,
        'email_templates' => $emailTemplates,
        'wa_templates'    => $waTemplates,
        'template_vars'   => $templateVars,
    ]]);
}

// ══════════════════════════════════════════════════════════
//  POST handlers
// ══════════════════════════════════════════════════════════

function createRule($pdo, $input, $currentUser) {
    $name        = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $triggerType = trim($input['trigger_type'] ?? '');
    $triggerCfg  = $input['trigger_config'] ?? null;
    $conditions  = $input['conditions'] ?? null;
    $actionType  = trim($input['action_type'] ?? '');
    $actionCfg   = $input['action_config'] ?? null;

    if ($name === '' || $triggerType === '' || $actionType === '') {
        echo json_encode(['success' => false, 'message' => 'Name, trigger, and action are required.']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO automation_rules
            (name, description, trigger_type, trigger_config, conditions, action_type, action_config, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $name,
        $description ?: null,
        $triggerType,
        is_array($triggerCfg)  ? json_encode($triggerCfg)  : ($triggerCfg ?: null),
        is_array($conditions)  ? json_encode($conditions)  : ($conditions ?: null),
        $actionType,
        is_array($actionCfg)   ? json_encode($actionCfg)   : ($actionCfg ?: null),
        $currentUser['user_id'],
    ]);

    $ruleId = intval($pdo->lastInsertId());
    echo json_encode(['success' => true, 'message' => 'Rule created.', 'rule_id' => $ruleId]);
}

function updateRule($pdo, $input) {
    $ruleId      = intval($input['rule_id'] ?? 0);
    $name        = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $triggerType = trim($input['trigger_type'] ?? '');
    $triggerCfg  = $input['trigger_config'] ?? null;
    $conditions  = $input['conditions'] ?? null;
    $actionType  = trim($input['action_type'] ?? '');
    $actionCfg   = $input['action_config'] ?? null;

    if (!$ruleId || $name === '' || $triggerType === '' || $actionType === '') {
        echo json_encode(['success' => false, 'message' => 'Rule ID, name, trigger, and action required.']);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE automation_rules SET
            name = ?, description = ?, trigger_type = ?, trigger_config = ?,
            conditions = ?, action_type = ?, action_config = ?
        WHERE rule_id = ?
    ");
    $stmt->execute([
        $name,
        $description ?: null,
        $triggerType,
        is_array($triggerCfg) ? json_encode($triggerCfg) : ($triggerCfg ?: null),
        is_array($conditions) ? json_encode($conditions) : ($conditions ?: null),
        $actionType,
        is_array($actionCfg)  ? json_encode($actionCfg)  : ($actionCfg ?: null),
        $ruleId,
    ]);

    echo json_encode(['success' => true, 'message' => 'Rule updated.']);
}

function toggleRule($pdo, $input) {
    $ruleId = intval($input['rule_id'] ?? 0);
    if (!$ruleId) { echo json_encode(['success' => false, 'message' => 'Rule ID required']); return; }

    $stmt = $pdo->prepare("UPDATE automation_rules SET is_active = NOT is_active WHERE rule_id = ?");
    $stmt->execute([$ruleId]);

    // Return new state
    $st = $pdo->prepare("SELECT is_active FROM automation_rules WHERE rule_id = ?");
    $st->execute([$ruleId]);
    $active = intval($st->fetchColumn());

    echo json_encode(['success' => true, 'message' => $active ? 'Rule activated.' : 'Rule deactivated.', 'is_active' => $active]);
}

function deleteRule($pdo, $input) {
    $ruleId = intval($input['rule_id'] ?? 0);
    if (!$ruleId) { echo json_encode(['success' => false, 'message' => 'Rule ID required']); return; }

    // Logs cascade via FK
    $stmt = $pdo->prepare("DELETE FROM automation_rules WHERE rule_id = ?");
    $stmt->execute([$ruleId]);

    echo json_encode(['success' => true, 'message' => 'Rule deleted.']);
}
