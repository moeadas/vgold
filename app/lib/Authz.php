<?php
// VGo Authorization helpers — workspace-scoped access control

class Authz {
    public const CRM_MODULES = [
        'crm.dashboard' => 'CRM overview',
        'crm.leads' => 'Leads',
        'crm.interactions' => 'Interactions & follow-ups',
        'crm.proposals' => 'Proposals',
        'crm.email' => 'Email marketing',
        'crm.communications' => 'VoIP & WhatsApp',
        'crm.automation' => 'Automations',
        'crm.reports' => 'Reports & exports',
        'crm.knowledge' => 'Knowledge hub',
    ];

    /**
     * Accounting & Finance modules.
     *
     * IMPORTANT — these do NOT follow the CRM rule where every workspace admin
     * is implicitly granted everything. Accounting is explicit-grant only: a
     * user sees it if, and only if, a row exists in user_module_access (or they
     * are the bootstrap owner below). That keeps finance data invisible to
     * other admins until it is deliberately shared from Settings → Team.
     */
    public const ACC_MODULES = [
        'acc.dashboard'  => 'Finance overview',
        'acc.invoices'   => 'Invoices',
        'acc.bills'      => 'Bills & expenses',
        'acc.customers'  => 'Customers',
        'acc.vendors'    => 'Vendors',
        'acc.banking'    => 'Banking & transactions',
        'acc.accounting' => 'Journal & chart of accounts',
        'acc.catalog'    => 'Items, categories & taxes',
        'acc.recurring'  => 'Recurring schedules',
        'acc.reports'    => 'Financial reports',
        'acc.settings'   => 'Accounting settings',
    ];

    /**
     * Bootstrap owner: always holds every accounting module so the app can
     * never lock itself out. Additional people are granted from Settings.
     */
    public const ACC_OWNER_EMAIL = 'm.abuadas@victorygenomics.com';

    public static function allModules() {
        return self::CRM_MODULES + self::ACC_MODULES;
    }

