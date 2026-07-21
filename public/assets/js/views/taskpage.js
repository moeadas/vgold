// VGo — Task Page (full-page task view, replaces drawer)
let _taskPageData = null; // cached task page data

async function renderTaskPage(taskId) {
  if (!_taskPageData || _taskPageData.id !== taskId) {
    try {
      const res = await API.task(taskId);
      _taskPageData = res.task;
    } catch (e) {
      return `<div class="fade-in"><p style="color:var(--barn)">Task not found.</p><button class="back-link" onclick="goBackFromTask()">${I.arrowL}Back</button></div>`;
    }
  }

  const t = _taskPageData;
  const projectId = t.project_id || t.project?.id;
  const projectName = t.project_name || t.project?.name || '';
  const projectColor = t.project_color || t.project?.color || '#C99520';

  // Breadcrumb / back button
  const backHTML = `<button class="back-link" onclick="goBackFromTask()">${I.arrowL}Back to ${esc(projectName)}</button>`;

  // Status toggle: only in_progress / completed
  const isCompleted = t.status === 'completed';
  const statusHTML = `<button class="health-pill" id="task-status-toggle" style="cursor:pointer;color:${isCompleted ? '#6B8E5A' : '#C99520'};background:${isCompleted ? '#E8F0E4' : '#F0E8DC'};border:none" onclick="toggleTaskPageStatus(${t.id})">
    <span class="dot" style="background:${isCompleted ? '#6B8E5A' : '#C99520'}"></span>
    ${isCompleted ? 'Completed' : 'In Progress'}
  </button>`;

  // Assignees
  const assignees = t.assignees || [];
  const assigneeAvatars = assignees.length ? assignees.map(a => `<div class="avatar avatar-sm" style="background:${a.avatar_color || a.bg};margin-right:-6px;border:2px solid var(--surface)" title="${esc(a.name)}">${esc(a.initials)}</div>`).join('') : '<span style="font-size:13px;color:var(--muted)">Unassigned</span>';

  // Comments
  const comments = (t.comments || []).map(c => `
    <div style="display:flex;gap:11px;margin-bottom:16px">
      <div class="avatar" style="width:30px;height:30px;font-size:11px;background:${c.bg || c.avatar_color}">${esc(c.initials)}</div>
      <div style="min-width:0">
        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:3px"><span style="font-size:13px;font-weight:700">${esc(c.who || c.name)}</span><span style="font-size:11px;color:var(--muted)">${esc(c.time || c.time_ago)}</span></div>
        <div style="font-size:14px;line-height:1.5">${linkify(c.text || c.body)}</div>
      </div>
    </div>
  `).join('');

  // Files
  const taskFiles = (t.files || []).map(f => {
    const colorMap = { PDF: '#B0432B', DOC: '#4A7C9B', DOCX: '#4A7C9B', XLS: '#5B8C5A', XLSX: '#5B8C5A', PNG: '#C99520', JPG: '#C99520', JPEG: '#C99520', ZIP: '#8A6D4F', CSV: '#4A7C9B' };
    const fc = colorMap[(f.ext || '').toUpperCase()] || '#6B5A4A';
    return `<div class="file-row" style="cursor:pointer" onclick="viewTaskFile(${f.id})">
      <div class="file-icon" style="background:${fc}"><span>${esc(f.ext || 'FILE')}</span></div>
      <div style="flex:1;min-width:0">
        <div class="file-name">${esc(f.name || f.filename)}</div>
        <div class="file-meta">${esc(f.size || '')} · ${esc(f.by || '')} · ${esc(f.when || '')}</div>
      </div>
      <div class="file-actions" onclick="event.stopPropagation()">
        <button class="file-action-btn" onclick="viewTaskFile(${f.id})" title="Open">${I.eye}</button>
        <button class="file-action-btn" onclick="downloadTaskFile(${f.id})" title="Download">${I.download}</button>
        ${State.user?.role === 'admin' || f.uploaded_by === State.user?.id ? `<button class="file-action-btn danger" onclick="deleteTaskFile(${t.id},${f.id})" title="Delete">${I.trash}</button>` : ''}
      </div>
    </div>`;
  }).join('');

  return `
    <div class="fade-in">
      ${backHTML}
      <div style="display:flex;align-items:center;gap:12px;margin:14px 0 18px">
        ${statusHTML}
        <span style="font-size:13px;color:var(--muted)">·</span>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="width:8px;height:8px;border-radius:99px;background:${projectColor}"></span>
          <span style="font-size:13px;color:var(--muted)">${esc(projectName)}</span>
        </div>
      </div>
      <h1 id="task-page-title" data-value="${esc(t.title)}" onclick="editTaskPageTitle(${t.id},this)" title="Click to edit" style="font-family:var(--serif);font-weight:500;font-size:28px;line-height:1.2;margin-bottom:20px;cursor:text;border-radius:8px;padding:2px 6px;margin-left:-6px">${esc(t.title)}</h1>
      <div style="position:relative;display:inline-block;margin-bottom:20px">
        <button onclick="event.stopPropagation();toggleAgendaAddMenu('task-${t.id}', this)" title="Add to agenda" aria-label="Add to agenda" class="task-row-dots">${I.plus}</button>
        <div class="task-quick-menu" id="agenda-add-menu-task-${t.id}" style="top:100%;left:0;right:auto">
          <button onclick="event.stopPropagation();addToAgendaFromTask(${t.id},'${esc(t.title).replace(/'/g,"\\'")}',${projectId})">Add to Agenda</button>
        </div>
      </div>
      
      <div class="grid-2-proj">
        <div>
          <!-- Assignees -->
          <div style="margin-bottom:24px">
            <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Assignees</div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">${assigneeAvatars}</div>
            <div id="task-page-assignee-picker"></div>
          </div>

          <!-- Description -->
          <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
              <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Description</div>
              <span class="edit-hint">${I.pencil || ''}<span>Click to edit</span></span>
            </div>
            <div id="task-page-desc" class="editable-field${t.description ? '' : ' is-empty'}" data-value="${esc(t.description || '')}" onclick="editTaskPageDesc(${t.id},this)" title="Click to edit description" style="font-family:var(--serif);font-size:17px;line-height:1.6">${esc(t.description || 'No description yet. Click to add one.')}</div>
          </div>

          <!-- Files -->
          <div class="cat-toggle-section" style="margin-top:8px">
            <button class="cat-toggle-header" onclick="toggleCatSection('task-files-body')">
              <span style="display:flex;align-items:center;gap:8px">
                <span class="cat-toggle-chevron" id="task-files-chevron">${I.arrowR}</span>
                <span style="font-size:22px;font-family:var(--serif);font-weight:400;color:var(--gold)">Files</span>
                <span style="font-size:13px;color:var(--muted)">(${(t.files||[]).length})</span>
              </span>
            </button>
            <div class="cat-toggle-body" id="task-files-body" style="display:none">
              <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
                <label class="btn" style="cursor:pointer">${I.upload}Upload<input type="file" multiple style="display:none" onchange="uploadTaskFiles(${t.id},this.files)"></label>
              </div>
              <div class="task-upload-zone" id="task-upload-zone" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="event.preventDefault();this.classList.remove('drag-over');uploadTaskFiles(${t.id},event.dataTransfer.files)" onclick="this.querySelector('input').click()">
                ${I.upload} Drag files here or click to upload
                <input type="file" multiple style="display:none" onchange="uploadTaskFiles(${t.id},this.files)">
              </div>
              <div id="task-files-list" style="display:flex;flex-direction:column;gap:8px;margin-top:12px">${taskFiles || '<div style="font-size:14px;color:var(--muted);padding:8px 0">No files attached.</div>'}</div>
            </div>
          </div>

          <!-- Delete button -->
          <div style="margin-top:30px">
            <button onclick="deleteTaskFromPage(${t.id})" title="Delete task" style="background:none;border:1px solid var(--border);cursor:pointer;padding:8px 12px;border-radius:8px;color:#B0432B;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:all .15s" onmouseover="this.style.background='#F4D6CC';this.style.borderColor='#B0432B'" onmouseout="this.style.background='none';this.style.borderColor='var(--border)'">${I.trash}<span>Delete task</span></button>
          </div>
        </div>

        <!-- Comments panel -->
        <div class="chat-panel">
          <div class="chat-header">
            ${I.comment || I.msg}
            <div><div style="font-size:14px;font-weight:700">Comments</div><div style="font-size:12px;color:var(--muted)">${(t.comments || []).length} comments</div></div>
          </div>
          <div class="chat-messages" id="task-page-comments" style="padding:16px 18px;overflow-y:auto;flex:1">
            ${comments || '<div style="font-size:14px;color:var(--muted);padding:20px 0">No comments yet. Start the conversation below.</div>'}
          </div>
          <div class="chat-input-row">
            <div class="chat-input-wrap" style="position:relative">
              <input class="chat-input" id="task-page-comment-input" placeholder="Add a comment… use @ to mention" onkeydown="onTaskPageCommentKey(event,${t.id})" oninput="onTaskPageCommentInput()">
              <div class="mention-dropdown" id="task-page-mention-dropdown" style="display:none"></div>
            </div>
            <button class="chat-send" onclick="sendTaskPageComment(${t.id})">${I.send}</button>
          </div>
        </div>
      </div>
    </div>
  `;
}

