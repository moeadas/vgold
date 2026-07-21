<?php if (!isset($pageTitle)) $pageTitle = 'Victory Genomics CRM'; ?>
<?php
// Load active users for admin switch-user dropdown
$_switchableUsers = [];
$_isAdminOrImpersonating = hasRole('Admin') || isImpersonating();
if ($_isAdminOrImpersonating) {
    try {
        $_suDb = Database::getInstance()->getConnection();
        $_switchableUsers = $_suDb->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ffffff">
    <title><?php echo htmlspecialchars($pageTitle); ?> — <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
    /* ── Notification Bell ────────────────────── */
    .notif-bell-wrap{position:fixed;top:12px;right:16px;z-index:1050;}
    .notif-bell-btn{background:#fff;border:1px solid #e5e5e7;border-radius:50%;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:box-shadow .15s;}
    .notif-bell-btn:hover{box-shadow:0 2px 8px rgba(0,0,0,.14);}
    .notif-bell-btn svg{color:#1d1d1f;}
    .notif-badge{position:absolute;top:-4px;right:-4px;background:#ff3b30;color:#fff;font-size:10px;font-weight:700;line-height:1;padding:3px 5px;border-radius:10px;min-width:16px;text-align:center;}
    .notif-panel{position:absolute;top:46px;right:0;width:360px;max-height:480px;background:#fff;border:1px solid #e5e5e7;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.14);overflow:hidden;display:flex;flex-direction:column;}
    .notif-panel-header{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #f0f0f2;font-size:14px;}
    .notif-mark-all{background:none;border:none;color:#0071e3;font-size:12px;cursor:pointer;font-weight:500;}
    .notif-mark-all:hover{text-decoration:underline;}
    .notif-panel-body{overflow-y:auto;max-height:400px;flex:1;}
    .notif-item{display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid #f5f5f7;cursor:pointer;transition:background .1s;}
    .notif-item:hover{background:#f5f5f7;}
    .notif-item.unread{background:#f0f7ff;}
    .notif-item.unread:hover{background:#e5f0fb;}
    .notif-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;}
    .notif-icon.wa_inbound{background:#dcf8c6;color:#25D366;}
    .notif-icon.wa_unmatched{background:#fff3cd;color:#ff9500;}
    .notif-icon.lead_assigned{background:#e8f4fd;color:#0071e3;}
    .notif-icon.system{background:#f0f0f2;color:#86868b;}
    .notif-content{flex:1;min-width:0;}
    .notif-title{font-size:13px;font-weight:600;color:#1d1d1f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .notif-body{font-size:12px;color:#86868b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .notif-time{font-size:10px;color:#aeaeb2;margin-top:3px;}
    .notif-empty{text-align:center;padding:40px 20px;color:#86868b;font-size:13px;}
    .notif-dot{width:8px;height:8px;border-radius:50%;background:#0071e3;flex-shrink:0;margin-top:4px;}
    @media(max-width:768px){.notif-bell-wrap{top:10px;right:56px;} .notif-panel{width:calc(100vw - 24px);right:-40px;}}
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleMobileSidebar()" aria-label="Toggle menu">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5h12M3 9h12M3 13h12"/></svg>
    </button>

    <!-- Notification Bell (top-right) -->
    <div class="notif-bell-wrap" id="notifBellWrap">
        <button class="notif-bell-btn" id="notifBellBtn" onclick="toggleNotifPanel()" aria-label="Notifications">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
        </button>
        <div class="notif-panel" id="notifPanel" style="display:none;">
            <div class="notif-panel-header">
                <strong>Notifications</strong>
                <button class="notif-mark-all" onclick="markAllNotifRead()">Mark all read</button>
            </div>
            <div class="notif-panel-body" id="notifPanelBody">
                <div class="notif-empty">No notifications</div>
            </div>
        </div>
    </div>

    <!-- Sidebar backdrop for mobile -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleMobileSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="/assets/images/VG%20logo.svg" alt="Victory Genomics" class="sidebar-logo-img">
        </div>

        <ul class="nav-menu">
            <li class="nav-item">
                <a href="/dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/leads.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leads.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Leads</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/interactions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'interactions.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>Interactions</span>
                </a>
            </li>
            <?php if (hasRole('Sales Manager')): ?>
            <li class="nav-item">
                <a href="/pages/proposals.php" class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['proposals.php','proposal-form.php']) ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span>Proposals</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/reports.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/export.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'export.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Export Data</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('Sales Manager')): ?>
            <li><hr class="nav-divider"></li>
            <li class="nav-section-label">Email Marketing</li>
            <li class="nav-item">
                <a href="/pages/email-campaigns.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'email-campaigns.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                    <span>Email Campaigns</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/email-templates.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'email-templates.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    <span>Templates</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/email-lists.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'email-lists.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Email Audiences</span>
                </a>
            </li>
            <?php endif; ?>

            <li><hr class="nav-divider"></li>
            <li class="nav-section-label">Communications</li>
            <li class="nav-item">
                <a href="/pages/voip-dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'voip-dashboard.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span>VoIP Calls</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/pages/whatsapp-dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'whatsapp-dashboard.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <span>WhatsApp</span>
                </a>
            </li>

            <?php if (hasRole('Sales Manager')): ?>
            <li><hr class="nav-divider"></li>
            <li class="nav-section-label">Automation</li>
            <li class="nav-item">
                <a href="/pages/automation.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'automation.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                    <span>Workflows</span>
                </a>
            </li>
            <?php endif; ?>

            <li><hr class="nav-divider"></li>
            <li class="nav-section-label">Knowledge Hub</li>
            <li class="nav-item">
                <a href="/pages/quick-guides.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'quick-guides.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    <span>Quick Guides</span>
                </a>
            </li>

            <?php if (hasRole('Admin')): ?>
                <li><hr class="nav-divider"></li>
                <li class="nav-item">
                    <a href="/pages/users.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/pages/settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($_isAdminOrImpersonating && !empty($_switchableUsers)): ?>
            <li><hr class="nav-divider"></li>
            <li class="nav-section-label">Switch User</li>
            <li class="nav-item" style="padding:0 12px 4px;">
                <select id="switchUserSelect" class="form-control" style="font-size:12px;padding:6px 8px;width:100%;"
                        onchange="handleSwitchUser(this.value)">
                    <option value="">-- View as user --</option>
                    <?php foreach ($_switchableUsers as $_su): ?>
                        <?php if ($_su['user_id'] == $_SESSION['user_id']) continue; ?>
                        <option value="<?php echo $_su['user_id']; ?>">
                            <?php echo htmlspecialchars($_su['full_name']); ?> (<?php echo htmlspecialchars($_su['role']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </li>
            <?php endif; ?>

            <li class="nav-spacer"><hr class="nav-divider"></li>
            <li class="nav-item">
                <a href="/pages/profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/logout.php" class="nav-link" style="color:var(--color-danger);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>Logout</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?php echo getInitials($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                    <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Hidden CSRF for switch-user JS -->
    <input type="hidden" name="csrf_token" id="globalCsrfToken" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">

    <!-- Main Content -->
    <main class="main-content">
    <?php if (isImpersonating()): ?>
        <?php $_origAdmin = getOriginalAdmin(); ?>
        <div id="impersonationBanner" style="background:linear-gradient(135deg,#ff9500,#ff6b00);color:#fff;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:500;border-radius:8px;margin:0 0 16px 0;">
            <span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:middle;margin-right:6px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Viewing as <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)
                &mdash; logged in as <?php echo htmlspecialchars($_origAdmin['full_name']); ?>
            </span>
            <button onclick="handleSwitchBack()" style="background:#fff;color:#ff6b00;border:none;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">
                Switch Back
            </button>
        </div>
    <?php endif; ?>
