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
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
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
    if (!$open || !$close) return false;
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

// True iff SSO has posted a full enrollment schedule (date + time +
// venue) in /admin/school-year. Gates "Accept" releases — we don't
// want a student admitted into limbo with no idea when to show up.
function enrollment_schedule_posted(): bool
{
    return school_setting('enrollment_date',  '') !== ''
        && school_setting('enrollment_time',  '') !== ''
        && school_setting('enrollment_venue', '') !== '';
}

// ================================================================
// EXAM SCORE TIER SYSTEM
// ================================================================
// Raw score → 1-10 rank (percentage-based)
// Rank tiers per client interview:
//   High    7–10  → Passed
//   Average 4–6   → Passed
//   Low     1–3   → Rejected
// ================================================================

/**
 * Convert raw score to a 1–10 ranking using percentage.
 * e.g. 70% → rank 7, 40% → rank 4, 25% → rank 3
 */
function score_to_rank(int $score, int $total): int
{
    if ($total <= 0) return 1;
    $pct = ($score / $total) * 100;         // 0–100
    $rank = (int) ceil($pct / 10);          // 0–10
    return max(1, min(10, $rank ?: 1));     // clamp 1–10
}

/**
 * Return display info for a 1–10 rank.
 * Returns: ['tier' => 'high'|'average'|'low', 'label' => ..., 'color' => ..., 'verdict' => 'passed'|'rejected']
 */
function rank_tier_info(int $rank): array
{
    if ($rank >= 7) return ['tier' => 'high',    'label' => 'High',    'color' => '#22c55e', 'bg' => '#dcfce7', 'verdict' => 'passed'];
    if ($rank >= 4) return ['tier' => 'average', 'label' => 'Average', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'verdict' => 'passed'];
    return              ['tier' => 'low',     'label' => 'Low',     'color' => '#ef4444', 'bg' => '#fee2e2', 'verdict' => 'rejected'];
}

/**
 * Get the minimum rank required to pass for a given course.
 * Checks course_passing_scores DB table first; falls back to config.
 */
function get_pass_threshold(string $course): int
{
    static $cache = [];
    if (isset($cache[$course])) return $cache[$course];
    try {
        $stmt = db()->prepare('SELECT pass_from FROM course_passing_scores WHERE course_name=? LIMIT 1');
        $stmt->execute([$course]);
        $row = $stmt->fetch();
        if ($row) return $cache[$course] = (int) $row['pass_from'];
    } catch (\Throwable $e) {}
    $config = COURSE_PASSING_SCORES[$course] ?? null;
    return $cache[$course] = $config ? (int)$config['pass_from'] : 4;
}

/**
 * Determine if a score passes for a course.
 */
function exam_passed(int $score, int $total, string $course): bool
{
    return score_to_rank($score, $total) >= get_pass_threshold($course);
}

/**
 * Return list of available courses the applicant's score qualifies for,
 * excluding their applied course and any that are full.
 *
 * @return array<string>  list of course names
 */
function suggest_alt_courses(int $score, int $total, string $appliedCourse): array
{
    if ($total <= 0) return [];
    $rank = score_to_rank($score, $total);

    // Get all per-course thresholds from DB
    $thresholds = [];
    try {
        $rows = db()->query('SELECT course_name, pass_from FROM course_passing_scores')->fetchAll();
        foreach ($rows as $r) $thresholds[$r['course_name']] = (int)$r['pass_from'];
    } catch (\Throwable $e) {}
    // Fall back to config for any missing
    foreach (COURSE_PASSING_SCORES as $cn => $cfg) {
        if (!isset($thresholds[$cn])) $thresholds[$cn] = (int)$cfg['pass_from'];
    }

    // Find full courses
    $fullCourses = [];
    try {
        $sy = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
        $stmt = db()->prepare(
            'SELECT cc.course_name
             FROM course_caps cc
             LEFT JOIN applicants a      ON a.course_applied = cc.course_name AND a.school_year = cc.school_year
             LEFT JOIN admission_results r ON r.applicant_id = a.id AND r.result = \'accepted\'
             WHERE cc.school_year = ? AND cc.max_slots IS NOT NULL
             GROUP BY cc.course_name, cc.max_slots
             HAVING COUNT(r.id) >= cc.max_slots'
        );
        $stmt->execute([$sy]);
        $fullCourses = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}

    $suggestions = [];
    foreach (PLP_COURSES as $course) {
        if ($course === $appliedCourse) continue;
        if (in_array($course, $fullCourses, true)) continue;
        $pf = $thresholds[$course] ?? 4;
        if ($rank >= $pf) $suggestions[] = $course;
    }
    return $suggestions;
}