// Navigate to task page
function goTaskPage(taskId, projectId) {
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

// Go back from task page
function goBackFromTask() {
  if (State.activeTaskProjectId) {
    goProject(State.activeTaskProjectId);
  } else {
    nav('mytasks');
  }
}

// Toggle task status (in_progress <-> completed)
async function toggleTaskPageStatus(taskId) {
  const t = _taskPageData;
  if (!t) return;
  const newStatus = t.status === 'completed' ? 'in_progress' : 'completed';
  try {
    await API.updateTask(taskId, { status: newStatus });
    _taskPageData.status = newStatus;
    State.myTasksData = null;
    State.meetingData = null;
    State.dayPlan = undefined;
    render();
    toast('Status updated', 'success');
  } catch (e) { toast(e.message, 'error'); }
}

// Inline edit task title on page
function editTaskPageTitle(taskId, el) {
  if (el.querySelector('input')) return;
  const oldVal = el.dataset.value || el.textContent.trim();
  const input = document.createElement('input');
  input.type = 'text';
  input.value = oldVal;
  input.className = 'inline-edit-input';
  input.style.fontSize = '28px';
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
      _taskPageData.title = newVal;
    } else {
      el.innerHTML = esc(oldVal);
    }
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    if (e.key === 'Escape') { el.innerHTML = esc(oldVal); }
  });
}

