// ============================================================================
// VGold — bank statement import and the review queue that follows it.
//
// Three pages, no popups anywhere:
//   acc-bank-import  upload a statement, confirm how it should be read
//   acc-bank-review  decide each line: match, add, or exclude
//   acc-reconciliation (accounting2.js) tick the cleared items to zero
//
// The mapping step exists because a misread date column is invisible after the
// fact: the numbers all look plausible, they are simply in the wrong month. So
// the file is re-parsed live as the mapping changes and you see the actual
// dates and amounts your choices produce before anything is written.
// ============================================================================

Object.assign(API, {
  accStatementPreview: (file) => {
    const fd = new FormData();
    fd.append('file', file);
    return API.uploadReq('/acc/bank-imports/preview', fd);
  },
  accStatementReparse: (d) => API.req('/acc/bank-imports/reparse', { method: 'POST', body: JSON.stringify(d) }),
  accStatementCommit: (d) => API.req('/acc/bank-imports', { method: 'POST', body: JSON.stringify(d) }),
  accBankImports: () => API.req('/acc/bank-imports'),
  accDeleteBankImport: (id) => API.req('/acc/bank-imports/' + id, { method: 'DELETE' }),
  accBankReview: (p = {}) => API.req('/acc/bank-review?' + new URLSearchParams(p).toString()),
  accBankAcceptMatches: (accountId) => API.req('/acc/bank-review/accept-matches', { method: 'POST', body: JSON.stringify({ account_id: accountId }) }),
  accBankLineDocuments: (id) => API.req('/acc/bank-lines/' + id + '/documents'),
  accBankLineMatch: (id, d) => API.req('/acc/bank-lines/' + id + '/match', { method: 'POST', body: JSON.stringify(d) }),
  accBankLineAdd: (id, d) => API.req('/acc/bank-lines/' + id + '/add', { method: 'POST', body: JSON.stringify(d) }),
  accBankLineExclude: (id) => API.req('/acc/bank-lines/' + id + '/exclude', { method: 'POST' }),
  accBankLineUndo: (id) => API.req('/acc/bank-lines/' + id + '/undo', { method: 'POST' }),
  accReconciliationReopen: (id) => API.req('/acc/reconciliations/' + id + '/reopen', { method: 'POST' }),
});

/* ===================== state ===================== */

const BankFeed = {
  step: 'upload',        // upload | mapping | done
  file: null,
  busy: false,
  error: null,
  preview: null,         // what the server made of the file
  mapping: null,         // the mapping currently being edited
  accountId: '',
  closingBalance: '',
  check: null,           // live re-parse under the current mapping
  result: null,          // import outcome
};

/** Which row of the review queue is open, and what it is showing. */
const BankRow = { id: null, mode: null, docs: null, busy: false };

const BF_ROLES = [
  ['date', 'Date', 'Required — when the money moved.'],
  ['description', 'Description', 'What the bank called it.'],
  ['amount', 'Amount (signed)', 'One column, negative for money out.'],
  ['debit', 'Money out', 'Use with “Money in” when the bank splits them.'],
  ['credit', 'Money in', ''],
  ['type', 'Debit/credit marker', 'A column saying DR/CR when amounts are all positive.'],
  ['reference', 'Reference / cheque no.', ''],
  ['payee', 'Payee', ''],
  ['balance', 'Running balance', 'Used to read the closing balance off the file.'],
];

const BF_DATE_FORMATS = [
  ['d/m/Y', '31/12/2026 — day first'],
  ['m/d/Y', '12/31/2026 — month first'],
  ['Y-m-d', '2026-12-31'],
  ['d-m-Y', '31-12-2026'],
  ['m-d-Y', '12-31-2026'],
  ['d.m.Y', '31.12.2026'],
  ['d/m/y', '31/12/26 — day first'],
  ['m/d/y', '12/31/26 — month first'],
  ['Y/m/d', '2026/12/31'],
  ['Ymd', '20261231'],
  ['textual', '31 Dec 2026'],
];

/* ===================== import ===================== */

