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
$message = '';
$error = '';

// Import is limited to courses allocated to this lecturer by admin.
$assignedCourses = [];
$stmt = $pdo->prepare("
    SELECT DISTINCT course_code, course_title, level
    FROM lecturer_courses
    WHERE lecturer_id = ?
    ORDER BY course_code
");
$stmt->execute([$lecturerId]);
foreach ($stmt->fetchAll() as $c) {
    $assignedCourses[strtoupper(trim($c['course_code']))] = $c;
}

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    // Validate file
    if (isLecturerRestricted($lecturerId)) {
        $error = '🔒 ' . LECTURER_RESTRICTED_MESSAGE;
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error uploading file.';
    } elseif ($file['size'] > 5000000) {
        $error = 'File too large. Maximum 5MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Only CSV files are allowed.';
        } else {
            // Read CSV
            $handle = fopen($file['tmp_name'], 'r');
            $imported = 0;
            $skipped = 0;
            $errors = [];
            $rowNumber = 0;
            
            // Skip header row
            $header = fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                $rowNumber++;
                
                // Your CSV has 7 columns: course_code, question_text, option_a, option_b, option_c, option_d, correct_option
                if (count($data) < 7) {
                    $errors[] = "Row $rowNumber: Missing columns. Expected 7, got " . count($data);
                    $skipped++;
                    continue;
                }
                
                // Map columns according to your CSV structure
                $courseCode = strtoupper(trim($data[0] ?? ''));
                $question = trim($data[1] ?? '');
                $optionA = trim($data[2] ?? '');
                $optionB = trim($data[3] ?? '');
                $optionC = trim($data[4] ?? '');
                $optionD = trim($data[5] ?? '');
                $correctOption = strtoupper(trim($data[6] ?? ''));
                
                // Difficulty - default to medium since your CSV doesn't have this column
                $difficulty = 'medium';
                
                // Validate required fields
                if (empty($courseCode) || empty($question) || empty($optionA) || empty($optionB) || 
                    empty($optionC) || empty($optionD) || empty($correctOption)) {
                    $errors[] = "Row $rowNumber: Missing required fields.";
                    $skipped++;
                    continue;
                }
                
                // Validate correct option
                if (!in_array($correctOption, ['A', 'B', 'C', 'D'])) {
                    $errors[] = "Row $rowNumber: Correct option must be A, B, C, or D. Got: $correctOption";
                    $skipped++;
                    continue;
                }

                if (!isset($assignedCourses[$courseCode])) {
                    $errors[] = "Row $rowNumber: Course $courseCode is not allocated to your account.";
                    $skipped++;
                    continue;
                }
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO course_questions (lecturer_id, course_code, question, image_url, option_a, option_b, option_c, option_d, correct_option, difficulty, level) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $level = (int)($assignedCourses[$courseCode]['level'] ?? 100);
                    
                    $stmt->execute([$lecturerId, $courseCode, $question, $optionA, $optionB, $optionC, $optionD, $correctOption, $difficulty, $level]);
                    $imported++;
                } catch (Exception $e) {
                    $errors[] = "Row $rowNumber: " . $e->getMessage();
                    $skipped++;
                }
            }
            
            fclose($handle);
            
            $message = "✅ Imported: $imported questions | Skipped: $skipped";
            if (!empty($errors)) {
                $message .= "<br><small>Errors: " . implode('; ', array_slice($errors, 0, 5)) . "</small>";
            }
        }
    }
}

