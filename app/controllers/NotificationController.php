<?php
class NotificationController {
    
    public static function list() {
        $userId = Auth::userId();
        $type = $_GET['type'] ?? null;
        if ($type) {
            $notifs = DB::fetchAll(
                "SELECT * FROM notifications WHERE user_id = ? AND type = ? ORDER BY created_at DESC LIMIT 50",
                [$userId, $type]
            );
        } else {
            $notifs = DB::fetchAll(
                "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
                [$userId]
            );
        }
        $result = array_map(fn($n) => [
            'id' => (int)$n['id'],
            'type' => $n['type'],
            'title' => $n['title'],
            'body' => $n['body'],
            'link_type' => $n['link_type'],
            'link_id' => (int)$n['link_id'],
            'project_id' => (int)$n['project_id'],
            'is_read' => (bool)$n['is_read'],
            'created_at' => $n['created_at'],
            'time_ago' => timeAgo($n['created_at']),
        ], $notifs);
        jsonResponse(['notifications' => $result]);
    }
    
    public static function unreadCount() {
        $userId = Auth::userId();
        $count = DB::fetch("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
        jsonResponse(['count' => (int)$count['c']]);
    }
    
    public static function markRead($id) {
        DB::update('notifications', ['is_read' => 1], 'id = ? AND user_id = ?', [$id, Auth::userId()]);
        jsonResponse(['ok' => true]);
    }
    
    public static function markAllRead() {
        DB::update('notifications', ['is_read' => 1], 'user_id = ?', [Auth::userId()]);
        jsonResponse(['ok' => true]);
    }
    
    public static function subscribe() {
        // Store push subscription endpoint
        $data = input();
        $endpoint = $data['endpoint'] ?? '';
        $userId = Auth::userId();
        if ($endpoint) {
            DB::query(
                "INSERT IGNORE INTO push_subscriptions (user_id, endpoint, auth_keys, created_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE created_at = NOW()",
                [$userId, $endpoint, json_encode($data['keys'] ?? [])]
            );
        }
        jsonResponse(['ok' => true]);
    }
    
    // Helper: create a notification for a user
    public static function create($userId, $type, $title, $body, $linkType = null, $linkId = null, $projectId = null) {
        $wsId = Auth::workspaceId();
        DB::insert('notifications', [
            'workspace_id' => $wsId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link_type' => $linkType,
            'link_id' => $linkId,
            'project_id' => $projectId,
        ]);
        
        // Deliver browser/mobile push (best-effort, must never break the request)
        try {
            $link = '/';
            if ($linkType === 'task' && $projectId) $link = '/#project/' . $projectId;
            else if ($linkType === 'project' && $linkId) $link = '/#project/' . $linkId;
            Push::toUser($userId, $title, $body, $link);
        } catch (\Throwable $e) { /* push is non-critical */ }
    }
    
    // Helper: notify all members of a project except the actor
    public static function notifyProjectMembers($projectId, $excludeUserId, $type, $title, $body, $linkType = null, $linkId = null) {
        $members = DB::fetchAll(
            "SELECT user_id FROM project_members WHERE project_id = ? AND user_id != ?",
            [$projectId, $excludeUserId]
        );
        foreach ($members as $m) {
            self::create($m['user_id'], $type, $title, $body, $linkType, $linkId, $projectId);
        }
    }
    
    // Helper: notify mentioned users in a message
    public static function notifyMentions($text, $fromUserId, $projectId, $taskId = null) {
        // Match @Name patterns
        preg_match_all('/@(\w+(?:\s+\w+)?)/', $text, $matches);
        if (empty($matches[1])) return;
        
        $fromUser = DB::fetch("SELECT name FROM users WHERE id = ?", [$fromUserId]);
        $fromName = $fromUser['name'] ?? 'Someone';
        
        // Get project name for context
        $projName = '';
        if ($projectId) {
            $proj = DB::fetch("SELECT name FROM projects WHERE id = ?", [$projectId]);
            $projName = $proj['name'] ?? '';
        }
        
        foreach ($matches[1] as $name) {
            $user = DB::fetch("SELECT id, name FROM users WHERE name LIKE ? LIMIT 1", ["%$name%"]);
            if ($user && $user['id'] != $fromUserId) {
                $title = $fromName . ' mentioned you';
                if ($projName) $title .= ' in ' . $projName;
                self::create(
                    $user['id'],
                    'mention',
                    $title,
                    $text,
                    $taskId ? 'task' : 'project',
                    $taskId ?: $projectId
                );
            }
        }
    }
}