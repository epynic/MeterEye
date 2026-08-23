<?php
require_once getenv('EB_CONFIG') ?: __DIR__ . '/../config.php';

header('Content-Type: application/json');

// API key check — include as header X-API-Key or ?key= param
$provided = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if (!hash_equals(UPLOAD_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$maxBytes = 10 * 1024 * 1024;

if (str_contains($contentType, 'multipart/form-data')) {
    if (empty($_FILES['image']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No image field']);
        exit;
    }
    $tmp  = $_FILES['image']['tmp_name'];
    $size = $_FILES['image']['size'];
    $isUpload = true;
} else {
    // Raw body (e.g. application/octet-stream)
    $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    $size = strlen($body);
    $tmp  = tempnam(sys_get_temp_dir(), 'eb_');
    file_put_contents($tmp, $body);
    $isUpload = false;
}

if ($size === 0 || $size > $maxBytes) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid size']);
    exit;
}

// Validate JPEG magic bytes (FF D8 FF)
$fh    = fopen($tmp, 'rb');
$magic = fread($fh, 3);
fclose($fh);
if ($magic !== "\xFF\xD8\xFF") {
    if (!$isUpload) unlink($tmp);
    http_response_code(400);
    echo json_encode(['error' => 'Not a JPEG']);
    exit;
}

$filename = date('Y-m-d_His') . '_' . substr(uniqid(), -6) . '.jpg';
$dest     = IMAGES_DIR . $filename;

if ($isUpload) {
    move_uploaded_file($tmp, $dest);
} else {
    rename($tmp, $dest);
}

// Make readable by the OCR worker / analysis tools (already public via nginx).
@chmod($dest, 0644);

http_response_code(201);
echo json_encode(['status' => 'ok', 'filename' => $filename]);
