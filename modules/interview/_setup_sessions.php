<?php
// _setup_sessions.php — flat session list for one college.
// Variables in scope: $college, $slots, $byDate, $staffList,
// $isAdmin, $errors, $today, $showPast.

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
<div style="display:flex;align-items:center;margin-bottom:var(--space-4)">
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

<!-- Tab strip + actions -->
<div style="display:flex;align-items:center;margin-bottom:var(--space-5);gap:var(--space-3);flex-wrap:wrap">
    <div class="seg-control">
        <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($college) ?>"
           class="seg-control-item <?= !$showPast ? 'active' : '' ?>">
            Upcoming
        </a>
        <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($college) ?>&past=1"
           class="seg-control-item <?= $showPast ? 'active' : '' ?>">
            Past
        </a>
    </div>
    <div style="margin-left:auto;display:flex;gap:var(--space-2)">
        <button type="button" class="btn btn-sm"
                onclick="document.getElementById('batch-create-modal').style.display='flex'"
                style="font-size:var(--text-xs)">
            <?= icon('ic_fluent_calendar_add_24_regular', 14) ?>
            Batch Create
        </button>
        <button type="button" class="btn btn-primary btn-sm"
                onclick="document.getElementById('add-session-modal').style.display='flex'"
                style="font-size:var(--text-xs)">
            + Add Session
        </button>
    </div>
</div>

<style>
    .session-card {
        padding:var(--space-4) var(--space-5);
    }
    .session-card .who {
        display:flex;align-items:center;gap:var(--space-2);
        font-weight:var(--weight-semibold);font-size:var(--text-sm);
        color:var(--text-primary);
    }
    .session-card .where {
        display:flex;align-items:center;gap:var(--space-1);
        font-size:var(--text-xs);color:var(--text-secondary);margin-top:var(--space-1);
    }
    .session-card .notes {
        font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1);
    }
    .session-card .time {
        min-width:140px;font-size:var(--text-sm);
        font-weight:var(--weight-medium);color:var(--text-secondary);
    }
    @media (max-width: 720px) {
        .session-card .time { min-width:auto; }
    }
</style>

<!-- Sessions list -->
<?php if (empty($byDate)): ?>
    <div style="text-align:center;padding:var(--space-16) var(--space-4);color:var(--text-tertiary);
                 display:flex;flex-direction:column;align-items:center;gap:var(--space-4)">
        <div style="font-size:var(--text-sm)">
            <?= $showPast ? 'No past sessions.' : 'No upcoming sessions yet.' ?>
        </div>
        <?php if (!$showPast): ?>
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-session-modal').style.display='flex'">
                + Add Session
            </button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:var(--space-2)">
    <?php foreach ($byDate as $date => $dateSlots): ?>
        <div style="display:flex;align-items:center;gap:var(--space-3);
                     padding:var(--space-3) 0 var(--space-1);
                     color:var(--text-tertiary);font-size:var(--text-xs);
                     font-weight:var(--weight-medium);letter-spacing:.04em">
            <span><?= format_date($date, 'l, F j, Y') ?></span>
            <?php if ($date === $today): ?>
                <span class="badge badge-info" style="letter-spacing:0">Today</span>
            <?php endif; ?>
            <div style="flex:1;height:1px;background:var(--border)"></div>
        </div>

        <?php foreach ($dateSlots as $slot):
            $booked    = (int)$slot['booked'];
            $capacity  = (int)$slot['capacity'];
            $nowTime   = date('H:i:s');
            $isExpired = _session_expired($date, $slot['end_time'] ?? null, $today, $nowTime);
            $isClosed  = $slot['status'] === 'closed' || $isExpired;
            $isFull    = $booked >= $capacity;
            $canDelete = $booked === 0;
            $fillPct   = $capacity > 0 ? min(100, round(($booked / $capacity) * 100)) : 0;

            $timeLabel = 'All day';
            if ($slot['slot_time']) {
                $timeLabel = format_time($slot['slot_time']);
                if ($slot['end_time']) $timeLabel .= ' – ' . format_time($slot['end_time']);
            }
        ?>
            <div class="card session-card">
                <div style="display:flex;align-items:flex-start;gap:var(--space-4);flex-wrap:wrap">

                    <!-- Time block -->
                    <div class="time">
                        <?= $timeLabel ?>
                    </div>

                    <!-- Person + location block (the centerpiece) -->
                    <div style="flex:1;min-width:200px">
                        <div class="who">
                            <?= icon('ic_fluent_people_24_regular', 14) ?>
                            <?= e($slot['interviewer_name'] ?? 'Unassigned') ?>
                        </div>
                        <?php if (!empty($slot['location_label'])): ?>
                            <div class="where">
                                <?= icon('ic_fluent_location_24_regular', 12) ?>
                                <?= e($slot['location_label']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($slot['location_notes'])): ?>
                            <div class="notes">
                                <?= e($slot['location_notes']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Booking progress -->
                    <div style="flex:1;min-width:160px">
                        <div style="display:flex;align-items:baseline;gap:var(--space-2);margin-bottom:var(--space-2)">
                            <span style="font-weight:var(--weight-semibold);font-size:var(--text-sm)"><?= $booked ?></span>
                            <span style="color:var(--text-tertiary);font-size:var(--text-xs)">/ <?= $capacity ?> booked</span>
                        </div>
                        <div style="height:3px;border-radius:99px;background:var(--border);overflow:hidden">
                            <div style="height:100%;width:<?= $fillPct ?>%;border-radius:99px;
                                         background:<?= $isClosed ? 'var(--border-strong)' : ($isFull ? 'var(--warning)' : 'var(--accent)') ?>;
                                         transition:width .3s ease"></div>
                        </div>
                    </div>

                    <!-- Status -->
                    <div>
                        <?php if ($isExpired && $slot['status'] !== 'closed'): ?>
                            <span class="badge badge-neutral">Ended</span>
                        <?php elseif ($isClosed): ?>
                            <span class="badge badge-neutral">Closed</span>
                        <?php elseif ($isFull): ?>
                            <span class="badge badge-review">Full</span>
                        <?php else: ?>
                            <span class="badge badge-approved">Open</span>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;align-items:center;gap:var(--space-1);flex-wrap:wrap">
                        <a href="<?= url('/staff/interviews/' . $slot['id'] . '/roster') ?>"
                           class="btn btn-ghost btn-sm">Roster (<?= $booked ?>)</a>

                        <?php if (!$isExpired): ?>
                            <button type="button" class="btn-icon" title="Edit session"
                                    style="padding:var(--space-1)"
                                    onclick='openEditSession(<?= json_encode([
                                        "id"             => (int)$slot["id"],
                                        "slot_date"      => $slot["slot_date"],
                                        "slot_time"      => substr($slot["slot_time"] ?? "", 0, 5),
                                        "end_time"       => substr($slot["end_time"] ?? "", 0, 5),
                                        "capacity"       => (int)$slot["capacity"],
                                        "assigned_to"    => (int)($slot["assigned_to"] ?? 0),
                                        "location_label" => $slot["location_label"] ?? "",
                                        "location_notes" => $slot["location_notes"] ?? "",
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <?= icon('ic_fluent_edit_24_regular', 14) ?>
                            </button>

                            <form method="POST" action="<?= url('/staff/interviews/' . $slot['id']) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"
                                       value="<?= $slot['status'] === 'closed' ? 'open_slot' : 'close_slot' ?>">
                                <button class="btn btn-ghost btn-sm">
                                    <?= $slot['status'] === 'closed' ? 'Reopen' : 'Close' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canDelete): ?>
                            <form method="POST" action="<?= url('/staff/interviews/setup') ?>"
                                  onsubmit="return confirm('Remove this session?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"  value="delete_session">
                                <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                <button class="btn-icon" style="color:var(--error);padding:var(--space-1)"
                                        title="Remove session">
                                    <?= icon('ic_fluent_delete_24_regular', 14) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ── Add Session Modal ─────────────────────────────────── -->
