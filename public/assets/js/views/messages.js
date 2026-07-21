// VGo — Messages view with create channel + start DM

// C2 — collapsible conversation sections. Closed by default so lower categories
// (like Comments under Quick access) are no longer pushed off-screen. The unread
// count stays visible on the collapsed header so users know where to look.
let _msgSectionOpen = { channels: false, dms: false, quick: false };

// Build one collapsible section. The header is always visible with its label and
// an unread pill (when there are unread messages); the body only renders when the
// section is expanded.
function convSection(id, label, unreadCount, bodyHTML) {
  const open = !!_msgSectionOpen[id];
  const badge = unreadCount > 0
    ? `<span class="conv-sec-badge">${unreadCount}</span>`
    : '';
  return `
    <div class="conv-section ${open ? 'open' : ''}" data-section="${id}">
      <button type="button" class="conv-sec-header" onclick="toggleMsgSection('${id}')" aria-expanded="${open}">
        <span class="conv-sec-chevron">${open ? '▾' : '▸'}</span>
        <span class="conv-sec-label">${esc(label)}</span>
        ${badge}
      </button>
      <div class="conv-sec-body" style="${open ? '' : 'display:none'}">
        ${bodyHTML}
      </div>
    </div>`;
}

// Flip a section open/closed and re-render.
function toggleMsgSection(id) {
  _msgSectionOpen[id] = !_msgSectionOpen[id];
  render();
}

