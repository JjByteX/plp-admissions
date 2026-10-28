<?php
// ============================================================
// core/exam.php
// Exam helpers shared by the exam module pages.
// ============================================================

// Auto-derive the exam title from the active school year. Shared by
// create_exam and edit_exam so both stay in sync.
function buildExamTitle(): string {
    $autoYear = (string) school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
    return 'PLP Admissions Test (' . $autoYear . ')';
}

// An exam is "in progress" while a student is mid-exam: an unsubmitted draft
// exists, or one of its room slots is open right now (database clock).
function exam_in_progress(PDO $db, int $examId): bool {
    if ($examId <= 0) return false;
    $stmt = $db->prepare(
        'SELECT EXISTS (SELECT 1 FROM exam_drafts d
                         WHERE d.exam_id = ?
                           AND NOT EXISTS (SELECT 1 FROM exam_results r
                                            WHERE r.applicant_id = d.applicant_id AND r.exam_id = d.exam_id))
             OR EXISTS (SELECT 1 FROM exam_slot_schedule s
                         WHERE (s.exam_id = ? OR (s.exam_id IS NULL AND EXISTS (SELECT 1 FROM exams x WHERE x.id = ? AND x.is_active = 1)))
                           AND now() >= s.exam_date + s.slot_time
                           AND now() <= ' . slot_close_sql('s') . ')'
    );
    $stmt->execute([$examId, $examId, $examId]);
    return (bool) $stmt->fetchColumn();
}

// Once any student has submitted, the scoring side of the exam is frozen.
function exam_has_results(PDO $db, int $examId): bool {
    if ($examId <= 0) return false;
    $stmt = $db->prepare('SELECT 1 FROM exam_results WHERE exam_id = ? LIMIT 1');
    $stmt->execute([$examId]);
    return (bool) $stmt->fetchColumn();
}

// Type icons helper
function typeIcon($type, $size = 14) {
    $icons = [
        'multiple_choice' => 'radio-button',
        'checkboxes'      => 'check-square',
        'dropdown'        => 'caret-circle-down',
        'short_answer'    => 'text-align-left',
        'paragraph'       => 'text-align-justify',
        'linear_scale'    => 'arrows-left-right',
    ];
    return icon($icons[$type] ?? 'radio-button', $size);
}

/**
 * Format a name as: "SURNAME SUFFIX, FIRST MIDDLE" — all caps.
 * Falls back to splitting `users.name` if structured columns are empty.
 */
function format_exam_roster_name(array $r): string {
    $last   = trim($r['last_name']   ?? '');
    $first  = trim($r['first_name']  ?? '');
    $middle = trim($r['middle_name'] ?? '');
    $suffix = trim($r['suffix']      ?? '');

    if ($last === '' && $first === '') {
        // Legacy fallback — split the consolidated `users.name` on the last space.
        $full  = trim((string) ($r['student_name'] ?? ''));
        $parts = preg_split('/\s+/', $full) ?: [];
        if (count($parts) >= 2) {
            $last  = array_pop($parts);
            $first = implode(' ', $parts);
        } else {
            $last  = $full;
        }
    }

    $surnamePart = strtoupper(trim($last . ($suffix !== '' ? ' ' . $suffix : '')));
    $firstPart   = strtoupper(trim($first . ($middle !== '' ? ' ' . $middle : '')));
    return $surnamePart . ($firstPart !== '' ? ', ' . $firstPart : '');
}
