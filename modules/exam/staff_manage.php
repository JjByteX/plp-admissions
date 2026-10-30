<?php
// ============================================================
// modules/exam/staff_manage.php
// M4 — Staff: create/manage exam, add/edit questions
// Enhanced: Exam Sets (dates + password), Inline Editing,
//           Section/Part grouping by answer mode
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db = db();
$errors  = [];
$success = [];

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
$QUESTION_TYPES = [
    'multiple_choice' => ['label' => 'Multiple Choice', 'icon' => 'radio'],
    'checkboxes'      => ['label' => 'Checkboxes',       'icon' => 'check'],
    'dropdown'        => ['label' => 'Dropdown',          'icon' => 'chevron'],
    'short_answer'    => ['label' => 'Short Answer',      'icon' => 'text'],
    'paragraph'       => ['label' => 'Paragraph',         'icon' => 'paragraph'],
    'linear_scale'    => ['label' => 'Linear Scale',      'icon' => 'scale'],
];
$CHOICE_TYPES = ['multiple_choice', 'checkboxes', 'dropdown'];

$SECTION_COLORS = [
    'multiple_choice' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
    'checkboxes'      => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#6ee7b7'],
    'dropdown'        => ['bg' => '#ede9fe', 'text' => '#5b21b6', 'border' => '#c4b5fd'],
    'short_answer'    => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
    'paragraph'       => ['bg' => '#fce7f3', 'text' => '#9d174d', 'border' => '#f9a8d4'],
    'linear_scale'    => ['bg' => '#f3f4f6', 'text' => 'var(--text-secondary)', 'border' => '#d1d5db'],
];

// ----------------------------------------------------------------
// POST handlers
// ----------------------------------------------------------------
// AJAX detection helper now lives in core/helpers.php so every module
// that returns JSON can use the same predicate. The local declaration
// here was kept as a fallback during the migration but has been removed
// to avoid "Cannot redeclare is_ajax_request()" fatals once helpers.php
// is loaded (bootstrap.php already pulls it in).

// buildExamTitle(), exam_in_progress() and exam_has_results() live in core/exam.php.

