<?php
/**
 * Victory Genomics CRM - Data Export Page
 * Export leads, interactions, WhatsApp messages, VoIP calls
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

// Only admins and sales managers
if (!hasRole('Admin') && !hasRole('Sales Manager')) {
    $_SESSION['error'] = 'You do not have permission to export data.';
    header('Location: /dashboard.php');
    exit;
}

$db = Database::getInstance();

// Get counts for display
$counts = [
    'leads'        => $db->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
    'interactions'  => $db->query("SELECT COUNT(*) FROM interactions")->fetchColumn(),
    'whatsapp'      => $db->query("SELECT COUNT(*) FROM whatsapp_messages")->fetchColumn(),
    'voip'          => $db->query("SELECT COUNT(*) FROM voip_calls")->fetchColumn(),
];

$pageTitle = 'Data Export';
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Data Export</h1>
        <p class="text-muted">Export your CRM data including leads, interactions, WhatsApp conversations, and VoIP call history</p>
    </div>
</div>

<!-- Export Stats -->
<div class="grid grid-4 mb-2">
    <div class="stat-card">
        <div class="stat-icon bg-gradient-primary">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($counts['leads']); ?></div>
            <div class="stat-label">Leads</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($counts['interactions']); ?></div>
            <div class="stat-label">Interactions</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($counts['whatsapp']); ?></div>
            <div class="stat-label">WhatsApp Messages</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-warning">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($counts['voip']); ?></div>
            <div class="stat-label">VoIP Calls</div>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <!-- Full Export -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title" style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Full Database Export
            </h3>
        </div>
        <div class="card-body">
            <p style="margin-bottom:16px;color:var(--color-text-secondary);font-size:13px;">
                Export the entire CRM database including all leads, interactions, WhatsApp conversations, and VoIP call history in a single download.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="/api/export.php?scope=all&format=csv" class="btn btn-primary" style="flex:1;text-align:center;min-width:140px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download CSV Archive
                </a>
                <a href="/api/export.php?scope=all&format=json" class="btn btn-outline" style="flex:1;text-align:center;min-width:140px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download JSON
                </a>
            </div>
            <div style="margin-top:12px;padding:10px;background:var(--color-bg-secondary);border-radius:8px;font-size:12px;color:var(--color-text-secondary);">
                <strong>CSV Archive</strong> contains 4 separate CSV files: leads.csv, interactions.csv, whatsapp_messages.csv, voip_calls.csv — ideal for Excel/Google Sheets.<br>
                <strong>JSON</strong> is a single file with all data — ideal for backups and programmatic use.
            </div>
        </div>
    </div>

    <!-- Individual Exports -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title" style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                Export by Section
            </h3>
        </div>
        <div class="card-body">
            <p style="margin-bottom:16px;color:var(--color-text-secondary);font-size:13px;">
                Export individual sections of the CRM database.
            </p>

            <!-- Leads -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--color-border);">
                <div>
                    <strong>Leads</strong>
                    <span class="badge badge-secondary" style="margin-left:6px;"><?php echo $counts['leads']; ?></span>
                    <div style="font-size:12px;color:var(--color-text-secondary);">Company info, contacts, status, country</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <a href="/api/export.php?scope=leads&format=csv" class="btn btn-sm btn-primary">CSV</a>
                    <a href="/api/export.php?scope=leads&format=json" class="btn btn-sm btn-outline">JSON</a>
                </div>
            </div>

            <!-- Interactions -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--color-border);">
                <div>
                    <strong>Interactions</strong>
                    <span class="badge badge-secondary" style="margin-left:6px;"><?php echo $counts['interactions']; ?></span>
                    <div style="font-size:12px;color:var(--color-text-secondary);">Calls, meetings, emails, notes</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <a href="/api/export.php?scope=interactions&format=csv" class="btn btn-sm btn-primary">CSV</a>
                    <a href="/api/export.php?scope=interactions&format=json" class="btn btn-sm btn-outline">JSON</a>
                </div>
            </div>

            <!-- WhatsApp -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--color-border);">
                <div>
                    <strong>WhatsApp Messages</strong>
                    <span class="badge badge-secondary" style="margin-left:6px;"><?php echo $counts['whatsapp']; ?></span>
                    <div style="font-size:12px;color:var(--color-text-secondary);">Full conversation history with status</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <a href="/api/export.php?scope=whatsapp&format=csv" class="btn btn-sm btn-primary">CSV</a>
                    <a href="/api/export.php?scope=whatsapp&format=json" class="btn btn-sm btn-outline">JSON</a>
                </div>
            </div>

            <!-- VoIP -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;">
                <div>
                    <strong>VoIP Calls</strong>
                    <span class="badge badge-secondary" style="margin-left:6px;"><?php echo $counts['voip']; ?></span>
                    <div style="font-size:12px;color:var(--color-text-secondary);">Call logs, duration, outcomes</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <a href="/api/export.php?scope=voip&format=csv" class="btn btn-sm btn-primary">CSV</a>
                    <a href="/api/export.php?scope=voip&format=json" class="btn btn-sm btn-outline">JSON</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
