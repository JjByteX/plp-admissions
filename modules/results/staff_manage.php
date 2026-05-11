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
//   Gate 2 (Dean/SSO/Admin) — Release. Final confirmation that flips
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
// SSO/Admin can also "Close Admissions" — bulk-reject every applicant
// who hasn't been released yet, so leftover rows don't sit forever.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();

$canRelease       = ($role === ROLE_SSO || $role === ROLE_DEAN || $role === ROLE_ADMIN);
$canOverride      = ($role === ROLE_ADMIN);
$canCloseCycle    = ($role === ROLE_SSO || $role === ROLE_ADMIN);

// Dean is dept-scoped — only see applicants whose course maps to their
// own college. Admin and SSO see every applicant across all colleges.
$scopedDept    = ($role === ROLE_DEAN) ? (string) user_department($staffId) : '';
$scopedCourses = ($scopedDept !== '') ? courses_in_department($scopedDept) : [];

$search    = trim($_GET['q']      ?? '');
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
if ($role === ROLE_DEAN) {
    if (empty($scopedCourses)) {
        // Dean has no department on file (or no courses mapped) — show nothing.
        $where[] = '1 = 0';
    } else {
        $names = [];
        foreach ($scopedCourses as $i => $c) {
            $key          = ':dc' . $i;
            $names[]      = $key;
            $params[$key] = $c;
        }
        $where[] = 'a.course_applied IN (' . implode(',', $names) . ')';
    }
}

