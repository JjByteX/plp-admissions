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

ensure_email_verification_columns();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'verify';

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
                    audit_log('verify_resent', "Resent verification email for user #{$user['id']}", 'user', (int) $user['id']);
                } catch (\Throwable $e) {
                    error_log('Resend verification email failed: ' . $e->getMessage());
                }
            }
        }
        // Either way (user exists or not), show a generic success so attackers
        // can't enumerate accounts.
        if (empty($errors['general'])) {
            Session::flash('success', 'If that email is registered and unverified, a fresh code is on its way.');
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
        . ' - TIMESTAMPDIFF(SECOND, email_verify_last_sent_at, NOW()))
         FROM users WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $resendIn = (int) ($stmt->fetchColumn() ?: 0);
}

// -- View --------------------------------------------------------
$schoolLogo = school_setting('school_logo', '');
$flashSuccess = Session::getFlash('success');
$flashError   = Session::getFlash('error');

ob_start();
?>
<div class="auth-card animate-fade-in">

    <button class="auth-theme-toggle" onclick="Theme.toggle()" aria-label="Toggle theme">
        <?= icon('ic_fluent_weather_sunny_24_regular', 16, '', 'data-theme-icon="dark" class="hidden"') ?>
        <?= icon('ic_fluent_weather_moon_24_regular', 16, '', 'data-theme-icon="light"') ?>
    </button>

    <div class="auth-header">
        <?php if ($schoolLogo): ?>
            <img src="<?= e(str_starts_with($schoolLogo, 'http') ? $schoolLogo : url($schoolLogo)) ?>" alt="School Logo" class="auth-logo-img">
        <?php else: ?>
            <div class="auth-logo">
                <?php include VIEWS_PATH . '/partials/icons/ic_fluent_building_bank_24_regular.svg'; ?>
            </div>
        <?php endif; ?>
        <div class="auth-header-text">
            <h1 class="auth-title">Verify your email</h1>
            <p class="auth-subtitle">Enter the 6-digit code we emailed you, or click the link in the email.</p>
        </div>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success" style="margin-bottom:var(--space-5)">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
            <?= e($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/verify-pending') ?>" data-once novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">

        <div class="form-group">
            <label class="form-label" for="email">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-input <?= isset($errors['email']) ? 'error' : '' ?>"
                value="<?= e($email) ?>"
                placeholder="you@example.com"
                autocomplete="email"
                required
            >
            <?php if (!empty($errors['email'])): ?>
                <span class="form-error"><?= e($errors['email']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="code">Verification code</label>
            <input
                type="text"
                id="code"
                name="code"
                class="form-input <?= isset($errors['code']) ? 'error' : '' ?>"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                autocomplete="one-time-code"
                placeholder="123 456"
                style="font-family:var(--font-mono);font-size:var(--text-xl);letter-spacing:.4em;text-align:center"
                required
                autofocus
            >
            <?php if (!empty($errors['code'])): ?>
                <span class="form-error"><?= e($errors['code']) ?></span>
            <?php endif; ?>
            <?php if ($info): ?>
                <span class="form-error" style="color:var(--text-tertiary)"><?= e($info) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Verify and continue</button>
    </form>

    <div style="margin-top:var(--space-5);text-align:center;font-size:var(--text-sm);color:var(--text-secondary)">
        Didn't get the email?
        <form method="POST" action="<?= url('/verify-pending') ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <input type="hidden" name="email"  value="<?= e($email) ?>">
            <button type="submit"
                    id="resend-btn"
                    class="btn-link"
                    style="background:none;border:none;color:var(--accent);font-weight:var(--weight-medium);cursor:pointer;padding:0"
                    <?= $resendIn > 0 ? 'disabled' : '' ?>>
                <span id="resend-label"><?= $resendIn > 0 ? 'Resend in ' . $resendIn . 's' : 'Resend code' ?></span>
            </button>
        </form>
    </div>

    <div style="margin-top:var(--space-3);text-align:center">
        <a href="<?= url('/login') ?>" style="font-size:var(--text-xs);color:var(--text-tertiary)">Back to login</a>
    </div>
</div>

<script>
(function () {
    var btn   = document.getElementById('resend-btn');
    var label = document.getElementById('resend-label');
    var left  = <?= (int) $resendIn ?>;
    if (!btn || left <= 0) return;
    btn.disabled = true;
    var t = setInterval(function () {
        left -= 1;
        if (left <= 0) {
            clearInterval(t);
            btn.disabled  = false;
            label.textContent = 'Resend code';
        } else {
            label.textContent = 'Resend in ' + left + 's';
        }
    }, 1000);

    // Auto-submit when the user pastes/types a 6-digit code.
    var input = document.getElementById('code');
    if (input) {
        input.addEventListener('input', function () {
            var v = (input.value || '').replace(/\D/g, '');
            if (v.length === 6) input.form.submit();
        });
    }
})();
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Verify Email';
include VIEWS_PATH . '/layouts/auth.php';
