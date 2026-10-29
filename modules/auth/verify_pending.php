<?php
// ============================================================
// modules/auth/verify_pending.php
// Email verification — code entry path.
//
// Shows a 6-digit code input plus a "Resend code" button. Users land
// here after registering, after clicking an expired magic link, or after
// trying to log in with an unverified account.
//
// Successful verification auto-logs the user in.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header('Location: ' . Auth::homeUrl()); exit; }

// ── Email resolution ────────────────────────────────────────
// Session is set by register.php right after creating the account.
// Falling back to ?email= lets users verify across devices.
$email = trim(strtolower(
    $_POST['email']
    ?? $_GET['email']
    ?? Session::get('verify_pending_email', '')
    ?? ''
));

$errors = [];
$info   = null;

if (($action = require_post_action('verify')) !== null) {

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter the email you registered with.';
    }

    if (empty($errors) && $action === 'resend') {
        // ── Resend a fresh code/link ───────────────────────────────
        $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && empty($user['email_verified'])) {
            $gate = can_resend_verification((int) $user['id']);
            if (!$gate['ok'] && !empty($gate['retry_after'])) {
                $errors['general'] = 'Please wait ' . (int) $gate['retry_after'] . ' seconds before requesting another code.';
            } else {
                $creds = generate_verify_credentials((int) $user['id']);
                try {
                    send_verification_email($user['email'], $user['name'], $creds['token'], $creds['code']);
                    audit_log(AuditAction::VERIFY_RESENT, "Resent verification email for user #{$user['id']}", 'user', (int) $user['id']);
                } catch (\Throwable $e) {
                    error_log('Resend verification email failed: ' . $e->getMessage());
                }
            }
        }
        // Either way (user exists or not), show a generic success so attackers
        // can't enumerate accounts.
        if (empty($errors['general'])) {
            Session::flash('success', 'If the email is registered and unverified, a new code has been sent.');
            Session::set('verify_pending_email', $email);
            redirect('/verify-pending');
        }
    }

    if (empty($errors) && $action === 'verify') {
        // ── Verify a 6-digit code ──────────────────────────────────
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (strlen($code) !== 6) {
            $errors['code'] = 'Enter the 6-digit code from your email.';
        } else {
            $result = verify_user_by_code($email, $code);
            if (!empty($result['ok']) && !empty($result['user'])) {
                Session::remove('verify_pending_email');
                Auth::login($result['user']);
                Session::flash('success', !empty($result['already'])
                    ? 'Welcome back! Your email is already verified.'
                    : 'Email verified. Welcome!');
                header('Location: ' . Auth::homeUrl()); exit;
            }
            $errors['code'] = $result['error'] ?? 'Invalid or expired code.';
            if (!empty($result['attempts_remaining'])) {
                $info = 'Attempts remaining: ' . (int) $result['attempts_remaining'];
            }
        }
    }
}

// Compute resend cooldown for the UI countdown timer.
$resendIn = 0;
if ($email !== '') {
    $stmt = db()->prepare(
        'SELECT GREATEST(0, ' . (int) VERIFY_RESEND_COOLDOWN_SECS
        . ' - TRUNC(EXTRACT(EPOCH FROM (NOW() - email_verify_last_sent_at))))
         FROM users WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $resendIn = (int) ($stmt->fetchColumn() ?: 0);
}

// -- View --------------------------------------------------------
$flashSuccess = Session::getFlash('success');
$flashError   = Session::getFlash('error');

ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <h1 class="auth-title">Verify your email</h1>
        <p class="auth-subtitle">Enter the 6-digit code we emailed you, or click the link in the email.</p>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success" role="status">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
            <?= e($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/verify-pending') ?>" data-once novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">

        <div class="form-group">
            <label class="form-label" for="email">Email address</label>
            <input type="email" id="email" name="email"
                class="form-input <?= isset($errors['email']) ? 'error' : '' ?>"
                value="<?= e($email) ?>"
                placeholder="you@example.com"
                autocomplete="email" required>
            <?php if (!empty($errors['email'])): ?>
                <span class="form-error"><?= e($errors['email']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="code">Verification code</label>
            <input type="text" id="code" name="code"
                class="form-input auth-code <?= isset($errors['code']) ? 'error' : '' ?>"
                inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                autocomplete="one-time-code" placeholder="123456"
                required>
            <?php if (!empty($errors['code'])): ?>
                <span class="form-error"><?= e($errors['code']) ?></span>
            <?php endif; ?>
            <?php if ($info): ?>
                <span class="form-hint"><?= e($info) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Verify and continue</button>
    </form>

    <div class="auth-footer">
        Didn't get the email?
        <form method="POST" action="<?= url('/verify-pending') ?>" class="auth-inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <input type="hidden" name="email"  value="<?= e($email) ?>">
            <button type="submit" id="resend-btn" class="auth-link-btn" data-resend-in="<?= (int) $resendIn ?>" <?= $resendIn > 0 ? 'disabled' : '' ?>>
                <span id="resend-label"><?= $resendIn > 0 ? 'Resend in ' . $resendIn . 's' : 'Resend code' ?></span>
            </button>
        </form>
    </div>

    <div class="auth-footer">
        <a class="auth-back" href="<?= url('/login') ?>"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Back to sign in</a>
    </div>
</div>


<?php
$content   = ob_get_clean();
$pageTitle = 'Verify Email';
$pageScripts = ['js/pages/auth-verify-code.js', 'js/pages/auth-resend.js'];
include VIEWS_PATH . '/layouts/auth.php';
