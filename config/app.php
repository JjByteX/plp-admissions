<?php
// ============================================================
// config/app.php
// Application-wide constants
// ============================================================

// -- Load .env file (local secrets) ------------------------------
(function () {
    $envFile = dirname(__DIR__) . '/.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (!getenv($key)) {
            putenv("$key=$val");
        }
    }
})();

// -- Environment -------------------------------------------------
define('APP_ENV', getenv('APP_ENV') ?: 'development');   // 'development' | 'production'
define('APP_DEBUG', APP_ENV === 'development');

// -- Paths -------------------------------------------------------
define('ROOT_PATH',   dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('CORE_PATH',   ROOT_PATH . '/core');
define('MODULES_PATH',ROOT_PATH . '/modules');
define('VIEWS_PATH',  ROOT_PATH . '/views');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('UPLOAD_PATH', PUBLIC_PATH . '/uploads');

// -- URL ---------------------------------------------------------
if (APP_ENV === 'production') {
    define('BASE_URL', rtrim(getenv('APP_URL') ?: 'https://plp-admissions.vercel.app', '/'));
} else {
    $__forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $__scheme = ($__forwarded === 'https'
        || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))
        ? 'https' : 'http';
    $__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__script = $_SERVER['SCRIPT_NAME'] ?? '/plp-admissions/public/index.php';
    $__base   = rtrim(dirname(dirname($__script)), '/\\');
    define('BASE_URL', $__scheme . '://' . $__host . $__base . '/public');
    unset($__scheme, $__host, $__script, $__base);
}

// -- Session -----------------------------------------------------
define('SESSION_NAME',    'plp_session');
define('SESSION_LIFETIME_STUDENT', 1800);   // 30 min
define('SESSION_LIFETIME_STAFF',   7200);   // 2 hours
define('SESSION_WARN_BEFORE',       300);   // warn 5 min before expiry

// -- File uploads ------------------------------------------------
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);
define('ALLOWED_MIME_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

// -- Roles -------------------------------------------------------
// DB enum values stored on users.role:
//   'student' | 'staff' | 'sso' | 'dean' | 'admin'
//
// ROLE_STAFF and ROLE_PROFESSOR both refer to the 'staff' enum value —
// "Professor" is just the user-facing label for legacy 'staff' rows
// (faculty who proctor exams and conduct interviews). SSO and Dean are
// new top-level roles introduced for the role-redesign rollout.
define('ROLE_STUDENT',   'student');
define('ROLE_STAFF',     'staff');
define('ROLE_PROFESSOR', 'staff');     // alias — DB enum stays 'staff'
define('ROLE_SSO',       'sso');
define('ROLE_DEAN',      'dean');
define('ROLE_ADMIN',     'admin');

// -- Applicant types ---------------------------------------------
define('TYPE_FRESHMAN',   'freshman');
define('TYPE_TRANSFEREE', 'transferee');
define('TYPE_FOREIGN',    'foreign');

// ----------------------------------------------------------------
// OFFICIAL PLP DOCUMENTARY REQUIREMENTS
// Source: Pamantasan ng Lungsod ng Pasig Admission Requirements
// ----------------------------------------------------------------

// -- Documents shared by BOTH freshmen and transferees -----------
define('DOCS_CORE', [
    'applicant_id'       => 'Government-issued ID / School ID (Applicant)',
    'psa_birth_cert'     => 'PSA Birth Certificate',
    'passport_photos'    => 'Passport-size Photos, white background with nameplate',
    'parent_id'          => 'Government-issued ID of Parent/Guardian',
    'proof_of_income'    => 'Proof of Income of Parents (ITR, DSWD Case Study, or DSWD Beneficiary ID)',
    'guardianship_affidavit' => 'Notarized Affidavit of Guardianship (for applicants under a guardian)',
]);

// -- Freshman-only docs ------------------------------------------
// Note: Form 138 is for currently graduating Grade 12; Form 137 is for SHS graduates.
// Applicants upload whichever applies to them.
define('DOCS_FRESHMAN', [
    'form_138'           => 'CTC of Grade 11 Form 138 (for currently enrolled Grade 12)',
    'form_137'           => 'CTC of Form 137 with remark "For Evaluation Purposes Only" (for SHS graduates)',
]);

// -- Transferee-only docs ----------------------------------------
define('DOCS_TRANSFEREE', [
    'tor'                => 'CTC of Transcript of Records (TOR) — "For Evaluation Purposes Only"',
    'good_moral'         => 'Certificate of Good Moral Character',
]);

// -- Foreign student docs (kept for system completeness) ---------
define('DOCS_FOREIGN', [
    'tor'                => 'CTC of Transcript of Records (TOR) — "For Evaluation Purposes Only"',
    'good_moral'         => 'Certificate of Good Moral Character',
    'passport'           => 'Passport',
    'visa_permit'        => 'Visa or Study Permit',
    'alien_cert'         => 'Alien Certificate of Registration',
]);

// -- Official PLP courses offered --------------------------------
define('PLP_COURSES', [
    'BS Accountancy (BSA)',
    'BS Business Administration major in Marketing Management (BSBA)',
    'BS Entrepreneurship (BSENT)',
    'BS Hospitality Management (BSHM)',
    'Bachelor of Elementary Education (BEED)',
    'Bachelor of Secondary Education Major in English (BSED-ENG)',
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)',
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)',
    'AB Psychology (AB Psych)',
    'BS Computer Science (BSCS)',
    'BS Information Technology (BSIT)',
    'BS Electronics Engineering (BSECE)',
    'BS Nursing (BSN)',
]);