async function renderMessages() {
  let channels = State.channels;
  if (!channels) {
    try { const res = await API.channels(); channels = res; State.channels = res; } catch(e) { channels = { channels: [], dms: [], members: [] }; }
  }
  
  // Load mentions (type=mention + chat notifications containing @)
  if (!State.mentions) {
    try {
      const res = await API.req('/notifications');
      const all = res.notifications || [];
      State.mentions = all.filter(n => n.type === 'mention' || (n.body && n.body.includes('@')));
    } catch(e) { State.mentions = []; }
  }
  const mentionCount = (State.mentions || []).length;

  // Comments feed (B7c) — all comments on projects the user is part of.
  if (!State.commentsFeed) {
    try {
      const cf = await API.commentsFeed();
      State.commentsFeed = cf.comments || [];
      State.commentsUnread = cf.unread || 0;
    } catch(e) { State.commentsFeed = []; State.commentsUnread = 0; }
  }
  const commentsCount = State.commentsUnread || 0;

  const teamChans = (channels.channels || []).map(c =>
    `<div class="conv-row">
      <button class="conv-btn ${State.activeChannel == c.id ? 'active' : ''}" onclick="openChannel(${c.id})">
        <span style="font-size:16px;color:var(--muted);font-weight:400;flex:none">#</span>
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.name)}</span>
        ${c.count ? `<span style="font-size:11px;color:var(--gold);background:var(--gold-bg);border-radius:99px;padding:1px 7px">${c.count}</span>` : ''}
      </button>
      <button class="conv-delete" title="Delete channel" aria-label="Delete channel" onclick="event.stopPropagation();deleteChannelFromList(${c.id},'${esc(c.name).replace(/'/g,"\\'")}')">${I.trash}</button>
    </div>`
  ).join('');
  const dms = (channels.dms || []).map(c =>
    `<button class="conv-btn ${State.activeChannel == c.id ? 'active' : ''}" onclick="openChannel(${c.id})">
      <span class="avatar avatar-sm" style="background:${c.avBg || '#9A8A78'}">${esc(c.initials || '??')}</span>
      <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.name)}</span>
      ${c.count ? `<span style="font-size:11px;color:var(--gold);background:var(--gold-bg);border-radius:99px;padding:1px 7px">${c.count}</span>` : ''}
    </button>`
  ).join('');

  // C2 — per-section unread totals so the collapsed toggle headers can still
  // surface where unread messages live.
  const channelsUnread = (channels.channels || []).reduce((s, c) => s + (c.count || 0), 0);
  const dmsUnread = (channels.dms || []).reduce((s, c) => s + (c.count || 0), 0);
  const quickUnread = (mentionCount || 0) + (commentsCount || 0);

  // C2 — auto-expand the section that contains whatever is currently active so
  // the open conversation/view is never hidden inside a collapsed toggle.
  if (State.viewMentions || State.viewComments) {
    _msgSectionOpen.quick = true;
  } else if (State.activeChannel) {
    const inChannels = (channels.channels || []).some(c => c.id == State.activeChannel);
    const inDms = (channels.dms || []).some(c => c.id == State.activeChannel);
    if (inChannels) _msgSectionOpen.channels = true;
    if (inDms) _msgSectionOpen.dms = true;
  }

  let convHTML = '<div style="padding:22px;color:var(--muted)">Select a conversation</div>';
  let convTitle = State.channelName || 'Select a channel';
  let convHeaderExtra = '';
  let showComposer = State.activeChannel && !State.viewMentions && !State.viewComments;

  if (State.viewComments) {
    convTitle = 'Comments';
    const total = (State.commentsFeed || []).length;
    convHeaderExtra = '<span style="font-size:12px;color:var(--muted);font-weight:400">' + total + ' comment' + (total === 1 ? '' : 's') + '</span>';
    if (!State.commentsFeed || !State.commentsFeed.length) {
      convHTML = '<div style="padding:40px;text-align:center;color:var(--muted)"><div style="font-size:48px;margin-bottom:12px">💬</div><div style="font-size:15px;font-weight:600;color:var(--text)">No comments yet</div><div style="font-size:13px;margin-top:4px">Comments posted on projects you belong to will appear here.</div></div>';
    } else {
      convHTML = '<div style="padding:16px 22px;display:flex;flex-direction:column;gap:12px">' + State.commentsFeed.map(c => `
        <div style="border:1px solid ${c.unread ? 'var(--gold)' : 'var(--border)'};border-radius:14px;padding:16px;background:var(--surface);transition:border-color .15s;cursor:pointer" onmouseover="this.style.borderColor='var(--gold)'" onmouseout="this.style.borderColor='${c.unread ? 'var(--gold)' : 'var(--border)'}'" onclick="nav('project');goProject(${c.project_id})">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
            <div class="avatar avatar-md" style="background:${c.bg || '#9A8A78'};font-size:13px;flex:none">${esc(c.initials || '??')}</div>
            <div style="flex:1;min-width:0">
              <div style="font-size:14px;font-weight:700">${esc(c.who)}${c.me ? ' <span style=\"font-weight:400;color:var(--muted);font-size:12px\">(you)</span>' : ''}</div>
              <div style="font-size:12px;color:var(--muted)">${esc(c.time_ago || '')} · in ${esc(c.project_name || 'a project')}</div>
            </div>
            ${c.unread ? '<span style="font-size:10px;font-weight:700;color:var(--gold);background:var(--gold-bg);border-radius:99px;padding:2px 8px;flex:none">NEW</span>' : ''}
          </div>
          <div style="font-size:13.5px;line-height:1.5;color:var(--text);background:var(--gold-bg);border-radius:10px;padding:12px 14px;border-left:3px solid var(--gold)">${linkify(esc(c.text || ''))}</div>
          <div style="margin-top:10px;font-size:12px;color:var(--gold);font-weight:600">View project →</div>
        </div>
      `).join('') + '</div>';
    }
  } else if (State.viewMentions) {
    convTitle = 'Mentions';
    convHeaderExtra = '<span style="font-size:12px;color:var(--muted);font-weight:400">' + (State.mentions?.length || 0) + ' mention' + ((State.mentions?.length || 0) === 1 ? '' : 's') + '</span>';
    if (!State.mentions || !State.mentions.length) {
      convHTML = '<div style="padding:40px;text-align:center;color:var(--muted)"><div style="font-size:48px;margin-bottom:12px">@</div><div style="font-size:15px;font-weight:600;color:var(--text)">No mentions yet</div><div style="font-size:13px;margin-top:4px">When someone mentions you with @, it will appear here.</div></div>';
    } else {
      convHTML = '<div style="padding:16px 22px;display:flex;flex-direction:column;gap:12px">' + State.mentions.map(n => {
        // Parse who and context from title
        // Title formats: "X mentioned you in Y" or "X mentioned you" or "X posted in Y"
        let who = 'Someone';
        let context = '';
        const m1 = n.title.match(/^(.+?) mentioned you(?: in (.+))?$/);
        const m2 = n.title.match(/^(.+?) posted in (.+)$/);
        if (m1) { who = m1[1]; context = m1[2] || ''; }
        else if (m2) { who = m2[1]; context = m2[2] || ''; }
        else { who = n.title; }
        
        const linkType = n.link_type || 'project';
        const linkId = n.link_id || n.project_id;
        const goTo = linkType === 'task' ? "nav('mytasks')" : "nav('project');goProject(" + linkId + ")";
        const contextLabel = context ? 'in ' + esc(context) : (linkType === 'task' ? 'in a task' : '');
        return `
          <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:var(--surface);transition:border-color .15s;cursor:pointer" onmouseover="this.style.borderColor='var(--gold)'" onmouseout="this.style.borderColor='var(--border)'" onclick="${goTo}">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
              <div class="avatar avatar-md" style="background:#C99520;font-size:14px;flex:none">@</div>
              <div style="flex:1;min-width:0">
                <div style="font-size:14px;font-weight:700">${esc(who)} mentioned you</div>
                <div style="font-size:12px;color:var(--muted)">${esc(n.time_ago || '')}${contextLabel ? ' · ' + contextLabel : ''}</div>
              </div>
            </div>
            <div style="font-size:13.5px;line-height:1.5;color:var(--text);background:var(--gold-bg);border-radius:10px;padding:12px 14px;border-left:3px solid var(--gold)">${linkify(esc(n.body || ''))}</div>
            <div style="margin-top:10px;font-size:12px;color:var(--gold);font-weight:600">View ${linkType === 'task' ? 'task' : 'project'} →</div>
          </div>
        `;
      }).join('') + '</div>';
    }
  } else if (State.activeChannel && State.channelMessages) {
    convHTML = State.channelMessages.map(m => `
      <div class="chat-msg ${m.me ? 'me' : ''}">
        <div class="avatar" style="width:32px;height:32px;font-size:12px;background:${m.bg}">${m.initials}</div>
        <div style="min-width:0">
          <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:4px"><span style="font-size:13px;font-weight:700">${esc(m.who)}</span><span style="font-size:11px;color:var(--muted)">${esc(m.time)}</span></div>
          <div class="msg-bubble">${linkify(m.text)}</div>
        </div>
      </div>
    `).join('');
  }

  return `
    <div class="fade-in">
      <div class="section-label">Messages</div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
        <h1 class="page-title-sm">Team chat</h1>
        <div style="display:flex;gap:8px">
          <button class="btn mobile-conv-toggle" onclick="toggleMobileConvList()" style="display:none">${I.grid}Channels</button>
          <button class="btn" onclick="openStartDMModal()">${I.user}<span class="mobile-hide">Send Message</span></button>
          <button class="btn-primary" onclick="openCreateChannelModal()">${I.plus}<span class="mobile-hide">New channel</span></button>
        </div>
      </div>
      <div class="msg-layout">
        <div class="conv-list">
          ${convSection('channels', 'Channels', channelsUnread, teamChans || '<div style="padding:0 6px;color:var(--muted);font-size:13px">No channels</div>')}
          ${convSection('dms', 'Direct messages', dmsUnread, dms || '<div style="padding:0 6px;color:var(--muted);font-size:13px">No DMs yet</div>')}
          ${convSection('quick', 'Quick access', quickUnread, `
            <button class="conv-btn ${State.viewMentions ? 'active' : ''}" onclick="openMentions()">
              <span class="avatar avatar-sm" style="background:#C99520;font-size:13px">@</span>
              <span style="flex:1;text-align:left">Mentions</span>
              ${mentionCount ? `<span style="font-size:11px;color:var(--gold);background:var(--gold-bg);border-radius:99px;padding:1px 7px">${mentionCount}</span>` : ''}
            </button>
            <button class="conv-btn ${State.viewComments ? 'active' : ''}" onclick="openComments()">
              <span class="avatar avatar-sm" style="background:#6B8E5A;font-size:13px">💬</span>
              <span style="flex:1;text-align:left">Comments</span>
              ${commentsCount ? `<span style="font-size:11px;color:var(--gold);background:var(--gold-bg);border-radius:99px;padding:1px 7px">${commentsCount}</span>` : ''}
            </button>`)}
        </div>
        <div class="chat-col">
          <div style="flex:none;display:flex;align-items:center;gap:11px;padding:16px 22px;border-bottom:1px solid var(--border)">
            <div style="line-height:1.25;flex:1"><div style="font-size:15px;font-weight:700" id="conv-title">${esc(convTitle)}</div></div>
            ${convHeaderExtra}
          </div>
          <div class="chat-messages" id="conv-messages">${convHTML}</div>
          ${showComposer ? `<div class="chat-input-row">
            <div style="flex:1;position:relative">
              <div class="msg-composer" id="msg-composer" contenteditable="true" placeholder="Write a message… or @ to mention…" onkeydown="onComposerKey(event)" oninput="onComposerInput()"></div>
              <div class="mention-dropdown" id="mention-dropdown" style="display:none"></div>
              <div id="chat-attachment-preview" style="display:none;margin-top:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);font-size:13px;display:flex;align-items:center;gap:8px">
                <span id="chat-attachment-name" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                <button class="btn" style="padding:2px 8px;font-size:12px" onclick="clearChatAttachment()">✕</button>
              </div>
            </div>
            <input type="file" id="chat-attachment-input" style="display:none" onchange="onChatAttachmentSelect(this.files)">
            <button class="btn" style="padding:8px 10px;flex:none" onclick="document.getElementById('chat-attachment-input').click()" title="Attach file">${I.upload || '📎'}</button>
            <button class="chat-send" onclick="sendChannelMsg()">${I.send}</button>
          </div>` : ''}
        </div>
      </div>
    </div>
  `;
}

