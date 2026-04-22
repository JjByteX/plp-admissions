<?php
// ============================================================
// modules/auth/keepalive.php
// POST /auth/keepalive — refreshes session activity timestamp
// Returns JSON with updated seconds_remaining
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireLogin();
csrf_check();

// Touch _last_activity — enforceTimeout already ran in Session::start()
// so just update the timestamp directly
$_SESSION['_last_activity'] = time();

header('Content-Type: application/json');
echo json_encode([
    'ok'                => true,
    'seconds_remaining' => Session::secondsRemaining(),
]);