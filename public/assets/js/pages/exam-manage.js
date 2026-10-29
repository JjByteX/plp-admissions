// Page script for modules/exam/staff_manage.php (exam editor).
// Server values come from window.PAGE, set by the small bootstrap in the module:
//   PAGE.examId, PAGE.live, PAGE.typeLabels, PAGE.sectionColors, PAGE.icons.{add,dismiss}
// Loaded through $pageScripts before app.js, same order the inline block used to run in.

// ── Live-exam prompt ──────────────────────────────────────────
// While students are taking the exam, changes that affect scoring ask for
// confirmation first. The server enforces it too (confirm_live).
(function () {
    const LIVE = PAGE.live;
    const RISKY = ['create_exam','activate_exam','delete_exam','delete_section','add_question','edit_question','delete_question'];
    const MSG = 'Students are taking the exam right now.\n\nThis change applies to them immediately and can change their score. Continue?';

    if (LIVE) {
        const origFetch = window.fetch.bind(window);
        const askLive = () => ui.confirm(MSG, { title: 'Exam in progress', ok: 'Continue' });
        window.fetch = async function (url, opts) {
            const body = opts && opts.body;
            if (body instanceof FormData && RISKY.includes(body.get('action')) && !body.get('confirm_live')) {
                if (!await askLive()) {
                    return new Response(JSON.stringify({ ok: false, cancelled: true }), { status: 409 });
                }
                body.append('confirm_live', '1');
            }
            return origFetch(url, opts);
        };

        // The dialog is async: a form that needs the prompt is held back, asked, then
        // sent again with confirm_live set. This listener is registered before app.js's
        // initConfirm, so this prompt comes first, then any data-confirm on the form.
        function needsLive(form) {
            const act = form.querySelector('input[name="action"]');
            return !!act && RISKY.includes(act.value) && !form.querySelector('input[name="confirm_live"]');
        }
        function addLive(form) {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = 'confirm_live'; h.value = '1';
            form.appendChild(h);
        }
        document.addEventListener('submit', e => {
            const form = e.target;
            if (!needsLive(form)) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            const submitter = e.submitter;
            askLive().then(ok => { if (ok) { addLive(form); form.requestSubmit(submitter); } });
        }, true);
        const origSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            if (!needsLive(this)) return origSubmit.call(this);
            const form = this;
            askLive().then(ok => { if (ok) { addLive(form); origSubmit.call(form); } });
        };
    }

    // Show the server's reason when it blocks a save (locked exam, live delete).
    const baseFetch = window.fetch.bind(window);
    window.fetch = function (url, opts) {
        return baseFetch(url, opts).then(r => {
            if (r.status === 409) {
                r.clone().json().then(d => { if (d && d.blocked && d.error) ui.alert(d.error); }).catch(() => {});
            }
            return r;
        });
    };
})();

// Per-room access codes are generated on the Exam Slots page — the
// global Generate / Extend / countdown UI used to live here and has
// been removed in Chunk 7.

// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)  { PLP.modal.open(id); }
function closeModal(id) { PLP.modal.close(id); }

// ── Edit exam modal ───────────────────────────────────────────
// Title is auto-derived server-side from the active school year, so
// the form only collects description + shuffle flags.
function openEditExamModal(exam) {
    document.getElementById('edit-exam-id').value       = exam.id;
    document.getElementById('edit-exam-desc').value     = exam.description || '';
    document.getElementById('edit-exam-shuffleQ').checked = exam.shuffle_questions == 1;
    document.getElementById('edit-exam-shuffleC').checked = exam.shuffle_choices == 1;
    openModal('edit-exam-modal');
}

