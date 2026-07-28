// VGo — Task Overview: Company Tasks + User Cards.
// The meeting agenda that used to live here as a sub-view is now its own
// screen, "Priorities" (see views/priorities.js). The agenda *actions* below
// are still shared by that view and by the + buttons on tasks/projects.
let _allTasksData = null;
let _taskOverviewFilter = null; // null = all users, or user id

async function renderTaskOverview() {
  // Fetch all tasks data
  if (!_allTasksData) {
    try {
      const res = await API.allTasks();
      _allTasksData = res;
    } catch (e) {
      return `<div class="fade-in"><p style="color:var(--barn)">Failed to load tasks.</p></div>`;
    }
  }

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

  // Task groups. CRM follow-ups are tucked into a collapsible section per status
  // group — same treatment as My Tasks — so they don't swamp the workflow tasks.
  const groupsHTML = Object.values(groups).filter(g => g.tasks.length > 0).map((g, gi) => {
    const crmTasks = g.tasks.filter(isCrmFollowUpTask);
    const wfTasks = g.tasks.filter(t => !isCrmFollowUpTask(t));
    const crmSection = crmFollowUpSection(crmTasks, `overview-${gi}`, t => overviewTaskRowHTML(t));
    return `
    <div class="task-group">
      <div class="task-group-header">
        <span class="dot" style="background:${g.color}"></span>
        <span class="label">${g.label}</span>
        <span class="count">${wfTasks.length}${crmTasks.length ? ` + ${crmTasks.length} CRM` : ''}</span>
      </div>
      <div class="task-list">
        ${wfTasks.map(t => overviewTaskRowHTML(t)).join('') || (crmTasks.length ? '<div class="empty-state" style="padding:10px 2px;text-align:left"><div class="desc">No workflow tasks — see CRM follow-ups below.</div></div>' : '')}
      </div>
      ${crmSection}
    </div>`;
  }).join('') || '<div class="empty-state"><div class="title">No tasks</div><div class="desc">No tasks match the current filter.</div></div>';

  return `
    <div class="fade-in">
      <div class="section-label">Task Overview</div>
      <h1 class="page-title-sm" style="margin-bottom:20px">Company Tasks</h1>

      <!-- User filter cards -->
      ${userCardsHTML}

      <!-- All Tasks -->
      <div style="font-size:18px;font-weight:700;color:var(--gold);margin-bottom:14px">All Tasks</div>
      ${groupsHTML}
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
      <div class="task-row-right" style="display:flex;align-items:center;gap:8px;margin-left:auto;flex:none">
        ${t.deadline_label ? `<span style="font-size:12px;color:${t.deadline_label.includes('Overdue') ? '#B0432B' : 'var(--muted)'};font-weight:${t.deadline_label.includes('Overdue') ? 700 : 400}">${esc(t.deadline_label)}</span>` : ''}
        <span class="meeting-status" style="background:${t.status_color};color:#FFF;font-size:10px;padding:2px 7px">${esc(t.status_label)}</span>
        <div class="task-avatars-wrap" style="display:flex;align-items:center">${assigneeHTML}</div>
        <div class="task-row-agenda" style="position:relative;flex:none">
          <button onclick="event.stopPropagation();toggleAgendaAddMenu(${t.id}, this)" title="Add to Priorities" aria-label="Add to Priorities" class="task-row-dots">${I.plus}</button>
          <div class="task-quick-menu" id="agenda-add-menu-${t.id}">
            <button onclick="event.stopPropagation();addToAgendaFromTask(${t.id},'${esc(t.title).replace(/'/g,"\\'")}',${t.project_id})">Add to Priorities</button>
          </div>
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

// ===== PRIORITIES (formerly "Meeting Agenda") ACTIONS =====
// The board itself now renders in views/priorities.js; these mutators are
// shared by that view and by the + buttons on task rows / project pages.

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
    toast('Added to Priorities', 'success');
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
        toast('Renamed', 'success');
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
  appConfirm('Remove this item from Priorities?', async () => {
    try {
      await API.deleteAgenda(id);
      State.agendaItems = (State.agendaItems || []).filter(x => x.id !== id);
      toast('Removed from Priorities', 'success');
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
    State.agendaItems = null; // Priorities board must refetch
    toast('Added to Priorities', 'success');
  } catch(e) { toast(e.message, 'error'); }
}

async function addToAgendaFromProject(projectId, projectName) {
  closeAllTaskMenus();
  try {
    await API.createAgenda({ title: projectName, related_project_id: projectId });
    State.agendaItems = null; // Priorities board must refetch
    toast('Added to Priorities', 'success');
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
