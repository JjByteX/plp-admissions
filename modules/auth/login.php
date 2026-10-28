<?php
// ============================================================
// modules/auth/login.php
// M2 — Authentication: Login
//
// Uses the shared auth layout (views/layouts/auth.php) like every
// other auth page, so the look, fonts and form parts are identical.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

// Already logged in — redirect home
if (Auth::check()) {
    header("Location: " . Auth::homeUrl()); exit;
}

// Security verification runs once on page start, not on the form.
captcha_gate('/login');

$errors     = [];
$didTimeout = Session::getFlash('timeout');
$resetDone  = Session::getFlash('reset_done');
$googleErr  = Session::getFlash('google_error');
$flashError = Session::getFlash('error');

$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = post_str('email');
    $password = post_str('password');

    // Basic validation
    if (!$email)    $errors['email']    = 'Email is required.';
    if (!$password) $errors['password'] = 'Password is required.';

    // Rate limiting check
    if (empty($errors) && is_login_locked($email)) {
        $errors['general'] = 'Too many failed attempts. Please try again in 15 minutes.';
    }

    if (empty($errors)) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Check email verification for students
            if ($user['role'] === 'student' && empty($user['email_verified'])) {
                // Don't lock the user out — send them to the verify page where they
                // can enter a fresh code or request a resend.
                clear_login_attempts($email);
                Session::set('verify_pending_email', $user['email']);
                fail('Verify your email first. Enter the code we sent, or request a new one.', '/verify-pending');
            } else {
                clear_login_attempts($email);
                Auth::login($user, !empty($_POST['remember']));
                audit_log('login', "Successful login: {$user['email']}", 'user', $user['id']);
                header("Location: " . Auth::homeUrl()); exit;
            }
        } else {
            record_failed_login($email);
            audit_log('login_failed', "Failed login attempt for: {$email}");
            $errors['general'] = 'Incorrect email or password.';
        }
    }
}

// -- View --------------------------------------------------------
ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <h1 class="auth-title">Sign in</h1>
        <p class="auth-subtitle">Continue your application.</p>
    </div>

    <?php if ($didTimeout): ?>
        <div class="alert alert-info" role="status">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            Your session expired. Please sign in again.
        </div>
    <?php endif; ?>

    <?php if ($resetDone): ?>
        <div class="alert alert-success" role="status">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
            Password updated. Sign in with your new password.
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if ($googleErr): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($googleErr) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/login') ?>" data-once novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="email">Email</label>
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
            <label class="form-label" for="password">Password</label>
            <input type="password" id="password" name="password"
                class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                placeholder="Password"
                autocomplete="current-password" required>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <div class="auth-row">
            <label class="form-check" for="remember">
                <input type="checkbox" id="remember" name="remember" value="1">
                <span>Remember me</span>
            </label>
            <a class="auth-link" href="<?= url('/forgot-password') ?>">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <?php if (GOOGLE_ENABLED): ?>
        <div class="auth-divider"><span>or</span></div>
        <a class="auth-google" id="auth-google" href="<?= url('/auth/google') ?>">
            <?= google_logo_svg() ?>
            Continue with Google
        </a>
    <?php endif; ?>

    <div class="auth-footer">
        New applicant?
        <a class="auth-link" href="<?= url('/register') ?>">Create an account</a>
    </div>

</div>

<script>
(function () {
    // Carry the Remember me tick through the Google redirect
    var g = document.getElementById('auth-google'), rem = document.getElementById('remember');
    if (g && rem) {
        g.addEventListener('click', function () {
            g.href = g.href.split('?')[0] + (rem.checked ? '?remember=1' : '');
        });
    }
})();
</script>
<?php
$content   = ob_get_clean();
$pageTitle = 'Sign In';
include VIEWS_PATH . '/layouts/auth.php';
