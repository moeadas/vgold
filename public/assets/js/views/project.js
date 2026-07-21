// VGo — Project Detail view with new status system
async function renderProject() {
  let p = State.activeProject;
  if (!p || p.id != State.activeProjectId) {
    try {
      const res = await API.project(State.activeProjectId);
      p = res.project;
      State.activeProject = p;
      // Mark chat as read for this project
      API.markChatRead(State.activeProjectId).then(() => {
        if (State.unreadCounts) { State.unreadCounts[State.activeProjectId] = 0; }
      }).catch(() => {});
    } catch(e) { return '<div class="fade-in"><p>Project not found.</p></div>'; }
  }

  const members = (p.members || []).map(m => `<div class="avatar avatar-sm" style="background:${m.bg};margin-left:-7px;border:2px solid var(--surface)">${m.initials}</div>`).join('');

  const groups = (p.groups || []).map(g => `
    <div class="task-group">
      <div class="task-group-header">
        <span class="dot" style="background:${g.color}"></span>
        <span class="label">${esc(g.label)}</span>
        <span class="count">${g.count}</span>
      </div>
      <div class="task-list">
        ${(g.tasks || []).map(t => taskRow(t, p.id)).join('')}
      </div>
    </div>
  `).join('');

  const projFiles = p.files || [];
  const filesSection = renderCollapsibleFiles(projFiles, 'proj', p.folders || []);

  // C3 — multi-level breadcrumb (category → project → sub-project → …). The
  // backend provides `breadcrumb` as an ordered ancestor list; we append the
  // current project as the (non-clickable) trailing crumb.
  const crumbs = (p.breadcrumb || []).map(b =>
    `<button class="crumb-link" onclick="${b.is_category ? `goCategory(${b.id})` : `goProject(${b.id})`}">${esc(b.name)}</button>`
  );
  const breadcrumbHTML = crumbs.length
    ? `<nav class="breadcrumbs">${crumbs.join('<span class="crumb-sep">/</span>')}<span class="crumb-sep">/</span><span class="crumb-current">${esc(p.name)}</span></nav>`
    : '';

  // C3 — sub-projects grid on the project page (mirrors the category page).
  const subs = p.subprojects || [];
  const subGrid = subs.length ? subs.map(sp => {
    const memberAvatars = (sp.members || []).slice(0, 5).map(m =>
      `<div class="avatar avatar-sm" style="background:${m.avatar_color};border:2px solid var(--surface);margin-left:-7px">${m.initials}</div>`
    ).join('');
    return `
      <div class="project-card" onclick="goProject(${sp.id})">
        ${State.user?.role === 'admin' ? `<button class="cat-delete-btn" onclick="event.stopPropagation();deleteProject(${sp.id})" title="Delete sub-project">${I.trash}</button>` : ''}
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px">
          ${unreadKPI(State.unreadCounts, sp.id)}<div class="pc-title">${esc(sp.name)}</div>
          <span class="health-pill" style="color:${sp.health_color};background:${sp.healthBg || '#E5E6D2'};flex:none"><span class="dot" style="background:${sp.health_color}"></span>${sp.health_label}</span>
        </div>
        <div class="pc-metrics">
          <div class="pc-metric"><span class="num">${sp.open_tasks ?? 0}</span><span class="label">Open</span></div>
          <div class="pc-metric"><span class="num">${sp.completed_tasks ?? 0}</span><span class="label">Done</span></div>
          <div class="pc-metric"><span class="num">${sp.total_files ?? 0}</span><span class="label">${(sp.total_files ?? 0) === 1 ? 'File' : 'Files'}</span></div>
          <div class="pc-metric"><span class="num">${sp.progress}% <span style="font-size:12px;font-weight:600;color:var(--muted)">(${sp.completed_tasks ?? 0}/${sp.total_tasks ?? 0})</span></span><span class="label">Progress</span></div>
        </div>
        <div class="progress-bar"><div class="fill" style="width:${sp.progress}%;background:${sp.health_color}"></div></div>
        <div class="pc-footer">
          <div class="pc-team">${memberAvatars}</div>
          <span style="font-size:12.5px;color:var(--muted)">${esc(sp.due_label || '')}</span>
        </div>
      </div>
    `;
  }).join('') : '';

  const subprojectsSection = `
    <div style="margin-top:30px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <h3 style="font-size:24px;font-weight:700;color:var(--gold)">Sub-projects${subs.length ? ` <span style="font-size:14px;font-weight:600;color:var(--muted)">(${subs.length})</span>` : ''}</h3>
        <button class="btn" onclick="openNewProjectModal(${p.id},'${esc(p.name).replace(/'/g,"\\'")}')">${I.plus}Add sub-project</button>
      </div>
      ${subs.length ? `<div class="project-grid">${subGrid}</div>` : `<div class="empty-state" style="padding:22px"><div class="desc">No sub-projects yet. Break this project into sub-projects for complex work.</div></div>`}
    </div>`;

  const chat = (p.chat || []).map(m => `
    <div class="chat-msg ${m.me ? 'me' : ''}">
      <div class="avatar avatar-sm" style="background:${m.bg}">${m.initials}</div>
      <div style="min-width:0">
        <div style="display:flex;align-items:baseline;gap:7px;margin-bottom:3px"><span style="font-size:12.5px;font-weight:700">${esc(m.who)}</span><span style="font-size:11px;color:var(--muted)">${esc(m.time)}</span></div>
        <div class="msg-bubble">${linkify(m.text)}</div>
      </div>
    </div>
  `).join('');

  return `
    <div class="fade-in">
      <button class="back-link" onclick="${p.parent_id ? `goCategory(${p.parent_id})` : `nav('projects')`}">${I.arrowL}${esc(p.category_name || 'All Projects')}</button>
      ${breadcrumbHTML}
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
        <h1 class="page-title-sm" id="proj-name" data-value="${esc(p.name)}" onclick="editProjectName(${p.id},this)" title="Click to edit" style="cursor:text;border-radius:8px;padding:2px 8px;margin:-2px -8px">${esc(p.name)}</h1>
        <span class="health-pill" style="color:${p.health_color};background:${p.healthBg || '#E8F0E4'}"><span class="dot" style="background:${p.health_color}"></span>${p.health_label}</span>
        <div style="position:relative;flex:none">
          <button onclick="event.stopPropagation();toggleAgendaAddMenu('proj-${p.id}', this)" title="Add to agenda" aria-label="Add to agenda" class="task-row-dots">${I.plus}</button>
          <div class="task-quick-menu" id="agenda-add-menu-proj-${p.id}" style="left:0;right:auto">
            <button onclick="event.stopPropagation();addToAgendaFromProject(${p.id},'${esc(p.name).replace(/'/g,"\\'")}')">Add to Agenda</button>
          </div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin-bottom:22px">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:120px;height:7px;border-radius:99px;background:var(--sand);overflow:hidden"><div style="height:100%;width:${p.progress}%;background:${p.health_color};border-radius:99px"></div></div>
          <span style="font-size:13px;font-weight:700">${p.progress}%</span>
          <span style="font-size:12px;color:var(--muted);font-weight:400">(${p.completed_tasks ?? 0}/${p.total_tasks ?? 0} tasks)</span>
        </div>
        <span style="font-size:13px;color:var(--muted)">·</span>
        <span style="font-size:13.5px;color:var(--text-2)">${esc(p.due_label)}</span>
        <span style="font-size:13px;color:var(--muted)">·</span>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="display:flex;align-items:center">${members}</div>
          <button class="btn-secondary" style="padding:5px 10px;font-size:12px;flex:none" onclick="openMembersModal(${p.id},'${esc(p.name).replace(/'/g,"\'")}')">${I.people || '👥'} Members</button>
        </div>
      </div>
      <div class="grid-2-proj">
        <div>
          <div class="card card-pad" style="margin-bottom:24px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:9px;margin-bottom:12px">
              <div style="display:flex;align-items:center;gap:9px">
                <div class="ai-badge" style="width:22px;height:22px;border-radius:6px">${I.sparkle}</div>
                <span style="font-size:13px;font-weight:700">Summary</span>
              </div>
              <span class="edit-hint">${I.pencil || ''}<span>Click to edit</span></span>
            </div>
            <div class="editable-field${p.description ? '' : ' is-empty'}" id="proj-desc" data-value="${esc(p.description || '')}" onclick="editProjectDesc(${p.id},this)" title="Click to edit summary" style="font-family:var(--sans);font-size:18px;line-height:1.5;font-weight:700">${esc(p.description || 'No description yet. Click to add one.')}</div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h3 style="font-size:24px;font-weight:700;color:var(--gold)">Tasks</h3>
            <button class="btn" onclick="toggleAddTask()">${I.plus}Add task</button>
          </div>
          <div id="add-task-area"></div>
          ${groups}
          ${subprojectsSection}
          <div style="margin-top:30px">${filesSection}</div>
        </div>
        <div class="chat-panel">
          <div class="chat-header">
            ${I.msg}
            <div><div style="font-size:14px;font-weight:700">Project chat</div><div style="font-size:12px;color:var(--muted)">${esc(p.name)} · ${(p.members||[]).length} members</div></div>
          </div>
          <div class="chat-messages" id="proj-chat">${chat}</div>
          <div class="chat-input-row">
            <div class="chat-input-wrap" style="position:relative">
              <input class="chat-input" id="proj-chat-input" placeholder="Message the team… use @ to mention" onkeydown="onProjChatKey(event,${p.id})" oninput="onProjChatInput()">
              <div class="mention-dropdown" id="proj-mention-dropdown" style="display:none"></div>
            </div>
            <button class="chat-send" onclick="sendProjChat(${p.id})">${I.send}</button>
          </div>
        </div>
      </div>
    </div>
  `;
}

