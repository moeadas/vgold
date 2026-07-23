// VGo — Task Overview: All Tasks + User Cards + Unified Meeting Agenda
let _allTasksData = null;
let _taskOverviewFilter = null; // null = all users, or user id

async function renderTaskOverview() {
  if (!State.taskOverviewSubView) State.taskOverviewSubView = 'tasks';
  // Fetch all tasks data
  if (!_allTasksData) {
    try {
      const res = await API.allTasks();
      _allTasksData = res;
    } catch (e) {
      return `<div class="fade-in"><p style="color:var(--barn)">Failed to load tasks.</p></div>`;
    }
  }

  // Fetch agenda
  let agendaItems = [];
  try {
    const aRes = await API.getAgenda();
    agendaItems = aRes.agenda || aRes.items || [];
    State.agendaItems = agendaItems;
  } catch(e) { State.agendaItems = []; }

  const tasks = _allTasksData.tasks || [];
  const users = _allTasksData.users || [];

  // Apply user filter
  const filteredTasks = _taskOverviewFilter !== null
    ? tasks.filter(t => {
        const user = users.find(u => u.id === _taskOverviewFilter);
        if (!user) return false;
        return (t.assignees || []).some(a => a.id === _taskOverviewFilter) ||
               t.assignee_name === user.name;
      })
    : tasks;

  // Group by status
  const groups = {
    in_progress: { label: 'In Progress', color: '#C99520', tasks: [] },
    completed: { label: 'Completed', color: '#6B8E5A', tasks: [] },
  };
  filteredTasks.forEach(t => { if (groups[t.status]) groups[t.status].tasks.push(t); });

  // User cards row
  const userCardsHTML = renderUserCards(users, tasks);

  // Task groups
  const groupsHTML = Object.values(groups).filter(g => g.tasks.length > 0).map(g => `
    <div class="task-group">
      <div class="task-group-header">
        <span class="dot" style="background:${g.color}"></span>
        <span class="label">${g.label}</span>
        <span class="count">${g.tasks.length}</span>
      </div>
      <div class="task-list">
        ${g.tasks.map(t => overviewTaskRowHTML(t)).join('')}
      </div>
    </div>
  `).join('') || '<div class="empty-state"><div class="title">No tasks</div><div class="desc">No tasks match the current filter.</div></div>';

  // Agenda section (only rendered in agenda sub-view or inline in tasks sub-view)
  const agendaHTML = renderUnifiedAgenda(agendaItems);

  // View toggle
  const toggleHTML = `
    <div class="view-toggle" style="margin-bottom:20px">
      <button class="view-toggle-btn ${State.taskOverviewSubView === 'tasks' ? 'active' : ''}" onclick="setTaskOverviewSubView('tasks')">All Tasks</button>
      <button class="view-toggle-btn ${State.taskOverviewSubView === 'agenda' ? 'active' : ''}" onclick="setTaskOverviewSubView('agenda')">Agenda</button>
    </div>
  `;

  let contentHTML;
  if (State.taskOverviewSubView === 'agenda') {
    // Agenda-only full page view
    contentHTML = agendaHTML;
  } else {
    // Tasks view (no agenda at bottom)
    contentHTML = `
      <!-- User filter cards -->
      ${userCardsHTML}

      <!-- All Tasks -->
      <div style="font-size:18px;font-weight:700;color:var(--gold);margin-bottom:14px">All Tasks</div>
      ${groupsHTML}
    `;
  }

  return `
    <div class="fade-in">
      <div class="section-label">Task Overview</div>
      <h1 class="page-title-sm" style="margin-bottom:20px">Workspace task overview</h1>

      ${toggleHTML}

      ${contentHTML}
    </div>
  `;
}

