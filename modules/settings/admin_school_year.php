<?php
// ============================================================
// modules/settings/admin_school_year.php
// Admin: manage admissions window (open/close dates)
// School year is derived automatically from the open date.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db      = db();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_admissions_window') {
        $open        = trim($_POST['admissions_open']    ?? '');
        $close       = trim($_POST['admissions_close']   ?? '');
        $docDeadline = trim($_POST['document_deadline']  ?? '');

        if (!$open || !$close || !$docDeadline) {
            $errors[] = 'Admissions opens, closes, and document deadline are all required.';
        } elseif ($close <= $open) {
            $errors[] = 'Admissions close date must be after the open date.';
        } elseif ($docDeadline <= $open) {
            $errors[] = 'Document deadline must be after the admissions open date.';
        } elseif ($docDeadline > $close) {
            $errors[] = 'Document deadline must be on or before the admissions close date.';
        } else {
            $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                       ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value';
            $db->prepare($upsert)->execute(['admissions_open',  $open]);
            $db->prepare($upsert)->execute(['admissions_close', $close]);
            $db->prepare($upsert)->execute(['document_deadline', $docDeadline]);

            // Derive and persist school year for reference
            $openYear  = (int) date('Y', strtotime($open));
            $schoolYear = $openYear . '-' . ($openYear + 1);
            $db->prepare($upsert)->execute(['current_school_year', $schoolYear]);

            audit_log('admissions_window_set',
                "Admissions window set: {$open} to {$close} (AY {$schoolYear})");
            $success[] = "Admissions window saved (AY {$schoolYear}).";
        }
    }

    if ($action === 'set_enrollment_schedule') {
        $eDate  = trim($_POST['enrollment_date']  ?? '');
        $eTime  = trim($_POST['enrollment_time']  ?? '');
        $eVenue = trim($_POST['enrollment_venue'] ?? '');

        if (!$eDate || !$eTime || !$eVenue) {
            $errors[] = 'Enrollment date, time, and venue are all required.';
        } else {
            $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                       ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value';
            $db->prepare($upsert)->execute(['enrollment_date',  $eDate]);
            $db->prepare($upsert)->execute(['enrollment_time',  $eTime]);
            $db->prepare($upsert)->execute(['enrollment_venue', $eVenue]);

            audit_log('enrollment_schedule_set',
                "Enrollment schedule set: {$eDate} {$eTime} @ {$eVenue}");
            $success[] = 'Enrollment schedule saved. Admitted students will see this on their result page.';
        }
    }

    if ($action === 'new_cycle') {
        $confirm = trim($_POST['confirm_text'] ?? '');
        if (strcasecmp($confirm, 'Start new cycle') !== 0) {
            $errors[] = 'Type "Start new cycle" to confirm.';
        } else {
            $db->exec('UPDATE exams SET is_active=0');
            audit_log('new_cycle_started', 'Started new admission cycle — exam deactivated.');
            $success[] = 'New cycle started. Previous data preserved.';
        }
    }

    // ── Import legacy rows (from AI import modal) ─────────────────────────
    if ($action === 'import_legacy_rows') {
        header('Content-Type: application/json');
        // Re-check CSRF here so failure returns JSON, not plain text
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Invalid or expired form token. Please refresh the page and try again.']);
            exit;
        }

        $rows      = json_decode($_POST['rows'] ?? '[]', true);
        $targetSY  = trim($_POST['school_year'] ?? '');
        $adminId   = Auth::id();

        if (!$rows || !is_array($rows) || count($rows) === 0) {
            echo json_encode(['ok' => false, 'error' => 'No rows received.']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $targetSY)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid school year format.']);
            exit;
        }

        // Validate that the two halves are consecutive years
        [$y1, $y2] = explode('-', $targetSY);
        if ((int)$y2 !== (int)$y1 + 1) {
            echo json_encode(['ok' => false, 'error' => 'School year must be consecutive years (e.g. 2023-2024).']);
            exit;
        }

        // Auto-create the school year in school_settings if it doesn't exist
        $existsStmt = $db->prepare(
            'SELECT COUNT(*) FROM school_settings WHERE setting_key = ? AND setting_value = ?'
        );
        $existsStmt->execute(['current_school_year', $targetSY]);
        // We don't block based on this — we just ensure the SY exists as a record
        // in school_settings so the results filter can discover it.
        // We do NOT overwrite the REAL current_school_year.
        // Instead we use a separate key: historical_school_years (comma-separated list).
        $histStmt = $db->prepare(
            'SELECT setting_value FROM school_settings WHERE setting_key = ?'
        );
        $histStmt->execute(['historical_school_years']);
        $histRow = $histStmt->fetch();
        $existingHist = $histRow ? array_filter(array_map('trim', explode(',', $histRow['setting_value']))) : [];
        if (!in_array($targetSY, $existingHist, true)) {
            $existingHist[] = $targetSY;
            $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                       ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value';
            $db->prepare($upsert)->execute(['historical_school_years', implode(',', $existingHist)]);
        }

        // Load current course list for validation (case-insensitive)
        $courseStmt = $db->query('SELECT course_name FROM course_departments');
        $validCourses = [];
        foreach ($courseStmt->fetchAll() as $c) {
            $validCourses[strtolower($c['course_name'])] = $c['course_name'];
        }

        $inserted = 0;
        $skipped  = [];

        try {
            $db->beginTransaction();

            // ── Pre-flight: resolve emails and bulk-check duplicates ──────────
            $sySlug = str_replace('-', '', $targetSY);
            $generatedEmails = [];
            foreach ($rows as $i => $row) {
                $email = trim($row['email'] ?? '');
                if (!$email) {
                    $email = 'legacy.' . $sySlug . '.' . ($i + 1) . '@plp.archive';
                }
                $generatedEmails[$i] = $email;
            }

            // One query to find all already-existing emails
            $placeholders = implode(',', array_fill(0, count($generatedEmails), '?'));
            $existStmt = $db->prepare("SELECT email FROM users WHERE email IN ({$placeholders})");
            $existStmt->execute(array_values($generatedEmails));
            $existingEmails = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

            // ── Prepare statements ONCE outside the loop ──────────────────────
            // Use a fixed dummy password for all legacy imports (accounts are non-activatable)
            $sharedPwHash = password_hash('legacy-import-' . $sySlug, PASSWORD_DEFAULT);

            $userStmt = $db->prepare(
                'INSERT INTO users
                 (name, first_name, middle_name, last_name, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                 RETURNING id'
            );
            $appStmt = $db->prepare(
                'INSERT INTO applicants
                 (user_id, applicant_type, course_applied, overall_status, school_year)
                 VALUES (?, ?, ?, ?, ?)
                 RETURNING id'
            );
            $resStmt = $db->prepare(
                'INSERT INTO admission_results
                 (applicant_id, result, remarks, released_by, released_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );

            foreach ($rows as $i => $row) {
                $name   = trim($row['name']   ?? '');
                $email  = $generatedEmails[$i];
                $type   = trim($row['type']   ?? '');
                $course = trim($row['course'] ?? '');
                $result = trim($row['result'] ?? '');
                $score  = isset($row['score'])  ? (int)$row['score']  : null;
                $total  = isset($row['total'])  ? (int)$row['total']  : null;
                $remarks = trim($row['remarks'] ?? '');

                if (!$name || !$type || !$course || !$result) {
                    $skipped[] = "Row " . ($i + 1) . ": missing required field (name, type, course, or result).";
                    continue;
                }

                // Skip duplicates (checked in bulk above)
                if (isset($existingEmails[$email])) {
                    $skipped[] = "Row " . ($i + 1) . " ({$name}): email {$email} already exists — skipped.";
                    continue;
                }

                // Normalize result
                $resultNorm = match(strtolower($result)) {
                    'accepted','passed','admitted','accept','pass','admit','✓','yes','approved' => 'accepted',
                    'waitlisted','waitlist','wait','pending','hold','conditional'               => 'waitlisted',
                    default                                                                     => 'rejected',
                };

                // Normalize applicant type
                $typeNorm = match(strtolower($type)) {
                    'freshman','fresh','grade 12','g12','hs','senior high','grade12','shs','sh' => 'freshman',
                    'transferee','transfer','trans'                                              => 'transferee',
                    'foreign','international','intl','foreigner'                                => 'foreign',
                    default                                                                     => 'freshman',
                };

                // Match course (already AI-normalized, but do a safety fuzzy check)
                $courseMatched = $validCourses[strtolower($course)] ?? $course;

                // Parse name into parts (Last, First Middle or just Full Name)
                $nameParts  = explode(',', $name, 2);
                $lastName   = '';
                $firstName  = '';
                $middleName = '';
                if (count($nameParts) === 2) {
                    // "Dela Cruz, Juan M." format
                    $lastName  = trim($nameParts[0]);
                    $firstMid  = preg_split('/\s+/', trim($nameParts[1]), 2);
                    $firstName = $firstMid[0] ?? '';
                    $middleName = $firstMid[1] ?? '';
                } else {
                    // "Juan Dela Cruz" format
                    $parts      = preg_split('/\s+/', $name);
                    $firstName  = array_shift($parts) ?? '';
                    $lastName   = array_pop($parts) ?? '';
                    $middleName = implode(' ', $parts);
                }

                $userStmt->execute([$name, $firstName, $middleName, $lastName, $email, $sharedPwHash, 'student']);
                $userId = (int)$userStmt->fetchColumn();

                $appStmt->execute([$userId, $typeNorm, $courseMatched, 'released', $targetSY]);
                $applicantId = (int)$appStmt->fetchColumn();

                // Build remarks — stash exam score here since we skip exam_results
                $remarkParts = [];
                if ($score !== null && $total !== null) {
                    $remarkParts[] = "Exam: {$score}/{$total}";
                } elseif ($score !== null) {
                    $remarkParts[] = "Exam score: {$score}";
                }
                if ($remarks !== '') {
                    $remarkParts[] = $remarks;
                }
                $remarkParts[] = '[Legacy import ' . $targetSY . ']';
                $finalRemarks  = implode(' | ', $remarkParts);

                $resStmt->execute([$applicantId, $resultNorm, $finalRemarks, $adminId]);

                $inserted++;
            }

            $db->commit();

            audit_log(
                'legacy_data_imported',
                "Imported {$inserted} historical record(s) for AY {$targetSY}. " .
                (count($skipped) ? count($skipped) . " skipped." : ""),
                'school_year',
                null
            );

            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
            ]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Reload settings after possible save
$admissionsOpen    = school_setting('admissions_open',  '');
$admissionsClose   = school_setting('admissions_close', '');
$docDeadline       = school_setting('document_deadline', '');
$currentYear       = school_setting('current_school_year', '—');
$enrollmentDate    = school_setting('enrollment_date',  '');
$enrollmentTime    = school_setting('enrollment_time',  '');
$enrollmentVenue   = school_setting('enrollment_venue', '');
$isOpen            = admissions_is_open();

// Stats by school year
$stmt = $db->query(
    'SELECT school_year, overall_status, COUNT(*) as cnt
     FROM applicants GROUP BY school_year, overall_status ORDER BY school_year DESC'
);
$rawStats = $stmt->fetchAll();

$statsByYear = [];
foreach ($rawStats as $row) {
    $statsByYear[$row['school_year']][$row['overall_status']] = (int)$row['cnt'];
}

// Load course names for the AI prompt
$courseStmt   = $db->query('SELECT course_name FROM course_departments ORDER BY course_name');
$courseNames  = array_column($courseStmt->fetchAll(), 'course_name');
$courseNamesJson = json_encode($courseNames);

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="admin-form-stack">

    <!-- Admissions window -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
            <div class="card-title" style="margin:0">Admissions Window</div>
            <?php if ($isOpen): ?>
                <span class="badge badge-approved">Open</span>
            <?php elseif ($admissionsOpen || $admissionsClose): ?>
                <span class="badge badge-pending">Closed</span>
            <?php else: ?>
                <span class="badge" style="background:var(--bg-subtle);color:var(--text-tertiary)">Not set</span>
            <?php endif; ?>
        </div>

        <form method="POST" id="apw-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_admissions_window">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-3)">
                <div>
                    <label class="form-label" for="apw-open">Admissions Opens</label>
                    <input type="date" id="apw-open" name="admissions_open" class="form-input"
                           value="<?= e($admissionsOpen) ?>" required>
                </div>
                <div>
                    <label class="form-label" for="apw-close">Admissions Closes</label>
                    <input type="date" id="apw-close" name="admissions_close" class="form-input"
                           value="<?= e($admissionsClose) ?>" required>
                </div>
                <div>
                    <label class="form-label" for="apw-deadline">Document Deadline</label>
                    <input type="date" id="apw-deadline" name="document_deadline" class="form-input"
                           value="<?= e($docDeadline) ?>" required>
                </div>
            </div>
            <div id="apw-hint" style="display:none;font-size:var(--text-sm);color:var(--error);margin-bottom:var(--space-3)"></div>
            <button type="submit" id="apw-save" class="btn btn-primary" disabled>Save Window</button>
        </form>
        <script>
        (function(){
            var form     = document.getElementById('apw-form');
            if (!form) return;
            var openEl   = document.getElementById('apw-open');
            var closeEl  = document.getElementById('apw-close');
            var deadEl   = document.getElementById('apw-deadline');
            var saveBtn  = document.getElementById('apw-save');
            var hintEl   = document.getElementById('apw-hint');

            function evaluate(){
                var o = openEl.value, c = closeEl.value, d = deadEl.value;
                deadEl.min = o || '';
                deadEl.max = c || '';
                var msg = '';
                if (!o || !c || !d) {
                    msg = 'Fill in all three dates to save.';
                } else if (c <= o) {
                    msg = 'Admissions close date must be after the open date.';
                } else if (d <= o) {
                    msg = 'Document deadline must be after the admissions open date.';
                } else if (d > c) {
                    msg = 'Document deadline must be on or before the admissions close date.';
                }
                if (msg) {
                    saveBtn.disabled = true;
                    hintEl.textContent = msg;
                    hintEl.style.display = '';
                } else {
                    saveBtn.disabled = false;
                    hintEl.textContent = '';
                    hintEl.style.display = 'none';
                }
            }
            ['input','change','blur'].forEach(function(evt){
                openEl .addEventListener(evt, evaluate);
                closeEl.addEventListener(evt, evaluate);
                deadEl .addEventListener(evt, evaluate);
            });
            evaluate();
        })();
        </script>
    </div>

    <!-- Enrollment schedule -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
            <div class="card-title" style="margin:0">Enrollment Schedule</div>
            <?php if ($enrollmentDate && $enrollmentTime && $enrollmentVenue): ?>
                <span class="badge badge-approved">Set</span>
            <?php else: ?>
                <span class="badge" style="background:var(--bg-subtle);color:var(--text-tertiary)">Not set</span>
            <?php endif; ?>
        </div>

        <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:0;margin-bottom:var(--space-4)">
            Shown to admitted applicants on their result page. They
            bring the original copies of their uploaded documents on
            this date for verification before official enrollment.
        </p>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_enrollment_schedule">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-3)">
                <div>
                    <label class="form-label" for="enr-date">Date</label>
                    <input type="date" id="enr-date" name="enrollment_date" class="form-input"
                           value="<?= e($enrollmentDate) ?>" required>
                </div>
                <div>
                    <label class="form-label" for="enr-time">Time</label>
                    <input type="time" id="enr-time" name="enrollment_time" class="form-input"
                           value="<?= e($enrollmentTime) ?>" required>
                </div>
            </div>
            <div style="margin-bottom:var(--space-3)">
                <label class="form-label" for="enr-venue">Venue</label>
                <input type="text" id="enr-venue" name="enrollment_venue" class="form-input"
                       value="<?= e($enrollmentVenue) ?>"
                       placeholder="e.g. PLP Main Building, Registrar's Office" required>
            </div>
            <button type="submit" class="btn btn-primary">Save Schedule</button>
        </form>
    </div>

    <!-- ── Import Historical Records ──────────────────────────────── -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-2)">
            <div class="card-title" style="margin:0">Import Historical Records</div>
            <button class="btn btn-secondary" onclick="openLegacyImportModal()" style="display:flex;align-items:center;gap:6px">
                <?= icon('arrow-circle-down', 14) ?>
                Import with AI ✨
            </button>
        </div>
        <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
            Upload an old Excel, CSV, PDF, or image of past admissions results — AI will extract the rows,
            normalize course names and result statuses, and save them under the selected school year.
            Imported records appear in <strong>Admin → Results</strong> under the matching school year filter.
        </p>
    </div>

    <!-- Historical summary -->
    <?php if (!empty($statsByYear)): ?>
    <div class="card">
        <div class="card-title" style="margin-bottom:var(--space-5)">Applicant History</div>
        <div style="display:flex;flex-direction:column;gap:var(--space-4)">
        <?php foreach ($statsByYear as $year => $statuses):
            $total = array_sum($statuses);
        ?>
            <div style="padding:var(--space-4);background:var(--bg-subtle);border-radius:var(--radius-md)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3)">
                    <div>
                        <span style="font-weight:var(--weight-semibold)"><?= e($year) ?></span>
                        <?php if ($year === $currentYear): ?>
                            <span class="badge badge-approved" style="margin-left:var(--space-2)">Current</span>
                        <?php endif; ?>
                    </div>
                    <span style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= $total ?> total</span>
                </div>
                <div style="display:flex;gap:var(--space-4);flex-wrap:wrap">
                    <?php
                    $stages = ['pending','documents','submitted','exam','interview','released'];
                    foreach ($stages as $stage):
                        $cnt = $statuses[$stage] ?? 0;
                        if (!$cnt) continue;
                    ?>
                        <div style="font-size:var(--text-sm)">
                            <span style="color:var(--text-tertiary)"><?= ucfirst($stage) ?>:</span>
                            <span style="font-weight:var(--weight-semibold);margin-left:4px"><?= $cnt ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- New cycle — danger zone -->
    <div class="card card-danger">
        <div class="card-title" style="color:var(--error);margin-bottom:var(--space-1)">Start New Admission Cycle</div>
        <p class="card-description" style="margin-bottom:var(--space-5)">
            Starts a new admissions cycle. The current exam is deactivated and all previous applicant data is preserved.
        </p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="new_cycle">
            <div>
                <label class="form-label">Type <strong>Start new cycle</strong> to confirm</label>
                <input type="text" name="confirm_text" class="form-input"
                       placeholder="Start new cycle" autocomplete="off" style="max-width:280px">
            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-danger">Start New Cycle</button>
            </div>
        </form>
    </div>

