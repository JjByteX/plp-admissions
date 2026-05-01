<?php
// ============================================================
// modules/results/student_view.php
// M6 — Student: view admission result
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

ob_start();
?>

<?php if (!$result): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary)">
        <svg width="48" height="48" fill="none" viewBox="0 0 24 24" style="color:var(--neutral-300);margin-bottom:var(--space-4)">
            <path stroke="currentColor" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p style="font-weight:var(--weight-medium)">Result not yet released</p>
        <p style="font-size:var(--text-sm);margin-top:4px">Your admission result has not been released yet. You will be notified once it is ready.</p>
    </div>
<?php else:
    $resultConfig = [
        'accepted'   => ['class' => 'success', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'title' => 'Congratulations!', 'sub' => 'You have been accepted.'],
        'waitlisted' => ['class' => 'warning', 'icon' => 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'title' => 'Waitlisted', 'sub' => 'You are on the waitlist.'],
        'rejected'   => ['class' => 'error',   'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z', 'title' => 'Not Accepted', 'sub' => 'Your application was not accepted this cycle.'],
    ];
    $cfg = $resultConfig[$result['result']] ?? $resultConfig['rejected'];
?>
    <div class="card" style="padding:var(--space-8);text-align:center;max-width:480px;margin:0 auto">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--<?= $cfg['class'] ?>-bg);display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-6)">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 32, 'color:var(--' . $cfg['class'] . ')') ?><!--
                <path stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="<?= $cfg['icon'] ?>"/>
            </svg>
        </div>

        <h2 style="font-size:var(--text-2xl);font-weight:var(--weight-bold);margin-bottom:var(--space-2)"><?= $cfg['title'] ?></h2>
        <p style="color:var(--text-secondary);margin-bottom:var(--space-6)"><?= $cfg['sub'] ?></p>

        <div style="background:var(--neutral-50);border-radius:var(--radius-md);padding:var(--space-5);text-align:left;margin-bottom:var(--space-6)">
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

        <!-- Exam score breakdown (always shown if available) -->
        <?php if ($_examResult): ?>
        <?php
            $eRank   = $_examResult['rank_score'] > 0 ? (int)$_examResult['rank_score']
                       : score_to_rank((int)$_examResult['score'], (int)($_examResult['total_items'] ?: 1));
            $eTier   = rank_tier_info($eRank);
            $ePassed = $_examResult['passed'] !== null ? (bool)$_examResult['passed']
                       : exam_passed((int)$_examResult['score'], (int)($_examResult['total_items'] ?: 1), $applicant['course_applied']);
            $ePct    = $_examResult['total_items'] > 0 ? round(($_examResult['score'] / $_examResult['total_items']) * 100) : 0;
        ?>
        <div style="background:var(--neutral-50);border-radius:var(--radius-md);padding:var(--space-5);margin-bottom:var(--space-5)">
            <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-3)">Entrance Exam Result</div>
            <div style="display:flex;align-items:center;gap:var(--space-4);flex-wrap:wrap">
                <!-- Rank circle -->
                <div style="width:56px;height:56px;border-radius:50%;
                            background:<?= $eTier['bg'] ?>;border:2px solid <?= $eTier['color'] ?>;
                            display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
                    <span style="font-size:1.3rem;font-weight:var(--weight-bold);color:<?= $eTier['color'] ?>;line-height:1"><?= $eRank ?></span>
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
                            <span style="font-size:var(--text-xs);color:#22c55e;font-weight:var(--weight-semibold)">✓ Passed</span>
                        <?php else: ?>
                            <span style="font-size:var(--text-xs);color:#ef4444;font-weight:var(--weight-semibold)">✗ Did not pass</span>
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