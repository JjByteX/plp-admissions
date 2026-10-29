<?php
// ============================================================
// core/helpers.php
// Globally available utility functions
// ============================================================

// -- URL ---------------------------------------------------------
function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $href = url('assets/' . $path);
    // Version by file time so a changed app.css / app.js is never served stale.
    $file = dirname(__DIR__) . '/public/assets/' . $path;
    return is_file($file) ? $href . '?v=' . filemtime($file) : $href;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

require_once __DIR__ . '/helpers/http.php';

// -- Bulk delete of empty slots ----------------------------------
// Deletes the given slot ids that have no child rows (bookings) and skips the rest.
// $cfg comes from code constants, never request data:
//   table, child, fk (child column pointing at table.id), noun, audit,
//   skipNote (e.g. 'have applicants'), allNote (e.g. 'have bookings')
// Returns ['deleted' => int, 'skipped' => int].
function bulk_delete_empty(PDO $db, array $cfg, array $ids): array
{
    $result = ['deleted' => 0, 'skipped' => 0];
    if (!$ids) {
        return $result;
    }
    [$ph, $params] = sql_in($ids);
    $stmt = $db->prepare(
        "SELECT s.id, COUNT(c.id) AS filled
           FROM {$cfg['table']} s
           LEFT JOIN {$cfg['child']} c ON c.{$cfg['fk']} = s.id
          WHERE s.id IN ({$ph})
          GROUP BY s.id"
    );
    $stmt->execute($params);
    $deletable = [];
    foreach ($stmt->fetchAll() as $row) {
        if ((int)$row['filled'] > 0) { $result['skipped']++; continue; }
        $deletable[] = (int)$row['id'];
    }
    if ($deletable) {
        [$delPh, $delParams] = sql_in($deletable);
        $del = $db->prepare("DELETE FROM {$cfg['table']} WHERE id IN ({$delPh})");
        $del->execute($delParams);
        $result['deleted'] = $del->rowCount();
        audit_log($cfg['audit'],
            "Bulk-deleted {$result['deleted']} {$cfg['noun']}(s): " . implode(',', $deletable));
    }
    return $result;
}

// Flashes the standard "N removed; M skipped" / "N removed" / "Nothing removed" message.
function flash_bulk_delete_result(array $r, array $cfg): void
{
    $n = $r['deleted'];
    $m = $r['skipped'];
    $noun = $cfg['noun'];
    if ($n > 0 && $m > 0) {
        Session::flash('success', "{$n} {$noun}(s) removed; {$m} skipped ({$cfg['skipNote']}).");
    } elseif ($n > 0) {
        Session::flash('success', "{$n} {$noun}(s) removed.");
    } elseif ($m > 0) {
        Session::flash('error', "Nothing removed - all {$m} selected {$noun}(s) {$cfg['allNote']}.");
    }
}

