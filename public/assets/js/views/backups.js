// VGo — backups and code/repo parity, shown in Settings.
//
// The point of this panel is that a backup you have not checked is not a
// backup. It states when the last one actually succeeded, where the off-server
// copy went, and whether the code running here is the code in the repository —
// the two halves you need to rebuild from scratch.

function bkSize(bytes) {
  const b = Number(bytes) || 0;
  if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
  if (b >= 1024) return Math.round(b / 1024) + ' KB';
  return b + ' B';
}

function bkWhen(ts) {
  if (!ts) return '—';
  const d = new Date(String(ts).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return esc(ts);
  const mins = Math.round((Date.now() - d.getTime()) / 60000);
  if (mins < 60) return mins + ' min ago';
  if (mins < 1440) return Math.round(mins / 60) + 'h ago';
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
       + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

const BK_STATUS = {
  ok:         ['Backed up', 'ok'],
  local_only: ['On the server only', 'warn'],
  running:    ['Running…', 'warn'],
  failed:     ['Failed', 'bad'],
};

/** Rendered into Settings for admins. Returns '' for everyone else. */
async function renderBackupsCard(user) {
  if (!user || user.role !== 'admin') return '';

  if (State.backups === null || State.backups === undefined) {
    try { State.backups = await API.backups(); }
    catch (e) { State.backups = { error: e.message, runs: [] }; }
  }
  const b = State.backups || {};
  if (b.error) {
    return `<div class="settings-card"><h3>Backups</h3>
      <div class="mail-status mail-status-bad">${esc(b.error)}</div></div>`;
  }

  const runs = b.runs || [];
  const last = runs.find(r => r.status === 'ok' || r.status === 'local_only');
  const banner = b.healthy
    ? `<div class="mail-status mail-status-ok"><strong>Backed up ${esc(bkWhen(last?.finished_at))}.</strong>
         ${last?.remote_path ? 'A copy is on ' + esc(b.destination?.label || 'SharePoint') + '.' : 'The off-server copy did not go out — see below.'}</div>`
    : `<div class="mail-status mail-status-bad"><strong>No successful backup in the last 26 hours.</strong>
         ${runs.length ? 'The most recent attempt is listed below.' : 'Nothing has run yet — set up the daily schedule below, or run one now.'}</div>`;

  const exposed = b.dir_exposed
    ? `<div class="mail-status mail-status-warn" style="margin-top:10px">Archives are being written inside the web root because no directory beside it was writable. They are blocked by a server rule and given unguessable names, but a directory outside the web root would be safer.</div>`
    : '';

  const rows = runs.map(r => {
    const [label, tone] = BK_STATUS[r.status] || [r.status, 'warn'];
    return `<tr>
      <td>${esc(bkWhen(r.started_at))}<div class="ct-secline">${esc(r.trigger === 'cron' ? 'scheduled' : r.trigger)}</div></td>
      <td><span class="bk-pill bk-${tone}">${esc(label)}</span>
        ${r.error ? `<div class="ct-secline" style="color:var(--red,#B0432B)">${esc(r.error)}</div>` : ''}</td>
      <td style="text-align:right">${r.bytes ? esc(bkSize(r.bytes)) : '—'}</td>
      <td>${r.rows != null ? esc(Number(r.rows).toLocaleString()) + ' rows · ' + esc(r.tables) + ' tables' : '—'}</td>
      <td>${r.remote_path ? '<span title="' + esc(r.remote_path) + '">off-server ✓</span>' : '<span class="ct-secline">server only</span>'}</td>
      <td style="text-align:right">${r.downloadable
        ? `<a class="btn-secondary btn-sm" href="${esc(API.backupDownloadUrl(r.id))}">Download</a>`
        : '<span class="ct-secline">pruned</span>'}</td>
    </tr>`;
  }).join('');

  return `
    <div class="settings-card settings-card-wide" id="settings-backups">
      <h3>Backups</h3>
      <div class="desc">A full copy of every table, plus the uploaded files, taken daily and copied off this server. The archive holds a SQL dump that restores exactly, and a CSV per table for reading.</div>
      ${banner}
      ${exposed}

      <div class="bk-actions">
        <button class="btn-primary" id="bk-run" onclick="bkRunNow()">Back up now</button>
        <span class="desc" style="margin:0">Keeps ${esc(b.keep?.local)} days here, ${esc(b.keep?.remote)} off-server, plus ${esc(b.keep?.weekly)} weekly.</span>
      </div>
      <div id="bk-result" class="mail-status" style="display:none;margin-top:12px"></div>

      <div class="settings-subsection">
        <h4>Daily schedule</h4>
        <div class="desc">Add this in <strong>SiteGround → Site Tools → Devs → Cron Jobs</strong>, once a day (03:00 is a good time). Keep the URL private — the secret in it is what authorises the job.</div>
        <input class="form-input sched-cron-url" readonly onclick="this.select()"
               value="${esc(b.cron_url ? 'curl -s \\"' + b.cron_url + '&_t=$(date +%s)\\"' : 'Unavailable')}">
      </div>

      ${runs.length ? `
      <div class="settings-subsection">
        <h4>Recent runs</h4>
        <div class="table-container"><table class="table">
          <thead><tr><th>When</th><th>Result</th><th style="text-align:right">Size</th><th>Contents</th><th>Off-server</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div>` : ''}
    </div>

    ${await renderVersionCard()}`;
}

async function bkRunNow() {
  const btn = document.getElementById('bk-run');
  const out = document.getElementById('bk-result');
  const show = (msg, tone) => {
    if (!out) { toast(msg, tone === 'ok' ? 'success' : 'error'); return; }
    out.className = 'mail-status mail-status-' + tone;
    out.textContent = msg;
    out.style.display = 'block';
  };
  if (btn) { btn.disabled = true; btn.textContent = 'Backing up…'; }
  show('Dumping the database, packing the files and copying off-server. This takes a few seconds.', 'warn');
  try {
    const res = await API.runBackup();
    State.backups = null;
    if (res.status === 'local_only') {
      show('Backed up (' + bkSize(res.bytes) + '), but the off-server copy failed: ' + (res.warning || 'unknown reason'), 'warn');
    } else {
      show('Backed up ' + bkSize(res.bytes) + ' and copied to ' + (res.remote || 'the off-server store') + '.', 'ok');
    }
    setTimeout(render, 900);
  } catch (e) {
    show(e.message, 'bad');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Back up now'; }
  }
}

/* ---------------- code / repo parity ---------------- */

async function renderVersionCard() {
  const v = State.versionCheck;
  const body = !v
    ? `<div class="desc">Check that every file on this server matches the commit it was deployed from — so the server can be rebuilt from the repository with confidence.</div>
       <button class="btn-secondary" style="margin-top:10px" onclick="bkCheckVersion()">Check against GitHub</button>`
    : bkVersionBody(v);

  return `<div class="settings-card settings-card-wide" id="settings-code-version">
    <h3>Code version</h3>
    ${body}
    <div id="bk-version-msg" class="mail-status" style="display:none;margin-top:12px"></div>
  </div>`;
}

function bkVersionBody(v) {
  if (v.error) return `<div class="mail-status mail-status-bad">${esc(v.error)}</div>
    <button class="btn-secondary" style="margin-top:10px" onclick="bkCheckVersion()">Try again</button>`;
  if (v.reason) return `<div class="mail-status mail-status-warn">${esc(v.reason)}</div>`;

  const short = (s) => esc(String(s || '').slice(0, 10));
  const clean = v.ok
    ? `<div class="mail-status mail-status-ok"><strong>The live code matches the repository exactly.</strong>
         All ${esc(v.checked)} tracked files are identical to <code>${short(v.sha)}</code>.</div>`
    : `<div class="mail-status mail-status-bad"><strong>The live code has drifted from the repository.</strong>
         ${v.modified.length ? esc(v.modified.length) + ' file(s) differ' : ''}${v.modified.length && v.missing.length ? ', ' : ''}${v.missing.length ? esc(v.missing.length) + ' missing' : ''}.
         Rebuilding from git would not reproduce this server as it stands.</div>`;

  const list = (title, arr, tone) => arr && arr.length
    ? `<div class="bk-drift"><strong>${esc(title)}</strong><ul>${arr.slice(0, 25).map(f => `<li class="bk-${tone}">${esc(f)}</li>`).join('')}</ul>
       ${arr.length > 25 ? `<div class="ct-secline">…and ${arr.length - 25} more</div>` : ''}</div>` : '';

  return `
    ${clean}
    <div class="bk-version-facts">
      <div><span class="ct-secline">Deployed commit</span><a href="${esc(v.commit_url)}" target="_blank" rel="noopener"><code>${short(v.sha)}</code></a></div>
      <div><span class="ct-secline">Repository HEAD</span><code>${short(v.repo_head) || '—'}</code></div>
      <div><span class="ct-secline">Files checked</span><strong>${esc(v.checked)}</strong></div>
    </div>
    ${v.up_to_date === false
      ? `<div class="mail-status mail-status-warn" style="margin-top:10px">The repository has moved on since this deploy — HEAD is <code>${short(v.repo_head)}</code>${v.head_message ? ' (“' + esc(v.head_message) + '”)' : ''}. That is only a problem if you expected this server to be current.</div>`
      : ''}
    ${list('Different from the repository', v.modified, 'bad')}
    ${list('In the repository but missing here', v.missing, 'bad')}
    ${v.extra_total ? `<div class="bk-drift"><strong>Here but not in the repository (${esc(v.extra_total)})</strong>
        <ul>${v.extra.slice(0, 15).map(f => `<li class="bk-warn">${esc(f)}</li>`).join('')}</ul>
        <div class="ct-secline">These would not come back from a git clone. Anything you need must be committed.</div></div>` : ''}
    <button class="btn-secondary" style="margin-top:12px" onclick="bkCheckVersion()">Check again</button>`;
}

async function bkCheckVersion() {
  const msg = document.getElementById('bk-version-msg');
  if (msg) { msg.className = 'mail-status mail-status-warn'; msg.textContent = 'Comparing every file against GitHub…'; msg.style.display = 'block'; }
  try {
    State.versionCheck = await API.versionCheck();
  } catch (e) {
    State.versionCheck = { error: e.message };
  }
  render();
}
