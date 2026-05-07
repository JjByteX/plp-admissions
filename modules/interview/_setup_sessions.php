<?php
// _setup_sessions.php — card-grid session list for one college.
// Variables in scope: $college, $slots, $byDate, $staffList,
// $isAdmin, $errors, $today.
//
// Layout matches the exam directory style 1:1: a responsive grid
// of cards (one per session) with a dashed "+ Add Session" card as
// the first cell. Setup is purely about creating/editing sessions —
// past sessions, rosters, and evaluation live elsewhere.

function _session_expired(string $date, ?string $endTime, string $today, string $nowTime): bool {
    if ($date < $today) return true;
    if ($date === $today && $endTime !== null && $nowTime >= $endTime) return true;
    return false;
}
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;align-items:center;margin-bottom:var(--space-5)">
    <?php if ($isAdmin): ?>
        <a href="<?= url('/staff/interviews/setup') ?>" class="btn btn-ghost btn-sm">← Back</a>
    <?php else: ?>
        <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
    <?php endif; ?>
    <div style="flex:1;text-align:center">
        <span style="font-weight:var(--weight-semibold);font-size:var(--text-base)"><?= e($college) ?></span>
        <span style="color:var(--text-tertiary);font-size:var(--text-sm)"> — Interview Sessions</span>
    </div>
    <div style="width:80px"></div>
</div>

<style>
/* Mirrors .exam-dir-grid / .exam-dir-card from staff_manage.php so the
   setup page reads as part of the same family. */
.sess-dir-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--space-4);
    margin-top: var(--space-5);
}
.sess-dir-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-5);
    background: var(--bg-elevated);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: var(--text-primary);
    transition: border-color .15s, box-shadow .15s;
    position: relative;
    cursor: pointer;
    min-height: 220px;
}
.sess-dir-card:hover { border-color: var(--accent); box-shadow: var(--shadow-sm); }
.sess-dir-card.is-today  { border-color: var(--accent); background: var(--accent-muted); }
.sess-dir-card.is-closed { opacity: .7; }

.sess-dir-title {
    font-size: var(--text-base);
    font-weight: var(--weight-semibold);
    color: var(--text-primary);
    line-height: 1.35;
    padding-right: 70px; /* leave room for the absolute status badge */
}
.sess-dir-meta {
    display: flex; flex-direction: column; gap: 4px;
    font-size: var(--text-xs); color: var(--text-tertiary);
}
.sess-dir-meta-row { display: flex; align-items: center; gap: 5px; }
.sess-dir-footer {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: auto; padding-top: var(--space-3);
    border-top: 1px solid var(--border);
}

/* Dashed "+ Add Session" card — same shape as the New Exam card. */
.sess-dir-card--add {
    align-items: center; justify-content: center; text-align: center;
    border-style: dashed;
    background: transparent;
}
.sess-dir-card--add:hover { background: var(--accent-muted); }
.sess-dir-card--add .plus {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--accent-muted); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
}
.sess-dir-card--add .label {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--accent);
    margin-top: var(--space-2);
}
.sess-dir-card--add .hint {
    font-size: var(--text-xs); color: var(--text-tertiary);
    margin-top: var(--space-1);
}
</style>

<?php
$nowTime = date('H:i:s');

// Flatten $byDate back to a single ordered list for the grid.
$flatSlots = [];
foreach ($byDate as $date => $dateSlots) {
    foreach ($dateSlots as $s) { $flatSlots[] = $s; }
}
?>

