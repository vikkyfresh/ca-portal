<?php
/**
 * export_results.php
 * Lecturer-side: export test results as CSV / Excel / JSON / PDF
 *
 * Usage: export_results.php?test_id=12&format=csv
 */
session_start();

// ── Auth guard ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['lecturer_id'])) {
    http_response_code(401);
    die("Unauthorized");
}

require_once '../../includes/config.php';

// ── Input validation ─────────────────────────────────────────────────────────
$lecturerId = (int) $_SESSION['lecturer_id'];          // cast — never trust session blind
$testId     = (int) ($_GET['test_id'] ?? 0);
$format     = strtolower(trim($_GET['format'] ?? 'csv'));

$allowedFormats = ['csv', 'excel', 'json', 'pdf'];
if (!$testId || !in_array($format, $allowedFormats, true)) {
    http_response_code(400);
    die("Invalid request. Use format=csv|excel|json|pdf and a valid test_id.");
}

// ── Verify test belongs to this lecturer ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.*,
        (SELECT COUNT(*) FROM attempts WHERE test_id = t.id AND status = 'completed') AS total_submissions,
        (SELECT AVG(percentage) FROM attempts WHERE test_id = t.id AND status = 'completed') AS avg_score
    FROM tests t
    WHERE t.id = ? AND t.created_by = ?
    LIMIT 1
");
$stmt->execute([$testId, $lecturerId]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    http_response_code(404);
    die("Test not found or you do not have permission to export it.");
}

// ── Fetch student attempts ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        a.*,
        s.full_name, s.matric, s.email,
        s.level AS student_level
    FROM attempts a
    JOIN students s ON a.student_matric = s.matric
    WHERE a.test_id = ? AND a.status = 'completed'
    ORDER BY a.percentage DESC, a.time_spent_seconds ASC
");
$stmt->execute([$testId]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Statistics ────────────────────────────────────────────────────────────────
$totalStudents = count($results);
$passMark      = (float) ($test['passing_score'] ?? 50);

$passCount = $failCount = 0;
$scores = [];

foreach ($results as $r) {
    $pct = (float) $r['percentage'];
    $scores[] = $pct;
    $pct >= $passMark ? $passCount++ : $failCount++;
}

$highestScore  = $scores ? max($scores) : 0;
$lowestScore   = $scores ? min($scores) : 0;
$averageScore  = $scores ? array_sum($scores) / count($scores) : 0;
$passRate      = $totalStudents > 0 ? ($passCount / $totalStudents) * 100 : 0;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Sanitize a string for use in a Content-Disposition filename.
 * Removes characters that break HTTP headers / file systems.
 */
function safeFilename(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $s);
}

/** Safely format a DB datetime string; returns 'N/A' if blank/null */
function safeDate(mixed $val, string $fmt = 'Y-m-d H:i:s'): string {
    if (empty($val)) return 'N/A';
    $ts = strtotime($val);
    return $ts ? date($fmt, $ts) : 'N/A';
}

$courseCode = $test['course_code'] ?? 'COURSE';
$testTitle  = $test['test_title']  ?? 'Test';
$baseFile   = safeFilename($courseCode) . '_' . safeFilename($testTitle);
$generatedAt = date('Y-m-d H:i:s');

// ── Guard: no submissions yet ─────────────────────────────────────────────────
// (Only blocks PDF/print; CSV/Excel/JSON will still export with headers only)

