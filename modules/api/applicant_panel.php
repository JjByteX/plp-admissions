<?php
// ============================================================
// modules/api/applicant_panel.php
//
// Returns rendered HTML for the applicant detail panel.
// Used by the slide-in drawer (see views/partials/applicant_drawer.php).
//
//   GET /api/applicant-panel?id=123
//
// Auth: any staff-side role (STAFF, SSO, DEAN, ADMIN).
// Output: HTML fragment (text/html). NOT a full page.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$applicantId = (int) ($_GET['id'] ?? 0);
if ($applicantId <= 0) {
    http_response_code(400);
    echo '<div class="ap-drawer-error">Missing or invalid applicant id.</div>';
    return;
}

$panelStandalone = true;
include VIEWS_PATH . '/partials/applicant_panel.php';