</div>

<!-- ════════════════════════════════════════════════════════════
     AI IMPORT MODAL — Legacy Records
     (CSS classes mirror exam/staff_manage.php exactly)
════════════════════════════════════════════════════════════ -->
<style>
@keyframes ai-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.ai-status-bar { display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--radius-md);font-size:var(--text-sm);border:1px solid; }
.ai-status-bar.connected { background:#f0faf4;border-color:#a7d9b8;color:#1a5c32; }
.ai-status-bar.disconnected { background:#fafafa;border-color:var(--border);color:var(--text-secondary); }
.ai-status-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.ai-status-bar.connected .ai-status-dot { background:#22c55e; }
.ai-status-bar.disconnected .ai-status-dot { background:var(--neutral-400);animation:ai-pulse 2s ease-in-out infinite; }
.ai-dropzone { border:2px dashed var(--border);border-radius:var(--radius-md);padding:var(--space-6) var(--space-5);text-align:center;cursor:pointer;transition:border-color .15s,background .15s; }
.ai-dropzone:hover,.ai-dropzone.dragover { border-color:var(--accent);background:rgba(45,106,79,.03); }
.ai-dropzone.has-file { border-style:solid;border-color:var(--accent);background:rgba(45,106,79,.04); }
.ai-dropzone-icon { width:40px;height:40px;margin:0 auto var(--space-2);border-radius:var(--radius-md);background:var(--neutral-100);display:flex;align-items:center;justify-content:center; }
.ai-file-tag { display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border-radius:var(--radius-sm);padding:4px 10px;font-size:var(--text-xs);font-weight:var(--weight-medium);margin-top:var(--space-2); }
.ai-file-tag .rm { cursor:pointer;opacity:.7;background:none;border:none;color:#fff;padding:0;font-size:14px;display:flex;align-items:center;line-height:1; }
.ai-file-tag .rm:hover { opacity:1; }
.ai-progress-wrap { padding:var(--space-2) 0; }
.ai-progress-track { height:5px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:var(--space-3); }
.ai-progress-fill { height:100%;background:var(--accent);border-radius:99px;width:0%;transition:width .4s cubic-bezier(.4,0,.2,1); }
.ai-progress-step { font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px; }
.ai-warn-strip { display:flex;align-items:flex-start;gap:8px;background:#fffbeb;border:1px solid #fcd34d;border-radius:var(--radius-md);padding:10px 12px;font-size:var(--text-xs);color:#92400e;line-height:1.5; }
</style>

<div id="legacy-import-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:600px;max-height:90vh;display:flex;flex-direction:column">
        <?= csrf_field() ?>

        <!-- Header — same accent-box + subtitle pattern as exam import -->
        <div class="modal-header" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:var(--space-3)">
                <div style="width:34px;height:34px;border-radius:var(--radius-md);background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('sparkle', 17, 'color:#fff') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Import Historical Records with AI</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px">Upload an old file and AI will extract the admissions records</div>
                </div>
            </div>
            <button class="btn-icon" onclick="closeLegacyImportModal()">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>

        <!-- Body -->
        <div class="modal-body" style="overflow-y:auto;display:flex;flex-direction:column;gap:var(--space-4)">

            <!-- Puter status bar -->
            <div id="legacy-puter-status" class="ai-status-bar disconnected">
                <div class="ai-status-dot"></div>
                <div id="legacy-puter-status-text" style="flex:1">Checking Puter connection…</div>
                <a id="legacy-puter-link" href="https://puter.com" target="_blank" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);text-decoration:none;white-space:nowrap">Create account →</a>
                <button id="legacy-puter-signin-btn" onclick="legacyPuterSignIn()" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);background:none;border:none;cursor:pointer;padding:0;white-space:nowrap">Sign in →</button>
                <button id="legacy-puter-signout-btn" onclick="legacyPuterSignOut()" style="display:none;font-size:var(--text-xs);color:var(--text-tertiary);background:none;border:none;cursor:pointer;padding:0">Sign out</button>
            </div>

            <!-- Step: upload -->
            <div id="legacy-step-upload">

                <!-- School year picker -->
                <div style="margin-bottom:var(--space-4)">
                    <label class="form-label" for="legacy-sy-input">Target School Year</label>
                    <div style="display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap">
                        <input type="text" id="legacy-sy-input" class="form-control"
                               style="width:140px;padding:var(--space-1) var(--space-2)"
                               placeholder="e.g. 2023-2024" pattern="\d{4}-\d{4}"
                               value="<?= e(date('Y') - 1) . '-' . e(date('Y')) ?>">
                        <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                            Current SY: <strong><?= e($currentYear) ?></strong> — import into a past year only.
                        </span>
                    </div>
                    <div id="legacy-sy-hint" style="display:none;font-size:var(--text-xs);color:var(--error);margin-top:4px"></div>
                </div>

                <!-- Drop zone -->
                <div class="ai-dropzone" id="legacy-drop-zone"
                     onclick="document.getElementById('legacy-file-input').click()"
                     ondragover="event.preventDefault();this.classList.add('dragover')"
                     ondragleave="this.classList.remove('dragover')"
                     ondrop="handleLegacyFileDrop(event)">
                    <div class="ai-dropzone-icon">
                        <?= icon('file', 20, 'color:var(--text-tertiary)') ?>
                    </div>
                    <div id="legacy-dropzone-label" style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-secondary)">Drop file here or <span style="color:var(--accent)">click to browse</span></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">CSV · Excel (xlsx/xls) · PDF · DOCX · JPG · PNG — columns can be in any order</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">Need a template? <a href="/sample-legacy-admissions.csv" download style="color:var(--accent)" onclick="event.stopPropagation()">Download sample CSV</a></div>
                    <div id="legacy-file-tag-wrap" style="display:none;margin-top:var(--space-3)">
                        <span class="ai-file-tag">
                            <?= icon('file', 12, 'color:#fff') ?>
                            <span id="legacy-file-tag-name"></span>
                            <button class="rm" onclick="event.stopPropagation();clearLegacyFile()" title="Remove">✕</button>
                        </span>
                    </div>
                    <input type="file" id="legacy-file-input"
                           accept=".csv,.xlsx,.xls,.pdf,.docx,.doc,.jpg,.jpeg,.png"
                           style="display:none"
                           onchange="handleLegacyFileSelect(this)">
                </div>
            </div>

            <!-- Step: processing -->
            <div id="legacy-step-processing" style="display:none;flex-direction:column;gap:var(--space-3);padding:var(--space-6) 0;text-align:center">
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)" id="legacy-processing-label">AI is processing your file…</div>
                <div class="ai-progress-wrap" style="padding:0">
                    <div class="ai-progress-track">
                        <div class="ai-progress-fill" id="legacy-progress-fill"></div>
                    </div>
                </div>
                <div class="ai-progress-step" id="legacy-progress-step">Preparing…</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);opacity:.7;margin-top:var(--space-1)">A Puter sign-in popup may appear</div>
                <div class="ai-progress-step" id="legacy-progress-pct" style="display:none">0%</div>
            </div>

            <!-- Step: preview -->
            <div id="legacy-step-preview" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold)" id="legacy-preview-count"></div>
                    <button class="btn btn-ghost btn-sm" onclick="resetLegacyImport()">← Try again</button>
                </div>
                <div class="ai-warn-strip">
                    <?= icon('warning:fill', 14, 'margin-top:1px;color:#d97706') ?>
                    <span>AI results may not be 100% accurate. <strong>Review each row and uncheck any you want to skip</strong> before saving.</span>
                </div>
                <div style="display:flex;justify-content:flex-end">
                    <label style="font-size:var(--text-xs);color:var(--text-tertiary);display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="legacy-check-all" checked onchange="toggleAllLegacyRows(this.checked)"> Select all
                    </label>
                </div>
                <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md)">
                    <table style="width:100%;border-collapse:collapse;font-size:var(--text-sm)">
                        <thead>
                            <tr style="background:var(--bg-subtle);position:sticky;top:0;z-index:1">
                                <th style="padding:8px 10px;text-align:left;font-weight:var(--weight-semibold);border-bottom:1px solid var(--border);width:28px"></th>
                                <th style="padding:8px 10px;text-align:left;font-weight:var(--weight-semibold);border-bottom:1px solid var(--border)">Name</th>
                                <th style="padding:8px 10px;text-align:left;font-weight:var(--weight-semibold);border-bottom:1px solid var(--border)">Type</th>
                                <th style="padding:8px 10px;text-align:left;font-weight:var(--weight-semibold);border-bottom:1px solid var(--border)">Course</th>
                                <th style="padding:8px 10px;text-align:left;font-weight:var(--weight-semibold);border-bottom:1px solid var(--border)">Result</th>
                                <th style="padding:8px 10px;text-align:left;font-weight:var(--weight-semibold);border-bottom:1px solid var(--border)">Score</th>
                            </tr>
                        </thead>
                        <tbody id="legacy-preview-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Step: error -->
            <div id="legacy-step-error" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:flex-start;gap:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:var(--radius-md);padding:12px 14px;font-size:var(--text-sm);color:#991b1b">
                    <?= icon('warning-circle:fill', 16, 'margin-top:1px') ?>
                    <span id="legacy-error-msg"></span>
                </div>
                <button class="btn btn-ghost btn-sm" style="align-self:flex-start" onclick="resetLegacyImport()">← Try again</button>
            </div>

        </div><!-- /modal-body -->

        <!-- Footer: upload step -->
        <div class="modal-footer" id="legacy-modal-footer" style="border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeLegacyImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="legacy-process-btn" onclick="startLegacyProcessing()" disabled>
                <?= icon('sparkle', 14, 'margin-right:5px') ?>
                Extract Records
            </button>
        </div>

        <!-- Footer: save step -->
        <div class="modal-footer" id="legacy-save-footer" style="display:none;border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeLegacyImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="legacy-save-btn" onclick="saveLegacyRows()">
                <?= icon('floppy-disk', 14, 'margin-right:5px') ?>
                Save Selected Records
            </button>
        </div>

    </div><!-- /modal -->
