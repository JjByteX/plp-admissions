<?php
// ============================================================
// modules/settings/admin_courses.php
// Admin: manage courses, strand mappings, and per-course tier
// thresholds (Pass / Average / High).
//
// All tier thresholds (built-in + custom courses) live here in
// course_passing_scores; the legacy custom_courses.pass_from
// column is no longer written to.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db       = db();
$errors   = [];
$success  = [];
$adminId  = Auth::id();
$role     = Auth::role();

// Admin and SSO can add/edit/delete courses, set enrollment caps, and
// edit tier thresholds school-wide. Dean is restricted to tier thresholds
// AND max-slots edits for the courses in their own college (oversight +
// standards-setting + their own college's intake size).
$canManageCourses = ($role === ROLE_ADMIN || $role === ROLE_SSO);
$isDean           = StaffScope::current()->restricted();
$scope            = StaffScope::current();
// Anyone here can change max-slots, but Dean's writes are scoped to their
// own college (enforced in the `update_course_caps` handler below).
$canEditMaxSlots  = $canManageCourses || $isDean;

// Every course belongs to exactly one college (course_departments row).
// Interview slots, exam slots and the Dean's view all follow that link, so a
// course without one is skipped by all of them.
$colleges    = [];
$collegeIds  = [];
foreach ($db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll() as $col) {
    $colleges[]   = ['id' => (int)$col['id'], 'name' => (string)$col['name']];
    $collegeIds[] = (int)$col['id'];
}
$collegeNames = array_column($colleges, 'name', 'id');

