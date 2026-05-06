<?php
// ============================================================
// modules/interview/staff_manage.php
// M5 — Staff: Interview Sessions (schedule management)
// Desk info lives in /staff/settings
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST actions
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_slot') {
        $slotId   = (int)($_POST['slot_id']      ?? 0);
        $date     = trim($_POST['slot_date']     ?? '');
        $time     = trim($_POST['slot_time']     ?? '') ?: null;
        $endTime  = trim($_POST['slot_end_time'] ?? '') ?: null;
        $capacity = max(1, (int)($_POST['capacity'] ?? 30));

        $slotDept = trim($_POST['department'] ?? '');
        if ($slotDept === '') {
            $slotDept = user_department($staffId);
        } elseif (!in_array($slotDept, departments_list(), true)) {
            $errors[] = 'Invalid department selected.';
        }
        if ($slotDept === '') $errors[] = 'Select a department.';
        if (!$slotId)         $errors[] = 'Invalid session.';
        if (!$date)           $errors[] = 'Date is required.';
        if (!$time || !$endTime) $errors[] = 'Start and end time required.';
        elseif ($endTime <= $time) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            // Don't allow shrinking below current booked count
            $stmt = $db->prepare('SELECT COUNT(*) FROM interview_queue WHERE slot_id=?');
            $stmt->execute([$slotId]);
            $booked = (int)$stmt->fetchColumn();
            if ($capacity < $booked) {
                $errors[] = "Cannot shrink capacity below {$booked} (currently booked).";
            } else {
                $db->prepare(
                    'UPDATE interview_slots
                        SET slot_date=?, slot_time=?, end_time=?, capacity=?, department=?
                      WHERE id=?'
                )->execute([$date, $time, $endTime, $capacity, $slotDept, $slotId]);
                audit_log('interview_slot_edited',
                    "Edited slot #{$slotId}: {$date} {$time}–{$endTime} [{$slotDept}] cap {$capacity}",
                    'interview_slot', $slotId);

                // Re-run auto-assignment — the department may have just been
                // set for the first time, so previously-stuck applicants can
                // now be matched to this slot.
                $assigned = 0;
                try {
                    $assigned = bulk_assign_pending_applicants($slotDept, $staffId);
                } catch (Throwable $e) {
                    error_log('bulk_assign after edit_slot failed: ' . $e->getMessage());
                }

                $success[] = 'Session updated.'
                    . ($assigned > 0
                        ? " {$assigned} waiting applicant(s) were automatically assigned."
                        : '');
            }
        }
    }

    if ($action === 'create_slot') {
        $date     = trim($_POST['slot_date']     ?? '');
        $time     = trim($_POST['slot_time']     ?? '') ?: null;
        $endTime  = trim($_POST['slot_end_time'] ?? '') ?: null;
        $capacity = max(1, (int)($_POST['capacity'] ?? 30));

        // Department defaults to the staff's own department.  Admins (or
        // cross-department staff) may override via a dropdown.
        $slotDept = trim($_POST['department'] ?? '');
        if ($slotDept === '') {
            $slotDept = user_department($staffId);
        } elseif (!in_array($slotDept, departments_list(), true)) {
            $errors[] = 'Invalid department selected.';
        }
        if ($slotDept === '') {
            $errors[] = 'Select a department for this session.';
        }

        if (!$date) {
            $errors[] = 'Date is required.';
        } elseif ($date < date('Y-m-d')) {
            $errors[] = 'Date cannot be in the past.';
        } elseif (!$time || !$endTime) {
            $errors[] = 'Start and end time required.';
        } elseif ($endTime <= $time) {
            $errors[] = 'End time must be after start time.';
        } elseif (empty($errors)) {
            try {
                $db->prepare(
                    'INSERT INTO interview_slots
                        (slot_date, slot_time, end_time, capacity, department, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$date, $time, $endTime, $capacity, $slotDept, $staffId]);
                $newSlotId = (int)$db->lastInsertId();
                audit_log(
                    'interview_slot_created',
                    "Created slot #{$newSlotId} on {$date} for "
                        . ($slotDept !== '' ? $slotDept : 'any department'),
                    'interview_slot',
                    $newSlotId
                );

                // Auto-assign pending applicants to the new (and any
                // other open) department slot(s).  Students never book
                // their own slot — this is how assignment happens.
                $assigned = 0;
                try {
                    $assigned = bulk_assign_pending_applicants(
                        $slotDept !== '' ? $slotDept : null,
                        $staffId
                    );
                } catch (Throwable $e) {
                    error_log('bulk_assign after create_slot failed: ' . $e->getMessage());
                }

                $success[] = 'Session added for ' . format_date($date) . '.'
                    . ($assigned > 0
                        ? " {$assigned} pending applicant(s) were automatically assigned."
                        : '');
            } catch (PDOException) {
                $errors[] = 'Unable to create session. Try again.';
            }
        }
    }

}

