// VGo — Priorities
// The former "Agenda" sub-view of Task Overview, promoted to its own screen.
// Layout is a column-per-workspace grid: each column header is the workspace
// name, and every item added to Priorities becomes a card in its workspace's
// column showing the task/project name plus the people assigned to it.

async function renderPriorities() {
  let items = State.agendaItems;
  if (!items) {
    try {
      const res = await API.getAgenda();
      items = res.agenda || res.items || [];
      State.agendaItems = items;
    } catch (e) {
      items = [];
      State.agendaItems = [];
    }
  }

  const showDone = State.prioritiesShowDone === true;
  const visible = showDone ? items : items.filter(a => !a.is_completed);
  const doneCount = items.filter(a => a.is_completed).length;

  // Group into columns keyed by root workspace. Items with no linked
  // project/task (free-text points) collect in a trailing "General" column.
  const cols = new Map();
  const GENERAL = '__general__';
  visible.forEach(a => {
    const key = a.workspace_project_id ? String(a.workspace_project_id) : GENERAL;
    if (!cols.has(key)) {
      cols.set(key, {
        key,
        id: a.workspace_project_id || null,
        name: a.workspace_name || 'General',
        color: a.workspace_color || 'var(--gold)',
        items: [],
      });
    }
    cols.get(key).items.push(a);
  });

  // Busiest workspaces first; "General" always last.
  const columns = Array.from(cols.values()).sort((a, b) => {
    if (a.key === GENERAL) return 1;
    if (b.key === GENERAL) return -1;
    return b.items.length - a.items.length || String(a.name).localeCompare(String(b.name));
  });

  const board = columns.length
    ? `<div class="pri-board">${columns.map(prioritiesColumn).join('')}</div>`
    : `<div class="empty-state" style="padding:40px 20px">
         <div class="title">Nothing prioritised yet</div>
         <div class="desc">Add a point below, or use the <strong>+</strong> button on any task or project to send it here.</div>
       </div>`;

  return `
    <div class="fade-in">
      <div class="section-label">Priorities</div>
      <h1 class="page-title-sm" style="margin-bottom:6px">What the team is focused on</h1>
      <p class="page-desc" style="margin-bottom:18px">Organised by workspace. Add tasks or projects from anywhere with the <strong>+</strong> button.</p>

      <div class="pri-toolbar">
        <div class="agenda-add-row" style="flex:1;margin:0">
          <input class="agenda-add-input" id="agenda-quick-add" placeholder="Add a priority…" onkeydown="if(event.key==='Enter'){event.preventDefault();quickAddAgenda()}">
          <button class="btn-primary" style="padding:8px 16px;font-size:13px;flex:none" onclick="quickAddAgenda()">${I.plus}Add</button>
        </div>
        <button class="btn" style="flex:none" onclick="togglePrioritiesDone()">
          ${showDone ? 'Hide' : 'Show'} completed${doneCount ? ` (${doneCount})` : ''}
        </button>
      </div>

      ${board}
    </div>
  `;
}

function prioritiesColumn(col) {
  return `
    <section class="pri-col" aria-label="${esc(col.name)}">
      <header class="pri-col-head" ${col.id ? `onclick="goCategory(${col.id})" style="cursor:pointer" title="Open ${esc(col.name)}"` : ''}>
        <span class="pri-col-dot" style="background:${esc(col.color)}"></span>
        <span class="pri-col-name">${esc(col.name)}</span>
        <span class="pri-col-count">${col.items.length}</span>
      </header>
      <div class="pri-col-body">
        ${col.items.map(prioritiesCard).join('')}
      </div>
    </section>
  `;
}

function prioritiesCard(a) {
  const people = (a.assignees && a.assignees.length)
    ? a.assignees
    : (a.assignee_name ? [{ id: a.assigned_to, name: a.assignee_name, initials: a.assignee_initials || '?', avatar_color: a.assignee_color || '#9A8A78' }] : []);

  const avatars = people.slice(0, 4).map(p =>
    `<span class="avatar avatar-sm pri-av" title="${esc(p.name)}" style="background:${p.avatar_color || '#9A8A78'}">${esc(p.initials || '?')}</span>`
  ).join('');
  const more = people.length > 4 ? `<span class="avatar avatar-sm pri-av pri-av-more">+${people.length - 4}</span>` : '';

  // The card headline is the linked task/project name where there is one,
  // falling back to the free-text point the user typed.
  const headline = a.related_task_title || (a.kind === 'project' ? a.related_project_name : null) || a.title;
  const openAttr = a.related_task_id
    ? `onclick="meetingOpenTask(${a.related_task_id}, ${a.related_project_id || 'null'})"`
    : (a.related_project_id ? `onclick="meetingGoProject(${a.related_project_id})"` : '');

  const kindLabel = a.kind === 'task' ? 'Task' : (a.kind === 'project' ? 'Project' : 'Note');
  const trail = a.related_project_name && a.kind === 'task' ? a.related_project_name : '';

  return `
    <article class="pri-card ${a.is_completed ? 'done' : ''}" id="agenda-item-${a.id}">
      <div class="pri-card-top">
        <button class="agenda-check pri-check" title="${a.is_completed ? 'Mark incomplete' : 'Mark complete'}"
                onclick="event.stopPropagation();toggleAgendaComplete(${a.id}, ${a.is_completed ? 'true' : 'false'})">
          ${a.is_completed ? I.check : '<span class="agenda-check-empty"></span>'}
        </button>
        <div class="pri-card-main" ${openAttr} ${openAttr ? 'style="cursor:pointer"' : ''}>
          <div class="pri-card-title" id="agenda-title-${a.id}" data-value="${esc(a.title)}">${esc(headline)}</div>
          <div class="pri-card-meta">
            <span class="pri-kind pri-kind-${esc(a.kind || 'note')}">${kindLabel}</span>
            ${trail ? `<span class="pri-trail">${esc(trail)}</span>` : ''}
          </div>
        </div>
        <div class="agenda-item-actions" onclick="event.stopPropagation()">
          <button class="agenda-item-dots" onclick="toggleAgendaMenu(${a.id}, this)">${I.dots}</button>
          <div class="task-quick-menu agenda-menu" id="agenda-menu-${a.id}">
            <button onclick="editAgendaInline(${a.id})">Rename</button>
            <button onclick="toggleAgendaComplete(${a.id}, ${a.is_completed ? 'true' : 'false'})">${a.is_completed ? 'Mark incomplete' : 'Mark complete'}</button>
            <button onclick="deleteAgendaItem(${a.id})" style="color:var(--red)">Remove</button>
          </div>
        </div>
      </div>
      ${a.description ? `<div class="pri-card-desc">${linkifyText(a.description)}</div>` : ''}
      ${people.length ? `<div class="pri-card-people">${avatars}${more}</div>` : '<div class="pri-card-people pri-none">Unassigned</div>'}
    </article>
  `;
}

function togglePrioritiesDone() {
  State.prioritiesShowDone = !State.prioritiesShowDone;
  render();
}
