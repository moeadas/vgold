// VGo — Settings view (profile, email, notifications, SMTP, AI keys, team, user management)
async function renderSettings() {
  let user = State.user || {};
  let settings = State.notifSettings;
  if (!settings) {
    try { const res = await API.notifSettings(); settings = res.settings; State.notifSettings = settings; } catch(e) { settings = {}; }
  }
  let team = State.teamData;
  if (!team) {
    try { const res = await API.team(); team = res; State.teamData = res; } catch(e) { team = { members: [], invites: [] }; }
  }
  let keys = State.apiKeys;
  if (!keys) {
    try { const res = await API.apiKeys(); keys = res.keys; State.apiKeys = keys; } catch(e) { keys = []; }
  }
  let providers = State.aiProviders;
  if (!providers) {
    try { const res = await API.providers(); providers = res.providers; State.aiProviders = providers; } catch(e) { providers = {}; }
  }
  let smtp = State.smtpSettings;
  if (smtp === undefined) {
    try { const res = await API.smtp(); smtp = res.settings; State.smtpSettings = smtp; } catch(e) { smtp = null; }
  }

  const notifPref = settings.email_notify_pref || 'all';
  const notifRadio = `
    <div class="notif-pref-group">
      <label class="notif-pref-option ${notifPref === 'all' ? 'selected' : ''}">
        <input type="radio" name="email_pref" value="all" ${notifPref === 'all' ? 'checked' : ''} onchange="updateNotifPref('all')">
        <span class="notif-pref-icon">📬</span>
        <div><div class="notif-pref-title">Notify me about everything</div><div class="notif-pref-desc">Task assignments, mentions, chat, completions</div></div>
      </label>
      <label class="notif-pref-option ${notifPref === 'mentions' ? 'selected' : ''}">
        <input type="radio" name="email_pref" value="mentions" ${notifPref === 'mentions' ? 'checked' : ''} onchange="updateNotifPref('mentions')">
        <span class="notif-pref-icon">💬</span>
        <div><div class="notif-pref-title">Only when someone messages or mentions me</div><div class="notif-pref-desc">Just @mentions and direct messages</div></div>
      </label>
      <label class="notif-pref-option ${notifPref === 'none' ? 'selected' : ''}">
        <input type="radio" name="email_pref" value="none" ${notifPref === 'none' ? 'checked' : ''} onchange="updateNotifPref('none')">
        <span class="notif-pref-icon">🔕</span>
        <div><div class="notif-pref-title">Don't notify me at all</div><div class="notif-pref-desc">No email notifications</div></div>
      </label>
    </div>
  `;

  const members = (team.members || []).map(m => {
    const isMs = (m.auth_provider || 'password') === 'microsoft';
    const providerBadge = isMs 
      ? '<span style="font-size:10px;font-weight:700;color:#00A4EF;background:#E8F4FC;border-radius:999px;padding:2px 8px">MS</span>'
      : '<span style="font-size:10px;font-weight:700;color:var(--muted);background:var(--surface-2,#f0ede7);border-radius:999px;padding:2px 8px">PW</span>';
    const rolePill = m.role === 'admin'
      ? '<span class="role-pill" style="color:#25563F;background:#E4EDE7">admin</span>'
      : '<span class="role-pill" style="color:#6E5638;background:#F2E6CF">member</span>';
    const roleToggle = user.role === 'admin' && m.id !== user.id
      ? `<select onchange="changeUserRole(${m.id},this.value)" style="font-size:11px;padding:3px 8px;border:1px solid var(--border);border-radius:6px;background:var(--surface)"><option value="member" ${m.role !== 'admin' ? 'selected' : ''}>Member</option><option value="admin" ${m.role === 'admin' ? 'selected' : ''}>Admin</option></select>`
      : rolePill;
    const actions = user.role === 'admin' && m.id !== user.id
      ? `<button class="btn-icon-danger" onclick="deleteUser(${m.id},'${esc(m.name)}')" title="Remove user">${I.trash || '🗑'}</button>`
      : '';
    return `
    <div class="member-row">
      <div class="avatar" style="width:34px;height:34px;font-size:12px;background:${m.avatar_color}">${m.initials}</div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:6px"><span style="font-size:14.5px;font-weight:700">${esc(m.name)}</span>${providerBadge}</div>
        <div style="font-size:12.5px;color:var(--muted)">${esc(m.email)}</div>
      </div>
      ${roleToggle}
      ${actions}
    </div>`;
  }).join('');

  const invites = (team.invites || []).map(i => `
    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px dashed var(--border-2);border-radius:11px;margin-bottom:8px">
      <div class="avatar" style="width:32px;height:32px;background:#EAE0CE;color:var(--muted)">${I.user}</div>
      <span style="flex:1;font-size:14.5px">${esc(i.email)}</span>
      <span style="font-size:12px;color:var(--text-2)">${esc(i.role)}</span>
      <span style="font-size:11px;font-weight:700;color:#8C6510;background:#F8E6B8;border-radius:999px;padding:3px 10px">${esc(i.status)}</span>
    </div>
  `).join('');

  const providerIcons = { gemini: '✨', anthropic: '🧠', openai: '🤖', ollama: '🐑' };
  const aiKeys = Object.entries(providers).map(([key, p]) => {
    const connected = keys.find(k => k.provider === key && k.has_key);
    return `
      <div class="api-provider">
        <div class="provider-icon" style="background:var(--primary-bg);color:var(--primary-dark)">${providerIcons[key] || '🔌'}</div>
        <div class="provider-info">
          <div class="provider-name">${p.label}</div>
          <div class="provider-desc">${p.docs}</div>
        </div>
        <span class="status-pill ${connected ? 'connected' : 'not-connected'}">${connected ? 'Connected' : 'Not connected'}</span>
        <button class="${connected ? 'btn-disconnect' : 'btn-connect'}" onclick="toggleApiKeyForm('${key}')">${connected ? 'Manage' : 'Connect'}</button>
      </div>
      <div class="api-key-form" id="form-${key}">
        <div class="form-row">
          <div class="form-field"><label>API Key</label><input type="password" id="key-${key}" placeholder="${key === 'ollama' ? 'Not required for local' : 'sk-...'}" value="${connected ? '••••••••' : ''}"></div>
          <div class="form-field"><label>Model (optional)</label><input id="model-${key}" placeholder="${p.default_model}" value="${connected ? (keys.find(k => k.provider === key)?.model || '') : ''}"></div>
        </div>
        ${key === 'ollama' ? `<div class="form-row"><div class="form-field"><label>Base URL</label><input id="url-${key}" placeholder="${p.default_url}" value="${connected ? (keys.find(k => k.provider === key)?.base_url || '') : ''}"></div></div>` : ''}
        <div class="actions">
          <button class="btn-cancel" onclick="closeApiKeyForm('${key}')">Cancel</button>
          <button class="btn-save" onclick="saveApiKey('${key}')">Save</button>
        </div>
      </div>
    `;
  }).join('');

  // SMTP section
  const smtpSection = smtp ? `
    <div class="settings-card">
      <h3>Email Notifications (SMTP)</h3>
      <div class="desc">Configure how VGo sends email notifications to your team.</div>
      <div style="display:flex;align-items:center;gap:8px;margin:10px 0 14px;padding:8px 12px;background:var(--primary-bg);border-radius:8px;font-size:13px">
        <span style="font-size:16px">✅</span>
        <span>SMTP is configured — emails will be sent from <b>${esc(smtp.from_email)}</b> via <b>${esc(smtp.host)}:${smtp.port}</b></span>
        <button class="btn-secondary" style="margin-left:auto;padding:6px 12px;font-size:12px" onclick="testSmtp()">Send test email</button>
      </div>
      ${renderSmtpForm(smtp)}
    </div>
  ` : `
    <div class="settings-card">
      <h3>Email Notifications (SMTP)</h3>
      <div class="desc">Configure SMTP to enable email notifications for your team.</div>
      ${renderSmtpForm(null)}
    </div>
  `;

  setTimeout(renderPushNotifState, 50);
  return `
    <div class="fade-in settings-page">
      <div class="section-label">Settings</div>
      <h1 class="page-title-sm" style="margin-bottom:26px">Your account</h1>
      
      <div class="settings-card">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:18px">
          <div class="avatar avatar-lg" style="background:${user.avatar_color || '#9C8060'}">${user.initials || '??'}</div>
          <div style="flex:1">
            <div style="font-family:var(--serif);font-size:24px;font-weight:600;line-height:1.1">${esc(user.name)}</div>
            <div style="font-size:14px;color:var(--muted);margin-top:3px">${esc(user.email)}</div>
          </div>
          <span style="font-size:12px;font-weight:700;color:var(--primary-dark);background:var(--primary-bg);border-radius:999px;padding:5px 13px">${user.role}</span>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:18px">
          <h3 style="margin-bottom:12px">Profile Details</h3>
          <div class="form-row" style="gap:12px">
            <div class="form-field" style="flex:1">
              <label class="form-label">Full name</label>
              <input class="form-input" id="prof-name" value="${esc(user.name)}" placeholder="Your name">
            </div>
            <div class="form-field" style="flex:1">
              <label class="form-label">Email address</label>
              <input class="form-input" id="prof-email" type="email" value="${esc(user.email)}" placeholder="you@company.com">
            </div>
          </div>
          <button class="btn-primary" style="margin-top:12px" onclick="saveProfile()">Save profile</button>
          <div id="prof-error" class="pw-error" style="display:none"></div>
        </div>
      </div>

      <div class="settings-card">
        <h3>Change Password</h3>
        <div class="desc">Update your account password.</div>
        <div class="password-form">
          <div class="form-field">
            <label class="form-label">Current password</label>
            <input class="form-input" type="password" id="pw-current" placeholder="••••••••" autocomplete="current-password">
          </div>
          <div class="form-field">
            <label class="form-label">New password</label>
            <input class="form-input" type="password" id="pw-new" placeholder="At least 6 characters" autocomplete="new-password">
          </div>
          <div class="form-field">
            <label class="form-label">Confirm new password</label>
            <input class="form-input" type="password" id="pw-confirm" placeholder="••••••••" autocomplete="new-password">
          </div>
          <div class="pw-error" id="pw-error" style="display:none"></div>
          <button class="btn-primary" style="align-self:flex-start" onclick="changePassword()">Update password</button>
        </div>
      </div>

      <div class="settings-card">
        <h3>Email Notifications</h3>
        <div class="desc">Choose what gets sent to your email inbox.</div>
        ${notifRadio}
      </div>

      <div class="settings-card">
        <h3>Push Notifications</h3>
        <div class="desc">Get real-time notifications on your device, even when the app is closed.</div>
        <div id="push-notif-section">
          <div id="push-notif-state"></div>
        </div>
      </div>

      ${user.role === 'admin' ? smtpSection : ''}

      <div class="settings-card">
        <h3>AI Connections</h3>
        <div class="desc">Connect your AI provider API keys to power VGo's AI features.</div>
        ${aiKeys}
      </div>

      <div class="settings-card">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:4px">
          <span style="font-size:15px;font-weight:700">Team members</span>
          ${user.role === 'admin' ? '<span style="font-size:11px;font-weight:700;color:var(--primary-dark);background:var(--primary-bg);border-radius:999px;padding:3px 9px">Admin</span>' : ''}
        </div>
        <div class="desc">Manage who has access to your workspace.</div>
        ${user.role === 'admin' ? `
        <div style="margin:14px 0;padding:16px;border:1px solid var(--border);border-radius:12px;background:var(--sand-light,var(--surface-2,#faf8f4))">
          <div style="font-size:13px;font-weight:700;margin-bottom:10px">Add new user</div>
          <div class="form-row" style="gap:10px">
            <div class="form-field" style="flex:1">
              <input class="form-input" id="new-user-name" placeholder="Full name">
            </div>
            <div class="form-field" style="flex:1">
              <input class="form-input" id="new-user-email" type="email" placeholder="email@company.com" oninput="updateAuthProviderHint()">
            </div>
            <div class="form-field" style="flex:0 0 120px">
              <input class="form-input" id="new-user-pass" type="password" placeholder="Password (ext. only)">
            </div>
            <div class="role-toggle" style="flex:none">
              <button class="active" id="new-user-member" onclick="setNewUserRole('member')">Member</button>
              <button id="new-user-admin" onclick="setNewUserRole('admin')">Admin</button>
            </div>
            <button class="btn-primary" style="flex:none;padding:9px 16px;font-size:13.5px" onclick="createUser()">Add user</button>
          </div>
          <div id="auth-provider-hint" style="font-size:11px;color:var(--muted);margin-top:6px"></div>
          <div id="new-user-error" class="pw-error" style="display:none;margin-top:8px"></div>
        </div>
        ` : ''}
        ${invites ? `<div style="margin-bottom:14px"><div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Pending</div>${invites}</div>` : ''}
        ${members}
      </div>

      ${user.role === 'admin' ? `
      <div class="settings-card">
        <h3 style="color:var(--red)">Danger Zone</h3>
        <div class="desc">Irreversible actions. Proceed with caution.</div>
        <div class="danger-zone">
          <h4>Master Reset</h4>
          <p>This will permanently delete all projects, tasks, messages, files, team members, and AI keys. Only your admin account will remain. This cannot be undone.</p>
          <button class="btn-danger" onclick="masterReset()">Reset all data</button>
        </div>
      </div>
      ` : ''}
    </div>
  `;
}

