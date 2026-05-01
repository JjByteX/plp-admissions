<?php
// ============================================================
// modules/interview/student_view.php
//
// Students NO LONGER pick their own slot.  After passing the exam
// the applicant waits for staff to auto-assign them an interview
// slot (triggered whenever staff creates a new slot for their
// department).  The only POST action left on this page is the
// "I'm Here" check-in on the interview day.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();

$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/documents'); }
$applicantId = $applicant['id'];

// Guard: only students at interview stage (or beyond) should see this page.
// Failed exam students stay at 'exam' status (Fix #1) — send them back.
$allowedStatuses = ['interview', 'result', 'released'];
if (!in_array($applicant['overall_status'], $allowedStatuses, true)) {
    redirect('/student/documents');
}

// Load stepper dependencies
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_examResult = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT * FROM admission_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_admissionResult = $stmt->fetch() ?: null;

// ----------------------------------------------------------------
// Load student's current queue entry (if any)
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT q.*,
            s.slot_date,
            s.slot_time,
            s.end_time,
            s.capacity,
            s.department AS slot_department,
            u.name       AS staff_name,
            u.desk_label,
            u.desk_notes
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   users u           ON u.id = s.created_by
     WHERE  q.applicant_id = ?
     ORDER BY q.id DESC
     LIMIT 1'
);
$stmt->execute([$applicantId]);
$myEntry = $stmt->fetch() ?: null;

$stepperCurrent = current_step($applicant, $_examResult, $myEntry, $_admissionResult);

$errors = [];
$today  = date('Y-m-d');

// ----------------------------------------------------------------
// POST — only "I'm Here" check-in is allowed on this page now.
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'checkin') {
        if (!$myEntry
            || $myEntry['slot_date'] !== $today
            || $myEntry['status'] !== 'scheduled'
            || ($myEntry['interview_status'] ?? 'pending') !== 'pending') {
            redirect('/student/interview');
        }

        $db->beginTransaction();
        try {
            // Atomic: next queue number for this staff's slots today
            $stmt = $db->prepare(
                'SELECT COALESCE(MAX(q.queue_number), 0) + 1
                 FROM   interview_queue q
                 JOIN   interview_slots s ON s.id = q.slot_id
                 WHERE  s.slot_date = ? AND s.created_by = (
                     SELECT created_by FROM interview_slots WHERE id = ?
                 )
                 AND q.queue_number IS NOT NULL'
            );
            $stmt->execute([$today, $myEntry['slot_id']]);
            $nextNum = (int)$stmt->fetchColumn();

            $db->prepare(
                'UPDATE interview_queue
                 SET    status        = "checked_in",
                        queue_number  = ?,
                        checked_in_at = NOW()
                 WHERE  id = ? AND status = "scheduled"'
            )->execute([$nextNum, $myEntry['id']]);

            $db->commit();
            Session::flash('success', 'You are now in the queue!');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('student check-in failed: ' . $e->getMessage());
            $errors[] = 'Check-in failed. Please try again.';
        }

        // Reload entry
        $stmt = $db->prepare(
            'SELECT q.*,
                    s.slot_date, s.slot_time, s.end_time, s.capacity,
                    s.department AS slot_department,
                    u.name AS staff_name, u.desk_label, u.desk_notes
             FROM   interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             JOIN   users u           ON u.id = s.created_by
             WHERE  q.applicant_id = ?
             ORDER BY q.id DESC
             LIMIT 1'
        );
        $stmt->execute([$applicantId]);
        $myEntry = $stmt->fetch() ?: null;
    }
}

// ----------------------------------------------------------------
// Student department — used for the waiting message.
// ----------------------------------------------------------------
$studentDept = user_department($userId)
    ?: course_to_department($applicant['course_applied']);

// Queue position (how many checked_in ahead of this student)
$queuePosition = null;
if ($myEntry && $myEntry['status'] === 'checked_in') {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         WHERE  s.slot_date = ? AND s.created_by = (
             SELECT created_by FROM interview_slots WHERE id = ?
         )
         AND q.status = "checked_in"
         AND q.queue_number < ?'
    );
    $stmt->execute([$today, $myEntry['slot_id'], $myEntry['queue_number']]);
    $ahead         = (int)$stmt->fetchColumn();
    $queuePosition = $ahead + 1;
}

