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