-- ================================================================
-- CA PORTAL — CUMULATIVE UPDATE SCRIPT
-- Run this entire file in phpMyAdmin SQL tab on your ca_portal DB
-- Safe to run on a fresh or existing database.
-- ================================================================


-- ────────────────────────────────────────────────────────────────
-- 1. CONCURRENT LOGIN PREVENTION
--    Stores one active session token per student.
--    NULL = no active session (student is logged out).
-- ────────────────────────────────────────────────────────────────
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'Active session token — NULL means no active session';


-- ────────────────────────────────────────────────────────────────
-- 2. PROCTORING / LIVENESS + ANTI-CHEAT LOGS
--    One row per violation event during a student test session.
--    violation_type covers: face detection, eye tracking,
--    tab switching, and fullscreen exit.
--    snapshot_path stores webcam evidence image (nullable).
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS proctoring_logs (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    test_id         INT             NOT NULL,
    student_matric  VARCHAR(20)     NOT NULL,
    event_type      ENUM(
                        'face_out',
                        'eyes_closed',
                        'eyes_away',
                        'tab_switch',
                        'fullscreen_exit'
                    )               NOT NULL,
    event_data      TEXT            NULL DEFAULT NULL
                    COMMENT 'JSON blob e.g. {"warning_count":2}',
    screenshot_path VARCHAR(255)    NULL DEFAULT NULL
                    COMMENT 'Relative path to webcam snapshot image, if captured',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_test    (test_id),
    INDEX idx_matric  (student_matric),
    INDEX idx_both    (test_id, student_matric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anti-cheat violation log — liveness, tab switch, fullscreen';


-- ================================================================
-- Done. Both updates are now applied.
-- ================================================================
