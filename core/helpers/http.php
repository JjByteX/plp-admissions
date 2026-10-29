<?php
// ============================================================
// core/helpers/http.php
// Request input, JSON and CSV responses, SQL IN() helpers.
// Loaded by core/helpers.php; plain functions, unchanged.
// ============================================================

// -- Request input + flash-redirect ------------------------------
function post_str(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_int(string $key, int $default = 0): int
{
    return (int)($_POST[$key] ?? $default);
}

function get_str(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function get_int(string $key, int $default = 0): int
{
    return (int)($_GET[$key] ?? $default);
}

function flash_redirect(string $type, string $message, string $to): never
{
    Session::flash($type, $message);
    redirect($to);
}

function fail(string $message, string $to): never
{
    flash_redirect('error', $message, $to);
}

function ok(string $message, string $to): never
{
    flash_redirect('success', $message, $to);
}

// POST handler preamble: returns null on non-POST; on POST runs csrf_check()
// and returns the submitted action (or $default when none was sent).
function require_post_action(string $default = ''): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return null;
    }
    csrf_check();
    return (string)($_POST['action'] ?? $default);
}

// -- JSON responses (API endpoints) ------------------------------
function json_response(array $body, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): never
{
    json_response(['ok' => false, 'error' => $message], $code);
}

// Sends 405 (JSON) unless the request uses the given method.
function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        json_error(strtoupper($method) . ' only', 405);
    }
}

// -- CSV download ------------------------------------------------
// Streams a CSV (UTF-8 BOM so Excel reads accents) and returns; the caller
// decides what happens next (audit, exit). $rows may be a generator.
function csv_download(string $filename, array $header, iterable $rows): void
{
    $safe = str_replace(['"', "\r", "\n"], '', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
}

// -- SQL IN() helpers --------------------------------------------
// Positional placeholders for an IN() list. Returns [ '?,?,?', [values] ].
// An empty list gives 'NULL' (IN (NULL) matches nothing), so the query stays valid.
function sql_in(array $values): array
{
    $values = array_values($values);
    if (!$values) {
        return ['NULL', []];
    }
    return [implode(',', array_fill(0, count($values), '?')), $values];
}

// "1,2,x,-3" -> [1, 2] (positive ints only).
function parse_id_list(string $csv): array
{
    return array_values(array_filter(array_map('intval', explode(',', $csv)), fn($v) => $v > 0));
}
