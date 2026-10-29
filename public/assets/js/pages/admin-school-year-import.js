// ── Puter helpers (shared with exam import; guard against double-load) ──
// Shared flag; a window property avoids a duplicate top-level declaration if exam-ai-import.js is also loaded.
window.puterLoaded = window.puterLoaded || false;

// Shared "load this script once" helper. checkFn reports whether the
// library is already present (skips loading entirely); onLoaded runs
// once the script tag fires its load event, before the promise
// resolves, for libraries that need a bit of post-load setup (e.g.
// pdf.js's worker path). Each specific loader below is a thin wrapper
// so every existing call site (await loadXIfNeeded()) keeps working
// unchanged.
function loadScriptOnce(src, checkFn, onLoaded) {
    return new Promise(res => {
        if (checkFn()) { res(); return; }
        const s = document.createElement('script');
        s.src = src;
        s.onload = () => { if (onLoaded) onLoaded(); res(); };
        document.head.appendChild(s);
    });
}
function loadPuterIfNeeded() {
    return loadScriptOnce(
        'https://js.puter.com/v2/',
        () => puterLoaded || window.puter,
        () => { puterLoaded = true; }
    );
}
function loadPdfJsIfNeeded() {
    return PLP.loadPdfJs();
}
function loadMammothIfNeeded() {
    return loadScriptOnce(
        'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js',
        () => !!window.mammoth
    );
}
function loadSheetJs() {
    return loadScriptOnce(
        'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
        () => !!window.XLSX
    );
}

// ── Puter status for legacy modal ──
async function refreshLegacyPuterStatus() {
    const bar      = document.getElementById('legacy-puter-status');
    const label    = document.getElementById('legacy-puter-status-text');
    const linkEl   = document.getElementById('legacy-puter-link');
    const signinBtn= document.getElementById('legacy-puter-signin-btn');
    const signoutBtn=document.getElementById('legacy-puter-signout-btn');
    label.textContent = 'Checking Puter connection…';
    [linkEl, signinBtn, signoutBtn].forEach(el => el.style.display = 'none');
    try {
        await loadPuterIfNeeded();
        const ok = await puter.auth.isSignedIn();
        if (ok) {
            let name = '';
            try { const u = await puter.auth.getUser(); name = u?.username ? ' as @' + u.username : ''; } catch(_) {}
            bar.className = 'ai-status-bar connected';
            label.textContent = 'Connected to Puter' + name;
            signoutBtn.style.display = '';
        } else {
            bar.className = 'ai-status-bar disconnected';
            label.textContent = 'Not signed in to Puter';
            linkEl.style.display = '';
            signinBtn.style.display = '';
        }
    } catch(_) {
        bar.className = 'ai-status-bar disconnected';
        label.textContent = 'Could not reach Puter';
        linkEl.style.display = '';
        signinBtn.style.display = '';
    }
}
async function legacyPuterSignIn()  { await loadPuterIfNeeded(); try { await puter.auth.signIn();  void refreshLegacyPuterStatus(); } catch(_) {} }
async function legacyPuterSignOut() { await loadPuterIfNeeded(); try { await puter.auth.signOut(); void refreshLegacyPuterStatus(); } catch(_) {} }

// ── Modal open/close ──
let legacySelectedFile = null;
let legacyExtractedRows = [];

function openNewCycleModal() {
    document.getElementById('new-cycle-modal').style.display = '';
    let i = document.getElementById('new-cycle-confirm');
    if (i) { i.value = ''; i.focus(); }
}
function closeNewCycleModal() {
    document.getElementById('new-cycle-modal').style.display = 'none';
}
function openLegacyImportModal() {
    resetLegacyImport(true);
    document.getElementById('legacy-import-modal').style.display = '';
    void refreshLegacyPuterStatus();
}
function closeLegacyImportModal() {
    document.getElementById('legacy-import-modal').style.display = 'none';
}

