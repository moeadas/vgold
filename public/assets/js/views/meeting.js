// VGo — Meeting Points: list + board + agenda views, filterable, 3-dot actions
async function renderMeetingPoints() {
  try { const res = await API.meetingPoints(); State.meetingData = res; } catch(e) { State.meetingData = { urgent: [], normal: [], stats: {}, byCategory: [] }; }
  try { const aRes = await API.getAgenda(); State.agendaItems = aRes.agenda || aRes.items || []; } catch(e) { State.agendaItems = []; }

  const data = State.meetingData;
  const agendaItems = State.agendaItems || [];
  const allUrgent = data.urgent || [];
  const allNormal = data.normal || [];
  const stats = data.stats || {};
  const byCategory = data.byCategory || [];
  const viewMode = State.meetingView || 'list';
  const filter = State.meetingFilter || 'all';

  // Filter items
  let urgent = allUrgent;
  let normal = allNormal;
  if (filter === 'urgent') { normal = []; }
  else if (filter === 'normal') { urgent = []; }
  else if (filter === 'in_progress') {
    urgent = urgent.filter(t => t.status === 'in_progress');
    normal = normal.filter(t => t.status === 'in_progress');
  }

  const meetingItem = (t) => `
    <div class="meeting-item" data-task-id="${t.id}">
      <div class="meeting-item-main" onclick="meetingOpenTask(${t.id},${t.project_id})" style="cursor:pointer">
        <div class="meeting-item-title">${esc(t.title)}</div>
        <div class="meeting-item-meta">
          <span style="display:flex;align-items:center;gap:6px">
            <span style="width:8px;height:8px;border-radius:99px;background:${t.project_color}"></span>
            ${esc(t.project_name)}
          </span>
          <span class="meeting-status" style="background:${t.status_color};color:#FFF">${esc(t.status_label)}</span>
          ${t.priority === 'urgent' ? '<span style="font-size:10px;font-weight:700;color:#FFF;background:#B0432B;border-radius:99px;padding:2px 7px">URGENT</span>' : ''}
          ${t.deadline_date ? `<span style="display:flex;align-items:center;gap:5px;color:${t.deadline_label.includes('Overdue') ? '#B0432B' : 'var(--muted)'};font-weight:${t.deadline_label.includes('Overdue') ? 700 : 400}">${I.clock}<span>${esc(t.deadline_label)}</span></span>` : ''}
        </div>
      </div>
      <div class="meeting-item-assignee">
        <div class="avatar avatar-md" style="background:${t.assignee_color}">${t.assignee_initials}</div>
        <span>${esc(t.assignee_name)}</span>
      </div>
      <div class="meeting-item-actions" onclick="event.stopPropagation()">
        <button class="task-row-dots" onclick="toggleMeetingMenu(${t.id}, this)">${I.dots}</button>
        <div class="task-quick-menu" id="meeting-menu-${t.id}">
          <button onclick="meetingOpenTask(${t.id},${t.project_id})">Open task</button>
          <button onclick="meetingCycleStatus(${t.id})">Change status</button>
          <button onclick="meetingDeleteTask(${t.id})" style="color:var(--red)">Delete</button>
        </div>
      </div>
    </div>
  `;

  // Board view with filter
  const filteredCategories = byCategory.map(cat => {
    let tasks = cat.tasks || [];
    if (filter === 'urgent') tasks = tasks.filter(t => t.priority === 'urgent');
    else if (filter === 'normal') tasks = tasks.filter(t => t.priority !== 'urgent');
    else if (filter === 'in_progress') tasks = tasks.filter(t => t.status === 'in_progress');
    return { ...cat, tasks };
  }).filter(cat => cat.tasks.length > 0);

  const boardHTML = filteredCategories.length ? filteredCategories.map(cat => {
    const tasks = cat.tasks || [];
    return `
      <div class="board-column">
        <div class="board-column-header">
          <span class="dot" style="background:${cat.color}"></span>
          <span class="board-column-title">${esc(cat.name)}</span>
          <span class="board-column-count">${tasks.length}</span>
        </div>
        <div class="board-column-body">
          ${tasks.map(t => `
            <div class="board-card" onclick="meetingOpenTask(${t.id},${t.project_id})">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <div class="board-card-title" style="flex:1;cursor:pointer">${esc(t.title)}</div>
                <div class="meeting-item-actions" onclick="event.stopPropagation()">
                  <button class="task-row-dots" onclick="toggleMeetingMenu(${t.id}, this)">${I.dots}</button>
                  <div class="task-quick-menu" id="meeting-menu-${t.id}">
                    <button onclick="meetingOpenTask(${t.id},${t.project_id})">Open task</button>
                    <button onclick="meetingCycleStatus(${t.id})">Change status</button>
                    <button onclick="meetingDeleteTask(${t.id})" style="color:var(--red)">Delete</button>
                  </div>
                </div>
              </div>
              <div class="board-card-meta">
                <span class="meeting-status" style="background:${t.status_color};color:#FFF;font-size:10px;padding:2px 6px">${esc(t.status_label)}</span>
                ${t.priority === 'urgent' ? '<span style="font-size:10px;font-weight:700;color:#FFF;background:#B0432B;border-radius:99px;padding:2px 7px">URGENT</span>' : ''}
              </div>
              <div class="board-card-footer">
                <span style="font-size:11px;color:var(--muted)">${esc(t.project_name)}</span>
                <div class="avatar avatar-sm" style="background:${t.assignee_color}">${t.assignee_initials}</div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }).join('') : '<div class="empty-state"><div class="title">No tasks match</div></div>';

  const chip = (key, num, label, color, active) => `
    <div class="meeting-stat-card ${active ? 'meeting-stat-active' : ''}" onclick="setMeetingFilter('${key}')" style="cursor:pointer">
      <div class="meeting-stat-num" style="color:${color}">${num}</div>
      <div class="meeting-stat-label">${label}</div>
    </div>
  `;

  // Header action button depends on the active view
  const headerAction = viewMode === 'agenda'
    ? `<button class="btn-primary" onclick="setMeetingView('agenda');setTimeout(()=>document.getElementById('agenda-title')?.focus(),50)">${I.plus}New agenda item</button>`
    : `<button class="btn" onclick="refreshMeeting()">${I.sun}Refresh</button>`;

  return `
    <div class="fade-in">
      <div class="section-label">Meeting Points</div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:20px;gap:12px;flex-wrap:wrap">
        <h1 class="page-title-sm">Team meeting agenda</h1>
        <div style="display:flex;gap:8px;align-items:center">
          <div class="view-toggle">
            <button class="view-toggle-btn ${viewMode === 'list' ? 'active' : ''}" onclick="setMeetingView('list')">List</button>
            <button class="view-toggle-btn ${viewMode === 'board' ? 'active' : ''}" onclick="setMeetingView('board')">Board</button>
            <button class="view-toggle-btn ${viewMode === 'agenda' ? 'active' : ''}" onclick="setMeetingView('agenda')">Agenda${agendaItems.length ? ` (${agendaItems.length})` : ''}</button>
          </div>
          ${headerAction}
        </div>
      </div>

      ${viewMode !== 'agenda' ? `
      <div class="meeting-stats">
        ${chip('all', stats.total || 0, 'Total points', '#7e6549', filter === 'all')}
        ${chip('urgent', stats.urgent || 0, 'Urgent items', '#B0432B', filter === 'urgent')}
        ${chip('normal', stats.normal || 0, 'Normal items', '#9A8A78', filter === 'normal')}
        ${chip('in_progress', stats.in_progress || 0, 'In progress', '#C99520', filter === 'in_progress')}
      </div>
      ` : ''}

      ${viewMode === 'list' ? `
        ${urgent.length ? `
          <div class="meeting-section meeting-urgent">
            <div class="meeting-section-header">
              <div class="meeting-priority-badge urgent">URGENT</div>
              <span class="meeting-section-count">${urgent.length} items</span>
            </div>
            <div class="meeting-list">${urgent.map(meetingItem).join('')}</div>
          </div>
        ` : ''}
        ${normal.length ? `
          <div class="meeting-section">
            <div class="meeting-section-header">
              <div class="meeting-priority-badge normal">NORMAL</div>
              <span class="meeting-section-count">${normal.length} items</span>
            </div>
            <div class="meeting-list">${normal.map(meetingItem).join('')}</div>
          </div>
        ` : ''}
        ${!urgent.length && !normal.length ? '<div class="empty-state"><div class="title">All caught up</div><div class="desc">No tasks to discuss.</div></div>' : ''}
      ` : viewMode === 'board' ? `
        <div class="board-view">${boardHTML}</div>
      ` : `
        <!-- ===== AGENDA TAB ===== -->
        ${renderAgendaTab(agendaItems)}
      `}
    </div>
  `;
}

