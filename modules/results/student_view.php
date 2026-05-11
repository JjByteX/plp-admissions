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
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Name</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Course Applied</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Withdrawn On</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)">
                        <?= $applicant['withdrawn_at'] ? format_date($applicant['withdrawn_at'], 'F j, Y') : '—' ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Status</div>
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:9999px;font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-secondary);background:var(--bg-subtle)">Withdrawn</span>
                </div>
            </div>
            <?php if ($applicant['withdrawn_reason']): ?>
                <div style="margin-top:var(--space-4);padding-top:var(--space-4);border-top:1px solid var(--border)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Reason Given</div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary)"><?= e($applicant['withdrawn_reason']) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <p style="font-size:var(--text-sm);color:var(--text-tertiary)">
            If you believe this was done in error, please visit the admissions office in person to discuss your options.
        </p>
    </div>

<?php elseif (!$result): ?>
<!-- ── No result yet ────────────────────────────────────────── -->
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary)">
        <svg width="48" height="48" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);margin-bottom:var(--space-4)">
            <path stroke="currentColor" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p style="font-weight:var(--weight-medium)">Result not yet released</p>
        <p style="font-size:var(--text-sm);margin-top:4px">Your admission result has not been released yet. You will be notified once it is ready.</p>
    </div>

<?php else:
    $resultConfig = [
        'accepted'   => ['class' => 'success', 'title' => 'Congratulations!', 'sub' => 'You have been accepted.'],
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
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Name</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e(Auth::user()['name']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Course Applied</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($applicant['course_applied']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">School Year</div>
                    <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)"><?= e($applicant['school_year']) ?></div>
                </div>
                <div>
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Result</div>
                    <span class="badge badge-<?= $result['result'] ?>"><?= e(RESULT_LABELS[$result['result']]) ?></span>
                </div>
            </div>
            <?php if ($result['remarks']): ?>
                <div style="margin-top:var(--space-4);padding-top:var(--space-4);border-top:1px solid var(--border)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Remarks</div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary)"><?= e($result['remarks']) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Exam score breakdown -->
        <?php if ($_examResult): ?>
        <?php
            $eRank   = $_examResult['rank_score'] > 0 ? (int)$_examResult['rank_score']
                       : score_to_rank((int)$_examResult['score'], (int)($_examResult['total_items'] ?: 1));
            $eTier   = rank_tier_info($eRank);
            $ePassed = $_examResult['passed'] !== null ? (bool)$_examResult['passed']
                       : exam_passed((int)$_examResult['score'], (int)($_examResult['total_items'] ?: 1), $applicant['course_applied']);
            $ePct    = $_examResult['total_items'] > 0 ? round(($_examResult['score'] / $_examResult['total_items']) * 100) : 0;
        ?>
        <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-3)">Entrance Exam Result</div>
            <div style="display:flex;align-items:center;gap:var(--space-4);flex-wrap:wrap">
                <div style="width:56px;height:56px;border-radius:50%;
                            background:<?= $eTier['bg'] ?>;border:2px solid <?= $eTier['color'] ?>;
                            display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
                    <span style="font-size:1.3rem;font-weight:var(--weight-semibold);color:<?= $eTier['color'] ?>;line-height:1"><?= $eRank ?></span>
                    <span style="font-size:9px;color:<?= $eTier['color'] ?>">/10</span>
                </div>
                <div style="flex:1">
                    <div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:4px;flex-wrap:wrap">
                        <span style="font-weight:var(--weight-semibold);font-size:var(--text-sm)">
                            <?= (int)$_examResult['score'] ?> / <?= (int)$_examResult['total_items'] ?> &nbsp;(<?= $ePct ?>%)
                        </span>
                        <span style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:<?= $eTier['color'] ?>">
                            <?= $eTier['label'] ?> Tier
                        </span>
                        <?php if ($ePassed): ?>
                            <span style="font-size:var(--text-xs);color:var(--success);font-weight:var(--weight-semibold)"><?= icon('ic_fluent_checkmark_24_regular', 12) ?> Passed</span>
                        <?php else: ?>
                            <span style="font-size:var(--text-xs);color:var(--error);font-weight:var(--weight-semibold)"><?= icon('ic_fluent_dismiss_24_regular', 12) ?> Did not pass</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        Course: <?= e($applicant['course_applied']) ?> &nbsp;·&nbsp;
                        Passing rank: ≥ <?= get_pass_threshold($applicant['course_applied']) ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Course suggestion from staff -->
        <?php if ($suggestion && $suggestion['status'] === 'pending'): ?>
        <div style="border:1.5px solid #f59e0b;background:#fffbeb;border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="display:flex;align-items:flex-start;gap:var(--space-3)">
                <div style="width:36px;height:36px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path stroke="#f59e0b" stroke-width="2" stroke-linecap="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3M6.343 6.343l-.707-.707M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>
                </div>
                <div style="flex:1">
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:4px">Course Suggestion from Admissions</div>
                    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-3)">
                        The admissions office has suggested an alternative course based on your exam results:
                    </p>
                    <div style="background:white;border:1px solid #fde68a;border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:var(--space-3)">
                        <?= e($suggestion['suggested_course']) ?>
                    </div>
                    <?php if ($suggestion['note']): ?>
                    <p style="font-size:var(--text-xs);color:var(--text-secondary);font-style:italic;margin-bottom:var(--space-3)">
                        "<?= e($suggestion['note']) ?>"
                    </p>
                    <?php endif; ?>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        Please visit the admissions office to discuss this recommendation and update your application if you wish to proceed.
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
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                              d="M12 2a10 10 0 100 20A10 10 0 0012 2zm0 0v4m0 8v4m-4-8h8"/>
                        <circle cx="12" cy="12" r="2" fill="#6366f1"/>
                    </svg>
                </div>
                <div style="flex:1">
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:#4338ca;margin-bottom:4px">
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
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="flex-shrink:0">
                                <path stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>
                                <circle cx="12" cy="12" r="10" stroke="#6366f1" stroke-width="2"/>
                            </svg>
                            <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($ac) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="background:#e0e7ff;border-radius:var(--radius-sm);padding:var(--space-3);font-size:var(--text-xs);color:#3730a3">
                        <strong>What to do next:</strong> Visit the admissions office and let them know you're interested in
                        exploring one of the programs above. They'll be happy to walk you through the transfer process — no
                        need to re-take the exam for these courses.
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Rejected with no qualifying alternatives — still be kind -->
        <div style="border:1px solid var(--border);background:var(--bg-subtle);border-radius:var(--radius-md);
                    padding:var(--space-4);margin-bottom:var(--space-5)">
            <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
                We understand this isn't the news you were hoping for, and we truly appreciate the effort you put in.
                While there are no qualifying alternatives available based on your current strand and score, we
                encourage you to visit the admissions office — they can advise you on options for the next admission cycle.
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
