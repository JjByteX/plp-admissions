<?php
// ============================================================
// modules/interview/staff_queue.php
// Live interview queue — call students, evaluate inline
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$today   = date('Y-m-d');

$myDept = user_department($staffId);

// Load desk info for this interviewer
$deskLabel = '';
$deskNotes = '';
try {
    $deskStmt = $db->prepare(
        'SELECT desk_label, desk_notes FROM interview_desks
         WHERE assigned_to = ? AND is_active = 1
         ORDER BY id LIMIT 1'
    );
    $deskStmt->execute([$staffId]);
    $deskRow = $deskStmt->fetch();
    if ($deskRow) {
        $deskLabel = $deskRow['desk_label'] ?? '';
        $deskNotes = $deskRow['desk_notes'] ?? '';
    }
} catch (\Throwable $e) {}

// Fallback: try department-based desk or user's own desk_label
if (!$deskLabel && $myDept) {
    try {
        $deskStmt = $db->prepare('SELECT desk_label, desk_notes FROM interview_desks WHERE department = ? LIMIT 1');
        $deskStmt->execute([$myDept]);
        $deskRow = $deskStmt->fetch();
        if ($deskRow) {
            $deskLabel = $deskRow['desk_label'] ?? '';
            $deskNotes = $deskRow['desk_notes'] ?? '';
        }
    } catch (\Throwable $e) {}
}
if (!$deskLabel) {
    try {
        $deskStmt = $db->prepare('SELECT desk_label, desk_notes FROM users WHERE id=?');
        $deskStmt->execute([$staffId]);
        $deskRow   = $deskStmt->fetch();
        $deskLabel = $deskRow['desk_label'] ?? '';
        $deskNotes = $deskRow['desk_notes'] ?? '';
    } catch (\Throwable $e) {}
}

// Ensure checkin_code column exists
ensure_checkin_code_column();

// Ensure evaluation_result column exists
try { $db->query("SELECT evaluation_result FROM interview_queue LIMIT 0"); }
catch (\Throwable $e) {
    $db->exec("ALTER TABLE interview_queue ADD COLUMN evaluation_result VARCHAR(10) DEFAULT NULL AFTER interview_notes");
}
try { $db->query("SELECT evaluated_at FROM interview_queue LIMIT 0"); }
catch (\Throwable $e) {
    $db->exec("ALTER TABLE interview_queue ADD COLUMN evaluated_at DATETIME DEFAULT NULL AFTER evaluation_result");
}

// ----------------------------------------------------------------
// Load today's queue
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT q.id          AS queue_id,
            q.queue_number,
            q.status,
            q.checkin_code,
            q.checked_in_at,
            q.interview_notes,
            q.evaluation_result,
            a.id          AS app_id,
            a.course_applied,
            a.applicant_type,
            u.name        AS student_name,
            u.email       AS student_email,
            s.id          AS slot_id,
            s.slot_time
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   applicants a      ON a.id = q.applicant_id
     JOIN   users u           ON u.id = a.user_id
     WHERE  s.slot_date = ? AND s.created_by = ?
     ORDER BY
         FIELD(q.status, "in_progress", "checked_in", "scheduled", "completed", "no_show"),
         q.queue_number ASC,
         q.created_at   ASC'
);
$stmt->execute([$today, $staffId]);
$allEntries = $stmt->fetchAll();

$inProgress = [];
$waiting    = [];
$scheduled  = [];
$done       = [];

foreach ($allEntries as $entry) {
    match ($entry['status']) {
        'in_progress' => $inProgress[] = $entry,
        'checked_in'  => $waiting[]    = $entry,
        'scheduled'   => $scheduled[]  = $entry,
        default       => $done[]       = $entry,
    };
}

ob_start();
?>

<!-- ================================================================
     BACK BUTTON
================================================================ -->
<div style="margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
</div>

<style>
@keyframes pulse-dot {
    0%,100%{opacity:1;transform:scale(1)}
    50%{opacity:.5;transform:scale(1.3)}
}
</style>

<!-- ================================================================
     DESK INFO STRIP