// ===== Agenda tab body =====
function renderAgendaTab(agendaItems) {
  return `
    <div class="grid-2-proj" style="align-items:start">
      <!-- Left: current agenda list -->
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
          <h3 style="font-size:22px;font-weight:700;color:var(--gold)">Next meeting agenda</h3>
          <span style="font-size:13px;color:var(--muted)">${agendaItems.length} item${agendaItems.length === 1 ? '' : 's'}</span>
        </div>
        <div id="agenda-list-body" class="meeting-list">
          ${agendaItems.length ? agendaItems.map(agendaRow).join('') : agendaEmptyHTML()}
        </div>
      </div>

      <!-- Right: add form (sticky) -->
      <div class="card card-pad" style="border:2px solid var(--gold);border-radius:14px;position:sticky;top:16px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span style="font-size:15px;font-weight:700">${I.plus} Add agenda items</span>
        </div>
        <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px">Add as many as you like — the list updates instantly, no page reload.</p>
        <div style="display:flex;flex-direction:column;gap:12px">
          <div>
            <label class="form-label">Title *</label>
            <input class="form-input" id="agenda-title" placeholder="Discussion point..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();addAgendaItem()}">
          </div>
          <div>
            <label class="form-label">Description</label>
            <input class="form-input" id="agenda-desc" placeholder="Add details... (optional)" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();addAgendaItem()}">
          </div>
          <div>
            <label class="form-label">Assign to</label>
            <select class="form-input" id="agenda-assignee"><option value="">Unassigned</option></select>
          </div>
          <div>
            <label class="form-label">Related category</label>
            <select class="form-input" id="agenda-category" onchange="loadAgendaCategoryProjects(this.value)"><option value="">None</option></select>
          </div>
          <div id="agenda-project-wrap" style="display:none">
            <label class="form-label">Related project</label>
            <select class="form-input" id="agenda-project" onchange="loadAgendaProjectTasks(this.value)"><option value="">None</option></select>
          </div>
          <div id="agenda-task-select-wrap" style="display:none">
            <label class="form-label">Related task</label>
            <select class="form-input" id="agenda-task"><option value="">None</option></select>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:16px">
          <button class="btn-primary" onclick="addAgendaItem()">${I.plus}Add item</button>
          <span id="agenda-added-toast" style="font-size:13px;color:var(--good, #6B8E5A);opacity:0;transition:opacity .3s">Added!</span>
        </div>
      </div>
    </div>
  `;
}

