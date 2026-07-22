<?php

class CrmSyncController {
    public static function syncFollowUps() {
        Auth::requireAdmin();
        jsonResponse(['ok' => true] + CRMTaskBridge::syncAll());
    }

    public static function taskCrmContext($taskId) {
        Authz::requireTaskAccess((int)$taskId);
        $row = DB::fetch(
            "SELECT ctl.link_type, l.lead_id, l.company_name, l.contact_person,
                    l.email, l.phone, l.lead_status, l.priority,
                    i.interaction_id, i.interaction_type, i.interaction_date,
                    i.subject, i.notes, i.outcome, i.next_action, i.next_action_date
             FROM crm_task_links ctl
             JOIN crm_leads l ON l.lead_id = ctl.crm_lead_id
             LEFT JOIN crm_interactions i ON i.interaction_id = ctl.crm_interaction_id
             WHERE ctl.task_id = ? LIMIT 1",
            [(int)$taskId]
        );
        if (!$row) jsonResponse(['context' => null]);
        jsonResponse(['context' => [
            'link_type' => $row['link_type'],
            'lead' => [
                'id' => (int)$row['lead_id'],
                'name' => $row['contact_person'] ?: $row['company_name'] ?: ('Lead #' . $row['lead_id']),
                'company_name' => $row['company_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'status' => $row['lead_status'],
                'priority' => $row['priority'],
            ],
            'interaction' => $row['interaction_id'] ? [
                'id' => (int)$row['interaction_id'],
                'type' => $row['interaction_type'],
                'date' => $row['interaction_date'],
                'subject' => $row['subject'],
                'notes' => $row['notes'],
                'outcome' => $row['outcome'],
                'next_action' => $row['next_action'],
                'next_action_date' => $row['next_action_date'],
            ] : null,
        ]]);
    }
}
