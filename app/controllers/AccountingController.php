<?php
/**
 * AccountingController — native Accounting & Finance app inside VGo.
 *
 * A faithful port of the VGACC Laravel application onto VGo's own stack:
 * plain PHP + the DB:: PDO helper, served under /api/acc/*, sharing VGo's
 * session, CSRF and user table. No iframes, no second framework, no legacy
 * bridge — every screen in the SPA talks to these endpoints.
 *
 * Access is explicit-grant only (see Authz::ACC_MODULES); write operations
 * require the module grant for the area being written to.
 */
class AccountingController
{
    /* ============================================================
     * Boot / shared helpers
     * ============================================================ */

    /** Ensure schema, then check the caller holds $module. */
    private static function boot($module)
    {
        AccSchema::ensure();
        Authz::requireAccModule($module);
    }

    private static function page()
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = (int)($_GET['per_page'] ?? 25);
        if ($per < 5) $per = 25;
        if ($per > 200) $per = 200;
        return [$page, $per, ($page - 1) * $per];
    }

    private static function meta($page, $per, $total)
    {
        return [
            'page' => $page,
            'per_page' => $per,
            'total' => (int)$total,
            'pages' => $per > 0 ? (int)ceil($total / $per) : 1,
        ];
    }

    private static function tx($fn)
    {
        $pdo = DB::conn();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $result = $fn();
            if ($own) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * Bootstrap payload — everything the SPA needs for pickers
     * ============================================================ */

    public static function bootstrap()
    {
        AccSchema::ensure();
        if (!Authz::hasAnyAccAccess()) jsonError('You do not have access to the Accounting & Finance app', 403);

        $granted = array_values(array_filter(Authz::grantedModules(), fn($k) => isset(Authz::ACC_MODULES[$k])));
        $user = Auth::user();

        jsonResponse([
            'modules'   => $granted,
            'is_admin'  => $user && $user['role'] === 'admin',
            'can_admin' => ($user && $user['role'] === 'admin') && in_array('acc.settings', $granted, true),
            'settings'  => self::companySettings(),
            'empty'     => AccSchema::isEmpty(),
            'options'   => self::optionsPayload($granted),
        ]);
    }

    /**
     * The picker lists the Accounting UI needs up front.
     *
     * This used to return everything to anyone holding any one accounting
     * module, so a person granted only the finance overview received every bank
     * balance, the full customer and vendor lists and the whole chart of
     * accounts. Each list is now tied to the modules that actually need it to do
     * their job — a bills user needs vendors to enter a bill, an invoices user
     * needs customers, and neither needs the other.
     *
     * Bank balances are cut finer than the account list itself: several modules
     * need the account *picker* to record a payment, but the running balance is
     * only the business of banking, reports and the overview.
     */
    private static function optionsPayload(?array $granted = null)
    {
        $granted = $granted ?? array_values(array_filter(Authz::grantedModules(), fn($k) => isset(Authz::ACC_MODULES[$k])));
        $has = fn(...$keys) => (bool)array_intersect($keys, $granted);

        // Modules that create or edit a document, and so need the line-item pickers.
        $posts = ['acc.invoices', 'acc.bills', 'acc.recurring', 'acc.accounting', 'acc.reports'];

        $needsAccounts = $has('acc.banking', 'acc.dashboard', 'acc.reports', ...$posts);
        $seesBalances  = $has('acc.banking', 'acc.dashboard', 'acc.reports');
        $accounts = $needsAccounts
            ? DB::fetchAll("SELECT id, name, bank_name, number, type, balance, currency_code, color FROM acc_accounts WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name")
            : [];
        if ($accounts && !$seesBalances) {
            foreach ($accounts as &$a) { unset($a['balance']); }
            unset($a);
        }

        $catalog = $has('acc.catalog', 'acc.settings', ...$posts);

        return [
            'accounts'   => $accounts,
            'customers'  => $has('acc.customers', 'acc.invoices', 'acc.recurring', 'acc.reports')
                ? DB::fetchAll("SELECT id, name, email FROM acc_contacts WHERE deleted_at IS NULL AND type = 'customer' ORDER BY name") : [],
            'vendors'    => $has('acc.vendors', 'acc.bills', 'acc.recurring', 'acc.reports')
                ? DB::fetchAll("SELECT id, name, email, category FROM acc_contacts WHERE deleted_at IS NULL AND type = 'vendor' ORDER BY name") : [],
            'investors'  => $has('acc.investors', 'acc.reports')
                ? DB::fetchAll("SELECT id, name, email, category FROM acc_contacts WHERE deleted_at IS NULL AND type = 'investor' ORDER BY name") : [],
            'taxes'      => $catalog
                ? DB::fetchAll("SELECT id, name, rate, type FROM acc_taxes WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name") : [],
            'categories' => $catalog
                ? DB::fetchAll("SELECT id, name, type, color, parent_id FROM acc_categories WHERE deleted_at IS NULL ORDER BY type, name") : [],
            'items'      => $catalog
                ? DB::fetchAll("SELECT id, name, sku, sale_price, purchase_price, type FROM acc_items WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name") : [],
            'coa'        => $has('acc.accounting', 'acc.settings', 'acc.reports', 'acc.invoices', 'acc.bills')
                ? DB::fetchAll("SELECT id, code, name, type, side FROM acc_chart_of_accounts WHERE deleted_at IS NULL AND enabled = 1 ORDER BY code") : [],
            'agents'     => self::agentList(),
            'adjustment_kinds' => Acc::ADJUSTMENT_KINDS,
        ];
    }

    /**
     * Workspace members usable as the sales agent on a document or transaction.
     * Reuses the same membership join Settings → Team uses, so the picker can
     * never offer someone outside the workspace.
     */
    private static function agentList()
    {
        try {
            return DB::fetchAll(
                "SELECT u.id, u.name, u.email, u.avatar_color
                   FROM users u
                   JOIN workspace_members wm ON wm.user_id = u.id
                  WHERE wm.workspace_id = ? AND u.is_active = 1
                  ORDER BY u.name",
                [Auth::workspaceId()]
            );
        } catch (\Throwable $e) {
            error_log('AccountingController::agentList: ' . $e->getMessage());
            return [];
        }
    }

    private static function companySettings()
    {
        Acc::flushSettings();
        $defaults = [
            'company_name' => 'Victory Genomics, Inc.',
            'company_ein' => '',
            'company_address' => '',
            'company_phone' => '',
            'company_email' => '',
            'company_website' => '',
            'fiscal_year_start' => '01-01',
            'default_currency' => 'USD',
            'invoice_prefix' => 'INV-',
            'invoice_next_number' => '0150',
            'bill_prefix' => 'BILL-',
            'bill_next_number' => '2078',
            'default_payment_terms' => 'Net 30',
            'invoice_footer' => 'Thank you for working with Victory Genomics.',
        ];
        $out = [];
        foreach ($defaults as $k => $v) $out[$k] = Acc::setting($k, $v);
        return $out;
    }

    /* ============================================================
     * Dashboard
     * ============================================================ */

    public static function dashboard()
    {
        self::boot('acc.dashboard');
        $year = (int)date('Y');

        $totals = DB::fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
             FROM acc_transactions
             WHERE deleted_at IS NULL AND is_transfer = 0 AND YEAR(paid_at) = ?",
            [$year]
        );
        $income  = Acc::money($totals['income'] ?? 0);
        $expense = Acc::money($totals['expense'] ?? 0);

        $cash = DB::fetch("SELECT COALESCE(SUM(balance), 0) AS c FROM acc_accounts WHERE deleted_at IS NULL AND enabled = 1");

        // Cash flow — last 6 months including the current one.
        $cashFlow = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("first day of -$i month");
            $row = DB::fetch(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
                 FROM acc_transactions
                 WHERE deleted_at IS NULL AND is_transfer = 0
                   AND YEAR(paid_at) = ? AND MONTH(paid_at) = ?",
                [(int)date('Y', $ts), (int)date('n', $ts)]
            );
            $cashFlow[] = [
                'month'   => date('M', $ts),
                'income'  => Acc::money($row['income'] ?? 0),
                'expense' => Acc::money($row['expense'] ?? 0),
            ];
        }

        $spending = DB::fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(t.amount) AS total
               FROM acc_transactions t
          LEFT JOIN acc_categories c ON c.id = t.category_id
              WHERE t.deleted_at IS NULL AND t.is_transfer = 0 AND t.type = 'expense'
                AND YEAR(t.paid_at) = ?
           GROUP BY COALESCE(c.name, 'Uncategorized')
           ORDER BY total DESC LIMIT 6",
            [$year]
        );

        $overdue = DB::fetchAll(
            "SELECT d.id, d.number, d.amount, d.paid_amount, d.due_at, c.name AS contact_name
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE d.deleted_at IS NULL AND d.type = 'invoice'
                AND d.status NOT IN ('paid','cancelled','draft')
                AND d.due_at < CURDATE() AND d.paid_amount < d.amount
           ORDER BY d.due_at ASC LIMIT 5"
        );

        $unpaidBills = DB::fetchAll(
            "SELECT d.id, d.number, d.amount, d.paid_amount, d.due_at, c.name AS contact_name
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE d.deleted_at IS NULL AND d.type = 'bill'
                AND d.status NOT IN ('paid','cancelled')
                AND d.paid_amount < d.amount
           ORDER BY d.due_at ASC LIMIT 5"
        );

        $recent = DB::fetchAll(
            "SELECT d.id, d.number, d.status, d.amount, d.due_at, c.name AS contact_name
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE d.deleted_at IS NULL AND d.type = 'invoice'
           ORDER BY d.issued_at DESC, d.id DESC LIMIT 6"
        );

        $receivable = DB::fetch(
            "SELECT COALESCE(SUM(amount - paid_amount), 0) AS v FROM acc_documents
              WHERE deleted_at IS NULL AND type = 'invoice' AND status NOT IN ('paid','cancelled','draft')"
        );
        $payable = DB::fetch(
            "SELECT COALESCE(SUM(amount - paid_amount), 0) AS v FROM acc_documents
              WHERE deleted_at IS NULL AND type = 'bill' AND status NOT IN ('paid','cancelled','draft')"
        );

        jsonResponse([
            'year' => $year,
            'stats' => [
                'income' => $income,
                'expense' => $expense,
                'net' => Acc::money($income - $expense),
                'cash' => Acc::money($cash['c'] ?? 0),
                'receivable' => Acc::money($receivable['v'] ?? 0),
                'payable' => Acc::money($payable['v'] ?? 0),
            ],
            'cash_flow' => $cashFlow,
            'spending' => $spending,
            'overdue_invoices' => $overdue,
            'unpaid_bills' => $unpaidBills,
            'recent_invoices' => $recent,
        ]);
    }

    /* ============================================================
     * Documents — invoices & bills share one table
     * ============================================================ */

    private static function docType()
    {
        return ($_GET['type'] ?? 'invoice') === 'bill' ? 'bill' : 'invoice';
    }

    private static function docModule($type)
    {
        return $type === 'bill' ? 'acc.bills' : 'acc.invoices';
    }

    public static function documents()
    {
        $type = self::docType();
        self::boot(self::docModule($type));
        list($page, $per, $offset) = self::page();

        $status = $_GET['status'] ?? 'all';
        $search = trim((string)($_GET['search'] ?? ''));

        $where = "d.deleted_at IS NULL AND d.type = ?";
        $params = [$type];

        if ($status === 'open') {
            $where .= " AND d.status NOT IN ('paid','cancelled','draft')";
        } elseif ($status === 'overdue') {
            $where .= " AND d.status NOT IN ('paid','cancelled','draft') AND d.due_at < CURDATE() AND d.paid_amount < d.amount";
        } elseif ($status === 'draft') {
            $where .= " AND d.status = 'draft'";
        } elseif ($status === 'paid') {
            $where .= " AND d.status = 'paid'";
        }

        if ($search !== '') {
            $where .= " AND (d.number LIKE ? OR d.order_number LIKE ? OR c.name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $rows = DB::fetchAll(
            "SELECT d.*, c.name AS contact_name, c.id AS contact_id,
                    (SELECT di.name FROM acc_document_items di WHERE di.document_id = d.id ORDER BY di.id LIMIT 1) AS first_item
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE $where
           ORDER BY d.issued_at DESC, d.id DESC
              LIMIT $per OFFSET $offset",
            $params
        );
        $count = DB::fetch(
            "SELECT COUNT(*) AS n FROM acc_documents d LEFT JOIN acc_contacts c ON c.id = d.contact_id WHERE $where",
            $params
        );

        foreach ($rows as &$r) $r['display_status'] = Acc::displayStatus($r);
        unset($r);

        // Filter tab counts + money summary, always computed over the whole type.
        $counts = DB::fetch(
            "SELECT
               COUNT(*) AS all_n,
               SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') THEN 1 ELSE 0 END) AS open_n,
               SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') AND due_at < CURDATE() AND paid_amount < amount THEN 1 ELSE 0 END) AS overdue_n,
               SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_n,
               SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_n,
               COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') THEN amount - paid_amount END), 0) AS open_amt,
               COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') AND due_at < CURDATE() AND paid_amount < amount THEN amount - paid_amount END), 0) AS overdue_amt,
               COALESCE(SUM(CASE WHEN status = 'draft' THEN amount END), 0) AS draft_amt
             FROM acc_documents WHERE deleted_at IS NULL AND type = ?",
            [$type]
        );

        jsonResponse([
            'type' => $type,
            'documents' => $rows,
            'counts' => $counts,
            'meta' => self::meta($page, $per, $count['n'] ?? 0),
        ]);
    }

    public static function document($id)
    {
        AccSchema::ensure();
        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$doc) jsonError('Document not found', 404);
        Authz::requireAccModule(self::docModule($doc['type']));

        $doc['display_status'] = Acc::displayStatus($doc);
        $contact = $doc['contact_id']
            ? DB::fetch("SELECT * FROM acc_contacts WHERE id = ?", [(int)$doc['contact_id']])
            : null;

        $items = DB::fetchAll("SELECT * FROM acc_document_items WHERE document_id = ? ORDER BY id", [(int)$doc['id']]);
        foreach ($items as &$it) {
            $it['taxes'] = DB::fetchAll(
                "SELECT tax_id, name, amount FROM acc_document_item_taxes WHERE document_item_id = ?",
                [(int)$it['id']]
            );
        }
        unset($it);

        $payments = DB::fetchAll(
            "SELECT t.*, a.name AS account_name, u.name AS agent_name FROM acc_transactions t
          LEFT JOIN acc_accounts a ON a.id = t.account_id
          LEFT JOIN users u ON u.id = t.user_id
              WHERE t.document_id = ? AND t.deleted_at IS NULL ORDER BY t.paid_at DESC, t.id DESC",
            [(int)$doc['id']]
        );
        foreach ($payments as &$p) $p['adjustments'] = Acc::transactionSplits($p['id']);
        unset($p);

        jsonResponse([
            'document' => $doc,
            'contact' => $contact,
            'items' => $items,
            'agent' => $doc['user_id'] ? DB::fetch("SELECT id, name, email, avatar_color FROM users WHERE id = ?", [(int)$doc['user_id']]) : null,
            'totals' => DB::fetchAll("SELECT * FROM acc_document_totals WHERE document_id = ? ORDER BY sort_order", [(int)$doc['id']]),
            'histories' => DB::fetchAll("SELECT * FROM acc_document_histories WHERE document_id = ? ORDER BY id DESC", [(int)$doc['id']]),
            'payments' => $payments,
            'attachments' => self::attachmentsFor('document', $doc['id']),
            'company' => self::companySettings(),
        ]);
    }

    public static function createDocument()
    {
        $data = input();
        $type = ($data['type'] ?? 'invoice') === 'bill' ? 'bill' : 'invoice';
        self::boot(self::docModule($type));

        $contactId = (int)($data['contact_id'] ?? 0);
        if (!$contactId) jsonError($type === 'bill' ? 'Select a vendor' : 'Select a customer');
        $contact = DB::fetch("SELECT * FROM acc_contacts WHERE id = ? AND deleted_at IS NULL", [$contactId]);
        if (!$contact) jsonError('Contact not found', 404);

        $items = $data['items'] ?? [];
        if (!is_array($items) || !count($items)) jsonError('Add at least one line item');

        $result = self::tx(function () use ($data, $type, $contactId) {
            $number = Acc::reserveNumber($type);
            $issued = Acc::date($data['issued_at'] ?? null, date('Y-m-d'));
            $due    = Acc::date($data['due_at'] ?? null, date('Y-m-d', strtotime($type === 'bill' ? '+15 days' : '+30 days')));

            $docId = DB::insert('acc_documents', [
                'type'          => $type,
                'number'        => $number,
                'order_number'  => Acc::strOrNull($data['order_number'] ?? null, 100),
                'status'        => 'draft',
                'issued_at'     => $issued,
                'due_at'        => $due,
                'amount'        => 0,
                'paid_amount'   => 0,
                'currency_code' => Acc::setting('default_currency', 'USD'),
                'contact_id'    => $contactId,
                'category_id'   => Acc::intOrNull($data['category_id'] ?? null),
                'notes'         => $data['notes'] ?? null,
                'terms'         => $data['terms'] ?? null,
                'crm_lead_id'   => Acc::intOrNull($data['crm_lead_id'] ?? null),
                'user_id'       => Acc::intOrNull($data['user_id'] ?? null),
            ]);

            Acc::writeDocumentLines($docId, $data['items']);
            Acc::addHistory($docId, 'draft', ucfirst($type) . ' created');
            return $docId;
        });

        // A bill created from a scan carries the original file with it, so the
        // document and the paperwork behind it never drift apart.
        $attachmentId = null;
        if (!empty($data['staged_path'])) {
            $attachmentId = self::attachStaged(
                (int)$result,
                (string)$data['staged_path'],
                Acc::strOrNull($data['staged_name'] ?? null, 255) ?: 'bill',
                Acc::strOrNull($data['staged_mime'] ?? null, 120),
                (int)($data['staged_size'] ?? 0)
            );
        }

        jsonResponse([
            'ok' => true,
            'id' => (int)$result,
            'attachment_id' => $attachmentId,
            'document' => DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$result]),
        ]);
    }

    public static function updateDocument($id)
    {
        AccSchema::ensure();
        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$doc) jsonError('Document not found', 404);
        Authz::requireAccModule(self::docModule($doc['type']));
        if (in_array($doc['status'], ['paid', 'cancelled'], true)) {
            jsonError('A ' . $doc['status'] . ' document can no longer be edited');
        }

        $data = input();
        self::tx(function () use ($doc, $data) {
            $fields = [
                'contact_id'   => (int)($data['contact_id'] ?? $doc['contact_id']),
                'order_number' => Acc::strOrNull($data['order_number'] ?? $doc['order_number'], 100),
                'issued_at'    => Acc::date($data['issued_at'] ?? null, $doc['issued_at']),
                'due_at'       => Acc::date($data['due_at'] ?? null, $doc['due_at']),
                'category_id'  => Acc::intOrNull($data['category_id'] ?? $doc['category_id']),
                'notes'        => array_key_exists('notes', $data) ? $data['notes'] : $doc['notes'],
                'terms'        => array_key_exists('terms', $data) ? $data['terms'] : $doc['terms'],
                'user_id'      => array_key_exists('user_id', $data) ? Acc::intOrNull($data['user_id']) : Acc::intOrNull($doc['user_id']),
            ];
            DB::query(
                "UPDATE acc_documents SET contact_id = ?, order_number = ?, issued_at = ?, due_at = ?,
                        category_id = ?, notes = ?, terms = ?, user_id = ? WHERE id = ?",
                [$fields['contact_id'], $fields['order_number'], $fields['issued_at'], $fields['due_at'],
                 $fields['category_id'], $fields['notes'], $fields['terms'], $fields['user_id'], (int)$doc['id']]
            );

            if (isset($data['items']) && is_array($data['items']) && count($data['items'])) {
                Acc::writeDocumentLines($doc['id'], $data['items']);
                // A posted document whose figures changed must be re-posted.
                if (!in_array($doc['status'], ['draft'], true)) {
                    Acc::reverseForDocument($doc, 'Edited');
                    $fresh = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$doc['id']]);
                    if ($doc['type'] === 'invoice') Acc::postInvoiceIssued($fresh);
                    else Acc::postBillReceived($fresh);
                }
            }
            Acc::addHistory($doc['id'], $doc['status'], ucfirst($doc['type']) . ' updated');
            Acc::syncDocumentPaymentState($doc['id']);
        });

        jsonResponse(['ok' => true]);
    }

    public static function deleteDocument($id)
    {
        AccSchema::ensure();
        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$doc) jsonError('Document not found', 404);
        Authz::requireAccModule(self::docModule($doc['type']));

        self::tx(function () use ($doc) {
            Acc::reverseForDocument($doc, 'Deleted');
            // Detach payments so account balances stay truthful.
            $payments = DB::fetchAll("SELECT * FROM acc_transactions WHERE document_id = ? AND deleted_at IS NULL", [(int)$doc['id']]);
            DB::query("UPDATE acc_transactions SET deleted_at = NOW() WHERE document_id = ?", [(int)$doc['id']]);
            foreach ($payments as $p) Acc::recalcAccount($p['account_id']);
            DB::query("UPDATE acc_documents SET deleted_at = NOW() WHERE id = ?", [(int)$doc['id']]);
        });

        jsonResponse(['ok' => true]);
    }

    /** Mark sent (invoice) / received (bill) / cancelled — posts or reverses the ledger. */
    public static function documentStatus($id)
    {
        AccSchema::ensure();
        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$doc) jsonError('Document not found', 404);
        Authz::requireAccModule(self::docModule($doc['type']));

        $data = input();
        $action = $data['action'] ?? '';
        if (!in_array($action, ['send', 'receive', 'cancel'], true)) jsonError('Unknown action');

        self::tx(function () use ($doc, $action) {
            if ($action === 'cancel') {
                Acc::reverseForDocument($doc, 'Cancelled');
                DB::query("UPDATE acc_documents SET status = 'cancelled' WHERE id = ?", [(int)$doc['id']]);
                Acc::addHistory($doc['id'], 'cancelled', ucfirst($doc['type']) . ' cancelled');
                return;
            }
            if ($doc['status'] !== 'draft') return; // idempotent

            $newStatus = $doc['type'] === 'invoice' ? 'sent' : 'open';
            DB::query("UPDATE acc_documents SET status = ? WHERE id = ?", [$newStatus, (int)$doc['id']]);
            $fresh = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$doc['id']]);
            if ($doc['type'] === 'invoice') {
                Acc::postInvoiceIssued($fresh);
                Acc::addHistory($doc['id'], 'sent', 'Invoice marked as sent');
            } else {
                Acc::postBillReceived($fresh);
                Acc::addHistory($doc['id'], 'open', 'Bill marked as received');
            }
        });

        jsonResponse(['ok' => true, 'document' => DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$id])]);
    }

    /** Record a payment against an invoice or bill. */
    public static function documentPayment($id)
    {
        AccSchema::ensure();
        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$doc) jsonError('Document not found', 404);
        Authz::requireAccModule(self::docModule($doc['type']));
        if ($doc['status'] === 'cancelled') jsonError('This document was cancelled');

        $data = input();
        $amount = Acc::money($data['amount'] ?? 0);
        if ($amount <= 0) jsonError('Enter a payment amount greater than zero');

        // Adjustments absorb the gap between cash and debt (wire fee, early-pay
        // discount, small write-off), so the document can settle for more — or
        // less — than the money that actually moved.
        $adjustments = Acc::normaliseAdjustments($doc['type'], $data['adjustments'] ?? []);
        $settled = $amount;
        foreach ($adjustments as $a) $settled += $a['delta'];
        $settled = Acc::money($settled);

        $balance = Acc::money(Acc::num($doc['amount']) - Acc::num($doc['paid_amount']));
        if ($settled <= 0) jsonError('Adjustments cannot cancel out the whole payment');
        if ($settled > $balance + 0.005) {
            jsonError('This settles ' . number_format($settled, 2)
                . ' against an outstanding balance of ' . number_format($balance, 2));
        }

        $accountId = (int)($data['account_id'] ?? 0);
        $account = DB::fetch("SELECT * FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$accountId]);
        if (!$account) jsonError('Select the account the money moved through');

        $paidAt = Acc::date($data['paid_at'] ?? null, date('Y-m-d'));

        self::tx(function () use ($doc, $data, $amount, $accountId, $paidAt, $adjustments, $settled) {
            // A draft document that receives a payment is implicitly issued first.
            if ($doc['status'] === 'draft') {
                DB::query("UPDATE acc_documents SET status = ? WHERE id = ?",
                    [$doc['type'] === 'invoice' ? 'sent' : 'open', (int)$doc['id']]);
                $issued = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$doc['id']]);
                if ($doc['type'] === 'invoice') Acc::postInvoiceIssued($issued);
                else Acc::postBillReceived($issued);
            }

            $txId = DB::insert('acc_transactions', [
                'type'           => $doc['type'] === 'invoice' ? 'income' : 'expense',
                'paid_at'        => $paidAt,
                'amount'         => $amount,
                'currency_code'  => $doc['currency_code'],
                'account_id'     => $accountId,
                'document_id'    => (int)$doc['id'],
                'contact_id'     => Acc::intOrNull($doc['contact_id']),
                'category_id'    => Acc::intOrNull($doc['category_id']),
                'description'    => 'Payment — ' . $doc['number'],
                'payment_method' => Acc::strOrNull($data['payment_method'] ?? 'bank_transfer', 64),
                'reference'      => Acc::strOrNull($data['reference'] ?? null),
                'is_transfer'    => 0,
                'user_id'        => Acc::intOrNull($data['user_id'] ?? $doc['user_id']),
            ]);

            $fresh = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$doc['id']]);
            Acc::applyPayment($fresh, $txId, $amount, $data['adjustments'] ?? [], $paidAt);

            Acc::recalcAccount($accountId);
            Acc::syncDocumentPaymentState($doc['id']);

            $note = 'Payment of ' . number_format($amount, 2) . ' recorded';
            if (count($adjustments)) {
                $parts = [];
                foreach ($adjustments as $a) $parts[] = strtolower(Acc::ADJUSTMENT_KINDS[$a['kind']]) . ' ' . number_format($a['gross'], 2);
                $note .= ' (settles ' . number_format($settled, 2) . ' — ' . implode(', ', $parts) . ')';
            }
            Acc::addHistory($doc['id'], 'payment', $note);
        });

        jsonResponse(['ok' => true, 'document' => DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$id])]);
    }

    /* ============================================================
     * Contacts — customers & vendors
     * ============================================================ */


    /**
     * The three kinds of contact, and what each one means downstream.
     *
     * An investor is money-in like a customer, but nothing is ever invoiced to
     * them — their cash arrives as a direct bank deposit that posts to equity or
     * to a director's loan. So `doc` is null and every document subquery has to
     * be skipped rather than run against a type that will never match.
     */
    public const CONTACT_KINDS = [
        'customer' => ['module' => 'acc.customers', 'doc' => 'invoice', 'tx' => 'income'],
        'vendor'   => ['module' => 'acc.vendors',   'doc' => 'bill',    'tx' => 'expense'],
        'investor' => ['module' => 'acc.investors', 'doc' => null,      'tx' => 'income'],
    ];

    /** Resolve any user-supplied type to a known kind, defaulting to customer. */
    private static function contactKind($type)
    {
        $t = strtolower(trim((string)$type));
        $k = self::CONTACT_KINDS[$t] ?? self::CONTACT_KINDS['customer'];
        return $k + ['type' => isset(self::CONTACT_KINDS[$t]) ? $t : 'customer'];
    }

    /**
     * Guard for contact endpoints now that customers, vendors and investors are
     * separate modules. Reads the type from the request (or from the stored row
     * for id-addressed endpoints) and requires only the matching grant, so a
     * sales user granted acc.customers never sees vendor or investor records.
     */
    private static function bootContacts($type = null, $id = null) {
        if ($id !== null && $type === null) {
            $row = DB::fetch("SELECT type FROM acc_contacts WHERE id = ?", [(int)$id]);
            $type = $row['type'] ?? null;
        }
        if ($type === null) $type = $_GET['type'] ?? (input()['type'] ?? null);
        self::boot(self::contactKind($type)['module']);
    }

    public static function contacts()
    {
        self::bootContacts();
        list($page, $per, $offset) = self::page();
        $kind = self::contactKind($_GET['type'] ?? 'customer');
        $type = $kind['type'];
        $search = trim((string)($_GET['search'] ?? ''));

        $where = "c.deleted_at IS NULL AND c.type = ?";
        $params = [$type];
        if ($search !== '') {
            $where .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $docType = $kind['doc'];
        // Money can reach a contact two ways: through a document, or as a direct bank
        // payment with no paperwork (statement imports, ad-hoc transfers). Counting only
        // documents made every imported vendor read $0.00. Direct rows are the ones with
        // no document_id — payments *against* a bill are already covered by the document
        // subquery, so including them here would double-count.
        $txType  = $kind['tx'];
        $year = (int)date('Y');

        // Investors have no document type at all, so those subqueries collapse to
        // constants instead of scanning acc_documents for a type that cannot exist.
        $docOpen = $docType
            ? "COALESCE((SELECT SUM(d.amount - d.paid_amount) FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                      AND d.status NOT IN ('paid','cancelled','draft')), 0)"
            : "0";
        $docYtd = $docType
            ? "COALESCE((SELECT SUM(d.amount) FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                      AND d.status <> 'cancelled' AND YEAR(d.issued_at) = $year), 0)"
            : "0";
        $docTotal = $docType
            ? "COALESCE((SELECT SUM(d.amount) FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                      AND d.status <> 'cancelled'), 0)"
            : "0";
        $lastDoc = $docType
            ? "(SELECT d.number FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                 ORDER BY d.issued_at DESC, d.id DESC LIMIT 1)"
            : "NULL";
        $lastDocDate = $docType
            ? "COALESCE((SELECT MAX(d.issued_at) FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'), '1000-01-01')"
            : "'1000-01-01'";

        $rows = DB::fetchAll(
            "SELECT c.*,
                $docOpen AS open_amount,
                $docYtd
              + COALESCE((SELECT SUM(t.amount) FROM acc_transactions t
                    WHERE t.contact_id = c.id AND t.deleted_at IS NULL AND t.is_transfer = 0
                      AND t.document_id IS NULL AND t.type = '$txType'
                      AND YEAR(t.paid_at) = $year), 0) AS ytd_amount,
                $docTotal
              + COALESCE((SELECT SUM(t.amount) FROM acc_transactions t
                    WHERE t.contact_id = c.id AND t.deleted_at IS NULL AND t.is_transfer = 0
                      AND t.document_id IS NULL AND t.type = '$txType'), 0) AS total_amount,
                $lastDoc AS last_document,
                GREATEST(
                  $lastDocDate,
                  COALESCE((SELECT MAX(t.paid_at) FROM acc_transactions t
                    WHERE t.contact_id = c.id AND t.deleted_at IS NULL AND t.is_transfer = 0), '1000-01-01')
                ) AS last_activity
             FROM acc_contacts c
            WHERE $where
         ORDER BY c.name ASC
            LIMIT $per OFFSET $offset",
            $params
        );
        $count = DB::fetch("SELECT COUNT(*) AS n FROM acc_contacts c WHERE $where", $params);

        jsonResponse([
            'type' => $type,
            'contacts' => $rows,
            'meta' => self::meta($page, $per, $count['n'] ?? 0),
        ]);
    }

    public static function contact($id)
    {
        self::bootContacts(null, $id);
        $contact = DB::fetch("SELECT * FROM acc_contacts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$contact) jsonError('Contact not found', 404);

        $kind = self::contactKind($contact['type']);
        $docType = $kind['doc'];
        $txType  = $kind['tx'];
        $stats = $docType ? DB::fetch(
            "SELECT
               COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN amount END), 0) AS total,
               COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN paid_amount END), 0) AS paid,
               COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') THEN amount - paid_amount END), 0) AS outstanding
             FROM acc_documents WHERE contact_id = ? AND deleted_at IS NULL AND type = ?",
            [(int)$id, $docType]
        ) : ['total' => 0, 'paid' => 0, 'outstanding' => 0];
        // Direct bank payments carry no document, so fold them into total and paid.
        // Outstanding stays document-only: a settled bank payment owes nothing.
        $direct = DB::fetch(
            "SELECT COALESCE(SUM(amount), 0) AS amt FROM acc_transactions
              WHERE contact_id = ? AND deleted_at IS NULL AND is_transfer = 0
                AND document_id IS NULL AND type = ?",
            [(int)$id, $txType]
        );
        $stats['total'] = (float)$stats['total'] + (float)($direct['amt'] ?? 0);
        $stats['paid']  = (float)$stats['paid']  + (float)($direct['amt'] ?? 0);

        jsonResponse([
            'contact' => $contact,
            'people' => DB::fetchAll("SELECT * FROM acc_contact_people WHERE contact_id = ? ORDER BY id", [(int)$id]),
            'stats' => [
                'total' => Acc::money($stats['total'] ?? 0),
                'paid' => Acc::money($stats['paid'] ?? 0),
                'outstanding' => Acc::money($stats['outstanding'] ?? 0),
            ],
            'documents' => $docType ? DB::fetchAll(
                "SELECT d.*, (SELECT di.name FROM acc_document_items di WHERE di.document_id = d.id ORDER BY di.id LIMIT 1) AS first_item
                   FROM acc_documents d
                  WHERE d.contact_id = ? AND d.deleted_at IS NULL AND d.type = ?
               ORDER BY d.issued_at DESC, d.id DESC LIMIT 50",
                [(int)$id, $docType]
            ) : [],
            // An investor has no paperwork, so the payment list is the whole record —
            // give it room rather than the 25 rows a customer's sidebar needs.
            'transactions' => DB::fetchAll(
                "SELECT t.*, d.number AS document_number, cat.name AS category_name
                   FROM acc_transactions t
              LEFT JOIN acc_documents d ON d.id = t.document_id
              LEFT JOIN acc_categories cat ON cat.id = t.category_id
                  WHERE t.contact_id = ? AND t.deleted_at IS NULL
               ORDER BY t.paid_at DESC, t.id DESC LIMIT " . ($docType ? 25 : 200),
                [(int)$id]
            ),
        ]);
    }

    private static function contactFields($data, $existing = null)
    {
        $type = Acc::enum($data['type'] ?? ($existing['type'] ?? 'customer'),
                          array_keys(self::CONTACT_KINDS), 'customer');
        return [
            'type'          => $type,
            'name'          => Acc::strOrNull($data['name'] ?? ($existing['name'] ?? null)),
            'email'         => Acc::strOrNull($data['email'] ?? ($existing['email'] ?? null)),
            'tax_number'    => Acc::strOrNull($data['tax_number'] ?? ($existing['tax_number'] ?? null), 100),
            'phone'         => Acc::strOrNull($data['phone'] ?? ($existing['phone'] ?? null), 64),
            'address'       => $data['address'] ?? ($existing['address'] ?? null),
            'city'          => Acc::strOrNull($data['city'] ?? ($existing['city'] ?? null), 120),
            'state'         => Acc::strOrNull($data['state'] ?? ($existing['state'] ?? null), 120),
            'zip_code'      => Acc::strOrNull($data['zip_code'] ?? ($existing['zip_code'] ?? null), 32),
            'country'       => Acc::strOrNull($data['country'] ?? ($existing['country'] ?? null), 120),
            'website'       => Acc::strOrNull($data['website'] ?? ($existing['website'] ?? null)),
            'currency_code' => Acc::strOrNull($data['currency_code'] ?? ($existing['currency_code'] ?? 'USD'), 8) ?: 'USD',
            'category'      => Acc::strOrNull($data['category'] ?? ($existing['category'] ?? null), 120),
            'crm_lead_id'   => Acc::intOrNull($data['crm_lead_id'] ?? ($existing['crm_lead_id'] ?? null)),
            'enabled'       => isset($data['enabled']) ? (int)!!$data['enabled'] : (int)($existing['enabled'] ?? 1),
        ];
    }

    public static function createContact()
    {
        self::bootContacts();
        $data = input();
        $fields = self::contactFields($data);
        if (!$fields['name']) jsonError('Name is required');

        $id = self::tx(function () use ($fields, $data) {
            $id = DB::insert('acc_contacts', $fields);
            $person = Acc::strOrNull($data['contact_name'] ?? null);
            if ($person) {
                DB::insert('acc_contact_people', [
                    'contact_id' => $id,
                    'name' => $person,
                    'email' => $fields['email'],
                    'phone' => $fields['phone'],
                    'position' => 'Primary Contact',
                ]);
            }
            return $id;
        });

        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    public static function updateContact($id)
    {
        self::bootContacts(null, $id);
        $existing = DB::fetch("SELECT * FROM acc_contacts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$existing) jsonError('Contact not found', 404);

        $fields = self::contactFields(input(), $existing);
        if (!$fields['name']) jsonError('Name is required');

        $sets = [];
        $params = [];
        foreach ($fields as $col => $val) { $sets[] = "`$col` = ?"; $params[] = $val; }
        $params[] = (int)$id;
        DB::query("UPDATE acc_contacts SET " . implode(', ', $sets) . " WHERE id = ?", $params);

        jsonResponse(['ok' => true]);
    }

    public static function deleteContact($id)
    {
        self::bootContacts(null, $id);
        $contact = DB::fetch("SELECT * FROM acc_contacts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$contact) jsonError('Contact not found', 404);

        $used = DB::fetch("SELECT COUNT(*) AS n FROM acc_documents WHERE contact_id = ? AND deleted_at IS NULL", [(int)$id]);
        if (($used['n'] ?? 0) > 0) {
            jsonError('This contact has ' . $used['n'] . ' document(s) and cannot be deleted. Disable it instead.');
        }
        DB::query("UPDATE acc_contacts SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        DB::query("DELETE FROM acc_contact_people WHERE contact_id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * Banking — accounts, transactions, transfers, reconciliations
     * ============================================================ */

    public static function banking()
    {
        self::boot('acc.banking');
        $accounts = DB::fetchAll("SELECT * FROM acc_accounts WHERE deleted_at IS NULL ORDER BY name");
        $total = 0;
        foreach ($accounts as $a) if ((int)$a['enabled'] === 1) $total += Acc::num($a['balance']);

        jsonResponse([
            'accounts' => $accounts,
            'total_balance' => Acc::money($total),
            'transfers' => DB::fetchAll(
                "SELECT t.*, f.name AS from_name, o.name AS to_name
                   FROM acc_transfers t
              LEFT JOIN acc_accounts f ON f.id = t.from_account_id
              LEFT JOIN acc_accounts o ON o.id = t.to_account_id
                  WHERE t.deleted_at IS NULL
               ORDER BY t.transferred_at DESC, t.id DESC LIMIT 100"
            ),
            'reconciliations' => DB::fetchAll(
                "SELECT r.*, a.name AS account_name FROM acc_reconciliations r
              LEFT JOIN acc_accounts a ON a.id = r.account_id
                  WHERE r.deleted_at IS NULL
               ORDER BY r.started_at DESC, r.id DESC LIMIT 100"
            ),
            'imports' => DB::fetchAll(
                "SELECT i.id, i.account_id, i.filename, i.format, i.statement_start, i.statement_end,
                        i.total_rows, i.imported_rows, i.duplicate_rows, i.skipped_rows, i.created_at,
                        a.name AS account_name, u.name AS uploaded_by_name,
                        (SELECT COUNT(*) FROM acc_bank_lines l WHERE l.import_id = i.id AND l.status = 'pending') AS pending
                   FROM acc_bank_imports i
              LEFT JOIN acc_accounts a ON a.id = i.account_id
              LEFT JOIN users u ON u.id = i.uploaded_by
                  WHERE i.deleted_at IS NULL
               ORDER BY i.id DESC LIMIT 50"
            ),
            'review_pending' => BankFeedController::pendingCount(),
        ]);
    }

    public static function transactions()
    {
        self::boot('acc.banking');
        list($page, $per, $offset) = self::page();
        $where = "t.deleted_at IS NULL";
        $params = [];

        $type = $_GET['tx_type'] ?? 'all';
        if (in_array($type, ['income', 'expense'], true)) { $where .= " AND t.type = ?"; $params[] = $type; }

        if (!empty($_GET['account_id'])) { $where .= " AND t.account_id = ?"; $params[] = (int)$_GET['account_id']; }
        if (isset($_GET['hide_transfers']) && $_GET['hide_transfers'] === '1') $where .= " AND t.is_transfer = 0";

        // "Unmatched" = real money with no document behind it — the working
        // queue when you are reconciling a statement against invoices.
        $match = $_GET['match'] ?? 'all';
        if ($match === 'unmatched')    $where .= " AND t.document_id IS NULL AND t.is_transfer = 0";
        elseif ($match === 'matched')  $where .= " AND t.document_id IS NOT NULL";

        if (!empty($_GET['user_id'])) { $where .= " AND t.user_id = ?"; $params[] = (int)$_GET['user_id']; }

        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $where .= " AND (t.description LIKE ? OR t.reference LIKE ? OR c.name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $rows = DB::fetchAll(
            "SELECT t.*, a.name AS account_name, c.name AS contact_name, c.country AS contact_country,
                    cat.name AS category_name, d.number AS document_number, d.type AS document_type,
                    u.name AS agent_name,
                    (SELECT COUNT(*) FROM acc_transaction_splits s WHERE s.transaction_id = t.id) AS adjustment_count,
                    (SELECT COUNT(*) FROM acc_attachments at2 WHERE at2.attachable_type = 'transaction' AND at2.attachable_id = t.id) AS attachment_count
               FROM acc_transactions t
          LEFT JOIN acc_accounts a ON a.id = t.account_id
          LEFT JOIN acc_contacts c ON c.id = t.contact_id
          LEFT JOIN acc_categories cat ON cat.id = t.category_id
          LEFT JOIN acc_documents d ON d.id = t.document_id
          LEFT JOIN users u ON u.id = t.user_id
              WHERE $where
           ORDER BY t.paid_at DESC, t.id DESC
              LIMIT $per OFFSET $offset",
            $params
        );
        $count = DB::fetch(
            "SELECT COUNT(*) AS n FROM acc_transactions t
          LEFT JOIN acc_contacts c ON c.id = t.contact_id WHERE $where",
            $params
        );

        $unmatched = DB::fetch(
            "SELECT COUNT(*) AS n FROM acc_transactions t
              WHERE t.deleted_at IS NULL AND t.is_transfer = 0 AND t.document_id IS NULL"
        );

        jsonResponse([
            'transactions' => $rows,
            'unmatched_count' => (int)($unmatched['n'] ?? 0),
            'meta' => self::meta($page, $per, $count['n'] ?? 0),
        ]);
    }

    /**
     * Open documents a transaction could be applied to. Income matches unpaid
     * invoices, expense matches unpaid bills; the contact narrows it when known.
     * Each row carries its outstanding balance so the UI can show the difference
     * against the transaction amount before anything is committed.
     */
    public static function matchableDocuments()
    {
        self::boot('acc.banking');
        $type = ($_GET['type'] ?? 'income') === 'expense' ? 'bill' : 'invoice';

        $where = "d.deleted_at IS NULL AND d.type = ? AND d.status NOT IN ('paid','cancelled')
                  AND (d.amount - d.paid_amount) > 0.005";
        $params = [$type];

        if (!empty($_GET['contact_id'])) { $where .= " AND d.contact_id = ?"; $params[] = (int)$_GET['contact_id']; }

        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $where .= " AND (d.number LIKE ? OR c.name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }

        $rows = DB::fetchAll(
            "SELECT d.id, d.number, d.type, d.status, d.issued_at, d.due_at, d.amount, d.paid_amount,
                    (d.amount - d.paid_amount) AS balance, c.name AS contact_name, d.contact_id
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE $where
           ORDER BY d.due_at ASC, d.id ASC LIMIT 50",
            $params
        );
        foreach ($rows as &$r) $r['display_status'] = Acc::displayStatus($r);
        unset($r);

        jsonResponse(['documents' => $rows]);
    }

    public static function createAccount()
    {
        self::boot('acc.banking');
        $data = input();
        $name = Acc::strOrNull($data['name'] ?? null);
        if (!$name) jsonError('Account name is required');

        $opening = Acc::money($data['opening_balance'] ?? 0);
        $id = DB::insert('acc_accounts', [
            'name' => $name,
            'bank_name' => Acc::strOrNull($data['bank_name'] ?? null),
            'number' => Acc::strOrNull($data['number'] ?? null, 100),
            'currency_code' => Acc::strOrNull($data['currency_code'] ?? 'USD', 8) ?: 'USD',
            'opening_balance' => $opening,
            'balance' => $opening,
            'type' => Acc::enum($data['type'] ?? 'bank', ['bank', 'credit_card', 'cash'], 'bank'),
            'color' => Acc::strOrNull($data['color'] ?? null, 16),
            'enabled' => isset($data['enabled']) ? (int)!!$data['enabled'] : 1,
        ]);
        Acc::recalcAccount($id);
        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    public static function updateAccount($id)
    {
        self::boot('acc.banking');
        $acct = DB::fetch("SELECT * FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$acct) jsonError('Account not found', 404);
        $data = input();

        DB::query(
            "UPDATE acc_accounts SET name = ?, bank_name = ?, number = ?, currency_code = ?,
                    opening_balance = ?, type = ?, color = ?, enabled = ? WHERE id = ?",
            [
                Acc::strOrNull($data['name'] ?? $acct['name']) ?: $acct['name'],
                Acc::strOrNull($data['bank_name'] ?? $acct['bank_name']),
                Acc::strOrNull($data['number'] ?? $acct['number'], 100),
                Acc::strOrNull($data['currency_code'] ?? $acct['currency_code'], 8) ?: 'USD',
                Acc::money($data['opening_balance'] ?? $acct['opening_balance']),
                Acc::enum($data['type'] ?? $acct['type'], ['bank', 'credit_card', 'cash'], $acct['type']),
                Acc::strOrNull($data['color'] ?? $acct['color'], 16),
                isset($data['enabled']) ? (int)!!$data['enabled'] : (int)$acct['enabled'],
                (int)$id,
            ]
        );
        Acc::recalcAccount($id);
        jsonResponse(['ok' => true]);
    }

    public static function deleteAccount($id)
    {
        self::boot('acc.banking');
        $used = DB::fetch("SELECT COUNT(*) AS n FROM acc_transactions WHERE account_id = ? AND deleted_at IS NULL", [(int)$id]);
        if (($used['n'] ?? 0) > 0) jsonError('This account has transactions and cannot be deleted. Disable it instead.');
        DB::query("UPDATE acc_accounts SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    public static function accountDetail($id)
    {
        self::boot('acc.banking');
        $acct = DB::fetch("SELECT * FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$acct) jsonError('Account not found', 404);

        jsonResponse([
            'account' => $acct,
            'transactions' => DB::fetchAll(
                "SELECT t.*, c.name AS contact_name, cat.name AS category_name,
                        d.number AS document_number, d.type AS document_type
                   FROM acc_transactions t
              LEFT JOIN acc_contacts c ON c.id = t.contact_id
              LEFT JOIN acc_categories cat ON cat.id = t.category_id
              LEFT JOIN acc_documents d ON d.id = t.document_id
                  WHERE t.account_id = ? AND t.deleted_at IS NULL
               ORDER BY t.paid_at DESC, t.id DESC LIMIT 200",
                [(int)$id]
            ),
        ]);
    }

    /**
     * Validate a document a transaction is being applied to and work out how
     * much of it the transaction settles. Returns [document|null, settled].
     * Errors out rather than silently ignoring a bad match.
     */
    private static function resolveMatch($documentId, $type, $cash, $adjustments, $excludeTxId = null)
    {
        $documentId = Acc::intOrNull($documentId);
        if (!$documentId) return [null, 0.0, []];

        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [$documentId]);
        if (!$doc) jsonError('The document you are matching to no longer exists', 404);
        if ($doc['status'] === 'cancelled') jsonError('That document was cancelled');

        $wantType = $doc['type'] === 'invoice' ? 'income' : 'expense';
        if ($type !== $wantType) {
            jsonError($doc['type'] === 'invoice'
                ? 'An invoice can only be matched to money coming in'
                : 'A bill can only be matched to money going out');
        }

        $adj = Acc::normaliseAdjustments($doc['type'], $adjustments);
        $settled = Acc::money($cash);
        foreach ($adj as $a) $settled += $a['delta'];
        $settled = Acc::money($settled);
        if ($settled <= 0) jsonError('Adjustments cannot cancel out the whole payment');

        // Outstanding balance ignoring this transaction's own current effect, so
        // re-saving an existing match does not read as an overpayment.
        $already = DB::fetch(
            "SELECT
               COALESCE((SELECT SUM(t.amount) FROM acc_transactions t
                          WHERE t.document_id = ? AND t.deleted_at IS NULL AND t.is_transfer = 0
                            AND (? IS NULL OR t.id <> ?)), 0)
             + COALESCE((SELECT SUM(s.amount) FROM acc_transaction_splits s
                          JOIN acc_transactions t2 ON t2.id = s.transaction_id
                         WHERE s.document_id = ? AND t2.deleted_at IS NULL
                           AND (? IS NULL OR s.transaction_id <> ?)), 0) AS paid",
            [$documentId, $excludeTxId, $excludeTxId, $documentId, $excludeTxId, $excludeTxId]
        );
        $balance = Acc::money(Acc::num($doc['amount']) - Acc::money($already['paid'] ?? 0));
        if ($settled > $balance + 0.005) {
            jsonError('This settles ' . number_format($settled, 2) . ' against an outstanding balance of '
                . number_format($balance, 2) . ' on ' . $doc['number']);
        }
        return [$doc, $settled, $adj];
    }

    public static function createTransaction()
    {
        self::boot('acc.banking');
        $data = input();
        $type = Acc::enum($data['type'] ?? 'income', ['income', 'expense'], 'income');
        $amount = Acc::money($data['amount'] ?? 0);
        if ($amount <= 0) jsonError('Enter an amount greater than zero');

        $accountId = (int)($data['account_id'] ?? 0);
        if (!DB::fetch("SELECT id FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$accountId])) {
            jsonError('Select an account');
        }

        $adjustments = $data['adjustments'] ?? [];
        list($doc) = self::resolveMatch($data['document_id'] ?? null, $type, $amount, $adjustments);

        $id = self::tx(function () use ($data, $type, $amount, $accountId, $doc, $adjustments) {
            $paidAt = Acc::date($data['paid_at'] ?? null, date('Y-m-d'));
            $id = DB::insert('acc_transactions', [
                'type' => $type,
                'paid_at' => $paidAt,
                'amount' => $amount,
                'currency_code' => Acc::setting('default_currency', 'USD'),
                'account_id' => $accountId,
                'document_id' => $doc ? (int)$doc['id'] : null,
                'contact_id' => Acc::intOrNull($data['contact_id'] ?? ($doc['contact_id'] ?? null)),
                'category_id' => Acc::intOrNull($data['category_id'] ?? null),
                'description' => $data['description'] ?? null,
                'payment_method' => Acc::strOrNull($data['payment_method'] ?? null, 64),
                'reference' => Acc::strOrNull($data['reference'] ?? null),
                'is_transfer' => 0,
                'user_id' => Acc::intOrNull($data['user_id'] ?? ($doc['user_id'] ?? null)),
            ]);

            // Matching to a document is a payment: it must hit the ledger too,
            // or the trial balance drifts away from the document balances.
            if ($doc) {
                Acc::applyPayment($doc, $id, $amount, $adjustments, $paidAt);
                Acc::syncDocumentPaymentState($doc['id']);
                Acc::addHistory($doc['id'], 'payment', 'Matched bank transaction of ' . number_format($amount, 2));
            }
            Acc::recalcAccount($accountId);
            return $id;
        });

        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    public static function updateTransaction($id)
    {
        self::boot('acc.banking');
        $tx = DB::fetch("SELECT * FROM acc_transactions WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$tx) jsonError('Transaction not found', 404);
        if ((int)$tx['is_transfer'] === 1) jsonError('Edit the transfer instead — this row is one half of it.');

        $data = input();
        $newAccount = (int)($data['account_id'] ?? $tx['account_id']);
        $amount = Acc::money($data['amount'] ?? $tx['amount']);
        if ($amount <= 0) jsonError('Enter an amount greater than zero');
        $type = Acc::enum($data['type'] ?? $tx['type'], ['income', 'expense'], $tx['type']);

        // Omitting document_id leaves the existing match alone; sending null clears it.
        $targetDocId = array_key_exists('document_id', $data)
            ? Acc::intOrNull($data['document_id'])
            : Acc::intOrNull($tx['document_id']);
        $adjustments = array_key_exists('adjustments', $data)
            ? $data['adjustments']
            : array_map(
                fn($s) => ['kind' => $s['kind'], 'amount' => abs(Acc::num($s['amount'])), 'description' => $s['description']],
                Acc::transactionSplits($tx['id'])
            );
        if (!$targetDocId) $adjustments = [];

        list($doc) = self::resolveMatch($targetDocId, $type, $amount, $adjustments, (int)$id);

        self::tx(function () use ($tx, $id, $data, $type, $amount, $newAccount, $doc, $adjustments) {
            $paidAt = Acc::date($data['paid_at'] ?? null, $tx['paid_at']);

            // Posted entries are never edited, only reversed and re-posted.
            Acc::unapplyPayment((int)$id, 'Payment edited');

            DB::query(
                "UPDATE acc_transactions SET type = ?, paid_at = ?, amount = ?, account_id = ?, contact_id = ?,
                        category_id = ?, description = ?, payment_method = ?, reference = ?,
                        document_id = ?, user_id = ? WHERE id = ?",
                [
                    $type,
                    $paidAt,
                    $amount,
                    $newAccount,
                    Acc::intOrNull($data['contact_id'] ?? $tx['contact_id']),
                    Acc::intOrNull($data['category_id'] ?? $tx['category_id']),
                    array_key_exists('description', $data) ? $data['description'] : $tx['description'],
                    Acc::strOrNull($data['payment_method'] ?? $tx['payment_method'], 64),
                    Acc::strOrNull($data['reference'] ?? $tx['reference']),
                    $doc ? (int)$doc['id'] : null,
                    array_key_exists('user_id', $data) ? Acc::intOrNull($data['user_id']) : Acc::intOrNull($tx['user_id']),
                    (int)$id,
                ]
            );

            if ($doc) Acc::applyPayment($doc, (int)$id, $amount, $adjustments, $paidAt);

            Acc::recalcAccount($tx['account_id']);
            if ($newAccount !== (int)$tx['account_id']) Acc::recalcAccount($newAccount);

            // Both the old and the new document need their balance re-derived.
            if ($tx['document_id']) Acc::syncDocumentPaymentState((int)$tx['document_id']);
            if ($doc && (int)$doc['id'] !== (int)$tx['document_id']) Acc::syncDocumentPaymentState((int)$doc['id']);
        });

        jsonResponse(['ok' => true]);
    }

    public static function deleteTransaction($id)
    {
        self::boot('acc.banking');
        $tx = DB::fetch("SELECT * FROM acc_transactions WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$tx) jsonError('Transaction not found', 404);
        if ((int)$tx['is_transfer'] === 1) jsonError('Delete the transfer instead — this row is one half of it.');
        if (!empty($tx['reconciliation_id'])) {
            jsonError('This transaction is part of a finished reconciliation. Reopen that period before deleting it.');
        }

        self::tx(function () use ($tx, $id) {
            // Reverse the ledger before the row goes away, else the trial balance
            // keeps a payment that no longer exists.
            Acc::unapplyPayment((int)$id, 'Payment deleted');
            DB::query("UPDATE acc_transactions SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
            // A statement line whose transaction is gone must go back to the
            // review queue, or it stays "done" with nothing behind it.
            if (!empty($tx['bank_line_id'])) {
                DB::query(
                    "UPDATE acc_bank_lines SET status = 'pending', transaction_id = NULL,
                            match_confidence = NULL, decided_by = NULL, decided_at = NULL
                      WHERE id = ?",
                    [(int)$tx['bank_line_id']]
                );
            }
            Acc::recalcAccount($tx['account_id']);
            if ($tx['document_id']) Acc::syncDocumentPaymentState((int)$tx['document_id']);
        });
        jsonResponse(['ok' => true]);
    }

    public static function createTransfer()
    {
        self::boot('acc.banking');
        $data = input();
        $from = (int)($data['from_account_id'] ?? 0);
        $to   = (int)($data['to_account_id'] ?? 0);
        $amount = Acc::money($data['amount'] ?? 0);

        if (!$from || !$to) jsonError('Choose both accounts');
        if ($from === $to) jsonError('Choose two different accounts');
        if ($amount <= 0) jsonError('Enter an amount greater than zero');
        foreach ([$from, $to] as $a) {
            if (!DB::fetch("SELECT id FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$a])) jsonError('Account not found', 404);
        }

        $date = Acc::date($data['transferred_at'] ?? null, date('Y-m-d'));
        $desc = Acc::strOrNull($data['description'] ?? null, 255) ?: 'Account transfer';

        $id = self::tx(function () use ($from, $to, $amount, $date, $desc) {
            // is_transfer keeps both legs out of Profit & Loss and Cash Flow.
            $expenseId = DB::insert('acc_transactions', [
                'type' => 'expense', 'paid_at' => $date, 'amount' => $amount,
                'currency_code' => Acc::setting('default_currency', 'USD'),
                'account_id' => $from, 'description' => $desc, 'is_transfer' => 1,
            ]);
            $incomeId = DB::insert('acc_transactions', [
                'type' => 'income', 'paid_at' => $date, 'amount' => $amount,
                'currency_code' => Acc::setting('default_currency', 'USD'),
                'account_id' => $to, 'description' => $desc, 'is_transfer' => 1,
            ]);
            $transferId = DB::insert('acc_transfers', [
                'transferred_at' => $date, 'description' => $desc,
                'from_account_id' => $from, 'to_account_id' => $to, 'amount' => $amount,
                'currency_code' => Acc::setting('default_currency', 'USD'),
                'expense_transaction_id' => $expenseId, 'income_transaction_id' => $incomeId,
            ]);
            Acc::recalcAccount($from);
            Acc::recalcAccount($to);
            return $transferId;
        });

        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    public static function deleteTransfer($id)
    {
        self::boot('acc.banking');
        $t = DB::fetch("SELECT * FROM acc_transfers WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$t) jsonError('Transfer not found', 404);

        self::tx(function () use ($t) {
            foreach (['expense_transaction_id', 'income_transaction_id'] as $col) {
                if ($t[$col]) DB::query("UPDATE acc_transactions SET deleted_at = NOW() WHERE id = ?", [(int)$t[$col]]);
            }
            DB::query("UPDATE acc_transfers SET deleted_at = NOW() WHERE id = ?", [(int)$t['id']]);
            Acc::recalcAccount($t['from_account_id']);
            Acc::recalcAccount($t['to_account_id']);
        });
        jsonResponse(['ok' => true]);
    }

    /**
     * Start a reconciliation.
     *
     * The beginning balance is the ending balance of the last one closed on this
     * account, stored rather than recomputed. Recomputing it would let an edit
     * to a years-old transaction quietly rewrite a period that was signed off —
     * the exact failure a reconciliation exists to prevent.
     */
    public static function createReconciliation()
    {
        self::boot('acc.banking');
        $data = input();
        $accountId = (int)($data['account_id'] ?? 0);
        $account = DB::fetch("SELECT * FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$accountId]);
        if (!$account) jsonError('Select an account');

        $open = DB::fetch(
            "SELECT id, ended_at FROM acc_reconciliations
              WHERE account_id = ? AND deleted_at IS NULL AND reconciled = 0
           ORDER BY id DESC LIMIT 1",
            [$accountId]
        );
        if ($open) {
            jsonResponse(['ok' => true, 'id' => (int)$open['id'], 'existing' => true]);
        }

        $prev = self::lastClosedReconciliation($accountId);
        $opening = $prev ? Acc::money($prev['closing_balance']) : Acc::money($account['opening_balance']);
        $startedAt = $prev && !empty($prev['ended_at'])
            ? date('Y-m-d', strtotime($prev['ended_at'] . ' +1 day'))
            : Acc::date($data['started_at'] ?? null, date('Y-m-01'));

        $id = DB::insert('acc_reconciliations', [
            'account_id' => $accountId,
            'started_at' => Acc::date($data['started_at'] ?? null, $startedAt),
            'ended_at' => Acc::date($data['ended_at'] ?? null, date('Y-m-t')),
            'opening_balance' => $opening,
            'closing_balance' => Acc::money($data['closing_balance'] ?? 0),
            'reconciled' => 0,
        ]);
        jsonResponse(['ok' => true, 'id' => (int)$id, 'opening_balance' => $opening]);
    }

    private static function lastClosedReconciliation($accountId)
    {
        return DB::fetch(
            "SELECT * FROM acc_reconciliations
              WHERE account_id = ? AND deleted_at IS NULL AND reconciled = 1
           ORDER BY ended_at DESC, id DESC LIMIT 1",
            [(int)$accountId]
        );
    }

    /**
     * The reconcile worksheet.
     *
     * Everything not yet locked into a closed reconciliation and dated on or
     * before the statement end is listed — including items older than the
     * period. A cheque written in March that only cleared in May has to be
     * tickable in May, or the difference can never reach zero.
     */
    public static function reconciliation($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch(
            "SELECT r.*, a.name AS account_name, a.balance AS account_balance, a.currency_code
               FROM acc_reconciliations r
          LEFT JOIN acc_accounts a ON a.id = r.account_id
              WHERE r.id = ? AND r.deleted_at IS NULL",
            [(int)$id]
        );
        if (!$rec) jsonError('Reconciliation not found', 404);

        $accountId = (int)$rec['account_id'];
        $end = $rec['ended_at'] ?: date('Y-m-d');
        $closed = (int)$rec['reconciled'] === 1;

        $scope = $closed
            ? "t.reconciliation_id = " . (int)$id
            : "(t.reconciliation_id IS NULL OR t.reconciliation_id = " . (int)$id . ") AND t.paid_at <= ?";
        $params = $closed ? [$accountId] : [$accountId, $end];

        $rows = DB::fetchAll(
            "SELECT t.id, t.type, t.paid_at, t.amount, t.description, t.reference, t.is_transfer,
                    t.cleared_at, t.reconciliation_id, t.document_id, t.bank_line_id,
                    c.name AS contact_name, d.number AS document_number,
                    cat.name AS category_name
               FROM acc_transactions t
          LEFT JOIN acc_contacts c ON c.id = t.contact_id
          LEFT JOIN acc_documents d ON d.id = t.document_id
          LEFT JOIN acc_categories cat ON cat.id = t.category_id
              WHERE t.account_id = ? AND t.deleted_at IS NULL AND $scope
           ORDER BY t.paid_at ASC, t.id ASC
              LIMIT 2000",
            $params
        );

        $clearedIn = 0.0; $clearedOut = 0.0; $openIn = 0.0; $openOut = 0.0;
        foreach ($rows as $r) {
            $amt = (float)$r['amount'];
            $isCleared = !empty($r['cleared_at']) || (int)$r['reconciliation_id'] === (int)$id;
            if ($r['type'] === 'income') { $isCleared ? $clearedIn += $amt : $openIn += $amt; }
            else { $isCleared ? $clearedOut += $amt : $openOut += $amt; }
        }

        $opening   = Acc::money($rec['opening_balance']);
        $statement = Acc::money($rec['closing_balance']);
        $clearedBalance = Acc::money($opening + $clearedIn - $clearedOut);
        $difference     = Acc::money($statement - $clearedBalance);

        // Statement lines still waiting on a decision would change the answer if
        // dealt with — worth saying so before anyone signs the period off.
        $pending = DB::fetch(
            "SELECT COUNT(*) AS n FROM acc_bank_lines
              WHERE account_id = ? AND status = 'pending' AND posted_at <= ?",
            [$accountId, $end]
        );

        jsonResponse([
            'reconciliation' => $rec,
            'transactions'   => $rows,
            'summary' => [
                'opening_balance'  => $opening,
                'statement_balance' => $statement,
                'cleared_in'       => Acc::money($clearedIn),
                'cleared_out'      => Acc::money($clearedOut),
                'cleared_balance'  => $clearedBalance,
                'difference'       => $difference,
                'balanced'         => abs($difference) < 0.005,
                'uncleared_in'     => Acc::money($openIn),
                'uncleared_out'    => Acc::money($openOut),
                'cleared_count'    => count(array_filter($rows, fn($r) => !empty($r['cleared_at']) || (int)$r['reconciliation_id'] === (int)$id)),
                'total_count'      => count($rows),
            ],
            'pending_statement_lines' => (int)($pending['n'] ?? 0),
            // Kept for older clients that still read these two keys.
            'unreconciled'   => array_values(array_filter($rows, fn($r) => empty($r['cleared_at']))),
            'cleared_total'  => Acc::money($clearedIn - $clearedOut),
            'attachments'    => self::attachmentsFor('reconciliation', $rec['id']),
        ]);
    }

    /** Tick or untick transactions on the worksheet. */
    public static function reconciliationMark($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch("SELECT * FROM acc_reconciliations WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$rec) jsonError('Reconciliation not found', 404);
        if ((int)$rec['reconciled'] === 1) jsonError('This period is closed. Reopen it before changing what is cleared.');

        $data = input();
        $ids = $data['transaction_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$clean) jsonError('Select at least one transaction');
        // Default is to clear, so the old call shape keeps working unchanged.
        $cleared = !array_key_exists('cleared', $data) || (bool)$data['cleared'];

        $in = implode(',', array_fill(0, count($clean), '?'));
        $params = array_merge($clean, [(int)$rec['account_id']]);
        DB::query(
            "UPDATE acc_transactions
                SET cleared_at = " . ($cleared ? "COALESCE(cleared_at, NOW())" : "NULL") . ",
                    reconciled = " . ($cleared ? "1" : "0") . "
              WHERE id IN ($in) AND account_id = ? AND deleted_at IS NULL AND reconciliation_id IS NULL",
            $params
        );

        jsonResponse(['ok' => true, 'marked' => count($clean), 'cleared' => $cleared]);
    }

    /**
     * Finish the period.
     *
     * Refuses while the difference is not zero unless explicitly forced, and
     * records the forced amount as an adjustment note rather than pretending
     * the period balanced.
     */
    public static function reconciliationClose($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch("SELECT * FROM acc_reconciliations WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$rec) jsonError('Reconciliation not found', 404);
        if ((int)$rec['reconciled'] === 1) jsonError('This period is already closed.');

        $data = input();
        $endedAt = Acc::date($data['ended_at'] ?? null, $rec['ended_at'] ?: date('Y-m-d'));
        $statement = array_key_exists('closing_balance', $data) && $data['closing_balance'] !== null && $data['closing_balance'] !== ''
            ? Acc::money($data['closing_balance']) : Acc::money($rec['closing_balance']);

        $sums = DB::fetch(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS net,
                    COUNT(*) AS n
               FROM acc_transactions
              WHERE account_id = ? AND deleted_at IS NULL AND cleared_at IS NOT NULL
                AND reconciliation_id IS NULL AND paid_at <= ?",
            [(int)$rec['account_id'], $endedAt]
        );
        $clearedBalance = Acc::money(Acc::num($rec['opening_balance']) + Acc::num($sums['net'] ?? 0));
        $difference = Acc::money($statement - $clearedBalance);

        if (abs($difference) >= 0.005 && empty($data['force'])) {
            jsonError('The difference is ' . number_format($difference, 2)
                . '. Tick the items that appear on your statement until it reaches zero, or close it anyway and record the gap.', 409);
        }

        self::tx(function () use ($rec, $id, $endedAt, $statement, $difference, $sums, $data) {
            DB::query(
                "UPDATE acc_transactions SET reconciliation_id = ?, reconciled = 1
                  WHERE account_id = ? AND deleted_at IS NULL AND cleared_at IS NOT NULL
                    AND reconciliation_id IS NULL AND paid_at <= ?",
                [(int)$id, (int)$rec['account_id'], $endedAt]
            );
            DB::query(
                "UPDATE acc_reconciliations SET ended_at = ?, closing_balance = ?, reconciled = 1 WHERE id = ?",
                [$endedAt, $statement, (int)$id]
            );
        });

        jsonResponse([
            'ok' => true,
            'cleared_count' => (int)($sums['n'] ?? 0),
            'difference' => $difference,
            'forced' => abs($difference) >= 0.005,
        ]);
    }

    /**
     * Reopen a closed period.
     *
     * Only the most recent one on the account: reopening an earlier period would
     * move the beginning balance of every period after it, and nothing would say
     * so on screen.
     */
    public static function reconciliationReopen($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch("SELECT * FROM acc_reconciliations WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$rec) jsonError('Reconciliation not found', 404);
        if ((int)$rec['reconciled'] !== 1) jsonError('That period is already open.');

        $newer = DB::fetch(
            "SELECT id FROM acc_reconciliations
              WHERE account_id = ? AND deleted_at IS NULL AND reconciled = 1 AND id > ? LIMIT 1",
            [(int)$rec['account_id'], (int)$id]
        );
        if ($newer) jsonError('A later period has been reconciled on this account. Reopen that one first.');

        self::tx(function () use ($id) {
            DB::query("UPDATE acc_transactions SET reconciliation_id = NULL WHERE reconciliation_id = ?", [(int)$id]);
            DB::query("UPDATE acc_reconciliations SET reconciled = 0 WHERE id = ?", [(int)$id]);
        });
        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * Ledger — journal entries & chart of accounts
     * ============================================================ */

    public static function journal()
    {
        self::boot('acc.accounting');
        list($page, $per, $offset) = self::page();

        $rows = DB::fetchAll(
            "SELECT e.*,
                    COALESCE((SELECT SUM(l.debit)  FROM acc_journal_lines l WHERE l.journal_entry_id = e.id), 0) AS total_debit,
                    COALESCE((SELECT SUM(l.credit) FROM acc_journal_lines l WHERE l.journal_entry_id = e.id), 0) AS total_credit
               FROM acc_journal_entries e
              WHERE e.deleted_at IS NULL
           ORDER BY e.entry_date DESC, e.id DESC
              LIMIT $per OFFSET $offset"
        );
        foreach ($rows as &$r) {
            $r['lines'] = DB::fetchAll(
                "SELECT l.*, c.code, c.name AS account_name
                   FROM acc_journal_lines l
              LEFT JOIN acc_chart_of_accounts c ON c.id = l.chart_of_account_id
                  WHERE l.journal_entry_id = ? ORDER BY l.id",
                [(int)$r['id']]
            );
        }
        unset($r);

        $count = DB::fetch("SELECT COUNT(*) AS n FROM acc_journal_entries WHERE deleted_at IS NULL");
        jsonResponse(['entries' => $rows, 'meta' => self::meta($page, $per, $count['n'] ?? 0)]);
    }

    public static function createJournalEntry()
    {
        self::boot('acc.accounting');
        $data = input();
        $lines = $data['lines'] ?? [];
        if (!is_array($lines) || count($lines) < 2) jsonError('A journal entry needs at least two lines');

        $clean = [];
        $debit = 0; $credit = 0;
        foreach ($lines as $l) {
            $coaId = (int)($l['chart_of_account_id'] ?? 0);
            if (!$coaId) continue;
            $coa = DB::fetch("SELECT * FROM acc_chart_of_accounts WHERE id = ? AND deleted_at IS NULL", [$coaId]);
            if (!$coa) continue;
            $d = Acc::money($l['debit'] ?? 0);
            $c = Acc::money($l['credit'] ?? 0);
            if ($d <= 0 && $c <= 0) continue;
            if ($d > 0 && $c > 0) jsonError('A line can be a debit or a credit, not both');
            $debit += $d; $credit += $c;
            $clean[] = ['coa' => $coa, 'debit' => $d, 'credit' => $c, 'description' => Acc::strOrNull($l['description'] ?? null, 255)];
        }
        if (count($clean) < 2) jsonError('A journal entry needs at least two valid lines');
        if (abs(Acc::money($debit) - Acc::money($credit)) >= 0.01) {
            jsonError('Debits (' . number_format($debit, 2) . ') must equal credits (' . number_format($credit, 2) . ')');
        }

        $memo = Acc::strOrNull($data['memo'] ?? null, 255);
        if (!$memo) jsonError('Add a memo describing this entry');
        $status = Acc::enum($data['status'] ?? 'posted', ['posted', 'draft'], 'posted');

        $id = self::tx(function () use ($memo, $data, $clean, $status) {
            if ($status === 'posted') {
                return Acc::postEntry($memo, 'manual', Acc::date($data['entry_date'] ?? null, date('Y-m-d')), $clean);
            }
            // Draft: written but not reflected in balances.
            $entryId = DB::insert('acc_journal_entries', [
                'number' => Acc::nextJournalNumber(),
                'entry_date' => Acc::date($data['entry_date'] ?? null, date('Y-m-d')),
                'memo' => $memo, 'source' => 'manual', 'status' => 'draft',
            ]);
            foreach ($clean as $l) {
                DB::insert('acc_journal_lines', [
                    'journal_entry_id' => $entryId,
                    'chart_of_account_id' => (int)$l['coa']['id'],
                    'debit' => $l['debit'], 'credit' => $l['credit'],
                    'description' => $l['description'] ?? $memo,
                ]);
            }
            return $entryId;
        });

        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    /** Posted entries are immutable — they can only be reversed. */
    public static function reverseJournalEntry($id)
    {
        self::boot('acc.accounting');
        $entry = DB::fetch("SELECT * FROM acc_journal_entries WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$entry) jsonError('Journal entry not found', 404);
        if ($entry['status'] !== 'posted') jsonError('Only posted entries can be reversed');
        if ($entry['source'] === 'reversal') jsonError('A reversal cannot itself be reversed');

        self::tx(function () use ($entry) {
            $lines = DB::fetchAll("SELECT * FROM acc_journal_lines WHERE journal_entry_id = ?", [(int)$entry['id']]);
            $mirror = [];
            foreach ($lines as $l) {
                $coa = DB::fetch("SELECT * FROM acc_chart_of_accounts WHERE id = ?", [(int)$l['chart_of_account_id']]);
                if (!$coa) continue;
                $mirror[] = ['coa' => $coa, 'debit' => Acc::num($l['credit']), 'credit' => Acc::num($l['debit']), 'description' => 'Reversal'];
            }
            if (count($mirror) >= 2) {
                Acc::postEntry("Reversal: reversing {$entry['number']}", 'reversal', date('Y-m-d'), $mirror, $entry['document_id']);
            }
            DB::query("UPDATE acc_journal_entries SET status = 'reversed' WHERE id = ?", [(int)$entry['id']]);
            foreach ($lines as $l) Acc::recalcCoa((int)$l['chart_of_account_id']);
        });

        jsonResponse(['ok' => true]);
    }

    public static function deleteJournalEntry($id)
    {
        self::boot('acc.accounting');
        $entry = DB::fetch("SELECT * FROM acc_journal_entries WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$entry) jsonError('Journal entry not found', 404);
        if ($entry['status'] === 'posted') jsonError('Posted entries are never deleted — reverse it instead');

        DB::query("DELETE FROM acc_journal_lines WHERE journal_entry_id = ?", [(int)$id]);
        DB::query("UPDATE acc_journal_entries SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    public static function chartOfAccounts()
    {
        self::boot('acc.accounting');
        $rows = DB::fetchAll("SELECT * FROM acc_chart_of_accounts WHERE deleted_at IS NULL ORDER BY code");
        $debit = 0; $credit = 0;
        foreach ($rows as $r) {
            if ($r['side'] === 'debit') $debit += Acc::num($r['balance']);
            else $credit += Acc::num($r['balance']);
        }
        jsonResponse([
            'accounts' => $rows,
            'total_debit' => Acc::money($debit),
            'total_credit' => Acc::money($credit),
        ]);
    }

    public static function createCoa()
    {
        self::boot('acc.accounting');
        $data = input();
        $code = Acc::strOrNull($data['code'] ?? null, 32);
        $name = Acc::strOrNull($data['name'] ?? null);
        if (!$code || !$name) jsonError('Code and name are required');
        if (DB::fetch("SELECT id FROM acc_chart_of_accounts WHERE code = ? AND deleted_at IS NULL", [$code])) {
            jsonError('An account with code ' . $code . ' already exists');
        }
        $id = DB::insert('acc_chart_of_accounts', [
            'code' => $code, 'name' => $name,
            'type' => Acc::enum($data['type'] ?? 'asset', ['asset', 'liability', 'equity', 'revenue', 'expense'], 'asset'),
            'side' => Acc::enum($data['side'] ?? 'debit', ['debit', 'credit'], 'debit'),
            'balance' => Acc::money($data['balance'] ?? 0),
            'enabled' => 1,
        ]);
        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    public static function updateCoa($id)
    {
        self::boot('acc.accounting');
        $coa = DB::fetch("SELECT * FROM acc_chart_of_accounts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$coa) jsonError('Account not found', 404);
        $data = input();
        $code = Acc::strOrNull($data['code'] ?? $coa['code'], 32) ?: $coa['code'];
        $dupe = DB::fetch("SELECT id FROM acc_chart_of_accounts WHERE code = ? AND id <> ? AND deleted_at IS NULL", [$code, (int)$id]);
        if ($dupe) jsonError('An account with code ' . $code . ' already exists');

        DB::query(
            "UPDATE acc_chart_of_accounts SET code = ?, name = ?, type = ?, side = ?, enabled = ? WHERE id = ?",
            [
                $code,
                Acc::strOrNull($data['name'] ?? $coa['name']) ?: $coa['name'],
                Acc::enum($data['type'] ?? $coa['type'], ['asset', 'liability', 'equity', 'revenue', 'expense'], $coa['type']),
                Acc::enum($data['side'] ?? $coa['side'], ['debit', 'credit'], $coa['side']),
                isset($data['enabled']) ? (int)!!$data['enabled'] : (int)$coa['enabled'],
                (int)$id,
            ]
        );
        Acc::recalcCoa($id);
        jsonResponse(['ok' => true]);
    }

    public static function deleteCoa($id)
    {
        self::boot('acc.accounting');
        $used = DB::fetch("SELECT COUNT(*) AS n FROM acc_journal_lines WHERE chart_of_account_id = ?", [(int)$id]);
        if (($used['n'] ?? 0) > 0) jsonError('This account has journal lines and cannot be deleted. Disable it instead.');
        DB::query("UPDATE acc_chart_of_accounts SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * Catalog — items, categories, taxes
     * ============================================================ */

    public static function catalog()
    {
        self::boot('acc.catalog');
        jsonResponse([
            'items' => DB::fetchAll(
                "SELECT i.*, c.name AS category_name FROM acc_items i
              LEFT JOIN acc_categories c ON c.id = i.category_id
                  WHERE i.deleted_at IS NULL ORDER BY i.name"
            ),
            'categories' => DB::fetchAll(
                "SELECT c.*, p.name AS parent_name FROM acc_categories c
              LEFT JOIN acc_categories p ON p.id = c.parent_id
                  WHERE c.deleted_at IS NULL ORDER BY c.type, c.name"
            ),
            'taxes' => DB::fetchAll("SELECT * FROM acc_taxes WHERE deleted_at IS NULL ORDER BY name"),
        ]);
    }

    public static function saveItem($id = null)
    {
        self::boot('acc.catalog');
        $data = input();
        $name = Acc::strOrNull($data['name'] ?? null);
        if (!$name) jsonError('Item name is required');

        $fields = [
            'name' => $name,
            'sku' => Acc::strOrNull($data['sku'] ?? null, 100),
            'description' => $data['description'] ?? null,
            'sale_price' => isset($data['sale_price']) && $data['sale_price'] !== '' ? Acc::money($data['sale_price']) : null,
            'purchase_price' => isset($data['purchase_price']) && $data['purchase_price'] !== '' ? Acc::money($data['purchase_price']) : null,
            'type' => Acc::enum($data['type'] ?? 'service', ['service', 'product'], 'service'),
            'category_id' => Acc::intOrNull($data['category_id'] ?? null),
            'enabled' => isset($data['enabled']) ? (int)!!$data['enabled'] : 1,
        ];

        if ($id) {
            $sets = []; $params = [];
            foreach ($fields as $col => $val) { $sets[] = "`$col` = ?"; $params[] = $val; }
            $params[] = (int)$id;
            DB::query("UPDATE acc_items SET " . implode(', ', $sets) . " WHERE id = ?", $params);
            jsonResponse(['ok' => true, 'id' => (int)$id]);
        }
        jsonResponse(['ok' => true, 'id' => (int)DB::insert('acc_items', $fields)]);
    }

    public static function updateItem($id) { self::saveItem($id); }

    public static function deleteItem($id)
    {
        self::boot('acc.catalog');
        DB::query("UPDATE acc_items SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    public static function saveCategory($id = null)
    {
        self::boot('acc.catalog');
        $data = input();
        $name = Acc::strOrNull($data['name'] ?? null);
        if (!$name) jsonError('Category name is required');

        $parentId = Acc::intOrNull($data['parent_id'] ?? null);
        if ($id && $parentId === (int)$id) jsonError('A category cannot be its own parent');

        $fields = [
            'name' => $name,
            'type' => Acc::enum($data['type'] ?? 'expense', ['income', 'expense'], 'expense'),
            'color' => Acc::strOrNull($data['color'] ?? '#7e6549', 16) ?: '#7e6549',
            'parent_id' => $parentId,
            'enabled' => isset($data['enabled']) ? (int)!!$data['enabled'] : 1,
        ];

        if ($id) {
            $sets = []; $params = [];
            foreach ($fields as $col => $val) { $sets[] = "`$col` = ?"; $params[] = $val; }
            $params[] = (int)$id;
            DB::query("UPDATE acc_categories SET " . implode(', ', $sets) . " WHERE id = ?", $params);
            jsonResponse(['ok' => true, 'id' => (int)$id]);
        }
        jsonResponse(['ok' => true, 'id' => (int)DB::insert('acc_categories', $fields)]);
    }

    public static function updateCategory($id) { self::saveCategory($id); }

    public static function deleteCategory($id)
    {
        self::boot('acc.catalog');
        $used = DB::fetch("SELECT COUNT(*) AS n FROM acc_transactions WHERE category_id = ? AND deleted_at IS NULL", [(int)$id]);
        if (($used['n'] ?? 0) > 0) jsonError('This category is in use by ' . $used['n'] . ' transaction(s). Disable it instead.');
        DB::query("UPDATE acc_categories SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        DB::query("UPDATE acc_categories SET parent_id = NULL WHERE parent_id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    public static function saveTax($id = null)
    {
        self::boot('acc.catalog');
        $data = input();
        $name = Acc::strOrNull($data['name'] ?? null);
        if (!$name) jsonError('Tax name is required');
        $rate = Acc::num($data['rate'] ?? 0);
        if ($rate < 0 || $rate > 100) jsonError('Rate must be between 0 and 100');

        $fields = [
            'name' => $name,
            'rate' => round($rate, 4),
            'type' => Acc::enum($data['type'] ?? 'normal', ['normal', 'exempt', 'inclusive', 'compound'], 'normal'),
            'enabled' => isset($data['enabled']) ? (int)!!$data['enabled'] : 1,
        ];

        if ($id) {
            $sets = []; $params = [];
            foreach ($fields as $col => $val) { $sets[] = "`$col` = ?"; $params[] = $val; }
            $params[] = (int)$id;
            DB::query("UPDATE acc_taxes SET " . implode(', ', $sets) . " WHERE id = ?", $params);
            jsonResponse(['ok' => true, 'id' => (int)$id]);
        }
        jsonResponse(['ok' => true, 'id' => (int)DB::insert('acc_taxes', $fields)]);
    }

    public static function updateTax($id) { self::saveTax($id); }

    public static function deleteTax($id)
    {
        self::boot('acc.catalog');
        $used = DB::fetch("SELECT COUNT(*) AS n FROM acc_document_item_taxes WHERE tax_id = ?", [(int)$id]);
        if (($used['n'] ?? 0) > 0) jsonError('This tax is applied to existing documents. Disable it instead.');
        DB::query("UPDATE acc_taxes SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * Recurring schedules
     * ============================================================ */

    public static function recurring()
    {
        self::boot('acc.recurring');
        $rows = DB::fetchAll(
            "SELECT r.*, d.number AS document_number, d.type AS document_type, d.amount AS document_amount,
                    c.name AS contact_name
               FROM acc_recurrings r
          LEFT JOIN acc_documents d ON (r.recurable_type = 'document' AND d.id = r.recurable_id)
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE r.deleted_at IS NULL
           ORDER BY r.id DESC"
        );
        foreach ($rows as &$r) $r['next_run'] = self::nextRunDate($r);
        unset($r);
        jsonResponse(['schedules' => $rows]);
    }

    /** Next occurrence after last_ran_at (or started_at when never run). */
    private static function nextRunDate($rec)
    {
        $base = $rec['last_ran_at'] ?: $rec['started_at'];
        if (!$base) return null;
        $n = max(1, (int)$rec['interval_n']);
        $map = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'quarterly' => 'month', 'yearly' => 'year'];
        $unit = $map[$rec['frequency']] ?? 'month';
        if ($rec['frequency'] === 'quarterly') $n = $n * 3;
        $ts = strtotime($base);
        if (!$ts) return null;
        return date('Y-m-d', strtotime("+$n $unit", $ts));
    }

    public static function saveRecurring($id = null)
    {
        self::boot('acc.recurring');
        $data = input();
        $targetId = (int)($data['recurable_id'] ?? 0);
        if (!$targetId) jsonError('Choose the invoice or bill to repeat');
        if (!DB::fetch("SELECT id FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [$targetId])) {
            jsonError('Document not found', 404);
        }

        $fields = [
            'recurable_type' => 'document',
            'recurable_id' => $targetId,
            'frequency' => Acc::enum($data['frequency'] ?? 'monthly', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], 'monthly'),
            'interval_n' => max(1, (int)($data['interval_n'] ?? 1)),
            'started_at' => Acc::dateTime($data['started_at'] ?? null, date('Y-m-d H:i:s')),
            'limit_by' => Acc::enum($data['limit_by'] ?? 'count', ['count', 'date'], 'count'),
            'limit_count' => max(0, (int)($data['limit_count'] ?? 0)),
            'limit_date' => Acc::dateTime($data['limit_date'] ?? null, null),
            'auto_send' => !empty($data['auto_send']) ? 1 : 0,
            'status' => Acc::enum($data['status'] ?? 'active', ['active', 'paused', 'ended'], 'active'),
        ];

        if ($id) {
            $sets = []; $params = [];
            foreach ($fields as $col => $val) { $sets[] = "`$col` = ?"; $params[] = $val; }
            $params[] = (int)$id;
            DB::query("UPDATE acc_recurrings SET " . implode(', ', $sets) . " WHERE id = ?", $params);
            jsonResponse(['ok' => true, 'id' => (int)$id]);
        }
        jsonResponse(['ok' => true, 'id' => (int)DB::insert('acc_recurrings', $fields)]);
    }

    public static function updateRecurring($id) { self::saveRecurring($id); }

    public static function deleteRecurring($id)
    {
        self::boot('acc.recurring');
        DB::query("UPDATE acc_recurrings SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    /** Generate every schedule that is due now (the "Run due now" button). */
    public static function runRecurring()
    {
        self::boot('acc.recurring');
        $today = date('Y-m-d');
        $rows = DB::fetchAll("SELECT * FROM acc_recurrings WHERE deleted_at IS NULL AND status = 'active'");
        $generated = [];

        foreach ($rows as $rec) {
            $next = self::nextRunDate($rec);
            if (!$next || $next > $today) continue;
            if ($rec['limit_by'] === 'count' && (int)$rec['limit_count'] > 0
                && (int)$rec['occurrences'] >= (int)$rec['limit_count']) {
                DB::query("UPDATE acc_recurrings SET status = 'ended' WHERE id = ?", [(int)$rec['id']]);
                continue;
            }
            if ($rec['limit_by'] === 'date' && $rec['limit_date'] && $next > date('Y-m-d', strtotime($rec['limit_date']))) {
                DB::query("UPDATE acc_recurrings SET status = 'ended' WHERE id = ?", [(int)$rec['id']]);
                continue;
            }

            $source = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [(int)$rec['recurable_id']]);
            if (!$source) continue;

            $newId = self::tx(function () use ($source, $rec, $next) {
                $number = Acc::reserveNumber($source['type']);
                $termDays = max(1, (int)((strtotime($source['due_at']) - strtotime($source['issued_at'])) / 86400));
                $docId = DB::insert('acc_documents', [
                    'type' => $source['type'],
                    'number' => $number,
                    'order_number' => $source['order_number'],
                    'status' => 'draft',
                    'issued_at' => $next,
                    'due_at' => date('Y-m-d', strtotime("+$termDays days", strtotime($next))),
                    'amount' => 0,
                    'paid_amount' => 0,
                    'currency_code' => $source['currency_code'],
                    'contact_id' => $source['contact_id'],
                    'category_id' => $source['category_id'],
                    'notes' => $source['notes'],
                    'terms' => $source['terms'],
                    'parent_id' => (int)$source['id'],
                ]);

                $srcItems = DB::fetchAll("SELECT * FROM acc_document_items WHERE document_id = ? ORDER BY id", [(int)$source['id']]);
                $payload = [];
                foreach ($srcItems as $si) {
                    $taxIds = DB::fetchAll("SELECT tax_id FROM acc_document_item_taxes WHERE document_item_id = ?", [(int)$si['id']]);
                    $payload[] = [
                        'name' => $si['name'], 'sku' => $si['sku'], 'item_id' => $si['item_id'],
                        'quantity' => $si['quantity'], 'price' => $si['price'],
                        'tax_ids' => array_map(fn($t) => (int)$t['tax_id'], $taxIds),
                    ];
                }
                Acc::writeDocumentLines($docId, $payload);
                Acc::addHistory($docId, 'draft', 'Generated from recurring schedule #' . $rec['id']);

                // auto_send also posts to the ledger, matching the source app.
                if ((int)$rec['auto_send'] === 1) {
                    DB::query("UPDATE acc_documents SET status = ? WHERE id = ?",
                        [$source['type'] === 'invoice' ? 'sent' : 'open', $docId]);
                    $fresh = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [$docId]);
                    if ($source['type'] === 'invoice') Acc::postInvoiceIssued($fresh);
                    else Acc::postBillReceived($fresh);
                    Acc::addHistory($docId, $source['type'] === 'invoice' ? 'sent' : 'open', 'Auto-sent by recurring schedule');
                }

                DB::query(
                    "UPDATE acc_recurrings SET last_ran_at = ?, occurrences = occurrences + 1 WHERE id = ?",
                    [$next . ' 00:00:00', (int)$rec['id']]
                );
                return $docId;
            });

            $generated[] = ['schedule_id' => (int)$rec['id'], 'document_id' => (int)$newId];
        }

        jsonResponse(['ok' => true, 'generated' => $generated, 'count' => count($generated)]);
    }

    /* ============================================================
     * Reports — every figure computed live
     * ============================================================ */

    /* ============================================================
     * Attachments — bank statements, supplier PDFs, remittance advice
     * ============================================================ */

    /** Attachments hanging off one record, newest first. */
    private static function attachmentsFor($type, $id)
    {
        try {
            return DB::fetchAll(
                "SELECT a.id, a.name, a.mime, a.size, a.created_at, u.name AS uploaded_by_name
                   FROM acc_attachments a
              LEFT JOIN users u ON u.id = a.uploaded_by
                  WHERE a.attachable_type = ? AND a.attachable_id = ?
               ORDER BY a.id DESC",
                [$type, (int)$id]
            );
        } catch (\Throwable $e) {
            error_log('AccountingController::attachmentsFor: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate an attachment target and enforce the module grant that owns it.
     * A bill PDF is gated by acc.bills, a statement by acc.banking — so the
     * attachment surface can never widen someone's access.
     */
    private static function attachableGuard($type, $id)
    {
        $type = Acc::enum((string)$type, AccSchema::ATTACHABLE, '');
        $id   = (int)$id;
        if ($type === '' || $id <= 0) jsonError('Unknown attachment target');

        if ($type === 'document') {
            $doc = DB::fetch("SELECT id, type FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$doc) jsonError('Document not found', 404);
            Authz::requireAccModule(self::docModule($doc['type']));
        } elseif ($type === 'reconciliation') {
            if (!DB::fetch("SELECT id FROM acc_reconciliations WHERE id = ? AND deleted_at IS NULL", [$id])) {
                jsonError('Reconciliation not found', 404);
            }
            Authz::requireAccModule('acc.banking');
        } elseif ($type === 'bank_import') {
            if (!DB::fetch("SELECT id FROM acc_bank_imports WHERE id = ? AND deleted_at IS NULL", [$id])) {
                jsonError('Statement import not found', 404);
            }
            Authz::requireAccModule('acc.banking');
        } else {
            if (!DB::fetch("SELECT id FROM acc_transactions WHERE id = ? AND deleted_at IS NULL", [$id])) {
                jsonError('Transaction not found', 404);
            }
            Authz::requireAccModule('acc.banking');
        }
        return [$type, $id];
    }

    /**
     * Read an uploaded bill and hand back a draft for review.
     *
     * The file is parked in the attachment directory but no attachment row and
     * no document are created — a bill only exists once a person has looked at
     * what was read off the page and pressed save. The staged path comes back so
     * the save step can attach the original to the document it creates.
     */
    public static function extractBill()
    {
        AccSchema::ensure();
        if (!Authz::hasModuleAccess('acc.bills')) jsonError('You do not have access to Bills', 403);

        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $file = $_FILES['file'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            jsonError('That file is larger than this server accepts (limit ' . ini_get('upload_max_filesize') . ').');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload failed');
        if ($file['size'] <= 0) jsonError('That file is empty');
        if ($file['size'] > 12 * 1024 * 1024) jsonError('Bills up to 12MB can be read. Try a smaller scan or a photo.');

        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = strtolower((string)($file['type'] ?? ''));
        $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'heic', 'heif'];
        if (!in_array($ext, $allowedExt, true)) {
            jsonError('Upload a PDF or a photo of the bill (PDF, PNG, JPG, WEBP).');
        }
        // Trust the extension over the browser-declared type, which is often blank.
        $mimeByExt = [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
            'heic' => 'image/heic', 'heif' => 'image/heif',
        ];
        $mime = $mimeByExt[$ext];

        $dir = AccSchema::attachmentDir();
        if (!is_dir($dir) || !is_writable($dir)) jsonError('Attachment storage is not writable on the server');

        $safe   = ltrim(mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']), 0, 120), '.');
        $unique = 'billscan_' . Auth::userId() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        $full   = $dir . '/' . $unique;
        if (!move_uploaded_file($file['tmp_name'], $full)) jsonError('Failed to save the file');
        @chmod($full, 0644);

        try {
            $draft = BillExtractor::extract($full, $mime, Auth::userId());
            $draft = BillExtractor::match($draft);
        } catch (\Throwable $e) {
            @unlink($full); // nothing was created, so leave nothing behind
            jsonError($e->getMessage(), 422);
        }

        $draft['staged_path'] = AccSchema::ATTACHMENT_DIR . '/' . $unique;
        $draft['staged_name'] = mb_substr($file['name'], 0, 255);
        $draft['staged_mime'] = $mime;
        $draft['staged_size'] = (int)$file['size'];

        jsonResponse(['ok' => true, 'draft' => $draft]);
    }

    /**
     * Attach a file staged by extractBill() to the document it became.
     * Called after the draft is saved; a failure here must not lose the bill.
     */
    public static function attachStaged($documentId, $path, $name, $mime, $size)
    {
        try {
            $dir  = AccSchema::attachmentDir();
            $real = realpath($dir . '/' . basename($path));
            if (!$real || strpos($real, realpath($dir)) !== 0 || !is_file($real)) return null;

            $id = DB::insert('acc_attachments', [
                'attachable_type' => 'document',
                'attachable_id'   => (int)$documentId,
                'name'            => $name,
                'path'            => AccSchema::ATTACHMENT_DIR . '/' . basename($real),
                'mime'            => $mime,
                'size'            => (int)$size,
                'uploaded_by'     => Auth::userId(),
            ]);
            Acc::addHistory((int)$documentId, 'attachment', 'Attached ' . $name);
            return (int)$id;
        } catch (\Throwable $e) {
            error_log('attachStaged: ' . $e->getMessage());
            return null;
        }
    }

    /** Create a vendor from a scanned bill, when none matched. */
    public static function createVendorFromDraft()
    {
        AccSchema::ensure();
        if (!Authz::hasModuleAccess('acc.bills') && !Authz::hasModuleAccess('acc.vendors')) {
            jsonError('You do not have access to Vendors', 403);
        }
        $data = input();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') jsonError('A vendor name is required');

        $existing = DB::fetch("SELECT id FROM acc_contacts WHERE type = 'vendor' AND deleted_at IS NULL AND LOWER(name) = LOWER(?)", [$name]);
        if ($existing) jsonResponse(['ok' => true, 'id' => (int)$existing['id'], 'existed' => true]);

        $id = DB::insert('acc_contacts', [
            'type'          => 'vendor',
            'name'          => mb_substr($name, 0, 191),
            'email'         => Acc::strOrNull($data['email'] ?? null, 191),
            'tax_number'    => Acc::strOrNull($data['tax_number'] ?? null, 100),
            'currency_code' => Acc::strOrNull($data['currency_code'] ?? null, 8) ?: Acc::setting('default_currency', 'USD'),
            'enabled'       => 1,
        ]);
        jsonResponse(['ok' => true, 'id' => (int)$id, 'existed' => false]);
    }

    public static function uploadAttachment()
    {
        AccSchema::ensure();
        if (!Authz::hasAnyAccAccess()) jsonError('You do not have access to the Accounting & Finance app', 403);

        list($type, $targetId) = self::attachableGuard(
            $_POST['attachable_type'] ?? '', $_POST['attachable_id'] ?? 0
        );

        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload failed');
        if ($file['size'] <= 0) jsonError('That file is empty');
        if ($file['size'] > 25 * 1024 * 1024) jsonError('File too large (max 25MB)');

        // Two different dangers here. The first group is anything the SERVER could
        // be tricked into executing. The second is anything the BROWSER will
        // execute when the file is later served back inline from this origin —
        // an SVG is a script container, not a picture, and it was previously
        // accepted and rendered inline, which is same-origin script execution.
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $blocked = ['php','php3','php4','php5','php7','php8','phtml','phar','cgi','pl','py','sh','bash',
                    'htaccess','htpasswd','exe','bat','cmd','com','js','jsp','asp','aspx',
                    'svg','svgz','html','htm','xhtml','xht','xml','xsl','xslt','swf','mhtml','mht'];
        if ($ext === '' || in_array($ext, $blocked, true)) jsonError('That file type is not allowed');

        $dir = AccSchema::attachmentDir();
        if (!is_dir($dir) || !is_writable($dir)) jsonError('Attachment storage is not writable on the server');

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
        $safe = ltrim(mb_substr($safe, 0, 120), '.');
        $unique = $type . '_' . $targetId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        $full = $dir . '/' . $unique;

        if (!move_uploaded_file($file['tmp_name'], $full)) jsonError('Failed to save the file');
        @chmod($full, 0644);

        $id = DB::insert('acc_attachments', [
            'attachable_type' => $type,
            'attachable_id'   => $targetId,
            'name'            => mb_substr($file['name'], 0, 255),
            'path'            => AccSchema::ATTACHMENT_DIR . '/' . $unique,
            // Derived from the bytes, never from $file['type'] — that field is
            // whatever the client claimed, and it is echoed straight back as the
            // Content-Type on download.
            'mime'            => self::sniffMime($full, $file['type'] ?? null),
            'size'            => (int)$file['size'],
            'uploaded_by'     => Auth::userId(),
        ]);

        if ($type === 'document') Acc::addHistory($targetId, 'attachment', 'Attached ' . $file['name']);

        jsonResponse(['ok' => true, 'id' => (int)$id, 'attachments' => self::attachmentsFor($type, $targetId)]);
    }

    /** The only types ever rendered in the browser rather than downloaded. */
    private const INLINE_SAFE_MIMES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
        'application/pdf', 'text/plain',
    ];

    /**
     * The file's real MIME type, read from its bytes.
     *
     * $_FILES['x']['type'] is supplied by the browser and is trivially forged,
     * so a caller could label a script-bearing SVG as anything they liked — or
     * label anything at all as image/svg+xml and have it served back inline from
     * this origin. finfo reads the actual content. The claimed type is only used
     * as a last resort when finfo is unavailable, and even then anything outside
     * the inline allowlist simply downloads instead of rendering.
     */
    private static function sniffMime($path, $claimed = null)
    {
        $mime = null;
        if (function_exists('finfo_open') && is_file($path)) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = @finfo_file($fi, $path) ?: null; @finfo_close($fi); }
        }
        if (!$mime) $mime = Acc::strOrNull($claimed, 120);
        $mime = strtolower(trim((string)$mime));
        // finfo reports SVG as image/svg+xml or text/xml depending on the build;
        // either way it must never be presented as a renderable image.
        if ($mime === '' ) return 'application/octet-stream';
        if (strpos($mime, 'svg') !== false) return 'application/octet-stream';
        return substr($mime, 0, 120);
    }

    public static function downloadAttachment($id)
    {
        AccSchema::ensure();
        if (!Authz::hasAnyAccAccess()) jsonError('You do not have access to the Accounting & Finance app', 403);

        $row = DB::fetch("SELECT * FROM acc_attachments WHERE id = ?", [(int)$id]);
        if (!$row) jsonError('Attachment not found', 404);
        self::attachableGuard($row['attachable_type'], $row['attachable_id']);

        $dir  = realpath(AccSchema::attachmentDir());
        $full = realpath(dirname(dirname(__DIR__)) . '/' . ltrim($row['path'], '/'));
        // Refuse anything that resolves outside the attachment directory.
        if (!$dir || !$full || strpos($full, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
            jsonError('That file is no longer on the server', 404);
        }

        $inline = isset($_GET['inline']) && $_GET['inline'] === '1';
        // Trust the bytes on the way out too, not the stored string — rows
        // written before MIME sniffing landed still carry a client-supplied value.
        $mime = self::sniffMime($full, $row['mime']);
        // An explicit allowlist, not a `^image/` prefix: that prefix also matched
        // image/svg+xml, which the browser runs as script on this origin.
        if ($inline && !in_array(strtolower($mime), self::INLINE_SAFE_MIMES, true)) $inline = false;

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . str_replace('"', '', $row['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        readfile($full);
        exit;
    }

    public static function deleteAttachment($id)
    {
        AccSchema::ensure();
        if (!Authz::hasAnyAccAccess()) jsonError('You do not have access to the Accounting & Finance app', 403);

        $row = DB::fetch("SELECT * FROM acc_attachments WHERE id = ?", [(int)$id]);
        if (!$row) jsonError('Attachment not found', 404);
        self::attachableGuard($row['attachable_type'], $row['attachable_id']);

        $dir  = realpath(AccSchema::attachmentDir());
        $full = realpath(dirname(dirname(__DIR__)) . '/' . ltrim($row['path'], '/'));
        if ($dir && $full && strpos($full, $dir . DIRECTORY_SEPARATOR) === 0 && is_file($full)) @unlink($full);

        DB::query("DELETE FROM acc_attachments WHERE id = ?", [(int)$row['id']]);
        if ($row['attachable_type'] === 'document') {
            Acc::addHistory($row['attachable_id'], 'attachment', 'Removed ' . $row['name']);
        }

        jsonResponse(['ok' => true, 'attachments' => self::attachmentsFor($row['attachable_type'], $row['attachable_id'])]);
    }

    public static function reports()
    {
        self::boot('acc.reports');
        $year = (int)($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');

        // Filing period. Defaults to the whole year, so callers that only send
        // ?year= get exactly the same numbers as before.
        $period = Acc::enum($_GET['period'] ?? 'year', ['year', 'q1', 'q2', 'q3', 'q4'], 'year');
        $qStart = ['year' => 1, 'q1' => 1, 'q2' => 4, 'q3' => 7, 'q4' => 10][$period];
        $qMonths = $period === 'year' ? 12 : 3;
        $from = sprintf('%04d-%02d-01', $year, $qStart);
        $to   = date('Y-m-t', mktime(0, 0, 0, $qStart + $qMonths - 1, 1, $year));

        // Accrual recognises tax when the document is issued; cash recognises it
        // when the money actually arrives. Most US sales-tax filings are cash.
        $basis = Acc::enum($_GET['basis'] ?? 'accrual', ['accrual', 'cash'], 'accrual');

        // ── Profit & Loss (operational transactions only) ──
        $revenue = DB::fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(t.amount) AS total
               FROM acc_transactions t
          LEFT JOIN acc_categories c ON c.id = t.category_id
              WHERE t.deleted_at IS NULL AND t.is_transfer = 0 AND t.type = 'income' AND t.paid_at BETWEEN ? AND ?
           GROUP BY COALESCE(c.name, 'Uncategorized') ORDER BY total DESC",
            [$from, $to]
        );
        $expenses = DB::fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(t.amount) AS total
               FROM acc_transactions t
          LEFT JOIN acc_categories c ON c.id = t.category_id
              WHERE t.deleted_at IS NULL AND t.is_transfer = 0 AND t.type = 'expense' AND t.paid_at BETWEEN ? AND ?
           GROUP BY COALESCE(c.name, 'Uncategorized') ORDER BY total DESC",
            [$from, $to]
        );
        $totalRevenue = 0; foreach ($revenue as $r) $totalRevenue += Acc::num($r['total']);
        $totalExpense = 0; foreach ($expenses as $r) $totalExpense += Acc::num($r['total']);

        // ── Tax summary ──
        // Accrual: the tax on every document issued in the period.
        // Cash: each document's tax allocated pro-rata to the money actually
        //       received against it in the period (amount paid ÷ document total).
        if ($basis === 'cash') {
            $taxSql =
                "SELECT tx.name, tx.rate,
                        SUM(dit.amount * (t.amount / NULLIF(d.amount, 0))) AS total
                   FROM acc_transactions t
                   JOIN acc_documents d ON d.id = t.document_id
                   JOIN acc_document_items di ON di.document_id = d.id
                   JOIN acc_document_item_taxes dit ON dit.document_item_id = di.id
                   JOIN acc_taxes tx ON tx.id = dit.tax_id
                  WHERE t.deleted_at IS NULL AND t.is_transfer = 0
                    AND d.deleted_at IS NULL AND d.status <> 'cancelled' AND d.type = ?
                    AND t.paid_at BETWEEN ? AND ?
               GROUP BY tx.id, tx.name, tx.rate ORDER BY total DESC";
        } else {
            $taxSql =
                "SELECT tx.name, tx.rate, SUM(dit.amount) AS total
                   FROM acc_document_item_taxes dit
                   JOIN acc_document_items di ON di.id = dit.document_item_id
                   JOIN acc_documents d ON d.id = di.document_id
                   JOIN acc_taxes tx ON tx.id = dit.tax_id
                  WHERE d.deleted_at IS NULL AND d.type = ? AND d.status <> 'cancelled'
                    AND d.issued_at BETWEEN ? AND ?
               GROUP BY tx.id, tx.name, tx.rate ORDER BY total DESC";
        }
        $collected = DB::fetchAll($taxSql, ['invoice', $from, $to]);
        $paidTax   = DB::fetchAll($taxSql, ['bill', $from, $to]);
        $totalCollected = 0; foreach ($collected as $r) $totalCollected += Acc::num($r['total']);
        $totalPaidTax = 0;  foreach ($paidTax as $r)  $totalPaidTax += Acc::num($r['total']);

        // ── Trial balance ──
        $coa = DB::fetchAll("SELECT * FROM acc_chart_of_accounts WHERE deleted_at IS NULL ORDER BY code");
        $trial = []; $tDebit = 0; $tCredit = 0;
        foreach ($coa as $row) {
            $bal = Acc::num($row['balance']);
            $d = $row['side'] === 'debit' ? max(0, $bal) : max(0, -$bal);
            $c = $row['side'] === 'credit' ? max(0, $bal) : max(0, -$bal);
            $tDebit += $d; $tCredit += $c;
            $trial[] = ['code' => $row['code'], 'name' => $row['name'], 'debit' => Acc::money($d), 'credit' => Acc::money($c)];
        }

        // ── Cash flow by month ──
        $months = []; $inTotal = 0; $outTotal = 0;
        for ($m = 1; $m <= 12; $m++) {
            $row = DB::fetch(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
                   FROM acc_transactions
                  WHERE deleted_at IS NULL AND is_transfer = 0 AND YEAR(paid_at) = ? AND MONTH(paid_at) = ?",
                [$year, $m]
            );
            $in = Acc::money($row['income'] ?? 0);
            $out = Acc::money($row['expense'] ?? 0);
            $inTotal += $in; $outTotal += $out;
            $months[] = ['month' => date('M', mktime(0, 0, 0, $m, 1, $year)), 'income' => $in, 'expense' => $out, 'net' => Acc::money($in - $out)];
        }

        // ── Aged receivables / payables ──
        $aging = [];
        foreach (['invoice' => 'receivable', 'bill' => 'payable'] as $docType => $label) {
            $aging[$label] = DB::fetch(
                "SELECT
                   COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_at) <= 0  THEN amount - paid_amount END), 0) AS current_amt,
                   COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_at) BETWEEN 1 AND 30  THEN amount - paid_amount END), 0) AS d30,
                   COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_at) BETWEEN 31 AND 60 THEN amount - paid_amount END), 0) AS d60,
                   COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_at) > 60 THEN amount - paid_amount END), 0) AS d90
                 FROM acc_documents
                WHERE deleted_at IS NULL AND type = ? AND status NOT IN ('paid','cancelled','draft')",
                [$docType]
            );
        }

        // ── Sales analysis: by location, by agent, by type ──
        // All three are invoice-based (what was sold), not cash-based, and all
        // group by the NORMALISED label so NULL and '' never split into two rows.
        $salesWhere = "d.deleted_at IS NULL AND d.type = 'invoice'
                       AND d.status NOT IN ('cancelled','draft') AND d.issued_at BETWEEN ? AND ?";
        $salesParams = [$from, $to];

        $byCountry = DB::fetchAll(
            "SELECT COALESCE(NULLIF(TRIM(c.country), ''), 'Unspecified') AS name,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(d.amount), 0) AS total,
                    COALESCE(SUM(d.paid_amount), 0) AS collected
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE $salesWhere
           GROUP BY COALESCE(NULLIF(TRIM(c.country), ''), 'Unspecified')
           ORDER BY total DESC",
            $salesParams
        );
        $byRegion = DB::fetchAll(
            "SELECT COALESCE(NULLIF(TRIM(c.state), ''), 'Unspecified') AS name,
                    COALESCE(NULLIF(TRIM(c.country), ''), 'Unspecified') AS country,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(d.amount), 0) AS total,
                    COALESCE(SUM(d.paid_amount), 0) AS collected
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE $salesWhere
           GROUP BY COALESCE(NULLIF(TRIM(c.state), ''), 'Unspecified'),
                    COALESCE(NULLIF(TRIM(c.country), ''), 'Unspecified')
           ORDER BY total DESC",
            $salesParams
        );
        $byAgent = DB::fetchAll(
            "SELECT d.user_id, COALESCE(u.name, 'Unassigned') AS name,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(d.amount), 0) AS total,
                    COALESCE(SUM(d.paid_amount), 0) AS collected,
                    COALESCE(SUM(d.amount - d.paid_amount), 0) AS outstanding
               FROM acc_documents d
          LEFT JOIN users u ON u.id = d.user_id
              WHERE $salesWhere
           GROUP BY d.user_id, COALESCE(u.name, 'Unassigned')
           ORDER BY total DESC",
            $salesParams
        );
        $byType = DB::fetchAll(
            "SELECT COALESCE(NULLIF(TRIM(cat.name), ''), 'Uncategorized') AS name,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(d.amount), 0) AS total,
                    COALESCE(SUM(d.paid_amount), 0) AS collected
               FROM acc_documents d
          LEFT JOIN acc_categories cat ON cat.id = d.category_id
              WHERE $salesWhere
           GROUP BY COALESCE(NULLIF(TRIM(cat.name), ''), 'Uncategorized')
           ORDER BY total DESC",
            $salesParams
        );
        $salesTotal = 0; foreach ($byCountry as $r) $salesTotal += Acc::num($r['total']);

        $years = DB::fetchAll(
            "SELECT DISTINCT YEAR(paid_at) AS y FROM acc_transactions WHERE deleted_at IS NULL ORDER BY y DESC"
        );
        $yearList = array_map(fn($r) => (int)$r['y'], $years);
        if (!in_array((int)date('Y'), $yearList, true)) array_unshift($yearList, (int)date('Y'));
        if (!in_array($year, $yearList, true)) $yearList[] = $year;
        rsort($yearList);

        jsonResponse([
            'year' => $year,
            'years' => $yearList,
            'period' => $period,
            'basis' => $basis,
            'from' => $from,
            'to' => $to,
            'sales' => [
                'by_country' => $byCountry,
                'by_region'  => $byRegion,
                'by_agent'   => $byAgent,
                'by_type'    => $byType,
                'total'      => Acc::money($salesTotal),
            ],
            'profit_loss' => [
                'revenue' => $revenue,
                'expenses' => $expenses,
                'total_revenue' => Acc::money($totalRevenue),
                'total_expense' => Acc::money($totalExpense),
                'net_income' => Acc::money($totalRevenue - $totalExpense),
            ],
            'tax' => [
                'collected' => $collected,
                'paid' => $paidTax,
                'total_collected' => Acc::money($totalCollected),
                'total_paid' => Acc::money($totalPaidTax),
                'net_tax' => Acc::money($totalCollected - $totalPaidTax),
            ],
            'trial_balance' => [
                'rows' => $trial,
                'total_debit' => Acc::money($tDebit),
                'total_credit' => Acc::money($tCredit),
                'balanced' => abs($tDebit - $tCredit) < 0.01,
            ],
            'cash_flow' => [
                'months' => $months,
                'total_in' => Acc::money($inTotal),
                'total_out' => Acc::money($outTotal),
                'net' => Acc::money($inTotal - $outTotal),
            ],
            'aging' => $aging,
        ]);
    }

    /* ============================================================
     * Settings, seeding, reset
     * ============================================================ */

    public static function settings()
    {
        self::boot('acc.settings');
        jsonResponse([
            'settings' => self::companySettings(),
            'counts' => self::dataCounts(),
            'seeded' => Acc::setting('seed_version', '') === AccSchema::SEED_VERSION,
        ]);
    }

    public static function updateSettings()
    {
        self::boot('acc.settings');
        $data = input();
        $allowed = [
            'company_name', 'company_ein', 'company_address', 'company_phone', 'company_email',
            'company_website', 'fiscal_year_start', 'default_currency',
            'invoice_prefix', 'invoice_next_number', 'bill_prefix', 'bill_next_number',
            'default_payment_terms', 'invoice_footer',
        ];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) Acc::setSetting($key, (string)$data[$key]);
        }
        Acc::flushSettings();
        jsonResponse(['ok' => true, 'settings' => self::companySettings()]);
    }

    public static function recalcBalances()
    {
        Authz::requireAccAdmin();
        AccSchema::ensure();
        $a = Acc::recalcAllAccounts();
        $c = Acc::recalcAllCoa();
        jsonResponse(['ok' => true, 'accounts' => $a, 'chart_of_accounts' => $c]);
    }

    private static function dataCounts()
    {
        $out = [];
        foreach (AccSchema::BUSINESS_TABLES as $t) {
            try {
                $row = DB::fetch("SELECT COUNT(*) AS n FROM `$t`");
                $out[$t] = (int)($row['n'] ?? 0);
            } catch (\Throwable $e) { $out[$t] = 0; }
        }
        return $out;
    }

    /** Public counts endpoint used by the main VGo settings Danger Zone. */
    public static function dataSummary()
    {
        AccSchema::ensure();
        Authz::requireAccAdmin();
        jsonResponse([
            'counts' => self::dataCounts(),
            'seeded' => Acc::setting('seed_version', '') === AccSchema::SEED_VERSION,
        ]);
    }

    /**
     * Clear all accounting data. Requires the caller to type CLEAR ACCOUNTING
     * and re-enter their password — the same shape as VGo's master reset.
     */
    public static function resetData()
    {
        AccSchema::ensure();
        Authz::requireAccAdmin();
        $data = input();

        // Typing DELETE is the guard. Surrounding dashes or spaces are forgiven
        // because the prompt shows the word emphasised; the older phrase still
        // works so nothing that already scripted this breaks.
        $confirm = strtoupper(trim((string)($data['confirm_text'] ?? ''), " \t\n\r-–—_"));
        if ($confirm !== 'DELETE' && $confirm !== 'CLEAR ACCOUNTING') {
            jsonError('Type DELETE to confirm.');
        }
        $user = Auth::user();
        $password = (string)($data['password'] ?? '');
        // Microsoft-authenticated accounts have no local password to verify.
        if (($user['auth_provider'] ?? 'password') === 'password') {
            if ($password === '' || empty($user['password']) || !password_verify($password, $user['password'])) {
                jsonError('Password is incorrect');
            }
        }

        $mode = ($data['mode'] ?? 'fresh') === 'sample' ? 'sample' : 'fresh';
        $deleted = AccSchema::wipe(false);
        if ($mode === 'sample') AccSeed::run();

        Acc::flushSettings();
        jsonResponse(['ok' => true, 'mode' => $mode, 'deleted' => $deleted, 'counts' => self::dataCounts()]);
    }

    /** Load the bundled demo dataset (only when the app is empty). */
    public static function seed()
    {
        AccSchema::ensure();
        Authz::requireAccAdmin();
        if (!AccSchema::isEmpty()) jsonError('Accounting already contains data. Clear it first.');
        AccSeed::run();
        Acc::flushSettings();
        jsonResponse(['ok' => true, 'counts' => self::dataCounts()]);
    }

    /* ============================================================
     * CRM / Workflow integration
     * ============================================================ */

    /** Search CRM leads that are not yet linked to an accounting customer. */
    public static function crmLeadSearch()
    {
        self::boot('acc.customers');
        $search = trim((string)($_GET['search'] ?? ''));
        if (mb_strlen($search) < 2) jsonResponse(['leads' => []]);
        $like = '%' . $search . '%';
        try {
            $leads = DB::fetchAll(
                "SELECT l.id, l.company_name, l.contact_name, l.email, l.phone, l.country, l.status
                   FROM crm_leads l
                  WHERE (l.company_name LIKE ? OR l.contact_name LIKE ? OR l.email LIKE ?)
               ORDER BY l.id DESC LIMIT 20",
                [$like, $like, $like]
            );
        } catch (\Throwable $e) {
            $leads = [];
        }
        $linked = DB::fetchAll("SELECT crm_lead_id FROM acc_contacts WHERE crm_lead_id IS NOT NULL AND deleted_at IS NULL");
        $linkedIds = array_map(fn($r) => (int)$r['crm_lead_id'], $linked);
        foreach ($leads as &$l) $l['already_linked'] = in_array((int)$l['id'], $linkedIds, true);
        unset($l);
        jsonResponse(['leads' => $leads]);
    }

    /** Convert a CRM lead into an accounting customer, keeping the link. */
    public static function importCrmLead()
    {
        self::boot('acc.customers');
        $data = input();
        $leadId = (int)($data['lead_id'] ?? 0);
        if (!$leadId) jsonError('Choose a CRM lead');

        $existing = DB::fetch("SELECT id FROM acc_contacts WHERE crm_lead_id = ? AND deleted_at IS NULL", [$leadId]);
        if ($existing) jsonResponse(['ok' => true, 'id' => (int)$existing['id'], 'existing' => true]);

        try {
            $lead = DB::fetch("SELECT * FROM crm_leads WHERE id = ?", [$leadId]);
        } catch (\Throwable $e) {
            $lead = null;
        }
        if (!$lead) jsonError('CRM lead not found', 404);

        $name = Acc::strOrNull($lead['company_name'] ?? null) ?: Acc::strOrNull($lead['contact_name'] ?? null);
        if (!$name) jsonError('That lead has no company or contact name');

        $id = self::tx(function () use ($lead, $leadId, $name) {
            $contactId = DB::insert('acc_contacts', [
                'type' => 'customer',
                'name' => $name,
                'email' => Acc::strOrNull($lead['email'] ?? null),
                'phone' => Acc::strOrNull($lead['phone'] ?? null, 64),
                'country' => Acc::strOrNull($lead['country'] ?? null, 120),
                'currency_code' => Acc::setting('default_currency', 'USD'),
                'crm_lead_id' => $leadId,
                'enabled' => 1,
            ]);
            $person = Acc::strOrNull($lead['contact_name'] ?? null);
            if ($person && $person !== $name) {
                DB::insert('acc_contact_people', [
                    'contact_id' => $contactId,
                    'name' => $person,
                    'email' => Acc::strOrNull($lead['email'] ?? null),
                    'phone' => Acc::strOrNull($lead['phone'] ?? null, 64),
                    'position' => 'CRM contact',
                ]);
            }
            return $contactId;
        });

        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }
}
