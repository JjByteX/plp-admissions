// Move any element marked data-page-action (the element itself) or
// data-page-actions (its children) into the heading row, top right.
(function () {
    var slot = document.getElementById('page-head-actions');
    if (!slot) return;
    document.querySelectorAll('#main-content [data-page-action]').forEach(function (el) {
        el.removeAttribute('data-page-action');
        slot.appendChild(el);
    });
    document.querySelectorAll('#main-content [data-page-actions]').forEach(function (wrap) {
        while (wrap.firstElementChild) slot.appendChild(wrap.firstElementChild);
        wrap.remove();
    });
})();
