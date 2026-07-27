// VGo — CRM Customers
//
// A customer IS a converted lead, so this screen reuses the lead record and all
// of its actions (call, WhatsApp, email, log interaction). Money is NOT stored
// here: lifetime value, open balance and the products bought are read live from
// Accounting via acc_contacts.crm_lead_id, so the CRM can never disagree with
// the ledger.

async function renderCrmCustomers() {
  if (!crmHas('crm.leads')) return crmAccessDenied('crm.leads');

  let data = State.crmCustomers;
  if (!data) {
    try {
      data = await API.crmCustomers(State.crmCustomerSearch || '');
      State.crmCustomers = data;
    } catch (e) {
      return crmModError('Customers', e.message);
    }
  }
  const customers = data.customers || [];
  const totals = data.totals || {};
  const money = (v, cur) => crmMoney(v, cur);

  const stats = crmStatRow([
    ['Customers', totals.customers || 0, CT_IC.users || CT_IC.doc],
    ['Lifetime value', money(totals.lifetime_value || 0), CT_IC.chart],
    ['Open balance', money(totals.open_balance || 0), CT_IC.doc],
    ['Linked to Accounting', (totals.linked || 0) + ' / ' + (totals.customers || 0), CT_IC.check],
  ]);

  const rows = customers.map(c => {
    const f = c.finance || {};
    const products = (f.items || []).slice(0, 2).map(i => esc(i.name)).join(', ');
    const more = (f.items || []).length > 2 ? ` +${f.items.length - 2}` : '';
    return `<tr>
      <td><strong>${esc(c.display_name)}</strong>
        ${c.company_name && c.company_name !== c.display_name ? `<div class="ct-secline">${esc(c.company_name)}</div>` : ''}</td>
      <td>${esc(c.country || '—')}</td>
      <td>${esc(c.assigned_name || 'Unassigned')}</td>
      <td>${products ? esc(products) + more : '<span class="ct-secline">No invoiced products</span>'}</td>
      <td style="text-align:right"><strong>${money(f.lifetime_value || 0, f.currency)}</strong>
        ${Number(f.open_balance) > 0 ? `<div class="ct-secline" style="color:var(--color-danger)">${money(f.open_balance, f.currency)} open</div>` : ''}</td>
      <td>${f.contact_id ? crmBadge('Linked', 'badge-green') : crmBadge('Not linked', 'badge-gray')}</td>
      <td><div class="ct-actions-cell">
        <button class="btn btn-outline btn-sm" onclick="goCrmCustomer(${c.id})">Open</button>
      </div></td>
    </tr>`;
  }).join('');

  return `<div class="crm-native fade-in">
    ${crmModHead('Customers', 'Leads that became customers. Purchases and value come live from Accounting.',
      `<button class="btn btn-outline" onclick="nav('crm-leads')">View leads</button>`)}
    ${stats}
    <div class="ct-toolbar" style="margin-bottom:16px">
      <input class="form-control" id="cust-search" placeholder="Search customers by name, company, email or phone…"
             value="${esc(State.crmCustomerSearch || '')}" onkeydown="if(event.key==='Enter')crmCustomerSearch()">
      <button class="btn btn-primary" onclick="crmCustomerSearch()">Search</button>
      ${State.crmCustomerSearch ? `<button class="btn btn-outline" onclick="crmCustomerClearSearch()">Clear</button>` : ''}
    </div>
    ${crmTable(['Customer', 'Country', 'Owner', 'Products bought', 'Lifetime value', 'Accounting', ''], rows,
      'No customers yet. Convert a won lead from its detail page to create one.')}
  </div>`;
}

function crmCustomerSearch() {
  State.crmCustomerSearch = document.getElementById('cust-search')?.value.trim() || '';
  State.crmCustomers = null;
  render();
}
function crmCustomerClearSearch() {
  State.crmCustomerSearch = '';
  State.crmCustomers = null;
  render();
}

// A customer opens in the normal lead detail screen — same record, same history.
function goCrmCustomer(id) {
  goCrmLead(id);
}

