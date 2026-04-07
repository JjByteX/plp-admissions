<?php
// ============================================================
// views/partials/stepper.php
// M7 — Applicant Progress Tracker
// Requires $stepperData array passed from the module:
//   ['current' => 'documents', 'steps' => [...]]
// ============================================================

$steps = [
    'register'  => 'Register',
    'documents' => 'Submit Documents',
    'exam'      => 'Entrance Exam',
    'interview' => 'Interview',
    'result'    => 'Result',
];

$currentStep = $stepperCurrent ?? 'documents';

// Determine state for each step
$stepKeys = array_keys($steps);
$currentIndex = array_search($currentStep, $stepKeys);

function stepState(int $idx, int $currentIdx): string {
    if ($idx < $currentIdx)  return 'done';
    if ($idx === $currentIdx) return 'active';
    return 'locked';
}

// Step-to-URL map (students only)
$stepUrls = [
    'register'  => null,
    'documents' => url('/student/documents'),
    'exam'      => url('/student/exam'),
    'interview' => url('/student/interview'),
    'result'    => url('/student/result'),
];
?>
<div class="stepper" aria-label="Admission progress">
    <?php foreach ($steps as $key => $label):
        $idx   = array_search($key, $stepKeys);
        $state = stepState($idx, $currentIndex);
        $href  = ($state === 'done' && $stepUrls[$key]) ? $stepUrls[$key] : null;
    ?>

        <?php if ($idx > 0): ?>
            <div class="step-connector <?= $state === 'done' || ($idx <= $currentIndex) ? 'done' : '' ?>"></div>
        <?php endif; ?>

        <div class="step <?= $state ?>" role="listitem" aria-label="<?= e($label) ?>: <?= $state ?>">
            <?php if ($href): ?>
                <a href="<?= $href ?>" class="step-dot" title="Go to <?= e($label) ?>">
            <?php else: ?>
                <div class="step-dot">
            <?php endif; ?>

                <?php if ($state === 'done'): ?>
                    <!-- Checkmark -->
                    <svg viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <?php elseif ($state === 'active'): ?>
                    <!-- Dot -->
                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="4"/></svg>
                <?php else: ?>
                    <!-- Lock -->
                    <svg viewBox="0 0 24 24" fill="none"><rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                <?php endif; ?>

            <?php if ($href): ?></a><?php else: ?></div><?php endif; ?>

            <span class="step-label"><?= e($label) ?></span>
        </div>

    <?php endforeach; ?>
</div>
