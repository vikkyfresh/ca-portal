<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($adminName) . '&background=1e3a8a&color=fff&size=80&bold=true';
if (!empty($_SESSION['admin_id'])) {
    $_spStmt = $pdo->prepare('SELECT photo FROM admins WHERE id = ? LIMIT 1');
    $_spStmt->execute([$_SESSION['admin_id']]);
    $_spRow = $_spStmt->fetch();
    if (!empty($_spRow['photo'])) {
        $_sp = dirname(__DIR__) . '/' . ltrim($_spRow['photo'], '/');
        if (file_exists($_sp)) $photoSrc = '../' . ltrim($_spRow['photo'], '/');
    }
}

// ── Handle face enroll POST (admin-side direct enroll) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enroll') {
    header('Content-Type: application/json');
    $matric     = strtoupper(trim($_POST['matric'] ?? ''));
    $descriptor = trim($_POST['descriptor'] ?? '');
    if (!preg_match('/^\d{2}CS\d{4}$/', $matric) || empty($descriptor)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']); exit;
    }
    $decoded = json_decode($descriptor, true);
    if (json_last_error() !== JSON_ERROR_NONE || count($decoded) !== 128) {
        echo json_encode(['success' => false, 'message' => 'Invalid descriptor']); exit;
    }
    // Check already enrolled
    $chk = $pdo->prepare("SELECT face_descriptor FROM students WHERE matric = ?");
    $chk->execute([$matric]);
    $row = $chk->fetch();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Student not found']); exit; }
    if (!empty($row['face_descriptor'])) { echo json_encode(['success' => false, 'message' => 'Student already enrolled']); exit; }

    $stmt = $pdo->prepare("UPDATE students SET face_descriptor = ? WHERE matric = ?");
    $stmt->execute([$descriptor, $matric]);
    if ($stmt->rowCount()) {
        logAudit('face_enrolled', 'admin', $_SESSION['admin_id'] ?? null, $_SESSION['admin_name'] ?? null,
            ($_SESSION['admin_name'] ?? 'An admin') . " enrolled the face for student $matric.");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Enroll failed']);
    }
    exit;
}

// ── Generate enrollment link ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_link') {
    header('Content-Type: application/json');
    $hours   = (int)($_POST['hours'] ?? 24);
    $hours   = max(1, min(168, $hours)); // 1hr to 7 days
    $token   = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    $label   = trim($_POST['label'] ?? 'Enrollment Link');
    $label   = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    $stmt = $pdo->prepare("INSERT INTO enrollment_links (token, label, expires_at, created_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$token, $label, $expires, $_SESSION['admin_id']]);

    $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . dirname($_SERVER['PHP_SELF']) . '/../student-enroll.php?token=' . $token;
    echo json_encode(['success' => true, 'link' => $link, 'expires' => $expires]);
    exit;
}

// ── Revoke link ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_link') {
    header('Content-Type: application/json');
    $token = trim($_POST['token'] ?? '');
    $stmt  = $pdo->prepare("UPDATE enrollment_links SET revoked = 1 WHERE token = ?");
    $stmt->execute([$token]);
    echo json_encode(['success' => true]);
    exit;
}