if (($action = require_post_action()) !== null) {

    // ── Exam-in-use guard ────────────────────────────────────
    // Locked (has submitted results): no adding or deleting questions, sections
    // or the exam; edit_question only changes wording. Scores are stored when a
    // student submits, so this keeps the exam matching those scores.
    // Live (a student is taking it): allowed, but staff must confirm first.
    $examLocked = false;
    $guardMsg   = null;
    $needsConfirm = false;
    if (in_array($action, ['create_exam', 'activate_exam', 'delete_exam', 'delete_section',
                           'add_question', 'edit_question', 'delete_question'], true)) {
        $targetExamId = post_int('exam_id');
        if ($action === 'delete_question') {
            $st = $db->prepare('SELECT exam_id FROM questions WHERE id=?');
            $st->execute([post_int('question_id')]);
            $targetExamId = (int)$st->fetchColumn();
        } elseif ($action === 'delete_section') {
            $st = $db->prepare('SELECT exam_id FROM exam_sections WHERE id=?');
            $st->execute([post_int('section_id')]);
            $targetExamId = (int)$st->fetchColumn();
        }
        // Creating or activating an exam switches students off the current active exam.
        $liveExamId = $targetExamId;
        if ($action === 'create_exam' || $action === 'activate_exam') {
            $liveExamId = (int)$db->query('SELECT id FROM exams WHERE is_active=1 LIMIT 1')->fetchColumn();
            if ($action === 'activate_exam' && $liveExamId === $targetExamId) $liveExamId = 0;
        }

        $examLocked = in_array($action, ['delete_exam', 'delete_section', 'add_question', 'delete_question', 'edit_question'], true)
                      && exam_has_results($db, $targetExamId);
        $live = exam_in_progress($db, $liveExamId);

        // An empty section holds no questions, so removing it can't affect any
        // recorded or in-progress score. Never block or prompt for it.
        if ($action === 'delete_section') {
            $st = $db->prepare('SELECT COUNT(*) FROM questions WHERE section_id=?');
            $st->execute([post_int('section_id')]);
            if ((int)$st->fetchColumn() === 0) { $examLocked = false; $live = false; }
        }

        // Adding questions to an exam with results is allowed, but only after a
        // warning: recorded scores stay as they are, new questions are unscored for
        // students who already submitted.
        if ($examLocked && $action === 'add_question') {
            $examLocked = false;
            if (empty($_POST['confirm_live'])) {
                $guardMsg = 'This exam already has submitted results. Scores already recorded stay as they are, but the new questions will not count for students who already submitted. Confirm to continue.';
                $needsConfirm = true;
            }
        }

        if ($guardMsg !== null) {
            // soft warning set above; handled by the response below
        } elseif ($examLocked && $action !== 'edit_question') {
            $guardMsg = 'This exam already has submitted results, so questions, sections and the exam itself can no longer be added or deleted. Scores already recorded stay as they are. To change the content, create a new exam.';
        } elseif ($live && $action === 'delete_exam') {
            $guardMsg = 'Students are taking this exam right now, so it cannot be deleted.';
        } elseif ($live && empty($_POST['confirm_live'])) {
            $guardMsg = 'Students are taking the exam right now. This change applies to them immediately and can change their score. Confirm to continue.';
            $needsConfirm = true;
        }
    }
    if ($guardMsg !== null) {
        $isFetch = is_ajax_request() || (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty');
        if ($isFetch) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'blocked' => true, 'needs_confirm' => $needsConfirm, 'error' => $guardMsg]);
            exit;
        }
        $errors[] = $guardMsg;
        $action = '';                       // skip the switch; the page re-renders with the message
    }

    switch ($action) {

        // ── Exam CRUD ────────────────────────────────────────────
        // Schedule and access code live on each room slot
        // (exam_slot_schedule); the exam itself is content-only
        // (description + shuffle flags). The title is auto-derived
        // server-side from the active school year.
        case 'create_exam':
            $description    = post_str('description');
            $shuffleQ       = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC       = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $title          = buildExamTitle();
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $insExam = $db->prepare(
                'INSERT INTO exams (title, description, shuffle_questions, shuffle_choices, is_active)
                 VALUES (?,?,?,?,1)
                 RETURNING id'
            );
            $insExam->execute([$title, $description ?: null, $shuffleQ, $shuffleC]);
            $newExamId = (int)$insExam->fetchColumn();
            $success[] = 'Exam created and set as active.';
            audit_log(AuditAction::EXAM_CREATED, "Created exam: {$title}", 'exam', $newExamId);
            // Redirect to this exam's explicit URL so the page always has ?exam=ID
            header('Location: ' . url('/staff/exam') . '?exam=' . $newExamId . '&created=1');
            exit;

        case 'edit_exam':
            $examId      = post_int('exam_id');
            $description = post_str('description');
            $shuffleQ    = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC    = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $title       = buildExamTitle();
            $db->prepare(
                'UPDATE exams SET title=?, description=?, shuffle_questions=?, shuffle_choices=? WHERE id=?'
            )->execute([$title, $description ?: null, $shuffleQ, $shuffleC, $examId]);
            // Handle inline-edit AJAX
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $title]);
                exit;
            }
            $success[] = 'Exam updated.';
            audit_log(AuditAction::EXAM_UPDATED, "Updated exam ID {$examId}: {$title}", 'exam', $examId);
            break;

        case 'activate_exam':
            $examId = post_int('exam_id');
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare('UPDATE exams SET is_active=1 WHERE id=?')->execute([$examId]);
            $success[] = 'Exam activated.';
            audit_log(AuditAction::EXAM_ACTIVATED, "Activated exam ID {$examId}", 'exam', $examId);
            break;

        case 'delete_exam':
            $examId = post_int('exam_id');
            if (!$examId) { $errors[] = 'Invalid exam.'; break; }
            // CASCADE constraints on exam_sections, questions, and exam_results
            // handle child rows automatically — just delete the parent.
            $db->prepare('DELETE FROM exams WHERE id=?')->execute([$examId]);
            audit_log(AuditAction::EXAM_DELETED, "Deleted exam ID {$examId}", 'exam', $examId);
            header('Location: ' . url('/staff/exam'));
            exit;

        // ── Section CRUD ─────────────────────────────────────────
        case 'create_section':
            $examId    = post_int('exam_id');
            $secTitle  = post_str('section_title');
            $secType   = $_POST['section_type'] ?? 'multiple_choice';
            $secDesc   = post_str('section_desc');
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $maxOrd = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM exam_sections WHERE exam_id=?');
            $maxOrd->execute([$examId]);
            $insSec = $db->prepare(
                'INSERT INTO exam_sections (exam_id, title, description, question_type, sort_order) VALUES (?,?,?,?,?) RETURNING id'
            );
            $insSec->execute([$examId, $secTitle, $secDesc ?: null, $secType, (int)$maxOrd->fetchColumn() + 1]);
            $newSecId = (int)$insSec->fetchColumn();
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'section_id' => $newSecId]);
                exit;
            }
            $success[] = 'Section added.';
            break;

        case 'edit_section':
            $secId    = post_int('section_id');
            $secTitle = post_str('section_title');
            $secType  = $_POST['section_type'] ?? 'multiple_choice';
            $secDesc  = post_str('section_desc');
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $db->prepare('UPDATE exam_sections SET title=?, description=?, question_type=? WHERE id=?')
               ->execute([$secTitle, $secDesc ?: null, $secType, $secId]);
            // Handle inline AJAX
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $secTitle, 'type' => $secType]);
                exit;
            }
            $success[] = 'Section updated.';
            break;

        case 'delete_section':
            $secId = post_int('section_id');
            // Delete all questions in section first, then the section itself
            $db->prepare('DELETE FROM questions WHERE section_id=?')->execute([$secId]);
            $db->prepare('DELETE FROM exam_sections WHERE id=?')->execute([$secId]);
            $success[] = 'Section deleted.';
            break;

        // ── Question CRUD ─────────────────────────────────────────
        case 'add_question':
        case 'edit_question':
            $isEdit   = ($action === 'edit_question');
            $examId   = post_int('exam_id');
            $qId      = post_int('question_id');
            $sectionId= $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
            $qText    = post_str('question_text');
            $qDesc    = post_str('question_desc');
            $qType    = $_POST['question_type'] ?? 'multiple_choice';
            $points   = max(0, post_int('points', 1));
            $required = isset($_POST['is_required']) ? 1 : 0;

            if (!$qText) { $errors[] = 'Question text is required.'; break; }
            if (!$sectionId) { $errors[] = 'Every question must belong to a section.'; break; }
            if (!array_key_exists($qType, $QUESTION_TYPES)) $qType = 'multiple_choice';

            if ($isEdit && $qId && $examLocked) {
                // Results exist: only the wording may change. Type, points, choices and the answer key stay as they are.
                $db->prepare('UPDATE questions SET question_text=?, description=? WHERE id=? AND exam_id=?')
                   ->execute([$qText, $qDesc ?: null, $qId, $examId]);
                $success[] = 'Wording updated. Points, choices and the answer key are locked because this exam already has submitted results.';
                break;
            }

            $choices = null; $correctIndex = null; $correctAnswer = null;
            $scaleMin = 1; $scaleMax = 5; $scaleMinLabel = null; $scaleMaxLabel = null;

            if (in_array($qType, $CHOICE_TYPES)) {
                $rawChoices = array_map('trim', $_POST['choices'] ?? []);
                $rawChoices = array_values(array_filter($rawChoices, fn($c) => $c !== ''));
                if (count($rawChoices) < 2) { $errors[] = 'At least 2 choices are required.'; break; }
                $choices = json_encode($rawChoices);
                if ($qType === 'checkboxes') {
                    $corrects = array_map('intval', $_POST['correct_indices'] ?? []);
                    $corrects = array_values(array_filter($corrects, fn($i) => $i >= 0 && $i < count($rawChoices)));
                    $correctAnswer = json_encode($corrects);
                } else {
                    $ci = post_int('correct_index');
                    $correctIndex = ($ci >= 0 && $ci < count($rawChoices)) ? $ci : 0;
                }
            } elseif ($qType === 'linear_scale') {
                $scaleMin = max(0, min(1, post_int('scale_min', 1)));
                $scaleMax = max(2, min(10, post_int('scale_max', 5)));
                $scaleMinLabel = post_str('scale_min_label') ?: null;
                $scaleMaxLabel = post_str('scale_max_label') ?: null;
            } elseif ($qType === 'short_answer') {
                $correctAnswer = post_str('expected_answer') ?: null;
            }

            if ($isEdit && $qId) {
                $db->prepare(
                    'UPDATE questions SET question_text=?, question_type=?, description=?, points=?,
                     is_required=?, choices=?, correct_index=?, correct_answer=?, scale_min=?,
                     scale_max=?, scale_min_label=?, scale_max_label=?, section_id=?
                     WHERE id=? AND exam_id=?'
                )->execute([
                    $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel,
                    $sectionId, $qId, $examId
                ]);
                $success[] = 'Question updated.';
            } else {
                $maxOrder = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE exam_id=?');
                $maxOrder->execute([$examId]);
                $insQ = $db->prepare(
                    'INSERT INTO questions (exam_id, question_text, question_type, description, points,
                     is_required, choices, correct_index, correct_answer, scale_min, scale_max,
                     scale_min_label, scale_max_label, section_id, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     RETURNING id'
                );
                $insQ->execute([
                    $examId, $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel,
                    $sectionId, (int)$maxOrder->fetchColumn() + 1
                ]);
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'id' => (int)$insQ->fetchColumn()]);
                    exit;
                }
                $success[] = 'Question added.';
            }
            break;

        case 'delete_question':
            $qId = post_int('question_id');
            $db->prepare('DELETE FROM questions WHERE id=?')->execute([$qId]);
            $success[] = 'Question deleted.';
            break;

        case 'reorder_questions':
            $order = $_POST['order'] ?? [];
            foreach ($order as $idx => $qId) {
                $db->prepare('UPDATE questions SET sort_order=? WHERE id=?')
                   ->execute([$idx + 1, (int)$qId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;

        // Per-room access code generation lives on the Exam Slots page now
        // (modules/exam/staff_slots.php → action=generate_slot_code).
        // Schedule lives on the slot too — see the slot's exam_date / slot_time.

        case 'inline_edit_question':
            // Quick inline text edit for a single question
            $qId   = post_int('question_id');
            $qText = post_str('question_text');
            if ($qText) {
                $db->prepare('UPDATE questions SET question_text=? WHERE id=?')->execute([$qText, $qId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'text' => $qText]);
            exit;
    }
}

// ----------------------------------------------------------------
// Load data
// ----------------------------------------------------------------
$exams        = $db->query('SELECT * FROM exams ORDER BY id DESC')->fetchAll();
// Question count per exam, for the cards in the exam directory.
$examMeta = [];
foreach ($db->query('SELECT exam_id, COUNT(*) AS n FROM questions GROUP BY exam_id')->fetchAll() as $r) {
    $examMeta[(int)$r['exam_id']] = ['question_count' => (int)$r['n']];
}
$activeExam   = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch() ?: null;
$schoolYear    = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$slotCountStmt = $db->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year = ?');
$slotCountStmt->execute([$schoolYear]);
$slotCount     = (int) $slotCountStmt->fetchColumn();
$hasRoomSlots  = $slotCount > 0;
$questions    = [];
$sections     = [];
$selectedExamId = get_int('exam');
$selectedExam   = null;

if ($selectedExamId) {
    $stmt = $db->prepare('SELECT * FROM exams WHERE id=?');
    $stmt->execute([$selectedExamId]);
    $selectedExam = $stmt->fetch() ?: null;

    if ($selectedExam) {
        $stmt = $db->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
        $stmt->execute([$selectedExamId]);
        $questions = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM exam_sections WHERE exam_id=? ORDER BY sort_order, id');
        $stmt->execute([$selectedExamId]);
        $sections = $stmt->fetchAll();
    }
}

$resultStats = []; $totalPoints = 0;
if ($selectedExam) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) as total, AVG(score) as avg_score, MAX(score) as max_score, MIN(score) as min_score
         FROM exam_results WHERE exam_id=?'
    );
    $stmt->execute([$selectedExamId]);
    $resultStats = $stmt->fetch();
    $totalPoints = array_sum(array_column($questions, 'points'));
}

