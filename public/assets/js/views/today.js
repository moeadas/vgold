// VGo — Today view
async function renderToday() {
  let data = State.todayData;
  if (!data) {
    try { data = await API.today(); State.todayData = data; } catch(e) { data = { focus: [], followups: [], week: {} }; }
  }
  const focus = data.focus || [];
  const followups = data.followups || [];
  const week = data.week || {};
  const greeting = getGreeting();

  const focusHTML = focus.length ? focus.map(t => `
    <div class="task-item" onclick="toggleTodayTask(${t.id},this)">
      <span class="checkbox ${t.done ? 'done' : ''}">${I.check}</span>
      <div style="flex:1;min-width:0">
        <div class="task-title ${t.done ? 'done' : ''}">${esc(t.title)}</div>
        <div class="task-meta">
          <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:6px;height:6px;border-radius:99px;background:${t.dot || '#7C8454'}"></span>${esc(t.project)}</span>
          <span style="color:${t.due === 'Overdue' ? '#A8432B' : '#9C8060'};font-weight:${t.due === 'Overdue' ? 700 : 400}">${esc(t.due)}</span>
        </div>
      </div>
      ${t.ai_flagged ? '<span class="ai-flag">AI flagged</span>' : ''}
    </div>
  `).join('') : '<div class="text-muted" style="padding:12px 0">Nothing needs you today. You\'re all caught up.</div>';

  const followupHTML = followups.length ? followups.map(f => `
    <div class="task-item" style="cursor:default">
      <div class="avatar avatar-md" style="background:${f.avBg || '#9C8060'}">${esc(f.initials || '??')}</div>
      <div style="flex:1;min-width:0">
        <div class="task-title" style="font-size:14.5px">${esc(f.title)}</div>
        <div class="task-meta">${esc(f.meta)}</div>
      </div>
    </div>
  `).join('') : '';

  return `
    <div class="fade-in">
      <div class="section-label">Today</div>
      <h1 class="page-title">${greeting}</h1>
      <div class="card card-pad ai-summary">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:13px">
          <div class="ai-badge">${I.sparkle}</div>
          <span style="font-size:13px;font-weight:700">Here's where things stand</span>
        </div>
        <p class="ai-text">You have <b>${focus.length} things that need you today</b>. ${focus.length > 0 ? 'Focus on the most urgent first — everything else can wait.' : 'You\'re all caught up.'}</p>
        <div class="chip-row">
          <button class="chip" onclick="nav('projects')">Review projects ${I.arrowR}</button>
          <button class="chip" onclick="openAsk()">Ask VGo something</button>
        </div>
      </div>
      <div class="grid-2">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:11px">Needs you today</div>
          <div style="display:flex;flex-direction:column;gap:9px;margin-bottom:26px">${focusHTML}</div>
          ${followupHTML ? `<div style="font-size:13px;font-weight:700;color:var(--text-2);margin-bottom:11px">Waiting on others</div><div style="display:flex;flex-direction:column;gap:9px">${followupHTML}</div>` : ''}
        </div>
        <div style="display:flex;flex-direction:column;gap:16px">
          <div class="card card-pad-sm">
            <div style="font-size:13px;font-weight:700;margin-bottom:15px">Your week</div>
            <div class="stat-row">
              <div class="stat"><span class="label">Done</span><span class="value" style="color:var(--sage)">${week.done || 0}</span></div>
              <div class="stat-divider"></div>
              <div class="stat"><span class="label">In progress</span><span class="value">${week.in_progress || 0}</span></div>
              <div class="stat-divider"></div>
              <div class="stat"><span class="label">Blocked</span><span class="value" style="color:var(--barn)">${week.blocked || 0}</span></div>
            </div>
          </div>
          <div style="background:var(--text);border-radius:16px;padding:20px;color:var(--bg)">
            <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#C9B398;margin-bottom:10px">Tip</div>
            <div style="font-family:var(--serif);font-size:19px;line-height:1.35">Press <span style="font-family:var(--mono);font-size:13px;border:1px solid #C9B398;border-radius:5px;padding:1px 6px">⌘K</span> to ask VGo anything</div>
          </div>
        </div>
      </div>
    </div>
  `;
}

async function toggleTodayTask(id, el) {
  try {
    const res = await API.toggleTask(id);
    const checkbox = el.querySelector('.checkbox');
    const title = el.querySelector('.task-title');
    if (res.status === 'done') {
      checkbox.classList.add('done');
      title.classList.add('done');
    } else {
      checkbox.classList.remove('done');
      title.classList.remove('done');
    }
  } catch(e) { console.error(e); }
}