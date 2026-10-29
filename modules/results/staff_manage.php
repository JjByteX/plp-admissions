<?php
// ============================================================
// modules/results/staff_manage.php
// Results — release admission decisions (SSO / Dean / Admin)
//
// Two-gate flow:
//   Gate 1 (Professor) — interviews the student, marks Pass/Reject.
//                        This is a RECOMMENDATION only — the Dean is
//                        the final decision-maker and can override it
//                        (a written reason is required and audited).
//   Gate 2 (Dean/Admin) — Release. Final confirmation that flips
//                        the result from internal-only to applicant-
//                        visible. The releaser explicitly picks Accept
//                        or Reject per row.
//
// Buckets shown on this page (the Professor's recommendation):
//   awaiting     — exam done, interview not yet evaluated
//   ready_accept — exam passed AND Professor marked Pass
//                  (Recommended: Accept)
//   ready_reject — exam failed OR Professor marked Reject
//                  (Recommended: Reject)
//   released     — admission_results row exists (final, applicant-visible)
//   withdrawn    — applicant pulled out of the cycle
//
// Per-row actions:
//   • Awaiting interview → no actions, status text only
//   • Recommended: Accept → "Accept" (matches), "Reject" (override)
//   • Recommended: Reject → "Reject" (matches), "Accept" (override)
//   • Released            → "Edit" override button (Admin only, audited)
//   • Withdrawn           → status text only
//   Override (the choice that contradicts the recommendation) opens a
//   modal demanding a written reason, which gets stored on the result
//   row and written to the audit log.
//
// Admin can also "Close Admissions" — bulk-reject every applicant
// who hasn't been released yet, so leftover rows don't sit forever.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_DEAN, ROLE_ADMIN);

// Finalize closed exams nobody submitted (one cheap EXISTS when there is no work).
finalize_closed_exam_drafts();

// Retry sends to Enrollment that are still waiting (throttled; does nothing when there are none).
enrollment_handoff_retry_pending(db());

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();

$canRelease       = ($role === ROLE_DEAN || $role === ROLE_ADMIN);
$canOverride      = ($role === ROLE_ADMIN);
$canCloseCycle    = ($role === ROLE_ADMIN);

// Dean is dept-scoped — only see applicants whose course maps to their
// own college. Admin sees every applicant across all colleges.
$scope = StaffScope::current();

