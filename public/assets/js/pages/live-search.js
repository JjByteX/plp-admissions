// Live search for the table toolbars: submit the GET form shortly after the
// person stops typing, then put the cursor back in the box after the reload.
(function () {
    var KEY = 'plpLiveSearchFocus';
    document.querySelectorAll('.auto-table-toolbar .auto-table-search input[name="q"]').forEach(function (input) {
        var form = input.form;
        if (!form) return;

        var wasFocused = false;
        try { wasFocused = sessionStorage.getItem(KEY) === location.pathname; sessionStorage.removeItem(KEY); } catch (e) {}
        if (wasFocused) {
            input.focus();
            var end = input.value.length;
            input.setSelectionRange(end, end);
        }

        var timer = null;
        var last = input.value;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (input.value === last) return;
                last = input.value;
                try { sessionStorage.setItem(KEY, location.pathname); } catch (e) {}
                form.submit();
            }, 400);
        });
    });
})();
