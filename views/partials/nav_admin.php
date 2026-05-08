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
$isSSO    = ($navRole === ROLE_SSO);

// Readiness checks for red-dot indicators
$_navDb = db();
$_navSY = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$_navExamReady = (int)$_navDb->query('SELECT COUNT(*) FROM exams WHERE is_active=1')->fetchColumn() > 0;
$_navSlotStmt  = $_navDb->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year=?');
$_navSlotStmt->execute([$_navSY]);
$_navExamReady = $_navExamReady && (int)$_navSlotStmt->fetchColumn() > 0;
$_navIntReady  = (int)$_navDb->query("SELECT COUNT(*) FROM interview_slots WHERE slot_date >= CURDATE()")->fetchColumn() > 0;

// School-year window check — red dot if any of the three window
// fields (open, close, document deadline) hasn't been filled in yet.
$_navAdmOpen         = school_setting('admissions_open', '');
$_navAdmClose        = school_setting('admissions_close', '');
$_navAdmDocDeadline  = school_setting('document_deadline', '');
$_navAdmNeedsSetup   = ($_navAdmOpen === '' || $_navAdmClose === '' || $_navAdmDocDeadline === '');

// Courses & Strands check — red dot if any active course is missing
// an explicit max_slots cap for the current school year. Custom
// inactive courses are excluded.
$_navCoursesNeedsSetup = false;
try {
    $_navCourseList = function_exists('get_all_courses')
        ? get_all_courses()
        : PLP_COURSES;
    $_navTotalCourses = count($_navCourseList);
    if ($_navTotalCourses > 0) {
        $_navCapStmt = $_navDb->prepare(
            'SELECT COUNT(DISTINCT course_name)
               FROM course_caps
              WHERE school_year = ?
                AND max_slots IS NOT NULL'
        );
        $_navCapStmt->execute([$_navSY]);
        $_navCappedCount = (int)$_navCapStmt->fetchColumn();
        $_navCoursesNeedsSetup = $_navCappedCount < $_navTotalCourses;
    }
} catch (\Throwable $e) {
    // course_caps table may not exist on older installs — silent.
}

// Pending-work indicators (amber dot, separate semantic from the
// red "needs setup" dot). Visible only to roles that can act on
// the queue.
$_navDocsPending = false;
$_navResPending  = false;

// Documents — at least one document row in 'uploaded' (= submitted
// by student, awaiting staff review). Admin / SSO only.
if ($navRole === ROLE_ADMIN || $navRole === ROLE_SSO) {
    try {
        $_navDocsPending = (int)$_navDb->query(
            "SELECT COUNT(*) FROM documents WHERE status='uploaded'"
        )->fetchColumn() > 0;
    } catch (\Throwable $e) { /* table missing — silent */ }
}

// Results — at least one applicant in the ready_accept / ready_reject
// bucket from the results-manager bucket logic: exam outcome decided
// (er.passed set) AND interview decided (iq.evaluation_result set OR
// exam outright failed) AND no admission_results row yet AND not
// withdrawn. Dean is scoped to their college's courses.
try {
    $_navResSql =
        "SELECT COUNT(*)
           FROM applicants a
      LEFT JOIN admission_results ar ON ar.applicant_id = a.id
      LEFT JOIN exam_results       er ON er.applicant_id = a.id
      LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
          WHERE ar.result IS NULL
            AND a.overall_status <> 'withdrawn'
            AND ( er.passed = 0
               OR iq.evaluation_result IN ('pass','fail') )";

    if ($isDean) {
        $_navDeanDept = function_exists('user_department')
            ? (string) user_department((int) Auth::id())
            : '';
        $_navDeanCourses = ($_navDeanDept !== '' && function_exists('courses_in_department'))
            ? courses_in_department($_navDeanDept)
            : [];
        if (!empty($_navDeanCourses)) {
            $_navInPh   = implode(',', array_fill(0, count($_navDeanCourses), '?'));
            $_navResStmt = $_navDb->prepare($_navResSql . " AND a.course_applied IN ($_navInPh)");
            $_navResStmt->execute($_navDeanCourses);
            $_navResPending = (int)$_navResStmt->fetchColumn() > 0;
        }
        // dean with no department / no courses → no dot
    } else {
        $_navResPending = (int)$_navDb->query($_navResSql)->fetchColumn() > 0;
    }
} catch (\Throwable $e) { /* tables missing — silent */ }

// Dean's "Interviews" link goes to the queue page (read-only), since
// /staff/interviews shows a setup-card landing that's geared toward
// Admin. SSO doesn't run interviews — they only set sessions up — so
// jump them straight to /staff/interviews/setup, skipping the two-card
// landing AND the live queue entirely. Admin keeps the landing.
if ($isDean)      { $intHref = '/staff/interviews/queue'; }
elseif ($isSSO)   { $intHref = '/staff/interviews/setup';  }
else              { $intHref = '/staff/interviews'; }

$items = [
    ['href' => '/admin/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',        'icon' => 'ic_fluent_home_24_regular',
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],

    ['href' => '/admin/school-year', 'key' => 'school-year', 'label' => 'School Year',      'icon' => 'ic_fluent_arrow_sync_24_regular', 'alert' => $_navAdmNeedsSetup,
        'roles' => [ROLE_ADMIN, ROLE_SSO]],
    ['href' => '/admin/courses',     'key' => 'courses',     'label' => 'Courses & Strands','icon' => 'ic_fluent_library_24_regular', 'alert' => $_navCoursesNeedsSetup,
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],

    ['href' => '/staff/applicants',  'key' => 'documents',   'label' => 'Documents',        'icon' => 'ic_fluent_document_24_regular',      'pending' => $_navDocsPending,
        'roles' => [ROLE_ADMIN, ROLE_SSO]],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exam',             'icon' => 'ic_fluent_edit_24_regular',          'alert' => !$_navExamReady,
        'roles' => [ROLE_ADMIN, ROLE_SSO]],
    ['href' => $intHref,             'key' => 'interviews',  'label' => 'Interviews',       'icon' => 'ic_fluent_calendar_ltr_24_regular',  'alert' => !$_navIntReady,
        'roles' => [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN]],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Results',          'icon' => 'ic_fluent_ribbon_star_24_regular',   'pending' => $_navResPending,
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
        <?php elseif (!empty($item['pending'])): ?>
            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#f59e0b;margin-left:auto;flex-shrink:0" title="Has pending work"></span>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