if ($search) {
    $where[]      = '(u.name LIKE :q OR u.email LIKE :q OR a.course_applied LIKE :q)';
    $params[':q'] = '%' . $search . '%';
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
if ($role === ROLE_DEAN) {
    if (empty($scopedCourses)) {
        $countWhere .= ' AND 1 = 0';
    } else {
        $names = [];
        foreach ($scopedCourses as $i => $c) {
            $key               = ':cdc' . $i;
            $names[]           = $key;
            $countParams[$key] = $c;
        }
        $countWhere .= ' AND a.course_applied IN (' . implode(',', $names) . ')';
    }
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
if ($role === ROLE_DEAN && !empty($scopedCourses)) {
    $scNames = [];
    foreach ($scopedCourses as $i => $c) {
        $k = ':scc' . $i;
        $scNames[] = $k;
        $slotCapParams[$k] = $c;
    }
    $slotCapCourseFilter = ' AND cc.course_name IN (' . implode(',', $scNames) . ')';
}
$slotCapStmt = $db->prepare(
    "SELECT cc.course_name,
            cc.max_slots,
            COALESCE(SUM(ar.result = 'accepted'), 0) AS accepted_count,
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

// ── Paginate ──────────────────────────────────────────────────
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
            er.score  AS exam_score, er.total_items AS exam_total,
            er.rank_score AS exam_rank, er.passed AS exam_passed,
            iq.status AS interview_status, iq.interview_notes,
            iq.evaluation_result,
            $bucketCase AS bucket
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE $whereStr
     ORDER BY $orderCol $orderDir",
    $params, $page, 25
);

// ── Default tab ───────────────────────────────────────────────
// "All" and "Awaiting interview" are no longer tabs. If somebody
// lands here without a selection, drop them on Ready: Accept (the
// SSO worklist) instead of an unfiltered list.
if ($filterRes === '' || $filterRes === 'awaiting') {
    $filterRes = 'ready_accept';
    // Re-apply the bucket filter we just defaulted to.
    $where[]            = "$bucketCase = :bucket";
    $params[':bucket']  = $filterRes;
    $whereStr           = implode(' AND ', $where);
}

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

<!-- ============================================================
     TOP BAR: Search + Auto-Release (LEFT) · Tabs (RIGHT)

     Tab cleanup (vs Chunk 4):
       • "All" tab dropped — no useful filter, just clutter.
       • "Awaiting interview" tab dropped — SSO can't act on it
         here. Rendered instead as a small grey chip below this
         bar that links into the Interviews queue (where the
         action actually lives).
       • "Filter" dropdown dropped — was a duplicate of these
         tabs. Search + Auto-Release stay on the left.
       • "Withdrawn" kept but de-emphasized (muted style).
============================================================ -->
<?php
// Primary tabs — the Dean / SSO worklist + audit view. Labels read as
// "Recommended:" because the bucket reflects the Professor's recommendation
// — the Dean has final say and can release either way.
$primaryTabs = [
    'ready_accept' => ['label' => 'Recommended: Accept', 'count' => (int)$countRows['ready_accept_count']],
    'ready_reject' => ['label' => 'Recommended: Reject', 'count' => (int)$countRows['ready_reject_count']],
    'released'     => ['label' => 'Released',            'count' => (int)$countRows['released_count']],
];
// Secondary tab — archive-style, rendered muted at the end.
$secondaryTabs = [
    'withdrawn'    => ['label' => 'Withdrawn',    'count' => (int)$countRows['withdrawn_count']],
];
$awaitingCount = (int)$countRows['awaiting_count'];
?>
<div style="
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:var(--space-4);
    margin-bottom:var(--space-3);
    border-bottom:1px solid var(--border);
    flex-wrap:wrap;
">
    <!-- LEFT: Search + Auto-Release -->
    <div style="display:flex;align-items:center;gap:var(--space-2);padding-bottom:var(--space-1);flex-shrink:0">
        <form method="GET" style="display:flex;align-items:center;gap:var(--space-2);margin:0">
            <input type="hidden" name="result"   value="<?= e($filterRes) ?>">
            <input type="hidden" name="sort_col" value="<?= e($sortCol) ?>">
            <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">

            <!-- Search input -->
            <div style="position:relative">
                <?= icon('ic_fluent_search_24_filled', 14, 'position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);pointer-events:none') ?>
                <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                       style="padding:0 var(--space-3) 0 32px;height:32px;min-height:32px;font-size:var(--text-sm);width:220px;border-radius:var(--radius-sm)"
                       placeholder="Search name, email, course…">
            </div>

            <button type="submit" style="display:none" aria-hidden="true"></button>
        </form>

        <?php if ($canCloseCycle): ?>
            <!-- Close Admissions (SSO / Admin) — bulk-rejects every
                 unreleased applicant so leftover rows don't sit forever. -->
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="openCloseAdmissionsModal()"
                    style="display:inline-flex;align-items:center;gap:6px;font-size:var(--text-xs);color:var(--error);border:1px solid var(--error)">
                <?= icon('ic_fluent_lock_closed_24_regular', 13) ?>
                Close Admissions
            </button>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Bucket tabs (primary + de-emphasized Withdrawn) -->
    <div style="display:flex;gap:var(--space-1);flex-wrap:wrap;align-items:flex-end">
        <?php foreach ($primaryTabs as $val => $tab):
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

        <?php // Subtle separator before the de-emphasized archive tab. ?>
        <span aria-hidden="true" style="
            align-self:center;width:1px;height:14px;background:var(--border);
            margin:0 var(--space-2) calc(var(--space-1) + 2px)">
        </span>

        <?php foreach ($secondaryTabs as $val => $tab):
            $active = ($filterRes === $val);
        ?>
            <a href="<?= filterUrl(['result' => $val]) ?>"
               style="
                   padding:var(--space-2) var(--space-3);
                   border-bottom:2px solid <?= $active ? 'var(--text-tertiary)' : 'transparent' ?>;
                   color:<?= $active ? 'var(--text-secondary)' : 'var(--text-tertiary)' ?>;
                   font-size:var(--text-xs);
                   font-weight:<?= $active ? 'var(--weight-medium)' : 'var(--weight-regular)' ?>;
                   white-space:nowrap;text-decoration:none;margin-bottom:-1px;
                   opacity:<?= $active ? '1' : '.75' ?>;
                   transition:color var(--transition-fast),opacity var(--transition-fast);
               "
               onmouseover="this.style.opacity='1'"
               onmouseout="this.style.opacity='<?= $active ? '1' : '.75' ?>'">
                <?= $tab['label'] ?>
                <span style="margin-left:4px;font-size:var(--text-xs);color:var(--text-tertiary)"><?= $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($awaitingCount > 0): ?>
<!-- Awaiting-interview nudge — replaces the old "Awaiting interview" tab.
     SSO can't release these here (Professor hasn't evaluated yet), so
     surface them as a quiet pointer to the Interviews page where the
     action actually lives. -->
<div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-4)">
    <a href="<?= url('/staff/interviews/queue') ?>"
       style="
           display:inline-flex;align-items:center;gap:var(--space-2);
           padding:4px var(--space-3);border-radius:var(--radius-full);
           background:var(--bg-secondary);border:1px solid var(--border);
           font-size:var(--text-xs);color:var(--text-tertiary);text-decoration:none;
           transition:background var(--transition-fast),color var(--transition-fast);
       "
       onmouseover="this.style.background='var(--bg-overlay)';this.style.color='var(--text-secondary)'"
       onmouseout="this.style.background='var(--bg-secondary)';this.style.color='var(--text-tertiary)'"
       title="Open the Interviews queue to record Pass/Reject">
        <?= icon('ic_fluent_clock_24_regular', 12) ?>
        <span><strong style="color:var(--text-secondary);font-weight:var(--weight-medium)"><?= $awaitingCount ?></strong>
            applicant<?= $awaitingCount === 1 ? '' : 's' ?> awaiting Professor evaluation</span>
        <?= icon('ic_fluent_chevron_right_24_regular', 12) ?>
    </a>
