// ============================================================================
// VGo — contractors invoice the company from inside the app.
//
// Two screens, both full pages:
//   my-invoices               what a contractor sees: submit, then track
//   acc-contractor-invoices   what accounting sees: a queue, then a decision
//
// The approval screen puts the original PDF beside the figures rather than
// above or behind them. That is the whole point of the screen: the numbers are
// only worth approving if someone has compared them to the document, and bank
// details — which VGo deliberately never stores — are read off it there.
// ============================================================================

Object.assign(API, {
  ciMine: () => API.req('/contractor/invoices'),
  ciExtract: (file) => {
    const fd = new FormData();
    fd.append('file', file);
    return API.uploadReq('/contractor/invoices/extract', fd);
  },
  ciSubmit: (d) => API.req('/contractor/invoices', { method: 'POST', body: JSON.stringify(d) }),
  ciWithdraw: (id) => API.req('/contractor/invoices/' + id + '/withdraw', { method: 'POST' }),
  ciFileUrl: (id, inline) => '/api/contractor/invoices/' + id + '/file' + (inline ? '?inline=1' : ''),

  ciQueue: (p = {}) => API.req('/acc/contractor-invoices?' + new URLSearchParams(p).toString()),
  ciDetail: (id) => API.req('/acc/contractor-invoices/' + id),
  ciApprove: (id, d) => API.req('/acc/contractor-invoices/' + id + '/approve', { method: 'POST', body: JSON.stringify(d) }),
  ciReject: (id, d) => API.req('/acc/contractor-invoices/' + id + '/reject', { method: 'POST', body: JSON.stringify(d) }),
  setContractor: (id, on) => API.req('/settings/users/' + id + '/contractor', {
    method: 'POST', body: JSON.stringify({ user_id: id, is_contractor: on }),
  }),
});

/* ===================== state ===================== */

const CInv = {
  // contractor side
  mine: null,
  step: 'list',        // list | upload | review
  file: null,
  busy: false,
  error: null,
  draft: null,
  confirmPeriod: false,
  // approver side
  queue: null,
  queueStatus: 'submitted',
  detail: null,
  decision: null,      // 'approve' | 'reject' | null
  decisionBusy: false,
};

const CI_STATUS = {
  submitted: ['Waiting for approval', 'warn'],
  approved:  ['Approved', 'ok'],
  paid:      ['Paid', 'ok'],
  rejected:  ['Sent back', 'bad'],
};

