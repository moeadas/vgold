<?php
// VGold — Web Push sender using minishlink/web-push
require_once __DIR__ . '/../../vendor/autoload.php';
// Push config holds the VAPID keypair. It is optional: if absent, push is disabled
// gracefully rather than fataling the whole API (push is non-critical).
if (file_exists(__DIR__ . '/../../config/push.php')) {
    require_once __DIR__ . '/../../config/push.php';
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class Push {
    /** Web push is only available when the VAPID keypair is configured. */
    public static function enabled() {
        return defined('VAPID_PUBLIC') && defined('VAPID_PRIVATE') && defined('VAPID_SUBJECT')
            && VAPID_PUBLIC && VAPID_PRIVATE;
    }

    public static function toUser($userId, $title, $body, $link = '/') {
        if (!self::enabled()) return;
        $subs = DB::fetchAll("SELECT endpoint, auth_keys FROM push_subscriptions WHERE user_id = ?", [$userId]);
        if (!$subs) return;

        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC,
                'privateKey' => VAPID_PRIVATE,
            ],
        ];

        try {
            $webPush = new WebPush($auth);
            $webPush->setDefaultOptions(['TTL' => 86400]);
            $payload = json_encode(['title' => $title, 'body' => $body, 'link' => $link, 'tag' => 'vgo-' . time()]);

            foreach ($subs as $s) {
                $keys = json_decode($s['auth_keys'], true) ?: [];
                $sub = Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'keys' => [
                        'p256dh' => $keys['p256dh'] ?? '',
                        'auth' => $keys['auth'] ?? '',
                    ],
                ]);
                $webPush->queueNotification($sub, $payload);
            }

            // Flush and prune dead subscriptions (410 Gone / 404)
            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                    if ($code === 410 || $code === 404) {
                        DB::query("DELETE FROM push_subscriptions WHERE endpoint = ?", [(string)$report->getEndpoint()]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Push is non-critical — swallow errors
            error_log('Push error: ' . $e->getMessage());
        }
    }
}