<?php
// ============================================================
// modules/exam/_manage_view.php
// View for the exam manager screen. Included by modules/exam/staff_manage.php after its
// controller logic, inside the ob_start() buffer, so it shares that
// file's variables. HTML only: no request handling lives here.
// ============================================================
?>


<?php foreach ($errors as $e): ?>
    <div class="alert alert-error mb-3"><?= e($e) ?></div>
<?php endforeach; ?>
<?php if (!empty($examFrozen)): ?>
    <div class="alert alert-warning mb-3">This exam already has submitted results. You can still fix wording, but questions, choices, points and answer keys are locked so recorded scores stay accurate. To change the content, create a new exam.</div>
<?php endif; ?>
<?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success mb-3">Exam created and set as active.</div>
<?php endif; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success mb-3"><?= e($s) ?></div>
<?php endforeach; ?>

<?php
// ── View routing ──────────────────────────────────────────────
$view = 'landing';
if (isset($_GET['view']) && $_GET['view'] === 'exams') $view = 'exams';
elseif ($selectedExamId) $view = 'editor';
?>

<?php /* ════════════════════════════════════════════════════
   LANDING - two big entry-point cards
════════════════════════════════════════════════════ */ ?>
<?php if ($view === 'landing'): ?>

<div class="select-grid">

    <!-- Exam Builder — always enabled -->
    <a href="<?= url('/staff/exam') ?>?view=exams" class="select-card">
        <div class="select-card-title">Exam Builder</div>
        <div class="select-card-desc">Create exams, set questions, and manage schedules.</div>
        <div class="select-card-meta">
            <div class="select-card-meta-row">
                <?= count($exams) ?> exam<?= count($exams) !== 1 ? 's' : '' ?> created
                <?php if ($activeExam): ?>
                    &nbsp;&middot;&nbsp;
                    <span class="text-success fw-medium"><?= e($activeExam['title']) ?> is active</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- Room Slots — disabled if no exams set up yet -->
    <?php if (!empty($exams)): ?>
    <a href="<?= url('/staff/exam/slots') ?>" class="select-card">
    <?php else: ?>
    <div class="select-card is-disabled" title="Create an exam first before setting up room slots">
    <?php endif; ?>
        <div class="select-card-title">Room Slots</div>
        <div class="select-card-desc">Assign rooms, manage seating, and organize exam-day logistics.</div>
        <div class="select-card-meta">
            <div class="select-card-meta-row">
                <?php if (empty($exams)): ?>
                    Create an exam first
                <?php else: ?>
                    <?= $slotCount ?> slot<?= $slotCount !== 1 ? 's' : '' ?> configured
                <?php endif; ?>
            </div>
        </div>
    <?php if (!empty($exams)): ?>
    </a>
    <?php else: ?>
    </div>
    <?php endif; ?>

</div>

<?php /* ════════════════════════════════════════════════════
   EXAM DIRECTORY - card grid of all exams
════════════════════════════════════════════════════ */ ?>
<?php elseif ($view === 'exams'): ?>

<div class="select-grid exam-dir-grid">
    <?php foreach ($exams as $ex):
        $qCount = $examMeta[$ex['id']]['question_count'] ?? 0;
        $isActive = (bool)$ex['is_active'];
    ?>
        <a href="<?= url('/staff/exam') ?>?exam=<?= $ex['id'] ?>" class="select-card exam-dir-card <?= $isActive ? 'is-active' : '' ?>">
            <?php if ($isActive): ?>
                <div class="select-card-badge"><span class="badge badge-success">Active</span></div>
            <?php endif; ?>
            <div class="select-card-title"><?= e($ex['title']) ?></div>
            <div class="select-card-meta">
                <div class="select-card-meta-row">
                    <?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?>
                </div>
            </div>
            <div class="select-card-footer">
                <span>Created <?= e(format_date($ex['created_at'] ?? null, 'M j, Y')) ?></span>
                <span class="select-card-action">
                    Edit
                </span>
            </div>
        </a>
    <?php endforeach; ?>

    <!-- New Exam card (always last in grid) -->
    <div class="select-card select-card--add exam-dir-card" onclick="openModal('create-exam-modal')"
         role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <div class="select-card-title"><?= empty($exams) ? 'Create Your First Exam' : 'New Exam' ?></div>
        <div class="select-card-desc"><?= empty($exams) ? 'Get started by creating an entrance exam.' : 'Build a new entrance exam' ?></div>
    </div>
