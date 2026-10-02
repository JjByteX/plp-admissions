<?php
// ============================================================
// index.php (project root)
// Sends anyone who opens the project folder straight to the app
// in /public/, so you don't have to type "/public" yourself.
// Works for `php -S localhost:8000` and XAMPP subfolder installs.
// ============================================================

$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/\\');
header('Location: ' . $base . '/public/');
exit;
