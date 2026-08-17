-- ============================================================
--  PATCH 3 — FULL PROCTORING HARDENING
--  Run once on your ca_portal database.
-- ============================================================


-- ────────────────────────────────────────────────────────────
-- 1. New violation types: multiple faces in frame, camera/model
--    unavailable. Both were previously invisible to log-proctoring.php.
-- ────────────────────────────────────────────────────────────
ALTER TABLE proctoring_logs
    MODIFY COLUMN event_type ENUM(
        'face_out',
        'eyes_closed',
        'eyes_away',
        'tab_switch',
        'fullscreen_exit',
        'multiple_faces',
        'no_camera'
    ) NOT NULL;


-- ────────────────────────────────────────────────────────────
-- 2. LIVE HEARTBEAT TRACKING
--    One row per in-progress attempt. The client pings this every
--    ~15s while the test is open. Lets the server detect the case
--    client-side proctoring can never self-report: its own script
--    being blocked, disabled, or killed mid-test. Cleaned up
--    (deleted) automatically when the attempt is submitted —
--    see api/submit-test.php.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS active_attempts (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    test_id             INT UNSIGNED    NOT NULL,
    student_matric      VARCHAR(20)     NOT NULL,
    started_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_heartbeat      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    heartbeat_gaps      INT UNSIGNED    NOT NULL DEFAULT 0
                        COMMENT 'Count of missed/late heartbeats — signals the monitor script stalled or was tampered with',
    monitoring_active   TINYINT(1)      NOT NULL DEFAULT 1
                        COMMENT '0 = camera/model failed to start on the client (degraded proctoring)',

    UNIQUE KEY uq_test_matric (test_id, student_matric),
    INDEX idx_heartbeat (last_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ────────────────────────────────────────────────────────────
-- 3. Per-attempt integrity verdict, computed at submission time
--    from the active_attempts row and written permanently onto
--    the attempts record so lecturers can see it after the fact.
--      NULL/'clean'        -> no gaps, camera monitoring ran throughout
--      'degraded'          -> camera/model never started (proctoring ran blind)
--      'gaps'              -> monitor stalled one or more times mid-test
--      'no_monitoring'     -> no heartbeat ever received at all (script
--                             likely blocked/disabled by the student)
-- ────────────────────────────────────────────────────────────
ALTER TABLE attempts
    ADD COLUMN IF NOT EXISTS proctoring_flag VARCHAR(20) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS proctoring_note VARCHAR(255) NULL DEFAULT NULL;

-- ============================================================
-- Done.
-- ============================================================
