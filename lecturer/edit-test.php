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
$message = '';
$error = '';

// Fetch test
$stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
$stmt->execute([$testId, $lecturerId]);
$test = $stmt->fetch();

if (!$test) {
    header('Location: tests.php');
    exit;
}

// Check if test has submissions
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE test_id = ?");
$stmt->execute([$testId]);
$hasSubmissions = $stmt->fetchColumn() > 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasSubmissions) {
    if (isLecturerRestricted($lecturerId)) {
        $message = '🔒 ' . LECTURER_RESTRICTED_MESSAGE;
    } else {
    $courseCode = $_POST['course_code'] ?? $test['course_code'];
    $courseTitle = $_POST['course_title'] ?? $test['course_title'];
    $testTitle = $_POST['test_title'] ?? $test['test_title'];
    $level = intval($_POST['level'] ?? $test['level']);
    $duration = intval($_POST['duration_minutes'] ?? $test['duration_minutes']);
    $totalQuestions = intval($_POST['total_questions'] ?? $test['total_questions']);
    $timePerQuestion = intval($_POST['time_per_question'] ?? $test['time_per_question']);
    $startDate = $_POST['start_date'] ?? $test['start_date'];
    $expiryDate = $_POST['expiry_date'] ?? $test['expiry_date'];
    $maxAttempts = intval($_POST['max_attempts'] ?? $test['max_attempts']);
    $passingScore = 50; // Fixed portal-wide standard — same for every course, no longer editable per test.
    $requireFaceVerify = isset($_POST['require_face_verify']) ? 1 : 0;
    $shuffleQuestions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffleOptions = isset($_POST['shuffle_options']) ? 1 : 0;
    $showResultsImmediately = isset($_POST['show_results_immediately']) ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE tests SET course_code=?, course_title=?, test_title=?, level=?, duration_minutes=?, 
                          total_questions=?, time_per_question=?, start_date=?, expiry_date=?, max_attempts=?, 
                          passing_score=?, require_face_verify=?, shuffle_questions=?, shuffle_options=?, 
                          show_results_immediately=? WHERE id=? AND created_by=?");
    $stmt->execute([$courseCode, $courseTitle, $testTitle, $level, $duration, $totalQuestions, $timePerQuestion,
                   $startDate, $expiryDate, $maxAttempts, $passingScore, $requireFaceVerify, $shuffleQuestions,
                   $shuffleOptions, $showResultsImmediately, $testId, $lecturerId]);
    
    $message = "✅ Test updated successfully!";
    
    // Refresh test data
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND created_by = ?");
    $stmt->execute([$testId, $lecturerId]);
    $test = $stmt->fetch();
    }
}

