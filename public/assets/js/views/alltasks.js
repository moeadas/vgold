// VGo — All Tasks view with user cards + task filtering
let _allTasksData = null;
let _allTasksUserFilter = null; // null = all users, or user id

async function renderAllTasks() {
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
  const filteredTasks = _allTasksUserFilter !== null
    ? tasks.filter(t => {
        // Check if this user is in the assignees array or is the assignee_name match
        const user = users.find(u => u.id === _allTasksUserFilter);
        if (!user) return false;
        return (t.assignees || []).some(a => a.id === _allTasksUserFilter) ||
               t.assignee_name === user.name;
      })
    : tasks;

  // Group by status
  const groups = {
    in_progress: { label: 'In Progress', color: '#C99520', tasks: [] },
    completed: { label: 'Completed', color: '#6B8E5A', tasks: [] },
  };

  filteredTasks.forEach(t => {
    if (groups[t.status]) groups[t.status].tasks.push(t);
  });

  // User cards row
  const userCardsHTML = renderUserCards(users);

  // Task groups
  const groupsHTML = Object.values(groups).filter(g => g.tasks.length > 0).map(g => `
    <div class="task-group">
      <div class="task-group-header">
        <span class="dot" style="background:${g.color}"></span>
        <span class="label">${g.label}</span>
        <span class="count">${g.tasks.length}</span>
      </div>
      <div class="task-list">
        ${g.tasks.map(t => allTaskRowHTML(t)).join('')}
      </div>
    </div>
  `).join('') || '<div class="empty-state"><div class="title">No tasks</div><div class="desc">No tasks match the current filter.</div></div>';

  return `
    <div class="fade-in">
      <div class="section-label">All Tasks</div>
      <h1 class="page-title-sm" style="margin-bottom:20px">Workspace task overview</h1>
      ${userCardsHTML}
      ${groupsHTML || '<div class="empty-state"><div class="title">No tasks yet</div><div class="desc">Tasks will appear here once created.</div></div>'}
    </div>
  `;
}

function renderUserCards(users) {
  // "All Users" card
  const allCard = `
    <div class="user-card ${_allTasksUserFilter === null ? 'user-card-active' : ''}" onclick="filterAllTasksByUser(null)">
      <div class="user-card-avatar" style="background:var(--gold);width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#FFF">
        ${I.grid}
      </div>
      <div class="user-card-info">
        <div class="user-card-name">All Users</div>
        <div class="user-card-stats">${(_allTasksData.tasks || []).length} total</div>
      </div>
    </div>
  `;

  const userCards = users.map(u => {
    const isActive = _allTasksUserFilter === u.id;
    const progressWidth = u.total > 0 ? (u.completed / u.total) * 100 : 0;
    return `
      <div class="user-card ${isActive ? 'user-card-active' : ''}" onclick="filterAllTasksByUser(${u.id})">
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

function allTaskRowHTML(t) {
  const assignees = t.assignees || [];
  const shown = assignees.slice(0, 3).map(a => `<span class="task-avatar" title="${esc(a.name)}" style="background:${a.avatar_color}">${esc(a.initials)}</span>`).join('');
  const extra = assignees.length > 3 ? `<span class="task-avatar more">+${assignees.length - 3}</span>` : '';
  const assigneeHTML = (shown || extra) ? `<div class="task-avatars">${shown}${extra}</div>` : '<span class="task-avatar" style="background:#EAE0CE;color:var(--muted)">—</span>';

  return `
    <div class="task-row" onclick="goTaskPage(${t.id}, ${t.project_id})">
      <div class="task-checkbox ${t.done ? 'done' : ''}" onclick="event.stopPropagation();toggleAllTask(${t.id},this)">${I.check}</div>
      <span class="task-name ${t.done ? 'done' : ''}">${esc(t.title)}</span>
      ${t.priority === 'urgent' ? '<span style="font-size:11px;font-weight:700;color:#FFF;background:#B0432B;border-radius:99px;padding:2px 8px">URGENT</span>' : ''}
      <span style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)">
        <span style="width:6px;height:6px;border-radius:99px;background:${t.project_color}"></span>${esc(t.project_name)}
      </span>
      ${t.deadline_label ? `<span style="font-size:12px;color:${t.deadline_label.includes('Overdue') ? '#B0432B' : 'var(--muted)'};font-weight:${t.deadline_label.includes('Overdue') ? 700 : 400}">${esc(t.deadline_label)}</span>` : ''}
      <span class="meeting-status" style="background:${t.status_color};color:#FFF;font-size:10px;padding:2px 7px">${esc(t.status_label)}</span>
      <div style="display:flex;align-items:center;margin-left:auto">${assigneeHTML}</div>
      <button onclick="event.stopPropagation();addToAgendaFromTask(${t.id},'${esc(t.title).replace(/'/g,"\\'")}',${t.project_id})" title="Add to meeting agenda" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--gold);display:inline-flex;align-items:center" aria-label="Add to meeting agenda">${I.plus}</button>
    </div>
  `;
}

function filterAllTasksByUser(userId) {
  _allTasksUserFilter = userId;
  render();
}

async function toggleAllTask(id, el) {
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