function taskRow(t, projectId) {
  const assignees = (t.assignees || []).length ? t.assignees : (t.assignee_name ? [{initials: t.who, avatar_color: t.avBg, name: t.assignee_name}] : []);
  const shown = assignees.slice(0, 3).map(a => `<span class="task-avatar" title="${esc(a.name)}" style="background:${a.avatar_color}">${esc(a.initials || initialsOf(a.name))}</span>`).join('');
  const extra = assignees.length > 3 ? `<span class="task-avatar more">+${assignees.length - 3}</span>` : '';
  const assigneeHTML = (shown || extra) ? `<div class="task-avatars">${shown}${extra}</div>` : '<span class="task-avatar" style="background:#EAE0CE;color:var(--muted)">—</span>';
  return `<div class="task-row" onclick="openTask(${t.id},${projectId || (t.project_id || 'null')})" data-task-id="${t.id}">
    <div class="task-checkbox ${t.done ? 'done' : ''}" onclick="event.stopPropagation();toggleTask(${t.id},this)">${I.check}</div>
    <span class="task-name ${t.done ? 'done' : ''}">${esc(t.title)}</span>
    ${t.priority === 'urgent' ? '<span style="font-size:11px;font-weight:700;color:#FFF;background:#B0432B;border-radius:99px;padding:2px 8px">URGENT</span>' : ''}
    ${t.deadline_label ? `<span style="font-size:12px;color:${t.deadline_label.includes('Overdue') ? '#B0432B' : 'var(--muted)'}">${esc(t.deadline_label)}</span>` : ''}
    ${t.comment_count ? `<span class="comment-indicator">${I.comment}${t.comment_count}</span>` : ''}
    <div style="position:relative;flex:none">
      <button onclick="event.stopPropagation();toggleAgendaAddMenu(${t.id}, this)" title="Add to agenda" aria-label="Add to agenda" class="task-row-dots">${I.plus}</button>
      <div class="task-quick-menu" id="agenda-add-menu-${t.id}">
        <button onclick="event.stopPropagation();addToAgendaFromTask(${t.id},'${esc(t.title).replace(/'/g,"\\'")}',${projectId})">Add to Agenda</button>
      </div>
    </div>
    <div style="display:flex;align-items:center;margin-left:auto">${assigneeHTML}</div>
  </div>`;
}

