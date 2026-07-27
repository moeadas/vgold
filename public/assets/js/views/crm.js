// VGold native CRM views — same shell, session, permissions, and task model as Workflow.
// Markup mirrors the original Victory Genomics CRM pages using the design-system
// classes from style.css, which are exposed to the SPA scoped under `.crm-native`.
// Every top-level view (and every Modal body) is wrapped in `.crm-native` so those
// styles apply. Data wiring, API calls, field names, and routing are unchanged.

const CRM_MODULE_COPY = {
  'crm.proposals': { title: 'Proposals', description: 'Create, review, and track proposals alongside each lead.', features: ['Proposal pipeline', 'Templates and numbering', 'Lead-linked documents'] },
  'crm.email': { title: 'Email marketing', description: 'Manage audiences, templates, campaigns, and delivery activity.', features: ['Campaigns', 'Templates', 'Audience lists'] },
  'crm.communications': { title: 'Calls & WhatsApp', description: 'Keep calls and messages attached to the same customer record.', features: ['VoIP activity', 'WhatsApp conversations', 'Communication history'] },
  'crm.automation': { title: 'Automations', description: 'Run lead and follow-up actions from one shared rules engine.', features: ['Triggers', 'Actions', 'Run history'] },
  'crm.reports': { title: 'Reports', description: 'See CRM performance and export the data your team needs.', features: ['Pipeline reporting', 'Team performance', 'Data exports'] },
  'crm.knowledge': { title: 'Knowledge hub', description: 'Keep sales guides and customer-facing knowledge close to the work.', features: ['Quick guides', 'Sales playbooks', 'Shared resources'] },
};

function crmHas(moduleKey) {
  return (State.user?.modules || []).includes(moduleKey);
}

// The lead-detail view now relies entirely on the scoped design-system classes in
// crm-native.css, so no per-view style injection is needed. Kept as a no-op to
// preserve the public API for any external callers.
function ensureCrmDetailStyles() { /* styles now live in crm-native.css */ }

// ===== Small inline SVG icons (feather-style, stroke=currentColor) =====
const CRM_ICONS = {
  users: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  check: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
  refresh: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
  clock: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  message: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
  calendar: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
  plus: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
  search: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
  back: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
  edit: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
  phone: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
  mail: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
};

// ===== Badge helpers (map lead status / priority / interaction type to design classes) =====
function crmStatusBadge(status) {
  const map = {
    'New Lead': 'info', 'Contacted': 'warning', 'Interested': 'success', 'Not Interested': 'danger',
    'Schedule Call': 'primary', 'Call Scheduled': 'primary', 'Demo Scheduled': 'primary',
    'Proposal Sent': 'warning', 'Negotiation': 'info', 'Won': 'success', 'Lost': 'danger', 'On Hold': 'secondary',
  };
  return 'badge-' + (map[status] || 'secondary');
}
function crmPriorityBadge(priority) {
  const map = { 'Low': 'secondary', 'Medium': 'info', 'High': 'warning', 'Urgent': 'danger' };
  return 'badge-' + (map[priority] || 'secondary');
}
function crmInteractionBadge(type) {
  return 'badge-' + String(type || 'note').toLowerCase().replace(/[^a-z]+/g, '-');
}

function crmAccessDenied(moduleKey) {
  const label = CRM_MODULE_COPY[moduleKey]?.title || 'CRM';
  return `<div class="crm-native fade-in"><div class="empty-state card" style="padding:56px 24px;max-width:560px;margin:48px auto;text-align:center;"><h3>${esc(label)} access is not enabled</h3><p>Ask a VGold administrator to enable this module in Settings → Team module access.</p></div></div>`;
}

async function renderCrmDashboard() {
  if (!(State.user?.modules || []).length) return crmAccessDenied('crm.dashboard');
  let data = State.crmDashboard;
  if (!data) {
    data = await API.crmDashboard();
    State.crmDashboard = data;
  }
  const s = data.stats || {};
  const stat = (label, value, icon, tone, target) => value === null || value === undefined ? '' : `
    <div class="stat-card${target ? ' clickable-card' : ''}"${target ? ` onclick="nav('${target}')" style="cursor:pointer;"` : ''}>
      <div class="stat-icon ${tone}">${icon}</div>
      <div class="stat-content"><div class="stat-label">${esc(label)}</div><div class="stat-value">${esc(String(value))}</div></div>
    </div>`;
  const actionCard = (kicker, title, body, cta, target) => `
    <div class="card clickable-card" onclick="nav('${target}')" style="cursor:pointer;">
      <div class="card-body">
        <div class="sidebar-label">${esc(kicker)}</div>
        <h3 class="card-title" style="margin:6px 0 8px;">${esc(title)}</h3>
        <p class="text-muted" style="font-size:13px;line-height:1.5;margin-bottom:12px;">${esc(body)}</p>
        <a class="btn btn-sm btn-outline">${esc(cta)}</a>
      </div>
    </div>`;
  const allowedCards = [
    crmHas('crm.leads') ? actionCard('Customer records', 'Open leads', 'Search, prioritize, and assign every active opportunity.', 'View leads →', 'crm-leads') : '',
    crmHas('crm.interactions') ? actionCard('Shared activity', 'Log an interaction', 'Capture a call, meeting, note, or follow-up without leaving VGold.', 'Open interactions →', 'crm-interactions') : '',
    actionCard('CRM ↔ Workflow', 'Follow-ups become tasks', 'Every next action appears in Workflow with the lead name and full context.', 'View my tasks →', 'mytasks'),
  ].filter(Boolean).join('');
  const email = data.email;
  const recentLeads = data.recent_leads || [];
  const recentActivity = data.recent_activity || [];
  const emailCards = email ? `
    <h2 class="section-title" style="margin:24px 0 12px;">Email marketing</h2>
    <div class="stats-grid">
      ${stat('Campaigns', email.campaigns, CRM_ICONS.mail || CRM_ICONS.check, 'bg-gradient-primary', crmHas('crm.email') ? 'crm-email' : null)}
      ${stat('Emails sent', email.sent, CRM_ICONS.check, 'bg-gradient-success', crmHas('crm.email') ? 'crm-email' : null)}
      ${stat('Audiences', email.lists, CRM_ICONS.users, 'bg-gradient-warning', crmHas('crm.email') ? 'crm-email' : null)}
      ${stat('Subscribers', email.subscribers, CRM_ICONS.users, 'bg-gradient-info', crmHas('crm.email') ? 'crm-email' : null)}
    </div>` : '';
  const recentLeadRows = recentLeads.map(l => `
    <tr class="clickable-row" onclick="goCrmLead(${l.id})" style="cursor:pointer;">
      <td><strong>${esc(l.display_name || 'Unnamed')}</strong><br><small class="text-muted">${esc(l.company_name || l.lead_type || '—')}</small></td>
      <td>${esc(l.country || '—')}</td>
      <td><span class="badge ${crmStatusBadge(l.status)}">${esc(l.status)}</span></td>
      <td>${esc(l.assigned_name || 'Unassigned')}</td>
      <td class="text-muted">${l.last_interaction ? crmFormatDate(l.last_interaction) : '—'}</td>
    </tr>`).join('');
  const activityItems = recentActivity.map(a => crmTimelineItem(a, { showLead: true })).join('');
  return `
    <div class="crm-native fade-in">
      <div class="page-header">
        <div><h1 class="page-title">CRM Dashboard</h1><p class="page-subtitle">Relationships, with the work attached — leads, conversations, and next actions in one place.</p></div>
        ${crmHas('crm.leads') ? `<button class="btn btn-primary" onclick="openCrmLeadModal()">${CRM_ICONS.plus} Add New Lead</button>` : ''}
      </div>
      <div class="stats-grid">
        ${stat('Active Leads', s.leads, CRM_ICONS.users, 'bg-gradient-primary', 'crm-leads')}
        ${stat('Won', s.won, CRM_ICONS.check, 'bg-gradient-success', 'crm-leads')}
        ${stat('Contacted today', s.contacted_today, CRM_ICONS.phone || CRM_ICONS.check, 'bg-gradient-primary', 'crm-interactions')}
        ${stat('Open Follow-ups', s.follow_ups, CRM_ICONS.refresh, 'bg-gradient-warning', 'crm-interactions')}
        ${stat('Overdue', s.overdue, CRM_ICONS.clock, 'bg-gradient-info', 'crm-interactions')}
      </div>
      ${emailCards}
      <div class="grid grid-3" style="margin-top:16px;">${allowedCards}</div>
      <div class="grid grid-2" style="margin-top:16px;gap:16px;align-items:start;">
        ${crmHas('crm.leads') ? `
        <div class="card">
          <div class="card-header"><h3 class="card-title">Recent leads</h3><a class="btn btn-sm btn-outline" onclick="nav('crm-leads')" style="cursor:pointer;">View all →</a></div>
          <div class="card-body"><div class="table-container"><table class="table">
            <thead><tr><th>Lead</th><th>Country</th><th>Status</th><th>Owner</th><th>Last activity</th></tr></thead>
            <tbody>${recentLeadRows || `<tr><td colspan="5" class="text-center text-muted">No leads yet.</td></tr>`}</tbody>
          </table></div></div>
        </div>` : ''}
        ${crmHas('crm.interactions') ? `
        <div class="card">
          <div class="card-header"><h3 class="card-title">Recent activity</h3><a class="btn btn-sm btn-outline" onclick="nav('crm-interactions')" style="cursor:pointer;">View all →</a></div>
          <div class="card-body">${activityItems ? `<div class="timeline">${activityItems}</div>` : `<div class="text-muted text-center" style="padding:24px;">No activity yet.</div>`}</div>
        </div>` : ''}
      </div>
    </div>`;
}

