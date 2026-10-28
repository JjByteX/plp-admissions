<?php
// ============================================================
// modules/exam/take.php
// M4 — Student: take the entrance exam
// Google Forms-style rendering, selection prevention
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

// Finalize closed exams nobody submitted (one cheap EXISTS when there is no work).
finalize_closed_exam_drafts();

$db     = db();
$userId = Auth::id();
warn_if_db_timezone_off();

$applicant = require_applicant();
$applicantId = $applicant['id'];

$stepperCurrent = 'exam';

// Self-heal: if the applicant is still stuck in a pre-exam stage but all of
// their required documents are actually approved, advance them now. This
// covers cases where a document was rejected-then-replaced-then-approved
// but the auto-advance didn't fire (e.g. a race or a partial state).
if (in_array($applicant['overall_status'] ?? '', ['submitted', 'documents'], true)) {
    $requiredDocs = docs_for_type($applicant['applicant_type'] ?? '', $applicant['doc_flags'] ?? null);
    if (!empty($requiredDocs)) {
        $slugs = array_keys($requiredDocs);
        [$placeholders, $slugParams] = sql_in($slugs);
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM documents
              WHERE applicant_id = ?
                AND doc_type IN ($placeholders)
                AND status = 'approved'"
        );
        $stmt->execute(array_merge([$applicantId], $slugParams));
        $approvedCount = (int)$stmt->fetchColumn();

        if ($approvedCount === count($requiredDocs)) {
            $db->prepare(
                'UPDATE applicants
                    SET overall_status = \'exam\',
                        documents_approved_at = COALESCE(documents_approved_at, NOW())
                  WHERE id = ?
                    AND overall_status NOT IN (\'exam\',\'interview\',\'result\',\'released\',\'withdrawn\')'
            )->execute([$applicantId]);

            // Refresh the row so the rest of the file sees the new stage.
            $stmt = $db->prepare('SELECT * FROM applicants WHERE id = ?');
            $stmt->execute([$applicantId]);
            $applicant = $stmt->fetch() ?: $applicant;

            // Fire the same automation hooks the normal approve path uses.
            if (function_exists('notify_stage_transition')) notify_stage_transition($applicantId, 'exam');
            if (function_exists('auto_assign_exam_slot'))   auto_assign_exam_slot($applicantId);

            audit_log('applicant_advanced_exam_selfheal', "Self-healed advance to exam for applicant {$applicantId}", 'applicant', $applicantId);
        }
    }
}

if ($applicant['overall_status'] !== 'exam') {
    fail('You are not yet eligible to take the entrance exam.', '/student/documents');
}

// Background safety-net: if the student is at the exam stage but
// somehow doesn't have a slot row yet (e.g. they were advanced to
// exam before any matching slot existed, or an earlier auto-assign
// failed for any reason), try again every time they open this page.
// auto_assign_exam_slot is a no-op when a row already exists.
if (function_exists('auto_assign_exam_slot')) {
    $hasSlot = $db->prepare('SELECT id FROM applicant_exam_slots WHERE applicant_id = ? LIMIT 1');
    $hasSlot->execute([$applicantId]);
    if (!$hasSlot->fetch()) {
        auto_assign_exam_slot($applicantId);
    }
}

// Already submitted?
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$existing = $stmt->fetch();
if ($existing) {
    // Only send to interview if they actually passed; failed applicants stay on exam stage
    redirect($existing['passed'] ? '/student/interview' : '/student/documents');
}

// Fetch active exam — try multiple fallbacks so students with valid
// slot assignments are not blocked by a missing is_active flag.
$exam = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch();
if (!$exam) {
    // Fallback: exam linked to this student's assigned slot
    $slotExamStmt = $db->prepare(
        'SELECT e.* FROM applicant_exam_slots aes
         JOIN exam_slot_schedule s ON s.id = aes.slot_id
         JOIN exams e ON e.id = s.exam_id
         WHERE aes.applicant_id = ?
         LIMIT 1'
    );
    $slotExamStmt->execute([$applicantId]);
    $exam = $slotExamStmt->fetch();
}
if (!$exam) {
    // Fallback: most recent exam regardless of is_active
    $exam = $db->query('SELECT * FROM exams ORDER BY id DESC LIMIT 1')->fetch();
}
if (!$exam) {
    ob_start();
    echo '<div class="alert alert-warning">No active entrance exam has been set up yet. Please check back later.</div>';
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}
$examId = $exam['id'];

// ── Slot-aware gate ────────────────────────────────────────────────────────
// Look up the applicant's assigned exam slot. If they don't have one, they
// see a "waiting" screen. If their slot is in the future, they see a slot
// card. Only on their actual slot date do they reach the password gate.
//
// As of Chunk 7, the access password lives on the slot row (per-room),
// so we pull access_password and password_issued_at along with the
// scheduling fields.
// end_time is what slot_window() uses to compute the exam-duration
// timer. Omitting it makes slot_window() fall back to a hardcoded
// 90-minute window, which silently truncates the student's exam if
// they enter late or the room's actual window is longer than 90 min.
$slotStmt = $db->prepare(
    'SELECT s.id, s.exam_date, s.slot_time, s.end_time, s.room_label,
            s.access_password, s.password_issued_at
       FROM applicant_exam_slots aes
       JOIN exam_slot_schedule  s ON s.id = aes.slot_id
      WHERE aes.applicant_id = ?
      LIMIT 1'
);
$slotStmt->execute([$applicantId]);
$mySlot = $slotStmt->fetch();

// ----------------------------------------------------------------
// Latest EXAM reschedule-request state for this applicant.
//
// Drives the same UI pattern we use on the interview side: when the
// student submits "Need to reschedule?" the form is replaced with a
// pending banner so they cannot submit a duplicate, and they see a
// "denied" / "approved" note on the next page load.
// ----------------------------------------------------------------
$myExamReschedule        = null;
$examRescheduleHistory   = [];
try {
    // Defensive: join through applicants so a stray row with a
    // wrong applicant_id can NEVER leak across users. Same
    // pattern as modules/interview/student_view.php.
    $rrStmt = $db->prepare(
        'SELECT rr.id, rr.slot_id, rr.reason, rr.status,
                rr.created_at, rr.reviewed_at, rr.deny_reason
           FROM exam_reschedule_requests rr
           JOIN applicants a ON a.id = rr.applicant_id
          WHERE a.user_id = ?
            AND rr.applicant_id = ?
          ORDER BY rr.id DESC
          LIMIT 5'
    );
    $rrStmt->execute([$userId, $applicantId]);
    $examRescheduleHistory = $rrStmt->fetchAll() ?: [];
    $myExamReschedule      = $examRescheduleHistory[0] ?? null;
} catch (\Throwable) {
    $myExamReschedule      = null;
    $examRescheduleHistory = [];
}
$examReschedulePending  = $myExamReschedule && $myExamReschedule['status'] === 'pending';
$examRescheduleDenied   = $myExamReschedule
    && $myExamReschedule['status'] === 'denied'
    && $mySlot
    && (int)$myExamReschedule['slot_id'] === (int)$mySlot['id'];
$examRescheduleApproved = $myExamReschedule
    && $myExamReschedule['status'] === 'approved'
    && $mySlot
    && (int)$myExamReschedule['slot_id'] !== (int)$mySlot['id'];

