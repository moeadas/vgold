<?php
/**
 * Victory Genomics CRM - Reports & Analytics
 * With Sales Rep & Date Range filters for Sales Manager+
 * Apple-style, SVG icons, Chart.js
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

// Only admins and sales managers can access reports
if (!hasRole('Sales Manager')) {
    $_SESSION['error'] = 'You do not have permission to view reports.';
    header('Location: /dashboard.php');
    exit;
}

$pageTitle = 'Reports & Analytics';

// Get filter values
$filterRepId   = isset($_GET['sales_rep']) ? intval($_GET['sales_rep']) : 0;
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';
$isManager      = hasRole('Sales Manager');

// Get sales reps for the filter dropdown (visible to Sales Manager+)
$salesReps = [];
if ($isManager) {
    try {
        $db = Database::getInstance();
        $salesReps = $db->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' ORDER BY full_name")->fetchAll();
    } catch (Exception $e) { /* skip */ }
}

// Build WHERE clauses for leads and interactions based on filters
$leadWhere = "1=1";
$intWhere  = "1=1";
$leadParams = [];
$intParams  = [];

if ($filterRepId) {
    $leadWhere .= " AND l.assigned_to = ?";
    $leadParams[] = $filterRepId;
    $intWhere .= " AND i.user_id = ?";
    $intParams[] = $filterRepId;
}
if ($filterDateFrom) {
    $leadWhere .= " AND l.created_at >= ?";
    $leadParams[] = $filterDateFrom . ' 00:00:00';
    $intWhere .= " AND i.interaction_date >= ?";
    $intParams[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $leadWhere .= " AND l.created_at <= ?";
    $leadParams[] = $filterDateTo . ' 23:59:59';
    $intWhere .= " AND i.interaction_date <= ?";
    $intParams[] = $filterDateTo . ' 23:59:59';
}

// Get the selected rep name for display
$filterRepName = '';
if ($filterRepId) {
    foreach ($salesReps as $rep) {
        if ($rep['user_id'] == $filterRepId) {
            $filterRepName = $rep['full_name'];
            break;
        }
    }
}

try {
    $db = Database::getInstance();
    
    // Lead Stats
    $leadStats = $db->query("
        SELECT 
            COUNT(*) as total_leads,
            COUNT(CASE WHEN l.lead_status = 'New Lead' THEN 1 END) as new_leads,
            COUNT(CASE WHEN l.lead_status = 'Interested' THEN 1 END) as interested,
            COUNT(CASE WHEN l.lead_status = 'Won' THEN 1 END) as won,
            COUNT(CASE WHEN l.lead_status = 'Lost' THEN 1 END) as lost,
            COUNT(CASE WHEN l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as leads_this_month,
            COUNT(CASE WHEN l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as leads_this_week
        FROM leads l
        WHERE $leadWhere
    ", $leadParams)->fetch();
    
    // Leads by Status
    $leadsByStatus = $db->query("
        SELECT l.lead_status, COUNT(*) as count 
        FROM leads l 
        WHERE $leadWhere 
        GROUP BY l.lead_status ORDER BY count DESC
    ", $leadParams)->fetchAll();
    
    // Leads by Type
    $leadsByType = $db->query("
        SELECT l.lead_type, COUNT(*) as count 
        FROM leads l 
        WHERE $leadWhere 
        GROUP BY l.lead_type ORDER BY count DESC
    ", $leadParams)->fetchAll();
    
    // Leads by Country
    $leadsByCountry = $db->query("
        SELECT l.country, COUNT(*) as count 
        FROM leads l 
        WHERE $leadWhere AND l.country IS NOT NULL AND l.country != '' 
        GROUP BY l.country ORDER BY count DESC LIMIT 15
    ", $leadParams)->fetchAll();
    
    // Interaction Stats
    $interactionStats = $db->query("
        SELECT COUNT(*) as total_interactions,
            COUNT(CASE WHEN i.interaction_type = 'Call' THEN 1 END) as calls,
            COUNT(CASE WHEN i.interaction_type = 'Email' THEN 1 END) as emails,
            COUNT(CASE WHEN i.interaction_type = 'Meeting' THEN 1 END) as meetings,
            COUNT(CASE WHEN i.interaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as interactions_this_month
        FROM interactions i
        WHERE $intWhere
    ", $intParams)->fetch();
    
    // User Performance (always show all users, not filtered)
    $userPerformance = $db->query("
        SELECT u.full_name, u.role,
            COUNT(DISTINCT l.lead_id) as assigned_leads,
            COUNT(DISTINCT i.interaction_id) as interactions,
            COUNT(CASE WHEN l.lead_status = 'Won' THEN 1 END) as deals_won
        FROM users u
        LEFT JOIN leads l ON u.user_id = l.assigned_to
        LEFT JOIN interactions i ON u.user_id = i.user_id
        WHERE u.status = 'Active'
        GROUP BY u.user_id
        ORDER BY deals_won DESC, interactions DESC
        LIMIT 10
    ")->fetchAll();
    
    // Monthly Trend (filtered)
    $trendWhere = str_replace('l.', 'lt.', $leadWhere);
    $monthlyTrend = $db->query("
        SELECT DATE_FORMAT(lt.created_at, '%Y-%m') as month, COUNT(*) as count
        FROM leads lt
        WHERE lt.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND $trendWhere
        GROUP BY DATE_FORMAT(lt.created_at, '%Y-%m')
        ORDER BY month ASC
    ", $leadParams)->fetchAll();
    
    // Conversion
    $conversionData = $db->query("
        SELECT COUNT(*) as total,
            COUNT(CASE WHEN l.lead_status IN ('Won', 'Lost') THEN 1 END) as closed,
            COUNT(CASE WHEN l.lead_status = 'Won' THEN 1 END) as won
        FROM leads l
        WHERE $leadWhere
    ", $leadParams)->fetch();
    
    $conversionRate = $conversionData['total'] > 0 
        ? round(($conversionData['won'] / $conversionData['total']) * 100, 1) 
        : 0;
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error loading analytics: " . $e->getMessage();
    $leadStats = $interactionStats = [];
    $leadsByStatus = $leadsByType = $leadsByCountry = [];
    $userPerformance = $monthlyTrend = [];
    $conversionRate = 0;
    $conversionData = ['won' => 0, 'total' => 0];
}

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reports &amp; Analytics</h1>
        <p class="page-subtitle">
            <?php if ($filterRepName): ?>
                Showing data for <strong><?php echo htmlspecialchars($filterRepName); ?></strong>
                <?php if ($filterDateFrom || $filterDateTo): ?>
                    &middot;
                    <?php if ($filterDateFrom) echo htmlspecialchars($filterDateFrom); ?>
                    <?php if ($filterDateFrom && $filterDateTo) echo ' &ndash; '; ?>
                    <?php if ($filterDateTo) echo htmlspecialchars($filterDateTo); ?>
                <?php endif; ?>
            <?php elseif ($filterDateFrom || $filterDateTo): ?>
                Filtered by date:
                <?php if ($filterDateFrom) echo htmlspecialchars($filterDateFrom); ?>
                <?php if ($filterDateFrom && $filterDateTo) echo ' &ndash; '; ?>
                <?php if ($filterDateTo) echo htmlspecialchars($filterDateTo); ?>
            <?php else: ?>
                Comprehensive insights into your sales performance
            <?php endif; ?>
        </p>
    </div>
    <div class="card-actions">
        <button class="btn btn-outline" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Report
        </button>
    </div>
</div>

<?php if ($isManager): ?>
<!-- Filters for Sales Manager -->
<div class="card filter-card" style="margin-bottom:20px;">
    <div class="card-body">
        <form method="GET" class="filter-form" id="reportFilterForm">
            <div class="form-group filter-group">
                <label class="form-label">Sales Rep</label>
                <select name="sales_rep" class="form-control">
                    <option value="">All Sales Reps</option>
                    <?php foreach ($salesReps as $rep): ?>
                        <option value="<?php echo $rep['user_id']; ?>" <?php echo $filterRepId == $rep['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rep['full_name']); ?> (<?php echo htmlspecialchars($rep['role']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group filter-group">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
            </div>
            <div class="form-group filter-group">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filterDateTo); ?>">
            </div>
            <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Apply Filters
            </button>
            <?php if ($filterRepId || $filterDateFrom || $filterDateTo): ?>
                <a href="/pages/reports.php" class="btn btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Key Metrics -->
<div class="grid grid-4">
    <div class="stat-card">
        <div class="stat-icon bg-gradient-primary">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $leadStats['total_leads'] ?? 0; ?></div>
            <div class="stat-label">Total Leads</div>
            <div class="stat-change positive">+<?php echo $leadStats['leads_this_month'] ?? 0; ?> this month</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $conversionRate; ?>%</div>
            <div class="stat-label">Conversion Rate</div>
            <div class="stat-change"><?php echo $conversionData['won'] ?? 0; ?> won / <?php echo $conversionData['total'] ?? 0; ?> total</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $interactionStats['total_interactions'] ?? 0; ?></div>
            <div class="stat-label">Total Updates</div>
            <div class="stat-change positive">+<?php echo $interactionStats['interactions_this_month'] ?? 0; ?> this month</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $leadStats['won'] ?? 0; ?></div>
            <div class="stat-label">Won</div>
            <div class="stat-change negative"><?php echo $leadStats['lost'] ?? 0; ?> lost</div>
        </div>
    </div>
</div>

<!-- Interaction Breakdown (visible when filters active) -->
<?php if ($filterRepId || $filterDateFrom || $filterDateTo): ?>
<div class="grid grid-4" style="margin-top:0;">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#e8f0fe,#bfdbfe);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1a56db" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $interactionStats['calls'] ?? 0; ?></div>
            <div class="stat-label">Calls</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#f0e8ff,#e0d5ff);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $interactionStats['emails'] ?? 0; ?></div>
            <div class="stat-label">Emails</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#e6f7ed,#bbf7d0);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $interactionStats['meetings'] ?? 0; ?></div>
            <div class="stat-label">Meetings</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#fef4e2,#fed7aa);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo $leadStats['new_leads'] ?? 0; ?></div>
            <div class="stat-label">New Leads</div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card"><div class="card-header"><h3 class="card-title">Leads by Status</h3></div><div class="card-body"><canvas id="statusChart" height="300"></canvas></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Leads by Type</h3></div><div class="card-body"><canvas id="typeChart" height="300"></canvas></div></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Lead Generation Trend (Last 6 Months)</h3></div>
    <div class="card-body"><canvas id="trendChart" height="100"></canvas></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Leads by Country</h3></div>
    <div class="card-body">
        <div class="table-container">
            <table class="table">
                <thead><tr><th>Country</th><th>Count</th><th>%</th><th>Progress</th></tr></thead>
                <tbody>
                    <?php $totalLeads = max($leadStats['total_leads'] ?? 1, 1);
                    foreach ($leadsByCountry as $country): $pct = round(($country['count'] / $totalLeads) * 100, 1); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($country['country']); ?></td>
                            <td><?php echo $country['count']; ?></td>
                            <td><?php echo $pct; ?>%</td>
                            <td><div class="progress-bar"><div class="progress-fill" style="width:<?php echo $pct; ?>%"></div></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Team Performance -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Team Performance</h3></div>
    <div class="card-body">
        <div class="table-container">
            <table class="table">
                <thead><tr><th>User</th><th>Role</th><th>Leads</th><th>Interactions</th><th>Won</th><th>Win Rate</th></tr></thead>
                <tbody>
                    <?php foreach ($userPerformance as $user):
                        $winRate = $user['assigned_leads'] > 0 ? round(($user['deals_won'] / $user['assigned_leads']) * 100, 1) : 0; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><span class="badge bg-gray-100 text-gray-800"><?php echo htmlspecialchars($user['role']); ?></span></td>
                            <td><?php echo $user['assigned_leads']; ?></td>
                            <td><?php echo $user['interactions']; ?></td>
                            <td><?php echo $user['deals_won']; ?></td>
                            <td>
                                <div class="performance-indicator">
                                    <div class="progress-bar"><div class="progress-fill bg-success" style="width:<?php echo $winRate; ?>%"></div></div>
                                    <span class="performance-label"><?php echo $winRate; ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
var chartColors = ['#0071e3', '#5856d6', '#ff2d55', '#5ac8fa', '#34c759', '#ff9500', '#ff3b30', '#af52de'];

new Chart(document.getElementById('statusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($leadsByStatus, 'lead_status')); ?>,
        datasets: [{ data: <?php echo json_encode(array_column($leadsByStatus, 'count')); ?>, backgroundColor: chartColors }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
});

new Chart(document.getElementById('typeChart').getContext('2d'), {
    type: 'pie',
    data: {
        labels: <?php echo json_encode(array_column($leadsByType, 'lead_type')); ?>,
        datasets: [{ data: <?php echo json_encode(array_column($leadsByType, 'count')); ?>, backgroundColor: chartColors }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
});

new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($monthlyTrend, 'month')); ?>,
        datasets: [{ label: 'New Leads', data: <?php echo json_encode(array_column($monthlyTrend, 'count')); ?>, borderColor: '#0071e3', backgroundColor: 'rgba(0,113,227,0.08)', tension: 0.4, fill: true }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php include '../includes/footer.php'; ?>
