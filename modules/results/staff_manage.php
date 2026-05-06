<?php
// ============================================================
// modules/results/staff_manage.php
// M6 — Staff: release admission results
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();

$search    = trim($_GET['q']      ?? '');
$filterRes = $_GET['result']      ?? '';  // accepted|waitlisted|rejected|pending|withdrawn|''
$sortCol   = $_GET['sort_col']    ?? 'updated';
$sortDir   = strtolower($_GET['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page      = max(1, (int)($_GET['page'] ?? 1));

// ── WHERE builder ─────────────────────────────────────────────
$where  = ["a.overall_status IN ('released','exam','interview','withdrawn')"];
$params = [];

if ($search) {
    $where[]      = '(u.name LIKE :q OR u.email LIKE :q OR a.course_applied LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

if ($filterRes === 'withdrawn') {
    $where[] = "a.overall_status = 'withdrawn'";
} elseif ($filterRes === 'pending') {
    $where[] = 'ar.result IS NULL';
    $where[] = "a.overall_status != 'withdrawn'";
} elseif ($filterRes) {
    $where[]           = 'ar.result = :result';
    $params[':result'] = $filterRes;
    $where[]           = "a.overall_status != 'withdrawn'";
}

$whereStr = implode(' AND ', $where);

// ── Counts for filter tabs ────────────────────────────────────
$countRows = $db->query(
    "SELECT
       SUM(CASE WHEN a.overall_status = 'withdrawn' THEN 1 ELSE 0 END)                         AS withdrawn_count,
       SUM(CASE WHEN a.overall_status != 'withdrawn' AND ar.result IS NULL THEN 1 ELSE 0 END)  AS pending_count,
       SUM(CASE WHEN ar.result = 'accepted'   AND a.overall_status != 'withdrawn' THEN 1 ELSE 0 END) AS accepted_count,
       SUM(CASE WHEN ar.result = 'waitlisted' AND a.overall_status != 'withdrawn' THEN 1 ELSE 0 END) AS waitlisted_count,
       SUM(CASE WHEN ar.result = 'rejected'   AND a.overall_status != 'withdrawn' THEN 1 ELSE 0 END) AS rejected_count,
       COUNT(*) AS total_count
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     WHERE a.overall_status IN ('released','exam','interview','withdrawn')"
)->fetch(PDO::FETCH_ASSOC);

// ── Sort column map ───────────────────────────────────────────
$colMap   = [
    'applicant' => 'u.name',
    'course'    => 'a.course_applied',
    'result'    => 'ar.result',
    'released'  => 'ar.released_at',
    'updated'   => 'a.updated_at',
];
$orderCol = $colMap[$sortCol] ?? 'a.updated_at';
$orderDir = strtoupper($sortDir);

// ── Paginate ──────────────────────────────────────────────────
$result = paginate(
    $db,
    "SELECT COUNT(*)
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            ar.result AS admission_result, ar.remarks AS admission_remarks, ar.released_at,

            ar.promoted_from_waitlist,
            er.score  AS exam_score, er.total_items AS exam_total,
            er.rank_score AS exam_rank, er.passed AS exam_passed,
            iq.status AS interview_status, iq.interview_notes
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE $whereStr
     ORDER BY $orderCol $orderDir",
    $params, $page, 25
);

// ── Filter URL helper ─────────────────────────────────────────
function filterUrl(array $merge = []): string {
    global $search, $filterRes, $sortCol, $sortDir;
    $base = ['q' => $search, 'result' => $filterRes, 'sort_col' => $sortCol, 'sort_dir' => $sortDir, 'page' => 1];
    return '?' . http_build_query(array_merge($base, $merge));
}

function results_sortable_th(string $col, string $label, string $currentCol, string $currentDir, string $search, string $filterRes): string {
    $isActive = ($currentCol === $col);
    $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $base = ['q' => $search, 'result' => $filterRes, 'sort_col' => $col, 'sort_dir' => $isActive ? $nextDir : 'asc', 'page' => 1];
    $url = '?' . http_build_query($base);
    $sortIcon  = icon('ic_fluent_chevron_up_down_24_filled', 13);
    $sortColor = $isActive ? 'var(--accent)' : 'var(--text-tertiary)';
    return '<th><a href="' . $url . '" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:inherit;white-space:nowrap;">'
         . htmlspecialchars($label)
         . '<span style="color:' . $sortColor . ';display:flex;align-items:center;margin-left:2px;">' . $sortIcon . '</span>'
         . '</a></th>';
}

ob_start();
?>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('info')): ?>
    <div class="alert alert-info" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ── Auto Actions ─────────────────────────────── -->
<div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-4);gap:var(--space-3)">
    <form method="POST" action="<?= url('/staff/results/auto-release') ?>" style="margin:0">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm"
                onclick="return confirm('Auto-release results for all eligible applicants based on configured score thresholds?\n\nThis will accept, waitlist, or reject applicants who have completed both exam and interview.')">
            <?= icon('ic_fluent_ribbon_star_24_regular', 14) ?>
            Auto-Release Results
        </button>
    </form>
</div>

<!-- ============================================================
     TOP BAR: Tabs (left) + Search (right)
============================================================ -->
<div style="
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:var(--space-4);
    margin-bottom:var(--space-5);
    border-bottom:1px solid var(--border);
    flex-wrap:wrap;
">
    <!-- Result status tabs -->
    <div style="display:flex;gap:var(--space-1)">
        <?php
        $tabs = [
            ''           => ['label' => 'All',       'count' => (int)$countRows['total_count']],
            'pending'    => ['label' => 'Pending',   'count' => (int)$countRows['pending_count']],
            'accepted'   => ['label' => 'Accepted',  'count' => (int)$countRows['accepted_count']],
            'waitlisted' => ['label' => 'Waitlisted','count' => (int)$countRows['waitlisted_count']],
            'rejected'   => ['label' => 'Rejected',  'count' => (int)$countRows['rejected_count']],
            'withdrawn'  => ['label' => 'Withdrawn', 'count' => (int)$countRows['withdrawn_count']],
        ];
        foreach ($tabs as $val => $tab):
            $active = ($filterRes === $val);
        ?>
            <a href="<?= filterUrl(['result' => $val]) ?>"
               style="
                   padding:var(--space-2) var(--space-4);
                   border-bottom:2px solid <?= $active ? 'var(--accent)' : 'transparent' ?>;
                   color:<?= $active ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                   font-size:var(--text-sm);
                   font-weight:<?= $active ? 'var(--weight-semibold)' : 'var(--weight-regular)' ?>;
                   white-space:nowrap;text-decoration:none;margin-bottom:-1px;
                   transition:color var(--transition-fast);
               ">
                <?= $tab['label'] ?>
                <span style="margin-left:4px;font-size:var(--text-xs);color:var(--text-tertiary)"><?= $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Search + Filter (right) -->
    <form method="GET" style="display:flex;align-items:center;gap:var(--space-2);padding-bottom:var(--space-1);flex-shrink:0">
        <input type="hidden" name="result" value="<?= e($filterRes) ?>">
        <input type="hidden" name="sort_col" value="<?= e($sortCol) ?>">
        <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">

        <!-- Search input -->
        <div style="position:relative">
            <?= icon('ic_fluent_search_24_filled', 14, 'position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);pointer-events:none') ?>
            <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                   style="padding:0 var(--space-3) 0 32px;height:32px;min-height:32px;font-size:var(--text-sm);width:220px;border-radius:var(--radius-sm)"
                   placeholder="Search name, email, course…">
        </div>

        <!-- Filter dropdown -->
        <div style="position:relative" id="results-filter-wrapper">
            <button type="button" id="results-filter-btn" onclick="toggleResultsFilter()" style="
                display:flex;align-items:center;gap:var(--space-2);
                height:32px;padding:0 var(--space-3);
                border:1px solid var(--border);border-radius:var(--radius-sm);
                background:var(--bg-elevated);color:var(--text-secondary);
                font-size:var(--text-sm);cursor:pointer;white-space:nowrap;
                transition:border-color var(--transition-fast),color var(--transition-fast);
            " aria-haspopup="true" aria-expanded="false">
                <?= icon('ic_fluent_filter_24_filled', 14) ?>
                Filter
                <?php if ($filterRes): ?>
                    <span style="
                        display:inline-flex;align-items:center;justify-content:center;
                        width:16px;height:16px;border-radius:50%;
                        background:var(--accent);color:var(--accent-text);
                        font-size:10px;font-weight:var(--weight-semibold);
                    ">1</span>
                <?php endif; ?>
            </button>

            <div id="results-filter-dropdown" style="
                display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:200;
                background:var(--bg-elevated);border:1px solid var(--border);
                border-radius:var(--radius-md);box-shadow:var(--shadow-md);
                min-width:220px;padding:var(--space-3);
            ">
                <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-2);padding:0 var(--space-1)">Result Status</div>
                <?php foreach ($tabs as $val => $tab):
                    $isActive = ($filterRes === $val);
                ?>
                <a href="<?= filterUrl(['result' => $val]) ?>" style="
                    display:flex;align-items:center;justify-content:space-between;
                    padding:var(--space-2) var(--space-3);border-radius:var(--radius-sm);
                    background:<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>;
                    color:<?= $isActive ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                    font-size:var(--text-sm);
                    font-weight:<?= $isActive ? 'var(--weight-semibold)' : 'var(--weight-regular)' ?>;
                    text-decoration:none;
                    transition:background var(--transition-fast);
                " onmouseover="if(!this.style.background.includes('accent-muted')) this.style.background='var(--bg-overlay)'"
                   onmouseout="this.style.background='<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>'">
                    <?= $tab['label'] ?>
                    <span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= $tab['count'] ?></span>
                    <?php if ($isActive): ?>
                        <?= icon('ic_fluent_checkmark_24_regular', 13) ?>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php if ($filterRes): ?>
                    <div style="border-top:1px solid var(--border);margin-top:var(--space-2);padding-top:var(--space-2)">
                        <a href="<?= filterUrl(['result' => '']) ?>" style="
                            display:flex;align-items:center;gap:var(--space-2);
                            padding:var(--space-2) var(--space-3);font-size:var(--text-sm);
                            color:var(--text-tertiary);border-radius:var(--radius-sm);text-decoration:none;
                            transition:background var(--transition-fast);
                        " onmouseover="this.style.background='var(--bg-overlay)'" onmouseout="this.style.background='transparent'">
                            <?= icon('ic_fluent_dismiss_24_regular', 13) ?>
                            Clear filter
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" style="display:none" aria-hidden="true"></button>
    </form>
