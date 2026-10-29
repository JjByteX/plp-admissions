<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>403 - Access Denied</title>
<link rel="stylesheet" href="<?= e(asset('css/error-page.css')) ?>">
</head>
<body class="err-403">
<div class="wrap">
    <h1>403</h1>
    <p>You don't have permission to access this page.</p>
    <a href="<?= url('/') ?>">Go home</a>
</div>
</body>
</html>