if (!$mySlot) {
    ob_start();
    ?>
    <div style="max-width:480px;margin:var(--space-12) auto;text-align:center">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--bg-subtle);
                    display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-6)">
            <?= icon('clock:fill', 32, 'color:var(--text-secondary)') ?>
        </div>
        <h2 style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-2)">
            Awaiting Slot Assignment
        </h2>
        <p style="color:var(--text-secondary)">
            Your documents are approved. The admissions office will assign you to an exam date and room shortly. Check back here, or watch your email for a notification.
        </p>
    </div>
    <?php
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

$slotTs       = strtotime($mySlot['exam_date']);
$todayTs      = strtotime(date('Y-m-d'));
$isSlotToday  = $slotTs === $todayTs;
$isSlotFuture = $slotTs > $todayTs;
$isSlotPast   = $slotTs < $todayTs;
$slotDateNice = date('l, F j, Y', $slotTs);
$slotTimeNice = date('g:i A',     strtotime($mySlot['slot_time']));

if ($isSlotFuture) {
    ob_start();
    ?>
    <div style="max-width:520px;margin:var(--space-12) auto">
        <div class="card" style="padding:var(--space-6);text-align:center">
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;font-weight:var(--weight-semibold);margin-bottom:var(--space-2)">
                Your Exam is Scheduled
            </div>
            <div style="font-size:var(--text-3xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">
                <?= e($slotDateNice) ?>
            </div>
            <div style="font-size:var(--text-lg);color:var(--text-secondary);margin-bottom:var(--space-5)">
                <?= e($slotTimeNice) ?>
            </div>
            <div style="background:var(--bg-subtle);border-radius:var(--radius);padding:var(--space-4);margin-bottom:var(--space-5)">
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">Room</div>
                <div style="font-size:var(--text-lg);font-weight:var(--weight-medium)"><?= e($mySlot['room_label']) ?></div>
            </div>
            <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
                On exam day, log in here. The proctor in your room will announce the access code when the exam opens - you'll have 5 minutes to enter it to start.
            </p>
        </div>

        <?php if ($examReschedulePending): ?>
            <!-- Pending review — hide the form so the student can't
                 submit a duplicate. Page has "changed" the moment they
                 hit submit. -->
            <div style="margin-top:var(--space-4);padding:var(--space-4) var(--space-5);
                        background:var(--warning-bg);border-radius:var(--radius)">
                <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:var(--warning)">
                    Reschedule request submitted
                </div>
                <div style="font-size:var(--text-xs);color:var(--text-secondary);margin-top:2px">
                    Submitted <?= date('M j, g:i A', strtotime($myExamReschedule['created_at'])) ?>
                    - awaiting staff review. You'll see a new slot here once it's approved.
                </div>
                <?php if (!empty($myExamReschedule['reason'])): ?>
                    <div style="margin-top:var(--space-2);font-size:var(--text-sm);color:var(--text-secondary);
                                 white-space:pre-line">
                        <?= e($myExamReschedule['reason']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($examRescheduleApproved): ?>
                <div class="alert alert-success" style="margin-top:var(--space-4);font-size:var(--text-sm)">
                    Your reschedule request was approved - you've been moved to the slot shown above.
                </div>
            <?php elseif ($examRescheduleDenied): ?>
                <div class="alert alert-warning" style="margin-top:var(--space-4);font-size:var(--text-sm)">
                    Your previous reschedule request was not approved - you've kept your current slot.
                    <?php if (!empty($myExamReschedule['deny_reason'])): ?>
                        <div style="margin-top:var(--space-2);font-weight:var(--weight-medium)">
                            Reason from staff:
                            <span style="font-weight:var(--weight-regular);white-space:pre-line">
                                <?= e($myExamReschedule['deny_reason']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:var(--space-2)">You may submit a new request below if needed.</div>
                </div>
            <?php endif; ?>
            <div class="card" style="padding:var(--space-4);margin-top:var(--space-4)">
                <details>
                    <summary style="cursor:pointer;font-size:var(--text-sm);color:var(--accent);font-weight:var(--weight-medium)">
                        Need to reschedule?
                    </summary>
                    <form method="POST" action="<?= url('/api/exam-reschedule-request') ?>" style="margin-top:var(--space-3)">
                        <?= csrf_field() ?>
                        <textarea id="resched_reason" name="reschedule_reason" aria-label="Reschedule reason" class="form-textarea" rows="3"
                                  placeholder="Why do you need to reschedule? (e.g. emergency)"
                                  required style="margin-bottom:var(--space-3)"></textarea>
                        <button type="submit" class="btn btn-ghost" style="width:100%">Submit Reschedule Request</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <?php
            // Reschedule history — collapsed by default so it doesn't
            // crowd the page. Shows the most recent attempts and
            // includes the deny reason if staff provided one.
            $pastExamReschedules = array_slice($examRescheduleHistory, $examReschedulePending ? 1 : 0);
        ?>
        <?php if (!empty($pastExamReschedules)): ?>
            <details style="margin-top:var(--space-4)">
                <summary style="cursor:pointer;font-size:var(--text-xs);color:var(--text-tertiary)">
                    Past reschedule requests (<?= count($pastExamReschedules) ?>)
                </summary>
                <div style="margin-top:var(--space-3);display:flex;flex-direction:column;gap:var(--space-2)">
                    <?php foreach ($pastExamReschedules as $h): ?>
                        <div style="padding:var(--space-3);background:var(--bg-subtle);border-radius:var(--radius);font-size:var(--text-xs)">
                            <div style="display:flex;justify-content:space-between;gap:var(--space-3);align-items:flex-start">
                                <div style="color:var(--text-tertiary)">
                                    <?= date('M j, Y · g:i A', strtotime($h['created_at'])) ?>
                                </div>
                                <?php
                                    $sLabel = $h['status'];
                                    $sClass = $sLabel === 'approved' ? 'badge-success'
                                            : ($sLabel === 'denied' ? 'badge-error' : 'badge-warning');
                                ?>
                                <span class="badge <?= $sClass ?>">
                                    <?= e(ucfirst($sLabel)) ?>
                                </span>
                            </div>
                            <?php if (!empty($h['reason'])): ?>
                                <div style="margin-top:var(--space-2);color:var(--text-secondary);white-space:pre-line">
                                    <?= e($h['reason']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($h['status'] === 'denied' && !empty($h['deny_reason'])): ?>
                                <div style="margin-top:var(--space-2);color:var(--text-tertiary)">
                                    Staff: <?= e($h['deny_reason']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
    <?php
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

if ($isSlotPast) {
    ob_start();
    ?>
    <div style="max-width:520px;margin:var(--space-12) auto;text-align:center">
        <div class="alert alert-warning" style="text-align:left">
            <strong>Your exam date has passed.</strong>
            <p style="margin:var(--space-2) 0 0">
                Your scheduled exam was on <?= e($slotDateNice) ?> in <?= e($mySlot['room_label']) ?>.
                <?php if (!$examReschedulePending): ?>
                    If you missed it, use the form below to request a reschedule.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($examReschedulePending): ?>
            <div style="margin-top:var(--space-4);padding:var(--space-4) var(--space-5);
                        background:var(--warning-bg);border-radius:var(--radius);text-align:left">
                <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:var(--warning)">
                    Reschedule request submitted
                </div>
                <div style="font-size:var(--text-xs);color:var(--text-secondary);margin-top:2px">
                    Submitted <?= date('M j, g:i A', strtotime($myExamReschedule['created_at'])) ?>
                    - awaiting staff review.
                </div>
                <?php if (!empty($myExamReschedule['reason'])): ?>
                    <div style="margin-top:var(--space-2);font-size:var(--text-sm);color:var(--text-secondary);
                                 white-space:pre-line">
                        <?= e($myExamReschedule['reason']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($examRescheduleDenied): ?>
                <div class="alert alert-warning" style="margin-top:var(--space-4);font-size:var(--text-sm);text-align:left">
                    Your previous reschedule request was not approved.
                    <?php if (!empty($myExamReschedule['deny_reason'])): ?>
                        <div style="margin-top:var(--space-2);font-weight:var(--weight-medium)">
                            Reason from staff:
                            <span style="font-weight:var(--weight-regular);white-space:pre-line">
                                <?= e($myExamReschedule['deny_reason']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:var(--space-2)">You may submit a new request below.</div>
                </div>
            <?php endif; ?>
            <div class="card" style="padding:var(--space-4);margin-top:var(--space-4);text-align:left">
                <details open>
                    <summary style="cursor:pointer;font-size:var(--text-sm);color:var(--accent);font-weight:var(--weight-medium)">
                        Request a reschedule
                    </summary>
                    <form method="POST" action="<?= url('/api/exam-reschedule-request') ?>" style="margin-top:var(--space-3)">
                        <?= csrf_field() ?>
                        <textarea id="missed_reason" name="reschedule_reason" aria-label="Reschedule reason" class="form-textarea" rows="3"
                                  placeholder="Why did you miss the exam, and why reschedule?"
                                  required style="margin-bottom:var(--space-3)"></textarea>
                        <button type="submit" class="btn btn-primary" style="width:100%">Submit Reschedule Request</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <?php $pastExamReschedules = array_slice($examRescheduleHistory, $examReschedulePending ? 1 : 0); ?>
        <?php if (!empty($pastExamReschedules)): ?>
            <details style="margin-top:var(--space-4);text-align:left">
                <summary style="cursor:pointer;font-size:var(--text-xs);color:var(--text-tertiary)">
                    Past reschedule requests (<?= count($pastExamReschedules) ?>)
                </summary>
                <div style="margin-top:var(--space-3);display:flex;flex-direction:column;gap:var(--space-2)">
                    <?php foreach ($pastExamReschedules as $h): ?>
                        <div style="padding:var(--space-3);background:var(--bg-subtle);border-radius:var(--radius);font-size:var(--text-xs)">
                            <div style="display:flex;justify-content:space-between;gap:var(--space-3);align-items:flex-start">
                                <div style="color:var(--text-tertiary)">
                                    <?= date('M j, Y · g:i A', strtotime($h['created_at'])) ?>
                                </div>
                                <?php
                                    $sLabel = $h['status'];
                                    $sClass = $sLabel === 'approved' ? 'badge-success'
                                            : ($sLabel === 'denied' ? 'badge-error' : 'badge-warning');
                                ?>
                                <span class="badge <?= $sClass ?>">
                                    <?= e(ucfirst($sLabel)) ?>
                                </span>
                            </div>
                            <?php if (!empty($h['reason'])): ?>
                                <div style="margin-top:var(--space-2);color:var(--text-secondary);white-space:pre-line">
                                    <?= e($h['reason']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($h['status'] === 'denied' && !empty($h['deny_reason'])): ?>
                                <div style="margin-top:var(--space-2);color:var(--text-tertiary)">
                                    Staff: <?= e($h['deny_reason']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
    <?php
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

// $isSlotToday — fall through to the existing password gate below.

// ── Password gate: required on exam day ─────────────────────────────────────
// Students must enter the current (non-expired) access password before seeing
// exam questions. The password is per-room (lives on the slot row, per Chunk 7)
// and is valid for 5 minutes from when the proctor issued it.
//
// Late-entry lockout was removed. The proctor's "Extend +5m" button is
// the official mechanism for letting late-but-legitimate applicants in
// — anyone with a valid reason gets a freshly-issued code from the
// room proctor, regardless of how late they arrive.
$pwGateKey    = 'exam_pw_unlocked_' . $examId . '_slot_' . (int)$mySlot['id']; // session key (per-slot)
// Password gate is mandatory on the slot day — a student must enter the
// proctor's per-room access code before reaching the exam, even if no
// code has been issued yet (in that case the gate shows "no active code"
// with the input disabled). This guarantees nobody loads the exam
// without a freshly-issued code from the room proctor.
$needsPwGate  = $isSlotToday;
$isUnlocked   = isset($_SESSION[$pwGateKey]);

$slotOpensTs   = strtotime($mySlot['exam_date'] . ' ' . $mySlot['slot_time']);
$isLateLockout = false; // disabled - proctor decides via the Extend button

if ($needsPwGate && !$isUnlocked) {
    $pwValid    = exam_password_is_valid($mySlot);
    $pwSecsLeft = exam_password_seconds_remaining($mySlot);

    // Handle password submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam_access_password'])) {
        csrf_check();
        $submitted = strtoupper(post_str('exam_access_password'));
        $correct   = strtoupper(trim($mySlot['access_password']));

        if (!$pwValid) {
            $pwError = 'The access code has expired. Please ask your proctor to generate or extend the code.';
        } elseif ($submitted !== $correct) {
            $pwError = 'Incorrect access code. Please check with your proctor and try again.';
        } else {
            // Correct and still valid — unlock for this session.
            // Redirect (POST → GET) so the answer-submission handler below
            // is never triggered by the password form POST.
            $_SESSION[$pwGateKey] = time();
            redirect('/student/exam');
        }
    }

    if (!$isUnlocked) {
        // Show the password entry screen instead of the exam
        ob_start();
        ?>
        <div style="max-width:420px;margin:var(--space-12) auto;text-align:center">
            <div style="width:72px;height:72px;border-radius:50%;background:var(--bg-subtle);
                        display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-6)">
                <?= icon('lock:fill', 32, 'color:var(--text-secondary)') ?>
            </div>
            <h2 style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-2)">
                Exam Access Code
            </h2>
            <p style="color:var(--text-secondary);margin-bottom:var(--space-6)">
                Your proctor will give you an access code before the exam begins.
                Enter it below to start.
            </p>

            <?php if (isset($pwError)): ?>
                <div class="alert alert-error" style="margin-bottom:var(--space-4);text-align:left"><?= e($pwError) ?></div>
            <?php endif; ?>

            <?php if (!$pwValid): ?>
                <div class="alert alert-warning" style="margin-bottom:var(--space-4);text-align:left">
                    <strong>No active code right now.</strong>
                    The access code has not been issued yet or has expired.
                    Please wait for your proctor to provide one - they will generate a fresh
                    code from this room's slot in the Exam Slots page.
                </div>
            <?php endif; ?>

            <form method="POST" style="text-align:left">
                <?= csrf_field() ?>
                <label class="form-label" for="exam_access_password" style="display:block;margin-bottom:var(--space-2)">
                    Access Code
                </label>
                <input type="text" id="exam_access_password" name="exam_access_password" class="form-control"
                       autocomplete="off" autocorrect="off" spellcheck="false"
                       style="font-family:monospace;font-size:var(--text-2xl);letter-spacing:.4em;
                              text-align:center;text-transform:uppercase;margin-bottom:var(--space-4)"
                       placeholder="Access code" <?= !$pwValid ? 'disabled' : '' ?>
                       oninput="this.value=this.value.toUpperCase()">
                <?php if ($pwValid): ?>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-4)">
                        Code expires in <strong id="gate-timer"><?= $pwSecsLeft ?></strong> seconds.
                    </p>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="width:100%" <?= !$pwValid ? 'disabled' : '' ?>>
                    Enter Exam
                </button>
            </form>
        </div>
        <?php if ($pwValid): ?>
        <script>
        (function() {
            let s = <?= $pwSecsLeft ?>;
            const el = document.getElementById('gate-timer');
            const iv = setInterval(() => {
                if (!el) { clearInterval(iv); return; }
                s--;
                el.textContent = s;
                if (s <= 0) {
                    clearInterval(iv);
                    location.reload(); // refresh to show expired state
                }
            }, 1000);
        })();
        </script>
        <?php endif; ?>
        <?php
        $content   = ob_get_clean();
        $pageTitle = 'Entrance Exam';
        $activeNav = 'exam';
        $showStepper = true;
        include VIEWS_PATH . '/layouts/app.php';
        return;
    }
}
// ── End password gate ────────────────────────────────────────────────────────

// Draft for restore. The server copy is read here; the page script compares
// it with the browser copy and uses the newer one. Scoped to this applicant,
// exam and slot. ts is epoch milliseconds, so it can be compared with Date.now().
$draftKey    = 'plp_exam_draft:' . (int)$userId . ':' . (int)$examId . ':' . (int)($mySlot['id'] ?? 0);
$serverDraft = null;
try {
    $stmt = $db->prepare(
        "SELECT answers,
                CAST(extract(epoch FROM (saved_at AT TIME ZONE current_setting('TimeZone'))) * 1000 AS bigint) AS ts
           FROM exam_drafts
          WHERE applicant_id = ? AND exam_id = ? AND slot_id = ?"
    );
    $stmt->execute([$applicantId, $examId, $mySlot['id'] ?? 0]);
    if ($row = $stmt->fetch()) {
        $serverDraft = ['a' => $row['answers'], 't' => (int)$row['ts']];
    }
} catch (\Throwable $e) {
    // Non-critical: the browser copy can still restore.
}

// Fetch questions
$stmt = $db->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
$stmt->execute([$examId]);
$questions = $stmt->fetchAll();

// Fetch sections
$stmt = $db->prepare('SELECT * FROM exam_sections WHERE exam_id=? ORDER BY sort_order, id');
$stmt->execute([$examId]);
$sections = $stmt->fetchAll();

// Group questions by section
$questionsBySection = [];
foreach ($sections as $sec) $questionsBySection[$sec['id']] = [];
$unsectioned = [];
foreach ($questions as $q) {
    $sid = (int)$q['section_id'];
    if (isset($questionsBySection[$sid])) $questionsBySection[$sid][] = $q;
    else $unsectioned[] = $q;
}

// Shuffle if configured
if ($exam['shuffle_questions']) {
    foreach ($questionsBySection as &$secQs) shuffle($secQs);
    unset($secQs); // prevent dangling reference from corrupting render loop
    shuffle($unsectioned);
}

$errors = [];
$totalPoints = array_sum(array_column($questions, 'points'));

// ----------------------------------------------------------------
// POST — submit answers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $answers = $_POST['answers'] ?? [];
    $note    = null;

    // Grace rule: a normal submit up to N seconds after close is accepted.
    // Later than that, only what the server saved before close counts.
    if (!empty($mySlot['id']) && !slot_is_open((int)$mySlot['id'], exam_submit_grace_seconds())) {
        $stmt = $db->prepare('SELECT answers FROM exam_drafts WHERE applicant_id = ? AND exam_id = ? AND slot_id = ?');
        $stmt->execute([$applicantId, $examId, $mySlot['id']]);
        $draftJson = $stmt->fetchColumn();
        if ($draftJson === false) {
            // ponytail: no saved draft means nothing to score. Staff handle it, not scored 0.
            fail('The exam has closed and no saved answers were found. Please contact the admissions office.', '/student/documents');
        }
        $answers = exam_answers_from_draft((string)$draftJson);
        $note    = EXAM_AUTO_NOTE;
    }

    $res = finalize_exam_result($applicantId, $examId, $answers, $note);
    if ($res === null) {
        // Already submitted (double submit): the page top sends them to the right place.
        redirect('/student/exam');
    }

    $score       = $res['score'];
    $totalPoints = $res['total'];
    $rank        = $res['rank'];
    $passed      = $res['passed'];
    $pct         = $res['pct'];
    $tierInfo    = rank_tier_info($rank);

    // Build course suggestions if failed
    $altCourses = [];
    if (!$passed) {
        $altCourses = suggest_alt_courses($score, count($questions), $applicant['course_applied']);
    }

    // ── Inline result screen ──────────────────────────────────────
    ob_start();
    ?>
    <div style="max-width:520px;margin:0 auto;padding:var(--space-4) 0">

        <!-- Score card -->
        <div class="card" style="padding:var(--space-8);text-align:center;margin-bottom:var(--space-5)">

            <!-- Big rank circle -->
            <div style="width:96px;height:96px;border-radius:50%;
                        background:<?= $tierInfo['bg'] ?>;
                        border:3px solid <?= $tierInfo['color'] ?>;
                        display:flex;flex-direction:column;align-items:center;justify-content:center;
                        margin:0 auto var(--space-5)">
                <span style="font-size:2rem;font-weight:var(--weight-semibold);color:<?= $tierInfo['color'] ?>;line-height:1"><?= $rank ?></span>
                <span style="font-size:var(--text-xs);color:<?= $tierInfo['color'] ?>;font-weight:var(--weight-medium)">/10</span>
            </div>

            <!-- Verdict banner -->
            <div style="display:inline-flex;align-items:center;gap:6px;
                        background:<?= $tierInfo['bg'] ?>;
                        color:<?= $tierInfo['color'] ?>;
                        border-radius:var(--radius);
                        padding:4px 14px;
                        font-weight:var(--weight-semibold);
                        font-size:var(--text-sm);
                        margin-bottom:var(--space-3)">
                <?php if ($passed): ?>
                    <?= icon('check', 14) ?>
                    Passed
                <?php else: ?>
                    <?= icon('x', 14) ?>
                    Did not pass
                <?php endif; ?>
            </div>

            <h2 style="font-size:var(--text-xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">
                <?= $passed ? 'Congratulations!' : 'Better luck next time.' ?>
            </h2>
            <p style="color:var(--text-secondary);font-size:var(--text-sm);margin-bottom:var(--space-6)">
                <?= $passed
                    ? 'You passed the entrance exam. Proceed to the next step.'
                    : 'Your score did not meet the passing threshold for your chosen course.' ?>
            </p>

            <!-- Score breakdown grid -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-3);
                        background:var(--bg-subtle);border-radius:var(--radius);padding:var(--space-5)">
                <div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Raw Score</div>
                    <div style="font-size:var(--text-2xl);font-weight:var(--weight-semibold)"><?= $score ?></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary)">out of <?= $totalPoints ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Percentage</div>
                    <div style="font-size:var(--text-2xl);font-weight:var(--weight-semibold)"><?= $pct ?>%</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary)">of total items</div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Tier</div>
                    <div style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);color:<?= $tierInfo['color'] ?>"><?= $rank ?></div>
                    <div style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:<?= $tierInfo['color'] ?>"><?= $tierInfo['label'] ?> tier</div>
                </div>
            </div>

            <!-- Course + threshold row -->
            <div style="margin-top:var(--space-4);padding:var(--space-3) var(--space-4);
                        border:1px solid var(--border);border-radius:var(--radius);
                        font-size:var(--text-xs);color:var(--text-secondary);text-align:left;
                        display:flex;justify-content:space-between;align-items:center">
                <div>
                    <span style="color:var(--text-tertiary)">Course applied:</span>
                    <strong style="margin-left:4px"><?= e($applicant['course_applied']) ?></strong>
                </div>
                <div>
                    <span style="color:var(--text-tertiary)">Passing rank:</span>
                    <strong style="margin-left:4px">≥ <?= get_pass_threshold($applicant['course_applied']) ?></strong>
                </div>
            </div>
        </div>

        <?php if ($passed): ?>
        <!-- Passed → next step CTA -->
        <div class="card" style="padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
            <div style="width:40px;height:40px;border-radius:50%;background:var(--success-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <?= icon('check', 18, 'color:#22c55e') ?>
            </div>
            <div style="flex:1">
                <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm)">Next: Interview</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">The admissions office will automatically assign you an interview slot. No action required.</div>
            </div>
            <a href="<?= url('/student/interview') ?>" class="btn btn-primary btn-sm">Continue <?= icon('ic_fluent_arrow_right_24_regular', 16) ?></a>
        </div>

        <?php else: ?>
        <!-- Failed → course suggestion panel -->
        <?php if (!empty($altCourses)): ?>
        <div class="card" style="padding:var(--space-5);margin-bottom:var(--space-4)">
            <div style="display:flex;align-items:flex-start;gap:var(--space-3);margin-bottom:var(--space-4)">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--warning-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('warning-circle:fill', 16, 'color:#f59e0b') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm)">Available Alternatives</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                        Your score (rank <?= $rank ?>) qualifies for the following courses that still have available slots.
                        Your admissions officer can process a course shift for you.
                    </div>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                <?php foreach ($altCourses as $alt):
                    $altThreshold = get_pass_threshold($alt);
                    $altTier      = rank_tier_info($rank);
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;
                             padding:var(--space-3) var(--space-4);
                             background:var(--bg-subtle);border-radius:var(--radius);
                             border:1px solid var(--border)">
                    <div>
                        <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($alt) ?></div>
                        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                            Passing rank: ≥ <?= $altThreshold ?> &nbsp;·&nbsp;
                            Your rank: <span style="color:<?= $altTier['color'] ?>;font-weight:var(--weight-semibold)"><?= $rank ?> (<?= $altTier['label'] ?>)</span>
                        </div>
                    </div>
                    <span class="badge badge-approved" style="flex-shrink:0">Qualifies</span>
                </div>
                <?php endforeach; ?>
            </div>
            <p style="margin-top:var(--space-3);font-size:var(--text-xs);color:var(--text-tertiary)">
                ℹ Please approach the admissions office to request a course change. Final assignment is subject to staff approval.
            </p>
        </div>
        <?php else: ?>
        <div class="card" style="padding:var(--space-5);margin-bottom:var(--space-4)">
            <div style="display:flex;align-items:center;gap:var(--space-3)">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--error-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('x', 16, 'color:#ef4444') ?>
                </div>
                <div style="font-size:var(--text-sm);color:var(--text-secondary)">
                    Unfortunately, your score does not currently qualify for any available course.
                    Please contact the admissions office for further guidance.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card" style="padding:var(--space-4);background:var(--bg-subtle)">
            <p style="font-size:var(--text-xs);color:var(--text-tertiary);text-align:center">
                Your result has been recorded. The admissions office will follow up with next steps.
                You may also visit the office directly for assistance.
            </p>
        </div>
        <?php endif; ?>

    </div>
    <script>try { localStorage.removeItem(<?= json_encode($draftKey) ?>); } catch (e) {}</script>

    <?php
    $content     = ob_get_clean();
    $pageTitle   = 'Exam Result';
    $activeNav   = 'exam';
    $showStepper = true;
    include VIEWS_PATH . '/layouts/app.php';
    exit;
}

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
?>

