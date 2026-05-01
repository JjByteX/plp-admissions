<?php
// ============================================================
// modules/results/staff_suggest.php
// Staff: POST handler — suggest alternative course to applicant
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db          = db();
$applicantId = (int)($_GET['id'] ?? 0);
$course      = trim($_POST['suggest_course'] ?? '');
$note        = trim($_POST['suggest_note']   ?? '');
$staffId     = Auth::id();

if (!$applicantId || !in_array($course, PLP_COURSES, true)) {
    Session::flash('error', 'Invalid suggestion data.');
    redirect('/staff/results');
}

// Verify the applicant exists and failed the exam
$stmt = $db->prepare(
    'SELECT a.id, a.course_applied, er.score, er.total_items, er.passed, er.rank_score
     FROM applicants a
     LEFT JOIN exam_results er ON er.applicant_id = a.id
     WHERE a.id = ? LIMIT 1'
);
$stmt->execute([$applicantId]);
$app = $stmt->fetch();

if (!$app) {
    Session::flash('error', 'Applicant not found.');
    redirect('/staff/results');
}

// Verify the applicant's rank actually qualifies for the suggested course.
// Use the rank_score already stored at exam submission time so this gate
// uses the exact same threshold as exam_passed() — both read COURSE_PASSING_SCORES.
$rank      = isset($app['rank_score']) && $app['rank_score'] !== null
    ? (int)$app['rank_score']
    : score_to_rank((int)$app['score'], (int)($app['total_items'] ?: 1));
$threshold = COURSE_PASSING_SCORES[$course]['pass_from'] ?? 4;
if ($rank < $threshold) {
    Session::flash('error', "Applicant's rank ({$rank}) does not meet the passing threshold ({$threshold}) for {$course}.");
    redirect('/staff/results');
}

// Upsert course suggestion record
$db->prepare(
    'INSERT INTO course_suggestions
        (applicant_id, original_course, suggested_course, suggested_by, note, status)
     VALUES (?,?,?,?,?, "pending")
     ON DUPLICATE KEY UPDATE
        suggested_course=VALUES(suggested_course),
        suggested_by=VALUES(suggested_by),
        note=VALUES(note),
        status="pending",
        updated_at=NOW()'
)->execute([
    $applicantId,
    $app['course_applied'],
    $course,
    $staffId,
    $note ?: null,
]);

audit_log(
    'course_suggestion',
    "Suggested '{$course}' for applicant {$applicantId} (was '{$app['course_applied']}')",
    'applicant',
    $applicantId
);

Session::flash('success', "Course suggestion sent: {$course}.");
redirect('/staff/results');
