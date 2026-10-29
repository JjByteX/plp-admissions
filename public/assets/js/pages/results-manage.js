function openOverrideModal(appId, name, currentResult, currentRemarks) {
    document.getElementById('override-form').action = window.PAGE.resultsUrl + appId;
    document.getElementById('override-name').textContent = name;
    var resSel = document.getElementById('override-result');
    // Migrate any legacy 'waitlisted' record to a sensible default — admin must
    // re-pick Accept or Reject to save (the dropdown only offers those two).
    resSel.value = (currentResult === 'accepted' || currentResult === 'rejected') ? currentResult : 'accepted';
    document.getElementById('override-remarks').value = currentRemarks || '';
    document.getElementById('override-modal').style.display = 'flex';
}
var overrideModal = document.getElementById('override-modal');
if (overrideModal) overrideModal.addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});

// ── Course suggestion modal ────────────────────────────────────
function openSuggestModal(appId, name, alts, rank) {
    const modal = document.getElementById('suggest-modal');
    document.getElementById('suggest-name').textContent = name;
    document.getElementById('suggest-rank').textContent = rank;
    const list = document.getElementById('suggest-course-list');
    list.innerHTML = '';
    alts.forEach(function(course) {
        const li = document.createElement('label');
        li.style.cssText = 'display:flex;align-items:center;gap:var(--space-2);padding:var(--space-3) var(--space-4);border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;font-size:var(--text-sm)';
        li.innerHTML = '<input type="radio" name="suggest_course" value="' + escHtml(course) + '" style="accent-color:var(--accent)"> ' + escHtml(course);
        list.appendChild(li);
    });
    document.getElementById('suggest-form').action = window.PAGE.suggestUrl + appId;
    modal.style.display = 'flex';
}
function escHtml(str) {
    return PLP.esc(str);
}
document.getElementById('suggest-modal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
