<?php
/**
 * CS Dept CA Portal - Student Login
 * Student Authentication with Matric Number
 */
require_once __DIR__ . '/includes/config.php';

$accessBlock = getAccessBlock('student');
if ($accessBlock) {
    renderAccessBlockPage($accessBlock, 'student', 'index.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - CS Dept CA Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(to bottom right, #0f172a, #1e3a8a, #0f172a);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 480px;
        }

        .card {
            background: white;
            border-radius: 24px;
            padding: 36px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a202c;
            text-align: center;
            margin-bottom: 8px;
        }

        .subtitle {
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 24px;
        }

        .auth-section h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .instruction {
            color: #718096;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 600;
            font-size: 0.9rem;
            color: #4a5568;
            margin-bottom: 6px;
        }

        input[type="text"] {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        input[type="text"]:focus {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }

        input[type="text"]::placeholder {
            color: #a0aec0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            letter-spacing: normal;
            font-size: 0.9rem;
            text-transform: none;
        }

        .format-hint {
            color: #718096;
            font-size: 0.8rem;
            margin-top: 6px;
            margin-bottom: 2px;
        }

        .level-hint {
            color: #48bb78;
            font-size: 0.8rem;
            margin-bottom: 28px;
        }

        .button-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-primary {
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: linear-gradient(to right, #0f172a, #1e3a8a);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            padding: 12px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
            color: #4a5568;
            text-decoration: none;
            text-align: center;
            display: block;
        }

        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .message {
            margin-top: 20px;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            display: none;
        }

        .message.error {
            display: block;
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fc8181;
        }

        .message.success {
            display: block;
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .message.info {
            display: block;
            background: #bee3f8;
            color: #2b6cb0;
            border: 1px solid #90cdf4;
        }

        .message.warning {
            display: block;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }

        .footer-links {
            text-align: center;
            margin-top: 24px;
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .footer-links a {
            color: #1e3a8a;
            text-decoration: none;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>CS Dept CA Portal</h1>
            <p class="subtitle">Continuous Assessment System</p>
            
            <div class="auth-section">
                <h2>Student Authentication</h2>
                <p class="instruction">Enter your matriculation number</p>
                
                <label for="matric">Matriculation Number</label>
                <input type="text" id="matric" placeholder="E.G., 23CS1039" maxlength="8" autocomplete="off">
                <p class="format-hint">Format: YYCSXXXX (e.g., 23CS1039)</p>
                <p class="level-hint">Level will be auto-detected</p>
                
                <div class="button-group">
                    <button id="proceedBtn" class="btn-primary">Proceed to Face Verification</button>
                    <a href="index.php" class="btn-secondary">← Back to Portal</a>
                </div>
                
                <div id="message" class="message"></div>
            </div>
            
            <div class="footer-links">
                <a href="lecturer/">Lecturer Portal</a> | 
                <a href="admin/">Admin Portal</a>
            </div>
        </div>
    </div>
    
    <script>
        const matricInput = document.getElementById('matric');
        const proceedBtn = document.getElementById('proceedBtn');
        const messageDiv = document.getElementById('message');
        
        // Auto-capitalize input
        matricInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Matric format: YYcs[1|2]NNN
        // 1 = UTME  → level = (currentYear - enrollYear + 1) * 100
        // 2 = DE    → level = (currentYear - enrollYear + 2) * 100
        function validateMatric(matric) {
            return /^\d{2}[Cc][Ss][12]\d{3,}$/.test(matric);
        }

        function parseMatric(matric) {
            const m = matric.toUpperCase().match(/^(\d{2})CS([12])(\d{3,})$/);
            if (!m) return null;
            const enrollYear  = parseInt(m[1]);
            const type        = m[2] === '1' ? 'UTME' : 'DE';
            const currentYear = new Date().getFullYear() % 100;
            let   yearsPassed = currentYear - enrollYear;
            if (yearsPassed < 0) yearsPassed += 100;
            const levelOffset = type === 'DE' ? 2 : 1;
            let   level = (yearsPassed + levelOffset) * 100;
            level = Math.max(100, Math.min(500, level));
            return { type, enrollYear: 2000 + enrollYear, level };
        }

        function detectLevel(matric) {
            const info = parseMatric(matric);
            return info ? info.level : 100;
        }
        
        // Show message
        function showMessage(text, type) {
            messageDiv.textContent = text;
            messageDiv.className = 'message ' + type;
        }
        
        // Clear message
        function clearMessage() {
            messageDiv.textContent = '';
            messageDiv.className = 'message';
        }
        
        // Handle Proceed button
        proceedBtn.addEventListener('click', function() {
            const matric = matricInput.value.trim().toUpperCase();
            
            clearMessage();
            
            if (!matric) {
                showMessage('Please enter your matriculation number', 'error');
                matricInput.focus();
                return;
            }
            
            if (!validateMatric(matric)) {
                showMessage('Invalid format. Use YYCSXXXX (e.g., 23CS1039)', 'error');
                matricInput.focus();
                return;
            }
            
            const info  = parseMatric(matric);
            const level = info ? info.level : detectLevel(matric);
            const tag   = info ? ' [' + info.type + ']' : '';
            showMessage(`Level ${level}${tag} detected. Verifying...`, 'info');
            
            // ✅ STEP 1: Check if student exists and face is enrolled
            fetch(`api/check-student.php?matric=${matric}`)
                .then(response => response.json())
                .then(data => {
                    // Portal control check
                    if (data.portal_closed) {
                        showMessage('🔒 ' + data.message, 'error');
                        return;
                    }
                    if (!data.exists) {
                        showMessage('⛔ Matric number not found. Contact your lecturer.', 'error');
                        return;
                    }

                    // ✅ CHECK: Is there even an active test for this student's level?
                    // Checked before face enrollment — no point sending someone to enroll
                    // their face (or blocking them for not having done so) for a test that
                    // doesn't exist right now.
                    return fetch(`api/check-active-test.php?level=${data.level}&matric=${matric}`)
                        .then(response => response.json())
                        .then(testData => {
                            if (!testData.has_test) {
                                showMessage('⛔ No Active Test: There is currently no active test for Level ' + data.level + '. Contact your lecturer.', 'warning');
                                return;
                            }

                            // ✅ CHECK: Face enrolled? If not, send them straight into
                            // self-enrollment instead of a dead-end message — they enroll
                            // once, then continue through the normal verification flow.
                            if (!data.face_enrolled) {
                                showMessage('📷 Face Not Registered: Redirecting you to enroll your face now — this only takes a moment.', 'info');
                                proceedBtn.disabled = true;
                                setTimeout(() => {
                                    window.location.href = `face-enroll-required.php?matric=${matric}`;
                                }, 1200);
                                return;
                            }

                            // ✅ CHECK: Concurrent session active?
                            if (data.session_active) {
                                showMessage('🔒 Session Already Active: Another device is currently logged in with this matric number. If this is a mistake (e.g. you were disconnected earlier), the lock clears automatically — try again shortly, or contact your lecturer/admin to release it immediately.', 'warning');
                                return;
                            }

                            if (testData.already_taken) {
                                showMessage('✅ You have already completed this test. Contact your lecturer if you believe a retake should be approved.', 'info');
                                return;
                            }

                            // ✅ All checks passed!
                            showMessage(`✅ Welcome ${data.name}! Redirecting to face verification...`, 'success');
                            proceedBtn.disabled = true;

                            setTimeout(() => {
                                window.location.href = `face-verify.php?matric=${matric}`;
                            }, 1500);
                        });
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('Server error. Please try again later.', 'error');
                });
        });
        
        // Allow Enter key
        matricInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                proceedBtn.click();
            }
        });

        // Live portal-control check — if the site closes or student access gets
        // blocked while this page is open, reload so the maintenance screen shows.
        setInterval(function() {
            fetch('api/portal-status.php?role=student')
                .then(function(r) { return r.json(); })
                .then(function(d) { if (d.blocked) window.location.reload(); })
                .catch(function() {});
        }, 5000);
    </script>
</body>
</html>