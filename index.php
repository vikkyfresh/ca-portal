<?php
require_once 'includes/config.php';

// ── PORTAL CONTROL: full site-wide maintenance blocks the landing page too ──
// (role-specific blocks like students_blocked/lecturers_blocked are handled on
// the respective login pages, since one role being open should still show here)
$siteBlock = getAccessBlock('student');
if ($siteBlock && $siteBlock['variant'] === 'maintenance') {
    renderAccessBlockPage($siteBlock, 'student', 'index.php');
}
// ──────────────────────────────────────────────────────────────────────────

// Get counts with fallback to 0
$totalStudents    = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn() ?: 0;
$totalLecturers   = $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'lecturer'")->fetchColumn() ?: 0;
$totalTests       = $pdo->query("SELECT COUNT(*) FROM tests WHERE is_active = 1")->fetchColumn() ?: 0;
$totalSubmissions = $pdo->query("SELECT COUNT(*) FROM attempts WHERE status = 'completed'")->fetchColumn() ?: 0;

$session  = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_session'")->fetchColumn() ?: '2025/2026';
$semester = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'current_semester'")->fetchColumn() ?: 'First Semester';

$announcements = $pdo->query("
    SELECT course_code, test_title, level, created_at
    FROM tests
    WHERE is_active = 1
    ORDER BY created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CS CA Portal — Prince Abubakar Audu University, Anyigba</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* ── RESET ── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }
a    { text-decoration:none; color:inherit; }

:root {
    --navy:         #0a1628;
    --navy-mid:     #0f1f3d;
    --navy-light:   #1a2f54;
    --blue:         #1a4fd8;
    --blue-soft:    #2563eb;
    --blue-pale:    #3b82f6;
    --green:        #10b981;
    --green-soft:   #34d399;
    --purple:       #a78bfa;
    --purple-soft:  #c4b5fd;
    --amber:        #f59e0b;
    --amber-soft:   #fbbf24;
    --white:        #ffffff;
    --text:         rgba(255,255,255,0.88);
    --text-muted:   rgba(255,255,255,0.52);
    --text-dim:     rgba(255,255,255,0.3);
    --card:         rgba(255,255,255,0.055);
    --card-border:  rgba(255,255,255,0.09);
    --card-hover:   rgba(255,255,255,0.09);
    --r:            14px;
    --r-lg:         20px;
    --t:            all 0.22s ease;
}

body {
    font-family:'Inter',sans-serif;
    background:var(--navy);
    color:var(--text);
    overflow-x:hidden;
    line-height:1.6;
}

/* ── PAGE BACKGROUND ── */
.bg {
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
        radial-gradient(ellipse 900px 600px at 85% 5%,  rgba(37,99,235,0.16) 0%, transparent 65%),
        radial-gradient(ellipse 600px 500px at 5%  95%, rgba(10,22,40,0.9)   0%, transparent 70%),
        linear-gradient(155deg, #060e1c 0%, #0a1628 45%, #0d1a33 100%);
}

.bg::after {
    content:'';
    position:absolute; inset:0;
    background-image:
        radial-gradient(rgba(59,130,246,0.07) 1px, transparent 1px);
    background-size:32px 32px;
}

header, main, footer { position:relative; z-index:1; }

/* ══════════════ HEADER ══════════════ */
header {
    position:fixed; top:0; left:0; right:0; z-index:1000;
    height:66px;
    display:flex; align-items:center;
    padding:0 5%;
    background:rgba(6,14,28,0.8);
    backdrop-filter:blur(24px) saturate(160%);
    -webkit-backdrop-filter:blur(24px) saturate(160%);
    border-bottom:1px solid rgba(59,130,246,0.12);
}

.nav-wrap {
    width:100%; max-width:1280px; margin:0 auto;
    display:flex; align-items:center; justify-content:space-between;
}

.logo { display:flex; align-items:center; gap:12px; }

.logo-icon {
    width:40px; height:40px;
    display:flex; align-items:center; justify-content:center;
}

.logo-icon img { width:100%; height:100%; object-fit:contain; }

.logo-text strong {
    display:block; font-size:15px; font-weight:700;
    color:var(--white); letter-spacing:-0.2px; line-height:1.2;
}

.logo-text span { font-size:11px; color:var(--text-muted); }

.nav-links { display:flex; align-items:center; gap:4px; }

.nav-links a {
    font-size:14px; font-weight:500; color:var(--text-muted);
    padding:7px 13px; border-radius:8px; transition:var(--t);
}

.nav-links a:hover { color:var(--white); background:rgba(255,255,255,0.06); }

.session-pill {
    display:flex; align-items:center; gap:7px;
    background:rgba(59,130,246,0.1);
    border:1px solid rgba(59,130,246,0.2);
    border-radius:20px; padding:5px 13px;
    font-size:12px; font-weight:500; color:var(--blue-pale);
    margin-right:14px;
}

.pill-dot {
    width:6px; height:6px; background:var(--green);
    border-radius:50%; animation:blink 2s infinite;
}

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

.nav-cta {
    display:inline-flex; align-items:center; gap:7px;
    padding:8px 20px;
    background:var(--blue); color:var(--white);
    border-radius:8px; font-size:14px; font-weight:600;
    box-shadow:0 4px 16px rgba(37,99,235,0.35);
    transition:var(--t); margin-left:6px;
}

.nav-cta:hover { background:#1d4ed8; transform:translateY(-1px); box-shadow:0 6px 22px rgba(37,99,235,0.45); }

/* ══════════════ HERO ══════════════ */
.hero { min-height:100vh; display:flex; align-items:center; padding:110px 5% 72px; }

.hero-inner {
    max-width:1280px; margin:0 auto; width:100%;
    display:grid; grid-template-columns:1fr 410px; gap:64px; align-items:center;
}

.eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.22);
    color:var(--blue-pale); font-size:12px; font-weight:600; letter-spacing:.8px;
    text-transform:uppercase; padding:6px 14px; border-radius:30px; margin-bottom:22px;
}

