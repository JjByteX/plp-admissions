setAccentColor(document.body.dataset.accent || '');

// Auth UI (jQuery): show-password toggle, confirm-password check, loading label.
jQuery(function ($) {
    const eye = '<i class="ph-bold ph-eye" style="font-size:var(--text-xl)" aria-hidden="true"></i>';

    // One show/hide password toggle for every auth page.
    $('input[type="password"]').each(function () {
        const $in = $(this);
        if ($in.parent().hasClass('auth-pw')) return;
        const $wrap = $('<div class="auth-pw"></div>');
        $in.wrap($wrap);
        $('<button type="button" class="auth-pw-eye" aria-label="Show password"></button>')
            .html(eye)
            .insertAfter($in)
            .on('click', function () {
                const show = $in.attr('type') === 'password';
                $in.attr('type', show ? 'text' : 'password');
                $(this).toggleClass('on', show).attr('aria-label', show ? 'Hide password' : 'Show password');
                $(this).find('i').toggleClass('ph-eye', !show).toggleClass('ph-eye-slash', show);
            });
    });

    // Confirm password must match (client hint only; server still validates).
    const $pw = $('#password'), $cf = $('#password_confirm');
    if ($pw.length && $cf.length) {
        $cf.on('input blur', function () {
            const bad = $cf.val() !== '' && $cf.val() !== $pw.val();
            $cf.toggleClass('error', bad);
        });
    }

    // Live email check on blur (shows the red message before submit).
    // Plain string checks (a single-regex email test backtracks super-linearly):
    // no whitespace, exactly one "@" with text before it, and a "." inside the domain.
    function isValidEmail(v) {
        if (/\s/.test(v)) return false;
        const at = v.indexOf('@');
        if (at < 1 || at !== v.lastIndexOf('@')) return false;
        const domain = v.slice(at + 1);
        const dot = domain.indexOf('.', 1);
        return dot !== -1 && dot < domain.length - 1;
    }
    $('input[type="email"]').on('blur input', function (ev) {
        const $in = $(this), v = $.trim($in.val());
        if (ev.type === 'input' && !$in.hasClass('error')) return;
        const bad = v !== '' && !isValidEmail(v);
        $in.siblings('.form-error').remove();
        $in.toggleClass('error', bad);
        if (bad) $('<span class="form-error">Enter a valid email address.</span>').insertAfter($in);
    });

    // Loading label on submit (app.js already disables the button).
    $('form[data-once]').on('submit', function () {
        const $b = $(this).find('[type="submit"]').first();
        if ($b.length && !$b.data('label')) $b.data('label', $b.text()).attr('aria-busy', 'true');
    });
});
