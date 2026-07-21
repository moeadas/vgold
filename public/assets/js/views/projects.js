// Unread chat KPI badge helper
function unreadKPI(counts, id) {
  const n = counts?.[id] || 0;
  if (!n) return '';
  const msgIcon = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>';
  return `<div style="margin-bottom:10px"><span style="display:inline-flex;align-items:center;gap:4px;border-radius:99px;background:#7a5c1e;color:#fff;font-size:11px;font-weight:700;padding:3px 8px;line-height:1;flex:none">${msgIcon}<span>${n > 99 ? '99+' : n}</span></span></div>`;
}

// ===== B6 — PER-USER CARD ORDERING =====
// Load the user's saved card orders once. scope 0 = top-level category grid,
// scope = categoryId for a category's sub-project grid.
async function ensureCardOrders() {
  if (State.cardOrders) return;
  try { const res = await API.cardOrder(); State.cardOrders = res.orders || {}; }
  catch(e) { State.cardOrders = {}; }
}

// Reorder `items` (each with .id) by the saved order for `scopeId`. Unknown/new
// items are appended in their original order so nothing disappears.
function applyCardOrder(items, scopeId) {
  const order = (State.cardOrders && State.cardOrders[scopeId]) || null;
  if (!order || !order.length) return items;
  const byId = new Map(items.map(it => [it.id, it]));
  const out = [];
  order.forEach(id => { if (byId.has(id)) { out.push(byId.get(id)); byId.delete(id); } });
  byId.forEach(it => out.push(it)); // leftovers (newly created cards)
  return out;
}

// Persist the current DOM order of a grid to the server for this user.
async function persistCardOrder(gridEl, scopeId) {
  if (!gridEl) return;
  const ids = Array.from(gridEl.querySelectorAll('[data-card-id]')).map(el => parseInt(el.dataset.cardId)).filter(Boolean);
  if (!State.cardOrders) State.cardOrders = {};
  State.cardOrders[scopeId] = ids;
  try { await API.saveCardOrder(scopeId, ids); } catch(e) { /* non-fatal */ }
}

// Wire native HTML5 drag-and-drop on all draggable cards within a grid.
let _dragCardEl = null;
function initCardDragging(gridSelector, scopeId) {
  const grid = document.querySelector(gridSelector);
  if (!grid) return;
  grid.querySelectorAll('[data-card-id]').forEach(card => {
    card.setAttribute('draggable', 'true');
    card.addEventListener('dragstart', (e) => {
      _dragCardEl = card;
      card.classList.add('card-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', card.dataset.cardId); } catch(_) {}
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('card-dragging');
      grid.querySelectorAll('.card-drag-over').forEach(el => el.classList.remove('card-drag-over'));
      _dragCardEl = null;
      persistCardOrder(grid, scopeId);
    });
    card.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (!_dragCardEl || _dragCardEl === card) return;
      const rect = card.getBoundingClientRect();
      const after = (e.clientY - rect.top) > rect.height / 2;
      if (after) card.after(_dragCardEl); else card.before(_dragCardEl);
    });
  });
}

