<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

// Lecturer photo
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['lecturer_name'] ?? 'Lecturer') . '&background=1e3a8a&color=fff&size=80&bold=true';
if (!empty($_SESSION['lecturer_id'])) {
    $stmtPhoto = $pdo->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
    $stmtPhoto->execute([$_SESSION['lecturer_id']]);
    $photoRow = $stmtPhoto->fetch();
    if (!empty($photoRow['photo'])) {
        $sp = dirname(__DIR__) . '/' . ltrim($photoRow['photo'], '/');
        if (file_exists($sp)) $photoSrc = '../' . ltrim($photoRow['photo'], '/');
    }
}
$lecturerAvatarUrl = $photoSrc;
$avatarUrl = $photoSrc;


$lecturerId = $_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'] ?? 'Lecturer';
$testId = intval($_GET['id'] ?? 0);

// Fetch test details
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
$stmt->execute([$testId, $lecturerId]);
$test = $stmt->fetch();

if (!$test) {
    die("Test not found or you don't have permission.");
}

$courseCode = $test['course_code'];
$limit = (int)$test['total_questions'];
if ($limit <= 0) $limit = 20;

// Count available questions
$totalAvailStmt = $pdo->prepare("SELECT COUNT(*) FROM course_questions WHERE course_code = ?");
$totalAvailStmt->execute([$courseCode]);
$totalAvailable = $totalAvailStmt->fetchColumn();

// Fetch questions for preview (show all or limited)
$stmt = $pdo->prepare("SELECT * FROM course_questions WHERE course_code = ? ORDER BY id LIMIT " . (int)$limit);
$stmt->execute([$courseCode]);
$questions = $stmt->fetchAll();

$totalQuestions = count($questions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Test - <?= htmlspecialchars($test['course_code']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* ── Sidebar ── */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */ /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */ /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */ /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        /* → includes/sidebar.php */
        /* → includes/sidebar.php */
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: .9rem; display: flex; align-items: center; gap: 6px; }
        .content { padding: 24px; max-width: 900px; }
        
        .preview-banner { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 20px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; color: #92400e; }
        
        .stats-bar { background: white; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); display: flex; gap: 32px; flex-wrap: wrap; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: #0f172a; }
        .stat-label { color: #64748b; font-size: .8rem; }
        
        .question-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-left: 4px solid #1e3a8a; }
        .question-number { font-weight: 700; color: #1e3a8a; margin-bottom: 8px; }
        .question-text { font-weight: 600; margin-bottom: 12px; color: #0f172a; }
        .options { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .option { padding: 8px 12px; background: #f8fafc; border-radius: 8px; font-size: .9rem; }
        .option.correct { background: #d1fae5; border: 1px solid #10b981; font-weight: 600; }
        
        .empty-state { text-align: center; padding: 60px; color: #64748b; }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 12px; opacity: .4; }
        
        @media (max-width: 768px) { /* → includes/sidebar.php */ .main { margin-left: 0; } .options { grid-template-columns: 1fr; } .stats-bar { gap: 16px; } }
    </style>
</head>
<body>
<div class="layout">
    <?php $activePage='preview-test'; require_once __DIR__.'/includes/sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <h1>Preview: <?= htmlspecialchars($test['course_code']) ?> - <?= htmlspecialchars($test['test_title']) ?></h1>
            <a href="tests.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Tests</a>
        </div>
        <div class="content">
            
            <div class="preview-banner">
                <i class="fas fa-eye"></i>
                <div>
                    <strong>Preview Mode</strong><br>
                    <small>This is how students will see the test. Correct answers are highlighted in green. Students will see <?= $limit ?> random questions from <?= $totalAvailable ?> available.</small>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-bar">
                <div class="stat-item"><div class="stat-value"><?= $totalAvailable ?></div><div class="stat-label">Total Questions in Bank</div></div>
                <div class="stat-item"><div class="stat-value"><?= $limit ?></div><div class="stat-label">Questions Per Student</div></div>
                <div class="stat-item"><div class="stat-value"><?= $test['duration_minutes'] ?> min</div><div class="stat-label">Duration</div></div>
                <div class="stat-item"><div class="stat-value"><?= $test['level'] ?>L</div><div class="stat-label">Level</div></div>
            </div>
            
            <?php if (count($questions) > 0): ?>
                <?php foreach ($questions as $index => $q): ?>
                <div class="question-card">
                    <div class="question-number">Question <?= $index + 1 ?></div>
                    <div class="question-text"><?= htmlspecialchars($q['question']) ?></div>
                    <div class="options">
                        <div class="option <?= $q['correct_option'] === 'A' ? 'correct' : '' ?>">
                            <strong>A.</strong> <?= htmlspecialchars($q['option_a']) ?>
                            <?= $q['correct_option'] === 'A' ? ' ✅' : '' ?>
                        </div>
                        <div class="option <?= $q['correct_option'] === 'B' ? 'correct' : '' ?>">
                            <strong>B.</strong> <?= htmlspecialchars($q['option_b']) ?>
                            <?= $q['correct_option'] === 'B' ? ' ✅' : '' ?>
                        </div>
                        <div class="option <?= $q['correct_option'] === 'C' ? 'correct' : '' ?>">
                            <strong>C.</strong> <?= htmlspecialchars($q['option_c']) ?>
                            <?= $q['correct_option'] === 'C' ? ' ✅' : '' ?>
                        </div>
                        <div class="option <?= $q['correct_option'] === 'D' ? 'correct' : '' ?>">
                            <strong>D.</strong> <?= htmlspecialchars($q['option_d']) ?>
                            <?= $q['correct_option'] === 'D' ? ' ✅' : '' ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle"></i>
                    <h3>No Questions Found</h3>
                    <p>This test has no questions in the question bank yet.</p>
                    <a href="questions.php?course=<?= urlencode($test['course_code']) ?>" class="btn" style="margin-top:12px; background:#0f172a; color:white; padding:10px 20px; border-radius:8px; text-decoration:none; display:inline-block;">Add Questions</a>
                </div>
            <?php endif; ?>
            
        </div>
    </main>
</div>
</body>
</html>