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
        audit_log('password_reset_done', 'Password reset with emailed code', 'user', (int) $resetRow['user_id']);

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
        <div class="auth-logo">
            <?= icon('shield-check:fill', 26) ?>
        </div>
        <h1 class="auth-title">New password</h1>
        <p class="auth-subtitle">Choose a strong password for your account</p>
    </div>

    <form method="POST" action="<?= url('/reset-password') ?>" id="reset-form" data-once novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="password">New password</label>
            <div style="position:relative">
                <input type="password" id="password" name="password"
                    class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                    style="padding-right:44px"
                    placeholder="Min. 8 characters"
                    autocomplete="new-password" required>
                <button type="button" class="pw-eye" data-target="password" aria-label="Show password"
                    style="position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;background:none;cursor:pointer;color:var(--text-tertiary);display:grid;place-items:center">
                    <i class="ph-bold ph-eye" aria-hidden="true" style="font-size:20px"></i>
                </button>
            </div>
            <span class="form-hint" id="pw-hint" style="font-size:var(--text-xs);color:var(--text-tertiary)">At least 8 characters.</span>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="password_confirm">Re-enter new password</label>
            <div style="position:relative">
                <input type="password" id="password_confirm" name="password_confirm"
                    class="form-input <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                    style="padding-right:44px"
                    placeholder="Repeat password"
                    autocomplete="new-password" required>
                <button type="button" class="pw-eye" data-target="password_confirm" aria-label="Show password"
                    style="position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;background:none;cursor:pointer;color:var(--text-tertiary);display:grid;place-items:center">
                    <i class="ph-bold ph-eye" aria-hidden="true" style="font-size:20px"></i>
                </button>
            </div>
            <span id="match-msg" role="status" style="font-size:var(--text-xs)"></span>
            <?php if (!empty($errors['password_confirm'])): ?>
                <span class="form-error"><?= e($errors['password_confirm']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" id="reset-btn" class="btn btn-primary btn-block" disabled>Update password</button>
    </form>

    <div class="auth-footer">
        <a href="<?= url('/forgot-password?new=1') ?>"><?= icon('ic_fluent_arrow_left_24_regular', 14, 'display:inline-block;vertical-align:-2px') ?> Start over</a>
    </div>

</div>

<script>
(function () {
    var pw = document.getElementById('password');
    var pc = document.getElementById('password_confirm');
    var hint = document.getElementById('pw-hint');
    var msg = document.getElementById('match-msg');
    var btn = document.getElementById('reset-btn');

    function update() {
        var long = pw.value.length >= 8;
        hint.textContent = long ? 'Length looks good.' : 'At least 8 characters (' + pw.value.length + '/8).';
        hint.style.color = long ? 'var(--success, #2d6a4f)' : 'var(--text-tertiary)';

        var match = pc.value.length > 0 && pw.value === pc.value;
        if (pc.value.length === 0) {
            msg.textContent = '';
        } else if (match) {
            msg.textContent = 'Passwords match';
            msg.style.color = 'var(--success, #2d6a4f)';
        } else {
            msg.textContent = 'Passwords do not match';
            msg.style.color = 'var(--danger, #c0392b)';
        }
        btn.disabled = !(long && match);
    }
    pw.addEventListener('input', update);
    pc.addEventListener('input', update);
    update();

    document.querySelectorAll('.pw-eye').forEach(function (b) {
        b.addEventListener('click', function () {
            var input = document.getElementById(b.dataset.target);
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            b.firstElementChild.className = show ? 'ph-bold ph-eye-slash' : 'ph-bold ph-eye';
        });
    });
})();
</script>
<?php
$content   = ob_get_clean();
$pageTitle = 'New Password';
include VIEWS_PATH . '/layouts/auth.php';
