<?php
// ============================================================
// modules/auth/login.php
// M2 — Authentication: Login
//
// Standalone page (does not use views/layouts/auth.php).
// Images expected in public/assets/img/:
//   plp logo.png   — school seal
//   PLP PICTURE.jpg   — campus photo for the hero background
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

// Already logged in — redirect home
if (Auth::check()) {
    header("Location: " . Auth::homeUrl()); exit;
}

$errors     = [];
$didTimeout = Session::getFlash('timeout');

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
                Session::flash('error', 'Verify your email first. Enter the code we sent, or request a new one.');
                redirect('/verify-pending');
            } else {
                clear_login_attempts($email);
                Auth::login($user);
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
$logoUrl  = asset('img/' . rawurlencode('plp logo.png'));
$photoUrl = asset('img/' . rawurlencode('schol blg.jpg'));

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://js.hcaptcha.com https://*.hcaptcha.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https:; frame-src https://newassets.hcaptcha.com https://*.hcaptcha.com; connect-src 'self' https://*.hcaptcha.com;");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — PLP Admissions</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
    :root {
        --lg-green-950: #0c2a1a;
        --lg-green-800: #14532d;
        --lg-green-700: #1b6b3a;
        --lg-green-600: #1f7a3f;
        --lg-ink: #1a2420;
        --lg-muted: #5d6b63;
        --lg-field: #e8eefc;
        --lg-paper: #ffffff;
        --lg-link: #1d6b3b;
        --lg-danger: #b3261e;
        --lg-font-display: "Anton", "Arial Narrow", Impact, sans-serif;
        --lg-font-body: "Figtree", system-ui, -apple-system, "Segoe UI", sans-serif;
        --lg-radius: 8px;
        --lg-radius-lg: 16px;
        --lg-weight-semibold: 600;
        color-scheme: light;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body { background: var(--lg-green-950); color: #fff; font-family: var(--lg-font-body); }
    [hidden] { display: none !important; }

    .lg-page { min-height: 100vh; display: flex; flex-direction: column; }

    /* top bar */
    .lg-bar { background: var(--lg-paper); color: var(--lg-ink); padding: 12px clamp(16px, 8vw, 150px); display: flex; align-items: center; gap: 14px; }
    .lg-logo { width: 52px; height: 52px; object-fit: contain; flex: none; display: block; }
    .lg-bar-name { min-width: 0; }
    .lg-bar-title { margin: 0; font-size: 1.5rem; font-weight: var(--lg-weight-semibold); color: var(--lg-green-700); line-height: 1.1; }
    .lg-bar-sub { margin: 2px 0 0; font-size: .72rem; letter-spacing: .06em; color: var(--lg-muted); text-transform: uppercase; }

    /* hero */
    .lg-hero {
        flex: 1; position: relative; overflow: hidden;
        padding: clamp(32px, 6vw, 72px) clamp(16px, 8vw, 150px);
        display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 420px); gap: clamp(24px, 5vw, 80px); align-items: center;
        background:
            url("<?= e($photoUrl) ?>") center / cover no-repeat,
            #0c2a1a;
    }
    /* One flat green wash over the photo (no gradient) */
    .lg-hero::before { content: ''; position: absolute; inset: 0; background: rgba(10,38,22,.72); }
    .lg-hero > * { position: relative; z-index: 1; }

    .lg-intro { min-width: 0; }
    .lg-school { margin: 0 0 14px; font-size: .95rem; font-weight: var(--lg-weight-semibold); letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.9); }
    .lg-big { margin: 0 0 32px; font-family: var(--lg-font-display); font-weight: 400; font-size: clamp(3rem, 7.2vw, 5.4rem); line-height: .98; text-transform: uppercase; letter-spacing: .005em; text-wrap: balance; }

    .lg-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
    .lg-tab { background: none; border: 0; padding: 8px 16px; border-radius: var(--lg-radius); font: inherit; font-size: 1.02rem; font-weight: var(--lg-weight-semibold); color: rgba(255,255,255,.7); cursor: pointer; }
    .lg-tab:hover { background: rgba(255,255,255,.08); color: #fff; }
    .lg-tab[aria-selected="true"] { color: #fff; background: rgba(255,255,255,.16); }
    .lg-tab:focus-visible { outline: 2px solid #fff; outline-offset: 4px; }

    .lg-notice { margin: 0 0 18px; padding: 10px 14px; background: var(--lg-green-600); border-radius: var(--lg-radius); font-size: .92rem; max-width: 640px; }

    .lg-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; max-width: 640px; }
    .lg-info { background: rgba(8,22,14,.72); border: 1px solid rgba(255,255,255,.14); border-radius: 8px; padding: 16px 16px 16px 18px; display: flex; gap: 12px; justify-content: space-between; min-width: 0; }
    .lg-info h3 { margin: 0 0 8px; font-size: 1.05rem; font-weight: var(--lg-weight-semibold); }
    .lg-info p { margin: 0; font-size: .8rem; line-height: 1.5; color: rgba(255,255,255,.78); }
    .lg-info svg { width: 52px; height: 52px; flex: none; fill: none; stroke: #fff; stroke-width: 1.3; stroke-linecap: round; stroke-linejoin: round; opacity: .92; }

    /* login card */
    .lg-card { background: var(--lg-paper); color: var(--lg-ink); border-radius: var(--lg-radius-lg); padding: 34px 32px 28px; box-shadow: 0 24px 60px rgba(0,0,0,.38); }
    .lg-lead { margin: 0 0 24px; text-align: center; font-size: 1.3rem; font-weight: var(--lg-weight-semibold); color: var(--lg-green-700); }

    .lg-alert { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 16px; padding: 10px 12px; border-radius: var(--lg-radius); font-size: .86rem; line-height: 1.4; }
    .lg-alert-error { background: #fdecea; color: var(--lg-danger); border: 1px solid #f3c1bc; }
    .lg-alert-info  { background: #e8f3ec; color: var(--lg-green-800); border: 1px solid #bfdcc9; }

    .lg-field { margin-bottom: 16px; }
    .lg-field label { display: block; margin-bottom: 6px; font-size: .82rem; font-weight: var(--lg-weight-semibold); }
    .lg-input-wrap { position: relative; }
    .lg-field input { width: 100%; height: 48px; padding: 0 14px; border: 2px solid transparent; border-radius: var(--lg-radius); background: var(--lg-field); font: inherit; font-size: .95rem; color: var(--lg-ink); }
    .lg-field input::placeholder { color: #8b97a0; }
    .lg-field input:focus { outline: none; border-color: var(--lg-green-600); background: #fff; }
    .lg-field input.lg-bad { border-color: var(--lg-danger); background: #fdecea; }
    .lg-err { display: block; margin-top: 5px; font-size: .78rem; color: var(--lg-danger); }
    .lg-has-eye input { padding-right: 48px; }
    .lg-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border: 0; background: none; border-radius: var(--lg-radius); cursor: pointer; display: grid; place-items: center; color: var(--lg-muted); }
    .lg-eye:hover { background: rgba(0,0,0,.06); }
    .lg-eye:focus-visible { outline: 2px solid var(--lg-green-600); }
    .lg-eye svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
    .lg-eye.on { color: var(--lg-green-700); }

    .lg-forgot { text-align: right; margin: -4px 0 20px; }
    .lg-forgot a, .lg-signup a { color: var(--lg-link); font-size: .86rem; font-weight: var(--lg-weight-semibold); text-decoration: underline; text-underline-offset: 3px; }
    .lg-go { width: 100%; height: 50px; border: 0; border-radius: var(--lg-radius); background: var(--lg-green-700); color: #fff; font: inherit; font-size: 1rem; font-weight: var(--lg-weight-semibold); cursor: pointer; transition: background .15s; }
    .lg-go:hover { background: var(--lg-green-800); }
    .lg-go:disabled { opacity: .7; cursor: wait; }
    .lg-go:focus-visible { outline: 3px solid rgba(31,122,63,.4); outline-offset: 2px; }
    .lg-signup { margin: 18px 0 0; text-align: center; font-size: .86rem; color: var(--lg-muted); }

    @media (max-width: 880px) {
        .lg-hero { grid-template-columns: minmax(0, 1fr); }
        .lg-card { order: -1; max-width: 460px; width: 100%; justify-self: center; }
        .lg-big { font-size: clamp(2.6rem, 13vw, 4rem); margin-bottom: 24px; }
    }
    @media (max-width: 520px) {
        .lg-cards { grid-template-columns: minmax(0, 1fr); }
        .lg-card { padding: 26px 20px 22px; }
        .lg-bar-title { font-size: 1.2rem; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

<div class="lg-page">

    <header class="lg-bar">
        <img class="lg-logo" src="<?= e($logoUrl) ?>" alt="PLP seal">
        <div class="lg-bar-name">
            <p class="lg-bar-title">PLP Admissions</p>
            <p class="lg-bar-sub">Pamantasan ng Lungsod ng Pasig</p>
        </div>
    </header>

    <main class="lg-hero">

        <!-- Left: intro -->
        <section class="lg-intro" aria-labelledby="lg-hero-title">
            <p class="lg-school">Pamantasan ng Lungsod ng Pasig</p>
            <h1 class="lg-big" id="lg-hero-title">Admissions<br>Office</h1>

            <div class="lg-tabs" role="tablist" aria-label="Admissions information">
                <button type="button" class="lg-tab" role="tab" id="lg-t1" aria-selected="true"  aria-controls="lg-p1">Apply Online</button>
                <button type="button" class="lg-tab" role="tab" id="lg-t2" aria-selected="false" aria-controls="lg-p2">Advisory</button>
            </div>

            <div id="lg-p1" role="tabpanel" aria-labelledby="lg-t1">
                <p class="lg-notice">Create an account, upload your requirements, and track your application from one place.</p>
                <div class="lg-cards">
                    <article class="lg-info">
                        <div>
                            <h3>Submit Documents</h3>
                            <p>Upload your PSA birth certificate, Form 137 or 138, IDs, and other requirements.</p>
                        </div>
                        <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M12 5h17l9 9v29H12z"/><path d="M29 5v9h9M18 24h14M18 30h14M18 36h9"/></svg>
                    </article>
                    <article class="lg-info">
                        <div>
                            <h3>Exam &amp; Interview</h3>
                            <p>See your entrance exam schedule, interview slot, and final result as they are released.</p>
                        </div>
                        <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="7" y="9" width="34" height="32" rx="3"/><path d="M7 19h34M16 5v8M32 5v8M16 27h6M26 27h6M16 34h6"/></svg>
                    </article>
                </div>
            </div>

            <div id="lg-p2" role="tabpanel" aria-labelledby="lg-t2" hidden>
                <p class="lg-notice">Advisory: documents must be uploaded as PDF, JPG, PNG or WEBP, 4 MB or smaller.</p>
                <div class="lg-cards">
                    <article class="lg-info">
                        <div>
                            <h3>Check your email</h3>
                            <p>New accounts must verify their email with the code we send before signing in.</p>
                        </div>
                        <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="6" y="11" width="36" height="26" rx="3"/><path d="M7 14l17 13 17-13"/></svg>
                    </article>
                    <article class="lg-info">
                        <div>
                            <h3>Account lock</h3>
                            <p>Too many failed sign-ins lock your account for 15 minutes.</p>
                        </div>
                        <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="10" y="21" width="28" height="20" rx="3"/><path d="M16 21v-6a8 8 0 0 1 16 0v6M24 29v5"/></svg>
                    </article>
                </div>
            </div>
        </section>

        <!-- Right: login card -->
        <section class="lg-card animate-fade-in" aria-labelledby="lg-login-h">
            <p class="lg-lead" id="lg-login-h">Sign in to continue your application</p>

            <?php if ($didTimeout): ?>
                <div class="lg-alert lg-alert-info" role="status">Your session expired. Please sign in again.</div>
            <?php endif; ?>

            <?php if (!empty($errors['general'])): ?>
                <div class="lg-alert lg-alert-error" role="alert"><?= e($errors['general']) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= url('/login') ?>" data-once novalidate>
                <?= csrf_field() ?>

                <div class="lg-field">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="<?= isset($errors['email']) ? 'lg-bad' : '' ?>"
                        value="<?= e($email) ?>"
                        placeholder="you@example.com"
                        autocomplete="email"
                        required
                    >
                    <?php if (!empty($errors['email'])): ?>
                        <span class="lg-err"><?= e($errors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="lg-field">
                    <label for="password">Password</label>
                    <div class="lg-input-wrap lg-has-eye">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="<?= isset($errors['password']) ? 'lg-bad' : '' ?>"
                            placeholder="Password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="lg-eye" id="lg-eye" aria-label="Show password">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <?php if (!empty($errors['password'])): ?>
                        <span class="lg-err"><?= e($errors['password']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="lg-forgot"><a href="<?= url('/forgot-password') ?>">Forgot password?</a></div>

                <?php if (!empty($errors['captcha'])): ?>
                    <div class="lg-alert lg-alert-error" role="alert"><?= e($errors['captcha']) ?></div>
                <?php endif; ?>

                <?php if (HCAPTCHA_ENABLED): ?>
                    <div class="h-captcha" data-sitekey="<?= e(HCAPTCHA_SITE_KEY) ?>" style="margin-bottom:16px"></div>
                <?php endif; ?>

                <button type="submit" class="lg-go">Login</button>
            </form>

            <p class="lg-signup">
                New applicant?
                <a href="<?= url('/register') ?>">Create an account</a>
            </p>
        </section>

    </main>
</div>

<script src="<?= asset('js/jquery.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (HCAPTCHA_ENABLED): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>
<script>
(function () {
    // Info tabs
    var tabs = document.querySelectorAll('.lg-tab');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (o) {
                var on = o === t;
                o.setAttribute('aria-selected', on ? 'true' : 'false');
                document.getElementById(o.getAttribute('aria-controls')).hidden = !on;
            });
        });
    });

    // Live email check on blur
    if (window.jQuery) {
        var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        jQuery('#email').on('blur input', function (ev) {
            var $in = jQuery(this), v = jQuery.trim($in.val());
            if (ev.type === 'input' && !$in.hasClass('lg-bad')) return;
            var bad = v !== '' && !emailRe.test(v);
            $in.siblings('.lg-err').remove();
            $in.toggleClass('lg-bad', bad);
            if (bad) jQuery('<span class="lg-err">Enter a valid email address.</span>').insertAfter($in);
        });
    }

    // Show / hide password
    var pw  = document.getElementById('password');
    var eye = document.getElementById('lg-eye');
    eye.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.classList.toggle('on', show);
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>

</body>
</html>