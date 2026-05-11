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
$isDean           = ($role === ROLE_DEAN);
$scopedDept       = $isDean ? (string) user_department($adminId) : '';
$scopedCourses    = ($scopedDept !== '') ? courses_in_department($scopedDept) : [];
// Anyone here can change max-slots, but Dean's writes are scoped to their
// own college (enforced in the `update_course_caps` handler below).
$canEditMaxSlots  = $canManageCourses || $isDean;

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Dean is read-only on everything except tier thresholds and max-slots.
    // Anything else is dropped up front so the if-blocks below short-circuit.
    $deanWritable = ['update_tiers', 'update_course_caps'];
    if (!$canManageCourses && !in_array($action, $deanWritable, true)) {
        $errors[] = 'Read-only access — only SSO and Admin can manage courses.';
        $action   = '';
    }

    // ── Add new custom course ──────────────────────────────────
    if ($action === 'add_course') {
        $name    = trim($_POST['course_name'] ?? '');
        $strands = array_values(array_filter($_POST['strands'] ?? [], fn($s) => isset(SHS_STRANDS[$s])));

        if (!$name) {
            $errors[] = 'Course name is required.';
        } elseif (in_array($name, PLP_COURSES, true)) {
            $errors[] = "'{$name}' is already a built-in PLP course and cannot be re-added.";
        } else {
            $dup = $db->prepare('SELECT id FROM custom_courses WHERE course_name=?');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                $errors[] = "A custom course named '{$name}' already exists.";
            } else {
                $db->prepare(
                    'INSERT INTO custom_courses (course_name, strands, is_active, created_by)
                     VALUES (?,?,1,?)'
                )->execute([$name, json_encode($strands), $adminId]);
                $newId = (int)$db->lastInsertId();

                // Auto-create a tier-thresholds row with sensible defaults so
                // the new course shows up immediately in the tier table.
                $db->prepare(
                    'INSERT IGNORE INTO course_passing_scores
                        (course_name, pass_from, high_from, avg_from, confirmed)
                     VALUES (?, 4, 7, 4, 0)'
                )->execute([$name]);

                audit_log('course_added', "Added custom course: {$name}", 'custom_courses', $newId);
                $success[] = "Course \"{$name}\" added. Set its tier thresholds in the table above.";
            }
        }
    }

    // ── Edit existing custom course ────────────────────────────
    if ($action === 'edit_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $name     = trim($_POST['course_name'] ?? '');
        $strands  = array_values(array_filter($_POST['strands'] ?? [], fn($s) => isset(SHS_STRANDS[$s])));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!$courseId) {
            $errors[] = 'Invalid course.';
        } elseif (!$name) {
            $errors[] = 'Course name is required.';
        } elseif (in_array($name, PLP_COURSES, true)) {
            $errors[] = 'Cannot rename to a built-in PLP course name.';
        } else {
            // Capture old name so we can rename the tier row alongside it.
            $oldStmt = $db->prepare('SELECT course_name FROM custom_courses WHERE id=?');
            $oldStmt->execute([$courseId]);
            $oldName = $oldStmt->fetchColumn() ?: '';

            $db->prepare(
                'UPDATE custom_courses
                    SET course_name=?, strands=?, is_active=?
                  WHERE id=?'
            )->execute([$name, json_encode($strands), $isActive, $courseId]);

            if ($oldName && $oldName !== $name) {
                $db->prepare('UPDATE course_passing_scores SET course_name=? WHERE course_name=?')
                   ->execute([$name, $oldName]);
            }

            audit_log('course_updated', "Updated custom course ID {$courseId}: {$name}", 'custom_courses', $courseId);
            $success[] = 'Course updated.';
        }
    }

    // ── Delete custom course ───────────────────────────────────
    if ($action === 'delete_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if (!$courseId) {
            $errors[] = 'Invalid course.';
        } else {
            $row = $db->prepare('SELECT course_name FROM custom_courses WHERE id=?');
            $row->execute([$courseId]);
            $cn = $row->fetchColumn() ?: '';
            $db->prepare('DELETE FROM custom_courses WHERE id=?')->execute([$courseId]);
            if ($cn) {
                $db->prepare('DELETE FROM course_passing_scores WHERE course_name=?')->execute([$cn]);
            }
            audit_log('course_deleted', "Deleted custom course ID {$courseId}: {$cn}", 'custom_courses', $courseId);
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
            : array_values(array_intersect($allCourses, $scopedCourses));
        $written    = 0;
        foreach ($caps as $courseName => $maxVal) {
            if (!in_array($courseName, $writableCourses, true)) continue;
            $max = ($maxVal === '' || $maxVal === null) ? null : max(0, (int)$maxVal);
            $db->prepare(
                'INSERT INTO course_caps (course_name, school_year, max_slots)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE max_slots = VALUES(max_slots)'
            )->execute([$courseName, $schoolYear, $max]);
            $written++;
        }
        audit_log('course_caps_updated', "Updated enrollment caps for {$written} course(s) (AY {$schoolYear})");
        $success[] = "Enrollment caps saved for {$written} course(s).";
    }

    // ── Update tier thresholds (built-in + custom together) ────
    if ($action === 'update_tiers') {
        $scores    = $_POST['scores'] ?? [];
        $allCourses = function_exists('get_all_courses') ? get_all_courses() : PLP_COURSES;
        // Dean can only edit thresholds for courses in their own college.
        $writableCourses = $canManageCourses
            ? $allCourses
            : array_values(array_intersect($allCourses, $scopedCourses));
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
                 ON DUPLICATE KEY UPDATE
                    pass_from = VALUES(pass_from),
                    high_from = VALUES(high_from),
                    avg_from  = VALUES(avg_from),
                    confirmed = 1'
            )->execute([$courseName, $passFrom, $highFrom, $avgFrom]);
            $written++;
        }
        audit_log('passing_scores_updated', "Updated tier thresholds for {$written} course(s)");
        $success[] = "Tier thresholds saved for {$written} course(s).";
    }
}