// VGo — Projects view: Simple category cards → click to see projects grid
async function renderProjects() {
  let projects = State.projects;
  if (!projects) {
    try { const res = await API.projects(); projects = res.projects; State.projects = projects; } catch(e) { projects = []; }
  }
  
  // Fetch unread chat counts
  if (!State.unreadCounts) {
    try { const res = await API.unreadChatCounts(); State.unreadCounts = res.unread || {}; } catch(e) { State.unreadCounts = {}; }
  }

  // B6: apply the user's saved card order for the top-level grid (scope 0).
  await ensureCardOrders();
  projects = applyCardOrder(projects, 0);
  
  const greeting = getGreeting();

  const grid = projects.length ? projects.map(p => {
    const memberAvatars = (p.members || []).slice(0, 5).map(m =>
      `<div class="avatar avatar-sm" style="background:${m.avatar_color};border:2px solid var(--surface);margin-left:-7px">${m.initials}</div>`
    ).join('');
    const extraMembers = (p.members || []).length > 5 ? `<div class="avatar avatar-sm" style="background:var(--sand);color:var(--text-2);border:2px solid var(--surface);margin-left:-7px">+${p.members.length - 5}</div>` : '';
    const subCount = p.sub_project_count ?? (p.sub_projects || []).length;

    return `
      <div class="project-card category-card" data-card-id="${p.id}" onclick="goCategory(${p.id})">
        <span class="card-drag-handle" title="Drag to reorder" onclick="event.stopPropagation()">${I.grip || '⠿'}</span>
        ${State.user?.role === 'admin' ? `<button class="cat-delete-btn" onclick="event.stopPropagation();deleteCategory(${p.id})" title="Delete category">${I.trash}</button>` : ''}
        ${unreadKPI(State.unreadCounts, p.id)}<div class="pc-title">${esc(p.name)}</div>
        <div class="cat-stats">
          <div class="cat-stat">
            <span class="cat-stat-num">${subCount}</span>
            <span class="cat-stat-label">${subCount === 1 ? 'Project' : 'Projects'}</span>
          </div>
          <div class="cat-stat">
            <span class="cat-stat-num">${p.total_tasks ?? 0}</span>
            <span class="cat-stat-label">${(p.total_tasks ?? 0) === 1 ? 'Task' : 'Tasks'}</span>
          </div>
          <div class="cat-stat">
            <span class="cat-stat-num">${p.total_files ?? 0}</span>
            <span class="cat-stat-label">${(p.total_files ?? 0) === 1 ? 'File' : 'Files'}</span>
          </div>
        </div>
        <div class="pc-footer">
          <div style="display:flex;align-items:center;gap:6px">
            <div class="pc-team">${memberAvatars}${extraMembers}</div>
            <button class="btn-secondary" style="padding:4px 10px;font-size:11px;flex:none" onclick="event.stopPropagation();openMembersModal(${p.id},'${esc(p.name).replace(/'/g,"\'")}')">Manage</button>
          </div>
        </div>
      </div>
    `;
  }).join('') : `<div style="grid-column:1/-1" class="empty-state">
    <div class="title">No categories yet</div>
    <div class="desc">Create your first category to get started.</div>
  </div>`;

  return `
    <div class="fade-in">
      <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:18px">
        <div>
          <div class="section-label">Your workspace</div>
          <h1 class="page-title-sm">${greeting}</h1>
        </div>
        <button class="btn-primary" onclick="openNewProjectModal()">${I.plus}<span>New category</span></button>
      </div>
      <div class="project-grid">${grid}</div>
    </div>
  `;
}

// ===== Category detail page: shows grid of projects under a category =====
async function renderCategory() {
  let cat = State.activeCategory;
  if (!cat || cat.id != State.activeCategoryId) {
    try {
      const res = await API.category(State.activeCategoryId);
      cat = res.category;
      State.activeCategory = cat;
    } catch(e) { return '<div class="fade-in"><p>Category not found.</p></div>'; }
  }

  await ensureCardOrders();
  const projects = applyCardOrder(cat.projects || [], cat.id);

  const grid = projects.length ? projects.map(p => {
    const memberAvatars = (p.members || []).slice(0, 5).map(m =>
      `<div class="avatar avatar-sm" style="background:${m.avatar_color};border:2px solid var(--surface);margin-left:-7px">${m.initials}</div>`
    ).join('');

    return `
      <div class="project-card" data-card-id="${p.id}" onclick="goProject(${p.id})">
        <span class="card-drag-handle" title="Drag to reorder" onclick="event.stopPropagation()">${I.grip || '⠿'}</span>
        ${State.user?.role === 'admin' ? `<button class="cat-delete-btn" onclick="event.stopPropagation();deleteProject(${p.id})" title="Delete project">${I.trash}</button>` : ''}
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px">
          ${unreadKPI(State.unreadCounts, p.id)}<div class="pc-title">${esc(p.name)}</div>
          <span class="health-pill" style="color:${p.health_color};background:${p.healthBg || '#E5E6D2'};flex:none"><span class="dot" style="background:${p.health_color}"></span>${p.health_label}</span>
        </div>
        <div class="pc-metrics">
          <div class="pc-metric"><span class="num">${p.open_tasks ?? 0}</span><span class="label">Open</span></div>
          <div class="pc-metric"><span class="num">${p.completed_tasks ?? 0}</span><span class="label">Done</span></div>
          <div class="pc-metric"><span class="num">${p.total_files ?? 0}</span><span class="label">${(p.total_files ?? 0) === 1 ? 'File' : 'Files'}</span></div>
          <div class="pc-metric"><span class="num">${p.progress}% <span style="font-size:12px;font-weight:600;color:var(--muted)">(${p.completed_tasks ?? 0}/${p.total_tasks ?? 0})</span></span><span class="label">Progress</span></div>
        </div>
        <div class="progress-bar"><div class="fill" style="width:${p.progress}%;background:${p.health_color}"></div></div>
        <div class="pc-footer">
          <div style="display:flex;align-items:center;gap:6px">
            <div class="pc-team">${memberAvatars}</div>
            <button class="btn-secondary" style="padding:4px 10px;font-size:11px;flex:none" onclick="event.stopPropagation();openMembersModal(${cat.id},'${esc(cat.name).replace(/'/g,"\'")}')">Manage</button>
          </div>
          <span style="font-size:12.5px;color:var(--muted)">${esc(p.due_label || '')}</span>
        </div>
      </div>
    `;
  }).join('') : `<div style="grid-column:1/-1" class="empty-state">
    <div class="title">No projects yet</div>
    <div class="desc">Add a project to this category.</div>
  </div>`;

  return `
    <div class="fade-in">
      <button class="back-link" onclick="nav('projects')">${I.arrowL}All Projects</button>
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px">
        <div style="min-width:0">
          <h1 class="page-title-sm">${esc(cat.name)}</h1>
          ${cat.description ? `<p class="page-desc">${esc(cat.description)}</p>` : ''}
        </div>
        <button class="btn-primary" style="flex:none" onclick="openNewProjectModal(${cat.id},'${esc(cat.name)}')">${I.plus}<span>Add project</span></button>
      </div>
      <div class="project-grid">${grid}</div>
      
      <div id="cat-tasks-section" style="margin-top:20px">${renderCollapsibleTasks(cat, projects)}</div>
      <div id="cat-all-files-section" style="margin-top:14px"></div>
    </div>
  `;
}

