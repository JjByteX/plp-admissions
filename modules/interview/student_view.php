<?php
// ============================================================
// modules/interview/student_view.php
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();

$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/documents'); }
$applicantId = $applicant['id'];

// Load stepper dependencies
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_examResult = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT * FROM admission_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_admissionResult = $stmt->fetch() ?: null;

// ----------------------------------------------------------------
// Load student's queue entry (if any)
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT q.*,
            s.slot_date,
            s.slot_time,
            s.end_time,
            s.capacity,
            u.name       AS staff_name,
            u.desk_label,
            u.desk_notes
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   users u           ON u.id = s.created_by
     WHERE  q.applicant_id = ?
     LIMIT 1'
);
$stmt->execute([$applicantId]);
$myEntry = $stmt->fetch() ?: null;

$stepperCurrent = current_step($applicant, $_examResult, $myEntry, $_admissionResult);

$errors = [];
$today  = date('Y-m-d');

// ----------------------------------------------------------------
// POST — book a session OR check in ("I'm here")
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'book';

    // ---- Book a session ----------------------------------------
    if ($action === 'book') {
        if ($myEntry) { redirect('/student/interview'); }

        $slotId = (int)($_POST['slot_id'] ?? 0);

        $db->beginTransaction();
        try {
            // Lock slot row and check capacity
            $stmt = $db->prepare(
                'SELECT s.id, s.capacity,
                        COUNT(q.id) AS booked
                 FROM   interview_slots s
                 LEFT JOIN interview_queue q ON q.slot_id = s.id
                 WHERE  s.id = ? AND s.status = "open"
                 GROUP BY s.id
                 FOR UPDATE'
            );
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch();

            if (!$slot || (int)$slot['booked'] >= (int)$slot['capacity']) {
                $db->rollBack();
                $errors[] = 'That session is no longer available or has reached its capacity.';
            } else {
                $db->prepare(
                    'INSERT INTO interview_queue (slot_id, applicant_id, status)
                     VALUES (?, ?, "scheduled")'
                )->execute([$slotId, $applicantId]);

                $db->prepare(
                    'UPDATE applicants SET overall_status="interview" WHERE id=?'
                )->execute([$applicantId]);

                $db->commit();
                Session::flash('success', 'Your interview session has been booked!');
                redirect('/student/interview');
            }
        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = 'Booking failed. Please try again.';
        }
    }

    // ---- Check in ("I'm here") ---------------------------------
    if ($action === 'checkin') {
        if (!$myEntry || $myEntry['slot_date'] !== $today) { redirect('/student/interview'); }
        if ($myEntry['status'] !== 'scheduled') { redirect('/student/interview'); }

        $db->beginTransaction();
        try {
            // Atomic: next queue number for this staff's slots today
            $stmt = $db->prepare(
                'SELECT COALESCE(MAX(q.queue_number), 0) + 1
                 FROM   interview_queue q
                 JOIN   interview_slots s ON s.id = q.slot_id
                 WHERE  s.slot_date = ? AND s.created_by = (
                     SELECT created_by FROM interview_slots WHERE id = ?
                 )
                 AND q.queue_number IS NOT NULL'
            );
            $stmt->execute([$today, $myEntry['slot_id']]);
            $nextNum = (int)$stmt->fetchColumn();

            $db->prepare(
                'UPDATE interview_queue
                 SET    status = "checked_in",
                        queue_number = ?,
                        checked_in_at = NOW()
                 WHERE  id = ? AND status = "scheduled"'
            )->execute([$nextNum, $myEntry['id']]);

            $db->commit();
            Session::flash('success', 'You are now in the queue!');
        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = 'Check-in failed. Please try again.';
        }

        // Reload entry
        $stmt = $db->prepare(
            'SELECT q.*,
                    s.slot_date, s.slot_time, s.end_time, s.capacity,
                    u.name AS staff_name, u.desk_label, u.desk_notes
             FROM   interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             JOIN   users u           ON u.id = s.created_by
             WHERE  q.applicant_id = ?
             LIMIT 1'
        );
        $stmt->execute([$applicantId]);
        $myEntry = $stmt->fetch() ?: null;
    }
}