================================================================ -->
<?php if ($deskLabel): ?>
    <div style="display:flex;align-items:center;gap:var(--space-3);
                 padding:var(--space-3) var(--space-4);margin-bottom:var(--space-5);
                 background:var(--bg-elevated);border:1px solid var(--border);
                 border-radius:var(--radius-md);font-size:var(--text-sm)">
        <?= icon('ic_fluent_location_24_regular', 13, 'color:var(--text-tertiary);flex-shrink:0') ?>
        <span style="font-weight:var(--weight-medium)"><?= e($deskLabel) ?></span>
        <?php if ($deskNotes): ?>
            <span style="color:var(--text-tertiary)"><?= e($deskNotes) ?></span>
        <?php endif; ?>
        <a href="<?= url('/staff/interviews/setup') ?>"
           style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary);text-decoration:none">
            Edit
        </a>
    </div>
<?php else: ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
        <?= icon('ic_fluent_warning_24_regular', 15) ?>
        <span>No desk location set.
            <a href="<?= url('/staff/interviews/setup') ?>">Set it up in Interview Setup</a>
            so students know where to go.
        </span>
    </div>
<?php endif; ?>

<!-- ================================================================
     STATS ROW
================================================================ -->
<?php
$completedCount = count(array_filter($done, fn($e) => $e['status'] === 'completed'));
$stats = [
    ['n' => count($waiting),    'label' => 'Waiting',     'color' => 'var(--info)'],
    ['n' => count($inProgress), 'label' => 'In Progress', 'color' => 'var(--accent)'],
    ['n' => $completedCount,    'label' => 'Done',        'color' => 'var(--success)'],
    ['n' => count($scheduled),  'label' => 'Not yet in',  'color' => 'var(--text-tertiary)'],
];
?>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--space-3);margin-bottom:var(--space-6)">
    <?php foreach ($stats as $s): ?>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);
                     border-radius:var(--radius-md);padding:var(--space-4);text-align:center">
            <div style="font-size:1.625rem;font-weight:var(--weight-semibold);color:<?= $s['color'] ?>;line-height:1">
                <?= $s['n'] ?>
            </div>
            <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)">
                <?= $s['label'] ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ================================================================
     MANUAL CHECK-IN (code or name)
================================================================ -->
<div class="card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-5)">
    <form method="POST" action="<?= url('/staff/interviews/manual-checkin') ?>"
          style="display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap">
        <?= csrf_field() ?>
        <div style="flex-shrink:0;display:flex;align-items:center;gap:var(--space-2)">
            <?= icon('ic_fluent_person_24_regular', 16, 'color:var(--text-tertiary)') ?>
            <span style="font-size:var(--text-sm);font-weight:var(--weight-medium);white-space:nowrap">Manual Check-in</span>
        </div>
        <input type="text" name="checkin_search" class="form-input"
               placeholder="Enter check-in code (e.g. PLP-STAR-42) or student name"
               style="flex:1;min-width:200px;min-height:36px;font-size:var(--text-sm)" required>
        <button type="submit" class="btn btn-primary btn-sm">Check In</button>
    </form>
</div>

<!-- ================================================================
     NOW INTERVIEWING (with inline evaluation)