// ── Year → Title auto-generation ──────────────────────────────
(function() {
    // Create exam modal
    var createYear = document.getElementById('create-exam-year');
    var createPreview = document.getElementById('create-year-preview');
    var createHidden = document.getElementById('create-exam-title-hidden');
    if (createYear) {
        createYear.addEventListener('input', function() {
            var y = this.value.trim() || 'Year';
            createPreview.textContent = y;
            createHidden.value = 'PLP Admissions Test (' + this.value.trim() + ')';
        });
        // Set on form submit
        createYear.closest('form').addEventListener('submit', function() {
            createHidden.value = 'PLP Admissions Test (' + createYear.value.trim() + ')';
        });
    }
    // Edit exam modal
    var editYear = document.getElementById('edit-exam-year');
    var editPreview = document.getElementById('edit-year-preview');
    var editHidden = document.getElementById('edit-exam-title');
    if (editYear) {
        editYear.addEventListener('input', function() {
            var y = this.value.trim() || 'Year';
            editPreview.textContent = y;
            editHidden.value = 'PLP Admissions Test (' + this.value.trim() + ')';
        });
        editYear.closest('form').addEventListener('submit', function() {
            editHidden.value = 'PLP Admissions Test (' + editYear.value.trim() + ')';
        });
    }
})();

// ── Exam header edit mode toggle ──────────────────────────────
function enterExamEditMode() {
    document.getElementById('exam-header-card').dataset.editState = 'edit';
}
function exitExamEditMode() {
    document.getElementById('exam-header-card').dataset.editState = 'read';
}

// ── Inline section title edit ─────────────────────────────────
function startInlineSecEdit(sid) {
    document.getElementById(`sec-title-view-${sid}`).style.display = 'none';
    document.getElementById(`sec-title-edit-${sid}`).style.display = '';
    document.getElementById(`sec-title-input-${sid}`).focus();
    document.getElementById(`sec-title-input-${sid}`).select();
}
function cancelInlineSecEdit(sid) {
    document.getElementById(`sec-title-edit-${sid}`).style.display = 'none';
    document.getElementById(`sec-title-view-${sid}`).style.display = 'flex';
}
function saveInlineSecTitle(sid, secType) {
    const inp = document.getElementById(`sec-title-input-${sid}`);
    const newTitle = inp.value.trim();
    if (!newTitle) return;
    const csrfInput = document.querySelector('input[name^="_csrf"]');
    const fd = new FormData();
    fd.append('action', 'edit_section');
    fd.append('section_id', sid);
    fd.append('section_title', newTitle);
    fd.append('section_type', secType);
    fd.append(csrfInput.name, csrfInput.value);
    fetch(location.href, { method:'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json()).then(d => {
            if (d.ok) {
                document.getElementById(`sec-title-text-${sid}`).textContent = newTitle;
                cancelInlineSecEdit(sid);
            }
        }).catch(() => { location.reload(); });
}

// ── Section modal ─────────────────────────────────────────────
function selectSectionType(type) {
    document.getElementById('sec-type-val').value = type;
    document.querySelectorAll('#sec-type-grid .type-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.type === type);
    });
}
function openAddSectionModal() {
    document.getElementById('sec-modal-title').textContent = 'Add Section';
    document.getElementById('sec-action').value            = 'create_section';
    document.getElementById('sec-id').value                = '';
    document.getElementById('sec-title-field').value       = '';
    document.getElementById('sec-desc-field').value        = '';
    document.getElementById('sec-submit-btn').textContent  = 'Create Section';
    selectSectionType('multiple_choice');
    openModal('section-modal');
}
function confirmDeleteSection(form, qCount, title) {
    if (form.dataset.ok) { delete form.dataset.ok; return true; }   // replay after OK
    const msg = qCount === 0 ? `Remove "${title}"?`
        : `Remove "${title}" and its ${qCount} question${qCount !== 1 ? 's' : ''}?`;
    ui.confirm(msg, { danger: true, ok: 'Remove' })
        .then(ok => { if (ok) { form.dataset.ok = '1'; form.requestSubmit(); } });
    return false;
}

function openEditSectionModal(sec) {
    document.getElementById('sec-modal-title').textContent = 'Edit Section';
    document.getElementById('sec-action').value            = 'edit_section';
    document.getElementById('sec-id').value               = sec.id;
    document.getElementById('sec-title-field').value      = sec.title;
    document.getElementById('sec-desc-field').value       = sec.description || '';
    document.getElementById('sec-submit-btn').textContent  = 'Save Section';
    selectSectionType(sec.question_type);
    openModal('section-modal');
}

