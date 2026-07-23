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

// Styles for the native lead-detail view are injected once so we don't have to
// ship them in the global stylesheet. Idempotent — guarded by element id.
function ensureCrmDetailStyles() {
  if (typeof document === 'undefined' || document.getElementById('crm-native-styles')) return;
  const style = document.createElement('style');
  style.id = 'crm-native-styles';
  style.textContent = `
.crm-back{background:none;border:none;cursor:pointer;color:var(--muted);font-size:13px;font-weight:600;padding:4px 0;margin-bottom:12px}
.crm-back:hover{color:var(--gold)}
.crm-linkish{background:none;border:none;padding:0;cursor:pointer;color:inherit;font:inherit;text-align:left}
.crm-linkish:hover{color:var(--gold);text-decoration:underline}
.crm-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:18px}
.crm-detail-id{display:flex;align-items:flex-start;gap:14px}
.crm-lead-avatar.lg{width:52px;height:52px;border-radius:15px;font-size:17px}
.crm-detail-sub{color:var(--muted);font-size:13px;margin-top:3px}
.crm-detail-badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:9px}
.crm-chip{display:inline-flex;align-items:center;padding:3px 11px;border-radius:99px;background:#F0E8DC;color:#745A3F;font-size:11px;font-weight:700}
.crm-detail-actions{display:flex;gap:10px;flex-wrap:wrap}
.crm-detail-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:18px;align-items:start}
.crm-detail-main{display:flex;flex-direction:column;gap:18px;min-width:0}
.crm-detail-side{display:flex;flex-direction:column;gap:18px}
.crm-detail-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.crm-detail-section-head h3{font-size:15px;font-weight:700}
.crm-detail-h{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin-bottom:12px}
.crm-detail-item{display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-bottom:1px solid rgba(0,0,0,.05);font-size:13px}
.crm-detail-item:last-child{border-bottom:none}
.crm-detail-label{color:var(--muted);flex:none}
.crm-detail-value{text-align:right;font-weight:600;word-break:break-word}
.crm-detail-value a{color:var(--gold);text-decoration:none}
.crm-detail-value a:hover{text-decoration:underline}
.crm-notes-body{white-space:pre-wrap;font-size:13px;line-height:1.6;color:var(--text-2)}
.crm-outcome{font-size:12px;color:var(--muted);margin-top:6px}
.btn-sm{padding:6px 12px;font-size:12px}
@media (max-width:900px){.crm-detail-grid{grid-template-columns:1fr}}`;
  document.head.appendChild(style);
}

function crmAccessDenied(moduleKey) {
  const label = CRM_MODULE_COPY[moduleKey]?.title || 'CRM';
  return `<div class="fade-in crm-page"><div class="crm-empty card card-pad"><div class="crm-empty-mark">VG</div><h2>${esc(label)} access is not enabled</h2><p>Ask a VGold administrator to enable this module in Settings → Team module access.</p></div></div>`;
}

