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

if (($action = require_post_action()) !== null) {

    if ($action === 'set_admissions_window') {
        $open        = post_str('admissions_open');
        $close       = post_str('admissions_close');
        $docDeadline = post_str('document_deadline');

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

            audit_log(AuditAction::ADMISSIONS_WINDOW_SET,
                "Admissions window set: {$open} to {$close} (AY {$schoolYear})");
            $success[] = "Admissions window saved (AY {$schoolYear}).";
        }
    }

    if ($action === 'new_cycle') {
        $confirm = post_str('confirm_text');
        if (strcasecmp($confirm, 'Start new cycle') !== 0) {
            $errors[] = 'Type "Start new cycle" to confirm.';
        } else {
            $db->exec('UPDATE exams SET is_active=0');
            audit_log(AuditAction::NEW_CYCLE_STARTED, 'Started new admission cycle - exam deactivated.');
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
        $targetSY  = post_str('school_year');
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
            [$placeholders] = sql_in($generatedEmails);
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
                    $skipped[] = "Row " . ($i + 1) . " ({$name}): email {$email} already exists, skipped.";
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
                AuditAction::LEGACY_DATA_IMPORTED,
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
$currentYear       = school_setting('current_school_year', '-');
$isOpen           = admissions_is_open();

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

<div class="sy-card">
<div class="sy-col">

    <!-- Admissions window -->
    <div class="sy-sec">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
            <div class="card-title" style="margin:0">Admissions Window</div>
            <?php if ($isOpen): ?>
                <span class="badge badge-approved">Open</span>
            <?php elseif ($admissionsOpen || $admissionsClose): ?>
                <span class="badge badge-pending">Closed</span>
            <?php else: ?>
                <span class="badge">Not set</span>
            <?php endif; ?>
        </div>

        <form method="POST" id="apw-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_admissions_window">
            <div class="sy-date-grid" style="margin-bottom:var(--space-3)">
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
    </div>

    <!-- ── Import Historical Records ──────────────────────────────── -->
    <div class="sy-sec">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-2)">
            <div class="card-title" style="margin:0">Import Historical Records</div>
            <button class="btn btn-secondary" onclick="openLegacyImportModal()" style="display:flex;align-items:center;gap:6px">
                <?= icon('arrow-circle-down', 14) ?>
                Import with AI
            </button>
        </div>
        <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
            Upload a file of past admissions results and AI will read it for you.
            Imported records show up in <strong>Results</strong> under their school year.
        </p>
    </div>

    <!-- New cycle — destructive, so the action sits behind a confirmation popup -->
    <div class="sy-sec">
        <button type="button" class="btn btn-danger" onclick="openNewCycleModal()">Start New Admission Cycle</button>
    </div>

</div><!-- /sy-col -->
<div class="sy-col">

    <!-- Historical summary -->
    <?php if (!empty($statsByYear)): ?>
    <div class="sy-sec sy-grow">
        <div class="card-title" style="margin-bottom:var(--space-5)">Applicant History</div>
        <div class="sy-years">
        <?php foreach ($statsByYear as $year => $statuses):
            $total = array_sum($statuses);
        ?>
            <div class="sy-year">
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
    <?php else: ?>
    <div class="sy-sec sy-grow">
        <div class="card-title" style="margin-bottom:var(--space-3)">Applicant History</div>
        <p class="card-description" style="margin:0">No applicant history yet.</p>
    </div>
    <?php endif; ?>

</div><!-- /sy-col -->
</div><!-- /sy-card -->

<!-- ════════════════════════════════════════════════════════════
     AI IMPORT MODAL - Legacy Records
     (CSS classes mirror exam/staff_manage.php exactly)
════════════════════════════════════════════════════════════ -->

<!-- Confirm: start new cycle (destructive) -->
<div id="new-cycle-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:440px">
        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="new_cycle">
            <div class="modal-header" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border)">
                <div style="font-weight:var(--weight-semibold);font-size:var(--text-base);color:var(--error)">Start New Admission Cycle</div>
                <button type="button" class="btn-icon" onclick="closeNewCycleModal()">
                    <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
                </button>
            </div>
            <div class="modal-body">
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0 0 var(--space-4)">
                    The current exam will be deactivated. Previous applicant data is preserved.
                </p>
                <label class="form-label" for="new-cycle-confirm">Type <strong>Start new cycle</strong> to confirm</label>
                <input type="text" id="new-cycle-confirm" name="confirm_text" class="form-input"
                       placeholder="Start new cycle" autocomplete="off">
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border)">
                <button type="button" class="btn btn-ghost" onclick="closeNewCycleModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Start New Cycle</button>
            </div>
        </form>
    </div>