// ── Full inline question edit ─────────────────────────────────
const IQ_EDIT_CHOICES = {};

function startInlineQFullEdit(qid) {
    const card = document.querySelector(`.q-card[data-qid="${qid}"]`);
    if (!card || card.dataset.editing === '1') return;
    card.dataset.editing = '1';
    card.style.borderColor = 'var(--accent)';

    const q = JSON.parse(card.dataset.q);
    const CHOICE_TYPES = ['multiple_choice','checkboxes','dropdown'];

    // Parse existing choices into the edit state
    let choices = [];
    if (CHOICE_TYPES.includes(q.question_type) && q.choices) {
        const raw = JSON.parse(q.choices);
        const correctIndices = (q.question_type === 'checkboxes' && q.correct_answer)
            ? JSON.parse(q.correct_answer) : [];
        const singleCorrect = parseInt(q.correct_index ?? 0);
        choices = raw.map((text, i) => ({
            text,
            correct: q.question_type === 'checkboxes' ? correctIndices.includes(i) : i === singleCorrect
        }));
    }
    IQ_EDIT_CHOICES[qid] = choices;

    card.querySelector('.q-view-mode').style.display = 'none';
    const editEl = document.createElement('div');
    editEl.className = 'q-edit-mode';
    editEl.style.cssText = 'padding:var(--space-4) var(--space-5);display:flex;flex-direction:column;gap:var(--space-3);animation:creator-slide-in .15s ease';
    editEl.innerHTML = buildQEditHTML(q);
    card.appendChild(editEl);
    card.querySelector(`#qe-text-${qid}`)?.focus();
}

function buildQEditHTML(q) {
    const qid = q.id;
    const CHOICE_TYPES = ['multiple_choice','checkboxes','dropdown'];
    const isChoice = CHOICE_TYPES.includes(q.question_type);
    const choices = IQ_EDIT_CHOICES[qid] || [];

    let answerHTML = '';
    if (isChoice) {
        const isCheckbox = q.question_type === 'checkboxes';
        const rows = choices.map((c, i) => buildEditChoiceRow(c, i, qid, q.question_type)).join('');
        answerHTML = `
            <div>
                <label class="form-label">Answer Choices</label>
                <div id="qe-choices-${qid}" style="display:flex;flex-direction:column;gap:var(--space-2)">${rows}</div>
                <button type="button" class="iq-add-choice" onclick="addEditChoice(${qid},'${q.question_type}')">
                    ${PAGE.icons.add}
                    Add choice
                </button>
                <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">${isCheckbox?'Check all correct answers.':'Select the one correct answer.'}</p>
            </div>`;
    } else if (q.question_type === 'short_answer') {
        answerHTML = `
            <div>
                <label class="form-label">Expected Answer <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional)</span></label>
                <input type="text" id="qe-expected-${qid}" class="form-control" value="${escQA(q.correct_answer||'')}" placeholder="Leave blank for manual grading">
            </div>`;
    } else if (q.question_type === 'linear_scale') {
        answerHTML = `
            <div style="display:grid;grid-template-columns:80px 80px 1fr 1fr;gap:var(--space-3);align-items:end">
                <div><label class="form-label">Min</label>
                    <select id="qe-scale-min-${qid}" class="form-control">
                        <option value="0" ${q.scale_min==0?'selected':''}>0</option>
                        <option value="1" ${q.scale_min!=0?'selected':''}>1</option>
                    </select></div>
                <div><label class="form-label">Max</label>
                    <select id="qe-scale-max-${qid}" class="form-control">
                        ${[2,3,4,5,6,7,8,9,10].map(n=>`<option value="${n}"${q.scale_max==n?' selected':''}>${n}</option>`).join('')}
                    </select></div>
                <div><label class="form-label">Min label</label>
                    <input type="text" id="qe-scale-min-label-${qid}" class="form-control" value="${escQA(q.scale_min_label||'')}" placeholder="e.g. Strongly disagree"></div>
                <div><label class="form-label">Max label</label>
                    <input type="text" id="qe-scale-max-label-${qid}" class="form-control" value="${escQA(q.scale_max_label||'')}" placeholder="e.g. Strongly agree"></div>
            </div>`;
    }

    return `
        <div>
            <label class="form-label">Question <span style="color:var(--error)">*</span></label>
            <textarea id="qe-text-${qid}" class="form-control" rows="2">${escQT(q.question_text)}</textarea>
        </div>
        ${answerHTML}
        <div style="display:flex;gap:var(--space-4);align-items:flex-end">
            <div style="width:88px">
                <label class="form-label">Points</label>
                <input type="number" id="qe-points-${qid}" class="form-control" value="${q.points}" min="0" max="100">
            </div>
            <label style="display:flex;align-items:center;gap:var(--space-2);cursor:pointer;padding-bottom:6px;font-size:var(--text-sm)">
                <input type="checkbox" id="qe-required-${qid}" ${q.is_required?'checked':''} style="accent-color:var(--accent)"> Required
            </label>
        </div>
        <div style="display:flex;gap:var(--space-2);padding-top:var(--space-2);border-top:1px solid var(--border)">
            <button type="button" class="btn btn-primary btn-sm" onclick="saveInlineQFullEdit(${qid})">Save changes</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="cancelInlineQFullEdit(${qid})">Cancel</button>
        </div>`;
}

