<?php
// ============================================================
// modules/interview/staff_desks.php
// Interview Desk management — one desk per college/department.
// Admin can manage all desks; staff can only manage their own college.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$errors  = [];
$success = [];

// Auto-create table if missing (graceful upgrade)
try {
    $db->query("SELECT id FROM interview_desks LIMIT 0");
} catch (\Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS interview_desks (
        id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        department  VARCHAR(120)     NOT NULL DEFAULT '',
        desk_label  VARCHAR(120)     NOT NULL DEFAULT '',
        desk_notes  TEXT             DEFAULT NULL,
        is_active   TINYINT(1)      NOT NULL DEFAULT 1,
        created_by  INT(10) UNSIGNED NOT NULL,
        created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_desk_department (department)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$staffDept = user_department($staffId);

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_desk') {
        $dept  = $isAdmin ? trim($_POST['department'] ?? '') : $staffDept;
        $label = trim($_POST['desk_label'] ?? '');
        $notes = trim($_POST['desk_notes'] ?? '');

        if (!$dept)  $errors[] = 'College / Department is required.';
        if (!$label) $errors[] = 'Location label is required.';

        if (!$errors) {
            // Check if desk for this department already exists
            $check = $db->prepare('SELECT id FROM interview_desks WHERE department = ?');
            $check->execute([$dept]);
            if ($check->fetch()) {
                $errors[] = "A desk for \"{$dept}\" already exists. Edit it instead.";
            } else {
                $db->prepare(
                    'INSERT INTO interview_desks (department, desk_label, desk_notes, created_by) VALUES (?, ?, ?, ?)'
                )->execute([$dept, $label, $notes ?: null, $staffId]);
                audit_log('interview_desk_added', "Added desk for {$dept}: {$label}");
                $success[] = "Desk added for {$dept}.";
            }
        }
    }

    if ($action === 'edit_desk') {
        $deskId = (int)($_POST['desk_id'] ?? 0);
        $label  = trim($_POST['desk_label'] ?? '');
        $notes  = trim($_POST['desk_notes'] ?? '');
        $dept   = $isAdmin ? trim($_POST['department'] ?? '') : null;

        if (!$deskId) $errors[] = 'Invalid desk.';
        if (!$label)  $errors[] = 'Location label is required.';

        if (!$errors) {
            if ($isAdmin && $dept !== null) {
                $db->prepare('UPDATE interview_desks SET desk_label=?, desk_notes=?, department=? WHERE id=?')
                   ->execute([$label, $notes ?: null, $dept, $deskId]);
            } else {
                $db->prepare('UPDATE interview_desks SET desk_label=?, desk_notes=? WHERE id=?')
                   ->execute([$label, $notes ?: null, $deskId]);
            }
            audit_log('interview_desk_updated', "Updated desk #{$deskId}: {$label}");
            $success[] = 'Desk updated.';
        }
    }

    if ($action === 'delete_desk') {
        $deskId = (int)($_POST['desk_id'] ?? 0);
        if ($deskId) {
            $db->prepare('DELETE FROM interview_desks WHERE id=?')->execute([$deskId]);
            audit_log('interview_desk_deleted', "Deleted desk #{$deskId}");
            $success[] = 'Desk removed.';
        }
    }

    // PRG
    if (!$errors) {
        redirect('/staff/interviews/desks');
    }
}

// ── Load desks ────────────────────────────────────────────────
if ($isAdmin) {
    $desks = $db->query('SELECT * FROM interview_desks ORDER BY department ASC')->fetchAll();
} else {
    $stmt = $db->prepare('SELECT * FROM interview_desks WHERE department = ?');
    $stmt->execute([$staffDept]);
    $desks = $stmt->fetchAll();
}

// Departments that already have a desk
$usedDepts = array_column($desks, 'department');

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($s) ?></div>
<?php endforeach; ?>

<?php
    $availableDepts = array_diff(departments_list(), $usedDepts);
    $canAdd = $isAdmin ? !empty($availableDepts) : (!in_array($staffDept, $usedDepts) && $staffDept);
