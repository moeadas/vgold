<?php
/**
 * Victory Genomics CRM - Leads API
 * Handles CRUD operations for leads
 * SiteGround MySQL compatible
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/twilio.php';
require_once __DIR__ . '/../includes/automation-engine.php';
require_once __DIR__ . '/../includes/notification-helper.php';  // createNotification()

startSecureSession();
requireLogin();

header('Content-Type: application/json');
// Bust SiteGround / LiteSpeed dynamic cache for API responses
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-LiteSpeed-Cache-Control: no-cache');

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$currentUser = getCurrentUser();

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($db, $action, $currentUser);
            break;
        case 'POST':
            handlePostRequest($db, $action, $currentUser);
            break;
        case 'PUT':
            handlePutRequest($db, $action, $currentUser);
            break;
        case 'DELETE':
            handleDeleteRequest($db, $action, $currentUser);
            break;
        default:
            jsonError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("Leads API Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    jsonError('An error occurred: ' . $e->getMessage(), 500);
}

function handleGetRequest($db, $action, $currentUser) {
    switch ($action) {
        case 'list':   getLeadsList($db, $currentUser); break;
        case 'detail': getLeadDetail($db, $currentUser); break;
        case 'stats':  getLeadStats($db, $currentUser); break;
        case 'search': searchLeads($db, $currentUser); break;
        default:       getLeadsList($db, $currentUser);
    }
}

/**
 * Quick search leads by name, company, phone — used by link-to-lead in WhatsApp unmatched.
 * Role-scoped: non-managers only search leads assigned to / created by them.
 */
function searchLeads($db, $currentUser) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, $like];
    $scope  = '';
    if (!hasRole('Sales Manager')) {
        $scope    = ' AND (assigned_to = ? OR created_by = ?)';
        $params[] = $currentUser['user_id'];
        $params[] = $currentUser['user_id'];
    }
    $stmt = $db->prepare("
        SELECT lead_id, contact_person, company_name, phone, mobile, email, lead_status AS status
        FROM leads
        WHERE (contact_person LIKE ? OR company_name LIKE ? OR phone LIKE ? OR mobile LIKE ? OR email LIKE ?)
        $scope
        ORDER BY contact_person ASC
        LIMIT 15
    ");
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $results]);
}

function handlePostRequest($db, $action, $currentUser) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) $data = $_POST;

    // Verify CSRF
    $token = $data['csrf_token'] ?? null;
    if (!verifyCSRFToken($token)) {
        jsonError('Your session has expired. Please refresh the page and try again.', 403);
    }

    // Viewers are read-only — writes require at least Sales Rep
    if (!hasRole('Sales Rep')) {
        jsonError('Your account is view-only. Ask an administrator for Sales Rep access.', 403);
    }

    switch ($action) {
        case 'create':      createLead($db, $data, $currentUser); break;
        case 'bulk_assign':  bulkAssignLeads($db, $data, $currentUser); break;
        case 'bulk_delete':  bulkDeleteLeads($db, $data, $currentUser); break;
        default:             jsonError('Unknown action', 400);
    }
}

function handlePutRequest($db, $action, $currentUser) {
    $data = json_decode(file_get_contents('php://input'), true);

    $token = $data['csrf_token'] ?? null;
    if (!verifyCSRFToken($token)) {
        jsonError('Your session has expired. Please refresh the page and try again.', 403);
    }

    // Viewers are read-only — writes require at least Sales Rep
    if (!hasRole('Sales Rep')) {
        jsonError('Your account is view-only. Ask an administrator for Sales Rep access.', 403);
    }

    switch ($action) {
        case 'update': updateLead($db, $data, $currentUser); break;
        case 'status': updateLeadStatusAPI($db, $data, $currentUser); break;
        default:       jsonError('Unknown action', 400);
    }
}

function handleDeleteRequest($db, $action, $currentUser) {
    if (!hasRole('Sales Manager')) {
        jsonError('Permission denied', 403);
    }

    // CSRF check for DELETE too
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['csrf_token'] ?? $_GET['csrf_token'] ?? null;
    if (!verifyCSRFToken($token)) {
        jsonError('Invalid or expired request token.', 403);
    }

    $leadId = intval($_GET['id'] ?? 0);
    if (!$leadId) jsonError('Lead ID required', 400);

    deleteLead($db, $leadId, $currentUser);
}

