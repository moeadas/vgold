// ============================================================================
// VGold — Accounting & Finance (native), part 2
// Banking, ledger, catalog, recurring, reports, accounting settings, plus the
// two hooks the main Settings screen calls into (module chips + danger zone).
// ============================================================================

/* ===================== Banking ===================== */

async function renderAccBanking() {
  await accBoot();
  if (!accHas('acc.banking')) return accDenied('banking');
  if (!AccState.banking) AccState.banking = await API.accBanking();
  const b = AccState.banking;
  const tab = AccState.bankingTab;

  const tabs = ['accounts', 'transactions', 'transfers', 'reconciliations'];
  const tabBar = `<div class="acc-tabs">${tabs.map(t => `
    <button class="acc-tab ${tab === t ? 'active' : ''}" onclick="accBankingTab('${t}')">${esc(t.charAt(0).toUpperCase() + t.slice(1))}</button>`).join('')}</div>`;

  let body = '';
  if (tab === 'accounts') body = accBankingAccounts(b);
  else if (tab === 'transactions') body = await accBankingTransactions();
  else if (tab === 'transfers') body = accBankingTransfers(b);
  else body = accBankingReconciliations(b);

  const action = {
    accounts: `<button class="btn-primary" onclick="accAccountModal()">${I.plus} New account</button>`,
    transactions: `<button class="btn-primary" onclick="accTransactionModal()">${I.plus} New transaction</button>`,
    transfers: `<button class="btn-primary" onclick="accTransferModal()">${I.plus} New transfer</button>`,
    reconciliations: `<button class="btn-primary" onclick="accReconciliationModal()">${I.plus} Start reconciliation</button>`,
  }[tab];

  return `
    <div class="fade-in acc-page">
      ${accHeader('Banking', 'Accounts, money movements and statement reconciliation.', action)}
      <div class="acc-stats">
        ${accStat('Total balance', accMoney(b.total_balance), 'All enabled accounts')}
        ${(b.accounts || []).filter(a => Number(a.enabled) === 1).slice(0, 4).map(a =>
          accStat(a.name, accMoney(a.balance), (a.bank_name || '') + (a.number ? ' ····' + String(a.number).slice(-4) : ''),
            Number(a.balance) < 0 ? 'acc-neg' : '')).join('')}
      </div>
      ${tabBar}
      ${body}
    </div>`;
}

function accBankingTab(tab) {
  AccState.bankingTab = tab;
  if (tab === 'transactions') AccState.transactions = null;
  render();
}

function accBankingAccounts(b) {
  const rows = (b.accounts || []).map(a => [
    `<div><div class="acc-strong">${esc(a.name)}</div><div class="acc-sub">${esc(a.bank_name || '')}${a.number ? ' ····' + esc(String(a.number).slice(-4)) : ''}</div></div>`,
    `<span class="acc-chip">${esc(String(a.type).replace('_', ' '))}</span>`,
    accMoney(a.opening_balance),
    `<span class="${Number(a.balance) < 0 ? 'acc-neg' : ''}">${accMoney(a.balance)}</span>`,
    Number(a.enabled) === 1 ? accPill('active') : accPill('inactive'),
  ]);
  return `<div class="acc-card acc-card-flush">
    ${accTable(
      [{ label: 'Account', width: 'minmax(0,2fr)' }, { label: 'Type', width: '130px' },
       { label: 'Opening', width: '130px', align: 'right' }, { label: 'Balance', width: '140px', align: 'right' },
       { label: 'Status', width: '100px' }],
      rows, 'No accounts yet.', (i) => `onclick="accGoAccount(${(b.accounts[i] || {}).id})"`)}
  </div>`;
}

async function accBankingTransactions() {
  const f = AccState.txFilter;
  if (!AccState.transactions) AccState.transactions = await API.accTransactions(f);
  const data = AccState.transactions;
  const accounts = accOpts().accounts || [];

  const rows = (data.transactions || []).map(t => [
    accDateShort(t.paid_at),
    `<div style="min-width:0"><div class="acc-strong acc-truncate">${esc(t.description || '—')}</div>
      <div class="acc-sub acc-truncate">${esc(t.contact_name || '')}${t.agent_name ? ' · ' + esc(t.agent_name) : ''}</div></div>`,
    Number(t.is_transfer)
      ? '<span class="acc-chip">Transfer</span>'
      : (t.document_number
          ? `<span class="acc-chip acc-chip-match">${esc(t.document_number)}</span>${Number(t.adjustment_count) ? `<span class="acc-sub"> +${Number(t.adjustment_count)} adj</span>` : ''}`
          : `<button class="acc-link-btn" onclick="event.stopPropagation();accTransactionModal(${t.id})">Match…</button>`),
    t.category_name ? `<span class="acc-chip">${esc(t.category_name)}</span>` : '<span class="acc-dim">—</span>',
    esc(t.account_name || '—'),
    `<span class="${t.type === 'income' ? 'acc-pos' : 'acc-neg'}">${t.type === 'income' ? '+' : '−'}${accMoney(t.amount).replace('-', '')}</span>`,
    Number(t.is_transfer) ? '<span class="acc-dim">—</span>' :
      `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="event.stopPropagation();accTransactionModal(${t.id})">Edit</button>`,
  ]);

  return `
    <div class="acc-toolbar">
      <div class="acc-tabs" style="margin:0">
        ${[['all', 'All'], ['income', 'Income'], ['expense', 'Expense']].map(t =>
          `<button class="acc-tab ${f.tx_type === t[0] ? 'active' : ''}" onclick="accTxType('${t[0]}')">${t[1]}</button>`).join('')}
      </div>
      <div class="acc-tabs" style="margin:0">
        ${[['all', 'Any match'], ['unmatched', 'Unmatched'], ['matched', 'Matched']].map(t =>
          `<button class="acc-tab ${(f.match || 'all') === t[0] ? 'active' : ''}" onclick="accTxMatch('${t[0]}')">${t[1]}${
            t[0] === 'unmatched' && Number(data.unmatched_count) ? `<span class="acc-tab-count">${Number(data.unmatched_count)}</span>` : ''}</button>`).join('')}
      </div>
      <div style="min-width:170px">${accSelect('acc-tx-account', accounts.map(a => ({ value: a.id, label: a.name })), f.account_id, 'All accounts', 'onchange="accTxAccount()"')}</div>
      <div class="acc-search"><input class="form-input" id="acc-tx-search" placeholder="Search description or reference…" value="${esc(f.search)}" onkeydown="if(event.key==='Enter')accTxSearch()"></div>
      <button class="btn-secondary" onclick="accTxSearch()">Search</button>
    </div>
    <div class="acc-card acc-card-flush">
      ${accTable(
        [{ label: 'Date', width: '95px' }, { label: 'Description', width: 'minmax(0,1.6fr)' },
         { label: 'Applied to', width: '160px' },
         { label: 'Category', width: '140px' }, { label: 'Account', width: '130px' },
         { label: 'Amount', width: '130px', align: 'right' }, { label: '', width: '80px', align: 'right' }],
        rows, 'No transactions found.')}
      ${accPager(data.meta, 'accTxPage')}
    </div>`;
}

function accTxType(t) { AccState.txFilter.tx_type = t; AccState.txFilter.page = 1; AccState.transactions = null; render(); }
function accTxMatch(m) { AccState.txFilter.match = m; AccState.txFilter.page = 1; AccState.transactions = null; render(); }
function accTxAccount() { AccState.txFilter.account_id = accVal('acc-tx-account'); AccState.txFilter.page = 1; AccState.transactions = null; render(); }
function accTxSearch() { AccState.txFilter.search = accVal('acc-tx-search'); AccState.txFilter.page = 1; AccState.transactions = null; render(); }
function accTxPage(p) { AccState.txFilter.page = p; AccState.transactions = null; render(); }

function accBankingTransfers(b) {
  const rows = (b.transfers || []).map(t => [
    accDate(t.transferred_at),
    esc(t.from_name || '—'),
    esc(t.to_name || '—'),
    `<span class="acc-truncate">${esc(t.description || '—')}</span>`,
    accMoney(t.amount),
    `<button class="btn-secondary" style="padding:3px 9px;font-size:12px;color:var(--barn)" onclick="accDeleteTransfer(${t.id})">Delete</button>`,
  ]);
  return `<div class="acc-card acc-card-flush">
    ${accTable(
      [{ label: 'Date', width: '120px' }, { label: 'From', width: 'minmax(0,1fr)' }, { label: 'To', width: 'minmax(0,1fr)' },
       { label: 'Description', width: 'minmax(0,1.4fr)' }, { label: 'Amount', width: '130px', align: 'right' }, { label: '', width: '90px', align: 'right' }],
      rows, 'No transfers found.')}
  </div>`;
}

function accBankingReconciliations(b) {
  const rows = (b.reconciliations || []).map(r => [
    esc(r.account_name || '—'),
    accDate(r.started_at, { month: 'short', year: 'numeric' }) + ' – ' + (r.ended_at ? accDate(r.ended_at, { month: 'short', year: 'numeric' }) : '…'),
    accMoney(r.closing_balance),
    Number(r.reconciled) === 1 ? accPill('completed') : accPill('active'),
    `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accGoReconciliation(${r.id})">Open</button>`,
  ]);
  return `<div class="acc-card acc-card-flush">
    ${accTable(
      [{ label: 'Account', width: 'minmax(0,1.5fr)' }, { label: 'Period', width: 'minmax(0,1.2fr)' },
       { label: 'Closing', width: '140px', align: 'right' }, { label: 'Status', width: '120px' }, { label: '', width: '90px', align: 'right' }],
      rows, 'No reconciliations found.')}
  </div>`;
}

/* ---------- Account detail ---------- */