function accGoBankImport(accountId) {
  BankFeed.step = 'upload';
  BankFeed.file = null; BankFeed.preview = null; BankFeed.mapping = null;
  BankFeed.error = null; BankFeed.busy = false; BankFeed.check = null; BankFeed.result = null;
  BankFeed.accountId = accountId || '';
  BankFeed.closingBalance = '';
  accNav('acc-bank-import');
}

async function renderAccBankImport() {
  await accBoot();
  if (!accHas('acc.banking')) return accDenied('banking');

  let body;
  if (BankFeed.step === 'done') body = bfImportDone();
  else if (BankFeed.step === 'mapping') body = bfMappingStep();
  else body = bfUploadStep();

  return `<div class="fade-in acc-page">
    ${accHeader('Import a bank statement',
      'Download the statement from your bank as CSV, OFX or QFX, then drop it here. Nothing is saved until you have seen how it reads.',
      `<button class="btn-secondary" onclick="accNav('acc-banking')">← Back to banking</button>`)}
    ${body}
  </div>`;
}

function bfUploadStep() {
  const f = BankFeed.file;
  const accounts = accOpts().accounts || [];
  return `
    <div class="acc-card">
      <div class="form-row" style="gap:12px;flex-wrap:wrap;margin-bottom:16px">
        ${accField('Which account is this statement for?',
          accSelect('bf-account', accounts.map(a => ({ value: a.id, label: a.name + ' — ' + accMoney(a.balance) })),
            BankFeed.accountId, 'Select account…', 'onchange="bfSetAccount()"'))}
      </div>

      <div class="billscan-drop" id="bf-drop"
           ondragover="event.preventDefault();this.classList.add('over')"
           ondragleave="this.classList.remove('over')"
           ondrop="bfDrop(event)"
           onclick="document.getElementById('bf-file').click()">
        <input type="file" id="bf-file" accept=".csv,.txt,.tsv,.ofx,.qfx,text/csv" hidden onchange="bfPicked(this.files[0])">
        ${f ? `<div class="billscan-picked"><strong>${esc(f.name)}</strong>
                 <span class="acc-muted">${accFileSize(f.size)}</span></div>
               <p class="acc-muted">Click to choose a different file.</p>`
            : `<div class="billscan-drop-icon">${I.file || '📄'}</div>
               <strong>Drop a statement here, or click to choose one</strong>
               <p class="acc-muted">CSV, OFX or QFX · up to 8MB</p>`}
      </div>

      ${BankFeed.error ? `<div class="acc-alert acc-alert-bad">${esc(BankFeed.error)}</div>` : ''}

      <div class="acc-form-actions">
        <button class="btn-primary" ${!f || BankFeed.busy ? 'disabled' : ''} onclick="bfRead()">
          ${BankFeed.busy ? 'Reading the file…' : 'Read the statement'}
        </button>
        <button class="btn-secondary" onclick="accNav('acc-banking')">Cancel</button>
      </div>
      <p class="acc-muted" style="margin-top:14px">
        OFX and QFX carry the bank's own transaction IDs, so re-importing an overlapping period never duplicates anything.
        CSV is matched on date, amount and description instead — also safe to re-import, just less certain.
      </p>
    </div>`;
}

function bfSetAccount() { BankFeed.accountId = accVal('bf-account'); }

function bfDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('over');
  const f = e.dataTransfer?.files?.[0];
  if (f) bfPicked(f);
}

function bfPicked(file) {
  if (!file) return;
  if (file.size > 8 * 1024 * 1024) {
    BankFeed.error = 'That file is ' + accFileSize(file.size) + '. Statements up to 8MB can be read — export a shorter date range.';
    BankFeed.file = null;
  } else {
    BankFeed.file = file;
    BankFeed.error = null;
  }
  render();
}

