<?php
// ============================================================
// modules/auth/verify_email.php
// Email verification handler
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

ensure_email_verification_columns();

$token = trim($_GET['token'] ?? '');

if (!$token) {
    Session::flash('error', 'Invalid verification link.');
    redirect('/login');
}

$stmt = db()->prepare('SELECT id, name, email FROM users WHERE email_verify_token = ? LIMIT 1');
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    Session::flash('error', 'Invalid or expired verification link.');
    redirect('/login');
}

db()->prepare('UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = ?')
    ->execute([$user['id']]);

// Send welcome email now that email is verified
send_registration_email($user['email'], $user['name']);

audit_log('email_verified', "User #{$user['id']} ({$user['email']}) verified their email", 'user', $user['id']);

Session::flash('success', 'Email verified successfully! You can now log in.');
redirect('/login');
