# CA Portal — Continuous Assessment Portal

A full-stack web-based continuous assessment system built for a university faculty, featuring biometric identity verification, real-time anti-cheat proctoring, and role-based dashboards for students, lecturers, and administrators.

## Overview

CA Portal digitizes the continuous assessment process — from timed testing to result computation — while actively preventing exam malpractice through webcam-based biometric checks and behavioral monitoring during tests.

## Key Features

**🔐 Biometric Verification**
- Face recognition using `face-api.js` with liveness detection
- Multi-sample facial descriptor averaging for accuracy
- Tuned matching threshold (0.40) to balance security and false rejections

**🕵️ Anti-Cheat Proctoring**
- Detects 7 categories of suspicious behavior during tests (e.g. tab switching, face loss, multiple faces)
- Warning-based escalation for minor violations, zero-tolerance flags for severe ones
- Full proctoring log viewable per student attempt

**📝 Timed Testing Engine**
- Auto-submitting timed assessments
- Retake approval workflow (lecturer-controlled)
- Attempt limits with correct max-attempt enforcement

**👥 Role-Based Dashboards**
- **Students:** take tests, view results (percentage + pass/fail), see test history
- **Lecturers:** create/manage tests, review proctoring logs, approve retakes
- **Admins:** manage users, control portal-wide or per-lecturer maintenance/exam mode

**🔔 Notifications**
- Audience-targeted announcements with per-user read tracking

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP |
| Database | MySQL |
| Frontend | JavaScript, HTML, CSS |
| Biometrics | face-api.js |
| Auth | Session-based, OTP-verified |

## Security

This project has undergone multiple audit passes. Vulnerabilities identified and fixed include:
- SQL injection (raw query interpolation → prepared statements)
- IDOR on student logs and biometric data endpoints
- XSS from unencoded PHP-to-JS variable injection
- Session fixation on login
- OTP exposure in API responses

## Screenshots

*(Add screenshots here — dashboard, test-taking screen, proctoring log view)*

## Setup

```bash
git clone https://github.com/vikkyfresh/ca-portal.git
cd ca-portal
# Import database
mysql -u root -p ca_portal < database/schema.sql
# Configure DB credentials in config.php
# Point web server document root to /public
```

## Status

Actively maintained. Built as a final-year academic project, extended through multiple rounds of security and UX improvement.

## Author

**Vikkyfresh** — Computer Science, Prince Abubakar Audu University
[GitHub](https://github.com/vikkyfresh) · [Portfolio link]