</div>

<script>
(function(){
    window.toggleResultsFilter = function() {
        var dd   = document.getElementById('results-filter-dropdown');
        var btn  = document.getElementById('results-filter-btn');
        var chev = document.getElementById('results-filter-chevron');
        var open = dd.style.display === 'block';
        dd.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', String(!open));
        chev.style.transform = open ? '' : 'rotate(180deg)';
    };
    document.addEventListener('click', function(e) {
        var w = document.getElementById('results-filter-wrapper');
        if (w && !w.contains(e.target)) {
            var dd   = document.getElementById('results-filter-dropdown');
            var btn  = document.getElementById('results-filter-btn');
            var chev = document.getElementById('results-filter-chevron');
            if (dd)   dd.style.display = 'none';
            if (btn)  btn.setAttribute('aria-expanded','false');
            if (chev) chev.style.transform = '';
        }
    });
})();
</script>

<!-- ── Results table ──────────────────────────────────────── -->
<div class="card" style="padding:0;overflow:hidden">
    <table class="table" id="results-table">
        <thead>
            <tr>
                <th style="width:40px;padding-left:var(--space-3)">
                    <input type="checkbox" id="res-select-all" onchange="resToggleAll(this)"
                           style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                </th>
                <?= results_sortable_th('applicant', 'Applicant',   $sortCol, $sortDir, $search, $filterRes) ?>
                <?= results_sortable_th('course',    'Course',      $sortCol, $sortDir, $search, $filterRes) ?>
                <th>Exam Score</th>
                <th>Interview</th>
                <?= results_sortable_th('result',    'Result',      $sortCol, $sortDir, $search, $filterRes) ?>
                <?= results_sortable_th('released',  'Released',    $sortCol, $sortDir, $search, $filterRes) ?>
                <th style="width:100px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['data'])): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--text-tertiary);padding:var(--space-8)">No applicants found.</td></tr>
        <?php else: ?>
            <?php foreach ($result['data'] as $row):
                $pct = null;
                if ($row['exam_score'] !== null && (int)$row['exam_total'] > 0) {
                    $pct = round(($row['exam_score'] / $row['exam_total']) * 100);
                }
                $isWithdrawn = ($row['overall_status'] === 'withdrawn');
            ?>
                <tr class="res-bulk-row" data-id="<?= (int)$row['id'] ?>">
                    <td style="padding-left:var(--space-3)">
                        <?php if (!$isWithdrawn): ?>
                        <input type="checkbox" class="res-check" value="<?= (int)$row['id'] ?>"
                               onchange="resUpdateSelection()"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:var(--weight-medium)"><?= e($row['student_name']) ?></div>
                        <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= e($row['email']) ?></div>
                        <div style="margin-top:2px">
                            <span class="badge badge-<?= $row['overall_status'] ?>"><?= e(ucfirst(str_replace('_',' ',$row['overall_status']))) ?></span>
                        </div>
                    </td>

                    <td style="font-size:var(--text-sm)"><?= e($row['course_applied']) ?></td>

                    <!-- Exam score -->
                    <td>
                        <?php if ($row['exam_score'] !== null): ?>
                            <?php
                                $rank     = $row['exam_rank'] > 0 ? (int)$row['exam_rank']
                                            : score_to_rank((int)$row['exam_score'], (int)($row['exam_total'] ?: 1));
                                $tierInfo = rank_tier_info($rank);
                                $passed   = $row['exam_passed'] !== null
                                            ? (bool)$row['exam_passed']
                                            : exam_passed((int)$row['exam_score'], (int)($row['exam_total'] ?: 1), $row['course_applied']);
                            ?>
                            <div style="display:flex;align-items:center;gap:var(--space-2)">
                                <div style="width:32px;height:32px;border-radius:50%;
                                            background:<?= $tierInfo['bg'] ?>;
                                            border:2px solid <?= $tierInfo['color'] ?>;
                                            display:flex;align-items:center;justify-content:center;
                                            font-weight:var(--weight-semibold);font-size:var(--text-sm);
                                            color:<?= $tierInfo['color'] ?>;flex-shrink:0">
                                    <?= $rank ?>
                                </div>
                                <div>
                                    <div style="font-size:var(--text-xs);font-weight:var(--weight-medium)">
                                        <?= (int)$row['exam_score'] ?>/<?= (int)$row['exam_total'] ?>
                                        <span style="color:var(--text-tertiary)">(<?= $pct ?>%)</span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
                                        <span style="font-size:10px;font-weight:var(--weight-semibold);color:<?= $tierInfo['color'] ?>"><?= $tierInfo['label'] ?></span>
                                        <span style="font-size:10px;color:var(--text-tertiary)">·</span>
                                        <?php if ($passed): ?>
                                            <span style="font-size:10px;color:var(--success);font-weight:var(--weight-semibold)"><?= icon('ic_fluent_checkmark_24_regular', 10) ?> Passed</span>
                                        <?php else: ?>
                                            <span style="font-size:10px;color:var(--error);font-weight:var(--weight-semibold)"><?= icon('ic_fluent_dismiss_24_regular', 10) ?> Failed</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!$passed && $row['overall_status'] !== 'withdrawn'): ?>
                                <?php $alts = suggest_alt_courses((int)$row['exam_score'], (int)($row['exam_total'] ?: 1), $row['course_applied']); ?>
                                <?php if (!empty($alts)): ?>
                                <button class="btn btn-ghost btn-sm" style="margin-top:var(--space-1);font-size:10px;padding:2px 8px;color:var(--warning)"
                                        onclick="openSuggestModal(
                                            <?= $row['id'] ?>,
                                            <?= htmlspecialchars(json_encode($row['student_name']), ENT_QUOTES) ?>,
                                            <?= htmlspecialchars(json_encode($alts), ENT_QUOTES) ?>,
                                            <?= $rank ?>)">
                                    💡 Suggest course
                                </button>
                                <?php else: ?>
                                <div style="font-size:10px;color:var(--text-tertiary);margin-top:4px">No alt. courses available</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Interview status -->
                    <td>
                        <?php if ($row['interview_status']): ?>
                            <?php
                                $iMap = [
                                    'scheduled'   => ['badge-uploaded',    'Scheduled'],
                                    'checked_in'  => ['badge-uploaded',    'Checked In'],
                                    'in_progress' => ['badge-review',  'In Progress'],
                                    'completed'   => ['badge-approved','Completed'],
                                    'no_show'     => ['badge-rejected','No-show'],
                                ];
                                [$ibadge, $ilabel] = $iMap[$row['interview_status']] ?? ['badge-pending', ucfirst($row['interview_status'])];
                            ?>
                            <span class="badge <?= $ibadge ?>"><?= $ilabel ?></span>
                            <?php if ($row['interview_notes']): ?>
                                <div style="font-size:var(--text-xs);color:var(--text-tertiary);
                                             margin-top:var(--space-1);max-width:180px;
                                             white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                                     title="<?= e($row['interview_notes']) ?>">
                                    <?= e($row['interview_notes']) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Admission result -->
                    <td>
                        <?php if ($row['overall_status'] === 'withdrawn'): ?>
                            <span class="badge" style="color:#6b7280;background:#f3f4f6">Withdrawn</span>
                            <?php if (!empty($row['withdrawn_at'])): ?>
                                <div style="font-size:10px;color:var(--text-tertiary);margin-top:2px">
                                    <?= format_date($row['withdrawn_at'], 'M j, Y') ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($row['admission_result']): ?>
                            <span class="badge badge-<?= $row['admission_result'] ?>">
                                <?= e(RESULT_LABELS[$row['admission_result']]) ?>
                            </span>
                            <?php if ($row['promoted_from_waitlist']): ?>
                                <div style="font-size:10px;color:var(--accent);margin-top:2px">↑ Promoted from waitlist</div>
                            <?php endif; ?>
                            <?php if ($row['admission_remarks']): ?>
                                <div style="font-size:var(--text-xs);color:var(--text-tertiary);
                                             margin-top:var(--space-1);max-width:160px;
                                             white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                                     title="<?= e($row['admission_remarks']) ?>">
                                    <?= e($row['admission_remarks']) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">Pending</span>
                        <?php endif; ?>
                    </td>

                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?= $row['released_at'] ? format_date($row['released_at'], 'M j, Y') : '—' ?>
                    </td>

                    <td>
                        <?php if ($row['overall_status'] === 'withdrawn'): ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">Withdrawn</span>
                        <?php elseif ($row['admission_result'] === 'accepted'): ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">Accepted</span>
                        <?php elseif ($row['admission_result'] === 'rejected'): ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">Rejected</span>
                        <?php else: ?>
                            <div style="display:flex;gap:var(--space-2)">
                                <form method="POST" action="<?= url('/staff/results/' . $row['id']) ?>" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="result" value="accepted">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:var(--success);color:#fff;border-color:var(--success);font-size:var(--text-xs)"
                                            onclick="return confirm('Approve <?= e(addslashes($row['student_name'])) ?>?')">
                                        Approve
                                    </button>
                                </form>
                                <form method="POST" action="<?= url('/staff/results/' . $row['id']) ?>" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="result" value="rejected">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            style="font-size:var(--text-xs)"
                                            onclick="return confirm('Reject <?= e(addslashes($row['student_name'])) ?>?')">
                                        Reject
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($result['last_page'] > 1): ?>
    <div style="display:flex;justify-content:center;gap:var(--space-2);margin-top:var(--space-6)">
        <?php for ($i = 1; $i <= $result['last_page']; $i++): ?>
            <a href="<?= filterUrl(['page' => $i]) ?>"
               class="btn <?= $i === $result['current_page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
               style="min-width:36px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<!-- ── Suggest course modal ────────────────────────────────── -->
