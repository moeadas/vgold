<?php
/**
 * Victory Genomics CRM — Media Upload API
 * Accepts file uploads for WhatsApp media attachments.
 * Returns a public URL that Twilio can fetch when sending the message.
 *
 * Twilio requires media to be publicly accessible via HTTPS URL.
 * Supported: images (jpg/png/gif/webp), PDFs, documents (doc/docx/xls/xlsx/ppt/pptx), audio, video
 * Max file size: 16MB (Twilio WhatsApp limit)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF check
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page.']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'message' => $errMap[$code] ?? 'Upload failed.']);
    exit;
}

$file = $_FILES['file'];
$maxSize = 16 * 1024 * 1024; // 16MB — Twilio WhatsApp limit

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum 16MB for WhatsApp media.']);
    exit;
}

// Allowed MIME types for WhatsApp media
$allowedTypes = [
    // Images
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    // Documents
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain',
    'text/csv',
    // Audio
    'audio/mpeg', 'audio/ogg', 'audio/amr', 'audio/aac',
    // Video
    'video/mp4', 'video/3gpp',
];

$detectedType = mime_content_type($file['tmp_name']) ?: $file['type'];
if (!in_array($detectedType, $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'File type not supported for WhatsApp. Allowed: images, PDF, Office documents, audio, video.']);
    exit;
}

// Determine file extension from original name
$originalName = basename($file['name']);
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$safeExts = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','mp3','ogg','amr','aac','mp4','3gp'];
if (!in_array($ext, $safeExts)) {
    // Fallback extension from MIME
    $mimeExtMap = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
    ];
    $ext = $mimeExtMap[$detectedType] ?? 'bin';
}

// Create upload directory
$uploadDir = __DIR__ . '/../uploads/wa-media/' . date('Y-m');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$uniqueName = 'wa_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destPath = $uploadDir . '/' . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    exit;
}

// Build the public URL
$relativePath = '/uploads/wa-media/' . date('Y-m') . '/' . $uniqueName;
$publicUrl = rtrim(APP_URL, '/') . $relativePath;

// Determine media category for UI display
$isImage = strpos($detectedType, 'image/') === 0;
$isVideo = strpos($detectedType, 'video/') === 0;
$isAudio = strpos($detectedType, 'audio/') === 0;
$category = $isImage ? 'image' : ($isVideo ? 'video' : ($isAudio ? 'audio' : 'document'));

echo json_encode([
    'success'       => true,
    'url'           => $publicUrl,
    'relative_path' => $relativePath,
    'filename'      => $originalName,
    'size'          => $file['size'],
    'mime_type'     => $detectedType,
    'category'      => $category,
    'message'       => 'File uploaded successfully.',
]);
