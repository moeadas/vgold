<?php
/**
 * Victory Genomics CRM - Leads Management
 * List, search, filter, bulk actions, grid/list toggle
 * CSRF protected, Apple-style design, no FA icons
 * Region removed — Country used instead (dynamic filter)
 * Assign options hidden from Sales Reps
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();

$currentUser = getCurrentUser();
$csrf_token = generateCSRFToken();
$db = Database::getInstance()->getConnection();
$isSalesRep = !hasRole('Sales Manager');

$action = $_GET['action'] ?? 'list';

// Get filter options
$statuses = ['New Lead', 'Contacted', 'Interested', 'Not Interested', 'Schedule Call', 'Call Scheduled', 'Demo Scheduled', 'Proposal Sent', 'Negotiation', 'Won', 'Lost', 'On Hold'];
$leadTypes = ['Stable', 'Owner', 'Breeder', 'Trainer', 'Veterinarian', 'Consultant', 'Other'];
$priorities = ['Low', 'Medium', 'High', 'Urgent'];
$sources = ['Website', 'Facebook', 'Instagram', 'Google Ads', 'LinkedIn', 'Referral', 'Cold Outreach', 'Event', 'Import', 'Other'];

// Dynamic country list — only countries the user can see
if ($isSalesRep) {
    $countryStmt = $db->prepare("SELECT DISTINCT country FROM leads WHERE country IS NOT NULL AND country != '' AND (assigned_to = ? OR created_by = ?) ORDER BY country ASC");
    $countryStmt->execute([$currentUser['user_id'], $currentUser['user_id']]);
} else {
    $countryStmt = $db->query("SELECT DISTINCT country FROM leads WHERE country IS NOT NULL AND country != '' ORDER BY country ASC");
}
$countries = $countryStmt->fetchAll(PDO::FETCH_COLUMN);

// Get users for assignment dropdown (only for managers)
$users = hasRole('Sales Manager') ? getAllUsers() : [];

$pageTitle = 'Leads';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Leads Management</h1>
    <button type="button" class="btn btn-primary" onclick="openAddModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add New Lead
    </button>
</div>

<?php if (isset($_GET['follow_up']) && $_GET['follow_up'] == '1'): ?>
<div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        <strong>Follow Up Leads</strong> &mdash; Showing leads that have a "Follow-up" interaction logged.
    </div>
    <a href="/pages/leads.php" class="btn btn-sm btn-outline">Show All Leads</a>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card filter-card">
    <div class="card-body">
        <form id="filterForm" class="filter-form">
            <div class="form-group filter-group">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Company, contact, email..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            </div>
            <div class="form-group filter-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo ($_GET['status'] ?? '') === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group filter-group">
                <label class="form-label">Country</label>
                <select name="country" class="form-control">
                    <option value="">All Countries</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($_GET['country'] ?? '') === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$isSalesRep): ?>
            <div class="form-group filter-group">
                <label class="form-label">Assigned To</label>
                <select name="assigned_to" class="form-control">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>" <?php echo ($_GET['assigned_to'] ?? '') == $user['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Filter
            </button>
            <a href="/pages/leads.php" class="btn btn-outline">Clear</a>
        </form>
    </div>
</div>

<!-- Leads Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">All Leads</h2>
        <div class="card-header-actions">
            <div class="view-toggle">
                <button class="view-btn active" onclick="toggleView('list')" id="btn-list" title="List View">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button class="view-btn" onclick="toggleView('grid')" id="btn-grid" title="Grid View">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </button>
            </div>
            <?php if (hasRole('Sales Manager')): ?>
                <a href="/pages/export-leads.php" class="btn btn-sm btn-outline">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        
        <?php if (!$isSalesRep): ?>
        <!-- Bulk Actions Toolbar — managers only -->
        <div id="bulkActions" class="bulk-toolbar" style="display: none;">
            <div class="bulk-toolbar-left">
                <span class="bulk-count"><span id="selectedCount">0</span> selected</span>
                <div class="bulk-divider"></div>
                <select id="bulkAssignUser" class="form-control bulk-select">
                    <option value="">Assign to...</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm" onclick="bulkAssign()">Apply</button>
            </div>
            <div class="bulk-toolbar-right">
                <?php if (hasRole('Sales Manager')): ?>
                <button class="btn btn-danger btn-sm" onclick="bulkDelete()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    Delete
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="leadsListContainer" class="table-container">
            <table class="table" id="leadsTable">
                <thead>
                    <tr>
                        <?php if (!$isSalesRep): ?><th class="th-checkbox" style="width:40px;min-width:40px;"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th><?php endif; ?>
                        <th style="width:44px;min-width:44px;text-align:center;">#</th>
                        <th class="th-sortable th-resizable" data-sort="company_name">Company <span class="sort-icon"></span></th>
                        <th class="th-sortable th-resizable" data-sort="contact_person">Contact <span class="sort-icon"></span></th>
                        <th class="th-sortable th-resizable" data-sort="country">Country <span class="sort-icon"></span></th>
                        <th class="th-sortable th-resizable" data-sort="lead_status">Status <span class="sort-icon"></span></th>
                        <th class="th-sortable th-resizable" data-sort="priority">Priority <span class="sort-icon"></span></th>
                        <?php if (!$isSalesRep): ?><th class="th-sortable th-resizable" data-sort="assigned_name">Assigned To <span class="sort-icon"></span></th><?php endif; ?>
                        <th class="th-sortable th-resizable" data-sort="created_at">Date Created <span class="sort-icon"></span></th>
                        <th class="th-sortable th-resizable" data-sort="updated_at">Updated <span class="sort-icon"></span></th>
                        <th style="width:60px;min-width:60px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="leadsTableBody">
                    <tr>
                        <td colspan="<?php echo $isSalesRep ? '9' : '11'; ?>" class="text-center text-muted">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <?php if (!$isSalesRep): ?>
        <div id="gridSelectAllContainer" class="grid-select-all" style="display: none;">
            <input type="checkbox" id="gridSelectAll" onclick="toggleAll(this)">
            <label for="gridSelectAll">Select All Leads on Page</label>
        </div>
        <?php endif; ?>
        
        <div id="leadsGridContainer" class="leads-grid" style="display: none;"></div>
        <div id="pagination" class="pagination"></div>
    </div>
</div>

<!-- Add/Edit Lead Modal -->
<div id="leadModal" class="modal" style="display: none;">
    <div class="modal-backdrop" onclick="closeModal()"></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="modalTitle">Add New Lead</h3>
            <button type="button" class="btn-close" onclick="closeModal()">&times;</button>
        </div>
        <form id="leadForm" onsubmit="saveLead(event)">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="lead_id" id="lead_id">
            
            <div class="modal-body">
                <!-- Basic Info -->
                <h4 class="form-section-title">Basic Information</h4>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Lead Type</label>
                        <select name="lead_type" id="lead_type" class="form-control">
                            <?php foreach ($leadTypes as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" id="company_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Title/Position</label>
                        <input type="text" name="title_position" id="title_position" class="form-control">
                    </div>
                </div>

                <!-- Contact Details -->
                <h4 class="form-section-title">Contact Details</h4>
                <div class="grid grid-2">
                    <div class="form-group" id="fg_contact_person">
                        <label class="form-label">Contact Person <span class="required">*</span></label>
                        <input type="text" name="contact_person" id="contact_person" class="form-control">
                        <div class="field-error" id="err_contact_person"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <input type="text" name="country" id="country" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" id="phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile</label>
                        <input type="tel" name="mobile" id="mobile" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" name="city" id="city" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input type="url" name="website" id="website" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Number of Horses</label>
                        <input type="number" name="number_of_horses" id="number_of_horses" class="form-control" min="0">
                    </div>
                </div>

                <!-- Status -->
                <h4 class="form-section-title">Lead Status</h4>
                <div class="grid grid-<?php echo $isSalesRep ? '3' : '4'; ?>">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="lead_status" id="lead_status" class="form-control">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>"><?php echo $status; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" id="priority" class="form-control">
                            <?php foreach ($priorities as $priority): ?>
                                <option value="<?php echo $priority; ?>" <?php echo $priority === 'Medium' ? 'selected' : ''; ?>><?php echo $priority; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Source</label>
                        <select name="lead_source" id="lead_source" class="form-control">
                            <?php foreach ($sources as $source): ?>
                                <option value="<?php echo $source; ?>"><?php echo $source; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!$isSalesRep): ?>
                    <div class="form-group">
                        <label class="form-label">Assigned To</label>
                        <select name="assigned_to" id="assigned_to" class="form-control">
                            <option value="">Unassigned</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Lead</button>
            </div>
        </form>
    </div>
</div>

<script>
var isSalesRep = <?php echo $isSalesRep ? 'true' : 'false'; ?>;
let currentLeads = [];
let currentPage = 1;
let currentView = localStorage.getItem('leadsView') || 'list';
let currentSortBy = localStorage.getItem('leadsSortBy') || 'created_at';
let currentSortDir = localStorage.getItem('leadsSortDir') || 'DESC';

document.addEventListener('DOMContentLoaded', function() {
    toggleView(currentView, false);
    initSortHeaders();
    updateSortIndicators();
    initColumnResize();
    loadLeads();
});

function initSortHeaders() {
    var headers = document.querySelectorAll('.th-sortable');
    for (var i = 0; i < headers.length; i++) {
        headers[i].addEventListener('click', function() {
            var col = this.getAttribute('data-sort');
            if (currentSortBy === col) {
                currentSortDir = currentSortDir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                currentSortBy = col;
                // Default direction: DESC for dates, ASC for text
                currentSortDir = (col === 'updated_at' || col === 'created_at') ? 'DESC' : 'ASC';
            }
            localStorage.setItem('leadsSortBy', currentSortBy);
            localStorage.setItem('leadsSortDir', currentSortDir);
            updateSortIndicators();
            loadLeads(1);
        });
    }
}

function updateSortIndicators() {
    var headers = document.querySelectorAll('.th-sortable');
    for (var i = 0; i < headers.length; i++) {
        var col = headers[i].getAttribute('data-sort');
        var icon = headers[i].querySelector('.sort-icon');
        headers[i].classList.remove('th-sort-active');
        icon.className = 'sort-icon';
        if (col === currentSortBy) {
            headers[i].classList.add('th-sort-active');
            icon.classList.add(currentSortDir === 'ASC' ? 'sort-asc' : 'sort-desc');
        }
    }
}

function toggleView(view, render) {
    if (render === undefined) render = true;
    currentView = view;
    localStorage.setItem('leadsView', view);
    
    document.getElementById('btn-list').className = 'view-btn ' + (view === 'list' ? 'active' : '');
    document.getElementById('btn-grid').className = 'view-btn ' + (view === 'grid' ? 'active' : '');
    
    document.getElementById('leadsListContainer').style.display = view === 'list' ? 'block' : 'none';
    document.getElementById('leadsGridContainer').style.display = view === 'grid' ? 'grid' : 'none';
    
    var gridSelectAll = document.getElementById('gridSelectAllContainer');
    if (gridSelectAll) {
        gridSelectAll.style.display = view === 'grid' ? 'flex' : 'none';
    }
    
    if (render && currentLeads.length) {
        if (view === 'list') {
            document.getElementById('leadsGridContainer').innerHTML = '';
            renderLeadsList(currentLeads);
        } else {
            document.getElementById('leadsTableBody').innerHTML = '';
            renderLeadsGrid(currentLeads);
        }
        if (!isSalesRep) updateBulkState();
        var selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
        var gridAll = document.getElementById('gridSelectAll');
        if (gridAll) gridAll.checked = false;
    }
}

function loadLeads(page) {
    if (page === undefined) page = 1;
    currentPage = page;
    var params = new URLSearchParams(window.location.search);
    params.set('page', page);
    params.set('sort_by', currentSortBy);
    params.set('sort_dir', currentSortDir);
    
    var colSpan = isSalesRep ? 9 : 11;
    if (currentView === 'list') {
        document.getElementById('leadsTableBody').innerHTML = '<tr><td colspan="' + colSpan + '" class="text-center text-muted">Loading...</td></tr>';
    } else {
        document.getElementById('leadsGridContainer').innerHTML = '<div style="grid-column:1/-1;text-align:center;" class="text-muted">Loading...</div>';
    }
    
    fetch('/api/leads.php?action=list&' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                currentLeads = data.data.leads;
                if (currentView === 'list') {
                    renderLeadsList(currentLeads);
                } else {
                    renderLeadsGrid(currentLeads);
                }
                renderPagination(data.data.page, data.data.pages);
            }
        })
        .catch(function(err) {
            console.error('Error loading leads:', err);
            var errorMsg = 'Failed to load leads';
            var colSpan = isSalesRep ? 9 : 11;
            if (currentView === 'list') {
                document.getElementById('leadsTableBody').innerHTML = '<tr><td colspan="' + colSpan + '" class="text-center text-muted">' + errorMsg + '</td></tr>';
            } else {
                document.getElementById('leadsGridContainer').innerHTML = '<div style="grid-column:1/-1;text-align:center;">' + errorMsg + '</div>';
            }
        });
}

function renderLeadsList(leads) {
    var tbody = document.getElementById('leadsTableBody');
    var colSpan = isSalesRep ? 9 : 11;
    
    if (!leads.length) {
        tbody.innerHTML = '<tr><td colspan="' + colSpan + '" class="text-center text-muted">No leads found</td></tr>';
        return;
    }
    
    var startNum = ((currentPage - 1) * 25) + 1;
    tbody.innerHTML = leads.map(function(lead, idx) {
        var rowNum = startNum + idx;
        var row = '<tr class="clickable-row" onclick="if(!event.target.type||event.target.type!==\'checkbox\')window.location=\'/pages/lead-detail.php?id=' + lead.lead_id + '\'">';
        if (!isSalesRep) row += '<td onclick="event.stopPropagation()"><input type="checkbox" class="lead-checkbox" value="' + lead.lead_id + '" onchange="updateBulkState()"></td>';
        row += '<td style="text-align:center;color:var(--color-text-tertiary);font-size:13px;font-weight:500;">' + rowNum + '</td>' +
            '<td><strong>' + escapeHtml(lead.company_name || lead.contact_person || 'Unnamed') + '</strong><br><small class="text-muted">' + escapeHtml(lead.lead_type) + '</small></td>' +
            '<td>' + escapeHtml(lead.contact_person || '-') + '<br><small class="text-muted">' + escapeHtml(lead.email || '-') + '</small></td>' +
            '<td>' + escapeHtml(lead.country || '-') + '</td>' +
            '<td><span class="badge ' + getStatusClass(lead.lead_status) + '">' + escapeHtml(lead.lead_status) + '</span></td>' +
            '<td><span class="badge ' + getPriorityClass(lead.priority) + '">' + escapeHtml(lead.priority) + '</span></td>';
        if (!isSalesRep) row += '<td>' + escapeHtml(lead.assigned_name || 'Unassigned') + '</td>';
        row += '<td><small class="text-muted">' + formatDate(lead.created_at) + '</small></td>' +
            '<td><small class="text-muted">' + formatDate(lead.updated_at) + '</small></td>' +
            '<td onclick="event.stopPropagation()"><button class="btn btn-sm btn-outline" onclick="editLead(' + lead.lead_id + ')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button></td>' +
        '</tr>';
        return row;
    }).join('');
    
    var selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    if (!isSalesRep) updateBulkState();
}

function renderLeadsGrid(leads) {
    var container = document.getElementById('leadsGridContainer');
    
    if (!leads.length) {
        container.innerHTML = '<div style="grid-column:1/-1;text-align:center;" class="text-muted">No leads found</div>';
        return;
    }
    
    container.innerHTML = leads.map(function(lead) {
        return '<div class="lead-card">' +
            (!isSalesRep ? '<input type="checkbox" class="lead-checkbox card-checkbox" value="' + lead.lead_id + '" onchange="updateBulkState()">' : '') +
            '<div onclick="window.location=\'/pages/lead-detail.php?id=' + lead.lead_id + '\'" style="cursor:pointer;">' +
                '<div class="lead-card-header"><div>' +
                    '<h3 class="lead-card-title">' + escapeHtml(lead.company_name || lead.contact_person || 'Unnamed') + '</h3>' +
                    '<div class="lead-card-subtitle">' + escapeHtml(lead.country || '-') + '</div>' +
                '</div><span class="lead-tag purple">' + escapeHtml(lead.lead_type) + '</span></div>' +
                '<div class="lead-desc">' + escapeHtml(lead.notes ? (lead.notes.length > 100 ? lead.notes.substring(0, 100) + '...' : lead.notes) : 'No description available') + '</div>' +
                '<div class="lead-info-grid">' +
                    '<div class="lead-info-item"><span>' + escapeHtml(lead.phone || '-') + '</span></div>' +
                    '<div class="lead-info-item"><span>' + escapeHtml(lead.email || '-') + '</span></div>' +
                '</div>' +
                '<div class="lead-card-footer">' +
                    '<strong>' + escapeHtml(lead.contact_person || '-') + '</strong> ' +
                    '<span class="text-muted">(' + escapeHtml(lead.title_position || 'Contact') + ')</span>' +
                '</div>' +
            '</div></div>';
    }).join('');
    
    var gridAll = document.getElementById('gridSelectAll');
    if (gridAll) gridAll.checked = false;
    if (!isSalesRep) updateBulkState();
}

function renderPagination(current, total) {
    var div = document.getElementById('pagination');
    if (total <= 1) { div.innerHTML = ''; return; }
    
    var html = '';
    html += '<a href="#" class="' + (current === 1 ? 'disabled' : '') + '" onclick="if(' + current + '>1)loadLeads(' + (current - 1) + ');return false;">&laquo;</a>';
    
    if (total <= 7) {
        for (var i = 1; i <= total; i++) {
            html += '<a href="#" class="' + (i === current ? 'active' : '') + '" onclick="loadLeads(' + i + ');return false;">' + i + '</a>';
        }
    } else {
        html += '<a href="#" class="' + (1 === current ? 'active' : '') + '" onclick="loadLeads(1);return false;">1</a>';
        if (current > 3) html += '<span>...</span>';
        var start = Math.max(2, current - 1);
        var end = Math.min(total - 1, current + 1);
        for (var i = start; i <= end; i++) {
            html += '<a href="#" class="' + (i === current ? 'active' : '') + '" onclick="loadLeads(' + i + ');return false;">' + i + '</a>';
        }
        if (current < total - 2) html += '<span>...</span>';
        html += '<a href="#" class="' + (total === current ? 'active' : '') + '" onclick="loadLeads(' + total + ');return false;">' + total + '</a>';
    }
    
    html += '<a href="#" class="' + (current === total ? 'disabled' : '') + '" onclick="if(' + current + '<' + total + ')loadLeads(' + (current + 1) + ');return false;">&raquo;</a>';
    div.innerHTML = html;
}

function toggleAll(source) {
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = source.checked;
    }
    updateBulkState();
}

function updateBulkState() {
    var toolbar = document.getElementById('bulkActions');
    if (!toolbar) return;
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    var selected = 0;
    for (var i = 0; i < checkboxes.length; i++) {
        if (checkboxes[i].checked) selected++;
    }
    
    if (selected > 0) {
        toolbar.style.display = 'flex';
        document.getElementById('selectedCount').textContent = selected;
    } else {
        toolbar.style.display = 'none';
        var selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
    }
}

function getSelectedIds() {
    var checkboxes = document.getElementsByClassName('lead-checkbox');
    var ids = [];
    for (var i = 0; i < checkboxes.length; i++) {
        if (checkboxes[i].checked) ids.push(checkboxes[i].value);
    }
    return ids;
}

function bulkAssign() {
    var ids = getSelectedIds();
    var userId = document.getElementById('bulkAssignUser').value;
    if (ids.length === 0) return;
    if (!userId) { alert('Please select a user to assign to.'); return; }
    if (!confirm('Assign ' + ids.length + ' leads to selected user?')) return;
    
    fetch('/api/leads.php?action=bulk_assign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: '<?php echo $csrf_token; ?>', lead_ids: ids, assigned_to: userId })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showNotification(data.message || 'Leads assigned successfully', 'success');
            loadLeads(currentPage);
        } else {
            showNotification(data.message || 'Failed to assign leads', 'error');
        }
    })
    .catch(function() { showNotification('An error occurred', 'error'); });
}

function bulkDelete() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('Are you sure you want to PERMANENTLY delete ' + ids.length + ' leads? This cannot be undone.')) return;
    
    fetch('/api/leads.php?action=bulk_delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: '<?php echo $csrf_token; ?>', lead_ids: ids })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showNotification(data.message || 'Leads deleted successfully', 'success');
            loadLeads(currentPage);
        } else {
            showNotification(data.message || 'Failed to delete leads', 'error');
        }
    })
    .catch(function() { showNotification('An error occurred', 'error'); });
}

function getStatusClass(status) {
    var classes = {
        'New Lead': 'bg-blue-100 text-blue-800',
        'Contacted': 'bg-indigo-100 text-indigo-800',
        'Interested': 'bg-green-100 text-green-800',
        'Won': 'bg-green-500 text-white',
        'Lost': 'bg-gray-500 text-white'
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
}

function getPriorityClass(priority) {
    var classes = {
        'Low': 'bg-gray-100 text-gray-800',
        'Medium': 'bg-blue-100 text-blue-800',
        'High': 'bg-orange-100 text-orange-800',
        'Urgent': 'bg-red-500 text-white'
    };
    return classes[priority] || 'bg-gray-100 text-gray-800';
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New Lead';
    document.getElementById('leadForm').reset();
    document.getElementById('lead_id').value = '';
    clearFieldErrors();
    document.getElementById('leadModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('leadModal').style.display = 'none';
}

function editLead(id) {
    fetch('/api/leads.php?action=detail&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var lead = data.data.lead;
                document.getElementById('modalTitle').textContent = 'Edit Lead';
                document.getElementById('lead_id').value = lead.lead_id;
                Object.keys(lead).forEach(function(key) {
                    var field = document.getElementById(key);
                    if (field) field.value = lead[key] || '';
                });
                document.getElementById('leadModal').style.display = 'flex';
            }
        });
}

function clearFieldErrors() {
    var errors = document.querySelectorAll('#leadForm .field-error');
    for (var i = 0; i < errors.length; i++) {
        errors[i].textContent = '';
        errors[i].style.display = 'none';
    }
    var inputs = document.querySelectorAll('#leadForm .form-control.is-invalid');
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].classList.remove('is-invalid');
    }
}

function showFieldError(fieldName, message) {
    var errEl = document.getElementById('err_' + fieldName);
    var input = document.getElementById(fieldName);
    if (errEl) {
        errEl.textContent = message;
        errEl.style.display = 'block';
    }
    if (input) {
        input.classList.add('is-invalid');
        input.focus();
    }
}

// ─── Column Resize ──────────────────────────────────────────
function initColumnResize() {
    var table = document.getElementById('leadsTable');
    if (!table) return;

    var thead = table.querySelector('thead');
    var ths = thead.querySelectorAll('th.th-resizable');

    // Restore saved widths
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem('leadsColWidths')); } catch(e) {}

    // Set table to fixed layout so widths are respected
    table.style.tableLayout = 'fixed';

    if (saved && typeof saved === 'object') {
        ths.forEach(function(th) {
            var key = th.getAttribute('data-sort') || th.textContent.trim();
            if (saved[key]) th.style.width = saved[key] + 'px';
        });
    }

    ths.forEach(function(th) {
        // Create resize handle
        var handle = document.createElement('div');
        handle.className = 'col-resize-handle';
        th.appendChild(handle);
        th.style.position = 'relative';

        var startX, startW, thEl;

        handle.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            thEl = th;
            startX = e.pageX;
            startW = th.offsetWidth;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';

            function onMove(ev) {
                var diff = ev.pageX - startX;
                var newW = Math.max(50, startW + diff);
                thEl.style.width = newW + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                saveColumnWidths();
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    });
}

function saveColumnWidths() {
    var table = document.getElementById('leadsTable');
    if (!table) return;
    var ths = table.querySelectorAll('thead th.th-resizable');
    var widths = {};
    ths.forEach(function(th) {
        var key = th.getAttribute('data-sort') || th.textContent.trim();
        widths[key] = th.offsetWidth;
    });
    localStorage.setItem('leadsColWidths', JSON.stringify(widths));
}

function saveLead(e) {
    e.preventDefault();
    clearFieldErrors();

    var form = e.target;
    var formData = new FormData(form);
    var data = {};
    formData.forEach(function(value, key) { data[key] = value; });

    // Client-side validation: only contact_person is required
    var contactVal = (data.contact_person || '').trim();
    if (!contactVal) {
        showFieldError('contact_person', 'Cannot be empty');
        return;
    }

    var isEdit = !!data.lead_id;
    var url = '/api/leads.php?action=' + (isEdit ? 'update' : 'create');
    var method = isEdit ? 'PUT' : 'POST';
    
    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
        if (resp.success) {
            closeModal();
            loadLeads(currentPage);
            showNotification('Lead saved successfully', 'success');
        } else if (resp.field_errors) {
            // Show server-side field-level errors under the related inputs
            var keys = Object.keys(resp.field_errors);
            for (var i = 0; i < keys.length; i++) {
                showFieldError(keys[i], resp.field_errors[keys[i]]);
            }
        } else {
            // Check for session / access errors that need a page reload
            var msg = resp.message || 'Failed to save lead';
            if (msg.toLowerCase().indexOf('session') !== -1 || msg.toLowerCase().indexOf('expired') !== -1) {
                if (confirm(msg + '\n\nReload the page now?')) {
                    window.location.reload();
                    return;
                }
            }
            showNotification(msg, 'error');
        }
    })
    .catch(function() { showNotification('An error occurred', 'error'); });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
