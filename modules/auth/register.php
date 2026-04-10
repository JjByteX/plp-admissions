<?php
// ============================================================
// modules/auth/register.php
// M2 — Authentication: Student Registration
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old = [
        'first_name'     => trim($_POST['first_name']     ?? ''),
        'middle_name'    => trim($_POST['middle_name']    ?? ''),
        'last_name'      => trim($_POST['last_name']      ?? ''),
        'suffix'         => trim($_POST['suffix']         ?? ''),
        'birthdate'      => trim($_POST['birthdate']      ?? ''),
        'sex'            => trim($_POST['sex']            ?? ''),
        'street_address' => trim($_POST['street_address'] ?? ''),
        'barangay'       => trim($_POST['barangay']       ?? ''),
        'phone'          => trim($_POST['phone']          ?? ''),
        'email'          => trim($_POST['email']          ?? ''),
        'applicant_type' => trim($_POST['applicant_type'] ?? ''),
        'course_applied' => trim($_POST['course_applied'] ?? ''),
        'shs_strand'     => trim($_POST['shs_strand']     ?? ''),
    ];
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
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))
        $errors['email']      = 'Enter a valid email address.';
    if (!in_array($old['applicant_type'], [TYPE_FRESHMAN, TYPE_TRANSFEREE, TYPE_FOREIGN], true))
        $errors['applicant_type'] = 'Select an applicant type.';
    if (!in_array($old['course_applied'], PLP_COURSES, true))
        $errors['course_applied'] = 'Select a valid course.';
    if (strlen($password) < 8)
        $errors['password']   = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm)
        $errors['password_confirm'] = 'Passwords do not match.';

    // Strand validation for freshmen
    if ($old['applicant_type'] === TYPE_FRESHMAN) {
        if (empty($old['shs_strand'])) {
            $errors['shs_strand'] = 'Select your SHS strand.';
        } elseif (!empty($old['course_applied'])) {
            $allowedStrands = COURSE_STRAND_MAP[$old['course_applied']] ?? null;
            if ($allowedStrands !== null && !in_array($old['shs_strand'], $allowedStrands, true)) {
                $errors['shs_strand'] = 'Your strand (' . $old['shs_strand'] . ') is not accepted for '
                    . $old['course_applied'] . '. Accepted: ' . implode(', ', $allowedStrands) . '.';
            }
        }
    }

    // Email uniqueness
    if (empty($errors['email'])) {
        $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$old['email']]);
        if ($check->fetch()) $errors['email'] = 'An account with this email already exists.';
    }

    if (empty($errors)) {
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
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (name, first_name, middle_name, last_name, suffix,
                     birthdate, sex, address, phone, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ROLE_STUDENT,
            ]);
            $userId = (int) $pdo->lastInsertId();

            $schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
            $stmt = $pdo->prepare(
                'INSERT INTO applicants (user_id, applicant_type, course_applied, overall_status, school_year)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $old['applicant_type'],
                $old['course_applied'],
                'pending',
                $schoolYear,
            ]);
            $applicantId = (int) $pdo->lastInsertId();

            $docs    = docs_for_type($old['applicant_type']);
            $docStmt = $pdo->prepare(
                'INSERT INTO documents (applicant_id, doc_type, status) VALUES (?, ?, ?)'
            );
            foreach (array_keys($docs) as $slug) {
                $docStmt->execute([$applicantId, $slug, 'pending']);
            }

            $pdo->commit();

            $user = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $user->execute([$userId]);
            Auth::login($user->fetch());

            Session::flash('success', 'Account created successfully. Please upload your documents to continue.');
            redirect('/student/documents');

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Registration error: ' . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }
}

