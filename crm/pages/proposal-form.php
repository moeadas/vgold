<?php
/**
 * Victory Genomics CRM — Proposal Form (Create / Edit)
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$pageTitle   = 'Proposal';
$currentPage = 'proposals';
$csrfToken   = generateCSRFToken();
$proposalId  = intval($_GET['id'] ?? 0);
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="/pages/proposals.php" class="btn btn-outline btn-sm back-btn-margin">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Back
        </a>
        <h1 id="pageTitle"><?php echo $proposalId ? 'Edit Proposal' : 'New Proposal'; ?></h1>
        <p class="text-muted" id="pageSubtitle"><?php echo $proposalId ? 'Estimate #' . $proposalId : 'Fill in the details below'; ?></p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-outline btn-sm" onclick="previewProposal()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Preview
        </button>
        <button type="button" class="btn btn-outline btn-sm" id="downloadBtn" onclick="downloadPDF()" style="display:<?php echo $proposalId ? 'inline-flex' : 'none'; ?>;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download PDF
        </button>
        <button type="button" class="btn btn-primary btn-sm" onclick="saveProposal()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
            Save
        </button>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <!-- Left Column: Customer & Meta -->
    <div>
        <div class="card" style="padding:20px;">
            <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Proposal Details</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Estimate #</label>
                    <input type="text" id="estimateNumber" class="form-control" readonly style="background:#f5f5f7;">
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" id="proposalDate" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select id="proposalStatus" class="form-control">
                        <option value="Draft">Draft</option>
                        <option value="Sent">Sent</option>
                        <option value="Accepted">Accepted</option>
                        <option value="Declined">Declined</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card" style="padding:20px;margin-top:16px;">
            <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Customer Information</h3>
            <div class="form-group">
                <label class="form-label">Company Name</label>
                <input type="text" id="customerCompany" class="form-control" placeholder="e.g., Doha Stud">
            </div>
            <div class="form-group">
                <label class="form-label">Contact Name</label>
                <input type="text" id="contactName" class="form-control" placeholder="e.g., Abdulrahman Al-Nasser">
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea id="customerAddress" class="form-control" rows="3" placeholder="City, Country"></textarea>
            </div>
        </div>

        <div class="card" style="padding:20px;margin-top:16px;">
            <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Notes & Signature</h3>
            <div class="form-group">
                <label class="form-label">Notes / Terms</label>
                <textarea id="proposalNotes" class="form-control" rows="4" placeholder="e.g., Quotation is provided for one (1) Arabian horse..."></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Accepted By</label>
                    <input type="text" id="acceptedBy" class="form-control" placeholder="Signature name">
                </div>
                <div class="form-group">
                    <label class="form-label">Accepted Date</label>
                    <input type="date" id="acceptedDate" class="form-control">
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Line Items -->
    <div>
        <div class="card" style="padding:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="margin:0;font-size:15px;font-weight:600;">Line Items</h3>
                <button type="button" class="btn btn-outline btn-sm" onclick="addLineItem()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Item
                </button>
            </div>
            <div id="lineItemsContainer"></div>
            <div style="display:flex;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:2px solid #e8e8ed;">
                <div style="text-align:right;">
                    <span class="text-muted" style="font-size:13px;">TOTAL</span>
                    <div style="font-size:22px;font-weight:700;" id="totalDisplay">USD 0.00</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
const PROPOSAL_ID = <?php echo $proposalId; ?>;
let lineItems = [];

// ── Initialize ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    if (PROPOSAL_ID) {
        loadProposal();
    } else {
        // New proposal: get next number, set today's date
        document.getElementById('proposalDate').value = new Date().toISOString().split('T')[0];
        fetch('/api/proposals.php?action=next_number')
            .then(r => r.json())
            .then(data => {
                if (data.success) document.getElementById('estimateNumber').value = data.next_number;
            });
        addLineItem(); // Start with one empty line
    }
});

function loadProposal() {
    fetch('/api/proposals.php?action=get&id=' + PROPOSAL_ID)
    .then(r => r.json())
    .then(data => {
        if (!data.success) { showNotification(data.message, 'error'); return; }
        const p = data.proposal;
        document.getElementById('estimateNumber').value = p.estimate_number;
        document.getElementById('proposalDate').value = p.proposal_date || '';
        document.getElementById('proposalStatus').value = p.status || 'Draft';
        document.getElementById('customerCompany').value = p.customer_company || '';
        document.getElementById('contactName').value = p.contact_name || '';
        document.getElementById('customerAddress').value = p.customer_address || '';
        document.getElementById('proposalNotes').value = p.notes || '';
        document.getElementById('acceptedBy').value = p.accepted_by || '';
        document.getElementById('acceptedDate').value = p.accepted_date || '';
        document.getElementById('pageSubtitle').textContent = 'Estimate #' + p.estimate_number;

        lineItems = [];
        try {
            const items = typeof p.line_items === 'string' ? JSON.parse(p.line_items) : p.line_items;
            if (Array.isArray(items) && items.length > 0) {
                lineItems = items;
            } else {
                lineItems = [{ service: '', description: '', qty: 1, rate: 0 }];
            }
        } catch(e) {
            lineItems = [{ service: '', description: '', qty: 1, rate: 0 }];
        }
        renderLineItems();
    });
}

// ── Line Items ───────────────────────────────────────────────
function addLineItem() {
    lineItems.push({ service: '', description: '', qty: 1, rate: 0 });
    renderLineItems();
}

function removeLineItem(idx) {
    if (lineItems.length <= 1) return;
    lineItems.splice(idx, 1);
    renderLineItems();
}

function updateLineItem(idx, field, value) {
    lineItems[idx][field] = field === 'qty' || field === 'rate' ? parseFloat(value) || 0 : value;
    updateTotals();
}

function updateTotals() {
    let total = 0;
    lineItems.forEach((item, i) => {
        const amount = (item.qty || 0) * (item.rate || 0);
        item.amount = Math.round(amount * 100) / 100;
        total += item.amount;
        const amtEl = document.getElementById('lineAmt_' + i);
        if (amtEl) amtEl.textContent = item.amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    });
    document.getElementById('totalDisplay').textContent = 'USD ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderLineItems() {
    const container = document.getElementById('lineItemsContainer');
    container.innerHTML = lineItems.map((item, i) => {
        const amt = ((item.qty || 0) * (item.rate || 0)).toFixed(2);
        return '<div class="line-item-row" style="border:1px solid #e8e8ed;border-radius:8px;padding:14px;margin-bottom:10px;background:#fafbfc;">' +
            '<div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:10px;">' +
                '<div class="form-group" style="margin:0;"><label class="form-label" style="font-size:11px;">Service</label><input type="text" class="form-control" value="' + escAttr(item.service) + '" onchange="updateLineItem(' + i + ',\'service\',this.value)" placeholder="e.g., Arabian Product"></div>' +
                '<div class="form-group" style="margin:0;"><label class="form-label" style="font-size:11px;">Description</label><textarea class="form-control" rows="2" onchange="updateLineItem(' + i + ',\'description\',this.value)" placeholder="Service description...">' + escHtml(item.description) + '</textarea></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;">' +
                '<div class="form-group" style="margin:0;"><label class="form-label" style="font-size:11px;">Qty</label><input type="number" class="form-control" value="' + (item.qty || 1) + '" min="1" step="1" oninput="updateLineItem(' + i + ',\'qty\',this.value)"></div>' +
                '<div class="form-group" style="margin:0;"><label class="form-label" style="font-size:11px;">Rate (USD)</label><input type="number" class="form-control" value="' + (item.rate || 0) + '" min="0" step="0.01" oninput="updateLineItem(' + i + ',\'rate\',this.value)"></div>' +
                '<div class="form-group" style="margin:0;"><label class="form-label" style="font-size:11px;">Amount</label><div class="form-control" style="background:#f0f0f5;font-weight:600;" id="lineAmt_' + i + '">' + parseFloat(amt).toLocaleString('en-US', { minimumFractionDigits: 2 }) + '</div></div>' +
                '<button type="button" class="btn btn-outline btn-xs btn-danger-outline" onclick="removeLineItem(' + i + ')" title="Remove" style="margin-bottom:2px;' + (lineItems.length <= 1 ? 'visibility:hidden;' : '') + '">&#10005;</button>' +
            '</div>' +
        '</div>';
    }).join('');
    updateTotals();
}

// ── Save ─────────────────────────────────────────────────────
function saveProposal() {
    const payload = {
        csrf_token: CSRF_TOKEN,
        proposal_id: PROPOSAL_ID,
        proposal_date: document.getElementById('proposalDate').value,
        customer_company: document.getElementById('customerCompany').value,
        contact_name: document.getElementById('contactName').value,
        customer_address: document.getElementById('customerAddress').value,
        line_items: lineItems,
        notes: document.getElementById('proposalNotes').value,
        accepted_by: document.getElementById('acceptedBy').value,
        accepted_date: document.getElementById('acceptedDate').value,
        status: document.getElementById('proposalStatus').value,
    };

    fetch('/api/proposals.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        showNotification(data.message, data.success ? 'success' : 'error');
        if (data.success && !PROPOSAL_ID) {
            // Redirect to edit mode
            window.location.href = '/pages/proposal-form.php?id=' + data.proposal_id;
        }
    })
    .catch(() => showNotification('Network error', 'error'));
}

// ── Preview ──────────────────────────────────────────────────
function previewProposal() {
    if (PROPOSAL_ID) {
        // Saved proposal: load from server
        fetch('/api/proposals.php?action=pdf_html&id=' + PROPOSAL_ID)
        .then(r => r.json())
        .then(data => {
            if (data.success) openPreviewWindow(data.html);
            else showNotification('Save the proposal first to preview it.', 'warning');
        });
    } else {
        showNotification('Please save the proposal first to preview it.', 'warning');
    }
}

function downloadPDF() {
    if (!PROPOSAL_ID) { showNotification('Save first', 'warning'); return; }
    fetch('/api/proposals.php?action=pdf_html&id=' + PROPOSAL_ID)
    .then(r => r.json())
    .then(data => {
        if (!data.success) { showNotification(data.message, 'error'); return; }
        const win = window.open('', '_blank');
        win.document.write(data.html);
        win.document.close();
        setTimeout(() => { win.print(); }, 500);
    });
}

function openPreviewWindow(html) {
    const win = window.open('', '_blank', 'width=900,height=1100');
    win.document.write(html);
    win.document.close();
    var bar = win.document.createElement('div');
    bar.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#333;color:#fff;padding:8px 16px;display:flex;gap:12px;z-index:9999;font-family:Arial;font-size:14px;align-items:center;';
    bar.innerHTML = '<button onclick="window.print()" style="background:#0071e3;color:#fff;border:0;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Print / Save as PDF</button>' +
        '<span style="flex:1;"></span><span style="opacity:0.7;">Proposal Preview</span>';
    win.document.body.insertBefore(bar, win.document.body.firstChild);
    win.document.querySelector('.page').style.marginTop = '48px';
    var style = win.document.createElement('style');
    style.textContent = '@media print { div[style*="position:fixed"] { display:none!important; } .page { margin-top:0!important; } }';
    win.document.head.appendChild(style);
}

function escHtml(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return (s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php include '../includes/footer.php'; ?>
