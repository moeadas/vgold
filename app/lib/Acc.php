<?php
/**
 * Acc — shared helpers for the native Accounting & Finance app.
 *
 * Settings store, numeric coercion, atomic document-number reservation,
 * balance recalculation, and the double-entry ledger (ported 1:1 from the
 * VGACC LedgerService so the Journal, Chart of Accounts and Trial Balance
 * always reflect real activity).
 */
class Acc
{
    /* ===================== Settings (key/value) ===================== */

    private static $settingsCache = null;

    public static function allSettings()
    {
        if (self::$settingsCache !== null) return self::$settingsCache;
        $rows = DB::fetchAll("SELECT skey, svalue FROM acc_settings");
        $out = [];
        foreach ($rows as $r) $out[$r['skey']] = $r['svalue'];
        return self::$settingsCache = $out;
    }

    public static function setting($key, $default = null)
    {
        $all = self::allSettings();
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== ''
            ? $all[$key] : $default;
    }

    public static function setSetting($key, $value)
    {
        DB::query(
            "INSERT INTO acc_settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)",
            [$key, $value]
        );
        if (self::$settingsCache !== null) self::$settingsCache[$key] = $value;
    }

    public static function flushSettings() { self::$settingsCache = null; }

    /* ===================== Coercion / validation ===================== */

    /** Money → float rounded to 2dp (source app rounds line/tax amounts on write). */
    public static function money($v) { return round((float)$v, 2); }

    public static function num($v, $default = 0) { return is_numeric($v) ? (float)$v : (float)$default; }

    public static function intOrNull($v)
    {
        if ($v === null || $v === '' || $v === false) return null;
        return (int)$v;
    }