// ── POST handlers ─────────────────────────────────────────────────────────
if (($action = require_post_action()) !== null) {

    // Dean is read-only on everything except tier thresholds and max-slots.
    // Anything else is dropped up front so the if-blocks below short-circuit.
    $deanWritable = ['update_tiers', 'update_course_caps'];
    if (!$canManageCourses && !in_array($action, $deanWritable, true)) {
        $errors[] = 'Read-only access - only SSO and Admin can manage courses.';
        $action   = '';
    }

    // ── Add new custom course ──────────────────────────────────
    if ($action === 'add_course') {
        $name    = post_str('course_name');
        $strands = array_values(array_filter($_POST['strands'] ?? [], fn($s) => isset(SHS_STRANDS[$s])));
        $deptId  = post_int('department_id');

        if (!$name) {
            $errors[] = 'Course name is required.';
        } elseif (in_array($name, PLP_COURSES, true)) {
            $errors[] = "'{$name}' is already a built-in PLP course and cannot be re-added.";
        } elseif (!preg_match('/\([^()\s][^()]*\)\s*$/', $name)) {
            $errors[] = 'Write the course code in brackets at the end of the name, for example "BS Data Science (BSDS)". '
                      . 'Enrollment uses the same code, so add the program there first.';
        } elseif (!in_array($deptId, $collegeIds, true)) {
            $errors[] = 'Choose the college this course belongs to.';
        } else {
            $dup = $db->prepare('SELECT id FROM custom_courses WHERE course_name=?');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                $errors[] = "A custom course named '{$name}' already exists.";
            } else {
                try {
                    // Course, college link and default tiers are saved together
                    // so a course can never exist without its college.
                    $db->beginTransaction();

                    $insCourse = $db->prepare(
                        'INSERT INTO custom_courses (course_name, strands, is_active, created_by)
                         VALUES (?,?,1,?)
                         RETURNING id'
                    );
                    $insCourse->execute([$name, json_encode($strands), $adminId]);
                    $newId = (int)$insCourse->fetchColumn();

                    $db->prepare(
                        'INSERT INTO course_departments (course_name, department_id)
                         VALUES (?, ?)
                         ON CONFLICT (course_name) DO UPDATE SET department_id = EXCLUDED.department_id'
                    )->execute([$name, $deptId]);

                    // Auto-create a tier-thresholds row with sensible defaults so
                    // the new course shows up immediately in the tier table.
                    $db->prepare(
                        'INSERT INTO course_passing_scores
                            (course_name, pass_from, high_from, avg_from, confirmed)
                         VALUES (?, 4, 7, 4, 0)
                         ON CONFLICT DO NOTHING'
                    )->execute([$name]);

                    $db->commit();

                    audit_log(AuditAction::COURSE_ADDED, "Added custom course: {$name} ({$collegeNames[$deptId]})", 'custom_courses', $newId);
                    $success[] = "Course \"{$name}\" added to {$collegeNames[$deptId]}. Set its tier thresholds in the table above.";
                } catch (\Throwable $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('admin_courses add_course failed: ' . $ex->getMessage());
                    $errors[] = 'Could not add the course. Please try again.';
                }
            }
        }
    }

    // ── Edit existing custom course ────────────────────────────
    if ($action === 'edit_course') {
        $courseId = post_int('course_id');
        $name     = post_str('course_name');
        $strands  = array_values(array_filter($_POST['strands'] ?? [], fn($s) => isset(SHS_STRANDS[$s])));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $deptId   = post_int('department_id');

        if (!$courseId) {
            $errors[] = 'Invalid course.';
        } elseif (!$name) {
            $errors[] = 'Course name is required.';
        } elseif (in_array($name, PLP_COURSES, true)) {
            $errors[] = 'Cannot rename to a built-in PLP course name.';
        } elseif (!in_array($deptId, $collegeIds, true)) {
            $errors[] = 'Choose the college this course belongs to.';
        } else {
            // Capture old name so we can rename the tier row alongside it.
            $oldStmt = $db->prepare('SELECT course_name FROM custom_courses WHERE id=?');
            $oldStmt->execute([$courseId]);
            $oldName = $oldStmt->fetchColumn() ?: '';

            $dupStmt = $db->prepare('SELECT 1 FROM custom_courses WHERE course_name=? AND id<>?');
            $dupStmt->execute([$name, $courseId]);

            if (!$oldName) {
                $errors[] = 'Invalid course.';
            } elseif ($dupStmt->fetchColumn()) {
                $errors[] = "A custom course named '{$name}' already exists.";
            } else {
                try {
                    $db->beginTransaction();

                    $db->prepare(
                        'UPDATE custom_courses
                            SET course_name=?, strands=?, is_active=?
                          WHERE id=?'
                    )->execute([$name, json_encode($strands), $isActive, $courseId]);

                    if ($oldName !== $name) {
                        $db->prepare('UPDATE course_passing_scores SET course_name=? WHERE course_name=?')
                           ->execute([$name, $oldName]);
                        $db->prepare('DELETE FROM course_departments WHERE course_name=?')
                           ->execute([$oldName]);
                    }

                    // Also gives a college to older custom courses that never had one.
                    $db->prepare(
                        'INSERT INTO course_departments (course_name, department_id)
                         VALUES (?, ?)
                         ON CONFLICT (course_name) DO UPDATE SET department_id = EXCLUDED.department_id'
                    )->execute([$name, $deptId]);

                    $db->commit();

                    audit_log(AuditAction::COURSE_UPDATED, "Updated custom course ID {$courseId}: {$name} ({$collegeNames[$deptId]})", 'custom_courses', $courseId);
                    $success[] = 'Course updated.';
                } catch (\Throwable $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('admin_courses edit_course failed: ' . $ex->getMessage());
                    $errors[] = 'Could not update the course. Please try again.';
                }
            }
        }
    }

    // ── Delete custom course ───────────────────────────────────
    if ($action === 'delete_course') {
        $courseId = post_int('course_id');
        if (!$courseId) {
            $errors[] = 'Invalid course.';
        } else {
            $row = $db->prepare('SELECT course_name FROM custom_courses WHERE id=?');
            $row->execute([$courseId]);
            $cn = $row->fetchColumn() ?: '';
            $db->prepare('DELETE FROM custom_courses WHERE id=?')->execute([$courseId]);
            if ($cn) {
                $db->prepare('DELETE FROM course_passing_scores WHERE course_name=?')->execute([$cn]);
                $db->prepare('DELETE FROM course_departments WHERE course_name=?')->execute([$cn]);
            }
            audit_log(AuditAction::COURSE_DELETED, "Deleted custom course ID {$courseId}: {$cn}", 'custom_courses', $courseId);
            $success[] = 'Course deleted.';
        }
    }

    // ── Update per-course enrollment caps (per school year) ───
    if ($action === 'update_course_caps') {
        $schoolYear = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
        $caps       = $_POST['caps'] ?? [];
        $allCourses = function_exists('get_all_courses') ? get_all_courses() : PLP_COURSES;
        // Dean writes are restricted to courses in their own college only.
        // Admin / SSO can write any course.
        $writableCourses = $canManageCourses
            ? $allCourses
            : $scope->filterCourses($allCourses);
        $written    = 0;
        foreach ($caps as $courseName => $maxVal) {
            if (!in_array($courseName, $writableCourses, true)) continue;
            $max = ($maxVal === '' || $maxVal === null) ? null : max(0, (int)$maxVal);
            $db->prepare(
                'INSERT INTO course_caps (course_name, school_year, max_slots)
                 VALUES (?, ?, ?)
                 ON CONFLICT (course_name, school_year) DO UPDATE SET max_slots = EXCLUDED.max_slots'
            )->execute([$courseName, $schoolYear, $max]);
            $written++;
        }
        audit_log(AuditAction::COURSE_CAPS_UPDATED, "Updated enrollment caps for {$written} course(s) (AY {$schoolYear})");
        $success[] = "Enrollment caps saved for {$written} course(s).";
    }

    // ── Update tier thresholds (built-in + custom together) ────
    if ($action === 'update_tiers') {
        $scores    = $_POST['scores'] ?? [];
        $allCourses = function_exists('get_all_courses') ? get_all_courses() : PLP_COURSES;
        // Dean can only edit thresholds for courses in their own college.
        $writableCourses = $canManageCourses
            ? $allCourses
            : $scope->filterCourses($allCourses);
        $written   = 0;
        foreach ($scores as $courseName => $vals) {
            if (!in_array($courseName, $writableCourses, true)) continue;
            $highFrom = max(2, min(10, (int)($vals['high'] ?? 7)));
            $avgFrom  = max(1, min($highFrom - 1, (int)($vals['avg'] ?? 4)));
            $passFrom = $avgFrom; // Passing = Average tier and above
            $db->prepare(
                'INSERT INTO course_passing_scores
                    (course_name, pass_from, high_from, avg_from, confirmed)
                 VALUES (?, ?, ?, ?, 1)
                 ON CONFLICT (course_name) DO UPDATE SET
                    pass_from = EXCLUDED.pass_from,
                    high_from = EXCLUDED.high_from,
                    avg_from  = EXCLUDED.avg_from,
                    confirmed = 1'
            )->execute([$courseName, $passFrom, $highFrom, $avgFrom]);
            $written++;
        }
        audit_log(AuditAction::PASSING_SCORES_UPDATED, "Updated tier thresholds for {$written} course(s)");
        $success[] = "Tier thresholds saved for {$written} course(s).";
    }
}

