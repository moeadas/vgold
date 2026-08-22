// VGo — Sidebar (clean, minimal)
function renderSidebar() {
  const u = State.user || {};
  const initials = u.initials || '??';
  const color = u.avatar_color || '#9A8A78';
  const isAdmin = u.role === 'admin';
  
  const workflowItems = [
    { id: 'mytasks', label: 'My Tasks', icon: I.check },
    { id: 'priorities', label: 'Priorities', icon: I.star || I.sparkle },
    { id: 'taskoverview', label: 'Task Overview', icon: I.grid },
    { id: 'projects', label: 'Workspaces', icon: I.folder },
    { id: 'messages', label: 'Messages', icon: I.msg },
  ];
  // Contractors bill the company from here. Shown only to the people we accept
  // invoices from, so it stays invisible to staff.
  if (u.is_contractor) {
    workflowItems.push({ id: 'my-invoices', label: 'My invoices', icon: I.file || I.grid });
  }
  const granted = new Set(u.modules || []);
  const crmItems = [
    { module: 'crm.dashboard', id: 'crm-dashboard', label: 'Overview', icon: I.grid },
    { module: 'crm.leads', id: 'crm-leads', label: 'Leads', icon: I.user },
    { module: 'crm.leads', id: 'crm-customers', label: 'Customers', icon: I.people || I.user },
    { module: 'crm.interactions', id: 'crm-interactions', label: 'Interactions', icon: I.msg },
    { module: 'crm.proposals', id: 'crm-proposals', label: 'Proposals', icon: I.file },
    { module: 'crm.email', id: 'crm-email', label: 'Email marketing', icon: I.mail || I.msg },
    { module: 'crm.communications', id: 'crm-communications', label: 'Calls & WhatsApp', icon: I.phone || I.msg },
    { module: 'crm.automation', id: 'crm-automation', label: 'Automations', icon: I.sparkle },
    { module: 'crm.sales', id: 'crm-sales', label: 'Sales Dashboard', icon: I.chart || I.grid },
    { module: 'crm.reports', id: 'crm-reports', label: 'Reports', icon: I.chart || I.grid },
    { module: 'crm.knowledge', id: 'crm-knowledge', label: 'Knowledge hub', icon: I.book || I.file },
  ].filter(item => granted.has(item.module));

  // Accounting & Finance — third app. Explicit-grant only, so this whole group
  // is absent for anyone without at least one acc.* module.
  const A = (typeof ACC_NAV_ICONS !== 'undefined') ? ACC_NAV_ICONS : {};
  const accItems = [
    { module: 'acc.dashboard', id: 'acc-dashboard', label: 'Overview', icon: A.overview || I.grid },
    { module: 'acc.invoices', id: 'acc-invoices', label: 'Invoices', icon: A.invoice || I.file },
    { module: 'acc.bills', id: 'acc-bills', label: 'Bills', icon: A.bill || I.file },
    { module: 'acc.bills', id: 'acc-contractor-invoices', label: 'Contractor invoices', icon: A.contacts || I.people },
    { module: 'acc.customers', id: 'acc-customers', label: 'Customers', icon: A.contacts || I.people },
    { module: 'acc.vendors', id: 'acc-vendors', label: 'Vendors', icon: A.contacts || I.people },
    { module: 'acc.investors', id: 'acc-investors', label: 'Investors', icon: A.contacts || I.people },
    { module: 'acc.banking', id: 'acc-banking', label: 'Banking', icon: A.bank || I.grid },
    { module: 'acc.accounting', id: 'acc-ledger', label: 'Journal & ledger', icon: A.ledger || I.file },
    { module: 'acc.catalog', id: 'acc-catalog', label: 'Catalog', icon: A.catalog || I.grid },
    { module: 'acc.recurring', id: 'acc-recurring', label: 'Recurring', icon: A.recurring || I.clock },
    { module: 'acc.reports', id: 'acc-reports', label: 'Reports', icon: A.reports || I.grid },
    { module: 'acc.settings', id: 'acc-settings', label: 'Accounting settings', icon: A.settings || I.settings },
  ].filter(item => granted.has(item.module));

  // Each user's own ordering, layered over the canonical menus above.
  const ordered = { workflow: navApplyOrder(workflowItems, 'workflow'),
                    crm: navApplyOrder(crmItems, 'crm'),
                    acc: navApplyOrder(accItems, 'acc') };

  const workflowOpen = localStorage.getItem('vgold-nav-workflow') !== 'closed';
  const crmOpen = localStorage.getItem('vgold-nav-crm') !== 'closed';
  const accOpen = localStorage.getItem('vgold-nav-acc') !== 'closed';

  // Per-module notification counts. Every item carries a badge node — hidden at
  // zero — so the poll can fill it in without a re-render, and each declares its
  // group so the group header can roll its items up.
  const counts = (typeof navBadgeCounts === 'function') ? navBadgeCounts() : {};
  const groupTotal = (items) => items.reduce((s, n) => s + (Number(counts[n.id]) || 0), 0);
  const groupBadge = (key, items) => {
    const n = groupTotal(items);
    return `<span class="module-nav-count" data-nav-group-badge="${key}"${n > 0 ? '' : ' style="display:none"'}>${n > 0 ? (n > 99 ? '99+' : n) : ''}</span>`;
  };

  const renderItems = (items, groupKey) => items.map(n => {
    const count = Number(counts[n.id]) || 0;
    const badge = `<span class="nav-badge" data-nav-badge="${n.id}" data-nav-group="${groupKey}"`
      + (n.id === 'messages' ? ' id="nav-msg-badge"' : '')
      + (count > 0 ? '' : ' style="display:none"') + '>'
      + (count > 0 ? (count > 99 ? '99+' : count) : '') + '</span>';
    // Detail screens keep their parent nav item highlighted.
    const parents = {
      'acc-doc': State.accDocType === 'bill' ? 'acc-bills' : 'acc-invoices',
      'acc-contact': (typeof accContactKind === 'function'
        ? accContactKind(State.accContactType).nav : 'acc-customers'),
      'acc-account': 'acc-banking',
      'acc-bill-scan': 'acc-bills',
      'acc-danger': 'acc-settings',
      // A form page keeps whichever nav item it was opened from highlighted.
      'acc-form': (typeof AccState !== 'undefined' && AccState.form?.back?.screen) || 'acc-dashboard',
      'acc-reconciliation': 'acc-banking',
      'acc-bank-import': 'acc-banking',
      'acc-bank-review': 'acc-banking',
      'acc-contractor-invoice': 'acc-contractor-invoices',
      'crm-lead': 'crm-leads',
      'crm-lead-new': 'crm-leads',
      'crm-lead-email': 'crm-leads',
      'crm-customer': 'crm-customers',
      'crm-sale-new': 'crm-sales',
      'crm-sales-targets': 'crm-sales',
      'crm-sales-settings': 'crm-sales',
    };
    const activeId = parents[State.screen] || State.screen;
    return `<button class="nav-btn ${activeId === n.id ? 'active' : ''}" onclick="nav('${n.id}')"
      data-nav-id="${n.id}" data-nav-group-item="${groupKey}"
      aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown"
      title="${esc(n.label)} — drag the handle, or Alt+↑/↓, to reorder">${n.icon || I.grid}<span>${n.label}</span>${badge}<span class="nav-drag-handle" aria-hidden="true" title="Drag to reorder"><svg width="12" height="14" viewBox="0 0 12 14" fill="currentColor"><circle cx="3.5" cy="3" r="1.3"/><circle cx="8.5" cy="3" r="1.3"/><circle cx="3.5" cy="7" r="1.3"/><circle cx="8.5" cy="7" r="1.3"/><circle cx="3.5" cy="11" r="1.3"/><circle cx="8.5" cy="11" r="1.3"/></svg></span></button>`;
  }).join('');
  
  return `
    <aside class="sidebar">
      <div class="sidebar-logo">
        <a href="#mytasks" class="sidebar-logo-link" onclick="event.preventDefault();goHome()" title="Go to home" aria-label="VGo — go to home">
          <img src="/assets/img/vgo-logo.png?v=${window.ASSET_V || '1'}" alt="VGo">
        </a>
      </div>
      <nav class="nav-section" aria-label="VGo modules">
        <div class="module-nav-group">
          <button class="module-nav-toggle" onclick="toggleNavGroup('workflow')" aria-expanded="${workflowOpen}">
            <span class="module-nav-mark workflow">W</span><span class="module-nav-label">Workflow</span>${groupBadge('workflow', ordered.workflow)}${navResetBtn('workflow')}<span class="module-nav-chevron ${workflowOpen ? 'open' : ''}">⌄</span>
          </button>
          <div class="module-nav-items ${workflowOpen ? '' : 'collapsed'}" id="nav-group-workflow">${renderItems(ordered.workflow, 'workflow')}</div>
        </div>
        ${crmItems.length ? `
        <div class="module-nav-group crm-group">
          <button class="module-nav-toggle" onclick="toggleNavGroup('crm')" aria-expanded="${crmOpen}">
            <span class="module-nav-mark crm">C</span><span class="module-nav-label">CRM</span>${groupBadge('crm', ordered.crm)}${navResetBtn('crm')}<span class="module-nav-chevron ${crmOpen ? 'open' : ''}">⌄</span>
          </button>
          <div class="module-nav-items ${crmOpen ? '' : 'collapsed'}" id="nav-group-crm">${renderItems(ordered.crm, 'crm')}</div>
        </div>` : ''}
        ${accItems.length ? `
        <div class="module-nav-group acc-group">
          <button class="module-nav-toggle" onclick="toggleNavGroup('acc')" aria-expanded="${accOpen}">
            <span class="module-nav-mark acc">A</span><span class="module-nav-label">Accounting</span>${groupBadge('acc', ordered.acc)}${navResetBtn('acc')}<span class="module-nav-chevron ${accOpen ? 'open' : ''}">⌄</span>
          </button>
          <div class="module-nav-items ${accOpen ? '' : 'collapsed'}" id="nav-group-acc">${renderItems(ordered.acc, 'acc')}</div>
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

/* ===========================================================================
   Per-user sidebar ordering.

   The saved order is a *preference layered over* the canonical menus in
   renderSidebar(), never a replacement for them: ids the user has arranged come
   first in their order, then anything the list knows about that the preference
   does not — a newly shipped item, or a module just granted — is appended. So a
   release can add nav items and a stale preference can never hide them.

   One pointer-based drag engine rather than HTML5 drag-and-drop: DnD events do
   not fire on touch, and we want the same live-reorder feel everywhere. All
   listeners are delegated from document because renderSidebar() replaces the
   whole <aside> on every navigation.
=========================================================================== */
const NAV_GROUPS = ['workflow', 'crm', 'acc'];

function navOrderStore() {
  const u = (typeof State !== 'undefined' && State.user) || {};
  const fromServer = (u.nav_order && typeof u.nav_order === 'object' && !Array.isArray(u.nav_order)) ? u.nav_order : null;
  if (fromServer && Object.keys(fromServer).length) return fromServer;
  // Local mirror: covers the moment before /me resolves, and a failed save.
  try {
    const raw = localStorage.getItem('vgo-nav-order-' + (u.id || 0));
    const parsed = raw ? JSON.parse(raw) : null;
    if (parsed && typeof parsed === 'object') return parsed;
  } catch (e) {}
  return {};
}

function navApplyOrder(items, groupKey) {
  const want = navOrderStore()[groupKey];
  if (!Array.isArray(want) || !want.length) return items;
  const remaining = new Map(items.map(n => [n.id, n]));
  const out = [];
  want.forEach(id => { if (remaining.has(id)) { out.push(remaining.get(id)); remaining.delete(id); } });
  remaining.forEach(n => out.push(n));
  return out;
}

/** Reset control, shown only once a group actually has a custom order. */
function navResetBtn(group) {
  const has = Array.isArray(navOrderStore()[group]) && navOrderStore()[group].length;
  return `<span class="nav-reset" role="button" tabindex="0" data-nav-reset="${group}"
    title="Reset this menu to the default order" aria-label="Reset this menu to the default order"
    ${has ? '' : 'hidden'}>&#8635;</span>`;
}

function navSaveOrder(group, ids) {
  if (typeof State === 'undefined') return;
  State.user = State.user || {};
  const next = { ...(State.user.nav_order || {}) };
  if (ids && ids.length) next[group] = ids; else delete next[group];
  State.user.nav_order = next;
  try { localStorage.setItem('vgo-nav-order-' + (State.user.id || 0), JSON.stringify(next)); } catch (e) {}
  document.querySelectorAll(`[data-nav-reset="${group}"]`).forEach(b => { b.hidden = !(ids && ids.length); });
  clearTimeout(navSaveOrder._t);
  navSaveOrder._t = setTimeout(() => {
    if (typeof API === 'undefined' || !API.updateNavOrder) return;
    API.updateNavOrder(State.user.nav_order).catch(() => {
      if (typeof toast === 'function') toast('Menu order saved on this device only — the server did not accept it.', 'error');
    });
  }, 600);
}

function navResetOrder(group) {
  navSaveOrder(group, null);
  if (typeof render === 'function') render();
  else if (typeof State !== 'undefined') { const sb = document.querySelector('.sidebar'); if (sb) sb.outerHTML = renderSidebar(); }
  if (typeof toast === 'function') toast('Menu order reset.', 'success');
}
window.navResetOrder = navResetOrder;

const NavDrag = {
  el: null, box: null, group: null, startY: 0, moved: false, pressTimer: null, blockClickUntil: 0, touch: false,

  start(btn, y, touch) {
    const box = btn.closest('.module-nav-items');
    if (!box || !box.id || box.querySelectorAll('.nav-btn').length < 2) return false;
    NavDrag.touch = !!touch;
    NavDrag.el = btn;
    NavDrag.box = box;
    NavDrag.group = box.id.replace('nav-group-', '');
    NavDrag.startY = y;
    NavDrag.moved = false;
    btn.classList.add('nav-dragging');
    box.classList.add('nav-reordering');
    document.body.classList.add('nav-drag-active');
    return true;
  },

  move(y) {
    const el = NavDrag.el, box = NavDrag.box;
    if (!el || !box) return;
    if (!NavDrag.moved && Math.abs(y - NavDrag.startY) < 4) return;
    NavDrag.moved = true;
    const others = Array.from(box.querySelectorAll('.nav-btn')).filter(n => n !== el);
    let before = null;
    for (const s of others) {
      const r = s.getBoundingClientRect();
      if (y < r.top + r.height / 2) { before = s; break; }
    }
    if (before) { if (el.nextElementSibling !== before) box.insertBefore(el, before); }
    else if (box.lastElementChild !== el) box.appendChild(el);
  },

  finish() {
    clearTimeout(NavDrag.pressTimer);
    const el = NavDrag.el, box = NavDrag.box, group = NavDrag.group, moved = NavDrag.moved;
    if (el) el.classList.remove('nav-dragging');
    if (box) box.classList.remove('nav-reordering');
    document.body.classList.remove('nav-drag-active');
    NavDrag.el = NavDrag.box = NavDrag.group = null;
    NavDrag.moved = false;
    NavDrag.touch = false;
    if (moved && box && group) {
      // Swallow the click that a pointerup would otherwise deliver to nav().
      NavDrag.blockClickUntil = Date.now() + 350;
      navSaveOrder(group, Array.from(box.querySelectorAll('.nav-btn')).map(n => n.dataset.navId).filter(Boolean));
    }
  },
};

(function bindNavDrag() {
  if (window.__navDragBound) return;
  window.__navDragBound = true;

  document.addEventListener('pointerdown', e => {
    const reset = e.target.closest?.('[data-nav-reset]');
    if (reset) { e.preventDefault(); e.stopPropagation(); navResetOrder(reset.dataset.navReset); return; }
    const btn = e.target.closest?.('.nav-btn[data-nav-id]');
    if (!btn || e.button > 0) return;
    if (e.target.closest('.nav-drag-handle')) {
      if (NavDrag.start(btn, e.clientY)) { e.preventDefault(); btn.setPointerCapture?.(e.pointerId); }
      return;
    }
    // Touch has no hover, so there is no handle to aim at: long-press instead.
    if (e.pointerType === 'touch') {
      const y = e.clientY;
      NavDrag.pressTimer = setTimeout(() => {
        if (NavDrag.start(btn, y, true)) {
          NavDrag.moved = true;               // a deliberate long-press is already a drag
          btn.setPointerCapture?.(e.pointerId);
          if (navigator.vibrate) navigator.vibrate(12);
        }
      }, 350);
    }
  }, true);

  document.addEventListener('pointermove', e => {
    if (NavDrag.pressTimer && !NavDrag.el && Math.abs(e.clientY - NavDrag.startY) > 8) clearTimeout(NavDrag.pressTimer);
    if (!NavDrag.el || NavDrag.touch) return;   // touch is driven by touchmove below
    e.preventDefault();
    NavDrag.move(e.clientY);
  }, { passive: false });

  /* Touch is driven from the touch events, not the pointer ones. Once the finger
     moves, the browser claims the gesture as a scroll and fires pointercancel,
     which would abort a long-press drag on the very first move — so during a
     touch drag we preventDefault the scroll here and ignore pointercancel. */
  document.addEventListener('touchmove', e => {
    if (!NavDrag.el || !NavDrag.touch) return;
    e.preventDefault();
    const t = e.touches[0];
    if (t) NavDrag.move(t.clientY);
  }, { passive: false });

  document.addEventListener('pointerup', () => { clearTimeout(NavDrag.pressTimer); if (NavDrag.el) NavDrag.finish(); }, true);
  document.addEventListener('pointercancel', () => {
    clearTimeout(NavDrag.pressTimer);
    if (NavDrag.el && !NavDrag.touch) NavDrag.finish();
  }, true);
  ['touchend', 'touchcancel'].forEach(ev =>
    document.addEventListener(ev, () => { clearTimeout(NavDrag.pressTimer); if (NavDrag.el) NavDrag.finish(); }, true));

  document.addEventListener('click', e => {
    if (Date.now() < NavDrag.blockClickUntil && e.target.closest?.('.nav-btn[data-nav-id]')) {
      e.preventDefault(); e.stopPropagation();
    }
  }, true);

  // Keyboard equivalent — the drag handle is pointer-only, so this is the
  // accessible path, not an afterthought.
  document.addEventListener('keydown', e => {
    const reset = e.target.closest?.('[data-nav-reset]');
    if (reset && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); navResetOrder(reset.dataset.navReset); return; }
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    const btn = e.target.closest?.('.nav-btn[data-nav-id]');
    if (!btn) return;
    const box = btn.closest('.module-nav-items');
    if (!box || !box.id) return;
    e.preventDefault();
    const up = e.key === 'ArrowUp';
    const sib = up ? btn.previousElementSibling : btn.nextElementSibling;
    if (!sib) return;
    if (up) box.insertBefore(btn, sib); else box.insertBefore(sib, btn);
    btn.focus();
    const group = box.id.replace('nav-group-', '');
    navSaveOrder(group, Array.from(box.querySelectorAll('.nav-btn')).map(n => n.dataset.navId).filter(Boolean));
    const pos = Array.from(box.querySelectorAll('.nav-btn')).indexOf(btn) + 1;
    const live = document.getElementById('nav-live') || (() => {
      const d = document.createElement('div');
      d.id = 'nav-live'; d.className = 'sr-only'; d.setAttribute('aria-live', 'polite');
      document.body.appendChild(d); return d;
    })();
    live.textContent = `${btn.querySelector('span')?.textContent || 'Item'} moved to position ${pos} of ${box.querySelectorAll('.nav-btn').length}`;
  });
})();

function toggleNavGroup(group) {
  const el = document.getElementById('nav-group-' + group);
  if (!el) return;
  const collapsed = el.classList.toggle('collapsed');
  localStorage.setItem('vgold-nav-' + group, collapsed ? 'closed' : 'open');
  const toggle = el.previousElementSibling;
  toggle?.setAttribute('aria-expanded', String(!collapsed));
  toggle?.querySelector('.module-nav-chevron')?.classList.toggle('open', !collapsed);
}

// Clicking the logo always returns to the app home (My Tasks).
function goHome() {
  if (typeof nav === 'function') nav('mytasks');
}
window.goHome = goHome;

async function logout() {
  try {
    await API.logout();
  } catch(e) {}
  location.reload();
}