async function bfRead() {
  if (!BankFeed.file || BankFeed.busy) return;
  BankFeed.accountId = accVal('bf-account') || BankFeed.accountId;
  if (!BankFeed.accountId) { BankFeed.error = 'Choose which account this statement belongs to.'; render(); return; }

  BankFeed.busy = true; BankFeed.error = null;
  render();
  try {
    const res = await API.accStatementPreview(BankFeed.file);
    BankFeed.preview = res.preview;
    BankFeed.mapping = Object.assign({}, res.preview.mapping);
    BankFeed.closingBalance = res.preview.closing_balance != null ? String(res.preview.closing_balance) : '';
    BankFeed.step = 'mapping';
    BankFeed.check = null;
    render();
    if (BankFeed.mapping.format !== 'ofx') bfRecheck();
  } catch (e) {
    BankFeed.error = e.message;
  } finally {
    BankFeed.busy = false;
    render();
  }
}

/* ---------------- step 2: confirm the reading ---------------- */

function bfMappingStep() {
  const p = BankFeed.preview;
  const m = BankFeed.mapping || {};
  const isOfx = m.format === 'ofx';

  const warnings = (p.warnings || []).length
    ? `<div class="acc-alert acc-alert-warn"><strong>Before importing</strong>
        <ul style="margin:6px 0 0 18px">${p.warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul></div>`
    : '';

  const columnOptions = (p.columns || []).map(c => ({
    value: c.index,
    label: 'Column ' + (c.index + 1) + (c.header ? ' — ' + c.header : '') +
           (c.sample.length ? '  (' + c.sample.slice(0, 2).join(', ').slice(0, 40) + ')' : ''),
  }));

  const roleFields = isOfx ? '' : `
    <div class="bf-map-grid">
      ${BF_ROLES.map(([key, label, hint]) => `
        <div class="bf-map-field">
          <label class="form-label">${esc(label)}</label>
          ${accSelect('bf-map-' + key, columnOptions, m[key] === null || m[key] === undefined ? '' : m[key],
            key === 'date' ? 'Choose a column…' : 'Not used', 'onchange="bfMapChanged()"')}
          ${hint ? `<div class="acc-muted" style="margin-top:3px">${esc(hint)}</div>` : ''}
        </div>`).join('')}
      <div class="bf-map-field">
        <label class="form-label">How the dates are written</label>
        ${accSelect('bf-map-date_format', BF_DATE_FORMATS.map(([v, l]) => ({ value: v, label: l })),
          m.date_format || '', 'Choose…', 'onchange="bfMapChanged()"')}
        ${m.date_ambiguous ? `<div class="acc-alert acc-alert-warn" style="margin-top:6px;padding:8px 10px">
            Every date in this file fits both readings. Pick the one your bank uses.</div>` : ''}
      </div>
    </div>`;

  const check = BankFeed.check;
  const preview = check ? bfPreviewTable(check) : (isOfx ? bfPreviewTable({ sample: p.sample, rows_total: p.rows_total, skipped: [], skipped_total: 0, summary: null }) : '<div class="acc-muted">Reading…</div>');

  const accounts = accOpts().accounts || [];
  const account = accounts.find(a => String(a.id) === String(BankFeed.accountId));

  return `
    ${warnings}
    <div class="acc-card">
      <div class="bf-file-line">
        <div><strong>${esc(BankFeed.file ? BankFeed.file.name : p.staged_name)}</strong>
          <span class="acc-muted"> · ${esc(String(p.format).toUpperCase())} · ${esc(String(p.rows_total))} rows</span></div>
        <div class="acc-muted">into <strong>${esc(account ? account.name : '—')}</strong></div>
      </div>
      ${isOfx ? `<p class="acc-muted">OFX files describe themselves — there is nothing to map. Check the rows below and import.</p>` : roleFields}
    </div>

    <div class="acc-card">
      <div class="acc-card-head"><span class="acc-card-title">What will be imported</span>
        ${check && check.rows_total ? `<span class="acc-muted">${esc(String(check.rows_total))} rows</span>` : ''}</div>
      ${preview}
    </div>

    <div class="acc-card">
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Closing balance on the statement (optional)',
          `<input class="form-input" type="number" step="0.01" id="bf-closing" value="${esc(BankFeed.closingBalance)}"
                  style="text-align:right" placeholder="0.00" oninput="BankFeed.closingBalance=this.value">`)}
      </div>
      <p class="acc-muted" style="margin-top:6px">Used later as the target for reconciling. You can also enter it when you reconcile.</p>
      ${BankFeed.error ? `<div class="acc-alert acc-alert-bad">${esc(BankFeed.error)}</div>` : ''}
      <div class="acc-form-actions">
        <button class="btn-primary" id="bf-import" ${BankFeed.busy ? 'disabled' : ''} onclick="bfImport()">
          ${BankFeed.busy ? 'Importing…' : 'Import these rows'}</button>
        <button class="btn-secondary" onclick="bfStartOver()">Use a different file</button>
        <button class="btn-secondary" onclick="accNav('acc-banking')">Cancel</button>
      </div>
    </div>`;
}

