<?php
// ============================================================
// views/layouts/auth.php
// Clean centered layout for login / register / forgot-password
// ============================================================
$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$pageTitle   = $pageTitle ?? 'Welcome';
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

<!-- Theme toggle — fixed position -->
<button
    class="theme-toggle"
    onclick="Theme.toggle()"
    aria-label="Toggle theme"
    style="position:fixed;top:var(--space-4);right:var(--space-4);z-index:99"
>
    <svg data-theme-icon="dark" class="hidden" width="16" height="16" fill="none" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/>
        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
    </svg>
    <svg data-theme-icon="light" width="16" height="16" fill="none" viewBox="0 0 24 24">
        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
</button>

<script src="<?= asset('js/app.js') ?>"></script>
<script>setAccentColor('<?= e($accentColor) ?>');</script>

</body>
</html>
