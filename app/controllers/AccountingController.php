<?php
/**
 * AccountingController — native Accounting & Finance app inside VGold.
 *
 * A faithful port of the VGACC Laravel application onto VGold's own stack:
 * plain PHP + the DB:: PDO helper, served under /api/acc/*, sharing VGold's
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
            'options'   => self::optionsPayload(),
        ]);
    }

    private static function optionsPayload()
    {
        return [
            'accounts'   => DB::fetchAll("SELECT id, name, bank_name, number, type, balance, currency_code, color FROM acc_accounts WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name"),
            'customers'  => DB::fetchAll("SELECT id, name, email FROM acc_contacts WHERE deleted_at IS NULL AND type = 'customer' ORDER BY name"),
            'vendors'    => DB::fetchAll("SELECT id, name, email, category FROM acc_contacts WHERE deleted_at IS NULL AND type = 'vendor' ORDER BY name"),
            'taxes'      => DB::fetchAll("SELECT id, name, rate, type FROM acc_taxes WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name"),
            'categories' => DB::fetchAll("SELECT id, name, type, color, parent_id FROM acc_categories WHERE deleted_at IS NULL ORDER BY type, name"),
            'items'      => DB::fetchAll("SELECT id, name, sku, sale_price, purchase_price, type FROM acc_items WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name"),
            'coa'        => DB::fetchAll("SELECT id, code, name, type, side FROM acc_chart_of_accounts WHERE deleted_at IS NULL AND enabled = 1 ORDER BY code"),
        ];
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

        jsonResponse([
            'document' => $doc,
            'contact' => $contact,
            'items' => $items,
            'totals' => DB::fetchAll("SELECT * FROM acc_document_totals WHERE document_id = ? ORDER BY sort_order", [(int)$doc['id']]),
            'histories' => DB::fetchAll("SELECT * FROM acc_document_histories WHERE document_id = ? ORDER BY id DESC", [(int)$doc['id']]),
            'payments' => DB::fetchAll(
                "SELECT t.*, a.name AS account_name FROM acc_transactions t
              LEFT JOIN acc_accounts a ON a.id = t.account_id
                  WHERE t.document_id = ? AND t.deleted_at IS NULL ORDER BY t.paid_at DESC, t.id DESC",
                [(int)$doc['id']]
            ),
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
            ]);

            Acc::writeDocumentLines($docId, $data['items']);
            Acc::addHistory($docId, 'draft', ucfirst($type) . ' created');
            return $docId;
        });

        jsonResponse(['ok' => true, 'id' => (int)$result, 'document' => DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$result])]);
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
            ];
            DB::query(
                "UPDATE acc_documents SET contact_id = ?, order_number = ?, issued_at = ?, due_at = ?,
                        category_id = ?, notes = ?, terms = ? WHERE id = ?",
                [$fields['contact_id'], $fields['order_number'], $fields['issued_at'], $fields['due_at'],
                 $fields['category_id'], $fields['notes'], $fields['terms'], (int)$doc['id']]
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

        $balance = Acc::money(Acc::num($doc['amount']) - Acc::num($doc['paid_amount']));
        if ($amount > $balance + 0.005) jsonError('Payment exceeds the outstanding balance of ' . number_format($balance, 2));

        $accountId = (int)($data['account_id'] ?? 0);
        $account = DB::fetch("SELECT * FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$accountId]);
        if (!$account) jsonError('Select the account the money moved through');

        $paidAt = Acc::date($data['paid_at'] ?? null, date('Y-m-d'));

        self::tx(function () use ($doc, $data, $amount, $accountId, $paidAt) {
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
            ]);

            $fresh = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$doc['id']]);
            if ($doc['type'] === 'invoice') Acc::postInvoicePayment($fresh, $amount, $paidAt, $txId);
            else Acc::postBillPayment($fresh, $amount, $paidAt, $txId);

            Acc::recalcAccount($accountId);
            Acc::syncDocumentPaymentState($doc['id']);
            Acc::addHistory($doc['id'], 'payment', 'Payment of ' . number_format($amount, 2) . ' recorded');
        });

        jsonResponse(['ok' => true, 'document' => DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$id])]);
    }

    /* ============================================================
     * Contacts — customers & vendors
     * ============================================================ */

    public static function contacts()
    {
        self::boot('acc.contacts');
        list($page, $per, $offset) = self::page();
        $type = ($_GET['type'] ?? 'customer') === 'vendor' ? 'vendor' : 'customer';
        $search = trim((string)($_GET['search'] ?? ''));

        $where = "c.deleted_at IS NULL AND c.type = ?";
        $params = [$type];
        if ($search !== '') {
            $where .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $docType = $type === 'customer' ? 'invoice' : 'bill';
        $year = (int)date('Y');

        $rows = DB::fetchAll(
            "SELECT c.*,
                COALESCE((SELECT SUM(d.amount - d.paid_amount) FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                      AND d.status NOT IN ('paid','cancelled','draft')), 0) AS open_amount,
                COALESCE((SELECT SUM(d.amount) FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                      AND d.status <> 'cancelled' AND YEAR(d.issued_at) = $year), 0) AS ytd_amount,
                (SELECT d.number FROM acc_documents d
                    WHERE d.contact_id = c.id AND d.deleted_at IS NULL AND d.type = '$docType'
                 ORDER BY d.issued_at DESC, d.id DESC LIMIT 1) AS last_document
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
        self::boot('acc.contacts');
        $contact = DB::fetch("SELECT * FROM acc_contacts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$contact) jsonError('Contact not found', 404);

        $docType = $contact['type'] === 'customer' ? 'invoice' : 'bill';
        $stats = DB::fetch(
            "SELECT
               COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN amount END), 0) AS total,
               COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN paid_amount END), 0) AS paid,
               COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled','draft') THEN amount - paid_amount END), 0) AS outstanding
             FROM acc_documents WHERE contact_id = ? AND deleted_at IS NULL AND type = ?",
            [(int)$id, $docType]
        );

        jsonResponse([
            'contact' => $contact,
            'people' => DB::fetchAll("SELECT * FROM acc_contact_people WHERE contact_id = ? ORDER BY id", [(int)$id]),
            'stats' => [
                'total' => Acc::money($stats['total'] ?? 0),
                'paid' => Acc::money($stats['paid'] ?? 0),
                'outstanding' => Acc::money($stats['outstanding'] ?? 0),
            ],
            'documents' => DB::fetchAll(
                "SELECT d.*, (SELECT di.name FROM acc_document_items di WHERE di.document_id = d.id ORDER BY di.id LIMIT 1) AS first_item
                   FROM acc_documents d
                  WHERE d.contact_id = ? AND d.deleted_at IS NULL AND d.type = ?
               ORDER BY d.issued_at DESC, d.id DESC LIMIT 50",
                [(int)$id, $docType]
            ),
            'transactions' => DB::fetchAll(
                "SELECT t.*, d.number AS document_number FROM acc_transactions t
              LEFT JOIN acc_documents d ON d.id = t.document_id
                  WHERE t.contact_id = ? AND t.deleted_at IS NULL
               ORDER BY t.paid_at DESC, t.id DESC LIMIT 25",
                [(int)$id]
            ),
        ]);
    }

    private static function contactFields($data, $existing = null)
    {
        $type = Acc::enum($data['type'] ?? ($existing['type'] ?? 'customer'), ['customer', 'vendor'], 'customer');
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
        self::boot('acc.contacts');
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
        self::boot('acc.contacts');
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
        self::boot('acc.contacts');
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

        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $where .= " AND (t.description LIKE ? OR t.reference LIKE ? OR c.name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $rows = DB::fetchAll(
            "SELECT t.*, a.name AS account_name, c.name AS contact_name,
                    cat.name AS category_name, d.number AS document_number, d.type AS document_type
               FROM acc_transactions t
          LEFT JOIN acc_accounts a ON a.id = t.account_id
          LEFT JOIN acc_contacts c ON c.id = t.contact_id
          LEFT JOIN acc_categories cat ON cat.id = t.category_id
          LEFT JOIN acc_documents d ON d.id = t.document_id
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

        jsonResponse(['transactions' => $rows, 'meta' => self::meta($page, $per, $count['n'] ?? 0)]);
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

        $id = DB::insert('acc_transactions', [
            'type' => $type,
            'paid_at' => Acc::date($data['paid_at'] ?? null, date('Y-m-d')),
            'amount' => $amount,
            'currency_code' => Acc::setting('default_currency', 'USD'),
            'account_id' => $accountId,
            'document_id' => Acc::intOrNull($data['document_id'] ?? null),
            'contact_id' => Acc::intOrNull($data['contact_id'] ?? null),
            'category_id' => Acc::intOrNull($data['category_id'] ?? null),
            'description' => $data['description'] ?? null,
            'payment_method' => Acc::strOrNull($data['payment_method'] ?? null, 64),
            'reference' => Acc::strOrNull($data['reference'] ?? null),
            'is_transfer' => 0,
        ]);
        Acc::recalcAccount($accountId);
        if (!empty($data['document_id'])) Acc::syncDocumentPaymentState((int)$data['document_id']);

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

        DB::query(
            "UPDATE acc_transactions SET type = ?, paid_at = ?, amount = ?, account_id = ?, contact_id = ?,
                    category_id = ?, description = ?, payment_method = ?, reference = ? WHERE id = ?",
            [
                Acc::enum($data['type'] ?? $tx['type'], ['income', 'expense'], $tx['type']),
                Acc::date($data['paid_at'] ?? null, $tx['paid_at']),
                $amount,
                $newAccount,
                Acc::intOrNull($data['contact_id'] ?? $tx['contact_id']),
                Acc::intOrNull($data['category_id'] ?? $tx['category_id']),
                array_key_exists('description', $data) ? $data['description'] : $tx['description'],
                Acc::strOrNull($data['payment_method'] ?? $tx['payment_method'], 64),
                Acc::strOrNull($data['reference'] ?? $tx['reference']),
                (int)$id,
            ]
        );
        Acc::recalcAccount($tx['account_id']);
        if ($newAccount !== (int)$tx['account_id']) Acc::recalcAccount($newAccount);
        if ($tx['document_id']) Acc::syncDocumentPaymentState((int)$tx['document_id']);

        jsonResponse(['ok' => true]);
    }

    public static function deleteTransaction($id)
    {
        self::boot('acc.banking');
        $tx = DB::fetch("SELECT * FROM acc_transactions WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$tx) jsonError('Transaction not found', 404);
        if ((int)$tx['is_transfer'] === 1) jsonError('Delete the transfer instead — this row is one half of it.');

        DB::query("UPDATE acc_transactions SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        Acc::recalcAccount($tx['account_id']);
        if ($tx['document_id']) Acc::syncDocumentPaymentState((int)$tx['document_id']);
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

    public static function createReconciliation()
    {
        self::boot('acc.banking');
        $data = input();
        $accountId = (int)($data['account_id'] ?? 0);
        if (!DB::fetch("SELECT id FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$accountId])) jsonError('Select an account');

        $id = DB::insert('acc_reconciliations', [
            'account_id' => $accountId,
            'started_at' => Acc::date($data['started_at'] ?? null, date('Y-m-01')),
            'ended_at' => Acc::date($data['ended_at'] ?? null, date('Y-m-t')),
            'closing_balance' => Acc::money($data['closing_balance'] ?? 0),
            'reconciled' => 0,
        ]);
        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }

    public static function reconciliation($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch(
            "SELECT r.*, a.name AS account_name, a.balance AS account_balance
               FROM acc_reconciliations r
          LEFT JOIN acc_accounts a ON a.id = r.account_id
              WHERE r.id = ? AND r.deleted_at IS NULL",
            [(int)$id]
        );
        if (!$rec) jsonError('Reconciliation not found', 404);

        $unreconciled = DB::fetchAll(
            "SELECT * FROM acc_transactions
              WHERE account_id = ? AND deleted_at IS NULL AND reconciled = 0
                AND paid_at >= ? AND paid_at <= ?
           ORDER BY paid_at ASC, id ASC",
            [(int)$rec['account_id'], $rec['started_at'], $rec['ended_at'] ?: date('Y-m-d')]
        );

        $cleared = DB::fetch(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS v
               FROM acc_transactions
              WHERE account_id = ? AND deleted_at IS NULL AND reconciled = 1
                AND paid_at >= ? AND paid_at <= ?",
            [(int)$rec['account_id'], $rec['started_at'], $rec['ended_at'] ?: date('Y-m-d')]
        );

        jsonResponse([
            'reconciliation' => $rec,
            'unreconciled' => $unreconciled,
            'cleared_total' => Acc::money($cleared['v'] ?? 0),
        ]);
    }

    public static function reconciliationMark($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch("SELECT * FROM acc_reconciliations WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$rec) jsonError('Reconciliation not found', 404);

        $data = input();
        $ids = $data['transaction_ids'] ?? [];
        if (!is_array($ids) || !count($ids)) jsonError('Select at least one transaction');

        $clean = array_values(array_filter(array_map('intval', $ids)));
        if (!$clean) jsonError('Select at least one transaction');
        $in = implode(',', array_fill(0, count($clean), '?'));
        $params = array_merge($clean, [(int)$rec['account_id']]);
        DB::query("UPDATE acc_transactions SET reconciled = 1 WHERE id IN ($in) AND account_id = ?", $params);

        jsonResponse(['ok' => true, 'marked' => count($clean)]);
    }

    public static function reconciliationClose($id)
    {
        self::boot('acc.banking');
        $rec = DB::fetch("SELECT * FROM acc_reconciliations WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$rec) jsonError('Reconciliation not found', 404);
        $data = input();
        DB::query(
            "UPDATE acc_reconciliations SET ended_at = ?, reconciled = 1 WHERE id = ?",
            [Acc::date($data['ended_at'] ?? null, date('Y-m-d')), (int)$id]
        );
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

    public static function reports()
    {
        self::boot('acc.reports');
        $year = (int)($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');

        // ── Profit & Loss (operational transactions only) ──
        $revenue = DB::fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(t.amount) AS total
               FROM acc_transactions t
          LEFT JOIN acc_categories c ON c.id = t.category_id
              WHERE t.deleted_at IS NULL AND t.is_transfer = 0 AND t.type = 'income' AND YEAR(t.paid_at) = ?
           GROUP BY COALESCE(c.name, 'Uncategorized') ORDER BY total DESC",
            [$year]
        );
        $expenses = DB::fetchAll(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(t.amount) AS total
               FROM acc_transactions t
          LEFT JOIN acc_categories c ON c.id = t.category_id
              WHERE t.deleted_at IS NULL AND t.is_transfer = 0 AND t.type = 'expense' AND YEAR(t.paid_at) = ?
           GROUP BY COALESCE(c.name, 'Uncategorized') ORDER BY total DESC",
            [$year]
        );
        $totalRevenue = 0; foreach ($revenue as $r) $totalRevenue += Acc::num($r['total']);
        $totalExpense = 0; foreach ($expenses as $r) $totalExpense += Acc::num($r['total']);

        // ── Tax summary ──
        $collected = DB::fetchAll(
            "SELECT tx.name, tx.rate, SUM(dit.amount) AS total
               FROM acc_document_item_taxes dit
               JOIN acc_document_items di ON di.id = dit.document_item_id
               JOIN acc_documents d ON d.id = di.document_id
               JOIN acc_taxes tx ON tx.id = dit.tax_id
              WHERE d.deleted_at IS NULL AND d.type = 'invoice' AND d.status <> 'cancelled'
                AND YEAR(d.issued_at) = ?
           GROUP BY tx.id, tx.name, tx.rate ORDER BY total DESC",
            [$year]
        );
        $paidTax = DB::fetchAll(
            "SELECT tx.name, tx.rate, SUM(dit.amount) AS total
               FROM acc_document_item_taxes dit
               JOIN acc_document_items di ON di.id = dit.document_item_id
               JOIN acc_documents d ON d.id = di.document_id
               JOIN acc_taxes tx ON tx.id = dit.tax_id
              WHERE d.deleted_at IS NULL AND d.type = 'bill' AND d.status <> 'cancelled'
                AND YEAR(d.issued_at) = ?
           GROUP BY tx.id, tx.name, tx.rate ORDER BY total DESC",
            [$year]
        );
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

    /** Public counts endpoint used by the main VGold settings Danger Zone. */
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
     * and re-enter their password — the same shape as VGold's master reset.
     */
    public static function resetData()
    {
        AccSchema::ensure();
        Authz::requireAccAdmin();
        $data = input();

        if (($data['confirm_text'] ?? '') !== 'CLEAR ACCOUNTING') {
            jsonError('Type CLEAR ACCOUNTING to confirm');
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
        self::boot('acc.contacts');
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
        self::boot('acc.contacts');
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
