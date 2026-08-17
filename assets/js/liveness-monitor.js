/**
 * CA Portal — Liveness & Anti-Cheat Monitor v3
 * ─────────────────────────────────────────────
 * Monitors during test only. Requires face-api.js already loaded.
 *
 * Features:
 *  1. Face out of camera frame  (>5s  → warning system)
 *  2. Eyes closed too long      (>4s  → warning system)
 *  3. Eyes looking away         (>6s  → warning system)
 *  4. Tab switch / blur         (instant auto-submit)
 *  5. Fullscreen exit           (warn + auto-submit)
 *  6. Multiple faces in frame   (instant auto-submit — zero tolerance)
 *  7. Camera/model failure, at start OR mid-test — logged + shown to the
 *     student + reported to the server via degraded heartbeats, with a
 *     one-shot automatic recovery attempt if the camera drops mid-test
 *  8. Server heartbeat (~15s) — the one thing client JS can never
 *     self-report is its own script being blocked or killed. The server
 *     detects that by the *absence* of these pings (see api/heartbeat.php
 *     and the proctoring_flag computed in api/submit-test.php).
 *  9. Webcam snapshot on every violation
 *
 * Warning system (liveness only): 2 warnings → auto-submit
 * Tab switch: immediate auto-submit, no warnings
 * Fullscreen exit: 2 warnings → auto-submit
 * Multiple faces: immediate auto-submit, no warnings
 */

class LivenessMonitor {
    constructor(options = {}) {
        this.testId           = options.testId;
        this.matric            = options.matric;
        this.onAutoSubmit      = options.onAutoSubmit || (() => {});
        this.modelsPath        = options.modelsPath || 'assets/js/models';

        // ── Thresholds ──────────────────────────────────────────
        this.FACE_OUT_THRESHOLD    = 5000;
        this.EYES_CLOSED_THRESHOLD = 4000;
        this.EYES_AWAY_THRESHOLD   = 6000;
        this.MULTI_FACE_THRESHOLD  = 2000; // must persist 2s to avoid a single bad frame
        this.EAR_CLOSED_THRESHOLD  = 0.21;
        this.GAZE_THRESHOLD        = 0.35;
        this.HEARTBEAT_MS          = 15000;

        // ── Warning counters (separate pools) ───────────────────
        this.livenessWarnings    = 0;  // face/eye violations
        this.fullscreenWarnings  = 0;  // fullscreen exit violations
        this.MAX_WARNINGS        = 2;

        // ── State ───────────────────────────────────────────────
        this.isRunning        = false;
        this.submitted        = false;
        this.tabSwitchLocked  = false; // synchronous lock, closes the visibilitychange/blur race
        this.cameraOk         = false; // true once camera+models are actually running
        this.recovering       = false; // guards against overlapping camera-recovery attempts
        this.videoEl          = null;
        this.canvasEl         = null;
        this.snapCanvas       = null; // separate canvas for snapshots
        this.stream           = null;
        this.animFrame        = null;
        this.modelsLoaded     = false;
        this.heartbeatInterval = null;

        // ── Violation timers ────────────────────────────────────
        this.faceGoneAt       = null;
        this.eyesClosedAt     = null;
        this.eyesAwayAt       = null;
        this.multiFaceAt      = null;

        // ── Cooldown per type ────────────────────────────────────
        this.lastViolation    = {};
        this.COOLDOWN_MS      = 10000;

        // ── UI refs ──────────────────────────────────────────────
        this.bannerEl         = null;
        this.dotEl            = null;
        this.cameraWrapEl     = null;
        this.degradedBannerEl = null;

        this._buildUI();
    }