// ─── Access helpers ─────────────────────────────────────────

/**
 * Verify the current user can access / edit a specific lead.
 * Managers & Admins can access any lead; Sales Reps can only access leads
 * assigned to them OR created by them.
 * Returns the lead row on success, calls jsonError on failure.
 */
function requireLeadAccess($db, $leadId, $currentUser) {
    $stmt = $db->prepare("SELECT * FROM leads WHERE lead_id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) {
        jsonError('Lead not found', 404);
    }
    // Managers / Admins can access any lead
    if (hasRole('Sales Manager')) {
        return $lead;
    }
    // Sales Reps: must be assigned_to OR created_by
    $uid = $currentUser['user_id'];
    if ($lead['assigned_to'] == $uid || $lead['created_by'] == $uid) {
        return $lead;
    }
    jsonError('Access denied — this lead is not assigned to you.', 403);
}

// ─── GET Handlers ───────────────────────────────────────────

function getLeadsList($db, $currentUser) {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    $where  = ['1=1'];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[]  = 'l.lead_status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['country'])) {
        $where[]  = 'l.country = ?';
        $params[] = $_GET['country'];
    }
    if (!empty($_GET['assigned_to'])) {
        $where[]  = 'l.assigned_to = ?';
        $params[] = $_GET['assigned_to'];
    }
    if (!empty($_GET['search'])) {
        $where[] = '(l.company_name LIKE ? OR l.contact_person LIKE ? OR l.email LIKE ?)';
        $term = '%' . $_GET['search'] . '%';
        $params = array_merge($params, [$term, $term, $term]);
    }

    // Follow-up filter: leads that have at least one 'Follow-up' interaction
    $followUpFilter = !empty($_GET['follow_up']) && $_GET['follow_up'] == '1';
    if ($followUpFilter) {
        $where[] = "l.lead_id IN (
            SELECT DISTINCT lead_id FROM interactions WHERE interaction_type = 'Follow-up'
        )";
    }

    // Non-manager users see only their leads
    if (!hasRole('Sales Manager')) {
        $where[]  = '(l.assigned_to = ? OR l.created_by = ?)';
        $params[] = $currentUser['user_id'];
        $params[] = $currentUser['user_id'];
    }

    $whereClause = implode(' AND ', $where);

    // Count
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM leads l WHERE $whereClause");
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];

    // ── Sorting ────────────────────────────────────────────────────────
    $allowedSortColumns = [
        'updated_at'     => 'l.updated_at',
        'created_at'     => 'l.created_at',
        'company_name'   => 'l.company_name',
        'contact_person' => 'l.contact_person',
        'country'        => 'l.country',
        'lead_status'    => 'l.lead_status',
        'priority'       => 'l.priority',
        'lead_source'    => 'l.lead_source',
        'assigned_name'  => 'u1.full_name',
        'lead_type'      => 'l.lead_type',
    ];

    $sortBy  = $_GET['sort_by'] ?? 'updated_at';
    $sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC');

    // Whitelist validation
    $sortColumn = $allowedSortColumns[$sortBy] ?? 'l.updated_at';
    $sortDir    = ($sortDir === 'ASC') ? 'ASC' : 'DESC';

    // Leads with LEFT JOIN for interaction count (no N+1)
    $sql = "
        SELECT l.*, 
               u1.full_name as assigned_name,
               u2.full_name as created_name,
               COALESCE(ic.cnt, 0) as interaction_count
        FROM leads l
        LEFT JOIN users u1 ON l.assigned_to = u1.user_id
        LEFT JOIN users u2 ON l.created_by = u2.user_id
        LEFT JOIN (
            SELECT lead_id, COUNT(*) as cnt FROM interactions GROUP BY lead_id
        ) ic ON ic.lead_id = l.lead_id
        WHERE $whereClause
        ORDER BY $sortColumn $sortDir
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    jsonSuccess('Leads retrieved', [
        'leads'    => $leads,
        'total'    => $total,
        'page'     => $page,
        'pages'    => ceil($total / $limit),
        'sort_by'  => $sortBy,
        'sort_dir' => $sortDir,
    ]);
}

