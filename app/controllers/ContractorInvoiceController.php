<?php
/**
 * ContractorInvoiceController — contractors bill VGo from inside VGo.
 *
 * A contractor uploads their monthly invoice, VGo reads the figures off it,
 * they confirm and submit. Accounting sees it in a queue beside the original
 * document and either approves it — which creates the bill, ready to pay — or
 * rejects it with a reason the contractor can act on.
 *
 * Four rules hold this together, and each exists because of a specific way
 * money goes missing:
 *
 *  1. IDENTITY COMES FROM THE SESSION. Who is owed is the signed-in submitter,
 *     never the name printed on the page. Otherwise anyone could invoice you as
 *     somebody else.
 *
 *  2. A SUBMISSION IS NOT A BILL. Nothing reaches payables until a person has
 *     approved it, so an unreviewed figure can never be paid on its way past.
 *
 *  3. THE SAME PERIOD IS NOT BILLED TWICE. An identical invoice number is
 *     refused outright; a second invoice covering a month already claimed needs
 *     an explicit confirmation. Duplicate monthly invoices are the single most
 *     likely error here, and they look completely normal.
 *
 *  4. NO PAYMENT CREDENTIALS ARE STORED. Bank, routing and SWIFT numbers are
 *     never extracted and have no column. The approver reads them off the
 *     original PDF, shown beside the figures on the approval screen.
 */