// =============================================================================
// CSV
// =============================================================================
if ($format === 'csv') {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseFile . '_results.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM — makes Excel open accented names correctly
    fputs($out, "\xEF\xBB\xBF");

    // ── Cover info ────────────────────────────────────────────────────────────
    fputcsv($out, ['UNIVERSITY TEST RESULTS REPORT']);
    fputcsv($out, []);
    fputcsv($out, ['TEST INFORMATION']);
    fputcsv($out, ['Course Code',     $courseCode]);
    fputcsv($out, ['Test Title',      $testTitle]);
    fputcsv($out, ['Level',           $test['level'] ?? 'N/A']);
    fputcsv($out, ['Duration',        ($test['duration_minutes'] ?? '?') . ' minutes']);
    fputcsv($out, ['Pass Mark',       $passMark . '%']);
    fputcsv($out, ['Total Questions', $test['total_questions'] ?? '?']);
    fputcsv($out, ['Date Generated',  $generatedAt]);
    fputcsv($out, []);

    // ── Summary statistics ────────────────────────────────────────────────────
    fputcsv($out, ['SUMMARY STATISTICS']);
    fputcsv($out, ['Total Students',  $totalStudents]);
    fputcsv($out, ['Passed',          $passCount]);
    fputcsv($out, ['Failed',          $failCount]);
    fputcsv($out, ['Pass Rate',       round($passRate, 1) . '%']);
    fputcsv($out, ['Average Score',   round($averageScore, 1) . '%']);
    fputcsv($out, ['Highest Score',   round($highestScore, 1) . '%']);
    fputcsv($out, ['Lowest Score',    round($lowestScore, 1) . '%']);
    fputcsv($out, []);
    fputcsv($out, []);

    if ($totalStudents === 0) {
        fputcsv($out, ['No completed submissions found for this test.']);
        fclose($out);
        exit;
    }

    // ── Per-student rows ──────────────────────────────────────────────────────
    fputcsv($out, ['STUDENT PERFORMANCE DETAILS']);
    fputcsv($out, [
        'Rank', 'S/N', 'Student Name', 'Matric Number', 'Email', 'Level',
        'Raw Score', 'Total Questions', 'CA Mark (/30)',
        'Percentage',
        'Time Spent (min)',
        'Status',
        'Date Submitted',
    ]);

    $sn = $rank = 1;
    foreach ($results as $r) {
        $pct        = round((float) $r['percentage'], 2);
        $timeMins   = $r['time_spent_seconds'] > 0
                        ? round($r['time_spent_seconds'] / 60, 2)
                        : 'N/A';
        $status     = $pct >= $passMark ? 'PASS' : 'FAIL';
        $submitted  = safeDate($r['end_time'] ?? null);

        fputcsv($out, [
            $rank++,
            $sn++,
            $r['full_name']       ?? 'N/A',
            $r['matric']          ?? 'N/A',
            $r['email']           ?? 'N/A',
            $r['student_level']   ?? ($test['level'] ?? 'N/A'),
            $r['score']           ?? 0,
            $r['total']           ?? $test['total_questions'] ?? 0,
            $pct,                  // plain number — Excel can filter/sort/chart
            $timeMins,
            $status,
            $submitted,
        ]);
    }

    fclose($out);
    exit;
}

