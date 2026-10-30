// Page script for the AI exam import modal in modules/exam/staff_manage.php.
// Uses window.PAGE.examId from the module's bootstrap.

let puterLoaded = false;
function loadPuter() {
    return new Promise(res => {
        if (puterLoaded || window.puter) { puterLoaded=true; res(); return; }
        const s = document.createElement('script'); s.src = 'https://js.puter.com/v2/';
        s.onload = () => { puterLoaded=true; res(); }; document.head.appendChild(s);
    });
}
function loadPdfJs() { return PLP.loadPdfJs(); }
function loadMammoth() {
    return new Promise(res => {
        if (window.mammoth) { res(); return; }
        const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js'; s.onload = res; document.head.appendChild(s);
    });
}
async function refreshPuterStatus() {
    const bar=document.getElementById('ai-puter-status'), label=document.getElementById('ai-puter-status-text');
    const linkEl=document.getElementById('ai-puter-link'), signinBtn=document.getElementById('ai-puter-signin-btn'), signoutBtn=document.getElementById('ai-puter-signout-btn');
    label.textContent='Checking Puter connection…'; [linkEl,signinBtn,signoutBtn].forEach(el=>el.style.display='none');
    try {
        await loadPuter();
        const ok = await puter.auth.isSignedIn();
        if (ok) {
            let name=''; try { const u=await puter.auth.getUser(); name=u?.username?' as @'+u.username:''; } catch(_){}
            bar.className='ai-status-bar connected'; label.textContent='Connected to Puter'+name; signoutBtn.style.display='';
        } else { bar.className='ai-status-bar disconnected'; label.textContent='Not signed in to Puter'; linkEl.style.display=''; signinBtn.style.display=''; }
    } catch(_) { bar.className='ai-status-bar disconnected'; label.textContent='Could not reach Puter'; linkEl.style.display=''; signinBtn.style.display=''; }
}
async function puterSignIn() { await loadPuter(); try { await puter.auth.signIn(); void refreshPuterStatus(); } catch(_){} }
async function puterSignOut(){ await loadPuter(); try { await puter.auth.signOut(); void refreshPuterStatus(); } catch(_){} }

// aiGeneratedSections = [{section_title, section_type, questions:[...]}, ...]
let aiSelectedFile=null, aiGeneratedSections=[];

function openAiImportModal()  { resetAiImport(true); openModal('ai-import-modal'); void refreshPuterStatus(); }
function closeAiImportModal() { closeModal('ai-import-modal'); }
function handleAiFileDrop(e)  { e.preventDefault(); document.getElementById('ai-drop-zone').classList.remove('dragover'); if(e.dataTransfer.files[0]) setAiFile(e.dataTransfer.files[0]); }
function handleAiFileSelect(inp) { if(inp.files[0]) setAiFile(inp.files[0]); }
function setAiFile(file) {
    aiSelectedFile=file; document.getElementById('ai-drop-zone').classList.add('has-file');
    document.getElementById('ai-dropzone-label').style.display='none'; document.getElementById('ai-file-tag-wrap').style.display='';
    document.getElementById('ai-file-tag-name').textContent=file.name; document.getElementById('ai-process-btn').disabled=false;
}
function clearAiFile() {
    aiSelectedFile=null; document.getElementById('ai-file-input').value='';
    document.getElementById('ai-drop-zone').classList.remove('has-file','dragover');
    document.getElementById('ai-dropzone-label').style.display=''; document.getElementById('ai-file-tag-wrap').style.display='none';
    document.getElementById('ai-process-btn').disabled=true;
}
function resetAiImport(clearFile) {
    if(clearFile) { aiSelectedFile=null; document.getElementById('ai-file-input').value=''; document.getElementById('ai-drop-zone').classList.remove('has-file','dragover'); document.getElementById('ai-dropzone-label').style.display=''; document.getElementById('ai-file-tag-wrap').style.display='none'; }
    aiGeneratedSections=[];
    resetProgress();
    document.getElementById('ai-process-btn').disabled=!aiSelectedFile;
    ['upload','processing','preview','error'].forEach(s=>{ const el=document.getElementById('ai-step-'+s); if(el) el.style.display=s==='upload'?'':'none'; if(s==='preview'&&el) el.style.flexDirection='column'; });
    document.getElementById('ai-modal-footer').style.display=''; document.getElementById('ai-save-footer').style.display='none';
}
function showAiStep(step) {
    ['upload','processing','preview','error'].forEach(s=>{ const el=document.getElementById('ai-step-'+s); if(!el) return; el.style.display=s===step?(s==='processing'||s==='preview'?'flex':''):'none'; });
}
// ── Progress bar helpers ──────────────────────────────────────
// ── AI import progress ────────────────────────────────────────
// The pre-AI steps (file read → text extraction → upload) finish in
// milliseconds, so the bar used to ping straight to 88% the instant
// the request was sent and then sit there for the whole AI wait. To
// make the wait feel steady, we time-lock the 0 → 80% ramp to ~5s of
// real elapsed time, regardless of how fast the per-step targets
// arrive. Once the AI returns and we set target=100, we snap to the
// finish quickly.
const PROGRESS_RAMP_MS  = 10000;  // 0 → 80% takes this many ms of wall time
const PROGRESS_RAMP_PCT = 80;     // ceiling for the time-locked phase

