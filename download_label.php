<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
include 'includes/db.php';

$file     = basename($_GET['file'] ?? '');
$token    = $_GET['token'] ?? '';
$orderId  = intval($_GET['order_id'] ?? 0);
$expires  = intval($_GET['expires'] ?? 0);

if (
    empty($file) || empty($token)
    || empty($orderId) || empty($expires)
) {
    http_response_code(404);
    exit('Not found.');
}

// Reject expired links (24 hour window)
if (time() > $expires) {
    http_response_code(403);
    exit('This link has expired.');
}

// Validate token — sha256 of file + order + expiry + secret
$expectedToken = hash(
    'sha256',
    $file . $orderId . $expires . STRIPE_SECRET_KEY
);

if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    exit('Access denied.');
}

// Check file exists
$filePath = __DIR__ . '/labels/' . $file;
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found.');
}

// Serve the file
$ext = strtolower(pathinfo(
    $file,
    PATHINFO_EXTENSION
));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="'
    . $file . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit();
