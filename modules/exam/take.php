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

            audit_log(AuditAction::APPLICANT_ADVANCED_EXAM_SELFHEAL, "Self-healed advance to exam for applicant {$applicantId}", 'applicant', $applicantId);
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
    <div class="mwpx-480 my-12 mx-auto text-center">
        <div class="rounded-circle bg-subtle flex align-items-center justify-content-center" style="width:72px;height:72px;margin:0 auto var(--space-6)">
            <?= icon('clock:fill', 32, 'color:var(--text-secondary)') ?>
        </div>
        <h2 class="text-2xl fw-semibold mb-2">
            Awaiting Slot Assignment
        </h2>
        <p class="text-secondary">
            Your documents are approved. The admissions office will assign you to an exam date and room shortly. Check back here, or watch your email for a notification.
        </p>
    </div>
    <?php
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

$slotTs       = strtotime($mySlot['exam_date']);
$todayTs      = strtotime(now_date());
$isSlotToday  = $slotTs === $todayTs;
$isSlotFuture = $slotTs > $todayTs;
$isSlotPast   = $slotTs < $todayTs;
$slotDateNice = date('l, F j, Y', $slotTs);
$slotTimeNice = date('g:i A',     strtotime($mySlot['slot_time']));

if ($isSlotFuture) {
    ob_start();
    ?>
    <div class="mwpx-520 my-12 mx-auto">
        <div class="card p-6 text-center">
            <div class="text-xs text-tertiary text-uppercase fw-semibold mb-2" style="letter-spacing:.06em">
                Your Exam is Scheduled
            </div>
            <div class="fw-semibold mb-1" style="font-size:var(--text-3xl)">
                <?= e($slotDateNice) ?>
            </div>
            <div class="text-lg text-secondary mb-5">
                <?= e($slotTimeNice) ?>
            </div>
            <div class="bg-subtle rounded p-4 mb-5">
                <div class="text-xs text-tertiary text-uppercase fw-semibold mb-1" style="letter-spacing:.06em">Room</div>
                <div class="text-lg fw-medium"><?= e($mySlot['room_label']) ?></div>
            </div>
            <p class="text-secondary-sm">
                On exam day, log in here. The proctor in your room will announce the access code when the exam opens - you'll have 5 minutes to enter it to start.
            </p>
        </div>

        <?php if ($examReschedulePending): ?>
            <!-- Pending review — hide the form so the student can't
                 submit a duplicate. Page has "changed" the moment they
                 hit submit. -->
            <div class="mt-4 py-4 px-5 rounded" style="background:var(--warning-bg)">
                <div class="fw-semibold text-sm text-warning">
                    Reschedule request submitted
                </div>
                <div class="text-xs text-secondary" style="margin-top:2px">
                    Submitted <?= date('M j, g:i A', strtotime($myExamReschedule['created_at'])) ?>
                    - awaiting staff review. You'll see a new slot here once it's approved.
                </div>
                <?php if (!empty($myExamReschedule['reason'])): ?>
                    <div class="mt-2 text-sm text-secondary pre-line">
                        <?= e($myExamReschedule['reason']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($examRescheduleApproved): ?>
                <div class="alert alert-success mt-4 text-sm">
                    Your reschedule request was approved - you've been moved to the slot shown above.
                </div>
            <?php elseif ($examRescheduleDenied): ?>
                <div class="alert alert-warning mt-4 text-sm">
                    Your previous reschedule request was not approved - you've kept your current slot.
                    <?php if (!empty($myExamReschedule['deny_reason'])): ?>
                        <div class="mt-2 fw-medium">
                            Reason from staff:
                            <span class="fw-normal pre-line">
                                <?= e($myExamReschedule['deny_reason']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="mt-2">You may submit a new request below if needed.</div>
                </div>
            <?php endif; ?>
            <div class="card p-4 mt-4">
                <details>
                    <summary class="pointer text-sm text-accent fw-medium">
                        Need to reschedule?
                    </summary>
                    <form method="POST" action="<?= url('/api/exam-reschedule-request') ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <textarea id="resched_reason" name="reschedule_reason" aria-label="Reschedule reason" class="form-textarea mb-3" rows="3"
                                  placeholder="Why do you need to reschedule? (e.g. emergency)"
                                  required></textarea>
                        <button type="submit" class="btn btn-ghost w-100">Submit Reschedule Request</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <?php
            // Reschedule history — collapsed by default so it doesn't
            // crowd the page. Shows the most recent attempts and
            // includes the deny reason if staff provided one.
            $pastReschedules   = array_slice($examRescheduleHistory, $examReschedulePending ? 1 : 0);
            $historyExtraStyle = '';
            include VIEWS_PATH . '/partials/reschedule_history.php';
        ?>
    </div>
    <?php
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

if ($isSlotPast) {
    ob_start();
    ?>
    <div class="mwpx-520 my-12 mx-auto text-center">
        <div class="alert alert-warning text-start">
            <strong>Your exam date has passed.</strong>
            <p style="margin:var(--space-2) 0 0">
                Your scheduled exam was on <?= e($slotDateNice) ?> in <?= e($mySlot['room_label']) ?>.
                <?php if (!$examReschedulePending): ?>
                    If you missed it, use the form below to request a reschedule.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($examReschedulePending): ?>
            <div class="mt-4 py-4 px-5 rounded text-start" style="background:var(--warning-bg)">
                <div class="fw-semibold text-sm text-warning">
                    Reschedule request submitted
                </div>
                <div class="text-xs text-secondary" style="margin-top:2px">
                    Submitted <?= date('M j, g:i A', strtotime($myExamReschedule['created_at'])) ?>
                    - awaiting staff review.
                </div>
                <?php if (!empty($myExamReschedule['reason'])): ?>
                    <div class="mt-2 text-sm text-secondary pre-line">
                        <?= e($myExamReschedule['reason']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($examRescheduleDenied): ?>
                <div class="alert alert-warning mt-4 text-sm text-start">
                    Your previous reschedule request was not approved.
                    <?php if (!empty($myExamReschedule['deny_reason'])): ?>
                        <div class="mt-2 fw-medium">
                            Reason from staff:
                            <span class="fw-normal pre-line">
                                <?= e($myExamReschedule['deny_reason']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="mt-2">You may submit a new request below.</div>
                </div>
            <?php endif; ?>
            <div class="card p-4 mt-4 text-start">
                <details open>
                    <summary class="pointer text-sm text-accent fw-medium">
                        Request a reschedule
                    </summary>
                    <form method="POST" action="<?= url('/api/exam-reschedule-request') ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <textarea id="missed_reason" name="reschedule_reason" aria-label="Reschedule reason" class="form-textarea mb-3" rows="3"
                                  placeholder="Why did you miss the exam, and why reschedule?"
                                  required></textarea>
                        <button type="submit" class="btn btn-primary w-100">Submit Reschedule Request</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <?php
            $pastReschedules    = array_slice($examRescheduleHistory, $examReschedulePending ? 1 : 0);
            $historyExtraStyle  = 'text-align:left';
            include VIEWS_PATH . '/partials/reschedule_history.php';
        ?>
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
        <div class="mwpx-420 my-12 mx-auto text-center">
            <div class="rounded-circle bg-subtle flex align-items-center justify-content-center" style="width:72px;height:72px;margin:0 auto var(--space-6)">
                <?= icon('lock:fill', 32, 'color:var(--text-secondary)') ?>
            </div>
            <h2 class="text-2xl fw-semibold mb-2">
                Exam Access Code
            </h2>
            <p class="text-secondary mb-6">
                Your proctor will give you an access code before the exam begins.
                Enter it below to start.
            </p>

            <?php if (isset($pwError)): ?>
                <div class="alert alert-error mb-4 text-start"><?= e($pwError) ?></div>
            <?php endif; ?>

            <?php if (!$pwValid): ?>
                <div class="alert alert-warning mb-4 text-start">
                    <strong>No active code right now.</strong>
                    The access code has not been issued yet or has expired.
                    Please wait for your proctor to provide one - they will generate a fresh
                    code from this room's slot in the Exam Slots page.
                </div>
            <?php endif; ?>

            <form method="POST" class="text-start">
                <?= csrf_field() ?>
                <label class="form-label mb-2" for="exam_access_password" style="display:block">
                    Access Code
                </label>
                <input type="text" id="exam_access_password" name="exam_access_password" class="form-control text-2xl text-center text-uppercase mb-4"
                       autocomplete="off" autocorrect="off" spellcheck="false"
                       style="font-family:monospace;letter-spacing:.4em"
                       placeholder="Access code" <?= !$pwValid ? 'disabled' : '' ?>
                       oninput="this.value=this.value.toUpperCase()">
                <?php if ($pwValid): ?>
                    <p class="text-xs text-tertiary mb-4">
                        Code expires in <strong id="gate-timer" data-secs="<?= (int) $pwSecsLeft ?>"><?= $pwSecsLeft ?></strong> seconds.
                    </p>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-100" <?= !$pwValid ? 'disabled' : '' ?>>
                    Enter Exam
                </button>
            </form>
        </div>
        <?php
        $content   = ob_get_clean();
        $pageTitle = 'Entrance Exam';
        $activeNav = 'exam';
        $showStepper = true;
        if ($pwValid) {
            $pageScripts = ['js/pages/exam-gate-timer.js'];
        }
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
    <div class="mwpx-520 my-0 mx-auto py-4 px-0">

        <!-- Score card -->
        <div class="card p-8 text-center mb-5">

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

            <h2 class="text-xl fw-semibold mb-1">
                <?= $passed ? 'Congratulations!' : 'Better luck next time.' ?>
            </h2>
            <p class="text-secondary text-sm mb-6">
                <?= $passed
                    ? 'You passed the entrance exam. Proceed to the next step.'
                    : 'Your score did not meet the passing threshold for your chosen course.' ?>
            </p>

            <!-- Score breakdown grid -->
            <div class="gap-3 bg-subtle rounded p-5" style="display:grid;grid-template-columns:repeat(3,1fr)">
                <div>
                    <div class="text-xs text-tertiary text-uppercase mb-1" style="letter-spacing:.06em">Raw Score</div>
                    <div class="text-2xl fw-semibold"><?= $score ?></div>
                    <div class="text-xs text-tertiary">out of <?= $totalPoints ?></div>
                </div>
                <div>
                    <div class="text-xs text-tertiary text-uppercase mb-1" style="letter-spacing:.06em">Percentage</div>
                    <div class="text-2xl fw-semibold"><?= $pct ?>%</div>
                    <div class="text-xs text-tertiary">of total items</div>
                </div>
                <div>
                    <div class="text-xs text-tertiary text-uppercase mb-1" style="letter-spacing:.06em">Tier</div>
                    <div style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);color:<?= $tierInfo['color'] ?>"><?= $rank ?></div>
                    <div style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:<?= $tierInfo['color'] ?>"><?= $tierInfo['label'] ?> tier</div>
                </div>
            </div>

            <!-- Course + threshold row -->
            <div class="mt-4 py-3 px-4 bd rounded text-xs text-secondary text-start flex justify-content-between align-items-center">
                <div>
                    <span class="text-tertiary">Course applied:</span>
                    <strong class="ms-1"><?= e($applicant['course_applied']) ?></strong>
                </div>
                <div>
                    <span class="text-tertiary">Passing rank:</span>
                    <strong class="ms-1">≥ <?= get_pass_threshold($applicant['course_applied']) ?></strong>
                </div>
            </div>
        </div>

        <?php if ($passed): ?>
        <!-- Passed → next step CTA -->
        <div class="card p-5 flex align-items-center gap-4">
            <div class="rounded-circle flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;background:var(--success-bg)">
                <?= icon('check', 18, 'color:#22c55e') ?>
            </div>
            <div class="flex-1">
                <div class="fw-semibold text-sm">Next: Interview</div>
                <div class="text-xs text-tertiary" style="margin-top:2px">The admissions office will automatically assign you an interview slot. No action required.</div>
            </div>
            <a href="<?= url('/student/interview') ?>" class="btn btn-primary btn-sm">Continue <?= icon('ic_fluent_arrow_right_24_regular', 16) ?></a>
        </div>

        <?php else: ?>
        <!-- Failed → course suggestion panel -->
        <?php if (!empty($altCourses)): ?>
        <div class="card p-5 mb-4">
            <div class="flex align-items-start gap-3 mb-4">
                <div class="rounded-circle flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;background:var(--warning-bg)">
                    <?= icon('warning-circle:fill', 16, 'color:#f59e0b') ?>
                </div>
                <div>
                    <div class="fw-semibold text-sm">Available Alternatives</div>
                    <div class="text-xs text-tertiary" style="margin-top:2px">
                        Your score (rank <?= $rank ?>) qualifies for the following courses that still have available slots.
                        Your admissions officer can process a course shift for you.
                    </div>
                </div>
            </div>
            <div class="flex flex-column gap-2">
                <?php foreach ($altCourses as $alt):
                    $altThreshold = get_pass_threshold($alt);
                    $altTier      = rank_tier_info($rank);
                ?>
                <div class="flex align-items-center justify-content-between py-3 px-4 bg-subtle rounded bd">
                    <div>
                        <div class="fw-medium text-sm"><?= e($alt) ?></div>
                        <div class="text-xs text-tertiary" style="margin-top:2px">
                            Passing rank: ≥ <?= $altThreshold ?> &nbsp;·&nbsp;
                            Your rank: <span style="color:<?= $altTier['color'] ?>;font-weight:var(--weight-semibold)"><?= $rank ?> (<?= $altTier['label'] ?>)</span>
                        </div>
                    </div>
                    <span class="badge badge-approved flex-shrink-0">Qualifies</span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 text-xs text-tertiary">
                ℹ Please approach the admissions office to request a course change. Final assignment is subject to staff approval.
            </p>
        </div>
        <?php else: ?>
        <div class="card p-5 mb-4">
            <div class="flex align-items-center gap-3">
                <div class="rounded-circle flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;background:var(--error-bg)">
                    <?= icon('x', 16, 'color:#ef4444') ?>
                </div>
                <div class="text-sm text-secondary">
                    Unfortunately, your score does not currently qualify for any available course.
                    Please contact the admissions office for further guidance.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card p-4 bg-subtle">
            <p class="text-xs text-tertiary text-center">
                Your result has been recorded. The admissions office will follow up with next steps.
                You may also visit the office directly for assistance.
            </p>
        </div>
        <?php endif; ?>

    </div>
    <span id="exam-draft-clear" data-key="<?= e($draftKey) ?>" hidden></span>

    <?php
    $content     = ob_get_clean();
    $pageTitle   = 'Exam Result';
    $activeNav   = 'exam';
    $showStepper = true;
    $pageScripts = ['js/pages/exam-draft-clear.js'];
    include VIEWS_PATH . '/layouts/app.php';
    exit;
}

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
include __DIR__ . '/_take_view.php';
$content     = ob_get_clean();
$pageTitle   = 'Entrance Exam';
$activeNav   = 'exam';
$showStepper = true;
$pageStyles  = ['css/pages/exam-take.css'];
$pageScripts = ['js/pages/exam-take.js'];
include VIEWS_PATH . '/layouts/app.php';
