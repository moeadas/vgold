// VGold — read a supplier bill from a PDF or photo and turn it into a draft.
//
// Deliberately a page, not a popup: reviewing extracted figures against the
// original document needs room, and the review is the whole point. Nothing is
// saved until the numbers have been looked at — an OCR'd total quietly becoming
// a payable is the failure mode worth designing against.

const BillScan = {
  file: null,
  busy: false,
  draft: null,
  error: null,
  newVendorName: null,   // set when the vendor did not match anything on file
};

function accGoBillScan() {
  BillScan.file = null; BillScan.draft = null; BillScan.error = null;
  BillScan.busy = false; BillScan.newVendorName = null;
  accNav('acc-bill-scan');
}

async function renderAccBillScan() {
  if (!accHas('acc.bills')) return accDenied('Bills');
  await accBoot();

  const body = BillScan.draft ? billScanReview() : billScanUpload();
  return `<div class="fade-in acc-page">
    ${accHeader('Add a bill from a document',
      'Upload the supplier PDF or a photo. The details are read off it, and you check them before anything is saved.',
      `<button class="btn-secondary" onclick="accNav('acc-bills')">← Back to bills</button>`)}
    ${body}
  </div>`;
}

/* ---------------- step 1: choose a file ---------------- */

function billScanUpload() {
  const f = BillScan.file;
  return `
    <div class="acc-card">
      <div class="billscan-drop" id="billscan-drop"
           ondragover="event.preventDefault();this.classList.add('over')"
           ondragleave="this.classList.remove('over')"
           ondrop="billScanDrop(event)"
           onclick="document.getElementById('billscan-file').click()">
        <input type="file" id="billscan-file" accept=".pdf,.png,.jpg,.jpeg,.webp,image/*,application/pdf" hidden
               onchange="billScanPicked(this.files[0])">
        ${f ? `
          <div class="billscan-picked">
            <strong>${esc(f.name)}</strong>
            <span class="acc-muted">${accFileSize(f.size)}</span>
          </div>
          <p class="acc-muted">Click to choose a different file.</p>
        ` : `
          <div class="billscan-drop-icon">${I.file || '📄'}</div>
          <strong>Drop a bill here, or click to choose one</strong>
          <p class="acc-muted">PDF, PNG, JPG or WEBP · up to 12MB</p>
        `}
      </div>

      ${BillScan.error ? `<div class="acc-alert acc-alert-bad">${esc(BillScan.error)}</div>` : ''}

      <div class="acc-form-actions">
        <button class="btn-primary" ${!f || BillScan.busy ? 'disabled' : ''} onclick="billScanRead()">
          ${BillScan.busy ? 'Reading the document…' : 'Read the bill'}
        </button>
        <button class="btn-secondary" onclick="accNav('acc-bills')">Cancel</button>
      </div>
      ${BillScan.busy ? `<p class="acc-muted" style="margin-top:10px">This usually takes a few seconds. Larger scans take longer.</p>` : ''}
      <p class="acc-muted" style="margin-top:14px">Reading uses the AI provider connected under
        <strong>Settings → AI Connections</strong>. PDFs need an Anthropic or Google Gemini key;
        photos work with any of them.</p>
    </div>`;
}

function billScanDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.remove('over');
  const f = e.dataTransfer?.files?.[0];
  if (f) billScanPicked(f);
}

function billScanPicked(file) {
  if (!file) return;
  if (file.size > 12 * 1024 * 1024) {
    BillScan.error = 'That file is ' + accFileSize(file.size) + '. Bills up to 12MB can be read.';
    BillScan.file = null;
  } else {
    BillScan.file = file;
    BillScan.error = null;
  }
  render();
}

async function billScanRead() {
  if (!BillScan.file || BillScan.busy) return;
  BillScan.busy = true; BillScan.error = null;
  render();
  try {
    const fd = new FormData();
    fd.append('file', BillScan.file);
    const res = await API.uploadReq('/acc/bills/extract', fd);
    BillScan.draft = res.draft;
    BillScan.newVendorName = res.draft.vendor_id ? null : (res.draft.vendor_name || null);
  } catch (e) {
    BillScan.error = e.message;
  } finally {
    BillScan.busy = false;
    render();
  }
}

/* ---------------- step 2: review before saving ---------------- */