// ===== USER CARDS =====
function renderUserCards(users, allTasks) {
  const totalTasks = allTasks.length;
  const allCard = `
    <div class="user-card ${_taskOverviewFilter === null ? 'user-card-active' : ''}" onclick="filterTaskOverviewByUser(null)">
      <div class="user-card-avatar" style="background:var(--gold);width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#FFF">
        ${I.grid}
      </div>
      <div class="user-card-info">
        <div class="user-card-name">All Users</div>
        <div class="user-card-stats">${totalTasks} total</div>
      </div>
    </div>
  `;

  // Order users by most tasks first (descending). "All Users" always stays the
  // leading card; the busiest real users sit right next to it. Ties break by name.
  const sortedUsers = [...users].sort((a, b) =>
    (b.total || 0) - (a.total || 0) || String(a.name).localeCompare(String(b.name))
  );

  const userCards = sortedUsers.map(u => {
    const isActive = _taskOverviewFilter === u.id;
    const progressWidth = u.total > 0 ? (u.completed / u.total) * 100 : 0;
    return `
      <div class="user-card ${isActive ? 'user-card-active' : ''}" onclick="filterTaskOverviewByUser(${u.id})">
        <div class="user-card-avatar" style="background:${u.avatar_color};width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#FFF">
          ${esc(u.initials)}
        </div>
        <div class="user-card-info">
          <div class="user-card-name">${esc(u.name)}</div>
          <div class="user-card-stats">${u.completed}/${u.total} done</div>
          <div class="user-card-progress">
            <div class="user-card-progress-bar" style="width:${progressWidth}%"></div>
          </div>
        </div>
      </div>
    `;
  }).join('');

  return `
    <div class="user-cards-row">
      ${allCard}
      ${userCards}
    </div>
  `;
}

// ===== TASK ROWS =====
function overviewTaskRowHTML(t) {
  const assignees = t.assignees || [];
  const shown = assignees.slice(0, 3).map(a => `<span class="task-avatar" title="${esc(a.name)}" style="background:${a.avatar_color}">${esc(a.initials)}</span>`).join('');
  const extra = assignees.length > 3 ? `<span class="task-avatar more">+${assignees.length - 3}</span>` : '';
  const assigneeHTML = (shown || extra) ? `<div class="task-avatars">${shown}${extra}</div>` : '<span class="task-avatar" style="background:#EAE0CE;color:var(--muted)">—</span>';

  return `
    <div class="task-row" onclick="goTaskPage(${t.id}, ${t.project_id})">
      <div class="task-checkbox ${t.done ? 'done' : ''}" onclick="event.stopPropagation();toggleOverviewTask(${t.id},this)">${I.check}</div>
      <span class="task-name-wrap"><span class="task-name ${t.done ? 'done' : ''}">${esc(t.title)}</span>${t.source_module === 'crm.follow_up' && t.description ? `<small class="task-crm-context">${esc(t.description.split('\n')[0])}${t.crm_lead_id ? ` · <a href="#crm/lead/${t.crm_lead_id}" onclick="event.stopPropagation();event.preventDefault();goCrmLead(${t.crm_lead_id})" style="color:#8E6B3A;font-weight:600;text-decoration:none">View lead →</a>` : ''}</small>` : ''}</span>
      ${t.priority === 'urgent' ? '<span style="font-size:11px;font-weight:700;color:#FFF;background:#B0432B;border-radius:99px;padding:2px 8px">URGENT</span>' : ''}
      <span style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)">
        <span style="width:6px;height:6px;border-radius:99px;background:${t.project_color}"></span>${esc(t.project_name)}
      </span>
      ${t.deadline_label ? `<span style="font-size:12px;color:${t.deadline_label.includes('Overdue') ? '#B0432B' : 'var(--muted)'};font-weight:${t.deadline_label.includes('Overdue') ? 700 : 400}">${esc(t.deadline_label)}</span>` : ''}
      <span class="meeting-status" style="background:${t.status_color};color:#FFF;font-size:10px;padding:2px 7px">${esc(t.status_label)}</span>
      <div style="display:flex;align-items:center;margin-left:auto">${assigneeHTML}</div>
      <div style="position:relative;flex:none">
        <button onclick="event.stopPropagation();toggleAgendaAddMenu(${t.id}, this)" title="Add to agenda" aria-label="Add to agenda" class="task-row-dots">${I.plus}</button>
        <div class="task-quick-menu" id="agenda-add-menu-${t.id}">
          <button onclick="event.stopPropagation();addToAgendaFromTask(${t.id},'${esc(t.title).replace(/'/g,"\\'")}',${t.project_id})">Add to Agenda</button>
        </div>
      </div>
    </div>
  `;
}

function filterTaskOverviewByUser(userId) {
  _taskOverviewFilter = userId;
  render();
}

function setTaskOverviewSubView(view) {
  State.taskOverviewSubView = view;
  render();
}

async function toggleOverviewTask(id, el) {
  try {
    const res = await API.toggleTask(id);
    if (res.status === 'completed') {
      el.classList.add('done');
      el.parentElement.querySelector('.task-name')?.classList.add('done');
    } else {
      el.classList.remove('done');
      el.parentElement.querySelector('.task-name')?.classList.remove('done');
    }
    _allTasksData = null;
    State.myTasksData = null;
    State.meetingData = null;
    State.dayPlan = undefined;
    setTimeout(() => render(), 600);
  } catch (e) { toast(e.message, 'error'); }
}