let _progressTarget = 0, _progressCurrent = 0, _progressTimer = null;
let _progressStartedAt = 0;

function setProgress(pct, label, step) {
    _progressTarget = pct;
    if (label) document.getElementById('ai-processing-label').textContent = label;
    if (step)  document.getElementById('ai-progress-step').textContent = step;
    _tickProgress();
}

function _tickProgress() {
    if (_progressTimer) clearInterval(_progressTimer);
    if (!_progressStartedAt) _progressStartedAt = Date.now();

    _progressTimer = setInterval(() => {
        const gap = _progressTarget - _progressCurrent;
        if (gap <= 0) { clearInterval(_progressTimer); return; }

        let next;
        if (_progressTarget >= 95) {
            // Closing out — snap home (covers the AI-just-returned case).
            next = _progressCurrent + Math.max(2, gap * 0.3);
        } else {
            // Time-locked steady climb: position purely follows wall clock,
            // capped at PROGRESS_RAMP_PCT so we never blow past 80% before
            // the AI actually replies. Targets below the ceiling still cap
            // the climb (e.g. step says "30%" → bar parks at 30% even if
            // real time would put it higher).
            const elapsed = Date.now() - _progressStartedAt;
            const byTime  = Math.min(PROGRESS_RAMP_PCT, (elapsed / PROGRESS_RAMP_MS) * PROGRESS_RAMP_PCT);
            const minStep = _progressCurrent + 0.1;
            next = Math.min(_progressTarget, Math.max(minStep, byTime));
        }

        if (next >= _progressTarget) {
            next = _progressTarget;
            clearInterval(_progressTimer);
        }
        _progressCurrent = next;

        const fill = document.getElementById('ai-progress-fill');
        const pct  = document.getElementById('ai-progress-pct');
        if (fill) fill.style.width = _progressCurrent.toFixed(1) + '%';
        if (pct)  pct.textContent  = Math.round(_progressCurrent) + '%';
    }, 80);
}

function resetProgress() {
    if (_progressTimer) clearInterval(_progressTimer);
    _progressTarget = 0; _progressCurrent = 0;
    _progressStartedAt = 0;
    const fill = document.getElementById('ai-progress-fill');
    const pct  = document.getElementById('ai-progress-pct');
    if (fill) fill.style.width = '0%';
    if (pct)  pct.textContent  = '0%';
    document.getElementById('ai-progress-step').textContent = 'Preparing…';
    document.getElementById('ai-processing-label').textContent = 'Reading file…';
}

// Read the chosen file into { content, isImage }. Images stay as the File; the rest becomes text.
async function readAiFileContent(file, ext) {
    // Step 1 — Read file (0 → 15%)
    setProgress(15, 'Reading file…', 'Loading file from disk');
    if (['jpg', 'jpeg', 'png'].includes(ext)) return { content: file, isImage: true };
    if (ext === 'pdf') {
        // Step 2 — Extract PDF (15 → 30%)
        setProgress(30, 'Extracting text…', 'Parsing PDF pages');
        await loadPdfJs();
        return { content: await extractPdfText(file), isImage: false };
    }
    if (['docx', 'doc'].includes(ext)) {
        // Step 2 — Extract DOCX (15 → 30%)
        setProgress(30, 'Extracting text…', 'Parsing document');
        await loadMammoth();
        return { content: await extractDocxText(file), isImage: false };
    }
    return { content: await readFileAsText(file), isImage: false };
}