// Inline edit task description on page
function editTaskPageDesc(taskId, el) {
  if (el.querySelector('textarea')) return;
  const oldVal = el.dataset.value || '';
  const area = document.createElement('textarea');
  area.value = oldVal;
  area.className = 'inline-edit-area';
  area.style.fontFamily = 'var(--serif)';
  area.style.fontSize = '17px';
  area.style.width = '100%';
  area.rows = 4;
  el.innerHTML = '';
  el.appendChild(area);
  area.focus();
  area.addEventListener('blur', async () => {
    const newVal = area.value.trim();
    if (newVal !== oldVal) {
      el.dataset.value = newVal;
      el.innerHTML = esc(newVal || 'No description yet. Click to add one.');
      el.classList.toggle('is-empty', !newVal);
      await saveTaskField(taskId, 'description', newVal);
      _taskPageData.description = newVal;
    } else {
      el.innerHTML = esc(oldVal || 'No description yet. Click to add one.');
      el.classList.toggle('is-empty', !oldVal);
    }
  });
  area.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { el.innerHTML = esc(oldVal || 'No description yet. Click to add one.'); el.dataset.value = oldVal; el.classList.toggle('is-empty', !oldVal); }
  });
}

// Upload task files
async function uploadTaskFiles(taskId, files) {
  if (!files || !files.length) return;
  for (const f of files) {
    try { await API.uploadTaskFile(taskId, f); } catch (e) { console.error(e); toast(e.message, 'error'); }
  }
  _taskPageData = null;
  toast('Files uploaded', 'success');
  render();
  // Re-render assignee picker after render
  setTimeout(() => renderTaskPageAssigneePicker(), 50);
}

