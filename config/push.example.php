<?php
// Copy to config/push.php and fill with a VAPID keypair. GITIGNORED.
// Generate with:
//   php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
define('VAPID_SUBJECT', 'mailto:support@victorygenomics.com');
define('VAPID_PUBLIC', 'YOUR_VAPID_PUBLIC_KEY');
define('VAPID_PRIVATE', 'YOUR_VAPID_PRIVATE_KEY');
