<?php
/**
 * Victory Genomics CRM - User Profile
 * CSRF protected, fixed logActivity, password strength enforcement, Apple-style
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();

$pageTitle = 'My Profile';
$userId = getCurrentUserId();

try {
    $db = Database::getInstance();
    $userData = $db->findOne('users', ['user_id' => $userId]);
    if (!$userData) {
        $_SESSION['error'] = "User not found";
        header('Location: /dashboard.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Error loading profile: " . $e->getMessage();
    header('Location: /dashboard.php');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    
    try {
        $db = Database::getInstance();
        
        if ($_POST['action'] === 'update_profile') {
            $fullName = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $whatsappNumber = trim($_POST['whatsapp_number'] ?? '');
            
            if (empty($fullName) || empty($email)) {
                throw new Exception("Full name and email are required");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address");
            }
            
            $existing = $db->query("SELECT user_id FROM users WHERE email = ? AND user_id != ?", [$email, $userId])->fetch();
            if ($existing) {
                throw new Exception("Email already in use");
            }
            
            $db->update('users', [
                'full_name' => $fullName,
                'email' => $email,
                'whatsapp_number' => $whatsappNumber ?: null,
            ], ['user_id' => $userId]);
            logActivity($userId, 'Update Profile', 'User', $userId, "Updated profile information");
            $_SESSION['success'] = "Profile updated successfully";
            
            // Refresh session and user data
            $_SESSION['full_name'] = $fullName;
            $_SESSION['email'] = $email;
            $userData = $db->findOne('users', ['user_id' => $userId]);
            
        } elseif ($_POST['action'] === 'update_smtp') {
            $smtpEmail = trim($_POST['smtp_email'] ?? '');

            // Validate email format if provided
            if ($smtpEmail && !filter_var($smtpEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address");
            }

            $updateData = [
                'smtp_email' => $smtpEmail ?: null,
            ];

            $db->update('users', $updateData, ['user_id' => $userId]);
            logActivity($userId, 'Update Email Settings', 'User', $userId, "Updated email settings");
            $_SESSION['success'] = "Email settings saved successfully";
            $userData = $db->findOne('users', ['user_id' => $userId]);

        } elseif ($_POST['action'] === 'disconnect_microsoft') {
            $db->update('users', [
                'ms_access_token'    => null,
                'ms_refresh_token'   => null,
                'ms_token_expires'   => null,
                'ms_connected_email' => null,
            ], ['user_id' => $userId]);
            logActivity($userId, 'Disconnected Microsoft Email', 'User', $userId, "Disconnected Office 365 email");
            $_SESSION['success'] = "Microsoft Office 365 disconnected successfully.";
            $userData = $db->findOne('users', ['user_id' => $userId]);

        } elseif ($_POST['action'] === 'test_smtp') {
            // Quick test: try sending a test email via Microsoft Graph
            $msToken = $userData['ms_access_token'] ?? '';
            $msRefresh = $userData['ms_refresh_token'] ?? '';
            $msExpires = $userData['ms_token_expires'] ?? '';

            if (!empty($msToken) && !empty($msRefresh)) {
                // Check token expiry and refresh if needed
                if (!empty($msExpires) && strtotime($msExpires) <= time()) {
                    // Try refresh
                    $tokenUrl = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/token';
                    $postFields = http_build_query([
                        'client_id'     => MS_CLIENT_ID,
                        'client_secret' => MS_CLIENT_SECRET,
                        'refresh_token' => $msRefresh,
                        'grant_type'    => 'refresh_token',
                        'scope'         => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read offline_access',
                    ]);
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $tokenUrl, CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $postFields, CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                    ]);
                    $resp = curl_exec($ch); curl_close($ch);
                    $tokenData = json_decode($resp, true);
                    if (isset($tokenData['access_token'])) {
                        $msToken = $tokenData['access_token'];
                        $db->update('users', [
                            'ms_access_token'  => $tokenData['access_token'],
                            'ms_refresh_token' => $tokenData['refresh_token'] ?? $msRefresh,
                            'ms_token_expires' => date('Y-m-d H:i:s', time() + intval($tokenData['expires_in'] ?? 3600)),
                        ], ['user_id' => $userId]);
                    } else {
                        throw new Exception("Token expired and refresh failed. Please reconnect your Microsoft account.");
                    }
                }

                // Test by calling Graph /me endpoint
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => 'https://graph.microsoft.com/v1.0/me',
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $msToken, 'Content-Type: application/json'],
                ]);
                $meResp = curl_exec($ch);
                $meCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($meCode === 200) {
                    $meData = json_decode($meResp, true);
                    $email = $meData['mail'] ?? $meData['userPrincipalName'] ?? 'unknown';
                    $_SESSION['success'] = "Microsoft connection verified! Connected as: $email. Emails will be sent via Microsoft Graph API.";
                } else {
                    throw new Exception("Microsoft API returned HTTP $meCode. Please reconnect your account.");
                }
            } else {
                throw new Exception("Microsoft Office 365 is not connected. Please click 'Connect Microsoft Office 365' first.");
            }
            $userData = $db->findOne('users', ['user_id' => $userId]);

        } elseif ($_POST['action'] === 'change_password') {
            $currentPassword = $_POST['current_password'];
            $newPassword = $_POST['new_password'];
            $confirmPassword = $_POST['confirm_password'];
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                throw new Exception("All password fields are required");
            }
            if (!password_verify($currentPassword, $userData['password_hash'])) {
                throw new Exception("Current password is incorrect");
            }
            
            // Use strong password validation
            $passwordErrors = validatePasswordStrength($newPassword);
            if (!empty($passwordErrors)) {
                throw new Exception(implode('. ', $passwordErrors));
            }
            
            if ($newPassword !== $confirmPassword) {
                throw new Exception("New passwords do not match");
            }
            
            $db->update('users', ['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12])], ['user_id' => $userId]);
            logActivity($userId, 'Change Password', 'User', $userId, "Changed password");
            $_SESSION['success'] = "Password changed successfully";
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// Get user statistics
try {
    $db = Database::getInstance();
    $stats = $db->query("
        SELECT COUNT(DISTINCT l.lead_id) as assigned_leads,
            COUNT(DISTINCT i.interaction_id) as total_interactions,
            COUNT(CASE WHEN l.lead_status = 'Won' THEN 1 END) as deals_won,
            MAX(i.interaction_date) as last_activity
        FROM users u
        LEFT JOIN leads l ON u.user_id = l.assigned_to
        LEFT JOIN interactions i ON u.user_id = i.user_id
        WHERE u.user_id = ?
    ", [$userId])->fetch();
} catch (Exception $e) {
    $stats = ['assigned_leads' => 0, 'total_interactions' => 0, 'deals_won' => 0, 'last_activity' => null];
}

include '../includes/header.php';
$csrf_token = generateCSRFToken();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your personal information and settings</p>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="grid grid-3">
    <!-- Profile Overview -->
    <div>
        <div class="card profile-card">
            <div class="card-body text-center">
                <div class="profile-avatar-large"><?php echo strtoupper(substr($userData['full_name'], 0, 2)); ?></div>
                <h2 class="profile-name"><?php echo htmlspecialchars($userData['full_name']); ?></h2>
                <p class="profile-username">@<?php echo htmlspecialchars($userData['username']); ?></p>
                <span class="badge badge-lg bg-white-20"><?php echo htmlspecialchars($userData['role']); ?></span>
                
                <div class="profile-stats">
                    <div class="profile-stat-item"><div class="profile-stat-value"><?php echo $stats['assigned_leads']; ?></div><div class="profile-stat-label">Leads</div></div>
                    <div class="profile-stat-item"><div class="profile-stat-value"><?php echo $stats['total_interactions']; ?></div><div class="profile-stat-label">Interactions</div></div>
                    <div class="profile-stat-item"><div class="profile-stat-value"><?php echo $stats['deals_won']; ?></div><div class="profile-stat-label">Won</div></div>
                </div>
                
                <div class="profile-info">
                    <div class="profile-info-item"><?php echo htmlspecialchars($userData['email']); ?></div>
                    <div class="profile-info-item">Member since <?php echo date('M Y', strtotime($userData['created_at'])); ?></div>
                    <div class="profile-info-item">Last login: <?php echo $userData['last_login'] ? date('M d, Y H:i', strtotime($userData['last_login'])) : 'Never'; ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Forms -->
    <div class="profile-forms">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Profile Information</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($userData['username']); ?>" readonly>
                        <small class="form-hint">Username cannot be changed</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($userData['full_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($userData['email']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Number</label>
                        <input type="tel" name="whatsapp_number" class="form-control" placeholder="+34678281853" value="<?php echo htmlspecialchars($userData['whatsapp_number'] ?? ''); ?>">
                        <small class="form-hint">Your WhatsApp number in international format (e.g. +34678281853). Used for lead assignment notifications.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($userData['role']); ?>" readonly>
                        <small class="form-hint">Contact an administrator to change your role</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3 class="card-title">Change Password</h3></div>
            <div class="card-body">
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label class="form-label">Current Password <span class="required">*</span></label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password <span class="required">*</span></label>
                        <input type="password" name="new_password" id="newPassword" class="form-control" required>
                        <small class="form-hint">Minimum <?php echo PASSWORD_MIN_LENGTH; ?> characters with uppercase, lowercase, and number</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password <span class="required">*</span></label>
                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required>
                        <div id="passwordMatch" class="form-hint"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
        
        <!-- Email Settings (Microsoft Office 365) -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="2" style="vertical-align:middle;margin-right:6px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Email Settings
                </h3>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom:16px;font-size:13px;">
                    Connect your Microsoft Office 365 account to send emails directly from the CRM.
                    Sent emails will appear in your Outlook Sent Items <strong>and</strong> be logged as interactions on the lead.
                </p>

                <?php
                $msConnected = !empty($userData['ms_access_token']) && !empty($userData['ms_refresh_token']);
                $msEmail = $userData['ms_connected_email'] ?? '';
                ?>

                <?php if ($msConnected): ?>
                    <!-- Connected state -->
                    <div style="background:linear-gradient(135deg,#e8f5e9,#c8e6c9);border-radius:var(--radius-md);padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2e7d32" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <div style="flex:1;">
                            <div style="font-weight:600;color:#2e7d32;font-size:14px;">Microsoft Office 365 Connected</div>
                            <div style="color:#388e3c;font-size:13px;margin-top:2px;">
                                Sending as: <strong><?php echo htmlspecialchars($msEmail); ?></strong>
                            </div>
                            <?php if (!empty($userData['ms_token_expires'])): ?>
                            <div style="color:#66bb6a;font-size:12px;margin-top:2px;">
                                Token expires: <?php echo date('M d, Y H:i', strtotime($userData['ms_token_expires'])); ?> (auto-refreshes)
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                        <!-- Test Connection -->
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="test_smtp">
                            <button type="submit" class="btn btn-outline" style="font-size:13px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                Test Connection
                            </button>
                        </form>

                        <!-- Reconnect -->
                        <?php
                        $reconnectState = bin2hex(random_bytes(16));
                        $_SESSION['ms_oauth_state'] = $reconnectState;
                        $reconnectUrl = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/authorize?'
                            . http_build_query([
                                'client_id'     => MS_CLIENT_ID,
                                'response_type' => 'code',
                                'redirect_uri'  => MS_REDIRECT_URI,
                                'response_mode' => 'query',
                                'scope'         => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read offline_access',
                                'state'         => $reconnectState,
                                'prompt'        => 'consent',
                            ]);
                        ?>
                        <a href="<?php echo htmlspecialchars($reconnectUrl); ?>" class="btn btn-outline" style="font-size:13px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            Reconnect
                        </a>

                        <!-- Disconnect -->
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Disconnect Microsoft Office 365? You will not be able to send emails until you reconnect.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="disconnect_microsoft">
                            <button type="submit" class="btn" style="font-size:13px;background:#ffebee;color:#c62828;border:1px solid #ef9a9a;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                Disconnect
                            </button>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Not connected state -->
                    <div style="background:linear-gradient(135deg,#fff3e0,#ffe0b2);border-radius:var(--radius-md);padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#e65100" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div style="flex:1;">
                            <div style="font-weight:600;color:#e65100;font-size:14px;">Not Connected</div>
                            <div style="color:#bf360c;font-size:13px;margin-top:2px;">
                                Connect your Microsoft Office 365 account to send emails from the CRM.
                            </div>
                        </div>
                    </div>

                    <?php
                    // Generate OAuth2 authorization URL
                    $oauthState = bin2hex(random_bytes(16));
                    $_SESSION['ms_oauth_state'] = $oauthState;
                    $authUrl = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/authorize?'
                        . http_build_query([
                            'client_id'     => MS_CLIENT_ID,
                            'response_type' => 'code',
                            'redirect_uri'  => MS_REDIRECT_URI,
                            'response_mode' => 'query',
                            'scope'         => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read offline_access',
                            'state'         => $oauthState,
                            'prompt'        => 'consent',
                        ]);
                    ?>
                    <a href="<?php echo htmlspecialchars($authUrl); ?>" class="btn" style="background:linear-gradient(135deg,#0078d4,#106ebe);color:#fff;border:none;box-shadow:0 2px 8px rgba(0,120,212,0.3);font-size:14px;padding:12px 24px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:8px;"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        Connect Microsoft Office 365
                    </a>
                    <p class="text-muted" style="margin-top:12px;font-size:12px;">
                        You'll be redirected to Microsoft to sign in and grant permission. No passwords are stored in the CRM.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Recent Activity</h3></div>
            <div class="card-body">
                <?php
                try {
                    $db = Database::getInstance();
                    $recentActivity = $db->query("SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10", [$userId])->fetchAll();
                    if (empty($recentActivity)): ?>
                        <p class="text-muted text-center">No recent activity</p>
                    <?php else: ?>
                        <div class="activity-timeline">
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></div>
                                        <div class="activity-meta"><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif;
                } catch (Exception $e) { echo '<p class="text-muted text-center">Error loading activity</p>'; } ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('confirmPassword').addEventListener('input', function() {
    var np = document.getElementById('newPassword').value;
    var cp = this.value;
    var m = document.getElementById('passwordMatch');
    if (cp.length > 0) {
        m.textContent = np === cp ? 'Passwords match' : 'Passwords do not match';
        m.style.color = np === cp ? 'var(--color-success)' : 'var(--color-danger)';
    } else { m.textContent = ''; }
});

document.getElementById('passwordForm').addEventListener('submit', function(e) {
    if (document.getElementById('newPassword').value !== document.getElementById('confirmPassword').value) {
        e.preventDefault();
        alert('Passwords do not match!');
    }
});
</script>

<?php include '../includes/footer.php'; ?>
