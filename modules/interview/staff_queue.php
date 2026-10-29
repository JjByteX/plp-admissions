<?php
// ============================================================
// modules/interview/staff_queue.php
//
// Live interview queue — single unified page that lists every
// auto-assigned applicant. Each row exposes a single "Evaluation"
// action that opens a modal where the interviewer records notes
// plus a Pass / Reject decision.
//
// • Staff see every applicant assigned to a session they own
//   (assigned_to with created_by fallback for legacy rows).
// • Admins see everyone.
// • No-show is fully automatic: once a slot's end time has
//   passed (or, for slots without an explicit end time, one hour
//   after the start time), any still-waiting / still-in-progress
//   row is flipped to no_show right when the page loads. There is
//   no manual "No-show" button anywhere in the UI.
//
// The table is auto-paginated like the audit log: fixed-height rows,
// search + date/slot filters fused into the table card, and a
// pagination bar under it. Filters are GET params (?q= ?date= ?slot=)
// so they survive paging and the 60s auto-refresh.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

// SSO is a setup-only role — they create sessions and assign interviewers
// but they don't conduct interviews themselves, so they have no business
// on the live queue. Bounce them straight to Interview Setup if they end
// up here (typed URL, stale bookmark, etc.).
if (Auth::role() === ROLE_SSO) {
    redirect('/staff/interviews/setup');
}

$db        = db();
$staffId   = Auth::id();
$today     = now_date();
$role      = Auth::role();
$isAdmin   = $role === ROLE_ADMIN;
$isSSO     = $role === ROLE_SSO;
$isDean    = StaffScope::current()->restricted();
// Admin and SSO see every queue row across colleges. Dean sees every
// row whose slot belongs to their own college (oversight).
// Professor sees only rows on a session they personally own.
$canSeeAll = $isAdmin || $isSSO;
$staffDept = StaffScope::current()->department(); // '' unless Dean

// ----------------------------------------------------------------
// College scoping for Admin / SSO
//
// Admin and SSO have access to every college's queue, which is too
// much to render as a single mixed table. Instead, on first visit
// they get a college-picker landing (`_queue_college_select.php`);
// drilling into one sets `?college=...` and we scope the query.
// `?college=__all__` is an explicit "show me everything" escape
// hatch so the unified view stays reachable.
//
// Dean and Professor never reach the picker — Dean is auto-scoped
// to their own department, Professor to their own owned sessions.
// ----------------------------------------------------------------
$collegeFilter = get_str('college');
$showAll       = ($collegeFilter === '__all__');
if ($canSeeAll && $collegeFilter === '') {
    // Admin/SSO landing without a college - hand off to the picker.
    include __DIR__ . '/_queue_college_select.php';
    return;
}

// ----------------------------------------------------------------
// Desk strip — find this interviewer's most-recent (or upcoming)
// physical location so the staff sees where to send walk-ins. Purely
// informational; it does NOT scope the table below. Only Professors
// have a personal desk; oversight roles (Admin/SSO/Dean) skip this.
// ----------------------------------------------------------------
$deskLabel = '';
$deskNotes = '';
if (!$isAdmin && !$isSSO && !$isDean) {
    // Only an active session counts: open and not already past. A closed
    // or finished session must not leave its room/notes on screen.
    try {
        $deskStmt = $db->prepare(
            "SELECT location_label, location_notes
               FROM interview_slots
              WHERE COALESCE(assigned_to, created_by) = ?
                AND status = 'open'
                AND slot_date >= CAST(? AS date)
                AND location_label != ''
              ORDER BY slot_date ASC, slot_time ASC NULLS FIRST
              LIMIT 1"
        );
        $deskStmt->execute([$staffId, $today]);
        $deskRow = $deskStmt->fetch();
        if ($deskRow) {
            $deskLabel = $deskRow['location_label'] ?? '';
            $deskNotes = $deskRow['location_notes'] ?? '';
        }
    } catch (\Throwable $e) {}
}

