<?php
// ============================================================
// core/automation/exam_finalizer.php
// Exam finalizer: scoring, storing the result, closing expired drafts.
// Loaded by core/automation.php; plain functions, unchanged.
// ============================================================

// ----------------------------------------------------------------
// EXAM FINALIZER
// One place that scores an exam, stores the result and moves the
// applicant on. The exam page POST and the closed-exam sweep both call it.
// ----------------------------------------------------------------

const EXAM_AUTO_NOTE = 'Auto-submitted, connection lost';

/**
 * Log a warning when the database session is not on Asia/Manila.
 * Slot close times and draft saved_at are compared in SQL, so they must share one clock.
 * ponytail: log only, once per request. It does not block the exam.
 */
function warn_if_db_timezone_off(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $tz = (string) db()->query('SHOW timezone')->fetchColumn();
        if ($tz !== 'Asia/Manila') {
            error_log("DB timezone is '{$tz}', expected Asia/Manila. Exam close times will be wrong.");
        }
    } catch (\Throwable $e) {}
}

/**
 * SQL expression for when a slot closes, as a timestamp. $alias is the
 * exam_slot_schedule alias in the calling query. Same rules as slot_window():
 * end_time wraps past midnight to the next day; no end_time means 90 minutes.
 * Use it inside any query that must decide "is this slot still open" so no
 * PHP clock is ever compared with a database time.
 */
function slot_close_sql(string $alias = 's'): string
{
    return "(CASE WHEN {$alias}.end_time IS NULL THEN {$alias}.exam_date + {$alias}.slot_time + interval '90 minutes'
      WHEN {$alias}.end_time <= {$alias}.slot_time THEN {$alias}.exam_date + {$alias}.end_time + interval '1 day'
      ELSE {$alias}.exam_date + {$alias}.end_time END)";
}

/** True while the database clock is at or before the slot close (plus an optional grace). */
function slot_is_open(int $slotId, int $graceSeconds = 0): bool
{
    $grace = max(0, $graceSeconds);
    $stmt  = db()->prepare(
        'SELECT now() <= (' . slot_close_sql('s') . " + interval '{$grace} seconds')
           FROM exam_slot_schedule s WHERE s.id = ?"
    );
    $stmt->execute([$slotId]);
    return (bool) $stmt->fetchColumn();
}

/** Seconds after close in which a normal submit is still accepted. School setting, default 30. */
function exam_submit_grace_seconds(): int
{
    return max(0, (int) school_setting('exam_submit_grace_seconds', '30'));
}

/**
 * Turn a saved draft (JSON text) into the same shape as $_POST['answers']:
 * [questionId => value]. The browser stores keys as "answers[12]" or
 * "answers[12][]"; plain "12" keys work too.
 */
function exam_answers_from_draft(string $json): array
{
    $out = [];
    foreach ((array) json_decode($json, true) as $k => $v) {
        if (preg_match('/^(?:answers\[)?(\d+)/', (string) $k, $m)) {
            $out[(int) $m[1]] = $v;
        }
    }
    return $out;
}

