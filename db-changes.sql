-- ============================================================
--  CA PORTAL — DATABASE CHANGES
--  Run these queries once on your MySQL/MariaDB database
--  Order matters — run them top to bottom
-- ============================================================


-- ────────────────────────────────────────────────────────────
-- 1.  ENROLLMENT LINKS TABLE
--     Stores shareable links the admin generates so students
--     can enroll their own face without admin being present.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enrollment_links (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    token       VARCHAR(48)     NOT NULL UNIQUE,          -- random hex token in URL
    label       VARCHAR(120)    NOT NULL DEFAULT '',       -- admin's friendly label
    expires_at  DATETIME        NOT NULL,                  -- admin-set expiry
    revoked     TINYINT(1)      NOT NULL DEFAULT 0,        -- 1 = admin killed it early
    created_by  INT UNSIGNED    NULL,                      -- FK → admins.id
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ────────────────────────────────────────────────────────────
-- 2.  RETAKE APPROVALS TABLE
--     When a lecturer approves a student to retake a test,
--     a row is inserted here. The student's test page reads
--     this table on login / page load and shows the test again.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS retake_approvals (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    student_matric  VARCHAR(20)     NOT NULL,              -- FK → students.matric
    test_id         INT UNSIGNED    NOT NULL,              -- FK → tests.id  (adjust col name to match yours)
    approved_by     INT UNSIGNED    NOT NULL,              -- FK → lecturers.id (adjust to match yours)
    approved_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used            TINYINT(1)      NOT NULL DEFAULT 0,    -- 1 = student has already retaken
    used_at         DATETIME        NULL,

    UNIQUE KEY uq_student_test (student_matric, test_id),  -- one pending retake per test
    INDEX idx_matric  (student_matric),
    INDEX idx_test    (test_id),
    INDEX idx_used    (used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ────────────────────────────────────────────────────────────
-- 3.  CUSTOM TEST LINKS TABLE
--     Lets a lecturer generate a test link restricted to a
--     specific list of students (by matric number).
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS custom_test_links (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    token       VARCHAR(48)     NOT NULL UNIQUE,
    test_id     INT UNSIGNED    NOT NULL,                  -- which test this link is for
    created_by  INT UNSIGNED    NOT NULL,                  -- lecturer who made it
    expires_at  DATETIME        NULL,                      -- NULL = no expiry
    revoked     TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_token   (token),
    INDEX idx_test    (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ────────────────────────────────────────────────────────────
-- 4.  CUSTOM TEST LINK STUDENTS
--     The allowed matric numbers for each custom test link.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS custom_test_link_students (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    link_id     INT UNSIGNED    NOT NULL,                  -- FK → custom_test_links.id
    matric      VARCHAR(20)     NOT NULL,

    UNIQUE KEY uq_link_matric (link_id, matric),
    INDEX idx_link   (link_id),
    INDEX idx_matric (matric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ────────────────────────────────────────────────────────────
-- 5.  CONFIRM face_descriptor column exists on students table
--     (Skip if it already exists — this won't break anything)
-- ────────────────────────────────────────────────────────────
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS face_descriptor LONGTEXT NULL DEFAULT NULL
        COMMENT 'JSON array of 128 floats from face-api.js';


-- ────────────────────────────────────────────────────────────
--  SUMMARY OF WHAT EACH TABLE DOES
-- ────────────────────────────────────────────────────────────
--
--  enrollment_links         → Admin generates shareable link with expiry.
--                             student-enroll.php?token=XXX validates this.
--
--  retake_approvals         → Lecturer approves student retake.
--                             Row with used=0 means student can retake.
--                             After retake, set used=1, used_at=NOW().
--
--  custom_test_links        → Lecturer creates test link for specific students.
--
--  custom_test_link_students→ The matric numbers allowed on a custom link.
--
--  students.face_descriptor → Already in your DB — just confirming column exists.
--
-- ────────────────────────────────────────────────────────────

-- ────────────────────────────────────────────────────────────
-- 6.  CONCURRENT LOGIN PREVENTION
--     Stores an active session token per student.
--     When a student logs in, a token is written here.
--     A second login attempt is blocked if a token already exists.
--     Token is cleared on logout or session expiry.
-- ────────────────────────────────────────────────────────────
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'Active session token — NULL means no active session';