async function renderAccAccount(id) {
  await accBoot();
  if (!accHas('acc.banking')) return accDenied('banking');
  if (!AccState.account || Number(AccState.account.account.id) !== Number(id)) {
    AccState.account = await API.accAccount(id);
  }
  const { account: a, transactions } = AccState.account;

  const rows = (transactions || []).map(t => [
    accDateShort(t.paid_at),
    `<div style="min-width:0"><div class="acc-strong acc-truncate">${esc(t.description || '—')}</div>
      <div class="acc-sub acc-truncate">${esc(t.contact_name || '')}${t.document_number ? ' · ' + esc(t.document_number) : ''}</div></div>`,
    t.category_name ? `<span class="acc-chip">${esc(t.category_name)}</span>` : (Number(t.is_transfer) ? '<span class="acc-chip">Transfer</span>' : '<span class="acc-dim">—</span>'),
    Number(t.reconciled) === 1 ? '<span class="acc-pos">✓</span>' : '<span class="acc-dim">—</span>',
    `<span class="${t.type === 'income' ? 'acc-pos' : 'acc-neg'}">${t.type === 'income' ? '+' : '−'}${accMoney(t.amount).replace('-', '')}</span>`,
  ]);

  return `
    <div class="fade-in acc-page">
      <div style="margin-bottom:12px">${accBackLink('Banking', "accNav('acc-banking')")}</div>
      ${accHeader(a.name, `${a.bank_name || ''}${a.number ? ' · ····' + String(a.number).slice(-4) : ''} · ${String(a.type).replace('_', ' ')}`,
        `<button class="btn-secondary" onclick="accTransferModal(${a.id})">Transfer</button>
         <button class="btn-secondary" onclick="accReconciliationModal(${a.id})">Reconcile</button>
         <button class="btn-secondary" onclick="accAccountModal(${a.id})">${I.pencil} Edit</button>`)}
      <div class="acc-stats">
        ${accStat('Current balance', accMoney(a.balance), a.currency_code, Number(a.balance) < 0 ? 'acc-neg' : '')}
        ${accStat('Opening balance', accMoney(a.opening_balance), 'When the account was added')}
        ${accStat('Transactions', String((transactions || []).length), 'Most recent 200')}
      </div>
      <div class="acc-card acc-card-flush">
        ${accTable(
          [{ label: 'Date', width: '95px' }, { label: 'Description', width: 'minmax(0,2fr)' },
           { label: 'Category', width: '150px' }, { label: 'Cleared', width: '90px' },
           { label: 'Amount', width: '130px', align: 'right' }],
          rows, 'No transactions on this account.')}
      </div>
    </div>`;
}

