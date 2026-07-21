<?php
/**
 * Victory Genomics CRM - WhatsApp Dashboard
 * Role-aware: Sales Reps see only their messages; Admin/Manager see all with user info
 * Tabs: Inbox (conversations) | All Messages | Templates
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

$pageTitle = 'WhatsApp';
$csrf_token = generateCSRFToken();
$db = Database::getInstance();
$isSalesRep = !hasRole('Sales Manager');
$isManager = hasRole('Sales Manager');   // Sales Manager or Admin
$currentUserId = getCurrentUserId();

// Role-based WHERE clause
if ($isSalesRep) {
    $repFilter = "AND (wm.user_id = $currentUserId OR wm.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = $currentUserId))";
    $leadFilter = "AND l.assigned_to = $currentUserId";
} else {
    $repFilter = '';
    $leadFilter = '';
}

// Stats (role-filtered)
try {
    $stats = [
        'sent'      => $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE direction = 'Outbound' $repFilter")->fetchColumn(),
        'received'  => $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE direction = 'Inbound' $repFilter")->fetchColumn(),
        'today'     => $db->query("SELECT COUNT(*) FROM whatsapp_messages wm WHERE DATE(wm.created_at) = CURDATE() $repFilter")->fetchColumn(),
        'templates' => 0,
    ];
} catch (Exception $e) {
    $stats = ['sent' => 0, 'received' => 0, 'today' => 0, 'templates' => 0];
}

// Conversations grouped by lead (role-filtered) — "Inbox" view
try {
    $conversations = $db->query("
        SELECT l.lead_id, l.company_name, l.contact_person, l.phone, l.mobile, l.assigned_to,
               wm.message_body as last_message,
               wm.direction as last_direction,
               wm.created_at as last_message_at,
               wm.user_id as last_sender_id,
               u.full_name as last_sender_name,
               au.full_name as assigned_user_name,
               (SELECT COUNT(*) FROM whatsapp_messages WHERE lead_id = l.lead_id AND status = 'Received' AND direction = 'Inbound') as unread_count,
               (SELECT COUNT(*) FROM whatsapp_messages WHERE lead_id = l.lead_id) as message_count
        FROM leads l
        INNER JOIN whatsapp_messages wm ON wm.lead_id = l.lead_id
        LEFT JOIN users u ON wm.user_id = u.user_id
        LEFT JOIN users au ON l.assigned_to = au.user_id
        WHERE wm.message_id = (SELECT MAX(message_id) FROM whatsapp_messages WHERE lead_id = l.lead_id)
        $leadFilter
        ORDER BY wm.created_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $conversations = [];
}

// Recent messages (role-filtered)
try {
    $recentMessages = $db->query("
        SELECT wm.*, u.full_name as user_name, l.company_name, l.contact_person, l.assigned_to,
               au.full_name as assigned_user_name
        FROM whatsapp_messages wm
        LEFT JOIN users u ON wm.user_id = u.user_id
        LEFT JOIN leads l ON wm.lead_id = l.lead_id
        LEFT JOIN users au ON l.assigned_to = au.user_id
        WHERE 1=1 $repFilter
        ORDER BY wm.created_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentMessages = [];
}

// Get users for filter dropdown (admin/manager only)
$allUsers = [];
if (!$isSalesRep) {
    try {
        $allUsers = $db->query("SELECT user_id, full_name FROM users WHERE status = 'Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* skip */ }
}

// Unmatched messages count (admin/manager only)
$unmatchedCount = 0;
$unmatchedMessages = [];
if ($isManager) {
    try {
        $unmatchedCount = (int) $db->query("SELECT COUNT(DISTINCT SUBSTRING(REPLACE(REPLACE(from_number,'+',''),'-',''), -10)) FROM whatsapp_messages WHERE lead_id IS NULL AND direction = 'Inbound'")->fetchColumn();
    } catch (Exception $e) { $unmatchedCount = 0; }
}

include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">WhatsApp Messaging</h1>
    <button class="btn btn-primary" style="background:#25D366;border-color:#25D366;" onclick="showNewMessage()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        New Message
    </button>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#25D366;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Messages Sent</div><div class="stat-value"><?php echo $stats['sent']; ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Received</div><div class="stat-value"><?php echo $stats['received']; ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Today</div><div class="stat-value"><?php echo $stats['today']; ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-primary">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        </div>
        <div class="stat-content"><div class="stat-label">Templates</div><div class="stat-value" id="tpl-count-stat">-</div></div>
    </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--color-border-light);">
    <button class="wa-tab active" onclick="switchTab('inbox',this)" style="padding:10px 20px;font-size:13px;font-weight:600;background:none;border:none;border-bottom:2px solid #25D366;margin-bottom:-2px;cursor:pointer;color:#25D366;">
        Inbox <?php if (!empty($conversations)): ?><span class="badge" style="background:#25D366;color:#fff;font-size:10px;margin-left:4px;"><?php echo count($conversations); ?></span><?php endif; ?>
    </button>
    <button class="wa-tab" onclick="switchTab('messages',this)" style="padding:10px 20px;font-size:13px;font-weight:600;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;color:var(--color-text-secondary);">
        All Messages
    </button>
    <?php if ($isManager): ?>
    <button class="wa-tab" onclick="switchTab('unmatched',this)" style="padding:10px 20px;font-size:13px;font-weight:600;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;color:var(--color-text-secondary);">
        Unmatched
        <?php if ($unmatchedCount > 0): ?>
            <span class="badge" style="background:#ff9500;color:#fff;font-size:10px;margin-left:4px;"><?php echo $unmatchedCount; ?></span>
        <?php endif; ?>
    </button>
    <?php endif; ?>
    <button class="wa-tab" onclick="switchTab('templates',this)" style="padding:10px 20px;font-size:13px;font-weight:600;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;color:var(--color-text-secondary);">
        Templates
    </button>
</div>

