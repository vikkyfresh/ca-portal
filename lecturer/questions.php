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
$lecturerName = $_SESSION['lecturer_name'];

// Only courses allocated by admin should appear in the lecturer question bank.
$allCourses = [];
$stmt = $pdo->prepare("
    SELECT DISTINCT course_code, course_title, level
    FROM lecturer_courses
    WHERE lecturer_id = ?
    ORDER BY course_code
");
$stmt->execute([$lecturerId]);
foreach ($stmt->fetchAll() as $c) {
    $code = strtoupper(trim($c['course_code']));
    $allCourses[$code] = $c;
}

// Handle delete
if (isset($_GET['delete'])) {
    if (isLecturerRestricted($lecturerId)) {
        header("Location: questions.php?course=" . urlencode($_GET['course'] ?? '') . "&msg=restricted");
        exit;
    }
    $questionId = intval($_GET['delete']);
    $courseCode = strtoupper(trim($_GET['course'] ?? ''));
    if (!isset($allCourses[$courseCode])) {
        header("Location: questions.php?msg=not_allowed");
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM course_questions WHERE id = ? AND lecturer_id = ? AND course_code = ?");
    $stmt->execute([$questionId, $lecturerId, $courseCode]);
    header("Location: questions.php?course=" . urlencode($_GET['course'] ?? '') . "&msg=deleted");
    exit;
}

// Handle add question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (isLecturerRestricted($lecturerId)) {
        header("Location: questions.php?course=" . urlencode($_POST['course_code'] ?? '') . "&msg=restricted");
        exit;
    }
    $courseCode = strtoupper(trim($_POST['course_code'] ?? ''));
    $questionText = trim($_POST['question_text'] ?? '');
    $optionA = trim($_POST['option_a'] ?? '');
    $optionB = trim($_POST['option_b'] ?? '');
    $optionC = trim($_POST['option_c'] ?? '');
    $optionD = trim($_POST['option_d'] ?? '');
    $correctOption = strtoupper($_POST['correct_option'] ?? 'A');
    $difficulty = $_POST['difficulty'] ?? 'medium';

    if (!isset($allCourses[$courseCode])) {
        header("Location: questions.php?msg=not_allowed");
        exit;
    }
    
    if ($courseCode && $questionText && $optionA && $optionB && $optionC && $optionD) {
        $level = (int)($allCourses[$courseCode]['level'] ?? 100);

        // Handle optional diagram/image upload
        $imageUrl = null;
        if (!empty($_FILES['question_image']['name'])) {
            $uploadDir = '../uploads/questions/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $filename = 'q_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['question_image']['tmp_name'], $uploadDir . $filename)) {
                    $imageUrl = 'uploads/questions/' . $filename;
                }
            }
        }

        $stmt = $pdo->prepare("INSERT INTO course_questions (lecturer_id, course_code, question, image_url, option_a, option_b, option_c, option_d, correct_option, difficulty, level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$lecturerId, $courseCode, $questionText, $imageUrl, $optionA, $optionB, $optionC, $optionD, $correctOption, $difficulty, $level]);
        
        header("Location: questions.php?course=" . urlencode($courseCode) . "&msg=added");
        exit;
    }
}

// Get selected course
$requestedCourse = strtoupper(trim($_GET['course'] ?? ''));
$selectedCourse = isset($allCourses[$requestedCourse]) ? $requestedCourse : (!empty($allCourses) ? array_key_first($allCourses) : '');