// -- Document helpers -------------------------------------------
// Decode applicants.doc_flags (jsonb string from PDO, array, or null) into
// a clean ['married'=>bool,'guardian'=>bool,'grade12'=>bool,'shs_grad'=>bool].
function doc_flags_decode(array|string|null $flags): array
{
    if (is_string($flags)) {
        $flags = json_decode($flags, true);
    }
    if (!is_array($flags)) $flags = [];
    $out = [];
    foreach (array_unique(array_values(DOCS_CONDITIONAL)) as $key) {
        $out[$key] = !empty($flags[$key]);
    }
    return $out;
}

// Slots that apply to an applicant: type slots minus conditional slots
// whose flag is not ticked. Pass applicants.doc_flags as $flags.
function docs_for_type(string $applicantType, array|string|null $flags = null): array
{
    $docs = DOCS_CORE;
    if ($applicantType === TYPE_FRESHMAN) {
        $docs = array_merge($docs, DOCS_FRESHMAN);
    } elseif ($applicantType === TYPE_TRANSFEREE) {
        $docs = array_merge($docs, DOCS_TRANSFEREE);
    } elseif ($applicantType === TYPE_FOREIGN) {
        $docs = array_merge($docs, DOCS_FOREIGN);
    }
    $on = doc_flags_decode($flags);
    foreach (DOCS_CONDITIONAL as $slug => $flag) {
        if (empty($on[$flag])) unset($docs[$slug]);
    }
    return $docs;
}

// Normalise ticked boxes from a form (e.g. $_POST['flags']) for an applicant type.
// Grade 12 / SHS graduate only apply to freshmen.
function doc_flags_from_input(string $applicantType, mixed $input): array
{
    $flags = doc_flags_decode(is_array($input) ? $input : []);
    if ($applicantType !== TYPE_FRESHMAN) {
        $flags['grade12']  = false;
        $flags['shs_grad'] = false;
    }
    return $flags;
}

// Make the documents rows match the slots that apply: add missing pending
// rows, remove rows for slots that no longer apply. Approved rows are kept.
// ponytail: an uploaded (not approved) file on a removed slot is dropped from
// the row but stays in storage; upgrade path is a storage cleanup job.
function sync_doc_rows(PDO $db, int $applicantId, array $required): void
{
    $slugs = array_keys($required);
    $stmt = $db->prepare('SELECT doc_type FROM documents WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $have = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = $db->prepare('INSERT INTO documents (applicant_id, doc_type, status) VALUES (?, ?, \'pending\')');
    foreach (array_diff($slugs, $have) as $slug) {
        $ins->execute([$applicantId, $slug]);
    }
    if ($slugs) {
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $db->prepare(
            "DELETE FROM documents
              WHERE applicant_id = ? AND status <> 'approved' AND doc_type NOT IN ($in)"
        )->execute(array_merge([$applicantId], $slugs));
    }
}

// True when every slot that applies to the applicant has an approved file.
// Used by approve / approve-all / advance so extra or old rows never block.
function required_docs_all_approved(PDO $db, int $applicantId): bool
{
    $stmt = $db->prepare('SELECT applicant_type, doc_flags FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $a = $stmt->fetch();
    if (!$a) return false;
    $slugs = array_keys(docs_for_type($a['applicant_type'], $a['doc_flags'] ?? null));
    if (!$slugs) return false;
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT doc_type) FROM documents
          WHERE applicant_id = ? AND status = 'approved' AND doc_type IN ($in)"
    );
    $stmt->execute(array_merge([$applicantId], $slugs));
    return (int)$stmt->fetchColumn() === count($slugs);
}

