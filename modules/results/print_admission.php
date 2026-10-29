<?php
// ============================================================
// modules/results/print_admission.php
// Printable admission record — one applicant, one A4 sheet.
//
//   GET /print/admission?id={applicant_id}
//
// Student    : `id` is ignored, always the student's own record.
// SSO, Admin : any `id`.
// Everyone else gets 403; logged-out visitors go to the login page.
//
// Standalone page (no app layout): toolbar + sheet. The sheet reuses
// the applicant panel partial so the screen and the paper never drift.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';

// Personal data — never cache, not even the 403.
header('Cache-Control: no-store');
Auth::requireRole(ROLE_STUDENT, ROLE_SSO, ROLE_ADMIN);

$db        = db();
$isStudent = Auth::isStudent();

// ── Which applicant ────────────────────────────────────────
if ($isStudent) {
    // Own record only; a passed id is never read.
    $where = 'a.user_id = ? ORDER BY a.id DESC LIMIT 1';
    $param = Auth::id();
} else {
    $rawId = $_GET['id'] ?? '';
    if (!is_string($rawId) || !ctype_digit($rawId) || strlen($rawId) > 9 || (int) $rawId < 1) {
        http_response_code(400);
        exit('Missing or invalid applicant id.');
    }
    $where = 'a.id = ?';
    $param = (int) $rawId;
}

$stmt = $db->prepare(
    'SELECT a.id, a.overall_status, a.withdrawn_at, a.withdrawn_reason,
            u.name AS student_name, u.first_name, u.middle_name, u.last_name, u.suffix
       FROM applicants a JOIN users u ON u.id = a.user_id
      WHERE ' . $where
);
$stmt->execute([$param]);
$applicant = $stmt->fetch();

if (!$applicant) {
    http_response_code(404);
    include VIEWS_PATH . '/404.php';
    exit;
}

$applicantId = (int) $applicant['id'];
$fullName    = format_full_name($applicant, $applicant['student_name'] ?? '-');
$status      = (string) $applicant['overall_status'];

$schoolName = school_setting('school_name', 'PLP Admissions');
$schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$schoolLogo = school_setting('school_logo', '');
$logoUrl    = '';
if ($schoolLogo !== '') {
    $logoUrl = str_starts_with($schoolLogo, 'http') ? $schoolLogo : url('/' . $schoolLogo);
}

audit_log(AuditAction::ADMISSION_PRINT, 'Viewed printable admission record: ' . $fullName, 'applicant', $applicantId);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admission Record - <?= e($fullName) ?> - <?= e($schoolName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap">
<?= phosphor_head() ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="print-page">

<!-- Toolbar (hidden on paper) -->
<div class="pr-toolbar">
    <span class="pr-toolbar-title">Admission record</span>
    <button type="button" id="printBtn" class="btn btn-primary btn-sm">
        <?= icon('printer', 14) ?> Print / Save as PDF
    </button>
</div>

<div class="pr-sheet">

    <header class="pr-head">
        <?php if ($logoUrl !== ''): ?>
            <img class="pr-logo" src="<?= e($logoUrl) ?>" alt="">
        <?php endif; ?>
        <div class="pr-school-meta">
            <div class="pr-school"><?= e($schoolName) ?></div>
            <div class="pr-sy">School Year <?= e($schoolYear) ?></div>
        </div>
        <div class="pr-who">
            <div class="pr-name"><?= e($fullName) ?></div>
            <span class="badge pr-status"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span>
        </div>
    </header>

    <main class="pr-body">
        <?php
        $panelStandalone = true;
        $panelPrint      = true;
        $panelStudent    = $isStudent;
        include VIEWS_PATH . '/partials/applicant_panel.php';
        ?>

        <?php if ($status === 'withdrawn'): ?>
        <section class="ap-section">
            <div class="ap-section-title">Withdrawal</div>
            <div class="ap-kv">
                <div><span class="ap-k">Withdrawn</span>
                    <?= !empty($applicant['withdrawn_at']) ? e(format_date($applicant['withdrawn_at'], 'M j, Y g:i A')) : '-' ?>
                </div>
                <?php if (trim((string) ($applicant['withdrawn_reason'] ?? '')) !== ''): ?>
                <div><span class="ap-k">Reason</span> <?= nl2br(e($applicant['withdrawn_reason'])) ?></div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <footer class="pr-foot">
        <span>Printed <?= e(date('M j, Y g:i A')) ?></span>
        <span>Printed by <?= e(Auth::user()['name'] ?? '') ?> (<?= e(Auth::roleLabel(Auth::role())) ?>)</span>
    </footer>

</div>

<script src="<?= e(asset('js/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('js/pages/print-admission.js')) ?>"></script>
</body>
</html>
