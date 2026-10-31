<?php
// Run: php tests/enrollment_handoff_check.php
// Checks the pure helpers of the Enrollment handoff. No database, no network.
// ponytail: plain asserts, no framework; the send and retry flow is covered by the manual end to end test.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/enrollment_handoff.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

// Every built-in course has a code, and the code is what Enrollment seeds.
$expected = [
    'BS Accountancy (BSA)' => 'BSA',
    'BS Business Administration major in Marketing Management (BSBA)' => 'BSBA',
    'BS Entrepreneurship (BSENT)' => 'BSENT',
    'BS Hospitality Management (BSHM)' => 'BSHM',
    'Bachelor of Elementary Education (BEED)' => 'BEED',
    'Bachelor of Secondary Education Major in English (BSED-ENG)' => 'BSED-ENG',
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)' => 'BSED-FIL',
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)' => 'BSED-MATH',
    'AB Psychology (AB Psych)' => 'AB Psych',
    'BS Computer Science (BSCS)' => 'BSCS',
    'BS Information Technology (BSIT)' => 'BSIT',
    'BS Electronics Engineering (BSECE)' => 'BSECE',
    'BS Nursing (BSN)' => 'BSN',
];
foreach (PLP_COURSES as $course) {
    check(array_key_exists($course, $expected), "course not covered by this test: $course");
}
foreach ($expected as $course => $code) {
    check(enrollment_course_code($course) === $code, "$course gives code $code");
}
check(count($expected) === count(PLP_COURSES), 'test list matches PLP_COURSES');

check(enrollment_course_code('Some Custom Course') === null, 'a course without brackets has no code');
check(enrollment_course_code('Custom Course ()') === null, 'empty brackets have no code');
check(enrollment_course_code('  BS Nursing (BSN)  ') === 'BSN', 'surrounding spaces are ignored');

check(enrollment_applicant_reference('2026-2027', 42) === 'ADM-2026-000042', 'reference is padded to 6');
check(enrollment_applicant_reference('2026-2027', 1234567) === 'ADM-2026-1234567', 'long ids are not cut');
check(enrollment_applicant_reference('next year', 1) === null, 'school year without a year is rejected');
check(preg_match('/^[A-Za-z0-9-]{3,40}$/', enrollment_applicant_reference('2025-2026', 7)) === 1, 'matches the receiver format');

check(enrollment_admission_type('freshman') === 'new', 'freshman is new');
check(enrollment_admission_type('transferee') === 'transferee', 'transferee stays transferee');
check(enrollment_admission_type('foreign') === 'foreign', 'foreign stays foreign');

check(enrollment_full_name(['first_name' => 'Ana', 'middle_name' => 'Cruz', 'last_name' => 'Reyes', 'suffix' => 'Jr.']) === 'Ana Cruz Reyes Jr.', 'name order');
check(enrollment_full_name(['first_name' => '', 'last_name' => '', 'name' => 'Ana Reyes']) === 'Ana Reyes', 'falls back to the combined name');

// Enrollment's answers.
check(enrollment_classify_answer(201, ['status' => 'created', 'applicant_ref' => 'X'])[0] === 'sent', '201 created is sent');
check(enrollment_classify_answer(200, ['status' => 'updated'])[1] === 'updated', '200 updated is kept');
check(enrollment_classify_answer(200, ['status' => 'unchanged'])[0] === 'sent', '200 unchanged is sent');
check(enrollment_classify_answer(200, ['status' => 'weird'])[0] === 'pending', 'unknown 200 body stays pending');
check(enrollment_classify_answer(400, ['error' => 'bad'])[0] === 'rejected', '400 is rejected');
check(enrollment_classify_answer(409, ['error' => 'email taken'])[2] === 'email taken', '409 keeps the reason');
check(enrollment_classify_answer(422, ['error' => 'no program'])[0] === 'rejected', '422 is rejected');
check(enrollment_classify_answer(401, null)[0] === 'pending', '401 stays pending');
check(enrollment_classify_answer(503, ['error' => 'off'])[0] === 'pending', '503 stays pending');
check(enrollment_classify_answer(500, null)[0] === 'pending', '500 stays pending');
check(enrollment_classify_answer(null, null, 'timed out')[0] === 'pending', 'no answer stays pending');

check(enrollment_verified_documents([]) === [], 'no approved documents, none verified');
check(enrollment_verified_documents(['psa_birth_cert', 'alien_cert', 'psa_birth_cert']) === ['acr', 'psa_birth_cert'], 'mapped, unique and sorted');
check(enrollment_verified_documents(['valid_id_1', 'photo_1', 'barangay_cert']) === [], 'papers Enrollment asks for differently are not mapped');

check(enrollment_status_parse(['applicant_ref' => 'ADM-2026-000042', 'stage' => 'enrolled', 'student_number' => '2026-00012']) === [42, 'enrolled', '2026-00012'], 'status call is read');
check(is_string(enrollment_status_parse(['applicant_ref' => 'nope', 'stage' => 'enrolled', 'student_number' => '1'])), 'bad ref is refused');
check(is_string(enrollment_status_parse(['applicant_ref' => 'ADM-2026-000042', 'stage' => 'graduated', 'student_number' => '1'])), 'unknown stage is refused');
check(is_string(enrollment_status_parse(['applicant_ref' => 'ADM-2026-000042', 'stage' => 'student', 'student_number' => ''])), 'missing student number is refused');

