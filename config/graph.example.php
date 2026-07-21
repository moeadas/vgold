<?php
// Copy this file to config/graph.php and fill in real values.
// config/graph.php is gitignored and MUST NOT be committed.
//
// VGold supports TWO Microsoft identity flows:
//   1) User login  — OIDC authorization-code (openid/profile/email/User.Read)
//   2) App-only     — Microsoft Graph for SharePoint file storage + Mail.Send
//
// App-only auth can use EITHER a certificate (recommended, as VGO does) OR a
// client secret. Set 'app_auth' to 'certificate' or 'secret' accordingly.
return [
 'tenant_id' => 'YOUR_TENANT_ID',
 'client_id' => 'YOUR_CLIENT_ID',
 'object_id' => 'YOUR_OBJECT_ID',

 // ---- App-only auth method: 'certificate' or 'secret' ----
 'app_auth' => 'certificate',

 // Certificate (private key delivered separately; store OUTSIDE web root, chmod 600)
 'cert_key_path' => __DIR__ . '/certs/vgold_private.key',
 'cert_thumbprint' => 'YOUR_CERT_THUMBPRINT',

 // Client secret (used when app_auth = 'secret'). Store the SECRET VALUE, not the secret ID.
 'client_secret' => '',
 'client_secret_id' => '',

 // SharePoint target
 'site_url' => 'https://YOURTENANT.sharepoint.com/sites/YOUR_SITE',
 'site_id' => 'YOUR_SITE_ID',
 'drive_id' => 'YOUR_DRIVE_ID', // resolve once at setup and paste here

 // OAuth / login
 'redirect_uri' => 'https://vgold.victorygenomics.com/api/auth/microsoft/callback',
 'login_authority' => 'https://login.microsoftonline.com/YOUR_TENANT_ID',

 'graph_base' => 'https://graph.microsoft.com/v1.0',
 'upload_root' => 'AppFiles', // top folder inside the library
 'max_upload_bytes' => 2147483648, // 2 GB app cap
];