    public static function strOrNull($v, $max = 191)
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    }

    /** Normalise a Y-m-d date; returns null when unusable. */
    public static function date($v, $default = null)
    {
        if (!$v) return $default;
        $ts = strtotime((string)$v);
        return $ts ? date('Y-m-d', $ts) : $default;
    }

    public static function dateTime($v, $default = null)
    {
        if (!$v) return $default;
        $ts = strtotime((string)$v);
        return $ts ? date('Y-m-d H:i:s', $ts) : $default;
    }

    /** Validate a value against an allow-list, falling back to $default. */
    public static function enum($v, array $allowed, $default)
    {
        return in_array($v, $allowed, true) ? $v : $default;
    }

    /* ===================== Document numbering ===================== */

    /**
     * Atomically reserve the next invoice/bill number, preserving zero-pad width.
     * MUST run inside a transaction — the counter row is locked for its duration.
     */
    public static function reserveNumber($kind)
    {
        $prefixKey  = $kind === 'invoice' ? 'invoice_prefix' : 'bill_prefix';
        $prefixDef  = $kind === 'invoice' ? 'INV-' : 'BILL-';
        $counterKey = $kind === 'invoice' ? 'invoice_next_number' : 'bill_next_number';
        $counterDef = $kind === 'invoice' ? '0150' : '2078';

        $row = DB::fetch("SELECT svalue FROM acc_settings WHERE skey = ? FOR UPDATE", [$counterKey]);
        $current = ($row && $row['svalue'] !== null && $row['svalue'] !== '') ? (string)$row['svalue'] : $counterDef;
        $width = strlen($current);

        $prefix = self::setting($prefixKey, $prefixDef);
        $number = $prefix . $current;

        $next = str_pad((string)((int)$current + 1), $width, '0', STR_PAD_LEFT);
        self::setSetting($counterKey, $next);

        // Defensive: never mint a duplicate if the counter drifted behind reality.
        $guard = 0;
        while (DB::fetch("SELECT id FROM acc_documents WHERE number = ? LIMIT 1", [$number]) && $guard < 500) {
            $current = $next;
            $number  = $prefix . $current;
            $next    = str_pad((string)((int)$current + 1), $width, '0', STR_PAD_LEFT);
            self::setSetting($counterKey, $next);
            $guard++;
        }
        return $number;
    }

    public static function nextJournalNumber()
    {
        $row = DB::fetch("SELECT number FROM acc_journal_entries ORDER BY id DESC LIMIT 1");
        $n = 0;
        if ($row && preg_match('/(\d+)$/', $row['number'], $m)) $n = (int)$m[1];
        $candidate = 'JE-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
        $guard = 0;
        while (DB::fetch("SELECT id FROM acc_journal_entries WHERE number = ? LIMIT 1", [$candidate]) && $guard < 500) {
            $n++;
            $candidate = 'JE-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
            $guard++;
        }
        return $candidate;
    }

    /* ===================== Balances ===================== */

    /** Bank account balance = opening + income − expense (live rows only). */
    public static function recalcAccount($accountId)
    {
        $accountId = (int)$accountId;
        if (!$accountId) return;
        DB::query(
            "UPDATE acc_accounts a SET a.balance = a.opening_balance
               + COALESCE((SELECT SUM(t.amount) FROM acc_transactions t
                    WHERE t.account_id = a.id AND t.type = 'income'  AND t.deleted_at IS NULL), 0)
               - COALESCE((SELECT SUM(t.amount) FROM acc_transactions t
                    WHERE t.account_id = a.id AND t.type = 'expense' AND t.deleted_at IS NULL), 0)
             WHERE a.id = ?",
            [$accountId]
        );
    }

    public static function recalcAllAccounts()
    {
        $ids = DB::fetchAll("SELECT id FROM acc_accounts WHERE deleted_at IS NULL");
        foreach ($ids as $r) self::recalcAccount((int)$r['id']);
        return count($ids);
    }

    /**
     * Chart-of-account balance recomputed from posted journal lines only.
     * Debit-side: debits − credits. Credit-side: credits − debits.
     */
    public static function recalcCoa($coaId)
    {
        $coaId = (int)$coaId;
        if (!$coaId) return;
        DB::query(
            "UPDATE acc_chart_of_accounts c
                SET c.balance = CASE WHEN c.side = 'debit'
                    THEN COALESCE((SELECT SUM(l.debit - l.credit) FROM acc_journal_lines l
                          JOIN acc_journal_entries e ON e.id = l.journal_entry_id
                          WHERE l.chart_of_account_id = c.id AND e.status = 'posted' AND e.deleted_at IS NULL), 0)
                    ELSE COALESCE((SELECT SUM(l.credit - l.debit) FROM acc_journal_lines l
                          JOIN acc_journal_entries e ON e.id = l.journal_entry_id
                          WHERE l.chart_of_account_id = c.id AND e.status = 'posted' AND e.deleted_at IS NULL), 0)
                END
              WHERE c.id = ?",
            [$coaId]
        );
    }

    public static function recalcAllCoa()
    {
        $ids = DB::fetchAll("SELECT id FROM acc_chart_of_accounts WHERE deleted_at IS NULL");
        foreach ($ids as $r) self::recalcCoa((int)$r['id']);
        return count($ids);
    }

    /* ===================== Double-entry ledger ===================== */

    const CASH        = '1000';
    const AR          = '1020';
    const AP          = '2000';
    const TAX_PAYABLE = '2100';
    const REVENUE     = '4000';
    const EXPENSE     = '5000';

    /** Resolve (or create) a chart-of-accounts row by code, so posting never fails. */
    public static function coa($code, $name, $type, $side)
    {
        $row = DB::fetch("SELECT * FROM acc_chart_of_accounts WHERE code = ? AND deleted_at IS NULL LIMIT 1", [$code]);
        if ($row) return $row;
        $id = DB::insert('acc_chart_of_accounts', [
            'code' => $code, 'name' => $name, 'type' => $type,
            'side' => $side, 'balance' => 0, 'enabled' => 1,
        ]);
        return DB::fetch("SELECT * FROM acc_chart_of_accounts WHERE id = ?", [$id]);
    }

    /** Sum of per-line taxes on a document. */
    public static function documentTaxTotal($documentId)
    {
        $row = DB::fetch(
            "SELECT COALESCE(SUM(dit.amount), 0) AS t
               FROM acc_document_item_taxes dit
               JOIN acc_document_items di ON di.id = dit.document_item_id
              WHERE di.document_id = ?",
            [(int)$documentId]
        );
        return self::money($row['t'] ?? 0);
    }

    /**
     * Write one balanced journal entry.
     * $lines: [['coa'=>row, 'debit'=>float, 'credit'=>float, 'description'=>?string], ...]
     * Assumes the caller opened a transaction (all callers do).
     */
    public static function postEntry($memo, $source, $date, array $lines, $documentId = null, $transactionId = null)
    {
        $lines = array_values(array_filter($lines, function ($l) {
            return round(self::num($l['debit'] ?? 0) + self::num($l['credit'] ?? 0), 2) > 0;
        }));
        if (count($lines) < 2) return null;

        $entryId = DB::insert('acc_journal_entries', [
            'number'         => self::nextJournalNumber(),
            'entry_date'     => self::date($date, date('Y-m-d')),
            'memo'           => mb_substr((string)$memo, 0, 255),
            'source'         => $source,
            'status'         => 'posted',
            'document_id'    => self::intOrNull($documentId),
            'transaction_id' => self::intOrNull($transactionId),
        ]);

        $touched = [];
        foreach ($lines as $l) {
            DB::insert('acc_journal_lines', [
                'journal_entry_id'    => $entryId,
                'chart_of_account_id' => (int)$l['coa']['id'],
                'debit'               => self::money($l['debit'] ?? 0),
                'credit'              => self::money($l['credit'] ?? 0),
                'description'         => $l['description'] ?? $memo,
            ]);
            $touched[(int)$l['coa']['id']] = true;
        }
        foreach (array_keys($touched) as $coaId) self::recalcCoa($coaId);
        return $entryId;
    }

    /** DR Accounts Receivable · CR Revenue (+ CR Sales Tax Payable). */
    public static function postInvoiceIssued($doc)
    {
        $tax = self::documentTaxTotal($doc['id']);
        $subtotal = self::money(self::num($doc['amount']) - $tax);
        $lines = [
            ['coa' => self::coa(self::AR, 'Accounts Receivable', 'asset', 'debit'), 'debit' => self::num($doc['amount'])],
            ['coa' => self::coa(self::REVENUE, 'Sales Revenue', 'revenue', 'credit'), 'credit' => $subtotal],
        ];
        if ($tax > 0) {
            $lines[] = ['coa' => self::coa(self::TAX_PAYABLE, 'Sales Tax Payable', 'liability', 'credit'), 'credit' => $tax];
        }
        return self::postEntry("Invoice {$doc['number']} issued", 'invoice', $doc['issued_at'], $lines, $doc['id']);
    }

    /** DR Cash · CR Accounts Receivable. */
    public static function postInvoicePayment($doc, $amount, $date, $transactionId = null)
    {
        return self::postEntry("Payment received for {$doc['number']}", 'payment', $date, [
            ['coa' => self::coa(self::CASH, 'Cash — Operating', 'asset', 'debit'), 'debit' => $amount],
            ['coa' => self::coa(self::AR, 'Accounts Receivable', 'asset', 'debit'), 'credit' => $amount],
        ], $doc['id'], $transactionId);
    }

    /** DR Expense (+ DR Sales Tax) · CR Accounts Payable. */
    public static function postBillReceived($doc)
    {
        $tax = self::documentTaxTotal($doc['id']);
        $subtotal = self::money(self::num($doc['amount']) - $tax);
        $lines = [
            ['coa' => self::coa(self::EXPENSE, 'General Expense', 'expense', 'debit'), 'debit' => $subtotal],
        ];
        if ($tax > 0) {
            $lines[] = ['coa' => self::coa(self::TAX_PAYABLE, 'Sales Tax Payable', 'liability', 'credit'), 'debit' => $tax];
        }
        $lines[] = ['coa' => self::coa(self::AP, 'Accounts Payable', 'liability', 'credit'), 'credit' => self::num($doc['amount'])];
        return self::postEntry("Bill {$doc['number']} received", 'bill', $doc['issued_at'], $lines, $doc['id']);
    }

    /** DR Accounts Payable · CR Cash. */
    public static function postBillPayment($doc, $amount, $date, $transactionId = null)
    {
        return self::postEntry("Payment made for {$doc['number']}", 'payment', $date, [
            ['coa' => self::coa(self::AP, 'Accounts Payable', 'liability', 'credit'), 'debit' => $amount],
            ['coa' => self::coa(self::CASH, 'Cash — Operating', 'asset', 'debit'), 'credit' => $amount],
        ], $doc['id'], $transactionId);
    }

    /**
     * Mirror every posted entry for a document and mark the originals reversed.
     * Used on cancel/delete — posted entries are never edited, only reversed.
     */
    public static function reverseForDocument($doc, $reason = 'Reversal')
    {
        $entries = DB::fetchAll(
            "SELECT * FROM acc_journal_entries WHERE document_id = ? AND status = 'posted' AND deleted_at IS NULL",
            [(int)$doc['id']]
        );
        foreach ($entries as $entry) {
            $lines = DB::fetchAll("SELECT * FROM acc_journal_lines WHERE journal_entry_id = ?", [(int)$entry['id']]);
            $mirror = [];
            foreach ($lines as $l) {
                $coa = DB::fetch("SELECT * FROM acc_chart_of_accounts WHERE id = ?", [(int)$l['chart_of_account_id']]);
                if (!$coa) continue;
                $mirror[] = [
                    'coa' => $coa,
                    'debit' => self::num($l['credit']),   // swapped
                    'credit' => self::num($l['debit']),   // swapped
                    'description' => $reason,
                ];
            }
            if (count($mirror) >= 2) {
                self::postEntry("$reason: reversing {$entry['number']}", 'reversal', date('Y-m-d'), $mirror, $doc['id']);
            }
            DB::query("UPDATE acc_journal_entries SET status = 'reversed' WHERE id = ?", [(int)$entry['id']]);
            foreach ($lines as $l) self::recalcCoa((int)$l['chart_of_account_id']);
        }
    }

    /* ===================== Document helpers ===================== */

    /**
     * Rewrite a document's line items, per-line taxes and totals, then set the
     * header amount. Returns the recomputed [subtotal, tax, total].
     * $items: [['name','quantity','price','item_id','sku','tax_ids'=>[]], ...]
     */
    public static function writeDocumentLines($documentId, array $items)
    {
        $documentId = (int)$documentId;
        DB::query("DELETE dit FROM acc_document_item_taxes dit
                   JOIN acc_document_items di ON di.id = dit.document_item_id
                   WHERE di.document_id = ?", [$documentId]);
        DB::query("DELETE FROM acc_document_items WHERE document_id = ?", [$documentId]);
        DB::query("DELETE FROM acc_document_totals WHERE document_id = ?", [$documentId]);

        $subtotal = 0.0;
        $taxTotal = 0.0;
        foreach ($items as $it) {
            $name = self::strOrNull($it['name'] ?? null);
            if ($name === null) continue;
            $qty   = round(self::num($it['quantity'] ?? 1, 1), 2);
            $price = self::money($it['price'] ?? 0);
            $total = self::money($qty * $price);
            $itemId = DB::insert('acc_document_items', [
                'document_id' => $documentId,
                'item_id'     => self::intOrNull($it['item_id'] ?? null),
                'name'        => $name,
                'sku'         => self::strOrNull($it['sku'] ?? null, 100),
                'quantity'    => $qty,
                'price'       => $price,
                'total'       => $total,
            ]);
            $subtotal += $total;

            $taxIds = $it['tax_ids'] ?? [];
            if (!is_array($taxIds)) $taxIds = [];
            foreach ($taxIds as $taxId) {
                $tax = DB::fetch("SELECT * FROM acc_taxes WHERE id = ? AND deleted_at IS NULL", [(int)$taxId]);
                if (!$tax) continue;
                $amount = self::money($total * (self::num($tax['rate']) / 100));
                if ($amount <= 0) continue;
                DB::insert('acc_document_item_taxes', [
                    'document_item_id' => $itemId,
                    'tax_id'           => (int)$tax['id'],
                    'name'             => $tax['name'],
                    'amount'           => $amount,
                ]);
                $taxTotal += $amount;
            }
        }

        $subtotal = self::money($subtotal);
        $taxTotal = self::money($taxTotal);
        $total    = self::money($subtotal + $taxTotal);

        DB::insert('acc_document_totals', ['document_id' => $documentId, 'code' => 'subtotal', 'name' => 'Subtotal', 'amount' => $subtotal, 'sort_order' => 1]);
        DB::insert('acc_document_totals', ['document_id' => $documentId, 'code' => 'tax',      'name' => 'Tax',      'amount' => $taxTotal, 'sort_order' => 2]);
        DB::insert('acc_document_totals', ['document_id' => $documentId, 'code' => 'total',    'name' => 'Total',    'amount' => $total,    'sort_order' => 3]);

        DB::query("UPDATE acc_documents SET amount = ? WHERE id = ?", [$total, $documentId]);

        return [$subtotal, $taxTotal, $total];
    }

    public static function addHistory($documentId, $status, $description = null)
    {
        DB::insert('acc_document_histories', [
            'document_id' => (int)$documentId,
            'status'      => $status,
            'description' => $description,
        ]);
    }

    /**
     * Effective status for display: an unpaid, past-due, non-draft document
     * reads as "overdue" without mutating the stored status.
     */
    public static function displayStatus($doc)
    {
        $status = $doc['status'];
        if (!in_array($status, ['paid', 'cancelled', 'draft'], true)
            && !empty($doc['due_at']) && $doc['due_at'] < date('Y-m-d')
            && self::num($doc['paid_amount']) < self::num($doc['amount'])) {
            return 'overdue';
        }
        return $status;
    }

    /** Recompute paid_amount from live payment rows and re-derive the status. */
    public static function syncDocumentPaymentState($documentId)
    {
        $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$documentId]);
        if (!$doc) return null;
        $row = DB::fetch(
            "SELECT COALESCE(SUM(amount), 0) AS paid FROM acc_transactions
              WHERE document_id = ? AND deleted_at IS NULL AND is_transfer = 0",
            [(int)$documentId]
        );
        $paid = self::money($row['paid'] ?? 0);
        $amount = self::num($doc['amount']);
        $status = $doc['status'];

        if (!in_array($status, ['draft', 'cancelled'], true)) {
            if ($paid >= $amount - 0.005 && $amount > 0) {
                $status = 'paid';
            } elseif ($paid > 0) {
                $status = 'partial';
            } else {
                $status = $doc['type'] === 'invoice' ? 'sent' : 'open';
            }
        }
        DB::query("UPDATE acc_documents SET paid_amount = ?, status = ? WHERE id = ?", [$paid, $status, (int)$documentId]);
        return DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$documentId]);
    }
}