/** Format money without pretending we know a currency we were never given. */
function crmMoney(value, currency) {
  const n = Number(value || 0);
  const cur = currency || 'USD';
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(n);
  } catch (e) {
    return cur + ' ' + n.toLocaleString(undefined, { maximumFractionDigits: 0 });
  }
}

// ===== Convert a lead into a customer =====
async function crmConvertLead(leadId, name) {
  appConfirm(`Convert ${name || 'this lead'} into a customer? This marks the lead as Won and creates a matching customer record in Accounting.`, async () => {
    try {
      const res = await API.crmConvertLead(leadId);
      State.crmLeadDetail = null;
      State.crmLeads = null;
      State.crmCustomers = null;
      State.crmDashboard = null;
      toast(res.contact_created
        ? 'Converted — customer created in Accounting'
        : (res.contact_id ? 'Converted — already linked in Accounting' : 'Converted to customer'), 'success');
      render();
    } catch (e) { toast(e.message, 'error'); }
  });
}

/**
 * Purchases panel for the lead detail page. Rendered only when the lead is a
 * customer; the data comes from Accounting, never from CRM columns.
 */
function crmCustomerFinanceCard(finance, leadId) {
  const f = finance || {};
  if (!f.contact_id) {
    return `<div class="card">
      <div class="card-header"><h3 class="card-title">Purchases</h3></div>
      <div class="card-body empty-state" style="padding:22px">
        <p>This customer is not linked to Accounting yet, so there is nothing to bill against.</p>
        <button class="btn btn-primary btn-sm" style="margin-top:12px" onclick="crmConvertLead(${leadId})">Link to Accounting</button>
      </div></div>`;
  }
  const items = f.items || [];
  const rows = items.map(i => `<tr>
      <td>${esc(i.name)}</td>
      <td style="text-align:right">${Number(i.qty).toLocaleString()}</td>
      <td style="text-align:right">${crmMoney(i.unit_price, f.currency)}</td>
      <td style="text-align:right"><strong>${crmMoney(i.total, f.currency)}</strong></td>
      <td style="text-align:right" class="ct-secline">${crmModDate(i.last_date)}</td>
    </tr>`).join('');

  return `<div class="card">
    <div class="card-header">
      <h3 class="card-title">Purchases</h3>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" onclick="accOpenContact(${f.contact_id})">Open in Accounting</button>
        <button class="btn btn-primary btn-sm" onclick="accNewInvoiceFor(${f.contact_id})">Issue invoice</button>
      </div>
    </div>
    <div class="card-body">
      <div class="ct-cust-kpis">
        <div><span class="ct-secline">Lifetime value</span><strong>${crmMoney(f.lifetime_value, f.currency)}</strong></div>
        <div><span class="ct-secline">Paid</span><strong>${crmMoney(f.paid, f.currency)}</strong></div>
        <div><span class="ct-secline">Open balance</span><strong style="${Number(f.open_balance) > 0 ? 'color:var(--color-danger)' : ''}">${crmMoney(f.open_balance, f.currency)}</strong></div>
        <div><span class="ct-secline">Invoices</span><strong>${f.invoice_count || 0}</strong></div>
      </div>
      ${items.length
        ? crmTable(['Product / service', 'Qty', 'Unit price', 'Total', 'Last invoiced'], rows, '')
        : '<p class="ct-secline" style="margin-top:12px">No invoiced products yet.</p>'}
    </div>
  </div>`;
}

/** Jump into the Accounting app at this customer. */
function accOpenContact(contactId) {
  State.accContactType = 'customer';
  if (typeof accNav === 'function') accNav('acc-contact', { accContactId: contactId });
}

/**
 * Open the Accounting invoice editor for this customer. Guarded on the grant:
 * a sales user may hold acc.customers without acc.invoices.
 */
function accNewInvoiceFor(contactId) {
  if (typeof accHas === 'function' && !accHas('acc.invoices')) {
    toast('You do not have access to invoicing. Ask an admin for the Invoices module.', 'error');
    return;
  }
  State.accContactType = 'customer';
  State.accPrefillContactId = contactId;
  if (typeof accDocEditor === 'function') accDocEditor('invoice');
  else if (typeof accNav === 'function') accNav('acc-invoices');
}
