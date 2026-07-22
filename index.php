<?php
/**
 * VGold docroot bootstrap.
 *
 * The SiteGround subdomain document root is this directory, but the real front
 * controller lives in public/. Rather than depend solely on .htaccess rewrites
 * (which can hit DirectoryIndex/-Indexes 403 edge cases for "/"), this tiny
 * shim guarantees the homepage and any request that lands here is handed to the
 * public/ front controller. .htaccess still handles asset passthrough and the
 * /api + /crm routing; this only covers the bare-root / DirectoryIndex case.
 */
require __DIR__ . '/public/index.php';
