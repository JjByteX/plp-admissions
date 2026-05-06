<?php
// ============================================================
// modules/exam/staff_slots.php
// Phase 1: Rooms-and-slots calendar for the entrance exam.
// Staff defines exam-day slots (date · time · room · capacity)
// and manually drops eligible applicants into them.
// Phase 2 will add auto-assignment by docs_approved_at FCFS.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$errors  = [];
$success = [];

// Auto-add department column if missing (graceful upgrade)
try {
    $db->query("SELECT department FROM exam_slot_schedule LIMIT 0");
} catch (\Throwable $e) {
    $db->exec("ALTER TABLE exam_slot_schedule ADD COLUMN department VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'College/department this slot is for' AFTER room_label");
}

$schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$activeExam = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch();
$activeExamId = $activeExam ? (int)$activeExam['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Add a new exam slot (room reservation on a date/time) ───
    // ── Update exam config (room default + daily cap) ────────────
    if ($action === 'update_exam_config') {
        $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        $roomCap  = max(1, (int)($_POST['exam_room_capacity'] ?? 35));
        $dailyCap = max(1, (int)($_POST['exam_daily_cap']     ?? 3000));
        $db->prepare($upsert)->execute(['exam_room_capacity', $roomCap]);
        $db->prepare($upsert)->execute(['exam_daily_cap',     $dailyCap]);
        audit_log('exam_config_updated', "Room cap={$roomCap}, Daily cap={$dailyCap}");
        $success[] = 'Exam config saved.';
    }

    if ($action === 'add_slot') {
        $date     = trim($_POST['exam_date']  ?? '');
        $time     = trim($_POST['slot_time']  ?? '08:00');
        $room     = trim($_POST['room_label'] ?? '');
        $capacity = (int)($_POST['capacity']  ?? 35);
        $slotDept = $isAdmin
            ? trim($_POST['department'] ?? '')
            : user_department($staffId);

        if (!$date)              $errors[] = 'Exam date is required.';
        if (!$room)              $errors[] = 'Room label is required.';
        if (!$slotDept)          $errors[] = 'College / Department is required.';
        if ($capacity < 1)       $errors[] = 'Capacity must be at least 1.';
        if ($capacity > 500)     $errors[] = 'Capacity above 500 is unrealistic.';

        if (!$errors) {
            $db->prepare(
                'INSERT INTO exam_slot_schedule
                    (exam_id, exam_date, slot_time, room_label, department, capacity, school_year, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$activeExamId, $date, $time . ':00', $room, $slotDept, $capacity, $schoolYear, $staffId]);
            audit_log('exam_slot_added',
                "Added slot: {$date} {$time} {$room} [{$slotDept}] (cap {$capacity})");
            $success[] = "Slot added: {$room} ({$slotDept}) on " . date('M j, Y', strtotime($date)) . " at {$time}.";
        }
    }

    // ── Edit an existing slot ───────────────────────────────────
    if ($action === 'edit_slot') {
        $slotId   = (int)($_POST['slot_id']   ?? 0);
        $date     = trim($_POST['exam_date']  ?? '');
        $time     = trim($_POST['slot_time']  ?? '08:00');
        $room     = trim($_POST['room_label'] ?? '');
        $capacity = (int)($_POST['capacity']  ?? 35);
        $slotDept = $isAdmin
            ? trim($_POST['department'] ?? '')
            : user_department($staffId);

        if (!$slotId)          $errors[] = 'Invalid slot.';
        if (!$date)            $errors[] = 'Exam date is required.';
        if (!$room)            $errors[] = 'Room label is required.';
        if (!$slotDept)        $errors[] = 'College / Department is required.';
        if ($capacity < 1)     $errors[] = 'Capacity must be at least 1.';
        if ($capacity > 500)   $errors[] = 'Capacity above 500 is unrealistic.';

        if (!$errors) {
            // Don't allow shrinking below current fill
            $stmt = $db->prepare('SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?');
            $stmt->execute([$slotId]);
            $filled = (int)$stmt->fetchColumn();
            if ($capacity < $filled) {
                $errors[] = "Cannot shrink capacity below {$filled} (currently filled).";
            } else {
                $db->prepare(
                    'UPDATE exam_slot_schedule
                        SET exam_date=?, slot_time=?, room_label=?, department=?, capacity=?
                      WHERE id=?'
                )->execute([$date, $time . ':00', $room, $slotDept, $capacity, $slotId]);
                audit_log('exam_slot_edited',
                    "Edited slot {$slotId}: {$date} {$time} {$room} [{$slotDept}] (cap {$capacity})");
                $success[] = 'Slot updated.';
            }
        }
    }

    // ── Edit slot capacity inline ───────────────────────────────
    if ($action === 'edit_capacity') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $cap    = max(1, (int)($_POST['capacity'] ?? 35));
        if ($slotId) {
            // Don't allow shrinking below current fill
            $stmt = $db->prepare(
                'SELECT (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?) AS filled'
            );
            $stmt->execute([$slotId]);
            $filled = (int)$stmt->fetchColumn();
            if ($cap < $filled) {
                $errors[] = "Cannot shrink capacity below {$filled} (currently filled).";
            } else {
                $db->prepare('UPDATE exam_slot_schedule SET capacity=? WHERE id=?')
                   ->execute([$cap, $slotId]);
                audit_log('exam_slot_capacity_changed', "Slot {$slotId} capacity → {$cap}");
                $success[] = 'Capacity updated.';
            }
        }
    }

    // ── Delete a slot (only if empty) ───────────────────────────
    if ($action === 'delete_slot') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        if ($slotId) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?');
            $stmt->execute([$slotId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Cannot delete a slot that has applicants assigned. Unassign them first.';
            } else {
                $db->prepare('DELETE FROM exam_slot_schedule WHERE id=?')->execute([$slotId]);
                audit_log('exam_slot_deleted', "Deleted slot {$slotId}");
                $success[] = 'Slot deleted.';
            }
        }
    }

    // ── Assign applicant to slot ────────────────────────────────
    if ($action === 'assign') {
        $applicantId = (int)($_POST['applicant_id'] ?? 0);
        $slotId      = (int)($_POST['slot_id']      ?? 0);
        if (!$applicantId || !$slotId) {
            $errors[] = 'Applicant and slot are both required.';
        } else {
            // Validate slot has room
            $stmt = $db->prepare(
                'SELECT s.capacity, s.exam_date,
                        (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=s.id) AS filled
                   FROM exam_slot_schedule s WHERE s.id=?'
            );
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch();
            if (!$slot) {
                $errors[] = 'Slot not found.';
            } elseif ((int)$slot['filled'] >= (int)$slot['capacity']) {
                $errors[] = 'Slot is full.';
            } else {
                // Enforce exam_daily_cap
                $dailyCap = (int) school_setting('exam_daily_cap', defined('EXAM_DAILY_CAP') ? EXAM_DAILY_CAP : 3000);
                $stmt2 = $db->prepare(
                    'SELECT COUNT(*) FROM applicant_exam_slots aes
                       JOIN exam_slot_schedule s ON s.id = aes.slot_id
                      WHERE s.exam_date = ? AND s.school_year = ?'
                );
                $stmt2->execute([$slot['exam_date'], $schoolYear]);
                $dayCount = (int)$stmt2->fetchColumn();
                if ($dayCount >= $dailyCap) {
                    $errors[] = "Daily exam cap of {$dailyCap} reached for " . date('M j, Y', strtotime($slot['exam_date'])) . ". Add more slots on another day or increase the daily cap in Exam Config.";
                } else {
                // Validate applicant is at exam stage
                $stmt = $db->prepare('SELECT overall_status FROM applicants WHERE id=?');
                $stmt->execute([$applicantId]);
                $st = $stmt->fetchColumn();
                if ($st !== 'exam') {
                    $errors[] = "Applicant is not at exam stage (currently: {$st}).";
                } else {
                    // Unique key on applicant_id means upsert moves them between slots
                    $db->prepare(
                        'INSERT INTO applicant_exam_slots (applicant_id, slot_id, assigned_at)
                         VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE slot_id=VALUES(slot_id), assigned_at=NOW()'
                    )->execute([$applicantId, $slotId]);
                    audit_log('exam_slot_assigned',
                        "Assigned applicant {$applicantId} → slot {$slotId}",
                        'applicant', $applicantId);
                    $success[] = 'Applicant assigned.';
                }
                } // end daily cap check
            }
        }
    }

    // ── B3: Batch create exam slots ────────────────────────────
    if ($action === 'batch_create_slots') {
        $bStartDate = trim($_POST['batch_start_date'] ?? '');
        $bEndDate   = trim($_POST['batch_end_date']   ?? '');
        $bTime      = trim($_POST['batch_time']       ?? '08:00');
        $bRooms     = $_POST['batch_rooms'] ?? [];
        if (!is_array($bRooms)) $bRooms = [$bRooms];
        $bRooms     = array_filter(array_map('trim', $bRooms));
        $bCapacity  = max(1, (int)($_POST['batch_capacity'] ?? $examRoomCap ?? 35));
        $bDept      = $isAdmin ? trim($_POST['batch_department'] ?? '') : user_department($staffId);
        $bDays      = array_map('intval', $_POST['batch_days'] ?? [1,2,3,4,5]);

        if (!$bStartDate || !$bEndDate) $errors[] = 'Start and end dates are required.';
        elseif ($bEndDate < $bStartDate) $errors[] = 'End date must be after start date.';
        elseif (empty($bRooms)) $errors[] = 'At least one room label is required.';
        elseif (!$bDept) $errors[] = 'Department is required.';
        else {
            $totalCreated = 0;
            foreach ($bRooms as $bRoom) {
                $created = batch_create_exam_slots([
                    'start_date' => $bStartDate,
                    'end_date'   => $bEndDate,
                    'slot_time'  => $bTime,
                    'room_label' => $bRoom,
                    'capacity'   => $bCapacity,
                    'days'       => $bDays,
                ], $bDept, $staffId);
                $totalCreated += $created;
            }
            $success[] = "Created {$totalCreated} exam slot(s) in batch across " . count($bRooms) . " room(s).";
        }
    }

    // ── Unassign applicant from their slot ──────────────────────
    if ($action === 'unassign') {
        $applicantId = (int)($_POST['applicant_id'] ?? 0);
        if ($applicantId) {
            $db->prepare('DELETE FROM applicant_exam_slots WHERE applicant_id=?')
               ->execute([$applicantId]);
            audit_log('exam_slot_unassigned', "Unassigned applicant {$applicantId} from their slot",
                'applicant', $applicantId);
            $success[] = 'Applicant unassigned.';
        }
    }
}