</div>

<?php /* ════════════════════════════════════════════════════
   EXAM EDITOR - full question builder for a selected exam
════════════════════════════════════════════════════ */ ?>
<?php else: ?>

<?php if ($selectedExam): ?>

        <!-- Exam header card: read-only auto-derived title + editable description and shuffle flags. -->

        <form method="POST" id="exam-edit-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="edit_exam">
            <input type="hidden" name="exam_id"  value="<?= $selectedExam['id'] ?>">

            <div class="card mb-5 p-4" id="exam-header-card" data-edit-state="read"
                >
                <div class="flex align-items-start gap-4">
                    <div class="flex-1 min-w-0">

                        <div class="exam-inline exam-inline--title py-2 px-3"
                             style="border:1px solid transparent">
                            <?= e($selectedExam['title']) ?>
                        </div>
                        <div class="exam-show-edit text-xs text-tertiary" style="margin:4px 0 var(--space-2)">
                            Title is set automatically from the active school year. Change it on the
                            <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                        </div>

                        <textarea class="exam-inline exam-inline--desc" name="description" rows="1" aria-label="Description"
                                  placeholder="Add description (optional)"
                                  data-original="<?= e($selectedExam['description'] ?? '') ?>"
                                  readonly><?= e($selectedExam['description'] ?? '') ?></textarea>

                        <div class="exam-show-read text-xs text-tertiary mt-2 flex gap-3 flex-wrap align-items-center">
                            <span><?= count($questions) ?> questions</span>
                            <span aria-hidden="true">·</span>
                            <span><?= $totalPoints ?> total points</span>
                            <?php if ($selectedExam['shuffle_questions']): ?>
                                <span aria-hidden="true">·</span>
                                <span class="inline-flex align-items-center gap-1">
                                    <?= icon('ic_fluent_arrow_sync_24_regular', 12) ?>
                                    Shuffled
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="exam-show-edit flex gap-4 flex-wrap mt-3">
                            <label class="inline-flex align-items-center pointer text-sm" style="gap:6px">
                                <input type="checkbox" name="shuffle_questions"
                                       data-original-checked="<?= $selectedExam['shuffle_questions'] ? '1' : '0' ?>"
                                       class="accent-c" <?= $selectedExam['shuffle_questions'] ? 'checked' : '' ?>>
                                Shuffle questions
                            </label>
                            <label class="inline-flex align-items-center pointer text-sm" style="gap:6px">
                                <input type="checkbox" name="shuffle_choices"
                                       data-original-checked="<?= $selectedExam['shuffle_choices'] ? '1' : '0' ?>"
                                       class="accent-c" <?= $selectedExam['shuffle_choices'] ? 'checked' : '' ?>>
                                Shuffle choices
                            </label>
                        </div>
                    </div>

                    <div class="flex gap-2 flex-shrink-0 align-items-center flex-wrap justify-content-end">
                        <button type="button" class="btn btn-ghost btn-sm exam-show-read"
                                onclick="enterExamEditMode()">Edit</button>
                        <button type="button" class="btn btn-ghost btn-sm exam-show-edit"
                                onclick="exitExamEditMode()">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm exam-show-edit">Save</button>

                        <?php if (!$selectedExam['is_active']): ?>
                            <button type="button" class="btn btn-secondary btn-sm exam-show-read"
                                    onclick="document.getElementById('activate-exam-form').submit()">Set Active</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm exam-show-read text-error"
                                style="border-color:var(--error);background:transparent"
                                data-confirm="Delete this exam? All questions will be removed." data-confirm-danger data-confirm-ok="Delete"
                                onclick="document.getElementById('delete-exam-form').submit();">Delete</button>
                    </div>
                </div>

                <?php if (!empty($resultStats) && $resultStats['total'] > 0): ?>
                    <div class="flex gap-6 mt-4 pt-4 bd-t">
                        <div><div class="text-xs text-tertiary">Takers</div><div class="fw-semibold"><?= $resultStats['total'] ?></div></div>
                        <div><div class="text-xs text-tertiary">Avg Score</div><div class="fw-semibold"><?= round($resultStats['avg_score'],1) ?></div></div>
                        <div><div class="text-xs text-tertiary">Highest</div><div class="fw-semibold"><?= $resultStats['max_score'] ?></div></div>
                        <div><div class="text-xs text-tertiary">Lowest</div><div class="fw-semibold"><?= $resultStats['min_score'] ?></div></div>
                    </div>
                <?php endif; ?>

            </div>
        </form>

        <?php if (!$selectedExam['is_active']): ?>
            <form method="POST" id="activate-exam-form" style="display:none">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="activate_exam">
                <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
            </form>
        <?php endif; ?>
        <form method="POST" id="delete-exam-form" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="delete_exam">
            <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
        </form>

        <!-- ── Sectioned question list ── -->
        <div id="questions-list">

        <?php
        $globalQNum = 0;
        function renderQuestionCard($q, &$globalQNum, $QUESTION_TYPES, $CHOICE_TYPES): void {
            global $selectedExam;
            $globalQNum++;
            $choices  = $q['choices'] ? json_decode($q['choices'], true) : [];
            $typeMeta = $QUESTION_TYPES[$q['question_type']] ?? $QUESTION_TYPES['multiple_choice'];
            $qJson    = htmlspecialchars(json_encode($q));
        ?>
            <div class="card q-card" data-qid="<?= $q['id'] ?>" data-q="<?= $qJson ?>">
                <div class="q-view-mode flex gap-3 align-items-start">
                        <div class="flex-1 min-w-0">
                            <div class="flex gap-2 flex-wrap" style="align-items:baseline">
                                <span class="fw-medium text-tertiary text-end" style="min-width:1.5em"><?= $globalQNum ?>.</span>
                                <span class="fw-medium flex-1 min-w-0"><?= e($q['question_text']) ?></span>
                                <span class="text-xs text-tertiary text-nowrap"><?= $q['points'] ?> pt<?= $q['points']!=1?'s':'' ?></span>
                                <?php if (!$q['is_required']): ?>
                                    <span class="type-pill">Optional</span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2" style="margin-left:calc(1.5em + var(--space-2))">
                            <?php if (in_array($q['question_type'], $CHOICE_TYPES) && !empty($choices)): ?>
                                <div class="flex flex-column gap-1">
                                <?php
                                $correctIndices = [];
                                if ($q['question_type'] === 'checkboxes' && $q['correct_answer'])
                                    $correctIndices = json_decode($q['correct_answer'], true) ?? [];
                                foreach ($choices as $ci => $choice):
                                    $isCorrect = ($q['question_type'] === 'multiple_choice' && (int)$q['correct_index'] === $ci)
                                              || ($q['question_type'] === 'dropdown' && (int)$q['correct_index'] === $ci)
                                              || ($q['question_type'] === 'checkboxes' && in_array($ci, $correctIndices));
                                ?>
                                    <div class="flex align-items-center gap-2 text-sm">
                                        <?php if ($isCorrect): ?>
                                            <?= icon('ic_fluent_checkmark_24_regular', 13, 'color:var(--success);flex-shrink:0') ?>
                                        <?php elseif ($q['question_type'] === 'checkboxes'): ?>
                                            <span class="rounded flex-shrink-0" style="width:13px;height:13px;border:1.5px solid var(--border);display:inline-block"></span>
                                        <?php else: ?>
                                            <span class="rounded-circle flex-shrink-0" style="width:13px;height:13px;border:1.5px solid var(--border);display:inline-block"></span>
                                        <?php endif; ?>
                                        <span style="color:<?= $isCorrect ? 'var(--success)' : 'var(--text-secondary)' ?>"><?= e($choice) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php elseif ($q['question_type'] === 'linear_scale'): ?>
                                <div class="flex align-items-center gap-2">
                                    <?php if ($q['scale_min_label']): ?><span class="text-xs text-tertiary"><?= e($q['scale_min_label']) ?></span><?php endif; ?>
                                    <?php for ($s=$q['scale_min'];$s<=$q['scale_max'];$s++): ?><div class="scale-btn"><?= $s ?></div><?php endfor; ?>
                                    <?php if ($q['scale_max_label']): ?><span class="text-xs text-tertiary"><?= e($q['scale_max_label']) ?></span><?php endif; ?>
                                </div>
                            <?php elseif ($q['question_type'] === 'short_answer'): ?>
                                <div class="text-sm text-tertiary flex pb-1" style="height:32px;border-bottom:2px solid var(--border);width:60%;align-items:flex-end">
                                    <?= $q['correct_answer'] ? 'Expected: '.e($q['correct_answer']) : 'Short answer text' ?>
                                </div>
                            <?php elseif ($q['question_type'] === 'paragraph'): ?>
                                <div class="text-sm text-tertiary flex pb-1" style="height:48px;border-bottom:2px solid var(--border);align-items:flex-end">Paragraph text</div>
                            <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-column flex-shrink-0 align-items-center" style="gap:2px">
                            <button type="button" class="drag-handle" title="Drag to reorder">
                                <?= icon('dots-six-vertical', 14) ?>
                            </button>
                            <button class="btn-icon" title="Edit question" onclick="startInlineQFullEdit(<?= $q['id'] ?>)">
                                <?= icon('ic_fluent_edit_24_regular', 14) ?>
                            </button>
                            <form method="POST" data-confirm="Remove this question?" data-confirm-danger data-confirm-ok="Remove" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <button class="btn-icon text-error" title="Delete">
                                    <?= icon('ic_fluent_delete_24_regular', 14) ?>
                                </button>
                            </form>
                        </div>
                </div>
            </div>
        <?php } ?>

        <?php if (empty($sections) && empty($questions)): ?>
            <div class="card p-12 text-start text-tertiary">
                <div class="text-sm">Click <strong>Add Section</strong> to create a section and start adding questions.</div>
            </div>
        <?php else: ?>

            <?php foreach ($sections as $sec):
                $sc = $SECTION_COLORS[$sec['question_type']] ?? $SECTION_COLORS['multiple_choice'];
                $secQs    = $questionsBySection[$sec['id']] ?? [];
                $secQCount = count($secQs);
                $secJson   = htmlspecialchars(json_encode($sec));
            ?>
                <div class="card section-block mb-5 p-0" style="overflow:visible" data-section-id="<?= $sec['id'] ?>">
                    <div class="section-header" style="background:<?= $sc['bg'] ?>;border-color:<?= $sc['border'] ?>;border-radius:var(--radius) var(--radius) 0 0;margin-bottom:0;align-items:flex-start">
                        <?php /* Stack title row + description vertically inside one flex
                                 cell so a long description wraps below the title instead
                                 of getting laid out alongside it (which used to push the
                                 title under the description and look like an overlap). */ ?>
                        <div class="flex-1 min-w-0 flex flex-column" style="gap:2px">
                            <div id="sec-title-view-<?= $sec['id'] ?>" class="flex align-items-center gap-2 min-w-0">
                                <?= typeIcon($sec['question_type']) ?>
                                <span id="sec-title-text-<?= $sec['id'] ?>"
                                      style="font-size:var(--text-sm);font-weight:var(--weight-semibold);color:<?= $sc['text'] ?>;cursor:pointer"
                                      class="inline-editable"
                                      role="button" tabindex="0"
                                      onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                                      onclick="startInlineSecEdit(<?= $sec['id'] ?>)"
                                      title="Click to rename"><?= e($sec['title']) ?></span>
                                <span style="font-size:var(--text-xs);color:<?= $sc['text'] ?>;opacity:.65" id="sec-count-<?= $sec['id'] ?>"><?= count($secQs) ?> question<?= count($secQs)!==1?'s':'' ?></span>
                            </div>
                            <?php if (!empty($sec['description'])): ?>
                                <div style="font-size:var(--text-xs);color:<?= $sc['text'] ?>;opacity:.7;line-height:1.4;word-break:break-word"><?= e($sec['description']) ?></div>
                            <?php endif; ?>
                            <div id="sec-title-edit-<?= $sec['id'] ?>" style="display:none">
                                <input type="text" id="sec-title-input-<?= $sec['id'] ?>"
                                       class="inline-edit-input text-sm fw-semibold"
                                       value="<?= e($sec['title']) ?>"
                                      >
                                <div class="inline-edit-actions">
                                    <button class="inline-save-btn" onclick="saveInlineSecTitle(<?= $sec['id'] ?>, '<?= e($sec['question_type']) ?>')">Save</button>
                                    <button class="inline-cancel-btn" onclick="cancelInlineSecEdit(<?= $sec['id'] ?>)">Cancel</button>
                                    <button class="inline-cancel-btn ms-auto" onclick="openEditSectionModal(<?= $secJson ?>)">Full edit…</button>
                                </div>
                            </div>
                        </div>
                        <div class="section-actions">
                            <button class="btn-icon" title="Edit section" onclick="openEditSectionModal(<?= $secJson ?>)">
                                <?= icon('ic_fluent_edit_24_regular', 13) ?>
                            </button>
                            <form method="POST" style="display:inline"<?= $secQCount === 0 ? ' data-empty-section="1"' : '' ?>
                                  onsubmit="return confirmDeleteSection(this, <?= $secQCount ?>, <?= json_encode($sec['title']) ?>)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                <button class="btn-icon text-error" title="Delete section" type="submit">
                                    <?= icon('ic_fluent_delete_24_regular', 13) ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="section-questions flex flex-column p-0" id="sec-questions-<?= $sec['id'] ?>">
                        <?php if (empty($secQs)): ?>
                            <div id="sec-empty-<?= $sec['id'] ?>" class="py-4 px-5 rounded text-center text-tertiary text-sm" style="border:1.5px dashed var(--border)">
                                No questions yet - click <strong>Add question</strong> below to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($secQs as $q): renderQuestionCard($q, $globalQNum, $QUESTION_TYPES, $CHOICE_TYPES); endforeach; ?>
                        <?php endif; ?>
                        <div id="inline-creator-<?= $sec['id'] ?>" class="inline-question-creator" style="display:none" data-section-id="<?= $sec['id'] ?>" data-section-type="<?= $sec['question_type'] ?>"></div>
                    </div>

                    <div class="bd-t py-3 px-4">
                        <button class="btn btn-ghost btn-sm flex align-items-center text-accent" id="sec-add-btn-<?= $sec['id'] ?>"
                                onclick="showInlineCreator(<?= $sec['id'] ?>, '<?= $sec['question_type'] ?>')"
                                style="gap:5px">
                            <?= icon('ic_fluent_add_24_regular', 13) ?>
                            Add question
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
        </div><!-- /questions-list -->

        <div class="flex justify-content-center gap-2 mt-4">
            <button class="btn btn-ghost btn-sm flex align-items-center"
                    onclick="openAddSectionModal()"
                    style="gap:5px">
                <?= icon('list-plus', 14) ?>
                Add Section
            </button>
            <button class="btn btn-secondary btn-sm flex align-items-center" onclick="openAiImportModal()" style="gap:5px">
                <?= icon('map-pin', 14) ?>
                Import with AI
            </button>
        </div>

