<?php
// ============================================================
// modules/auth/register.php
// M2 — Authentication: Student Registration
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

// Security verification runs once on page start, not on the form.
captcha_gate('/register');

// Block registration outside the admissions window
if (!admissions_is_open()) {
    $open  = admissions_open_date();
    $close = admissions_close_date();
    $msg   = 'Admissions are currently closed.';
    if ($open && $close) {
        $msg .= ' The application window is ' . $open->format('F j, Y') . ' to ' . $close->format('F j, Y') . '.';
    }
    $closedMsg  = $msg;
    ob_start();
    ?>
    <div class="auth-card animate-fade-in">
        <div class="auth-header">
            <h1 class="auth-title">Registration closed</h1>
            <p class="auth-subtitle">New accounts can't be created right now.</p>
        </div>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($closedMsg) ?>
        </div>
        <div class="auth-footer">
            <a class="auth-back" href="<?= url('/login') ?>"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Back to sign in</a>
        </div>
    </div>
    <?php
    $content   = ob_get_clean();
    $pageTitle = 'Admissions Closed';
    include VIEWS_PATH . '/layouts/auth.php';
    exit;
}

// Google mode: the applicant came back from Google and still has to finish the profile.
$googlePending = null;
if (!empty($_GET['google'])) {
    $gp = GOOGLE_ENABLED ? Session::get('google_pending') : null;
    if (!is_array($gp) || empty($gp['sub']) || empty($gp['email'])) {
        redirect('/register');
    }
    $googlePending = $gp;
}
$googleMode = $googlePending !== null;

