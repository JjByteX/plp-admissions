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

$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/documents'); }
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
// Admitted-applicant context: pull the enrollment schedule SSO set
// in /admin/school-year, and the actual approved documents the
// applicant uploaded so the "bring originals of" list matches what
// they submitted (no hardcoded labels).
// ----------------------------------------------------------------
$enrollmentDate  = school_setting('enrollment_date',  '');
$enrollmentTime  = school_setting('enrollment_time',  '');
$enrollmentVenue = school_setting('enrollment_venue', '');

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
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($isWithdrawn): ?>
<!-- ── Withdrawn state ──────────────────────────────────────── -->
    <div class="card withdrawn-card">
        <div class="status-icon-lg" style="background:var(--bg-subtle)">
            <?= icon('ic_fluent_dismiss_circle_24_regular', 32, 'color:var(--text-tertiary)') ?>
        </div>
        <h2 style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-2);color:var(--text-primary)">Application Withdrawn</h2>
        <p style="color:var(--text-secondary);margin-bottom:var(--space-6)">You have voluntarily withdrawn your application.</p>

        <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-5);text-align:left;margin-bottom:var(--space-6)">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Name</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Course Applied</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Withdrawn On</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)">
                        <?= $applicant['withdrawn_at'] ? format_date($applicant['withdrawn_at'], 'F j, Y') : '—' ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Status</div>
                    <span style="display:inline-flex;align-items:center;gap:var(--space-1);padding:var(--space-1) var(--space-2);border-radius:var(--radius-full);font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-secondary);background:var(--bg-subtle)">Withdrawn</span>
                </div>
            </div>
            <?php if ($applicant['withdrawn_reason']): ?>
                <div style="margin-top:var(--space-4);padding-top:var(--space-4);border-top:1px solid var(--border)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Reason Given</div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary)"><?= e($applicant['withdrawn_reason']) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <p style="font-size:var(--text-sm);color:var(--text-tertiary)">
            If you think this is a mistake, visit the admissions office in person.
        </p>
    </div>

<?php elseif (!$result): ?>
<!-- ── No result yet ────────────────────────────────────────── -->
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary)">
        <?= icon('warning-circle:fill', 48, 'color:var(--text-tertiary);margin-bottom:var(--space-4)') ?>
        <p style="font-weight:var(--weight-medium)">Result not yet released</p>
        <p style="font-size:var(--text-sm);margin-top:var(--space-1)">You'll be notified once your result is ready.</p>
    </div>

<?php elseif ($result['result'] === 'accepted'): ?>
<!-- ── Admitted ─────────────────────────────────────────────── -->
<!--
  Replaces the old generic "Accepted" pill. The applicant has been
  ADMITTED, not yet officially enrolled. They finalize enrollment by
  bringing originals of every document they uploaded so SSO can
  verify them against the scans. The schedule below is set by SSO
  on /admin/school-year (enrollment_date / time / venue).
