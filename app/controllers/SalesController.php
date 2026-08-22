<?php
/**
 * Sales Dashboard — targets, attainment, clients sold to, and commission.
 *
 * WHERE A SALE COMES FROM (the decision the whole module rests on)
 * ---------------------------------------------------------------
 * `crm_sales` is a hybrid ledger:
 *   • manual rows — a manager records a deal against a CRM lead;
 *   • mirrored rows — every Accounting invoice whose customer is linked back to
 *     a CRM lead (acc_contacts.crm_lead_id) is copied in by syncFromAccounting()
 *     and kept in step on every dashboard load.
 * The mirror is keyed on `acc_document_id`, which carries a UNIQUE index, so the
 * sync is idempotent no matter how often it runs. It NEVER overwrites the rep on
 * an existing row — a manager may deliberately have re-attributed a deal — and
 * it never touches a manual row.
 *
 * COMMISSION is a flat percentage per person (crm_sales_commission.rate) paid on
 * CASH COLLECTED, not on the invoiced amount: commission_amount =
 * collected_amount x rate / 100. For a mirrored sale, collected_amount IS
 * acc_documents.paid_amount, so commission can never run ahead of the ledger.
 * The rate is SNAPSHOT onto each sale when it is written, so changing someone's
 * rate today does not silently rewrite last quarter's payouts. A manager may
 * override the rate on a single deal (rate_override = 1), after which the sync
 * leaves that rate alone.
 *
 * WHO SEES WHAT
 *   rep      — their own sales, their own targets, their own commission. Can log
 *              a sale for themselves, which lands as `pending` and counts for
 *              nothing until a manager confirms it.
 *   manager  — VGo admin, or crm_role Admin / Sales Manager: the whole team, a
 *              per-person filter, and the right to record, edit and confirm
 *              sales and to set anyone's target.
 *   admin    — VGo workspace admin only: the commission rates.
 */
class SalesController
{
    const STATUSES = ['won', 'pending', 'cancelled'];
    const PERIODS  = ['month', 'quarter', 'year'];

    /* ===================================================================
       Access
       =================================================================== */

    private static function requireSales()
    {
        Authz::requireModuleAccess('crm.sales');
        if (!SalesSchema::ensure()) {
            jsonError('The sales tables are not available on this database yet. An administrator should check the error log.', 500);
        }
    }

    /** VGo admin, or a real CRM Admin / Sales Manager. Mirrors CRMController. */
    public static function canManage()
    {
        $u = Auth::user();
        if ($u && ($u['role'] ?? '') === 'admin') return true;
        if (!Auth::crmUserId()) { /* populates $_SESSION['crm_role'] */ }
        return in_array($_SESSION['crm_role'] ?? null, ['Admin', 'Sales Manager'], true);
    }

    /** Commission rates are a workspace-admin setting and nothing less. */
    private static function isAdmin()
    {
        $u = Auth::user();
        return $u && ($u['role'] ?? '') === 'admin';
    }

    private static function requireManager()
    {
        if (!self::canManage()) jsonError('Only sales managers and administrators can do that', 403);
    }

    private static function requireAdmin()
    {
        if (!self::isAdmin()) jsonError('Administrator access required', 403);
    }

    /* ===================================================================
       Periods
       =================================================================== */

