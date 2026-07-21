// VGo — Main App Controller
const State = {
  screen: 'mytasks',
  user: null,
  projects: null,
  activeProjectId: null,
  activeProject: null,
  activeCategoryId: null,
  activeCategory: null,
  activeChannel: null,
  channelMessages: null,
  channelName: null,
  todayData: null,
  myTasksFilter: null,
  taskOverviewFilter: null,
  channels: null,
  teamData: null,
  notifSettings: null,
  notifCount: 0,
  notifPanel: false,
  apiKeys: null,
  aiProviders: null,
  askOpen: false,
  activeTaskId: null,
  activeTaskProjectId: null,
  taskOverviewSubView: 'tasks',
  filesOpen: false,
  unreadCounts: null,
  msgUnreadTotal: 0,
  mentions: null,
  commentsFeed: null,
  commentsUnread: 0,
  viewComments: false,
  cardOrders: null,
};

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function linkify(s) {
  const esc = String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  return esc.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener" style="color:var(--gold-dark);text-decoration:underline">$1</a>');
}

// Allowlist HTML sanitizer for AI-generated / server HTML fragments (XSS defense, H4).
// Parses the fragment, drops any tag/attribute not on the allowlist, blocks
// javascript:/data: URLs and all inline event handlers, then returns safe HTML.
function sanitizeHtml(dirty) {
  const ALLOWED_TAGS = new Set(['A','B','I','EM','STRONG','P','BR','UL','OL','LI','H1','H2','H3','H4','H5','H6','CODE','PRE','BLOCKQUOTE','SPAN','DIV','HR','TABLE','THEAD','TBODY','TR','TH','TD']);
  const ALLOWED_ATTR = { 'A': ['href','target','rel','class','data-type','data-id'], '*': ['class'] };
  const template = document.createElement('template');
  template.innerHTML = String(dirty ?? '');

  const walk = (node) => {
    // Iterate over a static copy since we mutate during traversal
    Array.from(node.childNodes).forEach(child => {
      if (child.nodeType === 1) { // element
        const tag = child.tagName;
        if (!ALLOWED_TAGS.has(tag)) {
          // Unwrap disallowed element: keep its (sanitized) children, drop the tag
          walk(child);
          while (child.firstChild) node.insertBefore(child.firstChild, child);
          node.removeChild(child);
          return;
        }
        // Scrub attributes
        const allowed = (ALLOWED_ATTR[tag] || []).concat(ALLOWED_ATTR['*'] || []);
        Array.from(child.attributes).forEach(attr => {
          const name = attr.name.toLowerCase();
          if (name.startsWith('on') || !allowed.includes(name)) {
            child.removeAttribute(attr.name);
          }
        });
        // Neutralize dangerous URLs on <a href>
        if (tag === 'A' && child.hasAttribute('href')) {
          const href = (child.getAttribute('href') || '').trim();
          if (/^\s*(javascript|data|vbscript):/i.test(href)) {
            child.removeAttribute('href');
          } else if (/^https?:/i.test(href)) {
            child.setAttribute('rel', 'noopener noreferrer');
            child.setAttribute('target', '_blank');
          }
        }
        walk(child);
      } else if (child.nodeType === 8) { // comment
        node.removeChild(child);
      }
    });
  };
  walk(template.content);
  return template.innerHTML;
}

function getGreeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

function nav(screen) {
  State.screen = screen;
  State.activeProjectId = null;
  State.activeProject = null;
  if (screen === 'projects') State.unreadCounts = null;
  State.activeCategoryId = null;
  updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
  closeMobileSidebar();
}

function toggleMobileSidebar() {
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) return;
  const isOpen = sidebar.classList.contains('sidebar-open');
  if (isOpen) {
    closeMobileSidebar();
  } else {
    sidebar.classList.add('sidebar-open');
    let overlay = document.getElementById('mobile-sidebar-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'mobile-sidebar-overlay';
      overlay.className = 'mobile-sidebar-overlay';
      overlay.addEventListener('click', closeMobileSidebar);
      document.body.appendChild(overlay);
    }
    requestAnimationFrame(() => overlay.classList.add('visible'));
  }
}

