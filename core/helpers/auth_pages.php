<?php
// ============================================================
// core/helpers/auth_pages.php
// Sign-in page helpers: Cloudflare Turnstile gate, auth CSP, Google sign-in claims.
// Loaded by core/helpers.php; plain functions, no side effects
// beyond defining them.
// ============================================================

// -- Captcha (Cloudflare Turnstile) -------------------------------
// The check runs once, on /security-check, before the sign in, register and
// forgot-password pages open. A pass is kept in the session, so the forms
// themselves carry no widget.
const CAPTCHA_PASS_SECS = 43200; // 12 h (the session inactivity timeout can end it sooner)

function captcha_passed(): bool
{
    if (!CAPTCHA_ENABLED) {
        return true; // Skip if keys are not configured
    }
    return (int) Session::get('captcha_ok_until', 0) > time();
}

function captcha_mark_passed(): void
{
    Session::set('captcha_ok_until', time() + CAPTCHA_PASS_SECS);
}

/** Call at the top of a guest-only page. Sends the visitor to /security-check until they pass, then back to $path. */
function captcha_gate(string $path): void
{
    if (captcha_passed()) {
        return;
    }
    $qs = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? (string) ($_SERVER['QUERY_STRING'] ?? '') : '';
    Session::set('captcha_next', $path . ($qs !== '' ? '?' . $qs : ''));
    redirect('/security-check');
}

function captcha_verify(?string $token = null): bool
{
    if (!CAPTCHA_ENABLED) {
        return true; // Skip if keys are not configured
    }

    $token ??= $_POST['cf-turnstile-response'] ?? '';
    if (!is_string($token) || $token === '') {
        return false;
    }

    $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $response = @file_get_contents($verifyUrl, false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'timeout' => 5,
            'content' => http_build_query([
                'secret'   => TURNSTILE_SECRET_KEY,
                'response' => $token,
            ]),
        ],
    ]));

    // ponytail: fails closed. A Cloudflare outage blocks the guest pages. Upgrade: fail open with a log.
    if (!$response) {
        return false;
    }

    $data = json_decode($response, true);
    return !empty($data['success']);
}

// -- Content-Security-Policy for the auth pages ------------------
function auth_csp(): string
{
    $cf = 'https://challenges.cloudflare.com';
    return "default-src 'self'; script-src 'self' 'unsafe-inline' $cf; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
        . "img-src 'self' data: blob: https:; frame-src $cf; connect-src 'self' $cf;";
}

// -- Google sign in ----------------------------------------------
// Pure check of the id token claims (no network). The token came straight from
// Google over TLS, so the signature is not verified (Google's own guidance).
function google_claims_ok(array $c, string $clientId, ?int $now = null): bool
{
    $now ??= time();
    if (!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
        return false;
    }
    $aud = $c['aud'] ?? '';
    if (!(is_array($aud) ? in_array($clientId, $aud, true) : ($clientId !== '' && $aud === $clientId))) {
        return false;
    }
    if ((int) ($c['exp'] ?? 0) <= $now) {
        return false;
    }
    if (!in_array($c['email_verified'] ?? false, [true, 'true'], true)) {
        return false;
    }
    foreach (['sub', 'email'] as $k) {
        if (!is_string($c[$k] ?? null) || $c[$k] === '') {
            return false;
        }
    }
    return true;
}

function google_logo_svg(): string
{
    return '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">'
        . '<path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 '
        . '14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/>'
        . '<path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8'
        . 'c4.4-4.1 7.1-10.1 7.1-17.5z"/>'
        . '<path fill="#FBBC05" d="M10.5 28.7c-.5-1.4-.8-3-.8-4.7s.3-3.2.8-4.7l-7.9-6.1C.9 16.4 0 20.100 0 24'
        . 's.9 7.600 2.600 10.800l7.900-6.100z"/>'
        . '<path fill="#34A853" d="M24 48c6.500 0 11.900-2.100 15.900-5.800l-7.500-5.800'
        . 'c-2.100 1.400-4.800 2.300-8.400 2.300-6.300 0-11.600-4.100-13.500-9.800l-7.900 6.100C6.500 42.600 14.600 48 24 48z"/>'
        . '</svg>';
}
