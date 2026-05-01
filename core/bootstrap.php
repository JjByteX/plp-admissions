<?php
// ============================================================
// core/bootstrap.php
// Loaded first on every request via public/index.php
// ============================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/interview_scheduler.php';

// -- Error display -----------------------------------------------
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// -- Session -----------------------------------------------------
Session::start();
