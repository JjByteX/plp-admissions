<?php
// ============================================================
// modules/auth/admin/dashboard.php
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$pdo        = db();
$schoolYear = school_setting('current_school_year');

// ── Date range ────────────────────────────────────────────────────
$validRanges = ['today','yesterday','this-week','last-week','this-month','last-month','this-year','last-year','custom'];
$range    = in_array($_GET['range'] ?? '', $validRanges) ? $_GET['range'] : 'this-year';
$fromDate = isset($_GET['from']) ? preg_replace('/[^0-9\-]/', '', $_GET['from']) : null;
$toDate   = isset($_GET['to'])   ? preg_replace('/[^0-9\-]/', '', $_GET['to'])   : null;

$dateFilter = '';
$dateExtra  = [];
switch ($range) {
    case 'today':
        $dateFilter = 'AND DATE(a.created_at) = CURDATE()'; break;
    case 'yesterday':
        $dateFilter = 'AND DATE(a.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)'; break;
    case 'this-week':
        $dateFilter = 'AND YEARWEEK(a.created_at, 1) = YEARWEEK(CURDATE(), 1)'; break;
    case 'last-week':
        $dateFilter = 'AND YEARWEEK(a.created_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)'; break;
    case 'this-month':
        $dateFilter = 'AND YEAR(a.created_at) = YEAR(CURDATE()) AND MONTH(a.created_at) = MONTH(CURDATE())'; break;
    case 'last-month':
        $dateFilter = 'AND YEAR(a.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(a.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))'; break;
    case 'last-year':
        $dateFilter = 'AND YEAR(a.created_at) = YEAR(CURDATE()) - 1'; break;
    case 'custom':
        if ($fromDate && $toDate) {
            $dateFilter = 'AND DATE(a.created_at) BETWEEN ? AND ?';
            $dateExtra  = [$fromDate, $toDate];
        }
        break;
    default:
        $dateFilter = 'AND YEAR(a.created_at) = YEAR(CURDATE())';
}

// ── CSV export (early exit) ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvStmt = $pdo->prepare("
        SELECT
            u.name,
            u.email,
            u.sex,
            TIMESTAMPDIFF(YEAR, u.birthdate, CURDATE())                                      AS age,
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(u.address, 'Brgy. ', -1), ',', 1))         AS barangay,
            a.applicant_type,
            a.course_applied,
            a.overall_status,
            a.school_year,
            COALESCE(ar.result, 'pending')                                                   AS result,
            ar.released_at,
            a.created_at
        FROM applicants a
        JOIN  users u              ON u.id = a.user_id
        LEFT JOIN admission_results ar ON ar.applicant_id = a.id
        WHERE a.school_year = ? $dateFilter
        ORDER BY a.created_at DESC
    ");
    $csvStmt->execute(array_merge([$schoolYear], $dateExtra));
    $rows = $csvStmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plp-admissions-' . $schoolYear . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name','Email','Sex','Age','Barangay','Type','Course','Stage','School Year','Result','Released At','Applied At']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

// ── Stats ──────────────────────────────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*)                              AS total,
        SUM(ar.result = 'accepted')           AS accepted,
        SUM(ar.result = 'rejected')           AS rejected,
        SUM(ar.result = 'waitlisted')         AS waitlisted,
        SUM(a.overall_status = 'released')    AS released
    FROM applicants a
    LEFT JOIN admission_results ar ON ar.applicant_id = a.id
    WHERE a.school_year = ? $dateFilter
");
$statsStmt->execute(array_merge([$schoolYear], $dateExtra));
$t = $statsStmt->fetch(PDO::FETCH_ASSOC);

$total         = (int)$t['total'];
$accepted      = (int)$t['accepted'];
$rejected      = (int)$t['rejected'];
$waitlisted    = (int)$t['waitlisted'];
$released      = (int)$t['released'];
$acceptedPct   = $released > 0 ? round($accepted   / $released * 100, 1) : 0;
$rejectedPct   = $released > 0 ? round($rejected   / $released * 100, 1) : 0;
$waitlistedPct = $released > 0 ? round($waitlisted / $released * 100, 1) : 0;
$completionPct = $total > 0    ? round($released   / $total    * 100)    : 0;