// Student is eligible for interview only if they actually passed the exam.
// A result row with passed=0 means they failed and should not see interview content.
$eligibleForInterview = $_examResult && !empty($_examResult['passed']);

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$eligibleForInterview): ?>
    <!-- ============================================================
         Student still needs to finish earlier steps (docs / exam).
    ============================================================ -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-2)">
            Interview not yet available
        </div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary)">
            You'll be able to see your interview schedule after you've
            completed the documents and exam steps.
        </div>
    </div>

<?php elseif ($myEntry): ?>

    <?php
        $slotIsToday      = ($myEntry['slot_date'] === $today);
        $interviewStatus  = $myEntry['interview_status'] ?? 'pending';
        $evaluationResult = $myEntry['evaluation_result'] ?? null;
    ?>

    <?php if ($interviewStatus === 'completed'): ?>
        <!-- ============================================================
             COMPLETED — evaluated by staff
        ============================================================ -->
        <div class="card" style="padding:var(--space-6)">
            <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div style="width:48px;height:48px;border-radius:var(--radius-lg);
                             background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 22, 'color:var(--success)') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold)">Interview Completed</div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        Your interview has been recorded
                    </div>
                </div>
                <span class="badge badge-neutral" style="margin-left:auto">Completed</span>
            </div>
            <div class="alert alert-info" style="margin-top:var(--space-2)">
                Your results will be released by the admissions office. You will be notified once available.
            </div>
        </div>

    <?php elseif ($interviewStatus === 'absent'): ?>
        <!-- ============================================================
             ABSENT — staff will reschedule
        ============================================================ -->
        <div class="card" style="padding:var(--space-6)">
            <div class="alert alert-error" style="margin-bottom:var(--space-4)">
                You were marked as absent for your scheduled interview on
                <strong><?= format_date($myEntry['slot_date']) ?></strong>.
            </div>
            <div style="font-size:var(--text-sm);color:var(--text-secondary)">
                The admissions office will reschedule your interview shortly.
                Please check back on this page — a new slot will appear
                automatically once it's assigned.
            </div>
        </div>

    <?php elseif (in_array($myEntry['status'], ['checked_in', 'in_progress'], true)): ?>
        <!-- ============================================================
             CHECKED IN STATE — queue number + desk instructions
        ============================================================ -->
        <div class="card" style="padding:var(--space-6);text-align:center;margin-bottom:var(--space-4)">

            <div style="display:inline-flex;flex-direction:column;align-items:center;
                        background:var(--accent);color:#fff;border-radius:var(--radius-xl);
                        padding:var(--space-6) var(--space-10);margin-bottom:var(--space-5)">
                <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.1em;
                             opacity:.85;font-weight:var(--weight-medium);margin-bottom:var(--space-1)">
                    Queue Number
                </div>
                <div style="font-size:3.5rem;font-weight:var(--weight-semibold);line-height:1">
                    <?= e($myEntry['queue_number']) ?>
                </div>
            </div>

            <?php if ($myEntry['status'] === 'in_progress'): ?>
                <div class="badge badge-info"
                     style="font-size:var(--text-sm);padding:var(--space-2) var(--space-4);margin-bottom:var(--space-4)">
                    Your interview is now in progress
                </div>
            <?php else: ?>
                <?php if ($queuePosition !== null): ?>
                    <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-4)">
                        <?php if ($queuePosition === 1): ?>
                            You are <strong>next</strong> — please be ready!
                        <?php else: ?>
                            There <?= $queuePosition - 1 === 1 ? 'is' : 'are' ?>
                            <strong><?= $queuePosition - 1 ?></strong>
                            applicant<?= $queuePosition - 1 !== 1 ? 's' : '' ?> ahead of you.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Desk instructions -->
            <?php if ($myEntry['desk_label']): ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                             padding:var(--space-4) var(--space-5);text-align:left;margin-bottom:var(--space-4)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                                 color:var(--text-tertiary);margin-bottom:var(--space-2)">Proceed to</div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg);
                                 margin-bottom:<?= $myEntry['desk_notes'] ? 'var(--space-2)' : '0' ?>">
                        <?= e($myEntry['desk_label']) ?>
                    </div>
                    <?php if ($myEntry['desk_notes']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);white-space:pre-line">
                            <?= e($myEntry['desk_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                Checked in at <?= date('g:i A', strtotime($myEntry['checked_in_at'])) ?>
                &nbsp;·&nbsp;
                <?= format_date($myEntry['slot_date']) ?>
            </div>
        </div>

        <div class="alert alert-warning">
            <?= icon('ic_fluent_warning_24_regular', 16) ?>
            Please stay nearby and have your valid ID and application documents ready.
        </div>

        <script>setTimeout(function(){ window.location.reload(); }, 30000);</script>

    <?php elseif ($slotIsToday && $myEntry['status'] === 'scheduled'): ?>
        <!-- ============================================================
             TODAY — "I'm here" check-in
        ============================================================ -->
        <div class="card" style="padding:var(--space-6)">
            <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div style="width:48px;height:48px;border-radius:var(--radius-lg);
                             background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 22, 'color:var(--success)') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold)">Today is your Interview Day</div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?php if ($myEntry['slot_time']): ?>
                            <?= format_time($myEntry['slot_time']) ?><?= $myEntry['end_time'] ? ' – ' . format_time($myEntry['end_time']) : '' ?> &nbsp;·&nbsp;
                        <?php endif; ?>
                        <?= format_date($myEntry['slot_date']) ?>
                    </div>
                </div>
                <span class="badge badge-info" style="margin-left:auto">Scheduled</span>
            </div>

            <!-- Desk location — visible BEFORE check-in -->
            <?php if ($myEntry['desk_label']): ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                             padding:var(--space-4) var(--space-5);margin-bottom:var(--space-5)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                                 color:var(--text-tertiary);margin-bottom:var(--space-1)">
                        After check-in, proceed to
                    </div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">
                        <?= e($myEntry['desk_label']) ?>
                    </div>
                    <?php if ($myEntry['desk_notes']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);
                                     margin-top:var(--space-1);white-space:pre-line">
                            <?= e($myEntry['desk_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="checkin">
                <button type="submit" class="btn btn-primary"
                        style="width:100%;padding:var(--space-4);font-size:var(--text-lg)">
                    I'm Here
                </button>
            </form>

            <p style="font-size:var(--text-xs);color:var(--text-tertiary);text-align:center;margin-top:var(--space-3)">
                Tap this button when you arrive at the admissions area to receive your queue number.
            </p>
        </div>

    <?php else: ?>
        <!-- ============================================================
             FUTURE CONFIRMED BOOKING
        ============================================================ -->
        <div class="card" style="padding:var(--space-6)">
            <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div style="width:48px;height:48px;border-radius:var(--radius-lg);
                             background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 22, 'color:var(--success)') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold)">Interview Scheduled</div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?= format_date($myEntry['slot_date'], 'l, F j, Y') ?>
                        <?php if ($myEntry['slot_time']): ?>
                            &nbsp;·&nbsp;<?= format_time($myEntry['slot_time']) ?><?= $myEntry['end_time'] ? ' – ' . format_time($myEntry['end_time']) : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge badge-success" style="margin-left:auto">Confirmed</span>
            </div>

            <!-- Desk location — show even before interview day -->
            <?php if ($myEntry['desk_label']): ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                             padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                                 color:var(--text-tertiary);margin-bottom:var(--space-1)">Report to</div>
                    <div style="font-weight:var(--weight-semibold)"><?= e($myEntry['desk_label']) ?></div>
                    <?php if ($myEntry['desk_notes']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);
                                     margin-top:var(--space-1);white-space:pre-line">
                            <?= e($myEntry['desk_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <?= icon('ic_fluent_info_24_regular', 15) ?>
                <span>
                    Your interview slot was assigned by the admissions office.
                    On the day of your interview, return to this page and tap
                    <strong>"I'm Here"</strong> to join the queue.
                </span>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ============================================================
         WAITING — passed exam, no slot yet.  Staff will auto-assign
         as soon as a slot opens up for the student's department.
    ============================================================ -->
    <div class="card" style="padding:var(--space-6);text-align:center">
        <div style="margin-bottom:var(--space-3)">
            <?= icon('ic_fluent_hourglass_24_regular', 28, 'color:var(--text-tertiary)') ?>
        </div>
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-2)">
            Waiting for interview assignment
        </div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);max-width:480px;margin:0 auto">
            You've passed the exam. The admissions office will automatically
            assign you an interview slot
            <?php if ($studentDept !== ''): ?>
                for <strong><?= e($studentDept) ?></strong>
            <?php endif; ?>
            as soon as a session becomes available. You'll see the date
            and time here once it's booked — no action required.
        </div>
    </div>
<?php endif; ?>

<!-- Step navigation -->
<div class="step-nav">
    <a href="<?= url('/student/documents') ?>" class="btn btn-ghost">← Documents</a>
    <?php if ($myEntry && ($myEntry['interview_status'] ?? 'pending') === 'completed'): ?>
        <a href="<?= url('/student/result') ?>" class="btn btn-primary">My Result →</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = 'My Interview';
$activeNav   = 'interview';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