function closeMobileSidebar() {
  const sidebar = document.querySelector('.sidebar');
  if (sidebar) sidebar.classList.remove('sidebar-open');
  const overlay = document.getElementById('mobile-sidebar-overlay');
  if (overlay) overlay.classList.remove('visible');
}

// Close sidebar on navigation
window.addEventListener('popstate', closeMobileSidebar);

function goProject(id) {
  State.screen = 'project';
  State.activeProjectId = id;
  State.activeProject = null;
  render();
  setTimeout(() => updateHash(), 200); // Update hash after project loads so we have the name
}

async function render() {
  const app = document.getElementById('app');
  if (!State.user) {
    try {
      const res = await API.me();
      State.user = res.user;
      window.__authProvider = res.user.auth_provider || 'password';
    } catch(e) {
      renderLogin();
      return;
    }
  }

  // Load projects for sidebar
  if (!State.projects) {
    API.projects().then(res => { State.projects = res.projects; if (State.screen !== 'login') render(); }).catch(() => {});
  }

  let mainContent = '';
  try {
    switch (State.screen) {
      case 'taskoverview': mainContent = await renderTaskOverview(); break;
      case 'mytasks': mainContent = await renderMyTasks(); break;
      case 'projects': mainContent = await renderProjects(); break;
      case 'category': mainContent = await renderCategory(); break;
      case 'project': mainContent = await renderProject(); break;
      case 'messages': mainContent = await renderMessages(); break;
      case 'task': mainContent = await renderTaskPage(State.activeTaskId); break;
      case 'settings': mainContent = await renderSettings(); break;
      default: mainContent = await renderMyTasks();
    }
  } catch(e) {
    // A3: don't leak internal error details to users; log for debugging instead.
    console.error('View render error:', e);
    mainContent = `<div class="fade-in" role="alert"><div class="card card-pad" style="max-width:480px;margin:40px auto;text-align:center">
      <p style="color:var(--barn);font-weight:700;font-size:16px;margin-bottom:6px">Something went wrong</p>
      <p style="color:var(--muted);font-size:14px;margin-bottom:16px">We couldn't load this page. Please try again.</p>
      <button class="btn-primary" onclick="render()">Retry</button>
    </div></div>`;
  }

  const today = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
  app.innerHTML = `
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app">
      ${renderSidebar()}
      <main class="main">
        <div class="topbar" role="banner">
          <button class="mobile-menu-btn" onclick="toggleMobileSidebar()" aria-label="Open menu">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
          </button>
          <div class="topbar-date">${today}</div>
          <div class="topbar-search" role="search">
            <label for="global-search" class="sr-only">Search projects, tasks, and files</label>
            <input type="text" id="global-search" placeholder="Search projects, tasks, files…" role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list" autocomplete="off" oninput="doSearch(this.value)" onfocus="showSearchResults()" onkeydown="handleSearchKeydown(event)" onblur="setTimeout(()=>hideSearchResults(),200)">
            <div id="search-results" class="search-results" role="listbox" aria-label="Search results"></div>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <div style="display:flex;align-items:center;gap:6px;position:relative">
            <button class="notif-btn" id="notif-btn" onclick="toggleNotifPanel()" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-controls="notif-panel" style="background:none;border:none;cursor:pointer;position:relative;padding:7px;border-radius:10px;color:var(--text-2);display:flex;align-items:center;justify-content:center;transition:background .15s">
              ${I.bell}
              <span id="notif-badge" style="display:none;position:absolute;top:2px;right:2px;background:#B0432B;color:#FFF;font-size:10px;font-weight:700;border-radius:99px;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:0 4px;line-height:1">${State.notifCount}</span>
            </button>
            <div class="notif-panel" id="notif-panel" role="menu" aria-label="Notifications" style="display:none">
              <div class="notif-panel-header">
                <span style="font-weight:700;font-size:14px">Notifications</span>
                <button onclick="markAllNotifsRead()" style="background:none;border:none;cursor:pointer;font-size:12px;color:var(--gold);font-weight:700">Mark all read</button>
              </div>
              <div class="notif-list" id="notif-list"></div>
            </div>
          </div>
            <button class="ask-btn" onclick="openAsk()" aria-label="Ask VGo AI assistant">${I.sparkle}<span>Ask VGo</span><span class="kbd" aria-hidden="true">⌘K</span></button>
          </div>
        </div>
        <div class="content" id="main-content" role="main" tabindex="-1">${mainContent}</div>
      </main>
    </div>
    <nav class="mobile-nav" id="mobile-nav">
      <button class="mobile-nav-btn ${State.screen === 'mytasks' ? 'active' : ''}" data-nav="mytasks">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        <span>Tasks</span>
      </button>
      <button class="mobile-nav-btn ${State.screen === 'projects' || State.screen === 'category' || State.screen === 'project' ? 'active' : ''}" data-nav="projects">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1h-7.93a1 1 0 0 1-.84-.46l-.7-1.08A1 1 0 0 0 8.79 5H4a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1Z"/></svg>
        <span>Projects</span>
      </button>
      <button class="mobile-nav-btn ${State.screen === 'messages' ? 'active' : ''}" data-nav="messages">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
        <span>Messages</span>
      </button>
      <button class="mobile-nav-btn ${State.screen === 'settings' ? 'active' : ''}" data-nav="settings">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12.2 2h-.4a2 2 0 0 0-2 2 1.7 1.7 0 0 1-2.6 1.5 2 2 0 0 0-2.7.7l-.2.4a2 2 0 0 0 .7 2.7 1.7 1.7 0 0 1 0 3 2 2 0 0 0-.7 2.7l.2.4a2 2 0 0 0 2.7.7A1.7 1.7 0 0 1 9.8 20a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2 1.7 1.7 0 0 1 2.6-1.5 2 2 0 0 0 2.7-.7l.2-.4a2 2 0 0 0-.7-2.7 1.7 1.7 0 0 1 0-3 2 2 0 0 0 .7-2.7l-.2-.4a2 2 0 0 0-2.7-.7A1.7 1.7 0 0 1 14.2 4a2 2 0 0 0-2-2Z"/></svg>
        <span>Settings</span>
      </button>
    </nav>
    ${renderAskModal()}
    ${renderFilesModal()}
  `;
  
  // Wire up day plan links after render
  if (State.screen === 'mytasks') wireDayPlanLinks();
  // Wire up task page assignee picker after render
  if (State.screen === 'task') renderTaskPageAssigneePicker();
  // Wire up agenda form assignee dropdown after render
  // B6: enable per-user drag-to-arrange on the project/category card grids.
  if (State.screen === 'projects' && typeof initCardDragging === 'function') {
    initCardDragging('.project-grid', 0);
  }
  if (State.screen === 'category' && typeof initCardDragging === 'function' && State.activeCategoryId) {
    initCardDragging('.project-grid', State.activeCategoryId);
  }

}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    if (State.askOpen) closeAsk();
    else openAsk();
  }
  if (e.key === 'Escape' && State.askOpen) closeAsk();
  if (e.key === 'Escape' && State.filesOpen) closeFiles();
});

