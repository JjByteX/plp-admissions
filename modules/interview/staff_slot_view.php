<?php
// ============================================================
// modules/interview/staff_slot_view.php
//
// Slot detail page — shows the students assigned to this slot
// and lets staff mark attendance + record Pass/Fail evaluations
// in a single submission.
//
// URL:
//   GET  /staff/interviews/{id}/roster
//   POST /staff/interviews/{id}/roster   (action=save_evaluations)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::user()['role'] ?? '';
$slotId  = (int)($_GET['id'] ?? 0);

if ($slotId <= 0) { redirect('/staff/interviews'); }

// ----------------------------------------------------------------
// Load slot + ownership check
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT s.*, u.name AS staff_name, u.desk_label
       FROM interview_slots s
       JOIN users u ON u.id = s.created_by
      WHERE s.id = ?
      LIMIT 1'
);
$stmt->execute([$slotId]);
$slot = $stmt->fetch();
if (!$slot) {
    Session::flash('error', 'Slot not found.');
    redirect('/staff/interviews');
}

$isAdmin    = ($role === ROLE_ADMIN);
$isOwner    = ((int)$slot['created_by'] === $staffId);
if (!$isOwner && !$isAdmin) {
    Session::flash('error', 'You can only view rosters for your own sessions.');
    redirect('/staff/interviews');
}

$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST — save evaluations
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_evaluations') {
        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows)) { $rows = []; }

        // Validate first, so we don't leave the batch half-saved.
        $toSave = [];
        foreach ($rows as $queueIdStr => $rowData) {
            $queueId = (int)$queueIdStr;
            if ($queueId <= 0) continue;

            $absent = !empty($rowData['absent']);
            $result = isset($rowData['result']) ? (string)$rowData['result'] : '';
            $result = strtolower(trim($result));

            if (!$absent) {
                if ($result !== 'pass' && $result !== 'fail') {
                    $errors[] = 'Every present student needs a Pass/Fail evaluation.';
                    $toSave = [];
                    break;
                }
            } else {
                $result = '';
            }

            $toSave[] = [
                'queue_id' => $queueId,
                'absent'   => $absent,
                'result'   => $result ?: null,
            ];
        }

        if (empty($errors) && !empty($toSave)) {
            $saved = 0;
            foreach ($toSave as $item) {
                $ok = record_interview_evaluation(
                    $item['queue_id'],
                    (bool)$item['absent'],
                    $item['result'],
                    $staffId
                );
                if ($ok) $saved++;
            }
            if ($saved > 0) {
                Session::flash('success', "Saved attendance + evaluation for {$saved} student(s).");
            } else {
                Session::flash('error', 'No evaluations were saved.');
            }
            redirect('/staff/interviews/' . $slotId . '/roster');
        }
        if (empty($errors) && empty($toSave)) {
            $errors[] = 'Nothing to save — no students on this slot.';
        }
    }
}

// ----------------------------------------------------------------
// Roster — everyone assigned to this slot
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT q.id          AS queue_id,
            q.status,
            q.attendance_status,
            q.evaluation_result,
            q.interview_status,
            q.evaluated_at,
            a.id           AS applicant_id,
            a.course_applied,
            u.name         AS student_name,
            u.email        AS student_email,
            u.department   AS student_department
       FROM interview_queue q
       JOIN applicants a ON a.id = q.applicant_id
       JOIN users u      ON u.id = a.user_id
      WHERE q.slot_id = ?
      ORDER BY u.name ASC'
);
$stmt->execute([$slotId]);
$roster = $stmt->fetchAll();