// View / download / delete task files
async function viewTaskFile(fileId) {
  try {
    const res = await API.previewFile(fileId);
    if (res.url) { window.open(res.url, '_blank'); return; }
  } catch (e) {}
  window.open('/api/files/' + fileId + '/download?inline=1', '_blank');
}

async function downloadTaskFile(fileId) {
  window.open('/api/files/' + fileId + '/download', '_blank');
}

async function deleteTaskFile(taskId, fileId) {
  appConfirm('Delete this file?', async () => {
    try {
      await API.deleteFile(fileId);
      _taskPageData = null;
      toast('File deleted', 'success');
      render();
      setTimeout(() => renderTaskPageAssigneePicker(), 50);
    } catch (e) { toast(e.message, 'error'); }
  });
}

// Delete task from page
async function deleteTaskFromPage(taskId) {
  appConfirm('Delete this task? This cannot be undone.', async () => {
    try {
      await API.deleteTask(taskId);
      _taskPageData = null;
      State.myTasksData = null;
      State.meetingData = null;
      State.dayPlan = undefined;
      toast('Task deleted', 'success');
      goBackFromTask();
    } catch (e) { toast(e.message, 'error'); }
  });
}

// Send comment from task page
async function sendTaskPageComment(taskId) {
  const input = document.getElementById('task-page-comment-input');
  if (!input) return;
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  hideTaskPageMentionDropdown();
  try {
    const res = await API.addComment(taskId, text);
    const c = res.comment;
    if (_taskPageData) {
      _taskPageData.comments = [...(_taskPageData.comments || []), c];
    }
    // B5: update the comment list in place instead of a full re-render so the
    // input keeps focus and typing never "stops working".
    const cm = document.getElementById('task-page-comments');
    if (cm && c) {
      // Clear the "no comments yet" placeholder on first comment.
      if (_taskPageData && _taskPageData.comments.length === 1) cm.innerHTML = '';
      cm.insertAdjacentHTML('beforeend', `
        <div style="display:flex;gap:11px;margin-bottom:16px">
          <div class="avatar" style="width:30px;height:30px;font-size:11px;background:${c.bg || c.avatar_color}">${esc(c.initials)}</div>
          <div style="min-width:0">
            <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:3px"><span style="font-size:13px;font-weight:700">${esc(c.who || c.name)}</span><span style="font-size:11px;color:var(--muted)">${esc(c.time || c.time_ago || 'now')}</span></div>
            <div style="font-size:14px;line-height:1.5">${linkify(c.text || c.body || '')}</div>
          </div>
        </div>`);
      cm.scrollTop = cm.scrollHeight;
    }
    input.focus();
  } catch (e) { toast(e.message, 'error'); }
}

// @mentions for task page comments
let _taskPageMentionSearch = '';
let _taskPageMentionTimer = null;
let _taskPageMentionActiveIndex = -1;
let _taskPageMentionUsers = [];

function onTaskPageCommentKey(e, taskId) {
  const dropdown = document.getElementById('task-page-mention-dropdown');
  if (dropdown && dropdown.style.display !== 'none' && dropdown.children.length) {
    if (e.key === 'ArrowDown') { e.preventDefault(); selectTaskPageMention(1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); selectTaskPageMention(-1); return; }
    if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); confirmTaskPageMention(); return; }
    if (e.key === 'Escape') { hideTaskPageMentionDropdown(); return; }
  }
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendTaskPageComment(taskId);
  }
}

function onTaskPageCommentInput() {
  const input = document.getElementById('task-page-comment-input');
  if (!input) return;
  const text = input.value;
  const cursorPos = input.selectionStart;
  const beforeCursor = text.substring(0, cursorPos);
  const match = beforeCursor.match(/@(\w*)$/);
  if (match) {
    _taskPageMentionSearch = match[1];
    clearTimeout(_taskPageMentionTimer);
    _taskPageMentionTimer = setTimeout(() => fetchTaskPageMentions(_taskPageMentionSearch), 150);
  } else {
    hideTaskPageMentionDropdown();
  }
}