// ── Load data ─────────────────────────────────────────────────────────────
$customCourses = $db->query(
    'SELECT cc.*, cd.department_id, d.name AS college_name
       FROM custom_courses cc
       LEFT JOIN course_departments cd ON cd.course_name = cc.course_name
       LEFT JOIN departments d ON d.id = cd.department_id
      ORDER BY cc.is_active DESC, cc.course_name ASC'
)->fetchAll();

$tierMap = [];
try {
    $rows = $db->query(
        'SELECT course_name, pass_from, high_from, avg_from, confirmed
           FROM course_passing_scores'
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $tierMap[$r['course_name']] = [
            'pass_from' => (int)$r['pass_from'],
            'high_from' => (int)$r['high_from'],
            'avg_from'  => (int)$r['avg_from'],
            'confirmed' => (int)$r['confirmed'],
        ];
    }
} catch (\Throwable $e) { /* table may not exist yet */ }

$allCoursesList = function_exists('get_all_courses') ? get_all_courses() : PLP_COURSES;

// Dean dept scope — only show courses that belong to their own college.
if (!$canManageCourses && $scope->restricted()) {
    $allCoursesList = $scope->filterCourses($allCoursesList);
    $customCourses  = array_values(array_filter(
        $customCourses,
        fn($cc) => $scope->canAccessCourse((string)$cc['course_name'])
    ));
}

