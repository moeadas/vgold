<?php
/**
 * Victory Genomics CRM — Notification Helper
 * Provides createNotification() for use by any PHP file.
 * Safe to require_once from anywhere — contains only a function definition.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Create an in-app notification for a CRM user.
 *
 * @param int       $userId  Recipient user_id
 * @param string    $type    Notification type (wa_inbound, wa_unmatched, lead_assigned, system, etc.)
 * @param string    $title   Short headline
 * @param string    $body    Optional longer description / preview
 * @param string    $link    Optional CRM page to navigate to on click
 * @param int|null  $leadId  Optional related lead_id
 * @return int      notification_id
 */
function createNotification($userId, $type, $title, $body = '', $link = '', $leadId = null) {
    $db = Database::getInstance();

    // Auto-create table if missing (self-healing — runs once)
    static $tableChecked = false;
    if (!$tableChecked) {
        try {
            $db->query("SELECT 1 FROM notifications LIMIT 1");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false
                || strpos($e->getMessage(), 'not found') !== false) {
                $db->getConnection()->exec("
                    CREATE TABLE IF NOT EXISTS `notifications` (
                        `notification_id` int(11) NOT NULL AUTO_INCREMENT,
                        `user_id`         int(11) NOT NULL,
                        `type`            varchar(50) NOT NULL DEFAULT 'info',
                        `title`           varchar(255) NOT NULL,
                        `body`            text DEFAULT NULL,
                        `link`            varchar(500) DEFAULT NULL,
                        `lead_id`         int(11) DEFAULT NULL,
                        `is_read`         tinyint(1) NOT NULL DEFAULT 0,
                        `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`notification_id`),
                        KEY `idx_user_read` (`user_id`, `is_read`),
                        KEY `idx_user_created` (`user_id`, `created_at`),
                        KEY `idx_lead` (`lead_id`),
                        CONSTRAINT `notif_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
                        CONSTRAINT `notif_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                error_log("Notifications: auto-created notifications table");
            }
        }
        $tableChecked = true;
    }

    $crmNotifId = $db->insert('notifications', [
        'user_id'    => $userId,
        'type'       => $type,
        'title'      => $title,
        'body'       => $body,
        'link'       => $link,
        'lead_id'    => $leadId,
        'is_read'    => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    // Unified bell: mirror this CRM notification into the VGold Workflow
    // notification store (keyed by the VGold user id) so it shows in the single
    // app-wide bell AND triggers web-push — exactly like a Workflow notification.
    // Guarded so the standalone CRM (no VGold bridge) is unaffected. Best-effort:
    // a failure here must never break the CRM action that raised the notice.
    if (defined('VGOLD_BRIDGE_LOADED') && class_exists('DB')) {
        if (!class_exists('Push') && file_exists(__DIR__ . '/../../app/lib/Push.php')) {
            require_once __DIR__ . '/../../app/lib/Push.php';
        }
        try {
            $vg = DB::fetch(
                "SELECT u.id,
                        (SELECT wm.workspace_id FROM workspace_members wm
                         WHERE wm.user_id = u.id ORDER BY wm.joined_at ASC LIMIT 1) AS ws
                 FROM users u WHERE u.crm_user_id = ? LIMIT 1",
                [(int)$userId]
            );
            if ($vg && !empty($vg['id']) && !empty($vg['ws'])) {
                DB::insert('notifications', [
                    'workspace_id' => (int)$vg['ws'],
                    'user_id'      => (int)$vg['id'],
                    'type'         => $type,
                    'title'        => $title,
                    'body'         => $body,
                    'link_type'    => $leadId ? 'crm_lead' : 'crm',
                    'link_id'      => $leadId ? (int)$leadId : null,
                ]);
                if (class_exists('Push')) {
                    Push::toUser((int)$vg['id'], $title, $body,
                        $leadId ? '/crm/pages/lead-detail.php?id=' . (int)$leadId : '/');
                }
            }
        } catch (\Throwable $e) {
            error_log('Unified notification mirror failed: ' . $e->getMessage());
        }
    }

    return $crmNotifId;
}
