<?php
// ============================================================
// modules/interview/staff_cancel_slot.php
//
// Bulk reschedule (interview side).
//
// Lets SSO / Admin cancel an upcoming interview slot in one
// click and move every applicant booked into it to a
// replacement slot of their choosing. Each affected student
// gets an in-app notification + branded email letting them
// know their slot has changed, and the entire move runs inside
// a single transaction with FOR UPDATE locks on the target
// slot so capacity can't be over-subscribed by two admins
// hitting Cancel at the same time.
//
// Dean is intentionally excluded — bulk-cancel-and-move is a
// scheduling action owned by SSO / the registrar, not an
// academic-oversight one.
//
// This is the "typhoon scenario" flow: cancel a room/day, move
// everyone at once, instead of approving N individual
// reschedule requests.
//
// URL:
//   GET  /staff/interviews/cancel-slot
//   POST /staff/interviews/cancel-slot   (action=cancel_slot)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_slot') {
        $sourceId  = (int)($_POST['source_slot_id'] ?? 0);
        $targetId  = (int)($_POST['target_slot_id'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');

        if ($sourceId <= 0 || $targetId <= 0) {
            Session::flash('error', 'Pick both a slot to cancel and a replacement slot.');
            redirect('/staff/interviews/cancel-slot');
        }
        if ($sourceId === $targetId) {
            Session::flash('error', 'The replacement slot must be different from the slot you\'re cancelling.');
            redirect('/staff/interviews/cancel-slot');
        }
        if ($reason === '') {
            Session::flash('error', 'Please enter a reason — it\'s shown to the affected students.');
            redirect('/staff/interviews/cancel-slot');
        }

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');
        $movedIds  = [];
        $newSlot   = null;
        $oldSlot   = null;
        $errMsg    = null;

        try {
            $db->beginTransaction();

            // Lock both slots up front (lowest id first to avoid deadlocks).
            $lockIds = [$sourceId, $targetId];
            sort($lockIds);
            $lockStmt = $db->prepare(
                'SELECT id FROM interview_slots WHERE id IN (?, ?) FOR UPDATE'
            );
            $lockStmt->execute($lockIds);
            $lockStmt->fetchAll();

            $src = $db->prepare(
                'SELECT s.id, s.slot_date, s.slot_time, s.end_time, s.department, s.location_label, s.status,
                        (SELECT COUNT(*) FROM interview_queue q
                           WHERE q.slot_id = s.id
                             AND q.interview_status IN ("pending","completed")) AS booked
                   FROM interview_slots s WHERE s.id = ? LIMIT 1'
            );
            $src->execute([$sourceId]);
            $oldSlot = $src->fetch();
            if (!$oldSlot) {
                $errMsg = 'The slot you wanted to cancel no longer exists.';
                throw new \RuntimeException($errMsg);
            }

            $tgt = $db->prepare(
                'SELECT s.id, s.slot_date, s.slot_time, s.end_time, s.department, s.location_label, s.status, s.capacity,
                        (SELECT COUNT(*) FROM interview_queue q
                           WHERE q.slot_id = s.id
                             AND q.interview_status IN ("pending","completed")) AS booked
                   FROM interview_slots s WHERE s.id = ? LIMIT 1'
            );
            $tgt->execute([$targetId]);
            $newSlot = $tgt->fetch();
            if (!$newSlot) {
                $errMsg = 'The replacement slot you picked no longer exists.';
                throw new \RuntimeException($errMsg);
            }
            if ($newSlot['status'] !== 'open'
                || (string)$newSlot['slot_date'] < $today
                || ((string)$newSlot['slot_date'] === $today
                    && !empty($newSlot['end_time'])
                    && (string)$newSlot['end_time'] <= $nowTime)) {
                $errMsg = 'The replacement slot is not open or is already in the past.';
                throw new \RuntimeException($errMsg);
            }

            $needed = (int)$oldSlot['booked'];
            $spotsLeft = (int)$newSlot['capacity'] - (int)$newSlot['booked'];
            if ($needed > $spotsLeft) {
                $errMsg = "Replacement slot only has {$spotsLeft} open seat(s) but {$needed} student(s) need to move. "
                        . 'Pick a slot with more capacity, or create one first.';
                throw new \RuntimeException($errMsg);
            }

            // Collect the queue rows we need to migrate.
            $qStmt = $db->prepare(
                'SELECT id, applicant_id
                   FROM interview_queue
                  WHERE slot_id = ?
                    AND interview_status IN ("pending","completed")
                  FOR UPDATE'
            );
            $qStmt->execute([$sourceId]);
            $rows = $qStmt->fetchAll();

            // Figure out the starting queue number on the target slot.
            $nextNumStmt = $db->prepare(
                'SELECT COALESCE(MAX(q.queue_number), 0) + 1
                   FROM interview_queue q
                   JOIN interview_slots s2 ON s2.id = q.slot_id
                  WHERE s2.slot_date = (SELECT slot_date FROM interview_slots WHERE id = ?)
                    AND COALESCE(s2.assigned_to, s2.created_by) = (
                        SELECT COALESCE(assigned_to, created_by)
                          FROM interview_slots WHERE id = ?
                    )
                    AND q.queue_number IS NOT NULL'
            );
            $nextNumStmt->execute([$targetId, $targetId]);
            $nextNum = (int)$nextNumStmt->fetchColumn();

            $updateStmt = $db->prepare(
                'UPDATE interview_queue
                    SET slot_id = ?, queue_number = ?
                  WHERE id = ?'
            );
            foreach ($rows as $r) {
                $updateStmt->execute([$targetId, $nextNum, (int)$r['id']]);
                $movedIds[] = (int)$r['applicant_id'];
                $nextNum++;
            }

            // Close the cancelled slot so future auto-assigns skip it.
            $db->prepare(
                'UPDATE interview_slots SET status = "closed" WHERE id = ?'
            )->execute([$sourceId]);

            // Log a bulk reschedule_requests row per applicant so the
            // student-facing history block shows what happened.
            ensure_reschedule_requests_table();
            $logStmt = $db->prepare(
                'INSERT INTO reschedule_requests
                    (applicant_id, queue_id, reason, status, reviewed_by, reviewed_at)
                  VALUES (?, 0, ?, "approved", ?, NOW())'
            );
            foreach ($rows as $r) {
                try {
                    $logStmt->execute([
                        (int)$r['applicant_id'],
                        '[Bulk reschedule by staff] ' . $reason,
                        $staffId,
                    ]);
                } catch (\Throwable) {
                    // Non-fatal: history logging is best-effort.
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (!$errMsg) {
                error_log('bulk cancel_slot (interview) failed: ' . $e->getMessage());
                $errMsg = 'Could not cancel that slot — please try again.';
            }
            Session::flash('error', $errMsg);
            redirect('/staff/interviews/cancel-slot');
        }

        // Notify every affected student outside the transaction.
        if (!empty($movedIds) && $newSlot) {
            $when = trim(
                format_date((string)$newSlot['slot_date'])
                . (!empty($newSlot['slot_time']) ? ', ' . format_time((string)$newSlot['slot_time']) : '')
            );
            $extra = "Your new slot is {$when}"
                   . (!empty($newSlot['location_label']) ? ' at ' . $newSlot['location_label'] : '')
                   . ($reason !== '' ? '. Reason: ' . $reason : '.');
            foreach ($movedIds as $aid) {
                notify_reschedule_decision($aid, 'interview', 'approved', $extra);
            }
        }

        audit_log(
            'interview_slot_cancelled_bulk',
            "Cancelled interview slot #{$sourceId} and moved " . count($movedIds)
            . " applicant(s) to slot #{$targetId} — {$reason}",
            'interview_slot',
            $sourceId
        );

        Session::flash(
            'success',
            'Cancelled slot and moved ' . count($movedIds) . ' student(s) to the replacement slot. They have been notified.'
        );
        redirect('/staff/interviews/cancel-slot');
    }
}

// ----------------------------------------------------------------
// GET — list upcoming slots with bookings + replacement choices
// ----------------------------------------------------------------
$today   = date('Y-m-d');
$nowTime = date('H:i:s');

$stmt = $db->prepare(
    'SELECT s.id, s.slot_date, s.slot_time, s.end_time, s.department, s.capacity, s.location_label, s.status,
            COALESCE(u.name, "") AS interviewer_name,
            (SELECT COUNT(*) FROM interview_queue q
               WHERE q.slot_id = s.id
                 AND q.interview_status IN ("pending","completed")) AS booked
       FROM interview_slots s
  LEFT JOIN users u ON u.id = COALESCE(s.assigned_to, s.created_by)
      WHERE s.slot_date >= ?
        AND NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
      ORDER BY s.status ASC, s.slot_date ASC, s.slot_time ASC'
);
$stmt->execute([$today, $today, $nowTime]);
$slots = $stmt->fetchAll();

// Split into source candidates (have bookings) and replacement
// candidates (open + has capacity remaining).
$cancellable  = [];
$replacements = [];
foreach ($slots as $s) {
    if ((int)$s['booked'] > 0 && $s['status'] === 'open') {
        $cancellable[] = $s;
    }
    if ($s['status'] === 'open' && (int)$s['booked'] < (int)$s['capacity']) {
        $replacements[] = $s;
    }
}

ob_start();
?>

<div style="margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/interviews/setup') ?>" class="btn btn-ghost btn-sm">← Back to Interview Slots</a>
</div>

<div class="card" style="padding:var(--space-6);margin-bottom:var(--space-5)">
    <h2 style="font-size:var(--text-xl);font-weight:var(--weight-semibold);margin:0 0 var(--space-2)">
        Cancel an interview slot
    </h2>
    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
        Use this for typhoon closures or any time you need to move
        everyone in a slot at once. Pick the slot to cancel, pick a
        replacement slot with enough capacity, and write a short
        reason — every affected student will get an in-app
        notification and email with the new date / time.
    </p>
</div>

<?php if (empty($cancellable)): ?>
    <div class="card" style="padding:var(--space-8);text-align:center;color:var(--text-tertiary)">
        No upcoming slots with bookings to cancel.
    </div>
<?php else: ?>
    <div class="card" style="padding:0;overflow:hidden">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:var(--bg-subtle);text-align:left;font-size:var(--text-xs);
                            color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em">
                    <th style="padding:var(--space-3) var(--space-4)">Slot</th>
                    <th style="padding:var(--space-3) var(--space-4)">Interviewer</th>
                    <th style="padding:var(--space-3) var(--space-4)">Department</th>
                    <th style="padding:var(--space-3) var(--space-4)">Booked</th>
                    <th style="padding:var(--space-3) var(--space-4)">Move everyone to…</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cancellable as $s):
                $needed = (int)$s['booked'];
                $options = array_filter($replacements, fn($r) =>
                    (int)$r['id'] !== (int)$s['id']
                    && ((int)$r['capacity'] - (int)$r['booked']) >= $needed
                );
            ?>
                <tr style="border-top:1px solid var(--border);font-size:var(--text-sm)">
                    <td style="padding:var(--space-3) var(--space-4);white-space:nowrap">
                        <?php
                            // Single-line slot label: "May 14, 2026 · 9:00 AM – 11:00 AM · Room 101"
                            $_slotParts = [format_date($s['slot_date'])];
                            if ($s['slot_time']) {
                                $_t = format_time($s['slot_time']);
                                if ($s['end_time']) $_t .= '–' . format_time($s['end_time']);
                                $_slotParts[] = $_t;
                            }
                            if (!empty($s['location_label'])) $_slotParts[] = $s['location_label'];
                        ?>
                        <span style="font-weight:var(--weight-medium)"><?= format_date($s['slot_date']) ?></span>
                        <?php if (count($_slotParts) > 1): ?>
                            <span style="color:var(--text-tertiary)"> · <?= e(implode(' · ', array_slice($_slotParts, 1))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4)"><?= e($s['interviewer_name'] ?: '—') ?></td>
                    <td style="padding:var(--space-3) var(--space-4)"><?= e($s['department'] ?: 'any') ?></td>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <strong><?= (int)$s['booked'] ?></strong> / <?= (int)$s['capacity'] ?>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <?php if (empty($options)): ?>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                No open slot has enough capacity yet — create one first.
                            </div>
                        <?php else: ?>
                            <form method="POST" style="display:flex;flex-direction:column;gap:var(--space-2)"
                                  onsubmit="return confirm('Cancel this slot and move all <?= (int)$s['booked'] ?> applicant(s)?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_slot">
                                <input type="hidden" name="source_slot_id" value="<?= (int)$s['id'] ?>">
                                <select name="target_slot_id" required class="form-control"
                                        style="font-size:var(--text-xs);height:30px;min-height:30px;padding:0 var(--space-2)">
                                    <option value="">Pick a replacement slot…</option>
                                    <?php foreach ($options as $r):
                                        $left = (int)$r['capacity'] - (int)$r['booked'];
                                    ?>
                                        <option value="<?= (int)$r['id'] ?>">
                                            <?= format_date($r['slot_date']) ?>
                                            <?php if ($r['slot_time']): ?> <?= format_time($r['slot_time']) ?><?php endif; ?>
                                            · <?= e($r['department'] ?: 'any') ?>
                                            (<?= $left ?> open)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="reason" required maxlength="500"
                                       placeholder="Reason shown to students (e.g. Typhoon Pepito)"
                                       class="form-control"
                                       style="font-size:var(--text-xs);height:30px;min-height:30px;padding:0 var(--space-2)">
                                <button type="submit" class="btn btn-sm btn-primary"
                                        style="height:30px;min-height:30px;padding:0 var(--space-3);font-size:var(--text-xs)">
                                    Cancel slot & move all
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Cancel Interview Slot (Bulk)';
$activeNav = 'interview';
$pageWide  = true; // table-heavy page — match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';
