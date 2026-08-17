<?php
session_start();
require_once 'includes/config.php';

// ── CLEAR SESSION TOKEN FROM DB (release concurrent login lock) ──
if (!empty($_SESSION['student_matric'])) {
    try {
        $stmt = $pdo->prepare("UPDATE students SET session_token = NULL, session_token_created_at = NULL WHERE matric = ? AND session_token = ?");
        $stmt->execute([$_SESSION['student_matric'], $_SESSION['session_token'] ?? '']);
    } catch (Exception $e) { /* silent fail */ }

    logAudit('student_logout', 'student', $_SESSION['student_matric'], $_SESSION['student_name'] ?? null,
        ($_SESSION['student_name'] ?? $_SESSION['student_matric']) . ' logged out.');
}
// ────────────────────────────────────────────────────────────

session_destroy();
header('Location: index.php?message=logged_out');
exit;
?>
