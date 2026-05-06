<?php
// ============================================================
// modules/interview/staff_desks.php
// Redirect to new Interview Setup flow
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

redirect('/staff/interviews/setup');
