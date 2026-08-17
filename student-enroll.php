<?php
/**
 * student-enroll.php
 * Sits in the ROOT of your project (same level as the admin/ folder)
 * URL: yoursite.com/student-enroll.php?token=xxxx
 */
session_start();
require_once 'includes/config.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$student = null;
$link_row = null;

// ── Validate token ─────────────────────────────────────────────────────────
if (empty($token)) {
    $error = 'No enrollment link provided. Please use the link sent to you.';
} else {
    $stmt = $pdo->prepare("SELECT * FROM enrollment_links WHERE token = ?");
    $stmt->execute([$token]);
    $link_row = $stmt->fetch();

    if (!$link_row) {
        $error = 'This enrollment link is invalid.';
    } elseif ($link_row['revoked']) {
        $error = 'This enrollment link has been revoked by the administrator.';
    } elseif (strtotime($link_row['expires_at']) < time()) {
        $error = 'This enrollment link has expired. Please contact your administrator for a new link.';
    }
}

// ── Handle matric lookup (AJAX) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_matric') {
    header('Content-Type: application/json');
    $matric = strtoupper(trim($_POST['matric'] ?? ''));
    $tok    = trim($_POST['token'] ?? '');

    // Re-validate token
    $lk = $pdo->prepare("SELECT * FROM enrollment_links WHERE token = ? AND revoked = 0 AND expires_at > NOW()");
    $lk->execute([$tok]);
    if (!$lk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Link is no longer valid.']); exit;
    }

    $st = $pdo->prepare("SELECT matric, full_name, level, face_descriptor FROM students WHERE matric = ?");
    $st->execute([$matric]);
    $row = $st->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Matric number not found. Make sure you entered it correctly (e.g. 22CS0001).']); exit;
    }
    if (!empty($row['face_descriptor'])) {
        echo json_encode(['success' => false, 'message' => 'Your face is already enrolled. You do not need to enroll again.']); exit;
    }
    echo json_encode(['success' => true, 'name' => $row['full_name'], 'level' => $row['level'], 'matric' => $row['matric']]);
    exit;
}

