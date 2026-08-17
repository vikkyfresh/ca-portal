document.addEventListener('DOMContentLoaded', function() {
    
    const matricInput = document.getElementById('matric');
    const proceedBtn = document.getElementById('proceedBtn');
    const previewBtn = document.getElementById('previewBtn');
    const messageDiv = document.getElementById('message');
    
    // Auto-capitalize input
    matricInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
    
    // Format validation function
    // Matric: YYcs[1|2]NNN  (1=UTME, 2=DE)
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
    
    // Show message helper
    function showMessage(text, type) {
        messageDiv.textContent = text;
        messageDiv.className = 'message ' + type;
    }
    
    // Clear message
    function clearMessage() {
        messageDiv.textContent = '';
        messageDiv.className = 'message';
    }
    
    // Handle Proceed button click
    proceedBtn.addEventListener('click', function() {
        const matric = matricInput.value.trim().toUpperCase();
        
        clearMessage();
        
        // Check if empty
        if (!matric) {
            showMessage('Please enter your matriculation number', 'error');
            matricInput.focus();
            return;
        }
        
        // Validate format
        if (!validateMatric(matric)) {
            showMessage('Invalid format. Use YYCSXXXX (e.g., 23CS1039)', 'error');
            matricInput.focus();
            return;
        }
        
        // Calculate level
        const level = detectLevel(matric);
        showMessage(`Level ${level} detected. Checking matric number...`, 'info');
        
        // For now, simulate checking (we'll add real backend later)
        setTimeout(() => {
            // In Phase 2, this will be a real API call
            showMessage(`Matric ${matric} validated. Redirecting to face verification...`, 'success');
            
            // For now, just log to console
            console.log('Proceeding with matric:', matric, 'Level:', level);
            
            // Will redirect to face-verify.php in Phase 2
            // window.location.href = `face-verify.php?matric=${matric}`;
        }, 1000);
    });
    
    // Handle Preview button click
    previewBtn.addEventListener('click', function() {
        clearMessage();
        showMessage('Preview mode - showing all available tests (admin feature)', 'info');
        
        // Will redirect to preview page later
        // window.location.href = 'preview.php';
    });
    
    // Allow Enter key to trigger Proceed
    matricInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            proceedBtn.click();
        }
    });
    
});