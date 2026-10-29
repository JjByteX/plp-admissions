(function () {
    const el = document.getElementById('exam-draft-clear');
    if (!el) return;
    try { localStorage.removeItem(el.dataset.key); } catch (e) {}
})();