</div><!-- /modal-backdrop -->

<!-- ── Legacy Import JavaScript ───────────────────────────── -->
<script>
// ── Puter helpers (shared with exam import; guard against double-load) ──
if (typeof puterLoaded === 'undefined') {
    var puterLoaded = false;
}

// Shared "load this script once" helper. checkFn reports whether the
// library is already present (skips loading entirely); onLoaded runs
// once the script tag fires its load event, before the promise
// resolves, for libraries that need a bit of post-load setup (e.g.
// pdf.js's worker path). Each specific loader below is a thin wrapper
// so every existing call site (await loadXIfNeeded()) keeps working
// unchanged.
function loadScriptOnce(src, checkFn, onLoaded) {
    return new Promise(res => {
        if (checkFn()) { res(); return; }
        const s = document.createElement('script');
        s.src = src;
        s.onload = () => { if (onLoaded) onLoaded(); res(); };
        document.head.appendChild(s);
    });
}
function loadPuterIfNeeded() {
    return loadScriptOnce(
        'https://js.puter.com/v2/',
        () => puterLoaded || window.puter,
        () => { puterLoaded = true; }
    );
}
function loadPdfJsIfNeeded() {
    return loadScriptOnce(
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
        () => !!window.pdfjsLib,
        () => {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
    );
}
function loadMammothIfNeeded() {
    return loadScriptOnce(
        'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js',
        () => !!window.mammoth
    );
}
function loadSheetJs() {
    return loadScriptOnce(
        'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
        () => !!window.XLSX
    );
}

// ── Puter status for legacy modal ──
async function refreshLegacyPuterStatus() {
    const bar      = document.getElementById('legacy-puter-status');
    const label    = document.getElementById('legacy-puter-status-text');
    const linkEl   = document.getElementById('legacy-puter-link');
    const signinBtn= document.getElementById('legacy-puter-signin-btn');
    const signoutBtn=document.getElementById('legacy-puter-signout-btn');
    label.textContent = 'Checking Puter connection…';
    [linkEl, signinBtn, signoutBtn].forEach(el => el.style.display = 'none');
    try {
        await loadPuterIfNeeded();
        const ok = await puter.auth.isSignedIn();
        if (ok) {
            let name = '';
            try { const u = await puter.auth.getUser(); name = u?.username ? ' as @' + u.username : ''; } catch(_) {}
            bar.className = 'ai-status-bar connected';
            label.textContent = 'Connected to Puter' + name;
            signoutBtn.style.display = '';
        } else {
            bar.className = 'ai-status-bar disconnected';
            label.textContent = 'Not signed in to Puter';
            linkEl.style.display = '';
            signinBtn.style.display = '';
        }
    } catch(_) {
        bar.className = 'ai-status-bar disconnected';
        label.textContent = 'Could not reach Puter';
        linkEl.style.display = '';
        signinBtn.style.display = '';
    }
}
async function legacyPuterSignIn()  { await loadPuterIfNeeded(); try { await puter.auth.signIn();  refreshLegacyPuterStatus(); } catch(_) {} }
async function legacyPuterSignOut() { await loadPuterIfNeeded(); try { await puter.auth.signOut(); refreshLegacyPuterStatus(); } catch(_) {} }

// ── Modal open/close ──
let legacySelectedFile = null;
let legacyExtractedRows = [];

function openLegacyImportModal() {
    resetLegacyImport(true);
    document.getElementById('legacy-import-modal').style.display = '';
    refreshLegacyPuterStatus();
}
function closeLegacyImportModal() {
    document.getElementById('legacy-import-modal').style.display = 'none';
}

// ── File handling ──
function handleLegacyFileDrop(e) {
    e.preventDefault();
    document.getElementById('legacy-drop-zone').classList.remove('dragover');
    if (e.dataTransfer.files[0]) setLegacyFile(e.dataTransfer.files[0]);
}
function handleLegacyFileSelect(inp) {
    if (inp.files[0]) setLegacyFile(inp.files[0]);
}
function setLegacyFile(file) {
    legacySelectedFile = file;
    document.getElementById('legacy-drop-zone').classList.add('has-file');
    document.getElementById('legacy-dropzone-label').style.display = 'none';
    document.getElementById('legacy-file-tag-wrap').style.display = '';
    document.getElementById('legacy-file-tag-name').textContent = file.name;
    document.getElementById('legacy-process-btn').disabled = false;
}
function clearLegacyFile() {
    legacySelectedFile = null;
    document.getElementById('legacy-file-input').value = '';
    document.getElementById('legacy-drop-zone').classList.remove('has-file', 'dragover');
    document.getElementById('legacy-dropzone-label').style.display = '';
    document.getElementById('legacy-file-tag-wrap').style.display = 'none';
    document.getElementById('legacy-process-btn').disabled = true;
}
function resetLegacyImport(clearFile) {
    if (clearFile) clearLegacyFile();
    legacyExtractedRows = [];
    legacyResetProgress();
    ['upload', 'processing', 'preview', 'error'].forEach(s => {
        const el = document.getElementById('legacy-step-' + s);
        if (el) el.style.display = s === 'upload' ? '' : 'none';
    });
    document.getElementById('legacy-modal-footer').style.display = '';
    document.getElementById('legacy-save-footer').style.display = 'none';
    document.getElementById('legacy-process-btn').disabled = !legacySelectedFile;
}
function showLegacyStep(step) {
    ['upload', 'processing', 'preview', 'error'].forEach(s => {
        const el = document.getElementById('legacy-step-' + s);
        if (!el) return;
        el.style.display = s === step ? (s === 'processing' || s === 'preview' ? 'flex' : '') : 'none';
    });
}

// ── Progress ──
const LEGACY_RAMP_MS  = 10000;
const LEGACY_RAMP_PCT = 80;
let _lProgressTarget = 0, _lProgressCurrent = 0, _lProgressTimer = null, _lProgressStart = 0;

function legacySetProgress(pct, label, step) {
    _lProgressTarget = pct;
    if (label) document.getElementById('legacy-processing-label').textContent = label;
    if (step)  document.getElementById('legacy-progress-step').textContent = step;
    _lTickProgress();
}
function _lTickProgress() {
    if (_lProgressTimer) clearInterval(_lProgressTimer);
    if (!_lProgressStart) _lProgressStart = Date.now();
    _lProgressTimer = setInterval(() => {
        const gap = _lProgressTarget - _lProgressCurrent;
        if (gap <= 0) { clearInterval(_lProgressTimer); return; }
        let next;
        if (_lProgressTarget >= 95) {
            next = _lProgressCurrent + Math.max(2, gap * 0.3);
        } else {
            const elapsed = Date.now() - _lProgressStart;
            const byTime  = Math.min(LEGACY_RAMP_PCT, (elapsed / LEGACY_RAMP_MS) * LEGACY_RAMP_PCT);
            const minStep = _lProgressCurrent + 0.1;
            next = Math.min(_lProgressTarget, Math.max(minStep, byTime));
        }
        if (next >= _lProgressTarget) { next = _lProgressTarget; clearInterval(_lProgressTimer); }
        _lProgressCurrent = next;
        const fill = document.getElementById('legacy-progress-fill');
        const pct  = document.getElementById('legacy-progress-pct');
        if (fill) fill.style.width = _lProgressCurrent.toFixed(1) + '%';
        if (pct)  pct.textContent  = Math.round(_lProgressCurrent) + '%';
    }, 80);
}
function legacyResetProgress() {
    if (_lProgressTimer) clearInterval(_lProgressTimer);
    _lProgressTarget = 0; _lProgressCurrent = 0; _lProgressStart = 0;
    const fill = document.getElementById('legacy-progress-fill');
    const pct  = document.getElementById('legacy-progress-pct');
    if (fill) fill.style.width = '0%';
    if (pct)  pct.textContent  = '0%';
    const lbl  = document.getElementById('legacy-processing-label');
    const step = document.getElementById('legacy-progress-step');
    if (lbl)  lbl.textContent  = 'Reading file…';
    if (step) step.textContent = 'Preparing…';
}

// ── File readers ──
function readLegacyFileAsText(f) {
    return new Promise((r, j) => {
        const x = new FileReader();
        x.onload = () => r(x.result);
        x.onerror = j;
        x.readAsText(f);
    });
}
async function extractLegacyPdfText(f) {
    const b = await f.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: b }).promise;
    let t = '';
    for (let i = 1; i <= Math.min(pdf.numPages, 20); i++) {
        const pg = await pdf.getPage(i);
        const tc = await pg.getTextContent();
        t += tc.items.map(it => it.str).join(' ') + '\n';
    }
    return t.trim();
}
async function extractLegacyDocxText(f) {
    const b = await f.arrayBuffer();
    return (await mammoth.extractRawText({ arrayBuffer: b })).value;
}
async function extractXlsxAsText(f) {
    await loadSheetJs();
    const b  = await f.arrayBuffer();
    const wb = XLSX.read(b, { type: 'array' });
    let out  = '';
    for (const sheetName of wb.SheetNames) {
        const ws  = wb.Sheets[sheetName];
        const csv = XLSX.utils.sheet_to_csv(ws);
        if (csv.trim()) out += '=== Sheet: ' + sheetName + ' ===\n' + csv + '\n\n';
    }
    return out.trim();
}

