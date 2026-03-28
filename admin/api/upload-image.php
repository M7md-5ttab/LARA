<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

admin_require_auth_api();
admin_require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!isset($_FILES['image'])) {
    admin_json(['ok' => false, 'error' => 'Missing file'], 400);
}

$file = $_FILES['image'];
if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'], $file['name'])) {
    admin_json(['ok' => false, 'error' => 'Invalid upload'], 400);
}

if ((int) $file['error'] !== UPLOAD_ERR_OK) {
    admin_json(['ok' => false, 'error' => 'Upload failed'], 400);
}

$maxBytes = (int) (Env::get('ADMIN_UPLOAD_MAX_BYTES', '2097152') ?? '2097152');
$size = (int) $file['size'];
if ($size <= 0 || $size > $maxBytes) {
    admin_json(['ok' => false, 'error' => "File too large (max {$maxBytes} bytes)"], 400);
}

$tmp = (string) $file['tmp_name'];
if (!is_uploaded_file($tmp)) {
    admin_json(['ok' => false, 'error' => 'Invalid upload source'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp) ?: '';

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (!isset($allowed[$mime])) {
    admin_json(['ok' => false, 'error' => 'Invalid file type. Allowed: jpg, png, webp'], 400);
}

// Additional image validation
if (@getimagesize($tmp) === false) {
    admin_json(['ok' => false, 'error' => 'File is not a valid image'], 400);
}

$ext = $allowed[$mime];
$uploadDir = PROJECT_ROOT . '/uploads/menu';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        admin_json(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
    }
}

$safeName = 'menu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($tmp, $dest)) {
    admin_json(['ok' => false, 'error' => 'Failed to store uploaded file'], 500);
}

$relativeUrl = 'uploads/menu/' . $safeName;
admin_json(['ok' => true, 'url' => $relativeUrl]);
