// VGo — Messages view with create channel + start DM

// C2 — collapsible conversation sections. Closed by default so lower categories
// (like Comments under Quick access) are no longer pushed off-screen. The unread
// count stays visible on the collapsed header so users know where to look.
let _msgSectionOpen = { channels: true, dms: true, quick: false };
// Sections holding unread messages are force-opened on each render (below) so a
// count on a collapsed header is never the only clue that something is waiting.

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
  // A badge means "new since you looked", not "how many exist" — so count only
  // the unread ones. app.js's nav badge already did this; this one didn't, so
  // the same data showed two different numbers.
  const mentionCount = (State.mentions || []).filter(n => !n.is_read).length;

  // Comments feed (B7c) — all comments on projects the user is part of.
  if (!State.commentsFeed) {
    try {
      const cf = await API.commentsFeed();
      State.commentsFeed = cf.comments || [];
      State.commentsUnread = cf.unread || 0;
    } catch(e) { State.commentsFeed = []; State.commentsUnread = 0; }
    resetCommentReplyEchoes();
  }
  const commentsCount = State.commentsUnread || 0;

  const teamChans = (channels.channels || []).map(c =>
    `<div class="conv-row">
      <button class="conv-btn ${State.activeChannel == c.id ? 'active' : ''}" onclick="openChannel(${c.id})">
        <span style="font-size:16px;color:var(--muted);font-weight:400;flex:none">#</span>
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.name)}</span>
        ${c.count ? `<span style="font-size:11px;color:var(--gold);background:var(--gold-bg);border-radius:99px;padding:1px 7px">${c.count}</span>` : ''}
      </button>
      <button class="conv-delete" title="Delete channel" aria-label="Delete channel" onclick="event.stopPropagation();deleteChannelFromList(${c.id},'${escJs(c.name)}')">${I.trash}</button>
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

  // Any section with unread opens itself, so the red nav badge always has a
  // visible destination.
  if (channelsUnread > 0) _msgSectionOpen.channels = true;
  if (dmsUnread > 0) _msgSectionOpen.dms = true;
  if (quickUnread > 0) _msgSectionOpen.quick = true;

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
      convHTML = '<div style="padding:16px 22px;display:flex;flex-direction:column;gap:12px">'
        + State.commentsFeed.map(commentCardHTML).join('') + '</div>';
    }
  } else if (State.viewMentions) {
    convTitle = 'Mentions';
    convHeaderExtra = '<span style="font-size:12px;color:var(--muted);font-weight:400">' + (State.mentions?.length || 0) + ' mention' + ((State.mentions?.length || 0) === 1 ? '' : 's') + '</span>';
    if (!State.mentions || !State.mentions.length) {
      convHTML = '<div style="padding:40px;text-align:center;color:var(--muted)"><div style="font-size:48px;margin-bottom:12px">@</div><div style="font-size:15px;font-weight:600;color:var(--text)">No mentions yet</div><div style="font-size:13px;margin-top:4px">When someone mentions you with @, it will appear here.</div></div>';
    } else {
      convHTML = '<div style="padding:16px 22px;display:flex;flex-direction:column;gap:12px">' + State.mentions.map((n, idx) => {
        // Parse who and context from title
        // Title formats: "X mentioned you in Y" or "X mentioned you" or "X posted in Y"
        let who = 'Someone';
        let context = '';
        const m1 = n.title.match(/^(.+?) mentioned you(?: in (.+))?$/);
        const m2 = n.title.match(/^(.+?) posted in (.+)$/);
        if (m1) { who = m1[1]; context = m1[2] || ''; }
        else if (m2) { who = m2[1]; context = m2[2] || ''; }
        else { who = n.title; }
        
        // Route through the same server-computed target the bell uses. This card
        // used to send every task mention to nav('mytasks') — the list, not the
        // task — and could call goProject(0) when link_id was missing.
        const linkType = n.link_type || 'project';
        const contextLabel = context ? 'in ' + esc(context)
          : (linkType === 'task' ? 'in a task' : linkType === 'channel' ? 'in a channel' : '');
        const goLabel = linkType === 'task' ? 'View task'
          : linkType === 'channel' ? 'Open the message'
          : 'View project';
        return `
          <div style="border:1px solid var(--border);border-radius:14px;padding:16px;background:var(--surface);transition:border-color .15s;cursor:pointer" onmouseover="this.style.borderColor='var(--gold)'" onmouseout="this.style.borderColor='var(--border)'" onclick="openMentionCard(${idx})">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
              <div class="avatar avatar-md" style="background:#C99520;font-size:14px;flex:none">@</div>
              <div style="flex:1;min-width:0">
                <div style="font-size:14px;font-weight:700">${esc(who)} mentioned you</div>
                <div style="font-size:12px;color:var(--muted)">${esc(n.time_ago || '')}${contextLabel ? ' · ' + contextLabel : ''}</div>
              </div>
            </div>
            <div style="font-size:13.5px;line-height:1.5;color:var(--text);background:var(--gold-bg);border-radius:10px;padding:12px 14px;border-left:3px solid var(--gold)">${linkify(esc(n.body || ''))}</div>
            <div style="margin-top:10px;font-size:12px;color:var(--gold);font-weight:600">${goLabel} →</div>
          </div>
        `;
      }).join('') + '</div>';
    }
  } else if (State.activeChannel && State.channelMessages) {
    // Shared renderer (app.js) — gives every message reply + delete actions, the
    // quoted-parent block, and the attachment chip, which the old inline markup
    // here was missing on reload.
    convHTML = State.channelMessages.map(m => chatMsgHTML('channel', m)).join('');
  }

  // On mobile the conversation list is off-canvas, so landing here with nothing
  // selected shows an empty pane and a "Select a conversation" message with no
  // visible way forward. When there is no selection, open the list instead.
  const nothingSelected = !State.activeChannel && !State.viewMentions && !State.viewComments;

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
        <div class="conv-list${nothingSelected ? ' conv-list-mobile-open' : ''}">
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
          ${showComposer ? `<div class="chat-reply-bar" id="chat-reply-bar-channel" style="display:none"></div>
          <div class="chat-input-row">
            <div style="flex:1;position:relative">
              <textarea class="msg-composer" id="msg-composer" rows="1" placeholder="Write a message… @ to mention · Shift+Enter for a new line or bullet" onkeydown="onComposerKey(event)" oninput="onComposerInput()"></textarea>
              <div class="mention-dropdown" id="mention-dropdown" style="display:none"></div>
              <div id="chat-attachment-preview" class="chat-attachment-preview">
                <span class="chat-attachment-chip">${I.upload || '📎'}</span>
                <span id="chat-attachment-name"></span>
                <button class="chat-attachment-clear" onclick="clearChatAttachment()" title="Remove attachment" aria-label="Remove attachment">✕</button>
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

// ===== COMMENTS FEED — reply in place =====
//
// A comment in this feed is a message in some project's chat thread. Replying
// used to mean navigating into that project, which made answering five comments
// five round trips. The composer below posts straight back into the same thread
// with parent_id set, so the reply lands in the project conversation exactly as
// if it had been typed there — you just never had to leave this page.
//
// Both maps survive re-renders (the 12s realtime poll re-renders this view), so
// an open box and a half-typed reply are not thrown away underneath the user.
let _cmtReplyOpen = new Set();
let _cmtDrafts = {};

/** Replies the user has just sent, keyed by the comment they answered. */
let _cmtSentReplies = {};

const CMT_REPLY_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>';

// Inline handlers run in global scope, so go through a function rather than
// assigning into the module-level map from an attribute.
function setCommentDraft(id, value) { _cmtDrafts[id] = value; }

// The server's reply_count already includes anything we echoed locally once the
// feed has been refetched, so the local echo must be dropped at the same moment
// or every reply gets counted twice.
function resetCommentReplyEchoes() { _cmtSentReplies = {}; }

function commentCardHTML(c) {
  const quote = typeof chatQuoteHTML === 'function' ? chatQuoteHTML(c.reply_to) : '';
  const open = _cmtReplyOpen.has(c.id);
  const draft = _cmtDrafts[c.id] || '';
  const sent = _cmtSentReplies[c.id] || [];
  const replyCount = (Number(c.reply_count) || 0) + sent.length;

  return `
    <div class="cmt-card ${c.unread ? 'unread' : ''}" id="cmt-card-${c.id}" onclick="commentCardClick(event, ${c.project_id})">
      <div class="cmt-head">
        <div class="avatar avatar-md" style="background:${c.bg || '#9A8A78'};font-size:13px;flex:none">${esc(c.initials || '??')}</div>
        <div style="flex:1;min-width:0">
          <div class="cmt-who">${esc(c.who)}${c.me ? ' <span class="cmt-you">(you)</span>' : ''}</div>
          <div class="cmt-meta">${esc(c.time_ago || '')} · in ${esc(c.project_name || 'a project')}</div>
        </div>
        ${c.unread ? '<span class="cmt-new">NEW</span>' : ''}
      </div>
      <div class="cmt-body">${quote}${linkify(c.text || '')}</div>
      <div class="cmt-replies" id="cmt-replies-${c.id}">${sent.map(cmtSentReplyHTML).join('')}</div>
      <div class="cmt-actions">
        <button class="cmt-reply-btn" onclick="toggleCommentReply(${c.id})">${CMT_REPLY_ICON}<span>Reply</span></button>
        ${replyCount ? `<span class="cmt-count">${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}</span>` : ''}
        <button class="cmt-open-btn" onclick="nav('project');goProject(${c.project_id})">View project →</button>
      </div>
      <div class="cmt-reply-box" id="cmt-reply-${c.id}" style="${open ? '' : 'display:none'}">
        <textarea class="cmt-reply-input" id="cmt-reply-input-${c.id}" rows="2"
          placeholder="Reply to ${esc(c.who)}… (Enter to send, Shift+Enter for a new line or bullet)"
          oninput="setCommentDraft(${c.id}, this.value); mlAutoGrow(this)"
          onkeydown="onCommentReplyKey(event, ${c.id}, ${c.project_id})">${esc(draft)}</textarea>
        <div class="cmt-reply-foot">
          <span class="cmt-reply-hint">Posts to the ${esc(c.project_name || 'project')} thread</span>
          <button class="btn-primary cmt-reply-send" onclick="sendCommentReply(${c.id}, ${c.project_id})">Send reply</button>
        </div>
      </div>
    </div>`;
}

function cmtSentReplyHTML(r) {
  return `<div class="cmt-reply-sent">
      <div class="avatar avatar-sm" style="background:${r.bg || '#9A8A78'};flex:none">${esc(r.initials || '??')}</div>
      <div style="min-width:0;flex:1">
        <div class="cmt-meta"><strong>${esc(r.who)}</strong> · ${esc(r.time_ago || 'just now')}</div>
        <div class="cmt-reply-text">${linkify(r.text || '')}</div>
      </div>
    </div>`;
}

// The card is clickable as a whole, but the reply area inside it must not open
// the project every time someone clicks into the textarea.
function commentCardClick(e, projectId) {
  if (e.target.closest('.cmt-actions, .cmt-reply-box, .cmt-replies')) return;
  nav('project');
  goProject(projectId);
}

function toggleCommentReply(id) {
  const box = document.getElementById('cmt-reply-' + id);
  if (!box) return;
  if (_cmtReplyOpen.has(id)) {
    _cmtReplyOpen.delete(id);
    box.style.display = 'none';
  } else {
    _cmtReplyOpen.add(id);
    box.style.display = '';
    const input = document.getElementById('cmt-reply-input-' + id);
    if (input) { input.focus(); input.selectionStart = input.value.length; }
  }
}

function onCommentReplyKey(e, id, projectId) {
  if (mlListContinue(e)) return;
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendCommentReply(id, projectId);
  } else if (e.key === 'Escape') {
    toggleCommentReply(id);
  }
}