let addingTask = false;
let selectedAssignees = [];
let taskAssigneePicker = null;
function toggleAddTask() {
  addingTask = !addingTask;
  selectedAssignees = [];
  const area = document.getElementById('add-task-area');
  if (!addingTask) { area.innerHTML = ''; return; }
  const project = State.activeProject;
  const maxDate = project?.due_date || '';
  // Map project members to the format AssigneePicker expects
  const pickerMembers = (project?.members || []).map(m => ({
    id: m.id, name: m.name, avatar_color: m.bg || m.avatar_color, initials: m.initials
  }));
  area.innerHTML = `
    <div class="add-task-form" style="animation:vgFade .2s ease both">
      <input id="new-task-title" placeholder="What needs to get done?" onkeydown="if(event.key==='Enter'){event.preventDefault();submitNewTask(${project.id})}">
      <div class="add-task-fields">
        <div class="add-task-field">
          <label class="add-task-label">Deadline</label>
          <input type="date" id="new-task-deadline" ${maxDate ? `max="${maxDate}"` : ''} class="add-task-date">
        </div>
        <div class="add-task-field" style="flex:1;min-width:220px">
          <label class="add-task-label">Assign to</label>
          <div id="assignee-picker-container"></div>
        </div>
      </div>
      ${maxDate ? `<div style="font-size:12px;color:var(--muted);margin-top:12px">Deadline capped at project due date (${maxDate})</div>` : ''}
      <div class="add-task-actions">
        <button class="btn-secondary" onclick="toggleAddTask()">Cancel</button>
        <button class="btn-primary" style="padding:8px 16px;font-size:13.5px" onclick="submitNewTask(${project.id})">Add task</button>
      </div>
    </div>`;
  document.getElementById('new-task-title').focus();
  // Render AssigneePicker
  const pickerContainer = document.getElementById('assignee-picker-container');
  taskAssigneePicker = AssigneePicker.render({
    members: pickerMembers,
    selectedIds: [],
    onChange: (ids) => { selectedAssignees = ids; }
  });
  pickerContainer.appendChild(taskAssigneePicker.el);
}