// ----------------------------------------------------------------
// Desk info (from new interview_desks table)
// ----------------------------------------------------------------
$staffDept = user_department($staffId);

// Auto-create interview_desks table if missing
try {
    $db->query("SELECT id FROM interview_desks LIMIT 0");
} catch (\Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS interview_desks (
        id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        department  VARCHAR(120)     NOT NULL DEFAULT '',
        desk_label  VARCHAR(120)     NOT NULL DEFAULT '',
        desk_notes  TEXT             DEFAULT NULL,
        is_active   TINYINT(1)      NOT NULL DEFAULT 1,
        created_by  INT(10) UNSIGNED NOT NULL,
        created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_desk_department (department)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$deskCount  = (int)$db->query('SELECT COUNT(*) FROM interview_desks')->fetchColumn();
$hasDesks   = $deskCount > 0;

// View routing: landing (default) or sessions
$interviewView = $_GET['view'] ?? '';
if ($interviewView !== 'sessions') {
    $interviewView = 'landing';
}

// ----------------------------------------------------------------
// Load sessions (only when showing sessions view)
// ----------------------------------------------------------------
$showPast = isset($_GET['past']);
$today    = date('Y-m-d');
$slots    = [];
$byDate   = [];
$hasToday = false;

// Helper: has this slot passed its end time?
function slot_is_expired(string $date, ?string $endTime, string $today, string $nowTime): bool {
    if ($date < $today) return true;
    if ($date === $today && $endTime !== null && $nowTime >= $endTime) return true;
    return false;
}

// Count upcoming sessions for landing card
$upcomingStmt = $db->prepare(
    'SELECT COUNT(*) FROM interview_slots WHERE created_by = ? AND slot_date >= ?'
);
$upcomingStmt->execute([$staffId, $today]);
$upcomingCount = (int)$upcomingStmt->fetchColumn();

if ($interviewView === 'sessions') {
    if ($showPast) {
        $stmt = $db->prepare(
            'SELECT s.*,
                    COUNT(q.id)                    AS booked,
                    SUM(q.status = "in_progress")  AS in_progress,
                    SUM(q.status = "completed")    AS completed,
                    SUM(q.status = "no_show")      AS no_show
             FROM   interview_slots s
             LEFT JOIN interview_queue q ON q.slot_id = s.id
             WHERE  s.created_by = ? AND s.slot_date < ?
             GROUP BY s.id
             ORDER BY s.slot_date DESC, s.slot_time DESC
             LIMIT 60'
        );
        $stmt->execute([$staffId, $today]);
    } else {
        $stmt = $db->prepare(
            'SELECT s.*, s.end_time,
                    COUNT(q.id)                    AS booked,
                    SUM(q.status = "checked_in")   AS waiting,
                    SUM(q.status = "in_progress")  AS in_progress,
                    SUM(q.status = "completed")    AS completed,
                    SUM(q.status = "no_show")      AS no_show
             FROM   interview_slots s
             LEFT JOIN interview_queue q ON q.slot_id = s.id
             WHERE  s.created_by = ? AND s.slot_date >= ?
             GROUP BY s.id
             ORDER BY s.slot_date ASC, s.slot_time ASC
             LIMIT 200'
        );
        $stmt->execute([$staffId, $today]);
    }

    $slots  = $stmt->fetchAll();
    foreach ($slots as $slot) {
        $byDate[$slot['slot_date']][] = $slot;
    }

    $todayStmt = $db->prepare(
        'SELECT COUNT(*) FROM interview_slots WHERE created_by = ? AND slot_date = ?'
    );
    $todayStmt->execute([$staffId, $today]);
    $hasToday = (int)$todayStmt->fetchColumn() > 0;
}



ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($s) ?></div>
<?php endforeach; ?>

<style>
    @keyframes pulse-dot {
        0%,100%{opacity:1;transform:scale(1)}
        50%{opacity:.5;transform:scale(1.3)}
    }
    .intv-landing-wrap {
        display:flex;align-items:center;justify-content:center;
        min-height:calc(100vh - 200px);padding-bottom:var(--space-16);
    }
    .intv-landing-grid {
        display:grid;grid-template-columns:1fr 1fr;gap:var(--space-6);
        max-width:640px;width:100%;
    }
    .intv-landing-card {
        display:flex;flex-direction:column;align-items:center;text-align:center;
        gap:var(--space-4);padding:var(--space-10) var(--space-6);
        background:var(--bg-elevated);border:1.5px solid var(--border);
        border-radius:var(--radius-lg);text-decoration:none;color:var(--text-primary);
        transition:border-color .18s,box-shadow .18s,transform .15s;cursor:pointer;
    }
    .intv-landing-card:hover {
        border-color:var(--accent);box-shadow:0 8px 24px rgba(0,0,0,.08);transform:translateY(-4px);
    }
    .intv-landing-card--disabled {
        opacity:0.45;pointer-events:none;cursor:not-allowed;
    }
    .intv-landing-icon {
        width:60px;height:60px;border-radius:var(--radius-lg);
        background:var(--accent-muted);color:var(--accent);
        display:flex;align-items:center;justify-content:center;flex-shrink:0;
    }
    .intv-landing-title {
        font-size:var(--text-lg);font-weight:var(--weight-semibold);
        color:var(--text-primary);letter-spacing:-0.2px;margin-top:var(--space-1);
    }
    .intv-landing-desc {
        font-size:var(--text-sm);color:var(--text-secondary);line-height:1.55;
        margin-top:var(--space-1);
    }
    .intv-landing-meta {
        font-size:var(--text-xs);color:var(--text-tertiary);
        display:flex;align-items:center;gap:var(--space-2);
        justify-content:center;flex-wrap:wrap;margin-top:var(--space-2);
    }
    @media (max-width:640px) { .intv-landing-grid { grid-template-columns:1fr;max-width:320px; } }
</style>

<?php if ($interviewView === 'landing'): ?>
<!-- ================================================================
     LANDING — two entry-point cards (like Exams)
================================================================ -->
<div class="intv-landing-wrap">
<div class="intv-landing-grid">

    <!-- Interview Desks (left) -->
    <a href="<?= url('/staff/interviews/desks') ?>" class="intv-landing-card">
        <div class="intv-landing-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                      d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1118 0z"/>
                <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
            </svg>
        </div>
        <div class="intv-landing-title">Interview Desks</div>
        <div class="intv-landing-desc">
            Manage interview desk locations per college. Students see this location after being assigned.
        </div>
        <div class="intv-landing-meta">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/></svg>
            <?= $deskCount ?> desk<?= $deskCount !== 1 ? 's' : '' ?> configured
        </div>
    </a>

    <!-- Interview Sessions (right) — disabled if no desks -->
    <?php if ($hasDesks): ?>
    <a href="<?= url('/staff/interviews') ?>?view=sessions" class="intv-landing-card">
    <?php else: ?>
    <div class="intv-landing-card intv-landing-card--disabled" title="Set up Interview Desks first">
    <?php endif; ?>
        <div class="intv-landing-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
            </svg>
        </div>
        <div class="intv-landing-title">Interview Sessions</div>
        <div class="intv-landing-desc">
            Schedule interview days, manage capacity, and track applicant queues per college.
        </div>
        <div class="intv-landing-meta">
            <?php if (!$hasDesks): ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
                Set up Interview Desks first
            <?php else: ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/></svg>
                <?= $upcomingCount ?> upcoming session<?= $upcomingCount !== 1 ? 's' : '' ?>
            <?php endif; ?>
        </div>
    <?php if ($hasDesks): ?>
    </a>
    <?php else: ?>
    </div>
    <?php endif; ?>

</div>
</div>

<?php else: ?>
<!-- ================================================================
     SESSIONS VIEW — TAB STRIP
================================================================ -->
<div style="display:flex;align-items:center;margin-bottom:var(--space-5);position:relative">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm" style="margin-right:auto">← Back</a>
    <div style="position:absolute;left:50%;transform:translateX(-50%);display:flex;gap:0;border:1px solid var(--border);border-radius:var(--radius-md);
                 overflow:hidden;background:var(--bg-elevated)">
        <?php if ($hasToday): ?>
            <a href="<?= url('/staff/interviews/queue') ?>"
               style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                      text-decoration:none;color:var(--text-secondary);
                      display:flex;align-items:center;gap:var(--space-2);
                      border-right:1px solid var(--border)">
                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;
                              background:var(--accent);animation:pulse-dot 1.8s ease-in-out infinite"></span>
                Live Queue
            </a>
        <?php endif; ?>
        <a href="<?= url('/staff/interviews') ?>?view=sessions"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;border-right:1px solid var(--border);
                  <?= !$showPast ? 'background:var(--bg-subtle);color:var(--text-primary);font-weight:var(--weight-medium)' : 'color:var(--text-secondary)' ?>">
            Upcoming
        </a>
        <a href="?view=sessions&past=1"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;border-right:1px solid var(--border);
                  <?= $showPast ? 'background:var(--bg-subtle);color:var(--text-primary);font-weight:var(--weight-medium)' : 'color:var(--text-secondary)' ?>">
            Past
        </a>
        <a href="<?= url('/staff/interviews/absent') ?>"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;color:var(--text-secondary)">
            Absent
        </a>
        <div style="margin-left:auto;padding-right:var(--space-2)">
            <a href="<?= url('/staff/interviews/batch') ?>" class="btn btn-sm" style="font-size:var(--text-xs)">
                <?= icon('ic_fluent_calendar_add_24_regular', 14) ?>
                Batch Create
            </a>
        </div>
    </div>
</div>

<!-- ================================================================
     SESSIONS LIST
================================================================ -->
<?php if (empty($byDate)): ?>
    <div style="text-align:center;padding:var(--space-16) var(--space-4);color:var(--text-tertiary);display:flex;flex-direction:column;align-items:center;gap:var(--space-4)">
        <div style="font-size:var(--text-sm)">
            <?= $showPast ? 'No past sessions.' : 'No upcoming sessions yet.' ?>
        </div>
        <?php if (!$showPast): ?>
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-session-modal').style.display='flex'">
                + Add Session
            </button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:var(--space-2)">
    <?php foreach ($byDate as $date => $dateSlots): ?>

        <!-- Date divider -->
        <div style="display:flex;align-items:center;gap:var(--space-3);
                     padding:var(--space-3) 0 var(--space-1);
                     color:var(--text-tertiary);font-size:var(--text-xs);
                     font-weight:var(--weight-medium);letter-spacing:.04em">
            <span><?= format_date($date, 'l, F j, Y') ?></span>
            <?php if ($date === $today): ?>
                <span class="badge badge-info" style="letter-spacing:0">Today</span>
            <?php endif; ?>
            <div style="flex:1;height:1px;background:var(--border)"></div>
        </div>

        <?php foreach ($dateSlots as $slot):
            $booked     = (int)$slot['booked'];
            $capacity   = (int)$slot['capacity'];
            $waiting    = (int)($slot['waiting']     ?? 0);
            $inProgress = (int)($slot['in_progress'] ?? 0);
            $completed  = (int)($slot['completed']   ?? 0);
            $noShow     = (int)($slot['no_show']      ?? 0);
            $nowTime    = date('H:i:s');
            $isExpired  = slot_is_expired($date, $slot['end_time'] ?? null, $today, $nowTime);
            $isClosed   = $slot['status'] === 'closed' || $isExpired;
            $isFull     = $booked >= $capacity;
            $canDelete  = $booked === 0;
            $fillPct    = $capacity > 0 ? min(100, round(($booked / $capacity) * 100)) : 0;

            // Build the time range label
            $timeLabel = 'All day';
            if ($slot['slot_time']) {
                $timeLabel = format_time($slot['slot_time']);
                if ($slot['end_time']) {
                    $timeLabel .= ' – ' . format_time($slot['end_time']);
                }
            }
        ?>
            <div class="card" style="padding:var(--space-4) var(--space-5)">
                <div style="display:flex;align-items:center;gap:var(--space-4)">

                    <!-- Time range -->
                    <div style="min-width:<?= $slot['end_time'] ? '140px' : '64px' ?>;font-size:var(--text-sm);
                                 font-weight:var(--weight-medium);color:var(--text-secondary)">
                        <?= $timeLabel ?>
                    </div>

                    <!-- Capacity + live stats -->
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:baseline;gap:var(--space-2);margin-bottom:var(--space-2)">
                            <span style="font-weight:var(--weight-semibold);font-size:var(--text-sm)"><?= $booked ?></span>
                            <span style="color:var(--text-tertiary);font-size:var(--text-xs)">/ <?= $capacity ?></span>
                            <?php if ($date === $today && ($inProgress + $waiting) > 0): ?>
                                <span style="color:var(--text-tertiary);font-size:var(--text-xs)">·</span>
                                <?php if ($inProgress): ?>
                                    <span style="color:var(--accent);font-size:var(--text-xs)">● <?= $inProgress ?> in interview</span>
                                <?php endif; ?>
                                <?php if ($waiting): ?>
                                    <span style="color:var(--text-secondary);font-size:var(--text-xs)"><?= $waiting ?> waiting</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <!-- Fill bar -->
                        <div style="height:3px;border-radius:99px;background:var(--border);overflow:hidden">
                            <div style="height:100%;width:<?= $fillPct ?>%;border-radius:99px;
                                         background:<?= $isClosed ? 'var(--border-strong)' : ($isFull ? 'var(--warning)' : 'var(--accent)') ?>;
                                         transition:width .3s ease"></div>
                        </div>
                    </div>

                    <!-- Status badge -->
                    <?php if ($isExpired && $slot['status'] !== 'closed'): ?>
                        <span class="badge badge-neutral">Ended</span>
                    <?php elseif ($isClosed): ?>
                        <span class="badge badge-neutral">Closed</span>
                    <?php elseif ($isFull): ?>
                        <span class="badge badge-review">Full</span>
                    <?php else: ?>
                        <span class="badge badge-approved">Open</span>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div style="display:flex;align-items:center;gap:var(--space-1)">

                        <a href="<?= url('/staff/interviews/' . $slot['id'] . '/roster') ?>"
                           class="btn btn-ghost btn-sm">
                            Roster (<?= $booked ?>)
                        </a>

        <?php if (!$isExpired): ?>
                        <button type="button" class="btn-icon" title="Edit session"
                                style="padding:var(--space-1)"
                                onclick="openEditSession(<?= (int)$slot['id'] ?>, '<?= e($slot['slot_date']) ?>', '<?= e(substr($slot['slot_time'] ?? '',0,5)) ?>', '<?= e(substr($slot['end_time'] ?? '',0,5)) ?>', <?= $capacity ?>, '<?= e(addslashes($slot['department'])) ?>')">
                            <?= icon('ic_fluent_edit_24_regular', 14) ?>
                        </button>
                        <?php endif; ?>

                        <?php if (!$isExpired): ?>
                        <form method="POST" action="<?= url('/staff/interviews/' . $slot['id']) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"
                                   value="<?= $slot['status'] === 'closed' ? 'open_slot' : 'close_slot' ?>">
                            <button class="btn btn-ghost btn-sm">
                                <?= $slot['status'] === 'closed' ? 'Reopen' : 'Close' ?>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($canDelete): ?>
                            <form method="POST" action="<?= url('/staff/interviews/' . $slot['id']) ?>"
                                  onsubmit="return confirm('Remove this session?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_slot">
                                <button class="btn-icon" style="color:var(--text-tertiary);padding:var(--space-1)"
                                        title="Delete session">
<?= icon('ic_fluent_delete_24_regular', 14) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$showPast): ?>
<div style="display:flex;justify-content:center;margin-top:var(--space-4)">
    <button class="btn btn-primary btn-sm"
            onclick="document.getElementById('add-session-modal').style.display='flex'">
        + Add Session
    </button>
</div>
<?php endif; ?>

<!-- ================================================================
     ADD SESSION MODAL
================================================================ -->
<div id="add-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:360px">
        <div class="modal-header">
            <div class="modal-title">New Session</div>
            <button class="btn-icon"
                    onclick="document.getElementById('add-session-modal').style.display='none'">
<?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_slot">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">

                <div>
                    <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="slot_date" class="form-control"
                           min="<?= date('Y-m-d') ?>" required>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" class="form-control" id="modal-start-time" required>
                    </div>
                    <div>
                        <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_end_time" class="form-control" id="modal-end-time" required>
                    </div>
                </div>

                <div>
                    <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                    <input type="number" name="capacity" class="form-control"
                           value="45" min="1" max="500" required>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Recommended: 40–50 per day.
                    </p>
                </div>

                <div>
                    <label class="form-label">College / Department</label>
                    <?php if ($isAdmin): ?>
                        <select name="department" class="form-control" required>
                            <option value="">— Select college —</option>
                            <?php foreach (departments_list() as $deptName): ?>
                                <option value="<?= e($deptName) ?>"><?= e($deptName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= e($staffDept ?: 'Not assigned') ?>" disabled>
                        <input type="hidden" name="department" value="<?= e($staffDept) ?>">
                    <?php endif; ?>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Only applicants from this college will be assigned to this session.
                    </p>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('add-session-modal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Add Session</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Session Modal ──────────────────────────────────── -->
<div id="edit-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <div class="modal-title">Edit Session</div>
            <button class="btn-icon" onclick="document.getElementById('edit-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit_slot">
            <input type="hidden" name="slot_id" id="edit-sess-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">College / Department <span style="color:var(--error)">*</span></label>
                    <?php if ($isAdmin): ?>
                        <select name="department" id="edit-sess-dept" class="form-control" required>
                            <option value="">— Select college —</option>
                            <?php foreach (departments_list() as $deptName): ?>
                                <option value="<?= e($deptName) ?>"><?= e($deptName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= e($staffDept ?: 'Not assigned') ?>" disabled>
                        <input type="hidden" name="department" value="<?= e($staffDept) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="slot_date" id="edit-sess-date" class="form-control" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" id="edit-sess-time" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_end_time" id="edit-sess-end" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                        <input type="number" name="capacity" id="edit-sess-cap" class="form-control"
                               min="1" max="500" value="30" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('edit-session-modal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditSession(id, date, time, endTime, cap, dept) {
    document.getElementById('edit-sess-id').value   = id;
    document.getElementById('edit-sess-date').value = date;
    document.getElementById('edit-sess-time').value = time;
    document.getElementById('edit-sess-end').value  = endTime;
    document.getElementById('edit-sess-cap').value  = cap;
    var deptSel = document.getElementById('edit-sess-dept');
    if (deptSel) deptSel.value = dept;
    document.getElementById('edit-session-modal').style.display = 'flex';
}
['add-session-modal','edit-session-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>

<?php endif; /* end landing vs sessions view */ ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Sessions';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';