function billScanReview() {
  const d = BillScan.draft;
  const opts = AccState.boot?.options || {};
  const vendors = opts.vendors || [];
  const categories = (opts.categories || []).filter(c => c.type !== 'income');

  const confidence = { high: ['Read cleanly', 'ok'], medium: ['Read with some uncertainty', 'warn'], low: ['Hard to read — check every field', 'bad'] }[d.confidence] || ['', 'warn'];

  const warnings = (d.warnings || []).length ? `
    <div class="acc-alert acc-alert-warn">
      <strong>Worth checking</strong>
      <ul style="margin:6px 0 0 18px">${d.warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul>
    </div>` : '';

  const vendorRow = d.vendor_id ? `
    <div class="form-field" style="flex:2">
      <label class="form-label">Vendor</label>
      <select class="form-input" id="bs-vendor">
        ${vendors.map(v => `<option value="${v.id}" ${Number(v.id) === Number(d.vendor_id) ? 'selected' : ''}>${esc(v.name)}</option>`).join('')}
      </select>
      ${d.vendor_match ? `<div class="acc-muted" style="margin-top:4px">Matched “${esc(d.vendor_name || '')}” to ${esc(d.vendor_match.name)}.</div>` : ''}
    </div>`
    : `
    <div class="form-field" style="flex:2">
      <label class="form-label">Vendor</label>
      <select class="form-input" id="bs-vendor" onchange="billScanVendorChanged()">
        <option value="__new__" selected>Create “${esc(d.vendor_name || 'new vendor')}”</option>
        ${vendors.map(v => `<option value="${v.id}">${esc(v.name)}</option>`).join('')}
      </select>
      <div class="acc-muted" style="margin-top:4px">No existing vendor matched${d.vendor_name ? ' “' + esc(d.vendor_name) + '”' : ''}. It will be created when you save.</div>
    </div>`;

  return `
    ${warnings}
    <div class="acc-card">
      <div class="billscan-status billscan-${confidence[1]}">
        <span>${esc(confidence[0])}</span>
        <span class="acc-muted">from ${esc(d.staged_name || 'the uploaded file')} · the original will be attached to this bill</span>
      </div>

      <div class="form-row" style="gap:12px;margin-top:16px">
        ${vendorRow}
        <div class="form-field" style="flex:1">
          <label class="form-label">Bill number</label>
          <input class="form-input" id="bs-number" value="${esc(d.document_number || '')}" placeholder="Supplier's reference">
        </div>
      </div>

      <div class="form-row" style="gap:12px">
        <div class="form-field" style="flex:1">
          <label class="form-label">Bill date</label>
          <input class="form-input" id="bs-issued" type="date" value="${esc(d.issued_at || '')}">
        </div>
        <div class="form-field" style="flex:1">
          <label class="form-label">Due date</label>
          <input class="form-input" id="bs-due" type="date" value="${esc(d.due_at || '')}">
        </div>
        <div class="form-field" style="flex:1">
          <label class="form-label">Category</label>
          <select class="form-input" id="bs-category">
            <option value="">Uncategorised</option>
            ${categories.map(c => `<option value="${c.id}" ${Number(c.id) === Number(d.category_id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
          </select>
        </div>
      </div>

      <h3 class="acc-subhead" style="margin-top:20px">Line items</h3>
      <div class="acc-table-wrap">
        <table class="acc-table billscan-lines">
          <thead><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:130px">Unit price</th><th style="width:120px;text-align:right">Total</th><th style="width:40px"></th></tr></thead>
          <tbody id="bs-lines">${billScanLineRows()}</tbody>
        </table>
      </div>
      <button class="btn-secondary btn-sm" style="margin-top:10px" onclick="billScanAddLine()">+ Add line</button>

      <div class="billscan-totals">
        <div><span>Lines total</span><strong id="bs-lines-total">—</strong></div>
        ${d.tax_total !== null ? `<div><span>Tax on the document</span><strong>${accMoney(d.tax_total, d.currency)}</strong></div>` : ''}
        ${d.total !== null ? `<div class="billscan-total-stated"><span>Total on the document</span><strong>${accMoney(d.total, d.currency)}</strong></div>` : ''}
      </div>

      <div class="form-field" style="margin-top:16px">
        <label class="form-label">Notes</label>
        <textarea class="form-input" id="bs-notes" rows="2" placeholder="Payment terms, reference…">${esc(d.notes || '')}</textarea>
      </div>

      <div id="bs-error" class="acc-alert acc-alert-bad" style="display:none"></div>

      <div class="acc-form-actions">
        <button class="btn-primary" id="bs-save" onclick="billScanSave()">Save as draft bill</button>
        <button class="btn-secondary" onclick="billScanStartOver()">Use a different file</button>
        <button class="btn-secondary" onclick="accNav('acc-bills')">Cancel</button>
      </div>
      <p class="acc-muted" style="margin-top:10px">Saved as a draft, so nothing is posted to the ledger until you mark it received.</p>
    </div>`;
}

function billScanLineRows() {
  return (BillScan.draft.line_items || []).map((li, i) => `
    <tr>
      <td><input class="form-input" value="${esc(li.name)}" oninput="billScanSetLine(${i},'name',this.value)"></td>
      <td><input class="form-input" type="number" step="any" value="${esc(li.quantity)}" oninput="billScanSetLine(${i},'quantity',this.value)"></td>
      <td><input class="form-input" type="number" step="any" value="${esc(li.unit_price)}" oninput="billScanSetLine(${i},'unit_price',this.value)"></td>
      <td style="text-align:right" id="bs-line-total-${i}">${accMoney(li.quantity * li.unit_price, BillScan.draft.currency)}</td>
      <td><button class="acc-icon-btn" title="Remove line" onclick="billScanRemoveLine(${i})">×</button></td>
    </tr>`).join('');
}

function billScanSetLine(i, field, value) {
  const li = BillScan.draft.line_items[i];
  if (!li) return;
  li[field] = (field === 'name') ? value : (parseFloat(value) || 0);
  const cell = document.getElementById('bs-line-total-' + i);
  if (cell) cell.textContent = accMoney(li.quantity * li.unit_price, BillScan.draft.currency);
  billScanRecalc();
}

function billScanAddLine() {
  BillScan.draft.line_items.push({ name: '', quantity: 1, unit_price: 0, total: null });
  document.getElementById('bs-lines').innerHTML = billScanLineRows();
  billScanRecalc();
}

function billScanRemoveLine(i) {
  BillScan.draft.line_items.splice(i, 1);
  document.getElementById('bs-lines').innerHTML = billScanLineRows();
  billScanRecalc();
}

/** Keep the running total visible against the figure printed on the document. */
function billScanRecalc() {
  const sum = (BillScan.draft.line_items || []).reduce((t, li) => t + (Number(li.quantity) || 0) * (Number(li.unit_price) || 0), 0);
  const el = document.getElementById('bs-lines-total');
  if (el) el.textContent = accMoney(sum, BillScan.draft.currency);
}

function billScanVendorChanged() {
  const v = document.getElementById('bs-vendor')?.value;
  BillScan.newVendorName = (v === '__new__') ? (BillScan.draft.vendor_name || 'New vendor') : null;
}

function billScanStartOver() {
  BillScan.draft = null; BillScan.file = null; BillScan.error = null;
  render();
}

async function billScanSave() {
  const d = BillScan.draft;
  const err = document.getElementById('bs-error');
  const btn = document.getElementById('bs-save');
  const fail = (m) => { if (err) { err.textContent = m; err.style.display = 'block'; } else toast(m, 'error'); };
  if (err) err.style.display = 'none';

  const lines = (d.line_items || []).filter(li => (li.name || '').trim() !== '');
  if (!lines.length) return fail('Add at least one line item before saving.');

  const vendorSel = document.getElementById('bs-vendor')?.value || '';
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

  try {
    let vendorId = vendorSel;
    if (vendorSel === '__new__') {
      const v = await API.accCreateVendorFromDraft({
        name: d.vendor_name || 'New vendor',
        email: d.vendor_email || null,
        tax_number: d.vendor_tax_id || null,
        currency_code: d.currency || null,
      });
      vendorId = v.id;
    }

    const res = await API.accCreateDocument({
      type: 'bill',
      contact_id: Number(vendorId),
      order_number: (document.getElementById('bs-number')?.value || d.document_number || '').trim() || null,
      issued_at: document.getElementById('bs-issued')?.value || null,
      due_at: document.getElementById('bs-due')?.value || null,
      category_id: document.getElementById('bs-category')?.value || null,
      notes: document.getElementById('bs-notes')?.value || null,
      items: lines.map(li => ({ name: li.name, quantity: li.quantity, price: li.unit_price, tax_ids: [] })),
      // Carries the uploaded file onto the bill it became.
      staged_path: d.staged_path,
      staged_name: d.staged_name,
      staged_mime: d.staged_mime,
      staged_size: d.staged_size,
    });

    AccState.docs.bill = null;
    AccState.dashboard = null;
    AccState.contacts = null;
    BillScan.draft = null; BillScan.file = null;
    toast(res.attachment_id ? 'Bill created with the original attached' : 'Bill created', 'success');
    accGoDoc(res.id);
  } catch (e) {
    fail(e.message);
    if (btn) { btn.disabled = false; btn.textContent = 'Save as draft bill'; }
  }
}
