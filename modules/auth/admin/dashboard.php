<?php
// ============================================================
// modules/auth/admin/dashboard.php
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

// Finalize closed exams nobody submitted (one cheap EXISTS when there is no work).
finalize_closed_exam_drafts();

$pdo            = db();
$currentSY      = school_setting('current_school_year');

// ── School year switcher ──────────────────────────────────────────
// Build list: current SY + any historical ones saved by legacy import
$histRaw        = school_setting('historical_school_years', '');
$historicalSYs  = $histRaw ? array_filter(array_map('trim', explode(',', $histRaw))) : [];
$allSYs         = array_unique(array_merge([$currentSY], $historicalSYs));
rsort($allSYs); // newest first

$schoolYear = $_GET['sy'] ?? $currentSY;
if (!in_array($schoolYear, $allSYs, true)) {
    $schoolYear = $currentSY; // fallback to current if invalid
}

// ── Dean dept scope ───────────────────────────────────────────────
// Dean sees only applicants whose course maps to their own college.
// Admin / SSO see everything (filter is empty string + empty params).
[$deptFilter, $deptParams] = viewer_course_filter('a');

// ── Date range ────────────────────────────────────────────────────
$validRanges = ['today','yesterday','this-week','last-week','this-month','last-month','this-year','last-year','custom'];
$range    = in_array($_GET['range'] ?? '', $validRanges) ? $_GET['range'] : 'this-year';
$fromDate = isset($_GET['from']) ? preg_replace('/[^0-9\-]/', '', $_GET['from']) : null;
$toDate   = isset($_GET['to'])   ? preg_replace('/[^0-9\-]/', '', $_GET['to'])   : null;

$dateFilter = '';
$dateExtra  = [];
switch ($range) {
    case 'today':
        $dateFilter = 'AND CAST(a.created_at AS date) = CURRENT_DATE'; break;
    case 'yesterday':
        $dateFilter = 'AND CAST(a.created_at AS date) = CURRENT_DATE - 1'; break;
    case 'this-week':
        $dateFilter = 'AND date_trunc(\'week\', a.created_at) = date_trunc(\'week\', LOCALTIMESTAMP)'; break;
    case 'last-week':
        $dateFilter = 'AND date_trunc(\'week\', a.created_at) = date_trunc(\'week\', LOCALTIMESTAMP - INTERVAL \'1 week\')'; break;
    case 'this-month':
        $dateFilter = 'AND date_trunc(\'month\', a.created_at) = date_trunc(\'month\', LOCALTIMESTAMP)'; break;
    case 'last-month':
        $dateFilter = 'AND date_trunc(\'month\', a.created_at) = date_trunc(\'month\', LOCALTIMESTAMP - INTERVAL \'1 month\')'; break;
    case 'last-year':
        $dateFilter = 'AND EXTRACT(YEAR FROM a.created_at) = EXTRACT(YEAR FROM CURRENT_DATE) - 1'; break;
    case 'custom':
        if ($fromDate && $toDate) {
            $dateFilter = 'AND CAST(a.created_at AS date) BETWEEN ? AND ?';
            $dateExtra  = [$fromDate, $toDate];
        }
        break;
    default:
        $dateFilter = 'AND EXTRACT(YEAR FROM a.created_at) = EXTRACT(YEAR FROM CURRENT_DATE)';
}

// ── CSV export (early exit) ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvStmt = $pdo->prepare("
        SELECT
            u.name,
            u.email,
            u.sex,
            CAST(EXTRACT(YEAR FROM AGE(CURRENT_DATE, u.birthdate)) AS integer)               AS age,
            TRIM(split_part(COALESCE(substring(u.address from '.*Brgy[.] (.*)$'), u.address), ',', 1)) AS barangay,
            a.applicant_type,
            a.course_applied,
            a.overall_status,
            a.school_year,
            COALESCE(ar.result, 'pending')                                                   AS result,
            ar.remarks,
            ar.released_at,
            er.score,
            er.total_items,
            a.created_at
        FROM applicants a
        JOIN  users u              ON u.id = a.user_id
        LEFT JOIN admission_results ar ON ar.applicant_id = a.id
        LEFT JOIN LATERAL (
            SELECT score, total_items
            FROM exam_results
            WHERE applicant_id = a.id
            ORDER BY submitted_at DESC, id DESC
            LIMIT 1
        ) er ON true
        WHERE a.school_year = ? $dateFilter $deptFilter
        ORDER BY a.created_at DESC
    ");
    $csvStmt->execute(array_merge([$schoolYear], $dateExtra, $deptParams));
    $rows = $csvStmt->fetchAll(PDO::FETCH_ASSOC);
    // The file follows the dashboard: same school year, same date range (on the
    // date the applicant applied), same college scope. The range is in the name.
    $rangeSlug = ($range === 'custom' && $fromDate && $toDate) ? $fromDate . '_to_' . $toDate : $range;
    csv_download(
        'plp-admissions-' . $schoolYear . '-' . $rangeSlug . '.csv',
        ['Name','Email','Sex','Age','Barangay','Type','Course','Stage','School Year','Result','Remarks','Released At','Exam Score','Total Items','Applied At'],
        $rows
    );
    exit;
}

