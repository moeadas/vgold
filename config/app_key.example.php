<?php
// Copy to config/app_key.php and set a long random string (e.g. `openssl rand -base64 48`).
// config/app_key.php is gitignored and used to encrypt secrets at rest (Crypto.php).
// If this file is missing, a key is derived from the DB credentials as a fallback.
return 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';
