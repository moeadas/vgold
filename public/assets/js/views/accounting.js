// ============================================================================
// VGo — Accounting & Finance (native)
// Part 1: shared helpers, API surface, dashboard, invoices, bills, contacts.
// Part 2 (accounting2.js): banking, ledger, catalog, recurring, reports,
// accounting settings, and the hooks the main Settings screen calls into.
//
// Everything here renders with the VGO design tokens — no iframe, no legacy
// bridge. Views are async functions returning HTML strings, matching the
// convention used by the Workflow and CRM views.
// ============================================================================

/* ===================== Sidebar icons ===================== */
// Defined here (not in icons.js) so the accounting module stays self-contained.
const ACC_NAV_ICONS = {
  overview: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-5 4 3 5-7"/></svg>',
  invoice: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h6M8 17h4"/></svg>',
  bill: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3v18l2-1.5L8 21l2-1.5L12 21l2-1.5L16 21l2-1.5L20 21V3l-2 1.5L16 3l-2 1.5L12 3l-2 1.5L8 3 6 4.5Z"/><path d="M8 9h8M8 13h5"/></svg>',
  contacts: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/></svg>',
  bank: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M5 10v8M10 10v8M14 10v8M19 10v8M3 20h18M12 3 3 8h18Z"/></svg>',
  ledger: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M6 7h12M4 21h16"/><path d="M6 7 3 14h6ZM18 7l-3 7h6Z"/></svg>',
  catalog: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>',
  recurring: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>',
  reports: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
  settings: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12.2 2h-.4a2 2 0 0 0-2 2 1.7 1.7 0 0 1-2.6 1.5 2 2 0 0 0-2.7.7l-.2.4a2 2 0 0 0 .7 2.7 1.7 1.7 0 0 1 0 3 2 2 0 0 0-.7 2.7l.2.4a2 2 0 0 0 2.7.7A1.7 1.7 0 0 1 9.8 20a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2 1.7 1.7 0 0 1 2.6-1.5 2 2 0 0 0 2.7-.7l.2-.4a2 2 0 0 0-.7-2.7 1.7 1.7 0 0 1 0-3 2 2 0 0 0 .7-2.7l-.2-.4a2 2 0 0 0-2.7-.7A1.7 1.7 0 0 1 14.2 4a2 2 0 0 0-2-2Z"/></svg>',
};

/* ===================== State ===================== */

const AccState = {
  form: null,            // the form currently rendered as a page (see accOpenForm)
  billScan: null,        // draft read off an uploaded bill, awaiting review
  boot: null,            // /acc/bootstrap payload (modules, options, settings)
  dashboard: null,
  docs: { invoice: null, bill: null },
  docFilter: { invoice: { status: 'all', search: '', page: 1 }, bill: { status: 'all', search: '', page: 1 } },
  doc: null,             // active document detail
  contacts: null,
  contactTab: 'customer',
  contactSearch: '',
  contact: null,
  banking: null,
  bankingTab: 'accounts',
  transactions: null,
  txFilter: { tx_type: 'all', account_id: '', search: '', match: 'all', page: 1 },
  account: null,
  reconciliation: null,
  bankReview: null,          // the statement-line review queue for one account
  bankReviewAccountId: '',
  bankReviewStatus: 'pending',
  journal: null,
  coa: null,
  ledgerTab: 'journal',
  catalog: null,
  catalogTab: 'items',
  recurring: null,
  reports: null,
  reportsYear: null,
  reportPeriod: 'year',  // year | q1..q4 — filing period for reports
  reportBasis: 'accrual',// accrual | cash — tax recognition basis
  reportTab: 'pnl',
  settings: null,
  editor: null,          // in-flight document editor state
  matchable: [],         // open documents offered in the transaction matcher
};

/* ===================== API ===================== */

Object.assign(API, {
  accBootstrap: () => API.req('/acc/bootstrap'),
  accDashboard: () => API.req('/acc/dashboard'),
  accDocuments: (p = {}) => API.req('/acc/documents?' + new URLSearchParams(p).toString()),
  accDocument: (id) => API.req('/acc/documents/' + id),
  accCreateDocument: (d) => API.req('/acc/documents', { method: 'POST', body: JSON.stringify(d) }),
  accCreateVendorFromDraft: (d) => API.req('/acc/vendors/from-draft', { method: 'POST', body: JSON.stringify(d) }),
  accUpdateDocument: (id, d) => API.req('/acc/documents/' + id, { method: 'PUT', body: JSON.stringify(d) }),
  accDeleteDocument: (id) => API.req('/acc/documents/' + id, { method: 'DELETE' }),
  accDocumentStatus: (id, action) => API.req('/acc/documents/' + id + '/status', { method: 'POST', body: JSON.stringify({ action }) }),
  accDocumentPayment: (id, d) => API.req('/acc/documents/' + id + '/payment', { method: 'POST', body: JSON.stringify(d) }),

  accContacts: (p = {}) => API.req('/acc/contacts?' + new URLSearchParams(p).toString()),
  accContact: (id) => API.req('/acc/contacts/' + id),
  accCreateContact: (d) => API.req('/acc/contacts', { method: 'POST', body: JSON.stringify(d) }),
  accUpdateContact: (id, d) => API.req('/acc/contacts/' + id, { method: 'PUT', body: JSON.stringify(d) }),
  accDeleteContact: (id) => API.req('/acc/contacts/' + id, { method: 'DELETE' }),
  accCrmLeadSearch: (q) => API.req('/acc/contacts/crm-search?search=' + encodeURIComponent(q)),
  accImportCrmLead: (leadId) => API.req('/acc/contacts/import-crm', { method: 'POST', body: JSON.stringify({ lead_id: leadId }) }),

  accBanking: () => API.req('/acc/banking'),
  accTransactions: (p = {}) => API.req('/acc/transactions?' + new URLSearchParams(p).toString()),
  accCreateTransaction: (d) => API.req('/acc/transactions', { method: 'POST', body: JSON.stringify(d) }),
  accUpdateTransaction: (id, d) => API.req('/acc/transactions/' + id, { method: 'PUT', body: JSON.stringify(d) }),
  accDeleteTransaction: (id) => API.req('/acc/transactions/' + id, { method: 'DELETE' }),
  accAccount: (id) => API.req('/acc/accounts/' + id),
  accCreateAccount: (d) => API.req('/acc/accounts', { method: 'POST', body: JSON.stringify(d) }),
  accUpdateAccount: (id, d) => API.req('/acc/accounts/' + id, { method: 'PUT', body: JSON.stringify(d) }),
  accDeleteAccount: (id) => API.req('/acc/accounts/' + id, { method: 'DELETE' }),
  accCreateTransfer: (d) => API.req('/acc/transfers', { method: 'POST', body: JSON.stringify(d) }),
  accDeleteTransfer: (id) => API.req('/acc/transfers/' + id, { method: 'DELETE' }),
  accCreateReconciliation: (d) => API.req('/acc/reconciliations', { method: 'POST', body: JSON.stringify(d) }),
  accReconciliation: (id) => API.req('/acc/reconciliations/' + id),
  accReconciliationMark: (id, ids, cleared) => API.req('/acc/reconciliations/' + id + '/mark', {
    method: 'POST', body: JSON.stringify({ transaction_ids: ids, cleared: cleared !== false }),
  }),
  accReconciliationClose: (id, d) => API.req('/acc/reconciliations/' + id + '/close', { method: 'POST', body: JSON.stringify(d) }),

  accJournal: (p = {}) => API.req('/acc/journal?' + new URLSearchParams(p).toString()),
  accCreateJournal: (d) => API.req('/acc/journal', { method: 'POST', body: JSON.stringify(d) }),
  accReverseJournal: (id) => API.req('/acc/journal/' + id + '/reverse', { method: 'POST' }),
  accDeleteJournal: (id) => API.req('/acc/journal/' + id, { method: 'DELETE' }),
  accCoa: () => API.req('/acc/coa'),
  accCreateCoa: (d) => API.req('/acc/coa', { method: 'POST', body: JSON.stringify(d) }),
  accUpdateCoa: (id, d) => API.req('/acc/coa/' + id, { method: 'PUT', body: JSON.stringify(d) }),
  accDeleteCoa: (id) => API.req('/acc/coa/' + id, { method: 'DELETE' }),

  accCatalog: () => API.req('/acc/catalog'),
  accSaveItem: (id, d) => id ? API.req('/acc/items/' + id, { method: 'PUT', body: JSON.stringify(d) }) : API.req('/acc/items', { method: 'POST', body: JSON.stringify(d) }),
  accDeleteItem: (id) => API.req('/acc/items/' + id, { method: 'DELETE' }),
  accSaveCategory: (id, d) => id ? API.req('/acc/categories/' + id, { method: 'PUT', body: JSON.stringify(d) }) : API.req('/acc/categories', { method: 'POST', body: JSON.stringify(d) }),
  accDeleteCategory: (id) => API.req('/acc/categories/' + id, { method: 'DELETE' }),
  accSaveTax: (id, d) => id ? API.req('/acc/taxes/' + id, { method: 'PUT', body: JSON.stringify(d) }) : API.req('/acc/taxes', { method: 'POST', body: JSON.stringify(d) }),
  accDeleteTax: (id) => API.req('/acc/taxes/' + id, { method: 'DELETE' }),

  accRecurring: () => API.req('/acc/recurring'),
  accSaveRecurring: (id, d) => id ? API.req('/acc/recurring/' + id, { method: 'PUT', body: JSON.stringify(d) }) : API.req('/acc/recurring', { method: 'POST', body: JSON.stringify(d) }),
  accDeleteRecurring: (id) => API.req('/acc/recurring/' + id, { method: 'DELETE' }),
  accRunRecurring: () => API.req('/acc/recurring/run', { method: 'POST' }),

  accMatchable: (p = {}) => API.req('/acc/transactions/matchable?' + new URLSearchParams(p).toString()),

  // Attachments. Upload reuses the shared multipart helper so CSRF is handled
  // exactly the same way it is for task and message uploads.
  accUploadAttachment: (type, id, file) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('attachable_type', type);
    fd.append('attachable_id', id);
    return API.uploadReq('/acc/attachments', fd);
  },
  accDeleteAttachment: (id) => API.req('/acc/attachments/' + id, { method: 'DELETE' }),
  accAttachmentUrl: (id, inline) => '/api/acc/attachments/' + id + '/download' + (inline ? '?inline=1' : ''),

  accReports: (p = {}) => API.req('/acc/reports?' + new URLSearchParams(
    typeof p === 'object' && p !== null ? p : { year: p }
  ).toString()),
  accSettings: () => API.req('/acc/settings'),
  accUpdateSettings: (d) => API.req('/acc/settings', { method: 'PUT', body: JSON.stringify(d) }),
  accRecalcBalances: () => API.req('/acc/settings/recalc', { method: 'POST' }),
  accSeed: () => API.req('/acc/settings/seed', { method: 'POST' }),
  accDataSummary: () => API.req('/acc/data-summary'),
  accReset: (d) => API.req('/acc/reset', { method: 'POST', body: JSON.stringify(d) }),
});