// ----------------------------------------------------------------
// AUTO NO-SHOW
// Flip any still-waiting / in-progress queue row whose slot has
// already finished into the canonical absent state:
//   status='no_show', interview_status='absent', attendance_status='absent'
// This runs on every page load so the table — and the Absent
// Students tab — always reflect reality without anyone having to
// click a button. The previous inline UPDATE only set q.status,
// which left absent_tab queries (WHERE q.interview_status='absent')
// missing these rows.
//
// "Finished" means:
//   • slot_date is before today, OR
//   • slot_date is today AND end_time is set AND end_time <= NOW, OR
//   • slot_date is today AND end_time is NULL AND slot_time is set
//     AND the start time was more than an hour ago.
// ----------------------------------------------------------------
if (function_exists('auto_detect_interview_no_shows')) {
    try {
        auto_detect_interview_no_shows(null, Auth::id());
    } catch (\Throwable $e) {
        // Schema differences shouldn't block the page from rendering
        error_log('auto-no-show update failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// Load the queue rows.
// • Staff: every row attached to a session they own.
// • Admin: every row, period.
// ----------------------------------------------------------------
$selectCols =
    'SELECT q.id          AS queue_id,
            q.queue_number,
            q.status,
            q.checked_in_at,
            q.interview_notes,
            q.evaluation_result,
            q.evaluated_at,
            a.id          AS app_id,
            a.course_applied,
            a.applicant_type,
            u.name        AS student_name,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.suffix,
            u.email       AS student_email,
            u.phone       AS student_phone,
            u.birthdate   AS student_birthdate,
            u.sex         AS student_sex,
            u.address     AS student_address,
            u.department  AS student_department,
            a.shs_strand,
            a.school_year,
            s.id          AS slot_id,
            s.slot_date   AS slot_date,
            s.slot_time   AS slot_time,
            s.end_time    AS slot_end_time,
            s.department  AS slot_department,
            er.score      AS exam_score,
            er.total_items AS exam_total,
            er.passed     AS exam_passed
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   applicants a      ON a.id = q.applicant_id
     JOIN   users u           ON u.id = a.user_id
     LEFT JOIN exam_results er ON er.applicant_id = a.id ';

$orderTail =
    " ORDER BY
         CASE q.status
             WHEN 'in_progress' THEN 1
             WHEN 'checked_in'  THEN 2
             WHEN 'scheduled'   THEN 3
             WHEN 'completed'   THEN 4
             WHEN 'no_show'     THEN 5
             ELSE 0
         END,
         q.queue_number ASC NULLS FIRST,
         q.created_at   ASC";

if ($canSeeAll && !$showAll) {
    // Admin / SSO with a specific college picked - scope by slot department.
    $stmt = $db->prepare(
        $selectCols .
        ' WHERE s.department = ?' .
        $orderTail
    );
    $stmt->execute([$collegeFilter]);
} elseif ($canSeeAll) {
    // Admin / SSO with the explicit "all" escape hatch - every row, every college.
    $stmt = $db->prepare($selectCols . $orderTail);
    $stmt->execute();
} elseif ($isDean) {
    // Dean — every row whose slot is in their college (oversight).
    // Fall back to the COALESCE(assigned_to, created_by) ownership rule
    // when the dean has no department on file, so they at least see
    // sessions tied to their own user id (rare edge case).
    if ($staffDept !== '') {
        $stmt = $db->prepare(
            $selectCols .
            ' WHERE s.department = ?' .
            $orderTail
        );
        $stmt->execute([$staffDept]);
    } else {
        $stmt = $db->prepare(
            $selectCols .
            ' WHERE COALESCE(s.assigned_to, s.created_by) = ?' .
            $orderTail
        );
        $stmt->execute([$staffId]);
    }
} else {
    // Professor — only rows on a session they personally own.
    $stmt = $db->prepare(
        $selectCols .
        ' WHERE COALESCE(s.assigned_to, s.created_by) = ?' .
        $orderTail
    );
    $stmt->execute([$staffId]);
}
$rows = $stmt->fetchAll();

// Build the slot + date maps used by the two toolbar dropdowns.
//
//   $slotMap  — every distinct slot the visible rows belong to,
//               with a per-slot applicant count. Drives the
//               "Filter by slot" dropdown.
//   $dateMap  — every distinct slot date with a per-date applicant
//               count and a "past" flag. Drives the
//               "Filter by date" dropdown.
//
// Both filters are pure client-side (the rows already include
// data-slot / data-date attributes), so there's no extra DB hit.
$slotMap = [];
$dateMap = [];
$todayDate = $today; // YYYY-MM-DD, declared at the top of the file
foreach ($rows as $r) {
    $sid  = (int)$r['slot_id'];
    $date = (string)($r['slot_date'] ?? '');
    if ($sid > 0 && !isset($slotMap[$sid])) {
        $slotMap[$sid] = [
            'id'         => $sid,
            'date'       => $date,
            'time'       => $r['slot_time'] ?? '',
            'end_time'   => $r['slot_end_time'] ?? '',
            'department' => $r['slot_department'] ?? '',
            'count'      => 0,
        ];
    }
    if ($sid > 0) $slotMap[$sid]['count']++;

    if ($date !== '') {
        if (!isset($dateMap[$date])) {
            $dateMap[$date] = [
                'date'    => $date,
                'count'   => 0,
                'is_past' => $date < $todayDate,
            ];
        }
        $dateMap[$date]['count']++;
    }
}
// Sort slots by date ASC, time ASC.
usort($slotMap, function ($a, $b) {
    return ($a['date'] <=> $b['date']) ?: ($a['time'] <=> $b['time']);
});
// Sort dates ASC.
usort($dateMap, function ($a, $b) {
    return $a['date'] <=> $b['date'];
});

// Format name as "SURNAME SUFFIX, FIRST MIDDLE" (uses shared helper)
function queue_format_name(array $r): string {
    return format_full_name($r);
}

// Status badge mapping — "Waiting" covers scheduled + checked_in, since
// auto-checkin collapses those two states into one user-facing label.
function queue_status_badge(string $status): array {
    return match ($status) {
        'scheduled'   => ['Waiting',     'badge-info'],
        'checked_in'  => ['Waiting',     'badge-info'],
        'in_progress' => ['In Progress', 'badge-approved'],
        'completed'   => ['Done',        'badge-approved'],
        'no_show'     => ['No-show',     'badge-rejected'],
        default       => [ucfirst($status), 'badge-info'],
    };
}

// ----------------------------------------------------------------
// Filters (search + date + slot) — applied server-side so pagination
// counts only the rows that match.
//
// The date / slot dropdowns are built from the UNFILTERED rows above
// (so they always list every option); a slot that doesn't belong to
// the chosen date is dropped, the same cascade the old client-side
// filter did.
// ----------------------------------------------------------------
$filterQ    = get_str('q');
$filterDate = get_str('date');
$filterSlot = get_int('slot');

$slotById = [];
foreach ($slotMap as $sl) { $slotById[(int)$sl['id']] = $sl; }
if (!in_array($filterDate, array_column($dateMap, 'date'), true)) {
    $filterDate = '';
}
if (!isset($slotById[$filterSlot])
    || ($filterDate !== '' && (string)$slotById[$filterSlot]['date'] !== $filterDate)) {
    $filterSlot = 0;
}

$visibleRows = array_values(array_filter($rows, function (array $r) use ($filterQ, $filterDate, $filterSlot): bool {
    if ($filterDate !== '' && (string)($r['slot_date'] ?? '') !== $filterDate) return false;
    if ($filterSlot > 0 && (int)$r['slot_id'] !== $filterSlot) return false;
    if ($filterQ !== '') {
        $hay = mb_strtolower(queue_format_name($r) . ' ' . ($r['course_applied'] ?? '') . ' ' . ($r['applicant_type'] ?? ''));
        if (!str_contains($hay, mb_strtolower($filterQ))) return false;
    }
    return true;
}));
$hasFilters = ($filterQ !== '' || $filterDate !== '' || $filterSlot > 0);

// Pagination — same auto-fit scheme as the audit log: AutoPageSize (app.js)
// measures how many fixed-height rows fit and reloads with ?per_page=.
$perPage  = auto_per_page('interview-queue');
$total    = count($visibleRows);
$pages    = max(1, (int)ceil($total / $perPage));
$page     = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$result   = ['total' => $total, 'current_page' => $page, 'last_page' => $pages, 'per_page' => $perPage];
$pageRows = array_slice($visibleRows, ($page - 1) * $perPage, $perPage);

// URL helper — keeps the college scope + every filter + per_page while
// overriding just the given keys (page links, clear link).
$queueUrl = function (array $merge = []) use ($canSeeAll, $collegeFilter, $filterQ, $filterDate, $filterSlot, $perPage): string {
    $base = [
        'college'  => $canSeeAll ? $collegeFilter : '',
        'q'        => $filterQ,
        'date'     => $filterDate,
        'slot'     => $filterSlot > 0 ? $filterSlot : '',
        'per_page' => $perPage,
        'page'     => 1,
    ];
    return url('/staff/interviews/queue') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
};

ob_start();
?>


<?php if ($msg = Session::getFlash('success')): ?>
    <div id="iq-flash-success" class="alert alert-success mb-4 flex align-items-center gap-3 flex-shrink-0">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
        <span class="flex-1"><?= e($msg) ?></span>
        <button onclick="this.parentElement.remove()" class="btn btn-icon" style="color:inherit" aria-label="Dismiss">&times;</button>
    </div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div id="iq-flash-error" class="alert alert-error mb-4 flex align-items-center gap-3 flex-shrink-0">
        <?= icon('ic_fluent_info_24_regular', 16) ?>
        <span class="flex-1"><?= e($msg) ?></span>
        <button onclick="this.parentElement.remove()" class="btn btn-icon" style="color:inherit" aria-label="Dismiss">&times;</button>
    </div>
<?php endif; ?>

<!-- ============================================================
     BACK BUTTON / CONTEXT HEADER
     ------------------------------------------------------------
     • Admin / SSO with a college filter (or all-mode): give them
       a way back to the college picker.
     • Dean and Professor land here directly from the sidebar
       (this IS their primary view), so no back button.
============================================================ -->
<?php if ($canSeeAll): ?>
    <div class="flex align-items-center gap-3 mb-5 flex-wrap">
        <span class="text-sm text-secondary">
            <?php if ($showAll): ?>
                <strong>All colleges</strong>
            <?php else: ?>
                <strong><?= e($collegeFilter) ?></strong>
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>

<!-- ============================================================
     DESK INFO STRIP (Professor only)
     Only shown when a desk location has been set for this professor
     by SSO / Admin during interview setup. If empty, render nothing
     - setup is not the professor's responsibility.
============================================================ -->
<?php if (!$isAdmin && !$isSSO && !$isDean && $deskLabel): ?>
    <div class="flex align-items-center gap-3 flex-shrink-0 py-3 px-4 mb-4 bg-elevated bd rounded text-sm">
        <?= icon('ic_fluent_location_24_regular', 13, 'color:var(--text-tertiary);flex-shrink:0') ?>
        <span class="fw-medium"><?= e($deskLabel) ?></span>
        <?php if ($deskNotes): ?>
            <span class="text-tertiary"><?= e($deskNotes) ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (empty($rows)):
    // ----------------------------------------------------------------
    // Empty state — full-page, role-aware. We intentionally hide the
    // toolbar AND the table card so an empty queue isn't dressed up to
    // look like a populated one.
    // ----------------------------------------------------------------
    if ($canSeeAll && $showAll) {
        $emptyTitle = 'No interviews scheduled yet';
        $emptyBody  = 'Applicants will appear here once SSO sets up sessions and places them.';
    } elseif ($canSeeAll) {
        $emptyTitle = 'No interviews in ' . $collegeFilter . ' yet';
        $emptyBody  = 'Applicants will appear here once a session in this college is set up.';
    } elseif ($isDean) {
        $deptLabel  = $staffDept !== '' ? $staffDept : 'your college';
        $emptyTitle = 'No interviews in ' . $deptLabel . ' yet';
        $emptyBody  = 'Applicants will appear here once a session in your college is set up.';
    } else {
        $emptyTitle = 'No interviews assigned to you yet';
        $emptyBody  = 'Applicants will appear here once SSO places them on your session.';
    }
?>
    <!--
        Use the same .iq-table-card wrapper a populated queue uses so the
        empty state extends the full width AND height of the page area
        (the .page:has(.iq-table-card) rule above turns the page into a
        flex column, and flex:1 makes this card claim the leftover space).
    -->
    <div class="card iq-table-card py-12 px-8 flex flex-column align-items-center justify-content-center gap-3 text-center"
        >
        <div class="rounded bg-subtle text-tertiary flex align-items-center justify-content-center" style="width:56px;height:56px">
            <?= icon('ic_fluent_calendar_ltr_24_regular', 28) ?>
        </div>
        <div class="text-base fw-semibold text-main">
            <?= e($emptyTitle) ?>
        </div>
        <div class="mwpx-480 text-sm text-tertiary" style="line-height:1.5">
            <?= e($emptyBody) ?>
        </div>
    </div>
<?php else: ?>

<!-- ============================================================
     TABLE - one .auto-table-wrap (measured by AutoPageSize): the card
     holds the search + date/slot filters fused to the fixed-height
     table, and the pagination bar sits under it. Same structure as
     the audit log.
============================================================ -->
<div class="auto-table-wrap" data-auto-page-size="interview-queue" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + filters, one row, inside the card -->
    <form method="GET" action="<?= e(url('/staff/interviews/queue')) ?>" class="auto-table-toolbar">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
        <?php if ($canSeeAll): ?>
            <input type="hidden" name="college" value="<?= e($collegeFilter) ?>">
        <?php endif; ?>

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="q" class="form-input" aria-label="Filter by name or course" placeholder="Filter by name or course…"
                   value="<?= e($filterQ) ?>" autocomplete="off">
        </div>

        <?php if (count($dateMap) > 1): ?>
        <select name="date" class="form-input" aria-label="Interview date" style="width:230px" onchange="this.form.submit()"
                title="Filter by interview date">
            <?php $totalApplicants = count($rows); ?>
            <option value="">
                All dates · <?= (int)$totalApplicants ?> applicant<?= $totalApplicants === 1 ? '' : 's' ?>
            </option>
            <?php foreach ($dateMap as $d): ?>
                <option value="<?= e($d['date']) ?>" <?= $filterDate === (string)$d['date'] ? 'selected' : '' ?>>
                    <?= format_date($d['date']) ?><?= $d['is_past'] ? ' (Past)' : '' ?>
                    · <?= (int)$d['count'] ?> applicant<?= (int)$d['count'] === 1 ? '' : 's' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <?php if (count($slotMap) > 1): ?>
        <select name="slot" class="form-input wpx-260" aria-label="Interview slot" onchange="this.form.submit()"
                title="Filter by specific slot within the selected date">
            <?php $totalSlotApplicants = array_sum(array_column($slotMap, 'count')); ?>
            <option value="">
                All slots · <?= (int)$totalSlotApplicants ?> applicant<?= $totalSlotApplicants === 1 ? '' : 's' ?>
            </option>
            <?php foreach ($slotMap as $sl): ?>
                <?php
                    // With a date chosen, only that date's slots are listed.
                    if ($filterDate !== '' && (string)$sl['date'] !== $filterDate) continue;
                    $slPast = (string)$sl['date'] !== '' && (string)$sl['date'] < $todayDate;
                    $slLbl  = format_date($sl['date']);
                    if (!empty($sl['time'])) {
                        $slLbl .= ' · ' . format_time($sl['time']);
                        if (!empty($sl['end_time'])) {
                            $slLbl .= ' – ' . format_time($sl['end_time']);
                        }
                    }
                    if ($slPast) $slLbl .= ' (Past)';
                    if (!empty($sl['department'])) {
                        $slLbl .= ' · ' . $sl['department'];
                    }
                    $slLbl .= ' · ' . (int)$sl['count'] . ' applicant' . ((int)$sl['count'] === 1 ? '' : 's');
                ?>
                <option value="<?= (int)$sl['id'] ?>" <?= $filterSlot === (int)$sl['id'] ? 'selected' : '' ?>>
                    <?= e($slLbl) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <?php if ($hasFilters): ?>
            <a href="<?= e($queueUrl(['q' => '', 'date' => '', 'slot' => '', 'page' => 1])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table body — fixed-height rows, sized so the rows AutoPageSize
         picks fit exactly; it only scrolls as a safety net -->
    <div class="auto-table-body">
        <table class="auto-table" id="queue-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <?php if ($canSeeAll && $showAll): ?>
                        <th class="wpx-140">College</th>
                    <?php endif; ?>
                    <th>Course</th>
                    <th class="wpx-110">Type</th>
                    <th class="wpx-120">Status</th>
                    <th class="wpx-190">Actions</th>
                </tr>
            </thead>
            <?php if (!empty($pageRows)): ?>
            <tbody>
            <?php foreach ($pageRows as $r):
                [$badgeText, $badgeClass] = queue_status_badge($r['status']);
                $rowClass  = 'iq-row-' . str_replace('_', '-', $r['status']);
                $name      = queue_format_name($r);
                $course    = $r['course_applied'] ?? '';
                $type      = $r['applicant_type'] ?? '';
                $isInProg  = $r['status'] === 'in_progress';
                $isFinal   = in_array($r['status'], ['completed', 'no_show'], true);
                $existing  = $r['interview_notes'] ?? '';
            ?>
                <tr class="<?= $rowClass ?>">
                    <td>
                        <?php // Single-line row - plain name.
                              // Eval result moved into the Status column.
                              // Details open from the eye View button only. ?>
                        <span class="auto-table-clip iq-name-cell"><?= e($name) ?></span>
                    </td>

                    <?php if ($canSeeAll && $showAll): ?>
                        <td class="text-sm">
                            <?php $col = (string)($r['slot_department'] ?? ''); ?>
                            <span class="auto-table-clip"><?= $col !== '' ? e($col) : '<span class="text-tertiary">-</span>' ?></span>
                        </td>
                    <?php endif; ?>

                    <td class="text-sm" title="<?= e($course) ?>">
                        <span class="auto-table-clip"><?= $course !== '' ? e($course) : '<span class="text-tertiary">-</span>' ?></span>
                    </td>

                    <td class="text-sm">
                        <span class="auto-table-clip"><?= $type !== '' ? e(ucfirst($type)) : '<span class="text-tertiary">-</span>' ?></span>
                    </td>

                    <td>
                        <?php
                            // For completed rows, surface the eval result (Pass / Decline)
                            // as the badge itself — same pattern as the Results page.
                            // For non-completed rows (Scheduled / In Progress / No-show / etc.)
                            // we still show the queue stage as the badge.
                            $statusBadgeClass = $badgeClass;
                            $statusBadgeText  = $badgeText;
                            if ($r['status'] === 'completed' && $r['evaluation_result']) {
                                if ($r['evaluation_result'] === 'pass') {
                                    $statusBadgeClass = 'badge-approved';
                                    $statusBadgeText  = 'Pass';
                                } elseif ($r['evaluation_result'] === 'reject') {
                                    $statusBadgeClass = 'badge-rejected';
                                    $statusBadgeText  = 'Decline';
                                }
                            }
                        ?>
                        <span class="badge <?= $statusBadgeClass ?>"
                              <?= ($isFinal && $existing !== '') ? 'title="' . e($existing) . '"' : '' ?>>
                            <?php if ($isInProg): ?>
                                <span class="rounded-circle me-1" style="display:inline-block;width:6px;height:6px;background:var(--white);animation:pulse-dot 1.4s infinite"></span>
                            <?php endif; ?>
                            <?= e($statusBadgeText) ?>
                        </span>
                    </td>

                    <td>
                        <div class="iq-actions">
                            <button type="button"
                                    class="btn btn-ghost btn-sm iq-view-btn"
                                    data-applicant-panel="<?= (int)$r['app_id'] ?>"
                                    title="View applicant details"
                                    aria-label="View applicant details">
                                <?= icon('ic_fluent_eye_show_24_regular', 14) ?>
                            </button>
                            <?php if ($isFinal || $isDean): ?>
                                <?php // Final-state rows and Dean (oversight, read-only): View-only. The Status
                                      // column already shows Pass / Decline / No-show,
                                      // so we don't repeat it here. ?>
                            <?php else: ?>
                                <?php
                                    $evalData = [
                                        'queueId' => (int)$r['queue_id'],
                                        'name'    => $name,
                                        'course'  => $course,
                                        'type'    => $type,
                                        'notes'   => $existing,
                                        'email'   => $r['student_email'] ?? '',
                                        'phone'   => $r['student_phone'] ?? '',
                                        'birthdate' => $r['student_birthdate'] ?? '',
                                        'sex'     => $r['student_sex'] ?? '',
                                        'address' => $r['student_address'] ?? '',
                                        'strand'  => $r['shs_strand'] ?? '',
                                        'school_year' => $r['school_year'] ?? '',
                                        'exam_score'  => $r['exam_score'] !== null ? (int)$r['exam_score'] : null,
                                        'exam_total'  => $r['exam_total'] !== null ? (int)$r['exam_total'] : null,
                                        'exam_passed' => $r['exam_passed'] !== null ? (int)$r['exam_passed'] : null,
                                    ];
                                ?>
                                <button type="button" class="btn btn-primary btn-sm"
                                        onclick='openEvalModal(<?= htmlspecialchars(json_encode($evalData), ENT_QUOTES) ?>)'>
                                    <?= icon('ic_fluent_edit_24_regular', 13) ?>
                                    Evaluation
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($pageRows)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_calendar_ltr_24_regular', 32) ?>
            <div>No applicants match your filters.</div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $queueUrl(['page' => $p]), 'applicants') ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->
<?php endif; // empty($rows) ?>

<!-- ============================================================
     EVALUATION MODAL - notes + Pass / Reject
============================================================ -->
<div id="eval-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal flex flex-column overflow-hidden" style="max-width:860px;max-height:calc(100vh - 2 * var(--space-4))">
        <div class="modal-header">
            <div class="modal-title">Interview Evaluation</div>
            <button class="btn-icon" type="button" onclick="closeEvalModal()">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>

        <form method="POST" id="eval-form" action="" class="flex flex-column" style="flex:1;min-height:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="complete_with_evaluation">
            <input type="hidden" name="evaluation_result" id="eval-result-input" value="">

            <div class="modal-body flex flex-column gap-4 flex-1 overflow-y-auto" style="min-height:0">
                <div>
                    <label class="form-label" for="eval-notes">Interview notes</label>
                    <textarea id="eval-notes" name="interview_notes" class="form-control"
                              rows="6" placeholder="Interview notes / evaluation remarks…"></textarea>
                    <div class="mt-2 text-xs text-tertiary">
                        Choose Pass or Decline to finalize this interview.
                    </div>
                </div>

                <!-- Applicant details: two columns, long fields use the full row -->
                <div class="bd-t pt-4">
                    <div class="fw-semibold text-sm mb-3">Applicant Details</div>
                    <div class="eval-details">
                        <div class="eval-full">
                            <div class="eval-k">Full Name</div>
                            <div id="eval-detail-name" style="font-weight:var(--weight-medium)"></div>
                        </div>
                        <div id="eval-detail-email-wrap" class="eval-full">
                            <div class="eval-k">Email</div>
                            <div id="eval-detail-email"></div>
                        </div>
                        <div id="eval-detail-phone-wrap" style="display:none">
                            <div class="eval-k">Phone</div>
                            <div id="eval-detail-phone"></div>
                        </div>
                        <div id="eval-detail-bday-wrap" style="display:none">
                            <div class="eval-k">Birthdate</div>
                            <div id="eval-detail-bday"></div>
                        </div>
                        <div id="eval-detail-sex-wrap" style="display:none">
                            <div class="eval-k">Sex</div>
                            <div id="eval-detail-sex"></div>
                        </div>
                        <div>
                            <div class="eval-k">Applicant Type</div>
                            <div id="eval-detail-type"></div>
                        </div>
                        <div id="eval-detail-address-wrap" class="eval-full" style="display:none">
                            <div class="eval-k">Address</div>
                            <div id="eval-detail-address"></div>
                        </div>
                        <div class="eval-full">
                            <div class="eval-k">Course Applied</div>
                            <div id="eval-detail-course" style="font-weight:var(--weight-medium)"></div>
                        </div>
                        <div id="eval-detail-strand-wrap" style="display:none">
                            <div class="eval-k">SHS Strand</div>
                            <div id="eval-detail-strand"></div>
                        </div>
                        <div id="eval-detail-sy-wrap" style="display:none">
                            <div class="eval-k">School Year</div>
                            <div id="eval-detail-sy"></div>
                        </div>
                        <div id="eval-detail-exam-wrap" style="display:none">
                            <div class="eval-k">Exam Score</div>
                            <div id="eval-detail-exam" style="font-weight:var(--weight-medium)"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer flex gap-2 justify-content-end">
                <button type="button" class="btn btn-ghost" onclick="closeEvalModal()">Cancel</button>
                <button type="submit" class="btn btn-reject"
                        onclick="document.getElementById('eval-result-input').value='reject'">
                    <?= icon('ic_fluent_dismiss_24_regular', 13) ?> Decline
                </button>
                <button type="submit" class="btn btn-pass"
                        onclick="document.getElementById('eval-result-input').value='pass'">
                    <?= icon('ic_fluent_checkmark_24_regular', 13) ?> Pass
                </button>
            </div>
        </form>
    </div>
</div>

<script>window.PAGE = <?= json_encode([
    'interviewUrl' => url('/staff/interviews/'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

<?php
// Slide-in applicant detail drawer (markup + JS opener).
include VIEWS_PATH . '/partials/applicant_drawer.php';

$pageStyles  = ['css/pages/interview-queue.css'];
$pageScripts = ['js/pages/interview-queue.js', 'js/pages/live-search.js'];
$content   = ob_get_clean();
if ($canSeeAll) {
    $pageTitle = $showAll
        ? 'Live Queue · All colleges'
        : 'Live Queue · ' . $collegeFilter;
} else {
    $pageTitle = 'Live Queue';
}
$activeNav = 'interviews';
$pageBreadcrumb = $canSeeAll
    ? [
        ['label' => 'Interview Sessions', 'to' => '/staff/interviews'],
        ['label' => 'Live Queue',         'to' => '/staff/interviews/queue'],
    ]
    : [];
$pageActions = '';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
