<?php
/**
 * Victory Genomics CRM - VoIP API Endpoint
 * Handles: token generation, call initiation, call status, call logging, TwiML
 * Role-based: Sales Reps see only their calls; Admin/Manager see all
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/twilio.php';

$action = $_GET['action'] ?? '';

// ─── Public webhook endpoints (no auth required) ───
// These return TwiML (XML) or minimal responses — do NOT set JSON header here
if (in_array($action, ['twiml', 'twiml_outbound', 'twiml_conference', 'call_status', 'dial_status', 'recording_status'])) {
    handleWebhook($action);
    exit;
}

// All other endpoints return JSON
header('Content-Type: application/json');

// ─── Authenticated endpoints ───
startSecureSession();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

switch ($action) {
    case 'token':
        getVoiceToken();
        break;
    case 'call':
        initiateCall();
        break;
    case 'server_call':
        // DISABLED: server_call caused double-call bugs. All calls go through device.connect() now.
        echo json_encode(['success' => false, 'message' => 'server_call is disabled. Use device.connect() instead.']);
        break;
    case 'end_call':
        endCall();
        break;
    case 'log_call':
        logCallOutcome();
        break;
    case 'call_history':
        getCallHistory();
        break;
    case 'call_stats':
        getCallStats();
        break;
    case 'poll_status':
        pollCallStatus();
        break;
    case 'configure_webhooks':
        configureWebhooks();
        break;
    case 'check_webhooks':
        checkWebhooks();
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ──────────────────────────────────────
// TOKEN GENERATION
// ──────────────────────────────────────
function getVoiceToken() {
    try {
        $twilio = TwilioHelper::getInstance();
        if (!$twilio->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Twilio not configured. Please set up your Twilio credentials in Settings.']);
            return;
        }

        $identity = 'crm_user_' . getCurrentUserId();
        $token = $twilio->generateVoiceToken($identity);

        echo json_encode([
            'success'  => true,
            'token'    => $token,
            'identity' => $identity,
        ]);
    } catch (Exception $e) {
        error_log("VoIP token error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not generate token: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// INITIATE CALL (logs to DB, returns call_id)
// ──────────────────────────────────────
function initiateCall() {
    $input = json_decode(file_get_contents('php://input'), true);
    $toNumber = $input['to_number'] ?? '';
    $leadId   = intval($input['lead_id'] ?? 0);

    if (empty($toNumber)) {
        echo json_encode(['success' => false, 'message' => 'Phone number is required']);
        return;
    }

    try {
        $twilio = TwilioHelper::getInstance();
        $normalized = TwilioHelper::normalizePhone($toNumber);

        // Log call in database
        $callId = TwilioHelper::logCall([
            'lead_id'     => $leadId ?: null,
            'user_id'     => getCurrentUserId(),
            'from_number' => $twilio->getPhoneNumber(),
            'to_number'   => $normalized,
            'direction'   => 'Outbound',
            'status'      => 'Initiated',
            'started_at'  => date('Y-m-d H:i:s'),
        ]);

        // Also log as interaction if we have a lead
        if ($leadId) {
            $db = Database::getInstance();
            $db->insert('interactions', [
                'lead_id'          => $leadId,
                'user_id'          => getCurrentUserId(),
                'interaction_type' => 'VoIP Call',
                'interaction_date' => date('Y-m-d H:i:s'),
                'subject'          => 'VoIP Call to ' . $normalized,
                'notes'            => 'Outbound VoIP call initiated from CRM',
                'outcome'          => null,
            ]);

            logActivity(getCurrentUserId(), 'VoIP Call', 'VoIP', $callId, "Called $normalized for lead #$leadId");
        } else {
            logActivity(getCurrentUserId(), 'VoIP Call', 'VoIP', $callId, "Called $normalized (no lead)");
        }

        echo json_encode([
            'success'   => true,
            'call_id'   => $callId,
            'to_number' => $normalized,
            'message'   => 'Call initiated',
        ]);
    } catch (Exception $e) {
        error_log("VoIP call error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to initiate call: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// SERVER-SIDE CALL (REST API fallback when WebRTC unavailable)
// Twilio calls the lead; when they answer, TwiML connects them
// back to the CRM user via Twilio Client.
// ──────────────────────────────────────
function serverSideCall() {
    $input = json_decode(file_get_contents('php://input'), true);
    $toNumber = $input['to_number'] ?? '';
    $leadId   = intval($input['lead_id'] ?? 0);
    $callId   = intval($input['call_id'] ?? 0);

    if (empty($toNumber)) {
        echo json_encode(['success' => false, 'message' => 'Phone number is required']);
        return;
    }

    try {
        $twilio = TwilioHelper::getInstance();
        if (!$twilio->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Twilio not configured. Go to Settings > VoIP & WhatsApp to configure.']);
            return;
        }

        $normalized = TwilioHelper::normalizePhone($toNumber);
        $userId = getCurrentUserId();

        // Get base URL for callback
        $appUrl = $twilio->getAppUrl();
        $statusCallbackUrl = $appUrl . '/api/voip.php?action=call_status';

        // Conference-based approach:
        // 1. Create a unique conference room
        // 2. Call the lead — when they answer, TwiML puts them into the conference
        // 3. The browser joins the same conference via WebRTC device.connect()
        $confRoom = 'crm_call_' . $callId . '_' . time();
        
        // TwiML URL: when the lead answers, they join the conference
        $twimlUrl = $appUrl . '/api/voip.php?action=twiml_conference&room=' . urlencode($confRoom) . '&call_id=' . $callId;

        $params = [
            'url' => $twimlUrl,
            'statusCallback' => $statusCallbackUrl,
            'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
            'statusCallbackMethod' => 'POST',
        ];

        if ($twilio->isVoipRecordingEnabled()) {
            $params['record'] = true;
            $params['recordingStatusCallback'] = $appUrl . '/api/voip.php?action=recording_status';
        }

        // Make REST API call: Twilio calls the LEAD
        $twilioCall = $twilio->getClient()->calls->create(
            TwilioHelper::normalizePhone($toNumber), // To: the lead
            $twilio->getPhoneNumber(),                 // From: CRM phone number
            $params
        );

        // Update the call record with the Twilio SID
        if ($callId) {
            $db = Database::getInstance();
            $db->update('voip_calls', [
                'twilio_call_sid' => $twilioCall->sid,
                'status' => 'Ringing',
            ], ['call_id' => $callId]);
        }

        echo json_encode([
            'success'   => true,
            'call_sid'  => $twilioCall->sid,
            'status'    => $twilioCall->status,
            'conf_room' => $confRoom,
            'message'   => 'Call initiated via Twilio',
        ]);
    } catch (Exception $e) {
        error_log("Server-side call error: " . $e->getMessage());

        // Provide helpful error for trial accounts
        $errMsg = $e->getMessage();
        if (stripos($errMsg, 'not verified') !== false || stripos($errMsg, 'trial') !== false || $e->getCode() == 21219) {
            $errMsg = 'Twilio trial accounts can only call verified numbers. Please verify this number in your Twilio Console > Verified Caller IDs.';
        }
        if (stripos($errMsg, 'VoIP is disabled') !== false) {
            $errMsg = 'VoIP is disabled. Enable it in Settings > VoIP & WhatsApp.';
        }

        echo json_encode(['success' => false, 'message' => $errMsg]);
    }
}

// ──────────────────────────────────────
// END CALL
// ──────────────────────────────────────
function endCall() {
    $input = json_decode(file_get_contents('php://input'), true);
    $callId   = intval($input['call_id'] ?? 0);
    $callSid  = $input['call_sid'] ?? '';
    $duration = intval($input['duration'] ?? 0);
    $reason   = $input['reason'] ?? 'completed';

    try {
        $db = Database::getInstance();

        if ($callId) {
            $updates = [
                'status'   => 'Completed',
                'ended_at' => date('Y-m-d H:i:s'),
            ];
            // Only set duration from client if Twilio hasn't already set it via webhook
            if ($duration > 0) {
                $currentCall = $db->query("SELECT duration_seconds FROM voip_calls WHERE call_id = ?", [$callId])->fetch(PDO::FETCH_ASSOC);
                if (!$currentCall || !$currentCall['duration_seconds'] || $currentCall['duration_seconds'] == 0) {
                    $updates['duration_seconds'] = $duration;
                }
            }

            // Map reasons to statuses
            $statusMap = [
                'completed' => 'Completed',
                'canceled'  => 'Canceled',
                'rejected'  => 'Canceled',
                'failed'    => 'Failed',
                'no-answer' => 'No-Answer',
                'busy'      => 'Busy',
            ];
            if (isset($statusMap[$reason])) {
                $updates['status'] = $statusMap[$reason];
            }

            $db->update('voip_calls', $updates, ['call_id' => $callId]);
        }

        // End on Twilio side if we have a SID
        if ($callSid) {
            $twilio = TwilioHelper::getInstance();
            $client = $twilio->getClient();
            if ($client) {
                try {
                    $client->calls($callSid)->update(['status' => 'completed']);
                } catch (Exception $e) {
                    // Call may already be ended
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Call ended']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// LOG CALL OUTCOME
// ──────────────────────────────────────
function logCallOutcome() {
    $input = json_decode(file_get_contents('php://input'), true);
    $callId   = intval($input['call_id'] ?? 0);
    $notes    = $input['notes'] ?? '';
    $outcome  = $input['outcome'] ?? '';
    $duration = intval($input['duration'] ?? 0);

    if (!$callId) {
        echo json_encode(['success' => false, 'message' => 'Call ID is required']);
        return;
    }

    try {
        $db = Database::getInstance();
        $updateData = ['notes' => $notes];
        if ($outcome) $updateData['outcome'] = $outcome;
        if ($duration > 0) {
            // Only update duration if not already set by webhook
            $currentCall = $db->query("SELECT duration_seconds, lead_id FROM voip_calls WHERE call_id = ?", [$callId])->fetch(PDO::FETCH_ASSOC);
            if ($currentCall && (!$currentCall['duration_seconds'] || $currentCall['duration_seconds'] == 0)) {
                $updateData['duration_seconds'] = $duration;
            }
        }

        $db->update('voip_calls', $updateData, ['call_id' => $callId]);

        // Update the related interaction if exists
        if ($outcome) {
            $call = $db->query("SELECT lead_id, to_number FROM voip_calls WHERE call_id = ?", [$callId])->fetch(PDO::FETCH_ASSOC);
            if ($call && $call['lead_id']) {
                try {
                    // Update the most recent VoIP Call interaction for this lead
                    $db->query(
                        "UPDATE interactions SET outcome = ?, notes = CONCAT(COALESCE(notes,''), '\n', ?) 
                         WHERE lead_id = ? AND interaction_type = 'VoIP Call' 
                         ORDER BY interaction_id DESC LIMIT 1",
                        [$outcome, $notes ? "Outcome: $outcome. $notes" : "Outcome: $outcome", $call['lead_id']]
                    );
                } catch (Exception $e) {
                    // Non-critical
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Call logged']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// CALL HISTORY (role-based)
// ──────────────────────────────────────
function getCallHistory() {
    $leadId = intval($_GET['lead_id'] ?? 0);
    $limit  = min(intval($_GET['limit'] ?? 50), 200);
    $offset = intval($_GET['offset'] ?? 0);

    try {
        $db = Database::getInstance();
        $params = [];
        $where = '1=1';

        if ($leadId) {
            $where .= ' AND vc.lead_id = ?';
            $params[] = $leadId;
        }

        // Role-based filtering
        $isSalesRep = !hasRole('Sales Manager');
        if ($isSalesRep) {
            $userId = getCurrentUserId();
            $where .= " AND (vc.user_id = ? OR vc.lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = ?))";
            $params[] = $userId;
            $params[] = $userId;
        }

        $calls = $db->query("
            SELECT vc.*, u.full_name as user_name, l.company_name, l.contact_person
            FROM voip_calls vc
            LEFT JOIN users u ON vc.user_id = u.user_id
            LEFT JOIN leads l ON vc.lead_id = l.lead_id
            WHERE $where
            ORDER BY vc.created_at DESC
            LIMIT $limit OFFSET $offset
        ", $params)->fetchAll(PDO::FETCH_ASSOC);

        $total = $db->query("
            SELECT COUNT(*) FROM voip_calls vc WHERE $where
        ", $params)->fetchColumn();

        echo json_encode(['success' => true, 'data' => $calls, 'total' => $total]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// CALL STATS (role-based)
// ──────────────────────────────────────
function getCallStats() {
    try {
        $db = Database::getInstance();
        $isSalesRep = !hasRole('Sales Manager');
        $userId = getCurrentUserId();

        if ($isSalesRep) {
            $filter = "WHERE (user_id = ? OR lead_id IN (SELECT lead_id FROM leads WHERE assigned_to = ?))";
            $params = [$userId, $userId];
        } else {
            $filter = "WHERE 1=1";
            $params = [];
        }

        $stats = [
            'total_calls'    => $db->query("SELECT COUNT(*) FROM voip_calls $filter", $params)->fetchColumn(),
            'today_calls'    => $db->query("SELECT COUNT(*) FROM voip_calls $filter AND DATE(created_at) = CURDATE()", $params)->fetchColumn(),
            'total_duration' => $db->query("SELECT COALESCE(SUM(duration_seconds),0) FROM voip_calls $filter", $params)->fetchColumn(),
            'avg_duration'   => $db->query("SELECT COALESCE(AVG(duration_seconds),0) FROM voip_calls $filter AND duration_seconds > 0", $params)->fetchColumn(),
            'completed'      => $db->query("SELECT COUNT(*) FROM voip_calls $filter AND status = 'Completed'", $params)->fetchColumn(),
            'positive'       => $db->query("SELECT COUNT(*) FROM voip_calls $filter AND outcome = 'Positive'", $params)->fetchColumn(),
        ];

        echo json_encode(['success' => true, 'data' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// WEBHOOK HANDLERS (no auth)
// ──────────────────────────────────────
function handleWebhook($action) {
    switch ($action) {
        case 'twiml':
            // Generate TwiML for WebRTC calls.
            // Browser calls device.connect({params:{To:'+1xxx', call_id:'123'}})
            // → Twilio POSTs here with To=+1xxx
            // → We return <Dial><Number>+1xxx</Number></Dial>
            // → Twilio bridges the browser leg with the phone leg = two-way audio
            header('Content-Type: text/xml');
            $toNumber = $_REQUEST['To'] ?? $_REQUEST['number'] ?? '';
            $response = new \Twilio\TwiML\VoiceResponse();
            if ($toNumber && strpos($toNumber, 'client:') !== 0) {
                // Outbound call: dial the lead's phone
                $twilio = TwilioHelper::getInstance();
                $appUrl = $twilio->getAppUrl();
                $callId = $_REQUEST['call_id'] ?? '';
                $statusUrl = $appUrl . '/api/voip.php?action=dial_status' . ($callId ? '&call_id=' . urlencode($callId) : '');
                $dial = $response->dial('', [
                    'callerId' => $twilio->getPhoneNumber(),
                    'timeout'  => 30,
                    'action'   => $statusUrl,
                    'method'   => 'POST',
                ]);
                $dial->number(TwilioHelper::normalizePhone($toNumber));
            } elseif ($toNumber && strpos($toNumber, 'client:') === 0) {
                // Dial another Twilio Client (internal call)
                $twilio = TwilioHelper::getInstance();
                $dial = $response->dial('', [
                    'callerId' => $twilio->getPhoneNumber(),
                    'timeout'  => 30,
                ]);
                $dial->client(str_replace('client:', '', $toNumber));
            } else {
                // Inbound call: greet and connect to the first available CRM user
                $response->say('Thanks for calling Victory Genomics. Please hold while we connect you.', ['voice' => 'alice']);
                $response->dial()->client('crm_user_1');
            }
            echo $response;
            break;

        case 'twiml_outbound':
            // DEPRECATED: No longer used. All calls go through device.connect() + twiml action.
            header('Content-Type: text/xml');
            $response = new \Twilio\TwiML\VoiceResponse();
            $response->say('This endpoint is deprecated. Please update your configuration.', ['voice' => 'alice']);
            $response->hangup();
            echo $response;
            break;

        case 'twiml_conference':
            // Put a caller into a conference room (used by server-side calls)
            // Both the lead and the CRM user's browser join the same conference
            header('Content-Type: text/xml');
            $confRoom = $_REQUEST['room'] ?? '';
            $callId = $_REQUEST['call_id'] ?? '';
            $response = new \Twilio\TwiML\VoiceResponse();
            if ($confRoom) {
                $dial = $response->dial('');
                $confAttrs = [
                    'startConferenceOnEnter' => true,
                    'endConferenceOnExit'    => true,
                    'beep'                   => false,
                ];
                // If this is the CRM user joining, wait for the lead
                if (isset($_REQUEST['is_agent']) && $_REQUEST['is_agent'] === '1') {
                    $confAttrs['startConferenceOnEnter'] = true;
                    $confAttrs['endConferenceOnExit'] = true;
                }
                $dial->conference($confRoom, $confAttrs);
            } else {
                $response->say('Sorry, there was a problem connecting your call.', ['voice' => 'alice']);
                $response->hangup();
            }
            echo $response;
            break;

        case 'dial_status':
            // Called after <Dial> completes — return TwiML to end the call cleanly
            header('Content-Type: text/xml');
            $dialCallStatus = $_POST['DialCallStatus'] ?? '';
            $callId = $_REQUEST['call_id'] ?? '';
            
            // Update call record with dial result
            if ($callId) {
                try {
                    $db = Database::getInstance();
                    $statusMap = [
                        'completed'  => 'Completed',
                        'busy'       => 'Busy',
                        'no-answer'  => 'No-Answer',
                        'failed'     => 'Failed',
                        'canceled'   => 'Canceled',
                    ];
                    $mappedStatus = $statusMap[$dialCallStatus] ?? 'Completed';
                    $db->update('voip_calls', [
                        'status'   => $mappedStatus,
                        'ended_at' => date('Y-m-d H:i:s'),
                    ], ['call_id' => $callId]);
                } catch (Exception $e) {
                    error_log("Dial status update error: " . $e->getMessage());
                }
            }
            
            $response = new \Twilio\TwiML\VoiceResponse();
            $response->hangup();
            echo $response;
            break;

        case 'call_status':
            $callSid  = $_POST['CallSid'] ?? '';
            $status   = $_POST['CallStatus'] ?? '';
            $duration = intval($_POST['CallDuration'] ?? 0);

            if ($callSid) {
                try {
                    $db = Database::getInstance();
                    $statusMap = [
                        'initiated'   => 'Initiated',
                        'ringing'     => 'Ringing',
                        'in-progress' => 'In-Progress',
                        'completed'   => 'Completed',
                        'busy'        => 'Busy',
                        'no-answer'   => 'No-Answer',
                        'canceled'    => 'Canceled',
                        'failed'      => 'Failed',
                    ];

                    $mappedStatus = $statusMap[$status] ?? 'Completed';
                    $updates = ['status' => $mappedStatus];

                    // Twilio reports CallDuration only on completed calls
                    if ($duration > 0) {
                        $updates['duration_seconds'] = $duration;
                    }
                    if (in_array($status, ['completed', 'busy', 'no-answer', 'canceled', 'failed'])) {
                        $updates['ended_at'] = date('Y-m-d H:i:s');
                    }

                    // Build dynamic UPDATE to handle nullable fields properly
                    $sets = [];
                    $params = [];
                    foreach ($updates as $key => $val) {
                        if ($key === 'duration_seconds') {
                            // Only update duration if it's currently 0 or NULL
                            $sets[] = "duration_seconds = CASE WHEN COALESCE(duration_seconds,0) = 0 THEN ? ELSE duration_seconds END";
                            $params[] = $val;
                        } elseif ($key === 'ended_at') {
                            $sets[] = "ended_at = COALESCE(ended_at, ?)";
                            $params[] = $val;
                        } else {
                            $sets[] = "$key = ?";
                            $params[] = $val;
                        }
                    }
                    $params[] = $callSid;

                    $db->query(
                        "UPDATE voip_calls SET " . implode(', ', $sets) . " WHERE twilio_call_sid = ?",
                        $params
                    );

                    error_log("VoIP webhook: CallSid=$callSid status=$status duration=$duration -> $mappedStatus");
                } catch (Exception $e) {
                    error_log("VoIP status webhook error: " . $e->getMessage());
                }
            }
            http_response_code(200);
            echo '<Response/>';
            break;

        case 'recording_status':
            $callSid      = $_POST['CallSid'] ?? '';
            $recordingUrl = $_POST['RecordingUrl'] ?? '';
            $recordingSid = $_POST['RecordingSid'] ?? '';

            if ($callSid && $recordingUrl) {
                try {
                    $db = Database::getInstance();
                    $db->query(
                        "UPDATE voip_calls SET recording_url = ?, recording_sid = ? WHERE twilio_call_sid = ?",
                        [$recordingUrl, $recordingSid, $callSid]
                    );
                    error_log("VoIP recording saved: CallSid=$callSid RecordingSid=$recordingSid");
                } catch (Exception $e) {
                    error_log("VoIP recording webhook error: " . $e->getMessage());
                }
            }
            http_response_code(200);
            echo '<Response/>';
            break;
    }
}

// ──────────────────────────────────────
// POLL CALL STATUS (for server-side calls without WebRTC events)
// ──────────────────────────────────────
function pollCallStatus() {
    $callId = intval($_GET['call_id'] ?? 0);
    if (!$callId) {
        echo json_encode(['success' => false, 'message' => 'call_id required']);
        return;
    }
    try {
        $db = Database::getInstance();
        $call = $db->query(
            "SELECT call_id, status, duration_seconds, twilio_call_sid, ended_at FROM voip_calls WHERE call_id = ?",
            [$callId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$call) {
            echo json_encode(['success' => false, 'message' => 'Call not found']);
            return;
        }

        // If we have a Twilio SID and status is still Ringing/Initiated, query Twilio for live status
        if ($call['twilio_call_sid'] && in_array($call['status'], ['Initiated', 'Ringing'])) {
            try {
                $twilio = TwilioHelper::getInstance();
                $client = $twilio->getClient();
                if ($client) {
                    $twilioCall = $client->calls($call['twilio_call_sid'])->fetch();
                    $statusMap = [
                        'queued'      => 'Initiated',
                        'ringing'     => 'Ringing',
                        'in-progress' => 'In-Progress',
                        'completed'   => 'Completed',
                        'busy'        => 'Busy',
                        'no-answer'   => 'No-Answer',
                        'canceled'    => 'Canceled',
                        'failed'      => 'Failed',
                    ];
                    $liveStatus = $statusMap[$twilioCall->status] ?? $call['status'];
                    if ($liveStatus !== $call['status']) {
                        $updates = ['status' => $liveStatus];
                        if ($twilioCall->duration) {
                            $updates['duration_seconds'] = intval($twilioCall->duration);
                        }
                        if (in_array($twilioCall->status, ['completed', 'busy', 'no-answer', 'canceled', 'failed'])) {
                            $updates['ended_at'] = date('Y-m-d H:i:s');
                        }
                        $db->update('voip_calls', $updates, ['call_id' => $callId]);
                        $call['status'] = $liveStatus;
                        if (isset($updates['duration_seconds'])) {
                            $call['duration_seconds'] = $updates['duration_seconds'];
                        }
                    }
                }
            } catch (Exception $e) {
                // Non-critical — return DB status
                error_log("Poll Twilio status error: " . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'data' => $call]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// CONFIGURE TWILIO WEBHOOKS (Admin only)
// Sets the phone number's voice/messaging webhooks to point to CRM
// ──────────────────────────────────────
function configureWebhooks() {
    if (!hasRole('Sales Manager') && !hasRole('Admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        return;
    }

    try {
        $twilio = TwilioHelper::getInstance();
        if (!$twilio->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Twilio not configured']);
            return;
        }

        $client = $twilio->getClient();
        $phoneNumber = $twilio->getPhoneNumber();
        $appUrl = $twilio->getAppUrl();

        // Find the phone number SID
        $numbers = $client->incomingPhoneNumbers->read(['phoneNumber' => $phoneNumber], 1);
        if (empty($numbers)) {
            echo json_encode(['success' => false, 'message' => 'Phone number not found in Twilio account: ' . $phoneNumber]);
            return;
        }

        $numberSid = $numbers[0]->sid;
        $changes = [];

        // Update voice webhook to point to CRM's TwiML endpoint
        $voiceUrl = $appUrl . '/api/voip.php?action=twiml';
        $statusCallbackUrl = $appUrl . '/api/voip.php?action=call_status';
        $smsUrl = $appUrl . '/api/whatsapp.php?action=webhook';

        $client->incomingPhoneNumbers($numberSid)->update([
            'voiceUrl'            => $voiceUrl,
            'voiceMethod'         => 'POST',
            'statusCallback'      => $statusCallbackUrl,
            'statusCallbackMethod'=> 'POST',
            'smsUrl'              => $smsUrl,
            'smsMethod'           => 'POST',
        ]);

        $changes[] = "Voice URL → $voiceUrl";
        $changes[] = "Status Callback → $statusCallbackUrl";
        $changes[] = "SMS URL → $smsUrl";

        // Also update the TwiML App if configured
        $twimlAppSid = $twilio->getTwimlAppSid();
        if ($twimlAppSid) {
            try {
                $client->applications($twimlAppSid)->update([
                    'voiceUrl'    => $voiceUrl,
                    'voiceMethod' => 'POST',
                    'statusCallback' => $statusCallbackUrl,
                    'statusCallbackMethod' => 'POST',
                ]);
                $changes[] = "TwiML App Voice URL → $voiceUrl";
                $changes[] = "TwiML App Status Callback → $statusCallbackUrl";
            } catch (Exception $e) {
                $changes[] = "TwiML App update failed: " . $e->getMessage();
            }
        }

        logActivity(getCurrentUserId(), 'Webhook Configuration', 'Settings', null, 'Updated Twilio webhooks: ' . implode('; ', $changes));

        echo json_encode([
            'success' => true,
            'message' => 'Twilio webhooks configured successfully',
            'changes' => $changes
        ]);
    } catch (Exception $e) {
        error_log("Configure webhooks error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed: ' . $e->getMessage()]);
    }
}

// ──────────────────────────────────────
// CHECK TWILIO WEBHOOK CONFIGURATION
// ──────────────────────────────────────
function checkWebhooks() {
    if (!hasRole('Sales Manager') && !hasRole('Admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        return;
    }

    try {
        $twilio = TwilioHelper::getInstance();
        if (!$twilio->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'Twilio not configured']);
            return;
        }

        $client = $twilio->getClient();
        $phoneNumber = $twilio->getPhoneNumber();
        $appUrl = $twilio->getAppUrl();

        $result = [
            'phone_number' => $phoneNumber,
            'app_url' => $appUrl,
            'issues' => [],
        ];

        // Check phone number webhooks
        $numbers = $client->incomingPhoneNumbers->read(['phoneNumber' => $phoneNumber], 1);
        if (!empty($numbers)) {
            $num = $numbers[0];
            $result['phone_config'] = [
                'voice_url'       => $num->voiceUrl,
                'voice_method'    => $num->voiceMethod,
                'status_callback' => $num->statusCallback,
                'sms_url'         => $num->smsUrl,
            ];

            $expectedVoiceUrl = $appUrl . '/api/voip.php?action=twiml';
            if (strpos($num->voiceUrl, 'demo.twilio.com') !== false) {
                $result['issues'][] = 'Phone number voice URL points to Twilio demo — calls will NOT connect through CRM';
            } elseif ($num->voiceUrl !== $expectedVoiceUrl) {
                $result['issues'][] = 'Phone number voice URL does not match CRM endpoint';
            }
        } else {
            $result['issues'][] = 'Phone number not found in Twilio account';
        }

        // Check TwiML App
        $twimlAppSid = $twilio->getTwimlAppSid();
        if ($twimlAppSid) {
            try {
                $app = $client->applications($twimlAppSid)->fetch();
                $result['twiml_app'] = [
                    'friendly_name'   => $app->friendlyName,
                    'voice_url'       => $app->voiceUrl,
                    'voice_method'    => $app->voiceMethod,
                    'status_callback' => $app->statusCallback,
                ];
                if (empty($app->voiceUrl)) {
                    $result['issues'][] = 'TwiML App has no voice URL configured';
                }
            } catch (Exception $e) {
                $result['issues'][] = 'Could not fetch TwiML App: ' . $e->getMessage();
            }
        }

        $result['success'] = true;
        $result['healthy'] = empty($result['issues']);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
