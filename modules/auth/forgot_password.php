<?php
// ============================================================
// modules/auth/forgot_password.php
// Password reset steps 1 and 2: email -> 6-digit code -> confirm.
// The code is only ever emailed. It is never shown on screen.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

// Security verification runs once on page start, not on the form.
captcha_gate('/forgot-password');

// "Use a different email" clears the flow.
if (isset($_GET['new'])) {
    unset($_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_miss']);
    header('Location: ' . url('/forgot-password')); exit;
}

$errors = [];
$notice = '';
$email  = trim($_SESSION['reset_email'] ?? '');
$step   = $email !== '' ? 2 : 1;

function reset_find_user(string $email): ?array
{
    $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

/** Send a code if the account exists and the rest period is over. Never reveals anything. */
function reset_send(?array $user): void
{
    if (!$user) return;
    if (reset_cooldown((int) $user['id']) > 0) return;
    $code = create_reset_code((int) $user['id']);
    if (!send_reset_code_email($user['email'], $code)) {
        error_log('Password reset email failed for user ' . (int) $user['id']);
    }
    audit_log('password_reset_requested', 'Reset code sent', 'user', (int) $user['id']);
}

if (($action = require_post_action('send')) !== null) {

    if ($action === 'send') {
        $email = post_str('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } else {
            reset_send(reset_find_user($email));
            // Same screen for known and unknown emails.
            $_SESSION['reset_email']   = $email;
            $_SESSION['reset_sent_at'] = time();
            $_SESSION['reset_miss']    = 0;
            $step = 2;
        }

    } elseif ($action === 'resend' && $email !== '') {
        $user  = reset_find_user($email);
        $rest  = max(0, RESET_RESEND_SECS - (time() - (int) ($_SESSION['reset_sent_at'] ?? 0)));
        if ($user) $rest = max($rest, reset_cooldown((int) $user['id']));

        if ($rest > 0) {
            $errors['code'] = 'Please wait ' . $rest . ' seconds before requesting another code.';
        } else {
            reset_send($user);
            $_SESSION['reset_sent_at'] = time();
            $_SESSION['reset_miss']    = 0;
            $notice = 'A new code was sent. The older code no longer works.';
        }

    } elseif ($action === 'confirm' && $email !== '') {
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $msgs = [
            'wrong'   => 'That code is not correct.',
            'expired' => 'That code has expired. Request a new one.',
            'used'    => 'That code is no longer valid. Request a new one.',
            'locked'  => 'Too many wrong tries. Request a new code.',
        ];

        if (strlen($code) !== 6) {
            $errors['code'] = 'Enter the 6 digit code.';
        } else {
            $stmt = db()->prepare(
                'SELECT pr.* FROM password_resets pr
                   JOIN users u ON u.id = pr.user_id
                  WHERE u.email = ? AND u.is_active = 1 AND pr.used = 0
                  ORDER BY pr.id DESC LIMIT 1'
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch();

            if (!$row) {
                // Unknown email (or no live code): count misses in the session so
                // the lockout looks the same as for a real account.
                $_SESSION['reset_miss'] = (int) ($_SESSION['reset_miss'] ?? 0) + 1;
                $result = $_SESSION['reset_miss'] >= RESET_MAX_ATTEMPTS ? 'locked' : 'wrong';
            } else {
                $result = reset_code_check($row, $code, time());
                if ($result === 'wrong') {
                    db()->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')
                       ->execute([$row['id']]);
                    if ((int) $row['attempts'] + 1 >= RESET_MAX_ATTEMPTS) $result = 'locked';
                }
            }

            if ($result === 'ok') {
                $_SESSION['reset_ok'] = [
                    'user_id' => (int) $row['user_id'],
                    'row_id'  => (int) $row['id'],
                    'expires' => time() + RESET_OK_TTL_SECS,
                ];
                unset($_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_miss']);
                header('Location: ' . url('/reset-password')); exit;
            }
            $errors['code'] = $msgs[$result];
        }
    }
}

// Seconds left on the Resend button (server value, JS only counts down).
$resendIn = 0;
if ($step === 2) {
    $resendIn = max(0, RESET_RESEND_SECS - (time() - (int) ($_SESSION['reset_sent_at'] ?? 0)));
    $u = reset_find_user($email);
    if ($u) $resendIn = max($resendIn, reset_cooldown((int) $u['id']));
}

ob_start();
?>
<div class="auth-card animate-fade-in">

    <div class="auth-header">
        <?php if ($step === 1): ?>
            <h1 class="auth-title">Forgot your password?</h1>
            <p class="auth-subtitle">Enter your email and we will send you a 6 digit code.</p>
        <?php else: ?>
            <h1 class="auth-title">Enter the code</h1>
            <p class="auth-subtitle">If that email is registered, we sent a 6 digit code to <strong><?= e($email) ?></strong>. It expires in 15 minutes.</p>
        <?php endif; ?>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-success" role="status">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
            <?= e($notice) ?>
        </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>

        <form method="POST" action="<?= url('/forgot-password') ?>" data-once novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
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
            <button type="submit" class="btn btn-primary btn-block">Send code</button>
        </form>

    <?php else: ?>

        <form method="POST" action="<?= url('/forgot-password') ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <div class="form-group">
                <label class="form-label" for="code">6 digit code</label>
                <input type="text" id="code" name="code"
                    class="form-input auth-code <?= isset($errors['code']) ? 'error' : '' ?>"
                    inputmode="numeric" maxlength="6" pattern="[0-9]*"
                    autocomplete="one-time-code" placeholder="123456"
                    required>
                <?php if (!empty($errors['code'])): ?>
                    <span class="form-error"><?= e($errors['code']) ?></span>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Confirm code</button>
        </form>

        <div class="auth-footer">
            Didn't get the code?
            <form method="POST" action="<?= url('/forgot-password') ?>" class="auth-inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend">
                <button type="submit" id="resend-btn" class="auth-link-btn" <?= $resendIn > 0 ? 'disabled' : '' ?>>
                    <span id="resend-label"><?= $resendIn > 0 ? 'Resend in ' . (int) $resendIn . 's' : 'Resend code' ?></span>
                </button>
            </form>
        </div>

        <div class="auth-footer">
            <a class="auth-link" href="<?= url('/forgot-password?new=1') ?>">Use a different email</a>
        </div>

        <script>
        (function () {
            var btn = document.getElementById('resend-btn');
            var label = document.getElementById('resend-label');
            var left = <?= (int) $resendIn ?>;
            if (!btn || left <= 0) return;
            btn.disabled = true;
            var t = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(t);
                    btn.disabled = false;
                    label.textContent = 'Resend code';
                } else {
                    label.textContent = 'Resend in ' + left + 's';
                }
            }, 1000);
        })();
        </script>

    <?php endif; ?>

    <div class="auth-footer">
        <a class="auth-back" href="<?= url('/login') ?>"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Back to sign in</a>
    </div>

</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Reset Password';
include VIEWS_PATH . '/layouts/auth.php';
