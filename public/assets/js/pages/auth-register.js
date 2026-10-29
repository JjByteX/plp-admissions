const REG_DATA   = JSON.parse(document.getElementById('reg-data').textContent);
const strandMap  = REG_DATA.strandMap;
const allStrands = REG_DATA.allStrands;

function setSex(val) {
    document.getElementById('sex_value').value = val;
    document.querySelectorAll('.sex-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.textContent.trim() === val);
    });
}

function onTypeChange(type) {
    document.getElementById('qual-freshman').style.display   = type === 'freshman'   ? 'block' : 'none';
    document.getElementById('qual-transferee').style.display = type === 'transferee' ? 'block' : 'none';
    document.getElementById('strand-group').style.display    = type === 'freshman'   ? 'flex'  : 'none';
    if (type !== 'freshman') document.getElementById('shs_strand').value = '';
    rebuildStrandOptions(document.getElementById('course_applied').value);
}

function onCourseChange(course) {
    rebuildStrandOptions(course);
}

function rebuildStrandOptions(course) {
    const sel = document.getElementById('shs_strand');
    const prevVal = sel.value;
    sel.innerHTML = '';

    const allowedKeys = strandMap[course] || null;
    // If no course selected yet, or type isn't freshman, show placeholder only
    if (!course || document.getElementById('strand-group').style.display === 'none') {
        sel.innerHTML = '<option value="">Select…</option>';
        return;
    }

    if (allowedKeys && allowedKeys.length) {
        sel.innerHTML = '<option value="">Select…</option>';
        allowedKeys.forEach(function(key) {
            const label = allStrands[key] || key;
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = label;
            if (key === prevVal) opt.selected = true;
            sel.appendChild(opt);
        });
    } else {
        // Open to all strands
        sel.innerHTML = '<option value="">Select…</option>';
        Object.keys(allStrands).forEach(function(key) {
            const opt = document.createElement('option');
            opt.value = key;
            opt.textContent = allStrands[key];
            if (key === prevVal) opt.selected = true;
            sel.appendChild(opt);
        });
    }
}

(function() {
    const t = document.getElementById('applicant_type').value;
    const c = document.getElementById('course_applied').value;
    const savedStrand = REG_DATA.savedStrand;

    if (t) onTypeChange(t);
    if (c) rebuildStrandOptions(c);

    // Restore previously selected strand after rebuilding
    if (savedStrand) {
        const sel = document.getElementById('shs_strand');
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === savedStrand) {
                sel.options[i].selected = true;
                break;
            }
        }
    }
})();