// ── AI + processing core ──
const LEGACY_COURSES = <?= $courseNamesJson ?>;

// ─────────────────────────────────────────────────────────────
//  PHASE 1 PROMPT  — schema detection only (header + 5 rows)
// ─────────────────────────────────────────────────────────────
const SCHEMA_PROMPT = `You are a column-mapping assistant for a Philippine university admissions system.

You will be given CSV column headers and up to 5 sample rows from a legacy admissions file.
Your ONLY job: return a JSON object that maps each output field to the matching header name(s).

Output exactly this shape (no markdown, no prose, just the JSON object):
{
  "name": "column name if name is in one field, or null",
  "name_parts": ["col1","col2","col3"] or null (if name is split across columns, list them in order: last/first/middle or first/last — whatever the file uses),
  "email":   "column name or null",
  "type":    "column name or null",
  "course":  "column name or null",
  "result":  "column name or null",
  "score":   "column name or null",
  "total":   "column name or null",
  "remarks": "column name or null"
}

Rules:
- Use the EXACT header string as it appears in the CSV (case-sensitive)
- If a field has no matching column, use null
- For name: prefer a single full-name column; if absent, list the part-columns in display order
- "type" means applicant type (freshman / transferee / foreign)
- "result" means admission outcome (accepted / rejected / waitlisted)
- "score" / "total" are exam scores
- Filipino header synonyms: Pangalan=name, Kurso=course, Uri=type, Resulta=result`;