<!-- ========== TAB: INBOX (Conversations) ========== -->
<div id="tab-inbox">
    <?php if (empty($conversations)): ?>
        <div class="card">
            <div class="card-body" style="text-align:center;padding:40px;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="1.5" style="margin-bottom:12px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <p style="font-size:15px;font-weight:500;">No WhatsApp conversations yet</p>
                <p class="text-muted" style="font-size:13px;">Start by messaging a lead from their detail page or click "New Message" above.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body" style="padding:0;">
                <?php foreach ($conversations as $i => $conv): ?>
                    <div class="wa-inbox-item" style="display:flex;align-items:center;gap:12px;padding:14px 20px;cursor:pointer;border-bottom:1px solid var(--color-border-light);transition:background 0.15s;"
                         onmouseover="this.style.background='var(--color-bg-hover,#f5f5f7)'" onmouseout="this.style.background=''"
                         onclick="WhatsAppChat.open(<?php echo $conv['lead_id']; ?>, '<?php echo htmlspecialchars($conv['phone'] ?: $conv['mobile']); ?>', '<?php echo htmlspecialchars($conv['contact_person'] ?: $conv['company_name']); ?>')">
                        <div style="width:42px;height:42px;border-radius:50%;background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;flex-shrink:0;">
                            <?php echo getInitials($conv['contact_person'] ?: $conv['company_name']); ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <strong style="font-size:14px;"><?php echo htmlspecialchars($conv['contact_person'] ?: $conv['company_name']); ?></strong>
                                <small class="text-muted" style="flex-shrink:0;"><?php echo timeAgo($conv['last_message_at']); ?></small>
                            </div>
                            <div style="font-size:12px;color:var(--color-text-secondary);margin-top:2px;">
                                <?php echo htmlspecialchars($conv['company_name']); ?>
                                <?php if (!$isSalesRep && $conv['assigned_user_name']): ?>
                                    <span class="badge" style="background:#e8f0fe;color:#1a56db;font-size:10px;margin-left:4px;"><?php echo htmlspecialchars($conv['assigned_user_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:13px;color:var(--color-text-secondary);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?php if ($conv['last_direction'] === 'Outbound'): ?>
                                    <span style="color:#25D366;">
                                        <?php echo (!$isSalesRep && $conv['last_sender_name']) ? htmlspecialchars($conv['last_sender_name']) . ': ' : 'You: '; ?>
                                    </span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars(substr($conv['last_message'], 0, 80)); ?>
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                            <span style="font-size:11px;color:var(--color-text-muted);"><?php echo $conv['message_count']; ?> msgs</span>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <span style="background:#25D366;color:#fff;font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px;"><?php echo $conv['unread_count']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ========== TAB: ALL MESSAGES ========== -->
<div id="tab-messages" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">All Messages</h2>
            <?php if (!$isSalesRep && !empty($allUsers)): ?>
                <select id="filterUser" onchange="filterMessages()" class="form-control" style="width:auto;font-size:12px;padding:6px 8px;">
                    <option value="">All Users</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?php echo $u['user_id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table class="table" id="messagesTable">
                    <thead>
                        <tr>
                            <th>Lead</th>
                            <?php if (!$isSalesRep): ?><th>User</th><?php endif; ?>
                            <th>Direction</th>
                            <th>To/From</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentMessages)): ?>
                            <tr><td colspan="<?php echo $isSalesRep ? 6 : 7; ?>" class="text-center text-muted">No messages yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentMessages as $msg): ?>
                                <tr data-user-id="<?php echo $msg['user_id'] ?: ''; ?>">
                                    <td>
                                        <?php if ($msg['lead_id']): ?>
                                            <a href="lead-detail.php?id=<?php echo $msg['lead_id']; ?>"><?php echo htmlspecialchars($msg['company_name'] ?: $msg['contact_person'] ?: 'Lead #'.$msg['lead_id']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isSalesRep): ?>
                                        <td>
                                            <?php if ($msg['user_name']): ?>
                                                <span class="badge" style="background:#e8f0fe;color:#1a56db;font-size:10px;"><?php echo htmlspecialchars($msg['user_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:11px;">System</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><span class="badge <?php echo $msg['direction']==='Outbound'?'bg-blue-100 text-blue-800':'bg-green-100 text-green-800'; ?>"><?php echo $msg['direction']; ?></span></td>
                                    <td style="font-size:12px;"><?php echo htmlspecialchars($msg['direction']==='Outbound'? $msg['to_number'] : $msg['from_number']); ?></td>
                                    <td><small><?php echo htmlspecialchars(substr($msg['message_body'], 0, 60)); ?></small></td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'Sent' => 'bg-blue-100 text-blue-800',
                                            'Delivered' => 'bg-green-100 text-green-800',
                                            'Read' => 'bg-green-100 text-green-800',
                                            'Failed' => 'bg-red-100 text-red-800',
                                            'Received' => 'bg-gray-100 text-gray-800',
                                            'Queued' => 'bg-gray-100 text-gray-800',
                                        ];
                                        $sc = $statusColors[$msg['status']] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="badge <?php echo $sc; ?>"><?php echo $msg['status']; ?></span>
                                    </td>
                                    <td><small class="text-muted"><?php echo timeAgo($msg['created_at']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========== TAB: UNMATCHED MESSAGES (Admin/Manager only) ========== -->
<?php if ($isManager): ?>
<div id="tab-unmatched" style="display:none;">
    <div class="card" style="margin-bottom:16px;border-left:4px solid #ff9500;">
        <div class="card-body" style="padding:16px 20px;">
            <div style="display:flex;align-items:flex-start;gap:12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ff9500" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div>
                    <strong style="font-size:14px;">Unmatched Inbound Messages</strong>
                    <p style="font-size:12px;color:var(--color-text-secondary);margin:6px 0 0;">
                        These are WhatsApp messages received from phone numbers that <strong>don't match any lead</strong> in the CRM.
                        You can <strong>view the full conversation, reply directly</strong> to qualify them, and then
                        <strong>link them to an existing lead</strong> or <strong>create a new lead</strong> when ready.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Unmatched Senders <span id="unmatched-count-badge" class="badge" style="background:#ff9500;color:#fff;font-size:10px;margin-left:6px;"><?php echo $unmatchedCount; ?></span></h2>
            <button class="btn btn-sm btn-outline" onclick="loadUnmatchedMessages()" title="Refresh">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
        </div>
        <div class="card-body" id="unmatched-container">
            <div style="text-align:center;padding:30px;color:var(--color-text-secondary);">
                <div class="spinner" style="margin:0 auto 12px;width:28px;height:28px;border:3px solid var(--color-border);border-top-color:#ff9500;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                <p style="font-size:13px;">Loading unmatched messages...</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Link to Lead Modal (Admin/Manager only) -->
<?php if ($isManager): ?>
<div id="linkLeadModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="closeLinkLeadModal()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header" style="background:#ff9500;">
            <h3 style="color:#fff;">Link to Existing Lead</h3>
            <button type="button" class="btn-close" onclick="closeLinkLeadModal()" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin-bottom:12px;">
                Messages from <strong id="linkFromNumber"></strong> will be linked to the selected lead.
            </p>
            <input type="hidden" id="linkFromNumberHidden">
            <div class="form-group">
                <label class="form-label">Search Lead</label>
                <input type="text" id="linkLeadSearch" class="form-control" placeholder="Type lead name or company..."
                       oninput="searchLeadsForLink(this.value)" autocomplete="off">
            </div>
            <div id="linkLeadResults" style="max-height:200px;overflow-y:auto;border:1px solid var(--color-border-light);border-radius:8px;display:none;"></div>
            <input type="hidden" id="linkLeadId">
            <div id="linkLeadSelected" style="display:none;margin-top:8px;padding:10px 12px;background:var(--color-bg);border-radius:8px;font-size:13px;">
                <span id="linkLeadSelectedName"></span>
                <button type="button" onclick="clearLinkLead()" style="float:right;border:none;background:none;color:#ff3b30;font-size:12px;cursor:pointer;">Clear</button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeLinkLeadModal()">Cancel</button>
            <button class="btn btn-primary" style="background:#ff9500;border-color:#ff9500;" onclick="submitLinkToLead()" id="linkSubmitBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                Link Messages
            </button>
        </div>
    </div>
</div>

<!-- Create Lead from Message Modal -->
<div id="createLeadModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="closeCreateLeadModal()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header" style="background:#25D366;">
            <h3 style="color:#fff;">Create New Lead</h3>
            <button type="button" class="btn-close" onclick="closeCreateLeadModal()" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--color-text-secondary);margin-bottom:12px;">
                Create a new lead from the unmatched WhatsApp sender. All messages from this number will be automatically linked.
            </p>
            <input type="hidden" id="createLeadFromNumber">
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" id="createLeadPhone" class="form-control" readonly style="background:var(--color-bg);">
            </div>
            <div class="form-group">
                <label class="form-label">Contact Name <span style="color:#ff3b30;">*</span></label>
                <input type="text" id="createLeadName" class="form-control" placeholder="e.g. John Smith">
            </div>
            <div class="form-group">
                <label class="form-label">Company Name</label>
                <input type="text" id="createLeadCompany" class="form-control" placeholder="e.g. Acme Corp (optional)">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeCreateLeadModal()">Cancel</button>
            <button class="btn btn-primary" style="background:#25D366;border-color:#25D366;" onclick="submitCreateLead()" id="createLeadSubmitBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                Create Lead
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========== TAB: TEMPLATES (Twilio Content Templates) ========== -->
<div id="tab-templates" style="display:none;">
    <!-- Template creation guide for managers -->
    <?php if ($isManager): ?>
    <div class="card" style="margin-bottom:16px;border-left:4px solid #25D366;">
        <div class="card-body" style="padding:16px 20px;">
            <div style="display:flex;align-items:flex-start;gap:12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <div>
                    <strong style="font-size:14px;">About WhatsApp Message Templates</strong>
                    <p style="font-size:12px;color:var(--color-text-secondary);margin:6px 0 0;">
                        WhatsApp requires <strong>pre-approved templates</strong> for business-initiated messages (when the contact hasn't messaged you in the last 24 hours).
                        Templates are created here and submitted to <strong>Meta for approval</strong> (usually takes minutes to a few hours).
                        Once approved, all users can use them to start conversations with leads.
                    </p>
                    <details style="margin-top:10px;">
                        <summary style="font-size:12px;font-weight:600;cursor:pointer;color:#25D366;">Variable Reference Guide</summary>
                        <div style="margin-top:8px;font-size:12px;color:var(--color-text-secondary);line-height:1.7;">
                            <p>Use numbered placeholders <code>{{1}}</code>, <code>{{2}}</code>, etc. in your template body. Each variable needs a description.</p>
                            <table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:11px;">
                                <thead>
                                    <tr style="background:var(--color-bg);border-bottom:1px solid var(--color-border-light);">
                                        <th style="padding:6px 8px;text-align:left;">Variable</th>
                                        <th style="padding:6px 8px;text-align:left;">Suggested Use</th>
                                        <th style="padding:6px 8px;text-align:left;">Example Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr style="border-bottom:1px solid var(--color-border-light);">
                                        <td style="padding:6px 8px;"><code>{{1}}</code></td>
                                        <td style="padding:6px 8px;">Contact name (first name)</td>
                                        <td style="padding:6px 8px;">John</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--color-border-light);">
                                        <td style="padding:6px 8px;"><code>{{2}}</code></td>
                                        <td style="padding:6px 8px;">Sales rep name or message content</td>
                                        <td style="padding:6px 8px;">Sarah from Victory Genomics</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--color-border-light);">
                                        <td style="padding:6px 8px;"><code>{{3}}</code></td>
                                        <td style="padding:6px 8px;">Meeting details, date/time, etc.</td>
                                        <td style="padding:6px 8px;">Tuesday at 3pm</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="margin-top:8px;"><strong>Meta Rules:</strong></p>
                            <ul style="padding-left:18px;margin:4px 0;">
                                <li>Template must start and end with static text (not a variable)</li>
                                <li>Variables cannot be consecutive without static text between them</li>
                                <li>Max 10 emojis, no more than 2 consecutive newlines</li>
                                <li>Keep it professional and relevant to your business</li>
                            </ul>
                            <p style="margin-top:8px;"><strong>Example Template:</strong></p>
                            <div style="background:var(--color-bg);padding:10px;border-radius:6px;margin-top:4px;font-family:monospace;font-size:11px;">
                                Hello {{1}}, this is {{2}} from Victory Genomics. We noticed your interest in our genomics solutions and would love to schedule a brief call to discuss how we can help your organization. Would you be available {{3}}? Please reply to this message to let us know.
                            </div>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">WhatsApp Templates</h2>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn btn-sm btn-outline" onclick="loadDashboardTemplates()" title="Refresh">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Refresh
                </button>
                <?php if ($isManager): ?>
                    <button class="btn btn-sm btn-primary" style="background:#25D366;border-color:#25D366;" onclick="showCreateTemplate()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Create Template
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body" id="templates-container">
            <div style="text-align:center;padding:30px;color:var(--color-text-secondary);">
                <div class="spinner" style="margin:0 auto 12px;width:28px;height:28px;border:3px solid var(--color-border);border-top-color:#25D366;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                <p style="font-size:13px;">Loading templates from Twilio...</p>
            </div>
        </div>
    </div>