$errors = [];
$old    = [];
if ($googleMode) {
    // Names from Google are only a hint; the applicant confirms the legal name.
    $old = ['first_name' => $googlePending['first_name'], 'last_name' => $googlePending['last_name'],
            'email' => $googlePending['email']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old = [
        'first_name'     => post_str('first_name'),
        'middle_name'    => post_str('middle_name'),
        'last_name'      => post_str('last_name'),
        'suffix'         => post_str('suffix'),
        'birthdate'      => post_str('birthdate'),
        'sex'            => post_str('sex'),
        'street_address' => post_str('street_address'),
        'barangay'       => post_str('barangay'),
        'phone'          => post_str('phone'),
        'email'          => $googleMode ? $googlePending['email'] : post_str('email'),
        'applicant_type' => post_str('applicant_type'),
        'course_applied' => post_str('course_applied'),
        'shs_strand'     => post_str('shs_strand'),
    ];
    $old['doc_flags'] = doc_flags_from_input($old['applicant_type'], $_POST['flags'] ?? []);
    $password        = $_POST['password']        ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validate
    if (!$old['first_name'])
        $errors['first_name'] = 'First name is required.';
    if (!$old['last_name'])
        $errors['last_name']  = 'Last name is required.';
    if (!$old['birthdate'] || !strtotime($old['birthdate']))
        $errors['birthdate']  = 'Enter a valid birthdate.';
    if (!in_array($old['sex'], ['M', 'F'], true))
        $errors['sex']        = 'Select M or F.';
    if (!$old['street_address'])
        $errors['street_address'] = 'Street address is required.';

    // Hardcoded list of valid Pasig City barangays
    $validBarangays = [
        'Bagong Ilog', 'Bagong Katipunan', 'Bambang', 'Buting', 'Caniogan',
        'Dela Paz', 'Kalawaan', 'Kapasigan', 'Kapitolyo', 'Malinao',
        'Manggahan', 'Maybunga', 'Oranbo', 'Palatiw', 'Pinagbuhatan',
        'Pineda', 'Rosario', 'Sagad', 'San Antonio', 'San Joaquin',
        'San Jose', 'San Miguel', 'San Nicolas', 'Santa Cruz',
        'Santa Lucia', 'Santa Rosa', 'Santo Tomas', 'Santolan',
        'Sumilang', 'Ugong',
    ];
    if (!in_array($old['barangay'], $validBarangays, true))
        $errors['barangay'] = 'Select a valid barangay in Pasig City.';
    if (!$old['phone'])
        $errors['phone']      = 'Phone number is required.';
    elseif (!preg_match('/^\d{11}$/', $old['phone']))
        $errors['phone']      = 'Enter a valid 11-digit mobile number (e.g. 09123456789).';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))
        $errors['email']      = 'Enter a valid email address.';
    if (!in_array($old['applicant_type'], [TYPE_FRESHMAN, TYPE_TRANSFEREE, TYPE_FOREIGN], true))
        $errors['applicant_type'] = 'Select an applicant type.';
    if (!in_array($old['course_applied'], get_all_courses(), true))
        $errors['course_applied'] = 'Select a valid course.';
    elseif (!empty($old['course_applied'])) {
        // Check course cap at registration time
        try {
            $regYear2 = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
            $capChk = db()->prepare(
                'SELECT cc.max_slots,
                        COUNT(DISTINCT CASE WHEN r.result=\'accepted\' THEN a.id END) AS accepted
                 FROM course_caps cc
                 LEFT JOIN applicants a      ON a.course_applied = cc.course_name AND a.school_year = cc.school_year
                 LEFT JOIN admission_results r ON r.applicant_id = a.id
                 WHERE cc.school_year = ? AND cc.course_name = ? AND cc.max_slots IS NOT NULL
                 GROUP BY cc.max_slots
                 LIMIT 1'
            );
            $capChk->execute([$regYear2, $old['course_applied']]);
            $capRow = $capChk->fetch();
            if ($capRow && (int)$capRow['accepted'] >= (int)$capRow['max_slots']) {
                $errors['course_applied'] = 'This course has reached its enrollment cap for ' . $regYear2 . '. Please choose an available course.';
            }
        } catch (\Throwable $e) { /* ignore - table may not exist yet */ }
    }
    if (!$googleMode && strlen($password) < 8)
        $errors['password']   = 'Password must be at least 8 characters.';
    if (!$googleMode && $password !== $passwordConfirm)
        $errors['password_confirm'] = 'Passwords do not match.';

    // Strand validation for freshmen
    if ($old['applicant_type'] === TYPE_FRESHMAN) {
        if (empty($old['shs_strand'])) {
            $errors['shs_strand'] = 'Select your SHS strand.';
        } elseif (!empty($old['course_applied'])) {
            $allowedStrands = get_all_strand_map()[$old['course_applied']] ?? null;
            if ($allowedStrands !== null && !in_array($old['shs_strand'], $allowedStrands, true)) {
                $errors['shs_strand'] = 'Your strand (' . $old['shs_strand'] . ') is not accepted for '
                    . $old['course_applied'] . '. Accepted: ' . implode(', ', $allowedStrands) . '.';
            }
        }
    }

    // A freshman must say if they are in Grade 12 or a graduate: it decides Form 138 or Form 137.
    if ($old['applicant_type'] === TYPE_FRESHMAN && doc_stage_of($old['doc_flags']) === '') {
        $errors['stage'] = 'Select whether you are currently in Grade 12 or a Senior High School graduate.';
    }

    // Email uniqueness
    if (empty($errors['email'])) {
        $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$old['email']]);
        if ($check->fetch()) $errors['email'] = 'An account with this email already exists.';
    }

    // Duplicate applicant detection (same name + birthdate)
    if (empty($errors)) {
        $dupCheck = db()->prepare(
            'SELECT u.id FROM users u
             WHERE UPPER(u.first_name) = UPPER(?) AND UPPER(u.last_name) = UPPER(?) AND u.birthdate = ?
             LIMIT 1'
        );
        $dupCheck->execute([$old['first_name'], $old['last_name'], $old['birthdate']]);
        if ($dupCheck->fetch()) {
            $errors['general'] = 'An applicant with the same name and birthdate already exists. If this is you, please use your existing account or contact the admissions office.';
        }
    }

    if (empty($errors)) {
        // Force uppercase on name fields
        $old['first_name']    = mb_strtoupper($old['first_name']);
        $old['middle_name']   = mb_strtoupper($old['middle_name']);
        $old['last_name']     = mb_strtoupper($old['last_name']);
        $old['suffix']        = mb_strtoupper($old['suffix']);
        $old['street_address']= mb_strtoupper($old['street_address']);

        $parts = array_filter([
            $old['first_name'],
            $old['middle_name'] ?: null,
            $old['last_name'],
            $old['suffix']      ?: null,
        ]);
        $displayName = implode(' ', $parts);

        $fullAddress = $old['street_address'] . ', Brgy. ' . $old['barangay'] . ', Pasig City';

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $department = course_to_department($old['course_applied']);

            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (name, first_name, middle_name, last_name, suffix,
                     birthdate, sex, address, phone, email, password_hash, role, department,
                     google_sub, email_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 RETURNING id'
            );
            $stmt->execute([
                $displayName,
                $old['first_name'],
                $old['middle_name'],
                $old['last_name'],
                $old['suffix'],
                $old['birthdate'],
                $old['sex'],
                $fullAddress,
                $old['phone'],
                $old['email'],
                // ponytail: Google users get a random unusable hash because the column is NOT NULL. Upgrade: make it nullable.
                $googleMode ? '!' . bin2hex(random_bytes(32)) : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ROLE_STUDENT,
                $department,
                $googleMode ? $googlePending['sub'] : null,
                $googleMode ? 1 : 0,
            ]);
            $userId = (int) $stmt->fetchColumn();

            $schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
            $stmt = $pdo->prepare(
                'INSERT INTO applicants (user_id, applicant_type, course_applied, shs_strand, overall_status, school_year, doc_flags)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)
                 RETURNING id'
            );
            $stmt->execute([
                $userId,
                $old['applicant_type'],
                $old['course_applied'],
                $old['applicant_type'] === TYPE_FRESHMAN ? ($old['shs_strand'] ?: null) : null,
                'pending',
                $schoolYear,
                json_encode($old['doc_flags']),
            ]);
            $applicantId = (int) $stmt->fetchColumn();

            $docs    = docs_for_type($old['applicant_type'], $old['doc_flags']);
            $docStmt = $pdo->prepare(
                'INSERT INTO documents (applicant_id, doc_type, status) VALUES (?, ?, ?)'
            );
            foreach (array_keys($docs) as $slug) {
                $docStmt->execute([$applicantId, $slug, 'pending']);
            }

            $pdo->commit();

            // Log the department assignment so auditors can trace
            // course → college decisions after the fact.
            if ($department !== '') {
                audit_log(
                    AuditAction::USER_DEPARTMENT_ASSIGNED,
                    "Registered user #{$userId} assigned to {$department} from course '" . $old['course_applied'] . "'",
                    'user',
                    $userId
                );
            }

            // Google mode: the email is already verified, so skip the code and sign in.
            if ($googleMode) {
                $remember = !empty($googlePending['remember']);
                Session::remove('google_pending');
                $newUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $newUser->execute([$userId]);
                Auth::login($newUser->fetch(), $remember);
                audit_log(AuditAction::LOGIN_GOOGLE, "Google sign up: {$old['email']}", 'user', $userId);
                header('Location: ' . Auth::homeUrl());
                exit;
            }

            // Send verification email — both magic link and 6-digit code
            $verifyCreds = generate_verify_credentials($userId);
            send_verification_email($old['email'], $displayName, $verifyCreds['token'], $verifyCreds['code']);

            // Don't auto-login — require email verification first.
            // Stash the email so /verify-pending can pre-fill the form.
            Session::set('verify_pending_email', $old['email']);
            Session::flash('success', 'Account created! Check your email for a verification code.');
            redirect('/verify-pending');

        } catch (Throwable $e) {
            // The transaction may already be committed (e.g. a failure in the
            // post-commit verification email step). Only roll back if there's
            // still an active transaction.
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (\Throwable) { /* ignore */ }
            }
            error_log('Registration error: ' . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }
}

