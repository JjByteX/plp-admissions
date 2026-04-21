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
            audit_log('login', "Successful login: {$user['email']}", 'user', $user['id']);
            header("Location: " . Auth::homeUrl()); exit;
        } else {
            audit_log('login_failed', "Failed login attempt for: {$email}");
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
            <h1 class="auth-title">PLP Admissions</h1>
            <p class="auth-subtitle">Pamantasan ng Lungsod ng Pasig</p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
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
                    <?= icon('ic_fluent_eye_show_24_regular', 16, '', 'id="eye-password"') ?>
                </button>
            </div>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors['captcha'])): ?>
            <div class="alert alert-error" style="margin-bottom:var(--space-4)">
                <?= icon('ic_fluent_info_24_regular', 16) ?>
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