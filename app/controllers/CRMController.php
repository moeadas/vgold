<?php

class CRMController {
    private static function requireCrm() {
        if (!Authz::hasAnyCrmAccess()) jsonError('You do not have access to CRM', 403);
    }

    // A VGo admin, or a real CRM Admin/Sales Manager, sees all records.
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
        // Explicit id set — used by the "with notifications" filter on the Leads
        // screen, which already knows the lead ids from the notification feed.
        if (!empty($_GET['ids'])) {
            $ids = array_slice(array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string)$_GET['ids'])),
                fn($n) => $n > 0
            ))), 0, 500);
            $where[] = $ids ? ('l.lead_id IN (' . implode(',', $ids) . ')') : '1=0';
        }
        // Owner filter: client sends a VGo user id → map to its crm_user_id.
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

    /** Does this table/column pair exist? Cached per request. */
    private static function tableHasColumn($table, $column) {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $r = DB::fetch(
                "SELECT COUNT(*) c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            return $cache[$key] = ((int)($r['c'] ?? 0) > 0);
        } catch (\Throwable $e) {
            return $cache[$key] = false;
        }
    }

    /**
     * "Last action taken" on a lead: the most recent of the record itself, its
     * logged interactions, its WhatsApp traffic and its calls.
     *
     * Composed from whatever those tables actually offer, so a missing table or
     * a renamed column degrades to a smaller GREATEST() instead of 500-ing the
     * whole Leads page.
     */
    private static function leadActivityExpr() {
        $parts = ["COALESCE(l.updated_at, l.created_at, '1000-01-01 00:00:00')"];
        foreach ([
            ['crm_interactions', 'interaction_date'],
            ['crm_whatsapp_messages', 'created_at'],
            ['crm_voip_calls', 'created_at'],
        ] as [$table, $col]) {
            if (self::tableHasColumn($table, $col) && self::tableHasColumn($table, 'lead_id')) {
                $parts[] = "COALESCE((SELECT MAX(a.`$col`) FROM `$table` a WHERE a.lead_id = l.lead_id), '1000-01-01 00:00:00')";
            }
        }
        return count($parts) > 1 ? 'GREATEST(' . implode(', ', $parts) . ')' : $parts[0];
    }

    public static function leads() {
        Authz::requireModuleAccess('crm.leads');
        [$where, $params] = self::leadFilters();
        $whereSql = implode(' AND ', $where);

        $total = (int)(DB::fetch("SELECT COUNT(*) c FROM crm_leads l WHERE $whereSql", $params)['c'] ?? 0);

        $activity = self::leadActivityExpr();
        $sortMap = [
            'last_activity' => 'last_activity_at',
            'updated_at' => 'l.updated_at', 'created_at' => 'l.created_at',
            'company_name' => 'l.company_name', 'contact_person' => 'l.contact_person',
            'country' => 'l.country', 'lead_status' => 'l.lead_status',
            // Ranked low-to-high so the default DESC click puts Urgent on top,
            // which is what "sort by priority" is asking for.
            'priority' => "FIELD(l.priority,'Low','Medium','High','Urgent')",
            'lead_source' => 'l.lead_source', 'lead_type' => 'l.lead_type', 'assigned_name' => 'assigned_name',
        ];
        $sortBy = $_GET['sort_by'] ?? '';
        // Default: whatever was touched most recently, because that is the list
        // you actually work from. Priority is still one click away.
        $orderCol = $sortMap[$sortBy] ?? 'last_activity_at';
        $dir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $perPage = min(max((int)($_GET['per_page'] ?? 50), 1), 500);
        $page = max((int)($_GET['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        $rows = DB::fetchAll(
            "SELECT l.*, u.id AS assigned_vgold_id, u.name AS assigned_name,
                    $activity AS last_activity_at
             FROM crm_leads l LEFT JOIN users u ON u.crm_user_id = l.assigned_to
             WHERE $whereSql
             ORDER BY $orderCol $dir, l.lead_id DESC LIMIT $perPage OFFSET $offset",
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

    /**
     * Country → business region. Mirrors regionForCountry() in
     * public/assets/js/countries.js — keep the two in sync.
     *
     * Region used to be a hand-typed free-text column, which is why the
     * "By Region" report showed 2,139 leads as "North America" while their
     * countries were spread across four continents. Deriving it from the
     * country makes the report trustworthy.
     */
    private const REGION_BY_COUNTRY = [
        'north america' => ['United States','Canada','Bermuda'],
        'latin america' => ['Mexico','Guatemala','Belize','El Salvador','Honduras','Nicaragua','Costa Rica','Panama','Colombia','Venezuela','Ecuador','Peru','Bolivia','Brazil','Paraguay','Uruguay','Argentina','Chile','Guyana','Suriname','Cuba','Dominican Republic','Haiti','Jamaica','Trinidad and Tobago','Bahamas','Barbados','Puerto Rico','Cayman Islands','Antigua and Barbuda','Dominica','Grenada'],
        'middle east'   => ['Saudi Arabia','United Arab Emirates','Qatar','Kuwait','Bahrain','Oman','Yemen','Jordan','Lebanon','Syria','Iraq','Iran','Israel','Palestine','Egypt','Libya'],
        'africa'        => ['Algeria','Morocco','Tunisia','Sudan','South Sudan','Ethiopia','Eritrea','Djibouti','Somalia','Kenya','Uganda','Tanzania','Rwanda','Burundi','Nigeria','Ghana','Senegal','Mali','Burkina Faso','Niger','Chad','Cameroon','Congo','Congo (DRC)','Gabon','Equatorial Guinea','Central African Republic','Angola','Zambia','Zimbabwe','Malawi','Mozambique','Botswana','Namibia','South Africa','Lesotho','Eswatini','Madagascar','Mauritius','Seychelles','Comoros','Cape Verde','Gambia','Guinea','Guinea-Bissau','Sierra Leone','Liberia','Cote d\'Ivoire','Togo','Benin','Mauritania'],
        'asia pacific'  => ['Australia','New Zealand','Fiji','Papua New Guinea','China','Japan','South Korea','Taiwan','Hong Kong','Macau','Mongolia','India','Pakistan','Bangladesh','Sri Lanka','Nepal','Bhutan','Maldives','Afghanistan','Thailand','Vietnam','Laos','Cambodia','Myanmar','Malaysia','Singapore','Indonesia','Philippines','Brunei','Kazakhstan','Uzbekistan','Turkmenistan','Kyrgyzstan','Tajikistan'],
        'europe'        => ['United Kingdom','Ireland','France','Germany','Italy','Spain','Portugal','Netherlands','Belgium','Luxembourg','Switzerland','Austria','Denmark','Sweden','Norway','Finland','Iceland','Poland','Czechia','Slovakia','Hungary','Romania','Bulgaria','Greece','Croatia','Slovenia','Serbia','Bosnia and Herzegovina','Montenegro','North Macedonia','Albania','Estonia','Latvia','Lithuania','Belarus','Ukraine','Moldova','Russia','Turkey','Cyprus','Malta','Monaco','Andorra','Liechtenstein','Gibraltar','Georgia','Armenia','Azerbaijan'],
    ];

    /** Common free-text spellings seen in the imported data. */
    private const COUNTRY_ALIASES = [
        'usa' => 'United States', 'u.s.a.' => 'United States', 'u.s.' => 'United States',
        'united states of america' => 'United States', 'america' => 'United States',
        'uk' => 'United Kingdom', 'u.k.' => 'United Kingdom', 'great britain' => 'United Kingdom',
        'england' => 'United Kingdom', 'scotland' => 'United Kingdom', 'wales' => 'United Kingdom',
        'northern ireland' => 'United Kingdom', 'uae' => 'United Arab Emirates',
        'u.a.e.' => 'United Arab Emirates', 'emirates' => 'United Arab Emirates',
        'ksa' => 'Saudi Arabia', 'holland' => 'Netherlands', 'the netherlands' => 'Netherlands',
        'korea' => 'South Korea', 'czech republic' => 'Czechia', 'ivory coast' => 'Cote d\'Ivoire',
        'swaziland' => 'Eswatini', 'burma' => 'Myanmar', 'macedonia' => 'North Macedonia',
        'russian federation' => 'Russia', 'republic of ireland' => 'Ireland',
    ];

    /** Canonical country name for free text, or '' when unrecognised. */
    public static function canonicalCountry($value) {
        $v = trim((string)$value);
        if ($v === '') return '';
        $lc = mb_strtolower($v);
        if (isset(self::COUNTRY_ALIASES[$lc])) return self::COUNTRY_ALIASES[$lc];
        foreach (self::REGION_BY_COUNTRY as $countries) {
            foreach ($countries as $c) {
                if (mb_strtolower($c) === $lc) return $c;
            }
        }
        return $v; // unknown — keep what the user typed rather than blanking it
    }

    /** Business region for a country name, or '' when unrecognised. */
    public static function regionForCountry($country) {
        $c = mb_strtolower(self::canonicalCountry($country));
        if ($c === '') return '';
        foreach (self::REGION_BY_COUNTRY as $region => $countries) {
            foreach ($countries as $name) {
                if (mb_strtolower($name) === $c) {
                    return ucwords($region);
                }
            }
        }
        return '';
    }

// ══════════════════════════════════════════════════════════════════════
    //  AUTOMATION BRIDGE
    //
    //  The rules engine lives in the legacy CRM (crm/includes/automation-engine.php)
    //  and was only ever called from crm/api/leads.php. The SPA writes through
    //  THIS controller, so no rule had ever fired from the live UI. Every lead
    //  and interaction write now goes through fireAutomation().
    //
    //  Deliberately swallow every failure: an automation must never be able to
    //  stop a lead being saved.
    // ══════════════════════════════════════════════════════════════════════
    public static function fireAutomation($trigger, array $context = []) {
        try {
            $root = dirname(__DIR__, 2);
            require_once $root . '/crm/includes/vgold_bridge.php';
            require_once $root . '/crm/includes/automation-engine.php';
            if (!function_exists('fireAutomationTrigger')) return;
            if (!isset($context['current_user'])) {
                $crmId = Auth::crmUserId();
                if ($crmId) $context['current_user'] = ['user_id' => $crmId];
            }
            fireAutomationTrigger($trigger, $context);
        } catch (\Throwable $e) {
            error_log('Automation bridge failed for ' . $trigger . ': ' . $e->getMessage());
        }
    }

    /**
     * Lead picker options. With ?q= this is a server-side search across name,
     * company, email and phone — the list is ~2,400 rows, far too long to ship
     * to the browser and scroll through.
     */
    public static function leadOptions() {
        if (!Authz::hasModuleAccess('crm.leads') && !Authz::hasModuleAccess('crm.interactions')) {
            jsonError('You do not have access to CRM leads', 403);
        }
        $q = trim((string)($_GET['q'] ?? ''));
        $params = [];
        $where = '1=1';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where = '(contact_person LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ? OR mobile LIKE ?)';
            $params = [$like, $like, $like, $like, $like];
        }
        $rows = DB::fetchAll(
            "SELECT lead_id, company_name, contact_person, lead_status, country FROM crm_leads
             WHERE $where
             ORDER BY COALESCE(NULLIF(contact_person, ''), company_name) ASC LIMIT 50",
            $params
        );
        jsonResponse(['leads' => array_map(fn($row) => [
            'id' => (int)$row['lead_id'],
            'name' => $row['contact_person'] ?: $row['company_name'] ?: ('Lead #' . $row['lead_id']),
            'company' => $row['company_name'],
            'country' => $row['country'],
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
        // Region is always derived from the country so the "By Region" report
        // stays consistent, whatever the client sent.
        $country = self::canonicalCountry($data['country'] ?? '');
        $region = self::regionForCountry($country) ?: trim((string)($data['region'] ?? ''));
        $id = DB::insert('crm_leads', [
            'company_name' => $company ?: null,
            'contact_person' => $contact ?: null,
            'email' => self::nullable($data['email'] ?? null),
            'phone' => self::nullable($data['phone'] ?? null),
            'mobile' => self::nullable($data['mobile'] ?? null),
            'city' => self::nullable($data['city'] ?? null),
            'country' => $country ?: null,
            'region' => $region ?: null,
            'lead_type' => self::choice($data['lead_type'] ?? 'Stable', ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'], 'Stable'),
            'lead_status' => self::choice($data['status'] ?? 'New Lead', ['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold','Not Interested'], 'New Lead'),
            'priority' => self::choice($data['priority'] ?? 'Medium', ['Low','Medium','High','Urgent'], 'Medium'),
            'notes' => self::nullable($data['notes'] ?? null),
            'assigned_to' => $assignedCrmId,
            'created_by' => $creatorCrmId,
        ]);
        $newLead = DB::fetch("SELECT * FROM crm_leads WHERE lead_id = ?", [(int)$id]);
        $ctx = ['lead_id' => (int)$id, 'lead' => $newLead];
        self::fireAutomation('lead_created', $ctx);
        if (!empty($newLead['lead_source'])) self::fireAutomation('lead_source_match', $ctx);
        if (!empty($newLead['assigned_to'])) {
            self::fireAutomation('lead_assigned', $ctx + ['new_assigned' => (int)$newLead['assigned_to']]);
        }

        jsonResponse(['ok' => true, 'id' => (int)$id], 201);
    }

// ══════════════════════════════════════════════════════════════════════
    //  CUSTOMERS — converted leads
    //
    //  A customer IS a lead whose status reached a won/customer state. Keeping
    //  one record means the whole history (interactions, WhatsApp thread, calls,
    //  emails) stays attached. Money is NOT duplicated here: purchased items,
    //  prices and total value are read live from the Accounting tables through
    //  acc_contacts.crm_lead_id.
    // ══════════════════════════════════════════════════════════════════════

    /** Lead statuses that mean "this is a customer now". */
    public const CUSTOMER_STATUSES = ['Won', 'Customer'];

    private static function customerStatusSql($alias = 'l') {
        $in = implode(',', array_fill(0, count(self::CUSTOMER_STATUSES), '?'));
        return ["$alias.lead_status IN ($in)", self::CUSTOMER_STATUSES];
    }

    public static function customers() {
        Authz::requireModuleAccess('crm.leads');
        [$statusSql, $statusParams] = self::customerStatusSql();
        $where = [$statusSql];
        $params = $statusParams;

        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(l.contact_person LIKE ? OR l.company_name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.mobile LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        // Reps see only their own book unless they manage the CRM.
        if (!self::isCrmManager()) {
            $crmId = Auth::crmUserId();
            if ($crmId) { $where[] = '(l.assigned_to = ? OR l.created_by = ?)'; array_push($params, $crmId, $crmId); }
        }
        $whereSql = implode(' AND ', $where);

        $rows = DB::fetchAll(
            "SELECT l.*, u.id AS assigned_vgold_id, u.name AS assigned_name
             FROM crm_leads l LEFT JOIN users u ON u.crm_user_id = l.assigned_to
             WHERE $whereSql
             ORDER BY l.updated_at DESC
             LIMIT 500",
            $params
        );

        $out = array_map(function ($row) {
            $lead = self::formatLead($row);
            $lead['finance'] = self::customerFinance((int)$row['lead_id']);
            return $lead;
        }, $rows);

        $totals = ['customers' => count($out), 'lifetime_value' => 0.0, 'open_balance' => 0.0, 'linked' => 0];
        foreach ($out as $c) {
            $totals['lifetime_value'] += (float)$c['finance']['lifetime_value'];
            $totals['open_balance'] += (float)$c['finance']['open_balance'];
            if ($c['finance']['contact_id']) $totals['linked']++;
        }

        jsonResponse(['customers' => $out, 'totals' => $totals]);
    }

    /**
     * Live financial summary for a lead, read from Accounting. Returns zeros when
     * the lead has no linked acc_contacts row yet — never invents numbers.
     */
    public static function customerFinance($leadId) {
        $empty = [
            'contact_id' => null, 'lifetime_value' => 0.0, 'open_balance' => 0.0,
            'paid' => 0.0, 'invoice_count' => 0, 'last_invoice_date' => null,
            'currency' => null, 'items' => [],
        ];
        try {
            $contact = DB::fetch(
                "SELECT id, name, currency_code FROM acc_contacts WHERE crm_lead_id = ? AND deleted_at IS NULL LIMIT 1",
                [(int)$leadId]
            );
            if (!$contact) return $empty;

            $agg = DB::fetch(
                "SELECT COUNT(*) AS n,
                        COALESCE(SUM(amount), 0) AS billed,
                        COALESCE(SUM(paid_amount), 0) AS paid,
                        MAX(issued_at) AS last_date
                   FROM acc_documents
                  WHERE contact_id = ? AND type = 'invoice' AND deleted_at IS NULL
                    AND status <> 'cancelled'",
                [(int)$contact['id']]
            );
            $billed = (float)($agg['billed'] ?? 0);
            $paid   = (float)($agg['paid'] ?? 0);

            // Products bought, newest first — name, quantity and the price actually charged.
            $items = DB::fetchAll(
                "SELECT di.name, SUM(di.quantity) AS qty, SUM(di.total) AS total,
                        MAX(d.issued_at) AS last_date,
                        MAX(di.price) AS unit_price
                   FROM acc_document_items di
                   JOIN acc_documents d ON d.id = di.document_id
                  WHERE d.contact_id = ? AND d.type = 'invoice' AND d.deleted_at IS NULL
                    AND d.status <> 'cancelled'
                  GROUP BY di.name
                  ORDER BY last_date DESC
                  LIMIT 25",
                [(int)$contact['id']]
            );

            return [
                'contact_id' => (int)$contact['id'],
                'lifetime_value' => $billed,
                'open_balance' => max(0, $billed - $paid),
                'paid' => $paid,
                'invoice_count' => (int)($agg['n'] ?? 0),
                'last_invoice_date' => $agg['last_date'] ?? null,
                'currency' => $contact['currency_code'] ?? null,
                'items' => array_map(fn($i) => [
                    'name' => $i['name'],
                    'qty' => (float)$i['qty'],
                    'unit_price' => (float)$i['unit_price'],
                    'total' => (float)$i['total'],
                    'last_date' => $i['last_date'],
                ], $items),
            ];
        } catch (\Throwable $e) {
            // Accounting tables may not be provisioned for this workspace.
            return $empty;
        }
    }

    /**
     * Convert a lead into a customer: set the status and make sure a matching
     * acc_contacts row exists and is linked. Idempotent.
     */
    public static function convertLead($id) {
        Authz::requireModuleAccess('crm.leads');
        $id = (int)$id;
        $lead = DB::fetch("SELECT * FROM crm_leads WHERE lead_id = ?", [$id]);
        if (!$lead) jsonError('Lead not found', 404);
        self::assertLeadAccess($lead);

        $oldStatus = $lead['lead_status'];
        if (!in_array($oldStatus, self::CUSTOMER_STATUSES, true)) {
            DB::update('crm_leads', ['lead_status' => 'Won'], 'lead_id = ?', [$id]);
        }

        $contactId = null;
        $created = false;
        try {
            $existing = DB::fetch("SELECT id FROM acc_contacts WHERE crm_lead_id = ? AND deleted_at IS NULL", [$id]);
            if ($existing) {
                $contactId = (int)$existing['id'];
            } else {
                $name = $lead['company_name'] ?: $lead['contact_person'] ?: ('Lead #' . $id);
                $contactId = (int)DB::insert('acc_contacts', [
                    'type' => 'customer',
                    'name' => $name,
                    'email' => $lead['email'] ?: null,
                    'phone' => $lead['mobile'] ?: ($lead['phone'] ?: null),
                    'country' => $lead['country'] ?: null,
                    'crm_lead_id' => $id,
                ]);
                $created = true;
            }
        } catch (\Throwable $e) {
            // Converting must still succeed on the CRM side even if Accounting
            // is not provisioned — the link can be made later.
            $contactId = null;
        }

        self::fireAutomation('lead_converted', [
            'lead_id' => $id, 'lead' => $lead,
            'old_status' => $oldStatus, 'new_status' => 'Won',
        ]);

        jsonResponse(['ok' => true, 'lead_id' => $id, 'contact_id' => $contactId, 'contact_created' => $created]);
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
        $payload = [
            'lead' => self::formatLeadDetail($lead),
            'interactions' => array_map([self::class, 'formatInteraction'], $rows),
        ];
        // Customers get a live purchases panel read from Accounting.
        if (in_array($lead['lead_status'], self::CUSTOMER_STATUSES, true)) {
            $payload['finance'] = self::customerFinance($id);
        }
        // Deals booked against this lead, for the Sales card. Only sent to
        // someone who actually holds the Sales Dashboard module, and a rep only
        // ever sees their own — the lead page must not become a side door into
        // the team's numbers.
        if (class_exists('SalesController') && Authz::hasModuleAccess('crm.sales')) {
            $sales = SalesController::forLead($id);
            if (!SalesController::canManage()) {
                $me = (int)Auth::userId();
                $sales = array_values(array_filter($sales, fn($s) => (int)$s['rep_user_id'] === $me));
            }
            $payload['sales'] = $sales;
        }
        jsonResponse($payload);
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
        // Whenever the country changes, the region is re-derived from it rather
        // than trusting whatever the client posted.
        if (array_key_exists('country', $data) && trim((string)$data['country']) !== '') {
            $fields['country'] = self::canonicalCountry($data['country']);
            $derived = self::regionForCountry($fields['country']);
            if ($derived !== '') $fields['region'] = $derived;
        }
        if (!isset($fields['region']) && array_key_exists('region', $data) && trim((string)$data['region']) !== '') {
            $fields['region'] = self::choice($data['region'], ['North America','Latin America','Europe','Middle East','Africa','Asia Pacific','Other'], $lead['region']);
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

        $after = DB::fetch("SELECT * FROM crm_leads WHERE lead_id = ?", [$id]);
        $ctx = ['lead_id' => $id, 'lead' => $after];

        if (array_key_exists('lead_status', $fields) && $fields['lead_status'] !== $lead['lead_status']) {
            self::fireAutomation('lead_status_changed',
                $ctx + ['old_status' => $lead['lead_status'], 'new_status' => $fields['lead_status']]);
            if (in_array($fields['lead_status'], self::CUSTOMER_STATUSES, true)) {
                self::fireAutomation('lead_converted',
                    $ctx + ['old_status' => $lead['lead_status'], 'new_status' => $fields['lead_status']]);
            }
        }
        if (array_key_exists('assigned_to', $fields) && (int)$fields['assigned_to'] !== (int)$lead['assigned_to']) {
            $assignCtx = $ctx + ['old_assigned' => $lead['assigned_to'] ? (int)$lead['assigned_to'] : null,
                                 'new_assigned' => $fields['assigned_to'] ? (int)$fields['assigned_to'] : null];
            self::fireAutomation(empty($lead['assigned_to']) ? 'lead_assigned' : 'lead_reassigned', $assignCtx);
        }

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
        self::fireAutomation('interaction_logged', [
            'lead_id' => (int)$data['lead_id'],
            'interaction_id' => (int)$id,
            'interaction_type' => $type,
            'outcome' => self::nullable($data['outcome'] ?? null),
        ]);

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
            'last_activity_at' => $row['last_activity_at'] ?? $row['updated_at'] ?? null,
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