</div>
<?php endif; ?>

<?php if (!empty($slotCaps)): ?>
<!-- ── Slot capacity indicator ────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;gap:var(--space-2);margin-bottom:var(--space-4)">
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
        border-radius:var(--radius-md);
        background:var(--bg-elevated);
        font-size:var(--text-xs);
        min-width:180px;
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
        <div style="height:4px;background:var(--border);border-radius:var(--radius-full);overflow:hidden">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:var(--radius-full);transition:width .3s"></div>
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

<style>
/* Make the table card stretch to fill the .page area so the gap below the
   card matches the .page horizontal padding (var(--space-8) = 32px). */
.page:has(.results-table-card) { display:flex; flex-direction:column; }
.results-table-card { flex:1; min-height:300px; }
</style>

<!-- ── Results table ──────────────────────────────────────── -->
<div class="card results-table-card" style="padding:0;overflow:hidden;display:flex;flex-direction:column">
    <table class="table" id="results-table">
        <thead>
            <tr>
                <?php if ($canRelease): ?>
                <th style="width:40px;padding-left:var(--space-3)">
                    <input type="checkbox" id="res-select-all" onchange="resToggleAll(this)"
                           style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                </th>
                <?php endif; ?>
                <?= results_sortable_th('applicant', 'Applicant',   $sortCol, $sortDir, $search, $filterRes) ?>
                <?= results_sortable_th('course',    'Course',      $sortCol, $sortDir, $search, $filterRes) ?>
                <th>Exam Score</th>
                <th>Interview</th>
                <?= results_sortable_th('result',    'Result',      $sortCol, $sortDir, $search, $filterRes) ?>
                <?= results_sortable_th('released',  'Released',    $sortCol, $sortDir, $search, $filterRes) ?>
                <th style="width:160px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($result['data'])): ?>
            <?php foreach ($result['data'] as $row):
                $bucket     = $row['bucket'] ?? 'awaiting';
                $isReady    = ($bucket === 'ready_accept' || $bucket === 'ready_reject');
                $selectable = $canRelease && $isReady;
                $fullName   = format_full_name($row);
            ?>
                <tr class="res-bulk-row" data-id="<?= (int)$row['id'] ?>" data-bucket="<?= e($bucket) ?>">
                    <?php if ($canRelease): ?>
                    <td style="padding-left:var(--space-3)">
                        <?php if ($selectable): ?>
                        <input type="checkbox" class="res-check" value="<?= (int)$row['id'] ?>"
                               onchange="resUpdateSelection()"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <td>
                        <button type="button"
                                data-applicant-panel="<?= (int)$row['id'] ?>"
                                title="View applicant details"
                                style="background:none;border:none;padding:0;cursor:pointer;text-align:left;width:100%">
                            <div style="font-weight:var(--weight-medium);color:var(--text-primary);white-space:nowrap"><?= e($fullName) ?></div>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary);white-space:nowrap"><?= e($row['email']) ?></div>
                        </button>
                    </td>

                    <td style="font-size:var(--text-sm)"><?= e($row['course_applied']) ?></td>

                    <!-- Exam score -->
                    <td style="font-size:var(--text-sm);white-space:nowrap">
                        <?php if ($row['exam_score'] !== null): ?>
                            <?= (int)$row['exam_score'] ?>/<?= (int)$row['exam_total'] ?>
                            <?php if ((int)$row['exam_passed'] === 1): ?>
                                <span style="color:var(--success)">&nbsp;Passed</span>
                            <?php elseif ((int)$row['exam_passed'] === 0): ?>
                                <span style="color:var(--error)">&nbsp;Failed</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary)">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Interview status + Pass/Reject -->
                    <td style="font-size:var(--text-sm);white-space:nowrap">
                        <?php if ($row['interview_status']): ?>
                            <?php
                                $iMap = [
                                    'scheduled'   => ['badge-uploaded', 'Scheduled'],
                                    'checked_in'  => ['badge-uploaded', 'Checked In'],
                                    'in_progress' => ['badge-review',   'In Progress'],
                                    'completed'   => ['badge-approved', 'Completed'],
                                    'no_show'     => ['badge-rejected', 'No-show'],
                                ];
                                [$ibadge, $ilabel] = $iMap[$row['interview_status']] ?? ['badge-pending', ucfirst($row['interview_status'])];
                                $evalLabel = '';
                                $evalColor = '';
                                if ($row['evaluation_result'] === 'pass') { $evalLabel = 'Pass'; $evalColor = 'var(--success)'; }
                                elseif ($row['evaluation_result'] === 'reject') { $evalLabel = 'Reject'; $evalColor = 'var(--error)'; }
                            ?>
                            <span class="badge <?= $ibadge ?>"
                                  <?= $row['interview_notes'] ? 'title="' . e($row['interview_notes']) . '"' : '' ?>>
                                <?= $ilabel ?><?= $evalLabel ? ' · ' : '' ?>
                                <?php if ($evalLabel): ?>
                                    <span style="color:<?= $evalColor ?>;font-weight:var(--weight-semibold)"><?= $evalLabel ?></span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary)">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Result column -->
                    <td style="white-space:nowrap">
                        <?php if ($bucket === 'withdrawn'): ?>
                            <span class="badge" style="color:#6b7280;background:#f3f4f6">Withdrawn</span>
                        <?php elseif ($bucket === 'released'): ?>
                            <span class="badge badge-<?= $row['admission_result'] ?>"
                                  <?= $row['admission_remarks'] ? 'title="' . e($row['admission_remarks']) . '"' : '' ?>>
                                <?= e(RESULT_LABELS[$row['admission_result']] ?? ucfirst($row['admission_result'])) ?>
                            </span>
                        <?php elseif ($bucket === 'ready_accept'): ?>
                            <span class="badge badge-approved" title="Professor recommends: Accept">Recommended: Accept</span>
                        <?php elseif ($bucket === 'ready_reject'): ?>
                            <span class="badge badge-rejected" title="Professor recommends: Reject">Recommended: Reject</span>
                        <?php else: /* awaiting */ ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">Awaiting interview</span>
                        <?php endif; ?>
                    </td>

                    <!-- Released-on date -->
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?= $row['released_at'] ? format_date($row['released_at'], 'M j, Y') : '—' ?>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap">

                        <!-- View Details button — visible to all roles -->
                        <button type="button" class="btn btn-ghost btn-sm"
                                data-applicant-panel="<?= (int)$row['id'] ?>"
                                title="View applicant details"
                                style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px">
                            <?= icon('ic_fluent_eye_show_24_regular', 13) ?>
                            View
                        </button>

                        <?php if ($bucket === 'withdrawn'): ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">Withdrawn</span>

                        <?php elseif ($bucket === 'released'): ?>
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
                                $confirmReject = "Release {$fullName} as Rejected? The applicant will be notified by email.";
                            ?>
                            <!-- Accept button -->
                            <?php if ($recAccept): ?>
                                <!-- Matches recommendation: direct release with a confirm. -->
                                <form method="POST" action="<?= url('/staff/results/' . $row['id']) ?>" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"   value="release">
                                    <input type="hidden" name="decision" value="accepted">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:var(--success);color:#fff;border-color:var(--success);font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px"
                                            onclick="return confirm(<?= htmlspecialchars(json_encode($confirmAccept), ENT_QUOTES) ?>)">
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

                            <!-- Reject button -->
                            <?php if (!$recAccept): ?>
                                <!-- Matches recommendation: direct release with a confirm. -->
                                <form method="POST" action="<?= url('/staff/results/' . $row['id']) ?>" style="margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"   value="release">
                                    <input type="hidden" name="decision" value="rejected">
                                    <button type="submit" class="btn btn-sm"
                                            style="background:var(--error);color:#fff;border-color:var(--error);font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px"
                                            onclick="return confirm(<?= htmlspecialchars(json_encode($confirmReject), ENT_QUOTES) ?>)">
                                        <?= icon('ic_fluent_dismiss_circle_24_regular', 13) ?>
                                        Reject
                                    </button>
                                </form>
                            <?php else: ?>
                                <!-- Override: Professor recommended Accept. Demand a reason. -->
                                <button type="button" class="btn btn-ghost btn-sm"
                                        style="font-size:var(--text-xs);display:inline-flex;align-items:center;gap:4px;border:1px dashed var(--error);color:var(--error)"
                                        title="Override Professor recommendation (reason required)"
                                        onclick="openReleaseOverrideModal(<?= (int)$row['id'] ?>, <?= $nameJson ?>, 'rejected', <?= $bucketJson ?>)">
                                    <?= icon('ic_fluent_dismiss_circle_24_regular', 13) ?>
                                    Reject&hellip;
                                </button>
                            <?php endif; ?>

                        <?php elseif ($bucket === 'ready_accept'): ?>
                            <span style="font-size:var(--text-xs);color:var(--success);font-weight:var(--weight-medium)">Recommended: Accept</span>

                        <?php elseif ($bucket === 'ready_reject'): ?>
                            <span style="font-size:var(--text-xs);color:var(--error);font-weight:var(--weight-medium)">Recommended: Reject</span>

                        <?php else: /* awaiting */ ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">Awaiting interview</span>
                        <?php endif; ?>

                        </div><!-- /actions flex -->
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (empty($result['data'])): ?>
        <!-- Empty state — fills remaining card height, centered both axes, no hover -->
        <div class="empty-state" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:var(--space-3);color:var(--text-tertiary);padding:var(--space-8)">
            <?= icon('ic_fluent_clipboard_24_regular', 32) ?>
            <div>No applicants found.</div>
        </div>
    <?php else: ?>
        <!-- Filler below the last row so the empty space inherits a top divider line -->
        <div style="flex:1;border-top:1px solid var(--border)"></div>
    <?php endif; ?>
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
                            border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);
                            font-size:var(--text-xs);color:var(--text-secondary)">
                    <strong style="color:var(--warning)">Admin override.</strong>
                    Changing a released result is audited. Use only when correcting a clerical error or
                    handling an exceptional case (e.g. flagged for review).
                </div>
                <p id="override-name" style="font-weight:var(--weight-medium);margin:0"></p>
                <div>
                    <label class="form-label">Result <span style="color:var(--error)">*</span></label>
                    <select name="result" class="form-control" id="override-result" required>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Reason / remarks <span style="color:var(--error)">*</span></label>
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
                    border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);
                    font-size:var(--text-xs);color:var(--text-secondary)">
                    <strong style="color:var(--warning)">Heads up.</strong>
                    The Professor interviewed this applicant face-to-face. Overriding their
                    recommendation requires a written reason and will be recorded in the audit log.
                </div>
                <p id="release-override-name" style="font-weight:var(--weight-medium);margin:0"></p>
                <div>
                    <label class="form-label">Result <span style="color:var(--error)">*</span></label>
                    <div id="release-override-result-label" style="font-size:var(--text-sm);color:var(--text-secondary)"></div>
                </div>
                <div>
                    <label class="form-label" for="release-override-reason">
                        Reason for override <span style="color:var(--error)">*</span>
                    </label>
                    <textarea name="reason" id="release-override-reason" class="form-control"
                              rows="4" required
                              placeholder="Why are you overriding the Professor's recommendation?"></textarea>
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
                            border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);
                            font-size:var(--text-xs);color:var(--text-secondary)">
                    <strong style="color:var(--error)">This is irreversible.</strong>
                    Every applicant who has not yet been released will be marked as
                    <strong>Rejected</strong> and emailed. Withdrawn applicants and
                    already-released results are not touched.
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
                <button type="submit" class="btn" style="background:var(--error);color:#fff;border-color:var(--error)"
                        onclick="return confirm('Close admissions and bulk-reject every unreleased applicant? This cannot be undone.')">
                    Close Admissions
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── Suggest course modal (kept) ─────────────────────── -->
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

