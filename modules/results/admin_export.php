<?php
// ============================================================
// modules/results/admin_export.php
// M6 — Admin: export all results as CSV or view summary
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$db = db();

// -- CSV export -------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $schoolYear = school_setting('current_school_year', '');
    $stmt = $db->prepare(
        'SELECT u.name, u.email, a.applicant_type, a.course_applied,
                a.school_year, a.overall_status,
                ar.result, ar.remarks, ar.released_at,
                er.score, er.total_items
         FROM applicants a
         JOIN users u ON u.id = a.user_id
         LEFT JOIN admission_results ar ON ar.applicant_id = a.id
         LEFT JOIN exam_results er ON er.applicant_id = a.id
         WHERE a.school_year = ?
         ORDER BY u.name'
    );
    $stmt->execute([$schoolYear]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plp_results_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Name','Email','Type','Course','School Year','Stage','Result','Remarks','Released At','Exam Score','Total Items']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'], $r['email'], $r['applicant_type'], $r['course_applied'],
            $r['school_year'], $r['overall_status'],
            $r['result'] ?? '', $r['remarks'] ?? '',
            $r['released_at'] ?? '',
            $r['score'] ?? '', $r['total_items'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// -- Summary view -----------------------------------------------
$schoolYear = $_GET['sy'] ?? school_setting('current_school_year', '');
$stmt = $db->prepare(
    'SELECT ar.result, COUNT(*) as cnt
     FROM admission_results ar
     JOIN applicants a ON a.id = ar.applicant_id
     WHERE a.school_year = ?
     GROUP BY ar.result'
);
$stmt->execute([$schoolYear]);
$resultCounts = array_column($stmt->fetchAll(), 'cnt', 'result');

$totalReleased = array_sum($resultCounts);

$stmt = $db->prepare(
    'SELECT COUNT(*) FROM applicants WHERE school_year=?'
);
$stmt->execute([$schoolYear]);
$totalApplicants = (int)$stmt->fetchColumn();

// School years list
$years = $db->query(
    'SELECT DISTINCT school_year FROM applicants ORDER BY school_year DESC'
)->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between">
    <div>
        <h1 class="page-title">Results Export</h1>
        <p class="page-description">Summary and CSV export of admission results.</p>
    </div>
    <a href="?export=csv&sy=<?= urlencode($schoolYear) ?>" class="btn btn-primary">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" style="margin-right:6px"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        Export CSV
    </a>
</div>

<!-- Year filter -->
<div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-6);flex-wrap:wrap">
    <?php foreach ($years as $yr): ?>
        <a href="?sy=<?= urlencode($yr) ?>"
           class="btn <?= $yr === $schoolYear ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= e($yr) ?></a>
    <?php endforeach; ?>
</div>

<!-- Stats -->
<div class="metrics-row" style="margin-bottom:var(--space-8)">
    <div class="metric-card">
        <div class="metric-label">Total Applicants</div>
        <div class="metric-value"><?= number_format($totalApplicants) ?></div>
        <div class="metric-sub"><?= e($schoolYear) ?></div>
    </div>
    <div class="metric-card metric-card--success">
        <div class="metric-label">Accepted</div>
        <div class="metric-value"><?= number_format($resultCounts['accepted'] ?? 0) ?></div>
        <div class="metric-sub">
            <?= $totalReleased ? round((($resultCounts['accepted'] ?? 0) / $totalReleased) * 100) : 0 ?>%
        </div>
    </div>
    <div class="metric-card metric-card--warning">
        <div class="metric-label">Waitlisted</div>
        <div class="metric-value"><?= number_format($resultCounts['waitlisted'] ?? 0) ?></div>
        <div class="metric-sub">
            <?= $totalReleased ? round((($resultCounts['waitlisted'] ?? 0) / $totalReleased) * 100) : 0 ?>%
        </div>
    </div>
    <div class="metric-card metric-card--error">
        <div class="metric-label">Not Accepted</div>
        <div class="metric-value"><?= number_format($resultCounts['rejected'] ?? 0) ?></div>
        <div class="metric-sub">
            <?= $totalReleased ? round((($resultCounts['rejected'] ?? 0) / $totalReleased) * 100) : 0 ?>%
        </div>
    </div>
</div>

<!-- Bar chart (pure CSS) -->
<?php if ($totalReleased > 0): ?>
<div class="card" style="padding:var(--space-6)">
    <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-5)">Result Breakdown</div>
    <?php foreach (['accepted' => ['Accepted','var(--success)'], 'waitlisted' => ['Waitlisted','var(--warning)'], 'rejected' => ['Not Accepted','var(--error)']] as $key => [$label, $color]):
        $cnt = $resultCounts[$key] ?? 0;
        $pct = $totalReleased > 0 ? round(($cnt / $totalReleased) * 100) : 0;
    ?>
        <div style="margin-bottom:var(--space-4)">
            <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= $label ?></span>
                <span style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= $cnt ?> (<?= $pct ?>%)</span>
            </div>
            <div style="height:10px;background:var(--bg-subtle);border-radius:var(--radius-full);overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:var(--radius-full)"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
    <div style="text-align:center;padding:var(--space-10);color:var(--text-tertiary);font-size:var(--text-sm)">
        No results released yet for <?= e($schoolYear) ?>.
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Results Export';
$activeNav = 'results';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';