</div>

<!-- New Message Modal -->
<div id="newMsgModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideNewMessage()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header" style="background:#25D366;">
            <h3 style="color:#fff;">New WhatsApp Message</h3>
            <button type="button" class="btn-close" onclick="hideNewMessage()" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="tel" id="waMsgNumber" class="form-control" placeholder="+971 50 123 4567">
            </div>
            <div class="form-group">
                <label class="form-label">Message</label>
                <textarea id="waMsgBody" class="form-control" rows="4" placeholder="Type your message..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="hideNewMessage()">Cancel</button>
            <button class="btn btn-primary" style="background:#25D366;border-color:#25D366;" onclick="sendQuickMessage()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                Send
            </button>
        </div>
    </div>
</div>

<!-- Create Template Modal (Admin/Manager only) -->
<?php if ($isManager): ?>
<div id="createTplModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideCreateTemplate()"></div>
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header" style="background:#25D366;">
            <h3 style="color:#fff;">Create WhatsApp Template</h3>
            <button type="button" class="btn-close" onclick="hideCreateTemplate()" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Template Name</label>
                <input type="text" id="ctplName" class="form-control" placeholder="e.g. welcome_message (lowercase, underscores)">
                <small class="text-muted">Lowercase letters, numbers, and underscores only. This is the internal name.</small>
            </div>
            <div class="grid grid-2" style="gap:12px;">
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select id="ctplCategory" class="form-control">
                        <option value="UTILITY">Utility (transactional, updates)</option>
                        <option value="MARKETING">Marketing (promotions, offers)</option>
                    </select>
                    <small class="text-muted">Utility templates are more likely to be approved and have lower costs.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Language</label>
                    <select id="ctplLanguage" class="form-control">
                        <option value="en">English (en)</option>
                        <option value="ar">Arabic (ar)</option>
                        <option value="es">Spanish (es)</option>
                        <option value="fr">French (fr)</option>
                        <option value="de">German (de)</option>
                        <option value="pt">Portuguese (pt)</option>
                        <option value="it">Italian (it)</option>
                        <option value="tr">Turkish (tr)</option>
                        <option value="nl">Dutch (nl)</option>
                        <option value="ja">Japanese (ja)</option>
                        <option value="zh_CN">Chinese Simplified (zh_CN)</option>
                    </select>
                    <small class="text-muted">Must match the language of the template body per Meta guidelines. Arabic templates use RTL.</small>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Template Body</label>
                <textarea id="ctplBody" class="form-control" rows="5" placeholder="Hello {{1}}, this is {{2}} from Victory Genomics. We would like to discuss how our genomics solutions can benefit your organization. Would you be available for a brief call? Please reply to continue the conversation."
                    oninput="updateCreatePreview()"></textarea>
                <small class="text-muted">Use <code>{{1}}</code>, <code>{{2}}</code>, etc. for variables. Must start and end with static text.</small>
            </div>

            <!-- Dynamic variable descriptions -->
            <div id="ctplVarsContainer" style="display:none;">
                <label class="form-label" style="margin-bottom:8px;">Variable Descriptions</label>
                <div id="ctplVarsList"></div>
                <small class="text-muted">Describe what each variable represents. This helps sales reps fill them correctly.</small>
            </div>

            <!-- Live Preview -->
            <div style="margin-top:16px;">
                <label class="form-label">Preview</label>
                <div id="ctplPreview" style="background:#ECE5DD;padding:12px 16px;border-radius:8px;font-size:13px;line-height:1.5;color:#333;min-height:40px;">
                    <em class="text-muted">Start typing your template body above...</em>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="hideCreateTemplate()">Cancel</button>
            <button class="btn btn-primary" style="background:#25D366;border-color:#25D366;" onclick="submitCreateTemplate()" id="ctplSubmitBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Create &amp; Submit for Approval
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Unmatched sender cards ── */
.unmatched-sender-card {
    border: 1px solid var(--color-border-light);
    border-radius: 10px;
    margin-bottom: 10px;
    overflow: hidden;
    transition: box-shadow 0.15s;
}
.unmatched-sender-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.unmatched-sender-header {
    display: flex;
    align-items: center;
    padding: 14px 16px;
    cursor: pointer;
    transition: background 0.15s;
}
.unmatched-sender-header:hover {
    background: var(--color-bg-hover, #f5f5f7);
}
.unmatched-chevron {
    transition: transform 0.2s ease;
    flex-shrink: 0;
}
.unmatched-thread {
    border-top: 1px solid var(--color-border-light);
}
.unmatched-thread-messages {
    max-height: 320px;
    overflow-y: auto;
    padding: 12px 16px;
    background: #ECE5DD;
}
.unmatched-thread-composer {
    padding: 10px 16px;
    background: var(--color-bg-card, #fff);
    border-top: 1px solid var(--color-border-light);
}
.unmatched-reply-input {
    overflow: hidden;
}
.unmatched-thread-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 10px 16px;
    background: var(--color-bg, #f9f9fb);
    border-top: 1px solid var(--color-border-light);
}
</style>

<script>
// ─── Tab switching ───
function switchTab(tabId, btn) {
    document.querySelectorAll('[id^="tab-"]').forEach(function(el) { el.style.display = 'none'; });
    document.getElementById('tab-' + tabId).style.display = 'block';
    document.querySelectorAll('.wa-tab').forEach(function(el) {
        el.style.borderBottomColor = 'transparent';
        el.style.color = 'var(--color-text-secondary)';
    });
    btn.style.borderBottomColor = '#25D366';
    btn.style.color = '#25D366';

    // Load templates when tab is shown
    if (tabId === 'templates') {
        loadDashboardTemplates();
    }
    // Load unmatched messages when tab is shown
    if (tabId === 'unmatched' && typeof loadUnmatchedMessages === 'function') {
        loadUnmatchedMessages();
    }
}

// ─── Filter messages by user ───
function filterMessages() {
    var sel = document.getElementById('filterUser');
    if (!sel) return;
    var uid = sel.value;
    var rows = document.querySelectorAll('#messagesTable tbody tr');
    rows.forEach(function(row) {
        if (!uid) { row.style.display = ''; return; }
        row.style.display = (row.getAttribute('data-user-id') === uid) ? '' : 'none';
    });
}

// ─── New Message Modal ───
function showNewMessage() { document.getElementById('newMsgModal').style.display = 'flex'; }
function hideNewMessage() { document.getElementById('newMsgModal').style.display = 'none'; }

async function sendQuickMessage() {
    var num = document.getElementById('waMsgNumber').value.trim();
    var body = document.getElementById('waMsgBody').value.trim();
    if (!num || !body) { showNotification('Phone and message required', 'error'); return; }
    try {
        var resp = await fetch('/api/whatsapp.php?action=send', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ to_number: num, body: body, lead_id: 0 })
        });
        var data = await resp.json();
        if (data.success) {
            showNotification('Message sent!', 'success');
            hideNewMessage();
            location.reload();
        } else {
            showNotification('Error: ' + (data.message || 'Failed'), 'error');
        }
    } catch(e) { showNotification('Send failed', 'error'); }
}

