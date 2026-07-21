<?php
/**
 * Victory Genomics CRM — Proposals API
 *
 * Actions:
 *   GET  ?action=list                — paginated list
 *   GET  ?action=get&id=N            — single proposal
 *   POST ?action=save                — create/update proposal
 *   POST ?action=delete              — soft-delete (mark Declined) or hard-delete
 *   GET  ?action=next_number         — return next estimate number
 *   GET  ?action=pdf_html&id=N       — return A4 HTML for client-side PDF generation
 *   GET  ?action=company_info        — return proposal company settings
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/automation-engine.php';

startSecureSession();
requireLogin();
requireRole('Sales Manager');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('X-LiteSpeed-Cache-Control: no-cache');

$db     = Database::getInstance();
$pdo    = $db->getConnection();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Helper: get setting value ────────────────────────────────
function getProposalSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

// ══════════════════════════════════════════════════════════════
//  LIST proposals
// ══════════════════════════════════════════════════════════════
if ($action === 'list') {
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]  = "(p.customer_company LIKE ? OR p.contact_name LIKE ? OR p.estimate_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status !== '') {
        $where[]  = "p.status = ?";
        $params[] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM proposals p $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name AS creator_name
        FROM proposals p
        LEFT JOIN users u ON u.user_id = p.created_by
        $whereSQL
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'   => true,
        'proposals' => $proposals,
        'total'     => $total,
        'page'      => $page,
        'pages'     => ceil($total / $limit),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  GET single proposal
// ══════════════════════════════════════════════════════════════
if ($action === 'get') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Proposal ID required.']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name AS creator_name
        FROM proposals p
        LEFT JOIN users u ON u.user_id = p.created_by
        WHERE p.proposal_id = ?
    ");
    $stmt->execute([$id]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proposal) {
        echo json_encode(['success' => false, 'message' => 'Proposal not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'proposal' => $proposal]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  NEXT_NUMBER — return the next estimate number
// ══════════════════════════════════════════════════════════════
if ($action === 'next_number') {
    $next = getProposalSetting($pdo, 'proposal_next_number', '1006');
    echo json_encode(['success' => true, 'next_number' => intval($next)]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  COMPANY_INFO — return proposal company settings
// ══════════════════════════════════════════════════════════════
if ($action === 'company_info') {
    echo json_encode([
        'success' => true,
        'company' => [
            'name'    => getProposalSetting($pdo, 'proposal_company_name', 'Victory Genomics, INC.'),
            'address' => getProposalSetting($pdo, 'proposal_company_address', ''),
            'phone'   => getProposalSetting($pdo, 'proposal_company_phone', ''),
            'email'   => getProposalSetting($pdo, 'proposal_company_email', ''),
            'website' => getProposalSetting($pdo, 'proposal_company_website', ''),
        ],
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  SAVE (create or update)
// ══════════════════════════════════════════════════════════════
if ($action === 'save' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $csrfToken = $input['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $proposalId      = intval($input['proposal_id'] ?? 0);
    $customerCompany = trim($input['customer_company'] ?? '');
    $contactName     = trim($input['contact_name'] ?? '');
    $customerAddress = trim($input['customer_address'] ?? '');
    $lineItems       = $input['line_items'] ?? [];
    $notes           = trim($input['notes'] ?? '');
    $acceptedBy      = trim($input['accepted_by'] ?? '');
    $acceptedDate    = trim($input['accepted_date'] ?? '');
    $status          = trim($input['status'] ?? 'Draft');
    $proposalDate    = trim($input['proposal_date'] ?? date('Y-m-d'));

    // Validate line_items structure and compute total
    if (!is_array($lineItems)) $lineItems = [];
    $totalAmount = 0;
    foreach ($lineItems as &$item) {
        $item['service']     = trim($item['service'] ?? '');
        $item['description'] = trim($item['description'] ?? '');
        $item['qty']         = max(1, floatval($item['qty'] ?? 1));
        $item['rate']        = floatval($item['rate'] ?? 0);
        $item['amount']      = round($item['qty'] * $item['rate'], 2);
        $totalAmount += $item['amount'];
    }
    unset($item);

    $lineItemsJson = json_encode($lineItems);

    $validStatuses = ['Draft', 'Sent', 'Accepted', 'Declined'];
    if (!in_array($status, $validStatuses)) $status = 'Draft';

    if ($proposalId > 0) {
        // ── Get old status for automation ────────────────────
        $oldStatusStmt = $pdo->prepare("SELECT status FROM proposals WHERE proposal_id = ?");
        $oldStatusStmt->execute([$proposalId]);
        $oldProposalStatus = $oldStatusStmt->fetchColumn();

        // ── UPDATE existing proposal ─────────────────────────
        $stmt = $pdo->prepare("
            UPDATE proposals SET
                customer_company = ?, contact_name = ?, customer_address = ?,
                line_items = ?, notes = ?, accepted_by = ?, accepted_date = ?,
                total_amount = ?, status = ?, proposal_date = ?
            WHERE proposal_id = ?
        ");
        $stmt->execute([
            $customerCompany ?: null, $contactName ?: null, $customerAddress ?: null,
            $lineItemsJson, $notes ?: null, $acceptedBy ?: null,
            $acceptedDate ?: null, $totalAmount, $status, $proposalDate,
            $proposalId,
        ]);
        echo json_encode(['success' => true, 'message' => 'Proposal updated.', 'proposal_id' => $proposalId]);

        // Automation: proposal status changed
        if ($oldProposalStatus && $oldProposalStatus !== $status) {
            try {
                fireAutomationTrigger('proposal_status_changed', [
                    'proposal_id'  => $proposalId,
                    'old_status'   => $oldProposalStatus,
                    'new_status'   => $status,
                    'lead_id'      => null,
                    'lead'         => null,
                    'current_user' => getCurrentUser(),
                ]);
            } catch (\Exception $e) {
                error_log("Proposal automation trigger error: " . $e->getMessage());
            }
        }
    } else {
        // ── CREATE new proposal ──────────────────────────────
        // Get & increment estimate number
        $nextNum = intval(getProposalSetting($pdo, 'proposal_next_number', '1006'));

        $stmt = $pdo->prepare("
            INSERT INTO proposals (estimate_number, proposal_date, customer_company, contact_name,
                customer_address, line_items, notes, accepted_by, accepted_date,
                total_amount, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $nextNum, $proposalDate,
            $customerCompany ?: null, $contactName ?: null, $customerAddress ?: null,
            $lineItemsJson, $notes ?: null, $acceptedBy ?: null,
            $acceptedDate ?: null, $totalAmount, $status,
            $_SESSION['user_id'],
        ]);

        $newId = intval($pdo->lastInsertId());

        // Increment the next number
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'proposal_next_number'")
            ->execute([$nextNum + 1]);

        echo json_encode(['success' => true, 'message' => 'Proposal created.', 'proposal_id' => $newId, 'estimate_number' => $nextNum]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
//  DELETE
// ══════════════════════════════════════════════════════════════
if ($action === 'delete' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $csrfToken = $input['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
    $id = intval($input['proposal_id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Proposal ID required.']);
        exit;
    }
    $pdo->prepare("DELETE FROM proposals WHERE proposal_id = ?")->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Proposal deleted.']);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  PDF_HTML — return the A4 print-ready HTML for a proposal
// ══════════════════════════════════════════════════════════════
if ($action === 'pdf_html') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Proposal ID required.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM proposals WHERE proposal_id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Proposal not found.']);
        exit;
    }

    $co = [
        'name'    => getProposalSetting($pdo, 'proposal_company_name', 'Victory Genomics, INC.'),
        'address' => getProposalSetting($pdo, 'proposal_company_address', ''),
        'phone'   => getProposalSetting($pdo, 'proposal_company_phone', ''),
        'email'   => getProposalSetting($pdo, 'proposal_company_email', ''),
        'website' => getProposalSetting($pdo, 'proposal_company_website', ''),
    ];

    $lineItems = json_decode($p['line_items'], true) ?: [];
    $e = function($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };
    $nl2br_e = function($s) use ($e) { return nl2br($e($s)); };
    $fmtDate = function($d) {
        if (!$d) return '';
        $t = strtotime($d);
        return $t ? date('m/d/Y', $t) : $d;
    };

    // Build line items rows
    $rowsHTML = '';
    foreach ($lineItems as $item) {
        $amt = number_format($item['amount'] ?? 0, 2);
        $rate = number_format($item['rate'] ?? 0, 2);
        $qty = intval($item['qty'] ?? 0);
        $rowsHTML .= '<tr>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:11px;width:15%;">' . $e($item['service'] ?? '') . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:11px;width:40%;white-space:pre-line;">' . $e($item['description'] ?? '') . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:11px;text-align:center;width:10%;">' . $qty . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:11px;text-align:right;width:15%;">' . $rate . '</td>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:11px;text-align:right;width:15%;">' . $amt . '</td>'
            . '</tr>';
    }

    $total = number_format($p['total_amount'], 2);
    $coAddressLines = array_filter(array_map('trim', explode("\n", $co['address'])));

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
@page { size: A4; margin: 40px 50px; }
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 10px; line-height: 1.4; background: #fff; }
.page { width: 210mm; min-height: 297mm; padding: 36px 48px; margin: 0 auto; position: relative; }
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
.company-info { font-size: 8px; color: #333; line-height: 1.6; }
.company-name { font-size: 12px; font-weight: 700; color: #000; margin-bottom: 4px; }
.logo { height: 50px; width: auto; }
.estimate-title { font-size: 15px; color: #4f90bb; margin-bottom: 16px; }
.meta-row { display: flex; justify-content: space-between; margin-bottom: 20px; }
.address-block .label, .meta-fields .label { font-size: 10px; color: #8d9096; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
.address-block .value, .meta-fields .value { font-size: 10px; color: #000; margin-bottom: 2px; }
.meta-fields { text-align: right; }
.meta-fields .row { display: flex; justify-content: flex-end; gap: 16px; margin-bottom: 3px; }
.items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.items-table th { background: #eef5fa; color: #4f90bb; font-size: 10px; font-weight: 600; text-align: left; padding: 10px; border-bottom: 2px solid #d0e3f0; }
.items-table th:nth-child(3), .items-table th:nth-child(4), .items-table th:nth-child(5) { text-align: right; }
.items-table th:nth-child(3) { text-align: center; }
.footer-section { border-top: 2px dashed #ccc; padding-top: 16px; display: flex; justify-content: space-between; align-items: flex-start; margin-top: 8px; }
.footer-notes { font-size: 8px; color: #8d9096; line-height: 1.6; max-width: 55%; white-space: pre-line; }
.footer-total { text-align: right; }
.footer-total .total-label { font-size: 11px; color: #8d9096; }
.footer-total .total-value { font-size: 16px; font-weight: 700; color: #000; }
.signature-area { margin-top: 40px; }
.signature-area .sig-label { font-size: 10px; color: #8d9096; margin-bottom: 20px; }
.page-footer { position: absolute; bottom: 36px; left: 48px; right: 48px; text-align: center; font-size: 9px; color: #8d9096; }
</style></head><body><div class="page">';

    // Header with logo + company info
    $html .= '<div class="header">';
    $html .= '<div><img src="/assets/images/VG%20logo.svg" class="logo" alt="Victory Genomics"><div class="company-info" style="margin-top:8px;">';
    $html .= '<div class="company-name">' . $e($co['name']) . '</div>';
    foreach ($coAddressLines as $line) {
        $html .= '<div>' . $e($line) . '</div>';
    }
    if ($co['phone']) $html .= '<div>' . $e($co['phone']) . '</div>';
    if ($co['email']) $html .= '<div>' . $e($co['email']) . '</div>';
    if ($co['website']) $html .= '<div>' . $e($co['website']) . '</div>';
    $html .= '</div></div></div>';

    // Estimate title
    $html .= '<div class="estimate-title">Estimate</div>';

    // Address + Meta row
    $html .= '<div class="meta-row">';
    $html .= '<div class="address-block">';
    $html .= '<div class="label">ADDRESS</div>';
    if ($p['contact_name']) $html .= '<div class="value">' . $e($p['contact_name']) . '</div>';
    if ($p['customer_company']) $html .= '<div class="value">' . $e($p['customer_company']) . '</div>';
    if ($p['customer_address']) $html .= '<div class="value">' . $nl2br_e($p['customer_address']) . '</div>';
    $html .= '</div>';
    $html .= '<div class="meta-fields">';
    $html .= '<div class="row"><span class="label">ESTIMATE</span><span class="value">' . $e($p['estimate_number']) . '</span></div>';
    $html .= '<div class="row"><span class="label">DATE</span><span class="value">' . $fmtDate($p['proposal_date']) . '</span></div>';
    $html .= '</div></div>';

    // Line items table
    $html .= '<table class="items-table"><thead><tr>';
    $html .= '<th>SERVICE</th><th>DESCRIPTION</th><th style="text-align:center;">QTY</th><th style="text-align:right;">RATE</th><th style="text-align:right;">AMOUNT</th>';
    $html .= '</tr></thead><tbody>' . $rowsHTML . '</tbody></table>';

    // Footer section with notes + total
    $html .= '<div class="footer-section">';
    $html .= '<div class="footer-notes">' . $e($p['notes'] ?? '') . '</div>';
    $html .= '<div class="footer-total"><div class="total-label">TOTAL</div><div class="total-value">USD ' . $total . '</div></div>';
    $html .= '</div>';

    // Accepted By / Accepted Date
    $html .= '<div class="signature-area">';
    $html .= '<div class="sig-label">Accepted By</div>';
    if ($p['accepted_by']) $html .= '<div style="font-size:10px;margin-top:-14px;margin-bottom:20px;">' . $e($p['accepted_by']) . '</div>';
    $html .= '<div class="sig-label">Accepted Date</div>';
    if ($p['accepted_date']) $html .= '<div style="font-size:10px;margin-top:-14px;">' . $fmtDate($p['accepted_date']) . '</div>';
    $html .= '</div>';

    // Page footer
    $html .= '<div class="page-footer">Page 1 of 1</div>';
    $html .= '</div></body></html>';

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// ── Fallback ─────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