function renderSmtpForm(smtp) {
  const s = smtp || {};
  return `
    <div class="smtp-form">
      <div class="form-row" style="gap:12px">
        <div class="form-field" style="flex:2">
          <label class="form-label">SMTP Host</label>
          <input class="form-input" id="smtp-host" value="${esc(s.host || '')}" placeholder="mail.example.com">
        </div>
        <div class="form-field" style="flex:1">
          <label class="form-label">Port</label>
          <input class="form-input" id="smtp-port" type="number" value="${s.port || 465}" placeholder="465">
        </div>
        <div class="form-field" style="flex:1">
          <label class="form-label">Encryption</label>
          <select class="form-input" id="smtp-encryption">
            <option value="ssl" ${s.encryption === 'ssl' ? 'selected' : ''}>SSL</option>
            <option value="tls" ${s.encryption === 'tls' ? 'selected' : ''}>TLS</option>
            <option value="none" ${s.encryption === 'none' ? 'selected' : ''}>None</option>
          </select>
        </div>
      </div>
      <div class="form-row" style="gap:12px">
        <div class="form-field" style="flex:1">
          <label class="form-label">Username</label>
          <input class="form-input" id="smtp-username" value="${esc(s.username || '')}" placeholder="you@example.com">
        </div>
        <div class="form-field" style="flex:1">
          <label class="form-label">Password</label>
          <input class="form-input" id="smtp-password" type="password" placeholder="${s.host ? '•••••••• (leave blank to keep)' : 'Password'}">
        </div>
      </div>
      <div class="form-row" style="gap:12px">
        <div class="form-field" style="flex:1">
          <label class="form-label">From Name</label>
          <input class="form-input" id="smtp-from-name" value="${esc(s.from_name || 'VGo')}" placeholder="VGo">
        </div>
        <div class="form-field" style="flex:1">
          <label class="form-label">From Email</label>
          <input class="form-input" id="smtp-from-email" type="email" value="${esc(s.from_email || '')}" placeholder="noreply@example.com">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:12px">
        <button class="btn-primary" onclick="saveSmtp()">Save SMTP settings</button>
      </div>
      <div id="smtp-error" class="pw-error" style="display:none;margin-top:8px"></div>
    </div>
  `;
}

