// VGo — Sidebar (clean, minimal)
function renderSidebar() {
  const u = State.user || {};
  const initials = u.initials || '??';
  const color = u.avatar_color || '#9A8A78';
  const isAdmin = u.role === 'admin';
  
  const workflowItems = [
    { id: 'mytasks', label: 'My Tasks', icon: I.check },
    { id: 'taskoverview', label: 'Task Overview', icon: I.grid },
    { id: 'projects', label: 'Projects', icon: I.folder },
    { id: 'messages', label: 'Messages', icon: I.msg },
  ];
  const granted = new Set(u.modules || []);
  const crmItems = [
    { module: 'crm.dashboard', id: 'crm-dashboard', label: 'Overview', icon: I.grid },
    { module: 'crm.leads', id: 'crm-leads', label: 'Leads', icon: I.user },
    { module: 'crm.interactions', id: 'crm-interactions', label: 'Interactions', icon: I.msg },
    { module: 'crm.proposals', id: 'crm-proposals', label: 'Proposals', icon: I.file },
    { module: 'crm.email', id: 'crm-email', label: 'Email marketing', icon: I.mail || I.msg },
    { module: 'crm.communications', id: 'crm-communications', label: 'Calls & WhatsApp', icon: I.phone || I.msg },
    { module: 'crm.automation', id: 'crm-automation', label: 'Automations', icon: I.sparkle },
    { module: 'crm.reports', id: 'crm-reports', label: 'Reports', icon: I.chart || I.grid },
    { module: 'crm.knowledge', id: 'crm-knowledge', label: 'Knowledge hub', icon: I.book || I.file },
  ].filter(item => granted.has(item.module));
  const workflowOpen = localStorage.getItem('vgold-nav-workflow') !== 'closed';
  const crmOpen = localStorage.getItem('vgold-nav-crm') !== 'closed';

  const renderItems = (items) => items.map(n => {
    const badge = (n.id === 'messages' && State.msgUnreadTotal > 0)
      ? `<span class="nav-badge" id="nav-msg-badge">${State.msgUnreadTotal > 99 ? '99+' : State.msgUnreadTotal}</span>`
      : (n.id === 'messages' ? `<span class="nav-badge" id="nav-msg-badge" style="display:none"></span>` : '');
    return `<button class="nav-btn ${State.screen === n.id ? 'active' : ''}" onclick="nav('${n.id}')">${n.icon || I.grid}<span>${n.label}</span>${badge}</button>`;
  }).join('');
  
  return `
    <aside class="sidebar">
      <div class="sidebar-logo">
        <img src="/assets/img/vgo-logo.png" alt="VGold">
      </div>
      <nav class="nav-section" aria-label="VGold modules">
        <div class="module-nav-group">
          <button class="module-nav-toggle" onclick="toggleNavGroup('workflow')" aria-expanded="${workflowOpen}">
            <span class="module-nav-mark workflow">W</span><span>Workflow</span><span class="module-nav-chevron ${workflowOpen ? 'open' : ''}">⌄</span>
          </button>
          <div class="module-nav-items ${workflowOpen ? '' : 'collapsed'}" id="nav-group-workflow">${renderItems(workflowItems)}</div>
        </div>
        ${crmItems.length ? `
        <div class="module-nav-group crm-group">
          <button class="module-nav-toggle" onclick="toggleNavGroup('crm')" aria-expanded="${crmOpen}">
            <span class="module-nav-mark crm">C</span><span>CRM</span><span class="module-nav-chevron ${crmOpen ? 'open' : ''}">⌄</span>
          </button>
          <div class="module-nav-items ${crmOpen ? '' : 'collapsed'}" id="nav-group-crm">${renderItems(crmItems)}</div>
        </div>` : ''}
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

function toggleNavGroup(group) {
  const el = document.getElementById('nav-group-' + group);
  if (!el) return;
  const collapsed = el.classList.toggle('collapsed');
  localStorage.setItem('vgold-nav-' + group, collapsed ? 'closed' : 'open');
  const toggle = el.previousElementSibling;
  toggle?.setAttribute('aria-expanded', String(!collapsed));
  toggle?.querySelector('.module-nav-chevron')?.classList.toggle('open', !collapsed);
}

async function logout() {
  try {
    await API.logout();
  } catch(e) {}
  location.reload();
}