// ── Load data ─────────────────────────────────────────────────────────────
$customCourses = $db->query(
    'SELECT * FROM custom_courses ORDER BY is_active DESC, course_name ASC'
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
if (!$canManageCourses && $scopedDept !== '') {
    $deanSet        = array_flip($scopedCourses);
    $allCoursesList = array_values(array_filter($allCoursesList, fn($c) => isset($deanSet[$c])));
    $customCourses  = array_values(array_filter(
        $customCourses,
        fn($cc) => isset($deanSet[$cc['course_name']])
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

<div>

    <!-- ── Unified Courses Table ────────────────────────────────── -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--space-4);margin-bottom:var(--space-4)">
        <div>
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Courses & Settings</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                School year <strong><?= e($schoolYear) ?></strong>. Tiers use rank scores 1–10; passing = Average and above. Enrollment cap auto-disables a course when full; leave blank for unlimited.
            </div>
        </div>
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;font-size:var(--text-sm);margin-bottom:var(--space-6);background:var(--bg-elevated);width:100%">
        <?php
        $hStyle = 'padding:var(--space-3) var(--space-4);background:var(--bg-subtle);font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.04em;border-bottom:1px solid var(--border)';
        $hStyleC = $hStyle . ';text-align:center';
        ?>
        <div style="display:grid;grid-template-columns:minmax(280px,1.6fr) minmax(160px,0.9fr) 90px 110px 90px 110px 110px 60px">
            <div style="<?= $hStyle ?>">Course</div>
            <div style="<?= $hStyle ?>">Strands</div>
            <div style="<?= $hStyleC ?>">High</div>
            <div style="<?= $hStyleC ?>">Average</div>
            <div style="<?= $hStyleC ?>">Low</div>
            <div style="<?= $hStyleC ?>">Accepted</div>
            <div style="<?= $hStyleC ?>">Max Slots</div>
            <div style="<?= $hStyleC ?>"></div>
        </div>

        <?php foreach ($allCoursesList as $ci => $course):
            $pr       = $tierMap[$course] ?? null;
            $highFrom = $pr['high_from'] ?? 7;
            $avgFrom  = $pr['avg_from']  ?? 4;
            $maxSlots = array_key_exists($course, $capRows) ? $capRows[$course] : null;
            $accepted = $acceptedCounts[$course] ?? 0;
            $isFull   = $maxSlots !== null && $accepted >= $maxSlots;
            $isBuiltIn = in_array($course, PLP_COURSES, true);
            $strands  = $isBuiltIn
                ? (COURSE_STRAND_MAP[$course] ?? [])
                : (json_decode($customCourses[array_search($course, array_column($customCourses, 'course_name'))]['strands'] ?? '[]', true) ?: []);
            $isLast   = $ci === count($allCoursesList) - 1;
            $bb       = !$isLast ? 'border-bottom:1px solid var(--border)' : '';
            $enc      = htmlspecialchars($course, ENT_QUOTES);
        ?>
        <!-- Read-only row -->
        <div id="row-read-<?= $ci ?>" class="course-row" style="display:grid;grid-template-columns:minmax(280px,1.6fr) minmax(160px,0.9fr) 90px 110px 90px 110px 110px 60px;<?= $bb ?>;transition:background-color var(--transition-fast)">
            <div style="padding:var(--space-3) var(--space-4)">
                <?= e($course) ?>
                <?php if ($isFull): ?>
                    <span style="font-size:10px;padding:1px 6px;background:#fee2e2;color:#b91c1c;border-radius:9999px;margin-left:4px">Full</span>
                <?php endif; ?>
            </div>
            <div style="padding:var(--space-3) var(--space-4)">
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <?php if ($strands): foreach ($strands as $s): ?>
                        <span style="font-size:11px;padding:2px 8px;background:var(--bg-subtle);border-radius:9999px;color:var(--text-secondary);white-space:nowrap"><?= e($s) ?></span>
                    <?php endforeach; else: ?>
                        <span style="font-size:var(--text-xs);color:var(--text-tertiary)">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="padding:var(--space-3) var(--space-4);text-align:center;color:var(--text-secondary)"><?= $highFrom ?>–10</div>
            <div style="padding:var(--space-3) var(--space-4);text-align:center;color:var(--text-secondary)"><?= $avgFrom ?>–<?= $highFrom - 1 ?></div>
            <div style="padding:var(--space-3) var(--space-4);text-align:center;color:var(--text-secondary)">1–<?= max(0, $avgFrom - 1) ?></div>
            <div style="padding:var(--space-3) var(--space-4);text-align:center"><strong><?= $accepted ?></strong></div>
            <div style="padding:var(--space-3) var(--space-4);text-align:center;color:var(--text-tertiary);position:relative">
                <?php
                    // Red dot when this row's max-slots hasn't been set yet AND
                    // the current user is allowed to set it. Visual nudge so the
                    // Dean knows which of their courses still needs an intake cap.
                    $needsCap = ($maxSlots === null) && $canEditMaxSlots;
                ?>
                <?php if ($needsCap): ?>
                    <span title="Max slots not set" style="position:absolute;top:8px;right:10px;
                                 width:8px;height:8px;border-radius:50%;background:var(--error)"></span>
                <?php endif; ?>
                <?= $maxSlots !== null ? $maxSlots : '∞' ?>
            </div>
            <div style="padding:var(--space-3) var(--space-4);text-align:center">
                <button type="button" class="btn-icon" title="Edit" onclick="startRowEdit(<?= $ci ?>)" style="color:var(--text-tertiary)">
                    <?= icon('ic_fluent_edit_24_regular', 14) ?>
                </button>
            </div>
        </div>
        <!-- Edit row -->
        <div id="row-edit-<?= $ci ?>" style="display:none;<?= $bb ?>;background:var(--bg-subtle)">
            <form method="POST" class="row-edit-form" data-row="<?= $ci ?>" style="display:grid;grid-template-columns:minmax(280px,1.6fr) minmax(160px,0.9fr) 90px 110px 90px 110px 110px 60px;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_tiers">
                <input type="hidden" name="scores[<?= $enc ?>][course]" value="<?= $enc ?>">
                <div style="padding:var(--space-3) var(--space-4);font-weight:var(--weight-medium)"><?= e($course) ?></div>
                <div style="padding:var(--space-3) var(--space-4)">
                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                        <?php if ($strands): foreach ($strands as $s): ?>
                            <span style="font-size:11px;padding:2px 8px;background:var(--bg-subtle);border-radius:9999px;color:var(--text-secondary);white-space:nowrap"><?= e($s) ?></span>
                        <?php endforeach; else: ?>
                            <span style="color:var(--text-tertiary)">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="padding:var(--space-3) var(--space-4)">
                    <input type="number" name="scores[<?= $enc ?>][high]"
                           class="form-control tier-high" data-course="<?= $enc ?>" data-row="<?= $ci ?>"
                           value="<?= $highFrom ?>" min="2" max="10"
                           style="font-size:var(--text-sm);padding:5px 8px;text-align:center;width:100%">
                </div>
                <div style="padding:var(--space-3) var(--space-4)">
                    <input type="number" name="scores[<?= $enc ?>][avg]"
                           class="form-control tier-avg" data-course="<?= $enc ?>" data-row="<?= $ci ?>"
                           value="<?= $avgFrom ?>" min="1" max="9"
                           style="font-size:var(--text-sm);padding:5px 8px;text-align:center;width:100%">
                </div>
                <div style="padding:var(--space-3) var(--space-4);text-align:center;color:var(--text-tertiary)" class="low-label" data-row="<?= $ci ?>">
                    1–<?= max(0, $avgFrom - 1) ?>
                </div>
                <div style="padding:var(--space-3) var(--space-4);text-align:center"><strong><?= $accepted ?></strong></div>
                <div style="padding:var(--space-3) var(--space-4)">
                    <?php if ($canEditMaxSlots): ?>
                        <input type="number" name="caps[<?= $enc ?>]" class="form-control"
                               min="0" max="99999"
                               value="<?= $maxSlots !== null ? (int)$maxSlots : '' ?>"
                               placeholder="∞"
                               style="font-size:var(--text-sm);padding:5px 8px;text-align:center;width:100%">
                    <?php else: ?>
                        <div style="text-align:center;color:var(--text-tertiary);font-size:var(--text-sm)">
                            <?= $maxSlots !== null ? (int)$maxSlots : '∞' ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="padding:var(--space-3) var(--space-4);text-align:center">
                    <button type="button" class="btn-icon" title="Cancel" onclick="cancelRowEdit(<?= $ci ?>)" style="color:var(--text-tertiary)">
                        <?= icon('ic_fluent_dismiss_24_regular', 14) ?>
                    </button>
                </div>
            </form>
            <div style="display:flex;gap:var(--space-2);justify-content:flex-end;padding:var(--space-2) var(--space-4) var(--space-3)">
                <button type="button" class="btn btn-primary btn-sm" onclick="saveRowEdit(<?= $ci ?>)">Save</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($canManageCourses): /* Dean is scoped to their own college's
              tier thresholds + max-slots only — Custom Courses and the SHS
              Strand reference are out of scope for that role. */ ?>

    <!-- ── Section 4: Custom Courses ───────────────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
        <div>
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Custom Courses</div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                Additional programs you've added. They appear in dropdowns and strand-match suggestions.
            </div>
        </div>
        <?php if ($canManageCourses): ?>
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-course-modal').style.display='flex'">
                + Add Course
            </button>
        <?php endif; ?>
    </div>
    <div class="card" style="padding:var(--space-5);margin-bottom:var(--space-6)">

        <?php if (!$customCourses): ?>
            <div style="text-align:left;padding:var(--space-8);color:var(--text-tertiary);font-size:var(--text-sm)">
                No custom courses yet. Click <strong>+ Add Course</strong> to add a new program.
            </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--space-3)">
        <?php foreach ($customCourses as $cc):
            $ccStrands = json_decode($cc['strands'] ?? '[]', true) ?: [];
        ?>
        <div style="border:1px solid var(--border);border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);
                    display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap;
                    <?= !$cc['is_active'] ? 'opacity:0.55' : '' ?>">
            <div style="flex:1;min-width:200px">
                <div style="display:flex;align-items:center;gap:var(--space-2)">
                    <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($cc['course_name']) ?></span>
                    <?php if (!$cc['is_active']): ?>
                        <span style="font-size:var(--text-xs);padding:1px 7px;background:#f3f4f6;color:#6b7280;border-radius:9999px">Inactive</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:var(--space-1);flex-wrap:wrap;margin-top:var(--space-1)">
                    <?php foreach ($ccStrands as $s): ?>
                        <span style="font-size:var(--text-xs);padding:1px 7px;background:var(--bg-subtle);
                                     border-radius:9999px;color:var(--text-secondary)"><?= e($s) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$ccStrands): ?>
                        <span style="font-size:var(--text-xs);color:var(--text-tertiary)">No strands set</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($canManageCourses): ?>
                <div style="display:flex;gap:var(--space-2);align-items:center">
                    <button class="btn-icon" title="Edit"
                            onclick="openEditModal(<?= $cc['id'] ?>, <?= e(json_encode($cc['course_name'])) ?>,
                                     <?= e(json_encode($ccStrands)) ?>, <?= $cc['is_active'] ?>)"
                            style="color:var(--text-tertiary)">
                        <?= icon('ic_fluent_edit_24_regular', 14) ?>
                    </button>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete this course? This cannot be undone.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_course">
                        <input type="hidden" name="course_id" value="<?= $cc['id'] ?>">
                        <button type="submit" class="btn-icon" title="Delete"
                                style="color:var(--error)">
                            <?= icon('ic_fluent_delete_24_regular', 14) ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Section 5: Strand Reference ─────────────────────────── -->
    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base);margin-bottom:var(--space-4)">SHS Strand Reference</div>
    <div class="card" style="padding:var(--space-5)">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--space-3)">
        <?php foreach (SHS_STRANDS as $key => $label): ?>
            <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);
                        padding:var(--space-3)">
                <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-primary)"><?= e($key) ?></div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px"><?= e($label) ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php endif; /* /Custom Courses + SHS Strand Reference for Admin/SSO only */ ?>
