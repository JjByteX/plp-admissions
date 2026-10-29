<?php
// modules/auth/logout.php
require_once CORE_PATH . '/bootstrap.php';
audit_log(AuditAction::LOGOUT, 'User logged out');
Auth::logout();