async function loadCategoryFiles(catId) {
  try {
    const res = await fetch('/api/files?category=' + catId, { credentials: 'same-origin' });
    const data = await res.json();
    const files = data.files || [];
    // Cache for the file-search feature
    State.catFilesCache = files;
    // Populate the "All Files" collapsible section only (no duplicated full list)
    const allFilesSection = document.getElementById('cat-all-files-section');
    if (allFilesSection) {
      allFilesSection.innerHTML = renderCollapsibleFiles(files, 'cat');
    }
  } catch(e) {}
}

// ===== COLLAPSIBLE TASKS & FILES FOR CATEGORY =====
function renderCollapsibleTasks(cat, projects) {
  // Gather all tasks from all sub-projects
  let allTasks = [];
  (projects || []).forEach(p => {
    // We need to fetch tasks for each project — but the category API doesn't include tasks
    // We'll use the groups from each project, but since we don't have them loaded,
    // we'll store project info and load tasks via API
  });
  const totalTasks = projects.reduce((sum, p) => sum + (p.total_tasks || 0), 0);
  return `
    <div class="cat-toggle-section">
      <button class="cat-toggle-header" onclick="toggleCatSection('cat-all-tasks-body')">
        <span style="display:flex;align-items:center;gap:8px">
          <span class="cat-toggle-chevron" id="cat-all-tasks-chevron">${I.arrowR}</span>
          <span style="font-size:18px;font-weight:700;color:var(--gold)">All Tasks</span>
          <span style="font-size:13px;color:var(--muted)">(${totalTasks})</span>
        </span>
      </button>
      <div class="cat-toggle-body" id="cat-all-tasks-body" style="display:none">
        <div style="padding:16px 0;color:var(--muted);font-size:14px">Loading tasks…</div>
      </div>
    </div>
  `;
}