function bfPreviewTable(check) {
  const rows = (check.sample || []).map(r => [
    esc(r.posted_at || '—'),
    `<span class="acc-truncate">${esc(r.description || '—')}</span>`,
    esc(r.reference || '—'),
    `<span class="${Number(r.amount) < 0 ? 'acc-neg' : 'acc-pos'}">${accMoney(r.amount)}</span>`,
    r.balance == null ? '<span class="acc-dim">—</span>' : accMoney(r.balance),
  ]);

  const s = check.summary;
  const summary = s ? `
    <div class="bf-summary">
      <div><span>Rows</span><strong>${esc(String(s.count))}</strong></div>
      <div><span>Money in</span><strong class="acc-pos">${accMoney(s.money_in)}</strong></div>
      <div><span>Money out</span><strong class="acc-neg">${accMoney(s.money_out)}</strong></div>
      <div><span>Period</span><strong>${esc(s.first_date || '—')} → ${esc(s.last_date || '—')}</strong></div>
    </div>` : '';

  const skipped = (check.skipped_total || 0) > 0 ? `
    <div class="acc-alert acc-alert-warn" style="margin:12px 20px">
      <strong>${esc(String(check.skipped_total))} row(s) will be left out</strong>
      <ul style="margin:6px 0 0 18px">
        ${(check.skipped || []).slice(0, 5).map(k => `<li>Line ${esc(String(k.line))}: ${esc(k.reason)}${k.raw ? ' — ' + esc(String(k.raw).slice(0, 60)) : ''}</li>`).join('')}
      </ul>
    </div>` : '';

  return summary + accTable(
    [{ label: 'Date', width: '110px' }, { label: 'Description', width: 'minmax(0,2fr)' },
     { label: 'Reference', width: '120px' }, { label: 'Amount', width: '130px', align: 'right' },
     { label: 'Balance', width: '130px', align: 'right' }],
    rows, 'Nothing reads as a transaction with these settings.') + skipped;
}

function bfMapChanged() {
  const m = BankFeed.mapping;
  BF_ROLES.forEach(([key]) => {
    const v = accVal('bf-map-' + key);
    m[key] = v === '' ? null : Number(v);
  });
  m.date_format = accVal('bf-map-date_format') || null;
  bfRecheck();
}

let bfRecheckTimer = null;
function bfRecheck() {
  clearTimeout(bfRecheckTimer);
  bfRecheckTimer = setTimeout(async () => {
    if (!BankFeed.preview) return;
    try {
      BankFeed.check = await API.accStatementReparse({
        staged_path: BankFeed.preview.staged_path,
        mapping: BankFeed.mapping,
      });
      BankFeed.error = null;
    } catch (e) {
      BankFeed.check = { sample: [], rows_total: 0, skipped: [], skipped_total: 0, summary: null };
      BankFeed.error = e.message;
    }
    render();
  }, 180);
}

function bfStartOver() {
  BankFeed.step = 'upload'; BankFeed.file = null; BankFeed.preview = null;
  BankFeed.mapping = null; BankFeed.check = null; BankFeed.error = null;
  render();
}