// ── Stats ──────────────────────────────────────────────────────────
// Waitlisted tier was retired in the role redesign — the only outcomes
// are Accepted or Declined. The third KPI now surfaces Withdrawn,
// which is tracked on applicants.overall_status (not admission_results).
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*)                              AS total,
        SUM(CASE WHEN ar.result = 'accepted'         THEN 1 ELSE 0 END) AS accepted,
        SUM(CASE WHEN ar.result = 'rejected'         THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN a.overall_status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn,
        SUM(CASE WHEN a.overall_status = 'released'  THEN 1 ELSE 0 END) AS released
    FROM applicants a
    LEFT JOIN admission_results ar ON ar.applicant_id = a.id
    WHERE a.school_year = ? $dateFilter $deptFilter
");
$statsStmt->execute(array_merge([$schoolYear], $dateExtra, $deptParams));
$t = $statsStmt->fetch(PDO::FETCH_ASSOC);

$total         = (int)$t['total'];
$accepted      = (int)$t['accepted'];
$rejected      = (int)$t['rejected'];
$withdrawn     = (int)$t['withdrawn'];
$released      = (int)$t['released'];
// All three outcome KPIs use the same denominator (total applicants) so
// the percentages are directly comparable. Withdrawn can happen at any
// stage, so anchoring it to released-only would understate it; anchoring
// Accepted / Declined to total is honest too (anyone not yet released
// just hasn't landed there yet — they're part of the same denominator).
$acceptedPct   = $total > 0 ? round($accepted  / $total * 100, 1) : 0;
$rejectedPct   = $total > 0 ? round($rejected  / $total * 100, 1) : 0;
$withdrawnPct  = $total > 0 ? round($withdrawn / $total * 100, 1) : 0;
$completionPct = $total > 0 ? round($released  / $total * 100)    : 0;

// ── Pipeline ───────────────────────────────────────────────────────
$pipelineStmt = $pdo->prepare("
    SELECT overall_status, COUNT(*) AS cnt
    FROM applicants a
    WHERE a.school_year = ? $dateFilter $deptFilter
    GROUP BY overall_status
");
$pipelineStmt->execute(array_merge([$schoolYear], $dateExtra, $deptParams));
$pipelineMap    = array_column($pipelineStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'overall_status');
$pipelineOrder  = ['pending','documents','exam','interview','released'];
$pipelineLabels = ['Pending','Documents','Exam','Interview','Released'];
$pipelineData   = array_map(fn($s) => (int)($pipelineMap[$s] ?? 0), $pipelineOrder);

// ── Course — all PLP courses, 0 if no data ─────────────────────────
$courseCountsStmt = $pdo->prepare("
    SELECT a.course_applied AS label, COUNT(*) AS cnt
    FROM applicants a
    WHERE a.school_year = ? $dateFilter $deptFilter
    GROUP BY a.course_applied
");
$courseCountsStmt->execute(array_merge([$schoolYear], $dateExtra, $deptParams));
$courseCountsMap = array_column($courseCountsStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'label');

// Admin / SSO: every PLP course. Dean: only the courses of their own college.
$viewerDept = viewer_scoped_department();
$isDean     = StaffScope::current()->restricted();
$courseFull = $isDean ? StaffScope::current()->courses() : PLP_COURSES;
$courseData   = array_map(fn($c) => (int)($courseCountsMap[$c] ?? 0), $courseFull);
// Axis shows only the acronym (the text in the last parentheses, e.g.
// "BS Information Technology (BSIT)" -> "BSIT"); the full name is shown in the
// chart tooltip on hover.
$courseLabels = array_map(function ($c) {
    return preg_match('/\(([^()]+)\)\s*$/', $c, $m) ? $m[1] : $c;
}, $courseFull);

// Biggest first, so the chart reads as a ranking. Ties keep their config order.
$courseRows = [];
foreach ($courseFull as $i => $c) { $courseRows[] = [$c, $courseLabels[$i], $courseData[$i]]; }
usort($courseRows, fn($a, $b) => $b[2] <=> $a[2]);
$courseFull   = array_column($courseRows, 0);
$courseLabels = array_column($courseRows, 1);
$courseData   = array_column($courseRows, 2);

// ── SHS Strand — all strands from config, 0 if no data ────────────
$strandCountsStmt = $pdo->prepare("
    SELECT a.shs_strand AS label, COUNT(*) AS cnt
    FROM applicants a
    WHERE a.school_year = ? $dateFilter $deptFilter
      AND a.shs_strand IS NOT NULL AND a.shs_strand != ''
    GROUP BY a.shs_strand
");
$strandCountsStmt->execute(array_merge([$schoolYear], $dateExtra, $deptParams));
$strandCountsMap = array_column($strandCountsStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'label');

// Use full labels from config; keys are the DB values
$strandLabels = [];   // acronyms (axis)
$strandFull   = [];   // full names (tooltip)
$strandData   = [];
// Dean: only strands accepted by their own courses (COURSE_STRAND_MAP).
$allowedStrands = null;
if ($isDean) {
    $allowedStrands = [];
    foreach ($courseFull as $c) {
        foreach (COURSE_STRAND_MAP[$c] ?? [] as $st) { $allowedStrands[$st] = true; }
    }
}
foreach (SHS_STRANDS as $key => $label) {
    if ($allowedStrands !== null && !isset($allowedStrands[$key])) continue;
    $strandLabels[] = $key;
    $strandFull[]   = $label;
    $strandData[]   = (int)($strandCountsMap[$key] ?? 0);
}
$strandRows = [];
foreach ($strandLabels as $i => $l) { $strandRows[] = [$l, $strandFull[$i], $strandData[$i]]; }
usort($strandRows, fn($a, $b) => $b[2] <=> $a[2]);
$strandLabels = array_column($strandRows, 0);
$strandFull   = array_column($strandRows, 1);
$strandData   = array_column($strandRows, 2);

// ── Sex ────────────────────────────────────────────────────────────
$sexStmt = $pdo->prepare("
    SELECT u.sex, COUNT(*) AS cnt
    FROM applicants a JOIN users u ON u.id = a.user_id
    WHERE a.school_year = ? $dateFilter $deptFilter AND u.sex IN ('M','F')
    GROUP BY u.sex
");
$sexStmt->execute(array_merge([$schoolYear], $dateExtra, $deptParams));
$sexMap    = array_column($sexStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'sex');
$sexMale   = (int)($sexMap['M'] ?? 0);
$sexFemale = (int)($sexMap['F'] ?? 0);



// ── Labels & URLs ──────────────────────────────────────────────────
$rangeLabels = [
    'today'      => 'Today',      'yesterday'  => 'Yesterday',
    'this-week'  => 'This week',  'last-week'  => 'Last week',
    'this-month' => 'This month', 'last-month' => 'Last month',
    'this-year'  => 'This year',  'last-year'  => 'Last year',
    'custom'     => ($fromDate && $toDate) ? e($fromDate).' – '.e($toDate) : 'Custom range',
];
$rangeLabel  = $rangeLabels[$range] ?? 'This year';
$exportUrl   = url('/admin/dashboard').'?'.http_build_query(array_filter([
    'sy' => $schoolYear, 'range' => $range, 'from' => $fromDate, 'to' => $toDate, 'export' => 'csv'
]));

// Donut ring math  r=24, cx=cy=29, viewBox 58×58
$circ    = round(2 * M_PI * 24, 2); // ≈ 150.80
$dashOff = round($circ * (1 - $completionPct / 100), 2);

$courseCount = count($courseFull);
// Course canvas height = rows x fixed row height (+ axis room), so bars never touch.
$courseRowPx   = 32;
$courseCanvasH = $courseCount * $courseRowPx + 40;

ob_start();
?>

<style>
/* ── All UI colors from app CSS variables ─────────────────── */

.db-header {
    display:flex; align-items:flex-start; justify-content:space-between;
    flex-wrap:wrap; gap:var(--space-3); margin-bottom:var(--space-6);
}
.db-heading {
    font-size:var(--text-xl); font-weight:var(--weight-semibold);
    color:var(--text-primary); margin:0 0 var(--space-1); letter-spacing:-0.2px;
}
.db-sub      { font-size:var(--text-sm); color:var(--text-tertiary); margin:0; }
.db-header:not(:has(*)), .db-header:not(:has(.db-controls)) { display:none; }
.page-head-actions .dp-menu { left:auto; right:0; }
.db-controls { display:flex; align-items:center; gap:var(--space-2); flex-wrap:wrap; }

/* ── 3-column grid: 30% | 40% | 30%
   Col1 and Col2 are plain flex stacks - no row-spanning.
   ───────────────────────────────────── */
.db-grid {
    display: grid;
    /* minmax(0, 1fr), not 1fr: a bare 1fr can't shrink below its content, so
       after the sidebar collapsed and re-expanded the charts' old pixel width
       held the columns open and the cards ran off to the right. */
    grid-template-columns: repeat(2, minmax(0, 1fr));
    grid-template-rows: auto 1fr;
    gap: var(--space-4);
    align-items: stretch;
    min-height: 0;
    flex: 1;
}
.db-grid > .card { display: flex; flex-direction: column; min-width: 0; }

/* Make the dashboard fill the .page so the bottom gap matches the
   left/right gap (var(--space-8) = 32px from .page padding). */
.page:has(.dashboard-wrap) { display:flex; flex-direction:column; }
.dashboard-wrap { display:flex; flex-direction:column; flex:1; min-height:0; }

/* KPI cards — 4 equal squares, single accent color */
.db-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--space-3);
    margin-top: var(--space-4);
    flex: 1;
}
.db-kpi-item {
    background: var(--bg-subtle);
    border-radius: var(--radius);
    padding: var(--space-5) var(--space-4);
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}
.db-kpi-val {
    font-size: 2rem;
    font-weight: var(--weight-semibold);
    letter-spacing: -0.04em;
    line-height: 1;
    color: var(--text-primary);
}
.db-kpi-val--success,
.db-kpi-val--error,
.db-kpi-val--warning { color: var(--text-primary); }
.db-kpi-lbl {
    font-size: var(--text-sm);
    color: var(--text-secondary);
    font-weight: var(--weight-medium);
    letter-spacing: 0;
    text-transform: none;
    margin-top: var(--space-1);
}
.db-kpi-sub {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    margin-top: calc(var(--space-1) * -1);
}
.db-kpi-bar {
    height: 4px;
    margin-top: auto;
    background: var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.db-kpi-bar > span { display: block; height: 100%; background: var(--accent-light); border-radius: var(--radius); }
.db-empty { flex: 1; display: flex; align-items: center; justify-content: center; min-height: 120px; color: var(--text-tertiary); font-size: var(--text-sm); }

/* Pipeline donut (SVG) */
.db-donut-wrap { position:relative; width:110px; height:110px; flex-shrink:0; }
.db-donut      { width:110px; height:110px; }
.db-donut-bg   { stroke:var(--bg-subtle); }
.db-donut-fill { stroke:var(--accent); stroke-linecap:round; transition:stroke-dashoffset .5s ease; }
.db-donut-lbl  { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; line-height:1; gap:2px; }
.db-donut-pct  { font-size:22px; font-weight:var(--weight-semibold); color:var(--text-primary); }
.db-donut-sub  { font-size:var(--text-xs); color:var(--text-tertiary); text-transform:uppercase; letter-spacing:.05em; }

/* Card chart header */
.db-ch     { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:var(--space-4); }
.db-ch-sub { font-size:var(--text-sm); color:var(--text-secondary); font-weight:var(--weight-medium); }

/* Legend */
.db-legend      { display:flex; flex-wrap:wrap; gap:var(--space-3); margin-top:var(--space-3); justify-content:center; }
.db-legend-item { display:flex; align-items:center; gap:var(--space-2); font-size:var(--text-sm); color:var(--text-secondary); }
.db-legend-dot  { width:8px; height:8px; border-radius:var(--radius); flex-shrink:0; }


/* Dashboard card titles */
.db-grid .card-title { font-size: var(--text-base); font-weight: var(--weight-semibold); color: var(--text-primary); }

/* Date picker */
.dp-wrap { position:relative; }
.dp-menu {
    display:none; position:absolute; left:0; top:calc(100% + var(--space-1));
    min-width:176px; background:var(--bg-elevated); border:1px solid var(--border);
    border-radius:var(--radius); box-shadow:var(--shadow-md);
    padding:var(--space-1) 0; z-index:200;
}
.dp-menu.open { display:block; }
.dp-item {
    display:block; width:100%; text-align:left; padding:var(--space-2) var(--space-4);
    font-size:var(--text-sm); font-family:var(--font-sans); color:var(--text-secondary);
    background:none; border:none; cursor:pointer;
}
.dp-item:hover  { background:var(--bg-subtle); color:var(--text-primary); }
.dp-item.active { color:var(--accent); font-weight:var(--weight-medium); }
.dp-divider     { height:1px; background:var(--border); margin:var(--space-1) 0; }
.dp-custom-row  { display:none; padding:var(--space-2) var(--space-3); gap:var(--space-2); align-items:center; }
.dp-custom-row.show { display:flex; }
.dp-date-in {
    flex:1; height:var(--control-height); padding:0 var(--space-2); font-size:var(--text-sm);
    font-family:var(--font-sans); border:1px solid var(--border);
    border-radius:var(--radius); background:var(--bg-subtle); color:var(--text-primary); min-width:0;
}

@media (max-width:640px) {
    .dp-menu, .page-head-actions .dp-menu {
        left:auto; right:0;
        min-width:min(220px, calc(100vw - 2rem));
        max-width:calc(100vw - 2rem);
    }
    .dp-item { font-size:var(--text-base); padding:var(--space-3) var(--space-4); }
    .dp-custom-row.show { flex-wrap:wrap; }
}
@media (max-width:960px) {
    .db-grid { grid-template-columns:minmax(0, 1fr); }
}
@media (max-width:560px) {
    .db-grid { grid-template-columns:minmax(0, 1fr); }
}
@media (max-width:768px) {
    .db-kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .db-kpi-item { min-width:0;padding:var(--space-4) var(--space-3); }
}
</style>

<div class="dashboard-wrap">

    <!-- ── Header ───────────────────────────────────────────────── -->
    <div class="db-header">
        <div class="db-controls" data-page-actions>

            <?php if (count($allSYs) > 1): ?>
            <div style="display:flex;align-items:center;gap:var(--space-2);">
                <label for="db-school-year" style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap;">School Year</label>
                <select id="db-school-year" class="toolbar-select" onchange="location.href=this.value">
                    <?php foreach ($allSYs as $sy): ?>
                        <?php
                        $params = $_GET;
                        $params['sy'] = $sy;
                        $url = '?' . http_build_query($params);
                        $label = 'SY ' . e($sy) . ($sy === $currentSY ? ' (current)' : '');
                        ?>
                        <option value="<?= $url ?>" <?= $sy === $schoolYear ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="dp-wrap" id="dpWrap">
                <button class="db-pill-btn" onclick="dpToggle(event)" type="button" style="
                    display:flex;align-items:center;gap:var(--space-2);
                    height:var(--control-height);padding:0 var(--space-3);
                    border:1px solid var(--border);border-radius:var(--radius);
                    background:var(--bg-elevated);color:var(--text-secondary);
                    font-size:var(--text-sm);cursor:pointer;white-space:nowrap;
                    transition:border-color var(--transition-fast),color var(--transition-fast);
                ">
                    <span id="dpLabel"><?= e($rangeLabel) ?></span>
                    <?= icon('caret-down', 10) ?>
                </button>
                <div class="dp-menu app-dropdown-panel" id="dpMenu">
                    <button class="dp-item <?= $range==='today'      ?'active':''?>" onclick="dpSet('today')">Today</button>
                    <button class="dp-item <?= $range==='yesterday'  ?'active':''?>" onclick="dpSet('yesterday')">Yesterday</button>
                    <div class="dp-divider"></div>
                    <button class="dp-item <?= $range==='this-week'  ?'active':''?>" onclick="dpSet('this-week')">This week</button>
                    <button class="dp-item <?= $range==='last-week'  ?'active':''?>" onclick="dpSet('last-week')">Last week</button>
                    <div class="dp-divider"></div>
                    <button class="dp-item <?= $range==='this-month' ?'active':''?>" onclick="dpSet('this-month')">This month</button>
                    <button class="dp-item <?= $range==='last-month' ?'active':''?>" onclick="dpSet('last-month')">Last month</button>
                    <div class="dp-divider"></div>
                    <button class="dp-item <?= $range==='this-year'  ?'active':''?>" onclick="dpSet('this-year')">This year</button>
                    <button class="dp-item <?= $range==='last-year'  ?'active':''?>" onclick="dpSet('last-year')">Last year</button>
                    <div class="dp-divider"></div>
                    <button class="dp-item <?= $range==='custom'     ?'active':''?>" onclick="dpCustom()">Custom range&hellip;</button>
                    <div class="dp-custom-row <?= $range==='custom'?'show':''?>" id="dpCustomRow">
                        <input type="date" class="dp-date-in" id="dpFrom" aria-label="From date" value="<?= e($fromDate ?? '') ?>">
                        <input type="date" class="dp-date-in" id="dpTo" aria-label="To date" value="<?= e($toDate   ?? '') ?>">
                        <button class="btn btn-primary" style="padding:var(--space-1) var(--space-3);font-size:var(--text-sm)" onclick="dpApply()">Go</button>
                    </div>
                </div>
            </div>

            <a class="db-pill-btn" href="<?= $exportUrl ?>" style="
                display:flex;align-items:center;gap:var(--space-2);
                height:var(--control-height);padding:0 var(--space-3);
                border:1px solid var(--border);border-radius:var(--radius);
                background:var(--bg-elevated);color:var(--text-secondary);
                font-size:var(--text-sm);cursor:pointer;white-space:nowrap;text-decoration:none;
                transition:border-color var(--transition-fast),color var(--transition-fast);
            ">
                <?= icon('download-simple', 14) ?>
                Export CSV
            </a>

        </div>
    </div>

    <!-- ── Main grid ─────────────────────────────────────────────── -->
    <div class="db-grid">

        <!-- Cell 1 — Key Metrics -->
        <div class="card db-c-kpi" style="padding:var(--space-5);">
            <div style="display:flex;align-items:baseline;justify-content:space-between;">
                <span class="card-title">Key metrics</span>
                <span style="font-size:var(--text-sm);color:var(--text-tertiary);"><?= e($rangeLabel) ?></span>
            </div>
            <div class="db-kpi-grid">
                <div class="db-kpi-item">
                    <div class="db-kpi-val"><?= number_format($total) ?></div>
                    <div class="db-kpi-lbl">Total</div>
                    <div class="db-kpi-sub">SY <?= e($schoolYear) ?></div>
                </div>
                <div class="db-kpi-item">
                    <div class="db-kpi-val db-kpi-val--success"><?= number_format($accepted) ?></div>
                    <div class="db-kpi-lbl">Accepted</div>
                    <div class="db-kpi-sub"><?= $acceptedPct ?>% of total</div>
                    <div class="db-kpi-bar"><span style="width:<?= min(100, $acceptedPct) ?>%"></span></div>
                </div>
                <div class="db-kpi-item">
                    <div class="db-kpi-val db-kpi-val--error"><?= number_format($rejected) ?></div>
                    <div class="db-kpi-lbl">Declined</div>
                    <div class="db-kpi-sub"><?= $rejectedPct ?>% of total</div>
                    <div class="db-kpi-bar"><span style="width:<?= min(100, $rejectedPct) ?>%"></span></div>
                </div>
                <div class="db-kpi-item">
                    <div class="db-kpi-val"><?= number_format($withdrawn) ?></div>
                    <div class="db-kpi-lbl">Withdrawn</div>
                    <div class="db-kpi-sub"><?= $withdrawnPct ?>% of total</div>
                    <div class="db-kpi-bar"><span style="width:<?= min(100, $withdrawnPct) ?>%"></span></div>
                </div>
            </div>
        </div>

        <!-- Cell 2 — Pipeline -->
        <div class="card db-c-pipeline" style="padding:var(--space-5);">
            <div class="db-ch">
                <span class="card-title">Pipeline</span>
                <span class="db-ch-sub"><?= number_format($total) ?> total</span>
            </div>
            <div style="display:flex;gap:0;align-items:center;flex:1;">
                <!-- Chart -->
                <div style="flex:1;min-width:0;padding-right:var(--space-4);">
                    <?php if ($total === 0): ?>
                        <div class="db-empty">No applicants in this range.</div>
                    <?php else: ?>
                    <div style="position:relative;width:100%;height:<?= count($pipelineOrder) * 30 + 56 ?>px;">
                        <canvas id="chartPipeline"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Divider -->
                <div style="width:1px;background:var(--border);flex-shrink:0;align-self:stretch;"></div>
                <!-- Donut — flex:1 mirrors the Sex side below -->
                <div style="flex:1;min-width:0;padding-left:var(--space-4);display:flex;align-items:center;justify-content:center;">
                    <div class="db-donut-wrap" title="<?= $completionPct ?>% released">
                        <svg class="db-donut" viewBox="0 0 58 58" role="img" aria-label="<?= $completionPct ?>% released">
                            <circle class="db-donut-bg"   cx="29" cy="29" r="24" fill="none" stroke-width="7"/>
                            <circle class="db-donut-fill" cx="29" cy="29" r="24" fill="none" stroke-width="7"
                                    stroke-dasharray="<?= $circ ?>"
                                    stroke-dashoffset="<?= $dashOff ?>"
                                    transform="rotate(-90 29 29)"/>
                        </svg>
                        <div class="db-donut-lbl">
                            <span class="db-donut-pct"><?= $completionPct ?>%</span>
                            <span class="db-donut-sub">done</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cell 3 — Course -->
        <div class="card db-c-course" style="padding:var(--space-5);">
            <div class="db-ch">
                <span class="card-title">Course</span>
                <span class="db-ch-sub"><?= $courseCount ?> courses</span>
            </div>
            <?php if ($total === 0): ?>
                <div class="db-empty">No applicants in this range.</div>
            <?php else: ?>
            <div style="width:100%;flex:1;min-height:200px;max-height:380px;overflow-y:auto;">
                <div style="position:relative;width:100%;height:<?= (int)$courseCanvasH ?>px;">
                    <canvas id="chartCourse"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Cell 4 — SHS Strand -->
        <div class="card db-c-strand" style="padding:var(--space-5);">
            <div class="db-ch">
                <span class="card-title">SHS Strand</span>
                <span class="db-ch-sub">by strand</span>
            </div>
            <?php if ($total === 0): ?>
                <div class="db-empty">No applicants in this range.</div>
            <?php else: ?>
            <div style="position:relative;width:100%;flex:1;min-height:200px;">
                <canvas id="chartStrand"></canvas>
            </div>
            <?php endif; ?>
            <div class="db-legend" id="strandLegend"></div>
        </div>




    </div><!-- /db-grid -->

</div>

<script src="<?= asset('js/chart.umd.js') ?>"></script>
<script>
(function () {

    var DATA = {
        pipeline: { labels: <?= json_encode($pipelineLabels) ?>, data: <?= json_encode($pipelineData) ?> },
        course:   { labels: <?= json_encode($courseLabels)   ?>, full: <?= json_encode($courseFull) ?>, data: <?= json_encode($courseData)   ?> },
        strand:   { labels: <?= json_encode($strandLabels)   ?>, full: <?= json_encode($strandFull) ?>, data: <?= json_encode($strandData)   ?> }
    };

    function v(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }

    var charts = {};

    function buildCharts() {
        Object.values(charts).forEach(function(c) { if (c) c.destroy(); });
        charts = {};

        var radius      = parseInt(v('--radius'), 10) || 8;   // shared radius token
        var accent      = v('--accent');
        var accentLight = v('--accent-light');
        var textPrimary = v('--text-primary');
        var textSecond  = v('--text-secondary');
        var border      = v('--border');
        var radius      = parseFloat(v('--radius')) || 8;
        // Single accent color family for all charts — no rainbow
        var barColor    = accentLight || accent;

        Chart.defaults.font  = { family: v('--font-sans') || 'DM Sans,sans-serif', size: 14 };
        Chart.defaults.color = textSecond;

        // Print the count at the end of every bar so nobody has to hover to read it.
        var valueLabels = { id: 'valueLabels', afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle    = textPrimary;
            ctx.font         = '600 14px ' + (v('--font-sans') || 'DM Sans,sans-serif');
            ctx.textBaseline = 'middle';
            ctx.textAlign    = 'left';
            chart.getDatasetMeta(0).data.forEach(function (bar, i) {
                if (!chart.data.datasets[0].data[i]) return; // hide 0 labels
                ctx.fillText(String(chart.data.datasets[0].data[i]), bar.x + 8, bar.y);
            });
            ctx.restore();
        } };
        function mk(id, cfg) {
            var el = document.getElementById(id);
            if (!el) return null;
            cfg.plugins = [valueLabels];
            cfg.options.layout = { padding: { right: 32 } };
            return new Chart(el, cfg);
        }

        var xGrid = { grid:{color:border}, border:{display:false}, ticks:{color:textSecond, precision:0} };
        var yFlat  = { grid:{display:false}, border:{display:false}, ticks:{color:textPrimary, font:{size:14}} };
        var tip   = { titleFont:{size:14}, bodyFont:{size:14}, callbacks:{ label:function(c){ return ' '+c.raw+' applicants'; } } };
        function fullTip(key){
            return { titleFont:{size:14}, bodyFont:{size:14}, callbacks:{
                title:function(items){ return DATA[key].full[items[0].dataIndex]; },
                label:function(c){ return ' '+c.raw+' applicants'; } } };
        }

        // Pipeline — horizontal bar (same accent, different opacity per stage)
        charts.pipeline = mk('chartPipeline', {
            type: 'bar',
            data: {
                labels: DATA.pipeline.labels,
                datasets: [{ data: DATA.pipeline.data, borderRadius:radius, borderSkipped:false,
                    backgroundColor: DATA.pipeline.data.map(function(){ return barColor; }) }]
            },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:tip },
                scales:{ x:xGrid, y:yFlat } }
        });

        // Course — horizontal bar
        charts.course = mk('chartCourse', {
            type: 'bar',
            data: { labels:DATA.course.labels,
                datasets:[{ data:DATA.course.data, backgroundColor:barColor, borderRadius:radius, borderSkipped:false, barThickness:14, maxBarThickness:16 }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:fullTip('course') },
                scales:{ x:xGrid, y:yFlat } }
        });

        // SHS Strand — same single color
        charts.strand = mk('chartStrand', {
            type: 'bar',
            data: { labels:DATA.strand.labels,
                datasets:[{ data:DATA.strand.data, backgroundColor:barColor, borderRadius:radius, borderSkipped:false }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:fullTip('strand') },
                scales:{ x:xGrid, y:yFlat } }
        });
        var sl=document.getElementById('strandLegend');
        if(sl) sl.innerHTML='';

    }

    buildCharts();

    new MutationObserver(function(ms){
        ms.forEach(function(m){ if(m.attributeName==='data-theme') buildCharts(); });
    }).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});

    // Date picker
    var BASE='<?= url('/admin/dashboard') ?>';
    var SY='<?= $schoolYear !== $currentSY ? rawurlencode($schoolYear) : '' ?>';
    var SYQ=SY ? '&sy='+SY : '';
    window.dpToggle=function(e){ e.stopPropagation(); document.getElementById('dpMenu').classList.toggle('open'); };
    window.dpSet   =function(r){ window.location.href=BASE+'?range='+r+SYQ; };
    window.dpCustom=function() { document.getElementById('dpCustomRow').classList.add('show'); };
    window.dpApply =function() {
        var f=document.getElementById('dpFrom').value, t=document.getElementById('dpTo').value;
        if(f&&t) window.location.href=BASE+'?range=custom&from='+encodeURIComponent(f)+'&to='+encodeURIComponent(t)+SYQ;
    };
    document.addEventListener('click',function(e){
        var w=document.getElementById('dpWrap');
        if(w&&!w.contains(e.target)) document.getElementById('dpMenu').classList.remove('open');
    });

})();
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';