// Flags for the page: confirm prompt while students are taking the exam,
// notice once results exist.
$examLive   = exam_in_progress($db, (int)($selectedExam['id'] ?? 0))
           || exam_in_progress($db, (int)($activeExam['id'] ?? 0));
$examFrozen = $selectedExam && exam_has_results($db, (int)$selectedExam['id']);

// Group questions by section (every question must belong to one)
$questionsBySection = [];
foreach ($sections as $sec) $questionsBySection[$sec['id']] = [];
foreach ($questions as $q) {
    $sid = (int)$q['section_id'];
    if (isset($questionsBySection[$sid])) {
        $questionsBySection[$sid][] = $q;
    }
}

ob_start();
include __DIR__ . '/_manage_view.php';
$content   = ob_get_clean();
$pageTitle = 'Entrance Exam';
$pageStyles  = ['css/ai-import.css', 'css/pages/exam-manage.css'];
$pageScripts = ['js/pages/exam-manage.js', 'js/pages/exam-ai-import.js'];
// Entrance Exam > Exams > Exam Editor (replaces the old back-arrow buttons).
$pageBreadcrumb = [];
if ($view === 'exams') {
    $pageHeading    = 'Exams';
    $pageBreadcrumb = [['label' => 'Entrance Exam', 'to' => '/staff/exam']];
} elseif ($view === 'editor') {
    $pageHeading    = 'Exam Editor';
    $pageBreadcrumb = [
        ['label' => 'Entrance Exam', 'to' => '/staff/exam'],
        ['label' => 'Exams',         'to' => '/staff/exam?view=exams'],
    ];
}
$activeNav = 'exam';
$pageWide  = ($view !== 'editor'); // picker views share the wide container
include VIEWS_PATH . '/layouts/app.php';
