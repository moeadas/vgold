<?php
class AdminController {
    
    public static function reset() {
        Auth::requireAdmin();
        $wsId = Auth::workspaceId();
        
        // Get all project IDs in this workspace
        $projectIds = DB::fetchAll("SELECT id FROM projects WHERE workspace_id = ?", [$wsId]);
        $pIds = array_column($projectIds, 'id');
        
        // Get all user IDs in this workspace (except keep the admin)
        $userId = Auth::userId();

        // Determine other members of THIS workspace (scoped) before we start deleting.
        $otherUserIds = DB::fetchAll("SELECT user_id FROM workspace_members WHERE workspace_id = ? AND user_id != ?", [$wsId, $userId]);
        $otherIds = array_column($otherUserIds, 'user_id');

        DB::conn()->beginTransaction();
        try {
            // Delete in dependency order
            if ($pIds) {
                $placeholders = implode(',', array_fill(0, count($pIds), '?'));
                DB::query("DELETE FROM task_comments WHERE task_id IN (SELECT id FROM tasks WHERE project_id IN ($placeholders))", $pIds);
                DB::query("DELETE FROM tasks WHERE project_id IN ($placeholders)", $pIds);
                DB::query("DELETE FROM files WHERE project_id IN ($placeholders)", $pIds);
                DB::query("DELETE FROM project_chat WHERE project_id IN ($placeholders)", $pIds);
                DB::query("DELETE FROM project_members WHERE project_id IN ($placeholders)", $pIds);
                DB::query("DELETE FROM projects WHERE id IN ($placeholders)", $pIds);
            }
            
            // Delete messages and channels
            DB::query("DELETE FROM messages WHERE channel_id IN (SELECT id FROM channels WHERE workspace_id = ?)", [$wsId]);
            DB::query("DELETE FROM channel_members WHERE channel_id IN (SELECT id FROM channels WHERE workspace_id = ?)", [$wsId]);
            DB::query("DELETE FROM channels WHERE workspace_id = ?", [$wsId]);
            
            // Delete invitations
            DB::query("DELETE FROM invitations WHERE workspace_id = ?", [$wsId]);
            
            // Remove other users from this workspace
            if ($otherIds) {
                $placeholders = implode(',', array_fill(0, count($otherIds), '?'));
                DB::query("DELETE FROM workspace_members WHERE workspace_id = ? AND user_id IN ($placeholders)", array_merge([$wsId], $otherIds));
            }
            
            // Delete other users — only those who belong to this workspace (scope fix)
            if ($otherIds) {
                $placeholders = implode(',', array_fill(0, count($otherIds), '?'));
                DB::query("DELETE FROM user_api_keys WHERE user_id IN ($placeholders)", $otherIds);
                DB::query("DELETE FROM user_settings WHERE user_id IN ($placeholders)", $otherIds);
                DB::query("DELETE FROM project_members WHERE user_id IN ($placeholders)", $otherIds);
                DB::query("DELETE FROM task_assignees WHERE user_id IN ($placeholders)", $otherIds);
                DB::query("DELETE FROM users WHERE id IN ($placeholders)", $otherIds);
            }
            
            // Reset digests
            DB::query("DELETE FROM digests WHERE workspace_id = ?", [$wsId]);
            
            // Recreate default channels
            foreach (['general', 'random'] as $ch) {
                $chId = DB::insert('channels', ['workspace_id' => $wsId, 'name' => $ch, 'type' => 'channel']);
                DB::insert('channel_members', ['channel_id' => $chId, 'user_id' => $userId]);
            }
            
            DB::conn()->commit();
            jsonResponse(['ok' => true, 'message' => 'All data has been reset. Only your account remains.']);
        } catch (Exception $e) {
            DB::conn()->rollBack();
            $msg = APP_DEBUG ? $e->getMessage() : 'Reset failed';
            jsonError($msg, 500);
        }
    }
}