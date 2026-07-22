// VGo API client
const API = {
  token: null,
  csrf: null,
  // Shared headers for CSRF (H5). Attach the token to unsafe requests.
  csrfHeaders() {
    return API.csrf ? { 'X-CSRF-Token': API.csrf } : {};
  },
  async req(path, opts = {}) {
    const url = '/api' + path;
    const method = (opts.method || 'GET').toUpperCase();
    const unsafe = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
    const res = await fetch(url, {
      ...opts,
      headers: {
        'Content-Type': 'application/json',
        ...(unsafe ? API.csrfHeaders() : {}),
        ...(opts.headers || {}),
      },
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({ error: 'Request failed' }));
    // Capture/refresh CSRF token whenever the server returns one.
    if (data && data.csrf_token) API.csrf = data.csrf_token;
    if (!res.ok) throw new Error(data.error || 'API error');
    return data;
  },
  // Auth
  register: (name, email, password) => API.req('/auth/register', { method: 'POST', body: JSON.stringify({ name, email, password }) }),
  login: (email, password) => API.req('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }),
  logout: () => API.req('/auth/logout', { method: 'POST' }),
  me: () => API.req('/auth/me'),
  // Projects
  projects: () => API.req('/projects'),
  category: (id) => API.req('/categories/' + id),
  project: (id) => API.req('/projects/' + id),
  createProject: (data) => API.req('/projects', { method: 'POST', body: JSON.stringify(data) }),
  updateProject: (id, data) => API.req('/projects/' + id, { method: 'PUT', body: JSON.stringify(data) }),
  deleteProject: (id) => API.req('/projects/' + id, { method: 'DELETE' }),
  addMember: (pid, uid) => API.req('/projects/' + pid + '/members', { method: 'POST', body: JSON.stringify({ user_id: uid }) }),
  removeMember: (pid, uid) => API.req('/projects/' + pid + '/members', { method: 'DELETE', body: JSON.stringify({ user_id: uid }) }),
  listMembers: (pid) => API.req('/projects/' + pid + '/members'),
  // Tasks
  task: (id) => API.req('/tasks/' + id),
  taskComments: (id) => API.req('/tasks/' + id + '/comments'),
  uploadTaskFile: (taskId, file) => {
    const fd = new FormData();
    fd.append('file', file);
    return API.uploadReq('/tasks/' + taskId + '/upload', fd);
  },
  today: () => API.req('/tasks/today'),
  allTasks: () => API.req('/tasks/all'),
  myTasks: () => API.req('/tasks/my-tasks'),
  meetingPoints: () => API.req('/tasks/meeting-points'),
    getAgenda: () => API.req('/tasks/meeting-agenda'),
    createAgenda: (data) => API.req('/tasks/meeting-agenda', { method: 'POST', body: JSON.stringify(data) }),
    updateAgenda: (id, data) => API.req('/tasks/meeting-agenda/' + id, { method: 'PUT', body: JSON.stringify(data) }),
    deleteAgenda: (id) => API.req('/tasks/meeting-agenda/' + id, { method: 'DELETE' }),
  createTask: (data) => API.req('/tasks', { method: 'POST', body: JSON.stringify(data) }),
  updateTask: (id, data) => API.req('/tasks/' + id, { method: 'PUT', body: JSON.stringify(data) }),
  toggleTask: (id) => API.req('/tasks/' + id + '/toggle', { method: 'POST' }),
  deleteTask: (id) => API.req('/tasks/' + id, { method: 'DELETE' }),
  addComment: (taskId, body) => API.req('/tasks/' + taskId + '/comments', { method: 'POST', body: JSON.stringify({ body }) }),
  // Messages
  channels: () => API.req('/messages/channels'),
  createChannel: (data) => API.req('/messages/create-channel', { method: 'POST', body: JSON.stringify(data) }),
  deleteChannel: (id) => API.req('/messages/channel/' + id, { method: 'DELETE' }),
  startDM: (userIds) => API.req('/messages/start-dm', { method: 'POST', body: JSON.stringify(Array.isArray(userIds) ? { user_ids: userIds } : { user_id: userIds }) }),
  channelMessages: (id) => API.req('/messages/' + id),
  sendMessage: (channelId, body) => API.req('/messages/' + channelId, { method: 'POST', body: JSON.stringify({ body }) }),
  sendMessageWithAttachment: (channelId, body, file) => {
    const fd = new FormData();
    if (body) fd.append('body', body);
    fd.append('attachment', file);
    return API.uploadReq('/messages/' + channelId, fd);
  },
  mentions: (q) => API.req('/messages/mentions?q=' + encodeURIComponent(q)),
  sendProjectChat: (projectId, body) => API.req('/projects/' + projectId + '/chat', { method: 'POST', body: JSON.stringify({ body }) }),
  unreadChatCounts: () => API.req('/projects/unread-counts'),
  markChatRead: (projectId) => API.req('/projects/' + projectId + '/chat/read', { method: 'POST' }),
  // B4a — the previous implementation used `.then(r => r.json())` without checking
  // res.ok and, crucially, without capturing the rotated CSRF token from the
  // response. That caused the classic "first upload fails, second works" glitch
  // (a rotated token was discarded, so the next unsafe request used a stale one).
  // uploadReq centralises multipart uploads: it captures the refreshed token and
  // throws on failure so callers can react.
  async uploadReq(path, formData) {
    const res = await fetch('/api' + path, {
      method: 'POST',
      body: formData,
      headers: API.csrfHeaders(),
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({ error: 'Upload failed' }));
    if (data && data.csrf_token) API.csrf = data.csrf_token;
    if (!res.ok) throw new Error(data.error || 'Upload failed');
    return data;
  },
  uploadFile: (projectId, file) => {
    const fd = new FormData();
    fd.append('file', file);
    return API.uploadReq('/projects/' + projectId + '/upload', fd);
  },
  downloadFile: (id) => window.open('/api/files/' + id + '/download', '_blank'),
  previewFile: (id) => API.req('/files/' + id + '/preview'),
  editFile: (id) => API.req('/files/' + id + '/edit'),
  deleteFile: (id) => API.req('/files/' + id, { method: 'DELETE' }),
  // Settings
  profile: () => API.req('/settings/profile'),
  updateProfile: (data) => API.req('/settings/profile', { method: 'PUT', body: JSON.stringify(data) }),
  updatePassword: (data) => API.req('/settings/password', { method: 'PUT', body: JSON.stringify(data) }),
  notifSettings: () => API.req('/settings/notifications'),
  updateNotifications: (data) => API.req('/settings/notifications', { method: 'PUT', body: JSON.stringify(data) }),
  apiKeys: () => API.req('/settings/api-keys'),
  updateApiKey: (data) => API.req('/settings/api-keys', { method: 'PUT', body: JSON.stringify(data) }),
  deleteApiKey: (provider) => API.req('/settings/api-keys', { method: 'DELETE', body: JSON.stringify({ provider }) }),
  team: () => API.req('/settings/team'),
  invite: (email, role) => API.req('/settings/invite', { method: 'POST', body: JSON.stringify({ email, role }) }),
  members: () => API.req('/settings/members'),
  crmRoleMap: () => API.req('/settings/crm-role-map'),
  updateCrmRoleMap: (data) => API.req('/settings/crm-role-map', { method: 'PUT', body: JSON.stringify(data) }),
  // SMTP
  smtp: () => API.req('/settings/smtp'),
  updateSmtp: (data) => API.req('/settings/smtp', { method: 'PUT', body: JSON.stringify(data) }),
  testSmtp: () => API.req('/settings/smtp/test', { method: 'POST' }),
  // User management
  createUser: (data) => API.req('/settings/users', { method: 'POST', body: JSON.stringify(data) }),
  deleteUser: (userId, extra) => API.req('/settings/users', { method: 'DELETE', body: JSON.stringify({ user_id: userId, ...(extra || {}) }) }),
  changeRole: (userId, role) => API.req('/settings/users/' + userId + '/role', { method: 'PATCH', body: JSON.stringify({ user_id: userId, role }) }),
  toggleUserActive: (userId) => API.req('/settings/users/' + userId + '/toggle-active', { method: 'POST', body: JSON.stringify({ user_id: userId }) }),
  moduleAccess: () => API.req('/settings/module-access'),
  updateModuleAccess: (userId, modules) => API.req('/settings/module-access', { method: 'PUT', body: JSON.stringify({ user_id: userId, modules }) }),
  crmSettings: () => API.req('/settings/crm'),
  updateCrmSettings: (data) => API.req('/settings/crm', { method: 'PUT', body: JSON.stringify(data) }),
  myCategories: () => API.req('/categories/mine'),
  // Native CRM modules
  crmDashboard: () => API.req('/crm/dashboard'),
  crmLeads: (params = {}) => API.req('/crm/leads?' + new URLSearchParams(params).toString()),
  createCrmLead: (data) => API.req('/crm/leads', { method: 'POST', body: JSON.stringify(data) }),
  crmLeadOptions: () => API.req('/crm/lead-options'),
  crmInteractions: () => API.req('/crm/interactions'),
  createCrmInteraction: (data) => API.req('/crm/interactions', { method: 'POST', body: JSON.stringify(data) }),
  // AI
  providers: () => API.req('/ai/providers'),
  ask: (prompt) => API.req('/ai/ask', { method: 'POST', body: JSON.stringify({ prompt }) }),
  planMyDay: () => API.req('/ai/plan-my-day', { method: 'POST' }),
  getDayPlan: () => API.req('/ai/day-plan'),
  // Admin
  reset: () => API.req('/admin/reset', { method: 'POST' }),
  // Notifications
  notifications: () => API.req('/notifications'),
  unreadCount: () => API.req('/notifications/unread-count'),
  markRead: (id) => API.req('/notifications/' + id + '/read', { method: 'POST' }),
  markAllRead: () => API.req('/notifications/read-all', { method: 'POST' }),
  subscribePush: (data) => API.req('/notifications/subscribe', { method: 'POST', body: JSON.stringify(data) }),
  // Folders, links, card order, comments feed, real-time (Feature batch B)
  createFolder: (pid, name, parentFolderId) => API.req('/projects/' + pid + '/folders', { method: 'POST', body: JSON.stringify({ name, parent_folder_id: parentFolderId || null }) }),
  deleteFolder: (pid, folderId) => API.req('/projects/' + pid + '/folders/' + folderId, { method: 'DELETE' }),
  addFileLink: (pid, url, name, folderId) => API.req('/projects/' + pid + '/file-link', { method: 'POST', body: JSON.stringify({ url, name, folder_id: folderId || null }) }),
  uploadFileToFolder: (projectId, file, folderId) => {
    const fd = new FormData();
    fd.append('file', file);
    if (folderId) fd.append('folder_id', folderId);
    return API.uploadReq('/projects/' + projectId + '/upload', fd);
  },
  stateVersion: () => API.req('/state-version'),
  cardOrder: () => API.req('/card-order'),
  saveCardOrder: (scopeId, order) => API.req('/card-order', { method: 'POST', body: JSON.stringify({ scope_id: scopeId, order }) }),
  commentsFeed: () => API.req('/comments-feed'),
  markCommentsFeedRead: () => API.req('/comments-feed/read', { method: 'POST' }),
};
