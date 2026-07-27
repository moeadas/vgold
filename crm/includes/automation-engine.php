<?php
/**
 * Victory Genomics CRM — Automation Engine
 *
 * Core function: fireAutomationTrigger()
 * Called from leads API, webhook, proposals when events happen.
 * Loads active rules matching the trigger, evaluates conditions, executes actions.
 *
 * Trigger types:
 *   lead_created, lead_status_changed, lead_assigned, lead_reassigned,
 *   lead_source_match, proposal_status_changed, lead_converted,
 *   interaction_logged, call_completed, whatsapp_received
 *
 * Condition fields (on leads):
 *   country, region, lead_source, lead_type, priority, lead_status, assigned_to
 *
 * Action types:
 *   assign_user, assign_round_robin, send_email_template,
 *   send_whatsapp_template, send_whatsapp_message, send_notification_email,
 *   notify_in_app, change_lead_status, change_priority, set_field,
 *   log_interaction, create_task, add_to_email_list
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Fire an automation trigger.
 *
 * @param string $triggerType   e.g. 'lead_created'
 * @param array  $context       Contextual data:
 *   - lead_id        (int)        always present for lead triggers
 *   - lead           (array|null) full lead row (saves re-query)
 *   - proposal_id    (int|null)   for proposal triggers
 *   - old_status     (string)     for status-changed triggers
 *   - new_status     (string)     for status-changed triggers
 *   - old_assigned   (int|null)   for assignment triggers
 *   - new_assigned   (int|null)   for assignment triggers
 *   - current_user   (array|null) user performing the action
 */