/* ===================== Formatting helpers ===================== */

const ACC_CURRENCY_SYMBOLS = { USD: '$', EUR: '€', GBP: '£', CAD: 'CA$', AED: 'AED ', SAR: 'SAR ' };

function accSymbol() {
  const code = (AccState.boot && AccState.boot.settings && AccState.boot.settings.default_currency) || 'USD';
  return ACC_CURRENCY_SYMBOLS[code] || (code + ' ');
}

function accMoney(v) {
  const n = Number(v || 0);
  const sign = n < 0 ? '-' : '';
  return sign + accSymbol() + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function accMoneyCompact(v) {
  const n = Number(v || 0);
  const a = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  if (a >= 1000000) return sign + accSymbol() + (a / 1000000).toFixed(1) + 'M';
  if (a >= 1000) return sign + accSymbol() + (a / 1000).toFixed(1) + 'k';
  return sign + accSymbol() + a.toFixed(0);
}

// Parse a Y-m-d string without letting the browser shift it by timezone.
function accParseDate(d) {
  if (!d) return null;
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  const t = new Date(d);
  return isNaN(t.getTime()) ? null : t;
}

function accDate(d, opts) {
  const t = accParseDate(d);
  if (!t) return '—';
  return t.toLocaleDateString('en-US', opts || { month: 'short', day: 'numeric', year: 'numeric' });
}

function accDateShort(d) { return accDate(d, { month: 'short', day: 'numeric' }); }

function accDateTime(d) {
  const t = d ? new Date(String(d).replace(' ', 'T')) : null;
  if (!t || isNaN(t.getTime())) return '—';
  return t.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
    ' · ' + t.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function accToday() { return new Date().toISOString().slice(0, 10); }

function accAddDays(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function accPill(status) {
  const s = String(status || 'draft').toLowerCase();
  const label = s.charAt(0).toUpperCase() + s.slice(1);
  return `<span class="acc-pill ${esc(s)}">${esc(label)}</span>`;
}

function accEmpty(text) { return `<div class="acc-empty">${esc(text)}</div>`; }

function accHas(module) {
  return !!(AccState.boot && (AccState.boot.modules || []).includes(module));
}

function accCanAdmin() { return !!(AccState.boot && AccState.boot.can_admin); }

/** Page header with optional action buttons. */
function accHeader(title, desc, actions) {
  return `
    <div class="acc-head">
      <div class="acc-head-main">
        <div class="section-label">Accounting &amp; Finance</div>
        <h1>${esc(title)}</h1>
        ${desc ? `<p class="page-desc">${esc(desc)}</p>` : ''}
      </div>
      ${actions ? `<div class="acc-head-actions">${actions}</div>` : ''}
    </div>`;
}

/* ===================== Forms as pages, not popups =====================
 *
 * Every accounting form used to be a Modal. They are now pages, but rather than
 * fifteen bespoke screens each form keeps its existing body/footer markup and
 * renders into this one host — same fields, same handlers, no overlay.
 *
 * accOpenForm() takes exactly the shape Modal.open() did, so a call site only
 * changes its function name.
 */
function accOpenForm(spec) {
  AccState.form = {
    key: spec.key || 'form',
    title: spec.title || '',
    desc: spec.desc || '',
    body: spec.body || '',
    footer: spec.footer || '',
    onMount: spec.onMount || null,
    // Where Cancel goes back to — wherever the form was opened from.
    back: { screen: State.screen, docId: State.accDocId, contactId: State.accContactId,
            accountId: State.accAccountId, reconciliationId: State.accReconciliationId },
  };
  State.screen = 'acc-form';
  updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
  closeMobileSidebar();
}

/** Leave a form page and go back where it was opened from. */
function accCloseForm() {
  const back = AccState.form?.back;
  AccState.form = null;
  // Some delete handlers call this from a list row, where no form was ever
  // opened. Closing nothing must not navigate anywhere.
  if (State.screen !== 'acc-form') return;
  if (!back || !back.screen || back.screen === 'acc-form') { accNav('acc-dashboard'); return; }
  accNav(back.screen, {
    accDocId: back.docId, accContactId: back.contactId,
    accAccountId: back.accountId, accReconciliationId: back.reconciliationId,
  });
}

function renderAccForm() {
  const f = AccState.form;
  // A refresh or a shared link has no form in memory — nothing to restore.
  if (!f) {
    return `<div class="fade-in acc-page">${accHeader('Accounting & Finance', '')}
      <div class="acc-card"><div class="acc-empty">That form is no longer open.
      <div style="margin-top:14px"><button class="btn-primary" onclick="accNav('acc-dashboard')">Go to Accounting</button></div></div></div></div>`;
  }
  if (f.onMount) setTimeout(f.onMount, 0);
  return `<div class="fade-in acc-page acc-form-page">
    ${accHeader(f.title, f.desc, `<button class="btn-secondary" onclick="accCloseForm()">← Back</button>`)}
    <div class="acc-card acc-form-card">
      <div class="acc-form-body">${f.body}</div>
      ${f.footer ? `<div class="acc-form-actions">${f.footer}</div>` : ''}
    </div>
  </div>`;
}

function accBackLink(label, onclick) {
  return `<button class="btn-secondary" style="padding:6px 12px;font-size:13px" onclick="${onclick}">← ${esc(label)}</button>`;
}

function accStat(label, value, sub, cls) {
  return `<div class="acc-stat">
    <div class="acc-stat-label">${esc(label)}</div>
    <div class="acc-stat-value ${cls || ''}">${value}</div>
    ${sub ? `<div class="acc-stat-sub">${esc(sub)}</div>` : ''}
  </div>`;
}

/** Grid table. cols: [{label, width, align}] ; rows: array of arrays of HTML. */
function accTable(cols, rows, emptyText, rowAttrs) {
  const template = cols.map(c => c.width || '1fr').join(' ');
  const head = `<div class="acc-row acc-row-head" style="grid-template-columns:${template}">` +
    cols.map(c => `<div${c.align === 'right' ? ' class="acc-num"' : ''}>${esc(c.label)}</div>`).join('') + '</div>';
  if (!rows.length) return `<div class="acc-table-wrap"><div class="acc-table">${head}${accEmpty(emptyText || 'Nothing here yet.')}</div></div>`;
  const body = rows.map((cells, i) => {
    const attrs = rowAttrs ? rowAttrs(i) : '';
    return `<div class="acc-row ${attrs ? 'acc-row-link' : ''}" style="grid-template-columns:${template}" ${attrs}>` +
      cells.map((cell, j) => `<div${cols[j] && cols[j].align === 'right' ? ' class="acc-num"' : ''}>${cell}</div>`).join('') + '</div>';
  }).join('');
  return `<div class="acc-table-wrap"><div class="acc-table">${head}${body}</div></div>`;
}

function accPager(meta, onPage) {
  if (!meta || meta.pages <= 1) return '';
  const p = meta.page;
  return `<div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;padding:12px 20px;font-size:13px">
    <button class="btn-secondary" style="padding:5px 11px" ${p <= 1 ? 'disabled' : ''} onclick="${onPage}(${p - 1})">Prev</button>
    <span class="acc-dim">Page ${p} of ${meta.pages} · ${meta.total} total</span>
    <button class="btn-secondary" style="padding:5px 11px" ${p >= meta.pages ? 'disabled' : ''} onclick="${onPage}(${p + 1})">Next</button>
  </div>`;
}

function accSelect(id, options, selected, placeholder, extra) {
  const opts = (placeholder ? `<option value="">${esc(placeholder)}</option>` : '') +
    options.map(o => `<option value="${esc(o.value)}" ${String(o.value) === String(selected) ? 'selected' : ''}>${esc(o.label)}</option>`).join('');
  return `<select class="form-input" id="${id}" ${extra || ''}>${opts}</select>`;
}

function accField(label, inner) {
  return `<div class="form-field" style="flex:1;min-width:0"><label class="form-label">${esc(label)}</label>${inner}</div>`;
}

function accVal(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

function accNumVal(id) {
  const el = document.getElementById(id);
  return el ? Number(el.value || 0) : 0;
}

/* ===================== Boot & navigation ===================== */

async function accBoot(force) {
  if (AccState.boot && !force) return AccState.boot;
  const res = await API.accBootstrap();
  AccState.boot = res;
  return res;
}

/** Options loaded with the bootstrap payload (accounts, contacts, taxes…). */
function accOpts() { return (AccState.boot && AccState.boot.options) || {}; }

async function accRefreshOptions() {
  try { await accBoot(true); } catch (e) {}
}

function accNav(screen, extra) {
  State.screen = screen;
  Object.assign(State, extra || {});
  State.activeProjectId = null;
  State.activeProject = null;
  State.activeCategoryId = null;
  updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
}

function accGoDoc(id) { AccState.doc = null; accNav('acc-doc', { accDocId: id }); }
function accGoContact(id) { AccState.contact = null; accNav('acc-contact', { accContactId: id }); }
function accGoAccount(id) { AccState.account = null; accNav('acc-account', { accAccountId: id }); }
function accGoReconciliation(id) { AccState.reconciliation = null; accNav('acc-reconciliation', { accReconciliationId: id }); }

/** Wrap a view in a friendly message when the module is not granted. */
function accDenied(what) {
  return `<div class="fade-in acc-page">${accHeader('Accounting & Finance', '')}
    <div class="acc-card"><div class="acc-empty">You do not have access to ${esc(what)}. Ask an administrator to enable it in Settings → Team module access.</div></div></div>`;
}

/* ===================== Dashboard ===================== */

async function renderAccDashboard() {
  await accBoot();
  if (!accHas('acc.dashboard')) return accDenied('the finance overview');
  if (!AccState.dashboard) AccState.dashboard = await API.accDashboard();
  const d = AccState.dashboard;
  const s = d.stats;

  // Empty-state nudge: offer to load the bundled demo dataset.
  const emptyNotice = (AccState.boot.empty && accCanAdmin()) ? `
    <div class="acc-card" style="border-color:var(--ochre);background:var(--ochre-bg)">
      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:220px">
          <div style="font-weight:700;margin-bottom:3px">No accounting data yet</div>
          <div style="font-size:13px">Load the bundled sample dataset to explore every screen with realistic numbers.</div>
        </div>
        <button class="btn-primary" onclick="accLoadSeed()">Load sample data</button>
      </div>
    </div>` : '';

  const maxFlow = Math.max(1, ...d.cash_flow.map(c => Math.max(Number(c.income), Number(c.expense))));
  const chart = `
    <div class="acc-card acc-card-flush">
      <div class="acc-card-head">
        <span class="acc-card-title">Cash flow</span>
        <span class="acc-legend"><span><i style="background:var(--primary)"></i>Money in</span><span><i style="background:#DCCEB9"></i>Money out</span></span>
      </div>
      <div style="padding:14px 20px 18px">
        <div class="acc-chart">
          ${d.cash_flow.map(c => `
            <div class="acc-chart-col">
              <div class="acc-chart-bars">
                <div class="acc-chart-bar in" style="height:${Math.round(Number(c.income) / maxFlow * 118) || 2}px" title="In ${accMoney(c.income)}"></div>
                <div class="acc-chart-bar out" style="height:${Math.round(Number(c.expense) / maxFlow * 118) || 2}px" title="Out ${accMoney(c.expense)}"></div>
              </div>
              <div class="acc-chart-label">${esc(c.month)}</div>
            </div>`).join('')}
        </div>
      </div>
    </div>`;

  const attention = [];
  (d.overdue_invoices || []).forEach(i => attention.push({
    title: 'Overdue: ' + (i.contact_name || 'Unknown'),
    sub: accMoney(Number(i.amount) - Number(i.paid_amount)) + ' past due · ' + accDateShort(i.due_at),
    cls: 'acc-neg', id: i.id,
  }));
  (d.unpaid_bills || []).forEach(b => attention.push({
    title: 'Unpaid bill: ' + (b.contact_name || 'Unknown'),
    sub: accMoney(Number(b.amount) - Number(b.paid_amount)) + ' due · ' + accDateShort(b.due_at),
    cls: 'acc-warn', id: b.id,
  }));

  const attentionCard = `
    <div class="acc-card acc-card-flush">
      <div class="acc-card-head"><span class="acc-card-title">Needs attention</span></div>
      ${attention.length ? attention.map(a => `
        <div class="acc-row acc-row-link" style="grid-template-columns:1fr auto" onclick="accGoDoc(${a.id})">
          <div><div class="acc-strong ${a.cls}">${esc(a.title)}</div><div class="acc-sub">${esc(a.sub)}</div></div>
          <div class="acc-dim">${I.arrowR || '→'}</div>
        </div>`).join('')
        : `<div class="acc-empty">All clear — no overdue invoices or unpaid bills.</div>`}
    </div>`;

  const maxSpend = Math.max(1, ...(d.spending || []).map(s2 => Number(s2.total)));
  const spendingCard = `
    <div class="acc-card">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
        <span class="acc-card-title">Spending by category</span><span class="acc-card-note">${d.year}</span>
      </div>
      ${(d.spending || []).length ? d.spending.map(s2 => `
        <div class="acc-bar-row">
          <div class="acc-bar-top"><span>${esc(s2.name)}</span><span class="acc-strong">${accMoney(s2.total)}</span></div>
          <div class="acc-bar-track"><div class="acc-bar-fill" style="width:${Math.round(Number(s2.total) / maxSpend * 100)}%"></div></div>
        </div>`).join('') : `<div class="acc-empty">No spending recorded yet.</div>`}
    </div>`;

  const recentCard = `
    <div class="acc-card acc-card-flush">
      <div class="acc-card-head">
        <span class="acc-card-title">Recent invoices</span>
        <button class="btn-secondary" style="padding:5px 11px;font-size:12.5px" onclick="accNav('acc-invoices')">View all</button>
      </div>
      ${accTable(
        [{ label: 'Number', width: '110px' }, { label: 'Customer', width: 'minmax(0,1.8fr)' }, { label: 'Status', width: '110px' }, { label: 'Amount', width: '120px', align: 'right' }],
        (d.recent_invoices || []).map(i => [
          `<span class="acc-mono">${esc(i.number)}</span>`,
          `<span class="acc-strong acc-truncate">${esc(i.contact_name || 'Unknown')}</span>`,
          accPill(i.status),
          accMoney(i.amount),
        ]),
        'No invoices yet.',
        (i) => `onclick="accGoDoc(${(d.recent_invoices[i] || {}).id})"`
      )}
    </div>`;

  return `
    <div class="fade-in acc-page">
      ${accHeader('Finance overview', 'Live position for ' + d.year + ' — income, spend, cash and what needs chasing.',
        `${accHas('acc.invoices') ? `<button class="btn-primary" onclick="accDocEditor('invoice')">${I.plus} New invoice</button>` : ''}
         ${accHas('acc.bills') ? `<button class="btn-secondary" onclick="accDocEditor('bill')">${I.plus} New bill</button>` : ''}`)}
      ${emptyNotice}
      <div class="acc-stats">
        ${accStat('Total income', accMoney(s.income), 'Year to date')}
        ${accStat('Total expense', accMoney(s.expense), 'Year to date')}
        ${accStat('Net income', accMoney(s.net), Number(s.net) >= 0 ? 'Profit' : 'Loss', Number(s.net) >= 0 ? 'acc-pos' : 'acc-neg')}
        ${accStat('Cash on hand', accMoney(s.cash), 'All enabled accounts')}
        ${accStat('Receivable', accMoney(s.receivable), 'Owed to you', Number(s.receivable) > 0 ? 'acc-warn' : '')}
        ${accStat('Payable', accMoney(s.payable), 'You owe', Number(s.payable) > 0 ? 'acc-neg' : '')}
      </div>
      <div class="acc-split">${chart}${attentionCard}</div>
      <div class="acc-split">${spendingCard}${recentCard}</div>
    </div>`;
}

async function accLoadSeed() {
  const ok = await Modal.confirm({
    title: 'Load sample data',
    message: 'This loads the bundled demo dataset (customers, vendors, invoices, bills, transactions and a full chart of accounts). You can clear it later from Settings.',
    confirmText: 'Load sample data',
  });
  if (!ok) return;
  try {
    await API.accSeed();
    accResetCaches();
    toast('Sample accounting data loaded', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function accResetCaches() {
  AccState.boot = null;
  AccState.dashboard = null;
  AccState.docs = { invoice: null, bill: null };
  AccState.doc = null;
  AccState.contacts = null;
  AccState.contact = null;
  AccState.banking = null;
  AccState.transactions = null;
  AccState.account = null;
  AccState.reconciliation = null;
  AccState.journal = null;
  AccState.coa = null;
  AccState.catalog = null;
  AccState.recurring = null;
  AccState.reports = null;
  AccState.settings = null;
  AccState.matchable = [];
}

/* ===================== Invoices & bills — list ===================== */

async function renderAccDocuments(type) {
  await accBoot();
  const module = type === 'bill' ? 'acc.bills' : 'acc.invoices';
  if (!accHas(module)) return accDenied(type === 'bill' ? 'bills' : 'invoices');

  const f = AccState.docFilter[type];
  if (!AccState.docs[type]) {
    AccState.docs[type] = await API.accDocuments({ type, status: f.status, search: f.search, page: f.page });
  }
  const data = AccState.docs[type];
  const c = data.counts || {};
  const isInvoice = type === 'invoice';

  const tabs = [
    ['all', 'All', c.all_n],
    ['open', 'Open', c.open_n],
    ['overdue', 'Overdue', c.overdue_n],
  ].concat(isInvoice ? [['draft', 'Draft', c.draft_n]] : []).concat([['paid', 'Paid', c.paid_n]]);

  const summary = `<div class="acc-summary">
      <span class="acc-dim">Open <b>${accMoney(c.open_amt)}</b></span>
      <span class="acc-dim">Overdue <b class="acc-neg">${accMoney(c.overdue_amt)}</b></span>
      ${isInvoice ? `<span class="acc-dim">Draft <b>${accMoney(c.draft_amt)}</b></span>` : ''}
    </div>`;

  const rows = (data.documents || []).map(d => [
    `<span class="acc-mono">${esc(d.number)}</span>`,
    `<div style="min-width:0"><div class="acc-strong acc-truncate">${esc(d.contact_name || '—')}</div>
     <div class="acc-sub acc-truncate">${esc(d.first_item || d.order_number || '')}</div></div>`,
    accDateShort(d.due_at),
    accPill(d.display_status),
    accMoney(d.amount),
    Number(d.amount) - Number(d.paid_amount) > 0.005
      ? `<span class="acc-num">${accMoney(Number(d.amount) - Number(d.paid_amount))}</span>` : '<span class="acc-dim acc-num">—</span>',
  ]);

  return `
    <div class="fade-in acc-page">
      ${accHeader(isInvoice ? 'Invoices' : 'Bills & expenses',
        isInvoice ? 'Money owed to Victory Genomics.' : 'Money Victory Genomics owes.',
        (isInvoice
          ? `<button class="btn-primary" onclick="accDocEditor('${type}')">${I.plus} New invoice</button>`
          : `<button class="btn-secondary" onclick="accGoBillScan()">${I.file || ''} Upload a bill</button>
             <button class="btn-primary" onclick="accDocEditor('${type}')">${I.plus} New bill</button>`))}

      <div class="acc-toolbar">
        <div class="acc-tabs" style="margin:0">
          ${tabs.map(t => `<button class="acc-tab ${f.status === t[0] ? 'active' : ''}" onclick="accDocStatusFilter('${type}','${t[0]}')">
            ${esc(t[1])}<span class="acc-tab-count">${Number(t[2] || 0)}</span></button>`).join('')}
        </div>
        <div class="acc-search">
          <input class="form-input" id="acc-doc-search-${type}" placeholder="Search number, reference or name…"
                 value="${esc(f.search)}" onkeydown="if(event.key==='Enter')accDocSearch('${type}')">
        </div>
        <button class="btn-secondary" onclick="accDocSearch('${type}')">Search</button>
      </div>
      ${summary}

      <div class="acc-card acc-card-flush" style="margin-top:14px">
        ${accTable(
          [{ label: 'Number', width: '110px' }, { label: isInvoice ? 'Customer' : 'Vendor', width: 'minmax(0,2fr)' },
           { label: 'Due', width: '100px' }, { label: 'Status', width: '110px' },
           { label: 'Amount', width: '120px', align: 'right' }, { label: 'Balance', width: '120px', align: 'right' }],
          rows,
          isInvoice ? 'No invoices match.' : 'No bills match.',
          (i) => `onclick="accGoDoc(${(data.documents[i] || {}).id})"`
        )}
        ${accPager(data.meta, type === 'invoice' ? 'accInvoicePage' : 'accBillPage')}
      </div>
    </div>`;
}

function accDocStatusFilter(type, status) {
  AccState.docFilter[type].status = status;
  AccState.docFilter[type].page = 1;
  AccState.docs[type] = null;
  render();
}

function accDocSearch(type) {
  AccState.docFilter[type].search = accVal('acc-doc-search-' + type);
  AccState.docFilter[type].page = 1;
  AccState.docs[type] = null;
  render();
}

function accInvoicePage(p) { AccState.docFilter.invoice.page = p; AccState.docs.invoice = null; render(); }
function accBillPage(p) { AccState.docFilter.bill.page = p; AccState.docs.bill = null; render(); }

/* ===================== Document detail ===================== */

async function renderAccDocument(id) {
  await accBoot();
  if (!id) return accDenied('this document');
  if (!AccState.doc || Number(AccState.doc.document.id) !== Number(id)) {
    AccState.doc = await API.accDocument(id);
  }
  const { document: d, contact, items, totals, histories, payments, attachments, agent } = AccState.doc;
  const isInvoice = d.type === 'invoice';
  const module = isInvoice ? 'acc.invoices' : 'acc.bills';
  if (!accHas(module)) return accDenied(isInvoice ? 'invoices' : 'bills');

  const totalOf = (code, fallback) => {
    const t = (totals || []).find(x => x.code === code);
    return t ? Number(t.amount) : fallback;
  };
  const subtotal = totalOf('subtotal', Number(d.amount));
  const tax = totalOf('tax', 0);
  const total = totalOf('total', Number(d.amount));
  const paid = Number(d.paid_amount);
  const due = total - paid;

  const canEdit = !['paid', 'cancelled'].includes(d.status);
  const canPay = !['paid', 'cancelled'].includes(d.status) && due > 0.005;

  const actions = `
    ${d.status === 'draft' ? `<button class="btn-primary" onclick="accDocAction(${d.id},'${isInvoice ? 'send' : 'receive'}')">${isInvoice ? 'Mark as sent' : 'Mark as received'}</button>` : ''}
    ${canPay ? `<button class="btn-primary" onclick="accPaymentModal(${d.id})">Record payment</button>` : ''}
    ${canEdit ? `<button class="btn-secondary" onclick="accDocEditor('${d.type}',${d.id})">${I.pencil} Edit</button>` : ''}
    ${isInvoice ? `<button class="btn-secondary" onclick="accPrintInvoice(${d.id})">Print / PDF</button>` : ''}
    ${d.status !== 'cancelled' ? `<button class="btn-secondary" onclick="accDocAction(${d.id},'cancel')">Cancel</button>` : ''}
    <button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteDoc(${d.id})">${I.trash} Delete</button>`;

  const lineRows = (items || []).map(it => [
    `<div><div class="acc-strong">${esc(it.name)}</div>${(it.taxes || []).length ? `<div class="acc-sub">${(it.taxes || []).map(t => esc(t.name)).join(', ')}</div>` : ''}</div>`,
    `<span class="acc-mono">${Number(it.quantity)}</span>`,
    accMoney(it.price),
    accMoney(it.total),
  ]);

  return `
    <div class="fade-in acc-page">
      <div style="margin-bottom:12px">${accBackLink(isInvoice ? 'Invoices' : 'Bills', `accNav('${isInvoice ? 'acc-invoices' : 'acc-bills'}')`)}</div>
      ${accHeader(d.number + ' · ' + (contact ? contact.name : '—'),
        `Issued ${accDate(d.issued_at)} · Due ${accDate(d.due_at)}`, actions)}
      <div style="margin:-8px 0 16px">${accPill(d.display_status)}</div>

      <div class="acc-split">
        <div>
          <div class="acc-card acc-card-flush">
            <div style="padding:18px 20px;display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;border-bottom:1px solid var(--border)">
              <div>
                <div class="acc-print-label">${isInvoice ? 'Bill to' : 'Bill from'}</div>
                <div class="acc-strong" style="font-size:15px">${esc(contact ? contact.name : '—')}</div>
                ${contact && contact.email ? `<div class="acc-sub">${esc(contact.email)}</div>` : ''}
                ${contact && contact.phone ? `<div class="acc-sub">${esc(contact.phone)}</div>` : ''}
                ${contact && contact.address ? `<div class="acc-sub">${esc(contact.address)}</div>` : ''}
              </div>
              <div style="display:flex;gap:26px;flex-wrap:wrap">
                <div><div class="acc-print-label">Issued</div><div class="acc-strong">${accDate(d.issued_at)}</div></div>
                <div><div class="acc-print-label">Due</div><div class="acc-strong">${accDate(d.due_at)}</div></div>
                ${agent ? `<div><div class="acc-print-label">${isInvoice ? 'Sales agent' : 'Owner'}</div><div class="acc-strong">${esc(agent.name)}</div></div>` : ''}
              </div>
            </div>
            ${accTable(
              [{ label: 'Description', width: 'minmax(0,2fr)' }, { label: 'Qty', width: '70px' },
               { label: 'Rate', width: '110px', align: 'right' }, { label: 'Amount', width: '120px', align: 'right' }],
              lineRows, 'No line items.')}
            <div style="padding:16px 20px">
              <div class="acc-totals">
                <div class="acc-kv"><span class="acc-kv-label">Subtotal</span><span class="acc-kv-value">${accMoney(subtotal)}</span></div>
                ${tax > 0 ? `<div class="acc-kv"><span class="acc-kv-label">Tax</span><span class="acc-kv-value">${accMoney(tax)}</span></div>` : ''}
                <div class="acc-kv acc-kv-grand"><span>Total</span><span class="acc-kv-value">${accMoney(total)}</span></div>
                <div class="acc-kv"><span class="acc-kv-label">Paid</span><span class="acc-kv-value acc-pos">${accMoney(paid)}</span></div>
                <div class="acc-due-box"><span>Amount due</span><span>${accMoney(due)}</span></div>
              </div>
            </div>
          </div>
          ${d.notes ? `<div class="acc-card"><div class="acc-card-title" style="margin-bottom:6px">Notes</div><div style="font-size:13.5px;white-space:pre-wrap">${esc(d.notes)}</div></div>` : ''}
          ${d.terms ? `<div class="acc-card"><div class="acc-card-title" style="margin-bottom:6px">Terms</div><div style="font-size:13.5px;white-space:pre-wrap">${esc(d.terms)}</div></div>` : ''}
        </div>

        <div>
          <div class="acc-card">
            <div class="acc-card-title" style="margin-bottom:10px">Payments</div>
            ${(payments || []).length ? payments.map(p => `
              <div class="acc-kv">
                <span><span class="acc-strong">${esc((p.payment_method || 'bank transfer').replace(/_/g, ' '))}</span>
                  <span class="acc-sub"> · ${accDate(p.paid_at)}${p.account_name ? ' · ' + esc(p.account_name) : ''}</span></span>
                <span class="acc-kv-value acc-pos">${accMoney(p.amount)}</span>
              </div>
              ${(p.adjustments || []).map(a => `
                <div class="acc-kv acc-kv-sub">
                  <span class="acc-kv-label">${esc(accAdjLabel(a.kind))}${a.description ? ' · ' + esc(a.description) : ''}</span>
                  <span class="acc-kv-value acc-dim">${accMoney(Math.abs(Number(a.amount)))}</span>
                </div>`).join('')}
            `).join('') : `<div class="acc-sub">No payments recorded yet.</div>`}
          </div>
          ${accAttachmentsCard('document', d.id, attachments, isInvoice ? 'Attachments' : 'Bill & receipts')}
          <div class="acc-card">
            <div class="acc-card-title" style="margin-bottom:12px">Activity</div>
            ${(histories || []).length ? `<div class="acc-timeline">${histories.map(h => `
              <div class="acc-timeline-item">
                <div class="acc-timeline-title">${esc(String(h.status).charAt(0).toUpperCase() + String(h.status).slice(1))}</div>
                ${h.description ? `<div class="acc-sub">${esc(h.description)}</div>` : ''}
                <div class="acc-timeline-meta">${accDateTime(h.created_at)}</div>
              </div>`).join('')}</div>` : `<div class="acc-sub">No activity yet.</div>`}
          </div>
        </div>
      </div>
    </div>`;
}

async function accDocAction(id, action) {
  if (action === 'cancel') {
    const ok = await Modal.confirm({
      title: 'Cancel document',
      message: 'Cancelling posts a reversing journal entry and takes this document out of your open balances. This cannot be undone.',
      confirmText: 'Cancel document', danger: true,
    });
    if (!ok) return;
  }
  try {
    await API.accDocumentStatus(id, action);
    AccState.doc = null;
    AccState.docs = { invoice: null, bill: null };
    AccState.dashboard = null;
    toast(action === 'cancel' ? 'Document cancelled' : 'Status updated', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteDoc(id) {
  const ok = await Modal.confirm({
    title: 'Delete document',
    message: 'This removes the document, reverses its ledger entries and detaches any payments. This cannot be undone.',
    confirmText: 'Delete', danger: true,
  });
  if (!ok) return;
  try {
    const type = AccState.doc && AccState.doc.document ? AccState.doc.document.type : 'invoice';
    await API.accDeleteDocument(id);
    AccState.doc = null;
    AccState.docs = { invoice: null, bill: null };
    AccState.dashboard = null;
    toast('Document deleted', 'success');
    accNav(type === 'bill' ? 'acc-bills' : 'acc-invoices');
  } catch (e) { toast(e.message, 'error'); }
}

/* ===================== Document editor (create / edit) ===================== */

async function accDocEditor(type, id) {
  const o = accOpts();
  let doc = null, items = [];
  if (id) {
    try {
      const res = await API.accDocument(id);
      doc = res.document;
      items = res.items || [];
    } catch (e) { toast(e.message, 'error'); return; }
  }

  const isInvoice = type === 'invoice';
  const contacts = isInvoice ? (o.customers || []) : (o.vendors || []);
  // "Issue invoice" from a CRM customer preselects that contact.
  if (!id && isInvoice && State.accPrefillContactId) {
    doc = Object.assign({}, doc || {}, { contact_id: State.accPrefillContactId });
    State.accPrefillContactId = null;
  }
  AccState.editor = {
    type, id: id || null,
    lines: items.length
      ? items.map(it => ({ name: it.name, quantity: Number(it.quantity), price: Number(it.price), tax_ids: (it.taxes || []).map(t => Number(t.tax_id)) }))
      : [{ name: '', quantity: 1, price: 0, tax_ids: [] }],
  };

  accOpenForm({
    title: (id ? 'Edit ' : 'New ') + (isInvoice ? 'invoice' : 'bill'),
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField(isInvoice ? 'Customer' : 'Vendor',
          accSelect('acc-ed-contact', contacts.map(c => ({ value: c.id, label: c.name })),
            doc ? doc.contact_id : '', isInvoice ? 'Select customer…' : 'Select vendor…'))}
        ${accField('Reference / PO', `<input class="form-input" id="acc-ed-order" value="${esc(doc ? (doc.order_number || '') : '')}" placeholder="Optional">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Issue date', `<input class="form-input" type="date" id="acc-ed-issued" value="${esc(doc ? doc.issued_at : accToday())}">`)}
        ${accField('Due date', `<input class="form-input" type="date" id="acc-ed-due" value="${esc(doc ? doc.due_at : accAddDays(isInvoice ? 30 : 15))}">`)}
        ${accField('Apply tax to all lines', accSelect('acc-ed-tax',
          (o.taxes || []).map(t => ({ value: t.id, label: t.name + ' (' + Number(t.rate) + '%)' })),
          (AccState.editor.lines[0] && AccState.editor.lines[0].tax_ids[0]) || '', 'No tax', 'onchange="accEditorRecalc()"'))}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField(isInvoice ? 'Revenue type' : 'Expense type', accSelect('acc-ed-category',
          (o.categories || []).filter(c => c.type === (isInvoice ? 'income' : 'expense')).map(c => ({ value: c.id, label: c.name })),
          doc ? doc.category_id : '', 'Uncategorized'))}
        ${accField(isInvoice ? 'Sales agent' : 'Owner', accSelect('acc-ed-agent',
          (o.agents || []).map(a => ({ value: a.id, label: a.name })),
          doc ? doc.user_id : '', 'Unassigned'))}
      </div>

      <div style="margin-top:16px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <span class="acc-card-title" style="flex:1">Line items</span>
          <button class="btn-secondary" style="padding:5px 11px;font-size:12.5px" onclick="accEditorAddLine()">${I.plus} Add line</button>
        </div>
        <div class="acc-line" style="font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)">
          <div>Description</div><div>Qty</div><div>Rate</div><div style="text-align:right">Amount</div><div></div>
        </div>
        <div class="acc-lines" id="acc-ed-lines"></div>
      </div>

      <div class="form-row" style="gap:12px;margin-top:14px;flex-wrap:wrap">
        ${accField('Notes', `<textarea class="form-input" id="acc-ed-notes" rows="2" placeholder="Visible on the document">${esc(doc ? (doc.notes || '') : '')}</textarea>`)}
        ${isInvoice ? accField('Terms', `<textarea class="form-input" id="acc-ed-terms" rows="2">${esc(doc ? (doc.terms || '') : (AccState.boot.settings.default_payment_terms || ''))}</textarea>`) : ''}
      </div>

      <div class="acc-totals" style="margin-top:14px">
        <div class="acc-kv"><span class="acc-kv-label">Subtotal</span><span class="acc-kv-value" id="acc-ed-subtotal">${accMoney(0)}</span></div>
        <div class="acc-kv"><span class="acc-kv-label">Tax</span><span class="acc-kv-value" id="acc-ed-taxamt">${accMoney(0)}</span></div>
        <div class="acc-kv acc-kv-grand"><span>Total</span><span class="acc-kv-value" id="acc-ed-total">${accMoney(0)}</span></div>
      </div>`,
    footer: `<button class="btn-secondary" onclick="accCloseForm()">Cancel</button>
             <button class="btn-primary" onclick="accEditorSave()">${id ? 'Save changes' : 'Create ' + (isInvoice ? 'invoice' : 'bill')}</button>`,
    onMount: () => { accEditorRenderLines(); },
  });
}

function accEditorRenderLines() {
  const wrap = document.getElementById('acc-ed-lines');
  if (!wrap) return;
  const items = accOpts().items || [];
  const datalist = `<datalist id="acc-item-list">${items.map(i => `<option value="${esc(i.name)}">`).join('')}</datalist>`;
  wrap.innerHTML = datalist + AccState.editor.lines.map((l, i) => `
    <div class="acc-line">
      <input class="form-input" list="acc-item-list" placeholder="Item or service"
             value="${esc(l.name)}" oninput="accEditorSet(${i},'name',this.value)" onchange="accEditorAutofill(${i},this.value)">
      <input class="form-input" type="number" step="0.01" min="0" style="text-align:right"
             value="${Number(l.quantity)}" oninput="accEditorSet(${i},'quantity',this.value)">
      <input class="form-input" type="number" step="0.01" min="0" style="text-align:right"
             value="${Number(l.price)}" oninput="accEditorSet(${i},'price',this.value)">
      <div class="acc-line-amount" id="acc-ed-line-${i}">${accMoney(Number(l.quantity) * Number(l.price))}</div>
      <button class="acc-line-del" onclick="accEditorRemoveLine(${i})" title="Remove line">×</button>
    </div>`).join('');
  accEditorRecalc();
}

function accEditorSet(i, key, value) {
  if (!AccState.editor || !AccState.editor.lines[i]) return;
  AccState.editor.lines[i][key] = (key === 'name') ? value : Number(value || 0);
  const el = document.getElementById('acc-ed-line-' + i);
  if (el) {
    const l = AccState.editor.lines[i];
    el.textContent = accMoney(Number(l.quantity) * Number(l.price));
  }
  accEditorRecalc();
}

/** Picking a catalogued item name fills in its price. */
function accEditorAutofill(i, value) {
  const item = (accOpts().items || []).find(x => x.name === value);
  if (!item || !AccState.editor || !AccState.editor.lines[i]) return;
  const type = AccState.editor.type;
  const price = type === 'invoice' ? item.sale_price : item.purchase_price;
  if (price === null || price === undefined) return;
  AccState.editor.lines[i].price = Number(price);
  accEditorRenderLines();
}

function accEditorAddLine() {
  AccState.editor.lines.push({ name: '', quantity: 1, price: 0, tax_ids: [] });
  accEditorRenderLines();
}

function accEditorRemoveLine(i) {
  if (AccState.editor.lines.length <= 1) { toast('An invoice needs at least one line', 'error'); return; }
  AccState.editor.lines.splice(i, 1);
  accEditorRenderLines();
}

function accEditorRecalc() {
  if (!AccState.editor) return;
  const taxId = accVal('acc-ed-tax');
  const tax = (accOpts().taxes || []).find(t => String(t.id) === String(taxId));
  const rate = tax ? Number(tax.rate) / 100 : 0;
  let subtotal = 0;
  AccState.editor.lines.forEach(l => { subtotal += Number(l.quantity || 0) * Number(l.price || 0); });
  const taxAmt = subtotal * rate;
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = accMoney(v); };
  set('acc-ed-subtotal', subtotal);
  set('acc-ed-taxamt', taxAmt);
  set('acc-ed-total', subtotal + taxAmt);
}

async function accEditorSave() {
  const ed = AccState.editor;
  if (!ed) return;
  const contactId = accVal('acc-ed-contact');
  if (!contactId) { toast(ed.type === 'invoice' ? 'Select a customer' : 'Select a vendor', 'error'); return; }

  const taxId = accVal('acc-ed-tax');
  const taxIds = taxId ? [Number(taxId)] : [];
  const lines = ed.lines
    .filter(l => String(l.name || '').trim() !== '')
    .map(l => ({ name: l.name, quantity: Number(l.quantity || 0), price: Number(l.price || 0), tax_ids: taxIds }));
  if (!lines.length) { toast('Add at least one line item', 'error'); return; }

  const payload = {
    type: ed.type,
    contact_id: Number(contactId),
    order_number: accVal('acc-ed-order'),
    issued_at: accVal('acc-ed-issued'),
    due_at: accVal('acc-ed-due'),
    notes: accVal('acc-ed-notes'),
    terms: accVal('acc-ed-terms'),
    category_id: accVal('acc-ed-category') ? Number(accVal('acc-ed-category')) : null,
    user_id: accVal('acc-ed-agent') ? Number(accVal('acc-ed-agent')) : null,
    items: lines,
  };

  try {
    let newId = ed.id;
    if (ed.id) await API.accUpdateDocument(ed.id, payload);
    else {
      const res = await API.accCreateDocument(payload);
      newId = res.id;
    }
    accCloseForm();
    AccState.editor = null;
    AccState.doc = null;
    AccState.docs = { invoice: null, bill: null };
    AccState.dashboard = null;
    toast(ed.id ? 'Saved' : (ed.type === 'invoice' ? 'Invoice created' : 'Bill created'), 'success');
    accGoDoc(newId);
  } catch (e) { toast(e.message, 'error'); }
}

/* ===================== Payment modal ===================== */

/* ===================== Attachments (shared) =====================
 * One card used by invoices, bills, reconciliations and transactions. Files are
 * stored outside the docroot and streamed back through an authorised endpoint,
 * so a link is useless to anyone without the matching module grant.
 */

function accFileExt(name) {
  const m = String(name || '').match(/\.([A-Za-z0-9]{1,6})$/);
  return m ? m[1].toUpperCase() : 'FILE';
}

function accFileSize(bytes) {
  const b = Number(bytes) || 0;
  if (b < 1024) return b + ' B';
  if (b < 1048576) return Math.round(b / 1024) + ' KB';
  return (b / 1048576).toFixed(1) + ' MB';
}

function accAttachmentList(list) {
  if (!list || !list.length) return `<div class="acc-sub">No files attached yet.</div>`;
  return list.map(a => `
    <div class="acc-att">
      <span class="acc-att-ext">${esc(accFileExt(a.name))}</span>
      <div style="min-width:0;flex:1">
        <a class="acc-att-name" href="${API.accAttachmentUrl(a.id, true)}" target="_blank" rel="noopener">${esc(a.name)}</a>
        <div class="acc-sub">${accFileSize(a.size)}${a.uploaded_by_name ? ' · ' + esc(a.uploaded_by_name) : ''}${a.created_at ? ' · ' + accDate(a.created_at) : ''}</div>
      </div>
      <a class="acc-att-act" href="${API.accAttachmentUrl(a.id)}" title="Download">&darr;</a>
      <button type="button" class="acc-att-act acc-att-del" title="Remove" onclick="accRemoveAttachment(${a.id})">&times;</button>
    </div>`).join('');
}

function accAttachmentsCard(type, id, attachments, title) {
  return `
    <div class="acc-card" id="acc-att-card" data-att-type="${type}" data-att-id="${id}">
      <div class="acc-card-title" style="margin-bottom:10px">${esc(title || 'Attachments')}</div>
      <div id="acc-att-list">${accAttachmentList(attachments)}</div>
      <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <input type="file" id="acc-att-file" style="display:none" onchange="accAddAttachment()">
        <button type="button" class="btn-secondary" onclick="document.getElementById('acc-att-file').click()">Attach file</button>
        <span class="acc-sub">PDF, image or spreadsheet · max 25MB</span>
      </div>
    </div>`;
}

/** Refresh the on-screen list and the cached copy without a full re-render. */
function accSetAttachments(type, id, list) {
  const el = document.getElementById('acc-att-list');
  if (el) el.innerHTML = accAttachmentList(list);
  if (type === 'document' && AccState.doc && Number(AccState.doc.document.id) === Number(id)) {
    AccState.doc.attachments = list;
  }
  if (type === 'reconciliation' && AccState.reconciliation
      && Number(id) === Number(AccState.reconciliation.reconciliation.id)) {
    AccState.reconciliation.attachments = list;
  }
}

async function accAddAttachment() {
  const card = document.getElementById('acc-att-card');
  const input = document.getElementById('acc-att-file');
  const file = input && input.files && input.files[0];
  if (!card || !file) return;
  const type = card.getAttribute('data-att-type');
  const id = card.getAttribute('data-att-id');
  try {
    const res = await API.accUploadAttachment(type, id, file);
    accSetAttachments(type, id, res.attachments || []);
    toast('File attached', 'success');
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    if (input) input.value = '';
  }
}

async function accRemoveAttachment(attId) {
  const ok = await Modal.confirm({
    title: 'Remove file',
    message: 'This permanently deletes the file from the server. This cannot be undone.',
    confirmText: 'Remove', danger: true,
  });
  if (!ok) return;
  const card = document.getElementById('acc-att-card');
  try {
    const res = await API.accDeleteAttachment(attId);
    if (card) accSetAttachments(card.getAttribute('data-att-type'), card.getAttribute('data-att-id'), res.attachments || []);
    toast('File removed', 'success');
  } catch (e) { toast(e.message, 'error'); }
}

/* ---------- Payment adjustments (bank fees, discounts, write-offs) ---------- */
// Shared by the document payment modal and the bank-transaction matcher: the
// cash that moved and the amount a document settles for are rarely identical.

let ACC_ADJ_SEQ = 0;

function accAdjLabel(kind) {
  const k = accOpts().adjustment_kinds || {};
  return k[kind] || (kind === 'fee' ? 'Bank / processing fee' : kind === 'writeoff' ? 'Write-off' : 'Discount');
}

function accAdjKinds() {
  const k = accOpts().adjustment_kinds || { fee: 'Bank / processing fee', discount: 'Discount', writeoff: 'Write-off' };
  return Object.keys(k).map(key => ({ value: key, label: k[key] }));
}

function accAdjRow(a) {
  const i = ACC_ADJ_SEQ++;
  a = a || {};
  return `<div class="acc-adj-row" data-adj="${i}">
    ${accSelect('acc-adj-kind-' + i, accAdjKinds(), a.kind || 'fee', null, 'onchange="accAdjRecalc()"')}
    <input class="form-input" type="number" step="0.01" min="0" id="acc-adj-amt-${i}"
           value="${a.amount != null ? Number(a.amount) : ''}" placeholder="0.00"
           style="text-align:right" oninput="accAdjRecalc()">
    <input class="form-input" id="acc-adj-desc-${i}" value="${esc(a.description || '')}" placeholder="Note (optional)">
    <button type="button" class="acc-adj-x" title="Remove" onclick="accAdjRemove(${i})">&times;</button>
  </div>`;
}

function accAdjAdd(a) {
  const host = document.getElementById('acc-adj-rows');
  if (!host) return;
  host.insertAdjacentHTML('beforeend', accAdjRow(a));
  accAdjRecalc();
}

function accAdjRemove(i) {
  const row = document.querySelector('[data-adj="' + i + '"]');
  if (row) row.remove();
  accAdjRecalc();
}

/** Collect the adjustment rows currently on screen. */
function accAdjCollect() {
  const out = [];
  document.querySelectorAll('#acc-adj-rows [data-adj]').forEach(el => {
    const i = el.getAttribute('data-adj');
    const amount = Math.abs(accNumVal('acc-adj-amt-' + i) || 0);
    if (!amount) return;
    out.push({ kind: accVal('acc-adj-kind-' + i), amount, description: accVal('acc-adj-desc-' + i) });
  });
  return out;
}

/**
 * Settlement preview. Mirrors Acc::normaliseAdjustments on the server: a fee on
 * money coming in adds to what the document settles for, a fee on money going
 * out subtracts. The server re-checks all of this — this is only the preview.
 */
function accAdjSettled(docType, cash) {
  let settled = Number(cash) || 0;
  accAdjCollect().forEach(a => {
    settled += (docType === 'bill' && a.kind === 'fee') ? -a.amount : a.amount;
  });
  return Math.round(settled * 100) / 100;
}

function accAdjRecalc() {
  const box = document.getElementById('acc-adj-summary');
  if (!box) return;
  const docType = box.getAttribute('data-doc-type') || 'invoice';
  const balance = Number(box.getAttribute('data-balance')) || 0;
  const cashId = box.getAttribute('data-cash-field') || 'acc-pay-amount';
  const cash = accNumVal(cashId) || 0;
  const settled = accAdjSettled(docType, cash);
  const left = Math.round((balance - settled) * 100) / 100;
  const over = settled > balance + 0.005;

  box.innerHTML = `
    <div class="acc-kv"><span class="acc-kv-label">Cash ${docType === 'bill' ? 'paid' : 'received'}</span><span class="acc-kv-value">${accMoney(cash)}</span></div>
    <div class="acc-kv"><span class="acc-kv-label">Settles</span><span class="acc-kv-value ${over ? 'acc-neg' : ''}">${accMoney(settled)}</span></div>
    <div class="acc-kv"><span class="acc-kv-label">${over ? 'Over the balance by' : 'Still outstanding after'}</span><span class="acc-kv-value ${over ? 'acc-neg' : (Math.abs(left) < 0.005 ? 'acc-pos' : '')}">${accMoney(over ? settled - balance : left)}</span></div>`;
}

function accAdjBlock(docType, balance, cashField) {
  return `
    <div class="acc-adj">
      <div class="acc-adj-head">
        <span class="acc-card-title">Adjustments</span>
        <button type="button" class="btn-secondary btn-sm" onclick="accAdjAdd()">+ Add adjustment</button>
      </div>
      <p class="acc-sub" style="margin:0 0 8px">
        Use these when the money that moved doesn't equal the amount owed — a wire fee,
        an early-payment discount, or a small write-off.
      </p>
      <div id="acc-adj-rows"></div>
      <div id="acc-adj-summary" class="acc-adj-summary"
           data-doc-type="${docType}" data-balance="${Number(balance).toFixed(2)}" data-cash-field="${cashField}"></div>
    </div>`;
}

function accPaymentModal(docId) {
  const d = AccState.doc && AccState.doc.document;
  if (!d) return;
  const balance = Number(d.amount) - Number(d.paid_amount);
  const accounts = accOpts().accounts || [];

  accOpenForm({
    title: d.type === 'invoice' ? 'Record payment received' : 'Record payment made',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Amount', `<input class="form-input" type="number" step="0.01" min="0" id="acc-pay-amount" value="${balance.toFixed(2)}" style="text-align:right" oninput="accAdjRecalc()">`)}
        ${accField('Date', `<input class="form-input" type="date" id="acc-pay-date" value="${accToday()}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField(d.type === 'invoice' ? 'Deposit into' : 'Pay from',
          accSelect('acc-pay-account', accounts.map(a => ({ value: a.id, label: a.name + (a.number ? ' ····' + String(a.number).slice(-4) : '') })), accounts[0] ? accounts[0].id : '', 'Select account…'))}
        ${accField('Method', accSelect('acc-pay-method', [
          { value: 'bank_transfer', label: 'Bank transfer' }, { value: 'credit_card', label: 'Credit card' },
          { value: 'cash', label: 'Cash' }, { value: 'check', label: 'Check' }, { value: 'other', label: 'Other' },
        ], 'bank_transfer'))}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Reference', `<input class="form-input" id="acc-pay-ref" placeholder="Transaction ID (optional)">`)}
      </div>
      ${accAdjBlock(d.type, balance, 'acc-pay-amount')}
      <p class="acc-sub" style="margin-top:12px">Outstanding balance: <b>${accMoney(balance)}</b></p>`,
    footer: `<button class="btn-secondary" onclick="accCloseForm()">Cancel</button>
             <button class="btn-primary" onclick="accSavePayment(${docId})">Record payment</button>`,
  });
  accAdjRecalc();
}

async function accSavePayment(docId) {
  const amount = accNumVal('acc-pay-amount');
  if (!amount || amount <= 0) { toast('Enter an amount', 'error'); return; }
  const accountId = accVal('acc-pay-account');
  if (!accountId) { toast('Select an account', 'error'); return; }
  try {
    await API.accDocumentPayment(docId, {
      amount, account_id: Number(accountId),
      paid_at: accVal('acc-pay-date'),
      payment_method: accVal('acc-pay-method'),
      reference: accVal('acc-pay-ref'),
      adjustments: accAdjCollect(),
    });
    accCloseForm();
    AccState.doc = null;
    AccState.docs = { invoice: null, bill: null };
    AccState.dashboard = null;
    AccState.banking = null;
    AccState.transactions = null;
    toast('Payment recorded', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ===================== Printable invoice ===================== */

async function accPrintInvoice(id) {
  let data = AccState.doc;
  if (!data || Number(data.document.id) !== Number(id)) data = await API.accDocument(id);
  const { document: d, contact, items, totals, company } = data;
  const totalOf = (code, fb) => { const t = (totals || []).find(x => x.code === code); return t ? Number(t.amount) : fb; };

  const html = `
    <div class="acc-print-doc" id="acc-print-area">
      <div class="acc-print-head">
        <div>
          <div style="font-size:19px;font-weight:700">${esc(company.company_name || 'Victory Genomics, Inc.')}</div>
          <div class="acc-sub" style="white-space:pre-line">${esc(company.company_address || '')}</div>
          ${company.company_email ? `<div class="acc-sub">${esc(company.company_email)}</div>` : ''}
          ${company.company_ein ? `<div class="acc-sub">EIN ${esc(company.company_ein)}</div>` : ''}
        </div>
        <div style="text-align:right">
          <div class="acc-print-title">Invoice</div>
          <div class="acc-mono">${esc(d.number)}</div>
          <div class="acc-sub">Issued ${accDate(d.issued_at)}</div>
          <div class="acc-sub">Due ${accDate(d.due_at)}</div>
        </div>
      </div>
      <div class="acc-print-parties">
        <div><div class="acc-print-label">Bill to</div>
          <div class="acc-strong">${esc(contact ? contact.name : '—')}</div>
          <div class="acc-sub">${esc(contact && contact.address ? contact.address : '')}</div>
          <div class="acc-sub">${esc(contact && contact.email ? contact.email : '')}</div>
        </div>
        <div><div class="acc-print-label">Reference</div>
          <div class="acc-sub">${esc(d.order_number || '—')}</div>
        </div>
      </div>
      ${accTable(
        [{ label: 'Description', width: 'minmax(0,2fr)' }, { label: 'Qty', width: '70px' },
         { label: 'Rate', width: '110px', align: 'right' }, { label: 'Amount', width: '120px', align: 'right' }],
        (items || []).map(it => [esc(it.name), Number(it.quantity), accMoney(it.price), accMoney(it.total)]), 'No items.')}
      <div class="acc-totals" style="margin-top:18px">
        <div class="acc-kv"><span class="acc-kv-label">Subtotal</span><span class="acc-kv-value">${accMoney(totalOf('subtotal', d.amount))}</span></div>
        <div class="acc-kv"><span class="acc-kv-label">Tax</span><span class="acc-kv-value">${accMoney(totalOf('tax', 0))}</span></div>
        <div class="acc-kv acc-kv-grand"><span>Total due</span><span class="acc-kv-value">${accMoney(totalOf('total', d.amount) - Number(d.paid_amount))}</span></div>
      </div>
      ${company.invoice_footer ? `<p class="acc-sub" style="margin-top:22px">${esc(company.invoice_footer)}</p>` : ''}
    </div>`;

  accOpenForm({
    title: 'Invoice ' + d.number,
    body: html,
    footer: `<button class="btn-secondary" onclick="accCloseForm()">Close</button>
             <button class="btn-primary" onclick="accDoPrint()">Print / Save PDF</button>`,
  });
}

function accDoPrint() {
  const area = document.getElementById('acc-print-area');
  if (!area) return;
  const w = window.open('', '_blank');
  if (!w) { toast('Allow pop-ups to print', 'error'); return; }
  w.document.write(`<!DOCTYPE html><html><head><title>Invoice</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/accounting.css">
    </head><body style="background:#fff;padding:24px">${area.outerHTML}</body></html>`);
  w.document.close();
  setTimeout(() => { w.focus(); w.print(); }, 400);
}

/* ===================== Contacts ===================== */

async function renderAccContacts(type) {
  await accBoot();
  // Customers and vendors are separate modules so the sales team can be granted
  // customers without seeing supplier data.
  const tab = type || AccState.contactTab || 'customer';
  const mod = tab === 'vendor' ? 'acc.vendors' : 'acc.customers';
  if (!accHas(mod)) return accDenied(tab === 'vendor' ? 'vendors' : 'customers');
  if (AccState.contactTab !== tab) { AccState.contactTab = tab; AccState.contacts = null; }
  State.accContactType = tab;
  if (!AccState.contacts) {
    AccState.contacts = await API.accContacts({ type: tab, search: AccState.contactSearch, page: 1 });
  }
  const data = AccState.contacts;
  const isCustomer = tab === 'customer';

  const rows = (data.contacts || []).map(c => [
    `<div style="min-width:0"><div class="acc-strong acc-truncate">${esc(c.name)}</div>
      <div class="acc-sub acc-truncate">${esc(c.email || c.phone || '')}</div></div>`,
    c.category ? `<span class="acc-chip">${esc(c.category)}</span>` : '<span class="acc-dim">—</span>',
    Number(c.open_amount) > 0 ? `<span class="acc-neg">${accMoney(c.open_amount)}</span>` : accMoney(0),
    accMoney(c.ytd_amount),
    c.last_document ? `<span class="acc-mono">${esc(c.last_document)}</span>`
      : (c.last_activity && c.last_activity > '1900-01-01' ? accDateShort(c.last_activity) : '<span class="acc-dim">—</span>'),
  ]);

  return `
    <div class="fade-in acc-page">
      ${accHeader(isCustomer ? 'Customers' : 'Vendors',
        isCustomer ? 'Everyone you invoice.' : 'Everyone who bills you.',
        `${isCustomer ? `<button class="btn-secondary" onclick="accCrmImportModal()">Import from CRM</button>` : ''}
         <button class="btn-primary" onclick="accContactModal('${tab}')">${I.plus} New ${isCustomer ? 'customer' : 'vendor'}</button>`)}

      <div class="acc-toolbar">
        <div class="acc-search">
          <input class="form-input" id="acc-contact-search" placeholder="Search name, email or phone…"
                 value="${esc(AccState.contactSearch)}" onkeydown="if(event.key==='Enter')accContactSearch()">
        </div>
        <button class="btn-secondary" onclick="accContactSearch()">Search</button>
      </div>

      <div class="acc-card acc-card-flush">
        ${accTable(
          [{ label: isCustomer ? 'Customer' : 'Vendor', width: 'minmax(0,2fr)' }, { label: 'Category', width: '150px' },
           { label: 'Open', width: '120px', align: 'right' }, { label: isCustomer ? 'Billed YTD' : 'Spent YTD', width: '130px', align: 'right' },
           { label: 'Latest', width: '120px', align: 'right' }],
          rows, isCustomer ? 'No customers found.' : 'No vendors found.',
          (i) => `onclick="accGoContact(${(data.contacts[i] || {}).id})"`)}
        ${accPager(data.meta, 'accContactPage')}
      </div>
    </div>`;
}

function accContactTab(tab) {
  AccState.contactTab = tab; AccState.contacts = null;
  if (typeof accNav === 'function') accNav(tab === 'vendor' ? 'acc-vendors' : 'acc-customers'); else render();
}
function accContactSearch() { AccState.contactSearch = accVal('acc-contact-search'); AccState.contacts = null; render(); }
async function accContactPage(p) {
  AccState.contacts = await API.accContacts({ type: AccState.contactTab, search: AccState.contactSearch, page: p });
  render();
}

async function renderAccContact(id) {
  await accBoot();
  const wantVendor = State.accContactType === 'vendor';
  if (!accHas(wantVendor ? 'acc.vendors' : 'acc.customers')) return accDenied(wantVendor ? 'vendors' : 'customers');
  if (!AccState.contact || Number(AccState.contact.contact.id) !== Number(id)) {
    AccState.contact = await API.accContact(id);
  }
  const { contact: c, people, stats, documents, transactions } = AccState.contact;
  const isCustomer = c.type === 'customer';

  const docRows = (documents || []).map(d => [
    `<span class="acc-mono">${esc(d.number)}</span>`,
    `<span class="acc-truncate">${esc(d.first_item || d.order_number || d.number)}</span>`,
    accDateShort(d.due_at),
    accPill(d.status),
    accMoney(d.amount),
  ]);

  return `
    <div class="fade-in acc-page">
      <div style="margin-bottom:12px">${accBackLink(State.accContactType === 'vendor' ? 'Vendors' : 'Customers', "accNav('" + (State.accContactType === 'vendor' ? 'acc-vendors' : 'acc-customers') + "')")}</div>
      ${accHeader(c.name, `${isCustomer ? 'Customer' : 'Vendor'}${c.category ? ' · ' + c.category : ''}${c.crm_lead_id ? ' · linked to CRM lead #' + c.crm_lead_id : ''}`,
        `${isCustomer && accHas('acc.invoices') ? `<button class="btn-primary" onclick="accDocEditor('invoice')">${I.plus} New invoice</button>` : ''}
         ${!isCustomer && accHas('acc.bills') ? `<button class="btn-primary" onclick="accDocEditor('bill')">${I.plus} New bill</button>` : ''}
         <button class="btn-secondary" onclick="accContactModal('${c.type}',${c.id})">${I.pencil} Edit</button>
         <button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteContact(${c.id})">${I.trash}</button>`)}

      <div class="acc-stats">
        ${accStat('Open balance', accMoney(stats.outstanding), isCustomer ? 'Owed to you' : 'You owe', Number(stats.outstanding) > 0 ? 'acc-neg' : 'acc-pos')}
        ${accStat(isCustomer ? 'Total billed' : 'Total spent', accMoney(stats.total), 'All time, incl. direct payments')}
        ${accStat('Total paid', accMoney(stats.paid), 'All time', 'acc-pos')}
      </div>

      <div class="acc-split">
        <div class="acc-card acc-card-flush">
          <div class="acc-card-head"><span class="acc-card-title">${isCustomer ? 'Invoice history' : 'Bill history'}</span></div>
          ${accTable(
            [{ label: 'Number', width: '110px' }, { label: 'Description', width: 'minmax(0,2fr)' },
             { label: 'Due', width: '90px' }, { label: 'Status', width: '105px' }, { label: 'Amount', width: '115px', align: 'right' }],
            docRows, isCustomer ? 'No invoices found.' : 'No bills found.',
            (i) => `onclick="accGoDoc(${(documents[i] || {}).id})"`)}
        </div>
        <div>
          <div class="acc-card">
            <div class="acc-card-title" style="margin-bottom:10px">Contact details</div>
            ${['email', 'phone', 'address', 'city', 'state', 'zip_code', 'country', 'website', 'tax_number']
              .filter(k => c[k]).map(k => `<div class="acc-kv"><span class="acc-kv-label">${esc(k.replace(/_/g, ' '))}</span><span class="acc-kv-value">${esc(c[k])}</span></div>`).join('')
              || '<div class="acc-sub">No details recorded.</div>'}
          </div>
          ${(people || []).length ? `<div class="acc-card">
            <div class="acc-card-title" style="margin-bottom:10px">People</div>
            ${people.map(p => `<div class="acc-kv"><span><span class="acc-strong">${esc(p.name)}</span>${p.position ? `<span class="acc-sub"> · ${esc(p.position)}</span>` : ''}</span><span class="acc-sub">${esc(p.email || '')}</span></div>`).join('')}
          </div>` : ''}
          ${(transactions || []).length ? `<div class="acc-card">
            <div class="acc-card-title" style="margin-bottom:10px">Recent payments</div>
            ${transactions.slice(0, 8).map(t => `<div class="acc-kv">
              <span><span class="acc-sub">${accDate(t.paid_at)}</span>${t.document_number ? ` · <span class="acc-mono">${esc(t.document_number)}</span>` : ''}</span>
              <span class="acc-kv-value ${t.type === 'income' ? 'acc-pos' : ''}">${accMoney(t.amount)}</span></div>`).join('')}
          </div>` : ''}
        </div>
      </div>
    </div>`;
}

function accContactModal(type, id) {
  const c = (id && AccState.contact && Number(AccState.contact.contact.id) === Number(id)) ? AccState.contact.contact : null;
  const isCustomer = type === 'customer';
  accOpenForm({
    title: (id ? 'Edit ' : 'New ') + (isCustomer ? 'customer' : 'vendor'),
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Name', `<input class="form-input" id="acc-c-name" value="${esc(c ? c.name : '')}" placeholder="${isCustomer ? 'Company or person' : 'Supplier name'}">`)}
        ${accField(isCustomer ? 'Primary contact' : 'Category', isCustomer
          ? `<input class="form-input" id="acc-c-person" value="" placeholder="Optional">`
          : `<input class="form-input" id="acc-c-category" value="${esc(c ? (c.category || '') : '')}" placeholder="e.g. Reagents">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Email', `<input class="form-input" type="email" id="acc-c-email" value="${esc(c ? (c.email || '') : '')}">`)}
        ${accField('Phone', `<input class="form-input" id="acc-c-phone" value="${esc(c ? (c.phone || '') : '')}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Address', `<input class="form-input" id="acc-c-address" value="${esc(c ? (c.address || '') : '')}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('City', `<input class="form-input" id="acc-c-city" value="${esc(c ? (c.city || '') : '')}">`)}
        ${accField('State', `<input class="form-input" id="acc-c-state" value="${esc(c ? (c.state || '') : '')}">`)}
        ${accField('ZIP', `<input class="form-input" id="acc-c-zip" value="${esc(c ? (c.zip_code || '') : '')}">`)}
        ${accField('Country', `<input class="form-input" id="acc-c-country" value="${esc(c ? (c.country || '') : '')}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Website', `<input class="form-input" id="acc-c-website" value="${esc(c ? (c.website || '') : '')}">`)}
        ${accField('Tax number', `<input class="form-input" id="acc-c-tax" value="${esc(c ? (c.tax_number || '') : '')}">`)}
      </div>`,
    footer: `<button class="btn-secondary" onclick="accCloseForm()">Cancel</button>
             <button class="btn-primary" onclick="accSaveContact('${type}',${id || 'null'})">${id ? 'Save changes' : 'Create'}</button>`,
  });
}

async function accSaveContact(type, id) {
  const name = accVal('acc-c-name');
  if (!name) { toast('Name is required', 'error'); return; }
  const payload = {
    type, name,
    email: accVal('acc-c-email'), phone: accVal('acc-c-phone'), address: accVal('acc-c-address'),
    city: accVal('acc-c-city'), state: accVal('acc-c-state'), zip_code: accVal('acc-c-zip'),
    country: accVal('acc-c-country'), website: accVal('acc-c-website'), tax_number: accVal('acc-c-tax'),
    category: accVal('acc-c-category'), contact_name: accVal('acc-c-person'),
  };
  try {
    if (id) await API.accUpdateContact(id, payload);
    else await API.accCreateContact(payload);
    accCloseForm();
    AccState.contacts = null;
    AccState.contact = null;
    await accRefreshOptions();
    toast(id ? 'Saved' : 'Created', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteContact(id) {
  const ok = await Modal.confirm({ title: 'Delete contact', message: 'This removes the contact. Contacts with documents cannot be deleted.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.accDeleteContact(id);
    AccState.contacts = null;
    AccState.contact = null;
    await accRefreshOptions();
    toast('Contact deleted', 'success');
    accNav(State.accContactType === 'vendor' ? 'acc-vendors' : 'acc-customers');
  } catch (e) { toast(e.message, 'error'); }
}

/* ===================== CRM → Accounting import ===================== */

function accCrmImportModal() {
  accOpenForm({
    title: 'Import a customer from the CRM',
    body: `
      <p class="acc-sub" style="margin-bottom:12px">Search your CRM leads and turn one into an accounting customer. The link is kept, so the lead and the customer stay connected.</p>
      <input class="form-input" id="acc-crm-q" placeholder="Search company, contact or email…" oninput="accCrmSearchDebounced()">
      <div id="acc-crm-results" style="margin-top:12px"></div>`,
    footer: `<button class="btn-secondary" onclick="accCloseForm()">Close</button>`,
  });
}

let _accCrmTimer = null;
function accCrmSearchDebounced() {
  clearTimeout(_accCrmTimer);
  _accCrmTimer = setTimeout(accCrmSearch, 280);
}

async function accCrmSearch() {
  const q = accVal('acc-crm-q');
  const box = document.getElementById('acc-crm-results');
  if (!box) return;
  if (q.length < 2) { box.innerHTML = '<div class="acc-sub">Type at least two characters.</div>'; return; }
  box.innerHTML = '<div class="acc-sub">Searching…</div>';
  try {
    const res = await API.accCrmLeadSearch(q);
    if (!res.leads.length) { box.innerHTML = '<div class="acc-sub">No CRM leads matched.</div>'; return; }
    box.innerHTML = res.leads.map(l => `
      <div class="acc-kv">
        <span style="min-width:0">
          <span class="acc-strong">${esc(l.company_name || l.contact_name || 'Lead #' + l.id)}</span>
          <span class="acc-sub"> ${esc(l.email || '')}</span>
        </span>
        ${l.already_linked
          ? '<span class="acc-chip">Already imported</span>'
          : `<button class="btn-primary" style="padding:4px 10px;font-size:12.5px" onclick="accImportLead(${l.id})">Import</button>`}
      </div>`).join('');
  } catch (e) { box.innerHTML = `<div class="acc-sub acc-neg">${esc(e.message)}</div>`; }
}

async function accImportLead(leadId) {
  try {
    const res = await API.accImportCrmLead(leadId);
    accCloseForm();
    AccState.contacts = null;
    await accRefreshOptions();
    toast(res.existing ? 'That lead is already an accounting customer' : 'Customer imported from CRM', 'success');
    accGoContact(res.id);
  } catch (e) { toast(e.message, 'error'); }
}