// Slots that would be dropped by $newFlags but already hold an approved file.
// Returns their labels; empty array means the flag change is allowed.
function doc_flags_blocked_slots(PDO $db, int $applicantId, array $newFlags): array
{
    $stmt = $db->prepare('SELECT applicant_type, doc_flags FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $a = $stmt->fetch();
    if (!$a) return [];
    $dropped = array_diff_key(
        docs_for_type($a['applicant_type'], $a['doc_flags'] ?? null),
        docs_for_type($a['applicant_type'], $newFlags)
    );
    if (!$dropped) return [];
    $in = implode(',', array_fill(0, count($dropped), '?'));
    $stmt = $db->prepare(
        "SELECT doc_type FROM documents
          WHERE applicant_id = ? AND status = 'approved' AND doc_type IN ($in)"
    );
    $stmt->execute(array_merge([$applicantId], array_keys($dropped)));
    $blocked = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) $blocked[] = $dropped[$slug];
    return $blocked;
}

// -- Formatting -------------------------------------------------
function format_date(string $date, string $format = 'F j, Y'): string
{
    return date($format, strtotime($date));
}

function format_time(string $time): string
{
    return date('g:i A', strtotime($time));
}

/**
 * Format a person's full name as "LAST_NAME SUFFIX, FIRST_NAME MIDDLE_NAME".
 *
 * Accepts a row that contains any combination of `first_name`, `middle_name`,
 * `last_name`, `suffix`, plus optional fallbacks `name` / `student_name`.
 * If the split-name fields are empty, falls back to the legacy combined name.
 */
function format_full_name(array $row, string $fallback = '—'): string
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

    if ($left === '')  return $right !== '' ? $right : $fallback;
    if ($right === '') return $left;

    return $left . ', ' . $right;
}

// -- Applicant progress -----------------------------------------
// Returns which step is 'current' for a given applicant row
function current_step(array $applicant, ?array $examResult, ?array $interviewSlot, ?array $admissionResult): string
{
    $status = $applicant['overall_status'] ?? '';

    // Released with a decision recorded → show result step
    if ($admissionResult)                          return 'result';

    // Post-interview, awaiting staff decision
    if ($status === 'result')                      return 'result';

    // Released without a decision yet (edge case) → result step
    if ($status === 'released')                    return 'result';

    // Actively in interview stage
    if ($status === 'interview')                   return 'interview';
    if ($interviewSlot && $interviewSlot['status'] !== 'open') return 'interview';

    // Passed exam — waiting for interview assignment
    if ($examResult && !empty($examResult['passed'])) return 'interview';

    // Has exam result but failed — stays on exam step
    if ($examResult)                               return 'exam';

    if ($status === 'exam')                        return 'exam';
    if ($status === 'documents')                   return 'documents';
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
        error_log('Supabase upload failed: HTTP ' . $status . ' ' . $curlErr . ' ' . (is_string($response) ? $response : ''));
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
        return $filePath; // Already a full CDN URL — return as-is.
    }
    return url($filePath); // Local path — prepend BASE_URL.
}

// -- hCaptcha verification --------------------------------------
function hcaptcha_verify(): bool
{
    if (!HCAPTCHA_ENABLED) {
        return true; // Skip if keys are not configured
    }

    $token = $_POST['h-captcha-response'] ?? '';
    if (!$token) {
        return false;
    }

    $response = @file_get_contents('https://api.hcaptcha.com/siteverify', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'secret'   => HCAPTCHA_SECRET_KEY,
                'response' => $token,
            ]),
        ],
    ]));

    if (!$response) {
        return false;
    }

    $data = json_decode($response, true);
    return !empty($data['success']);
}

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