// ── Pipeline ───────────────────────────────────────────────────────
$pipelineStmt = $pdo->prepare("
    SELECT overall_status, COUNT(*) AS cnt
    FROM applicants a
    WHERE a.school_year = ? $dateFilter
    GROUP BY overall_status
");
$pipelineStmt->execute(array_merge([$schoolYear], $dateExtra));
$pipelineMap    = array_column($pipelineStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'overall_status');
$pipelineOrder  = ['pending','documents','exam','interview','released'];
$pipelineLabels = ['Pending','Documents','Exam','Interview','Released'];
$pipelineData   = array_map(fn($s) => (int)($pipelineMap[$s] ?? 0), $pipelineOrder);

// ── Course — all PLP courses, 0 if no data ─────────────────────────
$courseCountsStmt = $pdo->prepare("
    SELECT a.course_applied AS label, COUNT(*) AS cnt
    FROM applicants a
    WHERE a.school_year = ? $dateFilter
    GROUP BY a.course_applied
");
$courseCountsStmt->execute(array_merge([$schoolYear], $dateExtra));
$courseCountsMap = array_column($courseCountsStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'label');

$courseLabels = PLP_COURSES;
$courseData   = array_map(fn($c) => (int)($courseCountsMap[$c] ?? 0), $courseLabels);

// ── SHS Strand — all strands from config, 0 if no data ────────────
$strandCountsStmt = $pdo->prepare("
    SELECT a.shs_strand AS label, COUNT(*) AS cnt
    FROM applicants a
    WHERE a.school_year = ? $dateFilter
      AND a.shs_strand IS NOT NULL AND a.shs_strand != ''
    GROUP BY a.shs_strand
");
$strandCountsStmt->execute(array_merge([$schoolYear], $dateExtra));
$strandCountsMap = array_column($strandCountsStmt->fetchAll(PDO::FETCH_ASSOC), 'cnt', 'label');

// Use full labels from config; keys are the DB values
$strandLabels = [];
$strandData   = [];
foreach (SHS_STRANDS as $key => $label) {
    $strandLabels[] = $label;
    $strandData[]   = (int)($strandCountsMap[$key] ?? 0);
}

// ── Sex ────────────────────────────────────────────────────────────
$sexStmt = $pdo->prepare("
    SELECT u.sex, COUNT(*) AS cnt
    FROM applicants a JOIN users u ON u.id = a.user_id
    WHERE a.school_year = ? $dateFilter AND u.sex IN ('M','F')
    GROUP BY u.sex
");
$sexStmt->execute(array_merge([$schoolYear], $dateExtra));
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
    'range' => $range, 'from' => $fromDate, 'to' => $toDate, 'export' => 'csv'
]));

// Donut ring math  r=24, cx=cy=29, viewBox 58×58
$circ    = round(2 * M_PI * 24, 2); // ≈ 150.80
$dashOff = round($circ * (1 - $completionPct / 100), 2);

$courseCount = count($courseLabels);

ob_start();
?>

<style>
/* Wider sidebar for admin dashboard */
.sidebar { width: 272px; }

/* ── All UI colors from app CSS variables ─────────────────── */

.db-header {
    display:flex; align-items:flex-start; justify-content:space-between;
    flex-wrap:wrap; gap:var(--space-3); margin-bottom:var(--space-6);
}
.db-heading {
    font-size:var(--text-xl); font-weight:var(--weight-semibold);
    color:var(--text-primary); margin:0 0 var(--space-1); letter-spacing:-0.2px;
}
.db-sub      { font-size:var(--text-xs); color:var(--text-tertiary); margin:0; }
.db-controls { display:flex; align-items:center; gap:var(--space-2); flex-wrap:wrap; }

/* ── 3-column grid: 30% | 40% | 30%
   Col1 and Col2 are plain flex stacks — no row-spanning.
   ───────────────────────────────────── */
.db-grid {
    display:grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: auto auto;
    gap:var(--space-4);
    align-items:start;
}

/* KPI card — single column stack */
.db-kpi-grid { display:flex; flex-direction:column; gap:var(--space-2); margin-top:var(--space-3); }
.db-kpi-item { background:var(--bg-subtle); border-radius:var(--radius-md); padding:var(--space-2) var(--space-3); display:flex; align-items:baseline; justify-content:space-between; gap:var(--space-3); }
.db-kpi-val  { font-size:var(--text-xl); font-weight:var(--weight-semibold); letter-spacing:-0.03em; line-height:1; color:var(--text-primary); flex-shrink:0; }
.db-kpi-val--success { color:var(--success); }
.db-kpi-val--error   { color:var(--error);   }
.db-kpi-val--warning { color:var(--warning); }
.db-kpi-lbl  { font-size:var(--text-xs); color:var(--text-tertiary); text-transform:uppercase; letter-spacing:.04em; }
.db-kpi-sub  { display:none; }

/* Pipeline donut (SVG) */
.db-donut-wrap { position:relative; width:110px; height:110px; flex-shrink:0; }
.db-donut      { width:110px; height:110px; }
.db-donut-bg   { stroke:var(--bg-subtle); }
.db-donut-fill { stroke:var(--accent); stroke-linecap:round; transition:stroke-dashoffset .5s ease; }
.db-donut-lbl  { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; line-height:1; gap:2px; }
.db-donut-pct  { font-size:18px; font-weight:var(--weight-semibold); color:var(--text-primary); }
.db-donut-sub  { font-size:10px; color:var(--text-tertiary); text-transform:uppercase; letter-spacing:.05em; }

/* Card chart header */
.db-ch     { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:var(--space-4); }
.db-ch-sub { font-size:var(--text-xs); color:var(--text-tertiary); }

/* Legend */
.db-legend      { display:flex; flex-wrap:wrap; gap:var(--space-3); margin-top:var(--space-3); justify-content:center; }
.db-legend-item { display:flex; align-items:center; gap:var(--space-2); font-size:var(--text-xs); color:var(--text-secondary); }
.db-legend-dot  { width:8px; height:8px; border-radius:2px; flex-shrink:0; }


/* Date picker */
.dp-wrap { position:relative; }
.dp-menu {
    display:none; position:absolute; right:0; top:calc(100% + var(--space-1));
    min-width:176px; background:var(--bg-elevated); border:1px solid var(--border);
    border-radius:var(--radius-md); box-shadow:var(--shadow-md);
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
    flex:1; padding:var(--space-1) var(--space-2); font-size:var(--text-xs);
    font-family:var(--font-sans); border:1px solid var(--border);
    border-radius:var(--radius-sm); background:var(--bg-subtle); color:var(--text-primary); min-width:0;
}

@media (max-width:960px) {
    .db-grid { grid-template-columns:1fr; }
}
@media (max-width:560px) {
    .db-grid { grid-template-columns:1fr; }
}
</style>

<div>

    <!-- ── Header ───────────────────────────────────────────────── -->
    <div class="db-header">
        <div>
            <h1 class="db-heading">Admissions dashboard</h1>
            <p class="db-sub">Pamantasan ng Lungsod ng Pasig &middot; SY <?= e($schoolYear) ?></p>
        </div>
        <div class="db-controls">

            <div class="dp-wrap" id="dpWrap">
                <button class="btn btn-secondary btn-sm" onclick="dpToggle(event)" type="button">
                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                        <rect x="1" y="2.5" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.3"/>
                        <path d="M5 1v3M11 1v3M1 7h14" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    </svg>
                    <span id="dpLabel"><?= e($rangeLabel) ?></span>
                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                        <path d="M2 3.5l3 3 3-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="dp-menu" id="dpMenu">
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
                        <input type="date" class="dp-date-in" id="dpFrom" value="<?= e($fromDate ?? '') ?>">
                        <input type="date" class="dp-date-in" id="dpTo"   value="<?= e($toDate   ?? '') ?>">
                        <button class="btn btn-primary" style="padding:var(--space-1) var(--space-3);font-size:var(--text-xs)" onclick="dpApply()">Go</button>
                    </div>
                </div>
            </div>

            <a class="btn btn-secondary btn-sm" href="<?= $exportUrl ?>">
                <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                    <path d="M3 12.5h10M8 1.5v8M5 6.5l3 3 3-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
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
                <span style="font-size:var(--text-xs);color:var(--text-tertiary);"><?= e($rangeLabel) ?></span>
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
                    <div class="db-kpi-sub"><?= $acceptedPct ?>% of released</div>
                </div>
                <div class="db-kpi-item">
                    <div class="db-kpi-val db-kpi-val--error"><?= number_format($rejected) ?></div>
                    <div class="db-kpi-lbl">Rejected</div>
                    <div class="db-kpi-sub"><?= $rejectedPct ?>% of released</div>
                </div>
                <div class="db-kpi-item">
                    <div class="db-kpi-val db-kpi-val--warning"><?= number_format($waitlisted) ?></div>
                    <div class="db-kpi-lbl">Waitlisted</div>
                    <div class="db-kpi-sub"><?= $waitlistedPct ?>% of released</div>
                </div>
            </div>
        </div>

        <!-- Cell 2 — Pipeline -->
        <div class="card db-c-pipeline" style="padding:var(--space-5);">
            <div class="db-ch">
                <span class="card-title">Pipeline</span>
                <span class="db-ch-sub"><?= number_format($total) ?> total</span>
            </div>
            <div style="display:flex;gap:0;align-items:center;">
                <!-- Chart -->
                <div style="flex:1;min-width:0;padding-right:var(--space-4);">
                    <div style="position:relative;width:100%;height:<?= count($pipelineOrder) * 30 + 56 ?>px;">
                        <canvas id="chartPipeline"></canvas>
                    </div>
                </div>
                <!-- Divider -->
                <div style="width:1px;background:var(--border);flex-shrink:0;align-self:stretch;"></div>
                <!-- Donut — flex:1 mirrors the Sex side below -->
                <div style="flex:1;min-width:0;padding-left:var(--space-4);display:flex;align-items:center;justify-content:center;">
                    <div class="db-donut-wrap" title="<?= $completionPct ?>% released">
                        <svg class="db-donut" viewBox="0 0 58 58">
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
            <div style="position:relative;width:100%;height:<?= max(120, $courseCount * 28 + 20) ?>px;">
                <canvas id="chartCourse"></canvas>
            </div>
        </div>

        <!-- Cell 4 — SHS Strand -->
        <div class="card db-c-strand" style="padding:var(--space-5);">
            <div class="db-ch">
                <span class="card-title">SHS Strand</span>
                <span class="db-ch-sub">by strand</span>
            </div>
            <div style="position:relative;width:100%;height:<?= max(240, count($strandLabels) * 26 + 20) ?>px;">
                <canvas id="chartStrand"></canvas>
            </div>
            <div class="db-legend" id="strandLegend"></div>
        </div>




    </div><!-- /db-grid -->

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
(function () {

    var DATA = {
        pipeline: { labels: <?= json_encode($pipelineLabels) ?>, data: <?= json_encode($pipelineData) ?> },
        course:   { labels: <?= json_encode($courseLabels)   ?>, data: <?= json_encode($courseData)   ?> },
        strand:   { labels: <?= json_encode($strandLabels)   ?>, data: <?= json_encode($strandData)   ?> }
    };

    function v(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }

    var charts = {};

    function buildCharts() {
        Object.values(charts).forEach(function(c) { if (c) c.destroy(); });
        charts = {};

        var accent  = v('--accent');
        var success = v('--success');
        var warning = v('--warning');
        var error   = v('--error');
        var info    = v('--info');
        var muted   = v('--text-tertiary');
        var border  = v('--border');
        var elev    = v('--bg-elevated');
        var strandColors = [accent, info, '#7c3aed', warning, error, success, '#f97316', '#06b6d4', '#84cc16', '#ec4899'];

        Chart.defaults.font  = { family: v('--font-sans') || 'DM Sans,sans-serif', size: 11 };
        Chart.defaults.color = muted;

        var xGrid = { grid:{color:border}, border:{display:false}, ticks:{color:muted, precision:0} };
        var yFlat = { grid:{display:false}, border:{display:false}, ticks:{color:muted} };
        var tip   = { callbacks:{ label:function(c){ return ' '+c.raw+' applicants'; } } };

        // Pipeline — horizontal bar
        charts.pipeline = new Chart(document.getElementById('chartPipeline'), {
            type: 'bar',
            data: {
                labels: DATA.pipeline.labels,
                datasets: [{ data: DATA.pipeline.data, borderRadius:5, borderSkipped:false,
                    backgroundColor: [v('--border-strong'), info, warning, '#7c3aed', success] }]
            },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:tip },
                scales:{ x:xGrid, y:yFlat } }
        });

        // Course — horizontal bar
        charts.course = new Chart(document.getElementById('chartCourse'), {
            type: 'bar',
            data: { labels:DATA.course.labels,
                datasets:[{ data:DATA.course.data, backgroundColor:accent, borderRadius:5, borderSkipped:false }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:tip },
                scales:{ x:xGrid, y:yFlat } }
        });

        // SHS Strand — horizontal bar (all strands, including 0)
        var strandBg = DATA.strand.labels.map(function(_,i){ return strandColors[i%strandColors.length]; });
        charts.strand = new Chart(document.getElementById('chartStrand'), {
            type: 'bar',
            data: { labels:DATA.strand.labels,
                datasets:[{ data:DATA.strand.data, backgroundColor:strandBg, borderRadius:5, borderSkipped:false }] },
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){
                    return ' '+c.raw+' applicants';
                }}}},
                scales:{ x:xGrid, y:yFlat } }
        });
        var sl=document.getElementById('strandLegend');
        if(sl) sl.innerHTML='';

        // Sex — donut
        var fc=success, mc=info;
        var df=document.getElementById('dotFemale'), dm=document.getElementById('dotMale');
        if(df) df.style.background=fc;
        if(dm) dm.style.background=mc;
    }

    buildCharts();

    new MutationObserver(function(ms){
        ms.forEach(function(m){ if(m.attributeName==='data-theme') buildCharts(); });
    }).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});

    // Date picker
    var BASE='<?= url('/admin/dashboard') ?>';
    window.dpToggle=function(e){ e.stopPropagation(); document.getElementById('dpMenu').classList.toggle('open'); };
    window.dpSet   =function(r){ window.location.href=BASE+'?range='+r; };
    window.dpCustom=function() { document.getElementById('dpCustomRow').classList.add('show'); };
    window.dpApply =function() {
        var f=document.getElementById('dpFrom').value, t=document.getElementById('dpTo').value;
        if(f&&t) window.location.href=BASE+'?range=custom&from='+encodeURIComponent(f)+'&to='+encodeURIComponent(t);
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