// ===== UNIFIED MEETING AGENDA =====
function renderUnifiedAgenda(agendaItems) {
  const pending = agendaItems.filter(a => !a.is_completed);
  const completed = agendaItems.filter(a => a.is_completed);

  return `
    <div class="agenda-list-container">
      <div class="agenda-list-header">
        <div style="display:flex;align-items:center;gap:10px">
          <h3>Team Meeting Agenda</h3>
          <span class="agenda-count-badge">${agendaItems.length}</span>
        </div>
        <span style="font-size:13px;color:var(--muted)">${pending.length} pending · ${completed.length} done</span>
      </div>

      <!-- Inline add input -->
      <div class="agenda-add-row">
        <input class="agenda-add-input" id="agenda-quick-add" placeholder="Add agenda point…" onkeydown="if(event.key==='Enter'){event.preventDefault();quickAddAgenda()}">
        <button class="btn-primary" style="padding:8px 16px;font-size:13px;flex:none" onclick="quickAddAgenda()">${I.plus}Add</button>
      </div>

      <!-- Agenda list -->
      <div id="agenda-list-body">
        ${agendaItems.length ? agendaItems.map(agendaRow).join('') : '<div class="agenda-empty">No agenda points yet. Add one above or use the + button on any task.</div>'}
      </div>
    </div>
  `;
}

function agendaRow(a) {
  const projLink = a.related_project_id
    ? ` onclick="event.stopPropagation();meetingGoProject(${a.related_project_id})" style="cursor:pointer"`
    : '';
  const taskLink = (a.related_task_id && a.related_project_id)
    ? ` onclick="event.stopPropagation();meetingOpenTask(${a.related_task_id},${a.related_project_id})" style="cursor:pointer;text-decoration:underline;text-underline-offset:2px;color:var(--gold)"`
    : '';

  return `
    <div class="agenda-list-item ${a.is_completed ? 'completed' : ''}" id="agenda-item-${a.id}">
      <button class="agenda-check" onclick="event.stopPropagation();toggleAgendaComplete(${a.id}, ${a.is_completed ? 'true' : 'false'})" title="${a.is_completed ? 'Mark incomplete' : 'Mark complete'}">
        ${a.is_completed ? I.check : '<span class="agenda-check-empty"></span>'}
      </button>
      <div class="agenda-item-content">
        <div class="agenda-item-title" id="agenda-title-${a.id}" data-value="${esc(a.title)}">${a.is_completed ? '✓ ' : ''}${esc(a.title)}</div>
        ${a.description ? `<div class="agenda-item-desc">${esc(a.description)}</div>` : ''}
        ${a.related_project_name && !a.related_task_title ? `<div class="agenda-item-link"${projLink}><span style="width:8px;height:8px;border-radius:99px;background:${a.related_project_color || 'var(--gold)'};display:inline-block;margin-right:6px"></span>${esc(a.related_project_name)}</div>` : ''}
        ${a.related_task_title ? `<div class="agenda-item-link"${taskLink}>→ ${esc(a.related_task_title)}</div>` : ''}
        ${a.assignee_name ? `<div class="agenda-item-assignee"><div class="avatar avatar-sm" style="background:${a.assignee_color || '#9A8A78'}">${a.assignee_initials || '?'}</div><span>${esc(a.assignee_name)}</span></div>` : ''}
      </div>
      <div class="agenda-item-actions" onclick="event.stopPropagation()">
        <button class="agenda-item-dots" onclick="toggleAgendaMenu(${a.id}, this)">${I.dots}</button>
        <div class="task-quick-menu agenda-menu" id="agenda-menu-${a.id}">
          <button onclick="editAgendaInline(${a.id})">Edit</button>
          <button onclick="toggleAgendaComplete(${a.id}, ${a.is_completed ? 'true' : 'false'})">${a.is_completed ? 'Mark incomplete' : 'Mark complete'}</button>
          <button onclick="deleteAgendaItem(${a.id})" style="color:var(--red)">Delete</button>
        </div>
      </div>
    </div>
  `;
}