// Stricter content guard. < 50 chars from a PDF/DOCX almost always means the file
// is image-based (scanned, no text layer) - sending that to the AI just produces
// a refusal response.
function assertReadableAiContent(content, ext) {
    if (!content) throw new Error('Could not read this file. Try a different format.');
    if (typeof content !== 'string') return;
    const len = content.trim().length;
    if (len < 5) {
        throw new Error('Could not extract any readable text from this file. If it is a scanned PDF, save it as JPG/PNG instead and re-upload - the AI can read images.');
    }
    if (len < 50 && ['pdf', 'doc', 'docx'].includes(ext)) {
        throw new Error('Only ' + len + ' characters of text could be extracted from this ' + ext.toUpperCase() + '. It is most likely a scanned document with no selectable text. Save the pages as JPG/PNG images and upload one of those instead.');
    }
}

// Throws when the AI result is empty or is really a refusal; returns the question count.
function assertAiSections(sections) {
    if (!sections || sections.length === 0) throw new Error('No sections or questions were detected.');
    const totalQ = sections.reduce((sum, s) => sum + s.questions.length, 0);
    if (totalQ === 0) throw new Error('No questions were detected.');

    // Refusal detection: parseAiResp can succeed on AI apology JSON
    // (e.g. one section titled "Error" with a single question whose
    // text says "No exam content was provided…"). Surface those as a
    // clear error instead of letting them render as a fake exam.
    const refusal = _detectRefusal(sections);
    if (refusal) {
        throw new Error(
            'The AI didn\u2019t see any actual exam in what was sent. It said:\n\n“' +
            refusal + '”\n\n' +
            'Most common cause: the file is a scanned/image-only PDF or a corrupt upload. Save the pages as JPG/PNG and upload one of those, or pick a different file.'
        );
    }
    return totalQ;
}

function showAiPreviewResult(sections, totalQ) {
    aiGeneratedSections = sections;
    renderAiPreview(sections);
    showAiStep('preview');
    document.getElementById('ai-preview-count').textContent =
        `${sections.length} section${sections.length === 1 ? '' : 's'}, ${totalQ} question${totalQ === 1 ? '' : 's'} detected`;
    document.getElementById('ai-save-footer').style.display = '';
}

function showAiError(err) {
    showAiStep('error');
    document.getElementById('ai-modal-footer').style.display = '';
    const msg = typeof err === 'string' ? err : err?.message || err?.error || 'Unknown error';
    document.getElementById('ai-error-msg').textContent = msg;
    document.getElementById('ai-process-btn').disabled = !aiSelectedFile;
}

async function startAiProcessing() {
    if (!aiSelectedFile) return;
    resetProgress();
    showAiStep('processing');
    document.getElementById('ai-modal-footer').style.display = 'none';

    try {
        // Pre-flight: empty file would only confuse the AI and waste a call.
        if (!aiSelectedFile.size) {
            throw new Error('The selected file is empty (0 bytes). Pick a different file and try again.');
        }
        await loadPuter();
        const ext = aiSelectedFile.name.split('.').pop().toLowerCase();
        const { content, isImage } = await readAiFileContent(aiSelectedFile, ext);
        assertReadableAiContent(content, ext);

        // Step 3 — Send to AI (→ 40%)
        setProgress(40, 'Sending to AI…', 'Uploading content to Puter AI');

        // Step 4 — AI processing (40 → 88%, slow crawl while we wait)
        const pts = Number.parseInt(document.getElementById('ai-default-points').value, 10) || 1;
        // Kick off slow crawl to 88% — will snap to 100 when done
        setProgress(88, 'AI is processing…', 'Generating questions from your file');

        const sections = isImage
            ? await callPuterWithImage(content, pts)
            : await callPuterWithText(content, pts);

        // Step 5 — Finalizing (88 → 100%)
        setProgress(100, 'Finalizing…', 'Parsing AI response');
        await new Promise(r => setTimeout(r, 350)); // let bar finish

        showAiPreviewResult(sections, assertAiSections(sections));
    } catch (err) {
        showAiError(err);
    }
}

function readFileAsText(f) { return new Promise((r,j)=>{ const x=new FileReader(); x.onload=()=>r(x.result); x.onerror=j; x.readAsText(f); }); }
async function extractPdfText(f) {
    const b=await f.arrayBuffer(), pdf=await pdfjsLib.getDocument({data:b}).promise; let t='';
    for(let i=1;i<=Math.min(pdf.numPages,20);i++){const pg=await pdf.getPage(i),tc=await pg.getTextContent(); t+=tc.items.map(it=>it.str).join(' ')+'\n';} return t.trim();
}
async function extractDocxText(f) { const b=await f.arrayBuffer(); return(await mammoth.extractRawText({arrayBuffer:b})).value; }
async function ensurePuterAuth() { await loadPuter(); const ok=await puter.auth.isSignedIn(); if(!ok) await puter.auth.signIn(); }
function extractPuterText(response) {
    if(response&&response.success===false) throw new Error('Puter AI error: '+(response.error||'unknown'));
    const text=response?.message?.content?.[0]?.text||response?.message?.content||'';
    if(!text) throw new Error('AI returned an empty response.');
    return text;
}