// -- View -------------------------------------------------------
$schoolLogo = school_setting('school_logo', '');
ob_start();
?>
<div class="auth-card animate-fade-in" style="max-width:560px">

    <button class="auth-theme-toggle" onclick="Theme.toggle()" aria-label="Toggle theme">
        <svg data-theme-icon="dark" class="hidden" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        <svg data-theme-icon="light" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>

    <div class="auth-header">
        <?php if ($schoolLogo): ?>
            <img src="<?= url($schoolLogo) ?>" alt="School Logo" class="auth-logo-img">
        <?php else: ?>
            <div class="auth-logo">
                <?php include VIEWS_PATH . '/partials/icons/school.svg.php'; ?>
            </div>
        <?php endif; ?>
        <div class="auth-header-text">
            <h1 class="auth-title">PLP Admissions</h1>
            <p class="auth-subtitle">Pamantasan ng Lungsod ng Pasig</p>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/register') ?>" data-once novalidate>
        <?= csrf_field() ?>

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

        <!-- Course -->
        <div class="form-group">
            <label class="form-label" for="course_applied">Course Applying For <span>*</span></label>
            <select id="course_applied" name="course_applied"
                class="form-select <?= isset($errors['course_applied']) ? 'error' : '' ?>" required
                onchange="onCourseChange(this.value)">
                <option value="">Select…</option>
                <?php foreach (PLP_COURSES as $course): ?>
                    <option value="<?= e($course) ?>"
                        <?= ($old['course_applied'] ?? '') === $course ? 'selected' : '' ?>>
                        <?= e($course) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['course_applied'])): ?>
                <span class="form-error"><?= e($errors['course_applied']) ?></span>
            <?php endif; ?>
        </div>

        <!-- SHS Strand — only shown for freshmen; options filtered by course via JS -->
        <div id="strand-group" class="form-group" style="display:<?= ($old['applicant_type'] ?? '') === 'freshman' ? 'block' : 'none' ?>">
            <label class="form-label" for="shs_strand">SHS Strand <span>*</span></label>
            <select id="shs_strand" name="shs_strand"
                class="form-select <?= isset($errors['shs_strand']) ? 'error' : '' ?>">
                <option value="">Select…</option>
            </select>
            <?php if (!empty($errors['shs_strand'])): ?>
                <span class="form-error"><?= e($errors['shs_strand']) ?></span>
            <?php endif; ?>
        </div>

        <!-- Row: First / Middle / Last / Suffix — all in one row -->
        <div class="reg-row-names">
            <div class="form-group">
                <label class="form-label" for="first_name">First Name <span>*</span></label>
                <input type="text" id="first_name" name="first_name"
                    class="form-input <?= isset($errors['first_name']) ? 'error' : '' ?>"
                    value="<?= e($old['first_name'] ?? '') ?>"
                    placeholder=""
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
                    placeholder=""
                    autocomplete="additional-name">
            </div>
            <div class="form-group">
                <label class="form-label" for="last_name">Last Name <span>*</span></label>
                <input type="text" id="last_name" name="last_name"
                    class="form-input <?= isset($errors['last_name']) ? 'error' : '' ?>"
                    value="<?= e($old['last_name'] ?? '') ?>"
                    placeholder=""
                    autocomplete="family-name" required>
                <?php if (!empty($errors['last_name'])): ?>
                    <span class="form-error"><?= e($errors['last_name']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group reg-suffix-col">
                <label class="form-label" for="suffix">Suffix</label>
                <input type="text" id="suffix" name="suffix"
                    class="form-input"
                    value="<?= e($old['suffix'] ?? '') ?>"
                    placeholder=""
                    autocomplete="honorific-suffix"
                    maxlength="8">
            </div>
        </div>

        <!-- Row: Birthdate / Sex -->
        <div class="reg-row reg-row-birthdate">
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
                <label class="form-label">Sex <span>*</span></label>
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

        <!-- Row: Address / Barangay -->
        <div class="reg-row reg-row-address">
            <div class="form-group">
                <label class="form-label" for="street_address">Address <span>*</span></label>
                <input type="text" id="street_address" name="street_address"
                    class="form-input <?= isset($errors['street_address']) ? 'error' : '' ?>"
                    value="<?= e($old['street_address'] ?? '') ?>"
                    placeholder="House No., Building, Street"
                    autocomplete="street-address" required>
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

        <!-- Row: Email / Phone -->
        <div class="reg-row">
            <div class="form-group">
                <label class="form-label" for="email">Email Address <span>*</span></label>
                <input type="email" id="email" name="email"
                    class="form-input <?= isset($errors['email']) ? 'error' : '' ?>"
                    value="<?= e($old['email'] ?? '') ?>"
                    placeholder=""
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
                    placeholder=""
                    autocomplete="tel"
                    maxlength="10"
                    oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                    required>
                <?php if (!empty($errors['phone'])): ?>
                    <span class="form-error"><?= e($errors['phone']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="reg-row">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="password">Password <span>*</span></label>
                <div class="input-wrapper has-suffix">
                    <input type="password" id="password" name="password"
                        class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                        placeholder="Min. 8 characters"
                        autocomplete="new-password" required>
                    <button type="button" class="input-suffix-icon btn-pw-toggle"
                        onclick="togglePw('password',this)" tabindex="-1" aria-label="Show password">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                    </button>
                </div>
                <?php if (!empty($errors['password'])): ?>
                    <span class="form-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="password_confirm">Confirm Password <span>*</span></label>
                <div class="input-wrapper has-suffix">
                    <input type="password" id="password_confirm" name="password_confirm"
                        class="form-input <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                        placeholder=""
                        autocomplete="new-password" required>
                    <button type="button" class="input-suffix-icon btn-pw-toggle"
                        onclick="togglePw('password_confirm',this)" tabindex="-1" aria-label="Show password">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                    </button>
                </div>
                <?php if (!empty($errors['password_confirm'])): ?>
                    <span class="form-error"><?= e($errors['password_confirm']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:var(--space-6)">
            Create account
        </button>
    </form>

    <div class="auth-footer">
        Already have an account?
        <a href="<?= url('/login') ?>">Sign in</a>
    </div>

</div>

<script>
const strandMap  = <?= json_encode(COURSE_STRAND_MAP) ?>;
const allStrands = <?= json_encode(SHS_STRANDS) ?>;

function setSex(val) {
    document.getElementById('sex_value').value = val;
    document.querySelectorAll('.sex-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.textContent.trim() === val);
    });
}

function onTypeChange(type) {
    document.getElementById('qual-freshman').style.display   = type === 'freshman'   ? 'block' : 'none';
    document.getElementById('qual-transferee').style.display = type === 'transferee' ? 'block' : 'none';
    document.getElementById('strand-group').style.display    = type === 'freshman'   ? 'block' : 'none';
    if (type !== 'freshman') document.getElementById('shs_strand').value = '';
    rebuildStrandOptions(document.getElementById('course_applied').value);
}

function onCourseChange(course) {
    rebuildStrandOptions(course);
}

function rebuildStrandOptions(course) {
    const sel = document.getElementById('shs_strand');
    const prevVal = sel.value;
    sel.innerHTML = '';

    const allowedKeys = strandMap[course] || null;
    // If no course selected yet, or type isn't freshman, show placeholder only
    if (!course || document.getElementById('strand-group').style.display === 'none') {
        sel.innerHTML = '<option value="">Select…</option>';
        return;
    }

    if (allowedKeys && allowedKeys.length) {
        sel.innerHTML = '<option value="">Select…</option>';
        allowedKeys.forEach(function(key) {
            const label = allStrands[key] || key;
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = label;
            if (key === prevVal) opt.selected = true;
            sel.appendChild(opt);
        });
    } else {
        // Open to all strands
        sel.innerHTML = '<option value="">Select…</option>';
        Object.keys(allStrands).forEach(function(key) {
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = allStrands[key];
            if (key === prevVal) opt.selected = true;
            sel.appendChild(opt);
        });
    }
}

function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('svg').style.opacity = isText ? '1' : '0.5';
}

(function() {
    const t = document.getElementById('applicant_type').value;
    const c = document.getElementById('course_applied').value;
    const savedStrand = <?= json_encode($old['shs_strand'] ?? '') ?>;

    if (t) onTypeChange(t);
    if (c) rebuildStrandOptions(c);

    // Restore previously selected strand after rebuilding
    if (savedStrand) {
        const sel = document.getElementById('shs_strand');
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === savedStrand) {
                sel.options[i].selected = true;
                break;
            }
        }
    }
})();
</script>