<style>
/* ── Prevent text selection on exam content ──────────────── */
#exam-content,
#exam-content * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}

/* Allow selection only inside text inputs/textareas for typing */
#exam-content input[type="text"],
#exam-content input[type="number"],
#exam-content textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
    user-select: text;
}

/* ── Question cards ──────────────────────────────────────── */
.q-card {
    padding: var(--space-6);
    transition: border-color .15s;
}

/* Section divider in student exam view */
.exam-section-header {
    display: flex; flex-direction: column; gap: 2px;
    padding: var(--space-3) var(--space-5);
    border-radius: var(--radius);
    border: 1.5px solid transparent;
    margin-bottom: var(--space-1);
}
.exam-section-title {
    font-weight: var(--weight-semibold);
    font-size: var(--text-sm);
}
.exam-section-directions {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    margin-top: var(--space-2);
    padding: var(--space-2) var(--space-3);
    background: rgba(255,255,255,.55);
    border-radius: var(--radius);
    font-size: var(--text-xs);
    line-height: 1.5;
    opacity: .95;
}
.exam-section-directions-label {
    font-weight: var(--weight-semibold);
    white-space: nowrap;
    flex-shrink: 0;
}
.q-number {
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: var(--space-2);
}
.q-text {
    font-weight: var(--weight-medium);
    font-size: var(--text-base);
    margin-bottom: var(--space-2);
    line-height: 1.5;
}
.q-desc {
    font-size: var(--text-sm);
    color: var(--text-secondary);
    margin-bottom: var(--space-4);
}
.q-required {
    color: var(--error);
    margin-left: 2px;
}

