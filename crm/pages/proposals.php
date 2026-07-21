<?php
/**
 * Victory Genomics CRM — Proposals List
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$pageTitle   = 'Proposals';
$currentPage = 'proposals';
$csrfToken   = generateCSRFToken();
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Proposals / Estimates</h1>
        <p class="text-muted">Create and manage client proposals</p>
    </div>
    <div class="header-actions">
        <a href="/pages/proposal-form.php" class="btn btn-primary btn-sm">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Proposal
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;padding:16px;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <input type="text" id="searchInput" class="form-control" placeholder="Search company, contact, estimate #..." style="max-width:300px;" oninput="debounceLoad()">
        <select id="statusFilter" class="form-control" style="max-width:180px;" onchange="loadProposals()">
            <option value="">All Statuses</option>
            <option value="Draft">Draft</option>
            <option value="Sent">Sent</option>
            <option value="Accepted">Accepted</option>
            <option value="Declined">Declined</option>
        </select>
    </div>
</div>

<!-- Proposals Table -->
<div class="card">
    <div class="table-container">
        <table class="table" id="proposalsTable">
            <thead>
                <tr>
                    <th style="width:80px;">#</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th style="width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody id="proposalsBody">
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div id="pagination" style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;">
        <span id="pageInfo" class="text-muted" style="font-size:13px;"></span>
        <div id="pageButtons"></div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
let currentPage = 1;
let debounceTimer;

function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { currentPage = 1; loadProposals(); }, 300);
}

function loadProposals(page) {
    if (page) currentPage = page;
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    const params = new URLSearchParams({ action: 'list', page: currentPage, limit: 20, search, status });

    fetch('/api/proposals.php?' + params)
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        const body = document.getElementById('proposalsBody');
        if (data.proposals.length === 0) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:40px;">No proposals found</td></tr>';
            document.getElementById('pageInfo').textContent = '';
            document.getElementById('pageButtons').innerHTML = '';
            return;
        }

        body.innerHTML = data.proposals.map(p => {
            const statusClasses = {
                'Draft': 'background:#f3f4f6;color:#4b5563;',
                'Sent': 'background:#dbeafe;color:#1d4ed8;',
                'Accepted': 'background:#dcfce7;color:#166534;',
                'Declined': 'background:#fee2e2;color:#991b1b;'
            };
            const statusStyle = statusClasses[p.status] || '';
            const date = p.proposal_date ? new Date(p.proposal_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
            const total = parseFloat(p.total_amount || 0).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

            return '<tr>' +
                '<td><strong>' + escHtml(String(p.estimate_number)) + '</strong></td>' +
                '<td><small class="text-muted">' + escHtml(date) + '</small></td>' +
                '<td>' + escHtml(p.customer_company || '-') + '</td>' +
                '<td>' + escHtml(p.contact_name || '-') + '</td>' +
                '<td><strong>' + total + '</strong></td>' +
                '<td><span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:500;' + statusStyle + '">' + escHtml(p.status) + '</span></td>' +
                '<td><small class="text-muted">' + escHtml(p.creator_name || '') + '</small></td>' +
                '<td>' +
                    '<a href="/pages/proposal-form.php?id=' + p.proposal_id + '" class="btn btn-outline btn-xs" title="Edit" style="margin-right:4px;">Edit</a>' +
                    '<button type="button" class="btn btn-outline btn-xs" onclick="previewPDF(' + p.proposal_id + ')" title="Preview">Preview</button> ' +
                    '<button type="button" class="btn btn-outline btn-xs btn-danger-outline" onclick="deleteProposal(' + p.proposal_id + ')" title="Delete" style="margin-left:4px;">Del</button>' +
                '</td>' +
            '</tr>';
        }).join('');

        document.getElementById('pageInfo').textContent = 'Page ' + data.page + ' of ' + data.pages + ' (' + data.total + ' proposals)';
        let btns = '';
        if (data.page > 1) btns += '<button class="btn btn-outline btn-xs" onclick="loadProposals(' + (data.page - 1) + ')">Prev</button> ';
        if (data.page < data.pages) btns += '<button class="btn btn-outline btn-xs" onclick="loadProposals(' + (data.page + 1) + ')">Next</button>';
        document.getElementById('pageButtons').innerHTML = btns;
    })
    .catch(() => {
        document.getElementById('proposalsBody').innerHTML = '<tr><td colspan="8" class="text-center text-muted">Error loading proposals</td></tr>';
    });
}

function previewPDF(id) {
    fetch('/api/proposals.php?action=pdf_html&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (!data.success) { showNotification(data.message || 'Error', 'error'); return; }
        const win = window.open('', '_blank', 'width=900,height=1100');
        win.document.write(data.html);
        win.document.close();
        // Add print/download buttons
        const bar = win.document.createElement('div');
        bar.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#333;color:#fff;padding:8px 16px;display:flex;gap:12px;z-index:9999;font-family:Arial;font-size:14px;';
        bar.innerHTML = '<button onclick="window.print()" style="background:#0071e3;color:#fff;border:0;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Print / Save as PDF</button>' +
            '<button onclick="document.querySelector(\'div[style]\').remove();window.print()" style="background:#16a34a;color:#fff;border:0;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Download PDF</button>' +
            '<span style="flex:1;"></span><span style="opacity:0.7;">Estimate #' + id + '</span>';
        win.document.body.insertBefore(bar, win.document.body.firstChild);
        // Add top padding to avoid overlap
        win.document.querySelector('.page').style.marginTop = '48px';
        // On print, hide the bar
        var style = win.document.createElement('style');
        style.textContent = '@media print { div[style*="position:fixed"] { display: none !important; } .page { margin-top: 0 !important; } }';
        win.document.head.appendChild(style);
    })
    .catch(() => showNotification('Network error', 'error'));
}

function deleteProposal(id) {
    if (!confirm('Are you sure you want to delete this proposal?')) return;
    fetch('/api/proposals.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ proposal_id: id, csrf_token: CSRF_TOKEN })
    })
    .then(r => r.json())
    .then(data => {
        showNotification(data.message, data.success ? 'success' : 'error');
        if (data.success) loadProposals();
    })
    .catch(() => showNotification('Network error', 'error'));
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

document.addEventListener('DOMContentLoaded', loadProposals);
</script>

<?php include '../includes/footer.php'; ?>