<style>
/* Name row: first / middle / last / suffix */
.reg-row-names {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 80px;
    gap: var(--space-3);
    margin-bottom: 0;
}
.reg-suffix-col .form-input {
    max-width: 100%;
}

/* Standard 2-col row */
.reg-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
}

/* Birthdate/Sex: equal columns */
.reg-row-birthdate {
    grid-template-columns: 1fr 1fr;
}

/* Address: equal columns like all other rows */
.reg-row-address {
    grid-template-columns: 1fr 1fr;
}

/* Section labels */
.reg-section-label {
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    margin-bottom: var(--space-3);
    padding-bottom: var(--space-2);
    border-bottom: 1px solid var(--border);
    margin-top: var(--space-5);
}
.reg-section-label:first-of-type {
    margin-top: 0;
}

.sex-toggle {
    display: flex;
    gap: var(--space-2);
    height: 40px;
}
.sex-btn {
    width: 48px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--bg-primary);
    color: var(--text-secondary);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    cursor: pointer;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.sex-btn.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}
.sex-toggle.error .sex-btn { border-color: var(--color-error, #e53e3e); }

.qual-box {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: var(--radius-md);
    padding: var(--space-3) var(--space-4);
    margin-bottom: var(--space-4);
    font-size: var(--text-sm);
}
.qual-box-title {
    font-weight: var(--weight-semibold);
    color: var(--text-primary);
    margin-bottom: var(--space-2);
    font-size: var(--text-sm);
}
.qual-list {
    list-style: none; padding: 0; margin: 0;
    display: flex; flex-direction: column;
    gap: var(--space-1);
    color: var(--text-secondary);
}
.qual-list li::before { content: '· '; color: var(--accent); font-weight: bold; }

@media (max-width: 560px) {
    .reg-row-names { grid-template-columns: 1fr 1fr; }
    .reg-suffix-col { grid-column: span 1; }
    .reg-row, .reg-row-address, .reg-row-birthdate { grid-template-columns: 1fr; }
}
@media (max-width: 380px) {
    .reg-row-names { grid-template-columns: 1fr; }
}
</style>
<?php
$content   = ob_get_clean();
$pageTitle = 'Create Account';
include VIEWS_PATH . '/layouts/auth.php';