<?php
// ============================================================
// modules/auth/staff/dashboard.php
// Staff dashboard — pipeline bars with visible left-side labels
// ============================================================
require_once ROOT_PATH . '/core/Auth.php';
Auth::requireRole(ROLE_STAFF);

// ── Fetch summary counts ─────────────────────────────────────
$stats = db()->query(
    "SELECT
       COUNT(*)                                          AS total,

       /* pipeline steps */
       SUM(doc_status IN ('uploaded','under_review','approved'))  AS docs_submitted,
       SUM(doc_status = 'approved')                              AS docs_approved,
       SUM(exam_score IS NOT NULL)                               AS exam_taken,
       SUM(interview_status IN ('scheduled','completed'))        AS interviewed,
       SUM(result IN ('accepted','waitlisted','rejected'))       AS results_released,

       /* applicant types */
       SUM(applicant_type = 'freshman')                          AS cnt_freshman,
       SUM(applicant_type = 'transferee')                        AS cnt_transferee,
       SUM(applicant_type = 'foreign')                           AS cnt_foreign,

       /* result breakdown */
       SUM(result = 'accepted')                                  AS cnt_accepted,
       SUM(result = 'waitlisted')                                AS cnt_waitlisted,
       SUM(result = 'rejected')                                  AS cnt_rejected
     FROM applicants"
)->fetch(PDO::FETCH_ASSOC);

// Document-status breakdown (separate query for clarity)
$docStats = db()->query(
    "SELECT doc_status, COUNT(*) AS cnt
     FROM   applicants
     WHERE  doc_status IS NOT NULL
     GROUP  BY doc_status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Convenience helper: percentage relative to total registrants
function pct(int $part, int $total): int {
    return $total > 0 ? (int) round($part / $total * 100) : 0;
}

$total = (int) $stats['total'];

// Build pipeline rows (label, count, colour)
$pipeline = [
    ['label' => 'Registered',        'count' => $total,                          'color' => '#378ADD'],
    ['label' => 'Docs submitted',     'count' => (int) $stats['docs_submitted'],  'color' => '#1D9E75'],
    ['label' => 'Docs approved',      'count' => (int) $stats['docs_approved'],   'color' => '#1D9E75'],
    ['label' => 'Exam taken',         'count' => (int) $stats['exam_taken'],      'color' => '#BA7517'],
    ['label' => 'Interviewed',        'count' => (int) $stats['interviewed'],     'color' => '#7F77DD'],
    ['label' => 'Results released',   'count' => (int) $stats['results_released'],'color' => '#E24B4A'],
];

$docPipeline = [
    ['label' => 'Approved',      'count' => (int) ($docStats['approved']     ?? 0), 'color' => '#639922'],
    ['label' => 'Under review',  'count' => (int) ($docStats['under_review'] ?? 0), 'color' => '#BA7517'],
    ['label' => 'Rejected',      'count' => (int) ($docStats['rejected']     ?? 0), 'color' => '#E24B4A'],
];

$typePipeline = [
    ['label' => 'Freshman',   'count' => (int) $stats['cnt_freshman'],   'color' => '#378ADD'],
    ['label' => 'Transferee', 'count' => (int) $stats['cnt_transferee'], 'color' => '#7F77DD'],
    ['label' => 'Foreign',    'count' => (int) $stats['cnt_foreign'],    'color' => '#D4537E'],
];

$activeNav = 'dashboard';
$pageTitle = 'Dashboard';
include VIEWS_PATH . '/layouts/app.php';
?>

<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">
        Academic Year <?= date('Y') ?>–<?= date('Y') + 1 ?> &middot; Admission overview
    </p>
</div>

<!-- ── Stat cards ───────────────────────────────────────── -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total applicants</div>
        <div class="stat-value"><?= number_format($total) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Exam takers</div>
        <div class="stat-value"><?= number_format((int) $stats['exam_taken']) ?></div>
        <div class="stat-badge badge-blue"><?= pct((int) $stats['exam_taken'], $total) ?>%</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Accepted</div>
        <div class="stat-value"><?= number_format((int) $stats['cnt_accepted']) ?></div>
        <div class="stat-badge badge-green"><?= pct((int) $stats['cnt_accepted'], $total) ?>%</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Waitlisted</div>
        <div class="stat-value"><?= number_format((int) $stats['cnt_waitlisted']) ?></div>
        <div class="stat-badge badge-amber"><?= pct((int) $stats['cnt_waitlisted'], $total) ?>%</div>
    </div>
</div>

<!-- ── Pipeline charts ────────────────────────────────── -->
<div class="dashboard-grid">

    <!-- Admission pipeline -->
    <div class="chart-card">
        <h2 class="chart-title">Admission pipeline</h2>
        <div class="bar-list">
            <?php foreach ($pipeline as $row):
                $pctVal = pct($row['count'], $total);
            ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($row['label']) ?></span>
                <div class="bar-track">
                    <div class="bar-fill"
                         style="width:<?= $pctVal ?>%; background:<?= $row['color'] ?>"></div>
                </div>
                <span class="bar-count"><?= number_format($row['count']) ?></span>
                <span class="bar-pct"><?= $pctVal ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Document status + applicant type -->
    <div class="chart-card">
        <h2 class="chart-title">Document status</h2>
        <div class="bar-list">
            <?php
            $docTotal = array_sum(array_column($docPipeline, 'count'));
            foreach ($docPipeline as $row):
                $pctVal = pct($row['count'], $docTotal ?: 1);
            ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($row['label']) ?></span>
                <div class="bar-track">
                    <div class="bar-fill"
                         style="width:<?= $pctVal ?>%; background:<?= $row['color'] ?>"></div>
                </div>
                <span class="bar-count"><?= number_format($row['count']) ?></span>
                <span class="bar-pct"><?= $pctVal ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>

        <h2 class="chart-title" style="margin-top:1.25rem">Applicant type</h2>
        <div class="bar-list">
            <?php foreach ($typePipeline as $row):
                $pctVal = pct($row['count'], $total);
            ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($row['label']) ?></span>
                <div class="bar-track">
                    <div class="bar-fill"
                         style="width:<?= $pctVal ?>%; background:<?= $row['color'] ?>"></div>
                </div>
                <span class="bar-count"><?= number_format($row['count']) ?></span>
                <span class="bar-pct"><?= $pctVal ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- ── Step summary strip ────────────────────────────────── -->
<div class="step-strip">
    <?php
    $steps = [
        ['label' => 'Registered', 'count' => $total,                          'color' => '#378ADD'],
        ['label' => 'Documents',  'count' => (int) $stats['docs_submitted'],  'color' => '#1D9E75'],
        ['label' => 'Exam',       'count' => (int) $stats['exam_taken'],      'color' => '#BA7517'],
        ['label' => 'Interview',  'count' => (int) $stats['interviewed'],     'color' => '#7F77DD'],
        ['label' => 'Results',    'count' => (int) $stats['results_released'],'color' => '#639922'],
    ];
    foreach ($steps as $step):
        $pctVal = pct($step['count'], $total);
    ?>
    <div class="step-card">
        <div class="step-count"><?= number_format($step['count']) ?></div>
        <div class="step-label"><?= e($step['label']) ?></div>
        <div class="step-bar">
            <div class="step-bar-fill"
                 style="width:<?= $pctVal ?>%; background:<?= $step['color'] ?>"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