// ── AI prompt — extracts sections + questions ─────────────────
const AI_PROMPT = `You are an expert exam question extractor for Philippine school exams. Extract ALL sections and ALL questions from the exam content.

Return ONLY a valid JSON array of sections - no markdown, no explanation, no extra text.

Structure:
[
  {
    "section_title": "I. Multiple Choice",
    "section_type": "multiple_choice",
    "section_description": "Choose the best answer.",
    "questions": [
      {
        "question_text": "What is the capital of France?",
        "choices": ["London", "Paris", "Berlin", "Rome"],
        "correct_index": 1,
        "correct_indices": [],
        "correct_answer": null,
        "points": 1
      }
    ]
  }
]

SECTION TYPE RULES:
- "multiple_choice" → Multiple Choice, True/False, Matching Type
- "checkboxes"      → Select all that apply, Multiple correct answers
- "short_answer"    → Identification, Fill in the blank, Enumeration, Completion
- "paragraph"       → Essay, Explain, Discuss, Long answer

IDENTIFYING CORRECT ANSWERS (critical - read carefully):
- In Philippine printed exams, the correct answer is shown in BOLD, UNDERLINED, or ALL-CAPS in the answer choices
- Look very carefully at each choice - whichever one appears visually emphasized (bold/underlined/caps) is the correct answer
- correct_index = 0-based position of the correct choice (0=a, 1=b, 2=c, 3=d)
- If no answer is marked, set correct_index to 0
- For short_answer/identification: set correct_answer to the expected answer if shown (e.g. "QL", "QUALITATIVE", etc.)
- Do NOT include any "description", hint, or "correct answer indicated as bold" text in the output - only the fields listed in the schema above

CHOICE FORMATTING:
- Strip letter/number prefixes from choices (e.g. "a. Paris" → "Paris", "A) Paris" → "Paris", "1. Paris" → "Paris")
- Keep the choice text only, no labels

TRUE/FALSE SECTIONS - IMPORTANT:
- Each numbered statement is a SEPARATE question
- question_text = the statement itself (e.g. "The sky is blue")
- choices = ["True", "False"] for every question
- Do NOT extract the instructions ("Write TRUE if...") as a question - that is a direction, not a question
- correct_index = 0 if True is correct, 1 if False is correct (or 0 if unknown)

IDENTIFICATION/SHORT ANSWER SECTIONS:
- Each numbered item is a separate question
- Extract any hint text after the question (like "QL" or "QT") as correct_answer
- Example: "11. Identify patterns, features, themes QL" → question_text="Identify patterns, features, themes", correct_answer="QUALITATIVE"
- Expand abbreviations: QL/Q.L. → "QUALITATIVE", QT/Q.T. → "QUANTITATIVE"

GENERAL RULES:
- Extract EVERY numbered question - do not skip any
- section_description = copy any directions/instructions shown at the top of that section
- Do NOT treat section headings or instructions as questions
- Output ONLY the JSON array, nothing else

OUTPUT FORMAT (strict - failures here break the import):
- The very first character of your response must be \`[\` and the very last must be \`]\`
- Do NOT wrap the array in an object (no \`{"sections": [...]}\` - just the bare array)
- Do NOT include a trailing comma after the last item
- Do NOT include comments
- Do NOT include any prose, summary, or markdown fences before/after the array`;