function accAccountModal(id) {
  const a = (id && AccState.banking) ? (AccState.banking.accounts || []).find(x => Number(x.id) === Number(id))
    : (id && AccState.account ? AccState.account.account : null);
  const acct = a || (AccState.account && Number(AccState.account.account.id) === Number(id) ? AccState.account.account : null);
  Modal.open({
    title: id ? 'Edit account' : 'New account',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Account name', `<input class="form-input" id="acc-a-name" value="${esc(acct ? acct.name : '')}" placeholder="Operating">`)}
        ${accField('Bank', `<input class="form-input" id="acc-a-bank" value="${esc(acct ? (acct.bank_name || '') : '')}" placeholder="Mercury">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Account number', `<input class="form-input" id="acc-a-number" value="${esc(acct ? (acct.number || '') : '')}">`)}
        ${accField('Type', accSelect('acc-a-type', [
          { value: 'bank', label: 'Bank' }, { value: 'credit_card', label: 'Credit card' }, { value: 'cash', label: 'Cash' },
        ], acct ? acct.type : 'bank'))}
        ${accField('Currency', `<input class="form-input" id="acc-a-currency" value="${esc(acct ? acct.currency_code : 'USD')}" maxlength="3">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Opening balance', `<input class="form-input" type="number" step="0.01" id="acc-a-opening" value="${acct ? Number(acct.opening_balance) : 0}" style="text-align:right">`)}
        ${accField('Enabled', accSelect('acc-a-enabled', [{ value: '1', label: 'Enabled' }, { value: '0', label: 'Disabled' }], acct ? String(acct.enabled) : '1'))}
      </div>
      ${id ? '<p class="acc-sub" style="margin-top:10px">The current balance is always recalculated from the opening balance plus this account\'s transactions.</p>' : ''}`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteAccount(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveAccount(${id || 'null'})">${id ? 'Save changes' : 'Create account'}</button>`,
  });
}

async function accSaveAccount(id) {
  const name = accVal('acc-a-name');
  if (!name) { toast('Account name is required', 'error'); return; }
  const payload = {
    name, bank_name: accVal('acc-a-bank'), number: accVal('acc-a-number'),
    type: accVal('acc-a-type'), currency_code: accVal('acc-a-currency') || 'USD',
    opening_balance: accNumVal('acc-a-opening'), enabled: accVal('acc-a-enabled') === '1',
  };
  try {
    if (id) await API.accUpdateAccount(id, payload); else await API.accCreateAccount(payload);
    Modal.close();
    AccState.banking = null; AccState.account = null; AccState.dashboard = null;
    await accRefreshOptions();
    toast(id ? 'Account saved' : 'Account created', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteAccount(id) {
  const ok = await Modal.confirm({ title: 'Delete account', message: 'Accounts with transactions cannot be deleted — disable them instead.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.accDeleteAccount(id);
    Modal.close();
    AccState.banking = null; AccState.account = null;
    await accRefreshOptions();
    toast('Account deleted', 'success');
    accNav('acc-banking');
  } catch (e) { toast(e.message, 'error'); }
}

function accTransactionModal(id) {
  const t = id && AccState.transactions ? (AccState.transactions.transactions || []).find(x => Number(x.id) === Number(id)) : null;
  const o = accOpts();
  const contacts = (o.customers || []).concat(o.vendors || []);
  Modal.open({
    title: id ? 'Edit transaction' : 'New transaction',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Type', accSelect('acc-t-type', [{ value: 'income', label: 'Income' }, { value: 'expense', label: 'Expense' }], t ? t.type : 'income', null, 'onchange="accTxCatFilter()"'))}
        ${accField('Amount', `<input class="form-input" type="number" step="0.01" min="0" id="acc-t-amount" value="${t ? Number(t.amount) : ''}" style="text-align:right" placeholder="0.00">`)}
        ${accField('Date', `<input class="form-input" type="date" id="acc-t-date" value="${esc(t ? t.paid_at : accToday())}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Account', accSelect('acc-t-account', (o.accounts || []).map(a => ({ value: a.id, label: a.name })), t ? t.account_id : '', 'Select account…'))}
        ${accField('Category', `<span id="acc-t-cat-wrap">${accSelect('acc-t-category', (o.categories || []).map(c => ({ value: c.id, label: c.name + ' (' + c.type + ')' })), t ? t.category_id : '', 'No category')}</span>`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Contact', accSelect('acc-t-contact', contacts.map(c => ({ value: c.id, label: c.name })), t ? t.contact_id : '', 'No contact', 'onchange="accMatchReload()"'))}
        ${accField('Method', accSelect('acc-t-method', [
          { value: 'bank_transfer', label: 'Bank transfer' }, { value: 'credit_card', label: 'Credit card' },
          { value: 'cash', label: 'Cash' }, { value: 'check', label: 'Check' }, { value: 'other', label: 'Other' },
        ], t ? t.payment_method : 'bank_transfer'))}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Sales agent', accSelect('acc-t-agent', (o.agents || []).map(a => ({ value: a.id, label: a.name })), t ? t.user_id : '', 'Unassigned'))}
        ${accField('Description', `<input class="form-input" id="acc-t-desc" value="${esc(t ? (t.description || '') : '')}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Reference', `<input class="form-input" id="acc-t-ref" value="${esc(t ? (t.reference || '') : '')}">`)}
      </div>

      <div class="acc-match" id="acc-match-block">
        <div class="acc-adj-head">
          <span class="acc-card-title">Apply to an invoice or bill</span>
          <span class="acc-sub" id="acc-match-note"></span>
        </div>
        <div id="acc-match-select">${accMatchPlaceholder()}</div>
      </div>
      ${accAdjBlock('invoice', 0, 'acc-t-amount')}`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteTransaction(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveTransaction(${id || 'null'})">${id ? 'Save changes' : 'Add transaction'}</button>`,
  });
  accMatchReload(t ? t.document_id : null);
  ((t && t.adjustments) || []).forEach(a => accAdjAdd({ kind: a.kind, amount: Math.abs(Number(a.amount)), description: a.description }));
  accAdjRecalc();
}

function accTxCatFilter() { accMatchReload(); }

/* ---------- Matching a bank transaction to a document ---------- */

function accMatchPlaceholder() {
  return `${accSelect('acc-t-document', [], '', 'Not applied to a document', 'onchange="accMatchPicked()"')}`;
}

/**
 * Reload the candidate documents for the type/contact currently selected.
 * Income offers unpaid invoices, expense offers unpaid bills.
 */
async function accMatchReload(preselect) {
  const host = document.getElementById('acc-match-select');
  if (!host) return;
  const type = accVal('acc-t-type') || 'income';
  const contactId = accVal('acc-t-contact');
  const keep = preselect !== undefined ? preselect : accVal('acc-t-document');

  try {
    const res = await API.accMatchable({ type, contact_id: contactId || '' });
    AccState.matchable = res.documents || [];
  } catch (e) {
    AccState.matchable = [];
  }

  const opts = AccState.matchable.map(d => ({
    value: d.id,
    label: d.number + ' · ' + (d.contact_name || '—') + ' · ' + accMoney(d.balance) + ' outstanding',
  }));
  host.innerHTML = accSelect('acc-t-document', opts, keep || '', 'Not applied to a document', 'onchange="accMatchPicked()"');
  accMatchPicked();
}

/** Re-point the adjustment preview at the picked document's balance. */
function accMatchPicked() {
  const box = document.getElementById('acc-adj-summary');
  const note = document.getElementById('acc-match-note');
  const id = accVal('acc-t-document');
  const doc = (AccState.matchable || []).find(d => Number(d.id) === Number(id));

  if (box) {
    box.setAttribute('data-doc-type', doc ? doc.type : (accVal('acc-t-type') === 'expense' ? 'bill' : 'invoice'));
    box.setAttribute('data-balance', doc ? Number(doc.balance).toFixed(2) : '0.00');
  }
  if (note) {
    note.textContent = doc
      ? `${doc.number} · ${accMoney(doc.balance)} outstanding · due ${accDate(doc.due_at)}`
      : 'Optional — leave empty for a standalone transaction.';
  }
  const adj = document.querySelector('.acc-adj');
  if (adj) adj.style.display = doc ? '' : 'none';
  accAdjRecalc();
}

async function accSaveTransaction(id) {
  const amount = accNumVal('acc-t-amount');
  if (!amount || amount <= 0) { toast('Enter an amount', 'error'); return; }
  if (!accVal('acc-t-account')) { toast('Select an account', 'error'); return; }
  const docId = accVal('acc-t-document');
  const payload = {
    type: accVal('acc-t-type'), amount, paid_at: accVal('acc-t-date'),
    account_id: Number(accVal('acc-t-account')),
    category_id: accVal('acc-t-category') ? Number(accVal('acc-t-category')) : null,
    contact_id: accVal('acc-t-contact') ? Number(accVal('acc-t-contact')) : null,
    user_id: accVal('acc-t-agent') ? Number(accVal('acc-t-agent')) : null,
    document_id: docId ? Number(docId) : null,
    adjustments: docId ? accAdjCollect() : [],
    description: accVal('acc-t-desc'), payment_method: accVal('acc-t-method'), reference: accVal('acc-t-ref'),
  };
  try {
    if (id) await API.accUpdateTransaction(id, payload); else await API.accCreateTransaction(payload);
    Modal.close();
    // Matching moves a document's balance, so the document caches go too.
    AccState.transactions = null; AccState.banking = null; AccState.dashboard = null; AccState.account = null;
    AccState.doc = null; AccState.docs = { invoice: null, bill: null }; AccState.reports = null;
    toast(id ? 'Transaction saved' : 'Transaction added', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteTransaction(id) {
  const ok = await Modal.confirm({ title: 'Delete transaction', message: 'This removes the transaction and recalculates the account balance.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.accDeleteTransaction(id);
    Modal.close();
    // Matching moves a document's balance, so the document caches go too.
    AccState.transactions = null; AccState.banking = null; AccState.dashboard = null; AccState.account = null;
    AccState.doc = null; AccState.docs = { invoice: null, bill: null }; AccState.reports = null;
    toast('Transaction deleted', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function accTransferModal(fromId) {
  const accounts = accOpts().accounts || [];
  Modal.open({
    title: 'New transfer',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('From account', accSelect('acc-tr-from', accounts.map(a => ({ value: a.id, label: a.name + ' — ' + accMoney(a.balance) })), fromId || '', 'Select source…'))}
        ${accField('To account', accSelect('acc-tr-to', accounts.map(a => ({ value: a.id, label: a.name + ' — ' + accMoney(a.balance) })), '', 'Select destination…'))}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Amount', `<input class="form-input" type="number" step="0.01" min="0" id="acc-tr-amount" style="text-align:right" placeholder="0.00">`)}
        ${accField('Date', `<input class="form-input" type="date" id="acc-tr-date" value="${accToday()}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Description', `<input class="form-input" id="acc-tr-desc" placeholder="e.g. Monthly reserve transfer">`)}
      </div>
      <p class="acc-sub" style="margin-top:10px">Both sides are flagged as internal transfers, so they never appear in Profit &amp; Loss or Cash Flow.</p>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             <button class="btn-primary" onclick="accSaveTransfer()">Create transfer</button>`,
  });
}

async function accSaveTransfer() {
  const from = accVal('acc-tr-from'), to = accVal('acc-tr-to');
  if (!from || !to) { toast('Choose both accounts', 'error'); return; }
  if (from === to) { toast('Choose two different accounts', 'error'); return; }
  const amount = accNumVal('acc-tr-amount');
  if (!amount || amount <= 0) { toast('Enter an amount', 'error'); return; }
  try {
    await API.accCreateTransfer({
      from_account_id: Number(from), to_account_id: Number(to), amount,
      transferred_at: accVal('acc-tr-date'), description: accVal('acc-tr-desc'),
    });
    Modal.close();
    AccState.banking = null; AccState.transactions = null; AccState.account = null; AccState.dashboard = null;
    await accRefreshOptions();
    toast('Transfer created', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteTransfer(id) {
  const ok = await Modal.confirm({ title: 'Delete transfer', message: 'Both sides of the transfer are removed and balances recalculated.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.accDeleteTransfer(id);
    AccState.banking = null; AccState.transactions = null; AccState.dashboard = null;
    await accRefreshOptions();
    toast('Transfer deleted', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function accReconciliationModal(accountId) {
  const accounts = accOpts().accounts || [];
  const first = new Date(); first.setDate(1);
  const last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
  Modal.open({
    title: 'Start reconciliation',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Account', accSelect('acc-r-account', accounts.map(a => ({ value: a.id, label: a.name })), accountId || '', 'Select account…'))}
        ${accField('Closing balance (from statement)', `<input class="form-input" type="number" step="0.01" id="acc-r-closing" style="text-align:right" placeholder="0.00">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Period start', `<input class="form-input" type="date" id="acc-r-start" value="${first.toISOString().slice(0, 10)}">`)}
        ${accField('Period end', `<input class="form-input" type="date" id="acc-r-end" value="${last.toISOString().slice(0, 10)}">`)}
      </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             <button class="btn-primary" onclick="accSaveReconciliation()">Start</button>`,
  });
}

async function accSaveReconciliation() {
  if (!accVal('acc-r-account')) { toast('Select an account', 'error'); return; }
  try {
    const res = await API.accCreateReconciliation({
      account_id: Number(accVal('acc-r-account')),
      started_at: accVal('acc-r-start'), ended_at: accVal('acc-r-end'),
      closing_balance: accNumVal('acc-r-closing'),
    });
    Modal.close();
    AccState.banking = null;
    toast('Reconciliation started', 'success');
    accGoReconciliation(res.id);
  } catch (e) { toast(e.message, 'error'); }
}

async function renderAccReconciliation(id) {
  await accBoot();
  if (!accHas('acc.banking')) return accDenied('banking');
  if (!AccState.reconciliation || Number(AccState.reconciliation.reconciliation.id) !== Number(id)) {
    AccState.reconciliation = await API.accReconciliation(id);
  }
  const { reconciliation: r, unreconciled, cleared_total } = AccState.reconciliation;
  const difference = Number(r.closing_balance) - Number(cleared_total);

  const rows = (unreconciled || []).map(t => [
    `<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" class="acc-rec-check" value="${t.id}"></label>`,
    accDateShort(t.paid_at),
    `<span class="acc-truncate">${esc(t.description || '—')}</span>`,
    accPill(t.type),
    `<span class="${t.type === 'income' ? 'acc-pos' : 'acc-neg'}">${t.type === 'income' ? '+' : '−'}${accMoney(t.amount).replace('-', '')}</span>`,
  ]);

  return `
    <div class="fade-in acc-page">
      <div style="margin-bottom:12px">${accBackLink('Banking', "accNav('acc-banking')")}</div>
      ${accHeader('Reconciliation · ' + (r.account_name || ''), accDate(r.started_at) + ' – ' + (r.ended_at ? accDate(r.ended_at) : 'open'),
        Number(r.reconciled) === 1 ? '' : `<button class="btn-primary" onclick="accCloseReconciliation(${r.id})">Close reconciliation</button>`)}
      <div class="acc-stats">
        ${accStat('Statement balance', accMoney(r.closing_balance), 'From your bank')}
        ${accStat('Cleared in VGold', accMoney(cleared_total), 'Marked reconciled', 'acc-pos')}
        ${accStat('Difference', accMoney(difference), Math.abs(difference) < 0.01 ? 'Balanced' : 'Needs review', Math.abs(difference) < 0.01 ? 'acc-pos' : 'acc-warn')}
        ${accStat('Status', Number(r.reconciled) === 1 ? 'Completed' : 'In progress', '')}
      </div>
      <div class="acc-card acc-card-flush">
        <div class="acc-card-head">
          <span class="acc-card-title">Unreconciled transactions</span>
          ${rows.length ? `<button class="btn-secondary" style="padding:5px 11px;font-size:12.5px" onclick="accRecSelectAll()">Select all</button>
            <button class="btn-primary" style="padding:5px 11px;font-size:12.5px" onclick="accMarkReconciled(${r.id})">Mark selected as cleared</button>` : ''}
        </div>
        ${accTable(
          [{ label: '', width: '44px' }, { label: 'Date', width: '100px' }, { label: 'Description', width: 'minmax(0,2fr)' },
           { label: 'Type', width: '110px' }, { label: 'Amount', width: '130px', align: 'right' }],
          rows, 'No unreconciled transactions remaining.')}
      </div>
      ${accAttachmentsCard('reconciliation', r.id, AccState.reconciliation.attachments, 'Bank statements')}
    </div>`;
}

function accRecSelectAll() {
  const boxes = document.querySelectorAll('.acc-rec-check');
  const allChecked = Array.from(boxes).every(b => b.checked);
  boxes.forEach(b => { b.checked = !allChecked; });
}

async function accMarkReconciled(id) {
  const ids = Array.from(document.querySelectorAll('.acc-rec-check:checked')).map(b => Number(b.value));
  if (!ids.length) { toast('Select at least one transaction', 'error'); return; }
  try {
    await API.accReconciliationMark(id, ids);
    AccState.reconciliation = null;
    toast(ids.length + ' transaction(s) cleared', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accCloseReconciliation(id) {
  const ok = await Modal.confirm({ title: 'Close reconciliation', message: 'This marks the period as reconciled.', confirmText: 'Close period' });
  if (!ok) return;
  try {
    await API.accReconciliationClose(id, { ended_at: accToday() });
    AccState.reconciliation = null; AccState.banking = null;
    toast('Reconciliation closed', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ===================== Ledger — journal & chart of accounts ===================== */

async function renderAccLedger() {
  await accBoot();
  if (!accHas('acc.accounting')) return accDenied('the journal and chart of accounts');
  const tab = AccState.ledgerTab;
  if (tab === 'journal' && !AccState.journal) AccState.journal = await API.accJournal({ page: 1 });
  if (tab === 'coa' && !AccState.coa) AccState.coa = await API.accCoa();

  const tabBar = `<div class="acc-tabs">
      <button class="acc-tab ${tab === 'journal' ? 'active' : ''}" onclick="accLedgerTab('journal')">Journal</button>
      <button class="acc-tab ${tab === 'coa' ? 'active' : ''}" onclick="accLedgerTab('coa')">Chart of accounts</button>
    </div>`;

  const action = tab === 'journal'
    ? `<button class="btn-primary" onclick="accJournalModal()">${I.plus} New entry</button>`
    : `<button class="btn-primary" onclick="accCoaModal()">${I.plus} New account</button>`;

  return `
    <div class="fade-in acc-page">
      ${accHeader('Journal & ledger', 'Double-entry records. Posted entries are never edited — only reversed.', action)}
      ${tabBar}
      ${tab === 'journal' ? accJournalList() : accCoaList()}
    </div>`;
}

function accLedgerTab(t) { AccState.ledgerTab = t; render(); }

function accJournalList() {
  const data = AccState.journal;
  const entries = (data && data.entries) || [];
  return `<div class="acc-card acc-card-flush">
    ${entries.length ? entries.map(e => `
      <div style="border-bottom:1px solid var(--border)">
        <div class="acc-row acc-row-link" style="grid-template-columns:110px minmax(0,2fr) 110px 130px 130px 110px" onclick="accToggleEntry(${e.id})">
          <div class="acc-mono">${esc(e.number)}</div>
          <div class="acc-truncate">${esc(e.memo)}</div>
          <div>${accDateShort(e.entry_date)}</div>
          <div class="acc-num">${accMoney(e.total_debit)}</div>
          <div class="acc-num">${accMoney(e.total_credit)}</div>
          <div>${accPill(e.status)}</div>
        </div>
        <div id="acc-entry-${e.id}" style="display:none;background:var(--surface-2);padding:10px 20px 14px">
          ${accTable(
            [{ label: 'Account', width: 'minmax(0,2fr)' }, { label: 'Description', width: 'minmax(0,1.4fr)' },
             { label: 'Debit', width: '130px', align: 'right' }, { label: 'Credit', width: '130px', align: 'right' }],
            (e.lines || []).map(l => [
              `<span class="acc-mono">${esc(l.code || '—')}</span> ${esc(l.account_name || 'Unknown')}`,
              `<span class="acc-sub acc-truncate">${esc(l.description || '')}</span>`,
              Number(l.debit) > 0 ? accMoney(l.debit) : '<span class="acc-dim">—</span>',
              Number(l.credit) > 0 ? accMoney(l.credit) : '<span class="acc-dim">—</span>',
            ]), 'No lines.')}
          <div style="display:flex;gap:10px;align-items:center;margin-top:10px">
            <span class="acc-sub">Source: ${esc(e.source)}</span>
            <span style="flex:1"></span>
            ${e.status === 'posted' && e.source !== 'reversal'
              ? `<button class="btn-secondary" style="padding:4px 10px;font-size:12.5px" onclick="accReverseEntry(${e.id},'${esc(e.number)}')">Reverse entry</button>` : ''}
            ${e.status === 'draft'
              ? `<button class="btn-secondary" style="padding:4px 10px;font-size:12.5px;color:var(--barn)" onclick="accDeleteEntry(${e.id})">Delete draft</button>` : ''}
          </div>
        </div>
      </div>`).join('') : accEmpty('No journal entries found.')}
    ${accPager(data && data.meta, 'accJournalPage')}
  </div>`;
}

function accToggleEntry(id) {
  const el = document.getElementById('acc-entry-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

async function accJournalPage(p) { AccState.journal = await API.accJournal({ page: p }); render(); }

async function accReverseEntry(id, number) {
  const ok = await Modal.confirm({
    title: 'Reverse entry',
    message: 'Create a reversing entry for ' + number + '? Posted entries are never edited or deleted — they are reversed.',
    confirmText: 'Reverse entry',
  });
  if (!ok) return;
  try {
    await API.accReverseJournal(id);
    AccState.journal = null; AccState.coa = null; AccState.reports = null;
    toast('Entry reversed', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteEntry(id) {
  const ok = await Modal.confirm({ title: 'Delete draft entry', message: 'Draft entries can be deleted because they never affected balances.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.accDeleteJournal(id);
    AccState.journal = null;
    toast('Draft deleted', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function accCoaList() {
  const data = AccState.coa;
  const groups = [['asset', 'Assets'], ['liability', 'Liabilities'], ['equity', 'Equity'], ['revenue', 'Revenue'], ['expense', 'Expenses']];
  const accounts = (data && data.accounts) || [];
  return `
    ${groups.map(([type, label]) => {
      const rows = accounts.filter(a => a.type === type);
      if (!rows.length) return '';
      return `<div class="acc-card acc-card-flush">
        <div class="acc-card-head"><span class="acc-card-title">${label}</span><span class="acc-card-note">${rows.length} accounts</span></div>
        ${accTable(
          [{ label: 'Code', width: '90px' }, { label: 'Account', width: 'minmax(0,2fr)' },
           { label: 'Normal side', width: '130px' }, { label: 'Balance', width: '150px', align: 'right' }, { label: '', width: '70px', align: 'right' }],
          rows.map(a => [
            `<span class="acc-mono">${esc(a.code)}</span>`,
            esc(a.name) + (Number(a.enabled) === 0 ? ' <span class="acc-chip">disabled</span>' : ''),
            `<span class="acc-chip">${esc(a.side)}</span>`,
            accMoney(a.balance),
            `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accCoaModal(${a.id})">Edit</button>`,
          ]), 'None.')}
      </div>`;
    }).join('')}
    <div class="acc-card">
      <div class="acc-kv"><span class="acc-kv-label">Total debit</span><span class="acc-kv-value">${accMoney(data ? data.total_debit : 0)}</span></div>
      <div class="acc-kv"><span class="acc-kv-label">Total credit</span><span class="acc-kv-value">${accMoney(data ? data.total_credit : 0)}</span></div>
    </div>`;
}

function accCoaModal(id) {
  const a = id && AccState.coa ? (AccState.coa.accounts || []).find(x => Number(x.id) === Number(id)) : null;
  Modal.open({
    title: id ? 'Edit ledger account' : 'New ledger account',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Code', `<input class="form-input acc-mono" id="acc-coa-code" value="${esc(a ? a.code : '')}" placeholder="1000">`)}
        ${accField('Name', `<input class="form-input" id="acc-coa-name" value="${esc(a ? a.name : '')}" placeholder="Cash — Operating">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Type', accSelect('acc-coa-type', [
          { value: 'asset', label: 'Asset' }, { value: 'liability', label: 'Liability' }, { value: 'equity', label: 'Equity' },
          { value: 'revenue', label: 'Revenue' }, { value: 'expense', label: 'Expense' },
        ], a ? a.type : 'asset'))}
        ${accField('Normal side', accSelect('acc-coa-side', [{ value: 'debit', label: 'Debit' }, { value: 'credit', label: 'Credit' }], a ? a.side : 'debit'))}
        ${accField('Enabled', accSelect('acc-coa-enabled', [{ value: '1', label: 'Enabled' }, { value: '0', label: 'Disabled' }], a ? String(a.enabled) : '1'))}
      </div>
      <p class="acc-sub" style="margin-top:10px">Balances are always derived from posted journal lines — they cannot be typed in.</p>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteCoa(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveCoa(${id || 'null'})">${id ? 'Save changes' : 'Create'}</button>`,
  });
}

async function accSaveCoa(id) {
  const code = accVal('acc-coa-code'), name = accVal('acc-coa-name');
  if (!code || !name) { toast('Code and name are required', 'error'); return; }
  const payload = { code, name, type: accVal('acc-coa-type'), side: accVal('acc-coa-side'), enabled: accVal('acc-coa-enabled') === '1' };
  try {
    if (id) await API.accUpdateCoa(id, payload); else await API.accCreateCoa(payload);
    Modal.close();
    AccState.coa = null;
    await accRefreshOptions();
    toast(id ? 'Saved' : 'Created', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteCoa(id) {
  const ok = await Modal.confirm({ title: 'Delete ledger account', message: 'Accounts with journal lines cannot be deleted — disable them instead.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.accDeleteCoa(id);
    Modal.close();
    AccState.coa = null;
    await accRefreshOptions();
    toast('Deleted', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ---------- Manual journal entry ---------- */

let AccJournalDraft = null;

function accJournalModal() {
  AccJournalDraft = [{ coa: '', debit: 0, credit: 0, description: '' }, { coa: '', debit: 0, credit: 0, description: '' }];
  Modal.open({
    title: 'New journal entry',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Date', `<input class="form-input" type="date" id="acc-j-date" value="${accToday()}">`)}
        ${accField('Memo', `<input class="form-input" id="acc-j-memo" placeholder="What is this entry for?">`)}
      </div>
      <div style="margin-top:14px;display:flex;align-items:center;gap:10px">
        <span class="acc-card-title" style="flex:1">Lines</span>
        <button class="btn-secondary" style="padding:5px 11px;font-size:12.5px" onclick="accJournalAddLine()">${I.plus} Add line</button>
      </div>
      <div id="acc-j-lines" style="margin-top:8px"></div>
      <div class="acc-balance" id="acc-j-balance"></div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             <button class="btn-secondary" onclick="accSaveJournal('draft')">Save as draft</button>
             <button class="btn-primary" onclick="accSaveJournal('posted')">Post entry</button>`,
    onMount: () => accJournalRender(),
  });
}

function accJournalRender() {
  const wrap = document.getElementById('acc-j-lines');
  if (!wrap) return;
  const coa = accOpts().coa || [];
  wrap.innerHTML = AccJournalDraft.map((l, i) => `
    <div class="acc-line" style="grid-template-columns:minmax(0,2fr) 120px 120px minmax(0,1.2fr) 34px">
      ${accSelect('acc-j-coa-' + i, coa.map(c => ({ value: c.id, label: c.code + ' — ' + c.name })), l.coa, 'Select account…', `onchange="accJournalSet(${i},'coa',this.value)"`)}
      <input class="form-input" type="number" step="0.01" min="0" style="text-align:right" placeholder="Debit"
             value="${l.debit || ''}" oninput="accJournalSet(${i},'debit',this.value)">
      <input class="form-input" type="number" step="0.01" min="0" style="text-align:right" placeholder="Credit"
             value="${l.credit || ''}" oninput="accJournalSet(${i},'credit',this.value)">
      <input class="form-input" placeholder="Description" value="${esc(l.description || '')}" oninput="accJournalSet(${i},'description',this.value)">
      <button class="acc-line-del" onclick="accJournalRemoveLine(${i})">×</button>
    </div>`).join('');
  accJournalBalance();
}

function accJournalSet(i, key, value) {
  if (!AccJournalDraft[i]) return;
  AccJournalDraft[i][key] = (key === 'debit' || key === 'credit') ? Number(value || 0) : value;
  accJournalBalance();
}

function accJournalAddLine() { AccJournalDraft.push({ coa: '', debit: 0, credit: 0, description: '' }); accJournalRender(); }

function accJournalRemoveLine(i) {
  if (AccJournalDraft.length <= 2) { toast('A journal entry needs at least two lines', 'error'); return; }
  AccJournalDraft.splice(i, 1);
  accJournalRender();
}

function accJournalBalance() {
  const box = document.getElementById('acc-j-balance');
  if (!box) return;
  let d = 0, c = 0;
  AccJournalDraft.forEach(l => { d += Number(l.debit || 0); c += Number(l.credit || 0); });
  const diff = Math.abs(d - c);
  const ok = diff < 0.01 && d > 0;
  box.className = 'acc-balance ' + (ok ? 'ok' : 'bad');
  box.innerHTML = `
    <div class="acc-balance-title">${ok ? '✓ Balanced' : '⚠ Unbalanced'}</div>
    <div class="acc-kv"><span class="acc-kv-label">Total debits</span><span class="acc-kv-value">${accMoney(d)}</span></div>
    <div class="acc-kv"><span class="acc-kv-label">Total credits</span><span class="acc-kv-value">${accMoney(c)}</span></div>
    <div class="acc-kv"><span class="acc-kv-label">Difference</span><span class="acc-kv-value">${accMoney(d - c)}</span></div>`;
}

async function accSaveJournal(status) {
  const memo = accVal('acc-j-memo');
  if (!memo) { toast('Add a memo', 'error'); return; }
  const lines = AccJournalDraft
    .filter(l => l.coa && (Number(l.debit) > 0 || Number(l.credit) > 0))
    .map(l => ({ chart_of_account_id: Number(l.coa), debit: Number(l.debit || 0), credit: Number(l.credit || 0), description: l.description }));
  if (lines.length < 2) { toast('Add at least two complete lines', 'error'); return; }
  try {
    await API.accCreateJournal({ entry_date: accVal('acc-j-date'), memo, status, lines });
    Modal.close();
    AccState.journal = null; AccState.coa = null; AccState.reports = null;
    toast(status === 'posted' ? 'Entry posted' : 'Draft saved', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ===================== Catalog — items, categories, taxes ===================== */

async function renderAccCatalog() {
  await accBoot();
  if (!accHas('acc.catalog')) return accDenied('the item catalog');
  if (!AccState.catalog) AccState.catalog = await API.accCatalog();
  const c = AccState.catalog;
  const tab = AccState.catalogTab;

  const tabBar = `<div class="acc-tabs">
    ${[['items', 'Items & services'], ['categories', 'Categories'], ['taxes', 'Tax rates']].map(t =>
      `<button class="acc-tab ${tab === t[0] ? 'active' : ''}" onclick="accCatalogTab('${t[0]}')">${t[1]}</button>`).join('')}
  </div>`;

  const action = {
    items: `<button class="btn-primary" onclick="accItemModal()">${I.plus} New item</button>`,
    categories: `<button class="btn-primary" onclick="accCategoryModal()">${I.plus} New category</button>`,
    taxes: `<button class="btn-primary" onclick="accTaxModal()">${I.plus} New tax rate</button>`,
  }[tab];

  let body = '';
  if (tab === 'items') {
    body = `<div class="acc-card acc-card-flush">${accTable(
      [{ label: 'Name', width: 'minmax(0,2fr)' }, { label: 'SKU', width: '140px' }, { label: 'Type', width: '110px' },
       { label: 'Sale price', width: '130px', align: 'right' }, { label: 'Cost', width: '130px', align: 'right' }, { label: '', width: '70px', align: 'right' }],
      (c.items || []).map(i => [
        `<div><div class="acc-strong">${esc(i.name)}</div>${i.category_name ? `<div class="acc-sub">${esc(i.category_name)}</div>` : ''}</div>`,
        i.sku ? `<span class="acc-mono">${esc(i.sku)}</span>` : '<span class="acc-dim">—</span>',
        `<span class="acc-chip">${esc(i.type)}</span>`,
        i.sale_price !== null ? accMoney(i.sale_price) : '<span class="acc-dim">—</span>',
        i.purchase_price !== null ? accMoney(i.purchase_price) : '<span class="acc-dim">—</span>',
        `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accItemModal(${i.id})">Edit</button>`,
      ]), 'No items found.')}</div>`;
  } else if (tab === 'categories') {
    body = `<div class="acc-card acc-card-flush">${accTable(
      [{ label: 'Name', width: 'minmax(0,2fr)' }, { label: 'Type', width: '120px' }, { label: 'Parent', width: 'minmax(0,1fr)' },
       { label: 'Status', width: '110px' }, { label: '', width: '70px', align: 'right' }],
      (c.categories || []).map(x => [
        `<span style="display:inline-flex;align-items:center;gap:8px"><i style="width:9px;height:9px;border-radius:50%;background:${esc(x.color || '#7e6549')};display:inline-block"></i>${esc(x.name)}</span>`,
        `<span class="acc-chip">${esc(x.type)}</span>`,
        x.parent_name ? esc(x.parent_name) : '<span class="acc-dim">—</span>',
        Number(x.enabled) === 1 ? accPill('active') : accPill('inactive'),
        `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accCategoryModal(${x.id})">Edit</button>`,
      ]), 'No categories found.')}</div>`;
  } else {
    body = `<div class="acc-card acc-card-flush">${accTable(
      [{ label: 'Name', width: 'minmax(0,2fr)' }, { label: 'Rate', width: '120px', align: 'right' }, { label: 'Type', width: '130px' },
       { label: 'Status', width: '110px' }, { label: '', width: '70px', align: 'right' }],
      (c.taxes || []).map(t => [
        esc(t.name),
        `<span class="acc-strong">${Number(t.rate).toFixed(2)}%</span>`,
        `<span class="acc-chip">${esc(t.type)}</span>`,
        Number(t.enabled) === 1 ? accPill('active') : accPill('inactive'),
        `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accTaxModal(${t.id})">Edit</button>`,
      ]), 'No tax rates found.')}</div>`;
  }

  return `<div class="fade-in acc-page">
    ${accHeader('Catalog', 'Reusable line items, transaction categories and tax rates.', action)}
    ${tabBar}${body}
  </div>`;
}

function accCatalogTab(t) { AccState.catalogTab = t; render(); }

function accItemModal(id) {
  const i = id && AccState.catalog ? (AccState.catalog.items || []).find(x => Number(x.id) === Number(id)) : null;
  const cats = (AccState.catalog && AccState.catalog.categories) || [];
  Modal.open({
    title: id ? 'Edit item' : 'New item',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Name', `<input class="form-input" id="acc-i-name" value="${esc(i ? i.name : '')}">`)}
        ${accField('SKU', `<input class="form-input acc-mono" id="acc-i-sku" value="${esc(i ? (i.sku || '') : '')}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Type', accSelect('acc-i-type', [{ value: 'service', label: 'Service' }, { value: 'product', label: 'Product' }], i ? i.type : 'service'))}
        ${accField('Category', accSelect('acc-i-category', cats.map(c => ({ value: c.id, label: c.name })), i ? i.category_id : '', 'No category'))}
        ${accField('Enabled', accSelect('acc-i-enabled', [{ value: '1', label: 'Enabled' }, { value: '0', label: 'Disabled' }], i ? String(i.enabled) : '1'))}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Sale price', `<input class="form-input" type="number" step="0.01" id="acc-i-sale" value="${i && i.sale_price !== null ? Number(i.sale_price) : ''}" style="text-align:right">`)}
        ${accField('Purchase price', `<input class="form-input" type="number" step="0.01" id="acc-i-purchase" value="${i && i.purchase_price !== null ? Number(i.purchase_price) : ''}" style="text-align:right">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Description', `<textarea class="form-input" id="acc-i-desc" rows="2">${esc(i ? (i.description || '') : '')}</textarea>`)}
      </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteItem(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveItem(${id || 'null'})">${id ? 'Save' : 'Create'}</button>`,
  });
}

async function accSaveItem(id) {
  const name = accVal('acc-i-name');
  if (!name) { toast('Name is required', 'error'); return; }
  const payload = {
    name, sku: accVal('acc-i-sku'), type: accVal('acc-i-type'),
    category_id: accVal('acc-i-category') ? Number(accVal('acc-i-category')) : null,
    sale_price: accVal('acc-i-sale'), purchase_price: accVal('acc-i-purchase'),
    description: accVal('acc-i-desc'), enabled: accVal('acc-i-enabled') === '1',
  };
  try {
    await API.accSaveItem(id, payload);
    Modal.close(); AccState.catalog = null; await accRefreshOptions();
    toast(id ? 'Saved' : 'Created', 'success'); render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteItem(id) {
  const ok = await Modal.confirm({ title: 'Delete item', message: 'Existing documents keep their line text; only the catalog entry is removed.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { await API.accDeleteItem(id); Modal.close(); AccState.catalog = null; await accRefreshOptions(); toast('Deleted', 'success'); render(); }
  catch (e) { toast(e.message, 'error'); }
}

function accCategoryModal(id) {
  const c = id && AccState.catalog ? (AccState.catalog.categories || []).find(x => Number(x.id) === Number(id)) : null;
  const cats = ((AccState.catalog && AccState.catalog.categories) || []).filter(x => !id || Number(x.id) !== Number(id));
  Modal.open({
    title: id ? 'Edit category' : 'New category',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Name', `<input class="form-input" id="acc-cat-name" value="${esc(c ? c.name : '')}">`)}
        ${accField('Type', accSelect('acc-cat-type', [{ value: 'income', label: 'Income' }, { value: 'expense', label: 'Expense' }], c ? c.type : 'expense'))}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Parent', accSelect('acc-cat-parent', cats.map(x => ({ value: x.id, label: x.name })), c ? c.parent_id : '', 'No parent'))}
        ${accField('Colour', `<input class="form-input" type="color" id="acc-cat-color" value="${esc(c ? (c.color || '#7e6549') : '#7e6549')}" style="padding:4px;height:38px">`)}
        ${accField('Enabled', accSelect('acc-cat-enabled', [{ value: '1', label: 'Enabled' }, { value: '0', label: 'Disabled' }], c ? String(c.enabled) : '1'))}
      </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteCategory(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveCategory(${id || 'null'})">${id ? 'Save' : 'Create'}</button>`,
  });
}

async function accSaveCategory(id) {
  const name = accVal('acc-cat-name');
  if (!name) { toast('Name is required', 'error'); return; }
  const payload = {
    name, type: accVal('acc-cat-type'), color: accVal('acc-cat-color'),
    parent_id: accVal('acc-cat-parent') ? Number(accVal('acc-cat-parent')) : null,
    enabled: accVal('acc-cat-enabled') === '1',
  };
  try {
    await API.accSaveCategory(id, payload);
    Modal.close(); AccState.catalog = null; await accRefreshOptions();
    toast(id ? 'Saved' : 'Created', 'success'); render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteCategory(id) {
  const ok = await Modal.confirm({ title: 'Delete category', message: 'Categories used by transactions cannot be deleted — disable them instead.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { await API.accDeleteCategory(id); Modal.close(); AccState.catalog = null; await accRefreshOptions(); toast('Deleted', 'success'); render(); }
  catch (e) { toast(e.message, 'error'); }
}

function accTaxModal(id) {
  const t = id && AccState.catalog ? (AccState.catalog.taxes || []).find(x => Number(x.id) === Number(id)) : null;
  Modal.open({
    title: id ? 'Edit tax rate' : 'New tax rate',
    body: `
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Name', `<input class="form-input" id="acc-tax-name" value="${esc(t ? t.name : '')}" placeholder="e.g. MA sales tax">`)}
        ${accField('Rate (%)', `<input class="form-input" type="number" step="0.001" min="0" max="100" id="acc-tax-rate" value="${t ? Number(t.rate) : ''}" style="text-align:right">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Type', accSelect('acc-tax-type', [
          { value: 'normal', label: 'Normal' }, { value: 'exempt', label: 'Exempt' },
          { value: 'inclusive', label: 'Inclusive' }, { value: 'compound', label: 'Compound' },
        ], t ? t.type : 'normal'))}
        ${accField('Enabled', accSelect('acc-tax-enabled', [{ value: '1', label: 'Enabled' }, { value: '0', label: 'Disabled' }], t ? String(t.enabled) : '1'))}
      </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteTax(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveTax(${id || 'null'})">${id ? 'Save' : 'Create'}</button>`,
  });
}

async function accSaveTax(id) {
  const name = accVal('acc-tax-name');
  if (!name) { toast('Name is required', 'error'); return; }
  const payload = { name, rate: accNumVal('acc-tax-rate'), type: accVal('acc-tax-type'), enabled: accVal('acc-tax-enabled') === '1' };
  try {
    await API.accSaveTax(id, payload);
    Modal.close(); AccState.catalog = null; await accRefreshOptions();
    toast(id ? 'Saved' : 'Created', 'success'); render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteTax(id) {
  const ok = await Modal.confirm({ title: 'Delete tax rate', message: 'Taxes applied to existing documents cannot be deleted — disable them instead.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { await API.accDeleteTax(id); Modal.close(); AccState.catalog = null; await accRefreshOptions(); toast('Deleted', 'success'); render(); }
  catch (e) { toast(e.message, 'error'); }
}

/* ===================== Recurring ===================== */

async function renderAccRecurring() {
  await accBoot();
  if (!accHas('acc.recurring')) return accDenied('recurring schedules');
  if (!AccState.recurring) AccState.recurring = await API.accRecurring();
  const list = AccState.recurring.schedules || [];

  const rows = list.map(r => [
    `<div><div class="acc-strong">${esc(r.document_number || 'Document #' + r.recurable_id)}</div>
      <div class="acc-sub">${esc(r.contact_name || '')}</div></div>`,
    `<span class="acc-chip">${esc(r.document_type === 'bill' ? 'Bill' : 'Invoice')}</span>`,
    esc(String(r.frequency).charAt(0).toUpperCase() + String(r.frequency).slice(1)) + (Number(r.interval_n) > 1 ? ' ×' + r.interval_n : ''),
    r.last_ran_at ? accDate(r.last_ran_at) : '<span class="acc-dim">Never</span>',
    r.next_run ? accDate(r.next_run) : '<span class="acc-dim">—</span>',
    Number(r.auto_send) === 1 ? '<span class="acc-pos">Auto-send</span>' : '<span class="acc-dim">Draft only</span>',
    accPill(r.status),
    `<button class="btn-secondary" style="padding:3px 9px;font-size:12px" onclick="accRecurringModal(${r.id})">Edit</button>`,
  ]);

  return `<div class="fade-in acc-page">
    ${accHeader('Recurring', 'Repeat an invoice or bill on a schedule. Nothing is generated until it is due.',
      `<button class="btn-secondary" onclick="accRunRecurring()">Run due now</button>
       <button class="btn-primary" onclick="accRecurringModal()">${I.plus} New schedule</button>`)}
    <div class="acc-card acc-card-flush">
      ${accTable(
        [{ label: 'Template', width: 'minmax(0,1.6fr)' }, { label: 'Kind', width: '100px' }, { label: 'Frequency', width: '130px' },
         { label: 'Last run', width: '130px' }, { label: 'Next run', width: '130px' }, { label: 'Mode', width: '120px' },
         { label: 'Status', width: '100px' }, { label: '', width: '70px', align: 'right' }],
        rows, 'No recurring schedules found.')}
    </div>
  </div>`;
}

async function accRunRecurring() {
  try {
    const res = await API.accRunRecurring();
    AccState.recurring = null; AccState.docs = { invoice: null, bill: null }; AccState.dashboard = null;
    toast(res.count ? res.count + ' document(s) generated' : 'Nothing is due right now', res.count ? 'success' : 'info');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function accRecurringModal(id) {
  const r = id && AccState.recurring ? (AccState.recurring.schedules || []).find(x => Number(x.id) === Number(id)) : null;
  Modal.open({
    title: id ? 'Edit schedule' : 'New recurring schedule',
    body: `
      <div class="form-row" style="gap:12px">
        ${accField('Template document ID', `<input class="form-input" type="number" id="acc-rec-target" value="${r ? r.recurable_id : ''}" placeholder="Open the invoice/bill to copy and use its ID">`)}
      </div>
      <p class="acc-sub" style="margin-top:6px">New documents copy this template's contact, line items and payment terms.</p>
      <div class="form-row" style="gap:12px;margin-top:12px;flex-wrap:wrap">
        ${accField('Frequency', accSelect('acc-rec-freq', [
          { value: 'daily', label: 'Daily' }, { value: 'weekly', label: 'Weekly' }, { value: 'monthly', label: 'Monthly' },
          { value: 'quarterly', label: 'Quarterly' }, { value: 'yearly', label: 'Yearly' },
        ], r ? r.frequency : 'monthly'))}
        ${accField('Every', `<input class="form-input" type="number" min="1" id="acc-rec-interval" value="${r ? Number(r.interval_n) : 1}">`)}
        ${accField('Start date', `<input class="form-input" type="date" id="acc-rec-start" value="${esc(r && r.started_at ? String(r.started_at).slice(0, 10) : accToday())}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Limit by', accSelect('acc-rec-limitby', [{ value: 'count', label: 'Number of occurrences' }, { value: 'date', label: 'End date' }], r ? r.limit_by : 'count'))}
        ${accField('Occurrences (0 = unlimited)', `<input class="form-input" type="number" min="0" id="acc-rec-limitcount" value="${r ? Number(r.limit_count) : 0}">`)}
        ${accField('End date', `<input class="form-input" type="date" id="acc-rec-limitdate" value="${esc(r && r.limit_date ? String(r.limit_date).slice(0, 10) : '')}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Mode', accSelect('acc-rec-auto', [{ value: '0', label: 'Create as draft' }, { value: '1', label: 'Auto-send and post to ledger' }], r ? String(r.auto_send) : '0'))}
        ${accField('Status', accSelect('acc-rec-status', [
          { value: 'active', label: 'Active' }, { value: 'paused', label: 'Paused' }, { value: 'ended', label: 'Ended' },
        ], r ? r.status : 'active'))}
      </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
             ${id ? `<button class="btn-secondary" style="color:var(--barn)" onclick="accDeleteRecurring(${id})">Delete</button>` : ''}
             <button class="btn-primary" onclick="accSaveRecurring(${id || 'null'})">${id ? 'Save' : 'Create'}</button>`,
  });
}

async function accSaveRecurring(id) {
  const target = accVal('acc-rec-target');
  if (!target) { toast('Enter the document ID to repeat', 'error'); return; }
  const payload = {
    recurable_id: Number(target), frequency: accVal('acc-rec-freq'),
    interval_n: Number(accVal('acc-rec-interval') || 1), started_at: accVal('acc-rec-start'),
    limit_by: accVal('acc-rec-limitby'), limit_count: Number(accVal('acc-rec-limitcount') || 0),
    limit_date: accVal('acc-rec-limitdate') || null, auto_send: accVal('acc-rec-auto') === '1',
    status: accVal('acc-rec-status'),
  };
  try {
    await API.accSaveRecurring(id, payload);
    Modal.close(); AccState.recurring = null;
    toast(id ? 'Saved' : 'Schedule created', 'success'); render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accDeleteRecurring(id) {
  const ok = await Modal.confirm({ title: 'Delete schedule', message: 'Documents already generated are kept.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { await API.accDeleteRecurring(id); Modal.close(); AccState.recurring = null; toast('Deleted', 'success'); render(); }
  catch (e) { toast(e.message, 'error'); }
}

/* ===================== Reports ===================== */

async function renderAccReports() {
  await accBoot();
  if (!accHas('acc.reports')) return accDenied('financial reports');
  const year = AccState.reportsYear || new Date().getFullYear();
  const period = AccState.reportPeriod || 'year';
  const basis = AccState.reportBasis || 'accrual';
  if (!AccState.reports || Number(AccState.reports.year) !== Number(year)
      || AccState.reports.period !== period || AccState.reports.basis !== basis) {
    AccState.reports = await API.accReports({ year, period, basis });
  }
  const r = AccState.reports;
  const tab = AccState.reportTab;
  const periodLabel = r.period === 'year' ? 'Year ' + r.year : r.period.toUpperCase() + ' ' + r.year;

  const tabs = [['pnl', 'Profit & loss'], ['sales', 'Sales analysis'], ['tax', 'Tax summary'],
                ['trial', 'Trial balance'], ['cashflow', 'Cash flow'], ['aging', 'Aging']];
  const tabBar = `<div class="acc-tabs">${tabs.map(t =>
    `<button class="acc-tab ${tab === t[0] ? 'active' : ''}" onclick="accReportTab('${t[0]}')">${t[1]}</button>`).join('')}</div>`;

  let body = '';
  if (tab === 'pnl') {
    const p = r.profit_loss;
    body = `<div class="acc-card acc-card-flush">
      <div class="acc-card-head"><span class="acc-card-title">Profit &amp; loss</span><span class="acc-card-note">Year ${r.year}</span></div>
      ${accTable([{ label: 'Revenue', width: 'minmax(0,2fr)' }, { label: 'Amount', width: '160px', align: 'right' }],
        (p.revenue || []).map(x => [esc(x.name), accMoney(x.total)]), 'No revenue recorded for ' + r.year + '.')}
      <div class="acc-row acc-row-total" style="grid-template-columns:minmax(0,2fr) 160px"><div>Total revenue</div><div class="acc-num">${accMoney(p.total_revenue)}</div></div>
      ${accTable([{ label: 'Expenses', width: 'minmax(0,2fr)' }, { label: 'Amount', width: '160px', align: 'right' }],
        (p.expenses || []).map(x => [esc(x.name), accMoney(x.total)]), 'No expenses recorded for ' + r.year + '.')}
      <div class="acc-row acc-row-total" style="grid-template-columns:minmax(0,2fr) 160px"><div>Total expenses</div><div class="acc-num">${accMoney(p.total_expense)}</div></div>
      <div style="padding:16px 20px"><div class="acc-due-box" style="background:${Number(p.net_income) >= 0 ? 'var(--sage-bg)' : 'var(--barn-bg)'}">
        <span>Net income</span><span style="font-size:20px">${accMoney(p.net_income)}</span></div></div>
    </div>`;
  } else if (tab === 'sales') {
    const s = r.sales || {};
    const grand = Number(s.total) || 0;
    // Share bar makes the concentration obvious at a glance — one customer
    // country carrying 80% of revenue is the thing you want to notice.
    const share = (v) => {
      const pct = grand > 0 ? (Number(v) / grand) * 100 : 0;
      return `<div class="acc-share"><span style="width:${Math.max(2, Math.min(100, pct)).toFixed(1)}%"></span></div>
              <span class="acc-sub acc-share-pct">${pct.toFixed(1)}%</span>`;
    };
    const salesCard = (title, note, rows, emptyText, firstLabel) => `
      <div class="acc-card acc-card-flush">
        <div class="acc-card-head"><span class="acc-card-title">${title}</span><span class="acc-card-note">${note}</span></div>
        ${accTable(
          [{ label: firstLabel, width: 'minmax(0,1.4fr)' }, { label: 'Invoices', width: '90px', align: 'right' },
           { label: 'Share', width: 'minmax(0,1fr)' },
           { label: 'Invoiced', width: '140px', align: 'right' }, { label: 'Collected', width: '140px', align: 'right' }],
          (rows || []).map(x => [
            esc(x.name) + (x.country && x.country !== x.name ? ` <span class="acc-sub">${esc(x.country)}</span>` : ''),
            `<span class="acc-mono">${Number(x.invoices)}</span>`,
            share(x.total),
            accMoney(x.total),
            `<span class="acc-pos">${accMoney(x.collected)}</span>`,
          ]), emptyText)}
      </div>`;

    body = `
      <div class="acc-stats">
        ${accStat('Total sales', accMoney(s.total), periodLabel + ' · invoiced')}
        ${accStat('Countries', String((s.by_country || []).length), 'With activity')}
        ${accStat('Agents', String((s.by_agent || []).length), 'With attributed sales')}
        ${accStat('Revenue types', String((s.by_type || []).length), 'Categories used')}
      </div>
      ${salesCard('Sales by country', periodLabel, s.by_country, 'No invoices in this period.', 'Country')}
      ${salesCard('Sales by region', 'State / province, from the customer record', s.by_region, 'No invoices in this period.', 'Region')}
      ${salesCard('Sales by agent', 'Attributed from the sales agent on each invoice', s.by_agent, 'No invoices in this period.', 'Agent')}
      ${salesCard('Sales by type', 'Revenue category on each invoice', s.by_type, 'No invoices in this period.', 'Type')}
      <p class="acc-sub" style="margin-top:6px">
        Location comes from the country and state on the customer record, so filling those in on
        Customers &amp; vendors is what makes this report accurate. Rows without one group under "Unspecified".
      </p>`;
  } else if (tab === 'tax') {
    const t = r.tax;
    body = `<div class="acc-card acc-card-flush">
      <div class="acc-card-head"><span class="acc-card-title">Tax summary</span>
        <span class="acc-card-note">${periodLabel} · ${r.basis === 'cash' ? 'cash basis' : 'accrual basis'}</span></div>
      <div style="padding:12px 20px;border-bottom:1px solid var(--border)" class="acc-sub">
        ${r.basis === 'cash'
          ? 'Cash basis — tax is recognised as payments arrive, allocated pro-rata across each document. This is what most US state sales-tax filings ask for.'
          : 'Accrual basis — tax is recognised when the document is issued, whether or not it has been paid.'}
      </div>
      ${accTable([{ label: 'Tax collected (invoices)', width: 'minmax(0,2fr)' }, { label: 'Amount', width: '160px', align: 'right' }],
        (t.collected || []).map(x => [esc(x.name) + ' (' + Number(x.rate).toFixed(2) + '%)', accMoney(x.total)]), 'No tax collected.')}
      <div class="acc-row acc-row-total" style="grid-template-columns:minmax(0,2fr) 160px"><div>Total collected</div><div class="acc-num">${accMoney(t.total_collected)}</div></div>
      ${accTable([{ label: 'Tax paid (bills)', width: 'minmax(0,2fr)' }, { label: 'Amount', width: '160px', align: 'right' }],
        (t.paid || []).map(x => [esc(x.name) + ' (' + Number(x.rate).toFixed(2) + '%)', accMoney(x.total)]), 'No tax paid.')}
      <div class="acc-row acc-row-total" style="grid-template-columns:minmax(0,2fr) 160px"><div>Total paid</div><div class="acc-num">${accMoney(t.total_paid)}</div></div>
      <div style="padding:16px 20px"><div class="acc-due-box"><span>Net tax liability</span><span style="font-size:20px">${accMoney(t.net_tax)}</span></div></div>
    </div>`;
  } else if (tab === 'trial') {
    const tb = r.trial_balance;
    body = `<div class="acc-card acc-card-flush">
      <div class="acc-card-head"><span class="acc-card-title">Trial balance</span><span class="acc-card-note">Current balances</span></div>
      ${accTable(
        [{ label: 'Code', width: '90px' }, { label: 'Account', width: 'minmax(0,2fr)' },
         { label: 'Debit', width: '150px', align: 'right' }, { label: 'Credit', width: '150px', align: 'right' }],
        (tb.rows || []).map(x => [
          `<span class="acc-mono acc-dim">${esc(x.code)}</span>`, esc(x.name),
          Number(x.debit) > 0 ? accMoney(x.debit) : '<span class="acc-dim">—</span>',
          Number(x.credit) > 0 ? accMoney(x.credit) : '<span class="acc-dim">—</span>',
        ]), 'No chart-of-accounts entries yet.')}
      <div class="acc-row acc-row-total" style="grid-template-columns:90px minmax(0,2fr) 150px 150px">
        <div></div><div>Totals</div><div class="acc-num">${accMoney(tb.total_debit)}</div><div class="acc-num">${accMoney(tb.total_credit)}</div>
      </div>
      <div style="padding:14px 20px" class="${tb.balanced ? 'acc-pos' : 'acc-neg'}">
        ${tb.balanced ? '✦ Debits equal credits — the books are balanced.' : '⚠ Debits and credits do not match — review journal entries.'}
      </div>
    </div>`;
  } else if (tab === 'cashflow') {
    const cf = r.cash_flow;
    body = `<div class="acc-card acc-card-flush">
      <div class="acc-card-head"><span class="acc-card-title">Cash flow</span><span class="acc-card-note">Money in vs out by month</span></div>
      ${accTable(
        [{ label: 'Month', width: '110px' }, { label: 'In', width: 'minmax(0,1fr)', align: 'right' },
         { label: 'Out', width: 'minmax(0,1fr)', align: 'right' }, { label: 'Net', width: 'minmax(0,1fr)', align: 'right' }],
        (cf.months || []).map(m => [
          esc(m.month),
          Number(m.income) > 0 ? `<span class="acc-pos">${accMoney(m.income)}</span>` : '<span class="acc-dim">—</span>',
          Number(m.expense) > 0 ? `<span class="acc-neg">${accMoney(m.expense)}</span>` : '<span class="acc-dim">—</span>',
          `<span class="${Number(m.net) < 0 ? 'acc-neg' : ''}">${accMoney(m.net)}</span>`,
        ]), 'No cash movement in ' + r.year + '.')}
      <div class="acc-row acc-row-total" style="grid-template-columns:110px minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)">
        <div>Total</div><div class="acc-num">${accMoney(cf.total_in)}</div><div class="acc-num">${accMoney(cf.total_out)}</div><div class="acc-num">${accMoney(cf.net)}</div>
      </div>
    </div>`;
  } else {
    const ag = r.aging;
    const agingCard = (title, d) => `<div class="acc-card acc-card-flush">
      <div class="acc-card-head"><span class="acc-card-title">${title}</span></div>
      ${accTable(
        [{ label: 'Bucket', width: 'minmax(0,1fr)' }, { label: 'Amount', width: '160px', align: 'right' }],
        [['Current / not yet due', d.current_amt], ['1–30 days late', d.d30], ['31–60 days late', d.d60], ['60+ days late', d.d90]]
          .map(x => [esc(x[0]), accMoney(x[1])]), 'Nothing outstanding.')}
    </div>`;
    body = `<div class="acc-split">${agingCard('Aged receivables', ag.receivable)}${agingCard('Aged payables', ag.payable)}</div>`;
  }

  return `<div class="fade-in acc-page">
    ${accHeader('Reports', 'Every figure is computed live from your ledger — nothing here is hard-coded.',
      `<div style="min-width:110px">${accSelect('acc-report-year', (r.years || []).map(y => ({ value: y, label: String(y) })), r.year, null, 'onchange="accReportRefresh()"')}</div>
       <div style="min-width:130px">${accSelect('acc-report-period', [
          { value: 'year', label: 'Full year' }, { value: 'q1', label: 'Q1 (Jan–Mar)' }, { value: 'q2', label: 'Q2 (Apr–Jun)' },
          { value: 'q3', label: 'Q3 (Jul–Sep)' }, { value: 'q4', label: 'Q4 (Oct–Dec)' },
        ], r.period, null, 'onchange="accReportRefresh()"')}</div>
       <div style="min-width:130px">${accSelect('acc-report-basis', [
          { value: 'accrual', label: 'Accrual basis' }, { value: 'cash', label: 'Cash basis' },
        ], r.basis, null, 'onchange="accReportRefresh()"')}</div>
       <button class="btn-secondary" onclick="window.print()">Print</button>`)}
    ${tabBar}${body}
  </div>`;
}

function accReportTab(t) { AccState.reportTab = t; render(); }

/** Year, quarter and basis all reload the same payload. */
function accReportRefresh() {
  AccState.reportsYear = Number(accVal('acc-report-year')) || AccState.reportsYear;
  AccState.reportPeriod = accVal('acc-report-period') || 'year';
  AccState.reportBasis = accVal('acc-report-basis') || 'accrual';
  AccState.reports = null;
  render();
}
// Kept for any older call sites.
function accReportYear() { accReportRefresh(); }

/* ===================== Accounting settings ===================== */

async function renderAccSettings() {
  await accBoot();
  if (!accHas('acc.settings')) return accDenied('accounting settings');
  if (!AccState.settings) AccState.settings = await API.accSettings();
  const s = AccState.settings.settings;
  const counts = AccState.settings.counts || {};
  const totalRows = Object.values(counts).reduce((a, b) => a + Number(b || 0), 0);

  return `<div class="fade-in acc-page">
    ${accHeader('Accounting settings', 'Company profile, document numbering and data tools.')}

    <div class="acc-card">
      <div class="acc-card-title" style="margin-bottom:12px">Company profile</div>
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Company name', `<input class="form-input" id="acc-s-name" value="${esc(s.company_name)}">`)}
        ${accField('EIN / tax ID', `<input class="form-input acc-mono" id="acc-s-ein" value="${esc(s.company_ein)}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Address', `<textarea class="form-input" id="acc-s-address" rows="2">${esc(s.company_address)}</textarea>`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Email', `<input class="form-input" type="email" id="acc-s-email" value="${esc(s.company_email)}">`)}
        ${accField('Phone', `<input class="form-input" id="acc-s-phone" value="${esc(s.company_phone)}">`)}
        ${accField('Website', `<input class="form-input" id="acc-s-website" value="${esc(s.company_website)}">`)}
      </div>
      <div class="form-row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
        ${accField('Currency', accSelect('acc-s-currency', [
          { value: 'USD', label: 'USD ($)' }, { value: 'EUR', label: 'EUR (€)' },
          { value: 'GBP', label: 'GBP (£)' }, { value: 'CAD', label: 'CAD ($)' },
        ], s.default_currency))}
        ${accField('Fiscal year start', accSelect('acc-s-fiscal', [
          { value: '01-01', label: 'January' }, { value: '04-01', label: 'April' },
          { value: '07-01', label: 'July' }, { value: '10-01', label: 'October' },
        ], s.fiscal_year_start))}
        ${accField('Default payment terms', `<input class="form-input" id="acc-s-terms" value="${esc(s.default_payment_terms)}">`)}
      </div>
      <button class="btn-primary" style="margin-top:14px" onclick="accSaveSettings()">Save company settings</button>
    </div>

    <div class="acc-card">
      <div class="acc-card-title" style="margin-bottom:12px">Document numbering</div>
      <div class="form-row" style="gap:12px;flex-wrap:wrap">
        ${accField('Invoice prefix', `<input class="form-input acc-mono" id="acc-s-invprefix" value="${esc(s.invoice_prefix)}">`)}
        ${accField('Next invoice number', `<input class="form-input acc-mono" id="acc-s-invnext" value="${esc(s.invoice_next_number)}" style="text-align:right">`)}
        ${accField('Bill prefix', `<input class="form-input acc-mono" id="acc-s-billprefix" value="${esc(s.bill_prefix)}">`)}
        ${accField('Next bill number', `<input class="form-input acc-mono" id="acc-s-billnext" value="${esc(s.bill_next_number)}" style="text-align:right">`)}
      </div>
      <p class="acc-sub" style="margin-top:8px">Zero padding is preserved — <b>0150</b> keeps four digits.</p>
      <div class="form-row" style="gap:12px;margin-top:10px">
        ${accField('Invoice footer', `<input class="form-input" id="acc-s-footer" value="${esc(s.invoice_footer)}">`)}
      </div>
      <button class="btn-primary" style="margin-top:14px" onclick="accSaveSettings()">Save numbering</button>
    </div>

    <div class="acc-card">
      <div class="acc-card-title" style="margin-bottom:6px">Data tools</div>
      <div class="desc" style="font-size:13px;color:var(--muted);margin-bottom:12px">
        Accounting currently holds <b>${totalRows.toLocaleString()}</b> rows across ${Object.keys(counts).length} tables.
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="accRecalc()">Recalculate all balances</button>
        <button class="btn-secondary" onclick="nav('settings')">Clear accounting data →</button>
      </div>
      <p class="acc-sub" style="margin-top:10px">Clearing all accounting data lives in VGold Settings → Danger Zone, alongside the other destructive actions.</p>
    </div>
  </div>`;
}

async function accSaveSettings() {
  const payload = {
    company_name: accVal('acc-s-name'), company_ein: accVal('acc-s-ein'), company_address: accVal('acc-s-address'),
    company_email: accVal('acc-s-email'), company_phone: accVal('acc-s-phone'), company_website: accVal('acc-s-website'),
    default_currency: accVal('acc-s-currency'), fiscal_year_start: accVal('acc-s-fiscal'),
    default_payment_terms: accVal('acc-s-terms'),
    invoice_prefix: accVal('acc-s-invprefix'), invoice_next_number: accVal('acc-s-invnext'),
    bill_prefix: accVal('acc-s-billprefix'), bill_next_number: accVal('acc-s-billnext'),
    invoice_footer: accVal('acc-s-footer'),
  };
  Object.keys(payload).forEach(k => { if (payload[k] === '') delete payload[k]; });
  try {
    await API.accUpdateSettings(payload);
    AccState.settings = null;
    await accRefreshOptions();
    toast('Accounting settings saved', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function accRecalc() {
  try {
    const res = await API.accRecalcBalances();
    accResetCaches();
    toast('Recalculated ' + res.accounts + ' accounts and ' + res.chart_of_accounts + ' ledger accounts', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ============================================================================
 * Hooks used by the main VGold Settings screen (settings.js)
 * ==========================================================================*/

/**
 * Module-access chips for one team member, grouped by app.
 * CRM keeps its existing rule (admins implicitly hold everything, so the chips
 * are locked). Accounting is explicit-grant only, so admins are NOT auto-ticked
 * — otherwise the UI would claim every admin can see finance data when they
 * cannot.
 */
function accModuleChips(modules, member) {
  const list = modules || [];
  const groups = [
    { key: 'crm', label: 'CRM', note: 'Admins get all CRM modules automatically' },
    { key: 'acc', label: 'Accounting & Finance', note: 'Granted per person — admins are not included by default' },
  ];
  return groups.map(g => {
    const mods = list.filter(m => (m.group || 'crm') === g.key);
    if (!mods.length) return '';
    const adminImplicit = mods[0].admin_implicit !== false;
    const locked = adminImplicit && member.role === 'admin';
    return `
      <div class="acc-module-group">
        <div class="acc-module-group-label">${esc(g.label)}
          ${locked ? '<span class="acc-owner-tag">auto-granted</span>' : ''}
        </div>
        <div class="acc-module-chips">
          ${mods.map(mod => {
            const checked = locked || (member.access || []).includes(mod.key);
            return `<label class="module-access-chip ${checked ? 'checked' : ''} ${locked ? 'locked' : ''}">
              <input type="checkbox" data-user="${member.id}" data-module="${esc(mod.key)}" ${checked ? 'checked' : ''} ${locked ? 'disabled' : ''} onchange="saveModuleAccess(${member.id})"><span>${esc(mod.label)}</span>
            </label>`;
          }).join('')}
        </div>
      </div>`;
  }).join('');
}

/** Danger-zone card appended to VGold Settings — clears all accounting data. */
function accDangerZoneCard(user) {
  if (!user || user.role !== 'admin') return '';
  if (!(State.user && (State.user.modules || []).includes('acc.settings'))) return '';
  return `
    <div class="danger-zone" style="margin-top:14px" id="settings-acc-data">
      <h4>Clear Accounting &amp; Finance data</h4>
      <p>Permanently deletes every invoice, bill, payment, transfer, journal entry, contact, item, category, tax rate, bank account and ledger account in the Accounting app. Your company profile and the rest of VGold (Workflow, CRM) are untouched. Invoice and bill counters reset to 0001.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
        <div class="form-field" style="flex:1;min-width:170px">
          <label class="form-label">Your password</label>
          <input class="form-input" type="password" id="acc-reset-pw" autocomplete="current-password" placeholder="••••••••">
        </div>
        <div class="form-field" style="flex:1;min-width:190px">
          <label class="form-label">Type CLEAR ACCOUNTING</label>
          <input class="form-input" id="acc-reset-text" placeholder="CLEAR ACCOUNTING">
        </div>
        <button class="btn-danger" style="flex:none" onclick="accClearData('fresh')">Clear accounting data</button>
        <button class="btn-secondary" style="flex:none" onclick="accClearData('sample')">Clear &amp; reload sample data</button>
      </div>
    </div>`;
}

async function accClearData(mode) {
  const text = accVal('acc-reset-text');
  if (text !== 'CLEAR ACCOUNTING') { toast('Type CLEAR ACCOUNTING to confirm', 'error'); return; }
  const ok = await Modal.confirm({
    title: mode === 'sample' ? 'Reload sample accounting data' : 'Clear all accounting data',
    message: mode === 'sample'
      ? 'This deletes all accounting data and reloads the bundled demo dataset. Workflow and CRM are untouched.'
      : 'This permanently deletes all Accounting & Finance data. Workflow and CRM are untouched. This cannot be undone.',
    confirmText: mode === 'sample' ? 'Clear and reload' : 'Clear everything',
    danger: true,
  });
  if (!ok) return;
  try {
    await API.accReset({ mode, password: accVal('acc-reset-pw'), confirm_text: text });
    accResetCaches();
    toast(mode === 'sample' ? 'Accounting data reset to the sample dataset' : 'All accounting data cleared', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}
