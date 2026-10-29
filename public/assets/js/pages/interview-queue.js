// Auto-dismiss flash alerts after 5 seconds
['iq-flash-success','iq-flash-error'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) setTimeout(function() {
        el.style.transition = 'opacity .4s';
        el.style.opacity = '0';
        setTimeout(function() { el.remove(); }, 400);
    }, 5000);
});

// Evaluation modal handlers
const evalModal = document.getElementById('eval-modal');

function _showField(id, value) {
    const wrap = document.getElementById(id + '-wrap');
    const el   = document.getElementById(id);
    if (value) {
        el.textContent = value;
        if (wrap) wrap.style.display = '';
    } else {
        if (wrap) wrap.style.display = 'none';
    }
}

function openEvalModal(d) {
    document.getElementById('eval-form').action =
        window.PAGE.interviewUrl + d.queueId;

    // Applicant details panel
    document.getElementById('eval-detail-name').textContent = d.name || '';
    document.getElementById('eval-detail-email').textContent = d.email || '';
    document.getElementById('eval-detail-type').textContent = d.type ? d.type.charAt(0).toUpperCase() + d.type.slice(1) : '-';
    document.getElementById('eval-detail-course').textContent = d.course || '-';

    _showField('eval-detail-phone', d.phone);
    _showField('eval-detail-bday', d.birthdate);
    _showField('eval-detail-sex', d.sex === 'M' ? 'Male' : (d.sex === 'F' ? 'Female' : ''));
    _showField('eval-detail-address', d.address);
    _showField('eval-detail-strand', d.strand);
    _showField('eval-detail-sy', d.school_year);

    // Exam score
    const examWrap = document.getElementById('eval-detail-exam-wrap');
    const examEl   = document.getElementById('eval-detail-exam');
    if (d.exam_score !== null && d.exam_total !== null) {
        let scoreText = d.exam_score + '/' + d.exam_total;
        if (d.exam_passed === 1) {
            scoreText += ' - <span style="color:var(--success);font-weight:var(--weight-semibold)">Passed</span>';
        } else if (d.exam_passed === 0) {
            scoreText += ' - <span style="color:var(--error);font-weight:var(--weight-semibold)">Did not pass</span>';
        }
        examEl.innerHTML = scoreText;
        examWrap.style.display = '';
    } else {
        examWrap.style.display = 'none';
    }

    document.getElementById('eval-notes').value = d.notes || '';
    document.getElementById('eval-result-input').value = '';
    evalModal.style.display = 'flex';
    setTimeout(() => document.getElementById('eval-notes').focus(), 50);
}

function closeEvalModal() {
    evalModal.style.display = 'none';
}

evalModal.addEventListener('click', function (e) {
    if (e.target === evalModal) closeEvalModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && evalModal.style.display === 'flex') {
        closeEvalModal();
    }
});

// Submission guard — make sure the user actually clicked Pass or Reject
document.getElementById('eval-form').addEventListener('submit', function (e) {
    const form = e.currentTarget;
    if (form.dataset.ok) { delete form.dataset.ok; return; }   // replay after OK
    const decision = document.getElementById('eval-result-input').value;
    e.preventDefault();
    if (decision !== 'pass' && decision !== 'reject') return false;
    const submitter = e.submitter;
    void ui.confirm('Mark this interview as ' + (decision === 'pass' ? 'PASS' : 'FAIL') + '?',
               { danger: decision === 'reject', ok: 'Confirm' })
        .then(function (ok) { if (ok) { form.dataset.ok = '1'; form.requestSubmit(submitter); } });
    return false;
});

// Auto-refresh every 60s, but pause when a textarea/input is focused or
// has been edited recently (so we don't blow away typed-but-unsaved notes,
// and so the modal doesn't get clobbered mid-evaluation).
let lastFocusTime = 0;
document.addEventListener('focusin', (e) => {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') {
        lastFocusTime = Date.now();
    }
});
document.addEventListener('input', (e) => {
    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') {
        lastFocusTime = Date.now();
    }
});
setInterval(() => {
    if (Date.now() - lastFocusTime < 120000) return;
    if (evalModal.style.display === 'flex') return;
    window.location.reload();
}, 60000);