    public static function moduleDefinitions() {
        $out = [];
        foreach (self::CRM_MODULES as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'group' => 'crm', 'group_label' => 'CRM', 'admin_implicit' => true];
        }
        foreach (self::ACC_MODULES as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'group' => 'acc', 'group_label' => 'Accounting & Finance', 'admin_implicit' => false];
        }
        return $out;
    }

    /** Is this user the hard-wired accounting owner? */
    public static function isAccOwner($userId = null) {
        $userId = $userId ?? Auth::userId();
        if (!$userId) return false;
        $row = DB::fetch("SELECT email FROM users WHERE id = ?", [$userId]);
        return $row && strcasecmp(trim($row['email']), self::ACC_OWNER_EMAIL) === 0;
    }

    public static function grantedModules($userId = null, $workspaceId = null) {
        $userId = $userId ?? Auth::userId();
        $workspaceId = $workspaceId ?? Auth::workspaceId();
        $user = DB::fetch(
            "SELECT wm.role FROM workspace_members wm WHERE wm.user_id = ? AND wm.workspace_id = ?",
            [$userId, $workspaceId]
        );

        $rows = DB::fetchAll(
            "SELECT module_key FROM user_module_access WHERE workspace_id = ? AND user_id = ? AND can_access = 1",
            [$workspaceId, $userId]
        );
        $stored = array_map(fn($row) => $row['module_key'], $rows);

        // CRM: workspace admins implicitly hold every module (unchanged behaviour).
        $crm = ($user && $user['role'] === 'admin')
            ? array_keys(self::CRM_MODULES)
            : array_values(array_filter($stored, fn($key) => isset(self::CRM_MODULES[$key])));

        // Accounting: explicit grants only, plus the bootstrap owner.
        $acc = self::isAccOwner($userId)
            ? array_keys(self::ACC_MODULES)
            : array_values(array_filter($stored, fn($key) => isset(self::ACC_MODULES[$key])));

        // Back-compat: 'acc.contacts' was one module covering customers AND
        // vendors before they were split. Anyone still holding the old grant
        // keeps access to both halves.
        if (!self::isAccOwner($userId) && in_array('acc.contacts', $stored, true)) {
            foreach (['acc.customers', 'acc.vendors'] as $k) {
                if (!in_array($k, $acc, true)) $acc[] = $k;
            }
        }

        return array_values(array_merge($crm, $acc));
    }

    public static function hasModuleAccess($moduleKey) {
        $all = self::allModules();
        if (!isset($all[$moduleKey])) return false;
        return in_array($moduleKey, self::grantedModules(), true);
    }

    public static function hasAnyCrmAccess() {
        return count(array_filter(self::grantedModules(), fn($k) => isset(self::CRM_MODULES[$k]))) > 0;
    }

    public static function hasAnyAccAccess() {
        return count(array_filter(self::grantedModules(), fn($k) => isset(self::ACC_MODULES[$k]))) > 0;
    }

    public static function requireModuleAccess($moduleKey) {
        if (!self::hasModuleAccess($moduleKey)) jsonError('You do not have access to this CRM module', 403);
    }

    /** Guard for every /api/acc/* endpoint. */
    public static function requireAccModule($moduleKey) {
        if (!self::hasModuleAccess($moduleKey)) {
            jsonError('You do not have access to the Accounting & Finance app', 403);
        }
    }

    /**
     * Destructive / configuration operations: workspace admin AND an explicit
     * acc.settings grant (the owner satisfies the second half automatically).
     */
    public static function requireAccAdmin() {
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') jsonError('Administrator access required', 403);
        if (!self::hasModuleAccess('acc.settings')) {
            jsonError('You do not have access to the Accounting & Finance app', 403);
        }
    }
    
    public static function requireTaskAccess($taskId) {
        $task = DB::fetch("SELECT t.*, p.workspace_id, p.parent_id FROM tasks t JOIN projects p ON t.project_id = p.id WHERE t.id = ?", [$taskId]);
        if (!$task) jsonError('Task not found', 404);
        if ($task['workspace_id'] != Auth::workspaceId()) jsonError('Access denied', 403);
        
        $user = Auth::user();
        if ($user && $user['role'] === 'admin') return $task;
        
        // Member of the task's project OR of its parent category (project tree access).
        if (!self::isProjectTreeMember($task['project_id'], $task['parent_id'])) jsonError('Access denied', 403);
        return $task;
    }
    
    public static function requireProjectAccess($projectId) {
        $project = DB::fetch("SELECT * FROM projects WHERE id = ? AND workspace_id = ?", [$projectId, Auth::workspaceId()]);
        if (!$project) jsonError('Project not found', 404);
        
        $user = Auth::user();
        if ($user && $user['role'] === 'admin') return $project;
        
        // Member of the project OR of its parent category (project tree access).
        if (!self::isProjectTreeMember($projectId, $project['parent_id'])) jsonError('Access denied', 403);
        return $project;
    }

    // Shared helper: is the current user a member of $projectId, or (if it's a
    // sub-project) of its parent category $parentId? parent_id may be null.
    private static function isProjectTreeMember($projectId, $parentId) {
        if ($parentId === null) {
            $row = DB::fetch("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1", [$projectId, Auth::userId()]);
        } else {
            $row = DB::fetch(
                "SELECT 1 FROM project_members WHERE user_id = ? AND (project_id = ? OR project_id = ?) LIMIT 1",
                [Auth::userId(), $projectId, (int)$parentId]
            );
        }
        return (bool)$row;
    }
    
    public static function requireChannelAccess($channelId) {
        $channel = DB::fetch("SELECT * FROM channels WHERE id = ? AND workspace_id = ?", [$channelId, Auth::workspaceId()]);
        if (!$channel) jsonError('Channel not found', 404);
        
        $user = Auth::user();
        if ($user && $user['role'] === 'admin') return $channel;
        
        $member = DB::fetch("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, Auth::userId()]);
        if (!$member) jsonError('Access denied', 403);
        return $channel;
    }
    
    // Access to a "category" (parent project). A non-admin has access if they are a
    // member of the category itself OR of any of its sub-projects.
    public static function requireCategoryAccess($categoryId) {
        $project = DB::fetch("SELECT * FROM projects WHERE id = ? AND workspace_id = ? AND parent_id IS NULL", [$categoryId, Auth::workspaceId()]);
        if (!$project) jsonError('Category not found', 404);

        $user = Auth::user();
        if ($user && $user['role'] === 'admin') return $project;

        // Member of the category itself OR of any of its sub-projects.
        $member = DB::fetch(
            "SELECT 1 FROM project_members
             WHERE user_id = ?
               AND (project_id = ? OR project_id IN (SELECT id FROM projects WHERE parent_id = ?))
             LIMIT 1",
            [Auth::userId(), $categoryId, $categoryId]
        );
        if (!$member) jsonError('Access denied', 403);
        return $project;
    }

    // Access to any project row (category or sub-project). Non-admins must be a member
    // of the project itself, or of a related project in the same category tree:
    //  - opening a category: member of it OR any of its sub-projects
    //  - opening a sub-project: member of it OR of its parent category
    public static function requireProjectOrCategoryAccess($projectId) {
        $project = DB::fetch("SELECT * FROM projects WHERE id = ? AND workspace_id = ?", [$projectId, Auth::workspaceId()]);
        if (!$project) jsonError('Project not found', 404);

        $user = Auth::user();
        if ($user && $user['role'] === 'admin') return $project;

        if ($project['parent_id'] === null) {
            // It's a category — member of it or of any of its sub-projects.
            $member = DB::fetch(
                "SELECT 1 FROM project_members
                 WHERE user_id = ?
                   AND (project_id = ? OR project_id IN (SELECT id FROM projects WHERE parent_id = ?))
                 LIMIT 1",
                [Auth::userId(), $projectId, $projectId]
            );
        } else {
            // It's a sub-project — member of it OR of its parent category.
            $member = DB::fetch(
                "SELECT 1 FROM project_members WHERE user_id = ? AND (project_id = ? OR project_id = ?) LIMIT 1",
                [Auth::userId(), $projectId, (int)$project['parent_id']]
            );
        }
        if (!$member) jsonError('Access denied', 403);
        return $project;
    }

    public static function requireFileAccess($fileId) {
        $file = DB::fetch("SELECT f.*, p.workspace_id, p.parent_id FROM files f JOIN projects p ON f.project_id = p.id WHERE f.id = ?", [$fileId]);
        if (!$file) jsonError('File not found', 404);
        if ($file['workspace_id'] != Auth::workspaceId()) jsonError('Access denied', 403);

        $user = Auth::user();
        if ($user && $user['role'] === 'admin') return $file;

        // Non-admins must belong to the project the file lives in (or its category).
        self::requireProjectOrCategoryAccess($file['project_id']);
        return $file;
    }
}
