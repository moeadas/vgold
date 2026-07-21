<?php
/**
 * Victory Genomics CRM — Automation Rules
 * WHEN trigger / IF conditions / THEN action builder.
 */
$pageTitle = 'Automation';
require_once __DIR__ . '/../includes/auth.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$csrfToken = generateCSRFToken();
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Automation page styles ── */
.auto-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.auto-header h1 { font-size:22px; font-weight:600; color:#1d1d1f; margin:0; }

.auto-stats { display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap; }
.auto-stat { background:#fff; border-radius:12px; padding:16px 20px; flex:1; min-width:140px; box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid #e5e5e7; }
.auto-stat .stat-value { font-size:24px; font-weight:700; color:#1d1d1f; }
.auto-stat .stat-label { font-size:12px; color:#86868b; margin-top:2px; }

/* Rule cards */
.rule-card { background:#fff; border-radius:12px; padding:20px; margin-bottom:12px; border:1px solid #e5e5e7; box-shadow:0 1px 3px rgba(0,0,0,.04); transition:box-shadow .2s; }
.rule-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.08); }
.rule-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
.rule-name { font-size:15px; font-weight:600; color:#1d1d1f; }
.rule-desc { font-size:13px; color:#86868b; margin-top:2px; }
.rule-badges { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.rule-badge { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; }
.badge-trigger { background:#e8f4ff; color:#0071e3; }
.badge-action { background:#f0fdf4; color:#16a34a; }
.badge-conditions { background:#fef9c3; color:#a16207; }
.rule-meta { display:flex; gap:16px; margin-top:12px; font-size:12px; color:#86868b; flex-wrap:wrap; }
.rule-actions { display:flex; gap:6px; align-items:center; }
.rule-actions button { background:none; border:none; cursor:pointer; padding:6px; border-radius:6px; color:#86868b; transition:all .15s; }
.rule-actions button:hover { background:#f5f5f7; color:#1d1d1f; }
.rule-actions .btn-danger:hover { background:#fef2f2; color:#dc2626; }

/* Toggle switch */
.toggle { position:relative; width:40px; height:22px; cursor:pointer; }
.toggle input { display:none; }
.toggle .slider { position:absolute; inset:0; background:#d1d5db; border-radius:11px; transition:.2s; }
.toggle .slider::before { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px; background:#fff; border-radius:50%; transition:.2s; }
.toggle input:checked + .slider { background:#0071e3; }
.toggle input:checked + .slider::before { transform:translateX(18px); }

/* Tabs */
.auto-tabs { display:flex; gap:0; margin-bottom:24px; border-bottom:1px solid #e5e5e7; }
.auto-tab { padding:10px 20px; font-size:13px; font-weight:500; color:#86868b; cursor:pointer; border-bottom:2px solid transparent; transition:.15s; background:none; border-top:none; border-left:none; border-right:none; }
.auto-tab:hover { color:#1d1d1f; }
.auto-tab.active { color:#0071e3; border-bottom-color:#0071e3; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* Modal */
.auto-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; justify-content:center; align-items:flex-start; padding:40px 16px; overflow-y:auto; }
.auto-modal-overlay.show { display:flex; }
.auto-modal { background:#fff; border-radius:16px; width:100%; max-width:640px; box-shadow:0 20px 60px rgba(0,0,0,.2); animation:modalSlide .25s ease; }
@keyframes modalSlide { from { opacity:0; transform:translateY(-20px); } }
.auto-modal-header { padding:20px 24px 0; display:flex; justify-content:space-between; align-items:center; }
.auto-modal-header h2 { font-size:18px; font-weight:600; margin:0; }
.auto-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#86868b; padding:4px 8px; border-radius:6px; }
.auto-modal-close:hover { background:#f5f5f7; color:#1d1d1f; }
.auto-modal-body { padding:20px 24px; }
.auto-modal-footer { padding:16px 24px; border-top:1px solid #e5e5e7; display:flex; justify-content:flex-end; gap:8px; }

/* Form elements */
.auto-field { margin-bottom:16px; }
.auto-field label { display:block; font-size:13px; font-weight:500; color:#1d1d1f; margin-bottom:6px; }
.auto-field input, .auto-field select, .auto-field textarea {
    width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:13px;
    background:#fff; color:#1d1d1f; transition:border-color .15s; box-sizing:border-box;
}
.auto-field input:focus, .auto-field select:focus, .auto-field textarea:focus {
    outline:none; border-color:#0071e3; box-shadow:0 0 0 3px rgba(0,113,227,.12);
}
.auto-field textarea { min-height:60px; resize:vertical; }

/* Section headers in modal */
.section-label { font-size:13px; font-weight:600; color:#0071e3; text-transform:uppercase; letter-spacing:.5px; margin:20px 0 10px; padding-bottom:6px; border-bottom:1px solid #e8f4ff; }
.section-label:first-child { margin-top:0; }

/* Condition row */
.condition-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; flex-wrap:wrap; }
.condition-row select, .condition-row input { flex:1; min-width:100px; }
.condition-remove { background:none; border:none; color:#dc2626; cursor:pointer; font-size:18px; padding:4px; flex-shrink:0; }
.add-condition-btn { font-size:12px; color:#0071e3; background:none; border:none; cursor:pointer; font-weight:500; padding:4px 0; }
.add-condition-btn:hover { text-decoration:underline; }

/* Trigger config fields */
.trigger-config { background:#f9fafb; border-radius:8px; padding:12px; margin-top:8px; }

/* Action config fields */
.action-config { background:#f0fdf4; border-radius:8px; padding:12px; margin-top:8px; }

/* Variable picker */
.var-picker { margin-top:6px; }
.var-picker-label { font-size:11px; color:#86868b; margin-bottom:4px; }
.var-tags { display:flex; flex-wrap:wrap; gap:4px; }
.var-tag { display:inline-block; padding:3px 8px; background:#e8f4ff; color:#0071e3; border-radius:4px; font-size:11px; font-weight:500; cursor:pointer; border:1px solid transparent; transition:all .15s; user-select:none; }
.var-tag:hover { background:#0071e3; color:#fff; }
.var-tag:active { transform:scale(.95); }

/* Template preview */
.tpl-preview { background:#f9fafb; border:1px solid #e5e5e7; border-radius:6px; padding:8px 10px; margin-top:6px; font-size:12px; color:#6e6e73; line-height:1.5; max-height:80px; overflow-y:auto; }
.tpl-preview .tpl-subject { font-weight:600; color:#1d1d1f; margin-bottom:2px; }
.tpl-type-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:600; margin-left:6px; vertical-align:middle; }
.tpl-type-badge.twilio { background:#e0f2fe; color:#0369a1; }
.tpl-type-badge.local { background:#fef3c7; color:#92400e; }

/* Logs table */
.logs-table { width:100%; border-collapse:collapse; font-size:13px; }
.logs-table th { text-align:left; padding:10px 12px; color:#86868b; font-weight:500; border-bottom:1px solid #e5e5e7; font-size:12px; }
.logs-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; color:#1d1d1f; }
.logs-table tr:hover td { background:#f9fafb; }
.log-status { padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600; }
.log-status.success { background:#dcfce7; color:#16a34a; }
.log-status.failed { background:#fef2f2; color:#dc2626; }
.log-status.skipped { background:#fef9c3; color:#a16207; }

.empty-state { text-align:center; padding:60px 20px; color:#86868b; }
.empty-state svg { opacity:.3; margin-bottom:12px; }
.empty-state p { font-size:14px; margin:0; }

/* Pagination */
.logs-pagination { display:flex; justify-content:center; gap:4px; margin-top:16px; }
.logs-pagination button { padding:6px 12px; border:1px solid #d1d5db; background:#fff; border-radius:6px; cursor:pointer; font-size:12px; }
.logs-pagination button.active { background:#0071e3; color:#fff; border-color:#0071e3; }
.logs-pagination button:hover:not(.active) { background:#f5f5f7; }
.logs-pagination button:disabled { opacity:.4; cursor:not-allowed; }

/* Responsive */
@media (max-width:640px) {
    .auto-modal { margin:0; border-radius:12px; }
    .condition-row { flex-direction:column; }
    .condition-row select, .condition-row input { min-width:100%; }
    .rule-top { flex-direction:column; }
}
</style>

<div class="auto-header">
    <h1>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0071e3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Automation
    </h1>
    <button class="btn btn-primary" onclick="openRuleModal()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Rule
    </button>
</div>

<!-- Stats -->
<div class="auto-stats" id="autoStats"></div>

<!-- Tabs -->
<div class="auto-tabs">
    <button class="auto-tab active" data-tab="rules" onclick="switchTab('rules')">Rules</button>
    <button class="auto-tab" data-tab="logs" onclick="switchTab('logs')">Execution Logs</button>
</div>

<!-- Rules Tab -->
<div class="tab-pane active" id="tabRules">
    <div id="rulesList"></div>
</div>

<!-- Logs Tab -->
<div class="tab-pane" id="tabLogs">
    <div id="logsList"></div>
</div>

<!-- Rule Modal -->
<div class="auto-modal-overlay" id="ruleModal">
    <div class="auto-modal">
        <div class="auto-modal-header">
            <h2 id="ruleModalTitle">New Automation Rule</h2>
            <button class="auto-modal-close" onclick="closeRuleModal()">&times;</button>
        </div>
        <div class="auto-modal-body" id="ruleModalBody">
            <!-- Populated by JS -->
        </div>
        <div class="auto-modal-footer">
            <button class="btn btn-secondary" onclick="closeRuleModal()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveRule" onclick="saveRule()">Save Rule</button>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';

const CSRF = <?php echo json_encode($csrfToken); ?>;
const API  = '/api/automation.php';

let meta     = null;   // trigger/condition/action metadata
let rules    = [];
let editId   = null;   // null = create, int = edit
let logsPage = 1;

// ── Init ─────────────────────────────────────────────
async function init() {
    await loadMeta();
    await loadRules();
}

async function api(action, body = null) {
    const opts = { headers: { 'Content-Type': 'application/json' } };
    if (body) {
        opts.method = 'POST';
        opts.body = JSON.stringify({ ...body, csrf_token: CSRF });
    }
    const url = API + '?action=' + action + '&_cb=' + Date.now();
    const r = await fetch(url, opts);
    return r.json();
}

async function loadMeta() {
    const res = await api('meta');
    if (res.success) meta = res.data;
}

async function loadRules() {
    const res = await api('list');
    if (res.success) {
        rules = res.data;
        renderRules();
        renderStats();
    }
}

// ── Stats ────────────────────────────────────────────
function renderStats() {
    const total  = rules.length;
    const active = rules.filter(r => r.is_active == 1).length;
    const runs   = rules.reduce((s, r) => s + parseInt(r.run_count || 0), 0);
    document.getElementById('autoStats').innerHTML = `
        <div class="auto-stat"><div class="stat-value">${total}</div><div class="stat-label">Total Rules</div></div>
        <div class="auto-stat"><div class="stat-value">${active}</div><div class="stat-label">Active Rules</div></div>
        <div class="auto-stat"><div class="stat-value">${runs}</div><div class="stat-label">Total Executions</div></div>
    `;
}

// ── Rules List ───────────────────────────────────────
function renderRules() {
    const c = document.getElementById('rulesList');
    if (!rules.length) {
        c.innerHTML = `<div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <p>No automation rules yet. Click <strong>New Rule</strong> to create one.</p>
        </div>`;
        return;
    }

    c.innerHTML = rules.map(r => {
        const trigLabel = meta?.triggers?.find(t => t.value === r.trigger_type)?.label || r.trigger_type;
        const actLabel  = meta?.actions?.find(a => a.value === r.action_type)?.label || r.action_type;
        const conds     = r.conditions ? JSON.parse(r.conditions) : [];
        const condCount = Array.isArray(conds) ? conds.length : 0;
        const lastRun   = r.last_success ? timeAgo(r.last_success) : 'Never';
        const checked   = r.is_active == 1 ? 'checked' : '';

        return `<div class="rule-card" data-id="${r.rule_id}">
            <div class="rule-top">
                <div style="flex:1;">
                    <div class="rule-name">${esc(r.name)}</div>
                    ${r.description ? `<div class="rule-desc">${esc(r.description)}</div>` : ''}
                    <div class="rule-badges">
                        <span class="rule-badge badge-trigger">WHEN: ${esc(trigLabel)}</span>
                        ${condCount ? `<span class="rule-badge badge-conditions">IF: ${condCount} condition${condCount>1?'s':''}</span>` : ''}
                        <span class="rule-badge badge-action">THEN: ${esc(actLabel)}</span>
                    </div>
                    <div class="rule-meta">
                        <span>Runs: ${r.run_count || 0}</span>
                        <span>Last run: ${lastRun}</span>
                        ${r.created_by_name ? `<span>By: ${esc(r.created_by_name)}</span>` : ''}
                    </div>
                </div>
                <div class="rule-actions">
                    <label class="toggle" title="${r.is_active == 1 ? 'Active' : 'Inactive'}">
                        <input type="checkbox" ${checked} onchange="toggleRule(${r.rule_id})">
                        <span class="slider"></span>
                    </label>
                    <button title="Edit" onclick="editRule(${r.rule_id})">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button title="View Logs" onclick="viewRuleLogs(${r.rule_id})">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </button>
                    <button class="btn-danger" title="Delete" onclick="deleteRule(${r.rule_id}, '${esc(r.name)}')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </div>
        </div>`;
    }).join('');
}

// ── Tabs ─────────────────────────────────────────────
window.switchTab = function(tab) {
    document.querySelectorAll('.auto-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById(tab === 'rules' ? 'tabRules' : 'tabLogs').classList.add('active');
    if (tab === 'logs') loadLogs();
};

// ── Logs ─────────────────────────────────────────────
async function loadLogs(ruleId) {
    const params = ruleId ? `&rule_id=${ruleId}` : '';
    const res = await api('logs' + params + '&page=' + logsPage);
    if (!res.success) return;
    renderLogs(res.data, res.total, res.pages, ruleId);
}

function renderLogs(logs, total, pages, ruleId) {
    const c = document.getElementById('logsList');
    if (!logs.length) {
        c.innerHTML = '<div class="empty-state"><p>No execution logs yet.</p></div>';
        return;
    }

    let html = `<table class="logs-table">
        <thead><tr>
            <th>Date</th><th>Rule</th><th>Trigger</th><th>Lead</th><th>Status</th><th>Action</th><th>Time</th>
        </tr></thead><tbody>`;

    logs.forEach(l => {
        const leadName = l.contact_person || l.company_name || (l.lead_id ? '#'+l.lead_id : '-');
        html += `<tr>
            <td>${formatDt(l.created_at)}</td>
            <td>${esc(l.rule_name || '-')}</td>
            <td>${esc(l.trigger_type)}</td>
            <td>${l.lead_id ? `<a href="/pages/lead-detail.php?id=${l.lead_id}">${esc(leadName)}</a>` : '-'}</td>
            <td><span class="log-status ${l.status}">${l.status}</span></td>
            <td title="${esc(l.error_message || l.action_taken || '')}">${esc(truncate(l.action_taken || l.error_message || '-', 60))}</td>
            <td>${l.execution_ms != null ? l.execution_ms + 'ms' : '-'}</td>
        </tr>`;
    });

    html += '</tbody></table>';

    // Pagination
    if (pages > 1) {
        html += '<div class="logs-pagination">';
        html += `<button ${logsPage<=1?'disabled':''} onclick="changePage(${logsPage-1}${ruleId?','+ruleId:''})">Prev</button>`;
        for (let i = 1; i <= Math.min(pages, 10); i++) {
            html += `<button class="${i===logsPage?'active':''}" onclick="changePage(${i}${ruleId?','+ruleId:''})">${i}</button>`;
        }
        html += `<button ${logsPage>=pages?'disabled':''} onclick="changePage(${logsPage+1}${ruleId?','+ruleId:''})">Next</button>`;
        html += '</div>';
    }

    c.innerHTML = html;
}

window.changePage = function(p, ruleId) { logsPage = p; loadLogs(ruleId); };

window.viewRuleLogs = function(ruleId) {
    logsPage = 1;
    switchTab('logs');
    loadLogs(ruleId);
};

// ── Modal: Open / Close ──────────────────────────────
window.openRuleModal = function(rule) {
    editId = rule ? rule.rule_id : null;
    document.getElementById('ruleModalTitle').textContent = editId ? 'Edit Rule' : 'New Automation Rule';
    renderModalForm(rule);
    document.getElementById('ruleModal').classList.add('show');
};

window.closeRuleModal = function() {
    document.getElementById('ruleModal').classList.remove('show');
    editId = null;
};

// Close on backdrop click
document.getElementById('ruleModal').addEventListener('click', function(e) {
    if (e.target === this) closeRuleModal();
});

window.editRule = async function(id) {
    const res = await api('get&id=' + id);
    if (res.success) {
        const r = res.data;
        r.trigger_config = r.trigger_config ? JSON.parse(r.trigger_config) : {};
        r.conditions     = r.conditions ? JSON.parse(r.conditions) : [];
        r.action_config  = r.action_config ? JSON.parse(r.action_config) : {};
        openRuleModal(r);
    }
};

// ── Modal: Render form ───────────────────────────────
function renderModalForm(rule) {
    const r = rule || {};
    const conds = Array.isArray(r.conditions) ? r.conditions : [];
    const tCfg  = r.trigger_config || {};
    const aCfg  = r.action_config || {};

    let html = `
        <div class="auto-field">
            <label>Rule Name</label>
            <input id="fName" value="${esc(r.name || '')}" placeholder="e.g. UAE leads to Ahmed">
        </div>
        <div class="auto-field">
            <label>Description <span style="color:#86868b;font-weight:400;">(optional)</span></label>
            <input id="fDesc" value="${esc(r.description || '')}" placeholder="Short description of what this rule does">
        </div>

        <div class="section-label">WHEN (Trigger)</div>
        <div class="auto-field">
            <label>Trigger Event</label>
            <select id="fTrigger" onchange="onTriggerChange()">
                <option value="">Select trigger...</option>
                ${meta.triggers.map(t => `<option value="${t.value}" ${r.trigger_type===t.value?'selected':''}>${t.label}</option>`).join('')}
            </select>
            <div id="triggerConfig"></div>
        </div>

        <div class="section-label">IF (Conditions) <span style="font-weight:400;font-size:11px;color:#86868b;">— optional, all must match</span></div>
        <div id="conditionsContainer"></div>
        <button class="add-condition-btn" onclick="addCondition()">+ Add Condition</button>

        <div class="section-label">THEN (Action)</div>
        <div class="auto-field">
            <label>Action</label>
            <select id="fAction" onchange="onActionChange()">
                <option value="">Select action...</option>
                ${meta.actions.map(a => `<option value="${a.value}" ${r.action_type===a.value?'selected':''}>${a.label}</option>`).join('')}
            </select>
            <div id="actionConfig"></div>
        </div>
    `;

    document.getElementById('ruleModalBody').innerHTML = html;

    // Restore conditions
    conds.forEach(c => addCondition(c));

    // Restore trigger config
    onTriggerChange(tCfg);

    // Restore action config
    onActionChange(aCfg);
}

// ── Trigger config UI ────────────────────────────────
window.onTriggerChange = function(savedCfg) {
    const trig = document.getElementById('fTrigger').value;
    const c = document.getElementById('triggerConfig');
    const cfg = savedCfg || {};

    if (trig === 'lead_status_changed') {
        c.innerHTML = `<div class="trigger-config">
            <div class="auto-field" style="margin-bottom:8px;">
                <label>From Status <span style="color:#86868b;font-weight:400;">(optional)</span></label>
                <select id="tcFromStatus">
                    <option value="">Any</option>
                    ${meta.lead_statuses.map(s => `<option value="${s}" ${cfg.from_status===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div>
            <div class="auto-field" style="margin-bottom:0;">
                <label>To Status <span style="color:#86868b;font-weight:400;">(optional)</span></label>
                <select id="tcToStatus">
                    <option value="">Any</option>
                    ${meta.lead_statuses.map(s => `<option value="${s}" ${cfg.to_status===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div>
        </div>`;
    } else if (trig === 'lead_source_match') {
        c.innerHTML = `<div class="trigger-config">
            <div class="auto-field" style="margin-bottom:0;">
                <label>Lead Source</label>
                <select id="tcLeadSource">
                    ${meta.condition_fields.find(f=>f.value==='lead_source').options.map(s => `<option value="${s}" ${cfg.lead_source===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div>
        </div>`;
    } else if (trig === 'proposal_status_changed') {
        c.innerHTML = `<div class="trigger-config">
            <div class="auto-field" style="margin-bottom:8px;">
                <label>From Status <span style="color:#86868b;font-weight:400;">(optional)</span></label>
                <select id="tcFromStatus">
                    <option value="">Any</option>
                    ${meta.proposal_statuses.map(s => `<option value="${s}" ${cfg.from_status===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div>
            <div class="auto-field" style="margin-bottom:0;">
                <label>To Status <span style="color:#86868b;font-weight:400;">(optional)</span></label>
                <select id="tcToStatus">
                    <option value="">Any</option>
                    ${meta.proposal_statuses.map(s => `<option value="${s}" ${cfg.to_status===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div>
        </div>`;
    } else {
        c.innerHTML = '';
    }
};

// ── Variable picker helper ───────────────────────────
function buildVarPicker(targetId) {
    const vars = meta.template_vars || [];
    if (!vars.length) return '';
    return `<div class="var-picker">
        <div class="var-picker-label">Click to insert variable into the field above:</div>
        <div class="var-tags">
            ${vars.map(v => `<span class="var-tag" title="${esc(v.desc)}" onclick="insertVar('${targetId}','${v.tag}')">${esc(v.label)}</span>`).join('')}
        </div>
    </div>`;
}

window.insertVar = function(targetId, tag) {
    const el = document.getElementById(targetId);
    if (!el) return;
    // Insert at cursor position if it's a textarea/input
    const start = el.selectionStart ?? el.value.length;
    const end   = el.selectionEnd ?? el.value.length;
    const val   = el.value;
    el.value = val.substring(0, start) + tag + val.substring(end);
    el.focus();
    const newPos = start + tag.length;
    el.setSelectionRange(newPos, newPos);
};

// ── Template preview helpers ─────────────────────────
function getEmailTemplatePreview(templateId) {
    const t = meta.email_templates.find(t => t.template_id == templateId);
    if (!t) return '';
    return `<div class="tpl-preview">${t.subject ? `<div class="tpl-subject">Subject: ${esc(t.subject)}</div>` : ''}<em>Variables like {{contact_name}} will be replaced with lead data when sent.</em></div>`;
}

function getWaTemplatePreview(waId) {
    const t = meta.wa_templates.find(t => t.id === waId);
    if (!t) return '';
    const bodyPreview = t.body ? esc(t.body).substring(0, 200) + (t.body.length > 200 ? '...' : '') : '<em>No preview available</em>';
    return `<div class="tpl-preview">${bodyPreview}</div>`;
}

// ── Action config UI ─────────────────────────────────
window.onActionChange = function(savedCfg) {
    const act = document.getElementById('fAction').value;
    const c = document.getElementById('actionConfig');
    const cfg = savedCfg || {};

    switch (act) {
        case 'assign_user':
            c.innerHTML = `<div class="action-config"><div class="auto-field" style="margin-bottom:0;">
                <label>Assign To</label>
                <select id="acUserId">
                    <option value="">Select user...</option>
                    ${meta.users.map(u => `<option value="${u.user_id}" ${cfg.user_id==u.user_id?'selected':''}>${esc(u.full_name)} (${u.role})</option>`).join('')}
                </select>
            </div></div>`;
            break;

        case 'send_email_template': {
            const selId = cfg.template_id || '';
            c.innerHTML = `<div class="action-config">
                <div class="auto-field" style="margin-bottom:8px;">
                    <label>Email Template</label>
                    <select id="acTemplateId" onchange="onEmailTplChange()">
                        <option value="">Select template...</option>
                        ${meta.email_templates.map(t => `<option value="${t.template_id}" ${selId==t.template_id?'selected':''}>${esc(t.name)}${t.subject ? ' — '+esc(t.subject) : ''}</option>`).join('')}
                    </select>
                    <div id="emailTplPreview">${selId ? getEmailTemplatePreview(selId) : ''}</div>
                </div>
                <div style="font-size:11px;color:#86868b;margin-top:4px;">
                    <strong>Supported variables in email templates:</strong> {{contact_name}}, {{company_name}}, {{email}}, {{phone}}, {{mobile}}, {{country}}, {{region}}, {{lead_type}}, {{lead_status}}, {{lead_source}}, {{priority}}
                </div>
            </div>`;
            break;
        }

        case 'send_whatsapp_template': {
            // Determine selected value: could be local_N, twilio_SID, or legacy integer
            let selVal = '';
            if (cfg.wa_template_id) {
                selVal = cfg.wa_template_id;
            } else if (cfg.content_sid) {
                selVal = 'twilio_' + cfg.content_sid;
            } else if (cfg.template_id) {
                selVal = 'local_' + cfg.template_id;
            }
            c.innerHTML = `<div class="action-config">
                <div class="auto-field" style="margin-bottom:8px;">
                    <label>WhatsApp Template</label>
                    <select id="acWaTemplateId" onchange="onWaTplChange()">
                        <option value="">Select template...</option>
                        ${meta.wa_templates.map(t => {
                            const typeBadge = t.type === 'twilio'
                                ? '<span class="tpl-type-badge twilio">Twilio</span>'
                                : '<span class="tpl-type-badge local">Local</span>';
                            return `<option value="${t.id}" ${selVal===t.id?'selected':''}>${esc(t.name)} ${t.language ? '('+t.language+')' : ''}</option>`;
                        }).join('')}
                    </select>
                    <div id="waTplPreview">${selVal ? getWaTemplatePreview(selVal) : ''}</div>
                </div>
                <div style="font-size:11px;color:#86868b;margin-top:4px;">
                    <strong>Template variables:</strong> Local templates support {{contact_name}}, {{company_name}}, {{user_name}}, {{country}}, {{region}}, {{lead_type}}. Twilio templates use {{1}}, {{2}}, etc.
                </div>
            </div>`;
            break;
        }

        case 'send_notification_email':
            c.innerHTML = `<div class="action-config">
                <div class="auto-field" style="margin-bottom:8px;">
                    <label>Recipient</label>
                    <select id="acRecipient" onchange="toggleSpecificEmail()">
                        <option value="assigned_user" ${cfg.recipient==='assigned_user'?'selected':''}>Assigned User</option>
                        <option value="creator" ${cfg.recipient==='creator'?'selected':''}>Lead Creator</option>
                        <option value="specific_email" ${cfg.recipient==='specific_email'?'selected':''}>Specific Email</option>
                    </select>
                </div>
                <div class="auto-field" style="margin-bottom:8px;display:${cfg.recipient==='specific_email'?'block':'none'};" id="specificEmailField">
                    <label>Email Address</label>
                    <input id="acEmail" value="${esc(cfg.email || '')}" placeholder="user@example.com">
                </div>
                <div class="auto-field" style="margin-bottom:4px;">
                    <label>Subject</label>
                    <input id="acSubject" value="${esc(cfg.subject || 'Automation Alert')}" placeholder="Email subject — use variables like {{contact_name}}">
                </div>
                ${buildVarPicker('acSubject')}
                <div class="auto-field" style="margin-bottom:4px;margin-top:12px;">
                    <label>Body <span style="color:#86868b;font-weight:400;">(optional — variables supported)</span></label>
                    <textarea id="acBody" placeholder="Extra message text... Use {{contact_name}}, {{company_name}}, etc.">${esc(cfg.body || '')}</textarea>
                </div>
                ${buildVarPicker('acBody')}
            </div>`;
            break;

        case 'change_lead_status':
            c.innerHTML = `<div class="action-config"><div class="auto-field" style="margin-bottom:0;">
                <label>New Status</label>
                <select id="acStatus">
                    ${meta.lead_statuses.map(s => `<option value="${s}" ${cfg.status===s?'selected':''}>${s}</option>`).join('')}
                </select>
            </div></div>`;
            break;

        case 'change_priority':
            c.innerHTML = `<div class="action-config"><div class="auto-field" style="margin-bottom:0;">
                <label>New Priority</label>
                <select id="acPriority">
                    ${meta.priorities.map(p => `<option value="${p}" ${cfg.priority===p?'selected':''}>${p}</option>`).join('')}
                </select>
            </div></div>`;
            break;

        case 'log_interaction':
            c.innerHTML = `<div class="action-config">
                <div class="auto-field" style="margin-bottom:4px;">
                    <label>Note Text <span style="color:#86868b;font-weight:400;">(variables supported)</span></label>
                    <textarea id="acNote" placeholder="Note to log on the lead... Use {{contact_name}}, {{company_name}}, etc.">${esc(cfg.note || '')}</textarea>
                </div>
                ${buildVarPicker('acNote')}
            </div>`;
            break;

        default:
            c.innerHTML = '';
    }
};

window.onEmailTplChange = function() {
    const id = document.getElementById('acTemplateId')?.value;
    document.getElementById('emailTplPreview').innerHTML = id ? getEmailTemplatePreview(id) : '';
};

window.onWaTplChange = function() {
    const id = document.getElementById('acWaTemplateId')?.value;
    document.getElementById('waTplPreview').innerHTML = id ? getWaTemplatePreview(id) : '';
};

window.toggleSpecificEmail = function() {
    const r = document.getElementById('acRecipient');
    const f = document.getElementById('specificEmailField');
    if (f) f.style.display = r.value === 'specific_email' ? 'block' : 'none';
};

// ── Conditions ───────────────────────────────────────
let condCount = 0;
window.addCondition = function(saved) {
    const s = saved || {};
    const idx = condCount++;
    const row = document.createElement('div');
    row.className = 'condition-row';
    row.id = 'cond_' + idx;

    // Field select
    let fieldOpts = meta.condition_fields.map(f =>
        `<option value="${f.value}" ${s.field===f.value?'selected':''}>${f.label}</option>`
    ).join('');

    // Operator select
    let opOpts = meta.condition_operators.map(o =>
        `<option value="${o.value}" ${s.operator===o.value?'selected':''}>${o.label}</option>`
    ).join('');

    row.innerHTML = `
        <select class="cond-field" onchange="onCondFieldChange(${idx})">
            <option value="">Field...</option>
            ${fieldOpts}
        </select>
        <select class="cond-op">
            ${opOpts}
        </select>
        <span class="cond-value-wrap"></span>
        <button class="condition-remove" onclick="removeCondition(${idx})">&times;</button>
    `;

    document.getElementById('conditionsContainer').appendChild(row);

    // Render value field based on chosen field type
    renderCondValueField(idx, s.field, s.value);
};

window.removeCondition = function(idx) {
    const el = document.getElementById('cond_' + idx);
    if (el) el.remove();
};

window.onCondFieldChange = function(idx) {
    const row = document.getElementById('cond_' + idx);
    const field = row.querySelector('.cond-field').value;
    renderCondValueField(idx, field, '');
};

function renderCondValueField(idx, field, savedValue) {
    const row = document.getElementById('cond_' + idx);
    const wrap = row.querySelector('.cond-value-wrap');
    const fDef = meta.condition_fields.find(f => f.value === field);

    if (!fDef) {
        wrap.innerHTML = `<input class="cond-value" placeholder="Value..." value="${esc(savedValue || '')}">`;
        return;
    }

    if (fDef.type === 'enum') {
        wrap.innerHTML = `<select class="cond-value">
            <option value="">Select...</option>
            ${fDef.options.map(o => `<option value="${o}" ${savedValue===o?'selected':''}>${o}</option>`).join('')}
        </select>`;
    } else if (fDef.type === 'user') {
        wrap.innerHTML = `<select class="cond-value">
            <option value="">Select user...</option>
            ${meta.users.map(u => `<option value="${u.user_id}" ${savedValue==u.user_id?'selected':''}>${esc(u.full_name)}</option>`).join('')}
        </select>`;
    } else {
        wrap.innerHTML = `<input class="cond-value" placeholder="Value..." value="${esc(savedValue || '')}">`;
    }
}

// ── Collect form data ────────────────────────────────
function collectFormData() {
    const name        = (document.getElementById('fName').value || '').trim();
    const description = (document.getElementById('fDesc').value || '').trim();
    const triggerType = document.getElementById('fTrigger').value;
    const actionType  = document.getElementById('fAction').value;

    if (!name || !triggerType || !actionType) {
        alert('Please fill in the rule name, trigger, and action.');
        return null;
    }

    // Trigger config
    let triggerConfig = {};
    if (triggerType === 'lead_status_changed' || triggerType === 'proposal_status_changed') {
        const fs = document.getElementById('tcFromStatus');
        const ts = document.getElementById('tcToStatus');
        if (fs && fs.value) triggerConfig.from_status = fs.value;
        if (ts && ts.value) triggerConfig.to_status = ts.value;
    } else if (triggerType === 'lead_source_match') {
        const ls = document.getElementById('tcLeadSource');
        if (ls && ls.value) triggerConfig.lead_source = ls.value;
    }

    // Conditions
    const conditions = [];
    document.querySelectorAll('.condition-row').forEach(row => {
        const field = row.querySelector('.cond-field')?.value;
        const op    = row.querySelector('.cond-op')?.value;
        const val   = row.querySelector('.cond-value')?.value;
        if (field && op) {
            conditions.push({ field, operator: op, value: val || '' });
        }
    });

    // Action config
    let actionConfig = {};
    switch (actionType) {
        case 'assign_user':
            actionConfig.user_id = document.getElementById('acUserId')?.value;
            if (!actionConfig.user_id) { alert('Please select a user to assign.'); return null; }
            break;
        case 'send_email_template':
            actionConfig.template_id = document.getElementById('acTemplateId')?.value;
            if (!actionConfig.template_id) { alert('Please select an email template.'); return null; }
            break;
        case 'send_whatsapp_template': {
            const waVal = document.getElementById('acWaTemplateId')?.value;
            if (!waVal) { alert('Please select a WhatsApp template.'); return null; }
            // Store the composite id so we know which type
            actionConfig.wa_template_id = waVal;
            const waTpl = meta.wa_templates.find(t => t.id === waVal);
            if (waTpl) {
                if (waTpl.type === 'twilio') {
                    actionConfig.content_sid = waTpl.content_sid;
                } else {
                    actionConfig.template_id = waTpl.template_id;
                }
            }
            break;
        }
        case 'send_notification_email':
            actionConfig.recipient = document.getElementById('acRecipient')?.value || 'assigned_user';
            actionConfig.subject   = document.getElementById('acSubject')?.value || 'Automation Alert';
            actionConfig.body      = document.getElementById('acBody')?.value || '';
            if (actionConfig.recipient === 'specific_email') {
                actionConfig.email = document.getElementById('acEmail')?.value;
                if (!actionConfig.email) { alert('Please enter an email address.'); return null; }
            }
            break;
        case 'change_lead_status':
            actionConfig.status = document.getElementById('acStatus')?.value;
            if (!actionConfig.status) { alert('Please select a status.'); return null; }
            break;
        case 'change_priority':
            actionConfig.priority = document.getElementById('acPriority')?.value;
            if (!actionConfig.priority) { alert('Please select a priority.'); return null; }
            break;
        case 'log_interaction':
            actionConfig.note = document.getElementById('acNote')?.value || 'Automation triggered';
            break;
    }

    return {
        name, description, trigger_type: triggerType, trigger_config: triggerConfig,
        conditions, action_type: actionType, action_config: actionConfig,
    };
}

// ── Save rule ────────────────────────────────────────
window.saveRule = async function() {
    const data = collectFormData();
    if (!data) return;

    const btn = document.getElementById('btnSaveRule');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    try {
        if (editId) data.rule_id = editId;
        const action = editId ? 'update' : 'create';
        const res = await api(action, data);

        if (res.success) {
            closeRuleModal();
            await loadRules();
        } else {
            alert(res.message || 'Failed to save rule.');
        }
    } catch (e) {
        alert('Error saving rule: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save Rule';
    }
};

// ── Toggle rule ──────────────────────────────────────
window.toggleRule = async function(id) {
    await api('toggle', { rule_id: id });
    await loadRules();
};

// ── Delete rule ──────────────────────────────────────
window.deleteRule = async function(id, name) {
    if (!confirm(`Delete rule "${name}"? This will also delete all execution logs for this rule.`)) return;
    const res = await api('delete', { rule_id: id });
    if (res.success) await loadRules();
    else alert(res.message || 'Delete failed.');
};

// ── Helpers ──────────────────────────────────────────
function esc(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}

function truncate(s, n) {
    return s.length > n ? s.substring(0, n) + '...' : s;
}

function timeAgo(dt) {
    if (!dt) return 'Never';
    const diff = (Date.now() - new Date(dt + ' UTC').getTime()) / 1000;
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
    return formatDt(dt);
}

function formatDt(dt) {
    if (!dt) return '-';
    const d = new Date(dt + (dt.includes('T') || dt.includes('Z') ? '' : ' UTC'));
    return d.toLocaleDateString('en-US', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

// ── Boot ─────────────────────────────────────────────
init();

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
