// VGo — Ask modal + Task drawer
function renderAskModal() {
  if (!State.askOpen) return '';
  const suggestions = [
    { label: 'Summarize my projects', icon: I.grid },
    { label: 'What\'s blocking us right now?', icon: I.clock },
    { label: 'Plan my day', icon: I.sun, action: 'refreshDayPlan' },
    { label: 'Draft today\'s standup', icon: I.digest },
  ];
  return `
    <div class="ask-overlay" onclick="closeAsk(event)">
      <div class="ask-modal" onclick="event.stopPropagation()">
        <div class="ask-header">
          ${I.sparkle}
          <input class="ask-input" id="ask-input" placeholder="Ask anything, or tell VGo what to do…" onkeydown="if(event.key==='Enter')runAskFromInput();if(event.key==='Escape')closeAsk()">
          <span class="ask-esc">esc</span>
        </div>
        <div id="ask-body" class="ask-body">
          <div class="ask-suggestions">
            ${suggestions.map(s => `<button class="ask-suggestion" onclick="${s.action ? s.action + '()' : 'runAsk(\'' + esc(s.label) + '\')'}"><span class="icon">${s.icon}</span><span class="label">${esc(s.label)}</span></button>`).join('')}
          </div>
        </div>
      </div>
    </div>
  `;
}

function openAsk() { State.askOpen = true; render(); setTimeout(() => document.getElementById('ask-input')?.focus(), 50); }
function closeAsk(e) { if (e && e.target.closest('.ask-modal')) return; State.askOpen = false; State.askAnswer = null; render(); }

function navigateToTask(taskId, projectId) {
  if (!projectId) {
    API.myTasks().then(res => {
      const task = res.tasks.find(t => t.id === taskId);
      if (task) navigateToTask(taskId, task.project_id);
    });
    return;
  }
  closeAsk();
  goTaskPage(taskId, projectId);
}

function runAskFromInput() {
  const input = document.getElementById('ask-input');
  if (input && input.value.trim()) runAsk(input.value.trim());
}

async function refreshDayPlan() {
  closeAsk();
  State.screen = 'mytasks';
  State.dayPlan = undefined;
  render();
  toast('Day plan refreshed', 'success');
}

async function runAsk(prompt) {
  const body = document.getElementById('ask-body');
  body.innerHTML = `<div style="padding:20px 22px 24px 22px"><div style="display:flex;align-items:center;gap:9px;margin-bottom:14px"><div class="ai-badge" style="width:22px;height:22px;border-radius:6px">${I.sparkle}</div><span style="font-size:13px;font-weight:700">VGo is thinking…</span></div><p style="font-family:var(--serif);font-size:19px;color:var(--muted)">Analyzing your workspace…</p></div>`;
  try {
    const res = await API.ask(prompt);
    body.innerHTML = `<div class="ask-answer">
      <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px">
        <div class="ai-badge" style="width:22px;height:22px;border-radius:6px">${I.sparkle}</div>
        <span style="font-size:13px;font-weight:700">VGo</span>
      </div>
      <div class="ask-answer-html">${res.answer_html ? sanitizeHtml(res.answer_html) : esc(res.answer || 'No response')}</div>
    </div>`;
    // Wire up any ai-link clicks
    body.querySelectorAll('.ai-link').forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const type = link.dataset.type;
        const id = parseInt(link.dataset.id);
        if (!id || isNaN(id)) {
          // Try to find by text content
          const text = link.textContent.trim().toLowerCase();
          if (type === 'task') {
            API.myTasks().then(res => {
              const task = res.tasks.find(t => t.title.toLowerCase().includes(text) || text.includes(t.title.toLowerCase()));
              if (task) navigateToTask(task.id, task.project_id);
            });
          } else if (type === 'project') {
            API.projects().then(res => {
              const proj = res.projects.find(p => p.name.toLowerCase().includes(text) || text.includes(p.name.toLowerCase()));
              if (proj) { State.screen = 'project'; State.activeProjectId = proj.id; State.activeProject = null; closeAsk(); render(); }
            });
          }
          return;
        }
        if (type === 'task') {
          navigateToTask(id);
        } else if (type === 'project') {
          State.screen = 'project';
          State.activeProjectId = id;
          State.activeProject = null;
          closeAsk();
          render();
        }
      });
    });
  } catch(e) {
    body.innerHTML = `<div class="ask-answer"><p class="ask-answer-text" style="color:var(--barn)">Sorry, I couldn't connect right now. ${esc(e.message)}</p></div>`;
  }
}

