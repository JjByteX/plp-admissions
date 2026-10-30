<?php
// ============================================================
// modules/results/student_view.php
// M6 — Student: view admission result + enrollment intent +
//              withdraw application
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();

$applicant = require_applicant();
$applicantId = $applicant['id'];

$stmt = $db->prepare('SELECT * FROM admission_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$result = $stmt->fetch() ?: null;

// Stepper current step
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_examResult = $stmt->fetch() ?: null;

// Course suggestion from staff
$suggestion = null;
try {
    $stmt = $db->prepare(
        'SELECT cs.*, u.name AS staff_name
         FROM course_suggestions cs
         LEFT JOIN users u ON u.id = cs.suggested_by
         WHERE cs.applicant_id = ? LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $suggestion = $stmt->fetch() ?: null;
} catch (\Throwable $e) {}

$stmt = $db->prepare('SELECT q.* FROM interview_queue q WHERE q.applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_interviewSlot = $stmt->fetch() ?: null;

// ----------------------------------------------------------------
// Admitted-applicant context: the actual approved documents the
// applicant uploaded so the "bring originals of" list matches what
// they submitted (no hardcoded labels). The visit date is not set here:
// the Registrar assigns it in the Enrollment system.
// ----------------------------------------------------------------
$myDocs = [];
try {
    $stmt = $db->prepare(
        "SELECT doc_type, status FROM documents
          WHERE applicant_id = ? AND status = 'approved'
          ORDER BY id ASC"
    );
    $stmt->execute([$applicantId]);
    $myDocs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable) {}

// Build slug -> label map covering every doc type a student could
// have uploaded (Freshman / Transferee / Foreign).
$docLabels = array_merge(
    defined('DOCS_CORE')       ? DOCS_CORE       : [],
    defined('DOCS_FRESHMAN')   ? DOCS_FRESHMAN   : [],
    defined('DOCS_TRANSFEREE') ? DOCS_TRANSFEREE : [],
    defined('DOCS_FOREIGN')    ? DOCS_FOREIGN    : []
);

// College name from the applicant's course (e.g. "BS Information
// Technology (BSIT)" -> "College of Computer Studies").
$applicantCollege = function_exists('course_to_department')
    ? course_to_department((string)$applicant['course_applied'])
    : '';

$stepperCurrent = current_step($applicant, $_examResult, $_interviewSlot, $result);

// Withdrawal state helpers
$isWithdrawn = ($applicant['overall_status'] === 'withdrawn');
$canWithdraw = !$isWithdrawn;

ob_start();
?>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success mb-4"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error mb-4"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($isWithdrawn): ?>
<!-- ── Withdrawn state ──────────────────────────────────────── -->
    <div class="card withdrawn-card">
        <div class="status-icon-lg bg-subtle">
            <?= icon('ic_fluent_dismiss_circle_24_regular', 32, 'color:var(--text-tertiary)') ?>
        </div>
        <h2 class="text-2xl fw-semibold mb-2 text-main">Application Withdrawn</h2>
        <p class="text-secondary mb-6">You have voluntarily withdrawn your application.</p>

        <div class="bg-subtle rounded p-5 text-start mb-6">
            <div class="row row-cols-1 row-cols-md-2 gutter-4">
                <div>
                    <div class="label-caps">Name</div>
                    <div class="fw-medium text-sm"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div class="label-caps">Course Applied</div>
                    <div class="fw-medium text-sm"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div class="label-caps">Withdrawn On</div>
                    <div class="fw-medium text-sm">
                        <?= $applicant['withdrawn_at'] ? format_date($applicant['withdrawn_at'], 'F j, Y') : '-' ?>
                    </div>
                </div>
                <div>
                    <div class="label-caps">Status</div>
                    <span class="inline-flex align-items-center gap-1 py-1 px-2 rounded text-xs fw-semibold text-secondary bg-subtle">Withdrawn</span>
                </div>
            </div>
            <?php if ($applicant['withdrawn_reason']): ?>
                <div class="mt-4 pt-4 bd-t">
                    <div class="label-caps">Reason Given</div>
                    <p class="text-sm text-secondary"><?= e($applicant['withdrawn_reason']) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-sm text-tertiary">
            If you think this is a mistake, visit the admissions office in person.
        </p>
    </div>

<?php elseif (!$result): ?>
<!-- ── No result yet ────────────────────────────────────────── -->
    <div class="text-center text-tertiary" style="padding:var(--space-16)">
        <?= icon('warning-circle:fill', 48, 'color:var(--text-tertiary);margin-bottom:var(--space-4)') ?>
        <p class="fw-medium">Result not yet released</p>
        <p class="text-sm mt-1">You'll be notified once your result is ready.</p>
    </div>

<?php elseif ($result['result'] === 'accepted'): ?>
<!-- ── Admitted ─────────────────────────────────────────────── -->
<!--
  Replaces the old generic "Accepted" pill. The applicant has been
  ADMITTED, not yet officially enrolled. They finalize enrollment by
  bringing originals of every document they uploaded so SSO can
  verify them against the scans. There is no date on this page: after
  the student confirms, the Registrar assigns a visit in the Enrollment
  system and the student sees it there. Once confirmed, this page shows
  the Endorsement Slip (no date) that sends the student to Enrollment.
-->
    <div class="card p-6">
        <div class="text-center mb-6">
            <div class="status-icon-lg inline-flex align-items-center justify-content-center mb-4" style="background:var(--success-bg)">
                <?= icon('ic_fluent_checkmark_circle_24_regular', 32, 'color:var(--success)') ?>
            </div>
            <h2 class="text-2xl fw-semibold mb-2">You've been admitted.</h2>
            <p class="text-secondary m-0 text-sm">
                <strong><?= e($applicant['course_applied']) ?></strong>
                <?php if ($applicantCollege): ?>
                    · <?= e($applicantCollege) ?>
                <?php endif; ?>
                · SY <?= e($applicant['school_year']) ?>
            </p>
        </div>

        <p class="text-sm text-secondary text-center mb-6 mwpx-520 ms-auto" style="margin-right:auto">
            Admissions is done. To continue, press <strong>Continue to Enrollment</strong> below. This tells us you will enroll at PLP and sends your details to the Registrar, who will email you a link to set up your Enrollment account and give you a visit date. On that day, bring the <strong>original copies</strong> of your documents so we can verify them. The Registrar will guide you through the rest of enrollment.
        </p>

        <!-- Documents to bring -->
        <div class="bg-subtle rounded p-5 mb-5">
            <div class="text-xs text-uppercase text-tertiary fw-semibold mb-1" style="letter-spacing:.06em">Bring the originals of</div>
            <div class="text-xs text-tertiary mb-3">matches what you uploaded</div>
            <?php if (!empty($myDocs)): ?>
                <ul class="m-0 p-0 flex flex-column gap-2" style="list-style:none">
                    <?php foreach ($myDocs as $d):
                        $slug  = (string)$d['doc_type'];
                        $label = $docLabels[$slug] ?? ucwords(str_replace('_', ' ', $slug));
                    ?>
                        <li class="flex align-items-start gap-3 bd rounded py-2 px-3" style="background:var(--bg-elevated)">
                            <?= icon('ic_fluent_document_24_regular', 16, 'color:var(--text-tertiary);flex-shrink:0;margin-top:var(--space-1)') ?>
                            <span class="text-sm" style="line-height:1.4"><?= e($label) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-sm text-tertiary m-0">
                    Bring originals of every document you submitted with your application.
                </p>
            <?php endif; ?>
        </div>

        <!-- Confirm enrollment. Confirming also sends the applicant to Enrollment. -->
        <?php
            $isConfirmed = ($result['enrollment_intent'] ?? '') === 'confirmed';
            $handoff     = $isConfirmed ? enrollment_handoff_state($db, (int) $applicantId) : null;
            $handoffSent = $handoff && $handoff['status'] === 'sent';
            $enrollmentSiteUrl = trim((string) getenv('ENROLLMENT_SITE_URL'));
        ?>
        <div class="text-center mb-5">
            <?php if (!$isConfirmed): ?>
                <form method="POST" action="<?= url('/student/result') ?>" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_enrollment">
                    <button type="submit" class="btn btn-primary"
                            data-confirm="Continue to Enrollment? This confirms you will enroll at PLP and sends your details to the Registrar." data-confirm-ok="Continue">
                        Continue to Enrollment
                    </button>
                </form>
            <?php elseif ($handoffSent): ?>
                <p class="text-sm text-success fw-medium" style="margin:0 0 var(--space-3)">
                    Your details were sent to the Registrar. Check your email for a link to set your Enrollment password.
                </p>
                <?php if ($enrollmentSiteUrl !== ''): ?>
                    <a href="<?= e(rtrim($enrollmentSiteUrl, '/') . '/login') ?>" class="btn btn-primary" target="_blank" rel="noopener noreferrer">Go to Enrollment sign in</a>
                    <p class="text-xs text-tertiary" style="margin:var(--space-3) 0 0">
                        Sign in with the same email you applied with: <strong><?= e(Auth::user()['email'] ?? '') ?></strong>.
                        Enrollment has its own password, which you set once from the email we sent.
                    </p>
                <?php endif; ?>
            <?php elseif ($handoff && $handoff['status'] === 'rejected'): ?>
                <p class="text-secondary-sm">
                    You have confirmed you will enroll. The Registrar will contact you about your next step.
                </p>
            <?php else: ?>
                <p class="text-secondary-sm">
                    We are sending your details to the Registrar. You will get an email when it is done.
                </p>
            <?php endif; ?>
        </div>

        <?php if ($isConfirmed):
            // Endorsement Slip: proof that Admissions cleared the student. It carries
            // no date. The Enrollment system already has this record, so the Registrar
            // does not need the paper slip; this is the student's copy.
            $endorsementRef = enrollment_applicant_reference((string) $applicant['school_year'], (int) $applicantId);
        ?>
        <div class="bg-subtle rounded p-5 mb-5">
            <div class="text-xs text-uppercase text-tertiary fw-semibold mb-3" style="letter-spacing:.06em">Endorsement Slip</div>
            <div class="row row-cols-1 row-cols-md-2 mb-3 gutter-4">
                <div>
                    <div class="text-xs text-tertiary mb-1">Name</div>
                    <div class="fw-medium text-sm"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div class="text-xs text-tertiary mb-1">Application number</div>
                    <div class="fw-medium text-sm"><?= e($endorsementRef ?? '-') ?></div>
                </div>
                <div>
                    <div class="text-xs text-tertiary mb-1">Course</div>
                    <div class="fw-medium text-sm"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div class="text-xs text-tertiary mb-1">School Year</div>
                    <div class="fw-medium text-sm"><?= e($applicant['school_year']) ?></div>
                </div>
            </div>
            <p class="text-secondary-sm">
                Admitted by the Admissions office. Proceed to the Enrollment portal for your visit date.
            </p>
        </div>
        <?php endif; ?>

        <p class="text-xs text-tertiary text-center m-0">
            Photocopies and screenshots aren't accepted. Can't make it? Email
            <a href="mailto:sso@plp.edu.ph" class="text-secondary" style="text-decoration:underline">sso@plp.edu.ph</a>
            before your visit date.
        </p>
    </div>

<?php else:
    $resultConfig = [
        'rejected'   => ['class' => 'error',   'title' => 'Not Accepted',     'sub' => 'Your application was not accepted this cycle.'],
    ];
    $cfg = $resultConfig[$result['result']] ?? $resultConfig['rejected'];
?>
    <div class="card withdrawn-card">
        <div class="status-icon-lg" style="background:var(--<?= $cfg['class'] ?>-bg);display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-6)">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 32, 'color:var(--' . $cfg['class'] . ')') ?>
        </div>

        <h2 class="text-2xl fw-semibold mb-2"><?= $cfg['title'] ?></h2>
        <p class="text-secondary mb-6"><?= $cfg['sub'] ?></p>

        <div class="bg-subtle rounded p-5 text-start mb-6">
            <div class="row row-cols-1 row-cols-md-2 gutter-4">
                <div>
                    <div class="label-caps">Name</div>
                    <div class="fw-medium text-sm"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div class="label-caps">Course Applied</div>
                    <div class="fw-medium text-sm"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div class="label-caps">School Year</div>
                    <div class="fw-medium text-sm"><?= e($applicant['school_year']) ?></div>
                </div>
                <div>
                    <div class="label-caps">Result</div>
                    <span class="badge badge-<?= $result['result'] ?>"><?= e(RESULT_LABELS[$result['result']]) ?></span>
                </div>
            </div>
            <?php if ($result['remarks']): ?>
                <div class="mt-4 pt-4 bd-t">
                    <div class="label-caps">Remarks</div>
                    <p class="text-sm text-secondary"><?= e($result['remarks']) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Exam score breakdown removed from the result page.
             Students already see their full score / rank / tier on
             /student/exam right after submitting; repeating it here
             reads like a post-mortem (especially when rejected) and
             distracts from the actual decision. -->

        <!-- Course suggestion from staff -->
        <?php if ($suggestion && $suggestion['status'] === 'pending'): ?>
        <div class="rounded p-5 mb-5" style="border:1.5px solid var(--warning);background:var(--warning-bg)">
            <div class="flex align-items-start gap-3">
                <div class="rounded-circle flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;background:var(--warning-bg)">
                    <?= icon('lightbulb', 16, 'color:#f59e0b') ?>
                </div>
                <div class="flex-1">
                    <div class="fw-semibold text-sm mb-1">Course Suggestion from Admissions</div>
                    <p class="text-sm text-secondary mb-3">
                        Admissions suggested an alternative course:
                    </p>
                    <div class="rounded py-3 px-4 fw-semibold text-sm mb-3" style="background:var(--bg-elevated);border:1px solid var(--warning)">
                        <?= e($suggestion['suggested_course']) ?>
                    </div>
                    <?php if ($suggestion['note']): ?>
                    <p class="text-xs text-secondary mb-3">
                        "<?= e($suggestion['note']) ?>"
                    </p>
                    <?php endif; ?>
                    <p class="text-xs text-tertiary">
                        Visit the admissions office to discuss and update your application.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Auto strand-based alternative course suggestions ── -->
        <?php
        // Show friendly alternative course suggestions when rejected,
        // based on the student's SHS strand and their exam rank score.
        $showAutoSuggest = (
            $result['result'] === 'rejected'
            && !empty($applicant['shs_strand'])
            && !empty($_examResult)
            && (!$suggestion || $suggestion['status'] !== 'pending') // don't double-show if staff already suggested
        );

        if ($showAutoSuggest):
            $studentStrand   = $applicant['shs_strand'];
            $studentRank     = isset($_examResult['rank_score']) && $_examResult['rank_score'] !== null
                ? (int)$_examResult['rank_score']
                : score_to_rank((int)$_examResult['score'], (int)($_examResult['total_items'] ?: 1));
            $altCourses = strand_qualified_courses($studentRank, $applicant['course_applied'], $studentStrand);
        ?>
        <?php if (!empty($altCourses)): ?>
        <div class="rounded p-5 mb-5" style="border:1.5px solid var(--info);background:var(--info-bg)">
            <div class="flex align-items-start gap-3">
                <div class="rounded-circle flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;background:var(--info-bg)">
                    <?= icon('crosshair', 18, 'color:#6366f1') ?>
                </div>
                <div class="flex-1">
                    <div class="fw-semibold text-sm mb-1" style="color:var(--info)">
                        Good News - Other Doors Are Open For You
                    </div>
                    <p class="text-sm text-secondary mb-3">
                        While your score didn't meet the threshold for
                        <strong><?= e($applicant['course_applied']) ?></strong> this time,
                        your results actually qualify you for the following
                        <?= $studentStrand ? '<strong>' . e(SHS_STRANDS[$studentStrand] ?? $studentStrand) . '</strong>-' : '' ?>compatible
                        programs we offer:
                    </p>
                    <ul class="p-0 flex flex-column gap-2" style="margin:0 0 var(--space-4) 0;list-style:none">
                        <?php foreach ($altCourses as $ac): ?>
                        <li class="flex align-items-center gap-3 rounded py-2 px-3" style="background:var(--bg-elevated);border:1px solid var(--info)">
                            <?= icon('check-circle:fill', 14, 'color:#6366f1') ?>
                            <span class="text-sm fw-medium"><?= e($ac) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="rounded p-3 text-xs" style="background:var(--info-bg);color:var(--info)">
                        <strong>What to do next:</strong> Visit the admissions office to apply for one of these programs - no re-exam needed.
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Rejected with no qualifying alternatives — still be kind -->
        <div class="bd bg-subtle rounded p-4 mb-5">
            <p class="text-secondary-sm">
                We understand this isn't the news you hoped for. There are no qualifying alternatives for your strand and
                score this cycle. Visit the admissions office to discuss options for the next admission cycle.
            </p>
        </div>
        <?php endif; ?>
        <?php endif; // end $showAutoSuggest ?>

        <!-- Withdraw is now inside the settings gear at bottom -->

    </div>
<?php endif; ?>

<!-- Step navigation -->
<div class="step-nav">
    <a href="<?= url('/student/interview') ?>" class="btn-icon" aria-label="Back to interview" title="Back to interview"><?= icon('ic_fluent_arrow_left_24_regular', 18) ?></a>
    <span></span>
</div>



<?php
$content     = ob_get_clean();
$pageTitle   = 'Admission Result';
// Shared header slot: shows in every state (withdrawn, pending, admitted, rejected).
$pageActions = ui_button('Print', ['variant' => 'secondary', 'icon' => 'printer', 'href' => url('/print/admission'), 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]);
$activeNav   = 'result';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