/* ── Choice labels ───────────────────────────────────────── */
.choice-label {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    transition: background .12s, border-color .12s, box-shadow .12s;
    margin-bottom: var(--space-2);
    background: var(--bg-elevated);
    user-select: none;
}
.choice-label:hover {
    border-color: var(--accent);
    background: var(--accent-muted);
}
.choice-label.chosen {
    background: var(--accent-muted);
    border-color: var(--accent);
}
.choice-label input { accent-color: var(--accent); flex-shrink: 0; width: 16px; height: 16px; }
.choice-label span { font-size: var(--text-sm); line-height: var(--leading-normal); }

/* ── Dropdown ────────────────────────────────────────────── */
.exam-select {
    max-width: 320px;
}

/* ── Short answer ────────────────────────────────────────── */
.short-answer-input {
    max-width: 440px;
    width: 100%;
    padding: 10px var(--space-4);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-elevated);
    font-family: var(--font-sans);
    font-size: var(--text-sm);
    color: var(--text-primary);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    min-height: 42px;
}
.short-answer-input::placeholder { color: var(--text-placeholder); }
.short-answer-input:hover:not(:focus) { border-color: var(--border-strong); }
.short-answer-input:focus {
    border-color: var(--accent);
    outline: 1px solid var(--accent);
}

