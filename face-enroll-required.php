<?php
/**
 * face-enroll-required.php
 * Reached two ways:
 *  1. Directly from student-login.php, the moment check-student.php reports
 *     an unenrolled face — the student hasn't verified anything yet, they're
 *     only identified via pending_verify_matric (set as soon as their matric
 *     was looked up). This is the primary, expected path.
 *  2. From take-test.php, for the edge case of a student who reached the
 *     test page (e.g. via a face-verify-not-required custom link) but still
 *     has no face on file — identified via the existing face_verified flag.
 */
session_start();
require_once 'includes/config.php';

$matric = strtoupper(trim($_GET['matric'] ?? $_SESSION['authenticated_matric'] ?? $_SESSION['student_matric'] ?? $_SESSION['pending_verify_matric'] ?? ''));

$viaPendingLogin = $matric && (($_SESSION['pending_verify_matric'] ?? '') === $matric);
$viaVerifiedSession = $matric && !empty($_SESSION['face_verified']);

if (!$matric || (!$viaPendingLogin && !$viaVerifiedSession)) {
    header('Location: student-login.php'); exit;
}

$testId = intval($_GET['test_id'] ?? 0);

// Double check — if they've already enrolled since being redirected here, let them through
$chk = $pdo->prepare("SELECT face_descriptor, full_name FROM students WHERE matric = ? LIMIT 1");
$chk->execute([$matric]);
$row = $chk->fetch();
if (!$row) { header('Location: student-login.php'); exit; }
if (!empty($row['face_descriptor'])) {
    // Already enrolled — send them onward. Someone arriving via the login
    // path still needs to go through actual face verification; someone
    // arriving with an already-verified session can go straight to their test.
    if ($viaVerifiedSession && $testId) {
        header("Location: take-test.php?test_id=$testId&matric=$matric"); exit;
    }
    header("Location: face-verify.php?matric=$matric"); exit;
}

$studentName = $row['full_name'];

