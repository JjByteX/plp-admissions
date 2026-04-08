<?php
// ============================================================
// modules/interview/staff_manage.php
// M5 — Staff: create interview slots, view schedule
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST — create slot(s)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_slots') {
        $date  = trim($_POST['slot_date'] ?? '');
        $times = array_filter(array_map('trim', explode(',', $_POST['slot_times'] ?? '')));

        if (!$date || empty($times)) {
            $errors[] = 'Please provide a date and at least one time slot.';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO interview_slots (slot_date, slot_time, created_by) VALUES (?,?,?)'
            );
            $created = 0;
            foreach ($times as $t) {
                try {
                    $stmt->execute([$date, $t, $staffId]);
                    $created++;
                } catch (PDOException) {
                    // skip duplicates
                }
            }
            $success[] = "$created slot(s) created for " . format_date($date) . ".";
        }
    }
}

// ----------------------------------------------------------------
// Load upcoming + past slots
// ----------------------------------------------------------------
$filter = $_GET['filter'] ?? 'upcoming';
$today  = date('Y-m-d');

if ($filter === 'past') {
    $stmt = $db->prepare(
        'SELECT s.*, a.id AS app_id, u.name AS student_name
         FROM interview_slots s
         LEFT JOIN applicants a ON a.id = s.assigned_applicant_id
         LEFT JOIN users u ON u.id = a.user_id
         WHERE s.slot_date < ?
         ORDER BY s.slot_date DESC, s.slot_time DESC LIMIT 100'
    );
    $stmt->execute([$today]);
} else {
    $stmt = $db->prepare(
        'SELECT s.*, a.id AS app_id, u.name AS student_name
         FROM interview_slots s
         LEFT JOIN applicants a ON a.id = s.assigned_applicant_id
         LEFT JOIN users u ON u.id = a.user_id
         WHERE s.slot_date >= ?
         ORDER BY s.slot_date, s.slot_time LIMIT 200'
    );
    $stmt->execute([$today]);
}
$slots = $stmt->fetchAll();

// Group by date
$byDate = [];
foreach ($slots as $slot) {
    $byDate[$slot['slot_date']][] = $slot;
}

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<!-- Filter tabs + Add Slots on one line -->
<div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:var(--space-5);border-bottom:1px solid var(--border)">
    <div style="display:flex;gap:var(--space-1)">
        <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past'] as $val => $lbl): ?>
            <a href="?filter=<?= $val ?>"
               style="padding:var(--space-2) var(--space-4);border-bottom:2px solid <?= $filter===$val ? 'var(--accent)' : 'transparent' ?>;
                      color:<?= $filter===$val ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                      font-size:var(--text-sm);font-weight:<?= $filter===$val ? 'var(--weight-semibold)' : '' ?>;
                      text-decoration:none;white-space:nowrap;margin-bottom:-1px">
                <?= $lbl ?>
            </a>
        <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-sm" style="margin-bottom:var(--space-2)"
            onclick="document.getElementById('create-slots-modal').style.display='flex'">
        + Add Slots
    </button>
</div>

<?php if (empty($byDate)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm)">
        No <?= $filter ?> slots.
    </div>
<?php else: ?>
    <?php foreach ($byDate as $date => $dateSlots): ?>
        <div style="margin-bottom:var(--space-6)">
            <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-3);color:var(--text-primary)">
                <?= format_date($date, 'l, F j, Y') ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:var(--space-2)">
            <?php foreach ($dateSlots as $slot): ?>
                <div class="card" style="padding:var(--space-3) var(--space-5)">
                    <div style="display:flex;align-items:center;gap:var(--space-4)">
                        <div style="font-weight:var(--weight-medium);min-width:80px"><?= format_time($slot['slot_time']) ?></div>
                        <?php if ($slot['student_name']): ?>
                            <div style="flex:1">
                                <a href="<?= url('/staff/applicants/' . $slot['app_id']) ?>"
                                   style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--accent)">
                                    <?= e($slot['student_name']) ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="flex:1;font-size:var(--text-sm);color:var(--text-tertiary)">Unbooked</div>
                        <?php endif; ?>
                        <?php
                        $statusColors = [
                            'open'      => 'badge-success',
                            'scheduled' => 'badge-info',
                            'completed' => 'badge-neutral',
                            'no_show'   => 'badge-error',
                        ];
                        ?>
                        <span class="badge <?= $statusColors[$slot['status']] ?? 'badge-neutral' ?>">
                            <?= e(ucfirst(str_replace('_',' ',$slot['status']))) ?>
                        </span>
                        <?php if (in_array($slot['status'], ['scheduled'], true)): ?>
                            <div style="display:flex;gap:var(--space-2)">
                                <form method="POST" action="<?= url('/staff/interviews/' . $slot['id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_completed">
                                    <button class="btn btn-success btn-sm">Done</button>
                                </form>
                                <form method="POST" action="<?= url('/staff/interviews/' . $slot['id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_no_show">
                                    <button class="btn btn-danger btn-sm">No-show</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php if ($slot['status'] === 'open'): ?>
                            <form method="POST" action="<?= url('/staff/interviews/' . $slot['id']) ?>"
                                  onsubmit="return confirm('Delete this slot?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_slot">
                                <button class="btn-icon" style="color:var(--error)">
                                    <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18m-2 0V20a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Create slots modal -->
