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

    /**
     * Which sidebar nav item does a notification belong to?
     *
     * CRM notifications are mirrored into this table by the CRM's
     * createNotification(), carrying their original type plus link_type
     * 'crm_lead' / 'crm' — so one map covers both apps.
     */
    private static function navIdFor($type, $linkType) {
        static $byType = [
            // Workflow
            'assignment' => 'mytasks',
            'due_soon'   => 'mytasks',
            'overdue'    => 'mytasks',
            'completion' => 'taskoverview',
            'comment'    => 'messages',
            'mention'    => 'messages',
            'message'    => 'messages',
            // CRM
            'lead_assigned' => 'crm-leads',
            'lead_new'      => 'crm-leads',
            'wa_inbound'    => 'crm-communications',
            'wa_unmatched'  => 'crm-communications',
            'wa_failed'     => 'crm-communications',
            'call_missed'   => 'crm-communications',
            'call_logged'   => 'crm-communications',
            'follow_up'     => 'crm-interactions',
            'interaction'   => 'crm-interactions',
            'proposal'      => 'crm-proposals',
            'campaign'      => 'crm-email',
            // Contractor invoices point at two different screens depending on
            // which side you are: the approver's queue, or the contractor's
            // own list. Same link_type, opposite ends of the same transaction.
            'contractor_invoice_submitted' => 'acc-contractor-invoices',
            'contractor_invoice_decision'  => 'my-invoices',
            'contractor_invoice_paid'      => 'my-invoices',
        ];

        $type = (string)$type;
        if (isset($byType[$type])) return $byType[$type];

        // An automation notice is raised by Automations but is *about* a lead —
        // send it where the work is, and only fall back to the rules screen when
        // no lead is attached.
        if ($type === 'automation') {
            return $linkType === 'crm_lead' ? 'crm-leads' : 'crm-automation';
        }

        switch ((string)$linkType) {
            case 'task':
            case 'follow_up': return 'mytasks';
            case 'project':   return 'projects';
            case 'crm_lead':  return 'crm-leads';
            case 'crm':       return 'crm-dashboard';
        }
        return 'mytasks';
    }

    /**
     * Unread notification counts keyed by sidebar nav id, so each menu item can
     * show where its notifications actually are. The per-item numbers always sum
     * to the bell count — nothing is counted twice and nothing is dropped.
     */
    public static function moduleCounts() {
        $userId = Auth::userId();
        $rows = DB::fetchAll(
            "SELECT type, link_type, COUNT(*) AS c
               FROM notifications
              WHERE user_id = ? AND is_read = 0
              GROUP BY type, link_type",
            [$userId]
        );
        $counts = [];
        $total = 0;
        foreach ($rows as $r) {
            $id = self::navIdFor($r['type'], $r['link_type']);
            $counts[$id] = ($counts[$id] ?? 0) + (int)$r['c'];
            $total += (int)$r['c'];
        }

        // Same unread set, broken down by the record each notification points at,
        // so a list can show *which* leads or tasks the module count is about.
        $recs = DB::fetchAll(
            "SELECT link_type, link_id, COUNT(*) AS c
               FROM notifications
              WHERE user_id = ? AND is_read = 0 AND link_type IS NOT NULL AND link_id > 0
              GROUP BY link_type, link_id",
            [$userId]
        );
        $records = [];
        foreach ($recs as $r) {
            $records[$r['link_type']][(string)(int)$r['link_id']] = (int)$r['c'];
        }
        foreach ($records as $k => $v) $records[$k] = (object)$v;

        jsonResponse(['counts' => (object)$counts, 'records' => (object)$records, 'total' => $total]);
    }

    /**
     * Mark one record's notifications read — called when that lead or task is
     * opened. This is what lets a module badge survive being looked at: the
     * count only goes down as the individual records are actually dealt with.
     */
    public static function readRecord() {
        $userId = Auth::userId();
        $data   = input();
        $type   = trim((string)($data['link_type'] ?? ''));
        $id     = (int)($data['link_id'] ?? 0);
        if ($type === '' || $id <= 0) jsonResponse(['ok' => false, 'error' => 'link_type and link_id are required'], 400);

        DB::query(
            "UPDATE notifications SET is_read = 1
              WHERE user_id = ? AND is_read = 0 AND link_type = ? AND link_id = ?",
            [$userId, $type, $id]
        );
        jsonResponse(['ok' => true]);
    }

    /**
     * Mark every unread notification belonging to one nav item as read — called
     * when that module is opened, so a badge clears by being dealt with.
     */
    public static function readModule() {
        $userId = Auth::userId();
        $data   = input();
        $module = trim((string)($data['module'] ?? ''));
        if ($module === '') jsonResponse(['ok' => false, 'error' => 'module is required'], 400);

        $rows = DB::fetchAll(
            "SELECT id, type, link_type FROM notifications WHERE user_id = ? AND is_read = 0 LIMIT 1000",
            [$userId]
        );
        $ids = [];
        foreach ($rows as $r) {
            if (self::navIdFor($r['type'], $r['link_type']) === $module) $ids[] = (int)$r['id'];
        }
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            DB::query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($in)", [$userId]);
        }
        jsonResponse(['ok' => true, 'cleared' => count($ids)]);
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