// ─── TEMPLATES ───
var dashboardTemplates = [];

async function loadDashboardTemplates() {
    var container = document.getElementById('templates-container');
    if (!container) return;

    container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--color-text-secondary);">' +
        '<div class="spinner" style="margin:0 auto 12px;width:28px;height:28px;border:3px solid var(--color-border);border-top-color:#25D366;border-radius:50%;animation:spin 0.8s linear infinite;"></div>' +
        '<p style="font-size:13px;">Loading templates from Twilio...</p></div>';

    try {
        var resp = await fetch('/api/whatsapp.php?action=content_templates');
        var data = await resp.json();

        if (!data.success) {
            container.innerHTML = '<div style="text-align:center;padding:30px;color:#ff3b30;"><p>Failed to load templates: ' + escapeHtml(data.message || 'Unknown error') + '</p></div>';
            return;
        }

        dashboardTemplates = data.data || [];

        // Update stat counter
        var approvedCount = dashboardTemplates.filter(function(t) { return t.approval_status === 'approved'; }).length;
        var statEl = document.getElementById('tpl-count-stat');
        if (statEl) statEl.textContent = approvedCount;

        if (dashboardTemplates.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--color-text-secondary);">' +
                '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="1.5" style="margin-bottom:12px;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>' +
                '<p style="font-size:14px;font-weight:500;">No templates created yet</p>' +
                '<p style="font-size:12px;margin-top:4px;">Create your first WhatsApp template to start initiating conversations with leads.</p></div>';
            return;
        }

        var isManager = <?php echo $isManager ? 'true' : 'false'; ?>;
        var html = '<div class="table-container"><table class="table"><thead><tr>' +
            '<th>Template Name</th><th>Language</th><th>Category</th><th>Body Preview</th><th>Variables</th><th>Status</th><th>Created</th>' +
            (isManager ? '<th style="width:70px;text-align:center;">Actions</th>' : '') +
            '</tr></thead><tbody>';

        dashboardTemplates.forEach(function(tpl) {
            var statusClass = '', statusLabel = tpl.approval_status || 'unknown';
            switch (tpl.approval_status) {
                case 'approved':
                    statusClass = 'bg-green-100 text-green-800';
                    statusLabel = 'Approved';
                    break;
                case 'pending':
                case 'received':
                    statusClass = 'bg-yellow-100 text-yellow-800';
                    statusLabel = 'Pending';
                    break;
                case 'rejected':
                    statusClass = 'bg-red-100 text-red-800';
                    statusLabel = 'Rejected';
                    break;
                default:
                    statusClass = 'bg-gray-100 text-gray-800';
            }

            var varKeys = Object.keys(tpl.variables || {});
            var varText = varKeys.length > 0 ?
                varKeys.map(function(k) { return '{{' + k + '}} = ' + escapeHtml(tpl.variables[k] || ''); }).join(', ') :
                '<span class="text-muted">None</span>';

            var rejectionNote = (tpl.approval_status === 'rejected' && tpl.rejection_reason) ?
                '<br><small style="color:#ff3b30;">' + escapeHtml(tpl.rejection_reason) + '</small>' : '';

            var createdDate = tpl.created_at ? new Date(tpl.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : '-';

            var deleteBtn = isManager ?
                '<td style="text-align:center;">' +
                    '<button class="btn btn-sm" style="background:none;border:1px solid #ff3b30;color:#ff3b30;padding:4px 8px;font-size:11px;cursor:pointer;border-radius:6px;" ' +
                        'onclick="deleteTemplate(\'' + escapeHtml(tpl.content_sid) + '\', \'' + escapeHtml(tpl.friendly_name) + '\')" ' +
                        'title="Delete this template">' +
                        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:2px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                        'Delete' +
                    '</button>' +
                '</td>' : '';

            var langLabel = tpl.language || 'en';
            var langBadge = langLabel === 'ar' ?
                '<span class="badge" style="background:#fff3e0;color:#e65100;font-size:10px;">AR</span>' :
                '<span class="badge" style="background:#e3f2fd;color:#1565c0;font-size:10px;">' + langLabel.toUpperCase() + '</span>';

            html += '<tr>' +
                '<td><strong>' + escapeHtml(tpl.friendly_name) + '</strong><br><small class="text-muted" style="font-size:10px;">' + escapeHtml(tpl.content_sid) + '</small></td>' +
                '<td>' + langBadge + '</td>' +
                '<td><span class="badge" style="background:#e8f5e9;color:#2e7d32;font-size:10px;">' + escapeHtml(tpl.category || 'UTILITY') + '</span></td>' +
                '<td style="max-width:300px;"><small>' + escapeHtml((tpl.body || '').substring(0, 100)) + ((tpl.body || '').length > 100 ? '...' : '') + '</small></td>' +
                '<td style="font-size:11px;">' + varText + '</td>' +
                '<td><span class="badge ' + statusClass + '">' + statusLabel + '</span>' + rejectionNote + '</td>' +
                '<td><small class="text-muted">' + createdDate + '</small></td>' +
                deleteBtn +
                '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;

    } catch (e) {
        container.innerHTML = '<div style="text-align:center;padding:30px;color:#ff3b30;"><p>Failed to load templates. Please try again.</p></div>';
        console.error('Template load error:', e);
    }
}

// ─── Create Template Modal ───
function showCreateTemplate() {
    document.getElementById('createTplModal').style.display = 'flex';
    // Reset form
    document.getElementById('ctplName').value = '';
    document.getElementById('ctplCategory').value = 'UTILITY';
    document.getElementById('ctplLanguage').value = 'en';
    document.getElementById('ctplBody').value = '';
    document.getElementById('ctplVarsContainer').style.display = 'none';
    document.getElementById('ctplVarsList').innerHTML = '';
    document.getElementById('ctplPreview').innerHTML = '<em class="text-muted">Start typing your template body above...</em>';
}
function hideCreateTemplate() {
    document.getElementById('createTplModal').style.display = 'none';
}

function updateCreatePreview() {
    var body = document.getElementById('ctplBody').value;
    var previewEl = document.getElementById('ctplPreview');
    var varsContainer = document.getElementById('ctplVarsContainer');
    var varsList = document.getElementById('ctplVarsList');

    if (!body.trim()) {
        previewEl.innerHTML = '<em class="text-muted">Start typing your template body above...</em>';
        varsContainer.style.display = 'none';
        return;
    }

    // Find all {{N}} variables
    var matches = body.match(/\{\{(\d+)\}\}/g);
    var uniqueVars = [];
    if (matches) {
        matches.forEach(function(m) {
            if (uniqueVars.indexOf(m) === -1) uniqueVars.push(m);
        });
    }

    // Show/update variable description fields
    if (uniqueVars.length > 0) {
        varsContainer.style.display = 'block';
        var existingInputs = {};
        varsList.querySelectorAll('input').forEach(function(inp) {
            existingInputs[inp.getAttribute('data-var-key')] = inp.value;
        });

        var varsHtml = '';
        uniqueVars.forEach(function(v) {
            var key = v.replace(/[{}]/g, '');
            var existingVal = existingInputs[key] || '';
            varsHtml += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">' +
                '<code style="flex-shrink:0;font-size:12px;background:var(--color-bg);padding:3px 8px;border-radius:4px;">' + v + '</code>' +
                '<input type="text" class="form-control" data-var-key="' + key + '" value="' + escapeHtml(existingVal) + '" placeholder="Description (e.g. Contact name, Rep name, Meeting time)" style="font-size:12px;padding:6px 10px;">' +
                '</div>';
        });
        varsList.innerHTML = varsHtml;
    } else {
        varsContainer.style.display = 'none';
    }

    // Preview with highlighted variables
    var preview = escapeHtml(body);
    if (uniqueVars.length > 0) {
        uniqueVars.forEach(function(v) {
            var escaped = v.replace(/[{}]/g, function(m) { return '&#' + m.charCodeAt(0) + ';'; });
            preview = preview.replace(new RegExp(escaped, 'g'),
                '<span style="background:#25D366;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;">' + v + '</span>');
        });
    }
    previewEl.innerHTML = preview;
}

async function submitCreateTemplate() {
    var name = document.getElementById('ctplName').value.trim();
    var category = document.getElementById('ctplCategory').value;
    var body = document.getElementById('ctplBody').value.trim();
    var language = document.getElementById('ctplLanguage').value;

    if (!name) { showNotification('Template name is required', 'error'); return; }
    if (!body) { showNotification('Template body is required', 'error'); return; }

    // Validate body starts and ends with static text
    if (/^\{\{/.test(body)) {
        showNotification('Template body must start with static text, not a variable', 'error');
        return;
    }
    if (/\}\}$/.test(body.trim())) {
        showNotification('Template body must end with static text, not a variable', 'error');
        return;
    }

    // Collect variable descriptions
    var variables = {};
    var varInputs = document.querySelectorAll('#ctplVarsList input');
    var allVarsFilled = true;
    varInputs.forEach(function(inp) {
        var key = inp.getAttribute('data-var-key');
        var desc = inp.value.trim();
        if (!desc) {
            allVarsFilled = false;
            inp.style.borderColor = '#ff3b30';
        } else {
            inp.style.borderColor = '';
            variables[key] = desc;
        }
    });

    if (!allVarsFilled) {
        showNotification('Please describe all template variables', 'error');
        return;
    }

    var btn = document.getElementById('ctplSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:6px;"></span> Submitting...';

    try {
        var resp = await fetch('/api/whatsapp.php?action=create_content_template', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                name: name,
                category: category,
                language: language,
                body: body,
                variables: variables
            })
        });
        var data = await resp.json();
        if (data.success) {
            showNotification('Template "' + data.name + '" created and submitted for Meta approval (status: ' + data.approval_status + ')!', 'success');
            hideCreateTemplate();
            // Reload templates
            loadDashboardTemplates();
        } else {
            showNotification('Error: ' + (data.message || 'Failed to create template'), 'error');
        }
    } catch(e) {
        showNotification('Failed to create template', 'error');
        console.error('Create template error:', e);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Create &amp; Submit for Approval';
    }
}

