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
$today     = date('Y-m-d');
$role      = Auth::role();
$isAdmin   = $role === ROLE_ADMIN;
$isSSO     = $role === ROLE_SSO;
$isDean    = $role === ROLE_DEAN;
// Admin and SSO see every queue row across colleges. Dean sees every
// row whose slot belongs to their own college (oversight).
// Professor sees only rows on a session they personally own.
$canSeeAll = $isAdmin || $isSSO;
$staffDept = (string) user_department($staffId);

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
$collegeFilter = trim((string)($_GET['college'] ?? ''));
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
    try {
        $deskStmt = $db->prepare(
            'SELECT location_label, location_notes
               FROM interview_slots
              WHERE COALESCE(assigned_to, created_by) = ?
                AND location_label != ""
              ORDER BY ABS(DATEDIFF(slot_date, ?)) ASC, slot_time ASC
              LIMIT 1'
        );
        $deskStmt->execute([$staffId, $today]);
        $deskRow = $deskStmt->fetch();
        if ($deskRow) {
            $deskLabel = $deskRow['location_label'] ?? '';
            $deskNotes = $deskRow['location_notes'] ?? '';
        }
    } catch (\Throwable $e) {}

    if (!$deskLabel) {
        try {
            $deskStmt = $db->prepare('SELECT desk_label, desk_notes FROM users WHERE id=?');
            $deskStmt->execute([$staffId]);
            $deskRow   = $deskStmt->fetch();
            $deskLabel = $deskRow['desk_label'] ?? '';
            $deskNotes = $deskRow['desk_notes'] ?? '';
        } catch (\Throwable $e) {}
    }
}

// ----------------------------------------------------------------
// Schema upgrades — evaluation columns
// ----------------------------------------------------------------
try { $db->query("SELECT evaluation_result FROM interview_queue LIMIT 0"); }
catch (\Throwable $e) {
    $db->exec("ALTER TABLE interview_queue ADD COLUMN evaluation_result VARCHAR(10) DEFAULT NULL AFTER interview_notes");
}
try { $db->query("SELECT evaluated_at FROM interview_queue LIMIT 0"); }
catch (\Throwable $e) {
    $db->exec("ALTER TABLE interview_queue ADD COLUMN evaluated_at DATETIME DEFAULT NULL AFTER evaluation_result");
}
// Migrate 'fail' → 'reject' for existing data
try {
    $db->exec("UPDATE interview_queue SET evaluation_result='reject' WHERE evaluation_result='fail'");
} catch (\Throwable $e) {}

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
    ' ORDER BY
         FIELD(q.status, "in_progress", "checked_in", "scheduled", "completed", "no_show"),
         q.queue_number ASC,
         q.created_at   ASC';

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

ob_start();
?>

<style>
@keyframes pulse-dot {
    0%,100%{opacity:1;transform:scale(1)}
    50%   {opacity:.5;transform:scale(1.3)}
}

/* Make the table card stretch to fill the .page area so the gap below the
   card matches the .page horizontal padding (var(--space-8) = 32px). Same
   pattern used on the Results page. */
.page:has(.iq-table-card) { display:flex; flex-direction:column; }
.iq-table-card { flex:1; min-height:300px; }

/* Toolbar above the table (search + count). Matches the look of the
   Results page top bar. */
.iq-toolbar {
    display:flex;
    align-items:center;
    gap:var(--space-3);
    margin-bottom:var(--space-4);
    flex-wrap:wrap;
}
.iq-toolbar .iq-search-wrap {
    position:relative;
    flex:1;
    min-width:240px;
    max-width:420px;
}
.iq-toolbar .iq-search-wrap input {
    width:100%;
    padding:0 var(--space-3) 0 32px;
    height:36px;
    min-height:36px;
    font-size:var(--text-sm);
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    background:var(--bg-elevated);
    color:var(--text-primary);
}
.iq-toolbar .iq-filter-select {
    height:36px;
    min-height:36px;
    font-size:var(--text-sm);
    max-width:340px;
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    padding:0 var(--space-3);
    background:var(--bg-elevated);
    color:var(--text-primary);
}
.iq-toolbar .iq-count {
    font-size:var(--text-xs);
    color:var(--text-tertiary);
    white-space:nowrap;
}