async function bfImport() {
  if (BankFeed.busy) return;
  BankFeed.busy = true; BankFeed.error = null;
  render();
  try {
    const res = await API.accStatementCommit({
      account_id: Number(BankFeed.accountId),
      staged_path: BankFeed.preview.staged_path,
      staged_name: BankFeed.preview.staged_name,
      staged_size: BankFeed.preview.staged_size,
      mapping: BankFeed.mapping,
      closing_balance: BankFeed.closingBalance === '' ? null : Number(BankFeed.closingBalance),
    });
    BankFeed.result = res;
    BankFeed.step = 'done';
    AccState.banking = null;
    AccState.bankReview = null;
  } catch (e) {
    BankFeed.error = e.message;
  } finally {
    BankFeed.busy = false;
    render();
  }
}

function bfImportDone() {
  const r = BankFeed.result || {};
  return `
    <div class="acc-card">
      <div class="acc-alert acc-alert-ok">
        <strong>${esc(String(r.imported || 0))} statement line(s) imported.</strong>
        ${r.duplicates ? ' ' + esc(String(r.duplicates)) + ' were already here and were left alone.' : ''}
        ${r.skipped ? ' ' + esc(String(r.skipped)) + ' could not be read and were skipped.' : ''}
      </div>
      <p class="acc-muted">The original file is attached to the import, so the figures can always be checked against what the bank sent.</p>
      <div class="acc-form-actions">
        <button class="btn-primary" onclick="accGoBankReview(${Number(BankFeed.accountId) || 'null'})">Review these lines</button>
        <button class="btn-secondary" onclick="accGoBankImport(${Number(BankFeed.accountId) || 'null'})">Import another statement</button>
        <button class="btn-secondary" onclick="accNav('acc-banking')">Back to banking</button>
      </div>
    </div>`;
}

/* ===================== the review queue ===================== */

function accGoBankReview(accountId) {
  AccState.bankReview = null;
  BankRow.id = null; BankRow.mode = null; BankRow.docs = null;
  if (accountId) AccState.bankReviewAccountId = accountId;
  accNav('acc-bank-review');
}

async function renderAccBankReview() {
  await accBoot();
  if (!accHas('acc.banking')) return accDenied('banking');

  if (!AccState.bankReview) {
    AccState.bankReview = await API.accBankReview({
      account_id: AccState.bankReviewAccountId || '',
      status: AccState.bankReviewStatus || 'pending',
    });
    if (AccState.bankReview.account) AccState.bankReviewAccountId = AccState.bankReview.account.id;
  }
  const d = AccState.bankReview;

  if (!d.account) {
    return `<div class="fade-in acc-page">
      ${accHeader('Review bank transactions', 'Statement lines waiting to be matched, added or excluded.',
        `<button class="btn-primary" onclick="accGoBankImport()">${I.plus} Import a statement</button>`)}
      <div class="acc-card"><div class="acc-empty">No statements have been imported yet.
        <div style="margin-top:14px"><button class="btn-primary" onclick="accGoBankImport()">Import a statement</button></div>
      </div></div>
    </div>`;
  }

  const c = d.counts;
  const status = d.status;
  const highCount = (d.lines || []).filter(l => l.match && l.match.confidence === 'high').length;

  const tabs = [['pending', 'For review', c.pending], ['accepted', 'In VGold', c.accepted], ['excluded', 'Excluded', c.excluded]];

  return `<div class="fade-in acc-page">
    ${accHeader('Review bank transactions', 'Each line is either already in VGold, or needs adding — or is none of our business.',
      `<button class="btn-secondary" onclick="accGoBankImport(${d.account.id})">${I.plus} Import a statement</button>
       <button class="btn-secondary" onclick="accNav('acc-banking')">← Banking</button>`)}

    <div class="acc-toolbar">
      <div style="min-width:200px">${accSelect('bf-rev-account',
        (d.accounts || []).map(a => ({ value: a.id, label: a.name + (Number(a.pending) ? '  (' + a.pending + ')' : '') })),
        d.account.id, null, 'onchange="bfReviewAccount()"')}</div>
      <div class="acc-tabs" style="margin:0">
        ${tabs.map(([k, label, n]) => `<button class="acc-tab ${status === k ? 'active' : ''}" onclick="bfReviewTab('${k}')">
          ${esc(label)}${Number(n) ? `<span class="acc-tab-count">${Number(n)}</span>` : ''}</button>`).join('')}
      </div>
      ${status === 'pending' && highCount ? `<button class="btn-primary" onclick="bfAcceptAll()">Accept ${highCount} certain match${highCount === 1 ? '' : 'es'}</button>` : ''}
    </div>

    ${status === 'pending' && d.counts.pending === 0
      ? `<div class="acc-card"><div class="acc-empty">Nothing left to review on this account.
          <div style="margin-top:14px"><button class="btn-secondary" onclick="accNav('acc-banking')">Reconcile it</button></div></div></div>`
      : `<div class="acc-card acc-card-flush">${bfReviewRows(d)}</div>`}
  </div>`;
}