// ── File handling ──
function handleLegacyFileDrop(e) {
    e.preventDefault();
    document.getElementById('legacy-drop-zone').classList.remove('dragover');
    if (e.dataTransfer.files[0]) setLegacyFile(e.dataTransfer.files[0]);
}
function handleLegacyFileSelect(inp) {
    if (inp.files[0]) setLegacyFile(inp.files[0]);
}
function setLegacyFile(file) {
    legacySelectedFile = file;
    document.getElementById('legacy-drop-zone').classList.add('has-file');
    document.getElementById('legacy-dropzone-label').style.display = 'none';
    document.getElementById('legacy-file-tag-wrap').style.display = '';
    document.getElementById('legacy-file-tag-name').textContent = file.name;
    document.getElementById('legacy-process-btn').disabled = false;
}
function clearLegacyFile() {
    legacySelectedFile = null;
    document.getElementById('legacy-file-input').value = '';
    document.getElementById('legacy-drop-zone').classList.remove('has-file', 'dragover');
    document.getElementById('legacy-dropzone-label').style.display = '';
    document.getElementById('legacy-file-tag-wrap').style.display = 'none';
    document.getElementById('legacy-process-btn').disabled = true;
}
function resetLegacyImport(clearFile) {
    if (clearFile) clearLegacyFile();
    legacyExtractedRows = [];
    legacyResetProgress();
    ['upload', 'processing', 'preview', 'error'].forEach(s => {
        const el = document.getElementById('legacy-step-' + s);
        if (el) el.style.display = s === 'upload' ? '' : 'none';
    });
    document.getElementById('legacy-modal-footer').style.display = '';
    document.getElementById('legacy-save-footer').style.display = 'none';
    document.getElementById('legacy-process-btn').disabled = !legacySelectedFile;
}
function showLegacyStep(step) {
    ['upload', 'processing', 'preview', 'error'].forEach(s => {
        const el = document.getElementById('legacy-step-' + s);
        if (!el) return;
        el.style.display = s === step ? (s === 'processing' || s === 'preview' ? 'flex' : '') : 'none';
    });
}

// ── Progress ──
const LEGACY_RAMP_MS  = 10000;
const LEGACY_RAMP_PCT = 80;
let _lProgressTarget = 0, _lProgressCurrent = 0, _lProgressTimer = null, _lProgressStart = 0;

function legacySetProgress(pct, label, step) {
    _lProgressTarget = pct;
    if (label) document.getElementById('legacy-processing-label').textContent = label;
    if (step)  document.getElementById('legacy-progress-step').textContent = step;
    _lTickProgress();
}
function _lTickProgress() {
    if (_lProgressTimer) clearInterval(_lProgressTimer);
    if (!_lProgressStart) _lProgressStart = Date.now();
    _lProgressTimer = setInterval(() => {
        const gap = _lProgressTarget - _lProgressCurrent;
        if (gap <= 0) { clearInterval(_lProgressTimer); return; }
        let next;
        if (_lProgressTarget >= 95) {
            next = _lProgressCurrent + Math.max(2, gap * 0.3);
        } else {
            const elapsed = Date.now() - _lProgressStart;
            const byTime  = Math.min(LEGACY_RAMP_PCT, (elapsed / LEGACY_RAMP_MS) * LEGACY_RAMP_PCT);
            const minStep = _lProgressCurrent + 0.1;
            next = Math.min(_lProgressTarget, Math.max(minStep, byTime));
        }
        if (next >= _lProgressTarget) { next = _lProgressTarget; clearInterval(_lProgressTimer); }
        _lProgressCurrent = next;
        const fill = document.getElementById('legacy-progress-fill');
        const pct  = document.getElementById('legacy-progress-pct');
        if (fill) fill.style.width = _lProgressCurrent.toFixed(1) + '%';
        if (pct)  pct.textContent  = Math.round(_lProgressCurrent) + '%';
    }, 80);
}
function legacyResetProgress() {
    if (_lProgressTimer) clearInterval(_lProgressTimer);
    _lProgressTarget = 0; _lProgressCurrent = 0; _lProgressStart = 0;
    const fill = document.getElementById('legacy-progress-fill');
    const pct  = document.getElementById('legacy-progress-pct');
    if (fill) fill.style.width = '0%';
    if (pct)  pct.textContent  = '0%';
    const lbl  = document.getElementById('legacy-processing-label');
    const step = document.getElementById('legacy-progress-step');
    if (lbl)  lbl.textContent  = 'Reading file…';
    if (step) step.textContent = 'Preparing…';
}

