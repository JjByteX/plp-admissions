<?php
// ============================================================
// modules/auth/forgot_password.php
// M2 — Token-based password reset (token shown on screen)
// No email required — token displayed for staff/admin to relay
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

$errors  = [];
$success = false;
$token   = null;
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (empty($errors)) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalidate old tokens
            db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')
               ->execute([$user['id']]);

            // Generate new token
            $token   = bin2hex(random_bytes(16));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            db()->prepare(
                'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
            )->execute([$user['id'], $token, $expires]);
        }

        // Always show success (don't reveal whether email exists)
        $success = true;
    }
}

ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <div class="auth-logo">
            <?php include VIEWS_PATH . '/partials/icons/help.svg.php'; ?>
        </div>
        <h1 class="auth-title">Reset password</h1>
        <p class="auth-subtitle">Enter your email to get a reset token</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:var(--space-5)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php if ($token): ?>
                Your reset token has been generated. Bring this to the admissions office or use it below.
            <?php else: ?>
                If that email is registered, a reset token has been generated.
            <?php endif; ?>
        </div>

        <?php if ($token): ?>
            <div class="card" style="margin-bottom:var(--space-5);text-align:center">
                <div class="card-title" style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-2)">Your reset token</div>
                <code style="font-family:var(--font-mono);font-size:var(--text-xl);font-weight:500;letter-spacing:0.1em;color:var(--accent)"><?= e($token) ?></code>
                <p style="margin-top:var(--space-2);font-size:var(--text-xs);color:var(--text-tertiary)">Expires in 1 hour</p>
            </div>
            <a href="<?= url('/reset-password?token=' . urlencode($token)) ?>" class="btn btn-primary btn-block">
                Use this token now
            </a>
        <?php endif; ?>

    <?php else: ?>

        <form method="POST" action="<?= url('/forgot-password') ?>" data-once novalidate>
            <?= csrf_field() ?>
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
            <button type="submit" class="btn btn-primary btn-block">Get reset token</button>
        </form>

    <?php endif; ?>

    <div class="auth-footer">
        <a href="<?= url('/login') ?>">← Back to sign in</a>
    </div>

</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Reset Password';
include VIEWS_PATH . '/layouts/auth.php';