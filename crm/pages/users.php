<?php
/**
 * Victory Genomics CRM - User Management
 * CSRF protected, fixed logActivity signatures, Apple-style, no FA
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole(['Admin', 'Sales Manager']);

$pageTitle = 'User Management';

// Handle user actions (delete, activate, deactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    
    $action = $_POST['action'];
    $userId = intval($_POST['user_id'] ?? 0);
    
    try {
        $db = Database::getInstance();
        
        switch ($action) {
            case 'delete':
                if (getCurrentUserId() == $userId) {
                    throw new Exception("You cannot delete your own account");
                }
                $db->delete('users', ['user_id' => $userId]);
                logActivity(getCurrentUserId(), 'Delete User', 'User', $userId, "Deleted user ID: $userId");
                $_SESSION['success'] = "User deleted successfully";
                break;
                
            case 'toggle_status':
                $newStatus = $_POST['new_status'];
                $db->update('users', ['status' => $newStatus], ['user_id' => $userId]);
                logActivity(getCurrentUserId(), 'Toggle User Status', 'User', $userId, "Changed status to: $newStatus");
                $_SESSION['success'] = "User status updated successfully";
                break;
        }
        
        header('Location: users.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

// Fetch all users
try {
    $db = Database::getInstance();
    $users = $db->query("
        SELECT u.*, 
               COUNT(DISTINCT l.lead_id) as assigned_leads,
               COUNT(DISTINCT i.interaction_id) as total_interactions
        FROM users u
        LEFT JOIN leads l ON u.user_id = l.assigned_to
        LEFT JOIN interactions i ON u.user_id = i.user_id
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    $users = [];
    $_SESSION['error'] = "Error loading users: " . $e->getMessage();
}

$roleStats = [];
foreach ($users as $user) {
    $role = $user['role'];
    $roleStats[$role] = ($roleStats[$role] ?? 0) + 1;
}

include '../includes/header.php';
$csrf_token = generateCSRFToken();
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="page-subtitle">Manage system users and permissions</p>
    </div>
    <a href="user-form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
        Add New User
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="grid grid-3">
    <div class="stat-card">
        <div class="stat-icon bg-gradient-primary">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="stat-details"><div class="stat-value"><?php echo count($users); ?></div><div class="stat-label">Total Users</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-success">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-details"><div class="stat-value"><?php echo count(array_filter($users, fn($u) => $u['status'] === 'Active')); ?></div><div class="stat-label">Active Users</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-gradient-info">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div class="stat-details"><div class="stat-value"><?php echo $roleStats['Admin'] ?? 0; ?></div><div class="stat-label">Administrators</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Users</h3>
        <div class="card-actions">
            <input type="text" id="searchUsers" class="form-control" placeholder="Search users..." class="w-300">
        </div>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table class="table" id="usersTable">
                <thead>
                    <tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>WA Notify</th><th>Leads</th><th>Interactions</th><th>Last Login</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="9" class="text-center">No users found</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 2)); ?></div>
                                        <div>
                                            <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                            <div class="user-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><span class="badge bg-gray-100 text-gray-800"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                <td><span class="badge badge-<?php echo $user['status'] === 'Active' ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($user['status']); ?></span></td>
                                <td>
                                    <?php if (intval($user['wa_notify_enabled'] ?? 0) === 1 && !empty($user['whatsapp_number'])): ?>
                                        <span class="badge badge-success" title="WhatsApp: <?php echo htmlspecialchars($user['whatsapp_number']); ?>">On</span>
                                    <?php elseif (intval($user['wa_notify_enabled'] ?? 0) === 1): ?>
                                        <span class="badge badge-warning" title="Enabled but no WhatsApp number">No #</span>
                                    <?php else: ?>
                                        <span class="text-muted">Off</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo intval($user['assigned_leads']); ?></td>
                                <td><?php echo intval($user['total_interactions']); ?></td>
                                <td><?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : '<span class="text-muted">Never</span>'; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="user-form.php?id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-info" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </a>
                                        <?php if (getCurrentUserId() != $user['user_id']): ?>
                                            <?php if (hasRole('Admin') && $user['status'] === 'Active'): ?>
                                                <button type="button" class="btn btn-sm btn-outline" title="Switch to this user" onclick="handleSwitchUser(<?php echo $user['user_id']; ?>)">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 3h5v5"/><path d="M21 3l-7 7"/><path d="M8 21H3v-5"/><path d="M3 21l7-7"/></svg>
                                                </button>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle user status?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $user['status'] === 'Active' ? 'Inactive' : 'Active'; ?>">
                                                <button type="submit" class="btn btn-sm btn-warning" title="<?php echo $user['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?>">
                                                    <?php echo $user['status'] === 'Active' ? '&#x2715;' : '&#x2713;'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user permanently?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchUsers');
    var rows = document.querySelectorAll('#usersTable tbody tr');
    searchInput.addEventListener('input', function() {
        var term = this.value.toLowerCase();
        rows.forEach(function(row) { row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none'; });
    });
});
</script>

<?php include '../includes/footer.php'; ?>
