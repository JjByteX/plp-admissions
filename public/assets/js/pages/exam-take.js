// ── Context menu & drag prevention on exam content ──────────
const examContent = document.getElementById('exam-content');
if (examContent) {
    examContent.addEventListener('contextmenu', e => e.preventDefault());
    examContent.addEventListener('dragstart',   e => e.preventDefault());
    // Block Ctrl+A / Cmd+A on the exam area
    examContent.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'a') e.preventDefault();
    });
}

// ── Answer tracking ──────────────────────────────────────────
const EXAM_PAGE = window.PAGE;
const TOTAL = EXAM_PAGE.total;
const answeredSet = new Set();

function updateProgress() {
    const n = answeredSet.size;
    document.getElementById('answered-count').textContent = n + ' of ' + TOTAL + ' answered';
    document.getElementById('answered-label').textContent  = n + ' / ' + TOTAL + ' answered';
    document.getElementById('progress-fill').style.width   = (TOTAL ? (n/TOTAL*100) : 0) + '%';
}

function markAnswered(qid) {
    // Check if actually has a value
    const form = document.getElementById('exam-form');
    const inputs = form.querySelectorAll(`[name="answers[${qid}]"], [name="answers[${qid}][]"]`);
    let hasAnswer = false;
    inputs.forEach(inp => {
        if (inp.type === 'radio' || inp.type === 'checkbox') {
            if (inp.checked) hasAnswer = true;
        } else if (inp.value.trim()) hasAnswer = true;
    });
    if (hasAnswer) answeredSet.add(qid);
    else answeredSet.delete(qid);

    updateProgress();
}

// Radio — single choice or dropdown
function markChosenRadio(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${CSS.escape(name)}"]`).forEach(r => {
        const label = r.closest('label');
        if (label) label.classList.toggle('chosen', r.checked);
    });
    // Extract qid from name like answers[123]
    const qid = parseInt(name.match(/\[(\d+)\]/)[1]);
    markAnswered(qid);
}

// Checkbox — multi select
function markChosenCheckbox(input) {
    const label = input.closest('label');
    if (label) label.classList.toggle('chosen', input.checked);
    const qid = parseInt(input.name.match(/\[(\d+)\]/)[1]);
    markAnswered(qid);
}

// Linear scale — toggle bubble
function markScaleChosen(input, qid) {
    // Uncheck visual on siblings first
    document.querySelectorAll(`input[name="answers[${qid}]"]`).forEach(r => {
        const bubble = r.parentElement?.querySelector('.scale-bubble');
        if (bubble) bubble.classList.toggle('chosen-scale', r.checked);
    });
    markAnswered(qid);
}

// Dropdown/text change handler — already calls markAnswered directly

// Set by the save code below. While true, Submit is blocked.
let examOffline = false;
let timerEnded  = false;

// ── Submit confirm ───────────────────────────────────────────
function confirmSubmit() {
    if (examOffline) return;
    const unanswered = TOTAL - answeredSet.size;
    const msg = unanswered > 0
        ? `You have ${unanswered} unanswered question${unanswered>1?'s':''}.  Once submitted, your answers cannot be changed.`
        : 'Once submitted, your answers cannot be changed. Make sure all answers are correct.';
    document.getElementById('confirm-message').textContent = msg;
    document.getElementById('confirm-modal').style.display = 'flex';
}

// ── Countdown timer ──────────────────────────────────────────
if (EXAM_PAGE.secondsRemaining > 0) {
let seconds = EXAM_PAGE.secondsRemaining;
const timerEl = document.getElementById('timer');
const interval = setInterval(() => {
    seconds--;
    if (seconds <= 0) {
        clearInterval(interval);
        // Offline: do not submit, keep the answers. The save code submits once a save gets through.
        if (examOffline) { timerEnded = true; return; }
        document.getElementById('exam-form').submit();
        return;
    }
    const m = String(Math.floor(seconds/60)).padStart(2,'0');
    const s = String(seconds%60).padStart(2,'0');
    timerEl.textContent = m + ':' + s;
    if (seconds <= 300) timerEl.classList.add('warning');
}, 1000);
}