// ─────────────────────────────────────────────────────────────
//  PHASE 2  — client-side row mapping (no AI needed)
// ─────────────────────────────────────────────────────────────
const RESULT_MAP = {
    accepted:   ['accepted','passed','admitted','pass','admit','approved','qualified','pumasa','yes','✓','1'],
    waitlisted: ['waitlisted','waitlist','wait','pending','hold','conditional','for interview','wl'],
    rejected:   ['rejected','failed','did not pass','fail','not qualified','bumagsak','hindi pumasa','no','✗','0'],
};
const TYPE_MAP = {
    freshman:   ['freshman','fresh','grade 12','g12','hs','senior high','shs','sh','grade12','1st year','new student','new'],
    transferee: ['transferee','transfer','trans','2nd year','3rd year'],
    foreign:    ['foreign','international','intl','foreigner',"int'l"],
};

function normalizeLookup(val, map, fallback) {
    if (val == null) return fallback;
    const v = String(val).toLowerCase().trim();
    for (const [key, aliases] of Object.entries(map)) {
        if (aliases.some(a => v.includes(a))) return key;
    }
    return fallback;
}

function normalizeCourse(raw) {
    if (!raw) return '';
    const v = raw.trim();
    const lower = v.toLowerCase();
    // Exact match first
    const exact = LEGACY_COURSES.find(c => c.toLowerCase() === lower);
    if (exact) return exact;
    // Abbreviation in parentheses match e.g. "(BSIT)"
    const abbr = v.match(/\(([^)]+)\)/)?.[1]?.toUpperCase();
    if (abbr) {
        const m = LEGACY_COURSES.find(c => c.toUpperCase().includes('(' + abbr + ')'));
        if (m) return m;
    }
    // Keyword match: check if raw appears inside any course string
    const keyword = LEGACY_COURSES.find(c => c.toLowerCase().includes(lower) || lower.includes(c.toLowerCase().replace(/\s*\([^)]+\)\s*/,'').toLowerCase().trim()));
    if (keyword) return keyword;
    return v; // keep original if no match
}

