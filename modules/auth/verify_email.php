<?php
// ============================================================
// modules/auth/verify_email.php
// Magic-link path of the email verification flow.
//
// On success: marks the user verified, auto-logs them in, redirects
// straight to their home page (Auth::homeUrl()). On failure / expiry,
// kicks them to /verify-pending so they can request a fresh code.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

ensure_email_verification_columns();

$token = trim($_GET['token'] ?? '');

// No token at all → send them to login.
if ($token === '') {
    Session::flash('error', 'Invalid verification link.');
    redirect('/login');
}

$user = find_user_by_verify_token($token);

// Token unknown or expired — kick to /verify-pending so the user can request a new code.
if (!$user) {
    Session::flash('error', 'Link invalid or expired. Request a new one below.');
    redirect('/verify-pending');
}

// Already verified (rare race) — just log them in.
if (!empty($user['email_verified'])) {
    Auth::login($user);
    audit_log('email_verified_idempotent', "User #{$user['id']} hit verify link but was already verified", 'user', (int) $user['id']);
    header('Location: ' . Auth::homeUrl()); exit;
}

// Mark verified, send welcome email, audit, auto-login.
mark_user_email_verified((int) $user['id']);
try {
    send_registration_email($user['email'], $user['name']);
} catch (\Throwable $e) {
    error_log('Welcome email failed after verify: ' . $e->getMessage());
}
audit_log('email_verified', "User #{$user['id']} ({$user['email']}) verified via link", 'user', (int) $user['id']);

Auth::login($user);

Session::flash('success', 'Email verified. Welcome!');
header('Location: ' . Auth::homeUrl()); exit;
