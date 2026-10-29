(function(){
    var form     = document.getElementById('apw-form');
    if (!form) return;
    var openEl   = document.getElementById('apw-open');
    var closeEl  = document.getElementById('apw-close');
    var deadEl   = document.getElementById('apw-deadline');
    var saveBtn  = document.getElementById('apw-save');
    var hintEl   = document.getElementById('apw-hint');

    function evaluate(){
        var o = openEl.value, c = closeEl.value, d = deadEl.value;
        deadEl.min = o || '';
        deadEl.max = c || '';
        var msg = '';
        if (!o || !c || !d) {
            msg = 'Fill in all three dates to save.';
        } else if (c <= o) {
            msg = 'Admissions close date must be after the open date.';
        } else if (d <= o) {
            msg = 'Document deadline must be after the admissions open date.';
        } else if (d > c) {
            msg = 'Document deadline must be on or before the admissions close date.';
        }
        if (msg) {
            saveBtn.disabled = true;
            hintEl.textContent = msg;
            hintEl.style.display = '';
        } else {
            saveBtn.disabled = false;
            hintEl.textContent = '';
            hintEl.style.display = 'none';
        }
    }
    ['input','change','blur'].forEach(function(evt){
        openEl .addEventListener(evt, evaluate);
        closeEl.addEventListener(evt, evaluate);
        deadEl .addEventListener(evt, evaluate);
    });
    evaluate();
})();