<div id="suggest-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <div class="modal-title">Suggest Alternative Course</div>
            <button class="btn-icon" onclick="document.getElementById('suggest-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="suggest-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="suggest_course">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);font-size:var(--text-sm)">
                    Applicant: <strong id="suggest-name"></strong><br>
                    <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        Exam rank: <strong id="suggest-rank"></strong>/10 — did not pass applied course threshold.
                    </span>
                </div>
                <div>
                    <label class="form-label">Suggest a course where their score qualifies:</label>
                    <div id="suggest-course-list" style="display:flex;flex-direction:column;gap:var(--space-2);margin-top:var(--space-2)"></div>
                </div>
                <div>
                    <label class="form-label">Note for applicant (optional)</label>
                    <textarea name="suggest_note" class="form-control" rows="2"
                              placeholder="e.g. We recommend you consider this course based on your exam results…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('suggest-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Send Suggestion</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Release / Edit modal ─────────────────────────────────── -->
<div id="release-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Release Result</div>
            <button class="btn-icon" onclick="document.getElementById('release-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="release-form" action="">
            <?= csrf_field() ?>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <p id="release-name" style="font-weight:var(--weight-medium)"></p>
                <div>
                    <label class="form-label">Decision <span style="color:var(--error)">*</span></label>
                    <select name="result" class="form-control" id="release-result" required>
                        <option value="">Select…</option>
                        <?php foreach (RESULT_LABELS as $val => $lbl): ?>
                            <option value="<?= $val ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Remarks (optional)</label>
                    <textarea name="remarks" class="form-control" rows="3" id="release-remarks"
                              placeholder="Additional notes for the applicant…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('release-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Result</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReleaseModal(appId, name, currentResult, currentRemarks) {
    document.getElementById('release-form').action = '<?= url('/staff/results/') ?>' + appId;
    document.getElementById('release-name').textContent = name;
    document.getElementById('release-result').value = currentResult || '';
    document.getElementById('release-remarks').value = currentRemarks || '';
    document.getElementById('release-modal').style.display = 'flex';
}
document.getElementById('release-modal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});

// ── Course suggestion modal ────────────────────────────────────
function openSuggestModal(appId, name, alts, rank) {
    const modal = document.getElementById('suggest-modal');
    document.getElementById('suggest-name').textContent = name;
    document.getElementById('suggest-rank').textContent = rank;
    const list = document.getElementById('suggest-course-list');
    list.innerHTML = '';
    alts.forEach(function(course) {
        const li = document.createElement('label');
        li.style.cssText = 'display:flex;align-items:center;gap:var(--space-2);padding:var(--space-3) var(--space-4);border:1px solid var(--border);border-radius:var(--radius-md);cursor:pointer;font-size:var(--text-sm)';
        li.innerHTML = '<input type="radio" name="suggest_course" value="' + escHtml(course) + '" style="accent-color:var(--accent)"> ' + escHtml(course);
        list.appendChild(li);
    });
    document.getElementById('suggest-form').action = '<?= url('/staff/results/suggest/') ?>' + appId;
    modal.style.display = 'flex';
}
function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.getElementById('suggest-modal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
</script>

<!-- ============================================================
     BULK ACTION TOOLBAR (floating, appears on selection)
============================================================ -->
<div id="res-bulk-toolbar" style="
    display:none;
    position:fixed;bottom:var(--space-6);left:50%;transform:translateX(-50%);z-index:500;
    background:var(--bg-elevated);border:1px solid var(--border);
    border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);
    padding:var(--space-3) var(--space-5);
    align-items:center;gap:var(--space-4);
    animation:resToolbarSlideUp .2s ease-out;
">
    <div style="display:flex;align-items:center;gap:var(--space-2)">
        <span id="res-bulk-count" style="
            display:inline-flex;align-items:center;justify-content:center;
            min-width:24px;height:24px;padding:0 var(--space-2);
            border-radius:var(--radius-full);
            background:var(--accent);color:var(--accent-text);
            font-size:var(--text-xs);font-weight:var(--weight-semibold);
        ">0</span>
        <span style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap">selected</span>
    </div>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-sm" onclick="resBulkAction('accepted')"
            style="display:flex;align-items:center;gap:5px;white-space:nowrap;background:var(--success);color:#fff;border-color:var(--success)">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 14) ?>
        Accept
    </button>
    <button type="button" class="btn btn-sm" onclick="resBulkAction('waitlisted')"
            style="display:flex;align-items:center;gap:5px;white-space:nowrap;background:var(--warning);color:#fff;border-color:var(--warning)">
        <?= icon('ic_fluent_clock_24_regular', 14) ?>
        Waitlist
    </button>
    <button type="button" class="btn btn-danger btn-sm" onclick="resBulkAction('rejected')"
            style="display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_dismiss_circle_24_regular', 14) ?>
        Reject
    </button>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-ghost btn-sm" onclick="resClearSelection()"
            style="color:var(--text-tertiary);font-size:var(--text-xs);white-space:nowrap">
        Clear
    </button>
