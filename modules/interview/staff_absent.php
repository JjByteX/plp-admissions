<?php
// ============================================================
// modules/interview/staff_absent.php
//
// Dedicated "Absent Students" list.
// Lists every applicant whose most recent interview_queue row has
// interview_status='absent', with a multi-select form to reschedule
// them into any open slot matching their department.
//
// URL:
//   GET  /staff/interviews/absent
//   POST /staff/interviews/absent   (action=reschedule)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();

$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST — reschedule selected applicants
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'reschedule') {
        $applicantIds = $_POST['applicant_ids'] ?? [];
        if (!is_array($applicantIds)) { $applicantIds = []; }
        $applicantIds = array_values(array_unique(array_map('intval', $applicantIds)));
        $applicantIds = array_filter($applicantIds, fn($v) => $v > 0);

        $targetSlotId = (int)($_POST['target_slot_id'] ?? 0);

        if (empty($applicantIds)) {
            $errors[] = 'Please select at least one absent applicant to reschedule.';
        } else {
            $ok = 0;
            $fail = 0;
            foreach ($applicantIds as $aid) {
                try {
                    $newSlot = reschedule_absent_applicant(
                        $aid,
                        $targetSlotId > 0 ? $targetSlotId : null,
                        $staffId
                    );
                    if ($newSlot) $ok++; else $fail++;
                } catch (Throwable $e) {
                    error_log('reschedule_absent_applicant failed: ' . $e->getMessage());
                    $fail++;
                }
            }
            if ($ok > 0) {
                Session::flash('success',
                    "Rescheduled {$ok} applicant(s)."
                    . ($fail > 0 ? " {$fail} could not be rescheduled (no open slot)." : ''));
            } else {
                Session::flash('error',
                    'No applicants could be rescheduled — make sure there are open slots for their department.');
            }
            redirect('/staff/interviews/absent');
        }
    }
}

// ----------------------------------------------------------------
// Load absent applicants + their previous slot
// ----------------------------------------------------------------
$absent = $db->query(
    'SELECT q.id            AS queue_id,
            q.applicant_id,
            q.evaluated_at,
            s.id             AS slot_id,
            s.slot_date      AS missed_date,
            s.slot_time      AS missed_time,
            s.department     AS missed_department,
            a.course_applied,
            u.name           AS student_name,
            u.email          AS student_email,
            u.department     AS student_department
       FROM interview_queue q
       JOIN applicants a ON a.id = q.applicant_id
       JOIN users u      ON u.id = a.user_id
  LEFT JOIN interview_slots s ON s.id = q.slot_id
      WHERE q.interview_status = "absent"
      ORDER BY q.evaluated_at DESC, q.id DESC'
)->fetchAll();

// ----------------------------------------------------------------
// Load open slots (used as reschedule targets)
// ----------------------------------------------------------------
$today   = date('Y-m-d');
$nowTime = date('H:i:s');
$stmt = $db->prepare(
    'SELECT s.id, s.slot_date, s.slot_time, s.end_time, s.department, s.capacity,
            (SELECT COUNT(*) FROM interview_queue q
              WHERE q.slot_id = s.id
                AND q.interview_status IN ("pending","completed")) AS booked
       FROM interview_slots s
      WHERE s.status = "open"
        AND s.slot_date >= ?
        AND NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
      ORDER BY s.slot_date ASC, s.slot_time ASC'
);
$stmt->execute([$today, $today, $nowTime]);
$openSlots = array_filter(
    $stmt->fetchAll(),
    fn($s) => (int)$s['booked'] < (int)$s['capacity']
);