// ----------------------------------------------------------------
// Render
// ----------------------------------------------------------------
$timeLabel = 'All day';
if ($slot['slot_time']) {
    $timeLabel = format_time($slot['slot_time']);
    if ($slot['end_time']) {
        $timeLabel .= ' – ' . format_time($slot['end_time']);
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

<!-- Breadcrumb -->
<div style="margin-bottom:var(--space-4);font-size:var(--text-sm)">
    <a href="<?= url('/staff/interviews') ?>" style="color:var(--text-secondary)">
        ← Back to sessions
    </a>
</div>

<!-- Slot summary -->
<div class="card" style="padding:var(--space-5);margin-bottom:var(--space-5)">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-3)">
        <div>
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg);margin-bottom:2px">
                <?= format_date($slot['slot_date'], 'l, F j, Y') ?> &nbsp;·&nbsp; <?= e($timeLabel) ?>
            </div>
            <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                <?= e($slot['department'] ?: 'Any department') ?>
                &nbsp;·&nbsp; Staff: <?= e($slot['staff_name']) ?>
                <?php if ($slot['desk_label']): ?>
                    &nbsp;·&nbsp; <?= e($slot['desk_label']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:var(--space-2)">
            <span class="badge <?= $slot['status'] === 'open' ? 'badge-approved' : 'badge-neutral' ?>">
                <?= e(ucfirst($slot['status'])) ?>
            </span>
            <span class="badge badge-neutral">
                <?= count($roster) ?> / <?= (int)$slot['capacity'] ?> booked
            </span>
        </div>
    </div>
</div>

<?php if (empty($roster)): ?>
    <div class="card" style="padding:var(--space-8);text-align:center;color:var(--text-tertiary)">
        No students have been assigned to this slot yet.
    </div>
<?php else: ?>
    <form method="POST" id="eval-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_evaluations">

        <div class="card" style="padding:0;overflow:hidden">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:var(--bg-subtle);text-align:left;font-size:var(--text-xs);
                                color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em">
                        <th style="padding:var(--space-3) var(--space-4)">Student</th>
                        <th style="padding:var(--space-3) var(--space-4)">Course</th>
                        <th style="padding:var(--space-3) var(--space-4)">Department</th>
                        <th style="padding:var(--space-3) var(--space-4);text-align:center">Absent</th>
                        <th style="padding:var(--space-3) var(--space-4)">Evaluation</th>
                        <th style="padding:var(--space-3) var(--space-4)">Current status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $row):
                    $isLocked = in_array($row['interview_status'], ['completed','absent','rescheduled'], true);
                    $absentChecked = ($row['attendance_status'] ?? '') === 'absent';
                    $evalPass      = ($row['evaluation_result'] ?? '') === 'pass';
                    $evalFail      = ($row['evaluation_result'] ?? '') === 'fail';
                ?>
                    <tr data-queue-id="<?= (int)$row['queue_id'] ?>" style="border-top:1px solid var(--border);font-size:var(--text-sm)">
                        <td style="padding:var(--space-3) var(--space-4)">
                            <div style="font-weight:var(--weight-medium)"><?= e($row['student_name']) ?></div>
                            <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                <?= e($row['student_email']) ?>
                            </div>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?= e($row['course_applied'] ?: '—') ?>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?= e($row['student_department'] ?: '—') ?>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4);text-align:center">
                            <label style="display:inline-flex;align-items:center;cursor:<?= $isLocked ? 'default' : 'pointer' ?>">
                                <input type="checkbox"
                                       name="rows[<?= (int)$row['queue_id'] ?>][absent]"
                                       value="1"
                                       class="js-absent-toggle"
                                       <?= $absentChecked ? 'checked' : '' ?>
                                       <?= $isLocked ? 'disabled' : '' ?>>
                            </label>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <div style="display:flex;gap:var(--space-3)">
                                <label style="display:inline-flex;align-items:center;gap:var(--space-1);cursor:pointer">
                                    <input type="radio"
                                           name="rows[<?= (int)$row['queue_id'] ?>][result]"
                                           value="pass"
                                           class="js-result-radio"
                                           <?= $evalPass ? 'checked' : '' ?>
                                           <?= ($isLocked || $absentChecked) ? 'disabled' : '' ?>>
                                    Pass
                                </label>
                                <label style="display:inline-flex;align-items:center;gap:var(--space-1);cursor:pointer">
                                    <input type="radio"
                                           name="rows[<?= (int)$row['queue_id'] ?>][result]"
                                           value="fail"
                                           class="js-result-radio"
                                           <?= $evalFail ? 'checked' : '' ?>
                                           <?= ($isLocked || $absentChecked) ? 'disabled' : '' ?>>
                                    Fail
                                </label>
                            </div>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?php if ($row['interview_status'] === 'completed'): ?>
                                <span class="badge badge-approved">Completed</span>
                                <?php if ($row['evaluation_result']): ?>
                                    <span style="margin-left:var(--space-1);font-size:var(--text-xs);color:var(--text-tertiary)">
                                        (<?= e(ucfirst($row['evaluation_result'])) ?>)
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($row['interview_status'] === 'absent'): ?>
                                <span class="badge badge-rejected">Absent</span>
                            <?php elseif ($row['interview_status'] === 'rescheduled'): ?>
                                <span class="badge badge-neutral">Rescheduled</span>
                            <?php else: ?>
                                <span class="badge badge-review">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:var(--space-4);gap:var(--space-2)">
            <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save evaluations</button>
        </div>
    </form>

    <script>
        // When the "Absent" checkbox is toggled on:
        //   - uncheck + disable the Pass/Fail radios on that row
        // When toggled off:
        //   - re-enable the radios (staff will still need to pick one)
        document.querySelectorAll('.js-absent-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                const row = cb.closest('tr');
                row.querySelectorAll('.js-result-radio').forEach(function (r) {
                    if (cb.checked) {
                        r.checked = false;
                        r.disabled = true;
                    } else {
                        r.disabled = false;
                    }
                });
            });
        });

        // Client-side validation mirrors the server rule — every non-absent
        // student must have a Pass/Fail selected.
        document.getElementById('eval-form').addEventListener('submit', function (evt) {
            const missing = [];
            document.querySelectorAll('tr[data-queue-id]').forEach(function (tr) {
                const absent = tr.querySelector('.js-absent-toggle');
                if (absent && absent.checked) return;
                const radios = tr.querySelectorAll('.js-result-radio');
                const checked = Array.from(radios).some(function (r) { return r.checked; });
                if (!checked && radios.length > 0 && !radios[0].disabled) {
                    missing.push(tr.querySelector('td').innerText.trim());
                }
            });
            if (missing.length > 0) {
                evt.preventDefault();
                alert('Please select Pass or Fail for every present student:\n\n' + missing.join('\n'));
            }
        });
    </script>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Roster';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
