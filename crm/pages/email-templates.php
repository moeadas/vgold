<?php
/**
 * Victory Genomics CRM V2 — Email Templates
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$csrfToken = generateCSRFToken();
$db = Database::getInstance()->getConnection();

$templates = $db->query("SELECT t.*, u.full_name as creator FROM email_templates t LEFT JOIN users u ON t.created_by = u.user_id ORDER BY t.updated_at DESC")->fetchAll();

$pageTitle = 'Email Templates';
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Email Templates</h1>
        <p class="text-muted">Reusable email designs for your campaigns</p>
    </div>
    <button class="btn btn-primary" onclick="createTemplate()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Template
    </button>
</div>

<?php if (empty($templates)): ?>
    <div class="card">
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-text-tertiary)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
            <h3>No templates yet</h3>
            <p>Create your first email template to use across campaigns</p>
        </div>
    </div>
<?php else: ?>
    <div class="template-grid">
        <?php foreach ($templates as $t): ?>
            <div class="card template-card">
                <div class="card-body">
                    <div class="template-card-header">
                        <h3><?php echo htmlspecialchars($t['name']); ?></h3>
                        <span class="badge badge-default"><?php echo $t['category']; ?></span>
                    </div>
                    <?php if ($t['subject']): ?>
                        <p class="text-muted fs-13"><?php echo htmlspecialchars($t['subject']); ?></p>
                    <?php endif; ?>
                    <div class="template-meta">
                        <span>By <?php echo htmlspecialchars($t['creator'] ?? 'Unknown'); ?></span>
                        <span><?php echo timeAgo($t['updated_at']); ?></span>
                    </div>
                </div>
                <div class="card-footer template-actions">
                    <a href="email-builder.php?mode=template&id=<?php echo $t['template_id']; ?>" class="btn btn-sm btn-outline">Edit Design</a>
                    <button class="btn btn-sm btn-outline btn-danger-outline" onclick="deleteTemplate(<?php echo $t['template_id']; ?>)">Delete</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Create Template Modal -->
<div id="createTemplateModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>New Template</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form id="newTemplateForm">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Template Name *</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g., Monthly Newsletter">
                </div>
                <div class="form-group">
                    <label class="form-label">Default Subject</label>
                    <input type="text" name="subject" class="form-control" placeholder="e.g., Your monthly update from Victory Genomics">
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <option value="Marketing">Marketing</option>
                        <option value="Newsletter">Newsletter</option>
                        <option value="Announcement">Announcement</option>
                        <option value="Follow-up">Follow-up</option>
                        <option value="Welcome">Welcome</option>
                        <option value="Custom" selected>Custom</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create & Open Builder</button>
            </div>
        </form>
    </div>
</div>

<script>
function createTemplate() {
    document.getElementById('createTemplateModal').style.display = 'flex';
}
function closeModal() {
    document.getElementById('createTemplateModal').style.display = 'none';
}
document.getElementById('createTemplateModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('newTemplateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const data = { csrf_token: '<?php echo $csrfToken; ?>' };
    fd.forEach((v, k) => data[k] = v);

    fetch('/api/email.php?action=template_save', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    }).then(r => r.json()).then(d => {
        if (d.success) {
            window.location.href = 'email-builder.php?mode=template&id=' + d.data.template_id;
        } else {
            showNotification(d.message, 'error');
        }
    });
});

function deleteTemplate(id) {
    if (!confirm('Delete this template permanently?')) return;
    fetch('/api/email.php?action=template_delete', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ template_id: id, csrf_token: '<?php echo $csrfToken; ?>' })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else showNotification(d.message, 'error');
    });
}
</script>

<?php include '../includes/footer.php'; ?>
