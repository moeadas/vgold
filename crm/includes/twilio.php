<?php
/**
 * Victory Genomics CRM — Twilio Integration Helper
 * Unified VoIP (Voice) + WhatsApp via Twilio
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;

class TwilioHelper {
    private static $instance = null;
    private $client;
    private $accountSid;
    private $authToken;
    private $phoneNumber;      // VoIP phone number
    private $twimlAppSid;
    private $whatsappFromNumber;
    private $apiKey;
    private $apiSecret;
    private $appUrl;
    private $voipEnabled;
    private $voipRecordingEnabled;

    private function __construct() {
        // Try to load from DB settings first, then fall back to env vars
        $dbSettings = self::loadSettingsFromDB();

        $this->accountSid         = $dbSettings['twilio_account_sid']    ?? getenv('TWILIO_ACCOUNT_SID')    ?: '';
        $this->authToken          = $dbSettings['twilio_auth_token']     ?? getenv('TWILIO_AUTH_TOKEN')     ?: '';
        $this->phoneNumber        = $dbSettings['twilio_phone_number']   ?? getenv('TWILIO_PHONE_NUMBER')   ?: '';
        $this->twimlAppSid        = $dbSettings['twilio_twiml_app_sid']  ?? getenv('TWILIO_TWIML_APP_SID')  ?: '';
        $this->whatsappFromNumber = $dbSettings['whatsapp_from_number']  ?? getenv('WHATSAPP_FROM_NUMBER')  ?: '';
        $this->apiKey             = $dbSettings['twilio_api_key']        ?? getenv('TWILIO_API_KEY')        ?: '';
        $this->apiSecret          = $dbSettings['twilio_api_secret']     ?? getenv('TWILIO_API_SECRET')     ?: '';
        $this->appUrl             = $dbSettings['app_url']               ?? getenv('APP_URL')               ?: 'https://crm.victorygenomics.com';
        $this->voipEnabled        = ($dbSettings['voip_enabled'] ?? getenv('VOIP_ENABLED') ?: '1') === '1';
        $this->voipRecordingEnabled = ($dbSettings['voip_recording_enabled'] ?? getenv('VOIP_RECORDING_ENABLED') ?: '0') === '1';

        if ($this->accountSid && $this->authToken) {
            $this->client = new Client($this->accountSid, $this->authToken);
        }
    }

    /**
     * Load Twilio/app settings from the database settings table
     */
    private static function loadSettingsFromDB() {
        try {
            $db = Database::getInstance();
            $rows = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (
                'twilio_account_sid','twilio_auth_token','twilio_phone_number','twilio_twiml_app_sid',
                'whatsapp_from_number','twilio_api_key','twilio_api_secret','app_url',
                'voip_recording_enabled','whatsapp_sandbox_mode'
            )")->fetchAll(\PDO::FETCH_KEY_PAIR);
            // Only return keys that have non-empty values (so env fallback works)
            return array_filter($rows, function($v) { return $v !== '' && $v !== null; });
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset singleton so next getInstance() re-reads settings from DB
     */
    public static function resetInstance() {
        self::$instance = null;
    }

    public function getClient() {
        return $this->client;
    }

    public function getAccountSid()        { return $this->accountSid; }
    public function getAuthToken()         { return $this->authToken; }
    public function getPhoneNumber()       { return $this->phoneNumber; }
    public function getTwimlAppSid()       { return $this->twimlAppSid; }
    public function getWhatsappFromNumber(){ return $this->whatsappFromNumber; }
    public function getApiKey()            { return $this->apiKey; }
    public function getApiSecret()         { return $this->apiSecret; }
    public function getAppUrl()            { return $this->appUrl; }
    public function isVoipEnabled()         { return $this->voipEnabled; }
    public function isVoipRecordingEnabled(){ return $this->voipRecordingEnabled; }

    /**
     * Check if Twilio is properly configured
     */
    public function isConfigured() {
        return !empty($this->accountSid) && !empty($this->authToken) && !empty($this->phoneNumber);
    }

    // ─────────────────────────────────────
    // VOICE (VoIP)
    // ─────────────────────────────────────

    /**
     * Generate an Access Token for the Twilio JS Client (WebRTC)
     *
     * IMPORTANT: Twilio Voice SDK 2.x REQUIRES tokens signed by a Standard API Key (SK...).
     * Tokens signed with Account SID + Auth Token are NOT accepted by the Voice SDK,
     * even though they work for other Twilio SDKs. Standard API Keys cannot authenticate
     * to the REST API (always returns 401), but they ARE valid for signing Access Tokens.
     */
    public function generateVoiceToken($identity) {
        if (!$this->isConfigured()) {
            throw new \Exception('Twilio is not configured');
        }

        if (empty($this->apiKey) || empty($this->apiSecret)) {
            throw new \Exception('Twilio API Key and Secret are required for Voice SDK. Please set twilio_api_key and twilio_api_secret in Settings.');
        }

        // Use the API Key directly — do NOT validate it against the REST API
        // (Standard API Keys return 401 on REST API but are valid for signing tokens)
        $token = new AccessToken(
            $this->accountSid,
            $this->apiKey,
            $this->apiSecret,
            3600,      // TTL = 1 hour
            $identity
        );

        $voiceGrant = new VoiceGrant();
        if ($this->twimlAppSid) {
            $voiceGrant->setOutgoingApplicationSid($this->twimlAppSid);
        }
        $voiceGrant->setIncomingAllow(true);
        $token->addGrant($voiceGrant);

        error_log("VoIP token: generated for $identity using API Key " . substr($this->apiKey, 0, 8) . "...");
        return $token->toJWT();
    }

    /**
     * Initiate an outbound call via REST API (server-side)
     */
    public function makeCall($toNumber, $statusCallbackUrl = null) {
        if (!$this->client) {
            throw new \Exception('Twilio client not initialized');
        }
        if (!$this->voipEnabled) {
            throw new \Exception('VoIP is disabled. Enable it in Settings > VoIP & WhatsApp.');
        }

        // Use the public-facing URL for TwiML — Twilio needs an externally reachable URL
        $appUrl = $this->appUrl;

        // The TwiML URL must include the number to dial
        $twimlUrl = $appUrl . '/api/voip.php?action=twiml&number=' . urlencode(TwilioHelper::normalizePhone($toNumber));

        $params = [
            'url'  => $twimlUrl,
        ];

        if ($statusCallbackUrl) {
            $params['statusCallback'] = $statusCallbackUrl;
            $params['statusCallbackEvent'] = ['initiated', 'ringing', 'answered', 'completed'];
            $params['statusCallbackMethod'] = 'POST';
        }

        if ($this->voipRecordingEnabled) {
            $params['record'] = true;
            $params['recordingStatusCallback'] = $appUrl . '/api/voip.php?action=recording_status';
        }

        return $this->client->calls->create(
            TwilioHelper::normalizePhone($toNumber),
            $this->phoneNumber,
            $params
        );
    }

    /**
     * Generate TwiML for outbound call connection
     */
    public function generateCallTwiML($toNumber) {
        $response = new VoiceResponse();
        $dial = $response->dial('', ['callerId' => $this->phoneNumber]);
        $dial->number($toNumber);
        return $response;
    }

    /**
     * Get call details from Twilio
     */
    public function getCallDetails($callSid) {
        if (!$this->client) return null;
        try {
            return $this->client->calls($callSid)->fetch();
        } catch (\Exception $e) {
            error_log("Twilio getCallDetails error: " . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────
    // WHATSAPP
    // ─────────────────────────────────────

    /**
     * Twilio Content Template SIDs for WhatsApp business-initiated messages.
     * These must be approved by Meta before use.
     */
    const WA_TEMPLATES = [
        'notification'         => 'HXaba2d863dba9c67faffba804c0fb92fc', // vg_notification_v2: Hello {{1}}, … Victory Genomics … {{2}} — Please reply …
        'introduction'         => 'HXb03084711a92b6de0eeab13ddab69115', // vg_introduction
        'followup'             => 'HXd39da36e0e16ba0d49e5059ee307a07e', // vg_followup
        'meeting_confirmation' => 'HX985745e93ab91384f56c4975474f5392', // vg_meeting_confirmation
        'thank_you'            => 'HX5283249c37a851f8496624b28c7ea3af', // vg_thank_you
        'lead_assignment'      => 'HX27128e155ccaa99a12b8104648d6200e', // vg_lead_assignment: Hi {{1}}, a new lead … {{2}} … Assigned by: {{3}}
    ];

    /**
     * Check whether a contact is inside the 24-hour customer-service window.
     * Returns true if the recipient sent us an inbound WhatsApp message within the
     * last 24 hours, meaning we can reply with a free-form (Body) message.
     */
    public static function isInsideServiceWindow($toNumber) {
        try {
            $db = Database::getInstance();
            $normalized = self::normalizePhone($toNumber);
            $digits = preg_replace('/[^0-9]/', '', $normalized);
            $last10 = substr($digits, -10);

            $row = $db->query(
                "SELECT MAX(created_at) AS last_inbound
                   FROM whatsapp_messages
                  WHERE direction = 'Inbound'
                    AND REPLACE(REPLACE(from_number,'+',''),'-','') LIKE ?",
                ['%' . $last10]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($row && $row['last_inbound']) {
                $lastInbound = new \DateTime($row['last_inbound']);
                $now = new \DateTime();
                $diff = $now->getTimestamp() - $lastInbound->getTimestamp();
                return $diff < (24 * 3600); // within 24 hours
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Send a WhatsApp message.
     *
     * WhatsApp rules (enforced since April 2025):
     *  - Inside 24h window  → free-form Body is allowed
     *  - Outside 24h window → must use an approved Content Template (ContentSid)
     *
     * @param string      $toNumber   Destination phone in E.164
     * @param string      $body       Message text
     * @param string|null $mediaUrl   Optional media URL
     * @param string|null $contentSid Force a specific Twilio ContentSid
     * @param array|null  $contentVars ContentVariables keyed "1","2",…
     * @param string|null $contactName Lead/contact name for template variable
     */
    public function sendWhatsApp($toNumber, $body, $mediaUrl = null, $contentSid = null, $contentVars = null, $contactName = null) {
        if (!$this->client) {
            throw new \Exception('Twilio client not initialized');
        }

        $from = $this->whatsappFromNumber ?: $this->phoneNumber;
        $fromWA = 'whatsapp:' . $from;
        $toWA   = 'whatsapp:' . self::normalizePhone($toNumber);

        $statusCb = rtrim($this->appUrl, '/') . '/api/whatsapp.php?action=status';

        // Determine if we're inside the 24h service window
        $insideWindow = self::isInsideServiceWindow($toNumber);

        if ($contentSid) {
            // ── Caller explicitly provided a Content Template ──
            $params = [
                'from'             => $fromWA,
                'contentSid'       => $contentSid,
                'statusCallback'   => $statusCb,
            ];
            if ($contentVars) {
                $params['contentVariables'] = json_encode($contentVars);
            }
        } elseif ($insideWindow) {
            // ── Inside 24h window — free-form Body allowed ──
            $params = [
                'from' => $fromWA,
                'body' => $body,
                'statusCallback' => $statusCb,
            ];
            if ($mediaUrl) {
                $params['mediaUrl'] = [$mediaUrl];
            }
        } else {
            // ── Outside 24h window — wrap the message in the notification template ──
            $name = $contactName ?: 'there';
            $params = [
                'from'             => $fromWA,
                'contentSid'       => self::WA_TEMPLATES['notification'],
                'contentVariables' => json_encode(['1' => $name, '2' => $body]),
                'statusCallback'   => $statusCb,
            ];
        }

        try {
            return $this->client->messages->create($toWA, $params);
        } catch (\Twilio\Exceptions\RestException $e) {
            // 63016: outside window AND template not yet approved → give clear message
            if ($e->getCode() == 63016) {
                if (!$insideWindow) {
                    throw new \Exception(
                        'Cannot send free-form WhatsApp message — the 24-hour customer service window has expired. ' .
                        'The contact must message you on WhatsApp first, or use an approved template. ' .
                        'Templates are pending Meta approval; please try again shortly.'
                    );
                }
                throw $e;
            }
            // 63007: Channel not found
            if ($e->getCode() == 63007) {
                throw new \Exception(
                    'WhatsApp channel not found for ' . $from . '. Ensure this number is registered as a WhatsApp Sender in your Twilio Console.'
                );
            }
            throw $e;
        }
    }

    /**
     * Get WhatsApp message status
     */
    public function getMessageStatus($messageSid) {
        if (!$this->client) return null;
        try {
            return $this->client->messages($messageSid)->fetch();
        } catch (\Exception $e) {
            error_log("Twilio getMessageStatus error: " . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────

    /**
     * Normalize phone number to E.164 format
     */
    public static function normalizePhone($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($phone, '+') !== 0) {
            // Assume US if no country code
            if (strlen($phone) === 10) {
                $phone = '+1' . $phone;
            } elseif (strlen($phone) === 11 && $phone[0] === '1') {
                $phone = '+' . $phone;
            } else {
                $phone = '+' . $phone;
            }
        }
        return $phone;
    }

    /**
     * Validate webhook signature from Twilio
     */
    public function validateWebhook($url, $params, $signature) {
        $validator = new \Twilio\Security\RequestValidator($this->authToken);
        return $validator->validate($signature, $url, $params);
    }

    /**
     * Process WhatsApp template variables
     */
    public static function processTemplate($template, $lead, $userName = '') {
        $replacements = [
            '{{contact_name}}'  => $lead['contact_person'] ?? 'there',
            '{{company_name}}'  => $lead['company_name'] ?? '',
            '{{user_name}}'     => $userName,
            '{{lead_type}}'     => $lead['lead_type'] ?? '',
            '{{country}}'       => $lead['country'] ?? '',
            '{{region}}'        => $lead['region'] ?? '',
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    // ─────────────────────────────────────
    // DATABASE HELPERS
    // ─────────────────────────────────────

    /**
     * Log a VoIP call to the database
     */
    public static function logCall($data) {
        $db = Database::getInstance();
        return $db->insert('voip_calls', [
            'lead_id'         => $data['lead_id'] ?? null,
            'user_id'         => $data['user_id'],
            'twilio_call_sid' => $data['call_sid'] ?? null,
            'direction'       => $data['direction'] ?? 'Outbound',
            'from_number'     => $data['from_number'],
            'to_number'       => $data['to_number'],
            'status'          => $data['status'] ?? 'Initiated',
            'started_at'      => $data['started_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Log a WhatsApp message to the database
     */
    public static function logMessage($data) {
        $db = Database::getInstance();
        $row = [
            'lead_id'             => $data['lead_id'] ?? null,
            'user_id'             => $data['user_id'] ?? null,
            'twilio_message_sid'  => $data['message_sid'] ?? null,
            'direction'           => $data['direction'] ?? 'Outbound',
            'from_number'         => $data['from_number'],
            'to_number'           => $data['to_number'],
            'message_body'        => $data['body'] ?? '',
            'media_url'           => $data['media_url'] ?? null,
            'status'              => $data['status'] ?? 'Queued',
            'template_id'         => $data['template_id'] ?? null,
            'sent_at'             => $data['sent_at'] ?? date('Y-m-d H:i:s'),
        ];

        // Store WhatsApp profile name when available (inbound messages)
        if (!empty($data['profile_name'])) {
            $row['profile_name'] = $data['profile_name'];
        }

        return $db->insert('whatsapp_messages', $row);
    }

    /**
     * Send a WhatsApp notification to a user when a lead is assigned to them.
     * Checks the wa_lead_assignment_notify setting and the user's whatsapp_number.
     * Silently fails (logs errors) so it never blocks the main operation.
     *
     * @param int    $assignedUserId  The user_id who just received the lead
     * @param string $leadName        Lead contact person or company name
     * @param int    $leadId          Lead ID (for reference)
     * @param string $assignedByName  Name of the user who made the assignment
     */
    public static function notifyLeadAssignment($assignedUserId, $leadName, $leadId, $assignedByName = '') {
        try {
            $db = Database::getInstance();

            // Check if notification is enabled globally (master switch)
            $setting = $db->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'wa_lead_assignment_notify'"
            )->fetchColumn();
            if ($setting !== '1') {
                return; // Notifications disabled globally
            }

            // Get the assigned user and check per-user opt-in
            $user = $db->findOne('users', ['user_id' => $assignedUserId]);
            if (!$user || intval($user['wa_notify_enabled'] ?? 0) !== 1) {
                return; // User has not opted in to notifications
            }
            if (empty($user['whatsapp_number'])) {
                error_log("WA Lead Assignment: User {$user['full_name']} has notifications enabled but no WhatsApp number");
                return; // No WhatsApp number on file
            }

            $twilio = self::getInstance();
            if (!$twilio->isConfigured()) {
                return;
            }

            $toNumber = self::normalizePhone($user['whatsapp_number']);
            $userName = $user['full_name'] ?? 'there';
            $assigner = $assignedByName ?: 'System';

            // Always use the dedicated lead_assignment template.
            // This is a business-initiated message (sales rep never messages the CRM),
            // so it will ALWAYS be outside the 24h window — free-form won't work.
            $contentSid = self::WA_TEMPLATES['lead_assignment'];
            $contentVars = [
                '1' => $userName,           // Rep name
                '2' => $leadName,           // Lead name / description
                '3' => $assigner,           // Assigned by
            ];

            $from = $twilio->getWhatsappFromNumber() ?: $twilio->getPhoneNumber();
            $fromWA = 'whatsapp:' . $from;
            $toWA   = 'whatsapp:' . $toNumber;
            $statusCb = rtrim($twilio->getAppUrl(), '/') . '/api/whatsapp.php?action=status';

            $message = $twilio->getClient()->messages->create($toWA, [
                'from'             => $fromWA,
                'contentSid'       => $contentSid,
                'contentVariables' => json_encode($contentVars),
                'statusCallback'   => $statusCb,
            ]);

            // Build a readable body for logging
            $bodyForLog = "Hi $userName, a new lead has been assigned to you: $leadName. Assigned by: $assigner. Please follow up as soon as possible. - Victory Genomics CRM";

            // Log the notification — intentionally set lead_id to NULL so it
            // does NOT appear inside the lead's WhatsApp conversation view.
            // The message is sent to the sales-agent, not to the lead.
            self::logMessage([
                'lead_id'     => null,
                'user_id'     => $assignedUserId,
                'message_sid' => $message->sid ?? null,
                'direction'   => 'Outbound',
                'from_number' => $from,
                'to_number'   => $toNumber,
                'body'        => $bodyForLog,
                'status'      => $message->status ?? 'Sent',
                'sent_at'     => date('Y-m-d H:i:s'),
            ]);

            error_log("WA Lead Assignment Notification: Sent to {$user['full_name']} ($toNumber) for lead #$leadId — SID: " . ($message->sid ?? 'N/A'));
        } catch (\Exception $e) {
            // Never let notification failure break the main flow
            error_log("WA Lead Assignment Notification FAILED: " . $e->getMessage());
        }
    }

    /**
     * Find lead by phone number
     */
    public static function findLeadByPhone($phoneNumber) {
        $db = Database::getInstance();
        $normalized = self::normalizePhone($phoneNumber);
        $digits = preg_replace('/[^0-9]/', '', $normalized);
        $last10 = substr($digits, -10);

        // Search phone and mobile fields
        $result = $db->query("
            SELECT * FROM leads 
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?
            LIMIT 1
        ", ['%' . $last10, '%' . $last10]);

        return $result->fetch(\PDO::FETCH_ASSOC);
    }
}
