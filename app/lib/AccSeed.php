<?php
/**
 * AccSeed — the bundled demo dataset for Accounting & Finance.
 *
 * A direct port of VGACC's DatabaseSeeder: same companies, invoices, bills,
 * chart of accounts and journal entries, so the app looks and behaves exactly
 * like the standalone version on first open. All sample data lives here and
 * nowhere else — clearing data from Settings truly starts from a clean slate.
 */
class AccSeed
{
    public static function run()
    {
        AccSchema::ensure();

        $pdo = DB::conn();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();

        try {
            /* ---------- Company settings ---------- */
            $settings = [
                'company_name' => 'Victory Genomics, Inc.',
                'company_ein' => '87-2214065',
                'company_address' => '410 Carver Way, Suite 300, Boston, MA 02139',
                'company_email' => 'accounting@victorygenomics.com',
                'company_phone' => '',
                'company_website' => '',
                'fiscal_year_start' => '01-01',
                'default_currency' => 'USD',
                'invoice_prefix' => 'INV-',
                'invoice_next_number' => '0150',
                'default_payment_terms' => 'Net 30',
                'invoice_footer' => 'Thank you for working with Victory Genomics.',
                'bill_prefix' => 'BILL-',
                'bill_next_number' => '2078',
                'seed_version' => AccSchema::SEED_VERSION,
            ];
            foreach ($settings as $k => $v) Acc::setSetting($k, $v);

            /* ---------- Tax rates ---------- */
            $taxIds = [];
            foreach ([
                ['Research services — exempt', 0, 'exempt'],
                ['NY sales tax', 8.875, 'normal'],
                ['MA sales tax', 6.25, 'normal'],
            ] as $t) {
                $taxIds[] = DB::insert('acc_taxes', ['name' => $t[0], 'rate' => $t[1], 'type' => $t[2], 'enabled' => 1]);
            }

            /* ---------- Categories ---------- */
            $incomeCatIds = [];
            foreach ([
                ['Sequencing services', '#7e6549'],
                ['Analysis & consulting', '#6B8E5A'],
                ['Retainers', '#4A7C9B'],
            ] as $c) {
                $incomeCatIds[] = DB::insert('acc_categories', ['name' => $c[0], 'type' => 'income', 'color' => $c[1], 'enabled' => 1]);
            }
            $expenseCatIds = [];
            foreach ([
                ['Reagents & consumables', '#B0432B'],
                ['Cloud compute', '#4A7C9B'],
                ['Payroll & contractors', '#C99520'],
                ['Logistics & shipping', '#6B8E5A'],
                ['Software & tools', '#9A8A78'],
            ] as $c) {
                $expenseCatIds[] = DB::insert('acc_categories', ['name' => $c[0], 'type' => 'expense', 'color' => $c[1], 'enabled' => 1]);
            }

            /* ---------- Bank accounts ---------- */
            $operating = DB::insert('acc_accounts', [
                'name' => 'Operating', 'bank_name' => 'Mercury', 'number' => '4821',
                'currency_code' => 'USD', 'opening_balance' => 150000, 'balance' => 150000,
                'type' => 'bank', 'color' => '#7e6549', 'enabled' => 1,
            ]);
            $card = DB::insert('acc_accounts', [
                'name' => 'Corporate Card', 'bank_name' => 'Brex', 'number' => '0913',
                'currency_code' => 'USD', 'opening_balance' => 0, 'balance' => 0,
                'type' => 'credit_card', 'color' => '#9A8A78', 'enabled' => 1,
            ]);
            $savings = DB::insert('acc_accounts', [
                'name' => 'Reserves', 'bank_name' => 'Mercury', 'number' => '7302',
                'currency_code' => 'USD', 'opening_balance' => 200000, 'balance' => 200000,
                'type' => 'bank', 'color' => '#6B8E5A', 'enabled' => 1,
            ]);

            /* ---------- Chart of accounts ---------- */
            foreach ([
                ['1000', 'Cash — Operating', 'asset', 'debit'],
                ['1010', 'Cash — Reserves', 'asset', 'debit'],
                ['1020', 'Accounts Receivable', 'asset', 'debit'],
                ['1030', 'Inventory — Reagents', 'asset', 'debit'],
                ['1040', 'Prepaid Expenses', 'asset', 'debit'],
                ['2000', 'Accounts Payable', 'liability', 'credit'],
                ['2010', 'Credit Card Balance', 'liability', 'credit'],
                ['2020', 'Accrued Payroll', 'liability', 'credit'],
                ['2100', 'Sales Tax Payable', 'liability', 'credit'],
                ['3000', "Owner's Equity", 'equity', 'credit'],
                ['3010', 'Retained Earnings', 'equity', 'credit'],
                ['4000', 'Sequencing Revenue', 'revenue', 'credit'],
                ['4010', 'Analysis & Consulting Revenue', 'revenue', 'credit'],
                ['4020', 'Retainer Revenue', 'revenue', 'credit'],
                ['5000', 'Reagents & Consumables', 'expense', 'debit'],
                ['5010', 'Cloud Computing', 'expense', 'debit'],
                ['5020', 'Payroll & Contractors', 'expense', 'debit'],
                ['5030', 'Logistics & Shipping', 'expense', 'debit'],
                ['5040', 'Software & Tools', 'expense', 'debit'],
            ] as $c) {
                DB::insert('acc_chart_of_accounts', [
                    'code' => $c[0], 'name' => $c[1], 'type' => $c[2], 'side' => $c[3],
                    'balance' => 0, 'enabled' => 1,
                ]);
            }

            /* ---------- Customers ---------- */
            $customers = [
                ['Halcyon Therapeutics', 'i.reyes@halcyontx.com', '(617) 555-0142', '88 Kendall Sq, Cambridge, MA 02142', 'Dr. Imogen Reyes'],
                ['Cascade Biosciences', 'pnathan@cascadebio.com', '(206) 555-0198', '2200 Westlake Ave, Seattle, WA 98121', 'Priya Nathan'],
                ['Meridian Oncology Center', 'j.okafor@meridianonc.org', '(312) 555-0177', '500 N Michigan Ave, Chicago, IL 60611', 'James Okafor'],
                ['Northgate University Genomics Core', 'avoss@northgate.edu', '(650) 555-0123', '450 Serra Mall, Stanford, CA 94305', 'Prof. Alan Voss'],
                ['Sable Peak Labs', 'devon@sablepeak.com', '(303) 555-0166', '1800 Broadway, Denver, CO 80202', 'Devon Marsh'],
                ['Bluewater Diagnostics', 's.lindqvist@bluewaterdx.com', '(415) 555-0144', '1 Ferry Building, San Francisco, CA 94111', 'Sofia Lindqvist'],
            ];
            $customerIds = [];
            foreach ($customers as $c) {
                $id = DB::insert('acc_contacts', [
                    'type' => 'customer', 'name' => $c[0], 'email' => $c[1], 'phone' => $c[2],
                    'address' => $c[3], 'currency_code' => 'USD', 'enabled' => 1,
                ]);
                $customerIds[] = $id;
                DB::insert('acc_contact_people', [
                    'contact_id' => $id, 'name' => $c[4], 'email' => $c[1], 'position' => 'Primary Contact',
                ]);
            }

            /* ---------- Vendors ---------- */
            $vendors = [
                ['Praxis Sequencing Supply', 'orders@praxisseq.com', '(800) 555-0100', 'Reagents & kits'],
                ['LabForce Staffing', 'contact@labforce.com', '(617) 555-0188', 'Contract staff'],
                ['Amazon Web Services', 'billing@aws.amazon.com', '(800) 555-0190', 'Cloud compute'],
                ['Regent Scientific', 'sales@regentsci.com', '(888) 555-0155', 'Consumables'],
                ['CryoStore Logistics', 'ship@cryostore.com', '(650) 555-0112', 'Logistics'],
                ['Benchling', 'billing@benchling.com', '(415) 555-0133', 'Software'],
            ];
            $vendorIds = [];
            foreach ($vendors as $v) {
                $vendorIds[] = DB::insert('acc_contacts', [
                    'type' => 'vendor', 'name' => $v[0], 'email' => $v[1], 'phone' => $v[2],
                    'category' => $v[3], 'currency_code' => 'USD', 'enabled' => 1,
                ]);
            }

            /* ---------- Item catalog ---------- */
            foreach ([
                ['Whole-genome sequencing (30×)', 'WGS-30X', 1150, null, 'service'],
                ['Whole-exome sequencing', 'WES-STD', 1050, null, 'service'],
                ['RNA-seq library prep & sequencing', 'RNA-SEQ', 385, null, 'service'],
                ['Differential expression analysis', 'DE-ANALYSIS', 900, null, 'service'],
                ['Variant calling & annotation', 'VC-ANNOT', 2050, null, 'service'],
                ['Clinical interpretation report', 'CLIN-RPT', 2400, null, 'service'],
                ['ctDNA liquid biopsy panel', 'CTDNA-PANEL', 775, null, 'service'],
                ['Targeted panel validation', 'PANEL-VAL', 7600, null, 'service'],
                ['Library prep — per sample', 'LIB-PREP', 185, null, 'service'],
                ['Sample QC & normalization', 'QC-NORM', 1200, null, 'service'],
                ['Bioinformatics retainer (monthly)', 'BIO-RETAINER', 5000, null, 'service'],
                ['NovaSeq reagent kit (S4)', 'REAGENT-S4', null, 18660, 'product'],
            ] as $i) {
                DB::insert('acc_items', [
                    'name' => $i[0], 'sku' => $i[1], 'sale_price' => $i[2],
                    'purchase_price' => $i[3], 'type' => $i[4], 'enabled' => 1,
                ]);
            }

            /* ---------- Invoices ---------- */
            $invoices = [
                ['INV-0149', 0, 'draft',   '2026-07-11', '2026-08-10', 0,     [['Whole-genome sequencing (30×) — 18 samples', 18, 1150], ['Variant calling & annotation', 1, 2050]]],
                ['INV-0148', 0, 'sent',    '2026-06-24', '2026-07-24', 0,     [['Whole-genome sequencing (30×) — 24 samples', 24, 1200]]],
                ['INV-0147', 3, 'sent',    '2026-05-31', '2026-06-30', 0,     [['RNA-seq library prep & sequencing', 30, 385], ['Differential expression analysis', 1, 900]]],
                ['INV-0146', 1, 'paid',    '2026-06-10', '2026-07-10', 19200, [['Whole-exome sequencing — 16 samples', 16, 1050], ['Clinical interpretation report', 1, 2400]]],
                ['INV-0145', 5, 'paid',    '2026-06-06', '2026-07-06', 7600,  [['Targeted panel validation run', 1, 7600]]],
                ['INV-0144', 2, 'partial', '2026-06-02', '2026-07-02', 17000, [['ctDNA liquid biopsy panel — 40 samples', 40, 775], ['Pilot study design & reporting', 1, 3000]]],
                ['INV-0143', 4, 'paid',    '2026-06-01', '2026-07-01', 5000,  [['Bioinformatics retainer — June 2026', 1, 5000]]],
                ['INV-0142', 0, 'paid',    '2026-05-18', '2026-06-17', 9340,  [['Library prep — 44 samples', 44, 185], ['Sample QC & normalization', 1, 1200]]],
            ];
            // Spread the demo invoices across real workspace members so the
            // sales-by-agent report has something to show out of the box.
            $agentIds = [];
            try {
                foreach (DB::fetchAll(
                    "SELECT u.id FROM users u JOIN workspace_members wm ON wm.user_id = u.id
                      WHERE wm.workspace_id = ? AND u.is_active = 1 ORDER BY u.id LIMIT 4",
                    [Auth::workspaceId()]
                ) as $u) $agentIds[] = (int)$u['id'];
            } catch (\Throwable $e) { $agentIds = []; }

            // Revenue type per invoice (index into $incomeCatIds), so the
            // sales-by-type report is meaningful rather than all "Uncategorized".
            $invoiceCat = [
                'INV-0149' => 0, 'INV-0148' => 0, 'INV-0147' => 0, 'INV-0146' => 0,
                'INV-0145' => 0, 'INV-0144' => 1, 'INV-0143' => 2, 'INV-0142' => 0,
            ];

            $docIds = [];
            $agentSeq = 0;
            foreach ($invoices as $inv) {
                list($num, $custIdx, $status, $issued, $due, $paid, $lines) = $inv;
                $agentId = $agentIds ? $agentIds[$agentSeq++ % count($agentIds)] : null;
                $docId = DB::insert('acc_documents', [
                    'type' => 'invoice', 'number' => $num, 'status' => $status,
                    'issued_at' => $issued, 'due_at' => $due, 'amount' => 0, 'paid_amount' => $paid,
                    'currency_code' => 'USD', 'contact_id' => $customerIds[$custIdx], 'terms' => 'Net 30',
                    'user_id' => $agentId,
                    'category_id' => isset($invoiceCat[$num]) ? ($incomeCatIds[$invoiceCat[$num]] ?? null) : null,
                ]);
                self::writeLines($docId, $lines);
                Acc::addHistory($docId, $status, 'Invoice ' . $status);
                if ($paid > 0) Acc::addHistory($docId, 'payment', 'Payment of ' . number_format($paid, 2) . ' received');
                $docIds[$num] = $docId;
            }

            /* ---------- Bills ---------- */
            $bills = [
                ['BILL-2077', 0, 'open', '2026-07-15', '2026-07-21', 0,     [['NovaSeq reagent kits', 1, 21340]]],
                ['BILL-2076', 4, 'paid', '2026-06-25', '2026-07-27', 1890,  [['Cold-chain shipping — June', 1, 1890]]],
                ['BILL-2075', 3, 'open', '2026-06-20', '2026-07-02', 0,     [['Plasticware & consumables', 1, 4215]]],
                ['BILL-2074', 2, 'paid', '2026-06-30', '2026-07-08', 6730,  [['Cloud compute — June', 1, 6730]]],
                ['BILL-2073', 1, 'paid', '2026-06-30', '2026-07-07', 11200, [['Contract technicians — June', 1, 11200]]],
                ['BILL-2072', 0, 'paid', '2026-06-28', '2026-07-08', 18660, [['Flow cells (S4)', 1, 18660]]],
            ];
            foreach ($bills as $bill) {
                list($num, $vendIdx, $status, $issued, $due, $paid, $lines) = $bill;
                $docId = DB::insert('acc_documents', [
                    'type' => 'bill', 'number' => $num, 'status' => $status,
                    'issued_at' => $issued, 'due_at' => $due, 'amount' => 0, 'paid_amount' => $paid,
                    'currency_code' => 'USD', 'contact_id' => $vendorIds[$vendIdx],
                ]);
                self::writeLines($docId, $lines);
                Acc::addHistory($docId, $status, 'Bill ' . $status);
                if ($paid > 0) Acc::addHistory($docId, 'payment', 'Payment of ' . number_format($paid, 2) . ' sent');
                $docIds[$num] = $docId;
            }

            /* ---------- Transactions (payments) ---------- */
            $transactions = [
                ['income',  '2026-07-10', 19200, $operating, $customerIds[1], $incomeCatIds[1],  'Payment — INV-0146', 'bank_transfer', 'INV-0146'],
                ['expense', '2026-07-08', 18660, $operating, $vendorIds[0],   $expenseCatIds[0], 'Flow cells (S4) — BILL-2072', 'bank_transfer', 'BILL-2072'],
                ['expense', '2026-07-07', 6730,  $card,      $vendorIds[2],   $expenseCatIds[1], 'Cloud compute — June', 'credit_card', 'BILL-2074'],
                ['income',  '2026-07-03', 7600,  $operating, $customerIds[5], $incomeCatIds[0],  'Payment — INV-0145', 'bank_transfer', 'INV-0145'],
                ['expense', '2026-07-01', 11200, $operating, $vendorIds[1],   $expenseCatIds[2], 'Contract technicians — June', 'bank_transfer', 'BILL-2073'],
                ['income',  '2026-06-30', 17000, $operating, $customerIds[2], $incomeCatIds[0],  'Partial payment — INV-0144', 'bank_transfer', 'INV-0144'],
                ['expense', '2026-06-27', 1890,  $card,      $vendorIds[4],   $expenseCatIds[3], 'Cold-chain shipping', 'credit_card', 'BILL-2076'],
                ['income',  '2026-06-24', 5000,  $operating, $customerIds[4], $incomeCatIds[2],  'Payment — INV-0143', 'bank_transfer', 'INV-0143'],
                ['expense', '2026-06-18', 1410,  $card,      $vendorIds[5],   $expenseCatIds[4], 'ELN subscription true-up', 'credit_card', null],
                ['income',  '2026-06-15', 9340,  $operating, $customerIds[0], $incomeCatIds[0],  'Payment — INV-0142', 'bank_transfer', 'INV-0142'],
            ];
            foreach ($transactions as $t) {
                list($type, $date, $amount, $acctId, $contactId, $catId, $desc, $method, $docNum) = $t;
                DB::insert('acc_transactions', [
                    'type' => $type, 'paid_at' => $date, 'amount' => $amount, 'currency_code' => 'USD',
                    'account_id' => $acctId, 'contact_id' => $contactId, 'category_id' => $catId,
                    'document_id' => $docNum && isset($docIds[$docNum]) ? $docIds[$docNum] : null,
                    'description' => $desc, 'payment_method' => $method,
                    'reconciled' => 0, 'is_transfer' => 0,
                ]);
            }

            /* ---------- Transfer (both legs flagged is_transfer) ---------- */
            $expenseLeg = DB::insert('acc_transactions', [
                'type' => 'expense', 'paid_at' => '2026-06-15', 'amount' => 15000, 'currency_code' => 'USD',
                'account_id' => $operating, 'description' => 'Monthly reserve transfer', 'is_transfer' => 1,
            ]);
            $incomeLeg = DB::insert('acc_transactions', [
                'type' => 'income', 'paid_at' => '2026-06-15', 'amount' => 15000, 'currency_code' => 'USD',
                'account_id' => $savings, 'description' => 'Monthly reserve transfer', 'is_transfer' => 1,
            ]);
            DB::insert('acc_transfers', [
                'transferred_at' => '2026-06-15', 'description' => 'Monthly reserve transfer',
                'from_account_id' => $operating, 'to_account_id' => $savings, 'amount' => 15000,
                'currency_code' => 'USD', 'expense_transaction_id' => $expenseLeg, 'income_transaction_id' => $incomeLeg,
            ]);

            /* ---------- Reconciliations ---------- */
            DB::insert('acc_reconciliations', [
                'account_id' => $operating, 'started_at' => '2026-06-01', 'ended_at' => '2026-06-30',
                'closing_balance' => 228260, 'reconciled' => 1,
            ]);
            DB::insert('acc_reconciliations', [
                'account_id' => $card, 'started_at' => '2026-07-01', 'ended_at' => '2026-07-31',
                'closing_balance' => -10030, 'reconciled' => 0,
            ]);

            /* ---------- Journal entries ----------
             * Posted through the ledger so the Chart of Accounts, Trial Balance
             * and Journal all agree with the documents above. */
            foreach (['INV-0148', 'INV-0147', 'INV-0146', 'INV-0145', 'INV-0144', 'INV-0143', 'INV-0142'] as $num) {
                $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [$docIds[$num]]);
                if ($doc) Acc::postInvoiceIssued($doc);
            }
            foreach (['BILL-2077', 'BILL-2076', 'BILL-2075', 'BILL-2074', 'BILL-2073', 'BILL-2072'] as $num) {
                $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [$docIds[$num]]);
                if ($doc) Acc::postBillReceived($doc);
            }
            foreach ([
                ['INV-0146', 19200, '2026-07-10'], ['INV-0145', 7600, '2026-07-03'],
                ['INV-0144', 17000, '2026-06-30'], ['INV-0143', 5000, '2026-06-24'],
                ['INV-0142', 9340, '2026-06-15'],
            ] as $p) {
                $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [$docIds[$p[0]]]);
                if ($doc) Acc::postInvoicePayment($doc, $p[1], $p[2]);
            }
            foreach ([
                ['BILL-2076', 1890, '2026-06-27'], ['BILL-2074', 6730, '2026-07-07'],
                ['BILL-2073', 11200, '2026-07-01'], ['BILL-2072', 18660, '2026-07-08'],
            ] as $p) {
                $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [$docIds[$p[0]]]);
                if ($doc) Acc::postBillPayment($doc, $p[1], $p[2]);
            }
            // Reserve transfer between the two cash accounts.
            Acc::postEntry('Monthly reserve transfer', 'system', '2026-06-15', [
                ['coa' => Acc::coa('1010', 'Cash — Reserves', 'asset', 'debit'), 'debit' => 15000],
                ['coa' => Acc::coa('1000', 'Cash — Operating', 'asset', 'debit'), 'credit' => 15000],
            ]);

            /* ---------- Recurring schedules ---------- */
            DB::insert('acc_recurrings', [
                'recurable_type' => 'document', 'recurable_id' => $docIds['INV-0143'],
                'frequency' => 'monthly', 'interval_n' => 1,
                'started_at' => '2026-01-01 00:00:00', 'last_ran_at' => '2026-06-01 00:00:00',
                'limit_count' => 12, 'limit_by' => 'count', 'auto_send' => 1,
                'occurrences' => 6, 'status' => 'active',
            ]);
            DB::insert('acc_recurrings', [
                'recurable_type' => 'document', 'recurable_id' => $docIds['BILL-2074'],
                'frequency' => 'monthly', 'interval_n' => 1,
                'started_at' => '2026-01-01 00:00:00', 'last_ran_at' => '2026-06-30 00:00:00',
                'limit_count' => 0, 'limit_by' => 'count', 'auto_send' => 0,
                'occurrences' => 6, 'status' => 'active',
            ]);

            /* ---------- Derived balances ---------- */
            Acc::recalcAllAccounts();
            Acc::recalcAllCoa();

            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Seed line items without touching tax tables (demo data is tax-exempt). */
    private static function writeLines($documentId, array $lines)
    {
        $subtotal = 0;
        foreach ($lines as $l) {
            list($name, $qty, $price) = $l;
            $total = round($qty * $price, 2);
            DB::insert('acc_document_items', [
                'document_id' => $documentId, 'name' => $name,
                'quantity' => $qty, 'price' => $price, 'total' => $total,
            ]);
            $subtotal += $total;
        }
        $subtotal = round($subtotal, 2);
        DB::insert('acc_document_totals', ['document_id' => $documentId, 'code' => 'subtotal', 'name' => 'Subtotal', 'amount' => $subtotal, 'sort_order' => 1]);
        DB::insert('acc_document_totals', ['document_id' => $documentId, 'code' => 'tax', 'name' => 'Tax (research exempt)', 'amount' => 0, 'sort_order' => 2]);
        DB::insert('acc_document_totals', ['document_id' => $documentId, 'code' => 'total', 'name' => 'Total', 'amount' => $subtotal, 'sort_order' => 3]);
        DB::query("UPDATE acc_documents SET amount = ? WHERE id = ?", [$subtotal, $documentId]);
    }
}
