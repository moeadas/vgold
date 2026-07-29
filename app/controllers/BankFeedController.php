<?php
/**
 * BankFeedController — bank statement import and the review queue that follows.
 *
 * The shape is QuickBooks': a statement arrives, its rows land in a queue, and
 * each row is either MATCHED to something already recorded, ADDED as a new
 * transaction, or EXCLUDED as none of VGold's business. Only then does the
 * reconcile screen have a set of cleared items to tick against a closing
 * balance.
 *
 * Two deliberate refusals:
 *  - Import never happens off the back of the upload. The upload proposes a
 *    reading of the file; a person confirms it. A misread date column is
 *    invisible after the fact and expensive to unpick.
 *  - Auto-matching only fires when exactly one candidate is plausible
 *    (see BankMatcher). Ambiguity goes to the queue, not to a coin toss.
 */
class BankFeedController
{
    /** Statements are text; 8MB is a decade of transactions. */
    const MAX_BYTES = 8388608;
    const ALLOWED_EXT = ['csv', 'txt', 'tsv', 'ofx', 'qfx'];

    private static function boot()
    {
        AccSchema::ensure();
        Authz::requireAccModule('acc.banking');
    }

    private static function account($id)
    {
        $a = DB::fetch("SELECT * FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$a) jsonError('Select an account', 404);
        return $a;
    }

    /* ============================================================
     * 1 — upload and propose a reading
     * ============================================================ */

    public static function preview()
    {
        self::boot();

        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $file = $_FILES['file'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            jsonError('That file is larger than this server accepts (limit ' . ini_get('upload_max_filesize') . ').');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload failed');
        if ($file['size'] <= 0) jsonError('That file is empty');
        if ($file['size'] > self::MAX_BYTES) jsonError('Statements up to 8MB can be read. Export a shorter date range.');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            jsonError('Upload a CSV, OFX or QFX statement. Most banks offer all three under “export” or “download transactions”.');
        }

        $dir = AccSchema::attachmentDir();
        if (!is_dir($dir) || !is_writable($dir)) jsonError('Attachment storage is not writable on the server');

        $safe   = ltrim(mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']), 0, 120), '.');
        $unique = 'stmt_' . (int)Auth::userId() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        $full   = $dir . '/' . $unique;
        if (!move_uploaded_file($file['tmp_name'], $full)) jsonError('Failed to save the file');
        @chmod($full, 0644);

        try {
            $sniff = StatementParser::sniff($full, $file['name']);
        } catch (\Throwable $e) {
            @unlink($full);
            jsonError($e->getMessage(), 422);
        }

        $sniff['staged_path'] = AccSchema::ATTACHMENT_DIR . '/' . $unique;
        $sniff['staged_name'] = mb_substr($file['name'], 0, 255);
        $sniff['staged_size'] = (int)$file['size'];
        $sniff['accounts'] = DB::fetchAll(
            "SELECT id, name, bank_name, number, currency_code, balance
               FROM acc_accounts WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name"
        );
        jsonResponse(['ok' => true, 'preview' => $sniff]);
    }

    /**
     * Re-read a staged file under a mapping the user has adjusted, without
     * importing. This is what makes the mapping screen honest: you see the
     * dates and amounts your choices actually produce.
     */
    public static function reparse()
    {
        self::boot();
        $data = input();
        $path = self::stagedPath($data['staged_path'] ?? '');
        $mapping = self::cleanMapping($data['mapping'] ?? []);

        try {
            $parsed = StatementParser::rows($path, $mapping);
        } catch (\Throwable $e) {
            jsonError($e->getMessage(), 422);
        }

        $rows = $parsed['rows'];
        jsonResponse([
            'ok' => true,
            'sample' => array_slice($rows, 0, 12),
            'rows_total' => count($rows),
            'skipped' => array_slice($parsed['skipped'], 0, 12),
            'skipped_total' => count($parsed['skipped']),
            'summary' => self::summarise($rows),
        ]);
    }

    /* ============================================================
     * 2 — commit the import
     * ============================================================ */

    public static function commit()
    {
        self::boot();
        $data = input();
        $account = self::account($data['account_id'] ?? 0);
        $path = self::stagedPath($data['staged_path'] ?? '');
        $mapping = self::cleanMapping($data['mapping'] ?? []);

        if (($mapping['format'] ?? 'csv') !== 'ofx') {
            if (($mapping['date'] ?? null) === null) jsonError('Choose which column holds the date.');
            if (($mapping['amount'] ?? null) === null && ($mapping['debit'] ?? null) === null && ($mapping['credit'] ?? null) === null) {
                jsonError('Choose which column holds the amount, or a pair of money-in and money-out columns.');
            }
            if (empty($mapping['date_format'])) jsonError('Choose how the dates in this file are written.');
        }

        try {
            $parsed = StatementParser::rows($path, $mapping);
        } catch (\Throwable $e) {
            jsonError($e->getMessage(), 422);
        }
        $rows = $parsed['rows'];
        if (!count($rows)) jsonError('No usable rows were found with that mapping. Check the date and amount columns.', 422);

        // If every amount is positive and nothing says otherwise, the file has
        // not been understood — importing would post every withdrawal as income.
        if (!count(array_filter($rows, fn($r) => (float)$r['amount'] < 0))
            && ($mapping['format'] ?? 'csv') !== 'ofx'
            && ($mapping['debit'] ?? null) === null && ($mapping['type'] ?? null) === null) {
            jsonError('Every row in this file is positive, so nothing marks the withdrawals. Map a “Money out” column or a debit/credit marker before importing.', 422);
        }

        $accountId = (int)$account['id'];
        $summary = self::summarise($rows);

        // Multiset difference against what is already stored: re-uploading an
        // overlapping statement adds only the rows that are genuinely new,
        // while two identical charges on one day both survive.
        $keyed = [];
        foreach ($rows as $r) {
            $key = StatementParser::dedupeKey($accountId, $r);
            $keyed[$key][] = $r;
        }
        $existing = [];
        if (count($keyed)) {
            $keys = array_keys($keyed);
            foreach (array_chunk($keys, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                foreach (DB::fetchAll(
                    "SELECT dedupe_key, COUNT(*) AS n, MAX(occurrence) AS mx
                       FROM acc_bank_lines WHERE account_id = ? AND dedupe_key IN ($in)
                   GROUP BY dedupe_key",
                    array_merge([$accountId], $chunk)
                ) as $e) {
                    $existing[$e['dedupe_key']] = ['n' => (int)$e['n'], 'mx' => (int)$e['mx']];
                }
            }
        }

        $importId = DB::insert('acc_bank_imports', [
            'account_id'      => $accountId,
            'filename'        => mb_substr((string)($data['staged_name'] ?? basename($path)), 0, 255),
            'format'          => ($mapping['format'] ?? 'csv') === 'ofx' ? 'ofx' : 'csv',
            'statement_start' => $summary['first_date'],
            'statement_end'   => $summary['last_date'],
            'closing_balance' => isset($data['closing_balance']) && $data['closing_balance'] !== '' && $data['closing_balance'] !== null
                                    ? Acc::money($data['closing_balance']) : $summary['closing_balance'],
            'total_rows'      => count($rows),
            'imported_rows'   => 0,
            'duplicate_rows'  => 0,
            'skipped_rows'    => count($parsed['skipped']),
            'mapping'         => json_encode($mapping),
            'uploaded_by'     => Auth::userId(),
        ]);

        $imported = 0; $duplicates = 0;
        foreach ($keyed as $key => $group) {
            $have = $existing[$key]['n'] ?? 0;
            $next = isset($existing[$key]) ? $existing[$key]['mx'] + 1 : 0;
            $new = array_slice($group, $have);           // the tail is what is new
            $duplicates += min($have, count($group));
            foreach ($new as $r) {
                try {
                    DB::insert('acc_bank_lines', [
                        'import_id'     => (int)$importId,
                        'account_id'    => $accountId,
                        'posted_at'     => $r['posted_at'],
                        'amount'        => round((float)$r['amount'], 4),
                        'description'   => mb_substr((string)$r['description'], 0, 500),
                        'payee'         => $r['payee'] ? mb_substr((string)$r['payee'], 0, 191) : null,
                        'reference'     => $r['reference'] ? mb_substr((string)$r['reference'], 0, 100) : null,
                        'balance_after' => $r['balance'] === null ? null : round((float)$r['balance'], 4),
                        'fitid'         => $r['fitid'] ? mb_substr((string)$r['fitid'], 0, 120) : null,
                        'dedupe_key'    => $key,
                        'occurrence'    => $next,
                        'status'        => 'pending',
                    ]);
                    $imported++;
                    $next++;
                } catch (\Throwable $e) {
                    // The unique key is the last line of defence against a double
                    // submit; a collision here means the row is already stored.
                    $duplicates++;
                }
            }
        }

        DB::query(
            "UPDATE acc_bank_imports SET imported_rows = ?, duplicate_rows = ? WHERE id = ?",
            [$imported, $duplicates, (int)$importId]
        );

        // Keep the original file with the import, so the numbers can always be
        // checked against what the bank actually sent.
        $attachmentId = self::attachStatement((int)$importId, $path, (string)($data['staged_name'] ?? ''), (int)($data['staged_size'] ?? 0));

        jsonResponse([
            'ok' => true,
            'id' => (int)$importId,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'skipped' => count($parsed['skipped']),
            'attachment_id' => $attachmentId,
        ]);
    }

    /* ============================================================
     * 3 — the review queue
     * ============================================================ */

    public static function imports()
    {
        self::boot();
        $rows = DB::fetchAll(
            "SELECT i.*, a.name AS account_name, u.name AS uploaded_by_name,
                    (SELECT COUNT(*) FROM acc_bank_lines l WHERE l.import_id = i.id AND l.status = 'pending') AS pending
               FROM acc_bank_imports i
          LEFT JOIN acc_accounts a ON a.id = i.account_id
          LEFT JOIN users u ON u.id = i.uploaded_by
              WHERE i.deleted_at IS NULL
           ORDER BY i.id DESC LIMIT 50"
        );
        jsonResponse(['imports' => $rows, 'pending_total' => self::pendingCount()]);
    }

    /** Count of statement lines still waiting on a decision, for the nav badge. */
    public static function pendingCount()
    {
        try {
            $r = DB::fetch("SELECT COUNT(*) AS n FROM acc_bank_lines WHERE status = 'pending'");
            return (int)($r['n'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The queue for one account: pending lines with their suggested match and,
     * where there is none, the category this payee was given last time.
     */
    public static function review()
    {
        self::boot();
        $accountId = (int)($_GET['account_id'] ?? 0);
        if (!$accountId) {
            $first = DB::fetch("SELECT account_id FROM acc_bank_lines WHERE status = 'pending' ORDER BY id DESC LIMIT 1");
            $accountId = (int)($first['account_id'] ?? 0);
        }
        if (!$accountId) {
            jsonResponse(['account' => null, 'lines' => [], 'counts' => ['pending' => 0, 'accepted' => 0, 'excluded' => 0], 'accounts' => self::accountList()]);
        }
        $account = self::account($accountId);

        $status = in_array($_GET['status'] ?? 'pending', ['pending', 'accepted', 'excluded'], true) ? $_GET['status'] : 'pending';
        $where = $status === 'accepted' ? "l.status IN ('matched','added')" : "l.status = " . ($status === 'excluded' ? "'excluded'" : "'pending'");

        $lines = DB::fetchAll(
            "SELECT l.*, t.description AS transaction_description, t.paid_at AS transaction_date,
                    t.amount AS transaction_amount, t.type AS transaction_type,
                    c.name AS transaction_contact, d.number AS transaction_document
               FROM acc_bank_lines l
          LEFT JOIN acc_transactions t ON t.id = l.transaction_id AND t.deleted_at IS NULL
          LEFT JOIN acc_contacts c ON c.id = t.contact_id
          LEFT JOIN acc_documents d ON d.id = t.document_id
              WHERE l.account_id = ? AND $where
           ORDER BY l.posted_at DESC, l.id DESC
              LIMIT 400",
            [$accountId]
        );

        $counts = DB::fetch(
            "SELECT
                SUM(status = 'pending') AS pending,
                SUM(status IN ('matched','added')) AS accepted,
                SUM(status = 'excluded') AS excluded
               FROM acc_bank_lines WHERE account_id = ?",
            [$accountId]
        );

        $suggestions = [];
        if ($status === 'pending' && count($lines)) {
            try {
                $suggestions = BankMatcher::suggestAll($lines, $accountId);
            } catch (\Throwable $e) {
                error_log('BankFeedController::review match: ' . $e->getMessage());
            }
            foreach ($lines as &$l) {
                $s = $suggestions[(int)$l['id']] ?? null;
                $l['match'] = $s;
                $l['recall'] = null;
                if (!$s || $s['confidence'] === 'none' || $s['confidence'] === 'low') {
                    try { $l['recall'] = BankMatcher::recall($l['description'], (float)$l['amount'] > 0); }
                    catch (\Throwable $e) { $l['recall'] = null; }
                }
            }
            unset($l);
        }

        jsonResponse([
            'account'  => $account,
            'accounts' => self::accountList(),
            'status'   => $status,
            'lines'    => $lines,
            'counts'   => [
                'pending'  => (int)($counts['pending'] ?? 0),
                'accepted' => (int)($counts['accepted'] ?? 0),
                'excluded' => (int)($counts['excluded'] ?? 0),
            ],
            'options'  => [
                'categories' => DB::fetchAll("SELECT id, name, type FROM acc_categories WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name"),
                'contacts'   => DB::fetchAll("SELECT id, name, type FROM acc_contacts WHERE deleted_at IS NULL AND enabled = 1 ORDER BY name LIMIT 500"),
            ],
        ]);
    }

    private static function accountList()
    {
        return DB::fetchAll(
            "SELECT a.id, a.name, a.balance,
                    (SELECT COUNT(*) FROM acc_bank_lines l WHERE l.account_id = a.id AND l.status = 'pending') AS pending
               FROM acc_accounts a WHERE a.deleted_at IS NULL AND a.enabled = 1 ORDER BY a.name"
        );
    }

    /* ============================================================
     * 4 — deciding a line
     * ============================================================ */

    private static function line($id)
    {
        $l = DB::fetch("SELECT * FROM acc_bank_lines WHERE id = ?", [(int)$id]);
        if (!$l) jsonError('Statement line not found', 404);
        return $l;
    }

    /** Link a statement line to a transaction already in VGold. */
    public static function matchLine($id)
    {
        self::boot();
        $line = self::line($id);
        if ($line['status'] !== 'pending') jsonError('That line has already been dealt with.');

        $data = input();
        $txId = (int)($data['transaction_id'] ?? 0);
        $tx = DB::fetch("SELECT * FROM acc_transactions WHERE id = ? AND deleted_at IS NULL", [$txId]);
        if (!$tx) jsonError('That transaction no longer exists', 404);
        if ((int)$tx['account_id'] !== (int)$line['account_id']) jsonError('That transaction is on a different account.');
        if (!empty($tx['bank_line_id'])) jsonError('That transaction is already matched to another statement line.');

        $signed = ($tx['type'] === 'income' ? 1 : -1) * round((float)$tx['amount'], 2);
        if (abs($signed - round((float)$line['amount'], 2)) > 0.005) {
            jsonError('The amounts do not agree — the statement says ' . number_format((float)$line['amount'], 2)
                . ' and the transaction is ' . number_format($signed, 2) . '.');
        }

        // `reconciled` is the legacy name for cleared and is still read in a few
        // list views, so the two are always written together.
        DB::query(
            "UPDATE acc_transactions SET bank_line_id = ?, cleared_at = COALESCE(cleared_at, NOW()), reconciled = 1 WHERE id = ?",
            [(int)$line['id'], $txId]
        );
        self::decide($line['id'], 'matched', $txId, $data['confidence'] ?? null);
        jsonResponse(['ok' => true, 'transaction_id' => $txId]);
    }

    /**
     * Create a transaction from a statement line.
     *
     * The line's own amount and date are authoritative — the form cannot change
     * them, because a transaction that does not agree with the statement it
     * came from would break the reconciliation it exists to serve.
     */
    public static function addLine($id)
    {
        self::boot();
        $line = self::line($id);
        if ($line['status'] !== 'pending') jsonError('That line has already been dealt with.');

        $data = input();
        $amount = round(abs((float)$line['amount']), 4);
        if ($amount <= 0) jsonError('That line has no amount.');
        $type = (float)$line['amount'] > 0 ? 'income' : 'expense';

        $categoryId = Acc::intOrNull($data['category_id'] ?? null);
        $contactId  = Acc::intOrNull($data['contact_id'] ?? null);
        $documentId = Acc::intOrNull($data['document_id'] ?? null);

        $doc = null;
        if ($documentId) {
            $doc = DB::fetch("SELECT * FROM acc_documents WHERE id = ? AND deleted_at IS NULL", [$documentId]);
            if (!$doc) jsonError('That invoice or bill no longer exists', 404);
            $wantType = $type === 'income' ? 'invoice' : 'bill';
            if ($doc['type'] !== $wantType) {
                jsonError($type === 'income' ? 'Money received can only be applied to an invoice.' : 'Money paid out can only be applied to a bill.');
            }
        }

        $description = trim((string)($data['description'] ?? $line['description']));
        $accountId = (int)$line['account_id'];

        $txId = self::tx(function () use ($line, $type, $amount, $accountId, $categoryId, $contactId, $doc, $description) {
            $id = DB::insert('acc_transactions', [
                'type'           => $type,
                'paid_at'        => $line['posted_at'],
                'amount'         => $amount,
                'currency_code'  => Acc::setting('default_currency', 'USD'),
                'account_id'     => $accountId,
                'document_id'    => $doc ? (int)$doc['id'] : null,
                'contact_id'     => $contactId ?: ($doc['contact_id'] ?? null),
                'category_id'    => $categoryId,
                'description'    => mb_substr($description, 0, 1000),
                'payment_method' => 'bank_transfer',
                'reference'      => Acc::strOrNull($line['reference'] ?? null),
                'is_transfer'    => 0,
                'bank_line_id'   => (int)$line['id'],
                'cleared_at'     => date('Y-m-d H:i:s'),
                'reconciled'     => 1,
            ]);
            if ($doc) {
                Acc::applyPayment($doc, $id, $amount, [], $line['posted_at']);
                Acc::syncDocumentPaymentState($doc['id']);
                Acc::addHistory($doc['id'], 'payment', 'Matched bank statement line of ' . number_format($amount, 2));
            }
            Acc::recalcAccount($accountId);
            return $id;
        });

        self::decide($line['id'], 'added', (int)$txId, null);
        jsonResponse(['ok' => true, 'transaction_id' => (int)$txId]);
    }

    /** Mark a line as none of VGold's business (an internal transfer, a duplicate). */
    public static function excludeLine($id)
    {
        self::boot();
        $line = self::line($id);
        if ($line['status'] !== 'pending') jsonError('That line has already been dealt with.');
        self::decide($line['id'], 'excluded', null, null);
        jsonResponse(['ok' => true]);
    }

    /**
     * Put a decided line back in the queue.
     *
     * An added transaction is deleted with it — leaving it behind would double
     * the account balance the moment the line is dealt with again.
     */
    public static function undoLine($id)
    {
        self::boot();
        $line = self::line($id);
        if ($line['status'] === 'pending') jsonError('That line is already waiting for review.');

        // Checked before the transaction opens: jsonError() ends the request, and
        // aborting mid-transaction is a worse way to say no.
        if ($line['transaction_id']) {
            $locked = DB::fetch(
                "SELECT id FROM acc_transactions WHERE id = ? AND reconciliation_id IS NOT NULL",
                [(int)$line['transaction_id']]
            );
            if ($locked) jsonError('That transaction is part of a finished reconciliation. Reopen the reconciliation first.');
        }

        self::tx(function () use ($line) {
            if ($line['status'] === 'added' && $line['transaction_id']) {
                $tx = DB::fetch("SELECT * FROM acc_transactions WHERE id = ? AND deleted_at IS NULL", [(int)$line['transaction_id']]);
                if ($tx) {
                    Acc::unapplyPayment((int)$tx['id'], 'Bank statement line returned to review');
                    DB::query("UPDATE acc_transactions SET deleted_at = NOW() WHERE id = ?", [(int)$tx['id']]);
                    Acc::recalcAccount((int)$tx['account_id']);
                }
            } elseif ($line['status'] === 'matched' && $line['transaction_id']) {
                DB::query(
                    "UPDATE acc_transactions SET bank_line_id = NULL, cleared_at = NULL, reconciled = 0
                      WHERE id = ? AND reconciliation_id IS NULL",
                    [(int)$line['transaction_id']]
                );
            }
            DB::query(
                "UPDATE acc_bank_lines SET status = 'pending', transaction_id = NULL, match_confidence = NULL,
                        decided_by = NULL, decided_at = NULL WHERE id = ?",
                [(int)$line['id']]
            );
        });
        jsonResponse(['ok' => true]);
    }

    /**
     * Accept every line whose match is unambiguous.
     *
     * Deliberately limited to `high` confidence: this is the one action here
     * that touches many rows without anyone looking at them individually.
     */
    public static function acceptMatches()
    {
        self::boot();
        $data = input();
        $accountId = (int)($data['account_id'] ?? 0);
        self::account($accountId);

        $lines = DB::fetchAll(
            "SELECT * FROM acc_bank_lines WHERE account_id = ? AND status = 'pending' ORDER BY posted_at, id LIMIT 400",
            [$accountId]
        );
        if (!count($lines)) jsonResponse(['ok' => true, 'accepted' => 0]);

        $suggestions = BankMatcher::suggestAll($lines, $accountId);
        $accepted = 0; $used = [];
        foreach ($lines as $line) {
            $s = $suggestions[(int)$line['id']] ?? null;
            if (!$s || $s['confidence'] !== 'high' || !$s['best']) continue;
            $txId = (int)$s['best'];
            // Two lines cannot both claim the same transaction.
            if (isset($used[$txId])) continue;
            $ok = DB::query(
                "UPDATE acc_transactions SET bank_line_id = ?, cleared_at = COALESCE(cleared_at, NOW()), reconciled = 1
                  WHERE id = ? AND deleted_at IS NULL AND bank_line_id IS NULL",
                [(int)$line['id'], $txId]
            )->rowCount();
            if (!$ok) continue;
            self::decide($line['id'], 'matched', $txId, 'high');
            $used[$txId] = true;
            $accepted++;
        }
        jsonResponse(['ok' => true, 'accepted' => $accepted]);
    }

    /** Candidate invoices/bills a statement line could be applied to. */
    public static function lineDocuments($id)
    {
        self::boot();
        $line = self::line($id);
        $type = (float)$line['amount'] > 0 ? 'invoice' : 'bill';
        $amount = abs((float)$line['amount']);

        // Ordered by how close the outstanding balance is to this line, because
        // the one that settles it exactly is almost always the right one.
        $rows = DB::fetchAll(
            "SELECT d.id, d.number, d.type, d.status, d.issued_at, d.due_at, d.amount, d.paid_amount,
                    d.contact_id, c.name AS contact_name,
                    (d.amount - d.paid_amount) AS balance
               FROM acc_documents d
          LEFT JOIN acc_contacts c ON c.id = d.contact_id
              WHERE d.deleted_at IS NULL AND d.type = ?
                AND d.status NOT IN ('cancelled','paid')
                AND (d.amount - d.paid_amount) > 0.005
           ORDER BY ABS((d.amount - d.paid_amount) - ?) ASC, d.due_at ASC
              LIMIT 40",
            [$type, $amount]
        );
        foreach ($rows as &$r) $r['display_status'] = Acc::displayStatus($r);
        unset($r);
        jsonResponse(['documents' => $rows, 'expected_type' => $type]);
    }

    public static function deleteImport($id)
    {
        self::boot();
        $imp = DB::fetch("SELECT * FROM acc_bank_imports WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$imp) jsonError('Import not found', 404);

        $decided = DB::fetch(
            "SELECT COUNT(*) AS n FROM acc_bank_lines WHERE import_id = ? AND status <> 'pending'",
            [(int)$id]
        );
        if ((int)($decided['n'] ?? 0) > 0) {
            jsonError('Some lines from this statement have already been matched or added. Undo those first, or leave the import in place — it is the record of where those transactions came from.');
        }
        DB::query("DELETE FROM acc_bank_lines WHERE import_id = ?", [(int)$id]);
        DB::query("UPDATE acc_bank_imports SET deleted_at = NOW() WHERE id = ?", [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    private static function decide($lineId, $status, $transactionId, $confidence)
    {
        DB::query(
            "UPDATE acc_bank_lines SET status = ?, transaction_id = ?, match_confidence = ?,
                    decided_by = ?, decided_at = NOW() WHERE id = ?",
            [$status, $transactionId ?: null, $confidence, Auth::userId(), (int)$lineId]
        );
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

    /** Resolve a staged upload path, refusing anything outside the store. */
    private static function stagedPath($rel)
    {
        $rel = (string)$rel;
        if ($rel === '') jsonError('The uploaded file is no longer available. Upload it again.');
        $dir  = AccSchema::attachmentDir();
        $real = realpath($dir . '/' . basename($rel));
        $base = realpath($dir);
        if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) {
            jsonError('The uploaded file is no longer available. Upload it again.');
        }
        return $real;
    }

    /** Only the keys a mapping is allowed to contain, coerced to safe types. */
    private static function cleanMapping($raw)
    {
        if (!is_array($raw)) $raw = [];
        $idx = function ($v) {
            if ($v === null || $v === '' || $v === false) return null;
            return (int)$v;
        };
        $format = ($raw['format'] ?? 'csv') === 'ofx' ? 'ofx' : 'csv';
        $delim = (string)($raw['delimiter'] ?? ',');
        if (!in_array($delim, StatementParser::DELIMITERS, true)) $delim = ',';

        $fmt = $raw['date_format'] ?? null;
        if ($fmt !== null && $fmt !== 'textual' && !array_key_exists($fmt, StatementParser::DATE_FORMATS)) $fmt = null;

        return [
            'format'         => $format,
            'delimiter'      => $delim,
            'data_from'      => max(0, (int)($raw['data_from'] ?? 0)),
            'date'           => $idx($raw['date'] ?? null),
            'description'    => $idx($raw['description'] ?? null),
            'payee'          => $idx($raw['payee'] ?? null),
            'amount'         => $idx($raw['amount'] ?? null),
            'debit'          => $idx($raw['debit'] ?? null),
            'credit'         => $idx($raw['credit'] ?? null),
            'balance'        => $idx($raw['balance'] ?? null),
            'reference'      => $idx($raw['reference'] ?? null),
            'type'           => $idx($raw['type'] ?? null),
            'date_format'    => $fmt,
            'amount_sign'    => ($raw['amount_sign'] ?? 'natural') === 'unsigned' ? 'unsigned' : 'natural',
            'debit_negative' => !empty($raw['debit_negative']),
        ];
    }

    private static function summarise(array $rows)
    {
        $dates = array_values(array_filter(array_column($rows, 'posted_at')));
        sort($dates);
        $in = 0.0; $out = 0.0;
        $lastBalance = null;
        foreach ($rows as $r) {
            $a = (float)$r['amount'];
            if ($a >= 0) $in += $a; else $out += -$a;
        }
        // The running balance on the newest row is the statement's closing figure.
        $withBalance = array_values(array_filter($rows, fn($r) => $r['balance'] !== null && $r['posted_at']));
        if (count($withBalance)) {
            usort($withBalance, fn($a, $b) => [$a['posted_at'], 0] <=> [$b['posted_at'], 0]);
            $lastBalance = round((float)end($withBalance)['balance'], 4);
        }
        return [
            'count' => count($rows),
            'money_in' => round($in, 2),
            'money_out' => round($out, 2),
            'net' => round($in - $out, 2),
            'first_date' => $dates[0] ?? null,
            'last_date' => count($dates) ? end($dates) : null,
            'closing_balance' => $lastBalance,
        ];
    }

    private static function attachStatement($importId, $realPath, $name, $size)
    {
        try {
            $dir = AccSchema::attachmentDir();
            $id = DB::insert('acc_attachments', [
                'attachable_type' => 'bank_import',
                'attachable_id'   => (int)$importId,
                'name'            => mb_substr($name ?: basename($realPath), 0, 255),
                'path'            => AccSchema::ATTACHMENT_DIR . '/' . basename($realPath),
                'mime'            => 'text/plain',
                'size'            => $size ?: (int)@filesize($realPath),
                'uploaded_by'     => Auth::userId(),
            ]);
            return (int)$id;
        } catch (\Throwable $e) {
            error_log('BankFeedController::attachStatement: ' . $e->getMessage());
            return null;
        }
    }
}