</div>

<!-- ── Add Course Modal ───────────────────────────────────────────────── -->
<div id="add-course-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;
            align-items:center;justify-content:center;padding:var(--space-4)">
    <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-lg);padding:var(--space-6);
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
                    <label class="form-label">Course / Program Name <span style="color:var(--error)">*</span></label>
                    <input type="text" name="course_name" class="form-control"
                           placeholder="e.g. BS Data Science (BSDS)" required>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                        Use the full official name including abbreviation.
                    </p>
                </div>
                <div>
                    <label class="form-label">Accepted SHS Strands</label>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-2)">
                        Check all strands whose graduates may apply for this course.
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2)">
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
    <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-lg);padding:var(--space-6);
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
                    <label class="form-label">Course / Program Name <span style="color:var(--error)">*</span></label>
                    <input type="text" name="course_name" id="edit-course-name" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Accepted SHS Strands</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2)" id="edit-strand-grid">
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

<script>
function openEditModal(id, name, strands, isActive) {
    document.getElementById('edit-course-id').value   = id;
    document.getElementById('edit-course-name').value = name;
    document.getElementById('edit-is-active').checked = !!isActive;
    document.querySelectorAll('.edit-strand-cb').forEach(cb => {
        cb.checked = Array.isArray(strands) && strands.includes(cb.value);
    });
    document.getElementById('edit-course-modal').style.display = 'flex';
}