function fireAutomationTrigger(string $triggerType, array $context = []): void
{
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();

        // Load active rules for this trigger
        $stmt = $pdo->prepare("
            SELECT * FROM automation_rules
            WHERE is_active = 1 AND trigger_type = ?
            ORDER BY rule_id ASC
        ");
        $stmt->execute([$triggerType]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rules)) return;

        // Ensure we have the lead data
        $lead = $context['lead'] ?? null;
        if (!$lead && !empty($context['lead_id'])) {
            $lead = $db->findOne('leads', ['lead_id' => $context['lead_id']]);
        }

        foreach ($rules as $rule) {
            $startMs = intval(microtime(true) * 1000);

            try {
                // Check trigger-specific config
                if (!matchesTriggerConfig($rule, $triggerType, $context)) {
                    continue; // trigger config doesn't match, skip silently
                }

                // Evaluate conditions against lead data
                if (!evaluateConditions($rule, $lead, $context)) {
                    logAutomation($pdo, $rule, $triggerType, $context, 'skipped',
                        'Conditions not met', null, $startMs);
                    continue;
                }

                // Execute the action
                $result = executeAction($rule, $lead, $context, $db, $pdo);

                // Increment run count
                $pdo->prepare("UPDATE automation_rules SET run_count = run_count + 1 WHERE rule_id = ?")
                    ->execute([$rule['rule_id']]);

                logAutomation($pdo, $rule, $triggerType, $context, 'success',
                    $result, null, $startMs);

            } catch (\Exception $e) {
                error_log("Automation rule #{$rule['rule_id']} error: " . $e->getMessage());
                logAutomation($pdo, $rule, $triggerType, $context, 'failed',
                    null, $e->getMessage(), $startMs);
            }
        }
    } catch (\Exception $e) {
        error_log("Automation engine error [{$triggerType}]: " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────
//  TRIGGER CONFIG MATCHING
// ─────────────────────────────────────────────────────────

function matchesTriggerConfig(array $rule, string $triggerType, array $ctx): bool
{
    $config = !empty($rule['trigger_config']) ? json_decode($rule['trigger_config'], true) : [];
    if (empty($config)) return true; // no extra filter

    switch ($triggerType) {
        case 'lead_status_changed':
            // Optional from_status / to_status filters
            if (!empty($config['from_status']) && ($ctx['old_status'] ?? '') !== $config['from_status']) {
                return false;
            }
            if (!empty($config['to_status']) && ($ctx['new_status'] ?? '') !== $config['to_status']) {
                return false;
            }
            return true;

        case 'lead_source_match':
            // source filter
            if (!empty($config['lead_source'])) {
                $lead = $ctx['lead'] ?? null;
                if (!$lead && !empty($ctx['lead_id'])) {
                    $lead = Database::getInstance()->findOne('leads', ['lead_id' => $ctx['lead_id']]);
                }
                if (!$lead || ($lead['lead_source'] ?? '') !== $config['lead_source']) {
                    return false;
                }
            }
            return true;

        case 'proposal_status_changed':
            if (!empty($config['from_status']) && ($ctx['old_status'] ?? '') !== $config['from_status']) {
                return false;
            }
            if (!empty($config['to_status']) && ($ctx['new_status'] ?? '') !== $config['to_status']) {
                return false;
            }
            return true;

        case 'interaction_logged':
            // Optional filters on the kind of interaction and its outcome.
            if (!empty($config['interaction_type']) && ($ctx['interaction_type'] ?? '') !== $config['interaction_type']) {
                return false;
            }
            if (!empty($config['outcome']) && ($ctx['outcome'] ?? '') !== $config['outcome']) {
                return false;
            }
            return true;

        case 'call_completed':
            if (!empty($config['outcome']) && ($ctx['outcome'] ?? '') !== $config['outcome']) {
                return false;
            }
            return true;

        case 'whatsapp_received':
            // Optional keyword filter on the inbound message body.
            if (!empty($config['keyword'])) {
                $body = (string)($ctx['message_body'] ?? '');
                if (stripos($body, $config['keyword']) === false) return false;
            }
            return true;

        default:
            return true;
    }
}

// ─────────────────────────────────────────────────────────
//  CONDITION EVALUATION
// ─────────────────────────────────────────────────────────

function evaluateConditions(array $rule, ?array $lead, array $ctx): bool
{
    $conditions = !empty($rule['conditions']) ? json_decode($rule['conditions'], true) : [];
    if (empty($conditions) || !is_array($conditions)) return true; // no conditions = always match

    foreach ($conditions as $cond) {
        $field    = $cond['field']    ?? '';
        $operator = $cond['operator'] ?? 'equals';
        $value    = $cond['value']    ?? '';

        // Get the actual value from lead or context
        $actual = getFieldValue($field, $lead, $ctx);

        if (!matchCondition($actual, $operator, $value)) {
            return false; // AND logic: all must pass
        }
    }
    return true;
}

function getFieldValue(string $field, ?array $lead, array $ctx)
{
    // Special handling for assigned_to — might be user_id or full_name
    if ($field === 'assigned_to') {
        return $lead['assigned_to'] ?? $ctx['new_assigned'] ?? null;
    }

    // Check lead data first
    if ($lead && array_key_exists($field, $lead)) {
        return $lead[$field];
    }

    // Check context
    if (array_key_exists($field, $ctx)) {
        return $ctx[$field];
    }

    return null;
}

function matchCondition($actual, string $operator, $expected): bool
{
    $actual   = (string)($actual ?? '');
    $expected = (string)$expected;

    switch ($operator) {
        case 'equals':
            return strcasecmp($actual, $expected) === 0;
        case 'not_equals':
            return strcasecmp($actual, $expected) !== 0;
        case 'contains':
            return stripos($actual, $expected) !== false;
        case 'not_contains':
            return stripos($actual, $expected) === false;
        case 'starts_with':
            return stripos($actual, $expected) === 0;
        case 'ends_with':
            return substr(strtolower($actual), -strlen($expected)) === strtolower($expected);
        case 'is_empty':
            return $actual === '';
        case 'is_not_empty':
            return $actual !== '';
        case 'is':   // alias for equals (used for assigned_to user_id)
            return $actual === $expected;
        case 'is_not':
            return $actual !== $expected;
        default:
            return strcasecmp($actual, $expected) === 0;
    }
}

// ─────────────────────────────────────────────────────────
//  ACTION EXECUTION
// ─────────────────────────────────────────────────────────

function executeAction(array $rule, ?array $lead, array $ctx, $db, $pdo): string
{
    $config = json_decode($rule['action_config'], true) ?: [];
    $leadId = $lead['lead_id'] ?? $ctx['lead_id'] ?? null;

    switch ($rule['action_type']) {

        // ── Assign to user ──────────────────────────────
        case 'assign_user':
            $userId = intval($config['user_id'] ?? 0);
            if (!$userId) throw new \Exception('assign_user: no user_id in config');

            $user = $db->findOne('users', ['user_id' => $userId]);
            if (!$user) throw new \Exception("assign_user: user #{$userId} not found");

            if ($leadId) {
                $pdo->prepare("UPDATE leads SET assigned_to = ? WHERE lead_id = ?")
                    ->execute([$userId, $leadId]);

                // Notify via WhatsApp
                try {
                    require_once __DIR__ . '/twilio.php';
                    $leadName = $lead['contact_person'] ?? $lead['company_name'] ?? 'Lead';
                    TwilioHelper::notifyLeadAssignment($userId, $leadName, $leadId, 'Automation');
                } catch (\Exception $e) {
                    error_log("Automation WA notify failed: " . $e->getMessage());
                }
            }
            return "Assigned lead #{$leadId} to {$user['full_name']}";

        // ── Send email template ─────────────────────────
        case 'send_email_template':
            $templateId = intval($config['template_id'] ?? 0);
            if (!$templateId) throw new \Exception('send_email_template: no template_id');

            $template = $db->findOne('email_templates', ['template_id' => $templateId]);
            if (!$template) throw new \Exception("send_email_template: template #{$templateId} not found");

            $to = $lead['email'] ?? null;
            if (empty($to)) return "Skipped: lead #{$leadId} has no email address";

            // Process template variables in subject and HTML
            $subject = processEmailVars($template['subject'] ?? $template['name'] ?? 'Victory Genomics', $lead);
            $html    = processEmailVars($template['content_html'] ?? '', $lead);

            require_once __DIR__ . '/../api/email.php';
            $sent = sendEmailViaSMTP($to, $subject, $html);
            if (!$sent) throw new \Exception("SMTP send failed to {$to}");

            return "Sent email template \"{$template['name']}\" to {$to}";

        // ── Send WhatsApp template ──────────────────────
        case 'send_whatsapp_template':
            require_once __DIR__ . '/twilio.php';

            $toNumber = $lead['mobile'] ?? $lead['phone'] ?? null;
            if (empty($toNumber)) return "Skipped: lead #{$leadId} has no phone/mobile";

            $twilio     = TwilioHelper::getInstance();
            $normalized = TwilioHelper::normalizePhone($toNumber);
            $contactName = $lead['contact_person'] ?? null;
            $currentUser = $ctx['current_user'] ?? null;
            $userName    = $currentUser['full_name'] ?? 'Victory Genomics';

            $contentSid  = $config['content_sid'] ?? null;
            $templateId  = intval($config['template_id'] ?? 0);
            $tplName     = '';

            if ($contentSid) {
                // ── Twilio Content API template ──
                // Build variables from lead data for {{1}}, {{2}} etc.
                $contentVars = [];
                if ($lead) {
                    $contentVars['1'] = $lead['contact_person'] ?? 'there';
                    $contentVars['2'] = $lead['company_name'] ?? '';
                    $contentVars['3'] = $userName;
                }
                $message = $twilio->sendWhatsApp($normalized, '', null, $contentSid, $contentVars, $contactName);
                $tplName = $contentSid;
                $body    = "(Twilio content template: {$contentSid})";
            } elseif ($templateId) {
                // ── Local DB template (whatsapp_templates table) ──
                $waTemplate = $db->findOne('whatsapp_templates', ['template_id' => $templateId]);
                if (!$waTemplate) throw new \Exception("send_whatsapp_template: WA template #{$templateId} not found");

                $body = TwilioHelper::processTemplate($waTemplate['body'], $lead ?: [], $userName);
                $message = $twilio->sendWhatsApp($normalized, $body, null, null, null, $contactName);
                $tplName = $waTemplate['name'];
            } else {
                throw new \Exception('send_whatsapp_template: no template_id or content_sid in config');
            }

            // Log the message
            TwilioHelper::logMessage([
                'lead_id'     => $leadId,
                'user_id'     => $currentUser['user_id'] ?? 1,
                'message_sid' => $message->sid ?? '',
                'direction'   => 'Outbound',
                'from_number' => $twilio->getWhatsappFromNumber() ?: $twilio->getPhoneNumber(),
                'to_number'   => $normalized,
                'body'        => $body,
                'status'      => $message->status ?? 'Sent',
                'template_id' => $templateId ?: null,
                'sent_at'     => date('Y-m-d H:i:s'),
            ]);

            return "Sent WhatsApp template \"{$tplName}\" to {$normalized}";

        // ── Send notification email ─────────────────────
        case 'send_notification_email':
            $recipientType = $config['recipient'] ?? 'assigned_user'; // assigned_user, specific_email, creator
            $customSubject = $config['subject'] ?? 'CRM Automation Notification';
            $customBody    = $config['body'] ?? '';

            $to = null;
            if ($recipientType === 'assigned_user') {
                $assignedId = $lead['assigned_to'] ?? $ctx['new_assigned'] ?? null;
                if ($assignedId) {
                    $assignee = $db->findOne('users', ['user_id' => $assignedId]);
                    $to = $assignee['email'] ?? null;
                }
            } elseif ($recipientType === 'creator') {
                $creatorId = $lead['created_by'] ?? null;
                if ($creatorId) {
                    $creator = $db->findOne('users', ['user_id' => $creatorId]);
                    $to = $creator['email'] ?? null;
                }
            } elseif ($recipientType === 'specific_email') {
                $to = $config['email'] ?? null;
            }

            if (empty($to)) return "Skipped: no recipient email found ({$recipientType})";

            $leadName = $lead['contact_person'] ?? $lead['company_name'] ?? 'Lead #' . $leadId;
            $html = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>"
                  . "<h2 style='color:#0071e3;'>Automation Alert</h2>"
                  . "<p><strong>Rule:</strong> {$rule['name']}</p>"
                  . "<p><strong>Lead:</strong> {$leadName}</p>"
                  . ($customBody ? "<p>{$customBody}</p>" : "")
                  . "<p style='margin-top:20px;'><a href='" . APP_URL . CRM_BASE . "/pages/lead-detail.php?id={$leadId}' "
                  . "style='background:#0071e3;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;'>View Lead</a></p>"
                  . "<p style='color:#999;font-size:12px;margin-top:30px;'>This is an automated notification from Victory Genomics CRM.</p>"
                  . "</div>";

            require_once __DIR__ . '/../api/email.php';
            $sent = sendEmailViaSMTP($to, processEmailVars($customSubject, $lead), $html);
            if (!$sent) throw new \Exception("Notification email failed to {$to}");

            return "Sent notification email to {$to}";

        // ── Change lead status ──────────────────────────
        case 'change_lead_status':
            $newStatus = $config['status'] ?? null;
            if (!$newStatus) throw new \Exception('change_lead_status: no status in config');
            if (!$leadId) throw new \Exception('change_lead_status: no lead_id');

            $pdo->prepare("UPDATE leads SET lead_status = ? WHERE lead_id = ?")
                ->execute([$newStatus, $leadId]);

            return "Changed lead #{$leadId} status to \"{$newStatus}\"";

        // ── Change priority ─────────────────────────────
        case 'change_priority':
            $newPriority = $config['priority'] ?? null;
            if (!$newPriority) throw new \Exception('change_priority: no priority in config');
            if (!$leadId) throw new \Exception('change_priority: no lead_id');

            $pdo->prepare("UPDATE leads SET priority = ? WHERE lead_id = ?")
                ->execute([$newPriority, $leadId]);

            return "Changed lead #{$leadId} priority to \"{$newPriority}\"";

        // ── Log interaction note ────────────────────────
        case 'log_interaction':
            $note = $config['note'] ?? 'Automation triggered';
            if (!$leadId) throw new \Exception('log_interaction: no lead_id');

            $userId = $ctx['current_user']['user_id'] ?? 1; // fallback to admin

            $pdo->prepare("
                INSERT INTO interactions (lead_id, user_id, interaction_type, interaction_date, subject, notes, outcome)
                VALUES (?, ?, 'Note', NOW(), ?, ?, 'Neutral')
            ")->execute([
                $leadId,
                $userId,
                'Automation: ' . $rule['name'],
                processEmailVars($note, $lead),
            ]);

            return "Logged interaction note on lead #{$leadId}";

        // ── Assign round-robin across a pool of users ───
        case 'assign_round_robin':
            $pool = $config['user_ids'] ?? [];
            if (is_string($pool)) $pool = array_filter(array_map('trim', explode(',', $pool)));
            $pool = array_values(array_filter(array_map('intval', (array)$pool)));
            if (!$pool) throw new \Exception('assign_round_robin: no user_ids in config');
            if (!$leadId) throw new \Exception('assign_round_robin: no lead_id');

            // Rotate on the rule's own run_count so distribution stays even
            // without needing extra state.
            $idx = intval($rule['run_count'] ?? 0) % count($pool);
            $userId = $pool[$idx];

            $pdo->prepare("UPDATE leads SET assigned_to = ? WHERE lead_id = ?")->execute([$userId, $leadId]);
            try {
                require_once __DIR__ . '/twilio.php';
                $leadName = $lead['contact_person'] ?? $lead['company_name'] ?? 'Lead';
                TwilioHelper::notifyLeadAssignment($userId, $leadName, $leadId, 'Automation');
            } catch (\Exception $e) {
                error_log("Automation WA notify failed: " . $e->getMessage());
            }
            return "Round-robin assigned lead #{$leadId} to user #{$userId}";

        // ── Free-form WhatsApp (only valid inside the 24h window) ───
        case 'send_whatsapp_message':
            $body = trim((string)($config['body'] ?? ''));
            if ($body === '') throw new \Exception('send_whatsapp_message: no body in config');
            if (!$lead) throw new \Exception('send_whatsapp_message: no lead');

            require_once __DIR__ . '/twilio.php';
            $to = $lead['mobile'] ?: $lead['phone'];
            if (!TwilioHelper::isValidPhone($to)) {
                throw new \Exception('send_whatsapp_message: lead has no valid phone number');
            }
            $twilio = TwilioHelper::getInstance();
            $twilio->sendWhatsApp(TwilioHelper::normalizePhone($to), processEmailVars($body, $lead), null, null, null,
                                  $lead['contact_person'] ?? null);
            return "Sent WhatsApp message to {$to}";

        // ── In-app notification for a CRM user ──────────
        case 'notify_in_app':
            $userId = intval($config['user_id'] ?? 0);
            if (!$userId && $leadId && $lead) $userId = intval($lead['assigned_to'] ?? 0);
            if (!$userId) throw new \Exception('notify_in_app: no recipient');
            $title = processEmailVars((string)($config['title'] ?? ('Automation: ' . $rule['name'])), $lead);
            $text  = processEmailVars((string)($config['body'] ?? ''), $lead);

            require_once __DIR__ . '/notification-helper.php';
            createNotification($userId, 'automation', $title, $text,
                               $leadId ? ('/pages/lead-detail.php?id=' . $leadId) : null, $leadId);
            return "Notified user #{$userId} in app";

        // ── Set an arbitrary allowlisted lead field ─────
        case 'set_field':
            $field = (string)($config['field'] ?? '');
            $allowed = ['lead_type', 'lead_source', 'region', 'facility_type', 'priority', 'lead_status', 'notes'];
            if (!in_array($field, $allowed, true)) throw new \Exception("set_field: field '{$field}' is not allowed");
            if (!$leadId) throw new \Exception('set_field: no lead_id');
            $value = processEmailVars((string)($config['value'] ?? ''), $lead);
            $pdo->prepare("UPDATE leads SET `{$field}` = ? WHERE lead_id = ?")->execute([$value, $leadId]);
            return "Set {$field} = \"{$value}\" on lead #{$leadId}";

        // ── Create a Workflow task (the VGold side of the house) ───
        case 'create_task':
            $title = processEmailVars((string)($config['title'] ?? ''), $lead);
            if ($title === '') throw new \Exception('create_task: no title in config');
            $projectId = intval($config['project_id'] ?? 0);
            if (!$projectId) throw new \Exception('create_task: no project_id in config');
            $dueDays = intval($config['due_in_days'] ?? 0);
            $due = $dueDays > 0 ? date('Y-m-d', strtotime("+{$dueDays} days")) : null;

            // tasks/projects are VGold tables — NOT rewritten by the crm_ bridge.
            $pdo->prepare("INSERT INTO tasks (project_id, title, description, status, priority, deadline_date, crm_lead_id, source_module, created_at)
                           VALUES (?, ?, ?, 'in_progress', ?, ?, ?, 'crm.automation', NOW())")
                ->execute([
                    $projectId, $title,
                    processEmailVars((string)($config['description'] ?? ''), $lead),
                    $config['priority'] ?? 'medium',
                    $due, $leadId ?: null,
                ]);
            return "Created Workflow task \"{$title}\"";

        // ── Add the lead to an email list ───────────────
        case 'add_to_email_list':
            $listId = intval($config['list_id'] ?? 0);
            if (!$listId) throw new \Exception('add_to_email_list: no list_id in config');
            if (!$lead || empty($lead['email'])) throw new \Exception('add_to_email_list: lead has no email');
            $pdo->prepare("INSERT IGNORE INTO email_list_members (list_id, lead_id, email, name, subscribed_at)
                           VALUES (?, ?, ?, ?, NOW())")
                ->execute([$listId, $leadId, $lead['email'],
                           $lead['contact_person'] ?: ($lead['company_name'] ?: '')]);
            return "Added lead #{$leadId} to email list #{$listId}";

        default:
            throw new \Exception("Unknown action type: {$rule['action_type']}");
    }
}

// ─────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────

/**
 * Replace {{variable}} placeholders in email content / subjects.
 */
function processEmailVars(string $text, ?array $lead): string
{
    if (!$lead) return $text;

    $replacements = [
        '{{contact_name}}'  => $lead['contact_person'] ?? '',
        '{{company_name}}'  => $lead['company_name'] ?? '',
        '{{email}}'         => $lead['email'] ?? '',
        '{{phone}}'         => $lead['phone'] ?? '',
        '{{mobile}}'        => $lead['mobile'] ?? '',
        '{{country}}'       => $lead['country'] ?? '',
        '{{region}}'        => $lead['region'] ?? '',
        '{{lead_type}}'     => $lead['lead_type'] ?? '',
        '{{lead_status}}'   => $lead['lead_status'] ?? '',
        '{{lead_source}}'   => $lead['lead_source'] ?? '',
        '{{priority}}'      => $lead['priority'] ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $text);
}

/**
 * Log automation execution to automation_logs.
 */
function logAutomation($pdo, array $rule, string $triggerType, array $ctx,
                       string $status, ?string $actionTaken, ?string $error, int $startMs): void
{
    $elapsed = intval(microtime(true) * 1000) - $startMs;

    try {
        $pdo->prepare("
            INSERT INTO automation_logs
                (rule_id, rule_name, trigger_type, lead_id, proposal_id, status, action_taken, error_message, execution_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $rule['rule_id'],
            $rule['name'],
            $triggerType,
            $ctx['lead_id'] ?? null,
            $ctx['proposal_id'] ?? null,
            $status,
            $actionTaken ? mb_substr($actionTaken, 0, 500) : null,
            $error ? mb_substr($error, 0, 500) : null,
            $elapsed,
        ]);
    } catch (\Exception $e) {
        error_log("Failed to log automation: " . $e->getMessage());
    }
}
