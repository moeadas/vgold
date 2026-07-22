// VGold native CRM views — same shell, session, permissions, and task model as Workflow.

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

function crmAccessDenied(moduleKey) {
  const label = CRM_MODULE_COPY[moduleKey]?.title || 'CRM';
  return `<div class="fade-in crm-page"><div class="crm-empty card card-pad"><div class="crm-empty-mark">VG</div><h2>${esc(label)} access is not enabled</h2><p>Ask a VGold administrator to enable this module in Settings → Team module access.</p></div></div>`;
}

async function renderCrmDashboard() {
  if (!(State.user?.modules || []).length) return crmAccessDenied('crm.dashboard');
  return renderCrmEmbeddedModule('crm.dashboard');
  let data = State.crmDashboard;
  if (!data) {
    data = await API.crmDashboard();
    State.crmDashboard = data;
  }
  const s = data.stats || {};
  const stat = (label, value, tone, target) => value === null ? '' : `
    <button class="crm-stat ${tone}" onclick="nav('${target}')">
      <span class="crm-stat-value">${value}</span><span class="crm-stat-label">${label}</span>
    </button>`;
  const allowedCards = [
    crmHas('crm.leads') ? `<button class="crm-action-card" onclick="nav('crm-leads')"><span class="crm-action-kicker">Customer records</span><strong>Open leads</strong><span>Search, prioritize, and assign every active opportunity.</span><b>View leads →</b></button>` : '',
    crmHas('crm.interactions') ? `<button class="crm-action-card" onclick="nav('crm-interactions')"><span class="crm-action-kicker">Shared activity</span><strong>Log an interaction</strong><span>Capture a call, meeting, note, or follow-up without leaving VGold.</span><b>Open interactions →</b></button>` : '',
    `<button class="crm-action-card bridge" onclick="nav('mytasks')"><span class="crm-action-kicker">CRM ↔ Workflow</span><strong>Follow-ups become tasks</strong><span>Every next action appears in Workflow with the lead name and full context.</span><b>View my tasks →</b></button>`,
  ].filter(Boolean).join('');
  return `
    <div class="fade-in crm-page">
      <div class="crm-hero">
        <div><div class="section-label">CRM</div><h1>Relationships, with the work attached.</h1><p>Leads, conversations, and next actions now live in the same operating system as your projects and tasks.</p></div>
        <div class="crm-bridge-seal"><span>CRM</span><i></i><span>WORKFLOW</span></div>
      </div>
      <div class="crm-stats">
        ${stat('Active leads', s.leads, 'gold', 'crm-leads')}
        ${stat('Open follow-ups', s.follow_ups, 'ink', 'crm-interactions')}
        ${stat('Overdue', s.overdue, 'red', 'crm-interactions')}
        ${stat('Won', s.won, 'green', 'crm-leads')}
      </div>
      <div class="crm-action-grid">${allowedCards}</div>
    </div>`;
}

async function renderCrmLeads() {
  if (!crmHas('crm.leads')) return crmAccessDenied('crm.leads');
  return renderCrmEmbeddedModule('crm.leads');
  const data = await API.crmLeads();
  State.crmLeads = data.leads || [];
  const rows = State.crmLeads.map(lead => `
    <tr>
      <td><button class="crm-lead-link" onclick="openCrmInteractionModal(${lead.id})"><span class="crm-lead-avatar">${esc((lead.display_name || '?').slice(0,2).toUpperCase())}</span><span><strong>${esc(lead.display_name)}</strong><small>${esc(lead.company_name && lead.company_name !== lead.display_name ? lead.company_name : lead.lead_type)}</small></span></button></td>
      <td><span class="crm-status ${crmStatusClass(lead.status)}">${esc(lead.status)}</span></td>
      <td><span class="crm-priority ${String(lead.priority).toLowerCase()}">${esc(lead.priority)}</span></td>
      <td>${esc(lead.assigned_name || 'Unassigned')}</td>
      <td>${esc([lead.country, lead.region].filter(Boolean).join(' · ') || '—')}</td>
      <td><button class="btn-secondary crm-row-action" onclick="openCrmInteractionModal(${lead.id})">Log activity</button></td>
    </tr>`).join('');
  return `
    <div class="fade-in crm-page">
      <div class="crm-page-head">
        <div><div class="section-label">CRM / Leads</div><h1 class="page-title-sm">Leads</h1><p class="page-desc">One customer record for every conversation, follow-up, and workflow task.</p></div>
        <button class="btn-primary" onclick="openCrmLeadModal()">Add lead</button>
      </div>
      <div class="crm-toolbar">
        <label class="crm-search"><span>⌕</span><input id="crm-lead-search" placeholder="Search by lead, company, or email" onkeydown="if(event.key==='Enter')searchCrmLeads()"></label>
        <select id="crm-lead-status" onchange="searchCrmLeads()"><option value="">All statuses</option>${['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'].map(x => `<option>${x}</option>`).join('')}</select>
      </div>
      <div class="crm-table-wrap">
        <table class="crm-table"><thead><tr><th>Lead</th><th>Status</th><th>Priority</th><th>Owner</th><th>Region</th><th></th></tr></thead>
        <tbody>${rows || `<tr><td colspan="6"><div class="crm-table-empty">No leads yet. Add the first customer record to get started.</div></td></tr>`}</tbody></table>
      </div>
    </div>`;
}

