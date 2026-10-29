<?php
// ============================================================
// core/automation/auth_flows.php
// Account flows: email verification, password reset codes, login rate limiting.
// Loaded by core/automation.php; plain functions, unchanged.
// ============================================================

// ----------------------------------------------------------------
// EMAIL VERIFICATION
// ----------------------------------------------------------------

/**
 * Generate a fresh token + 6-digit code for the email-verification flow,
 * persist them on the user row, and return both so the caller can email them.
 */
function generate_verify_credentials(int $userId): array
{
    $token = bin2hex(random_bytes(16));
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    db()->prepare(
        'UPDATE users
            SET email_verify_token            = ?,
                email_verify_code             = ?,
                email_verify_code_expires_at  = NOW() + (CAST(? AS integer) * INTERVAL \'1 second\'),
                email_verify_attempts         = 0,
                email_verify_last_sent_at     = NOW()
          WHERE id = ?'
    )->execute([$token, $code, (int) VERIFY_CODE_TTL_SECS, $userId]);

    return ['token' => $token, 'code' => $code];
}

/**
 * Returns ['ok' => bool, 'retry_after' => int] — caller is allowed to send a
 * fresh code only when ok=true. retry_after is the seconds remaining until the
 * cooldown window closes.
 */
function can_resend_verification(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT GREATEST(0, CAST(? AS integer) - TRUNC(EXTRACT(EPOCH FROM (NOW() - email_verify_last_sent_at))))
           FROM users
          WHERE id = ?
          LIMIT 1'
    );
    $stmt->execute([(int) VERIFY_RESEND_COOLDOWN_SECS, $userId]);
    $remaining = (int) ($stmt->fetchColumn() ?: 0);
    return ['ok' => $remaining <= 0, 'retry_after' => $remaining];
}

function find_user_by_verify_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email_verify_token = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mark_user_email_verified(int $userId): void
{
    db()->prepare(
        'UPDATE users
            SET email_verified                = 1,
                email_verify_token            = NULL,
                email_verify_code             = NULL,
                email_verify_code_expires_at  = NULL,
                email_verify_attempts         = 0
          WHERE id = ?'
    )->execute([$userId]);
}

/**
 * Validate a 6-digit code submitted via /verify-pending. Returns:
 *   ['ok' => true,  'user' => array, 'already' => bool]
 *   ['ok' => false, 'error' => string, 'attempts_remaining' => int]
 */