function buildEditChoiceRow(choice, idx, qid, qType) {
    const isCheckbox = qType === 'checkboxes';
    const inputType = isCheckbox ? 'checkbox' : 'radio';
    const name = isCheckbox ? `qe_cb_${qid}_${idx}` : `qe_rb_${qid}`;
    const delBtn = idx >= 2
        ? `<button type="button" class="btn-icon" style="color:var(--error);flex-shrink:0" onclick="removeEditChoice(${qid},${idx},'${qType}')">
               ${PAGE.icons.dismiss}
           </button>`
        : '<div style="width:22px"></div>';
    return `<div class="iq-choice-row" data-choice-idx="${idx}">
        <input type="${inputType}" class="choice-input" name="${name}" value="${idx}" ${choice.correct?'checked':''} style="accent-color:var(--accent);flex-shrink:0"
               onchange="syncEditCorrect(${qid},${idx},'${qType}',this)">
        <input type="text" class="form-control" value="${escQA(choice.text)}" placeholder="Choice ${String.fromCharCode(65+idx)}"
               oninput="IQ_EDIT_CHOICES[${qid}][${idx}].text=this.value">
        ${delBtn}
    </div>`;
}

function syncEditCorrect(qid, idx, qType, el) {
    const choices = IQ_EDIT_CHOICES[qid]; if (!choices) return;
    if (qType === 'checkboxes') choices[idx].correct = el.checked;
    else choices.forEach((c,i) => c.correct = (i===idx));
}
function addEditChoice(qid, qType) {
    const choices = IQ_EDIT_CHOICES[qid]; if (!choices) return;
    choices.push({text:'', correct:false});
    const listEl = document.getElementById(`qe-choices-${qid}`);
    const idx = choices.length - 1;
    const div = document.createElement('div');
    div.innerHTML = buildEditChoiceRow(choices[idx], idx, qid, qType);
    listEl.appendChild(div.firstElementChild);
    listEl.lastElementChild.querySelector('input[type=text]')?.focus();
}
function removeEditChoice(qid, idx, qType) {
    const choices = IQ_EDIT_CHOICES[qid]; if (!choices) return;
    choices.splice(idx, 1);
    if (choices.length > 0 && !choices.some(c=>c.correct)) choices[0].correct = true;
    document.getElementById(`qe-choices-${qid}`).innerHTML =
        choices.map((c,i) => buildEditChoiceRow(c, i, qid, qType)).join('');
}
function cancelInlineQFullEdit(qid) {
    const card = document.querySelector(`.q-card[data-qid="${qid}"]`); if (!card) return;
    delete card.dataset.editing; delete IQ_EDIT_CHOICES[qid];
    card.style.borderColor = '';
    card.querySelector('.q-edit-mode')?.remove();
    card.querySelector('.q-view-mode').style.display = 'flex';
}
async function saveInlineQFullEdit(qid) {
    const card = document.querySelector(`.q-card[data-qid="${qid}"]`); if (!card) return;
    const q = JSON.parse(card.dataset.q);
    const qText = document.getElementById(`qe-text-${qid}`)?.value.trim();
    if (!qText) { document.getElementById(`qe-text-${qid}`).focus(); return; }

    const saveBtn = card.querySelector('.q-edit-mode .btn-primary');
    if (saveBtn) { saveBtn.disabled=true; saveBtn.textContent='Saving…'; }

    const examId = PAGE.examId;
    const csrfInput = document.querySelector('input[name^="_csrf"]');
    const CHOICE_TYPES = ['multiple_choice','checkboxes','dropdown'];
    const choices = IQ_EDIT_CHOICES[qid] || [];

    const fd = new FormData();
    fd.append('action','edit_question'); fd.append('exam_id',examId);
    fd.append('question_id',qid); fd.append('section_id',q.section_id||'');
    fd.append(csrfInput.name,csrfInput.value);
    fd.append('question_text',qText);
    fd.append('question_type',q.question_type);
    fd.append('points',document.getElementById(`qe-points-${qid}`)?.value||1);
    if (document.getElementById(`qe-required-${qid}`)?.checked) fd.append('is_required','1');

    if (CHOICE_TYPES.includes(q.question_type)) {
        const texts = choices.map(c=>c.text).filter(t=>t.trim());
        if (texts.length < 2) { ui.alert('Add at least 2 choices.'); if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save changes';} return; }
        texts.forEach(t=>fd.append('choices[]',t));
        if (q.question_type==='checkboxes') choices.forEach((c,i)=>{if(c.correct)fd.append('correct_indices[]',i);});
        else { const ci=choices.findIndex(c=>c.correct); fd.append('correct_index',ci>=0?ci:0); }
    } else if (q.question_type==='short_answer') {
        fd.append('expected_answer',document.getElementById(`qe-expected-${qid}`)?.value.trim()||'');
    } else if (q.question_type==='linear_scale') {
        fd.append('scale_min',document.getElementById(`qe-scale-min-${qid}`)?.value||1);
        fd.append('scale_max',document.getElementById(`qe-scale-max-${qid}`)?.value||5);
        fd.append('scale_min_label',document.getElementById(`qe-scale-min-label-${qid}`)?.value.trim()||'');
        fd.append('scale_max_label',document.getElementById(`qe-scale-max-label-${qid}`)?.value.trim()||'');
    }
    try {
        const resp = await fetch(location.href,{method:'POST',body:fd});
        if (resp.ok) window.location.reload();
        else { if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save changes';} ui.alert('Unable to save. Try again.'); }
    } catch(e) { window.location.reload(); }
}
function escQT(s){return PLP.esc(s);}
function escQA(s){return PLP.esc(s);}

// ── Inline question creator ───────────────────────────────────
const CHOICE_TYPE_KEYS = ['multiple_choice','checkboxes','dropdown'];

function showInlineCreator(secId, secType) {
    // Hide the "Add question" button while creating
    document.getElementById(`sec-add-btn-${secId}`).style.display = 'none';
    // Hide empty state if present
    const emptyEl = document.getElementById(`sec-empty-${secId}`);
    if (emptyEl) emptyEl.style.display = 'none';

    const container = document.getElementById(`inline-creator-${secId}`);
    container.dataset.choices = JSON.stringify(
        CHOICE_TYPE_KEYS.includes(secType)
            ? [{text:'',correct:true},{text:'',correct:false},{text:'',correct:false},{text:'',correct:false}]
            : []
    );
    renderInlineCreator(container, secId, secType);
    container.style.display = '';
    container.querySelector('textarea')?.focus();
}

function hideInlineCreator(secId) {
    const container = document.getElementById(`inline-creator-${secId}`);
    container.style.display = 'none';
    container.innerHTML = '';
    document.getElementById(`sec-add-btn-${secId}`).style.display = 'flex';
    // Restore empty state if no questions exist
    const emptyEl = document.getElementById(`sec-empty-${secId}`);
    if (emptyEl) emptyEl.style.display = '';
}

function renderInlineCreator(container, secId, secType) {
    const choices = JSON.parse(container.dataset.choices || '[]');
    const isChoice = CHOICE_TYPE_KEYS.includes(secType);
    const isCheckbox = secType === 'checkboxes';
    const typeLabelMap = PAGE.typeLabels;
    const scColors = PAGE.sectionColors;
    const sc = scColors[secType] || scColors['multiple_choice'];

    let choicesHtml = '';
    if (isChoice) {
        choicesHtml = `<div>
            <label class="form-label" style="margin-bottom:var(--space-2)">Answer Choices</label>
            <div id="iq-choices-${secId}" style="display:flex;flex-direction:column;gap:var(--space-2)">
                ${choices.map((c, i) => renderChoiceRow(c, i, secId, secType)).join('')}
            </div>
            <button type="button" class="iq-add-choice" onclick="addInlineChoice(${secId}, '${secType}')">
                ${PAGE.icons.add}
                Add choice
            </button>
            <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">${isCheckbox ? 'Check all correct answers.' : 'Select the one correct answer.'}</p>
        </div>`;
    } else if (secType === 'short_answer') {
        choicesHtml = `<div>
            <label class="form-label">Expected Answer <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional)</span></label>
            <input type="text" id="iq-expected-${secId}" class="form-control" placeholder="Leave blank for manual grading">
        </div>`;
    } else if (secType === 'linear_scale') {
        choicesHtml = `<div style="display:grid;grid-template-columns:80px 80px 1fr 1fr;gap:var(--space-3);align-items:end">
            <div>
                <label class="form-label">Min</label>
                <select id="iq-scale-min-${secId}" class="form-control"><option value="0">0</option><option value="1" selected>1</option></select>
            </div>
            <div>
                <label class="form-label">Max</label>
                <select id="iq-scale-max-${secId}" class="form-control">${[2,3,4,5,6,7,8,9,10].map(n=>`<option value="${n}"${n===5?' selected':''}>${n}</option>`).join('')}</select>
            </div>
            <div><label class="form-label">Min label</label><input type="text" id="iq-scale-min-label-${secId}" class="form-control" placeholder="e.g. Strongly disagree"></div>
            <div><label class="form-label">Max label</label><input type="text" id="iq-scale-max-label-${secId}" class="form-control" placeholder="e.g. Strongly agree"></div>
        </div>`;
    }

    container.innerHTML = `
        <div class="iq-body">
            <div style="display:flex;align-items:center;gap:var(--space-2)">
                <span class="iq-type-badge" style="background:${sc.bg};color:${sc.text};border:1px solid ${sc.border}">
                    ${typeLabelMap[secType] || secType}
                </span>
                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">New question</span>
            </div>
            <div>
                <label class="form-label">Question <span style="color:var(--error)">*</span></label>
                <textarea id="iq-text-${secId}" class="form-control" rows="2" placeholder="Enter your question…"></textarea>
            </div>
            ${choicesHtml}
            <div style="display:flex;gap:var(--space-4);align-items:flex-end">
                <div style="width:88px">
                    <label class="form-label">Points</label>
                    <input type="number" id="iq-points-${secId}" class="form-control" value="1" min="0" max="100">
                </div>
                <label style="display:flex;align-items:center;gap:var(--space-2);cursor:pointer;padding-bottom:6px;font-size:var(--text-sm)">
                    <input type="checkbox" id="iq-required-${secId}" checked style="accent-color:var(--accent)">
                    Required
                </label>
            </div>
        </div>
        <div class="iq-footer">
            <button type="button" class="btn btn-ghost btn-sm" onclick="hideInlineCreator(${secId})">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveInlineQuestion(${secId}, '${secType}')">Save question</button>
        </div>`;
}

function renderChoiceRow(choice, idx, secId, secType) {
    const isCheckbox = secType === 'checkboxes';
    const inputType = isCheckbox ? 'checkbox' : 'radio';
    const name = isCheckbox ? `iq_correct_${secId}_${idx}` : `iq_correct_${secId}`;
    const checked = choice.correct ? 'checked' : '';
    const delBtn = idx >= 2
        ? `<button type="button" class="btn-icon" style="color:var(--error);flex-shrink:0"
               onclick="removeInlineChoice(${secId}, ${idx}, '${secType}')">
               ${PAGE.icons.dismiss}
           </button>`
        : '<div style="width:22px"></div>';
    return `<div class="iq-choice-row" data-choice-idx="${idx}">
        <input type="${inputType}" class="choice-input" name="${name}" value="${idx}" ${checked} style="accent-color:var(--accent);flex-shrink:0"
               onchange="syncChoiceCorrect(${secId}, ${idx}, '${secType}', this)">
        <input type="text" class="form-control" value="${escapeHtmlAttr(choice.text)}"
               placeholder="Choice ${String.fromCharCode(65+idx)}"
               oninput="syncChoiceText(${secId}, ${idx}, this.value)">
        ${delBtn}
    </div>`;
}

function escapeHtmlAttr(s) {
    return PLP.esc(s);
}

function getCreatorChoices(secId) {
    const container = document.getElementById(`inline-creator-${secId}`);
    return JSON.parse(container.dataset.choices || '[]');
}
function setCreatorChoices(secId, choices) {
    document.getElementById(`inline-creator-${secId}`).dataset.choices = JSON.stringify(choices);
}

function syncChoiceText(secId, idx, val) {
    const choices = getCreatorChoices(secId);
    if (choices[idx]) choices[idx].text = val;
    setCreatorChoices(secId, choices);
}
function syncChoiceCorrect(secId, idx, secType, el) {
    const choices = getCreatorChoices(secId);
    if (secType === 'checkboxes') {
        choices[idx].correct = el.checked;
    } else {
        choices.forEach((c,i) => c.correct = (i === idx));
    }
    setCreatorChoices(secId, choices);
}
function addInlineChoice(secId, secType) {
    const choices = getCreatorChoices(secId);
    choices.push({text:'', correct:false});
    setCreatorChoices(secId, choices);
    const listEl = document.getElementById(`iq-choices-${secId}`);
    const idx = choices.length - 1;
    const rowDiv = document.createElement('div');
    rowDiv.innerHTML = renderChoiceRow(choices[idx], idx, secId, secType);
    listEl.appendChild(rowDiv.firstElementChild);
    listEl.lastElementChild.querySelector('input[type=text]')?.focus();
}
function removeInlineChoice(secId, idx, secType) {
    const choices = getCreatorChoices(secId);
    choices.splice(idx, 1);
    // Reset correct if needed
    if (!choices.some(c => c.correct) && choices.length > 0) choices[0].correct = true;
    setCreatorChoices(secId, choices);
    const container = document.getElementById(`inline-creator-${secId}`);
    renderInlineCreator(container, secId, secType);
}

async function saveInlineQuestion(secId, secType) {
    const qText = document.getElementById(`iq-text-${secId}`)?.value.trim();
    if (!qText) {
        document.getElementById(`iq-text-${secId}`).focus();
        document.getElementById(`iq-text-${secId}`).style.borderColor = 'var(--error)';
        return;
    }
    const choices = getCreatorChoices(secId);
    const points  = parseInt(document.getElementById(`iq-points-${secId}`)?.value || 1);
    const required = document.getElementById(`iq-required-${secId}`)?.checked ? 1 : 0;
    const examId  = PAGE.examId;
    const csrfInput = document.querySelector('input[name^="_csrf"]');

    const fd = new FormData();
    fd.append('action', 'add_question');
    fd.append('exam_id', examId);
    fd.append('section_id', secId);
    fd.append('question_text', qText);
    fd.append('question_type', secType);
    fd.append('points', points);
    if (required) fd.append('is_required', '1');
    fd.append(csrfInput.name, csrfInput.value);

    const isChoice = CHOICE_TYPE_KEYS.includes(secType);
    if (isChoice) {
        const texts = choices.map(c => c.text).filter(t => t.trim());
        if (texts.length < 2) {
            ui.alert('Add at least 2 choices.');
            return;
        }
        texts.forEach(t => fd.append('choices[]', t));
        if (secType === 'checkboxes') {
            choices.forEach((c,i) => { if (c.correct) fd.append('correct_indices[]', i); });
        } else {
            const ci = choices.findIndex(c => c.correct);
            fd.append('correct_index', ci >= 0 ? ci : 0);
        }
    } else if (secType === 'short_answer') {
        const exp = document.getElementById(`iq-expected-${secId}`)?.value.trim();
        if (exp) fd.append('expected_answer', exp);
    } else if (secType === 'linear_scale') {
        fd.append('scale_min', document.getElementById(`iq-scale-min-${secId}`)?.value || 1);
        fd.append('scale_max', document.getElementById(`iq-scale-max-${secId}`)?.value || 5);
        const minL = document.getElementById(`iq-scale-min-label-${secId}`)?.value.trim();
        const maxL = document.getElementById(`iq-scale-max-label-${secId}`)?.value.trim();
        if (minL) fd.append('scale_min_label', minL);
        if (maxL) fd.append('scale_max_label', maxL);
    }

    // Disable save button
    const saveBtn = document.querySelector(`#inline-creator-${secId} .btn-primary`);
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

    try {
        const resp = await fetch(location.href, { method: 'POST', body: fd });
        if (resp.ok) {
            window.location.reload();
        } else {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save question'; }
            ui.alert('Unable to save. Try again.');
        }
    } catch(e) {
        window.location.reload();
    }
}

// Live-update the Low label and enforce high > avg constraints
(function() {
    document.addEventListener('input', function(e) {
        const el = e.target;
        if (!el.classList.contains('passing-high') && !el.classList.contains('passing-avg')) return;
        const course = el.dataset.course;
        if (!course) return;
        const row   = el.closest('div[style*="grid-template-columns"]');
        if (!row) return;
        const highEl = row.querySelector('.passing-high');
        const avgEl  = row.querySelector('.passing-avg');
        const lowEl  = row.querySelector('.low-label');
        if (!highEl || !avgEl || !lowEl) return;
        const highVal = parseInt(highEl.value, 10) || 7;
        const avgVal  = parseInt(avgEl.value,  10) || 4;
        // Enforce avg < high
        if (el.classList.contains('passing-high') && avgVal >= highVal) {
            avgEl.value = Math.max(1, highVal - 1);
        }
        if (el.classList.contains('passing-avg') && avgVal >= highVal) {
            highEl.value = Math.min(10, avgVal + 1);
        }
        const finalAvg = parseInt(avgEl.value, 10) || 4;
        lowEl.textContent = finalAvg <= 1 ? '-' : '1–' + (finalAvg - 1);
    });
})();

// Section-scoped drag-and-drop (questions stay inside their section)
(function() {
    const sectionContainers = document.querySelectorAll('.section-questions');
    if (!sectionContainers.length) return;
    let dragged = null;
    let sourceContainer = null;

    sectionContainers.forEach(container => {
        container.querySelectorAll('.q-card').forEach(card => {
            card.draggable = true;
            card.addEventListener('dragstart', () => {
                dragged = card;
                sourceContainer = container;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                dragged = null;
                saveOrder();
            });
        });

        container.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragged || sourceContainer !== container) return;
            const after = getDragAfterElement(container, e.clientY);
            if (after === null) container.appendChild(dragged);
            else container.insertBefore(dragged, after);
        });
    });

    function getDragAfterElement(container, y) {
        const els = [...container.querySelectorAll('.q-card:not(.dragging)')];
        return els.reduce((closest, el) => {
            const box = el.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            return offset < 0 && offset > closest.offset ? {offset, el} : closest;
        }, {offset: Number.NEGATIVE_INFINITY}).el ?? null;
    }

    function saveOrder() {
        const order = [];
        document.querySelectorAll('.section-questions .q-card').forEach(el => {
            order.push(el.dataset.qid);
        });
        const csrfInput = document.querySelector('input[name^="_csrf"]');
        const fd = new FormData();
        fd.append('action', 'reorder_questions');
        fd.append(csrfInput.name, csrfInput.value);
        order.forEach(id => fd.append('order[]', id));
        fetch(location.href, {method:'POST', body: fd}).catch(()=>{});
    }
})();