<div id="add-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <div class="modal-title">Add Session</div>
            <button class="btn-icon" onclick="document.getElementById('add-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_session">
            <input type="hidden" name="department" value="<?= e($college) ?>">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
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
                <div>
                    <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="slot_date" class="form-control"
                           value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" class="form-control" value="09:00" required>
                    </div>
                    <div>
                        <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_end_time" class="form-control" value="16:00" required>
                    </div>
                    <div>
                        <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                        <input type="number" name="capacity" class="form-control"
                               value="30" min="1" max="500" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('add-session-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Session</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Session Modal ────────────────────────────────── -->
<div id="edit-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <div class="modal-title">Edit Session</div>
            <button class="btn-icon" onclick="document.getElementById('edit-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>">
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
                               min="1" max="500" value="30" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('edit-session-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Batch Create Modal ────────────────────────────────── -->
<div id="batch-create-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <div class="modal-title">Batch Create Sessions</div>
            <button class="btn-icon" onclick="document.getElementById('batch-create-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="batch_create">
            <input type="hidden" name="department" value="<?= e($college) ?>">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
                    Create one session per selected weekday within the date range, all sharing the same
                    interviewer, location, and capacity.
                </p>
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
                    <label class="form-label">Directions / Notes</label>
                    <input type="text" name="location_notes" class="form-control"
                           placeholder="optional">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Start Date <span style="color:var(--error)">*</span></label>
                        <input type="date" name="start_date" class="form-control"
                               value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </div>
                    <div>
                        <label class="form-label">End Date <span style="color:var(--error)">*</span></label>
                        <input type="date" name="end_date" class="form-control"
                               value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required>
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
                        <input type="number" name="capacity" class="form-control" value="30" min="1" max="500">
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
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('batch-create-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Sessions</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditSession(data) {
    document.getElementById('edit-sess-id').value       = data.id;
    document.getElementById('edit-sess-date').value     = data.slot_date;
    document.getElementById('edit-sess-time').value     = data.slot_time;
    document.getElementById('edit-sess-end').value      = data.end_time;
    document.getElementById('edit-sess-cap').value      = data.capacity;
    document.getElementById('edit-sess-label').value    = data.location_label || '';
    document.getElementById('edit-sess-notes').value    = data.location_notes || '';
    var sel = document.getElementById('edit-sess-assigned');
    if (sel) sel.value = data.assigned_to || '';
    document.getElementById('edit-session-modal').style.display = 'flex';
}
['add-session-modal','edit-session-modal','batch-create-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>
