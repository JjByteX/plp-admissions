<?php
// ============================================================
// modules/results/staff_auto_waitlist.php
// DEPRECATED — Waitlist tier was retired in the role redesign.
// Results are now Accept-only or Reject-only; the auto-waitlist
// button has been removed from the Results page UI.
// This stub remains so any cached form posts to the old route
// don't 404 — they just redirect with a friendly notice.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);
csrf_check();

Session::flash('info',
    'Auto-Waitlist has been removed. Results are now Accept or Decline only — '
    . 'use Auto-Release Results instead.');
redirect('/staff/results');
