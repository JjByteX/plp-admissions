<?php
// ============================================================
// modules/auth/student/dashboard.php
// Student home — shows current step, summary cards
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$userId = Auth::id();

// Fetch applicant record
$stmt = db()->prepare(
    'SELECT * FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$userId]);
$applicant = $stmt->fetch();

if (!$applicant) {
    // Edge case — account exists but no applicant row
    Session::flash('error', 'Your application record was not found. Please contact the admissions office.');
    Auth::logout();
}

$applicantId = $applicant['id'];

// Document status summary
$docStmt = db()->prepare(
    'SELECT status, COUNT(*) as cnt FROM documents WHERE applicant_id = ? GROUP BY status'
);
$docStmt->execute([$applicantId]);
$docCounts = array_column($docStmt->fetchAll(), 'cnt', 'status');
$totalDocs    = array_sum($docCounts);
$approvedDocs = (int)($docCounts['approved'] ?? 0);
$rejectedDocs = (int)($docCounts['rejected'] ?? 0);

// Exam result
$examResult = db()->prepare(
    'SELECT * FROM exam_results WHERE applicant_id = ? LIMIT 1'
);
$examResult->execute([$applicantId]);
$examResult = $examResult->fetch() ?: null;

// Interview slot
$slotStmt = db()->prepare(
    'SELECT * FROM interview_slots WHERE assigned_applicant_id = ? LIMIT 1'
);
$slotStmt->execute([$applicantId]);
$interviewSlot = $slotStmt->fetch() ?: null;

// Admission result
$resultStmt = db()->prepare(
    'SELECT * FROM admission_results WHERE applicant_id = ? LIMIT 1'
);
$resultStmt->execute([$applicantId]);
$admissionResult = $resultStmt->fetch() ?: null;

// Determine stepper position
$stepperCurrent = current_step($applicant, $examResult, $interviewSlot, $admissionResult);

// -- View -------------------------------------------------------
ob_start();
?>

<div class="page-header">
    <h1 class="page-title">Hello, <?= e(explode(' ', Auth::user()['name'])[0]) ?> 👋</h1>
    <p class="page-description">
        <?= e(school_setting('school_name', 'PLP')) ?> &middot;
        <?= e($applicant['school_year']) ?> &middot;
        <strong><?= e($applicant['course_applied']) ?></strong>
    </p>
</div>

<!-- Overall status banner -->
<?php if ($admissionResult): ?>
    <?php $resultClass = match($admissionResult['result']) {
        'accepted'   => 'alert-success',
        'waitlisted' => 'alert-warning',
        default      => 'alert-error',
    }; ?>
    <div class="alert <?= $resultClass ?>" style="margin-bottom:var(--space-6)">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div>
            <strong>Your admission result is ready.</strong>
            <a href="<?= url('/student/result') ?>" style="margin-left:var(--space-2)">View result →</a>
        </div>
    </div>
<?php endif; ?>

<!-- Quick status cards -->
<div class="metrics-row" style="margin-bottom:var(--space-6)">
    <div class="metric-card">
        <div class="metric-label">Documents</div>
        <div class="metric-value"><?= $approvedDocs ?><span style="font-size:var(--text-xl);color:var(--text-tertiary)">/<?= $totalDocs ?></span></div>
        <div class="metric-sub">Approved</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Entrance Exam</div>
        <div class="metric-value" style="font-size:var(--text-xl)">
            <?php if ($examResult): ?>
                <?= $examResult['score'] ?>/<?= $examResult['total_items'] ?>
            <?php else: ?>
                <span style="color:var(--text-tertiary)">—</span>
            <?php endif; ?>
        </div>
        <div class="metric-sub"><?= $examResult ? 'Completed' : 'Not yet taken' ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Interview</div>
        <div class="metric-value" style="font-size:var(--text-xl)">
            <?php if ($interviewSlot): ?>
                <?= format_date($interviewSlot['slot_date'], 'M j') ?>
            <?php else: ?>
                <span style="color:var(--text-tertiary)">—</span>
            <?php endif; ?>
        </div>
        <div class="metric-sub"><?= $interviewSlot ? ucfirst(str_replace('_',' ',$interviewSlot['status'])) : 'Not scheduled' ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Application</div>
        <div class="metric-value" style="font-size:var(--text-base);padding-top:4px">
            <span class="badge badge-<?= $admissionResult ? $admissionResult['result'] : 'pending' ?>">
                <?= $admissionResult ? RESULT_LABELS[$admissionResult['result']] : 'In Progress' ?>
            </span>
        </div>
        <div class="metric-sub"><?= e($applicant['school_year']) ?></div>
    </div>
</div>

<!-- Next step CTA -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">What to do next</div>
            <div class="card-description">Complete each step in order to finish your application.</div>
        </div>
    </div>

    <?php
    // Determine the actionable next step
    if ($admissionResult): ?>
        <div style="display:flex;align-items:center;gap:var(--space-4)">
            <div style="flex:1">
                <p style="font-size:var(--text-sm);color:var(--text-secondary)">
                    Your admission result has been released. Check your dashboard for the outcome and next steps.
                </p>
            </div>
            <a href="<?= url('/student/result') ?>" class="btn btn-primary">View my result</a>
        </div>

    <?php elseif ($interviewSlot && $interviewSlot['status'] === 'scheduled'): ?>
        <div style="display:flex;align-items:center;gap:var(--space-4)">
            <div style="flex:1">
                <p style="font-weight:var(--weight-medium);color:var(--text-primary)">Interview scheduled</p>
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">
                    <?= format_date($interviewSlot['slot_date']) ?> at <?= format_time($interviewSlot['slot_time']) ?>
                </p>
            </div>
            <a href="<?= url('/student/interview') ?>" class="btn btn-secondary">View details</a>
        </div>

    <?php elseif ($examResult): ?>
        <p style="font-size:var(--text-sm);color:var(--text-secondary)">
            Entrance exam completed. Waiting for staff to schedule your interview.
        </p>

    <?php elseif ($applicant['overall_status'] === 'exam'): ?>
        <div style="display:flex;align-items:center;gap:var(--space-4)">
            <div style="flex:1">
                <p style="font-weight:var(--weight-medium);color:var(--text-primary)">Entrance exam unlocked</p>
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">All documents approved. You can now take the entrance exam.</p>
            </div>
            <a href="<?= url('/student/exam') ?>" class="btn btn-primary">Take exam</a>
        </div>

    <?php elseif ($rejectedDocs > 0): ?>
        <div style="display:flex;align-items:center;gap:var(--space-4)">
            <div style="flex:1">
                <p style="font-weight:var(--weight-medium);color:var(--error)"><?= $rejectedDocs ?> document(s) rejected</p>
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">Please re-upload the rejected documents with the correct files.</p>
            </div>
            <a href="<?= url('/student/documents') ?>" class="btn btn-danger">Fix documents</a>
        </div>

    <?php else: ?>
        <div style="display:flex;align-items:center;gap:var(--space-4)">
            <div style="flex:1">
                <p style="font-weight:var(--weight-medium);color:var(--text-primary)">Upload your documents</p>
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">
                    <?= $approvedDocs ?> of <?= $totalDocs ?> documents approved. Upload and submit all required documents to proceed.
                </p>
            </div>
            <a href="<?= url('/student/documents') ?>" class="btn btn-primary">Go to documents</a>
        </div>
    <?php endif; ?>
</div>

<?php
$content        = ob_get_clean();
$pageTitle      = 'Dashboard';
$activeNav      = 'dashboard';
$showStepper    = true;
include VIEWS_PATH . '/layouts/app.php';