async function sendCommentReply(id, projectId) {
  const input = document.getElementById('cmt-reply-input-' + id);
  if (!input) return;
  const text = input.value.trim();
  if (!text) return;

  const sendBtn = document.querySelector('#cmt-reply-' + id + ' .cmt-reply-send');
  if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = 'Sending…'; }

  try {
    const res = await API.sendProjectChat(projectId, text, id);
    const m = res.message || {};
    const reply = {
      id: m.id,
      who: m.who || (State.user && State.user.name) || 'You',
      initials: m.initials || '??',
      bg: m.bg,
      text: m.text || text,
      time_ago: 'just now',
    };
    // Show it under the comment straight away…
    _cmtSentReplies[id] = (_cmtSentReplies[id] || []).concat([reply]);
    const list = document.getElementById('cmt-replies-' + id);
    if (list) list.insertAdjacentHTML('beforeend', cmtSentReplyHTML(reply));

    // …and put it in the feed itself, so it is still there after the next
    // re-render without waiting for a refetch.
    if (Array.isArray(State.commentsFeed) && m.id) {
      const parent = State.commentsFeed.find(c => c.id === id);
      State.commentsFeed.unshift({
        id: m.id,
        project_id: projectId,
        project_name: parent ? parent.project_name : '',
        who: reply.who,
        initials: reply.initials,
        bg: reply.bg,
        text: reply.text,
        time_ago: 'just now',
        me: true,
        unread: false,
        parent_id: id,
        reply_to: m.reply_to || (parent ? { who: parent.who, text: parent.text } : null),
        reply_count: 0,
      });
    }

    // Clear the box but leave it open — the whole point is replying to several
    // messages in a row without leaving this screen.
    input.value = '';
    _cmtDrafts[id] = '';
    input.focus();
    toast('Reply posted to the project thread', 'success');
    // The project page caches its own copy of the thread; drop it so the reply
    // is there when the user opens the project.
    if (State.activeProjectId === projectId) State.activeProject = null;
  } catch (e) {
    toast(e.message || 'Could not send reply', 'error');
  } finally {
    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'Send reply'; }
  }
}