// ── Load slots for current school year ──────────────────────────
$staffDept = $isAdmin ? '' : user_department($staffId);
if ($isAdmin) {
    $stmt = $db->prepare(
        "SELECT s.id, s.exam_date, s.slot_time, s.room_label, s.department, s.capacity,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=s.id) AS filled
           FROM exam_slot_schedule s
          WHERE s.school_year = ?
          ORDER BY s.exam_date ASC, s.slot_time ASC, s.department ASC, s.room_label ASC"
    );
    $stmt->execute([$schoolYear]);
} else {
    $stmt = $db->prepare(
        "SELECT s.id, s.exam_date, s.slot_time, s.room_label, s.department, s.capacity,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=s.id) AS filled
           FROM exam_slot_schedule s
          WHERE s.school_year = ? AND (s.department = ? OR s.department = '')
          ORDER BY s.exam_date ASC, s.slot_time ASC, s.room_label ASC"
    );
    $stmt->execute([$schoolYear, $staffDept]);
}
$slots = $stmt->fetchAll();

// ── Roster per slot (for expand) ────────────────────────────────
$rosterBySlot = [];
if ($slots) {
    $slotIds = array_column($slots, 'id');
    $in = implode(',', array_fill(0, count($slotIds), '?'));
    $stmt = $db->prepare(
        "SELECT aes.slot_id, aes.applicant_id, aes.assigned_at,
                u.name AS student_name, a.course_applied, a.applicant_type
           FROM applicant_exam_slots aes
           JOIN applicants a ON a.id = aes.applicant_id
           JOIN users u      ON u.id = a.user_id
          WHERE aes.slot_id IN ({$in})
          ORDER BY u.name ASC"
    );
    $stmt->execute($slotIds);
    foreach ($stmt->fetchAll() as $r) {
        $rosterBySlot[(int)$r['slot_id']][] = $r;
    }
}

