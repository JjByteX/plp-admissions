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
       COUNT(*)                                                              AS total,

       /* pipeline steps — derived from overall_status + related tables */
       SUM(a.overall_status IN ('submitted','exam','interview','released'))  AS docs_submitted,
       SUM(a.overall_status IN ('exam','interview','released'))              AS docs_approved,
       SUM(EXISTS (
           SELECT 1 FROM exam_results er WHERE er.applicant_id = a.id
       ))                                                                    AS exam_taken,
       SUM(EXISTS (
           SELECT 1 FROM interview_queue iq
            WHERE iq.applicant_id = a.id
              AND iq.interview_status IN ('pending','completed')
       ))                                                                    AS interviewed,
       SUM(EXISTS (
           SELECT 1 FROM admission_results ar WHERE ar.applicant_id = a.id
       ))                                                                    AS results_released,

       /* applicant types */
       SUM(a.applicant_type = 'freshman')                                    AS cnt_freshman,
       SUM(a.applicant_type = 'transferee')                                  AS cnt_transferee,
       SUM(a.applicant_type = 'foreign')                                     AS cnt_foreign,

       /* result breakdown from admission_results */
       SUM((SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'accepted')            AS cnt_accepted,
       SUM((SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'waitlisted')          AS cnt_waitlisted,
       SUM((SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'rejected')            AS cnt_rejected
     FROM applicants a"
)->fetch(PDO::FETCH_ASSOC);

// Document-status breakdown — count document rows by status
$docStatsRow = db()->query(
    "SELECT
        SUM(status = 'approved')     AS approved,
        SUM(status = 'under_review') AS under_review,
        SUM(status = 'rejected')     AS rejected
     FROM documents"
)->fetch(PDO::FETCH_ASSOC);

$docStats = [
    'approved'     => (int)($docStatsRow['approved']     ?? 0),
    'under_review' => (int)($docStatsRow['under_review'] ?? 0),
    'rejected'     => (int)($docStatsRow['rejected']     ?? 0),
];

// Convenience helper: percentage relative to total registrants
function pct(int $part, int $total): int {
    return $total > 0 ? (int) round($part / $total * 100) : 0;
}

$total = (int) $stats['total'];

// Build pipeline rows (label, count, colour)
$pipeline = [
    ['label' => 'Registered',        'count' => $total,                          'color' => 'var(--chart-blue)'],
    ['label' => 'Docs submitted',     'count' => (int) $stats['docs_submitted'],  'color' => 'var(--chart-green)'],
    ['label' => 'Docs approved',      'count' => (int) $stats['docs_approved'],   'color' => 'var(--chart-green)'],
    ['label' => 'Exam taken',         'count' => (int) $stats['exam_taken'],      'color' => 'var(--chart-amber)'],
    ['label' => 'Interviewed',        'count' => (int) $stats['interviewed'],     'color' => 'var(--chart-purple)'],
    ['label' => 'Results released',   'count' => (int) $stats['results_released'],'color' => 'var(--chart-red)'],
];

$docPipeline = [
    ['label' => 'Approved',      'count' => (int) ($docStats['approved']     ?? 0), 'color' => 'var(--chart-lime)'],
    ['label' => 'Under review',  'count' => (int) ($docStats['under_review'] ?? 0), 'color' => 'var(--chart-amber)'],
    ['label' => 'Rejected',      'count' => (int) ($docStats['rejected']     ?? 0), 'color' => 'var(--chart-red)'],
];

$typePipeline = [
    ['label' => 'Freshman',   'count' => (int) $stats['cnt_freshman'],   'color' => 'var(--chart-blue)'],
    ['label' => 'Transferee', 'count' => (int) $stats['cnt_transferee'], 'color' => 'var(--chart-purple)'],
    ['label' => 'Foreign',    'count' => (int) $stats['cnt_foreign'],    'color' => 'var(--chart-pink)'],
];

// Idle applicant alerts
$idleDays = (int) school_setting('idle_applicant_days', '7');
$idleSummary = get_idle_summary($idleDays);
$totalIdle = array_sum(array_column($idleSummary, 'count'));

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

<!-- ── Idle Applicant Alerts ─────────────────────────────── -->
<?php if ($totalIdle > 0): ?>
<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-6)">
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-3)">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="#f97316" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <strong style="font-size:var(--text-sm)">Idle Applicants (<?= $totalIdle ?> waiting ><?= $idleDays ?> days)</strong>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
        <?php
        $stageLabels = [
            'pending' => 'Pending registration',
            'documents' => 'Waiting for document review',
            'submitted' => 'Docs submitted, awaiting review',
            'exam' => 'Waiting for exam slot',
            'interview' => 'Waiting for interview',
        ];
        foreach ($idleSummary as $idle):
            $label = $stageLabels[$idle['stage']] ?? ucfirst($idle['stage']);
        ?>
        <div class="idle-alert">
            <div class="idle-alert-count"><?= (int)$idle['count'] ?></div>
            <div>
                <div style="font-weight:var(--weight-medium)"><?= e($label) ?></div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Max <?= (int)$idle['max_days'] ?> days idle</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Actions ────────────────────────────────────── -->
<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-6)">
    <strong style="font-size:var(--text-sm);display:block;margin-bottom:var(--space-3)">Quick Actions</strong>
    <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
        <form method="POST" action="<?= url('/staff/results/auto-release') ?>" style="margin:0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm"
                    onclick="return confirm('Auto-release results for all eligible applicants based on score thresholds?')">
                <?= icon('ic_fluent_ribbon_star_24_regular', 14) ?>
                Auto-Release Results
            </button>
        </form>
        <a href="<?= url('/staff/interviews/batch') ?>" class="btn btn-sm">
            <?= icon('ic_fluent_calendar_add_24_regular', 14) ?>
            Batch Create Interviews
        </a>
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

        <h2 class="chart-title" style="margin-top:var(--space-6)">Applicant type</h2>
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
        ['label' => 'Registered', 'count' => $total,                          'color' => 'var(--chart-blue)'],
        ['label' => 'Documents',  'count' => (int) $stats['docs_submitted'],  'color' => 'var(--chart-green)'],
        ['label' => 'Exam',       'count' => (int) $stats['exam_taken'],      'color' => 'var(--chart-amber)'],
        ['label' => 'Interview',  'count' => (int) $stats['interviewed'],     'color' => 'var(--chart-purple)'],
        ['label' => 'Results',    'count' => (int) $stats['results_released'],'color' => 'var(--chart-lime)'],
    ];
    foreach ($steps as $step):
        $pctVal = pct($step['count'], $total);
    ?>
    <div class="step-card">
        <div class="step-count"><?= number_format($step['count']) ?></div>
        <div class="step-card-label"><?= e($step['label']) ?></div>
        <div class="step-bar">
            <div class="step-bar-fill"
                 style="width:<?= $pctVal ?>%; background:<?= $step['color'] ?>"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>