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
$lecturerDept = $_SESSION['lecturer_department'] ?? 'Computer Science';

// Get assigned courses for dropdown
$stmt = $pdo->prepare("SELECT * FROM lecturer_courses WHERE lecturer_id = ? ORDER BY level, course_code");
$stmt->execute([$lecturerId]);
$courses = $stmt->fetchAll();

$courseCode = $_GET['course'] ?? '';

// Fetch students grouped by level for the custom picker
$levelsStmt = $pdo->prepare("SELECT DISTINCT level FROM lecturer_courses WHERE lecturer_id=? ORDER BY level");
$levelsStmt->execute([$lecturerId]);
$pickerLevels = $levelsStmt->fetchAll(PDO::FETCH_COLUMN);
$studentsByLevel = [];
foreach ($pickerLevels as $lv) {
    $s = $pdo->prepare("SELECT matric, full_name FROM students WHERE level=? ORDER BY full_name");
    $s->execute([$lv]);
    $studentsByLevel[$lv] = $s->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Test - Lecturer Portal</title>
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
        .nav-section { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:rgba(255,255,255,.35); padding:12px 12px 5px; }
        /* .nav a defined in includes/sidebar.php */
        .nav a i { width:18px; text-align:center; font-size:.88rem; }
        
        /* sidebar CSS → includes/sidebar.php */
        .nav a i { width: 20px; }
        .main { flex: 1; margin-left: 260px; }
        .topbar { background: white; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 50; }
        .topbar h1 { font-size: 1.5rem; color: #0f172a; }
        .back-btn { padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.9rem; display: flex; align-items: center; gap: 6px; }
        .back-btn:hover { background: #e2e8f0; }
        .content { padding: 24px; max-width: 800px; }
        
        .form-section { background: white; border-radius: 16px; padding: 28px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .form-section h3 { color: #0f172a; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; }
        .form-section h3 i { color: #1e3a8a; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 500; font-size: 0.9rem; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; font-family: inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #1e3a8a; }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .form-group small { color: #64748b; font-size: 0.8rem; margin-top: 4px; display: block; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; padding: 8px 0; color: #475569; cursor: pointer; font-size: 0.9rem; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e3a8a; }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; text-decoration: none; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 8px; }
        
        .preview-card { background: linear-gradient(to right, #0f172a, #1e3a8a); border-radius: 16px; padding: 32px; color: white; margin-top: 24px; display: none; }
        .preview-card h2 { margin-bottom: 8px; font-size: 1.3rem; }
        .preview-card .check-icon { font-size: 2.5rem; color: #10b981; margin-bottom: 12px; }
        .link-box { display: flex; gap: 8px; margin: 16px 0; }
        .link-box input { flex: 1; padding: 12px; border: none; border-radius: 8px; background: rgba(255,255,255,0.15); color: white; font-size: 0.9rem; }
        .copy-btn { padding: 12px 20px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
        .copy-btn:hover { background: #059669; }
        .expiry-warning { background: rgba(245,158,11,0.2); border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-size: 0.9rem; }
        .preview-actions { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .btn-outline { background: transparent; color: white; border: 1px solid rgba(255,255,255,0.3); text-decoration: none; }
        .btn-outline:hover { background: rgba(255,255,255,0.1); }
        
        .access-type-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
        .access-type-card { border:2px solid #e2e8f0; border-radius:12px; padding:18px; cursor:pointer; transition:all .2s; position:relative; }
        .access-type-card:hover { border-color:#1e3a8a; background:#f8faff; }
        .access-type-card.selected { border-color:#1e3a8a; background:#eff6ff; }
        .access-type-card input[type=radio] { position:absolute; opacity:0; }
        .access-type-card .card-icon { font-size:1.5rem; margin-bottom:8px; }
        .access-type-card h4 { font-size:.95rem; font-weight:700; color:#0f172a; margin-bottom:4px; }
        .access-type-card p { font-size:.8rem; color:#64748b; line-height:1.4; }
        .access-type-card .check { position:absolute; top:12px; right:12px; width:20px; height:20px; border-radius:50%; border:2px solid #e2e8f0; display:flex; align-items:center; justify-content:center; font-size:.7rem; color:white; background:#e2e8f0; transition:all .2s; }
        .access-type-card.selected .check { background:#1e3a8a; border-color:#1e3a8a; }
        /* Student picker */
        .student-picker { display:none; margin-top:16px; }
        .level-tabs { display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
        .level-tab { padding:6px 16px; border-radius:8px; border:2px solid #e2e8f0; background:#fff; font-size:.83rem; font-weight:600; color:#64748b; cursor:pointer; transition:all .2s; }
        .level-tab.active { background:#1e3a8a; border-color:#1e3a8a; color:#fff; }
        .picker-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .picker-toolbar label { font-size:.84rem; font-weight:600; color:#475569; }
        .picker-btns { display:flex; gap:8px; }
        .picker-btn { padding:5px 12px; border:1px solid #e2e8f0; background:#f8fafc; border-radius:8px; font-size:.78rem; font-weight:600; color:#475569; cursor:pointer; }
        .picker-btn:hover { background:#e2e8f0; }
        .student-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:8px; max-height:240px; overflow-y:auto; border:2px solid #e2e8f0; border-radius:10px; padding:12px; background:#f8fafc; }
        .student-chip { display:flex; align-items:center; gap:8px; padding:8px 10px; background:#fff; border:1.5px solid #e2e8f0; border-radius:8px; cursor:pointer; transition:all .2s; font-size:.82rem; user-select:none; }
        .student-chip:hover { border-color:#1e3a8a; background:#eff6ff; }
        .student-chip.selected { background:#dbeafe; border-color:#1e3a8a; }
        .student-chip input[type=checkbox] { accent-color:#1e3a8a; width:15px; height:15px; flex-shrink:0; }
        .chip-name { font-weight:600; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .chip-matric { font-size:.73rem; color:#64748b; }
        .selected-count { font-size:.8rem; color:#1e40af; font-weight:600; margin-top:8px; }
        .empty-courses i { font-size: 3rem; margin-bottom: 16px; opacity: 0.5; }
        
        .menu-toggle { display: none; background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #475569; }
        
        @media (max-width: 768px) { /* sidebar CSS → includes/sidebar.php */ /* sidebar CSS → includes/sidebar.php */ .main { margin-left: 0; } .menu-toggle { display: block; } .form-row { grid-template-columns: 1fr; } .preview-actions { flex-direction: column; } }
    
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
                <?php $activePage='create-test'; require_once __DIR__.'/includes/sidebar.php'; ?>
        
        <main class="main">
            <div class="topbar">
                <div style="display:flex; align-items:center; gap:16px;">
                    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
                    <h1>Create New Test</h1>
                </div>
                <a href="tests.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Tests</a>
            </div>
            
            <div class="content">
                
                <?php if (empty($courses)): ?>
                <div class="form-section">
                    <div class="empty-courses">
                        <i class="fas fa-book-open"></i>
                        <h3>No Courses Assigned</h3>
                        <p>You don't have any courses assigned yet.</p>
                        <p>Please contact the administrator to get courses assigned.</p>
                    </div>
                </div>
                <?php else: ?>
                
                <form id="createTestForm">
                    
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Course *</label>
                                <select name="course_code" id="courseSelect" required>
                                    <option value="">-- Select a course --</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= htmlspecialchars($c['course_code']) ?>" 
                                                data-title="<?= htmlspecialchars($c['course_title']) ?>"
                                                data-level="<?= $c['level'] ?>"
                                                <?= $courseCode === $c['course_code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['course_code']) ?> - <?= htmlspecialchars($c['course_title']) ?> (Level <?= $c['level'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Course Title</label>
                                <input type="text" id="courseTitle" name="course_title" readonly placeholder="Auto-filled from course selection">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Test Title *</label>
                            <input type="text" name="test_title" placeholder="e.g., Continuous Assessment Test 1" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Description (Optional)</label>
                            <textarea name="description" rows="2" placeholder="Brief description of what this test covers..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Target Level *</label>
                                <select name="level" id="levelSelect" required>
                                    <option value="">-- Select Level --</option>
                                    <option value="100">100 Level</option>
                                    <option value="200">200 Level</option>
                                    <option value="300">300 Level</option>
                                    <option value="400">400 Level</option>
                                    <option value="500">500 Level</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Duration (minutes) *</label>
                                <input type="number" name="duration_minutes" id="duration" value="20" min="5" max="180" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Number of Questions *</label>
                                <input type="number" name="total_questions" id="totalQuestions" value="20" min="5" max="100" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Time Per Question (seconds)</label>
                                <input type="number" name="time_per_question" id="timePerQuestion" value="60" readonly>
                                <small>Auto-calculated: (Duration × 60) ÷ Questions</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Schedule -->
                    <div class="form-section">
                        <h3><i class="fas fa-calendar-alt"></i> Availability Schedule</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Start Date & Time</label>
                                <input type="datetime-local" name="start_date" id="startDate">
                            </div>
                            
                            <div class="form-group">
                                <label>Expiry Date & Time</label>
                                <input type="datetime-local" name="expiry_date" id="expiryDate">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Maximum Attempts</label>
                                <input type="hidden" name="max_attempts" value="1">
                                <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:.85rem;color:#166534;display:flex;align-items:center;gap:8px">
                                    <i class="fas fa-lock"></i>
                                    <span><strong>1 attempt per student.</strong> Retakes must be approved by you from the Results page.</span>
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
                    
                    <!-- Access Type -->
                    <div class="form-section">
                        <h3><i class="fas fa-lock"></i> Test Access Type</h3>
                        <p style="font-size:.85rem;color:#64748b;margin-bottom:16px">Choose who can access this test.</p>

                        <div class="access-type-row">
                            <label class="access-type-card selected" id="cardGeneral" onclick="setAccessType('general')">
                                <input type="radio" name="access_type" value="general" checked>
                                <div class="check"><i class="fas fa-check" style="font-size:.6rem"></i></div>
                                <div class="card-icon">🌐</div>
                                <h4>General</h4>
                                <p>Visible on dashboard to all students at the selected level. Anyone at that level can take it.</p>
                            </label>
                            <label class="access-type-card" id="cardCustom" onclick="setAccessType('custom')">
                                <input type="radio" name="access_type" value="custom">
                                <div class="check"><i class="fas fa-check" style="font-size:.6rem"></i></div>
                                <div class="card-icon">🔒</div>
                                <h4>Custom (Restricted)</h4>
                                <p>Hidden from dashboard. Only students you select can access it via a generated link.</p>
                            </label>
                        </div>

                        <!-- Student picker (shown only for custom) -->
                        <div class="student-picker" id="studentPicker">
                            <div class="picker-toolbar">
                                <label>Select Allowed Students</label>
                                <div class="picker-btns">
                                    <button type="button" class="picker-btn" onclick="pickerSelectAll()">✅ Select All</button>
                                    <button type="button" class="picker-btn" onclick="pickerClearAll()">☐ Clear</button>
                                </div>
                            </div>

                            <div class="level-tabs" id="pickerLevelTabs">
                                <?php foreach($pickerLevels as $i => $lv): ?>
                                <button type="button" class="level-tab <?= $i===0?'active':'' ?>"
                                    onclick="switchPickerLevel(<?= $lv ?>, this)"><?= $lv ?>L</button>
                                <?php endforeach; ?>
                            </div>

                            <?php foreach($pickerLevels as $i => $lv): ?>
                            <div class="student-grid" id="picker-level-<?= $lv ?>" style="<?= $i>0?'display:none':'' ?>">
                                <?php foreach($studentsByLevel[$lv] as $s): ?>
                                <div class="student-chip" onclick="togglePickerChip(this)"
                                     data-matric="<?= htmlspecialchars($s['matric']) ?>">
                                    <input type="checkbox" name="allowed_matrics[]"
                                           value="<?= htmlspecialchars($s['matric']) ?>">
                                    <div>
                                        <div class="chip-name"><?= htmlspecialchars($s['full_name']) ?></div>
                                        <div class="chip-matric"><?= htmlspecialchars($s['matric']) ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if(empty($studentsByLevel[$lv])): ?>
                                <p style="color:#94a3b8;font-size:.84rem;padding:8px">No students at this level.</p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <div class="selected-count" id="pickerCount">0 students selected</div>
                        </div>
                    </div>

                    <!-- Security Settings -->
                    <div class="form-section">
                        <h3><i class="fas fa-shield-alt"></i> Security & Display Settings</h3>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" name="require_face_verify" checked>
                            <span>Require face verification before test starts</span>
                        </label>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" name="shuffle_questions" checked>
                            <span>Shuffle questions for each student</span>
                        </label>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" name="shuffle_options" checked>
                            <span>Shuffle answer options</span>
                        </label>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" name="show_results_immediately" checked>
                            <span>Show results immediately after submission</span>
                        </label>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="tests.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" id="createTestBtn">
                            <i class="fas fa-plus-circle"></i> Create Test
                        </button>
                    </div>
                </form>
                
                <!-- Success Preview (Hidden initially) -->
                <div class="preview-card" id="linkPreview">
                    <div class="check-icon">✅</div>
                    <h2 id="previewTitle">Test Created Successfully!</h2>
                    <p id="previewSubtitle">Share this link with your students:</p>

                    <!-- General link section -->
                    <div id="generalLinkSection">
                        <div class="link-box">
                            <input type="text" id="testLink" readonly>
                            <button class="copy-btn" onclick="copyTestLink()">
                                <i class="fas fa-copy"></i> Copy Link
                            </button>
                        </div>
                        <p style="font-size: 0.9rem;">
                            Access Code: <strong id="accessCode"></strong>
                        </p>
                        <div class="expiry-warning">
                            <i class="fas fa-clock"></i>
                            <strong>Link expires if not used by the test's own end date/time</strong><br>
                            <small>Once a student uses it, the test remains active until the scheduled expiry date.</small>
                            <div id="countdownDisplay" style="margin-top: 8px; font-size: 1.1rem; font-weight: 600;"></div>
                        </div>
                    </div>

                    <!-- Custom link section -->
                    <div id="customLinkSection" style="display:none">
                        <div style="background:rgba(16,185,129,.15);border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;margin:16px 0;font-size:.9rem">
                            <i class="fas fa-lock"></i> <strong>Restricted test created.</strong>
                            Only the students you selected can access this test via the link below.
                            The test is <strong>hidden from the student dashboard</strong>.
                        </div>
                        <div class="link-box">
                            <input type="text" id="customTestLink" readonly>
                            <button class="copy-btn" onclick="copyCustomTestLink()">
                                <i class="fas fa-copy"></i> Copy Link
                            </button>
                        </div>
                        <p style="font-size:.85rem;opacity:.8;margin-top:8px">
                            <i class="fas fa-shield-alt"></i>
                            Students will enter their matric number, complete face verification, then start the test.
                        </p>
                    </div>

                    <div class="preview-actions">
                        <a href="#" id="addQuestionsLink" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Questions
                        </a>
                        <a href="tests.php" class="btn btn-outline">
                            <i class="fas fa-list"></i> View All Tests
                        </a>
                    </div>
                </div>
                
                <?php endif; ?>
                
            </div>
        </main>
    </div>
    
    <script>
        // ── Course select auto-fill ──────────────────────────────────────────
        const courseSelect = document.getElementById('courseSelect');
        const courseTitleInput = document.getElementById('courseTitle');
        const levelSelect = document.getElementById('levelSelect');

        if (courseSelect) {
            courseSelect.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                courseTitleInput.value = opt.dataset.title || '';
                if (opt.dataset.level) levelSelect.value = opt.dataset.level;
            });
            if (courseSelect.value) courseSelect.dispatchEvent(new Event('change'));
        }

        // ── Time per question ────────────────────────────────────────────────
        function updateTimePerQuestion() {
            const d = parseInt(document.getElementById('duration').value) || 20;
            const q = parseInt(document.getElementById('totalQuestions').value) || 20;
            document.getElementById('timePerQuestion').value = Math.floor((d * 60) / q);
        }
        document.getElementById('duration').addEventListener('input', updateTimePerQuestion);
        document.getElementById('totalQuestions').addEventListener('input', updateTimePerQuestion);

        // ── Default dates ────────────────────────────────────────────────────
        // IMPORTANT: datetime-local inputs display and submit plain wall-clock
        // digits with no timezone conversion — whatever you put in the field is
        // taken literally as local time. toISOString() returns UTC, so using it
        // here silently mislabels UTC time as local, and the size of the error
        // depends entirely on how far the browser's own clock/timezone is from
        // UTC (e.g. an 8-9hr skew on a machine set to US Pacific/Alaska time).
        // Build the value from local date components instead so what's shown
        // and submitted always matches the browser's actual local clock.
        function toLocalInputValue(d) {
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }
        const now = new Date();
        const nextWeek = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
        document.getElementById('startDate').value = toLocalInputValue(now);
        document.getElementById('expiryDate').value = toLocalInputValue(nextWeek);
        updateTimePerQuestion();

        // ── Access type toggle ───────────────────────────────────────────────
        function setAccessType(type) {
            document.getElementById('cardGeneral').classList.toggle('selected', type === 'general');
            document.getElementById('cardCustom').classList.toggle('selected', type === 'custom');
            document.querySelector('[name=access_type][value=general]').checked = (type === 'general');
            document.querySelector('[name=access_type][value=custom]').checked = (type === 'custom');
            document.getElementById('studentPicker').style.display = type === 'custom' ? 'block' : 'none';
        }

        // ── Student picker ───────────────────────────────────────────────────
        var currentPickerLevel = <?= json_encode($pickerLevels[0] ?? null) ?>;

        function switchPickerLevel(lv, btn) {
            document.querySelectorAll('.student-grid[id^="picker-level-"]').forEach(g => g.style.display = 'none');
            document.querySelectorAll('#pickerLevelTabs .level-tab').forEach(t => t.classList.remove('active'));
            document.getElementById('picker-level-' + lv).style.display = 'grid';
            btn.classList.add('active');
            currentPickerLevel = lv;
        }

        function togglePickerChip(chip) {
            chip.classList.toggle('selected');
            chip.querySelector('input').checked = chip.classList.contains('selected');
            updatePickerCount();
        }

        function pickerSelectAll() {
            const grid = document.getElementById('picker-level-' + currentPickerLevel);
            if (!grid) return;
            grid.querySelectorAll('.student-chip').forEach(c => {
                c.classList.add('selected');
                c.querySelector('input').checked = true;
            });
            updatePickerCount();
        }

        function pickerClearAll() {
            document.querySelectorAll('.student-chip').forEach(c => {
                c.classList.remove('selected');
                c.querySelector('input').checked = false;
            });
            updatePickerCount();
        }

        function updatePickerCount() {
            const n = document.querySelectorAll('.student-chip.selected').length;
            document.getElementById('pickerCount').textContent = n + ' student' + (n === 1 ? '' : 's') + ' selected';
        }

        function getSelectedMatrics() {
            return Array.from(document.querySelectorAll('.student-chip.selected input')).map(i => i.value);
        }

        // ── Form submit ──────────────────────────────────────────────────────
        document.getElementById('createTestForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const accessType = document.querySelector('[name=access_type]:checked').value;
            const matrics = getSelectedMatrics();

            if (accessType === 'custom' && matrics.length === 0) {
                alert('Please select at least one student for a custom test.');
                return;
            }

            const btn = document.getElementById('createTestBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Test...';
            btn.disabled = true;

            const formData = new FormData(this);
            formData.append('action', 'create');
            if (accessType === 'custom') {
                formData.append('allowed_matrics', JSON.stringify(matrics));
            }

            try {
                const response = await fetch('api/tests.php', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    document.getElementById('createTestForm').style.display = 'none';
                    document.getElementById('addQuestionsLink').href =
                        'questions.php?course=' + (courseSelect.value || '');

                    if (accessType === 'custom') {
                        document.getElementById('generalLinkSection').style.display = 'none';
                        document.getElementById('customLinkSection').style.display  = 'block';
                        document.getElementById('customTestLink').value = data.custom_link;
                        document.getElementById('previewTitle').textContent = '🔒 Custom Test Created!';
                        document.getElementById('previewSubtitle').textContent =
                            'Share this restricted link with your ' + matrics.length + ' selected student(s):';
                    } else {
                        document.getElementById('testLink').value   = data.test_link;
                        document.getElementById('accessCode').textContent = data.access_code;
                        startCountdown(data.link_expires_at);
                    }

                    document.getElementById('linkPreview').style.display = 'block';
                    document.getElementById('linkPreview').scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('❌ ' + (data.message || 'Failed to create test.'));
                    btn.innerHTML = '<i class="fas fa-plus-circle"></i> Create Test';
                    btn.disabled = false;
                }
            } catch (error) {
                alert('❌ Network error. Please try again.');
                btn.innerHTML = '<i class="fas fa-plus-circle"></i> Create Test';
                btn.disabled = false;
            }
        });

        // ── Copy functions ───────────────────────────────────────────────────
        function copyTestLink() {
            const val = document.getElementById('testLink').value;
            navigator.clipboard.writeText(val).then(() => alert('✅ Link copied!')).catch(() => prompt('Copy:', val));
        }
        function copyCustomTestLink() {
            const val = document.getElementById('customTestLink').value;
            navigator.clipboard.writeText(val).then(() => alert('✅ Link copied!')).catch(() => prompt('Copy:', val));
        }

        // ── Countdown ────────────────────────────────────────────────────────
        function startCountdown(expiresAt) {
            const display = document.getElementById('countdownDisplay');
            if (!display) return;
            function update() {
                const diff = new Date(expiresAt).getTime() - Date.now();
                if (diff <= 0) { display.textContent = '⚠️ Link expired'; return; }
                const h = Math.floor(diff / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                display.textContent = `Time remaining: ${h}h ${m}m ${s}s`;
            }
            update(); setInterval(update, 1000);
        }
    </script>
</body>
</html>