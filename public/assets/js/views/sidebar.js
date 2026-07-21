// VGo — Sidebar (clean, minimal)
function renderSidebar() {
  const u = State.user || {};
  const initials = u.initials || '??';
  const color = u.avatar_color || '#9A8A78';
  const isAdmin = u.role === 'admin';
  
  let navItems = [
    { id: 'mytasks', label: 'My Tasks', icon: I.check },
    { id: 'taskoverview', label: 'Task Overview', icon: I.grid },
    { id: 'projects', label: 'Projects', icon: I.folder },
    { id: 'messages', label: 'Messages', icon: I.msg },
  ];
  
  return `
    <aside class="sidebar">
      <div class="sidebar-logo">
        <img src="/assets/img/vgo-logo.png" alt="VGo">
      </div>
      <nav class="nav-section">
        ${navItems.map(n => {
          const badge = (n.id === 'messages' && State.msgUnreadTotal > 0)
            ? `<span class="nav-badge" id="nav-msg-badge">${State.msgUnreadTotal > 99 ? '99+' : State.msgUnreadTotal}</span>`
            : (n.id === 'messages' ? `<span class="nav-badge" id="nav-msg-badge" style="display:none"></span>` : '');
          return `<button class="nav-btn ${State.screen === n.id ? 'active' : ''}" onclick="nav('${n.id}')">${n.icon}<span>${n.label}</span>${badge}</button>`;
        }).join('')}
        ${u.crm_user_id ? `<a class="nav-btn" href="/crm/" style="text-decoration:none">${I.people}<span>CRM</span></a>` : ''}
      </nav>
      <div class="sidebar-bottom">
        <div class="sidebar-user" onclick="nav('settings')" style="cursor:pointer;transition:background .15s" onmouseover="this.style.background='var(--gold-bg)'" onmouseout="this.style.background='none'">
          <div class="avatar avatar-md" style="background:${color}">${initials}</div>
          <div style="line-height:1.2;overflow:hidden;flex:1">
            <div style="font-size:13px;font-weight:700;white-space:nowrap">${esc(u.name || 'User')}</div>
            <div style="font-size:12px;color:var(--muted);white-space:nowrap">${u.role || ''}</div>
          </div>
          <span style="color:var(--muted);flex:none">${I.settings}</span>
        </div>
        <button class="nav-btn logout-btn" onclick="logout()">
          ${I.logout}
          <span>Sign out</span>
        </button>
      </div>
    </aside>`;
}

async function logout() {
  try {
    await API.logout();
  } catch(e) {}
  location.reload();
}