async function callPuterWithText(text, pts) {
    const trunc=text.length>10000?text.slice(0,10000)+'…[truncated]':text;
    await ensurePuterAuth();
    const response=await puter.ai.chat(AI_PROMPT+'\n\nCONTENT:\n\n'+trunc, {model:'claude-sonnet-4-6'});
    return parseAiResp(extractPuterText(response), pts);
}
async function callPuterWithImage(file, pts) {
    if (!file || !file.size) throw new Error('Image file is empty. Try a different image.');
    await ensurePuterAuth();
    const tmpName='exam_import_'+Date.now()+'.'+file.name.split('.').pop();
    let puterFile;
    try { puterFile=await puter.fs.write(tmpName,file); }
    catch(e) { throw new Error('Could not upload image to Puter: '+(e&&e.message||e)); }
    if (!puterFile || !puterFile.path) {
        throw new Error('Image upload to Puter returned no file path. Try again or use a different image.');
    }
    // If Puter reports the written file size, sanity-check it matches
    // the source. Zero-byte writes are the usual cause of the AI
    // responding with "no content was provided".
    if (typeof puterFile.size === 'number' && puterFile.size === 0) {
        try{await puter.fs.delete(puterFile.path);}catch(_){}
        throw new Error('Image upload to Puter wrote 0 bytes. Try again or use a different image.');
    }
    let response;
    try {
        response=await puter.ai.chat(
            [{role:'user',content:[{type:'file',puter_path:puterFile.path},{type:'text',text:AI_PROMPT}]}],
            {model:'claude-sonnet-4-6'}
        );
    } finally { try{await puter.fs.delete(puterFile.path);}catch(_){} }
    return parseAiResp(extractPuterText(response), pts);
}

// Expand common Philippine exam abbreviations in answers
function expandAnswer(ans) {
    if (!ans) return ans;
    const map = {
        'QL': 'QUALITATIVE', 'Q.L.': 'QUALITATIVE', 'Ql': 'QUALITATIVE', 'ql': 'QUALITATIVE',
        'QT': 'QUANTITATIVE', 'Q.T.': 'QUANTITATIVE', 'Qt': 'QUANTITATIVE', 'qt': 'QUANTITATIVE',
        'T': 'TRUE', 'F': 'FALSE',
    };
    const trimmed = ans.trim();
    return map[trimmed] || ans;
}

// ── JSON repair helpers ──────────────────────────────────────
// LLM responses sometimes break the contract in known ways. These
// helpers try, in order: (1) bare array slice, (2) object-wrapped
// array unwrap, (3) truncation recovery — walk back to the last
// complete `}` at array depth 0 and close with `]`.

// Remove a leading "<fence>[lang]" and a trailing "<fence>" using string ops
// (regexes anchored on \s*...$ backtrack super-linearly on long replies).
function _stripFenceMarkers(s, fence, langs) {
    let t = s;
    if (t.startsWith(fence)) {
        t = t.slice(fence.length);
        const lang = langs.find(l => t.slice(0, l.length).toLowerCase() === l);
        if (lang) t = t.slice(lang.length);
        t = t.trimStart();
    }
    if (t.endsWith(fence)) t = t.slice(0, -fence.length).trimEnd();
    return t;
}
function _stripFences(s) {
    const t = _stripFenceMarkers(s.trim(), '```', ['json5', 'javascript', 'json', 'js']);
    return _stripFenceMarkers(t, '~~~', ['json']).trim();
}
function _stripTrailingCommas(s) {
    return s.replace(/,(\s*[}\]])/g, '$1');
}
function _tryParseArray(s) {
    const start = s.indexOf('[');
    const end   = s.lastIndexOf(']');
    if (start === -1 || end === -1 || end <= start) return null;
    const sliced = _stripTrailingCommas(s.slice(start, end + 1));
    try { const a = JSON.parse(sliced); return Array.isArray(a) ? a : null; } catch (_) { return null; }
}
function _tryParseObjectWrapper(s) {
    let obj;
    try { obj = JSON.parse(_stripTrailingCommas(s)); } catch (_) { return null; }
    if (!obj || typeof obj !== 'object') return null;
    for (const k of ['sections','data','result','output','exam','items','questions']) {
        if (Array.isArray(obj[k])) return obj[k];
    }
    // Single-section object → wrap it.
    if (Array.isArray(obj.questions)) return [obj];
    return null;
}
// Track string/escape state one character at a time; true = char is inside a string.
function _scanJsonChar(st, c) {
    if (st.esc) { st.esc = false; return true; }
    if (c === '\\') { st.esc = true; return true; }
    if (c === '"') { st.inStr = !st.inStr; return true; }
    return st.inStr;
}
// Walk forward tracking string state and bracket depth; return the index of the
// last "}" that closed a section object (array depth 1), or -1.
function _lastClosedSectionIndex(work) {
    const st = { esc: false, inStr: false };
    let depth = 0, lastSafe = -1;
    for (let i = 0; i < work.length; i++) {
        const c = work[i];
        if (_scanJsonChar(st, c)) continue;
        if (c === '{' || c === '[') depth++;
        else if (c === '}' || c === ']') {
            depth--;
            if (depth === 1 && c === '}') lastSafe = i; // a section closed
        }
    }
    return lastSafe;
}
function _tryRepairTruncated(s) {
    const start = s.indexOf('[');
    if (start === -1) return null;
    const work = s.slice(start);
    const lastSafe = _lastClosedSectionIndex(work);
    if (lastSafe === -1) return null;
    const candidate = _stripTrailingCommas(work.slice(0, lastSafe + 1) + ']');
    try { const a = JSON.parse(candidate); return Array.isArray(a) ? a : null; } catch (_) { return null; }
}

