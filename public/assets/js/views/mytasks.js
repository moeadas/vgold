// VGo — My Tasks view with day plan card + filterable task sections
async function renderMyTasks() {
  // Fetch the code-based day plan (fast, no AI) for the top card
  let plan = State.dayPlan;
  if (plan === undefined) {
    try { const r = await API.getDayPlan(); plan = r.plan; State.dayPlan = plan; }
    catch (e) { plan = null; State.dayPlan = null; }
  }

  let data = State.myTasksData;
  if (!data) {
    try { const res = await API.myTasks(); data = res.tasks; State.myTasksData = data; } catch(e) { data = []; }
  }

  // Active filter
  const filter = State.myTasksFilter || 'all';

  // Group by status (only in_progress + completed)
  const groups = {
    in_progress: { label: 'In Progress', color: '#C99520', tasks: [] },
    completed: { label: 'Completed', color: '#6B8E5A', tasks: [] },
  };

  (data || []).forEach(t => {
    if (groups[t.status]) groups[t.status].tasks.push(t);
  });

  const total = (data || []).length;
  const completed = groups.completed.tasks.length;
  const inProgress = groups.in_progress.tasks.length;
  const urgentCount = (data||[]).filter(t=>t.priority==='urgent'&&t.status==='in_progress').length;

  const chip = (key, num, label, color, active) => `
    <div class="status-chip ${active ? 'status-chip-active' : ''}" onclick="setMyTasksFilter('${key}')" style="${active ? `border-color:${color};background:${color}1a` : ''}">
      ${color ? `<span style="width:8px;height:8px;border-radius:99px;background:${color}"></span>` : ''}
      <span class="num" style="${color ? `color:${color}` : ''}">${num}</span>
      <span class="label">${label}</span>
    </div>
  `;

  // Apply filter
  let filteredGroups = Object.values(groups).filter(g => g.tasks.length > 0);
  
  if (filter === 'urgent') {
    const urgentTasks = (data || []).filter(t => t.priority === 'urgent' && t.status === 'in_progress');
    filteredGroups = [{
      label: 'Urgent',
      color: '#B0432B',
      tasks: urgentTasks
    }];
  } else if (filter !== 'all') {
    filteredGroups = filteredGroups.filter(g => g.tasks[0]?.status === filter);
  }

  const groupsHTML = filteredGroups.map(g => `
    <div class="task-group">
      <div class="task-group-header">
        <span class="dot" style="background:${g.color}"></span>
        <span class="label">${g.label}</span>
        <span class="count">${g.tasks.length}</span>
      </div>
      <div class="task-list">
        ${g.tasks.map(t => taskRowHTML(t)).join('')}
      </div>
    </div>
  `).join('') || (filter !== 'all' ? '<div class="empty-state"><div class="title">No tasks</div><div class="desc">No tasks match this filter.</div></div>' : '');

  // Day plan card
  const planCardHTML = plan && plan.html ? `
    <div class="card day-plan-card" style="margin-bottom:24px">
      <div class="day-plan-content" id="day-plan-content">${sanitizeHtml(plan.html || '')}</div>
      <div style="display:flex;align-items:center;gap:9px;border-top:1px solid var(--border);padding-top:14px;margin-top:18px">
        <span style="font-size:12.5px;color:var(--muted);flex:1">Your suggested order for today · updates as tasks change</span>
        <button class="btn" onclick="regenerateDayPlan()">${I.sun} Refresh</button>
      </div>
    </div>` : '';

  return `
    <div class="fade-in">
      <div class="section-label">My Tasks</div>
      <h1 class="page-title-sm" style="margin-bottom:20px">All your tasks in one place</h1>
      ${planCardHTML}
      <div class="status-chips">
        ${chip('all', total, 'Total', '', filter === 'all')}
        ${chip('in_progress', inProgress, 'In Progress', '#C99520', filter === 'in_progress')}
        ${chip('completed', completed, 'Completed', '#6B8E5A', filter === 'completed')}
        ${chip('urgent', urgentCount, 'Urgent', '#B0432B', filter === 'urgent')}
      </div>
      ${groupsHTML || '<div class="empty-state"><div class="title">No tasks yet</div><div class="desc">Tasks assigned to you will appear here.</div></div>'}
    </div>
  `;
}

