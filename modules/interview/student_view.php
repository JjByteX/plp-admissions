<?php
// ============================================================
// modules/interview/student_view.php
// M5 — Student: view and book interview slot
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();

$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/dashboard'); }
$applicantId = $applicant['id'];

// Check existing slot
$stmt = $db->prepare('SELECT * FROM interview_slots WHERE assigned_applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$mySlot = $stmt->fetch() ?: null;

$errors = [];

// ----------------------------------------------------------------
// POST — book a slot
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($mySlot) { redirect('/student/interview'); }

    $slotId = (int)($_POST['slot_id'] ?? 0);
    $stmt   = $db->prepare(
        'SELECT * FROM interview_slots WHERE id=? AND status="open" AND assigned_applicant_id IS NULL FOR UPDATE'
    );
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch();

    if (!$slot) {
        $errors[] = 'That slot is no longer available.';
    } else {
        $db->prepare(
            'UPDATE interview_slots SET assigned_applicant_id=?, status="scheduled" WHERE id=?'
        )->execute([$applicantId, $slotId]);

        $db->prepare('UPDATE applicants SET overall_status="interview" WHERE id=?')
           ->execute([$applicantId]);

        Session::flash('success', 'Interview slot booked!');
        redirect('/student/interview');
    }
}

// Open slots
$today = date('Y-m-d');
$stmt  = $db->prepare(
    'SELECT * FROM interview_slots
     WHERE status="open" AND assigned_applicant_id IS NULL AND slot_date >= ?
     ORDER BY slot_date, slot_time'
);
$stmt->execute([$today]);
$openSlots = $stmt->fetchAll();

ob_start();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Interview</h1>
        <p class="page-description">Book your admissions interview slot.</p>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($mySlot): ?>
    <!-- Confirmed slot -->
    <div class="card" style="padding:var(--space-6)">
        <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
            <div style="width:48px;height:48px;border-radius:var(--radius-lg);background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                <svg width="22" height="22" fill="none" viewBox="0 0 24 24" style="color:var(--success)"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <div style="font-weight:var(--weight-semibold)">Interview Scheduled</div>
                <div style="font-size:var(--text-sm);color:var(--text-tertiary)">Your slot has been confirmed</div>
            </div>
            <span class="badge badge-<?= $mySlot['status'] ?>" style="margin-left:auto">
                <?= e(ucfirst(str_replace('_',' ',$mySlot['status']))) ?>
            </span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:var(--space-4);padding:var(--space-5);background:var(--neutral-50);border-radius:var(--radius-md)">
            <div>
                <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Date</div>
                <div style="font-weight:var(--weight-semibold)"><?= format_date($mySlot['slot_date']) ?></div>
            </div>
            <div>
                <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:4px">Time</div>
                <div style="font-weight:var(--weight-semibold)"><?= format_time($mySlot['slot_time']) ?></div>
            </div>
        </div>

        <div class="alert alert-warning" style="margin-top:var(--space-4)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            Please arrive 10 minutes before your scheduled time. Bring a valid ID and your application documents.
        </div>
    </div>

<?php elseif ($applicant['overall_status'] !== 'interview'): ?>
    <div class="alert alert-warning">
        Interview scheduling will be available after your entrance exam is complete and reviewed.
    </div>

<?php elseif (empty($openSlots)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary)">
        <svg width="40" height="40" fill="none" viewBox="0 0 24 24" style="margin-bottom:var(--space-3);color:var(--neutral-300)"><path stroke="currentColor" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <p style="font-weight:var(--weight-medium)">No available slots</p>
        <p style="font-size:var(--text-sm);margin-top:4px">No interview slots are open right now. Check back later or contact the admissions office.</p>
    </div>

<?php else: ?>
    <!-- Slot picker -->
    <form method="POST">
        <?= csrf_field() ?>
        <div style="margin-bottom:var(--space-4);font-weight:var(--weight-medium)">Select a slot</div>
        <div style="display:flex;flex-direction:column;gap:var(--space-2);margin-bottom:var(--space-6)">
            <?php foreach ($openSlots as $slot): ?>
                <label style="display:flex;align-items:center;gap:var(--space-4);padding:var(--space-4) var(--space-5);
                              border:1px solid var(--border);border-radius:var(--radius-md);cursor:pointer" class="exam-choice">
                    <input type="radio" name="slot_id" value="<?= $slot['id'] ?>" required style="accent-color:var(--accent)">
                    <div>
                        <div style="font-weight:var(--weight-medium)"><?= format_date($slot['slot_date']) ?></div>
                        <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= format_time($slot['slot_time']) ?></div>
                    </div>
                    <span class="badge badge-success" style="margin-left:auto">Open</span>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Book Selected Slot</button>
    </form>
<?php endif; ?>

<?php
$content     = ob_get_clean();
$pageTitle   = 'Interview';
$activeNav   = 'interview';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