function toggleAgendaAddMenu(id, btn) {
  closeAllTaskMenus();
  const menu = document.getElementById('agenda-add-menu-' + id);
  if (menu) menu.classList.toggle('show');
}

function toggleAssigneeSelection(id, btn) {
  id = String(id);
  const idx = selectedAssignees.indexOf(id);
  if (idx > -1) {
    selectedAssignees.splice(idx, 1);
    btn.classList.remove('selected');
  } else {
    selectedAssignees.push(id);
    btn.classList.add('selected');
  }
}

async function submitNewTask(pid) {
  const title = document.getElementById('new-task-title').value.trim();
  if (!title) return;
  const deadline = document.getElementById('new-task-deadline')?.value || null;
  const assigneeIds = (taskAssigneePicker ? taskAssigneePicker.getSelected() : selectedAssignees).map(id => parseInt(id));
  try {
    await API.createTask({ project_id: pid, title, assignee_ids: assigneeIds, deadline_date: deadline });
    State.activeProject = null;
    addingTask = false;
    toast('Task added', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function toggleTask(id, el) {
  try {
    const res = await API.toggleTask(id);
    if (res.status === 'completed') {
      el.classList.add('done');
      el.parentElement.querySelector('.task-name')?.classList.add('done');
    } else {
      el.classList.remove('done');
      el.parentElement.querySelector('.task-name')?.classList.remove('done');
    }
    // Re-render project to update groups after a short delay
    setTimeout(() => {
      State.activeProject = null;
      State.meetingData = null;
      State.myTasksData = null;
      State.dayPlan = undefined;
      render();
    }, 600);
  } catch(e) { toast(e.message, 'error'); }
}

async function sendProjChat(pid) {
  const input = document.getElementById('proj-chat-input');
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  try {
    const res = await API.sendProjectChat(pid, text);
    const m = res.message;
    const chat = document.getElementById('proj-chat');
    chat.innerHTML += `<div class="chat-msg me"><div class="avatar avatar-sm" style="background:${m.bg}">${m.initials}</div><div><div style="display:flex;align-items:baseline;gap:7px;margin-bottom:3px"><span style="font-size:12.5px;font-weight:700">${esc(m.who)}</span><span style="font-size:11px;color:var(--muted)">now</span></div><div class="msg-bubble">${linkify(m.text)}</div></div></div>`;
    chat.scrollTop = chat.scrollHeight;
  } catch(e) { toast(e.message, 'error'); }
}

// B4a/B4b — upload one or more files, optionally into a specific folder.
// Uploads run sequentially and any failure is surfaced to the user (the old code
// swallowed errors, which masked the first-upload CSRF glitch). After a successful
// batch we invalidate the cached project and re-render so the new files show
// immediately — no manual refresh required.
async function uploadFiles(pid, files, folderId = null) {
  if (!pid) { toast('No project selected', 'error'); return; }
  if (!files || !files.length) return;
  let ok = 0, failed = 0;
  toast('Uploading' + (files.length > 1 ? ' ' + files.length + ' files…' : '…'), 'info');
  for (const f of files) {
    try {
      if (folderId) await API.uploadFileToFolder(pid, f, folderId);
      else await API.uploadFile(pid, f);
      ok++;
    } catch(e) { failed++; console.error('upload failed', e); }
  }
  if (ok) toast(ok + (ok === 1 ? ' file' : ' files') + ' uploaded', 'success');
  if (failed) toast(failed + (failed === 1 ? ' file' : ' files') + ' failed to upload', 'error');
  State.activeProject = null;
  render();
}
// ===== INLINE EDIT FUNCTIONS =====
function editProjectName(id, el) {
  if (el.querySelector('input')) return;
  const oldVal = el.dataset.value;
  const input = document.createElement('input');
  input.type = 'text';
  input.value = oldVal;
  input.className = 'inline-edit-input';
  input.style.fontFamily = 'var(--serif)';
  input.style.fontSize = '38px';
  input.style.color = 'var(--gold)';
  input.style.width = '100%';
  el.innerHTML = '';
  el.appendChild(input);
  input.focus();
  input.select();
  input.addEventListener('blur', async () => {
    const newVal = input.value.trim();
    if (newVal && newVal !== oldVal) {
      el.dataset.value = newVal;
      el.innerHTML = esc(newVal);
      await saveProjectField(id, 'name', newVal);
      render();
    } else {
      el.innerHTML = esc(oldVal);
    }
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    if (e.key === 'Escape') { el.innerHTML = esc(oldVal); el.dataset.value = oldVal; }
  });
}

function editProjectDesc(id, el) {
  if (el.querySelector('textarea')) return;
  const oldVal = el.dataset.value || '';
  const area = document.createElement('textarea');
  area.value = oldVal;
  area.className = 'inline-edit-area';
  area.style.fontFamily = 'var(--serif)';
  area.style.fontSize = '18px';
  area.style.width = '100%';
  area.rows = 3;
  el.innerHTML = '';
  el.appendChild(area);
  area.focus();
  area.addEventListener('blur', async () => {
    const newVal = area.value.trim();
    if (newVal !== oldVal) {
      el.dataset.value = newVal;
      el.innerHTML = esc(newVal || 'No description yet. Click to add one.');
      el.classList.toggle('is-empty', !newVal);
      await saveProjectField(id, 'description', newVal);
      State.activeProject = null;
      render();
    } else {
      el.innerHTML = esc(oldVal || 'No description yet. Click to add one.');
      el.classList.toggle('is-empty', !oldVal);
    }
  });
  area.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { el.innerHTML = esc(oldVal || 'No description yet. Click to add one.'); el.dataset.value = oldVal; el.classList.toggle('is-empty', !oldVal); }
  });
}

function editTaskTitle(taskId, el) {
  if (el.querySelector('input')) return;
  const oldVal = el.dataset.value || el.textContent.trim();
  const input = document.createElement('input');
  input.type = 'text';
  input.value = oldVal;
  input.className = 'inline-edit-input';
  input.style.fontSize = '25px';
  input.style.fontFamily = 'var(--serif)';
  input.style.color = 'var(--gold)';
  input.style.width = '100%';
  el.innerHTML = '';
  el.appendChild(input);
  input.focus();
  input.select();
  input.addEventListener('blur', async () => {
    const newVal = input.value.trim();
    if (newVal && newVal !== oldVal) {
      el.dataset.value = newVal;
      el.innerHTML = esc(newVal);
      await saveTaskField(taskId, 'title', newVal);
    } else {
      el.innerHTML = esc(oldVal);
    }
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    if (e.key === 'Escape') { el.innerHTML = esc(oldVal); }
  });
}


async function editFileFromId(id) {
  try {
    const res = await API.editFile(id);
    if (res.url) window.open(res.url, '_blank');
  } catch(e) { toast(e.message, 'error'); }
}


function initialsOf(name = '') {
  return name.trim().split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}


// ===== @MENTIONS for project chat =====
let projMentionSearch = '';
let projMentionTimer = null;
let projMentionActiveIndex = -1;
let projMentionUsers = [];

function onProjChatKey(e, pid) {
  const dropdown = document.getElementById('proj-mention-dropdown');
  if (dropdown && dropdown.style.display !== 'none' && dropdown.children.length) {
    if (e.key === 'ArrowDown') { e.preventDefault(); selectProjMention(1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); selectProjMention(-1); return; }
    if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); confirmProjMention(); return; }
    if (e.key === 'Escape') { hideProjMentionDropdown(); return; }
  }
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendProjChat(pid);
  }
}