$search    = get_str('q');
$filterRes = $_GET['result']      ?? '';  // ''|awaiting|ready_accept|ready_reject|released|withdrawn
$sortCol   = $_GET['sort_col']    ?? 'updated';
$sortDir   = strtolower($_GET['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page      = max(1, (int)($_GET['page'] ?? 1));

// ── Bucket SQL ────────────────────────────────────────────────
// One CASE expression we reuse in both the listing query and the
// per-bucket count query so the table and the tab badges always agree.
$bucketCase = "CASE
    WHEN a.overall_status = 'withdrawn' THEN 'withdrawn'
    WHEN ar.result IS NOT NULL THEN 'released'
    WHEN er.passed = 0 OR iq.evaluation_result = 'reject' THEN 'ready_reject'
    WHEN er.passed = 1 AND iq.evaluation_result = 'pass' THEN 'ready_accept'
    ELSE 'awaiting'
  END";

// ── WHERE builder ─────────────────────────────────────────────
$where  = ["a.overall_status IN ('released','exam','interview','withdrawn')"];
$params = [];

// Dean dept scope — limit to applicants whose course maps to dean's college.
// A Dean with no department / no mapped courses sees nothing (handled by StaffScope).
$scope->applyToWhere($where, $params, 'a.course_applied', 'dc');

if ($search) {
    // PDO::ATTR_EMULATE_PREPARES is off in config/db.php, so a single
    // named placeholder cannot be reused across multiple positions in
    // the same statement (MySQL native prepared statements bind by
    // position). Use distinct names for each LIKE so execute(...) finds
    // exactly one value per placeholder.
    $where[]       = '(u.name ILIKE :q1 OR u.email ILIKE :q2 OR a.course_applied ILIKE :q3)';
    $needle        = '%' . $search . '%';
    $params[':q1'] = $needle;
    $params[':q2'] = $needle;
    $params[':q3'] = $needle;
}

// ── Default tab (must run BEFORE the listing query so the bucket
//                filter is baked into the WHERE clause) ───────
// "All" and "Awaiting interview" are no longer tabs. If somebody
// lands here without a selection, drop them on Ready: Accept (the
// SSO worklist) instead of an unfiltered list. The previous
// version applied this default AFTER the listing query, which
// caused the table to show every applicant regardless of bucket
// while the tab badges all read 0 — surfacing not-yet-interviewed
// applicants on the "Recommended: Accept" tab.
if ($filterRes === '' || $filterRes === 'awaiting') {
    $filterRes = 'ready_accept';
}

$validBuckets = ['awaiting', 'ready_accept', 'ready_reject', 'released', 'withdrawn'];
if (in_array($filterRes, $validBuckets, true)) {
    $where[]            = "$bucketCase = :bucket";
    $params[':bucket']  = $filterRes;
}

$whereStr = implode(' AND ', $where);

// ── Counts for filter tabs ────────────────────────────────────
// Same Dean dept scope applied so the tab badges match the table.
$countWhere  = "a.overall_status IN ('released','exam','interview','withdrawn')";
$countParams = [];
[$countScopeSql, $countScopeParams] = $scope->courseSql('a.course_applied', 'cdc');
if ($countScopeSql !== '') {
    $countWhere .= ' AND ' . $countScopeSql;
    $countParams = array_merge($countParams, $countScopeParams);
}
$countStmt = $db->prepare(
    "SELECT
       SUM(CASE WHEN $bucketCase = 'awaiting'     THEN 1 ELSE 0 END) AS awaiting_count,
       SUM(CASE WHEN $bucketCase = 'ready_accept' THEN 1 ELSE 0 END) AS ready_accept_count,
       SUM(CASE WHEN $bucketCase = 'ready_reject' THEN 1 ELSE 0 END) AS ready_reject_count,
       SUM(CASE WHEN $bucketCase = 'released'     THEN 1 ELSE 0 END) AS released_count,
       SUM(CASE WHEN $bucketCase = 'withdrawn'    THEN 1 ELSE 0 END) AS withdrawn_count,
       COUNT(*) AS total_count
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE $countWhere"
);
$countStmt->execute($countParams);
$countRows = $countStmt->fetch(PDO::FETCH_ASSOC);

// ── Slot capacity per course ──────────────────────────────────
// Shows how many are already accepted vs the max slots cap, so
// the Dean can tell at a glance if they are at risk of over-accepting.
$sy = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$slotCapParams = [':sy' => $sy];
$slotCapCourseFilter = '';
[$scopeCapSql, $scopeCapParams] = $scope->courseSql('cc.course_name', 'scc');
if ($scopeCapSql !== '') {
    $slotCapCourseFilter = ' AND ' . $scopeCapSql;
    $slotCapParams = array_merge($slotCapParams, $scopeCapParams);
}
$slotCapStmt = $db->prepare(
    "SELECT cc.course_name,
            cc.max_slots,
            COALESCE(SUM(CASE WHEN ar.result = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted_count,
            SUM(CASE WHEN $bucketCase = 'ready_accept' THEN 1 ELSE 0 END) AS pending_accept_count
     FROM course_caps cc
     LEFT JOIN applicants a   ON a.course_applied = cc.course_name AND a.school_year = cc.school_year
     LEFT JOIN users u        ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE cc.school_year = :sy AND cc.max_slots IS NOT NULL{$slotCapCourseFilter}
     GROUP BY cc.course_name, cc.max_slots
     HAVING cc.max_slots > 0
     ORDER BY cc.course_name"
);
$slotCapStmt->execute($slotCapParams);
$slotCaps = $slotCapStmt->fetchAll(PDO::FETCH_ASSOC);

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
// MySQL sorts NULLs first on ASC and last on DESC; Postgres is the reverse.
$orderDir .= ($orderDir === 'ASC') ? ' NULLS FIRST' : ' NULLS LAST';

// ── Paginate ──────────────────────────────────────────────────
// Rows per page — AutoPageSize (app.js) measures how many fixed-height rows
// fit the table and reloads with ?per_page=; see auto_per_page() in helpers.php.
$perPage = auto_per_page('results-manage');
$result = paginate(
    $db,
    "SELECT COUNT(*)
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            ar.result AS admission_result, ar.remarks AS admission_remarks, ar.released_at,
            ar.enrollment_intent,
            eo.status AS handoff_status, eo.last_error AS handoff_error,
            eo.enrollment_stage, eo.enrollment_student_number,
            er.score  AS exam_score, er.total_items AS exam_total,
            er.rank_score AS exam_rank, er.passed AS exam_passed, er.submit_note AS exam_note,
            iq.status AS interview_status, iq.interview_notes,
            iq.evaluation_result,
            $bucketCase AS bucket
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     LEFT JOIN enrollment_handoff_outbox eo ON eo.applicant_id = a.id
     WHERE $whereStr
     ORDER BY $orderCol $orderDir",
    $params, $page, $perPage
);

// ── Filter URL helper ─────────────────────────────────────────
function filterUrl(array $merge = []): string {
    global $search, $filterRes, $sortCol, $sortDir, $perPage;
    $base = ['q' => $search, 'result' => $filterRes, 'sort_col' => $sortCol, 'sort_dir' => $sortDir, 'per_page' => $perPage, 'page' => 1];
    return '?' . http_build_query(array_merge($base, $merge));
}

// Sortable header for this page's filters (search + result status),
// always resetting to page 1 on a sort change. Shared implementation
// lives in core/helpers.php as sortable_th().

ob_start();
?>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('info')): ?>
    <div class="alert alert-info" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($role === ROLE_ADMIN && ($programWarning = enrollment_program_warning()) !== ''): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($programWarning) ?></div>
<?php endif; ?>

<!-- ============================================================
     Search + Close Admissions (LEFT) · Tabs (RIGHT) now live in the
     table card's toolbar, fused to the table (see below).

     Tab cleanup (vs Chunk 4):
       • "All" tab dropped - no useful filter, just clutter.
       • "Awaiting interview" tab dropped - SSO can't act on it
         here. Rendered instead as a small grey chip below this
         bar that links into the Interviews queue (where the
         action actually lives).
       • "Filter" dropdown dropped - was a duplicate of these
         tabs. Search + Auto-Release stay on the left.
       • "Withdrawn" kept but de-emphasized (muted style).
============================================================ -->
<?php
// Primary tabs — the Dean / SSO worklist + audit view. Labels read as
// "Recommended:" because the bucket reflects the Professor's recommendation
// — the Dean has final say and can release either way.
$primaryTabs = [
    'ready_accept' => ['label' => 'Recommended: Accept', 'count' => (int)$countRows['ready_accept_count']],
    'ready_reject' => ['label' => 'Recommended: Decline', 'count' => (int)$countRows['ready_reject_count']],
    'released'     => ['label' => 'Released',            'count' => (int)$countRows['released_count']],
];
// Secondary tab — archive-style, rendered muted at the end.
$secondaryTabs = [
    'withdrawn'    => ['label' => 'Withdrawn',    'count' => (int)$countRows['withdrawn_count']],
];
$awaitingCount = (int)$countRows['awaiting_count'];
?>
<?php if ($awaitingCount > 0): ?>
<!-- Awaiting-interview nudge — replaces the old "Awaiting interview" tab.
     SSO can't release these here (Professor hasn't evaluated yet), so
     surface them as a quiet pointer to the Interviews page where the
     action actually lives. -->
<div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-3);flex-shrink:0">
    <a href="<?= url('/staff/interviews/queue') ?>"
       style="
           display:inline-flex;align-items:center;gap:var(--space-2);
           padding:4px var(--space-3);border-radius:var(--radius);
           background:var(--bg-secondary);border:1px solid var(--border);
           font-size:var(--text-xs);color:var(--text-tertiary);text-decoration:none;
           transition:background var(--transition-fast),color var(--transition-fast);
       "
       onmouseover="this.style.background='var(--bg-overlay)';this.style.color='var(--text-secondary)'"
       onmouseout="this.style.background='var(--bg-secondary)';this.style.color='var(--text-tertiary)'"
       onfocus="this.style.background='var(--bg-overlay)';this.style.color='var(--text-secondary)'"
       onblur="this.style.background='var(--bg-secondary)';this.style.color='var(--text-tertiary)'"
       title="Open the Interviews queue to record Pass/Decline">
        <?= icon('ic_fluent_clock_24_regular', 12) ?>
        <span><strong style="color:var(--text-secondary);font-weight:var(--weight-medium)"><?= $awaitingCount ?></strong>
            applicant<?= $awaitingCount === 1 ? '' : 's' ?> awaiting Professor evaluation</span>
        <?= icon('ic_fluent_chevron_right_24_regular', 12) ?>
    </a>
</div>
<?php endif; ?>

<?php if (!empty($slotCaps)): ?>
<!-- ── Slot capacity indicator ────────────────────────────── -->
<div style="display:flex;flex-wrap:nowrap;overflow-x:auto;gap:var(--space-2);margin-bottom:var(--space-3);flex-shrink:0">
    <?php foreach ($slotCaps as $cap):
        $accepted = (int)$cap['accepted_count'];
        $pending  = (int)$cap['pending_accept_count'];
        $max      = (int)$cap['max_slots'];
        $total    = $accepted + $pending;
        $pct      = $max > 0 ? min(100, round($total / $max * 100)) : 0;
        $overLimit   = $total > $max;
        $nearLimit   = !$overLimit && $pct >= 80;
        $barColor    = $overLimit ? 'var(--error)' : ($nearLimit ? 'var(--warning)' : 'var(--success)');
        $borderColor = $overLimit ? 'var(--error)' : ($nearLimit ? 'var(--warning)' : 'var(--border)');
    ?>
    <div style="
        display:flex;flex-direction:column;gap:4px;
        padding:var(--space-2) var(--space-3);
        border:1px solid <?= $borderColor ?>;
        border-radius:var(--radius);
        background:var(--bg-elevated);
        font-size:var(--text-xs);
        min-width:180px;flex-shrink:0;
    ">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-3)">
            <span style="color:var(--text-secondary);font-weight:var(--weight-medium);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px" title="<?= e($cap['course_name']) ?>">
                <?= e($cap['course_name']) ?>
            </span>
            <span style="color:<?= $barColor ?>;font-weight:var(--weight-semibold);white-space:nowrap">
                <?= $accepted ?><?= $pending > 0 ? '+' . $pending : '' ?> / <?= $max ?>
            </span>
        </div>
        <!-- Progress bar -->
        <div style="height:4px;background:var(--border);border-radius:var(--radius);overflow:hidden">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:var(--radius);transition:width .3s"></div>
        </div>
        <div style="color:var(--text-tertiary)">
            <?= $accepted ?> accepted<?= $pending > 0 ? ' · <strong style="color:' . $barColor . '">' . $pending . ' pending</strong>' : '' ?>
            <?php if ($overLimit): ?>
                &nbsp;<span style="color:var(--error);font-weight:var(--weight-semibold)">· Over limit!</span>
            <?php elseif ($nearLimit): ?>
                &nbsp;<span style="color:var(--warning)">· Near limit</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
// .auto-table-wrap that AutoPageSize measures, the card with the toolbar and
// the fixed-height table fused together, and the pagination bar under the card.
$sortParams = ['q' => $search, 'result' => $filterRes, 'per_page' => $perPage, 'page' => 1];
?>
<div class="auto-table-wrap" data-auto-page-size="results-manage" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + Close Admissions (left), bucket tabs (right), one row -->
    <form method="GET" class="auto-table-toolbar">
        <input type="hidden" name="result"   value="<?= e($filterRes) ?>">
        <input type="hidden" name="sort_col" value="<?= e($sortCol) ?>">
        <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <?php
        $segTabs = [];
        foreach (array_merge($primaryTabs, $secondaryTabs) as $val => $tab) {
            $segTabs[$val] = ['label' => $tab['label'], 'href' => filterUrl(['result' => $val]), 'count' => $tab['count']];
        }
        echo segmented_tabs($segTabs, (string)$filterRes, 'Result buckets');
        ?>

        <div class="auto-table-search" style="max-width:320px">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" id="res_q" name="q" aria-label="Search" class="form-input" placeholder="Search name, email, course…" value="<?= e($search) ?>">
        </div>

        <?php if ($search !== ''): ?>
            <a href="<?= e(filterUrl(['q' => ''])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>

        <?php if ($canCloseCycle): ?>
            <!-- Close Admissions (SSO / Admin) — bulk-rejects every
                 unreleased applicant so leftover rows don't sit forever. -->
            <?= ui_button('Close Admissions', ['variant' => 'outline-danger', 'icon' => 'ic_fluent_lock_closed_24_regular', 'attrs' => ['data-page-action' => '', 'onclick' => 'openCloseAdmissionsModal()']]) ?>
        <?php endif; ?>

        <span class="toolbar-spacer"></span>

    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table" id="results-table">
            <thead>
                <tr>
                    <?php if ($canRelease): ?>
                    <th class="col-check">
                        <input type="checkbox" id="res-select-all" aria-label="Select all" onchange="resToggleAll(this)"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                    </th>
                    <?php endif; ?>
                    <?= sortable_th('applicant', 'Applicant', $sortCol, $sortDir, $sortParams) ?>
                    <?= sortable_th('course',    'Course',    $sortCol, $sortDir, $sortParams) ?>
                    <th style="width:100px">Exam Score</th>
                    <th style="width:120px">Interview</th>
                    <?= sortable_th('result',    'Result',    $sortCol, $sortDir, $sortParams, 'width:220px') ?>
                    <?= sortable_th('released',  'Released',  $sortCol, $sortDir, $sortParams, 'width:120px') ?>
                    <th style="width:320px">Actions</th>
                </tr>
            </thead>
            <?php if (!empty($result['data'])): ?>
            <tbody>
            <?php foreach ($result['data'] as $row):
                $bucket     = $row['bucket'] ?? 'awaiting';
                $isReady    = ($bucket === 'ready_accept' || $bucket === 'ready_reject');
                $selectable = $canRelease && $isReady;
                $fullName   = format_full_name($row);
            ?>
                <tr class="res-bulk-row" data-id="<?= (int)$row['id'] ?>" data-bucket="<?= e($bucket) ?>">
                    <?php if ($canRelease): ?>
                    <td class="col-check">
                        <?php if ($selectable): ?>
                        <input type="checkbox" class="res-check" aria-label="Select applicant" value="<?= (int)$row['id'] ?>"
                               onchange="resUpdateSelection()"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <td>
                        <?php // Single-line row - plain name (email on hover).
                              // Details open from the eye View button only. ?>
                        <span class="auto-table-clip" title="<?= e($row['email']) ?>"
                              style="font-weight:var(--weight-medium);color:var(--text-primary)"><?= e($fullName) ?></span>
                    </td>

                    <td style="font-size:var(--text-sm)" title="<?= e($row['course_applied']) ?>">
                        <span class="auto-table-clip"><?= e($row['course_applied']) ?></span>
                    </td>

                    <!-- Exam score — color carries the pass/fail signal so we
                         don't repeat it as text. Tooltip stays explicit for
                         hover + screen-reader users. -->
                    <td style="font-size:var(--text-sm)">
                        <?php if ($row['exam_score'] !== null):
                            $_examScoreColor = ((int)$row['exam_passed'] === 1) ? 'var(--success)'
                                              : (((int)$row['exam_passed'] === 0) ? 'var(--error)' : 'inherit');
                            $_examScoreTitle = ((int)$row['exam_passed'] === 1) ? 'Passed the entrance exam'
                                              : (((int)$row['exam_passed'] === 0) ? 'Did not pass the entrance exam' : '');
                        ?>
                            <span style="color:<?= $_examScoreColor ?>;font-weight:var(--weight-medium)"
                                  title="<?= e($_examScoreTitle) ?>">
                                <?= (int)$row['exam_score'] ?>/<?= (int)$row['exam_total'] ?>
                            </span>
                            <?php if (!empty($row['exam_note'])): ?>
                                <div style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e($row['exam_note']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary)">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Interview column — if the interview is completed, the
                         evaluation result (Pass / Decline) is the meaningful
                         signal, so we show that as the badge instead of
                         "Completed · Pass" (which double-states the outcome).
                         For other statuses (Scheduled, In Progress, No-show, etc.)
                         we show the status as-is, since there's no result yet. -->
                    <td style="font-size:var(--text-sm)">
                        <?php if ($row['interview_status']):
                            $iMap = [
                                'scheduled'   => ['badge-uploaded', 'Scheduled'],
                                'checked_in'  => ['badge-uploaded', 'Checked In'],
                                'in_progress' => ['badge-review',   'In Progress'],
                                'completed'   => ['badge-approved', 'Completed'],
                                'no_show'     => ['badge-rejected', 'No-show'],
                            ];
                            [$ibadge, $ilabel] = $iMap[$row['interview_status']] ?? ['badge-pending', ucfirst($row['interview_status'])];
                            // For completed rows, surface the eval result as the
                            // badge itself — "Pass" or "Decline" with matching color.
                            if ($row['interview_status'] === 'completed' && $row['evaluation_result']) {
                                if ($row['evaluation_result'] === 'pass') {
                                    $ibadge = 'badge-approved';
                                    $ilabel = 'Pass';
                                } elseif ($row['evaluation_result'] === 'reject') {
                                    $ibadge = 'badge-rejected';
                                    $ilabel = 'Decline';
                                }
                            }
                        ?>
                            <span class="badge <?= $ibadge ?>"
                                  <?= $row['interview_notes'] ? 'title="' . e($row['interview_notes']) . '"' : '' ?>>
                                <?= e($ilabel) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary)">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- Result column -->
                    <td>
                        <?php if ($bucket === 'withdrawn'): ?>
                            <span class="badge">Withdrawn</span>
                        <?php elseif ($bucket === 'released'): ?>
                            <span class="badge badge-<?= $row['admission_result'] ?>"
                                  <?= $row['admission_remarks'] ? 'title="' . e($row['admission_remarks']) . '"' : '' ?>>
                                <?= e(RESULT_LABELS[$row['admission_result']] ?? ucfirst($row['admission_result'])) ?>
                            </span>
                        <?php elseif ($bucket === 'ready_accept'): ?>
                            <span class="badge badge-approved" title="Professor recommends: Accept">Recommended: Accept</span>
                        <?php elseif ($bucket === 'ready_reject'): ?>
                            <span class="badge badge-rejected" title="Professor recommends: Decline">Recommended: Decline</span>
                        <?php else: /* awaiting */ ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">Awaiting interview</span>
                        <?php endif; ?>
                    </td>

                    <!-- Released-on date -->
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?= $row['released_at'] ? format_date($row['released_at'], 'M j, Y') : '-' ?>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:nowrap">

                        <!-- View Details button — visible to all roles -->
                        <button type="button" class="btn btn-ghost btn-sm"
                                data-applicant-panel="<?= (int)$row['id'] ?>"
                                title="View applicant details"
                                style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px">
                            <?= icon('ic_fluent_eye_show_24_regular', 13) ?>
                            View
                        </button>

                        <?php if ($bucket === 'withdrawn'): ?>
                            <?php // Result column already shows the "Withdrawn" pill,
                                  // so no extra label is needed here. ?>

                        <?php elseif ($bucket === 'released'): ?>
                            <?php if ($row['admission_result'] === 'accepted' && $row['enrollment_intent'] === 'confirmed'): ?>
                                <?php if ($row['handoff_status'] === 'sent'): ?>
                                    <?php if ($row['enrollment_stage'] === 'enrolled'): ?>
                                        <span class="badge badge-approved" title="The COR was issued. Student number <?= e((string) $row['enrollment_student_number']) ?>">Enrolled</span>
                                    <?php elseif ($row['enrollment_stage'] === 'student'): ?>
                                        <span class="badge badge-approved" title="Documents received at the Registrar">Student <?= e((string) $row['enrollment_student_number']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-approved" title="This applicant has an Enrollment account">Sent to Enrollment</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="POST" action="<?= url('/staff/results/send-enrollment/' . $row['id']) ?>" style="margin:0">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-secondary btn-sm"
                                                style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px"
                                                <?= !empty($row['handoff_error']) ? 'title="' . e($row['handoff_error']) . '"' : '' ?>
                                                data-confirm="Send <?= e($fullName) ?> to Enrollment?" data-confirm-ok="Send">
                                            Send to Enrollment
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canOverride): ?>
                                <button type="button" class="btn btn-ghost btn-sm"
                                        style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px"
                                        onclick="openOverrideModal(<?= (int)$row['id'] ?>, <?= htmlspecialchars(json_encode($fullName), ENT_QUOTES) ?>, '<?= e($row['admission_result']) ?>', <?= htmlspecialchars(json_encode((string)($row['admission_remarks'] ?? '')), ENT_QUOTES) ?>)">
                                    <?= icon('ic_fluent_edit_24_regular', 13) ?>
                                    Edit
                                </button>
                            <?php endif; ?>

                        <?php elseif (($bucket === 'ready_accept' || $bucket === 'ready_reject') && $canRelease): ?>
                            <?php
                                $recAccept   = ($bucket === 'ready_accept');
                                $nameJson    = htmlspecialchars(json_encode($fullName), ENT_QUOTES);
                                $bucketJson  = htmlspecialchars(json_encode($bucket), ENT_QUOTES);
                                // Confirmation text for the "matches recommendation" path.
                                $confirmAccept = "Release {$fullName} as Accepted? The applicant will be notified by email.";
                                $confirmReject = "Release {$fullName} as Declined? The applicant will be notified by email.";
                            ?>
                            <!-- Accept button -->
                            <?php if ($recAccept): ?>
                                <!-- Matches recommendation: direct release with a confirm. -->
                                <form method="POST" action="<?= url('/staff/results/' . $row['id']) ?>" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"   value="release">
                                    <input type="hidden" name="decision" value="accepted">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:var(--success);color:var(--white);border-color:var(--success);font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px"
                                            data-confirm="<?= e($confirmAccept) ?>" data-confirm-ok="Accept">
                                        <?= icon('ic_fluent_checkmark_circle_24_regular', 13) ?>
                                        Accept
                                    </button>
                                </form>
                            <?php else: ?>
                                <!-- Override: Professor recommended Reject. Demand a reason. -->
                                <button type="button" class="btn btn-ghost btn-sm"
                                        style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px;border:1px dashed var(--success);color:var(--success)"
                                        title="Override Professor recommendation (reason required)"
                                        onclick="openReleaseOverrideModal(<?= (int)$row['id'] ?>, <?= $nameJson ?>, 'accepted', <?= $bucketJson ?>)">
                                    <?= icon('ic_fluent_checkmark_circle_24_regular', 13) ?>
                                    Accept&hellip;
                                </button>
                            <?php endif; ?>

                            <!-- Decline button (DB value stays 'rejected') -->
                            <?php if (!$recAccept): ?>
                                <!-- Matches recommendation: direct release with a confirm. -->
                                <form method="POST" action="<?= url('/staff/results/' . $row['id']) ?>" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"   value="release">
                                    <input type="hidden" name="decision" value="rejected">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:var(--error);color:var(--white);border-color:var(--error);font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px"
                                            data-confirm="<?= e($confirmReject) ?>" data-confirm-danger data-confirm-ok="Decline">
                                        <?= icon('ic_fluent_dismiss_circle_24_regular', 13) ?>
                                        Decline
                                    </button>
                                </form>
                            <?php else: ?>
                                <!-- Override: Professor recommended Accept. Demand a reason. -->
                                <button type="button" class="btn btn-ghost btn-sm"
                                        style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px;border:1px dashed var(--error);color:var(--error)"
                                        title="Override Professor recommendation (reason required)"
                                        onclick="openReleaseOverrideModal(<?= (int)$row['id'] ?>, <?= $nameJson ?>, 'rejected', <?= $bucketJson ?>)">
                                    <?= icon('ic_fluent_dismiss_circle_24_regular', 13) ?>
                                    Decline&hellip;
                                </button>
                            <?php endif; ?>

                        <?php elseif ($bucket === 'ready_accept'): ?>
                            <span style="font-size:var(--text-xs);color:var(--success);font-weight:var(--weight-medium)">Recommended: Accept</span>

                        <?php elseif ($bucket === 'ready_reject'): ?>
                            <span style="font-size:var(--text-xs);color:var(--error);font-weight:var(--weight-medium)">Recommended: Decline</span>

                        <?php else: /* awaiting */ ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">Awaiting interview</span>
                        <?php endif; ?>

                        </div><!-- /actions flex -->
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($result['data'])): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_clipboard_24_regular', 32) ?>
            <div><?= $search !== '' ? 'No applicants match your search.' : 'No applicants found.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap, same as the audit log.
     Its 40px is reserved in AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => filterUrl(['page' => $p]), 'applicants') ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->

