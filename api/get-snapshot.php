<?php
/**
 * api/get-snapshot.php
 * Securely serves webcam snapshot images for proctoring detail pages.
 * Only accessible by logged-in lecturers or admins.
 */
session_start();
$isLecturer = isset($_SESSION['lecturer_id']);
$isAdmin    = isset($_SESSION['admin_id']);

if (!$isLecturer && !$isAdmin) {
    http_response_code(403);
    exit('Forbidden');
}

$file = $_GET['file'] ?? '';

// Sanitise — only allow alphanumeric, underscore, dash, dot, slash within uploads/snapshots/
$file = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $file);
if (strpos($file, '..') !== false) { http_response_code(400); exit('Bad request'); }

// Must be inside uploads/snapshots/
if (!str_starts_with($file, 'uploads/snapshots/')) {
    http_response_code(403); exit('Forbidden');
}

$fullPath = dirname(__DIR__) . '/' . $file;

if (!file_exists($fullPath)) {
    http_response_code(404); exit('Not found');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = match($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'webp'        => 'image/webp',
    default       => 'application/octet-stream'
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
readfile($fullPath);
?>
