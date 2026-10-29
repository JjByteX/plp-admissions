(function () {
    const selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.js-select-row').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
        });
    }
})();