// ── File readers ──
function readLegacyFileAsText(f) {
    return new Promise((r, j) => {
        const x = new FileReader();
        x.onload = () => r(x.result);
        x.onerror = j;
        x.readAsText(f);
    });
}
async function extractLegacyPdfText(f) {
    const b = await f.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: b }).promise;
    let t = '';
    for (let i = 1; i <= Math.min(pdf.numPages, 20); i++) {
        const pg = await pdf.getPage(i);
        const tc = await pg.getTextContent();
        t += tc.items.map(it => it.str).join(' ') + '\n';
    }
    return t.trim();
}
async function extractLegacyDocxText(f) {
    const b = await f.arrayBuffer();
    return (await mammoth.extractRawText({ arrayBuffer: b })).value;
}
async function extractXlsxAsText(f) {
    await loadSheetJs();
    const b  = await f.arrayBuffer();
    const wb = XLSX.read(b, { type: 'array' });
    let out  = '';
    for (const sheetName of wb.SheetNames) {
        const ws  = wb.Sheets[sheetName];
        const csv = XLSX.utils.sheet_to_csv(ws);
        if (csv.trim()) out += '=== Sheet: ' + sheetName + ' ===\n' + csv + '\n\n';
    }
    return out.trim();
}

// ── AI + processing core ──
const LEGACY_COURSES = window.PAGE.legacyCourses;

// ─────────────────────────────────────────────────────────────
//  PHASE 1 PROMPT  — schema detection only (header + 5 rows)
// ─────────────────────────────────────────────────────────────
const SCHEMA_PROMPT = `You are a column-mapping assistant for a Philippine university admissions system.

You will be given CSV column headers and up to 5 sample rows from a legacy admissions file.
Your ONLY job: return a JSON object that maps each output field to the matching header name(s).

Output exactly this shape (no markdown, no prose, just the JSON object):
{
  "name": "column name if name is in one field, or null",
  "name_parts": ["col1","col2","col3"] or null (if name is split across columns, list them in order: last/first/middle or first/last - whatever the file uses),
  "email":   "column name or null",
  "type":    "column name or null",
  "course":  "column name or null",
  "result":  "column name or null",
  "score":   "column name or null",
  "total":   "column name or null",
  "remarks": "column name or null"
}

Rules:
- Use the EXACT header string as it appears in the CSV (case-sensitive)
- If a field has no matching column, use null
- For name: prefer a single full-name column; if absent, list the part-columns in display order
- "type" means applicant type (freshman / transferee / foreign)
- "result" means admission outcome (accepted / rejected / waitlisted)
- "score" / "total" are exam scores
- Filipino header synonyms: Pangalan=name, Kurso=course, Uri=type, Resulta=result`;

// ─────────────────────────────────────────────────────────────
//  PHASE 2  — client-side row mapping (no AI needed)
// ─────────────────────────────────────────────────────────────
const RESULT_MAP = {
    accepted:   ['accepted','passed','admitted','pass','admit','approved','qualified','pumasa','yes','✓','1'],
    waitlisted: ['waitlisted','waitlist','wait','pending','hold','conditional','for interview','wl'],
    rejected:   ['rejected','failed','did not pass','fail','not qualified','bumagsak','hindi pumasa','no','✗','0'],
};
const TYPE_MAP = {
    freshman:   ['freshman','fresh','grade 12','g12','hs','senior high','shs','sh','grade12','1st year','new student','new'],
    transferee: ['transferee','transfer','trans','2nd year','3rd year'],
    foreign:    ['foreign','international','intl','foreigner',"int'l"],
};