function ciMoney(amount, currency) {
  const n = Number(amount || 0);
  return (currency ? currency + ' ' : '') + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function ciDate(d) {
  if (!d) return '—';
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
  const t = m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(d);
  return isNaN(t.getTime()) ? '—' : t.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
}

function ciPill(status) {
  const [label, tone] = CI_STATUS[status] || [status, 'warn'];
  return `<span class="ci-pill ci-${tone}">${esc(label)}</span>`;
}

/* ============================================================
 * Contractor — My invoices
 * ============================================================ */

function goMyInvoices() {
  CInv.step = 'list'; CInv.draft = null; CInv.file = null; CInv.error = null; CInv.mine = null;
  nav('my-invoices');
}

async function renderMyInvoices() {
  if (!CInv.mine) {
    try {
      CInv.mine = await API.ciMine();
    } catch (e) {
      return `<div class="fade-in"><div class="page-head"><h1>My invoices</h1></div>
        <div class="card card-pad"><div class="empty-state">${esc(e.message)}</div></div></div>`;
    }
  }
  if (CInv.step === 'upload' || CInv.step === 'review') return ciSubmitPage();
  return ciListPage();
}

function ciListPage() {
  const d = CInv.mine;
  const rows = d.invoices || [];

  const body = rows.length ? `
    <div class="ci-rows">
      ${rows.map(r => `
        <div class="ci-row">
          <div class="ci-row-period">
            <div class="ci-strong">${esc(r.period_label || ciDate(r.issued_at))}</div>
            <div class="ci-muted">${r.invoice_number ? 'No. ' + esc(r.invoice_number) : 'Submitted ' + esc(ciDate(r.submitted_at))}</div>
          </div>
          <div class="ci-row-amount">${esc(ciMoney(r.total, r.currency_code))}</div>
          <div class="ci-row-status">
            ${ciPill(r.display_status)}
            ${r.display_status === 'approved' && r.bill_due_at ? `<div class="ci-muted">due ${esc(ciDate(r.bill_due_at))}</div>` : ''}
            ${r.display_status === 'paid' ? `<div class="ci-muted">paid ${esc(ciDate(r.paid_at))}</div>` : ''}
          </div>
          <div class="ci-row-actions">
            ${r.has_file ? `<a class="btn-secondary btn-sm" href="${esc(API.ciFileUrl(r.id, true))}" target="_blank" rel="noopener">View</a>` : ''}
            ${r.status === 'submitted' ? `<button class="btn-secondary btn-sm" onclick="ciWithdraw(${r.id})">Withdraw</button>` : ''}
          </div>
          ${r.status === 'rejected' && r.decision_note ? `
            <div class="ci-row-note ci-note-bad">
              <strong>Sent back:</strong> ${esc(r.decision_note)}
              <button class="btn-primary btn-sm" style="margin-left:auto" onclick="ciStartSubmit()">Submit a corrected invoice</button>
            </div>` : ''}
        </div>`).join('')}
    </div>`
    : `<div class="empty-state">You have not submitted any invoices yet.
        <div style="margin-top:14px"><button class="btn-primary" onclick="ciStartSubmit()">Submit your first invoice</button></div>
       </div>`;

  return `
    <div class="fade-in">
      <div class="page-head">
        <div class="page-head-main">
          <h1>My invoices</h1>
          <p class="page-desc">Send Victory Genomics your monthly invoice and follow what happens to it.</p>
        </div>
        <div class="page-head-actions">
          <button class="btn-primary" onclick="ciStartSubmit()">${I.plus || '+'} Submit an invoice</button>
        </div>
      </div>
      <div class="card card-flush">${body}</div>
      <p class="ci-muted" style="margin-top:14px">
        Approved invoices are paid by bank transfer to the details on your invoice. VGo does not store your bank details —
        they are read from the document you attach each time.
      </p>
    </div>`;
}

function ciStartSubmit() {
  CInv.step = 'upload';
  CInv.file = null; CInv.draft = null; CInv.error = null; CInv.busy = false; CInv.confirmPeriod = false;
  render();
}

function ciBackToList() {
  CInv.step = 'list'; CInv.draft = null; CInv.file = null; CInv.error = null;
  CInv.mine = null;
  render();
}

function ciSubmitPage() {
  return `
    <div class="fade-in">
      <div class="page-head">
        <div class="page-head-main">
          <h1>Submit an invoice</h1>
          <p class="page-desc">Attach your invoice as a PDF. The details are read off it and you check them before anything is sent.</p>
        </div>
        <div class="page-head-actions">
          <button class="btn-secondary" onclick="ciBackToList()">← My invoices</button>
        </div>
      </div>
      ${CInv.step === 'review' ? ciReviewCard() : ciUploadCard()}
    </div>`;
}

function ciUploadCard() {
  const f = CInv.file;
  const ai = CInv.mine && CInv.mine.ai_available !== false;
  return `
    <div class="card card-pad">
      <div class="billscan-drop"
           ondragover="event.preventDefault();this.classList.add('over')"
           ondragleave="this.classList.remove('over')"
           ondrop="ciDrop(event)"
           onclick="document.getElementById('ci-file').click()">
        <input type="file" id="ci-file" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/*" hidden
               onchange="ciPicked(this.files[0])">
        ${f ? `<div class="billscan-picked"><strong>${esc(f.name)}</strong>
                 <span class="ci-muted">${esc(ciFileSize(f.size))}</span></div>
               <p class="ci-muted">Click to choose a different file.</p>`
            : `<div class="billscan-drop-icon">${I.file || '📄'}</div>
               <strong>Drop your invoice here, or click to choose it</strong>
               <p class="ci-muted">PDF preferred · photos also work · up to 12MB</p>`}
      </div>
      ${CInv.error ? `<div class="ci-alert ci-alert-bad">${esc(CInv.error)}</div>` : ''}
      <div class="form-actions">
        <button class="btn-primary" ${!f || CInv.busy ? 'disabled' : ''} onclick="ciRead()">
          ${CInv.busy ? 'Reading your invoice…' : 'Continue'}
        </button>
        <button class="btn-secondary" onclick="ciBackToList()">Cancel</button>
      </div>
      <p class="ci-muted" style="margin-top:14px">
        ${ai ? 'The amount, period and invoice number are read from the document — you confirm them on the next screen.'
             : 'Automatic reading is not switched on, so you will fill the details in on the next screen. The document is still attached to your invoice.'}
      </p>
    </div>`;
}

function ciFileSize(bytes) {
  const b = Number(bytes) || 0;
  if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
  if (b >= 1024) return Math.round(b / 1024) + ' KB';
  return b + ' B';
}

function ciDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('over');
  const f = e.dataTransfer?.files?.[0];
  if (f) ciPicked(f);
}