/** True when no answer has a value (all empty strings or empty lists). */
function exam_answers_all_blank(array $answers): bool
{
    foreach ($answers as $v) {
        if (is_array($v) ? count($v) > 0 : trim((string) $v) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * Score answers against questions. No database.
 * Returns [score, savedAnswers] where savedAnswers is the cleaned answer set.
 */
function score_exam_answers(array $questions, array $answers): array
{
    $score        = 0;
    $savedAnswers = [];

    foreach ($questions as $q) {
        $qId   = $q['id'];
        $qType = $q['question_type'] ?? 'multiple_choice';

        switch ($qType) {
            case 'multiple_choice':
            case 'dropdown':
                $chosen = isset($answers[$qId]) ? (int)$answers[$qId] : -1;
                $savedAnswers[$qId] = $chosen;
                if ($chosen === (int)$q['correct_index']) {
                    $score += (int)$q['points'];
                }
                break;

            case 'checkboxes':
                $chosen = isset($answers[$qId]) && is_array($answers[$qId])
                    ? array_map('intval', $answers[$qId]) : [];
                sort($chosen);
                $savedAnswers[$qId] = $chosen;
                $correctIndices = $q['correct_answer'] ? json_decode($q['correct_answer'], true) : [];
                sort($correctIndices);
                if (!empty($correctIndices) && $chosen === $correctIndices) {
                    $score += (int)$q['points'];
                } elseif (empty($correctIndices) && !empty($chosen)) {
                    // No correct answer set: give credit for any answer
                    $score += (int)$q['points'];
                }
                break;

            case 'identification':
            case 'short_answer':
                $text = trim($answers[$qId] ?? '');
                $savedAnswers[$qId] = $text;
                if ($q['correct_answer'] !== null && $q['correct_answer'] !== '') {
                    if (mb_strtolower($text) === mb_strtolower(trim($q['correct_answer']))) {
                        $score += (int)$q['points'];
                    }
                }
                // No expected answer: score stays 0 (manual grading)
                break;

            case 'paragraph':
                $savedAnswers[$qId] = trim($answers[$qId] ?? '');
                // Always 0: manual grading
                break;

            case 'linear_scale':
                $chosen = isset($answers[$qId]) ? (int)$answers[$qId] : -1;
                $savedAnswers[$qId] = $chosen;
                // Credit for any selection in range
                if ($chosen >= $q['scale_min'] && $chosen <= $q['scale_max']) {
                    $score += (int)$q['points'];
                }
                break;
        }
    }

    return [$score, $savedAnswers];
}

/**
 * Score, store and advance one applicant's exam. $answers has the same shape
 * as $_POST['answers']. $note goes to exam_results.submit_note.
 *
 * Safe against double submit: the unique (applicant_id, exam_id) index plus
 * ON CONFLICT DO NOTHING. Returns null when a result already exists (or the
 * applicant is missing), otherwise ['score','total','rank','passed','pct'].
 * On success the server draft is deleted.
 */
function finalize_exam_result(int $applicantId, int $examId, array $answers, ?string $note = null): ?array
{
    $pdo = db();
    warn_if_db_timezone_off();

    $stmt = $pdo->prepare('SELECT user_id, course_applied FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();
    if (!$applicant) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM questions WHERE exam_id = ?');
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll();

    [$score, $savedAnswers] = score_exam_answers($questions, $answers);
    $total  = array_sum(array_column($questions, 'points')) ?: count($questions);
    $rank   = score_to_rank($score, $total);
    $passed = exam_passed($score, $total, $applicant['course_applied']);

    $ins = $pdo->prepare(
        'INSERT INTO exam_results (applicant_id, exam_id, score, total_items, answers, rank_score, passed, submit_note)
         VALUES (?,?,?,?,?,?,?,?)
         ON CONFLICT (applicant_id, exam_id) DO NOTHING'
    );
    $ins->execute([$applicantId, $examId, $score, $total, json_encode($savedAnswers), $rank, $passed ? 1 : 0, $note]);
    if ($ins->rowCount() === 0) {
        return null;
    }

    // Passed advances to interview; failed stays at exam so staff can review
    // and suggest an alternative course.
    $pdo->prepare('UPDATE applicants SET overall_status=? WHERE id=?')
        ->execute([$passed ? 'interview' : 'exam', $applicantId]);

    $pdo->prepare('DELETE FROM exam_drafts WHERE applicant_id = ? AND exam_id = ?')
        ->execute([$applicantId, $examId]);

    // Auto-assign an interview slot the moment they pass. Returns null when no
    // compatible slot is open; bulk_assign_pending_applicants() picks them up later.
    if ($passed) {
        try {
            assign_interview_slot($applicantId, (int) $applicant['user_id']);
        } catch (\Throwable $e) {
            error_log('Auto-assign interview after exam pass failed: ' . $e->getMessage());
        }
    }

    return [
        'score'  => $score,
        'total'  => $total,
        'rank'   => $rank,
        'passed' => $passed,
        'pct'    => $total > 0 ? round(($score / $total) * 100) : 0,
    ];
}

/**
 * Sweep: finalize every draft whose slot has closed and that has no result yet.
 * Uses the server draft and the "Auto-submitted, connection lost" note, then
 * finalize_exam_result() deletes the draft. Not limited to the current student.
 * Applicants with no draft are skipped (staff handle them, not scored 0).
 * Returns how many results were created.
 *
 * ponytail: one cheap EXISTS first, so a page load with no work costs one tiny query.
 * Drafts are only written while the slot is open, so no second time check is needed here.
 */
function finalize_closed_exam_drafts(): int
{
    static $ran = false;            // once per request
    if ($ran) {
        return 0;
    }
    $ran = true;

    $pdo  = db();
    $from = 'FROM exam_drafts d
             JOIN exam_slot_schedule s ON s.id = d.slot_id
            WHERE now() > ' . slot_close_sql('s') . '
              AND NOT EXISTS (SELECT 1 FROM exam_results r
                               WHERE r.applicant_id = d.applicant_id AND r.exam_id = d.exam_id)';

    try {
        if (!$pdo->query("SELECT EXISTS (SELECT 1 {$from})")->fetchColumn()) {
            return 0;
        }

        $rows = $pdo->query("SELECT d.applicant_id, d.exam_id, d.answers {$from}")->fetchAll();
        $done = 0;
        foreach ($rows as $row) {
            try {
                $draftAnswers = exam_answers_from_draft((string) $row['answers']);
                // A draft with no real answer is not a submission. Leave it for staff
                // instead of recording a score of 0.
                if (exam_answers_all_blank($draftAnswers)) {
                    continue;
                }
                $res = finalize_exam_result(
                    (int) $row['applicant_id'],
                    (int) $row['exam_id'],
                    $draftAnswers,
                    EXAM_AUTO_NOTE
                );
                if ($res !== null) {
                    $done++;
                }
            } catch (\Throwable $e) {
                error_log('Auto-submit of closed exam draft failed: ' . $e->getMessage());
            }
        }
        return $done;
    } catch (\Throwable $e) {
        error_log('finalize_closed_exam_drafts failed: ' . $e->getMessage());
        return 0;
    }
}