// Boot
// Parse hash to determine screen
function slugify(name) {
  return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

// Map of slug -> id (populated when projects load)
function buildSlugMap() {
  const map = { categories: {}, projects: {} };
  if (State.projects) {
    State.projects.forEach(p => { map.categories[slugify(p.name)] = p.id; });
  }
  // Also check sub-projects from activeCategory
  if (State.activeCategory && State.activeCategory.projects) {
    State.activeCategory.projects.forEach(p => { map.projects[slugify(p.name)] = p.id; });
  }
  return map;
}

function resolveSlug(type, slug) {
  // First try numeric
  if (/^\d+$/.test(slug)) return parseInt(slug);
  // Try slug map
  const map = buildSlugMap();
  if (map[type] && map[type][slug]) return map[type][slug];
  return null;
}

function routeFromHash() {
  const hash = location.hash.replace('#', '');
  const parts = hash.split('/');
  if (!hash) { State.screen = 'mytasks'; State.activeProjectId = null; State.activeProject = null; State.activeCategoryId = null; }
  else if (hash === 'projects') { State.screen = 'projects'; State.activeProjectId = null; State.activeProject = null; State.activeCategoryId = null; }
  else if (hash === 'taskoverview' || hash === 'alltasks') { State.screen = 'taskoverview'; State.activeProjectId = null; State.activeProject = null; }
  else if (hash === 'mytasks') { State.screen = 'mytasks'; State.activeProjectId = null; State.activeProject = null; }
  else if (hash === 'messages') { State.screen = 'messages'; State.activeProjectId = null; State.activeProject = null; }
  else if (hash === 'settings') { State.screen = 'settings'; State.activeProjectId = null; State.activeProject = null; }
  else if (parts[0] === 'project' && parts[1]) { 
    const id = resolveSlug('projects', parts[1]);
    if (id) { State.screen = 'project'; State.activeProjectId = id; State.activeProject = null; }
    else { State.screen = 'projects'; } // fallback if slug not resolved yet
  }
  else if (parts[0] === 'category' && parts[1]) { 
    const id = resolveSlug('categories', parts[1]);
    if (id) { State.screen = 'category'; State.activeCategoryId = id; State.activeCategory = null; }
    else { State.screen = 'projects'; } // fallback if slug not resolved yet
  }
  else if (parts[0] === 'task' && parts[1]) {
    const id = parseInt(parts[1]);
    if (id) { State.screen = 'task'; State.activeTaskId = id; }
    else { State.screen = 'mytasks'; }
  }
  else { State.screen = 'mytasks'; }
}

// Update hash when navigating
function updateHash() {
  let hash = State.screen;
  if (State.screen === 'task' && State.activeTaskId) {
    hash = 'task/' + State.activeTaskId;
  } else if (State.screen === 'project' && State.activeProject) {
    hash = 'project/' + slugify(State.activeProject.name || State.activeProjectId);
  } else if (State.screen === 'project' && State.activeProjectId) {
    hash = 'project/' + State.activeProjectId;
  } else if (State.screen === 'category' && State.activeCategory) {
    hash = 'category/' + slugify(State.activeCategory.name || State.activeCategoryId);
  } else if (State.screen === 'category' && State.activeCategoryId) {
    // Find category name from cached projects
    const cat = (State.projects || []).find(p => p.id === State.activeCategoryId);
    hash = 'category/' + (cat ? slugify(cat.name) : State.activeCategoryId);
  }
  if (location.hash !== '#' + hash) history.pushState(null, '', '#' + hash);
}

window.addEventListener('popstate', () => { routeFromHash(); render(); });
window.addEventListener('hashchange', () => { routeFromHash(); render(); });

// Mobile nav event delegation (survives re-renders)
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.mobile-nav-btn');
  if (btn && btn.dataset.nav) {
    nav(btn.dataset.nav);
  }
});