function ciPicked(file) {
  if (!file) return;
  if (file.size > 12 * 1024 * 1024) {
    CInv.error = 'That file is ' + ciFileSize(file.size) + '. Invoices up to 12MB can be read.';
    CInv.file = null;
  } else {
    CInv.file = file; CInv.error = null;
  }
  render();
}

async function ciRead() {
  if (!CInv.file || CInv.busy) return;
  CInv.busy = true; CInv.error = null;
  render();
  try {
    const res = await API.ciExtract(CInv.file);
    CInv.draft = res.draft;
    CInv.step = 'review';
  } catch (e) {
    CInv.error = e.message;
  } finally {
    CInv.busy = false;
    render();
  }
}

function ciReviewCard() {
  const d = CInv.draft;
  const warnings = (d.warnings || []).length ? `
    <div class="ci-alert ci-alert-warn">
      <strong>Worth checking</strong>
      <ul style="margin:6px 0 0 18px">${d.warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul>
    </div>` : '';

  const lines = (d.line_items || []).map((li, i) => `
    <tr>
      <td><input class="form-input" value="${esc(li.name)}" oninput="ciSetLine(${i},'name',this.value)"></td>
      <td><input class="form-input" type="number" step="any" value="${esc(li.quantity)}" oninput="ciSetLine(${i},'quantity',this.value)"></td>
      <td><input class="form-input" type="number" step="any" value="${esc(li.unit_price)}" oninput="ciSetLine(${i},'unit_price',this.value)"></td>
      <td style="text-align:right" id="ci-line-total-${i}">${esc(ciMoney(li.quantity * li.unit_price, d.currency))}</td>
      <td><button class="acc-icon-btn" title="Remove" onclick="ciRemoveLine(${i})">×</button></td>
    </tr>`).join('');

  return `
    ${warnings}
    <div class="card card-pad">
      <div class="ci-file-strip">
        <span>From <strong>${esc(d.staged_name || 'your file')}</strong></span>
        <span class="ci-muted">The original is attached to this invoice and is what accounting pays against.</span>
      </div>

      <div class="form-row" style="gap:12px;flex-wrap:wrap;margin-top:16px">
        <div class="form-field" style="flex:1;min-width:180px">
          <label class="form-label">Period covered</label>
          <input class="form-input" id="ci-period" value="${esc(d.period_label || '')}" placeholder="e.g. March 2026">
          <div class="ci-muted" style="margin-top:4px">Which month's work this is for.</div>
        </div>
        <div class="form-field" style="flex:1;min-width:150px">
          <label class="form-label">Your invoice number</label>
          <input class="form-input" id="ci-number" value="${esc(d.invoice_number || '')}" placeholder="Optional">
        </div>
        <div class="form-field" style="flex:1;min-width:150px">
          <label class="form-label">Invoice date</label>
          <input class="form-input" type="date" id="ci-issued" value="${esc(d.issued_at || '')}">
        </div>
      </div>

      <div class="form-row" style="gap:12px;flex-wrap:wrap;margin-top:12px">
        <div class="form-field" style="flex:1;min-width:130px">
          <label class="form-label">Currency</label>
          <input class="form-input" id="ci-currency" maxlength="3" value="${esc(d.currency || (CInv.mine && CInv.mine.currency) || 'USD')}">
        </div>
        <div class="form-field" style="flex:1;min-width:150px">
          <label class="form-label">Total amount</label>
          <input class="form-input" type="number" step="0.01" id="ci-total" style="text-align:right"
                 value="${d.total !== null && d.total !== undefined ? Number(d.total) : ''}" placeholder="0.00">
        </div>
      </div>

      <h3 class="acc-subhead" style="margin-top:22px">What you are billing for</h3>
      <div class="acc-table-wrap">
        <table class="acc-table billscan-lines">
          <thead><tr><th>Description</th><th style="width:90px">Qty / hours</th><th style="width:120px">Rate</th><th style="width:120px;text-align:right">Total</th><th style="width:40px"></th></tr></thead>
          <tbody id="ci-lines">${lines}</tbody>
        </table>
      </div>
      <button class="btn-secondary btn-sm" style="margin-top:10px" onclick="ciAddLine()">+ Add line</button>

      <div class="form-field" style="margin-top:18px">
        <label class="form-label">Anything accounting should know</label>
        <textarea class="form-input" id="ci-notes" rows="2" placeholder="Optional">${esc(d.notes || '')}</textarea>
      </div>

      ${CInv.confirmPeriod ? `
        <div class="ci-alert ci-alert-warn">
          <strong>You already have an invoice for this period.</strong>
          <div>${esc(CInv.error || '')}</div>
          <label style="display:flex;gap:8px;align-items:center;margin-top:8px;cursor:pointer">
            <input type="checkbox" id="ci-confirm-period"> This is genuinely for different work — submit it as well.
          </label>
        </div>`
        : (CInv.error ? `<div class="ci-alert ci-alert-bad">${esc(CInv.error)}</div>` : '')}

      <div class="form-actions">
        <button class="btn-primary" ${CInv.busy ? 'disabled' : ''} onclick="ciSubmit()">${CInv.busy ? 'Submitting…' : 'Submit to accounting'}</button>
        <button class="btn-secondary" onclick="ciStartSubmit()">Use a different file</button>
        <button class="btn-secondary" onclick="ciBackToList()">Cancel</button>
      </div>
    </div>`;
}