function normalizeLookup(val, map, fallback) {
    if (val == null) return fallback;
    const v = String(val).toLowerCase().trim();
    for (const [key, aliases] of Object.entries(map)) {
        if (aliases.some(a => v.includes(a))) return key;
    }
    return fallback;
}

// "(BSIT)" style abbreviation inside a course name, via indexOf (no regex backtracking).
function parenText(s) {
    const open = s.indexOf('(');
    const close = open === -1 ? -1 : s.indexOf(')', open + 1);
    return close > open + 1 ? s.slice(open + 1, close) : '';
}

// Course name with its first "(ABBR)" part removed, lowercased.
function courseBaseName(c) {
    const open = c.indexOf('(');
    const close = open === -1 ? -1 : c.indexOf(')', open + 1);
    const base = close > open + 1 ? c.slice(0, open) + c.slice(close + 1) : c;
    return base.trim().toLowerCase();
}

function normalizeCourse(raw) {
    if (!raw) return '';
    const v = raw.trim();
    const lower = v.toLowerCase();
    // Exact match first
    const exact = LEGACY_COURSES.find(c => c.toLowerCase() === lower);
    if (exact) return exact;
    // Abbreviation in parentheses match e.g. "(BSIT)"
    const abbr = parenText(v).toUpperCase();
    if (abbr) {
        const m = LEGACY_COURSES.find(c => c.toUpperCase().includes('(' + abbr + ')'));
        if (m) return m;
    }
    // Keyword match: check if raw appears inside any course string
    const keyword = LEGACY_COURSES.find(c => c.toLowerCase().includes(lower) || lower.includes(courseBaseName(c)));
    return keyword || v; // keep original if no match
}

function mappedText(rowObj, key) {
    return key ? String(rowObj[key] ?? '').trim() : '';
}

function mappedNumber(raw) {
    if (raw == null || raw === '') return null;
    const n = Number.parseFloat(raw);
    return Number.isNaN(n) ? null : n;
}

function mappedName(rowObj, schema) {
    if (schema.name && rowObj[schema.name] != null) return String(rowObj[schema.name]).trim();
    if (Array.isArray(schema.name_parts)) {
        return schema.name_parts.map(p => (rowObj[p] ?? '')).join(' ').replace(/\s+/g, ' ').trim();
    }
    return '';
}

function applySchemaToRow(rowObj, schema) {
    return {
        name:    mappedName(rowObj, schema),
        email:   mappedText(rowObj, schema.email),
        type:    normalizeLookup(mappedText(rowObj, schema.type), TYPE_MAP, 'freshman'),
        course:  normalizeCourse(mappedText(rowObj, schema.course)),
        result:  normalizeLookup(mappedText(rowObj, schema.result), RESULT_MAP, 'rejected'),
        score:   mappedNumber(schema.score ? rowObj[schema.score] : null),
        total:   mappedNumber(schema.total ? rowObj[schema.total] : null),
        remarks: mappedText(rowObj, schema.remarks),
    };
}

// Parse CSV text into array of {header: value} objects
function parseCsvToObjects(text) {
    const lines = text.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) return { headers: [], rows: [] };
    // Simple CSV parser (handles quoted fields)
    function parseLine(line) {
        const fields = [];
        let cur = '', inQ = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') { inQ = !inQ; }
            else if (ch === ',' && !inQ) { fields.push(cur.trim()); cur = ''; }
            else cur += ch;
        }
        fields.push(cur.trim());
        return fields;
    }
    const headers = parseLine(lines[0]);
    const rows = [];
    for (let i = 1; i < lines.length; i++) {
        const vals = parseLine(lines[i]);
        if (vals.every(v => !v)) continue; // skip blank rows
        const obj = {};
        headers.forEach((h, idx) => { obj[h] = vals[idx] ?? ''; });
        rows.push(obj);
    }
    return { headers, rows };
}