/* ── Paragraph ───────────────────────────────────────────── */
.paragraph-input {
    width: 100%;
    padding: 10px var(--space-4);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-elevated);
    font-family: var(--font-sans);
    font-size: var(--text-sm);
    color: var(--text-primary);
    outline: none;
    resize: vertical;
    min-height: 100px;
    line-height: var(--leading-relaxed);
    transition: border-color .15s, box-shadow .15s;
}
.paragraph-input::placeholder { color: var(--text-placeholder); }
.paragraph-input:hover:not(:focus) { border-color: var(--border-strong); }
.paragraph-input:focus {
    border-color: var(--accent);
    outline: 1px solid var(--accent);
}

/* ── Linear scale ────────────────────────────────────────── */
.scale-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
}
.scale-label {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    white-space: nowrap;
}
.scale-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    cursor: pointer;
}
.scale-option input { display: none; }
.scale-bubble {
    width: 40px; height: 40px;
    border: 2px solid var(--border);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    transition: background .12s, border-color .12s, color .12s;
    cursor: pointer;
}
.scale-option:hover .scale-bubble {
    border-color: var(--accent);
    background: var(--accent-bg, rgba(45,106,79,.07));
}
.scale-option input:checked ~ .scale-bubble {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}

/* ── Progress bar ────────────────────────────────────────── */
.exam-progress {
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: var(--space-3) var(--space-5);
    display: flex;
    align-items: center;
    gap: var(--space-4);
    margin: 0 calc(-1 * var(--space-6)) var(--space-6);
}
.progress-bar-wrap {
    flex: 1;
    height: 6px;
    background: var(--bg-subtle);
    border-radius: var(--radius);
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: var(--accent);
    border-radius: var(--radius);
    transition: width .3s;
    width: 0%;
}