function ciSetLine(i, field, value) {
  const li = CInv.draft.line_items[i];
  if (!li) return;
  li[field] = (field === 'name') ? value : (parseFloat(value) || 0);
  const cell = document.getElementById('ci-line-total-' + i);
  if (cell) cell.textContent = ciMoney(li.quantity * li.unit_price, ciVal('ci-currency'));
}

function ciAddLine() {
  CInv.draft.line_items.push({ name: '', quantity: 1, unit_price: 0, total: null });
  render();
}

function ciRemoveLine(i) {
  CInv.draft.line_items.splice(i, 1);
  render();
}

function ciVal(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

async function ciSubmit() {
  if (CInv.busy) return;
  const d = CInv.draft;
  const total = Number(ciVal('ci-total') || 0);
  if (!total || total <= 0) { CInv.error = 'Enter the amount you are invoicing for.'; render(); return; }
  if (!ciVal('ci-period')) { CInv.error = 'Say which period this invoice covers, e.g. “March 2026”.'; render(); return; }

  const confirmBox = document.getElementById('ci-confirm-period');
  if (CInv.confirmPeriod && !(confirmBox && confirmBox.checked)) {
    CInv.error = 'Tick the box to confirm this is a second invoice for that period.';
    render();
    return;
  }

  CInv.busy = true;
  const payload = {
    invoice_number: ciVal('ci-number') || null,
    issued_at: ciVal('ci-issued') || null,
    period_label: ciVal('ci-period'),
    period_start: d.period_start || null,
    period_end: d.period_end || null,
    currency: ciVal('ci-currency') || 'USD',
    total,
    subtotal: d.subtotal,
    tax_total: d.tax_total,
    notes: ciVal('ci-notes') || null,
    line_items: (d.line_items || []).filter(li => (li.name || '').trim() !== ''),
    contractor_name: d.contractor_name || null,
    confidence: d.confidence || null,
    warnings: d.warnings || [],
    staged_path: d.staged_path,
    staged_name: d.staged_name,
    staged_mime: d.staged_mime,
    staged_size: d.staged_size,
    confirm_duplicate_period: CInv.confirmPeriod && confirmBox && confirmBox.checked,
  };

  try {
    await API.ciSubmit(payload);
    CInv.busy = false;
    toast('Invoice submitted — accounting has been notified', 'success');
    ciBackToList();
  } catch (e) {
    CInv.busy = false;
    // A period clash is a question, not a failure: keep everything typed in and
    // ask for a deliberate confirmation.
    if (/already have an invoice covering/i.test(e.message)) {
      CInv.confirmPeriod = true;
      CInv.error = e.message;
    } else {
      CInv.error = e.message;
    }
    render();
  }
}

async function ciWithdraw(id) {
  const ok = await Modal.confirm({
    title: 'Withdraw this invoice',
    message: 'It will be removed from accounting\'s queue. You can submit a corrected one afterwards.',
    confirmText: 'Withdraw', danger: true,
  });
  if (!ok) return;
  try {
    await API.ciWithdraw(id);
    CInv.mine = null;
    toast('Invoice withdrawn', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ============================================================
 * Accounting — the approval queue
 * ============================================================ */

function accGoContractorInvoices(id) {
  CInv.queue = null; CInv.detail = null; CInv.decision = null;
  if (id) {
    State.ciDetailId = id;
    // Opening the invoice IS dealing with the notification about it, so the
    // module count comes down by one rather than all at once on arrival.
    if (typeof clearRecordBadge === 'function') clearRecordBadge('contractor_invoice', id);
  } else {
    State.ciDetailId = null;
  }
  accNav('acc-contractor-invoices');
}

async function renderAccContractorInvoices() {
  await accBoot();
  if (!accHas('acc.bills')) return accDenied('Bills');

  if (State.ciDetailId) return ciDetailPage();

  if (!CInv.queue) CInv.queue = await API.ciQueue({ status: CInv.queueStatus });
  const d = CInv.queue;
  const c = d.counts;

  const tabs = [['submitted', 'Waiting', c.submitted], ['approved', 'Approved', c.approved],
                ['rejected', 'Sent back', c.rejected], ['all', 'All', null]];

  const rows = (d.invoices || []).map(r => [
    `<div style="min-width:0"><div class="acc-strong acc-truncate">${esc(r.contractor || '—')}</div>
      <div class="acc-sub acc-truncate">${esc(r.contractor_email || '')}</div></div>`,
    `<div><div class="acc-strong">${esc(r.period_label || '—')}</div>
      ${r.invoice_number ? `<div class="acc-sub">No. ${esc(r.invoice_number)}</div>` : ''}</div>`,
    esc(ciDate(r.submitted_at)),
    `<span class="acc-strong">${esc(ciMoney(r.total, r.currency_code))}</span>`,
    ciPill(r.display_status) + (r.bill_number ? `<div class="acc-sub">${esc(r.bill_number)}</div>` : ''),
    `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accGoContractorInvoices(${r.id})">
       ${r.status === 'submitted' ? 'Review' : 'Open'}</button>`,
  ]);

  return `
    <div class="fade-in acc-page">
      ${accHeader('Contractor invoices', 'Invoices submitted by people engaged on contract, waiting to become bills.',
        `<button class="btn-secondary" onclick="accNav('acc-bills')">← Bills</button>`)}
      <div class="acc-tabs">
        ${tabs.map(([k, label, n]) => `<button class="acc-tab ${CInv.queueStatus === k ? 'active' : ''}" onclick="ciQueueTab('${k}')">
          ${esc(label)}${Number(n) ? `<span class="acc-tab-count">${Number(n)}</span>` : ''}</button>`).join('')}
      </div>
      <div class="acc-card acc-card-flush">
        ${accTable(
          [{ label: 'Contractor', width: 'minmax(0,1.4fr)' }, { label: 'Period', width: 'minmax(0,1fr)' },
           { label: 'Submitted', width: '130px' }, { label: 'Amount', width: '150px', align: 'right' },
           { label: 'Status', width: '160px' }, { label: '', width: '90px', align: 'right' }],
          rows, CInv.queueStatus === 'submitted' ? 'Nothing is waiting for approval.' : 'Nothing here.')}
      </div>
    </div>`;
}

function ciQueueTab(status) {
  CInv.queueStatus = status; CInv.queue = null;
  render();
}

async function ciDetailPage() {
  if (!CInv.detail || Number(CInv.detail.invoice.id) !== Number(State.ciDetailId)) {
    CInv.detail = await API.ciDetail(State.ciDetailId);
    CInv.decision = null;
  }
  const inv = CInv.detail.invoice;
  const cats = CInv.detail.options.categories || [];
  const pending = inv.status === 'submitted';
  const ex = inv.extraction || {};

  const warnings = (ex.warnings || []).length ? `
    <div class="acc-alert acc-alert-warn">
      <strong>Flagged when it was read</strong>
      <ul style="margin:6px 0 0 18px">${ex.warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul>
    </div>` : '';

  const history = (inv.history || []).length ? `
    <div class="acc-card">
      <div class="acc-card-head"><span class="acc-card-title">Also from ${esc(inv.contractor || 'this contractor')}</span></div>
      <div class="ci-history">
        ${inv.history.map(h => `
          <div class="ci-history-row ${h.period_label && h.period_label === inv.period_label ? 'ci-history-clash' : ''}">
            <span>${esc(h.period_label || ciDate(h.submitted_at))}</span>
            <span class="acc-muted">${esc(h.invoice_number ? 'No. ' + h.invoice_number : '')}</span>
            <span>${esc(ciMoney(h.total, h.currency_code))}</span>
            ${ciPill(h.status)}
          </div>`).join('')}
      </div>
      ${inv.history.some(h => h.period_label && h.period_label === inv.period_label)
        ? `<div class="acc-alert acc-alert-warn" style="margin:12px 20px 16px">
             Another invoice from this contractor covers <strong>${esc(inv.period_label)}</strong>. Check it is not the same work twice.</div>`
        : ''}
    </div>` : '';

  return `
    <div class="fade-in acc-page">
      <div style="margin-bottom:12px">${accBackLink('Contractor invoices', "accGoContractorInvoices()")}</div>
      ${accHeader(inv.contractor || 'Contractor invoice',
        (inv.period_label ? inv.period_label + ' · ' : '') + ciMoney(inv.total, inv.currency_code),
        inv.document_id ? `<button class="btn-secondary" onclick="accGoDoc(${inv.document_id})">Open bill ${esc(inv.bill_number || '')}</button>` : '')}

      ${inv.status === 'approved' ? `<div class="acc-alert acc-alert-ok">
          <strong>Approved${inv.decided_by_name ? ' by ' + esc(inv.decided_by_name) : ''}.</strong>
          Bill ${esc(inv.bill_number || '')} is in payables${inv.bill_due_at ? ', due ' + esc(ciDate(inv.bill_due_at)) : ''}.
          ${inv.paid_at ? ' Paid ' + esc(ciDate(inv.paid_at)) + '.' : ''}
        </div>` : ''}
      ${inv.status === 'rejected' ? `<div class="acc-alert acc-alert-bad">
          <strong>Sent back${inv.decided_by_name ? ' by ' + esc(inv.decided_by_name) : ''}:</strong> ${esc(inv.decision_note || '')}
        </div>` : ''}
      ${warnings}

      <div class="ci-review">
        <div class="ci-review-doc">
          <div class="acc-card acc-card-flush">
            <div class="acc-card-head">
              <span class="acc-card-title">The invoice as submitted</span>
              ${inv.has_file ? `<a class="btn-secondary btn-sm" href="${esc(API.ciFileUrl(inv.id, false))}">Download</a>` : ''}
            </div>
            ${inv.has_file
              ? `<iframe class="ci-doc-frame" src="${esc(API.ciFileUrl(inv.id, true))}" title="Submitted invoice"></iframe>`
              : `<div class="acc-empty">No document was attached.</div>`}
          </div>
          <p class="acc-muted" style="margin-top:10px">
            Payment details are on this document. VGo does not store bank, routing or SWIFT numbers —
            take them from here when you make the transfer.
          </p>
        </div>

        <div class="ci-review-form">
          <div class="acc-card">
            <div class="acc-card-title" style="margin-bottom:12px">What will become a bill</div>
            <div class="form-row" style="gap:12px;flex-wrap:wrap">
              ${accField('Amount', `<input class="form-input" type="number" step="0.01" id="ci-a-total" style="text-align:right"
                        value="${Number(inv.total)}" ${pending ? '' : 'disabled'}>`)}
              ${accField('Currency', `<input class="form-input" id="ci-a-currency" maxlength="3" value="${esc(inv.currency_code)}" ${pending ? '' : 'disabled'}>`)}
            </div>
            <div class="form-row" style="gap:12px;flex-wrap:wrap;margin-top:10px">
              ${accField('Bill date', `<input class="form-input" type="date" id="ci-a-issued" value="${esc(inv.issued_at || '')}" ${pending ? '' : 'disabled'}>`)}
              ${accField('Due date', `<input class="form-input" type="date" id="ci-a-due" value="${esc(ciDefaultDue(inv))}" ${pending ? '' : 'disabled'}>`)}
            </div>
            <div class="form-row" style="gap:12px;flex-wrap:wrap;margin-top:10px">
              ${accField('Expense category', accSelect('ci-a-category', cats.map(c => ({ value: c.id, label: c.name })), '', 'Uncategorised', pending ? '' : 'disabled'))}
            </div>

            <div class="ci-lines-preview">
              ${(inv.line_items || []).map(li => `
                <div class="ci-line-row">
                  <span class="acc-truncate">${esc(li.name)}</span>
                  <span class="acc-muted">${esc(String(li.quantity))} × ${esc(ciMoney(li.price ?? li.unit_price, inv.currency_code))}</span>
                  <span>${esc(ciMoney((li.quantity || 1) * (li.price ?? li.unit_price ?? 0), inv.currency_code))}</span>
                </div>`).join('')}
            </div>
            ${inv.notes ? `<p class="acc-muted" style="margin-top:12px"><strong>From the contractor:</strong> ${esc(inv.notes)}</p>` : ''}
            ${ex.read_name && inv.contractor && ex.read_name !== inv.contractor ? `
              <div class="acc-alert acc-alert-warn" style="margin-top:12px">
                The document is issued by <strong>${esc(ex.read_name)}</strong> but was submitted by
                <strong>${esc(inv.contractor)}</strong>, who is who will be paid.
              </div>` : ''}

            ${pending ? `
              <div id="ci-decision-error" class="acc-alert acc-alert-bad" style="display:none"></div>
              ${CInv.decision === 'reject' ? `
                <div class="form-field" style="margin-top:14px">
                  <label class="form-label">Why is it going back?</label>
                  <textarea class="form-input" id="ci-a-reason" rows="3" placeholder="e.g. The period says March but the dates are April."></textarea>
                  <div class="acc-muted" style="margin-top:4px">The contractor sees this, so it needs to be enough to act on.</div>
                </div>
                <div class="acc-form-actions">
                  <button class="btn-primary" ${CInv.decisionBusy ? 'disabled' : ''} onclick="ciDoReject(${inv.id})">Send it back</button>
                  <button class="btn-secondary" onclick="ciSetDecision(null)">Cancel</button>
                </div>`
              : `
                <div class="acc-form-actions">
                  <button class="btn-primary" ${CInv.decisionBusy ? 'disabled' : ''} onclick="ciDoApprove(${inv.id})">
                    ${CInv.decisionBusy ? 'Approving…' : 'Approve — create the bill'}</button>
                  <button class="btn-secondary" onclick="ciSetDecision('reject')">Send back…</button>
                </div>
                <p class="acc-muted" style="margin-top:10px">Approving creates a bill marked received, so it appears in payables straight away.</p>`}
            ` : ''}
          </div>
        </div>
      </div>

      ${history}
    </div>`;
}

/** 15 days from the bill date, unless the contractor's own invoice said otherwise. */
function ciDefaultDue(inv) {
  if (inv.bill_due_at) return inv.bill_due_at;
  const base = inv.issued_at ? new Date(inv.issued_at + 'T00:00:00') : new Date();
  base.setDate(base.getDate() + 15);
  return base.toISOString().slice(0, 10);
}

function ciSetDecision(mode) { CInv.decision = mode; render(); }

function ciDecisionError(msg) {
  const el = document.getElementById('ci-decision-error');
  if (el) { el.textContent = msg; el.style.display = 'block'; } else toast(msg, 'error');
}

async function ciDoApprove(id) {
  if (CInv.decisionBusy) return;
  const total = Number(ciVal('ci-a-total') || 0);
  if (!total || total <= 0) return ciDecisionError('The amount must be more than zero.');

  CInv.decisionBusy = true; render();
  try {
    const res = await API.ciApprove(id, {
      total,
      currency: ciVal('ci-a-currency') || null,
      issued_at: ciVal('ci-a-issued') || null,
      due_at: ciVal('ci-a-due') || null,
      category_id: ciVal('ci-a-category') || null,
    });
    CInv.decisionBusy = false;
    CInv.detail = null; CInv.queue = null;
    AccState.docs.bill = null; AccState.dashboard = null; AccState.contacts = null;
    toast('Approved — bill ' + (res.number || '') + ' is in payables', 'success');
    accGoDoc(res.document_id);
  } catch (e) {
    CInv.decisionBusy = false;
    render();
    ciDecisionError(e.message);
  }
}

async function ciDoReject(id) {
  if (CInv.decisionBusy) return;
  const reason = ciVal('ci-a-reason');
  if (!reason || reason.length < 3) return ciDecisionError('Say why it is going back, so it can be corrected.');

  CInv.decisionBusy = true; render();
  try {
    await API.ciReject(id, { note: reason });
    CInv.decisionBusy = false;
    CInv.detail = null; CInv.queue = null;
    toast('Sent back to the contractor', 'success');
    accGoContractorInvoices();
  } catch (e) {
    CInv.decisionBusy = false;
    render();
    ciDecisionError(e.message);
  }
}