// ─── Delete Template ───
async function deleteTemplate(contentSid, templateName) {
    if (!confirm('Are you sure you want to delete the template "' + templateName + '"?\n\nThis will permanently remove it from Twilio and Meta/WhatsApp. This action cannot be undone.')) {
        return;
    }

    // Second confirmation for extra safety
    if (!confirm('Final confirmation: Delete "' + templateName + '" (SID: ' + contentSid + ')?\n\nClick OK to permanently delete.')) {
        return;
    }

    // Find and disable the delete button
    var buttons = document.querySelectorAll('#templates-container button');
    buttons.forEach(function(btn) {
        if (btn.getAttribute('onclick') && btn.getAttribute('onclick').indexOf(contentSid) !== -1) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" style="display:inline-block;width:12px;height:12px;border:2px solid rgba(255,59,48,0.3);border-top-color:#ff3b30;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;"></span>';
        }
    });

    try {
        var resp = await fetch('/api/whatsapp.php?action=delete_content_template', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ content_sid: contentSid })
        });
        var data = await resp.json();
        if (data.success) {
            showNotification('Template "' + templateName + '" deleted successfully.', 'success');
            // Reload the templates list
            loadDashboardTemplates();
        } else {
            showNotification('Error: ' + (data.message || 'Failed to delete template'), 'error');
            // Re-enable buttons
            loadDashboardTemplates();
        }
    } catch(e) {
        showNotification('Failed to delete template', 'error');
        console.error('Delete template error:', e);
        loadDashboardTemplates();
    }
}

