<?php
// ============================================================
// modules/auth/reset_password.php
// Password reset step 3. Opens only after the emailed code was
// confirmed on /forgot-password (session flag, nothing in the URL).
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

$ok = $_SESSION['reset_ok'] ?? null;
if (!$ok || (int) ($ok['expires'] ?? 0) < time()) {
    unset($_SESSION['reset_ok']);
    header('Location: ' . url('/forgot-password')); exit;
}

// Re-check the row on every request (page load and POST).
$stmt = db()->prepare(
    'SELECT pr.id, pr.user_id, u.email
       FROM password_resets pr
       JOIN users u ON u.id = pr.user_id
      WHERE pr.id = ? AND pr.user_id = ? AND pr.used = 0
        AND pr.expires_at > NOW() - (CAST(? AS integer) * INTERVAL \'1 second\')
        AND u.is_active = 1
      LIMIT 1'
);
$stmt->execute([(int) $ok['row_id'], (int) $ok['user_id'], (int) RESET_CODE_TTL_SECS]);
$resetRow = $stmt->fetch();
if (!$resetRow) {
    unset($_SESSION['reset_ok']);
    header('Location: ' . url('/forgot-password')); exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password        = $_POST['password']         ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8)          $errors['password']         = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm) $errors['password_confirm'] = 'Passwords do not match.';

    if (empty($errors)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([
               password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
               $resetRow['user_id'],
           ]);

        db()->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
           ->execute([$resetRow['id']]);

        clear_login_attempts($resetRow['email']);
        Auth::rememberRevokeAll((int) $resetRow['user_id']);
        audit_log(AuditAction::PASSWORD_RESET_DONE, 'Password reset with emailed code', 'user', (int) $resetRow['user_id']);

        // ponytail: other open sessions live until idle timeout (no user_id on `sessions`). Upgrade: add the column.
        unset($_SESSION['reset_ok'], $_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_miss']);
        Session::flash('reset_done', true);
        header('Location: ' . url('/login')); exit;
    }
}

ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <h1 class="auth-title">New password</h1>
        <p class="auth-subtitle">Choose a new password for your account.</p>
    </div>

    <form method="POST" action="<?= url('/reset-password') ?>" id="reset-form" data-once novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="password">New password</label>
            <input type="password" id="password" name="password"
                class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                placeholder="Min. 8 characters"
                autocomplete="new-password" required>
            <span class="form-hint" id="pw-hint">At least 8 characters.</span>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="password_confirm">Re-enter new password</label>
            <input type="password" id="password_confirm" name="password_confirm"
                class="form-input <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                placeholder="Repeat password"
                autocomplete="new-password" required>
            <span class="form-hint" id="match-msg" role="status"></span>
            <?php if (!empty($errors['password_confirm'])): ?>
                <span class="form-error"><?= e($errors['password_confirm']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" id="reset-btn" class="btn btn-primary btn-block" disabled>Update password</button>
    </form>

    <div class="auth-footer">
        <a class="auth-back" href="<?= url('/forgot-password?new=1') ?>"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Start over</a>
    </div>

</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'New Password';
$pageScripts = ['js/pages/auth-reset-password.js'];
include VIEWS_PATH . '/layouts/auth.php';
