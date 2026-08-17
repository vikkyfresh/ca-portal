-- ============================================================
--  PATCH 4 — AUDIT LOG SYSTEM
--  Run once on your ca_portal database.
--
--  Replaces the old admin/audit-logs.php (which only showed test
--  attempts) with a real, system-wide activity log: logins/logouts
--  for all three roles, test creation and completion, face
--  enrollment (both self-service and admin-assisted), retake
--  approvals, and portal-control changes.
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    event_type   VARCHAR(50)     NOT NULL
                 COMMENT 'e.g. student_login, lecturer_login, admin_login, test_completed, test_created, face_enrolled, retake_approved, portal_setting_changed',
    actor_type   ENUM('student','lecturer','admin','system') NOT NULL,
    actor_id     VARCHAR(50)     NULL COMMENT 'matric number or admins.id, as a string',
    actor_name   VARCHAR(150)    NULL,
    description  VARCHAR(500)    NOT NULL COMMENT 'human-readable summary shown in the log table',
    ip_address   VARCHAR(45)     NULL,
    metadata     TEXT            NULL COMMENT 'optional JSON blob with extra structured detail',
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event_type (event_type),
    INDEX idx_actor       (actor_type, actor_id),
    INDEX idx_created     (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='System-wide activity log for the admin audit trail';

-- ============================================================
-- Done.
-- ============================================================