// ===== AGENDA ACTIONS =====
async function quickAddAgenda() {
  const input = document.getElementById('agenda-quick-add');
  const title = input?.value.trim();
  if (!title) return;
  try {
    const res = await API.createAgenda({ title });
    const newItem = res && res.item && res.item.id ? res.item : null;
    if (newItem) {
      State.agendaItems = [...(State.agendaItems || []), newItem];
    } else {
      try { const a = await API.getAgenda(); State.agendaItems = a.agenda || a.items || []; } catch(e) {}
    }
    input.value = '';
    toast('Agenda point added', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
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
    const items = State.agendaItems || [];
    const it = items.find(x => x.id === id);
    if (it) { it.is_completed = !isCompleted; }
    render();
  } catch(e) { toast(e.message, 'error'); }
}

function editAgendaInline(id) {
  closeAllTaskMenus();
  const titleEl = document.getElementById('agenda-title-' + id);
  if (!titleEl || titleEl.querySelector('input')) return;
  const oldVal = titleEl.dataset.value || titleEl.textContent.trim();
  const input = document.createElement('input');
  input.type = 'text';
  input.value = oldVal;
  input.style.cssText = 'font-size:15px;font-weight:600;font-family:var(--sans);color:var(--text);border:1px solid var(--gold);border-radius:6px;padding:4px 8px;width:100%;background:var(--surface)';
  titleEl.innerHTML = '';
  titleEl.appendChild(input);
  input.focus();
  input.select();
  input.addEventListener('blur', async () => {
    const newVal = input.value.trim();
    if (newVal && newVal !== oldVal) {
      try {
        await API.updateAgenda(id, { title: newVal });
        titleEl.dataset.value = newVal;
        titleEl.innerHTML = esc(newVal);
        toast('Agenda updated', 'success');
        // Update local state
        const items = State.agendaItems || [];
        const it = items.find(x => x.id === id);
        if (it) it.title = newVal;
      } catch(e) { toast(e.message, 'error'); titleEl.innerHTML = esc(oldVal); }
    } else {
      titleEl.innerHTML = esc(oldVal);
    }
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    if (e.key === 'Escape') { titleEl.innerHTML = esc(oldVal); titleEl.dataset.value = oldVal; }
  });
}

async function deleteAgendaItem(id) {
  closeAllTaskMenus();
  appConfirm('Delete this agenda point?', async () => {
    try {
      await API.deleteAgenda(id);
      State.agendaItems = (State.agendaItems || []).filter(x => x.id !== id);
      toast('Agenda point deleted', 'success');
      render();
    } catch(e) { toast(e.message, 'error'); }
  });
}

// ===== ADD TO AGENDA FROM EXTERNAL PAGES =====
async function addToAgendaFromTask(taskId, taskTitle, projectId) {
  closeAllTaskMenus();
  try {
    // Fetch task details to get assignee
    let assigneeId = null;
    try {
      const taskRes = await API.task(taskId);
      const task = taskRes.task;
      if (task.assignees && task.assignees.length > 0) {
        assigneeId = task.assignees[0].id;
      } else if (task.assigned_to) {
        assigneeId = task.assigned_to;
      }
    } catch(e) {}
    
    await API.createAgenda({ 
      title: taskTitle, 
      related_task_id: taskId, 
      related_project_id: projectId,
      assigned_to: assigneeId
    });
    toast('Added to meeting agenda', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

async function addToAgendaFromProject(projectId, projectName) {
  closeAllTaskMenus();
  try {
    await API.createAgenda({ title: 'Discuss: ' + projectName, related_project_id: projectId });
    toast('Added to meeting agenda', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

// ===== NAVIGATION HELPERS (kept from meeting.js) =====
function meetingOpenTask(taskId, projectId) {
  closeAllTaskMenus();
  State.screen = 'task';
  State.activeTaskId = taskId;
  _taskPageData = null;
  render();
}

function meetingGoProject(projectId) {
  closeAllTaskMenus();
  if (typeof goProject === 'function') { goProject(projectId); return; }
  State.screen = 'project';
  State.activeProjectId = projectId;
  State.activeProject = null;
  render();
}

function goTaskPage(taskId, projectId) {
  closeAllTaskMenus();
  State.screen = 'task';
  State.activeTaskId = parseInt(taskId);
  State.activeTaskProjectId = projectId ? parseInt(projectId) : null;
  _taskPageData = null;
  updateHash();
  render();
  document.querySelector('.main')?.scrollTo(0, 0);
}

function toggleAgendaAddMenu(id, btn) {
  closeAllTaskMenus();
  const menu = document.getElementById('agenda-add-menu-' + id);
  if (menu) menu.classList.toggle('show');
}
