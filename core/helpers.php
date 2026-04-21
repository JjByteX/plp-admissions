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
    }
    return $cache[$key] ?? $default;
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
    if ($admissionResult)                          return 'result';
    if ($interviewSlot && $interviewSlot['status'] !== 'open') return 'interview';
    if ($examResult)                               return 'interview';
    if ($applicant['overall_status'] === 'exam')   return 'exam';
    if ($applicant['overall_status'] === 'documents') return 'documents';
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