function toggleMobileConvList() {
  const list = document.querySelector('.conv-list');
  if (list) list.classList.toggle('conv-list-mobile-open');
}

function openMentions() {
  State.viewMentions = !State.viewMentions;
  if (State.viewMentions) {
    State.viewComments = false;
    State.activeChannel = null;
    State.channelName = null;
  }
  render();
}

// B7c — Comments feed view. Opening it marks the feed as read (clears unread).
async function openComments() {
  State.viewComments = !State.viewComments;
  if (State.viewComments) {
    State.viewMentions = false;
    State.activeChannel = null;
    State.channelName = null;
    render();
    // Mark read after render so the "NEW" badges are seen once, then cleared.
    try {
      await API.markCommentsFeedRead();
      State.commentsUnread = 0;
      if (State.commentsFeed) State.commentsFeed = State.commentsFeed.map(c => ({ ...c, unread: false }));
      // Refresh the Messages nav badge total.
      if (typeof loadMsgUnread === 'function') loadMsgUnread();
      updateMsgBadge && updateMsgBadge();
    } catch(e) {}
  } else {
    render();
  }
}

async function openChannel(id) {
  State.viewMentions = false;
  State.viewComments = false;
  State.activeChannel = id;
  try {
    const res = await API.channelMessages(id);
    State.channelMessages = res.messages;
    State.channelName = res.channel.name;
    render();
    const el = document.getElementById('conv-messages');
    if (el) el.scrollTop = el.scrollHeight;
  } catch(e) { toast(e.message, 'error'); }
}