// ─────────────────────────────────────────────────────────────
//  Puter helpers
// ─────────────────────────────────────────────────────────────
async function puterChat(prompt) {
    await loadPuterIfNeeded();
    const ok = await puter.auth.isSignedIn();
    if (!ok) await puter.auth.signIn();
    const response = await puter.ai.chat(prompt, { model: 'claude-sonnet-4-6' });
    return extractLegacyText(response);
}

// Detect column schema via AI (one small call)
async function detectSchema(headers, sampleRows) {
    const sampleCsv = [headers.join(',')]
        .concat(sampleRows.slice(0,5).map(r => headers.map(h => r[h] ?? '').join(',')))
        .join('\n');
    const raw = await puterChat(SCHEMA_PROMPT + '\n\nHEADERS AND SAMPLE ROWS:\n' + sampleCsv);
    // Parse the returned JSON object
    const cleaned = PLP.stripCodeFence(raw);
    const start = cleaned.indexOf('{'), end = cleaned.lastIndexOf('}');
    if (start === -1 || end === -1) throw new Error('AI did not return a valid schema map.');
    return JSON.parse(cleaned.slice(start, end + 1));
}

// Full-file call for unstructured content (PDF, image, DOCX) — JSONL output
const LEGACY_AI_PROMPT = `You are an expert data extraction assistant for a Philippine university admissions system.

Your task: extract ALL student admission records from the uploaded file (which may be a messy old Excel, CSV, PDF, table image, or hand-keyed spreadsheet).

Output ONE JSON object per line (JSONL). Each line must be a complete, self-contained JSON object. No wrapping array. No markdown. No prose.

Each object:
{"name":"Full Name","email":"email or empty","type":"freshman|transferee|foreign","course":"exact course","result":"accepted|waitlisted|rejected","score":numeric_or_null,"total":numeric_or_null,"remarks":"notes or empty"}

NORMALIZATION:
type:   freshman←SHS/G12/Fresh/1st year | transferee←Transfer/2nd-3rd year | foreign←International
result: accepted←Passed/Admitted/Qualified/PUMASA | waitlisted←Pending/WL/Conditional | rejected←Failed/Did not pass/BUMAGSAK
course: normalize to closest from: ${LEGACY_COURSES.join(', ')}
score:  "87/100"→score=87,total=100 | "87%"→score=87,total=100 | "87"→score=87,total=null

If no records found, output nothing.`;

async function callLegacyPuterWithImage(file) {
    if (!file || !file.size) throw new Error('Image file is empty.');
    await loadPuterIfNeeded();
    const ok = await puter.auth.isSignedIn();
    if (!ok) await puter.auth.signIn();
    const tmpName = 'legacy_import_' + Date.now() + '.' + file.name.split('.').pop();
    let puterFile;
    try { puterFile = await puter.fs.write(tmpName, file); }
    catch (e) { throw new Error('Could not upload image to Puter: ' + (e?.message || e)); }
    if (!puterFile?.path) throw new Error('Puter returned no file path. Try again.');
    if (typeof puterFile.size === 'number' && puterFile.size === 0) {
        try { await puter.fs.delete(puterFile.path); } catch(_) {}
        throw new Error('Image upload wrote 0 bytes. Try again.');
    }
    let response;
    try {
        response = await puter.ai.chat(
            [{ role: 'user', content: [{ type: 'file', puter_path: puterFile.path }, { type: 'text', text: LEGACY_AI_PROMPT }] }],
            { model: 'claude-sonnet-4-6' }
        );
    } finally {
        try { await puter.fs.delete(puterFile.path); } catch(_) {}
    }
    return parseLegacyResp(extractLegacyText(response));
}