/**
 * Build a styled HTML email body using school branding.
 */
function email_template(string $title, string $body): string
{
    $schoolName  = school_setting('school_name', 'PLP Admissions');
    $accentColor = school_setting('accent_color', '#2d6a4f');

    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 0">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
    <tr><td style="background:' . e($accentColor) . ';padding:24px 32px">
        <h1 style="margin:0;color:#ffffff;font-size:20px">' . e($schoolName) . '</h1>
    </td></tr>
    <tr><td style="padding:32px">
        <h2 style="margin:0 0 16px;color:#1a1a1a;font-size:18px">' . $title . '</h2>
        <div style="color:#374151;font-size:14px;line-height:1.6">' . $body . '</div>
    </td></tr>
    <tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:center">
        <p style="margin:0;font-size:12px;color:#9ca3af">This is an automated email from ' . e($schoolName) . '. Please do not reply.</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
}

// -- Audit log --------------------------------------------------
// IP addresses are intentionally NOT recorded — the school doesn't want
// them in the log table or in the admin UI. We still write a NULL into
// the legacy `ip_address` column so deployments that haven't dropped
// it yet keep working without a schema change.
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


// -- SVG icon helper -------------------------------------------
// Loads a Fluent icon from views/partials/icons/ and sets size.
// $extra = any additional SVG attributes e.g. 'id="foo" class="bar"'
function icon(string $name, int $size = 16, string $style = '', string $extra = ''): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $path = VIEWS_PATH . '/partials/icons/' . $name . '.svg';
        $cache[$name] = file_exists($path) ? file_get_contents($path) : '';
    }
    if (!$cache[$name]) return '';
    $svg = $cache[$name];
    $svg = preg_replace_callback('/<svg\b([^>]*)>/', function ($m) use ($size, $style, $extra) {
        $attrs = $m[1];
        $attrs = preg_replace('/\s*width="[^"]*"/',  '', $attrs);
        $attrs = preg_replace('/\s*height="[^"]*"/', '', $attrs);
        $out  = '<svg';
        $out .= ' width="' . $size . '" height="' . $size . '"';
        if ($style) $out .= ' style="' . $style . '"';
        if ($extra) $out .= ' ' . $extra;
        $out .= $attrs . '>';
        return $out;
    }, $svg, 1);
    return $svg;
}

