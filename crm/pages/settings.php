<?php
/**
 * Victory Genomics CRM - System Settings
 * CSRF protected, SQL injection fixed, logActivity fixed, Apple-style
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole(['Admin']);

$pageTitle = 'System Settings';

// Fetch current settings
try {
    $db = Database::getInstance();
    $settings = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (empty($settings)) {
        $defaultSettings = [
            'company_name' => 'Victory Genomics CRM',
            'company_email' => 'contact@victorygenomics.com',
            'company_phone' => '',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'leads_per_page' => '25',
            'auto_assign_leads' => '0',
            'email_notifications' => '1'
        ];
        foreach ($defaultSettings as $key => $value) {
            $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
        }
        $settings = $defaultSettings;
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Error loading settings: " . $e->getMessage();
    $settings = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    
    try {
        $db = Database::getInstance();
        
        if ($_POST['action'] === 'update_settings' || $_POST['action'] === 'update_email_settings' || $_POST['action'] === 'update_twilio_settings' || $_POST['action'] === 'update_proposal_settings') {
            $settingsToUpdate = [];
            if ($_POST['action'] === 'update_email_settings') {
                $settingsToUpdate = ['smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption','email_from_name','email_from_address','email_reply_to','company_address','email_batch_size','email_batch_delay'];
            } elseif ($_POST['action'] === 'update_twilio_settings') {
                // Handle unchecked checkboxes
                foreach (['voip_enabled','voip_recording_enabled','whatsapp_enabled','whatsapp_sandbox_mode','wa_lead_assignment_notify'] as $cb) {
                    if (!isset($_POST[$cb])) $_POST[$cb] = '0';
                }
                $settingsToUpdate = ['twilio_account_sid','twilio_auth_token','twilio_phone_number','twilio_twiml_app_sid','twilio_api_key','twilio_api_secret','app_url','voip_enabled','voip_recording_enabled','whatsapp_enabled','whatsapp_sandbox_mode','whatsapp_from_number','wa_lead_assignment_notify'];
            } elseif ($_POST['action'] === 'update_proposal_settings') {
                $settingsToUpdate = ['proposal_company_name','proposal_company_address','proposal_company_phone','proposal_company_email','proposal_company_website','proposal_next_number'];
            } else {
                $settingsToUpdate = ['company_name','company_email','company_phone','timezone','date_format','leads_per_page','auto_assign_leads','email_notifications'];
            }
            foreach ($settingsToUpdate as $key) {
                if (isset($_POST[$key])) {
                    $value = $_POST[$key];
                    $existing = $db->findOne('settings', ['setting_key' => $key]);
                    if ($existing) {
                        $db->update('settings', ['setting_value' => $value], ['setting_key' => $key]);
                    } else {
                        $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                    }
                }
            }
            logActivity(getCurrentUserId(), 'Update Settings', 'System', null, "Updated system settings");
            $_SESSION['success'] = "Settings updated successfully";
            $settings = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        
        if ($_POST['action'] === 'delete_all_leads') {
            $password = $_POST['confirm_password'] ?? '';
            $currentUser = getCurrentUser();
            
            // FIXED: Use prepared statement instead of string concatenation
            $stmt = $db->query("SELECT password_hash FROM users WHERE user_id = ?", [$currentUser['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $_SESSION['error'] = 'Invalid password. Delete operation cancelled.';
            } else {
                $leadCount = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
                $db->query("DELETE FROM leads");
                $db->query("DELETE FROM interactions");
                logActivity($currentUser['user_id'], 'Delete All Leads', 'Lead', null, "Deleted all $leadCount leads from the system");
                $_SESSION['success'] = "Successfully deleted $leadCount leads.";
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Get system statistics
try {
    $db = Database::getInstance();
    $sysStats = [
        'total_users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'active_users' => $db->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn(),
        'total_leads' => $db->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
        'total_interactions' => $db->query("SELECT COUNT(*) FROM interactions")->fetchColumn(),
    ];
} catch (Exception $e) {
    $sysStats = ['total_users' => 0, 'active_users' => 0, 'total_leads' => 0, 'total_interactions' => 0];
}

include '../includes/header.php';
$csrf_token = generateCSRFToken();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">System Settings</h1>
        <p class="page-subtitle">Configure system-wide preferences</p>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="grid grid-4 mb-2">
    <div class="stat-card"><div class="stat-icon bg-gradient-primary"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div class="stat-details"><div class="stat-value"><?php echo $sysStats['active_users']; ?> / <?php echo $sysStats['total_users']; ?></div><div class="stat-label">Active Users</div></div></div>
    <div class="stat-card"><div class="stat-icon bg-gradient-success"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></div><div class="stat-details"><div class="stat-value"><?php echo $sysStats['total_leads']; ?></div><div class="stat-label">Total Leads</div></div></div>
    <div class="stat-card"><div class="stat-icon bg-gradient-info"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div><div class="stat-details"><div class="stat-value"><?php echo $sysStats['total_interactions']; ?></div><div class="stat-label">Interactions</div></div></div>
    <div class="stat-card"><div class="stat-icon bg-gradient-warning"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></div><div class="stat-details"><div class="stat-value">MySQL</div><div class="stat-label">Database</div></div></div>
</div>

<div class="grid grid-3">
    <div class="settings-main">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update_settings">
            
            <div class="card">
                <div class="card-header"><h3 class="card-title">General Settings</h3></div>
                <div class="card-body">
                    <div class="grid grid-2">
                        <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Company Email</label><input type="email" name="company_email" class="form-control" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Company Phone</label><input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>"></div>
                        <div class="form-group">
                            <label class="form-label">Timezone</label>
                            <select name="timezone" class="form-control">
                                <?php foreach (['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Europe/London','Europe/Paris','Asia/Dubai','Asia/Qatar'] as $tz): ?>
                                    <option value="<?php echo $tz; ?>" <?php echo ($settings['timezone'] ?? '') === $tz ? 'selected' : ''; ?>><?php echo $tz; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Date Format</label>
                            <select name="date_format" class="form-control">
                                <option value="Y-m-d" <?php echo ($settings['date_format'] ?? '') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Leads Per Page</label><input type="number" name="leads_per_page" class="form-control" min="10" max="100" value="<?php echo htmlspecialchars($settings['leads_per_page'] ?? 25); ?>"></div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header"><h3 class="card-title">Lead Management</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-check"><input type="checkbox" name="auto_assign_leads" value="1" <?php echo ($settings['auto_assign_leads'] ?? 0) == 1 ? 'checked' : ''; ?>><span class="form-check-label">Auto-assign leads to available sales reps</span></label>
                    </div>
                    <div class="form-group">
                        <label class="form-check"><input type="checkbox" name="email_notifications" value="1" <?php echo ($settings['email_notifications'] ?? 1) == 1 ? 'checked' : ''; ?>><span class="form-check-label">Send email notifications for new leads</span></label>
                    </div>
                </div>
            </div>
            
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save Settings</button></div>
        </form>
    </div>
    
    <div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Data Import/Export</h3></div>
            <div class="card-body">
                <a href="import-leads.php" class="btn btn-block btn-primary">Import Leads (CSV)</a>
                <a href="export-leads.php" class="btn btn-block btn-info" style="margin-top:0.5rem;">Export Leads (CSV)</a>
                <hr>
                <button type="button" class="btn btn-block btn-danger" onclick="showDeleteModal()">Delete All Leads</button>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3 class="card-title">System Information</h3></div>
            <div class="card-body">
                <div class="info-item"><div class="info-label">CRM Version</div><div class="info-value"><?php echo APP_VERSION; ?></div></div>
                <div class="info-item"><div class="info-label">PHP Version</div><div class="info-value"><?php echo PHP_VERSION; ?></div></div>
                <div class="info-item"><div class="info-label">Server</div><div class="info-value"><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></div></div>
                <div class="info-item"><div class="info-label">Database</div><div class="info-value">MySQL</div></div>
            </div>
        </div>
    </div>
</div>

<!-- Twilio VoIP & WhatsApp Settings -->
<div class="card mt-2" style="grid-column:1/-1;">
    <div class="card-header">
        <h3 class="card-title">VoIP &amp; WhatsApp (Twilio)</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update_twilio_settings">
            
            <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Application</h4>
            <div class="grid grid-2" style="margin-bottom:20px;">
                <div class="form-group">
                    <label class="form-label">Application URL</label>
                    <input type="url" name="app_url" class="form-control" placeholder="https://crm.victorygenomics.com" value="<?php echo htmlspecialchars($settings['app_url'] ?? ''); ?>">
                    <small style="color:var(--text-muted);font-size:12px;">Public URL where this CRM is hosted (used for Twilio webhooks)</small>
                </div>
                <div class="form-group">&nbsp;</div>
            </div>

            <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Twilio Credentials</h4>
            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">Twilio Account SID</label>
                    <input type="text" name="twilio_account_sid" class="form-control" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" value="<?php echo htmlspecialchars($settings['twilio_account_sid'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Twilio Auth Token</label>
                    <input type="password" name="twilio_auth_token" class="form-control" placeholder="Your auth token" value="<?php echo htmlspecialchars($settings['twilio_auth_token'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Twilio API Key <small style="color:var(--text-muted);">(for VoIP tokens)</small></label>
                    <input type="text" name="twilio_api_key" class="form-control" placeholder="SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" value="<?php echo htmlspecialchars($settings['twilio_api_key'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Twilio API Secret <small style="color:var(--text-muted);">(for VoIP tokens)</small></label>
                    <input type="password" name="twilio_api_secret" class="form-control" placeholder="Your API secret" value="<?php echo htmlspecialchars($settings['twilio_api_secret'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">VoIP Phone Number <small style="color:var(--text-muted);">(for voice calls)</small></label>
                    <input type="text" name="twilio_phone_number" class="form-control" placeholder="+18583585260" value="<?php echo htmlspecialchars($settings['twilio_phone_number'] ?? ''); ?>">
                    <small style="color:var(--text-muted);font-size:11px;">This number is used as Caller ID for outbound VoIP calls. Must be a Twilio number you own.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">TwiML App SID <small style="color:var(--text-muted);">(optional, for browser calls)</small></label>
                    <input type="text" name="twilio_twiml_app_sid" class="form-control" placeholder="APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" value="<?php echo htmlspecialchars($settings['twilio_twiml_app_sid'] ?? ''); ?>">
                </div>
            </div>

            <h4 style="margin:20px 0 12px 0;font-size:14px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">WhatsApp</h4>
            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">WhatsApp Sender Number <small style="color:var(--text-muted);">(separate from VoIP)</small></label>
                    <input type="text" name="whatsapp_from_number" class="form-control" placeholder="+12036457444" value="<?php echo htmlspecialchars($settings['whatsapp_from_number'] ?? ''); ?>">
                    <small style="color:var(--text-muted);font-size:11px;">This number is registered as a WhatsApp Business sender in your Twilio Console. Can be different from VoIP number.</small>
                </div>
                <div class="form-group">&nbsp;</div>
            </div>
            <div class="grid grid-4" style="margin-top:8px;">
                <div class="form-group">
                    <label class="form-check"><input type="checkbox" name="voip_enabled" value="1" <?php echo ($settings['voip_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="form-check-label">VoIP Enabled</span></label>
                </div>
                <div class="form-group">
                    <label class="form-check"><input type="checkbox" name="voip_recording_enabled" value="1" <?php echo ($settings['voip_recording_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="form-check-label">Call Recording</span></label>
                </div>
                <div class="form-group">
                    <label class="form-check"><input type="checkbox" name="whatsapp_enabled" value="1" <?php echo ($settings['whatsapp_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="form-check-label">WhatsApp Enabled</span></label>
                </div>
                <div class="form-group">
                    <label class="form-check"><input type="checkbox" name="whatsapp_sandbox_mode" value="1" <?php echo ($settings['whatsapp_sandbox_mode'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="form-check-label">WhatsApp Sandbox Mode</span></label>
                </div>
            </div>

            <h4 style="margin:20px 0 12px 0;font-size:14px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Notifications</h4>
            <div class="form-group">
                <label class="form-check"><input type="checkbox" name="wa_lead_assignment_notify" value="1" <?php echo ($settings['wa_lead_assignment_notify'] ?? '0') == '1' ? 'checked' : ''; ?>><span class="form-check-label">WhatsApp Lead Assignment Notifications (Master Switch)</span></label>
                <small style="color:var(--text-muted);font-size:12px;display:block;margin-top:4px;margin-left:24px;">Master switch for lead assignment notifications. When enabled, users who have notifications turned on in their profile will receive a WhatsApp message when a lead is assigned to them. Enable per-user in <strong>User Management &gt; Edit User</strong>.</small>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save Twilio Settings</button></div>
        </form>
    </div>
</div>

<!-- Email Marketing Settings -->
<div class="card mt-2" style="grid-column:1/-1;">
    <div class="card-header"><h3 class="card-title">Email Marketing / SMTP</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update_email_settings">
            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control" placeholder="mail.yourdomain.com" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" placeholder="465" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '465'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Username</label>
                    <input type="text" name="smtp_username" class="form-control" placeholder="user@yourdomain.com" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Password</label>
                    <input type="password" name="smtp_password" class="form-control" placeholder="••••••••" value="<?php echo htmlspecialchars($settings['smtp_password'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Encryption</label>
                    <select name="smtp_encryption" class="form-control">
                        <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="tls" <?php echo ($settings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="" <?php echo ($settings['smtp_encryption'] ?? '') === '' ? 'selected' : ''; ?>>None</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">From Name</label>
                    <input type="text" name="email_from_name" class="form-control" placeholder="Victory Genomics" value="<?php echo htmlspecialchars($settings['email_from_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">From Email</label>
                    <input type="email" name="email_from_address" class="form-control" placeholder="marketing@victorygenomics.com" value="<?php echo htmlspecialchars($settings['email_from_address'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Reply-To Email</label>
                    <input type="email" name="email_reply_to" class="form-control" placeholder="info@victorygenomics.com" value="<?php echo htmlspecialchars($settings['email_reply_to'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Company Address (footer)</label>
                    <input type="text" name="company_address" class="form-control" placeholder="123 Main St, City" value="<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Batch Size (emails per batch)</label>
                    <input type="number" name="email_batch_size" class="form-control" min="1" max="500" value="<?php echo htmlspecialchars($settings['email_batch_size'] ?? '50'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Batch Delay (seconds)</label>
                    <input type="number" name="email_batch_delay" class="form-control" min="0" max="60" value="<?php echo htmlspecialchars($settings['email_batch_delay'] ?? '2'); ?>">
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save Email Settings</button></div>
        </form>
    </div>
</div>

<!-- Proposal / Estimate Settings -->
<div class="card mt-2" style="grid-column:1/-1;">
    <div class="card-header"><h3 class="card-title">Proposal / Estimate Settings</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update_proposal_settings">
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">These details appear on your proposal / estimate documents.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="proposal_company_name" class="form-control" value="<?php echo htmlspecialchars($settings['proposal_company_name'] ?? 'Victory Genomics, INC.'); ?>"></div>
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="proposal_company_phone" class="form-control" value="<?php echo htmlspecialchars($settings['proposal_company_phone'] ?? ''); ?>"></div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="proposal_company_email" class="form-control" value="<?php echo htmlspecialchars($settings['proposal_company_email'] ?? ''); ?>"></div>
                <div class="form-group"><label class="form-label">Website</label><input type="text" name="proposal_company_website" class="form-control" value="<?php echo htmlspecialchars($settings['proposal_company_website'] ?? ''); ?>"></div>
                <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Address (one line per row)</label><textarea name="proposal_company_address" class="form-control" rows="3"><?php echo htmlspecialchars($settings['proposal_company_address'] ?? ''); ?></textarea></div>
                <div class="form-group"><label class="form-label">Next Estimate Number</label><input type="number" name="proposal_next_number" class="form-control" min="1" value="<?php echo htmlspecialchars($settings['proposal_next_number'] ?? '1006'); ?>"><small style="color:var(--text-muted);font-size:11px;">Auto-increments after each new proposal</small></div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save Proposal Settings</button></div>
        </form>
    </div>
</div>

<!-- Google Sheets Integration -->
<div class="card mt-2" style="grid-column:1/-1;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">Google Sheets Integration</h3>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="syncAllSheets()" id="syncAllBtn" title="Sync all enabled sheets now">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:3px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Sync All Now
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="showWebhookModal()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Google Sheet
            </button>
        </div>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">
            Connect Google Sheets from your Meta / Google Ads campaigns. The CRM automatically pulls new rows and imports them as leads.
            <strong>Requirement:</strong> Each sheet must be set to <em>"Anyone with the link can view"</em>.
        </p>
        <div id="webhookEndpointsList">
            <div style="text-align:center;padding:20px;color:var(--text-muted);">Loading...</div>
        </div>
    </div>
</div>

<!-- Google Sheet Endpoint Modal (Create / Edit) -->
<div id="webhookModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideWebhookModal()"></div>
    <div class="modal-content" style="max-width:720px;">
        <div class="modal-header">
            <h3 id="webhookModalTitle">Add Google Sheet</h3>
            <button type="button" class="btn-close" onclick="hideWebhookModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="wh_endpoint_id" value="">

            <div class="form-group">
                <label class="form-label">Name <span style="color:red;">*</span></label>
                <input type="text" id="wh_name" class="form-control" placeholder="e.g. Meta Ads - Saudi Arabia">
            </div>

            <h4 style="margin:16px 0 8px 0;font-size:13px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Google Sheet</h4>
            <div class="grid grid-2" style="gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Google Sheet URL <span style="color:red;">*</span></label>
                    <input type="url" id="wh_sheet_url" class="form-control" placeholder="https://docs.google.com/spreadsheets/d/...">
                    <small style="color:var(--text-muted);font-size:11px;">Paste the full Google Sheets URL. The sheet must be shared as "Anyone with the link can view".</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Sheet / Tab Name <small style="color:var(--text-muted);">(optional)</small></label>
                    <input type="text" id="wh_sheet_name" class="form-control" placeholder="Sheet1 (default: first tab)">
                </div>
                <div class="form-group">
                    <label class="form-label">Assign Leads To</label>
                    <select id="wh_assigned_to" class="form-control">
                        <option value="">-- Unassigned --</option>
                        <?php
                        $allUsers = $db->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' AND role IN ('Admin','Sales Manager','Sales Rep') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($allUsers as $u): ?>
                            <option value="<?php echo $u['user_id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?> (<?php echo $u['role']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin:16px 0 8px 0;font-size:13px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Lead Defaults</h4>
            <div class="grid grid-3" style="gap:12px;">
                <div class="form-group">
                    <label class="form-label">Lead Source</label>
                    <select id="wh_lead_source" class="form-control">
                        <?php foreach (['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'] as $ls): ?>
                            <option value="<?php echo $ls; ?>" <?php echo $ls === 'Facebook' ? 'selected' : ''; ?>><?php echo $ls; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Lead Type</label>
                    <select id="wh_lead_type" class="form-control">
                        <?php foreach (['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'] as $lt): ?>
                            <option value="<?php echo $lt; ?>" <?php echo $lt === 'Other' ? 'selected' : ''; ?>><?php echo $lt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <select id="wh_priority" class="form-control">
                        <?php foreach (['Low','Medium','High','Urgent'] as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $p === 'Medium' ? 'selected' : ''; ?>><?php echo $p; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Lead Status</label>
                    <select id="wh_lead_status" class="form-control">
                        <?php foreach (['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo $st === 'New Lead' ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Region</label>
                    <select id="wh_region" class="form-control">
                        <?php foreach (['North America','Europe','Middle East','Asia-Pacific','Latin America','Africa','Other'] as $r): ?>
                            <option value="<?php echo $r; ?>" <?php echo $r === 'Middle East' ? 'selected' : ''; ?>><?php echo $r; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <input type="text" id="wh_country" class="form-control" placeholder="e.g. Saudi Arabia">
                </div>
            </div>

            <h4 style="margin:16px 0 8px 0;font-size:13px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">Field Mapping</h4>
            <p style="color:var(--text-muted);font-size:12px;margin-bottom:10px;">
                Map your Google Sheet column headers to CRM fields. Left = sheet column header (lowercase, spaces become underscores), Right = CRM field.
            </p>
            <div id="fieldMappingRows">
                <!-- Dynamically populated -->
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="addMappingRow('', '')" style="margin-top:8px;">
                + Add Mapping Row
            </button>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="hideWebhookModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="webhookSaveBtn" onclick="saveWebhookEndpoint()">Save</button>
        </div>
    </div>
</div>

<!-- Import Logs Modal -->
<div id="webhookLogsModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideWebhookLogsModal()"></div>
    <div class="modal-content" style="max-width:800px;">
        <div class="modal-header">
            <h3 id="logsModalTitle">Import Logs</h3>
            <button type="button" class="btn-close" onclick="hideWebhookLogsModal()">&times;</button>
        </div>
        <div class="modal-body" style="max-height:500px;overflow:auto;">
            <div id="webhookLogsBody">
                <div style="text-align:center;padding:20px;color:var(--text-muted);">Loading...</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="hideWebhookLogsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Delete All Leads Modal -->
<div id="deleteModal" class="modal" style="display:none;">
    <div class="modal-backdrop" onclick="hideDeleteModal()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>Delete All Leads</h3>
            <button type="button" class="btn-close" onclick="hideDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="delete_all_leads">
            <div class="modal-body">
                <p class="text-danger"><strong>Warning:</strong> This will permanently delete ALL leads and interactions. This cannot be undone.</p>
                <div class="form-group">
                    <label class="form-label">Enter your password to confirm:</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required placeholder="Your account password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete All Leads</button>
            </div>
        </form>
    </div>
</div>

<script>
// ─── Delete All Leads Modal ──────────────────────────────────
function showDeleteModal() { document.getElementById('deleteModal').style.display = 'flex'; document.getElementById('confirm_password').value = ''; document.getElementById('confirm_password').focus(); }
function hideDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { hideDeleteModal(); hideWebhookModal(); hideWebhookLogsModal(); }
});
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) hideDeleteModal(); });

// ─── Google Sheets Integration ───────────────────────────────
const CSRF_TOKEN = '<?php echo $csrf_token; ?>';

const CRM_FIELDS = [
    {value: 'contact_person', label: 'Contact Person'},
    {value: 'company_name',   label: 'Company Name'},
    {value: 'email',          label: 'Email'},
    {value: 'phone',          label: 'Phone'},
    {value: 'mobile',         label: 'Mobile / WhatsApp'},
    {value: 'city',           label: 'City'},
    {value: 'country',        label: 'Country'},
    {value: 'address',        label: 'Address'},
    {value: 'title_position', label: 'Title / Position'},
    {value: 'website',        label: 'Website'},
    {value: 'specialization', label: 'Specialization'},
    {value: 'notes',          label: 'Notes'},
    {value: 'lead_type',      label: 'Lead Type'},
    {value: 'lead_source',    label: 'Lead Source'},
    {value: 'facebook_url',   label: 'Facebook URL'},
    {value: 'instagram_url',  label: 'Instagram URL'},
    {value: 'linkedin_url',   label: 'LinkedIn URL'},
    {value: 'number_of_horses', label: 'Number of Horses'},
    {value: 'horse_breed',    label: 'Horse Breed'},
    {value: '',               label: '-- Ignore / Skip --'},
];

document.addEventListener('DOMContentLoaded', loadWebhookEndpoints);

function loadWebhookEndpoints() {
    fetch('/api/webhooks.php?action=list')
    .then(r => r.json())
    .then(resp => {
        const container = document.getElementById('webhookEndpointsList');
        if (!resp.success || !resp.data || resp.data.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted);"><p>No Google Sheets connected yet.</p><p style="font-size:12px;">Click "Add Google Sheet" to connect your first sheet.</p></div>';
            return;
        }
        let html = '<table class="table"><thead><tr><th>Name</th><th>Assigned To</th><th>Status</th><th>Imported</th><th>Synced Row</th><th>Last Sync</th><th style="text-align:right;">Actions</th></tr></thead><tbody>';
        resp.data.forEach(ep => {
            const enabled = parseInt(ep.enabled) === 1;
            const hasSheet = ep.sheet_url && ep.sheet_url.length > 0;
            let statusBadge = '';
            if (!hasSheet) statusBadge = '<span class="badge bg-yellow-100 text-yellow-800">No Sheet URL</span>';
            else if (enabled) statusBadge = '<span class="badge bg-green-100 text-green-800">Active</span>';
            else statusBadge = '<span class="badge bg-gray-300 text-gray-800">Disabled</span>';
            const lastRcv = ep.last_received ? new Date(ep.last_received).toLocaleString() : 'Never';
            const syncedRow = ep.last_synced_row || '0';
            html += `<tr>
                <td><strong>${escHtml(ep.name)}</strong>${ep.sheet_name ? '<br><small style="color:var(--text-muted);">Tab: ' + escHtml(ep.sheet_name) + '</small>' : ''}</td>
                <td>${ep.assigned_name ? escHtml(ep.assigned_name) : '<em style="color:var(--text-muted);">Unassigned</em>'}</td>
                <td>${statusBadge}</td>
                <td>${ep.total_imported}</td>
                <td style="font-size:12px;">${syncedRow}</td>
                <td style="font-size:12px;color:var(--text-muted);">${lastRcv}</td>
                <td style="text-align:right;white-space:nowrap;">
                    <button class="btn btn-primary btn-sm" onclick="syncSheet(${ep.endpoint_id}, this)" title="Sync now" ${!hasSheet || !enabled ? 'disabled' : ''}>Sync</button>
                    <button class="btn btn-outline btn-sm" onclick="showWebhookLogs(${ep.endpoint_id}, '${escHtml(ep.name)}')" title="Import Logs">Logs</button>
                    <button class="btn btn-outline btn-sm" onclick="editWebhookEndpoint(${ep.endpoint_id})" title="Edit">Edit</button>
                    <button class="btn btn-outline btn-sm" onclick="toggleWebhook(${ep.endpoint_id})" title="${enabled ? 'Disable' : 'Enable'}">${enabled ? 'Disable' : 'Enable'}</button>
                    <button class="btn btn-outline btn-sm" onclick="resetSyncPosition(${ep.endpoint_id}, '${escHtml(ep.name)}')" title="Reset sync position">Reset</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteWebhook(${ep.endpoint_id}, '${escHtml(ep.name)}')" title="Delete">Del</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    })
    .catch(err => {
        document.getElementById('webhookEndpointsList').innerHTML = '<div style="color:red;padding:10px;">Failed to load: ' + err.message + '</div>';
    });
}

// ─── Show / Hide modals ─────────────────────────────────────
function showWebhookModal(editData) {
    document.getElementById('webhookModal').style.display = 'flex';
    document.getElementById('wh_endpoint_id').value = '';
    document.getElementById('wh_name').value = '';
    document.getElementById('wh_sheet_url').value = '';
    document.getElementById('wh_sheet_name').value = '';
    document.getElementById('wh_assigned_to').value = '';
    document.getElementById('wh_lead_source').value = 'Facebook';
    document.getElementById('wh_lead_type').value = 'Other';
    document.getElementById('wh_priority').value = 'Medium';
    document.getElementById('wh_lead_status').value = 'New Lead';
    document.getElementById('wh_region').value = 'Middle East';
    document.getElementById('wh_country').value = '';
    document.getElementById('fieldMappingRows').innerHTML = '';
    document.getElementById('webhookModalTitle').textContent = 'Add Google Sheet';

    if (editData) {
        document.getElementById('webhookModalTitle').textContent = 'Edit Google Sheet';
        document.getElementById('wh_endpoint_id').value = editData.endpoint_id;
        document.getElementById('wh_name').value = editData.name || '';
        document.getElementById('wh_sheet_url').value = editData.sheet_url || '';
        document.getElementById('wh_sheet_name').value = editData.sheet_name || '';
        document.getElementById('wh_assigned_to').value = editData.assigned_to || '';

        const defaults = typeof editData.lead_defaults === 'string' ? JSON.parse(editData.lead_defaults || '{}') : (editData.lead_defaults || {});
        document.getElementById('wh_lead_source').value = defaults.lead_source || 'Facebook';
        document.getElementById('wh_lead_type').value = defaults.lead_type || 'Other';
        document.getElementById('wh_priority').value = defaults.priority || 'Medium';
        document.getElementById('wh_lead_status').value = defaults.lead_status || 'New Lead';
        document.getElementById('wh_region').value = defaults.region || 'Middle East';
        document.getElementById('wh_country').value = defaults.country || '';

        const mapping = typeof editData.field_mapping === 'string' ? JSON.parse(editData.field_mapping || '{}') : (editData.field_mapping || {});
        Object.entries(mapping).forEach(([src, dest]) => addMappingRow(src, dest));
    }

    if (!editData) {
        addMappingRow('full_name', 'contact_person');
        addMappingRow('email', 'email');
        addMappingRow('phone', 'phone');
        addMappingRow('company', 'company_name');
        addMappingRow('city', 'city');
    }
    document.getElementById('wh_name').focus();
}

function hideWebhookModal() { document.getElementById('webhookModal').style.display = 'none'; }
function hideWebhookLogsModal() { document.getElementById('webhookLogsModal').style.display = 'none'; }

// ─── Field mapping ───────────────────────────────────────────
function addMappingRow(srcVal, destVal) {
    const container = document.getElementById('fieldMappingRows');
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';

    let optionsHtml = '';
    CRM_FIELDS.forEach(f => {
        const sel = (f.value === destVal) ? 'selected' : '';
        optionsHtml += `<option value="${f.value}" ${sel}>${f.label}</option>`;
    });

    row.innerHTML = `
        <input type="text" class="form-control mapping-src" value="${escHtml(srcVal)}" placeholder="Sheet column header" style="flex:1;font-size:13px;">
        <span style="color:var(--text-muted);font-size:18px;">&rarr;</span>
        <select class="form-control mapping-dest" style="flex:1;font-size:13px;">${optionsHtml}</select>
        <button type="button" class="btn btn-outline btn-sm" onclick="this.parentElement.remove()" style="padding:4px 8px;color:red;" title="Remove">&times;</button>
    `;
    container.appendChild(row);
}

function collectFieldMapping() {
    const mapping = {};
    document.querySelectorAll('#fieldMappingRows > div').forEach(row => {
        const src = row.querySelector('.mapping-src').value.trim();
        const dest = row.querySelector('.mapping-dest').value;
        if (src && dest) mapping[src] = dest;
    });
    return mapping;
}

function collectLeadDefaults() {
    return {
        lead_source: document.getElementById('wh_lead_source').value,
        lead_type:   document.getElementById('wh_lead_type').value,
        priority:    document.getElementById('wh_priority').value,
        lead_status: document.getElementById('wh_lead_status').value,
        region:      document.getElementById('wh_region').value,
        country:     document.getElementById('wh_country').value,
    };
}

// ─── Save (create or update) ─────────────────────────────────
function saveWebhookEndpoint() {
    const endpointId = document.getElementById('wh_endpoint_id').value;
    const name = document.getElementById('wh_name').value.trim();
    if (!name) { alert('Please enter a name.'); return; }

    const sheetUrl = document.getElementById('wh_sheet_url').value.trim();

    const payload = {
        csrf_token:    CSRF_TOKEN,
        name:          name,
        sheet_url:     sheetUrl,
        sheet_name:    document.getElementById('wh_sheet_name').value.trim(),
        assigned_to:   document.getElementById('wh_assigned_to').value || null,
        field_mapping: JSON.stringify(collectFieldMapping()),
        lead_defaults: JSON.stringify(collectLeadDefaults()),
    };

    let url = '/api/webhooks.php?action=create';
    if (endpointId) {
        url = '/api/webhooks.php?action=update';
        payload.endpoint_id = endpointId;
    }

    const btn = document.getElementById('webhookSaveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) {
            hideWebhookModal();
            loadWebhookEndpoints();
        } else {
            alert('Error: ' + (resp.message || 'Unknown error'));
        }
    })
    .catch(err => alert('Request failed: ' + err.message))
    .finally(() => { btn.disabled = false; btn.textContent = 'Save'; });
}

// ─── Sync a single sheet ─────────────────────────────────────
function syncSheet(id, btnEl) {
    const origText = btnEl.textContent;
    btnEl.disabled = true;
    btnEl.textContent = 'Syncing...';

    fetch('/api/sheets-sync.php?action=sync&id=' + id, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) {
            alert(resp.message || 'Sync completed.');
            loadWebhookEndpoints();
        } else {
            alert('Sync failed: ' + (resp.message || 'Unknown error'));
        }
    })
    .catch(err => alert('Sync error: ' + err.message))
    .finally(() => { btnEl.disabled = false; btnEl.textContent = origText; });
}

// ─── Sync all sheets ─────────────────────────────────────────
function syncAllSheets() {
    const btn = document.getElementById('syncAllBtn');
    btn.disabled = true;
    btn.textContent = 'Syncing...';

    fetch('/api/sheets-sync.php?action=sync_all', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) {
            let summary = 'Sync completed:\n';
            (resp.results || []).forEach(r => {
                const res = r.result || {};
                summary += `\n${r.name}: ${res.message || 'OK'}`;
            });
            alert(summary);
            loadWebhookEndpoints();
        } else {
            alert('Sync failed: ' + (resp.message || 'Unknown error'));
        }
    })
    .catch(err => alert('Sync error: ' + err.message))
    .finally(() => { btn.disabled = false; btn.textContent = 'Sync All Now'; });
}

// ─── Reset sync position ─────────────────────────────────────
function resetSyncPosition(id, name) {
    if (!confirm('Reset sync position for "' + name + '"?\n\nNext sync will re-process all rows from the beginning. Duplicates will be automatically skipped.')) return;

    fetch('/api/webhooks.php?action=reset_sync', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF_TOKEN, endpoint_id: id})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) { alert(resp.message); loadWebhookEndpoints(); }
        else alert('Error: ' + resp.message);
    })
    .catch(err => alert('Error: ' + err.message));
}

// ─── Edit endpoint ───────────────────────────────────────────
function editWebhookEndpoint(id) {
    fetch('/api/webhooks.php?action=detail&id=' + id)
    .then(r => r.json())
    .then(resp => {
        if (resp.success && resp.data) showWebhookModal(resp.data);
        else alert('Failed to load details.');
    })
    .catch(err => alert('Error: ' + err.message));
}

// ─── Toggle enable/disable ───────────────────────────────────
function toggleWebhook(id) {
    fetch('/api/webhooks.php?action=toggle', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF_TOKEN, endpoint_id: id})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) loadWebhookEndpoints();
        else alert('Error: ' + resp.message);
    })
    .catch(err => alert('Error: ' + err.message));
}

// ─── Delete endpoint ─────────────────────────────────────────
function deleteWebhook(id, name) {
    if (!confirm('Delete "' + name + '"?\n\nPreviously imported leads will NOT be deleted.')) return;

    fetch('/api/webhooks.php?action=delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF_TOKEN, endpoint_id: id})
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) loadWebhookEndpoints();
        else alert('Error: ' + resp.message);
    })
    .catch(err => alert('Error: ' + err.message));
}

// ─── Show import logs ────────────────────────────────────────
function showWebhookLogs(id, name) {
    document.getElementById('logsModalTitle').textContent = 'Import Logs: ' + name;
    document.getElementById('webhookLogsBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">Loading...</div>';
    document.getElementById('webhookLogsModal').style.display = 'flex';

    fetch('/api/webhooks.php?action=logs&id=' + id)
    .then(r => r.json())
    .then(resp => {
        if (!resp.success || !resp.data || resp.data.length === 0) {
            document.getElementById('webhookLogsBody').innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:20px;">No import logs yet. Click "Sync" to pull data from the sheet.</p>';
            return;
        }
        let html = '<table class="table" style="font-size:13px;"><thead><tr><th>Date</th><th>Status</th><th>Lead</th><th>Details</th></tr></thead><tbody>';
        resp.data.forEach(log => {
            const dt = new Date(log.created_at).toLocaleString();
            let statusBadge = '';
            if (log.status === 'created') statusBadge = '<span class="badge bg-green-100 text-green-800">Created</span>';
            else if (log.status === 'duplicate') statusBadge = '<span class="badge bg-yellow-100 text-yellow-800">Duplicate</span>';
            else statusBadge = '<span class="badge bg-red-100 text-red-800">Error</span>';

            const leadInfo = log.lead_id ? `<a href="/pages/lead-detail.php?id=${log.lead_id}">#${log.lead_id}</a> ${escHtml(log.contact_person || '')}` : '-';
            const detail = log.error_message ? escHtml(log.error_message) : '';

            html += `<tr><td>${dt}</td><td>${statusBadge}</td><td>${leadInfo}</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(detail)}">${detail}</td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('webhookLogsBody').innerHTML = html;
    })
    .catch(err => {
        document.getElementById('webhookLogsBody').innerHTML = '<p style="color:red;">Failed to load logs: ' + err.message + '</p>';
    });
}

// ─── Utilities ───────────────────────────────────────────────
function escHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}
</script>

<?php include '../includes/footer.php'; ?>