// -- Departments / Colleges -------------------------------------
// Keep the canonical name in sync with `departments.name` (DB).
define('DEPT_CCS', 'College of Computer Studies');
define('DEPT_CON', 'College of Nursing');
define('DEPT_CBA', 'College of Business and Accountancy');
define('DEPT_COE', 'College of Education');
define('DEPT_CAS', 'College of Arts and Sciences');
define('DEPT_CEN', 'College of Engineering');

define('PLP_DEPARTMENTS', [
    DEPT_CCS,
    DEPT_CON,
    DEPT_CBA,
    DEPT_COE,
    DEPT_CAS,
    DEPT_CEN,
]);

// Course → department mapping.  This is the config-level fallback;
// the `course_departments` DB table is the source of truth once seeded.
define('COURSE_DEPARTMENT_MAP', [
    'BS Information Technology (BSIT)'                                  => DEPT_CCS,
    'BS Computer Science (BSCS)'                                        => DEPT_CCS,
    'BS Nursing (BSN)'                                                  => DEPT_CON,
    'BS Accountancy (BSA)'                                              => DEPT_CBA,
    'BS Business Administration major in Marketing Management (BSBA)'   => DEPT_CBA,
    'BS Entrepreneurship (BSENT)'                                       => DEPT_CBA,
    'BS Hospitality Management (BSHM)'                                  => DEPT_CBA,
    'Bachelor of Elementary Education (BEED)'                           => DEPT_COE,
    'Bachelor of Secondary Education Major in English (BSED-ENG)'       => DEPT_COE,
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)'      => DEPT_COE,
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)'  => DEPT_COE,
    'AB Psychology (AB Psych)'                                          => DEPT_CAS,
    'BS Electronics Engineering (BSECE)'                                => DEPT_CEN,
]);

// -- Strand requirements per course (freshmen only) --------------
// Applicants should apply only to courses where their SHS strand is applicable.
define('COURSE_STRAND_MAP', [
    'BS Accountancy (BSA)'                                               => ['ABM'],
    'BS Business Administration major in Marketing Management (BSBA)'   => ['ABM'],
    'BS Entrepreneurship (BSENT)'                                        => ['ABM'],
    'BS Hospitality Management (BSHM)'                                   => ['ABM', 'TVL-HE'],
    'Bachelor of Elementary Education (BEED)'                            => ['HUMSS', 'GAS', 'TVL-Sports'],
    'Bachelor of Secondary Education Major in English (BSED-ENG)'       => ['HUMSS', 'GAS', 'STEM'],
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)'      => ['HUMSS', 'GAS', 'STEM'],
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)'  => ['HUMSS', 'GAS', 'STEM'],
    'AB Psychology (AB Psych)'                                           => ['HUMSS', 'STEM'],
    'BS Computer Science (BSCS)'                                         => ['STEM'],
    'BS Information Technology (BSIT)'                                   => ['STEM', 'TVL-ICT'],
    'BS Electronics Engineering (BSECE)'                                 => ['STEM'],
    'BS Nursing (BSN)'                                                   => ['STEM'],
]);

// -- All SHS strands (for the registration dropdown) -------------
// Only strands accepted by at least one course in COURSE_STRAND_MAP are listed.
// TVL-IA, Arts Track, and Sports Track are valid DepEd strands but PLP currently
// has no courses that accept them — they have been removed to prevent applicants
// from hitting a registration dead-end. Add them back here and to COURSE_STRAND_MAP
// if PLP adds a qualifying course in a future school year.
define('SHS_STRANDS', [
    'ABM'        => 'ABM — Accountancy, Business and Management',
    'STEM'       => 'STEM — Science, Technology, Engineering and Mathematics',
    'HUMSS'      => 'HUMSS — Humanities and Social Sciences',
    'GAS'        => 'GAS — General Academic Strand',
    'TVL-HE'     => 'TVL — Home Economics',
    'TVL-ICT'    => 'TVL — Information and Communications Technology',
    'TVL-Sports' => 'TVL — Sports',
]);

// -- Document status labels -------------------------------------
define('DOC_STATUS_LABELS', [
    'pending'      => 'Pending',
    'uploaded'     => 'Uploaded',
    'under_review' => 'Under Review',
    'approved'     => 'Approved',
    'rejected'     => 'Rejected',
]);