// Detect AI "refusal" / apology responses that are technically valid
// JSON but carry no actual exam content - e.g. one section titled
// "Error" / "Apology" with a single short_answer question whose text
// is the AI explaining it couldn't read the file.
function _detectRefusal(sections) {
    if (!Array.isArray(sections) || sections.length === 0) return null;
    const PHRASES = [
        'no exam content', 'no content was provided', 'no content provided',
        'temporarily unavailable', 'file storage',
        'i apologize', 'i cannot read', 'i can\u2019t read', "i can't read",
        'i am unable', 'i\u2019m unable', "i'm unable",
        'could not extract', 'couldn\u2019t read', "couldn't read",
        'appears to be empty', 'no readable text',
        'no file', 'blank image', 'image is blank',
        'cannot access', 'unable to access',
        'no questions were found', 'no questions found'
    ];
    const matches = (txt) => {
        if (!txt) return false;
        const low = String(txt).toLowerCase();
        return PHRASES.some(p => low.indexOf(p) !== -1);
    };
    // Very strong signal: any section title hints at an error/apology.
    for (const sec of sections) {
        const title = String(sec.section_title || '').toLowerCase();
        if (title === 'error' || title === 'apology' ||
            title.startsWith('error') || title.startsWith('apology') ||
            title.indexOf('cannot') !== -1 || title.indexOf('unable') !== -1) {
            // Find a question text to surface in the error.
            const q = (sec.questions || []).find(q => q && q.question_text);
            return q ? String(q.question_text) : 'Error: ' + (sec.section_title || 'unknown');
        }
    }
    // If there is exactly 1 question and its text matches a refusal
    // phrase, that is also strong enough to surface.
    const allQuestions = sections.flatMap(s => s.questions || []);
    if (allQuestions.length === 1 && matches(allQuestions[0].question_text)) {
        return String(allQuestions[0].question_text);
    }
    // Catch the case where every question matches a refusal phrase.
    if (allQuestions.length > 0 && allQuestions.every(q => matches(q.question_text))) {
        return String(allQuestions[0].question_text);
    }
    return null;
}

function parseAiResp(raw, dp) {
    if (typeof raw !== 'string' || raw.trim().length === 0) {
        throw new Error('AI returned an empty response. Try uploading the file again.');
    }
    const s = _stripFences(raw);

    // Three repair strategies in order of fidelity.
    let arr = _tryParseArray(s);
    if (!arr) arr = _tryParseObjectWrapper(s);
    if (!arr) arr = _tryRepairTruncated(s);

    if (!arr) {
        // All strategies failed — surface the first bit of the raw
        // response so the user can see what the AI actually returned.
        const snippet = raw.length > 240 ? raw.slice(0, 240) + '… (+' + (raw.length - 240) + ' more chars)' : raw;
        throw new Error(
            'AI response could not be parsed as JSON. The model may have returned prose, ' +
            'or the response was cut off mid-answer. Try clicking Process again - if it ' +
            'keeps failing, use a shorter or clearer file.\n\nFirst part of the response:\n' + snippet
        );
    }
    if (!Array.isArray(arr)) throw new Error('AI did not return a list.');

    const VALID_TYPES=['multiple_choice','checkboxes','short_answer','paragraph'];
    return arr.map(sec=>({
        section_title: sec.section_title||sec.title||'Untitled Section',
        section_type: VALID_TYPES.includes(sec.section_type)?sec.section_type:'multiple_choice',
        section_description: sec.section_description||sec.description||'',
        questions: (Array.isArray(sec.questions)?sec.questions:[]).map(q=>({
            question_text:  q.question_text||'Untitled question',
            question_type:  VALID_TYPES.includes(q.question_type)?q.question_type:
                            (VALID_TYPES.includes(sec.section_type)?sec.section_type:'multiple_choice'),
            choices:        Array.isArray(q.choices)?q.choices:[],
            correct_index:  typeof q.correct_index==='number'?q.correct_index:0,
            correct_answer: expandAnswer(q.correct_answer)||null,
            correct_indices:Array.isArray(q.correct_indices)?q.correct_indices:[],
            points:         typeof q.points==='number'&&q.points>=0?q.points:dp,
            is_required:    1,
        }))
    })).filter(sec=>sec.questions.length>0);
}

