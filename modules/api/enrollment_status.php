<?php
// ============================================================
// modules/api/enrollment_status.php
// POST from the Enrollment system: an applicant became a student, or was
// enrolled (COR issued). No login and no CSRF: the caller proves itself with
// the shared secret, like the intake call in the other direction.
//
// Body (JSON): { "applicant_ref": "ADM-2026-000042",
//                "stage": "student" | "enrolled",
//                "student_number": "2026-00012" }
// Setting (environment): ENROLLMENT_STATUS_SECRET, must equal
// ADMISSIONS_STATUS_SECRET in the Enrollment project.
// Safe to repeat. The stage only moves forward, so a late or repeated
// "student" never undoes "enrolled".
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

$secret = trim((string) getenv('ENROLLMENT_STATUS_SECRET'));
if ($secret === '') {
    json_response(['error' => 'The status endpoint is not configured.'], 503);
}

// Apache can hide the Authorization header from PHP; Vercel passes it through.
$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $header = (string) $value;
        }
    }
}
if (!str_starts_with($header, 'Bearer ') || !hash_equals($secret, trim(substr($header, 7)))) {
    json_response(['error' => 'Not authorized.'], 401);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$parsed = enrollment_status_parse(is_array($body) ? $body : []);
if (is_string($parsed)) {
    json_response(['error' => $parsed], 400);
}
[$applicantId, $stage, $studentNumber] = $parsed;

try {
    $db   = db();
    $stmt = $db->prepare(
        "UPDATE enrollment_handoff_outbox
            SET enrollment_stage = ?, enrollment_student_number = ?, enrollment_stage_at = NOW()
          WHERE applicant_id = ?
            AND (enrollment_stage IS NULL OR enrollment_stage = 'student')
            AND (enrollment_stage IS DISTINCT FROM ?)"
    );
    $stmt->execute([$stage, $studentNumber, $applicantId, $stage]);

    if ($stmt->rowCount() > 0) {
        audit_log(AuditAction::ENROLLMENT_STATUS_PREFIX . $stage, "Applicant {$applicantId}: Enrollment reports {$stage} {$studentNumber}", 'applicant', $applicantId);
        json_response(['status' => 'recorded'], 200);
    }

    $exists = $db->prepare('SELECT 1 FROM enrollment_handoff_outbox WHERE applicant_id = ?');
    $exists->execute([$applicantId]);
    if (!$exists->fetchColumn()) {
        json_response(['error' => 'No such applicant was sent to Enrollment.'], 404);
    }
    json_response(['status' => 'unchanged'], 200);
} catch (\Throwable $e) {
    error_log('enrollment status error: ' . $e->getMessage());
    json_response(['error' => 'The status could not be recorded.'], 500);
}
