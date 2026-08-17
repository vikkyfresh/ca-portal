<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Lecturer Portal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(to bottom right, #0f172a, #1e3a8a, #0f172a); display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; border-radius: 16px; padding: 32px; max-width: 400px; width: 100%; }
        h2 { margin-bottom: 20px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; margin-bottom: 4px; color: #475569; }
        .form-group input { width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px; }
        .btn { width: 100%; padding: 12px; background: #0f172a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .step { display: none; }
        .step.active { display: block; }
        a { color: #1e3a8a; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Forgot Password</h2>
        
        <div id="step1" class="step active">
            <div class="form-group"><label>Registered Email</label><input type="email" id="email" required></div>
            <button class="btn" onclick="sendOTP()">Send OTP</button>
        </div>
        
        <div id="step2" class="step">
            <p>OTP sent to <strong id="sentEmail"></strong></p>
            <div class="form-group"><label>Enter OTP</label><input type="text" id="otp" maxlength="6" required></div>
            <button class="btn" onclick="verifyOTP()">Verify</button>
        </div>
        
        <div id="step3" class="step">
            <div class="form-group"><label>New Password</label><input type="password" id="newPassword" required></div>
            <button class="btn" onclick="resetPassword()">Reset Password</button>
        </div>
        
        <p style="margin-top:20px;"><a href="index.php">Back to Login</a></p>
    </div>
    
    <script>
        let email = '', otp = '';
        
        async function sendOTP() {
            email = document.getElementById('email').value;
            const fd = new FormData();
            fd.append('action', 'request_otp');
            fd.append('email', email);
            const resp = await fetch('api/password.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('sentEmail').textContent = email;
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step2').classList.add('active');
                alert('OTP sent! (Check console: ' + data.otp + ')');
            } else {
                alert(data.message || 'Failed');
            }
        }
        
        async function verifyOTP() {
            otp = document.getElementById('otp').value;
            const fd = new FormData();
            fd.append('action', 'verify_otp');
            fd.append('email', email);
            fd.append('otp', otp);
            const resp = await fetch('api/password.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('step2').classList.remove('active');
                document.getElementById('step3').classList.add('active');
            } else {
                alert('Invalid OTP');
            }
        }
        
        async function resetPassword() {
            const pw = document.getElementById('newPassword').value;
            const fd = new FormData();
            fd.append('action', 'reset_password');
            fd.append('email', email);
            fd.append('otp', otp);
            fd.append('new_password', pw);
            const resp = await fetch('api/password.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                alert('Password reset! Please login.');
                window.location.href = 'index.php?message=password_changed';
            } else {
                alert(data.message || 'Failed');
            }
        }
    </script>
</body>
</html>