// ── Preview ───────────────────────────────────────────────────
const AI_SECTION_COLORS = {
    multiple_choice: {bg:'#dbeafe',text:'#1d4ed8',border:'#93c5fd'},
    checkboxes:      {bg:'#d1fae5',text:'#065f46',border:'#6ee7b7'},
    short_answer:    {bg:'#fef3c7',text:'#92400e',border:'#fcd34d'},
    paragraph:       {bg:'#fce7f3',text:'#9d174d',border:'#f9a8d4'},
};

function renderAiPreview(sections) {
    const wrap=document.getElementById('ai-questions-preview'); wrap.innerHTML='';
    const CT=['multiple_choice','checkboxes','dropdown'];
    let globalQ=0;
    sections.forEach(sec=>{
        const sc=AI_SECTION_COLORS[sec.section_type]||AI_SECTION_COLORS['multiple_choice'];
        // Section header
        const secHead=document.createElement('div');
        secHead.style.cssText=`background:${sc.bg};border:1.5px solid ${sc.border};border-radius:var(--radius);padding:8px 12px;margin-top:8px`;
        let secDesc = sec.section_description ? `<div style="font-size:var(--text-xs);color:${sc.text};opacity:.75;margin-top:2px">${escHtml(sec.section_description)}</div>` : '';
        secHead.innerHTML=`<div style="display:flex;align-items:center;gap:8px"><span style="font-size:var(--text-xs);font-weight:600;color:${sc.text}">${escHtml(sec.section_title)}</span><span style="font-size:var(--text-xs);color:${sc.text};opacity:.7;margin-left:auto">${sec.section_type.replace(/_/g,' ')} · ${sec.questions.length} question${sec.questions.length!==1?'s':''}</span></div>${secDesc}`;
        wrap.appendChild(secHead);

        // Questions
        sec.questions.forEach(q=>{
            globalQ++;
            const card=document.createElement('div'); card.className='ai-q-card'; card.style.marginLeft='12px';
            let choices='';
            if(CT.includes(q.question_type)&&q.choices&&q.choices.length){
                choices='<div style="display:flex;flex-direction:column;gap:2px;margin-top:6px">'+q.choices.map((c,ci)=>{
                    const ok=q.question_type==='checkboxes'?(q.correct_indices||[]).includes(ci):ci===Number.parseInt(q.correct_index, 10);
                    const dot=ok
                        ? `<span style="width:14px;height:14px;border-radius:50%;background:var(--success);flex-shrink:0;display:inline-flex;align-items:center;justify-content:center"><i class="ph-bold ph-check" aria-hidden="true" style="font-size:var(--text-2xs);color:var(--white)"></i></span>`
                        : `<span style="width:14px;height:14px;border-radius:50%;border:1.5px solid var(--border-strong);flex-shrink:0;display:inline-block"></span>`;
                    return`<div style="display:flex;align-items:center;gap:8px;padding:var(--space-1) 0;font-size:var(--text-sm);color:${ok?'var(--success)':'var(--text-secondary)'};${ok?'font-weight:600':''}">${dot}${escHtml(c)}</div>`;
                }).join('')+'</div>';
            } else if(q.question_type==='short_answer'){
                choices=`<div style="margin-top:6px;font-size:var(--text-xs);color:var(--text-tertiary)">${q.correct_answer?'Expected: <strong style=\'color:#111827\'">'+escHtml(q.correct_answer)+'</strong>':'Short answer - reviewed manually'}</div>`;
            } else {
                choices=`<div style="margin-top:6px;font-size:var(--text-xs);color:var(--text-tertiary)">Paragraph - reviewed manually</div>`;
            }
            card.innerHTML=`<div class="ai-q-card-head"><span style="background:var(--accent);color:var(--white);border-radius:var(--radius);padding:1px 7px;font-weight:600;font-size:var(--text-xs)">Q${globalQ}</span><span style="font-size:var(--text-xs);color:var(--text-tertiary)">${q.question_type.replace(/_/g,' ')}</span><span style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary)">${q.points} pt${q.points!==1?'s':''}</span></div><div class="ai-q-card-body"><div class="ai-q-text">${escHtml(q.question_text)}</div>${choices}</div>`;
            wrap.appendChild(card);
        });
    });
}

