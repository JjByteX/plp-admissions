<?php
// ============================================================
// config/app.php
// Application-wide constants
// ============================================================

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
    $__scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__script = $_SERVER['SCRIPT_NAME'] ?? '/plp-admissions/public/index.php';
    $__base   = rtrim(dirname(dirname($__script)), '/\\');
    define('BASE_URL', $__scheme . '://' . $__host . $__base . '/public');
    unset($__scheme, $__host, $__script, $__base);
}

// -- Session -----------------------------------------------------
define('SESSION_NAME',    'plp_session');
define('SESSION_LIFETIME', 7200);

// -- File uploads ------------------------------------------------
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);
define('ALLOWED_MIME_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

// -- Roles -------------------------------------------------------
define('ROLE_STUDENT', 'student');
define('ROLE_STAFF',   'staff');
define('ROLE_ADMIN',   'admin');

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
define('SHS_STRANDS', [
    'ABM'        => 'ABM — Accountancy, Business and Management',
    'STEM'       => 'STEM — Science, Technology, Engineering and Mathematics',
    'HUMSS'      => 'HUMSS — Humanities and Social Sciences',
    'GAS'        => 'GAS — General Academic Strand',
    'TVL-HE'     => 'TVL — Home Economics',
    'TVL-ICT'    => 'TVL — Information and Communications Technology',
    'TVL-Sports' => 'TVL — Sports',
    'TVL-IA'     => 'TVL — Industrial Arts',
    'Arts'       => 'Arts and Design Track',
    'Sports'     => 'Sports Track',
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
define('RESULT_LABELS', [
    'accepted'   => 'Accepted',
    'waitlisted' => 'Waitlisted',
    'rejected'   => 'Rejected',
]);

// -- hCaptcha ----------------------------------------------------
define('HCAPTCHA_SITE_KEY',   getenv('HCAPTCHA_SITE_KEY')   ?: '');
define('HCAPTCHA_SECRET_KEY', getenv('HCAPTCHA_SECRET_KEY') ?: '');
define('HCAPTCHA_ENABLED',    !empty(HCAPTCHA_SITE_KEY) && !empty(HCAPTCHA_SECRET_KEY));

// -- Progress steps (student tracker) ---------------------------
define('PROGRESS_STEPS', [
    'register'  => 'Register',
    'documents' => 'Submit Documents',
    'exam'      => 'Entrance Exam',
    'interview' => 'Interview',
    'result'    => 'Result',
]);
