<?php
session_start();
header('Content-Type: application/json');
require_once '../../includes/config.php';

if (!isset($_SESSION['lecturer_id'])) { echo json_encode(['success' => false]); exit; }

$lecturerId = $_SESSION['lecturer_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'list') {
    $testId = intval($_GET['test_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT q.* FROM questions q JOIN tests t ON q.test_id = t.id WHERE q.test_id = ? AND t.created_by = ? ORDER BY q.id");
    $stmt->execute([$testId, $lecturerId]);
    echo json_encode(['success' => true, 'questions' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'add') {
    guardLecturerWriteJson();
    $testId = intval($_POST['test_id'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, option_a, option_b, option_c, option_d, correct_option, difficulty, explanation) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$testId, $_POST['question_text'], $_POST['option_a'], $_POST['option_b'], $_POST['option_c'], $_POST['option_d'], strtoupper($_POST['correct_option'] ?? 'A'), $_POST['difficulty'] ?? 'medium', $_POST['explanation'] ?? '']);
    echo json_encode(['success' => true, 'message' => 'Question added']);
    exit;
}

if ($action === 'delete') {
    guardLecturerWriteJson();
    $id = intval($_POST['question_id'] ?? 0);
    $stmt = $pdo->prepare("DELETE q FROM questions q JOIN tests t ON q.test_id = t.id WHERE q.id = ? AND t.created_by = ?");
    $stmt->execute([$id, $lecturerId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false]);
?>