<?php else: ?>
    <div class="card text-start text-tertiary" style="padding:var(--space-16)">
        <p class="text-sm">Select an exam from the directory or create a new one.</p>
        <div class="mt-4">
            <a href="<?= url('/staff/exam') ?>?view=exams" class="btn btn-primary btn-sm">Go to Exam Builder</a>
        </div>
    </div>
<?php endif; ?>

<?php endif; /* end view routing */ ?>


<!-- ════════════════════════════════════════════════════════════
     CREATE EXAM MODAL
════════════════════════════════════════════════════════════ -->
<div id="create-exam-modal" class="modal-backdrop" style="display:none">
    <div class="modal mwpx-520 overflow-y-auto" style="max-height:92vh">
        <div class="modal-header">
            <div class="modal-title">New Exam</div>
            <button class="btn-icon" onclick="closeModal('create-exam-modal')">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_exam">
            <div class="modal-body flex flex-column gap-4">
                <div class="text-sm text-secondary bg-subtle py-3 px-4 rounded">
                    Title will be set automatically:
                    <strong>PLP Admissions Test (<?= e($schoolYear) ?>)</strong>
                    <div class="text-xs text-tertiary" style="margin-top:2px">
                        Pulled from the active school year. Change it on the
                        <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                    </div>
                </div>
                <div>
                    <label class="form-label" for="create-exam-desc">Description</label>
                    <textarea id="create-exam-desc" name="description" class="form-control" rows="2" placeholder="Brief instructions shown to students…"></textarea>
                </div>

                <div class="flex flex-column gap-2">
                    <label class="flex align-items-center gap-3 pointer">
                        <input type="checkbox" name="shuffle_questions" class="accent-c">
                        <span class="text-sm">Shuffle question order</span>
                    </label>
                    <label class="flex align-items-center gap-3 pointer">
                        <input type="checkbox" name="shuffle_choices" class="accent-c">
                        <span class="text-sm">Shuffle answer choices</span>
                    </label>
                </div>
                <div class="alert alert-warning text-sm">
                    Creating a new exam will deactivate the currently active exam.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('create-exam-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Exam</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     ADD / EDIT SECTION MODAL
════════════════════════════════════════════════════════════ -->
<div id="section-modal" class="modal-backdrop" style="display:none">
    <div class="modal mwpx-440">
        <div class="modal-header">
            <div class="modal-title" id="sec-modal-title">Add Section</div>
            <button class="btn-icon" onclick="closeModal('section-modal')">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="sec-action" value="create_section">
            <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?? 0 ?>">
            <input type="hidden" name="section_id" id="sec-id" value="">
            <div class="modal-body flex flex-column gap-4">
                <div>
                    <label class="form-label" for="sec-title-field">Section Title <span class="text-error">*</span></label>
                    <input type="text" name="section_title" id="sec-title-field" class="form-control"
                           placeholder="e.g. Part 1: Multiple Choice" required>
                </div>
                <div>
                    <label class="form-label" for="sec-desc-field">Description / Instructions</label>
                    <textarea name="section_desc" id="sec-desc-field" class="form-control" rows="2"
                              placeholder="e.g. Choose the best answer for each question."></textarea>
                </div>
                <div>
                    <span class="form-label">Answer Mode</span>
                    <p class="text-xs text-tertiary mb-2">
                        All questions added to this section will automatically use this type.
                    </p>
                    <div class="type-grid" id="sec-type-grid">
                        <?php foreach ($QUESTION_TYPES as $typeKey => $typeMeta): ?>
                            <div class="type-option <?= $typeKey==='multiple_choice'?'selected':'' ?>"
                                 data-type="<?= $typeKey ?>"
                                 role="button" tabindex="0"
                                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                                 onclick="selectSectionType('<?= $typeKey ?>')">
                                <?= typeIcon($typeKey, 20) ?>
                                <?= $typeMeta['label'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="section_type" id="sec-type-val" value="multiple_choice">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('section-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="sec-submit-btn">Create Section</button>
            </div>
        </form>
    </div>
</div>


<script>
window.PAGE = <?= json_encode([
    'examId'        => (int)($selectedExam['id'] ?? 0),
    'live'          => !empty($examLive),
    'typeLabels'    => array_map(fn($v) => $v['label'], $QUESTION_TYPES),
    'sectionColors' => $SECTION_COLORS,
    'icons'         => [
        'add'     => icon('ic_fluent_add_24_regular', 11),
        'dismiss' => icon('ic_fluent_dismiss_24_regular', 13),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<!-- ════════════════════════════════════════════════════════════
     AI IMPORT MODAL
════════════════════════════════════════════════════════════ -->

<div id="ai-import-modal" class="modal-backdrop" style="display:none">
    <div class="modal flex flex-column" style="max-width:500px;max-height:90vh">
        <?= csrf_field() ?>
        <div class="modal-header py-4 px-5 bd-b">
            <div class="flex align-items-center gap-3">
                <div class="rounded flex align-items-center justify-content-center flex-shrink-0" style="width:34px;height:34px;background:var(--accent)">
                    <?= icon('sparkle', 17, 'color:#fff') ?>
                </div>
                <div>
                    <div class="fw-semibold text-base">Import Exam with AI</div>
                    <div class="text-xs text-tertiary" style="margin-top:1px">Upload a file and AI will extract the questions</div>
                </div>
            </div>
            <button class="btn-icon" onclick="closeAiImportModal()">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <div class="modal-body overflow-y-auto flex flex-column gap-4">
            <div id="ai-puter-status" class="ai-status-bar disconnected">
                <div class="ai-status-dot"></div>
                <div id="ai-puter-status-text" style="flex:1">Checking Puter connection…</div>
                <a id="ai-puter-link" href="https://puter.com" target="_blank" class="text-xs text-nowrap" style="display:none;font-weight:var(--weight-medium);color:var(--accent);text-decoration:none">Create account <?= icon('ic_fluent_arrow_right_24_regular', 14) ?></a>
                <button id="ai-puter-signin-btn" onclick="puterSignIn()" class="btn btn-ghost btn-sm" style="display:none;color:var(--accent)">Sign in <?= icon('ic_fluent_arrow_right_24_regular', 14) ?></button>
                <button id="ai-puter-signout-btn" onclick="puterSignOut()" class="btn btn-ghost btn-sm" style="display:none">Sign out</button>
            </div>
            <div id="ai-step-upload">
                <div class="ai-dropzone" id="ai-drop-zone" role="button" tabindex="0" onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();this.click();}" onclick="document.getElementById('ai-file-input').click()" ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="handleAiFileDrop(event)">
                    <div class="ai-dropzone-icon"><?= icon('file', 20, 'color:var(--text-tertiary)') ?></div>
                    <div id="ai-dropzone-label" class="text-sm" style="font-weight:var(--weight-medium);color:var(--text-secondary)">Drop file here or <span class="text-accent">click to browse</span></div>
                    <div class="text-xs text-tertiary mt-1">JPG · PNG · PDF · DOCX · TXT</div>
                    <div id="ai-file-tag-wrap" style="display:none;margin-top:var(--space-3)">
                        <span class="ai-file-tag">
                            <?= icon('file', 12, 'color:#fff') ?>
                            <span id="ai-file-tag-name"></span>
                            <button class="rm" onclick="event.stopPropagation();clearAiFile()" title="Remove"><?= icon('ic_fluent_dismiss_24_regular', 12) ?></button>
                        </span>
                    </div>
                    <input type="file" id="ai-file-input" aria-label="Choose file" accept=".jpg,.jpeg,.png,.pdf,.docx,.txt,.doc" style="display:none" onchange="handleAiFileSelect(this)">
                </div>
                <div class="flex align-items-center gap-2 mt-3">
                    <label for="ai-default-points" class="text-sm text-secondary text-nowrap">Points per question</label>
                    <input type="number" id="ai-default-points" class="form-control py-1 px-2 text-center" value="1" min="0" max="100" style="width:64px">
                </div>
            </div>
            <div id="ai-step-processing" class="flex-column gap-3 py-6 px-0 text-center" style="display:none">
                <div class="text-sm" style="font-weight:var(--weight-medium)" id="ai-processing-label">AI is processing your file…</div>
                <div class="ai-progress-wrap p-0">
                    <div class="ai-progress-track">
                        <div class="ai-progress-fill" id="ai-progress-fill"></div>
                    </div>
                </div>
                <div class="ai-progress-step text-xs" id="ai-progress-step" style="color:var(--text-tertiary)">Preparing…</div>
                <div class="text-xs text-tertiary mt-1" style="opacity:.7">A Puter sign-in popup may appear</div>
                <div class="ai-progress-step" id="ai-progress-pct" style="display:none">0%</div>
            </div>
            <div id="ai-step-preview" class="flex-column gap-3" style="display:none">
                <div class="flex align-items-center justify-content-between">
                    <div class="text-sm" style="font-weight:var(--weight-semibold)" id="ai-preview-count"></div>
                    <button class="btn btn-ghost btn-sm" onclick="resetAiImport()"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Try again</button>
                </div>
                <div class="ai-warn-strip">
                    <?= icon('warning:fill', 14, 'margin-top:1px;color:#d97706') ?>
                    <span>AI results may not be 100% accurate. <strong>Review each question and answer</strong> before saving.</span>
                </div>
                <div id="ai-questions-preview" class="flex flex-column gap-2 overflow-y-auto" style="max-height:320px"></div>
            </div>
            <div id="ai-step-error" class="flex-column gap-3" style="display:none">
                <div class="flex align-items-start rounded text-sm text-error" style="gap:10px;background:var(--error-bg);border:1px solid var(--error);padding:12px 14px">
                    <?= icon('warning-circle:fill', 16, 'margin-top:1px') ?>
                    <span id="ai-error-msg"></span>
                </div>
                <button class="btn btn-ghost btn-sm align-self-start" onclick="resetAiImport()"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Try again</button>
            </div>
        </div>
        <div class="modal-footer bd-t" id="ai-modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeAiImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="ai-process-btn" onclick="startAiProcessing()" disabled>
                <?= icon('sparkle', 14, 'margin-right:5px') ?>
                Generate Questions
            </button>
        </div>
        <div class="modal-footer bd-t" id="ai-save-footer" style="display:none">
            <button type="button" class="btn btn-ghost" onclick="closeAiImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="ai-save-btn" onclick="saveAiQuestions()">
                <?= icon('floppy-disk', 14, 'margin-right:5px') ?>
                Add All to Exam
            </button>
        </div>
    </div>
</div>

