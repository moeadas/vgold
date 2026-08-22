/* =============================================================================
   VGo — CRM Sales Dashboard

   Four screens, all real pages (no popups for anything you do work in — Moe's
   standing preference):

     crm-sales           the dashboard: attainment, KPIs, leaderboard, clients,
                         commission and the sale ledger
     crm-sale-new        record / edit one sale
     crm-sales-targets   set targets per person per period (managers + admins)
     crm-sales-settings  commission rates (ADMINS ONLY)

   Everything money-shaped comes from the server already scoped: a rep's request
   is pinned to their own rep_user_id in SalesController::scope(), so this file
   never has to be the thing that keeps one rep out of another's numbers.

   Escaping: esc() for text, escJs() for anything landing inside a JS string
   literal in an inline handler. See [[vgold-security-audit]].
============================================================================= */

const SalesUI = {
  period: 'month',
  start: null,          // null = the current period
  rep: 'all',
  tab: 'overview',
  cache: null,
  targets: null,
  commission: null,
  form: null,           // draft for crm-sale-new
  leadResults: null,
};

/** Called by app.js on every realtime tick — see [[vgold-realtime]]. */
function invalidateSalesCache() {
  SalesUI.cache = null;
  SalesUI.targets = null;
  SalesUI.commission = null;
}
window.invalidateSalesCache = invalidateSalesCache;

/* ---------------------------------------------------------------- helpers */

function sdMoney(v, cur) {
  return (typeof crmMoney === 'function') ? crmMoney(v, cur || SalesUI.cache?.currency)
    : ((cur || 'USD') + ' ' + Number(v || 0).toLocaleString());
}
/** Short form for chart axes and dense cells: 1.2M / 48k / 940. */
function sdShort(v) {
  const n = Math.abs(Number(v) || 0);
  const s = n >= 1e9 ? (n / 1e9).toFixed(1) + 'B'
    : n >= 1e6 ? (n / 1e6).toFixed(n >= 1e7 ? 0 : 1) + 'M'
    : n >= 1e3 ? Math.round(n / 1e3) + 'k'
    : String(Math.round(n));
  return (Number(v) < 0 ? '-' : '') + s;
}
function sdPct(v) { return v === null || v === undefined ? '—' : (Math.round(Number(v) * 10) / 10) + '%'; }
function sdDate(v) { return (typeof crmModDate === 'function') ? crmModDate(v) : esc(v || ''); }

/**
 * Attainment against pace, not against 100%.
 * Being at 40% of target is fine on day 12 of a month and alarming on day 28,
 * so the colour is decided by the gap to where you should be by now.
 */
function sdTone(attainment, pace) {
  if (attainment === null || attainment === undefined) return 'neutral';
  const a = Number(attainment), p = Number(pace) || 0;
  if (a >= 100) return 'good';
  if (a >= p - 5) return 'ok';
  if (a >= p - 20) return 'warn';
  return 'bad';
}
/* The app is themed warm (overrides.css repaints crm-native's Apple blue to the
   VGo brown/gold), so these follow app.css's palette rather than stock iOS
   colours — a blue progress bar reads as a foreign element here. `ok` defers to
   the live accent token so it tracks any future re-theme. */
const SD_TONE_COLOR = {
  good: 'var(--sage, #5B8C5A)',
  ok: 'var(--color-accent, #7e6549)',
  warn: 'var(--ochre, #C99520)',
  bad: 'var(--barn, #B0432B)',
  neutral: '#d6d0c7',
};

/** Progress ring. Visually capped at 100%; the label always tells the truth. */
function sdRing(pct, tone, size) {
  size = size || 148;
  const stroke = 13;
  const r = (size / 2) - (stroke / 2) - 2;
  const c = 2 * Math.PI * r;
  const shown = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, Number(pct)));
  const dash = (c * shown) / 100;
  const color = SD_TONE_COLOR[tone] || SD_TONE_COLOR.neutral;
  return `<svg class="sd-ring" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" role="img"
      aria-label="Attainment ${sdPct(pct)}">
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="#ececeF" stroke-width="${stroke}"/>
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="${color}" stroke-width="${stroke}"
      stroke-linecap="round" stroke-dasharray="${dash.toFixed(2)} ${(c - dash).toFixed(2)}"
      transform="rotate(-90 ${size / 2} ${size / 2})"/>
    <text x="50%" y="48%" text-anchor="middle" class="sd-ring-value">${pct === null || pct === undefined ? '—' : Math.round(pct) + '%'}</text>
    <text x="50%" y="64%" text-anchor="middle" class="sd-ring-label">of target</text>
  </svg>`;
}

/** Track + fill + a "today" pace marker. */
function sdProgress(pct, tone, pace) {
  const shown = Math.max(0, Math.min(100, Number(pct) || 0));
  const color = SD_TONE_COLOR[tone] || SD_TONE_COLOR.neutral;
  const marker = (pace === null || pace === undefined) ? '' :
    `<span class="sd-pace" style="left:${Math.max(0, Math.min(100, Number(pace)))}%" title="Where you should be today"></span>`;
  return `<span class="sd-track"><span class="sd-fill" style="width:${shown}%;background:${color}"></span>${marker}</span>`;
}

/* ------------------------------------------------------------ trend chart */

/**
 * 12 months of booked revenue as bars, with the target as a line over the top.
 * Hand-rolled SVG on purpose: the app loads no charting library, and the CSP
 * blocks third-party script origins anyway.
 */
