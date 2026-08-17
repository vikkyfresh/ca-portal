<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
require_once '../includes/config.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$photoSrc = 'https://ui-avatars.com/api/?name=' . urlencode($adminName) . '&background=1e3a8a&color=fff&size=80&bold=true';
if (!empty($_SESSION['admin_id'])) {
    $_spStmt = $pdo->prepare('SELECT photo FROM admins WHERE id = ? LIMIT 1');
    $_spStmt->execute([$_SESSION['admin_id']]);
    $_spRow = $_spStmt->fetch();
    if (!empty($_spRow['photo'])) {
        $_sp = dirname(__DIR__) . '/' . ltrim($_spRow['photo'], '/');
        if (file_exists($_sp)) $photoSrc = '../' . ltrim($_spRow['photo'], '/');
    }
}

// Does the audit_logs table exist yet? (patch-4-audit-logs.sql)
$auditTableReady = true;
try { $pdo->query("SELECT 1 FROM audit_logs LIMIT 1"); } catch (Exception $e) { $auditTableReady = false; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Audit Logs - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="../assets/js/ui-notify.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
        body { background:#f1f5f9; display:flex; min-height:100vh; }
        .main { flex:1; margin-left:260px; }
        .topbar { background:#fff; padding:16px 28px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .topbar h1 { font-size:1.4rem; color:#0f172a; font-weight:800; }
        .topbar p { font-size:.82rem; color:#64748b; margin-top:2px; }
        .content { padding:24px 28px 60px; }

        .notice { background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:14px 18px; border-radius:12px; font-size:.85rem; margin-bottom:20px; display:flex; gap:10px; align-items:flex-start; }

        .card { background:#fff; border-radius:16px; padding:22px 24px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:20px; }
        .card h2 { font-size:1rem; color:#0f172a; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
        .card h2 i { color:#1e3a8a; }

        .filter-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:14px; }
        .filter-grid label { display:block; font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.03em; margin-bottom:5px; }
        .filter-grid input, .filter-grid select { width:100%; padding:9px 11px; border:1.5px solid #e2e8f0; border-radius:9px; font-size:.85rem; color:#0f172a; }
        .filter-grid input:focus, .filter-grid select:focus { outline:none; border-color:#1e3a8a; }

        .btn { padding:9px 18px; border-radius:9px; border:none; font-size:.83rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:opacity .15s; }
        .btn:hover { opacity:.88; }
        .btn-primary { background:linear-gradient(to right,#0f172a,#1e3a8a); color:#fff; }
        .btn-outline { background:#f1f5f9; color:#334155; }
        .btn-pdf { background:#fee2e2; color:#991b1b; }
        .btn-excel { background:#dcfce7; color:#166534; }
        .btn:disabled { opacity:.5; cursor:not-allowed; }

        .action-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

        table { width:100%; border-collapse:collapse; }
        th, td { padding:11px 13px; border-bottom:1px solid #f1f5f9; font-size:.82rem; text-align:left; vertical-align:top; }
        th { background:#f8fafc; color:#64748b; font-weight:700; font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; position:sticky; top:0; }
        tbody tr:hover { background:#f8fafc; }

        .badge { padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:700; white-space:nowrap; display:inline-block; }
        .badge-student { background:#dbeafe; color:#1e40af; }
        .badge-lecturer { background:#fef3c7; color:#92400e; }
        .badge-admin { background:#ede9fe; color:#5b21b6; }
        .badge-system { background:#f1f5f9; color:#475569; }

        .event-pill { font-size:.72rem; font-weight:700; color:#0f172a; background:#f1f5f9; padding:3px 9px; border-radius:6px; white-space:nowrap; }

        .pagination { display:flex; justify-content:space-between; align-items:center; margin-top:16px; flex-wrap:wrap; gap:10px; }
        .pagination .info { font-size:.8rem; color:#64748b; }
        .pagination .pages { display:flex; gap:6px; }
        .pagination button { padding:7px 13px; border-radius:8px; border:1.5px solid #e2e8f0; background:#fff; cursor:pointer; font-size:.8rem; font-weight:600; color:#334155; }
        .pagination button:disabled { opacity:.4; cursor:not-allowed; }
        .pagination button.active { background:#1e3a8a; color:#fff; border-color:#1e3a8a; }

        .empty-state { text-align:center; padding:50px 20px; color:#94a3b8; }
        .empty-state i { font-size:2.2rem; margin-bottom:10px; display:block; }

        #printableExport { position:fixed; left:-9999px; top:0; width:1400px; background:#fff; padding:24px; }
        #printableExport h2 { margin-bottom:4px; }
        #printableExport p { color:#64748b; font-size:.85rem; margin-bottom:16px; }
        #printableExport table { width:100%; border-collapse:collapse; font-size:11px; }
        #printableExport th, #printableExport td { border:1px solid #cbd5e1; padding:6px 8px; text-align:left; }
        #printableExport th { background:#1e3a8a; color:#fff; }

        @media(max-width:768px){ .main{margin-left:0} }
    </style>
</head>
<body>
<?php $activePage='audit-logs'; require_once __DIR__.'/includes/sidebar.php'; ?>
<main class="main">
    <div class="topbar">
        <div>
            <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')" style="display:none"><i class="fas fa-bars"></i></button>
            <h1><i class="fas fa-clipboard-list" style="color:#1e3a8a;margin-right:8px"></i>Audit Logs</h1>
            <p>Every login, test event, enrollment, and portal-control change across the system.</p>
        </div>
    </div>

    <div class="content">
        <?php if (!$auditTableReady): ?>
        <div class="notice">
            <i class="fas fa-triangle-exclamation" style="margin-top:2px"></i>
            <div>The audit log table hasn't been created yet. Run <strong>patch-4-audit-logs.sql</strong> on your database, then refresh this page. New activity will start appearing automatically — nothing before the migration is retroactively logged.</div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card">
            <h2><i class="fas fa-filter"></i> Filter</h2>
            <div class="filter-grid">
                <div>
                    <label>Start Date</label>
                    <input type="date" id="fStart">
                </div>
                <div>
                    <label>End Date</label>
                    <input type="date" id="fEnd">
                </div>
                <div>
                    <label>Event Type</label>
                    <select id="fEventType">
                        <option value="all">All Events</option>
                        <option value="student_login">Student Login</option>
                        <option value="student_logout">Student Logout</option>
                        <option value="lecturer_login">Lecturer Login</option>
                        <option value="admin_login">Admin Login</option>
                        <option value="test_created">Test Created</option>
                        <option value="test_completed">Test Completed</option>
                        <option value="face_enrolled">Face Enrolled</option>
                        <option value="retake_approved">Retake Approved</option>
                        <option value="portal_setting_changed">Portal Setting Changed</option>
                    </select>
                </div>
                <div>
                    <label>Actor Type</label>
                    <select id="fActorType">
                        <option value="all">All</option>
                        <option value="student">Student</option>
                        <option value="lecturer">Lecturer</option>
                        <option value="admin">Admin</option>
                        <option value="system">System</option>
                    </select>
                </div>
                <div>
                    <label>Search</label>
                    <input type="text" id="fSearch" placeholder="Name, matric, description…">
                </div>
            </div>
            <div class="action-row">
                <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-magnifying-glass"></i> Apply Filters</button>
                <button class="btn btn-outline" onclick="resetFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
                <div style="flex:1"></div>
                <button class="btn btn-pdf" onclick="exportLog('pdf')" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                <button class="btn btn-excel" onclick="exportLog('csv')" id="exportCsvBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
            </div>
        </div>

        <!-- Table -->
        <div class="card">
            <h2><i class="fas fa-list"></i> Activity <span id="resultCount" style="color:#94a3b8;font-weight:500"></span></h2>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr><th style="width:150px">Date / Time</th><th>Event</th><th>Actor</th><th>Description</th></tr>
                    </thead>
                    <tbody id="logBody">
                        <tr><td colspan="4" class="empty-state"><i class="fas fa-spinner fa-spin"></i>Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="paginationWrap" style="display:none">
                <div class="info" id="pageInfo"></div>
                <div class="pages" id="pageButtons"></div>
            </div>
        </div>
    </div>
</main>

<!-- Hidden printable table used only to generate the PDF export -->
<div id="printableExport">
    <h2>CA Portal — Audit Log Export</h2>
    <p id="printMeta"></p>
    <table id="printTable">
        <thead><tr><th>Date/Time</th><th>Event</th><th>Actor Type</th><th>Actor ID</th><th>Actor Name</th><th>Description</th><th>IP Address</th></tr></thead>
        <tbody id="printBody"></tbody>
    </table>
</div>

<script>
const EVENT_LABELS = {
    student_login: 'Student Login', student_logout: 'Student Logout',
    lecturer_login: 'Lecturer Login', admin_login: 'Admin Login',
    test_created: 'Test Created', test_completed: 'Test Completed',
    face_enrolled: 'Face Enrolled', retake_approved: 'Retake Approved',
    portal_setting_changed: 'Portal Setting Changed'
};

let currentPage = 1;

function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function buildParams(extra) {
    const p = new URLSearchParams();
    const start = document.getElementById('fStart').value;
    const end = document.getElementById('fEnd').value;
    const eventType = document.getElementById('fEventType').value;
    const actorType = document.getElementById('fActorType').value;
    const search = document.getElementById('fSearch').value.trim();
    if (start) p.set('start', start);
    if (end) p.set('end', end);
    if (eventType) p.set('event_type', eventType);
    if (actorType) p.set('actor_type', actorType);
    if (search) p.set('search', search);
    Object.entries(extra || {}).forEach(([k,v]) => p.set(k, v));
    return p;
}

function applyFilters() { currentPage = 1; loadLogs(); }

function resetFilters() {
    document.getElementById('fStart').value = '';
    document.getElementById('fEnd').value = '';
    document.getElementById('fEventType').value = 'all';
    document.getElementById('fActorType').value = 'all';
    document.getElementById('fSearch').value = '';
    currentPage = 1;
    loadLogs();
}

function actorBadge(type) {
    const cls = { student:'badge-student', lecturer:'badge-lecturer', admin:'badge-admin', system:'badge-system' }[type] || 'badge-system';
    return `<span class="badge ${cls}">${esc(type)}</span>`;
}

function loadLogs() {
    const tbody = document.getElementById('logBody');
    tbody.innerHTML = '<tr><td colspan="4" class="empty-state"><i class="fas fa-spinner fa-spin"></i>Loading…</td></tr>';

    const params = buildParams({ page: currentPage, per_page: 25 });
    fetch('api/audit-log-data.php?' + params.toString())
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                tbody.innerHTML = `<tr><td colspan="4" class="empty-state"><i class="fas fa-triangle-exclamation"></i>${esc(d.message || 'Failed to load')}</td></tr>`;
                document.getElementById('resultCount').textContent = '';
                document.getElementById('paginationWrap').style.display = 'none';
                return;
            }
            document.getElementById('resultCount').textContent = `(${d.total} total)`;
            if (!d.rows.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i>No matching activity in this range.</td></tr>';
            } else {
                tbody.innerHTML = d.rows.map(r => `
                    <tr>
                        <td>${esc(new Date(r.created_at.replace(' ','T')).toLocaleString())}</td>
                        <td><span class="event-pill">${esc(EVENT_LABELS[r.event_type] || r.event_type)}</span></td>
                        <td>${actorBadge(r.actor_type)}<br><span style="font-size:.78rem;color:#334155">${esc(r.actor_name || r.actor_id || '—')}</span></td>
                        <td>${esc(r.description)}</td>
                    </tr>
                `).join('');
            }
            renderPagination(d);
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="4" class="empty-state"><i class="fas fa-triangle-exclamation"></i>Server error loading audit log.</td></tr>';
        });
}

function renderPagination(d) {
    const wrap = document.getElementById('paginationWrap');
    if (d.pages <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    document.getElementById('pageInfo').textContent = `Page ${d.page} of ${d.pages}`;
    const btnWrap = document.getElementById('pageButtons');
    let html = `<button ${d.page<=1?'disabled':''} onclick="currentPage=${d.page-1};loadLogs()"><i class="fas fa-chevron-left"></i></button>`;
    const startP = Math.max(1, d.page-2), endP = Math.min(d.pages, d.page+2);
    for (let i = startP; i <= endP; i++) {
        html += `<button class="${i===d.page?'active':''}" onclick="currentPage=${i};loadLogs()">${i}</button>`;
    }
    html += `<button ${d.page>=d.pages?'disabled':''} onclick="currentPage=${d.page+1};loadLogs()"><i class="fas fa-chevron-right"></i></button>`;
    btnWrap.innerHTML = html;
}

async function exportLog(format) {
    const start = document.getElementById('fStart').value || 'the earliest record';
    const end = document.getElementById('fEnd').value || 'the latest record';
    const label = format === 'pdf' ? 'PDF' : 'Excel (CSV)';

    const ok = await confirmDialog(
        `This will export audit log activity from <strong>${esc(start)}</strong> to <strong>${esc(end)}</strong> as a ${label} file. Continue?`,
        { title: 'Confirm Export', confirmText: `Yes, Export ${label}`, cancelText: 'Cancel' }
    );
    if (!ok) return;

    const btn = document.getElementById(format === 'pdf' ? 'exportPdfBtn' : 'exportCsvBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing…';

    try {
        if (format === 'csv') {
            const params = buildParams({ format: 'csv' });
            window.location.href = 'api/audit-log-export.php?' + params.toString();
            notify('Excel (CSV) export started — check your downloads.', 'success');
        } else {
            const params = buildParams({ format: 'json' });
            const res = await fetch('api/audit-log-export.php?' + params.toString());
            const data = await res.json();
            if (!data.success) { notify(data.message || 'Export failed.', 'error'); return; }
            if (!data.rows.length) { notify('No records in this range to export.', 'warning'); return; }

            document.getElementById('printMeta').textContent =
                `Range: ${document.getElementById('fStart').value || 'earliest'} to ${document.getElementById('fEnd').value || 'latest'} · ${data.rows.length} record(s) · Generated ${new Date().toLocaleString()}`;
            document.getElementById('printBody').innerHTML = data.rows.map(r => `
                <tr>
                    <td>${esc(r.created_at)}</td>
                    <td>${esc(EVENT_LABELS[r.event_type] || r.event_type)}</td>
                    <td>${esc(r.actor_type)}</td>
                    <td>${esc(r.actor_id)}</td>
                    <td>${esc(r.actor_name)}</td>
                    <td>${esc(r.description)}</td>
                    <td>${esc(r.ip_address)}</td>
                </tr>
            `).join('');

            await html2pdf().set({
                margin: [0.4, 0.3],
                filename: `audit-log-${data.range}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
            }).from(document.getElementById('printableExport')).save();
            notify('PDF exported successfully.', 'success');
        }
    } catch (e) {
        notify('Export failed — please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

loadLogs();
</script>
</body>
</html>