// -- Student reschedule request -----------------------------------
// Shared flow for the interview and exam reschedule endpoints. The module
// still does its own Auth::requireRole() + csrf_check() first. $cfg comes
// from code constants (table / column names), never request data:
//   cols       applicants columns to load (e.g. 'id, overall_status')
//   redirect   page to return to
//   table      requests table; inserts applicant_id, reason + resolve() columns
//   resolve    fn(array $applicant, PDO $db): array  - checks stage / active slot
//              (call fail() itself) and returns extra insert columns, e.g. ['slot_id' => 5]
//   pendingMsg flash text when a pending request already exists
//   auditAction, auditLabel   audit entry, e.g. 'exam_reschedule_requested', 'EXAM reschedule'
//   roles, notifyType, notifyTitle, notifyMessage, notifyLink   reviewer notification
//   successMsg
function submit_reschedule_request(array $cfg): never
{
    $db        = db();
    $applicant = applicant_for_user((int) Auth::id(), $cfg['cols']);
    if (!$applicant) {
        fail('No application found.', $cfg['redirect']);
    }

    $extra = ($cfg['resolve'])($applicant, $db);

    $reason = post_str('reschedule_reason');
    if (!$reason) {
        fail('Please provide a reason for rescheduling.', $cfg['redirect']);
    }

    // One pending request per applicant.
    $stmt = $db->prepare("SELECT id FROM {$cfg['table']} WHERE applicant_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$applicant['id']]);
    if ($stmt->fetch()) {
        fail($cfg['pendingMsg'], $cfg['redirect']);
    }

    $cols = array_merge(['applicant_id' => $applicant['id']], $extra, ['reason' => $reason]);
    $db->prepare(
        "INSERT INTO {$cfg['table']} (" . implode(', ', array_keys($cols)) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')'
    )->execute(array_values($cols));

    audit_log(
        $cfg['auditAction'],
        "Applicant {$applicant['id']} requested {$cfg['auditLabel']}: {$reason}",
        'applicant',
        (int) $applicant['id']
    );

    notify_roles($cfg['roles'], $cfg['notifyType'], $cfg['notifyTitle'], $cfg['notifyMessage'], $cfg['notifyLink']);

    ok($cfg['successMsg'], $cfg['redirect']);
}

// -- Badge / label maps ------------------------------------------
// Badge colour name for a role ('error', 'warning', ...); used as "badge-<name>".
function role_badge_class(?string $role): string
{
    return match ($role) {
        ROLE_ADMIN   => 'error',
        ROLE_DEAN    => 'warning',
        ROLE_SSO     => 'success',
        ROLE_STAFF, ROLE_PROCTOR => 'info',
        default      => 'neutral',
    };
}

// Label + CSS class for a document status. Unknown statuses read as Pending.
function doc_status_badge(string $status): array
{
    static $map = [
        'pending'               => ['label' => 'Pending',               'class' => 'badge-pending'],
        'uploaded'              => ['label' => 'Uploaded',              'class' => 'badge-info'],
        'under_review'          => ['label' => 'Under Review',          'class' => 'badge-warning'],
        'approved'              => ['label' => 'Approved',              'class' => 'badge-success'],
        'rejected'              => ['label' => 'Declined',              'class' => 'badge-error'],
        'resubmission_required' => ['label' => 'Resubmission Required', 'class' => 'badge-error'],
    ];
    return $map[$status] ?? $map['pending'];
}

// -- Applicant lookup --------------------------------------------
// Latest applicant row for a user. $cols must be code-defined ('*' or a
// comma-separated column list), never request data; anything else throws.
function applicant_for_user(int $userId, string $cols = '*'): ?array
{
    if (!preg_match('/^(\*|[A-Za-z_][A-Za-z0-9_]*(\s*,\s*[A-Za-z_][A-Za-z0-9_]*)*)$/', $cols)) {
        throw new InvalidArgumentException('Invalid applicant column list.');
    }
    $stmt = db()->prepare("SELECT $cols FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// The signed-in user's applicant row, or redirect when they have none.
function require_applicant(string $cols = '*', string $redirectTo = '/student/documents'): array
{
    $applicant = applicant_for_user((int)Auth::id(), $cols);
    if (!$applicant) {
        redirect($redirectTo);
    }
    return $applicant;
}

// -- Settings modal ----------------------------------------------
// The settings pages are shown inside the settings modal (an iframe that
// loads them with ?embed=1; views/partials/settings_modal.php). In embed mode
// the layout draws only the page content: no sidebar, header or heading.
function is_embed(): bool
{
    return ($_GET['embed'] ?? '') === '1';
}

// Settings no longer has a page of its own. Opening its URL directly sends
// the user to their home page with the modal open. Embedded loads and form
// posts (which stay on the embed URL) pass straight through.
function settings_modal_gate(): void
{
    if (is_embed() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    require_once VIEWS_PATH . '/partials/page_header.php';
    redirect(page_home_crumb()['to'] . '?settings=1');
}

// -- Output escaping ---------------------------------------------
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// -- CSRF --------------------------------------------------------
function csrf_token(): string
{
    if (!Session::has('_csrf')) {
        Session::set('_csrf', bin2hex(random_bytes(32)));
    }
    return Session::get('_csrf');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['_csrf'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function csrf_check(): void
{
    if (!csrf_verify()) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

// -- Admissions window helpers ----------------------------------
function admissions_open_date(): ?DateTime
{
    $v = school_setting('admissions_open');
    return $v ? new DateTime($v) : null;
}

function admissions_close_date(): ?DateTime
{
    $v = school_setting('admissions_close');
    return $v ? new DateTime($v) : null;
}

function admissions_is_open(): bool
{
    $open  = admissions_open_date();
    $close = admissions_close_date();
    if (!$open || !$close) {
        return false;
    }
    $now = new DateTime();
    return $now >= $open && $now <= $close;
}

// -- School settings cache --------------------------------------
function school_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $rows  = db()->query('SELECT setting_key, setting_value FROM school_settings')->fetchAll();
            $cache = array_column($rows, 'setting_value', 'setting_key');
        } catch (Throwable) {
            $cache = [];
        }

        // Derive current_school_year automatically from admissions_open date
        if (!empty($cache['admissions_open'])) {
            $openYear = (int) date('Y', strtotime($cache['admissions_open']));
            $cache['current_school_year'] = $openYear . '-' . ($openYear + 1);
        }
    }
    return $cache[$key] ?? $default;
}

require_once __DIR__ . '/helpers/exam_scores.php';
require_once __DIR__ . '/helpers/docs.php';
// -- Formatting -------------------------------------------------
function format_date(string $date, string $format = 'F j, Y'): string
{
    return date($format, strtotime($date));
}

function format_time(string $time): string
{
    return date('g:i A', strtotime($time));
}

/** Today as "Y-m-d". One place to read the clock, so tests can reason about it. */
function now_date(): string
{
    return date('Y-m-d');
}

/** "Oct 28, 2026 11:49 PM" - date and time as shown in tables and panels. */
function format_datetime(string $dt): string
{
    return date('M j, Y g:i A', strtotime($dt));
}

/**
 * Format a person's full name as "LAST_NAME SUFFIX, FIRST_NAME MIDDLE_NAME".
 *
 * Accepts a row that contains any combination of `first_name`, `middle_name`,
 * `last_name`, `suffix`, plus optional fallbacks `name` / `student_name`.
 * If the split-name fields are empty, falls back to the legacy combined name.
 */
function format_full_name(array $row, string $fallback = '-'): string
{
    $first  = trim((string) ($row['first_name']  ?? ''));
    $middle = trim((string) ($row['middle_name'] ?? ''));
    $last   = trim((string) ($row['last_name']   ?? ''));
    $suffix = trim((string) ($row['suffix']      ?? ''));

    // Fall back to the legacy combined name when split parts are missing.
    if ($first === '' && $last === '') {
        $legacy = trim((string) ($row['name'] ?? $row['student_name'] ?? ''));
        return $legacy !== '' ? $legacy : $fallback;
    }

    $left  = trim($last  . ($suffix !== '' ? ' ' . $suffix : ''));
    $right = trim($first . ($middle !== '' ? ' ' . $middle : ''));

    if ($left === '') {
        return $right !== '' ? $right : $fallback;
    }
    if ($right === '') {
        return $left;
    }

    return $left . ', ' . $right;
}

// -- Applicant progress -----------------------------------------
// Returns which step is 'current' for a given applicant row
function current_step(array $applicant, ?array $examResult, ?array $interviewSlot, ?array $admissionResult): string
{
    $status = $applicant['overall_status'] ?? '';

    // Released with a decision recorded → show result step
    if ($admissionResult) {
        return 'result';
    }

    // Post-interview, awaiting staff decision
    if ($status === 'result') {
        return 'result';
    }

    // Released without a decision yet (edge case) → result step
    if ($status === 'released') {
        return 'result';
    }

    // Actively in interview stage
    if ($status === 'interview') {
        return 'interview';
    }
    if ($interviewSlot && $interviewSlot['status'] !== 'open') {
        return 'interview';
    }

    // Passed exam — waiting for interview assignment
    if ($examResult && !empty($examResult['passed'])) {
        return 'interview';
    }

    // Has exam result but failed — stays on exam step
    if ($examResult) {
        return 'exam';
    }

    if ($status === 'exam') {
        return 'exam';
    }
    if ($status === 'documents') {
        return 'documents';
    }
    return 'documents';
}

// -- File upload (Supabase Storage) -----------------------------
// Function name and signature are unchanged so every caller stays as is.
// Logos (school_logo_*) go to the branding bucket, everything else to
// the documents bucket. Returns the full public URL, or null on failure.
function uploadcare_upload(string $tmpPath, string $filename, string $mimeType): ?string
{
    if (SUPABASE_URL === '' || SUPABASE_SERVICE_KEY === '') {
        error_log('Supabase upload failed: SUPABASE_URL or SUPABASE_SERVICE_KEY is not set');
        return null;
    }

    $bucket  = str_starts_with($filename, 'school_logo_') ? SUPABASE_BUCKET_BRANDING : SUPABASE_BUCKET_DOCUMENTS;
    $base    = rtrim(SUPABASE_URL, '/') . '/storage/v1/object';
    $encName = rawurlencode($filename);

    $body = file_get_contents($tmpPath);
    if ($body === false) {
        error_log('Supabase upload failed: could not read temp file');
        return null;
    }

    $ch = curl_init($base . '/' . $bucket . '/' . $encName);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Content-Type: ' . $mimeType,
        ],
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log(
            'Supabase upload failed: HTTP ' . $status . ' ' . $curlErr . ' '
            . (is_string($response) ? $response : '')
        );
        return null;
    }

    return $base . '/public/' . $bucket . '/' . $encName;
}

/**
 * Convert a stored file_path (either a full https:// CDN URL or a
 * root-relative local path like /uploads/documents/foo.pdf) into a
 * fully-qualified URL safe for use in <img src> / <iframe src>.
 */
function file_url(string $filePath): string
{
    if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
        return $filePath; // Already a full CDN URL - return as-is.
    }
    return url($filePath); // Local path - prepend BASE_URL.
}

require_once __DIR__ . '/helpers/auth_pages.php';
// -- Email (PHPMailer + Gmail SMTP) ------------------------------
function send_email(string $to, string $subject, string $htmlBody, string $toName = ''): bool
{
    if (!SMTP_ENABLED) {
        error_log('Email skipped (SMTP not configured): ' . $subject . ' → ' . $to);
        return false;
    }

    require_once ROOT_PATH . '/lib/PHPMailer/src/Exception.php';
    require_once ROOT_PATH . '/lib/PHPMailer/src/PHPMailer.php';
    require_once ROOT_PATH . '/lib/PHPMailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Force UTF-8 for both headers (subject, names) and body. PHPMailer's
        // default is ISO-8859-1, which mangles em-dashes, accented characters,
        // emoji, and non-ASCII names into "â€"" / "?" gibberish.
        $mail->CharSet  = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('Email send failed: ' . $e->getMessage());
        return false;
    }
}