function escHtml(s){return PLP.esc(s);}

// ── Save — creates sections first, then adds questions ────────
const AI_CHOICE_TYPES = ['multiple_choice', 'checkboxes', 'dropdown'];

// Create one section on the server. Resolves with its id, or null on any failure.
async function createAiSection(sec, examId, csrfName, csrf) {
    const fd = new FormData();
    fd.append('action', 'create_section');
    fd.append('exam_id', examId);
    fd.append('section_title', sec.section_title);
    fd.append('section_desc', sec.section_description || '');
    fd.append('section_type', sec.section_type);
    fd.append(csrfName, csrf);
    try {
        const r = await fetch(location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json();
        return d.ok ? d.section_id : null;
    } catch (_) {
        return null; // reported by the caller as "Error creating section"
    }
}

function buildAiQuestionForm(q, sectionId, examId, csrfName, csrf) {
    const fd = new FormData();
    fd.append('action', 'add_question'); fd.append('exam_id', examId); fd.append(csrfName, csrf);
    // Live-exam confirmation is asked once in saveAiQuestions(); sending this
    // flag stops the fetch wrapper in exam-manage.js prompting per question.
    fd.append('confirm_live', '1');
    fd.append('question_text', q.question_text);
    fd.append('question_type', q.question_type); fd.append('points', q.points); fd.append('is_required', '1');
    fd.append('section_id', sectionId);
    if (AI_CHOICE_TYPES.includes(q.question_type) && q.choices.length) {
        q.choices.forEach(c => fd.append('choices[]', c));
        if (q.question_type === 'checkboxes') (q.correct_indices || []).forEach(ci => fd.append('correct_indices[]', ci));
        else fd.append('correct_index', q.correct_index);
    } else if (q.question_type === 'short_answer') {
        fd.append('expected_answer', q.correct_answer || '');
    }
    return fd;
}

// Save one question. Resolves true when the server accepted it.
async function saveAiQuestion(q, sectionId, examId, csrfName, csrf) {
    try {
        const resp = await fetch(location.href, {
            method: 'POST',
            body: buildAiQuestionForm(q, sectionId, examId, csrfName, csrf),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const d = await resp.json();
        if (d.ok) return true;
        console.warn('Failed to save question:', q.question_text);
    } catch (e) {
        console.error('Save error:', e);
    }
    return false;
}

async function saveAiQuestions() {
    const btn = document.getElementById('ai-save-btn'); btn.disabled = true;
    const examId = PAGE.examId;
    const csrfInput = document.querySelector('#ai-import-modal input[type="hidden"]');
    const csrfName = csrfInput?.name || '_csrf_token', csrf = csrfInput?.value || '';
    if (!examId) { void ui.alert('Select an exam first.'); btn.disabled = false; return; }
    if (!csrf) { void ui.alert('Session expired. Refresh and try again.'); btn.disabled = false; return; }

    // One warning for the whole batch: live exam, and/or exam that already has results.
    if (PAGE.live || PAGE.frozen) {
        const ok = PAGE.live
            ? await ui.confirm(
                'Students are taking the exam right now.\n\nAdding these questions applies to them immediately and can change their score. Continue?',
                { title: 'Exam in progress', ok: 'Continue' })
            : await ui.confirm(
                'This exam already has submitted results.\n\nOnce added, questions cannot be deleted. Scores already recorded stay as they are, and the new questions will not count for students who already submitted. Students are not notified automatically, so tell them yourself if needed. Continue?',
                { title: 'Exam has results', ok: 'Continue' });
        if (!ok) { btn.disabled = false; return; }
    }

    const totalQ = aiGeneratedSections.reduce((sum, s) => sum + s.questions.length, 0);
    let saved = 0;

    for (const sec of aiGeneratedSections) {
        const sectionId = await createAiSection(sec, examId, csrfName, csrf);
        if (!sectionId) { btn.textContent = `Error creating section "${sec.section_title}"`; continue; }

        for (const q of sec.questions) {
            if (await saveAiQuestion(q, sectionId, examId, csrfName, csrf)) saved++;
            btn.textContent = `Saving… (${saved}/${totalQ})`;
        }
    }
    // Nothing saved: stay on the preview and say so instead of reloading silently.
    if (saved === 0) {
        void ui.alert('No questions could be added. Check the exam is still open for changes, then try again.');
        btn.disabled = false; btn.textContent = 'Add All to Exam';
        return;
    }
    window.location.reload();
}