// Delete a channel (with all its messages). Uses the app-native confirm dialog.
function deleteChannelFromList(id, name) {
  appConfirm(`Delete the channel "${name}"? All of its messages will be permanently removed. This cannot be undone.`, async () => {
    try {
      await API.deleteChannel(id);
      // Clear any state pointing at the deleted channel + refresh the list.
      if (State.activeChannel == id) {
        State.activeChannel = null;
        State.channelName = null;
        State.channelMessages = null;
      }
      State.channels = null;
      toast('Channel deleted', 'success');
      if (typeof loadMsgUnread === 'function') loadMsgUnread();
      render();
    } catch(e) { toast(e.message, 'error'); }
  });
}

let _chatAttachment = null;

function onChatAttachmentSelect(files) {
  if (!files || !files.length) return;
  _chatAttachment = files[0];
  const preview = document.getElementById('chat-attachment-preview');
  const name = document.getElementById('chat-attachment-name');
  if (preview && name) {
    name.textContent = _chatAttachment.name;
    preview.style.display = 'flex';
  }
}

function clearChatAttachment() {
  _chatAttachment = null;
  const preview = document.getElementById('chat-attachment-preview');
  if (preview) preview.style.display = 'none';
  const input = document.getElementById('chat-attachment-input');
  if (input) input.value = '';
}

