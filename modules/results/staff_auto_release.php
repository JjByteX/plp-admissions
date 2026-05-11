<?php
// ============================================================
// modules/results/staff_auto_release.php
// Auto-release results: walks every applicant in Ready: Accept and
// Ready: Reject and releases them in bulk. Applicants whose
// interview hasn't been evaluated yet are skipped.
//
// Backed by core/automation.php :: auto_release_results().
// Waitlist tier was retired with the role redesign — only accepted
// and rejected outcomes are produced now.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);
csrf_check();

$counts = auto_release_results();
$total  = (int)($counts['accepted'] ?? 0) + (int)($counts['rejected'] ?? 0);

if ($total > 0) {
    Session::flash('success',
        "Auto-released {$total} result(s): "
        . (int)$counts['accepted'] . ' accepted, '
        . (int)$counts['rejected'] . ' rejected.');
} else {
    Session::flash('info', 'No applicants eligible for auto-release at this time.');
}

redirect('/staff/results');