.eyebrow i { font-size:9px; }

.hero h1 {
    font-size:clamp(2.4rem,4.5vw,3.8rem); font-weight:700; line-height:1.08;
    letter-spacing:-1.5px; color:var(--white); margin-bottom:20px;
}

.hero h1 .grad {
    background:linear-gradient(90deg,#93c5fd,#3b82f6,#60a5fa);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}

.hero-sub { font-size:16px; color:var(--text-muted); line-height:1.75; max-width:490px; margin-bottom:38px; font-weight:400; }

.hero-btns { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:48px; }

.btn {
    display:inline-flex; align-items:center; gap:9px; padding:13px 26px;
    border-radius:9px; font-size:14.5px; font-weight:600; transition:var(--t);
    cursor:pointer; border:none; font-family:'Inter',sans-serif;
}

.btn-primary { background:var(--blue); color:var(--white); box-shadow:0 4px 20px rgba(37,99,235,0.35); }
.btn-primary:hover { background:#1d4ed8; transform:translateY(-2px); box-shadow:0 8px 28px rgba(37,99,235,0.45); }
.btn-outline { background:var(--card); border:1px solid var(--card-border); color:var(--text); }
.btn-outline:hover { background:var(--card-hover); border-color:rgba(59,130,246,0.3); transform:translateY(-2px); }

.trust { display:flex; gap:22px; flex-wrap:wrap; }
.trust-item { display:flex; align-items:center; gap:7px; font-size:13px; color:var(--text-muted); }
.trust-item i { color:var(--blue-soft); font-size:12px; }

/* ── STATS CARD ── */
.stats-card {
    background:rgba(255,255,255,0.04); border:1px solid rgba(59,130,246,0.15);
    border-radius:var(--r-lg); padding:30px; backdrop-filter:blur(16px);
    position:relative; overflow:hidden;
}

.stats-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,transparent,#3b82f6,transparent); }

.card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; }

.card-head h3 { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; color:var(--text-muted); }

.live-badge {
    display:flex; align-items:center; gap:5px; font-size:11px; font-weight:600;
    color:var(--green); background:rgba(16,185,129,0.1);
    border:1px solid rgba(16,185,129,0.2); padding:3px 10px; border-radius:20px;
}

.live-badge .dot { width:5px; height:5px; background:var(--green); border-radius:50%; animation:blink 2s infinite; }

.stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:18px; }

.stat-box {
    background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07);
    border-radius:11px; padding:18px 14px; transition:var(--t);
}

.stat-box:hover { background:rgba(59,130,246,0.08); border-color:rgba(59,130,246,0.2); }

.stat-icon { font-size:17px; margin-bottom:12px; }
.stat-box:nth-child(1) .stat-icon { color:var(--blue-pale); }
.stat-box:nth-child(2) .stat-icon { color:var(--green-soft); }
.stat-box:nth-child(3) .stat-icon { color:var(--purple-soft); }
.stat-box:nth-child(4) .stat-icon { color:var(--amber-soft); }

.stat-num { font-size:2rem; font-weight:700; color:var(--white); line-height:1; letter-spacing:-1px; margin-bottom:4px; }
.stat-lbl { font-size:12px; color:var(--text-muted); }

.ses-strip {
    display:flex; align-items:center; justify-content:space-between;
    background:rgba(59,130,246,0.07); border:1px solid rgba(59,130,246,0.14);
    border-radius:10px; padding:13px 16px;
}

.ses-strip-inner p { font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-dim); margin-bottom:3px; }
.ses-strip-inner strong { font-size:13.5px; font-weight:600; color:var(--blue-pale); }
.ses-strip i { font-size:19px; color:rgba(59,130,246,0.3); }

/* ══════════════ SHARED ══════════════ */
section { padding:88px 5%; }
.sec-wrap { max-width:1280px; margin:0 auto; }

.sec-tag {
    display:inline-flex; align-items:center; gap:7px;
    font-size:11px; font-weight:700; letter-spacing:1.5px;
    text-transform:uppercase; color:var(--blue-soft); margin-bottom:10px;
}

.sec-tag::before { content:''; display:block; width:16px; height:2px; background:var(--blue-soft); border-radius:2px; }

.sec-title { font-size:clamp(1.8rem,3vw,2.5rem); font-weight:700; letter-spacing:-.7px; color:var(--white); line-height:1.18; margin-bottom:10px; }
.sec-desc { font-size:15.5px; color:var(--text-muted); line-height:1.7; max-width:480px; }
.divider { max-width:1280px; margin:0 auto; height:1px; background:rgba(59,130,246,0.09); }

/* ══════════════ PORTALS ══════════════ */
.portals-section { background:rgba(0,0,0,0.18); border-top:1px solid rgba(59,130,246,0.07); border-bottom:1px solid rgba(59,130,246,0.07); }
.portals-head { display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap; margin-bottom:44px; }
.portals-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }

.p-card {
    background:var(--card); border:1px solid var(--card-border);
    border-radius:var(--r-lg); padding:34px 28px; position:relative;
    overflow:hidden; transition:var(--t); cursor:pointer;
}

