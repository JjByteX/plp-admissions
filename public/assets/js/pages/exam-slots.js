['add-slot-modal','exam-config-modal','edit-slot-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if (m) m.addEventListener('click', function (e) { if (e.target === this) this.style.display = 'none'; });
});

// ── Date filter on the slot card grid ────────────────────────
// Mirrors the interview queue's date dropdown (see staff_queue.php).
// Pure client-side: hide cards whose data-date doesn't match the
// selected value, then rebuild the "N slots · X / Y seats filled"
// summary line so the badge always reflects what's visible.
(function() {
    var dateEl  = document.getElementById('es-date-filter');
    var countEl = document.getElementById('es-slot-count');
    if (!dateEl) return;

    function applyEsDateFilter() {
        var picked  = dateEl.value;
        var visible = 0, filled = 0, capacity = 0;
        document.querySelectorAll('.es-slot-grid .es-slot-card').forEach(function(card) {
            var d = card.getAttribute('data-date') || '';
            var match = !picked || d === picked;
            card.style.display = match ? '' : 'none';
            if (match) {
                visible++;
                filled   += parseInt(card.getAttribute('data-filled')   || '0', 10);
                capacity += parseInt(card.getAttribute('data-capacity') || '0', 10);
            }
        });
        if (countEl) {
            countEl.innerHTML = visible + ' slot' + (visible === 1 ? '' : 's')
                + ' \u00a0\u00b7\u00a0 ' + filled + ' / ' + capacity + ' seats filled';
        }
    }
    dateEl.addEventListener('change', applyEsDateFilter);
})();

// ── Bulk select mode for exam slot cards ─────────────────────
// Shared "select mode" logic lives in createCardBulkSelector()
// (public/assets/js/app.js), which loads after this inline block.
// _esSelector() builds it lazily on first call instead of at parse
// time, so load order doesn't matter. These wrappers keep the same
// global names the markup already calls via onclick=, so no HTML
// changed.
var __esSelectorInstance = null;
function _esSelector() {
    if (!__esSelectorInstance) {
        __esSelectorInstance = createCardBulkSelector({
            gridSel:     '.es-slot-grid',
            cardSel:     '.es-slot-card',
            checkboxSel: '.es-select-checkbox',
            barId:       'es-bulk-bar',
            toggleBtnId: 'es-select-toggle',
            countId:     'es-bulk-count',
            deleteBtnId: 'es-bulk-delete-btn',
            idsInputId:  'es-bulk-ids',
            confirmMsg:  function (n) { return 'Remove ' + n + ' slot(s)? This cannot be undone.'; },
        });
    }
    return __esSelectorInstance;
}
function _esGrid()              { return document.querySelector('.es-slot-grid'); }
function toggleEsSelectMode()   { _esSelector().toggleSelectMode(); }
function cancelEsSelectMode()   { _esSelector().cancelSelectMode(); }
function onEsCheckboxChange(cb) { _esSelector().onCheckboxChange(cb); }
function confirmEsBulkDelete(b) { return _esSelector().confirmBulkDelete(b); }
function onEsCardClick(event, link) {
    var grid = _esGrid();
    if (grid && grid.classList.contains('is-selecting')) {
        // While selecting, the card toggles its checkbox instead of navigating
        // to the roster. Disabled (filled) cards do nothing.
        event.preventDefault();
        var cb = link.querySelector('.es-select-checkbox');
        if (cb && !cb.disabled) {
            cb.checked = !cb.checked;
            onEsCheckboxChange(cb);
        }
        return false;
    }
    return true;
}

// When the user changes the open time, auto-bump the close time to
// open + 90 minutes — but only if they haven't manually moved the
// close field yet (so we don't overwrite an explicit close on edit).
function _autoEnd(openId, closeId) {
    var openEl  = document.getElementById(openId);
    var closeEl = document.getElementById(closeId);
    if (!openEl || !closeEl) return;
    closeEl.addEventListener('input', function () { closeEl.dataset.userset = '1'; });
    openEl.addEventListener('change', function () {
        if (closeEl.dataset.userset === '1') return;
        var parts = (openEl.value || '').split(':');
        if (parts.length !== 2) return;
        var d = new Date();
        d.setHours(parseInt(parts[0], 10) || 0, (parseInt(parts[1], 10) || 0) + 90, 0, 0);
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        closeEl.value = hh + ':' + mm;
    });
}
_autoEnd('add-slot-time',   'add-slot-end');
_autoEnd('edit-slot-time',  'edit-slot-end');
_autoEnd('batch-time',      'batch-end-time');