/* ── Timer ───────────────────────────────────────────────── */
.timer-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: var(--text-sm); font-weight: var(--weight-semibold);
    padding: 4px 12px;
    background: var(--bg-subtle);
    border-radius: var(--radius);
    font-variant-numeric: tabular-nums;
}
.timer-chip.warning { background: rgba(220,38,38,.08); color: var(--error); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
        <?php if ($exam['description']): ?>
            <p class="page-description"><?= e($exam['description']) ?></p>
        <?php else: ?>
            <p class="page-description">
                <?= count($questions) ?> questions &middot;
                Answer all required questions before submitting.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-warning" style="margin-bottom:var(--space-6)">
    <?= icon('ic_fluent_warning_24_regular', 16) ?>
    <span>You can only submit <strong>once</strong>. Do not close this page until you click <strong>Submit Exam</strong>.</span>
    <?php
        // The exam closes at the slot's explicit end_time.
        $slotWindow    = slot_window($mySlot);
        $dispRemaining = max(0, $slotWindow['closes']->getTimestamp() - time());
        if ($dispRemaining > 0):
        $dispM = str_pad(floor($dispRemaining / 60), 2, '0', STR_PAD_LEFT);
        $dispS = str_pad($dispRemaining % 60, 2, '0', STR_PAD_LEFT);
    ?>
        &nbsp;&nbsp;Time remaining: <span id="timer" class="timer-chip"><?= $dispM ?>:<?= $dispS ?></span>
    <?php endif; ?>
</div>

<form method="POST" id="exam-form">
    <?= csrf_field() ?>

    <!-- Sticky progress bar -->
    <div class="exam-progress" id="exam-progress">
        <span style="font-size:var(--text-xs);color:var(--text-tertiary);white-space:nowrap" id="answered-label">0 / <?= count($questions) ?> answered</span>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progress-fill"></div>
        </div>
    </div>

    <div id="exam-content" style="display:flex;flex-direction:column;gap:var(--space-4)">
    <?php
    // Build shuffle index for choices if needed
    $shuffleChoices = (bool)$exam['shuffle_choices'];

    $SECTION_COLORS = [
        'multiple_choice' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        'checkboxes'      => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#6ee7b7'],
        'dropdown'        => ['bg' => '#ede9fe', 'text' => '#5b21b6', 'border' => '#c4b5fd'],
        'short_answer'    => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        'identification'  => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        'paragraph'       => ['bg' => '#fce7f3', 'text' => '#9d174d', 'border' => '#f9a8d4'],
        'linear_scale'    => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#d1d5db'],
    ];

    $globalI = 0;

    // Helper: render a single question card
    function renderStudentQuestion($q, &$globalI, $shuffleChoices) {
        $qType   = $q['question_type'] ?? 'multiple_choice';
        $choices = $q['choices'] ? json_decode($q['choices'], true) : [];
        $required = (bool)$q['is_required'];

        // Shuffle choices while preserving correct index mapping
        $choiceMap = range(0, count($choices)-1);
        if ($shuffleChoices && in_array($qType, ['multiple_choice','checkboxes','dropdown'])) {
            shuffle($choiceMap);
        }
        $globalI++;
        $totalQs = $GLOBALS['_exam_total_q'];
    ?>
        <div class="card q-card" id="qcard-<?= $q['id'] ?>" data-qid="<?= $q['id'] ?>" data-type="<?= $qType ?>">
            <div class="q-number">
                Question <?= $globalI ?> of <?= $totalQs ?>
                <?php if ($required): ?><span class="q-required">*</span><?php endif; ?>
                <?php if ($q['points'] > 0): ?>
                    &middot; <?= $q['points'] ?> pt<?= $q['points']!=1?'s':'' ?>
                <?php endif; ?>
            </div>
            <div class="q-text"><?= e($q['question_text']) ?></div>

            <!-- ── MULTIPLE CHOICE ── -->
            <?php if ($qType === 'multiple_choice'): ?>
                <div>
                <?php foreach ($choiceMap as $ci):
                    $choice = $choices[$ci]; ?>
                    <label class="choice-label" id="cl-<?= $q['id'] ?>-<?= $ci ?>">
                        <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $ci ?>"
                               <?= $required ? 'required' : '' ?>
                               onchange="markChosenRadio(this)">
                        <span><?= e($choice) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

            <!-- ── CHECKBOXES ── -->
            <?php elseif ($qType === 'checkboxes'): ?>
                <div>
                <?php foreach ($choiceMap as $ci):
                    $choice = $choices[$ci]; ?>
                    <label class="choice-label" id="cl-<?= $q['id'] ?>-<?= $ci ?>">
                        <input type="checkbox" name="answers[<?= $q['id'] ?>][]" value="<?= $ci ?>"
                               onchange="markChosenCheckbox(this)">
                        <span><?= e($choice) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

            <!-- ── DROPDOWN ── -->
            <?php elseif ($qType === 'dropdown'): ?>
                <select id="answer_<?= (int)$q['id'] ?>" name="answers[<?= $q['id'] ?>]" aria-label="Your answer" class="form-select exam-select"
                        <?= $required ? 'required' : '' ?>
                        onchange="markAnswered(<?= $q['id'] ?>)">
                    <option value="">- Select an answer -</option>
                    <?php foreach ($choiceMap as $ci):
                        $choice = $choices[$ci]; ?>
                        <option value="<?= $ci ?>"><?= e($choice) ?></option>
                    <?php endforeach; ?>
                </select>

            <!-- ── SHORT ANSWER / IDENTIFICATION ── -->\
            <?php elseif ($qType === 'short_answer' || $qType === 'identification'): ?>
                <input type="text"
                       id="answer_<?= (int)$q['id'] ?>"
                       name="answers[<?= $q['id'] ?>]"
                       aria-label="Your answer"
                       class="short-answer-input"
                       placeholder="Your answer"
                       <?= $required ? 'required' : '' ?>
                       oninput="markAnswered(<?= $q['id'] ?>)">

            <!-- ── PARAGRAPH ── -->
            <?php elseif ($qType === 'paragraph'): ?>
                <textarea id="answer_<?= (int)$q['id'] ?>"
                          name="answers[<?= $q['id'] ?>]"
                          aria-label="Your answer"
                          class="paragraph-input"
                          placeholder="Your answer"
                          rows="3"
                          <?= $required ? 'required' : '' ?>
                          oninput="markAnswered(<?= $q['id'] ?>)"></textarea>

            <!-- ── LINEAR SCALE ── -->
            <?php elseif ($qType === 'linear_scale'):
                $sMin = (int)($q['scale_min'] ?? 1);
                $sMax = (int)($q['scale_max'] ?? 5);
            ?>
                <div class="scale-row">
                    <?php if ($q['scale_min_label']): ?>
                        <span class="scale-label"><?= e($q['scale_min_label']) ?></span>
                    <?php endif; ?>
                    <?php for ($s = $sMin; $s <= $sMax; $s++): ?>
                        <label class="scale-option">
                            <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $s ?>"
                                   <?= $required ? 'required' : '' ?>
                                   onchange="markScaleChosen(this, <?= $q['id'] ?>)">
                            <div class="scale-bubble"><?= $s ?></div>
                        </label>
                    <?php endfor; ?>
                    <?php if ($q['scale_max_label']): ?>
                        <span class="scale-label"><?= e($q['scale_max_label']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php } // end renderStudentQuestion

    $GLOBALS['_exam_total_q'] = count($questions);

    foreach ($sections as $sec):
        $secQs = $questionsBySection[$sec['id']] ?? [];
        if (empty($secQs)) continue;
        $sc = $SECTION_COLORS[$sec['question_type']] ?? $SECTION_COLORS['multiple_choice'];
    ?>
        <div class="exam-section-header" style="background:<?= $sc['bg'] ?>;border-color:<?= $sc['border'] ?>">
            <div class="exam-section-title" style="color:<?= $sc['text'] ?>"><?= e($sec['title']) ?></div>
            <?php if (!empty($sec['description'])): ?>
                <div class="exam-section-directions" style="color:<?= $sc['text'] ?>">
                    <span class="exam-section-directions-label">Directions:</span>
                    <span><?= e($sec['description']) ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php foreach ($secQs as $q): renderStudentQuestion($q, $globalI, $shuffleChoices); endforeach; ?>
    <?php endforeach;

    // Unsectioned questions (fallback)
    foreach ($unsectioned as $q): renderStudentQuestion($q, $globalI, $shuffleChoices); endforeach;
    ?>
    </div>

    <div style="margin-top:var(--space-8);display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:var(--text-sm);color:var(--text-tertiary)" id="answered-count">
            0 of <?= count($questions) ?> answered
        </div>
        <button type="button" class="btn btn-primary" id="submit-btn" onclick="confirmSubmit()">Submit Exam</button>
    </div>
</form>

<!-- Confirm modal -->
<div id="confirm-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:400px">
        <div class="modal-header"><div class="modal-title">Submit Exam?</div></div>
        <div class="modal-body">
            <p style="color:var(--text-secondary);font-size:var(--text-sm)" id="confirm-message">
                Once submitted, your answers cannot be changed.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('confirm-modal').style.display='none'">Go Back</button>
            <button class="btn btn-primary" id="confirm-yes" onclick="document.getElementById('exam-form').submit()">Yes, Submit</button>
        </div>
    </div>
</div>

<div id="exam-status-msg" class="alert alert-warning" role="status"
     style="display:none;position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:1000;max-width:92vw;margin:0"></div>

<script>
// ── Context menu & drag prevention on exam content ──────────
const examContent = document.getElementById('exam-content');
if (examContent) {
    examContent.addEventListener('contextmenu', e => e.preventDefault());
    examContent.addEventListener('dragstart',   e => e.preventDefault());
    // Block Ctrl+A / Cmd+A on the exam area
    examContent.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'a') e.preventDefault();
    });
}