<?php if ($canOverride): ?>
<!-- ── Admin override modal ────────────────────────────────── -->
<div id="override-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <div class="modal-title">Edit Released Result</div>
            <button class="btn-icon" onclick="document.getElementById('override-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="override-form" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="override">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="background:var(--warning-bg,rgba(245,158,11,.08));border:1px solid var(--warning);
                            border-radius:var(--radius);padding:var(--space-3) var(--space-4);
                            font-size:var(--text-xs);color:var(--text-secondary)">
                    <strong style="color:var(--warning)">Admin override.</strong>
                    Changing a released result is audited. Use only when correcting a clerical error or
                    handling an exceptional case (e.g. flagged for review).
                </div>
                <p id="override-name" style="font-weight:var(--weight-medium);margin:0"></p>
                <div>
                    <label class="form-label" for="override-result">Result <span style="color:var(--error)">*</span></label>
                    <select name="result" class="form-control" id="override-result" required>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Declined</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="override-remarks">Reason / remarks <span style="color:var(--error)">*</span></label>
                    <textarea name="remarks" class="form-control" rows="3" id="override-remarks" required
                              placeholder="Why is this result being changed?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('override-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Override</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canRelease): ?>
<!-- ── Release-with-override modal ─────────────────────────── -->
<!-- Opens when the releaser picks the option that conflicts with
     the Professor's recommendation (e.g. Dean accepts a row the
     Professor flagged Reject). A written reason is mandatory; it
     gets stored on admission_results.remarks and audited. -->