function taskRowHTML(t) {
  return `
    <div class="task-row" onclick="goTaskPage(${t.id}, ${t.project_id})">
      <div class="task-checkbox ${t.done ? 'done' : ''}" onclick="event.stopPropagation();toggleMyTask(${t.id},this)">${I.check}</div>
      <span class="task-name-wrap"><span class="task-name ${t.done ? 'done' : ''}">${esc(t.title)}</span>${t.source_module === 'crm.follow_up' && t.description ? `<small class="task-crm-context">${esc(t.description.split('\n')[0])}</small>` : ''}</span>
      ${t.priority === 'urgent' ? '<span style="font-size:11px;font-weight:700;color:#FFF;background:#B0432B;border-radius:99px;padding:2px 8px">URGENT</span>' : ''}
      <span style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)">
        <span style="width:6px;height:6px;border-radius:99px;background:${t.project_color}"></span>${esc(t.project_name)}
      </span>
      ${t.deadline_label ? `<span style="font-size:12px;color:${t.deadline_label.includes('Overdue') ? '#B0432B' : 'var(--muted)'};font-weight:${t.deadline_label.includes('Overdue') ? 700 : 400}">${esc(t.deadline_label)}</span>` : ''}
      <div class="task-row-actions" onclick="event.stopPropagation()">
        <button class="task-row-dots" onclick="toggleTaskMenu(${t.id}, this)">${I.dots}</button>
        <div class="task-quick-menu" id="task-menu-${t.id}">
          <button onclick="openTaskFromMyTasks(${t.id}, ${t.project_id})">Open task</button>
          <button onclick="cycleTaskStatus(${t.id})">Change status</button>
          <button onclick="deleteTaskFromMyTasks(${t.id})" style="color:var(--red)">Delete</button>
        </div>
      </div>
    </div>
  `;
}

function setMyTasksFilter(filter) {
  State.myTasksFilter = filter;
  render();
}

async function toggleMyTask(id, el) {
  try {
    const res = await API.toggleTask(id);
    if (res.status === 'completed') {
      el.classList.add('done');
      el.parentElement.querySelector('.task-name')?.classList.add('done');
    } else {
      el.classList.remove('done');
      el.parentElement.querySelector('.task-name')?.classList.remove('done');
    }
    State.myTasksData = null;
    State.meetingData = null;
    State.dayPlan = undefined;
    setTimeout(() => render(), 600);
  } catch(e) { toast(e.message, 'error'); }
}

function openTaskFromMyTasks(taskId, projectId) {
  closeAllTaskMenus();
  goTaskPage(taskId, projectId);
}

async function cycleTaskStatus(taskId) {
  closeAllTaskMenus();
  try {
    await API.updateTask(taskId, { _cycle: true });
    State.myTasksData = null;
    State.meetingData = null;
    State.dayPlan = undefined;
    toast('Status updated', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function deleteTaskFromMyTasks(taskId) {
  closeAllTaskMenus();
  appConfirm('Delete this task? This cannot be undone.', async () => {
    try {
      await API.deleteTask(taskId);
      State.myTasksData = null;
      State.meetingData = null;
      State.dayPlan = undefined;
      toast('Task deleted', 'success');
      render();
    } catch(e) { toast(e.message, 'error'); }
  });
}

function toggleTaskMenu(id, btn) {
  closeAllTaskMenus();
  const menu = document.getElementById('task-menu-' + id);
  if (menu) menu.classList.toggle('show');
}

function closeAllTaskMenus() {
  document.querySelectorAll('.task-quick-menu.show').forEach(m => m.classList.remove('show'));
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.task-row-actions') && !e.target.closest('.agenda-item-actions')) closeAllTaskMenus();
});

// ===== DAY PLAN HELPERS (moved from dayplan.js) =====

async function regenerateDayPlan() {
  try {
    await fetch('/api/ai/delete-plan', { method: 'POST', headers: API.csrfHeaders(), credentials: 'same-origin' });
  } catch(e) {}
  State.dayPlan = undefined;
  toast('Day plan refreshed', 'success');
  render();
}

function wireDayPlanLinks() {
  const container = document.getElementById('day-plan-content');
  if (!container) return;
  container.querySelectorAll('.ai-link').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const type = link.dataset.type;
      const id = parseInt(link.dataset.id);
      if (!id || isNaN(id)) {
        const text = link.textContent.trim().toLowerCase();
        if (type === 'task') {
          API.myTasks().then(res => {
            const task = res.tasks.find(t => t.title.toLowerCase().includes(text) || text.includes(t.title.toLowerCase()));
            if (task) navigateToTask(task.id, task.project_id);
          });
        } else if (type === 'project') {
          API.projects().then(res => {
            const proj = res.projects.find(p => p.name.toLowerCase().includes(text) || text.includes(p.name.toLowerCase()));
            if (proj) { State.screen = 'project'; State.activeProjectId = proj.id; State.activeProject = null; render(); }
          });
        }
        return;
      }
      if (type === 'task') navigateToTask(id);
      else if (type === 'project') { State.screen = 'project'; State.activeProjectId = id; State.activeProject = null; render(); }
    });
  });
}