// ─── Load templates on page load (lazy) ───
document.addEventListener('DOMContentLoaded', function() {
    // Pre-fetch template count for the stat card
    fetch('/api/whatsapp.php?action=content_templates')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var approved = (data.data || []).filter(function(t) { return t.approval_status === 'approved'; });
                var statEl = document.getElementById('tpl-count-stat');
                if (statEl) statEl.textContent = approved.length;
            }
        })
        .catch(function() {});
});

// ─── UNMATCHED MESSAGES (Admin/Manager) ───
<?php if ($isManager): ?>

var unmatchedData = [];

async function loadUnmatchedMessages() {
    var container = document.getElementById('unmatched-container');
    if (!container) return;

    container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--color-text-secondary);">' +
        '<div class="spinner" style="margin:0 auto 12px;width:28px;height:28px;border:3px solid var(--color-border);border-top-color:#ff9500;border-radius:50%;animation:spin 0.8s linear infinite;"></div>' +
        '<p style="font-size:13px;">Loading unmatched messages...</p></div>';

    try {
        var resp = await fetch('/api/whatsapp.php?action=unmatched_messages');
        var data = await resp.json();

        if (!data.success) {
            container.innerHTML = '<div style="text-align:center;padding:30px;color:#ff3b30;"><p>' + escapeHtml(data.message || 'Error') + '</p></div>';
            return;
        }

        unmatchedData = data.data || [];

        var badge = document.getElementById('unmatched-count-badge');
        if (badge) badge.textContent = unmatchedData.length;

        if (unmatchedData.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--color-text-secondary);">' +
                '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="1.5" style="margin-bottom:12px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                '<p style="font-size:15px;font-weight:500;">All messages are matched!</p>' +
                '<p style="font-size:13px;margin-top:4px;">No unmatched inbound WhatsApp messages. Great job!</p></div>';
            return;
        }

        var html = '';

        unmatchedData.forEach(function(msg, idx) {
            var phone = escapeHtml(msg.from_number || '');
            var profileName = escapeHtml(msg.profile_name || '');
            var displayName = profileName || phone;
            var body = escapeHtml((msg.message_body || '').substring(0, 80));
            if ((msg.message_body || '').length > 80) body += '...';
            var count = msg.thread_count || 1;
            var timeStr = msg.created_at ? new Date(msg.created_at).toLocaleString('en-US', {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'}) : '-';
            var safePhone = escapeHtml(msg.from_number || '');

            // Build avatar: initials if profile name exists, otherwise person icon
            var avatarContent = '';
            if (profileName) {
                var nameParts = profileName.split(' ');
                var initials = nameParts[0].charAt(0).toUpperCase();
                if (nameParts.length > 1) initials += nameParts[nameParts.length - 1].charAt(0).toUpperCase();
                avatarContent = initials;
            } else {
                avatarContent = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
            }

            html += '<div class="unmatched-sender-card" id="unmatched-card-' + idx + '">' +
                '<div class="unmatched-sender-header" onclick="toggleUnmatchedThread(' + idx + ', \'' + safePhone + '\')">' +
                    '<div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;">' +
                        '<div style="width:42px;height:42px;border-radius:50%;background:' + (profileName ? '#25D366' : '#ff9500') + ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;flex-shrink:0;">' +
                            avatarContent +
                        '</div>' +
                        '<div style="flex:1;min-width:0;">' +
                            '<div style="display:flex;justify-content:space-between;align-items:center;">' +
                                '<div style="min-width:0;">' +
                                    '<strong style="font-size:14px;">' + displayName + '</strong>' +
                                    (profileName ? '<span style="font-size:12px;color:var(--color-text-secondary);margin-left:8px;">' + phone + '</span>' : '') +
                                '</div>' +
                                '<small class="text-muted" style="flex-shrink:0;">' + timeStr + '</small>' +
                            '</div>' +
                            '<div style="font-size:13px;color:var(--color-text-secondary);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
                                body +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;margin-left:8px;">' +
                            '<span class="badge" style="background:#ff9500;color:#fff;font-size:10px;">' + count + ' msg' + (count > 1 ? 's' : '') + '</span>' +
                            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-text-secondary)" stroke-width="2" class="unmatched-chevron" id="chevron-' + idx + '"><polyline points="6 9 12 15 18 9"/></svg>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="unmatched-thread" id="unmatched-thread-' + idx + '" style="display:none;">' +
                    '<div class="unmatched-thread-messages" id="unmatched-messages-' + idx + '">' +
                        '<div style="text-align:center;padding:20px;color:var(--color-text-secondary);">' +
                            '<div class="spinner" style="margin:0 auto 8px;width:22px;height:22px;border:2px solid var(--color-border);border-top-color:#ff9500;border-radius:50%;animation:spin 0.8s linear infinite;"></div>' +
                            '<small>Loading messages...</small>' +
                        '</div>' +
                    '</div>' +
                    '<div class="unmatched-thread-composer">' +
                        '<div style="display:flex;gap:8px;align-items:flex-end;">' +
                            '<textarea class="form-control unmatched-reply-input" id="unmatched-reply-' + idx + '" placeholder="Type a reply..." rows="1" ' +
                                'oninput="this.style.height=\'auto\';this.style.height=Math.min(this.scrollHeight,80)+\'px\'" ' +
                                'onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();sendUnmatchedReply(' + idx + ',\'' + safePhone + '\');}" ' +
                                'style="font-size:13px;resize:none;"></textarea>' +
                            '<button class="btn btn-sm" style="background:#25D366;color:#fff;border:none;flex-shrink:0;height:36px;padding:0 12px;" onclick="sendUnmatchedReply(' + idx + ',\'' + safePhone + '\')" title="Send reply">' +
                                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="unmatched-thread-actions">' +
                        '<button class="btn btn-sm btn-outline" style="font-size:11px;" onclick="openLinkLeadModal(\'' + safePhone + '\')" title="Link to existing lead">' +
                            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' +
                            'Link to Lead' +
                        '</button>' +
                        '<button class="btn btn-sm btn-primary" style="background:#25D366;border-color:#25D366;font-size:11px;" onclick="openCreateLeadModal(\'' + safePhone + '\')" title="Create new lead">' +
                            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>' +
                            'Create Lead' +
                        '</button>' +
                        '<button class="btn btn-sm btn-outline" style="font-size:11px;margin-left:auto;" onclick="WhatsAppChat.open(0, \'' + safePhone + '\', \'' + (profileName || phone) + '\')" title="Open full chat panel">' +
                            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' +
                            'Full Chat' +
                        '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        });

        container.innerHTML = html;

    } catch (e) {
        container.innerHTML = '<div style="text-align:center;padding:30px;color:#ff3b30;"><p>Failed to load unmatched messages. Please try again.</p></div>';
        console.error('Unmatched load error:', e);
    }
}

// ── Toggle unmatched thread (expand/collapse) ──
var unmatchedOpenIdx = null;

async function toggleUnmatchedThread(idx, phone) {
    var thread = document.getElementById('unmatched-thread-' + idx);
    var chevron = document.getElementById('chevron-' + idx);
    if (!thread) return;

    // If already open, collapse it
    if (thread.style.display !== 'none') {
        thread.style.display = 'none';
        if (chevron) chevron.style.transform = '';
        unmatchedOpenIdx = null;
        return;
    }

    // Collapse any previously open thread
    if (unmatchedOpenIdx !== null && unmatchedOpenIdx !== idx) {
        var prevThread = document.getElementById('unmatched-thread-' + unmatchedOpenIdx);
        var prevChevron = document.getElementById('chevron-' + unmatchedOpenIdx);
        if (prevThread) prevThread.style.display = 'none';
        if (prevChevron) prevChevron.style.transform = '';
    }

    // Expand this thread
    thread.style.display = 'block';
    if (chevron) chevron.style.transform = 'rotate(180deg)';
    unmatchedOpenIdx = idx;

    // Load the conversation
    await loadUnmatchedThread(idx, phone);
}

async function loadUnmatchedThread(idx, phone) {
    var container = document.getElementById('unmatched-messages-' + idx);
    if (!container) return;

    container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--color-text-secondary);">' +
        '<div class="spinner" style="margin:0 auto 8px;width:22px;height:22px;border:2px solid var(--color-border);border-top-color:#ff9500;border-radius:50%;animation:spin 0.8s linear infinite;"></div>' +
        '<small>Loading messages...</small></div>';

    try {
        var resp = await fetch('/api/whatsapp.php?action=unmatched_chat_history&phone=' + encodeURIComponent(phone));
        var data = await resp.json();

        if (!data.success || !data.data || data.data.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--color-text-secondary);">' +
                '<small>No messages found</small></div>';
            return;
        }

        var html = '';
        data.data.forEach(function(msg) {
            var isOut = msg.direction === 'Outbound';
            var time = msg.created_at ? new Date(msg.created_at).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'}) : '';
            var dateStr = msg.created_at ? new Date(msg.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric'}) : '';
            var msgBody = escapeHtml(msg.message_body || '');
            var senderLabel = '';
            if (isOut && msg.user_name) {
                senderLabel = '<div style="font-size:10px;font-weight:600;color:#25D366;margin-bottom:2px;">' + escapeHtml(msg.user_name) + '</div>';
            } else if (!isOut && msg.profile_name) {
                senderLabel = '<div style="font-size:10px;font-weight:600;color:#ff9500;margin-bottom:2px;">' + escapeHtml(msg.profile_name) + '</div>';
            }

            html += '<div style="display:flex;justify-content:' + (isOut ? 'flex-end' : 'flex-start') + ';margin-bottom:8px;">' +
                '<div style="max-width:80%;padding:8px 12px;border-radius:' + (isOut ? '12px 12px 4px 12px' : '12px 12px 12px 4px') + ';' +
                    'background:' + (isOut ? '#DCF8C6' : '#fff') + ';' +
                    'box-shadow:0 1px 2px rgba(0,0,0,0.06);font-size:13px;line-height:1.4;">' +
                    senderLabel +
                    '<div>' + msgBody + '</div>' +
                    '<div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:4px;">' +
                        '<span style="font-size:10px;color:#86868b;">' + dateStr + ' ' + time + '</span>' +
                        (isOut ? getUnmatchedStatusIcon(msg.status) : '') +
                    '</div>' +
                '</div>' +
            '</div>';
        });

        container.innerHTML = html;
        container.scrollTop = container.scrollHeight;
    } catch (e) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:#ff3b30;"><small>Failed to load messages</small></div>';
        console.error('Unmatched thread load error:', e);
    }
}

