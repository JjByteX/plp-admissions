<?php
// ============================================================
// modules/interview/student_view.php
//
// Students NO LONGER pick their own slot. After passing the exam
// the applicant waits for staff to auto-assign them an interview
// slot (triggered whenever staff creates a new slot for their
// department). The slot assignment also auto-checks them in, so
// there is no "I'm Here" button anymore — the page is read-only.
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

// Background safety-net: if the student has reached the interview stage
// but somehow doesn't have an interview_queue row yet (e.g. they passed
// the exam before any matching session existed, an earlier auto-assign
// returned null, or a staff member just created a fresh slot for their
// department), retry assignment every time they open this page.
// assign_interview_slot() is idempotent — it no-ops when an active
// queue row already exists.
//
// This is the same pattern modules/exam/take.php uses for exam slots,
// so the moment a slot exists in the student's department, refreshing
// /student/interview books them automatically without staff action.
if ($applicant['overall_status'] === 'interview'
    && function_exists('assign_interview_slot')) {
    try {
        $hasActive = $db->prepare(
            'SELECT id FROM interview_queue
              WHERE applicant_id = ?
                AND interview_status IN ("pending","completed")
              LIMIT 1'
        );
        $hasActive->execute([$applicantId]);
        if (!$hasActive->fetch()) {
            assign_interview_slot($applicantId, $userId);
        }
    } catch (\Throwable $e) {
        error_log('Interview slot self-heal on page visit failed: ' . $e->getMessage());
    }
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
// After the desk/session merge, location lives directly on each session row.
// The interviewer is whoever the session is assigned_to (with created_by as
// fallback for legacy rows).
$stmt = $db->prepare(
    'SELECT q.*,
            s.slot_date,
            s.slot_time,
            s.end_time,
            s.capacity,
            s.department                              AS slot_department,
            COALESCE(NULLIF(s.location_label, ""), u.desk_label) AS desk_label,
            COALESCE(s.location_notes, u.desk_notes)            AS desk_notes,
            COALESCE(au.name, cu.name)                AS staff_name
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   users           cu ON cu.id = s.created_by
     LEFT JOIN users        au ON au.id = s.assigned_to
     LEFT JOIN users        u  ON u.id  = COALESCE(s.assigned_to, s.created_by)
     WHERE  q.applicant_id = ?
     ORDER BY q.id DESC
     LIMIT 1'
);
$stmt->execute([$applicantId]);
$myEntry = $stmt->fetch() ?: null;

$stepperCurrent = current_step($applicant, $_examResult, $myEntry, $_admissionResult);

// ----------------------------------------------------------------
// Latest reschedule-request state for this applicant.
//
// This is what makes the student-side page change immediately after
// they submit "Need to reschedule?":
//   • pending  → show a banner, hide the form (no double submits)
//   • denied   → show a small note explaining staff kept the old slot
//   • approved → the queue row already points at the new slot, so the
//                normal "Interview Scheduled" view handles it; we just
//                surface a one-time confirmation banner.
// The table is created on-demand by the API endpoint, so we wrap the
// read in try/catch to stay safe on fresh installs.
// ----------------------------------------------------------------
$myReschedule    = null;
$rescheduleHistory = [];
try {
    // Defensive: join through applicants so a stray row with a
    // wrong applicant_id can NEVER leak across users. We require
    // (a) the request to belong to *this* user's applicant chain
    // and (b) it to belong to the same applicant row we just
    // resolved from the session. Both constraints together make
    // it impossible to render another student's request even if
    // legacy/bad data exists in reschedule_requests.
    $rrStmt = $db->prepare(
        'SELECT rr.id, rr.queue_id, rr.reason, rr.status,
                rr.created_at, rr.reviewed_at, rr.deny_reason
           FROM reschedule_requests rr
           JOIN applicants a ON a.id = rr.applicant_id
          WHERE a.user_id = ?
            AND rr.applicant_id = ?
          ORDER BY rr.id DESC
          LIMIT 5'
    );
    $rrStmt->execute([$userId, $applicantId]);
    $rescheduleHistory = $rrStmt->fetchAll() ?: [];
    $myReschedule      = $rescheduleHistory[0] ?? null;
} catch (\Throwable) {
    $myReschedule      = null;
    $rescheduleHistory = [];
}
$reschedulePending = $myReschedule && $myReschedule['status'] === 'pending';
$rescheduleDenied  = $myReschedule
    && $myReschedule['status'] === 'denied'
    && $myEntry
    && (int)$myReschedule['queue_id'] === (int)$myEntry['id'];
$rescheduleApproved = $myReschedule
    && $myReschedule['status'] === 'approved'
    && $myEntry
    && (int)$myReschedule['queue_id'] !== (int)$myEntry['id'];

$errors = [];
$today  = date('Y-m-d');

// This page no longer accepts POST. The previous "I'm Here" check-in is
// now performed automatically at slot assignment time — see
// core/interview_scheduler.php :: assign_interview_slot().

// ----------------------------------------------------------------
// Student department — used for the waiting message.
// ----------------------------------------------------------------
$studentDept = user_department($userId)
    ?: course_to_department($applicant['course_applied']);

// Queue position (how many checked_in ahead of this student) — scoped to
// this interviewer's queue for today, using assigned_to with created_by fallback.
$queuePosition = null;
if ($myEntry && $myEntry['status'] === 'checked_in') {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         WHERE  s.slot_date = ? AND COALESCE(s.assigned_to, s.created_by) = (
             SELECT COALESCE(assigned_to, created_by) FROM interview_slots WHERE id = ?
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

    <?php elseif (in_array($myEntry['status'], ['checked_in', 'in_progress'], true) && $slotIsToday): ?>
        <!-- ============================================================
             CHECKED IN STATE — queue number + desk instructions
             (auto-checked-in at assignment time; only show queue UI on
             the actual interview day)
        ============================================================ -->
        <div class="card" style="padding:var(--space-6);text-align:center;margin-bottom:var(--space-4)">

            <?php if ($myEntry['status'] === 'in_progress'): ?>
                <div class="badge badge-info"
                     style="font-size:var(--text-sm);padding:var(--space-2) var(--space-4);margin-bottom:var(--space-4)">
                    Your interview is now in progress
                </div>
            <?php else: ?>
                <div style="display:inline-flex;align-items:center;gap:var(--space-2);
                            background:var(--success-bg);color:var(--success);border-radius:var(--radius-md);
                            padding:var(--space-2) var(--space-4);margin-bottom:var(--space-4);font-weight:var(--weight-semibold)">
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
                    Checked In
                </div>
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

        <?php if ($myEntry['status'] !== 'in_progress'): ?>
        <div class="card" style="padding:var(--space-4);margin-top:var(--space-4)">
            <?php if ($reschedulePending): ?>
                <!-- The request is sitting in the SSO/Admin/Dean queue.
                     Hide the form so the student can't submit a duplicate. -->
                <div style="display:flex;align-items:center;gap:var(--space-3)">
                    <?= icon('ic_fluent_hourglass_24_regular', 18, 'color:var(--warning)') ?>
                    <div>
                        <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm)">
                            Reschedule request submitted
                        </div>
                        <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                            Submitted <?= date('M j, g:i A', strtotime($myReschedule['created_at'])) ?>
                            — awaiting staff review.
                        </div>
                    </div>
                </div>
                <?php if (!empty($myReschedule['reason'])): ?>
                    <div style="margin-top:var(--space-3);padding:var(--space-3) var(--space-4);background:var(--bg-subtle);
                                 border-radius:var(--radius-md);font-size:var(--text-sm);white-space:pre-line;color:var(--text-secondary)">
                        <?= e($myReschedule['reason']) ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($rescheduleDenied): ?>
                    <div class="alert alert-warning" style="margin-bottom:var(--space-3);font-size:var(--text-sm)">
                        Your previous reschedule request was not approved — you've kept your current slot.
                        <?php if (!empty($myReschedule['deny_reason'])): ?>
                            <div style="margin-top:var(--space-2);font-weight:var(--weight-medium)">
                                Reason from staff:
                                <span style="font-weight:var(--weight-regular);white-space:pre-line">
                                    <?= e($myReschedule['deny_reason']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:var(--space-2)">You may submit a new request below if needed.</div>
                    </div>
                <?php endif; ?>
                <details>
                    <summary style="cursor:pointer;font-size:var(--text-sm);color:var(--accent);font-weight:var(--weight-medium)">
                        Need to reschedule?
                    </summary>
                    <form method="POST" action="<?= url('/api/reschedule-request') ?>" style="margin-top:var(--space-3)">
                        <?= csrf_field() ?>
                        <textarea name="reschedule_reason" class="form-textarea" rows="3" placeholder="Why do you need to reschedule? (e.g. emergency)" required style="margin-bottom:var(--space-3)"></textarea>
                        <button type="submit" class="btn btn-ghost" style="width:100%">Submit Reschedule Request</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <script>setTimeout(function(){ window.location.reload(); }, 30000);</script>

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

            <!-- Reschedule request -->
            <?php if ($reschedulePending): ?>
                <!-- Pending review — the page has "changed" the moment they
                     submitted, so they see status instead of the form. -->
                <div style="margin-top:var(--space-4);padding:var(--space-4) var(--space-5);
                             background:var(--warning-bg);border-radius:var(--radius-md);
                             display:flex;align-items:flex-start;gap:var(--space-3)">
                    <?= icon('ic_fluent_hourglass_24_regular', 18, 'color:var(--warning)') ?>
                    <div style="flex:1">
                        <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:var(--warning)">
                            Reschedule request submitted
                        </div>
                        <div style="font-size:var(--text-xs);color:var(--text-secondary);margin-top:2px">
                            Submitted <?= date('M j, g:i A', strtotime($myReschedule['created_at'])) ?>
                            — awaiting staff review. You'll see a new slot here once it's approved.
                        </div>
                        <?php if (!empty($myReschedule['reason'])): ?>
                            <div style="margin-top:var(--space-2);font-size:var(--text-sm);color:var(--text-secondary);
                                         white-space:pre-line">
                                <?= e($myReschedule['reason']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php if ($rescheduleApproved): ?>
                    <div class="alert alert-success" style="margin-top:var(--space-4);font-size:var(--text-sm)">
                        Your reschedule request was approved — you've been moved to the slot shown above.
                    </div>
                <?php elseif ($rescheduleDenied): ?>
                    <div class="alert alert-warning" style="margin-top:var(--space-4);font-size:var(--text-sm)">
                        Your previous reschedule request was not approved — you've kept your current slot.
                        <?php if (!empty($myReschedule['deny_reason'])): ?>
                            <div style="margin-top:var(--space-2);font-weight:var(--weight-medium)">
                                Reason from staff:
                                <span style="font-weight:var(--weight-regular);white-space:pre-line">
                                    <?= e($myReschedule['deny_reason']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:var(--space-2)">You may submit a new request below if needed.</div>
                    </div>
                <?php endif; ?>
                <details style="margin-top:var(--space-4)">
                    <summary style="cursor:pointer;font-size:var(--text-sm);color:var(--accent);font-weight:var(--weight-medium)">
                        Need to reschedule?
                    </summary>
                    <form method="POST" action="<?= url('/api/reschedule-request') ?>" style="margin-top:var(--space-3)">
                        <?= csrf_field() ?>
                        <textarea name="reschedule_reason" class="form-textarea" rows="3" placeholder="Why do you need to reschedule?" required style="margin-bottom:var(--space-3)"></textarea>
                        <button type="submit" class="btn btn-ghost" style="width:100%">Submit Reschedule Request</button>
                    </form>
                </details>
            <?php endif; ?>

            <?php
                // Past reschedule requests — lets students see the full
                // history of their attempts (and the reason staff gave
                // when one was denied). Hidden by default to avoid
                // cluttering the card; click to expand.
                $pastReschedules = array_slice($rescheduleHistory, $reschedulePending ? 1 : 0);
            ?>
            <?php if (!empty($pastReschedules)): ?>
                <details style="margin-top:var(--space-4)">
                    <summary style="cursor:pointer;font-size:var(--text-xs);color:var(--text-tertiary)">
                        Past reschedule requests (<?= count($pastReschedules) ?>)
                    </summary>
                    <div style="margin-top:var(--space-3);display:flex;flex-direction:column;gap:var(--space-2)">
                        <?php foreach ($pastReschedules as $h): ?>
                            <div style="padding:var(--space-3);background:var(--bg-subtle);border-radius:var(--radius-md);
                                         font-size:var(--text-xs)">
                                <div style="display:flex;justify-content:space-between;gap:var(--space-3);align-items:flex-start">
                                    <div style="color:var(--text-tertiary)">
                                        <?= date('M j, Y · g:i A', strtotime($h['created_at'])) ?>
                                    </div>
                                    <?php
                                        $sLabel = $h['status'];
                                        $sClass = $sLabel === 'approved' ? 'badge-success'
                                                : ($sLabel === 'denied' ? 'badge-error' : 'badge-warning');
                                    ?>
                                    <span class="badge <?= $sClass ?>" style="font-size:10px">
                                        <?= e(ucfirst($sLabel)) ?>
                                    </span>
                                </div>
                                <?php if (!empty($h['reason'])): ?>
                                    <div style="margin-top:var(--space-2);color:var(--text-secondary);white-space:pre-line">
                                        <?= e($h['reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($h['status'] === 'denied' && !empty($h['deny_reason'])): ?>
                                    <div style="margin-top:var(--space-2);color:var(--text-tertiary);font-style:italic">
                                        Staff: <?= e($h['deny_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
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
    <?php
        // Only expose a real "My Result" link once the admissions office has
        // actually released a decision. Before that, show the same button in a
        // disabled state so the student sees what's coming next without
        // landing on an empty / "result not yet released" page.
        $resultReleased = !empty($_admissionResult);
        $interviewDone  = $myEntry && ($myEntry['interview_status'] ?? 'pending') === 'completed';
    ?>
    <?php if ($interviewDone && $resultReleased): ?>
        <a href="<?= url('/student/result') ?>" class="btn btn-primary">My Result →</a>
    <?php elseif ($interviewDone): ?>
        <button type="button" class="btn btn-primary" disabled
                title="Result not released yet. You'll be notified."
                aria-disabled="true"
                style="opacity:.55;cursor:not-allowed">
            Result not released yet
        </button>
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
