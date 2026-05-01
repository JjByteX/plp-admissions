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
    // Admin override bypasses the date window (for testing/development)
    if (school_setting('admissions_override') === '1') return true;

    $open  = admissions_open_date();
    $close = admissions_close_date();
    if (!$open || !$close) return false;
    $now = new DateTime();
    return $now >= $open && $now <= $close;
}

// -- Flash messages ----------------------------------------------
function flash(string $key, mixed $value): void
{
    Session::flash($key, $value);
}

function flash_get(string $key, mixed $default = null): mixed
{
    return Session::getFlash($key, $default);
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
             LEFT JOIN admission_results r ON r.applicant_id = a.id AND r.result = "accepted"
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
function docs_for_type(string $applicantType): array
{
    $docs = DOCS_CORE;
    if ($applicantType === TYPE_FRESHMAN) {
        $docs = array_merge($docs, DOCS_FRESHMAN);
    } elseif ($applicantType === TYPE_TRANSFEREE) {
        $docs = array_merge($docs, DOCS_TRANSFEREE);
    } elseif ($applicantType === TYPE_FOREIGN) {
        $docs = array_merge($docs, DOCS_FOREIGN);
    }
    return $docs;
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

// -- Uploadcare file upload -------------------------------------
function uploadcare_upload(string $tmpPath, string $filename, string $mimeType): ?string
{
    if (!UPLOADCARE_ENABLED) {
        return null;
    }

    $boundary = '----UploadcareBoundary' . uniqid();
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"UPLOADCARE_PUB_KEY\"\r\n\r\n" . UPLOADCARE_PUB_KEY . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"UPLOADCARE_STORE\"\r\n\r\n1\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mimeType}\r\n\r\n";
    $body .= file_get_contents($tmpPath) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $response = @file_get_contents('https://upload.uploadcare.com/base/', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body),
            'content' => $body,
            'timeout' => 30,
        ],
    ]));

    if (!$response) return null;
    $data = json_decode($response, true);
    if (empty($data['file'])) return null;

    $cdnBase = getenv('UPLOADCARE_CDN_BASE') ?: 'ucarecdn.com';
    return 'https://' . $cdnBase . '/' . $data['file'] . '/';
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

// -- Audit log --------------------------------------------------
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
        $ip       = $_SERVER['HTTP_X_FORWARDED_FOR']
                    ?? $_SERVER['REMOTE_ADDR']
                    ?? null;
        // Take only the first IP if comma-separated
        if ($ip && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        db()->prepare(
            'INSERT INTO audit_logs
             (user_id, user_name, user_role, action, description, entity_type, entity_id, ip_address)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$userId, $userName, $userRole, $action, $description ?: null,
                    $entityType, $entityId, $ip]);
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