// -- Admission result labels ------------------------------------
// Waitlist tier was retired in the role redesign — the only outcomes
// are Accepted or Rejected (a Professor's interview Fail blocks
// acceptance entirely). Legacy 'waitlisted' rows in the DB still
// render as "Waitlisted (legacy)" via the fallback in pages that
// read this constant.
define('RESULT_LABELS', [
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
]);

// ----------------------------------------------------------------
// EXAM & INTERVIEW CAPACITY (from client interview)
// ----------------------------------------------------------------
define('EXAM_DEFAULT_DURATION',   90);   // 1 hr 30 min default (customizable)
define('EXAM_ROOM_CAPACITY',      35);   // max applicants per room
define('EXAM_DAILY_CAP',        3000);   // max applicants per day (all courses)

// ----------------------------------------------------------------
// PER-COURSE PASSING SCORE TIERS
// Source: client interview — BSIT confirmed; all others use same
// tier system pending client confirmation of exact passing marks.
//
// Ranking tiers (1–10 scale):
//   High    10, 9, 8, 7  → Passed
//   Average  6, 5, 4     → Passed
//   Low      3, 2, 1     → Rejected
// ----------------------------------------------------------------
define('SCORE_TIER_HIGH',    ['min' => 7, 'max' => 10, 'label' => 'High',    'verdict' => 'passed']);
define('SCORE_TIER_AVERAGE', ['min' => 4, 'max' => 6,  'label' => 'Average', 'verdict' => 'passed']);
define('SCORE_TIER_LOW',     ['min' => 1, 'max' => 3,  'label' => 'Low',     'verdict' => 'rejected']);

// Per-course passing configuration.
// 'pass_from' = minimum score to pass (scores >= pass_from → Passed).
// 'confirmed' = true if the client has confirmed the exact threshold.
// Default: pass_from=4 (Average tier and above pass) per BSIT-confirmed rule.
define('COURSE_PASSING_SCORES', [
    'BS Accountancy (BSA)'                                               => ['pass_from' => 4, 'confirmed' => false],
    'BS Business Administration major in Marketing Management (BSBA)'   => ['pass_from' => 4, 'confirmed' => false],
    'BS Entrepreneurship (BSENT)'                                        => ['pass_from' => 4, 'confirmed' => false],
    'BS Hospitality Management (BSHM)'                                   => ['pass_from' => 4, 'confirmed' => false],
    'Bachelor of Elementary Education (BEED)'                            => ['pass_from' => 4, 'confirmed' => false],
    'Bachelor of Secondary Education Major in English (BSED-ENG)'       => ['pass_from' => 4, 'confirmed' => false],
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)'      => ['pass_from' => 4, 'confirmed' => false],
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)'  => ['pass_from' => 4, 'confirmed' => false],
    'AB Psychology (AB Psych)'                                           => ['pass_from' => 4, 'confirmed' => false],
    'BS Computer Science (BSCS)'                                         => ['pass_from' => 4, 'confirmed' => false],
    'BS Information Technology (BSIT)'                                   => ['pass_from' => 4, 'confirmed' => true],  // confirmed
    'BS Electronics Engineering (BSECE)'                                 => ['pass_from' => 4, 'confirmed' => false],
    'BS Nursing (BSN)'                                                   => ['pass_from' => 4, 'confirmed' => false],
]);

// -- hCaptcha ----------------------------------------------------
define('HCAPTCHA_SITE_KEY',   getenv('HCAPTCHA_SITE_KEY')   ?: '');
define('HCAPTCHA_SECRET_KEY', getenv('HCAPTCHA_SECRET_KEY') ?: '');
define('HCAPTCHA_ENABLED',    !empty(HCAPTCHA_SITE_KEY) && !empty(HCAPTCHA_SECRET_KEY));

// -- Email (Gmail SMTP via PHPMailer) -----------------------------
define('SMTP_HOST',       getenv('SMTP_HOST')       ?: 'smtp.gmail.com');
define('SMTP_PORT',       getenv('SMTP_PORT')       ?: 587);
define('SMTP_USER',       getenv('SMTP_USER')       ?: '');
define('SMTP_PASS',       getenv('SMTP_PASS')       ?: '');
define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME')  ?: 'PLP Admissions');
define('SMTP_ENABLED',    !empty(SMTP_USER) && !empty(SMTP_PASS));

// -- Email verification ------------------------------------------
define('VERIFY_RESEND_COOLDOWN_SECS', 60);   // wait between resend requests
define('VERIFY_CODE_TTL_SECS',        15 * 60);  // 15-minute code lifetime
define('VERIFY_MAX_CODE_ATTEMPTS',    5);    // bad-code attempts per credential

// -- Progress steps (student tracker) ---------------------------
define('PROGRESS_STEPS', [
    'register'  => 'Register',
    'documents' => 'Submit Documents',
    'exam'      => 'Entrance Exam',
    'interview' => 'Interview',
    'result'    => 'Result',
]);