    /** First day of the period $type that contains $date. */
    public static function periodStart($type, $date)
    {
        $ts = strtotime($date ?: 'today');
        if ($ts === false) $ts = time();
        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);
        switch ($type) {
            case 'year':    return sprintf('%04d-01-01', $y);
            case 'quarter': return sprintf('%04d-%02d-01', $y, (intdiv($m - 1, 3) * 3) + 1);
            default:        return sprintf('%04d-%02d-01', $y, $m);
        }
    }

    /** Last day of the period beginning at $start. */
    public static function periodEnd($type, $start)
    {
        $add = ['year' => '+1 year', 'quarter' => '+3 months'][$type] ?? '+1 month';
        return date('Y-m-d', strtotime($start . ' ' . $add . ' -1 day'));
    }

    public static function periodLabel($type, $start)
    {
        $ts = strtotime($start);
        switch ($type) {
            case 'year':    return date('Y', $ts);
            case 'quarter': return 'Q' . (intdiv((int)date('n', $ts) - 1, 3) + 1) . ' ' . date('Y', $ts);
            default:        return date('F Y', $ts);
        }
    }

    private static function periodShift($type, $start, $dir)
    {
        $step = ['year' => '1 year', 'quarter' => '3 months'][$type] ?? '1 month';
        return date('Y-m-d', strtotime($start . ' ' . ($dir > 0 ? '+' : '-') . $step));
    }

    /* ===================================================================
       Commission rates
       =================================================================== */

    /** userId => rate. One query, cached per request. */
    public static function rates()
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            foreach (DB::fetchAll("SELECT user_id, rate FROM crm_sales_commission WHERE is_active = 1") as $r) {
                $cache[(int)$r['user_id']] = (float)$r['rate'];
            }
        } catch (\Throwable $e) { error_log('SalesController::rates: ' . $e->getMessage()); }
        return $cache;
    }

    private static function rateFor($userId)
    {
        $r = self::rates();
        return isset($r[(int)$userId]) ? (float)$r[(int)$userId] : 0.0;
    }

    /** Commission is earned on cash collected, and only on a confirmed sale. */
    private static function commissionOn($status, $collected, $rate)
    {
        if ($status !== 'won') return 0.0;
        return round(((float)$collected) * ((float)$rate) / 100, 2);
    }

    /* ===================================================================
       Mirror from Accounting
       =================================================================== */

    /**
     * Copy invoices into crm_sales and keep the mirrored ones in step.
     *
     * Deliberately narrow: only invoices whose contact carries a crm_lead_id,
     * because without that link there is no CRM lead to attribute the sale to
     * and no rep to credit. Everything is wrapped — Accounting may not be
     * provisioned, and a sync failure must never take the dashboard down.
     *
     * Returns ['created' => n, 'updated' => n] or null when it could not run.
     */
    public static function syncFromAccounting()
    {
        if (!SalesSchema::ensure()) return null;
        $created = 0; $updated = 0;
        try {
            $rows = DB::fetchAll(
                "SELECT d.id, d.number, d.amount, d.paid_amount, d.currency_code, d.issued_at, d.status,
                        c.name AS contact_name, c.crm_lead_id,
                        l.company_name, l.contact_person, l.assigned_to,
                        u.id AS rep_user_id
                   FROM acc_documents d
                   JOIN acc_contacts c ON c.id = d.contact_id
                   LEFT JOIN crm_leads l ON l.lead_id = c.crm_lead_id
                   LEFT JOIN users u ON u.crm_user_id = l.assigned_to
                  WHERE d.type = 'invoice' AND d.deleted_at IS NULL
                    AND c.deleted_at IS NULL AND c.crm_lead_id IS NOT NULL"
            );
        } catch (\Throwable $e) {
            // Accounting tables absent on this install — nothing to mirror.
            return null;
        }

        foreach ($rows as $r) {
            try {
                $docId    = (int)$r['id'];
                $cancelled = strtolower((string)$r['status']) === 'cancelled';
                $client   = $r['company_name'] ?: ($r['contact_person'] ?: $r['contact_name']);
                $existing = DB::fetch("SELECT * FROM crm_sales WHERE acc_document_id = ?", [$docId]);

                if (!$existing) {
                    if ($cancelled) continue;               // never mirror a cancelled invoice in
                    $rep  = (int)($r['rep_user_id'] ?? 0);
                    $rate = self::rateFor($rep);
                    $collected = (float)$r['paid_amount'];
                    DB::insert('crm_sales', [
                        'lead_id'           => (int)$r['crm_lead_id'],
                        'client_name'       => mb_substr((string)$client, 0, 200),
                        'product'           => 'Invoice ' . $r['number'],
                        'rep_user_id'       => $rep,
                        'amount'            => (float)$r['amount'],
                        'collected_amount'  => $collected,
                        'currency_code'     => $r['currency_code'] ?: 'USD',
                        'sale_date'         => $r['issued_at'],
                        'status'            => 'won',
                        'commission_rate'   => $rate,
                        'commission_amount' => self::commissionOn('won', $collected, $rate),
                        'acc_document_id'   => $docId,
                        'source'            => 'accounting',
                        'created_by'        => null,
                    ]);
                    $created++;
                    continue;
                }

                // Refresh the money and the dates only. The rep may have been
                // re-attributed by a manager, and the rate may be overridden —
                // neither is the sync's business.
                $rate      = (int)$existing['rate_override'] === 1
                    ? (float)$existing['commission_rate']
                    : self::rateFor((int)$existing['rep_user_id']);
                $status    = $cancelled ? 'cancelled' : ($existing['status'] === 'pending' ? 'pending' : 'won');
                $collected = (float)$r['paid_amount'];
                $patch = [
                    'client_name'       => mb_substr((string)$client, 0, 200),
                    'amount'            => (float)$r['amount'],
                    'collected_amount'  => $collected,
                    'currency_code'     => $r['currency_code'] ?: 'USD',
                    'sale_date'         => $r['issued_at'],
                    'status'            => $status,
                    'commission_rate'   => $rate,
                    'commission_amount' => self::commissionOn($status, $collected, $rate),
                ];
                $changed = false;
                foreach ($patch as $k => $v) {
                    if ((string)$existing[$k] !== (string)$v) { $changed = true; break; }
                }
                if ($changed) { DB::update('crm_sales', $patch, 'id = ?', [(int)$existing['id']]); $updated++; }
            } catch (\Throwable $e) {
                error_log('SalesController::syncFromAccounting row: ' . $e->getMessage());
            }
        }
        return ['created' => $created, 'updated' => $updated];
    }

    public static function syncNow()
    {
        self::requireSales();
        self::requireManager();
        $res = self::syncFromAccounting();
        if ($res === null) jsonError('Accounting is not available on this workspace, so there is nothing to import.', 400);
        jsonResponse(['ok' => true] + $res);
    }

    /* ===================================================================
       People
       =================================================================== */

    /** Active workspace members, with their commission rate attached. */
    public static function people()
    {
        $rows = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.avatar_color, u.crm_role, wm.role AS ws_role
               FROM users u
               JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ?
              WHERE u.is_active = 1
              ORDER BY u.name",
            [Auth::workspaceId()]
        );
        $rates = self::rates();
        return array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'name'         => $r['name'],
            'email'        => $r['email'],
            'initials'     => initials($r['name']),
            'avatar_color' => $r['avatar_color'] ?: '#9A8A78',
            'crm_role'     => $r['crm_role'] ?: ($r['ws_role'] === 'admin' ? 'Admin' : 'Sales Rep'),
            'is_admin'     => $r['ws_role'] === 'admin',
            'rate'         => isset($rates[(int)$r['id']]) ? (float)$rates[(int)$r['id']] : 0.0,
        ], $rows);
    }

    /* ===================================================================
       Targets
       =================================================================== */

    /**
     * The target for one person in one period, with a fallback chain.
     *
     * A manager who sets a single annual number should not have to also fill in
     * twelve months before the monthly view means anything, so a missing month
     * falls back to its quarter / 3, then its year / 12, and a missing quarter
     * to its year / 4. `derived` says which happened, and the UI labels it, so a
     * split number is never mistaken for one somebody actually set.
     */
    public static function targetFor($userId, $type, $start)
    {
        $all = self::allTargets();
        $key = $type . '|' . $start . '|' . (int)$userId;
        if (isset($all[$key])) {
            return ['amount' => $all[$key]['amount'], 'deals' => $all[$key]['deals'], 'derived' => null];
        }
        $chain = [];
        if ($type === 'month')   $chain = [['quarter', 3], ['year', 12]];
        if ($type === 'quarter') $chain = [['year', 4]];
        foreach ($chain as [$parentType, $divisor]) {
            $pKey = $parentType . '|' . self::periodStart($parentType, $start) . '|' . (int)$userId;
            if (isset($all[$pKey]) && $all[$pKey]['amount'] > 0) {
                return [
                    'amount'  => round($all[$pKey]['amount'] / $divisor, 2),
                    'deals'   => (int)floor($all[$pKey]['deals'] / $divisor),
                    'derived' => $parentType,
                ];
            }
        }
        return ['amount' => 0.0, 'deals' => 0, 'derived' => null];
    }

    /**
     * Every target row, once per request.
     *
     * The dashboard resolves a target for each of 12 trend months x every person,
     * which as one SELECT apiece was several hundred round-trips per page load.
     * The table is tiny (people x periods), so it is cheaper to hold all of it.
     */
    private static function allTargets()
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            foreach (DB::fetchAll("SELECT user_id, period_type, period_start, target_amount, target_deals FROM crm_sales_targets") as $r) {
                $cache[$r['period_type'] . '|' . $r['period_start'] . '|' . (int)$r['user_id']] = [
                    'amount' => (float)$r['target_amount'], 'deals' => (int)$r['target_deals'],
                ];
            }
        } catch (\Throwable $e) { error_log('SalesController::allTargets: ' . $e->getMessage()); }
        return $cache;
    }

    public static function targets()
    {
        self::requireSales();
        $type  = in_array($_GET['period'] ?? '', self::PERIODS, true) ? $_GET['period'] : 'month';
        $start = self::periodStart($type, $_GET['start'] ?? date('Y-m-d'));
        $canManage = self::canManage();
        $me = (int)Auth::userId();

        $people = self::people();
        if (!$canManage) $people = array_values(array_filter($people, fn($p) => $p['id'] === $me));

        $end = self::periodEnd($type, $start);
        $out = [];
        foreach ($people as $p) {
            $t = self::targetFor($p['id'], $type, $start);
            $actual = self::actualsFor([$p['id']], $start, $end);
            $out[] = $p + [
                'target'        => $t['amount'],
                'target_deals'  => $t['deals'],
                'derived'       => $t['derived'],
                'booked'        => $actual['booked'],
                'deals'         => $actual['deals'],
            ];
        }

        $team = self::targetFor(0, $type, $start);
        jsonResponse([
            'can_manage'  => $canManage,
            'period'      => self::periodPayload($type, $start),
            'people'      => $out,
            'team_target' => $team['amount'],
            'currency'    => self::currency(),
        ]);
    }

    public static function saveTargets()
    {
        self::requireSales();
        self::requireManager();
        $in    = input();
        $type  = in_array($in['period_type'] ?? '', self::PERIODS, true) ? $in['period_type'] : 'month';
        $start = self::periodStart($type, $in['period_start'] ?? date('Y-m-d'));
        $rows  = is_array($in['targets'] ?? null) ? $in['targets'] : [];
        if (!$rows) jsonError('No targets were submitted', 422);

        $valid = array_column(self::people(), 'id');
        $valid[] = 0;                                   // 0 = the whole team
        $saved = 0; $notify = [];
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? -1);
            if (!in_array($uid, $valid, true)) continue;
            $amount = max(0, (float)($r['target_amount'] ?? 0));
            $deals  = max(0, (int)($r['target_deals'] ?? 0));

            $existing = DB::fetch(
                "SELECT id, target_amount FROM crm_sales_targets WHERE user_id = ? AND period_type = ? AND period_start = ?",
                [$uid, $type, $start]
            );
            if ($amount <= 0 && $deals <= 0) {
                if ($existing) { DB::delete('crm_sales_targets', 'id = ?', [(int)$existing['id']]); $saved++; }
                continue;
            }
            if ($existing) {
                DB::update('crm_sales_targets', [
                    'target_amount' => $amount, 'target_deals' => $deals,
                    'currency_code' => self::currency(),
                ], 'id = ?', [(int)$existing['id']]);
                if ((float)$existing['target_amount'] !== $amount && $uid > 0) $notify[$uid] = $amount;
            } else {
                DB::insert('crm_sales_targets', [
                    'user_id' => $uid, 'period_type' => $type, 'period_start' => $start,
                    'target_amount' => $amount, 'target_deals' => $deals,
                    'currency_code' => self::currency(), 'created_by' => Auth::userId(),
                ]);
                if ($uid > 0) $notify[$uid] = $amount;
            }
            $saved++;
        }

        // Tell people their number changed — a target nobody knows about is not
        // a target. Never notify the manager about their own edit.
        $label = self::periodLabel($type, $start);
        foreach ($notify as $uid => $amount) {
            if ((int)$uid === (int)Auth::userId()) continue;
            try {
                NotificationController::create(
                    $uid, 'sales_target', 'Your ' . $label . ' sales target',
                    'Your target for ' . $label . ' is now ' . self::currency() . ' ' . number_format($amount, 0) . '.',
                    'crm_sales', 0
                );
            } catch (\Throwable $e) { error_log('SalesController::saveTargets notify: ' . $e->getMessage()); }
        }

        jsonResponse(['ok' => true, 'saved' => $saved]);
    }

    /* ===================================================================
       Commission settings (admin only)
       =================================================================== */

    public static function commissionSettings()
    {
        self::requireSales();
        self::requireAdmin();
        jsonResponse(['people' => self::people(), 'currency' => self::currency()]);
    }

    public static function saveCommission()
    {
        self::requireSales();
        self::requireAdmin();
        $in   = input();
        $rows = is_array($in['rates'] ?? null) ? $in['rates'] : [];
        $valid = array_column(self::people(), 'id');
        $saved = 0;
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            if (!in_array($uid, $valid, true)) continue;
            $rate = (float)($r['rate'] ?? 0);
            if ($rate < 0) $rate = 0;
            if ($rate > 100) $rate = 100;
            $existing = DB::fetch("SELECT id FROM crm_sales_commission WHERE user_id = ?", [$uid]);
            if ($existing) {
                DB::update('crm_sales_commission', ['rate' => $rate, 'is_active' => 1, 'updated_by' => Auth::userId()],
                    'id = ?', [(int)$existing['id']]);
            } else {
                DB::insert('crm_sales_commission', [
                    'user_id' => $uid, 'rate' => $rate, 'basis' => 'collected',
                    'is_active' => 1, 'updated_by' => Auth::userId(),
                ]);
            }
            $saved++;
        }

        // Rates are snapshot per sale, so a change only takes effect from now on.
        // Rows the manager has not deliberately overridden are re-stamped for the
        // CURRENT and FUTURE periods only — history stays as it was paid.
        $from = self::periodStart('month', date('Y-m-d'));
        try {
            foreach (DB::fetchAll("SELECT id, rep_user_id, status, collected_amount FROM crm_sales
                                    WHERE deleted_at IS NULL AND rate_override = 0 AND sale_date >= ?", [$from]) as $s) {
                $rate = self::rateFor((int)$s['rep_user_id']);
                DB::update('crm_sales', [
                    'commission_rate'   => $rate,
                    'commission_amount' => self::commissionOn($s['status'], $s['collected_amount'], $rate),
                ], 'id = ?', [(int)$s['id']]);
            }
        } catch (\Throwable $e) { error_log('SalesController::saveCommission restamp: ' . $e->getMessage()); }

        jsonResponse(['ok' => true, 'saved' => $saved]);
    }

    /* ===================================================================
       Sales CRUD
       =================================================================== */

    public static function options()
    {
        self::requireSales();
        $canManage = self::canManage();
        jsonResponse([
            'can_manage' => $canManage,
            'can_admin'  => self::isAdmin(),
            'me'         => (int)Auth::userId(),
            'people'     => $canManage ? self::people() : array_values(array_filter(self::people(), fn($p) => $p['id'] === (int)Auth::userId())),
            'currency'   => self::currency(),
            'statuses'   => self::STATUSES,
        ]);
    }

    public static function createSale()
    {
        self::requireSales();
        $in = input();
        $canManage = self::canManage();

        $rep = (int)($in['rep_user_id'] ?? 0) ?: (int)Auth::userId();
        if (!$canManage && $rep !== (int)Auth::userId()) {
            jsonError('You can only log a sale against yourself', 403);
        }
        // A rep's own entry is a claim, not a fact: it waits for a manager.
        $status = $canManage
            ? (in_array($in['status'] ?? '', self::STATUSES, true) ? $in['status'] : 'won')
            : 'pending';

        $amount    = round(max(0, (float)($in['amount'] ?? 0)), 2);
        $collected = $canManage ? round(max(0, (float)($in['collected_amount'] ?? 0)), 2) : 0.0;
        if ($collected > $amount) $collected = $amount;
        if ($amount <= 0) jsonError('Enter the sale price', 422);

        [$leadId, $clientName] = self::resolveClient($in);
        if ($clientName === '') jsonError('Choose a client or type a client name', 422);

        $rate = ($canManage && isset($in['commission_rate']) && $in['commission_rate'] !== '')
            ? round(max(0, min(100, (float)$in['commission_rate'])), 3)
            : self::rateFor($rep);
        $override = ($canManage && isset($in['commission_rate']) && $in['commission_rate'] !== '') ? 1 : 0;

        $id = (int)DB::insert('crm_sales', [
            'lead_id'           => $leadId,
            'client_name'       => $clientName,
            'product'           => self::str($in['product'] ?? null, 255),
            'rep_user_id'       => $rep,
            'amount'            => $amount,
            'collected_amount'  => $collected,
            'currency_code'     => self::currency(),
            'sale_date'         => self::date($in['sale_date'] ?? null),
            'status'            => $status,
            'commission_rate'   => $rate,
            'commission_amount' => self::commissionOn($status, $collected, $rate),
            'rate_override'     => $override,
            'source'            => 'manual',
            'notes'             => self::str($in['notes'] ?? null, 2000),
            'created_by'        => Auth::userId(),
        ]);
        if (!$id) jsonError('The sale could not be saved', 500);

        if ($status === 'pending') self::notifyManagers($id, $clientName, $amount);
        jsonResponse(['ok' => true, 'id' => $id, 'status' => $status]);
    }

    public static function updateSale($id)
    {
        self::requireSales();
        self::requireManager();
        $id   = (int)$id;
        $sale = DB::fetch("SELECT * FROM crm_sales WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$sale) jsonError('Sale not found', 404);
        $in = input();

        $patch = [];
        if (array_key_exists('rep_user_id', $in))  $patch['rep_user_id'] = (int)$in['rep_user_id'];
        if (array_key_exists('product', $in))      $patch['product']     = self::str($in['product'], 255);
        if (array_key_exists('notes', $in))        $patch['notes']       = self::str($in['notes'], 2000);
        if (array_key_exists('sale_date', $in))    $patch['sale_date']   = self::date($in['sale_date']);
        if (array_key_exists('status', $in) && in_array($in['status'], self::STATUSES, true)) {
            $patch['status'] = $in['status'];
        }
        if (array_key_exists('client_name', $in) || array_key_exists('lead_id', $in)) {
            [$leadId, $clientName] = self::resolveClient($in);
            if ($clientName !== '') { $patch['lead_id'] = $leadId; $patch['client_name'] = $clientName; }
        }

        // A mirrored sale takes its money from Accounting; editing it here would
        // only be overwritten by the next sync, so the fields are refused rather
        // than accepted and silently lost.
        $mirrored = (int)$sale['acc_document_id'] > 0;
        if (!$mirrored) {
            if (array_key_exists('amount', $in))           $patch['amount']           = round(max(0, (float)$in['amount']), 2);
            if (array_key_exists('collected_amount', $in)) $patch['collected_amount'] = round(max(0, (float)$in['collected_amount']), 2);
        }
        if (isset($in['commission_rate']) && $in['commission_rate'] !== '') {
            $patch['commission_rate'] = round(max(0, min(100, (float)$in['commission_rate'])), 3);
            $patch['rate_override']   = 1;
        }

        $next = array_merge($sale, $patch);
        if ((float)$next['collected_amount'] > (float)$next['amount']) {
            $patch['collected_amount'] = (float)$next['amount'];
            $next['collected_amount']  = (float)$next['amount'];
        }
        // The rate follows the rep unless somebody pinned it to this deal.
        if (isset($patch['rep_user_id']) && (int)$next['rate_override'] === 0) {
            $patch['commission_rate'] = self::rateFor((int)$patch['rep_user_id']);
            $next['commission_rate']  = $patch['commission_rate'];
        }
        $patch['commission_amount'] = self::commissionOn($next['status'], $next['collected_amount'], $next['commission_rate']);

        DB::update('crm_sales', $patch, 'id = ?', [$id]);

        // Confirming somebody else's claim is worth telling them about.
        if (($sale['status'] ?? '') === 'pending' && ($patch['status'] ?? '') === 'won'
            && (int)$sale['rep_user_id'] !== (int)Auth::userId()) {
            try {
                NotificationController::create(
                    (int)$sale['rep_user_id'], 'sales_won', 'Sale confirmed',
                    'Your sale to ' . $next['client_name'] . ' was confirmed and now counts toward your target.',
                    'crm_sales', $id
                );
            } catch (\Throwable $e) { error_log('SalesController::updateSale notify: ' . $e->getMessage()); }
        }

        jsonResponse(['ok' => true, 'id' => $id]);
    }

    public static function deleteSale($id)
    {
        self::requireSales();
        self::requireManager();
        $id = (int)$id;
        $sale = DB::fetch("SELECT id, acc_document_id FROM crm_sales WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$sale) jsonError('Sale not found', 404);
        if ((int)$sale['acc_document_id'] > 0) {
            jsonError('This sale mirrors an Accounting invoice. Cancel or delete the invoice in Accounting instead.', 409);
        }
        DB::update('crm_sales', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        jsonResponse(['ok' => true]);
    }

    /** Paged list behind the dashboard's "All sales" tab. */
    public static function index()
    {
        self::requireSales();
        [$scopeSql, $scopeParams, $canManage] = self::scope($_GET['rep'] ?? null);
        $where  = [$scopeSql, 's.deleted_at IS NULL'];
        $params = $scopeParams;

        if (!empty($_GET['from'])) { $where[] = 's.sale_date >= ?'; $params[] = self::date($_GET['from']); }
        if (!empty($_GET['to']))   { $where[] = 's.sale_date <= ?'; $params[] = self::date($_GET['to']); }
        if (!empty($_GET['status']) && in_array($_GET['status'], self::STATUSES, true)) {
            $where[] = 's.status = ?'; $params[] = $_GET['status'];
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') { $where[] = '(s.client_name LIKE ? OR s.product LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

        $limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
        $rows = DB::fetchAll(
            "SELECT s.*, u.name AS rep_name, u.avatar_color
               FROM crm_sales s LEFT JOIN users u ON u.id = s.rep_user_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY s.sale_date DESC, s.id DESC LIMIT $limit",
            $params
        );
        jsonResponse([
            'sales' => array_map([self::class, 'formatSale'], $rows),
            'can_manage' => $canManage,
            'currency' => self::currency(),
        ]);
    }

    /* ===================================================================
       The dashboard
       =================================================================== */

    public static function dashboard()
    {
        self::requireSales();

        // Keep the mirror honest on every load. It is a single indexed join and
        // writes only when a figure actually moved, so the common case is one
        // read. Failures are swallowed — a dashboard that renders stale is far
        // better than one that 500s because Accounting is mid-migration.
        try { self::syncFromAccounting(); } catch (\Throwable $e) { error_log('SalesController::dashboard sync: ' . $e->getMessage()); }

        $type  = in_array($_GET['period'] ?? '', self::PERIODS, true) ? $_GET['period'] : 'month';
        $start = self::periodStart($type, $_GET['start'] ?? date('Y-m-d'));
        $end   = self::periodEnd($type, $start);
        $canManage = self::canManage();
        $me = (int)Auth::userId();

        $repFilter = $_GET['rep'] ?? null;
        [$scopeSql, $scopeParams] = self::scope($repFilter);
        $viewingUser = $canManage
            ? (ctype_digit((string)$repFilter) && (int)$repFilter > 0 ? (int)$repFilter : 0)
            : $me;

        // ---- headline figures -------------------------------------------
        $agg = DB::fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN s.status = 'won' THEN s.amount END), 0)            AS booked,
                COALESCE(SUM(CASE WHEN s.status = 'won' THEN s.collected_amount END), 0)  AS collected,
                COALESCE(SUM(CASE WHEN s.status = 'won' THEN s.commission_amount END), 0) AS commission,
                COALESCE(SUM(CASE WHEN s.status = 'won' THEN s.amount * s.commission_rate / 100 END), 0) AS commission_potential,
                SUM(CASE WHEN s.status = 'won' THEN 1 ELSE 0 END)                          AS deals,
                COALESCE(SUM(CASE WHEN s.status = 'pending' THEN s.amount END), 0)         AS pipeline,
                SUM(CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END)                      AS pipeline_deals,
                COUNT(DISTINCT s.currency_code)                                            AS currencies
               FROM crm_sales s
              WHERE $scopeSql AND s.deleted_at IS NULL AND s.sale_date BETWEEN ? AND ?",
            array_merge($scopeParams, [$start, $end])
        ) ?: [];

        $booked    = (float)($agg['booked'] ?? 0);
        $collected = (float)($agg['collected'] ?? 0);
        $deals     = (int)($agg['deals'] ?? 0);

        // ---- target ------------------------------------------------------
        $people = self::people();
        if ($viewingUser) {
            $t = self::targetFor($viewingUser, $type, $start);
            $target = $t['amount']; $targetDeals = $t['deals']; $targetDerived = $t['derived']; $targetSource = 'person';
        } else {
            $explicit = self::targetFor(0, $type, $start);
            if ($explicit['amount'] > 0) {
                $target = $explicit['amount']; $targetDeals = $explicit['deals'];
                $targetDerived = $explicit['derived']; $targetSource = 'team';
            } else {
                $target = 0.0; $targetDeals = 0; $targetDerived = null; $targetSource = 'sum';
                foreach ($people as $p) {
                    $t = self::targetFor($p['id'], $type, $start);
                    $target += $t['amount']; $targetDeals += $t['deals'];
                }
            }
        }

        // How far through the period we are — the honest yardstick for "on track".
        $totalDays   = max(1, (int)((strtotime($end) - strtotime($start)) / 86400) + 1);
        $elapsedDays = min($totalDays, max(0, (int)((strtotime(date('Y-m-d')) - strtotime($start)) / 86400) + 1));
        $pace = round(($elapsedDays / $totalDays) * 100, 1);

        // ---- per-person board -------------------------------------------
        $board = [];
        $boardPeople = $viewingUser
            ? array_values(array_filter($people, fn($p) => $p['id'] === $viewingUser))
            : $people;
        foreach ($boardPeople as $p) {
            $a = self::actualsFor([$p['id']], $start, $end);
            $t = self::targetFor($p['id'], $type, $start);
            if ($a['booked'] <= 0 && $a['deals'] <= 0 && $t['amount'] <= 0 && $a['pipeline'] <= 0) continue;
            $board[] = [
                'user_id'      => $p['id'],
                'name'         => $p['name'],
                'initials'     => $p['initials'],
                'avatar_color' => $p['avatar_color'],
                'crm_role'     => $p['crm_role'],
                'rate'         => $p['rate'],
                'target'       => $t['amount'],
                'target_deals' => $t['deals'],
                'derived'      => $t['derived'],
                'booked'       => $a['booked'],
                'collected'    => $a['collected'],
                'commission'   => $a['commission'],
                'deals'        => $a['deals'],
                'pipeline'     => $a['pipeline'],
                'attainment'   => $t['amount'] > 0 ? round($a['booked'] / $t['amount'] * 100, 1) : null,
            ];
        }
        usort($board, fn($x, $y) => $y['booked'] <=> $x['booked']);

        // ---- clients sold to --------------------------------------------
        $clients = DB::fetchAll(
            "SELECT s.lead_id,
                    MAX(s.client_name) AS client_name,
                    COUNT(*) AS deals,
                    SUM(s.amount) AS amount,
                    SUM(s.collected_amount) AS collected,
                    SUM(s.commission_amount) AS commission,
                    MAX(s.sale_date) AS last_sale,
                    MAX(s.currency_code) AS currency_code,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS reps
               FROM crm_sales s LEFT JOIN users u ON u.id = s.rep_user_id
              WHERE $scopeSql AND s.deleted_at IS NULL AND s.status = 'won'
                AND s.sale_date BETWEEN ? AND ?
              GROUP BY s.lead_id, LOWER(s.client_name)
              ORDER BY amount DESC LIMIT 100",
            array_merge($scopeParams, [$start, $end])
        );

        // ---- recent sales -------------------------------------------------
        $recent = DB::fetchAll(
            "SELECT s.*, u.name AS rep_name, u.avatar_color
               FROM crm_sales s LEFT JOIN users u ON u.id = s.rep_user_id
              WHERE $scopeSql AND s.deleted_at IS NULL AND s.sale_date BETWEEN ? AND ?
              ORDER BY s.sale_date DESC, s.id DESC LIMIT 50",
            array_merge($scopeParams, [$start, $end])
        );

        // ---- 12-month trend ------------------------------------------------
        $trendFrom = date('Y-m-01', strtotime($start . ' -11 months'));
        $trendTo   = date('Y-m-d', strtotime(date('Y-m-01', strtotime($start)) . ' +1 month -1 day'));
        $tRows = DB::fetchAll(
            "SELECT DATE_FORMAT(s.sale_date, '%Y-%m-01') AS m,
                    COALESCE(SUM(CASE WHEN s.status = 'won' THEN s.amount END), 0) AS booked,
                    COALESCE(SUM(CASE WHEN s.status = 'won' THEN s.collected_amount END), 0) AS collected
               FROM crm_sales s
              WHERE $scopeSql AND s.deleted_at IS NULL AND s.sale_date BETWEEN ? AND ?
              GROUP BY m ORDER BY m",
            array_merge($scopeParams, [$trendFrom, $trendTo])
        );
        $byMonth = [];
        foreach ($tRows as $r) $byMonth[$r['m']] = $r;
        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m-01', strtotime($start . " -$i months"));
            $mTarget = 0.0;
            if ($viewingUser) {
                $mTarget = self::targetFor($viewingUser, 'month', $m)['amount'];
            } else {
                foreach ($people as $p) $mTarget += self::targetFor($p['id'], 'month', $m)['amount'];
            }
            $trend[] = [
                'month'     => $m,
                'label'     => date('M', strtotime($m)),
                'year'      => date('Y', strtotime($m)),
                'booked'    => (float)($byMonth[$m]['booked'] ?? 0),
                'collected' => (float)($byMonth[$m]['collected'] ?? 0),
                'target'    => round($mTarget, 2),
            ];
        }

        // ---- pipeline by lead status (links straight back to Leads) -------
        $pipeline = [];
        try {
            $pipeline = DB::fetchAll(
                "SELECT l.lead_status AS label, COUNT(*) AS value
                   FROM crm_leads l
                  WHERE l.lead_status IN ('Proposal Sent','Negotiation','Demo Scheduled','Interested')
                  " . ($viewingUser ? "AND l.assigned_to = (SELECT crm_user_id FROM users WHERE id = " . (int)$viewingUser . ")" : "") . "
                  GROUP BY l.lead_status ORDER BY value DESC"
            );
        } catch (\Throwable $e) { $pipeline = []; }

        $pendingCount = (int)($agg['pipeline_deals'] ?? 0);

        jsonResponse([
            'can_manage' => $canManage,
            'can_admin'  => self::isAdmin(),
            'me'         => $me,
            'scope'      => $canManage ? ($viewingUser ? 'person' : 'team') : 'own',
            'viewing_user' => $viewingUser,
            'period'     => self::periodPayload($type, $start),
            'currency'   => self::currency(),
            'mixed_currency' => (int)($agg['currencies'] ?? 0) > 1,
            'kpis' => [
                'booked'      => $booked,
                'collected'   => $collected,
                'outstanding' => max(0, $booked - $collected),
                'deals'       => $deals,
                'avg_deal'    => $deals > 0 ? round($booked / $deals, 2) : 0,
                'commission'  => (float)($agg['commission'] ?? 0),
                'commission_potential' => round((float)($agg['commission_potential'] ?? 0), 2),
                'pipeline'    => (float)($agg['pipeline'] ?? 0),
                'pipeline_deals' => $pendingCount,
                'target'      => round($target, 2),
                'target_deals'=> $targetDeals,
                'target_derived' => $targetDerived,
                'target_source'  => $targetSource,
                'attainment'  => $target > 0 ? round($booked / $target * 100, 1) : null,
                'gap'         => round(max(0, $target - $booked), 2),
                'pace'        => $pace,
                'days_left'   => max(0, $totalDays - $elapsedDays),
            ],
            'board'    => $board,
            'clients'  => array_map(fn($c) => [
                'lead_id'    => $c['lead_id'] !== null ? (int)$c['lead_id'] : null,
                'name'       => $c['client_name'],
                'deals'      => (int)$c['deals'],
                'amount'     => (float)$c['amount'],
                'collected'  => (float)$c['collected'],
                'commission' => (float)$c['commission'],
                'last_sale'  => $c['last_sale'],
                'currency'   => $c['currency_code'],
                'reps'       => $c['reps'],
            ], $clients),
            'sales'    => array_map([self::class, 'formatSale'], $recent),
            'trend'    => $trend,
            'lead_pipeline' => array_map(fn($p) => ['label' => $p['label'], 'value' => (int)$p['value']], $pipeline),
            'people'   => $canManage ? $people : [],
        ]);
    }

    /* ===================================================================
       Internals
       =================================================================== */

    /** Booked / collected / commission / deals for a set of users in a window. */
    private static function actualsFor(array $userIds, $start, $end)
    {
        $byRep = self::actualsByRep($start, $end);
        $out = ['booked' => 0.0, 'collected' => 0.0, 'commission' => 0.0, 'deals' => 0, 'pipeline' => 0.0];
        foreach ($userIds as $uid) {
            $r = $byRep[(int)$uid] ?? null;
            if (!$r) continue;
            $out['booked']     += $r['booked'];
            $out['collected']  += $r['collected'];
            $out['commission'] += $r['commission'];
            $out['deals']      += $r['deals'];
            $out['pipeline']   += $r['pipeline'];
        }
        return $out;
    }

    /**
     * Per-rep totals for one window, in ONE query, memoised per window.
     * The leaderboard asks for every person in turn; without this that is one
     * query per head on every dashboard load.
     */
    private static function actualsByRep($start, $end)
    {
        static $memo = [];
        $key = $start . '|' . $end;
        if (isset($memo[$key])) return $memo[$key];
        $out = [];
        try {
            $rows = DB::fetchAll(
                "SELECT rep_user_id,
                        COALESCE(SUM(CASE WHEN status = 'won' THEN amount END), 0) booked,
                        COALESCE(SUM(CASE WHEN status = 'won' THEN collected_amount END), 0) collected,
                        COALESCE(SUM(CASE WHEN status = 'won' THEN commission_amount END), 0) commission,
                        SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) deals,
                        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) pipeline
                   FROM crm_sales
                  WHERE deleted_at IS NULL AND sale_date BETWEEN ? AND ?
                  GROUP BY rep_user_id",
                [$start, $end]
            );
            foreach ($rows as $r) {
                $out[(int)$r['rep_user_id']] = [
                    'booked' => (float)$r['booked'], 'collected' => (float)$r['collected'],
                    'commission' => (float)$r['commission'], 'deals' => (int)$r['deals'],
                    'pipeline' => (float)$r['pipeline'],
                ];
            }
        } catch (\Throwable $e) { error_log('SalesController::actualsByRep: ' . $e->getMessage()); }
        return $memo[$key] = $out;
    }

    /**
     * The row filter for the caller.
     *
     * A rep is pinned to their own rep_user_id server-side — the `rep` query
     * parameter is only honoured for a manager, so nobody can widen their own
     * view by editing the URL.
     */
    private static function scope($repParam)
    {
        $canManage = self::canManage();
        if (!$canManage) return ['s.rep_user_id = ?', [(int)Auth::userId()], false];
        if (ctype_digit((string)$repParam) && (int)$repParam > 0) return ['s.rep_user_id = ?', [(int)$repParam], true];
        return ['1 = 1', [], true];
    }

    private static function periodPayload($type, $start)
    {
        return [
            'type'  => $type,
            'start' => $start,
            'end'   => self::periodEnd($type, $start),
            'label' => self::periodLabel($type, $start),
            'prev'  => self::periodShift($type, $start, -1),
            'next'  => self::periodShift($type, $start, 1),
            'is_current' => $start === self::periodStart($type, date('Y-m-d')),
        ];
    }

    private static function formatSale($r)
    {
        return [
            'id'          => (int)$r['id'],
            'lead_id'     => $r['lead_id'] !== null ? (int)$r['lead_id'] : null,
            'client_name' => $r['client_name'],
            'product'     => $r['product'],
            'rep_user_id' => (int)$r['rep_user_id'],
            'rep_name'    => $r['rep_name'] ?? null,
            'avatar_color'=> $r['avatar_color'] ?? '#9A8A78',
            'amount'      => (float)$r['amount'],
            'collected'   => (float)$r['collected_amount'],
            'currency'    => $r['currency_code'],
            'sale_date'   => $r['sale_date'],
            'status'      => $r['status'],
            'commission_rate'   => (float)$r['commission_rate'],
            'commission'  => (float)$r['commission_amount'],
            'rate_override' => (int)$r['rate_override'] === 1,
            'acc_document_id' => $r['acc_document_id'] !== null ? (int)$r['acc_document_id'] : null,
            'source'      => $r['source'],
            'notes'       => $r['notes'],
        ];
    }

    /** Accept either a CRM lead id or a typed name; return both. */
    private static function resolveClient($in)
    {
        $leadId = isset($in['lead_id']) && $in['lead_id'] !== '' && $in['lead_id'] !== null ? (int)$in['lead_id'] : null;
        $name   = self::str($in['client_name'] ?? '', 200) ?? '';
        if ($leadId) {
            $lead = DB::fetch("SELECT lead_id, company_name, contact_person FROM crm_leads WHERE lead_id = ?", [$leadId]);
            if (!$lead) { $leadId = null; }
            else if ($name === '') $name = $lead['company_name'] ?: ($lead['contact_person'] ?: ('Lead #' . $leadId));
        }
        return [$leadId, trim($name)];
    }

    private static function notifyManagers($saleId, $client, $amount)
    {
        try {
            $rows = DB::fetchAll(
                "SELECT u.id FROM users u
                   JOIN workspace_members wm ON wm.user_id = u.id AND wm.workspace_id = ?
                  WHERE u.is_active = 1 AND (wm.role = 'admin' OR u.crm_role IN ('Admin','Sales Manager'))",
                [Auth::workspaceId()]
            );
            $actor = Auth::user();
            foreach ($rows as $r) {
                if ((int)$r['id'] === (int)Auth::userId()) continue;
                NotificationController::create(
                    (int)$r['id'], 'sales_pending', 'Sale awaiting confirmation',
                    ($actor['name'] ?? 'A rep') . ' logged a sale to ' . $client . ' for '
                        . self::currency() . ' ' . number_format($amount, 0) . '.',
                    'crm_sales', (int)$saleId
                );
            }
        } catch (\Throwable $e) { error_log('SalesController::notifyManagers: ' . $e->getMessage()); }
    }

    /** Reporting currency. Follows Accounting's default when it is configured. */
    public static function currency()
    {
        static $cur = null;
        if ($cur !== null) return $cur;
        $cur = 'USD';
        try {
            $row = DB::fetch("SELECT svalue FROM acc_settings WHERE skey = 'default_currency'");
            if ($row && trim((string)$row['svalue']) !== '') $cur = trim($row['svalue']);
        } catch (\Throwable $e) { /* Accounting not provisioned */ }
        return $cur;
    }

    private static function str($v, $max)
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    }

    private static function date($v)
    {
        $ts = strtotime((string)$v);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    /** Sales attached to one lead — used by the CRM lead detail page. */
    public static function forLead($leadId)
    {
        if (!SalesSchema::ensure()) return [];
        try {
            $rows = DB::fetchAll(
                "SELECT s.*, u.name AS rep_name, u.avatar_color
                   FROM crm_sales s LEFT JOIN users u ON u.id = s.rep_user_id
                  WHERE s.lead_id = ? AND s.deleted_at IS NULL
                  ORDER BY s.sale_date DESC, s.id DESC LIMIT 25",
                [(int)$leadId]
            );
            return array_map([self::class, 'formatSale'], $rows);
        } catch (\Throwable $e) { return []; }
    }
}