<script>
<?php if ($canOverride): ?>
function openOverrideModal(appId, name, currentResult, currentRemarks) {
    document.getElementById('override-form').action = '<?= url('/staff/results/') ?>' + appId;
    document.getElementById('override-name').textContent = name;
    var resSel = document.getElementById('override-result');
    // Migrate any legacy 'waitlisted' record to a sensible default — admin must
    // re-pick Accept or Reject to save (the dropdown only offers those two).
    resSel.value = (currentResult === 'accepted' || currentResult === 'rejected') ? currentResult : 'accepted';
    document.getElementById('override-remarks').value = currentRemarks || '';
    document.getElementById('override-modal').style.display = 'flex';
}
document.getElementById('override-modal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
<?php endif; ?>

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

    <button type="button" class="btn btn-sm" onclick="resBulkRelease('accepted')"
            style="background:var(--success);color:#fff;border-color:var(--success);display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 14) ?>
        Accept Selected
    </button>

    <button type="button" class="btn btn-sm" onclick="resBulkRelease('rejected')"
            style="background:var(--error);color:#fff;border-color:var(--error);display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_dismiss_circle_24_regular', 14) ?>
        Reject Selected
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

<style>
@keyframes resToolbarSlideUp {
    from { opacity:0; transform:translateX(-50%) translateY(16px); }
    to   { opacity:1; transform:translateX(-50%) translateY(0); }
}
@keyframes resUndoSlideUp {
    from { opacity:0; transform:translateX(-50%) translateY(20px); }
    to   { opacity:1; transform:translateX(-50%) translateY(0); }
}
tr.res-bulk-row.res-selected { background:var(--accent-muted); }
tr.res-bulk-row.res-selected td:first-child { box-shadow:inset 3px 0 0 var(--accent); }
</style>

<script>
/* ── Undo toast (bulk release) ──────────────────────────────── */
function showResUndoToast(msg, onCommit) {
    var existing = document.getElementById('res-undo-toast');
    if (existing) { existing._cancelFn && existing._cancelFn(); existing.remove(); }

    var DELAY = 6000;
    var toast = document.createElement('div');
    toast.id = 'res-undo-toast';
    toast.style.cssText = [
        'position:fixed;bottom:calc(var(--space-6) + 56px);left:50%;transform:translateX(-50%);',
        'z-index:600;background:var(--bg-elevated);border:1px solid var(--border);',
        'border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);',
        'padding:12px 20px;display:flex;align-items:center;gap:12px;',
        'font-size:var(--text-sm);min-width:320px;',
        'animation:resUndoSlideUp .25s ease'
    ].join('');

    // Progress bar
    var bar = document.createElement('div');
    bar.style.cssText = 'position:absolute;bottom:0;left:0;height:3px;background:var(--accent);border-radius:0 0 var(--radius-lg) var(--radius-lg);width:100%;transition:width ' + DELAY + 'ms linear';

    toast.innerHTML = '<span style="flex:1">' + msg + '</span>'
        + '<button id="res-undo-btn" style="background:none;border:1px solid var(--border);border-radius:var(--radius-sm);padding:4px 14px;cursor:pointer;font-size:var(--text-sm);color:var(--text-primary);font-weight:500;white-space:nowrap">Undo</button>'
        + '<button onclick="dismissResUndoToast()" style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);font-size:16px;padding:0 4px">&times;</button>';
    toast.appendChild(bar);
    document.body.appendChild(toast);

    // Kick off the progress bar shrink
    requestAnimationFrame(function() { requestAnimationFrame(function() { bar.style.width = '0'; }); });

    var timer = setTimeout(function() {
        dismissResUndoToast();
        onCommit();
    }, DELAY);

    toast._cancelFn = function() { clearTimeout(timer); };

    document.getElementById('res-undo-btn').addEventListener('click', function() {
        dismissResUndoToast();
        resClearSelection();
    });
}

function dismissResUndoToast() {
    var t = document.getElementById('res-undo-toast');
    if (t) { t._cancelFn && t._cancelFn(); t.remove(); }
}

/* ── Single-release undo intercept ──────────────────────────── */
// Intercept individual "Release as Accept/Reject" form submits and
// show a short undo window before actually posting.
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || form.id === 'res-bulk-form') return;
    var actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || actionInput.value !== 'release') return;

    e.preventDefault();

    var btn = form.querySelector('button[type="submit"]');
    var label = btn ? btn.textContent.trim() : 'Releasing…';

    showResUndoToast(label + ' — releasing…', function() { form.submit(); });
}, true);

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
    if (allCb) {
        allCb.checked       = count > 0 && count === total;
        allCb.indeterminate = count > 0 && count < total;
    }

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
    var allCb = document.getElementById('res-select-all');
    if (allCb) allCb.checked = false;
    document.querySelectorAll('.res-check').forEach(function(cb) { cb.checked = false; });
    resUpdateSelection();
}

