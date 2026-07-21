<?php
require_once __DIR__ . '/../lib/Authz.php';

class ProjectController {
    
    public static function index() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        $user = Auth::user();
        $isAdmin = $user && $user['role'] === 'admin';
        
        if ($isAdmin) {
            // Admins see all categories
            $categories = DB::fetchAll(
                "SELECT p.* FROM projects p 
                 WHERE p.workspace_id = ? AND p.parent_id IS NULL 
                 ORDER BY p.updated_at DESC",
                [$wsId]
            );
        } else {
            // Members see only categories they belong to
            $categories = DB::fetchAll(
                "SELECT p.* FROM projects p 
                 INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 WHERE p.workspace_id = ? AND p.parent_id IS NULL 
                 ORDER BY p.updated_at DESC",
                [$userId, $wsId]
            );
        }
        
        $result = array_map(function($p) {
            $progress = calculateProgress($p['id']);
            $health = calculateHealth($p['id'], $p['due_date']);
            
            if ($progress != $p['progress'] || $health != $p['health']) {
                DB::update('projects', ['progress' => $progress, 'health' => $health], 'id = ?', [$p['id']]);
            }
            
            // Count sub-projects
            $subCount = DB::fetch(
                "SELECT COUNT(*) as c FROM projects WHERE parent_id = ?",
                [$p['id']]
            );
            
            // Count tasks in sub-projects only (categories are containers, not task holders)
            $totalTasks = DB::fetch(
                "SELECT COUNT(*) as c FROM tasks t 
                 WHERE t.project_id IN (SELECT id FROM projects WHERE parent_id = ?)",
                [$p['id']]
            );
            
            // Count files in sub-projects only
            $totalFiles = DB::fetch(
                "SELECT COUNT(*) as c FROM files f 
                 WHERE f.project_id IN (SELECT id FROM projects WHERE parent_id = ?)",
                [$p['id']]
            );
            
            $members = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM project_members pm 
                 JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ?",
                [$p['id']]
            );
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'color' => $p['color'],
                'sub_projects' => [],
                'sub_project_count' => (int)($subCount['c'] ?? 0),
                'total_tasks' => (int)($totalTasks['c'] ?? 0),
                'total_files' => (int)($totalFiles['c'] ?? 0),
                'members' => array_map(fn($m) => [
                    'id' => (int)$m['id'],
                    'name' => $m['name'],
                    'initials' => initials($m['name']),
                    'avatar_color' => $m['avatar_color'],
                ], $members),
            ];
        }, $categories);
        
        jsonResponse(['projects' => $result]);
    }
    
    public static function category($id) {
        // Authorization: non-admins must belong to the category or one of its sub-projects
        $project = Authz::requireCategoryAccess($id);
        
        // Get sub-projects
        $subs = DB::fetchAll(
            "SELECT * FROM projects WHERE parent_id = ? ORDER BY created_at ASC",
            [$id]
        );
        
        $projects = array_map(function($p) {
            $progress = calculateProgress($p['id']);
            $health = calculateHealth($p['id'], $p['due_date']);
            
            if ($progress != $p['progress'] || $health != $p['health']) {
                DB::update('projects', ['progress' => $progress, 'health' => $health], 'id = ?', [$p['id']]);
            }
            
            $taskStats = DB::fetch(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress
                 FROM tasks WHERE project_id = ?",
                [$p['id']]
            );
            
            $openTasks = $taskStats['in_progress'] ?? 0;
            $completedTasks = $taskStats['completed'] ?? 0;
            $totalTasks = $taskStats['total'] ?? 0;
            
            $totalFiles = DB::fetch("SELECT COUNT(*) as c FROM files WHERE project_id = ?", [$p['id']]);
            
            $members = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM project_members pm 
                 JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ?",
                [$p['id']]
            );
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'color' => $p['color'],
                'health' => $health,
                'health_label' => healthLabel($health),
                'health_color' => healthColor($health),
                'healthBg' => healthBg($health),
                'progress' => $progress,
                'completed_tasks' => (int)$completedTasks,
                'total_tasks' => (int)$totalTasks,
                'total_files' => (int)($totalFiles['c'] ?? 0),
                'due_date' => $p['due_date'],
                'due_label' => formatDate($p['due_date']),
                'open_tasks' => $openTasks,
                'members' => array_map(fn($m) => [
                    'id' => (int)$m['id'],
                    'name' => $m['name'],
                    'initials' => initials($m['name']),
                    'avatar_color' => $m['avatar_color'],
                ], $members),
            ];
        }, $subs);
        
        // Fetch category-level chat
        $chat = DB::fetchAll(
            "SELECT pc.*, u.name, u.avatar_color FROM project_chat pc JOIN users u ON pc.user_id = u.id WHERE pc.project_id = ? ORDER BY pc.created_at",
            [$id]
        );
        $chatFormatted = array_map(fn($m) => [
            'id' => (int)$m['id'],
            'who' => $m['name'],
            'initials' => initials($m['name']),
            'bg' => $m['avatar_color'],
            'text' => $m['body'],
            'time' => timeAgo($m['created_at']),
            'me' => $m['user_id'] == Auth::userId(),
        ], $chat);
        
        jsonResponse([
            'category' => [
                'id' => (int)$project['id'],
                'name' => $project['name'],
                'description' => $project['description'],
                'projects' => $projects,
            ]
        ]);
    }
    
    public static function show($id) {
        // Authorization: non-admins must be a member of this project (or its category)
        $project = Authz::requireProjectOrCategoryAccess($id);
        
        // Auto-calculate
        $progress = calculateProgress($id);
        $health = calculateHealth($id, $project['due_date']);
        if ($progress != $project['progress'] || $health != $project['health']) {
            DB::update('projects', ['progress' => $progress, 'health' => $health], 'id = ?', [$id]);
        }
        
        $members = DB::fetchAll(
            "SELECT u.id, u.name, u.avatar_color, pm.role FROM project_members pm 
             JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ?",
            [$id]
        );
        
        // Task groups: In Progress, Completed
        $groups = [];
        $statusConfig = [
            'in_progress' => ['label' => 'In Progress', 'color' => '#C99520'],
            'completed' => ['label' => 'Completed', 'color' => '#6B8E5A'],
        ];
        
        foreach ($statusConfig as $status => $cfg) {
            $tasks = DB::fetchAll(
                "SELECT t.*, u.name as assignee_name, u.avatar_color as assignee_color 
                 FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id 
                 WHERE t.project_id = ? AND t.status = ? ORDER BY t.sort_order, t.created_at",
                [$id, $status]
            );
            if (count($tasks) > 0) {
                $groups[] = [
                    'label' => $cfg['label'],
                    'color' => $cfg['color'],
                    'count' => count($tasks),
                    'tasks' => array_map(fn($t) => self::formatTask($t), $tasks),
                ];
            }
        }
        
        // Task stats for completed_tasks / total_tasks
        $taskStats = DB::fetch(
            "SELECT \n                COUNT(*) as total,\n                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,\n                SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress\n             FROM tasks WHERE project_id = ?",
            [$id]
        );
        $totalTasks = $taskStats['total'] ?? 0;
        $completedTasks = $taskStats['completed'] ?? 0;
        
        $files = DB::fetchAll(
            "SELECT f.*, u.name as uploader FROM files f JOIN users u ON f.uploaded_by = u.id WHERE f.project_id = ? ORDER BY f.created_at DESC",
            [$id]
        );
        $filesFormatted = array_map(fn($f) => self::formatFile($f), $files);

        // Folders for this project (B4b)
        $folders = self::formatFolders($id);
        
        $chat = DB::fetchAll(
            "SELECT pc.*, u.name, u.avatar_color FROM project_chat pc JOIN users u ON pc.user_id = u.id WHERE pc.project_id = ? ORDER BY pc.created_at",
            [$id]
        );
        $chatFormatted = array_map(fn($m) => [
            'id' => (int)$m['id'],
            'who' => $m['name'],
            'initials' => initials($m['name']),
            'bg' => $m['avatar_color'],
            'text' => $m['body'],
            'time' => (new DateTime($m['created_at']))->format('g:i A'),
            'me' => $m['user_id'] == Auth::userId(),
        ], $chat);
        
        // Get parent name for the back-link (immediate parent).
        $categoryName = null;
        if ($project['parent_id']) {
            $parent = DB::fetch("SELECT name FROM projects WHERE id = ?", [$project['parent_id']]);
            $categoryName = $parent['name'] ?? null;
        }

        // C3 — full ancestor breadcrumb chain (category → project → sub-project → …).
        // Walk up parent_id links, collecting each ancestor, then reverse so the
        // outermost category comes first. Guarded against cycles by a depth cap.
        $breadcrumb = [];
        $cursor = $project['parent_id'] ? (int)$project['parent_id'] : null;
        $depth = 0;
        while ($cursor && $depth < 12) {
            $anc = DB::fetch("SELECT id, name, parent_id FROM projects WHERE id = ?", [$cursor]);
            if (!$anc) break;
            $breadcrumb[] = [
                'id' => (int)$anc['id'],
                'name' => $anc['name'],
                'is_category' => empty($anc['parent_id']),
            ];
            $cursor = $anc['parent_id'] ? (int)$anc['parent_id'] : null;
            $depth++;
        }
        $breadcrumb = array_reverse($breadcrumb);

        // C3 — child sub-projects (projects whose parent is this project). Rendered
        // as cards on the project page so complex projects can nest sub-projects.
        $childRows = DB::fetchAll(
            "SELECT * FROM projects WHERE parent_id = ? ORDER BY created_at ASC",
            [$id]
        );
        $subprojects = array_map(function($p) {
            $prog = calculateProgress($p['id']);
            $hlth = calculateHealth($p['id'], $p['due_date']);
            $ts = DB::fetch(
                "SELECT COUNT(*) as total,
                        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress
                 FROM tasks WHERE project_id = ?",
                [$p['id']]
            );
            $tf = DB::fetch("SELECT COUNT(*) as c FROM files WHERE project_id = ?", [$p['id']]);
            $mem = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM project_members pm
                 JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ?",
                [$p['id']]
            );
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'health' => $hlth,
                'health_label' => healthLabel($hlth),
                'health_color' => healthColor($hlth),
                'healthBg' => healthBg($hlth),
                'progress' => $prog,
                'completed_tasks' => (int)($ts['completed'] ?? 0),
                'total_tasks' => (int)($ts['total'] ?? 0),
                'open_tasks' => (int)($ts['in_progress'] ?? 0),
                'total_files' => (int)($tf['c'] ?? 0),
                'due_date' => $p['due_date'],
                'due_label' => formatDate($p['due_date']),
                'members' => array_map(fn($m) => [
                    'id' => (int)$m['id'],
                    'name' => $m['name'],
                    'initials' => initials($m['name']),
                    'avatar_color' => $m['avatar_color'],
                ], $mem),
            ];
        }, $childRows);

        jsonResponse([
            'project' => [
                'id' => (int)$project['id'],
                'name' => $project['name'],
                'description' => $project['description'],
                'color' => $project['color'],
                'parent_id' => $project['parent_id'] ? (int)$project['parent_id'] : null,
                'category_name' => $categoryName,
                'breadcrumb' => $breadcrumb,
                'subprojects' => $subprojects,
                'health' => $health,
                'health_label' => healthLabel($health),
                'health_color' => healthColor($health),
                'healthBg' => healthBg($health),
                'progress' => $progress,
                'completed_tasks' => (int)$completedTasks,
                'total_tasks' => (int)$totalTasks,
                'due_date' => $project['due_date'],
                'due_label' => formatDate($project['due_date']),
                'members' => array_map(fn($m) => [
                    'id' => (int)$m['id'],
                    'name' => $m['name'],
                    'initials' => initials($m['name']),
                    'bg' => $m['avatar_color'],
                    'role' => $m['role'],
                ], $members),
                'groups' => $groups,
                'files' => $filesFormatted,
                'folders' => $folders,
                'chat' => $chatFormatted,
            ]
        ]);
    }

    // Shared file formatter (includes folder + link support, B4).
    private static function formatFile($f) {
        return [
            'id' => (int)$f['id'],
            'name' => $f['original_name'],
            'size' => (isset($f['file_size']) && $f['file_size']) ? ($f['file_size'] < 1048576 ? round($f['file_size']/1024) . ' KB' : round($f['file_size']/1048576, 1) . ' MB') : '',
            'ext' => strtoupper($f['file_ext'] ?? ''),
            'by' => $f['uploader'] ?? '',
            'when' => timeAgo($f['created_at']),
            'folder_id' => isset($f['folder_id']) && $f['folder_id'] !== null ? (int)$f['folder_id'] : null,
            'storage' => $f['storage'] ?? 'local',
            'external_url' => $f['external_url'] ?? null,
        ];
    }

    // Build a flat list of folders for a project (B4b).
    private static function formatFolders($projectId) {
        $rows = DB::fetchAll(
            "SELECT ff.*, (SELECT COUNT(*) FROM files f WHERE f.folder_id = ff.id) as file_count
             FROM file_folders ff WHERE ff.project_id = ? ORDER BY ff.name ASC",
            [$projectId]
        );
        return array_map(fn($f) => [
            'id' => (int)$f['id'],
            'name' => $f['name'],
            'parent_folder_id' => $f['parent_folder_id'] !== null ? (int)$f['parent_folder_id'] : null,
            'file_count' => (int)($f['file_count'] ?? 0),
        ], $rows);
    }

    // ===== FOLDERS (B4b) =====
    public static function createFolder($projectId) {
        Authz::requireProjectAccess($projectId);
        $data = input();
        requireFields(['name'], $data);
        $name = trim($data['name']);
        if ($name === '') jsonError('Folder name required');
        $parentId = isset($data['parent_folder_id']) && $data['parent_folder_id'] ? (int)$data['parent_folder_id'] : null;
        // Validate parent belongs to same project
        if ($parentId) {
            $parent = DB::fetch("SELECT id FROM file_folders WHERE id = ? AND project_id = ?", [$parentId, $projectId]);
            if (!$parent) jsonError('Parent folder not found', 404);
        }
        $id = DB::insert('file_folders', [
            'project_id' => $projectId,
            'parent_folder_id' => $parentId,
            'name' => $name,
            'created_by' => Auth::userId(),
        ]);
        jsonResponse(['ok' => true, 'folder' => [
            'id' => (int)$id,
            'name' => $name,
            'parent_folder_id' => $parentId,
            'file_count' => 0,
        ]]);
    }

    public static function deleteFolder($projectId, $folderId) {
        Authz::requireProjectAccess($projectId);
        $folder = DB::fetch("SELECT * FROM file_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
        if (!$folder) jsonError('Folder not found', 404);
        $user = Auth::user();
        if ($folder['created_by'] != Auth::userId() && (!$user || $user['role'] !== 'admin')) {
            jsonError('You can only delete folders you created', 403);
        }
        // Move files back to project root (don't delete files)
        DB::query("UPDATE files SET folder_id = NULL WHERE folder_id = ?", [$folderId]);
        // Re-parent sub-folders to root
        DB::query("UPDATE file_folders SET parent_folder_id = NULL WHERE parent_folder_id = ?", [$folderId]);
        DB::delete('file_folders', 'id = ?', [$folderId]);
        jsonResponse(['ok' => true]);
    }

    // ===== ADD FILE VIA LINK (B4d) =====
    public static function addFileLink($projectId) {
        Authz::requireProjectAccess($projectId);
        $data = input();
        requireFields(['url', 'name'], $data);
        $url = trim($data['url']);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            jsonError('Please enter a valid http(s) link');
        }
        $name = trim($data['name']);
        $folderId = isset($data['folder_id']) && $data['folder_id'] ? (int)$data['folder_id'] : null;
        if ($folderId) {
            $folder = DB::fetch("SELECT id FROM file_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
            if (!$folder) jsonError('Folder not found', 404);
        }
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (!$ext || strlen($ext) > 8) $ext = 'link';
        $id = DB::insert('files', [
            'project_id' => $projectId,
            'folder_id' => $folderId,
            'uploaded_by' => Auth::userId(),
            'filename' => $name,
            'storage' => 'link',
            'external_url' => $url,
            'original_name' => $name,
            'file_size' => 0,
            'file_type' => 'link',
            'file_ext' => $ext,
        ]);
        $f = DB::fetch("SELECT f.*, u.name as uploader FROM files f JOIN users u ON f.uploaded_by = u.id WHERE f.id = ?", [$id]);
        jsonResponse(['ok' => true, 'file' => self::formatFile($f)]);
    }

    public static function create() {
        $data = input();
        requireFields(['name'], $data);
        
        $user = Auth::user();
        $parentId = $data['parent_id'] ?? null;
        $isCategory = $parentId === null;
        
        // Category creation: admin only
        if ($isCategory) {
            Auth::requireAdmin();
        } else {
            // Project / sub-project creation. The parent may be a category
            // (parent_id IS NULL) OR another project (C3 — nested sub-projects).
            // requireProjectOrCategoryAccess verifies the parent exists in this
            // workspace AND that the caller (if not admin) belongs to its tree; it
            // sends a 403/404 and exits otherwise.
            Authz::requireProjectOrCategoryAccess((int)$parentId);
        }
        
        $id = DB::insert('projects', [
            'workspace_id' => Auth::workspaceId(),
            'parent_id' => $parentId,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'color' => $data['color'] ?? '#7e6549',
            'health' => 'on_track',
            'progress' => 0,
            'due_date' => $data['due_date'] ?? null,
            'created_by' => Auth::userId(),
        ]);
        
        // Always add creator as Lead
        DB::insert('project_members', [
            'project_id' => $id,
            'user_id' => Auth::userId(),
            'role' => 'Lead',
        ]);
        
        // Add selected members (for both categories and sub-projects)
        if (!empty($data['member_ids']) && is_array($data['member_ids'])) {
            foreach ($data['member_ids'] as $mid) {
                $mid = (int)$mid;
                if ($mid !== Auth::userId()) {
                    // Verify they're in this workspace
                    $wm = DB::fetch("SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?", [Auth::workspaceId(), $mid]);
                    if ($wm) {
                        DB::query("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'Member')", [$id, $mid]);
                    }
                }
            }
        }
        
        // Return the freshly-created row so the client can render its description
        // immediately without a stale cache (B2).
        $row = DB::fetch("SELECT * FROM projects WHERE id = ?", [$id]);
        jsonResponse(['ok' => true, 'id' => (int)$id, 'project' => [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'color' => $row['color'],
            'parent_id' => $row['parent_id'] ? (int)$row['parent_id'] : null,
        ]]);
    }
    
    // Category picker: only categories the user belongs to; admins see all
    public static function myCategories() {
        $user = Auth::user();
        if ($user && $user['role'] === 'admin') {
            $cats = DB::fetchAll("SELECT id, name FROM projects WHERE workspace_id = ? AND parent_id IS NULL ORDER BY name", [Auth::workspaceId()]);
        } else {
            $cats = DB::fetchAll(
                "SELECT p.id, p.name FROM projects p JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.workspace_id = ? AND p.parent_id IS NULL ORDER BY p.name",
                [Auth::userId(), Auth::workspaceId()]);
        }
        jsonResponse(['categories' => $cats]);
    }
    
    public static function update($id) {
        // Authorization: non-admins must be a member of this project (or its category)
        $project = Authz::requireProjectOrCategoryAccess($id);
        
        $data = input();
        $allowed = ['name', 'description', 'color', 'health', 'due_date'];
        $update = array_intersect_key($data, array_flip($allowed));
        if ($update) {
            DB::update('projects', $update, 'id = ?', [$id]);
        }
        jsonResponse(['ok' => true]);
    }
    
    public static function delete($id) {
        $project = DB::fetch("SELECT * FROM projects WHERE id = ? AND workspace_id = ?", [$id, Auth::workspaceId()]);
        if (!$project) jsonError('Category not found', 404);
        
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') jsonError('Only admins can delete categories', 403);
        
        // C3 — collect the FULL descendant tree (sub-projects, sub-sub-projects, …)
        // via a breadth-first walk so nested deletes don't orphan grandchildren.
        $allIds = [(int)$id];
        $frontier = [(int)$id];
        $guard = 0;
        while (!empty($frontier) && $guard < 50) {
            $ph = implode(',', array_fill(0, count($frontier), '?'));
            $children = DB::fetchAll("SELECT id FROM projects WHERE parent_id IN ($ph)", $frontier);
            $frontier = [];
            foreach ($children as $c) {
                $cid = (int)$c['id'];
                if (!in_array($cid, $allIds, true)) { $allIds[] = $cid; $frontier[] = $cid; }
            }
            $guard++;
        }
        $idList = implode(',', array_map('intval', $allIds));
        
        // Delete tasks in all projects
        DB::query("DELETE FROM tasks WHERE project_id IN ($idList)");
        // Delete task assignees
        DB::query("DELETE FROM task_assignees WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN ($idList))");
        // Delete project chat messages
        DB::query("DELETE FROM project_chat WHERE project_id IN ($idList)");
        // Delete files (and their DB records)
        $files = DB::fetchAll("SELECT id, filename, project_id FROM files WHERE project_id IN ($idList)");
        foreach ($files as $f) {
            $path = UPLOAD_PATH . '/project_' . $f['project_id'] . '/' . $f['filename'];
            if (file_exists($path)) @unlink($path);
        }
        DB::query("DELETE FROM files WHERE project_id IN ($idList)");
        // Delete project members
        DB::query("DELETE FROM project_members WHERE project_id IN ($idList)");
        // Delete the projects themselves
        DB::query("DELETE FROM projects WHERE id IN ($idList)");
        
        jsonResponse(['ok' => true]);
    }
    
    public static function search() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) { jsonResponse(['results' => []]); return; }
        $like = '%' . $q . '%';
        
        $user = Auth::user();
        $isAdmin = $user && $user['role'] === 'admin';
        
        if ($isAdmin) {
            $projects = DB::fetchAll(
                "SELECT p.id, p.name, p.description, p.parent_id, p.color
                 FROM projects p
                 WHERE p.workspace_id = ? AND (p.name LIKE ? OR p.description LIKE ?)
                 ORDER BY p.parent_id IS NULL DESC, p.name ASC
                 LIMIT 5",
                [$wsId, $like, $like]
            );
            $tasks = DB::fetchAll(
                "SELECT t.id, t.title, t.status, t.priority, p.name as project_name, p.id as project_id, p.color as project_color
                 FROM tasks t
                 JOIN projects p ON t.project_id = p.id
                 WHERE p.workspace_id = ? AND t.title LIKE ?
                 ORDER BY t.priority DESC, t.created_at DESC
                 LIMIT 8",
                [$wsId, $like]
            );
            $files = DB::fetchAll(
                "SELECT f.id, f.original_name, f.file_ext, f.file_size, p.name as project_name
                 FROM files f
                 JOIN projects p ON f.project_id = p.id
                 WHERE p.workspace_id = ? AND f.original_name LIKE ?
                 ORDER BY f.created_at DESC
                 LIMIT 5",
                [$wsId, $like]
            );
        } else {
            $projects = DB::fetchAll(
                "SELECT p.id, p.name, p.description, p.parent_id, p.color
                 FROM projects p
                 INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 WHERE p.workspace_id = ? AND (p.name LIKE ? OR p.description LIKE ?)
                 ORDER BY p.parent_id IS NULL DESC, p.name ASC
                 LIMIT 5",
                [$userId, $wsId, $like, $like]
            );
            $tasks = DB::fetchAll(
                "SELECT t.id, t.title, t.status, t.priority, p.name as project_name, p.id as project_id, p.color as project_color
                 FROM tasks t
                 JOIN projects p ON t.project_id = p.id
                 INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 WHERE p.workspace_id = ? AND t.title LIKE ?
                 ORDER BY t.priority DESC, t.created_at DESC
                 LIMIT 8",
                [$userId, $wsId, $like]
            );
            $files = DB::fetchAll(
                "SELECT f.id, f.original_name, f.file_ext, f.file_size, p.name as project_name
                 FROM files f
                 JOIN projects p ON f.project_id = p.id
                 INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 WHERE p.workspace_id = ? AND f.original_name LIKE ?
                 ORDER BY f.created_at DESC
                 LIMIT 5",
                [$userId, $wsId, $like]
            );
        }
        
        $result = [];
        if (count($projects) > 0) {
            $result[] = ['group' => 'Projects', 'items' => array_map(fn($p) => [
                'type' => 'project',
                'id' => (int)$p['id'],
                'title' => $p['name'],
                'meta' => $p['parent_id'] ? 'Project' : 'Category',
                'color' => $p['color'],
            ], $projects)];
        }
        if (count($tasks) > 0) {
            $result[] = ['group' => 'Tasks', 'items' => array_map(fn($t) => [
                'type' => 'task',
                'id' => (int)$t['id'],
                'title' => $t['title'],
                'meta' => $t['project_name'],
                'project_id' => (int)$t['project_id'],
                'priority' => $t['priority'],
            ], $tasks)];
        }
        if (count($files) > 0) {
            $result[] = ['group' => 'Files', 'items' => array_map(fn($f) => [
                'type' => 'file',
                'id' => (int)$f['id'],
                'title' => $f['original_name'],
                'meta' => $f['project_name'] . ' · ' . strtoupper($f['file_ext'] ?? ''),
            ], $files)];
        }
        
        jsonResponse(['results' => $result]);
    }
    
    public static function allFiles() {
        $wsId = Auth::workspaceId();
        
        // If ?category=X is provided, filter to that category and its sub-projects
        $categoryId = isset($_GET['category']) ? (int)$_GET['category'] : null;
        
        if ($categoryId) {
            // Verify access to this category (member of the category or any of its sub-projects)
            Authz::requireCategoryAccess($categoryId);
            
            // Files in this category's sub-projects (category is parent, files are in child projects)
            $files = DB::fetchAll(
                "SELECT f.*, u.name as uploader, p.name as project_name
                 FROM files f 
                 JOIN users u ON f.uploaded_by = u.id 
                 JOIN projects p ON f.project_id = p.id 
                 WHERE p.workspace_id = ? AND (f.project_id IN (SELECT id FROM projects WHERE parent_id = ?) OR f.project_id = ?)
                 ORDER BY f.created_at DESC",
                [$wsId, $categoryId, $categoryId]
            );
        } else {
            // No category filter: only return files from projects the user has access to
            $user = Auth::user();
            if ($user && $user['role'] === 'admin') {
                $files = DB::fetchAll(
                    "SELECT f.*, u.name as uploader, p.name as project_name
                     FROM files f 
                     JOIN users u ON f.uploaded_by = u.id 
                     JOIN projects p ON f.project_id = p.id 
                     WHERE p.workspace_id = ?
                     ORDER BY f.created_at DESC",
                    [$wsId]
                );
            } else {
                // Non-admin: only files from projects they're a member of
                $files = DB::fetchAll(
                    "SELECT f.*, u.name as uploader, p.name as project_name
                     FROM files f 
                     JOIN users u ON f.uploaded_by = u.id 
                     JOIN projects p ON f.project_id = p.id 
                     WHERE p.workspace_id = ? 
                     AND f.project_id IN (SELECT project_id FROM project_members WHERE user_id = ?)
                     ORDER BY f.created_at DESC",
                    [$wsId, Auth::userId()]
                );
            }
        }
        
        $result = array_map(function($f) {
            $out = self::formatFile($f);
            $out['stored'] = $f['filename'];
            $out['project_name'] = $f['project_name'];
            return $out;
        }, $files);
        jsonResponse(['files' => $result]);
    }
    
    public static function addMember($id) {
        $project = Authz::requireProjectAccess($id);
        $data = input();
        requireFields(['user_id'], $data);
        $userId = (int)$data['user_id'];
        
        // Verify user is in this workspace
        $wm = DB::fetch("SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?", [Auth::workspaceId(), $userId]);
        if (!$wm) jsonError('User not in this workspace', 403);
        
        // Use INSERT IGNORE to avoid duplicates
        DB::query("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)", [$id, $userId, $data['role'] ?? 'Member']);
        jsonResponse(['ok' => true]);
    }
    
    public static function removeMember($id) {
        $project = Authz::requireProjectAccess($id);
        $data = input();
        requireFields(['user_id'], $data);
        $userId = (int)$data['user_id'];
        
        // Don't allow removing the last Lead
        $isLead = DB::fetch("SELECT role FROM project_members WHERE project_id = ? AND user_id = ?", [$id, $userId]);
        if ($isLead && $isLead['role'] === 'Lead') {
            $leadCount = DB::fetch("SELECT COUNT(*) as cnt FROM project_members WHERE project_id = ? AND role = 'Lead'", [$id]);
            if ($leadCount['cnt'] <= 1) jsonError('Cannot remove the last Lead from this project');
        }
        
        DB::delete('project_members', 'project_id = ? AND user_id = ?', [$id, $userId]);
        jsonResponse(['ok' => true]);
    }
    
    // List members of a project/category with full details
    public static function listMembers($id) {
        $project = Authz::requireProjectAccess($id);
        $members = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.avatar_color, pm.role FROM project_members pm 
             JOIN users u ON pm.user_id = u.id WHERE pm.project_id = ? ORDER BY pm.role DESC, u.name ASC",
            [$id]
        );
        
        // Also get workspace members NOT in this project (for the add picker)
        $inProject = array_column($members, 'id');
        $available = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.avatar_color FROM workspace_members wm 
             JOIN users u ON wm.user_id = u.id 
             WHERE wm.workspace_id = ? AND u.is_active = 1" .
             ($inProject ? " AND u.id NOT IN (" . implode(',', array_fill(0, count($inProject), '?')) . ")" : "") .
             " ORDER BY u.name ASC",
            $inProject ? array_merge([Auth::workspaceId()], $inProject) : [Auth::workspaceId()]
        );
        
        jsonResponse([
            'members' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'name' => $m['name'],
                'email' => $m['email'],
                'initials' => initials($m['name']),
                'avatar_color' => $m['avatar_color'],
                'role' => $m['role'],
            ], $members),
            'available' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'name' => $m['name'],
                'email' => $m['email'],
                'initials' => initials($m['name']),
                'avatar_color' => $m['avatar_color'],
            ], $available),
        ]);
    }
    
    private static function formatTask($t) {
        $completed = $t['status'] === 'completed';
        $comments = DB::fetchAll(
            "SELECT tc.*, u.name, u.avatar_color FROM task_comments tc JOIN users u ON tc.user_id = u.id WHERE tc.task_id = ? ORDER BY tc.created_at",
            [$t['id']]
        );
        
        // Get all assignees (multi-user)
        $assignees = DB::fetchAll(
            "SELECT u.id, u.name, u.avatar_color FROM task_assignees ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = ?",
            [$t['id']]
        );
        $assigneeList = array_map(fn($a) => [
            'id' => (int)$a['id'],
            'name' => $a['name'],
            'initials' => initials($a['name']),
            'avatar_color' => $a['avatar_color'],
        ], $assignees);
        
        return [
            'id' => (int)$t['id'],
            'title' => $t['title'],
            'description' => $t['description'],
            'project_id' => (int)$t['project_id'],
            'project_name' => $t['project_name'] ?? null,
            'status' => $t['status'],
            'status_label' => statusLabel($t['status']),
            'status_color' => statusColor($t['status']),
            'status_bg' => statusBg($t['status']),
            'who' => $t['assignee_name'] ? initials($t['assignee_name']) : '—',
            'avBg' => $t['assignee_color'] ?? '#9A8A78',
            'assignee_name' => $t['assignee_name'],
            'assignee_id' => (int)$t['assigned_to'],
            'assignees' => $assigneeList,
            'done' => $completed,
            'ai_flagged' => (bool)$t['ai_flagged'],
            'due_date' => $t['due_date'],
            'deadline_date' => $t['deadline_date'],
            'deadline_label' => formatDate($t['deadline_date'] ?? $t['due_date']),
            'comment_count' => count($comments),
            'comments' => array_map(fn($c) => [
                'id' => (int)$c['id'],
                'who' => $c['name'],
                'initials' => initials($c['name']),
                'bg' => $c['avatar_color'],
                'text' => $c['body'],
                'time' => (new DateTime($c['created_at']))->format('M j, g:i A'),
            ], $comments),
        ];
    }

    // ===== CHAT UNREAD COUNTS =====
    
    public static function ensureChatReadsTable() {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `chat_reads` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `project_id` INT NOT NULL,
                `last_read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `user_project` (`user_id`, `project_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Exception $e) {}
    }
    
    // GET /api/projects/unread-counts — returns { projectId: unreadCount, ... }
    // For category (parent) projects, the count is the SUM of all sub-project unread counts
    public static function unreadChatCounts() {
        self::ensureChatReadsTable();
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Get all projects the user has access to
        if (Auth::user() && Auth::user()['role'] === 'admin') {
            $projects = DB::fetchAll("SELECT id, parent_id FROM projects WHERE workspace_id = ?", [$wsId]);
        } else {
            $projects = DB::fetchAll(
                "SELECT DISTINCT p.id, p.parent_id FROM projects p
                 INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 WHERE p.workspace_id = ?",
                [$userId, $wsId]
            );
        }
        
        // Count unread for each individual project
        $perProject = [];
        foreach ($projects as $p) {
            $pid = (int)$p['id'];
            
            $readRow = DB::fetch(
                "SELECT last_read_at FROM chat_reads WHERE user_id = ? AND project_id = ?",
                [$userId, $pid]
            );
            
            if ($readRow) {
                $count = DB::fetch(
                    "SELECT COUNT(*) as c FROM project_chat WHERE project_id = ? AND created_at > ? AND user_id != ?",
                    [$pid, $readRow['last_read_at'], $userId]
                );
            } else {
                $count = DB::fetch(
                    "SELECT COUNT(*) as c FROM project_chat WHERE project_id = ? AND user_id != ?",
                    [$pid, $userId]
                );
            }
            
            $perProject[$pid] = (int)($count['c'] ?? 0);
        }
        
        // Build result: for sub-projects, use their own count; for categories, sum all sub-projects
        $result = [];
        foreach ($projects as $p) {
            $pid = (int)$p['id'];
            if ($p['parent_id'] === null) {
                // Category: sum unread from all accessible sub-projects
                $total = 0;
                foreach ($projects as $sp) {
                    if ((int)$sp['parent_id'] === $pid) {
                        $total += $perProject[(int)$sp['id']] ?? 0;
                    }
                }
                $result[$pid] = $total;
            } else {
                $result[$pid] = $perProject[$pid] ?? 0;
            }
        }
        
        jsonResponse(['unread' => $result]);
    }
    
    // ===== COMMENTS FEED (B7) =====
    // GET /api/comments-feed — all project-chat comments across projects the user
    // is part of, newest first, with unread flag + total unread count.
    public static function commentsFeed() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        $user = Auth::user();
        $isAdmin = $user && $user['role'] === 'admin';

        if ($isAdmin) {
            $projRows = DB::fetchAll("SELECT id FROM projects WHERE workspace_id = ?", [$wsId]);
        } else {
            $projRows = DB::fetchAll(
                "SELECT DISTINCT p.id FROM projects p
                 LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 LEFT JOIN projects parent ON p.parent_id = parent.id
                 LEFT JOIN project_members pm2 ON pm2.project_id = parent.id AND pm2.user_id = ?
                 WHERE p.workspace_id = ? AND (pm.user_id IS NOT NULL OR pm2.user_id IS NOT NULL)",
                [$userId, $userId, $wsId]
            );
        }
        $ids = array_map(fn($r) => (int)$r['id'], $projRows);
        if (!$ids) { jsonResponse(['comments' => [], 'unread' => 0]); return; }
        $inClause = implode(',', $ids);

        $readRow = DB::fetch("SELECT last_read_at FROM comment_feed_reads WHERE user_id = ?", [$userId]);
        $lastRead = $readRow['last_read_at'] ?? null;

        $rows = DB::fetchAll(
            "SELECT pc.*, u.name, u.avatar_color, p.name as project_name
             FROM project_chat pc
             JOIN users u ON pc.user_id = u.id
             JOIN projects p ON pc.project_id = p.id
             WHERE pc.project_id IN ($inClause)
             ORDER BY pc.created_at DESC LIMIT 100",
            []
        );

        $unread = 0;
        $comments = array_map(function($c) use ($userId, $lastRead, &$unread) {
            $isUnread = ($c['user_id'] != $userId) && (!$lastRead || $c['created_at'] > $lastRead);
            if ($isUnread) $unread++;
            return [
                'id' => (int)$c['id'],
                'project_id' => (int)$c['project_id'],
                'project_name' => $c['project_name'],
                'who' => $c['name'],
                'initials' => initials($c['name']),
                'bg' => $c['avatar_color'],
                'text' => $c['body'],
                'time_ago' => timeAgo($c['created_at']),
                'me' => $c['user_id'] == $userId,
                'unread' => $isUnread,
            ];
        }, $rows);

        jsonResponse(['comments' => $comments, 'unread' => $unread]);
    }

    // POST /api/comments-feed/read — mark comments feed as read
    public static function markCommentsFeedRead() {
        $userId = Auth::userId();
        $existing = DB::fetch("SELECT id FROM comment_feed_reads WHERE user_id = ?", [$userId]);
        if ($existing) {
            DB::query("UPDATE comment_feed_reads SET last_read_at = NOW() WHERE user_id = ?", [$userId]);
        } else {
            DB::insert('comment_feed_reads', ['user_id' => $userId]);
        }
        jsonResponse(['ok' => true]);
    }

    // ===== CARD ORDERING (B6) =====
    // GET /api/card-order — returns { scope_id: [projectId,...], ... } for the user
    public static function cardOrder() {
        $rows = DB::fetchAll("SELECT scope_id, order_json FROM card_orders WHERE user_id = ?", [Auth::userId()]);
        $out = [];
        foreach ($rows as $r) {
            $arr = json_decode($r['order_json'], true);
            if (is_array($arr)) $out[(int)$r['scope_id']] = array_map('intval', $arr);
        }
        jsonResponse(['orders' => $out]);
    }

    // POST /api/card-order — body { scope_id, order: [ids] }
    public static function saveCardOrder() {
        $data = input();
        $scopeId = isset($data['scope_id']) ? (int)$data['scope_id'] : 0;
        $order = $data['order'] ?? [];
        if (!is_array($order)) jsonError('order must be an array');
        $order = array_values(array_map('intval', $order));
        $json = json_encode($order);
        $existing = DB::fetch("SELECT id FROM card_orders WHERE user_id = ? AND scope_id = ?", [Auth::userId(), $scopeId]);
        if ($existing) {
            DB::update('card_orders', ['order_json' => $json], 'user_id = ? AND scope_id = ?', [Auth::userId(), $scopeId]);
        } else {
            DB::insert('card_orders', ['user_id' => Auth::userId(), 'scope_id' => $scopeId, 'order_json' => $json]);
        }
        jsonResponse(['ok' => true]);
    }

    // ===== REAL-TIME STATE VERSION (B3) =====
    // GET /api/state-version — lightweight poll: returns counters that change when
    // projects/tasks/chat are created, so the client can refresh only when needed.
    public static function stateVersion() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        $user = Auth::user();
        $isAdmin = $user && $user['role'] === 'admin';

        if ($isAdmin) {
            $ids = DB::fetchAll("SELECT id FROM projects WHERE workspace_id = ?", [$wsId]);
        } else {
            $ids = DB::fetchAll(
                "SELECT DISTINCT p.id FROM projects p
                 LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                 LEFT JOIN projects parent ON p.parent_id = parent.id
                 LEFT JOIN project_members pm2 ON pm2.project_id = parent.id AND pm2.user_id = ?
                 WHERE p.workspace_id = ? AND (pm.user_id IS NOT NULL OR pm2.user_id IS NOT NULL)",
                [$userId, $userId, $wsId]
            );
        }
        $idList = array_map(fn($r) => (int)$r['id'], $ids);
        $inClause = $idList ? implode(',', $idList) : '0';

        $proj = DB::fetch("SELECT COUNT(*) as c, COALESCE(UNIX_TIMESTAMP(MAX(updated_at)),0) as m FROM projects WHERE workspace_id = ?", [$wsId]);
        $tasks = DB::fetch("SELECT COUNT(*) as c, COALESCE(UNIX_TIMESTAMP(MAX(updated_at)),0) as m FROM tasks WHERE project_id IN ($inClause)");
        $chat = DB::fetch("SELECT COUNT(*) as c, COALESCE(UNIX_TIMESTAMP(MAX(created_at)),0) as m FROM project_chat WHERE project_id IN ($inClause)");

        // A single fingerprint the client can compare against its last-known value.
        $version = ($proj['c'] . ':' . $proj['m']) . '|' . ($tasks['c'] . ':' . $tasks['m']) . '|' . ($chat['c'] . ':' . $chat['m']);
        jsonResponse(['version' => $version]);
    }

    // POST /api/projects/{projectId}/chat/read — mark chat as read
    public static function markChatRead($projectId) {
        self::ensureChatReadsTable();
        $userId = Auth::userId();
        
        // Upsert chat_reads
        $existing = DB::fetch("SELECT id FROM chat_reads WHERE user_id = ? AND project_id = ?", [$userId, $projectId]);
        if ($existing) {
            DB::query("UPDATE chat_reads SET last_read_at = NOW() WHERE user_id = ? AND project_id = ?", [$userId, $projectId]);
        } else {
            DB::insert('chat_reads', ['user_id' => $userId, 'project_id' => $projectId]);
        }
        
        jsonResponse(['ok' => true]);
    }

}