function verify_user_by_code(string $email, string $code): array
{
    $email = strtolower(trim($email));
    $code  = preg_replace('/\D/', '', $code);
    if ($email === '' || strlen($code) !== 6) {
        return ['ok' => false, 'error' => 'Invalid or expired code.'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['ok' => false, 'error' => 'Invalid or expired code.'];
    }

    if (!empty($user['email_verified'])) {
        return ['ok' => true, 'user' => $user, 'already' => true];
    }

    if (empty($user['email_verify_code']) || empty($user['email_verify_code_expires_at'])) {
        return ['ok' => false, 'error' => 'Request a new code, then try again.'];
    }

    if (strtotime($user['email_verify_code_expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This code has expired. Request a new one.'];
    }

    $tries = (int) ($user['email_verify_attempts'] ?? 0);
    if ($tries >= (int) VERIFY_MAX_CODE_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new code.'];
    }

    if (!hash_equals((string) $user['email_verify_code'], $code)) {
        db()->prepare('UPDATE users SET email_verify_attempts = email_verify_attempts + 1 WHERE id = ?')
            ->execute([$user['id']]);
        return [
            'ok'                 => false,
            'error'              => 'Incorrect code.',
            'attempts_remaining' => max(0, (int) VERIFY_MAX_CODE_ATTEMPTS - ($tries + 1)),
        ];
    }

    mark_user_email_verified((int) $user['id']);
    $user['email_verified'] = 1;
    return ['ok' => true, 'user' => $user, 'already' => false];
}

function send_verification_email(string $email, string $name, string $token, ?string $code = null): void
{
    $verifyUrl = rtrim(BASE_URL, '/') . '/verify-email?token=' . $token;

    $body  = '<p>Hello, <strong>' . e($name) . '</strong>!</p>'
        . '<p>Please verify your email address to activate your account.</p>';

    if ($code !== null && $code !== '') {
        $body .= email_muted('Verification code:', 'margin:16px 0 6px 0;')
            . email_code((string) $code);
    }

    $body .= email_button($verifyUrl, 'Verify Email', null, true)
        . email_muted('If the button doesn\'t work, copy this link:<br>'
            . email_link($verifyUrl), 'margin-top:16px;');

    send_email(
        $email,
        'Verify Your Email - ' . school_setting('school_name', 'PLP Admissions'),
        email_template('Verify Your Email', $body),
        $name
    );
}

// ----------------------------------------------------------------
// PASSWORD RESET CODE
// ----------------------------------------------------------------

/**
 * Make a fresh 6-digit reset code. Older codes for the user stop working.
 * Only the sha256 hash is stored (column `token`). Returns the plain code
 * so the caller can email it. Never show or log it.
 * ponytail: sha256 of a 6-digit code is brute-forceable offline if the DB
 * leaks. Expiry + 5 tries cover online guessing. Upgrade: HMAC with a secret.
 */
function create_reset_code(int $userId): string
{
    db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$userId]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    db()->prepare(
        'INSERT INTO password_resets (user_id, token, expires_at, attempts)
         VALUES (?, ?, NOW() + (CAST(? AS integer) * INTERVAL \'1 second\'), 0)'
    )->execute([$userId, hash('sha256', $code), (int) RESET_CODE_TTL_SECS]);

    return $code;
}

/** Seconds left before a new reset code may be sent (0 = allowed). */
function reset_cooldown(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT GREATEST(0, CAST(? AS integer) - TRUNC(EXTRACT(EPOCH FROM (NOW() - created_at))))
           FROM password_resets
          WHERE user_id = ?
          ORDER BY id DESC
          LIMIT 1'
    );
    $stmt->execute([(int) RESET_RESEND_SECS, $userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Pure check of a reset row against a typed code. No DB.
 * $row: token (sha256 hash), expires_at (timestamp or string), used, attempts.
 * Returns 'ok', 'expired', 'used', 'locked' or 'wrong'.
 */
function reset_code_check(array $row, string $input, int $now): string
{
    if (!empty($row['used'])) {
        return 'used';
    }
    if ((int) ($row['attempts'] ?? 0) >= RESET_MAX_ATTEMPTS) {
        return 'locked';
    }

    $exp = is_numeric($row['expires_at'] ?? null)
        ? (int) $row['expires_at']
        : (int) strtotime((string) ($row['expires_at'] ?? ''));
    if ($exp <= $now) {
        return 'expired';
    }

    return hash_equals((string) ($row['token'] ?? ''), hash('sha256', $input)) ? 'ok' : 'wrong';
}

function send_reset_code_email(string $email, string $code): bool
{
    $body = '<p>We got a request to reset the password for your account.</p>'
        . email_muted('Your password reset code:', 'margin:16px 0 6px 0;')
        . email_code($code)
        . email_muted('This code expires in 15 minutes. '
            . 'If you did not ask for it, ignore this email. Your password stays the same.');

    return send_email($email, 'Your PLP password reset code', email_template('Password Reset Code', $body));
}

// ----------------------------------------------------------------
// LOGIN RATE LIMITING
// ----------------------------------------------------------------

function record_failed_login(string $email): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')->execute([$email, $ip]);
}

function clear_login_attempts(string $email): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}

function is_login_locked(string $email): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > NOW() - INTERVAL \'15 minutes\''
    );
    $stmt->execute([$email]);
    return (int) $stmt->fetchColumn() >= 5;
}
