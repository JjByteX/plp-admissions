<?php
// ============================================================
// modules/interview/staff_queue.php
// M5 — Staff: Live interview queue for today
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$today   = date('Y-m-d');

$deskStmt = $db->prepare('SELECT desk_label, desk_notes FROM users WHERE id=?');
$deskStmt->execute([$staffId]);
$deskRow   = $deskStmt->fetch();
$deskLabel = $deskRow['desk_label'] ?? '';
$deskNotes = $deskRow['desk_notes'] ?? '';

// ----------------------------------------------------------------
// Load today's queue
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT q.id          AS queue_id,
            q.queue_number,
            q.status,
            q.checked_in_at,
            q.interview_notes,
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
     TAB STRIP
================================================================ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
    <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:var(--radius-md);
                 overflow:hidden;background:var(--bg-elevated)">
        <a href="<?= url('/staff/interviews/queue') ?>"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;border-right:1px solid var(--border);
                  background:var(--bg-subtle);color:var(--text-primary);font-weight:var(--weight-medium);
                  display:flex;align-items:center;gap:var(--space-2)">
            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;
                          background:var(--accent);animation:pulse-dot 1.8s ease-in-out infinite"></span>
            Live Queue
        </a>
        <a href="<?= url('/staff/interviews') ?>"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;color:var(--text-secondary);border-right:1px solid var(--border)">
            Upcoming
        </a>
        <a href="<?= url('/staff/interviews') ?>?past=1"
           style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);
                  text-decoration:none;color:var(--text-secondary)">
            Past
        </a>
    </div>
    <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= format_date($today, 'l, F j, Y') ?></div>
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
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);flex-shrink:0">
            <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path stroke="currentColor" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <span style="font-weight:var(--weight-medium)"><?= e($deskLabel) ?></span>
        <?php if ($deskNotes): ?>
            <span style="color:var(--text-tertiary)"><?= e($deskNotes) ?></span>
        <?php endif; ?>
        <a href="<?= url('/staff/interviews') ?>"
           style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary);text-decoration:none">
            Edit
        </a>
    </div>
<?php else: ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-width="2"
                  d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span>No desk location set.
            <a href="<?= url('/staff/interviews') ?>">Set it on the Sessions page</a>
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
     NOW INTERVIEWING
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
                    <div style="display:flex;gap:var(--space-2)">
                        <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_completed">
                            <button class="btn btn-primary btn-sm">Done</button>
                        </form>
                        <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_no_show">
                            <button class="btn btn-ghost btn-sm" style="color:var(--error)">No-show</button>
                        </form>
                    </div>
                </div>

                <!-- Notes always visible for active interview -->
                <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_notes">
                    <textarea name="interview_notes" rows="3"
                              placeholder="Evaluation notes…"
                              class="form-control"
                              style="font-size:var(--text-sm);resize:vertical"
                              ><?= e($entry['interview_notes'] ?? '') ?></textarea>
                    <div style="margin-top:var(--space-2);display:flex;justify-content:flex-end">
                        <button type="submit" class="btn btn-ghost btn-sm">Save Notes</button>
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
                     text-align:center;color:var(--text-tertiary);font-size:var(--text-sm)">
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
                            <button class="btn btn-ghost btn-sm"
                                    onclick="toggleNotes(<?= $entry['queue_id'] ?>)"
                                    style="color:var(--text-tertiary)"
                                    title="Notes">
                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                <?php if ($entry['interview_notes']): ?>
                                    <span style="width:5px;height:5px;border-radius:50%;
                                                  background:var(--accent);display:inline-block"></span>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>

                    <div id="notes-<?= $entry['queue_id'] ?>" style="display:none;margin-top:var(--space-3)">
                        <form method="POST" action="<?= url('/staff/interviews/' . $entry['queue_id']) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_notes">
                            <textarea name="interview_notes" rows="2"
                                      placeholder="Pre-interview notes…"
                                      class="form-control"
                                      style="font-size:var(--text-sm);resize:vertical"
                                      ><?= e($entry['interview_notes'] ?? '') ?></textarea>
                            <div style="margin-top:var(--space-2);display:flex;justify-content:flex-end">
                                <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                            </div>
                        </form>
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
                    <span class="badge <?= $entry['status'] === 'completed' ? 'badge-approved' : 'badge-rejected' ?>">
                        <?= $entry['status'] === 'completed' ? 'Done' : 'No-show' ?>
                    </span>
                    <?php if ($entry['interview_notes']): ?>
                        <button class="btn-icon" onclick="toggleNotes(<?= $entry['queue_id'] ?>)"
                                style="color:var(--text-tertiary)" title="View notes">
                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
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