<?php
// ============================================================
// views/layouts/auth.php
// Clean centered layout for login / register / forgot-password
// ============================================================
$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$pageTitle   = $pageTitle ?? 'Welcome';
?>
<?php
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
    <title><?= e($pageTitle) ?> — <?= e($schoolName) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">

    <script>
        (function(){
            const t = localStorage.getItem('plp_theme') || 'light';
            document.documentElement.dataset.theme = t;
        })();
    </script>
</head>
<body>

<div class="auth-page">
    <?= $content ?? '' ?>
</div>



<script src="<?= asset('js/app.js') ?>"></script>
<script>setAccentColor('<?= e($accentColor) ?>');</script>
<?php if (HCAPTCHA_ENABLED): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>

</body>
</html>