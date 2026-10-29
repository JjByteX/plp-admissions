<?php
// ============================================================
// views/layouts/auth.php
// Shared layout for every auth page (login, register, forgot / reset
// password, verify email, privacy policy).
//
// Optional variables set by the page before including this file:
//   $pageTitle        string  Browser title
//   $authWide         bool    Register only: no photo panel, top bar + wide
//                             content instead (so a long form gets the space)
//   $authTopbarLink   array   [text, label, href] shown on the right of the
//                             wide top bar
//   $hidePrivacyLink  bool    Hide the footer Privacy Policy link
//   $loadTurnstile    bool    Security check page only: load the Turnstile script
//   $pageStyles       array   Extra stylesheets (paths under public/assets)
//   $pageScripts      array   Extra scripts (paths under public/assets), loaded before jQuery
// ============================================================
$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$pageTitle   = $pageTitle ?? 'Welcome';
$authLogo    = school_setting('school_logo', '');
$authLogoUrl = $authLogo
    ? (str_starts_with($authLogo, 'http') ? $authLogo : url($authLogo))
    : asset('img/' . rawurlencode('plp logo.png'));
$authPhotoUrl = asset('img/' . rawurlencode('schol blg.jpg'));
?>
<?php
header("Content-Security-Policy: " . auth_csp());
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e($schoolName) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap">

    <?= phosphor_head() ?>

    <!-- Critical canvas colour: applies before app.css arrives so page switches never flash -->
    <link rel="stylesheet" href="<?= asset('css/auth-critical.css') ?>">
    <!-- Bootstrap 5.3.3 + Bootstrap Icons, served locally (works offline). Order: Bootstrap -> bootstrap-compat.css -> app.css -->
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/bootstrap-compat.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
    <link rel="stylesheet" href="<?= asset($pageStyle) ?>">
    <?php endforeach; ?>

    <script src="<?= asset('js/auth-head.js') ?>"></script>
</head>
<body data-accent="<?= e($accentColor) ?>">

<?php if (!empty($authWide)): ?>
<div class="auth-page auth-page--wide">
    <header class="auth-topbar">
        <a class="auth-brand auth-brand--bar" href="<?= url('/login') ?>">
            <img class="auth-brand-logo" src="<?= e($authLogoUrl) ?>" alt="PLP seal">
            <span>
                <span class="auth-brand-title">PLP Admissions</span>
                <span class="auth-brand-sub"><?= e($schoolName) ?></span>
            </span>
        </a>
        <?php if (!empty($authTopbarLink)): ?>
        <p class="auth-topbar-link">
            <?= e($authTopbarLink['text']) ?>
            <a class="auth-link" href="<?= e($authTopbarLink['href']) ?>"><?= e($authTopbarLink['label']) ?></a>
        </p>
        <?php endif; ?>
    </header>
    <main class="auth-main">
        <?= $content ?? '' ?>
    </main>
    <?php if (empty($hidePrivacyLink)): ?>
    <footer class="auth-legal"><a href="<?= url('/privacy') ?>">Privacy Policy</a></footer>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="auth-page" style="--auth-photo:url('<?= e($authPhotoUrl) ?>')">
    <aside class="auth-aside">
        <div class="auth-brand">
            <img class="auth-brand-logo" src="<?= e($authLogoUrl) ?>" alt="PLP seal">
            <div>
                <p class="auth-brand-title">PLP Admissions</p>
                <p class="auth-brand-sub"><?= e($schoolName) ?></p>
            </div>
        </div>
        <div class="auth-aside-copy">
            <h2>Your application, in one place.</h2>
            <ul class="auth-points">
                <li>Upload your requirements (PDF, JPG, PNG or WEBP, 4 MB max)</li>
                <li>See your exam and interview schedule, then your result</li>
                <li>Verify your email with the code we send before signing in</li>
            </ul>
        </div>
        <p class="auth-aside-foot">Admissions Office</p>
    </aside>
    <div class="auth-pane">
        <main class="auth-main">
            <?= $content ?? '' ?>
        </main>
        <?php if (empty($hidePrivacyLink)): ?>
        <footer class="auth-legal"><a href="<?= url('/privacy') ?>">Privacy Policy</a></footer>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* Page scripts load before jquery/app.js, the same point the old inline blocks ran at. */ ?>
<?php foreach (($pageScripts ?? []) as $pageScript): ?>
<script src="<?= asset($pageScript) ?>"></script>
<?php endforeach; ?>
<script src="<?= asset('js/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/auth-ui.js') ?>"></script>
<?php if (CAPTCHA_ENABLED && !empty($loadTurnstile)): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>

</body>
</html>