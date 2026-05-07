<?php
// ============================================================
// modules/interview/staff_queue.php
// Live interview queue — single unified table with status-aware
// inline action buttons. No more check-in code verification.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$today   = date('Y-m-d');
$isAdmin = Auth::role() === ROLE_ADMIN;

// ----------------------------------------------------------------
// VIEW MODE
// Admin can drill in: /queue → pick college → pick session → queue.
// Staff land directly on their own queue (today's sessions for them).
// Anyone with ?slot=ID jumps straight to that session's queue.
// ----------------------------------------------------------------
$slotId          = (int) ($_GET['slot'] ?? 0);
$selectedCollege = trim($_GET['college'] ?? '');

$viewMode = 'queue';
if ($slotId === 0 && $isAdmin) {
    $viewMode = $selectedCollege === '' ? 'college_select' : 'session_select';
}

if ($viewMode === 'college_select') {
    require __DIR__ . '/_queue_college_select.php';
    return;
}
if ($viewMode === 'session_select') {
    require __DIR__ . '/_queue_session_select.php';
    return;
}

// ----------------------------------------------------------------
// QUEUE MODE — load context (location + interviewer) for the strip.
// If a slot is selected, scope to that slot. Otherwise (staff
// default) scope to this interviewer's sessions.
// ----------------------------------------------------------------
$deskLabel       = '';
$deskNotes       = '';
$slotInterviewer = '';
$slotDept        = '';