<div class="sess-dir-grid">

    <?php /* ── Add Session card (always first; opens the modal) ─── */ ?>
    <div class="sess-dir-card sess-dir-card--add"
         onclick="document.getElementById('add-session-modal').style.display='flex'"
         role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <div class="plus">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 5v14M5 12h14"/>
            </svg>
        </div>
        <div class="label">Add Session</div>
        <div class="hint">Single or recurring · date · time · interviewer</div>
    </div>

    <?php /* ── Existing sessions (upcoming only) ────────────────── */ ?>
    <?php foreach ($flatSlots as $slot):
        $date      = $slot['slot_date'];
        $booked    = (int)$slot['booked'];
        $capacity  = (int)$slot['capacity'];
        $isExpired = _session_expired($date, $slot['end_time'] ?? null, $today, $nowTime);
        $isClosed  = $slot['status'] === 'closed' || $isExpired;
        $isFull    = $booked >= $capacity;

        $timeLabel = 'All day';
        if ($slot['slot_time']) {
            $timeLabel = format_time($slot['slot_time']);
            if ($slot['end_time']) $timeLabel .= ' – ' . format_time($slot['end_time']);
        }

        $cardClasses = 'sess-dir-card';
        if ($date === $today)             $cardClasses .= ' is-today';
        if ($slot['status'] === 'closed') $cardClasses .= ' is-closed';

        $editPayload = json_encode([
            'id'             => (int)$slot['id'],
            'slot_date'      => $slot['slot_date'],
            'slot_time'      => substr($slot['slot_time'] ?? '', 0, 5),
            'end_time'       => substr($slot['end_time']  ?? '', 0, 5),
            'capacity'       => (int)$slot['capacity'],
            'booked'         => $booked,
            'status'         => $slot['status'],
            'assigned_to'    => (int)($slot['assigned_to'] ?? 0),
            'location_label' => $slot['location_label'] ?? '',
            'location_notes' => $slot['location_notes'] ?? '',
        ], JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
        <div class="<?= $cardClasses ?>" onclick='openEditSession(<?= $editPayload ?>)'>

            <!-- Status badge top-right -->
            <div style="position:absolute;top:var(--space-4);right:var(--space-4)">
                <?php if ($isExpired && $slot['status'] !== 'closed'): ?>
                    <span class="badge badge-neutral" style="font-size:10px">Ended</span>
                <?php elseif ($isClosed): ?>
                    <span class="badge badge-neutral" style="font-size:10px">Closed</span>
                <?php elseif ($isFull): ?>
                    <span class="badge badge-review" style="font-size:10px">Full</span>
                <?php elseif ($date === $today): ?>
                    <span class="badge badge-info" style="font-size:10px">Today</span>
                <?php else: ?>
                    <span class="badge badge-approved" style="font-size:10px">Open</span>
                <?php endif; ?>
            </div>

            <!-- Title: interviewer name -->
            <div class="sess-dir-title"><?= e($slot['interviewer_name'] ?? 'Unassigned') ?></div>

            <!-- Meta: date · time · location · capacity -->
            <div class="sess-dir-meta">
                <div class="sess-dir-meta-row">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <?= e(format_date($date, 'D, M j')) ?>
                </div>
                <div class="sess-dir-meta-row">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 7v5l3 3"/></svg>
                    <?= e($timeLabel) ?>
                </div>
                <?php if (!empty($slot['location_label'])): ?>
                    <div class="sess-dir-meta-row">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/></svg>
                        <?= e($slot['location_label']) ?>
                    </div>
                <?php endif; ?>
                <div class="sess-dir-meta-row">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    <?= $booked ?> / <?= $capacity ?> booked
                </div>
            </div>

            <!-- Footer: notes (or empty) on left, "Edit →" on right -->
            <div class="sess-dir-footer">
                <span style="font-size:var(--text-xs);color:var(--text-tertiary);
                             overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%">
                    <?= !empty($slot['location_notes'])
                        ? e($slot['location_notes'])
                        : '<span style="opacity:.5">No notes</span>' ?>
                </span>
                <span style="font-size:var(--text-xs);font-weight:var(--weight-medium);
                             color:var(--accent);display:flex;align-items:center;gap:4px">
                    Edit
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                        <path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </span>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<!-- ─────────────────────────────────────────────────────────
     ADD SESSION MODAL — with Single / Recurring toggle.
     The toggle swaps which fields are visible and rewrites
     the hidden `action` so one form submits as either
     `add_session` or `batch_create`.
───────────────────────────────────────────────────────── -->
<div id="add-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <div class="modal-title">Add Session</div>
            <button class="btn-icon" onclick="document.getElementById('add-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="add-session-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="add-action" value="add_session">
            <input type="hidden" name="department" value="<?= e($college) ?>">

            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">

                <!-- Mode toggle -->
                <div class="seg-control" style="align-self:flex-start">
                    <button type="button" id="add-mode-single"
                            class="seg-control-item active"
                            onclick="setAddMode('single')">Single session</button>
                    <button type="button" id="add-mode-batch"
                            class="seg-control-item"
                            onclick="setAddMode('batch')">Recurring (multiple)</button>
                </div>

                <!-- Shared fields: interviewer + location -->
                <div>
                    <label class="form-label">Assigned Interviewer <span style="color:var(--error)">*</span></label>
                    <select name="assigned_to" class="form-control" required>
                        <option value="">— Select staff —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Location Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="location_label" class="form-control" required
                           placeholder="e.g. Room 201, Desk A">
                </div>
                <div>
                    <label class="form-label">
                        Directions / Notes
                        <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                    </label>
                    <input type="text" name="location_notes" class="form-control"
                           placeholder="e.g. 2nd floor, turn left at the bulletin board">
                </div>

                <!-- ── Single-session fields ─────────────────────── -->
                <div id="add-single-fields" style="display:flex;flex-direction:column;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                        <input type="date" name="slot_date" class="form-control"
                               value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                            <input type="time" name="slot_time" class="form-control" value="09:00">
                        </div>
                        <div>
                            <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                            <input type="time" name="slot_end_time" class="form-control" value="16:00">
                        </div>
                        <div>
                            <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                            <input type="number" name="capacity" class="form-control"
                                   value="30" min="1" max="500">
                        </div>
                    </div>
                </div>

                <!-- ── Recurring (batch) fields ──────────────────── -->
                <div id="add-batch-fields" style="display:none;flex-direction:column;gap:var(--space-4)">
                    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
                        One session is created per selected weekday in the date range.
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Start Date <span style="color:var(--error)">*</span></label>
                            <input type="date" name="start_date" class="form-control"
                                   value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        </div>
                        <div>
                            <label class="form-label">End Date <span style="color:var(--error)">*</span></label>
                            <input type="date" name="end_date" class="form-control"
                                   value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control" value="09:00">
                        </div>
                        <div>
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control" value="16:00">
                        </div>
                        <div>
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity_batch" class="form-control" value="30" min="1" max="500">
                        </div>
                    </div>
                    <div>
                        <label class="form-label" style="margin-bottom:var(--space-2)">Days of Week</label>
                        <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
                            <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri'] as $dn => $dl): ?>
                                <label class="form-check" style="gap:var(--space-1)">
                                    <input type="checkbox" name="days[]" value="<?= $dn ?>" checked>
                                    <?= $dl ?>
                                </label>
                            <?php endforeach; ?>
                            <label class="form-check" style="gap:var(--space-1)">
                                <input type="checkbox" name="days[]" value="6"> Sat
                            </label>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('add-session-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" id="add-submit">Add Session</button>
            </div>
        </form>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────
     EDIT SESSION MODAL — also hosts Close/Reopen and Delete
     so the cards themselves stay clean.
───────────────────────────────────────────────────────── -->
<div id="edit-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <div class="modal-title">Edit Session</div>
            <button class="btn-icon" onclick="document.getElementById('edit-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="edit-session-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit_session">
            <input type="hidden" name="slot_id" id="edit-sess-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Assigned Interviewer <span style="color:var(--error)">*</span></label>
                    <select name="assigned_to" id="edit-sess-assigned" class="form-control" required>
                        <option value="">— Select staff —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Location Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="location_label" id="edit-sess-label" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Directions / Notes</label>
                    <input type="text" name="location_notes" id="edit-sess-notes" class="form-control">
                </div>
                <div>
                    <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="slot_date" id="edit-sess-date" class="form-control" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" id="edit-sess-time" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_end_time" id="edit-sess-end" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                        <input type="number" name="capacity" id="edit-sess-cap" class="form-control"
                               min="1" max="500" required>
                    </div>
                </div>

                <div id="edit-sess-bookinfo"
                     style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:-4px"></div>
            </div>
            <div class="modal-footer" style="justify-content:space-between">
                <!-- Destructive actions on the left -->
                <div style="display:flex;gap:var(--space-2)">
                    <button type="button" id="edit-sess-toggle"
                            class="btn btn-ghost"
                            style="font-size:var(--text-xs)"
                            onclick="submitSessionAction(this.dataset.next)">
                        Close session
                    </button>
                    <button type="button" id="edit-sess-delete"
                            class="btn btn-ghost"
                            style="font-size:var(--text-xs);color:var(--error)"
                            onclick="if(confirm('Remove this session?'))submitSessionAction('delete_session')">
                        Remove
                    </button>
                </div>
                <div style="display:flex;gap:var(--space-2)">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('edit-session-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>

        <!-- Hidden form used to submit Close/Reopen/Delete actions -->
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="edit-action-form" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  id="edit-action-name">
            <input type="hidden" name="slot_id" id="edit-action-id">
        </form>
    </div>
</div>

<script>
// ── Add modal: switch between Single and Recurring fields ─────
function setAddMode(mode) {
    var single   = document.getElementById('add-single-fields');
    var batch    = document.getElementById('add-batch-fields');
    var btnS     = document.getElementById('add-mode-single');
    var btnB     = document.getElementById('add-mode-batch');
    var action   = document.getElementById('add-action');
    var submit   = document.getElementById('add-submit');

    var isBatch = mode === 'batch';
    single.style.display = isBatch ? 'none' : 'flex';
    batch.style.display  = isBatch ? 'flex' : 'none';
    btnS.classList.toggle('active', !isBatch);
    btnB.classList.toggle('active',  isBatch);
    action.value = isBatch ? 'batch_create' : 'add_session';
    submit.textContent = isBatch ? 'Create Sessions' : 'Add Session';

    // Disable inputs in the hidden block so they don't block submission
    // (and so capacity from the wrong block doesn't get sent).
    document.querySelectorAll('#add-single-fields [name]').forEach(function(el){ el.disabled = isBatch; });
    document.querySelectorAll('#add-batch-fields [name]').forEach(function(el){ el.disabled = !isBatch; });

    // Capacity in the batch block is named `capacity_batch` to avoid
    // colliding with the single field; rewrite it on submit so the
    // server still receives `capacity`.
    var capBatch = document.querySelector('#add-batch-fields [name="capacity_batch"], #add-batch-fields [name="capacity"]');
    if (capBatch) capBatch.name = isBatch ? 'capacity' : 'capacity_batch';
}
// Initialize on first render
document.addEventListener('DOMContentLoaded', function(){ setAddMode('single'); });

// ── Edit modal: open + prefill ────────────────────────────────
function openEditSession(data) {
    document.getElementById('edit-sess-id').value      = data.id;
    document.getElementById('edit-sess-date').value    = data.slot_date;
    document.getElementById('edit-sess-time').value    = data.slot_time;
    document.getElementById('edit-sess-end').value     = data.end_time;
    document.getElementById('edit-sess-cap').value     = data.capacity;
    document.getElementById('edit-sess-label').value   = data.location_label || '';
    document.getElementById('edit-sess-notes').value   = data.location_notes || '';
    var sel = document.getElementById('edit-sess-assigned');
    if (sel) sel.value = data.assigned_to || '';

    // Close vs Reopen label
    var toggle = document.getElementById('edit-sess-toggle');
    if (data.status === 'closed') {
        toggle.textContent = 'Reopen session';
        toggle.dataset.next = 'open_slot';
    } else {
        toggle.textContent = 'Close session';
        toggle.dataset.next = 'close_slot';
    }

    // Disable Remove if there are bookings
    var del = document.getElementById('edit-sess-delete');
    var info = document.getElementById('edit-sess-bookinfo');
    if (data.booked > 0) {
        del.disabled = true;
        del.style.opacity = '.45';
        del.title = 'Cannot remove a session with bookings';
        info.textContent = data.booked + ' applicant(s) booked — Remove disabled.';
    } else {
        del.disabled = false;
        del.style.opacity = '';
        del.title = '';
        info.textContent = '';
    }

    document.getElementById('edit-session-modal').style.display = 'flex';
}

function submitSessionAction(actionName) {
    var slotId = document.getElementById('edit-sess-id').value;
    if (!slotId || !actionName) return;
    document.getElementById('edit-action-name').value = actionName;
    document.getElementById('edit-action-id').value   = slotId;
    document.getElementById('edit-action-form').submit();
}

// Click outside the modal closes it
['add-session-modal','edit-session-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>