// ===== Profile update =====
async function saveProfile() {
  const name = document.getElementById('prof-name')?.value.trim();
  const email = document.getElementById('prof-email')?.value.trim();
  const errEl = document.getElementById('prof-error');
  errEl.style.display = 'none';
  if (!name || !email) { errEl.textContent = 'Name and email are required'; errEl.style.display = 'block'; return; }
  try {
    await API.updateProfile({ name, email });
    State.user.name = name;
    State.user.email = email;
    State.user.initials = name.split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase();
    toast('Profile updated successfully', 'success');
    render();
  } catch(e) { errEl.textContent = e.message; errEl.style.display = 'block'; }
}


// ===== Push notification state =====
async function renderPushNotifState() {
  const el = document.getElementById('push-notif-state');
  if (!el) return;
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    el.innerHTML = '<div class="settings-hint">Notifications not supported on this browser.</div>';
    return;
  }
  const perm = Notification.permission; // 'granted' | 'denied' | 'default'
  if (perm === 'granted') {
    el.innerHTML = '<div class="push-state-on"><span class="push-dot on"></span>Enabled — notifications are active</div>' +
      '<button class="btn-secondary" style="margin-top:8px" onclick="disablePushNotifications()">Disable notifications</button>';
  } else if (perm === 'denied') {
    el.innerHTML = '<div class="push-state-denied"><span class="push-dot denied"></span>Blocked — enable in browser/device settings</div>';
  } else {
    el.innerHTML = '<div class="push-state-default">Receive push notifications on this device</div>' +
      '<button class="btn-primary" style="margin-top:8px" onclick="enableNotifications()">Enable notifications</button>';
    // iOS hint
    if (/iPhone|iPad|iPod/i.test(navigator.userAgent)) {
      el.innerHTML += '<div class="settings-hint" style="margin-top:8px">⚠️ On iOS: tap Share → Add to Home Screen first for push to work.</div>';
    }
  }
}

