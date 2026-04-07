<?php
// ============================================================
// modules/auth/login.php
// M2 — Authentication: Login
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

// Already logged in — redirect home
if (Auth::check()) {
    redirect(Auth::homeUrl());
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
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            Auth::login($user);
            redirect(Auth::homeUrl());
        } else {
            $errors['general'] = 'Incorrect email or password.';
        }
    }
}

// -- View --------------------------------------------------------
ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <div class="auth-logo">
            <?php include VIEWS_PATH . '/partials/icons/school.svg.php'; ?>
        </div>
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">
            <?= e(school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig')) ?><br>
            Admission System
        </p>
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
            <input
                type="password"
                id="password"
                name="password"
                class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                placeholder="••••••••"
                autocomplete="current-password"
                required
            >
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:var(--space-2)">
            Sign in
        </button>
    </form>

    <div class="auth-footer">
        New applicant?
        <a href="<?= url('/register') ?>">Create an account</a>
    </div>

</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Sign In';
include VIEWS_PATH . '/layouts/auth.php';