// ── Add Slot modal: Single / Recurring toggle ────────────────
function setSlotMode(mode) {
    var single = document.getElementById('add-slot-single-fields');
    var batch  = document.getElementById('add-slot-batch-fields');
    var btnS   = document.getElementById('add-slot-mode-single');
    var btnB   = document.getElementById('add-slot-mode-batch');
    var action = document.getElementById('add-slot-action');
    var submit = document.getElementById('add-slot-submit');
    var dept   = document.getElementById('add-slot-dept');
    if (!single || !batch) return;

    var isBatch = mode === 'batch';
    single.style.display = isBatch ? 'none' : 'flex';
    batch.style.display  = isBatch ? 'flex' : 'none';
    if (btnS) btnS.classList.toggle('active', !isBatch);
    if (btnB) btnB.classList.toggle('active',  isBatch);
    if (action) action.value = isBatch ? 'batch_create_slots' : 'add_slot';
    if (submit) submit.textContent = isBatch ? 'Create Slots' : 'Add Slot';

    // Disable inputs in the inactive block so they don't post and don't
    // block submission via HTML5 `required` validation.
    document.querySelectorAll('#add-slot-single-fields [name]').forEach(function(el){ el.disabled = isBatch; });
    document.querySelectorAll('#add-slot-batch-fields  [name]').forEach(function(el){ el.disabled = !isBatch; });

    // The shared College/Department field posts as `department` for the
    // single-slot handler and `batch_department` for the batch handler.
    if (dept) dept.name = isBatch ? 'batch_department' : 'department';
}
document.addEventListener('DOMContentLoaded', function(){ setSlotMode('single'); });

function addBatchRoom() {
    var list = document.getElementById('batch-rooms-list');
    if (!list) return;
    var wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;gap:var(--space-2);align-items:center';

    var input = document.createElement('input');
    input.type        = 'text';
    input.name        = 'batch_rooms[]';
    input.className   = 'form-control';
    input.placeholder = 'e.g. Room 102';
    input.required    = true;
    input.style.flex  = '1';

    var removeBtn = document.createElement('button');
    removeBtn.type      = 'button';
    removeBtn.className = 'btn-icon';
    removeBtn.title     = 'Remove';
    removeBtn.style.cssText = 'color:var(--error);padding:var(--space-1);flex-shrink:0';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick   = function () { wrapper.remove(); };

    wrapper.appendChild(input);
    wrapper.appendChild(removeBtn);
    list.appendChild(wrapper);
    input.focus();
}

function openEditExamSlot(payload) {
    if (!payload) return;
    document.getElementById('edit-slot-id').value   = payload.id;
    document.getElementById('edit-slot-date').value = payload.exam_date;
    document.getElementById('edit-slot-time').value = payload.slot_time || '';
    document.getElementById('edit-slot-end').value  = payload.end_time  || '';
    document.getElementById('edit-slot-room').value = payload.room_label || '';
    document.getElementById('edit-slot-cap').value  = payload.capacity || '';

    var deptSel = document.getElementById('edit-slot-dept');
    if (deptSel) deptSel.value = payload.department || '';

    // Wire the inline delete button to the same slot id
    var delIn = document.getElementById('delete-slot-id');
    if (delIn) delIn.value = payload.id;
    var delBtn = document.getElementById('edit-slot-delete-btn');
    if (delBtn) {
        delBtn.disabled = (parseInt(payload.filled, 10) || 0) > 0;
        delBtn.title    = delBtn.disabled
            ? 'Cannot delete a slot with applicants assigned. Unassign them first.'
            : '';
    }

    document.getElementById('edit-slot-modal').style.display = 'flex';
}

// ── Per-room access code (Chunk 7) ────────────────────────────
//
// Drives the proctor-facing code panel on the slot roster page,
// plus the small "code active" badge on the college slot grid.

function _csrfPair() {
    var i = document.querySelector('input[name^="_csrf"]');
    return i ? { name: i.name, value: i.value } : null;
}

function _formatMMSS(s) {
    if (s < 0) s = 0;
    var m = Math.floor(s / 60);
    var r = s % 60;
    return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
}

// Roster countdown — single slot, controls visible if you can generate
var _slotCodeTimer = null;
function startSlotCodeCountdown(secsLeft) {
    if (_slotCodeTimer) clearInterval(_slotCodeTimer);
    var timerEl   = document.getElementById('slot-code-timer');
    var displayEl = document.getElementById('slot-code-display');
    var extendBtn = document.getElementById('slot-code-extend-btn');
    var genBtn    = document.getElementById('slot-code-generate-btn');
    if (!timerEl) return;

    function render(s) {
        timerEl.classList.remove('is-warn', 'is-expired');
        if (s > 0) {
            timerEl.style.display = '';
            timerEl.textContent = 'Expires in ' + _formatMMSS(s);
            if (s <= 60) timerEl.classList.add('is-warn');
            if (extendBtn) extendBtn.style.display = 'inline-flex';
            if (genBtn)    genBtn.style.display    = 'inline-flex';
        } else {
            timerEl.style.display = '';
            timerEl.textContent = 'Expired - reissuing…';
            timerEl.classList.add('is-expired');
            if (displayEl) {
                displayEl.classList.add('is-empty');
                displayEl.textContent = 'No active code';
            }
            if (extendBtn) extendBtn.style.display = 'none';
            if (genBtn)    genBtn.style.display    = 'none';
        }
    }
    render(secsLeft);
    if (secsLeft > 0) {
        _slotCodeTimer = setInterval(function () {
            secsLeft--;
            render(secsLeft);
            if (secsLeft <= 0) {
                clearInterval(_slotCodeTimer); _slotCodeTimer = null;
                // Auto-refresh so the server-side auto-issue logic on
                // staff_slots.php picks up where we left off and rolls
                // a fresh 5-minute code without proctor intervention.
                setTimeout(function () { location.reload(); }, 800);
            }
        }, 1000);
    }
}