if ($slotId > 0) {
    try {
        $stmt = $db->prepare(
            'SELECT s.location_label, s.location_notes, s.department,
                    s.slot_date, s.slot_time, s.end_time,
                    u.name AS interviewer_name
               FROM interview_slots s
               LEFT JOIN users u ON u.id = s.assigned_to
              WHERE s.id = ?
              LIMIT 1'
        );
        $stmt->execute([$slotId]);
        $slotRow = $stmt->fetch();
        if (!$slotRow) {
            // Bad slot id — bounce back to the picker
            header('Location: ' . url('/staff/interviews/queue'));
            return;
        }
        $deskLabel       = $slotRow['location_label']  ?? '';
        $deskNotes       = $slotRow['location_notes']  ?? '';
        $slotInterviewer = $slotRow['interviewer_name'] ?? '';
        $slotDept        = $slotRow['department']      ?? '';
    } catch (\Throwable $e) {}
} else {
    // Staff default — find this interviewer's location for the strip.
    try {
        $deskStmt = $db->prepare(
            'SELECT location_label, location_notes
               FROM interview_slots
              WHERE COALESCE(assigned_to, created_by) = ?
                AND slot_date = ?
                AND location_label != ""
              ORDER BY slot_time ASC
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
            $deskStmt = $db->prepare(
                'SELECT location_label, location_notes
                   FROM interview_slots
                  WHERE COALESCE(assigned_to, created_by) = ?
                    AND slot_date >= ?
                    AND location_label != ""
                  ORDER BY slot_date ASC, slot_time ASC
                  LIMIT 1'
            );
            $deskStmt->execute([$staffId, $today]);
            $deskRow = $deskStmt->fetch();
            if ($deskRow) {
                $deskLabel = $deskRow['location_label'] ?? '';
                $deskNotes = $deskRow['location_notes'] ?? '';
            }
        } catch (\Throwable $e) {}
    }

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

// ----------------------------------------------------------------
// AUTO NO-SHOW SWEEP
// Anyone whose slot time has passed and they're still 'scheduled'
// (never checked in) gets auto-flipped to no_show.
//
// "Past their slot" = either:
//   slot_date < today, OR
//   slot_date = today AND now > end_time (or slot_time + 30 min if no end)
// ----------------------------------------------------------------
$autoNoShowCount = 0;
try {
    if ($slotId > 0) {
        $stmt = $db->prepare(
            'UPDATE interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             SET    q.status = "no_show",
                    q.attendance_status = "absent",
                    q.interview_status = "absent"
             WHERE  q.status = "scheduled"
               AND  s.id = ?
               AND  (
                      s.slot_date < CURDATE()
                      OR (
                        s.slot_date = CURDATE()
                        AND NOW() > TIMESTAMP(
                            s.slot_date,
                            COALESCE(s.end_time, ADDTIME(s.slot_time, "00:30:00"))
                        )
                      )
                    )'
        );
        $stmt->execute([$slotId]);
    } else {
        $stmt = $db->prepare(
            'UPDATE interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             SET    q.status = "no_show",
                    q.attendance_status = "absent",
                    q.interview_status = "absent"
             WHERE  q.status = "scheduled"
               AND  COALESCE(s.assigned_to, s.created_by) = ?
               AND  (
                      s.slot_date < CURDATE()
                      OR (
                        s.slot_date = CURDATE()
                        AND NOW() > TIMESTAMP(
                            s.slot_date,
                            COALESCE(s.end_time, ADDTIME(s.slot_time, "00:30:00"))
                        )
                      )
                    )'
        );
        $stmt->execute([$staffId]);
    }
    $autoNoShowCount = $stmt->rowCount();
    if ($autoNoShowCount > 0) {
        audit_log('interview_auto_noshow',
            "Auto-marked {$autoNoShowCount} past-time scheduled applicants as no-show",
            'interview_queue', null);
    }
} catch (\Throwable $e) {
    // Non-fatal — UI still works without the sweep
    error_log('Auto no-show sweep failed: ' . $e->getMessage());
}

// ----------------------------------------------------------------
// Load the queue rows.
// - slot mode: every queue row in this session (regardless of date)
// - staff mode: today's rows for sessions assigned to this user
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
            s.id          AS slot_id,
            s.slot_time,
            s.end_time
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   applicants a      ON a.id = q.applicant_id
     JOIN   users u           ON u.id = a.user_id ';
$orderTail =
    ' ORDER BY
         FIELD(q.status, "in_progress", "checked_in", "scheduled", "completed", "no_show"),
         s.slot_time ASC,
         q.queue_number ASC,
         q.created_at   ASC';

if ($slotId > 0) {
    $stmt = $db->prepare($selectCols . ' WHERE s.id = ?' . $orderTail);
    $stmt->execute([$slotId]);
} else {
    $stmt = $db->prepare(
        $selectCols .
        ' WHERE s.slot_date = ?
            AND COALESCE(s.assigned_to, s.created_by) = ?' .
        $orderTail
    );
    $stmt->execute([$today, $staffId]);
}
$rows = $stmt->fetchAll();

// Pre-compute counts per status for the stats row
$counts = ['scheduled' => 0, 'checked_in' => 0, 'in_progress' => 0,
           'completed' => 0, 'no_show'    => 0];
foreach ($rows as $r) {
    if (isset($counts[$r['status']])) $counts[$r['status']]++;
}

// Distinct session ids in today's queue (with their start times). The Roster /
// batch-evaluation page lives off the queue (the operational control), so we
// expose a quick link to it for each session here instead of from setup.
$sessionsToday = [];
foreach ($rows as $r) {
    $sid = (int)$r['slot_id'];
    if ($sid <= 0 || isset($sessionsToday[$sid])) continue;
    $sessionsToday[$sid] = $r['slot_time'];
}

// Format name as "SURNAME SUFFIX, FIRST MIDDLE" (uses shared helper)
function queue_format_name(array $r): string {
    return format_full_name($r);
}

// Status badge mapping
function queue_status_badge(string $status): array {
    return match ($status) {
        'scheduled'   => ['Scheduled',   'badge-info'],
        'checked_in'  => ['Waiting',     'badge-pending'],
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
.q-table { width:100%; border-collapse:separate; border-spacing:0; }
.q-table th, .q-table td {
    padding: var(--space-3) var(--space-3);
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: var(--text-sm);
    vertical-align: middle;
}
.q-table th {
    font-size: var(--text-xs);
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-tertiary);
    background: var(--bg-elevated);
    font-weight: var(--weight-medium);
    border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 1;
}
.q-table tr.row-in-progress td { background: rgba(45,106,79,0.06); }
.q-table tr.row-no-show td,
.q-table tr.row-completed td { color: var(--text-tertiary); }
.q-num {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 28px; height: 28px; padding: 0 6px;
    border-radius: var(--radius-sm); background: var(--bg-subtle);
    font-weight: var(--weight-semibold); font-size: var(--text-xs);
}
.q-num.in-progress { background: var(--accent); color: #fff; }
.q-actions {
    display: flex; gap: var(--space-1); flex-wrap: wrap; justify-content: flex-end;
}
.q-actions form { display: inline; margin: 0; }
.notes-row td { padding: 0 var(--space-3) var(--space-3); border-bottom: 1px solid var(--border); }
.notes-row.collapsed { display: none; }
.notes-box {
    background: var(--bg-subtle);
    border-radius: var(--radius-md);
    padding: var(--space-3) var(--space-4);
    font-size: var(--text-xs);
    color: var(--text-secondary);
    white-space: pre-line;
}
.btn-pass { background: var(--success-bg, #d1fae5); color: var(--success, #15803d); border-color: var(--success, #15803d); }
.btn-fail { background: var(--error-bg, #fee2e2);   color: var(--error, #b91c1c);     border-color: var(--error, #b91c1c); }
.q-name-cell { display:flex; flex-direction:column; gap:2px; }
.q-name-cell .name { font-weight: var(--weight-medium); }
.q-name-cell .meta { font-size: var(--text-xs); color: var(--text-tertiary); }
.q-toolbar { display:flex; align-items:center; gap:var(--space-2); margin-bottom:var(--space-4); flex-wrap:wrap; }
.q-toolbar input[type=search] {
    flex: 1; min-width: 200px; min-height: 36px;
    padding: 6px 12px; border:1px solid var(--border);
    border-radius: var(--radius-md); font-size: var(--text-sm);
}
</style>

<!-- ============================================================
     BACK BUTTON
     - Admin viewing a slot → back to that college's session picker
     - Admin (no slot, but somehow here) → back to college picker
     - Staff → back to the interviews home
============================================================ -->
<?php
if ($slotId > 0 && $isAdmin && $slotDept !== '') {
    $backHref = url('/staff/interviews/queue') . '?college=' . urlencode($slotDept);
} elseif ($slotId > 0 && $isAdmin) {
    $backHref = url('/staff/interviews/queue');
} else {
    $backHref = url('/staff/interviews');
}
?>
<div style="margin-bottom:var(--space-5)">
    <a href="<?= e($backHref) ?>" class="btn btn-ghost btn-sm">← Back</a>
</div>

<?php if ($slotId > 0): /* Slot context strip — admin needs to know what they're looking at */ ?>
    <div style="margin-bottom:var(--space-4)">
        <h2 style="margin:0 0 var(--space-1) 0;font-size:var(--text-lg);font-weight:var(--weight-semibold)">
            <?= e($slotInterviewer ?: 'Unassigned') ?>
            <?php if ($slotDept): ?>
                <span style="color:var(--text-tertiary);font-weight:var(--weight-regular)">
                    · <?= e($slotDept) ?>
                </span>
            <?php endif; ?>
        </h2>
        <?php if (!empty($slotRow['slot_date']) || !empty($slotRow['slot_time'])): ?>
            <p style="margin:0;color:var(--text-tertiary);font-size:var(--text-sm)">
                <?php
                if (!empty($slotRow['slot_date'])) {
                    echo e(date('D, M j', strtotime($slotRow['slot_date'])));
                }
                if (!empty($slotRow['slot_time'])) {
                    echo ' · ' . e(date('g:i A', strtotime($slotRow['slot_time'])));
                    if (!empty($slotRow['end_time'])) {
                        echo ' – ' . e(date('g:i A', strtotime($slotRow['end_time'])));
                    }
                }
                ?>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     DESK INFO STRIP
============================================================ -->
<?php if ($deskLabel): ?>
    <div style="display:flex;align-items:center;gap:var(--space-3);
                 padding:var(--space-3) var(--space-4);margin-bottom:var(--space-4);
                 background:var(--bg-elevated);border:1px solid var(--border);
                 border-radius:var(--radius-md);font-size:var(--text-sm)">
        <?= icon('ic_fluent_location_24_regular', 13, 'color:var(--text-tertiary);flex-shrink:0') ?>
        <span style="font-weight:var(--weight-medium)"><?= e($deskLabel) ?></span>
        <?php if ($deskNotes): ?>
            <span style="color:var(--text-tertiary)"><?= e($deskNotes) ?></span>
        <?php endif; ?>
        <a href="<?= url('/staff/interviews/setup') ?>"
           style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary);text-decoration:none">
            Edit
        </a>
    </div>
<?php else: ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-4)">
        <?= icon('ic_fluent_warning_24_regular', 15) ?>
        <span>No desk location set.
            <a href="<?= url('/staff/interviews/setup') ?>">Set it up in Interview Setup</a>
            so students know where to go.
        </span>
    </div>
<?php endif; ?>

<!-- ============================================================
     ROSTER LINKS — quick way to reach the per-session batch
     evaluation page from the queue (the operational control).
============================================================ -->
<?php if (!empty($sessionsToday)): ?>
    <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap;
                margin-bottom:var(--space-4);font-size:var(--text-xs)">
        <span style="color:var(--text-tertiary)">
            <?= count($sessionsToday) === 1 ? 'Roster:' : 'Rosters:' ?>
        </span>
        <?php foreach ($sessionsToday as $sid => $sessionTime): ?>
            <a href="<?= url('/staff/interviews/' . $sid . '/roster') ?>"
               class="btn btn-ghost btn-sm"
               style="font-size:var(--text-xs)">
                <?= icon('ic_fluent_clipboard_text_24_regular', 13) ?>
                View full roster<?php if (count($sessionsToday) > 1 && $sessionTime): ?>
                    &nbsp;·&nbsp; <?= date('g:i A', strtotime($sessionTime)) ?>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     AUTO NO-SHOW NOTICE
============================================================ -->
<?php if ($autoNoShowCount > 0): ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-4);font-size:var(--text-sm)">
        <?= icon('ic_fluent_clock_24_regular', 15) ?>
        <span>
            <?= $autoNoShowCount ?> applicant<?= $autoNoShowCount === 1 ? '' : 's' ?>
            past their assigned time and not checked in — auto-marked as no-show.
        </span>
    </div>
<?php endif; ?>

<!-- ============================================================
     STATS ROW (compact)
============================================================ -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:var(--space-2);margin-bottom:var(--space-5)">
    <?php
    $stats = [
        ['n' => $counts['scheduled'],   'label' => 'Scheduled',   'color' => 'var(--text-tertiary)'],
        ['n' => $counts['checked_in'],  'label' => 'Waiting',     'color' => 'var(--info)'],
        ['n' => $counts['in_progress'], 'label' => 'In Progress', 'color' => 'var(--accent)'],
        ['n' => $counts['completed'],   'label' => 'Done',        'color' => 'var(--success)'],
        ['n' => $counts['no_show'],     'label' => 'No-show',     'color' => 'var(--error)'],
    ];
    foreach ($stats as $s): ?>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);
                     border-radius:var(--radius-md);padding:var(--space-3);text-align:center">
            <div style="font-size:1.375rem;font-weight:var(--weight-semibold);color:<?= $s['color'] ?>;line-height:1">
                <?= $s['n'] ?>
            </div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                <?= $s['label'] ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     TOOLBAR
============================================================ -->
<div class="q-toolbar">
    <input type="search" id="q-filter" placeholder="Filter by name or course…"
           autocomplete="off">
    <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
        <?= count($rows) ?> applicant<?= count($rows) === 1 ? '' : 's' ?> today
    </span>
</div>

<!-- ============================================================
     UNIFIED QUEUE TABLE
============================================================ -->
<?php if (empty($rows)): ?>
    <div style="padding:var(--space-8) var(--space-4);background:var(--bg-subtle);
                 border-radius:var(--radius-md);text-align:center;color:var(--text-tertiary)">
        <?= icon('ic_fluent_calendar_24_regular', 32, 'color:var(--text-tertiary)') ?>
        <div style="margin-top:var(--space-3);font-size:var(--text-sm)">
            No interviews scheduled for today.
        </div>
    </div>
<?php else: ?>
    <div class="card" style="overflow:hidden;padding:0">
        <table class="q-table" id="queue-table">
            <thead>
                <tr>
                    <th style="width:60px">Time</th>
                    <th style="width:50px">#</th>
                    <th>Applicant</th>
                    <th style="width:120px">Status</th>
                    <th style="width:auto;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                [$badgeText, $badgeClass] = queue_status_badge($r['status']);
                $rowClass = 'row-' . str_replace('_', '-', $r['status']);
                $name      = queue_format_name($r);
                $course    = $r['course_applied'] ?? '';
                $type      = $r['applicant_type'] ?? '';
                $hasNotes  = !empty($r['interview_notes']);
                $notesId   = 'notes-' . $r['queue_id'];
                $isInProg  = $r['status'] === 'in_progress';
            ?>
                <tr class="<?= $rowClass ?>" data-name="<?= e(strtolower($name . ' ' . $course)) ?>">
                    <td style="font-variant-numeric: tabular-nums; color:var(--text-secondary)">
                        <?= $r['slot_time'] ? date('g:i A', strtotime($r['slot_time'])) : '—' ?>
                    </td>
                    <td>
                        <?php if ($r['queue_number']): ?>
                            <span class="q-num <?= $isInProg ? 'in-progress' : '' ?>">
                                <?= e($r['queue_number']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="q-name-cell">
                            <span class="name"><?= e($name) ?></span>
                            <span class="meta">
                                <?= e(ucfirst($type)) ?>
                                <?php if ($course): ?> · <?= e($course) ?><?php endif; ?>
                                <?php if ($r['checked_in_at']): ?>
                                    · in at <?= date('g:i A', strtotime($r['checked_in_at'])) ?>
                                <?php endif; ?>
                                <?php if ($r['evaluation_result']): ?>
                                    · <strong style="color:<?= $r['evaluation_result'] === 'pass' ? 'var(--success)' : 'var(--error)' ?>">
                                        <?= ucfirst($r['evaluation_result']) ?>
                                    </strong>
                                <?php endif; ?>
                            </span>
                        </div>
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
                        <div class="q-actions">

                            <?php /* ── SCHEDULED → Check-in OR No-show ───────── */ ?>
                            <?php if ($r['status'] === 'scheduled'): ?>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="staff_checkin">
                                    <button class="btn btn-primary btn-sm">
                                        <?= icon('ic_fluent_checkmark_24_regular', 13) ?> Check In
                                    </button>
                                </form>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>"
                                      onsubmit="return confirm('Mark as no-show?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_no_show">
                                    <button class="btn btn-ghost btn-sm" style="color:var(--error)">No-show</button>
                                </form>

                            <?php /* ── CHECKED-IN → Start ─────────────────────── */ ?>
                            <?php elseif ($r['status'] === 'checked_in'): ?>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="start_interview">
                                    <button class="btn btn-primary btn-sm">
                                        Start →
                                    </button>
                                </form>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>"
                                      onsubmit="return confirm('Mark as no-show?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_no_show">
                                    <button class="btn btn-ghost btn-sm" style="color:var(--error)">No-show</button>
                                </form>

                            <?php /* ── IN PROGRESS → Pass / Fail / Notes ───────── */ ?>
                            <?php elseif ($r['status'] === 'in_progress'): ?>
                                <button type="button" class="btn btn-ghost btn-sm"
                                        onclick="toggleNotes('<?= $notesId ?>')">
                                    <?= icon('ic_fluent_edit_24_regular', 13) ?> Notes
                                </button>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>"
                                      onsubmit="return prepareEval(this, 'pass')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="complete_with_evaluation">
                                    <input type="hidden" name="evaluation_result" value="pass">
                                    <input type="hidden" name="interview_notes" value="">
                                    <button class="btn btn-sm btn-pass">
                                        <?= icon('ic_fluent_checkmark_24_regular', 13) ?> Pass
                                    </button>
                                </form>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>"
                                      onsubmit="return prepareEval(this, 'fail')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="complete_with_evaluation">
                                    <input type="hidden" name="evaluation_result" value="fail">
                                    <input type="hidden" name="interview_notes" value="">
                                    <button class="btn btn-sm btn-fail">
                                        <?= icon('ic_fluent_dismiss_24_regular', 13) ?> Fail
                                    </button>
                                </form>
                                <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>"
                                      onsubmit="return confirm('Mark as no-show?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_no_show">
                                    <button class="btn btn-ghost btn-sm" style="color:var(--error)">No-show</button>
                                </form>

                            <?php /* ── COMPLETED / NO-SHOW → View notes ─────────── */ ?>
                            <?php elseif ($r['status'] === 'completed'): ?>
                                <?php if ($hasNotes): ?>
                                    <button type="button" class="btn btn-ghost btn-sm"
                                            onclick="toggleNotes('<?= $notesId ?>')">
                                        <?= icon('ic_fluent_edit_24_regular', 13) ?> View Notes
                                    </button>
                                <?php else: ?>
                                    <span style="color:var(--text-tertiary);font-size:var(--text-xs)">—</span>
                                <?php endif; ?>

                            <?php elseif ($r['status'] === 'no_show'): ?>
                                <span style="color:var(--text-tertiary);font-size:var(--text-xs)">—</span>
                            <?php endif; ?>

                        </div>
                    </td>
                </tr>

                <?php /* ── Inline notes / evaluation textarea ──────────────── */ ?>
                <?php if ($r['status'] === 'in_progress' || ($hasNotes && $r['status'] === 'completed')): ?>
                    <tr class="notes-row collapsed" id="<?= $notesId ?>">
                        <td colspan="5">
                            <?php if ($r['status'] === 'in_progress'): ?>
                                <div style="padding-top:var(--space-2)">
                                    <textarea id="<?= $notesId ?>-text"
                                              rows="3"
                                              class="form-control"
                                              placeholder="Interview notes / evaluation remarks…"
                                              style="font-size:var(--text-sm);resize:vertical;width:100%;
                                                     border:1px solid var(--border);border-radius:var(--radius-md);
                                                     padding:var(--space-2) var(--space-3);font-family:inherit"
                                              ><?= e($r['interview_notes'] ?? '') ?></textarea>
                                    <div style="display:flex;justify-content:flex-end;gap:var(--space-2);margin-top:var(--space-2)">
                                        <form method="POST" action="<?= url('/staff/interviews/' . $r['queue_id']) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="save_notes">
                                            <input type="hidden" name="interview_notes"
                                                   id="<?= $notesId ?>-savefield" value="">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    onclick="saveNotesOnly('<?= $notesId ?>', this.closest('form'))">
                                                Save Notes
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php elseif ($hasNotes): ?>
                                <div class="notes-box"><?= e($r['interview_notes']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- ============================================================
     SCRIPT
============================================================ -->
<script>
// Live filter
document.getElementById('q-filter')?.addEventListener('input', (e) => {
    const term = e.target.value.toLowerCase().trim();
    document.querySelectorAll('#queue-table tbody tr:not(.notes-row)').forEach(tr => {
        const haystack = tr.dataset.name || '';
        const show = !term || haystack.includes(term);
        tr.style.display = show ? '' : 'none';
        // Hide accompanying notes row too
        const next = tr.nextElementSibling;
        if (next && next.classList.contains('notes-row')) {
            next.style.display = show ? (next.classList.contains('collapsed') ? 'none' : '') : 'none';
        }
    });
});

// Toggle inline notes box
function toggleNotes(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = !el.classList.contains('collapsed');
    el.classList.toggle('collapsed');
    if (!isOpen) {
        const txt = document.getElementById(id + '-text');
        if (txt) txt.focus();
    }
}

// When clicking Pass / Fail, copy the notes textarea content into the form's
// hidden interview_notes field so the eval saves any in-flight notes.
function prepareEval(form, label) {
    const tr = form.closest('tr');
    if (!tr) return true;
    const notesRow = tr.nextElementSibling;
    if (notesRow && notesRow.classList.contains('notes-row')) {
        const txt = notesRow.querySelector('textarea');
        if (txt) {
            form.querySelector('[name=interview_notes]').value = txt.value;
        }
    }
    return confirm('Mark this interview as ' + (label === 'pass' ? 'PASS' : 'FAIL') + '?');
}

// Save notes only (no Pass/Fail)
function saveNotesOnly(id, form) {
    const txt = document.getElementById(id + '-text');
    const field = document.getElementById(id + '-savefield');
    if (txt && field) {
        field.value = txt.value;
        form.submit();
    }
}

// Auto-refresh every 30s, but pause when a textarea is focused
let lastFocusTime = 0;
document.addEventListener('focusin', (e) => {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') {
        lastFocusTime = Date.now();
    }
});
setInterval(() => {
    // If user typed within last 60s, skip the refresh
    if (Date.now() - lastFocusTime < 60000) return;
    window.location.reload();
}, 30000);
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
