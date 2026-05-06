<?php
// _setup_desks.php — Desk card grid for a college
// Variables: $college, $desks, $staffList, $isAdmin, $errors, $success, $today
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

<div style="display:flex;align-items:center;margin-bottom:var(--space-5)">
    <?php if ($isAdmin): ?>
        <a href="<?= url('/staff/interviews/setup') ?>" class="btn btn-ghost btn-sm">← All Colleges</a>
    <?php else: ?>
        <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
    <?php endif; ?>
    <div style="flex:1;text-align:center">
        <span style="font-weight:var(--weight-semibold);font-size:var(--text-base)"><?= e($college) ?></span>
        <span style="color:var(--text-tertiary);font-size:var(--text-sm)"> — Interview Desks</span>
    </div>
    <div style="width:80px"></div>
</div>

<style>
    .desk-grid {
        display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));
        gap:var(--space-4);max-width:960px;margin:0 auto;
    }
    .desk-card {
        display:flex;flex-direction:column;
        padding:var(--space-6) var(--space-5);
        background:var(--bg-elevated);border:1.5px solid var(--border);
        border-radius:var(--radius-lg);text-decoration:none;color:var(--text-primary);
        transition:border-color .18s,box-shadow .18s,transform .15s;cursor:pointer;
        position:relative;
    }
    .desk-card:hover {
        border-color:var(--accent);box-shadow:0 6px 20px rgba(0,0,0,.07);transform:translateY(-3px);
    }
    .desk-card-add {
        display:flex;flex-direction:column;align-items:center;justify-content:center;
        gap:var(--space-3);padding:var(--space-8) var(--space-5);
        background:var(--bg-subtle);border:2px dashed var(--border);
        border-radius:var(--radius-lg);cursor:pointer;color:var(--text-tertiary);
        transition:border-color .18s,color .18s;
    }
    .desk-card-add:hover {
        border-color:var(--accent);color:var(--accent);
    }
    .desk-card-label {
        font-size:var(--text-base);font-weight:var(--weight-semibold);margin-bottom:var(--space-1);
    }
    .desk-card-interviewer {
        font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-2);
        display:flex;align-items:center;gap:var(--space-1);
    }
    .desk-card-meta {
        font-size:var(--text-xs);color:var(--text-tertiary);
        display:flex;align-items:center;gap:var(--space-2);margin-top:auto;padding-top:var(--space-3);
    }
    .desk-card-notes {
        font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1);
    }
    .desk-card-actions {
        position:absolute;top:var(--space-2);right:var(--space-2);
        display:flex;gap:var(--space-1);opacity:0;transition:opacity .15s;
    }
    .desk-card:hover .desk-card-actions { opacity:1; }
</style>

<div class="desk-grid">
    <?php foreach ($desks as $desk): ?>
        <a href="<?= url('/staff/interviews/setup') ?>?desk=<?= (int)$desk['id'] ?>" class="desk-card">
            <div class="desk-card-actions">
                <button type="button" class="btn-icon" style="padding:2px"
                        onclick="event.preventDefault();event.stopPropagation();openEditDesk(<?= (int)$desk['id'] ?>, '<?= e(addslashes($desk['desk_label'])) ?>', '<?= e(addslashes($desk['desk_notes'] ?? '')) ?>', <?= (int)($desk['assigned_to'] ?? 0) ?>)"
                        title="Edit desk">
                    <?= icon('ic_fluent_edit_24_regular', 13) ?>
                </button>
                <form method="POST" style="margin:0"
                      onclick="event.stopPropagation()"
                      onsubmit="event.preventDefault();if(confirm('Remove this desk and all its sessions?'))this.submit()">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_desk">
                    <input type="hidden" name="desk_id" value="<?= (int)$desk['id'] ?>">
                    <button type="submit" class="btn-icon" style="padding:2px;color:var(--error)" title="Remove desk">
                        <?= icon('ic_fluent_delete_24_regular', 13) ?>
                    </button>
                </form>
            </div>

            <div class="desk-card-label"><?= e($desk['desk_label']) ?></div>
            <div class="desk-card-interviewer">
                <?= icon('ic_fluent_people_24_regular', 14) ?>
                <?= e($desk['interviewer_name'] ?? 'Unassigned') ?>
            </div>
            <?php if ($desk['desk_notes']): ?>
                <div class="desk-card-notes">
                    <?= icon('ic_fluent_location_24_regular', 11) ?>
                    <?= e($desk['desk_notes']) ?>
                </div>
            <?php endif; ?>
            <div class="desk-card-meta">
                <?= icon('ic_fluent_calendar_ltr_24_regular', 12) ?>
                <?= (int)$desk['upcoming'] ?> upcoming session<?= (int)$desk['upcoming'] !== 1 ? 's' : '' ?>
            </div>
        </a>
    <?php endforeach; ?>

    <!-- + Add Desk -->
    <div class="desk-card-add"
         onclick="document.getElementById('add-desk-modal').style.display='flex'">
        <div style="width:48px;height:48px;border-radius:50%;border:2px dashed currentColor;
                     display:flex;align-items:center;justify-content:center;font-size:1.5rem">
            +
        </div>
        <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Add Desk</div>
    </div>
</div>

<!-- ── Add Desk Modal ────────────────────────────────────── -->
<div id="add-desk-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Add Interview Desk</div>
            <button class="btn-icon" onclick="document.getElementById('add-desk-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_desk">
            <input type="hidden" name="department" value="<?= e($college) ?>">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">College</label>
                    <input type="text" class="form-control" value="<?= e($college) ?>" disabled>
                </div>
                <div>
                    <label class="form-label">Desk Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="desk_label" class="form-control" required
                           placeholder="e.g. Desk A, Room 201">
                </div>
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
                    <label class="form-label">
                        Location / Directions
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

<!-- ── Edit Desk Modal ───────────────────────────────────── -->
<div id="edit-desk-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Edit Desk</div>
            <button class="btn-icon" onclick="document.getElementById('edit-desk-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit_desk">
            <input type="hidden" name="desk_id" id="edit-desk-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Desk Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="desk_label" id="edit-desk-label" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Assigned Interviewer</label>
                    <select name="assigned_to" id="edit-desk-assigned" class="form-control">
                        <option value="">— Select staff —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Location / Directions</label>
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
function openEditDesk(id, label, notes, assignedTo) {
    document.getElementById('edit-desk-id').value = id;
    document.getElementById('edit-desk-label').value = label;
    document.getElementById('edit-desk-notes').value = notes;
    var sel = document.getElementById('edit-desk-assigned');
    if (sel) sel.value = assignedTo || '';
    document.getElementById('edit-desk-modal').style.display = 'flex';
}

['add-desk-modal','edit-desk-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>
