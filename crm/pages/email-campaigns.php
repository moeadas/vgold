<?php
/**
 * Victory Genomics CRM V2 — Email Campaigns
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$csrfToken = generateCSRFToken();
$db = Database::getInstance()->getConnection();

// Get campaigns
$campaigns = $db->query("SELECT c.*, u.full_name as creator, el.name as list_name 
    FROM email_campaigns c 
    LEFT JOIN users u ON c.created_by = u.user_id 
    LEFT JOIN email_lists el ON c.list_id = el.list_id 
    ORDER BY c.updated_at DESC")->fetchAll();

// Stats
$totalCampaigns = count($campaigns);
$sent = count(array_filter($campaigns, fn($c) => $c['status'] === 'Sent'));
$drafts = count(array_filter($campaigns, fn($c) => $c['status'] === 'Draft'));
$totalSent = array_sum(array_column($campaigns, 'total_sent'));

$pageTitle = 'Email Campaigns';
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Email Campaigns</h1>
        <p class="text-muted">Create and manage email marketing campaigns</p>
    </div>
    <a href="email-campaign-form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Campaign
    </a>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div>
            <div class="stat-label">TOTAL CAMPAIGNS</div>
            <div class="stat-value"><?php echo $totalCampaigns; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div>
            <div class="stat-label">SENT</div>
            <div class="stat-value"><?php echo $sent; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div>
            <div class="stat-label">DRAFTS</div>
            <div class="stat-value"><?php echo $drafts; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div>
            <div class="stat-label">EMAILS SENT</div>
            <div class="stat-value"><?php echo number_format($totalSent); ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">All Campaigns</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>List</th>
                    <th>Sent</th>
                    <th>Opened</th>
                    <th>Clicked</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No campaigns yet. Create your first campaign!</td></tr>
                <?php else: foreach ($campaigns as $c): 
                    $openRate = $c['total_sent'] > 0 ? round(($c['total_opened'] / $c['total_sent']) * 100, 1) : 0;
                    $clickRate = $c['total_sent'] > 0 ? round(($c['total_clicked'] / $c['total_sent']) * 100, 1) : 0;
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                            <div class="text-muted fs-12"><?php echo htmlspecialchars($c['subject']); ?></div>
                        </td>
                        <td><span class="badge badge-<?php echo strtolower($c['status']); ?>"><?php echo $c['status']; ?></span></td>
                        <td><?php echo htmlspecialchars($c['list_name'] ?? '—'); ?></td>
                        <td><?php echo number_format($c['total_sent']); ?></td>
                        <td><?php echo $c['total_opened']; ?> <span class="text-muted">(<?php echo $openRate; ?>%)</span></td>
                        <td><?php echo $c['total_clicked']; ?> <span class="text-muted">(<?php echo $clickRate; ?>%)</span></td>
                        <td><?php echo timeAgo($c['created_at']); ?></td>
                        <td>
                            <div class="action-btns">
                                <?php if ($c['status'] === 'Draft'): ?>
                                    <a href="email-campaign-form.php?id=<?php echo $c['campaign_id']; ?>" class="btn btn-sm btn-outline" title="Edit">Edit</a>
                                <?php endif; ?>
                                <?php if (in_array($c['status'], ['Sent', 'Sending'])): ?>
                                    <a href="email-campaign-report.php?id=<?php echo $c['campaign_id']; ?>" class="btn btn-sm btn-outline" title="Report">Report</a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline" onclick="duplicateCampaign(<?php echo $c['campaign_id']; ?>)" title="Duplicate">Copy</button>
                                <?php if ($c['status'] === 'Draft'): ?>
                                    <button class="btn btn-sm btn-outline btn-danger-outline" onclick="deleteCampaign(<?php echo $c['campaign_id']; ?>)" title="Delete">Del</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function duplicateCampaign(id) {
    if (!confirm('Duplicate this campaign?')) return;
    fetch('/api/email.php?action=campaign_duplicate', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ campaign_id: id, csrf_token: '<?php echo $csrfToken; ?>' })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else showNotification(d.message, 'error');
    });
}
function deleteCampaign(id) {
    if (!confirm('Delete this draft campaign?')) return;
    fetch('/api/email.php?action=campaign_delete', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ campaign_id: id, csrf_token: '<?php echo $csrfToken; ?>' })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else showNotification(d.message, 'error');
    });
}
</script>

<?php include '../includes/footer.php'; ?>