function startRowEdit(i) {
    document.getElementById('row-read-' + i).style.display = 'none';
    document.getElementById('row-edit-' + i).style.display = 'block';
}
function cancelRowEdit(i) {
    document.getElementById('row-edit-' + i).style.display = 'none';
    document.getElementById('row-read-' + i).style.display = 'grid';
}
function saveRowEdit(i) {
    var editDiv = document.getElementById('row-edit-' + i);
    var form = editDiv.querySelector('form');
    // Inject cap value as a hidden field alongside the tiers form
    var capInput = form.querySelector('input[name^="caps["]');
    if (capInput) {
        // Also submit caps via a separate fetch, then submit tiers form
        var capsData = new FormData();
        capsData.append('action', 'update_course_caps');
        // Add CSRF token
        var csrfInput = form.querySelector('input[name="_csrf"]');
        if (csrfInput) capsData.append('_csrf', csrfInput.value);
        capsData.append(capInput.name, capInput.value);
        fetch(window.location.pathname, { method: 'POST', body: capsData })
            .then(function() { form.submit(); })
            .catch(function() { form.submit(); });
    } else {
        form.submit();
    }
}

// Live constraints: avg < high; live-update Low band label.
(function() {
    document.addEventListener('input', function(e) {
        var el = e.target;
        if (!el.classList.contains('tier-high') && !el.classList.contains('tier-avg')) return;
        var rowIdx = el.dataset.row;
        var editDiv = document.getElementById('row-edit-' + rowIdx);
        if (!editDiv) return;
        var highEl = editDiv.querySelector('.tier-high');
        var avgEl  = editDiv.querySelector('.tier-avg');
        var lowEl  = editDiv.querySelector('.low-label');
        if (!highEl || !avgEl || !lowEl) return;
        var highVal = parseInt(highEl.value, 10) || 7;
        var avgVal  = parseInt(avgEl.value,  10) || 4;
        if (el.classList.contains('tier-high') && avgVal >= highVal) {
            avgEl.value = Math.max(1, highVal - 1);
        }
        if (el.classList.contains('tier-avg')  && avgVal >= highVal) {
            highEl.value = Math.min(10, avgVal + 1);
        }
        var finalAvg = parseInt(avgEl.value, 10) || 4;
        lowEl.textContent = finalAvg <= 1 ? '—' : '1–' + (finalAvg - 1);
    });
})();

// Close modals on backdrop click
['add-course-modal','edit-course-modal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>
<style>
.course-row:hover { background: var(--bg-overlay); }
</style>
<?php
$content   = ob_get_clean();
$pageTitle = 'Courses & Strands';
$activeNav = 'courses';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
