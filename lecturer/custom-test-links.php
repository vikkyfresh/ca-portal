<?php
session_start();
if (!isset($_SESSION['lecturer_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$lecturerId   = (int)$_SESSION['lecturer_id'];
$lecturerName = $_SESSION['lecturer_name'] ?? 'Lecturer';

// ── AJAX: Generate custom link ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    header('Content-Type: application/json');
    guardLecturerWriteJson();
    $testId  = (int)($_POST['test_id'] ?? 0);
    $matrics = json_decode($_POST['matrics'] ?? '[]', true);
    $hours   = max(1, min(168, (int)($_POST['hours'] ?? 24)));

    if (!$testId || empty($matrics)) {
        echo json_encode(['success'=>false,'message'=>'Select a test and at least one student.']); exit;
    }
    // Verify test belongs to this lecturer
    $chk = $pdo->prepare("SELECT id, course_code, test_title FROM tests WHERE id=? AND created_by=?");
    $chk->execute([$testId, $lecturerId]);
    $testRow = $chk->fetch();
    if (!$testRow) { echo json_encode(['success'=>false,'message'=>'Test not found.']); exit; }

    $token   = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

    $ins = $pdo->prepare("INSERT INTO custom_test_links (token, test_id, created_by, expires_at) VALUES (?,?,?,?)");
    $ins->execute([$token, $testId, $lecturerId, $expires]);
    $linkId = $pdo->lastInsertId();

    // Insert allowed matrics
    $insM = $pdo->prepare("INSERT IGNORE INTO custom_test_link_students (link_id, matric) VALUES (?,?)");
    foreach ($matrics as $m) {
        $m = strtoupper(trim($m));
        if ($m) $insM->execute([$linkId, $m]);
    }

    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
         . str_replace('/lecturer', '', dirname($_SERVER['PHP_SELF'])) . '/take-test-link.php?token=' . $token;

    echo json_encode(['success'=>true,'link'=>$url,'expires'=>$expires,'count'=>count($matrics)]);
    exit;
}

// ── AJAX: Revoke link ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    header('Content-Type: application/json');
    guardLecturerWriteJson();
    $token = trim($_POST['token'] ?? '');
    $pdo->prepare("UPDATE custom_test_links SET revoked=1 WHERE token=? AND created_by=?")
        ->execute([$token, $lecturerId]);
    echo json_encode(['success'=>true]); exit;
}

// ── AJAX: Get students on a link (for modal) ───────────────────────────────
if (isset($_GET['get_students'])) {
    header('Content-Type: application/json');
    $linkId = (int)($_GET['link_id'] ?? 0);
    // Verify link belongs to this lecturer
    $chk = $pdo->prepare("SELECT id FROM custom_test_links WHERE id=? AND created_by=?");
    $chk->execute([$linkId, $lecturerId]);
    if (!$chk->fetch()) { echo json_encode(['students' => []]); exit; }
    $stmt = $pdo->prepare("
        SELECT cls.matric, COALESCE(s.full_name,'—') AS full_name
        FROM custom_test_link_students cls
        LEFT JOIN students s ON s.matric = cls.matric
        WHERE cls.link_id = ?
        ORDER BY s.full_name
    ");
    $stmt->execute([$linkId]);
    echo json_encode(['students' => $stmt->fetchAll()]);
    exit;
}

// ── Page data ──────────────────────────────────────────────────────────────
// Tests by this lecturer — only non-expired ones
$tests = $pdo->prepare("
    SELECT id, course_code, test_title, level, expiry_date, start_date
    FROM tests
    WHERE created_by = ? AND is_active = 1
    AND (expiry_date IS NULL OR expiry_date >= NOW())
    ORDER BY created_at DESC
");
$tests->execute([$lecturerId]);
$tests = $tests->fetchAll();

// Levels the lecturer teaches
$levStmt = $pdo->prepare("SELECT DISTINCT level FROM lecturer_courses WHERE lecturer_id=? ORDER BY level");
$levStmt->execute([$lecturerId]);
$levels = $levStmt->fetchAll(PDO::FETCH_COLUMN);

// All students per level
$studentsByLevel = [];
foreach ($levels as $lv) {
    $s = $pdo->prepare("SELECT matric, full_name FROM students WHERE level=? ORDER BY full_name");
    $s->execute([$lv]);
    $studentsByLevel[$lv] = $s->fetchAll();
}

// Existing custom links
$links = $pdo->prepare("
    SELECT cl.*, t.course_code, t.test_title,
           (SELECT COUNT(*) FROM custom_test_link_students WHERE link_id=cl.id) AS student_count
    FROM custom_test_links cl
    JOIN tests t ON t.id=cl.test_id
    WHERE cl.created_by=?
    ORDER BY cl.created_at DESC LIMIT 30
");
$links->execute([$lecturerId]);
$links = $links->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Test Links — Lecturer Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;min-height:100vh}
        .layout{display:flex;min-height:100vh}
        .main{flex:1;margin-left:260px;display:flex;flex-direction:column}
        .topbar{background:#fff;padding:16px 28px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50}
        .topbar h1{font-size:1.3rem;color:#0f172a;font-weight:700}
        .topbar p{font-size:.82rem;color:#64748b;margin-top:2px}
        .content{padding:28px;display:flex;flex-direction:column;gap:24px}

        .card{background:#fff;border-radius:16px;padding:26px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
        .card-title{font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:8px}

        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:.84rem;font-weight:600;color:#475569;margin-bottom:6px}
        .form-group select,.form-group input{width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:.9rem;font-family:inherit;outline:none;transition:border .2s}
        .form-group select:focus,.form-group input:focus{border-color:#1e3a8a}
        .form-hint{font-size:.76rem;color:#94a3b8;margin-top:4px}

        /* Student picker */
        .picker-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        .picker-header label{font-size:.84rem;font-weight:600;color:#475569}
        .picker-actions{display:flex;gap:8px}
        .picker-action-btn{padding:5px 12px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;font-size:.78rem;font-weight:600;color:#475569;cursor:pointer;transition:all .2s}
        .picker-action-btn:hover{background:#e2e8f0}
        .student-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;max-height:280px;overflow-y:auto;border:2px solid #e2e8f0;border-radius:10px;padding:12px;background:#f8fafc}
        .student-chip{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:all .2s;font-size:.82rem;user-select:none}
        .student-chip:hover{border-color:#1e3a8a;background:#eff6ff}
        .student-chip.selected{background:#dbeafe;border-color:#1e3a8a}
        .student-chip input[type=checkbox]{accent-color:#1e3a8a;width:15px;height:15px;flex-shrink:0}
        .student-chip .chip-name{font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .student-chip .chip-matric{font-size:.73rem;color:#64748b}
        .selected-count{font-size:.8rem;color:#1e40af;font-weight:600;margin-top:8px}

        /* Level tabs */
        .level-tabs{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
        .level-tab{padding:6px 16px;border-radius:8px;border:2px solid #e2e8f0;background:#fff;font-size:.83rem;font-weight:600;color:#64748b;cursor:pointer;transition:all .2s}
        .level-tab.active{background:#1e3a8a;border-color:#1e3a8a;color:#fff}

        /* Link box */
        .link-box{background:#f0fdf4;border:2px solid #bbf7d0;border-radius:12px;padding:18px;display:none;margin-top:16px}
        .link-box p{font-size:.82rem;color:#166534;font-weight:600;margin-bottom:8px}
        .link-copy-row{display:flex;gap:8px;align-items:center}
        .link-url{flex:1;padding:10px 14px;border:1px solid #bbf7d0;border-radius:8px;font-size:.8rem;color:#0f172a;background:#fff;word-break:break-all;font-family:monospace}
        .btn-copy{padding:10px 16px;background:#1e3a8a;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600;white-space:nowrap;transition:background .2s}
        .btn-copy:hover{background:#1e40af}
        .link-meta{font-size:.76rem;color:#4ade80;margin-top:8px;display:flex;gap:16px}
        .link-meta span{display:flex;align-items:center;gap:4px}

        .btn-generate{width:100%;padding:13px;background:linear-gradient(to right,#0f172a,#1e3a8a);color:#fff;border:none;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;margin-top:4px}
        .btn-generate:hover{opacity:.9;transform:translateY(-1px)}
        .btn-generate:disabled{opacity:.5;cursor:not-allowed;transform:none}

        /* Links table */
        table{width:100%;border-collapse:collapse}
        th,td{padding:11px 14px;border-bottom:1px solid #f1f5f9;font-size:.84rem;text-align:left;vertical-align:middle}
        th{background:#f8fafc;color:#64748b;font-weight:600;white-space:nowrap}
        .badge{padding:3px 10px;border-radius:20px;font-size:.74rem;font-weight:700}
        .badge-active{background:#dbeafe;color:#1e40af}
        .badge-expired{background:#f1f5f9;color:#94a3b8}
        .badge-revoked{background:#fee2e2;color:#991b1b}
        .btn-revoke{padding:4px 11px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:.76rem;font-weight:600;cursor:pointer;transition:background .2s}
        .btn-revoke:hover{background:#fecaca}
        .btn-view-students{padding:4px 11px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:6px;font-size:.76rem;font-weight:600;cursor:pointer;transition:background .2s}
        .btn-view-students:hover{background:#dbeafe}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px}
        .modal-overlay.open{display:flex}
        .modal-card{background:#fff;border-radius:16px;padding:26px;max-width:440px;width:100%;max-height:80vh;overflow-y:auto}
        .modal-card h3{font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:16px}
        .modal-matric-list{display:flex;flex-direction:column;gap:6px}
        .modal-matric-row{padding:8px 12px;background:#f8fafc;border-radius:8px;font-size:.83rem;display:flex;justify-content:space-between}
        .modal-matric-row strong{color:#0f172a}
        .modal-matric-row span{color:#64748b}
        .modal-close{width:100%;padding:10px;background:#f1f5f9;border:none;border-radius:8px;font-weight:600;cursor:pointer;margin-top:16px;font-size:.88rem}

        @media(max-width:900px){.form-grid{grid-template-columns:1fr}.main{margin-left:0}.student-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:560px){.student-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
<?php $activePage='custom-links'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
        <div>
            <h1><i class="fas fa-link" style="color:#1e3a8a;margin-right:8px"></i>Custom Test Links</h1>
            <p>Generate a restricted test link for specific students only.</p>
        </div>
        <a href="tests.php" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:6px">
            <i class="fas fa-arrow-left"></i> My Tests
        </a>
    </div>

    <div class="content">

        <!-- Generator card -->
        <div class="card">
            <div class="card-title"><i class="fas fa-plus-circle" style="color:#1e3a8a"></i> Generate a New Link</div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Select Test</label>
                    <select id="testSelect">
                        <option value="">-- Choose a test --</option>
                        <?php if(empty($tests)): ?>
                        <option value="" disabled>No active tests available — create a test first</option>
                        <?php else: ?>
                        <?php foreach($tests as $t): 
                            $expiry = $t['expiry_date'] ? ' · Expires ' . date('d M Y, g:ia', strtotime($t['expiry_date'])) : '';
                        ?>
                        <option value="<?= $t['id'] ?>" data-level="<?= $t['level'] ?>">
                            <?= htmlspecialchars($t['course_code']) ?> — <?= htmlspecialchars($t['test_title']) ?> (<?= $t['level'] ?>L)<?= $expiry ?>
                        </option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Link Expiry</label>
                    <select id="linkHours">
                        <option value="6">6 Hours</option>
                        <option value="12">12 Hours</option>
                        <option value="24" selected>24 Hours (1 Day)</option>
                        <option value="48">48 Hours (2 Days)</option>
                        <option value="72">72 Hours (3 Days)</option>
                        <option value="120">5 Days</option>
                        <option value="168">7 Days</option>
                    </select>
                    <div class="form-hint">Link stops working after this duration.</div>
                </div>
            </div>

            <!-- Level tabs + student picker -->
            <div class="form-group">
                <div class="picker-header">
                    <label>Select Students (from your class attendance)</label>
                    <div class="picker-actions">
                        <button class="picker-action-btn" onclick="selectAll()"><i class="fas fa-check-square"></i> Select All</button>
                        <button class="picker-action-btn" onclick="clearAll()"><i class="fas fa-square"></i> Clear</button>
                    </div>
                </div>

                <div class="level-tabs" id="levelTabs">
                    <?php foreach($levels as $i => $lv): ?>
                    <button class="level-tab <?= $i===0?'active':'' ?>" onclick="switchLevel(<?= $lv ?>, this)">
                        <?= $lv ?>L
                    </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach($levels as $i => $lv): ?>
                <div class="student-grid" id="level-<?= $lv ?>" style="<?= $i>0?'display:none':'' ?>">
                    <?php foreach($studentsByLevel[$lv] as $s): ?>
                    <div class="student-chip" onclick="toggleChip(this)" data-matric="<?= htmlspecialchars($s['matric']) ?>">
                        <input type="checkbox" value="<?= htmlspecialchars($s['matric']) ?>">
                        <div>
                            <div class="chip-name"><?= htmlspecialchars($s['full_name']) ?></div>
                            <div class="chip-matric"><?= htmlspecialchars($s['matric']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($studentsByLevel[$lv])): ?>
                    <p style="color:#94a3b8;font-size:.84rem;padding:8px">No students found for this level.</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="selected-count" id="selectedCount">0 students selected</div>
            </div>

            <button class="btn-generate" id="genBtn" onclick="generateLink()">
                <i class="fas fa-link"></i> Generate Custom Link
            </button>

            <div class="link-box" id="linkBox">
                <p><i class="fas fa-check-circle"></i> Custom link created — share with the selected students:</p>
                <div class="link-copy-row">
                    <div class="link-url" id="linkUrl"></div>
                    <button class="btn-copy" onclick="copyLink()"><i class="fas fa-copy"></i> Copy</button>
                </div>
                <div class="link-meta">
                    <span><i class="fas fa-clock"></i> <span id="linkExpiry"></span></span>
                    <span><i class="fas fa-users"></i> <span id="linkStudentCount"></span> students</span>
                </div>
            </div>
        </div>

        <!-- Existing links -->
        <div class="card">
            <div class="card-title"><i class="fas fa-list" style="color:#1e3a8a"></i> Generated Links</div>
            <?php if(empty($links)): ?>
            <p style="color:#94a3b8;font-size:.87rem;text-align:center;padding:24px 0">No custom links generated yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr><th>Test</th><th>Students</th><th>Status</th><th>Expires</th><th>Created</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach($links as $l):
                    $expired = strtotime($l['expires_at']) < time();
                    $revoked = (bool)$l['revoked'];
                    if ($revoked)     { $badge='badge-revoked'; $btext='Revoked'; }
                    elseif($expired)  { $badge='badge-expired'; $btext='Expired'; }
                    else              { $badge='badge-active';  $btext='Active';  }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($l['course_code']) ?></strong><br>
                        <span style="font-size:.76rem;color:#64748b"><?= htmlspecialchars($l['test_title']) ?></span></td>
                    <td><span style="font-weight:700;color:#1e3a8a"><?= $l['student_count'] ?></span> students</td>
                    <td><span class="badge <?= $badge ?>"><?= $btext ?></span></td>
                    <td style="font-size:.78rem;color:#64748b"><?= date('d M Y, g:ia', strtotime($l['expires_at'])) ?></td>
                    <td style="font-size:.78rem;color:#64748b"><?= date('d M Y', strtotime($l['created_at'])) ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                        <button class="btn-view-students" onclick="viewStudents(<?= $l['id'] ?>)">
                            <i class="fas fa-users"></i> Students
                        </button>
                        <?php if(!$revoked && !$expired): ?>
                        <button class="btn-copy" style="padding:4px 11px;font-size:.76rem"
                            onclick="copyCustomLink('<?= htmlspecialchars($l['token']) ?>')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <button class="btn-revoke" onclick="revokeLink('<?= htmlspecialchars($l['token']) ?>', this)">
                            <i class="fas fa-ban"></i> Revoke
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Students modal -->
<div class="modal-overlay" id="studentsModal">
    <div class="modal-card">
        <h3><i class="fas fa-users" style="color:#1e3a8a;margin-right:6px"></i> Allowed Students</h3>
        <div class="modal-matric-list" id="modalMatricList">Loading…</div>
        <button class="modal-close" onclick="closeModal()">Close</button>
    </div>
</div>

<script>
var currentLevel = <?= json_encode($levels[0] ?? null) ?>;

function switchLevel(lv, btn) {
    document.querySelectorAll('.student-grid').forEach(function(g) { g.style.display='none'; });
    document.querySelectorAll('.level-tab').forEach(function(t) { t.classList.remove('active'); });
    document.getElementById('level-'+lv).style.display='grid';
    btn.classList.add('active');
    currentLevel = lv;
}

function toggleChip(chip) {
    chip.classList.toggle('selected');
    chip.querySelector('input').checked = chip.classList.contains('selected');
    updateCount();
}

function selectAll() {
    var grid = document.getElementById('level-'+currentLevel);
    if (!grid) return;
    grid.querySelectorAll('.student-chip').forEach(function(c) {
        c.classList.add('selected');
        c.querySelector('input').checked = true;
    });
    updateCount();
}

function clearAll() {
    document.querySelectorAll('.student-chip').forEach(function(c) {
        c.classList.remove('selected');
        c.querySelector('input').checked = false;
    });
    updateCount();
}

function updateCount() {
    var n = document.querySelectorAll('.student-chip.selected').length;
    document.getElementById('selectedCount').textContent = n + ' student' + (n===1?'':'s') + ' selected';
}

function getSelectedMatrics() {
    return Array.from(document.querySelectorAll('.student-chip.selected input'))
        .map(function(i){return i.value;});
}

async function generateLink() {
    var testId = document.getElementById('testSelect').value;
    var hours  = document.getElementById('linkHours').value;
    var mats   = getSelectedMatrics();

    if (!testId) { alert('Please select a test first.'); return; }
    if (mats.length === 0) { alert('Please select at least one student.'); return; }

    var btn = document.getElementById('genBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';

    var fd = new FormData();
    fd.append('action', 'generate');
    fd.append('test_id', testId);
    fd.append('hours', hours);
    fd.append('matrics', JSON.stringify(mats));

    try {
        var resp = await fetch('custom-test-links.php', { method:'POST', body:fd });
        var data = await resp.json();
        if (data.success) {
            document.getElementById('linkUrl').textContent = data.link;
            document.getElementById('linkExpiry').textContent = 'Expires: ' + data.expires;
            document.getElementById('linkStudentCount').textContent = data.count;
            document.getElementById('linkBox').style.display = 'block';
            btn.innerHTML = '<i class="fas fa-link"></i> Generate Another Link';
            setTimeout(function(){location.reload();}, 4000);
        } else {
            alert(data.message || 'Failed to generate. Try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-link"></i> Generate Custom Link';
        }
    } catch(e) {
        alert('Network error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-link"></i> Generate Custom Link';
    }
}

function copyLink() {
    var url = document.getElementById('linkUrl').textContent;
    navigator.clipboard.writeText(url).then(function(){
        var btn = document.querySelector('#linkBox .btn-copy');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function(){ btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000);
    });
}

function copyCustomLink(token) {
    var base = window.location.origin + window.location.pathname.replace('/lecturer/custom-test-links.php','');
    var url  = base + '/take-test-link.php?token=' + token;
    navigator.clipboard.writeText(url).then(function(){
        alert('Link copied to clipboard!');
    }).catch(function(){
        prompt('Copy this link:', url);
    });
}

async function revokeLink(token, btn) {
    if (!confirm('Revoke this link? Students will no longer be able to use it.')) return;
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'revoke');
    fd.append('token', token);
    try {
        var resp = await fetch('custom-test-links.php', { method:'POST', body:fd });
        var data = await resp.json();
        if (data.success) location.reload();
    } catch(e) { alert('Error.'); btn.disabled = false; }
}

async function viewStudents(linkId) {
    document.getElementById('modalMatricList').innerHTML = 'Loading…';
    document.getElementById('studentsModal').classList.add('open');
    try {
        var resp = await fetch('custom-test-links.php?get_students=1&link_id=' + linkId);
        var data = await resp.json();
        if (data.students && data.students.length) {
            var html = data.students.map(function(s){
                return '<div class="modal-matric-row"><strong>' + s.matric + '</strong><span>' + s.full_name + '</span></div>';
            }).join('');
            document.getElementById('modalMatricList').innerHTML = html;
        } else {
            document.getElementById('modalMatricList').innerHTML = '<p style="color:#94a3b8;font-size:.85rem">No students found.</p>';
        }
    } catch(e) {
        document.getElementById('modalMatricList').innerHTML = '<p style="color:#ef4444">Error loading students.</p>';
    }
}

function closeModal() { document.getElementById('studentsModal').classList.remove('open'); }
document.getElementById('studentsModal').addEventListener('click', function(e){ if(e.target===this)closeModal(); });
</script>
</body>
</html>
