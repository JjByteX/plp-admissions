<?php
// views/partials/nav_admin.php
// Management sidebar — shared by Admin / SSO / Dean.
//
// Flat list, no section labels — with 4–9 items per role, headings
// are pure overhead. Items are ordered by when each role actually
// uses them in the admissions cycle:
//
//   Dashboard           (overview — always first)
//   School Year         (set up the cycle)
//   Courses & Strands   (set up programs + tier thresholds)
//   Documents           (intake)
//   Exam                (assess: written)
//   Interviews          (assess: oral)
//   Results             (decide + release)
//   Users               (admin oversight)
//   Audit Log           (admin oversight)
//
// Each item declares which roles can see it via the `roles` key,
// so nobody sees a link that 403s when clicked.

$nav      = $activeNav ?? '';
$navRole  = Auth::role();
$isDean   = ($navRole === ROLE_DEAN);

// Readiness checks for red-dot indicators
$_navDb = db();
$_navSY = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$_navExamReady = (int)$_navDb->query('SELECT COUNT(*) FROM exams WHERE is_active=1')->fetchColumn() > 0;
$_navSlotStmt  = $_navDb->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year=?');
$_navSlotStmt->execute([$_navSY]);
$_navExamReady = $_navExamReady && (int)$_navSlotStmt->fetchColumn() > 0;
$_navIntReady  = (int)$_navDb->query("SELECT COUNT(*) FROM interview_slots WHERE slot_date >= CURDATE()")->fetchColumn() > 0;

// School-year window check — red dot if not configured yet
$_navAdmOpen      = school_setting('admissions_open', '');
$_navAdmClose     = school_setting('admissions_close', '');
$_navAdmNeedsSetup = ($_navAdmOpen === '' || $_navAdmClose === '');

// Dean's "Interviews" link goes to the queue page (read-only), since
// /staff/interviews shows a setup-card landing that's geared toward
// SSO/Admin. Admin/SSO keep the landing page so they can reach Setup.
$intHref = $isDean ? '/staff/interviews/queue' : '/staff/interviews';

$items = [
    ['href' => '/admin/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',        'icon' => 'ic_fluent_home_24_regular',
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],

    ['href' => '/admin/school-year', 'key' => 'school-year', 'label' => 'School Year',      'icon' => 'ic_fluent_arrow_sync_24_regular', 'alert' => $_navAdmNeedsSetup,
        'roles' => [ROLE_ADMIN, ROLE_SSO]],
    ['href' => '/admin/courses',     'key' => 'courses',     'label' => 'Courses & Strands','icon' => 'ic_fluent_library_24_regular',
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],

    ['href' => '/staff/applicants',  'key' => 'documents',   'label' => 'Documents',        'icon' => 'ic_fluent_document_24_regular',
        'roles' => [ROLE_ADMIN, ROLE_SSO]],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exam',             'icon' => 'ic_fluent_edit_24_regular',          'alert' => !$_navExamReady,
        'roles' => [ROLE_ADMIN, ROLE_SSO]],
    ['href' => $intHref,             'key' => 'interviews',  'label' => 'Interviews',       'icon' => 'ic_fluent_calendar_ltr_24_regular',  'alert' => !$_navIntReady,
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Results',          'icon' => 'ic_fluent_ribbon_star_24_regular',
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],

    ['href' => '/admin/users',       'key' => 'users',       'label' => 'Users',            'icon' => 'ic_fluent_shield_24_regular',
        'roles' => [ROLE_ADMIN]],
    ['href' => '/admin/audit-log',   'key' => 'audit-log',   'label' => 'Audit Log',        'icon' => 'ic_fluent_eye_show_24_regular',
        'roles' => [ROLE_ADMIN]],
];

$visible = array_values(array_filter($items, fn($i) => in_array($navRole, $i['roles'], true)));
?>
<?php foreach ($visible as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg'; ?>
        <?= e($item['label']) ?>
        <?php if (!empty($item['alert'])): ?>
            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--error);margin-left:auto;flex-shrink:0" title="Needs setup"></span>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
