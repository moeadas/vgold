// VGold native CRM module views (Knowledge, Proposals, Automations, …).
// These replace the iframe-embedded legacy pages. Each view calls the existing
// /crm/api/*.php JSON endpoints through the unified session (mount.php enforces
// the same per-user module access), so there is one app, one version, no iframes.
//
// This file loads AFTER crm.js and overrides window.renderCrmModule: migrated
// modules render natively; any not-yet-migrated module still falls back to the
// legacy embed so nothing breaks mid-migration.

const CrmMod = { cache: {}, tab: {} };

// ---- transport helpers -------------------------------------------------
async function crmApiGet(path) {
  const res = await fetch('/crm/api/' + path, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
  if (res.status === 401) throw new Error('Your CRM session expired — reload the app to continue.');
  if (res.status === 403) throw new Error('You do not have access to this module.');
  const data = await res.json().catch(() => { throw new Error('The server returned an unexpected response.'); });
  return data;
}
let _crmCsrfToken = null;
async function crmCsrf() {
  if (_crmCsrfToken) return _crmCsrfToken;
  const d = await crmApiGet('csrf.php');
  _crmCsrfToken = d.token;
  return _crmCsrfToken;
}
async function crmApiPost(path, body) {
  const token = await crmCsrf();
  const res = await fetch('/crm/api/' + path, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...(body || {}), csrf_token: token }),
  });
  const data = await res.json().catch(() => ({ success: false, message: 'The server returned an unexpected response.' }));
  if (data && data.message && /csrf|expired|refresh/i.test(data.message) && !body?._retried) {
    _crmCsrfToken = null; // token rotated — refetch once and retry
    return crmApiPost(path, { ...(body || {}), _retried: true });
  }
  if (!data.success) throw new Error(data.message || 'Request failed.');
  return data;
}
function crmModInvalidate(key) { delete CrmMod.cache[key]; }
function crmModDate(v) {
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? esc(v) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
function crmTitleCase(s) { return String(s || '').replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }
function crmModError(label, msg) {
  return `<div class="fade-in crm-page"><div class="crm-empty card card-pad"><div class="crm-empty-mark">VG</div><h2>${esc(label)}</h2><p>${esc(msg || 'This section could not be loaded.')}</p><button class="btn-secondary" onclick="render()" style="margin-top:14px">Retry</button></div></div>`;
}
function crmModHead(kicker, title, desc, actionsHtml) {
  return `<div class="crm-page-head">
    <div><div class="section-label">${esc(kicker)}</div><h1 class="page-title-sm">${esc(title)}</h1>${desc ? `<p class="page-desc">${esc(desc)}</p>` : ''}</div>
    <div class="crm-detail-actions">${actionsHtml || ''}</div>
  </div>`;
}
function ensureCrmModStyles() {
  if (typeof ensureCrmDetailStyles === 'function') ensureCrmDetailStyles();
  if (typeof document === 'undefined' || document.getElementById('crm-mod-styles')) return;
  const s = document.createElement('style');
  s.id = 'crm-mod-styles';
  s.textContent = `
.crm-tabs{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
.crm-tabs button{background:none;border:1px solid var(--border);border-radius:99px;padding:7px 15px;font-size:13px;font-weight:600;color:var(--text-2);cursor:pointer}
.crm-tabs button.active{background:var(--gold);border-color:var(--gold);color:#fff}
.crm-cardgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.crm-res-card{display:flex;flex-direction:column;gap:8px;padding:18px;border:1px solid var(--border);border-radius:16px;background:var(--surface)}
.crm-res-card h4{font-size:15px;font-weight:700}
.crm-res-card p{font-size:13px;color:var(--text-2);line-height:1.5;flex:1}
.crm-res-actions{display:flex;gap:10px;align-items:center;margin-top:6px}
.crm-res-actions a{color:var(--gold);font-weight:700;font-size:13px;text-decoration:none}
.crm-res-actions button{background:none;border:none;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;padding:0}
.crm-res-actions button:hover{color:var(--gold)}
.crm-cat-label{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:22px 0 12px}
.crm-switch{position:relative;display:inline-block;width:40px;height:22px}
.crm-switch input{opacity:0;width:0;height:0}
.crm-switch span{position:absolute;inset:0;background:#CBB;border-radius:99px;transition:.2s;cursor:pointer}
.crm-switch span:before{content:'';position:absolute;height:16px;width:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.crm-switch input:checked+span{background:var(--gold)}
.crm-switch input:checked+span:before{transform:translateX(18px)}
.crm-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}
.crm-badge.ok{background:#E8F0E4;color:#4B6B3A}.crm-badge.warn{background:#F6EBCF;color:#8A6B2A}.crm-badge.err{background:#F4D6CC;color:#9B3B22}.crm-badge.mut{background:#EEE7DB;color:#7A6A55}`;
  document.head.appendChild(s);
}

// ---- override the dispatcher ------------------------------------------
window.renderCrmModule = async function (moduleKey) {
  if (typeof crmHas === 'function' && !crmHas(moduleKey)) return crmAccessDenied(moduleKey);
  ensureCrmModStyles();
  try {
    switch (moduleKey) {
      case 'crm.knowledge':  return await renderCrmKnowledge();
      case 'crm.proposals':  return await renderCrmProposals();
      case 'crm.automation': return await renderCrmAutomation();
      default:               return renderCrmEmbeddedModule(moduleKey); // not yet migrated
    }
  } catch (e) {
    return crmModError(CRM_MODULE_COPY[moduleKey]?.title || 'CRM', e.message);
  }
};

// ======================================================================
//  KNOWLEDGE HUB  — full native CRUD (reuses /crm/api/knowledge-hub.php)
// ======================================================================
async function renderCrmKnowledge() {
  let data = CrmMod.cache.knowledge;
  if (!data) { data = await crmApiGet('knowledge-hub.php?action=list'); CrmMod.cache.knowledge = data; }
  const cards = data.data || [];
  const groups = {};
  cards.forEach(c => { const k = (c.category || 'General').trim() || 'General'; (groups[k] = groups[k] || []).push(c); });
  const body = Object.keys(groups).sort().map(cat => `
    <div class="crm-cat-label">${esc(cat)}</div>
    <div class="crm-cardgrid">${groups[cat].map(c => `
      <div class="crm-res-card">
        <h4>${esc(c.title)}</h4>
        ${c.description ? `<p>${esc(c.description)}</p>` : '<p></p>'}
        <div class="crm-res-actions">
          <a href="${esc(c.url)}" target="_blank" rel="noopener">Open →</a>
          <span style="flex:1"></span>
          <button onclick="openKnowledgeModal(${c.card_id})">Edit</button>
          <button onclick="deleteKnowledge(${c.card_id})">Delete</button>
        </div>
      </div>`).join('')}</div>`).join('');
  return `<div class="fade-in crm-page">
    ${crmModHead('CRM / Knowledge', 'Knowledge hub', 'Sales guides and shared resources, one click from the work.',
      `<button class="btn-primary" onclick="openKnowledgeModal()">Add resource</button>`)}
    ${cards.length ? body : `<div class="crm-empty-list">No resources yet. Add the first guide or link.</div>`}
  </div>`;
}
function openKnowledgeModal(id = null) {
  const c = id ? (CrmMod.cache.knowledge?.data || []).find(x => Number(x.card_id) === Number(id)) || {} : {};
  const v = x => esc(x == null ? '' : String(x));
  Modal.open({
    title: id ? 'Edit resource' : 'Add resource',
    body: `<div class="crm-form-grid">
      ${crmInput('kh-title','Title','Resource name','text', v(c.title))}
      ${crmInput('kh-category','Category','e.g. Sales, Product','text', v(c.category))}
      <div class="form-field crm-form-wide">${crmInputInner('kh-url','Link URL','https://…','text', v(c.url))}</div>
      <div class="form-field crm-form-wide"><label class="form-label">Description</label><textarea class="form-input" id="kh-desc" rows="3">${v(c.description)}</textarea></div>
    </div><div class="pw-error" id="kh-error" style="display:none"></div>`,
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="saveKnowledge(${id || 0})">${id ? 'Save changes' : 'Add resource'}</button>`,
  });
}
async function saveKnowledge(id) {
  const err = document.getElementById('kh-error');
  const g = i => document.getElementById(i)?.value.trim() || '';
  try {
    const payload = { title: g('kh-title'), category: g('kh-category'), url: g('kh-url'), description: g('kh-desc') };
    if (id) payload.card_id = id;
    await crmApiPost('knowledge-hub.php?action=' + (id ? 'update' : 'create'), payload);
    crmModInvalidate('knowledge'); Modal.close(); toast(id ? 'Resource updated' : 'Resource added', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
function deleteKnowledge(id) {
  appConfirm('Remove this resource?', async () => {
    try { await crmApiPost('knowledge-hub.php?action=delete', { card_id: id }); crmModInvalidate('knowledge'); toast('Resource removed', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// ======================================================================
//  PROPOSALS  — native list + view + delete + PDF (reuses proposals.php)
// ======================================================================
async function renderCrmProposals() {
  const q = CrmMod.tab.proposalQ || '';
  const status = CrmMod.tab.proposalStatus || '';
  let data = CrmMod.cache.proposals;
  if (!data) {
    data = await crmApiGet('proposals.php?action=list&limit=100' + (q ? '&search=' + encodeURIComponent(q) : '') + (status ? '&status=' + encodeURIComponent(status) : ''));
    CrmMod.cache.proposals = data;
  }
  const rows = (data.proposals || []).map(p => {
    const amount = p.total_amount ?? p.grand_total ?? p.total ?? null;
    return `<tr>
      <td><strong>${esc(p.estimate_number || ('#' + p.proposal_id))}</strong></td>
      <td>${esc(p.customer_company || '—')}${p.contact_name ? `<br><small style="color:var(--muted)">${esc(p.contact_name)}</small>` : ''}</td>
      <td><span class="crm-badge ${proposalStatusClass(p.status)}">${esc(p.status || 'Draft')}</span></td>
      <td>${amount != null && amount !== '' ? esc((p.currency ? p.currency + ' ' : '') + amount) : '—'}</td>
      <td>${crmModDate(p.created_at)}</td>
      <td style="white-space:nowrap">
        <button class="btn-secondary btn-sm" onclick="viewProposal(${p.proposal_id})">View</button>
        <button class="btn-secondary btn-sm" onclick="openProposalPdf(${p.proposal_id})">PDF</button>
        <button class="btn-secondary btn-sm" onclick="deleteProposal(${p.proposal_id})">Delete</button>
      </td></tr>`;
  }).join('');
  const statuses = ['Draft', 'Sent', 'Accepted', 'Declined', 'Expired'];
  return `<div class="fade-in crm-page">
    ${crmModHead('CRM / Proposals', 'Proposals', 'Create, review, and track proposals alongside each lead.',
      `<a class="btn-primary" href="/crm/pages/proposal-form.php?embedded=1" target="_blank" rel="noopener">New proposal ↗</a>`)}
    <div class="crm-toolbar">
      <label class="crm-search"><span>⌕</span><input id="prop-search" value="${esc(q)}" placeholder="Search company, contact, or estimate #" onkeydown="if(event.key==='Enter')searchProposals()"></label>
      <select id="prop-status" onchange="searchProposals()"><option value="">All statuses</option>${statuses.map(x => `<option ${x === status ? 'selected' : ''}>${x}</option>`).join('')}</select>
    </div>
    <div class="crm-table-wrap"><table class="crm-table">
      <thead><tr><th>Estimate</th><th>Customer</th><th>Status</th><th>Amount</th><th>Created</th><th></th></tr></thead>
      <tbody>${rows || `<tr><td colspan="6"><div class="crm-table-empty">No proposals found.</div></td></tr>`}</tbody>
    </table></div>
  </div>`;
}
function proposalStatusClass(s) {
  s = String(s || '').toLowerCase();
  if (s === 'accepted' || s === 'won') return 'ok';
  if (s === 'declined' || s === 'lost' || s === 'expired') return 'err';
  if (s === 'sent' || s === 'negotiation') return 'warn';
  return 'mut';
}
function searchProposals() {
  CrmMod.tab.proposalQ = document.getElementById('prop-search')?.value.trim() || '';
  CrmMod.tab.proposalStatus = document.getElementById('prop-status')?.value || '';
  crmModInvalidate('proposals'); render();
}
async function viewProposal(id) {
  try {
    const d = await crmApiGet('proposals.php?action=get&id=' + id);
    if (!d.success) throw new Error(d.message || 'Not found');
    const p = d.proposal || {};
    const row = (l, val) => (val == null || val === '') ? '' : `<div class="crm-detail-item"><div class="crm-detail-label">${esc(l)}</div><div class="crm-detail-value">${esc(val)}</div></div>`;
    Modal.open({
      title: 'Proposal ' + (p.estimate_number || ('#' + id)),
      body: `<div>
        ${row('Customer', p.customer_company)}
        ${row('Contact', p.contact_name)}
        ${row('Email', p.customer_email)}
        ${row('Status', p.status)}
        ${row('Amount', (p.total_amount ?? p.grand_total ?? p.total) != null ? ((p.currency ? p.currency + ' ' : '') + (p.total_amount ?? p.grand_total ?? p.total)) : '')}
        ${row('Valid until', crmModDate(p.valid_until))}
        ${row('Created by', p.creator_name)}
        ${row('Created', crmModDate(p.created_at))}
        ${p.notes ? `<div style="margin-top:12px"><div class="crm-detail-label">Notes</div><p class="crm-notes-body">${esc(p.notes)}</p></div>` : ''}
      </div>`,
      footer: `<button class="btn-secondary" onclick="Modal.close()">Close</button><button class="btn-primary" onclick="openProposalPdf(${id})">Open PDF</button>`,
    });
  } catch (e) { toast(e.message, 'error'); }
}
function openProposalPdf(id) {
  window.open('/crm/api/proposals.php?action=pdf_html&id=' + id, '_blank', 'noopener');
}
function deleteProposal(id) {
  appConfirm('Delete this proposal permanently?', async () => {
    try { await crmApiPost('proposals.php?action=delete', { proposal_id: id }); crmModInvalidate('proposals'); toast('Proposal deleted', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// ======================================================================
//  AUTOMATIONS  — native rules list + toggle + delete + run history
// ======================================================================
async function renderCrmAutomation() {
  const tab = CrmMod.tab.automation || 'rules';
  const tabs = `<div class="crm-tabs">
    <button class="${tab === 'rules' ? 'active' : ''}" onclick="setAutomationTab('rules')">Rules</button>
    <button class="${tab === 'logs' ? 'active' : ''}" onclick="setAutomationTab('logs')">Run history</button>
  </div>`;
  let inner;
  if (tab === 'logs') {
    let logs = CrmMod.cache.autoLogs;
    if (!logs) { logs = await crmApiGet('automation.php?action=logs'); CrmMod.cache.autoLogs = logs; }
    const rows = (logs.data || []).map(l => `<tr>
      <td>${crmModDate(l.created_at)}</td>
      <td>${esc(l.contact_person || l.company_name || (l.lead_id ? 'Lead #' + l.lead_id : '—'))}</td>
      <td><span class="crm-badge ${l.status === 'success' ? 'ok' : (l.status === 'skipped' ? 'mut' : 'err')}">${esc(l.status || '')}</span></td>
      <td>${esc(l.message || l.action_type || '')}</td></tr>`).join('');
    inner = `<div class="crm-table-wrap"><table class="crm-table">
      <thead><tr><th>When</th><th>Lead</th><th>Result</th><th>Detail</th></tr></thead>
      <tbody>${rows || `<tr><td colspan="4"><div class="crm-table-empty">No automation runs recorded yet.</div></td></tr>`}</tbody>
    </table></div>`;
  } else {
    let data = CrmMod.cache.automation;
    if (!data) { data = await crmApiGet('automation.php?action=list'); CrmMod.cache.automation = data; }
    const rows = (data.data || []).map(r => `<tr>
      <td><strong>${esc(r.name)}</strong>${r.description ? `<br><small style="color:var(--muted)">${esc(r.description)}</small>` : ''}</td>
      <td>${esc(crmTitleCase(r.trigger_type))} <span style="color:var(--muted)">→</span> ${esc(crmTitleCase(r.action_type))}</td>
      <td>${Number(r.log_count || 0)}</td>
      <td>${r.last_success ? crmModDate(r.last_success) : '<span style="color:var(--muted)">—</span>'}</td>
      <td><label class="crm-switch"><input type="checkbox" ${Number(r.is_active) ? 'checked' : ''} onchange="toggleAutomation(${r.rule_id})"><span></span></label></td>
      <td><button class="btn-secondary btn-sm" onclick="deleteAutomation(${r.rule_id})">Delete</button></td>
    </tr>`).join('');
    inner = `<div class="crm-bridge-note"><span class="crm-bridge-icon">↔</span><div><strong>Rules run automatically.</strong><p>Enable or pause a rule with the toggle; open Run history to see what fired.</p></div></div>
      <div class="crm-table-wrap"><table class="crm-table">
      <thead><tr><th>Rule</th><th>Trigger → Action</th><th>Runs</th><th>Last success</th><th>Active</th><th></th></tr></thead>
      <tbody>${rows || `<tr><td colspan="6"><div class="crm-table-empty">No automation rules yet.</div></td></tr>`}</tbody>
    </table></div>`;
  }
  return `<div class="fade-in crm-page">
    ${crmModHead('CRM / Automations', 'Automations', 'Lead and follow-up actions from one shared rules engine.', '')}
    ${tabs}${inner}
  </div>`;
}
function setAutomationTab(t) { CrmMod.tab.automation = t; render(); }
async function toggleAutomation(id) {
  try { await crmApiPost('automation.php?action=toggle', { rule_id: id }); crmModInvalidate('automation'); toast('Rule updated', 'success'); }
  catch (e) { toast(e.message, 'error'); render(); }
}
function deleteAutomation(id) {
  appConfirm('Delete this automation rule and its run history?', async () => {
    try { await crmApiPost('automation.php?action=delete', { rule_id: id }); crmModInvalidate('automation'); crmModInvalidate('autoLogs'); toast('Rule deleted', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}