document.addEventListener('DOMContentLoaded', () => {
  fetch('/api/auth/me', { credentials: 'same-origin' }).then(r => {
    if (r.ok) return r.json();
    throw new Error('not logged in');
  }).then(res => {
    State.user = res.user;
    if (res.csrf_token) API.csrf = res.csrf_token; // CSRF token for state-changing requests (H5)
    window.__authProvider = res.user.auth_provider || 'password';
    // B1: honor the user's preferred default landing screen when no hash is present.
    if (!location.hash && res.user.default_screen) State.screen = res.user.default_screen;
    // Load projects first so slug-based routing works on initial load
    API.projects().then(projRes => {
      State.projects = projRes.projects;
      routeFromHash(); // Parse hash before first render
      render();
      loadNotifCount();
      loadMsgUnread();
      setInterval(loadNotifCount, 60000);
      setInterval(loadMsgUnread, 45000);
      startRealtimePolling();
      initPushNotifications();
    }).catch(() => {
      routeFromHash();
      render();
    });
  }).catch(() => {
    renderLogin();
  });
});

// ===== REAL-TIME UPDATES (B3) =====
// Lightweight polling of /state-version. When the fingerprint changes (a project,
// task, or project chat message was created/updated by anyone), we invalidate the
// relevant caches and re-render the current view so all users stay in sync without
// a manual refresh.
let _lastStateVersion = null;
let _realtimeTimer = null;
function startRealtimePolling() {
  if (_realtimeTimer) return;
  const tick = async () => {
    if (!State.user || document.hidden) return;
    try {
      const res = await API.stateVersion();
      const v = res.version;
      if (_lastStateVersion === null) { _lastStateVersion = v; return; }
      if (v !== _lastStateVersion) {
        _lastStateVersion = v;
        applyRealtimeRefresh();
      }
    } catch(e) {}
  };
  _realtimeTimer = setInterval(tick, 12000);
  // Refresh immediately when the tab regains focus.
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
  tick();
}

