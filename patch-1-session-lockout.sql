-- ============================================================
--  PATCH 1 — SESSION LOCKOUT FIX
--  Run once on your ca_portal database.
--
--  Adds a timestamp next to session_token so abandoned sessions
--  (browser closed / crashed mid-test, before logout or submit)
--  can be auto-expired instead of permanently locking the student
--  out. See api/check-student.php for the expiry logic
--  (SESSION_LOCK_TIMEOUT_MINUTES, defined in includes/config.php).
-- ============================================================

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS session_token_created_at DATETIME NULL DEFAULT NULL
    COMMENT 'When session_token was written — used to auto-expire abandoned locks';

-- Optional: clears every currently-stuck lock immediately after you deploy
-- this patch, so existing locked-out students don't have to wait out the
-- timeout window once. Safe to run — anyone with a genuinely active session
-- will just get a fresh token next time they verify.
-- UPDATE students SET session_token = NULL, session_token_created_at = NULL;