check(enrollment_retry_delay(0) === 0, 'first retry has no wait');
check(enrollment_retry_delay(3) === 900, 'wait grows by 5 minutes per attempt');
check(enrollment_retry_delay(99) === 3600, 'wait stops growing at 1 hour');
check(enrollment_retry_due(0, null, 1000) === true, 'never tried is due');
check(enrollment_retry_due(2, 1000, 1500) === false, 'not due inside the wait');
check(enrollment_retry_due(2, 1000, 1600) === true, 'due once the wait has passed');
check(enrollment_retry_due(20, null, 1000) === false, 'gives up after 20 attempts');

// The visit Enrollment booked.
$visitAnswer = ['status' => 'created', 'visit' => ['date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '11:00', 'office_name' => 'Registrar Office']];
check(enrollment_visit_from_answer($visitAnswer) === ['date' => '2026-11-02', 'start' => '09:00', 'end' => '11:00', 'office' => 'Registrar Office'], 'visit is read');
check(enrollment_visit_from_answer(['status' => 'created']) === null, 'no visit in the answer');
check(enrollment_visit_from_answer(null) === null, 'no answer, no visit');
check(enrollment_visit_from_answer(['visit' => ['date' => '2026-02-31', 'start_time' => '09:00', 'end_time' => '11:00', 'office_name' => 'X']]) === null, 'impossible date is refused');
check(enrollment_visit_from_answer(['visit' => ['date' => '2026-11-02', 'start_time' => '25:00', 'end_time' => '11:00', 'office_name' => 'X']]) === null, 'impossible time is refused');
check(enrollment_visit_from_answer(['visit' => ['date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '11:00', 'office_name' => '  ']]) === null, 'blank office is refused');

// One confirmation message.
$sentWithVisit = ['status' => 'sent', 'enrollment_result' => 'created', 'visit_date' => '2026-11-02', 'visit_start_time' => '09:00:00', 'visit_end_time' => '11:00:00', 'visit_office' => 'Registrar Office'];
check(enrollment_confirmation_note($sentWithVisit) === 'Your next step: go to Registrar Office on Monday, November 2, 2026, 9:00 AM to 11:00 AM. Open the email from PLP Enrollment to set your password and see what to bring.', 'note carries the visit');
check(str_contains(enrollment_confirmation_note(array_merge($sentWithVisit, ['enrollment_result' => 'unchanged'])), 'Sign in to PLP Enrollment'), 'an existing account signs in');
check(str_contains(enrollment_confirmation_note(['status' => 'sent', 'enrollment_result' => 'created']), 'once it is set'), 'sent without a visit says it will come');
check(str_contains(enrollment_confirmation_note(['status' => 'pending']), 'will email you'), 'a pending send promises the email');
check(str_contains(enrollment_confirmation_note(null), 'will email you'), 'no outbox row promises the email');

// Same login on both sides: the bcrypt hash is shared, a Google sign-up has none.
$realHash = password_hash('Demo@12345', PASSWORD_BCRYPT, ['cost' => 4]);
$shared   = enrollment_shared_password_hash($realHash);
check($shared !== null && str_starts_with($shared, '$2b$'), 'a bcrypt hash is shared with the $2b$ prefix');
check($shared !== null && password_verify('Demo@12345', $shared), 'the shared hash still matches the same password');
check(strlen((string) $shared) === 60, 'the shared hash keeps its length');
check(enrollment_shared_password_hash('!' . bin2hex(random_bytes(32))) === null, 'a Google sign-up has no hash to share');
check(enrollment_shared_password_hash('') === null, 'an empty hash is not shared');
check(enrollment_shared_password_hash(null) === null, 'no hash is not shared');
check(enrollment_shared_password_hash('Demo@12345') === null, 'a plain password is never passed along as a hash');

// The message says to sign in when the account takes the Admissions password.
$sharedNote = enrollment_confirmation_note($sentWithVisit, true);
check($sharedNote === 'Your next step: go to Registrar Office on Monday, November 2, 2026, 9:00 AM to 11:00 AM. Sign in to PLP Enrollment with the same email and password you use here to see what to bring.', 'shared login note carries the visit');
check(!str_contains($sharedNote, 'email from PLP Enrollment'), 'shared login note sends no email');
check(str_contains(enrollment_confirmation_note(['status' => 'sent', 'enrollment_result' => 'created'], true), 'same email and password'), 'shared login without a visit says sign in');
check(str_contains(enrollment_confirmation_note(['status' => 'pending'], true), 'same email and password'), 'a pending shared login says what to sign in with');
check(!str_contains(enrollment_confirmation_note(['status' => 'pending'], true), 'link to set'), 'a pending shared login does not promise a link');
check(str_contains(enrollment_confirmation_note(array_merge($sentWithVisit, ['enrollment_result' => 'unchanged']), true), 'Sign in to PLP Enrollment to see what to bring'), 'an existing account note is the same either way');

// Program list check.
putenv('ENROLLMENT_INTAKE_URL=https://example.test/api/admissions/intake');
check(enrollment_programs_url() === 'https://example.test/api/admissions/programs', 'programs URL comes from the intake URL');
putenv('ENROLLMENT_INTAKE_URL=https://example.test/other');
check(enrollment_programs_url() === '', 'an unexpected intake URL gives no programs URL');
putenv('ENROLLMENT_INTAKE_URL');
$codes = ['BSA', 'BSIT', 'ab psych'];
check(enrollment_unmatched_courses(['BS Accountancy (BSA)', 'AB Psychology (AB Psych)'], $codes) === [], 'known codes match, whatever the letter case');
check(enrollment_unmatched_courses(['BS Accountancy (BSA)', 'BS Biology (BSBIO)', 'Custom Course'], $codes) === ['BS Biology (BSBIO)', 'Custom Course'], 'unknown code and missing code are reported');

echo "enrollment handoff checks passed\n";