// ── Eligible applicants not yet in any slot ─────────────────────
// FCFS: order by documents_approved_at (oldest first), then by id
$stmt = $db->prepare(
    "SELECT a.id, a.course_applied, a.applicant_type, a.documents_approved_at,
            u.name AS student_name
       FROM applicants a
       JOIN users u ON u.id = a.user_id
  LEFT JOIN applicant_exam_slots aes ON aes.applicant_id = a.id
      WHERE a.school_year   = ?
        AND a.overall_status = 'exam'
        AND aes.id IS NULL
   ORDER BY a.course_applied ASC, a.documents_approved_at IS NULL, a.documents_approved_at ASC, a.id ASC"
);
$stmt->execute([$schoolYear]);
$unassigned = $stmt->fetchAll();

// Default exam date for the add-slot form: tomorrow
$examRoomCap  = (int) school_setting('exam_room_capacity', defined('EXAM_ROOM_CAPACITY') ? EXAM_ROOM_CAPACITY : 35);
$examDailyCap = (int) school_setting('exam_daily_cap',     defined('EXAM_DAILY_CAP')     ? EXAM_DAILY_CAP     : 3000);

$defaultDate = $activeExam && !empty($activeExam['scheduled_start'])
    ? date('Y-m-d', strtotime($activeExam['scheduled_start']))
    : date('Y-m-d', strtotime('+1 day'));