// -- View -------------------------------------------------------
// Load course caps and accepted counts for the current school year
$regSchoolYear = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$fullCourses   = [];   // courses that have hit their cap
try {
    $capData = db()->prepare(
        'SELECT cc.course_name, cc.max_slots,
                COUNT(DISTINCT CASE WHEN r.result=\'accepted\' THEN a.id END) AS accepted
         FROM course_caps cc
         LEFT JOIN applicants a      ON a.course_applied = cc.course_name AND a.school_year = cc.school_year
         LEFT JOIN admission_results r ON r.applicant_id = a.id
         WHERE cc.school_year = ? AND cc.max_slots IS NOT NULL
         GROUP BY cc.course_name, cc.max_slots'
    );
    $capData->execute([$regSchoolYear]);
    foreach ($capData->fetchAll() as $row) {
        if ((int)$row['accepted'] >= (int)$row['max_slots']) {
            $fullCourses[] = $row['course_name'];
        }
    }
} catch (\Throwable $e) { /* table may not exist yet */ }
ob_start();
?>
<div class="auth-card auth-card-wide animate-fade-in">

    <div class="reg-head">
        <div class="auth-header">
            <h1 class="auth-title">Create your account</h1>
            <p class="auth-subtitle">Fill in your details to start your application.</p>
        </div>
        <?php if (!$googleMode && GOOGLE_ENABLED): ?>
            <a class="auth-google" href="<?= url('/auth/google') ?>">
                <?= google_logo_svg() ?>
                Sign up with Google
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" role="alert">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <?php if ($googleMode): ?>
        <div class="alert alert-info" role="status">
            <?= icon('ic_fluent_info_24_regular', 16) ?>
            <span>
                Signed in with Google as <strong><?= e($googlePending['email']) ?></strong>.
                Finish your details to create your account.
                <a class="auth-link" href="<?= url('/register') ?>">Use a different account</a>
            </span>
        </div>
    <?php elseif (GOOGLE_ENABLED): ?>
        <div class="auth-divider"><span>or fill in the form</span></div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/register' . ($googleMode ? '?google=1' : '')) ?>" data-once novalidate>
        <?= csrf_field() ?>

        <!-- Rows of two, so both columns always line up -->
        <div class="reg-pair">
            <!-- Applicant Type -->
            <div class="form-group">
                <label class="form-label" for="applicant_type">Applicant Type <span>*</span></label>
                <select id="applicant_type" name="applicant_type"
                    class="form-select <?= isset($errors['applicant_type']) ? 'error' : '' ?>" required
                    onchange="onTypeChange(this.value)">
                    <option value="">Select…</option>
                    <option value="freshman"   <?= ($old['applicant_type'] ?? '') === 'freshman'   ? 'selected' : '' ?>>Freshman (Graduating / Graduate of Senior HS)</option>
                    <option value="transferee" <?= ($old['applicant_type'] ?? '') === 'transferee' ? 'selected' : '' ?>>Transferee (Incoming 2nd–3rd Year)</option>
                    <option value="foreign"    <?= ($old['applicant_type'] ?? '') === 'foreign'    ? 'selected' : '' ?>>Foreign Student</option>
                </select>
                <?php if (!empty($errors['applicant_type'])): ?>
                    <span class="form-error"><?= e($errors['applicant_type']) ?></span>
                <?php endif; ?>
            </div>
            <!-- Course -->
            <div class="form-group">
                <label class="form-label" for="course_applied">Course Applying For <span>*</span></label>
                <select id="course_applied" name="course_applied"
                    class="form-select <?= isset($errors['course_applied']) ? 'error' : '' ?>" required
                    onchange="onCourseChange(this.value)">
                    <option value="">Select…</option>
                    <?php foreach (get_all_courses() as $course):
                        $isFull = in_array($course, $fullCourses, true);
                    ?>
                        <option value="<?= e($course) ?>"
                            <?= ($old['course_applied'] ?? '') === $course ? 'selected' : '' ?>
                            <?= $isFull ? 'disabled' : '' ?>>
                            <?= e($course) ?><?= $isFull ? ' - FULL' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['course_applied'])): ?>
                    <span class="form-error"><?= e($errors['course_applied']) ?></span>
                <?php endif; ?>
                <?php if (!empty($fullCourses)): ?>
                    <span class="form-hint">
                        Courses marked <strong>FULL</strong> have reached their enrollment cap for <?= e($regSchoolYear) ?> and are no longer accepting applications.
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="reg-pair">
            <!-- SHS Strand — only shown for freshmen; options filtered by course via JS -->
            <div id="strand-group" class="form-group" style="display:<?= ($old['applicant_type'] ?? '') === 'freshman' ? 'flex' : 'none' ?>">
                <label class="form-label" for="shs_strand">SHS Strand <span>*</span></label>
                <select id="shs_strand" name="shs_strand"
                    class="form-select <?= isset($errors['shs_strand']) ? 'error' : '' ?>">
                    <option value="">Select…</option>
                </select>
                <?php if (!empty($errors['shs_strand'])): ?>
                    <span class="form-error"><?= e($errors['shs_strand']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="reg-pair reg-info">
            <div id="qual-freshman" class="qual-box" style="display:<?= ($old['applicant_type'] ?? '') === 'freshman' ? 'block' : 'none' ?>">
                <div class="qual-box-title">Freshman Qualifications</div>
                <ul class="qual-list">
                    <li>At least <strong>80% General Weighted Average (GWA)</strong></li>
                    <li>Graduate of Senior High School or currently enrolled in Grade 12 SHS</li>
                    <li>Applicant, Parent, or Guardian must be a resident of Pasig City for at least <strong>five (5) years</strong></li>
                </ul>
            </div>
            <div id="qual-transferee" class="qual-box" style="display:<?= ($old['applicant_type'] ?? '') === 'transferee' ? 'block' : 'none' ?>">
                <div class="qual-box-title">Transferee Qualifications</div>
                <ul class="qual-list">
                    <li>Incoming 2nd–3rd year ONLY from any CHED-accredited college/university (at least 36 units taken)</li>
                    <li>Graduated under the K–12 Program</li>
                    <li>GWA of <strong>2.25 or higher</strong></li>
                    <li>No Failing, Dropped, or Incomplete Grades</li>
                    <li>No Shifting of Program</li>
                    <li>Parent/Guardian or Applicant must be a resident of Pasig City for at least <strong>five (5) years</strong></li>
                </ul>
            </div>
            <!-- Which conditional documents apply (shown by jQuery once a type is picked) -->
            <div id="doc-flags" class="qual-box" style="display:none">
                <div class="qual-box-title">Which of these apply to you?</div>
                <p class="form-hint" style="margin:0 0 var(--space-3)">
                    This decides which documents you need to upload. You can change it later on the documents page until you submit.
                </p>
                <?php $_stage = doc_stage_of($old['doc_flags'] ?? []); ?>
                <!-- Freshmen pick exactly one (required); it decides Form 138 or Form 137 -->
                <div data-types="freshman" role="radiogroup" aria-required="true" style="margin-bottom:var(--space-3)">
                    <div style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--text-secondary);margin-bottom:var(--space-2)">Select one <span style="color:var(--error)">*</span></div>
                    <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                        <label class="form-check">
                            <input type="radio" name="flags[stage]" value="grade12" <?= $_stage === 'grade12' ? 'checked' : '' ?>>
                            <span>I am currently in Grade 12</span>
                        </label>
                        <label class="form-check">
                            <input type="radio" name="flags[stage]" value="shs_grad" <?= $_stage === 'shs_grad' ? 'checked' : '' ?>>
                            <span>I am a Senior High School graduate</span>
                        </label>
                    </div>
                    <?php if (!empty($errors['stage'])): ?>
                        <span class="form-error"><?= e($errors['stage']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--text-secondary);margin-bottom:var(--space-2)">Also tick if they apply</div>
                <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                    <label class="form-check">
                        <input type="checkbox" name="flags[married]" value="1" <?= !empty($old['doc_flags']['married']) ? 'checked' : '' ?>>
                        <span>I am married</span>
                    </label>
                    <label class="form-check">
                        <input type="checkbox" name="flags[guardian]" value="1" <?= !empty($old['doc_flags']['guardian']) ? 'checked' : '' ?>>
                        <span>I am not living with my parents</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="reg-pair">
            <div class="form-group">
                <label class="form-label" for="first_name">First Name <span>*</span></label>
                <input type="text" id="first_name" name="first_name"
                    class="form-input <?= isset($errors['first_name']) ? 'error' : '' ?>"
                    value="<?= e($old['first_name'] ?? '') ?>"
                    autocomplete="given-name" required>
                <?php if (!empty($errors['first_name'])): ?>
                    <span class="form-error"><?= e($errors['first_name']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="middle_name">Middle Name</label>
                <input type="text" id="middle_name" name="middle_name"
                    class="form-input"
                    value="<?= e($old['middle_name'] ?? '') ?>"
                    autocomplete="additional-name">
            </div>
        </div>

        <div class="reg-pair">
            <div class="form-group">
                <label class="form-label" for="last_name">Last Name <span>*</span></label>
                <input type="text" id="last_name" name="last_name"
                    class="form-input <?= isset($errors['last_name']) ? 'error' : '' ?>"
                    value="<?= e($old['last_name'] ?? '') ?>"
                    autocomplete="family-name" required>
                <?php if (!empty($errors['last_name'])): ?>
                    <span class="form-error"><?= e($errors['last_name']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="suffix">Suffix</label>
                <input type="text" id="suffix" name="suffix"
                    class="form-input"
                    value="<?= e($old['suffix'] ?? '') ?>"
                    autocomplete="honorific-suffix"
                    maxlength="8">
            </div>
        </div>

        <div class="reg-pair">
            <div class="form-group">
                <label class="form-label" for="birthdate">Birthdate <span>*</span></label>
                <input type="date" id="birthdate" name="birthdate"
                    class="form-input <?= isset($errors['birthdate']) ? 'error' : '' ?>"
                    value="<?= e($old['birthdate'] ?? '') ?>"
                    max="<?= date('Y-m-d', strtotime('-15 years')) ?>"
                    required>
                <?php if (!empty($errors['birthdate'])): ?>
                    <span class="form-error"><?= e($errors['birthdate']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <span class="form-label">Sex <span>*</span></span>
                <div class="sex-toggle <?= isset($errors['sex']) ? 'error' : '' ?>">
                    <input type="hidden" name="sex" id="sex_value" value="<?= e($old['sex'] ?? '') ?>">
                    <button type="button" class="sex-btn <?= ($old['sex'] ?? '') === 'M' ? 'active' : '' ?>"
                        onclick="setSex('M')">M</button>
                    <button type="button" class="sex-btn <?= ($old['sex'] ?? '') === 'F' ? 'active' : '' ?>"
                        onclick="setSex('F')">F</button>
                </div>
                <?php if (!empty($errors['sex'])): ?>
                    <span class="form-error"><?= e($errors['sex']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="reg-pair">
            <div class="form-group">
                <label class="form-label" for="street_address">Address <span>*</span></label>
                <input type="text" id="street_address" name="street_address"
                    class="form-input <?= isset($errors['street_address']) ? 'error' : '' ?>"
                    value="<?= e($old['street_address'] ?? '') ?>"
                    placeholder="House No., Building, Street"
                    autocomplete="address-line1" required>
                <?php if (!empty($errors['street_address'])): ?>
                    <span class="form-error"><?= e($errors['street_address']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="barangay">Barangay <span>*</span></label>
                <select id="barangay" name="barangay"
                    class="form-select <?= isset($errors['barangay']) ? 'error' : '' ?>" required>
                    <option value="">Select…</option>
                    <?php
                    $pasigBarangays = [
                        'Bagong Ilog', 'Bagong Katipunan', 'Bambang', 'Buting', 'Caniogan',
                        'Dela Paz', 'Kalawaan', 'Kapasigan', 'Kapitolyo', 'Malinao',
                        'Manggahan', 'Maybunga', 'Oranbo', 'Palatiw', 'Pinagbuhatan',
                        'Pineda', 'Rosario', 'Sagad', 'San Antonio', 'San Joaquin',
                        'San Jose', 'San Miguel', 'San Nicolas', 'Santa Cruz',
                        'Santa Lucia', 'Santa Rosa', 'Santo Tomas', 'Santolan',
                        'Sumilang', 'Ugong',
                    ];
                    foreach ($pasigBarangays as $brgy): ?>
                        <option value="<?= e($brgy) ?>"
                            <?= ($old['barangay'] ?? '') === $brgy ? 'selected' : '' ?>>
                            <?= e($brgy) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['barangay'])): ?>
                    <span class="form-error"><?= e($errors['barangay']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="reg-pair">
            <div class="form-group">
                <label class="form-label" for="email">Email Address <span>*</span></label>
                <input type="email" id="email" name="email"
                    class="form-input <?= isset($errors['email']) ? 'error' : '' ?>"
                    value="<?= e($old['email'] ?? '') ?>"
                    placeholder="you@example.com"
                    <?= $googleMode ? 'readonly' : '' ?>
                    autocomplete="email" required>
                <?php if (!empty($errors['email'])): ?>
                    <span class="form-error"><?= e($errors['email']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="phone">Phone Number <span>*</span></label>
                <input type="tel" id="phone" name="phone"
                    class="form-input <?= isset($errors['phone']) ? 'error' : '' ?>"
                    value="<?= e($old['phone'] ?? '') ?>"
                    autocomplete="tel"
                    maxlength="11"
                    inputmode="numeric"
                    placeholder="09123456789"
                    oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"
                    required>
                <?php if (!empty($errors['phone'])): ?>
                    <span class="form-error"><?= e($errors['phone']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$googleMode): ?>
        <div class="reg-pair">
            <div class="form-group">
                <label class="form-label" for="password">Password <span>*</span></label>
                <input type="password" id="password" name="password"
                    class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                    placeholder="Min. 8 characters"
                    autocomplete="new-password" required>
                <?php if (!empty($errors['password'])): ?>
                    <span class="form-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="password_confirm">Confirm Password <span>*</span></label>
                <input type="password" id="password_confirm" name="password_confirm"
                    class="form-input <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                    placeholder="Repeat password"
                    autocomplete="new-password" required>
                <?php if (!empty($errors['password_confirm'])): ?>
                    <span class="form-error"><?= e($errors['password_confirm']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="reg-submit">
            <button type="submit" class="btn btn-primary btn-lg">Create account</button>
        </div>
    </form>

</div>

<script type="application/json" id="reg-data"><?= json_encode([
    'strandMap'   => get_all_strand_map(),
    'allStrands'  => SHS_STRANDS,
    'savedStrand' => $old['shs_strand'] ?? '',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php
$content        = ob_get_clean();
$pageTitle      = 'Create Account';
$authWide       = true;
$pageStyles      = ['css/pages/auth-register.css'];
$pageScripts     = ['js/pages/auth-register.js', 'js/pages/auth-register-validate.js'];
$authTopbarLink = ['text' => 'Already have an account?', 'label' => 'Sign in', 'href' => url('/login')];
include VIEWS_PATH . '/layouts/auth.php';