</div>

<div id="legacy-import-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:600px;max-height:90vh;display:flex;flex-direction:column">
        <?= csrf_field() ?>

        <!-- Header — same accent-box + subtitle pattern as exam import -->
        <div class="modal-header" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:var(--space-3)">
                <div style="width:34px;height:34px;border-radius:var(--radius);background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('sparkle', 17, 'color:#fff') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Import Historical Records with AI</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px">Upload an old file and AI will read the records</div>
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
                <a id="legacy-puter-link" href="https://puter.com" target="_blank" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);text-decoration:none;white-space:nowrap">Create account <?= icon('ic_fluent_arrow_right_24_regular', 14) ?></a>
                <button id="legacy-puter-signin-btn" onclick="legacyPuterSignIn()" class="btn btn-ghost btn-sm" style="display:none;color:var(--accent)">Sign in <?= icon('ic_fluent_arrow_right_24_regular', 14) ?></button>
                <button id="legacy-puter-signout-btn" onclick="legacyPuterSignOut()" class="btn btn-ghost btn-sm" style="display:none">Sign out</button>
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
                            Current SY: <strong><?= e($currentYear) ?></strong>. Import into a past year only.
                        </span>
                    </div>
                    <div id="legacy-sy-hint" style="display:none;font-size:var(--text-xs);color:var(--error);margin-top:4px"></div>
                </div>

                <!-- Drop zone -->
                <div class="ai-dropzone" id="legacy-drop-zone" role="button" tabindex="0"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                     onclick="document.getElementById('legacy-file-input').click()"
                     ondragover="event.preventDefault();this.classList.add('dragover')"
                     ondragleave="this.classList.remove('dragover')"
                     ondrop="handleLegacyFileDrop(event)">
                    <div class="ai-dropzone-icon">
                        <?= icon('file', 20, 'color:var(--text-tertiary)') ?>
                    </div>
                    <div id="legacy-dropzone-label" style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-secondary)">Drop file here or <span style="color:var(--accent)">click to browse</span></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">CSV, Excel, PDF, DOCX, JPG or PNG. Columns can be in any order.</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">Need a template? <a href="/sample-legacy-admissions.csv" download style="color:var(--accent)" onclick="event.stopPropagation()">Download sample CSV</a></div>
                    <div id="legacy-file-tag-wrap" style="display:none;margin-top:var(--space-3)">
                        <span class="ai-file-tag">
                            <?= icon('file', 12, 'color:#fff') ?>
                            <span id="legacy-file-tag-name"></span>
                            <button class="rm" onclick="event.stopPropagation();clearLegacyFile()" title="Remove"><?= icon('x', 12) ?></button>
                        </span>
                    </div>
                    <input type="file" id="legacy-file-input" aria-label="Choose file to import"
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
                    <button class="btn btn-ghost btn-sm" onclick="resetLegacyImport()"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Try again</button>
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
                <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
                    <div class="table-responsive">
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
            </div>

            <!-- Step: error -->
            <div id="legacy-step-error" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:flex-start;gap:10px;background:var(--error-bg);border:1px solid var(--error);border-radius:var(--radius);padding:12px 14px;font-size:var(--text-sm);color:var(--error)">
                    <?= icon('warning-circle:fill', 16, 'margin-top:1px') ?>
                    <span id="legacy-error-msg"></span>
                </div>
                <button class="btn btn-ghost btn-sm" style="align-self:flex-start" onclick="resetLegacyImport()"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Try again</button>
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
<script>window.PAGE = <?= json_encode([
    'legacyCourses' => $courseNames,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Admissions';
$pageHeading = 'School Year';
$pageStyles  = ['css/pages/admin-school-year.css'];
$pageScripts = ['js/pages/admin-school-year-window.js', 'js/pages/admin-school-year-import.js'];
$pageWide  = true;
$activeNav = 'school-year';
include VIEWS_PATH . '/layouts/app.php';