// ── Answer tracking ──────────────────────────────────────────
const TOTAL = <?= count($questions) ?>;
const answeredSet = new Set();

function updateProgress() {
    const n = answeredSet.size;
    document.getElementById('answered-count').textContent = n + ' of ' + TOTAL + ' answered';
    document.getElementById('answered-label').textContent  = n + ' / ' + TOTAL + ' answered';
    document.getElementById('progress-fill').style.width   = (TOTAL ? (n/TOTAL*100) : 0) + '%';
}

function markAnswered(qid) {
    // Check if actually has a value
    const form = document.getElementById('exam-form');
    const inputs = form.querySelectorAll(`[name="answers[${qid}]"], [name="answers[${qid}][]"]`);
    let hasAnswer = false;
    inputs.forEach(inp => {
        if (inp.type === 'radio' || inp.type === 'checkbox') {
            if (inp.checked) hasAnswer = true;
        } else if (inp.value.trim()) hasAnswer = true;
    });
    if (hasAnswer) answeredSet.add(qid);
    else answeredSet.delete(qid);

    updateProgress();
}

// Radio — single choice or dropdown
function markChosenRadio(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${CSS.escape(name)}"]`).forEach(r => {
        const label = r.closest('label');
        if (label) label.classList.toggle('chosen', r.checked);
    });
    // Extract qid from name like answers[123]
    const qid = parseInt(name.match(/\[(\d+)\]/)[1]);
    markAnswered(qid);
}

// Checkbox — multi select
function markChosenCheckbox(input) {
    const label = input.closest('label');
    if (label) label.classList.toggle('chosen', input.checked);
    const qid = parseInt(input.name.match(/\[(\d+)\]/)[1]);
    markAnswered(qid);
}

// Linear scale — toggle bubble
function markScaleChosen(input, qid) {
    // Uncheck visual on siblings first
    document.querySelectorAll(`input[name="answers[${qid}]"]`).forEach(r => {
        const bubble = r.parentElement?.querySelector('.scale-bubble');
        if (bubble) bubble.classList.toggle('chosen-scale', r.checked);
    });
    markAnswered(qid);
}

// Dropdown/text change handler — already calls markAnswered directly

// Set by the save code below. While true, Submit is blocked.
let examOffline = false;
let timerEnded  = false;

// ── Submit confirm ───────────────────────────────────────────
function confirmSubmit() {
    if (examOffline) return;
    const unanswered = TOTAL - answeredSet.size;
    const msg = unanswered > 0
        ? `You have ${unanswered} unanswered question${unanswered>1?'s':''}.  Once submitted, your answers cannot be changed.`
        : 'Once submitted, your answers cannot be changed. Make sure all answers are correct.';
    document.getElementById('confirm-message').textContent = msg;
    document.getElementById('confirm-modal').style.display = 'flex';
}

// ── Countdown timer ──────────────────────────────────────────
<?php
    // Closes at the slot's explicit end_time (per-room window).
    $slotWindow       = slot_window($mySlot);
    $secondsRemaining = max(0, $slotWindow['closes']->getTimestamp() - time());
    if ($secondsRemaining > 0):
?>
let seconds = <?= $secondsRemaining ?>;
const timerEl = document.getElementById('timer');
const interval = setInterval(() => {
    seconds--;
    if (seconds <= 0) {
        clearInterval(interval);
        // Offline: do not submit, keep the answers. The save code submits once a save gets through.
        if (examOffline) { timerEnded = true; return; }
        document.getElementById('exam-form').submit();
        return;
    }
    const m = String(Math.floor(seconds/60)).padStart(2,'0');
    const s = String(seconds%60).padStart(2,'0');
    timerEl.textContent = m + ':' + s;
    if (seconds <= 300) timerEl.classList.add('warning');
}, 1000);
<?php endif; ?>

