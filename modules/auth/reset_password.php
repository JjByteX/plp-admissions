<?php
// ============================================================
// modules/auth/reset_password.php
// M2 — Password reset via token
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

$errors  = [];
$success = false;
$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Validate token exists
$resetRow = null;
if ($token) {
    $stmt = db()->prepare(
        'SELECT pr.*, u.email FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $resetRow = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$resetRow) {
        $errors['token'] = 'This reset token is invalid or has expired.';
    } else {
        $password        = $_POST['password']         ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8)       $errors['password']         = 'Password must be at least 8 characters.';
        if ($password !== $passwordConfirm) $errors['password_confirm'] = 'Passwords do not match.';

        if (empty($errors)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([
                   password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                   $resetRow['user_id'],
               ]);

            db()->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
               ->execute([$resetRow['id']]);

            $success = true;
        }
    }
}

ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <div class="auth-logo">
            <?php include VIEWS_PATH . '/partials/icons/shield.svg.php'; ?>
        </div>
        <h1 class="auth-title">New password</h1>
        <p class="auth-subtitle">Choose a strong password for your account</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:var(--space-5)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Password updated successfully.
        </div>
        <a href="<?= url('/login') ?>" class="btn btn-primary btn-block">Sign in</a>

    <?php elseif (!$token || !$resetRow): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
            This reset link is invalid or has expired.
        </div>
        <a href="<?= url('/forgot-password') ?>" class="btn btn-secondary btn-block">Request new token</a>

    <?php else: ?>
        <form method="POST" action="<?= url('/reset-password') ?>" data-once novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="form-group">
                <label class="form-label" for="password">New password</label>
                <input type="password" id="password" name="password"
                    class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                    placeholder="Min. 8 characters"
                    autocomplete="new-password" required>
                <?php if (!empty($errors['password'])): ?>
                    <span class="form-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Confirm new password</label>
                <input type="password" id="password_confirm" name="password_confirm"
                    class="form-input <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                    placeholder="Repeat password"
                    autocomplete="new-password" required>
                <?php if (!empty($errors['password_confirm'])): ?>
                    <span class="form-error"><?= e($errors['password_confirm']) ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Update password</button>
        </form>
    <?php endif; ?>

    <div class="auth-footer">
        <a href="<?= url('/login') ?>">← Back to sign in</a>
    </div>

</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'New Password';
include VIEWS_PATH . '/layouts/auth.php';