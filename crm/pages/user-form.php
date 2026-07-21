<?php
/**
 * Victory Genomics CRM - User Form (Add/Edit)
 * CSRF protected, fixed logActivity, password strength, Apple-style
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole(['Admin', 'Sales Manager']);

$pageTitle = 'User Form';
$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEdit = $userId > 0;

$userData = null;
if ($isEdit) {
    try {
        $db = Database::getInstance();
        $userData = $db->findOne('users', ['user_id' => $userId]);
        if (!$userData) {
            $_SESSION['error'] = "User not found";
            header('Location: users.php');
            exit;
        }
        $pageTitle = 'Edit User';
    } catch (Exception $e) {
        $_SESSION['error'] = "Error loading user: " . $e->getMessage();
        header('Location: users.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    
    try {
        $db = Database::getInstance();
        
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? '';
        $status = $_POST['status'] ?? 'Active';
        
        if (empty($username) || empty($email) || empty($fullName) || empty($role)) {
            throw new Exception("All required fields must be filled");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }
        
        // Check username uniqueness
        if ($isEdit) {
            $existing = $db->query("SELECT user_id FROM users WHERE username = ? AND user_id != ?", [$username, $userId])->fetch();
        } else {
            $existing = $db->findOne('users', ['username' => $username]);
        }
        if ($existing) {
            throw new Exception("Username already exists");
        }
        
        $whatsappNumber = trim($_POST['whatsapp_number'] ?? '');
        $waNotifyEnabled = isset($_POST['wa_notify_enabled']) ? '1' : '0';

        $data = [
            'username' => $username,
            'email' => $email,
            'full_name' => $fullName,
            'role' => $role,
            'status' => $status,
            'whatsapp_number' => $whatsappNumber ?: null,
            'wa_notify_enabled' => $waNotifyEnabled,
        ];
        
        // Handle password
        if (!$isEdit) {
            $password = $_POST['password'] ?? '';
            if (empty($password)) {
                throw new Exception("Password is required for new users");
            }
            $passwordErrors = validatePasswordStrength($password);
            if (!empty($passwordErrors)) {
                throw new Exception(implode('. ', $passwordErrors));
            }
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        } elseif (!empty($_POST['password'])) {
            $password = $_POST['password'];
            $passwordErrors = validatePasswordStrength($password);
            if (!empty($passwordErrors)) {
                throw new Exception(implode('. ', $passwordErrors));
            }
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        
        if ($isEdit) {
            $db->update('users', $data, ['user_id' => $userId]);
            logActivity(getCurrentUserId(), 'Update User', 'User', $userId, "Updated user: $username");
            $_SESSION['success'] = "User updated successfully";
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $newUserId = $db->insert('users', $data);
            logActivity(getCurrentUserId(), 'Create User', 'User', $newUserId, "Created user: $username");
            $_SESSION['success'] = "User created successfully";
        }
        
        header('Location: users.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

include '../includes/header.php';
$csrf_token = generateCSRFToken();
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo $isEdit ? 'Edit User' : 'Add New User'; ?></h1>
        <p class="page-subtitle"><?php echo $isEdit ? 'Update user information and permissions' : 'Create a new system user'; ?></p>
    </div>
    <a href="users.php" class="btn btn-outline">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Users
    </a>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<form method="POST" class="form-container">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Basic Information</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Username <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($userData['username'] ?? ''); ?>" <?php echo $isEdit ? 'readonly' : ''; ?>>
                    <small class="form-hint">Username cannot be changed after creation</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">WhatsApp Number</label>
                    <input type="tel" name="whatsapp_number" class="form-control" placeholder="+34678281853" value="<?php echo htmlspecialchars($userData['whatsapp_number'] ?? ''); ?>">
                    <small class="form-hint">International format (e.g. +34678281853). Required to receive lead assignment notifications.</small>
                </div>
                <div class="form-group" style="margin-top:12px;">
                    <label class="form-check">
                        <input type="checkbox" name="wa_notify_enabled" value="1" <?php echo (intval($userData['wa_notify_enabled'] ?? 0)) === 1 ? 'checked' : ''; ?>>
                        <span class="form-check-label">Receive WhatsApp lead assignment notifications</span>
                    </label>
                    <small class="form-hint" style="margin-left:24px;">When a lead is assigned to this user, they will receive a WhatsApp notification.</small>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3 class="card-title">Security &amp; Access</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label"><?php echo $isEdit ? '' : '<span class="required">*</span> '; ?>Password</label>
                    <input type="password" name="password" class="form-control" <?php echo $isEdit ? '' : 'required'; ?>>
                    <small class="form-hint"><?php echo $isEdit ? 'Leave blank to keep current password' : 'Min ' . PASSWORD_MIN_LENGTH . ' chars with uppercase, lowercase, and number'; ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label">Role <span class="required">*</span></label>
                    <select name="role" class="form-control" required>
                        <option value="">Select Role</option>
                        <option value="Admin" <?php echo ($userData['role'] ?? '') === 'Admin' ? 'selected' : ''; ?>>Admin - Full system access</option>
                        <option value="Sales Manager" <?php echo ($userData['role'] ?? '') === 'Sales Manager' ? 'selected' : ''; ?>>Sales Manager - Manage team &amp; leads</option>
                        <option value="Sales Rep" <?php echo ($userData['role'] ?? '') === 'Sales Rep' ? 'selected' : ''; ?>>Sales Rep - Manage assigned leads</option>
                        <option value="Viewer" <?php echo ($userData['role'] ?? '') === 'Viewer' ? 'selected' : ''; ?>>Viewer - Read-only access</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Status <span class="required">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="Active" <?php echo ($userData['status'] ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo ($userData['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update User' : 'Create User'; ?></button>
        <a href="users.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php include '../includes/footer.php'; ?>
