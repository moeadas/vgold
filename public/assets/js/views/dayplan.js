// VGo — My Day Plan view (plan on top, tasks below)
async function renderDayPlan() {
  let plan;
  // Always fetch fresh to reflect latest task statuses
  try {
    const res = await API.getDayPlan();
    plan = res.plan;
    State.dayPlan = plan;
  } catch(e) { plan = null; State.dayPlan = null; }

  // Also fetch today's tasks (formerly the "Today" view)
  let todayData;
  try {
    todayData = await API.today();
    State.todayData = todayData;
  } catch(e) { todayData = { focus: [], followups: [], week: {} }; }

  const focus = todayData.focus || [];
  const followups = todayData.followups || [];
  const week = todayData.week || {};
  const greeting = getGreeting();
  const todayFormatted = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

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
      <div class="section-label">My Day Plan</div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:20px">
        <h1 class="page-title-sm">${greeting}, ${esc(State.user?.name?.split(' ')[0] || '')}</h1>
        <span style="font-size:14px;color:var(--muted)">${todayFormatted}</span>
      </div>

      ${plan ? `
        <div class="card day-plan-card" style="width:100%;max-width:860px;margin-bottom:30px">
          <div class="day-plan-content" id="day-plan-content">
            ${sanitizeHtml(plan.html || '')}
          </div>
          <div style="display:flex;align-items:center;gap:9px;border-top:1px solid var(--border);padding-top:18px;margin-top:24px">
            <div class="ai-badge" style="width:22px;height:22px;border-radius:6px">${I.sparkle}</div>
            <span style="font-size:12.5px;color:var(--muted);flex:1">Auto-generated daily plan · Refreshes each day</span>
            <button class="btn" onclick="regenerateDayPlan()">${I.sun}Regenerate</button>
          </div>
        </div>
      ` : `
        <div class="card card-pad" style="text-align:center;max-width:560px;margin:0 auto 30px auto">
          <div class="ai-badge" style="width:48px;height:48px;border-radius:14px;margin:0 auto 16px">${I.sparkle}</div>
          <p style="font-family:var(--serif);font-size:20px;color:var(--gold);margin-bottom:8px">No tasks to plan today</p>
          <p style="font-size:15px;color:var(--muted)">You're all caught up! Enjoy the breathing room.</p>
        </div>
      `}

      <div class="grid-2" style="max-width:1280px">
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

async function generateDayPlan() {
  const content = document.querySelector('.content');
  if (content) {
    content.innerHTML = `
      <div class="fade-in">
        <div class="section-label">My Day Plan</div>
        <h1 class="page-title-sm">Generating your plan...</h1>
        <div class="card card-pad" style="text-align:center;padding:40px;max-width:560px;margin:20px auto">
          <div class="ai-badge" style="width:48px;height:48px;border-radius:14px;margin:0 auto 16px;animation:vgPop 1s ease infinite alternate">${I.sparkle}</div>
          <p style="font-family:var(--serif);font-size:20px;color:var(--gold)">Analyzing your tasks and building a plan...</p>
          <p style="font-size:14px;color:var(--muted);margin-top:8px">This takes a few seconds</p>
        </div>
      </div>
    `;
  }
  
  try {
    const res = await API.planMyDay();
    State.dayPlan = { html: sanitizeHtml(res.plan_html || ''), date: new Date().toISOString().split('T')[0] };
    toast('Day plan generated!', 'success');
    render();
  } catch(e) {
    toast(e.message, 'error');
    State.dayPlan = null;
    render();
  }
}

async function regenerateDayPlan() {
  // Delete today's plan and reload (code-based auto-generation)
  try {
    await fetch('/api/ai/delete-plan', { method: 'POST', headers: API.csrfHeaders(), credentials: 'same-origin' });
  } catch(e) {}
  State.dayPlan = undefined;
  toast('Day plan refreshed', 'success');
  render();
}

function wireDayPlanLinks() {
  const container = document.getElementById('day-plan-content');
  if (!container) return;
  container.querySelectorAll('.ai-link').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const type = link.dataset.type;
      const id = parseInt(link.dataset.id);
      if (!id || isNaN(id)) {
        const text = link.textContent.trim().toLowerCase();
        if (type === 'task') {
          API.myTasks().then(res => {
            const task = res.tasks.find(t => t.title.toLowerCase().includes(text) || text.includes(t.title.toLowerCase()));
            if (task) navigateToTask(task.id, task.project_id);
          });
        } else if (type === 'project') {
          API.projects().then(res => {
            const proj = res.projects.find(p => p.name.toLowerCase().includes(text) || text.includes(p.name.toLowerCase()));
            if (proj) { State.screen = 'project'; State.activeProjectId = proj.id; State.activeProject = null; render(); }
          });
        }
        return;
      }
      if (type === 'task') navigateToTask(id);
      else if (type === 'project') { State.screen = 'project'; State.activeProjectId = id; State.activeProject = null; render(); }
    });
  });
}

async function toggleTodayTask(id, el) {
  try {
    const res = await API.toggleTask(id);
    const checkbox = el.querySelector('.checkbox');
    const title = el.querySelector('.task-title');
    if (res.status === 'completed' || res.status === 'done') {
      checkbox?.classList.add('done');
      title?.classList.add('done');
    } else {
      checkbox?.classList.remove('done');
      title?.classList.remove('done');
    }
    State.todayData = null;
    State.dayPlan = undefined;
    State.myTasksData = null;
    State.meetingData = null;
    setTimeout(() => render(), 500);
  } catch(e) { console.error(e); }
}