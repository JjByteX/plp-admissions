<?php
// ============================================================
// config/app.php
// Application-wide constants
// ============================================================

// -- Environment -------------------------------------------------
define('APP_ENV', 'development');   // 'development' | 'production'
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
// Change to your InfinityFree subdomain in production
define('BASE_URL', APP_ENV === 'production'
    ? 'https://plp-admissions.rf.gd'
    : 'http://localhost/plp-admissions/public'
);

// -- Session -----------------------------------------------------
define('SESSION_NAME',    'plp_session');
define('SESSION_LIFETIME', 7200);   // 2 hours inactivity timeout (seconds)

// -- File uploads ------------------------------------------------
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);   // 5 MB per document
define('ALLOWED_MIME_TYPES', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

// -- Roles -------------------------------------------------------
define('ROLE_STUDENT', 'student');
define('ROLE_STAFF',   'staff');
define('ROLE_ADMIN',   'admin');

// -- Applicant types ---------------------------------------------
define('TYPE_FRESHMAN',   'freshman');
define('TYPE_TRANSFEREE', 'transferee');
define('TYPE_FOREIGN',    'foreign');

// -- Document slugs (core documents required by all) -------------
define('DOCS_CORE', [
    'psa_birth_cert'     => 'PSA Birth Certificate',
    'report_card'        => 'Report Card (Form 138 / SF9)',
    'good_moral'         => 'Certificate of Good Moral Character',
    'id_pictures'        => 'ID Pictures (1×1 or 2×2)',
    'hs_diploma'         => 'High School Diploma / Certificate of Graduation',
]);

// -- Additional docs for transferees -----------------------------
define('DOCS_TRANSFEREE', [
    'tor'                => 'Transcript of Records (TOR)',
    'honorable_dismissal'=> 'Honorable Dismissal / Transfer Credentials',
    'college_tor'        => 'TOR from Previous College Enrollment',
]);

// -- Additional docs for foreign students -----------------------
define('DOCS_FOREIGN', [
    'passport'           => 'Passport',
    'visa_permit'        => 'Visa or Study Permit',
    'alien_cert'         => 'Alien Certificate of Registration',
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

// -- Progress steps (student tracker) ---------------------------
define('PROGRESS_STEPS', [
    'register'  => 'Register',
    'documents' => 'Submit Documents',
    'exam'      => 'Entrance Exam',
    'interview' => 'Interview',
    'result'    => 'Result',
]);
