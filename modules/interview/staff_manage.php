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
$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST actions
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_desk') {
        $deskLabel = trim($_POST['desk_label'] ?? '');
        $deskNotes = trim($_POST['desk_notes'] ?? '');
        if (!$deskLabel) {
            $errors[] = 'Location label is required.';
        } else {
            $db->prepare('UPDATE users SET desk_label=?, desk_notes=? WHERE id=?')
               ->execute([$deskLabel, $deskNotes ?: null, $staffId]);
            $success[] = 'Desk info saved.';
        }
    }

    if ($action === 'create_slot') {
        $date    = trim($_POST['slot_date']     ?? '');
        $time    = trim($_POST['slot_time']     ?? '') ?: null;
        $endTime = trim($_POST['slot_end_time'] ?? '') ?: null;
        $capacity = max(1, (int)($_POST['capacity'] ?? 30));

        if (!$date) {
            $errors[] = 'Please select a date.';
        } elseif ($date < date('Y-m-d')) {
            $errors[] = 'Date cannot be in the past.';
        } elseif ($time && $endTime && $endTime <= $time) {
            $errors[] = 'End time must be after the start time.';
        } else {
            try {
                $db->prepare(
                    'INSERT INTO interview_slots (slot_date, slot_time, end_time, capacity, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$date, $time, $endTime, $capacity, $staffId]);
                $success[] = 'Session added for ' . format_date($date) . '.';
            } catch (PDOException) {
                $errors[] = 'Could not create session. Please try again.';
            }
        }
    }
}

// ----------------------------------------------------------------
// Desk info
// ----------------------------------------------------------------
$deskStmt = $db->prepare('SELECT desk_label, desk_notes FROM users WHERE id=?');
$deskStmt->execute([$staffId]);
$deskRow   = $deskStmt->fetch();
$deskLabel = $deskRow['desk_label'] ?? '';
$deskNotes = $deskRow['desk_notes'] ?? '';

// ----------------------------------------------------------------
// Load sessions
// ----------------------------------------------------------------
$showPast = isset($_GET['past']);
$today    = date('Y-m-d');

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
$byDate = [];
foreach ($slots as $slot) {
    $byDate[$slot['slot_date']][] = $slot;
}

// ----------------------------------------------------------------
// Helper: has this slot passed its end time?
// ----------------------------------------------------------------
function slot_is_expired(string $date, ?string $endTime, string $today, string $nowTime): bool {
    if ($date < $today) return true;
    if ($date === $today && $endTime !== null && $nowTime >= $endTime) return true;
    return false;
}

// Check for today's sessions independently of which tab is shown
$todayStmt = $db->prepare(
    'SELECT COUNT(*) FROM interview_slots WHERE created_by = ? AND slot_date = ?'
);
$todayStmt->execute([$staffId, $today]);
$hasToday = (int)$todayStmt->fetchColumn() > 0;

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($s) ?></div>
<?php endforeach; ?>

<!-- ================================================================
     TAB STRIP
================================================================ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
    <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:var(--radius-md);
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
        <a href="<?= url('/staff/interviews') ?>"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;border-right:1px solid var(--border);
                  <?= !$showPast ? 'background:var(--bg-subtle);color:var(--text-primary);font-weight:var(--weight-medium)' : 'color:var(--text-secondary)' ?>">
            Upcoming
        </a>
        <a href="?past=1"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;
                  <?= $showPast ? 'background:var(--bg-subtle);color:var(--text-primary);font-weight:var(--weight-medium)' : 'color:var(--text-secondary)' ?>">
            Past
        </a>
    </div>

    <?php if (!$showPast): ?>
        <button class="btn btn-primary btn-sm"
                onclick="document.getElementById('add-session-modal').style.display='flex'">
            + Add Session
        </button>
    <?php endif; ?>
</div>

<style>
    @keyframes pulse-dot {
        0%,100%{opacity:1;transform:scale(1)}
        50%{opacity:.5;transform:scale(1.3)}
    }
</style>

<!-- ================================================================
     DESK INFO CARD
================================================================ -->
<div class="card" style="padding:var(--space-5);margin-bottom:var(--space-5)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
        <div>
            <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold)">Interview Desk</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                Students see this location after booking
            </div>
        </div>
        <?php if ($deskLabel): ?>
            <span class="badge badge-approved">Set</span>
        <?php else: ?>
            <span class="badge badge-rejected">Not set</span>
        <?php endif; ?>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_desk">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
            <div>
                <label class="form-label">Location <span style="color:var(--error)">*</span></label>
                <input type="text" name="desk_label" class="form-control"
                       value="<?= e($deskLabel) ?>"
                       placeholder="e.g. Room 201 – Desk A">
            </div>
            <div>
                <label class="form-label">
                    Directions
                    <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                </label>
                <input type="text" name="desk_notes" class="form-control"
                       value="<?= e($deskNotes) ?>"
                       placeholder="e.g. 2nd floor, turn left">
            </div>
        </div>
        <div style="margin-top:var(--space-3);display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-secondary btn-sm">Save Desk Info</button>
        </div>
    </form>
</div>

<!-- ================================================================
     SESSIONS LIST
================================================================ -->
<?php if (empty($byDate)): ?>
    <div style="text-align:center;padding:var(--space-16) var(--space-4);color:var(--text-tertiary)">
        <div style="font-size:var(--text-sm)">
            <?= $showPast ? 'No past sessions.' : 'No upcoming sessions yet.' ?>
        </div>
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
                                  onsubmit="return confirm('Delete this session?')">
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

                <div>
                    <label class="form-label">
                        Start Time
                        <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                    </label>
                    <input type="time" name="slot_time" class="form-control" id="modal-start-time">
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Leave blank if students can arrive any time that day
                    </p>
                </div>

                <div>
                    <label class="form-label">
                        End Time
                        <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                    </label>
                    <input type="time" name="slot_end_time" class="form-control" id="modal-end-time">
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Session automatically closes to new check-ins after this time
                    </p>
                </div>

                <div>
                    <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                    <input type="number" name="capacity" class="form-control"
                           value="30" min="1" max="500" required>
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

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Sessions';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';