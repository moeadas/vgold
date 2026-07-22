<?php
require_once __DIR__ . '/../lib/DB.php';
require_once __DIR__ . '/../lib/Auth.php';

/**
 * CrmSyncController (Phase 5) — Task ⇆ CRM follow-up bridge.
 *
 * Every CRM interaction that carries a `next_action` is an actionable
 * follow-up. This controller turns those into first-class VGold tasks under
 * the "CRM" project category, one task per interaction, tracked idempotently
 * through the crm_task_links bridge table (UNIQUE task_id, keyed by
 * crm_interaction_id). Re-running the sync never duplicates tasks; it only
 * creates tasks for interactions that don't yet have a link.
 *
 * Assignment: the task is assigned to the VGold user linked to the
 * interaction's CRM user (users.crm_user_id = crm_interactions.user_id). If no
 * linked user exists, the task is left unassigned but still created so the
 * follow-up is not lost.
 */
class CrmSyncController {

    /** Resolve (and lazily create) the CRM root category for the workspace. */
    public static function crmCategoryId($wsId) {
        $cat = DB::fetch(
            "SELECT id FROM projects WHERE workspace_id = ? AND parent_id IS NULL AND name = 'CRM' LIMIT 1",
            [$wsId]
        );
        if ($cat) return (int)$cat['id'];
        $admin = DB::fetch("SELECT id FROM users WHERE role='admin' AND crm_user_id IS NOT NULL ORDER BY id LIMIT 1");
        $creator = $admin ? (int)$admin['id'] : (int)Auth::userId();
        return (int)DB::insert('projects', [
            'workspace_id' => $wsId,
            'parent_id'    => null,
            'name'         => 'CRM',
            'description'  => 'Leads, follow-ups and sales activity from the Victory Genomics CRM.',
            'color'        => '#C99520',
            'created_by'   => $creator,
        ]);
    }

    /**
     * HTTP entry: sync CRM follow-ups into tasks. Admin-only.
     * Returns counts. Optional {only_open:true} restricts to interactions whose
     * next_action_date is today or in the future (or null).
     */
    public static function syncFollowUps() {
        Auth::requireAdmin();
        $data = input();
        $onlyOpen = !empty($data['only_open']);
        $res = self::runSync(Auth::workspaceId(), $onlyOpen);
        jsonResponse(['ok' => true] + $res);
    }

    /**
     * Core sync routine (also callable from CLI/migration).
     * @return array{created:int,skipped:int,linked_users:int}
     */
    public static function runSync($wsId, $onlyOpen = false) {
        $catId = self::crmCategoryId($wsId);

        // Map crm user_id -> unified user id (for assignment).
        $userMap = [];
        foreach (DB::fetchAll("SELECT id, crm_user_id FROM users WHERE crm_user_id IS NOT NULL") as $u) {
            $userMap[(int)$u['crm_user_id']] = (int)$u['id'];
        }

        // Candidate interactions: have a next_action, not already linked.
        $where = "i.next_action IS NOT NULL AND i.next_action <> ''";
        if ($onlyOpen) {
            $where .= " AND (i.next_action_date IS NULL OR i.next_action_date >= CURDATE())";
        }
        $rows = DB::fetchAll(
            "SELECT i.interaction_id, i.lead_id, i.user_id, i.subject, i.next_action,
                    i.next_action_date, l.company_name, l.contact_person AS contact_name
             FROM crm_interactions i
             LEFT JOIN crm_leads l ON l.lead_id = i.lead_id
             LEFT JOIN crm_task_links tl ON tl.crm_interaction_id = i.interaction_id
             WHERE tl.id IS NULL AND $where
             ORDER BY i.interaction_id"
        );

        $created = 0; $skipped = 0;
        foreach ($rows as $r) {
            $assignee = $userMap[(int)$r['user_id']] ?? null;
            $leadLabel = trim(($r['company_name'] ?? '') . (($r['contact_name'] ?? '') ? ' — ' . $r['contact_name'] : ''));
            $title = 'Follow-up: ' . $r['next_action'];
            if (strlen($title) > 290) $title = substr($title, 0, 287) . '...';
            $desc = 'CRM follow-up';
            if ($leadLabel !== '') $desc .= ' for ' . $leadLabel;
            if (!empty($r['subject'])) $desc .= "\nInteraction: " . $r['subject'];
            $desc .= "\n(Auto-generated from CRM interaction #" . $r['interaction_id'] . ')';

            $due = $r['next_action_date'] ?: null;
            // created_by must be a valid user. Prefer the assignee, else the
            // acting user (HTTP), else any linked CRM user (CLI/migration).
            $creator = $assignee;
            if (!$creator && Auth::check()) $creator = (int)Auth::userId();
            if (!$creator && $userMap)      $creator = (int)reset($userMap);
            if (!$creator)                  $creator = 1;

            $taskId = DB::insert('tasks', [
                'project_id'    => $catId,
                'title'         => $title,
                'description'   => $desc,
                'status'        => 'in_progress',
                'priority'      => 'normal',
                'assigned_to'   => $assignee,
                'created_by'    => $creator,
                'due_date'      => $due,
                'deadline_date' => $due,
                'ai_flagged'    => 0,
            ]);
            if ($assignee) {
                DB::query("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)", [$taskId, $assignee]);
            }
            DB::insert('crm_task_links', [
                'task_id'            => $taskId,
                'crm_lead_id'        => $r['lead_id'] ?: null,
                'crm_interaction_id' => $r['interaction_id'],
                'link_type'          => 'follow_up',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'category_id' => $catId, 'candidates' => count($rows)];
    }

    /**
     * List the CRM context for a given task (used by the task UI to show the
     * linked lead / interaction). Returns null-ish payload if not a CRM task.
     */
    public static function taskCrmContext($taskId) {
        Auth::requireAuth();
        $link = DB::fetch(
            "SELECT tl.*, l.company_name, l.contact_person AS contact_name, l.email AS lead_email, l.phone AS lead_phone,
                    i.interaction_type, i.subject, i.next_action, i.next_action_date
             FROM crm_task_links tl
             LEFT JOIN crm_leads l ON l.lead_id = tl.crm_lead_id
             LEFT JOIN crm_interactions i ON i.interaction_id = tl.crm_interaction_id
             WHERE tl.task_id = ?",
            [(int)$taskId]
        );
        if (!$link) { jsonResponse(['crm' => null]); return; }
        jsonResponse(['crm' => [
            'lead_id'          => $link['crm_lead_id'] ? (int)$link['crm_lead_id'] : null,
            'interaction_id'   => $link['crm_interaction_id'] ? (int)$link['crm_interaction_id'] : null,
            'company_name'     => $link['company_name'],
            'contact_name'     => $link['contact_name'],
            'lead_email'       => $link['lead_email'],
            'lead_phone'       => $link['lead_phone'],
            'interaction_type' => $link['interaction_type'],
            'subject'          => $link['subject'],
            'next_action'      => $link['next_action'],
            'next_action_date' => $link['next_action_date'],
            'lead_url'         => $link['crm_lead_id'] ? ('/crm/pages/lead-detail.php?id=' . (int)$link['crm_lead_id']) : null,
        ]]);
    }
}