<div id="release-override-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title">Override Professor recommendation</div>
            <button class="btn-icon" onclick="document.getElementById('release-override-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="release-override-form" action=""
              onsubmit="document.getElementById('release-override-modal').style.display='none'">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="release">
            <input type="hidden" name="decision" id="release-override-decision" value="">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div id="release-override-warning" style="
                    background:var(--warning-bg,rgba(245,158,11,.08));
                    border:1px solid var(--warning);
                    border-radius:var(--radius);padding:var(--space-3) var(--space-4);
                    font-size:var(--text-xs);color:var(--text-secondary)">
                    <strong style="color:var(--warning)">Heads up.</strong>
                    The Professor interviewed this applicant face-to-face. Overriding their
                    recommendation requires a written reason and will be recorded in the audit log.
                </div>
                <p id="release-override-name" style="font-weight:var(--weight-medium);margin:0"></p>
                <div>
                    <span class="form-label">Result <span style="color:var(--error)">*</span></span>
                    <div id="release-override-result-label" style="font-size:var(--text-sm);color:var(--text-secondary)"></div>
                </div>
                <div>
                    <label class="form-label" for="release-override-reason">
                        Reason for override <span style="color:var(--error)">*</span>
                    </label>
                    <textarea name="reason" id="release-override-reason" class="form-control"
                              rows="4" required
                              placeholder="Reason for override (required)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('release-override-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Release with Override</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canCloseCycle): ?>
