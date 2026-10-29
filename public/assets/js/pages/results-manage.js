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