async function fetchTaskPageMentions(q) {
  try {
    const res = await API.mentions(q);
    _taskPageMentionUsers = res.users || [];
    renderTaskPageMentionDropdown(_taskPageMentionUsers);
  } catch (e) {}
}

function renderTaskPageMentionDropdown(users) {
  const dropdown = document.getElementById('task-page-mention-dropdown');
  if (!dropdown || !users.length) { if (dropdown) dropdown.style.display = 'none'; return; }
  _taskPageMentionActiveIndex = -1;
  dropdown.innerHTML = users.map((u, i) => `
    <div class="mention-item" id="task-page-mention-${i}" onclick="insertTaskPageMention(${i})" onmouseover="highlightTaskPageMention(${i})">
      <div class="avatar avatar-sm" style="background:${u.color}">${esc(u.initials)}</div>
      <span>${esc(u.name)}</span>
    </div>
  `).join('');
  dropdown.style.display = 'block';
}

function selectTaskPageMention(dir) {
  if (!_taskPageMentionUsers.length) return;
  _taskPageMentionActiveIndex = Math.max(-1, Math.min(_taskPageMentionUsers.length - 1, _taskPageMentionActiveIndex + dir));
  document.querySelectorAll('#task-page-mention-dropdown .mention-item').forEach((el, i) => {
    el.style.background = i === _taskPageMentionActiveIndex ? 'var(--gold-bg)' : '';
  });
}

function confirmTaskPageMention() {
  if (_taskPageMentionActiveIndex >= 0) insertTaskPageMention(_taskPageMentionActiveIndex);
  else if (_taskPageMentionUsers.length) insertTaskPageMention(0);
}

function insertTaskPageMention(index) {
  const input = document.getElementById('task-page-comment-input');
  const text = input.value;
  const user = _taskPageMentionUsers[index];
  if (!user) return;
  const cursorPos = input.selectionStart;
  const before = text.substring(0, cursorPos);
  const after = text.substring(cursorPos);
  const newBefore = before.replace(/@(\w*)$/, '@' + user.name + ' ');
  input.value = newBefore + after;
  input.focus();
  const newPos = newBefore.length;
  input.setSelectionRange(newPos, newPos);
  hideTaskPageMentionDropdown();
}

function highlightTaskPageMention(index) {
  _taskPageMentionActiveIndex = index;
  document.querySelectorAll('#task-page-mention-dropdown .mention-item').forEach((el, i) => {
    el.style.background = i === index ? 'var(--gold-bg)' : '';
  });
}

function hideTaskPageMentionDropdown() {
  const dropdown = document.getElementById('task-page-mention-dropdown');
  if (dropdown) dropdown.style.display = 'none';
}

// Render assignee picker on the task page
function renderTaskPageAssigneePicker() {
  const pickerEl = document.getElementById('task-page-assignee-picker');
  if (!pickerEl || typeof AssigneePicker === 'undefined') return;
  // We need project members — fetch from the task's project
  const projectId = State.activeTaskProjectId || (_taskPageData && _taskPageData.project_id);
  if (!projectId) return;
  API.project(projectId).then(res => {
    const proj = res.project;
    const projMembers = (proj.members || []).map(m => ({
      id: m.id, name: m.name, avatar_color: m.bg || m.avatar_color, initials: m.initials
    }));
    const selectedIds = (_taskPageData.assignees || []).map(a => String(a.id));
    const picker = AssigneePicker.render({
      members: projMembers,
      selectedIds: selectedIds,
      onChange: (ids) => syncTaskPageAssignees(_taskPageData.id, ids)
    });
    pickerEl.innerHTML = '';
    pickerEl.appendChild(picker.el);
  }).catch(() => {});
}

async function syncTaskPageAssignees(taskId, assigneeIds) {
  try {
    await API.updateTask(taskId, { assignee_ids: assigneeIds.map(id => parseInt(id)) });
    toast('Assignees updated', 'success');
  } catch (e) { toast(e.message, 'error'); }
}