// Reusable collapsible + searchable files section.
// scope is a unique prefix (e.g. 'cat', 'proj') so ids don't collide.
// B4 — folder-aware file browser. `folders` is a flat list [{id,name,parent_folder_id,file_count}].
// Files with folder_id === null live at the project root. `scope` is 'proj' or 'cat'.
// For 'proj', uploads target the active project directly; for 'cat', uploadCategoryFiles
// picks the first sub-project (folders/links are only offered in the 'proj' scope where a
// concrete project id exists).
function renderCollapsibleFiles(files, scope = 'cat', folders = null) {
  files = files || [];
  const count = files.length;
  const bodyId = scope + '-all-files-body';
  const chevronId = scope + '-all-files-chevron';
  const searchId = scope + '-file-search';
  const listId = scope + '-file-list';
  const uploadFn = scope === 'proj' ? 'uploadFiles' : 'uploadCategoryFiles';
  const uploadId = scope === 'proj' ? 'State.activeProjectId' : 'State.activeCategory?.id';
  const supportsFolders = scope === 'proj';
  folders = supportsFolders ? (folders || []) : [];

  // Split files into root vs. by-folder buckets.
  const rootFiles = files.filter(f => !f.folder_id);
  const byFolder = {};
  folders.forEach(fo => { byFolder[fo.id] = []; });
  files.forEach(f => { if (f.folder_id && byFolder[f.folder_id]) byFolder[f.folder_id].push(f); });

  // Folder blocks (top-level folders only; nesting kept flat for simplicity).
  const folderBlocks = folders.filter(fo => !fo.parent_folder_id).map(fo => {
    const inside = byFolder[fo.id] || [];
    const fBodyId = `folder-body-${fo.id}`;
    return `
      <div class="folder-block" data-folder-id="${fo.id}">
        <div class="folder-header">
          <button class="folder-toggle" onclick="toggleFolder('${fBodyId}', this)">
            <span class="folder-chevron">${I.arrowR}</span>
            <span class="folder-icon">${I.folder || '📁'}</span>
            <span class="folder-name">${esc(fo.name)}</span>
            <span class="folder-count">${inside.length}</span>
          </button>
          <div class="folder-actions" onclick="event.stopPropagation()">
            <label class="file-action-btn" title="Upload into folder" style="cursor:pointer">${I.upload}<input type="file" multiple style="display:none" onchange="uploadFiles(${uploadId},this.files,${fo.id})"></label>
            <button class="file-action-btn" title="Add link into folder" onclick="openAddLinkModal(${uploadId},${fo.id})">${I.link || '🔗'}</button>
            ${State.user?.role === 'admin' ? `<button class="file-action-btn danger" title="Delete folder" onclick="deleteFolderFromId(${uploadId},${fo.id})">${I.trash}</button>` : ''}
          </div>
        </div>
        <div class="folder-body" id="${fBodyId}" style="display:none">
          ${inside.length ? inside.map(f => renderFileRow(f)).join('') : '<div style="padding:10px 0 4px 34px;color:var(--muted);font-size:13px">Empty folder.</div>'}
        </div>
      </div>`;
  }).join('');

  const toolbar = `
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:10px;flex-wrap:wrap">
      ${supportsFolders ? `<button class="btn" onclick="openNewFolderModal(${uploadId})">${I.folder || '📁'}New folder</button>` : ''}
      ${supportsFolders ? `<button class="btn" onclick="openAddLinkModal(${uploadId},null)">${I.link || '🔗'}Add link</button>` : ''}
      <label class="btn" style="cursor:pointer">${I.upload}Upload<input type="file" multiple style="display:none" onchange="${uploadFn}(${uploadId},this.files)"></label>
    </div>`;

  const totalItems = count + folders.length;

  return `
    <div class="cat-toggle-section">
      <button class="cat-toggle-header" onclick="toggleCatSection('${bodyId}')">
        <span style="display:flex;align-items:center;gap:8px">
          <span class="cat-toggle-chevron" id="${chevronId}">${I.arrowR}</span>
          <span style="font-size:18px;font-weight:700;color:var(--gold)">All Files</span>
          <span style="font-size:13px;color:var(--muted)">(${count})</span>
        </span>
      </button>
      <div class="cat-toggle-body" id="${bodyId}" style="display:none">
        ${toolbar}
        <label class="upload-zone" onclick="this.querySelector('input').click()">
          ${I.upload} Drag files here or click to upload
          <input type="file" multiple style="display:none" onchange="${uploadFn}(${uploadId},this.files)">
        </label>
        ${totalItems ? `
          <div class="file-search-wrap">
            ${I.search || ''}
            <input type="text" class="file-search-input" id="${searchId}" placeholder="Search files by name…" oninput="filterFileList('${scope}')" aria-label="Search files">
          </div>
          ${folderBlocks}
          <div id="${listId}" style="display:flex;flex-direction:column;gap:8px;margin-top:${folderBlocks ? '8px' : '0'}">
            ${rootFiles.map(f => renderFileRow(f)).join('')}
          </div>
          <div id="${listId}-empty" style="display:none;padding:16px 0;color:var(--muted);font-size:14px">No files match your search.</div>
        ` : '<div style="padding:16px 0;color:var(--muted);font-size:14px">No files yet.</div>'}
      </div>
    </div>
  `;
}

// Expand/collapse a single folder block.
function toggleFolder(bodyId, btn) {
  const body = document.getElementById(bodyId);
  if (!body) return;
  const shown = body.style.display !== 'none';
  body.style.display = shown ? 'none' : 'block';
  const chevron = btn.querySelector('.folder-chevron');
  if (chevron) { chevron.style.transform = shown ? 'rotate(0deg)' : 'rotate(90deg)'; chevron.style.transition = 'transform .2s ease'; }
}