function sdTrendChart(trend, currency) {
  const rows = trend || [];
  if (!rows.length) return `<div class="empty-state"><p>No sales history yet.</p></div>`;
  const W = 760, H = 240, padL = 52, padR = 12, padT = 16, padB = 34;
  const iw = W - padL - padR, ih = H - padT - padB;
  const max = Math.max(1, ...rows.map(r => Math.max(Number(r.booked) || 0, Number(r.target) || 0)));
  const step = iw / rows.length;
  const bw = Math.max(6, Math.min(38, step * 0.52));
  const y = v => padT + ih - (ih * (Number(v) || 0) / max);
  const cx = i => padL + step * i + step / 2;

  const grid = [0, 0.25, 0.5, 0.75, 1].map(f => {
    const gy = padT + ih - ih * f;
    return `<line x1="${padL}" y1="${gy.toFixed(1)}" x2="${W - padR}" y2="${gy.toFixed(1)}" class="sd-grid"/>
      <text x="${padL - 8}" y="${(gy + 4).toFixed(1)}" text-anchor="end" class="sd-axis">${sdShort(max * f)}</text>`;
  }).join('');

  const bars = rows.map((r, i) => {
    const v = Number(r.booked) || 0;
    const top = y(v);
    const h = Math.max(v > 0 ? 2 : 0, padT + ih - top);
    const collected = Number(r.collected) || 0;
    const ch = Math.max(collected > 0 ? 2 : 0, ih * collected / max);
    return `<g>
      <rect x="${(cx(i) - bw / 2).toFixed(1)}" y="${top.toFixed(1)}" width="${bw.toFixed(1)}" height="${h.toFixed(1)}"
        rx="4" class="sd-bar"><title>${esc(r.label)} ${esc(r.year)} — booked ${esc(sdMoney(v, currency))}</title></rect>
      <rect x="${(cx(i) - bw / 2).toFixed(1)}" y="${(padT + ih - ch).toFixed(1)}" width="${bw.toFixed(1)}" height="${ch.toFixed(1)}"
        rx="4" class="sd-bar-collected"><title>collected ${esc(sdMoney(collected, currency))}</title></rect>
    </g>`;
  }).join('');

  const hasTarget = rows.some(r => Number(r.target) > 0);
  const line = hasTarget ? `<polyline class="sd-target-line" points="${
    rows.map((r, i) => `${cx(i).toFixed(1)},${y(r.target).toFixed(1)}`).join(' ')}"/>` +
    rows.map((r, i) => Number(r.target) > 0
      ? `<circle class="sd-target-dot" cx="${cx(i).toFixed(1)}" cy="${y(r.target).toFixed(1)}" r="3"><title>target ${esc(sdMoney(r.target, currency))}</title></circle>`
      : '').join('') : '';

  const labels = rows.map((r, i) =>
    `<text x="${cx(i).toFixed(1)}" y="${H - 12}" text-anchor="middle" class="sd-axis">${esc(r.label)}</text>`).join('');

  return `<div class="sd-chart-wrap">
    <svg viewBox="0 0 ${W} ${H}" class="sd-chart" role="img" aria-label="Booked revenue by month against target">
      ${grid}${bars}${line}${labels}
    </svg>
    <div class="sd-legend">
      <span><i class="sd-key sd-key-booked"></i>Booked</span>
      <span><i class="sd-key sd-key-collected"></i>Collected</span>
      ${hasTarget ? `<span><i class="sd-key sd-key-target"></i>Target</span>` : ''}
    </div>
  </div>`;
}

/* =========================================================== the dashboard */