?>
<div style="display:flex;align-items:center;margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
</div>

<?php if (empty($desks)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm);display:flex;flex-direction:column;align-items:center;gap:var(--space-4)">
        <span>No desks configured yet.</span>
        <?php if ($canAdd): ?>
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-desk-modal').style.display='flex'">
                + Add Desk
            </button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table class="data-table" style="margin:0;width:100%">
        <thead>
            <tr>
                <th>College / Department</th>
                <th>Location</th>
                <th>Directions</th>
                <th style="width:120px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($desks as $desk): ?>
            <tr>
                <td style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($desk['department'] ?: '—') ?></td>
                <td><?= e($desk['desk_label']) ?></td>
                <td style="font-size:var(--text-sm);color:var(--text-secondary)"><?= e($desk['desk_notes'] ?? '—') ?></td>
                <td>
                    <div style="display:flex;gap:var(--space-2)">
                        <button class="btn btn-ghost btn-sm" style="font-size:var(--text-xs)"
                                onclick="openEditDesk(<?= (int)$desk['id'] ?>, '<?= e(addslashes($desk['department'])) ?>', '<?= e(addslashes($desk['desk_label'])) ?>', '<?= e(addslashes($desk['desk_notes'] ?? '')) ?>')">
                            Edit
                        </button>
                        <?php if ($isAdmin): ?>
                            <form method="POST" style="margin:0"
                                  onsubmit="return confirm('Remove this desk?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"  value="delete_desk">
                                <input type="hidden" name="desk_id" value="<?= (int)$desk['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:var(--text-xs);color:var(--error)">
                                    Remove
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($canAdd): ?>
        <div style="display:flex;justify-content:center;padding:var(--space-4)">
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('add-desk-modal').style.display='flex'">
                + Add Desk
            </button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Add Desk Modal -->
<div id="add-desk-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Add Desk</div>
            <button class="btn-icon" onclick="document.getElementById('add-desk-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_desk">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">College / Department <span style="color:var(--error)">*</span></label>
                    <?php if ($isAdmin): ?>
                        <select name="department" class="form-control" required>
                            <option value="">— Select college —</option>
                            <?php foreach ($availableDepts as $dept): ?>
                                <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= e($staffDept ?: 'Not assigned') ?>" disabled>
                        <input type="hidden" name="department" value="<?= e($staffDept) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Location <span style="color:var(--error)">*</span></label>
                    <input type="text" name="desk_label" class="form-control" required
                           placeholder="e.g. Room 201 — Admin Bldg, 2F">
                </div>
                <div>
                    <label class="form-label">
                        Directions
                        <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                    </label>
                    <input type="text" name="desk_notes" class="form-control"
                           placeholder="e.g. 2nd floor, turn left">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('add-desk-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Desk</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Desk Modal -->
<div id="edit-desk-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Edit Desk</div>
            <button class="btn-icon" onclick="document.getElementById('edit-desk-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit_desk">
            <input type="hidden" name="desk_id" id="edit-desk-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">College / Department</label>
                    <?php if ($isAdmin): ?>
                        <select name="department" id="edit-desk-dept" class="form-control">
                            <?php foreach (departments_list() as $dept): ?>
                                <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" id="edit-desk-dept-label" disabled>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Location <span style="color:var(--error)">*</span></label>
                    <input type="text" name="desk_label" id="edit-desk-label" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">
                        Directions
                        <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                    </label>
                    <input type="text" name="desk_notes" id="edit-desk-notes" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('edit-desk-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditDesk(id, dept, label, notes) {
    document.getElementById('edit-desk-id').value = id;
    document.getElementById('edit-desk-label').value = label;
    document.getElementById('edit-desk-notes').value = notes;
    var deptSelect = document.getElementById('edit-desk-dept');
    if (deptSelect) { deptSelect.value = dept; }
    var deptLabel = document.getElementById('edit-desk-dept-label');
    if (deptLabel) { deptLabel.value = dept || 'Not assigned'; }
    document.getElementById('edit-desk-modal').style.display = 'flex';
}

['add-desk-modal','edit-desk-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Desks';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