function agendaEmptyHTML() {
  return '<div class="empty-state" id="agenda-empty"><div class="title">No agenda items yet</div><div class="desc">Add discussion points using the form on the right.</div></div>';
}

// A single agenda row (kept as a standalone fn so we can inject one without a full re-render)
function agendaRow(a) {
  const projClick = a.related_project_id
    ? ` onclick="event.stopPropagation();meetingGoProject(${a.related_project_id})" style="cursor:pointer;text-decoration:underline;text-underline-offset:2px"`
    : '';
  const taskClick = (a.related_task_id && a.related_project_id)
    ? ` onclick="event.stopPropagation();meetingOpenTask(${a.related_task_id},${a.related_project_id})" style="cursor:pointer;text-decoration:underline;text-underline-offset:2px;color:var(--gold)"`
    : ' style="color:var(--muted)"';
  return `
    <div class="meeting-item" id="agenda-item-${a.id}"${a.is_completed ? ' style="opacity:.55"' : ''}>
      <div class="meeting-item-main">
        <div class="meeting-item-title" style="${a.is_completed ? 'text-decoration:line-through' : ''}">${a.is_completed ? '✓ ' : ''}${esc(a.title)}</div>
        ${a.description ? `<div style="font-size:13px;color:var(--muted);margin-top:4px">${esc(a.description)}</div>` : ''}
        <div class="meeting-item-meta">
          ${a.related_project_name ? `<span style="display:flex;align-items:center;gap:6px"${projClick}><span style="width:8px;height:8px;border-radius:99px;background:${a.related_project_color || 'var(--gold)'}"></span>${esc(a.related_project_name)}</span>` : ''}
          ${a.related_task_title ? `<span style="font-size:12px"${taskClick}>→ ${esc(a.related_task_title)}</span>` : ''}
        </div>
      </div>
      <div class="meeting-item-assignee">
        ${a.assignee_name ? `<div class="avatar avatar-md" style="background:${a.assignee_color || '#9A8A78'}">${a.assignee_initials || '?'}</div><span>${esc(a.assignee_name)}</span>` : ''}
      </div>
      <div class="meeting-item-actions" onclick="event.stopPropagation()">
        <button class="task-row-dots" onclick="toggleAgendaMenu(${a.id}, this)">${I.dots}</button>
        <div class="task-quick-menu" id="agenda-menu-${a.id}">
          <button onclick="toggleAgendaComplete(${a.id}, ${a.is_completed ? 'true' : 'false'})">${a.is_completed ? 'Mark incomplete' : 'Mark complete'}</button>
          <button onclick="deleteAgendaItem(${a.id})" style="color:var(--red)">Delete</button>
        </div>
      </div>
    </div>
  `;
}