// ── JSON parse helpers ──
function extractLegacyText(response) {
    if (response && response.success === false) throw new Error('Puter AI error: ' + (response.error || 'unknown'));
    const text = response?.message?.content?.[0]?.text || response?.message?.content || '';
    if (!text) throw new Error('AI returned an empty response.');
    return text;
}
// Parse JSONL response — tolerates a truncated last line.
// Parse one JSON text; returns the object, or null if it is invalid or not a plain object.
function tryParseObject(text) {
    try {
        const obj = JSON.parse(text);
        return obj && typeof obj === 'object' && !Array.isArray(obj) ? obj : null;
    } catch (_) {
        return null;
    }
}

// Track string/escape state one character at a time. Returns true when the
// character belongs to a string literal and must not be counted as a brace.
function scanStringState(st, ch) {
    if (st.escape) { st.escape = false; return true; }
    if (ch === '\\') { if (st.inStr) st.escape = true; return true; }
    if (ch === '"') { st.inStr = !st.inStr; return true; }
    return st.inStr;
}

// Count braces; when a top-level {...} closes, return it parsed (or null).
function scanBraces(st, str, i) {
    const ch = str[i];
    if (ch === '{') {
        if (st.depth++ === 0) st.start = i;
        return null;
    }
    if (ch !== '}') return null;
    st.depth--;
    if (st.depth !== 0 || st.start === -1) return null;
    const obj = tryParseObject(str.slice(st.start, i + 1));
    st.start = -1;
    return obj;
}

// Walk a string and extract every complete top-level {...} object,
// even if the surrounding array is truncated. Handles escaped chars + nested braces.
function extractCompleteObjects(str) {
    const results = [];
    const st = { depth: 0, start: -1, inStr: false, escape: false };
    for (let i = 0; i < str.length; i++) {
        if (scanStringState(st, str[i])) continue;
        const obj = scanBraces(st, str, i);
        if (obj) results.push(obj);
    }
    return results;
}

// Strategy 1: JSONL — one object per line (ideal path)
function parseJsonlLines(cleaned) {
    const rows = [];
    for (const line of cleaned.split('\n')) {
        const t = line.trim().replace(/,$/, '');
        if (!t || t === '[' || t === ']') continue;
        const obj = tryParseObject(t);
        if (obj) rows.push(obj);
    }
    return rows;
}

// Strategy 2: complete JSON array (not truncated)
function parseJsonArray(cleaned) {
    try {
        const s = cleaned.replace(/,(\s*[}\]])/g, '$1');
        const start = s.indexOf('['), end = s.lastIndexOf(']');
        if (start === -1 || end <= start) return [];
        const a = JSON.parse(s.slice(start, end + 1));
        return Array.isArray(a) ? a : [];
    } catch (_) {
        return [];
    }
}

function parseLegacyResp(raw) {
    const cleaned = PLP.stripCodeFence(raw);
    // Strategy 3 (last resort): salvage every complete {...} from a cut-off response.
    for (const strategy of [parseJsonlLines, parseJsonArray, extractCompleteObjects]) {
        const rows = strategy(cleaned);
        if (rows.length > 0) return rows;
    }
    throw new Error('AI response was not valid JSON. Raw:\n' + raw.slice(0, 300));
}

// ── Result badge colours ──
function resultBadge(r) {
    const map = {
        accepted:   'badge-approved',
        waitlisted: 'badge-pending',
        rejected:   'badge-rejected',
    };
    return `<span class="badge ${map[r] || ''}">${r}</span>`;
}

