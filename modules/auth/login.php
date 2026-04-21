<?php
// ============================================================
// modules/auth/login.php
// M2 — Authentication: Login
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

// Already logged in — redirect home
if (Auth::check()) {
    header("Location: " . Auth::homeUrl()); exit;
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic validation
    if (!$email)    $errors['email']    = 'Email is required.';
    if (!$password) $errors['password'] = 'Password is required.';

    if (empty($errors)) {
        // hCaptcha verification
        if (!hcaptcha_verify()) {
            $errors['captcha'] = 'Please complete the CAPTCHA.';
        }
    }

    if (empty($errors)) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            Auth::login($user);
            header("Location: " . Auth::homeUrl()); exit;
        } else {
            $errors['general'] = 'Incorrect email or password.';
        }
    }
}

// -- View --------------------------------------------------------
$schoolLogo = school_setting('school_logo', '');
ob_start();
?>
<div class="auth-card animate-fade-in">

    <button class="auth-theme-toggle" onclick="Theme.toggle()" aria-label="Toggle theme">
        <svg data-theme-icon="dark" class="hidden" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        <svg data-theme-icon="light" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>

    <div class="auth-header">
        <?php if ($schoolLogo): ?>
            <img src="<?= e(str_starts_with($schoolLogo, 'http') ? $schoolLogo : url($schoolLogo)) ?>" alt="School Logo" class="auth-logo-img">
        <?php else: ?>
            <div class="auth-logo">
                <?php include VIEWS_PATH . '/partials/icons/school.svg.php'; ?>
            </div>
        <?php endif; ?>
        <div class="auth-header-text">
            <h1 class="auth-title">PLP Admissions</h1>
            <p class="auth-subtitle">Pamantasan ng Lungsod ng Pasig</p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/login') ?>" data-once novalidate>
        <?= csrf_field() ?>

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
            <div style="display:flex;justify-content:space-between;align-items:center">
                <label class="form-label" for="password">Password</label>
                <a href="<?= url('/forgot-password') ?>" style="font-size:var(--text-xs);color:var(--accent)">
                    Forgot password?
                </a>
            </div>
            <div class="input-wrapper has-suffix">
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    required
                >
                <button type="button" class="input-suffix-icon btn-pw-toggle" onclick="togglePw('password',this)" tabindex="-1" aria-label="Show password">
                    <svg id="eye-password" width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                </button>
            </div>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors['captcha'])): ?>
            <div class="alert alert-error" style="margin-bottom:var(--space-4)">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
                <?= e($errors['captcha']) ?>
            </div>
        <?php endif; ?>

        <?php if (HCAPTCHA_ENABLED): ?>
            <div class="h-captcha" data-sitekey="<?= e(HCAPTCHA_SITE_KEY) ?>" style="margin-bottom:var(--space-4)"></div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:var(--space-2)">
            Sign in
        </button>
    </form>

    <div class="auth-footer">
        New applicant?
        <a href="<?= url('/register') ?>">Create an account</a>
    </div>

</div>
<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('svg').style.opacity = isText ? '1' : '0.5';
}
</script>
<?php
$content   = ob_get_clean();
$pageTitle = 'Sign In';
include VIEWS_PATH . '/layouts/auth.php';