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
        'name'           => trim($_POST['name']           ?? ''),
        'email'          => trim($_POST['email']          ?? ''),
        'course_applied' => trim($_POST['course_applied'] ?? ''),
        'applicant_type' => trim($_POST['applicant_type'] ?? ''),
        'shs_strand'     => trim($_POST['shs_strand']     ?? ''),
    ];
    $password        = $_POST['password']        ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validate
    if (!$old['name'])                         $errors['name']           = 'Full name is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!in_array($old['applicant_type'], [TYPE_FRESHMAN, TYPE_TRANSFEREE, TYPE_FOREIGN], true))
        $errors['applicant_type'] = 'Select an applicant type.';
    if (!in_array($old['course_applied'], PLP_COURSES, true))
        $errors['course_applied'] = 'Select a valid course.';
    if (strlen($password) < 8)                 $errors['password']       = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm)        $errors['password_confirm'] = 'Passwords do not match.';

    // Strand validation for freshmen
    if ($old['applicant_type'] === TYPE_FRESHMAN) {
        if (empty($old['shs_strand'])) {
            $errors['shs_strand'] = 'Select your SHS strand.';
        } elseif (!empty($old['course_applied'])) {
            $allowedStrands = COURSE_STRAND_MAP[$old['course_applied']] ?? null;
            if ($allowedStrands !== null && !in_array($old['shs_strand'], $allowedStrands, true)) {
                $errors['shs_strand'] = 'Your strand (' . $old['shs_strand'] . ') is not accepted for ' . $old['course_applied'] . '. Accepted: ' . implode(', ', $allowedStrands) . '.';
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
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Create user
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $old['name'],
                $old['email'],
                password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ROLE_STUDENT,
            ]);
            $userId = (int) $pdo->lastInsertId();

            // Create applicant record
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

            // Seed document rows per applicant type
            $docs = docs_for_type($old['applicant_type']);
            $docStmt = $pdo->prepare(
                'INSERT INTO documents (applicant_id, doc_type, status) VALUES (?, ?, ?)'
            );
            foreach (array_keys($docs) as $slug) {
                $docStmt->execute([$applicantId, $slug, 'pending']);
            }

            $pdo->commit();

            // Auto-login
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
ob_start();
?>
<div class="auth-card animate-fade-in" style="max-width:480px">

    <div class="auth-header">
        <div class="auth-logo">
            <?php include VIEWS_PATH . '/partials/icons/school.svg.php'; ?>
        </div>
        <h1 class="auth-title">Create an account</h1>
        <p class="auth-subtitle">Apply to <?= e(school_setting('school_name', 'PLP')) ?></p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
            <?= e($errors['general']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/register') ?>" data-once novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="name">Full name <span>*</span></label>
            <input type="text" id="name" name="name"
                class="form-input <?= isset($errors['name']) ? 'error' : '' ?>"
                value="<?= e($old['name'] ?? '') ?>"
                placeholder="Juan dela Cruz"
                autocomplete="name" required>
            <?php if (!empty($errors['name'])): ?>
                <span class="form-error"><?= e($errors['name']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="email">Email address <span>*</span></label>
            <input type="email" id="email" name="email"
                class="form-input <?= isset($errors['email']) ? 'error' : '' ?>"
                value="<?= e($old['email'] ?? '') ?>"
                placeholder="you@example.com"
                autocomplete="email" required>
            <?php if (!empty($errors['email'])): ?>
                <span class="form-error"><?= e($errors['email']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="applicant_type">Applicant type <span>*</span></label>
            <select id="applicant_type" name="applicant_type"
                class="form-select <?= isset($errors['applicant_type']) ? 'error' : '' ?>" required
                onchange="onTypeChange(this.value)">
                <option value="">Select type…</option>
                <option value="freshman"   <?= ($old['applicant_type'] ?? '') === 'freshman'   ? 'selected' : '' ?>>Freshman (Graduating / Graduate of Senior HS)</option>
                <option value="transferee" <?= ($old['applicant_type'] ?? '') === 'transferee' ? 'selected' : '' ?>>Transferee (Incoming 2nd–3rd Year)</option>
                <option value="foreign"    <?= ($old['applicant_type'] ?? '') === 'foreign'    ? 'selected' : '' ?>>Foreign Student</option>
            </select>
            <?php if (!empty($errors['applicant_type'])): ?>
                <span class="form-error"><?= e($errors['applicant_type']) ?></span>
            <?php endif; ?>
        </div>

        <!-- Qualifications info box — shown per type -->
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

        <div class="form-group">
            <label class="form-label" for="course_applied">Course applied for <span>*</span></label>
            <select id="course_applied" name="course_applied"
                class="form-select <?= isset($errors['course_applied']) ? 'error' : '' ?>" required
                onchange="onCourseChange(this.value)">
                <option value="">Select course…</option>
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

        <!-- SHS Strand — shown for freshmen only -->
        <div id="strand-group" class="form-group" style="display:<?= ($old['applicant_type'] ?? '') === 'freshman' ? 'block' : 'none' ?>">
            <label class="form-label" for="shs_strand">SHS Strand <span>*</span></label>
            <select id="shs_strand" name="shs_strand"
                class="form-select <?= isset($errors['shs_strand']) ? 'error' : '' ?>">
                <option value="">Select strand…</option>
                <?php foreach (SHS_STRANDS as $key => $label): ?>
                    <option value="<?= e($key) ?>"
                        <?= ($old['shs_strand'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p id="strand-note" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">
                Only strands accepted for your selected course will pass validation.
            </p>
            <?php if (!empty($errors['shs_strand'])): ?>
                <span class="form-error"><?= e($errors['shs_strand']) ?></span>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="password">Password <span>*</span></label>
                <div class="input-wrapper has-suffix">
                    <input type="password" id="password" name="password"
                        class="form-input <?= isset($errors['password']) ? 'error' : '' ?>"
                        placeholder="Min. 8 characters"
                        autocomplete="new-password" required>
                    <button type="button" class="input-suffix-icon btn-pw-toggle" onclick="togglePw('password',this)" tabindex="-1" aria-label="Show password">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                    </button>
                </div>
                <?php if (!empty($errors['password'])): ?>
                    <span class="form-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="password_confirm">Confirm <span>*</span></label>
                <div class="input-wrapper has-suffix">
                    <input type="password" id="password_confirm" name="password_confirm"
                        class="form-input <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                        placeholder="Repeat password"
                        autocomplete="new-password" required>
                    <button type="button" class="input-suffix-icon btn-pw-toggle" onclick="togglePw('password_confirm',this)" tabindex="-1" aria-label="Show password">
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
const strandMap = <?= json_encode(COURSE_STRAND_MAP) ?>;

function onTypeChange(type) {
    document.getElementById('qual-freshman').style.display  = type === 'freshman'   ? 'block' : 'none';
    document.getElementById('qual-transferee').style.display = type === 'transferee' ? 'block' : 'none';
    document.getElementById('strand-group').style.display   = type === 'freshman'   ? 'block' : 'none';
    if (type !== 'freshman') {
        document.getElementById('shs_strand').value = '';
    }
    updateStrandNote();
}

function onCourseChange(course) {
    updateStrandNote(course);
}

function updateStrandNote(course) {
    course = course || document.getElementById('course_applied').value;
    const note = document.getElementById('strand-note');
    if (!note) return;
    const strands = strandMap[course];
    if (strands && strands.length) {
        note.textContent = 'Accepted strands for this course: ' + strands.join(', ') + '.';
        note.style.color = 'var(--accent)';
    } else if (course) {
        note.textContent = 'Open to all SHS strands.';
        note.style.color = 'var(--text-tertiary)';
    } else {
        note.textContent = 'Select a course first to see accepted strands.';
        note.style.color = 'var(--text-tertiary)';
    }
}

function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('svg').style.opacity = isText ? '1' : '0.5';
}

// Init on page load (for validation repopulation)
(function() {
    const t = document.getElementById('applicant_type').value;
    if (t) onTypeChange(t);
    const c = document.getElementById('course_applied').value;
    if (c) updateStrandNote(c);
})();
</script>
<style>
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
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    color: var(--text-secondary);
}
.qual-list li::before {
    content: '· ';
    color: var(--accent);
    font-weight: bold;
}
</style>
<?php
$content   = ob_get_clean();
$pageTitle = 'Create Account';
include VIEWS_PATH . '/layouts/auth.php';