async function renderCrmDashboard() {
  if (!(State.user?.modules || []).length) return crmAccessDenied('crm.dashboard');
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
  const data = await API.crmLeads();
  State.crmLeads = data.leads || [];
  const rows = State.crmLeads.map(lead => `
    <tr>
      <td><button class="crm-lead-link" onclick="goCrmLead(${lead.id})"><span class="crm-lead-avatar">${esc((lead.display_name || '?').slice(0,2).toUpperCase())}</span><span><strong>${esc(lead.display_name)}</strong><small>${esc(lead.company_name && lead.company_name !== lead.display_name ? lead.company_name : lead.lead_type)}</small></span></button></td>
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
  ensureCrmDetailStyles();
  const data = await API.crmInteractions();
  State.crmInteractions = data.interactions || [];
  const cards = State.crmInteractions.map(item => {
    const hasFollowUp = !!item.next_action;
    return `<article class="crm-interaction-card">
      <div class="crm-interaction-rail ${crmInteractionClass(item.type)}"></div>
      <div class="crm-interaction-main">
        <div class="crm-interaction-top"><span class="crm-type">${esc(item.type)}</span><time>${crmFormatDate(item.occurred_at)}</time></div>
        <h3><button class="crm-linkish" onclick="goCrmLead(${item.lead_id})">${esc(item.lead_name)}</button></h3>
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

// ===== Native lead detail =====
function goCrmLead(id) {
  State.screen = 'crm-lead';
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
  return `<div class="crm-detail-item"><div class="crm-detail-label">${esc(label)}</div><div class="crm-detail-value">${inner}</div></div>`;
}

async function renderCrmLeadDetail(id) {
  if (!crmHas('crm.leads')) return crmAccessDenied('crm.leads');
  ensureCrmDetailStyles();
  if (!id) { nav('crm-leads'); return ''; }
  let data;
  try {
    data = await API.crmLeadDetail(id);
  } catch (e) {
    return `<div class="fade-in crm-page"><div class="crm-empty card card-pad"><div class="crm-empty-mark">VG</div><h2>Lead unavailable</h2><p>${esc(e.message || 'This lead could not be loaded.')}</p><button class="btn-secondary" onclick="nav('crm-leads')" style="margin-top:14px">← Back to leads</button></div></div>`;
  }
  const lead = data.lead || {};
  State.crmLeadDetail = lead;
  const socials = [
    ['Website', lead.website, { href: true, text: lead.website }],
    ['Facebook', lead.facebook_url, { href: true, text: 'Facebook' }],
    ['Instagram', lead.instagram_url, { href: true, text: 'Instagram' }],
    ['LinkedIn', lead.linkedin_url, { href: true, text: 'LinkedIn' }],
    ['Twitter / X', lead.twitter_url, { href: true, text: 'Twitter' }],
    ['YouTube', lead.youtube_url, { href: true, text: 'YouTube' }],
  ].map(([l, v, o]) => crmDetailRow(l, v, o)).join('');

  const interactions = (data.interactions || []).map(item => {
    const hasFollowUp = !!item.next_action;
    return `<article class="crm-interaction-card">
      <div class="crm-interaction-rail ${crmInteractionClass(item.type)}"></div>
      <div class="crm-interaction-main">
        <div class="crm-interaction-top"><span class="crm-type">${esc(item.type)}</span><time>${crmFormatDate(item.occurred_at)}</time></div>
        ${item.subject ? `<p class="crm-subject">${esc(item.subject)}</p>` : ''}
        ${item.notes ? `<p class="crm-notes">${esc(item.notes)}</p>` : ''}
        ${item.outcome ? `<div class="crm-outcome">Outcome: ${esc(item.outcome)}</div>` : ''}
        ${hasFollowUp ? `<button class="crm-followup ${item.follow_up_completed ? 'complete' : ''}" onclick="${item.workflow_task_id ? `goTaskPage(${item.workflow_task_id})` : ''}">
          <span>${item.follow_up_completed ? '✓' : '→'}</span><span><b>${esc(item.next_action)}</b><small>${crmFormatDate(item.next_action_date)}${item.workflow_task_id ? ' · Open in Workflow' : ''}</small></span>
        </button>` : ''}
      </div>
      <div class="crm-interaction-user">${esc(item.user_name)}</div>
    </article>`;
  }).join('');

  const nameLine = lead.company_name && lead.company_name !== lead.display_name ? esc(lead.company_name) : (lead.title_position ? esc(lead.title_position) : esc(lead.lead_type || ''));

  return `
    <div class="fade-in crm-page crm-lead-detail">
      <button class="crm-back" onclick="nav('crm-leads')">← Leads</button>
      <div class="crm-detail-head">
        <div class="crm-detail-id">
          <span class="crm-lead-avatar lg">${esc((lead.display_name || '?').slice(0,2).toUpperCase())}</span>
          <div>
            <h1 class="page-title-sm">${esc(lead.display_name)}</h1>
            <div class="crm-detail-sub">${nameLine}</div>
            <div class="crm-detail-badges">
              <span class="crm-status ${crmStatusClass(lead.status)}">${esc(lead.status)}</span>
              <span class="crm-priority ${String(lead.priority).toLowerCase()}">${esc(lead.priority)}</span>
              ${lead.region ? `<span class="crm-chip">${esc(lead.region)}</span>` : ''}
            </div>
          </div>
        </div>
        <div class="crm-detail-actions">
          <button class="btn-secondary" onclick="openCrmLeadEditModal(${lead.id})">Edit lead</button>
          <button class="btn-primary" onclick="openCrmInteractionModal(${lead.id})">Log interaction</button>
        </div>
      </div>

      <div class="crm-detail-grid">
        <div class="crm-detail-main">
          <div class="card card-pad">
            <div class="crm-detail-section-head"><h3>Interactions & follow-ups</h3><button class="btn-secondary btn-sm" onclick="openCrmInteractionModal(${lead.id})">Log interaction</button></div>
            <div class="crm-interaction-list">${interactions || `<div class="crm-empty-list">No activity logged yet for this lead.</div>`}</div>
          </div>
          ${lead.notes ? `<div class="card card-pad"><h3 class="crm-detail-h">Notes</h3><p class="crm-notes-body">${esc(lead.notes)}</p></div>` : ''}
        </div>
        <aside class="crm-detail-side">
          <div class="card card-pad">
            <h3 class="crm-detail-h">Contact</h3>
            ${crmDetailRow('Contact person', lead.contact_person)}
            ${crmDetailRow('Title / position', lead.title_position)}
            ${crmDetailRow('Email', lead.email, { mailto: true })}
            ${crmDetailRow('Phone', lead.phone, { tel: true })}
            ${crmDetailRow('Mobile', lead.mobile, { tel: true })}
            ${crmDetailRow('Country', lead.country)}
            ${crmDetailRow('City', lead.city)}
            ${crmDetailRow('Address', lead.address)}
          </div>
          ${socials ? `<div class="card card-pad"><h3 class="crm-detail-h">Online</h3>${socials}</div>` : ''}
          <div class="card card-pad">
            <h3 class="crm-detail-h">Facility</h3>
            ${crmDetailRow('Lead type', lead.lead_type)}
            ${crmDetailRow('Facility type', lead.facility_type)}
            ${crmDetailRow('Number of horses', lead.number_of_horses)}
            ${crmDetailRow('Specialization', lead.specialization)}
            ${crmDetailRow('Horse breed', lead.horse_breed)}
            ${crmDetailRow('Horse sex', lead.horse_sex)}
          </div>
          <div class="card card-pad">
            <h3 class="crm-detail-h">Management</h3>
            ${crmDetailRow('Owner', lead.assigned_name || 'Unassigned')}
            ${crmDetailRow('Created by', lead.created_name)}
            ${crmDetailRow('Lead source', lead.lead_source)}
            ${crmDetailRow('Created', crmFormatDate(lead.created_at))}
            ${crmDetailRow('Last updated', crmFormatDate(lead.updated_at))}
          </div>
        </aside>
      </div>
    </div>`;
}

async function openCrmLeadEditModal(id) {
  const lead = (State.crmLeadDetail && State.crmLeadDetail.id === Number(id)) ? State.crmLeadDetail : (await API.crmLeadDetail(id)).lead;
  const members = (await API.members()).members || [];
  const sel = (id2, label, value, options) => `<div class="form-field"><label class="form-label">${esc(label)}</label><select class="form-input" id="${id2}">${options.map(o => `<option ${o === (value || '') ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`;
  const val = v => esc(v == null ? '' : String(v));
  Modal.open({
    title: 'Edit lead',
    body: `<div class="crm-form-grid">
      ${crmInput('cl-contact','Lead name','Contact person','text', val(lead.contact_person))}
      ${crmInput('cl-company','Company / stable','Organization','text', val(lead.company_name))}
      ${crmInput('cl-title','Title / position','','text', val(lead.title_position))}
      ${crmInput('cl-email','Email','name@example.com','email', val(lead.email))}
      ${crmInput('cl-phone','Phone','','tel', val(lead.phone))}
      ${crmInput('cl-mobile','Mobile','','tel', val(lead.mobile))}
      ${crmInput('cl-website','Website','https://','text', val(lead.website))}
      ${crmInput('cl-country','Country','','text', val(lead.country))}
      ${crmInput('cl-city','City','','text', val(lead.city))}
      ${sel('cl-region','Region', lead.region, ['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other'])}
      <div class="form-field"><label class="form-label">Owner</label><select class="form-input" id="cl-assignee"><option value="">Unassigned</option>${members.map(m => `<option value="${m.id}" ${m.id === lead.assigned_to ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></div>
      ${sel('cl-type','Lead type', lead.lead_type, ['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'])}
      ${sel('cl-status','Status', lead.status, ['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'])}
      ${sel('cl-priority','Priority', lead.priority, ['Low','Medium','High','Urgent'])}
      ${sel('cl-source','Lead source', lead.lead_source, ['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'])}
      ${sel('cl-facility','Facility type', lead.facility_type, ['','Breeding','Racing','Training','Multi-Purpose','Other'])}
      ${crmInput('cl-horses','Number of horses','','number', val(lead.number_of_horses))}
      ${crmInput('cl-specialization','Specialization','','text', val(lead.specialization))}
      <div class="form-field crm-form-wide"><label class="form-label">Address</label><input class="form-input" id="cl-address" value="${val(lead.address)}"></div>
      <div class="form-field crm-form-wide"><label class="form-label">Notes</label><textarea class="form-input" id="cl-notes" rows="4">${val(lead.notes)}</textarea></div>
    </div><div class="pw-error" id="cl-error" style="display:none"></div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveCrmLeadEdit(${lead.id})">Save changes</button>`,
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