async function disablePushNotifications() {
  try {
    const reg = await navigator.serviceWorker.getRegistration('/sw.js');
    if (reg && reg.pushManager) {
      const sub = await reg.pushManager.getSubscription();
      if (sub) await sub.unsubscribe();
    }
    toast('Notifications disabled', 'success');
    renderPushNotifState();
  } catch(e) { toast('Could not disable: ' + e.message, 'error'); }
}

// ===== Notification preference =====
async function updateNotifPref(pref) {
  try {
    await API.updateNotifications({ email_notify_pref: pref });
    State.notifSettings.email_notify_pref = pref;
    document.querySelectorAll('.notif-pref-option').forEach(el => {
      el.classList.toggle('selected', el.querySelector('input').value === pref);
    });
    toast('Notification preference updated', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

// ===== SMTP =====
async function saveSmtp() {
  const data = {
    host: document.getElementById('smtp-host')?.value.trim(),
    port: parseInt(document.getElementById('smtp-port')?.value) || 465,
    encryption: document.getElementById('smtp-encryption')?.value,
    username: document.getElementById('smtp-username')?.value.trim(),
    from_name: document.getElementById('smtp-from-name')?.value.trim() || 'VGo',
    from_email: document.getElementById('smtp-from-email')?.value.trim(),
  };
  const pw = document.getElementById('smtp-password')?.value;
  if (pw) data.password = pw;
  
  const errEl = document.getElementById('smtp-error');
  errEl.style.display = 'none';
  if (!data.host || !data.username || !data.from_email) {
    errEl.textContent = 'Host, username, and from email are required';
    errEl.style.display = 'block';
    return;
  }
  
  try {
    await API.updateSmtp(data);
    State.smtpSettings = null; // Force reload
    toast('SMTP settings saved', 'success');
    render();
  } catch(e) { errEl.textContent = e.message; errEl.style.display = 'block'; }
}

async function testSmtp() {
  try {
    toast('Sending test email...', 'info');
    const res = await API.testSmtp();
    toast(res.message || 'Test email sent!', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

// ===== User management =====
let newUserRole = 'member';
function setNewUserRole(r) {
  newUserRole = r;
  document.getElementById('new-user-member')?.classList.toggle('active', r === 'member');
  document.getElementById('new-user-admin')?.classList.toggle('active', r === 'admin');
}

function updateAuthProviderHint() {
  const email = document.getElementById('new-user-email')?.value.trim() || '';
  const hint = document.getElementById('auth-provider-hint');
  const passField = document.getElementById('new-user-pass');
  if (!hint) return;
  if (email.endsWith('@victorygenomics.com')) {
    hint.textContent = 'Internal user — will sign in with Microsoft. Password not required.';
    if (passField) passField.placeholder = 'Not needed (MS user)';
  } else {
    hint.textContent = 'External user — password required, invite email will be sent.';
    if (passField) passField.placeholder = 'Password';
  }
}

async function changeUserRole(userId, role) {
  try {
    await API.changeRole(userId, role);
    State.teamData = null;
    toast('Role updated', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function toggleUserActive(userId) {
  try {
    await API.toggleUserActive(userId);
    State.teamData = null;
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function createUser() {
  const name = document.getElementById('new-user-name')?.value.trim();
  const email = document.getElementById('new-user-email')?.value.trim();
  const pass = document.getElementById('new-user-pass')?.value;
  const errEl = document.getElementById('new-user-error');
  errEl.style.display = 'none';
  if (!name || !email) { errEl.textContent = 'Name and email are required'; errEl.style.display = 'block'; return; }
  
  // Determine auth_provider from email domain
  const isInternal = email.endsWith('@victorygenomics.com');
  const authProvider = isInternal ? 'microsoft' : 'password';
  
  if (authProvider === 'password' && (!pass || pass.length < 6)) {
    errEl.textContent = 'Password must be at least 6 characters for external users';
    errEl.style.display = 'block';
    return;
  }
  
  try {
    const payload = { name, email, role: newUserRole, auth_provider: authProvider };
    if (authProvider === 'password') payload.password = pass;
    await API.createUser(payload);
    State.teamData = null;
    const msg = authProvider === 'microsoft' 
      ? 'User added: ' + name + ' (sign in with Microsoft)'
      : 'User invited: ' + name + ' (password email sent)';
    toast(msg, 'success');
    render();
  } catch(e) { errEl.textContent = e.message; errEl.style.display = 'block'; }
}

async function deleteUser(userId, name) {
  // Get remaining team members for reassignment dropdown
  const members = (State.teamData?.members || []).filter(m => m.id !== userId);
  
  const memberOptions = members.length ? members.map(m => 
    `<option value="${m.id}">${esc(m.name)}</option>`
  ).join('') : '<option value="">No available members</option>';

  Modal.open({
    title: 'Remove user',
    body: `
      <p style="font-size:14px;color:var(--text-2);margin-bottom:18px">You are about to remove <b>${esc(name)}</b> from the workspace. They will lose access to all projects and tasks.</p>
      <div class="form-field">
        <label class="form-label">Reassign projects & tasks to</label>
        <select class="form-input" id="reassign-select" style="cursor:pointer">
          <option value="">Leave unassigned</option>
          ${memberOptions}
        </select>
        <p style="font-size:12px;color:var(--muted);margin-top:6px">Their project memberships, task assignments, and uploaded files will be transferred to the selected user.</p>
      </div>
    `,
    footer: `
      <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" style="background:#B0432B" onclick="confirmDeleteUser(${userId}, '${esc(name).replace(/'/g,"\'")}')">Remove user</button>
    `,
  });
}

async function confirmDeleteUser(userId, name) {
  const reassignTo = document.getElementById('reassign-select')?.value || null;
  Modal.close();
  try {
    await API.deleteUser(userId, reassignTo ? { reassign_to: parseInt(reassignTo) } : {});
    State.teamData = null;
    toast('User removed: ' + name + (reassignTo ? ' — tasks reassigned' : ''), 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

// ===== Existing functions =====
let inviteRole = 'member';
function setInviteRole(r) {
  inviteRole = r;
  document.getElementById('role-member-btn').classList.toggle('active', r === 'member');
  document.getElementById('role-admin-btn').classList.toggle('active', r === 'admin');
}

async function sendInvite() {
  const email = document.getElementById('invite-email').value.trim();
  if (!email || email.indexOf('@') < 0) return;
  try {
    await API.invite(email, inviteRole);
    State.teamData = null;
    document.getElementById('invite-email').value = '';
    render();
    toast('Invite sent to ' + email, 'success');
  } catch(e) { toast(e.message, 'error'); }
}

async function toggleNotif(key, btn) {
  const isOn = btn.classList.contains('on');
  try {
    await API.updateNotifications({ [key]: !isOn });
    btn.classList.toggle('on', !isOn);
    btn.classList.toggle('off', isOn);
  } catch(e) { console.error(e); }
}

function toggleApiKeyForm(provider) {
  const form = document.getElementById('form-' + provider);
  form.classList.toggle('show');
}
function closeApiKeyForm(provider) {
  document.getElementById('form-' + provider).classList.remove('show');
}

async function saveApiKey(provider) {
  const key = document.getElementById('key-' + provider)?.value || '';
  const model = document.getElementById('model-' + provider)?.value || '';
  const url = document.getElementById('url-' + provider)?.value || null;
  if (key === '••••••••') return;
  try {
    await API.updateApiKey({ provider, api_key: key, model: model || null, base_url: url });
    State.apiKeys = null;
    closeApiKeyForm(provider);
    render();
    toast(esc(providers[provider]?.label || provider) + ' connected', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

async function changePassword() {
  const current = document.getElementById('pw-current').value;
  const newPw = document.getElementById('pw-new').value;
  const confirm = document.getElementById('pw-confirm').value;
  const errEl = document.getElementById('pw-error');
  errEl.style.display = 'none';
  if (!current || !newPw) { errEl.textContent = 'Please fill in all fields'; errEl.style.display = 'block'; return; }
  if (newPw.length < 6) { errEl.textContent = 'Password must be at least 6 characters'; errEl.style.display = 'block'; return; }
  if (newPw !== confirm) { errEl.textContent = 'New passwords do not match'; errEl.style.display = 'block'; return; }
  try {
    await API.updatePassword({ current_password: current, new_password: newPw });
    document.getElementById('pw-current').value = '';
    document.getElementById('pw-new').value = '';
    document.getElementById('pw-confirm').value = '';
    toast('Password updated successfully', 'success');
  } catch(e) { errEl.textContent = e.message; errEl.style.display = 'block'; }
}

async function masterReset() {
  const confirmed = await Modal.confirm({
    title: 'Master Reset',
    message: 'This will permanently delete ALL projects, tasks, messages, files, team members, and AI keys. Only your admin account will remain. This CANNOT be undone. Are you absolutely sure?',
    confirmText: 'Yes, reset everything',
    danger: true,
  });
  if (!confirmed) return;
  try {
    const res = await API.reset();
    State.projects = null;
    State.todayData = null;
    State.channels = null;
    State.teamData = null;
    State.notifSettings = null;
    State.apiKeys = null;
    State.aiProviders = null;
    toast('All data has been reset', 'success');
    nav('today');
  } catch(e) { toast(e.message, 'error'); }
}