// ----------------------------------------------------------------
// Load available sessions (if student has no booking)
// ----------------------------------------------------------------
$openSessions = [];
if (!$myEntry) {
    $nowTime = date('H:i:s');
    $stmt = $db->prepare(
        'SELECT s.*,
                u.name       AS staff_name,
                u.desk_label,
                u.desk_notes,
                COUNT(q.id)  AS booked
         FROM   interview_slots s
         JOIN   users u ON u.id = s.created_by
         LEFT JOIN interview_queue q ON q.slot_id = s.id
         WHERE  s.slot_date >= ? AND s.status = "open"
           AND  NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
         GROUP BY s.id
         HAVING booked < s.capacity
         ORDER BY s.slot_date ASC, s.slot_time ASC'
    );
    $stmt->execute([$today, $today, $nowTime]);
    $openSessions = $stmt->fetchAll();
}

// Queue position (how many checked_in ahead of this student)
$queuePosition = null;
if ($myEntry && $myEntry['status'] === 'checked_in') {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         WHERE  s.slot_date = ? AND s.created_by = (
             SELECT created_by FROM interview_slots WHERE id = ?
         )
         AND q.status = "checked_in"
         AND q.queue_number < ?'
    );
    $stmt->execute([$today, $myEntry['slot_id'], $myEntry['queue_number']]);
    $ahead         = (int)$stmt->fetchColumn();
    $queuePosition = $ahead + 1;
}

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($myEntry): ?>

    <?php $slotIsToday = ($myEntry['slot_date'] === $today); ?>

    <?php if (in_array($myEntry['status'], ['checked_in', 'in_progress'], true)): ?>
        <!-- ============================================================
             CHECKED IN STATE — queue number + desk instructions
        ============================================================ -->
        <div class="card" style="padding:var(--space-6);text-align:center;margin-bottom:var(--space-4)">

            <div style="display:inline-flex;flex-direction:column;align-items:center;
                        background:var(--accent);color:#fff;border-radius:var(--radius-xl);
                        padding:var(--space-6) var(--space-10);margin-bottom:var(--space-5)">
                <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.1em;
                             opacity:.85;font-weight:var(--weight-medium);margin-bottom:var(--space-1)">
                    Queue Number
                </div>
                <div style="font-size:3.5rem;font-weight:var(--weight-semibold);line-height:1">
                    <?= e($myEntry['queue_number']) ?>
                </div>
            </div>

            <?php if ($myEntry['status'] === 'in_progress'): ?>
                <div class="badge badge-info"
                     style="font-size:var(--text-sm);padding:var(--space-2) var(--space-4);margin-bottom:var(--space-4)">
                    Your interview is now in progress
                </div>
            <?php else: ?>
                <?php if ($queuePosition !== null): ?>
                    <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-4)">
                        <?php if ($queuePosition === 1): ?>
                            You are <strong>next</strong> — please be ready!
                        <?php else: ?>
                            There <?= $queuePosition - 1 === 1 ? 'is' : 'are' ?>
                            <strong><?= $queuePosition - 1 ?></strong>
                            applicant<?= $queuePosition - 1 !== 1 ? 's' : '' ?> ahead of you.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Desk instructions -->
            <?php if ($myEntry['desk_label']): ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                             padding:var(--space-4) var(--space-5);text-align:left;margin-bottom:var(--space-4)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                                 color:var(--text-tertiary);margin-bottom:var(--space-2)">Proceed to</div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg);
                                 margin-bottom:<?= $myEntry['desk_notes'] ? 'var(--space-2)' : '0' ?>">
                        <?= e($myEntry['desk_label']) ?>
                    </div>
                    <?php if ($myEntry['desk_notes']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);white-space:pre-line">
                            <?= e($myEntry['desk_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                Checked in at <?= date('g:i A', strtotime($myEntry['checked_in_at'])) ?>
                &nbsp;·&nbsp;
                <?= format_date($myEntry['slot_date']) ?>
            </div>
        </div>

        <div class="alert alert-warning">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24">
                <path stroke="currentColor" stroke-width="2"
                      d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            Please stay nearby and have your valid ID and application documents ready.
        </div>

        <script>setTimeout(function(){ window.location.reload(); }, 30000);</script>

    <?php elseif ($myEntry['status'] === 'completed'): ?>
        <div class="card" style="padding:var(--space-6)">
            <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div style="width:48px;height:48px;border-radius:var(--radius-lg);
                             background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                    <svg width="22" height="22" fill="none" viewBox="0 0 24 24" style="color:var(--success)">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold)">Interview Completed</div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        Your interview has been recorded
                    </div>
                </div>
                <span class="badge badge-neutral" style="margin-left:auto">Completed</span>
            </div>
            <div class="alert alert-info" style="margin-top:var(--space-2)">
                Your results will be released by the admissions office. You will be notified once available.
            </div>
        </div>

    <?php elseif ($myEntry['status'] === 'no_show'): ?>
        <div class="card" style="padding:var(--space-6)">
            <div class="alert alert-error">
                You were marked as absent for your scheduled interview. Please contact the admissions office.
            </div>
        </div>

    <?php elseif ($slotIsToday && $myEntry['status'] === 'scheduled'): ?>
        <!-- ============================================================
             TODAY — "I'm here" check-in
        ============================================================ -->
        <div class="card" style="padding:var(--space-6)">
            <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div style="width:48px;height:48px;border-radius:var(--radius-lg);
                             background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                    <svg width="22" height="22" fill="none" viewBox="0 0 24 24" style="color:var(--success)">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold)">Today is your Interview Day</div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?php if ($myEntry['slot_time']): ?>
                            <?= format_time($myEntry['slot_time']) ?><?= $myEntry['end_time'] ? ' – ' . format_time($myEntry['end_time']) : '' ?> &nbsp;·&nbsp;
                        <?php endif; ?>
                        <?= format_date($myEntry['slot_date']) ?>
                    </div>
                </div>
                <span class="badge badge-info" style="margin-left:auto">Scheduled</span>
            </div>

            <!-- Desk location — visible BEFORE check-in -->
            <?php if ($myEntry['desk_label']): ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                             padding:var(--space-4) var(--space-5);margin-bottom:var(--space-5)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                                 color:var(--text-tertiary);margin-bottom:var(--space-1)">
                        After check-in, proceed to
                    </div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">
                        <?= e($myEntry['desk_label']) ?>
                    </div>
                    <?php if ($myEntry['desk_notes']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);
                                     margin-top:var(--space-1);white-space:pre-line">
                            <?= e($myEntry['desk_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="checkin">
                <button type="submit" class="btn btn-primary"
                        style="width:100%;padding:var(--space-4);font-size:var(--text-lg)">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" style="margin-right:var(--space-2)">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                              d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                    </svg>
                    I'm Here
                </button>
            </form>

            <p style="font-size:var(--text-xs);color:var(--text-tertiary);text-align:center;margin-top:var(--space-3)">
                Tap this button when you arrive at the admissions area to receive your queue number.
            </p>
        </div>

    <?php else: ?>
        <!-- ============================================================
             FUTURE CONFIRMED BOOKING
        ============================================================ -->
        <div class="card" style="padding:var(--space-6)">
            <div style="display:flex;align-items:center;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div style="width:48px;height:48px;border-radius:var(--radius-lg);
                             background:var(--success-bg);display:flex;align-items:center;justify-content:center">
                    <svg width="22" height="22" fill="none" viewBox="0 0 24 24" style="color:var(--success)">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold)">Interview Booked</div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?= format_date($myEntry['slot_date'], 'l, F j, Y') ?>
                        <?php if ($myEntry['slot_time']): ?>
                            &nbsp;·&nbsp;<?= format_time($myEntry['slot_time']) ?><?= $myEntry['end_time'] ? ' – ' . format_time($myEntry['end_time']) : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge badge-success" style="margin-left:auto">Confirmed</span>
            </div>

            <!-- Desk location — show even before interview day -->
            <?php if ($myEntry['desk_label']): ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                             padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4)">
                    <div style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.07em;
                                 color:var(--text-tertiary);margin-bottom:var(--space-1)">Report to</div>
                    <div style="font-weight:var(--weight-semibold)"><?= e($myEntry['desk_label']) ?></div>
                    <?php if ($myEntry['desk_notes']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);
                                     margin-top:var(--space-1);white-space:pre-line">
                            <?= e($myEntry['desk_notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>
                    On the day of your interview, return to this page and tap <strong>"I'm Here"</strong>
                    to join the queue and receive your number.
                </span>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ============================================================
         NO BOOKING — Show available sessions
    ============================================================ -->
    <?php if (empty($openSessions)): ?>
        <div class="card" style="padding:var(--space-6);text-align:center">
            <div style="color:var(--text-tertiary);font-size:var(--text-sm)">
                No interview sessions are currently available. Please check back later or
                contact the admissions office.
            </div>
        </div>
    <?php else: ?>
        <div style="margin-bottom:var(--space-4)">
            <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">
                Available Interview Sessions
            </div>
            <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                Select a session to book your interview slot.
            </div>
        </div>

        <?php
        // Group sessions by date
        $sessionsByDate = [];
        foreach ($openSessions as $sess) {
            $sessionsByDate[$sess['slot_date']][] = $sess;
        }
        ?>
        <?php foreach ($sessionsByDate as $date => $sessions): ?>
            <div style="margin-bottom:var(--space-5)">
                <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold);
                             color:var(--text-secondary);margin-bottom:var(--space-2)">
                    <?= format_date($date, 'l, F j, Y') ?>
                    <?php if ($date === $today): ?>
                        <span class="badge badge-info" style="margin-left:var(--space-2)">Today</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                <?php foreach ($sessions as $sess):
                    $spotsLeft = (int)$sess['capacity'] - (int)$sess['booked'];
                    $timeLabel = 'Any time';
                    if ($sess['slot_time']) {
                        $timeLabel = format_time($sess['slot_time']);
                        if ($sess['end_time']) {
                            $timeLabel .= ' – ' . format_time($sess['end_time']);
                        }
                    }
                ?>
                    <div class="card" style="padding:var(--space-4) var(--space-5)">
                        <div style="display:flex;align-items:center;gap:var(--space-4)">
                            <div style="flex:1">
                                <div style="font-weight:var(--weight-medium)">
                                    <?= $timeLabel ?>
                                    <?php if ($sess['desk_label']): ?>
                                        <span style="color:var(--text-tertiary);font-weight:400">
                                            · <?= e($sess['desk_label']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($sess['desk_notes']): ?>
                                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                                        <?= e($sess['desk_notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary);white-space:nowrap">
                                <?= $spotsLeft ?> spot<?= $spotsLeft !== 1 ? 's' : '' ?> left
                            </div>
                            <form method="POST"
                                  onsubmit="return confirm('Book the <?= $timeLabel ?> session on <?= format_date($date) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="book">
                                <input type="hidden" name="slot_id" value="<?= $sess['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Book</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Step navigation -->
<div class="step-nav">
    <a href="<?= url('/student/documents') ?>" class="btn btn-ghost">← Documents</a>
    <?php if ($myEntry && $myEntry['status'] === 'completed'): ?>
        <a href="<?= url('/student/result') ?>" class="btn btn-primary">My Result →</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = 'My Interview';
$activeNav   = 'interview';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';