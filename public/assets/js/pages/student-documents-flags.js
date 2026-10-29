// 1D: save ticks with $.ajax, then refresh the slot list and submit panel without a reload.
// jQuery loads after page content, so wait for DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$) return;

    // Reusable: re-fetch this page and swap in the fresh slot list + submit panel.
    // Used by the ticks (1.18) and by the batch upload box (5.11).
    window.refreshSlotList = function () {
        return $.get(window.location.href).done(function (html) {
            var $page = $('<div>').append($.parseHTML(html));
            $('#doc-list').html($page.find('#doc-list').html());
            $('#doc-submit').html($page.find('#doc-submit').html());
        });
    };

    if (!$('#flags-form').length) return;

    function say(ok, msg) {
        $('#flags-msg').text(msg).css('color', ok ? 'var(--success)' : 'var(--error)').show();
    }

    // The Grade 12 / SHS graduate radio: remember the saved choice so a failed save can put it back.
    var $stage    = $('#flags-form input[name="flags[stage]"]');
    var savedStage = $stage.filter(':checked').val() || '';
    function putBack($el) {
        if ($el.is(':radio')) {
            $stage.each(function () { this.checked = (this.value === savedStage); });
        } else {
            $el.prop('checked', !$el.prop('checked'));
        }
    }

    $('#flags-form').on('change', 'input[type=checkbox], input[type=radio]', function () {
        var $box = $(this);
        $.ajax({
            url: $('#flags-form').attr('action') || window.location.href,
            method: 'POST',
            data: $('#flags-form').serialize(),
            dataType: 'json'
        }).done(function (res) {
            if (res.ok) {
                if ($box.is(':radio')) savedStage = $box.val();
                say(true, res.message);
                window.refreshSlotList();
            } else {
                putBack($box); // put it back
                say(false, res.message);
            }
        }).fail(function () {
            putBack($box);
            say(false, 'Could not save. Please try again.');
        });
    });
});