ob_start();
?>

<div style="display:flex;align-items:center;margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/exam') ?>" class="btn btn-ghost btn-sm" style="margin-right:auto">← Back</a>
    <button class="btn btn-sm"
            onclick="document.getElementById('batch-exam-modal').style.display='flex'"
            style="font-size:var(--text-xs)">
        <?= icon('ic_fluent_calendar_add_24_regular', 14) ?>
        Batch Create
    </button>
    <button class="btn btn-ghost btn-sm"
            onclick="document.getElementById('exam-config-modal').style.display='flex'"
            style="font-size:var(--text-xs);color:var(--text-secondary)">
        <?= icon('ic_fluent_settings_24_regular', 14) ?>
        Config
    </button>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<!-- ── Slots table ─────────────────────────────────────────── -->
<div class="card" style="padding:0;margin-bottom:var(--space-6);overflow:hidden">
    <div style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:var(--weight-semibold)">Scheduled Slots (<?= count($slots) ?>)</div>
        <?php
        $totalCap = array_sum(array_column($slots, 'capacity'));
        $totalFil = array_sum(array_column($slots, 'filled'));
        ?>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
            <?= $totalFil ?> / <?= $totalCap ?> seats filled
        </div>
    </div>
    <?php if (!$slots): ?>
        <div style="padding:var(--space-8);text-align:left;color:var(--text-tertiary);display:flex;flex-direction:column;align-items:center;gap:var(--space-4)">
            <span>No slots yet.</span>
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-slot-modal').style.display='flex'">
                + Add Slot
            </button>
        </div>
    <?php else: ?>
        <table class="data-table" style="margin:0;width:100%">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>College</th>
                    <th>Room</th>
                    <th style="text-align:center">Seats</th>
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($slots as $slot):
                $sid    = (int)$slot['id'];
                $filled = (int)$slot['filled'];
                $cap    = (int)$slot['capacity'];
                $isFull = $filled >= $cap;
                $isPast = strtotime($slot['exam_date']) < strtotime(date('Y-m-d'));
                $roster = $rosterBySlot[$sid] ?? [];
            ?>
                <tr data-slot-id="<?= $sid ?>" style="<?= $isPast ? 'opacity:.6' : '' ?>">
                    <td>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleRoster(<?= $sid ?>)" aria-expanded="false">
                            <span id="caret-<?= $sid ?>">▸</span>
                        </button>
                    </td>
                    <td><?= e(date('M j, Y', strtotime($slot['exam_date']))) ?></td>
                    <td><?= e(date('g:i A', strtotime($slot['slot_time']))) ?></td>
                    <td style="font-size:var(--text-xs)"><?= e($slot['department'] ?: '—') ?></td>
                    <td><?= e($slot['room_label']) ?></td>
                    <td style="text-align:center">
                        <span style="font-weight:var(--weight-semibold);<?= $isFull ? 'color:var(--error)' : '' ?>">
                            <?= $filled ?> / <?= $cap ?>
                        </span>
                        <?php if ($isFull): ?>
                            <span style="font-size:10px;padding:1px 6px;background:#fee2e2;color:#b91c1c;border-radius:9999px;margin-left:6px">Full</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$isPast): ?>
                            <button type="button"
                                    class="btn-icon"
                                    title="Edit slot"
                                    style="padding:var(--space-1)"
                                    onclick="openEditExamSlot(<?= $sid ?>, '<?= e($slot['exam_date']) ?>', '<?= e(substr($slot['slot_time'],0,5)) ?>', '<?= e(addslashes($slot['room_label'])) ?>', '<?= e(addslashes($slot['department'])) ?>', <?= $cap ?>)">
                                <?= icon('ic_fluent_edit_24_regular', 14) ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($filled === 0 && !$isPast): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this slot?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_slot">
                                <input type="hidden" name="slot_id" value="<?= $sid ?>">
                                <button type="submit" class="btn-icon" title="Delete slot" style="color:var(--error);padding:var(--space-1)"><?= icon('ic_fluent_delete_24_regular', 14) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="roster-<?= $sid ?>" style="display:none;background:var(--bg-subtle)">
                    <td></td>
                    <td colspan="6" style="padding:var(--space-3) var(--space-4)">
                        <?php if (!$roster): ?>
                            <div style="color:var(--text-tertiary);font-size:var(--text-sm);font-style:italic">No applicants assigned yet.</div>
                        <?php else: ?>
                            <div style="display:flex;flex-direction:column;gap:var(--space-1)">
                            <?php foreach ($roster as $r): ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:var(--space-1) 0;border-bottom:1px solid var(--border)">
                                    <div style="font-size:var(--text-sm)">
                                        <strong><?= e($r['student_name']) ?></strong>
                                        <span style="color:var(--text-tertiary);margin-left:var(--space-2)"><?= e($r['course_applied']) ?></span>
                                    </div>
                                    <?php if (!$isPast): ?>
                                        <form method="POST" style="margin:0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="unassign">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$r['applicant_id'] ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:var(--text-xs)">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="display:flex;justify-content:center;padding:var(--space-4)">
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-slot-modal').style.display='flex'">
                + Add Slot
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- ── Unassigned eligible applicants ───────────────────────── -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div>
            <div style="font-weight:var(--weight-semibold)">Awaiting Slot (<?= count($unassigned) ?>)</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                Documents approved. Earliest-approved first.
            </div>
        </div>
    </div>
    <?php if (!$unassigned): ?>
        <div style="padding:var(--space-8);text-align:left;color:var(--text-tertiary)">
            No applicants are waiting for a slot right now.
        </div>
    <?php else: ?>
        <table class="data-table" style="margin:0;width:100%">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Course</th>
                    <th>College</th>
                    <th>Type</th>
                    <th>Approved</th>
                    <th style="width:340px">Assign to Slot</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unassigned as $u):
                $applicantDept = course_to_department($u['course_applied']);
            ?>
                <tr>
                    <td><?= e($u['student_name']) ?></td>
                    <td style="font-size:var(--text-sm)"><?= e($u['course_applied']) ?></td>
                    <td style="font-size:var(--text-xs)"><?= e($applicantDept ?: '—') ?></td>
                    <td><span class="badge badge-neutral"><?= e(ucfirst($u['applicant_type'])) ?></span></td>
                    <td style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        <?= $u['documents_approved_at']
                            ? e(date('M j, g:i A', strtotime($u['documents_approved_at'])))
                            : '<em>—</em>' ?>
                    </td>
                    <td>
                        <form method="POST" style="display:flex;gap:var(--space-2);align-items:center;margin:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"       value="assign">
                            <input type="hidden" name="applicant_id" value="<?= (int)$u['id'] ?>">
                            <select name="slot_id" required class="form-input" style="flex:1;font-size:var(--text-xs);padding:4px 8px">
                                <option value="">— Choose slot —</option>
                                <?php foreach ($slots as $s):
                                    if ((int)$s['filled'] >= (int)$s['capacity']) continue;
                                    if (strtotime($s['exam_date']) < strtotime(date('Y-m-d'))) continue;
                                    // Only show slots matching the applicant's college
                                    if ($applicantDept && !empty($s['department']) && $s['department'] !== $applicantDept) continue;
                                ?>
                                    <option value="<?= (int)$s['id'] ?>">
                                        <?= e(date('M j', strtotime($s['exam_date']))) ?>
                                        · <?= e(date('g:i A', strtotime($s['slot_time']))) ?>
                                        · <?= e($s['room_label']) ?>
                                        (<?= (int)$s['filled'] ?>/<?= (int)$s['capacity'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm" style="font-size:var(--text-xs)">Assign</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
function toggleRoster(slotId) {
    var row   = document.getElementById('roster-' + slotId);
    var caret = document.getElementById('caret-' + slotId);
    if (!row) return;
    var open = row.style.display !== 'none';
    row.style.display = open ? 'none' : 'table-row';
    caret.textContent = open ? '▸' : '▾';
}
</script>

<!-- ── Edit Slot Modal ─────────────────────────────────────── -->
<div id="edit-slot-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Edit Slot</div>
            <button class="btn-icon" onclick="document.getElementById('edit-slot-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit_slot">
            <input type="hidden" name="slot_id" id="edit-slot-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">College / Department <span style="color:var(--error)">*</span></label>
                    <?php if ($isAdmin): ?>
                        <select name="department" id="edit-slot-dept" class="form-control" required>
                            <option value="">— Select college —</option>
                            <?php foreach (departments_list() as $dept): ?>
                                <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php $myDept = user_department($staffId); ?>
                        <input type="text" class="form-control" value="<?= e($myDept ?: 'Not assigned') ?>" disabled>
                        <input type="hidden" name="department" value="<?= e($myDept) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Exam Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="exam_date" id="edit-slot-date" class="form-control" required min="<?= date('Y-m-d') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" id="edit-slot-time" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                        <input type="number" name="capacity" id="edit-slot-cap" class="form-control" min="1" max="500" required>
                    </div>
                </div>
                <div>
                    <label class="form-label">Room Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="room_label" id="edit-slot-room" class="form-control" required maxlength="80">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('edit-slot-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Add Slot Modal ──────────────────────────────────────── -->
<div id="add-slot-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Add Slot</div>
            <button class="btn-icon" onclick="document.getElementById('add-slot-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_slot">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">College / Department <span style="color:var(--error)">*</span></label>
                    <?php if ($isAdmin): ?>
                        <select name="department" class="form-control" required>
                            <option value="">— Select college —</option>
                            <?php foreach (departments_list() as $dept): ?>
                                <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php $myDept = user_department($staffId); ?>
                        <input type="text" class="form-control" value="<?= e($myDept ?: 'Not assigned') ?>" disabled>
                        <input type="hidden" name="department" value="<?= e($myDept) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Exam Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="exam_date" class="form-control" required
                           value="<?= e($defaultDate) ?>" min="<?= date('Y-m-d') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" class="form-control" value="08:00" required>
                    </div>
                    <div>
                        <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                        <input type="number" name="capacity" class="form-control"
                               value="<?= (int) school_setting('exam_room_capacity', '35') ?>"
                               min="1" max="500" required>
                    </div>
                </div>
                <div>
                    <label class="form-label">Room Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="room_label" class="form-control" required
                           placeholder="e.g. Room 101 — Engineering Bldg, 2F" maxlength="80">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('add-slot-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Slot</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Exam Config Modal ─────────────────────────────────────── -->
<div id="exam-config-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Exam Config</div>
            <button class="btn-icon" onclick="document.getElementById('exam-config-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_exam_config">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Default Seats per Room</label>
                    <input type="number" name="exam_room_capacity" class="form-control"
                           value="<?= $examRoomCap ?>" min="1" max="200">
                </div>
                <div>
                    <label class="form-label">Max Applicants per Exam Day</label>
                    <input type="number" name="exam_daily_cap" class="form-control"
                           value="<?= $examDailyCap ?>" min="1" max="10000">
                </div>
                <?php $roomsNeeded = $examRoomCap > 0 ? ceil($examDailyCap / $examRoomCap) : '—'; ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);font-size:var(--text-xs);color:var(--text-secondary)">
                    <?= $examDailyCap ?> applicants &divide; <?= $examRoomCap ?>/room
                    = <strong>~<?= $roomsNeeded ?> rooms/day</strong>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('exam-config-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Config</button>
            </div>
        </form>
    </div>
</div>

<!-- B3: Batch Create Exam Slots Modal -->
<div id="batch-exam-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <div class="modal-title">Batch Create Exam Slots</div>
            <button class="btn-icon" onclick="document.getElementById('batch-exam-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="batch_create_slots">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="display:flex;gap:var(--space-3)">
                    <div style="flex:1">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="batch_start_date" class="form-control" value="<?= e($defaultDate) ?>" required>
                    </div>
                    <div style="flex:1">
                        <label class="form-label">End Date</label>
                        <input type="date" name="batch_end_date" class="form-control" value="<?= e(date('Y-m-d', strtotime($defaultDate . ' +6 days'))) ?>" required>
                    </div>
                </div>
                <div>
                    <label class="form-label">Time</label>
                    <input type="time" name="batch_time" class="form-control" value="08:00" required>
                </div>
                <div>
                    <label class="form-label">Rooms</label>
                    <div id="batch-rooms-list" style="display:flex;flex-direction:column;gap:var(--space-2)">
                        <input type="text" name="batch_rooms[]" class="form-control" placeholder="e.g. Room 101" required>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:var(--space-2);font-size:var(--text-xs)"
                            onclick="addBatchRoom()">+ Add another room</button>
                </div>
                <div>
                    <label class="form-label">College / Department</label>
                    <?php if ($isAdmin): ?>
                        <select name="batch_department" class="form-control" required>
                            <option value="">— Select college —</option>
                            <?php foreach (departments_list() as $dept): ?>
                                <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php $myDept = user_department($staffId); ?>
                        <input type="text" class="form-control" value="<?= e($myDept ?: 'Not assigned') ?>" disabled>
                        <input type="hidden" name="batch_department" value="<?= e($myDept) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Seats per Slot</label>
                    <input type="number" name="batch_capacity" class="form-control" value="<?= $examRoomCap ?>" min="1" max="500">
                </div>
                <div>
                    <label class="form-label">Days of Week</label>
                    <div style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
                        <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',0=>'Sun'] as $dv => $dl): ?>
                            <label style="display:flex;align-items:center;gap:4px;font-size:var(--text-sm)">
                                <input type="checkbox" name="batch_days[]" value="<?= $dv ?>" <?= $dv >= 1 && $dv <= 5 ? 'checked' : '' ?>>
                                <?= $dl ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('batch-exam-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Slots</button>
            </div>
        </form>
    </div>
</div>

<script>
['add-slot-modal','exam-config-modal','edit-slot-modal','batch-exam-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});

function addBatchRoom() {
    var list = document.getElementById('batch-rooms-list');
    var wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;gap:var(--space-2);align-items:center';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'batch_rooms[]';
    input.className = 'form-control';
    input.placeholder = 'e.g. Room 102';
    input.required = true;
    input.style.flex = '1';
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-icon';
    removeBtn.title = 'Remove';
    removeBtn.style.cssText = 'color:var(--error);padding:var(--space-1);flex-shrink:0';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function() { wrapper.remove(); };
    wrapper.appendChild(input);
    wrapper.appendChild(removeBtn);
    list.appendChild(wrapper);
    input.focus();
}

function openEditExamSlot(id, date, time, room, dept, cap) {
    document.getElementById('edit-slot-id').value   = id;
    document.getElementById('edit-slot-date').value = date;
    document.getElementById('edit-slot-time').value = time;
    document.getElementById('edit-slot-room').value = room;
    document.getElementById('edit-slot-cap').value  = cap;
    var deptSel = document.getElementById('edit-slot-dept');
    if (deptSel) deptSel.value = dept;
    document.getElementById('edit-slot-modal').style.display = 'flex';
}
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Exam Room Slots';
$activeNav = 'exam';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