</div>

<!-- Hidden form for bulk result actions -->
<form id="res-bulk-form" method="POST" action="<?= url('/staff/results/bulk') ?>" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="res-bulk-action" value="">
</form>

<style>
@keyframes resToolbarSlideUp {
    from { opacity:0; transform:translateX(-50%) translateY(16px); }
    to   { opacity:1; transform:translateX(-50%) translateY(0); }
}
tr.res-bulk-row.res-selected { background:var(--accent-muted); }
tr.res-bulk-row.res-selected td:first-child { box-shadow:inset 3px 0 0 var(--accent); }
</style>

<script>
/* ── Results bulk selection logic ───────────────────────── */
function resGetSelectedIds() {
    return Array.from(document.querySelectorAll('.res-check:checked')).map(function(cb) { return cb.value; });
}

function resUpdateSelection() {
    var ids     = resGetSelectedIds();
    var count   = ids.length;
    var toolbar = document.getElementById('res-bulk-toolbar');
    var badge   = document.getElementById('res-bulk-count');
    var allCb   = document.getElementById('res-select-all');
    var total   = document.querySelectorAll('.res-check').length;

    badge.textContent     = count;
    toolbar.style.display = count > 0 ? 'flex' : 'none';
    allCb.checked         = count > 0 && count === total;
    allCb.indeterminate   = count > 0 && count < total;

    document.querySelectorAll('.res-bulk-row').forEach(function(tr) {
        var cb = tr.querySelector('.res-check');
        if (cb && cb.checked) { tr.classList.add('res-selected'); }
        else { tr.classList.remove('res-selected'); }
    });
}

function resToggleAll(masterCb) {
    document.querySelectorAll('.res-check').forEach(function(cb) {
        cb.checked = masterCb.checked;
    });
    resUpdateSelection();
}

function resClearSelection() {
    document.getElementById('res-select-all').checked = false;
    document.querySelectorAll('.res-check').forEach(function(cb) { cb.checked = false; });
    resUpdateSelection();
}

function resBulkAction(action) {
    var ids = resGetSelectedIds();
    if (ids.length === 0) return;

    var labels = { accepted: 'Accept', waitlisted: 'Waitlist', rejected: 'Reject' };
    var label = labels[action] || action;

    if (!confirm(label + ' ' + ids.length + ' selected applicant(s)?\n\nThis will release their admission result immediately.')) return;

    var form = document.getElementById('res-bulk-form');
    document.getElementById('res-bulk-action').value = action;
    form.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
    ids.forEach(function(id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    form.submit();
}
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Results';
$activeNav = 'results';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
