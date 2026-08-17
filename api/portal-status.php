<?php
/**
 * Lightweight, unauthenticated status check used for live polling.
 * Returns whether the given role is currently blocked, and why.
 * GET /api/portal-status.php?role=student|lecturer
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

$role = $_GET['role'] ?? '';
if (!in_array($role, ['student', 'lecturer'], true)) {
    echo json_encode(['blocked' => false, 'error' => 'invalid_role']);
    exit;
}

$block = getAccessBlock($role);

if ($block) {
    echo json_encode([
        'blocked' => true,
        'variant' => $block['variant'],
        'title'   => $block['title'],
        'message' => $block['message'],
    ]);
} else {
    echo json_encode(['blocked' => false]);
}