function applySchemaToRow(rowObj, schema) {
    // Build name
    let name = '';
    if (schema.name && rowObj[schema.name] != null) {
        name = String(rowObj[schema.name]).trim();
    } else if (Array.isArray(schema.name_parts)) {
        name = schema.name_parts.map(p => (rowObj[p] ?? '')).join(' ').replace(/\s+/g,' ').trim();
    }

    const email   = schema.email   ? String(rowObj[schema.email]  ?? '').trim() : '';
    const typeRaw = schema.type    ? String(rowObj[schema.type]   ?? '').trim() : '';
    const courseR = schema.course  ? String(rowObj[schema.course] ?? '').trim() : '';
    const resultR = schema.result  ? String(rowObj[schema.result] ?? '').trim() : '';
    const scoreR  = schema.score   ? rowObj[schema.score]  : null;
    const totalR  = schema.total   ? rowObj[schema.total]  : null;
    const remR    = schema.remarks ? String(rowObj[schema.remarks] ?? '').trim() : '';

    const score = (scoreR != null && scoreR !== '') ? parseFloat(scoreR) : null;
    const total = (totalR != null && totalR !== '') ? parseFloat(totalR) : null;

    return {
        name:    name,
        email:   email,
        type:    normalizeLookup(typeRaw, TYPE_MAP, 'freshman'),
        course:  normalizeCourse(courseR),
        result:  normalizeLookup(resultR, RESULT_MAP, 'rejected'),
        score:   isNaN(score) ? null : score,
        total:   isNaN(total) ? null : total,
        remarks: remR,
    };
}

// Parse CSV text into array of {header: value} objects
function parseCsvToObjects(text) {
    const lines = text.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) return { headers: [], rows: [] };
    // Simple CSV parser (handles quoted fields)
    function parseLine(line) {
        const fields = [];
        let cur = '', inQ = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') { inQ = !inQ; }
            else if (ch === ',' && !inQ) { fields.push(cur.trim()); cur = ''; }
            else cur += ch;
        }
        fields.push(cur.trim());
        return fields;
    }
    const headers = parseLine(lines[0]);
    const rows = [];
    for (let i = 1; i < lines.length; i++) {
        const vals = parseLine(lines[i]);
        if (vals.every(v => !v)) continue; // skip blank rows
        const obj = {};
        headers.forEach((h, idx) => { obj[h] = vals[idx] ?? ''; });
        rows.push(obj);
    }
    return { headers, rows };
}

