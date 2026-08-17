<?php
class NotificationController {
    
    public static function list() {
        $userId = Auth::userId();
        $type = $_GET['type'] ?? null;
        self::refIdAvailable();
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
            'ref_id' => (int)($n['ref_id'] ?? 0),
            'is_read' => (bool)$n['is_read'],
            'created_at' => $n['created_at'],
            'time_ago' => timeAgo($n['created_at']),
            // Where clicking this should take you. Computed here so the client
            // never has to guess — and so a link type the client has never seen
            // still lands on a sensible screen instead of doing nothing.
            'target' => self::targetFor($n),
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
    
    /**
     * `ref_id` points at the exact ROW the notification is about — the message,
     * chat line or comment — while link_id points at the CONTAINER you have to
     * open to see it (channel, project, task). Without it a notification could
     * only ever get you to the right screen, not to the thing itself.
     *
     * Added lazily and gated on really existing, same as parent_id elsewhere:
     * if the ALTER fails, deep-linking degrades to screen-level routing instead
     * of making every notification insert reference a missing column.
     */
    private static $refIdCol = null;

    public static function refIdAvailable() {
        if (self::$refIdCol !== null) return self::$refIdCol;
        self::$refIdCol = false;
        try {
            $col = DB::fetch("SHOW COLUMNS FROM `notifications` LIKE 'ref_id'");
            if (!$col) {
                DB::query("ALTER TABLE `notifications` ADD COLUMN `ref_id` INT NULL DEFAULT NULL");
                $col = DB::fetch("SHOW COLUMNS FROM `notifications` LIKE 'ref_id'");
            }
            self::$refIdCol = (bool)$col;
        } catch (\Throwable $e) {
            self::$refIdCol = false;
        }
        return self::$refIdCol;
    }

    // Helper: create a notification for a user
    public static function create($userId, $type, $title, $body, $linkType = null, $linkId = null, $projectId = null, $refId = null) {
        $wsId = Auth::workspaceId();
        $row = [
            'workspace_id' => $wsId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link_type' => $linkType,
            'link_id' => $linkId,
            'project_id' => $projectId,
        ];
        if ($refId && self::refIdAvailable()) $row['ref_id'] = (int)$refId;
        DB::insert('notifications', $row);

        // Deliver browser/mobile push (best-effort, must never break the request).
        // Push used to know only two link types and dropped everything else on
        // '/', which is the same "tapping it does nothing" complaint one layer
        // down — so it now reuses the single routing table below.
        try {
            $t = self::targetFor([
                'type' => $type, 'link_type' => $linkType, 'link_id' => $linkId,
                'project_id' => $projectId, 'ref_id' => $refId,
            ]);
            Push::toUser($userId, $title, $body, $t['hash'] ?: '/');
        } catch (\Throwable $e) { /* push is non-critical */ }
    }

    /**
     * THE routing table. One place decides where a notification goes, and both
     * the bell (via list()) and web push (via create()) read it — the client
     * used to re-implement a partial copy in handleNotifClick() and silently did
     * nothing for link types it had never heard of.
     *
     * Returns:
     *   nav    — sidebar screen to land on, ALWAYS set (never "do nothing")
     *   action — optional deep link: 'task' | 'project' | 'channel' | 'crm_lead'
     *            | 'contractor_invoice'
     *   id / project_id — arguments for that action
     *   focus  — 'channel:123' / 'project:123' / 'task:123': the exact row to
     *            scroll to and highlight once the screen has rendered
     *   hash   — a URL fragment for push notifications
     */
    public static function targetFor($n) {
        $type     = (string)($n['type'] ?? '');
        $linkType = (string)($n['link_type'] ?? '');
        $linkId   = (int)($n['link_id'] ?? 0);
        $projId   = (int)($n['project_id'] ?? 0);
        $refId    = (int)($n['ref_id'] ?? 0);

        $t = ['nav' => self::navIdFor($type, $linkType), 'action' => null,
              'id' => 0, 'project_id' => 0, 'focus' => null, 'hash' => ''];

        switch ($linkType) {
            case 'task':
                if ($linkId) {
                    // goTaskPage() takes an optional project id, so a mention
                    // with no project still opens the task — navigateToTask()
                    // used to give up when the task wasn't in your My Tasks.
                    $t['action'] = 'task'; $t['id'] = $linkId; $t['project_id'] = $projId;
                    if ($refId) $t['focus'] = 'task:' . $refId;
                    $t['hash'] = '/#task/' . $linkId;
                }
                break;

            case 'channel':
                if ($linkId) {
                    $t['nav'] = 'messages';
                    $t['action'] = 'channel'; $t['id'] = $linkId;
                    if ($refId) $t['focus'] = 'channel:' . $refId;
                    $t['hash'] = '/#messages';
                }
                break;

            case 'project':
                if ($linkId) {
                    $t['action'] = 'project'; $t['id'] = $linkId;
                    if ($refId) $t['focus'] = 'project:' . $refId;
                    $t['hash'] = '/#project/' . $linkId;
                } elseif ($projId) {
                    // A mention that lost its link_id but kept a project.
                    $t['action'] = 'project'; $t['id'] = $projId;
                    if ($refId) $t['focus'] = 'project:' . $refId;
                    $t['hash'] = '/#project/' . $projId;
                }
                break;

            case 'crm_lead':
                if ($linkId) { $t['action'] = 'crm_lead'; $t['id'] = $linkId; $t['hash'] = '/#crm-lead/' . $linkId; }
                break;

            case 'contractor_invoice':
                // Which screen depends on which SIDE of the transaction you are,
                // and that is carried by `type`, not by link_type.
                if ($type === 'contractor_invoice_submitted') {
                    $t['nav'] = 'acc-contractor-invoices';
                    if ($linkId) { $t['action'] = 'contractor_invoice'; $t['id'] = $linkId; }
                } else {
                    $t['nav'] = 'my-invoices';
                }
                break;
        }

        // "X replied to you" is the one case where the obvious next act is to
        // reply back, so the client primes the composer. A mention only gets
        // scrolled to and highlighted — hijacking the composer for those would
        // be presumptuous.
        $t['reply'] = ($type === 'chat' && $refId && $t['focus']) ? true : false;

        if (!$t['hash']) $t['hash'] = '/#' . $t['nav'];
        return $t;
    }
    
    // Helper: notify all members of a project except the actor
    /**
     * $alsoExcludeUserId lets a caller take one more person out of the broadcast
     * because it is sending them something more specific — e.g. the author of a
     * message that was just replied to gets "X replied to you" instead of the
     * generic "X posted in <project>", rather than both.
     */
    public static function notifyProjectMembers($projectId, $excludeUserId, $type, $title, $body, $linkType = null, $linkId = null, $alsoExcludeUserId = null) {
        $members = DB::fetchAll(
            "SELECT user_id FROM project_members WHERE project_id = ? AND user_id != ?",
            [$projectId, $excludeUserId]
        );
        foreach ($members as $m) {
            if ($alsoExcludeUserId !== null && (int)$m['user_id'] === (int)$alsoExcludeUserId) continue;
            self::create($m['user_id'], $type, $title, $body, $linkType, $linkId, $projectId);
        }
    }
    
    /**
     * Helper: notify mentioned users in a message.
     *
     * $context optionally overrides where the mention POINTS:
     *   ['link_type' => 'channel', 'link_id' => 7, 'ref_id' => 42, 'label' => 'VG-Team']
     * Without it a channel mention was filed as link_type 'project' with a null
     * link_id — which routed nowhere, and was the wrong container anyway.
     */
    public static function notifyMentions($text, $fromUserId, $projectId, $taskId = null, $context = null) {
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
        $where    = $context['label'] ?? $projName;
        $linkType = $context['link_type'] ?? ($taskId ? 'task' : 'project');
        $linkId   = $context['link_id']   ?? ($taskId ?: $projectId);
        $refId    = $context['ref_id']    ?? null;

        foreach ($matches[1] as $name) {
            $user = DB::fetch("SELECT id, name FROM users WHERE name LIKE ? LIMIT 1", ["%$name%"]);
            if ($user && $user['id'] != $fromUserId) {
                $title = $fromName . ' mentioned you';
                if ($where) $title .= ' in ' . $where;
                // $projectId is now passed through as the 7th arg — it used to be
                // dropped, so EVERY mention row had project_id NULL and a task
                // mention could not be opened by anyone who did not already have
                // that task in their My Tasks.
                self::create(
                    $user['id'], 'mention', $title, $text,
                    $linkType, $linkId, $projectId ?: null, $refId
                );
            }
        }
    }
}