async function renderSalesDashboard() {
  if (!crmHas('crm.sales')) return crmAccessDenied('crm.sales');

  let d = SalesUI.cache;
  if (!d) {
    try {
      d = await API.salesDashboard({
        period: SalesUI.period,
        ...(SalesUI.start ? { start: SalesUI.start } : {}),
        ...(SalesUI.rep && SalesUI.rep !== 'all' ? { rep: SalesUI.rep } : {}),
      });
      SalesUI.cache = d;
    } catch (e) { return crmModError('Sales Dashboard', e.message); }
  }

  const k = d.kpis || {}, cur = d.currency;
  const tone = sdTone(k.attainment, k.pace);
  const scopeNote = d.scope === 'own' ? 'Your results'
    : d.scope === 'person' ? ((d.people || []).find(p => p.id === d.viewing_user)?.name || 'One person')
    : 'Whole team';

  /* ---- header controls ---- */
  const periodBtns = [['month', 'Month'], ['quarter', 'Quarter'], ['year', 'Year']].map(([k2, l]) =>
    `<button class="sd-seg ${SalesUI.period === k2 ? 'active' : ''}" onclick="salesSetPeriod('${k2}')">${l}</button>`).join('');

  const repFilter = d.can_manage ? `
    <select class="form-control sd-rep-select" onchange="salesSetRep(this.value)" aria-label="Filter by sales person">
      <option value="all"${SalesUI.rep === 'all' ? ' selected' : ''}>Whole team</option>
      ${(d.people || []).map(p => `<option value="${p.id}"${String(SalesUI.rep) === String(p.id) ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}
    </select>` : '';

  const actions = `
    ${repFilter}
    <button class="btn btn-outline" onclick="salesGoNew()">Record a sale</button>
    ${d.can_manage ? `<button class="btn btn-outline" onclick="nav('crm-sales-targets')">Targets</button>` : ''}
    ${d.can_admin ? `<button class="btn btn-outline" onclick="nav('crm-sales-settings')">Commission</button>` : ''}
    ${d.can_manage ? `<button class="btn btn-outline" onclick="salesSyncNow()" title="Pull invoices from Accounting">Sync</button>` : ''}`;

  const p = d.period || {};
  const periodNav = `
    <div class="sd-periodbar">
      <div class="sd-seg-group" role="group" aria-label="Period type">${periodBtns}</div>
      <div class="sd-period-nav">
        <button class="btn btn-ghost btn-sm" onclick="salesShiftPeriod('${escJs(p.prev)}')" aria-label="Previous period">‹</button>
        <span class="sd-period-label">${esc(p.label || '')}${p.is_current ? '' : ' <span class="badge badge-gray">past</span>'}</span>
        <button class="btn btn-ghost btn-sm" onclick="salesShiftPeriod('${escJs(p.next)}')" aria-label="Next period">›</button>
        ${p.is_current ? '' : `<button class="btn btn-ghost btn-sm" onclick="salesThisPeriod()">Today</button>`}
      </div>
      <span class="sd-scope">${esc(scopeNote)}</span>
    </div>`;

  /* ---- hero: attainment ---- */
  const targetNote = k.target > 0
    ? (k.target_derived ? `Split from the ${esc(k.target_derived)} target` :
       (k.target_source === 'sum' ? 'Sum of individual targets' :
        k.target_source === 'team' ? 'Team target' : 'Personal target'))
    : (d.can_manage ? 'No target set for this period' : 'No target set yet');

  const hero = `
    <div class="card sd-hero">
      <div class="sd-hero-ring">${sdRing(k.attainment, tone)}</div>
      <div class="sd-hero-body">
        <div class="sd-hero-top">
          <div>
            <div class="sd-hero-kicker">Booked in ${esc(p.label || '')}</div>
            <div class="sd-hero-value">${esc(sdMoney(k.booked, cur))}</div>
            <div class="sd-hero-sub">against a target of <strong>${k.target > 0 ? esc(sdMoney(k.target, cur)) : '—'}</strong>
              <span class="sd-hint">· ${esc(targetNote)}</span></div>
          </div>
          ${k.target > 0 ? `<div class="sd-hero-gap ${tone}">
            <div class="sd-gap-value">${k.gap > 0 ? esc(sdMoney(k.gap, cur)) : 'Target met'}</div>
            <div class="sd-gap-label">${k.gap > 0 ? 'still to go' : 'ahead of plan'}</div>
          </div>` : (d.can_manage ? `<button class="btn btn-primary btn-sm" onclick="nav('crm-sales-targets')">Set a target</button>` : '')}
        </div>
        ${sdProgress(k.attainment, tone, k.pace)}
        <div class="sd-hero-foot">
          <span><strong>${sdPct(k.pace)}</strong> of the period elapsed</span>
          <span><strong>${Number(k.days_left) || 0}</strong> days left</span>
          <span><strong>${Number(k.deals) || 0}</strong> deals won${k.target_deals > 0 ? ` of ${k.target_deals}` : ''}</span>
          ${k.pipeline > 0 ? `<span class="sd-foot-pending"><strong>${esc(sdMoney(k.pipeline, cur))}</strong> awaiting confirmation</span>` : ''}
        </div>
      </div>
    </div>`;

  /* ---- KPI cards ---- */
  const kpi = (label, value, sub, icon, cls) => `
    <div class="stat-card sd-kpi">
      <div class="stat-icon ${cls || ''}">${icon}</div>
      <div class="stat-content" style="min-width:0">
        <div class="stat-label">${esc(label)}</div>
        <div class="stat-value">${esc(value)}</div>
        ${sub ? `<div class="sd-kpi-sub">${sub}</div>` : ''}
      </div>
    </div>`;
  const kpis = `<div class="stats-grid sd-kpis">
    ${kpi('Booked', sdMoney(k.booked, cur), `${Number(k.deals) || 0} deals · avg ${esc(sdMoney(k.avg_deal, cur))}`, CT_IC.chart)}
    ${kpi('Cash collected', sdMoney(k.collected, cur),
      k.outstanding > 0 ? `<span class="sd-warn">${esc(sdMoney(k.outstanding, cur))} outstanding</span>` : 'Fully collected', CT_IC.check, 'sd-ic-green')}
    ${kpi('Commission earned', sdMoney(k.commission, cur),
      `on cash collected${k.commission_potential > 0 ? ` · ${esc(sdMoney(k.commission_potential, cur))} at full payment` : ''}`, CT_IC.doc, 'sd-ic-gold')}
    ${kpi('Attainment', sdPct(k.attainment), k.target > 0 ? `vs ${sdPct(k.pace)} of period elapsed` : 'no target set', CT_IC.clock, 'sd-ic-' + tone)}
    ${kpi('Awaiting confirmation', sdMoney(k.pipeline, cur),
      `${Number(k.pipeline_deals) || 0} logged by reps · ${(d.clients || []).length} clients sold to`, CT_IC.clock, 'sd-ic-amber')}
  </div>`;

  /* ---- leaderboard ---- */
  const board = (d.board || []).map((b, i) => {
    const t = sdTone(b.attainment, k.pace);
    return `<div class="sd-board-row" onclick="salesSetRep('${b.user_id}')" title="Show only ${esc(b.name)}">
      <span class="sd-rank">${i + 1}</span>
      <span class="avatar avatar-sm sd-avatar" style="background:${escJs(b.avatar_color || '#9A8A78')}">${esc(b.initials || '?')}</span>
      <span class="sd-board-who">
        <span class="sd-board-name">${esc(b.name)}</span>
        <span class="sd-board-role">${esc(b.crm_role || '')}${b.rate > 0 ? ' · ' + b.rate + '% commission' : ''}</span>
      </span>
      <span class="sd-board-bar">
        ${sdProgress(b.attainment, t, k.pace)}
        <span class="sd-board-nums">${esc(sdMoney(b.booked, cur))}${b.target > 0 ? ` / ${esc(sdMoney(b.target, cur))}` : ' · no target'}</span>
      </span>
      <span class="sd-board-att ${t}">${sdPct(b.attainment)}</span>
      <span class="sd-board-cell">${Number(b.deals) || 0}</span>
      <span class="sd-board-cell">${esc(sdMoney(b.commission, cur))}</span>
    </div>`;
  }).join('');

  const boardCard = d.can_manage ? `
    <div class="card sd-board">
      <div class="card-header">
        <h3 class="card-title">Team performance</h3>
        <div class="card-actions"><span class="sd-board-legend">booked / target · deals · commission</span></div>
      </div>
      <div class="card-body">
        ${board || `<div class="empty-state"><p>No targets set and no sales recorded in this period.</p></div>`}
      </div>
    </div>` : '';

  /* ---- clients ---- */
  const clientRows = (d.clients || []).map(c => `<tr>
    <td><strong>${c.lead_id
      ? `<a href="#crm/lead/${c.lead_id}" onclick="event.preventDefault();goCrmLead(${c.lead_id})">${esc(c.name)}</a>`
      : esc(c.name)}</strong></td>
    <td>${esc(c.reps || '—')}</td>
    <td style="text-align:right">${Number(c.deals) || 0}</td>
    <td style="text-align:right"><strong>${esc(sdMoney(c.amount, c.currency || cur))}</strong></td>
    <td style="text-align:right">${esc(sdMoney(c.collected, c.currency || cur))}
      ${c.amount - c.collected > 0.005 ? `<div class="ct-secline sd-warn">${esc(sdMoney(c.amount - c.collected, c.currency || cur))} open</div>` : ''}</td>
    <td style="text-align:right">${esc(sdMoney(c.commission, c.currency || cur))}</td>
    <td>${sdDate(c.last_sale)}</td>
  </tr>`).join('');

  /* ---- sale ledger ---- */
  const saleRows = (d.sales || []).map(s => {
    const badge = s.status === 'won' ? crmBadge('Won', 'badge-green')
      : s.status === 'pending' ? crmBadge('Pending', 'badge-yellow')
      : crmBadge('Cancelled', 'badge-gray');
    const src = s.acc_document_id
      ? `<span class="sd-src" title="Mirrored from an Accounting invoice">Invoice</span>`
      : `<span class="sd-src sd-src-manual" title="Recorded by hand">Manual</span>`;
    const canEdit = d.can_manage;
    return `<tr>
      <td>${sdDate(s.sale_date)}</td>
      <td><strong>${s.lead_id
        ? `<a href="#crm/lead/${s.lead_id}" onclick="event.preventDefault();goCrmLead(${s.lead_id})">${esc(s.client_name)}</a>`
        : esc(s.client_name)}</strong>
        ${s.product ? `<div class="ct-secline">${esc(s.product)}</div>` : ''}</td>
      <td>${esc(s.rep_name || 'Unassigned')}</td>
      <td style="text-align:right"><strong>${esc(sdMoney(s.amount, s.currency))}</strong></td>
      <td style="text-align:right">${esc(sdMoney(s.collected, s.currency))}</td>
      <td style="text-align:right">${esc(sdMoney(s.commission, s.currency))}
        <div class="ct-secline">${s.commission_rate}%${s.rate_override ? ' (set on this deal)' : ''}</div></td>
      <td>${badge} ${src}</td>
      <td><div class="ct-actions-cell">
        ${canEdit && s.status === 'pending' ? `<button class="btn btn-sm btn-primary" onclick="salesConfirm(${s.id})">Confirm</button>` : ''}
        ${canEdit ? `<button class="btn btn-sm btn-outline" onclick="salesGoEdit(${s.id})">Edit</button>` : ''}
        ${canEdit && !s.acc_document_id ? `<button class="btn btn-sm btn-outline" onclick="salesDelete(${s.id}, '${escJs(s.client_name)}')">Delete</button>` : ''}
      </div></td>
    </tr>`;
  }).join('');

  /* ---- lead pipeline (links back to Leads) ---- */
  const pipeCard = (d.lead_pipeline || []).length ? `
    <div class="card">
      <div class="card-header"><h3 class="card-title">Open pipeline in Leads</h3>
        <div class="card-actions"><button class="btn btn-sm btn-outline" onclick="nav('crm-leads')">Open leads</button></div></div>
      <div class="card-body">${crmBars(d.lead_pipeline, '#0071e3')}</div>
    </div>` : '';

  const mixed = d.mixed_currency ? `<div class="sd-notice">More than one currency appears in this period. Totals are added up as
    entered — convert them in Accounting if you need one reporting currency.</div>` : '';

  return `<div class="crm-native sales-dash fade-in">
    ${crmModHead('Sales Dashboard', 'Targets, results and commission — live from CRM leads and Accounting invoices.', actions)}
    ${periodNav}
    ${mixed}
    ${hero}
    ${kpis}
    <div class="sd-grid-2">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Last 12 months</h3></div>
        <div class="card-body">${sdTrendChart(d.trend, cur)}</div>
      </div>
      ${pipeCard || `<div class="card"><div class="card-header"><h3 class="card-title">Open pipeline in Leads</h3></div>
        <div class="card-body"><div class="empty-state"><p>No leads are currently in a closing stage.</p></div></div></div>`}
    </div>
    ${boardCard}
    <h3 class="sd-section">Clients sold to</h3>
    ${crmTable(['Client', 'Sold by', 'Deals', 'Sale price', 'Collected', 'Commission', 'Last sale'], clientRows,
      'No sales recorded in this period yet.')}
    <h3 class="sd-section">Sales in ${esc(p.label || 'this period')}</h3>
    ${crmTable(['Date', 'Client', 'Rep', 'Price', 'Collected', 'Commission', 'Status', ''], saleRows,
      'Nothing recorded yet. Use “Record a sale”, or link an invoice to a CRM lead in Accounting.')}
  </div>`;
}

/* ------------------------------------------------------- dashboard actions */

function salesSetPeriod(type) { SalesUI.period = type; SalesUI.start = null; SalesUI.cache = null; render(); }
function salesShiftPeriod(start) { SalesUI.start = start; SalesUI.cache = null; render(); }
function salesThisPeriod() { SalesUI.start = null; SalesUI.cache = null; render(); }
function salesSetRep(rep) { SalesUI.rep = String(rep); SalesUI.cache = null; render(); }

async function salesSyncNow() {
  try {
    const r = await API.salesSync();
    SalesUI.cache = null;
    toast(r.created || r.updated
      ? `Accounting sync: ${r.created} new, ${r.updated} updated.`
      : 'Accounting is already in step — nothing changed.', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

async function salesConfirm(id) {
  try {
    await API.updateSale(id, { status: 'won' });
    SalesUI.cache = null;
    toast('Sale confirmed.', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

function salesDelete(id, name) {
  appConfirm(`Delete the sale to ${name || 'this client'}? It will stop counting toward targets and commission.`, async () => {
    try {
      await API.deleteSale(id);
      SalesUI.cache = null;
      toast('Sale deleted.', 'success');
      render();
    } catch (e) { toast(e.message, 'error'); }
  });
}

/* ==================================================== record / edit a sale */

function salesGoNew() {
  SalesUI.form = { id: null, lead_id: null, client_name: '', product: '', rep_user_id: null,
                   amount: '', collected_amount: '', sale_date: new Date().toISOString().slice(0, 10),
                   status: 'won', commission_rate: '', notes: '' };
  SalesUI.leadResults = null;
  nav('crm-sale-new');
}

function salesGoEdit(id) {
  const s = ((SalesUI.cache && SalesUI.cache.sales) || []).find(x => x.id === id);
  if (!s) { toast('Reopen the dashboard and try again.', 'error'); return; }
  SalesUI.form = {
    id: s.id, lead_id: s.lead_id, client_name: s.client_name, product: s.product || '',
    rep_user_id: s.rep_user_id, amount: s.amount, collected_amount: s.collected,
    sale_date: s.sale_date, status: s.status,
    commission_rate: s.rate_override ? s.commission_rate : '',
    notes: s.notes || '', locked: !!s.acc_document_id, acc_document_id: s.acc_document_id,
  };
  SalesUI.leadResults = null;
  nav('crm-sale-new');
}

async function renderSaleForm() {
  if (!crmHas('crm.sales')) return crmAccessDenied('crm.sales');
  const f = SalesUI.form;
  if (!f) { nav('crm-sales'); return ''; }

  let opts;
  try { opts = await API.salesOptions(); }
  catch (e) { return crmModError('Record a sale', e.message); }

  const people = opts.people || [];
  const repId = f.rep_user_id || opts.me;
  const editing = !!f.id;

  const repField = opts.can_manage ? `
    <div class="form-group">
      <label class="form-label">Sales person<span class="required">*</span></label>
      <select class="form-control" id="sale-rep">
        ${people.map(p => `<option value="${p.id}"${String(p.id) === String(repId) ? ' selected' : ''}>${esc(p.name)}${p.rate > 0 ? ` — ${p.rate}%` : ' — no commission rate set'}</option>`).join('')}
      </select>
    </div>` : `<input type="hidden" id="sale-rep" value="${opts.me}">`;

  const chosen = f.lead_id
    ? `<div class="sd-chosen">Linked to CRM lead <strong>${esc(f.client_name)}</strong>
         <button type="button" class="btn btn-ghost btn-sm" onclick="salesClearLead()">Unlink</button></div>`
    : '';

  return `<div class="crm-native sales-dash fade-in">
    ${crmModHead(editing ? 'Edit sale' : 'Record a sale',
      editing ? 'Changing the price or the amount collected re-calculates commission.'
              : 'Link the deal to a CRM lead so it shows up on that client’s record.',
      `<button class="btn btn-outline" onclick="salesBackToDash()">Cancel</button>`)}

    ${f.locked ? `<div class="sd-notice">This sale mirrors Accounting invoice #${f.acc_document_id}. The price and the amount
      collected are read from the ledger and cannot be edited here — change the invoice in Accounting instead.</div>` : ''}
    ${!opts.can_manage ? `<div class="sd-notice">Your sale will be saved as <strong>pending</strong> and starts counting toward
      your target once a sales manager confirms it.</div>` : ''}

    <div class="card"><div class="card-body">
      <div class="sd-form-grid">
        <div class="form-group sd-span2">
          <label class="form-label" for="sale-client">Client<span class="required">*</span></label>
          <input class="form-control" id="sale-client" autocomplete="off"
            placeholder="Start typing a company or contact name…"
            value="${esc(f.client_name || '')}" oninput="salesLeadSearch()">
          <div class="sd-hint">Pick a CRM lead from the suggestions, or just type a name if the client is not in the CRM.</div>
          <div id="sale-lead-results" class="sd-suggest"></div>
          <div id="sale-lead-chosen">${chosen}</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="sale-product">What was sold</label>
          <input class="form-control" id="sale-product" value="${esc(f.product || '')}" placeholder="e.g. Genomic panel — 20 horses">
        </div>

        ${repField}

        <div class="form-group">
          <label class="form-label" for="sale-amount">Sale price<span class="required">*</span></label>
          <input class="form-control" id="sale-amount" type="number" min="0" step="0.01"
            value="${esc(String(f.amount ?? ''))}"${f.locked ? ' disabled' : ''}>
        </div>

        <div class="form-group">
          <label class="form-label" for="sale-collected">Cash collected so far</label>
          <input class="form-control" id="sale-collected" type="number" min="0" step="0.01"
            value="${esc(String(f.collected_amount ?? ''))}"${(f.locked || !opts.can_manage) ? ' disabled' : ''}>
          <div class="sd-hint">Commission is paid on what has actually been collected.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="sale-date">Sale date<span class="required">*</span></label>
          <input class="form-control" id="sale-date" type="date" value="${esc(f.sale_date || '')}">
        </div>

        ${opts.can_manage ? `
        <div class="form-group">
          <label class="form-label" for="sale-status">Status</label>
          <select class="form-control" id="sale-status">
            <option value="won"${f.status === 'won' ? ' selected' : ''}>Won — counts toward the target</option>
            <option value="pending"${f.status === 'pending' ? ' selected' : ''}>Pending confirmation</option>
            <option value="cancelled"${f.status === 'cancelled' ? ' selected' : ''}>Cancelled</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="sale-rate">Commission rate for this deal</label>
          <input class="form-control" id="sale-rate" type="number" min="0" max="100" step="0.01"
            value="${esc(String(f.commission_rate ?? ''))}" placeholder="Leave blank to use the rep’s rate">
          <div class="sd-hint">Only fill this in to override the standard rate on this one sale.</div>
        </div>` : ''}

        <div class="form-group sd-span2">
          <label class="form-label" for="sale-notes">Notes</label>
          <textarea class="form-control" id="sale-notes" rows="3">${esc(f.notes || '')}</textarea>
        </div>
      </div>

      <div class="sd-form-actions">
        <button class="btn btn-primary" onclick="salesSaveForm()">${editing ? 'Save changes' : 'Record sale'}</button>
        <button class="btn btn-outline" onclick="salesBackToDash()">Cancel</button>
      </div>
    </div></div>
  </div>`;
}

function salesBackToDash() { SalesUI.form = null; SalesUI.cache = null; nav('crm-sales'); }
function salesClearLead() {
  if (SalesUI.form) SalesUI.form.lead_id = null;
  const box = document.getElementById('sale-lead-chosen');
  if (box) box.innerHTML = '';
}

let _saleSearchTimer = null;
function salesLeadSearch() {
  clearTimeout(_saleSearchTimer);
  // Typing a fresh name means the previously picked lead no longer applies.
  if (SalesUI.form) SalesUI.form.lead_id = null;
  const chosen = document.getElementById('sale-lead-chosen');
  if (chosen) chosen.innerHTML = '';
  const q = (document.getElementById('sale-client')?.value || '').trim();
  const box = document.getElementById('sale-lead-results');
  if (!box) return;
  if (q.length < 2) { box.innerHTML = ''; return; }
  _saleSearchTimer = setTimeout(async () => {
    try {
      const res = await API.crmLeads({ q, limit: 8 });
      const leads = (res.leads || []).slice(0, 8);
      box.innerHTML = leads.length ? leads.map(l =>
        `<button type="button" class="sd-suggest-item" onclick="salesPickLead(${l.id}, '${escJs(l.display_name || l.company_name || '')}')">
          <strong>${esc(l.display_name || l.company_name || 'Lead #' + l.id)}</strong>
          <span>${esc(l.company_name && l.company_name !== l.display_name ? l.company_name : (l.country || ''))}</span>
        </button>`).join('')
        : `<div class="sd-suggest-empty">No matching lead — the name you typed will be saved as-is.</div>`;
    } catch (e) { box.innerHTML = ''; }
  }, 250);
}

function salesPickLead(id, name) {
  if (!SalesUI.form) return;
  SalesUI.form.lead_id = id;
  SalesUI.form.client_name = name;
  const input = document.getElementById('sale-client');
  if (input) input.value = name;
  const box = document.getElementById('sale-lead-results');
  if (box) box.innerHTML = '';
  const chosen = document.getElementById('sale-lead-chosen');
  if (chosen) {
    chosen.innerHTML = `<div class="sd-chosen">Linked to CRM lead <strong>${esc(name)}</strong>
      <button type="button" class="btn btn-ghost btn-sm" onclick="salesClearLead()">Unlink</button></div>`;
  }
}

async function salesSaveForm() {
  const f = SalesUI.form;
  if (!f) return;
  const val = id => document.getElementById(id)?.value;
  const payload = {
    lead_id: f.lead_id || null,
    client_name: (val('sale-client') || '').trim(),
    product: val('sale-product') || '',
    rep_user_id: Number(val('sale-rep')) || null,
    sale_date: val('sale-date'),
    notes: val('sale-notes') || '',
  };
  if (!f.locked) {
    payload.amount = Number(val('sale-amount')) || 0;
    payload.collected_amount = Number(val('sale-collected')) || 0;
  }
  if (document.getElementById('sale-status')) payload.status = val('sale-status');
  if (document.getElementById('sale-rate')) payload.commission_rate = val('sale-rate');

  if (!payload.client_name) { toast('Enter a client name.', 'error'); return; }
  if (!f.locked && !(payload.amount > 0)) { toast('Enter the sale price.', 'error'); return; }

  try {
    if (f.id) await API.updateSale(f.id, payload);
    else await API.createSale(payload);
    SalesUI.form = null;
    SalesUI.cache = null;
    State.crmLeadDetail = null;
    toast(f.id ? 'Sale updated.' : 'Sale recorded.', 'success');
    nav('crm-sales');
  } catch (e) { toast(e.message, 'error'); }
}

/* ============================================================ targets page */

async function renderSalesTargets() {
  if (!crmHas('crm.sales')) return crmAccessDenied('crm.sales');
  let d = SalesUI.targets;
  if (!d) {
    try {
      d = await API.salesTargets({ period: SalesUI.period, ...(SalesUI.start ? { start: SalesUI.start } : {}) });
      SalesUI.targets = d;
    } catch (e) { return crmModError('Sales targets', e.message); }
  }
  const p = d.period || {}, cur = d.currency;
  const ro = !d.can_manage;

  const row = (id, name, sub, target, deals, booked, dealCount, derived) => `<tr>
    <td><strong>${esc(name)}</strong>${sub ? `<div class="ct-secline">${esc(sub)}</div>` : ''}</td>
    <td style="text-align:right">${esc(sdMoney(booked, cur))}<div class="ct-secline">${Number(dealCount) || 0} deals</div></td>
    <td><input class="form-control sd-target-input" type="number" min="0" step="1"
        data-target-user="${id}" value="${target > 0 ? esc(String(target)) : ''}"
        placeholder="0"${ro ? ' disabled' : ''}>
        ${derived ? `<div class="ct-secline">currently split from the ${esc(derived)} target</div>` : ''}</td>
    <td><input class="form-control sd-target-input" type="number" min="0" step="1"
        data-target-deals="${id}" value="${deals > 0 ? esc(String(deals)) : ''}" placeholder="0"${ro ? ' disabled' : ''}></td>
  </tr>`;

  const rows = (d.people || []).map(pp =>
    row(pp.id, pp.name, pp.crm_role + (pp.rate > 0 ? ` · ${pp.rate}% commission` : ''),
        pp.target, pp.target_deals, pp.booked, pp.deals, pp.derived)).join('');

  const teamRow = d.can_manage ? `<tr class="sd-team-row">
    <td><strong>Whole team</strong><div class="ct-secline">Optional. Left blank, the team target is the sum of everyone’s.</div></td>
    <td style="text-align:right">—</td>
    <td><input class="form-control sd-target-input" type="number" min="0" step="1" data-target-user="0"
        value="${d.team_target > 0 ? esc(String(d.team_target)) : ''}" placeholder="Sum of individuals"></td>
    <td><input class="form-control sd-target-input" type="number" min="0" step="1" data-target-deals="0" placeholder="0"></td>
  </tr>` : '';

  const periodBtns = [['month', 'Month'], ['quarter', 'Quarter'], ['year', 'Year']].map(([k2, l]) =>
    `<button class="sd-seg ${SalesUI.period === k2 ? 'active' : ''}" onclick="salesTargetsPeriod('${k2}')">${l}</button>`).join('');

  return `<div class="crm-native sales-dash fade-in">
    ${crmModHead('Sales targets', d.can_manage
      ? 'Set what each person is aiming for. A quarterly or annual number is split evenly across the months inside it until you set those directly.'
      : 'Your targets. Only a sales manager or an administrator can change them.',
      `<button class="btn btn-outline" onclick="nav('crm-sales')">Back to dashboard</button>`)}
    <div class="sd-periodbar">
      <div class="sd-seg-group" role="group" aria-label="Period type">${periodBtns}</div>
      <div class="sd-period-nav">
        <button class="btn btn-ghost btn-sm" onclick="salesTargetsShift('${escJs(p.prev)}')" aria-label="Previous period">‹</button>
        <span class="sd-period-label">${esc(p.label || '')}</span>
        <button class="btn btn-ghost btn-sm" onclick="salesTargetsShift('${escJs(p.next)}')" aria-label="Next period">›</button>
      </div>
    </div>
    ${crmTable(['Person', `Booked in ${p.label || ''}`, `Target (${cur})`, 'Target deals'], rows + teamRow, 'No team members found.')}
    ${d.can_manage ? `<div class="sd-form-actions">
      <button class="btn btn-primary" onclick="salesSaveTargets()">Save targets for ${esc(p.label || '')}</button>
      <span class="sd-hint">Clearing a number removes that target. Everyone whose number changes gets a notification.</span>
    </div>` : ''}
  </div>`;
}

function salesTargetsPeriod(type) { SalesUI.period = type; SalesUI.start = null; SalesUI.targets = null; SalesUI.cache = null; render(); }
function salesTargetsShift(start) { SalesUI.start = start; SalesUI.targets = null; SalesUI.cache = null; render(); }

async function salesSaveTargets() {
  const p = SalesUI.targets?.period;
  if (!p) return;
  const byUser = {};
  document.querySelectorAll('[data-target-user]').forEach(el => {
    const id = el.getAttribute('data-target-user');
    byUser[id] = byUser[id] || { user_id: Number(id) };
    byUser[id].target_amount = Number(el.value) || 0;
  });
  document.querySelectorAll('[data-target-deals]').forEach(el => {
    const id = el.getAttribute('data-target-deals');
    byUser[id] = byUser[id] || { user_id: Number(id) };
    byUser[id].target_deals = Number(el.value) || 0;
  });
  try {
    await API.saveSalesTargets({ period_type: p.type, period_start: p.start, targets: Object.values(byUser) });
    SalesUI.targets = null;
    SalesUI.cache = null;
    toast('Targets saved.', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* ================================================ commission settings (admin) */

async function renderSalesCommission() {
  if (!crmHas('crm.sales')) return crmAccessDenied('crm.sales');
  let d = SalesUI.commission;
  if (!d) {
    try { d = await API.salesCommission(); SalesUI.commission = d; }
    catch (e) { return crmModError('Commission settings', e.message); }
  }
  const rows = (d.people || []).map(p => `<tr>
    <td><span class="avatar avatar-sm sd-avatar" style="background:${escJs(p.avatar_color || '#9A8A78')}">${esc(p.initials)}</span>
      <strong style="margin-left:8px">${esc(p.name)}</strong>
      <div class="ct-secline">${esc(p.email)}</div></td>
    <td>${esc(p.crm_role)}${p.is_admin ? ' ' + crmBadge('Admin', 'badge-gray') : ''}</td>
    <td style="max-width:160px">
      <div class="sd-rate-input">
        <input class="form-control" type="number" min="0" max="100" step="0.01"
          data-rate-user="${p.id}" value="${p.rate > 0 ? esc(String(p.rate)) : ''}" placeholder="0">
        <span>%</span>
      </div>
    </td>
  </tr>`).join('');

  return `<div class="crm-native sales-dash fade-in">
    ${crmModHead('Commission settings',
      'Administrators only. A rate applies to cash the person’s clients have actually paid, not to the invoiced amount.',
      `<button class="btn btn-outline" onclick="nav('crm-sales')">Back to dashboard</button>`)}
    <div class="sd-notice">Each sale keeps a copy of the rate that applied when it was recorded, so changing a rate here
      does not rewrite what was already earned. Sales dated in the current month or later are re-stamped with the new rate,
      except any deal where a manager set the rate by hand.</div>
    ${crmTable(['Person', 'Role', 'Commission rate'], rows, 'No team members found.')}
    <div class="sd-form-actions">
      <button class="btn btn-primary" onclick="salesSaveCommission()">Save rates</button>
    </div>
  </div>`;
}

async function salesSaveCommission() {
  const rates = [];
  document.querySelectorAll('[data-rate-user]').forEach(el => {
    rates.push({ user_id: Number(el.getAttribute('data-rate-user')), rate: Number(el.value) || 0 });
  });
  try {
    await API.saveSalesCommission(rates);
    SalesUI.commission = null;
    SalesUI.cache = null;
    toast('Commission rates saved.', 'success');
    render();
  } catch (e) { toast(e.message, 'error'); }
}

/* =========================================== the card on a CRM lead's page */

/**
 * Sales booked against one lead, shown on the lead detail screen. Rendered only
 * when the viewer holds crm.sales; the server has already filtered a rep's list
 * down to their own deals.
 */
function crmLeadSalesCard(sales, leadId, leadName) {
  if (!crmHas('crm.sales')) return '';
  const rows = sales || [];
  const total = rows.filter(s => s.status === 'won').reduce((a, s) => a + Number(s.amount || 0), 0);
  const collected = rows.filter(s => s.status === 'won').reduce((a, s) => a + Number(s.collected || 0), 0);
  const cur = rows[0]?.currency;
  const body = rows.length ? `
    <div class="sd-lead-summary">
      <div><span class="sd-lead-k">Sold</span><span class="sd-lead-v">${esc(sdMoney(total, cur))}</span></div>
      <div><span class="sd-lead-k">Collected</span><span class="sd-lead-v">${esc(sdMoney(collected, cur))}</span></div>
      <div><span class="sd-lead-k">Deals</span><span class="sd-lead-v">${rows.filter(s => s.status === 'won').length}</span></div>
    </div>
    <div class="table-container"><table class="table">
      <thead><tr><th>Date</th><th>What</th><th>Rep</th><th style="text-align:right">Price</th><th>Status</th></tr></thead>
      <tbody>${rows.map(s => `<tr>
        <td>${sdDate(s.sale_date)}</td>
        <td>${esc(s.product || '—')}</td>
        <td>${esc(s.rep_name || '—')}</td>
        <td style="text-align:right"><strong>${esc(sdMoney(s.amount, s.currency))}</strong></td>
        <td>${s.status === 'won' ? crmBadge('Won', 'badge-green')
              : s.status === 'pending' ? crmBadge('Pending', 'badge-yellow') : crmBadge('Cancelled', 'badge-gray')}</td>
      </tr>`).join('')}</tbody>
    </table></div>`
    : `<div class="empty-state" style="padding:18px"><p>No sales recorded against this client yet.</p></div>`;

  return `<div class="card sales-dash">
    <div class="card-header">
      <h3 class="card-title">Sales</h3>
      <div class="card-actions">
        <button class="btn btn-sm btn-outline" onclick="salesNewForLead(${Number(leadId) || 0}, '${escJs(leadName || '')}')">Record a sale</button>
        <button class="btn btn-sm btn-ghost" onclick="nav('crm-sales')">Dashboard</button>
      </div>
    </div>
    <div class="card-body">${body}</div>
  </div>`;
}

function salesNewForLead(leadId, name) {
  salesGoNew();
  if (SalesUI.form) { SalesUI.form.lead_id = leadId || null; SalesUI.form.client_name = name || ''; }
  render();
}

/** Small "sales vs target" tile for the CRM Overview screen. */
async function crmOverviewSalesTile() {
  if (!crmHas('crm.sales')) return '';
  try {
    const d = await API.salesDashboard({ period: 'month' });
    const k = d.kpis || {};
    const tone = sdTone(k.attainment, k.pace);
    return `<div class="card clickable-card sales-dash" onclick="nav('crm-sales')" style="cursor:pointer">
      <div class="card-body">
        <div class="sidebar-label">This month</div>
        <h3 class="card-title" style="margin:6px 0 8px">${esc(sdMoney(k.booked, d.currency))} booked</h3>
        ${sdProgress(k.attainment, tone, k.pace)}
        <p class="text-muted" style="font-size:13px;margin-top:10px">
          ${k.target > 0 ? `${sdPct(k.attainment)} of ${esc(sdMoney(k.target, d.currency))} · ${Number(k.days_left) || 0} days left`
            : 'No target set for this month'}</p>
        <a class="btn btn-sm btn-outline">Open Sales Dashboard →</a>
      </div>
    </div>`;
  } catch (e) { return ''; }
}
