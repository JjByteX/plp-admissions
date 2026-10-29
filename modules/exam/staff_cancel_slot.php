<?php
// ============================================================
// modules/exam/staff_cancel_slot.php
//
// Bulk reschedule (exam side).
//
// Mirrors modules/interview/staff_cancel_slot.php. Lets SSO /
// Admin cancel an upcoming exam slot and move every applicant
// assigned to it to a replacement slot in one step. Each
// affected student is notified (in-app + email). The slot
// move runs inside a transaction with FOR UPDATE locks on the
// target slot so capacity can't be over-subscribed.
//
// URL:
//   GET  /staff/exam/cancel-slot
//   POST /staff/exam/cancel-slot   (action=cancel_slot)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();

if (($action = require_post_action()) !== null) {

    if ($action === 'cancel_slot') {
        cancel_slot_and_move([
            'noun'      => 'exam',
            'redirect'  => '/staff/exam/cancel-slot',
            'lockTable' => 'exam_slot_schedule',

            'loadSlot' => function (PDO $db, int $id): ?array {
                $stmt = $db->prepare(
                    'SELECT id, exam_date, slot_time, room_label, exam_id, capacity, filled
                       FROM exam_slot_schedule WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row ?: null;
            },

            'capacityOf' => fn(array $slot): int => (int)$slot['capacity'],
            'bookedOf'   => fn(array $slot): int => (int)$slot['filled'],

            'validateTarget' => function (array $oldSlot, array $newSlot, string $today): ?string {
                if ((string)$newSlot['exam_date'] < $today) {
                    return 'The replacement slot is already in the past.';
                }

                // Same-exam guard: a multi-exam school can't accidentally
                // move students between different exams during a bulk
                // cancel. NULL exam_id means "any exam" so it's allowed.
                $srcExamId = $oldSlot['exam_id'] !== null ? (int)$oldSlot['exam_id'] : null;
                $tgtExamId = $newSlot['exam_id'] !== null ? (int)$newSlot['exam_id'] : null;
                if ($srcExamId !== null && $tgtExamId !== null && $srcExamId !== $tgtExamId) {
                    return 'The replacement slot is for a different exam than the one you\'re cancelling.';
                }

                return null;
            },

            'moveApplicants' => function (PDO $db, int $sourceId, int $targetId, int $staffId, string $reason): array {
                $movedIds = [];

                // Gather affected applicants.
                $aStmt = $db->prepare(
                    'SELECT id, applicant_id FROM applicant_exam_slots WHERE slot_id = ? FOR UPDATE'
                );
                $aStmt->execute([$sourceId]);
                $rows = $aStmt->fetchAll();
                $needed = count($rows);

                if ($needed > 0) {
                    // Move them all.
                    $db->prepare(
                        'UPDATE applicant_exam_slots
                            SET slot_id = ?, assigned_at = NOW()
                          WHERE slot_id = ?'
                    )->execute([$targetId, $sourceId]);

                    $db->prepare(
                        'UPDATE exam_slot_schedule
                            SET filled = filled + ?
                          WHERE id = ?'
                    )->execute([$needed, $targetId]);

                    $db->prepare(
                        'UPDATE exam_slot_schedule
                            SET filled = GREATEST(filled - ?, 0)
                          WHERE id = ?'
                    )->execute([$needed, $sourceId]);

                    foreach ($rows as $r) {
                        $movedIds[] = (int)$r['applicant_id'];
                    }

                    // Bulk-log into exam_reschedule_requests so the
                    // student-facing history view shows what happened.
                    $logStmt = $db->prepare(
                        'INSERT INTO exam_reschedule_requests
                            (applicant_id, slot_id, reason, status, reviewed_by, reviewed_at)
                          VALUES (?, ?, ?, \'approved\', ?, NOW())'
                    );
                    foreach ($rows as $r) {
                        // Postgres aborts the whole transaction on any error, so a
                        // savepoint keeps this log insert non-fatal like it was in MySQL.
                        $db->exec('SAVEPOINT bulk_log');
                        try {
                            $logStmt->execute([
                                (int)$r['applicant_id'],
                                $sourceId,
                                '[Bulk reschedule by staff] ' . $reason,
                                $staffId,
                            ]);
                            $db->exec('RELEASE SAVEPOINT bulk_log');
                        } catch (\Throwable) {
                            // Non-fatal.
                            $db->exec('ROLLBACK TO SAVEPOINT bulk_log');
                        }
                    }
                }

                return $movedIds;
            },

            'describeSlot' => function (array $slot): string {
                $when = trim(
                    format_date((string)$slot['exam_date'])
                    . (!empty($slot['slot_time']) ? ', ' . format_time((string)$slot['slot_time']) : '')
                );
                return $when . (!empty($slot['room_label']) ? ' at ' . $slot['room_label'] : '');
            },

            'auditAction' => 'exam_slot_cancelled_bulk',
        ]);
    }
}

// ----------------------------------------------------------------
// GET — list upcoming slots with bookings + replacement candidates
// ----------------------------------------------------------------
$today = now_date();

$stmt = $db->prepare(
    'SELECT id, exam_date, slot_time, end_time, room_label, department, exam_id, capacity, filled
       FROM exam_slot_schedule
      WHERE exam_date >= ?
      ORDER BY exam_date ASC, slot_time ASC'
);
$stmt->execute([$today]);
$slots = $stmt->fetchAll();

$cancellable  = [];
$replacements = [];
foreach ($slots as $s) {
    if ((int)$s['filled'] > 0) {
        $cancellable[] = $s;
    }
    if ((int)$s['filled'] < (int)$s['capacity']) {
        $replacements[] = $s;
    }
}

ob_start();
?>

<div class="card" style="padding:var(--space-6);margin-bottom:var(--space-5);flex-shrink:0">
    <h2 style="font-size:var(--text-xl);font-weight:var(--weight-semibold);margin:0 0 var(--space-2)">
        Cancel an exam slot
    </h2>
    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
        For typhoon closures, room conflicts, or any time you need
        to move everyone in an exam slot at once. Pick the slot to
        cancel, pick a replacement slot with enough capacity, and
        write a short reason - every affected student will get an
        in-app notification and email with the new date / time / room.
    </p>
</div>

<?php if (empty($cancellable)): ?>
    <div class="card" style="padding:var(--space-8);text-align:center;color:var(--text-tertiary)">
        No upcoming exam slots with bookings to cancel.
    </div>
<?php else: ?>
    <div class="card" style="padding:0;overflow:hidden">
        <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:var(--bg-subtle);text-align:left;font-size:var(--text-xs);
                            color:var(--text-secondary)">
                    <th style="padding:var(--space-3) var(--space-4)">Slot</th>
                    <th style="padding:var(--space-3) var(--space-4)">Room</th>
                    <th style="padding:var(--space-3) var(--space-4)">Department</th>
                    <th style="padding:var(--space-3) var(--space-4)">Filled</th>
                    <th style="padding:var(--space-3) var(--space-4)">Move everyone to…</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cancellable as $s):
                $needed   = (int)$s['filled'];
                $srcExam  = $s['exam_id'] !== null ? (int)$s['exam_id'] : null;
                $options  = array_filter($replacements, function($r) use ($s, $needed, $srcExam) {
                    if ((int)$r['id'] === (int)$s['id']) return false;
                    if (((int)$r['capacity'] - (int)$r['filled']) < $needed) return false;
                    $rExam = $r['exam_id'] !== null ? (int)$r['exam_id'] : null;
                    // Same-exam: allow if either side is null (any exam)
                    if ($srcExam !== null && $rExam !== null && $srcExam !== $rExam) return false;
                    return true;
                });
            ?>
                <tr style="border-top:1px solid var(--border);font-size:var(--text-sm);vertical-align:top">
                    <td style="padding:var(--space-3) var(--space-4);white-space:nowrap">
                        <?php // Single-line slot label: "May 14, 2026 · 9:00 AM – 11:00 AM" ?>
                        <span class="auto-table-clip">
                            <span style="font-weight:var(--weight-medium)"><?= format_date($s['exam_date']) ?></span>
                            <?php if ($s['slot_time']): ?>
                                <span style="color:var(--text-tertiary)"> · <?= format_time($s['slot_time']) ?><?= $s['end_time'] ? '–' . format_time($s['end_time']) : '' ?></span>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4)"><?= e($s['room_label'] ?: '-') ?></td>
                    <td style="padding:var(--space-3) var(--space-4)"><?= e($s['department'] ?: 'any') ?></td>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <strong><?= (int)$s['filled'] ?></strong> / <?= (int)$s['capacity'] ?>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <?php if (empty($options)): ?>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                No open slot has enough capacity yet - create one first.
                            </div>
                        <?php else: ?>
                            <form method="POST" style="display:flex;flex-direction:column;gap:var(--space-2)"
                                  data-confirm="Cancel this slot and move all <?= (int)$s['filled'] ?> applicant(s)?" data-confirm-danger data-confirm-ok="Cancel slot">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_slot">
                                <input type="hidden" name="source_slot_id" value="<?= (int)$s['id'] ?>">
                                <select id="cancel_target_<?= (int)$s['id'] ?>" name="target_slot_id" required class="form-control" aria-label="Replacement slot">
                                    <option value="">Pick a replacement slot…</option>
                                    <?php foreach ($options as $r):
                                        $left = (int)$r['capacity'] - (int)$r['filled'];
                                    ?>
                                        <option value="<?= (int)$r['id'] ?>">
                                            <?= format_date($r['exam_date']) ?>
                                            <?php if ($r['slot_time']): ?> <?= format_time($r['slot_time']) ?><?php endif; ?>
                                            · <?= e($r['room_label'] ?: 'room') ?>
                                            (<?= $left ?> open)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" id="cancel_reason_<?= (int)$s['id'] ?>" name="reason" required maxlength="500" aria-label="Reason"
                                       placeholder="Reason shown to students (e.g. Typhoon Pepito)"
                                       class="form-control"
                                       style="font-size:var(--text-xs);height:var(--control-height);min-height:var(--control-height);padding:0 var(--space-2)">
                                <button type="submit" class="btn btn-sm btn-primary"
                                        style="height:var(--control-height);min-height:var(--control-height);padding:0 var(--space-3);font-size:var(--text-xs)">
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
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Cancel Exam Slot (Bulk)';
$pageBreadcrumb = [['label' => 'Exam Slots', 'to' => '/staff/exam/slots']];
$activeNav = 'exam';
$pageWide  = true; // table-heavy page - match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';