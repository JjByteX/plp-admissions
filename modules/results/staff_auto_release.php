<?php
// ============================================================
// modules/results/staff_auto_release.php
// Auto-release results based on score thresholds
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$counts = auto_release_results();
$total = array_sum($counts);

if ($total > 0) {
    Session::flash('success',
        "Auto-released {$total} result(s): {$counts['accepted']} accepted, "
        . "{$counts['waitlisted']} waitlisted, {$counts['rejected']} rejected.");
} else {
    Session::flash('info', 'No applicants eligible for auto-release at this time.');
}

redirect('/staff/results');