// Auto-start the roster countdown if the page rendered with an active code.
(function () {
    var t = document.getElementById('slot-code-timer');
    if (!t) return;
    var s = parseInt(t.getAttribute('data-secs-left') || '0', 10);
    if (s > 0) startSlotCodeCountdown(s);
})();

async function _postSlotCodeAction(action) {
    var panel = document.getElementById('slot-code-panel');
    if (!panel) return;
    var slotId = panel.getAttribute('data-slot-id');
    var csrf   = _csrfPair();
    if (!csrf) return ui.alert('Missing CSRF token. Please reload the page.');

    var fd = new FormData();
    fd.append('action', action);
    fd.append('slot_id', slotId);
    fd.append(csrf.name, csrf.value);

    try {
        var resp = await fetch(location.href, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var d = await resp.json();
        if (!d.ok) { ui.alert(d.error || 'Could not update code.'); return; }

        var displayEl = document.getElementById('slot-code-display');
        if (displayEl) {
            displayEl.classList.remove('is-empty');
            displayEl.textContent = d.password;
        }
        startSlotCodeCountdown(d.expires_in);
    } catch (e) {
        ui.alert('Network error. Try again.');
    }
}

async function generateSlotCode() {
    if (document.getElementById('slot-code-display') &&
        !document.getElementById('slot-code-display').classList.contains('is-empty')) {
        if (!await ui.confirm('Issue a new code? The current code will be replaced immediately and applicants who have not yet entered the old code will be locked out.', { ok: 'Issue code' })) return;
    }
    _postSlotCodeAction('generate_slot_code');
}
function extendSlotCode() { _postSlotCodeAction('extend_slot_code'); }

// Inline edit of room label on the roster page
function startRoomLabelEdit() {
    var v = document.getElementById('room-label-view');
    var e = document.getElementById('room-label-edit');
    if (!v || !e) return;
    v.style.display = 'none';
    e.style.display = 'inline-flex';
    var inp = document.getElementById('room-label-input');
    if (inp) { inp.focus(); inp.select(); }
}
function cancelRoomLabelEdit() {
    var v = document.getElementById('room-label-view');
    var e = document.getElementById('room-label-edit');
    if (!v || !e) return;
    e.style.display = 'none';
    v.style.display = 'inline-flex';
}
async function saveRoomLabel(slotId) {
    var inp = document.getElementById('room-label-input');
    if (!inp) return;
    var newLabel = inp.value.trim();
    if (!newLabel) { ui.alert('Room label cannot be empty.'); return; }

    var csrf = _csrfPair();
    if (!csrf) return ui.alert('Missing CSRF token. Please reload the page.');

    var fd = new FormData();
    fd.append('action', 'update_room_label');
    fd.append('slot_id', slotId);
    fd.append('room_label', newLabel);
    fd.append(csrf.name, csrf.value);

    try {
        var resp = await fetch(location.href, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var d = await resp.json();
        if (!d.ok) { ui.alert(d.error || 'Could not rename room.'); return; }
        var t = document.getElementById('room-label-text');
        if (t) t.textContent = d.room_label;
        cancelRoomLabelEdit();
    } catch (e) {
        ui.alert('Network error. Try again.');
    }
}

// Card-grid badges — per-card lightweight countdowns, no buttons.
(function () {
    var badges = document.querySelectorAll('[data-card-pw-end]');
    if (!badges.length) return;
    function tick() {
        var now = Math.floor(Date.now() / 1000);
        badges.forEach(function (b) {
            var endTs = parseInt(b.getAttribute('data-card-pw-end') || '0', 10);
            var left  = Math.max(0, endTs - now);
            var t = b.querySelector('.card-pw-timer');
            if (t) t.textContent = _formatMMSS(left);
            if (left <= 60) b.classList.add('is-warn');
            if (left <= 0)  b.style.display = 'none';
        });
    }
    tick();
    setInterval(tick, 1000);
})();