    // ════════════════════════════════════════════════════════════
    // UI
    // ════════════════════════════════════════════════════════════
    _buildUI() {
        // Warning banner — fixed top
        this.bannerEl = document.createElement('div');
        this.bannerEl.id = 'liveness-banner';
        Object.assign(this.bannerEl.style, {
            display: 'none', position: 'fixed', top: '0', left: '0', right: '0',
            zIndex: '9999', padding: '14px 20px',
            background: 'linear-gradient(135deg,#dc2626,#b91c1c)',
            color: 'white', fontFamily: 'sans-serif', fontSize: '15px',
            fontWeight: '700', textAlign: 'center',
            boxShadow: '0 4px 20px rgba(0,0,0,0.4)', transition: 'all 0.3s'
        });

        // Persistent degraded-monitoring banner — sits below the main banner
        // and stays up for as long as camera monitoring is actually down, so
        // it can't be missed and doesn't get silently cleared by _hideBanner().
        this.degradedBannerEl = document.createElement('div');
        this.degradedBannerEl.id = 'liveness-degraded-banner';
        Object.assign(this.degradedBannerEl.style, {
            display: 'none', position: 'fixed', top: '0', left: '0', right: '0',
            zIndex: '9997', padding: '10px 20px',
            background: 'linear-gradient(135deg,#92400e,#b45309)',
            color: 'white', fontFamily: 'sans-serif', fontSize: '13px',
            fontWeight: '600', textAlign: 'center',
            boxShadow: '0 4px 20px rgba(0,0,0,0.3)'
        });
        this.degradedBannerEl.textContent = '⚠️ Camera monitoring is unavailable — this attempt is flagged for lecturer review.';

        // Camera preview — bottom right
        this.cameraWrapEl = document.createElement('div');
        this.cameraWrapEl.id = 'liveness-camera-wrap';
        Object.assign(this.cameraWrapEl.style, {
            position: 'fixed', bottom: '16px', right: '16px', zIndex: '9998',
            width: '120px', height: '90px', borderRadius: '12px',
            overflow: 'hidden', boxShadow: '0 4px 20px rgba(0,0,0,0.5)',
            border: '2px solid #10b981'
        });

        this.videoEl = document.createElement('video');
        this.videoEl.autoplay = true;
        this.videoEl.muted    = true;
        this.videoEl.playsInline = true;
        Object.assign(this.videoEl.style, { width: '100%', height: '100%', objectFit: 'cover' });

        // Status dot
        const dot = document.createElement('div');
        dot.id = 'liveness-dot';
        Object.assign(dot.style, {
            position: 'absolute', top: '6px', right: '6px',
            width: '10px', height: '10px', borderRadius: '50%',
            background: '#10b981', boxShadow: '0 0 6px #10b981', transition: 'background 0.3s'
        });
        this.dotEl = dot;

        // Hidden canvas for face detection
        this.canvasEl = document.createElement('canvas');
        this.canvasEl.style.display = 'none';

        // Separate canvas for snapshots (same size as video)
        this.snapCanvas = document.createElement('canvas');
        this.snapCanvas.style.display = 'none';

        this.cameraWrapEl.appendChild(this.videoEl);
        this.cameraWrapEl.appendChild(dot);
        document.body.appendChild(this.bannerEl);
        document.body.appendChild(this.degradedBannerEl);
        document.body.appendChild(this.cameraWrapEl);
        document.body.appendChild(this.canvasEl);
        document.body.appendChild(this.snapCanvas);
    }

    // ════════════════════════════════════════════════════════════
    // INIT
    // ════════════════════════════════════════════════════════════
    async init() {
        // Tab-switch and fullscreen-exit detection use only browser events — they
        // don't need the webcam or face-api models, so wire them up unconditionally.
        // Previously these lived inside the try block below, which meant ANY camera
        // or model-loading failure silently disabled *all* proctoring, including
        // these two checks that had nothing to do with the camera.
        this._setupTabDetection();
        this._setupFullscreen();

        // Heartbeat starts immediately regardless of camera outcome — its whole
        // purpose is to prove the monitor script itself is alive. It carries
        // this.cameraOk on every ping so the server also knows whether visual
        // monitoring specifically is up.
        this._startHeartbeat();

        try {
            await this._loadModels();
            await this._startCamera();
            this.cameraOk  = true;
            this.isRunning = true;
            this._detect();
            console.log('[LivenessMonitor v3] Started — full monitoring (camera + tab/fullscreen)');
        } catch (err) {
            console.warn('[LivenessMonitor] Camera/face monitoring unavailable — tab-switch and fullscreen detection are still active:', err.message);
            await this._enterDegradedMode();
        }
    }

