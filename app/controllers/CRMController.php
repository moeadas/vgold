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
        $hasLeads = in_array('crm.leads', $modules, true) || in_array('crm.dashboard', $modules, true);
        $hasInter = in_array('crm.interactions', $modules, true) || in_array('crm.dashboard', $modules, true);
        $stats = ['leads' => null, 'follow_ups' => null, 'overdue' => null, 'won' => null, 'contacted_today' => null];
        if ($hasLeads) {
            $row = DB::fetch(
                "SELECT COUNT(*) total, SUM(CASE WHEN lead_status = 'Won' THEN 1 ELSE 0 END) won FROM crm_leads"
            );
            $stats['leads'] = (int)($row['total'] ?? 0);
            $stats['won'] = (int)($row['won'] ?? 0);
        }
        if ($hasInter) {
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
            $ct = DB::fetch("SELECT COUNT(DISTINCT lead_id) c FROM crm_interactions WHERE DATE(interaction_date) = CURDATE()");
            $stats['contacted_today'] = (int)($ct['c'] ?? 0);
        }

        // Recent Leads feed (most recently touched)
        $recentLeads = [];
        if ($hasLeads) {
            $rows = DB::fetchAll(
                "SELECT l.*, u.id AS assigned_vgold_id, u.name AS assigned_name,
                        (SELECT MAX(interaction_date) FROM crm_interactions i WHERE i.lead_id = l.lead_id) AS last_interaction
                 FROM crm_leads l LEFT JOIN users u ON u.crm_user_id = l.assigned_to
                 ORDER BY l.updated_at DESC LIMIT 8"
            );
            foreach ($rows as $r) {
                $lead = self::formatLead($r);
                $lead['last_interaction'] = $r['last_interaction'];
                $recentLeads[] = $lead;
            }
        }

        // Recent Activity feed (latest interactions)
        $recentActivity = [];
        if ($hasInter) {
            $rows = DB::fetchAll(
                "SELECT i.*, l.company_name, l.contact_person, u.name AS user_name,
                        NULL AS workflow_task_id, NULL AS workflow_task_status
                 FROM crm_interactions i
                 JOIN crm_leads l ON l.lead_id = i.lead_id
                 LEFT JOIN users u ON u.crm_user_id = i.user_id
                 ORDER BY i.interaction_date DESC, i.interaction_id DESC LIMIT 12"
            );
            $recentActivity = array_map([self::class, 'formatInteraction'], $rows);
        }

        // Email-marketing KPIs (guarded — tables may not exist on every install)
        $email = null;
        if (in_array('crm.email', $modules, true)) {
            $email = ['campaigns' => 0, 'sent' => 0, 'lists' => 0, 'subscribers' => 0];
            try { $email['campaigns']   = (int)(DB::fetch("SELECT COUNT(*) c FROM crm_email_campaigns")['c'] ?? 0); } catch (\Throwable $e) {}
            try { $email['sent']        = (int)(DB::fetch("SELECT COALESCE(SUM(sent_count),0) c FROM crm_email_campaigns")['c'] ?? 0); } catch (\Throwable $e) {}
            try { $email['lists']       = (int)(DB::fetch("SELECT COUNT(*) c FROM crm_email_lists")['c'] ?? 0); } catch (\Throwable $e) {}
            try { $email['subscribers'] = (int)(DB::fetch("SELECT COUNT(*) c FROM crm_email_subscribers")['c'] ?? 0); } catch (\Throwable $e) {}
        }

        jsonResponse([
            'stats' => $stats,
            'modules' => $modules,
            'recent_leads' => $recentLeads,
            'recent_activity' => $recentActivity,
            'email' => $email,
        ]);
    }

    // Shared WHERE builder for the leads list + CSV export (same scoping/filters).
    private static function leadFilters() {
        $where = ['1=1'];
        $params = [];
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $where[] = '(l.company_name LIKE ? OR l.contact_person LIKE ? OR l.email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        foreach ([['status', 'lead_status'], ['country', 'country'], ['priority', 'priority'], ['lead_type', 'lead_type'], ['lead_source', 'lead_source']] as $pair) {
            $v = trim($_GET[$pair[0]] ?? '');
            if ($v !== '') { $where[] = "l.$pair[1] = ?"; $params[] = $v; }
        }
        // Owner filter: client sends a VGold user id → map to its crm_user_id.
        if (!empty($_GET['owner'])) {
            $where[] = 'l.assigned_to = (SELECT crm_user_id FROM users WHERE id = ? LIMIT 1)';
            $params[] = (int)$_GET['owner'];
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
        return [$where, $params];
    }

    public static function leads() {
        Authz::requireModuleAccess('crm.leads');
        [$where, $params] = self::leadFilters();
        $whereSql = implode(' AND ', $where);

        $total = (int)(DB::fetch("SELECT COUNT(*) c FROM crm_leads l WHERE $whereSql", $params)['c'] ?? 0);

        $sortMap = [
            'updated_at' => 'l.updated_at', 'created_at' => 'l.created_at',
            'company_name' => 'l.company_name', 'contact_person' => 'l.contact_person',
            'country' => 'l.country', 'lead_status' => 'l.lead_status',
            'priority' => "FIELD(l.priority,'Urgent','High','Medium','Low')",
            'lead_source' => 'l.lead_source', 'lead_type' => 'l.lead_type', 'assigned_name' => 'assigned_name',
        ];
        $sortBy = $_GET['sort_by'] ?? '';
        $orderCol = $sortMap[$sortBy] ?? "FIELD(l.priority,'Urgent','High','Medium','Low'), l.updated_at";
        $dir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $perPage = min(max((int)($_GET['per_page'] ?? 50), 1), 500);
        $page = max((int)($_GET['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $rows = DB::fetchAll(
            "SELECT l.*, u.id AS assigned_vgold_id, u.name AS assigned_name
             FROM crm_leads l LEFT JOIN users u ON u.crm_user_id = l.assigned_to
             WHERE $whereSql
             ORDER BY $orderCol $dir LIMIT $perPage OFFSET $offset",
            $params
        );
        jsonResponse([
            'leads' => array_map([self::class, 'formatLead'], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int)ceil(($total ?: 0) / $perPage),
        ]);
    }

    // Stream a CSV of all leads matching the current filters (not just one page).
    public static function exportLeads() {
        Authz::requireModuleAccess('crm.leads');
        [$where, $params] = self::leadFilters();
        $rows = DB::fetchAll(
            "SELECT l.*, u.name AS assigned_name
             FROM crm_leads l LEFT JOIN users u ON u.crm_user_id = l.assigned_to
             WHERE " . implode(' AND ', $where) . "
             ORDER BY l.updated_at DESC LIMIT 5000",
            $params
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads-' . date('Ymd-His') . '.csv"');
        $cols = ['lead_id','company_name','contact_person','email','phone','country','region','lead_type','lead_status','priority','lead_source','assigned_name','created_at','updated_at'];
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) $line[] = $r[$c] ?? '';
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    public static function bulkAssign() {
        Authz::requireModuleAccess('crm.leads');
        if (!self::isCrmManager()) jsonError('Only managers can bulk-assign leads', 403);
        $data = input();
        $ids = array_values(array_filter(array_map('intval', $data['lead_ids'] ?? [])));
        if (!$ids) jsonError('No leads selected');
        $assignedVgoldId = !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null;
        $crmId = $assignedVgoldId ? self::crmUserIdForWorkspaceMember($assignedVgoldId, true) : null;
        $in = implode(',', array_fill(0, count($ids), '?'));
        DB::update('crm_leads', ['assigned_to' => $crmId], "lead_id IN ($in)", $ids);
        jsonResponse(['ok' => true, 'updated' => count($ids)]);
    }

    public static function bulkDelete() {
        Authz::requireModuleAccess('crm.leads');
        if (!self::isCrmManager()) jsonError('Only managers can delete leads', 403);
        $data = input();
        $ids = array_values(array_filter(array_map('intval', $data['lead_ids'] ?? [])));
        if (!$ids) jsonError('No leads selected');
        $in = implode(',', array_fill(0, count($ids), '?'));
        DB::query("DELETE FROM crm_interactions WHERE lead_id IN ($in)", $ids);
        DB::query("DELETE FROM crm_leads WHERE lead_id IN ($in)", $ids);
        jsonResponse(['ok' => true, 'deleted' => count($ids)]);
    }

    // Bulk import already-mapped rows from the SPA CSV wizard.
    public static function importLeads() {
        Authz::requireModuleAccess('crm.leads');
        $data = input();
        $leads = $data['leads'] ?? [];
        if (!is_array($leads) || !count($leads)) jsonError('No rows to import');
        $dedupe = !empty($data['dedupe']);
        $creatorCrmId = self::crmUserIdForWorkspaceMember(Auth::userId(), true);
        $inserted = 0; $skipped = 0;
        foreach ($leads as $row) {
            if (!is_array($row)) { $skipped++; continue; }
            $company = trim((string)($row['company_name'] ?? ''));
            $contact = trim((string)($row['contact_person'] ?? ''));
            if ($company === '' && $contact === '') { $skipped++; continue; }
            $email = self::nullable($row['email'] ?? null);
            if ($dedupe) {
                $dup = null;
                if ($email) $dup = DB::fetch("SELECT lead_id FROM crm_leads WHERE email = ? LIMIT 1", [$email]);
                if (!$dup && $company !== '') $dup = DB::fetch("SELECT lead_id FROM crm_leads WHERE company_name = ? AND IFNULL(contact_person,'') = ? LIMIT 1", [$company, $contact]);
                if ($dup) { $skipped++; continue; }
            }
            DB::insert('crm_leads', [
                'company_name' => $company ?: null,
                'contact_person' => $contact ?: null,
                'email' => $email,
                'phone' => self::nullable($row['phone'] ?? null),
                'country' => self::nullable($row['country'] ?? null) ?: 'Unknown',
                'region' => self::choice($row['region'] ?? 'Other', ['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other'], 'Other'),
                'lead_type' => self::choice($row['lead_type'] ?? 'Other', ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'], 'Other'),
                'lead_status' => self::choice($row['lead_status'] ?? 'New Lead', ['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold','Not Interested'], 'New Lead'),
                'priority' => self::choice($row['priority'] ?? 'Medium', ['Low','Medium','High','Urgent'], 'Medium'),
                'lead_source' => self::choice($row['lead_source'] ?? 'Import', ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'], 'Import'),
                'notes' => self::nullable($row['notes'] ?? null),
                'assigned_to' => $creatorCrmId,
                'created_by' => $creatorCrmId,
            ]);
            $inserted++;
        }
        jsonResponse(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
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
        // Scope clause (shared by list, total, and type-counts).
        $scope = ['1=1'];
        $scopeParams = [];
        if (!self::isCrmManager()) {
            $crmId = Auth::crmUserId();
            if ($crmId) {
                $scope[] = '(l.assigned_to = ? OR l.created_by = ? OR i.user_id = ?)';
                array_push($scopeParams, $crmId, $crmId, $crmId);
            } else {
                $scope[] = '1=0';
            }
        }
        if (!empty($_GET['lead_id'])) { $scope[] = 'i.lead_id = ?'; $scopeParams[] = (int)$_GET['lead_id']; }
        $scopeSql = implode(' AND ', $scope);

        // Type counts (respect scope + lead filter, but NOT the active type filter).
        $typeCounts = [];
        foreach (DB::fetchAll(
            "SELECT i.interaction_type t, COUNT(*) c
             FROM crm_interactions i JOIN crm_leads l ON l.lead_id = i.lead_id
             WHERE $scopeSql GROUP BY i.interaction_type", $scopeParams) as $r) {
            $typeCounts[$r['t']] = (int)$r['c'];
        }

        // Add the type + follow-up filters on top of the scope for the actual list.
        $where = $scope;
        $params = $scopeParams;
        $type = trim($_GET['type'] ?? '');
        if ($type !== '') { $where[] = 'i.interaction_type = ?'; $params[] = $type; }
        if (!empty($_GET['follow_up'])) { $where[] = "i.next_action IS NOT NULL AND TRIM(i.next_action) <> ''"; }
        $whereSql = implode(' AND ', $where);

        $total = (int)(DB::fetch(
            "SELECT COUNT(*) c FROM crm_interactions i JOIN crm_leads l ON l.lead_id = i.lead_id WHERE $whereSql",
            $params
        )['c'] ?? 0);
        $perPage = min(max((int)($_GET['per_page'] ?? 30), 1), 200);
        $page = max((int)($_GET['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $rows = DB::fetchAll(
            "SELECT i.*, l.company_name, l.contact_person, u.name AS user_name,
                    ctl.task_id AS workflow_task_id, t.status AS workflow_task_status
             FROM crm_interactions i
             JOIN crm_leads l ON l.lead_id = i.lead_id
             LEFT JOIN users u ON u.crm_user_id = i.user_id
             LEFT JOIN crm_task_links ctl ON ctl.crm_interaction_id = i.interaction_id
             LEFT JOIN tasks t ON t.id = ctl.task_id
             WHERE $whereSql
             ORDER BY i.interaction_date DESC, i.interaction_id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        jsonResponse([
            'interactions' => array_map([self::class, 'formatInteraction'], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int)ceil(($total ?: 0) / $perPage),
            'type_counts' => $typeCounts,
        ]);
    }

    public static function deleteInteraction($id) {
        Authz::requireModuleAccess('crm.interactions');
        if (!self::isCrmManager()) jsonError('Only managers can delete interactions', 403);
        $id = (int)$id;
        $row = DB::fetch("SELECT interaction_id FROM crm_interactions WHERE interaction_id = ?", [$id]);
        if (!$row) jsonError('Interaction not found', 404);
        DB::query("DELETE FROM crm_task_links WHERE crm_interaction_id = ?", [$id]);
        DB::query("DELETE FROM crm_interactions WHERE interaction_id = ?", [$id]);
        jsonResponse(['ok' => true]);
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
            'city' => $row['city'] ?? null,
            'country' => $row['country'],
            'region' => $row['region'],
            'lead_type' => $row['lead_type'],
            'status' => $row['lead_status'],
            'priority' => $row['priority'],
            'lead_source' => $row['lead_source'] ?? null,
            'notes' => $row['notes'],
            'assigned_to' => $row['assigned_vgold_id'] ? (int)$row['assigned_vgold_id'] : null,
            'assigned_name' => $row['assigned_name'],
            'created_at' => $row['created_at'] ?? null,
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
