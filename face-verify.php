<?php
session_start();
$matric = strtoupper(trim($_GET['matric'] ?? ''));
if (!$matric || !preg_match('/^\d{2}CS\d{4}$/', $matric)) {
    header('Location: index.php');
    exit;
}

// This session must have legitimately looked up this exact matric first
// (via api/check-student.php or the custom-link matric check) — otherwise
// someone could jump straight to this page/endpoint for any matric number.
if (($_SESSION['pending_verify_matric'] ?? '') !== $matric) {
    header('Location: student-login.php');
    exit;
}

// Server-side lockout: max 5 failed attempts per session
if (!isset($_SESSION['face_attempts'])) $_SESSION['face_attempts'] = [];
$attempts = $_SESSION['face_attempts'][$matric] ?? 0;
$locked   = $attempts >= 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Verification - CS Dept CA Portal</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:linear-gradient(to bottom right,#0f172a,#1e3a8a,#0f172a); min-height:100vh; display:flex; justify-content:center; align-items:center; padding:20px; }
        .card { background:white; border-radius:24px; padding:36px 32px; max-width:500px; width:100%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); }
        h1 { font-size:1.5rem; color:#1a202c; text-align:center; margin-bottom:6px; }
        .subtitle { text-align:center; color:#64748b; font-size:.9rem; margin-bottom:20px; }
        .matric-display { background:#f8fafc; border:1px solid #e2e8f0; padding:10px 16px; border-radius:10px; margin-bottom:20px; text-align:center; font-weight:600; color:#0f172a; }
        .steps { display:flex; justify-content:space-between; margin-bottom:16px; gap:6px; }
        .step { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
        .step-dot { width:30px; height:30px; border-radius:50%; background:#e2e8f0; color:#a0aec0; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; transition:all .4s; }
        .step-label { font-size:.62rem; color:#94a3b8; text-align:center; transition:color .3s; }
        .step.active .step-dot { background:#1e3a8a; color:white; transform:scale(1.1); }
        .step.active .step-label { color:#1e3a8a; font-weight:600; }
        .step.done .step-dot { background:#10b981; color:white; }
        .step.done .step-label { color:#10b981; }
        .step.fail .step-dot { background:#ef4444; color:white; }
        .step.fail .step-label { color:#ef4444; }
        .video-wrap { position:relative; width:100%; border-radius:14px; overflow:hidden; background:#000; margin-bottom:14px; display:none; }
        #video { width:100%; display:block; border-radius:14px; transform:scaleX(-1); }
        #overlay { position:absolute; top:0; left:0; width:100%; height:100%; border-radius:14px; pointer-events:none; }
        .challenge-hint { text-align:center; margin:12px 0; font-size:1.45rem; min-height:52px; font-weight:500; }
        .hold-bar-wrapper { background:#e2e8f0; border-radius:4px; overflow:hidden; display:none; height:8px; margin:8px 0 12px; }
        .hold-bar-fill { height:100%; background:linear-gradient(to right,#1e3a8a,#10b981); width:0%; border-radius:4px; transition:width .08s linear; }
        .match-bar-wrapper { background:#e2e8f0; border-radius:4px; overflow:hidden; display:none; height:8px; margin:8px 0 6px; }
        .match-bar-fill { height:100%; background:linear-gradient(to right,#ef4444,#f59e0b,#10b981); width:0%; transition:width .4s ease; }
        .match-label { font-size:.78rem; color:#64748b; text-align:center; margin-bottom:10px; display:none; }
        .attempts-bar { font-size:.82rem; color:#92400e; text-align:center; margin-bottom:8px; display:none; }
        .status { margin:14px 0; padding:14px 18px; border-radius:12px; text-align:center; font-weight:500; font-size:.92rem; min-height:52px; display:flex; align-items:center; justify-content:center; }
        .status-info    { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
        .status-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
        .status-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .status-loading { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
        .btn-group { display:flex; flex-direction:column; gap:12px; margin-top:16px; }
        .btn { padding:15px 22px; font-size:1.02rem; font-weight:600; border:none; border-radius:12px; cursor:pointer; transition:all .2s; width:100%; }
        .btn-primary { background:linear-gradient(to right,#0f172a,#1e3a8a); color:white; }
        .btn-primary:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 6px 16px rgba(30,58,138,.45); }
        .btn-primary:disabled { opacity:.6; cursor:not-allowed; }
        .btn-secondary { background:white; color:#4a5568; border:2px solid #e2e8f0; text-decoration:none; display:block; text-align:center; }
        .btn-secondary:hover { background:#f8fafc; }
        .lockout-box { background:#fef2f2; border:2px solid #fecaca; border-radius:16px; padding:32px; text-align:center; }
        .lockout-box h2 { color:#991b1b; margin-bottom:10px; font-size:1.3rem; }
        .lockout-box p { color:#7f1d1d; font-size:.9rem; line-height:1.6; }
    </style>
</head>
<body>
<div class="card">
    <h1>CS Dept CA Portal</h1>
    <p class="subtitle">Secure Face Verification</p>
    <div class="matric-display">🎓 Matric: <?= htmlspecialchars($matric, ENT_QUOTES, 'UTF-8') ?></div>

    <?php if ($locked): ?>
    <div class="lockout-box">
        <h2>🔒 Account Locked</h2>
        <p>Too many failed verification attempts for this matric.<br><br>
        Please contact your administrator to reset your access.</p>
    </div>

    <?php else: ?>
    <div class="steps">
        <div class="step active" id="step1"><div class="step-dot">1</div><div class="step-label">Face<br>Found</div></div>
        <div class="step" id="step2"><div class="step-dot">2</div><div class="step-label">Liveness</div></div>
        <div class="step" id="step3"><div class="step-dot">3</div><div class="step-label">Hold<br>Still</div></div>
        <div class="step" id="step4"><div class="step-dot">4</div><div class="step-label">Matching</div></div>
        <div class="step" id="step5"><div class="step-dot">✓</div><div class="step-label">Granted</div></div>
    </div>

    <div class="video-wrap" id="videoWrap">
        <video id="video" autoplay playsinline muted></video>
        <canvas id="overlay"></canvas>
    </div>

    <div class="challenge-hint" id="challengeHint">Position your face in the frame</div>

    <div class="hold-bar-wrapper" id="holdBarWrap"><div class="hold-bar-fill" id="holdFill"></div></div>
    <div class="match-bar-wrapper" id="matchBarWrap"><div class="match-bar-fill" id="matchFill"></div></div>
    <div class="match-label" id="matchLabel"></div>
    <div class="attempts-bar" id="attemptsBar"></div>

    <div id="status" class="status status-loading">⏳ Loading models...</div>

    <div class="btn-group">
        <button id="startBtn" class="btn btn-primary" disabled>Start Verification</button>
        <a href="index.php" class="btn btn-secondary">← Cancel</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$locked): ?>
<script src="assets/js/face-api.min.js"></script>
<script>
(function(){
    'use strict';

    var MATRIC       = <?= json_encode($matric) ?>;
    var MAX_ATTEMPTS = 5;
    var failCount    = <?= (int)$attempts ?>;

    var MODEL_URLS = [
        window.location.origin + '/ca-portal/assets/js/models',
        'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights',
        'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights'
    ];

    // FIX 1: Threshold reduced from 0.62 to 0.40
    // 0.40 = strict, only genuine match passes
    // 0.62 was letting completely different faces through
    var MATCH_THRESHOLD = 0.40;

    var video         = document.getElementById('video');
    var canvas        = document.getElementById('overlay');
    var videoWrap     = document.getElementById('videoWrap');
    var statusDiv     = document.getElementById('status');
    var startBtn      = document.getElementById('startBtn');
    var challengeHint = document.getElementById('challengeHint');
    var holdWrap      = document.getElementById('holdBarWrap');
    var holdFill      = document.getElementById('holdFill');
    var matchWrap     = document.getElementById('matchBarWrap');
    var matchFill     = document.getElementById('matchFill');
    var matchLabel    = document.getElementById('matchLabel');
    var attemptsBar   = document.getElementById('attemptsBar');

    var stream           = null;
    var detecting        = false;
    var storedDescriptor = null;
    var currentChallenge = null;
    var blinkCount       = 0;
    var eyesWereOpen     = false; // FIX 2: proper open→close blink cycle
    var holdStart        = null;
    var verifyState      = 'idle'; // FIX 3: local var not window.state
    var collectedSamples = []; // FIX 4: multi-sample averaging

    var EAR_CLOSE       = 0.24;
    var EAR_OPEN        = 0.33;
    var MOUTH_THRESHOLD = 0.48;
    var HOLD_TIME       = 1500;
    var MIN_SAMPLES     = 6; // minimum captured frames required for a statistically meaningful average

    function ss(msg, type) {
        statusDiv.innerHTML = msg;
        statusDiv.className = 'status status-' + (type || 'info');
    }
    function step(n, cls) {
        var el = document.getElementById('step' + n);
        if (el) el.className = 'step ' + cls;
    }
    function getEAR(eye) {
        var d = function(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); };
        return (d(eye[1], eye[5]) + d(eye[2], eye[4])) / (2 * d(eye[0], eye[3]));
    }
    function getMouthOpen(landmarks) {
        var mouth = landmarks.getMouth();
        var d = function(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); };
        return (d(mouth[3], mouth[9]) + d(mouth[5], mouth[11])) / (2 * d(mouth[0], mouth[6]));
    }
    function drawBox(det, color) {
        var dims = faceapi.matchDimensions(canvas, video, true);
        var r    = faceapi.resizeResults(det, dims);
        var ctx  = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.translate(canvas.width, 0); ctx.scale(-1, 1);
        var b = r.detection.box;
        ctx.strokeStyle = color || '#1e3a8a'; ctx.lineWidth = 3;
        ctx.strokeRect(b.x, b.y, b.width, b.height);
        ctx.restore();
    }
    function euclidean(a, b) {
        var s = 0;
        for (var i = 0; i < a.length; i++) s += (a[i] - b[i]) * (a[i] - b[i]);
        return Math.sqrt(s);
    }
    function averageDescriptors(list) {
        var avg = new Float32Array(128);
        for (var d = 0; d < list.length; d++)
            for (var i = 0; i < 128; i++) avg[i] += list[d][i];
        for (var i = 0; i < 128; i++) avg[i] /= list.length;
        return avg;
    }
    function updateAttemptsBar() {
        var remaining = MAX_ATTEMPTS - failCount;
        if (failCount > 0) {
            attemptsBar.style.display = 'block';
            attemptsBar.textContent = '⚠️ ' + remaining + ' attempt' + (remaining === 1 ? '' : 's') + ' remaining before lockout';
        }
    }
    async function recordFailure() {
        failCount++;
        try {
            await fetch('api/set-verified.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=fail&matric=' + encodeURIComponent(MATRIC)
            });
        } catch(e) {}
        if (failCount >= MAX_ATTEMPTS) {
            location.reload();
        }
    }

    // ── Load models ────────────────────────────────────────────────────────
    async function loadModels() {
        if (typeof faceapi === 'undefined') {
            ss('❌ face-api.js not loaded. Check assets/js/face-api.min.js', 'error');
            return;
        }
        for (var i = 0; i < MODEL_URLS.length; i++) {
            var src  = MODEL_URLS[i];
            var name = src.includes('jsdelivr') ? 'CDN' : src.includes('github') ? 'GitHub' : 'Local';
            ss('⏳ Loading models (' + name + ')...', 'loading');
            try {
                await faceapi.nets.tinyFaceDetector.loadFromUri(src);
                await faceapi.nets.faceLandmark68Net.loadFromUri(src);
                await faceapi.nets.faceRecognitionNet.loadFromUri(src);
                ss('✅ Models ready. Click Start Verification', 'success');
                startBtn.disabled = false;
                updateAttemptsBar();
                return;
            } catch(e) {
                console.warn('Failed:', src, e.message);
            }
        }
        ss('❌ Failed to load models from all sources. Check console (F12).', 'error');
    }

    // ── Fetch stored descriptor ────────────────────────────────────────────
    async function fetchDescriptor() {
        var res  = await fetch('api/get-face-descriptor.php?matric=' + encodeURIComponent(MATRIC));
        var data = await res.json();
        if (!data.success) throw new Error(data.message || 'No enrolled face found. Contact admin.');
        return new Float32Array(data.descriptor);
    }

    // ── Camera ─────────────────────────────────────────────────────────────
    async function startCamera() {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }
        });
        video.srcObject = stream;
        await new Promise(function(r) { video.onloadedmetadata = r; });
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        videoWrap.style.display = 'block';
    }

    // ── Main detection loop ────────────────────────────────────────────────
    async function runLoop() {
        detecting        = true;
        verifyState      = 'face_found';
        blinkCount       = 0;
        eyesWereOpen     = false;
        collectedSamples = [];

        currentChallenge = Math.random() > 0.5 ? 'blink' : 'smile';
        challengeHint.textContent = currentChallenge === 'blink'
            ? '👁️ Blink naturally 1 or 2 times'
            : '😊 Smile or open your mouth';

        step(1, 'active');
        ss('📷 Position your face in the center', 'info');

        while (detecting) {
            await new Promise(function(r) { requestAnimationFrame(r); });
            if (video.readyState < 2) continue;

            var det;
            try {
                det = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                    .withFaceLandmarks()
                    .withFaceDescriptor();
            } catch(e) { continue; }

            if (!det) {
                canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                ss('⚠️ No face detected. Look straight at camera.', 'loading');
                continue;
            }

            drawBox(det, '#1e3a8a');
            var lm       = det.landmarks;
            var earAvg   = (getEAR(lm.getLeftEye()) + getEAR(lm.getRightEye())) / 2;
            var mouthVal = getMouthOpen(lm);

            // FIX 3: use verifyState not window.state
            if (verifyState === 'face_found') {
                verifyState = 'liveness';
                step(1, 'done'); step(2, 'active');
            }

            if (verifyState === 'liveness') {
                if (currentChallenge === 'blink') {
                    // FIX 2: require open→close cycle, not just any closed frame
                    if (!eyesWereOpen && earAvg >= EAR_OPEN) {
                        eyesWereOpen = true;
                    }
                    if (eyesWereOpen && earAvg < EAR_CLOSE) {
                        blinkCount++;
                        eyesWereOpen = false;
                        if (blinkCount >= 1) {
                            verifyState = 'holding';
                            challengeHint.style.opacity = '0.4';
                            step(2, 'done'); step(3, 'active');
                            holdStart = Date.now();
                            holdWrap.style.display = 'block';
                            ss('✅ Blink confirmed — Hold still for scan...', 'success');
                        }
                    }
                } else {
                    if (mouthVal > MOUTH_THRESHOLD) {
                        verifyState = 'holding';
                        challengeHint.style.opacity = '0.4';
                        step(2, 'done'); step(3, 'active');
                        holdStart = Date.now();
                        holdWrap.style.display = 'block';
                        ss('✅ Smile confirmed — Hold still for scan...', 'success');
                    }
                }
            }

            if (verifyState === 'holding') {
                drawBox(det, '#10b981');
                var progress = Math.min(100, ((Date.now() - holdStart) / HOLD_TIME) * 100);
                holdFill.style.width = progress + '%';

                // FIX 4: collect samples during hold for averaging
                collectedSamples.push(det.descriptor);

                if (progress >= 100) {
                    detecting = false;
                    holdWrap.style.display = 'none';
                    step(3, 'done'); step(4, 'active');
                    runMatch();
                }
            }
        }
    }

    // ── Face match: average distance across all held samples must pass, and no ──
    // ── single sample may be a wild outlier (consistency guard) ─────────────────
    async function runMatch() {
        if (!storedDescriptor || collectedSamples.length === 0) {
            ss('❌ Face data not loaded properly.', 'error');
            resetUI(); return;
        }
        if (collectedSamples.length < MIN_SAMPLES) {
            ss('⚠️ Hold still a little longer — not enough clear frames captured yet.', 'error');
            resetUI(); return;
        }

        ss('🔍 Comparing your face with enrolled record...', 'info');

        // Average all samples collected during hold
        var avgDesc = averageDescriptors(collectedSamples);
        var avgDist = euclidean(avgDesc, storedDescriptor);

        // Consistency check: every individual sample must also be reasonably close.
        // (Previously this blended in the SINGLE CLOSEST sample to lower the final
        // score — that's a favorable bias, not a stricter check: across a few dozen
        // frames per attempt, a lucky frame from a DIFFERENT face could occasionally
        // land close enough to drag the blended score under the threshold. Real
        // strictness means the whole capture has to consistently match, not just
        // its best moment.)
        var worstDist = 0;
        for (var i = 0; i < collectedSamples.length; i++) {
            var d = euclidean(collectedSamples[i], storedDescriptor);
            if (d > worstDist) worstDist = d;
        }

        // The average must pass the threshold, AND no single frame may be wildly
        // inconsistent with the rest of the capture (guards against a mid-capture
        // face swap or a spoofed/borrowed frame slipping through).
        var finalDist  = avgDist;
        var consistent = worstDist <= (MATCH_THRESHOLD * 1.5);
        var confidence = Math.max(0, Math.round((1 - finalDist / 0.65) * 100));

        matchWrap.style.display  = 'block';
        matchLabel.style.display = 'block';
        matchFill.style.width    = confidence + '%';
        matchLabel.textContent   = 'Match: ' + confidence + '% | Distance: ' + finalDist.toFixed(3) + ' | Samples: ' + collectedSamples.length;

        await new Promise(function(r) { setTimeout(r, 900); });

        if (finalDist <= MATCH_THRESHOLD && consistent) {
            step(4, 'done');
            onVerified();
        } else {
            step(4, 'fail');
            ss('❌ Face does not match enrolled record.<br>Distance: ' + finalDist.toFixed(3) + ' (max: ' + MATCH_THRESHOLD + ')', 'error');
            await recordFailure();
            updateAttemptsBar();
            resetUI();
        }
    }

    // ── Reset UI ───────────────────────────────────────────────────────────
    function resetUI() {
        if (stream) stream.getTracks().forEach(function(t) { t.stop(); });
        videoWrap.style.display     = 'none';
        holdWrap.style.display      = 'none';
        collectedSamples            = [];
        holdFill.style.width        = '0%';
        challengeHint.style.opacity = '1';
        challengeHint.textContent   = 'Position your face in the frame';
        startBtn.disabled           = failCount >= MAX_ATTEMPTS;
        startBtn.textContent        = '🔄 Try Again';
        step(1,'active'); step(2,''); step(3,''); step(4,''); step(5,'');
    }

    // ── Verified ───────────────────────────────────────────────────────────
    async function onVerified() {
        if (stream) stream.getTracks().forEach(function(t) { t.stop(); });
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        step(5, 'done');
        ss('🎉 Identity Verified Successfully!<br>Finalizing session...', 'success');
        try {
            const resp = await fetch('api/set-verified.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'matric=' + encodeURIComponent(MATRIC)
            });
            const data = await resp.json();

            if (!data.success) {
                // Face was genuinely verified — this is a session/eligibility rejection
                // (already taken, attempts exhausted, no active test, etc.) not a face-match
                // failure, so show the REAL reason instead of silently bouncing to the home page.
                ss('⛔ ' + (data.message || 'Could not start your session. Please contact your lecturer.'), 'error');
                return;
            }

            ss('🎉 Identity Verified Successfully!<br>Redirecting...', 'success');
            setTimeout(function() {
                // If this is a custom test link flow, go back to take-test-link.php
                const urlParams = new URLSearchParams(window.location.search);
                const redirectTo = urlParams.get('redirect');
                const token = urlParams.get('token');
                if (redirectTo === 'custom_test' && token) {
                    window.location.href = 'take-test-link.php?token=' + encodeURIComponent(token);
                } else {
                    window.location.href = 'dashboard.php?matric=' + encodeURIComponent(MATRIC);
                }
            }, 1400);
        } catch(e) {
            ss('⚠ Network error while finalizing your session. Please try again.', 'error');
        }
    }

    // ── Start button ───────────────────────────────────────────────────────
    startBtn.addEventListener('click', async function() {
        startBtn.disabled    = true;
        startBtn.textContent = 'Verifying...';
        matchWrap.style.display  = 'none';
        matchLabel.style.display = 'none';
        matchFill.style.width    = '0%';
        holdFill.style.width     = '0%';
        collectedSamples         = [];

        try {
            ss('🔄 Loading your enrolled face data...', 'info');
            storedDescriptor = await fetchDescriptor();
            await startCamera();
            await runLoop();
        } catch(e) {
            console.error(e);
            ss('❌ ' + (e.message || 'Failed to start verification'), 'error');
            resetUI();
        }
    });

    window.addEventListener('beforeunload', function() {
        detecting = false;
        if (stream) stream.getTracks().forEach(function(t) { t.stop(); });
    });

    loadModels();
})();
</script>
<?php endif; ?>
</body>
</html>