// ── Render preview table ──
function renderLegacyPreview(rows) {
    const tbody = document.getElementById('legacy-preview-tbody');
    tbody.innerHTML = '';
    rows.forEach((row, i) => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid var(--border)';
        const score = (row.score != null && row.total != null)
            ? row.score + '/' + row.total
            : (row.score != null ? String(row.score) : '-');
        tr.innerHTML = `
            <td style="padding:var(--space-2) var(--space-3)">
                <input type="checkbox" class="legacy-row-check" data-idx="${i}" checked>
            </td>
            <td style="padding:var(--space-2) var(--space-3)">${escHtml(row.name || '-')}</td>
            <td style="padding:var(--space-2) var(--space-3);white-space:nowrap">${escHtml(row.type || '-')}</td>
            <td style="padding:var(--space-2) var(--space-3);font-size:var(--text-xs)">${escHtml(row.course || '-')}</td>
            <td style="padding:var(--space-2) var(--space-3)">${resultBadge(row.result)}</td>
            <td style="padding:var(--space-2) var(--space-3);white-space:nowrap">${escHtml(score)}</td>
        `;
        tbody.appendChild(tr);
    });
}
function escHtml(s) {
    return PLP.esc(s);
}
function toggleAllLegacyRows(checked) {
    document.querySelectorAll('.legacy-row-check').forEach(cb => cb.checked = checked);
}

// ── Main processing ──
function validateLegacySchoolYear(syVal, syHint) {
    let msg = '';
    if (!/^\d{4}-\d{4}$/.test(syVal)) {
        msg = 'Enter a valid school year like 2023-2024.';
    } else {
        const [y1, y2] = syVal.split('-').map(Number);
        if (y2 !== y1 + 1) msg = 'The two years must be consecutive (e.g. 2023-2024).';
    }
    if (msg) {
        syHint.textContent = msg;
        syHint.style.display = '';
        return false;
    }
    syHint.style.display = 'none';
    return true;
}

// Read the chosen file into { content, isImage }. Images are passed through as the
// File itself; everything else becomes text.
async function readLegacyContent(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    legacySetProgress(15, 'Reading file…', 'Loading file from disk');

    if (['jpg', 'jpeg', 'png'].includes(ext)) return { content: file, isImage: true };
    if (ext === 'pdf') {
        legacySetProgress(30, 'Extracting text…', 'Parsing PDF pages');
        await loadPdfJsIfNeeded();
        return { content: await extractLegacyPdfText(file), isImage: false };
    }
    if (['docx', 'doc'].includes(ext)) {
        legacySetProgress(30, 'Extracting text…', 'Parsing document');
        await loadMammothIfNeeded();
        return { content: await extractLegacyDocxText(file), isImage: false };
    }
    if (['xlsx', 'xls'].includes(ext)) {
        legacySetProgress(30, 'Extracting text…', 'Reading spreadsheet');
        return { content: await extractXlsxAsText(file), isImage: false };
    }
    // CSV and plain text
    return { content: await readLegacyFileAsText(file), isImage: false };
}

// Structured (CSV/TSV): AI detects the column layout once, rows are then mapped client-side.
async function extractLegacyCsvRows(content) {
    legacySetProgress(35, 'Detecting columns…', 'Asking AI to identify column layout');
    const { headers, rows: parsedRows } = parseCsvToObjects(content);
    if (headers.length === 0 || parsedRows.length === 0) {
        throw new Error('Could not parse the CSV. Make sure it has a header row.');
    }
    const schema = await detectSchema(headers, parsedRows);

    legacySetProgress(60, 'Mapping records…', `Applying column map to ${parsedRows.length} rows`);
    return parsedRows
        .map(r => applySchemaToRow(r, schema))
        .filter(r => r.name.trim());   // skip rows with no name
}

// Images and unstructured text go to the AI whole; CSV-like text uses the schema path.
async function extractLegacyRows(content, isImage) {
    if (isImage) {
        legacySetProgress(40, 'Sending to AI…', 'Uploading image to Puter AI');
        return callLegacyPuterWithImage(content);
    }
    const firstLine = (typeof content === 'string' ? content : '').split(/\r?\n/)[0] || '';
    const isCsv = firstLine.split(',').length >= 3 || firstLine.split('\t').length >= 3;
    if (isCsv) return extractLegacyCsvRows(content);

    legacySetProgress(40, 'Sending to AI…', 'Extracting records from document');
    const raw = await puterChat(LEGACY_AI_PROMPT + '\n\nCONTENT:\n\n' + content.slice(0, 12000));
    return parseLegacyResp(raw);
}