// Course enrollment caps (per current school year)
$schoolYear     = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$capRows        = [];
$acceptedCounts = [];
try {
    $stmt = $db->prepare('SELECT course_name, max_slots FROM course_caps WHERE school_year=?');
    $stmt->execute([$schoolYear]);
    foreach ($stmt->fetchAll() as $row) $capRows[$row['course_name']] = $row['max_slots'];
} catch (\Throwable $e) { /* table may not exist yet */ }
try {
    $stmt = $db->prepare(
        "SELECT a.course_applied, COUNT(*) AS cnt
           FROM applicants a
           JOIN admission_results r ON r.applicant_id = a.id
          WHERE a.school_year = ? AND r.result = 'accepted'
          GROUP BY a.course_applied"
    );
    $stmt->execute([$schoolYear]);
    foreach ($stmt->fetchAll() as $row) $acceptedCounts[$row['course_applied']] = (int)$row['cnt'];
} catch (\Throwable $e) { /* table may not exist yet */ }

ob_start();
?>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<?php
// Strand pills for a table cell. Neutral badges, same as the other tables.
$strandPills = function (array $list): string {
    if (!$list) return '<span style="font-size:var(--text-xs);color:var(--text-tertiary)">-</span>';
    $out = '<div style="display:flex;gap:4px;flex-wrap:wrap;padding:var(--space-2) 0">';
    foreach ($list as $sk) $out .= '<span class="badge badge-neutral">' . e($sk) . '</span>';
    return $out . '</div>';
};
$customByName = array_column($customCourses, null, 'course_name');
?>
<div>

    <!-- ── Courses & Settings table ─────────────────────────────── -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--space-4);margin-bottom:var(--space-4)">
        <div>
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Courses & Settings</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                School year <strong><?= e($schoolYear) ?></strong>. Tiers use rank scores 1–10; passing = Average and above. Enrollment cap auto-disables a course when full; leave blank for unlimited.
            </div>
        </div>
    </div>

    <!-- Same card / toolbar / table as the other tables, but it scrolls inside
         instead of paginating: 10 rows to start, "Show more" at the bottom. -->
    <div class="auto-table-card auto-table-card--scroll" data-scroll-table data-limit="10" data-batch="10">

        <div class="auto-table-toolbar">
            <div class="auto-table-search">
                <?= icon('ic_fluent_search_24_filled', 14) ?>
                <input type="text" class="form-input" data-st-search autocomplete="off"
                       placeholder="Search course or strand…" aria-label="Search courses">
            </div>

            <select class="form-input" style="width:180px" data-st-filter="strand" aria-label="Strand">
                <option value="">All strands</option>
                <?php foreach (SHS_STRANDS as $sKey => $sLabel): ?>
                    <option value="<?= e($sKey) ?>"><?= e($sKey) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-input" style="width:180px" data-st-filter="status" aria-label="Status">
                <option value="">All statuses</option>
                <option value="full">Full</option>
                <option value="nocap">No cap set</option>
            </select>

            <button type="button" class="btn btn-ghost btn-sm" data-st-clear style="display:none">Clear</button>
        </div>

        <div class="auto-table-body">
            <table class="auto-table" style="min-width:1140px">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th style="width:240px">Strands</th>
                        <th class="col-center" style="width:90px">High</th>
                        <th class="col-center" style="width:110px">Average</th>
                        <th class="col-center" style="width:90px">Low</th>
                        <th class="col-center" style="width:110px">Accepted</th>
                        <th class="col-center" style="width:110px">Max Slots</th>
                        <th style="width:130px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allCoursesList as $ci => $course):
                    $pr        = $tierMap[$course] ?? null;
                    $highFrom  = $pr['high_from'] ?? 7;
                    $avgFrom   = $pr['avg_from']  ?? 4;
                    $maxSlots  = array_key_exists($course, $capRows) ? $capRows[$course] : null;
                    $accepted  = $acceptedCounts[$course] ?? 0;
                    $isFull    = $maxSlots !== null && $accepted >= $maxSlots;
                    $isBuiltIn = in_array($course, PLP_COURSES, true);
                    $strands   = $isBuiltIn
                        ? (COURSE_STRAND_MAP[$course] ?? [])
                        : (json_decode($customByName[$course]['strands'] ?? '[]', true) ?: []);
                    $enc       = htmlspecialchars($course, ENT_QUOTES);
                    $lowText   = '1–' . max(0, $avgFrom - 1);
                    $rowStatus = $isFull ? 'full' : ($maxSlots === null ? 'nocap' : 'open');
                    // Red dot when max-slots hasn't been set yet AND the current user
                    // may set it (nudges a Dean toward their college's intake cap).
                    $needsCap  = ($maxSlots === null) && $canEditMaxSlots;
                ?>
                    <tr id="course-row-<?= $ci ?>" data-st-row
                        data-search="<?= e(mb_strtolower($course . ' ' . implode(' ', $strands))) ?>"
                        data-strand="<?= e(implode('|', $strands)) ?>"
                        data-status="<?= $rowStatus ?>">
                        <td>
                            <span style="display:flex;align-items:center;gap:var(--space-2);min-width:0">
                                <span class="auto-table-clip" title="<?= e($course) ?>"><?= e($course) ?></span>
                                <?php if ($isFull): ?>
                                    <span class="badge badge-error" style="flex-shrink:0">Full</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="cell-wrap"><?= $strandPills($strands) ?></td>
                        <td class="col-center">
                            <span class="cell-view" style="color:var(--text-secondary)"><?= $highFrom ?>–10</span>
                            <input type="number" form="row-form-<?= $ci ?>" name="scores[<?= $enc ?>][high]"
                                   class="form-control tier-high cell-edit" data-row="<?= $ci ?>"
                                   value="<?= $highFrom ?>" min="2" max="10" aria-label="High from"
                                   style="font-size:var(--text-sm);padding:5px 8px;text-align:center;width:100%">
                        </td>
                        <td class="col-center">
                            <span class="cell-view" style="color:var(--text-secondary)"><?= $avgFrom ?>–<?= $highFrom - 1 ?></span>
                            <input type="number" form="row-form-<?= $ci ?>" name="scores[<?= $enc ?>][avg]"
                                   class="form-control tier-avg cell-edit" data-row="<?= $ci ?>"
                                   value="<?= $avgFrom ?>" min="1" max="9" aria-label="Average from"
                                   style="font-size:var(--text-sm);padding:5px 8px;text-align:center;width:100%">
                        </td>
                        <td class="col-center low-label" data-orig="<?= e($lowText) ?>" style="color:var(--text-secondary)"><?= e($lowText) ?></td>
                        <td class="col-center"><strong><?= (int)$accepted ?></strong></td>
                        <?php if ($canEditMaxSlots): ?>
                        <td class="col-center" style="position:relative;color:var(--text-tertiary)">
                            <span class="cell-view">
                                <?php if ($needsCap): ?>
                                    <span title="Max slots not set" style="position:absolute;top:8px;right:10px;
                                                 width:8px;height:8px;border-radius:50%;background:var(--error)"></span>
                                <?php endif; ?>
                                <?= $maxSlots !== null ? (int)$maxSlots : '∞' ?>
                            </span>
                            <input type="number" form="row-form-<?= $ci ?>" name="caps[<?= $enc ?>]"
                                   class="form-control cell-edit" min="0" max="99999"
                                   value="<?= $maxSlots !== null ? (int)$maxSlots : '' ?>" placeholder="∞"
                                   aria-label="Max slots"
                                   style="font-size:var(--text-sm);padding:5px 8px;text-align:center;width:100%">
                        </td>
                        <?php else: ?>
                        <td class="col-center" style="color:var(--text-tertiary)"><?= $maxSlots !== null ? (int)$maxSlots : '∞' ?></td>
                        <?php endif; ?>
                        <td class="col-center">
                            <button type="button" class="btn-icon cell-view" title="Edit"
                                    onclick="startRowEdit(<?= $ci ?>)" style="color:var(--text-tertiary)">
                                <?= icon('ic_fluent_edit_24_regular', 14) ?>
                            </button>
                            <form method="POST" id="row-form-<?= $ci ?>" class="row-edit-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_tiers">
                                <input type="hidden" name="scores[<?= $enc ?>][course]" value="<?= $enc ?>">
                                <button type="button" class="btn btn-primary btn-sm" onclick="saveRowEdit(<?= $ci ?>)">Save</button>
                                <button type="button" class="btn-icon" title="Cancel"
                                        onclick="cancelRowEdit(<?= $ci ?>)" style="color:var(--text-tertiary)">
                                    <?= icon('ic_fluent_dismiss_24_regular', 14) ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$allCoursesList): ?>
            <div class="auto-table-empty">
                <?= icon('ic_fluent_document_24_regular', 32) ?>
                <div>No courses to show.</div>
            </div>
            <?php endif; ?>
            <div class="auto-table-empty" data-st-empty hidden>
                <?= icon('ic_fluent_document_24_regular', 32) ?>
                <div>No courses match your filters.</div>
            </div>
            <div class="auto-table-more" data-st-more hidden>
                <span data-st-count></span>
                <button type="button" class="btn btn-secondary btn-sm" data-st-more-btn>Show more</button>
            </div>
        </div>
    </div>

    <?php if ($canManageCourses): /* Dean is scoped to their own college's
              tier thresholds + max-slots only - Custom Courses and the SHS
              Strand reference are out of scope for that role. */ ?>

    <!-- ── Custom Courses table ─────────────────────────────────── -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--space-4);margin-bottom:var(--space-4)">
        <div>
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Custom Courses</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                Additional programs you've added. They appear in dropdowns and strand-match suggestions.
            </div>
        </div>
        <?= ui_button('Add Course', ['variant' => 'primary', 'size' => 'sm', 'icon' => 'ic_fluent_add_24_regular', 'attrs' => ['style' => 'flex-shrink:0', 'onclick' => "document.getElementById('add-course-modal').style.display='flex'"]]) ?>
    </div>

    <div class="auto-table-card auto-table-card--scroll" data-scroll-table data-limit="10" data-batch="10">

        <div class="auto-table-toolbar">
            <div class="auto-table-search">
                <?= icon('ic_fluent_search_24_filled', 14) ?>
                <input type="text" class="form-input" data-st-search autocomplete="off"
                       placeholder="Search custom courses…" aria-label="Search custom courses">
            </div>

            <select class="form-input" style="width:180px" data-st-filter="status" aria-label="Status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>

            <button type="button" class="btn btn-ghost btn-sm" data-st-clear style="display:none">Clear</button>
        </div>

        <div class="auto-table-body">
            <table class="auto-table" style="min-width:960px">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th style="width:240px">College</th>
                        <th style="width:260px">Strands</th>
                        <th style="width:110px">Status</th>
                        <th class="col-center" style="width:110px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customCourses as $cc):
                    $ccStrands = json_decode($cc['strands'] ?? '[]', true) ?: [];
                ?>
                    <tr data-st-row
                        data-search="<?= e(mb_strtolower($cc['course_name'] . ' ' . ($cc['college_name'] ?? '') . ' ' . implode(' ', $ccStrands))) ?>"
                        data-strand="<?= e(implode('|', $ccStrands)) ?>"
                        data-status="<?= $cc['is_active'] ? 'active' : 'inactive' ?>">
                        <td style="font-weight:var(--weight-medium)">
                            <span class="auto-table-clip" title="<?= e($cc['course_name']) ?>"><?= e($cc['course_name']) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($cc['college_name'])): ?>
                                <span class="auto-table-clip" title="<?= e($cc['college_name']) ?>"><?= e($cc['college_name']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-error" title="Edit this course and choose a college">No college</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-wrap">
                            <?php if ($ccStrands): ?>
                                <?= $strandPills($ccStrands) ?>
                            <?php else: ?>
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">No strands set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cc['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-center">
                            <div style="display:flex;gap:var(--space-2);align-items:center;justify-content:center">
                                <button type="button" class="btn-icon" title="Edit"
                                        onclick="openEditModal(<?= (int)$cc['id'] ?>, <?= e(json_encode($cc['course_name'])) ?>, <?= e(json_encode($ccStrands)) ?>, <?= (int)$cc['is_active'] ?>, <?= (int)($cc['department_id'] ?? 0) ?>)"
                                        style="color:var(--text-tertiary)">
                                    <?= icon('ic_fluent_edit_24_regular', 14) ?>
                                </button>
                                <form method="POST" style="display:inline"
                                      data-confirm="Delete this course? This cannot be undone." data-confirm-danger data-confirm-ok="Delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_course">
                                    <input type="hidden" name="course_id" value="<?= (int)$cc['id'] ?>">
                                    <button type="submit" class="btn-icon" title="Delete" style="color:var(--error)">
                                        <?= icon('ic_fluent_delete_24_regular', 14) ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$customCourses): ?>
            <div class="auto-table-empty">
                <?= icon('ic_fluent_document_24_regular', 32) ?>
                <div>No custom courses yet. Click <strong>Add Course</strong> to add a new program.</div>
            </div>
            <?php endif; ?>
            <div class="auto-table-empty" data-st-empty hidden>
                <?= icon('ic_fluent_document_24_regular', 32) ?>
                <div>No custom courses match your filters.</div>
            </div>
            <div class="auto-table-more" data-st-more hidden>
                <span data-st-count></span>
                <button type="button" class="btn btn-secondary btn-sm" data-st-more-btn>Show more</button>
            </div>
        </div>
    </div>

    <!-- ── SHS Strand Reference table ───────────────────────────── -->
    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base);margin-bottom:var(--space-4)">SHS Strand Reference</div>

    <div class="auto-table-card auto-table-card--scroll" data-scroll-table data-limit="10" data-batch="10">

        <div class="auto-table-toolbar">
            <div class="auto-table-search">
                <?= icon('ic_fluent_search_24_filled', 14) ?>
                <input type="text" class="form-input" data-st-search autocomplete="off"
                       placeholder="Search strands…" aria-label="Search strands">
            </div>

            <button type="button" class="btn btn-ghost btn-sm" data-st-clear style="display:none">Clear</button>
        </div>

        <div class="auto-table-body">
            <table class="auto-table">
                <thead>
                    <tr>
                        <th style="width:160px">Strand</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (SHS_STRANDS as $key => $label): ?>
                    <tr data-st-row data-search="<?= e(mb_strtolower($key . ' ' . $label)) ?>">
                        <td style="font-weight:var(--weight-semibold)"><?= e($key) ?></td>
                        <td style="color:var(--text-secondary)"><span class="auto-table-clip" title="<?= e($label) ?>"><?= e($label) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="auto-table-empty" data-st-empty hidden>
                <?= icon('ic_fluent_document_24_regular', 32) ?>
                <div>No strands match your search.</div>
            </div>
            <div class="auto-table-more" data-st-more hidden>
                <span data-st-count></span>
                <button type="button" class="btn btn-secondary btn-sm" data-st-more-btn>Show more</button>
            </div>
        </div>
    </div>

    <?php endif; /* /Custom Courses + SHS Strand Reference for Admin/SSO only */ ?>
