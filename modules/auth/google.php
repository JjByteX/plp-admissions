<?php
// ============================================================
// modules/auth/google.php
// Sign in / sign up with Google (server side code flow, no library).
//   GET /auth/google           -> start: redirect to Google
//   GET /auth/google/callback  -> finish: check state, exchange code, decide
// Students only. Staff, dean, proctor, SSO and admin stay on password.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (!GOOGLE_ENABLED) {
    http_response_code(404);
    include VIEWS_PATH . '/404.php';
    exit;
}

if (Auth::check()) { header('Location: ' . Auth::homeUrl()); exit; }

function google_fail(string $msg): never
{
    Session::flash('google_error', $msg);
    redirect('/login');
}

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// -- Start -------------------------------------------------------
if (!str_ends_with(rtrim($path, '/'), '/callback')) {
    $state = bin2hex(random_bytes(16));
    Session::set('google_state', $state);
    Session::set('google_remember', !empty($_GET['remember']));
    // Remember the exact URI sent to Google; the token exchange must repeat it character for character.
    Session::set('google_redirect', GOOGLE_REDIRECT_URI);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'prompt'        => 'select_account',
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
    ]));
    exit;
}

// -- Callback ----------------------------------------------------
$expected = (string) Session::get('google_state', '');
$remember = (bool) Session::get('google_remember', false);
$redirect = (string) Session::get('google_redirect', '') ?: GOOGLE_REDIRECT_URI;
Session::remove('google_state');
Session::remove('google_remember');
Session::remove('google_redirect');

if (!empty($_GET['error'])) {
    google_fail('Google sign in was cancelled.');
}
$gotState = (string) ($_GET['state'] ?? '');
if ($expected === '' || !hash_equals($expected, $gotState)) {
    google_fail('Google sign in could not be verified. Please try again.');
}
$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    google_fail('Google sign in failed. Please try again.');
}

// Exchange the code (server to server).
$raw = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => 'Content-Type: application/x-www-form-urlencoded',
        'timeout'       => 5,
        'ignore_errors' => true,
        'content'       => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => $redirect,
            'grant_type'    => 'authorization_code',
        ]),
    ],
]));
$tok     = $raw ? json_decode($raw, true) : null;
$idToken = is_array($tok) ? (string) ($tok['id_token'] ?? '') : '';
$seg     = explode('.', $idToken);
$claims  = count($seg) === 3 ? json_decode((string) base64_decode(strtr($seg[1], '-_', '+/')), true) : null;

if (!is_array($claims) || !google_claims_ok($claims, GOOGLE_CLIENT_ID)) {
    error_log('[google] token exchange or claims check failed');
    google_fail('Google sign in failed. Make sure your Google email is verified, then try again.');
}

$sub   = (string) $claims['sub'];
$email = (string) $claims['email'];

// -- Decide ------------------------------------------------------
$stmt = db()->prepare('SELECT * FROM users WHERE google_sub = ? LIMIT 1');
$stmt->execute([$sub]);
$user = $stmt->fetch();

if (!$user) {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['role'] !== 'student') {
            google_fail('Staff and admin accounts sign in with a password.');
        }
        if (!empty($user['google_sub'])) {
            google_fail('This account is linked to a different Google account.');
        }
        if (!empty($user['is_active'])) {
            // Google verified this email, so link it and count the email as verified.
            db()->prepare('UPDATE users SET google_sub = ?, email_verified = 1 WHERE id = ?')
               ->execute([$sub, $user['id']]);
        }
    }
}

if ($user) {
    if (empty($user['is_active'])) {
        google_fail('This account cannot sign in. Contact the admissions office.');
    }
    Auth::login($user, $remember);
    audit_log(AuditAction::LOGIN_GOOGLE, "Google login: {$user['email']}", 'user', (int) $user['id']);
    header('Location: ' . Auth::homeUrl());
    exit;
}

// New applicant: finish the profile on the register page.
Session::set('google_pending', [
    'sub'        => $sub,
    'email'      => $email,
    'first_name' => (string) ($claims['given_name']  ?? ''),
    'last_name'  => (string) ($claims['family_name'] ?? ''),
    'remember'   => $remember,
]);
redirect('/register?google=1');