<!-- ── Close Admissions modal (SSO / Admin) ─────────────────── -->
<!-- Bulk-rejects every unreleased applicant for this cycle. A
     reason is requested for the audit trail; if left blank the
     server falls back to a generic note. -->
<div id="close-admissions-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title">Close Admissions Cycle</div>
            <button class="btn-icon" onclick="document.getElementById('close-admissions-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/results/bulk') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close_admissions">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="background:var(--error-bg,rgba(220,38,38,.08));
                            border:1px solid var(--error);
                            border-radius:var(--radius);padding:var(--space-3) var(--space-4);
                            font-size:var(--text-xs);color:var(--text-secondary)">
                    <strong style="color:var(--error)">Irreversible.</strong>
                    All unreleased applicants will be marked <strong>Declined</strong>
                    and emailed. Withdrawn and already-released applicants are not affected.
                </div>
                <div>
                    <label class="form-label" for="close-admissions-reason">
                        Reason (optional)
                    </label>
                    <textarea name="reason" id="close-admissions-reason" class="form-control"
                              rows="3"
                              placeholder="e.g. End of cycle for SY 2024–2025. All remaining applicants rejected."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('close-admissions-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn" style="background:var(--error);color:var(--white);border-color:var(--error)"
                        data-confirm="Close admissions? All unreleased applicants will be rejected. Irreversible." data-confirm-danger data-confirm-ok="Close admissions">
                    Close Admissions
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>window.PAGE = <?= json_encode([
    'resultsUrl' => url('/staff/results/'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

<?php if ($canRelease): ?>
<!-- ============================================================
     BULK ACTION TOOLBAR (floating, appears on selection)
     The Dean / SSO / Admin can release everything they've selected
     as Accepted OR as Rejected. The server still skips rows that are
     already released, withdrawn, or still awaiting interview.
============================================================ -->
<div id="res-bulk-toolbar" style="
    display:none;
    position:fixed;bottom:var(--space-6);left:50%;transform:translateX(-50%);z-index:500;
    background:var(--bg-elevated);border:1px solid var(--border);
    border-radius:var(--radius);box-shadow:var(--shadow-lg);
    padding:var(--space-3) var(--space-5);
    align-items:center;gap:var(--space-4);
    animation:resToolbarSlideUp .2s ease-out;
">
    <div style="display:flex;align-items:center;gap:var(--space-2)">
        <span id="res-bulk-count" style="
            display:inline-flex;align-items:center;justify-content:center;
            min-width:24px;height:24px;padding:0 var(--space-2);
            border-radius:var(--radius);
            background:var(--accent);color:var(--accent-text);
            font-size:var(--text-xs);font-weight:var(--weight-semibold);
        ">0</span>
        <span style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap">selected</span>
    </div>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-sm" onclick="resBulkRelease('accepted')"
            style="background:var(--success);color:var(--white);border-color:var(--success);display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 14) ?>
        Accept Selected
    </button>

    <button type="button" class="btn btn-sm" onclick="resBulkRelease('rejected')"
            style="background:var(--error);color:var(--white);border-color:var(--error);display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_dismiss_circle_24_regular', 14) ?>
        Decline Selected
    </button>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-ghost btn-sm" onclick="resClearSelection()"
            style="color:var(--text-tertiary);font-size:var(--text-xs);white-space:nowrap">
        Clear
    </button>
</div>

<!-- Hidden form for bulk release — action is populated by JS to one of
     'bulk_accept' or 'bulk_reject' depending on which toolbar button was
     clicked. Optional 'reason' input is added when the selection contains
     rows that conflict with the Professor's recommendation. -->
<form id="res-bulk-form" method="POST" action="<?= url('/staff/results/bulk') ?>" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="res-bulk-action" value="bulk_accept">
</form>


<?php endif; ?>

<?php
// Slide-in applicant detail drawer (markup + JS opener).
include VIEWS_PATH . '/partials/applicant_drawer.php';

$content   = ob_get_clean();
$pageTitle = 'Results';
$activeNav = 'results';
$pageScripts = ['js/pages/results-manage.js'];
if ($canRelease) {
    $pageStyles   = ['css/pages/results-release.css'];
    $pageScripts[] = 'js/pages/results-release.js';
}
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