function getUnmatchedStatusIcon(status) {
    switch(status) {
        case 'Sent':      return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#86868b" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
        case 'Delivered':  return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0071e3" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
        case 'Read':       return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#34c759" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
        case 'Failed':     return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#ff3b30" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/></svg>';
        default:           return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#86868b" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
    }
}

async function sendUnmatchedReply(idx, phone) {
    var input = document.getElementById('unmatched-reply-' + idx);
    if (!input) return;
    var body = input.value.trim();
    if (!body) return;

    // Disable input while sending
    input.disabled = true;
    input.value = '';

    // Optimistic: add the message to the thread
    var container = document.getElementById('unmatched-messages-' + idx);
    if (container) {
        var now = new Date();
        var time = now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
        var dateStr = now.toLocaleDateString('en-US', {month:'short', day:'numeric'});
        container.insertAdjacentHTML('beforeend',
            '<div style="display:flex;justify-content:flex-end;margin-bottom:8px;">' +
                '<div style="max-width:80%;padding:8px 12px;border-radius:12px 12px 4px 12px;background:#DCF8C6;box-shadow:0 1px 2px rgba(0,0,0,0.06);font-size:13px;line-height:1.4;">' +
                    '<div>' + escapeHtml(body) + '</div>' +
                    '<div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:4px;">' +
                        '<span style="font-size:10px;color:#86868b;">' + dateStr + ' ' + time + '</span>' +
                        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#86868b" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        container.scrollTop = container.scrollHeight;
    }

    try {
        var resp = await fetch('/api/whatsapp.php?action=send', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ to_number: phone, body: body, lead_id: 0 })
        });
        var data = await resp.json();
        if (data.success) {
            showNotification('Reply sent!', 'success');
        } else {
            showNotification('Error: ' + (data.message || 'Failed to send'), 'error');
        }
    } catch(e) {
        showNotification('Failed to send reply', 'error');
        console.error('Send reply error:', e);
    } finally {
        input.disabled = false;
        input.focus();
    }
}

