<?php
/**
 * Victory Genomics CRM - VoIP Dashboard
 * Role-aware: Admins/Managers see all calls; Sales Reps see only their own
 * Features: Call stats, call history, user filter, quick dialer, call detail modal
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

$pageTitle = 'VoIP Calls';
$csrf_token = generateCSRFToken();
$db = Database::getInstance();
$isSalesRep = !hasRole('Sales Manager');
$isManager = hasRole('Sales Manager');
$currentUserId = getCurrentUserId();
$currentUser = getCurrentUser();

// ─── Auto-create voip_calls table if missing ───
try {
    $db->query("SELECT 1 FROM voip_calls LIMIT 1");
} catch (Exception $e) {
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS voip_calls (
                call_id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NULL,
                user_id INT NULL,
                twilio_call_sid VARCHAR(50) NULL,
                direction ENUM('Inbound','Outbound') DEFAULT 'Outbound',
                from_number VARCHAR(30) NULL,
                to_number VARCHAR(30) NULL,
                status VARCHAR(30) DEFAULT 'Initiated',
                duration_seconds INT DEFAULT 0,
                outcome VARCHAR(30) NULL,
                notes TEXT NULL,
                recording_url VARCHAR(512) NULL,
                recording_sid VARCHAR(50) NULL,
                started_at DATETIME NULL,
                ended_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_lead (lead_id),
                INDEX idx_sid (twilio_call_sid),
                INDEX idx_created (created_at),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $ex) {
        error_log("Failed to create voip_calls table: " . $ex->getMessage());
    }
}

// ─── Role-based filters ───
if ($isSalesRep) {
    $statsFilter = "AND (vc.user_id = $currentUserId OR vc.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = $currentUserId))";
    $callFilter  = "AND (vc.user_id = $currentUserId OR vc.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = $currentUserId))";
} else {
    $statsFilter = '';
    $callFilter  = '';
}

// ─── Stats ───
try {
    $stats = [
        'total'     => $db->query("SELECT COUNT(*) FROM voip_calls vc WHERE 1=1 $statsFilter")->fetchColumn(),
        'today'     => $db->query("SELECT COUNT(*) FROM voip_calls vc WHERE DATE(vc.created_at) = CURDATE() $statsFilter")->fetchColumn(),
        'duration'  => $db->query("SELECT COALESCE(SUM(vc.duration_seconds),0) FROM voip_calls vc WHERE 1=1 $statsFilter")->fetchColumn(),
        'completed' => $db->query("SELECT COUNT(*) FROM voip_calls vc WHERE vc.status = 'Completed' $statsFilter")->fetchColumn(),
        'avg_dur'   => $db->query("SELECT COALESCE(AVG(vc.duration_seconds),0) FROM voip_calls vc WHERE vc.duration_seconds > 0 $statsFilter")->fetchColumn(),
        'positive'  => $db->query("SELECT COUNT(*) FROM voip_calls vc WHERE vc.outcome = 'Positive' $statsFilter")->fetchColumn(),
    ];
} catch (Exception $e) {
    $stats = ['total' => 0, 'today' => 0, 'duration' => 0, 'completed' => 0, 'avg_dur' => 0, 'positive' => 0];
}

// ─── Recent calls (role-filtered) ───
try {
    $calls = $db->query("
        SELECT vc.*, u.full_name as user_name, l.company_name, l.contact_person, l.assigned_to,
               au.full_name as assigned_user_name
        FROM voip_calls vc
        LEFT JOIN users u ON vc.user_id = u.user_id
        LEFT JOIN leads l ON vc.lead_id = l.lead_id
        LEFT JOIN users au ON l.assigned_to = au.user_id
        WHERE 1=1 $callFilter
        ORDER BY vc.created_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $calls = [];
}

// Get users for filter (admin/manager)
$allUsers = [];
if ($isManager) {
    try {
        $allUsers = $db->query("SELECT user_id, full_name FROM users WHERE status = 'Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Per-user breakdown for managers
$userBreakdown = [];
if ($isManager) {
    try {
        $userBreakdown = $db->query("
            SELECT u.full_name, u.user_id,
                   COUNT(vc.call_id) as total_calls,
                   COUNT(CASE WHEN DATE(vc.created_at) = CURDATE() THEN 1 END) as today_calls,
                   COALESCE(SUM(vc.duration_seconds),0) as total_duration,
                   COALESCE(AVG(CASE WHEN vc.duration_seconds > 0 THEN vc.duration_seconds END),0) as avg_duration,
                   COUNT(CASE WHEN vc.outcome = 'Positive' THEN 1 END) as positive_outcomes
            FROM users u
            LEFT JOIN voip_calls vc ON u.user_id = vc.user_id
            WHERE u.status = 'Active' AND u.role IN ('Sales Rep','Sales Manager','Admin')
            GROUP BY u.user_id, u.full_name
            ORDER BY total_calls DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            VoIP Calls
        </h1>
        <p class="page-subtitle">
            <?php if ($isSalesRep): ?>
                Your call history and statistics
            <?php else: ?>
                Team call history, statistics &amp; performance
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <span id="voip-ready-badge" class="voip-status-badge" style="font-size:11px;padding:4px 10px;border-radius:20px;background:#86868b;color:#fff;">Initializing...</span>
        <?php if ($isManager): ?>
        <button class="btn btn-outline" onclick="checkVoIPSetup()" title="Diagnose Twilio webhook configuration" style="font-size:12px;padding:6px 12px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Setup
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="showDialer()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            Quick Dial
        </button>
    </div>
</div>

<!-- ═══ Stats ═══ -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon bg-gradient-primary">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Total Calls</div><div class="stat-value"><?php echo $stats['total']; ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Today</div><div class="stat-value"><?php echo $stats['today']; ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Duration</div>
            <div class="stat-value"><?php
                $dur = $stats['duration'];
                if ($dur >= 3600) echo floor($dur/3600) . 'h ' . floor(($dur%3600)/60) . 'm';
                elseif ($dur >= 60) echo floor($dur/60) . 'm ' . ($dur%60) . 's';
                else echo $dur . 's';
            ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Completed</div><div class="stat-value"><?php echo $stats['completed']; ?></div></div>
    </div>
</div>

<!-- Additional stats row -->
<div class="stats-grid" style="margin-top:-8px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ff9f0a,#ff6723);color:#fff;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Avg Duration</div>
            <div class="stat-value"><?php
                $avg = round($stats['avg_dur']);
                echo $avg >= 60 ? floor($avg/60) . ':' . str_pad($avg%60, 2, '0', STR_PAD_LEFT) : $avg . 's';
            ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#34c759,#28a745);color:#fff;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Positive Outcomes</div><div class="stat-value"><?php echo $stats['positive']; ?></div></div>
    </div>
    <?php if ($stats['total'] > 0): ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#5856d6,#af52de);color:#fff;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-content">
            <div class="stat-label">Completion Rate</div>
            <div class="stat-value"><?php echo round(($stats['completed'] / max(1, $stats['total'])) * 100); ?>%</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($isManager && !empty($userBreakdown)): ?>
<!-- ═══ Team Performance (Admins/Managers only) ═══ -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Team Performance
        </h2>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Sales Rep</th>
                        <th>Total Calls</th>
                        <th>Today</th>
                        <th>Total Duration</th>
                        <th>Avg Duration</th>
                        <th>Positive</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userBreakdown as $ub): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ub['full_name']); ?></strong>
                            </td>
                            <td><span class="badge bg-blue-100 text-blue-800"><?php echo $ub['total_calls']; ?></span></td>
                            <td><?php echo $ub['today_calls']; ?></td>
                            <td><?php
                                $d = $ub['total_duration'];
                                if ($d >= 3600) echo floor($d/3600) . 'h ' . floor(($d%3600)/60) . 'm';
                                elseif ($d >= 60) echo floor($d/60) . 'm ' . ($d%60) . 's';
                                else echo $d . 's';
                            ?></td>
                            <td><?php
                                $a = round($ub['avg_duration']);
                                echo $a >= 60 ? floor($a/60) . ':' . str_pad($a%60, 2, '0', STR_PAD_LEFT) : $a . 's';
                            ?></td>
                            <td>
                                <?php if ($ub['positive_outcomes']): ?>
                                    <span class="badge bg-green-100 text-green-800"><?php echo $ub['positive_outcomes']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $rate = $ub['total_calls'] > 0 ? round(($ub['positive_outcomes'] / $ub['total_calls']) * 100) : 0;
                                    $barColor = $rate >= 50 ? '#34c759' : ($rate >= 25 ? '#ff9f0a' : '#ff3b30');
                                ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;height:6px;background:#f0f0f0;border-radius:3px;overflow:hidden;">
                                        <div style="width:<?php echo $rate; ?>%;height:100%;background:<?php echo $barColor; ?>;border-radius:3px;transition:width 0.3s;"></div>
                                    </div>
                                    <small style="color:var(--text-muted);min-width:30px;"><?php echo $rate; ?>%</small>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Call History ═══ -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Call History</h2>
        <div style="display:flex;align-items:center;gap:8px;">
            <!-- Search -->
            <input type="text" id="callSearch" class="form-control" placeholder="Search calls..." style="width:180px;font-size:12px;padding:6px 10px;" oninput="filterCalls()">
            <!-- Status filter -->
            <select id="filterStatus" onchange="filterCalls()" class="form-control" style="width:auto;font-size:12px;padding:6px 8px;">
                <option value="">All Statuses</option>
                <option value="Completed">Completed</option>
                <option value="In-Progress">In-Progress</option>
                <option value="Ringing">Ringing</option>
                <option value="No-Answer">No-Answer</option>
                <option value="Busy">Busy</option>
                <option value="Failed">Failed</option>
                <option value="Canceled">Canceled</option>
            </select>
            <?php if ($isManager && !empty($allUsers)): ?>
                <select id="filterUser" onchange="filterCalls()" class="form-control" style="width:auto;font-size:12px;padding:6px 8px;">
                    <option value="">All Users</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?php echo $u['user_id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table class="table" id="callsTable">
                <thead>
                    <tr>
                        <th>Lead / Contact</th>
                        <?php if ($isManager): ?><th>User</th><?php endif; ?>
                        <th>Number</th>
                        <th>Direction</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Outcome</th>
                        <th>Notes</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($calls)): ?>
                        <tr id="empty-row"><td colspan="<?php echo $isManager ? 10 : 9; ?>" class="text-center text-muted" style="padding:40px 20px;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#c7c7cc" stroke-width="1.5" style="margin-bottom:8px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <br>No calls yet. Use the <strong>Quick Dial</strong> button or call leads from their detail page.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($calls as $call): ?>
                            <tr data-user-id="<?php echo $call['user_id'] ?: ''; ?>"
                                data-status="<?php echo htmlspecialchars($call['status']); ?>"
                                data-search="<?php echo htmlspecialchars(strtolower(
                                    ($call['contact_person'] ?? '') . ' ' .
                                    ($call['company_name'] ?? '') . ' ' .
                                    ($call['to_number'] ?? '') . ' ' .
                                    ($call['from_number'] ?? '') . ' ' .
                                    ($call['user_name'] ?? '') . ' ' .
                                    ($call['outcome'] ?? '') . ' ' .
                                    ($call['notes'] ?? '')
                                )); ?>">
                                <td>
                                    <?php if ($call['lead_id']): ?>
                                        <a href="lead-detail.php?id=<?php echo $call['lead_id']; ?>" style="text-decoration:none;">
                                            <strong><?php echo htmlspecialchars($call['contact_person'] ?: $call['company_name'] ?: 'Lead #'.$call['lead_id']); ?></strong>
                                        </a>
                                        <?php if ($call['company_name'] && $call['contact_person']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($call['company_name']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No lead linked</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($isManager): ?>
                                    <td>
                                        <?php if ($call['user_name']): ?>
                                            <span class="badge" style="background:#e8f0fe;color:#1a56db;font-size:10px;"><?php echo htmlspecialchars($call['user_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:11px;">System</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td style="font-size:12px;font-family:monospace;"><?php echo htmlspecialchars($call['direction']==='Inbound' ? $call['from_number'] : $call['to_number']); ?></td>
                                <td>
                                    <?php
                                    $dirIcon = $call['direction'] === 'Inbound'
                                        ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/></svg>'
                                        : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';
                                    $dirClass = $call['direction'] === 'Inbound' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800';
                                    ?>
                                    <span class="badge <?php echo $dirClass; ?>"><?php echo $dirIcon; ?> <?php echo $call['direction']; ?></span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'Completed'   => 'bg-green-100 text-green-800',
                                        'In-Progress' => 'bg-blue-100 text-blue-800',
                                        'Ringing'     => 'bg-yellow-100 text-yellow-800',
                                        'No-Answer'   => 'bg-gray-100 text-gray-800',
                                        'Busy'        => 'bg-red-100 text-red-800',
                                        'Failed'      => 'bg-red-100 text-red-800',
                                        'Canceled'    => 'bg-gray-100 text-gray-800',
                                        'Initiated'   => 'bg-yellow-100 text-yellow-800',
                                    ];
                                    $sc = $statusColors[$call['status']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="badge <?php echo $sc; ?>"><?php echo htmlspecialchars($call['status']); ?></span>
                                </td>
                                <td>
                                    <?php if ($call['duration_seconds']): ?>
                                        <strong style="font-family:monospace;"><?php
                                            $d = $call['duration_seconds'];
                                            if ($d >= 3600) echo floor($d/3600) . ':' . str_pad(floor(($d%3600)/60), 2, '0', STR_PAD_LEFT) . ':' . str_pad($d%60, 2, '0', STR_PAD_LEFT);
                                            else echo floor($d/60) . ':' . str_pad($d%60, 2, '0', STR_PAD_LEFT);
                                        ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($call['outcome']): ?>
                                        <?php
                                        $outcomeColors = [
                                            'Positive'    => 'bg-green-100 text-green-800',
                                            'Neutral'     => 'bg-gray-100 text-gray-800',
                                            'Negative'    => 'bg-red-100 text-red-800',
                                            'No Response' => 'bg-yellow-100 text-yellow-800',
                                            'Voicemail'   => 'bg-blue-100 text-blue-800',
                                        ];
                                        $oc = $outcomeColors[$call['outcome']] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="badge <?php echo $oc; ?>"><?php echo htmlspecialchars($call['outcome']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:150px;">
                                    <?php if ($call['notes']): ?>
                                        <small title="<?php echo htmlspecialchars($call['notes']); ?>"><?php echo htmlspecialchars(substr($call['notes'], 0, 40)); ?><?php echo strlen($call['notes']) > 40 ? '...' : ''; ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo timeAgo($call['created_at']); ?></small>
                                    <?php if ($call['started_at']): ?>
                                        <br><small class="text-muted" style="font-size:10px;"><?php echo date('M d, H:i', strtotime($call['started_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                        <button class="comm-btn comm-btn-call" style="font-size:11px;padding:4px 8px;" onclick="VoIPPhone.call('<?php echo htmlspecialchars($call['direction']==='Inbound' ? $call['from_number'] : $call['to_number']); ?>', <?php echo $call['lead_id'] ?: 0; ?>)" title="Redial">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3"/></svg>
                                            Redial
                                        </button>
                                        <?php if ($call['recording_url']): ?>
                                            <button class="comm-btn" style="font-size:11px;padding:4px 8px;background:#e8f0fe;color:#1a56db;" onclick="playRecording('<?php echo htmlspecialchars($call['recording_url']); ?>')" title="Play Recording">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                                Play
                                            </button>
                                        <?php endif; ?>
                                        <button class="comm-btn" style="font-size:11px;padding:4px 8px;background:#f5f5f7;color:#636366;" onclick="showCallDetail(<?php echo htmlspecialchars(json_encode($call)); ?>)" title="View Details">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($calls) >= 200): ?>
            <div style="text-align:center;padding:12px;color:var(--text-muted);font-size:12px;">
                Showing last 200 calls. Use filters to narrow results.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ Quick Dialer Modal ═══ -->
<div id="dialerModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideDialer()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Quick Dial
            </h3>
            <button type="button" class="btn-close" onclick="hideDialer()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="tel" id="dialNumber" class="form-control" placeholder="+1 858 358 5260" autofocus
                    style="font-size:16px;letter-spacing:0.5px;"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();dialNumber();}">
                <small class="text-muted" style="font-size:11px;">Enter a phone number with country code (e.g. +1 for US)</small>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="hideDialer()">Cancel</button>
            <button class="btn btn-primary" onclick="dialNumber()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Call
            </button>
        </div>
    </div>
</div>

<!-- ═══ Call Detail Modal ═══ -->
<div id="callDetailModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideCallDetail()"></div>
    <div class="modal-content" style="max-width:540px;">
        <div class="modal-header">
            <h3>Call Details</h3>
            <button type="button" class="btn-close" onclick="hideCallDetail()">&times;</button>
        </div>
        <div class="modal-body" id="callDetailBody">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="hideCallDetail()">Close</button>
        </div>
    </div>
</div>

<!-- ═══ Recording Player ═══ -->
<div id="recordingPlayer" style="display:none;position:fixed;bottom:80px;right:24px;background:var(--color-surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-xl);border:1px solid var(--color-border);padding:16px;z-index:9999;width:320px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <strong style="font-size:13px;">Call Recording</strong>
        <button onclick="closeRecordingPlayer()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:16px;">&times;</button>
    </div>
    <audio id="recordingAudio" controls style="width:100%;" preload="none"></audio>
</div>

<script>
// ─── Dialer ───
function showDialer() {
    document.getElementById('dialerModal').style.display = 'flex';
    setTimeout(function() { document.getElementById('dialNumber').focus(); }, 100);
}
function hideDialer() {
    document.getElementById('dialerModal').style.display = 'none';
    document.getElementById('dialNumber').value = '';
}
function dialNumber() {
    var num = document.getElementById('dialNumber').value.trim();
    if (!num) { showNotification('Enter a phone number', 'error'); return; }
    hideDialer();
    VoIPPhone.call(num, 0);
}

// ─── Filters (user, status, search) ───
function filterCalls() {
    var userSel = document.getElementById('filterUser');
    var statusSel = document.getElementById('filterStatus');
    var searchInput = document.getElementById('callSearch');
    
    var uid = userSel ? userSel.value : '';
    var status = statusSel ? statusSel.value : '';
    var search = searchInput ? searchInput.value.toLowerCase().trim() : '';
    
    var rows = document.querySelectorAll('#callsTable tbody tr:not(#empty-row)');
    var visible = 0;
    
    rows.forEach(function(row) {
        var matchUser = !uid || (row.getAttribute('data-user-id') === uid);
        var matchStatus = !status || (row.getAttribute('data-status') === status);
        var matchSearch = !search || (row.getAttribute('data-search') || '').indexOf(search) !== -1;
        
        if (matchUser && matchStatus && matchSearch) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });
}

// ─── Call Detail Modal ───
function showCallDetail(call) {
    var body = document.getElementById('callDetailBody');
    var dur = call.duration_seconds || 0;
    var durStr = dur >= 3600 
        ? Math.floor(dur/3600) + ':' + String(Math.floor((dur%3600)/60)).padStart(2,'0') + ':' + String(dur%60).padStart(2,'0')
        : Math.floor(dur/60) + ':' + String(dur%60).padStart(2,'0');
    
    var html = '<div style="display:grid;grid-template-columns:140px 1fr;gap:8px 16px;font-size:13px;">';
    html += '<div class="text-muted">Direction</div><div><span class="badge ' + (call.direction === 'Inbound' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800') + '">' + escapeHtml(call.direction) + '</span></div>';
    html += '<div class="text-muted">From</div><div style="font-family:monospace;">' + escapeHtml(call.from_number || '-') + '</div>';
    html += '<div class="text-muted">To</div><div style="font-family:monospace;">' + escapeHtml(call.to_number || '-') + '</div>';
    html += '<div class="text-muted">Status</div><div>' + escapeHtml(call.status || '-') + '</div>';
    html += '<div class="text-muted">Duration</div><div><strong>' + durStr + '</strong></div>';
    html += '<div class="text-muted">Outcome</div><div>' + escapeHtml(call.outcome || '-') + '</div>';
    html += '<div class="text-muted">Notes</div><div>' + escapeHtml(call.notes || 'No notes') + '</div>';
    if (call.twilio_call_sid) {
        html += '<div class="text-muted">Twilio SID</div><div style="font-family:monospace;font-size:11px;word-break:break-all;">' + escapeHtml(call.twilio_call_sid) + '</div>';
    }
    html += '<div class="text-muted">Started</div><div>' + escapeHtml(call.started_at || call.created_at || '-') + '</div>';
    if (call.ended_at) {
        html += '<div class="text-muted">Ended</div><div>' + escapeHtml(call.ended_at) + '</div>';
    }
    if (call.contact_person || call.company_name) {
        html += '<div class="text-muted">Lead</div><div>' + escapeHtml(call.contact_person || '') + (call.company_name ? ' (' + escapeHtml(call.company_name) + ')' : '') + '</div>';
    }
    if (call.user_name) {
        html += '<div class="text-muted">Agent</div><div>' + escapeHtml(call.user_name) + '</div>';
    }
    if (call.recording_url) {
        html += '<div class="text-muted">Recording</div><div><button class="btn btn-sm btn-info" onclick="playRecording(\'' + escapeHtml(call.recording_url) + '\')">Play Recording</button></div>';
    }
    html += '</div>';
    
    body.innerHTML = html;
    document.getElementById('callDetailModal').style.display = 'flex';
}
function hideCallDetail() {
    document.getElementById('callDetailModal').style.display = 'none';
}

// ─── Recording Player ───
function playRecording(url) {
    var player = document.getElementById('recordingPlayer');
    var audio = document.getElementById('recordingAudio');
    audio.src = url;
    player.style.display = 'block';
    audio.play().catch(function() {});
}
function closeRecordingPlayer() {
    var audio = document.getElementById('recordingAudio');
    audio.pause();
    audio.src = '';
    document.getElementById('recordingPlayer').style.display = 'none';
}

// ─── VoIP Ready Badge ───
function updateReadyBadge() {
    var badge = document.getElementById('voip-ready-badge');
    if (!badge) return;
    if (typeof VoIPPhone !== 'undefined') {
        if (VoIPPhone.initSuccess) {
            badge.textContent = 'VoIP Ready';
            badge.style.background = '#34c759';
        } else if (VoIPPhone.initError) {
            badge.textContent = 'Server Call Mode';
            badge.style.background = '#ff9f0a';
            badge.title = 'WebRTC unavailable. Calls will use server-side dialing.';
        } else if (VoIPPhone.initAttempted) {
            badge.textContent = 'Connecting...';
            badge.style.background = '#ff9f0a';
        }
    }
}
// Check after VoIP init
setTimeout(updateReadyBadge, 2000);
setTimeout(updateReadyBadge, 5000);

// ─── Keyboard shortcuts ───
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideDialer();
        hideCallDetail();
        hideSetupModal();
    }
});

// ─── VoIP Setup / Diagnostics (Manager/Admin only) ───
function checkVoIPSetup() {
    showSetupModal('Checking Twilio configuration...');
    fetch('/api/voip.php?action=check_webhooks')
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success) {
                showSetupResult('<div style="color:#ff3b30;"><strong>Error:</strong> ' + escapeHtml(data.message || 'Unknown error') + '</div>');
                return;
            }
            var html = '<div style="font-size:13px;">';
            html += '<div style="margin-bottom:12px;"><strong>Phone Number:</strong> ' + escapeHtml(data.phone_number || '-') + '</div>';
            
            if (data.phone_config) {
                html += '<div style="margin-bottom:8px;"><strong>Phone Number Webhooks:</strong></div>';
                html += '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
                html += '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;color:#636366;">Voice URL</td><td style="padding:4px 8px;border-bottom:1px solid #eee;word-break:break-all;">' + escapeHtml(data.phone_config.voice_url || 'Not set') + '</td></tr>';
                html += '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;color:#636366;">Status Callback</td><td style="padding:4px 8px;border-bottom:1px solid #eee;word-break:break-all;">' + escapeHtml(data.phone_config.status_callback || 'Not set') + '</td></tr>';
                html += '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;color:#636366;">SMS URL</td><td style="padding:4px 8px;border-bottom:1px solid #eee;word-break:break-all;">' + escapeHtml(data.phone_config.sms_url || 'Not set') + '</td></tr>';
                html += '</table>';
            }
            
            if (data.twiml_app) {
                html += '<div style="margin:12px 0 8px;"><strong>TwiML App:</strong> ' + escapeHtml(data.twiml_app.friendly_name || '-') + '</div>';
                html += '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
                html += '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;color:#636366;">Voice URL</td><td style="padding:4px 8px;border-bottom:1px solid #eee;word-break:break-all;">' + escapeHtml(data.twiml_app.voice_url || 'Not set') + '</td></tr>';
                html += '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;color:#636366;">Status Callback</td><td style="padding:4px 8px;border-bottom:1px solid #eee;word-break:break-all;">' + escapeHtml(data.twiml_app.status_callback || 'Not set') + '</td></tr>';
                html += '</table>';
            }

            if (data.issues && data.issues.length > 0) {
                html += '<div style="margin-top:12px;padding:10px;background:#fff3cd;border-radius:8px;border:1px solid #ffc107;">';
                html += '<strong style="color:#856404;">Issues Found:</strong><ul style="margin:4px 0 0;padding-left:18px;">';
                data.issues.forEach(function(issue) {
                    html += '<li style="color:#856404;font-size:12px;margin-bottom:4px;">' + escapeHtml(issue) + '</li>';
                });
                html += '</ul>';
                html += '<div style="margin-top:10px;"><button class="btn btn-primary" onclick="fixWebhooks()" style="font-size:12px;padding:6px 16px;">Auto-Fix Webhooks</button></div>';
                html += '</div>';
            } else {
                html += '<div style="margin-top:12px;padding:10px;background:#d4edda;border-radius:8px;border:1px solid #28a745;">';
                html += '<strong style="color:#155724;">All webhooks configured correctly!</strong>';
                html += '</div>';
            }
            
            html += '</div>';
            showSetupResult(html);
        })
        .catch(function(err){
            showSetupResult('<div style="color:#ff3b30;"><strong>Error:</strong> ' + escapeHtml(err.message || 'Network error') + '</div>');
        });
}

function fixWebhooks() {
    showSetupResult('<div style="text-align:center;padding:20px;"><div class="spinner-border" style="width:24px;height:24px;border:3px solid #e8e8ed;border-top-color:#007aff;border-radius:50%;animation:spin 0.6s linear infinite;display:inline-block;"></div><br><small>Configuring webhooks...</small></div>');
    fetch('/api/voip.php?action=configure_webhooks')
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.success) {
                var html = '<div style="padding:10px;background:#d4edda;border-radius:8px;border:1px solid #28a745;">';
                html += '<strong style="color:#155724;">Webhooks configured successfully!</strong>';
                if (data.changes && data.changes.length) {
                    html += '<ul style="margin:8px 0 0;padding-left:18px;font-size:12px;">';
                    data.changes.forEach(function(c){ html += '<li style="color:#155724;">' + escapeHtml(c) + '</li>'; });
                    html += '</ul>';
                }
                html += '</div>';
                showSetupResult(html);
                if (typeof showNotification === 'function') showNotification('Twilio webhooks configured!', 'success');
            } else {
                showSetupResult('<div style="color:#ff3b30;"><strong>Failed:</strong> ' + escapeHtml(data.message || 'Unknown error') + '</div>');
            }
        })
        .catch(function(err){
            showSetupResult('<div style="color:#ff3b30;"><strong>Error:</strong> ' + escapeHtml(err.message || 'Network error') + '</div>');
        });
}

function showSetupModal(loadingText) {
    var modal = document.getElementById('setupModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'setupModal';
        modal.className = 'modal';
        modal.innerHTML = '<div class="modal-backdrop" onclick="hideSetupModal()"></div>' +
            '<div class="modal-content" style="max-width:560px;">' +
            '<div class="modal-header"><h3><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09"/></svg> VoIP Setup</h3><button type="button" class="btn-close" onclick="hideSetupModal()">&times;</button></div>' +
            '<div class="modal-body" id="setupBody"></div>' +
            '<div class="modal-footer"><button class="btn btn-outline" onclick="hideSetupModal()">Close</button></div>' +
            '</div>';
        document.body.appendChild(modal);
    }
    modal.style.display = 'flex';
    document.getElementById('setupBody').innerHTML = '<div style="text-align:center;padding:20px;"><div style="width:24px;height:24px;border:3px solid #e8e8ed;border-top-color:#007aff;border-radius:50%;animation:spin 0.6s linear infinite;display:inline-block;"></div><br><small>' + (loadingText || 'Loading...') + '</small></div>';
}

function showSetupResult(html) {
    var body = document.getElementById('setupBody');
    if (body) body.innerHTML = html;
}

function hideSetupModal() {
    var modal = document.getElementById('setupModal');
    if (modal) modal.style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