    async _loadModels() {
        if (this.modelsLoaded) return;
        const candidatePaths = [
            this.modelsPath,
            'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights',
            'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights'
        ];
        let lastErr = null;
        for (const path of candidatePaths) {
            try {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(path),
                    faceapi.nets.faceLandmark68Net.loadFromUri(path),
                ]);
                this.modelsLoaded = true;
                return;
            } catch (e) {
                lastErr = e;
                console.warn('[LivenessMonitor] Model path failed, trying next:', path);
            }
        }
        throw lastErr || new Error('All model sources failed');
    }

    async _startCamera() {
        this.stream = await navigator.mediaDevices.getUserMedia({
            video: { width: 320, height: 240, facingMode: 'user' }, audio: false
        });
        this.videoEl.srcObject = this.stream;
        await new Promise(res => { this.videoEl.onloadedmetadata = res; });
        const w = this.videoEl.videoWidth  || 320;
        const h = this.videoEl.videoHeight || 240;
        this.canvasEl.width  = w; this.canvasEl.height  = h;
        this.snapCanvas.width = w; this.snapCanvas.height = h;

        // Detect the camera being yanked away mid-test (another app grabs it
        // exclusively, permission revoked, device unplugged, etc.) — not just
        // failure at startup. Without this, a mid-test camera loss silently
        // stopped all face-based checks with nothing recorded anywhere.
        const track = this.stream.getVideoTracks()[0];
        if (track) {
            track.addEventListener('ended', () => this._handleCameraLost());
        }
    }

    // ════════════════════════════════════════════════════════════
    // DEGRADED MODE — camera/models unavailable, at start or mid-test
    // ════════════════════════════════════════════════════════════
    async _enterDegradedMode() {
        this.cameraOk = false;
        this.degradedBannerEl.style.display = 'block';
        // Best-effort — snapshot capture will simply fail silently if there's
        // truly no camera at all, which is fine; the log entry itself matters.
        const snapshot = this._captureSnapshot();
        await this._logViolation('no_camera', 0, snapshot);
    }

    _exitDegradedMode() {
        this.cameraOk = true;
        this.degradedBannerEl.style.display = 'none';
    }

    async _handleCameraLost() {
        if (this.submitted || this.recovering) return;
        this.recovering = true;
        this.isRunning = false; // stop the detect loop touching a dead video element
        if (this.animFrame) cancelAnimationFrame(this.animFrame);
        await this._enterDegradedMode();

        // One-shot automatic recovery attempt (covers transient issues like the
        // OS briefly handing the camera to another app). If it fails, the
        // student stays in degraded mode for the rest of the test — heartbeats
        // keep reporting monitoring_active:0 so it's visible after the fact.
        try {
            await this._startCamera();
            this._exitDegradedMode();
            this.isRunning = true;
            this._detect();
            console.log('[LivenessMonitor v3] Camera recovered mid-test');
        } catch (e) {
            console.warn('[LivenessMonitor] Camera recovery failed — staying in degraded mode:', e.message);
        } finally {
            this.recovering = false;
        }
    }

    // ════════════════════════════════════════════════════════════
    // SERVER HEARTBEAT — proves the monitor script is still alive
    // ════════════════════════════════════════════════════════════
    _startHeartbeat() {
        const ping = () => {
            fetch('api/heartbeat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    test_id: this.testId,
                    matric: this.matric,
                    monitoring_active: !!this.cameraOk
                })
            }).catch(() => { /* fail silently — next ping will retry */ });
        };
        ping();
        this.heartbeatInterval = setInterval(ping, this.HEARTBEAT_MS);
    }

    // ════════════════════════════════════════════════════════════
    // SNAPSHOT CAPTURE
    // ════════════════════════════════════════════════════════════
    _captureSnapshot() {
        try {
            const ctx = this.snapCanvas.getContext('2d');
            ctx.drawImage(this.videoEl, 0, 0, this.snapCanvas.width, this.snapCanvas.height);
            // Return base64 JPEG at 70% quality (keeps size small)
            return this.snapCanvas.toDataURL('image/jpeg', 0.7);
        } catch (e) {
            return null;
        }
    }

    // ════════════════════════════════════════════════════════════
    // TAB SWITCH DETECTION — immediate auto-submit
    // ════════════════════════════════════════════════════════════
    _setupTabDetection() {
        // visibilitychange and window-blur both fire for the same real tab
        // switch. this.submitted only flips true *after* the log-proctoring
        // fetch round-trips, so on a slow connection both handlers could
        // slip past the `if (this.submitted) return` check and log two
        // tab_switch rows for one event. tabSwitchLocked is set
        // synchronously the instant either handler decides to act — before
        // any await — so the second handler is always blocked, regardless
        // of network speed.
        const handleHidden = async () => {
            if (this.submitted || this.tabSwitchLocked) return;
            if (document.hidden || document.visibilityState === 'hidden') {
                this.tabSwitchLocked = true;
                const snapshot = this._captureSnapshot();
                await this._logViolation('tab_switch', 0, snapshot);
                this._showBanner('🚨 Tab switch detected — test is being submitted automatically.', 'red');
                this._triggerAutoSubmit();
            }
        };

        const handleBlur = async () => {
            if (this.submitted || this.tabSwitchLocked) return;
            // Small delay to avoid false triggers from fullscreen requests
            await new Promise(r => setTimeout(r, 400));
            if (this.submitted || this.tabSwitchLocked) return;
            if (!document.hasFocus()) {
                this.tabSwitchLocked = true;
                const snapshot = this._captureSnapshot();
                await this._logViolation('tab_switch', 0, snapshot);
                this._showBanner('🚨 Browser left focus — test is being submitted automatically.', 'red');
                this._triggerAutoSubmit();
            }
        };

        document.addEventListener('visibilitychange', handleHidden);
        window.addEventListener('blur', handleBlur);
    }

    // ════════════════════════════════════════════════════════════
    // FULLSCREEN ENFORCEMENT
    // ════════════════════════════════════════════════════════════
    _setupFullscreen() {
        // Request fullscreen immediately
        this._requestFullscreen();

        // Listen for fullscreen exit
        const fsChangeHandler = async () => {
            if (this.submitted) return;
            const isFs = !!(document.fullscreenElement ||
                            document.webkitFullscreenElement ||
                            document.mozFullScreenElement);
            if (!isFs) {
                // Student exited fullscreen
                this.fullscreenWarnings++;
                const snapshot = this._captureSnapshot();
                await this._logViolation('fullscreen_exit', this.fullscreenWarnings, snapshot);

                if (this.fullscreenWarnings > this.MAX_WARNINGS) {
                    this._showBanner('🚨 Fullscreen exited too many times — test is being submitted.', 'red');
                    this._triggerAutoSubmit();
                    return;
                }

                const remaining = this.MAX_WARNINGS - this.fullscreenWarnings;
                const msg = remaining === 0
                    ? `⛔ Fullscreen Exit! Warning ${this.fullscreenWarnings}/${this.MAX_WARNINGS} — Next exit will auto-submit!`
                    : `⛔ You exited fullscreen! Warning ${this.fullscreenWarnings}/${this.MAX_WARNINGS}. Return to fullscreen.`;
                this._showBanner(msg, 'orange');
                this._setDot('orange');

                // Show a re-enter fullscreen button
                this._showFullscreenPrompt();

                setTimeout(() => this._hideBanner(), 8000);
            }
        };

        document.addEventListener('fullscreenchange', fsChangeHandler);
        document.addEventListener('webkitfullscreenchange', fsChangeHandler);
        document.addEventListener('mozfullscreenchange', fsChangeHandler);
    }

    _requestFullscreen() {
        const el = document.documentElement;
        const req = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen;
        if (req) {
            req.call(el).catch(() => {
                // Browser may block — show manual prompt
                this._showFullscreenPrompt();
            });
        }
    }

    _showFullscreenPrompt() {
        let prompt = document.getElementById('fs-prompt');
        if (prompt) { prompt.style.display = 'flex'; return; }

        prompt = document.createElement('div');
        prompt.id = 'fs-prompt';
        Object.assign(prompt.style, {
            position: 'fixed', inset: '0', background: 'rgba(15,23,42,0.92)',
            zIndex: '10000', display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontFamily: 'sans-serif'
        });
        prompt.innerHTML = `
            <div style="background:white;border-radius:20px;padding:36px 32px;max-width:420px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.5)">
                <div style="font-size:3rem;margin-bottom:12px">⛶</div>
                <h2 style="color:#0f172a;margin-bottom:10px">Fullscreen Required</h2>
                <p style="color:#64748b;margin-bottom:24px;font-size:.9rem">
                    This test must be taken in fullscreen mode.<br>
                    Click the button below to continue.
                </p>
                <button onclick="
                    var el=document.documentElement;
                    var req=el.requestFullscreen||el.webkitRequestFullscreen||el.mozRequestFullScreen;
                    if(req){req.call(el).then(()=>{document.getElementById('fs-prompt').style.display='none';});}
                " style="background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white;border:none;padding:14px 32px;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;width:100%">
                    Enter Fullscreen
                </button>
            </div>`;
        document.body.appendChild(prompt);
    }

    // ════════════════════════════════════════════════════════════
    // FACE / EYE / MULTI-FACE DETECTION LOOP
    // ════════════════════════════════════════════════════════════
    async _detect() {
        if (!this.isRunning) return;

        const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.4 });
        const results = await faceapi.detectAllFaces(this.videoEl, options).withFaceLandmarks();
        const now = Date.now();

        // ── Multiple faces in frame — zero tolerance ────────────────────────
        // A second person visible (feeding answers) or a phone/photo held up
        // to spoof presence. This is a strong enough signal that it doesn't
        // go through the ordinary 2-strikes warning pool.
        if (results.length > 1) {
            this.faceGoneAt = this.eyesClosedAt = this.eyesAwayAt = null;
            this._setDot('red');
            if (!this.multiFaceAt) this.multiFaceAt = now;
            if (now - this.multiFaceAt >= this.MULTI_FACE_THRESHOLD) {
                await this._handleMultiFaceViolation();
                return; // auto-submit already triggered
            }
            setTimeout(() => { this.animFrame = requestAnimationFrame(() => this._detect()); }, 600);
            return;
        }
        this.multiFaceAt = null;

        const result = results[0];

        if (!result) {
            if (!this.faceGoneAt) this.faceGoneAt = now;
            this.eyesClosedAt = null;
            this.eyesAwayAt   = null;
            this._setDot('red');

            if (now - this.faceGoneAt >= this.FACE_OUT_THRESHOLD) {
                await this._handleLivenessViolation('face_out', '⚠️ Your face left the camera frame!');
                this.faceGoneAt = now;
            }
        } else {
            this.faceGoneAt = null;
            this._setDot('green');

            const lm      = result.landmarks;
            const leftEAR = this._earFromPoints(lm.getLeftEye());
            const rightEAR= this._earFromPoints(lm.getRightEye());
            const avgEAR  = (leftEAR + rightEAR) / 2;

            if (avgEAR < this.EAR_CLOSED_THRESHOLD) {
                if (!this.eyesClosedAt) this.eyesClosedAt = now;
                this.eyesAwayAt = null;
                if (now - this.eyesClosedAt >= this.EYES_CLOSED_THRESHOLD) {
                    await this._handleLivenessViolation('eyes_closed', '⚠️ Eyes closed for too long!');
                    this.eyesClosedAt = now;
                }
            } else {
                this.eyesClosedAt = null;
                const gazeOff = this._gazeOffset(lm);
                if (gazeOff > this.GAZE_THRESHOLD) {
                    if (!this.eyesAwayAt) this.eyesAwayAt = now;
                    if (now - this.eyesAwayAt >= this.EYES_AWAY_THRESHOLD) {
                        await this._handleLivenessViolation('eyes_away', '⚠️ Please look at the screen!');
                        this.eyesAwayAt = now;
                    }
                } else {
                    this.eyesAwayAt = null;
                    this._hideBanner();
                }
            }
        }

        setTimeout(() => { this.animFrame = requestAnimationFrame(() => this._detect()); }, 600);
    }

    // ════════════════════════════════════════════════════════════
    // LIVENESS VIOLATION HANDLER (with warning system)
    // ════════════════════════════════════════════════════════════
    async _handleLivenessViolation(type, message) {
        const now = Date.now();
        if (this.lastViolation[type] && now - this.lastViolation[type] < this.COOLDOWN_MS) return;
        this.lastViolation[type] = now;

        this.livenessWarnings++;
        const snapshot  = this._captureSnapshot();
        await this._logViolation(type, this.livenessWarnings, snapshot);

        if (this.livenessWarnings > this.MAX_WARNINGS) {
            this._showBanner('🚨 Too many violations — test is being submitted automatically.', 'red');
            this._triggerAutoSubmit();
            return;
        }

        const remaining = this.MAX_WARNINGS - this.livenessWarnings;
        const warnText  = remaining === 0
            ? `${message} Warning ${this.livenessWarnings}/${this.MAX_WARNINGS} — Next violation will auto-submit!`
            : `${message} Warning ${this.livenessWarnings}/${this.MAX_WARNINGS} — ${remaining} warning(s) left.`;
        this._showBanner(warnText, remaining === 0 ? 'orange' : 'red');
        this._setDot('orange');
        setTimeout(() => this._hideBanner(), 6000);
    }

    // ════════════════════════════════════════════════════════════
    // MULTI-FACE VIOLATION HANDLER — immediate, no warning pool
    // ════════════════════════════════════════════════════════════
    async _handleMultiFaceViolation() {
        const now = Date.now();
        if (this.lastViolation['multiple_faces'] && now - this.lastViolation['multiple_faces'] < this.COOLDOWN_MS) return;
        this.lastViolation['multiple_faces'] = now;

        const snapshot = this._captureSnapshot();
        await this._logViolation('multiple_faces', 1, snapshot);
        this._showBanner('🚨 Multiple faces detected — test is being submitted automatically.', 'red');
        this._triggerAutoSubmit();
    }

    // ════════════════════════════════════════════════════════════
    // LOG TO DB
    // ════════════════════════════════════════════════════════════
    async _logViolation(type, warningCount, snapshot = null) {
        try {
            await fetch('api/log-proctoring.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    test_id:        this.testId,
                    matric:         this.matric,
                    violation_type: type,
                    warning_count:  warningCount,
                    snapshot:       snapshot  // base64 or null
                })
            });
        } catch (e) { /* fail silently */ }
    }

    // ════════════════════════════════════════════════════════════
    // AUTO-SUBMIT
    // ════════════════════════════════════════════════════════════
    _triggerAutoSubmit() {
        if (this.submitted) return;
        this.submitted = true;
        this.stop();
        setTimeout(() => this.onAutoSubmit(), 1500);
    }

    // ════════════════════════════════════════════════════════════
    // MATH HELPERS
    // ════════════════════════════════════════════════════════════
    _earFromPoints(pts) {
        const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
        const vertical   = d(pts[1], pts[5]) + d(pts[2], pts[4]);
        const horizontal = d(pts[0], pts[3]) * 2;
        return horizontal === 0 ? 0 : vertical / horizontal;
    }

    _gazeOffset(lm) {
        const leftEye  = lm.getLeftEye();
        const rightEye = lm.getRightEye();
        const midLeft  = this._midpoint(leftEye);
        const midRight = this._midpoint(rightEye);
        const spread   = Math.abs(midLeft.x - midRight.x);
        const jawLine  = lm.getJawOutline ? lm.getJawOutline() : null;
        const faceW    = jawLine
            ? Math.abs(jawLine[0].x - jawLine[16].x)
            : spread * 4;
        if (faceW === 0) return 0;
        return Math.abs(spread / faceW - 0.5);
    }

    _midpoint(pts) {
        const sum = pts.reduce((a, p) => ({ x: a.x + p.x, y: a.y + p.y }), { x: 0, y: 0 });
        return { x: sum.x / pts.length, y: sum.y / pts.length };
    }

    // ════════════════════════════════════════════════════════════
    // UI HELPERS
    // ════════════════════════════════════════════════════════════
    _showBanner(msg, color = 'red') {
        const colors = {
            red:    'linear-gradient(135deg,#dc2626,#b91c1c)',
            orange: 'linear-gradient(135deg,#d97706,#b45309)'
        };
        this.bannerEl.style.background = colors[color] || colors.red;
        this.bannerEl.textContent = msg;
        this.bannerEl.style.display = 'block';
    }

    _hideBanner() { this.bannerEl.style.display = 'none'; }

    _setDot(color) {
        const map = { green: '#10b981', red: '#ef4444', orange: '#f59e0b' };
        const c = map[color] || map.green;
        this.dotEl.style.background = c;
        this.dotEl.style.boxShadow  = `0 0 6px ${c}`;
    }

    // ════════════════════════════════════════════════════════════
    // STOP
    // ════════════════════════════════════════════════════════════
    stop() {
        this.isRunning = false;
        if (this.animFrame) cancelAnimationFrame(this.animFrame);
        if (this.heartbeatInterval) clearInterval(this.heartbeatInterval);
        if (this.stream) this.stream.getTracks().forEach(t => t.stop());
        // Exit fullscreen cleanly
        try {
            const exit = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen;
            if (exit && document.fullscreenElement) exit.call(document);
        } catch(e) {}
    }
}
