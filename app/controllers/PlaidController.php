<?php
/**
 * Plaid bank feed for the Accounting app.
 *
 * Design note: Plaid does not get a parallel transaction store. A synced
 * transaction becomes an ordinary row in acc_bank_imports / acc_bank_lines,
 * exactly like a line parsed out of a PDF statement, so the matcher, the Bank
 * Review screen and the reconciliation engine treat both identically and
 * nothing downstream had to learn a second shape.
 *
 * Nothing here ever posts to the ledger. Lines arrive as `pending` and wait for
 * a human in Bank Review — that was an explicit product decision.
 */
class PlaidController
{
    const FITID_PREFIX = 'PLD:';

    private static function boot()
    {
        AccSchema::ensure();
        Authz::requireAccModule('acc.banking');
        PlaidSchema::ensure();
    }

    // ===================================================================
    // Status / configuration
    // ===================================================================

    public static function status()
    {
        self::boot();
        $st = Plaid::status();
        $conns = [];
        if (PlaidSchema::ready()) {
            foreach (DB::fetchAll("SELECT * FROM acc_bank_connections WHERE deleted_at IS NULL ORDER BY id DESC") as $c) {
                $accts = DB::fetchAll(
                    "SELECT pa.*, a.name AS ledger_name, a.number AS ledger_number
                       FROM acc_plaid_accounts pa
                  LEFT JOIN acc_accounts a ON a.id = pa.account_id
                      WHERE pa.connection_id = ? ORDER BY pa.id", [(int)$c['id']]);
                $conns[] = self::publicConnection($c, $accts);
            }
        }
        // Ledger accounts the user can map onto, with the cutover date we would
        // default to for each (day after its newest existing bank line).
        $ledger = [];
        foreach (DB::fetchAll("SELECT id,name,bank_name,number,type,currency_code FROM acc_accounts WHERE deleted_at IS NULL ORDER BY id") as $a) {
            $mx = DB::fetch("SELECT MAX(posted_at) m FROM acc_bank_lines WHERE account_id = ?", [(int)$a['id']]);
            $a['latest_line'] = $mx['m'] ?? null;
            $a['suggested_sync_from'] = $mx['m'] ? date('Y-m-d', strtotime($mx['m'] . ' +1 day')) : null;
            $ledger[] = $a;
        }
        jsonResponse(['plaid' => $st, 'connections' => $conns, 'ledger_accounts' => $ledger]);
    }

    /** Never returns the token or the secret — only whether they are present. */
    private static function publicConnection(array $c, array $accts = []): array
    {
        return [
            'id' => (int)$c['id'],
            'environment' => $c['environment'],
            'institution_name' => $c['institution_name'],
            'institution_id' => $c['institution_id'],
            'status' => $c['status'],
            'error_code' => $c['error_code'],
            'error_message' => $c['error_message'],
            'consent_expires_at' => $c['consent_expires_at'],
            'disconnect_at' => $c['disconnect_at'],
            'last_sync_at' => $c['last_sync_at'],
            'last_sync_status' => $c['last_sync_status'],
            'last_webhook_at' => $c['last_webhook_at'],
            'needs_reauth' => in_array($c['status'], ['login_required','pending_disconnect','pending_expiration','revoked'], true),
            'accounts' => array_map(fn($a) => [
                'id' => (int)$a['id'],
                'plaid_account_id' => $a['plaid_account_id'],
                'name' => $a['name'], 'official_name' => $a['official_name'],
                'mask' => $a['mask'], 'type' => $a['type'], 'subtype' => $a['subtype'],
                'current_balance' => $a['current_balance'], 'available_balance' => $a['available_balance'],
                'account_id' => $a['account_id'] ? (int)$a['account_id'] : null,
                'ledger_name' => $a['ledger_name'] ?? null,
                'ledger_number' => $a['ledger_number'] ?? null,
                'sync_from' => $a['sync_from'], 'enabled' => (int)$a['enabled'] === 1,
            ], $accts),
        ];
    }

    /**
     * Write credentials from the Settings panel into config/plaid.local.php.
     *
     * The file sits above the docroot and is gitignored, so this is the only
     * safe place for them; pasting keys into a chat or a repo is the failure
     * mode this endpoint exists to prevent. Blank fields leave the stored value
     * alone, so the panel never has to echo a secret back to be re-submitted.
     */
    public static function saveConfig()
    {
        AccSchema::ensure();
        Authz::requireAccModule('acc.settings');
        $d = input();
        $cur = Plaid::config();

        $clientId = trim((string)($d['client_id'] ?? ''));
        $sandbox  = trim((string)($d['sandbox_secret'] ?? ''));
        $prod     = trim((string)($d['production_secret'] ?? ''));
        $env      = ($d['env'] ?? '') === 'production' ? 'production' : 'sandbox';

        $next = [
            'client_id' => $clientId !== '' ? $clientId : (string)$cur['client_id'],
            'env' => $env,
            'secrets' => [
                'sandbox'    => $sandbox !== '' ? $sandbox : (string)($cur['secrets']['sandbox'] ?? ''),
                'production' => $prod !== ''    ? $prod    : (string)($cur['secrets']['production'] ?? ''),
            ],
            'redirect_uri' => (string)($cur['redirect_uri'] ?: 'https://' . APP_HOST . '/plaid/oauth'),
            'webhook_uri'  => (string)($cur['webhook_uri']  ?: 'https://' . APP_HOST . '/api/acc/plaid/webhook'),
            // Generated once and never rotated silently — rotating it would
            // orphan every stored access_token.
            'token_key'    => (string)($cur['token_key'] ?: bin2hex(random_bytes(24))),
        ];

        if ($env === 'production' && $next['secrets']['production'] === '') {
            jsonError('Add the Production secret before switching the environment to production.', 422);
        }

        $php = "<?php\n// Plaid credentials — NOT IN GIT, NOT WEB-SERVABLE.\n"
             . "// Written by Accounting → Settings → Plaid. Do not edit by hand while the app is running.\n"
             . 'return ' . var_export($next, true) . ";\n";
        $path = Plaid::configPath();
        if (@file_put_contents($path, $php, LOCK_EX) === false) {
            jsonError('Could not write the credentials file. Check permissions on config/.', 500);
        }
        @chmod($path, 0600);
        Plaid::reloadConfig();

        // Prove the keys work rather than reporting a hopeful success.
        [$ok, $j] = Plaid::call('/institutions/get_by_id', [
            'institution_id' => 'ins_127287', 'country_codes' => ['US'],
        ], 20);
        jsonResponse([
            'ok' => true,
            'plaid' => Plaid::status(),
            'verified' => $ok,
            'verify_error' => $ok ? null : Plaid::errText($j),
        ]);
    }

    // ===================================================================
    // Link
    // ===================================================================

    /**
     * Create a Link token.
     *
     * With `connection_id` this is UPDATE MODE — the re-auth path used for
     * consent expiry, a BofA migration notice, or any ITEM_LOGIN_REQUIRED.
     * Update mode reuses the existing Item instead of creating a second one,
     * which is what keeps the cursor and the account mapping intact.
     */
    public static function linkToken()
    {
        self::boot();
        $st = Plaid::status();
        if (!$st['configured']) jsonError('Plaid is not configured yet. Add the client ID and secret in Accounting → Settings.', 422);

        $d = input();
        $cfg = Plaid::config();
        $body = [
            'user' => ['client_user_id' => 'vgo-ws' . (int)Auth::workspaceId()],
            'client_name' => 'VGo Accounting',
            'country_codes' => ['US'],
            'language' => 'en',
            'redirect_uri' => $cfg['redirect_uri'],
            'webhook' => $cfg['webhook_uri'],
        ];

        $connId = (int)($d['connection_id'] ?? 0);
        if ($connId) {
            $conn = self::connection($connId);
            $tok = Plaid::openToken((string)$conn['access_token']);
            if ($tok === '') jsonError('Stored bank token could not be read. Disconnect and reconnect this bank.', 422);
            $body['access_token'] = $tok;      // update mode: no `products`
        } else {
            $body['products'] = ['transactions'];
        }

        [$ok, $j] = Plaid::call('/link/token/create', $body);
        if (!$ok) jsonError('Plaid rejected the request: ' . Plaid::errText($j), 422);
        jsonResponse(['link_token' => $j['link_token'], 'expiration' => $j['expiration'] ?? null, 'update_mode' => $connId > 0]);
    }

    /**
     * Exchange the public token for an access token and record the Item.
     *
     * Accounts discovered here are stored UNMAPPED. Auto-creating ledger
     * accounts would let a personal savings account picked by accident in the
     * bank's consent screen appear in the chart of accounts, so mapping is an
     * explicit act. We do suggest a match by mask.
     */
    public static function exchange()
    {
        self::boot();
        $d = input();
        $publicToken = trim((string)($d['public_token'] ?? ''));
        if ($publicToken === '') jsonError('Missing public_token');
        if (!PlaidSchema::ready()) jsonError('Bank connection tables are unavailable.', 500);

        [$ok, $j] = Plaid::call('/item/public_token/exchange', ['public_token' => $publicToken]);
        if (!$ok) jsonError('Token exchange failed: ' . Plaid::errText($j), 422);

        $accessToken = (string)$j['access_token'];
        $itemId = (string)$j['item_id'];

        $instId = null; $instName = null; $consentExpires = null;
        [$iok, $ij] = Plaid::call('/item/get', ['access_token' => $accessToken]);
        if ($iok) {
            $instId = $ij['item']['institution_id'] ?? null;
            $consentExpires = $ij['item']['consent_expiration_time'] ?? null;
            if ($instId) {
                [$nok, $nj] = Plaid::call('/institutions/get_by_id', ['institution_id' => $instId, 'country_codes' => ['US']]);
                if ($nok) $instName = $nj['institution']['name'] ?? null;
            }
        }

        $existing = DB::fetch("SELECT * FROM acc_bank_connections WHERE item_id = ?", [$itemId]);
        $row = [
            'provider' => 'plaid',
            'environment' => Plaid::env(),
            'item_id' => $itemId,
            'institution_id' => $instId,
            'institution_name' => $instName,
            'access_token' => Plaid::sealToken($accessToken),
            'status' => 'active',
            'error_code' => null, 'error_message' => null,
            'consent_expires_at' => $consentExpires ? date('Y-m-d H:i:s', strtotime($consentExpires)) : null,
            'disconnect_at' => null,
            'created_by' => Auth::userId(),
        ];
        if ($existing) {
            // Re-linking the same Item (update mode) must keep the cursor.
            unset($row['created_by']);
            DB::update('acc_bank_connections', $row, 'id = ?', [(int)$existing['id']]);
            $connId = (int)$existing['id'];
        } else {
            $connId = (int)DB::insert('acc_bank_connections', $row);
        }

        self::refreshAccounts($connId, $accessToken);

        $conn = DB::fetch("SELECT * FROM acc_bank_connections WHERE id = ?", [$connId]);
        $accts = DB::fetchAll(
            "SELECT pa.*, a.name AS ledger_name, a.number AS ledger_number
               FROM acc_plaid_accounts pa LEFT JOIN acc_accounts a ON a.id = pa.account_id
              WHERE pa.connection_id = ? ORDER BY pa.id", [$connId]);
        jsonResponse(['ok' => true, 'connection' => self::publicConnection($conn, $accts)]);
    }

    /**
     * Pull the Item's accounts and upsert them, suggesting a ledger mapping by
     * matching the last four digits against acc_accounts.number.
     */
    private static function refreshAccounts(int $connId, string $accessToken): void
    {
        [$ok, $j] = Plaid::call('/accounts/get', ['access_token' => $accessToken]);
        if (!$ok) return;
        $ledger = DB::fetchAll("SELECT id, number FROM acc_accounts WHERE deleted_at IS NULL");

        foreach (($j['accounts'] ?? []) as $a) {
            $pid  = (string)($a['account_id'] ?? '');
            if ($pid === '') continue;
            $mask = (string)($a['mask'] ?? '');

            $suggest = null;
            if ($mask !== '') {
                foreach ($ledger as $l) {
                    $digits = preg_replace('/\D+/', '', (string)$l['number']);
                    if ($digits !== '' && substr($digits, -strlen($mask)) === $mask) { $suggest = (int)$l['id']; break; }
                }
            }
            $syncFrom = null;
            if ($suggest) {
                $mx = DB::fetch("SELECT MAX(posted_at) m FROM acc_bank_lines WHERE account_id = ?", [$suggest]);
                if (!empty($mx['m'])) $syncFrom = date('Y-m-d', strtotime($mx['m'] . ' +1 day'));
            }

            $fields = [
                'connection_id' => $connId,
                'plaid_account_id' => $pid,
                'name' => mb_substr((string)($a['name'] ?? ''), 0, 191),
                'official_name' => $a['official_name'] ? mb_substr((string)$a['official_name'], 0, 191) : null,
                'mask' => $mask !== '' ? $mask : null,
                'type' => (string)($a['type'] ?? ''),
                'subtype' => (string)($a['subtype'] ?? ''),
                'currency_code' => $a['balances']['iso_currency_code'] ?? 'USD',
                'current_balance' => isset($a['balances']['current']) ? round((float)$a['balances']['current'], 4) : null,
                'available_balance' => isset($a['balances']['available']) ? round((float)$a['balances']['available'], 4) : null,
            ];
            $have = DB::fetch("SELECT id, account_id FROM acc_plaid_accounts WHERE plaid_account_id = ?", [$pid]);
            if ($have) {
                DB::update('acc_plaid_accounts', $fields, 'id = ?', [(int)$have['id']]);
            } else {
                DB::insert('acc_plaid_accounts', $fields + ['account_id' => $suggest, 'sync_from' => $syncFrom, 'enabled' => 1]);
            }
        }
    }

    /** Map (or unmap) one Plaid account onto a ledger account. */
    public static function mapAccount($id)
    {
        self::boot();
        $d = input();
        $pa = DB::fetch("SELECT * FROM acc_plaid_accounts WHERE id = ?", [(int)$id]);
        if (!$pa) jsonError('Unknown bank account', 404);

        $fields = [];
        if (array_key_exists('account_id', $d)) {
            $aid = (int)$d['account_id'];
            if ($aid) {
                if (!DB::fetch("SELECT id FROM acc_accounts WHERE id = ? AND deleted_at IS NULL", [$aid]))
                    jsonError('Unknown ledger account', 404);
                // One ledger account cannot be fed by two Plaid accounts, or the
                // same balance would be reconciled twice.
                $clash = DB::fetch("SELECT id FROM acc_plaid_accounts WHERE account_id = ? AND id != ?", [$aid, (int)$id]);
                if ($clash) jsonError('That ledger account is already linked to another bank account.', 422);
            }
            $fields['account_id'] = $aid ?: null;
        }
        if (array_key_exists('sync_from', $d)) {
            $s = trim((string)$d['sync_from']);
            $fields['sync_from'] = $s !== '' ? date('Y-m-d', strtotime($s)) : null;
        }
        if (array_key_exists('enabled', $d)) $fields['enabled'] = !empty($d['enabled']) ? 1 : 0;
        if ($fields) DB::update('acc_plaid_accounts', $fields, 'id = ?', [(int)$id]);
        jsonResponse(['ok' => true]);
    }

    public static function disconnect($id)
    {
        self::boot();
        $conn = self::connection((int)$id);
        $tok = Plaid::openToken((string)$conn['access_token']);
        if ($tok !== '') Plaid::call('/item/remove', ['access_token' => $tok], 20);
        // Keep the row (soft delete) so synced history stays explainable, but
        // destroy the token — a removed Item's token is useless and holding it
        // is pure liability.
        DB::update('acc_bank_connections',
            ['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'revoked', 'access_token' => null],
            'id = ?', [(int)$conn['id']]);
        jsonResponse(['ok' => true]);
    }

    private static function connection(int $id): array
    {
        $c = DB::fetch("SELECT * FROM acc_bank_connections WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$c) jsonError('Unknown bank connection', 404);
        return $c;
    }

    // ===================================================================
    // Sync
    // ===================================================================

    public static function syncNow($id)
    {
        self::boot();
        $conn = self::connection((int)$id);
        $res = self::syncConnection($conn);
        jsonResponse($res);
    }

    /**
     * Walk /transactions/sync to exhaustion and write the results into
     * acc_bank_lines.
     *
     * Two guards matter here and both exist because these accounts already hold
     * years of PDF-imported history:
     *   1. `sync_from` — per account, ignore anything on or before the cutover.
     *   2. a content-level duplicate check — same account, same day, same
     *      amount, similar text. Plaid's transaction_id can never match a
     *      fitid minted by the PDF importer, so identity dedupe alone would
     *      happily double-count the overlap.
     */
    public static function syncConnection(array $conn): array
    {
        if (!PlaidSchema::ready()) return ['ok' => false, 'error' => 'tables unavailable'];
        $token = Plaid::openToken((string)$conn['access_token']);
        if ($token === '') return ['ok' => false, 'error' => 'stored token unreadable — reconnect required'];

        $map = [];
        foreach (DB::fetchAll("SELECT * FROM acc_plaid_accounts WHERE connection_id = ?", [(int)$conn['id']]) as $pa) {
            if (!(int)$pa['enabled'] || !$pa['account_id']) continue;
            $map[$pa['plaid_account_id']] = $pa;
        }
        if (!$map) {
            self::touchSync($conn, 'No accounts are mapped to the ledger yet.');
            return ['ok' => true, 'added' => 0, 'modified' => 0, 'removed' => 0, 'skipped_duplicate' => 0,
                    'skipped_before_cutover' => 0, 'note' => 'no mapped accounts'];
        }

        $cursor = (string)($conn['cursor'] ?? '');
        $added = []; $modified = []; $removed = [];
        $guard = 0;

        do {
            $body = ['access_token' => $token, 'count' => 500];
            if ($cursor !== '') $body['cursor'] = $cursor;
            [$ok, $j] = Plaid::call('/transactions/sync', $body, 60);

            if (!$ok) {
                $code = (string)($j['error_code'] ?? '');
                // A cursor Plaid no longer recognises is recoverable: drop it and
                // re-read from the beginning. dedupe_key makes that safe.
                if ($code === 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION' || $code === 'INVALID_FIELD') {
                    if ($cursor !== '') { $cursor = ''; $added = $modified = $removed = []; continue; }
                }
                self::noteItemError($conn, $j);
                return ['ok' => false, 'error' => Plaid::errText($j)];
            }

            foreach (($j['added'] ?? []) as $t)    $added[] = $t;
            foreach (($j['modified'] ?? []) as $t) $modified[] = $t;
            foreach (($j['removed'] ?? []) as $t)  $removed[] = $t;
            $cursor = (string)($j['next_cursor'] ?? $cursor);
            $more = !empty($j['has_more']);
        } while ($more && ++$guard < 60);

        $stats = self::applyTransactions($conn, $map, $added, $modified, $removed);

        DB::update('acc_bank_connections', [
            'cursor' => $cursor,
            'status' => in_array($conn['status'], ['login_required','revoked'], true) ? 'active' : $conn['status'],
            'error_code' => null, 'error_message' => null,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_status' => sprintf('added %d, updated %d, removed %d, duplicates skipped %d, before cutover %d',
                $stats['added'], $stats['modified'], $stats['removed'], $stats['skipped_duplicate'], $stats['skipped_before_cutover']),
        ], 'id = ?', [(int)$conn['id']]);

        return ['ok' => true] + $stats;
    }

    private static function touchSync(array $conn, string $note): void
    {
        DB::update('acc_bank_connections',
            ['last_sync_at' => date('Y-m-d H:i:s'), 'last_sync_status' => mb_substr($note, 0, 500)],
            'id = ?', [(int)$conn['id']]);
    }

    private static function applyTransactions(array $conn, array $map, array $added, array $modified, array $removed): array
    {
        $s = ['added' => 0, 'modified' => 0, 'removed' => 0, 'skipped_duplicate' => 0,
              'skipped_before_cutover' => 0, 'skipped_unmapped' => 0];
        $importIds = [];

        foreach ($added as $t) {
            $pid = (string)($t['account_id'] ?? '');
            if (!isset($map[$pid])) { $s['skipped_unmapped']++; continue; }
            $pa = $map[$pid];
            $accountId = (int)$pa['account_id'];
            $row = self::toRow($t);

            if (!empty($pa['sync_from']) && $row['posted_at'] < $pa['sync_from']) { $s['skipped_before_cutover']++; continue; }

            $key = StatementParser::dedupeKey($accountId, $row);
            if (DB::fetch("SELECT id FROM acc_bank_lines WHERE account_id = ? AND dedupe_key = ?", [$accountId, $key])) {
                $s['skipped_duplicate']++; continue;
            }
            if (self::looksAlreadyImported($accountId, $row)) { $s['skipped_duplicate']++; continue; }

            if (!isset($importIds[$accountId])) $importIds[$accountId] = self::openImport($conn, $accountId);
            try {
                DB::insert('acc_bank_lines', [
                    'import_id' => $importIds[$accountId],
                    'account_id' => $accountId,
                    'posted_at' => $row['posted_at'],
                    'amount' => round((float)$row['amount'], 4),
                    'description' => mb_substr((string)$row['description'], 0, 500),
                    'payee' => $row['payee'] ? mb_substr((string)$row['payee'], 0, 191) : null,
                    'reference' => $row['reference'] ? mb_substr((string)$row['reference'], 0, 100) : null,
                    'balance_after' => null,
                    'fitid' => mb_substr((string)$row['fitid'], 0, 120),
                    'dedupe_key' => $key,
                    'occurrence' => 0,
                    'status' => 'pending',
                ]);
                $s['added']++;
            } catch (\Throwable $e) { $s['skipped_duplicate']++; }
        }

        // A modified transaction is usually a pending charge settling. Only
        // touch lines nobody has acted on — rewriting a line already matched to
        // a ledger transaction would silently change reconciled history.
        foreach ($modified as $t) {
            $pid = (string)($t['account_id'] ?? '');
            if (!isset($map[$pid])) continue;
            $accountId = (int)$map[$pid]['account_id'];
            $row = self::toRow($t);
            $line = DB::fetch("SELECT * FROM acc_bank_lines WHERE account_id = ? AND fitid = ?", [$accountId, $row['fitid']]);
            if (!$line || $line['status'] !== 'pending' || $line['transaction_id']) continue;
            DB::update('acc_bank_lines', [
                'posted_at' => $row['posted_at'],
                'amount' => round((float)$row['amount'], 4),
                'description' => mb_substr((string)$row['description'], 0, 500),
                'payee' => $row['payee'] ? mb_substr((string)$row['payee'], 0, 191) : null,
            ], 'id = ?', [(int)$line['id']]);
            $s['modified']++;
        }

        // Removed at the bank: delete only if still untouched, otherwise flag it
        // for a human. Deleting a line that has already been posted would leave
        // the ledger holding an entry with no evidence behind it.
        foreach ($removed as $r) {
            $fit = self::FITID_PREFIX . (string)($r['transaction_id'] ?? '');
            $line = DB::fetch("SELECT * FROM acc_bank_lines WHERE fitid = ?", [$fit]);
            if (!$line) continue;
            if ($line['status'] === 'pending' && !$line['transaction_id']) {
                DB::query("DELETE FROM acc_bank_lines WHERE id = ?", [(int)$line['id']]);
            } else {
                DB::update('acc_bank_lines', ['match_confidence' => 'removed_at_bank'], 'id = ?', [(int)$line['id']]);
            }
            $s['removed']++;
        }

        foreach ($importIds as $accountId => $importId) self::closeImport((int)$importId, (int)$accountId);
        return $s;
    }

    /**
     * Plaid's sign convention is the opposite of ours: it reports money leaving
     * the account as POSITIVE. acc_bank_lines is signed from the account's point
     * of view, so every amount is negated here. Getting this backwards would
     * post every payment as income.
     */
    private static function toRow(array $t): array
    {
        $desc = (string)($t['name'] ?? '');
        $merchant = (string)($t['merchant_name'] ?? '');
        if ($merchant !== '' && stripos($desc, $merchant) === false) $desc = $merchant . ' — ' . $desc;
        return [
            'posted_at' => (string)($t['date'] ?? date('Y-m-d')),
            'amount' => -1 * (float)($t['amount'] ?? 0),
            'description' => $desc !== '' ? $desc : 'Bank transaction',
            'payee' => $merchant !== '' ? $merchant : null,
            'reference' => $t['payment_meta']['reference_number'] ?? ($t['check_number'] ?? null),
            'balance' => null,
            'fitid' => self::FITID_PREFIX . (string)($t['transaction_id'] ?? ''),
        ];
    }

    /**
     * Does this transaction already exist from a different source?
     *
     * The three BofA accounts were loaded from PDF statements whose fitids are
     * unrelated to Plaid's ids, so identity dedupe cannot see the overlap. Same
     * account, same amount to the cent, within 4 days, and a shared run of the
     * description is enough to treat it as the same event — banks re-date
     * pending charges by a day or two, hence the window.
     */
    private static function looksAlreadyImported(int $accountId, array $row): bool
    {
        $amt = round((float)$row['amount'], 2);
        $cands = DB::fetchAll(
            "SELECT description, payee FROM acc_bank_lines
              WHERE account_id = ? AND ROUND(amount,2) = ?
                AND posted_at BETWEEN DATE_SUB(?, INTERVAL 4 DAY) AND DATE_ADD(?, INTERVAL 4 DAY)
                AND (fitid IS NULL OR fitid NOT LIKE '" . self::FITID_PREFIX . "%')",
            [$accountId, $amt, $row['posted_at'], $row['posted_at']]);
        if (!$cands) return false;

        $norm = fn($s) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s));
        $mine = $norm($row['description'] . ' ' . (string)$row['payee']);
        if ($mine === '') return true;   // same account, amount and day: assume same

        foreach ($cands as $c) {
            $theirs = $norm($c['description'] . ' ' . (string)$c['payee']);
            if ($theirs === '') continue;
            $short = strlen($mine) < strlen($theirs) ? $mine : $theirs;
            $long  = strlen($mine) < strlen($theirs) ? $theirs : $mine;
            $probe = substr($short, 0, max(6, (int)floor(strlen($short) * 0.6)));
            if ($probe !== '' && strpos($long, $probe) !== false) return true;
        }
        return false;
    }

    private static function openImport(array $conn, int $accountId): int
    {
        return (int)DB::insert('acc_bank_imports', [
            'account_id' => $accountId,
            'filename' => 'Plaid · ' . ($conn['institution_name'] ?: 'bank') . ' · ' . date('Y-m-d H:i'),
            'format' => 'plaid',
            'statement_start' => null, 'statement_end' => null, 'closing_balance' => null,
            'total_rows' => 0, 'imported_rows' => 0, 'duplicate_rows' => 0, 'skipped_rows' => 0,
            'mapping' => json_encode(['source' => 'plaid', 'item_id' => $conn['item_id']]),
            'uploaded_by' => Auth::userId() ?: null,
        ]);
    }

    /** Backfill the import's counters, or drop it if nothing landed. */
    private static function closeImport(int $importId, int $accountId): void
    {
        $r = DB::fetch("SELECT COUNT(*) c, MIN(posted_at) a, MAX(posted_at) b FROM acc_bank_lines WHERE import_id = ?", [$importId]);
        $n = (int)($r['c'] ?? 0);
        if (!$n) { DB::query("DELETE FROM acc_bank_imports WHERE id = ?", [$importId]); return; }
        DB::update('acc_bank_imports', [
            'total_rows' => $n, 'imported_rows' => $n,
            'statement_start' => $r['a'], 'statement_end' => $r['b'],
        ], 'id = ?', [$importId]);
    }

    private static function noteItemError(array $conn, array $j): void
    {
        $code = (string)($j['error_code'] ?? '');
        $status = $conn['status'];
        if ($code === 'ITEM_LOGIN_REQUIRED') $status = 'login_required';
        DB::update('acc_bank_connections', [
            'status' => $status, 'error_code' => $code ?: null,
            'error_message' => mb_substr((string)($j['error_message'] ?? ''), 0, 500),
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_status' => mb_substr(Plaid::errText($j), 0, 500),
        ], 'id = ?', [(int)$conn['id']]);
    }

    // ===================================================================
    // Webhook
    // ===================================================================

    /**
     * Plaid's webhook endpoint. UNAUTHENTICATED by necessity — Plaid has no
     * session — so the signature check below is the only thing standing between
     * this route and the open internet. It runs before anything is trusted, and
     * an unverified payload is recorded but never acted on.
     *
     * Always answers 200. Plaid retries non-2xx, and retrying will not fix a
     * payload we could not verify or an Item we do not know.
     */
    public static function webhook()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) $raw = '';
        $sig = $_SERVER['HTTP_PLAID_VERIFICATION'] ?? '';

