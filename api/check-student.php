<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/config.php';

$matric = $_GET['matric'] ?? '';

if (empty($matric)) {
    echo json_encode(['exists' => false, 'message' => 'Matric number required']);
    exit;
}
// ── PORTAL CONTROL CHECK ────────────────────────────────────
$stmtP = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('portal_open','students_blocked','portal_closed_message')");
$portalSettings = $stmtP->fetchAll(PDO::FETCH_KEY_PAIR);
$portalOpen      = ($portalSettings['portal_open']      ?? '1') === '1';
$studentsBlocked = ($portalSettings['students_blocked'] ?? '0') === '1';
$closedMsg       = $portalSettings['portal_closed_message'] ?? 'The portal is currently closed. Please check back later.';

// Trim whitespace from DB values to handle any formatting issues
$portalOpen      = trim($portalSettings['portal_open']      ?? '1') === '1';
$studentsBlocked = trim($portalSettings['students_blocked'] ?? '0') === '1';

if (!$portalOpen) {
    echo json_encode(['exists' => false, 'portal_closed' => true, 'message' => $closedMsg]);
    exit;
}
if ($studentsBlocked) {
    echo json_encode(['exists' => false, 'portal_closed' => true, 'message' => 'Student access is currently restricted. Please check back later.']);
    exit;
}
// ────────────────────────────────────────────────────────────



$stmt = $pdo->prepare("SELECT matric, full_name, level, face_descriptor, session_token, session_token_created_at FROM students WHERE matric = ?");
$stmt->execute([$matric]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['exists' => false, 'message' => 'Matric number not found']);
    exit;
}

// Bind this session to the matric that was just legitimately looked up, as
// early as possible — this is what lets face-enroll-required.php confirm a
// student arriving there genuinely came through this login step, even
// before we know whether their face is enrolled yet.
$_SESSION['pending_verify_matric'] = $student['matric'];

// ── STALE LOCK CLEANUP ──────────────────────────────────────
// A session_token with no timestamp (pre-migration rows) or one older than
// SESSION_LOCK_TIMEOUT_MINUTES is treated as abandoned — most likely a
// browser closed mid-test, a crash, or a lost connection rather than an
// actually-active session — and is auto-released so the student isn't
// permanently locked out. This runs before the concurrent-session check below.
if (!empty($student['session_token'])) {
    $createdAt = $student['session_token_created_at'] ?? null;
    $isStale = !$createdAt || (strtotime($createdAt) < strtotime('-' . SESSION_LOCK_TIMEOUT_MINUTES . ' minutes'));
    if ($isStale) {
        $pdo->prepare("UPDATE students SET session_token = NULL, session_token_created_at = NULL WHERE matric = ?")
            ->execute([$matric]);
        $student['session_token'] = null;
    }
}
// ────────────────────────────────────────────────────────────

// ✅ CHECK: Has face been enrolled?
if (empty($student['face_descriptor'])) {
    echo json_encode([
        'exists' => true,
        'face_enrolled' => false,
        'matric' => $student['matric'],
        'name' => $student['full_name'],
        'level' => $student['level'],
        'message' => 'Face not enrolled. Please contact admin for face enrollment.'
    ]);
    exit;
}

// ── CONCURRENT SESSION CHECK ────────────────────────────────
if (!empty($student['session_token'])) {
    echo json_encode([
        'exists'          => true,
        'face_enrolled'   => true,
        'session_active'  => true,
        'matric'          => $student['matric'],
        'name'            => $student['full_name'],
        'level'           => $student['level'],
        'message'         => 'A session is already active for this matric number. Only one login is allowed at a time.'
    ]);
    exit;
}
// ────────────────────────────────────────────────────────────

echo json_encode([
    'exists'         => true,
    'face_enrolled'  => true,
    'session_active' => false,
    'matric'         => $student['matric'],
    'name'           => $student['full_name'],
    'level'          => $student['level']
]);
?>