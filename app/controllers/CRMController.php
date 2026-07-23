<?php

class CRMController {
    private static function requireCrm() {
        if (!Authz::hasAnyCrmAccess()) jsonError('You do not have access to CRM', 403);
    }

    // A VGold admin, or a real CRM Admin/Sales Manager, sees all records.
    // Everyone else is scoped to leads they own or are assigned (mirrors the
    // legacy crm/api/leads.php scoping so the native SPA endpoints don't leak).
    private static function isCrmManager() {
        $u = Auth::user();
        if ($u && ($u['role'] ?? '') === 'admin') return true;
        $role = $_SESSION['crm_role'] ?? null;
        return in_array($role, ['Admin', 'Sales Manager'], true);
    }

    public static function dashboard() {
        self::requireCrm();
        $modules = Authz::grantedModules();
        $stats = ['leads' => null, 'follow_ups' => null, 'overdue' => null, 'won' => null];
        if (in_array('crm.leads', $modules, true) || in_array('crm.dashboard', $modules, true)) {
            $row = DB::fetch(
                "SELECT COUNT(*) total, SUM(CASE WHEN lead_status = 'Won' THEN 1 ELSE 0 END) won FROM crm_leads"
            );
            $stats['leads'] = (int)($row['total'] ?? 0);
            $stats['won'] = (int)($row['won'] ?? 0);
        }
        if (in_array('crm.interactions', $modules, true) || in_array('crm.dashboard', $modules, true)) {
            $row = DB::fetch(
                "SELECT COUNT(*) total,
                        SUM(CASE WHEN i.next_action_date < CURDATE() THEN 1 ELSE 0 END) overdue
                 FROM crm_interactions i
                 LEFT JOIN crm_task_links ctl ON ctl.crm_interaction_id = i.interaction_id
                 LEFT JOIN tasks t ON t.id = ctl.task_id
                 WHERE i.next_action IS NOT NULL AND TRIM(i.next_action) <> ''
                   AND (t.id IS NULL OR t.status <> 'completed')"
            );
            $stats['follow_ups'] = (int)($row['total'] ?? 0);
            $stats['overdue'] = (int)($row['overdue'] ?? 0);
        }
        jsonResponse(['stats' => $stats, 'modules' => $modules]);
    }