/* Row tints by status, matches existing badge palette. */
.iq-row-in-progress td { background: rgba(45,106,79,0.06); }
.iq-row-no-show     td,
.iq-row-completed   td { color: var(--text-tertiary); }

.iq-name-cell      { font-weight: var(--weight-medium); }
.iq-name-cell .sub {
    display:block;
    font-size:var(--text-xs);
    color:var(--text-tertiary);
    font-weight:var(--weight-regular);
    margin-top:2px;
}

.iq-eval-summary {
    font-size:var(--text-xs);
    color:var(--text-tertiary);
    max-width:240px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

/* Pass/Reject buttons inside the Evaluation modal */
.btn-pass   { background: var(--success-bg, #d1fae5); color: var(--success, #15803d); border-color: var(--success, #15803d); }
.btn-reject { background: var(--error-bg, #fee2e2);   color: var(--error, #b91c1c);     border-color: var(--error, #b91c1c); }
</style>

<?php if ($msg = Session::getFlash('success')): ?>
    <div id="iq-flash-success" class="alert alert-success" style="margin-bottom:var(--space-4);display:flex;align-items:center;gap:var(--space-3)">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
        <span style="flex:1"><?= e($msg) ?></span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:inherit;font-size:18px;line-height:1;padding:0 2px">&times;</button>
    </div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div id="iq-flash-error" class="alert alert-error" style="margin-bottom:var(--space-4);display:flex;align-items:center;gap:var(--space-3)">
        <?= icon('ic_fluent_info_24_regular', 16) ?>
        <span style="flex:1"><?= e($msg) ?></span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:inherit;font-size:18px;line-height:1;padding:0 2px">&times;</button>
    </div>
<?php endif; ?>
<script>
// Auto-dismiss flash alerts after 5 seconds
['iq-flash-success','iq-flash-error'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) setTimeout(function() {
        el.style.transition = 'opacity .4s';
        el.style.opacity = '0';
        setTimeout(function() { el.remove(); }, 400);
    }, 5000);
});
</script>

<!-- ============================================================
     BACK BUTTON / CONTEXT HEADER
     ------------------------------------------------------------
     • Admin / SSO with a college filter (or all-mode): give them
       a way back to the college picker.
     • Dean and Professor land here directly from the sidebar
       (this IS their primary view), so no back button.
============================================================ -->
<?php if ($canSeeAll): ?>
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-5);flex-wrap:wrap">
        <a href="<?= e(url('/staff/interviews/queue')) ?>" class="btn btn-ghost btn-sm">
            ← Back to colleges
        </a>
        <span style="font-size:var(--text-sm);color:var(--text-secondary)">
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
     — setup is not the professor's responsibility.
============================================================ -->
<?php if (!$isAdmin && !$isSSO && !$isDean && $deskLabel): ?>
    <div style="display:flex;align-items:center;gap:var(--space-3);
                 padding:var(--space-3) var(--space-4);margin-bottom:var(--space-4);
                 background:var(--bg-elevated);border:1px solid var(--border);
                 border-radius:var(--radius-md);font-size:var(--text-sm)">
        <?= icon('ic_fluent_location_24_regular', 13, 'color:var(--text-tertiary);flex-shrink:0') ?>
        <span style="font-weight:var(--weight-medium)"><?= e($deskLabel) ?></span>
        <?php if ($deskNotes): ?>
            <span style="color:var(--text-tertiary)"><?= e($deskNotes) ?></span>
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
        $emptyBody  = 'Once SSO creates interview sessions and applicants are placed, they will show up here.';
    } elseif ($canSeeAll) {
        $emptyTitle = 'No interviews in ' . $collegeFilter . ' yet';
        $emptyBody  = 'Once a session in this college is scheduled and applicants are placed, they will show up here.';
    } elseif ($isDean) {
        $deptLabel  = $staffDept !== '' ? $staffDept : 'your college';
        $emptyTitle = 'No interviews in ' . $deptLabel . ' yet';
        $emptyBody  = 'Once a session in your college is scheduled and applicants are placed, they will show up here.';
    } else {
        $emptyTitle = 'No interviews assigned to you yet';
        $emptyBody  = 'Applicants will appear here automatically once SSO places them on a session you own.';
    }
?>
    <!--
        Use the same .iq-table-card wrapper a populated queue uses so the
        empty state extends the full width AND height of the page area
        (the .page:has(.iq-table-card) rule above turns the page into a
        flex column, and flex:1 makes this card claim the leftover space).
    -->
    <div class="card iq-table-card"
         style="padding:var(--space-12) var(--space-8);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:var(--space-3);text-align:center">
        <div style="width:56px;height:56px;border-radius:var(--radius-lg);background:var(--bg-subtle);color:var(--text-tertiary);display:flex;align-items:center;justify-content:center">
            <?= icon('ic_fluent_calendar_ltr_24_regular', 28) ?>
        </div>
        <div style="font-size:var(--text-base);font-weight:var(--weight-semibold);color:var(--text-primary)">
            <?= e($emptyTitle) ?>
        </div>
        <div style="max-width:480px;font-size:var(--text-sm);color:var(--text-tertiary);line-height:1.5">
            <?= e($emptyBody) ?>
        </div>
    </div>
<?php else: ?>

<!-- ============================================================
     TOOLBAR — search + count
============================================================ -->
<div class="iq-toolbar">
    <div class="iq-search-wrap">
        <?= icon('ic_fluent_search_24_filled', 14, 'position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);pointer-events:none') ?>
        <input type="search" id="iq-filter" placeholder="Filter by name or course…"
               autocomplete="off">
    </div>
    <?php if (count($dateMap) > 1): ?>
    <select id="iq-date-filter" class="form-control iq-filter-select"
            title="Filter by interview date">
        <?php
            // The "All dates" item leads so the page starts unfiltered. The
            // applicant count next to each row mirrors what's currently visible
            // in the table (rebuilt client-side as filters change so the badge
            // and the visible count never drift apart).
            $totalApplicants = count($rows);
        ?>
        <option value="" data-count="<?= (int)$totalApplicants ?>">
            All dates · <?= (int)$totalApplicants ?> applicant<?= $totalApplicants === 1 ? '' : 's' ?>
        </option>
        <?php foreach ($dateMap as $d): ?>
            <option value="<?= e($d['date']) ?>" data-count="<?= (int)$d['count'] ?>">
                <?= format_date($d['date']) ?><?= $d['is_past'] ? ' (Past)' : '' ?>
                · <?= (int)$d['count'] ?> applicant<?= (int)$d['count'] === 1 ? '' : 's' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (count($slotMap) > 1): ?>
    <select id="iq-slot-filter" class="form-control iq-filter-select"
            title="Filter by specific slot within the selected date">
        <?php $totalSlotApplicants = array_sum(array_column($slotMap, 'count')); ?>
        <option value="" data-date="" data-count="<?= (int)$totalSlotApplicants ?>">
            All slots · <?= (int)$totalSlotApplicants ?> applicant<?= $totalSlotApplicants === 1 ? '' : 's' ?>
        </option>
        <?php foreach ($slotMap as $sl): ?>
            <?php
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
            <option value="<?= (int)$sl['id'] ?>"
                    data-date="<?= e($sl['date']) ?>"
                    data-count="<?= (int)$sl['count'] ?>">
                <?= e($slLbl) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <span class="iq-count" id="iq-count">
        <?= count($rows) ?> applicant<?= count($rows) === 1 ? '' : 's' ?>
    </span>
</div>

<!-- ============================================================
     TABLE — full-page card matching documents/results pages
============================================================ -->
<div class="card iq-table-card" style="padding:0;overflow:hidden;display:flex;flex-direction:column">
    <table class="table" id="queue-table">
        <thead>
            <tr>
                <th>Applicant</th>
                <?php if ($canSeeAll && $showAll): ?>
                    <th style="width:140px">College</th>
                <?php endif; ?>
                <th>Course</th>
                <th style="width:120px">Type</th>
                <th style="width:120px">Status</th>
                <th style="width:140px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            [$badgeText, $badgeClass] = queue_status_badge($r['status']);
            $rowClass  = 'iq-row-' . str_replace('_', '-', $r['status']);
            $name      = queue_format_name($r);
            $course    = $r['course_applied'] ?? '';
            $type      = $r['applicant_type'] ?? '';
            $isInProg  = $r['status'] === 'in_progress';
            $isFinal   = in_array($r['status'], ['completed', 'no_show'], true);
            $existing  = $r['interview_notes'] ?? '';
            $haystack  = strtolower($name . ' ' . $course . ' ' . $type);
        ?>
            <tr class="<?= $rowClass ?>"
                data-name="<?= e($haystack) ?>"
                data-slot="<?= (int)$r['slot_id'] ?>"
                data-date="<?= e($r['slot_date'] ?? '') ?>">
                <td>
                    <button type="button"
                            class="iq-name-cell iq-name-cell-clickable"
                            data-applicant-panel="<?= (int)$r['app_id'] ?>"
                            title="View applicant details">
                        <span><?= e($name) ?></span>
                        <?php if ($r['evaluation_result']): ?>
                            <span class="sub">
                                Result:
                                <strong style="color:<?= $r['evaluation_result'] === 'pass' ? 'var(--success)' : 'var(--error)' ?>">
                                    <?= ucfirst($r['evaluation_result']) ?>
                                </strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($isFinal && $existing !== ''): ?>
                            <span class="sub iq-eval-summary" title="<?= e($existing) ?>">
                                <?= e($existing) ?>
                            </span>
                        <?php endif; ?>
                    </button>
                </td>

                <?php if ($canSeeAll && $showAll): ?>
                    <td style="font-size:var(--text-sm)">
                        <?php $col = (string)($r['slot_department'] ?? ''); ?>
                        <?= $col !== '' ? e($col) : '<span style="color:var(--text-tertiary)">—</span>' ?>
                    </td>
                <?php endif; ?>

                <td style="font-size:var(--text-sm)">
                    <?= $course !== '' ? e($course) : '<span style="color:var(--text-tertiary)">—</span>' ?>
                </td>

                <td style="font-size:var(--text-sm)">
                    <?= $type !== '' ? e(ucfirst($type)) : '<span style="color:var(--text-tertiary)">—</span>' ?>
                </td>

                <td>
                    <span class="badge <?= $badgeClass ?>" style="font-size:var(--text-xs)">
                        <?php if ($isInProg): ?>
                            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;
                                         background:#fff;animation:pulse-dot 1.4s infinite;margin-right:4px"></span>
                        <?php endif; ?>
                        <?= e($badgeText) ?>
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
                        <?php if ($isFinal): ?>
                            <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                <?php if ($r['status'] === 'no_show'): ?>
                                    Marked no-show
                                <?php else: ?>
                                    <?= $r['evaluation_result']
                                        ? e(ucfirst($r['evaluation_result']))
                                        : 'Completed' ?>
                                <?php endif; ?>
                            </span>
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
    </table>

    <!-- Filler below the last row so the empty space inherits a top divider line -->
    <div style="flex:1;border-top:1px solid var(--border)"></div>
</div>
<?php endif; // empty($rows) ?>

<!-- ============================================================
     EVALUATION MODAL — notes + Pass / Reject
============================================================ -->
<div id="eval-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal" style="max-width:860px;display:flex;flex-direction:column;overflow:hidden">
        <div class="modal-header">
            <div class="modal-title">Interview Evaluation</div>
            <button class="btn-icon" type="button" onclick="closeEvalModal()">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>

        <div style="display:flex;flex:1;overflow:hidden;min-height:0">
            <!-- LEFT: Applicant details panel -->
            <div style="width:320px;flex-shrink:0;border-right:1px solid var(--border);padding:var(--space-4) var(--space-5);overflow-y:auto;background:var(--bg-subtle)">
                <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:var(--space-3)">Applicant Details</div>
                <div style="display:flex;flex-direction:column;gap:var(--space-3);font-size:var(--text-sm)">
                    <div>
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Full Name</div>
                        <div id="eval-detail-name" style="font-weight:var(--weight-medium)"></div>
                    </div>
                    <div id="eval-detail-email-wrap">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Email</div>
                        <div id="eval-detail-email"></div>
                    </div>
                    <div id="eval-detail-phone-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Phone</div>
                        <div id="eval-detail-phone"></div>
                    </div>
                    <div id="eval-detail-bday-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Birthdate</div>
                        <div id="eval-detail-bday"></div>
                    </div>
                    <div id="eval-detail-sex-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Sex</div>
                        <div id="eval-detail-sex"></div>
                    </div>
                    <div id="eval-detail-address-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Address</div>
                        <div id="eval-detail-address"></div>
                    </div>
                    <div style="border-top:1px solid var(--border);margin:var(--space-1) 0"></div>
                    <div>
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Applicant Type</div>
                        <div id="eval-detail-type"></div>
                    </div>
                    <div>
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Course Applied</div>
                        <div id="eval-detail-course" style="font-weight:var(--weight-medium)"></div>
                    </div>
                    <div id="eval-detail-strand-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">SHS Strand</div>
                        <div id="eval-detail-strand"></div>
                    </div>
                    <div id="eval-detail-sy-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">School Year</div>
                        <div id="eval-detail-sy"></div>
                    </div>
                    <div style="border-top:1px solid var(--border);margin:var(--space-1) 0"></div>
                    <div id="eval-detail-exam-wrap" style="display:none">
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Exam Score</div>
                        <div id="eval-detail-exam" style="font-weight:var(--weight-medium)"></div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Eval form -->
            <div style="flex:1;min-width:0;display:flex;flex-direction:column">
                <form method="POST" id="eval-form" action="" style="display:flex;flex-direction:column;flex:1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="complete_with_evaluation">
                    <input type="hidden" name="evaluation_result" id="eval-result-input" value="">

                    <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4);flex:1">
                        <div>
                            <label class="form-label" for="eval-notes">Interview notes</label>
                            <textarea id="eval-notes" name="interview_notes" class="form-control"
                                      rows="7" placeholder="Interview notes / evaluation remarks…"></textarea>
                        </div>

                        <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                            Choose Pass or Reject to finalize this interview.
                        </div>
                    </div>

                    <div class="modal-footer" style="display:flex;gap:var(--space-2);justify-content:flex-end">
                        <button type="button" class="btn btn-ghost" onclick="closeEvalModal()">Cancel</button>
                        <button type="submit" class="btn btn-reject"
                                onclick="document.getElementById('eval-result-input').value='reject'">
                            <?= icon('ic_fluent_dismiss_24_regular', 13) ?> Reject
                        </button>
                        <button type="submit" class="btn btn-pass"
                                onclick="document.getElementById('eval-result-input').value='pass'">
                            <?= icon('ic_fluent_checkmark_24_regular', 13) ?> Pass
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     SCRIPT
============================================================ -->
<script>
// Live filters — combines the name/course search box, the date
// dropdown and the slot dropdown. The slot dropdown cascades off the
// date one: picking a date hides slots on other dates and resets the
// slot filter if its current selection no longer matches.
(function() {
    var filterEl = document.getElementById('iq-filter');
    var dateEl   = document.getElementById('iq-date-filter');
    var slotEl   = document.getElementById('iq-slot-filter');
    var countEl  = document.getElementById('iq-count');

    function syncSlotOptions() {
        if (!slotEl) return;
        var selectedDate = dateEl ? dateEl.value : '';
        var currentSlot  = slotEl.value;
        var stillVisible = false;
        Array.prototype.forEach.call(slotEl.options, function(opt) {
            if (opt.value === '') { opt.hidden = false; return; } // keep "All slots"
            var optDate = opt.getAttribute('data-date') || '';
            var match   = !selectedDate || optDate === selectedDate;
            opt.hidden  = !match;
            if (match && opt.value === currentSlot) stillVisible = true;
        });
        // If the previously-selected slot is on a different date, clear it.
        if (currentSlot && !stillVisible) slotEl.value = '';
    }

    function applyFilters() {
        var term       = filterEl ? filterEl.value.toLowerCase().trim() : '';
        var slotId     = slotEl   ? slotEl.value : '';
        var dateValue  = dateEl   ? dateEl.value : '';
        var visible    = 0;
        document.querySelectorAll('#queue-table tbody tr').forEach(function(tr) {
            var haystack   = tr.dataset.name || '';
            var rowSlot    = tr.dataset.slot || '';
            var rowDate    = tr.dataset.date || '';
            var matchText  = !term      || haystack.includes(term);
            var matchSlot  = !slotId    || rowSlot === slotId;
            var matchDate  = !dateValue || rowDate === dateValue;
            var show       = matchText && matchSlot && matchDate;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (countEl) countEl.textContent = visible + ' applicant' + (visible === 1 ? '' : 's');
    }

    if (filterEl) filterEl.addEventListener('input', applyFilters);
    if (dateEl)   dateEl.addEventListener('change', function() { syncSlotOptions(); applyFilters(); });
    if (slotEl)   slotEl.addEventListener('change', applyFilters);

    // Initial sync in case the page is reloaded with a date already chosen.
    syncSlotOptions();
})();

// Evaluation modal handlers
const evalModal = document.getElementById('eval-modal');

function _showField(id, value) {
    var wrap = document.getElementById(id + '-wrap');
    var el   = document.getElementById(id);
    if (value) {
        el.textContent = value;
        if (wrap) wrap.style.display = '';
    } else {
        if (wrap) wrap.style.display = 'none';
    }
}

function openEvalModal(d) {
    document.getElementById('eval-form').action =
        '<?= e(url('/staff/interviews/')) ?>' + d.queueId;

    // Applicant details panel
    document.getElementById('eval-detail-name').textContent = d.name || '';
    document.getElementById('eval-detail-email').textContent = d.email || '';
    document.getElementById('eval-detail-type').textContent = d.type ? d.type.charAt(0).toUpperCase() + d.type.slice(1) : '—';
    document.getElementById('eval-detail-course').textContent = d.course || '—';

    _showField('eval-detail-phone', d.phone);
    _showField('eval-detail-bday', d.birthdate);
    _showField('eval-detail-sex', d.sex === 'M' ? 'Male' : (d.sex === 'F' ? 'Female' : ''));
    _showField('eval-detail-address', d.address);
    _showField('eval-detail-strand', d.strand);
    _showField('eval-detail-sy', d.school_year);

    // Exam score
    var examWrap = document.getElementById('eval-detail-exam-wrap');
    var examEl   = document.getElementById('eval-detail-exam');
    if (d.exam_score !== null && d.exam_total !== null) {
        var scoreText = d.exam_score + '/' + d.exam_total;
        if (d.exam_passed === 1) {
            scoreText += ' — <span style="color:var(--success);font-weight:var(--weight-semibold)">Passed</span>';
        } else if (d.exam_passed === 0) {
            scoreText += ' — <span style="color:var(--error);font-weight:var(--weight-semibold)">Failed</span>';
        }
        examEl.innerHTML = scoreText;
        examWrap.style.display = '';
    } else {
        examWrap.style.display = 'none';
    }

    document.getElementById('eval-notes').value = d.notes || '';
    document.getElementById('eval-result-input').value = '';
    evalModal.style.display = 'flex';
    setTimeout(() => document.getElementById('eval-notes').focus(), 50);
}

function closeEvalModal() {
    evalModal.style.display = 'none';
}

evalModal.addEventListener('click', function (e) {
    if (e.target === evalModal) closeEvalModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && evalModal.style.display === 'flex') {
        closeEvalModal();
    }
});

// Submission guard — make sure the user actually clicked Pass or Reject
document.getElementById('eval-form').addEventListener('submit', function (e) {
    const decision = document.getElementById('eval-result-input').value;
    if (decision !== 'pass' && decision !== 'reject') {
        e.preventDefault();
        return false;
    }
    if (!confirm('Mark this interview as ' + (decision === 'pass' ? 'PASS' : 'FAIL') + '?')) {
        e.preventDefault();
        return false;
    }
});

// Auto-refresh every 60s, but pause when a textarea/input is focused or
// has been edited recently (so we don't blow away typed-but-unsaved notes,
// and so the modal doesn't get clobbered mid-evaluation).
let lastFocusTime = 0;
document.addEventListener('focusin', (e) => {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') {
        lastFocusTime = Date.now();
    }
});
document.addEventListener('input', (e) => {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') {
        lastFocusTime = Date.now();
    }
});
setInterval(() => {
    if (Date.now() - lastFocusTime < 120000) return;
    if (evalModal.style.display === 'flex') return;
    window.location.reload();
}, 60000);
</script>

<?php
// Slide-in applicant detail drawer (markup + JS opener).
include VIEWS_PATH . '/partials/applicant_drawer.php';

$content   = ob_get_clean();
if ($canSeeAll) {
    $pageTitle = $showAll
        ? 'Live Queue · All colleges'
        : 'Live Queue · ' . $collegeFilter;
} else {
    $pageTitle = 'Live Queue';
}
$activeNav = 'interviews';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
