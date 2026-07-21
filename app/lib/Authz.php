<?php
// VGo Authorization helpers — workspace-scoped access control

class Authz {
    
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