function bfReviewRows(d) {
  const status = d.status;
  const lines = d.lines || [];
  if (!lines.length) return accEmpty(status === 'excluded' ? 'Nothing has been excluded.' : 'Nothing here yet.');

  return `<div class="bf-rows">${lines.map(l => bfReviewRow(l, status)).join('')}</div>`;
}

function bfReviewRow(l, status) {
  const amount = Number(l.amount);
  const open = Number(BankRow.id) === Number(l.id);

  let right;
  if (status === 'pending') {
    const m = l.match;
    if (m && m.confidence === 'high' && m.best) {
      const t = m.candidates[0];
      right = `<div class="bf-suggest bf-suggest-high">
          <div class="bf-suggest-text">Matches <strong>${esc(t.transaction.description || 'a transaction')}</strong>
            <span class="acc-muted">${esc(accDateShort(t.transaction.paid_at))}${t.transaction.contact_name ? ' · ' + esc(t.transaction.contact_name) : ''}${t.transaction.document_number ? ' · ' + esc(t.transaction.document_number) : ''}</span>
            <div class="acc-muted">${esc(t.reasons.join(' · '))}</div></div>
          <button class="btn-primary btn-sm" onclick="bfMatch(${l.id}, ${t.transaction.id})">Match</button>
        </div>`;
    } else if (m && (m.confidence === 'medium' || m.confidence === 'ambiguous' || m.confidence === 'low') && m.candidates.length) {
      const word = m.confidence === 'ambiguous'
        ? `${m.candidates.length} transactions fit this line equally well — pick one`
        : 'A possible match — check it';
      right = `<div class="bf-suggest bf-suggest-warn">
          <div class="bf-suggest-text">${esc(word)}</div>
          <button class="btn-secondary btn-sm" onclick="bfOpenRow(${l.id}, 'match')">Choose…</button>
        </div>`;
    } else {
      const r = l.recall;
      right = `<div class="bf-suggest">
          <div class="bf-suggest-text acc-muted">${r ? 'Last time this was ' + esc(r.category_name || r.contact_name || 'recorded') : 'Nothing in VGold matches this'}</div>
          <button class="btn-primary btn-sm" onclick="bfOpenRow(${l.id}, 'add')">Add</button>
        </div>`;
    }
  } else if (status === 'excluded') {
    right = `<div class="bf-suggest"><div class="bf-suggest-text acc-muted">Excluded</div>
      <button class="btn-secondary btn-sm" onclick="bfUndo(${l.id})">Undo</button></div>`;
  } else {
    right = `<div class="bf-suggest">
      <div class="bf-suggest-text">${l.status === 'added' ? 'Added' : 'Matched'} to
        <strong>${esc(l.transaction_description || 'a transaction')}</strong>
        ${l.transaction_document ? '<span class="acc-muted"> · ' + esc(l.transaction_document) + '</span>' : ''}</div>
      <button class="btn-secondary btn-sm" onclick="bfUndo(${l.id})">Undo</button></div>`;
  }

  return `
    <div class="bf-row ${open ? 'bf-row-open' : ''}">
      <div class="bf-row-main">
        <div class="bf-row-date">${esc(accDateShort(l.posted_at))}<div class="acc-muted">${esc(String(l.posted_at || '').slice(0, 4))}</div></div>
        <div class="bf-row-desc">
          <div class="acc-strong acc-truncate">${esc(l.description || '—')}</div>
          <div class="acc-muted acc-truncate">${esc(l.reference ? 'Ref ' + l.reference : '')}${l.payee && l.payee !== l.description ? (l.reference ? ' · ' : '') + esc(l.payee) : ''}</div>
        </div>
        <div class="bf-row-amount ${amount < 0 ? 'acc-neg' : 'acc-pos'}">${amount < 0 ? '−' : '+'}${accMoney(Math.abs(amount))}</div>
        <div class="bf-row-action">${right}</div>
      </div>
      ${status === 'pending' ? `
        <div class="bf-row-links">
          <button class="acc-link-btn" onclick="bfOpenRow(${l.id}, 'match')">Find a match</button>
          <button class="acc-link-btn" onclick="bfOpenRow(${l.id}, 'add')">Add as new</button>
          <button class="acc-link-btn" onclick="bfExclude(${l.id})">Exclude</button>
        </div>` : ''}
      ${open ? `<div class="bf-row-panel" id="bf-panel-${l.id}">${bfRowPanel(l)}</div>` : ''}
    </div>`;
}