// -- Pagination -------------------------------------------------
function paginate(PDO $pdo, string $countSql, string $dataSql, array $params, int $page, int $perPage = 20): array
{
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = (int) ceil($total / $perPage);
    $page  = max(1, min($page, $pages ?: 1));

    $stmt = $pdo->prepare($dataSql . " LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'data'         => $stmt->fetchAll(),
        'total'        => $total,
        'current_page' => $page,
        'last_page'    => $pages,
        'per_page'     => $perPage,
    ];
}

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
    if ($cache !== null) return $cache;
    $courses = PLP_COURSES;
    try {
        $rows = db()->query(
            "SELECT course_name FROM custom_courses WHERE is_active=1 ORDER BY course_name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $cn) {
            if (!in_array($cn, $courses, true)) $courses[] = $cn;
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
    if ($cache !== null) return $cache;
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
        if ($course === $appliedCourse)               continue; // skip applied course
        if (!in_array($strand, $strands, true))       continue; // strand not accepted
        $threshold = get_pass_threshold($course);
        if ($rankScore >= $threshold) $result[] = $course;      // rank qualifies
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

// ================================================================
// FEATURE: Exam Password Expiry helpers
// ================================================================

define('EXAM_PASSWORD_EXPIRY_SECONDS', 300); // 5 minutes

/**
 * Generate a random, readable 6-character exam access code.
 * Uses uppercase letters + digits, avoiding ambiguous characters (0/O, 1/I/L).
 */
function generate_exam_password(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $pwd   = '';
    for ($i = 0; $i < 6; $i++) {
        $pwd .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pwd;
}

/**
 * Check whether the access password on the given row is currently valid
 * (i.e. was issued within the last EXAM_PASSWORD_EXPIRY_SECONDS).
 *
 * As of Chunk 7 the password lives on `exam_slot_schedule` (per room),
 * not on `exams`. This helper accepts any row that has both
 * `access_password` and `password_issued_at` columns — historically
 * called with the exam row, now called with the slot row. Function name
 * kept as `exam_password_*` to avoid churn in call sites that only need
 * the value semantics.
 *
 * @param  array  $row  Row containing `access_password` + `password_issued_at`
 * @return bool
 */
function exam_password_is_valid(array $row): bool
{
    if (empty($row['access_password']))    return false;
    if (empty($row['password_issued_at'])) return false;
    // Compute age in the database so PHP/DB timezone differences don't skew the result.
    $stmt = db()->prepare(
        'SELECT TRUNC(EXTRACT(EPOCH FROM (NOW() - CAST(? AS timestamp))))'
    );
    $stmt->execute([$row['password_issued_at']]);
    $age = (int) $stmt->fetchColumn();
    return $age >= 0 && $age <= EXAM_PASSWORD_EXPIRY_SECONDS;
}

/**
 * Return the number of seconds remaining before the access password expires.
 * Returns 0 if already expired or never issued.
 *
 * @param  array  $row  Row containing `access_password` + `password_issued_at`
 */
function exam_password_seconds_remaining(array $row): int
{
    if (empty($row['password_issued_at'])) return 0;
    // Compute in the database so PHP/DB timezone differences don't skew the result.
    $stmt = db()->prepare(
        'SELECT GREATEST(0, ' . (int) EXAM_PASSWORD_EXPIRY_SECONDS
        . ' - TRUNC(EXTRACT(EPOCH FROM (NOW() - CAST(? AS timestamp)))))'
    );
    $stmt->execute([$row['password_issued_at']]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return true if today's date matches the slot's exam date.
 * Schedule now lives on the slot, not the exam (per Chunk 7).
 *
 * @param  array  $slot  Row from `exam_slot_schedule` containing `exam_date`
 */
function is_slot_today(array $slot): bool
{
    if (empty($slot['exam_date'])) return false;
    return date('Y-m-d') === date('Y-m-d', strtotime($slot['exam_date']));
}

/**
 * Return the wall-clock open / close DateTimes for a slot.
 *
 *   opens  = exam_date + slot_time
 *   closes = exam_date + end_time   (per Chunk 7.1 — each slot owns its window)
 *
 * If the slot has no `end_time` (legacy data), falls back to a 90-minute
 * window so the timer never returns negative numbers.
 *
 * Returns ['opens' => DateTime, 'closes' => DateTime].
 *
 * @param  array  $slot  Row from `exam_slot_schedule` (slot_time + end_time)
 */
function slot_window(array $slot): array
{
    $opens = new DateTime($slot['exam_date'] . ' ' . $slot['slot_time']);
    if (!empty($slot['end_time'])) {
        $closes = new DateTime($slot['exam_date'] . ' ' . $slot['end_time']);
        // If end_time wraps past midnight (e.g. 11pm → 1am), bump a day.
        if ($closes <= $opens) $closes->modify('+1 day');
    } else {
        $closes = (clone $opens)->modify('+90 minutes');
    }
    return ['opens' => $opens, 'closes' => $closes];
}

/**
 * Convenience: return the duration of a slot in minutes (closes − opens).
 */
function slot_duration_minutes(array $slot): int
{
    $w = slot_window($slot);
    return (int) round(($w['closes']->getTimestamp() - $w['opens']->getTimestamp()) / 60);
}

/**
 * How many minutes after `opens` an applicant can still enter the password
 * before being locked out for the day. Read from school setting
 * `exam_late_cutoff_minutes`, default 15.
 */
function exam_late_cutoff_minutes(): int
{
    $v = (int) school_setting('exam_late_cutoff_minutes', '15');
    return max(0, $v);
}

/**
 * Sortable table header link, shared by any staff listing page with
 * click-to-sort columns (results, documents, etc). $extraParams carries
 * whatever filters that page needs to preserve in the URL (search term,
 * status filter, page number, ...) alongside the sort change itself.
 */
function sortable_th(string $col, string $label, string $currentCol, string $currentDir, array $extraParams = [], string $thStyle = ''): string
{
    $isActive = ($currentCol === $col);
    $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $params   = array_merge($extraParams, ['sort_col' => $col, 'sort_dir' => $isActive ? $nextDir : 'asc']);
    $url      = '?' . http_build_query($params);

    $sortIcon  = icon('ic_fluent_chevron_up_down_24_filled', 13);
    $sortColor = $isActive ? 'var(--accent)' : 'var(--text-tertiary)';

    return '<th' . ($thStyle !== '' ? ' style="' . htmlspecialchars($thStyle) . '"' : '') . '><a href="' . $url . '" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:inherit;white-space:nowrap;">'
         . htmlspecialchars($label)
         . '<span style="color:' . $sortColor . ';display:flex;align-items:center;margin-left:2px;">' . $sortIcon . '</span>'
         . '</a></th>';
}

// ================================================================
// Auto-paginated tables (.auto-table-*): shared helpers
// ================================================================

/**
 * Rows per page. AutoPageSize (app.js) reloads once with ?per_page=<rows that fit>.
 * Clamped to [3, 200] so a hand-edited URL can't request an unbounded row count.
 */
function auto_per_page(int $fallback = 25): int
{
    $n = isset($_GET['per_page']) ? (int) $_GET['per_page'] : $fallback;
    return max(3, min(200, $n));
}

/**
 * Pagination footer rendered INSIDE the .auto-table-card.
 * $result = paginate()'s return value. $urlFor = fn(int $page): string.
 */
function auto_table_footer(array $result, callable $urlFor, string $noun = 'records'): string
{
    $total = (int) $result['total'];
    $page  = (int) $result['current_page'];
    $pages = max(1, (int) $result['last_page']);
    $per   = (int) $result['per_page'];
    $from  = $total === 0 ? 0 : ($page - 1) * $per + 1;
    $to    = min($page * $per, $total);

    $btn = function (bool $enabled, int $target, string $iconName, string $label) use ($urlFor): string {
        $ic = icon($iconName, 16);
        return $enabled
            ? '<a href="' . htmlspecialchars($urlFor($target)) . '" class="auto-table-page-btn" aria-label="' . $label . '">' . $ic . '</a>'
            : '<span class="auto-table-page-btn" aria-disabled="true" aria-label="' . $label . '">' . $ic . '</span>';
    };

    $strong = 'style="color:var(--text-primary);font-weight:var(--weight-semibold)"';
    return '<div class="auto-table-pagination">'
         . '<p class="auto-table-pagination-info" style="margin:0">Showing <strong>' . number_format($from) . '–' . number_format($to)
         . '</strong> of <strong>' . number_format($total) . '</strong> ' . htmlspecialchars($noun) . '</p>'
         . '<div class="auto-table-pagination-controls">'
         . $btn($page > 1, $page - 1, 'ic_fluent_chevron_left_24_regular', 'Previous page')
         . '<span style="padding:0 var(--space-1);font-size:var(--text-sm);color:var(--text-tertiary);white-space:nowrap">Page <strong ' . $strong . '>' . $page . '</strong> of <strong ' . $strong . '>' . $pages . '</strong></span>'
         . $btn($page < $pages, $page + 1, 'ic_fluent_chevron_right_24_regular', 'Next page')
         . '</div></div>';
}