// ── Warn before page leave ───────────────────────────────────
let submitted = false;
document.getElementById('exam-form').addEventListener('submit', () => { submitted = true; });
window.addEventListener('beforeunload', e => {
    if (!submitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// ── Save, retry and restore ─────────────────────────────────
// Browser copy on every change, server copy 2s after a change, 10s backup.
// Only a network failure (fetch throws / times out) means offline. A reply from
// the server that is an error is a server problem: keep retrying, but do not
// claim there is no connection or lock Submit.
(function() {
    const examId = EXAM_PAGE.examId;
    const slotId = EXAM_PAGE.slotId;
    const userId = EXAM_PAGE.userId;
    const KEY    = EXAM_PAGE.draftKey;
    const serverDraft = EXAM_PAGE.serverDraft;
    const saveUrl = EXAM_PAGE.saveUrl;
    const form = document.getElementById('exam-form');
    const csrfToken = form.querySelector('input[name="_csrf"]')?.value || '';
    const msgEl     = document.getElementById('exam-status-msg');
    const submitBtn = document.getElementById('submit-btn');
    const yesBtn    = document.getElementById('confirm-yes');
    const modal     = document.getElementById('confirm-modal');
    const OFFLINE_MSG = 'No connection. Your answers are saved on this device. Reconnect to submit.';
    const SERVER_MSG  = 'Could not reach the save service. Your answers are still saved on this device. Retrying...';
    const CLOSED_MSG  = 'This exam has closed. Click Submit Exam to send the answers saved before it closed.';

    let lastSaved = null;     // answers the server last confirmed
    let saving = false, closed = false, restoring = false;
    let debounce = null, retryTimer = null, retryDelay = 0;

    const saveIndicator = document.createElement('div');
    saveIndicator.style.cssText = 'position:fixed;bottom:16px;right:16px;padding:6px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);font-size:12px;color:var(--text-tertiary);z-index:999;opacity:0;transition:opacity .3s';
    document.body.appendChild(saveIndicator);

    function showMsg(text) {
        msgEl.textContent = text;
        msgEl.style.display = text ? 'block' : 'none';
    }

    function setOffline(v) {
        examOffline = v;
        submitBtn.disabled = v;
        yesBtn.disabled = v;
        if (v) modal.style.display = 'none';
        showMsg(v ? OFFLINE_MSG : '');
    }

    function collectAnswers() {
        const data = {};
        form.querySelectorAll('[name^="answers["]').forEach(inp => {
            const match = inp.name.match(/answers\[(\d+)\](\[\])?/);
            if (!match) return;
            const qid = match[0];
            if (inp.type === 'radio' || inp.type === 'checkbox') {
                if (inp.checked) {
                    if (match[2]) {
                        if (!data[qid]) data[qid] = [];
                        data[qid].push(inp.value);
                    } else {
                        data[qid] = inp.value;
                    }
                }
            } else {
                data[qid] = inp.value;
            }
        });
        return JSON.stringify(data);
    }

    // Browser copy. Student, exam and slot are stored inside it and checked on restore.
    function localSave() {
        try {
            localStorage.setItem(KEY, JSON.stringify({ u: userId, e: examId, s: slotId, t: Date.now(), a: collectAnswers() }));
        } catch (e) {}
    }

    function scheduleRetry() {
        clearTimeout(retryTimer);
        retryDelay = Math.min((retryDelay || 1000) * 2, 30000);   // 2s, 4s ... 30s
        retryTimer = setTimeout(serverSave, retryDelay);
    }

    async function serverSave() {
        if (submitted || closed || saving) return;
        const answers = collectAnswers();
        if (answers === lastSaved && !examOffline) return;       // nothing new to send
        saving = true;
        const ctl = new AbortController();
        const timeout = setTimeout(() => ctl.abort(), 8000);
        try {
            const body = new FormData();
            body.append('exam_id', examId);
            body.append('slot_id', slotId);
            body.append('answers', answers);
            body.append('_csrf', csrfToken);
            const r = await fetch(saveUrl, { method: 'POST', body: body, signal: ctl.signal });   // throws only on network failure / timeout
            let d = null;
            try { d = await r.json(); } catch (e) {}
            if (!d) {                                            // server answered, but not with JSON (error page, expired token)
                setOffline(false);
                showMsg(SERVER_MSG);
                scheduleRetry();
                return;
            }
            if (d.closed) {
                closed = true;
                clearTimeout(retryTimer);
                setOffline(false);
                showMsg(CLOSED_MSG);
                return;
            }
            if (d.error === 'Already submitted') { closed = true; clearTimeout(retryTimer); return; }
            if (!d.ok) {                                         // server refused the save
                setOffline(false);
                showMsg(SERVER_MSG);
                scheduleRetry();
                return;
            }
            lastSaved = answers;
            retryDelay = 0;
            clearTimeout(retryTimer);
            setOffline(false);                                   // also clears any server-problem message
            saveIndicator.textContent = 'Draft saved ' + String(d.saved_at).slice(11, 19);
            saveIndicator.style.opacity = '1';
            setTimeout(() => saveIndicator.style.opacity = '0', 3000);
            if (timerEnded) form.submit();                       // timer ran out while offline
        } catch (e) {
            setOffline(true);
            scheduleRetry();
        } finally {
            clearTimeout(timeout);
            saving = false;
        }
    }

    // Fill answers from a saved JSON string and run the page's own handlers so
    // the chosen styles and the progress counter update.
    function applyAnswers(json) {
        let data;
        try { data = JSON.parse(json); } catch (e) { return false; }
        if (!data || typeof data !== 'object') return false;
        restoring = true;
        form.querySelectorAll('[name^="answers["]').forEach(inp => {
            const m = inp.name.match(/answers\[(\d+)\](\[\])?/);
            if (!m || !(m[0] in data)) return;
            const v = data[m[0]];
            const choice = inp.type === 'radio' || inp.type === 'checkbox';
            if (inp.type === 'radio') inp.checked = String(v) === inp.value;
            else if (inp.type === 'checkbox') inp.checked = Array.isArray(v) && v.map(String).includes(inp.value);
            else inp.value = v;
            if (choice ? inp.checked : inp.value) {
                inp.dispatchEvent(new Event('input',  { bubbles: true }));
                inp.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        restoring = false;
        return true;
    }

    // Restore: use the newer of the server draft and this browser's copy.
    // ponytail: "newer" compares the browser clock with the server clock; a badly wrong device clock can pick the older copy.
    let loc = null;
    try { loc = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
    if (!loc || loc.u !== userId || loc.e !== examId || loc.s !== slotId || typeof loc.a !== 'string') loc = null;
    const fromLocal = !!loc && (!serverDraft || loc.t > serverDraft.t);
    const pick = fromLocal ? loc.a : (serverDraft ? serverDraft.a : null);
    if (pick && applyAnswers(pick)) {
        if (fromLocal) setTimeout(serverSave, 2000);             // browser copy is newer: push it up
        else lastSaved = collectAnswers();
    }

    ['input', 'change'].forEach(ev => form.addEventListener(ev, () => {
        if (restoring || submitted) return;
        localSave();
        clearTimeout(debounce);
        debounce = setTimeout(serverSave, 2000);
    }));

    setInterval(() => { if (!examOffline) serverSave(); }, 10000);   // backup; offline retries use their own backoff
    window.addEventListener('online', () => { if (examOffline) serverSave(); });
    window.addEventListener('focus',  () => { if (examOffline) serverSave(); });
})();