function onProjChatInput() {
  const input = document.getElementById('proj-chat-input');
  if (!input) return;
  const text = input.value;
  const cursorPos = input.selectionStart;
  const beforeCursor = text.substring(0, cursorPos);
  const match = beforeCursor.match(/@(\w*)$/);
  if (match) {
    projMentionSearch = match[1];
    clearTimeout(projMentionTimer);
    projMentionTimer = setTimeout(() => fetchProjMentions(projMentionSearch), 150);
  } else {
    hideProjMentionDropdown();
  }
}

async function fetchProjMentions(q) {
  try {
    const res = await API.mentions(q);
    projMentionUsers = res.users || [];
    renderProjMentionDropdown(projMentionUsers);
  } catch(e) {}
}

function renderProjMentionDropdown(users) {
  const dropdown = document.getElementById('proj-mention-dropdown');
  if (!dropdown || !users.length) { if (dropdown) dropdown.style.display = 'none'; return; }
  projMentionActiveIndex = -1;
  dropdown.innerHTML = users.map((u, i) => `
    <div class="mention-item" id="proj-mention-${i}" onclick="insertProjMention(${i})" onmouseover="highlightProjMention(${i})">
      <div class="avatar avatar-sm" style="background:${u.color}">${u.initials}</div>
      <span>${esc(u.name)}</span>
    </div>
  `).join('');
  dropdown.style.display = 'block';
}

