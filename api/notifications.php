<?php
/**
 * CS Dept CA Portal — Notifications API
 * Shared across student / lecturer / admin dashboards.
 *
 * GET  ?action=list&role=student|lecturer|admin&limit=8
 * POST action=mark_read        (id)
 * POST action=mark_all_read    (role)
 * POST action=create           (title, message, audience, level)   [admin only]
 * POST action=delete           (id)                                 [admin only]
 * POST action=toggle           (id)                                 [admin only]
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

function currentReader() {
    if (isset($_SESSION['admin_id']) && ($_SESSION['admin_role'] ?? '') !== 'lecturer') {
        return ['type' => 'admin', 'key' => (string)$_SESSION['admin_id']];
    }
    if (isset($_SESSION['lecturer_id'])) {
        return ['type' => 'lecturer', 'key' => (string)$_SESSION['lecturer_id']];
    }
    if (isset($_SESSION['authenticated_matric']) || isset($_SESSION['student_matric'])) {
        $m = $_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'];
        return ['type' => 'student', 'key' => (string)$m];
    }
    return null;
}

function isAdminSession() {
    return isset($_SESSION['admin_id']) && ($_SESSION['admin_role'] ?? '') !== 'lecturer';
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');

// ── LIST (also returns unread count) ─────────────────────────────
if ($action === 'list') {
    $reader = currentReader();
    if (!$reader) { echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit; }

    $limit = min(50, max(1, (int)($_GET['limit'] ?? 8)));

    // Build audience filter: 'all' always shown, plus the reader's own audience bucket
    $audienceMap = ['student' => 'students', 'lecturer' => 'lecturers', 'admin' => 'admin'];
    $audience = $audienceMap[$reader['type']] ?? 'all';

    $sql = "SELECT n.id, n.title, n.message, n.audience, n.level, n.created_at,
                   (r.id IS NOT NULL) AS is_read
            FROM notifications n
            LEFT JOIN notification_reads r
                   ON r.notification_id = n.id AND r.reader_type = ? AND r.reader_key = ?
            WHERE n.is_active = 1 AND (n.audience = 'all' OR n.audience = ?)";
    $params = [$reader['type'], $reader['key'], $audience];

    // Optional level targeting for students
    if ($reader['type'] === 'student' && isset($_GET['level'])) {
        $sql .= " AND (n.level IS NULL OR n.level = ?)";
        $params[] = (int)$_GET['level'];
    }

    $sql .= " ORDER BY n.created_at DESC LIMIT " . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $unreadStmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications n
        LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.reader_type = ? AND r.reader_key = ?
        WHERE n.is_active = 1 AND (n.audience = 'all' OR n.audience = ?) AND r.id IS NULL
    ");
    $unreadStmt->execute([$reader['type'], $reader['key'], $audience]);
    $unread = (int)$unreadStmt->fetchColumn();

    echo json_encode(['success' => true, 'notifications' => $rows, 'unread' => $unread]);
    exit;
}

// ── MARK READ ─────────────────────────────────────────────────────
if ($action === 'mark_read' && $method === 'POST') {
    $reader = currentReader();
    if (!$reader) { echo json_encode(['success' => false]); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, reader_type, reader_key) VALUES (?, ?, ?)");
        $stmt->execute([$id, $reader['type'], $reader['key']]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_all_read' && $method === 'POST') {
    $reader = currentReader();
    if (!$reader) { echo json_encode(['success' => false]); exit; }
    $audienceMap = ['student' => 'students', 'lecturer' => 'lecturers', 'admin' => 'admin'];
    $audience = $audienceMap[$reader['type']] ?? 'all';
    $ids = $pdo->prepare("SELECT id FROM notifications WHERE is_active = 1 AND (audience = 'all' OR audience = ?)");
    $ids->execute([$audience]);
    $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, reader_type, reader_key) VALUES (?, ?, ?)");
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $nid) {
        $stmt->execute([$nid, $reader['type'], $reader['key']]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── ADMIN: CREATE / DELETE / TOGGLE ──────────────────────────────
if ($action === 'create' && $method === 'POST') {
    if (!isAdminSession()) { echo json_encode(['success' => false, 'message' => 'Admins only']); exit; }
    $title    = trim($_POST['title'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    $audience = in_array($_POST['audience'] ?? '', ['all','students','lecturers'], true) ? $_POST['audience'] : 'all';
    $level    = ($_POST['level'] ?? '') !== '' ? (int)$_POST['level'] : null;
    if ($title === '' || $message === '') { echo json_encode(['success' => false, 'message' => 'Title and message are required']); exit; }

    $stmt = $pdo->prepare("INSERT INTO notifications (title, message, audience, level, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$title, $message, $audience, $level, $_SESSION['admin_id']]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($action === 'delete' && $method === 'POST') {
    if (!isAdminSession()) { echo json_encode(['success' => false, 'message' => 'Admins only']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'toggle' && $method === 'POST') {
    if (!isAdminSession()) { echo json_encode(['success' => false, 'message' => 'Admins only']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE notifications SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
