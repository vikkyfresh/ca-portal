<?php
/**
 * CS Dept CA Portal - Secure Grading Engine
 * Path: api/process-results.php
 */
session_start();
require_once '../includes/config.php';

// 1. SECURITY: Prevent direct URL access or unauthorized attempts
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Direct access not allowed.");
}

if (!isset($_SESSION['verified']) || $_SESSION['verified'] !== true) {
    die("Unauthorized session.");
}

// 2. INPUT VALIDATION
$testId  = (int)($_POST['test_id'] ?? 0);
$matric  = $_SESSION['authenticated_matric'];
$rawAnswers = $_POST['student_answers'] ?? '{}';
$answers = json_decode($rawAnswers, true);

if ($testId === 0 || empty($matric)) {
    die("Invalid request parameters.");
}

try {
    // 3. PREVENT DOUBLE SUBMISSION
    // Check if the student has already submitted this specific test
    $checkStmt = $pdo->prepare("SELECT id FROM results WHERE matric = ? AND test_id = ?");
    $checkStmt->execute([$matric, $testId]);
    if ($checkStmt->fetch()) {
        header("Location: ../dashboard.php?error=already_submitted");
        exit;
    }

    // 4. FETCH CORRECT ANSWERS FROM DB
    // We only fetch the ID and the correct letter (a, b, c, or d)
    $stmt = $pdo->prepare("SELECT id, correct_option FROM questions WHERE test_id = ?");
    $stmt->execute([$testId]);
    $correctTable = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Returns array like [question_id => 'a']

    $totalQuestions = count($correctTable);
    $score = 0;

    // 5. GRADING LOGIC
    // Map the JS indices (0, 1, 2, 3) to DB letters (a, b, c, d)
    $mapping = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];

    foreach ($correctTable as $qId => $correctKey) {
        if (isset($answers[$qId])) {
            $studentIndex = $answers[$qId];
            $studentLetter = $mapping[$studentIndex] ?? null;

            if ($studentLetter === strtolower($correctKey)) {
                $score++;
            }
        }
    }

    // 6. SAVE RESULTS TO DATABASE
    $insert = $pdo->prepare("
        INSERT INTO results (matric, test_id, score, total_questions, date_taken) 
        VALUES (:matric, :test_id, :score, :total, NOW())
    ");
    
    $insert->execute([
        ':matric' => $matric,
        ':test_id' => $testId,
        ':score' => $score,
        ':total' => $totalQuestions
    ]);

    // 7. REDIRECT TO SUCCESS PAGE
    // We pass the test_id so result.php can show the breakdown
    header("Location: ../result.php?test_id=" . $testId);
    exit;

} catch (PDOException $e) {
    error_log("Grading Error: " . $e->getMessage());
    die("A database error occurred during grading.");
}