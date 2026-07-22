<?php
require_once __DIR__ . '/../lib/Mail.php';
require_once __DIR__ . '/../lib/Crypto.php';

class SettingsController {
    
    public static function profile() {
        $user = Auth::user();
        jsonResponse(['user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'avatar_color' => $user['avatar_color'],
            'initials' => initials($user['name']),
        ]]);
    }
    
    public static function updateProfile() {
        $data = input();
        $allowed = ['name', 'email', 'avatar_color'];
        $update = array_intersect_key($data, array_flip($allowed));
        
        // Validate email if changing
        if (isset($update['email'])) {
            $update['email'] = trim($update['email']);
            if (!filter_var($update['email'], FILTER_VALIDATE_EMAIL)) {
                jsonError('Please enter a valid email address');
            }
            // Check email isn't taken by another user
            $existing = DB::fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$update['email'], Auth::userId()]);
            if ($existing) jsonError('That email is already in use');
        }
        
        if ($update) {
            DB::update('users', $update, 'id = ?', [Auth::userId()]);
        }
        jsonResponse(['ok' => true]);
    }
    
    public static function updatePassword() {
        $data = input();
        $current = $data['current_password'] ?? '';
        $new = $data['new_password'] ?? '';
        if (strlen($new) < 8) jsonError('Password must be at least 8 characters');
        
        $user = DB::fetch("SELECT password FROM users WHERE id = ?", [Auth::userId()]);
        if (!password_verify($current, $user['password'])) {
            jsonError('Current password is incorrect');
        }
        
        $hash = password_hash($new, PASSWORD_DEFAULT);
        DB::update('users', ['password' => $hash], 'id = ?', [Auth::userId()]);
        jsonResponse(['ok' => true]);
    }
    
    public static function notifications() {
        $settings = DB::fetch("SELECT * FROM user_settings WHERE user_id = ?", [Auth::userId()]);
        if (!$settings) {
            DB::insert('user_settings', ['user_id' => Auth::userId()]);
            $settings = DB::fetch("SELECT * FROM user_settings WHERE user_id = ?", [Auth::userId()]);
        }
        jsonResponse(['settings' => [
            'notify_assigned' => (bool)$settings['notify_assigned'],
            'notify_mentions' => (bool)$settings['notify_mentions'],
            'notify_chat' => (bool)$settings['notify_chat'],
            'notify_comments' => isset($settings['notify_comments']) ? (bool)$settings['notify_comments'] : true,
            'notify_digest' => (bool)$settings['notify_digest'],
            'week_start' => $settings['week_start'],
            'default_screen' => $settings['default_screen'] ?? 'mytasks',
            'email_notify_pref' => $settings['email_notify_pref'] ?? 'all',
        ]]);
    }
    
    public static function updateNotifications() {
        $data = input();
        $allowed = ['notify_assigned', 'notify_mentions', 'notify_chat', 'notify_comments', 'notify_digest', 'week_start', 'default_screen', 'email_notify_pref'];
        $update = [];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if (is_bool($data[$field])) {
                    $update[$field] = $data[$field] ? 1 : 0;
                } else {
                    $update[$field] = $data[$field];
                }
            }
        }
        
        $existing = DB::fetch("SELECT id FROM user_settings WHERE user_id = ?", [Auth::userId()]);
        if (!$existing) {
            DB::insert('user_settings', array_merge(['user_id' => Auth::userId()], $update));
        } else {
            DB::update('user_settings', $update, 'user_id = ?', [Auth::userId()]);
        }
        jsonResponse(['ok' => true]);
    }
    
    // ===== SMTP Settings =====
    public static function smtp() {
        $wsId = Auth::workspaceId();
        $cfg = DB::fetch("SELECT id, host, port, username, from_name, from_email, encryption, is_active FROM smtp_settings WHERE workspace_id = ?", [$wsId]);
        if (!$cfg) {
            jsonResponse(['settings' => null]);
        } else {
            jsonResponse(['settings' => [
                'id' => (int)$cfg['id'],
                'host' => $cfg['host'],
                'port' => (int)$cfg['port'],
                'username' => $cfg['username'],
                'from_name' => $cfg['from_name'],
                'from_email' => $cfg['from_email'],
                'encryption' => $cfg['encryption'],
                'is_active' => (bool)$cfg['is_active'],
            ]]);
        }
    }
    
    public static function updateSmtp() {
        Auth::requireAdmin();
        $data = input();
        $wsId = Auth::workspaceId();
        
        $update = [
            'host' => $data['host'] ?? '',
            'port' => (int)($data['port'] ?? 465),
            'username' => $data['username'] ?? '',
            'from_name' => $data['from_name'] ?? 'VGold',
            'from_email' => $data['from_email'] ?? '',
            'encryption' => $data['encryption'] ?? 'ssl',
            'is_active' => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
        ];
        
        // Only update password if provided (don't blank existing). Encrypted at rest (H6).
        if (!empty($data['password'])) {
            $update['password'] = Crypto::encrypt($data['password']);
        }
        
        $existing = DB::fetch("SELECT id FROM smtp_settings WHERE workspace_id = ?", [$wsId]);
        if ($existing) {
            DB::update('smtp_settings', $update, 'workspace_id = ?', [$wsId]);
        } else {
            $update['workspace_id'] = $wsId;
            if (!isset($update['password'])) $update['password'] = '';
            DB::insert('smtp_settings', $update);
        }
        jsonResponse(['ok' => true]);
    }
    
    public static function testSmtp() {
        Auth::requireAdmin();
        $wsId = Auth::workspaceId();
        $user = Auth::user();
        
        if (!Mail::isConfigured($wsId)) {
            jsonError('SMTP is not configured yet');
        }
        
        $html = '<h2>VGold SMTP Test</h2><p>This is a test email from VGold. If you received this, your SMTP settings are working correctly.</p><p>Sent: ' . date('Y-m-d H:i:s') . '</p>';
        
        $sent = Mail::send($user['email'], $user['name'], 'VGold SMTP Test', $html);
        if ($sent) {
            jsonResponse(['ok' => true, 'message' => 'Test email sent to ' . $user['email']]);
        } else {
            jsonError('Failed to send test email. Check your SMTP settings.');
        }
    }
    
    // ===== API Keys =====
    public static function apiKeys() {
        $keys = DB::fetchAll("SELECT * FROM user_api_keys WHERE user_id = ?", [Auth::userId()]);
        jsonResponse(['keys' => array_map(fn($k) => [
            'id' => (int)$k['id'],
            'provider' => $k['provider'],
            'has_key' => !empty($k['api_key']),
            'base_url' => $k['base_url'],
            'model' => $k['model'],
            'is_active' => (bool)$k['is_active'],
        ], $keys)]);
    }
    
    public static function updateApiKey() {
        $data = input();
        requireFields(['provider'], $data);
        
        $existing = DB::fetch(
            "SELECT id FROM user_api_keys WHERE user_id = ? AND provider = ?",
            [Auth::userId(), $data['provider']]
        );
        
        $update = [
            'api_key' => isset($data['api_key']) && $data['api_key'] !== '' ? Crypto::encrypt($data['api_key']) : ($data['api_key'] ?? ''),
            'base_url' => $data['base_url'] ?? null,
            'model' => $data['model'] ?? null,
            'is_active' => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
        ];
        
        if ($existing) {
            DB::update('user_api_keys', $update, 'user_id = ? AND provider = ?', [Auth::userId(), $data['provider']]);
        } else {
            DB::insert('user_api_keys', array_merge([
                'user_id' => Auth::userId(),
                'provider' => $data['provider'],
            ], $update));
        }
        jsonResponse(['ok' => true]);
    }
    
    public static function deleteApiKey() {
        $data = input();
        requireFields(['provider'], $data);
        DB::delete('user_api_keys', 'user_id = ? AND provider = ?', [Auth::userId(), $data['provider']]);
        jsonResponse(['ok' => true]);
    }
    
    // ===== Team Management =====
    public static function team() {
        $wsId = Auth::workspaceId();
        $members = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.avatar_color, u.is_active, u.auth_provider, wm.role FROM users u 
             JOIN workspace_members wm ON u.id = wm.user_id WHERE wm.workspace_id = ?
             ORDER BY wm.role DESC, u.name ASC",
            [$wsId]
        );
        
        $invites = DB::fetchAll(
            "SELECT * FROM invitations WHERE workspace_id = ? AND status = 'pending'",
            [$wsId]
        );
        
        jsonResponse([
            'members' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'name' => $m['name'],
                'email' => $m['email'],
                'initials' => initials($m['name']),
                'avatar_color' => $m['avatar_color'],
                'role' => $m['role'],
                'auth_provider' => $m['auth_provider'],
                'is_active' => (bool)$m['is_active'],
            ], $members),
            'invites' => array_map(fn($i) => [
                'id' => (int)$i['id'],
                'email' => $i['email'],
                'role' => $i['role'],
                'status' => ucfirst($i['status']),
            ], $invites),
        ]);
    }
    
    public static function invite() {
        Auth::requireAdmin();
        $data = input();
        requireFields(['email'], $data);
        
        $token = bin2hex(random_bytes(32));
        DB::insert('invitations', [
            'workspace_id' => Auth::workspaceId(),
            'email' => $data['email'],
            'role' => $data['role'] ?? 'member',
            'token' => $token,
            'invited_by' => Auth::userId(),
        ]);
        
        jsonResponse(['ok' => true, 'token' => $token]);
    }
    
    // Create a new user directly (admin)
    public static function createUser() {
        Auth::requireAdmin();
        $data = input();
        requireFields(['name', 'email'], $data);
        
        $email = strtolower(trim($data['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email');
        if (DB::fetch("SELECT id FROM users WHERE email = ?", [$email])) jsonError('User already exists');
        
        // Determine auth provider: default by domain, admin can override
        $isInternal = str_ends_with($email, '@victorygenomics.com');
        $ap = $data['auth_provider'] ?? '';
        $provider = $ap === 'password' ? 'password' : ($ap === 'microsoft' ? 'microsoft' : ($isInternal ? 'microsoft' : 'password'));
        $role = ($data['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        
        $colors = ['#9C8060', '#8A6D4F', '#4A7C9B', '#C99520', '#6B8E5A', '#B0432B', '#7e6549'];
        $color = $colors[array_rand($colors)];
        
        // Password: required for password users, empty for microsoft users
        if ($provider === 'password') {
            if (empty($data['password']) || strlen($data['password']) < 6)
                jsonError('Password must be at least 6 characters');
            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        } else {
            $hash = ''; // MS users don't need a local password
        }
        
        $userId = DB::insert('users', [
            'name' => $data['name'],
            'email' => $email,
            'password' => $hash,
            'auth_provider' => $provider,
            'avatar_color' => $color,
            'role' => $role,
        ]);
        
        DB::insert('workspace_members', [
            'workspace_id' => Auth::workspaceId(),
            'user_id' => $userId,
            'role' => $role,
        ]);
        
        DB::insert('user_settings', ['user_id' => $userId]);

        foreach (($data['module_access'] ?? []) as $moduleKey) {
            if (!isset(Authz::CRM_MODULES[$moduleKey])) continue;
            DB::insert('user_module_access', [
                'workspace_id' => Auth::workspaceId(),
                'user_id' => $userId,
                'module_key' => $moduleKey,
                'can_access' => 1,
                'updated_by' => Auth::userId(),
            ]);
        }
        
        // Assign to selected projects
        foreach (($data['project_ids'] ?? []) as $pid) {
            $pid = (int)$pid;
            DB::query("INSERT IGNORE INTO project_members (project_id, user_id) VALUES (?, ?)", [$pid, $userId]);
        }
        
        // For password users: create invite token and send email
        if ($provider === 'password') {
            $token = bin2hex(random_bytes(32));
            DB::insert('invitations', [
                'workspace_id' => Auth::workspaceId(),
                'email' => $email,
                'role' => $role,
                'token' => $token,
                'invited_by' => Auth::userId(),
            ]);
            // Try to send notification email
            try {
                Mail::sendNotification($userId, 'You have been invited to VGold',
                    "<p>An admin added you to VGold. Set your password to get started:</p>" .
                    "<p><a href='https://vgold.victorygenomics.com/set-password?token={$token}'>Set your password</a></p>");
            } catch (Exception $e) { /* email is best-effort */ }
        } else {
            // For MS users: send welcome email
            try {
                Mail::sendNotification($userId, 'You have been added to VGold',
                    "<p>You can now sign in to VGold using your Victory Genomics Microsoft account.</p>" .
                    "<p><a href='https://vgold.victorygenomics.com'>Open VGold</a></p>");
            } catch (Exception $e) { /* email is best-effort */ }
        }
        
        jsonResponse(['ok' => true, 'user_id' => (int)$userId]);
    }
    
    // Change a user's role (admin only, with last-admin safeguard)
    public static function changeRole() {
        Auth::requireAdmin();
        $data = input();
        requireFields(['user_id', 'role'], $data);
        $userId = (int)$data['user_id'];
        $role = $data['role'] === 'admin' ? 'admin' : 'member';
        
        $target = DB::fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$target) jsonError('User not found');
        
        // Last-admin safeguard: refuse to demote the last admin
        if ($target['role'] === 'admin' && $role === 'member') {
            $adminCount = DB::fetch("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin' AND is_active = 1");
            if ($adminCount['cnt'] <= 1) jsonError('Cannot demote the last remaining admin');
        }
        
        DB::update('users', ['role' => $role], 'id = ?', [$userId]);
        DB::update('workspace_members', ['role' => $role], 'user_id = ? AND workspace_id = ?', [$userId, Auth::workspaceId()]);
        
        jsonResponse(['ok' => true]);
    }

    // ===== Unified module access =====
    public static function moduleAccess() {
        Auth::requireAdmin();
        $workspaceId = Auth::workspaceId();
        $members = DB::fetchAll(
            "SELECT u.id, u.name, u.email, wm.role
             FROM users u JOIN workspace_members wm ON wm.user_id = u.id
             WHERE wm.workspace_id = ? ORDER BY wm.role DESC, u.name ASC",
            [$workspaceId]
        );
        jsonResponse([
            'modules' => Authz::moduleDefinitions(),
            'members' => array_map(fn($member) => [
                'id' => (int)$member['id'],
                'name' => $member['name'],
                'email' => $member['email'],
                'role' => $member['role'],
                'access' => Authz::grantedModules((int)$member['id'], $workspaceId),
            ], $members),
        ]);
    }

    public static function updateModuleAccess() {
        Auth::requireAdmin();
        $data = input();
        requireFields(['user_id', 'modules'], $data);
        if (!is_array($data['modules'])) jsonError('Modules must be a list');
        $userId = (int)$data['user_id'];
        $workspaceId = Auth::workspaceId();
        $member = DB::fetch(
            "SELECT wm.role FROM workspace_members wm WHERE wm.workspace_id = ? AND wm.user_id = ?",
            [$workspaceId, $userId]
        );
        if (!$member) jsonError('User is not a member of this workspace', 404);

        $modules = array_values(array_unique(array_filter(
            $data['modules'],
            fn($key) => is_string($key) && isset(Authz::CRM_MODULES[$key])
        )));
        DB::conn()->beginTransaction();
        try {
            DB::delete('user_module_access', 'workspace_id = ? AND user_id = ?', [$workspaceId, $userId]);
            foreach ($modules as $moduleKey) {
                DB::insert('user_module_access', [
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'module_key' => $moduleKey,
                    'can_access' => 1,
                    'updated_by' => Auth::userId(),
                ]);
            }
            DB::conn()->commit();
        } catch (\Throwable $e) {
            DB::conn()->rollBack();
            throw $e;
        }
        jsonResponse(['ok' => true, 'modules' => $modules]);
    }

    // ===== Centralized CRM settings =====
    public static function crmSettings() {
        Auth::requireAdmin();
        $defaults = self::crmSettingDefaults();
        $rows = DB::fetchAll("SELECT setting_key, setting_value FROM crm_settings");
        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $defaults)) $defaults[$row['setting_key']] = $row['setting_value'];
        }
        $followUp = DB::fetch(
            "SELECT setting_value FROM workspace_settings WHERE workspace_id = ? AND setting_group = 'crm' AND setting_key = 'follow_up_project_name'",
            [Auth::workspaceId()]
        );
        if ($followUp) $defaults['follow_up_project_name'] = $followUp['setting_value'];
        jsonResponse(['settings' => $defaults]);
    }

    public static function updateCrmSettings() {
        Auth::requireAdmin();
        $data = input();
        $allowed = array_keys(self::crmSettingDefaults());
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : trim((string)$data[$key]);
            if ($key === 'follow_up_project_name') {
                DB::query(
                    "INSERT INTO workspace_settings (workspace_id, setting_group, setting_key, setting_value, updated_by)
                     VALUES (?, 'crm', ?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
                    [Auth::workspaceId(), $key, $value, Auth::userId()]
                );
            } else {
                DB::query(
                    "INSERT INTO crm_settings (setting_key, setting_value, setting_type)
                     VALUES (?, ?, 'text') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$key, $value]
                );
            }
        }
        jsonResponse(['ok' => true]);
    }

    private static function crmSettingDefaults() {
        return [
            'company_name' => 'Victory Genomics',
            'company_email' => '',
            'company_phone' => '',
            'timezone' => 'Europe/Madrid',
            'leads_per_page' => '25',
            'auto_assign_leads' => '0',
            'follow_up_project_name' => 'CRM Follow-ups',
        ];
    }
    
    // Toggle user active status (admin only, with last-admin safeguard)
    public static function toggleUserActive() {
        Auth::requireAdmin();
        $data = input();
        requireFields(['user_id'], $data);
        $userId = (int)$data['user_id'];
        
        if ($userId === Auth::userId()) jsonError('You cannot deactivate your own account');
        
        $target = DB::fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$target) jsonError('User not found');
        
        // Last-admin safeguard: refuse to deactivate the last admin
        if ($target['role'] === 'admin' && $target['is_active']) {
            $adminCount = DB::fetch("SELECT COUNT(*) as cnt FROM users WHERE role = 'admin' AND is_active = 1");
            if ($adminCount['cnt'] <= 1) jsonError('Cannot deactivate the last remaining admin');
        }
        
        $newActive = $target['is_active'] ? 0 : 1;
        DB::update('users', ['is_active' => $newActive], 'id = ?', [$userId]);
        
        jsonResponse(['ok' => true, 'is_active' => $newActive]);
    }
    
    // Delete a user (admin)
    public static function deleteUser() {
        Auth::requireAdmin();
        $data = input();
        requireFields(['user_id'], $data);
        
        $userId = (int)$data['user_id'];
        $reassignTo = isset($data['reassign_to']) ? (int)$data['reassign_to'] : null;
        
        // Can't delete yourself
        if ($userId === Auth::userId()) jsonError('You cannot delete your own account');
        
        // Can't delete another admin
        $target = DB::fetch("SELECT role, crm_user_id FROM users WHERE id = ?", [$userId]);
        if (!$target) jsonError('User not found');
        if ($target['role'] === 'admin') jsonError('Cannot delete an admin user');
        
        // Can't reassign to the user being deleted
        if ($reassignTo && $reassignTo === $userId) jsonError('Cannot reassign to the user being removed');
        $reassignUser = null;
        if ($reassignTo) {
            $reassignUser = DB::fetch(
                "SELECT u.crm_user_id FROM users u JOIN workspace_members wm ON wm.user_id = u.id WHERE u.id = ? AND wm.workspace_id = ?",
                [$reassignTo, Auth::workspaceId()]
            );
            if (!$reassignUser) jsonError('Reassignment user is not in this workspace');
        }
        
        // If reassigning, transfer ownership of projects, tasks, and files
        if ($reassignTo) {
            // Transfer project membership
            DB::query("INSERT IGNORE INTO project_members (project_id, user_id, role) 
                       SELECT project_id, ?, role FROM project_members WHERE user_id = ?", [$reassignTo, $userId]);
            
            // Transfer task assignments
            DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) 
                       SELECT task_id, ? FROM task_assignees WHERE task_id IN (SELECT id FROM tasks WHERE assigned_to = ?)", [$reassignTo, $userId]);
            
            // Update tasks.assigned_to
            DB::update('tasks', ['assigned_to' => $reassignTo], 'assigned_to = ?', [$userId]);
            
            // Transfer task_assignees
            DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) 
                       SELECT task_id, ? FROM task_assignees WHERE user_id = ?", [$reassignTo, $userId]);
            
            // Transfer file uploads (keep original uploader but mark reassigned)
            DB::query("UPDATE files SET uploaded_by = ? WHERE uploaded_by = ?", [$reassignTo, $userId]);

            // CRM records use legacy CRM user IDs, not VGold user IDs.
            if (!empty($target['crm_user_id']) && !empty($reassignUser['crm_user_id'])) {
                DB::query("UPDATE crm_leads SET assigned_to = ? WHERE assigned_to = ?", [$reassignUser['crm_user_id'], $target['crm_user_id']]);
                DB::query("UPDATE crm_leads SET created_by = ? WHERE created_by = ?", [$reassignUser['crm_user_id'], $target['crm_user_id']]);
                DB::query("UPDATE crm_interactions SET user_id = ? WHERE user_id = ?", [$reassignUser['crm_user_id'], $target['crm_user_id']]);
            }
        }
        
        // Remove from workspace
        DB::delete('workspace_members', 'user_id = ? AND workspace_id = ?', [$userId, Auth::workspaceId()]);
        
        // Remove from project_members
        DB::delete('project_members', 'user_id = ?', [$userId]);
        
        // Remove from task_assignees
        DB::delete('task_assignees', 'user_id = ?', [$userId]);
        
        // Unassign tasks (only if not reassigned)
        if (!$reassignTo) {
            DB::update('tasks', ['assigned_to' => null], 'assigned_to = ?', [$userId]);
            if (!empty($target['crm_user_id'])) {
                DB::query("UPDATE crm_leads SET assigned_to = NULL WHERE assigned_to = ?", [$target['crm_user_id']]);
            }
        }

        DB::delete('user_module_access', 'workspace_id = ? AND user_id = ?', [Auth::workspaceId(), $userId]);
        
        // Delete the user
        DB::delete('users', 'id = ?', [$userId]);
        
        jsonResponse(['ok' => true]);
    }
    
    public static function workspaceMembers() {
        $wsId = Auth::workspaceId();
        $members = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.avatar_color, wm.role FROM users u 
             JOIN workspace_members wm ON u.id = wm.user_id WHERE wm.workspace_id = ?",
            [$wsId]
        );
        jsonResponse(['members' => array_map(fn($m) => [
            'id' => (int)$m['id'],
            'name' => $m['name'],
            'email' => $m['email'],
            'initials' => initials($m['name']),
            'avatar_color' => $m['avatar_color'],
            'bg' => $m['avatar_color'],
            'role' => $m['role'],
            'roleBg' => $m['role'] === 'admin' ? '#E4EDE7' : '#F2E6CF',
            'roleColor' => $m['role'] === 'admin' ? '#25563F' : '#6E5638',
        ], $members)]);
    }

    public static function crmRoleMap() {
        Auth::requireAdmin();
        $rows = DB::fetchAll("SELECT id, crm_role, vgold_role FROM crm_role_map ORDER BY id");
        $counts = [];
        foreach (DB::fetchAll("SELECT crm_role, COUNT(*) c FROM users WHERE crm_user_id IS NOT NULL AND crm_role IS NOT NULL GROUP BY crm_role") as $row) {
            $counts[$row['crm_role']] = (int)$row['c'];
        }
        jsonResponse(['mappings' => array_map(fn($row) => [
            'id' => (int)$row['id'],
            'crm_role' => $row['crm_role'],
            'vgold_role' => $row['vgold_role'],
            'user_count' => $counts[$row['crm_role']] ?? 0,
        ], $rows)]);
    }

    public static function updateCrmRoleMap() {
        Auth::requireAdmin();
        $data = input();
        $mappings = $data['mappings'] ?? null;
        if (!is_array($mappings) || !$mappings) jsonError('No mappings provided');

        $applyToUsers = !empty($data['apply_to_users']);
        $updated = 0;
        foreach ($mappings as $mapping) {
            $crmRole = trim($mapping['crm_role'] ?? '');
            $vgoldRole = ($mapping['vgold_role'] ?? '') === 'admin' ? 'admin' : 'member';
            if ($crmRole === '') continue;
            DB::query(
                "INSERT INTO crm_role_map (crm_role, vgold_role) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE vgold_role = VALUES(vgold_role)",
                [$crmRole, $vgoldRole]
            );
            $updated++;

            if ($applyToUsers) {
                $targets = DB::fetchAll("SELECT id, role FROM users WHERE crm_role = ? AND crm_user_id IS NOT NULL", [$crmRole]);
                foreach ($targets as $targetUser) {
                    if ($targetUser['role'] === $vgoldRole) continue;
                    if ($targetUser['role'] === 'admin' && $vgoldRole === 'member') {
                        $adminCount = DB::fetch("SELECT COUNT(*) c FROM users WHERE role = 'admin' AND is_active = 1");
                        if ((int)$adminCount['c'] <= 1) continue;
                    }
                    DB::update('users', ['role' => $vgoldRole], 'id = ?', [(int)$targetUser['id']]);
                    DB::update('workspace_members', ['role' => $vgoldRole], 'user_id = ? AND workspace_id = ?', [(int)$targetUser['id'], Auth::workspaceId()]);
                }
            }
        }
        jsonResponse(['ok' => true, 'updated' => $updated, 'applied_to_users' => $applyToUsers]);
    }
}
