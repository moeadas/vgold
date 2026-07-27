<?php
/**
 * Victory Genomics CRM — time-based automation scheduler.
 *
 * The event triggers (lead_created, lead_status_changed, …) fire inline from the
 * request that caused them. Time-based triggers have no such request — nothing
 * happens when a lead simply *stops* being touched — so they need a heartbeat.
 *
 * Time-based trigger types:
 *   lead_idle               no interaction for N days
 *   no_contact_after_created lead created N days ago and never contacted
 *   lead_stale_in_status    lead sitting in the same status for N days
 *   followup_overdue        an interaction's next_action_date has passed and
 *                           nothing has been logged on the lead since
 *
 * IDEMPOTENCE IS THE WHOLE GAME. The heartbeat may run every few minutes, but a
 * lead that has been idle for 10 days matches "idle for 5 days" on every single
 * run. crm_automation_fires records (rule, lead, cycle) so each rule fires at
 * most once per lead per cycle — by default one calendar day.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/automation-engine.php';

/** Trigger types handled here rather than inline from a request. */
function automationTimeTriggers(): array {
    return ['lead_idle', 'no_contact_after_created', 'lead_stale_in_status', 'followup_overdue'];
}

/** Dedupe + run-state tables, created on demand (no migration step on deploy). */
function ensureAutomationScheduleTables($pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_automation_fires` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `rule_id` INT NOT NULL,
            `lead_id` INT NOT NULL,
            `trigger_type` VARCHAR(50) NOT NULL,
            `cycle_key` VARCHAR(32) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_fire` (`rule_id`, `lead_id`, `cycle_key`),
            KEY `fire_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Exception $e) {
        error_log('ensureAutomationScheduleTables: ' . $e->getMessage());
    }
}

/** Read a scheduler setting from the CRM settings table. */
function automationSetting($pdo, string $key, $default = null) {
    try {
        $v = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $v->execute([$key]);
        $out = $v->fetchColumn();
        return ($out === false || $out === null || $out === '') ? $default : $out;
    } catch (\Exception $e) { return $default; }
}

function automationSettingSet($pdo, string $key, $value): void {
    try {
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute([$key, (string)$value]);
    } catch (\Exception $e) {
        error_log('automationSettingSet: ' . $e->getMessage());
    }
}

/**
 * Should the heartbeat run now? Uses a DB-held timestamp so every web worker
 * agrees, and claims the slot atomically so two concurrent requests cannot both
 * start a run.
 *
 * @param int $intervalMinutes minimum gap between runs
 */
function automationScheduleDue($pdo, int $intervalMinutes = 15): bool {
    $last = (int)automationSetting($pdo, 'automation_last_run_ts', 0);
    $now = time();
    if ($last && ($now - $last) < ($intervalMinutes * 60)) return false;

    // Atomic claim: only the worker that actually moves the timestamp proceeds.
    try {
        $st = $pdo->prepare(
            "UPDATE settings SET setting_value = ?
              WHERE setting_key = 'automation_last_run_ts'
                AND CAST(setting_value AS UNSIGNED) = ?"
        );
        $st->execute([(string)$now, (string)$last]);
        if ($st->rowCount() > 0) return true;

        // No row yet — insert it; whoever wins the unique key runs.
        if (!$last) {
            $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('automation_last_run_ts', ?)");
            $ins->execute([(string)$now]);
            return $ins->rowCount() > 0;
        }
    } catch (\Exception $e) {
        error_log('automationScheduleDue: ' . $e->getMessage());
    }
    return false;
}

/**
 * Find the leads a time-based rule currently matches.
 *
 * Every query is scoped so a rule can only ever act on leads that are still
 * "live" — the default excludes Won/Lost/Not Interested, because chasing a lost
 * lead forever is the classic way these systems become noise.
 */
function automationFindMatches($pdo, array $rule): array {
    $cfg = !empty($rule['trigger_config']) ? json_decode($rule['trigger_config'], true) : [];
    $cfg = is_array($cfg) ? $cfg : [];
    $days = max(1, (int)($cfg['days'] ?? 7));
    $limit = min(500, max(1, (int)($cfg['max_per_run'] ?? 200)));

    $excluded = "'Won','Lost','Not Interested','Customer'";
    $statusClause = '';
    $params = [];
    if (!empty($cfg['lead_status'])) {
        $statusClause = ' AND l.lead_status = ?';
        $params[] = $cfg['lead_status'];
    } else {
        $statusClause = " AND l.lead_status NOT IN ($excluded)";
    }

    switch ($rule['trigger_type']) {

        case 'lead_idle':
            // No interaction of any kind for N days (and none ever counts too).
            $sql = "SELECT l.* FROM leads l
                     WHERE 1=1 $statusClause
                       AND COALESCE(
                             (SELECT MAX(i.interaction_date) FROM interactions i WHERE i.lead_id = l.lead_id),
                             l.created_at
                           ) < DATE_SUB(NOW(), INTERVAL ? DAY)
                     ORDER BY l.lead_id ASC LIMIT $limit";
            $params[] = $days;
            break;

        case 'no_contact_after_created':
            $sql = "SELECT l.* FROM leads l
                     WHERE 1=1 $statusClause
                       AND l.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                       AND NOT EXISTS (SELECT 1 FROM interactions i WHERE i.lead_id = l.lead_id)
                     ORDER BY l.lead_id ASC LIMIT $limit";
            $params[] = $days;
            break;

        case 'lead_stale_in_status':
            $sql = "SELECT l.* FROM leads l
                     WHERE 1=1 $statusClause
                       AND l.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                     ORDER BY l.lead_id ASC LIMIT $limit";
            $params[] = $days;
            break;

        case 'followup_overdue':
            // A follow-up was promised (next_action_date) and nothing has been
            // logged on the lead since that date, so it was never done.
            $grace = max(0, (int)($cfg['grace_days'] ?? 0));
            $sql = "SELECT l.* FROM leads l
                     WHERE 1=1 $statusClause
                       AND EXISTS (
                             SELECT 1 FROM interactions i
                              WHERE i.lead_id = l.lead_id
                                AND i.next_action_date IS NOT NULL
                                AND i.next_action_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                                AND NOT EXISTS (
                                      SELECT 1 FROM interactions i2
                                       WHERE i2.lead_id = l.lead_id
                                         AND i2.interaction_date > i.next_action_date)
                           )
                     ORDER BY l.lead_id ASC LIMIT $limit";
            $params[] = $grace;
            break;

        default:
            return [];
    }

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        error_log('automationFindMatches[' . $rule['trigger_type'] . ']: ' . $e->getMessage());
        return [];
    }
}

/**
 * Run every active time-based rule once.
 *
 * @param array $opts  dry => bool (find matches but fire nothing)
 *                     rule_id => int (run a single rule, for testing)
 * @return array summary safe to echo as JSON
 */
function runAutomationSchedule($opts = []): array {
    $started = microtime(true);
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    ensureAutomationScheduleTables($pdo);

    $dry = !empty($opts['dry']);
    // One fire per rule+lead per calendar day, unless the rule asks for "once ever".
    $cycleKey = date('Y-m-d');

    $types = automationTimeTriggers();
    $in = implode(',', array_fill(0, count($types), '?'));
    $params = $types;
    $where = "is_active = 1 AND trigger_type IN ($in)";
    if (!empty($opts['rule_id'])) { $where .= ' AND rule_id = ?'; $params[] = (int)$opts['rule_id']; }

    try {
        $st = $pdo->prepare("SELECT * FROM automation_rules WHERE $where ORDER BY rule_id ASC");
        $st->execute($params);
        $rules = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        return ['ok' => false, 'error' => 'Could not load rules: ' . $e->getMessage()];
    }

    $summary = ['ok' => true, 'dry_run' => $dry, 'cycle' => $cycleKey, 'rules' => [], 'fired' => 0, 'matched' => 0, 'skipped' => 0];

    foreach ($rules as $rule) {
        $cfg = !empty($rule['trigger_config']) ? json_decode($rule['trigger_config'], true) : [];
        $onceEver = !empty($cfg['once_per_lead']);
        $key = $onceEver ? 'once' : $cycleKey;

        $matches = automationFindMatches($pdo, $rule);
        $fired = 0; $skipped = 0;

        foreach ($matches as $lead) {
            $leadId = (int)$lead['lead_id'];

            // Claim the fire BEFORE running the action. If the insert loses the
            // unique key, another run already handled this lead — skip silently.
            if (!$dry) {
                try {
                    $ins = $pdo->prepare("INSERT IGNORE INTO crm_automation_fires (rule_id, lead_id, trigger_type, cycle_key) VALUES (?, ?, ?, ?)");
                    $ins->execute([(int)$rule['rule_id'], $leadId, $rule['trigger_type'], $key]);
                    if ($ins->rowCount() === 0) { $skipped++; continue; }
                } catch (\Exception $e) { $skipped++; continue; }
            } else {
                $seen = $pdo->prepare("SELECT 1 FROM crm_automation_fires WHERE rule_id = ? AND lead_id = ? AND cycle_key = ?");
                $seen->execute([(int)$rule['rule_id'], $leadId, $key]);
                if ($seen->fetchColumn()) { $skipped++; continue; }
            }

            if ($dry) { $fired++; continue; }

            // Reuse the normal engine so conditions, logging and the whole
            // action vocabulary behave identically to an event-driven rule.
            fireAutomationTrigger($rule['trigger_type'], [
                'lead_id' => $leadId,
                'lead' => $lead,
                'scheduled' => true,
                'current_user' => ['user_id' => (int)($rule['created_by'] ?: 1)],
            ]);
            $fired++;
        }

        $summary['rules'][] = [
            'rule_id' => (int)$rule['rule_id'],
            'name' => $rule['name'],
            'trigger' => $rule['trigger_type'],
            'matched' => count($matches),
            'fired' => $fired,
            'skipped_already_fired' => $skipped,
        ];
        $summary['matched'] += count($matches);
        $summary['fired'] += $fired;
        $summary['skipped'] += $skipped;
    }

    $summary['rule_count'] = count($rules);
    $summary['ms'] = (int)round((microtime(true) - $started) * 1000);

    if (!$dry) {
        automationSettingSet($pdo, 'automation_last_run_ts', time());
        automationSettingSet($pdo, 'automation_last_run_summary', json_encode([
            'at' => date('Y-m-d H:i:s'),
            'rules' => count($rules),
            'matched' => $summary['matched'],
            'fired' => $summary['fired'],
            'ms' => $summary['ms'],
        ]));
        // Keep the dedupe table from growing without bound.
        try { $pdo->exec("DELETE FROM crm_automation_fires WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND cycle_key <> 'once'"); }
        catch (\Exception $e) {}
    }

    return $summary;
}

/**
 * Heartbeat used by ordinary app traffic.
 *
 * Runs AFTER the response has been sent so no user ever waits for it, and only
 * when the interval has elapsed. This means time-based rules work with no
 * server cron configured at all — a real cron just makes them punctual on days
 * when nobody opens the app.
 */
function automationHeartbeat(int $intervalMinutes = 15): void {
    try {
        $pdo = Database::getInstance()->getConnection();
        if (!automationScheduleDue($pdo, $intervalMinutes)) return;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { runAutomationSchedule(); }
            catch (\Throwable $e) { error_log('automationHeartbeat run: ' . $e->getMessage()); }
        });
    } catch (\Throwable $e) {
        error_log('automationHeartbeat: ' . $e->getMessage());
    }
}
