<?php
/**
 * Victory Genomics CRM - Interactions Management
 * 65/35 two-column layout, unified top filter bar
 * Follow-up filter, delete button for managers
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

$currentUser = getCurrentUser();
$isManager = hasRole('Sales Manager');
$csrf_token = generateCSRFToken();

$pageTitle = 'Interactions';
$leadId = isset($_GET['lead_id']) ? intval($_GET['lead_id']) : 0;
$interactionType = $_GET['type'] ?? '';
$filterType = $_GET['filter_type'] ?? '';
$followUpFilter = isset($_GET['follow_up']) && $_GET['follow_up'] == '1';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;

// Fetch lead details if leadId is provided
$leadData = null;
if ($leadId) {
    try {
        $db = Database::getInstance();
        $leadData = $db->findOne('leads', ['lead_id' => $leadId]);
    } catch (Exception $e) { /* Continue without lead data */ }
}

// Handle delete interaction (POST with action=delete_interaction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_interaction') {
    requireCSRF();
    if (!hasRole('Sales Manager')) {
        $_SESSION['error'] = 'Permission denied.';
    } else {
        try {
            $db = Database::getInstance();
            $deleteId = intval($_POST['interaction_id']);
            $db->query("DELETE FROM interactions WHERE interaction_id = ?", [$deleteId]);
            logActivity(getCurrentUserId(), 'Delete Interaction', 'Interaction', $deleteId, "Deleted interaction ID $deleteId");
            $_SESSION['success'] = "Interaction deleted successfully.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Error deleting interaction: " . $e->getMessage();
        }
    }
    // Redirect back to same page
    $redirectUrl = 'interactions.php?' . http_build_query(array_filter(['lead_id' => $leadId, 'filter_type' => $filterType, 'follow_up' => $followUpFilter ? '1' : '', 'page' => $page]));
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle form submission (log new interaction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'delete_interaction')) {
    requireCSRF();
    
    try {
        $db = Database::getInstance();
        
        // Ownership check: Sales Reps can only log interactions for their own leads
        if (!$isManager) {
            $intLeadId = intval($_POST['lead_id']);
            $uid = getCurrentUserId();
            $chk = $db->query(
                "SELECT lead_id FROM leads WHERE lead_id = ? AND (assigned_to = ? OR created_by = ?)",
                [$intLeadId, $uid, $uid]
            )->fetch();
            if (!$chk) {
                $_SESSION['error'] = "Access denied — you can only log interactions for leads assigned to you.";
                header('Location: interactions.php' . ($leadId ? '?lead_id=' . $leadId : ''));
                exit;
            }
        }

        $data = [
            'lead_id' => intval($_POST['lead_id']),
            'user_id' => getCurrentUserId(),
            'interaction_type' => $_POST['interaction_type'],
            'interaction_date' => $_POST['interaction_date'],
            'subject' => $_POST['subject'],
            'notes' => $_POST['notes'] ?? '',
            'outcome' => !empty($_POST['outcome']) ? $_POST['outcome'] : null,
            'next_action' => $_POST['next_action'] ?? null,
            'next_action_date' => !empty($_POST['next_action_date']) ? $_POST['next_action_date'] : null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $interactionId = $db->insert('interactions', $data);
        $db->update('leads', ['updated_at' => date('Y-m-d H:i:s')], ['lead_id' => $data['lead_id']]);
        logActivity(getCurrentUserId(), 'Log Interaction', 'Interaction', $interactionId, "Logged {$data['interaction_type']} for lead ID {$data['lead_id']}");
        
        $_SESSION['success'] = "Interaction logged successfully";
        header('Location: lead-detail.php?id=' . $data['lead_id']);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error logging interaction: " . $e->getMessage();
    }
}

// Fetch interactions with pagination
try {
    $db = Database::getInstance();
    
    $whereClause = "1=1";
    $params = [];

    // Sales Reps only see interactions for their own leads
    if (!$isManager) {
        $uid = getCurrentUserId();
        $whereClause .= " AND (i.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = ? OR created_by = ?))";
        $params[] = $uid;
        $params[] = $uid;
    }
    
    if ($leadId) {
        $whereClause .= " AND i.lead_id = ?";
        $params[] = $leadId;
    }
    if ($filterType) {
        $whereClause .= " AND i.interaction_type = ?";
        $params[] = $filterType;
    }
    
    // Follow-up filter: show interactions of type 'Follow-up'
    if ($followUpFilter) {
        $whereClause .= " AND i.interaction_type = 'Follow-up'";
    }
    
    // Count total (unfiltered for stats)
    $countAll = $db->query("SELECT COUNT(*) FROM interactions i WHERE 1=1" . ($leadId ? " AND i.lead_id = $leadId" : ""))->fetchColumn();
    
    // Count filtered
    $countResult = $db->query("SELECT COUNT(*) FROM interactions i WHERE $whereClause", $params)->fetchColumn();
    $totalPages = max(1, ceil($countResult / $perPage));
    $offset = ($page - 1) * $perPage;
    
    $interactions = $db->query("
        SELECT i.*, l.company_name, l.contact_person, u.full_name as user_name
        FROM interactions i
        LEFT JOIN leads l ON i.lead_id = l.lead_id
        LEFT JOIN users u ON i.user_id = u.user_id
        WHERE $whereClause
        ORDER BY i.interaction_date DESC, i.created_at DESC
        LIMIT $perPage OFFSET $offset
    ", $params)->fetchAll();
    
    // Quick stats for the type filter chips
    $typeCounts = $db->query("
        SELECT interaction_type, COUNT(*) as cnt
        FROM interactions i
        WHERE 1=1" . ($leadId ? " AND i.lead_id = $leadId" : "") . "
        GROUP BY interaction_type
    ")->fetchAll();
    $typeCountMap = [];
    foreach ($typeCounts as $tc) {
        $typeCountMap[$tc['interaction_type']] = $tc['cnt'];
    }
    
    // Follow-up count: leads with at least one 'Follow-up' interaction
    $followUpCount = $db->query("
        SELECT COUNT(DISTINCT lead_id)
        FROM interactions
        WHERE interaction_type = 'Follow-up'
    ")->fetchColumn();
    
} catch (Exception $e) {
    $interactions = [];
    $totalPages = 1;
    $countAll = 0;
    $countResult = 0;
    $typeCountMap = [];
    $followUpCount = 0;
    $_SESSION['error'] = "Error loading interactions: " . $e->getMessage();
}

// Fetch leads for dropdown — Sales Reps only see their own leads
try {
    $db = Database::getInstance();
    if ($isManager) {
        $leads = $db->query("SELECT lead_id, company_name, contact_person, lead_status FROM leads ORDER BY company_name ASC")->fetchAll();
    } else {
        $uid = getCurrentUserId();
        $leads = $db->query(
            "SELECT lead_id, company_name, contact_person, lead_status FROM leads WHERE assigned_to = ? OR created_by = ? ORDER BY company_name ASC",
            [$uid, $uid]
        )->fetchAll();
    }
} catch (Exception $e) { $leads = []; }

// Helper: group interactions by date label
function getDateGroupLabel($dateStr) {
    $date = date('Y-m-d', strtotime($dateStr));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($date === $today) return 'Today';
    if ($date === $yesterday) return 'Yesterday';
    if (strtotime($date) > strtotime('-7 days')) return date('l', strtotime($date));
    return date('M d, Y', strtotime($date));
}

include '../includes/header.php';

// Build filter URLs
$baseQuery = $_GET;
unset($baseQuery['filter_type'], $baseQuery['page'], $baseQuery['follow_up']);
$allUrl = '?' . http_build_query($baseQuery);
$followUpUrl = '?' . http_build_query(array_merge($baseQuery, ['follow_up' => '1']));
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Interactions &amp; Activities</h1>
        <p class="page-subtitle">
            <?php if ($followUpFilter): ?>
                Showing leads that require follow-up
            <?php elseif ($leadData): ?>
                Log communication with <?php echo htmlspecialchars($leadData['company_name']); ?>
            <?php else: ?>
                Track all communication with your leads
            <?php endif; ?>
        </p>
    </div>
    <?php if ($leadData): ?>
        <a href="lead-detail.php?id=<?php echo $leadId; ?>" class="btn btn-outline">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Lead
        </a>
    <?php endif; ?>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Unified Filter & Stats Bar -->
<div class="ix-toolbar">
    <div class="ix-toolbar-left">
        <a href="<?php echo $allUrl; ?>" class="ix-filter-chip <?php echo (!$filterType && !$followUpFilter) ? 'active' : ''; ?>">
            All <span class="ix-chip-count"><?php echo $countAll; ?></span>
        </a>
        <?php
        $filterTypes = ['Call', 'Email', 'Meeting', 'Demo', 'Follow-up', 'Note'];
        foreach ($filterTypes as $ft):
            $ftCount = $typeCountMap[$ft] ?? 0;
            if ($ftCount > 0):
                $ftQuery = array_merge($baseQuery, ['filter_type' => $ft]);
                $ftUrl = '?' . http_build_query($ftQuery);
        ?>
        <a href="<?php echo $ftUrl; ?>" class="ix-filter-chip ix-chip-<?php echo strtolower($ft); ?> <?php echo $filterType === $ft ? 'active' : ''; ?>">
            <?php echo $ft; ?> <span class="ix-chip-count"><?php echo $ftCount; ?></span>
        </a>
        <?php endif; endforeach; ?>
        <?php if ($followUpCount > 0): ?>
        <a href="<?php echo $followUpUrl; ?>" class="ix-filter-chip ix-chip-follow-up <?php echo $followUpFilter ? 'active' : ''; ?>">
            Needs Follow-up <span class="ix-chip-count"><?php echo $followUpCount; ?></span>
        </a>
        <?php endif; ?>
    </div>
    <div class="ix-toolbar-right">
        <div style="position:relative;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-text-tertiary)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInteractions" class="form-control" placeholder="Search..." style="padding-left:32px;width:180px;font-size:13px;">
        </div>
    </div>
</div>

<!-- 65 / 35 Two-Column Layout -->
<div class="ix-layout">
    <!-- Recent Interactions (LEFT ~65%) -->
    <div class="ix-main">
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <h3 class="card-title" style="margin:0;">
                        <?php echo $followUpFilter ? 'Leads Needing Follow-up' : 'Recent Interactions'; ?>
                    </h3>
                    <?php if ($filterType): ?>
                        <span class="badge bg-blue-100 text-blue-800" style="font-size:12px;"><?php echo $countResult; ?> <?php echo htmlspecialchars($filterType); ?></span>
                    <?php else: ?>
                        <span class="badge bg-blue-100 text-blue-800" style="font-size:12px;"><?php echo $countResult; ?> total</span>
                    <?php endif; ?>
                </div>
                <?php if ($filterType || $followUpFilter): ?>
                    <a href="<?php echo $allUrl; ?>" class="btn btn-sm btn-outline" style="font-size:12px;">Clear filter</a>
                <?php endif; ?>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($interactions)): ?>
                    <div class="empty-state" style="padding:48px 20px;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-text-tertiary)" stroke-width="1.5" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <h3>No Interactions Found</h3>
                        <p><?php echo $filterType ? 'No ' . htmlspecialchars($filterType) . ' interactions found.' : ($followUpFilter ? 'No leads need follow-up right now.' : 'Start logging communication with leads.'); ?></p>
                        <?php if ($filterType || $followUpFilter): ?>
                            <a href="<?php echo $allUrl; ?>" class="btn btn-outline" style="margin-top:12px;">Clear Filter</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="interactions-timeline" style="padding:16px;">
                        <?php
                        $lastGroup = '';
                        foreach ($interactions as $idx => $interaction):
                            $groupLabel = getDateGroupLabel($interaction['interaction_date']);
                            if ($groupLabel !== $lastGroup):
                                $lastGroup = $groupLabel;
                        ?>
                            <div class="ix-date-divider">
                                <span class="ix-date-divider-label"><?php echo $groupLabel; ?></span>
                                <div class="ix-date-divider-line"></div>
                            </div>
                        <?php endif; ?>
                            <div class="interaction-card-enhanced" data-searchable>
                                <div class="interaction-left-strip interaction-strip-<?php echo strtolower($interaction['interaction_type']); ?>"></div>
                                <div class="interaction-card-content">
                                    <div class="interaction-header">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <span class="interaction-type-badge badge-<?php echo strtolower($interaction['interaction_type']); ?>">
                                                <?php
                                                $typeIcons = [
                                                    'Call' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
                                                    'Email' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
                                                    'Meeting' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
                                                    'Demo' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
                                                    'Follow-up' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/></svg>',
                                                    'Note' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
                                                ];
                                                echo ($typeIcons[$interaction['interaction_type']] ?? '') . ' ';
                                                echo htmlspecialchars($interaction['interaction_type']);
                                                ?>
                                            </span>
                                            <a href="lead-detail.php?id=<?php echo $interaction['lead_id']; ?>" class="interaction-lead-link">
                                                <?php echo htmlspecialchars($interaction['company_name'] ?: $interaction['contact_person'] ?: 'Lead #' . $interaction['lead_id']); ?>
                                            </a>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <div class="interaction-date">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:middle;margin-right:3px;opacity:0.6;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                                <?php echo date('M d, Y - H:i', strtotime($interaction['interaction_date'])); ?>
                                            </div>
                                            <?php if ($isManager): ?>
                                            <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Delete this interaction?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="delete_interaction">
                                                <input type="hidden" name="interaction_id" value="<?php echo $interaction['interaction_id']; ?>">
                                                <button type="submit" class="btn-icon-delete" title="Delete interaction">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="interaction-subject"><?php echo htmlspecialchars($interaction['subject']); ?></div>
                                    <?php
                                    $fullNotes = $interaction['notes'];
                                    $isLong = strlen($fullNotes) > 150;
                                    $shortNotes = $isLong ? truncate($fullNotes, 150) : $fullNotes;
                                    ?>
                                    <div class="interaction-notes-preview">
                                        <span class="ix-notes-short"><?php echo nl2br(htmlspecialchars($shortNotes)); ?></span>
                                        <?php if ($isLong): ?>
                                            <span class="ix-notes-full" style="display:none;"><?php echo nl2br(htmlspecialchars($fullNotes)); ?></span>
                                            <button type="button" class="ix-expand-btn" onclick="toggleNotes(this)">Show more</button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($interaction['next_action']): ?>
                                        <div class="interaction-next-action">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>
                                            <strong>Next:</strong>&nbsp;<?php echo htmlspecialchars($interaction['next_action']); ?>
                                            <?php if ($interaction['next_action_date']): ?>
                                                <?php
                                                $isOverdue = strtotime($interaction['next_action_date']) <= strtotime('today');
                                                ?>
                                                <span class="<?php echo $isOverdue ? 'text-danger' : 'text-muted'; ?>" style="margin-left:4px;">
                                                    (<?php echo date('M d, Y', strtotime($interaction['next_action_date'])); ?><?php echo $isOverdue ? ' - OVERDUE' : ''; ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="interaction-footer">
                                        <div class="interaction-user">
                                            <span class="interaction-user-avatar"><?php echo getInitials($interaction['user_name']); ?></span>
                                            <?php echo htmlspecialchars($interaction['user_name']); ?>
                                        </div>
                                        <span class="interaction-time-ago"><?php echo timeAgo($interaction['interaction_date']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination" style="margin:0;padding:16px;border-top:1px solid var(--color-border-light);">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">&laquo;</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">&raquo;</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Log Interaction Form (RIGHT ~35%) -->
    <div class="ix-sidebar">
        <div class="card" style="position:sticky;top:20px;">
            <div class="card-header" style="background:linear-gradient(135deg,#f0f5ff,#e8f0fe);border-bottom:2px solid var(--color-accent);">
                <h3 class="card-title" style="display:flex;align-items:center;gap:8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Log New Interaction
                </h3>
            </div>
            <div class="card-body">
                <form method="POST" id="interactionForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Select Lead</label>
                        <select name="lead_id" class="form-control" required>
                            <option value="">Choose a lead...</option>
                            <?php foreach ($leads as $lead): ?>
                                <option value="<?php echo $lead['lead_id']; ?>" <?php echo ($leadId == $lead['lead_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lead['company_name'] ?: $lead['contact_person'] ?: 'Lead #' . $lead['lead_id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Interaction Type</label>
                        <select name="interaction_type" class="form-control" required>
                            <option value="">Select type...</option>
                            <option value="Call" <?php echo $interactionType === 'call' ? 'selected' : ''; ?>>Phone Call</option>
                            <option value="Email" <?php echo $interactionType === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="Meeting" <?php echo $interactionType === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                            <option value="Demo">Demo/Presentation</option>
                            <option value="Follow-up">Follow-up</option>
                            <option value="Note">Note</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date &amp; Time</label>
                        <input type="datetime-local" name="interaction_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Brief description" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Detailed notes..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Outcome</label>
                        <select name="outcome" class="form-control">
                            <option value="">— None —</option>
                            <option value="Positive">Positive</option>
                            <option value="Neutral">Neutral</option>
                            <option value="Negative">Negative</option>
                            <option value="No Response">No Response</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Next Action</label>
                        <input type="text" name="next_action" class="form-control" placeholder="What's the next step?">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Next Action Date</label>
                        <input type="date" name="next_action_date" class="form-control">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Log Interaction
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInteractions');
    if (searchInput) {
        var cards = document.querySelectorAll('[data-searchable]');
        var dividers = document.querySelectorAll('.ix-date-divider');
        searchInput.addEventListener('input', function() {
            var term = this.value.toLowerCase();
            cards.forEach(function(card) { 
                card.style.display = card.textContent.toLowerCase().includes(term) ? '' : 'none'; 
            });
            dividers.forEach(function(div) {
                var next = div.nextElementSibling;
                var hasVisible = false;
                while (next && !next.classList.contains('ix-date-divider')) {
                    if (next.style.display !== 'none' && next.hasAttribute('data-searchable')) hasVisible = true;
                    next = next.nextElementSibling;
                }
                div.style.display = hasVisible ? '' : 'none';
            });
        });
    }
});

function toggleNotes(btn) {
    var container = btn.parentElement;
    var shortEl = container.querySelector('.ix-notes-short');
    var fullEl = container.querySelector('.ix-notes-full');
    if (fullEl.style.display === 'none') {
        shortEl.style.display = 'none';
        fullEl.style.display = 'inline';
        btn.textContent = 'Show less';
    } else {
        shortEl.style.display = 'inline';
        fullEl.style.display = 'none';
        btn.textContent = 'Show more';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