// Client-side file search: filters visible file rows by name within a scope.
function filterFileList(scope) {
  const input = document.getElementById(scope + '-file-search');
  const list = document.getElementById(scope + '-file-list');
  const emptyEl = document.getElementById(scope + '-file-list-empty');
  if (!input || !list) return;
  const q = input.value.trim().toLowerCase();
  let visible = 0;
  // Root files
  Array.from(list.children).forEach(row => {
    const nameEl = row.querySelector('.file-name');
    const name = (nameEl ? nameEl.textContent : '').toLowerCase();
    const match = !q || name.includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  // Files inside folders — expand a folder automatically if it has matches.
  const body = document.getElementById(scope + '-all-files-body');
  if (body) {
    body.querySelectorAll('.folder-block').forEach(block => {
      let folderVisible = 0;
      block.querySelectorAll('.folder-body .file-row').forEach(row => {
        const nameEl = row.querySelector('.file-name');
        const name = (nameEl ? nameEl.textContent : '').toLowerCase();
        const match = !q || name.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) { folderVisible++; visible++; }
      });
      const fBody = block.querySelector('.folder-body');
      if (q && fBody) fBody.style.display = folderVisible ? 'block' : 'none';
      // When search cleared, collapse folders back to default.
      if (!q && fBody) fBody.style.display = 'none';
      block.style.display = (!q || folderVisible) ? '' : 'none';
    });
  }
  if (emptyEl) emptyEl.style.display = visible === 0 ? 'block' : 'none';
}

function toggleCatSection(bodyId) {
  const body = document.getElementById(bodyId);
  if (!body) return;
  const isShown = body.style.display !== 'none';
  body.style.display = isShown ? 'none' : 'block';
  // Update chevron rotation
  const chevronId = bodyId.replace('body', 'chevron');
  const chevron = document.getElementById(chevronId);
  if (chevron) {
    chevron.style.transform = isShown ? 'rotate(0deg)' : 'rotate(90deg)';
    chevron.style.transition = 'transform .2s ease';
  }
  // If opening tasks section for the first time, load tasks
  if (!isShown && bodyId === 'cat-all-tasks-body' && !body.dataset.loaded) {
    loadCategoryAllTasks(body);
    body.dataset.loaded = '1';
  }
}

async function loadCategoryAllTasks(container) {
  const cat = State.activeCategory;
  if (!cat) return;
  const projects = cat.projects || [];
  let allTasks = [];
  // Fetch tasks for each sub-project
  for (const p of projects) {
    try {
      const res = await API.project(p.id);
      const proj = res.project;
      (proj.groups || []).forEach(g => {
        (g.tasks || []).forEach(t => {
          allTasks.push({
            ...t,
            project_name: p.name,
            project_color: p.color || '#7e6549',
            project_id: p.id,
          });
        });
      });
    } catch(e) {}
  }
  if (!allTasks.length) {
    container.innerHTML = '<div style="padding:16px 0;color:var(--muted);font-size:14px">No tasks yet.</div>';
    return;
  }
  container.innerHTML = allTasks.map(t => {
    const assignees = (t.assignees || []).length ? t.assignees : (t.assignee_name ? [{initials: t.who, avatar_color: t.avBg, name: t.assignee_name}] : []);
    const shown = assignees.slice(0, 3).map(a => `<span class="task-avatar" title="${esc(a.name)}" style="background:${a.avatar_color}">${esc(a.initials || initialsOf(a.name))}</span>`).join('');
    const extra = assignees.length > 3 ? `<span class="task-avatar more">+${assignees.length - 3}</span>` : '';
    const assigneeHTML = (shown || extra) ? `<div class="task-avatars">${shown}${extra}</div>` : '<span class="task-avatar" style="background:#EAE0CE;color:var(--muted)">—</span>';
    return `<div class="task-row" onclick="openTaskPage(${t.id}, ${t.project_id})" style="cursor:pointer">
      <div class="task-checkbox ${t.done ? 'done' : ''}">${I.check}</div>
      <span class="task-name ${t.done ? 'done' : ''}">${esc(t.title)}</span>
      <span style="display:flex;align-items:center;gap:5px;flex:none">
        <span class="dot" style="width:8px;height:8px;border-radius:99px;background:${t.project_color || '#7e6549'}"></span>
        <span style="font-size:12px;color:var(--text-2);white-space:nowrap">${esc(t.project_name)}</span>
      </span>
      <span class="health-pill" style="font-size:11px;padding:2px 8px;color:${t.status_color};background:${t.status_bg || '#F0E8DC'};flex:none"><span class="dot" style="background:${t.status_color}"></span>${esc(t.status_label)}</span>
      <div style="display:flex;align-items:center;margin-left:auto">${assigneeHTML}</div>
    </div>`;
  }).join('');
}

function openTaskPage(taskId, projectId) {
  if (!projectId) return;
  goTaskPage(taskId, projectId);
}

function renderFileRow(f) {
  const isLink = f.storage === 'link' && f.external_url;
  // Link files: single "open external" affordance; downloads/edit/preview don't apply.
  if (isLink) {
    const url = f.external_url;
    return `<div class="file-row" style="cursor:pointer" onclick="window.open('${esc(url)}','_blank','noopener')">
      <div class="file-icon" style="background:#4A7C9B"><span>LINK</span></div>
      <div style="flex:1;min-width:0">
        <div class="file-name">${esc(f.name)} <span style="font-size:11px;color:var(--muted);font-weight:400">↗ external</span></div>
        <div class="file-meta">${esc(f.project_name || '')} · Link · ${esc(f.by || '')} · ${esc(f.when || '')}</div>
      </div>
      <div class="file-actions" onclick="event.stopPropagation()">
        <button class="file-action-btn" onclick="window.open('${esc(url)}','_blank','noopener')" title="Open link">${I.eye}</button>
        ${State.user?.role === 'admin' ? `<button class="file-action-btn danger" onclick="deleteFileFromId(${f.id})" title="Remove link">${I.trash}</button>` : ''}
      </div>
    </div>`;
  }
  const colorMap = { PDF: '#B0432B', FIG: '#6B8E5A', DOCX: '#4F5635', DOC: '#4F5635', PNG: '#C99520', JPG: '#C99520', JPEG: '#C99520', ZIP: '#9A8A78', XLSX: '#4A7C9B', CSV: '#4A7C9B' };
  const fc = colorMap[f.ext?.toUpperCase()] || '#6B5A4A';
  return `<div class="file-row" style="cursor:pointer" onclick="viewFile(${f.id})">
    <div class="file-icon" style="background:${fc}"><span>${esc(f.ext)}</span></div>
    <div style="flex:1;min-width:0">
      <div class="file-name">${esc(f.name)}</div>
      <div class="file-meta">${esc(f.project_name || '')} · ${esc(f.size)} · ${esc(f.by)} · ${esc(f.when)}</div>
    </div>
    <div class="file-actions" onclick="event.stopPropagation()">
      <button class="file-action-btn" onclick="viewFile(${f.id})" title="Open">${I.eye}</button>
      ${window.__authProvider === 'microsoft' ? `<button class="file-action-btn" onclick="editFileFromId(${f.id})" title="Edit">${I.pencil||''}</button>` : ''}
      <button class="file-action-btn" onclick="downloadFileFromId(${f.id})" title="Download">${I.download}</button>
      ${State.user?.role === 'admin' ? `<button class="file-action-btn danger" onclick="deleteFileFromId(${f.id})" title="Delete">${I.trash}</button>` : ''}
    </div>
  </div>`;
}

// ===== B4b — CREATE FOLDER =====
function openNewFolderModal(pid) {
  if (!pid) { toast('No project selected', 'error'); return; }
  Modal.open({
    title: 'New folder',
    body: `
      <div class="form-field">
        <label class="form-label">Folder name</label>
        <input class="form-input" id="new-folder-name" placeholder="e.g. Contracts" onkeydown="if(event.key==='Enter')submitNewFolder(${pid})">
      </div>`,
    footer: `
      <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" onclick="submitNewFolder(${pid})">Create folder</button>`,
    onMount: () => setTimeout(() => document.getElementById('new-folder-name')?.focus(), 100),
  });
}

async function submitNewFolder(pid) {
  const name = document.getElementById('new-folder-name')?.value.trim();
  if (!name) { toast('Please enter a folder name', 'error'); return; }
  try {
    await API.createFolder(pid, name, null);
    Modal.close();
    toast('Folder created', 'success');
    State.activeProject = null;
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function deleteFolderFromId(pid, folderId) {
  appConfirm('Delete this folder? Files inside it will be moved to the project root.', async () => {
    try {
      await API.deleteFolder(pid, folderId);
      toast('Folder deleted', 'success');
      State.activeProject = null;
      render();
    } catch(e) { toast(e.message, 'error'); }
  });
}

// ===== B4d — ADD FILE VIA LINK (e.g. SharePoint-hosted) =====
function openAddLinkModal(pid, folderId) {
  if (!pid) { toast('No project selected', 'error'); return; }
  Modal.open({
    title: 'Add file link',
    body: `
      <p style="font-size:13.5px;color:var(--muted);margin-bottom:14px">Paste a link to a file hosted elsewhere (e.g. SharePoint). It will appear alongside uploaded files.</p>
      <div class="form-field">
        <label class="form-label">Link URL</label>
        <input class="form-input" id="add-link-url" placeholder="https://…" onkeydown="if(event.key==='Enter')document.getElementById('add-link-name').focus()">
      </div>
      <div class="form-field">
        <label class="form-label">Display name (optional)</label>
        <input class="form-input" id="add-link-name" placeholder="e.g. Q3 Budget.xlsx" onkeydown="if(event.key==='Enter')submitAddLink(${pid},${folderId == null ? 'null' : folderId})">
      </div>`,
    footer: `
      <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" onclick="submitAddLink(${pid},${folderId == null ? 'null' : folderId})">Add link</button>`,
    onMount: () => setTimeout(() => document.getElementById('add-link-url')?.focus(), 100),
  });
}

async function submitAddLink(pid, folderId) {
  const url = document.getElementById('add-link-url')?.value.trim();
  let name = document.getElementById('add-link-name')?.value.trim();
  if (!url) { toast('Please enter a link URL', 'error'); return; }
  if (!/^https?:\/\//i.test(url)) { toast('Link must start with http:// or https://', 'error'); return; }
  if (!name) {
    // Derive a name from the URL's last path segment.
    try { const u = new URL(url); name = decodeURIComponent(u.pathname.split('/').filter(Boolean).pop() || u.hostname); }
    catch(e) { name = url; }
  }
  try {
    await API.addFileLink(pid, url, name, folderId || null);
    Modal.close();
    toast('Link added', 'success');
    State.activeProject = null;
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function uploadCategoryFiles(catId, files) {
  if (!files || !files.length) return;
  // Find first project in category to attach files to, or create a default
  const cat = State.activeCategory;
  const projects = cat?.projects || [];
  if (!projects.length) { toast('Create a project first before uploading files', 'error'); return; }
  // Use first project as the upload target
  const pid = projects[0].id;
  for (const f of files) {
    try { await API.uploadFile(pid, f); } catch(e) { console.error(e); }
  }
  toast('Files uploaded', 'success');
  loadCategoryFiles(catId);
}

async function downloadFileFromId(id) {
  API.downloadFile(id);
}

async function deleteFileFromId(id) {
  appConfirm('Delete this file?', async () => {
    try {
      await API.deleteFile(id);
      toast('File deleted', 'success');
      // Refresh whatever view we're in
      if (State.activeCategory) loadCategoryFiles(State.activeCategory.id);
      if (State.activeProject) { State.activeProject = null; render(); }
    } catch(e) { toast(e.message, 'error'); }
  });
}

function initialsOf(name = '') {
  return name.trim().split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

function goCategory(id) {
  State.screen = 'category';
  State.activeCategoryId = id;
  State.activeCategory = null;
  render();
  setTimeout(() => { 
    loadCategoryFiles(id); 
    updateHash(); // Update hash after render so we have the name
  }, 100);
}

function openNewProjectModal(parentId, parentName) {
  const isSub = !!parentId;
  // C3 — when the create modal is opened from an individual project page, the new
  // item is a *sub-project*; from a category page it's a *project*. This only
  // affects wording — the payload is identical (parent_id = the parent's id).
  const childWord = (isSub && State.screen === 'project') ? 'sub-project' : 'project';
  const childWordCap = childWord.charAt(0).toUpperCase() + childWord.slice(1);
  // Fetch members for the selector
  API.members().then(res => {
    const members = res.members || [];
    const memberCheckboxes = members.map(m => `
      <label class="member-pick" style="display:flex;align-items:center;gap:8px;padding:7px 0;cursor:pointer">
        <input type="checkbox" value="${m.id}" ${m.id === State.user.id ? 'checked' : ''} style="accent-color:var(--gold)">
        <div class="avatar avatar-sm" style="background:${m.avatar_color}">${m.initials}</div>
        <span style="font-size:14px">${esc(m.name)}</span>
        ${m.id === State.user.id ? '<span style="font-size:11px;color:var(--muted)">you</span>' : ''}
      </label>
    `).join('');
    
    Modal.open({
      title: isSub ? `Add ${childWord} to ${parentName}` : 'New Category',
      body: `
        <div class="form-field">
          <label class="form-label">${isSub ? childWordCap + ' name' : 'Category name'}</label>
          <input class="form-input" id="np-name" placeholder="${isSub ? 'e.g. Summer Campaign' : 'e.g. Marketing'}" onkeydown="if(event.key==='Enter')submitNewProject(${parentId || 'null'})">
        </div>
        <div class="form-field">
          <label class="form-label">Description</label>
          <textarea class="form-textarea" id="np-desc" placeholder="What is this ${isSub ? childWord : 'category'} about?"></textarea>
        </div>
        ${isSub ? `
        <div class="form-field">
          <label class="form-label">Due date (optional)</label>
          <input class="form-input" type="date" id="np-due">
        </div>
        ` : ''}
        <div class="form-field">
          <label class="form-label">Members</label>
          <div style="display:flex;flex-direction:column;gap:2px;max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:8px 12px">
            ${memberCheckboxes}
          </div>
          <p style="font-size:12px;color:var(--muted);margin-top:6px">${isSub ? 'Only members you select will see this ' + childWord + '. Members of the parent will also see it.' : 'Only members you select will see this category.'}</p>
        </div>
      `,
      footer: `
        <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
        <button class="btn-primary" onclick="submitNewProject(${parentId || 'null'})">${isSub ? 'Create ' + childWord : 'Create category'}</button>
      `,
      onMount: () => setTimeout(() => document.getElementById('np-name')?.focus(), 100),
    });
  }).catch(() => {
    // Fallback without members
    Modal.open({
      title: isSub ? `Add project to ${parentName}` : 'New Category',
      body: `
        <div class="form-field">
          <label class="form-label">${isSub ? 'Project name' : 'Category name'}</label>
          <input class="form-input" id="np-name" onkeydown="if(event.key==='Enter')submitNewProject(${parentId || 'null'})">
        </div>
        <div class="form-field">
          <label class="form-label">Description</label>
          <textarea class="form-textarea" id="np-desc"></textarea>
        </div>
      `,
      footer: `
        <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
        <button class="btn-primary" onclick="submitNewProject(${parentId || 'null'})">Create</button>
      `,
    });
  });
}

async function submitNewProject(parentId) {
  const name = document.getElementById('np-name')?.value.trim();
  const desc = document.getElementById('np-desc')?.value.trim();
  const due = document.getElementById('np-due')?.value;
  if (!name) { toast('Please enter a name', 'error'); return; }
  
  // Collect selected member IDs (for categories only)
  const memberInputs = document.querySelectorAll('.member-pick input:checked');
  const memberIds = Array.from(memberInputs).map(i => parseInt(i.value));
  
  try {
    const payload = { name, description: desc, due_date: due || null, parent_id: parentId || null };
    if (memberIds.length) payload.member_ids = memberIds;
    const res = await API.createProject(payload);
    Modal.close();
    // Invalidate every cache so the new project (with its description) is fetched fresh.
    // This also refreshes the project page's sub-projects grid (C3) when a
    // sub-project was created from inside a project.
    State.projects = null;
    State.activeCategory = null;
    State.activeProject = null;
    State.unreadCounts = null;
    // Distinguish messaging: creating inside a sub-project reads as a sub-project.
    let msg = 'Category created';
    if (parentId) {
      const onProjectPage = State.screen === 'project';
      msg = onProjectPage ? 'Sub-project created' : 'Project created';
    }
    toast(msg, 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

async function editFileFromId(id) {
  try {
    const res = await API.editFile(id);
    if (res.url) window.open(res.url, '_blank');
  } catch(e) { toast(e.message, 'error'); }
}


// ===== DELETE FUNCTIONS =====
async function deleteCategory(id) {
  appConfirm('Delete this category and all projects inside it?', async () => {
    try {
      await API.deleteProject(id);
      State.projects = null;
      State.unreadCounts = null;
      toast('Category deleted', 'success');
      render();
    } catch(e) { toast(e.message, 'error'); }
  });
}

async function deleteProject(id) {
  // C3 — if we're on a project page and deleting one of its *sub-projects*
  // (not the project itself), stay on the page and just refresh its grid.
  const deletingChild = State.screen === 'project' && State.activeProjectId && id != State.activeProjectId;
  appConfirm(deletingChild ? 'Delete this sub-project?' : 'Delete this project?', async () => {
    try {
      await API.deleteProject(id);
      State.activeCategory = null;
      State.unreadCounts = null;
      if (deletingChild) {
        State.activeProject = null; // force re-fetch so the sub-project grid updates
        toast('Sub-project deleted', 'success');
        render();
        return;
      }
      toast('Project deleted', 'success');
      // Go back to category view
      if (State.activeCategoryId) {
        goCategory(State.activeCategoryId);
      } else {
        nav('projects');
      }
    } catch(e) { toast(e.message, 'error'); }
  });
}