// ─────────────────────────────────────────────────────────────
//  Puter helpers
// ─────────────────────────────────────────────────────────────
async function puterChat(prompt) {
    await loadPuterIfNeeded();
    const ok = await puter.auth.isSignedIn();
    if (!ok) await puter.auth.signIn();
    const response = await puter.ai.chat(prompt, { model: 'claude-sonnet-4-6' });
    return extractLegacyText(response);
}

// Detect column schema via AI (one small call)
async function detectSchema(headers, sampleRows) {
    const sampleCsv = [headers.join(',')]
        .concat(sampleRows.slice(0,5).map(r => headers.map(h => r[h] ?? '').join(',')))
        .join('\n');
    const raw = await puterChat(SCHEMA_PROMPT + '\n\nHEADERS AND SAMPLE ROWS:\n' + sampleCsv);
    // Parse the returned JSON object
    const cleaned = raw.trim().replace(/^```(?:json)?\s*/i,'').replace(/\s*```\s*$/i,'').trim();
    const start = cleaned.indexOf('{'), end = cleaned.lastIndexOf('}');
    if (start === -1 || end === -1) throw new Error('AI did not return a valid schema map.');
    return JSON.parse(cleaned.slice(start, end + 1));
}

// Full-file call for unstructured content (PDF, image, DOCX) — JSONL output
const LEGACY_AI_PROMPT = `You are an expert data extraction assistant for a Philippine university admissions system.

Your task: extract ALL student admission records from the uploaded file (which may be a messy old Excel, CSV, PDF, table image, or hand-keyed spreadsheet).

Output ONE JSON object per line (JSONL). Each line must be a complete, self-contained JSON object. No wrapping array. No markdown. No prose.

Each object:
{"name":"Full Name","email":"email or empty","type":"freshman|transferee|foreign","course":"exact course","result":"accepted|waitlisted|rejected","score":numeric_or_null,"total":numeric_or_null,"remarks":"notes or empty"}

NORMALIZATION:
type:   freshman←SHS/G12/Fresh/1st year | transferee←Transfer/2nd-3rd year | foreign←International
result: accepted←Passed/Admitted/Qualified/PUMASA | waitlisted←Pending/WL/Conditional | rejected←Failed/Did not pass/BUMAGSAK
course: normalize to closest from: ${LEGACY_COURSES.join(', ')}
score:  "87/100"→score=87,total=100 | "87%"→score=87,total=100 | "87"→score=87,total=null

If no records found, output nothing.`;

async function callLegacyPuterWithImage(file) {
    if (!file || !file.size) throw new Error('Image file is empty.');
    await loadPuterIfNeeded();
    const ok = await puter.auth.isSignedIn();
    if (!ok) await puter.auth.signIn();
    const tmpName = 'legacy_import_' + Date.now() + '.' + file.name.split('.').pop();
    let puterFile;
    try { puterFile = await puter.fs.write(tmpName, file); }
    catch (e) { throw new Error('Could not upload image to Puter: ' + (e?.message || e)); }
    if (!puterFile?.path) throw new Error('Puter returned no file path. Try again.');
    if (typeof puterFile.size === 'number' && puterFile.size === 0) {
        try { await puter.fs.delete(puterFile.path); } catch(_) {}
        throw new Error('Image upload wrote 0 bytes. Try again.');
    }
    let response;
    try {
        response = await puter.ai.chat(
            [{ role: 'user', content: [{ type: 'file', puter_path: puterFile.path }, { type: 'text', text: LEGACY_AI_PROMPT }] }],
            { model: 'claude-sonnet-4-6' }
        );
    } finally {
        try { await puter.fs.delete(puterFile.path); } catch(_) {}
    }
    return parseLegacyResp(extractLegacyText(response));
}

// ── JSON parse helpers ──
function extractLegacyText(response) {
    if (response && response.success === false) throw new Error('Puter AI error: ' + (response.error || 'unknown'));
    const text = response?.message?.content?.[0]?.text || response?.message?.content || '';
    if (!text) throw new Error('AI returned an empty response.');
    return text;
}
// Parse JSONL response — tolerates a truncated last line
// Walk a string and extract every complete top-level {...} object,
// even if the surrounding array is truncated. Handles escaped chars + nested braces.
function extractCompleteObjects(str) {
    const results = [];
    let depth = 0, start = -1, inStr = false, escape = false;
    for (let i = 0; i < str.length; i++) {
        const ch = str[i];
        if (escape)          { escape = false; continue; }
        if (ch === '\\')     { if (inStr) escape = true; continue; }
        if (ch === '"')       { inStr = !inStr; continue; }
        if (inStr)            { continue; }
        if (ch === '{')       { if (depth++ === 0) start = i; }
        else if (ch === '}')  {
            if (--depth === 0 && start !== -1) {
                try { const obj = JSON.parse(str.slice(start, i + 1));
                      if (obj && typeof obj === 'object' && !Array.isArray(obj)) results.push(obj); }
                catch(_) {}
                start = -1;
            }
        }
    }
    return results;
}

function parseLegacyResp(raw) {
    const cleaned = raw.trim().replace(/^```(?:json)?\s*/i,'').replace(/\s*```\s*$/i,'').trim();

    // Strategy 1: JSONL — one object per line (ideal path)
    const rows = [];
    for (const line of cleaned.split(/\n/)) {
        const t = line.trim().replace(/,$/, '');
        if (!t || t === '[' || t === ']') continue;
        try { const obj = JSON.parse(t); if (obj && typeof obj === 'object' && !Array.isArray(obj)) rows.push(obj); } catch(_) {}
    }
    if (rows.length > 0) return rows;

    // Strategy 2: complete JSON array (not truncated)
    try {
        const s = cleaned.replace(/,(\s*[}\]])/g, '$1');
        const start = s.indexOf('['), end = s.lastIndexOf(']');
        if (start !== -1 && end > start) {
            const a = JSON.parse(s.slice(start, end + 1));
            if (Array.isArray(a) && a.length > 0) return a;
        }
    } catch(_) {}

    // Strategy 3: extract every complete {...} from a truncated array — handles cut-off responses
    const salvaged = extractCompleteObjects(cleaned);
    if (salvaged.length > 0) return salvaged;

    throw new Error('AI response was not valid JSON. Raw:\n' + raw.slice(0, 300));
}

// ── Result badge colours ──
function resultBadge(r) {
    const map = {
        accepted:   'badge-approved',
        waitlisted: 'badge-pending',
        rejected:   'badge-rejected',
    };
    return `<span class="badge ${map[r] || ''}">${r}</span>`;
}

// ── Render preview table ──
function renderLegacyPreview(rows) {
    const tbody = document.getElementById('legacy-preview-tbody');
    tbody.innerHTML = '';
    rows.forEach((row, i) => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid var(--border)';
        const score = (row.score != null && row.total != null)
            ? row.score + '/' + row.total
            : (row.score != null ? String(row.score) : '—');
        tr.innerHTML = `
            <td style="padding:var(--space-2) var(--space-3)">
                <input type="checkbox" class="legacy-row-check" data-idx="${i}" checked>
            </td>
            <td style="padding:var(--space-2) var(--space-3)">${escHtml(row.name || '—')}</td>
            <td style="padding:var(--space-2) var(--space-3);white-space:nowrap">${escHtml(row.type || '—')}</td>
            <td style="padding:var(--space-2) var(--space-3);font-size:var(--text-xs)">${escHtml(row.course || '—')}</td>
            <td style="padding:var(--space-2) var(--space-3)">${resultBadge(row.result)}</td>
            <td style="padding:var(--space-2) var(--space-3);white-space:nowrap">${escHtml(score)}</td>
        `;
        tbody.appendChild(tr);
    });
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function toggleAllLegacyRows(checked) {
    document.querySelectorAll('.legacy-row-check').forEach(cb => cb.checked = checked);
}