function bfRowPanel(l) {
  if (BankRow.mode === 'match') return bfMatchPanel(l);
  return bfAddPanel(l);
}

function bfMatchPanel(l) {
  const cands = (l.match && l.match.candidates) || [];
  if (!cands.length) {
    return `<div class="acc-muted">Nothing on this account has the same amount within five days of ${esc(accDate(l.posted_at))}.
      Record it with <strong>Add as new</strong> instead.</div>`;
  }
  return `
    <div class="bf-cands">
      ${cands.map(c => `
        <div class="bf-cand">
          <div>
            <div class="acc-strong">${esc(c.transaction.description || '—')}</div>
            <div class="acc-muted">${esc(accDate(c.transaction.paid_at))}${c.transaction.contact_name ? ' · ' + esc(c.transaction.contact_name) : ''}${c.transaction.category_name ? ' · ' + esc(c.transaction.category_name) : ''}${c.transaction.document_number ? ' · ' + esc(c.transaction.document_number) : ''}</div>
            <div class="acc-muted">${esc(c.reasons.join(' · '))}</div>
          </div>
          <div class="bf-cand-amt ${c.transaction.type === 'income' ? 'acc-pos' : 'acc-neg'}">${c.transaction.type === 'income' ? '+' : '−'}${accMoney(c.transaction.amount)}</div>
          <button class="btn-primary btn-sm" onclick="bfMatch(${l.id}, ${c.transaction.id})">Match this</button>
        </div>`).join('')}
    </div>
    <div class="acc-form-actions" style="padding-top:10px">
      <button class="btn-secondary btn-sm" onclick="bfOpenRow(${l.id}, 'add')">None of these — add as new</button>
      <button class="btn-secondary btn-sm" onclick="bfCloseRow()">Cancel</button>
    </div>`;
}

function bfAddPanel(l) {
  const d = AccState.bankReview || {};
  const o = d.options || {};
  const isIncome = Number(l.amount) > 0;
  const r = l.recall || {};
  const categories = (o.categories || []).filter(c => !c.type || c.type === (isIncome ? 'income' : 'expense'));

  return `
    <div class="form-row" style="gap:12px;flex-wrap:wrap">
      ${accField('Category', accSelect('bf-add-cat-' + l.id,
        categories.map(c => ({ value: c.id, label: c.name })), r.category_id || '', 'Uncategorised'))}
      ${accField('Contact', accSelect('bf-add-contact-' + l.id,
        (o.contacts || []).map(c => ({ value: c.id, label: c.name })), r.contact_id || '', 'No contact'))}
      ${accField(isIncome ? 'Apply to an invoice' : 'Apply to a bill',
        `<span id="bf-add-doc-wrap-${l.id}">${
          BankRow.docs === null
            ? `<button class="btn-secondary" style="width:100%" onclick="bfLoadDocs(${l.id})">Look for one…</button>`
            : accSelect('bf-add-doc-' + l.id, BankRow.docs.map(x => ({
                value: x.id, label: x.number + ' · ' + (x.contact_name || '—') + ' · ' + accMoney(x.balance) + ' outstanding',
              })), '', 'Not applied to a document')
        }</span>`)}
    </div>
    <div class="form-row" style="gap:12px;margin-top:10px">
      ${accField('Description', `<input class="form-input" id="bf-add-desc-${l.id}" value="${esc(l.description || '')}">`)}
    </div>
    ${r.from ? `<p class="acc-muted" style="margin-top:8px">Suggested from “${esc(r.from)}”, recorded earlier.</p>` : ''}
    <p class="acc-muted" style="margin-top:8px">The date (${esc(accDate(l.posted_at))}) and amount (${accMoney(Math.abs(Number(l.amount)))}) come from the statement and cannot be changed here — a transaction that disagreed with its own statement line could never reconcile.</p>
    <div class="acc-form-actions" style="padding-top:10px">
      <button class="btn-primary btn-sm" ${BankRow.busy ? 'disabled' : ''} onclick="bfAdd(${l.id})">${BankRow.busy ? 'Adding…' : 'Add to VGold'}</button>
      <button class="btn-secondary btn-sm" onclick="bfOpenRow(${l.id}, 'match')">Look for an existing one</button>
      <button class="btn-secondary btn-sm" onclick="bfCloseRow()">Cancel</button>
    </div>`;
}