async function sendChannelMsg() {
  const composer = document.getElementById('msg-composer');
  const text = composer.innerText.trim();
  if ((!text && !_chatAttachment) || !State.activeChannel) return;
  composer.innerHTML = '';
  try {
    let res;
    if (_chatAttachment) {
      res = await API.sendMessageWithAttachment(State.activeChannel, text, _chatAttachment);
      clearChatAttachment();
    } else {
      res = await API.sendMessage(State.activeChannel, text);
    }
    const m = res.message;
    const el = document.getElementById('conv-messages');
    if (el && m) {
      let attachHTML = '';
      if (m.attachment) {
        const a = m.attachment;
        const fc = a.ext ? a.ext.match(/^(jpg|jpeg|png|gif|webp)$/i) ? '#6B8E5A' : a.ext.match(/^(pdf)$/i) ? '#B0432B' : '#9A8A78' : '#9A8A78';
        attachHTML = `<div style="margin-top:8px;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);font-size:13px;cursor:pointer" onclick="window.open('/api/msg-attachments/${a.id}/download','_blank')"><span style="font-weight:700;background:${fc};color:#FFF;padding:2px 6px;border-radius:4px;font-size:10px">${esc(a.ext || 'FILE').toUpperCase()}</span><span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(a.original_name)}</span</span>`;
      }
      el.innerHTML += `<div class="chat-msg me"><div class="avatar" style="width:32px;height:32px;font-size:12px;background:${m.bg}">${m.initials}</div><div><div style="display:flex;align-items:baseline;gap:8px;margin-bottom:4px"><span style="font-size:13px;font-weight:700">${esc(m.who)}</span><span style="font-size:11px;color:var(--muted)">now</span></div><div class="msg-bubble">${linkify(m.text || '')}${attachHTML}</div></div></div>`;
      el.scrollTop = el.scrollHeight;
    }
  } catch(e) { toast(e.message, 'error'); }
}

// ===== @MENTIONS =====
let mentionSearch = '';
let mentionTimer = null;
let mentionActiveIndex = -1;
let mentionUsers = [];

function onComposerKey(e) {
  const dropdown = document.getElementById('mention-dropdown');
  if (dropdown.style.display !== 'none' && dropdown.children.length) {
    if (e.key === 'ArrowDown') { e.preventDefault(); selectMention(1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); selectMention(-1); return; }
    if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); confirmMention(); return; }
    if (e.key === 'Escape') { hideMentionDropdown(); return; }
  }
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendChannelMsg();
  }
}

function onComposerInput() {
  const composer = document.getElementById('msg-composer');
  if (!composer) return;
  const text = composer.innerText;
  // Find @ mention query
  const match = text.match(/@(\w*)$/);
  if (match) {
    mentionSearch = match[1];
    clearTimeout(mentionTimer);
    mentionTimer = setTimeout(() => fetchMentions(mentionSearch), 150);
  } else {
    hideMentionDropdown();
  }
}

async function fetchMentions(q) {
  try {
    const res = await API.mentions(q);
    mentionUsers = res.users || [];
    renderMentionDropdown(mentionUsers);
  } catch(e) {}
}

function renderMentionDropdown(users) {
  const dropdown = document.getElementById('mention-dropdown');
  if (!users.length) { dropdown.style.display = 'none'; return; }
  mentionActiveIndex = -1;
  dropdown.innerHTML = users.map((u, i) => `
    <div class="mention-item" id="mention-${i}" onclick="insertMention(${i})" onmouseover="highlightMention(${i})">
      <div class="avatar avatar-sm" style="background:${u.color}">${u.initials}</div>
      <span>${esc(u.name)}</span>
    </div>
  `).join('');
  dropdown.style.display = 'block';
}

function selectMention(dir) {
  if (!mentionUsers.length) return;
  mentionActiveIndex = Math.max(-1, Math.min(mentionUsers.length - 1, mentionActiveIndex + dir));
  document.querySelectorAll('.mention-item').forEach((el, i) => {
    el.style.background = i === mentionActiveIndex ? 'var(--gold-bg)' : '';
  });
}

function confirmMention() {
  if (mentionActiveIndex >= 0) insertMention(mentionActiveIndex);
  else if (mentionUsers.length) insertMention(0);
}

function insertMention(index) {
  const composer = document.getElementById('msg-composer');
  const text = composer.innerText;
  // Replace @query with @Name 
  const user = mentionUsers[index];
  if (!user) return;
  const newText = text.replace(/@\w*$/, '@' + user.name + ' ');
  composer.innerText = newText;
  hideMentionDropdown();
  composer.focus();
}

function highlightMention(index) {
  mentionActiveIndex = index;
  document.querySelectorAll('.mention-item').forEach((el, i) => {
    el.style.background = i === index ? 'var(--gold-bg)' : '';
  });
}