    public static function leads() {
        Authz::requireModuleAccess('crm.leads');
        $q = trim($_GET['q'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(l.company_name LIKE ? OR l.contact_person LIKE ? OR l.email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'l.lead_status = ?';
            $params[] = $status;
        }
        if (!self::isCrmManager()) {
            $crmId = Auth::crmUserId();
            if ($crmId) {
                $where[] = '(l.assigned_to = ? OR l.created_by = ?)';
                $params[] = $crmId;
                $params[] = $crmId;
            } else {
                $where[] = '1=0'; // no CRM identity → see nothing rather than everything
            }
        }
        $rows = DB::fetchAll(
            "SELECT l.*, u.id AS assigned_vgold_id, u.name AS assigned_name
             FROM crm_leads l LEFT JOIN users u ON u.crm_user_id = l.assigned_to
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(l.priority, 'Urgent','High','Medium','Low'), l.updated_at DESC LIMIT 200",
            $params
        );
        jsonResponse(['leads' => array_map([self::class, 'formatLead'], $rows)]);
    }

    public static function leadOptions() {
        if (!Authz::hasModuleAccess('crm.leads') && !Authz::hasModuleAccess('crm.interactions')) {
            jsonError('You do not have access to CRM leads', 403);
        }
        $rows = DB::fetchAll(
            "SELECT lead_id, company_name, contact_person, lead_status FROM crm_leads
             ORDER BY COALESCE(NULLIF(contact_person, ''), company_name) ASC LIMIT 500"
        );
        jsonResponse(['leads' => array_map(fn($row) => [
            'id' => (int)$row['lead_id'],
            'name' => $row['contact_person'] ?: $row['company_name'] ?: ('Lead #' . $row['lead_id']),
            'company' => $row['company_name'],
            'status' => $row['lead_status'],
        ], $rows)]);
    }

    public static function createLead() {
        Authz::requireModuleAccess('crm.leads');
        $data = input();
        $company = trim($data['company_name'] ?? '');
        $contact = trim($data['contact_person'] ?? '');
        if ($company === '' && $contact === '') jsonError('Enter a lead or company name');

        $assignedVgoldId = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : Auth::userId();
        $assignedCrmId = self::crmUserIdForWorkspaceMember($assignedVgoldId, true);
        $creatorCrmId = self::crmUserIdForWorkspaceMember(Auth::userId(), true);
        $id = DB::insert('crm_leads', [
            'company_name' => $company ?: null,
            'contact_person' => $contact ?: null,
            'email' => self::nullable($data['email'] ?? null),
            'phone' => self::nullable($data['phone'] ?? null),
            'country' => self::nullable($data['country'] ?? null),
            'region' => self::nullable($data['region'] ?? null),
            'lead_type' => self::choice($data['lead_type'] ?? 'Stable', ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'], 'Stable'),
            'lead_status' => self::choice($data['status'] ?? 'New Lead', ['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold','Not Interested'], 'New Lead'),
            'priority' => self::choice($data['priority'] ?? 'Medium', ['Low','Medium','High','Urgent'], 'Medium'),
            'notes' => self::nullable($data['notes'] ?? null),
            'assigned_to' => $assignedCrmId,
            'created_by' => $creatorCrmId,
        ]);
        jsonResponse(['ok' => true, 'id' => (int)$id], 201);
    }

    public static function interactions() {
        Authz::requireModuleAccess('crm.interactions');
        $scopeSql = '';
        $scopeParams = [];
        if (!self::isCrmManager()) {
            $crmId = Auth::crmUserId();
            if ($crmId) {
                $scopeSql = ' WHERE (l.assigned_to = ? OR l.created_by = ? OR i.user_id = ?)';
                $scopeParams = [$crmId, $crmId, $crmId];
            } else {
                $scopeSql = ' WHERE 1=0';
            }
        }
        $rows = DB::fetchAll(
            "SELECT i.*, l.company_name, l.contact_person, u.name AS user_name,
                    ctl.task_id AS workflow_task_id, t.status AS workflow_task_status
             FROM crm_interactions i
             JOIN crm_leads l ON l.lead_id = i.lead_id
             LEFT JOIN users u ON u.crm_user_id = i.user_id
             LEFT JOIN crm_task_links ctl ON ctl.crm_interaction_id = i.interaction_id
             LEFT JOIN tasks t ON t.id = ctl.task_id" . $scopeSql . "
             ORDER BY i.interaction_date DESC, i.interaction_id DESC LIMIT 200",
            $scopeParams
        );
        jsonResponse(['interactions' => array_map([self::class, 'formatInteraction'], $rows)]);
    }

    // Native lead detail: the single lead plus its full interaction/follow-up
    // timeline. Same owner-scoping as the list endpoints so reps can't read a
    // lead that isn't theirs by guessing an id.
    public static function leadDetail($id) {
        Authz::requireModuleAccess('crm.leads');
        $id = (int)$id;
        $lead = DB::fetch(
            "SELECT l.*, u.id AS assigned_vgold_id, u.name AS assigned_vgold_name,
                    cu.full_name AS assigned_crm_name, cb.full_name AS created_crm_name
             FROM crm_leads l
             LEFT JOIN users u ON u.crm_user_id = l.assigned_to
             LEFT JOIN crm_users cu ON cu.user_id = l.assigned_to
             LEFT JOIN crm_users cb ON cb.user_id = l.created_by
             WHERE l.lead_id = ?",
            [$id]
        );
        if (!$lead) jsonError('Lead not found', 404);
        self::assertLeadAccess($lead);

        $rows = DB::fetchAll(
            "SELECT i.*, l.company_name, l.contact_person, u.name AS user_name,
                    ctl.task_id AS workflow_task_id, t.status AS workflow_task_status
             FROM crm_interactions i
             JOIN crm_leads l ON l.lead_id = i.lead_id
             LEFT JOIN users u ON u.crm_user_id = i.user_id
             LEFT JOIN crm_task_links ctl ON ctl.crm_interaction_id = i.interaction_id
             LEFT JOIN tasks t ON t.id = ctl.task_id
             WHERE i.lead_id = ?
             ORDER BY i.interaction_date DESC, i.interaction_id DESC LIMIT 100",
            [$id]
        );
        jsonResponse([
            'lead' => self::formatLeadDetail($lead),
            'interactions' => array_map([self::class, 'formatInteraction'], $rows),
        ]);
    }

    public static function updateLead($id) {
        Authz::requireModuleAccess('crm.leads');
        $id = (int)$id;
        $lead = DB::fetch("SELECT * FROM crm_leads WHERE lead_id = ?", [$id]);
        if (!$lead) jsonError('Lead not found', 404);
        self::assertLeadAccess($lead);
        $data = input();

        $fields = [];
        foreach (['company_name','contact_person','title_position','city','address','phone','mobile','email','website','specialization','horse_breed','horse_sex','notes','facebook_url','instagram_url','linkedin_url','twitter_url','youtube_url'] as $f) {
            if (array_key_exists($f, $data)) $fields[$f] = self::nullable($data[$f]);
        }
        // country + region are NOT NULL — only overwrite when a real value is sent.
        if (array_key_exists('country', $data) && trim((string)$data['country']) !== '') {
            $fields['country'] = trim($data['country']);
        }
        if (array_key_exists('region', $data) && trim((string)$data['region']) !== '') {
            $fields['region'] = self::choice($data['region'], ['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other'], $lead['region']);
        }
        if (array_key_exists('lead_type', $data)) {
            $fields['lead_type'] = self::choice($data['lead_type'], ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'], $lead['lead_type']);
        }
        if (array_key_exists('status', $data)) {
            $fields['lead_status'] = self::choice($data['status'], ['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'], $lead['lead_status']);
        }
        if (array_key_exists('priority', $data)) {
            $fields['priority'] = self::choice($data['priority'], ['Low','Medium','High','Urgent'], $lead['priority']);
        }
        if (array_key_exists('lead_source', $data)) {
            $fields['lead_source'] = self::choice($data['lead_source'], ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'], $lead['lead_source']);
        }
        if (array_key_exists('facility_type', $data)) {
            $fields['facility_type'] = trim((string)$data['facility_type']) === '' ? null
                : self::choice($data['facility_type'], ['Breeding','Racing','Training','Multi-Purpose','Other'], null);
        }
        if (array_key_exists('number_of_horses', $data)) {
            $fields['number_of_horses'] = ($data['number_of_horses'] === '' || $data['number_of_horses'] === null) ? null : (int)$data['number_of_horses'];
        }
        if (array_key_exists('assigned_to', $data)) {
            $assignedVgoldId = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
            $fields['assigned_to'] = $assignedVgoldId ? self::crmUserIdForWorkspaceMember($assignedVgoldId, true) : null;
        }

        if (empty($fields)) jsonError('No changes provided');
        $finalCompany = array_key_exists('company_name', $fields) ? $fields['company_name'] : $lead['company_name'];
        $finalContact = array_key_exists('contact_person', $fields) ? $fields['contact_person'] : $lead['contact_person'];
        if (($finalCompany === null || $finalCompany === '') && ($finalContact === null || $finalContact === '')) {
            jsonError('A lead or company name is required');
        }

        DB::update('crm_leads', $fields, 'lead_id = ?', [$id]);
        jsonResponse(['ok' => true, 'id' => $id]);
    }

    private static function assertLeadAccess($lead) {
        if (self::isCrmManager()) return;
        $crmId = Auth::crmUserId();
        if (!$crmId || ((int)$lead['assigned_to'] !== (int)$crmId && (int)$lead['created_by'] !== (int)$crmId)) {
            jsonError('You do not have access to this lead', 403);
        }
    }

    private static function formatInteraction($row) {
        return [
            'id' => (int)$row['interaction_id'],
            'lead_id' => (int)$row['lead_id'],
            'lead_name' => $row['contact_person'] ?: $row['company_name'] ?: ('Lead #' . $row['lead_id']),
            'company_name' => $row['company_name'],
            'type' => $row['interaction_type'],
            'occurred_at' => $row['interaction_date'],
            'subject' => $row['subject'],
            'notes' => $row['notes'],
            'outcome' => $row['outcome'],
            'next_action' => $row['next_action'],
            'next_action_date' => $row['next_action_date'],
            'workflow_task_id' => $row['workflow_task_id'] ? (int)$row['workflow_task_id'] : null,
            'follow_up_completed' => $row['workflow_task_status'] === 'completed',
            'user_name' => $row['user_name'] ?: 'CRM user',
        ];
    }

    private static function formatLeadDetail($row) {
        return [
            'id' => (int)$row['lead_id'],
            'company_name' => $row['company_name'],
            'contact_person' => $row['contact_person'],
            'display_name' => $row['contact_person'] ?: $row['company_name'] ?: ('Lead #' . $row['lead_id']),
            'title_position' => $row['title_position'] ?? null,
            'email' => $row['email'],
            'phone' => $row['phone'],
            'mobile' => $row['mobile'] ?? null,
            'website' => $row['website'] ?? null,
            'country' => $row['country'],
            'city' => $row['city'] ?? null,
            'region' => $row['region'],
            'address' => $row['address'] ?? null,
            'lead_type' => $row['lead_type'],
            'status' => $row['lead_status'],
            'priority' => $row['priority'],
            'lead_source' => $row['lead_source'] ?? null,
            'facility_type' => $row['facility_type'] ?? null,
            'number_of_horses' => isset($row['number_of_horses']) && $row['number_of_horses'] !== null ? (int)$row['number_of_horses'] : null,
            'specialization' => $row['specialization'] ?? null,
            'horse_breed' => $row['horse_breed'] ?? null,
            'horse_sex' => $row['horse_sex'] ?? null,
            'notes' => $row['notes'],
            'facebook_url' => $row['facebook_url'] ?? null,
            'instagram_url' => $row['instagram_url'] ?? null,
            'linkedin_url' => $row['linkedin_url'] ?? null,
            'twitter_url' => $row['twitter_url'] ?? null,
            'youtube_url' => $row['youtube_url'] ?? null,
            'assigned_to' => $row['assigned_vgold_id'] ? (int)$row['assigned_vgold_id'] : null,
            'assigned_name' => $row['assigned_vgold_name'] ?: $row['assigned_crm_name'] ?: null,
            'created_name' => $row['created_crm_name'] ?: null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'],
        ];
    }

    public static function createInteraction() {
        Authz::requireModuleAccess('crm.interactions');
        $data = input();
        requireFields(['lead_id', 'type'], $data);
        $lead = DB::fetch("SELECT lead_id FROM crm_leads WHERE lead_id = ?", [(int)$data['lead_id']]);
        if (!$lead) jsonError('Lead not found', 404);

        $type = self::choice($data['type'], ['Call','Email','Meeting','Demo','Follow-up','Note','WhatsApp','SMS'], 'Note');
        $nextAction = self::nullable($data['next_action'] ?? null);
        $nextDate = self::nullable($data['next_action_date'] ?? null);
        if ($type === 'Follow-up' && !$nextAction) $nextAction = trim($data['subject'] ?? '') ?: 'Follow up with lead';
        if ($nextAction && !$nextDate) $nextDate = date('Y-m-d');

        DB::conn()->beginTransaction();
        try {
            $id = DB::insert('crm_interactions', [
                'lead_id' => (int)$data['lead_id'],
                'user_id' => self::crmUserIdForWorkspaceMember(Auth::userId(), true),
                'interaction_type' => $type,
                'interaction_date' => self::dateTime($data['occurred_at'] ?? null),
                'subject' => self::nullable($data['subject'] ?? null),
                'notes' => self::nullable($data['notes'] ?? null),
                'outcome' => self::choice($data['outcome'] ?? null, ['Positive','Neutral','Negative','No Response'], null),
                'next_action' => $nextAction,
                'next_action_date' => $nextDate,
            ]);
            $taskId = $nextAction ? CRMTaskBridge::syncInteraction((int)$id) : null;
            DB::conn()->commit();
        } catch (\Throwable $e) {
            DB::conn()->rollBack();
            throw $e;
        }
        jsonResponse(['ok' => true, 'id' => (int)$id, 'workflow_task_id' => $taskId], 201);
    }

    private static function formatLead($row) {
        return [
            'id' => (int)$row['lead_id'],
            'company_name' => $row['company_name'],
            'contact_person' => $row['contact_person'],
            'display_name' => $row['contact_person'] ?: $row['company_name'] ?: ('Lead #' . $row['lead_id']),
            'email' => $row['email'],
            'phone' => $row['phone'],
            'country' => $row['country'],
            'region' => $row['region'],
            'lead_type' => $row['lead_type'],
            'status' => $row['lead_status'],
            'priority' => $row['priority'],
            'notes' => $row['notes'],
            'assigned_to' => $row['assigned_vgold_id'] ? (int)$row['assigned_vgold_id'] : null,
            'assigned_name' => $row['assigned_name'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function crmUserIdForWorkspaceMember($userId, $required = false) {
        $row = DB::fetch(
            "SELECT u.id, u.name, u.email, u.role, u.crm_user_id FROM users u JOIN workspace_members wm ON wm.user_id = u.id
             WHERE u.id = ? AND wm.workspace_id = ? LIMIT 1",
            [$userId, Auth::workspaceId()]
        );
        if (!$row) jsonError('Assignee is not a workspace member');
        if ($required && empty($row['crm_user_id'])) {
            $legacy = DB::fetch("SELECT user_id, role, username FROM crm_users WHERE LOWER(email) = LOWER(?) LIMIT 1", [$row['email']]);
            if (!$legacy) {
                $base = preg_replace('/[^a-z0-9._-]/i', '', explode('@', $row['email'])[0] ?? '') ?: ('vgold' . $row['id']);
                $username = $base;
                $suffix = 0;
                while (DB::fetch("SELECT user_id FROM crm_users WHERE username = ? LIMIT 1", [$username])) {
                    $suffix++;
                    $username = $base . '-' . $row['id'] . ($suffix > 1 ? '-' . $suffix : '');
                }
                $crmRole = $row['role'] === 'admin' ? 'Admin' : 'Sales Rep';
                $crmUserId = (int)DB::insert('crm_users', [
                    'username' => substr($username, 0, 50),
                    'email' => substr($row['email'], 0, 100),
                    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                    'full_name' => substr($row['name'], 0, 100),
                    'role' => $crmRole,
                    'status' => 'Active',
                ]);
                $legacy = ['user_id' => $crmUserId, 'role' => $crmRole, 'username' => $username];
            }
            DB::update('users', [
                'crm_user_id' => (int)$legacy['user_id'],
                'crm_role' => $legacy['role'],
                'crm_username' => $legacy['username'],
            ], 'id = ?', [(int)$row['id']]);
            $row['crm_user_id'] = (int)$legacy['user_id'];
        }
        return !empty($row['crm_user_id']) ? (int)$row['crm_user_id'] : null;
    }

    private static function nullable($value) {
        $value = is_string($value) ? trim($value) : $value;
        return $value === '' || $value === null ? null : $value;
    }

    private static function choice($value, $allowed, $default) {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function dateTime($value) {
        if (!$value || strtotime($value) === false) return date('Y-m-d H:i:s');
        return date('Y-m-d H:i:s', strtotime($value));
    }
}