.p-card:hover { background:var(--card-hover); border-color:rgba(59,130,246,0.28); transform:translateY(-5px); box-shadow:0 18px 50px rgba(0,0,0,0.35); }
.p-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; opacity:.8; transition:opacity .3s; }
.p-card:hover::before { opacity:1; }
.p-student::before { background:linear-gradient(90deg,#2563eb,#60a5fa); }
.p-lecturer::before { background:linear-gradient(90deg,#10b981,#34d399); }
.p-admin::before { background:linear-gradient(90deg,#a78bfa,#c4b5fd); }

.p-card::after { content:''; position:absolute; top:-50px; right:-50px; width:140px; height:140px; border-radius:50%; opacity:.05; transition:opacity .3s; }
.p-card:hover::after { opacity:.12; }
.p-student::after { background:#3b82f6; }
.p-lecturer::after { background:#10b981; }
.p-admin::after { background:#a78bfa; }

.p-icon { width:52px; height:52px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:21px; margin-bottom:20px; position:relative; z-index:1; }
.p-student .p-icon { background:rgba(59,130,246,0.13); color:var(--blue-pale); }
.p-lecturer .p-icon { background:rgba(16,185,129,0.13); color:var(--green-soft); }
.p-admin .p-icon { background:rgba(167,139,250,0.13); color:var(--purple-soft); }

.p-card h3 { font-size:1.15rem; font-weight:700; color:var(--white); margin-bottom:9px; position:relative; z-index:1; letter-spacing:-.2px; }
.p-card p { font-size:13.5px; color:var(--text-muted); line-height:1.65; margin-bottom:26px; position:relative; z-index:1; }

.p-link { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:600; position:relative; z-index:1; transition:gap .2s; }
.p-student .p-link { color:var(--blue-pale); }
.p-lecturer .p-link { color:var(--green-soft); }
.p-admin .p-link { color:var(--purple-soft); }
.p-card:hover .p-link { gap:13px; }

/* ══════════════ HOW IT WORKS ══════════════ */
.how-grid { display:grid; grid-template-columns:1fr 1fr; gap:60px; align-items:start; margin-top:52px; }
.steps { display:flex; flex-direction:column; gap:4px; }

.step { display:flex; gap:16px; padding:18px; border-radius:var(--r); border:1px solid transparent; transition:var(--t); cursor:default; }
.step:hover { background:rgba(59,130,246,0.05); border-color:rgba(59,130,246,0.12); }

.step-n { width:32px; height:32px; flex-shrink:0; border-radius:50%; background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.24); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:var(--blue-soft); margin-top:2px; }
.step-b h4 { font-size:14.5px; font-weight:600; color:var(--white); margin-bottom:4px; }
.step-b p { font-size:13.5px; color:var(--text-muted); line-height:1.55; }

.feat-list { display:flex; flex-direction:column; gap:14px; }

.feat { background:var(--card); border:1px solid var(--card-border); border-radius:var(--r); padding:18px 20px; display:flex; gap:14px; align-items:flex-start; transition:var(--t); }
.feat:hover { background:rgba(59,130,246,0.06); border-color:rgba(59,130,246,0.2); transform:translateX(4px); }

.feat-ico { width:40px; height:40px; flex-shrink:0; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; }
.ic-blue { background:rgba(59,130,246,0.12); color:var(--blue-pale); }
.ic-green { background:rgba(16,185,129,0.12); color:var(--green-soft); }
.ic-purple { background:rgba(167,139,250,0.12); color:var(--purple-soft); }
.ic-amber { background:rgba(245,158,11,0.12); color:var(--amber-soft); }
.feat-b h4 { font-size:14px; font-weight:600; color:var(--white); margin-bottom:3px; }
.feat-b p { font-size:13px; color:var(--text-muted); line-height:1.55; }

/* ══════════════ ANNOUNCEMENTS ══════════════ */
.ann-section { background:rgba(0,0,0,0.18); border-top:1px solid rgba(59,130,246,0.07); }
.ann-grid { display:grid; grid-template-columns:1fr 320px; gap:52px; align-items:start; }
.ann-list { display:flex; flex-direction:column; margin-top:32px; }

.ann-item { display:flex; align-items:flex-start; gap:14px; padding:16px 0; border-bottom:1px solid rgba(255,255,255,0.05); transition:padding .2s; }
.ann-item:last-child { border-bottom:none; }
.ann-item:hover { padding-left:6px; }

.ann-code { display:inline-block; padding:4px 10px; background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.2); border-radius:6px; font-size:11px; font-weight:700; color:var(--blue-soft); white-space:nowrap; flex-shrink:0; }
.ann-info strong { display:block; font-size:13.5px; font-weight:600; color:var(--white); margin-bottom:3px; }
.ann-info time { font-size:12px; color:var(--text-dim); }
.ann-lvl { font-size:11px; color:var(--text-muted); font-weight:400; }

.no-ann { text-align:center; padding:44px 20px; background:var(--card); border:1px dashed rgba(255,255,255,0.08); border-radius:var(--r); color:var(--text-muted); font-size:14px; margin-top:24px; }
.no-ann i { font-size:26px; display:block; margin-bottom:10px; opacity:.4; }

.ann-select-wrap { margin-top:24px; }
.ann-select-label { font-size:12px; color:var(--text-muted); margin-bottom:8px; display:block; }
.ann-select { width:100%; padding:13px 16px; background:var(--card); border:1px solid rgba(255,255,255,0.1); border-radius:var(--r); color:var(--white); font-size:14px; font-family:inherit; appearance:none; -webkit-appearance:none; cursor:pointer; background-image:url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; background-size:16px; }
.ann-select:focus { outline:none; border-color:var(--blue-soft); }
.ann-select option { background:#0f172a; color:var(--white); }
.ann-detail { display:none; margin-top:16px; padding:16px; background:var(--card); border:1px solid rgba(255,255,255,0.08); border-radius:var(--r); align-items:flex-start; gap:14px; }
.ann-detail.show { display:flex; }
.ann-count { font-size:12px; color:var(--text-dim); margin-top:10px; }

.quick-card { background:var(--card); border:1px solid var(--card-border); border-radius:var(--r-lg); padding:26px; position:sticky; top:86px; }
.quick-card h4 { font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--text-dim); margin-bottom:16px; }

.q-link { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-radius:9px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.05); color:var(--text); font-size:13.5px; font-weight:500; margin-bottom:7px; transition:var(--t); }
.q-link:last-of-type { margin-bottom:0; }
.q-link:hover { background:rgba(59,130,246,0.08); border-color:rgba(59,130,246,0.2); padding-left:17px; color:var(--white); }
.q-left { display:flex; align-items:center; gap:9px; }
.q-left i { font-size:14px; color:var(--text-muted); }
.q-link i.arr { font-size:12px; color:var(--text-dim); }
.q-link:hover i.arr { color:var(--blue-soft); }

.mini-stats { display:grid; grid-template-columns:1fr 1fr; gap:9px; margin-top:18px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.06); }
.mini-stat { border-radius:9px; padding:13px; text-align:center; }
.ms-blue { background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.15); }
.ms-green { background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.15); }
.mini-num { font-size:1.35rem; font-weight:700; line-height:1; margin-bottom:4px; }
.ms-blue .mini-num { color:var(--blue-pale); }
.ms-green .mini-num { color:var(--green-soft); }
.mini-lbl { font-size:11px; color:var(--text-dim); }

/* ══════════════ FOOTER ══════════════ */
footer { background:rgba(0,0,0,0.3); border-top:1px solid rgba(59,130,246,0.1); padding:52px 5% 28px; }
.foot-inner { max-width:1280px; margin:0 auto; }
.foot-top { display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:56px; padding-bottom:36px; border-bottom:1px solid rgba(255,255,255,0.06); margin-bottom:26px; }
.foot-brand p { font-size:13.5px; color:var(--text-muted); line-height:1.7; margin-top:13px; max-width:270px; }
.foot-col h5 { font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--text-dim); margin-bottom:16px; }
.foot-col a { display:block; font-size:13.5px; color:var(--text-muted); margin-bottom:8px; transition:color .2s; }
.foot-col a:hover { color:var(--blue-soft); }
.foot-bottom { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.foot-bottom p { font-size:12.5px; color:var(--text-dim); }
.foot-tags { display:flex; gap:7px; flex-wrap:wrap; }
.f-tag { font-size:11px; padding:3px 11px; border-radius:20px; border:1px solid rgba(255,255,255,0.07); color:var(--text-dim); }

/* ══════════════ DEVELOPER SECTION ══════════════ */
.dev-section { background:rgba(0,0,0,0.22); border-top:1px solid rgba(59,130,246,0.07); }

.dev-grid {
    display:grid; grid-template-columns:1fr 1.35fr; gap:72px; align-items:center;
}

.dev-card {
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(59,130,246,0.18);
    border-radius:24px; padding:38px 36px;
    position:relative; overflow:hidden;
    backdrop-filter:blur(16px);
}

.dev-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,#2563eb,#60a5fa,#a78bfa);
}

.dev-card::after {
    content:''; position:absolute; bottom:-60px; right:-60px;
    width:200px; height:200px; border-radius:50%;
    background:radial-gradient(circle,rgba(59,130,246,0.12),transparent 70%);
    pointer-events:none;
}

.dev-avatar-wrap {
    display:flex; align-items:center; gap:20px; margin-bottom:26px;
}

.dev-avatar {
    width:72px; height:72px; border-radius:50%;
    background:linear-gradient(135deg,#1d4ed8,#7c3aed);
    display:flex; align-items:center; justify-content:center;
    font-size:26px; font-weight:700; color:#fff;
    flex-shrink:0; border:3px solid rgba(59,130,246,0.3);
    box-shadow:0 0 0 6px rgba(59,130,246,0.08);
    position:relative; z-index:1;
    letter-spacing:-1px;
}

.dev-meta { position:relative; z-index:1; }
.dev-meta h3 { font-size:1.25rem; font-weight:700; color:#fff; margin-bottom:4px; letter-spacing:-.3px; }
.dev-meta span { font-size:12.5px; color:var(--blue-pale); font-weight:500; }

.dev-role-badge {
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(167,139,250,0.12); border:1px solid rgba(167,139,250,0.25);
    color:#c4b5fd; font-size:11px; font-weight:600; letter-spacing:.5px;
    padding:4px 12px; border-radius:20px; margin-bottom:18px;
    position:relative; z-index:1;
}

.dev-bio {
    font-size:14px; color:var(--text-muted); line-height:1.75;
    margin-bottom:24px; position:relative; z-index:1;
}

.dev-tags { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:26px; position:relative; z-index:1; }
.dev-tag {
    font-size:11px; font-weight:600; padding:4px 12px;
    border-radius:20px; letter-spacing:.3px;
}
.tag-php   { background:rgba(59,130,246,0.1);  border:1px solid rgba(59,130,246,0.22);  color:var(--blue-pale); }
.tag-mysql { background:rgba(16,185,129,0.1);  border:1px solid rgba(16,185,129,0.22);  color:var(--green-soft); }
.tag-js    { background:rgba(245,158,11,0.1);  border:1px solid rgba(245,158,11,0.22);  color:var(--amber-soft); }
.tag-html  { background:rgba(239,68,68,0.1);   border:1px solid rgba(239,68,68,0.22);   color:#fca5a5; }
.tag-css   { background:rgba(167,139,250,0.1); border:1px solid rgba(167,139,250,0.22); color:var(--purple-soft); }
.tag-ai    { background:rgba(20,184,166,0.1);  border:1px solid rgba(20,184,166,0.22);  color:#5eead4; }

.dev-info-list {
    display:flex; flex-direction:column; gap:10px;
    position:relative; z-index:1;
    padding-top:20px; border-top:1px solid rgba(255,255,255,0.06);
}

.dev-info-row {
    display:flex; align-items:center; gap:10px;
    font-size:13px; color:var(--text-muted);
}
.dev-info-row i { width:16px; color:var(--blue-soft); font-size:13px; }
.dev-info-row strong { color:var(--text); font-weight:500; }

/* right side - project info */
.dev-right {}
.dev-right .sec-tag { margin-bottom:10px; }
.dev-right .sec-title { margin-bottom:14px; }
.dev-right .sec-desc { margin-bottom:36px; }

.dev-highlights { display:flex; flex-direction:column; gap:12px; }

.dev-hl {
    display:flex; gap:14px; align-items:flex-start;
    background:var(--card); border:1px solid var(--card-border);
    border-radius:12px; padding:16px 18px; transition:var(--t);
}
.dev-hl:hover { background:rgba(59,130,246,0.06); border-color:rgba(59,130,246,0.2); transform:translateX(5px); }

.hl-ico { width:38px; height:38px; flex-shrink:0; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:14px; }
.hl-b h4 { font-size:14px; font-weight:600; color:var(--white); margin-bottom:3px; }
.hl-b p  { font-size:13px; color:var(--text-muted); line-height:1.5; }

/* ══════════════ DEVELOPER SECTION ══════════════ */
.dev-section {
    background: var(--bg-card);
    border-top: 1px solid var(--card-border);
}

.dev-layout {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 56px;
    align-items: flex-start;
}

/* Left — photo + badge */
.dev-left { display: flex; flex-direction: column; align-items: center; gap: 16px; }

.dev-photo-wrap {
    width: 180px; height: 180px; border-radius: 50%;
    overflow: hidden;
    border: 4px solid rgba(59,130,246,0.4);
    box-shadow: 0 0 0 8px rgba(59,130,246,0.08);
    flex-shrink: 0;
}

.dev-photo-wrap img {
    width: 100%; height: 100%;
    object-fit: cover;
    object-position: center 20%;
    display: block;
}

.dev-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(59,130,246,0.12);
    border: 1px solid rgba(59,130,246,0.3);
    color: var(--blue-pale); font-size: 12px; font-weight: 600;
    padding: 6px 16px; border-radius: 20px;
    white-space: nowrap;
}

/* Right — all details */
.dev-right-col {}

.dev-eyebrow {
    font-size: 11px; font-weight: 700; letter-spacing: 2px;
    text-transform: uppercase; color: var(--blue-soft); margin-bottom: 8px;
    display: block;
}

.dev-name {
    font-size: clamp(1.8rem, 3vw, 2.6rem);
    font-weight: 700; color: #ffffff;
    letter-spacing: -0.5px; margin-bottom: 22px;
    line-height: 1.1;
}

/* Info cards row */
.dev-info-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 24px;
}

.dev-info-card {
    background: var(--card);
    border: 1px solid var(--card-border);
    border-radius: 10px; padding: 14px 16px;
}

.dev-info-card .label {
    font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase; color: var(--text-muted);
    display: block; margin-bottom: 6px;
}

.dev-info-card .value {
    font-size: 13.5px; font-weight: 600; color: #ffffff;
    line-height: 1.35;
}

/* Bio */
.dev-bio-text {
    font-size: 14.5px; color: var(--text-muted);
    line-height: 1.78; margin-bottom: 28px;
    max-width: 680px;
}

/* Contact buttons */
.dev-contacts { display: flex; gap: 10px; flex-wrap: wrap; }

.dev-contact-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--card);
    border: 1px solid var(--card-border);
    color: var(--text-muted); font-size: 13px; font-weight: 500;
    padding: 9px 18px; border-radius: 8px;
    transition: var(--t); text-decoration: none;
}

.dev-contact-btn:hover {
    background: rgba(59,130,246,0.1);
    border-color: rgba(59,130,246,0.3);
    color: var(--blue-pale);
}

.dev-contact-btn i { font-size: 13px; }

.dev-username {
    font-size: 13px; font-weight: 600; color: var(--blue-soft);
    letter-spacing: .5px;
}

/* Tech stack tags */
.dev-tags { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 28px; }
.dev-tag {
    font-size: 11px; font-weight: 600; padding: 4px 12px;
    border-radius: 20px; letter-spacing: .3px;
}
.tag-php   { background:rgba(59,130,246,0.1);  border:1px solid rgba(59,130,246,0.22);  color:var(--blue-pale); }
.tag-mysql { background:rgba(16,185,129,0.1);  border:1px solid rgba(16,185,129,0.22);  color:var(--green-soft); }
.tag-js    { background:rgba(245,158,11,0.1);  border:1px solid rgba(245,158,11,0.22);  color:var(--amber-soft); }
.tag-html  { background:rgba(239,68,68,0.1);   border:1px solid rgba(239,68,68,0.22);   color:#fca5a5; }
.tag-css   { background:rgba(167,139,250,0.1); border:1px solid rgba(167,139,250,0.22); color:var(--purple-soft); }
.tag-ai    { background:rgba(20,184,166,0.1);  border:1px solid rgba(20,184,166,0.22);  color:#5eead4; }

/* Project highlights */
.dev-highlights { display: flex; flex-direction: column; gap: 10px; margin-top: 28px; }
.dev-hl {
    display: flex; gap: 14px; align-items: flex-start;
    background: var(--card); border: 1px solid var(--card-border);
    border-radius: 12px; padding: 14px 16px; transition: var(--t);
}
.dev-hl:hover { background:rgba(59,130,246,0.06); border-color:rgba(59,130,246,0.2); transform:translateX(4px); }
.hl-ico { width:36px; height:36px; flex-shrink:0; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:14px; }
.hl-b h4 { font-size:13.5px; font-weight:600; color:var(--white); margin-bottom:3px; }
.hl-b p  { font-size:13px; color:var(--text-muted); line-height:1.5; margin:0; }

/* ══════════════ ANIMATIONS ══════════════ */
@keyframes fadeUp { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
.eyebrow { animation:fadeUp .5s ease both; }
.hero h1 { animation:fadeUp .6s ease .08s both; }
.hero-sub { animation:fadeUp .6s ease .16s both; }
.hero-btns { animation:fadeUp .6s ease .22s both; }
.trust { animation:fadeUp .6s ease .28s both; }
.stats-card { animation:fadeUp .7s ease .14s both; }

/* ══════════════ RESPONSIVE ══════════════ */
@media (max-width:1100px) {
    .hero-inner { grid-template-columns:1fr; gap:46px; }
    .stats-card { max-width:500px; }
    .portals-grid { grid-template-columns:1fr; }
    .how-grid { grid-template-columns:1fr; }
    .ann-grid { grid-template-columns:1fr; }
    .foot-top { grid-template-columns:1fr 1fr; }
    .dev-grid { grid-template-columns:1fr; gap:40px; }
    .dev-layout { grid-template-columns:1fr; gap:32px; }
    .dev-info-cards { grid-template-columns:1fr 1fr; }
}

@media (max-width:768px) {
    .dev-info-cards { grid-template-columns:1fr 1fr; }
    .dev-left { flex-direction:row; align-items:center; justify-content:flex-start; }
}

@media (max-width:768px) {
    header,footer { padding:0 4%; }
    section { padding:60px 4%; }
    .hero { padding:96px 4% 56px; }
    .hero h1 { font-size:2.1rem; letter-spacing:-1px; }
    .session-pill { display:none; }
    .nav-links a:not(.nav-cta) { display:none; }
    .portals-head { flex-direction:column; }
    .foot-top { grid-template-columns:1fr; gap:28px; }
    .foot-bottom { flex-direction:column; align-items:flex-start; }
}
</style>
</head>
<body>
<div class="bg"></div>

<header>
    <div class="nav-wrap">
        <div class="logo">
            <div class="logo-icon"><img src="assets/images/faculty-logo.png" alt="Faculty of Computing"></div>
            <div class="logo-text"><strong>CS CA Portal</strong><span>Prince Abubakar Audu University, Anyigba</span></div>
        </div>
        <div class="nav-links">
            <div class="session-pill"><span class="pill-dot"></span> <?= htmlspecialchars($session) ?> · <?= htmlspecialchars($semester) ?></div>
            <a href="#">Home</a>
            <a href="#portals">Portals</a>
            <a href="#notices">Notices</a>
            <a href="#developer">Developer</a>
            <a href="student-login.php" class="nav-cta"><i class="fas fa-sign-in-alt" style="font-size:12px"></i> Login</a>
        </div>
    </div>
</header>

<main>

<section class="hero">
    <div class="hero-inner">
        <div>
            <div class="eyebrow"><i class="fas fa-circle" style="font-size:7px"></i> <?= htmlspecialchars($session) ?> · <?= htmlspecialchars($semester) ?></div>
            <h1>Computer Science<br><span class="grad">Continuous Assessment</span><br>Portal</h1>
            <p class="hero-sub">A secure, web-based platform for managing CA tests across all levels of the CS Department at PAAU Anyigba — built for students, lecturers and administrators.</p>
            <div class="hero-btns">
                <a href="student-login.php" class="btn btn-primary"><i class="fas fa-user-graduate"></i> Student Login</a>
                <a href="lecturer/" class="btn btn-outline"><i class="fas fa-chalkboard-teacher"></i> Lecturer Portal</a>
                <a href="admin/" class="btn btn-outline"><i class="fas fa-user-shield"></i> Admin</a>
            </div>
            <div class="trust">
                <div class="trust-item"><i class="fas fa-lock"></i> Secure Access</div>
                <div class="trust-item"><i class="fas fa-clock"></i> 24/7 Available</div>
                <div class="trust-item"><i class="fas fa-camera"></i> Face Verified</div>
                <div class="trust-item"><i class="fas fa-bolt"></i> Instant Results</div>
            </div>
        </div>
        <div class="stats-card">
            <div class="card-head"><h3>System Overview</h3><div class="live-badge"><span class="dot"></span> Live</div></div>
            <div class="stats-grid">
                <div class="stat-box"><div class="stat-icon"><i class="fas fa-user-graduate"></i></div><div class="stat-num"><?= number_format($totalStudents) ?></div><div class="stat-lbl">Students</div></div>
                <div class="stat-box"><div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-num"><?= number_format($totalLecturers) ?></div><div class="stat-lbl">Lecturers</div></div>
                <div class="stat-box"><div class="stat-icon"><i class="fas fa-file-alt"></i></div><div class="stat-num"><?= number_format($totalTests) ?></div><div class="stat-lbl">Active Tests</div></div>
                <div class="stat-box"><div class="stat-icon"><i class="fas fa-check-double"></i></div><div class="stat-num"><?= number_format($totalSubmissions) ?></div><div class="stat-lbl">Submissions</div></div>
            </div>
            <div class="ses-strip"><div class="ses-strip-inner"><p>Current Academic Period</p><strong><?= htmlspecialchars($session) ?> — <?= htmlspecialchars($semester) ?></strong></div><i class="fas fa-calendar-alt"></i></div>
        </div>
    </div>
</section>

<div class="divider"></div>
<section class="portals-section" id="portals">
    <div class="sec-wrap">
        <div class="portals-head"><div><div class="sec-tag">Access Portals</div><h2 class="sec-title">Choose Your Portal</h2></div><p class="sec-desc">Each portal is purpose-built for the specific role of every stakeholder in the department.</p></div>
        <div class="portals-grid">
            <div class="p-card p-student" onclick="location.href='student-login.php'"><div class="p-icon"><i class="fas fa-user-graduate"></i></div><h3>Student Portal</h3><p>Log in with your matric number, complete <strong style="color:rgba(255,255,255,0.9)">face verification</strong>, and access your active test. Scores are shown immediately after submission.</p><div style="display:inline-flex;align-items:center;gap:6px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.25);border-radius:6px;padding:4px 10px;font-size:11px;color:#34d399;margin-bottom:16px;"><i class="fas fa-shield-alt"></i> Face ID Required</div><br><span class="p-link">Access Portal <i class="fas fa-arrow-right"></i></span></div>
            <div class="p-card p-lecturer" onclick="location.href='lecturer/'"><div class="p-icon"><i class="fas fa-chalkboard-teacher"></i></div><h3>Lecturer Portal</h3><p>Create and activate CA tests, manage your question bank, monitor live submissions and export results for all assigned courses.</p><span class="p-link">Access Portal <i class="fas fa-arrow-right"></i></span></div>
            <div class="p-card p-admin" onclick="location.href='admin/'"><div class="p-icon"><i class="fas fa-user-shield"></i></div><h3>Admin Portal</h3><p>Full system control — manage students, lecturers, sessions, course assignments, audit logs, face enrollment and reports.</p><span class="p-link">Access Portal <i class="fas fa-arrow-right"></i></span></div>
        </div>
    </div>
</section>

<div class="divider"></div>
<section>
    <div class="sec-wrap">
        <div class="sec-tag">How It Works</div><h2 class="sec-title">Built for Academic Integrity</h2>
        <div class="how-grid">
            <div>
                <p style="font-size:15px;color:var(--text-muted);line-height:1.7;margin-bottom:28px;">From test creation to final score — every step is automated, time-stamped, and tamper-proof.</p>
                <div class="steps">
                    <div class="step"><div class="step-n">01</div><div class="step-b"><h4>Lecturer Creates Assessment</h4><p>Set course, level, questions, time limit and activation window. Publish when ready.</p></div></div>
                    <div class="step"><div class="step-n">02</div><div class="step-b"><h4>Student Authenticates via Face ID</h4><p>Enter your matric number, then pass live face verification. Unrecognised faces are blocked — only enrolled students gain access.</p></div></div>
                    <div class="step"><div class="step-n">03</div><div class="step-b"><h4>Timed Test Begins</h4><p>Countdown timer runs live. System auto-submits at deadline — no late entries.</p></div></div>
                    <div class="step"><div class="step-n">04</div><div class="step-b"><h4>Results Available Instantly</h4><p>Students see scores immediately. Lecturers and admins access live analytics and exports.</p></div></div>
                </div>
            </div>
            <div class="feat-list">
                <div class="feat"><div class="feat-ico ic-blue"><i class="fas fa-random"></i></div><div class="feat-b"><h4>Randomised Question Sets</h4><p>Each student gets a unique question order to minimise answer sharing.</p></div></div>
                <div class="feat"><div class="feat-ico ic-green"><i class="fas fa-chart-bar"></i></div><div class="feat-b"><h4>Real-Time Analytics</h4><p>Track live submission counts, score distributions and class performance.</p></div></div>
                <div class="feat"><div class="feat-ico ic-purple"><i class="fas fa-layer-group"></i></div><div class="feat-b"><h4>Multi-Level Support</h4><p>Full support for 100–400 level courses across both semesters.</p></div></div>
                <div class="feat"><div class="feat-ico ic-amber"><i class="fas fa-database"></i></div><div class="feat-b"><h4>Centralised Records</h4><p>All assessment data secured — retrievable anytime for accreditation.</p></div></div>
            </div>
        </div>
    </div>
</section>

<div class="divider"></div>
<section class="ann-section" id="notices">
    <div class="sec-wrap">
        <div class="ann-grid">
            <div>
                <div class="sec-tag">Notices</div><h2 class="sec-title">Active Assessments</h2>
                <p class="sec-desc" style="margin-top:10px;">Tests currently open or recently published by lecturers. Log in promptly — access closes at the set deadline.</p>
                <a href="student-login.php" class="btn btn-primary" style="margin-top:28px;font-size:14px;padding:12px 22px;"><i class="fas fa-sign-in-alt"></i> Student Login</a>
                <div class="ann-list">
                    <?php if (!empty($announcements)): ?>
                        <div class="ann-select-wrap">
                            <label class="ann-select-label" for="annSelect">Choose a course to view its active test</label>
                            <select id="annSelect" class="ann-select" onchange="showAnnDetail(this.value)">
                                <option value="">— Select a course —</option>
                                <?php foreach ($announcements as $i => $a): ?>
                                <option value="<?= $i ?>"><?= htmlspecialchars($a['course_code']) ?> — <?= htmlspecialchars($a['test_title']) ?> (Level <?= htmlspecialchars($a['level']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="ann-count"><?= count($announcements) ?> active assessment<?= count($announcements) === 1 ? '' : 's' ?> right now.</div>

                            <?php foreach ($announcements as $i => $a): ?>
                            <div class="ann-detail" id="annDetail<?= $i ?>">
                                <span class="ann-code"><?= htmlspecialchars($a['course_code']) ?></span>
                                <div class="ann-info"><strong><?= htmlspecialchars($a['test_title']) ?> <span class="ann-lvl">— Level <?= htmlspecialchars($a['level']) ?></span></strong><time><?= date('D, d M Y', strtotime($a['created_at'])) ?></time></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <script>
                        function showAnnDetail(idx) {
                            document.querySelectorAll('.ann-detail').forEach(function(el){ el.classList.remove('show'); });
                            if (idx !== '') {
                                var el = document.getElementById('annDetail' + idx);
                                if (el) el.classList.add('show');
                            }
                        }
                        </script>
                    <?php else: ?>
                        <div class="no-ann"><i class="fas fa-bell-slash"></i> No active assessments at this time.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="quick-card">
                <h4>Quick Access</h4>
                <a href="student-login.php" class="q-link"><div class="q-left"><i class="fas fa-user-graduate"></i> Student Login</div><i class="fas fa-chevron-right arr"></i></a>
                <a href="lecturer/" class="q-link"><div class="q-left"><i class="fas fa-chalkboard-teacher"></i> Lecturer Dashboard</div><i class="fas fa-chevron-right arr"></i></a>
                <a href="admin/" class="q-link"><div class="q-left"><i class="fas fa-user-shield"></i> Admin Panel</div><i class="fas fa-chevron-right arr"></i></a>
                <a href="admin/dashboard.php" class="q-link"><div class="q-left"><i class="fas fa-chart-line"></i> View Analytics</div><i class="fas fa-chevron-right arr"></i></a>
                <div class="mini-stats">
                    <div class="mini-stat ms-blue"><div class="mini-num"><?= number_format($totalStudents) ?></div><div class="mini-lbl">Students</div></div>
                    <div class="mini-stat ms-green"><div class="mini-num"><?= number_format($totalTests) ?></div><div class="mini-lbl">Active Tests</div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="divider"></div>
<section class="dev-section" id="developer">
    <div class="sec-wrap">
        <div class="dev-layout">

            <!-- Left: Photo + badge -->
            <div class="dev-left">
                <div class="dev-photo-wrap">
                    <img src="uploads/passports/developer.jpg" alt="Oshadami Victor Odunayo"
                         onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=Oshadami+Victor&background=0a1628&color=fbbf24&size=300&bold=true';">
                </div>
                <div class="dev-badge"><i class="fas fa-code"></i> Project Developer</div>
                <div class="dev-username">@ vikkyfresh</div>
            </div>

            <!-- Right: All info -->
            <div class="dev-right-col">
                <span class="dev-eyebrow">About the Developer</span>
                <h2 class="dev-name">Oshadami Victor Odunayo</h2>

                <!-- Info cards -->
                <div class="dev-info-cards">
                    <div class="dev-info-card">
                        <span class="label">Department</span>
                        <span class="value">Computer Science</span>
                    </div>
                    <div class="dev-info-card">
                        <span class="label">Faculty</span>
                        <span class="value">Faculty of Computing</span>
                    </div>
                    <div class="dev-info-card">
                        <span class="label">Institution</span>
                        <span class="value">Prince Abubakar Audu University</span>
                    </div>
                    <div class="dev-info-card">
                        <span class="label">Project</span>
                        <span class="value">CSC 400 — 2025/2026</span>
                    </div>
                </div>

                <!-- Bio -->
                <p class="dev-bio-text">
                    The CS CA Portal was designed and developed as a course project for <strong style="color:rgba(255,255,255,0.88)">CSC 400 — Project</strong> 
                    at the Department of Computer Science, Prince Abubakar Audu University, Anyigba. 
                    The system was built to address the challenges of manual CA administration — providing a secure, 
                    face-verified, and fully automated platform for students, lecturers, and administrators. 
                    It reflects a commitment to academic integrity, digital innovation, and practical software engineering at PAAU.
                </p>

                <!-- Contact buttons -->
                <div class="dev-contacts">
                    <a href="mailto:chinadindu2003@gmail.com" class="dev-contact-btn">
                        <i class="fas fa-envelope"></i> chinadindu2003@gmail.com
                    </a>
                    <a href="tel:+2348167228389" class="dev-contact-btn">
                        <i class="fas fa-phone"></i> +234 816 722 8389
                    </a>
                </div>

                <!-- Tech stack -->
                <div class="dev-tags" style="margin-top:22px;">
                    <span class="dev-tag tag-php">PHP</span>
                    <span class="dev-tag tag-mysql">MySQL</span>
                    <span class="dev-tag tag-js">JavaScript</span>
                    <span class="dev-tag tag-html">HTML5</span>
                    <span class="dev-tag tag-css">CSS3</span>
                    <span class="dev-tag tag-ai">Face API.js</span>
                </div>

                <!-- Project highlights -->
                <div class="dev-highlights">
                    <div class="dev-hl">
                        <div class="hl-ico ic-blue"><i class="fas fa-brain"></i></div>
                        <div class="hl-b">
                            <h4>AI-Powered Face Verification</h4>
                            <p>Real-time facial recognition using Face API.js — students are verified live before each test begins.</p>
                        </div>
                    </div>
                    <div class="dev-hl">
                        <div class="hl-ico ic-green"><i class="fas fa-shield-alt"></i></div>
                        <div class="hl-b">
                            <h4>Role-Based Access Control</h4>
                            <p>Three independent portals — student, lecturer, admin — each with dedicated authentication and permission layers.</p>
                        </div>
                    </div>
                    <div class="dev-hl">
                        <div class="hl-ico ic-purple"><i class="fas fa-link"></i></div>
                        <div class="hl-b">
                            <h4>Custom Restricted Test Links</h4>
                            <p>Lecturers can generate token-based links that only pre-selected students can access — preventing unauthorised entry.</p>
                        </div>
                    </div>
                    <div class="dev-hl">
                        <div class="hl-ico ic-amber"><i class="fas fa-chart-bar"></i></div>
                        <div class="hl-b">
                            <h4>Live Analytics &amp; Reporting</h4>
                            <p>Real-time score tracking, submission counts, performance distributions — all accessible to lecturers and admins.</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>


<footer>
    <div class="foot-inner">
        <div class="foot-top">
            <div class="foot-brand"><div class="logo"><div class="logo-icon"><i class="fas fa-graduation-cap"></i></div><div class="logo-text"><strong>CS CA Portal</strong><span>PAAU Anyigba</span></div></div><p>A web-based continuous assessment management system for the Department of Computer Science — transparent, secure, and always available.</p></div>
            <div class="foot-col"><h5>Portals</h5><a href="student-login.php">Student Login</a><a href="lecturer/">Lecturer Portal</a><a href="admin/">Admin Panel</a><a href="admin/dashboard.php">Dashboard</a></div>
            <div class="foot-col"><h5>Institution</h5><a href="#">Computer Science Dept.</a><a href="#">Prince Abubakar Audu University</a><a href="#">Anyigba, Kogi State</a><a href="#">Contact Support</a></div>
        </div>
        <div class="foot-bottom"><p>&copy; <?= date('Y') ?> Department of Computer Science, PAAU Anyigba. Developed by <strong style="color:var(--blue-pale)">Oshadami Victor Odunayo</strong> (23CS1039) — CSC 402 Project.</p><div class="foot-tags"><span class="f-tag">PHP &amp; MySQL</span><span class="f-tag">SSL Secured</span><span class="f-tag">24/7 Uptime</span></div></div>
    </div>
</footer>

</body>
</html>