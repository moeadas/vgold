<?php
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/../lib/Mail.php';
require_once __DIR__ . '/../lib/Authz.php';
require_once __DIR__ . '/../lib/SharePointStore.php';
class MessageController {
    
    public static function channels() {
        $wsId = Auth::workspaceId();
        $userId = Auth::userId();

        $user = Auth::user();
        $isAdmin = $user && $user['role'] === 'admin';

        // Admins see all workspace channels; members only see channels they belong to.
        if ($isAdmin) {
            $channels = DB::fetchAll(
                "SELECT c.*, 
                    (SELECT body FROM messages m WHERE m.channel_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_msg,
                    (SELECT COUNT(*) FROM messages m WHERE m.channel_id = c.id) as msg_count
                 FROM channels c WHERE c.workspace_id = ? AND c.type = 'channel'
                 ORDER BY c.name",
                [$wsId]
            );
        } else {
            $channels = DB::fetchAll(
                "SELECT c.*, 
                    (SELECT body FROM messages m WHERE m.channel_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_msg,
                    (SELECT COUNT(*) FROM messages m WHERE m.channel_id = c.id) as msg_count
                 FROM channels c 
                 JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
                 WHERE c.workspace_id = ? AND c.type = 'channel'
                 ORDER BY c.name",
                [$userId, $wsId]
            );
        }
        
        $teamChannels = array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'preview' => $c['last_msg'] ? substr($c['last_msg'], 0, 60) : 'No messages yet',
            'count' => (int)$c['msg_count'],
        ], $channels);
        
        self::ensureChannelReadsTable();

        $dms = DB::fetchAll(
            "SELECT c.*, u.name as dm_name, u.avatar_color, u.id as dm_user_id
             FROM channels c 
             JOIN channel_members cm ON c.id = cm.channel_id 
             LEFT JOIN channel_members cm2 ON c.id = cm2.channel_id AND cm2.user_id != ?
             LEFT JOIN users u ON cm2.user_id = u.id
             WHERE c.workspace_id = ? AND c.type = 'dm' AND cm.user_id = ?",
            [$userId, $wsId, $userId]
        );
        
        $dmChannels = array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => $c['dm_name'] ?? 'Direct',
            'initials' => initials($c['dm_name'] ?? '?'),
            'avBg' => $c['avatar_color'] ?? '#9A8A78',
            'preview' => '',
            'user_id' => (int)$c['dm_user_id'],
            'type' => 'dm',
            'count' => self::channelUnreadCount((int)$c['id'], $userId),
        ], $dms);
        
        // Group DMs
        $groupDms = DB::fetchAll(
            "SELECT c.* FROM channels c 
             JOIN channel_members cm ON c.id = cm.channel_id 
             WHERE c.workspace_id = ? AND c.type = 'group_dm' AND cm.user_id = ?
             ORDER BY c.name",
            [$wsId, $userId]
        );
        
        $groupDmChannels = array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'initials' => initials($c['name']),
            'avBg' => '#9A8A78',
            'preview' => '',
            'type' => 'group_dm',
            'count' => self::channelUnreadCount((int)$c['id'], $userId),
        ], $groupDms);
        
        $dmChannels = array_merge($dmChannels, $groupDmChannels);
        
        // Get workspace members for "Start DM" feature
        $members = DB::fetchAll(
            "SELECT u.id, u.name, u.avatar_color FROM users u 
             JOIN workspace_members wm ON u.id = wm.user_id 
             WHERE wm.workspace_id = ? AND u.id != ?",
            [$wsId, $userId]
        );
        $memberList = array_map(fn($m) => [
            'id' => (int)$m['id'],
            'name' => $m['name'],
            'initials' => initials($m['name']),
            'avatar_color' => $m['avatar_color'],
        ], $members);
        
        $dmUnreadTotal = 0;
        foreach ($dmChannels as $d) { $dmUnreadTotal += $d['count'] ?? 0; }

        jsonResponse(['channels' => $teamChannels, 'dms' => $dmChannels, 'members' => $memberList, 'dm_unread_total' => $dmUnreadTotal]);
    }

    // ===== DM/channel read tracking (B7) =====
    public static function ensureChannelReadsTable() {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `channel_reads` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `channel_id` INT NOT NULL,
                `last_read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `user_channel` (`user_id`, `channel_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Exception $e) {}
    }

    private static function channelUnreadCount($channelId, $userId) {
        $readRow = DB::fetch("SELECT last_read_at FROM channel_reads WHERE user_id = ? AND channel_id = ?", [$userId, $channelId]);
        if ($readRow) {
            $c = DB::fetch("SELECT COUNT(*) as c FROM messages WHERE channel_id = ? AND created_at > ? AND user_id != ?", [$channelId, $readRow['last_read_at'], $userId]);
        } else {
            $c = DB::fetch("SELECT COUNT(*) as c FROM messages WHERE channel_id = ? AND user_id != ?", [$channelId, $userId]);
        }
        return (int)($c['c'] ?? 0);
    }

    private static function markChannelRead($channelId, $userId) {
        self::ensureChannelReadsTable();
        $existing = DB::fetch("SELECT id FROM channel_reads WHERE user_id = ? AND channel_id = ?", [$userId, $channelId]);
        if ($existing) {
            DB::query("UPDATE channel_reads SET last_read_at = NOW() WHERE user_id = ? AND channel_id = ?", [$userId, $channelId]);
        } else {
            DB::insert('channel_reads', ['user_id' => $userId, 'channel_id' => $channelId]);
        }
    }

    public static function show($channelId) {
        Authz::requireChannelAccess($channelId);
        self::markChannelRead($channelId, Auth::userId());
        $messages = DB::fetchAll(
            "SELECT m.*, u.name, u.avatar_color FROM messages m 
             JOIN users u ON m.user_id = u.id 
             WHERE m.channel_id = ? ORDER BY m.created_at ASC",
            [$channelId]
        );
        
        $channel = DB::fetch("SELECT * FROM channels WHERE id = ?", [$channelId]);
        
        jsonResponse([
            'channel' => [
                'id' => (int)$channel['id'],
                'name' => $channel['name'],
                'type' => $channel['type'],
            ],
            'messages' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'who' => $m['name'],
                'initials' => initials($m['name']),
                'bg' => $m['avatar_color'],
                'text' => $m['body'],
                'time' => (new DateTime($m['created_at']))->format('g:i A'),
                'me' => $m['user_id'] == Auth::userId(),
                'attachment' => self::getAttachmentForMessage((int)$m['id']),
            ], $messages),
        ]);
    }
    
    public static function send($channelId) {
        Authz::requireChannelAccess($channelId);
        $data = input();
        
        // Body is optional if an attachment is provided
        $body = $data['body'] ?? '';
        if (!$body && !isset($_FILES['attachment'])) {
            jsonError('Missing fields: body');
        }
        
        $id = DB::insert('messages', [
            'channel_id' => $channelId,
            'user_id' => Auth::userId(),
            'body' => $body,
        ]);
        
        // Handle file attachment
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            // Block executable/script extensions (mirror TaskController) to prevent
            // dropping a webshell into the web-served storage/ tree.
            $blocked = ['php','php3','php4','php5','php7','php8','phtml','phps','phar','cgi','pl','py','sh','htaccess','exe','bat','com','asp','aspx','jsp'];
            if (in_array($ext, $blocked, true)) jsonError('File type not allowed');
            // Non-guessable stored name so a valid attachment path can't be brute-forced.
            $rand = bin2hex(random_bytes(8));
            $storedName = 'msg_' . $id . '_' . $rand . ($ext !== '' ? '.' . $ext : '');
            $uploadDir = UPLOAD_PATH . '/messages';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $filePath = $uploadDir . '/' . $storedName;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                DB::insert('message_attachments', [
                    'message_id' => $id,
                    'filename' => $storedName,
                    'original_name' => $file['name'],
                    'file_size' => $file['size'],
                    'file_type' => $file['type'] ?? '',
                    'file_ext' => $ext,
                ]);
                $attachment = [
                    'original_name' => $file['name'],
                    'size' => $file['size'] < 1048576 ? round($file['size']/1024) . ' KB' : round($file['size']/1048576, 1) . ' MB',
                    'ext' => strtoupper($ext),
                ];
            }
        }
        
        // Notify @mentions
        $channel = DB::fetch("SELECT * FROM channels WHERE id = ?", [$channelId]);
        NotificationController::notifyMentions($body, Auth::userId(), ($channel && isset($channel['project_id'])) ? $channel['project_id'] : null);
        
        $msg = DB::fetch(
            "SELECT m.*, u.name, u.avatar_color FROM messages m JOIN users u ON m.user_id = u.id WHERE m.id = ?",
            [$id]
        );
        
        // Get attachment info if exists
        $attachmentInfo = self::getAttachmentForMessage((int)$id);
        
        jsonResponse(['ok' => true, 'message' => [
            'id' => (int)$msg['id'],
            'who' => $msg['name'],
            'initials' => initials($msg['name']),
            'bg' => $msg['avatar_color'],
            'text' => $msg['body'],
            'time' => 'now',
            'me' => true,
            'attachment' => $attachmentInfo,
        ]]);
    }
    
    public static function mentions() {
        $q = trim($_GET['q'] ?? '');
        $wsId = Auth::workspaceId();
        if (strlen($q) < 1) {
            // @ typed with no filter yet — return all workspace members
            $users = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM users u JOIN workspace_members wm ON u.id = wm.user_id WHERE wm.workspace_id = ? ORDER BY u.name LIMIT 10",
                [$wsId]
            );
        } else {
            $users = DB::fetchAll(
                "SELECT u.id, u.name, u.avatar_color FROM users u JOIN workspace_members wm ON u.id = wm.user_id WHERE wm.workspace_id = ? AND u.name LIKE ? ORDER BY u.name LIMIT 10",
                [$wsId, "%$q%"]
            );
        }
        jsonResponse(['users' => array_map(fn($u) => [
            'id' => (int)$u['id'],
            'name' => $u['name'],
            'color' => $u['avatar_color'],
            'initials' => initials($u['name']),
        ], $users)]);
    }
    
    public static function sendProjectChat($projectId) {
        Authz::requireProjectAccess($projectId);
        $data = input();
        requireFields(['body'], $data);
        
        $id = DB::insert('project_chat', [
            'project_id' => $projectId,
            'user_id' => Auth::userId(),
            'body' => $data['body'],
        ]);
        
        // Notify project members
        $actor = DB::fetch("SELECT name FROM users WHERE id = ?", [Auth::userId()]);
        $project = DB::fetch("SELECT name FROM projects WHERE id = ?", [$projectId]);
        NotificationController::notifyProjectMembers($projectId, Auth::userId(), 'chat', $actor['name'] . ' posted in ' . $project['name'], $data['body'], 'project', $projectId);
        
        // Create mention notifications + send email (non-blocking)
        try {
            NotificationController::notifyMentions($data['body'], Auth::userId(), $projectId);
            
            // Also send email for mentions
            preg_match_all('/@(\w+(?:\s+\w+)?)/', $data['body'], $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $name) {
                    $user = DB::fetch("SELECT id, name FROM users WHERE name LIKE ? LIMIT 1", ["%$name%"]);
                    if ($user && $user['id'] != Auth::userId()) {
                        $html = "<h3>New mention in " . esc($project['name']) . "</h3><p><b>" . esc($actor['name']) . "</b> mentioned you:</p><blockquote>" . esc($data['body']) . "</blockquote><p><a href='https://vgold.victorygenomics.com'>View in VGold →</a></p>";
                        Mail::sendNotification($user['id'], 'You were mentioned in ' . $project['name'], $html, 'mention');
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Mention notification failed: ' . $e->getMessage());
        }
        
        $msg = DB::fetch(
            "SELECT pc.*, u.name, u.avatar_color FROM project_chat pc JOIN users u ON pc.user_id = u.id WHERE pc.id = ?",
            [$id]
        );
        
        jsonResponse(['ok' => true, 'message' => [
            'id' => (int)$msg['id'],
            'who' => $msg['name'],
            'initials' => initials($msg['name']),
            'bg' => $msg['avatar_color'],
            'text' => $msg['body'],
            'time' => 'now',
            'me' => true,
        ]]);
    }
    
    // ===== UPLOAD FILE (SharePoint) =====
    public static function uploadFile($projectId) {
        Authz::requireProjectAccess($projectId);
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        
        $file = $_FILES['file'];
        $cfg = require __DIR__ . '/../../config/graph.php';
        if ($file['size'] > $cfg['max_upload_bytes']) jsonError('File too large');
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $blocked = ['php','php3','php4','php5','phtml','phar','cgi','pl','py','sh','htaccess','exe','bat'];
        if (in_array($ext, $blocked)) jsonError('File type not allowed');

        // Optional target folder (B4b). NULL = project/sub-project root.
        $folderId = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
        if ($folderId) {
            $folder = DB::fetch("SELECT id FROM file_folders WHERE id = ? AND project_id = ?", [$folderId, $projectId]);
            if (!$folder) jsonError('Folder not found', 404);
        }

        // Upload to SharePoint
        $sp = SharePointStore::upload($projectId, $file['tmp_name'], $file['name']);
        
        $id = DB::insert('files', [
            'project_id' => $projectId,
            'folder_id' => $folderId,
            'uploaded_by' => Auth::userId(),
            'filename' => $sp['name'],
            'sp_item_id' => $sp['id'],
            'sp_drive_id' => $cfg['drive_id'],
            'storage' => 'sharepoint',
            'original_name' => $file['name'],
            'file_size' => $file['size'],
            'file_type' => $file['type'] ?? '',
            'file_ext' => $ext,
        ]);
        
        jsonResponse(['ok' => true, 'file' => [
            'id' => (int)$id,
            'name' => $file['name'],
            'size' => $file['size'] < 1048576 ? round($file['size']/1024) . ' KB' : round($file['size']/1048576, 1) . ' MB',
            'ext' => strtoupper($ext),
            'by' => 'You',
            'when' => 'just now',
            'folder_id' => $folderId,
            'storage' => 'sharepoint',
        ]]);
    }
    
    // ===== CREATE CHANNEL =====
    public static function createChannel() {
        $data = input();
        requireFields(['name'], $data);
        
        $wsId = Auth::workspaceId();
        $userId = Auth::userId();
        
        $channelId = DB::insert('channels', [
            'workspace_id' => $wsId,
            'name' => $data['name'],
            'type' => 'channel',
        ]);
        
        // Add creator
        DB::insert('channel_members', ['channel_id' => $channelId, 'user_id' => $userId]);
        
        // Add selected members
        $members = $data['members'] ?? [];
        foreach ($members as $memberId) {
            // Verify member is in workspace
            $wm = DB::fetch("SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?", [$wsId, $memberId]);
            if ($wm) {
                DB::insert('channel_members', ['channel_id' => $channelId, 'user_id' => $memberId]);
            }
        }
        
        jsonResponse(['ok' => true, 'channel_id' => (int)$channelId]);
    }

    // Delete a channel and all of its messages/membership/read rows. Only admins
    // or a member of the channel may delete it. DMs (type != 'channel') are not
    // deletable via this endpoint.
    public static function deleteChannel($channelId) {
        $channelId = (int)$channelId;
        $wsId = Auth::workspaceId();
        $userId = Auth::userId();

        $channel = DB::fetch("SELECT * FROM channels WHERE id = ? AND workspace_id = ?", [$channelId, $wsId]);
        if (!$channel) jsonError('Channel not found', 404);
        if (($channel['type'] ?? 'channel') !== 'channel') jsonError('Only channels can be deleted', 400);

        // Authorization: admins always; otherwise must be a member of the channel.
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') {
            $member = DB::fetch("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, $userId]);
            if (!$member) jsonError('You can only delete channels you belong to', 403);
        }

        // Cascade-delete related rows, then the channel itself.
        DB::query("DELETE FROM messages WHERE channel_id = ?", [$channelId]);
        DB::query("DELETE FROM channel_members WHERE channel_id = ?", [$channelId]);
        // channel_reads may not exist on very old installs — guard it.
        try { DB::query("DELETE FROM channel_reads WHERE channel_id = ?", [$channelId]); } catch (Throwable $e) {}
        DB::query("DELETE FROM channels WHERE id = ?", [$channelId]);

        jsonResponse(['ok' => true]);
    }

    // ===== DOWNLOAD/VIEW FILE (SharePoint + legacy local) =====
    public static function downloadFile($id) {
        $file = Authz::requireFileAccess($id);

        // Link-backed file (B4d): redirect to the external URL (e.g. SharePoint share link)
        if (($file['storage'] ?? 'local') === 'link' && !empty($file['external_url'])) {
            header('Location: ' . $file['external_url']);
            exit;
        }

        // SharePoint-backed file: redirect to short-lived download URL
        if (($file['storage'] ?? 'local') === 'sharepoint' && !empty($file['sp_item_id'])) {
            $url = SharePointStore::downloadUrl($file['sp_item_id']);
            if (!$url) jsonError('File unavailable', 404);
            header('Location: ' . $url);
            exit;
        }
        
        // Legacy local file streaming
        $path = UPLOAD_PATH . '/project_' . $file['project_id'] . '/' . $file['filename'];
        if (!file_exists($path)) jsonError('File not found on disk', 404);
        
        $inline = isset($_GET['inline']);
        $ext = strtolower($file['file_ext'] ?? '');
        $imageTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp'];
        $pdfType = ['pdf' => 'application/pdf'];
        
        $contentType = $file['file_type'] ?: 'application/octet-stream';
        if ($inline && isset($imageTypes[$ext])) $contentType = $imageTypes[$ext];
        if ($inline && isset($pdfType[$ext])) $contentType = $pdfType[$ext];
        
        header('Content-Type: ' . $contentType);
        if ($inline) {
            header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
        }
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }
    
    // ===== PREVIEW FILE (Office for the web) =====
    public static function previewFile($id) {
        $file = Authz::requireFileAccess($id);
        if (($file['storage'] ?? 'local') !== 'sharepoint' || empty($file['sp_item_id'])) {
            jsonError('Preview not available for this file', 400);
        }
        $url = SharePointStore::previewUrl($file['sp_item_id']);
        jsonResponse(['ok' => true, 'url' => $url]);
    }
    
    // ===== DELETE FILE (SharePoint + legacy local) =====
    public static function deleteFile($id) {
        $file = Authz::requireFileAccess($id);
        
        // Only uploader or admin can delete
        $user = Auth::user();
        if ($file['uploaded_by'] != Auth::userId() && $user['role'] !== 'admin') {
            jsonError('You can only delete files you uploaded', 403);
        }
        
        // Delete from SharePoint if applicable
        if (($file['storage'] ?? 'local') === 'link') {
            // Link-only entry: nothing to remove from storage.
        } elseif (($file['storage'] ?? 'local') === 'sharepoint' && !empty($file['sp_item_id'])) {
            SharePointStore::delete($file['sp_item_id']);
        } else {
            // Legacy local delete
            $path = UPLOAD_PATH . '/project_' . $file['project_id'] . '/' . $file['filename'];
            if (file_exists($path)) @unlink($path);
        }
        
        DB::delete('files', 'id = ?', [$id]);
        jsonResponse(['ok' => true]);
    }
    
    // ===== HELPER: get attachment for a message =====
    
    // ===== DOWNLOAD MESSAGE ATTACHMENT =====
    public static function downloadMessageAttachment($id) {
        $att = DB::fetch("SELECT * FROM message_attachments WHERE id = ?", [$id]);
        if (!$att) jsonError('Attachment not found', 404);
        
        // Verify the user has access to the channel this message belongs to
        $msg = DB::fetch("SELECT channel_id FROM messages WHERE id = ?", [$att['message_id']]);
        if (!$msg) jsonError('Message not found', 404);
        Authz::requireChannelAccess($msg['channel_id']);
        
        $path = UPLOAD_PATH . '/messages/' . $att['filename'];
        if (!file_exists($path)) jsonError('File not found on disk', 404);
        
        $ext = strtolower($att['file_ext'] ?? '');
        $contentType = $att['file_type'] ?: 'application/octet-stream';
        
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $att['original_name'] . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    
    private static function getAttachmentForMessage($messageId) {
        $att = DB::fetch(
            "SELECT * FROM message_attachments WHERE message_id = ?",
            [$messageId]
        );
        if (!$att) return null;
        return [
            'id' => (int)$att['id'],
            'original_name' => $att['original_name'],
            'size' => $att['file_size'] < 1048576 ? round($att['file_size']/1024) . ' KB' : round($att['file_size']/1048576, 1) . ' MB',
            'ext' => strtoupper($att['file_ext']),
            'filename' => $att['filename'],
        ];
    }
    
    // ===== START DM =====
    public static function startDM() {
        $data = input();
        $wsId = Auth::workspaceId();
        $userId = Auth::userId();
        
        // Support both single user_id and array of user_ids
        $userIds = $data['user_ids'] ?? null;
        if (!$userIds && isset($data['user_id'])) {
            $userIds = [$data['user_id']];
        }
        if (!$userIds || !is_array($userIds) || count($userIds) < 1) {
            jsonError('Missing fields: user_id or user_ids');
        }
        
        // Convert to ints and dedupe
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        
        // Validate all users are in this workspace
        foreach ($userIds as $uid) {
            $wm = DB::fetch("SELECT id FROM workspace_members WHERE workspace_id = ? AND user_id = ?", [$wsId, $uid]);
            if (!$wm) jsonError('User not in this workspace', 403);
        }
        
        // Single user (1-on-1 DM)
        if (count($userIds) === 1) {
            $otherUserId = $userIds[0];
            
            // Check if DM already exists between these two users
            $existing = DB::fetch(
                "SELECT c.id FROM channels c 
                 WHERE c.workspace_id = ? AND c.type = 'dm'
                 AND c.id IN (SELECT channel_id FROM channel_members WHERE user_id = ?)
                 AND c.id IN (SELECT channel_id FROM channel_members WHERE user_id = ?)",
                [$wsId, $userId, $otherUserId]
            );
            
            if ($existing) {
                jsonResponse(['ok' => true, 'channel_id' => (int)$existing['id'], 'exists' => true]);
            }
            
            // Create new DM channel
            $channelId = DB::insert('channels', [
                'workspace_id' => $wsId,
                'name' => 'dm_' . $userId . '_' . $otherUserId,
                'type' => 'dm',
            ]);
            
            DB::insert('channel_members', ['channel_id' => $channelId, 'user_id' => $userId]);
            DB::insert('channel_members', ['channel_id' => $channelId, 'user_id' => $otherUserId]);
            
            jsonResponse(['ok' => true, 'channel_id' => (int)$channelId, 'exists' => false]);
        }
        
        // Multiple users (group DM)
        $allMembers = array_values(array_unique(array_merge([$userId], $userIds)));
        
        if (count($allMembers) < 3) {
            jsonError('Group DM requires at least 3 users total');
        }
        
        $groupName = $data['name'] ?? null;
        if (!$groupName) {
            // Generate a name from member names
            $names = DB::fetchAll(
                "SELECT id, name FROM users WHERE id IN (" . implode(',', array_fill(0, count($allMembers), '?')) . ")",
                $allMembers
            );
            $nameMap = [];
            foreach ($names as $n) $nameMap[$n['id']] = $n['name'];
            $groupName = implode(', ', array_map(fn($uid) => $nameMap[$uid] ?? 'Unknown', $allMembers));
        }
        
        // Create group_dm channel
        $channelId = DB::insert('channels', [
            'workspace_id' => $wsId,
            'name' => $groupName,
            'type' => 'group_dm',
        ]);
        
        foreach ($allMembers as $uid) {
            DB::insert('channel_members', ['channel_id' => $channelId, 'user_id' => $uid]);
        }
        
        jsonResponse(['ok' => true, 'channel_id' => (int)$channelId, 'exists' => false, 'type' => 'group_dm']);
    }

    
    // Edit file — internal (Microsoft-authenticated) users only
    public static function editFile($id) {
        $file = Authz::requireFileAccess($id);
        if (($_SESSION['auth_provider'] ?? '') !== 'microsoft') jsonError('Editing requires a Microsoft account', 403);
        if ($file['storage'] !== 'sharepoint') jsonError('Not editable', 400);
        
        $resp = Graph::request('POST',
            "/drives/" . $file['sp_drive_id'] . "/items/" . $file['sp_item_id'] . "/createLink",
            json_encode(['type' => 'edit', 'scope' => 'organization']),
            ['Content-Type: application/json']);
        if ($resp['code'] >= 300) jsonError('Could not create edit link', 500);
        $d = json_decode($resp['body'], true);
        jsonResponse(['ok' => true, 'url' => $d['link']['webUrl'] ?? null]);
    }
}