<div id="create-slots-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title">Add Interview Slots</div>
            <button class="btn-icon" onclick="document.getElementById('create-slots-modal').style.display='none'">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_slots">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="slot_date" class="form-control" min="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label class="form-label">Times <span style="color:var(--error)">*</span></label>
                    <!-- Hidden input that collects selected times for form submission -->
                    <input type="hidden" name="slot_times" id="slot-times-value" required>
                    <!-- Visual time grid -->
                    <div id="time-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:6px"></div>
                    <p id="time-selection-hint" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">
                        Click to select one or more times.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('create-slots-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" id="create-slots-submit">Create Slots</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    // Generate time options from 07:00 to 18:30 in 30-min steps
    const times = [];
    for (let h = 7; h <= 18; h++) {
        for (let m = 0; m < 60; m += 30) {
            if (h === 18 && m > 30) break;
            const hh = String(h).padStart(2, '0');
            const mm = String(m).padStart(2, '0');
            times.push(`${hh}:${mm}`);
        }
    }

    const selected = new Set();
    const grid = document.getElementById('time-grid');
    const hidden = document.getElementById('slot-times-value');
    const hint = document.getElementById('time-selection-hint');

    function fmt(t) {
        const [h, m] = t.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return `${h12}:${String(m).padStart(2,'0')} ${ampm}`;
    }

    function updateHidden() {
        const vals = [...selected].sort();
        hidden.value = vals.join(',');
        const n = vals.length;
        hint.textContent = n === 0
            ? 'Click to select one or more times.'
            : `${n} slot${n > 1 ? 's' : ''} selected: ${vals.map(fmt).join(', ')}`;
    }

    times.forEach(t => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = fmt(t);
        btn.dataset.time = t;
        btn.style.cssText = `
            padding:6px 4px;border-radius:var(--radius-md);font-size:11px;
            border:1.5px solid var(--border);background:var(--bg-secondary);
            color:var(--text-primary);cursor:pointer;transition:all .15s;font-weight:500;
        `;
        btn.addEventListener('click', () => {
            if (selected.has(t)) {
                selected.delete(t);
                btn.style.background = 'var(--bg-secondary)';
                btn.style.borderColor = 'var(--border)';
                btn.style.color = 'var(--text-primary)';
            } else {
                selected.add(t);
                btn.style.background = 'var(--accent)';
                btn.style.borderColor = 'var(--accent)';
                btn.style.color = '#fff';
            }
            updateHidden();
        });
        grid.appendChild(btn);
    });

    // Clear selections when modal is closed
    document.querySelector('[onclick*="create-slots-modal"]')?.addEventListener('click', () => {
        selected.clear();
        grid.querySelectorAll('button').forEach(b => {
            b.style.background = 'var(--bg-secondary)';
            b.style.borderColor = 'var(--border)';
            b.style.color = 'var(--text-primary)';
        });
        updateHidden();
    });

    // Validate at least one time selected on submit
    document.getElementById('create-slots-submit').addEventListener('click', function(e) {
        if (selected.size === 0) {
            e.preventDefault();
            hint.textContent = 'Please select at least one time.';
            hint.style.color = 'var(--error)';
        }
    });
})();
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Schedule';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