        $body = json_decode($raw, true);
        if (!is_array($body)) $body = [];
        $type = (string)($body['webhook_type'] ?? '');
        $code = (string)($body['webhook_code'] ?? '');
        $itemId = (string)($body['item_id'] ?? '');

        PlaidSchema::ensure();
        [$verified, $note] = Plaid::verifyWebhook($raw, (string)$sig);

        $eventId = 0;
        try {
            $eventId = (int)DB::insert('acc_plaid_events', [
                'item_id' => $itemId ?: null,
                'webhook_type' => $type ?: null,
                'webhook_code' => $code ?: null,
                'verified' => $verified ? 1 : 0,
                'verify_note' => mb_substr($note, 0, 191),
                // Truncated on purpose: the envelope is what makes an incident
                // explainable, and nothing here should become a second copy of
                // the transaction data.
                'payload' => mb_substr($raw, 0, 4000),
            ]);
        } catch (\Throwable $e) { error_log('plaid event log: ' . $e->getMessage()); }

        if (!$verified) {
            error_log('Plaid webhook rejected: ' . $note);
            http_response_code(200);
            echo json_encode(['ok' => false, 'ignored' => 'unverified']);
            return;
        }

        $result = 'ignored';
        try {
            $conn = $itemId ? DB::fetch("SELECT * FROM acc_bank_connections WHERE item_id = ? AND deleted_at IS NULL", [$itemId]) : null;
            if ($conn) {
                DB::update('acc_bank_connections', ['last_webhook_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$conn['id']]);
                $result = self::handleWebhook($conn, $type, $code, $body);
            } else {
                $result = 'no matching connection';
            }
        } catch (\Throwable $e) {
            $result = 'error: ' . $e->getMessage();
            error_log('Plaid webhook handling: ' . $e->getMessage());
        }

        if ($eventId) {
            try { DB::update('acc_plaid_events', ['result' => mb_substr($result, 0, 500)], 'id = ?', [$eventId]); }
            catch (\Throwable $e) {}
        }
        http_response_code(200);
        echo json_encode(['ok' => true]);
    }

    private static function handleWebhook(array $conn, string $type, string $code, array $body): string
    {
        // --- transactions ---
        if ($type === 'TRANSACTIONS') {
            if (in_array($code, ['SYNC_UPDATES_AVAILABLE','INITIAL_UPDATE','HISTORICAL_UPDATE','DEFAULT_UPDATE'], true)) {
                $r = self::syncConnection($conn);
                return 'sync: ' . json_encode($r);
            }
            if ($code === 'TRANSACTIONS_REMOVED') {
                $r = self::syncConnection($conn);
                return 'sync (removed): ' . json_encode($r);
            }
            return 'transactions/' . $code . ' noted';
        }

        // --- item lifecycle ---
        if ($type === 'ITEM') {
            switch ($code) {
                /**
                 * Bank of America is migrating to a new API through October
                 * 2026. This webhook is the one-week warning: go through Link
                 * update mode before `disconnect_time` or the Item drops into
                 * ITEM_LOGIN_REQUIRED and the feed stops.
                 */
                case 'PENDING_DISCONNECT':
                    $when = $body['disconnect_time'] ?? null;
                    DB::update('acc_bank_connections', [
                        'status' => 'pending_disconnect',
                        'disconnect_at' => $when ? date('Y-m-d H:i:s', strtotime($when)) : null,
                        'error_code' => (string)($body['reason'] ?? 'PENDING_DISCONNECT'),
                        'error_message' => 'Reconnect this bank to keep the feed running.',
                    ], 'id = ?', [(int)$conn['id']]);
                    self::notifyReauth($conn, 'Bank connection needs reconnecting',
                        ($conn['institution_name'] ?: 'Your bank') . ' must be reconnected'
                        . ($when ? ' before ' . date('j M Y', strtotime($when)) : ' within a week')
                        . ' or the transaction feed will stop.');
                    return 'pending_disconnect recorded';

                /** Consent is running out — BofA applies a 12-month expiry. */
                case 'PENDING_EXPIRATION':
                    $when = $body['consent_expiration_time'] ?? null;
                    DB::update('acc_bank_connections', [
                        'status' => 'pending_expiration',
                        'consent_expires_at' => $when ? date('Y-m-d H:i:s', strtotime($when)) : null,
                        'error_message' => 'Bank consent is expiring — reconnect to continue.',
                    ], 'id = ?', [(int)$conn['id']]);
                    self::notifyReauth($conn, 'Bank consent expiring',
                        ($conn['institution_name'] ?: 'Your bank') . ' consent expires'
                        . ($when ? ' on ' . date('j M Y', strtotime($when)) : ' soon')
                        . '. Reconnect to keep transactions syncing.');
                    return 'pending_expiration recorded';

                case 'ERROR':
                    $err = $body['error'] ?? [];
                    $ec = (string)($err['error_code'] ?? '');
                    DB::update('acc_bank_connections', [
                        'status' => $ec === 'ITEM_LOGIN_REQUIRED' ? 'login_required' : $conn['status'],
                        'error_code' => $ec ?: null,
                        'error_message' => mb_substr((string)($err['error_message'] ?? ''), 0, 500),
                    ], 'id = ?', [(int)$conn['id']]);
                    if ($ec === 'ITEM_LOGIN_REQUIRED') {
                        self::notifyReauth($conn, 'Bank connection stopped',
                            ($conn['institution_name'] ?: 'Your bank') . ' needs signing in again before transactions can sync.');
                    }
                    return 'item error ' . $ec;

                case 'USER_PERMISSION_REVOKED':
                case 'USER_ACCOUNT_REVOKED':
                    DB::update('acc_bank_connections',
                        ['status' => 'revoked', 'error_code' => $code,
                         'error_message' => 'Access was revoked at the bank.'],
                        'id = ?', [(int)$conn['id']]);
                    self::notifyReauth($conn, 'Bank access revoked',
                        'Access to ' . ($conn['institution_name'] ?: 'your bank') . ' was revoked. Reconnect to resume syncing.');
                    return 'revoked';

                case 'NEW_ACCOUNTS_AVAILABLE':
                    $tok = Plaid::openToken((string)$conn['access_token']);
                    if ($tok !== '') self::refreshAccounts((int)$conn['id'], $tok);
                    return 'accounts refreshed';

                case 'LOGIN_REPAIRED':
                    DB::update('acc_bank_connections',
                        ['status' => 'active', 'error_code' => null, 'error_message' => null],
                        'id = ?', [(int)$conn['id']]);
                    return 'login repaired';
            }
            return 'item/' . $code . ' noted';
        }

        return $type . '/' . $code . ' ignored';
    }

    /**
     * Tell a human the feed needs attention.
     *
     * Re-auth is the one failure mode nobody discovers on their own — the app
     * simply stops receiving transactions and looks fine. Routed through the
     * normal notification system so it lands in the bell and deep-links to
     * Banking like anything else.
     */
    private static function notifyReauth(array $conn, string $title, string $msg): void
    {
        try {
            $userId = (int)($conn['created_by'] ?? 0);
            if (!$userId) {
                $owner = DB::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [Authz::ACC_OWNER_EMAIL]);
                $userId = (int)($owner['id'] ?? 0);
            }
            if ($userId && class_exists('NotificationController')) {
                NotificationController::create($userId, 'bank_reauth', $title, $msg, 'acc_banking', (int)$conn['id']);
            }
        } catch (\Throwable $e) { error_log('plaid reauth notify: ' . $e->getMessage()); }
    }
}