// Email HTML lives in core/email_html.php (inline styles are required there).
require_once __DIR__ . '/email_html.php';

// -- Audit log --------------------------------------------------
// IP addresses are intentionally NOT recorded — the school doesn't want
// them in the log table or in the admin UI. We still write a NULL into
// the legacy `ip_address` column so deployments that haven't dropped
// it yet keep working without a schema change.
// Action names for the first argument (AuditAction::LOGIN, ...).
require_once __DIR__ . '/audit_actions.php';

function audit_log(
    string  $action,
    string  $description = '',
    ?string $entityType  = null,
    ?int    $entityId    = null
): void {
    try {
        $userId   = Auth::check() ? Auth::id()   : null;
        $userName = Auth::check() ? (Auth::user()['name'] ?? '') : 'System';
        $userRole = Auth::check() ? (Auth::user()['role'] ?? '') : '';
        db()->prepare(
            'INSERT INTO audit_logs
             (user_id, user_name, user_role, action, description, entity_type, entity_id, ip_address)
             VALUES (?,?,?,?,?,?,?,NULL)'
        )->execute([$userId, $userName, $userRole, $action, $description ?: null,
                    $entityType, $entityId]);
    } catch (Throwable) {
        // Never let audit failures break the main request
    }
}


require_once __DIR__ . '/helpers/ui.php';