// Invalidate caches for data that peers can change, then re-render the active view.
function applyRealtimeRefresh() {
  // Editing text in a field? Don't yank the DOM out from under the user.
  const ae = document.activeElement;
  if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable)) return;

  State.projects = null;
  State.unreadCounts = null;
  const s = State.screen;
  if (s === 'project') State.activeProject = null;
  if (s === 'category') State.activeCategory = null;
  // Keep the Messages nav badge fresh too.
  loadMsgUnread();
  if (['projects', 'category', 'project', 'mytasks', 'taskoverview'].includes(s)) {
    render();
  }
}

// ===== BROWSER PUSH NOTIFICATIONS =====
async function initPushNotifications() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  try {
    await navigator.serviceWorker.register('/sw.js');
    if (Notification.permission === 'granted') {
      await subscribeForPush();
    } else if (Notification.permission === 'default') {
      // Auto-request permission on boot if not yet decided
      const perm = await Notification.requestPermission();
      if (perm === 'granted') {
        await subscribeForPush();
      }
    }
  } catch(e) {}
}

async function subscribeForPush() {
  const reg = await navigator.serviceWorker.register('/sw.js');
  await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(window.VAPID_PUBLIC),
  });
  const json = sub.toJSON();
  await API.subscribePush({ endpoint: json.endpoint, keys: json.keys });
}

async function enableNotifications() {
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    toast('Notifications are not supported on this device/browser', 'error');
    return;
  }
  // iOS requires PWA install — detect and warn
  const isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (isIOS && !isStandalone) {
    toast('On iPhone: tap Share → Add to Home Screen, then open VGo from your home screen to enable notifications', 'error');
    return;
  }
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') { toast('Notifications blocked', 'error'); return; }
  try {
    await subscribeForPush();
    toast('Notifications enabled', 'success');
  } catch(e) { console.error('Push subscription failed:', e); toast('Could not enable notifications. Please try again.', 'error'); }
}

function urlBase64ToUint8Array(base64) {
  const padding = '='.repeat((4 - base64.length % 4) % 4);
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(b64);
  return new Uint8Array([...raw].map(c => c.charCodeAt(0)));
}

// Listen for push in service worker
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'NOTIFICATION') {
      State.notifCount++;
      updateNotifBadge();
      if (Notification.permission === 'granted') {
        new Notification(e.data.title, { body: e.data.body, icon: '/assets/img/vgo-logo.png' });
      }
    }
  });
}

// ===== AJAX SEARCH =====
let searchTimer = null;
function doSearch(q) {
  clearTimeout(searchTimer);
  if (q.trim().length < 1) { hideSearchResults(); return; }
  searchTimer = setTimeout(() => fetchSearch(q.trim()), 200);
}