// =============================================================================
// EXCEL  (styled HTML that Excel opens natively)
// =============================================================================
if ($format === 'excel') {

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseFile . '_results.xls"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // UTF-8 BOM
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
  h1   { font-size: 14pt; color: #1e3a8a; margin: 0 0 2px; }
  h2   { font-size: 11pt; color: #334155; margin: 16px 0 6px; }
  p.meta { font-size: 9pt; color: #64748b; margin: 0 0 12px; }

  /* Info tables */
  .info-table           { border-collapse: collapse; margin-bottom: 8px; }
  .info-table td        { padding: 4px 10px; font-size: 10pt; border: 1px solid #e2e8f0; }
  .info-table td:first-child { background: #f1f5f9; font-weight: bold; width: 160px; }

  /* Stats boxes — rendered as a table row for Excel compat */
  .stat-wrap  { border-collapse: collapse; margin-bottom: 14px; }
  .stat-cell  { border: 1px solid #e2e8f0; padding: 8px 16px; text-align: center; width: 100px; }
  .stat-num   { font-size: 16pt; font-weight: bold; color: #1e3a8a; display: block; }
  .stat-label { font-size: 8pt; color: #64748b; display: block; }
  .stat-pass  .stat-num { color: #16a34a; }
  .stat-fail  .stat-num { color: #dc2626; }

  /* Results table */
  .results-table          { border-collapse: collapse; width: 100%; }
  .results-table th       { background: #1e3a8a; color: #fff; padding: 7px 10px; font-size: 10pt; text-align: left; white-space: nowrap; }
  .results-table td       { padding: 5px 10px; border-bottom: 1px solid #e2e8f0; font-size: 10pt; vertical-align: middle; }
  .results-table tr:nth-child(even) td { background: #f8fafc; }
  .badge-pass { background: #dcfce7; color: #15803d; font-weight: bold; padding: 2px 8px; border-radius: 4px; }
  .badge-fail { background: #fee2e2; color: #b91c1c; font-weight: bold; padding: 2px 8px; border-radius: 4px; }

  .footer { margin-top: 20px; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
</style>
</head>
<body>

<h1><?= htmlspecialchars($courseCode) ?> — <?= htmlspecialchars($testTitle) ?></h1>
<p class="meta">Test Results Report &nbsp;|&nbsp; Generated: <?= $generatedAt ?></p>

<h2>Test Information</h2>
<table class="info-table">
  <tr><td>Course Code</td>    <td><?= htmlspecialchars($courseCode) ?></td></tr>
  <tr><td>Test Title</td>     <td><?= htmlspecialchars($testTitle) ?></td></tr>
  <tr><td>Level</td>          <td><?= htmlspecialchars($test['level'] ?? 'N/A') ?></td></tr>
  <tr><td>Duration</td>       <td><?= htmlspecialchars($test['duration_minutes'] ?? '?') ?> minutes</td></tr>
  <tr><td>Pass Mark</td>      <td><?= $passMark ?>%</td></tr>
  <tr><td>Total Questions</td><td><?= htmlspecialchars($test['total_questions'] ?? '?') ?></td></tr>
</table>

<h2>Summary Statistics</h2>
<table class="stat-wrap">
  <tr>
    <td class="stat-cell"><span class="stat-num"><?= $totalStudents ?></span><span class="stat-label">Total Students</span></td>
    <td class="stat-cell stat-pass"><span class="stat-num"><?= $passCount ?></span><span class="stat-label">Passed</span></td>
    <td class="stat-cell stat-fail"><span class="stat-num"><?= $failCount ?></span><span class="stat-label">Failed</span></td>
    <td class="stat-cell"><span class="stat-num"><?= round($passRate, 1) ?>%</span><span class="stat-label">Pass Rate</span></td>
    <td class="stat-cell"><span class="stat-num"><?= round($averageScore, 1) ?>%</span><span class="stat-label">Avg Score</span></td>
    <td class="stat-cell"><span class="stat-num"><?= round($highestScore, 1) ?>%</span><span class="stat-label">Highest</span></td>
    <td class="stat-cell"><span class="stat-num"><?= round($lowestScore, 1) ?>%</span><span class="stat-label">Lowest</span></td>
  </tr>
</table>

<?php if ($totalStudents === 0): ?>
  <p style="color:#ef4444;">No completed submissions found for this test.</p>
<?php else: ?>

<h2>Student Performance Details</h2>
<table class="results-table">
  <thead>
    <tr>
      <th>Rank</th>
      <th>Student Name</th>
      <th>Matric No</th>
      <th>Email</th>
      <th>Level</th>
      <th>Raw Score</th>
      <th>CA Mark (/30)</th>
      <th>Percentage</th>
      <th>Time (min)</th>
      <th>Status</th>
      <th>Date Submitted</th>
    </tr>
  </thead>
  <tbody>
    <?php $rank = 1; foreach ($results as $r):
        $pct      = round((float) $r['percentage'], 1);
        $timeMins = $r['time_spent_seconds'] > 0
                      ? round($r['time_spent_seconds'] / 60, 1)
                      : 'N/A';
        $status   = $pct >= $passMark ? 'PASS' : 'FAIL';
    ?>
    <tr>
      <td><?= $rank++ ?></td>
      <td><?= htmlspecialchars($r['full_name'] ?? 'N/A') ?></td>
      <td><?= htmlspecialchars($r['matric'] ?? 'N/A') ?></td>
      <td><?= htmlspecialchars($r['email'] ?? 'N/A') ?></td>
      <td><?= htmlspecialchars($r['student_level'] ?? $test['level'] ?? 'N/A') ?></td>
      <td><?= (int)($r['score'] ?? 0) ?>/<?= (int)($r['total'] ?? $test['total_questions'] ?? 0) ?></td>
      <td><strong><?= round(($r['score'] / max($r['total'] ?? 1, 1)) * 30, 1) ?></strong> / 30</td>
      <td><?= $pct ?>%</td>
      <td><?= $timeMins ?></td>
      <td><span class="badge-<?= strtolower($status) ?>"><?= $status ?></span></td>
      <td><?= safeDate($r['end_time'] ?? null, 'Y-m-d H:i') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<p class="footer">
  Generated by CS Dept CA Portal &nbsp;|&nbsp; <?= $generatedAt ?> &nbsp;|&nbsp;
  This report is system-generated and does not require a signature.
</p>
</body>
</html>
    <?php
    exit;
}

// =============================================================================
// JSON
// =============================================================================
if ($format === 'json') {

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseFile . '_results.json"');

    $out = [
        'report_info' => [
            'generated_at' => $generatedAt,
            'generated_by' => 'CS Dept CA Portal',
        ],
        'test_info' => [
            'course_code'       => $courseCode,
            'test_title'        => $testTitle,
            'level'             => $test['level']             ?? null,
            'duration_minutes'  => $test['duration_minutes']  ?? null,
            'passing_score'     => $passMark,
            'total_questions'   => $test['total_questions']   ?? null,
        ],
        'statistics' => [
            'total_students' => $totalStudents,
            'pass_count'     => $passCount,
            'fail_count'     => $failCount,
            'pass_rate'      => round($passRate, 1),
            'average_score'  => round($averageScore, 1),
            'highest_score'  => round($highestScore, 1),
            'lowest_score'   => round($lowestScore, 1),
        ],
        'student_results' => [],
    ];

    $rank = 1;
    foreach ($results as $r) {
        $pct = round((float) $r['percentage'], 2);
        $out['student_results'][] = [
            'rank'    => $rank++,
            'student' => [
                'name'       => $r['full_name']     ?? null,
                'matric'     => $r['matric']         ?? null,
                'email'      => $r['email']          ?? null,
                'level'      => $r['student_level']  ?? ($test['level'] ?? null),
            ],
            'performance' => [
                'score'              => (int)($r['score'] ?? 0),
                'total'              => (int)($r['total'] ?? $test['total_questions'] ?? 0),
                'percentage'         => $pct,
                'time_spent_seconds' => (int)($r['time_spent_seconds'] ?? 0),
                'status'             => $pct >= $passMark ? 'PASS' : 'FAIL',
                'date_submitted'     => safeDate($r['end_time'] ?? null),
            ],
        ];
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================================
// PDF  (printable page — instructor clicks "Print → Save as PDF")
// =============================================================================
if ($format === 'pdf') {
    // No extra headers — this is an HTML page the lecturer prints to PDF
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Results — <?= htmlspecialchars($courseCode) ?></title>
<style>
  @page { margin: 18mm 14mm; }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body   { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt; color: #0f172a; background: #fff; }

  /* ── Print button (hidden on print) ── */
  .print-btn {
    display: flex; gap: 10px; align-items: center;
    margin: 16px 0 20px;
  }
  .print-btn button {
    padding: 9px 18px; border: none; border-radius: 8px;
    font-size: 10pt; cursor: pointer; font-weight: 600;
  }
  .btn-print { background: #1e3a8a; color: #fff; }
  .btn-back  { background: #f1f5f9; color: #334155; }
  @media print { .print-btn { display: none; } }

  /* ── Header ── */
  .page-header {
    border-bottom: 3px solid #1e3a8a;
    padding-bottom: 10px;
    margin-bottom: 14px;
  }
  .page-header h1 { font-size: 15pt; color: #1e3a8a; }
  .page-header p  { font-size: 8.5pt; color: #64748b; margin-top: 2px; }

  /* ── Info + stats row ── */
  .top-row { display: flex; gap: 16px; margin-bottom: 14px; }
  .info-box { flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
  .info-box h3 { font-size: 9pt; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 7px; }
  .info-row { display: flex; justify-content: space-between; font-size: 9.5pt; padding: 2px 0; border-bottom: 1px solid #f1f5f9; }
  .info-row:last-child { border-bottom: none; }
  .info-row span:first-child { color: #64748b; }
  .info-row span:last-child  { font-weight: 600; }

  .stat-row { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
  .stat-box { flex: 1; min-width: 70px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; text-align: center; background: #fff; }
  .stat-box .n { font-size: 16pt; font-weight: 800; color: #1e3a8a; line-height: 1.1; }
  .stat-box .l { font-size: 7.5pt; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
  .stat-box.pass .n { color: #16a34a; }
  .stat-box.fail .n { color: #dc2626; }

  /* ── Results table ── */
  h2 { font-size: 10pt; color: #1e3a8a; text-transform: uppercase;
       letter-spacing: .07em; border-bottom: 1px solid #e2e8f0;
       padding-bottom: 5px; margin-bottom: 8px; }
  table  { width: 100%; border-collapse: collapse; page-break-inside: auto; }
  thead  { display: table-header-group; }
  tr     { page-break-inside: avoid; }
  th     { background: #1e3a8a; color: #fff; padding: 6px 8px; font-size: 8.5pt; text-align: left; white-space: nowrap; }
  td     { padding: 5px 8px; font-size: 8.5pt; border-bottom: 1px solid #f1f5f9; }
  tr:nth-child(even) td { background: #f8fafc; }

  .pass-badge { color: #15803d; font-weight: 700; }
  .fail-badge { color: #b91c1c; font-weight: 700; }

  /* ── Footer ── */
  .page-footer {
    margin-top: 20px; padding-top: 8px;
    border-top: 1px solid #e2e8f0;
    font-size: 7.5pt; color: #94a3b8; text-align: center;
  }

  .no-results { color: #ef4444; padding: 14px 0; font-weight: 600; }
</style>
</head>
<body>

<div class="print-btn">
  <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
  <button class="btn-back"  onclick="history.back()">← Back</button>
</div>

<!-- Header -->
<div class="page-header">
  <h1><?= htmlspecialchars($courseCode) ?> — <?= htmlspecialchars($testTitle) ?> &nbsp;·&nbsp; Results Report</h1>
  <p>Generated: <?= $generatedAt ?> &nbsp;|&nbsp; CS Dept CA Portal</p>
</div>

<!-- Test info + stats -->
<div class="top-row">
  <div class="info-box">
    <h3>Test Details</h3>
    <div class="info-row"><span>Course</span>      <span><?= htmlspecialchars($courseCode) ?></span></div>
    <div class="info-row"><span>Title</span>        <span><?= htmlspecialchars($testTitle) ?></span></div>
    <div class="info-row"><span>Level</span>        <span><?= htmlspecialchars($test['level'] ?? 'N/A') ?></span></div>
    <div class="info-row"><span>Duration</span>     <span><?= htmlspecialchars($test['duration_minutes'] ?? '?') ?> min</span></div>
    <div class="info-row"><span>Pass Mark</span>    <span><?= $passMark ?>%</span></div>
    <div class="info-row"><span>Questions</span>    <span><?= htmlspecialchars($test['total_questions'] ?? '?') ?></span></div>
  </div>
</div>

<div class="stat-row">
  <div class="stat-box"><div class="n"><?= $totalStudents ?></div><div class="l">Students</div></div>
  <div class="stat-box pass"><div class="n"><?= $passCount ?></div><div class="l">Passed</div></div>
  <div class="stat-box fail"><div class="n"><?= $failCount ?></div><div class="l">Failed</div></div>
  <div class="stat-box"><div class="n"><?= round($passRate, 1) ?>%</div><div class="l">Pass Rate</div></div>
  <div class="stat-box"><div class="n"><?= round($averageScore, 1) ?>%</div><div class="l">Average</div></div>
  <div class="stat-box"><div class="n"><?= round($highestScore, 1) ?>%</div><div class="l">Highest</div></div>
  <div class="stat-box"><div class="n"><?= round($lowestScore, 1) ?>%</div><div class="l">Lowest</div></div>
</div>

<!-- Results table -->
<h2>Student Performance Details</h2>

<?php if ($totalStudents === 0): ?>
  <p class="no-results">No completed submissions found for this test.</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Student Name</th>
      <th>Matric No</th>
      <th>Score</th>
      <th>CA Mark (/30)</th>
      <th>%</th>
      <th>Time</th>
      <th>Status</th>
      <th>Submitted</th>
    </tr>
  </thead>
  <tbody>
    <?php $rank = 1; foreach ($results as $r):
        $pct      = round((float)($r['percentage'] ?? 0), 1);
        $timeMins = ($r['time_spent_seconds'] ?? 0) > 0
                      ? round($r['time_spent_seconds'] / 60, 1) . ' min'
                      : 'N/A';
        $status   = $pct >= $passMark ? 'PASS' : 'FAIL';
    ?>
    <tr>
      <td><?= $rank++ ?></td>
      <td><?= htmlspecialchars($r['full_name'] ?? 'N/A') ?></td>
      <td><?= htmlspecialchars($r['matric'] ?? 'N/A') ?></td>
      <td><?= (int)($r['score'] ?? 0) ?>/<?= (int)($r['total'] ?? $test['total_questions'] ?? 0) ?></td>
      <td><strong><?= round(($r['score'] / max($r['total'] ?? 1, 1)) * 30, 1) ?></strong> / 30</td>
      <td><?= $pct ?>%</td>
      <td><?= $timeMins ?></td>
      <td class="<?= $status === 'PASS' ? 'pass-badge' : 'fail-badge' ?>"><?= $status ?></td>
      <td><?= safeDate($r['end_time'] ?? null, 'Y-m-d H:i') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="page-footer">
  CS Dept CA Portal &nbsp;·&nbsp; <?= $generatedAt ?> &nbsp;·&nbsp;
  System-generated report — no signature required
</div>

</body>
</html>
    <?php
    exit;
}

// Fallthrough (shouldn't reach here due to validation above)
http_response_code(400);
die("Invalid export format.");
