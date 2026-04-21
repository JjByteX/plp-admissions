<?php
// modules/auth/logout.php
require_once CORE_PATH . '/bootstrap.php';
audit_log('logout', 'User logged out');
Auth::logout();