function showLegacyPreview(rows) {
    legacyExtractedRows = rows;
    renderLegacyPreview(rows);
    showLegacyStep('preview');
    const count = rows.length;
    document.getElementById('legacy-preview-count').textContent =
        `${count} record${count === 1 ? '' : 's'} found. Uncheck any you want to skip.`;
    document.getElementById('legacy-save-footer').style.display = 'flex';
}

function showLegacyError(err) {
    showLegacyStep('error');
    document.getElementById('legacy-modal-footer').style.display = '';
    const msg = typeof err === 'string' ? err : (err?.message || err?.error || 'Unknown error');
    document.getElementById('legacy-error-msg').textContent = msg;
    document.getElementById('legacy-process-btn').disabled = !legacySelectedFile;
}

async function startLegacyProcessing() {
    if (!legacySelectedFile) return;

    const syVal = document.getElementById('legacy-sy-input').value.trim();
    const syHint = document.getElementById('legacy-sy-hint');
    if (!validateLegacySchoolYear(syVal, syHint)) return;

    legacyResetProgress();
    showLegacyStep('processing');
    document.getElementById('legacy-modal-footer').style.display = 'none';

    try {
        if (!legacySelectedFile.size) throw new Error('File is empty (0 bytes).');

        await loadPuterIfNeeded();
        const { content, isImage } = await readLegacyContent(legacySelectedFile);

        if (!content) throw new Error('Could not read this file. Try a different format.');
        if (typeof content === 'string' && content.trim().length < 5) {
            throw new Error('No readable text found. If this is a scanned PDF, save pages as JPG/PNG and upload those.');
        }

        const rows = await extractLegacyRows(content, isImage);

        legacySetProgress(100, 'Finalizing…', 'Parsing AI response');
        await new Promise(r => setTimeout(r, 350));

        if (!rows || rows.length === 0) throw new Error('No student records were detected in this file.');
        showLegacyPreview(rows);
    } catch (err) {
        showLegacyError(err);
    }
}

// ── Save ──
async function saveLegacyRows() {
    const saveBtn = document.getElementById('legacy-save-btn');
    const syVal   = document.getElementById('legacy-sy-input').value.trim();

    // Collect checked rows
    const checkedIndices = Array.from(document.querySelectorAll('.legacy-row-check:checked'))
                                .map(cb => Number.parseInt(cb.dataset.idx, 10));
    if (checkedIndices.length === 0) {
        await ui.alert('No rows selected. Check at least one record to save.');
        return;
    }

    const rowsToSave = checkedIndices.map(i => legacyExtractedRows[i]);

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    try {
        const fd = new FormData();
        fd.append('action',      'import_legacy_rows');
        fd.append('school_year', syVal);
        fd.append('rows',        JSON.stringify(rowsToSave));
        fd.append('_csrf',       document.querySelector('input[name=_csrf]')?.value || '');

        const resp = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body:   fd,
        });
        const json = await resp.json();

        if (!json.ok) throw new Error(json.error || 'Server returned an error.');

        const skippedTxt = json.skipped?.length
            ? '\n\n' + json.skipped.length + ' row(s) skipped: ' + json.skipped.join('; ')
            : '';

        closeLegacyImportModal();

        await ui.alert(
            json.inserted + ' record(s) imported into AY ' + syVal + '. ' +
            'They now appear in Results under ' + syVal + '.' + skippedTxt,
            { title: 'Import complete' }
        );
        window.location.reload();

    } catch (err) {
        await ui.alert('Save failed: ' + (err?.message || err), { title: 'Save failed' });
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Selected Records';
    }
}