================================================================ -->
<?php if (!empty($inProgress)): ?>
    <div style="margin-bottom:var(--space-5)">
        <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                     color:var(--text-tertiary);font-weight:var(--weight-medium);margin-bottom:var(--space-3)">
            Now Interviewing
        </div>
        <?php foreach ($inProgress as $entry): ?>
            <div class="card" style="padding:var(--space-5);border-left:3px solid var(--accent)">

                <div style="display:flex;align-items:flex-start;gap:var(--space-4);margin-bottom:var(--space-4)">
                    <div style="background:var(--accent);color:#fff;border-radius:var(--radius-md);
                                 width:48px;height:48px;display:flex;align-items:center;justify-content:center;
                                 font-size:var(--text-xl);font-weight:var(--weight-semibold);flex-shrink:0">
                        <?= e($entry['queue_number'] ?? '—') ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:var(--weight-semibold)"><?= e($entry['student_name'] ?? '—') ?></div>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary)">
                            <?= e(ucfirst($entry['applicant_type'] ?? '')) ?>
                            <?php if ($entry['course_applied']): ?>
                                · <?= e($entry['course_applied']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                            <?= e($entry['student_email'] ?? '') ?>
                        </div>
                    </div>
                </div>

                <!-- Inline evaluation form -->
                <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="complete_with_evaluation">

                    <!-- Pass / Fail -->
                    <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-3)">
                        <span style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-secondary);min-width:70px">
                            Evaluation
                        </span>
                        <label style="display:inline-flex;align-items:center;gap:var(--space-1);cursor:pointer;
                                       font-size:var(--text-sm)">
                            <input type="radio" name="evaluation_result" value="pass"
                                   <?= ($entry['evaluation_result'] ?? '') === 'pass' ? 'checked' : '' ?>>
                            Pass
                        </label>
                        <label style="display:inline-flex;align-items:center;gap:var(--space-1);cursor:pointer;
                                       font-size:var(--text-sm)">
                            <input type="radio" name="evaluation_result" value="fail"
                                   <?= ($entry['evaluation_result'] ?? '') === 'fail' ? 'checked' : '' ?>>
                            Fail
                        </label>
                    </div>

                    <!-- Notes -->
                    <textarea name="interview_notes" rows="3"
                              placeholder="Interview notes / evaluation remarks…"
                              class="form-control"
                              style="font-size:var(--text-sm);resize:vertical;margin-bottom:var(--space-3)"
                              ><?= e($entry['interview_notes'] ?? '') ?></textarea>

                    <div style="display:flex;align-items:center;gap:var(--space-2);justify-content:space-between">
                        <button type="submit" class="btn btn-primary btn-sm"
                                onclick="if(!document.querySelector('input[name=evaluation_result]:checked')){alert('Please select Pass or Fail');return false;}">
                            <?= icon('ic_fluent_checkmark_24_regular', 14) ?>
                            Complete Interview
                        </button>
                        <div style="display:flex;gap:var(--space-2)">
                            <button type="button" class="btn btn-ghost btn-sm"
                                    onclick="this.closest('form').querySelector('[name=action]').value='save_notes';this.closest('form').submit()">
                                Save Notes
                            </button>
                            <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>"
                                  style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_no_show">
                                <button class="btn btn-ghost btn-sm" style="color:var(--error)"
                                        onclick="return confirm('Mark as no-show?')">No-show</button>
                            </form>
                        </div>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ================================================================
     WAITING
================================================================ -->
<div style="margin-bottom:var(--space-5)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3)">
        <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                     color:var(--text-tertiary);font-weight:var(--weight-medium)">
            Waiting <?php if (!empty($waiting)): ?>(<?= count($waiting) ?>)<?php endif; ?>
        </div>
        <?php if (!empty($waiting) && empty($inProgress)): ?>
            <form method="POST" action="<?= url('/staff/interviews/call-next') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-primary btn-sm">Call Next →</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($waiting)): ?>
        <div style="padding:var(--space-6);background:var(--bg-subtle);border-radius:var(--radius-md);
                     text-align:left;color:var(--text-tertiary);font-size:var(--text-sm)">
            No one waiting right now.
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--space-2)">
            <?php foreach ($waiting as $entry): ?>
                <div class="card" style="padding:var(--space-4) var(--space-5)">
                    <div style="display:flex;align-items:center;gap:var(--space-4)">

                        <div style="background:var(--bg-subtle);border:2px solid var(--border);
                                     border-radius:var(--radius-md);width:40px;height:40px;
                                     display:flex;align-items:center;justify-content:center;
                                     font-size:var(--text-base);font-weight:var(--weight-semibold);
                                     color:var(--text-primary);flex-shrink:0">
                            <?= e($entry['queue_number']) ?>
                        </div>

                        <div style="flex:1;min-width:0">
                            <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">
                                <?= e($entry['student_name'] ?? '—') ?>
                            </div>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                <?= e(ucfirst($entry['applicant_type'] ?? '')) ?>
                                <?php if ($entry['course_applied']): ?>
                                    · <?= e($entry['course_applied']) ?>
                                <?php endif; ?>
                                · in at <?= $entry['checked_in_at']
                                    ? date('g:i A', strtotime($entry['checked_in_at']))
                                    : '—' ?>
                            </div>
                        </div>

                        <div style="display:flex;gap:var(--space-2)">
                            <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="start_interview">
                                <button class="btn btn-secondary btn-sm">Start</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ================================================================
     NOT YET CHECKED IN
