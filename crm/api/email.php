<?php
/**
 * Victory Genomics CRM V2 — Email Marketing API
 * Handles: templates, lists, campaigns, sending, tracking, unsubscribe
 */
require_once __DIR__ . '/../includes/auth.php';

// Public endpoints (no auth needed)
$publicActions = ['track_open', 'track_click', 'unsubscribe', 'unsubscribe_confirm'];
$action = $_GET['action'] ?? '';

if (!in_array($action, $publicActions)) {
    startSecureSession();
    requireLogin();
    requireRole('Sales Manager');
}

$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ─── PUBLIC: Tracking pixel (1x1 transparent GIF) ───
    if ($action === 'track_open' && isset($_GET['token'])) {
        $token = $_GET['token'];
        $stmt = $db->prepare("UPDATE email_campaign_log SET status = 'Opened', opened_at = NOW() WHERE tracking_token = ? AND status IN ('Sent','Opened')");
        $stmt->execute([$token]);
        // Update campaign total_opened
        $stmt2 = $db->prepare("UPDATE email_campaigns c SET c.total_opened = (SELECT COUNT(*) FROM email_campaign_log WHERE campaign_id = c.campaign_id AND status IN ('Opened','Clicked')) WHERE c.campaign_id = (SELECT campaign_id FROM email_campaign_log WHERE tracking_token = ? LIMIT 1)");
        $stmt2->execute([$token]);
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    // ─── PUBLIC: Click tracking ───
    if ($action === 'track_click' && isset($_GET['token']) && isset($_GET['url'])) {
        $token = $_GET['token'];
        $url = $_GET['url'];
        $stmt = $db->prepare("UPDATE email_campaign_log SET status = 'Clicked', clicked_at = NOW() WHERE tracking_token = ? AND status IN ('Sent','Opened','Clicked')");
        $stmt->execute([$token]);
        $stmt2 = $db->prepare("UPDATE email_campaigns c SET c.total_clicked = (SELECT COUNT(*) FROM email_campaign_log WHERE campaign_id = c.campaign_id AND status = 'Clicked') WHERE c.campaign_id = (SELECT campaign_id FROM email_campaign_log WHERE tracking_token = ? LIMIT 1)");
        $stmt2->execute([$token]);
        header('Location: ' . $url);
        exit;
    }

    // ─── PUBLIC: Unsubscribe page ───
    if ($action === 'unsubscribe' && isset($_GET['token'])) {
        $token = $_GET['token'];
        $stmt = $db->prepare("SELECT ecl.*, ec.name as campaign_name FROM email_campaign_log ecl JOIN email_campaigns ec ON ecl.campaign_id = ec.campaign_id WHERE ecl.tracking_token = ?");
        $stmt->execute([$token]);
        $log = $stmt->fetch();
        include __DIR__ . '/../pages/email-unsubscribe.php';
        exit;
    }

    if ($action === 'unsubscribe_confirm' && $method === 'POST') {
        $token = $_POST['token'] ?? '';
        $stmt = $db->prepare("SELECT lead_id, email FROM email_campaign_log WHERE tracking_token = ?");
        $stmt->execute([$token]);
        $log = $stmt->fetch();
        if ($log) {
            $db->prepare("UPDATE email_list_members SET status = 'Unsubscribed', unsubscribed_at = NOW() WHERE email = ?")->execute([$log['email']]);
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'You have been unsubscribed.']);
        exit;
    }

    // ─── AUTHENTICATED ENDPOINTS ───
    $currentUser = getCurrentUser();

    // ─── TEMPLATES ───
    if ($action === 'templates_list') {
        $stmt = $db->query("SELECT t.*, u.full_name as creator FROM email_templates t LEFT JOIN users u ON t.created_by = u.user_id ORDER BY t.updated_at DESC");
        jsonSuccess('Templates loaded', $stmt->fetchAll());
    }

    if ($action === 'template_get' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $template = $stmt->fetch();
        if (!$template) jsonError('Template not found', 404);
        jsonSuccess('Template loaded', $template);
    }

    if ($action === 'template_save' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        $name = sanitizeInput($input['name'] ?? '');
        $subject = sanitizeInput($input['subject'] ?? '');
        $contentJson = $input['content_json'] ?? '[]';
        $contentHtml = $input['content_html'] ?? '';
        $category = $input['category'] ?? 'Custom';
        $id = (int)($input['template_id'] ?? 0);

        if (empty($name)) jsonError('Template name is required');

        if ($id > 0) {
            $db->prepare("UPDATE email_templates SET name=?, subject=?, content_json=?, content_html=?, category=?, updated_at=NOW() WHERE template_id=?")
               ->execute([$name, $subject, $contentJson, $contentHtml, $category, $id]);
            logActivity($currentUser['user_id'], 'Update Template', 'Campaign', $id, "Updated email template: $name");
        } else {
            $stmt = $db->prepare("INSERT INTO email_templates (name, subject, content_json, content_html, category, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$name, $subject, $contentJson, $contentHtml, $category, $currentUser['user_id']]);
            $id = $db->lastInsertId();
            logActivity($currentUser['user_id'], 'Create Template', 'Campaign', $id, "Created email template: $name");
        }
        jsonSuccess('Template saved', ['template_id' => $id]);
    }

    if ($action === 'template_delete' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['template_id'] ?? 0);
        if ($id <= 0) jsonError('Invalid template ID');
        $db->prepare("DELETE FROM email_templates WHERE template_id = ?")->execute([$id]);
        logActivity($currentUser['user_id'], 'Delete Template', 'Campaign', $id, "Deleted email template #$id");
        jsonSuccess('Template deleted');
    }

    // ─── LISTS ───
    if ($action === 'lists_list') {
        $stmt = $db->query("SELECT el.*, u.full_name as creator, (SELECT COUNT(*) FROM email_list_members WHERE list_id = el.list_id AND status = 'Active') as active_members FROM email_lists el LEFT JOIN users u ON el.created_by = u.user_id ORDER BY el.updated_at DESC");
        jsonSuccess('Lists loaded', $stmt->fetchAll());
    }

    if ($action === 'list_get' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM email_lists WHERE list_id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $list = $stmt->fetch();
        if (!$list) jsonError('List not found', 404);

        // Get members
        $members = $db->prepare("SELECT elm.*, l.company_name, l.contact_person FROM email_list_members elm LEFT JOIN leads l ON elm.lead_id = l.lead_id WHERE elm.list_id = ? ORDER BY elm.subscribed_at DESC");
        $members->execute([(int)$_GET['id']]);
        $list['members'] = $members->fetchAll();
        jsonSuccess('List loaded', $list);
    }

    if ($action === 'list_save' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        $name = sanitizeInput($input['name'] ?? '');
        $description = sanitizeInput($input['description'] ?? '');
        $filterCriteria = $input['filter_criteria'] ?? '';
        $isDynamic = (int)($input['is_dynamic'] ?? 0);
        $id = (int)($input['list_id'] ?? 0);

        if (empty($name)) jsonError('List name is required');

        if ($id > 0) {
            $db->prepare("UPDATE email_lists SET name=?, description=?, filter_criteria=?, is_dynamic=?, updated_at=NOW() WHERE list_id=?")
               ->execute([$name, $description, $filterCriteria, $isDynamic, $id]);
            logActivity($currentUser['user_id'], 'Update List', 'Campaign', $id, "Updated email list: $name");
        } else {
            $stmt = $db->prepare("INSERT INTO email_lists (name, description, filter_criteria, is_dynamic, created_by) VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $description, $filterCriteria, $isDynamic, $currentUser['user_id']]);
            $id = $db->lastInsertId();
            logActivity($currentUser['user_id'], 'Create List', 'Campaign', $id, "Created email list: $name");
        }
        jsonSuccess('List saved', ['list_id' => $id]);
    }

    if ($action === 'list_delete' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['list_id'] ?? 0);
        if ($id <= 0) jsonError('Invalid list ID');
        $db->prepare("DELETE FROM email_lists WHERE list_id = ?")->execute([$id]);
        logActivity($currentUser['user_id'], 'Delete List', 'Campaign', $id, "Deleted email list #$id");
        jsonSuccess('List deleted');
    }

    if ($action === 'list_populate' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $listId = (int)($input['list_id'] ?? 0);
        $filters = $input['filters'] ?? [];

        if ($listId <= 0) jsonError('Invalid list ID');

        // Build query from filters
        $where = ["l.email IS NOT NULL", "l.email != ''"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "l.lead_status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['country'])) {
            $where[] = "l.country = ?";
            $params[] = $filters['country'];
        }
        if (!empty($filters['lead_type'])) {
            $where[] = "l.lead_type = ?";
            $params[] = $filters['lead_type'];
        }
        if (!empty($filters['priority'])) {
            $where[] = "l.priority = ?";
            $params[] = $filters['priority'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = "l.assigned_to = ?";
            $params[] = (int)$filters['assigned_to'];
        }
        if (!empty($filters['country'])) {
            $where[] = "l.country LIKE ?";
            $params[] = '%' . $filters['country'] . '%';
        }

        $sql = "SELECT l.lead_id, l.email FROM leads l WHERE " . implode(' AND ', $where);
        $leads = $db->prepare($sql);
        $leads->execute($params);
        $rows = $leads->fetchAll();

        // Check for unsubscribed emails
        $unsubscribed = $db->query("SELECT DISTINCT email FROM email_list_members WHERE status = 'Unsubscribed'")->fetchAll(PDO::FETCH_COLUMN);

        $added = 0;
        $skipped = 0;
        $insertStmt = $db->prepare("INSERT IGNORE INTO email_list_members (list_id, lead_id, email, status) VALUES (?, ?, ?, 'Active')");
        foreach ($rows as $row) {
            if (in_array($row['email'], $unsubscribed)) {
                $skipped++;
                continue;
            }
            $insertStmt->execute([$listId, $row['lead_id'], $row['email']]);
            $added += $insertStmt->rowCount();
        }

        // Update member count
        $count = $db->prepare("SELECT COUNT(*) FROM email_list_members WHERE list_id = ? AND status = 'Active'");
        $count->execute([$listId]);
        $memberCount = $count->fetchColumn();
        $db->prepare("UPDATE email_lists SET member_count = ? WHERE list_id = ?")->execute([$memberCount, $listId]);

        jsonSuccess("Added $added members, skipped $skipped (unsubscribed or duplicate)", ['added' => $added, 'skipped' => $skipped, 'total' => $memberCount]);
    }

    if ($action === 'list_remove_member' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $memberId = (int)($input['member_id'] ?? 0);
        if ($memberId <= 0) jsonError('Invalid member ID');
        $db->prepare("DELETE FROM email_list_members WHERE id = ?")->execute([$memberId]);
        jsonSuccess('Member removed');
    }

    // ─── CAMPAIGNS ───
    if ($action === 'campaigns_list') {
        $stmt = $db->query("SELECT c.*, u.full_name as creator, el.name as list_name FROM email_campaigns c LEFT JOIN users u ON c.created_by = u.user_id LEFT JOIN email_lists el ON c.list_id = el.list_id ORDER BY c.updated_at DESC");
        jsonSuccess('Campaigns loaded', $stmt->fetchAll());
    }

    if ($action === 'campaign_get' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT c.*, el.name as list_name FROM email_campaigns c LEFT JOIN email_lists el ON c.list_id = el.list_id WHERE c.campaign_id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $campaign = $stmt->fetch();
        if (!$campaign) jsonError('Campaign not found', 404);
        jsonSuccess('Campaign loaded', $campaign);
    }

    if ($action === 'campaign_save' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;

        $name = sanitizeInput($input['name'] ?? '');
        $subject = sanitizeInput($input['subject'] ?? '');
        $fromName = sanitizeInput($input['from_name'] ?? '');
        $fromEmail = sanitizeInput($input['from_email'] ?? '');
        $replyTo = sanitizeInput($input['reply_to'] ?? '');
        $templateId = !empty($input['template_id']) ? (int)$input['template_id'] : null;
        $listId = !empty($input['list_id']) ? (int)$input['list_id'] : null;
        $contentJson = $input['content_json'] ?? null;
        $contentHtml = $input['content_html'] ?? null;
        $scheduledAt = $input['scheduled_at'] ?? null;
        $id = (int)($input['campaign_id'] ?? 0);

        if (empty($name)) jsonError('Campaign name is required');
        if (empty($subject)) jsonError('Subject line is required');

        if ($id > 0) {
            $db->prepare("UPDATE email_campaigns SET name=?, subject=?, from_name=?, from_email=?, reply_to=?, template_id=?, list_id=?, content_json=?, content_html=?, scheduled_at=?, updated_at=NOW() WHERE campaign_id=? AND status IN ('Draft','Scheduled')")
               ->execute([$name, $subject, $fromName, $fromEmail, $replyTo, $templateId, $listId, $contentJson, $contentHtml, $scheduledAt, $id]);
            logActivity($currentUser['user_id'], 'Update Campaign', 'Campaign', $id, "Updated campaign: $name");
        } else {
            $stmt = $db->prepare("INSERT INTO email_campaigns (name, subject, from_name, from_email, reply_to, template_id, list_id, content_json, content_html, scheduled_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$name, $subject, $fromName, $fromEmail, $replyTo, $templateId, $listId, $contentJson, $contentHtml, $scheduledAt, $currentUser['user_id']]);
            $id = $db->lastInsertId();
            logActivity($currentUser['user_id'], 'Create Campaign', 'Campaign', $id, "Created campaign: $name");
        }
        jsonSuccess('Campaign saved', ['campaign_id' => $id]);
    }

    if ($action === 'campaign_delete' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['campaign_id'] ?? 0);
        if ($id <= 0) jsonError('Invalid campaign ID');
        $db->prepare("DELETE FROM email_campaigns WHERE campaign_id = ? AND status = 'Draft'")->execute([$id]);
        logActivity($currentUser['user_id'], 'Delete Campaign', 'Campaign', $id, "Deleted campaign #$id");
        jsonSuccess('Campaign deleted');
    }

    if ($action === 'campaign_duplicate' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['campaign_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE campaign_id = ?");
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) jsonError('Campaign not found', 404);

        $newId = $db->insert('email_campaigns', [
            'name' => $c['name'] . ' (Copy)',
            'subject' => $c['subject'],
            'from_name' => $c['from_name'],
            'from_email' => $c['from_email'],
            'reply_to' => $c['reply_to'],
            'template_id' => $c['template_id'],
            'list_id' => $c['list_id'],
            'content_json' => $c['content_json'],
            'content_html' => $c['content_html'],
            'status' => 'Draft',
            'created_by' => $currentUser['user_id']
        ]);
        logActivity($currentUser['user_id'], 'Duplicate Campaign', 'Campaign', $newId, "Duplicated campaign: {$c['name']}");
        jsonSuccess('Campaign duplicated', ['campaign_id' => $newId]);
    }

    // ─── SEND TEST EMAIL ───
    if ($action === 'send_test' && $method === 'POST') {
        requireCSRF();
        $input = json_decode(file_get_contents('php://input'), true);
        $testEmail = sanitizeInput($input['test_email'] ?? '');
        $subject = $input['subject'] ?? 'Test Email';
        $html = $input['content_html'] ?? '';

        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            jsonError('Valid email address is required');
        }

        $result = sendEmailViaSMTP($testEmail, $subject . ' [TEST]', $html);
        if ($result['success']) {
            jsonSuccess('Test email sent to ' . $testEmail);
        } else {
            jsonError('Failed to send: ' . $result['error']);
        }
    }

    // ─── SEND CAMPAIGN ───
    if ($action === 'campaign_send' && $method === 'POST') {
        requireCSRF();
        requireRole(['Admin', 'Sales Manager']);
        $input = json_decode(file_get_contents('php://input'), true);
        $campaignId = (int)($input['campaign_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE campaign_id = ? AND status IN ('Draft','Scheduled')");
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch();
        if (!$campaign) jsonError('Campaign not found or already sent');
        if (!$campaign['list_id']) jsonError('No audience list selected');
        if (empty($campaign['content_html'])) jsonError('Campaign has no email content');

        // Get active list members
        $members = $db->prepare("SELECT elm.lead_id, elm.email, l.company_name, l.contact_person FROM email_list_members elm LEFT JOIN leads l ON elm.lead_id = l.lead_id WHERE elm.list_id = ? AND elm.status = 'Active'");
        $members->execute([$campaign['list_id']]);
        $recipients = $members->fetchAll();

        if (empty($recipients)) jsonError('No active recipients in the selected list');

        // Mark campaign as sending
        $db->prepare("UPDATE email_campaigns SET status = 'Sending', total_recipients = ? WHERE campaign_id = ?")->execute([count($recipients), $campaignId]);

        // Queue all recipients
        $queueStmt = $db->prepare("INSERT INTO email_campaign_log (campaign_id, lead_id, email, status, tracking_token) VALUES (?, ?, ?, 'Queued', ?)");
        foreach ($recipients as $r) {
            $token = bin2hex(random_bytes(32));
            $queueStmt->execute([$campaignId, $r['lead_id'], $r['email'], $token]);
        }

        // Process queue in batches
        $batchSize = (int)(getSettingValue('email_batch_size') ?: 50);
        $batchDelay = (int)(getSettingValue('email_batch_delay') ?: 2);

        $queued = $db->prepare("SELECT ecl.*, l.company_name, l.contact_person FROM email_campaign_log ecl LEFT JOIN leads l ON ecl.lead_id = l.lead_id WHERE ecl.campaign_id = ? AND ecl.status = 'Queued' LIMIT ?");
        $queued->bindValue(1, $campaignId, PDO::PARAM_INT);
        $queued->bindValue(2, $batchSize, PDO::PARAM_INT);
        $queued->execute();
        $batch = $queued->fetchAll();

        $sent = 0;
        $failed = 0;
        $appUrl = rtrim(APP_URL, '/');

        foreach ($batch as $item) {
            $html = $campaign['content_html'];

            // Merge tags
            $html = str_replace('{{company_name}}', htmlspecialchars($item['company_name'] ?? ''), $html);
            $html = str_replace('{{contact_person}}', htmlspecialchars($item['contact_person'] ?? ''), $html);
            $html = str_replace('{{email}}', htmlspecialchars($item['email']), $html);

            // Tracking pixel
            $trackPixel = '<img src="' . $appUrl . '/api/email.php?action=track_open&token=' . $item['tracking_token'] . '" width="1" height="1" style="display:none;" alt="">';
            $html .= $trackPixel;

            // Rewrite links for click tracking
            $html = preg_replace_callback('/href="(https?:\/\/[^"]+)"/', function($m) use ($appUrl, $item) {
                $encodedUrl = urlencode($m[1]);
                return 'href="' . $appUrl . '/api/email.php?action=track_click&token=' . $item['tracking_token'] . '&url=' . $encodedUrl . '"';
            }, $html);

            // Add unsubscribe link
            $unsubLink = $appUrl . '/api/email.php?action=unsubscribe&token=' . $item['tracking_token'];
            $html = str_replace('{{unsubscribe_url}}', $unsubLink, $html);

            $result = sendEmailViaSMTP($item['email'], $campaign['subject'], $html, $campaign['from_name'], $campaign['from_email'], $campaign['reply_to']);

            if ($result['success']) {
                $db->prepare("UPDATE email_campaign_log SET status = 'Sent', sent_at = NOW() WHERE log_id = ?")->execute([$item['log_id']]);
                $sent++;
            } else {
                $db->prepare("UPDATE email_campaign_log SET status = 'Failed', error_message = ? WHERE log_id = ?")->execute([$result['error'], $item['log_id']]);
                $failed++;
            }
        }

        // Check if all done
        $remaining = $db->prepare("SELECT COUNT(*) FROM email_campaign_log WHERE campaign_id = ? AND status = 'Queued'");
        $remaining->execute([$campaignId]);
        $left = $remaining->fetchColumn();

        $finalStatus = ($left == 0) ? 'Sent' : 'Sending';
        $db->prepare("UPDATE email_campaigns SET status = ?, total_sent = total_sent + ?, total_failed = total_failed + ?, sent_at = IF(? = 'Sent', NOW(), sent_at) WHERE campaign_id = ?")
           ->execute([$finalStatus, $sent, $failed, $finalStatus, $campaignId]);

        logActivity($currentUser['user_id'], 'Send Campaign', 'Campaign', $campaignId, "Sent batch: $sent sent, $failed failed, $left remaining");
        jsonSuccess("Batch complete: $sent sent, $failed failed" . ($left > 0 ? ", $left remaining — click Send again to continue" : " — campaign complete!"), [
            'sent' => $sent, 'failed' => $failed, 'remaining' => $left, 'status' => $finalStatus
        ]);
    }

    // ─── CAMPAIGN REPORT ───
    if ($action === 'campaign_report' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $campaign = $db->prepare("SELECT c.*, el.name as list_name FROM email_campaigns c LEFT JOIN email_lists el ON c.list_id = el.list_id WHERE c.campaign_id = ?");
        $campaign->execute([$id]);
        $data = $campaign->fetch();
        if (!$data) jsonError('Campaign not found', 404);

        $logs = $db->prepare("SELECT ecl.*, l.company_name, l.contact_person FROM email_campaign_log ecl LEFT JOIN leads l ON ecl.lead_id = l.lead_id WHERE ecl.campaign_id = ? ORDER BY ecl.sent_at DESC");
        $logs->execute([$id]);
        $data['logs'] = $logs->fetchAll();

        jsonSuccess('Report loaded', $data);
    }

    // ─── LEADS WITH EMAIL (for list building) ───
    if ($action === 'leads_with_email') {
        $stmt = $db->query("SELECT lead_id, company_name, contact_person, email, lead_status, country, lead_type, priority FROM leads WHERE email IS NOT NULL AND email != '' ORDER BY company_name LIMIT 5000");
        jsonSuccess('Leads loaded', $stmt->fetchAll());
    }

    jsonError('Unknown action: ' . $action, 400);

} catch (Exception $e) {
    error_log("Email API Error: " . $e->getMessage());
    jsonError('Server error: ' . $e->getMessage(), 500);
}

// ─── Helper: Get setting value ───
function getSettingValue($key) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

// ─── Helper: Send email via SMTP (PHPMailer) or mail() fallback ───
function sendEmailViaSMTP($to, $subject, $html, $fromName = null, $fromEmail = null, $replyTo = null) {
    $smtpHost = getSettingValue('smtp_host');
    $smtpPort = getSettingValue('smtp_port') ?: 465;
    $smtpUser = getSettingValue('smtp_username');
    $smtpPass = getSettingValue('smtp_password');
    $smtpEnc  = getSettingValue('smtp_encryption') ?: 'ssl';
    $defaultFrom = getSettingValue('email_from_address');
    $defaultName = getSettingValue('email_from_name') ?: 'Victory Genomics';

    $fromEmail = $fromEmail ?: $defaultFrom;
    $fromName  = $fromName  ?: $defaultName;

    // Try PHPMailer if available
    $phpmailerPath = __DIR__ . '/../includes/PHPMailer.php';
    if (file_exists($phpmailerPath) && !empty($smtpHost)) {
        require_once $phpmailerPath;
        require_once __DIR__ . '/../includes/SMTP.php';
        require_once __DIR__ . '/../includes/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = $smtpEnc;
            $mail->Port = (int)$smtpPort;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo) $mail->addReplyTo($replyTo);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html));

            $mail->send();
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $mail->ErrorInfo];
        }
    }

    // Fallback to PHP mail()
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    if ($fromEmail) $headers .= "From: $fromName <$fromEmail>\r\n";
    if ($replyTo)   $headers .= "Reply-To: $replyTo\r\n";

    $result = @mail($to, $subject, $html, $headers);
    return $result ? ['success' => true] : ['success' => false, 'error' => 'PHP mail() failed'];
}