// ── Page data ──────────────────────────────────────────────────────────────
$students = $pdo->query("SELECT matric, full_name, level, face_descriptor FROM students ORDER BY level, full_name")->fetchAll();
$links    = $pdo->query("
    SELECT el.*, a.username AS created_by_name,
           (SELECT COUNT(*) FROM students s WHERE s.face_descriptor IS NOT NULL AND s.face_descriptor != '') AS enrolled_count
    FROM enrollment_links el
    LEFT JOIN admins a ON a.id = el.created_by
    ORDER BY el.created_at DESC
    LIMIT 20
")->fetchAll();

$total_students  = count($students);
$enrolled_count  = count(array_filter($students, fn($s) => !empty($s['face_descriptor'])));
$pending_count   = $total_students - $enrolled_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Enrollment - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;min-height:100vh}
        .layout{display:flex;min-height:100vh}
        .main{flex:1;margin-left:260px;display:flex;flex-direction:column}
        .topbar{background:#fff;padding:16px 28px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
        .topbar h1{font-size:1.4rem;color:#0f172a;font-weight:700}
        .topbar p{color:#64748b;font-size:.85rem;margin-top:2px}
        .content{padding:28px;display:flex;flex-direction:column;gap:24px}

        /* Stats row */
        .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
        .stat-card{background:#fff;border-radius:14px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;align-items:center;gap:16px}
        .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
        .si-blue{background:#eff6ff;color:#1e3a8a}
        .si-green{background:#f0fdf4;color:#166534}
        .si-yellow{background:#fefce8;color:#854d0e}
        .stat-info h4{font-size:1.5rem;font-weight:800;color:#0f172a}
        .stat-info p{font-size:.8rem;color:#64748b;margin-top:2px}

        /* Cards */
        .card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
        .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
        .card-header h3{font-size:1.05rem;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px}

        /* Two-col layout */
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start}

        /* Form */
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:.85rem;font-weight:600;color:#475569;margin-bottom:6px}
        .form-group select,.form-group input{width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:.95rem;font-family:inherit;outline:none;transition:border .2s}
        .form-group select:focus,.form-group input:focus{border-color:#1e3a8a}
        .form-hint{font-size:.78rem;color:#94a3b8;margin-top:5px}

        /* Link generator card */
        .link-box{background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:18px;margin-top:16px;display:none}
        .link-box p{font-size:.8rem;color:#64748b;margin-bottom:8px;font-weight:600}
        .link-copy-row{display:flex;gap:8px}
        .link-url{flex:1;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;color:#0f172a;background:#fff;word-break:break-all;font-family:monospace}
        .btn-copy{padding:10px 16px;background:#1e3a8a;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.85rem;font-weight:600;white-space:nowrap;transition:background .2s}
        .btn-copy:hover{background:#1e40af}
        .link-meta{font-size:.78rem;color:#64748b;margin-top:8px;display:flex;align-items:center;gap:6px}
        .link-meta i{color:#f59e0b}

        /* Status box */
        .status-box{padding:12px 16px;border-radius:10px;font-size:.9rem;font-weight:500;margin-bottom:16px;display:flex;align-items:center;gap:8px;min-height:46px}
        .s-info   {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
        .s-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        .s-error  {background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .s-loading{background:#fefce8;color:#854d0e;border:1px solid #fde68a}

        /* Video */
        .video-wrap{position:relative;width:100%;border-radius:12px;overflow:hidden;background:#0f172a;display:none;margin-bottom:14px;aspect-ratio:4/3}
        #video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)}
        #overlay{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none}

        /* Progress */
        .prog-wrap{background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;display:none;margin-bottom:6px}
        .prog-fill{height:100%;background:linear-gradient(to right,#1e3a8a,#10b981);width:0%;transition:width .3s;border-radius:6px}
        .prog-label{font-size:.78rem;color:#64748b;text-align:center;margin-bottom:14px;display:none}

        /* Buttons */
        .btn-row{display:flex;flex-direction:column;gap:10px}
        .btn{width:100%;padding:12px;border:none;border-radius:10px;font-size:.92rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s}
        .btn:disabled{opacity:.45;cursor:not-allowed}
        .btn-primary{background:linear-gradient(to right,#0f172a,#1e3a8a);color:#fff}
        .btn-primary:hover:not(:disabled){opacity:.9;transform:translateY(-1px)}
        .btn-success{background:#10b981;color:#fff}
        .btn-success:hover:not(:disabled){background:#059669}
        .btn-green{background:#10b981;color:#fff;border-radius:10px;padding:11px 20px;border:none;font-weight:600;cursor:pointer;font-size:.9rem;display:flex;align-items:center;gap:6px;transition:background .2s}
        .btn-green:hover{background:#059669}
        .btn-outline{background:#fff;color:#475569;border:1px solid #e2e8f0}
        .btn-outline:hover{background:#f8fafc}

        /* Links table */
        table{width:100%;border-collapse:collapse}
        th,td{padding:11px 14px;border-bottom:1px solid #f1f5f9;font-size:.85rem;text-align:left;vertical-align:middle}
        th{color:#64748b;font-weight:600;background:#f8fafc;white-space:nowrap}
        td:first-child{font-weight:600;color:#0f172a}
        .badge{padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700;white-space:nowrap}
        .badge-ok     {background:#dcfce7;color:#166534}
        .badge-no     {background:#fef3c7;color:#92400e}
        .badge-active {background:#dbeafe;color:#1e40af}
        .badge-expired{background:#f1f5f9;color:#94a3b8}
        .badge-revoked{background:#fee2e2;color:#991b1b}
        .btn-revoke{padding:5px 12px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;transition:background .2s}
        .btn-revoke:hover{background:#fecaca}

        /* Enrollment student table */
        .enroll-table-wrap{max-height:340px;overflow-y:auto}

        @media(max-width:900px){.two-col{grid-template-columns:1fr}.main{margin-left:0}.stats-row{grid-template-columns:1fr 1fr}}
        @media(max-width:560px){.stats-row{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
<?php $activePage='face-enrollment'; require_once __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </button>
        <div>
            <h1><i class="fas fa-camera" style="color:#1e3a8a;margin-right:8px"></i>Face Enrollment</h1>
            <p>Enroll students directly or generate a shareable enrollment link.</p>
        </div>
    </div>

    <div class="content">

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h4><?= $total_students ?></h4><p>Total Students</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info"><h4><?= $enrolled_count ?></h4><p>Enrolled</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-yellow"><i class="fas fa-clock"></i></div>
                <div class="stat-info"><h4><?= $pending_count ?></h4><p>Pending Enrollment</p></div>
            </div>
        </div>

        <div class="two-col">

            <!-- LEFT: Direct Admin Enroll -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-camera" style="color:#1e3a8a"></i> Enroll a Student Directly</h3>
                </div>

                <div class="form-group">
                    <label>Select Student</label>
                    <select id="sel" onchange="onSelect()">
                        <option value="">-- Select student --</option>
                        <?php foreach($students as $s): ?>
                        <option value="<?= htmlspecialchars($s['matric']) ?>"
                                data-name="<?= htmlspecialchars($s['full_name']) ?>"
                                data-enrolled="<?= !empty($s['face_descriptor']) ? '1' : '0' ?>">
                            <?= htmlspecialchars($s['matric']) ?> — <?= htmlspecialchars($s['full_name']) ?>
                            (<?= $s['level'] ?>L) <?= !empty($s['face_descriptor']) ? '✅' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="statusBox" class="status-box s-loading">
                    <span id="statusText">⏳ Loading face models…</span>
                </div>

                <div class="video-wrap" id="videoWrap">
                    <video id="video" autoplay playsinline muted></video>
                    <canvas id="overlay"></canvas>
                </div>

                <div class="prog-wrap" id="progWrap"><div class="prog-fill" id="progFill"></div></div>
                <div class="prog-label" id="progLabel"></div>

                <div class="btn-row">
                    <button class="btn btn-primary" id="startBtn" onclick="startCamera()" style="display:none" disabled>
                        <i class="fas fa-video"></i> Start Camera
                    </button>
                    <button class="btn btn-success" id="captureBtn" onclick="doCapture()" style="display:none" disabled>
                        <i class="fas fa-circle-check"></i> Capture & Enroll
                    </button>
                    <button class="btn btn-outline" id="stopBtn" onclick="stopCamera()" style="display:none">
                        <i class="fas fa-stop"></i> Stop Camera
                    </button>
                </div>
            </div>

            <!-- RIGHT: Generate Enrollment Link -->
            <div style="display:flex;flex-direction:column;gap:20px">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-link" style="color:#1e3a8a"></i> Generate Enrollment Link</h3>
                    </div>
                    <p style="font-size:.85rem;color:#64748b;margin-bottom:18px;line-height:1.6">
                        Share this link with students so they can enroll their face on their own device.
                        Each student enters their matric number to verify they exist in the system before enrolling.
                    </p>

                    <div class="form-group">
                        <label>Link Label <span style="font-weight:400;color:#94a3b8">(e.g. "100L CSC Class Enrollment")</span></label>
                        <input type="text" id="linkLabel" placeholder="e.g. 100L Morning Session" maxlength="80">
                    </div>

                    <div class="form-group">
                        <label>Link Expiry Duration</label>
                        <select id="linkHours">
                            <option value="6">6 Hours</option>
                            <option value="12">12 Hours</option>
                            <option value="24" selected>24 Hours (1 Day)</option>
                            <option value="48">48 Hours (2 Days)</option>
                            <option value="72">72 Hours (3 Days)</option>
                            <option value="120">5 Days</option>
                            <option value="168">7 Days</option>
                        </select>
                        <div class="form-hint">Link will stop working after this duration automatically.</div>
                    </div>

                    <button class="btn-green" onclick="generateLink()" id="genBtn" style="width:100%">
                        <i class="fas fa-link"></i> Generate Link
                    </button>

                    <div class="link-box" id="linkBox">
                        <p><i class="fas fa-check-circle" style="color:#10b981"></i> Link generated — share with students:</p>
                        <div class="link-copy-row">
                            <div class="link-url" id="linkUrl"></div>
                            <button class="btn-copy" onclick="copyLink()"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                        <div class="link-meta" id="linkMeta">
                            <i class="fas fa-clock"></i> <span></span>
                        </div>
                    </div>
                </div>

                <!-- Active Links -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list" style="color:#1e3a8a"></i> Enrollment Links</h3>
                    </div>
                    <?php if (empty($links)): ?>
                        <p style="color:#94a3b8;font-size:.88rem;text-align:center;padding:20px 0">No links generated yet.</p>
                    <?php else: ?>
                    <div style="overflow-x:auto">
                    <table>
                        <thead><tr><th>Label</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach($links as $l):
                            $now     = new DateTime();
                            $exp     = new DateTime($l['expires_at']);
                            $expired = $now > $exp;
                            $revoked = (bool)$l['revoked'];
                            if ($revoked)       { $badge = 'badge-revoked'; $btext = 'Revoked'; }
                            elseif ($expired)   { $badge = 'badge-expired'; $btext = 'Expired'; }
                            else                { $badge = 'badge-active';  $btext = 'Active';  }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($l['label']) ?></td>
                            <td><span class="badge <?= $badge ?>"><?= $btext ?></span></td>
                            <td style="font-size:.78rem;color:#64748b"><?= date('d M Y, g:ia', strtotime($l['expires_at'])) ?></td>
                            <td>
                                <?php if (!$revoked && !$expired): ?>
                                <button class="btn-revoke" onclick="revokeLink('<?= htmlspecialchars($l['token']) ?>', this)">
                                    <i class="fas fa-ban"></i> Revoke
                                </button>
                                <?php else: echo '—'; endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Enrollment Status Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list-check" style="color:#1e3a8a"></i> Student Enrollment Status</h3>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" id="searchInput" placeholder="Search matric or name…"
                           oninput="filterTable()"
                           style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.85rem;outline:none;width:220px">
                </div>
            </div>
            <div class="enroll-table-wrap">
            <table id="enrollTable">
                <thead><tr><th>Matric</th><th>Name</th><th>Level</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($students as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['matric']) ?></td>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?= (int)$s['level'] ?>L</td>
                    <td><span class="badge <?= !empty($s['face_descriptor']) ? 'badge-ok' : 'badge-no' ?>">
                        <?= !empty($s['face_descriptor']) ? '✅ Enrolled' : '⏳ Pending' ?>
                    </span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

    </div><!-- /content -->
</main>

<script src="../assets/js/face-api.min.js"></script>
<script>
// ── globals ────────────────────────────────────────────────────────────────
var MODELS_LOADED = false;
var CAM_STREAM    = null;
var LOOP_ON       = false;
var SEL_MATRIC    = '';
var SEL_NAME      = '';

var MODEL_URLS = [
    window.location.origin + '/ca-portal/assets/js/models',
    'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights'
];

function ss(msg, type) {
    document.getElementById('statusText').innerHTML = msg;
    document.getElementById('statusBox').className = 'status-box s-' + (type || 'info');
}

// ── Load models ────────────────────────────────────────────────────────────
async function tryLoadModels(url) {
    await faceapi.nets.tinyFaceDetector.loadFromUri(url);
    await faceapi.nets.faceLandmark68Net.loadFromUri(url);
    await faceapi.nets.faceRecognitionNet.loadFromUri(url);
}

async function loadModels() {
    if (typeof faceapi === 'undefined') {
        ss('❌ face-api.js not found. Check assets/js/face-api.min.js exists.', 'error');
        return;
    }
    for (var i = 0; i < MODEL_URLS.length; i++) {
        try {
            ss('⏳ Loading models (' + (i === 0 ? 'CDN' : i === 1 ? 'GitHub' : 'Local') + ')…', 'loading');
            await tryLoadModels(MODEL_URLS[i]);
            MODELS_LOADED = true;
            if (SEL_MATRIC) {
                var startBtn = document.getElementById('startBtn');
                startBtn.style.display = 'flex';
                startBtn.disabled = false;
                ss('✅ Ready! <strong>' + SEL_NAME + '</strong> selected. Click Start Camera.', 'success');
            } else {
                ss('✅ Models ready! Select a student to begin.', 'success');
            }
            return;
        } catch(e) {
            console.warn('Model source ' + MODEL_URLS[i] + ' failed:', e.message);
            if (i === MODEL_URLS.length - 1) {
                ss('❌ All model sources failed. Check your internet or local models folder.', 'error');
            }
        }
    }
}

// ── Student select ─────────────────────────────────────────────────────────
function onSelect() {
    var sel      = document.getElementById('sel');
    var opt      = sel.options[sel.selectedIndex];
    var enrolled = opt.dataset.enrolled === '1';
    if (opt.value) {
        SEL_MATRIC = opt.value;
        SEL_NAME   = opt.dataset.name;
        var startBtn = document.getElementById('startBtn');
        startBtn.style.display = 'flex';

        if (enrolled) {
            startBtn.disabled = true;
            ss('⚠️ <strong>' + SEL_NAME + '</strong> is already enrolled. Cannot re-enroll.', 'error');
            return;
        }
        if (!MODELS_LOADED) {
            startBtn.disabled = true;
            ss('⏳ Models still loading — will activate automatically.', 'loading');
        } else {
            startBtn.disabled = false;
            ss('✅ Ready! Click <strong>Start Camera</strong> to begin enrollment.', 'success');
        }
    } else {
        SEL_MATRIC = '';
        document.getElementById('startBtn').style.display = 'none';
        ss('⏳ Loading face models…', 'loading');
    }
}

// ── Camera ─────────────────────────────────────────────────────────────────
async function startCamera() {
    if (!MODELS_LOADED) { ss('⏳ Models not ready yet. Please wait.', 'loading'); return; }
    if (!SEL_MATRIC)    { ss('Please select a student first.', 'error'); return; }
    ss('Starting camera…', 'loading');
    try {
        CAM_STREAM = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }
        });
        var video = document.getElementById('video');
        video.srcObject = CAM_STREAM;
        await new Promise(function(r) { video.addEventListener('loadedmetadata', r, { once: true }); });
        var canvas  = document.getElementById('overlay');
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;

        document.getElementById('videoWrap').style.display  = 'block';
        document.getElementById('captureBtn').style.display = 'flex';
        document.getElementById('captureBtn').disabled      = false;
        document.getElementById('stopBtn').style.display    = 'flex';
        document.getElementById('startBtn').style.display   = 'none';

        ss('📷 Camera on! Look straight at camera then click <strong>Capture & Enroll</strong>.', 'info');
        startLiveLoop();
    } catch(e) {
        ss('❌ Camera error: ' + e.message, 'error');
    }
}

function startLiveLoop() {
    LOOP_ON = true;
    var video  = document.getElementById('video');
    var canvas = document.getElementById('overlay');
    (async function loop() {
        while (LOOP_ON && CAM_STREAM) {
            await new Promise(function(r) { requestAnimationFrame(r); });
            if (video.readyState < 2) continue;
            try {
                var det = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                    .withFaceLandmarks();
                var ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                if (det) {
                    var dims = faceapi.matchDimensions(canvas, video, true);
                    var r    = faceapi.resizeResults(det, dims);
                    ctx.save(); ctx.translate(canvas.width, 0); ctx.scale(-1, 1);
                    var b = r.detection.box;
                    ctx.strokeStyle = '#10b981'; ctx.lineWidth = 3;
                    ctx.strokeRect(b.x, b.y, b.width, b.height);
                    ctx.fillStyle = '#10b981';
                    ctx.fillRect(b.x, b.y - 22, 72, 20);
                    ctx.fillStyle = '#fff'; ctx.font = 'bold 12px sans-serif';
                    ctx.fillText(Math.round(det.detection.score * 100) + '% conf', b.x + 4, b.y - 7);
                    ctx.restore();
                }
            } catch(e) {}
        }
    })();
}

function stopCamera() {
    LOOP_ON = false;
    if (CAM_STREAM) { CAM_STREAM.getTracks().forEach(function(t) { t.stop(); }); CAM_STREAM = null; }
    document.getElementById('video').srcObject = null;
    document.getElementById('videoWrap').style.display  = 'none';
    document.getElementById('captureBtn').style.display = 'none';
    document.getElementById('stopBtn').style.display    = 'none';
    document.getElementById('startBtn').style.display   = 'flex';
    document.getElementById('progWrap').style.display   = 'none';
    document.getElementById('progLabel').style.display  = 'none';
    ss('Camera stopped. Click Start Camera to try again.', 'info');
}

// ── Capture + enroll ───────────────────────────────────────────────────────
async function doCapture() {
    if (!CAM_STREAM || !SEL_MATRIC) return;
    document.getElementById('captureBtn').disabled = true;
    LOOP_ON = false;
    ss('🔍 Capturing face…', 'loading');

    var video = document.getElementById('video');
    var descriptors = [];

    for (var i = 0; i < 3; i++) {
        try {
            var det = await faceapi
                .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptor();
            if (det) descriptors.push(det.descriptor);
        } catch(e) {}
        await new Promise(function(r) { setTimeout(r, 300); });
    }

    if (descriptors.length === 0) {
        ss('❌ No face detected. Ensure good lighting and look directly at the camera.', 'error');
        document.getElementById('captureBtn').disabled = false;
        LOOP_ON = true; startLiveLoop();
        return;
    }

    var avg = new Float32Array(128);
    for (var d = 0; d < descriptors.length; d++)
        for (var j = 0; j < 128; j++) avg[j] += descriptors[d][j];
    for (var k = 0; k < 128; k++) avg[k] /= descriptors.length;

    var quality = Math.round((descriptors.length / 3) * 100);
    document.getElementById('progWrap').style.display  = 'block';
    document.getElementById('progLabel').style.display = 'block';
    document.getElementById('progFill').style.width    = quality + '%';
    document.getElementById('progLabel').textContent   = 'Capture quality: ' + quality + '% (' + descriptors.length + '/3 samples)';

    ss('💾 Saving enrollment…', 'loading');
    var fd = new FormData();
    fd.append('action', 'enroll');
    fd.append('matric', SEL_MATRIC);
    fd.append('descriptor', JSON.stringify(Array.from(avg)));

    try {
        var resp = await fetch('face-enrollment.php', { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.success) {
            ss('🎉 <strong>' + SEL_NAME + '</strong> enrolled successfully! Reloading…', 'success');
            stopCamera();
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            ss('❌ ' + (data.message || 'Save failed. Try again.'), 'error');
            document.getElementById('captureBtn').disabled = false;
            LOOP_ON = true; startLiveLoop();
        }
    } catch(e) {
        ss('❌ Network error: ' + e.message, 'error');
        document.getElementById('captureBtn').disabled = false;
        LOOP_ON = true; startLiveLoop();
    }
}

// ── Generate link ──────────────────────────────────────────────────────────
async function generateLink() {
    var label = document.getElementById('linkLabel').value.trim() || 'Enrollment Link';
    var hours = document.getElementById('linkHours').value;
    var btn   = document.getElementById('genBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';

    var fd = new FormData();
    fd.append('action', 'generate_link');
    fd.append('label', label);
    fd.append('hours', hours);

    try {
        var resp = await fetch('face-enrollment.php', { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.success) {
            document.getElementById('linkUrl').textContent = data.link;
            document.getElementById('linkMeta').querySelector('span').textContent =
                'Expires: ' + data.expires;
            document.getElementById('linkBox').style.display = 'block';
            btn.innerHTML = '<i class="fas fa-link"></i> Generate Another Link';
            setTimeout(function() { location.reload(); }, 3000); // refresh link table
        } else {
            alert('Failed to generate link. Please try again.');
        }
    } catch(e) {
        alert('Network error: ' + e.message);
    }
    btn.disabled = false;
}

function copyLink() {
    var url = document.getElementById('linkUrl').textContent;
    navigator.clipboard.writeText(url).then(function() {
        var btn = document.querySelector('.btn-copy');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000);
    });
}

// ── Revoke link ────────────────────────────────────────────────────────────
async function revokeLink(token, btn) {
    if (!confirm('Revoke this link? Students will no longer be able to use it.')) return;
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'revoke_link');
    fd.append('token', token);
    try {
        var resp = await fetch('face-enrollment.php', { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.success) { location.reload(); }
    } catch(e) { alert('Error revoking link.'); btn.disabled = false; }
}

// ── Search table ───────────────────────────────────────────────────────────
function filterTable() {
    var q    = document.getElementById('searchInput').value.toLowerCase();
    var rows = document.querySelectorAll('#enrollTable tbody tr');
    rows.forEach(function(r) {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

window.addEventListener('beforeunload', function() {
    LOOP_ON = false;
    if (CAM_STREAM) CAM_STREAM.getTracks().forEach(function(t) { t.stop(); });
});

loadModels();
</script>
</div>
</body>
</html>
