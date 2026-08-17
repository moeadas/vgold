// VGo native CRM module views (Knowledge, Proposals, Automations, Email,
// Communications, Reports). These replace the iframe-embedded legacy pages with
// fully-native SPA views + builders, reskinned to match the original Victory
// Genomics CRM design system (classes scoped under `.crm-native`).
//
// Loads AFTER crm.js and overrides window.renderCrmModule. Every top-level view
// return and every Modal body is wrapped in `<div class="crm-native">…</div>` so
// the scoped design-system styles apply (the global Modal renders outside the
// view wrapper, so its body needs its own wrapper).

const CrmMod = { cache: {}, tab: {}, voip: { device: null, call: null, ready: false, seconds: 0, timer: null, callId: null, muted: false } };

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
/**
 * Multipart sibling of crmApiPost, for endpoints that read $_FILES.
 * JSON bodies cannot carry files, so anything with attachments goes through here.
 */
async function crmApiPostForm(path, formData, _retried) {
  formData.set('csrf_token', await crmCsrf());
  const res = await fetch('/crm/api/' + path, { method: 'POST', credentials: 'same-origin', body: formData });
  if (res.status === 413) throw new Error('The attachments are too large for the server to accept. Remove or shrink a file and try again.');
  const data = await res.json().catch(() => ({ success: false, message: 'The server returned an unexpected response.' }));
  if (data && data.message && /csrf|expired|refresh/i.test(data.message) && !_retried) {
    _crmCsrfToken = null; // token rotated — refetch once and retry
    return crmApiPostForm(path, formData, true);
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

// ---- shared markup helpers (original design-system classes) ------------
function crmModError(label, msg) {
  return `<div class="crm-native fade-in"><div class="card" style="max-width:520px;margin:48px auto">
    <div class="card-body empty-state"><h3>${esc(label || 'Something went wrong')}</h3>
    <p>${esc(msg || 'This section could not be loaded.')}</p>
    <button class="btn btn-primary btn-sm" style="margin-top:16px" onclick="render()">Retry</button></div></div></div>`;
}
function crmModHead(title, subtitle, actionsHtml) {
  return `<div class="page-header">
    <div><h1 class="page-title">${esc(title)}</h1>${subtitle ? `<p class="page-subtitle">${esc(subtitle)}</p>` : ''}</div>
    ${actionsHtml ? `<div class="card-header-actions">${actionsHtml}</div>` : ''}
  </div>`;
}
function crmTabBar(items, active, fnName) {
  return `<div class="ct-tabs">${items.map(([k, l]) =>
    `<button class="ct-tab ${k === active ? 'active' : ''}" onclick="${fnName}('${k}')">${esc(l)}</button>`).join('')}</div>`;
}
function crmTable(cols, rowsHtml, empty) {
  return `<div class="card"><div class="table-container"><table class="table">
    <thead><tr>${cols.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead>
    <tbody>${rowsHtml || `<tr><td colspan="${cols.length}" class="ct-empty-cell">${esc(empty || 'Nothing here yet.')}</td></tr>`}</tbody>
  </table></div></div>`;
}
const CT_IC = {
  phone: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
  clock: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  check: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
  mail: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
  send: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
  users: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  doc: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
  chart: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
  edit: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
};
function crmStatRow(items) {
  return `<div class="stats-grid">${items.map(it => {
    const label = it.label ?? it[0], value = it.value ?? it[1], icon = it.icon ?? it[2] ?? '', cls = it.iconClass ?? it[3] ?? '';
    return `<div class="stat-card"><div class="stat-icon ${cls}">${icon || '<span style="font-size:8px">●</span>'}</div>
      <div class="stat-content"><div class="stat-label">${esc(label)}</div><div class="stat-value">${value == null ? 0 : esc(value)}</div></div></div>`;
  }).join('')}</div>`;
}
function crmBadge(text, cls) { return `<span class="badge ${cls || 'badge-gray'}">${esc(text)}</span>`; }
function crmDuration(sec) { sec = Number(sec) || 0; const m = Math.floor(sec / 60), s = sec % 60; return m + ':' + String(s).padStart(2, '0'); }
function crmBars(rows, color) {
  if (!rows || !rows.length) return `<div class="empty-state"><p>No data yet.</p></div>`;
  const max = Math.max(1, ...rows.map(r => Number(r.value || 0)));
  return `<div class="ct-bars">${rows.map(r => `<div class="ct-bar-row">
    <span class="ct-bar-lbl">${esc(r.label || '—')}</span>
    <span class="ct-bar-track"><span class="ct-bar-fill" style="width:${Math.round((Number(r.value || 0) / max) * 100)}%;background:${color || 'var(--color-accent)'}"></span></span>
    <span class="ct-bar-val">${Number(r.value || 0)}</span></div>`).join('')}</div>`;
}
function crmWidenModal(px) {
  const box = Modal.current && Modal.current.querySelector('.modal');
  if (box) { box.style.width = px + 'px'; box.style.maxWidth = '96vw'; }
}
function crmModalBody(html) {
  // Every CRM modal body must carry the scoped design-system styles. Modals can
  // be opened from OUTSIDE the CRM module screens (e.g. the lead detail page's
  // Call / WhatsApp quick actions), where renderCrmModule() never ran — so inject
  // here too instead of relying on the dispatcher.
  ensureCrmModStyles();
  return `<div class="crm-native">${html}</div>`;
}

// ---- phone helpers -----------------------------------------------------
// Imported lead data frequently carries placeholders ("NA", "N/A", "-", "none")
// in the phone/mobile columns. Sending those to Twilio produced
// `The 'To' number whatsapp:+ is not a valid phone number` (HTTP 400).
// crmDigits/crmIsPhone gate every dial + message path, and crmLeadPhone picks
// the first field that actually holds a dialable number.
function crmDigits(v) { return String(v == null ? '' : v).replace(/[^0-9]/g, ''); }
function crmIsPhone(v) {
  const s = String(v == null ? '' : v).trim();
  if (!s) return false;
  if (/^(na|n\/a|none|null|nil|tbd|unknown|-+)$/i.test(s)) return false;
  const d = crmDigits(s);
  return d.length >= 7 && d.length <= 15;
}
function crmLeadPhone(lead) {
  if (!lead) return '';
  const candidates = [lead.mobile, lead.phone, lead.whatsapp, lead.phone_number, lead.contact_number];
  for (const c of candidates) { if (crmIsPhone(c)) return String(c).trim(); }
  return '';
}
window.crmDigits = crmDigits; window.crmIsPhone = crmIsPhone; window.crmLeadPhone = crmLeadPhone;

function ensureCrmModStyles() {
  if (typeof ensureCrmDetailStyles === 'function') ensureCrmDetailStyles();
  if (typeof document === 'undefined' || document.getElementById('crm-mod-styles')) return;
  const s = document.createElement('style');
  s.id = 'crm-mod-styles';
  s.textContent = `
.modal-body > .crm-native{min-height:0;background:transparent}
.crm-native .ct-tabs{display:flex;gap:4px;border-bottom:1px solid var(--color-border);margin-bottom:20px;flex-wrap:wrap}
.crm-native .ct-tab{background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-1px;padding:10px 18px;font-size:13px;font-weight:600;color:var(--color-text-secondary);cursor:pointer;font-family:inherit}
.crm-native .ct-tab:hover{color:var(--color-text)}
.crm-native .ct-tab.active{color:var(--color-accent);border-bottom-color:var(--color-accent)}
.crm-native .ct-empty-cell{text-align:center;color:var(--color-text-tertiary);padding:40px 20px;font-size:13px}
.crm-native .ct-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.crm-native .ct-toolbar .form-control{max-width:300px}
.crm-native .ct-filter-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.crm-native .ct-chip{font-size:12px;font-weight:500;padding:5px 14px;border-radius:20px;border:1px solid var(--color-border);background:var(--color-surface);cursor:pointer;color:var(--color-text-secondary);font-family:inherit}
.crm-native .ct-chip:hover{border-color:var(--color-text-secondary)}
.crm-native .ct-chip.active{background:var(--color-text);color:#fff;border-color:var(--color-text)}
.crm-native .ct-guides{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.crm-native .ct-guide{display:flex;align-items:center;gap:16px;padding:20px 22px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:12px;position:relative;transition:all .2s}
.crm-native .ct-guide:hover{box-shadow:var(--shadow-md);transform:translateY(-2px)}
.crm-native .ct-guide-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.crm-native .ct-guide-body{flex:1;min-width:0}
.crm-native .ct-guide-title{font-size:15px;font-weight:700;margin-bottom:3px}
.crm-native .ct-guide-desc{font-size:12px;color:var(--color-text-secondary);line-height:1.5;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.crm-native .ct-guide-tag{font-size:10px;font-weight:600;padding:2px 8px;border-radius:4px;text-transform:uppercase;letter-spacing:.4px;margin-right:4px}
.crm-native .ct-guide-actions{position:absolute;top:8px;right:8px;display:flex;gap:4px;opacity:0;transition:opacity .15s}
.crm-native .ct-guide:hover .ct-guide-actions{opacity:1}
.crm-native .ct-guide-actions button{background:var(--color-surface);border:1px solid var(--color-border);border-radius:6px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--color-text-secondary)}
.crm-native .ct-guide-actions button:hover{background:var(--color-bg);color:var(--color-text)}
.crm-native .ct-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.crm-native .ct-li{border:1px solid var(--color-border);border-radius:8px;padding:14px;margin-bottom:10px;background:var(--color-bg)}
.crm-native .ct-li-grid1{display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:10px}
.crm-native .ct-li-grid2{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end}
.crm-native .ct-li .form-label{font-size:11px;margin-bottom:3px}
.crm-native .ct-total{display:flex;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:2px solid var(--color-border);text-align:right}
.crm-native .ct-total-val{font-size:22px;font-weight:700}
.crm-native .ct-color-dots{display:flex;gap:8px;margin-top:6px;flex-wrap:wrap}
.crm-native .ct-color-dot{width:26px;height:26px;border-radius:50%;cursor:pointer;border:3px solid transparent}
.crm-native .ct-color-dot.sel{border-color:var(--color-text)}
.crm-native .ct-color-box{color:var(--color-text-secondary);font-size:8px}
.crm-native .ct-secline{font-size:12px;color:var(--color-text-secondary)}
.crm-native .ct-section-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--color-text-secondary);margin:6px 0 8px}
.crm-native .ct-cond{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px}
.crm-native .ct-cond-x{background:none;border:none;color:var(--color-text-tertiary);font-size:18px;cursor:pointer;padding:0 6px}
.crm-native .ct-cond-x:hover{color:var(--color-danger)}
.crm-native .ct-cfgbox{background:var(--color-bg);border-radius:8px;padding:12px;margin-top:8px}
.crm-native .ct-switch{position:relative;display:inline-block;width:40px;height:22px}
.crm-native .ct-switch input{opacity:0;width:0;height:0}
.crm-native .ct-switch span{position:absolute;inset:0;background:#d1d1d6;border-radius:99px;transition:.2s;cursor:pointer}
.crm-native .ct-switch span:before{content:'';position:absolute;height:16px;width:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.crm-native .ct-switch input:checked+span{background:var(--color-success)}
.crm-native .ct-switch input:checked+span:before{transform:translateX(18px)}
.crm-native .ct-bars{display:flex;flex-direction:column;gap:4px}
.crm-native .ct-bar-row{display:flex;align-items:center;gap:12px;padding:4px 0;font-size:13px}
.crm-native .ct-bar-lbl{flex:0 0 130px;color:var(--color-text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.crm-native .ct-bar-track{flex:1;height:9px;background:var(--color-bg);border-radius:99px;overflow:hidden}
.crm-native .ct-bar-fill{display:block;height:100%;border-radius:99px}
.crm-native .ct-bar-val{flex:0 0 auto;font-weight:700}
.crm-native .ct-dialpad{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:260px;margin:14px auto}
.crm-native .ct-key{padding:14px 0;border:1px solid var(--color-border);border-radius:12px;background:var(--color-surface);font-size:18px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .1s}
.crm-native .ct-key:hover{background:var(--color-bg)}
.crm-native .ct-sp-num{font-size:22px;font-weight:600;text-align:center;letter-spacing:1px}
.crm-native .ct-sp-timer{font-size:30px;font-weight:700;text-align:center;font-variant-numeric:tabular-nums;margin:10px 0}
.crm-native .ct-sp-status{text-align:center;margin-bottom:8px}
.crm-native .ct-round{width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#fff}
.crm-native .ct-round.call{background:var(--color-success)}
.crm-native .ct-round.hang{background:var(--color-danger)}
.crm-native .ct-wa-thread{max-height:46vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:6px 2px 12px}
.crm-native .ct-wa-msg{display:flex}.crm-native .ct-wa-msg.out{justify-content:flex-end}
.crm-native .ct-wa-bubble{max-width:78%;padding:8px 12px;border-radius:14px;background:var(--color-bg);font-size:13.5px;line-height:1.4}
.crm-native .ct-wa-msg.out .ct-wa-bubble{background:#dcf3d8}
.crm-native .ct-wa-time{display:block;font-size:10px;color:var(--color-text-tertiary);margin-top:3px}
.crm-native .ct-compose{display:flex;gap:8px;margin-top:10px}.crm-native .ct-compose .form-control{flex:1}
.crm-native .ct-mono{background:var(--color-bg);font-family:ui-monospace,monospace;font-size:12px;min-height:200px}
.crm-native .ct-actions-cell{white-space:nowrap;display:flex;gap:6px;flex-wrap:wrap}
.crm-native .wa-green-btn{background:#25D366;border-color:#25D366;color:#fff}
.crm-native .wa-green-btn:hover{background:#1eb85a;border-color:#1eb85a;color:#fff}
.crm-native .wa-inbox-list{display:flex;flex-direction:column}
.crm-native .wa-inbox-item{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;border-bottom:1px solid var(--color-border);transition:background .15s}
.crm-native .wa-inbox-item:last-child{border-bottom:none}
.crm-native .wa-inbox-item:hover{background:var(--color-bg)}
.crm-native .wa-avatar{width:42px;height:42px;border-radius:50%;background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;flex-shrink:0}
.crm-native .wa-avatar.orange{background:#ff9500}
.crm-native .wa-inbox-main{flex:1;min-width:0}
.crm-native .wa-inbox-row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.crm-native .wa-inbox-name{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.crm-native .wa-inbox-last{font-size:13px;color:var(--color-text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px}
.crm-native .wa-inbox-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.crm-native .wa-unread{background:#25D366;color:#fff;font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px}
.crm-native .wa-chat-sub{font-size:12px;color:var(--color-text-secondary);margin:-4px 0 10px}
.crm-native .wa-window-banner{display:flex;align-items:center;gap:8px;font-size:12px;padding:8px 12px;border-radius:8px;margin-bottom:10px}
.crm-native .wa-window-open{background:#e7f7ec;color:#1a7f37}
.crm-native .wa-window-closed{background:#fff4e5;color:#b25e00}
.crm-native .ct-wa-media-img{max-width:220px;border-radius:10px;display:block;margin-bottom:4px}
.crm-native .ct-wa-media-doc{display:inline-flex;align-items:center;gap:6px;color:var(--color-accent);font-size:12px;text-decoration:none;margin-bottom:4px}
.crm-native .ct-wa-status{margin-left:4px;vertical-align:middle}
.crm-native .wa-tpl-panel{border:1px solid var(--color-border);border-radius:10px;margin-bottom:10px;max-height:240px;overflow-y:auto;background:var(--color-surface)}
.crm-native .wa-tpl-head{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;font-size:12px;font-weight:700;border-bottom:1px solid var(--color-border)}
.crm-native .wa-tpl-item{padding:10px 12px;border-bottom:1px solid var(--color-border);cursor:pointer}
.crm-native .wa-tpl-item:last-child{border-bottom:none}
.crm-native .wa-tpl-item:hover{background:var(--color-bg)}
.crm-native .wa-tpl-item.disabled{opacity:.55;cursor:default}
.crm-native .wa-tpl-name{font-size:13px;font-weight:600}
.crm-native .wa-tpl-cat{font-size:11px;color:var(--color-text-secondary);margin-top:2px}
.crm-native .wa-tpl-body{font-size:12px;color:var(--color-text-secondary);margin-top:4px}
.crm-native .wa-tpl-preview-box{background:#ECE5DD;color:#333;padding:12px;border-radius:8px;font-size:13px;line-height:1.5;min-height:40px;margin-bottom:12px;white-space:pre-wrap}
.crm-native .wa-var-row{margin-bottom:8px}
.crm-native .wa-var-row label{display:block;font-size:11px;color:var(--color-text-secondary);margin-bottom:3px}
.crm-native .wa-unmatched-card{border:1px solid var(--color-border);border-radius:10px;margin-bottom:10px;overflow:hidden}
.crm-native .wa-unmatched-head{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer}
.crm-native .wa-unmatched-head:hover{background:var(--color-bg)}
.crm-native .wa-unmatched-thread{border-top:1px solid var(--color-border);background:#ECE5DD;padding:12px;max-height:300px;overflow-y:auto;display:flex;flex-direction:column;gap:8px}
.crm-native .wa-unmatched-actions{display:flex;gap:8px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid var(--color-border)}
.crm-native .wa-info-card{border-left:4px solid #25D366;margin-bottom:16px}
.crm-native .wa-info-card.orange{border-left-color:#ff9500}
@media(max-width:820px){.crm-native .ct-two{grid-template-columns:1fr}.crm-native .ct-li-grid1{grid-template-columns:1fr}.crm-native .ct-cond{grid-template-columns:1fr}}`;
  document.head.appendChild(s);
}
// Inject on load so CRM modals opened from non-CRM screens are styled too.
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => ensureCrmModStyles());
  else ensureCrmModStyles();
}

// ---- override the dispatcher ------------------------------------------
window.renderCrmModule = async function (moduleKey) {
  if (typeof crmHas === 'function' && !crmHas(moduleKey)) return crmAccessDenied(moduleKey);
  ensureCrmModStyles();
  try {
    switch (moduleKey) {
      case 'crm.knowledge':      return await renderCrmKnowledge();
      case 'crm.proposals':      return await renderCrmProposals();
      case 'crm.automation':     return await renderCrmAutomation();
      case 'crm.email':          return await renderCrmEmail();
      case 'crm.communications': return await renderCrmComms();
      case 'crm.reports':        return await renderCrmReports();
      default:                   return crmModError('CRM', 'Unknown module.');
    }
  } catch (e) {
    return crmModError((typeof CRM_MODULE_COPY !== 'undefined' && CRM_MODULE_COPY[moduleKey]?.title) || 'CRM', e.message);
  }
};