// ── Warn before page leave ───────────────────────────────────
let submitted = false;
document.getElementById('exam-form').addEventListener('submit', () => { submitted = true; });
window.addEventListener('beforeunload', e => {
    if (!submitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// ── Save, retry and restore ─────────────────────────────────
// Browser copy on every change, server copy 2s after a change, 10s backup.
// Only a network failure (fetch throws / times out) means offline. A reply from
// the server that is an error is a server problem: keep retrying, but do not
// claim there is no connection or lock Submit.
(function() {
    const examId = <?= (int)$examId ?>;
    const slotId = <?= (int)($mySlot['id'] ?? 0) ?>;
    const userId = <?= (int)$userId ?>;
    const KEY    = <?= json_encode($draftKey) ?>;
    const serverDraft = <?= json_encode($serverDraft) ?>;
    const saveUrl = '<?= url('/api/exam-autosave') ?>';
    const form = document.getElementById('exam-form');
    const csrfToken = form.querySelector('input[name="_csrf"]')?.value || '';
    const msgEl     = document.getElementById('exam-status-msg');
    const submitBtn = document.getElementById('submit-btn');
    const yesBtn    = document.getElementById('confirm-yes');
    const modal     = document.getElementById('confirm-modal');
    const OFFLINE_MSG = 'No connection. Your answers are saved on this device. Reconnect to submit.';
    const SERVER_MSG  = 'Could not reach the save service. Your answers are still saved on this device. Retrying...';
    const CLOSED_MSG  = 'This exam has closed. Click Submit Exam to send the answers saved before it closed.';

    let lastSaved = null;     // answers the server last confirmed
    let saving = false, closed = false, restoring = false;
    let debounce = null, retryTimer = null, retryDelay = 0;

    const saveIndicator = document.createElement('div');
    saveIndicator.style.cssText = 'position:fixed;bottom:16px;right:16px;padding:6px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);font-size:12px;color:var(--text-tertiary);z-index:999;opacity:0;transition:opacity .3s';
    document.body.appendChild(saveIndicator);

    function showMsg(text) {
        msgEl.textContent = text;
        msgEl.style.display = text ? 'block' : 'none';
    }

    function setOffline(v) {
        examOffline = v;
        submitBtn.disabled = v;
        yesBtn.disabled = v;
        if (v) modal.style.display = 'none';
        showMsg(v ? OFFLINE_MSG : '');
    }

    function collectAnswers() {
        const data = {};
        form.querySelectorAll('[name^="answers["]').forEach(inp => {
            const match = inp.name.match(/answers\[(\d+)\](\[\])?/);
            if (!match) return;
            const qid = match[0];
            if (inp.type === 'radio' || inp.type === 'checkbox') {
                if (inp.checked) {
                    if (match[2]) {
                        if (!data[qid]) data[qid] = [];
                        data[qid].push(inp.value);
                    } else {
                        data[qid] = inp.value;
                    }
                }
            } else {
                data[qid] = inp.value;
            }
        });
        return JSON.stringify(data);
    }

    // Browser copy. Student, exam and slot are stored inside it and checked on restore.
    function localSave() {
        try {
            localStorage.setItem(KEY, JSON.stringify({ u: userId, e: examId, s: slotId, t: Date.now(), a: collectAnswers() }));
        } catch (e) {}
    }

    function scheduleRetry() {
        clearTimeout(retryTimer);
        retryDelay = Math.min((retryDelay || 1000) * 2, 30000);   // 2s, 4s ... 30s
        retryTimer = setTimeout(serverSave, retryDelay);
    }

    async function serverSave() {
        if (submitted || closed || saving) return;
        const answers = collectAnswers();
        if (answers === lastSaved && !examOffline) return;       // nothing new to send
        saving = true;
        const ctl = new AbortController();
        const timeout = setTimeout(() => ctl.abort(), 8000);
        try {
            const body = new FormData();
            body.append('exam_id', examId);
            body.append('slot_id', slotId);
            body.append('answers', answers);
            body.append('_csrf', csrfToken);
            const r = await fetch(saveUrl, { method: 'POST', body: body, signal: ctl.signal });   // throws only on network failure / timeout
            let d = null;
            try { d = await r.json(); } catch (e) {}
            if (!d) {                                            // server answered, but not with JSON (error page, expired token)
                setOffline(false);
                showMsg(SERVER_MSG);
                scheduleRetry();
                return;
            }
            if (d.closed) {
                closed = true;
                clearTimeout(retryTimer);
                setOffline(false);
                showMsg(CLOSED_MSG);
                return;
            }
            if (d.error === 'Already submitted') { closed = true; clearTimeout(retryTimer); return; }
            if (!d.ok) {                                         // server refused the save
                setOffline(false);
                showMsg(SERVER_MSG);
                scheduleRetry();
                return;
            }
            lastSaved = answers;
            retryDelay = 0;
            clearTimeout(retryTimer);
            setOffline(false);                                   // also clears any server-problem message
            saveIndicator.textContent = 'Draft saved ' + String(d.saved_at).slice(11, 19);
            saveIndicator.style.opacity = '1';
            setTimeout(() => saveIndicator.style.opacity = '0', 3000);
            if (timerEnded) form.submit();                       // timer ran out while offline
        } catch (e) {
            setOffline(true);
            scheduleRetry();
        } finally {
            clearTimeout(timeout);
            saving = false;
        }
    }

    // Fill answers from a saved JSON string and run the page's own handlers so
    // the chosen styles and the progress counter update.
    function applyAnswers(json) {
        let data;
        try { data = JSON.parse(json); } catch (e) { return false; }
        if (!data || typeof data !== 'object') return false;
        restoring = true;
        form.querySelectorAll('[name^="answers["]').forEach(inp => {
            const m = inp.name.match(/answers\[(\d+)\](\[\])?/);
            if (!m || !(m[0] in data)) return;
            const v = data[m[0]];
            const choice = inp.type === 'radio' || inp.type === 'checkbox';
            if (inp.type === 'radio') inp.checked = String(v) === inp.value;
            else if (inp.type === 'checkbox') inp.checked = Array.isArray(v) && v.map(String).includes(inp.value);
            else inp.value = v;
            if (choice ? inp.checked : inp.value) {
                inp.dispatchEvent(new Event('input',  { bubbles: true }));
                inp.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        restoring = false;
        return true;
    }

    // Restore: use the newer of the server draft and this browser's copy.
    // ponytail: "newer" compares the browser clock with the server clock; a badly wrong device clock can pick the older copy.
    let loc = null;
    try { loc = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
    if (!loc || loc.u !== userId || loc.e !== examId || loc.s !== slotId || typeof loc.a !== 'string') loc = null;
    const fromLocal = !!loc && (!serverDraft || loc.t > serverDraft.t);
    const pick = fromLocal ? loc.a : (serverDraft ? serverDraft.a : null);
    if (pick && applyAnswers(pick)) {
        if (fromLocal) setTimeout(serverSave, 2000);             // browser copy is newer: push it up
        else lastSaved = collectAnswers();
    }

    ['input', 'change'].forEach(ev => form.addEventListener(ev, () => {
        if (restoring || submitted) return;
        localSave();
        clearTimeout(debounce);
        debounce = setTimeout(serverSave, 2000);
    }));

    setInterval(() => { if (!examOffline) serverSave(); }, 10000);   // backup; offline retries use their own backoff
    window.addEventListener('online', () => { if (examOffline) serverSave(); });
    window.addEventListener('focus',  () => { if (examOffline) serverSave(); });
})();
</script>

<!-- Step navigation -->
<div class="step-nav" style="margin-top:var(--space-4)">
    <a href="<?= url('/student/documents') ?>" class="btn btn-ghost"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Documents</a>
    <span></span>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = 'Entrance Exam';
$activeNav   = 'exam';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