function getLeadDetail($db, $currentUser) {
    $leadId = intval($_GET['id'] ?? 0);
    if (!$leadId) jsonError('Lead ID required', 400);

    // Ownership check — sales reps can only view their own leads
    requireLeadAccess($db, $leadId, $currentUser);

    $stmt = $db->prepare("
        SELECT l.*, 
               u1.full_name as assigned_name,
               u2.full_name as created_name
        FROM leads l
        LEFT JOIN users u1 ON l.assigned_to = u1.user_id
        LEFT JOIN users u2 ON l.created_by = u2.user_id
        WHERE l.lead_id = ?
    ");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) jsonError('Lead not found', 404);

    $intStmt = $db->prepare("
        SELECT i.*, u.full_name as user_name
        FROM interactions i LEFT JOIN users u ON i.user_id = u.user_id
        WHERE i.lead_id = ? ORDER BY i.interaction_date DESC
    ");
    $intStmt->execute([$leadId]);
    $interactions = $intStmt->fetchAll();

    $docStmt = $db->prepare("
        SELECT d.*, u.full_name as uploaded_by_name
        FROM documents d LEFT JOIN users u ON d.uploaded_by = u.user_id
        WHERE d.lead_id = ? ORDER BY d.uploaded_at DESC
    ");
    $docStmt->execute([$leadId]);
    $documents = $docStmt->fetchAll();

    jsonSuccess('Lead details retrieved', [
        'lead'         => $lead,
        'interactions' => $interactions,
        'documents'    => $documents,
    ]);
}

function getLeadStats($db, $currentUser) {
    // Role-scoped: non-managers only see stats for their own leads
    $scope  = '';
    $params = [];
    if (!hasRole('Sales Manager')) {
        $scope  = ' AND (assigned_to = ? OR created_by = ?)';
        $params = [$currentUser['user_id'], $currentUser['user_id']];
    }

    $stats = [];

    $stmt = $db->prepare("SELECT COUNT(*) as c FROM leads WHERE 1=1 $scope");
    $stmt->execute($params);
    $stats['total'] = $stmt->fetch()['c'];

    $stmt = $db->prepare("SELECT lead_status, COUNT(*) as count FROM leads WHERE 1=1 $scope GROUP BY lead_status");
    $stmt->execute($params);
    $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $db->prepare("SELECT country, COUNT(*) as count FROM leads WHERE country IS NOT NULL AND country != '' $scope GROUP BY country ORDER BY count DESC");
    $stmt->execute($params);
    $stats['by_country'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $db->prepare("SELECT COUNT(*) as c FROM leads WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) $scope");
    $stmt->execute($params);
    $stats['this_month'] = $stmt->fetch()['c'];

    jsonSuccess('Stats retrieved', $stats);
}

// ─── POST / PUT Handlers ──────────────────────────────────────

function emptyToNull($value) {
    return (isset($value) && $value !== '') ? $value : null;
}

function createLead($db, $data, $currentUser) {
    // Only the contact person's name is required; everything else is optional
    $fieldErrors = [];
    $contactPerson = trim($data['contact_person'] ?? '');
    if ($contactPerson === '') {
        $fieldErrors['contact_person'] = 'Cannot be empty';
    }
    if (!empty($fieldErrors)) {
        echo json_encode(['success' => false, 'message' => 'Please fix the highlighted fields.', 'field_errors' => $fieldErrors]);
        return;
    }

    // Convert empty strings to null for nullable/enum/int fields
    $numHorses = emptyToNull($data['number_of_horses'] ?? null);
    if ($numHorses !== null) $numHorses = intval($numHorses);
    $assignedTo = emptyToNull($data['assigned_to'] ?? null);
    if ($assignedTo === null) $assignedTo = $currentUser['user_id'];

    $stmt = $db->prepare("
        INSERT INTO leads (
            lead_type, company_name, contact_person, title_position, country, city,
            address, phone, mobile, email, website, facebook_url, instagram_url, linkedin_url,
            twitter_url, youtube_url, specialization, facility_type, number_of_horses, notes,
            lead_status, lead_source, priority, assigned_to, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['lead_type'] ?? 'Other', emptyToNull($data['company_name'] ?? null),
        $contactPerson, emptyToNull($data['title_position'] ?? null),
        emptyToNull($data['country'] ?? null), emptyToNull($data['city'] ?? null),
        emptyToNull($data['address'] ?? null), emptyToNull($data['phone'] ?? null), emptyToNull($data['mobile'] ?? null),
        emptyToNull($data['email'] ?? null), emptyToNull($data['website'] ?? null),
        emptyToNull($data['facebook_url'] ?? null), emptyToNull($data['instagram_url'] ?? null),
        emptyToNull($data['linkedin_url'] ?? null), emptyToNull($data['twitter_url'] ?? null),
        emptyToNull($data['youtube_url'] ?? null), emptyToNull($data['specialization'] ?? null),
        emptyToNull($data['facility_type'] ?? null), $numHorses,
        emptyToNull($data['notes'] ?? null), $data['lead_status'] ?? 'New Lead',
        $data['lead_source'] ?? 'Other', $data['priority'] ?? 'Medium',
        $assignedTo, $currentUser['user_id'],
    ]);

    $leadName = $data['contact_person'] ?? $data['company_name'] ?? 'New Lead';
    $leadId = $db->lastInsertId();
    logActivity($currentUser['user_id'], 'Created', 'Lead', $leadId, 'Created lead: ' . $leadName);

    // Send WhatsApp + in-app notification if lead is assigned to someone other than creator
    if ($assignedTo && $assignedTo != $currentUser['user_id']) {
        TwilioHelper::notifyLeadAssignment($assignedTo, $leadName, $leadId, $currentUser['full_name'] ?? '');
        try {
            createNotification(
                intval($assignedTo),
                'lead_assigned',
                'New lead assigned: ' . $leadName,
                'Assigned by ' . ($currentUser['full_name'] ?? 'System'),
                '/pages/lead-detail.php?id=' . $leadId,
                $leadId
            );
        } catch (\Exception $ne) {
            error_log("Lead assign notification failed: " . $ne->getMessage());
        }
    }

    // ── Automation triggers ──────────────────────────────────────
    // NOTE: these must run BEFORE jsonSuccess() — it exit()s, so anything
    // after it never executes. Wrapped so an automation failure can never
    // break the lead-save response.
    try {
        $leadStmt = $db->prepare("SELECT * FROM leads WHERE lead_id = ?");
        $leadStmt->execute([$leadId]);
        $freshLead = $leadStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $autoCtx = [
            'lead_id'      => intval($leadId),
            'lead'         => $freshLead,
            'current_user' => $currentUser,
        ];
        fireAutomationTrigger('lead_created', $autoCtx);

        // Source-match trigger
        if (!empty($freshLead['lead_source'])) {
            fireAutomationTrigger('lead_source_match', $autoCtx);
        }

        // Assignment trigger (first-time assign)
        if ($assignedTo) {
            $autoCtx['new_assigned'] = $assignedTo;
            fireAutomationTrigger('lead_assigned', $autoCtx);
        }
    } catch (\Throwable $ae) {
        error_log("Lead-create automation error: " . $ae->getMessage());
    }

    jsonSuccess('Lead created successfully', ['lead_id' => $leadId]);
}

function updateLead($db, $data, $currentUser) {
    if (empty($data['lead_id'])) jsonError('Lead ID required', 400);

    // Ownership check — sales reps can only edit their own leads
    requireLeadAccess($db, intval($data['lead_id']), $currentUser);

    // Only the contact person's name is required
    $fieldErrors = [];
    $contactPerson = trim($data['contact_person'] ?? '');
    if ($contactPerson === '') {
        $fieldErrors['contact_person'] = 'Cannot be empty';
    }
    if (!empty($fieldErrors)) {
        echo json_encode(['success' => false, 'message' => 'Please fix the highlighted fields.', 'field_errors' => $fieldErrors]);
        return;
    }

    // Get previous assigned_to before updating
    $prevStmt = $db->prepare("SELECT assigned_to FROM leads WHERE lead_id = ?");
    $prevStmt->execute([$data['lead_id']]);
    $prevLead = $prevStmt->fetch();
    $previousAssignedTo = $prevLead ? $prevLead['assigned_to'] : null;

    // Convert empty strings to null for nullable/enum/int fields
    $numHorses = emptyToNull($data['number_of_horses'] ?? null);
    if ($numHorses !== null) $numHorses = intval($numHorses);

    $newAssignedTo = emptyToNull($data['assigned_to'] ?? null);

    $stmt = $db->prepare("
        UPDATE leads SET
            lead_type = ?, company_name = ?, contact_person = ?, title_position = ?,
            country = ?, city = ?, address = ?, phone = ?, mobile = ?,
            email = ?, website = ?, facebook_url = ?, instagram_url = ?, linkedin_url = ?,
            twitter_url = ?, youtube_url = ?, specialization = ?, facility_type = ?,
            number_of_horses = ?, notes = ?, lead_status = ?, lead_source = ?,
            priority = ?, assigned_to = ?
        WHERE lead_id = ?
    ");
    $stmt->execute([
        $data['lead_type'] ?? 'Other', emptyToNull($data['company_name'] ?? null),
        $contactPerson, emptyToNull($data['title_position'] ?? null),
        emptyToNull($data['country'] ?? null), emptyToNull($data['city'] ?? null),
        emptyToNull($data['address'] ?? null), emptyToNull($data['phone'] ?? null), emptyToNull($data['mobile'] ?? null),
        emptyToNull($data['email'] ?? null), emptyToNull($data['website'] ?? null),
        emptyToNull($data['facebook_url'] ?? null), emptyToNull($data['instagram_url'] ?? null),
        emptyToNull($data['linkedin_url'] ?? null), emptyToNull($data['twitter_url'] ?? null),
        emptyToNull($data['youtube_url'] ?? null), emptyToNull($data['specialization'] ?? null),
        emptyToNull($data['facility_type'] ?? null), $numHorses,
        emptyToNull($data['notes'] ?? null), $data['lead_status'] ?? 'New Lead', $data['lead_source'] ?? 'Other',
        $data['priority'] ?? 'Medium', $newAssignedTo, $data['lead_id'],
    ]);

    $leadName = $data['contact_person'] ?? $data['company_name'] ?? 'Lead #' . $data['lead_id'];
    logActivity($currentUser['user_id'], 'Updated', 'Lead', $data['lead_id'], 'Updated lead: ' . $leadName);

    // Send WhatsApp + in-app notification if assignment changed
    if ($newAssignedTo && $newAssignedTo != $previousAssignedTo) {
        TwilioHelper::notifyLeadAssignment(
            intval($newAssignedTo),
            $leadName,
            intval($data['lead_id']),
            $currentUser['full_name'] ?? ''
        );
        // In-app notification
        try {
            createNotification(
                intval($newAssignedTo),
                'lead_assigned',
                'New lead assigned: ' . $leadName,
                'Assigned by ' . ($currentUser['full_name'] ?? 'System'),
                '/pages/lead-detail.php?id=' . intval($data['lead_id']),
                intval($data['lead_id'])
            );
        } catch (\Exception $ne) {
            error_log("Lead assign notification failed: " . $ne->getMessage());
        }
    }

    // ── Automation triggers ──────────────────────────────────────
    // Must run BEFORE jsonSuccess() (it exit()s). Failures never break the save.
    try {
        $updStmt = $db->prepare("SELECT * FROM leads WHERE lead_id = ?");
        $updStmt->execute([$data['lead_id']]);
        $updatedLead = $updStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $autoCtx = [
            'lead_id'      => intval($data['lead_id']),
            'lead'         => $updatedLead,
            'current_user' => $currentUser,
        ];

        // Status changed?
        $oldStatus = $data['_old_lead_status'] ?? null;
        $newStatus = $data['lead_status'] ?? ($updatedLead['lead_status'] ?? null);
        if ($oldStatus && $newStatus && $oldStatus !== $newStatus) {
            $autoCtx['old_status'] = $oldStatus;
            $autoCtx['new_status'] = $newStatus;
            fireAutomationTrigger('lead_status_changed', $autoCtx);
        }

        // Assignment changed?
        if ($newAssignedTo && $newAssignedTo != $previousAssignedTo) {
            $autoCtx['old_assigned'] = $previousAssignedTo;
            $autoCtx['new_assigned'] = $newAssignedTo;
            if ($previousAssignedTo) {
                fireAutomationTrigger('lead_reassigned', $autoCtx);
            } else {
                fireAutomationTrigger('lead_assigned', $autoCtx);
            }
        }
    } catch (\Throwable $ae) {
        error_log("Lead-update automation error: " . $ae->getMessage());
    }

    jsonSuccess('Lead updated successfully');
}

function updateLeadStatusAPI($db, $data, $currentUser) {
    $leadId = $data['lead_id'] ?? null;
    $status = $data['status'] ?? $data['lead_status'] ?? null;
    if (empty($leadId) || empty($status)) jsonError('Lead ID and status required', 400);

    // Ownership check — sales reps can only change status on their own leads
    requireLeadAccess($db, intval($leadId), $currentUser);

    // Get old status for automation
    $oldStmt = $db->prepare("SELECT lead_status FROM leads WHERE lead_id = ?");
    $oldStmt->execute([$leadId]);
    $oldRow = $oldStmt->fetch();
    $oldStatus = $oldRow ? $oldRow['lead_status'] : null;

    $stmt = $db->prepare("UPDATE leads SET lead_status = ? WHERE lead_id = ?");
    $stmt->execute([$status, $leadId]);
    logActivity($currentUser['user_id'], 'Status Change', 'Lead', $leadId, 'Changed status to: ' . $status);

    // Automation: status changed — must run BEFORE jsonSuccess() (it exit()s).
    if ($oldStatus && $oldStatus !== $status) {
        try {
            $lStmt = $db->prepare("SELECT * FROM leads WHERE lead_id = ?");
            $lStmt->execute([$leadId]);
            $lead = $lStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            fireAutomationTrigger('lead_status_changed', [
                'lead_id'      => intval($leadId),
                'lead'         => $lead,
                'old_status'   => $oldStatus,
                'new_status'   => $status,
                'current_user' => $currentUser,
            ]);
        } catch (\Throwable $ae) {
            error_log("Lead-status automation error: " . $ae->getMessage());
        }
    }

    jsonSuccess('Status updated successfully');
}

function deleteLead($db, $leadId, $currentUser) {
    $stmt = $db->prepare("SELECT contact_person, company_name FROM leads WHERE lead_id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) jsonError('Lead not found', 404);

    $stmt = $db->prepare("DELETE FROM leads WHERE lead_id = ?");
    $stmt->execute([$leadId]);
    $leadName = $lead['contact_person'] ?: $lead['company_name'] ?: 'Lead #' . $leadId;
    logActivity($currentUser['user_id'], 'Deleted', 'Lead', $leadId, 'Deleted lead: ' . $leadName);
    jsonSuccess('Lead deleted successfully');
}

// ─── Bulk Operations (Parameterized) ──────────────────────────────────

function bulkAssignLeads($db, $data, $currentUser) {
    if (!hasRole('Sales Manager')) jsonError('Permission denied', 403);
    if (empty($data['lead_ids']) || !is_array($data['lead_ids'])) jsonError('Lead IDs required', 400);
    if (empty($data['assigned_to'])) jsonError('Assigned user required', 400);

    $stmt = $db->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $stmt->execute([$data['assigned_to']]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Target user not found', 404);

    $ids = array_map('intval', $data['lead_ids']);
    $in  = Database::buildInClause($ids);

    $sql  = "UPDATE leads SET assigned_to = ? WHERE lead_id IN ({$in['placeholders']})";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$data['assigned_to']], $in['params']));

    $count = count($ids);
    logActivity($currentUser['user_id'], 'Bulk Assign', 'Lead', 0, "Assigned $count leads to " . $user['full_name']);

    // Send WhatsApp notification for bulk assignment
    $assignedToId = intval($data['assigned_to']);
    if ($assignedToId != $currentUser['user_id']) {
        TwilioHelper::notifyLeadAssignment(
            $assignedToId,
            "$count lead(s) (bulk assignment)",
            0,
            $currentUser['full_name'] ?? ''
        );
    }

    jsonSuccess("Successfully assigned $count leads");
}

function bulkDeleteLeads($db, $data, $currentUser) {
    if (!hasRole('Sales Manager')) jsonError('Permission denied', 403);
    if (empty($data['lead_ids']) || !is_array($data['lead_ids'])) jsonError('Lead IDs required', 400);

    $ids = array_map('intval', $data['lead_ids']);
    $in  = Database::buildInClause($ids);

    // Delete related records first (parameterized)
    $stmt = $db->prepare("DELETE FROM interactions WHERE lead_id IN ({$in['placeholders']})");
    $stmt->execute($in['params']);

    $stmt = $db->prepare("DELETE FROM documents WHERE lead_id IN ({$in['placeholders']})");
    $stmt->execute($in['params']);

    $stmt = $db->prepare("DELETE FROM leads WHERE lead_id IN ({$in['placeholders']})");
    $stmt->execute($in['params']);

    $count = count($ids);
    logActivity($currentUser['user_id'], 'Bulk Delete', 'Lead', 0, "Deleted $count leads");
    jsonSuccess("Successfully deleted $count leads");
}
?>
