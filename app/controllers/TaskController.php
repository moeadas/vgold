<?php
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/../lib/Mail.php';
require_once __DIR__ . '/../lib/Authz.php';

class TaskController {
    
    public static function create() {
        $data = input();
        requireFields(['project_id', 'title'], $data);

        // Authorization: caller must have access to the target project (IDOR fix)
        Authz::requireProjectAccess($data['project_id']);

        // Validate deadline doesn't exceed project due date
        $deadline = $data['deadline_date'] ?? $data['due_date'] ?? null;
        if ($deadline) {
            validateTaskDeadline($data['project_id'], $deadline);
        }
        
        // Support both single assigned_to and multiple assignees
        $assignedTo = $data['assigned_to'] ?? null;
        $assigneeIds = $data['assignee_ids'] ?? [];
        
        // If assignee_ids provided, use first as assigned_to for backward compat
        if (is_array($assigneeIds) && count($assigneeIds) > 0) {
            $assignedTo = $assigneeIds[0];
        }
        
        $id = DB::insert('tasks', [
            'project_id' => $data['project_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'status' => $data['status'] ?? 'in_progress',
            'priority' => $data['priority'] ?? 'normal',
            'assigned_to' => $assignedTo,
            'created_by' => Auth::userId(),
            'due_date' => $data['due_date'] ?? null,
            'deadline_date' => $deadline,
            'ai_flagged' => 0,
        ]);
        
        // Insert all assignees into task_assignees
        if (is_array($assigneeIds) && count($assigneeIds) > 0) {
            foreach ($assigneeIds as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)", [$id, $uid]);
                }
            }
        } else if ($assignedTo) {
            // Backward compat: also add single assigned_to to task_assignees
            DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)", [$id, (int)$assignedTo]);
        }
        
        // Notify all assigned users
        $notifyIds = is_array($assigneeIds) && count($assigneeIds) > 0 ? $assigneeIds : ($assignedTo ? [$assignedTo] : []);
        $actor = DB::fetch("SELECT name FROM users WHERE id = ?", [Auth::userId()]);
        foreach ($notifyIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0 && $uid !== Auth::userId()) {
                NotificationController::create(
                    $uid, 'assignment',
                    $actor['name'] . " assigned you a task",
                    $data['title'],
                    'task', $id, $data['project_id']
                );
                // Send email notification
                $html = "<h3>New task assigned</h3><p><b>{$actor['name']}</b> assigned you a new task:</p><p style='font-size:16px'><b>{$data['title']}</b></p><p><a href='https://vgold.victorygenomics.com'>View in VGold →</a></p>";
                Mail::sendNotification($uid, 'New task assigned: ' . $data['title'], $html, 'assignment');
            }
        }
        
        jsonResponse(['ok' => true, 'id' => (int)$id]);
    }
    
    public static function update($id) {
        $task = Authz::requireTaskAccess($id);
        
        $data = input();
        
        // Handle _cycle: cycle through statuses
        if (isset($data['_cycle']) && $data['_cycle']) {
            $cycle = ['in_progress' => 'completed', 'completed' => 'in_progress'];
            $newStatus = $cycle[$task['status']] ?? 'in_progress';
            DB::update('tasks', ['status' => $newStatus, 'completed_at' => $newStatus === 'completed' ? date('Y-m-d H:i:s') : null], 'id = ?', [$id]);
            CRMTaskBridge::syncTaskStatus((int)$id, $newStatus);
            jsonResponse(['ok' => true, 'status' => $newStatus]);
            return;
        }
        
        $allowed = ['title', 'description', 'status', 'priority', 'assigned_to', 'due_date', 'deadline_date', 'ai_flagged'];
        $update = array_intersect_key($data, array_flip($allowed));
        
        // Validate deadline if being updated
        if (isset($update['deadline_date']) && $update['deadline_date']) {
            validateTaskDeadline($task['project_id'], $update['deadline_date']);
        }
        
        if (isset($update['status'])) {
            $update['completed_at'] = $update['status'] === 'completed' ? date('Y-m-d H:i:s') : null;
        }
        
        if ($update) {
            DB::update('tasks', $update, 'id = ?', [$id]);
        }
        
        // Handle multi-user assignment update
        if (isset($data['assignee_ids']) && is_array($data['assignee_ids'])) {
            // Remove old assignees not in new list
            $oldAssignees = DB::fetchAll("SELECT user_id FROM task_assignees WHERE task_id = ?", [$id]);
            $oldIds = array_map(fn($a) => (int)$a['user_id'], $oldAssignees);
            $newIds = array_map(fn($a) => (int)$a, $data['assignee_ids']);
            
            // Remove ones not in new list
            $toRemove = array_diff($oldIds, $newIds);
            foreach ($toRemove as $rid) {
                DB::query("DELETE FROM task_assignees WHERE task_id = ? AND user_id = ?", [$id, $rid]);
            }
            
            // Add new ones
            $toAdd = array_diff($newIds, $oldIds);
            $actor = DB::fetch("SELECT name FROM users WHERE id = ?", [Auth::userId()]);
            foreach ($toAdd as $aid) {
                $aid = (int)$aid;
                if ($aid > 0) {
                    DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)", [$id, $aid]);
                    if ($aid !== Auth::userId()) {
                        NotificationController::create(
                            $aid, 'assignment',
                            "You were assigned a task",
                            $task['title'],
                            'task', $id, $task['project_id']
                        );
                        $html = "<h3>New task assigned</h3><p><b>{$actor['name']}</b> assigned you a task:</p><p style='font-size:16px'><b>{$task['title']}</b></p><p><a href='https://vgold.victorygenomics.com'>View in VGold →</a></p>";
                        Mail::sendNotification($aid, 'New task assigned: ' . $task['title'], $html, 'assignment');
                    }
                }
            }
            
            // Update assigned_to for backward compat (first assignee)
            if (count($newIds) > 0) {
                DB::update('tasks', ['assigned_to' => $newIds[0]], 'id = ?', [$id]);
            } else {
                DB::update('tasks', ['assigned_to' => null], 'id = ?', [$id]);
            }
        }
        
        // Notify on single assignment change (backward compat)
        if (isset($update['assigned_to']) && $update['assigned_to'] && $update['assigned_to'] != $task['assigned_to']) {
            $actor = DB::fetch("SELECT name FROM users WHERE id = ?", [Auth::userId()]);
            NotificationController::create(
                $update['assigned_to'], 'assignment',
                "You were assigned a task",
                $task['title'],
                'task', $id, $task['project_id']
            );
            // Sync task_assignees
            DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)", [$id, (int)$update['assigned_to']]);
        }

        if (isset($update['status'])) {
            CRMTaskBridge::syncTaskStatus((int)$id, $update['status']);
        }
        
        jsonResponse(['ok' => true]);
    }
    
    public static function toggle($id) {
        $task = Authz::requireTaskAccess($id);
        $task = DB::fetch("SELECT status, project_id, title, assigned_to FROM tasks WHERE id = ?", [$id]);
        
        $newStatus = $task['status'] === 'completed' ? 'in_progress' : 'completed';
        DB::update('tasks', [
            'status' => $newStatus,
            'completed_at' => $newStatus === 'completed' ? date('Y-m-d H:i:s') : null,
        ], 'id = ?', [$id]);
        CRMTaskBridge::syncTaskStatus((int)$id, $newStatus);
        
        // Notify all assignees when completed
        if ($newStatus === 'completed') {
            $assignees = DB::fetchAll("SELECT user_id FROM task_assignees WHERE task_id = ?", [$id]);
            $actor = DB::fetch("SELECT name FROM users WHERE id = ?", [Auth::userId()]);
            foreach ($assignees as $a) {
                if ($a['user_id'] != Auth::userId()) {
                    NotificationController::create(
                        $a['user_id'], 'completion',
                        $actor['name'] . " completed a task",
                        $task['title'],
                        'task', $id, $task['project_id']
                    );
                }
            }
            // Also notify old single assigned_to
            if ($task['assigned_to'] && $task['assigned_to'] != Auth::userId()) {
                NotificationController::create(
                    $task['assigned_to'], 'completion',
                    $actor['name'] . " completed a task",
                    $task['title'],
                    'task', $id, $task['project_id']
                );
            }
        }
        
        jsonResponse(['ok' => true, 'status' => $newStatus]);
    }
    
    // ===== SHOW single task (for task page) =====
    public static function show($id) {
        $task = Authz::requireTaskAccess($id);
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Get full task details with project info
        $taskRow = DB::fetch(
            "SELECT t.*, p.name as project_name, p.color as project_color, p.id as project_id
             FROM tasks t JOIN projects p ON t.project_id = p.id
             WHERE t.id = ?",
            [$id]
        );
        if (!$taskRow) jsonError('Task not found', 404);
        
        // Get assignees
        $assignees = DB::fetchAll(
            "SELECT u.id, u.name, u.avatar_color FROM task_assignees ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = ?",
            [$id]
        );
        
        // Get comments
        $comments = DB::fetchAll(
            "SELECT tc.*, u.name, u.avatar_color FROM task_comments tc JOIN users u ON tc.user_id = u.id WHERE tc.task_id = ? ORDER BY tc.created_at ASC",
            [$id]
        );
        
        // Get task files
        $files = DB::fetchAll(
            "SELECT * FROM task_files WHERE task_id = ? ORDER BY created_at DESC",
            [$id]
        );
        
        // Format files
        $formattedFiles = array_map(function($f) {
            $size = (int)($f['file_size'] ?? 0);
            $sizeLabel = $size < 1048576 ? round($size/1024) . ' KB' : round($size/1048576, 1) . ' MB';
            return [
                'id' => (int)$f['id'],
                'name' => $f['file_name'],
                'ext' => strtoupper(pathinfo($f['file_name'], PATHINFO_EXTENSION)),
                'size' => $sizeLabel,
                'uploaded_by' => (int)$f['uploaded_by'],
            ];
        }, $files);
        
        // Format comments
        $formattedComments = array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'who' => $c['name'],
                'initials' => initials($c['name']),
                'bg' => $c['avatar_color'],
                'text' => $c['body'],
                'time' => timeAgo($c['created_at']),
            ];
        }, $comments);
        
        // Format assignees
        $formattedAssignees = array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'initials' => initials($a['name']),
                'avatar_color' => $a['avatar_color'],
            ];
        }, $assignees);
        
        jsonResponse(['task' => [
            'id' => (int)$taskRow['id'],
            'title' => $taskRow['title'],
            'description' => $taskRow['description'] ?? '',
            'status' => $taskRow['status'],
            'priority' => $taskRow['priority'],
            'project_id' => (int)$taskRow['project_id'],
            'project_name' => $taskRow['project_name'],
            'project_color' => $taskRow['project_color'],
            'deadline_date' => $taskRow['deadline_date'] ?? $taskRow['due_date'],
            'assignees' => $formattedAssignees,
            'comments' => $formattedComments,
            'files' => $formattedFiles,
        ]]);
    }
    
    // ===== UPLOAD FILE to task =====
    public static function uploadFile($id) {
        Authz::requireTaskAccess($id);
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload failed');
        if ($file['size'] > 50 * 1024 * 1024) jsonError('File too large (max 50MB)');
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $blocked = ['php','php3','php4','php5','phtml','phar','cgi','pl','py','sh','htaccess','exe','bat'];
        if (in_array($ext, $blocked)) jsonError('File type not allowed');
        
        // Store file locally in uploads/task_files/
        $uploadDir = __DIR__ . '/../../uploads/task_files/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $uniqueName = $id . '_' . time() . '_' . $safeName;
        $filePath = 'uploads/task_files/' . $uniqueName;
        $fullPath = __DIR__ . '/../../' . $filePath;
        
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            jsonError('Failed to save file');
        }
        
        $fileId = DB::insert('task_files', [
            'task_id' => $id,
            'file_name' => $file['name'],
            'file_path' => $filePath,
            'file_size' => $file['size'],
            'file_type' => $file['type'] ?? '',
            'uploaded_by' => Auth::userId(),
        ]);
        
        $sizeLabel = $file['size'] < 1048576 ? round($file['size']/1024) . ' KB' : round($file['size']/1048576, 1) . ' MB';
        
        jsonResponse(['ok' => true, 'file' => [
            'id' => (int)$fileId,
            'name' => $file['name'],
            'size' => $sizeLabel,
            'ext' => strtoupper($ext),
            'by' => 'You',
            'when' => 'just now',
        ]]);
    }
    
    public static function delete($id) {
        Authz::requireTaskAccess($id);
        CRMTaskBridge::unlinkTask((int)$id);
        DB::delete('tasks', 'id = ?', [$id]);
        jsonResponse(['ok' => true]);
    }
    
    public static function addComment($id) {
        Authz::requireTaskAccess($id);
        $data = input();
        requireFields(['body'], $data);
        
        $cid = DB::insert('task_comments', [
            'task_id' => $id,
            'user_id' => Auth::userId(),
            'body' => $data['body'],
        ]);
        
        // Notify @mentions in the comment
        NotificationController::notifyMentions($data['body'], Auth::userId(), null, $id);
        
        // Also notify task assignees about the comment
        $task = DB::fetch("SELECT title, project_id FROM tasks WHERE id = ?", [$id]);
        $assignees = DB::fetchAll("SELECT user_id FROM task_assignees WHERE task_id = ?", [$id]);
        $actor = DB::fetch("SELECT name FROM users WHERE id = ?", [Auth::userId()]);
        foreach ($assignees as $a) {
            if ($a['user_id'] != Auth::userId()) {
                NotificationController::create(
                    $a['user_id'], 'comment',
                    $actor['name'] . ' commented on ' . $task['title'],
                    $data['body'],
                    'task', $id, $task['project_id']
                );
            }
        }
        
        $comment = DB::fetch(
            "SELECT tc.*, u.name, u.avatar_color FROM task_comments tc JOIN users u ON tc.user_id = u.id WHERE tc.id = ?",
            [$cid]
        );
        
        jsonResponse(['ok' => true, 'comment' => [
            'id' => (int)$comment['id'],
            'who' => $comment['name'],
            'initials' => initials($comment['name']),
            'bg' => $comment['avatar_color'],
            'text' => $comment['body'],
            'time' => 'just now',
        ]]);
    }
    
    public static function today() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Use task_assignees for multi-user
        $focusTasks = DB::fetchAll(
            "SELECT t.*, p.name as project_name, p.color as project_color, p.id as project_id 
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id 
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             WHERE (ta.user_id = ? OR t.assigned_to = ?) AND p.workspace_id = ? AND t.status NOT IN ('completed') 
             AND (t.deadline_date IS NOT NULL AND t.deadline_date <= CURDATE())
             GROUP BY t.id
             ORDER BY t.ai_flagged DESC, t.deadline_date ASC LIMIT 10",
            [$userId, $userId, $wsId]
        );
        
        $followUps = DB::fetchAll(
            "SELECT DISTINCT t.*, p.name as project_name, u.name as assignee_name, u.avatar_color 
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id 
             LEFT JOIN users u ON t.assigned_to = u.id 
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             WHERE p.workspace_id = ? AND t.status = 'in_progress'
             AND (ta.user_id IS NOT NULL AND ta.user_id != ?) AND ta.user_id IS NOT NULL
             ORDER BY t.updated_at ASC LIMIT 10",
            [$wsId, $userId]
        );
        
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekStats = DB::fetch(
            "SELECT 
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress
             FROM tasks t JOIN projects p ON t.project_id = p.id 
             WHERE p.workspace_id = ? AND t.updated_at >= ?",
            [$wsId, $weekStart]
        );
        
        jsonResponse([
            'focus' => array_map(fn($t) => [
                'id' => (int)$t['id'],
                'title' => $t['title'],
                'project' => $t['project_name'],
                'project_id' => (int)$t['project_id'],
                'dot' => $t['project_color'],
                'due' => formatDate($t['deadline_date'] ?? $t['due_date']),
                'ai_flagged' => (bool)$t['ai_flagged'],
                'done' => false,
            ], $focusTasks),
            'followups' => array_map(fn($t) => [
                'id' => (int)$t['id'],
                'title' => ($t['assignee_name'] ?? 'Someone') . ' — ' . $t['title'],
                'meta' => 'Waiting · ' . $t['project_name'],
                'initials' => initials($t['assignee_name'] ?? '??'),
                'avBg' => $t['avatar_color'] ?? '#9A8A78',
            ], $followUps),
            'week' => [
                'done' => (int)($weekStats['done'] ?? 0),
                'in_progress' => (int)($weekStats['in_progress'] ?? 0),
                'blocked' => 0,
            ],
        ]);
    }
    
    // ===== MY TASKS — all tasks assigned to current user =====
    public static function myTasks() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        $tasks = DB::fetchAll(
            "SELECT DISTINCT t.*, p.name as project_name, p.color as project_color, p.id as project_id,
                    u.name as assignee_name, u.avatar_color as assignee_color
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id
             LEFT JOIN users u ON t.assigned_to = u.id
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             WHERE (ta.user_id = ? OR t.assigned_to = ?) AND p.workspace_id = ?
             ORDER BY 
                CASE t.status WHEN 'in_progress' THEN 0 WHEN 'completed' THEN 1 END,
                t.priority = 'urgent' DESC,
                t.deadline_date ASC",
            [$userId, $userId, $wsId]
        );
        
        $result = array_map(fn($t) => [
            'id' => (int)$t['id'],
            'title' => $t['title'],
            'description' => $t['description'] ?? '',
            'source_module' => $t['source_module'] ?? null,
            'status' => $t['status'],
            'status_label' => statusLabel($t['status']),
            'status_color' => statusColor($t['status']),
            'priority' => $t['priority'],
            'project_name' => $t['project_name'],
            'project_id' => (int)$t['project_id'],
            'project_color' => $t['project_color'],
            'deadline_date' => $t['deadline_date'] ?? $t['due_date'],
            'deadline_label' => formatDate($t['deadline_date'] ?? $t['due_date']),
            'done' => $t['status'] === 'completed',
            'assignee_name' => $t['assignee_name'],
            'assignee_color' => $t['assignee_color'],
        ], $tasks);
        
        jsonResponse(['tasks' => $result]);
    }
    
    // ===== MEETING POINTS — all pending tasks across projects =====
    public static function meetingPoints() {
        $wsId = Auth::workspaceId();
        
        $urgent = DB::fetchAll(
            "SELECT DISTINCT t.*, p.name as project_name, p.color as project_color, p.id as project_id,
                    u.name as assignee_name, u.avatar_color as assignee_color
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id
             LEFT JOIN users u ON t.assigned_to = u.id
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             WHERE p.workspace_id = ? AND t.status NOT IN ('completed')
             AND t.priority = 'urgent'
             ORDER BY t.deadline_date ASC",
            [$wsId]
        );
        
        $normal = DB::fetchAll(
            "SELECT DISTINCT t.*, p.name as project_name, p.color as project_color, p.id as project_id,
                    u.name as assignee_name, u.avatar_color as assignee_color
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id
             LEFT JOIN users u ON t.assigned_to = u.id
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             WHERE p.workspace_id = ? AND t.status NOT IN ('completed')
             AND t.priority = 'normal'
             ORDER BY t.deadline_date ASC",
            [$wsId]
        );
        
        // Get all assignees for each task
        $getAssignees = function($taskId) {
            $assignees = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM task_assignees ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = ?",
                [$taskId]
            );
            return array_map(fn($a) => [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'initials' => initials($a['name']),
                'avatar_color' => $a['avatar_color'],
            ], $assignees);
        };
        
        $formatItem = fn($t) => [
            'id' => (int)$t['id'],
            'title' => $t['title'],
            'description' => $t['description'] ?? '',
            'source_module' => $t['source_module'] ?? null,
            'status' => $t['status'],
            'status_label' => statusLabel($t['status']),
            'status_color' => statusColor($t['status']),
            'priority' => $t['priority'],
            'project_name' => $t['project_name'],
            'project_id' => (int)$t['project_id'],
            'project_color' => $t['project_color'],
            'deadline_date' => $t['deadline_date'] ?? $t['due_date'],
            'deadline_label' => formatDate($t['deadline_date'] ?? $t['due_date']),
            'assignee_name' => $t['assignee_name'] ?? 'Unassigned',
            'assignee_initials' => $t['assignee_name'] ? initials($t['assignee_name']) : '—',
            'assignee_color' => $t['assignee_color'] ?? '#9A8A78',
            'assignees' => $getAssignees((int)$t['id']),
        ];
        
        jsonResponse([
            'urgent' => array_map($formatItem, $urgent),
            'normal' => array_map($formatItem, $normal),
            'byCategory' => self::meetingByCategory($wsId),
            'stats' => [
                'total' => count($urgent) + count($normal),
                'urgent' => count($urgent),
                'normal' => count($normal),
                'in_progress' => count(array_filter(array_merge($urgent, $normal), fn($t) => $t['status'] === 'in_progress')),
            ],
        ]);
    }
    
    private static function meetingByCategory($wsId) {
        $categories = DB::fetchAll(
            "SELECT p.id, p.name, p.color FROM projects p WHERE p.workspace_id = ? AND p.parent_id IS NULL ORDER BY p.name ASC",
            [$wsId]
        );
        
        $result = [];
        foreach ($categories as $cat) {
            $tasks = DB::fetchAll(
                "SELECT DISTINCT t.*, p.name as project_name, p.id as project_id, p.color as project_color,
                        u.name as assignee_name, u.avatar_color as assignee_color
                 FROM tasks t
                 JOIN projects p ON t.project_id = p.id
                 LEFT JOIN users u ON t.assigned_to = u.id
                 LEFT JOIN task_assignees ta ON ta.task_id = t.id
                 WHERE (p.id = ? OR p.parent_id = ?)
                 AND t.status NOT IN ('completed')
                 ORDER BY t.priority DESC, t.deadline_date ASC",
                [$cat['id'], $cat['id']]
            );
            
            if (count($tasks) === 0) continue;
            
            $result[] = [
                'id' => (int)$cat['id'],
                'name' => $cat['name'],
                'color' => $cat['color'],
                'tasks' => array_map(fn($t) => [
                    'id' => (int)$t['id'],
                    'title' => $t['title'],
                    'status' => $t['status'],
                    'status_label' => statusLabel($t['status']),
                    'status_color' => statusColor($t['status']),
                    'priority' => $t['priority'],
                    'project_name' => $t['project_name'],
                    'project_id' => (int)$t['project_id'],
                    'project_color' => $t['project_color'],
                    'assignee_name' => $t['assignee_name'] ?? 'Unassigned',
                    'assignee_initials' => $t['assignee_name'] ? initials($t['assignee_name']) : '—',
                    'assignee_color' => $t['assignee_color'] ?? '#9A8A78',
                ], $tasks),
            ];
        }
        return $result;
    }
    
    // ===== MEETING AGENDA =====
    
    public static function createAgenda() {
        $data = input();
        requireFields(['title'], $data);
        
        $wsId = Auth::workspaceId();
        
        // Validate assigned_to is in workspace if provided
        if (!empty($data['assigned_to'])) {
            $wm = DB::fetch("SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?", [$wsId, (int)$data['assigned_to']]);
            if (!$wm) jsonError('Assigned user not in this workspace', 403);
        }
        
        // Validate related_task_id exists if provided
        if (!empty($data['related_task_id'])) {
            $task = DB::fetch("SELECT t.id FROM tasks t JOIN projects p ON t.project_id = p.id WHERE t.id = ? AND p.workspace_id = ?", [(int)$data['related_task_id'], $wsId]);
            if (!$task) jsonError('Related task not found', 404);
        }
        
        // Validate related_project_id exists if provided
        if (!empty($data['related_project_id'])) {
            $proj = DB::fetch("SELECT id FROM projects WHERE id = ? AND workspace_id = ?", [(int)$data['related_project_id'], $wsId]);
            if (!$proj) jsonError('Related project not found', 404);
        }
        
        // Get next sort_order
        $maxOrder = DB::fetch("SELECT MAX(sort_order) as m FROM meeting_agenda WHERE workspace_id = ?", [$wsId]);
        $sortOrder = ($maxOrder['m'] ?? -1) + 1;
        
        $id = DB::insert('meeting_agenda', [
            'workspace_id' => $wsId,
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'assigned_to' => !empty($data['assigned_to']) ? (int)$data['assigned_to'] : null,
            'related_task_id' => !empty($data['related_task_id']) ? (int)$data['related_task_id'] : null,
            'related_project_id' => !empty($data['related_project_id']) ? (int)$data['related_project_id'] : null,
            'sort_order' => $sortOrder,
        ]);
        
        // Return the full inserted item (with joined display fields) so the client
        // can render it in place without refetching the whole agenda list.
        $a = DB::fetch(
            "SELECT ma.*, u.name as assignee_name, u.avatar_color as assignee_color,
                    t.title as related_task_title, p.name as related_project_name, p.color as related_project_color
             FROM meeting_agenda ma
             LEFT JOIN users u ON ma.assigned_to = u.id
             LEFT JOIN tasks t ON ma.related_task_id = t.id
             LEFT JOIN projects p ON ma.related_project_id = p.id
             WHERE ma.id = ?",
            [(int)$id]
        );
        $item = $a ? [
            'id' => (int)$a['id'],
            'title' => $a['title'],
            'description' => $a['description'],
            'assigned_to' => $a['assigned_to'] ? (int)$a['assigned_to'] : null,
            'assignee_name' => $a['assignee_name'] ?? null,
            'assignee_initials' => $a['assignee_name'] ? initials($a['assignee_name']) : null,
            'assignee_color' => $a['assignee_color'] ?? null,
            'related_task_id' => $a['related_task_id'] ? (int)$a['related_task_id'] : null,
            'related_task_title' => $a['related_task_title'] ?? null,
            'related_project_id' => $a['related_project_id'] ? (int)$a['related_project_id'] : null,
            'related_project_name' => $a['related_project_name'] ?? null,
            'related_project_color' => $a['related_project_color'] ?? null,
            'sort_order' => (int)$a['sort_order'],
            'created_at' => $a['created_at'],
            'completed_at' => $a['completed_at'],
            'is_completed' => $a['completed_at'] !== null,
        ] : null;
        
        jsonResponse(['ok' => true, 'id' => (int)$id, 'item' => $item]);
    }
    
    public static function listAgenda() {
        $wsId = Auth::workspaceId();
        
        $items = DB::fetchAll(
            "SELECT ma.*, u.name as assignee_name, u.avatar_color as assignee_color,
                    t.title as related_task_title, p.name as related_project_name, p.color as related_project_color
             FROM meeting_agenda ma
             LEFT JOIN users u ON ma.assigned_to = u.id
             LEFT JOIN tasks t ON ma.related_task_id = t.id
             LEFT JOIN projects p ON ma.related_project_id = p.id
             WHERE ma.workspace_id = ?
             ORDER BY ma.sort_order ASC, ma.created_at ASC",
            [$wsId]
        );
        
        $result = array_map(fn($a) => [
            'id' => (int)$a['id'],
            'title' => $a['title'],
            'description' => $a['description'],
            'assigned_to' => $a['assigned_to'] ? (int)$a['assigned_to'] : null,
            'assignee_name' => $a['assignee_name'] ?? null,
            'assignee_initials' => $a['assignee_name'] ? initials($a['assignee_name']) : null,
            'assignee_color' => $a['assignee_color'] ?? null,
            'related_task_id' => $a['related_task_id'] ? (int)$a['related_task_id'] : null,
            'related_task_title' => $a['related_task_title'] ?? null,
            'related_project_id' => $a['related_project_id'] ? (int)$a['related_project_id'] : null,
            'related_project_name' => $a['related_project_name'] ?? null,
            'related_project_color' => $a['related_project_color'] ?? null,
            'sort_order' => (int)$a['sort_order'],
            'created_at' => $a['created_at'],
            'completed_at' => $a['completed_at'],
            'is_completed' => $a['completed_at'] !== null,
        ], $items);
        
        jsonResponse(['agenda' => $result]);
    }
    
    public static function updateAgenda($id) {
        $wsId = Auth::workspaceId();
        $item = DB::fetch("SELECT * FROM meeting_agenda WHERE id = ? AND workspace_id = ?", [$id, $wsId]);
        if (!$item) jsonError('Agenda item not found', 404);
        
        $data = input();
        $allowed = ['title', 'description', 'assigned_to', 'related_task_id', 'related_project_id', 'sort_order'];
        $update = array_intersect_key($data, array_flip($allowed));
        
        // Handle completion toggle via 'completed' field
        if (isset($data['completed'])) {
            if ($data['completed']) {
                $update['completed_at'] = date('Y-m-d H:i:s');
            } else {
                $update['completed_at'] = null;
            }
        }
        
        // Convert empty strings to null for nullable fields
        foreach (['assigned_to', 'related_task_id', 'related_project_id'] as $f) {
            if (isset($update[$f]) && $update[$f] === '') $update[$f] = null;
            if (isset($update[$f]) && $update[$f] !== null) $update[$f] = (int)$update[$f];
        }
        
        if ($update) {
            DB::update('meeting_agenda', $update, 'id = ?', [$id]);
        }
        
        jsonResponse(['ok' => true]);
    }
    
    public static function deleteAgenda($id) {
        $wsId = Auth::workspaceId();
        $item = DB::fetch("SELECT * FROM meeting_agenda WHERE id = ? AND workspace_id = ?", [$id, $wsId]);
        if (!$item) jsonError('Agenda item not found', 404);
        
        DB::delete('meeting_agenda', 'id = ?', [$id]);
        jsonResponse(['ok' => true]);
    }
    
    // ===== ALL TASKS — every task in workspace + per-user stats =====
    public static function allTasks() {
        $userId = Auth::userId();
        $wsId = Auth::workspaceId();
        
        // Fetch all tasks across all projects in workspace
        $tasks = DB::fetchAll(
            "SELECT DISTINCT t.*, p.name as project_name, p.color as project_color, p.id as project_id,
                    u.name as assignee_name, u.avatar_color as assignee_color
             FROM tasks t 
             JOIN projects p ON t.project_id = p.id
             LEFT JOIN users u ON t.assigned_to = u.id
             LEFT JOIN task_assignees ta ON ta.task_id = t.id
             WHERE p.workspace_id = ?
             ORDER BY 
                CASE t.status WHEN 'in_progress' THEN 0 WHEN 'completed' THEN 1 END,
                t.priority = 'urgent' DESC,
                t.deadline_date ASC",
            [$wsId]
        );
        
        // Get all assignees for each task
        $getAssignees = function($taskId) {
            $assignees = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM task_assignees ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = ?",
                [$taskId]
            );
            return array_map(fn($a) => [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'initials' => initials($a['name']),
                'avatar_color' => $a['avatar_color'],
            ], $assignees);
        };
        
        $taskList = array_map(fn($t) => [
            'id' => (int)$t['id'],
            'title' => $t['title'],
            'description' => $t['description'] ?? '',
            'source_module' => $t['source_module'] ?? null,
            'status' => $t['status'],
            'status_label' => statusLabel($t['status']),
            'status_color' => statusColor($t['status']),
            'priority' => $t['priority'],
            'project_name' => $t['project_name'],
            'project_id' => (int)$t['project_id'],
            'project_color' => $t['project_color'],
            'deadline_date' => $t['deadline_date'] ?? $t['due_date'],
            'deadline_label' => formatDate($t['deadline_date'] ?? $t['due_date']),
            'done' => $t['status'] === 'completed',
            'assignee_name' => $t['assignee_name'] ?? 'Unassigned',
            'assignee_initials' => $t['assignee_name'] ? initials($t['assignee_name']) : '—',
            'assignee_color' => $t['assignee_color'] ?? '#9A8A78',
            'assignees' => $getAssignees((int)$t['id']),
        ], $tasks);
        
        // Build per-user statistics
        // Collect all unique users from task_assignees + assigned_to
        $userRows = DB::fetchAll(
            "SELECT DISTINCT u.id, u.name, u.avatar_color 
             FROM users u 
             WHERE u.id IN (
                SELECT ta.user_id FROM task_assignees ta 
                JOIN tasks t ON ta.task_id = t.id 
                JOIN projects p ON t.project_id = p.id 
                WHERE p.workspace_id = ?
                UNION
                SELECT t.assigned_to FROM tasks t 
                JOIN projects p ON t.project_id = p.id 
                WHERE p.workspace_id = ? AND t.assigned_to IS NOT NULL
             )
             ORDER BY u.name ASC",
            [$wsId, $wsId]
        );
        
        $userStats = [];
        foreach ($userRows as $u) {
            $uid = (int)$u['id'];
            // Count tasks for this user (via task_assignees or assigned_to)
            $stats = DB::fetch(
                "SELECT 
                    COUNT(DISTINCT t.id) as total,
                    SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
                 FROM tasks t 
                 JOIN projects p ON t.project_id = p.id
                 LEFT JOIN task_assignees ta ON ta.task_id = t.id
                 WHERE p.workspace_id = ? AND (ta.user_id = ? OR t.assigned_to = ?)
                 GROUP BY t.id",
                [$wsId, $uid, $uid]
            );
            // The GROUP BY t.id in the query above gives per-task rows, so we need to count differently
            $totalCount = DB::fetch(
                "SELECT COUNT(DISTINCT t.id) as cnt
                 FROM tasks t 
                 JOIN projects p ON t.project_id = p.id
                 LEFT JOIN task_assignees ta ON ta.task_id = t.id
                 WHERE p.workspace_id = ? AND (ta.user_id = ? OR t.assigned_to = ?)",
                [$wsId, $uid, $uid]
            );
            $completedCount = DB::fetch(
                "SELECT COUNT(DISTINCT t.id) as cnt
                 FROM tasks t 
                 JOIN projects p ON t.project_id = p.id
                 LEFT JOIN task_assignees ta ON ta.task_id = t.id
                 WHERE p.workspace_id = ? AND (ta.user_id = ? OR t.assigned_to = ?) AND t.status = 'completed'",
                [$wsId, $uid, $uid]
            );
            $inProgressCount = DB::fetch(
                "SELECT COUNT(DISTINCT t.id) as cnt
                 FROM tasks t 
                 JOIN projects p ON t.project_id = p.id
                 LEFT JOIN task_assignees ta ON ta.task_id = t.id
                 WHERE p.workspace_id = ? AND (ta.user_id = ? OR t.assigned_to = ?) AND t.status = 'in_progress'",
                [$wsId, $uid, $uid]
            );
            
            $total = (int)($totalCount['cnt'] ?? 0);
            $completed = (int)($completedCount['cnt'] ?? 0);
            $inProgress = (int)($inProgressCount['cnt'] ?? 0);
            $progress = $total > 0 ? round(($completed / $total) * 100) : 0;
            
            $userStats[] = [
                'id' => $uid,
                'name' => $u['name'],
                'avatar_color' => $u['avatar_color'],
                'initials' => initials($u['name']),
                'total' => $total,
                'completed' => $completed,
                'in_progress' => $inProgress,
                'progress' => $progress,
            ];
        }
        
        jsonResponse([
            'tasks' => $taskList,
            'users' => $userStats,
        ]);
    }
}
