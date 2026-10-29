(function () {
    var el = document.getElementById('exam-draft-clear');
    if (!el) return;
    try { localStorage.removeItem(el.getAttribute('data-key')); } catch (e) {}
})();