================================================================ -->
<?php if (!empty($scheduled)): ?>
    <div style="margin-bottom:var(--space-5)">
        <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                     color:var(--text-tertiary);font-weight:var(--weight-medium);margin-bottom:var(--space-3)">
            Not Yet Arrived (<?= count($scheduled) ?>)
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--space-1)">
            <?php foreach ($scheduled as $entry): ?>
                <div style="display:flex;align-items:center;gap:var(--space-4);
                              padding:var(--space-3) var(--space-4);
                              border:1px solid var(--border);border-radius:var(--radius-md);
                              background:var(--bg-elevated);opacity:.7">
                    <div style="flex:1;font-size:var(--text-sm)">
                        <?= e($entry['student_name'] ?? 'Unknown') ?>
                        <?php if ($entry['course_applied']): ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                · <?= e($entry['course_applied']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($entry['checkin_code'])): ?>
                            <span style="color:var(--accent);font-size:var(--text-xs);font-family:monospace;margin-left:var(--space-2)">
                                <?= e($entry['checkin_code']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>"
                          onsubmit="return confirm('Mark as no-show?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_no_show">
                        <button class="btn btn-ghost btn-sm" style="color:var(--error)">No-show</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ================================================================
     DONE TODAY
================================================================ -->
<?php if (!empty($done)): ?>
    <div>
        <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                     color:var(--text-tertiary);font-weight:var(--weight-medium);margin-bottom:var(--space-3)">
            Done (<?= count($done) ?>)
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--space-1)">
            <?php foreach ($done as $entry): ?>
                <div style="display:flex;align-items:center;gap:var(--space-4);
                              padding:var(--space-3) var(--space-4);
                              border:1px solid var(--border);border-radius:var(--radius-md);
                              background:var(--bg-elevated)">
                    <?php if ($entry['queue_number']): ?>
                        <span style="font-size:var(--text-xs);color:var(--text-tertiary);
                                      min-width:24px;text-align:center">
                            #<?= e($entry['queue_number']) ?>
                        </span>
                    <?php endif; ?>
                    <div style="flex:1;font-size:var(--text-sm)"><?= e($entry['student_name'] ?? '—') ?></div>
                    <?php if ($entry['evaluation_result'] ?? ''): ?>
                        <span class="badge <?= $entry['evaluation_result'] === 'pass' ? 'badge-approved' : 'badge-rejected' ?>">
                            <?= ucfirst($entry['evaluation_result']) ?>
                        </span>
                    <?php endif; ?>
                    <span class="badge <?= $entry['status'] === 'completed' ? 'badge-approved' : 'badge-rejected' ?>">
                        <?= $entry['status'] === 'completed' ? 'Done' : 'No-show' ?>
                    </span>
                    <?php if ($entry['interview_notes']): ?>
                        <button class="btn-icon" onclick="toggleNotes(<?= $entry['queue_id'] ?>)"
                                style="color:var(--text-tertiary)" title="View notes">
                            <?= icon('ic_fluent_edit_24_regular', 13) ?>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if ($entry['interview_notes']): ?>
                    <div id="notes-<?= $entry['queue_id'] ?>"
                         style="display:none;margin:-2px 0 var(--space-1);
                                padding:var(--space-3) var(--space-4);
                                background:var(--bg-subtle);border-radius:0 0 var(--radius-md) var(--radius-md);
                                font-size:var(--text-xs);color:var(--text-secondary);white-space:pre-line">
                        <?= e($entry['interview_notes']) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Auto-refresh every 30s -->
<script>
setTimeout(() => window.location.reload(), 30000);

function toggleNotes(id) {
    const el = document.getElementById('notes-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
