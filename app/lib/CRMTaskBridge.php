<?php

// Keeps legacy CRM follow-ups and native Workflow tasks as one shared work item.
class CRMTaskBridge {
    public static function syncInteraction($interactionId) {
        $interaction = DB::fetch(
            "SELECT i.*, l.company_name, l.contact_person, l.priority AS lead_priority,
                    l.assigned_to AS lead_assigned_to,
                    assignee.id AS assigned_vgold_id, author.id AS author_vgold_id
             FROM crm_interactions i
             JOIN crm_leads l ON l.lead_id = i.lead_id
             LEFT JOIN users assignee ON assignee.crm_user_id = l.assigned_to
             LEFT JOIN users author ON author.crm_user_id = i.user_id
             WHERE i.interaction_id = ?",
            [$interactionId]
        );
        if (!$interaction || trim((string)$interaction['next_action']) === '') return null;

        $workspaceId = self::workspaceIdForUsers($interaction['assigned_vgold_id'], $interaction['author_vgold_id']);
        if (!$workspaceId) return null;
        $createdBy = (int)($interaction['author_vgold_id'] ?: $interaction['assigned_vgold_id'] ?: self::workspaceAdminId($workspaceId));
        if (!$createdBy) return null;

        $projectId = self::ensureFollowUpProject($workspaceId, $createdBy);
        $leadName = trim($interaction['contact_person'] ?: $interaction['company_name'] ?: ('Lead #' . $interaction['lead_id']));
        $title = 'Follow-up: ' . $leadName;
        $details = array_filter([
            'Next action: ' . trim($interaction['next_action']),
            $interaction['subject'] ? 'Context: ' . $interaction['subject'] : null,
            $interaction['notes'] ? 'Notes: ' . $interaction['notes'] : null,
            $interaction['outcome'] ? 'Outcome: ' . $interaction['outcome'] : null,
            $interaction['company_name'] && $interaction['contact_person'] ? 'Company: ' . $interaction['company_name'] : null,
        ]);
        $assignedTo = (int)($interaction['assigned_vgold_id'] ?: $interaction['author_vgold_id'] ?: $createdBy);
        $priority = in_array($interaction['lead_priority'], ['High', 'Urgent'], true) ? 'urgent' : 'normal';
        $dueDate = $interaction['next_action_date'] ?: date('Y-m-d');

        $existing = DB::fetch(
            "SELECT t.id, t.status FROM crm_task_links ctl JOIN tasks t ON t.id = ctl.task_id
             WHERE ctl.crm_interaction_id = ? LIMIT 1",
            [$interactionId]
        );
        if (!$existing) {
            $existing = DB::fetch(
                "SELECT id, status FROM tasks WHERE source_module = 'crm.follow_up' AND source_record_id = ? LIMIT 1",
                [$interactionId]
            );
        }

        $taskData = [
            'project_id' => $projectId,
            'title' => $title,
            'description' => implode("\n\n", $details),
            'priority' => $priority,
            'assigned_to' => $assignedTo ?: null,
            'due_date' => $dueDate,
            'deadline_date' => $dueDate,
            'source_module' => 'crm.follow_up',
            'source_record_id' => (int)$interactionId,
            'crm_lead_id' => (int)$interaction['lead_id'],
        ];

        if ($existing) {
            DB::update('tasks', $taskData, 'id = ?', [(int)$existing['id']]);
            $taskId = (int)$existing['id'];
        } else {
            $taskData['status'] = 'in_progress';
            $taskData['created_by'] = $createdBy;
            $taskData['ai_flagged'] = 0;
            $taskId = (int)DB::insert('tasks', $taskData);
        }

        if ($assignedTo) {
            DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)", [$taskId, $assignedTo]);
        }
        $link = DB::fetch("SELECT id FROM crm_task_links WHERE crm_interaction_id = ? OR task_id = ? LIMIT 1", [$interactionId, $taskId]);
        if ($link) {
            DB::update('crm_task_links', [
                'task_id' => $taskId,
                'crm_lead_id' => (int)$interaction['lead_id'],
                'crm_interaction_id' => (int)$interactionId,
                'link_type' => 'follow_up',
            ], 'id = ?', [(int)$link['id']]);
        } else {
            DB::insert('crm_task_links', [
                'task_id' => $taskId,
                'crm_lead_id' => (int)$interaction['lead_id'],
                'crm_interaction_id' => (int)$interactionId,
                'link_type' => 'follow_up',
            ]);
        }
        return $taskId;
    }

    public static function syncAll() {
        $staleLinks = DB::fetchAll(
            "SELECT ctl.id, ctl.task_id FROM crm_task_links ctl
             LEFT JOIN crm_interactions i ON i.interaction_id = ctl.crm_interaction_id
             WHERE ctl.link_type = 'follow_up'
               AND (i.interaction_id IS NULL OR i.next_action IS NULL OR TRIM(i.next_action) = '')"
        );
        foreach ($staleLinks as $link) {
            $task = DB::fetch("SELECT source_module FROM tasks WHERE id = ?", [(int)$link['task_id']]);
            DB::delete('crm_task_links', 'id = ?', [(int)$link['id']]);
            if ($task && $task['source_module'] === 'crm.follow_up') {
                DB::delete('task_comments', 'task_id = ?', [(int)$link['task_id']]);
                DB::delete('task_assignees', 'task_id = ?', [(int)$link['task_id']]);
                DB::delete('tasks', 'id = ?', [(int)$link['task_id']]);
            }
        }
        $rows = DB::fetchAll(
            "SELECT interaction_id FROM crm_interactions
             WHERE next_action IS NOT NULL AND TRIM(next_action) <> ''
             ORDER BY interaction_id"
        );
        $createdOrUpdated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            try {
                if (self::syncInteraction((int)$row['interaction_id'])) $createdOrUpdated++;
                else $skipped++;
            } catch (\Throwable $e) {
                $skipped++;
                error_log('CRM follow-up sync #' . $row['interaction_id'] . ': ' . $e->getMessage());
            }
        }
        return ['synced' => $createdOrUpdated, 'skipped' => $skipped, 'removed' => count($staleLinks)];
    }

    // Task status is the canonical completion state; CRM reads it through crm_task_links.
    public static function syncTaskStatus($taskId, $status) {
        return;
    }

    public static function unlinkTask($taskId) {
        DB::delete('crm_task_links', 'task_id = ?', [(int)$taskId]);
    }

    private static function workspaceIdForUsers($primaryUserId, $fallbackUserId) {
        foreach ([$primaryUserId, $fallbackUserId] as $userId) {
            if (!$userId) continue;
            $row = DB::fetch("SELECT workspace_id FROM workspace_members WHERE user_id = ? ORDER BY joined_at ASC LIMIT 1", [(int)$userId]);
            if ($row) return (int)$row['workspace_id'];
        }
        $row = DB::fetch("SELECT id FROM workspaces ORDER BY id ASC LIMIT 1");
        return $row ? (int)$row['id'] : null;
    }

    private static function workspaceAdminId($workspaceId) {
        $row = DB::fetch(
            "SELECT user_id FROM workspace_members WHERE workspace_id = ? ORDER BY (role = 'admin') DESC, joined_at ASC LIMIT 1",
            [$workspaceId]
        );
        return $row ? (int)$row['user_id'] : null;
    }

    private static function ensureFollowUpProject($workspaceId, $createdBy) {
        $projectSetting = DB::fetch(
            "SELECT setting_value FROM workspace_settings WHERE workspace_id = ? AND setting_group = 'crm' AND setting_key = 'follow_up_project_name'",
            [$workspaceId]
        );
        $followUpProjectName = trim($projectSetting['setting_value'] ?? '') ?: 'CRM Follow-ups';
        $category = DB::fetch(
            "SELECT id FROM projects WHERE workspace_id = ? AND parent_id IS NULL AND name = 'CRM' LIMIT 1",
            [$workspaceId]
        );
        if (!$category) {
            $categoryId = (int)DB::insert('projects', [
                'workspace_id' => $workspaceId,
                'name' => 'CRM',
                'description' => 'Customer relationships, follow-ups, and sales activity.',
                'color' => '#8E6B3A',
                'health' => 'on_track',
                'progress' => 0,
                'created_by' => $createdBy,
            ]);
        } else {
            $categoryId = (int)$category['id'];
        }

        $project = DB::fetch(
            "SELECT id FROM projects WHERE workspace_id = ? AND parent_id = ? AND name = ? LIMIT 1",
            [$workspaceId, $categoryId, $followUpProjectName]
        );
        if (!$project) {
            $projectId = (int)DB::insert('projects', [
                'workspace_id' => $workspaceId,
                'parent_id' => $categoryId,
                'name' => $followUpProjectName,
                'description' => 'Actionable CRM work synchronized automatically from CRM interactions.',
                'color' => '#C99520',
                'health' => 'on_track',
                'progress' => 0,
                'created_by' => $createdBy,
            ]);
        } else {
            $projectId = (int)$project['id'];
        }

        DB::query(
            "INSERT IGNORE INTO project_members (project_id, user_id, role)
             SELECT ?, user_id, 'Member' FROM workspace_members WHERE workspace_id = ?",
            [$categoryId, $workspaceId]
        );
        DB::query(
            "INSERT IGNORE INTO project_members (project_id, user_id, role)
             SELECT ?, user_id, 'Member' FROM workspace_members WHERE workspace_id = ?",
            [$projectId, $workspaceId]
        );
        return $projectId;
    }
}