// Task navigation — the old side "task drawer/popup" has been fully removed.
// Clicking a task always opens the full task page. projectId is passed in by the
// caller; if omitted we resolve it from the active project or a lookup so we
// never fall back to the drawer or silently do nothing.
function openTask(id, projectId) {
  let pid = projectId;
  if (pid === undefined || pid === null || pid === 'null') pid = null;
  if (pid == null && State.activeProject) pid = State.activeProject.id;
  if (pid != null) { goTaskPage(id, pid); return; }
  // Last resort: find the task's project via My Tasks, then navigate.
  API.myTasks().then(res => {
    const task = (res.tasks || []).find(t => t.id == id);
    goTaskPage(id, task ? task.project_id : null);
  }).catch(() => goTaskPage(id, null));
}

// ===== FILES MODAL =====
function renderFilesModal() {
  if (!State.filesOpen) return '';
  return `
    <div class="modal-overlay" onclick="closeFiles(event)">
      <div class="modal" style="width:680px" onclick="event.stopPropagation()">
        <div class="modal-header">
          <h2>Files</h2>
          <button class="drawer-close" onclick="closeFiles()">${I.close}</button>
        </div>
        <div class="modal-body" id="files-modal-body">
          <p style="color:var(--muted);text-align:center;padding:40px 0">Loading files...</p>
        </div>
      </div>
    </div>
  `;
}

function openFiles() {
  State.filesOpen = true;
  render();
  loadFilesModal();
}

function closeFiles(e) {
  if (e && e.target.closest('.modal')) return;
  State.filesOpen = false;
  render();
}

async function loadFilesModal() {
  const body = document.getElementById('files-modal-body');
  if (!body) return;
  try {
    const res = await fetch('/api/files', { credentials: 'same-origin' });
    const data = await res.json();
    const files = data.files || [];
    if (!files.length) {
      body.innerHTML = `<div class="empty-state" style="padding:40px 0"><div class="title">No files yet</div><div class="desc">Upload files from any project or category page.</div></div>`;
      return;
    }
    body.innerHTML = files.map(f => {
      return `
      <div class="file-row" style="margin-bottom:10px;cursor:pointer" onclick="viewFile(${f.id})">
        <div class="file-icon" style="background:${fileColor(f.ext)}"><span>${esc(f.ext)}</span></div>
        <div style="flex:1;min-width:0">
          <div class="file-name">${esc(f.name)}</div>
          <div class="file-meta">${esc(f.project_name || '—')} · ${esc(f.size)} · ${esc(f.by)} · ${esc(f.when)}</div>
        </div>
        <div class="file-actions" onclick="event.stopPropagation()">
          <button class="file-action-btn" onclick="viewFile(${f.id})" title="Open">${I.eye}</button>
          ${window.__authProvider === 'microsoft' ? `<button class="file-action-btn" onclick="editFileFromId(${f.id})" title="Edit">${I.pencil||''}</button>` : ''}
          <button class="file-action-btn" onclick="downloadFileFromId(${f.id})" title="Download">${I.download}</button>
          ${State.user?.role === 'admin' ? `<button class="file-action-btn danger" onclick="deleteFileFromId(${f.id})" title="Delete">${I.trash}</button>` : ''}
        </div>
      </div>
    `}).join('');
  } catch(e) {
    body.innerHTML = `<p style="color:var(--barn);text-align:center;padding:20px">${esc(e.message)}</p>`;
  }
}

function fileColor(ext) {
  const colors = { PDF: '#B0432B', DOC: '#4A7C9B', DOCX: '#4A7C9B', XLS: '#5B8C5A', XLSX: '#5B8C5A', PNG: '#C99520', JPG: '#C99520', JPEG: '#C99520', ZIP: '#8A6D4F', default: '#7e6549' };
  return colors[ext?.toUpperCase()] || colors.default;
}

async function viewFile(id) {
  // Try SharePoint preview first (Office for the web)
  try {
    const res = await API.previewFile(id);
    if (res.url) {
      window.open(res.url, '_blank');
      return;
    }
  } catch(e) { /* fall through to legacy */ }
  
  // Legacy: try inline view for images/PDFs, download for others
  window.open('/api/files/' + id + '/download?inline=1', '_blank');
}