</div>

<!-- ── Add Course Modal ───────────────────────────────────────────────── -->
<div id="add-course-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
            align-items:center;justify-content:center;padding:var(--space-4)">
    <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:var(--space-6);
                max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg)">Add Custom Course</div>
            <button type="button" class="btn-icon" onclick="document.getElementById('add-course-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_course">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label" for="add-course-name">Course / Program Name <span style="color:var(--error)">*</span></label>
                    <input type="text" id="add-course-name" name="course_name" class="form-control"
                           placeholder="e.g. BS Data Science (BSDS)" required>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Use the full official name with the course code in brackets at the end. The same code must exist in Enrollment.
                    </p>
                </div>
                <div>
                    <label class="form-label" for="add-course-college">College <span style="color:var(--error)">*</span></label>
                    <select id="add-course-college" name="department_id" class="form-control" required>
                        <option value="">Select a college</option>
                        <?php foreach ($colleges as $col): ?>
                            <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Exam and interview slots and the Dean's view follow the college.
                    </p>
                </div>
                <div>
                    <span class="form-label">Accepted SHS Strands</span>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-2)">
                        Check all strands whose graduates may apply for this course.
                    </p>
                    <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-2);--bs-gutter-y:var(--space-2)">
                    <?php foreach (SHS_STRANDS as $sKey => $sLabel): ?>
                        <label style="display:flex;align-items:flex-start;gap:var(--space-2);cursor:pointer">
                            <input type="checkbox" name="strands[]" value="<?= e($sKey) ?>"
                                   style="accent-color:var(--accent);margin-top:2px">
                            <span style="font-size:var(--text-xs)">
                                <strong><?= e($sKey) ?></strong><br>
                                <span style="color:var(--text-tertiary)"><?= e($sLabel) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>
                <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin:0">
                    After adding, set this course's tier thresholds in the <strong>Tier Thresholds</strong> table above. Defaults: Pass = Avg 4, High = 7.
                </p>
                <div style="display:flex;gap:var(--space-2);justify-content:flex-end;padding-top:var(--space-2)">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('add-course-modal').style.display='none'">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">Add Course</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Course Modal ──────────────────────────────────────────────── -->