// ----------------------------------------------------------------
// Also load reschedule history per absent applicant (most recent 3)
// to surface "this is their 2nd miss" info for context.
// ----------------------------------------------------------------
$historyByApplicant = [];
if (!empty($absent)) {
    $ids  = array_map(fn($r) => (int)$r['applicant_id'], $absent);
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->prepare(
        "SELECT applicant_id, from_slot_date, from_slot_time, rescheduled_at
           FROM reschedule_logs
          WHERE applicant_id IN ($in)
          ORDER BY rescheduled_at DESC"
    );
    $rows->execute($ids);
    foreach ($rows->fetchAll() as $h) {
        $historyByApplicant[(int)$h['applicant_id']][] = $h;
    }
}

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
    <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:var(--radius-md);
                 overflow:hidden;background:var(--bg-elevated)">
        <a href="<?= url('/staff/interviews') ?>"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;border-right:1px solid var(--border);
                  color:var(--text-secondary)">
            Upcoming
        </a>
        <a href="<?= url('/staff/interviews') ?>?past=1"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;border-right:1px solid var(--border);
                  color:var(--text-secondary)">
            Past
        </a>
        <a href="<?= url('/staff/interviews/absent') ?>"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;
                  background:var(--bg-subtle);color:var(--text-primary);font-weight:var(--weight-medium)">
            Absent
        </a>
    </div>
</div>

<?php if (empty($absent)): ?>
    <div class="card" style="padding:var(--space-8);text-align:center;color:var(--text-tertiary)">
        No absent applicants right now.
    </div>
<?php else: ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reschedule">

        <div class="card" style="padding:0;overflow:hidden;margin-bottom:var(--space-4)">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:var(--bg-subtle);text-align:left;font-size:var(--text-xs);
                                color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em">
                        <th style="padding:var(--space-3) var(--space-4);width:36px">
                            <input type="checkbox" id="select-all">
                        </th>
                        <th style="padding:var(--space-3) var(--space-4)">Student</th>
                        <th style="padding:var(--space-3) var(--space-4)">Course / Dept</th>
                        <th style="padding:var(--space-3) var(--space-4)">Missed</th>
                        <th style="padding:var(--space-3) var(--space-4)">History</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($absent as $row):
                    $hist = $historyByApplicant[(int)$row['applicant_id']] ?? [];
                ?>
                    <tr style="border-top:1px solid var(--border);font-size:var(--text-sm)">
                        <td style="padding:var(--space-3) var(--space-4)">
                            <input type="checkbox"
                                   name="applicant_ids[]"
                                   value="<?= (int)$row['applicant_id'] ?>"
                                   class="js-select-row">
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <div style="font-weight:var(--weight-medium)"><?= e($row['student_name']) ?></div>
                            <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                <?= e($row['student_email']) ?>
                            </div>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <div><?= e($row['course_applied'] ?: '—') ?></div>
                            <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                <?= e($row['student_department'] ?: 'no department') ?>
                            </div>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?php if ($row['missed_date']): ?>
                                <?= format_date($row['missed_date']) ?>
                                <?php if ($row['missed_time']): ?>
                                    <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                        <?= format_time($row['missed_time']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?php if (empty($hist)): ?>
                                <span style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                    First miss
                                </span>
                            <?php else: ?>
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                    <?= count($hist) ?> previous reschedule<?= count($hist) === 1 ? '' : 's' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="padding:var(--space-4) var(--space-5);display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap">
            <label style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Reschedule target:</label>
            <select name="target_slot_id" class="form-control" style="max-width:420px">
                <option value="0">Auto-assign (earliest matching slot)</option>
                <?php foreach ($openSlots as $s):
                    $spotsLeft = (int)$s['capacity'] - (int)$s['booked'];
                ?>
                    <option value="<?= (int)$s['id'] ?>">
                        <?= format_date($s['slot_date']) ?>
                        <?php if ($s['slot_time']): ?>
                            at <?= format_time($s['slot_time']) ?>
                        <?php endif; ?>
                        &nbsp;·&nbsp; <?= e($s['department'] ?: 'any dept') ?>
                        (<?= $spotsLeft ?> spot<?= $spotsLeft !== 1 ? 's' : '' ?> left)
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="flex:1"></div>
            <button type="submit" class="btn btn-primary">Reschedule selected</button>
        </div>
        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">
            Choosing "Auto-assign" will pick the earliest slot that matches each applicant's department.
            Reschedules are recorded in <code>reschedule_logs</code> for audit.
        </p>
    </form>

    <script>
        const selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.js-select-row').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });
        }
    </script>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Absent Students';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