async function searchCrmLeads() {
  const q = document.getElementById('crm-lead-search')?.value.trim() || '';
  const status = document.getElementById('crm-lead-status')?.value || '';
  const data = await API.crmLeads({ q, status });
  State.crmLeads = data.leads || [];
  // Re-render keeps the active filter logic simple and predictable.
  await render();
  const qEl = document.getElementById('crm-lead-search');
  const sEl = document.getElementById('crm-lead-status');
  if (qEl) qEl.value = q;
  if (sEl) sEl.value = status;
}

async function renderCrmInteractions() {
  if (!crmHas('crm.interactions')) return crmAccessDenied('crm.interactions');
  return renderCrmEmbeddedModule('crm.interactions');
  const data = await API.crmInteractions();
  State.crmInteractions = data.interactions || [];
  const cards = State.crmInteractions.map(item => {
    const hasFollowUp = !!item.next_action;
    return `<article class="crm-interaction-card">
      <div class="crm-interaction-rail ${crmInteractionClass(item.type)}"></div>
      <div class="crm-interaction-main">
        <div class="crm-interaction-top"><span class="crm-type">${esc(item.type)}</span><time>${crmFormatDate(item.occurred_at)}</time></div>
        <h3>${esc(item.lead_name)}</h3>
        ${item.company_name && item.company_name !== item.lead_name ? `<div class="crm-company">${esc(item.company_name)}</div>` : ''}
        ${item.subject ? `<p class="crm-subject">${esc(item.subject)}</p>` : ''}
        ${item.notes ? `<p class="crm-notes">${esc(item.notes)}</p>` : ''}
        ${hasFollowUp ? `<button class="crm-followup ${item.follow_up_completed ? 'complete' : ''}" onclick="${item.workflow_task_id ? `goTaskPage(${item.workflow_task_id})` : ''}">
          <span>${item.follow_up_completed ? '✓' : '→'}</span><span><b>${esc(item.next_action)}</b><small>${crmFormatDate(item.next_action_date)}${item.workflow_task_id ? ' · Open in Workflow' : ''}</small></span>
        </button>` : ''}
      </div>
      <div class="crm-interaction-user">${esc(item.user_name)}</div>
    </article>`;
  }).join('');
  return `
    <div class="fade-in crm-page">
      <div class="crm-page-head">
        <div><div class="section-label">CRM / Interactions</div><h1 class="page-title-sm">Interactions & follow-ups</h1><p class="page-desc">The customer timeline and the work queue stay synchronized automatically.</p></div>
        <button class="btn-primary" onclick="openCrmInteractionModal()">Log interaction</button>
      </div>
      <div class="crm-bridge-note"><span class="crm-bridge-icon">↔</span><div><strong>One next action, one task.</strong><p>Adding a follow-up here creates a Workflow task named “Follow-up: Lead Name” with the full interaction context.</p></div></div>
      <div class="crm-interaction-list">${cards || `<div class="crm-empty-list">No CRM activity has been logged yet.</div>`}</div>
    </div>`;
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

async function openCrmLeadModal() {
  const members = (await API.members()).members || [];
  Modal.open({
    title: 'Add lead',
    body: `<div class="crm-form-grid">
      ${crmInput('crm-contact','Lead name','Contact person or owner')}
      ${crmInput('crm-company','Company / stable','Organization name')}
      ${crmInput('crm-email','Email','name@example.com','email')}
      ${crmInput('crm-phone','Phone','+34 …','tel')}
      ${crmInput('crm-country','Country','Country')}
      <div class="form-field"><label class="form-label">Owner</label><select class="form-input" id="crm-assignee">${members.map(m => `<option value="${m.id}" ${m.id === State.user.id ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></div>
      <div class="form-field"><label class="form-label">Status</label><select class="form-input" id="crm-status">${['New Lead','Contacted','Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'].map(x => `<option>${x}</option>`).join('')}</select></div>
      <div class="form-field"><label class="form-label">Priority</label><select class="form-input" id="crm-priority">${['Medium','High','Urgent','Low'].map(x => `<option>${x}</option>`).join('')}</select></div>
      <div class="form-field crm-form-wide"><label class="form-label">Notes</label><textarea class="form-input" id="crm-notes" rows="4" placeholder="Background, goals, or context"></textarea></div>
    </div><div class="pw-error" id="crm-form-error" style="display:none"></div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveCrmLead()">Add lead</button>`,
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

async function openCrmInteractionModal(leadId = null) {
  const leads = (await API.crmLeadOptions()).leads || [];
  if (!leads.length) { toast('Add a lead before logging an interaction', 'error'); return; }
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0,16);
  Modal.open({
    title: 'Log interaction',
    body: `<div class="crm-form-grid">
      <div class="form-field crm-form-wide"><label class="form-label">Lead</label><select class="form-input" id="crm-ix-lead">${leads.map(l => `<option value="${l.id}" ${l.id === leadId ? 'selected' : ''}>${esc(l.name)}${l.company && l.company !== l.name ? ' — ' + esc(l.company) : ''}</option>`).join('')}</select></div>
      <div class="form-field"><label class="form-label">Type</label><select class="form-input" id="crm-ix-type" onchange="crmToggleFollowUpFields()">${['Call','Email','Meeting','Demo','Follow-up','Note','WhatsApp','SMS'].map(x => `<option>${x}</option>`).join('')}</select></div>
      ${crmInput('crm-ix-date','Date & time','', 'datetime-local', local)}
      <div class="form-field crm-form-wide">${crmInputInner('crm-ix-subject','Subject','What happened or what is needed?')}</div>
      <div class="form-field crm-form-wide"><label class="form-label">Notes</label><textarea class="form-input" id="crm-ix-notes" rows="4" placeholder="Conversation details and useful context"></textarea></div>
      <div class="form-field"><label class="form-label">Outcome</label><select class="form-input" id="crm-ix-outcome"><option value="">No outcome</option>${['Positive','Neutral','Negative','No Response'].map(x => `<option>${x}</option>`).join('')}</select></div>
      <div></div>
      <div class="crm-followup-fields crm-form-wide"><div class="crm-followup-label"><span>Workflow bridge</span><small>Complete these fields to create a task automatically.</small></div><div class="crm-form-grid">
        ${crmInput('crm-ix-next','Next action','e.g. Send pricing and arrange a call')}
        ${crmInput('crm-ix-next-date','Due date','', 'date')}
      </div></div>
    </div><div class="pw-error" id="crm-ix-error" style="display:none"></div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveCrmInteraction()">Save interaction</button>`,
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

function crmInput(id, label, placeholder, type = 'text', value = '') {
  return `<div class="form-field">${crmInputInner(id, label, placeholder, type, value)}</div>`;
}
function crmToggleFollowUpFields() {
  const type = document.getElementById('crm-ix-type')?.value;
  const next = document.getElementById('crm-ix-next');
  if (type === 'Follow-up' && next && !next.value) next.value = 'Follow up with lead';
}
function crmInputInner(id, label, placeholder, type = 'text', value = '') {
  return `<label class="form-label" for="${id}">${esc(label)}</label><input class="form-input" id="${id}" type="${type}" value="${esc(value)}" placeholder="${esc(placeholder)}">`;
}
function crmStatusClass(status) { return String(status || '').toLowerCase().replace(/[^a-z]+/g, '-'); }
function crmInteractionClass(type) { return 'type-' + String(type || '').toLowerCase().replace(/[^a-z]+/g, '-'); }
function crmFormatDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? esc(value) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