function hideMentionDropdown() {
  const dropdown = document.getElementById('mention-dropdown');
  if (dropdown) { dropdown.style.display = 'none'; }
  mentionSearch = '';
  mentionActiveIndex = -1;
  mentionUsers = [];
}

// ===== CREATE CHANNEL MODAL =====
function openCreateChannelModal() {
  const members = State.channels?.members || [];
  const memberCheckboxes = members.map(m => `
    <label style="display:flex;align-items:center;gap:10px;padding:8px 0;cursor:pointer">
      <input type="checkbox" value="${m.id}" class="chan-member-cb" style="width:18px;height:18px;accent-color:var(--gold)">
      <span class="avatar avatar-sm" style="background:${m.avatar_color}">${m.initials}</span>
      <span style="font-size:14px">${esc(m.name)}</span>
    </label>
  `).join('');

  Modal.open({
    title: 'New Channel',
    body: `
      <div class="form-field">
        <label class="form-label">Channel name</label>
        <input class="form-input" id="ch-name" placeholder="e.g. q3-planning" onkeydown="if(event.key==='Enter')submitCreateChannel()">
      </div>
      <div class="form-field">
        <label class="form-label">Description (optional)</label>
        <input class="form-input" id="ch-desc" placeholder="What's this channel about?">
      </div>
      <div class="form-field">
        <label class="form-label">Add people</label>
        <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:8px 14px">
          ${memberCheckboxes || '<div style="color:var(--muted);padding:8px 0">No team members available</div>'}
        </div>
      </div>
    `,
    footer: `
      <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" onclick="submitCreateChannel()">Create channel</button>
    `,
    onMount: () => setTimeout(() => document.getElementById('ch-name')?.focus(), 100),
  });
}

async function submitCreateChannel() {
  const name = document.getElementById('ch-name')?.value.trim();
  if (!name) { toast('Please enter a channel name', 'error'); return; }
  const desc = document.getElementById('ch-desc')?.value.trim();
  const members = [...document.querySelectorAll('.chan-member-cb:checked')].map(cb => parseInt(cb.value));
  try {
    await API.createChannel({ name, description: desc, members });
    Modal.close();
    State.channels = null;
    toast('Channel created', 'success');
    render();
  } catch(e) { toast(e.message, 'error'); }
}

// ===== START DM MODAL (multi-user select) =====
function openStartDMModal() {
  const members = State.channels?.members || [];
  const memberCheckboxes = members.map(m => `
    <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color .15s;margin-bottom:6px" onmouseover="this.style.borderColor='var(--gold)'" onmouseout="this.style.borderColor='var(--border)'">
      <input type="checkbox" value="${m.id}" class="dm-member-cb" style="width:18px;height:18px;accent-color:var(--gold)">
      <span class="avatar avatar-md" style="background:${m.avatar_color}">${m.initials}</span>
      <div style="flex:1"><div style="font-size:14.5px;font-weight:700;color:var(--text)">${esc(m.name)}</div><div style="font-size:12px;color:var(--muted)">Send a direct message</div></div>
    </label>
  `).join('');

  Modal.open({
    title: 'Start Direct Message',
    body: `
      <p style="font-size:14px;color:var(--muted);margin-bottom:16px">Select one or more team members to start a conversation.</p>
      <div style="max-height:320px;overflow-y:auto">${memberCheckboxes || '<div style="color:var(--muted);padding:20px 0;text-align:center">No team members available</div>'}</div>
    `,
    footer: `
      <button class="btn-secondary" onclick="Modal.close()">Cancel</button>
      <button class="btn-primary" onclick="submitStartDM()">Start conversation</button>
    `,
  });
}

async function submitStartDM() {
  const selected = [...document.querySelectorAll('.dm-member-cb:checked')].map(cb => parseInt(cb.value));
  if (!selected.length) { toast('Please select at least one person', 'error'); return; }
  try {
    const res = await API.startDM(selected);
    Modal.close();
    State.channels = null;
    toast(selected.length > 1 ? 'Group DM created' : 'DM started', 'success');
    openChannel(res.channel_id);
  } catch(e) { toast(e.message, 'error'); }
}

async function startDM(userId) {
  try {
    const res = await API.startDM(userId);
    Modal.close();
    State.channels = null;
    toast('DM started', 'success');
    openChannel(res.channel_id);
  } catch(e) { toast(e.message, 'error'); }
}