async function fetchSearch(q) {
  try {
    const res = await fetch('/api/search?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
    const data = await res.json();
    renderSearchResults(data.results || []);
  } catch(e) { }
}

function renderSearchResults(groups) {
  const el = document.getElementById('search-results');
  if (!el) return;
  if (!groups.length) { el.innerHTML = '<div class="search-empty">No results found</div>'; el.classList.add('show'); setSearchExpanded(true); return; }
  el.innerHTML = groups.map(g => `
    <div class="search-group" role="group" aria-label="${esc(g.group)}">
      <div class="search-group-label">${esc(g.group)}</div>
      ${g.items.map(item => `
        <div class="search-item search-result-item" role="option" tabindex="-1" onclick="clickSearchResult(${item.type === 'task' ? `'task', ${item.id}, ${item.project_id}` : `'${item.type}', ${item.id}`})">
          <div class="search-item-icon" aria-hidden="true">${item.type === 'task' ? I.check : item.type === 'file' ? I.file : I.folder}</div>
          <div style="min-width:0;flex:1">
            <div class="search-item-title">${esc(item.title)}</div>
            <div class="search-item-meta">${esc(item.meta)}${item.priority === 'urgent' ? ' · urgent' : ''}</div>
          </div>
        </div>
      `).join('')}
    </div>
  `).join('');
  el.classList.add('show');
  setSearchExpanded(true);
}

function setSearchExpanded(expanded) {
  const input = document.getElementById('global-search');
  if (input) input.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

function showSearchResults() {
  const el = document.getElementById('search-results');
  if (el && el.innerHTML.trim()) { el.classList.add('show'); setSearchExpanded(true); }
}

function hideSearchResults() {
  const el = document.getElementById('search-results');
  if (el) el.classList.remove('show');
  setSearchExpanded(false);
}

// A5 — keyboard navigation for the search combobox (Arrow keys, Enter, Escape)
function handleSearchKeydown(e) {
  const el = document.getElementById('search-results');
  if (!el || !el.classList.contains('show')) return;
  const items = Array.from(el.querySelectorAll('.search-result-item'));
  if (!items.length) return;
  const current = items.findIndex(i => i.classList.contains('kb-active'));

  if (e.key === 'ArrowDown') {
    e.preventDefault();
    const next = current < items.length - 1 ? current + 1 : 0;
    focusSearchItem(items, next);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    const prev = current > 0 ? current - 1 : items.length - 1;
    focusSearchItem(items, prev);
  } else if (e.key === 'Enter') {
    if (current >= 0) { e.preventDefault(); items[current].click(); }
  } else if (e.key === 'Escape') {
    hideSearchResults();
    e.target.blur();
  }
}

function focusSearchItem(items, idx) {
  items.forEach(i => i.classList.remove('kb-active'));
  const item = items[idx];
  if (item) {
    item.classList.add('kb-active');
    item.scrollIntoView({ block: 'nearest' });
  }
}

function clickSearchResult(type, id, projectId) {
  hideSearchResults();
  document.getElementById('global-search').value = '';
  if (type === 'task') navigateToTask(id, projectId);
  else if (type === 'project') { goProject(id); }
}

// ===== NOTIFICATIONS =====
async function loadNotifCount() {
  if (!State.user) return;
  try {
    const res = await API.unreadCount();
    State.notifCount = res.count;
    updateNotifBadge();
  } catch(e) {}
}

// B8 — total unread across Direct Messages + Comments + Mentions, shown on the
// "Messages" nav item. Cheap enough to poll alongside the notification count.
async function loadMsgUnread() {
  if (!State.user) return;
  try {
    let dm = 0, comments = 0, mentions = 0;
    // DM/group-DM unread total (channels endpoint returns dm_unread_total)
    try {
      const ch = await API.channels();
      State.channels = ch;
      dm = ch.dm_unread_total || 0;
    } catch(e) {}
    // Comments feed unread
    try {
      const cf = await API.commentsFeed();
      State.commentsFeed = cf.comments || [];
      State.commentsUnread = cf.unread || 0;
      comments = cf.unread || 0;
    } catch(e) {}
    // Mentions (unread notifications of type mention)
    try {
      const res = await API.req('/notifications');
      const all = res.notifications || [];
      const ms = all.filter(n => n.type === 'mention' || (n.body && n.body.includes('@')));
      State.mentions = ms;
      mentions = ms.filter(n => !n.is_read).length;
    } catch(e) {}
    State.msgUnreadTotal = dm + comments + mentions;
    updateMsgBadge();
  } catch(e) {}
}

function updateMsgBadge() {
  const badge = document.getElementById('nav-msg-badge');
  if (!badge) return;
  if (State.msgUnreadTotal > 0) {
    badge.textContent = State.msgUnreadTotal > 99 ? '99+' : State.msgUnreadTotal;
    badge.style.display = 'flex';
  } else {
    badge.style.display = 'none';
  }
}

function updateNotifBadge() {
  const badge = document.getElementById('notif-badge');
  if (!badge) return;
  if (State.notifCount > 0) {
    badge.textContent = State.notifCount > 99 ? '99+' : State.notifCount;
    badge.style.display = 'flex';
  } else {
    badge.style.display = 'none';
  }
}

async function toggleNotifPanel() {
  const panel = document.getElementById('notif-panel');
  const btn = document.getElementById('notif-btn');
  if (!panel) return;
  State.notifPanel = !State.notifPanel;
  panel.style.display = State.notifPanel ? 'block' : 'none';
  if (btn) btn.setAttribute('aria-expanded', State.notifPanel ? 'true' : 'false');
  if (State.notifPanel) {
    await loadNotifList();
    document.addEventListener('click', closeNotifPanelOutside, { once: true });
    document.addEventListener('keydown', closeNotifPanelOnEsc);
    // Move focus into the panel for keyboard users
    const firstItem = panel.querySelector('.notif-item, button');
    if (firstItem) firstItem.focus();
  } else {
    document.removeEventListener('keydown', closeNotifPanelOnEsc);
  }
}

function setNotifClosed() {
  const panel = document.getElementById('notif-panel');
  const btn = document.getElementById('notif-btn');
  if (panel) panel.style.display = 'none';
  if (btn) btn.setAttribute('aria-expanded', 'false');
  State.notifPanel = false;
  document.removeEventListener('keydown', closeNotifPanelOnEsc);
}

function closeNotifPanelOnEsc(e) {
  if (e.key === 'Escape') {
    setNotifClosed();
    const btn = document.getElementById('notif-btn');
    if (btn) btn.focus();
  }
}

function closeNotifPanelOutside(e) {
  const panel = document.getElementById('notif-panel');
  const btn = document.getElementById('notif-btn');
  if (panel && !panel.contains(e.target) && !btn.contains(e.target)) {
    setNotifClosed();
  }
}

async function loadNotifList() {
  try {
    const res = await API.notifications();
    const list = document.getElementById('notif-list');
    if (!res.notifications || !res.notifications.length) {
      list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:14px">No notifications yet</div>';
      return;
    }
    list.innerHTML = res.notifications.map(n => `
      <div class="notif-item ${n.is_read ? 'read' : 'unread'}" role="menuitem" tabindex="0" onclick="handleNotifClick('${esc(n.link_type)}', ${n.link_id || 0}, ${n.project_id || 0}); markNotifRead(${n.id})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <div class="notif-dot ${n.is_read ? '' : 'active'}" aria-hidden="true"></div>
        <div style="flex:1;min-width:0">
          <div class="notif-title">${esc(n.title)}</div>
          <div class="notif-body">${esc(n.body)}</div>
          <div class="notif-time">${esc(n.time_ago)}</div>
        </div>
      </div>
    `).join('');
  } catch(e) {}
}

function handleNotifClick(type, id, projectId) {
  if (!type || !id) return;
  toggleNotifPanel();
  if (type === 'task') navigateToTask(id, projectId);
  else if (type === 'project') goProject(id);
}

async function markNotifRead(id) {
  try { await API.markRead(id); State.notifCount = Math.max(0, State.notifCount - 1); updateNotifBadge(); } catch(e) {}
}

async function markAllNotifsRead() {
  try {
    await API.markAllRead();
    State.notifCount = 0;
    updateNotifBadge();
    loadNotifList();
  } catch(e) {}
}
