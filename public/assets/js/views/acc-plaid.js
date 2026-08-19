// VGo — Plaid bank feed (Accounting → Banking → Live feed)
//
// The Plaid Link script is loaded on demand rather than in index.php: it is a
// third-party script most sessions never need, and pulling it on every page
// load would put cdn.plaid.com in the critical path of the whole app.

Object.assign(API, {
  plaidStatus:      () => API.req('/acc/plaid/status'),
  plaidSaveConfig:  (b) => API.req('/acc/plaid/config', { method: 'POST', body: JSON.stringify(b) }),
  plaidLinkToken:   (connectionId) => API.req('/acc/plaid/link-token', { method: 'POST', body: JSON.stringify({ connection_id: connectionId || 0 }) }),
  plaidMapAccount:  (id, b) => API.req('/acc/plaid/accounts/' + id, { method: 'POST', body: JSON.stringify(b) }),
  plaidSync:        (id) => API.req('/acc/plaid/connections/' + id + '/sync', { method: 'POST', body: '{}' }),
  plaidDisconnect:  (id) => API.req('/acc/plaid/connections/' + id, { method: 'DELETE' }),
});

const PLAID_TOKEN_KEY = 'vgo_plaid_link_token';
let _plaidState = null;

function plaidScript() {
  if (window.Plaid) return Promise.resolve();
  if (window._plaidScriptPromise) return window._plaidScriptPromise;
  window._plaidScriptPromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
    s.onload = resolve;
    s.onerror = () => reject(new Error('Could not load Plaid. Check the connection and try again.'));
    document.head.appendChild(s);
  });
  return window._plaidScriptPromise;
}

async function accBankingFeed() {
  if (!_plaidState) {
    try { _plaidState = await API.plaidStatus(); }
    catch (e) { return `<div class="acc-card"><div class="acc-empty">Could not load the bank feed: ${esc(e.message)}</div></div>`; }
  }
  const s = _plaidState;
  if (!s.plaid.configured) return plaidSetupCard(s);
  return plaidConnectionsCard(s);
}

function plaidEnvPill(env) {
  const live = env === 'production';
  return `<span class="plaid-pill ${live ? 'is-live' : 'is-test'}">${live ? 'Live' : 'Sandbox'}</span>`;
}

function plaidSetupCard(s) {
  return `
  <div class="acc-card">
    <div class="acc-card-head"><h3>Connect a bank automatically</h3>${plaidEnvPill(s.plaid.env)}</div>
    <div class="acc-empty" style="text-align:left">
      <p style="margin:0 0 12px">Plaid pulls transactions straight from the bank, so statements stop being a monthly chore.
      Nothing posts to the ledger on its own — lines arrive in Bank Review exactly like an imported statement.</p>
      <p style="margin:0 0 16px;color:var(--muted)">Add your Plaid keys to switch it on. They are stored on the server outside the web root, never in the code repository.</p>
    </div>
    ${plaidConfigForm(s)}
  </div>`;
}

function plaidConfigForm(s) {
  const p = s.plaid;
  return `
  <div class="plaid-form">
    ${accField('Client ID', `<input class="form-input" id="plaid-client" placeholder="${p.has_client ? 'Saved — leave blank to keep' : 'From the Plaid dashboard'}" value="">`)}
    ${accField('Sandbox secret', `<input class="form-input" id="plaid-sandbox" type="password" autocomplete="off" placeholder="${p.has_secret && p.env === 'sandbox' ? 'Saved — leave blank to keep' : 'Sandbox secret'}" value="">`)}
    ${accField('Production secret', `<input class="form-input" id="plaid-prod" type="password" autocomplete="off" placeholder="${p.has_prod_key ? 'Saved — leave blank to keep' : 'Add when Plaid approves production'}" value="">`)}
    ${accField('Environment', `<select class="form-input" id="plaid-env">
        <option value="sandbox" ${p.env === 'sandbox' ? 'selected' : ''}>Sandbox — test data only</option>
        <option value="production" ${p.env === 'production' ? 'selected' : ''}>Production — real bank data</option>
      </select>`)}
    <div class="plaid-hint">
      Redirect URI registered with Plaid: <code>${esc(p.redirect_uri || '—')}</code><br>
      Webhook: <code>${esc(p.webhook_uri || '—')}</code>
      ${p.client_hint ? `<br>Current client ID: <code>${esc(p.client_hint)}</code>` : ''}
    </div>
    <div class="plaid-actions">
      <button class="btn-primary" onclick="plaidSaveConfig()">Save &amp; verify</button>
    </div>
  </div>`;
}