function selectProjMention(dir) {
  if (!projMentionUsers.length) return;
  projMentionActiveIndex = Math.max(-1, Math.min(projMentionUsers.length - 1, projMentionActiveIndex + dir));
  document.querySelectorAll('#proj-mention-dropdown .mention-item').forEach((el, i) => {
    el.style.background = i === projMentionActiveIndex ? 'var(--gold-bg)' : '';
  });
}

function confirmProjMention() {
  if (projMentionActiveIndex >= 0) insertProjMention(projMentionActiveIndex);
  else if (projMentionUsers.length) insertProjMention(0);
}

function insertProjMention(index) {
  const input = document.getElementById('proj-chat-input');
  const text = input.value;
  const user = projMentionUsers[index];
  if (!user) return;
  const cursorPos = input.selectionStart;
  const before = text.substring(0, cursorPos);
  const after = text.substring(cursorPos);
  const newBefore = before.replace(/@(\w*)$/, '@' + user.name + ' ');
  input.value = newBefore + after;
  input.focus();
  const newPos = newBefore.length;
  input.setSelectionRange(newPos, newPos);
  hideProjMentionDropdown();
}

function highlightProjMention(index) {
  projMentionActiveIndex = index;
  document.querySelectorAll('#proj-mention-dropdown .mention-item').forEach((el, i) => {
    el.style.background = i === index ? 'var(--gold-bg)' : '';
  });
}

function hideProjMentionDropdown() {
  const dropdown = document.getElementById('proj-mention-dropdown');
  if (dropdown) dropdown.style.display = 'none';
}