// Get courses for dropdown
$courses = $pdo->prepare("SELECT * FROM lecturer_courses WHERE lecturer_id = ? ORDER BY level");
$courses->execute([$lecturerId]);
$courses = $courses->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Test - Lecturer Portal</title>
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
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 50; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.9rem; display: flex; align-items: center; gap: 6px; }
        .content { padding: 24px; max-width: 800px; }
        
        .alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h3 { margin-bottom: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; }
        .card h3 i { color: #1e3a8a; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 4px; color: #475569; font-weight: 500; font-size: 0.9rem; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; font-family: inherit; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #1e3a8a; }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .form-group small { color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; padding: 8px 0; color: #475569; cursor: pointer; font-size: 0.9rem; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e3a8a; }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; text-decoration: none; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        
        .menu-toggle { display: none; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #475569; }
        
        @media (max-width: 768px) { /* → includes/sidebar.php */ /* → includes/sidebar.php */ .main { margin-left: 0; } .menu-toggle { display: block; } .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="layout">
            <?php $activePage='edit-test'; require_once __DIR__.'/includes/sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <div style="display:flex; align-items:center; gap:16px;">
                <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
                <h1>Edit Test</h1>
            </div>
            <a href="tests.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Tests</a>
        </div>
        <div class="content">
            
            <?php if ($message): ?>
            <div class="alert <?= strpos($message, '🔒') !== false ? 'alert-danger' : 'alert-success' ?>"><i class="fas fa-check-circle"></i> <?= $message ?></div>
            <?php endif; ?>
            
            <?php if ($hasSubmissions): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>This test has <?= $stmt->fetchColumn() ?> student submission(s).</strong> Editing is disabled to protect data integrity.
            </div>
            <?php endif; ?>
            
            <form method="post">
                <!-- Basic Information -->
                <div class="card">
                    <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Course *</label>
                            <select name="course_code" <?= $hasSubmissions ? 'disabled' : '' ?>>
                                <?php foreach ($courses as $c): ?>
                                <option value="<?= htmlspecialchars($c['course_code']) ?>" <?= $test['course_code'] === $c['course_code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['course_code']) ?> - <?= htmlspecialchars($c['course_title']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Course Title</label>
                            <input type="text" name="course_title" value="<?= htmlspecialchars($test['course_title']) ?>" <?= $hasSubmissions ? 'readonly' : '' ?>>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Test Title *</label>
                        <input type="text" name="test_title" value="<?= htmlspecialchars($test['test_title']) ?>" required <?= $hasSubmissions ? 'readonly' : '' ?>>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Level *</label>
                            <select name="level" <?= $hasSubmissions ? 'disabled' : '' ?>>
                                <?php foreach ([100, 200, 300, 400, 500] as $l): ?>
                                <option value="<?= $l ?>" <?= $test['level'] == $l ? 'selected' : '' ?>>Level <?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Duration (minutes) *</label>
                            <input type="number" name="duration_minutes" id="duration" value="<?= $test['duration_minutes'] ?>" min="5" max="180" <?= $hasSubmissions ? 'readonly' : '' ?>>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Number of Questions *</label>
                            <input type="number" name="total_questions" id="totalQuestions" value="<?= $test['total_questions'] ?>" min="5" max="100" <?= $hasSubmissions ? 'readonly' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label>Time Per Question (seconds)</label>
                            <input type="number" name="time_per_question" id="timePerQuestion" value="<?= $test['time_per_question'] ?>" readonly>
                            <small>Auto-calculated from duration and question count</small>
                        </div>
                    </div>
                </div>
                
                <!-- Schedule -->
                <div class="card">
                    <h3><i class="fas fa-calendar-alt"></i> Schedule</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="datetime-local" name="start_date" value="<?= date('Y-m-d\TH:i', strtotime($test['start_date'] ?? 'now')) ?>" <?= $hasSubmissions ? 'readonly' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label>Expiry Date</label>
                            <input type="datetime-local" name="expiry_date" value="<?= date('Y-m-d\TH:i', strtotime($test['expiry_date'] ?? '+7 days')) ?>" <?= $hasSubmissions ? 'readonly' : '' ?>>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Max Attempts</label>
                            <input type="hidden" name="max_attempts" value="1">
                            <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:.85rem;color:#166534;display:flex;align-items:center;gap:8px">
                                <i class="fas fa-lock"></i>
                                <span><strong>1 attempt per student.</strong> Approve retakes individually from the Results page.</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Passing Score (%)</label>
                            <input type="hidden" name="passing_score" value="50">
                            <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:.85rem;color:#166534;display:flex;align-items:center;gap:8px">
                                <i class="fas fa-lock"></i>
                                <span><strong>Fixed at 50% portal-wide.</strong> Same pass mark for every course.</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Security Settings -->
                <div class="card">
                    <h3><i class="fas fa-shield-alt"></i> Security Settings</h3>
                    <label class="checkbox-label">
                        <input type="checkbox" name="require_face_verify" <?= $test['require_face_verify'] ? 'checked' : '' ?> <?= $hasSubmissions ? 'disabled' : '' ?>>
                        <span>Require face verification before test</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="shuffle_questions" <?= $test['shuffle_questions'] ? 'checked' : '' ?> <?= $hasSubmissions ? 'disabled' : '' ?>>
                        <span>Shuffle questions for each student</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="shuffle_options" <?= $test['shuffle_options'] ? 'checked' : '' ?> <?= $hasSubmissions ? 'disabled' : '' ?>>
                        <span>Shuffle answer options</span>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="show_results_immediately" <?= $test['show_results_immediately'] ? 'checked' : '' ?> <?= $hasSubmissions ? 'disabled' : '' ?>>
                        <span>Show results immediately after submission</span>
                    </label>
                </div>
                
                <?php if (!$hasSubmissions): ?>
                <div class="form-actions">
                    <a href="tests.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </main>
</div>

<script>
    // Auto-calculate time per question
    function updateTimePerQuestion() {
        const duration = parseInt(document.getElementById('duration').value) || <?= $test['duration_minutes'] ?>;
        const totalQuestions = parseInt(document.getElementById('totalQuestions').value) || <?= $test['total_questions'] ?>;
        const timePerQ = Math.floor((duration * 60) / totalQuestions);
        document.getElementById('timePerQuestion').value = timePerQ;
    }
    
    document.getElementById('duration').addEventListener('input', updateTimePerQuestion);
    document.getElementById('totalQuestions').addEventListener('input', updateTimePerQuestion);
    
    // Mobile menu toggle
    document.querySelector('.menu-toggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
</script>
</body>
</html>