async function plaidSaveConfig() {
  const body = {
    client_id: (document.getElementById('plaid-client')?.value || '').trim(),
    sandbox_secret: (document.getElementById('plaid-sandbox')?.value || '').trim(),
    production_secret: (document.getElementById('plaid-prod')?.value || '').trim(),
    env: document.getElementById('plaid-env')?.value || 'sandbox',
  };
  try {
    const res = await API.plaidSaveConfig(body);
    _plaidState = null;
    if (res.verified) toast('Plaid keys saved and verified', 'success');
    else toast('Saved, but Plaid rejected them: ' + (res.verify_error || 'unknown'), 'error');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function plaidConnectionsCard(s) {
  const conns = s.connections || [];
  const rows = conns.map(c => plaidConnectionHTML(c, s.ledger_accounts || [])).join('');
  return `
  <div class="acc-card">
    <div class="acc-card-head">
      <h3>Live bank feed</h3>
      <div style="display:flex;align-items:center;gap:8px">
        ${plaidEnvPill(s.plaid.env)}
        <button class="btn" onclick="plaidToggleSettings()">Settings</button>
        <button class="btn-primary" onclick="plaidConnect(0)">${I.plus} Connect a bank</button>
      </div>
    </div>
    <div id="plaid-settings" style="display:none">${plaidConfigForm(s)}</div>
    ${rows || '<div class="acc-empty">No bank connected yet. “Connect a bank” opens your bank’s own sign-in page — VGo never sees your banking password.</div>'}
  </div>`;
}

function plaidToggleSettings() {
  const el = document.getElementById('plaid-settings');
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function plaidConnectionHTML(c, ledger) {
  const statusLabel = {
    active: 'Connected', login_required: 'Needs signing in again',
    pending_disconnect: 'Must be reconnected', pending_expiration: 'Consent expiring',
    revoked: 'Access revoked',
  }[c.status] || c.status;

  const warn = c.needs_reauth;
  const opts = (sel) => ['<option value="">— not linked —</option>'].concat(
    ledger.map(l => `<option value="${l.id}" ${Number(sel) === Number(l.id) ? 'selected' : ''}>${esc(l.name)}</option>`)).join('');

  const accts = (c.accounts || []).map(a => `
    <div class="plaid-acct">
      <div class="plaid-acct-id">
        <strong>${esc(a.name || 'Account')}</strong>
        <span>${esc([a.mask ? '••' + a.mask : '', a.subtype || a.type || ''].filter(Boolean).join(' · '))}</span>
      </div>
      <div class="plaid-acct-map">
        <label>Ledger account</label>
        <select class="form-input" onchange="plaidMap(${a.id}, 'account_id', this.value)">${opts(a.account_id)}</select>
      </div>
      <div class="plaid-acct-map">
        <label>Sync from</label>
        <input class="form-input" type="date" value="${esc(a.sync_from || '')}" onchange="plaidMap(${a.id}, 'sync_from', this.value)">
      </div>
      <label class="plaid-acct-on">
        <input type="checkbox" ${a.enabled ? 'checked' : ''} onchange="plaidMap(${a.id}, 'enabled', this.checked ? 1 : 0)"> On
      </label>
    </div>`).join('');

  return `
  <div class="plaid-conn ${warn ? 'is-warn' : ''}">
    <div class="plaid-conn-head">
      <div>
        <div class="plaid-conn-name">${esc(c.institution_name || 'Bank')}</div>
        <div class="plaid-conn-meta">
          <span class="plaid-status ${warn ? 'warn' : 'ok'}">${esc(statusLabel)}</span>
          ${c.last_sync_at ? ` · last sync ${esc(c.last_sync_at)}` : ' · never synced'}
          ${c.consent_expires_at ? ` · consent expires ${esc(String(c.consent_expires_at).slice(0, 10))}` : ''}
        </div>
        ${c.last_sync_status ? `<div class="plaid-conn-note">${esc(c.last_sync_status)}</div>` : ''}
        ${warn && c.error_message ? `<div class="plaid-conn-note warn">${esc(c.error_message)}</div>` : ''}
      </div>
      <div class="plaid-conn-btns">
        ${warn ? `<button class="btn-primary" onclick="plaidConnect(${c.id})">Reconnect</button>`
                : `<button class="btn" onclick="plaidSync(${c.id})">Sync now</button>`}
        <button class="btn" onclick="plaidDisconnect(${c.id})">Disconnect</button>
      </div>
    </div>
    <div class="plaid-accts">${accts || '<div class="acc-empty">No accounts returned yet.</div>'}</div>
    <div class="plaid-hint">Only accounts linked to a ledger account are synced. “Sync from” protects the statements
    you already imported — transactions on or before that date are skipped.</div>
  </div>`;
}

async function plaidMap(id, field, value) {
  try {
    await API.plaidMapAccount(id, { [field]: value });
    _plaidState = null;
    toast('Saved', 'success');
  } catch (e) { toast(e.message, 'error'); _plaidState = null; render(); }
}

/**
 * Open Plaid Link. `connectionId` non-zero means update mode — the re-auth path
 * for consent expiry or a Bank of America migration notice, which keeps the
 * existing item and its sync cursor rather than creating a second connection.
 */
async function plaidConnect(connectionId) {
  try {
    await plaidScript();
    const res = await API.plaidLinkToken(connectionId);
    // The OAuth return lands on a fresh page (/plaid/oauth) that has to
    // re-open Link with this exact token, so it has to survive the round trip.
    try { sessionStorage.setItem(PLAID_TOKEN_KEY, res.link_token); } catch (e) {}

    const handler = Plaid.create({
      token: res.link_token,
      onSuccess: async (publicToken) => {
        try {
          await API.req('/acc/plaid/exchange', { method: 'POST', body: JSON.stringify({ public_token: publicToken }) });
          try { sessionStorage.removeItem(PLAID_TOKEN_KEY); } catch (e) {}
          _plaidState = null; AccState.banking = null;
          toast('Bank connected', 'success');
          render();
        } catch (e) { toast(e.message, 'error'); }
      },
      onExit: (err) => {
        try { sessionStorage.removeItem(PLAID_TOKEN_KEY); } catch (e) {}
        if (err) toast(err.display_message || err.error_message || 'Bank connection cancelled', 'error');
      },
    });
    handler.open();
  } catch (e) { toast(e.message, 'error'); }
}

async function plaidSync(id) {
  toast('Syncing…', 'info');
  try {
    const r = await API.plaidSync(id);
    _plaidState = null; AccState.banking = null;
    if (r.ok === false) toast('Sync failed: ' + (r.error || 'unknown'), 'error');
    else toast(`Added ${r.added || 0}, updated ${r.modified || 0}, skipped ${(r.skipped_duplicate || 0) + (r.skipped_before_cutover || 0)} already-known`, 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function plaidDisconnect(id) {
  if (!confirm('Disconnect this bank? Transactions already imported stay put; new ones stop arriving.')) return;
  try {
    await API.plaidDisconnect(id);
    _plaidState = null; AccState.banking = null;
    toast('Bank disconnected', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function invalidatePlaidCache() { _plaidState = null; }