function bfReviewAccount() {
  AccState.bankReviewAccountId = accVal('bf-rev-account');
  AccState.bankReview = null;
  BankRow.id = null; BankRow.docs = null;
  render();
}

function bfReviewTab(status) {
  AccState.bankReviewStatus = status;
  AccState.bankReview = null;
  BankRow.id = null; BankRow.docs = null;
  render();
}

function bfOpenRow(id, mode) {
  if (Number(BankRow.id) !== Number(id)) BankRow.docs = null;
  BankRow.id = id; BankRow.mode = mode; BankRow.busy = false;
  render();
}

function bfCloseRow() { BankRow.id = null; BankRow.mode = null; BankRow.docs = null; render(); }

async function bfLoadDocs(lineId) {
  try {
    const res = await API.accBankLineDocuments(lineId);
    BankRow.docs = res.documents || [];
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function bfReviewReload() {
  AccState.bankReview = null;
  AccState.banking = null;
  AccState.transactions = null;
  AccState.dashboard = null;
  AccState.account = null;
  BankRow.id = null; BankRow.docs = null; BankRow.busy = false;
  render();
}

async function bfMatch(lineId, txId) {
  try {
    await API.accBankLineMatch(lineId, { transaction_id: txId });
    toast('Matched', 'success');
    await bfReviewReload();
  } catch (e) { toast(e.message, 'error'); }
}

async function bfAdd(lineId) {
  if (BankRow.busy) return;
  BankRow.busy = true;
  const el = (id) => document.getElementById(id);
  const payload = {
    category_id: el('bf-add-cat-' + lineId)?.value || null,
    contact_id: el('bf-add-contact-' + lineId)?.value || null,
    document_id: el('bf-add-doc-' + lineId)?.value || null,
    description: el('bf-add-desc-' + lineId)?.value || null,
  };
  try {
    await API.accBankLineAdd(lineId, payload);
    toast('Added to VGold', 'success');
    await bfReviewReload();
  } catch (e) {
    BankRow.busy = false;
    toast(e.message, 'error');
    render();
  }
}

async function bfExclude(lineId) {
  try {
    await API.accBankLineExclude(lineId);
    toast('Excluded', 'success');
    await bfReviewReload();
  } catch (e) { toast(e.message, 'error'); }
}

async function bfUndo(lineId) {
  try {
    await API.accBankLineUndo(lineId);
    toast('Back in the review queue', 'success');
    await bfReviewReload();
  } catch (e) { toast(e.message, 'error'); }
}

async function bfAcceptAll() {
  const d = AccState.bankReview;
  if (!d || !d.account) return;
  try {
    const res = await API.accBankAcceptMatches(d.account.id);
    toast(res.accepted + ' line(s) matched', 'success');
    await bfReviewReload();
  } catch (e) { toast(e.message, 'error'); }
}
