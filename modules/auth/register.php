<?php
// ============================================================
// modules/auth/register.php
// M2 — Authentication: Student Registration
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

if (Auth::check()) { header("Location: " . Auth::homeUrl()); exit; }

$errors = [];
$old    = [];

// Courses list (expand as needed)
$courses = [
    'Bachelor of Science in Information Technology',
    'Bachelor of Science in Computer Science',
    'Bachelor of Science in Business Administration',
    'Bachelor of Science in Nursing',
    'Bachelor of Science in Education',
    'Bachelor of Arts in Communication',
    'Bachelor of Science in Criminology',
    'Bachelor of Science in Accountancy',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old = [
        'name'           => trim($_POST['name']           ?? ''),
        'email'          => trim($_POST['email']          ?? ''),
        'course_applied' => trim($_POST['course_applied'] ?? ''),
        'applicant_type' => trim($_POST['applicant_type'] ?? ''),
    ];
    $password        = $_POST['password']        ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validate
    if (!$old['name'])                         $errors['name']           = 'Full name is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!$old['course_applied'])               $errors['course_applied'] = 'Select a course.';
    if (!in_array($old['applicant_type'], [TYPE_FRESHMAN, TYPE_TRANSFEREE, TYPE_FOREIGN], true))
        $errors['applicant_type'] = 'Select an applicant type.';
    if (strlen($password) < 8)                 $errors['password']       = 'Password must be at least 8 characters.';
    if ($password !== $passwordConfirm)        $errors['password_confirm'] = 'Passwords do not match.';

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
                class="form-select <?= isset($errors['applicant_type']) ? 'error' : '' ?>" required>
                <option value="">Select type…</option>
                <option value="freshman"   <?= ($old['applicant_type'] ?? '') === 'freshman'   ? 'selected' : '' ?>>Freshman (Incoming 1st Year)</option>
                <option value="transferee" <?= ($old['applicant_type'] ?? '') === 'transferee' ? 'selected' : '' ?>>Transferee</option>
                <option value="foreign"    <?= ($old['applicant_type'] ?? '') === 'foreign'    ? 'selected' : '' ?>>Foreign Student</option>
            </select>
            <?php if (!empty($errors['applicant_type'])): ?>
                <span class="form-error"><?= e($errors['applicant_type']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="course_applied">Course applied for <span>*</span></label>
            <select id="course_applied" name="course_applied"
                class="form-select <?= isset($errors['course_applied']) ? 'error' : '' ?>" required>
                <option value="">Select course…</option>
                <?php foreach ($courses as $course): ?>
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
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('svg').style.opacity = isText ? '1' : '0.5';
}
</script>
<?php
$content   = ob_get_clean();
$pageTitle = 'Create Account';
include VIEWS_PATH . '/layouts/auth.php';