function setMeetingView(mode) { State.meetingView = mode; render(); }
function setMeetingFilter(filter) { State.meetingFilter = filter; render(); }

async function meetingCycleStatus(taskId) {
  closeAllTaskMenus();
  try {
    await API.updateTask(taskId, { _cycle: true });
    State.meetingData = null;
    State.dayPlan = undefined;
    toast('Status updated', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function meetingDeleteTask(taskId) {
  closeAllTaskMenus();
  try {
    await API.deleteTask(taskId);
    State.meetingData = null;
    State.dayPlan = undefined;
    toast('Task deleted', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

function toggleMeetingMenu(id, btn) {
  closeAllTaskMenus();
  const menu = document.getElementById('meeting-menu-' + id);
  if (menu) menu.classList.toggle('show');
}

function meetingOpenTask(taskId, projectId) {
  closeAllTaskMenus();
  State.screen = 'task';
  State.activeTaskId = taskId;
  render();
}

// Navigate to a project from an agenda item's related-project link
function meetingGoProject(projectId) {
  closeAllTaskMenus();
  if (typeof goProject === 'function') { goProject(projectId); return; }
  State.screen = 'project';
  State.activeProjectId = projectId;
  State.activeProject = null;
  render();
}

function refreshMeeting() {
  State.meetingData = null;
  render();
  toast('Meeting points refreshed', 'success');
}

// ===== Manual Meeting Agenda (in-place, no full re-render on add) =====
function initAgendaForm() {
  const sel = document.getElementById('agenda-assignee');
  if (sel && sel.options.length <= 1) {
    API.team().then(res => {
      (res.members || []).forEach(m => {
        sel.innerHTML += `<option value="${esc(m.id)}">${esc(m.name)}</option>`;
      });
    }).catch(() => {});
  }

  // Populate "Related category" with top-level categories only (keeps list short).
  const catSel = document.getElementById('agenda-category');
  if (catSel && catSel.options.length <= 1) {
    const cats = State.projects || [];
    if (cats.length) {
      catSel.innerHTML = '<option value="">None</option>' + cats.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
    } else {
      API.projects().then(res => {
        State.projects = res.projects || [];
        catSel.innerHTML = '<option value="">None</option>' + State.projects.map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
      }).catch(() => {});
    }
  }
}

// Category chosen → load its sub-projects into the project select
async function loadAgendaCategoryProjects(categoryId) {
  const projWrap = document.getElementById('agenda-project-wrap');
  const taskWrap = document.getElementById('agenda-task-select-wrap');
  const projSel = document.getElementById('agenda-project');
  const taskSel = document.getElementById('agenda-task');
  if (taskWrap) taskWrap.style.display = 'none';
  if (taskSel) taskSel.innerHTML = '<option value="">None</option>';
  if (!categoryId) { if (projWrap) projWrap.style.display = 'none'; if (projSel) projSel.innerHTML = '<option value="">None</option>'; return; }
  if (projWrap) projWrap.style.display = 'block';
  if (projSel) projSel.innerHTML = '<option value="">Loading…</option>';
  try {
    const res = await API.category(categoryId);
    const subs = (res.category && res.category.projects) || [];
    if (projSel) projSel.innerHTML = '<option value="">None</option>' + subs.map(sp => `<option value="${esc(sp.id)}">${esc(sp.name)}</option>`).join('');
  } catch(e) { if (projSel) projSel.innerHTML = '<option value="">None</option>'; }
}

// Project chosen → load its tasks into the task select
async function loadAgendaProjectTasks(projectId) {
  const wrap = document.getElementById('agenda-task-select-wrap');
  const sel = document.getElementById('agenda-task');
  if (!projectId) { if (wrap) wrap.style.display = 'none'; if (sel) sel.innerHTML = '<option value="">None</option>'; return; }
  if (wrap) wrap.style.display = 'block';
  if (sel) sel.innerHTML = '<option value="">Loading…</option>';
  try {
    const res = await API.project(projectId);
    const tasks = (res.project.groups || []).flatMap(g => g.tasks || []);
    if (sel) sel.innerHTML = '<option value="">None</option>' + tasks.map(t => `<option value="${t.id}">${esc(t.title)}</option>`).join('');
  } catch(e) { if (sel) sel.innerHTML = '<option value="">None</option>'; }
}

async function addAgendaItem() {
  const titleEl = document.getElementById('agenda-title');
  const title = titleEl?.value.trim();
  if (!title) { toast('Title is required', 'error'); if (titleEl) titleEl.focus(); return; }
  const desc = document.getElementById('agenda-desc')?.value.trim() || '';
  const assignee = document.getElementById('agenda-assignee')?.value || null;
  const project = document.getElementById('agenda-project')?.value || null;
  const task = document.getElementById('agenda-task')?.value || null;
  try {
    const res = await API.createAgenda({ title, description: desc, assigned_to: assignee || null, related_project_id: project || null, related_task_id: task || null });
    // Prefer the full item returned by the API so we can append it in place.
    const newItem = res && res.item && res.item.id ? res.item : null;
    const listBody = document.getElementById('agenda-list-body');

    if (newItem) {
      // Fast path: append the single new row without touching the rest.
      State.agendaItems = [...(State.agendaItems || []), newItem];
      if (listBody) {
        const empty = document.getElementById('agenda-empty');
        if (empty) empty.remove();
        const tmp = document.createElement('div');
        tmp.innerHTML = agendaRow(newItem).trim();
        const node = tmp.firstElementChild;
        if (node) { listBody.appendChild(node); node.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
      }
    } else {
      // Fallback: refetch the list and rebuild the list body in place.
      try { const a = await API.getAgenda(); State.agendaItems = a.agenda || a.items || []; }
      catch(e) { State.agendaItems = State.agendaItems || []; }
      const items = State.agendaItems || [];
      if (listBody) {
        listBody.innerHTML = items.length ? items.map(agendaRow).join('') : agendaEmptyHTML();
      }
    }

    // Update the tab counter without re-rendering
    updateAgendaTabCount();

    // Clear title + desc but keep assignee/category/project for fast multi-add
    if (titleEl) titleEl.value = '';
    const descEl = document.getElementById('agenda-desc');
    if (descEl) descEl.value = '';
    // Show inline confirmation
    const toastEl = document.getElementById('agenda-added-toast');
    if (toastEl) { toastEl.style.opacity = '1'; setTimeout(() => { toastEl.style.opacity = '0'; }, 1800); }
    if (titleEl) titleEl.focus();
  } catch(e) { toast(e.message, 'error'); }
}

// Keep the "Agenda (N)" tab label in sync after an in-place add/delete
function updateAgendaTabCount() {
  const n = (State.agendaItems || []).length;
  const btns = document.querySelectorAll('.view-toggle .view-toggle-btn');
  btns.forEach(b => {
    if (b.textContent.trim().startsWith('Agenda')) {
      b.textContent = 'Agenda' + (n ? ` (${n})` : '');
    }
  });
}

function toggleAgendaMenu(id, btn) {
  closeAllTaskMenus();
  const menu = document.getElementById('agenda-menu-' + id);
  if (menu) menu.classList.toggle('show');
}

async function toggleAgendaComplete(id, isCompleted) {
  closeAllTaskMenus();
  try {
    await API.updateAgenda(id, { completed: !isCompleted });
    // Update local state + re-render just this row in place
    const items = State.agendaItems || [];
    const it = items.find(x => x.id === id);
    if (it) { it.is_completed = !isCompleted; }
    const rowEl = document.getElementById('agenda-item-' + id);
    if (rowEl && it) {
      const tmp = document.createElement('div');
      tmp.innerHTML = agendaRow(it).trim();
      const node = tmp.firstElementChild;
      if (node) rowEl.replaceWith(node);
    } else {
      render();
    }
  } catch(e) { toast(e.message, 'error'); }
}

// ===== ADD TO AGENDA FROM EXTERNAL PAGES =====
async function addToAgendaFromTask(taskId, taskTitle, projectId) {
  try {
    await API.createAgenda({ 
      title: taskTitle, 
      related_task_id: taskId, 
      related_project_id: projectId 
    });
    toast('Added to meeting agenda', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

async function addToAgendaFromProject(projectId, projectName) {
  try {
    await API.createAgenda({ 
      title: 'Discuss: ' + projectName, 
      related_project_id: projectId 
    });
    toast('Added to meeting agenda', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

async function deleteAgendaItem(id) {
  closeAllTaskMenus();
  appConfirm('Delete this agenda point?', async () => {
    try {
      await API.deleteAgenda(id);
      State.agendaItems = (State.agendaItems || []).filter(x => x.id !== id);
      // Remove row in place
      const rowEl = document.getElementById('agenda-item-' + id);
      if (rowEl) rowEl.remove();
      // If list is now empty, show empty state
      const listBody = document.getElementById('agenda-list-body');
      if (listBody && !(State.agendaItems || []).length && !document.getElementById('agenda-empty')) {
        listBody.innerHTML = agendaEmptyHTML();
      }
      updateAgendaTabCount();
      toast('Agenda item deleted', 'success');
    } catch(e) { toast(e.message, 'error'); }
  });
}