<div id="edit-course-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
            align-items:center;justify-content:center;padding:var(--space-4)">
    <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);padding:var(--space-6);
                max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg)">Edit Course</div>
            <button type="button" class="btn-icon" onclick="document.getElementById('edit-course-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" id="edit-course-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_course">
            <input type="hidden" name="course_id" id="edit-course-id">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label" for="edit-course-name">Course / Program Name <span style="color:var(--error)">*</span></label>
                    <input type="text" name="course_name" id="edit-course-name" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="edit-course-college">College <span style="color:var(--error)">*</span></label>
                    <select name="department_id" id="edit-course-college" class="form-control" required>
                        <option value="">Select a college</option>
                        <?php foreach ($colleges as $col): ?>
                            <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <span class="form-label">Accepted SHS Strands</span>
                    <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-2);--bs-gutter-y:var(--space-2)" id="edit-strand-grid">
                    <?php foreach (SHS_STRANDS as $sKey => $sLabel): ?>
                        <label style="display:flex;align-items:flex-start;gap:var(--space-2);cursor:pointer">
                            <input type="checkbox" name="strands[]"
                                   class="edit-strand-cb" value="<?= e($sKey) ?>"
                                   style="accent-color:var(--accent);margin-top:2px">
                            <span style="font-size:var(--text-xs)">
                                <strong><?= e($sKey) ?></strong><br>
                                <span style="color:var(--text-tertiary)"><?= e($sLabel) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
                        <input type="checkbox" name="is_active" id="edit-is-active" style="accent-color:var(--accent)">
                        <span style="font-size:var(--text-sm)">Active (visible to the system)</span>
                    </label>
                </div>
                <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin:0">
                    Tier thresholds are managed in the <strong>Tier Thresholds</strong> table on this page.
                </p>
                <div style="display:flex;gap:var(--space-2);justify-content:flex-end;padding-top:var(--space-2)">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('edit-course-modal').style.display='none'">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Courses & Strands';
$activeNav = 'courses';
$pageWide  = true;
$pageStyles  = ['css/pages/admin-courses.css'];
$pageScripts = ['js/pages/admin-courses.js'];
include VIEWS_PATH . '/layouts/app.php';