// ── Handle face save (AJAX) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_face') {
    header('Content-Type: application/json');
    $matric     = strtoupper(trim($_POST['matric'] ?? ''));
    $descriptor = trim($_POST['descriptor'] ?? '');
    $tok        = trim($_POST['token'] ?? '');

    // Re-validate token
    $lk = $pdo->prepare("SELECT * FROM enrollment_links WHERE token = ? AND revoked = 0 AND expires_at > NOW()");
    $lk->execute([$tok]);
    if (!$lk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Link is no longer valid.']); exit;
    }

    if (!preg_match('/^\d{2}CS\d{4}$/', $matric) || empty($descriptor)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data submitted.']); exit;
    }
    $decoded = json_decode($descriptor, true);
    if (json_last_error() !== JSON_ERROR_NONE || count($decoded) !== 128) {
        echo json_encode(['success' => false, 'message' => 'Invalid face data. Please try again.']); exit;
    }

    // Final check — already enrolled?
    $chk = $pdo->prepare("SELECT face_descriptor FROM students WHERE matric = ?");
    $chk->execute([$matric]);
    $row = $chk->fetch();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Student not found.']); exit; }
    if (!empty($row['face_descriptor'])) { echo json_encode(['success' => false, 'message' => 'Already enrolled — no need to enroll again.']); exit; }

    $upd = $pdo->prepare("UPDATE students SET face_descriptor = ? WHERE matric = ?");
    $upd->execute([$descriptor, $matric]);
    echo json_encode($upd->rowCount() ? ['success' => true] : ['success' => false, 'message' => 'Save failed. Contact your administrator.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Enrollment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .wrap{background:#fff;border-radius:20px;padding:36px 32px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
        .logo{text-align:center;margin-bottom:28px}
        .logo-icon{width:64px;height:64px;background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;color:#fff;margin-bottom:12px}
        .logo h2{font-size:1.3rem;color:#0f172a;font-weight:800}
        .logo p{font-size:.85rem;color:#64748b;margin-top:4px}

        /* Step indicator */
        .steps{display:flex;justify-content:center;gap:0;margin-bottom:28px}
        .step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative}
        .step:not(:last-child)::after{content:'';position:absolute;top:14px;left:calc(50% + 14px);right:calc(-50% + 14px);height:2px;background:#e2e8f0;z-index:0}
        .step-dot{width:28px;height:28px;border-radius:50%;background:#e2e8f0;color:#94a3b8;font-size:.8rem;font-weight:700;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;transition:all .3s}
        .step.active .step-dot{background:#1e3a8a;color:#fff}
        .step.done .step-dot{background:#10b981;color:#fff}
        .step-label{font-size:.7rem;color:#94a3b8;margin-top:5px;font-weight:600}
        .step.active .step-label{color:#1e3a8a}
        .step.done .step-label{color:#10b981}

        /* Alert */
        .alert{padding:14px 18px;border-radius:12px;font-size:.9rem;font-weight:500;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;line-height:1.5}
        .alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .alert-info {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
        .alert-warn {background:#fefce8;color:#854d0e;border:1px solid #fde68a}
        .alert-ok   {background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

        /* Form */
        .form-group{margin-bottom:18px}
        .form-group label{display:block;font-size:.85rem;font-weight:600;color:#475569;margin-bottom:7px}
        .form-group input{width:100%;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;font-size:1rem;font-family:inherit;outline:none;transition:border .2s;text-transform:uppercase;letter-spacing:.05em}
        .form-group input:focus{border-color:#1e3a8a}
        .form-hint{font-size:.78rem;color:#94a3b8;margin-top:5px}

        /* Student badge */
        .student-badge{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px}
        .student-avatar{width:44px;height:44px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#166534;flex-shrink:0}
        .student-info h4{font-size:.95rem;font-weight:700;color:#166534}
        .student-info p{font-size:.78rem;color:#4ade80;margin-top:2px}

        /* Video */
        .video-wrap{position:relative;width:100%;border-radius:14px;overflow:hidden;background:#0f172a;margin-bottom:14px;aspect-ratio:4/3;display:none}
        #video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)}
        #overlay{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none}

        /* Progress */
        .prog-wrap{background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;display:none;margin-bottom:6px}
        .prog-fill{height:100%;background:linear-gradient(to right,#1e3a8a,#10b981);width:0%;transition:width .3s;border-radius:6px}
        .prog-label{font-size:.78rem;color:#64748b;text-align:center;margin-bottom:14px;display:none}

        /* Buttons */
        .btn{width:100%;padding:13px;border:none;border-radius:12px;font-size:.95rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;margin-bottom:10px}
        .btn:disabled{opacity:.45;cursor:not-allowed}
        .btn:last-child{margin-bottom:0}
        .btn-primary{background:linear-gradient(to right,#0f172a,#1e3a8a);color:#fff}
        .btn-primary:hover:not(:disabled){opacity:.9;transform:translateY(-1px)}
        .btn-success{background:#10b981;color:#fff}
        .btn-success:hover:not(:disabled){background:#059669}
        .btn-outline{background:#fff;color:#64748b;border:2px solid #e2e8f0}
        .btn-outline:hover{background:#f8fafc}

        /* Success screen */
        .success-screen{text-align:center;padding:10px 0}
        .success-icon{width:80px;height:80px;background:#dcfce7;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:2.5rem;margin-bottom:20px}
        .success-screen h3{font-size:1.3rem;font-weight:800;color:#0f172a;margin-bottom:8px}
        .success-screen p{color:#64748b;font-size:.9rem;line-height:1.6}

        /* Status box */
        .status-box{padding:11px 16px;border-radius:10px;font-size:.88rem;font-weight:500;margin-bottom:14px;display:flex;align-items:center;gap:8px}
        .s-loading{background:#fefce8;color:#854d0e;border:1px solid #fde68a}
        .s-info   {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
        .s-error  {background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .s-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

        @media(max-width:480px){.wrap{padding:24px 18px}.steps{gap:0}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <div class="logo-icon"><i class="fas fa-camera"></i></div>
        <h2>Face Enrollment</h2>
        <p>Register your face to access tests on the portal</p>
    </div>

    <?php if ($error): ?>
        <!-- ── Link error state ── -->
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="font-size:1.1rem;margin-top:1px;flex-shrink:0"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php else: ?>

        <!-- Step indicator -->
        <div class="steps" id="stepIndicator">
            <div class="step active" id="step1">
                <div class="step-dot">1</div>
                <div class="step-label">Verify</div>
            </div>
            <div class="step" id="step2">
                <div class="step-dot">2</div>
                <div class="step-label">Camera</div>
            </div>
            <div class="step" id="step3">
                <div class="step-dot">3</div>
                <div class="step-label">Done</div>
            </div>
        </div>

        <!-- ── SCREEN 1: Matric entry ── -->
        <div id="screen1">
            <div class="alert alert-info">
                <i class="fas fa-info-circle" style="font-size:1.1rem;flex-shrink:0;margin-top:1px"></i>
                <div>Enter your matric number exactly as registered by your department (e.g. <strong>22CS0001</strong>). Your face will be used to verify your identity before every test.</div>
            </div>

            <div class="form-group">
                <label>Your Matric Number</label>
                <input type="text" id="matricInput" placeholder="e.g. 22CS0001" maxlength="10"
                       oninput="this.value=this.value.toUpperCase()"
                       onkeydown="if(event.key==='Enter') checkMatric()">
                <div class="form-hint">Format: YYCSXXXX (year + CS + 4 digits)</div>
            </div>

            <div id="checkStatus" style="display:none" class="status-box s-loading">
                <i class="fas fa-spinner fa-spin"></i> <span>Checking…</span>
            </div>

            <button class="btn btn-primary" onclick="checkMatric()" id="checkBtn">
                <i class="fas fa-search"></i> Verify & Continue
            </button>
        </div>

        <!-- ── SCREEN 2: Camera + capture ── -->
        <div id="screen2" style="display:none">
            <div id="studentBadge" class="student-badge">
                <div class="student-avatar"><i class="fas fa-user-graduate"></i></div>
                <div class="student-info">
                    <h4 id="badgeName">—</h4>
                    <p id="badgeInfo">—</p>
                </div>
            </div>

            <div id="camStatus" class="status-box s-loading">
                <i class="fas fa-spinner fa-spin"></i> <span id="camStatusText">Loading face models…</span>
            </div>

            <div class="video-wrap" id="videoWrap">
                <video id="video" autoplay playsinline muted></video>
                <canvas id="overlay"></canvas>
            </div>

            <div class="prog-wrap" id="progWrap"><div class="prog-fill" id="progFill"></div></div>
            <div class="prog-label" id="progLabel"></div>

            <button class="btn btn-primary" id="startBtn" onclick="startCamera()" disabled>
                <i class="fas fa-video"></i> <span id="startBtnText">Loading Models…</span>
            </button>
            <button class="btn btn-success" id="captureBtn" onclick="doCapture()" style="display:none" disabled>
                <i class="fas fa-circle-check"></i> Capture & Enroll
            </button>
            <button class="btn btn-outline" id="stopBtn" onclick="stopCamera()" style="display:none">
                <i class="fas fa-stop"></i> Stop Camera
            </button>
        </div>

        <!-- ── SCREEN 3: Success ── -->
        <div id="screen3" style="display:none">
            <div class="success-screen">
                <div class="success-icon">🎉</div>
                <h3>Enrollment Complete!</h3>
                <p>Your face has been successfully registered, <strong id="successName"></strong>.<br><br>
                You can now log in to the student portal and take your tests. Face verification will happen automatically before each test.</p>
            </div>
        </div>

    <?php endif; ?>
</div>

<script src="assets/js/face-api.min.js"></script>
<script>
var TOKEN      = <?= json_encode($token) ?>;
var MATRIC     = '';
var STU_NAME   = '';
var MODELS_OK  = false;
var CAM_STREAM = null;
var LOOP_ON    = false;

var MODEL_URLS = [
    window.location.origin + '/ca-portal/assets/js/models',
    'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights'
];

function camSS(msg, type, icon) {
    icon = icon || (type === 'loading' ? 'fas fa-spinner fa-spin' : type === 'success' ? 'fas fa-check-circle' : type === 'error' ? 'fas fa-times-circle' : 'fas fa-info-circle');
    document.getElementById('camStatusText').innerHTML = msg;
    document.getElementById('camStatus').className = 'status-box s-' + type;
    document.getElementById('camStatus').querySelector('i').className = icon;
}

// ── Load models ────────────────────────────────────────────────────────────
async function tryLoadModels(url) {
    await faceapi.nets.tinyFaceDetector.loadFromUri(url);
    await faceapi.nets.faceLandmark68Net.loadFromUri(url);
    await faceapi.nets.faceRecognitionNet.loadFromUri(url);
}

async function loadModels() {
    if (typeof faceapi === 'undefined') {
        camSS('face-api.js failed to load. Please refresh the page.', 'error'); return;
    }
    for (var i = 0; i < MODEL_URLS.length; i++) {
        try {
            camSS('Loading models…', 'loading');
            await tryLoadModels(MODEL_URLS[i]);
            MODELS_OK = true;
            document.getElementById('startBtn').disabled = false;
            document.getElementById('startBtnText').textContent = 'Start Camera';
            camSS('Models ready! Click Start Camera to begin.', 'success');
            return;
        } catch(e) {
            if (i === MODEL_URLS.length - 1) {
                camSS('Failed to load face models. Check your connection and refresh.', 'error');
            }
        }
    }
}

// ── Step helpers ───────────────────────────────────────────────────────────
function setStep(n) {
    [1,2,3].forEach(function(i) {
        var el = document.getElementById('step'+i);
        el.className = 'step' + (i < n ? ' done' : i === n ? ' active' : '');
        if (i < n) el.querySelector('.step-dot').innerHTML = '<i class="fas fa-check" style="font-size:.65rem"></i>';
    });
}

// ── Check matric ───────────────────────────────────────────────────────────
async function checkMatric() {
    var m = document.getElementById('matricInput').value.trim().toUpperCase();
    if (!m) { showCheckStatus('Please enter your matric number.', 'error'); return; }

    var btn = document.getElementById('checkBtn');
    btn.disabled = true;
    showCheckStatus('Checking your matric number…', 'loading');

    var fd = new FormData();
    fd.append('action', 'check_matric');
    fd.append('matric', m);
    fd.append('token', TOKEN);

    try {
        var resp = await fetch(window.location.href, { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.success) {
            MATRIC   = data.matric;
            STU_NAME = data.name;
            document.getElementById('badgeName').textContent = data.name;
            document.getElementById('badgeInfo').textContent = data.matric + ' · ' + data.level + 'L';
            document.getElementById('successName').textContent = data.name;
            document.getElementById('screen1').style.display = 'none';
            document.getElementById('screen2').style.display = 'block';
            setStep(2);
            loadModels();
        } else {
            showCheckStatus(data.message, 'error');
            btn.disabled = false;
        }
    } catch(e) {
        showCheckStatus('Network error. Please check your connection.', 'error');
        btn.disabled = false;
    }
}

function showCheckStatus(msg, type) {
    var box = document.getElementById('checkStatus');
    box.style.display = 'flex';
    box.className = 'status-box s-' + type;
    var icon = type === 'loading' ? 'fas fa-spinner fa-spin' : type === 'error' ? 'fas fa-times-circle' : 'fas fa-info-circle';
    box.innerHTML = '<i class="' + icon + '"></i> <span>' + msg + '</span>';
}

// ── Camera ─────────────────────────────────────────────────────────────────
async function startCamera() {
    if (!MODELS_OK) { camSS('Models not ready yet. Please wait.', 'warn'); return; }
    camSS('Starting camera…', 'loading');
    try {
        CAM_STREAM = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }
        });
        var video = document.getElementById('video');
        video.srcObject = CAM_STREAM;
        await new Promise(function(r) { video.addEventListener('loadedmetadata', r, { once: true }); });
        var canvas = document.getElementById('overlay');
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;

        document.getElementById('videoWrap').style.display  = 'block';
        document.getElementById('captureBtn').style.display = 'flex';
        document.getElementById('captureBtn').disabled      = false;
        document.getElementById('stopBtn').style.display    = 'flex';
        document.getElementById('startBtn').style.display   = 'none';
        camSS('📷 Camera on! Face the camera and click <strong>Capture & Enroll</strong>.', 'info');
        startLiveLoop();
    } catch(e) {
        camSS('Camera error: ' + e.message + '. Please allow camera access.', 'error');
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
    document.getElementById('startBtn').disabled        = false;
    document.getElementById('progWrap').style.display   = 'none';
    document.getElementById('progLabel').style.display  = 'none';
    camSS('Camera stopped. Click Start Camera to try again.', 'info');
}

// ── Capture ────────────────────────────────────────────────────────────────
async function doCapture() {
    if (!CAM_STREAM || !MATRIC) return;
    document.getElementById('captureBtn').disabled = true;
    LOOP_ON = false;
    camSS('🔍 Detecting your face…', 'loading');

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
        camSS('❌ No face detected. Make sure your face is clearly visible and lighting is good.', 'error');
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

    camSS('💾 Saving your face data…', 'loading');

    var fd = new FormData();
    fd.append('action', 'save_face');
    fd.append('matric', MATRIC);
    fd.append('token', TOKEN);
    fd.append('descriptor', JSON.stringify(Array.from(avg)));

    try {
        var resp = await fetch(window.location.href, { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.success) {
            stopCamera();
            document.getElementById('screen2').style.display = 'none';
            document.getElementById('screen3').style.display = 'block';
            setStep(3);
        } else {
            camSS('❌ ' + (data.message || 'Failed to save. Please try again.'), 'error');
            document.getElementById('captureBtn').disabled = false;
            LOOP_ON = true; startLiveLoop();
        }
    } catch(e) {
        camSS('❌ Network error: ' + e.message, 'error');
        document.getElementById('captureBtn').disabled = false;
        LOOP_ON = true; startLiveLoop();
    }
}

window.addEventListener('beforeunload', function() {
    LOOP_ON = false;
    if (CAM_STREAM) CAM_STREAM.getTracks().forEach(function(t) { t.stop(); });
});
</script>
</body>
</html>