-->
    <div class="card" style="padding:var(--space-6)">
        <div style="text-align:center;margin-bottom:var(--space-6)">
            <div class="status-icon-lg" style="background:var(--success-bg);display:inline-flex;align-items:center;justify-content:center;margin-bottom:var(--space-4)">
                <?= icon('ic_fluent_checkmark_circle_24_regular', 32, 'color:var(--success)') ?>
            </div>
            <h2 style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-2)">You've been admitted.</h2>
            <p style="color:var(--text-secondary);margin:0;font-size:var(--text-sm)">
                <strong><?= e($applicant['course_applied']) ?></strong>
                <?php if ($applicantCollege): ?>
                    · <?= e($applicantCollege) ?>
                <?php endif; ?>
                · SY <?= e($applicant['school_year']) ?>
            </p>
        </div>

        <p style="font-size:var(--text-sm);color:var(--text-secondary);text-align:center;margin-bottom:var(--space-6);max-width:520px;margin-left:auto;margin-right:auto">
            One step left — bring the <strong>original copies</strong> of your documents on the date below so we can verify them. After that, you're officially enrolled.
        </p>

        <!-- Enrollment schedule -->
        <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);font-weight:var(--weight-semibold);margin-bottom:var(--space-3)">Enrollment Schedule</div>
            <?php if ($enrollmentDate && $enrollmentTime && $enrollmentVenue): ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-3)">
                    <div>
                        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-1)">Date</div>
                        <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e(format_date($enrollmentDate, 'l, F j, Y')) ?></div>
                    </div>
                    <div>
                        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-1)">Time</div>
                        <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e(date('g:i A', strtotime($enrollmentTime))) ?></div>
                    </div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-1)">Venue</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($enrollmentVenue) ?></div>
                </div>
            <?php else: ?>
                <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin:0">
                    The admissions office hasn't posted the enrollment schedule yet — check back soon, or watch your email for the announcement.
                </p>
            <?php endif; ?>
        </div>

        <!-- Documents to bring -->
        <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">Bring the originals of</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-3)">matches what you uploaded</div>
            <?php if (!empty($myDocs)): ?>
                <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:var(--space-2)">
                    <?php foreach ($myDocs as $d):
                        $slug  = (string)$d['doc_type'];
                        $label = $docLabels[$slug] ?? ucwords(str_replace('_', ' ', $slug));
                    ?>
                        <li style="display:flex;align-items:flex-start;gap:var(--space-3);background:white;border:1px solid var(--border);border-radius:var(--radius-sm);padding:var(--space-2) var(--space-3)">
                            <?= icon('ic_fluent_document_24_regular', 16, 'color:var(--text-tertiary);flex-shrink:0;margin-top:var(--space-1)') ?>
                            <span style="font-size:var(--text-sm);line-height:1.4"><?= e($label) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin:0">
                    Bring originals of every document you submitted with your application.
                </p>
            <?php endif; ?>
        </div>

        <p style="font-size:var(--text-xs);color:var(--text-tertiary);text-align:center;margin:0">
            Photocopies and screenshots aren't accepted. Can't make it? Email
            <a href="mailto:sso@plp.edu.ph" style="color:var(--text-secondary);text-decoration:underline">sso@plp.edu.ph</a>
            before the date.
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

        <h2 style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);margin-bottom:var(--space-2)"><?= $cfg['title'] ?></h2>
        <p style="color:var(--text-secondary);margin-bottom:var(--space-6)"><?= $cfg['sub'] ?></p>

        <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-5);text-align:left;margin-bottom:var(--space-6)">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Name</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Course Applied</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">School Year</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($applicant['school_year']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Result</div>
                    <span class="badge badge-<?= $result['result'] ?>"><?= e(RESULT_LABELS[$result['result']]) ?></span>
                </div>
            </div>
            <?php if ($result['remarks']): ?>
                <div style="margin-top:var(--space-4);padding-top:var(--space-4);border-top:1px solid var(--border)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-1)">Remarks</div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary)"><?= e($result['remarks']) ?></p>
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
        <div style="border:1.5px solid #f59e0b;background:#fffbeb;border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="display:flex;align-items:flex-start;gap:var(--space-3)">
                <div style="width:36px;height:36px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('lightbulb', 16, 'color:#f59e0b') ?>
                </div>
                <div style="flex:1">
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:var(--space-1)">Course Suggestion from Admissions</div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-3)">
                        Admissions suggested an alternative course:
                    </p>
                    <div style="background:white;border:1px solid #fde68a;border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:var(--space-3)">
                        <?= e($suggestion['suggested_course']) ?>
                    </div>
                    <?php if ($suggestion['note']): ?>
                    <p style="font-size:var(--text-xs);color:var(--text-secondary);margin-bottom:var(--space-3)">
                        "<?= e($suggestion['note']) ?>"
                    </p>
                    <?php endif; ?>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary)">
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
        <div style="border:1.5px solid #6366f1;background:#eef2ff;border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="display:flex;align-items:flex-start;gap:var(--space-3)">
                <div style="width:36px;height:36px;border-radius:50%;background:#e0e7ff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('crosshair', 18, 'color:#6366f1') ?>
                </div>
                <div style="flex:1">
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:#4338ca;margin-bottom:var(--space-1)">
                        Good News — Other Doors Are Open For You
                    </div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-3)">
                        While your score didn't meet the threshold for
                        <strong><?= e($applicant['course_applied']) ?></strong> this time,
                        your results actually qualify you for the following
                        <?= $studentStrand ? '<strong>' . e(SHS_STRANDS[$studentStrand] ?? $studentStrand) . '</strong>-' : '' ?>compatible
                        programs we offer:
                    </p>
                    <ul style="margin:0 0 var(--space-4) 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:var(--space-2)">
                        <?php foreach ($altCourses as $ac): ?>
                        <li style="display:flex;align-items:center;gap:var(--space-3);
                                   background:white;border:1px solid #c7d2fe;border-radius:var(--radius-md);
                                   padding:var(--space-2) var(--space-3)">
                            <?= icon('check-circle:fill', 14, 'color:#6366f1') ?>
                            <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($ac) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="background:#e0e7ff;border-radius:var(--radius-sm);padding:var(--space-3);font-size:var(--text-xs);color:#3730a3">
                        <strong>What to do next:</strong> Visit the admissions office to apply for one of these programs — no re-exam needed.
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Rejected with no qualifying alternatives — still be kind -->
        <div style="border:1px solid var(--border);background:var(--bg-subtle);border-radius:var(--radius-md);
                    padding:var(--space-4);margin-bottom:var(--space-5)">
            <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
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
    <a href="<?= url('/student/interview') ?>" class="btn btn-ghost">← Back</a>
    <span></span>
</div>



<?php
$content     = ob_get_clean();
$pageTitle   = 'Admission Result';
$activeNav   = 'result';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
