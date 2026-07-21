<?php
/**
 * Victory Genomics CRM V2 — Email Lists (Audiences)
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$csrfToken = generateCSRFToken();
$db = Database::getInstance()->getConnection();

$lists = $db->query("SELECT el.*, u.full_name as creator, 
    (SELECT COUNT(*) FROM email_list_members WHERE list_id = el.list_id AND status = 'Active') as active_count,
    (SELECT COUNT(*) FROM email_list_members WHERE list_id = el.list_id AND status = 'Unsubscribed') as unsub_count
    FROM email_lists el 
    LEFT JOIN users u ON el.created_by = u.user_id 
    ORDER BY el.updated_at DESC")->fetchAll();

$totalMembers = array_sum(array_column($lists, 'active_count'));

$pageTitle = 'Email Lists';
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Email Lists</h1>
        <p class="text-muted">Manage audience segments for your campaigns</p>
    </div>
    <button class="btn btn-primary" onclick="showCreateModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New List
    </button>
</div>

<div class="stats-grid mb-2">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div>
            <div class="stat-label">TOTAL LISTS</div>
            <div class="stat-value"><?php echo count($lists); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>
        </div>
        <div>
            <div class="stat-label">TOTAL SUBSCRIBERS</div>
            <div class="stat-value"><?php echo number_format($totalMembers); ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>List Name</th>
                    <th>Active Members</th>
                    <th>Unsubscribed</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lists)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No lists yet. Create your first audience list!</td></tr>
                <?php else: foreach ($lists as $l): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($l['name']); ?></strong>
                            <?php if ($l['description']): ?>
                                <div class="text-muted fs-12"><?php echo htmlspecialchars(truncate($l['description'], 80)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-success"><?php echo number_format($l['active_count']); ?></span></td>
                        <td><?php echo $l['unsub_count']; ?></td>
                        <td><?php echo timeAgo($l['created_at']); ?></td>
                        <td>
                            <div class="action-btns">
                                <button class="btn btn-sm btn-outline" onclick="showPopulateModal(<?php echo $l['list_id']; ?>, '<?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?>')">Add Leads</button>
                                <button class="btn btn-sm btn-outline btn-danger-outline" onclick="deleteList(<?php echo $l['list_id']; ?>)">Delete</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create List Modal -->
<div id="createListModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>New Email List</h2>
            <button type="button" class="modal-close" onclick="hideModal('createListModal')">&times;</button>
        </div>
        <form id="newListForm">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">List Name *</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g., Europe Interested Leads">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Optional description..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideModal('createListModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create List</button>
            </div>
        </form>
    </div>
</div>

<!-- Populate List Modal -->
<div id="populateModal" class="modal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>Add Leads to: <span id="populateListName"></span></h2>
            <button type="button" class="modal-close" onclick="hideModal('populateModal')">&times;</button>
        </div>
        <form id="populateForm">
            <input type="hidden" name="list_id" id="populateListId">
            <div class="modal-body">
                <p class="text-muted">Filter leads to add to this list. Only leads with email addresses are included. Unsubscribed contacts are automatically excluded.</p>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Lead Status</label>
                        <select name="status" class="form-control">
                            <option value="">All statuses</option>
                            <option value="New Lead">New Lead</option>
                            <option value="Contacted">Contacted</option>
                            <option value="Interested">Interested</option>
                            <option value="Won">Won</option>
                            <option value="On Hold">On Hold</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <input type="text" name="country" class="form-control" placeholder="e.g., USA, UK, UAE">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Lead Type</label>
                        <select name="lead_type" class="form-control">
                            <option value="">All types</option>
                            <option value="Stable">Stable</option>
                            <option value="Owner">Owner</option>
                            <option value="Breeder">Breeder</option>
                            <option value="Trainer">Trainer</option>
                            <option value="Veterinarian">Veterinarian</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="">All priorities</option>
                            <option value="Urgent">Urgent</option>
                            <option value="High">High</option>
                            <option value="Medium">Medium</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Country (contains)</label>
                    <input type="text" name="country" class="form-control" placeholder="e.g., United States">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideModal('populateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Matching Leads</button>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF = '<?php echo $csrfToken; ?>';

function showCreateModal() { document.getElementById('createListModal').style.display = 'flex'; }
function showPopulateModal(id, name) {
    document.getElementById('populateListId').value = id;
    document.getElementById('populateListName').textContent = name;
    document.getElementById('populateModal').style.display = 'flex';
}
function hideModal(id) { document.getElementById(id).style.display = 'none'; }

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});

document.getElementById('newListForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const data = { csrf_token: CSRF };
    fd.forEach((v, k) => data[k] = v);

    fetch('/api/email.php?action=list_save', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else showNotification(d.message, 'error');
    });
});

document.getElementById('populateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const listId = fd.get('list_id');
    const filters = {};
    fd.forEach((v, k) => { if (k !== 'list_id' && v) filters[k] = v; });

    fetch('/api/email.php?action=list_populate', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ list_id: parseInt(listId), filters: filters, csrf_token: CSRF })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            showNotification(d.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(d.message, 'error');
        }
    });
});

function deleteList(id) {
    if (!confirm('Delete this list and all its members?')) return;
    fetch('/api/email.php?action=list_delete', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ list_id: id, csrf_token: CSRF })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else showNotification(d.message, 'error');
    });
}
</script>

<?php include '../includes/footer.php'; ?>