function resBulkRelease(decision) {
    var ids = resGetSelectedIds();
    if (ids.length === 0) return;
    if (decision !== 'accepted' && decision !== 'rejected') return;

    // Tally how many rows match the chosen decision vs. how many will count
    // as overrides of the Professor's recommendation.
    var matchBucket = (decision === 'accepted') ? 'ready_accept' : 'ready_reject';
    var matches = 0, overrides = 0;
    ids.forEach(function(id) {
        var tr = document.querySelector('tr.res-bulk-row[data-id="' + id + '"]');
        if (!tr) return;
        if (tr.getAttribute('data-bucket') === matchBucket) matches++;
        else overrides++;
    });

    var label = (decision === 'accepted') ? 'Accepted' : 'Rejected';
    var msg = 'Release ' + ids.length + ' selected applicant(s) as ' + label + '?\n\n'
            + '\u2022 ' + matches   + ' match the Professor\u2019s recommendation\n'
            + '\u2022 ' + overrides + ' will override the Professor\u2019s recommendation\n\n'
            + 'The applicants will be notified by email.';

    if (!confirm(msg)) return;

    // If any rows override the recommendation, ask for a single shared
    // reason that gets recorded against every override in the audit log.
    var reason = '';
    if (overrides > 0) {
        reason = prompt(
            overrides + ' of these applicants will be released against the Professor\u2019s '
            + 'recommendation. Enter a reason for the override (recorded in the audit log):',
            ''
        );
        if (reason === null) return; // cancelled
        reason = reason.trim();
    }

    var form = document.getElementById('res-bulk-form');
    document.getElementById('res-bulk-action').value =
        (decision === 'accepted') ? 'bulk_accept' : 'bulk_reject';

    // Clear any previous dynamic inputs.
    form.querySelectorAll('input[name="ids[]"], input[name="reason"]').forEach(function(el) { el.remove(); });

    ids.forEach(function(id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    if (reason) {
        var rInput = document.createElement('input');
        rInput.type = 'hidden';
        rInput.name = 'reason';
        rInput.value = reason;
        form.appendChild(rInput);
    }

    // Show undo toast — submits form after 6s unless Undo is clicked.
    showResUndoToast(
        'Releasing ' + ids.length + ' applicant(s) as ' + label + '\u2026',
        function() { form.submit(); }
    );
}

// ── Single-row override modal opener ────────────────────────
// Triggered when the releaser clicks Accept on a Recommended:Reject row
// (or Reject on a Recommended:Accept row). Demands a written reason.
function openReleaseOverrideModal(appId, name, decision, bucket) {
    var modal = document.getElementById('release-override-modal');
    if (!modal) return;
    var form  = document.getElementById('release-override-form');
    form.action = '<?= url('/staff/results/') ?>' + appId;
    document.getElementById('release-override-decision').value = decision;
    document.getElementById('release-override-name').textContent = name;

    var label = (decision === 'accepted')
        ? 'Release as \u201cAccepted\u201d (Professor recommended Reject)'
        : 'Release as \u201cRejected\u201d (Professor recommended Accept)';
    document.getElementById('release-override-result-label').textContent = label;
    document.getElementById('release-override-reason').value = '';
    modal.style.display = 'flex';
}

// Click-outside-to-close for the override modal + close-admissions modal.
(function() {
    var m = document.getElementById('release-override-modal');
    if (m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
    var c = document.getElementById('close-admissions-modal');
    if (c) c.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
})();

<?php if ($canCloseCycle): ?>
function openCloseAdmissionsModal() {
    var modal = document.getElementById('close-admissions-modal');
    if (!modal) return;
    document.getElementById('close-admissions-reason').value = '';
    modal.style.display = 'flex';
}
<?php endif; ?>
</script>
<?php endif; ?>

<?php
// Slide-in applicant detail drawer (markup + JS opener).
include VIEWS_PATH . '/partials/applicant_drawer.php';

$content   = ob_get_clean();
$pageTitle = 'Results';
$activeNav = 'results';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