// Persistent leads-list UI state (filters, sort, page, multi-select).
const CrmLeads = {
  q: '', status: '', priority: '', lead_type: '', lead_source: '', country: '', owner: '',
  page: 1, per_page: 50, sort_by: '', sort_dir: 'DESC',
  selected: new Set(), total: 0, pages: 1,
};
const CRM_LEAD_STATUSES = ['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold','Not Interested'];
const CRM_LEAD_TYPES = ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'];
const CRM_LEAD_SOURCES = ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'];
const CRM_LEAD_PRIORITIES = ['Urgent','High','Medium','Low'];

function crmLeadsQuery() {
  const p = {};
  ['q','status','priority','lead_type','lead_source','country','owner'].forEach(k => { if (CrmLeads[k]) p[k] = CrmLeads[k]; });
  p.page = CrmLeads.page; p.per_page = CrmLeads.per_page;
  if (CrmLeads.sort_by) { p.sort_by = CrmLeads.sort_by; p.sort_dir = CrmLeads.sort_dir; }
  return p;
}

async function renderCrmLeads() {
  if (!crmHas('crm.leads')) return crmAccessDenied('crm.leads');
  if (!State.crmMembers) { try { State.crmMembers = (await API.members()).members || []; } catch(e) { State.crmMembers = []; } }
  const members = State.crmMembers;
  const data = await API.crmLeads(crmLeadsQuery());
  State.crmLeads = data.leads || [];
  CrmLeads.total = data.total || 0;
  CrmLeads.pages = data.pages || 1;

  const opt = (list, sel) => list.map(x => `<option ${x === sel ? 'selected' : ''}>${esc(x)}</option>`).join('');
  const sortArrow = c => CrmLeads.sort_by === c ? (CrmLeads.sort_dir === 'ASC' ? ' ↑' : ' ↓') : '';
  const th = (label, col) => `<th onclick="crmLeadSort('${col}')" style="cursor:pointer;user-select:none;white-space:nowrap;">${label}${sortArrow(col)}</th>`;
  const allChecked = State.crmLeads.length > 0 && State.crmLeads.every(l => CrmLeads.selected.has(l.id));

  const rows = State.crmLeads.map(lead => `
    <tr class="clickable-row" style="cursor:pointer;">
      <td onclick="event.stopPropagation()"><input type="checkbox" class="crm-lead-check" ${CrmLeads.selected.has(lead.id) ? 'checked' : ''} onchange="crmLeadToggle(${lead.id}, this.checked)"></td>
      <td onclick="goCrmLead(${lead.id})"><strong>${esc(lead.display_name || 'Unnamed')}</strong><br><small class="text-muted">${esc(lead.company_name || lead.lead_type || '—')}</small></td>
      <td onclick="goCrmLead(${lead.id})">${esc(lead.country || '—')}</td>
      <td onclick="goCrmLead(${lead.id})"><span class="badge ${crmStatusBadge(lead.status)}">${esc(lead.status)}</span></td>
      <td onclick="goCrmLead(${lead.id})"><span class="badge ${crmPriorityBadge(lead.priority)}">${esc(lead.priority)}</span></td>
      <td onclick="goCrmLead(${lead.id})">${esc(lead.lead_source || '—')}</td>
      <td onclick="goCrmLead(${lead.id})">${esc(lead.assigned_name || 'Unassigned')}</td>
      <td onclick="event.stopPropagation()" style="white-space:nowrap;">
        <button class="btn btn-sm btn-outline" onclick="goCrmLeadEditPage(${lead.id})" title="Edit lead">${CRM_ICONS.edit}</button>
        <button class="btn btn-sm btn-secondary" onclick="openCrmInteractionModal(${lead.id})">Log</button>
      </td>
    </tr>`).join('');

  const start = CrmLeads.total ? (CrmLeads.page - 1) * CrmLeads.per_page + 1 : 0;
  const end = Math.min(CrmLeads.page * CrmLeads.per_page, CrmLeads.total);
  const pager = `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;">
      <span class="text-muted" style="font-size:13px;">${start}–${end} of ${CrmLeads.total}</span>
      <div style="display:flex;gap:6px;">
        <button class="btn btn-sm btn-outline" ${CrmLeads.page <= 1 ? 'disabled' : ''} onclick="crmLeadPage(${CrmLeads.page - 1})">← Prev</button>
        <span class="text-muted" style="font-size:13px;align-self:center;">Page ${CrmLeads.page} / ${CrmLeads.pages || 1}</span>
        <button class="btn btn-sm btn-outline" ${CrmLeads.page >= CrmLeads.pages ? 'disabled' : ''} onclick="crmLeadPage(${CrmLeads.page + 1})">Next →</button>
      </div>
    </div>`;

  return `
    <div class="crm-native fade-in">
      <div class="page-header">
        <div><h1 class="page-title">Leads Management</h1><p class="page-subtitle">One customer record for every conversation, follow-up, and workflow task.</p></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-outline" onclick="openCrmImportWizard()">${CRM_ICONS.plus} Import CSV</button>
          <button class="btn btn-outline" onclick="crmExportLeadsCsv()">Export CSV</button>
          <button class="btn btn-primary" onclick="openCrmLeadModal()">${CRM_ICONS.plus} Add New Lead</button>
        </div>
      </div>

      <div class="card filter-card">
        <div class="card-body">
          <div class="filter-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <div class="form-group filter-group"><label class="form-label">Search</label>
              <input type="text" id="crm-lead-search" class="form-control" value="${esc(CrmLeads.q)}" placeholder="Company, contact, email..." onkeydown="if(event.key==='Enter')crmLeadApplyFilters()"></div>
            <div class="form-group filter-group"><label class="form-label">Status</label>
              <select id="crm-f-status" class="form-control" onchange="crmLeadApplyFilters()"><option value="">All statuses</option>${opt(CRM_LEAD_STATUSES, CrmLeads.status)}</select></div>
            <div class="form-group filter-group"><label class="form-label">Priority</label>
              <select id="crm-f-priority" class="form-control" onchange="crmLeadApplyFilters()"><option value="">All</option>${opt(CRM_LEAD_PRIORITIES, CrmLeads.priority)}</select></div>
            <div class="form-group filter-group"><label class="form-label">Type</label>
              <select id="crm-f-type" class="form-control" onchange="crmLeadApplyFilters()"><option value="">All</option>${opt(CRM_LEAD_TYPES, CrmLeads.lead_type)}</select></div>
            <div class="form-group filter-group"><label class="form-label">Source</label>
              <select id="crm-f-source" class="form-control" onchange="crmLeadApplyFilters()"><option value="">All</option>${opt(CRM_LEAD_SOURCES, CrmLeads.lead_source)}</select></div>
            <div class="form-group filter-group"><label class="form-label">Country</label>
              <input type="text" id="crm-f-country" class="form-control" value="${esc(CrmLeads.country)}" placeholder="Any" onkeydown="if(event.key==='Enter')crmLeadApplyFilters()"></div>
            <div class="form-group filter-group"><label class="form-label">Owner</label>
              <select id="crm-f-owner" class="form-control" onchange="crmLeadApplyFilters()"><option value="">Anyone</option>${members.map(m => `<option value="${m.id}" ${String(m.id) === String(CrmLeads.owner) ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></div>
            <button type="button" class="btn btn-primary" onclick="crmLeadApplyFilters()">${CRM_ICONS.search} Filter</button>
            <button type="button" class="btn btn-outline" onclick="crmLeadResetFilters()">Reset</button>
          </div>
        </div>
      </div>

      <div id="crm-bulk-bar">${crmBulkBarHtml()}</div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">All Leads</h2></div>
        <div class="card-body">
          <div class="table-container">
            <table class="table">
              <thead><tr>
                <th style="width:32px;"><input type="checkbox" ${allChecked ? 'checked' : ''} onchange="crmLeadSelectAllVisible(this.checked)"></th>
                ${th('Company','company_name')}${th('Country','country')}${th('Status','lead_status')}${th('Priority','priority')}${th('Source','lead_source')}${th('Owner','assigned_name')}<th>Actions</th>
              </tr></thead>
              <tbody>${rows || `<tr><td colspan="8" class="text-center text-muted">No leads match these filters.</td></tr>`}</tbody>
            </table>
          </div>
          ${pager}
        </div>
      </div>
    </div>`;
}

function crmBulkBarHtml() {
  const n = CrmLeads.selected.size;
  if (!n) return '';
  return `<div class="alert alert-info" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <strong>${n} selected</strong>
    <button class="btn btn-sm btn-primary" onclick="crmBulkAssignPrompt()">Assign to…</button>
    <button class="btn btn-sm btn-outline" style="color:#B0432B;border-color:#B0432B;" onclick="crmBulkDeletePrompt()">Delete</button>
    <button class="btn btn-sm btn-outline" onclick="crmClearSelection()">Clear</button>
  </div>`;
}
function crmRefreshBulkBar() {
  const el = document.getElementById('crm-bulk-bar');
  if (el) el.innerHTML = crmBulkBarHtml();
}
function crmLeadToggle(id, checked) {
  if (checked) CrmLeads.selected.add(id); else CrmLeads.selected.delete(id);
  crmRefreshBulkBar();
}
function crmLeadSelectAllVisible(checked) {
  (State.crmLeads || []).forEach(l => { if (checked) CrmLeads.selected.add(l.id); else CrmLeads.selected.delete(l.id); });
  document.querySelectorAll('.crm-lead-check').forEach(c => { c.checked = checked; });
  crmRefreshBulkBar();
}
function crmClearSelection() { CrmLeads.selected.clear(); document.querySelectorAll('.crm-lead-check').forEach(c => c.checked = false); crmRefreshBulkBar(); }
function crmLeadApplyFilters() {
  CrmLeads.q = document.getElementById('crm-lead-search')?.value.trim() || '';
  CrmLeads.status = document.getElementById('crm-f-status')?.value || '';
  CrmLeads.priority = document.getElementById('crm-f-priority')?.value || '';
  CrmLeads.lead_type = document.getElementById('crm-f-type')?.value || '';
  CrmLeads.lead_source = document.getElementById('crm-f-source')?.value || '';
  CrmLeads.country = document.getElementById('crm-f-country')?.value.trim() || '';
  CrmLeads.owner = document.getElementById('crm-f-owner')?.value || '';
  CrmLeads.page = 1;
  render();
}
function crmLeadResetFilters() {
  Object.assign(CrmLeads, { q:'', status:'', priority:'', lead_type:'', lead_source:'', country:'', owner:'', page:1, sort_by:'', sort_dir:'DESC' });
  render();
}
function crmLeadSort(col) {
  if (CrmLeads.sort_by === col) CrmLeads.sort_dir = CrmLeads.sort_dir === 'ASC' ? 'DESC' : 'ASC';
  else { CrmLeads.sort_by = col; CrmLeads.sort_dir = 'ASC'; }
  render();
}
function crmLeadPage(p) { if (p < 1 || p > CrmLeads.pages) return; CrmLeads.page = p; render(); }
function crmExportLeadsCsv() {
  const p = crmLeadsQuery();
  delete p.page; delete p.per_page;
  const qs = new URLSearchParams(p).toString();
  window.open('/api/crm/leads/export?' + qs, '_blank');
}
async function crmBulkAssignPrompt() {
  const members = State.crmMembers || [];
  const ids = Array.from(CrmLeads.selected);
  Modal.open({
    title: `Assign ${ids.length} lead${ids.length === 1 ? '' : 's'}`,
    body: `<div class="crm-native"><div class="form-group"><label class="form-label">Assign to</label>
      <select class="form-control" id="crm-bulk-assignee"><option value="">Unassigned</option>${members.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}</select></div></div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="crmBulkAssignConfirm()">Assign</button>`,
  });
}
async function crmBulkAssignConfirm() {
  const val = document.getElementById('crm-bulk-assignee')?.value || '';
  const ids = Array.from(CrmLeads.selected);
  Modal.close();
  try {
    await API.crmBulkAssign(ids, val ? Number(val) : null);
    CrmLeads.selected.clear();
    toast(`${ids.length} lead(s) reassigned`, 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}
async function crmBulkDeletePrompt() {
  const ids = Array.from(CrmLeads.selected);
  const ok = await Modal.confirm({ title: 'Delete leads', message: `Permanently delete ${ids.length} lead(s) and their interactions? This cannot be undone.`, confirmText: 'Delete', danger: true });
  if (!ok) return;
  try {
    await API.crmBulkDelete(ids);
    CrmLeads.selected.clear();
    toast(`${ids.length} lead(s) deleted`, 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

// ===== CSV Import Wizard =====
const CrmImport = { step: 1, headers: [], rows: [], mapping: {}, dedupe: true, result: null };
const CRM_IMPORT_FIELDS = [
  ['company_name','Company name'], ['contact_person','Contact person'], ['email','Email'], ['phone','Phone'],
  ['country','Country'], ['region','Region'], ['lead_type','Lead type'], ['lead_status','Status'],
  ['priority','Priority'], ['lead_source','Source'], ['notes','Notes'],
];
function crmParseCSV(text) {
  const rows = []; let cur = [], field = '', inQ = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQ) {
      if (c === '"') { if (text[i+1] === '"') { field += '"'; i++; } else inQ = false; }
      else field += c;
    } else {
      if (c === '"') inQ = true;
      else if (c === ',') { cur.push(field); field = ''; }
      else if (c === '\n') { cur.push(field); rows.push(cur); cur = []; field = ''; }
      else if (c !== '\r') field += c;
    }
  }
  if (field.length || cur.length) { cur.push(field); rows.push(cur); }
  return rows.filter(r => !(r.length === 1 && r[0].trim() === ''));
}
function openCrmImportWizard() {
  CrmImport.step = 1; CrmImport.headers = []; CrmImport.rows = []; CrmImport.mapping = {}; CrmImport.result = null; CrmImport.dedupe = true;
  crmImportRender();
}
function crmImportRender() {
  let body = '';
  if (CrmImport.step === 1) {
    body = `<div class="crm-native">
      <p class="text-muted" style="margin-bottom:12px;">Upload a CSV file. The first row must contain column headers. You'll map columns to lead fields in the next step.</p>
      <input type="file" accept=".csv,text/csv" class="form-control" onchange="crmImportFile(event)">
      <p class="text-muted" style="font-size:12px;margin-top:8px;">Tip: at minimum include a company or contact-person column.</p>
    </div>`;
  } else if (CrmImport.step === 2) {
    const colOpts = ['<option value="-1">— skip —</option>'].concat(CrmImport.headers.map((h, i) => `<option value="${i}">${esc(h || ('Column ' + (i+1)))}</option>`)).join('');
    const rowsHtml = CRM_IMPORT_FIELDS.map(([key, label]) => `
      <div class="form-row" style="display:flex;gap:12px;align-items:center;margin-bottom:8px;">
        <label class="form-label" style="flex:0 0 140px;margin:0;">${esc(label)}${key === 'company_name' || key === 'contact_person' ? ' <span class="text-muted">*</span>' : ''}</label>
        <select class="form-control crm-map" data-field="${key}" style="flex:1;">${colOpts.replace(`value="${CrmImport.mapping[key]}"`, `value="${CrmImport.mapping[key]}" selected`)}</select>
      </div>`).join('');
    body = `<div class="crm-native">
      <p class="text-muted" style="margin-bottom:12px;">${CrmImport.rows.length} data row(s) detected. Map each lead field to a CSV column (either <em>company name</em> or <em>contact person</em> is required).</p>
      ${rowsHtml}
      <label class="settings-check-row" style="margin-top:10px;display:flex;gap:8px;align-items:center;"><input type="checkbox" id="crm-import-dedupe" ${CrmImport.dedupe ? 'checked' : ''}><span>Skip duplicates (match on email, or company + contact)</span></label>
    </div>`;
  } else {
    const r = CrmImport.result || {};
    body = `<div class="crm-native" style="text-align:center;padding:12px;">
      <div style="font-size:40px;">✅</div>
      <h3 style="margin:8px 0;">Import complete</h3>
      <p><strong>${r.inserted || 0}</strong> imported · <strong>${r.skipped || 0}</strong> skipped</p>
    </div>`;
  }
  const footer = CrmImport.step === 1
    ? `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>`
    : CrmImport.step === 2
      ? `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="crmImportRun()">Import ${CrmImport.rows.length} rows</button>`
      : `<button class="btn-primary" onclick="Modal.close();State.crmLeads=null;render();">Done</button>`;
  Modal.open({ title: 'Import leads' + (CrmImport.step === 2 ? ' · map columns' : ''), body, footer });
}
function crmImportFile(ev) {
  const file = ev.target.files && ev.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    const parsed = crmParseCSV(String(reader.result || ''));
    if (parsed.length < 2) { toast('CSV needs a header row and at least one data row', 'error'); return; }
    CrmImport.headers = parsed[0];
    CrmImport.rows = parsed.slice(1);
    // Auto-guess mapping by header name.
    CrmImport.mapping = {};
    CRM_IMPORT_FIELDS.forEach(([key]) => {
      const idx = CrmImport.headers.findIndex(h => {
        const hh = String(h || '').toLowerCase().replace(/[^a-z]/g, '');
        const kk = key.replace(/[^a-z]/g, '');
        return hh === kk || hh.includes(kk) || (key === 'contact_person' && (hh.includes('contact') || hh.includes('name'))) || (key === 'company_name' && hh.includes('company'));
      });
      CrmImport.mapping[key] = idx;
    });
    CrmImport.step = 2;
    crmImportRender();
  };
  reader.readAsText(file);
}
async function crmImportRun() {
  document.querySelectorAll('.crm-map').forEach(sel => { CrmImport.mapping[sel.dataset.field] = parseInt(sel.value, 10); });
  CrmImport.dedupe = !!document.getElementById('crm-import-dedupe')?.checked;
  const leads = CrmImport.rows.map(r => {
    const o = {};
    CRM_IMPORT_FIELDS.forEach(([key]) => { const idx = CrmImport.mapping[key]; if (idx != null && idx >= 0) o[key] = (r[idx] || '').trim(); });
    return o;
  }).filter(o => (o.company_name || o.contact_person));
  if (!leads.length) { toast('Map company name or contact person first', 'error'); return; }
  try {
    const res = await API.crmImportLeads(leads, CrmImport.dedupe);
    CrmImport.result = res;
    CrmImport.step = 3;
    crmImportRender();
  } catch(e) { toast(e.message, 'error'); }
}

// Renders a single interaction as a design-system timeline item. Preserves the
// CRM↔Workflow follow-up linkage: a next action links to its Workflow task.
function crmTimelineItem(item, opts = {}) {
  const hasFollowUp = !!item.next_action;
  const leadLine = opts.showLead
    ? `<a class="interaction-lead-link" style="cursor:pointer;font-weight:600;" onclick="goCrmLead(${item.lead_id})">${esc(item.lead_name || 'Lead')}</a>`
    : '';
  const followUp = hasFollowUp ? `
    <div class="timeline-notes" style="margin-top:8px;">
      <button class="btn btn-sm ${item.follow_up_completed ? 'btn-success' : 'btn-outline'}"${item.workflow_task_id ? ` onclick="goTaskPage(${item.workflow_task_id})"` : ''} style="cursor:${item.workflow_task_id ? 'pointer' : 'default'};">
        ${item.follow_up_completed ? '✓' : '→'} Next: ${esc(item.next_action)}${item.next_action_date ? ' · ' + crmFormatDate(item.next_action_date) : ''}${item.workflow_task_id ? ' · Open in Workflow' : ''}
      </button>
    </div>` : '';
  return `
    <div class="timeline-item">
      <div class="timeline-marker"></div>
      <div class="timeline-content">
        <div class="timeline-header">
          <span class="badge ${crmInteractionBadge(item.type)}">${esc(item.type)}${leadLine ? ' · ' : ''}</span>
          <span style="display:flex;align-items:center;gap:8px;">
            <span class="timeline-date">${crmFormatDate(item.occurred_at)}</span>
            ${opts.canDelete ? `<button class="btn btn-sm btn-outline" style="color:#B0432B;border-color:#B0432B;padding:2px 8px;" title="Delete interaction" onclick="crmDeleteInteractionConfirm(${item.id})">✕</button>` : ''}
          </span>
        </div>
        ${leadLine ? `<div style="margin-bottom:4px;">${leadLine}${item.company_name && item.company_name !== item.lead_name ? ` <span class="text-muted">· ${esc(item.company_name)}</span>` : ''}</div>` : ''}
        ${item.subject ? `<div class="timeline-subject">${esc(item.subject)}</div>` : ''}
        ${item.notes ? `<div class="timeline-notes">${esc(item.notes)}</div>` : ''}
        ${item.outcome ? `<div class="timeline-notes text-muted">Outcome: ${esc(item.outcome)}</div>` : ''}
        ${followUp}
        <div class="timeline-footer">${esc(item.user_name || '')}</div>
      </div>
    </div>`;
}

const CrmInter = { type: '', follow_up: false, page: 1, per_page: 30, total: 0, pages: 1, type_counts: {} };

function crmInterQuery() {
  const p = { page: CrmInter.page, per_page: CrmInter.per_page };
  if (CrmInter.type) p.type = CrmInter.type;
  if (CrmInter.follow_up) p.follow_up = 1;
  return p;
}

async function renderCrmInteractions() {
  if (!crmHas('crm.interactions')) return crmAccessDenied('crm.interactions');
  const data = await API.crmInteractions(crmInterQuery());
  State.crmInteractions = data.interactions || [];
  CrmInter.total = data.total || 0;
  CrmInter.pages = data.pages || 1;
  CrmInter.type_counts = data.type_counts || {};

  const counts = CrmInter.type_counts;
  const totalAll = Object.values(counts).reduce((a, b) => a + b, 0);
  const chip = (label, val, count) => `<button class="btn btn-sm ${CrmInter.type === val ? 'btn-primary' : 'btn-outline'}" onclick="crmInterSetType('${val}')" style="margin:0 4px 4px 0;">${esc(label)}${count != null ? ` <span class="badge badge-blue">${count}</span>` : ''}</button>`;
  const typeChips = [chip('All', '', totalAll)]
    .concat(Object.keys(counts).sort().map(t => chip(t, t, counts[t])))
    .join('');

  const timeline = State.crmInteractions.map(item => crmTimelineItem(item, { showLead: true, canDelete: true })).join('');

  const start = CrmInter.total ? (CrmInter.page - 1) * CrmInter.per_page + 1 : 0;
  const end = Math.min(CrmInter.page * CrmInter.per_page, CrmInter.total);
  const pager = `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;">
      <span class="text-muted" style="font-size:13px;">${start}–${end} of ${CrmInter.total}</span>
      <div style="display:flex;gap:6px;">
        <button class="btn btn-sm btn-outline" ${CrmInter.page <= 1 ? 'disabled' : ''} onclick="crmInterPage(${CrmInter.page - 1})">← Prev</button>
        <span class="text-muted" style="font-size:13px;align-self:center;">Page ${CrmInter.page} / ${CrmInter.pages || 1}</span>
        <button class="btn btn-sm btn-outline" ${CrmInter.page >= CrmInter.pages ? 'disabled' : ''} onclick="crmInterPage(${CrmInter.page + 1})">Next →</button>
      </div>
    </div>`;

  return `
    <div class="crm-native fade-in">
      <div class="page-header">
        <div><h1 class="page-title">Interactions &amp; Activities</h1><p class="page-subtitle">The customer timeline and the work queue stay synchronized automatically.</p></div>
        <button class="btn btn-primary" onclick="openCrmInteractionModal()">${CRM_ICONS.plus} Log Interaction</button>
      </div>

      <div class="alert alert-info" style="display:flex;gap:10px;align-items:center;">
        <strong>One next action, one task.</strong>
        <span>Adding a follow-up here creates a Workflow task named “Follow-up: Lead Name” with the full interaction context.</span>
      </div>

      <div class="card filter-card">
        <div class="card-body">
          <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;">
            ${typeChips}
            <span style="flex:1;"></span>
            <button class="btn btn-sm ${CrmInter.follow_up ? 'btn-primary' : 'btn-outline'}" onclick="crmInterToggleFollowUp()">${CrmInter.follow_up ? '✓ ' : ''}Follow-ups only</button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Recent Interactions</h3><span class="badge badge-blue">${CrmInter.total} total</span></div>
        <div class="card-body">
          ${timeline ? `<div class="timeline">${timeline}</div>` : `<div class="empty-state" style="padding:40px 20px;text-align:center;"><h3>No Interactions Found</h3><p>${CrmInter.type || CrmInter.follow_up ? 'No interactions match these filters.' : 'Start logging communication with leads.'}</p></div>`}
          ${CrmInter.total > CrmInter.per_page ? pager : ''}
        </div>
      </div>
    </div>`;
}
function crmInterSetType(t) { CrmInter.type = t; CrmInter.page = 1; render(); }
function crmInterToggleFollowUp() { CrmInter.follow_up = !CrmInter.follow_up; CrmInter.page = 1; render(); }
function crmInterPage(p) { if (p < 1 || p > CrmInter.pages) return; CrmInter.page = p; render(); }
async function crmDeleteInteractionConfirm(id) {
  const ok = await Modal.confirm({ title: 'Delete interaction', message: 'Permanently delete this interaction? This cannot be undone.', confirmText: 'Delete', danger: true });
  if (!ok) return;
  try { await API.crmDeleteInteraction(id); toast('Interaction deleted', 'success'); render(); }
  catch(e) { toast(e.message || 'Delete failed', 'error'); }
}

async function renderCrmModule(moduleKey) {
  if (!crmHas(moduleKey)) return crmAccessDenied(moduleKey);
  return renderCrmEmbeddedModule(moduleKey);
}

const CRM_EMBEDDED_MODULES = {
  'crm.dashboard': { title: 'CRM overview', pages: [['Overview', '/crm/dashboard.php']] },
  'crm.leads': { title: 'Leads', pages: [['Leads', '/crm/pages/leads.php'], ['Add lead', '/crm/pages/lead-form.php'], ['Import leads', '/crm/pages/import-leads.php']] },
  'crm.interactions': { title: 'Interactions & follow-ups', pages: [['Interactions', '/crm/pages/interactions.php']] },
  'crm.proposals': { title: 'Proposals', pages: [['Proposals', '/crm/pages/proposals.php'], ['New proposal', '/crm/pages/proposal-form.php']] },
  'crm.email': { title: 'Email marketing', pages: [['Campaigns', '/crm/pages/email-campaigns.php'], ['Templates', '/crm/pages/email-templates.php'], ['Audiences', '/crm/pages/email-lists.php'], ['Email builder', '/crm/pages/email-builder.php']] },
  'crm.communications': { title: 'Calls & WhatsApp', pages: [['VoIP calls', '/crm/pages/voip-dashboard.php'], ['WhatsApp', '/crm/pages/whatsapp-dashboard.php']] },
  'crm.automation': { title: 'Automations', pages: [['Automation rules', '/crm/pages/automation.php']] },
  'crm.reports': { title: 'Reports & exports', pages: [['Reports', '/crm/pages/reports.php'], ['Export data', '/crm/pages/export.php']] },
  'crm.knowledge': { title: 'Knowledge hub', pages: [['Quick guides', '/crm/pages/quick-guides.php']] },
};

function renderCrmEmbeddedModule(moduleKey) {
  const module = CRM_EMBEDDED_MODULES[moduleKey];
  if (!module) return crmAccessDenied(moduleKey);
  const pages = module.pages || [];
  const src = pages[0][1] + (pages[0][1].includes('?') ? '&' : '?') + 'embedded=1';
  return `
    <div class="fade-in crm-page">
      <div class="crm-embedded-head">
        <div><div class="section-label">CRM</div><h1 class="page-title-sm">${esc(module.title)}</h1></div>
        ${pages.length > 1 ? `<nav class="crm-embedded-tabs">${pages.map((page, index) => `<button class="${index === 0 ? 'active' : ''}" onclick="switchCrmEmbedded('${page[1]}', this)">${esc(page[0])}</button>`).join('')}</nav>` : ''}
      </div>
      <div class="crm-embedded-frame-wrap"><iframe id="crm-embedded-frame" class="crm-embedded-frame" src="${src}" title="${esc(module.title)}" loading="eager"></iframe></div>
    </div>`;
}

function switchCrmEmbedded(url, button) {
  const frame = document.getElementById('crm-embedded-frame');
  if (!frame) return;
  document.querySelectorAll('.crm-embedded-tabs button').forEach(item => item.classList.toggle('active', item === button));
  frame.src = url + (url.includes('?') ? '&' : '?') + 'embedded=1';
}

// Consistent inline error box for modal bodies (styled regardless of scope).
function crmErrorBox(id) {
  return `<div id="${id}" style="display:none;margin-top:12px;padding:10px 12px;border-radius:8px;background:#fde8e8;color:#b3261e;font-size:13px;font-weight:600;"></div>`;
}

async function openCrmLeadModal() {
  const members = (await API.members()).members || [];
  const statuses = ['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'];
  Modal.open({
    title: 'Add New Lead',
    body: `<div class="crm-native">
      <div class="grid grid-2">
        ${crmInput('crm-contact','Lead name','Contact person or owner')}
        ${crmInput('crm-company','Company / stable','Organization name')}
        ${crmInput('crm-email','Email','name@example.com','email')}
        ${crmInput('crm-phone','Phone','+34 …','tel')}
        ${crmInput('crm-country','Country','Country')}
        <div class="form-group"><label class="form-label">Owner</label><select class="form-control" id="crm-assignee">${members.map(m => `<option value="${m.id}" ${m.id === State.user.id ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></div>
        <div class="form-group"><label class="form-label">Status</label><select class="form-control" id="crm-status">${statuses.map(x => `<option>${x}</option>`).join('')}</select></div>
        <div class="form-group"><label class="form-label">Priority</label><select class="form-control" id="crm-priority">${['Medium','High','Urgent','Low'].map(x => `<option>${x}</option>`).join('')}</select></div>
      </div>
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" id="crm-notes" rows="4" placeholder="Background, goals, or context"></textarea></div>
      ${crmErrorBox('crm-form-error')}
    </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveCrmLead()">Save Lead</button>`,
  });
}

async function saveCrmLead() {
  const err = document.getElementById('crm-form-error');
  try {
    await API.createCrmLead({
      contact_person: document.getElementById('crm-contact').value,
      company_name: document.getElementById('crm-company').value,
      email: document.getElementById('crm-email').value,
      phone: document.getElementById('crm-phone').value,
      country: document.getElementById('crm-country').value,
      assigned_to: Number(document.getElementById('crm-assignee').value),
      status: document.getElementById('crm-status').value,
      priority: document.getElementById('crm-priority').value,
      notes: document.getElementById('crm-notes').value,
    });
    State.crmDashboard = null;
    Modal.close();
    toast('Lead added', 'success');
    render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}

async function openCrmInteractionModal(leadId = null, presetType = null) {
  const leads = (await API.crmLeadOptions()).leads || [];
  if (!leads.length) { toast('Add a lead before logging an interaction', 'error'); return; }
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0,16);
  Modal.open({
    title: presetType === 'Meeting' ? 'Schedule Meeting' : 'Log New Interaction',
    body: `<div class="crm-native">
      <div class="form-group"><label class="form-label">Select Lead</label><select class="form-control" id="crm-ix-lead">${leads.map(l => `<option value="${l.id}" ${l.id === leadId ? 'selected' : ''}>${esc(l.name)}${l.company && l.company !== l.name ? ' — ' + esc(l.company) : ''}</option>`).join('')}</select></div>
      <div class="grid grid-2">
        <div class="form-group"><label class="form-label">Interaction Type</label><select class="form-control" id="crm-ix-type" onchange="crmToggleFollowUpFields()">${['Call','Email','Meeting','Demo','Follow-up','Note','WhatsApp','SMS'].map(x => `<option ${x === presetType ? 'selected' : ''}>${x}</option>`).join('')}</select></div>
        ${crmInput('crm-ix-date','Date & time','', 'datetime-local', local)}
      </div>
      ${crmInput('crm-ix-subject','Subject','What happened or what is needed?')}
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" id="crm-ix-notes" rows="4" placeholder="Conversation details and useful context"></textarea></div>
      <div class="form-group"><label class="form-label">Outcome</label><select class="form-control" id="crm-ix-outcome"><option value="">No outcome</option>${['Positive','Neutral','Negative','No Response'].map(x => `<option>${x}</option>`).join('')}</select></div>
      <div class="card" style="margin:4px 0 0;background:var(--color-bg);">
        <div class="card-body">
          <div class="sidebar-label" style="margin-bottom:2px;">Workflow bridge</div>
          <p class="text-muted" style="font-size:12px;margin-bottom:12px;">Complete these fields to create a task automatically.</p>
          <div class="grid grid-2">
            ${crmInput('crm-ix-next','Next action','e.g. Send pricing and arrange a call')}
            ${crmInput('crm-ix-next-date','Due date','', 'date')}
          </div>
        </div>
      </div>
      ${crmErrorBox('crm-ix-error')}
    </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveCrmInteraction()">Log Interaction</button>`,
  });
}

async function saveCrmInteraction() {
  const err = document.getElementById('crm-ix-error');
  try {
    const result = await API.createCrmInteraction({
      lead_id: Number(document.getElementById('crm-ix-lead').value),
      type: document.getElementById('crm-ix-type').value,
      occurred_at: document.getElementById('crm-ix-date').value,
      subject: document.getElementById('crm-ix-subject').value,
      notes: document.getElementById('crm-ix-notes').value,
      outcome: document.getElementById('crm-ix-outcome').value,
      next_action: document.getElementById('crm-ix-next').value,
      next_action_date: document.getElementById('crm-ix-next-date').value,
    });
    State.crmDashboard = null;
    State.projects = null;
    Modal.close();
    toast(result.workflow_task_id ? 'Interaction saved and Workflow task created' : 'Interaction saved', 'success');
    if (State.screen === 'crm-leads') nav('crm-interactions'); else render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}

// ===== Native lead detail =====
function goCrmLead(id) {
  State.screen = 'crm-lead';
  State.crmLeadEditMode = false;
  State.activeCrmLeadId = Number(id);
  State.activeProjectId = null;
  State.activeProject = null;
  State.activeCategoryId = null;
  updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
  closeMobileSidebar();
}

function crmDetailRow(label, value, opts = {}) {
  if (value === null || value === undefined || value === '') return '';
  const inner = opts.href
    ? `<a href="${esc(value.startsWith('http') ? value : 'https://' + value)}" target="_blank" rel="noopener">${esc(opts.text || value)}</a>`
    : opts.mailto
    ? `<a href="mailto:${esc(value)}">${esc(value)}</a>`
    : opts.tel
    ? `<a href="tel:${esc(value)}">${esc(value)}</a>`
    : esc(value);
  return `<div class="detail-item${opts.span2 ? ' detail-span-2' : ''}"><div class="detail-label">${esc(label)}</div><div class="detail-value">${inner}</div></div>`;
}

async function renderCrmLeadDetail(id) {
  if (!crmHas('crm.leads')) return crmAccessDenied('crm.leads');
  if (!id) { nav('crm-leads'); return ''; }
  // Native full-page edit screen (replaces the old edit popup). Reuses the same
  // 'crm-lead' screen dispatch — no app.js router changes needed.
  if (State.crmLeadEditMode) return renderCrmLeadEditPage(id);
  let data;
  try {
    data = await API.crmLeadDetail(id);
  } catch (e) {
    return `<div class="crm-native fade-in"><div class="empty-state card" style="padding:56px 24px;max-width:560px;margin:48px auto;text-align:center;"><h3>Lead unavailable</h3><p>${esc(e.message || 'This lead could not be loaded.')}</p><button class="btn btn-outline" onclick="nav('crm-leads')" style="margin-top:16px;">${CRM_ICONS.back} Back to leads</button></div></div>`;
  }
  const lead = data.lead || {};
  State.crmLeadDetail = lead;
  const interactions = data.interactions || [];

  // Social media links — only rendered if at least one is present.
  const socialDefs = [
    ['facebook', lead.facebook_url, 'Facebook'],
    ['instagram', lead.instagram_url, 'Instagram'],
    ['linkedin', lead.linkedin_url, 'LinkedIn'],
    ['twitter', lead.twitter_url, 'Twitter'],
    ['youtube', lead.youtube_url, 'YouTube'],
  ].filter(([, v]) => v);
  const socialCard = socialDefs.length ? `
    <div class="card">
      <div class="card-header"><h3 class="card-title">Social Media</h3></div>
      <div class="card-body"><div class="social-links-detail">
        ${socialDefs.map(([cls, v, label]) => `<a href="${esc(String(v).startsWith('http') ? v : 'https://' + v)}" target="_blank" rel="noopener" class="social-link-btn ${cls}">${label}</a>`).join('')}
      </div></div>
    </div>` : '';

  // Quick-stat tiles — only tiles we can derive from the API response.
  const followUpCount = interactions.filter(i => i.next_action).length;
  const tiles = [
    ['Interactions', interactions.length, CRM_ICONS.message, 'bg-gradient-info'],
    ['Follow-ups', followUpCount, CRM_ICONS.refresh, 'bg-gradient-warning'],
    lead.created_at ? ['Created', crmFormatDate(lead.created_at), CRM_ICONS.calendar, 'bg-gradient-primary'] : null,
    lead.updated_at ? ['Updated', crmFormatDate(lead.updated_at), CRM_ICONS.clock, 'bg-gradient-success'] : null,
  ].filter(Boolean);
  const tilesRow = tiles.map(([label, value, icon, tone]) => `
    <div class="stat-card"><div class="stat-icon ${tone}">${icon}</div><div class="stat-content"><div class="stat-value">${esc(String(value))}</div><div class="stat-label">${esc(label)}</div></div></div>`).join('');

  const timeline = interactions.map(item => crmTimelineItem(item)).join('');
  const location = [lead.city, lead.country].filter(Boolean).join(', ');

  // Quick-action availability guards (mirror lead-detail.php: use phone else mobile).
  const hasNumber = !!(lead.phone || lead.mobile);
  const hasEmail = !!lead.email;
  const disN = hasNumber ? '' : 'disabled title="No phone number on this lead"';
  const disE = hasEmail ? '' : 'disabled title="No email address on this lead"';
  const quickActions = (size) => `
    <button class="btn ${size} btn-primary" onclick="crmLeadVoipCall(${lead.id})" ${disN}>${CRM_ICONS.phone} VoIP Call</button>
    <button class="btn ${size} btn-success" onclick="crmLeadWhatsApp(${lead.id})" ${disN}>${CRM_ICONS.message} WhatsApp Message</button>
    <button class="btn ${size} btn-danger" onclick="openCrmLeadEmail(${lead.id})" ${disE}>${CRM_ICONS.mail} Send Email</button>
    <button class="btn ${size} btn-info" onclick="openCrmInteractionModal(${lead.id})">Log Interaction</button>
    <button class="btn ${size} btn-success" onclick="openCrmInteractionModal(${lead.id}, 'Meeting')">${CRM_ICONS.calendar} Schedule Meeting</button>
    <button class="btn ${size} btn-warning" onclick="goCrmLeadEditPage(${lead.id})">${CRM_ICONS.edit} Edit Lead</button>`;

  return `
    <div class="crm-native fade-in">
      <div class="lead-header">
        <div class="lead-header-left">
          <div class="lead-avatar">${esc((lead.display_name || '??').slice(0,2).toUpperCase())}</div>
          <div>
            <h1 class="lead-title">${esc(lead.display_name || 'Lead')}</h1>
            <div class="lead-meta">
              <span class="badge ${crmStatusBadge(lead.status)}">${esc(lead.status)}</span>
              ${lead.lead_type ? `<span class="badge badge-outline">${esc(lead.lead_type)}</span>` : ''}
              ${lead.priority ? `<span class="badge ${crmPriorityBadge(lead.priority)}">${esc(lead.priority)}</span>` : ''}
              ${location ? `<span class="text-muted">${esc(location)}</span>` : ''}
            </div>
          </div>
        </div>
        <div class="lead-header-actions">
          ${quickActions('btn-sm')}
          <button class="btn btn-sm btn-outline" onclick="nav('crm-leads')">${CRM_ICONS.back} Back</button>
        </div>
      </div>

      ${tilesRow ? `<div class="grid grid-4 mb-2">${tilesRow}</div>` : ''}

      <div class="grid grid-3">
        <div class="detail-main">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Contact Information</h3></div>
            <div class="card-body"><div class="grid grid-2">
              ${crmDetailRow('Contact Person', lead.contact_person)}
              ${crmDetailRow('Title/Position', lead.title_position)}
              ${crmDetailRow('Email', lead.email, { mailto: true })}
              ${crmDetailRow('Phone', lead.phone, { tel: true })}
              ${crmDetailRow('Mobile', lead.mobile, { tel: true })}
              ${crmDetailRow('Website', lead.website, { href: true, text: lead.website })}
            </div></div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">Location &amp; Facility</h3></div>
            <div class="card-body"><div class="grid grid-2">
              ${crmDetailRow('Country', lead.country)}
              ${crmDetailRow('City', lead.city)}
              ${crmDetailRow('Facility Type', lead.facility_type)}
              ${crmDetailRow('Number of Horses', lead.number_of_horses)}
              ${crmDetailRow('Horse Breed', lead.horse_breed)}
              ${crmDetailRow('Horse Sex', lead.horse_sex)}
              ${crmDetailRow('Address', lead.address, { span2: true })}
              ${crmDetailRow('Specialization', lead.specialization, { span2: true })}
            </div></div>
          </div>

          ${socialCard}

          ${lead.notes ? `<div class="card"><div class="card-header"><h3 class="card-title">Notes</h3></div><div class="card-body"><p style="white-space:pre-wrap;line-height:1.6;">${esc(lead.notes)}</p></div></div>` : ''}

          <div class="card">
            <div class="card-header">
              <h3 class="card-title">Recent Interactions <span class="badge badge-secondary">${interactions.length}</span></h3>
              <a class="btn btn-sm btn-primary" onclick="openCrmInteractionModal(${lead.id})" style="cursor:pointer;">Log Interaction</a>
            </div>
            <div class="card-body">
              ${timeline ? `<div class="timeline">${timeline}</div>` : `<p class="text-muted text-center">No interactions recorded yet</p>`}
            </div>
          </div>
        </div>

        <div>
          <div class="card">
            <div class="card-header"><h3 class="card-title">Lead Management</h3></div>
            <div class="card-body">
              <div class="sidebar-item"><div class="sidebar-label">Assigned To</div><div class="sidebar-value">${esc(lead.assigned_name || 'Unassigned')}</div></div>
              ${lead.created_name ? `<div class="sidebar-item"><div class="sidebar-label">Created By</div><div class="sidebar-value">${esc(lead.created_name)}</div></div>` : ''}
              ${lead.lead_source ? `<div class="sidebar-item"><div class="sidebar-label">Lead Source</div><div class="sidebar-value">${esc(lead.lead_source)}</div></div>` : ''}
              ${lead.created_at ? `<div class="sidebar-item"><div class="sidebar-label">Created</div><div class="sidebar-value">${crmFormatDate(lead.created_at)}</div></div>` : ''}
              ${lead.updated_at ? `<div class="sidebar-item"><div class="sidebar-label">Updated</div><div class="sidebar-value">${crmFormatDate(lead.updated_at)}</div></div>` : ''}
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title">Quick Actions</h3></div>
            <div class="card-body quick-actions">
              ${quickActions('btn-block')}
            </div>
          </div>
        </div>
      </div>
    </div>`;
}

async function openCrmLeadEditModal(id) {
  const lead = (State.crmLeadDetail && State.crmLeadDetail.id === Number(id)) ? State.crmLeadDetail : (await API.crmLeadDetail(id)).lead;
  const members = (await API.members()).members || [];
  const sel = (id2, label, value, options) => `<div class="form-group"><label class="form-label">${esc(label)}</label><select class="form-control" id="${id2}">${options.map(o => `<option ${o === (value || '') ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`;
  const val = v => esc(v == null ? '' : String(v));
  Modal.open({
    title: 'Edit Lead',
    body: `<div class="crm-native">
      <div class="grid grid-2">
        ${crmInput('cl-contact','Contact Person','Contact person','text', val(lead.contact_person))}
        ${crmInput('cl-company','Company / Stable','Organization','text', val(lead.company_name))}
        ${crmInput('cl-title','Title / Position','','text', val(lead.title_position))}
        ${crmInput('cl-email','Email','name@example.com','email', val(lead.email))}
        ${crmInput('cl-phone','Phone','','tel', val(lead.phone))}
        ${crmInput('cl-mobile','Mobile','','tel', val(lead.mobile))}
        ${crmInput('cl-website','Website','https://','text', val(lead.website))}
        ${crmInput('cl-country','Country','','text', val(lead.country))}
        ${crmInput('cl-city','City','','text', val(lead.city))}
        ${sel('cl-region','Region', lead.region, ['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other'])}
        <div class="form-group"><label class="form-label">Owner</label><select class="form-control" id="cl-assignee"><option value="">Unassigned</option>${members.map(m => `<option value="${m.id}" ${m.id === lead.assigned_to ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></div>
        ${sel('cl-type','Lead Type', lead.lead_type, ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'])}
        ${sel('cl-status','Status', lead.status, ['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'])}
        ${sel('cl-priority','Priority', lead.priority, ['Low','Medium','High','Urgent'])}
        ${sel('cl-source','Lead Source', lead.lead_source, ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'])}
        ${sel('cl-facility','Facility Type', lead.facility_type, ['','Breeding','Racing','Training','Multi-Purpose','Other'])}
        ${crmInput('cl-horses','Number of Horses','','number', val(lead.number_of_horses))}
        ${crmInput('cl-specialization','Specialization','','text', val(lead.specialization))}
      </div>
      <div class="form-group"><label class="form-label">Address</label><input class="form-control" id="cl-address" value="${val(lead.address)}"></div>
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" id="cl-notes" rows="4">${val(lead.notes)}</textarea></div>
      ${crmErrorBox('cl-error')}
    </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveCrmLeadEdit(${lead.id})">Save Changes</button>`,
  });
}

async function saveCrmLeadEdit(id) {
  const err = document.getElementById('cl-error');
  const g = i => document.getElementById(i)?.value ?? '';
  try {
    await API.updateCrmLead(id, {
      contact_person: g('cl-contact'), company_name: g('cl-company'), title_position: g('cl-title'),
      email: g('cl-email'), phone: g('cl-phone'), mobile: g('cl-mobile'), website: g('cl-website'),
      country: g('cl-country'), city: g('cl-city'), region: g('cl-region'), address: g('cl-address'),
      assigned_to: g('cl-assignee') ? Number(g('cl-assignee')) : '',
      lead_type: g('cl-type'), status: g('cl-status'), priority: g('cl-priority'),
      lead_source: g('cl-source'), facility_type: g('cl-facility'),
      number_of_horses: g('cl-horses'), specialization: g('cl-specialization'), notes: g('cl-notes'),
    });
    State.crmLeadDetail = null;
    State.crmLeads = null;
    State.crmDashboard = null;
    Modal.close();
    toast('Lead updated', 'success');
    render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}

// ===== Quick-action handlers (reuse crm-modules.js globals) =====
// Returns the currently-loaded lead if it matches the requested id.
function crmActiveLead(leadId) {
  const l = State.crmLeadDetail;
  return (l && Number(l.id) === Number(leadId)) ? l : null;
}

// VoIP Call — reuse the native softphone (voipOpenSoftphone) from crm-modules.js.
// It is self-contained (opens its own Modal + fetches a Twilio token), so it does
// not require the Communications screen to be mounted. We prefill the lead's number.
function crmLeadVoipCall(leadId) {
  const lead = crmActiveLead(leadId);
  // crmLeadPhone skips placeholder values ("NA", "-", "N/A") that imported rows
  // carry in phone/mobile and returns the first genuinely dialable field.
  const number = (typeof crmLeadPhone === 'function') ? crmLeadPhone(lead) : (lead ? (lead.phone || lead.mobile || '') : '');
  if (!number) { toast('No valid phone number on this lead. Add one first.', 'error'); return; }
  if (typeof voipOpenSoftphone !== 'function') { toast('Softphone is unavailable.', 'error'); return; }
  voipOpenSoftphone(number, leadId);
}

// WhatsApp — reuse the native chat modal (waOpenChat) from crm-modules.js. We seed
// CrmMod.cache.waChats so waOpenChat shows the right name and waSend finds the number
// even when the Communications screen was never visited.
function crmLeadWhatsApp(leadId) {
  const lead = crmActiveLead(leadId);
  const number = (typeof crmLeadPhone === 'function') ? crmLeadPhone(lead) : (lead ? (lead.phone || lead.mobile || '') : '');
  if (!number) { toast('No valid phone number on this lead. Add one first.', 'error'); return; }
  if (typeof waOpenChat !== 'function' || typeof CrmMod === 'undefined') { toast('WhatsApp is unavailable.', 'error'); return; }
  const name = lead.contact_person || lead.company_name || lead.display_name || ('Lead #' + leadId);
  CrmMod.cache.waChats = CrmMod.cache.waChats || { data: [] };
  const list = CrmMod.cache.waChats.data || (CrmMod.cache.waChats.data = []);
  let entry = list.find(x => Number(x.lead_id) === Number(leadId));
  if (!entry) { entry = { lead_id: Number(leadId) }; list.push(entry); }
  entry.contact_person = name;
  entry.company_name = lead.company_name || entry.company_name;
  entry.mobile = lead.mobile || number;
  entry.phone = lead.phone || number;
  // Pass the resolved number explicitly so the modal never falls back to a
  // placeholder like "NA" (which produced `whatsapp:+` → Twilio HTTP 400).
  waOpenChat(leadId, number, name);
}

// Send Email — native compose modal posting to the legacy send-email.php endpoint
// via crmApiPost (which attaches the csrf_token field automatically).
function openCrmLeadEmail(leadId) {
  const lead = crmActiveLead(leadId);
  const to = lead ? (lead.email || '') : '';
  if (!to) { toast('No email address on this lead.', 'error'); return; }
  Modal.open({
    title: 'Compose Email',
    body: `<div class="crm-native">
      <div class="form-group"><label class="form-label">To</label><input class="form-control" id="cle-to" type="email" value="${esc(to)}"></div>
      <div class="form-group"><label class="form-label">Cc <small class="text-muted">(optional, comma-separated)</small></label><input class="form-control" id="cle-cc" type="text" placeholder="cc1@example.com, cc2@example.com"></div>
      ${crmInput('cle-subject', 'Subject', 'Enter subject…')}
      <div class="form-group"><label class="form-label">Message</label><textarea class="form-control" id="cle-body" rows="10" placeholder="Write your email message here…"></textarea></div>
      ${crmErrorBox('cle-email-error')}
    </div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="sendCrmLeadEmail(${leadId})">${CRM_ICONS.mail} Send Email</button>`,
  });
}
async function sendCrmLeadEmail(leadId) {
  const err = document.getElementById('cle-email-error');
  const g = i => (document.getElementById(i)?.value || '').trim();
  const to = g('cle-to'), cc = g('cle-cc'), subject = g('cle-subject'), body = g('cle-body');
  if (!to || !subject || !body) {
    if (err) { err.textContent = 'To, subject, and message are all required.'; err.style.display = 'block'; }
    return;
  }
  if (typeof crmApiPost !== 'function') { if (err) { err.textContent = 'Email service is unavailable.'; err.style.display = 'block'; } return; }
  try {
    await crmApiPost('send-email.php', { lead_id: Number(leadId), to, cc, subject, body });
    State.crmLeadDetail = null;
    Modal.close();
    toast('Email sent', 'success');
    render();
  } catch (e) { if (err) { err.textContent = e.message; err.style.display = 'block'; } }
}

// ===== Native full-page Edit Lead (replaces openCrmLeadEditModal) =====
function goCrmLeadEditPage(id) {
  State.screen = 'crm-lead';
  State.activeCrmLeadId = Number(id);
  State.crmLeadEditMode = true;
  updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
  closeMobileSidebar();
}
function closeCrmLeadEdit() {
  State.crmLeadEditMode = false;
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
}

// Renders the full-width edit form (reproducing lead-form.php sections) inside
// `.crm-native`. Reached only when State.crmLeadEditMode is set.
async function renderCrmLeadEditPage(id) {
  let lead, members;
  try {
    lead = crmActiveLead(id) || (await API.crmLeadDetail(id)).lead;
    members = (await API.members()).members || [];
  } catch (e) {
    return `<div class="crm-native fade-in"><div class="empty-state card" style="padding:56px 24px;max-width:560px;margin:48px auto;text-align:center;"><h3>Lead unavailable</h3><p>${esc(e.message || 'This lead could not be loaded.')}</p><button class="btn btn-outline" onclick="closeCrmLeadEdit()" style="margin-top:16px;">${CRM_ICONS.back} Back</button></div></div>`;
  }
  State.crmLeadDetail = lead;
  const val = v => esc(v == null ? '' : String(v));
  const sel = (sid, label, value, options) => `<div class="form-group"><label class="form-label">${esc(label)}</label><select class="form-control" id="${sid}">${options.map(o => `<option value="${esc(o)}" ${String(o) === String(value == null ? '' : value) ? 'selected' : ''}>${o === '' ? 'Select…' : esc(o)}</option>`).join('')}</select></div>`;
  const memberOptions = `<option value="">Unassigned</option>${members.map(m => `<option value="${m.id}" ${m.id === lead.assigned_to ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}`;

  return `
    <div class="crm-native fade-in">
      <div class="page-header">
        <div><h1 class="page-title">Edit Lead</h1><p class="page-subtitle">${esc(lead.display_name || lead.contact_person || lead.company_name || ('Lead #' + lead.id))}</p></div>
        <div class="lead-header-actions">
          <button class="btn btn-outline" onclick="closeCrmLeadEdit()">${CRM_ICONS.back} Back</button>
          <button class="btn btn-primary" onclick="saveCrmLeadEditPage(${lead.id})">Save</button>
        </div>
      </div>

      ${crmErrorBox('cle-error')}

      <div class="card">
        <div class="card-header"><h2 class="card-title">Contact Details</h2></div>
        <div class="card-body">
          <div class="grid grid-2">
            ${crmInput('cle-contact', 'Contact Person', 'Contact person', 'text', val(lead.contact_person))}
            ${crmInput('cle-title', 'Title / Position', '', 'text', val(lead.title_position))}
            ${crmInput('cle-country', 'Country', '', 'text', val(lead.country))}
            ${crmInput('cle-horses', 'Number of Horses', '', 'number', val(lead.number_of_horses))}
            ${crmInput('cle-phone', 'Phone', '', 'tel', val(lead.phone))}
            ${crmInput('cle-mobile', 'Mobile', '', 'tel', val(lead.mobile))}
          </div>
          ${crmInput('cle-email', 'Email', 'email@example.com', 'text', val(lead.email))}
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Company Information</h2></div>
        <div class="card-body">
          ${crmInput('cle-company', 'Company Name', '', 'text', val(lead.company_name))}
          <div class="grid grid-2">
            ${sel('cle-type', 'Lead Type', lead.lead_type, ['', 'Stable', 'Owner', 'Breeder', 'Trainer', 'Veterinarian', 'Consultant', 'Other'])}
            ${sel('cle-facility', 'Facility Type', lead.facility_type, ['', 'Breeding', 'Racing', 'Training', 'Multi-Purpose', 'Other'])}
          </div>
          <div class="grid grid-2">
            ${crmInput('cle-spec', 'Specialization', 'e.g., Thoroughbred Breeding', 'text', val(lead.specialization))}
            ${crmInput('cle-breed', 'Horse Breed', 'e.g., Arabian, Thoroughbred', 'text', val(lead.horse_breed))}
          </div>
          <div class="grid grid-2">
            ${sel('cle-sex', 'Horse Sex', lead.horse_sex, ['', 'Stallion', 'Mare', 'Gelding', 'Colt', 'Filly', 'Mixed'])}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Location</h2></div>
        <div class="card-body">
          <div class="grid grid-2">
            ${crmInput('cle-city', 'City', '', 'text', val(lead.city))}
          </div>
          <div class="form-group"><label class="form-label">Address</label><textarea class="form-control" id="cle-address" rows="3">${val(lead.address)}</textarea></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Social Media &amp; Website</h2></div>
        <div class="card-body">
          ${crmInput('cle-website', 'Website', 'https://', 'text', val(lead.website))}
          <div class="grid grid-2">
            ${crmInput('cle-fb', 'Facebook URL', '', 'text', val(lead.facebook_url))}
            ${crmInput('cle-ig', 'Instagram URL', '', 'text', val(lead.instagram_url))}
          </div>
          <div class="grid grid-3">
            ${crmInput('cle-li', 'LinkedIn URL', '', 'text', val(lead.linkedin_url))}
            ${crmInput('cle-tw', 'Twitter URL', '', 'text', val(lead.twitter_url))}
            ${crmInput('cle-yt', 'YouTube URL', '', 'text', val(lead.youtube_url))}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Lead Details</h2></div>
        <div class="card-body">
          <div class="grid grid-2">
            ${sel('cle-status', 'Lead Status', lead.status, ['New Lead', 'Contacted', 'Interested', 'Not Interested', 'Schedule Call', 'Call Scheduled', 'Demo Scheduled', 'Proposal Sent', 'Negotiation', 'Won', 'Lost', 'On Hold'])}
            ${sel('cle-priority', 'Priority', lead.priority, ['Low', 'Medium', 'High', 'Urgent'])}
            ${sel('cle-source', 'Lead Source', lead.lead_source, ['Website', 'Facebook', 'Instagram', 'Google Ads', 'LinkedIn', 'Referral', 'Cold Outreach', 'Event', 'Import', 'Other'])}
            <div class="form-group"><label class="form-label">Assign To</label><select class="form-control" id="cle-assignee">${memberOptions}</select></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="card-title">Notes</h2></div>
        <div class="card-body">
          <div class="form-group"><textarea class="form-control" id="cle-notes" rows="5" placeholder="Add any additional information about this lead...">${val(lead.notes)}</textarea></div>
        </div>
      </div>

      <div class="form-actions" style="display:flex;gap:12px;justify-content:flex-end;margin-top:8px;">
        <button class="btn btn-outline" onclick="closeCrmLeadEdit()">Cancel</button>
        <button class="btn btn-primary" onclick="saveCrmLeadEditPage(${lead.id})">Save Changes</button>
      </div>
    </div>`;
}

async function saveCrmLeadEditPage(id) {
  const err = document.getElementById('cle-error');
  if (err) err.style.display = 'none';
  const g = i => document.getElementById(i)?.value ?? '';
  try {
    await API.updateCrmLead(id, {
      contact_person: g('cle-contact'), title_position: g('cle-title'), company_name: g('cle-company'),
      email: g('cle-email'), phone: g('cle-phone'), mobile: g('cle-mobile'), website: g('cle-website'),
      country: g('cle-country'), city: g('cle-city'), address: g('cle-address'),
      lead_type: g('cle-type'), facility_type: g('cle-facility'),
      number_of_horses: g('cle-horses'), specialization: g('cle-spec'),
      horse_breed: g('cle-breed'), horse_sex: g('cle-sex'),
      facebook_url: g('cle-fb'), instagram_url: g('cle-ig'), linkedin_url: g('cle-li'),
      twitter_url: g('cle-tw'), youtube_url: g('cle-yt'),
      status: g('cle-status'), priority: g('cle-priority'), lead_source: g('cle-source'),
      assigned_to: g('cle-assignee') ? Number(g('cle-assignee')) : '',
      notes: g('cle-notes'),
    });
    State.crmLeadEditMode = false;
    State.crmLeadDetail = null;
    State.crmLeads = null;
    State.crmDashboard = null;
    toast('Lead updated', 'success');
    render();
    document.querySelector('.main')?.scrollTo(0, 0);
  } catch (e) { if (err) { err.textContent = e.message; err.style.display = 'block'; } }
}

function crmInput(id, label, placeholder, type = 'text', value = '') {
  return `<div class="form-group">${crmInputInner(id, label, placeholder, type, value)}</div>`;
}
function crmToggleFollowUpFields() {
  const type = document.getElementById('crm-ix-type')?.value;
  const next = document.getElementById('crm-ix-next');
  if (type === 'Follow-up' && next && !next.value) next.value = 'Follow up with lead';
}
function crmInputInner(id, label, placeholder, type = 'text', value = '') {
  return `<label class="form-label" for="${id}">${esc(label)}</label><input class="form-control" id="${id}" type="${type}" value="${esc(value)}" placeholder="${esc(placeholder)}">`;
}
function crmStatusClass(status) { return String(status || '').toLowerCase().replace(/[^a-z]+/g, '-'); }
function crmInteractionClass(type) { return 'type-' + String(type || '').toLowerCase().replace(/[^a-z]+/g, '-'); }
function crmFormatDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? esc(value) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