// ── Handle self-enroll POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'self_enroll') {
    header('Content-Type: application/json');
    $descriptor = trim($_POST['descriptor'] ?? '');
    if (empty($descriptor)) { echo json_encode(['success'=>false,'message'=>'No face data received.']); exit; }
    $decoded = json_decode($descriptor, true);
    if (json_last_error() !== JSON_ERROR_NONE || count($decoded) !== 128) {
        echo json_encode(['success'=>false,'message'=>'Invalid face data. Please try again.']); exit;
    }
    $upd = $pdo->prepare("UPDATE students SET face_descriptor = ? WHERE matric = ?");
    $upd->execute([$descriptor, $matric]);
    if ($upd->rowCount()) {
        logAudit('face_enrolled', 'student', $matric, $studentName ?? $matric,
            ($studentName ?? $matric) . ' self-enrolled their face via the login flow.');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Save failed.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Enrollment Required</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .wrap{background:#fff;border-radius:20px;padding:36px 32px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
        .logo{text-align:center;margin-bottom:24px}
        .logo-icon{width:68px;height:68px;background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;color:#fff;margin-bottom:12px}
        .logo h2{font-size:1.25rem;color:#0f172a;font-weight:800}
        .logo p{font-size:.85rem;color:#64748b;margin-top:4px}

        .alert{padding:14px 18px;border-radius:12px;font-size:.88rem;font-weight:500;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;line-height:1.5}
        .alert-warn{background:#fefce8;color:#854d0e;border:1px solid #fde68a}
        .alert i{margin-top:1px;flex-shrink:0;font-size:1.05rem}

        .student-badge{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:12px}
        .student-avatar{width:42px;height:42px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#1e40af;flex-shrink:0}
        .student-info h4{font-size:.92rem;font-weight:700;color:#0f172a}
        .student-info p{font-size:.78rem;color:#64748b;margin-top:2px}

        .status-box{padding:11px 16px;border-radius:10px;font-size:.88rem;font-weight:500;margin-bottom:14px;display:flex;align-items:center;gap:8px}
        .s-loading{background:#fefce8;color:#854d0e;border:1px solid #fde68a}
        .s-info   {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
        .s-error  {background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .s-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

        .video-wrap{position:relative;width:100%;border-radius:14px;overflow:hidden;background:#0f172a;margin-bottom:14px;aspect-ratio:4/3;display:none}
        #video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)}
        #overlay{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none}

        .prog-wrap{background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;display:none;margin-bottom:6px}
        .prog-fill{height:100%;background:linear-gradient(to right,#1e3a8a,#10b981);width:0%;transition:width .3s;border-radius:6px}
        .prog-label{font-size:.78rem;color:#64748b;text-align:center;margin-bottom:12px;display:none}

        .btn{width:100%;padding:13px;border:none;border-radius:12px;font-size:.95rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;margin-bottom:10px}
        .btn:disabled{opacity:.45;cursor:not-allowed}
        .btn:last-child{margin-bottom:0}
        .btn-primary{background:linear-gradient(to right,#0f172a,#1e3a8a);color:#fff}
        .btn-primary:hover:not(:disabled){opacity:.9;transform:translateY(-1px)}
        .btn-success{background:#10b981;color:#fff}
        .btn-success:hover:not(:disabled){background:#059669}
        .btn-outline{background:#fff;color:#64748b;border:2px solid #e2e8f0}
        .btn-outline:hover{background:#f8fafc}

        .success-screen{text-align:center;padding:10px 0}
        .success-icon{width:80px;height:80px;background:#dcfce7;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:2.5rem;margin-bottom:20px}
        .success-screen h3{font-size:1.25rem;font-weight:800;color:#0f172a;margin-bottom:8px}
        .success-screen p{color:#64748b;font-size:.9rem;line-height:1.6}

        @media(max-width:480px){.wrap{padding:24px 18px}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <div class="logo-icon"><i class="fas fa-camera"></i></div>
        <h2>Face Enrollment Required</h2>
        <p>You must enroll your face before taking a test</p>
    </div>

    <!-- Enrollment panel -->
    <div id="enrollPanel">
        <div class="alert alert-warn">
            <i class="fas fa-exclamation-triangle"></i>
            <div>Your face has not been enrolled yet. You need to register your face <strong>once</strong> — after that, you can take all your tests normally.</div>
        </div>

        <div class="student-badge">
            <div class="student-avatar"><i class="fas fa-user-graduate"></i></div>
            <div class="student-info">
                <h4><?= htmlspecialchars($studentName) ?></h4>
                <p><?= htmlspecialchars($matric) ?></p>
            </div>
        </div>

        <div id="camStatus" class="status-box s-loading">
            <i class="fas fa-spinner fa-spin"></i>
            <span id="camStatusText">Loading face models…</span>
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
                <a href="<?= $viaVerifiedSession ? 'dashboard.php' : 'student-login.php' ?>" class="btn btn-outline" style="text-decoration:none;margin-top:6px">
            <i class="fas fa-arrow-left"></i> Back to <?= $viaVerifiedSession ? 'Dashboard' : 'Login' ?>
        </a>
    </div>

    <!-- Success panel -->
    <div id="successPanel" style="display:none">
        <div class="success-screen">
            <div class="success-icon">🎉</div>
            <h3>Enrollment Complete!</h3>
            <p>Your face has been registered. Taking you to face verification now…</p>
        </div>
    </div>
</div>

<script src="assets/js/face-api.min.js"></script>
<script>
var MATRIC     = <?= json_encode($matric) ?>;
var TEST_ID    = <?= json_encode($testId) ?>;
var VIA_VERIFIED_SESSION = <?= json_encode($viaVerifiedSession) ?>;
var MODELS_OK  = false;
var CAM_STREAM = null;
var LOOP_ON    = false;

var MODEL_URLS = [
    'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights',
    'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights',
    'assets/js/models'
];

function camSS(msg, type) {
    document.getElementById('camStatusText').innerHTML = msg;
    document.getElementById('camStatus').className = 'status-box s-' + type;
}

async function tryLoadModels(url) {
    await faceapi.nets.tinyFaceDetector.loadFromUri(url);
    await faceapi.nets.faceLandmark68Net.loadFromUri(url);
    await faceapi.nets.faceRecognitionNet.loadFromUri(url);
}

async function loadModels() {
    if (typeof faceapi === 'undefined') { camSS('face-api.js failed. Refresh the page.', 'error'); return; }
    for (var i = 0; i < MODEL_URLS.length; i++) {
        try {
            camSS('Loading face models…', 'loading');
            await tryLoadModels(MODEL_URLS[i]);
            MODELS_OK = true;
            document.getElementById('startBtn').disabled = false;
            document.getElementById('startBtnText').textContent = 'Start Camera';
            camSS('Models ready — click Start Camera to enroll your face.', 'success');
            return;
        } catch(e) {
            if (i === MODEL_URLS.length - 1) camSS('Failed to load models. Check connection and refresh.', 'error');
        }
    }
}

async function startCamera() {
    if (!MODELS_OK) { camSS('Models not ready yet.', 'loading'); return; }
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
        camSS('📷 Camera on! Face the camera and click Capture & Enroll.', 'info');
        startLiveLoop();
    } catch(e) {
        camSS('Camera error: ' + e.message, 'error');
    }
}

function startLiveLoop() {
    LOOP_ON = true;
    var video = document.getElementById('video'), canvas = document.getElementById('overlay');
    (async function loop() {
        while (LOOP_ON && CAM_STREAM) {
            await new Promise(function(r) { requestAnimationFrame(r); });
            if (video.readyState < 2) continue;
            try {
                var det = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize:320, scoreThreshold:0.5 }))
                    .withFaceLandmarks();
                var ctx = canvas.getContext('2d');
                ctx.clearRect(0,0,canvas.width,canvas.height);
                if (det) {
                    var dims = faceapi.matchDimensions(canvas, video, true);
                    var r = faceapi.resizeResults(det, dims);
                    ctx.save(); ctx.translate(canvas.width,0); ctx.scale(-1,1);
                    var b = r.detection.box;
                    ctx.strokeStyle='#10b981'; ctx.lineWidth=3;
                    ctx.strokeRect(b.x,b.y,b.width,b.height);
                    ctx.fillStyle='#10b981'; ctx.fillRect(b.x,b.y-22,72,20);
                    ctx.fillStyle='#fff'; ctx.font='bold 12px sans-serif';
                    ctx.fillText(Math.round(det.detection.score*100)+'% conf',b.x+4,b.y-7);
                    ctx.restore();
                }
            } catch(e) {}
        }
    })();
}

function stopCamera() {
    LOOP_ON = false;
    if (CAM_STREAM) { CAM_STREAM.getTracks().forEach(function(t){t.stop();}); CAM_STREAM = null; }
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

async function doCapture() {
    if (!CAM_STREAM) return;
    document.getElementById('captureBtn').disabled = true;
    LOOP_ON = false;
    camSS('🔍 Detecting your face…', 'loading');

    var video = document.getElementById('video'), descriptors = [];
    for (var i = 0; i < 3; i++) {
        try {
            var det = await faceapi
                .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize:416, scoreThreshold:0.5 }))
                .withFaceLandmarks().withFaceDescriptor();
            if (det) descriptors.push(det.descriptor);
        } catch(e) {}
        await new Promise(function(r){setTimeout(r,300);});
    }

    if (descriptors.length === 0) {
        camSS('❌ No face detected. Ensure good lighting and face the camera directly.', 'error');
        document.getElementById('captureBtn').disabled = false;
        LOOP_ON = true; startLiveLoop(); return;
    }

    var avg = new Float32Array(128);
    for (var d = 0; d < descriptors.length; d++)
        for (var j = 0; j < 128; j++) avg[j] += descriptors[d][j];
    for (var k = 0; k < 128; k++) avg[k] /= descriptors.length;

    var quality = Math.round((descriptors.length / 3) * 100);
    document.getElementById('progWrap').style.display  = 'block';
    document.getElementById('progLabel').style.display = 'block';
    document.getElementById('progFill').style.width    = quality + '%';
    document.getElementById('progLabel').textContent   = 'Quality: ' + quality + '% (' + descriptors.length + '/3 samples)';

    camSS('💾 Saving enrollment…', 'loading');
    var fd = new FormData();
    fd.append('action', 'self_enroll');
    fd.append('descriptor', JSON.stringify(Array.from(avg)));

    try {
        var resp = await fetch('face-enroll-required.php', { method:'POST', body:fd });
        var data = await resp.json();
        if (data.success) {
            stopCamera();
            document.getElementById('enrollPanel').style.display = 'none';
            document.getElementById('successPanel').style.display = 'block';
            setTimeout(function() {
                if (VIA_VERIFIED_SESSION && TEST_ID) {
                    // Edge-case path: session was already verified (e.g. a
                    // face-verify-not-required custom link), go straight to the test.
                    window.location.href = 'take-test.php?test_id=' + TEST_ID + '&matric=' + encodeURIComponent(MATRIC);
                } else {
                    // Normal path: they just enrolled, but still need to go through
                    // an actual live face verification before reaching their test —
                    // this reuses all the existing session-lock and already-taken
                    // checks in face-verify.php instead of duplicating them here.
                    window.location.href = 'face-verify.php?matric=' + encodeURIComponent(MATRIC);
                }
            }, 2500);
        } else {
            camSS('❌ ' + (data.message || 'Failed. Please try again.'), 'error');
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
    if (CAM_STREAM) CAM_STREAM.getTracks().forEach(function(t){t.stop();});
});

loadModels();
</script>
</body>
</html>