// ======================================================================
//  KNOWLEDGE HUB  — native CRUD (knowledge-hub.php), reskinned to Quick Guides
// ======================================================================
function khHexBg(hex) {
  const h = String(hex || '#0071e3').replace('#', '');
  const r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
  return `rgba(${r},${g},${b},0.12)`;
}
const KH_COLORS = ['#0071e3', '#00B8D9', '#34c759', '#ff9500', '#ff3b30', '#af52de', '#1d1d1f'];
async function renderCrmKnowledge() {
  let data = CrmMod.cache.knowledge;
  if (!data) { data = await crmApiGet('knowledge-hub.php?action=list'); CrmMod.cache.knowledge = data; }
  const cards = data.data || [];
  const cats = Array.from(new Set(cards.map(c => (c.category || '').trim()).filter(Boolean))).sort();
  const active = CrmMod.tab.knowledgeCat || 'all';
  const shown = active === 'all' ? cards : cards.filter(c => (c.category || '').toLowerCase().includes(active.toLowerCase()));
  const chips = `<div class="ct-filter-chips">
    <button class="ct-chip ${active === 'all' ? 'active' : ''}" onclick="khFilter('all')">All</button>
    ${cats.map(c => `<button class="ct-chip ${active === c ? 'active' : ''}" onclick="khFilter('${esc(c)}')">${esc(c)}</button>`).join('')}
  </div>`;
  const grid = shown.length ? `<div class="ct-guides">${shown.map(c => {
    const color = c.icon_color || '#0071e3';
    const tags = (c.category || '').split(',').map(s => s.trim()).filter(Boolean)
      .map(t => `<span class="ct-guide-tag" style="background:${khHexBg(color)};color:${color}">${esc(t)}</span>`).join('');
    return `<div class="ct-guide" style="border-left:3px solid ${color}">
      <div class="ct-guide-actions">
        <button title="Edit" onclick="khOpenForm(${c.card_id})">${CT_IC.edit}</button>
        <button title="Delete" onclick="khDelete(${c.card_id})">✕</button>
      </div>
      <div class="ct-guide-icon" style="background:${khHexBg(color)};color:${color}">${CT_IC.doc}</div>
      <div class="ct-guide-body">
        <div class="ct-guide-title">${esc(c.title)}</div>
        ${c.description ? `<div class="ct-guide-desc">${esc(c.description)}</div>` : ''}
        ${tags ? `<div>${tags}</div>` : ''}
      </div>
      <a class="btn btn-outline btn-sm" href="${esc(c.url)}" target="_blank" rel="noopener">Open</a>
    </div>`;
  }).join('')}</div>` : `<div class="card"><div class="card-body empty-state"><h3>No resources found</h3><p>Add the first guide or link for the team.</p></div></div>`;
  return `<div class="crm-native fade-in">
    ${crmModHead('Knowledge Hub', 'Shared resources, training materials, guides, and useful links for the team.',
      `<button class="btn btn-primary" onclick="khOpenForm()">+ Add Resource</button>`)}
    ${cats.length ? chips : ''}
    ${grid}
  </div>`;
}
function khFilter(cat) { CrmMod.tab.knowledgeCat = cat; render(); }
function khOpenForm(id = null) {
  const c = id ? (CrmMod.cache.knowledge?.data || []).find(x => Number(x.card_id) === Number(id)) || {} : {};
  const color = c.icon_color || '#0071e3';
  const v = x => esc(x == null ? '' : String(x));
  Modal.open({
    title: id ? 'Edit Resource' : 'Add New Resource',
    body: crmModalBody(`
      <div class="ct-two">
        <div class="form-group"><label class="form-label">Title <span style="color:var(--color-danger)">*</span></label>
          <input class="form-control" id="kh-title" value="${v(c.title)}" placeholder="e.g. Sales Playbook 2026"></div>
        <div class="form-group"><label class="form-label">Category</label>
          <input class="form-control" id="kh-category" value="${v(c.category)}" placeholder="e.g. Sales, Training, Product"></div>
      </div>
      <div class="form-group"><label class="form-label">Link / URL <span style="color:var(--color-danger)">*</span></label>
        <input class="form-control" id="kh-url" value="${v(c.url)}" placeholder="https://drive.google.com/... or /pages/...">
        <div class="form-hint">Google Drive, Dropbox, Notion, or any external URL</div></div>
      <div class="form-group"><label class="form-label">Card Color</label>
        <div class="ct-color-dots" id="kh-colors">${KH_COLORS.map(hex =>
          `<span class="ct-color-dot ${hex === color ? 'sel' : ''}" style="background:${hex}" data-hex="${hex}" onclick="khPickColor('${hex}')"></span>`).join('')}</div>
        <input type="hidden" id="kh-color" value="${color}"></div>
      <div class="form-group"><label class="form-label">Description</label>
        <textarea class="form-control" id="kh-desc" rows="2" placeholder="Brief description of this resource...">${v(c.description)}</textarea></div>
      <div class="form-error" id="kh-error" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" onclick="khSave(${id || 0})">${id ? 'Update Resource' : 'Save Resource'}</button>`,
  });
}
function khPickColor(hex) {
  document.getElementById('kh-color').value = hex;
  document.querySelectorAll('#kh-colors .ct-color-dot').forEach(d => d.classList.toggle('sel', d.dataset.hex === hex));
}
async function khSave(id) {
  const err = document.getElementById('kh-error');
  const g = i => document.getElementById(i)?.value.trim() || '';
  try {
    const payload = { title: g('kh-title'), category: g('kh-category'), url: g('kh-url'), description: g('kh-desc'), icon_color: g('kh-color') };
    if (!payload.title || !payload.url) throw new Error('Title and URL are required.');
    if (id) payload.card_id = id;
    await crmApiPost('knowledge-hub.php?action=' + (id ? 'update' : 'create'), payload);
    crmModInvalidate('knowledge'); Modal.close(); toast(id ? 'Resource updated' : 'Resource added', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
function khDelete(id) {
  appConfirm('Remove this resource?', async () => {
    try { await crmApiPost('knowledge-hub.php?action=delete', { card_id: id }); crmModInvalidate('knowledge'); toast('Resource removed', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// ======================================================================
//  PROPOSALS  — native list + native builder (proposals.php)
// ======================================================================
function proposalBadge(s) {
  s = String(s || 'Draft');
  const map = { Draft: 'badge-gray', Sent: 'badge-blue', Accepted: 'badge-green', Declined: 'badge-red', Expired: 'badge-gray' };
  return crmBadge(s, map[s] || 'badge-gray');
}
async function renderCrmProposals() {
  const q = CrmMod.tab.proposalQ || '', status = CrmMod.tab.proposalStatus || '';
  let data = CrmMod.cache.proposals;
  if (!data) {
    data = await crmApiGet('proposals.php?action=list&limit=100' + (q ? '&search=' + encodeURIComponent(q) : '') + (status ? '&status=' + encodeURIComponent(status) : ''));
    CrmMod.cache.proposals = data;
  }
  const rows = (data.proposals || []).map(p => {
    const total = 'USD ' + Number(p.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const date = p.proposal_date ? crmModDate(p.proposal_date) : '';
    return `<tr>
      <td><strong>${esc(String(p.estimate_number || ('#' + p.proposal_id)))}</strong></td>
      <td>${esc(date)}</td>
      <td>${esc(p.customer_company || '-')}</td>
      <td>${esc(p.contact_name || '-')}</td>
      <td><strong>${esc(total)}</strong></td>
      <td>${proposalBadge(p.status)}</td>
      <td>${esc(p.creator_name || '')}</td>
      <td><div class="ct-actions-cell">
        <button class="btn btn-outline btn-sm" onclick="proposalOpenForm(${p.proposal_id})">Edit</button>
        <button class="btn btn-outline btn-sm" onclick="proposalPreview(${p.proposal_id})">Preview</button>
        <button class="btn btn-outline btn-sm btn-danger" onclick="proposalDelete(${p.proposal_id})">Del</button>
      </div></td></tr>`;
  }).join('');
  const statuses = ['Draft', 'Sent', 'Accepted', 'Declined'];
  return `<div class="crm-native fade-in">
    ${crmModHead('Proposals / Estimates', 'Create and manage client proposals',
      `<button class="btn btn-primary" onclick="proposalOpenForm()">+ New Proposal</button>`)}
    <div class="card filter-card"><div class="card-body ct-toolbar">
      <input class="form-control" id="prop-search" value="${esc(q)}" placeholder="Search company, contact, estimate #..." onkeydown="if(event.key==='Enter')proposalSearch()">
      <select class="form-control" id="prop-status" style="max-width:180px" onchange="proposalSearch()">
        <option value="">All Statuses</option>${statuses.map(x => `<option ${x === status ? 'selected' : ''}>${x}</option>`).join('')}
      </select>
    </div></div>
    ${crmTable(['#', 'Date', 'Customer', 'Contact', 'Total', 'Status', 'Created By', 'Actions'], rows, 'No proposals found.')}
  </div>`;
}
function proposalSearch() {
  CrmMod.tab.proposalQ = document.getElementById('prop-search')?.value.trim() || '';
  CrmMod.tab.proposalStatus = document.getElementById('prop-status')?.value || '';
  crmModInvalidate('proposals'); render();
}
async function proposalOpenForm(id = null) {
  CrmMod.proposalDraft = { id: id || 0, estimate_number: '', items: [] };
  Modal.open({
    title: id ? 'Edit Proposal' : 'New Proposal',
    body: crmModalBody(`
      <div class="ct-two">
        <div>
          <div class="card"><div class="card-header"><h3 class="card-title">Proposal Details</h3></div><div class="card-body">
            <div class="ct-two">
              <div class="form-group"><label class="form-label">Estimate #</label><input class="form-control" id="pf-estimate" readonly style="background:var(--color-bg)"></div>
              <div class="form-group"><label class="form-label">Date</label><input type="date" class="form-control" id="pf-date"></div>
            </div>
            <div class="form-group"><label class="form-label">Status</label>
              <select class="form-control" id="pf-status">${['Draft', 'Sent', 'Accepted', 'Declined'].map(s => `<option>${s}</option>`).join('')}</select></div>
          </div></div>
          <div class="card" style="margin-top:14px"><div class="card-header"><h3 class="card-title">Customer Information</h3></div><div class="card-body">
            <div class="form-group"><label class="form-label">Company Name</label><input class="form-control" id="pf-company" placeholder="e.g., Doha Stud"></div>
            <div class="form-group"><label class="form-label">Contact Name</label><input class="form-control" id="pf-contact" placeholder="e.g., Abdulrahman Al-Nasser"></div>
            <div class="form-group"><label class="form-label">Address</label><textarea class="form-control" id="pf-address" rows="3" placeholder="City, Country"></textarea></div>
          </div></div>
          <div class="card" style="margin-top:14px"><div class="card-header"><h3 class="card-title">Notes & Signature</h3></div><div class="card-body">
            <div class="form-group"><label class="form-label">Notes / Terms</label><textarea class="form-control" id="pf-notes" rows="3" placeholder="Quotation is provided for..."></textarea></div>
            <div class="ct-two">
              <div class="form-group"><label class="form-label">Accepted By</label><input class="form-control" id="pf-acceptedby" placeholder="Signature name"></div>
              <div class="form-group"><label class="form-label">Accepted Date</label><input type="date" class="form-control" id="pf-accepteddate"></div>
            </div>
          </div></div>
        </div>
        <div>
          <div class="card"><div class="card-header"><h3 class="card-title">Line Items</h3>
            <div class="card-actions"><button class="btn btn-outline btn-sm" onclick="proposalAddItem()">+ Add Item</button></div></div>
          <div class="card-body">
            <div id="pf-items"></div>
            <div class="ct-total"><div><span class="ct-secline">TOTAL</span><div class="ct-total-val" id="pf-total">USD 0.00</div></div></div>
          </div></div>
        </div>
      </div>
      <div class="form-error" id="pf-error" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      ${id ? `<button class="btn-secondary" onclick="proposalPreview(${id})">Preview</button>` : ''}
      <button class="btn-primary" onclick="proposalSave()">Save</button>`,
    onMount: async () => {
      crmWidenModal(940);
      const d = CrmMod.proposalDraft;
      if (id) {
        try {
          const r = await crmApiGet('proposals.php?action=get&id=' + id);
          if (!r.success) throw new Error(r.message);
          const p = r.proposal;
          document.getElementById('pf-estimate').value = p.estimate_number || '';
          document.getElementById('pf-date').value = p.proposal_date || '';
          document.getElementById('pf-status').value = p.status || 'Draft';
          document.getElementById('pf-company').value = p.customer_company || '';
          document.getElementById('pf-contact').value = p.contact_name || '';
          document.getElementById('pf-address').value = p.customer_address || '';
          document.getElementById('pf-notes').value = p.notes || '';
          document.getElementById('pf-acceptedby').value = p.accepted_by || '';
          document.getElementById('pf-accepteddate').value = p.accepted_date || '';
          let items = [];
          try { items = typeof p.line_items === 'string' ? JSON.parse(p.line_items) : (p.line_items || []); } catch (e) { items = []; }
          d.items = Array.isArray(items) && items.length ? items.map(i => ({ service: i.service || '', description: i.description || '', qty: Number(i.qty) || 1, rate: Number(i.rate) || 0 })) : [{ service: '', description: '', qty: 1, rate: 0 }];
          proposalRenderItems();
        } catch (e) { document.getElementById('pf-error').textContent = e.message; document.getElementById('pf-error').style.display = 'block'; }
      } else {
        document.getElementById('pf-date').value = new Date().toISOString().split('T')[0];
        d.items = [{ service: '', description: '', qty: 1, rate: 0 }];
        proposalRenderItems();
        try { const n = await crmApiGet('proposals.php?action=next_number'); if (n.success) document.getElementById('pf-estimate').value = n.next_number; } catch (e) {}
      }
    },
  });
}
function proposalAddItem() { CrmMod.proposalDraft.items.push({ service: '', description: '', qty: 1, rate: 0 }); proposalRenderItems(); }
function proposalRemoveItem(i) { const d = CrmMod.proposalDraft; if (d.items.length <= 1) return; d.items.splice(i, 1); proposalRenderItems(); }
function proposalUpdateItem(i, field, val) {
  const it = CrmMod.proposalDraft.items[i]; if (!it) return;
  it[field] = (field === 'qty' || field === 'rate') ? (parseFloat(val) || 0) : val;
  proposalTotals();
}
function proposalTotals() {
  let total = 0;
  CrmMod.proposalDraft.items.forEach((it, i) => {
    const amt = Math.round((Number(it.qty) || 0) * (Number(it.rate) || 0) * 100) / 100;
    total += amt;
    const el = document.getElementById('pf-amt-' + i);
    if (el) el.textContent = amt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  });
  const t = document.getElementById('pf-total');
  if (t) t.textContent = 'USD ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function proposalRenderItems() {
  const c = document.getElementById('pf-items'); if (!c) return;
  const d = CrmMod.proposalDraft;
  c.innerHTML = d.items.map((it, i) => {
    const amt = ((Number(it.qty) || 0) * (Number(it.rate) || 0)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `<div class="ct-li">
      <div class="ct-li-grid1">
        <div class="form-group" style="margin:0"><label class="form-label">Service</label><input class="form-control" value="${esc(it.service)}" placeholder="e.g., Arabian Product" oninput="proposalUpdateItem(${i},'service',this.value)"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Description</label><textarea class="form-control" rows="2" placeholder="Service description..." oninput="proposalUpdateItem(${i},'description',this.value)">${esc(it.description)}</textarea></div>
      </div>
      <div class="ct-li-grid2">
        <div class="form-group" style="margin:0"><label class="form-label">Qty</label><input type="number" class="form-control" min="1" step="1" value="${esc(it.qty)}" oninput="proposalUpdateItem(${i},'qty',this.value)"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Rate (USD)</label><input type="number" class="form-control" min="0" step="0.01" value="${esc(it.rate)}" oninput="proposalUpdateItem(${i},'rate',this.value)"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Amount</label><div class="form-control" style="background:var(--color-bg);font-weight:600" id="pf-amt-${i}">${amt}</div></div>
        <button class="btn btn-outline btn-sm btn-danger" title="Remove" onclick="proposalRemoveItem(${i})" style="${d.items.length <= 1 ? 'visibility:hidden;' : ''}">✕</button>
      </div>
    </div>`;
  }).join('');
  proposalTotals();
}
async function proposalSave() {
  const err = document.getElementById('pf-error');
  const g = i => document.getElementById(i)?.value || '';
  try {
    const d = CrmMod.proposalDraft;
    const payload = {
      proposal_id: d.id || 0,
      proposal_date: g('pf-date'),
      status: g('pf-status'),
      customer_company: g('pf-company'),
      contact_name: g('pf-contact'),
      customer_address: g('pf-address'),
      notes: g('pf-notes'),
      accepted_by: g('pf-acceptedby'),
      accepted_date: g('pf-accepteddate'),
      line_items: d.items.map(i => ({ service: i.service, description: i.description, qty: Number(i.qty) || 1, rate: Number(i.rate) || 0 })),
    };
    const r = await crmApiPost('proposals.php?action=save', payload);
    crmModInvalidate('proposals'); Modal.close();
    toast(d.id ? 'Proposal updated' : 'Proposal created', 'success');
    render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
async function proposalPreview(id) {
  try {
    const d = await crmApiGet('proposals.php?action=pdf_html&id=' + id);
    if (!d.success) { toast(d.message || 'Save the proposal first to preview it.', 'error'); return; }
    const win = window.open('', '_blank', 'width=900,height=1100');
    if (!win) { toast('Popup blocked — allow popups to preview.', 'error'); return; }
    win.document.write(d.html); win.document.close();
    try {
      const bar = win.document.createElement('div');
      bar.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#333;color:#fff;padding:8px 16px;display:flex;gap:12px;z-index:9999;font-family:Arial;font-size:14px;align-items:center;';
      bar.innerHTML = '<button onclick="window.print()" style="background:#0071e3;color:#fff;border:0;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Print / Save as PDF</button><span style="flex:1"></span><span style="opacity:.7">Estimate #' + id + '</span>';
      win.document.body.insertBefore(bar, win.document.body.firstChild);
      const pg = win.document.querySelector('.page'); if (pg) pg.style.marginTop = '48px';
      const st = win.document.createElement('style');
      st.textContent = '@media print{div[style*="position:fixed"]{display:none!important}.page{margin-top:0!important}}';
      win.document.head.appendChild(st);
    } catch (e) {}
  } catch (e) { toast(e.message, 'error'); }
}
function proposalDelete(id) {
  appConfirm('Delete this proposal permanently?', async () => {
    try { await crmApiPost('proposals.php?action=delete', { proposal_id: id }); crmModInvalidate('proposals'); toast('Proposal deleted', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// ======================================================================
//  AUTOMATIONS  — native rules list + native WHEN/IF/THEN builder + logs
// ======================================================================
async function autoMeta() {
  if (!CrmMod.cache.autoMeta) {
    const d = await crmApiGet('automation.php?action=meta');
    CrmMod.cache.autoMeta = d.data || {};
  }
  return CrmMod.cache.autoMeta;
}
async function renderCrmAutomation() {
  const tab = CrmMod.tab.automation || 'rules';
  const meta = await autoMeta();
  const head = crmModHead('Automations', 'Lead and follow-up actions from one shared rules engine.',
    tab === 'rules' ? `<button class="btn btn-primary" onclick="autoOpenForm()">+ New Rule</button>` : '');
  const sched = meta.scheduler || null;
  const schedBar = (tab === 'rules' && sched) ? `
    <div class="ct-sched-bar">
      <span class="ct-sched-dot ${sched.healthy ? 'ok' : 'warn'}"></span>
      <span><strong>Scheduler</strong> — ${sched.last_run_human ? 'last ran ' + esc(sched.last_run_human) : 'has not run yet'}${sched.time_rule_count ? ` · ${sched.time_rule_count} time-based rule${sched.time_rule_count === 1 ? '' : 's'}` : ' · no time-based rules yet'}</span>
      <button class="btn btn-outline btn-sm" style="margin-left:auto" onclick="autoRunSchedulerNow()">Run now</button>
    </div>` : '';
  const tabBar = crmTabBar([['rules', 'Rules'], ['logs', 'Run history']], tab, 'autoSetTab');
  let inner;
  if (tab === 'logs') {
    let logs = CrmMod.cache.autoLogs;
    if (!logs) { logs = await crmApiGet('automation.php?action=logs'); CrmMod.cache.autoLogs = logs; }
    const rows = (logs.data || []).map(l => `<tr>
      <td>${crmModDate(l.created_at)}</td>
      <td>${esc(l.contact_person || l.company_name || (l.lead_id ? 'Lead #' + l.lead_id : '—'))}</td>
      <td>${crmBadge(l.status || '', l.status === 'success' ? 'badge-green' : (l.status === 'skipped' ? 'badge-gray' : 'badge-red'))}</td>
      <td>${esc(l.message || l.action_type || '')}</td></tr>`).join('');
    inner = crmTable(['When', 'Lead', 'Result', 'Detail'], rows, 'No automation runs recorded yet.');
  } else {
    let data = CrmMod.cache.automation;
    if (!data) { data = await crmApiGet('automation.php?action=list'); CrmMod.cache.automation = data; }
    const rules = data.data || [];
    const total = rules.length, activeN = rules.filter(r => Number(r.is_active)).length, runs = rules.reduce((a, r) => a + Number(r.log_count || 0), 0);
    const stats = crmStatRow([
      ['Total Rules', total, CT_IC.doc], ['Active Rules', activeN, CT_IC.check], ['Total Executions', runs, CT_IC.chart],
    ]);
    const rows = rules.map(r => {
      const trig = meta.triggers?.find(t => t.value === r.trigger_type)?.label || crmTitleCase(r.trigger_type);
      const act = meta.actions?.find(a => a.value === r.action_type)?.label || crmTitleCase(r.action_type);
      let condCount = 0; try { const c = typeof r.conditions === 'string' ? JSON.parse(r.conditions) : r.conditions; condCount = Array.isArray(c) ? c.length : 0; } catch (e) {}
      return `<tr>
        <td><strong>${esc(r.name)}</strong>${r.description ? `<div class="ct-secline">${esc(r.description)}</div>` : ''}</td>
        <td>${crmBadge('WHEN: ' + trig, 'badge-blue')} ${condCount ? crmBadge('IF: ' + condCount, 'badge-purple') : ''} ${crmBadge('THEN: ' + act, 'badge-green')}</td>
        <td>${Number(r.log_count || 0)}</td>
        <td>${r.last_success ? crmModDate(r.last_success) : '—'}</td>
        <td><label class="ct-switch"><input type="checkbox" ${Number(r.is_active) ? 'checked' : ''} onchange="autoToggle(${r.rule_id})"><span></span></label></td>
        <td><div class="ct-actions-cell">
          <button class="btn btn-outline btn-sm" onclick="autoOpenForm(${r.rule_id})">Edit</button>
          <button class="btn btn-outline btn-sm btn-danger" onclick="autoDelete(${r.rule_id})">Delete</button>
        </div></td></tr>`;
    }).join('');
    inner = stats + crmTable(['Rule', 'WHEN → IF → THEN', 'Runs', 'Last success', 'Active', ''], rows, 'No automation rules yet. Create your first rule.');
  }
  return `<div class="crm-native fade-in">${head}${schedBar}${tabBar}${inner}</div>`;
}
function autoSetTab(t) { CrmMod.tab.automation = t; render(); }

/** Fire the time-based scheduler immediately instead of waiting for the heartbeat. */
async function autoRunSchedulerNow() {
  try {
    toast('Running scheduler…', 'info');
    const r = await crmApiPost('automation.php?action=run_scheduler', {});
    const res = r.data || {};
    crmModInvalidate('automation'); crmModInvalidate('autoLogs'); crmModInvalidate('autoMeta');
    toast(`Scheduler done — ${res.matched || 0} matched, ${res.fired || 0} fired, ${res.skipped || 0} already handled`, 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}
async function autoToggle(id) {
  try { await crmApiPost('automation.php?action=toggle', { rule_id: id }); crmModInvalidate('automation'); toast('Rule updated', 'success'); }
  catch (e) { toast(e.message, 'error'); render(); }
}
function autoDelete(id) {
  appConfirm('Delete this automation rule and its run history?', async () => {
    try { await crmApiPost('automation.php?action=delete', { rule_id: id }); crmModInvalidate('automation'); crmModInvalidate('autoLogs'); toast('Rule deleted', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}
let _autoCondIdx = 0;
async function autoOpenForm(id = null) {
  const meta = await autoMeta();
  let rule = {};
  if (id) {
    const r = await crmApiGet('automation.php?action=get&id=' + id);
    if (!r.success) { toast(r.message || 'Rule not found', 'error'); return; }
    rule = r.data || {};
  }
  CrmMod.autoEdit = id || 0;
  const tCfg = autoParse(rule.trigger_config), aCfg = autoParse(rule.action_config), conds = autoParse(rule.conditions) || [];
  Modal.open({
    title: id ? 'Edit Rule' : 'New Automation Rule',
    body: crmModalBody(`
      <div class="form-group"><label class="form-label">Rule Name <span style="color:var(--color-danger)">*</span></label>
        <input class="form-control" id="ar-name" value="${esc(rule.name || '')}" placeholder="e.g., Assign new US leads to Sarah"></div>
      <div class="form-group"><label class="form-label">Description</label>
        <textarea class="form-control ml-grow" id="ar-desc" rows="2" placeholder="Optional — what this rule does, and why">${esc(rule.description || '')}</textarea></div>
      <div class="ct-section-label">WHEN (Trigger)</div>
      <div class="form-group"><label class="form-label">Trigger Event</label>
        <select class="form-control" id="ar-trigger" onchange="autoTriggerChange()">
          <option value="">Select...</option>
          ${(meta.triggers || []).map(t => `<option value="${t.value}" ${rule.trigger_type === t.value ? 'selected' : ''}>${esc(t.label)}</option>`).join('')}
        </select></div>
      <div id="ar-triggercfg"></div>
      <div class="ct-section-label" style="margin-top:14px">IF (Conditions) — optional, all must match</div>
      <div id="ar-conditions"></div>
      <button class="btn btn-outline btn-sm" onclick="autoAddCondition()">+ Add Condition</button>
      <div class="ct-section-label" style="margin-top:14px">THEN (Action)</div>
      <div class="form-group"><label class="form-label">Action</label>
        <select class="form-control" id="ar-action" onchange="autoActionChange()">
          <option value="">Select...</option>
          ${(meta.actions || []).map(a => `<option value="${a.value}" ${rule.action_type === a.value ? 'selected' : ''}>${esc(a.label)}</option>`).join('')}
        </select></div>
      <div id="ar-actioncfg"></div>
      <div class="form-error" id="ar-error" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" onclick="autoSave()">${id ? 'Save Changes' : 'Create Rule'}</button>`,
    onMount: () => {
      crmWidenModal(640);
      _autoCondIdx = 0;
      autoTriggerChange(tCfg);
      autoActionChange(aCfg);
      (conds || []).forEach(c => autoAddCondition(c));
    },
  });
}
function autoParse(v) { if (v == null) return null; if (typeof v !== 'string') return v; try { return JSON.parse(v); } catch (e) { return null; } }
function autoTriggerChange(saved) {
  const meta = CrmMod.cache.autoMeta || {};
  const trig = document.getElementById('ar-trigger').value;
  const c = document.getElementById('ar-triggercfg');
  const cfg = saved || {};
  const statusSel = (id, list, val) => `<div class="form-group"><label class="form-label">${id.includes('From') ? 'From' : 'To'} Status <span class="ct-secline">(optional)</span></label>
    <select class="form-control" id="${id}"><option value="">Any</option>${list.map(s => `<option ${val === s ? 'selected' : ''}>${s}</option>`).join('')}</select></div>`;
  if (trig === 'lead_status_changed') {
    c.innerHTML = `<div class="ct-cfgbox">${statusSel('tc-from', meta.lead_statuses || [], cfg.from_status)}${statusSel('tc-to', meta.lead_statuses || [], cfg.to_status)}</div>`;
  } else if (trig === 'proposal_status_changed') {
    c.innerHTML = `<div class="ct-cfgbox">${statusSel('tc-from', meta.proposal_statuses || [], cfg.from_status)}${statusSel('tc-to', meta.proposal_statuses || [], cfg.to_status)}</div>`;
  } else if (trig === 'lead_source_match') {
    const opts = (meta.condition_fields || []).find(f => f.value === 'lead_source')?.options || [];
    c.innerHTML = `<div class="ct-cfgbox"><div class="form-group"><label class="form-label">Lead Source</label>
      <select class="form-control" id="tc-source">${opts.map(s => `<option ${cfg.lead_source === s ? 'selected' : ''}>${s}</option>`).join('')}</select></div></div>`;
  } else if (trig === 'interaction_logged') {
    c.innerHTML = `<div class="ct-cfgbox">
      <div class="form-group"><label class="form-label">Interaction type <span class="ct-secline">(optional)</span></label>
        <select class="form-control" id="tc-ixtype"><option value="">Any</option>${(meta.interaction_types || []).map(t => `<option ${cfg.interaction_type === t ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
      <div class="form-group" style="margin:0"><label class="form-label">Outcome <span class="ct-secline">(optional)</span></label>
        <select class="form-control" id="tc-outcome"><option value="">Any</option>${(meta.outcomes || []).map(o => `<option ${cfg.outcome === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div></div>`;
  } else if (trig === 'call_completed') {
    c.innerHTML = `<div class="ct-cfgbox"><div class="form-group" style="margin:0"><label class="form-label">Call outcome <span class="ct-secline">(optional)</span></label>
      <select class="form-control" id="tc-outcome"><option value="">Any</option>${(meta.outcomes || []).map(o => `<option ${cfg.outcome === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div></div>`;
  } else if ((meta.time_triggers || []).includes(trig)) {
    const days = cfg.days != null ? cfg.days : (trig === 'followup_overdue' ? 0 : 7);
    const dayLabel = {
      lead_idle: 'Days with no interaction',
      no_contact_after_created: 'Days since the lead was created',
      lead_stale_in_status: 'Days without any change',
      followup_overdue: 'Grace period after the due date (days)',
    }[trig] || 'Days';
    c.innerHTML = `<div class="ct-cfgbox">
      <div class="ct-sched-note">Checked by the scheduler, not by a page view. Each rule fires at most once per lead per day.</div>
      <div class="ct-two">
        <div class="form-group"><label class="form-label">${dayLabel}</label>
          <input class="form-control" id="tc-days" type="number" min="${trig === 'followup_overdue' ? 0 : 1}" value="${esc(String(days))}"></div>
        <div class="form-group"><label class="form-label">Only this status <span class="ct-secline">(optional)</span></label>
          <select class="form-control" id="tc-status"><option value="">Any open status</option>${(meta.lead_statuses || []).map(st => `<option ${cfg.lead_status === st ? 'selected' : ''}>${st}</option>`).join('')}</select></div>
      </div>
      <div class="ct-two">
        <div class="form-group" style="margin:0"><label class="form-label">Max leads per run</label>
          <input class="form-control" id="tc-max" type="number" min="1" max="500" value="${esc(String(cfg.max_per_run || 200))}"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Repeat</label>
          <select class="form-control" id="tc-once">
            <option value="" ${!cfg.once_per_lead ? 'selected' : ''}>Once per lead per day</option>
            <option value="1" ${cfg.once_per_lead ? 'selected' : ''}>Once per lead, ever</option>
          </select></div>
      </div>
      <div class="form-hint">Leads that are Won, Lost, Not Interested or already Customers are skipped unless you pick a specific status.</div>
    </div>`;
  } else if (trig === 'whatsapp_received') {
    c.innerHTML = `<div class="ct-cfgbox"><div class="form-group" style="margin:0"><label class="form-label">Message contains <span class="ct-secline">(optional)</span></label>
      <input class="form-control" id="tc-keyword" value="${esc(cfg.keyword || '')}" placeholder="e.g. price"></div></div>`;
  } else { c.innerHTML = ''; }
}
function autoActionChange(saved) {
  const meta = CrmMod.cache.autoMeta || {};
  const act = document.getElementById('ar-action').value;
  const c = document.getElementById('ar-actioncfg');
  const cfg = saved || {};
  const box = h => `<div class="ct-cfgbox">${h}</div>`;
  if (act === 'assign_user') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">Assign To</label><select class="form-control" id="ac-user"><option value="">Select user...</option>${(meta.users || []).map(u => `<option value="${u.user_id}" ${cfg.user_id == u.user_id ? 'selected' : ''}>${esc(u.full_name)} (${esc(u.role)})</option>`).join('')}</select></div>`);
  } else if (act === 'send_email_template') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">Email Template</label><select class="form-control" id="ac-emailtpl"><option value="">Select template...</option>${(meta.email_templates || []).map(t => `<option value="${t.template_id}" ${cfg.template_id == t.template_id ? 'selected' : ''}>${esc(t.name)}${t.subject ? ' — ' + esc(t.subject) : ''}</option>`).join('')}</select><div class="form-hint">Variables like {{contact_name}} are replaced with lead data when sent.</div></div>`);
  } else if (act === 'send_whatsapp_template') {
    let sel = cfg.wa_template_id || (cfg.content_sid ? 'twilio_' + cfg.content_sid : (cfg.template_id ? 'local_' + cfg.template_id : ''));
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">WhatsApp Template</label><select class="form-control" id="ac-watpl"><option value="">Select template...</option>${(meta.wa_templates || []).map(t => `<option value="${esc(t.id)}" ${sel === t.id ? 'selected' : ''}>${esc(t.name)}${t.language ? ' (' + esc(t.language) + ')' : ''} — ${t.type === 'twilio' ? 'Twilio' : 'Local'}</option>`).join('')}</select></div>`);
  } else if (act === 'send_notification_email') {
    c.innerHTML = box(`<div class="form-group"><label class="form-label">Recipient</label>
        <select class="form-control" id="ac-recipient" onchange="autoToggleSpecificEmail()">
          <option value="assigned_user" ${cfg.recipient === 'assigned_user' ? 'selected' : ''}>Assigned User</option>
          <option value="creator" ${cfg.recipient === 'creator' ? 'selected' : ''}>Lead Creator</option>
          <option value="specific_email" ${cfg.recipient === 'specific_email' ? 'selected' : ''}>Specific Email</option>
        </select></div>
      <div class="form-group" id="ac-emailfield" style="display:${cfg.recipient === 'specific_email' ? 'block' : 'none'}"><label class="form-label">Email Address</label><input class="form-control" id="ac-email" value="${esc(cfg.email || '')}" placeholder="user@example.com"></div>
      <div class="form-group"><label class="form-label">Subject</label><input class="form-control" id="ac-subject" value="${esc(cfg.subject || 'Automation Alert')}" placeholder="Use variables like {{contact_name}}"></div>
      <div class="form-group" style="margin:0"><label class="form-label">Body <span class="ct-secline">(optional)</span></label><textarea class="form-control" id="ac-body" rows="3" placeholder="Use {{contact_name}}, {{company_name}}, etc.">${esc(cfg.body || '')}</textarea></div>`);
  } else if (act === 'change_lead_status') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">New Status</label><select class="form-control" id="ac-status">${(meta.lead_statuses || []).map(s => `<option ${cfg.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select></div>`);
  } else if (act === 'change_priority') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">New Priority</label><select class="form-control" id="ac-priority">${(meta.priorities || []).map(p => `<option ${cfg.priority === p ? 'selected' : ''}>${p}</option>`).join('')}</select></div>`);
  } else if (act === 'log_interaction') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">Note Text</label><textarea class="form-control" id="ac-note" rows="3" placeholder="Use {{contact_name}}, {{company_name}}, etc.">${esc(cfg.note || '')}</textarea></div>`);
  } else if (act === 'assign_round_robin') {
    const chosen = String(cfg.user_ids || '').split(',').filter(Boolean);
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">Team pool</label>
      <div class="ct-rr-pool">${(meta.users || []).map(u => `<label class="ct-rr-item"><input type="checkbox" class="ac-rr" value="${u.user_id}" ${chosen.includes(String(u.user_id)) ? 'checked' : ''}> ${esc(u.full_name)} <span class="ct-secline">${esc(u.role)}</span></label>`).join('')}</div>
      <div class="form-hint">Leads are handed out in rotation across everyone ticked.</div></div>`);
  } else if (act === 'send_whatsapp_message') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">Message</label>
      <textarea class="form-control" id="ac-wabody" rows="3" placeholder="Use {{contact_name}}, {{company_name}}, etc.">${esc(cfg.body || '')}</textarea>
      <div class="form-hint">Free-form messages only reach contacts who replied within the last 24 hours. Outside that window use a template action instead.</div></div>`);
  } else if (act === 'notify_in_app') {
    c.innerHTML = box(`<div class="form-group"><label class="form-label">Notify</label>
        <select class="form-control" id="ac-nuser"><option value="">The lead's assigned owner</option>${(meta.users || []).map(u => `<option value="${u.user_id}" ${cfg.user_id == u.user_id ? 'selected' : ''}>${esc(u.full_name)}</option>`).join('')}</select></div>
      <div class="form-group"><label class="form-label">Title</label><input class="form-control" id="ac-ntitle" value="${esc(cfg.title || '')}" placeholder="e.g. {{contact_name}} needs a follow-up"></div>
      <div class="form-group" style="margin:0"><label class="form-label">Body</label><textarea class="form-control" id="ac-nbody" rows="2">${esc(cfg.body || '')}</textarea></div>`);
  } else if (act === 'set_field') {
    c.innerHTML = box(`<div class="form-group"><label class="form-label">Field</label>
        <select class="form-control" id="ac-field">${(meta.settable_fields || []).map(f => `<option value="${f.value}" ${cfg.field === f.value ? 'selected' : ''}>${esc(f.label)}</option>`).join('')}</select></div>
      <div class="form-group" style="margin:0"><label class="form-label">Value</label><input class="form-control" id="ac-fieldval" value="${esc(cfg.value || '')}"></div>`);
  } else if (act === 'create_task') {
    c.innerHTML = box(`<div class="form-group"><label class="form-label">Project</label>
        <select class="form-control" id="ac-project"><option value="">Select a project…</option>${(meta.workflow_projects || []).map(pr => `<option value="${pr.id}" ${cfg.project_id == pr.id ? 'selected' : ''}>${esc(pr.parent_name ? pr.parent_name + ' › ' + pr.name : pr.name)}</option>`).join('')}</select></div>
      <div class="form-group"><label class="form-label">Task title</label><input class="form-control" id="ac-tasktitle" value="${esc(cfg.title || '')}" placeholder="e.g. Follow up with {{contact_name}}"></div>
      <div class="form-group"><label class="form-label">Description</label><textarea class="form-control" id="ac-taskdesc" rows="2">${esc(cfg.description || '')}</textarea></div>
      <div class="ct-two">
        <div class="form-group" style="margin:0"><label class="form-label">Due in (days)</label><input class="form-control" id="ac-taskdue" type="number" min="0" value="${esc(cfg.due_in_days || 3)}"></div>
        <div class="form-group" style="margin:0"><label class="form-label">Priority</label><select class="form-control" id="ac-taskprio">${['low','medium','high','urgent'].map(x => `<option value="${x}" ${cfg.priority === x ? 'selected' : ''}>${crmTitleCase(x)}</option>`).join('')}</select></div>
      </div>`);
  } else if (act === 'add_to_email_list') {
    c.innerHTML = box(`<div class="form-group" style="margin:0"><label class="form-label">Email list</label>
      <select class="form-control" id="ac-list"><option value="">Select a list…</option>${(meta.email_lists || []).map(l => `<option value="${l.list_id}" ${cfg.list_id == l.list_id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select></div>`);
  } else { c.innerHTML = ''; }
}
function autoToggleSpecificEmail() {
  const r = document.getElementById('ac-recipient'), f = document.getElementById('ac-emailfield');
  if (f) f.style.display = r.value === 'specific_email' ? 'block' : 'none';
}
function autoAddCondition(saved) {
  const meta = CrmMod.cache.autoMeta || {};
  const s = saved || {};
  const idx = _autoCondIdx++;
  const row = document.createElement('div');
  row.className = 'ct-cond'; row.id = 'cond-' + idx;
  row.innerHTML = `
    <select class="cond-field form-control" onchange="autoCondFieldChange(${idx})"><option value="">Field...</option>${(meta.condition_fields || []).map(f => `<option value="${f.value}" ${s.field === f.value ? 'selected' : ''}>${esc(f.label)}</option>`).join('')}</select>
    <select class="cond-op form-control">${(meta.condition_operators || []).map(o => `<option value="${o.value}" ${s.operator === o.value ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>
    <span class="cond-value-wrap"></span>
    <button class="ct-cond-x" onclick="autoRemoveCondition(${idx})">&times;</button>`;
  document.getElementById('ar-conditions').appendChild(row);
  autoCondValueField(idx, s.field, s.value);
}
function autoRemoveCondition(idx) { const el = document.getElementById('cond-' + idx); if (el) el.remove(); }
function autoCondFieldChange(idx) {
  const row = document.getElementById('cond-' + idx);
  autoCondValueField(idx, row.querySelector('.cond-field').value, '');
}
function autoCondValueField(idx, field, savedValue) {
  const meta = CrmMod.cache.autoMeta || {};
  const wrap = document.getElementById('cond-' + idx).querySelector('.cond-value-wrap');
  const fDef = (meta.condition_fields || []).find(f => f.value === field);
  if (fDef && fDef.type === 'enum') {
    wrap.innerHTML = `<select class="cond-value form-control"><option value="">Select...</option>${fDef.options.map(o => `<option ${savedValue === o ? 'selected' : ''}>${o}</option>`).join('')}</select>`;
  } else if (fDef && fDef.type === 'user') {
    wrap.innerHTML = `<select class="cond-value form-control"><option value="">Select user...</option>${(meta.users || []).map(u => `<option value="${u.user_id}" ${savedValue == u.user_id ? 'selected' : ''}>${esc(u.full_name)}</option>`).join('')}</select>`;
  } else {
    wrap.innerHTML = `<input class="cond-value form-control" placeholder="Value..." value="${esc(savedValue || '')}">`;
  }
}
async function autoSave() {
  const meta = CrmMod.cache.autoMeta || {};
  const err = document.getElementById('ar-error');
  const showErr = m => { err.textContent = m; err.style.display = 'block'; };
  err.style.display = 'none';
  const name = document.getElementById('ar-name').value.trim();
  const description = document.getElementById('ar-desc').value.trim();
  const triggerType = document.getElementById('ar-trigger').value;
  const actionType = document.getElementById('ar-action').value;
  if (!name || !triggerType || !actionType) return showErr('Please fill in the rule name, trigger, and action.');
  const triggerConfig = {};
  if (triggerType === 'lead_status_changed' || triggerType === 'proposal_status_changed') {
    const fs = document.getElementById('tc-from')?.value, ts = document.getElementById('tc-to')?.value;
    if (fs) triggerConfig.from_status = fs; if (ts) triggerConfig.to_status = ts;
  } else if (triggerType === 'lead_source_match') {
    const ls = document.getElementById('tc-source')?.value; if (ls) triggerConfig.lead_source = ls;
  }
  if (triggerType === 'interaction_logged') {
    const t = document.getElementById('tc-ixtype')?.value, o = document.getElementById('tc-outcome')?.value;
    if (t) triggerConfig.interaction_type = t;
    if (o) triggerConfig.outcome = o;
  }
  if (triggerType === 'call_completed') {
    const o = document.getElementById('tc-outcome')?.value;
    if (o) triggerConfig.outcome = o;
  }
  if ((meta.time_triggers || []).includes(triggerType)) {
    const d = document.getElementById('tc-days')?.value;
    if (d !== '' && d != null) triggerConfig[triggerType === 'followup_overdue' ? 'grace_days' : 'days'] = Number(d);
    const st = document.getElementById('tc-status')?.value;
    if (st) triggerConfig.lead_status = st;
    const mx = document.getElementById('tc-max')?.value;
    if (mx) triggerConfig.max_per_run = Number(mx);
    if (document.getElementById('tc-once')?.value === '1') triggerConfig.once_per_lead = true;
  }
  if (triggerType === 'whatsapp_received') {
    const kw = document.getElementById('tc-keyword')?.value.trim();
    if (kw) triggerConfig.keyword = kw;
  }
  const conditions = [];
  document.querySelectorAll('#ar-conditions .ct-cond').forEach(row => {
    const field = row.querySelector('.cond-field')?.value, op = row.querySelector('.cond-op')?.value, val = row.querySelector('.cond-value')?.value;
    if (field && op) conditions.push({ field, operator: op, value: val || '' });
  });
  const actionConfig = {};
  switch (actionType) {
    case 'assign_user': actionConfig.user_id = document.getElementById('ac-user')?.value; if (!actionConfig.user_id) return showErr('Please select a user to assign.'); break;
    case 'send_email_template': actionConfig.template_id = document.getElementById('ac-emailtpl')?.value; if (!actionConfig.template_id) return showErr('Please select an email template.'); break;
    case 'send_whatsapp_template': {
      const waVal = document.getElementById('ac-watpl')?.value; if (!waVal) return showErr('Please select a WhatsApp template.');
      actionConfig.wa_template_id = waVal;
      const t = (meta.wa_templates || []).find(x => x.id === waVal);
      if (t) { if (t.type === 'twilio') actionConfig.content_sid = t.content_sid; else actionConfig.template_id = t.template_id; }
      break;
    }
    case 'send_notification_email':
      actionConfig.recipient = document.getElementById('ac-recipient')?.value || 'assigned_user';
      actionConfig.subject = document.getElementById('ac-subject')?.value || 'Automation Alert';
      actionConfig.body = document.getElementById('ac-body')?.value || '';
      if (actionConfig.recipient === 'specific_email') { actionConfig.email = document.getElementById('ac-email')?.value; if (!actionConfig.email) return showErr('Please enter an email address.'); }
      break;
    case 'change_lead_status': actionConfig.status = document.getElementById('ac-status')?.value; break;
    case 'change_priority': actionConfig.priority = document.getElementById('ac-priority')?.value; break;
    case 'log_interaction': actionConfig.note = document.getElementById('ac-note')?.value || 'Automation triggered'; break;
    case 'assign_round_robin': {
      const ids = Array.from(document.querySelectorAll('.ac-rr:checked')).map(i => i.value);
      if (ids.length < 2) return showErr('Pick at least two people for a round-robin pool.');
      actionConfig.user_ids = ids.join(',');
      break;
    }
    case 'send_whatsapp_message':
      actionConfig.body = document.getElementById('ac-wabody')?.value.trim();
      if (!actionConfig.body) return showErr('Enter the WhatsApp message to send.');
      break;
    case 'notify_in_app':
      actionConfig.user_id = document.getElementById('ac-nuser')?.value || '';
      actionConfig.title = document.getElementById('ac-ntitle')?.value.trim();
      actionConfig.body = document.getElementById('ac-nbody')?.value || '';
      if (!actionConfig.title) return showErr('Enter a notification title.');
      break;
    case 'set_field':
      actionConfig.field = document.getElementById('ac-field')?.value;
      actionConfig.value = document.getElementById('ac-fieldval')?.value;
      if (!actionConfig.field) return showErr('Pick the field to set.');
      break;
    case 'create_task':
      actionConfig.project_id = document.getElementById('ac-project')?.value;
      actionConfig.title = document.getElementById('ac-tasktitle')?.value.trim();
      actionConfig.description = document.getElementById('ac-taskdesc')?.value || '';
      actionConfig.due_in_days = document.getElementById('ac-taskdue')?.value || 0;
      actionConfig.priority = document.getElementById('ac-taskprio')?.value || 'medium';
      if (!actionConfig.project_id) return showErr('Pick the project the task should be created in.');
      if (!actionConfig.title) return showErr('Enter a task title.');
      break;
    case 'add_to_email_list':
      actionConfig.list_id = document.getElementById('ac-list')?.value;
      if (!actionConfig.list_id) return showErr('Pick an email list.');
      break;
  }
  try {
    const payload = { name, description, trigger_type: triggerType, trigger_config: triggerConfig, conditions, action_type: actionType, action_config: actionConfig };
    if (CrmMod.autoEdit) payload.rule_id = CrmMod.autoEdit;
    await crmApiPost('automation.php?action=' + (CrmMod.autoEdit ? 'update' : 'create'), payload);
    crmModInvalidate('automation'); Modal.close(); toast(CrmMod.autoEdit ? 'Rule updated' : 'Rule created', 'success'); render();
  } catch (e) { showErr(e.message); }
}

// ======================================================================
//  EMAIL MARKETING  — campaigns / templates / audiences (email.php)
// ======================================================================
function emailStatusBadge(s) {
  s = String(s || 'Draft');
  const l = s.toLowerCase();
  const cls = l === 'sent' ? 'badge-green' : (l === 'sending' || l === 'scheduled' ? 'badge-yellow' : (l === 'failed' ? 'badge-red' : 'badge-gray'));
  return crmBadge(s, cls);
}
async function renderCrmEmail() {
  const tab = CrmMod.tab.email || 'campaigns';
  const tabBar = crmTabBar([['campaigns', 'Campaigns'], ['templates', 'Templates'], ['audiences', 'Audiences']], tab, 'emailSetTab');
  let inner = '', action = '', subtitle = 'Audiences, templates, and campaigns in one place.';
  if (tab === 'templates') {
    let d = CrmMod.cache.emailTemplates;
    if (!d) { d = await crmApiGet('email.php?action=templates_list'); CrmMod.cache.emailTemplates = d; }
    const rows = (d.data || []).map(t => `<tr>
      <td><strong>${esc(t.name)}</strong></td><td>${esc(t.subject || '—')}</td><td>${esc(t.category || '—')}</td><td>${crmModDate(t.updated_at)}</td>
      <td><div class="ct-actions-cell"><button class="btn btn-outline btn-sm" onclick="emailTemplateForm(${t.template_id})">Edit</button>
      <button class="btn btn-outline btn-sm btn-danger" onclick="emailTemplateDelete(${t.template_id})">Delete</button></div></td></tr>`).join('');
    action = `<button class="btn btn-primary" onclick="emailTemplateForm()">+ New Template</button>`;
    inner = crmTable(['Template', 'Subject', 'Category', 'Updated', 'Actions'], rows, 'No templates yet.');
  } else if (tab === 'audiences') {
    let d = CrmMod.cache.emailLists;
    if (!d) { d = await crmApiGet('email.php?action=lists_list'); CrmMod.cache.emailLists = d; }
    const lists = d.data || [];
    const stats = crmStatRow([['Total Lists', lists.length, CT_IC.users], ['Total Subscribers', lists.reduce((a, l) => a + Number(l.active_members || 0), 0), CT_IC.users]]);
    const rows = lists.map(l => `<tr>
      <td><strong>${esc(l.name)}</strong>${l.description ? `<div class="ct-secline">${esc(l.description)}</div>` : ''}</td>
      <td>${crmBadge(Number(l.active_members || 0), 'badge-green')}</td>
      <td>${crmModDate(l.updated_at)}</td>
      <td><div class="ct-actions-cell">
        <button class="btn btn-outline btn-sm" onclick="listViewMembers(${l.list_id})">Members</button>
        <button class="btn btn-outline btn-sm" onclick="listPopulate(${l.list_id})">Add Leads</button>
        <button class="btn btn-outline btn-sm" onclick="listForm(${l.list_id})">Rename</button>
        <button class="btn btn-outline btn-sm btn-danger" onclick="listDelete(${l.list_id})">Delete</button>
      </div></td></tr>`).join('');
    action = `<button class="btn btn-primary" onclick="listForm()">+ New List</button>`;
    inner = stats + crmTable(['List Name', 'Active Members', 'Created', 'Actions'], rows, 'No lists yet. Create your first audience list!');
  } else {
    let d = CrmMod.cache.emailCampaigns;
    if (!d) { d = await crmApiGet('email.php?action=campaigns_list'); CrmMod.cache.emailCampaigns = d; }
    const camps = d.data || [];
    const stats = crmStatRow([
      ['Total Campaigns', camps.length, CT_IC.mail],
      ['Sent', camps.filter(c => c.status === 'Sent').length, CT_IC.check],
      ['Drafts', camps.filter(c => c.status === 'Draft').length, CT_IC.edit],
      ['Emails Sent', camps.reduce((a, c) => a + Number(c.total_sent || 0), 0), CT_IC.send],
    ]);
    const rows = camps.map(c => {
      const st = String(c.status || 'Draft'), l = st.toLowerCase();
      const editable = l === 'draft' || l === 'scheduled';
      return `<tr>
        <td><strong>${esc(c.name)}</strong>${c.subject ? `<div class="ct-secline">${esc(c.subject)}</div>` : ''}</td>
        <td>${emailStatusBadge(st)}</td>
        <td>${esc(c.list_name || '—')}</td>
        <td>${Number(c.total_sent || 0)}</td>
        <td>${Number(c.total_opened || 0)}</td>
        <td>${Number(c.total_clicked || 0)}</td>
        <td>${crmModDate(c.created_at)}</td>
        <td><div class="ct-actions-cell">
          ${editable ? `<button class="btn btn-outline btn-sm" onclick="campaignForm(${c.campaign_id})">Edit</button>` : ''}
          ${(l === 'sent' || l === 'sending') ? `<button class="btn btn-outline btn-sm" onclick="campaignReport(${c.campaign_id})">Report</button>` : ''}
          <button class="btn btn-outline btn-sm" onclick="campaignDuplicate(${c.campaign_id})">Copy</button>
          ${l === 'draft' ? `<button class="btn btn-outline btn-sm btn-danger" onclick="campaignDelete(${c.campaign_id})">Del</button>` : ''}
        </div></td></tr>`;
    }).join('');
    action = `<button class="btn btn-primary" onclick="campaignForm()">+ New Campaign</button>`;
    inner = stats + crmTable(['Campaign', 'Status', 'List', 'Sent', 'Opened', 'Clicked', 'Created', 'Actions'], rows, 'No campaigns yet. Create your first campaign!');
  }
  return `<div class="crm-native fade-in">${crmModHead('Email Marketing', subtitle, action)}${tabBar}${inner}</div>`;
}
function emailSetTab(t) { CrmMod.tab.email = t; render(); }

// --- templates ---
function emailTemplateForm(id = null) {
  const t = id ? (CrmMod.cache.emailTemplates?.data || []).find(x => Number(x.template_id) === Number(id)) || {} : {};
  const v = x => esc(x == null ? '' : String(x));
  Modal.open({
    title: id ? 'Edit Template' : 'New Template',
    body: crmModalBody(`
      <div class="ct-two">
        <div class="form-group"><label class="form-label">Template Name <span style="color:var(--color-danger)">*</span></label><input class="form-control" id="et-name" value="${v(t.name)}"></div>
        <div class="form-group"><label class="form-label">Category</label><select class="form-control" id="et-category">${['Marketing', 'Newsletter', 'Announcement', 'Follow-up', 'Welcome', 'Custom'].map(o => `<option ${(t.category || 'Custom') === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div>
      </div>
      <div class="form-group"><label class="form-label">Subject Line</label><input class="form-control" id="et-subject" value="${v(t.subject)}"></div>
      <div class="form-group"><label class="form-label">HTML Content</label><textarea class="form-control ct-mono" id="et-html" rows="10">${v(t.content_html)}</textarea>
        <div class="form-hint">Merge tags: {{company_name}}, {{contact_person}}, {{email}}, {{unsubscribe_url}}</div></div>
      <div class="form-error" id="et-error" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="emailTemplateSave(${id || 0})">${id ? 'Save Changes' : 'Create Template'}</button>`,
    onMount: () => crmWidenModal(760),
  });
}
async function emailTemplateSave(id) {
  const err = document.getElementById('et-error');
  const g = i => document.getElementById(i)?.value ?? '';
  try {
    if (!g('et-name').trim()) throw new Error('Template name is required.');
    const payload = { name: g('et-name'), category: g('et-category'), subject: g('et-subject'), content_html: g('et-html'), content_json: '[]' };
    if (id) payload.template_id = id;
    await crmApiPost('email.php?action=template_save', payload);
    crmModInvalidate('emailTemplates'); Modal.close(); toast(id ? 'Template saved' : 'Template created', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
function emailTemplateDelete(id) { appConfirm('Delete this template?', async () => { try { await crmApiPost('email.php?action=template_delete', { template_id: id }); crmModInvalidate('emailTemplates'); toast('Template deleted', 'success'); render(); } catch (e) { toast(e.message, 'error'); } }); }

// --- audiences ---
function listForm(id = null) {
  const l = id ? (CrmMod.cache.emailLists?.data || []).find(x => Number(x.list_id) === Number(id)) || {} : {};
  Modal.open({
    title: id ? 'Rename List' : 'New Email List',
    body: crmModalBody(`
      <div class="form-group"><label class="form-label">List Name <span style="color:var(--color-danger)">*</span></label><input class="form-control" id="el-name" value="${esc(l.name || '')}" placeholder="e.g., Europe Interested Leads"></div>
      <div class="form-group"><label class="form-label">Description</label><textarea class="form-control" id="el-desc" rows="2" placeholder="Optional description...">${esc(l.description || '')}</textarea></div>
      <div class="form-error" id="el-error" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="listSave(${id || 0})">${id ? 'Save' : 'Create List'}</button>`,
  });
}
async function listSave(id) {
  const err = document.getElementById('el-error');
  try {
    const name = document.getElementById('el-name').value.trim();
    if (!name) throw new Error('List name is required.');
    const payload = { name, description: document.getElementById('el-desc').value };
    if (id) payload.list_id = id;
    await crmApiPost('email.php?action=list_save', payload);
    crmModInvalidate('emailLists'); Modal.close(); toast(id ? 'List saved' : 'List created', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
function listDelete(id) { appConfirm('Delete this list and all its members?', async () => { try { await crmApiPost('email.php?action=list_delete', { list_id: id }); crmModInvalidate('emailLists'); toast('List deleted', 'success'); render(); } catch (e) { toast(e.message, 'error'); } }); }
async function listViewMembers(id) {
  try {
    const d = await crmApiGet('email.php?action=list_get&id=' + id);
    if (!d.success) throw new Error(d.message);
    const list = d.data || {}, members = list.members || [];
    const rows = members.map(m => `<tr>
      <td>${esc(m.contact_person || m.company_name || '—')}</td>
      <td>${esc(m.email || '')}</td>
      <td>${crmBadge(m.status || 'Active', m.status === 'Unsubscribed' ? 'badge-red' : 'badge-green')}</td>
      <td><button class="btn btn-outline btn-sm btn-danger" onclick="listRemoveMember(${m.id},${id})">Remove</button></td></tr>`).join('');
    Modal.open({
      title: 'Members — ' + (list.name || ''),
      body: crmModalBody(`${crmTable(['Contact', 'Email', 'Status', ''], rows, 'No members yet. Use “Add Leads” to populate this list.')}`),
      footer: `<button class="btn-secondary" onclick="Modal.close()">Close</button><button class="btn-primary" onclick="listPopulate(${id})">Add Leads</button>`,
      onMount: () => crmWidenModal(700),
    });
  } catch (e) { toast(e.message, 'error'); }
}
async function listRemoveMember(memberId, listId) {
  try { await crmApiPost('email.php?action=list_remove_member', { member_id: memberId }); crmModInvalidate('emailLists'); toast('Member removed', 'success'); listViewMembers(listId); }
  catch (e) { toast(e.message, 'error'); }
}
function listPopulate(id) {
  const statuses = ['New Lead', 'Contacted', 'Interested', 'Not Interested', 'Won', 'Lost', 'On Hold'];
  const types = ['Stable', 'Owner', 'Breeder', 'Trainer', 'Veterinarian', 'Consultant'];
  const prios = ['Urgent', 'High', 'Medium', 'Low'];
  Modal.open({
    title: 'Add Leads to List',
    body: crmModalBody(`
      <p class="ct-secline" style="margin-bottom:14px">Filter leads to add to this list. Only leads with email addresses are included. Unsubscribed contacts are automatically excluded.</p>
      <div class="ct-two">
        <div class="form-group"><label class="form-label">Lead Status</label><select class="form-control" id="lp-status"><option value="">All statuses</option>${statuses.map(s => `<option>${s}</option>`).join('')}</select></div>
        <div class="form-group"><label class="form-label">Country</label><input class="form-control" id="lp-country" placeholder="e.g., United States"></div>
      </div>
      <div class="ct-two">
        <div class="form-group"><label class="form-label">Lead Type</label><select class="form-control" id="lp-type"><option value="">All types</option>${types.map(s => `<option>${s}</option>`).join('')}</select></div>
        <div class="form-group"><label class="form-label">Priority</label><select class="form-control" id="lp-priority"><option value="">All priorities</option>${prios.map(s => `<option>${s}</option>`).join('')}</select></div>
      </div>
      <div class="form-error" id="lp-error" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" onclick="listRunPopulate(${id})">Add Matching Leads</button>`,
  });
}
async function listRunPopulate(id) {
  const err = document.getElementById('lp-error');
  try {
    const filters = {};
    const s = document.getElementById('lp-status').value; if (s) filters.status = s;
    const c = document.getElementById('lp-country').value.trim(); if (c) filters.country = c;
    const t = document.getElementById('lp-type').value; if (t) filters.lead_type = t;
    const p = document.getElementById('lp-priority').value; if (p) filters.priority = p;
    const r = await crmApiPost('email.php?action=list_populate', { list_id: id, filters });
    crmModInvalidate('emailLists'); Modal.close();
    toast(`Added ${r.data.added} members, skipped ${r.data.skipped}`, 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}

// --- campaigns: the composer is a full SCREEN, not a modal ---
// The visual builder needs real width; inside a 860px modal it was unusable,
// which is why it looked "gone". campaignForm() now navigates to
// #crm/email-builder[/id] and renderEmailBuilderPage() draws the same controls
// full-width. All the block-builder helpers below are unchanged — they address
// elements by id, so they work identically on a page.
function campaignForm(id = null) {
  State.emailCampaignId = id ? Number(id) : null;
  State.screen = 'crm-email-builder';
  if (typeof updateHash === 'function') updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
}

function emailBuilderClose() {
  State.emailCampaignId = null;
  CrmMod.tab.email = 'campaigns';
  nav('crm-email');
}

async function renderEmailBuilderPage() {
  if (typeof crmHas === 'function' && !crmHas('crm.email')) return crmAccessDenied('crm.email');
  ensureCrmModStyles();
  const id = State.emailCampaignId || null;

  let lists = CrmMod.cache.emailLists;
  if (!lists) { lists = await crmApiGet('email.php?action=lists_list'); CrmMod.cache.emailLists = lists; }
  let templates = CrmMod.cache.emailTemplates;
  if (!templates) { templates = await crmApiGet('email.php?action=templates_list'); CrmMod.cache.emailTemplates = templates; }

  let c = {};
  if (id) {
    const r = await crmApiGet('email.php?action=campaign_get&id=' + id);
    if (!r.success) return crmModError('Campaign', r.message || 'Campaign not found.');
    c = r.data || {};
  }
  CrmMod.campaignEdit = id || 0;
  CrmMod.emailBlocks = [];
  CrmMod.emailMode = (c.content_html && c.content_html.trim()) ? 'html' : 'blocks';

  const listOpts = (lists.data || []).map(l => `<option value="${l.list_id}" ${c.list_id == l.list_id ? 'selected' : ''}>${esc(l.name)} (${Number(l.active_members || l.member_count || 0)} members)</option>`).join('');
  const tplOpts = (templates.data || []).map(t => `<option value="${t.template_id}" ${c.template_id == t.template_id ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
  const sched = c.scheduled_at ? String(c.scheduled_at).replace(' ', 'T').slice(0, 16) : '';
  const status = String(c.status || 'Draft').toLowerCase();
  const canSend = id && (status === 'draft' || status === 'scheduled');

  // Re-render the canvas once the DOM exists.
  setTimeout(() => { emailEditorMode(CrmMod.emailMode || 'blocks'); emailCanvasRender(); }, 0);

  const actions = `
    <button class="btn btn-outline" onclick="emailBuilderClose()">${CRM_ICONS && CRM_ICONS.back ? CRM_ICONS.back : ''} Back</button>
    <button class="btn btn-outline" type="button" onclick="campaignPreview()">Preview</button>
    ${canSend ? `<button class="btn btn-primary" style="background:var(--green,#34c759);border-color:var(--green,#34c759)" onclick="campaignSaveThenSend(${id})">Save &amp; send now</button>` : ''}
    <button class="btn btn-primary" onclick="campaignSave()">${id ? 'Save campaign' : 'Create campaign'}</button>`;

  return `<div class="crm-native fade-in eb-page">
    ${crmModHead(id ? 'Edit campaign' : 'New campaign',
      id ? `${esc(c.name || '')}${c.status ? ' · ' + esc(c.status) : ''}` : 'Compose the email, pick an audience, then send or schedule.',
      actions)}

    <div class="eb-layout">
      <div class="eb-main">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Email body</h3>
            <div class="ct-tabs" style="margin:0">
              <button type="button" class="ct-tab active" id="eb-tab-blocks" onclick="emailEditorMode('blocks')">Visual builder</button>
              <button type="button" class="ct-tab" id="eb-tab-html" onclick="emailEditorMode('html')">HTML</button>
            </div>
          </div>
          <div class="card-body">
            <div id="eb-blocks-pane">
              <div class="eb-palette">
                <button type="button" class="btn btn-sm btn-outline" onclick="emailAddBlock('heading')">+ Heading</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="emailAddBlock('text')">+ Text</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="emailAddBlock('button')">+ Button</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="emailAddBlock('image')">+ Image</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="emailAddBlock('divider')">+ Divider</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="emailAddBlock('spacer')">+ Spacer</button>
              </div>
              <div id="eb-canvas" class="eb-canvas"></div>
            </div>
            <div id="eb-html-pane" style="display:none">
              <textarea class="form-control ct-mono" id="cf-html" rows="22" placeholder="<html>…</html>" oninput="emailHtmlEdited()">${esc(c.content_html || '')}</textarea>
            </div>
            <div class="form-hint" style="margin-top:10px">Merge tags resolved on send: {{company_name}}, {{contact_person}}, {{email}}, {{unsubscribe_url}}. Include an unsubscribe link, e.g. &lt;a href="{{unsubscribe_url}}"&gt;Unsubscribe&lt;/a&gt;.</div>
          </div>
        </div>
      </div>

      <aside class="eb-side">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Campaign</h3></div>
          <div class="card-body">
            <div class="form-group"><label class="form-label">Campaign name <span style="color:var(--color-danger)">*</span></label>
              <input class="form-control" id="cf-name" value="${esc(c.name || '')}" placeholder="e.g. January Newsletter"></div>
            <div class="form-group"><label class="form-label">Subject line <span style="color:var(--color-danger)">*</span></label>
              <input class="form-control" id="cf-subject" value="${esc(c.subject || '')}" placeholder="e.g. Exciting news!"></div>
            <div class="form-group"><label class="form-label">Audience list <span style="color:var(--color-danger)">*</span></label>
              <select class="form-control" id="cf-list"><option value="">— Select a list —</option>${listOpts}</select>
              <div class="form-hint">Required before the campaign can send.</div></div>
            <div class="form-group" style="margin:0"><label class="form-label">Schedule</label>
              <input type="datetime-local" class="form-control" id="cf-scheduled" value="${sched}">
              <div class="form-hint">Leave blank to send manually.</div></div>
          </div>
        </div>

        <div class="card" style="margin-top:14px">
          <div class="card-header"><h3 class="card-title">Sender</h3></div>
          <div class="card-body">
            <div class="form-group"><label class="form-label">From name</label>
              <input class="form-control" id="cf-fromname" value="${esc(c.from_name || '')}" placeholder="Victory Genomics"></div>
            <div class="form-group"><label class="form-label">From email</label>
              <input type="email" class="form-control" id="cf-fromemail" value="${esc(c.from_email || '')}" placeholder="marketing@victorygenomics.com"></div>
            <div class="form-group" style="margin:0"><label class="form-label">Reply-to</label>
              <input type="email" class="form-control" id="cf-replyto" value="${esc(c.reply_to || '')}"></div>
          </div>
        </div>

        <div class="card" style="margin-top:14px">
          <div class="card-header"><h3 class="card-title">Start from template</h3></div>
          <div class="card-body">
            <div style="display:flex;gap:8px">
              <select class="form-control" id="cf-template"><option value="">— Blank —</option>${tplOpts}</select>
              <button class="btn btn-outline btn-sm" type="button" onclick="campaignLoadTemplate()">Load</button>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:14px">
          <div class="card-header"><h3 class="card-title">Send a test</h3></div>
          <div class="card-body">
            <div class="form-group"><label class="form-label">Test address</label>
              <input type="email" class="form-control" id="cf-test" placeholder="you@example.com">
              <div class="form-hint">Sends the current body without merge tags.</div></div>
            <button class="btn btn-outline" type="button" onclick="campaignSendTest()">Send test email</button>
          </div>
        </div>

        <div class="form-error" id="cf-error" style="display:none;margin-top:12px"></div>
      </aside>
    </div>

    <div class="eb-footer">${actions}</div>
  </div>`;
}

// ===== Email visual block builder =====
// Writes generated HTML into the hidden #cf-html textarea (the source of truth
// for save/test/preview). An HTML tab remains for fine-tuning. Existing
// campaigns open in HTML mode; the builder only overwrites the textarea once
// the user actually adds a block.
const EMAIL_BLOCK_LABELS = { heading: 'Heading', text: 'Text', button: 'Button', image: 'Image', divider: 'Divider', spacer: 'Spacer' };
let _emailBlockSeq = 0;
let _emailDragIdx = null;
function emailEditorMode(mode) {
  CrmMod.emailMode = mode;
  const bp = document.getElementById('eb-blocks-pane');
  const hp = document.getElementById('eb-html-pane');
  const tb = document.getElementById('eb-tab-blocks');
  const th = document.getElementById('eb-tab-html');
  if (!bp || !hp) return;
  const blocks = mode === 'blocks';
  bp.style.display = blocks ? '' : 'none';
  hp.style.display = blocks ? 'none' : '';
  tb?.classList.toggle('active', blocks);
  th?.classList.toggle('active', !blocks);
}
function emailAddBlock(type) {
  const defaults = {
    heading: { text: 'Your headline here', level: 'h2', align: 'left' },
    text: { text: 'Write your message here. You can use multiple lines.', align: 'left' },
    button: { text: 'Click here', url: 'https://', align: 'center' },
    image: { src: '', alt: '', url: '', align: 'center' },
    divider: {},
    spacer: { height: 24 },
  };
  CrmMod.emailBlocks.push({ id: ++_emailBlockSeq, type, ...(defaults[type] || {}) });
  emailCanvasRender();
  emailBuildHtml();
}
function emailBlockField(i, prop, val) {
  const b = CrmMod.emailBlocks[i];
  if (!b) return;
  b[prop] = val;
  emailBuildHtml();
}
function emailMoveBlock(i, dir) {
  const j = i + dir;
  const a = CrmMod.emailBlocks;
  if (j < 0 || j >= a.length) return;
  [a[i], a[j]] = [a[j], a[i]];
  emailCanvasRender();
  emailBuildHtml();
}
function emailRemoveBlock(i) {
  CrmMod.emailBlocks.splice(i, 1);
  emailCanvasRender();
  emailBuildHtml();
}
function emailDragStart(e, i) { _emailDragIdx = i; e.dataTransfer.effectAllowed = 'move'; }
function emailDragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
function emailDrop(e, i) {
  e.preventDefault();
  const from = _emailDragIdx;
  _emailDragIdx = null;
  if (from == null || from === i) return;
  const a = CrmMod.emailBlocks;
  const [moved] = a.splice(from, 1);
  a.splice(i, 0, moved);
  emailCanvasRender();
  emailBuildHtml();
}
function emailBlockEditor(b, i) {
  const alignSel = (prop) => `<select class="form-control" style="max-width:120px;" onchange="emailBlockField(${i},'${prop}',this.value)">${['left', 'center', 'right'].map(a => `<option value="${a}" ${b[prop] === a ? 'selected' : ''}>${a}</option>`).join('')}</select>`;
  let fields = '';
  if (b.type === 'heading') {
    fields = `<div style="display:flex;gap:8px;margin-bottom:6px;">
        <select class="form-control" style="max-width:90px;" onchange="emailBlockField(${i},'level',this.value)">${['h1', 'h2', 'h3'].map(l => `<option value="${l}" ${b.level === l ? 'selected' : ''}>${l.toUpperCase()}</option>`).join('')}</select>
        ${alignSel('align')}</div>
      <input class="form-control" value="${esc(b.text)}" oninput="emailBlockField(${i},'text',this.value)" placeholder="Heading text">`;
  } else if (b.type === 'text') {
    fields = `<div style="margin-bottom:6px;">${alignSel('align')}</div>
      <textarea class="form-control" rows="3" oninput="emailBlockField(${i},'text',this.value)" placeholder="Paragraph text">${esc(b.text)}</textarea>`;
  } else if (b.type === 'button') {
    fields = `<div style="display:flex;gap:8px;margin-bottom:6px;">
        <input class="form-control" value="${esc(b.text)}" oninput="emailBlockField(${i},'text',this.value)" placeholder="Button label">
        ${alignSel('align')}</div>
      <input class="form-control" value="${esc(b.url)}" oninput="emailBlockField(${i},'url',this.value)" placeholder="https://link-destination">`;
  } else if (b.type === 'image') {
    fields = `<input class="form-control" style="margin-bottom:6px;" value="${esc(b.src)}" oninput="emailBlockField(${i},'src',this.value)" placeholder="Image URL (https://…)">
      <div style="display:flex;gap:8px;margin-bottom:6px;">
        <input class="form-control" value="${esc(b.alt)}" oninput="emailBlockField(${i},'alt',this.value)" placeholder="Alt text">
        ${alignSel('align')}</div>
      <input class="form-control" value="${esc(b.url)}" oninput="emailBlockField(${i},'url',this.value)" placeholder="Link URL (optional)">`;
  } else if (b.type === 'spacer') {
    fields = `<label class="form-label" style="font-size:12px;">Height (px)</label>
      <input type="number" class="form-control" style="max-width:120px;" value="${Number(b.height) || 24}" oninput="emailBlockField(${i},'height',this.value)">`;
  } else if (b.type === 'divider') {
    fields = `<div class="text-muted" style="font-size:12px;">A horizontal separator line.</div>`;
  }
  return `<div class="eb-block card" draggable="true" ondragstart="emailDragStart(event,${i})" ondragover="emailDragOver(event)" ondrop="emailDrop(event,${i})" style="padding:12px;margin-bottom:8px;border:1px solid rgba(0,0,0,0.08);">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <span style="display:flex;align-items:center;gap:8px;"><span style="cursor:grab;color:#999;" title="Drag to reorder">⋮⋮</span><strong style="font-size:13px;">${esc(EMAIL_BLOCK_LABELS[b.type] || b.type)}</strong></span>
        <span style="display:flex;gap:4px;">
          <button type="button" class="btn btn-sm btn-outline" ${i === 0 ? 'disabled' : ''} onclick="emailMoveBlock(${i},-1)" title="Move up">↑</button>
          <button type="button" class="btn btn-sm btn-outline" ${i === CrmMod.emailBlocks.length - 1 ? 'disabled' : ''} onclick="emailMoveBlock(${i},1)" title="Move down">↓</button>
          <button type="button" class="btn btn-sm btn-outline" style="color:#B0432B;border-color:#B0432B;" onclick="emailRemoveBlock(${i})" title="Remove">✕</button>
        </span>
      </div>
      ${fields}</div>`;
}
function emailCanvasRender() {
  const el = document.getElementById('eb-canvas');
  if (!el) return;
  if (!CrmMod.emailBlocks.length) {
    el.innerHTML = `<div class="empty-state" style="padding:28px 16px;text-align:center;border:2px dashed rgba(0,0,0,0.12);border-radius:8px;"><p class="text-muted" style="margin:0;">Add blocks above to build your email. Drag blocks to reorder.</p></div>`;
    return;
  }
  el.innerHTML = CrmMod.emailBlocks.map((b, i) => emailBlockEditor(b, i)).join('');
}
function emailBlockToHtml(b) {
  const esch = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const align = b.align || 'left';
  if (b.type === 'heading') {
    const size = b.level === 'h1' ? '28px' : (b.level === 'h3' ? '18px' : '22px');
    return `<h2 style="margin:0 0 14px;font-size:${size};line-height:1.3;color:#1a1a1a;text-align:${align};font-weight:700;">${esch(b.text)}</h2>`;
  }
  if (b.type === 'text') {
    return `<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#333;text-align:${align};">${esch(b.text).replace(/\n/g, '<br>')}</p>`;
  }
  if (b.type === 'button') {
    const url = b.url || '#';
    return `<div style="text-align:${b.align || 'center'};margin:0 0 18px;"><a href="${esch(url)}" style="display:inline-block;padding:12px 28px;background:#B0432B;color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;">${esch(b.text || 'Click here')}</a></div>`;
  }
  if (b.type === 'image') {
    if (!b.src) return '';
    const img = `<img src="${esch(b.src)}" alt="${esch(b.alt)}" style="max-width:100%;height:auto;border:0;display:inline-block;">`;
    const wrapped = b.url ? `<a href="${esch(b.url)}">${img}</a>` : img;
    return `<div style="text-align:${b.align || 'center'};margin:0 0 18px;">${wrapped}</div>`;
  }
  if (b.type === 'divider') {
    return `<hr style="border:0;border-top:1px solid #e2e2e2;margin:18px 0;">`;
  }
  if (b.type === 'spacer') {
    return `<div style="height:${Number(b.height) || 24}px;line-height:${Number(b.height) || 24}px;font-size:1px;">&nbsp;</div>`;
  }
  return '';
}
function emailBuildHtml() {
  // Only overwrite the source HTML once blocks exist, so opening an existing
  // campaign in the builder tab doesn't wipe its hand-written HTML by accident.
  if (!CrmMod.emailBlocks.length) return;
  const body = CrmMod.emailBlocks.map(emailBlockToHtml).join('\n');
  const html = `<div style="max-width:600px;margin:0 auto;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#333;background:#ffffff;">
${body}
<div style="margin-top:28px;padding-top:16px;border-top:1px solid #eee;font-size:12px;color:#999;text-align:center;">
<a href="{{unsubscribe_url}}" style="color:#999;">Unsubscribe</a>
</div>
</div>`;
  const ta = document.getElementById('cf-html');
  if (ta) ta.value = html;
}
function emailHtmlEdited() {
  // User hand-edited the HTML directly — detach the builder so it won't clobber
  // their edits on the next block change.
  CrmMod.emailBlocks = [];
  emailCanvasRender();
}
async function campaignSendTest() {
  const to = (document.getElementById('cf-test').value || '').trim();
  const subject = (document.getElementById('cf-subject').value || '').trim() || 'Test Email';
  const html = document.getElementById('cf-html').value || '';
  if (!to) { toast('Enter a test email address', 'error'); return; }
  if (!html.trim()) { toast('Add email content before sending a test', 'error'); return; }
  try { await crmApiPost('email.php?action=send_test', { test_email: to, subject, content_html: html }); toast('Test email sent to ' + to, 'success'); }
  catch (e) { toast(e.message, 'error'); }
}
function campaignPreview() {
  const html = document.getElementById('cf-html').value || '';
  const w = window.open('', '_blank', 'width=820,height=1000');
  if (!w) { toast('Popup blocked — allow popups to preview.', 'error'); return; }
  w.document.write(html || '<p style="font-family:sans-serif;padding:40px;color:#888">No email content yet.</p>');
  w.document.close();
}
async function campaignLoadTemplate() {
  const id = document.getElementById('cf-template').value;
  if (!id) { toast('Select a template first', 'error'); return; }
  try {
    const r = await crmApiGet('email.php?action=template_get&id=' + id);
    if (!r.success) throw new Error(r.message);
    const body = document.getElementById('cf-html');
    const subj = document.getElementById('cf-subject');
    body.value = r.data.content_html || '';
    if (!subj.value && r.data.subject) subj.value = r.data.subject;
    toast('Template loaded into body', 'success');
  } catch (e) { toast(e.message, 'error'); }
}
function campaignCollect() {
  const g = i => document.getElementById(i)?.value ?? '';
  const name = g('cf-name').trim(), subject = g('cf-subject').trim();
  if (!name) throw new Error('Campaign name is required.');
  if (!subject) throw new Error('Subject line is required.');
  const payload = {
    campaign_id: CrmMod.campaignEdit || 0,
    name, subject,
    from_name: g('cf-fromname'), from_email: g('cf-fromemail'), reply_to: g('cf-replyto'),
    list_id: g('cf-list') || null, template_id: g('cf-template') || null,
    content_html: g('cf-html'), content_json: '[]',
    scheduled_at: g('cf-scheduled') || null,
  };
  return payload;
}
async function campaignSave() {
  const err = document.getElementById('cf-error');
  try {
    const r = await crmApiPost('email.php?action=campaign_save', campaignCollect());
    crmModInvalidate('emailCampaigns');
    toast('Campaign saved', 'success');
    if (State.screen === 'crm-email-builder') emailBuilderClose(); else render();
    return r.data ? r.data.campaign_id : null;
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; return null; }
}
async function campaignSaveThenSend(id) {
  const err = document.getElementById('cf-error');
  try {
    const payload = campaignCollect();
    if (!payload.list_id) throw new Error('Select an audience list before sending.');
    if (!payload.content_html.trim()) throw new Error('Add email content before sending.');
    await crmApiPost('email.php?action=campaign_save', payload);
    appConfirm('Send this campaign to the selected audience now?', () => {
      emailBuilderClose();
      campaignSendLoop(id);
    });
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
async function campaignSendLoop(id) {
  try {
    toast('Sending campaign…', 'info');
    let remaining = Infinity, sent = 0, failed = 0, guard = 0;
    while (remaining > 0 && guard < 1000) {
      const r = await crmApiPost('email.php?action=campaign_send', { campaign_id: id });
      sent += Number(r.data.sent || 0); failed += Number(r.data.failed || 0); remaining = Number(r.data.remaining || 0); guard++;
    }
    crmModInvalidate('emailCampaigns'); toast(`Campaign complete: ${sent} sent, ${failed} failed`, 'success'); render();
  } catch (e) { crmModInvalidate('emailCampaigns'); toast(e.message, 'error'); render(); }
}
function campaignDelete(id) { appConfirm('Delete this draft campaign?', async () => { try { await crmApiPost('email.php?action=campaign_delete', { campaign_id: id }); crmModInvalidate('emailCampaigns'); toast('Campaign deleted', 'success'); render(); } catch (e) { toast(e.message, 'error'); } }); }
async function campaignDuplicate(id) { try { await crmApiPost('email.php?action=campaign_duplicate', { campaign_id: id }); crmModInvalidate('emailCampaigns'); toast('Campaign duplicated', 'success'); render(); } catch (e) { toast(e.message, 'error'); } }
async function campaignReport(id) {
  try {
    const r = await crmApiGet('email.php?action=campaign_report&id=' + id);
    if (!r.success) throw new Error(r.message);
    const c = r.data || {}, logs = c.logs || [];
    const sent = Number(c.total_sent || 0), rec = Number(c.total_recipients || 0);
    const opened = Number(c.total_opened || 0), clicked = Number(c.total_clicked || 0), failed = Number(c.total_failed || 0);
    const openRate = sent ? Math.round((opened / sent) * 1000) / 10 : 0;
    const clickRate = sent ? Math.round((clicked / sent) * 1000) / 10 : 0;
    const delivRate = rec ? Math.round((sent / rec) * 1000) / 10 : 0;
    const cards = crmStatRow([
      ['Delivered', sent + (delivRate ? ' (' + delivRate + '%)' : ''), CT_IC.send],
      ['Opened', opened + (openRate ? ' (' + openRate + '%)' : ''), CT_IC.mail],
      ['Clicked', clicked + (clickRate ? ' (' + clickRate + '%)' : ''), CT_IC.chart],
      ['Failed', failed, CT_IC.check],
    ]);
    const bar = (l, pct, color) => `<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px"><span>${l}</span><strong>${pct}%</strong></div><div class="ct-bar-track"><span class="ct-bar-fill" style="width:${pct}%;background:${color}"></span></div></div>`;
    const rows = logs.map(g => `<tr>
      <td>${esc(g.email || '')}</td>
      <td>${esc(g.company_name || g.contact_person || '—')}</td>
      <td>${emailStatusBadge(g.status)}</td>
      <td>${g.sent_at ? crmModDate(g.sent_at) : '—'}</td>
      <td>${g.opened_at ? crmModDate(g.opened_at) : '—'}</td>
      <td>${g.clicked_at ? crmModDate(g.clicked_at) : '—'}</td></tr>`).join('');
    Modal.open({
      title: 'Report — ' + (c.name || ''),
      body: crmModalBody(`
        <p class="ct-secline" style="margin-top:-4px;margin-bottom:12px">Subject: ${esc(c.subject || '—')} · List: ${esc(c.list_name || '—')} · ${emailStatusBadge(c.status)}</p>
        ${cards}
        <div class="card" style="margin-top:14px"><div class="card-body">
          ${bar('Open Rate', openRate, 'var(--color-success)')}
          ${bar('Click Rate', clickRate, 'var(--color-accent)')}
          ${bar('Delivery Rate', delivRate, 'var(--color-warning)')}
        </div></div>
        <div style="margin-top:14px">${crmTable(['Email', 'Company', 'Status', 'Sent', 'Opened', 'Clicked'], rows, 'No send log entries yet.')}</div>`),
      footer: `<button class="btn-secondary" onclick="Modal.close()">Close</button>`,
      onMount: () => crmWidenModal(820),
    });
  } catch (e) { toast(e.message, 'error'); }
}

// ======================================================================
//  COMMUNICATIONS  — VoIP softphone + call dashboard / WhatsApp (voip.php/whatsapp.php)
// ======================================================================
async function renderCrmComms() {
  const tab = CrmMod.tab.comms || 'calls';
  const tabBar = crmTabBar([['calls', 'Calls'], ['whatsapp', 'WhatsApp']], tab, 'commsSetTab');
  let inner = '', actions = '';
  if (tab === 'whatsapp') {
    const built = await waBuildScreen();
    inner = built.inner;
    actions = built.actions;
  } else {
    const built = await voipBuildCalls();
    inner = built.inner;
    actions = built.actions;
  }
  return `<div class="crm-native fade-in">${crmModHead('Communications', 'Every call and message attached to the same customer record.', actions)}${tabBar}${inner}</div>`;
}
function commsSetTab(t) { CrmMod.tab.comms = t; render(); }

// --- VoIP call dashboard (filters, KPIs, call-detail modal, recording playback) ---
CrmMod.voipFilter = CrmMod.voipFilter || { status: '', search: '', agent: '', page: 1, per_page: 50 };
const VOIP_STATUSES = ['Completed', 'No Answer', 'Busy', 'Failed', 'Canceled', 'In Progress', 'Ringing', 'Initiated'];
function voipFmtDur(sec) {
  sec = Number(sec) || 0;
  if (sec >= 3600) return Math.floor(sec / 3600) + 'h ' + Math.floor((sec % 3600) / 60) + 'm';
  if (sec >= 60) return Math.floor(sec / 60) + 'm ' + (sec % 60) + 's';
  return sec + 's';
}
async function voipBuildCalls() {
  const f = CrmMod.voipFilter;
  const qp = new URLSearchParams();
  qp.set('limit', f.per_page);
  qp.set('offset', (f.page - 1) * f.per_page);
  if (f.status) qp.set('status', f.status);
  if (f.search) qp.set('search', f.search);
  if (f.agent) qp.set('user_id', f.agent);

  // Fetch agents, stats and history concurrently (agents + stats are cached
  // after the first load, so subsequent renders only hit call_history).
  const [agentsRes, statsRes, h] = await Promise.all([
    CrmMod.cache.callAgents ? Promise.resolve(CrmMod.cache.callAgents) : crmApiGet('voip.php?action=agents').catch(() => ({ data: [] })),
    CrmMod.cache.callStats ? Promise.resolve(CrmMod.cache.callStats) : crmApiGet('voip.php?action=call_stats'),
    crmApiGet('voip.php?action=call_history&' + qp.toString()),
  ]);
  CrmMod.cache.callAgents = agentsRes;
  CrmMod.cache.callStats = statsRes;
  const agents = agentsRes.data || [];
  const st = statsRes.data || {};

  const calls = h.data || [];
  const total = Number(h.total || 0);
  const pages = Math.max(1, Math.ceil(total / f.per_page));
  CrmMod.cache.callHistoryRows = calls;

  const cards = crmStatRow([
    ['Total Calls', st.total_calls, CT_IC.phone], ['Today', st.today_calls, CT_IC.clock],
    ['Total Duration', voipFmtDur(st.total_duration), CT_IC.clock], ['Avg Duration', voipFmtDur(Math.round(Number(st.avg_duration || 0))), CT_IC.clock],
    ['Completed', st.completed, CT_IC.check], ['Positive', st.positive, CT_IC.check],
  ]);

  const opt = (list, sel) => list.map(x => `<option ${x === sel ? 'selected' : ''}>${esc(x)}</option>`).join('');
  const agentSelect = agents.length > 1
    ? `<div class="form-group filter-group" style="margin:0;"><label class="form-label">Agent</label>
        <select id="voip-f-agent" class="form-control" onchange="voipApplyFilters()"><option value="">All agents</option>${agents.map(a => `<option value="${a.user_id}" ${String(a.user_id) === String(f.agent) ? 'selected' : ''}>${esc(a.full_name || ('Agent #' + a.user_id))}</option>`).join('')}</select></div>`
    : '';
  const filterBar = `<div class="card filter-card"><div class="card-body">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div class="form-group filter-group" style="margin:0;"><label class="form-label">Search</label>
        <input id="voip-f-search" class="form-control" value="${esc(f.search)}" placeholder="Number, company, contact…" onkeydown="if(event.key==='Enter')voipApplyFilters()"></div>
      <div class="form-group filter-group" style="margin:0;"><label class="form-label">Status</label>
        <select id="voip-f-status" class="form-control" onchange="voipApplyFilters()"><option value="">All statuses</option>${opt(VOIP_STATUSES, f.status)}</select></div>
      ${agentSelect}
      <button class="btn btn-primary" onclick="voipApplyFilters()">Filter</button>
      <button class="btn btn-outline" onclick="voipResetFilters()">Reset</button>
    </div>
  </div></div>`;

  const rows = calls.map(v => {
    const rec = v.recording_url ? ' 🔊' : '';
    return `<tr class="clickable-row" style="cursor:pointer;" onclick="voipCallDetail('${esc(String(v.call_id))}')">
      <td>${esc(v.contact_person || v.company_name || (v.lead_id ? 'Lead #' + v.lead_id : '—'))}</td>
      <td style="font-family:ui-monospace,monospace">${esc((v.direction === 'Inbound' ? v.from_number : v.to_number) || '-')}</td>
      <td>${esc(v.direction || '')}</td>
      <td>${esc(v.user_name || '—')}</td>
      <td>${crmBadge(v.status || '', String(v.status).toLowerCase() === 'completed' ? 'badge-green' : 'badge-gray')}</td>
      <td>${v.duration_seconds ? crmDuration(v.duration_seconds) : '—'}</td>
      <td>${esc(v.outcome || '-')}${rec}</td>
      <td>${crmModDate(v.created_at)}</td></tr>`;
  }).join('');
  const table = crmTable(['Lead / Contact', 'Number', 'Direction', 'Agent', 'Status', 'Duration', 'Outcome', 'Date'], rows, 'No calls match these filters.');

  const start = total ? (f.page - 1) * f.per_page + 1 : 0;
  const end = Math.min(f.page * f.per_page, total);
  const pager = total > f.per_page ? `<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px;">
      <span class="text-muted" style="font-size:13px;">${start}–${end} of ${total}</span>
      <div style="display:flex;gap:6px;">
        <button class="btn btn-sm btn-outline" ${f.page <= 1 ? 'disabled' : ''} onclick="voipPage(${f.page - 1})">← Prev</button>
        <span class="text-muted" style="font-size:13px;align-self:center;">Page ${f.page} / ${pages}</span>
        <button class="btn btn-sm btn-outline" ${f.page >= pages ? 'disabled' : ''} onclick="voipPage(${f.page + 1})">Next →</button>
      </div></div>` : '';

  const badge = CrmMod.voip.ready ? crmBadge('Softphone ready', 'badge-green') : crmBadge('Softphone idle', 'badge-gray');
  return {
    inner: cards + filterBar + table + pager,
    actions: `${badge} <button class="btn btn-primary" onclick="voipOpenSoftphone()">Quick Dial</button>`,
  };
}
function voipApplyFilters() {
  const f = CrmMod.voipFilter;
  f.search = document.getElementById('voip-f-search')?.value.trim() || '';
  f.status = document.getElementById('voip-f-status')?.value || '';
  f.agent = document.getElementById('voip-f-agent')?.value || '';
  f.page = 1;
  crmModInvalidate('callStats');
  render();
}
function voipResetFilters() {
  CrmMod.voipFilter = { status: '', search: '', agent: '', page: 1, per_page: 50 };
  crmModInvalidate('callStats');
  render();
}
function voipPage(p) { CrmMod.voipFilter.page = p; render(); }
function voipCallDetail(callId) {
  const v = (CrmMod.cache.callHistoryRows || []).find(x => String(x.call_id) === String(callId));
  if (!v) return;
  const number = (v.direction === 'Inbound' ? v.from_number : v.to_number) || '—';
  const field = (label, val) => `<div style="display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-bottom:1px solid rgba(0,0,0,0.06);"><span class="text-muted">${esc(label)}</span><span style="font-weight:600;text-align:right;">${val}</span></div>`;
  const recording = v.recording_url
    ? `<div style="margin-top:16px;"><div class="form-label" style="margin-bottom:6px;">Recording</div><audio src="${esc(v.recording_url)}" controls preload="none" style="width:100%;"></audio></div>`
    : `<div style="margin-top:16px;" class="text-muted">No recording available for this call.</div>`;
  Modal.open({
    title: 'Call Detail',
    body: crmModalBody(`
      ${field('Lead / Contact', esc(v.contact_person || v.company_name || (v.lead_id ? 'Lead #' + v.lead_id : '—')))}
      ${field('Number', `<span style="font-family:ui-monospace,monospace">${esc(number)}</span>`)}
      ${field('Direction', esc(v.direction || '—'))}
      ${field('Agent', esc(v.user_name || '—'))}
      ${field('Status', crmBadge(v.status || '—', String(v.status).toLowerCase() === 'completed' ? 'badge-green' : 'badge-gray'))}
      ${field('Duration', v.duration_seconds ? crmDuration(v.duration_seconds) : '—')}
      ${field('Outcome', esc(v.outcome || '—'))}
      ${field('Date', crmModDate(v.created_at))}
      ${v.notes ? `<div style="margin-top:16px;"><div class="form-label" style="margin-bottom:6px;">Notes</div><div class="timeline-notes">${esc(v.notes)}</div></div>` : ''}
      ${recording}`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Close</button>${v.lead_id ? `<button class="btn-primary" onclick="Modal.close();goCrmLead(${Number(v.lead_id)})">Open Lead</button>` : ''}`,
  });
}

// --- WhatsApp (faithful to whatsapp-dashboard.php + whatsapp.js) ---
CrmMod.wa = CrmMod.wa || { leadId: 0, toNumber: '', name: '', insideWindow: null, contentTemplates: [] };

function waEscJs(s) { return String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '').replace(/\r?\n/g, ' '); }
function waInitials(name) {
  const p = String(name || '').trim().split(/\s+/);
  if (!p[0]) return '?';
  let i = p[0][0] || '';
  if (p.length > 1) i += p[p.length - 1][0] || '';
  return i.toUpperCase();
}
function waTimeAgo(v) {
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return esc(v);
  const s = Math.floor((Date.now() - d.getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.floor(s / 60) + 'm ago';
  if (s < 86400) return Math.floor(s / 3600) + 'h ago';
  if (s < 604800) return Math.floor(s / 86400) + 'd ago';
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
function waMsgTime(v) {
  const d = new Date(String(v || '').replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function waStatusIcon(s) {
  const c = ({ Sent: '#86868b', Delivered: '#0071e3', Read: '#34c759', Failed: '#ff3b30' })[s] || '#86868b';
  if (s === 'Failed') return `<svg class="ct-wa-status" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="${c}" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
  return `<svg class="ct-wa-status" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="${c}" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`;
}

async function waBuildScreen() {
  const sub = CrmMod.tab.wa || 'inbox';
  // Prefetch stats and (on the default inbox) the chat list concurrently.
  const jobs = [CrmMod.cache.waStats ? Promise.resolve(CrmMod.cache.waStats) : crmApiGet('whatsapp.php?action=stats')];
  const wantChats = sub === 'inbox' && !CrmMod.cache.waChats;
  if (wantChats) jobs.push(crmApiGet('whatsapp.php?action=lead_chats').catch(() => null));
  const res = await Promise.all(jobs);
  CrmMod.cache.waStats = res[0];
  if (wantChats && res[1]) CrmMod.cache.waChats = res[1];
  const s = res[0];
  const st = s.data || {};
  const tplCount = CrmMod.cache.waContentTemplates
    ? (CrmMod.cache.waContentTemplates.data || []).filter(t => t.approval_status === 'approved').length
    : '—';
  const cards = crmStatRow([
    ['Messages Sent', st.total_sent, CT_IC.send],
    ['Received', st.total_received, CT_IC.mail],
    ['Today', st.today_sent, CT_IC.clock],
    ['Templates', tplCount, CT_IC.doc],
  ]);
  const subBar = crmTabBar([['inbox', 'Inbox'], ['unmatched', 'Unmatched'], ['templates', 'Templates']], sub, 'waSetSubTab');
  let body;
  if (sub === 'templates') body = await waRenderTemplatesTab();
  else if (sub === 'unmatched') body = await waRenderUnmatchedTab();
  else body = await waRenderInboxTab();
  return {
    inner: cards + subBar + `<div id="wa-subcontent">${body}</div>`,
    actions: `<button class="btn btn-primary wa-green-btn" onclick="waNewMessage()">+ New Message</button>`,
  };
}
function waSetSubTab(t) { CrmMod.tab.wa = t; render(); }

// --- Inbox (conversations grouped by lead) ---
async function waRenderInboxTab() {
  let c = CrmMod.cache.waChats;
  if (!c) { c = await crmApiGet('whatsapp.php?action=lead_chats'); CrmMod.cache.waChats = c; }
  const chats = c.data || [];
  if (!chats.length) return `<div class="card"><div class="card-body empty-state"><h3>No WhatsApp conversations yet</h3><p>Start by messaging a lead, or click “New Message” above.</p></div></div>`;
  const items = chats.map(ch => {
    const name = ch.contact_person || ch.company_name || ('Lead #' + ch.lead_id);
    const pre = ch.last_direction === 'Outbound' ? (ch.last_sender_name ? esc(ch.last_sender_name) + ': ' : 'You: ') : '';
    return `<div class="wa-inbox-item" onclick="waOpenChat(${ch.lead_id})">
      <div class="wa-avatar">${esc(waInitials(name))}</div>
      <div class="wa-inbox-main">
        <div class="wa-inbox-row"><span class="wa-inbox-name">${esc(name)}${typeof crmNotifPill === 'function' ? crmNotifPill(crmLeadNotifCount(ch.lead_id)) : ''}</span><small class="ct-secline">${waTimeAgo(ch.last_message_at)}</small></div>
        ${ch.company_name && ch.company_name !== name ? `<div class="ct-secline">${esc(ch.company_name)}</div>` : ''}
        <div class="wa-inbox-last">${pre}${esc((ch.last_message || '').slice(0, 80))}</div>
      </div>
      <div class="wa-inbox-meta"><span class="ct-secline">${Number(ch.message_count || 0)} msgs</span>${Number(ch.unread_count) ? `<span class="wa-unread">${Number(ch.unread_count)}</span>` : ''}</div>
    </div>`;
  }).join('');
  return `<div class="card"><div class="card-body" style="padding:0"><div class="wa-inbox-list">${items}</div></div></div>`;
}

// --- New quick message ---
function waNewMessage() {
  Modal.open({
    title: 'New WhatsApp Message',
    body: crmModalBody(`
      <div class="form-group"><label class="form-label">Phone Number</label><input class="form-control" id="wa-nm-num" placeholder="+971 50 123 4567"></div>
      <div class="form-group"><label class="form-label">Message</label><textarea class="form-control" id="wa-nm-body" rows="4" placeholder="Type your message…"></textarea></div>
      <div class="form-error" id="wa-nm-err" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary wa-green-btn" onclick="waSendQuick()">Send</button>`,
  });
}
async function waSendQuick() {
  const num = (document.getElementById('wa-nm-num').value || '').trim();
  const body = (document.getElementById('wa-nm-body').value || '').trim();
  const err = document.getElementById('wa-nm-err');
  if (!num || !body) { err.textContent = 'Phone number and message are required.'; err.style.display = 'block'; return; }
  try {
    await crmApiPost('whatsapp.php?action=send', { to_number: num, body, lead_id: 0 });
    crmModInvalidate('waChats'); crmModInvalidate('waStats'); Modal.close(); toast('Message sent', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}

// --- Chat panel (per-lead / per-number thread) ---
async function waOpenChat(leadId, toNumber, name) {
  ensureCrmModStyles();
  // Reading the conversation deals with its notifications.
  if (leadId && typeof clearRecordBadge === 'function') clearRecordBadge('crm_lead', Number(leadId));
  const ch = leadId ? (CrmMod.cache.waChats?.data || []).find(x => Number(x.lead_id) === Number(leadId)) : null;
  // Pick the first field that actually holds a dialable number — imported rows
  // often store "NA"/"-" in phone, with the real number in mobile (or vice versa).
  const num = crmIsPhone(toNumber) ? String(toNumber).trim() : (ch ? crmLeadPhone(ch) : '');
  const nm = name || (ch ? (ch.contact_person || ch.company_name || ('Lead #' + leadId)) : (num || 'Contact'));
  CrmMod.wa = { leadId: leadId || 0, toNumber: num, name: nm, insideWindow: null, contentTemplates: (CrmMod.cache.waContentTemplates?.data) || [] };
  Modal.open({
    title: 'WhatsApp — ' + nm,
    body: crmModalBody(`
      <div class="wa-chat-sub">${num ? esc(num) : '<span style="color:var(--color-danger)">No valid phone number on file — add one on the lead before messaging.</span>'}</div>
      <div id="wa-window-banner" class="wa-window-banner" style="display:none"></div>
      <div class="ct-wa-thread" id="wa-thread"><div class="empty-state"><p>Loading messages…</p></div></div>
      <div id="wa-tpl-panel" class="wa-tpl-panel" style="display:none"></div>
      <div id="wa-fill-panel" class="wa-tpl-panel" style="display:none"></div>
      <div class="ct-compose">
        <button class="btn btn-outline" title="Send a template" onclick="waShowTemplatePicker()" ${num ? '' : 'disabled'}>Templates</button>
        <textarea class="form-control ml-grow" id="wa-input" rows="1" placeholder="${num ? 'Type a message… Shift+Enter for a new line or bullet' : 'No phone number on this lead'}" ${num ? '' : 'disabled'} oninput="mlAutoGrow(this)" onkeydown="if(mlListContinue(event))return;if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();waSendMessage()}"></textarea>
        <button class="btn btn-primary wa-green-btn" onclick="waSendMessage()" ${num ? '' : 'disabled'}>Send</button>
      </div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Close</button>`,
    onMount: async () => {
      crmWidenModal(560);
      await waRefreshThread();
      if (num) {
        crmApiGet('whatsapp.php?action=check_window&phone=' + encodeURIComponent(num))
          .then(d => { if (d && d.success) { CrmMod.wa.insideWindow = d.inside_window; waRenderWindowBanner(); } }).catch(() => {});
      }
      if (!CrmMod.cache.waContentTemplates) {
        crmApiGet('whatsapp.php?action=content_templates')
          .then(d => { CrmMod.cache.waContentTemplates = d; CrmMod.wa.contentTemplates = d.data || []; }).catch(() => {});
      }
    },
  });
}
function waRenderWindowBanner() {
  const b = document.getElementById('wa-window-banner');
  if (!b) return;
  const w = CrmMod.wa.insideWindow;
  if (w === true) { b.style.display = 'flex'; b.className = 'wa-window-banner wa-window-open'; b.textContent = 'Free-form messages allowed (contact replied within 24h).'; }
  else if (w === false) { b.style.display = 'flex'; b.className = 'wa-window-banner wa-window-closed'; b.textContent = 'Outside 24h window — use a template to initiate the conversation.'; }
  else { b.style.display = 'none'; }
}
function waRenderMsg(m) {
  const out = String(m.direction).toLowerCase() === 'outbound';
  let media = '';
  if (m.media_url) {
    const u = esc(m.media_url), mt = (m.media_type || '').toLowerCase();
    if (mt.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(m.media_url)) media = `<a href="${u}" target="_blank" rel="noopener"><img class="ct-wa-media-img" src="${u}" alt="" loading="lazy"></a>`;
    else if (mt.startsWith('video/') || /\.(mp4|3gp)$/i.test(m.media_url)) media = `<video class="ct-wa-media-img" src="${u}" controls preload="metadata"></video>`;
    else if (mt.startsWith('audio/') || /\.(mp3|ogg|amr|aac)$/i.test(m.media_url)) media = `<audio src="${u}" controls preload="metadata" style="max-width:220px;display:block;margin-bottom:4px"></audio>`;
    else media = `<a class="ct-wa-media-doc" href="${u}" target="_blank" rel="noopener">${CT_IC.doc}<span>${esc(m.media_url.split('/').pop() || 'Document')}</span></a>`;
  }
  const body = (m.message_body || '').trim();
  return `<div class="ct-wa-msg ${out ? 'out' : 'in'}"><div class="ct-wa-bubble">${media}${body ? esc(body) : ''}<span class="ct-wa-time">${waMsgTime(m.created_at || m.sent_at)}${out ? ' ' + waStatusIcon(m.status) : ''}</span></div></div>`;
}
async function waRefreshThread() {
  const th = document.getElementById('wa-thread');
  if (!th) return;
  const w = CrmMod.wa;
  const params = w.leadId ? ('lead_id=' + w.leadId) : ('to_number=' + encodeURIComponent(w.toNumber || ''));
  try {
    const d = await crmApiGet('whatsapp.php?action=chat_history&' + params);
    const msgs = d.data || [];
    th.innerHTML = msgs.length ? msgs.map(waRenderMsg).join('') : `<div class="empty-state"><p>No messages yet. Send a WhatsApp message to start the conversation.</p></div>`;
    th.scrollTop = th.scrollHeight;
  } catch (e) { th.innerHTML = `<div class="empty-state"><p>Failed to load messages.</p></div>`; }
}
async function waSendMessage() {
  const inp = document.getElementById('wa-input');
  const body = (inp ? inp.value : '').trim();
  if (!body) return;
  const w = CrmMod.wa;
  if (!crmIsPhone(w.toNumber)) { toast('This lead has no valid phone number. Add one on the lead record first.', 'error'); return; }
  mlReset(inp);
  try {
    await crmApiPost('whatsapp.php?action=send', { to_number: w.toNumber, body, lead_id: w.leadId || 0 });
    crmModInvalidate('waChats'); crmModInvalidate('waStats');
    await waRefreshThread();
    if (w.toNumber) crmApiGet('whatsapp.php?action=check_window&phone=' + encodeURIComponent(w.toNumber)).then(d => { if (d && d.success) { CrmMod.wa.insideWindow = d.inside_window; waRenderWindowBanner(); } }).catch(() => {});
  } catch (e) { toast(e.message, 'error'); waRefreshThread(); }
}

// --- Template picker + variable fill (Twilio Content Templates) ---
function waShowTemplatePicker() {
  const p = document.getElementById('wa-tpl-panel');
  if (!p) return;
  if (p.style.display !== 'none') { p.style.display = 'none'; return; }
  document.getElementById('wa-fill-panel').style.display = 'none';
  const tpls = CrmMod.wa.contentTemplates || [];
  const approved = tpls.filter(t => t.approval_status === 'approved');
  const pending = tpls.filter(t => t.approval_status === 'pending' || t.approval_status === 'received');
  let h = `<div class="wa-tpl-head"><span>WhatsApp Templates</span><button class="ct-cond-x" onclick="document.getElementById('wa-tpl-panel').style.display='none'">&times;</button></div>`;
  if (!approved.length && !pending.length) {
    h += `<div style="padding:14px;font-size:12px;color:var(--color-text-secondary)">No approved templates available yet. Templates are pending Meta approval (usually minutes to a few hours).</div>`;
  } else {
    approved.forEach(t => {
      const vc = Object.keys(t.variables || {}).length;
      h += `<div class="wa-tpl-item" onclick="waFillTemplate('${waEscJs(t.content_sid)}')"><div class="wa-tpl-name">${esc(t.friendly_name)} ${crmBadge('Approved', 'badge-green')}</div><div class="wa-tpl-cat">${esc(t.category || 'UTILITY')}${vc ? ' · ' + vc + ' variable' + (vc > 1 ? 's' : '') : ''}</div><div class="wa-tpl-body">${esc((t.body || '').slice(0, 120))}</div></div>`;
    });
    pending.forEach(t => {
      h += `<div class="wa-tpl-item disabled"><div class="wa-tpl-name">${esc(t.friendly_name)} ${crmBadge('Pending', 'badge-yellow')}</div><div class="wa-tpl-body">${esc((t.body || '').slice(0, 120))}</div></div>`;
    });
  }
  p.innerHTML = h; p.style.display = 'block';
}
function waFillTemplate(sid) {
  const tpl = (CrmMod.wa.contentTemplates || []).find(t => t.content_sid === sid);
  if (!tpl) return;
  document.getElementById('wa-tpl-panel').style.display = 'none';
  const vars = tpl.variables || {}, keys = Object.keys(vars);
  const fp = document.getElementById('wa-fill-panel');
  let h = `<div class="wa-tpl-head"><span>Send Template: ${esc(tpl.friendly_name)}</span><button class="ct-cond-x" onclick="document.getElementById('wa-fill-panel').style.display='none'">&times;</button></div><div style="padding:12px"><div class="wa-tpl-preview-box" id="wa-tpl-preview">${esc(tpl.body || '')}</div>`;
  keys.forEach(k => {
    h += `<div class="wa-var-row"><label>{{${esc(k)}}} — ${esc(vars[k] || ('Variable ' + k))}</label><input class="form-control wa-var-input" data-k="${esc(k)}" placeholder="${esc(vars[k] || '')}" oninput="waUpdateTplPreview('${waEscJs(sid)}')"></div>`;
  });
  h += `<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px"><button class="btn btn-outline btn-sm" onclick="document.getElementById('wa-fill-panel').style.display='none'">Cancel</button><button class="btn btn-primary btn-sm wa-green-btn" onclick="waSendContentTemplate('${waEscJs(sid)}')">Send Template</button></div></div>`;
  fp.innerHTML = h; fp.style.display = 'block';
  fp.querySelectorAll('.wa-var-input').forEach(inp => {
    const desc = (vars[inp.dataset.k] || '').toLowerCase();
    if (/name/.test(desc) && CrmMod.wa.name) inp.value = /first/.test(desc) ? (CrmMod.wa.name.split(' ')[0] || '') : CrmMod.wa.name;
  });
  waUpdateTplPreview(sid);
}
function waUpdateTplPreview(sid) {
  const tpl = (CrmMod.wa.contentTemplates || []).find(t => t.content_sid === sid);
  if (!tpl) return;
  let body = esc(tpl.body || '');
  document.querySelectorAll('#wa-fill-panel .wa-var-input').forEach(inp => {
    const v = inp.value.trim();
    if (v) body = body.replace(new RegExp('\\{\\{' + inp.dataset.k + '\\}\\}', 'g'), '<strong style="color:#1a7f37">' + esc(v) + '</strong>');
  });
  const el = document.getElementById('wa-tpl-preview');
  if (el) el.innerHTML = body;
}
async function waSendContentTemplate(sid) {
  const tpl = (CrmMod.wa.contentTemplates || []).find(t => t.content_sid === sid);
  if (!tpl) return;
  const variables = {}; let ok = true;
  document.querySelectorAll('#wa-fill-panel .wa-var-input').forEach(inp => {
    const v = inp.value.trim();
    if (!v) { ok = false; inp.style.borderColor = 'var(--color-danger)'; } else { inp.style.borderColor = ''; variables[inp.dataset.k] = v; }
  });
  if (!ok) { toast('Please fill in all template variables', 'error'); return; }
  const w = CrmMod.wa;
  if (!crmIsPhone(w.toNumber)) { toast('This lead has no valid phone number. Add one on the lead record first.', 'error'); return; }
  try {
    await crmApiPost('whatsapp.php?action=send_content_template', { content_sid: sid, to_number: w.toNumber, lead_id: w.leadId || 0, variables });
    document.getElementById('wa-fill-panel').style.display = 'none';
    crmModInvalidate('waChats'); crmModInvalidate('waStats'); toast('Template message sent', 'success');
    await waRefreshThread();
  } catch (e) { toast(e.message, 'error'); }
}

// --- Templates management tab ---
async function waRenderTemplatesTab() {
  let d = CrmMod.cache.waContentTemplates;
  if (!d) { d = await crmApiGet('whatsapp.php?action=content_templates'); CrmMod.cache.waContentTemplates = d; }
  if (!d.success) return `<div class="card"><div class="card-body empty-state"><h3>Templates unavailable</h3><p>${esc(d.message || 'Could not load templates from Twilio.')}</p></div></div>`;
  const tpls = d.data || [];
  const info = `<div class="card wa-info-card"><div class="card-body"><strong>About WhatsApp Message Templates</strong><p class="ct-secline" style="margin-top:6px">WhatsApp requires pre-approved templates for business-initiated messages (outside the 24h window). Templates are submitted to Meta for approval — usually minutes to a few hours. Once approved they can be used to start conversations with leads.</p></div></div>`;
  const stMap = { approved: ['Approved', 'badge-green'], pending: ['Pending', 'badge-yellow'], received: ['Pending', 'badge-yellow'], rejected: ['Rejected', 'badge-red'] };
  const rows = tpls.map(t => {
    const stv = stMap[t.approval_status] || [t.approval_status || 'Unknown', 'badge-gray'];
    const vk = Object.keys(t.variables || {});
    const vt = vk.length ? vk.map(k => `{{${esc(k)}}}=${esc(t.variables[k] || '')}`).join(', ') : '<span class="ct-secline">None</span>';
    const rej = (t.approval_status === 'rejected' && t.rejection_reason) ? `<div style="color:var(--color-danger);font-size:11px">${esc(t.rejection_reason)}</div>` : '';
    return `<tr>
      <td><strong>${esc(t.friendly_name)}</strong><div class="ct-secline" style="font-size:10px">${esc(t.content_sid)}</div></td>
      <td>${crmBadge((t.language || 'en').toUpperCase(), 'badge-blue')}</td>
      <td>${crmBadge(t.category || 'UTILITY', 'badge-green')}</td>
      <td style="max-width:280px"><small>${esc((t.body || '').slice(0, 100))}${(t.body || '').length > 100 ? '…' : ''}</small></td>
      <td style="font-size:11px">${vt}</td>
      <td>${crmBadge(stv[0], stv[1])}${rej}</td>
      <td>${t.created_at ? crmModDate(t.created_at) : '—'}</td>
      <td><button class="btn btn-outline btn-sm btn-danger" onclick="waDeleteTemplate('${waEscJs(t.content_sid)}','${waEscJs(t.friendly_name)}')">Delete</button></td>
    </tr>`;
  }).join('');
  const head = `<div class="card"><div class="card-header"><h3 class="card-title">WhatsApp Templates</h3><div class="card-header-actions"><button class="btn btn-primary btn-sm wa-green-btn" onclick="waCreateTemplateForm()">+ Create Template</button></div></div>`;
  const table = `<div class="table-container"><table class="table"><thead><tr><th>Template</th><th>Lang</th><th>Category</th><th>Body</th><th>Variables</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>${rows || `<tr><td colspan="8" class="ct-empty-cell">No templates created yet.</td></tr>`}</tbody></table></div></div>`;
  return info + head + table;
}
function waCreateTemplateForm() {
  Modal.open({
    title: 'Create WhatsApp Template',
    body: crmModalBody(`
      <div class="form-group"><label class="form-label">Template Name</label><input class="form-control" id="wct-name" placeholder="e.g. welcome_message (lowercase, underscores)"><div class="form-hint">Lowercase letters, numbers, and underscores only.</div></div>
      <div class="ct-two">
        <div class="form-group"><label class="form-label">Category</label><select class="form-control" id="wct-cat"><option value="UTILITY">Utility (transactional, updates)</option><option value="MARKETING">Marketing (promotions, offers)</option></select></div>
        <div class="form-group"><label class="form-label">Language</label><select class="form-control" id="wct-lang">${['en', 'ar', 'es', 'fr', 'de', 'pt', 'it', 'tr', 'nl', 'ja', 'zh_CN'].map(l => `<option value="${l}">${l}</option>`).join('')}</select></div>
      </div>
      <div class="form-group"><label class="form-label">Template Body</label><textarea class="form-control" id="wct-body" rows="5" placeholder="Hello {{1}}, this is {{2}} from Victory Genomics. Would you be available {{3}}? Please reply to continue." oninput="waTplLivePreview()"></textarea><div class="form-hint">Use {{1}}, {{2}}, … for variables. Must start and end with static text.</div></div>
      <div id="wct-vars" style="display:none"><label class="form-label">Variable Descriptions</label><div id="wct-vars-list"></div></div>
      <div class="form-group"><label class="form-label">Preview</label><div class="wa-tpl-preview-box" id="wct-preview"><em class="ct-secline">Start typing your template body above…</em></div></div>
      <div class="form-error" id="wct-err" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary wa-green-btn" onclick="waSubmitTemplate()">Create &amp; Submit for Approval</button>`,
    onMount: () => crmWidenModal(600),
  });
}
function waTplLivePreview() {
  const body = document.getElementById('wct-body').value;
  const prev = document.getElementById('wct-preview'), vc = document.getElementById('wct-vars'), vl = document.getElementById('wct-vars-list');
  if (!body.trim()) { prev.innerHTML = '<em class="ct-secline">Start typing your template body above…</em>'; vc.style.display = 'none'; return; }
  const matches = body.match(/\{\{(\d+)\}\}/g) || [];
  const uniq = [];
  matches.forEach(m => { if (uniq.indexOf(m) === -1) uniq.push(m); });
  if (uniq.length) {
    const existing = {};
    vl.querySelectorAll('input').forEach(i => { existing[i.dataset.k] = i.value; });
    vc.style.display = 'block';
    vl.innerHTML = uniq.map(v => { const k = v.replace(/[{}]/g, ''); return `<div class="wa-var-row"><label>${esc(v)}</label><input class="form-control" data-k="${esc(k)}" value="${esc(existing[k] || '')}" placeholder="Description (e.g. Contact name, Rep name, Meeting time)"></div>`; }).join('');
  } else { vc.style.display = 'none'; }
  let p = esc(body);
  uniq.forEach(v => { p = p.split(v).join('<span style="background:#25D366;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600">' + esc(v) + '</span>'); });
  prev.innerHTML = p;
}
async function waSubmitTemplate() {
  const err = document.getElementById('wct-err');
  const g = i => document.getElementById(i).value;
  const show = m => { err.textContent = m; err.style.display = 'block'; };
  const name = g('wct-name').trim(), body = g('wct-body').trim();
  if (!name) return show('Template name is required.');
  if (!body) return show('Template body is required.');
  if (/^\{\{/.test(body)) return show('Template body must start with static text, not a variable.');
  if (/\}\}$/.test(body)) return show('Template body must end with static text, not a variable.');
  const variables = {}; let ok = true;
  document.querySelectorAll('#wct-vars-list input').forEach(i => {
    const d = i.value.trim();
    if (!d) { ok = false; i.style.borderColor = 'var(--color-danger)'; } else { i.style.borderColor = ''; variables[i.dataset.k] = d; }
  });
  if (!ok) return show('Please describe all template variables.');
  try {
    const r = await crmApiPost('whatsapp.php?action=create_content_template', { name, category: g('wct-cat'), language: g('wct-lang'), body, variables });
    crmModInvalidate('waContentTemplates'); Modal.close();
    toast('Template created and submitted for Meta approval' + (r.approval_status ? ' (' + r.approval_status + ')' : ''), 'success');
    render();
  } catch (e) { show(e.message); }
}
function waDeleteTemplate(sid, name) {
  appConfirm('Delete template "' + name + '"? This permanently removes it from Twilio and Meta/WhatsApp and cannot be undone.', async () => {
    try { await crmApiPost('whatsapp.php?action=delete_content_template', { content_sid: sid }); crmModInvalidate('waContentTemplates'); toast('Template deleted', 'success'); render(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// --- Unmatched inbound (managers) ---
async function waRenderUnmatchedTab() {
  let d;
  try { d = await crmApiGet('whatsapp.php?action=unmatched_messages'); }
  catch (e) { return `<div class="card"><div class="card-body empty-state"><h3>Unmatched inbox</h3><p>${esc(e.message)}</p><p class="ct-secline">This view is available to Sales Managers only.</p></div></div>`; }
  if (!d.success) return `<div class="card"><div class="card-body empty-state"><h3>Unmatched inbox</h3><p>${esc(d.message || 'Unavailable.')}</p></div></div>`;
  const senders = d.data || [];
  CrmMod.waUnmatched = senders;
  const info = `<div class="card wa-info-card orange"><div class="card-body"><strong>Unmatched Inbound Messages</strong><p class="ct-secline" style="margin-top:6px">WhatsApp messages received from numbers that don't match any lead. View the conversation, reply to qualify them, then link to an existing lead or create a new lead.</p></div></div>`;
  if (!senders.length) return info + `<div class="card"><div class="card-body empty-state"><h3>All messages are matched</h3><p>No unmatched inbound WhatsApp messages. Great job!</p></div></div>`;
  const cards = senders.map((m, idx) => {
    const phone = m.from_number || '', pname = m.profile_name || '', disp = pname || phone, count = m.thread_count || 1;
    const av = pname ? esc(waInitials(pname)) : CT_IC.users;
    return `<div class="wa-unmatched-card">
      <div class="wa-unmatched-head" onclick="waToggleUnmatched(${idx},'${waEscJs(phone)}')">
        <div class="wa-avatar ${pname ? '' : 'orange'}">${av}</div>
        <div class="wa-inbox-main">
          <div class="wa-inbox-row"><span class="wa-inbox-name">${esc(disp)}${pname ? ` <span class="ct-secline" style="font-weight:400">${esc(phone)}</span>` : ''}</span><small class="ct-secline">${waTimeAgo(m.created_at)}</small></div>
          <div class="wa-inbox-last">${esc((m.message_body || '').slice(0, 80))}</div>
        </div>
        <div class="wa-inbox-meta">${crmBadge(count + ' msg' + (count > 1 ? 's' : ''), 'badge-yellow')}</div>
      </div>
      <div id="wa-um-body-${idx}" style="display:none">
        <div class="wa-unmatched-thread" id="wa-um-thread-${idx}"></div>
        <div class="ct-compose" style="padding:10px 12px 0"><textarea class="form-control ml-grow" id="wa-um-reply-${idx}" rows="1" placeholder="Type a reply… Shift+Enter for a new line" oninput="mlAutoGrow(this)" onkeydown="if(mlListContinue(event))return;if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();waSendUnmatchedReply(${idx},'${waEscJs(phone)}')}"></textarea><button class="btn btn-primary wa-green-btn" onclick="waSendUnmatchedReply(${idx},'${waEscJs(phone)}')">Send</button></div>
        <div class="wa-unmatched-actions">
          <button class="btn btn-outline btn-sm" onclick="waLinkLeadModal('${waEscJs(phone)}')">Link to Lead</button>
          <button class="btn btn-primary btn-sm wa-green-btn" onclick="waCreateLeadModal('${waEscJs(phone)}')">Create Lead</button>
          <button class="btn btn-outline btn-sm" style="margin-left:auto" onclick="waOpenChat(0,'${waEscJs(phone)}','${waEscJs(disp)}')">Full Chat</button>
        </div>
      </div>
    </div>`;
  }).join('');
  return info + cards;
}
function waToggleUnmatched(idx, phone) {
  const b = document.getElementById('wa-um-body-' + idx);
  if (!b) return;
  if (b.style.display !== 'none') { b.style.display = 'none'; return; }
  b.style.display = 'block';
  waLoadUnmatchedThread(idx, phone);
}
function waLoadUnmatchedThread(idx, phone) {
  const th = document.getElementById('wa-um-thread-' + idx);
  if (!th) return;
  th.innerHTML = '<div class="empty-state"><p>Loading…</p></div>';
  crmApiGet('whatsapp.php?action=unmatched_chat_history&phone=' + encodeURIComponent(phone)).then(d => {
    const msgs = d.data || [];
    th.innerHTML = msgs.length ? msgs.map(m => {
      const out = String(m.direction).toLowerCase() === 'outbound';
      const sender = (out && m.user_name) ? `<div style="font-size:10px;font-weight:700;color:#1a7f37">${esc(m.user_name)}</div>`
        : (!out && m.profile_name) ? `<div style="font-size:10px;font-weight:700;color:#b25e00">${esc(m.profile_name)}</div>` : '';
      return `<div class="ct-wa-msg ${out ? 'out' : 'in'}"><div class="ct-wa-bubble">${sender}${esc(m.message_body || '')}<span class="ct-wa-time">${waMsgTime(m.created_at)}${out ? ' ' + waStatusIcon(m.status) : ''}</span></div></div>`;
    }).join('') : '<div class="empty-state"><p>No messages found.</p></div>';
    th.scrollTop = th.scrollHeight;
  }).catch(() => { th.innerHTML = '<div class="empty-state"><p>Failed to load messages.</p></div>'; });
}
async function waSendUnmatchedReply(idx, phone) {
  const inp = document.getElementById('wa-um-reply-' + idx);
  const body = (inp ? inp.value : '').trim();
  if (!body) return;
  mlReset(inp);
  try {
    await crmApiPost('whatsapp.php?action=send', { to_number: phone, body, lead_id: 0 });
    toast('Reply sent', 'success');
    waLoadUnmatchedThread(idx, phone);
  } catch (e) { toast(e.message, 'error'); }
}
function waLinkLeadModal(phone) {
  CrmMod.waLink = { phone, leadId: 0 };
  Modal.open({
    title: 'Link to Existing Lead',
    body: crmModalBody(`
      <p class="ct-secline" style="margin-bottom:12px">Messages from <strong>${esc(phone)}</strong> will be linked to the selected lead.</p>
      <div class="form-group"><label class="form-label">Search Lead</label><input class="form-control" id="wa-link-search" placeholder="Type lead name or company…" oninput="waSearchLeadsForLink(this.value)" autocomplete="off"></div>
      <div id="wa-link-results" style="max-height:200px;overflow-y:auto;border:1px solid var(--color-border);border-radius:8px;display:none"></div>
      <div id="wa-link-selected" class="ct-cfgbox" style="display:none;margin-top:8px"></div>
      <div class="form-error" id="wa-link-err" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary" style="background:#ff9500;border-color:#ff9500" onclick="waSubmitLinkToLead()">Link Messages</button>`,
  });
}
let _waLinkTimer = null;
function waSearchLeadsForLink(q) {
  clearTimeout(_waLinkTimer);
  const res = document.getElementById('wa-link-results');
  if (q.trim().length < 2) { res.style.display = 'none'; return; }
  _waLinkTimer = setTimeout(async () => {
    try {
      const d = await crmApiGet('leads.php?action=search&q=' + encodeURIComponent(q));
      const leads = d.data || [];
      if (!leads.length) { res.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--color-text-secondary)">No leads found</div>'; res.style.display = 'block'; return; }
      res.innerHTML = leads.map(l => `<div style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--color-border);font-size:13px" onclick="waSelectLinkLead(${l.lead_id},'${waEscJs(l.contact_person || '')}','${waEscJs(l.company_name || '')}')"><strong>${esc(l.contact_person || 'N/A')}</strong> — ${esc(l.company_name || '')}<div class="ct-secline">${esc(l.phone || l.mobile || '')}</div></div>`).join('');
      res.style.display = 'block';
    } catch (e) { res.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--color-danger)">Search failed</div>'; res.style.display = 'block'; }
  }, 300);
}
function waSelectLinkLead(id, name, company) {
  CrmMod.waLink.leadId = id;
  const s = document.getElementById('wa-link-selected');
  s.style.display = 'block';
  s.innerHTML = `Selected: <strong>${esc(name)}</strong>${company ? ' (' + esc(company) + ')' : ''}`;
  document.getElementById('wa-link-results').style.display = 'none';
  document.getElementById('wa-link-search').value = name;
}
async function waSubmitLinkToLead() {
  const err = document.getElementById('wa-link-err');
  const { phone, leadId } = CrmMod.waLink || {};
  if (!leadId) { err.textContent = 'Please select a lead.'; err.style.display = 'block'; return; }
  try {
    const r = await crmApiPost('whatsapp.php?action=link_to_lead', { from_number: phone, lead_id: leadId });
    crmModInvalidate('waChats'); Modal.close(); toast(r.message || 'Messages linked', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}
function waCreateLeadModal(phone) {
  let prefill = '';
  const m = (CrmMod.waUnmatched || []).find(x => x.from_number === phone);
  if (m && m.profile_name) prefill = m.profile_name;
  Modal.open({
    title: 'Create New Lead',
    body: crmModalBody(`
      <p class="ct-secline" style="margin-bottom:12px">Create a new lead from this unmatched WhatsApp sender. All messages from this number will be automatically linked.</p>
      <div class="form-group"><label class="form-label">Phone Number</label><input class="form-control" id="wa-cl-phone" value="${esc(phone)}" readonly style="background:var(--color-bg)"></div>
      <div class="form-group"><label class="form-label">Contact Name <span style="color:var(--color-danger)">*</span></label><input class="form-control" id="wa-cl-name" value="${esc(prefill)}" placeholder="e.g. John Smith"></div>
      <div class="form-group"><label class="form-label">Company Name</label><input class="form-control" id="wa-cl-company" placeholder="e.g. Acme Corp (optional)"></div>
      <div class="form-error" id="wa-cl-err" style="display:none"></div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Cancel</button><button class="btn-primary wa-green-btn" onclick="waSubmitCreateLead('${waEscJs(phone)}')">Create Lead</button>`,
  });
}
async function waSubmitCreateLead(phone) {
  const err = document.getElementById('wa-cl-err');
  const name = document.getElementById('wa-cl-name').value.trim();
  const company = document.getElementById('wa-cl-company').value.trim();
  if (!name) { err.textContent = 'Contact name is required.'; err.style.display = 'block'; return; }
  try {
    const r = await crmApiPost('whatsapp.php?action=create_lead_from_message', { from_number: phone, contact_person: name, company_name: company });
    crmModInvalidate('waChats'); Modal.close(); toast(r.message || 'Lead created', 'success'); render();
  } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
}

// --- VoIP softphone (Twilio Voice SDK 2.x) ---
let _twilioSdkPromise = null;
function voipLoadSdk() {
  if (window.Twilio && window.Twilio.Device) return Promise.resolve();
  if (_twilioSdkPromise) return _twilioSdkPromise;
  _twilioSdkPromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/@twilio/voice-sdk@2.13.0/dist/twilio.min.js';
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Failed to load the Twilio Voice SDK.'));
    document.head.appendChild(s);
  });
  return _twilioSdkPromise;
}
function voipStatus(text, cls) {
  const el = document.getElementById('sp-status');
  if (el) el.innerHTML = crmBadge(text, cls || 'badge-gray');
}
async function voipEnsureDevice() {
  const v = CrmMod.voip;
  if (v.device && v.ready) return v.device;
  await voipLoadSdk();
  const t = await crmApiGet('voip.php?action=token');
  if (!t.success) throw new Error(t.message || 'Could not get a voice token.');
  v.identity = t.identity;
  if (v.device) { try { v.device.updateToken(t.token); } catch (e) {} return v.device; }
  const device = new Twilio.Device(t.token, {
    codecPreferences: [Twilio.Call.Codec.Opus, Twilio.Call.Codec.PCMU],
    logLevel: 'warn', closeProtection: true, edge: 'ashburn',
  });
  device.on('registered', () => { v.ready = true; voipStatus('Ready', 'badge-green'); });
  device.on('error', (e) => { voipStatus('Error: ' + (e?.message || e), 'badge-red'); });
  device.on('incoming', (call) => { try { call.reject(); } catch (e) {} });
  device.on('tokenWillExpire', async () => { try { const nt = await crmApiGet('voip.php?action=token'); if (nt.success) device.updateToken(nt.token); } catch (e) {} });
  v.device = device;
  await device.register();
  return device;
}
function voipOpenSoftphone(prefill, leadId) {
  ensureCrmModStyles();
  const startNum = crmIsPhone(prefill) ? String(prefill).trim() : '';
  CrmMod.voip.leadId = Number(leadId) || 0;
  Modal.open({
    title: 'Softphone',
    body: crmModalBody(`
      <div class="ct-sp-status" id="sp-status">${crmBadge('Initializing…', 'badge-gray')}</div>
      <div id="sp-idle">
        <input class="form-control ct-sp-num" id="sp-number" value="${esc(startNum)}" placeholder="+1 858 358 5260" onkeydown="if(event.key==='Enter')voipDial()">
        <div class="form-hint" style="text-align:center">Enter a phone number with country code (e.g. +1 for US)</div>
        <div class="ct-dialpad">${['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'].map(k => `<button class="ct-key" onclick="voipKey('${k}')">${k}</button>`).join('')}</div>
        <div style="text-align:center;margin-top:6px"><button class="ct-round call" onclick="voipDial()">${CT_IC.phone}</button></div>
      </div>
      <div id="sp-active" style="display:none">
        <div class="ct-sp-num" id="sp-peer"></div>
        <div class="ct-sp-timer" id="sp-timer">0:00</div>
        <div class="ct-dialpad">${['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'].map(k => `<button class="ct-key" onclick="voipKey('${k}')">${k}</button>`).join('')}</div>
        <div style="display:flex;justify-content:center;gap:20px;margin-top:10px">
          <button class="btn btn-outline" id="sp-mute" onclick="voipMute()">Mute</button>
          <button class="ct-round hang" onclick="voipHangup()">${CT_IC.phone}</button>
        </div>
      </div>
      <div id="sp-post" style="display:none">
        <p style="text-align:center;font-weight:600;margin-bottom:12px">Call ended — log the outcome</p>
        <div class="form-group"><label class="form-label">Outcome</label>
          <select class="form-control" id="sp-outcome"><option value="">— Select —</option>${(typeof CRM_INTERACTION_OUTCOMES !== 'undefined' ? CRM_INTERACTION_OUTCOMES : ['Positive', 'Neutral', 'Negative', 'No Response']).map(o => `<option>${o}</option>`).join('')}</select></div>
        <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" id="sp-notes" rows="3" placeholder="Call notes…"></textarea></div>
      </div>`),
    footer: `<button class="btn-secondary" onclick="Modal.close()">Close</button><button class="btn-primary" id="sp-savelog" style="display:none" onclick="voipSaveLog()">Save Log</button>`,
    onMount: async () => {
      const n = document.getElementById('sp-number');
      if (n && startNum) { n.focus(); try { n.setSelectionRange(n.value.length, n.value.length); } catch (e) {} }
      try { voipStatus('Connecting to Twilio…', 'badge-yellow'); await voipEnsureDevice(); }
      catch (e) { voipStatus(e.message, 'badge-red'); }
    },
  });
}
function voipKey(k) {
  const v = CrmMod.voip;
  if (v.call) { try { v.call.sendDigits(k); } catch (e) {} }
  else { const n = document.getElementById('sp-number'); if (n) n.value += k; }
}
async function voipDial() {
  const num = document.getElementById('sp-number')?.value.trim();
  if (!num) { toast('Enter a number', 'error'); return; }
  if (!crmIsPhone(num)) {
    voipStatus('Invalid number', 'badge-red');
    toast('That is not a valid phone number. Enter it in full international format, e.g. +34 600 123 456.', 'error');
    return;
  }
  try {
    voipStatus('Connecting…', 'badge-yellow');
    await voipEnsureDevice();
    const r = await crmApiPost('voip.php?action=call', { to_number: num, lead_id: CrmMod.voip.leadId || 0 });
    CrmMod.voip.callId = r.call_id;
    const to = r.to_number || num;
    const call = await CrmMod.voip.device.connect({ params: { To: to, call_id: String(r.call_id) } });
    voipBindCall(call, to);
  } catch (e) { voipStatus(e.message, 'badge-red'); toast(e.message, 'error'); }
}
function voipBindCall(call, peer) {
  const v = CrmMod.voip;
  v.call = call; v.muted = false; v.seconds = 0; v._ended = false;
  document.getElementById('sp-idle').style.display = 'none';
  document.getElementById('sp-active').style.display = 'block';
  document.getElementById('sp-peer').textContent = peer || '';
  voipStatus('Ringing…', 'badge-yellow');
  call.on('accept', () => { voipStatus('In call', 'badge-green'); voipStartTimer(); });
  call.on('ringing', () => voipStatus('Ringing…', 'badge-yellow'));
  call.on('disconnect', () => voipEndCall('completed'));
  call.on('cancel', () => voipEndCall('canceled'));
  call.on('reject', () => voipEndCall('rejected'));
  call.on('error', (e) => { toast('Call error: ' + (e?.message || e), 'error'); voipEndCall('failed'); });
}
function voipStartTimer() {
  const v = CrmMod.voip;
  voipStopTimer();
  v.timer = setInterval(() => { v.seconds++; const t = document.getElementById('sp-timer'); if (t) t.textContent = crmDuration(v.seconds); }, 1000);
}
function voipStopTimer() { const v = CrmMod.voip; if (v.timer) { clearInterval(v.timer); v.timer = null; } }
function voipMute() {
  const v = CrmMod.voip; if (!v.call) return;
  v.muted = !v.muted; try { v.call.mute(v.muted); } catch (e) {}
  const b = document.getElementById('sp-mute'); if (b) b.textContent = v.muted ? 'Unmute' : 'Mute';
}
function voipHangup() { const v = CrmMod.voip; if (v.call) { try { v.call.disconnect(); } catch (e) {} } }
async function voipEndCall(reason) {
  const v = CrmMod.voip;
  if (v._ended) return; v._ended = true;
  voipStopTimer();
  const dur = v.seconds;
  const active = document.getElementById('sp-active'), post = document.getElementById('sp-post'), saveBtn = document.getElementById('sp-savelog');
  if (active) active.style.display = 'none';
  if (post) post.style.display = 'block';
  if (saveBtn) saveBtn.style.display = 'inline-flex';
  voipStatus('Call ended (' + crmDuration(dur) + ')', 'badge-gray');
  if (v.callId) { try { await crmApiPost('voip.php?action=end_call', { call_id: v.callId, duration: dur, reason }); } catch (e) {} }
  v.call = null;
  crmModInvalidate('callStats'); crmModInvalidate('callHistory');
}
async function voipSaveLog() {
  const v = CrmMod.voip;
  const outcome = document.getElementById('sp-outcome')?.value || '';
  const notes = document.getElementById('sp-notes')?.value || '';
  try {
    if (v.callId) await crmApiPost('voip.php?action=log_call', { call_id: v.callId, outcome, notes, duration: v.seconds });
    toast('Call logged', 'success');
    Modal.close();
    crmModInvalidate('callStats'); crmModInvalidate('callHistory'); render();
  } catch (e) { toast(e.message, 'error'); }
}

// ======================================================================
//  REPORTS  — native pipeline dashboard (reports.php) + data export center
// ======================================================================
const CRM_EXPORT_SCOPES = [
  ['leads', 'Leads', 'Every lead record with owner, status, and source.'],
  ['interactions', 'Interactions', 'Full activity timeline across all leads.'],
  ['whatsapp', 'WhatsApp', 'All inbound and outbound WhatsApp messages.'],
  ['voip', 'VoIP Calls', 'Call log with duration, outcome, and agent.'],
  ['all', 'Everything', 'Complete database bundle (CSV archive or JSON).'],
];
function crmExportData(scope, format) {
  const url = '/crm/api/export.php?format=' + encodeURIComponent(format) + '&scope=' + encodeURIComponent(scope);
  window.open(url, '_blank');
}
function crmExportCenterHtml() {
  const rows = CRM_EXPORT_SCOPES.map(([scope, label, desc]) => `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid rgba(0,0,0,0.06);flex-wrap:wrap;">
      <div style="min-width:200px;">
        <div style="font-weight:600;">${esc(label)}</div>
        <div class="text-muted" style="font-size:12px;">${esc(desc)}</div>
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-sm btn-outline" onclick="crmExportData('${scope}','csv')">CSV</button>
        <button class="btn btn-sm btn-outline" onclick="crmExportData('${scope}','json')">JSON</button>
      </div>
    </div>`).join('');
  return `<p class="text-muted" style="margin:0 0 8px;font-size:13px;">Download CRM data for reporting or backup. Exports respect your access level.</p>${rows}`;
}
async function renderCrmReports() {
  let d = CrmMod.cache.reports;
  if (!d) { d = await crmApiGet('reports.php'); CrmMod.cache.reports = d; }
  const data = d.data || {};
  const t = data.totals || {}, ix = data.interactions || {}, pr = data.proposals || {};
  const total = Number(t.total || 0), won = Number(t.won || 0);
  const winRate = total ? Math.round((won / total) * 100) : 0;
  const it = data.interaction_types || {};
  const team = data.team_performance || [];
  const cards = crmStatRow([
    ['Total Leads', total, CT_IC.users], ['Won', won, CT_IC.check], ['Win Rate', winRate + '%', CT_IC.chart],
    ['Interactions (30d)', Number(ix.last30 || 0), CT_IC.clock], ['Proposals', Number(pr.total || 0), CT_IC.doc],
  ]);
  const intCards = crmStatRow([
    ['Calls', Number(it.calls || 0), CT_IC.phone || CT_IC.clock],
    ['WhatsApp', Number(it.whatsapp || 0), CT_IC.send || CT_IC.mail],
    ['Emails', Number(it.emails || 0), CT_IC.mail || CT_IC.doc],
    ['Meetings', Number(it.meetings || 0), CT_IC.users],
    ['Total updates', Number(ix.total || 0), CT_IC.clock],
  ]);
  const card = (title, body) => `<div class="card"><div class="card-header"><h3 class="card-title">${esc(title)}</h3></div><div class="card-body">${body}</div></div>`;
  const teamRows = team.length ? team.map(r => {
    const wr = Number(r.win_rate || 0);
    return `<tr>
      <td style="font-weight:600">${esc(r.full_name || '—')}</td>
      <td style="color:var(--color-text-muted)">${esc(r.role || '')}</td>
      <td style="text-align:right">${Number(r.assigned_leads || 0)}</td>
      <td style="text-align:right">${Number(r.interactions || 0)}</td>
      <td style="text-align:right;font-weight:600">${Number(r.deals_won || 0)}</td>
      <td style="text-align:right">
        <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end">
          <div style="flex:0 0 64px;height:6px;background:var(--color-surface-2,#eee);border-radius:99px;overflow:hidden"><div style="width:${Math.min(100, wr)}%;height:100%;background:var(--color-success,#2e7d5b)"></div></div>
          <span style="min-width:40px;text-align:right">${wr}%</span>
        </div>
      </td>
    </tr>`;
  }).join('') : `<tr><td colspan="6" style="text-align:center;color:var(--color-text-muted);padding:18px">No team activity yet.</td></tr>`;
  const teamTable = `<div style="overflow-x:auto"><table class="crm-table" style="width:100%;border-collapse:collapse">
    <thead><tr>
      <th style="text-align:left;padding:8px 10px">Sales agent</th><th style="text-align:left;padding:8px 10px">Role</th>
      <th style="text-align:right;padding:8px 10px">Leads</th><th style="text-align:right;padding:8px 10px">Interactions</th>
      <th style="text-align:right;padding:8px 10px">Won</th><th style="text-align:right;padding:8px 10px">Win rate</th>
    </tr></thead><tbody>${teamRows}</tbody></table></div>`;
  return `<div class="crm-native fade-in">
    ${crmModHead('Reports & Analytics', 'Pipeline performance across the whole team.', '')}
    ${cards}
    <div style="margin-top:16px">${card('Team Performance — by sales agent', teamTable)}</div>
    <div style="margin-top:16px">${intCards}</div>
    <div style="margin-top:16px">${card('Leads by Status', crmBars(data.by_status || [], 'var(--color-accent)'))}</div>
    <div class="grid-2" style="margin-top:16px">
      ${card('Leads by Type', crmBars(data.by_type || [], 'var(--color-accent-2, var(--color-accent))'))}
      ${card('By Region', crmBars(data.by_region || [], 'var(--color-success)'))}
    </div>
    <div style="margin-top:16px">${card('New Leads (6 months)', crmBars(data.by_month || [], 'var(--color-warning)'))}</div>
  </div>`;
}