// Get questions for selected course
$questions = [];
$totalQuestions = 0;
if ($selectedCourse) {
    $stmt = $pdo->prepare("
        SELECT cq.*, a.full_name AS owner_name
        FROM course_questions cq
        LEFT JOIN admins a ON cq.lecturer_id = a.id
        WHERE cq.course_code = ?
        ORDER BY cq.id DESC
    ");
    $stmt->execute([$selectedCourse]);
    $questions = $stmt->fetchAll();
    $totalQuestions = count($questions);
}

$message = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank - Lecturer Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { min-height: 100vh; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* .nav defined in includes/sidebar.php */
        /* .nav a defined in includes/sidebar.php */
        
        .main { flex:1; margin-left:260px; }
        .topbar { background:white; padding:16px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .topbar h1 { font-size:1.5rem; color:#0f172a; }
        .content { padding:24px; }
        
        .course-tabs { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; }
        .course-tab { padding:10px 18px; border-radius:20px; text-decoration:none; color:#475569; background:white; border:1px solid #e2e8f0; font-weight:500; font-size:.9rem; }
        .course-tab.active { background:#0f172a; color:white; border-color:#0f172a; }
        .course-tab small { opacity:0.7; }
        
        .stats-bar { background:white; border-radius:12px; padding:16px 20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.1); display:flex; gap:32px; align-items:center; flex-wrap:wrap; }
        .stat-item { text-align:center; }
        .stat-value { font-size:1.8rem; font-weight:700; color:#0f172a; }
        .stat-label { color:#64748b; font-size:.8rem; }
        
        .card { background:white; border-radius:12px; padding:16px 20px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,.1); border-left:4px solid #1e3a8a; }
        .card .q-text { font-weight:600; margin-bottom:8px; color:#0f172a; }
        .card .options { display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:.85rem; color:#475569; }
        .card .correct { color:#10b981; font-weight:600; }
        .card .card-footer { margin-top:10px; display:flex; justify-content:space-between; align-items:center; font-size:.8rem; color:#64748b; }
        
        .btn { padding:6px 14px; border-radius:6px; cursor:pointer; font-size:.85rem; border:none; font-weight:500; text-decoration:none; display:inline-block; }
        .btn-primary { background:#0f172a; color:white; }
        .btn-red { background:#ef4444; color:white; }
        .btn-green { background:#10b981; color:white; }
        
        .alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .alert-success { background:#d1fae5; color:#065f46; }
        .alert-danger { background:#fee2e2; color:#991b1b; font-weight:600; }
        
        .modal { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:200; align-items:center; justify-content:center; padding:20px; }
        .modal.active { display:flex; }
        .modal-card { background:white; border-radius:16px; padding:24px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; }
        .modal-card h3 { margin-bottom:16px; color:#0f172a; }
        .form-group { margin-bottom:14px; }
        .form-group label { display:block; margin-bottom:4px; color:#475569; font-weight:500; font-size:.9rem; }
        .form-group input, .form-group textarea, .form-group select { width:100%; padding:10px; border:2px solid #e2e8f0; border-radius:8px; font-family:inherit; font-size:.95rem; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .form-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }
        .btn-cancel { background:#f1f5f9; color:#475569; }
        
        .empty-state { text-align:center; padding:60px; color:#64748b; }
        .empty-state i { font-size:3rem; display:block; margin-bottom:12px; opacity:.4; }
        
         .main{margin-left:0;} .form-row{grid-template-columns:1fr;} .card .options{grid-template-columns:1fr;} }
    
/* ── Responsive ── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:#cbd5e0;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}

@media(max-width:900px){
    .grid-2,.stats-grid,.kpi-grid{grid-template-columns:repeat(2,1fr)}
    .level-grid{grid-template-columns:repeat(2,1fr)}
    .form-grid{grid-template-columns:1fr}
    .info-grid{grid-template-columns:1fr}
    .hero-stats{display:none}
}
@media(max-width:768px){
    /* sidebar CSS → includes/sidebar.php */
    .main{margin-left:0}
    .topbar{padding:0 16px;height:auto;min-height:64px;flex-wrap:wrap;gap:8px;padding-top:10px;padding-bottom:10px}
    .content{padding:16px}
    .kpi-grid,.stats-grid{grid-template-columns:repeat(2,1fr)}
    .level-grid{flex-wrap:wrap}
    .card{padding:16px 14px}
    .tbl-wrap{overflow-x:auto}
    table{font-size:12px}
    thead th{padding:8px 10px;font-size:11px}
    tbody td{padding:8px 10px}
    .btn-row{flex-wrap:wrap}
    .back-btn{padding:7px 12px;font-size:12px}
    .profile-hero{flex-direction:column;text-align:center;padding:24px 16px}
    .hero-tags{justify-content:center}
}
@media(max-width:480px){
    .kpi-grid,.stats-grid{grid-template-columns:1fr}
    .grid-2{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="layout">
    <?php $activePage='questions'; require_once __DIR__.'/includes/sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
            <h1>Question Bank</h1>
            <div style="display:flex; gap:10px;">
                <a href="import-questions.php" class="btn btn-green">
                    <i class="fas fa-upload"></i> Import CSV
                </a>
                <?php if ($selectedCourse): ?>
                <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Question</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="content">
            
            <?php if ($message === 'added'): ?>
            <div class="alert alert-success">✅ Question added successfully!</div>
            <?php elseif ($message === 'deleted'): ?>
            <div class="alert alert-success">🗑️ Question deleted.</div>
            <?php elseif ($message === 'restricted'): ?>
            <div class="alert alert-danger">🔒 Your account is in view-only mode during the current exam session — editing is temporarily disabled by the administrator.</div>
            <?php elseif ($message === 'not_allowed'): ?>
            <div class="alert alert-danger">This course is not allocated to your account.</div>
            <?php endif; ?>
             
            <!-- Course Tabs -->
            <div class="course-tabs">
                <?php foreach ($allCourses as $code => $c): 
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM course_questions WHERE course_code = ?");
                    $countStmt->execute([$code]);
                    $count = $countStmt->fetchColumn();
                ?>
                <a href="?course=<?= urlencode($code) ?>" class="course-tab <?= $selectedCourse === $code ? 'active' : '' ?>">
                    <?= htmlspecialchars($code) ?> <small>(<?= $count ?>)</small>
                </a>
                <?php endforeach; ?>
                <?php if (empty($allCourses)): ?>
                <p style="color:#64748b;">No courses found. Contact admin to assign courses.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($selectedCourse): ?>
            
            <!-- Stats -->
            <div class="stats-bar">
                <div class="stat-item"><div class="stat-value"><?= $totalQuestions ?></div><div class="stat-label">Total Questions</div></div>
                <div class="stat-item"><div class="stat-value"><?= htmlspecialchars($selectedCourse) ?></div><div class="stat-label">Course</div></div>
                <button class="btn btn-primary" onclick="openAddModal()" style="margin-left:auto;"><i class="fas fa-plus"></i> Add Question</button>
            </div>
            
            <!-- Questions List -->
            <?php if (count($questions) > 0): ?>
                <?php foreach ($questions as $q): ?>
                <div class="card">
                    <div class="q-text">Q: <?= htmlspecialchars($q['question']) ?></div>
                    <div class="options">
                        <span class="<?= $q['correct_option'] === 'A' ? 'correct' : '' ?>">A. <?= htmlspecialchars($q['option_a']) ?></span>
                        <span class="<?= $q['correct_option'] === 'B' ? 'correct' : '' ?>">B. <?= htmlspecialchars($q['option_b']) ?></span>
                        <span class="<?= $q['correct_option'] === 'C' ? 'correct' : '' ?>">C. <?= htmlspecialchars($q['option_c']) ?></span>
                        <span class="<?= $q['correct_option'] === 'D' ? 'correct' : '' ?>">D. <?= htmlspecialchars($q['option_d']) ?></span>
                    </div>
                    <div class="card-footer">
                        <span>Difficulty: <?= htmlspecialchars($q['difficulty'] ?? 'medium') ?> | Level: <?= htmlspecialchars($q['level'] ?? 'N/A') ?> | Added by: <?= htmlspecialchars($q['owner_name'] ?? 'Unknown') ?></span>
                        <?php if ((int)$q['lecturer_id'] === (int)$lecturerId): ?>
                        <a href="?course=<?= urlencode($selectedCourse) ?>&delete=<?= $q['id'] ?>" class="btn btn-red" onclick="return confirm('Delete this question?')"><i class="fas fa-trash"></i> Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle"></i>
                    <p>No questions yet for <?= htmlspecialchars($selectedCourse) ?>.</p>
                    <button class="btn btn-primary" onclick="openAddModal()" style="margin-top:12px;"><i class="fas fa-plus"></i> Add First Question</button>
                </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book"></i>
                <p>Select a course above to manage its question bank.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Add Question Modal -->
<div class="modal" id="addModal">
    <div class="modal-card">
        <h3><i class="fas fa-plus-circle"></i> Add Question to <?= htmlspecialchars($selectedCourse) ?></h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="course_code" value="<?= htmlspecialchars($selectedCourse) ?>">
            
            <div class="form-group">
                <label>Question *</label>
                <textarea name="question_text" rows="3" required placeholder="Enter your question here..."></textarea>

                <!-- Diagram / Image upload -->
                <div style="margin-top:14px">
                    <label style="display:block;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">
                        Diagram / Image <span style="color:#94a3b8;font-weight:400;text-transform:none">(optional)</span>
                    </label>
                    <div style="border:2px dashed #e2e8f0;border-radius:12px;padding:16px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc" id="imgUploadArea" onclick="document.getElementById('questionImageInput').click()">
                        <img id="imgPreview" src="#" alt="preview" style="display:none;max-height:180px;max-width:100%;border-radius:8px;margin-bottom:8px;object-fit:contain">
                        <div id="imgUploadPrompt">
                            <i class="fas fa-image" style="font-size:24px;color:#94a3b8;display:block;margin-bottom:6px"></i>
                            <span style="font-size:13px;color:#64748b">Click to upload a diagram or image</span><br>
                            <small style="color:#94a3b8">JPG, PNG, GIF, SVG, WEBP · Max 5MB</small>
                        </div>
                    </div>
                    <input type="file" name="question_image" id="questionImageInput" accept="image/*" style="display:none" onchange="previewQImage(this)">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group"><label>Option A *</label><input name="option_a" required></div>
                <div class="form-group"><label>Option B *</label><input name="option_b" required></div>
                <div class="form-group"><label>Option C *</label><input name="option_c" required></div>
                <div class="form-group"><label>Option D *</label><input name="option_d" required></div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Correct Answer *</label>
                    <select name="correct_option" required>
                        <option value="A">Option A</option>
                        <option value="B">Option B</option>
                        <option value="C">Option C</option>
                        <option value="D">Option D</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Difficulty</label>
                    <select name="difficulty">
                        <option value="easy">Easy</option>
                        <option value="medium" selected>Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Question</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() { document.getElementById('addModal').classList.add('active'); }
    function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
    document.getElementById('addModal').addEventListener('click', function(e) { if(e.target === this) closeAddModal(); });

function previewQImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const preview = document.getElementById('imgPreview');
            const prompt  = document.getElementById('imgUploadPrompt');
            preview.src   = e.target.result;
            preview.style.display = 'block';
            prompt.style.display  = 'none';
            document.getElementById('imgUploadArea').style.borderColor = '#1e3a8a';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>
