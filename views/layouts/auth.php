<?php
// ============================================================
// views/layouts/auth.php
// Clean centered layout for login / register / forgot-password
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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://js.hcaptcha.com https://*.hcaptcha.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; frame-src https://newassets.hcaptcha.com https://*.hcaptcha.com; connect-src 'self' https://*.hcaptcha.com;");
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

    <?= phosphor_head() ?>

    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">

    <script>
        (function(){
            const t = localStorage.getItem('plp_theme') || 'light';
            document.documentElement.dataset.theme = t;
            var fs = null; try { fs = localStorage.getItem('plp_font_size'); } catch (e) {}
            document.documentElement.dataset.fontSize = ['small','medium','large','xlarge'].indexOf(fs) > -1 ? fs : 'medium';
        })();
    </script>
</head>
<body>

<div class="auth-page" style="--auth-photo:url('<?= e($authPhotoUrl) ?>')">
    <header class="auth-bar">
        <img class="auth-bar-logo" src="<?= e($authLogoUrl) ?>" alt="PLP seal">
        <div>
            <p class="auth-bar-title">PLP Admissions</p>
            <p class="auth-bar-sub"><?= e($schoolName) ?></p>
        </div>
    </header>
    <main class="auth-main">
        <?= $content ?? '' ?>
    </main>
</div>



<script src="<?= asset('js/jquery.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<script>setAccentColor('<?= e($accentColor) ?>');</script>
<script>
// Auth UI (jQuery): show-password toggle, confirm-password check, loading label.
jQuery(function ($) {
    var eye = '<i class="ph-bold ph-eye" style="font-size:18px" aria-hidden="true"></i>';

    // Pages that already ship their own toggle (register) are skipped.
    $('input[type="password"]').each(function () {
        var $in = $(this);
        if ($in.closest('.input-wrapper').find('.btn-pw-toggle').length) return;
        var $wrap = $('<div class="auth-pw"></div>');
        $in.wrap($wrap);
        $('<button type="button" class="auth-pw-eye" aria-label="Show password"></button>')
            .html(eye)
            .insertAfter($in)
            .on('click', function () {
                var show = $in.attr('type') === 'password';
                $in.attr('type', show ? 'text' : 'password');
                $(this).toggleClass('on', show).attr('aria-label', show ? 'Hide password' : 'Show password');
            });
    });

    // Confirm password must match (client hint only; server still validates).
    var $pw = $('#password'), $cf = $('#password_confirm');
    if ($pw.length && $cf.length) {
        $cf.on('input blur', function () {
            var bad = $cf.val() !== '' && $cf.val() !== $pw.val();
            $cf.toggleClass('error', bad);
        });
    }

    // Live email check on blur (shows the red message before submit).
    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    $('input[type="email"]').on('blur input', function (ev) {
        var $in = $(this), v = $.trim($in.val());
        if (ev.type === 'input' && !$in.hasClass('error')) return;
        var bad = v !== '' && !emailRe.test(v);
        $in.siblings('.form-error').remove();
        $in.toggleClass('error', bad);
        if (bad) $('<span class="form-error">Enter a valid email address.</span>').insertAfter($in);
    });

    // Loading label on submit (app.js already disables the button).
    $('form[data-once]').on('submit', function () {
        var $b = $(this).find('[type="submit"]').first();
        if ($b.length && !$b.data('label')) $b.data('label', $b.text()).attr('aria-busy', 'true');
    });
});
</script>
<?php if (HCAPTCHA_ENABLED): ?>
<script src="https://js.hcaptcha.com/1/api.js" async defer></script>
<?php endif; ?>

</body>
</html>