// ── Main processing ──
async function startLegacyProcessing() {
    if (!legacySelectedFile) return;

    // Validate school year first
    const syVal = document.getElementById('legacy-sy-input').value.trim();
    const syHint = document.getElementById('legacy-sy-hint');
    if (!syVal.match(/^\d{4}-\d{4}$/)) {
        syHint.textContent = 'Enter a valid school year like 2023-2024.';
        syHint.style.display = '';
        return;
    }
    const [y1, y2] = syVal.split('-').map(Number);
    if (y2 !== y1 + 1) {
        syHint.textContent = 'The two years must be consecutive (e.g. 2023-2024).';
        syHint.style.display = '';
        return;
    }
    syHint.style.display = 'none';

    legacyResetProgress();
    showLegacyStep('processing');
    document.getElementById('legacy-modal-footer').style.display = 'none';

    try {
        if (!legacySelectedFile.size) throw new Error('File is empty (0 bytes).');

        await loadPuterIfNeeded();
        const ext = legacySelectedFile.name.split('.').pop().toLowerCase();
        let content = null, isImage = false;

        legacySetProgress(15, 'Reading file…', 'Loading file from disk');

        if (['jpg', 'jpeg', 'png'].includes(ext)) {
            content = legacySelectedFile;
            isImage = true;
        } else if (ext === 'pdf') {
            legacySetProgress(30, 'Extracting text…', 'Parsing PDF pages');
            await loadPdfJsIfNeeded();
            content = await extractLegacyPdfText(legacySelectedFile);
        } else if (['docx', 'doc'].includes(ext)) {
            legacySetProgress(30, 'Extracting text…', 'Parsing document');
            await loadMammothIfNeeded();
            content = await extractLegacyDocxText(legacySelectedFile);
        } else if (['xlsx', 'xls'].includes(ext)) {
            legacySetProgress(30, 'Extracting text…', 'Reading spreadsheet');
            content = await extractXlsxAsText(legacySelectedFile);
        } else {
            // CSV and plain text
            content = await readLegacyFileAsText(legacySelectedFile);
        }

        if (!content) throw new Error('Could not read this file. Try a different format.');
        if (typeof content === 'string' && content.trim().length < 5) {
            throw new Error('No readable text found. If this is a scanned PDF, save pages as JPG/PNG and upload those.');
        }

        // ── Phase 1: schema detection (CSV/structured) or full AI extraction (images/PDFs) ──
        let rows = [];

        if (isImage) {
            legacySetProgress(40, 'Sending to AI…', 'Uploading image to Puter AI');
            rows = await callLegacyPuterWithImage(content);
        } else {
            // Detect if content looks like CSV/TSV (structured)
            const firstLine = (typeof content === 'string' ? content : '').split(/\r?\n/)[0] || '';
            const isCsv = firstLine.split(',').length >= 3 || firstLine.split('	').length >= 3;

            if (isCsv) {
                // Phase 1: AI detects column schema from header + 5 sample rows (one tiny call)
                legacySetProgress(35, 'Detecting columns…', 'Asking AI to identify column layout');
                const { headers, rows: parsedRows } = parseCsvToObjects(content);
                if (headers.length === 0 || parsedRows.length === 0) {
                    throw new Error('Could not parse the CSV. Make sure it has a header row.');
                }
                const schema = await detectSchema(headers, parsedRows);

                // Phase 2: all rows mapped client-side — no more AI calls needed
                legacySetProgress(60, 'Mapping records…', `Applying column map to ${parsedRows.length} rows`);
                rows = parsedRows
                    .map(r => applySchemaToRow(r, schema))
                    .filter(r => r.name.trim());   // skip rows with no name
            } else {
                // Unstructured (DOCX, plain text): full AI extraction via JSONL
                legacySetProgress(40, 'Sending to AI…', 'Extracting records from document');
                const raw = await puterChat(LEGACY_AI_PROMPT + '\n\nCONTENT:\n\n' + content.slice(0, 12000));
                rows = parseLegacyResp(raw);
            }
        }

        legacySetProgress(100, 'Finalizing…', 'Parsing AI response');
        await new Promise(r => setTimeout(r, 350));

        if (!rows || rows.length === 0) throw new Error('No student records were detected in this file.');

        legacyExtractedRows = rows;
        renderLegacyPreview(rows);
        showLegacyStep('preview');

        const count = rows.length;
        document.getElementById('legacy-preview-count').textContent =
            `${count} record${count !== 1 ? 's' : ''} detected — uncheck any you want to skip`;
        document.getElementById('legacy-save-footer').style.display = 'flex';

    } catch (err) {
        showLegacyStep('error');
        document.getElementById('legacy-modal-footer').style.display = '';
        const msg = typeof err === 'string' ? err : (err?.message || err?.error || 'Unknown error');
        document.getElementById('legacy-error-msg').textContent = msg;
        document.getElementById('legacy-process-btn').disabled = !legacySelectedFile;
    }
}

// ── Save ──
async function saveLegacyRows() {
    const saveBtn = document.getElementById('legacy-save-btn');
    const syVal   = document.getElementById('legacy-sy-input').value.trim();

    // Collect checked rows
    const checkedIndices = Array.from(document.querySelectorAll('.legacy-row-check:checked'))
                                .map(cb => parseInt(cb.dataset.idx));
    if (checkedIndices.length === 0) {
        alert('No rows selected. Check at least one record to save.');
        return;
    }

    const rowsToSave = checkedIndices.map(i => legacyExtractedRows[i]);

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    try {
        const fd = new FormData();
        fd.append('action',      'import_legacy_rows');
        fd.append('school_year', syVal);
        fd.append('rows',        JSON.stringify(rowsToSave));
        fd.append('_csrf',       document.querySelector('input[name=_csrf]')?.value || '');

        const resp = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body:   fd,
        });
        const json = await resp.json();

        if (!json.ok) throw new Error(json.error || 'Server returned an error.');

        const skippedHtml = json.skipped?.length
            ? '<br><small style="color:var(--text-tertiary)">' +
              json.skipped.length + ' row(s) skipped: ' +
              json.skipped.map(s => escHtml(s)).join('; ') + '</small>'
            : '';

        closeLegacyImportModal();

        // Show success alert on the page
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success';
        alertDiv.style.marginBottom = 'var(--space-3)';
        alertDiv.innerHTML =
            `<strong>${json.inserted} record(s) imported</strong> into AY ${escHtml(syVal)}.` +
            ` They now appear in Admin → Results under the ${escHtml(syVal)} filter.` +
            skippedHtml;
        document.querySelector('.admin-form-stack').prepend(alertDiv);

        // Reload after 1.5s so the Applicant History card refreshes
        setTimeout(() => window.location.reload(), 1500);

    } catch (err) {
        alert('Save failed: ' + (err?.message || err));
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Selected Records';
    }
}
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Admissions';
$activeNav = 'school-year';
include VIEWS_PATH . '/layouts/app.php';