$courses = array_keys($assignedCourses);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Questions - Lecturer Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; }
        .layout{display:flex;min-height:100vh}
        /* ── Sidebar ── */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */
        /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */
        /* .nav defined in includes/sidebar.php */
        
        /* .nav a defined in includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        
        /* sidebar CSS → includes/sidebar.php */
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: .9rem; display: flex; align-items: center; gap: 6px; }
        .content { padding: 24px; max-width: 800px; }
        
        .alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .card { background: white; border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; }
        .card h3 { margin-bottom: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        
        .upload-area { border: 2px dashed #cbd5e0; border-radius: 12px; padding: 40px; text-align: center; margin: 20px 0; cursor: pointer; transition: all .2s; }
        .upload-area:hover { border-color: #1e3a8a; background: #f8fafc; }
        .upload-area i { font-size: 3rem; color: #64748b; display: block; margin-bottom: 12px; }
        .upload-area.dragover { border-color: #1e3a8a; background: #dbeafe; }
        
        .template-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin: 20px 0; }
        .template-box h4 { margin-bottom: 8px; }
        .template-box pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 8px; overflow-x: auto; font-size: .85rem; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: .95rem; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e3a8a; }
        .btn-green { background: #10b981; color: white; }
        .btn-outline { background: white; color: #475569; border: 1px solid #e2e8f0; }
        
        .file-info { display: none; margin: 12px 0; padding: 10px; background: #f0fdf4; border-radius: 8px; color: #065f46; }
        
        @media (max-width: 768px) { /* sidebar CSS → includes/sidebar.php */ .main { margin-left: 0; } }
    
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
    <?php $activePage='import-questions'; require_once __DIR__.'/includes/sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <button class="menu-toggle" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')"><i class="fas fa-bars"></i></button>
    <h1>Import Questions</h1>
            <a href="questions.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Question Bank</a>
        </div>
        <div class="content">
            
            <?php if ($message): ?>
            <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
            <?php endif; ?>
            
            <!-- CSV Template Info -->
            <div class="card">
                <h3><i class="fas fa-info-circle"></i> CSV Format Requirements</h3>
                <p style="color:#64748b; margin-bottom:16px;">Your CSV file must have these columns in order:</p>
                <div class="template-box">
                    <pre>course_code,question_text,option_a,option_b,option_c,option_d,correct_option</pre>
                </div>
                <p style="color:#64748b; margin-bottom:16px;"><strong>Example rows:</strong></p>
                <div class="template-box">
                    <pre>CSC101,What is the decimal equivalent of binary 11010110?,210,186,198,214,D
CSC101,Convert hexadecimal 3F to binary.,00111100,00110111,00111110,00111111,D
CSC401,What is AI?,Simulation of human intelligence,Computer hardware,Programming language,Operating system,A</pre>
                </div>
                <p style="color:#64748b; font-size:.85rem;">
                    <strong>Notes:</strong><br>
                    • First row must be the header row (will be skipped)<br>
                    • Correct option must be <strong>A, B, C, or D</strong><br>
                    • Course code examples: CSC101, CSC201, CSC301, CSC401<br>
                    • Level is auto-detected from course code (e.g., CSC101 = Level 100)<br>
                    • Difficulty defaults to "medium" if not specified
                </p>
                <a href="#" onclick="downloadTemplate()" class="btn btn-outline" style="margin-top:12px;">
                    <i class="fas fa-download"></i> Download Template CSV
                </a>
            </div>
            
            <!-- Upload Form -->
            <div class="card">
                <h3><i class="fas fa-upload"></i> Upload CSV File</h3>
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <div class="upload-area" id="dropArea" onclick="document.getElementById('csvFile').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h3>Click to select CSV file</h3>
                        <p>or drag and drop here</p>
                        <p style="font-size:.8rem; color:#94a3b8;">Maximum 5MB</p>
                        <input type="file" id="csvFile" name="csv_file" accept=".csv" style="display:none;" onchange="showFileInfo()">
                    </div>
                    <div class="file-info" id="fileInfo">
                        <i class="fas fa-file-csv"></i> <span id="fileName"></span> (<span id="fileSize"></span>)
                    </div>
                    <button type="submit" class="btn btn-primary" id="uploadBtn" style="margin-top:16px;" disabled>
                        <i class="fas fa-upload"></i> Upload and Import Questions
                    </button>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
    const dropArea = document.getElementById('dropArea');
    const fileInput = document.getElementById('csvFile');
    const fileInfo = document.getElementById('fileInfo');
    const uploadBtn = document.getElementById('uploadBtn');
    
    dropArea.addEventListener('dragover', (e) => { e.preventDefault(); dropArea.classList.add('dragover'); });
    dropArea.addEventListener('dragleave', () => { dropArea.classList.remove('dragover'); });
    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        dropArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) { fileInput.files = files; showFileInfo(); }
    });
    
    function showFileInfo() {
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
            fileInfo.style.display = 'block';
            uploadBtn.disabled = false;
        }
    }
    
    function downloadTemplate() {
        const csvContent = "course_code,question_text,option_a,option_b,option_c,option_d,correct_option\nCSC101,What is the decimal equivalent of binary 11010110?,210,186,198,214,D\nCSC101,Convert hexadecimal 3F to binary.,00111100,00110111,00111110,00111111,D\nCSC401,What is AI?,Simulation of human intelligence,Computer hardware,Programming language,Operating system,A";
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'question_template.csv'; a.click();
        URL.revokeObjectURL(url);
    }
</script>
</body>
</html>
