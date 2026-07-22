<?php
// The email builder posts its image as `image`; reuse the hardened CRM media
// uploader, which expects `file` and returns the same {success,url} contract.
if (isset($_FILES['image']) && !isset($_FILES['file'])) $_FILES['file'] = $_FILES['image'];
require __DIR__ . '/upload-media.php';