// ================================================================
// FEATURE: Custom Courses + Strand Map (admin-managed)
// ================================================================

/**
 * Return the merged list of ALL courses — built-in PLP_COURSES
 * plus any active custom courses added by the admin.
 * Returns a flat array of course name strings.
 */
function get_all_courses(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $courses = PLP_COURSES;
    try {
        $rows = db()->query(
            "SELECT course_name FROM custom_courses WHERE is_active=1 ORDER BY course_name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $cn) {
            if (!in_array($cn, $courses, true)) {
                $courses[] = $cn;
            }
        }
    } catch (\Throwable $e) {}
    return $cache = $courses;
}

/**
 * Return the merged strand map — built-in COURSE_STRAND_MAP
 * plus strand data from admin-added custom_courses.
 * Returns: array<string, string[]>  course_name => [strand_key, ...]
 */
function get_all_strand_map(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $map = COURSE_STRAND_MAP;
    try {
        $rows = db()->query(
            "SELECT course_name, strands FROM custom_courses WHERE is_active=1"
        )->fetchAll();
        foreach ($rows as $row) {
            $strands = json_decode($row['strands'] ?? '[]', true) ?: [];
            $map[$row['course_name']] = $strands;
        }
    } catch (\Throwable $e) {}
    return $cache = $map;
}

/**
 * Return alternative courses a student with $strand qualifies for
 * based on their rank score, excluding the course they applied for.
 *
 * Used in student result view to politely suggest alternatives when
 * the applicant's rank is below the threshold for their chosen course.
 *
 * @param  int    $rankScore    The student's 1–10 rank
 * @param  string $appliedCourse The course they originally applied for
 * @param  string $strand        The student's SHS strand key (e.g. 'STEM')
 * @return array<string>         List of qualifying course names
 */
function strand_qualified_courses(int $rankScore, string $appliedCourse, string $strand): array
{
    $strandMap = get_all_strand_map();
    $result    = [];

    foreach ($strandMap as $course => $strands) {
        if ($course === $appliedCourse) {
            continue;  // skip applied course
        }
        if (!in_array($strand, $strands, true)) {
            continue;  // strand not accepted
        }
        $threshold = get_pass_threshold($course);
        if ($rankScore >= $threshold) {
            $result[] = $course;  // rank qualifies
        }
    }
    return $result;
}

// ================================================================
// AJAX request detection — used by JSON-returning POST endpoints to
// decide between echoing JSON and redirecting after a non-AJAX submit.
// Was previously local to modules/exam/staff_manage.php which broke
// staff_slots.php's per-room access-code AJAX (fatal undefined-function
// error → 500 HTML → JS reported as "Network error").
// ================================================================
if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax']);
    }
}

require_once __DIR__ . '/helpers/exam_access.php';