class ContractorInvoiceController
{
    const MAX_BYTES = 12582912;   // 12MB
    const ALLOWED_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'heic', 'heif'];
    const MIME_BY_EXT = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif',
    ];

    /** The module that owns approving these. */
    const APPROVER_MODULE = 'acc.bills';

    /* ============================================================
     * Guards
     * ============================================================ */

    private static function boot()
    {
        Schema::ensureUnifiedModules();
        AccSchema::ensure();
    }

    /** The signed-in user, with the contractor flag resolved. */
    private static function me()
    {
        $u = DB::fetch("SELECT id, name, email, role, is_contractor FROM users WHERE id = ?", [Auth::userId()]);
        if (!$u) jsonError('Not signed in', 401);
        return $u;
    }

    private static function requireContractor()
    {
        $u = self::me();
        if ((int)($u['is_contractor'] ?? 0) !== 1) {
            jsonError('Invoice submission is not enabled on your account. Ask an administrator to mark you as a contractor.', 403);
        }
        return $u;
    }

    private static function requireApprover()
    {
        self::boot();
        Authz::requireAccModule(self::APPROVER_MODULE);
    }

    private static function row($id, $forUser = null)
    {
        $r = DB::fetch("SELECT * FROM acc_contractor_invoices WHERE id = ? AND deleted_at IS NULL", [(int)$id]);
        if (!$r) jsonError('Invoice not found', 404);
        if ($forUser !== null && (int)$r['user_id'] !== (int)$forUser) jsonError('Invoice not found', 404);
        return $r;
    }

    /* ============================================================
     * Contractor — submitting
     * ============================================================ */

    /** Everything the contractor's own page needs, in one call. */
    public static function mine()
    {
        self::boot();
        $u = self::requireContractor();

        $rows = DB::fetchAll(
            "SELECT ci.*, d.number AS bill_number, d.status AS bill_status, d.due_at AS bill_due_at,
                    approver.name AS decided_by_name
               FROM acc_contractor_invoices ci
          LEFT JOIN acc_documents d ON d.id = ci.document_id AND d.deleted_at IS NULL
          LEFT JOIN users approver ON approver.id = ci.decided_by
              WHERE ci.user_id = ? AND ci.deleted_at IS NULL
           ORDER BY ci.id DESC LIMIT 100",
            [(int)$u['id']]
        );
        foreach ($rows as &$r) $r = self::present($r, false);
        unset($r);

        $vendor = DB::fetch(
            "SELECT id, name, currency_code FROM acc_contacts
              WHERE type = 'vendor' AND deleted_at IS NULL AND user_id = ? LIMIT 1",
            [(int)$u['id']]
        );

        jsonResponse([
            'invoices' => $rows,
            'me' => ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email']],
            'currency' => $vendor['currency_code'] ?? Acc::setting('default_currency', 'USD'),
            'ai_available' => self::aiAvailable(),
        ]);
    }

    /**
     * Read an uploaded invoice and hand back a draft.
     *
     * The file is parked but no row is created — a submission exists only once
     * the contractor has looked at what was read and pressed submit.
     */
    public static function extractUpload()
    {
        self::boot();
        $u = self::requireContractor();

        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $file = $_FILES['file'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            jsonError('That file is larger than this server accepts (limit ' . ini_get('upload_max_filesize') . ').');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload failed');
        if ($file['size'] <= 0) jsonError('That file is empty');
        if ($file['size'] > self::MAX_BYTES) jsonError('Invoices up to 12MB can be read. Try exporting a smaller PDF.');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            jsonError('Upload your invoice as a PDF. Photos (PNG, JPG) also work if that is all you have.');
        }
        $mime = self::MIME_BY_EXT[$ext];

        $dir = AccSchema::attachmentDir();
        if (!is_dir($dir) || !is_writable($dir)) jsonError('Attachment storage is not writable on the server');

        $safe   = ltrim(mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']), 0, 120), '.');
        $unique = 'cinv_' . (int)$u['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        $full   = $dir . '/' . $unique;
        if (!move_uploaded_file($file['tmp_name'], $full)) jsonError('Failed to save the file');
        @chmod($full, 0644);

        $draft = null; $error = null;
        try {
            // Read on the company's AI key, not the contractor's. They have no
            // reason to hold one, and asking them to would mean nobody's invoice
            // ever got read automatically.
            $draft = ContractorInvoiceExtractor::extract($full, $mime, self::aiKeyOwner((int)$u['id']));
        } catch (\Throwable $e) {
            // A failed read is not a failed submission. The file is kept and the
            // contractor fills the four fields in by hand — far better than
            // making them email it instead.
            $error = $e->getMessage();
            $draft = ContractorInvoiceExtractor::shape([]);
            $draft['warnings'][] = 'The details could not be read automatically, so please fill them in below.';
        }

        // Whoever the document says issued it, the money is owed to the person
        // signed in. Point out a mismatch rather than silently reassigning it.
        if (!empty($draft['contractor_name']) && !self::namesAgree($draft['contractor_name'], $u['name'])) {
            $draft['warnings'][] = 'This invoice is issued by “' . $draft['contractor_name']
                . '” but you are signed in as ' . $u['name'] . '. It will be submitted, and paid, as ' . $u['name'] . '.';
        }

        $draft['staged_path'] = AccSchema::ATTACHMENT_DIR . '/' . $unique;
        $draft['staged_name'] = mb_substr($file['name'], 0, 255);
        $draft['staged_mime'] = $mime;
        $draft['staged_size'] = (int)$file['size'];
        if (!$draft['currency']) $draft['currency'] = self::currencyFor((int)$u['id']);

        jsonResponse(['ok' => true, 'draft' => $draft, 'read_error' => $error]);
    }

    /** Create the submission. */
    public static function submit()
    {
        self::boot();
        $u = self::requireContractor();
        $data = input();

        $total = Acc::money($data['total'] ?? 0);
        if ($total <= 0) jsonError('Enter the amount you are invoicing for.');

        $number = Acc::strOrNull($data['invoice_number'] ?? null, 100);
        $issued = Acc::date($data['issued_at'] ?? null, date('Y-m-d'));

        $periodLabel = Acc::strOrNull($data['period_label'] ?? null, 100);
        $periodStart = Acc::date($data['period_start'] ?? null, null);
        $periodEnd   = Acc::date($data['period_end'] ?? null, null);
        if (!$periodStart && $periodLabel) {
            $g = ContractorInvoiceExtractor::monthFromText($periodLabel);
            if ($g) { $periodStart = $g['start']; $periodEnd = $g['end']; }
        }
        if (!$periodLabel && !$periodStart) jsonError('Say which period this invoice covers, e.g. “March 2026”.');
        if ($periodStart && $periodEnd && $periodEnd < $periodStart) jsonError('The period ends before it starts.');

        // Guard 1 — the same invoice number twice is always a mistake.
        if ($number !== null) {
            $clash = DB::fetch(
                "SELECT id, status FROM acc_contractor_invoices
                  WHERE user_id = ? AND deleted_at IS NULL AND status <> 'rejected'
                    AND invoice_number IS NOT NULL AND LOWER(invoice_number) = LOWER(?)
                  LIMIT 1",
                [(int)$u['id'], $number]
            );
            if ($clash) {
                jsonError('You have already submitted invoice ' . $number . '. Check your list — if this is a different invoice, give it its own number.', 409);
            }
        }

        // Guard 2 — a month already claimed needs saying so on purpose.
        if ($periodStart && empty($data['confirm_duplicate_period'])) {
            $overlap = DB::fetch(
                "SELECT id, period_label, status FROM acc_contractor_invoices
                  WHERE user_id = ? AND deleted_at IS NULL AND status IN ('submitted','approved')
                    AND period_start IS NOT NULL
                    AND period_start <= ? AND COALESCE(period_end, period_start) >= ?
                  LIMIT 1",
                [(int)$u['id'], $periodEnd ?: $periodStart, $periodStart]
            );
            if ($overlap) {
                jsonResponse([
                    'ok' => false,
                    'needs_confirmation' => 'duplicate_period',
                    'message' => 'You already have an invoice covering ' . ($overlap['period_label'] ?: 'this period')
                        . ' (' . $overlap['status'] . '). Submit this one as well only if it is genuinely for different work.',
                ], 409);
            }
        }

        $lines = self::cleanLines($data['line_items'] ?? [], $total, $periodLabel);
        $currency = Acc::strOrNull($data['currency'] ?? null, 8) ?: self::currencyFor((int)$u['id']);

        $extraction = [
            'confidence' => in_array($data['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $data['confidence'] : null,
            'warnings'   => array_slice(array_map(fn($w) => mb_substr((string)$w, 0, 300), (array)($data['warnings'] ?? [])), 0, 12),
            'read_name'  => Acc::strOrNull($data['contractor_name'] ?? null, 191),
            'edited'     => !empty($data['edited']),
        ];

        $id = self::tx(function () use ($u, $number, $issued, $periodLabel, $periodStart, $periodEnd, $currency, $data, $total, $lines, $extraction) {
            $vendor = self::vendorFor($u, $currency);
            return DB::insert('acc_contractor_invoices', [
                'user_id'       => (int)$u['id'],
                'contact_id'    => $vendor ? (int)$vendor['id'] : null,
                'status'        => 'submitted',
                'invoice_number'=> $number,
                'issued_at'     => $issued,
                'period_label'  => $periodLabel ?: ($periodStart ? date('F Y', strtotime($periodStart)) : null),
                'period_start'  => $periodStart,
                'period_end'    => $periodEnd,
                'currency_code' => strtoupper(mb_substr($currency, 0, 8)),
                'subtotal'      => isset($data['subtotal']) && $data['subtotal'] !== null && $data['subtotal'] !== '' ? Acc::money($data['subtotal']) : null,
                'tax_total'     => isset($data['tax_total']) && $data['tax_total'] !== null && $data['tax_total'] !== '' ? Acc::money($data['tax_total']) : null,
                'total'         => $total,
                'notes'         => Acc::strOrNull($data['notes'] ?? null, 2000),
                'line_items'    => json_encode($lines),
                'extraction'    => json_encode($extraction),
                'submitted_at'  => date('Y-m-d H:i:s'),
            ]);
        });

        // The paperwork travels with the claim from the first moment.
        $attachmentId = self::attachStaged((int)$id, $data);
        if ($attachmentId) {
            DB::query("UPDATE acc_contractor_invoices SET attachment_id = ? WHERE id = ?", [$attachmentId, (int)$id]);
        }

        self::notifyApprovers((int)$id, $u, $total, $currency, $periodLabel ?: ($periodStart ? date('F Y', strtotime($periodStart)) : ''));

        jsonResponse(['ok' => true, 'id' => (int)$id, 'attachment_id' => $attachmentId]);
    }

    /** Take a submission back while nobody has acted on it. */
    public static function withdraw($id)
    {
        self::boot();
        $u = self::requireContractor();
        $r = self::row($id, (int)$u['id']);
        if ($r['status'] !== 'submitted') {
            jsonError('This invoice has already been ' . $r['status'] . ' and can no longer be withdrawn.');
        }
        DB::query("UPDATE acc_contractor_invoices SET deleted_at = NOW() WHERE id = ?", [(int)$r['id']]);
        // The notification asking someone to review it should go too.
        try {
            DB::query(
                "UPDATE notifications SET is_read = 1 WHERE link_type = 'contractor_invoice' AND link_id = ?",
                [(int)$r['id']]
            );
        } catch (\Throwable $e) { /* tidying only */ }
        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * Approver — the queue
     * ============================================================ */

    public static function queue()
    {
        self::requireApprover();
        $status = in_array($_GET['status'] ?? 'submitted', ['submitted', 'approved', 'rejected', 'all'], true)
            ? ($_GET['status'] ?? 'submitted') : 'submitted';

        $where = "ci.deleted_at IS NULL";
        $params = [];
        if ($status !== 'all') { $where .= " AND ci.status = ?"; $params[] = $status; }

        $rows = DB::fetchAll(
            "SELECT ci.*, u.name AS contractor, u.email AS contractor_email,
                    c.name AS vendor_name,
                    d.number AS bill_number, d.status AS bill_status, d.due_at AS bill_due_at,
                    approver.name AS decided_by_name
               FROM acc_contractor_invoices ci
          LEFT JOIN users u ON u.id = ci.user_id
          LEFT JOIN acc_contacts c ON c.id = ci.contact_id
          LEFT JOIN acc_documents d ON d.id = ci.document_id AND d.deleted_at IS NULL
          LEFT JOIN users approver ON approver.id = ci.decided_by
              WHERE $where
           ORDER BY ci.submitted_at DESC, ci.id DESC LIMIT 200",
            $params
        );
        foreach ($rows as &$r) $r = self::present($r, true);
        unset($r);

        $counts = DB::fetch(
            "SELECT SUM(status = 'submitted') AS submitted,
                    SUM(status = 'approved') AS approved,
                    SUM(status = 'rejected') AS rejected
               FROM acc_contractor_invoices WHERE deleted_at IS NULL"
        );

        jsonResponse([
            'invoices' => $rows,
            'status' => $status,
            'counts' => [
                'submitted' => (int)($counts['submitted'] ?? 0),
                'approved'  => (int)($counts['approved'] ?? 0),
                'rejected'  => (int)($counts['rejected'] ?? 0),
            ],
        ]);
    }

    public static function detail($id)
    {
        self::requireApprover();
        $r = self::row($id);
        $extra = DB::fetch(
            "SELECT u.name AS contractor, u.email AS contractor_email, u.is_contractor,
                    c.name AS vendor_name, c.id AS vendor_id,
                    d.number AS bill_number, d.status AS bill_status, d.due_at AS bill_due_at,
                    approver.name AS decided_by_name
               FROM acc_contractor_invoices ci
          LEFT JOIN users u ON u.id = ci.user_id
          LEFT JOIN acc_contacts c ON c.id = ci.contact_id
          LEFT JOIN acc_documents d ON d.id = ci.document_id AND d.deleted_at IS NULL
          LEFT JOIN users approver ON approver.id = ci.decided_by
              WHERE ci.id = ?",
            [(int)$r['id']]
        );
        $row = self::present(array_merge($r, $extra ?: []), true);

        // What else this contractor has claimed, so a repeat month is obvious
        // to the approver even when the submitter confirmed past the guard.
        $row['history'] = DB::fetchAll(
            "SELECT id, status, period_label, period_start, total, currency_code, invoice_number, submitted_at
               FROM acc_contractor_invoices
              WHERE user_id = ? AND id <> ? AND deleted_at IS NULL
           ORDER BY id DESC LIMIT 8",
            [(int)$r['user_id'], (int)$r['id']]
        );

        jsonResponse([
            'invoice' => $row,
            'options' => [
                'categories' => DB::fetchAll("SELECT id, name FROM acc_categories WHERE deleted_at IS NULL AND enabled = 1 AND type IN ('expense','other') ORDER BY name"),
            ],
        ]);
    }

    /**
     * Approve — this is the moment a payable comes into existence.
     *
     * The approver's figures win over the contractor's: they have the original
     * document on screen beside the fields, and they are the ones signing off.
     */
    public static function approve($id)
    {
        self::requireApprover();
        $r = self::row($id);
        if ($r['status'] === 'approved') jsonError('This invoice has already been approved.');
        if ($r['status'] !== 'submitted') jsonError('Only a submitted invoice can be approved.');

        $data = input();
        $total = isset($data['total']) && $data['total'] !== '' ? Acc::money($data['total']) : Acc::money($r['total']);
        if ($total <= 0) jsonError('The amount must be more than zero.');

        $user = DB::fetch("SELECT id, name, email FROM users WHERE id = ?", [(int)$r['user_id']]);
        if (!$user) jsonError('The person who submitted this no longer has an account.', 409);

        $currency = Acc::strOrNull($data['currency'] ?? null, 8) ?: $r['currency_code'];
        $issued   = Acc::date($data['issued_at'] ?? null, $r['issued_at'] ?: date('Y-m-d'));
        $due      = Acc::date($data['due_at'] ?? null, date('Y-m-d', strtotime($issued . ' +15 days')));
        $categoryId = Acc::intOrNull($data['category_id'] ?? null);
        $note     = Acc::strOrNull($data['note'] ?? null, 1000);

        $lines = json_decode((string)$r['line_items'], true);
        if (!is_array($lines) || !count($lines)) {
            $lines = [['name' => ($r['period_label'] ?: 'Contractor services'), 'quantity' => 1, 'price' => $total]];
        }
        // The approver's total is authoritative, so a single-line invoice follows
        // it rather than keeping a figure nobody signed off.
        if (count($lines) === 1) {
            $lines[0]['quantity'] = 1;
            $lines[0]['price'] = $total;
        }

        $result = self::tx(function () use ($r, $user, $currency, $issued, $due, $categoryId, $lines, $total, $note) {
            $vendor = self::vendorFor($user, $currency);
            if (!$vendor) throw new Exception('A vendor record could not be created for this contractor.');

            $number = Acc::reserveNumber('bill');
            $docId = DB::insert('acc_documents', [
                'type'          => 'bill',
                'number'        => $number,
                'order_number'  => $r['invoice_number'],
                'status'        => 'draft',
                'issued_at'     => $issued,
                'due_at'        => $due,
                'amount'        => 0,
                'paid_amount'   => 0,
                'currency_code' => strtoupper(mb_substr($currency, 0, 8)),
                'contact_id'    => (int)$vendor['id'],
                'category_id'   => $categoryId,
                'notes'         => trim(($r['period_label'] ? 'Contractor invoice — ' . $r['period_label'] : 'Contractor invoice')
                                     . ($r['notes'] ? "\n" . $r['notes'] : '')),
            ]);

            Acc::writeDocumentLines($docId, array_map(fn($li) => [
                'name'     => $li['name'],
                'quantity' => $li['quantity'] ?? 1,
                'price'    => $li['price'] ?? ($li['unit_price'] ?? 0),
                'tax_ids'  => [],
            ], $lines));
            Acc::addHistory($docId, 'draft', 'Created from ' . $user['name'] . '’s submitted invoice');

            // Straight to received: approval IS the acceptance of the payable,
            // and this posts it to the ledger so it shows in what is owed.
            DB::query("UPDATE acc_documents SET status = 'open' WHERE id = ?", [(int)$docId]);
            $fresh = DB::fetch("SELECT * FROM acc_documents WHERE id = ?", [(int)$docId]);
            Acc::postBillReceived($fresh);
            Acc::addHistory($docId, 'open', 'Approved for payment');

            // The original document follows the claim onto the bill, so the
            // paperwork and the payable never drift apart.
            if ($r['attachment_id']) {
                DB::query(
                    "UPDATE acc_attachments SET attachable_type = 'document', attachable_id = ? WHERE id = ?",
                    [(int)$docId, (int)$r['attachment_id']]
                );
            }

            DB::query(
                "UPDATE acc_contractor_invoices
                    SET status = 'approved', document_id = ?, contact_id = ?, total = ?, currency_code = ?,
                        issued_at = ?, decided_at = NOW(), decided_by = ?, decision_note = ?
                  WHERE id = ?",
                [(int)$docId, (int)$vendor['id'], $total, strtoupper(mb_substr($currency, 0, 8)),
                 $issued, Auth::userId(), $note, (int)$r['id']]
            );

            return ['document_id' => (int)$docId, 'number' => $number, 'due_at' => $due];
        });

        self::notifyUser(
            (int)$r['user_id'], 'contractor_invoice_decision',
            'Invoice approved',
            'Your invoice' . ($r['period_label'] ? ' for ' . $r['period_label'] : '') . ' was approved for payment'
                . ' (' . self::amountText($total, $currency) . '), due ' . date('j M Y', strtotime($result['due_at'])) . '.',
            (int)$r['id']
        );

        jsonResponse(['ok' => true] + $result);
    }

    public static function reject($id)
    {
        self::requireApprover();
        $r = self::row($id);
        if ($r['status'] !== 'submitted') jsonError('Only a submitted invoice can be rejected.');

        $data = input();
        $reason = Acc::strOrNull($data['note'] ?? null, 1000);
        // A rejection without a reason just means the contractor resubmits the
        // same thing, so the reason is required rather than encouraged.
        if ($reason === null || mb_strlen($reason) < 3) {
            jsonError('Say why it is being sent back, so it can be corrected and resubmitted.');
        }

        DB::query(
            "UPDATE acc_contractor_invoices
                SET status = 'rejected', decided_at = NOW(), decided_by = ?, decision_note = ?
              WHERE id = ?",
            [Auth::userId(), $reason, (int)$r['id']]
        );

        self::notifyUser(
            (int)$r['user_id'], 'contractor_invoice_decision',
            'Invoice sent back',
            'Your invoice' . ($r['period_label'] ? ' for ' . $r['period_label'] : '') . ' needs a change: ' . $reason,
            (int)$r['id']
        );

        jsonResponse(['ok' => true]);
    }

    /* ============================================================
     * The original document, for either side
     * ============================================================ */

    /**
     * Stream the submitted file. Authorised for the person who submitted it and
     * for anyone who can approve it — deliberately not routed through the
     * accounting attachment endpoint, which would mean giving contractors an
     * accounting grant to see their own invoice back.
     */
    public static function file($id)
    {
        self::boot();
        $r = self::row($id);
        $isOwner = (int)$r['user_id'] === (int)Auth::userId();
        if (!$isOwner && !Authz::hasModuleAccess(self::APPROVER_MODULE)) {
            jsonError('You do not have access to this invoice', 403);
        }
        if (!$r['attachment_id']) jsonError('No document was attached to this invoice', 404);

        $att = DB::fetch("SELECT * FROM acc_attachments WHERE id = ?", [(int)$r['attachment_id']]);
        if (!$att) jsonError('That file is no longer on the server', 404);

        $dir  = realpath(AccSchema::attachmentDir());
        $full = realpath(dirname(dirname(__DIR__)) . '/' . ltrim($att['path'], '/'));
        if (!$dir || !$full || strpos($full, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
            jsonError('That file is no longer on the server', 404);
        }

        $mime = $att['mime'] ?: 'application/octet-stream';
        $inline = isset($_GET['inline']) && $_GET['inline'] === '1'
            && preg_match('#^(image/|application/pdf)#i', $mime);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . str_replace('"', '', $att['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        readfile($full);
        exit;
    }

    /* ============================================================
     * Hook — closing the loop when the bill is actually paid
     * ============================================================ */

    /**
     * Called from Acc::syncDocumentPaymentState whenever a document's payment
     * state settles. Kept here rather than in Acc so the ledger has no idea
     * contractor invoices exist; all three ways a bill gets paid (a manual
     * transaction, a payment against the document, or a line accepted from a
     * bank statement) run through that one function, so this catches them all.
     */
    public static function onDocumentSettled($doc, $previousStatus)
    {
        if (!$doc || ($doc['type'] ?? '') !== 'bill') return;
        if (($doc['status'] ?? '') !== 'paid' || $previousStatus === 'paid') return;

        try {
            $ci = DB::fetch(
                "SELECT * FROM acc_contractor_invoices
                  WHERE document_id = ? AND deleted_at IS NULL AND paid_at IS NULL LIMIT 1",
                [(int)$doc['id']]
            );
            if (!$ci) return;
            DB::query("UPDATE acc_contractor_invoices SET paid_at = NOW() WHERE id = ?", [(int)$ci['id']]);
            self::notifyUser(
                (int)$ci['user_id'], 'contractor_invoice_paid',
                'Invoice paid',
                'Your invoice' . ($ci['period_label'] ? ' for ' . $ci['period_label'] : '')
                    . ' has been paid (' . self::amountText($ci['total'], $ci['currency_code']) . ').',
                (int)$ci['id']
            );
        } catch (\Throwable $e) {
            error_log('ContractorInvoiceController::onDocumentSettled: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    /** One vendor record per contractor, reused for life. */
    private static function vendorFor($user, $currency = null)
    {
        $existing = DB::fetch(
            "SELECT * FROM acc_contacts WHERE type = 'vendor' AND deleted_at IS NULL AND user_id = ? LIMIT 1",
            [(int)$user['id']]
        );
        if ($existing) return $existing;

        // Adopt a vendor already created by hand for this person rather than
        // making a second one that splits their payment history in two.
        if (!empty($user['email'])) {
            $byEmail = DB::fetch(
                "SELECT * FROM acc_contacts WHERE type = 'vendor' AND deleted_at IS NULL
                   AND email IS NOT NULL AND LOWER(email) = LOWER(?) AND user_id IS NULL LIMIT 1",
                [$user['email']]
            );
            if ($byEmail) {
                DB::query("UPDATE acc_contacts SET user_id = ? WHERE id = ?", [(int)$user['id'], (int)$byEmail['id']]);
                $byEmail['user_id'] = (int)$user['id'];
                return $byEmail;
            }
        }

        $id = DB::insert('acc_contacts', [
            'type'          => 'vendor',
            'name'          => mb_substr($user['name'], 0, 191),
            'email'         => Acc::strOrNull($user['email'] ?? null, 191),
            'currency_code' => strtoupper(mb_substr($currency ?: Acc::setting('default_currency', 'USD'), 0, 8)),
            'user_id'       => (int)$user['id'],
            'enabled'       => 1,
        ]);
        return DB::fetch("SELECT * FROM acc_contacts WHERE id = ?", [(int)$id]);
    }

    private static function currencyFor($userId)
    {
        $v = DB::fetch(
            "SELECT currency_code FROM acc_contacts WHERE type = 'vendor' AND deleted_at IS NULL AND user_id = ? LIMIT 1",
            [(int)$userId]
        );
        return $v['currency_code'] ?? Acc::setting('default_currency', 'USD');
    }

    /** Coerce submitted lines; fall back to one line for the whole amount. */
    private static function cleanLines($raw, $total, $periodLabel)
    {
        $out = [];
        foreach ((array)$raw as $li) {
            if (!is_array($li)) continue;
            $name = Acc::strOrNull($li['name'] ?? null, 191);
            if ($name === null) continue;
            $qty = Acc::num($li['quantity'] ?? 1, 1);
            if ($qty == 0) $qty = 1;
            $price = Acc::num($li['unit_price'] ?? ($li['price'] ?? 0), 0);
            $out[] = ['name' => $name, 'quantity' => round($qty, 4), 'price' => round($price, 4)];
        }
        if (!$out) {
            $out[] = ['name' => $periodLabel ? $periodLabel . ' — contractor services' : 'Contractor services',
                      'quantity' => 1, 'price' => $total];
        }
        return $out;
    }

    private static function attachStaged($invoiceId, array $data)
    {
        if (empty($data['staged_path'])) return null;
        try {
            $dir  = AccSchema::attachmentDir();
            $real = realpath($dir . '/' . basename((string)$data['staged_path']));
            $base = realpath($dir);
            if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) return null;

            return (int)DB::insert('acc_attachments', [
                'attachable_type' => 'contractor_invoice',
                'attachable_id'   => (int)$invoiceId,
                'name'            => Acc::strOrNull($data['staged_name'] ?? null, 255) ?: 'invoice',
                'path'            => AccSchema::ATTACHMENT_DIR . '/' . basename($real),
                'mime'            => Acc::strOrNull($data['staged_mime'] ?? null, 120) ?: 'application/pdf',
                'size'            => (int)($data['staged_size'] ?? filesize($real)),
                'uploaded_by'     => Auth::userId(),
            ]);
        } catch (\Throwable $e) {
            error_log('ContractorInvoiceController::attachStaged: ' . $e->getMessage());
            return null;
        }
    }

    /** Shape a row for the client, decoding the JSON columns. */
    private static function present(array $r, $forApprover)
    {
        $r['total']      = $r['total'] === null ? null : (float)$r['total'];
        $r['subtotal']   = $r['subtotal'] === null ? null : (float)$r['subtotal'];
        $r['tax_total']  = $r['tax_total'] === null ? null : (float)$r['tax_total'];
        $r['line_items'] = json_decode((string)$r['line_items'], true) ?: [];
        $extraction      = json_decode((string)$r['extraction'], true) ?: [];
        $r['extraction'] = $forApprover ? $extraction : ['confidence' => $extraction['confidence'] ?? null];
        $r['has_file']   = !empty($r['attachment_id']);
        // A paid bill is the real end state, and it lives on the document.
        $r['display_status'] = $r['paid_at'] ? 'paid' : $r['status'];
        return $r;
    }

    private static function amountText($amount, $currency)
    {
        return strtoupper((string)$currency) . ' ' . number_format((float)$amount, 2);
    }

    /** Do the name on the page and the name on the account plausibly agree? */
    private static function namesAgree($a, $b)
    {
        $norm = function ($s) {
            $s = mb_strtolower(trim((string)$s));
            $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s);
            return trim(preg_replace('/\s+/', ' ', $s));
        };
        $a = $norm($a); $b = $norm($b);
        if ($a === '' || $b === '') return true;
        if ($a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false) return true;
        // Any two shared name parts is enough — people drop middle names.
        $pa = array_filter(explode(' ', $a), fn($w) => strlen($w) > 2);
        $pb = array_filter(explode(' ', $b), fn($w) => strlen($w) > 2);
        return count(array_intersect($pa, $pb)) >= 2;
    }

    private static function notifyApprovers($invoiceId, $user, $total, $currency, $period)
    {
        try {
            $ids = Authz::usersWithAccModule(self::APPROVER_MODULE);
            foreach ($ids as $uid) {
                if ((int)$uid === (int)$user['id']) continue;
                NotificationController::create(
                    (int)$uid,
                    'contractor_invoice_submitted',
                    'Invoice from ' . $user['name'],
                    $user['name'] . ' submitted an invoice' . ($period ? ' for ' . $period : '')
                        . ' — ' . self::amountText($total, $currency) . ', waiting for approval.',
                    'contractor_invoice',
                    (int)$invoiceId
                );
            }
        } catch (\Throwable $e) {
            error_log('ContractorInvoiceController::notifyApprovers: ' . $e->getMessage());
        }
    }

    private static function notifyUser($userId, $type, $title, $body, $invoiceId)
    {
        try {
            NotificationController::create((int)$userId, $type, $title, $body, 'contractor_invoice', (int)$invoiceId);
        } catch (\Throwable $e) {
            error_log('ContractorInvoiceController::notifyUser: ' . $e->getMessage());
        }
    }

    /**
     * Whose AI key pays for reading the document.
     *
     * The contractor's own is tried last and only as a courtesy: AI keys live
     * per user, and a contractor has no reason to have configured one. The
     * company's key does this work, so the accounting owner and the approvers
     * come first.
     */
    private static function aiKeyOwner($submitterId)
    {
        $candidates = [];
        try {
            $owner = DB::fetch("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1", [Authz::ACC_OWNER_EMAIL]);
            if ($owner) $candidates[] = (int)$owner['id'];
            foreach (Authz::usersWithAccModule(self::APPROVER_MODULE) as $uid) $candidates[] = (int)$uid;
            foreach (DB::fetchAll("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 10") as $a) {
                $candidates[] = (int)$a['id'];
            }
        } catch (\Throwable $e) { /* fall through to the submitter */ }
        $candidates[] = (int)$submitterId;

        foreach (array_unique($candidates) as $uid) {
            try {
                if (AiClient::resolveProvider($uid, 'pdf')) return $uid;
            } catch (\Throwable $e) { /* try the next */ }
        }
        return (int)$submitterId;
    }

    /**
     * Can a PDF actually be read here? Drives whether the upload page promises
     * to fill the fields in — better to say "type it in" up front than to spin
     * for twenty seconds and then admit it.
     */
    private static function aiAvailable()
    {
        try {
            return AiClient::resolveProvider(self::aiKeyOwner((int)Auth::userId()), 'pdf') !== null;
        } catch (\Throwable $e) {
            return true;   // assume yes; a failed read falls back to manual entry
        }
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
}
