<?php
// ============================================================
// modules/exam/_take_view.php
// View for the exam-taking screen. Included by modules/exam/take.php after its
// controller logic, inside the ob_start() buffer, so it shares that
// file's variables. HTML only: no request handling lives here.
// ============================================================
?>


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

<div class="alert alert-warning mb-6">
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
        <span class="text-xs text-nowrap" style="color:var(--text-tertiary)" id="answered-label">0 / <?= count($questions) ?> answered</span>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progress-fill"></div>
        </div>
    </div>

    <div id="exam-content" class="flex flex-column gap-4">
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

    <div class="mt-8 flex justify-content-between align-items-center">
        <div class="text-sm" style="color:var(--text-tertiary)" id="answered-count">
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
            <p class="text-sm" style="color:var(--text-secondary)" id="confirm-message">
                Once submitted, your answers cannot be changed.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('confirm-modal').style.display='none'">Go Back</button>
            <button class="btn btn-primary" id="confirm-yes" onclick="document.getElementById('exam-form').submit()">Yes, Submit</button>
        </div>
    </div>
</div>

<div id="exam-status-msg" class="alert alert-warning m-0" role="status"
     style="display:none;position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:1000;max-width:92vw"></div>

<?php
// Closes at the slot's explicit end_time (per-room window).
$slotWindow       = slot_window($mySlot);
$secondsRemaining = max(0, $slotWindow['closes']->getTimestamp() - time());
?>
<script>window.PAGE = <?= json_encode([
    'total'            => count($questions),
    'secondsRemaining' => $secondsRemaining,
    'examId'           => (int)$examId,
    'slotId'           => (int)($mySlot['id'] ?? 0),
    'userId'           => (int)$userId,
    'draftKey'         => $draftKey,
    'serverDraft'      => $serverDraft,
    'saveUrl'          => url('/api/exam-autosave'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

<!-- Step navigation -->
<div class="step-nav mt-4">
    <a href="<?= url('/student/documents') ?>" class="btn btn-ghost"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Documents</a>
    <span></span>
</div>

