<?php
/**
 * Victory Genomics CRM V2 — Campaign Report
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole('Sales Manager');

$db = Database::getInstance()->getConnection();
$campaignId = (int)($_GET['id'] ?? 0);

if (!$campaignId) { header('Location: email-campaigns.php'); exit; }

$stmt = $db->prepare("SELECT c.*, el.name as list_name, u.full_name as creator FROM email_campaigns c LEFT JOIN email_lists el ON c.list_id = el.list_id LEFT JOIN users u ON c.created_by = u.user_id WHERE c.campaign_id = ?");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) { $_SESSION['error'] = 'Campaign not found'; header('Location: email-campaigns.php'); exit; }

// Get send logs
$logs = $db->prepare("SELECT ecl.*, l.company_name, l.contact_person FROM email_campaign_log ecl LEFT JOIN leads l ON ecl.lead_id = l.lead_id WHERE ecl.campaign_id = ? ORDER BY ecl.sent_at DESC");
$logs->execute([$campaignId]);
$allLogs = $logs->fetchAll();

$totalRecipients = $campaign['total_recipients'];
$totalSent = $campaign['total_sent'];
$totalFailed = $campaign['total_failed'];
$totalOpened = $campaign['total_opened'];
$totalClicked = $campaign['total_clicked'];

$openRate = $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 1) : 0;
$clickRate = $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 1) : 0;
$deliveryRate = $totalRecipients > 0 ? round(($totalSent / $totalRecipients) * 100, 1) : 0;

$pageTitle = 'Campaign Report';
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="email-campaigns.php" class="btn btn-outline btn-sm back-btn-margin">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Campaigns
        </a>
        <h1><?php echo htmlspecialchars($campaign['name']); ?></h1>
        <p class="text-muted">Subject: <?php echo htmlspecialchars($campaign['subject']); ?> · List: <?php echo htmlspecialchars($campaign['list_name'] ?? '—'); ?></p>
    </div>
    <span class="badge badge-<?php echo strtolower($campaign['status']); ?>"><?php echo $campaign['status']; ?></span>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div>
            <div class="stat-label">DELIVERED</div>
            <div class="stat-value"><?php echo $totalSent; ?> <small class="text-muted">(<?php echo $deliveryRate; ?>%)</small></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </div>
        <div>
            <div class="stat-label">OPENED</div>
            <div class="stat-value"><?php echo $totalOpened; ?> <small class="text-muted">(<?php echo $openRate; ?>%)</small></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </div>
        <div>
            <div class="stat-label">CLICKED</div>
            <div class="stat-value"><?php echo $totalClicked; ?> <small class="text-muted">(<?php echo $clickRate; ?>%)</small></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-danger">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div>
            <div class="stat-label">FAILED</div>
            <div class="stat-value"><?php echo $totalFailed; ?></div>
        </div>
    </div>
</div>

<!-- Visual bars -->
<div class="card mt-2">
    <div class="card-body">
        <div class="report-bar-group">
            <div class="report-bar-label">
                <span>Open Rate</span>
                <strong><?php echo $openRate; ?>%</strong>
            </div>
            <div class="progress-bar"><div class="progress-fill bg-success" style="width:<?php echo $openRate; ?>%"></div></div>
        </div>
        <div class="report-bar-group mt-1">
            <div class="report-bar-label">
                <span>Click Rate</span>
                <strong><?php echo $clickRate; ?>%</strong>
            </div>
            <div class="progress-bar"><div class="progress-fill bg-info" style="width:<?php echo $clickRate; ?>%"></div></div>
        </div>
        <div class="report-bar-group mt-1">
            <div class="report-bar-label">
                <span>Delivery Rate</span>
                <strong><?php echo $deliveryRate; ?>%</strong>
            </div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $deliveryRate; ?>%"></div></div>
        </div>
    </div>
</div>

<!-- Recipient Log -->
<div class="card mt-2">
    <div class="card-header">
        <h2 class="card-title">Recipient Details</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Company</th>
                    <th>Status</th>
                    <th>Sent</th>
                    <th>Opened</th>
                    <th>Clicked</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allLogs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['email']); ?></td>
                        <td><?php echo htmlspecialchars($log['company_name'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo strtolower($log['status']); ?>"><?php echo $log['status']; ?></span></td>
                        <td><?php echo $log['sent_at'] ? formatDateTime($log['sent_at']) : '—'; ?></td>
                        <td><?php echo $log['opened_at'] ? formatDateTime($log['opened_at']) : '—'; ?></td>
                        <td><?php echo $log['clicked_at'] ? formatDateTime($log['clicked_at']) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($allLogs)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No send log entries yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