// ── Link to Lead Modal ──
function openLinkLeadModal(fromNumber) {
    document.getElementById('linkFromNumber').textContent = fromNumber;
    document.getElementById('linkFromNumberHidden').value = fromNumber;
    document.getElementById('linkLeadSearch').value = '';
    document.getElementById('linkLeadId').value = '';
    document.getElementById('linkLeadResults').style.display = 'none';
    document.getElementById('linkLeadResults').innerHTML = '';
    document.getElementById('linkLeadSelected').style.display = 'none';
    document.getElementById('linkLeadModal').style.display = 'flex';
}
function closeLinkLeadModal() { document.getElementById('linkLeadModal').style.display = 'none'; }

var searchLeadTimeout = null;
function searchLeadsForLink(query) {
    clearTimeout(searchLeadTimeout);
    var resultsDiv = document.getElementById('linkLeadResults');
    if (query.length < 2) { resultsDiv.style.display = 'none'; return; }

    searchLeadTimeout = setTimeout(async function() {
        try {
            // Use a simple search via the leads API
            var resp = await fetch('/api/leads.php?action=search&q=' + encodeURIComponent(query));
            var data = await resp.json();
            if (!data.success || !data.data || data.data.length === 0) {
                resultsDiv.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--color-text-secondary);">No leads found</div>';
                resultsDiv.style.display = 'block';
                return;
            }
            var html = '';
            data.data.forEach(function(lead) {
                html += '<div style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--color-border-light);font-size:13px;transition:background 0.1s;" ' +
                    'onmouseover="this.style.background=\'var(--color-bg-hover,#f5f5f7)\'" onmouseout="this.style.background=\'\'" ' +
                    'onclick="selectLinkLead(' + lead.lead_id + ',\'' + escapeHtml(lead.contact_person || '') + '\',\'' + escapeHtml(lead.company_name || '') + '\')">' +
                    '<strong>' + escapeHtml(lead.contact_person || 'N/A') + '</strong> — ' + escapeHtml(lead.company_name || '') +
                    '<br><small class="text-muted">' + escapeHtml(lead.phone || lead.mobile || '') + '</small>' +
                    '</div>';
            });
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        } catch(e) {
            resultsDiv.innerHTML = '<div style="padding:12px;font-size:12px;color:#ff3b30;">Search failed</div>';
            resultsDiv.style.display = 'block';
        }
    }, 300);
}

function selectLinkLead(leadId, name, company) {
    document.getElementById('linkLeadId').value = leadId;
    document.getElementById('linkLeadSelectedName').textContent = name + ' (' + company + ')';
    document.getElementById('linkLeadSelected').style.display = 'block';
    document.getElementById('linkLeadResults').style.display = 'none';
    document.getElementById('linkLeadSearch').value = name;
}

function clearLinkLead() {
    document.getElementById('linkLeadId').value = '';
    document.getElementById('linkLeadSelectedName').textContent = '';
    document.getElementById('linkLeadSelected').style.display = 'none';
    document.getElementById('linkLeadSearch').value = '';
}

async function submitLinkToLead() {
    var fromNumber = document.getElementById('linkFromNumberHidden').value;
    var leadId = document.getElementById('linkLeadId').value;
    if (!leadId) { showNotification('Please select a lead', 'error'); return; }

    var btn = document.getElementById('linkSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Linking...';

    try {
        var resp = await fetch('/api/whatsapp.php?action=link_to_lead', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ from_number: fromNumber, lead_id: parseInt(leadId) })
        });
        var data = await resp.json();
        if (data.success) {
            showNotification(data.message, 'success');
            closeLinkLeadModal();
            loadUnmatchedMessages();
        } else {
            showNotification('Error: ' + (data.message || 'Failed'), 'error');
        }
    } catch(e) { showNotification('Link failed', 'error'); }
    finally {
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Link Messages';
    }
}

// ── Create Lead from Message Modal ──
function openCreateLeadModal(fromNumber) {
    document.getElementById('createLeadFromNumber').value = fromNumber;
    document.getElementById('createLeadPhone').value = fromNumber;
    document.getElementById('createLeadCompany').value = '';

    // Pre-fill contact name from WhatsApp profile name if available
    var prefillName = '';
    if (unmatchedData && unmatchedData.length > 0) {
        var match = unmatchedData.find(function(m) { return m.from_number === fromNumber; });
        if (match && match.profile_name) {
            prefillName = match.profile_name;
        }
    }
    document.getElementById('createLeadName').value = prefillName;

    document.getElementById('createLeadModal').style.display = 'flex';
}
function closeCreateLeadModal() { document.getElementById('createLeadModal').style.display = 'none'; }

async function submitCreateLead() {
    var fromNumber = document.getElementById('createLeadFromNumber').value;
    var name = document.getElementById('createLeadName').value.trim();
    var company = document.getElementById('createLeadCompany').value.trim();

    if (!name) { showNotification('Contact name is required', 'error'); return; }

    var btn = document.getElementById('createLeadSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Creating...';

    try {
        var resp = await fetch('/api/whatsapp.php?action=create_lead_from_message', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ from_number: fromNumber, contact_person: name, company_name: company })
        });
        var data = await resp.json();
        if (data.success) {
            showNotification(data.message, 'success');
            closeCreateLeadModal();
            loadUnmatchedMessages();
        } else {
            showNotification('Error: ' + (data.message || 'Failed'), 'error');
        }
    } catch(e) { showNotification('Create lead failed', 'error'); }
    finally {
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg> Create Lead';
    }
}

<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
