<?php
/**
 * Victory Genomics CRM - Dashboard
 * Role-aware: Sales Reps see only their data
 * Apple-style clean design, no inline styles, SVG icons
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

startSecureSession();
requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();
$isSalesRep = !hasRole('Sales Manager'); // true for Sales Rep and Viewer roles

// Get statistics
$stats = [];

if ($isSalesRep) {
    // Sales Rep: only their assigned leads
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM leads WHERE assigned_to = ?");
    $stmt->execute([$currentUser['user_id']]);
    $stats['total_leads'] = $stmt->fetch()['total'];
} else {
    // Admin / Sales Manager: all leads
    $stmt = $db->query("SELECT COUNT(*) as total FROM leads");
    $stats['total_leads'] = $stmt->fetch()['total'];
}

// Leads by status (scoped for Sales Rep)
if ($isSalesRep) {
    $stmt = $db->prepare("SELECT lead_status, COUNT(*) as count FROM leads WHERE assigned_to = ? GROUP BY lead_status");
    $stmt->execute([$currentUser['user_id']]);
} else {
    $stmt = $db->query("SELECT lead_status, COUNT(*) as count FROM leads GROUP BY lead_status");
}
$stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Leads contacted/updated today (scoped for Sales Rep)
// Use both interaction_date and created_at to catch all logged contacts
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');
if ($isSalesRep) {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT i.lead_id) as cnt 
        FROM interactions i 
        INNER JOIN leads l ON i.lead_id = l.lead_id
        WHERE l.assigned_to = ?
          AND (i.interaction_date BETWEEN ? AND ?
               OR i.created_at BETWEEN ? AND ?)
    ");
    $stmt->execute([$currentUser['user_id'], $todayStart, $todayEnd, $todayStart, $todayEnd]);
} else {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT lead_id) as cnt 
        FROM interactions 
        WHERE interaction_date BETWEEN ? AND ?
           OR created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$todayStart, $todayEnd, $todayStart, $todayEnd]);
}
$stats['contacted_today'] = $stmt->fetch()['cnt'];

// Follow-up count: leads that have at least one 'Follow-up' interaction
if ($isSalesRep) {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT i.lead_id) as cnt
        FROM interactions i
        INNER JOIN leads l ON i.lead_id = l.lead_id
        WHERE l.assigned_to = ?
          AND i.interaction_type = 'Follow-up'
    ");
    $stmt->execute([$currentUser['user_id']]);
} else {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT lead_id) as cnt
        FROM interactions
        WHERE interaction_type = 'Follow-up'
    ");
    $stmt->execute();
}
$stats['follow_ups'] = $stmt->fetch()['cnt'];

// Recent leads — sorted by most recent interaction (scoped for Sales Rep)
if ($isSalesRep) {
    $stmt = $db->prepare("
        SELECT l.*, u.full_name as assigned_name, c.full_name as created_name,
               MAX(i.interaction_date) as last_interaction_date
        FROM leads l
        LEFT JOIN users u ON l.assigned_to = u.user_id
        LEFT JOIN users c ON l.created_by = c.user_id
        LEFT JOIN interactions i ON l.lead_id = i.lead_id
        WHERE l.assigned_to = ?
        GROUP BY l.lead_id
        ORDER BY last_interaction_date DESC, l.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$currentUser['user_id']]);
} else {
    $stmt = $db->prepare("
        SELECT l.*, u.full_name as assigned_name, c.full_name as created_name,
               MAX(i.interaction_date) as last_interaction_date
        FROM leads l
        LEFT JOIN users u ON l.assigned_to = u.user_id
        LEFT JOIN users c ON l.created_by = c.user_id
        LEFT JOIN interactions i ON l.lead_id = i.lead_id
        GROUP BY l.lead_id
        ORDER BY last_interaction_date DESC, l.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute();
}
$recent_leads = $stmt->fetchAll();

// Recent activities (scoped for Sales Rep)
if ($isSalesRep) {
    $stmt = $db->prepare("
        SELECT al.*, u.full_name as user_name
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
    $stmt->execute([$currentUser['user_id']]);
} else {
    $stmt = $db->prepare("
        SELECT al.*, u.full_name as user_name
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
    $stmt->execute();
}
$recent_activities = $stmt->fetchAll();

// Email marketing stats — only for Admin/Sales Manager
$emailStats = ['campaigns' => 0, 'sent' => 0, 'lists' => 0, 'subscribers' => 0];
if (hasRole('Sales Manager')) {
    try {
        $emailStats['campaigns'] = $db->query("SELECT COUNT(*) FROM email_campaigns")->fetchColumn();
        $emailStats['sent'] = $db->query("SELECT COALESCE(SUM(total_sent),0) FROM email_campaigns WHERE status = 'Sent'")->fetchColumn();
        $emailStats['lists'] = $db->query("SELECT COUNT(*) FROM email_lists")->fetchColumn();
        $emailStats['subscribers'] = $db->query("SELECT COUNT(*) FROM email_list_members WHERE status = 'Active'")->fetchColumn();
    } catch (Exception $e) {
        // Email tables may not exist yet
    }
}

$pageTitle = 'Dashboard';
include 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <a href="/pages/leads.php?action=add" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add New Lead
    </a>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon bg-gradient-primary">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label"><?php echo $isSalesRep ? 'My Leads' : 'Total Leads'; ?></div>
            <div class="stat-value"><?php echo number_format($stats['total_leads']); ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-gradient-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Won</div>
            <div class="stat-value"><?php echo number_format($stats['by_status']['Won'] ?? 0); ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-gradient-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Contacted Today</div>
            <div class="stat-value"><?php echo number_format($stats['contacted_today']); ?></div>
        </div>
    </div>

    <div class="stat-card clickable-card" onclick="window.location='/pages/leads.php?follow_up=1'" style="cursor:pointer;">
        <div class="stat-icon bg-gradient-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Follow Up</div>
            <div class="stat-value"><?php echo number_format($stats['follow_ups']); ?></div>
        </div>
    </div>
</div>

<?php if (hasRole('Sales Manager')): ?>
<!-- Email Marketing Stats — Admin / Sales Manager only -->
<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Email Campaigns</div>
            <div class="stat-value"><?php echo number_format($emailStats['campaigns']); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Emails Sent</div>
            <div class="stat-value"><?php echo number_format($emailStats['sent']); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Email Audiences</div>
            <div class="stat-value"><?php echo number_format($emailStats['lists']); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Subscribers</div>
            <div class="stat-value"><?php echo number_format($emailStats['subscribers']); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Content Grid -->
<div class="dashboard-grid">
    <!-- Recent Leads -->
    <div class="card dashboard-main">
        <div class="card-header">
            <h2 class="card-title">Recent Leads</h2>
            <a href="/pages/leads.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Country</th>
                            <th>Last Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_leads)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No leads found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_leads as $lead): ?>
                                <tr class="clickable-row" onclick="window.location='/pages/lead-detail.php?id=<?php echo $lead['lead_id']; ?>'">
                                    <td>
                                        <strong><?php echo htmlspecialchars($lead['company_name'] ?: ($lead['contact_person'] ?: 'Unnamed Lead')); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($lead['lead_type']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($lead['contact_person'] ?: '-'); ?>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($lead['email'] ?: '-'); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadgeClass($lead['lead_status']); ?>">
                                            <?php echo htmlspecialchars($lead['lead_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($lead['country'] ?: '-'); ?></td>
                                    <td>
                                        <small class="text-muted"><?php echo $lead['last_interaction_date'] ? timeAgo($lead['last_interaction_date']) : 'No activity'; ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="card dashboard-aside">
        <div class="card-header">
            <h2 class="card-title">Recent Activity</h2>
        </div>
        <div class="card-body">
            <div class="activity-feed">
                <?php if (empty($recent_activities)): ?>
                    <p class="text-center text-muted">No recent activity</p>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-feed-item">
                            <div class="activity-feed-avatar">
                                <?php echo getInitials($activity['user_name'] ?? 'System'); ?>
                            </div>
                            <div class="activity-feed-content">
                                <p class="activity-feed-text">
                                    <strong><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></strong>
                                    <span class="text-muted"><?php echo htmlspecialchars($activity['action']); ?></span>
                                </p>
                                <?php if ($activity['details']): ?>
                                    <p class="activity-feed-detail">
                                        <?php echo htmlspecialchars($activity['details']); ?>
                                    </p>
                                <?php endif; ?>
                                <p class="activity-feed-time">
                                    <?php echo timeAgo($activity['created_at']); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