async function editFileFromId(id) {
  try {
    const res = await API.editFile(id);
    if (res.url) window.open(res.url, '_blank');
  } catch(e) { toast(e.message, 'error'); }
}


// ===== Members Management Modal =====
let membersModalProjectId = null;
let membersModalData = null;

async function openMembersModal(projectId, projectName) {
  membersModalProjectId = projectId;
  const modal = document.getElementById('modal-root');
  modal.innerHTML = `
    <div class="modal-overlay" onclick="closeMembersModal(event)">
      <div class="modal-box" onclick="event.stopPropagation()" style="max-width:520px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
          <div>
            <h2 style="font-family:var(--sans);font-size:22px;font-weight:700">Manage members</h2>
            <div style="font-size:13px;color:var(--muted);margin-top:2px">${esc(projectName || '')}</div>
          </div>
          <button class="drawer-close" onclick="closeMembersModal()">${I.close}</button>
        </div>
        <div id="members-modal-body" style="min-height:80px">
          <div style="text-align:center;color:var(--muted);padding:20px">Loading…</div>
        </div>
      </div>
    </div>
  `;
  
  try {
    const res = await API.listMembers(projectId);
    membersModalData = res;
    renderMembersModalBody(res);
  } catch(e) {
    document.getElementById('members-modal-body').innerHTML = `<p style="color:var(--barn);text-align:center;padding:20px">${esc(e.message)}</p>`;
  }
}

function renderMembersModalBody(data) {
  const body = document.getElementById('members-modal-body');
  if (!body) return;
  
  const currentMembers = (data.members || []).map(m => `
    <div class="member-row" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <div class="avatar avatar-sm" style="background:${m.avatar_color}">${m.initials}</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700">${esc(m.name)}</div>
        <div style="font-size:12px;color:var(--muted)">${esc(m.email)}${m.role ? ' · ' + esc(m.role) : ''}</div>
      </div>
      ${m.role === 'Lead' ? '<span style="font-size:11px;font-weight:700;color:var(--primary-dark);background:var(--primary-bg);border-radius:99px;padding:3px 8px">Lead</span>' : ''}
      ${m.role !== 'Lead' ? `<button class="btn-icon-danger" onclick="removeProjectMember(${membersModalProjectId},${m.id},'${esc(m.name)}')" title="Remove">${I.trash || '✕'}</button>` : ''}
    </div>
  `).join('');
  
  const availableMembers = (data.available || []).map(m => `
    <div class="member-row" style="display:flex;align-items:center;gap:10px;padding:8px 0">
      <div class="avatar avatar-sm" style="background:${m.avatar_color}">${m.initials}</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700">${esc(m.name)}</div>
        <div style="font-size:12px;color:var(--muted)">${esc(m.email)}</div>
      </div>
      <button class="btn-secondary" style="padding:5px 12px;font-size:12px" onclick="addProjectMember(${membersModalProjectId},${m.id},'${esc(m.name)}')">Add</button>
    </div>
  `).join('');
  
  body.innerHTML = `
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Current members (${(data.members||[]).length})</div>
      ${currentMembers || '<div style="color:var(--muted);padding:12px 0">No members yet.</div>'}
    </div>
    ${availableMembers ? `
      <div>
        <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Add from workspace</div>
        ${availableMembers}
      </div>
    ` : '<div style="font-size:13px;color:var(--muted);padding:8px 0">All workspace members are already in this project.</div>'}
  `;
}

function closeMembersModal(e) {
  if (e && e.target.closest('.modal-box')) return;
  document.getElementById('modal-root').innerHTML = '';
  membersModalProjectId = null;
  membersModalData = null;
}

async function addProjectMember(pid, uid, name) {
  try {
    await API.addMember(pid, uid);
    toast(name + ' added', 'success');
    // Refresh modal
    const res = await API.listMembers(pid);
    membersModalData = res;
    renderMembersModalBody(res);
    // Invalidate all caches so cards update
    State.activeProject = null;
    State.activeCategory = null;
    State.projects = null;
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function removeProjectMember(pid, uid, name) {
  try {
    await API.removeMember(pid, uid);
    toast(name + ' removed', 'success');
    // Refresh modal
    const res = await API.listMembers(pid);
    membersModalData = res;
    renderMembersModalBody(res);
    // Invalidate all caches so cards update
    State.activeProject = null;
    State.activeCategory = null;
    State.projects = null;
    render();
  } catch(e) { toast(e.message, 'error'); }
}