// A mention card opens the exact thing that was mentioned, using the same
// server-computed target as the notification bell.
function openMentionCard(idx) {
  const n = (State.mentions || [])[idx];
  if (!n) return;
  if (!n.is_read) { n.is_read = true; if (typeof API.markRead === 'function') API.markRead(n.id).catch(() => {}); }
  if (typeof goToNotification === 'function') goToNotification(n.target);
}

async function openMentions() {
  State.viewMentions = !State.viewMentions;
  if (!State.viewMentions) { render(); return; }
  State.viewComments = false;
  State.activeChannel = null;
  State.channelName = null;
  render();
  // Now that the badge counts unread rather than all, opening the list has to
  // clear them or it stays lit forever. Render first so the items are seen once.
  const unread = (State.mentions || []).filter(n => !n.is_read);
  if (!unread.length) return;
  try {
    await Promise.all(unread.map(n => API.markRead(n.id).catch(() => {})));
    State.mentions = (State.mentions || []).map(n => ({ ...n, is_read: true }));
    if (typeof loadNotifCount === 'function') loadNotifCount();
    if (typeof loadMsgUnread === 'function') loadMsgUnread();
    render();
  } catch (e) {}
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
    // The GET marks the channel read server-side, so zero the cached badge now
    // instead of leaving it lit until the next poll.
    if (State.channels) {
      for (const list of [State.channels.channels, State.channels.dms]) {
        (list || []).forEach(c => { if (Number(c.id) === Number(id)) c.count = 0; });
      }
    }
    render();
    if (typeof loadMsgUnread === 'function') loadMsgUnread();
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

// The preview strip under the composer is hidden by CSS and only revealed by the
// `is-visible` class. It used to be toggled with an inline `display`, but the
// markup carried BOTH `display:none` and `display:flex` in one style attribute —
// the later declaration won, so an empty bar sat under every composer.
function onChatAttachmentSelect(files) {
  if (!files || !files.length) return;
  _chatAttachment = files[0];
  const preview = document.getElementById('chat-attachment-preview');
  const name = document.getElementById('chat-attachment-name');
  if (preview && name) {
    name.textContent = _chatAttachment.name;
    preview.classList.add('is-visible');
  }
}

function clearChatAttachment() {
  _chatAttachment = null;
  const preview = document.getElementById('chat-attachment-preview');
  if (preview) {
    preview.classList.remove('is-visible');
    const name = document.getElementById('chat-attachment-name');
    if (name) name.textContent = '';
  }
  const input = document.getElementById('chat-attachment-input');
  if (input) input.value = '';
}

async function sendChannelMsg() {
  const composer = document.getElementById('msg-composer');
  const text = composer.value.trim();
  if ((!text && !_chatAttachment) || !State.activeChannel) return;
  mlReset(composer);
  // Capture and clear the reply target BEFORE the await, so a fast second
  // message doesn't inherit the first one's parent.
  const replyTo = ChatReply.channel;
  chatCancelReply('channel');
  try {
    let res;
    if (_chatAttachment) {
      res = await API.sendMessageWithAttachment(State.activeChannel, text, _chatAttachment);
      clearChatAttachment();
    } else {
      res = await API.sendMessage(State.activeChannel, text, replyTo ? replyTo.id : null);
    }
    const m = res.message;
    const el = document.getElementById('conv-messages');
    if (el && m) {
      if (Array.isArray(State.channelMessages)) State.channelMessages.push(m);
      el.insertAdjacentHTML('beforeend', chatMsgHTML('channel', m));
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
  if (mlListContinue(e)) return;
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
  mlAutoGrow(composer);
  // Find @ mention query — only look at what precedes the caret.
  const text = composer.value.slice(0, composer.selectionStart);
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
  const user = mentionUsers[index];
  if (!user) return;
  // Replace the @query at the caret with @Name, leaving anything after it alone.
  const pos = composer.selectionStart;
  const before = composer.value.slice(0, pos).replace(/@\w*$/, '@' + user.name + ' ');
  composer.value = before + composer.value.slice(pos);
  mlAutoGrow(composer);
  hideMentionDropdown();
  composer.focus();
  composer.setSelectionRange(before.length, before.length);
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