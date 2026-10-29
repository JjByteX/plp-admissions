/* ============================================================
   PLP Admission System — app.js
   Vanilla JS only. No dependencies.
   ============================================================ */

'use strict';

// ============================================================
// Theme — light / dark
// ============================================================
const Theme = (() => {
    const KEY = 'plp_theme';

    function apply(theme) {
        document.documentElement.dataset.theme = theme;
        localStorage.setItem(KEY, theme);
        // Sync toggle icons — show the icon for the mode you'll SWITCH TO
        document.querySelectorAll('[data-theme-icon]').forEach(el => {
            el.dataset.themeIcon === theme
                ? el.classList.remove('hidden')
                : el.classList.add('hidden');
        });
    }

    function init() {
        // Auth pages stay light and never touch the saved preference.
        if (window.PLP_FORCE_LIGHT) { document.documentElement.dataset.theme = 'light'; return; }
        const saved = localStorage.getItem(KEY) || 'light';
        apply(saved);
    }

    function toggle() {
        const current = document.documentElement.dataset.theme || 'light';
        apply(current === 'dark' ? 'light' : 'dark');
    }

    return { init, toggle, apply };
})();

// ============================================================
// Dropdown menus
// ============================================================
const Dropdown = (() => {
    function init() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-dropdown]');
            const insideMenu = e.target.closest('.dropdown-menu');
            const openDropdown = document.querySelector('.dropdown.open');

            if (trigger) {
                const dropdown = trigger.closest('.dropdown');
                const isOpen = dropdown.classList.contains('open');
                closeAll();
                if (!isOpen) dropdown.classList.add('open');
            } else if (!insideMenu) {
                closeAll();
            }
        });
    }

    function closeAll() {
        document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
    }

    return { init };
})();

// ============================================================
// Sidebar — collapse / expand
//
// Ported from lakbay-pasig's SidebarProvider + useSidebar
// (src/components/ui/sidebar.tsx). Same state model, same rules:
//
//   open        desktop rail: expanded (true) or icon-only (false).
//               Persisted in the `sidebar_state` cookie for 7 days. The
//               cookie, not localStorage, because layouts/app.php reads it
//               server-side to render the right state on first paint (no
//               expanded -> collapsed flash). Lakbay's cookie is named
//               "sidebar:state"; PHP-safe name here.
//   openMobile  phone drawer. Never persisted (Lakbay: same).
//   toggle()    isMobile ? flip openMobile : flip open.
//   Ctrl/Cmd+B  toggles from anywhere on the page.
//
// What React did with state, this does with attributes on #sidebar-wrapper,
// which the CSS (app.css, "SIDEBAR" section) keys off:
//   data-state="expanded|collapsed"   data-collapsible="icon" when collapsed
//   data-mobile-open="true|false"
//
// Markup hooks:
//   [data-sidebar-toggle]   any button that toggles (header collapse button,
//                           collapsed logo button, phone menu button)
//   [data-sidebar-overlay]  dim layer behind the phone drawer, click closes
//   [data-tooltip]          label shown beside a row while the rail is collapsed
//
// Breakpoint: <=768px is "mobile". Keep MOBILE_QUERY equal to the
// max-width media queries in app.css.
// ============================================================
const Sidebar = (() => {
    const COOKIE_NAME    = 'sidebar_state';
    const COOKIE_MAX_AGE = 60 * 60 * 24 * 7; // 7 days, same as Lakbay
    const SHORTCUT_KEY   = 'b';
    const MOBILE_QUERY   = '(max-width: 768px)';
    const TOOLTIP_OFFSET = 4;                 // px between row and tooltip (Radix default)

    let wrapper    = null;
    let tooltip    = null;
    let mql        = null;
    let open       = true;
    let openMobile = false;

    const isMobile = () => (mql ? mql.matches : window.innerWidth <= 768);

    function setOpen(value) {
        open = !!value;
        wrapper.dataset.state       = open ? 'expanded' : 'collapsed';
        wrapper.dataset.collapsible = open ? '' : 'icon';
        document.cookie = `${COOKIE_NAME}=${open}; path=/; max-age=${COOKIE_MAX_AGE}; samesite=lax`;
        hideTooltip();
    }

    function setOpenMobile(value) {
        openMobile = !!value;
        wrapper.dataset.mobileOpen = String(openMobile);
        document.body.classList.toggle('sidebar-drawer-open', openMobile);
        hideTooltip();
    }

    function toggle() {
        return isMobile() ? setOpenMobile(!openMobile) : setOpen(!open);
    }

    // ── Tooltip ─────────────────────────────────────────────
    // Only while the rail is collapsed on desktop (Lakbay: TooltipContent
    // hidden={state !== "collapsed" || isMobile}). One element on <body>
    // rather than a ::after, because the collapsed rail clips its overflow.
    function showTooltip(target) {
        if (isMobile() || open) return;
        const label = target.dataset.tooltip;
        if (!label) return;

        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'sidebar-tooltip';
            tooltip.setAttribute('role', 'tooltip');
            document.body.appendChild(tooltip);
        }
        tooltip.textContent = label;
        tooltip.classList.add('is-visible'); // visible first, so it can be measured

        const r = target.getBoundingClientRect();
        const t = tooltip.getBoundingClientRect();
        tooltip.style.left = `${r.right + TOOLTIP_OFFSET}px`;
        tooltip.style.top  = `${r.top + (r.height - t.height) / 2}px`;
    }

    function hideTooltip() {
        if (tooltip) tooltip.classList.remove('is-visible');
    }

    function init() {
        wrapper = document.getElementById('sidebar-wrapper');
        if (!wrapper) return; // student layout / auth pages have no sidebar

        mql  = window.matchMedia(MOBILE_QUERY);
        open = wrapper.dataset.state !== 'collapsed'; // server-rendered from the cookie

        document.addEventListener('click', (e) => {
            if (e.target.closest('[data-sidebar-toggle]'))  { toggle(); return; }
            if (e.target.closest('[data-sidebar-overlay]')) { setOpenMobile(false); }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key.toLowerCase() === SHORTCUT_KEY && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                toggle();
            } else if (e.key === 'Escape' && openMobile) {
                setOpenMobile(false);
            }
        });

        // Tooltips: delegated, so rows need no per-element wiring.
        wrapper.addEventListener('mouseover', (e) => {
            const t = e.target.closest('[data-tooltip]');
            if (t) showTooltip(t);
        });
        wrapper.addEventListener('mouseout', (e) => {
            const t = e.target.closest('[data-tooltip]');
            if (t && !t.contains(e.relatedTarget)) hideTooltip();
        });
        wrapper.addEventListener('focusin', (e) => {
            const t = e.target.closest('[data-tooltip]');
            if (t) showTooltip(t);
        });
        wrapper.addEventListener('focusout', hideTooltip);
        wrapper.addEventListener('click', hideTooltip); // e.g. opening the account menu

        // Tablet widths (769-1100px): start on the icon rail so the content has
        // room — unless the person already chose (the cookie exists). Not saved.
        const TABLET_QUERY = '(min-width: 769px) and (max-width: 1100px)';
        const hasChoice = () => document.cookie.split('; ').some((c) => c.indexOf(COOKIE_NAME + '=') === 0);
        const applyTablet = (q) => {
            if (hasChoice()) return;
            open = !q.matches;
            wrapper.dataset.state       = open ? 'expanded' : 'collapsed';
            wrapper.dataset.collapsible = open ? '' : 'icon';
        };
        const tabletMql = window.matchMedia(TABLET_QUERY);
        applyTablet(tabletMql);
        tabletMql.addEventListener('change', applyTablet);

        // Resized from a phone width up to desktop with the drawer open:
        // close it, the desktop rail takes over.
        mql.addEventListener('change', () => {
            if (!mql.matches && openMobile) setOpenMobile(false);
            hideTooltip();
        });
    }

    return { init, toggle, setOpen, setOpenMobile };
})();

// ============================================================
// File drop zone
// ============================================================
const FileDropZone = (() => {
    function initZone(zone) {
        const input = zone.querySelector('input[type="file"]');
        const label = zone.querySelector('.file-drop-label');

        if (!input) return;

        // Click zone → open file picker (skip if zone handles its own clicks)
        if (!zone.dataset.noAutoClick) {
            zone.addEventListener('click', (e) => {
                if (e.target !== input) input.click();
            });
        }

        // Keyboard accessible
        zone.setAttribute('tabindex', '0');
        zone.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                input.click();
            }
        });

        // Drag events
        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            const files = e.dataTransfer?.files;
            if (files?.length) {
                input.files = files;
                updateLabel(label, files[0]);
                // Trigger change event so inline handlers fire
                input.dispatchEvent(new Event('change'));
            }
        });

        // File selected via picker
        input.addEventListener('change', () => {
            if (input.files?.[0]) updateLabel(label, input.files[0]);
        });
    }

    function init() {
        // Support both class variants
        document.querySelectorAll('.file-drop, .file-drop-zone').forEach(zone => initZone(zone));
    }

    function updateLabel(label, file) {
        if (!label) return;
        const size = (file.size / 1024 / 1024).toFixed(2);
        label.textContent = `${file.name} (${size} MB)`;
    }

    return { init };
})();

// ============================================================
// Alert auto-dismiss
// ============================================================
function initAlerts() {
    document.querySelectorAll('[data-auto-dismiss]').forEach(el => {
        const ms = Number.parseInt(el.dataset.autoDismiss, 10) || 4000;
        setTimeout(() => {
            el.style.transition = 'opacity 0.3s ease';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 300);
        }, ms);
    });
}

// ============================================================
// Form validation helpers
// ============================================================
function initForms() {
    // Prevent double submit
    document.querySelectorAll('form[data-once]').forEach(form => {
        form.addEventListener('submit', function () {
            const btn = this.querySelector('[type="submit"]');
            if (btn) {
                btn.classList.add('loading');
                btn.disabled = true;
            }
        });
    });

    // Live required field highlight
    document.querySelectorAll('.form-input[required], .form-select[required]').forEach(input => {
        input.addEventListener('blur', () => {
            if (!input.value.trim()) {
                input.classList.add('error');
            } else {
                input.classList.remove('error');
            }
        });
        input.addEventListener('input', () => input.classList.remove('error'));
    });
}

// ============================================================
// Dialogs: ui.alert / ui.confirm / ui.prompt (replace native popups)
// Same markup and classes as #logout-modal in views/layouts/app.php.
//   await ui.alert('Saved');
//   if (await ui.confirm('Delete?', { danger: true, ok: 'Delete' })) ...
//   const text = await ui.prompt('Reason?');   // string, or null if cancelled
// Esc / backdrop / Cancel => false (confirm), null (prompt), resolves (alert).
// ponytail: one dialog at a time (queued); the dialog sits last in <body>, so it
// paints over any open modal (all share z-index 200). Esc is stopped here, so the
// global Esc handler in app.php never sees it and the modal behind stays open.
// ============================================================
const ui = (() => {
    let queue = Promise.resolve();

    function show(kind, message, o) {
        return new Promise((resolve) => {
            const prev = document.activeElement;
            const title = o.title || (kind === 'alert' ? 'Notice' : kind === 'prompt' ? 'Input' : 'Confirm');
            const wrap = document.createElement('div');
            wrap.className = 'modal-backdrop';
            wrap.dataset.uiDialog = '';
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            wrap.setAttribute('aria-labelledby', 'ui-dialog-title');
            wrap.innerHTML =
                '<div class="modal" style="max-width:400px">' +
                '<div class="modal-header"><div class="modal-title" id="ui-dialog-title"></div></div>' +
                '<div class="modal-body"><p style="font-size:var(--text-sm);color:var(--text-secondary);white-space:pre-line;margin:0"></p></div>' +
                '<div class="modal-footer"><button type="button" class="btn btn-ghost" data-ui-cancel></button>' +
                '<button type="button" class="btn" data-ui-ok></button></div></div>';
            const q = (s) => wrap.querySelector(s);
            q('.modal-title').textContent = title;
            q('p').textContent = message;
            const okBtn = q('[data-ui-ok]'), cancelBtn = q('[data-ui-cancel]');
            okBtn.textContent = o.ok || 'OK';
            okBtn.classList.add(o.danger ? 'btn-danger' : 'btn-primary');
            cancelBtn.textContent = o.cancel || 'Cancel';
            if (kind === 'alert') cancelBtn.remove();
            let input = null;
            if (kind === 'prompt') {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-input';
                input.style.marginTop = 'var(--space-3)';
                input.value = o.value || '';
                q('.modal-body').appendChild(input);
            }

            function done(ok) {
                document.removeEventListener('keydown', onKey, true);
                wrap.remove();
                if (prev && prev.focus && document.contains(prev)) prev.focus();
                resolve(kind === 'confirm' ? ok : kind === 'prompt' ? (ok ? input.value : null) : undefined);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(false); return; }
                if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') { e.preventDefault(); e.stopPropagation(); done(true); return; }
                if (e.key === 'Tab') {   // keep focus inside the dialog
                    const f = [...wrap.querySelectorAll('input,button')];
                    const first = f[0], last = f[f.length - 1];
                    if (!wrap.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
                    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }
            okBtn.addEventListener('click', () => done(true));
            cancelBtn.addEventListener('click', () => done(false));
            wrap.addEventListener('click', (e) => { if (e.target === wrap) done(false); });
            document.addEventListener('keydown', onKey, true);
            wrap.style.display = 'flex';
            document.body.appendChild(wrap);
            (input || okBtn).focus();
            if (input) input.select();
        });
    }

    // Queue: a second call waits for the first.
    const run = (kind, message, o) => {
        const p = queue.then(() => show(kind, String(message == null ? '' : message), o || {}));
        queue = p.catch(() => {});
        return p;
    };
    return {
        alert:   (m, o) => run('alert', m, o),
        confirm: (m, o) => run('confirm', m, o),
        prompt:  (m, o) => run('prompt', m, o),
    };
})();
window.ui = ui;

// ============================================================
// Confirm dialogs (data-confirm attribute)
//   <button data-confirm="Delete this?" data-confirm-danger data-confirm-ok="Delete">
//   <form data-confirm="Submit?">
// Capture phase: stops the click or submit, asks, then replays it so every
// handler (inline onclick, submit listeners, form validation) runs once, after OK.
// ============================================================
function initConfirm() {
    let replaying = null, busy = false;
    const ask = (el) => ui.confirm(el.dataset.confirm || 'Are you sure?', {
        danger: 'confirmDanger' in el.dataset,
        ok: el.dataset.confirmOk,
        title: el.dataset.confirmTitle,
    });
    document.addEventListener('click', async (e) => {
        const el = e.target.closest('[data-confirm]');
        if (!el || el.tagName === 'FORM' || el === replaying) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (busy) return;   // ignore double clicks while the dialog is open
        busy = true;
        const ok = await ask(el);
        busy = false;
        if (ok) { replaying = el; el.click(); replaying = null; }
    }, true);
    document.addEventListener('submit', async (e) => {
        const f = e.target;
        if (!f.matches || !f.matches('[data-confirm]') || f === replaying) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (busy) return;
        busy = true;
        const ok = await ask(f);
        busy = false;
        if (ok) { replaying = f; f.requestSubmit(e.submitter); replaying = null; }
    }, true);
}

// ============================================================
// Accent color injection from school_settings
// Called inline after the CSS var is loaded
// ============================================================
function setAccentColor(hex) {
    if (!hex) return;
    document.documentElement.style.setProperty('--accent', hex);

    // Derive a lighter shade (+20% lightness approximation)
    document.documentElement.style.setProperty('--accent-light', hex);
}

// ============================================================
// Countdown timer (exam page)
// ============================================================
function initExamTimer(totalSeconds, onExpire) {
    const display = document.getElementById('exam-timer');
    if (!display) return;

    let remaining = totalSeconds;

    const interval = setInterval(() => {
        remaining--;

        const m = Math.floor(remaining / 60).toString().padStart(2, '0');
        const s = (remaining % 60).toString().padStart(2, '0');
        display.textContent = `${m}:${s}`;

        if (remaining <= 300) {  // last 5 min — turn red
            display.style.color = 'var(--error)';
        }

        if (remaining <= 0) {
            clearInterval(interval);
            if (typeof onExpire === 'function') onExpire();
        }
    }, 1000);
}

// ============================================================
// Notifications
// ============================================================
function markAllRead() {
    const csrf = PLP.csrf();
    fetch(window.__baseUrl + '/api/notifications', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'action=read&_csrf=' + encodeURIComponent(csrf)
    }).then(() => {
        const badge = document.getElementById('notif-badge');
        if (badge) badge.remove();
        document.querySelectorAll('#notif-list .dropdown-item').forEach(el => {
            el.style.background = '';
            const title = el.querySelector('div');
            if (title) title.style.fontWeight = 'normal';
        });
    }).catch(() => {});
}

function pollNotifications() {
    fetch(window.__baseUrl + '/api/notifications?action=count', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        const badge = document.getElementById('notif-badge');
        if (data.unread > 0) {
            if (badge) {
                badge.textContent = data.unread > 9 ? '9+' : data.unread;
            } else {
                const btn = document.querySelector('#notif-dropdown [data-dropdown]');
                if (btn) {
                    const span = document.createElement('span');
                    span.className = 'notif-badge';
                    span.id = 'notif-badge';
                    span.textContent = data.unread > 9 ? '9+' : data.unread;
                    btn.appendChild(span);
                }
            }
        } else if (badge) {
            badge.remove();
        }
    })
    .catch(() => {});
}

// ============================================================
// Font size preference — small / medium (default) / large / xlarge.
// ============================================================
const FontSize = (() => {
    const KEY = 'plp_font_size';
    const SIZES = ['small', 'medium', 'large', 'xlarge'];
    const LABELS = {
        small: 'Small',
        medium: 'Medium',
        large: 'Large',
        xlarge: 'Extra Large',
    };
    const DEFAULT = 'medium';

    function get() {
        let v = null;
        try { v = localStorage.getItem(KEY); } catch (e) {}
        return SIZES.includes(v) ? v : DEFAULT;
    }

    function apply(size) {
        if (!SIZES.includes(size)) size = DEFAULT;
        document.documentElement.dataset.fontSize = size;
        try { localStorage.setItem(KEY, size); } catch (e) {}
        document.querySelectorAll('.font-size-btn').forEach(btn => {
            btn.setAttribute('aria-pressed', btn.dataset.size === size ? 'true' : 'false');
        });
        document.querySelectorAll('[data-font-size-slider]').forEach(slider => {
            const index = SIZES.indexOf(size);
            const max = Number.parseInt(slider.max || String(SIZES.length - 1), 10) || SIZES.length - 1;
            const pct = max === 0 ? 0 : (index / max) * 100;
            slider.value = String(index);
            slider.style.setProperty('--font-slider-progress', `${pct}%`);
        });
        document.querySelectorAll('[data-font-size-label]').forEach(label => {
            label.textContent = LABELS[size] || LABELS[DEFAULT];
        });
        if (window.AutoPageSize && AutoPageSize.recheck) AutoPageSize.recheck();
    }

    function init() {
        document.querySelectorAll('[data-font-size-slider]').forEach(slider => {
            slider.addEventListener('input', () => {
                const index = Math.max(0, Math.min(SIZES.length - 1, Number.parseInt(slider.value, 10) || 0));
                apply(SIZES[index]);
            });
        });
        apply(get());
    }

    return { init, apply, get };
})();
window.FontSize = FontSize;

// ============================================================
// Auto page size — viewport-fit pagination for server-rendered
// list tables (audit log, and any future page opting in).
//
// Ported from lakbay-pasig's src/hooks/use-auto-page-size.ts: a
// table's rows are forced to a fixed height (--height-table-row /
// --height-table-header, section 1 of app.css), so the number of
// rows that fit a bounded container can be computed from the
// container's own height alone, without measuring a live row (a
// live-row measurement breaks pagination — a new page size changes
// which row sits first on the page, that row can be a different
// height, which recomputes the page size again, flipping forever).
//
// Same math as the hook: the region measured is the WHOLE
// .auto-table-wrap (card + the pagination bar under it), and the
// pagination bar is always reserved (--height-table-footer, 44px =
// 36px bar + 8px gap) whether or not it is showing:
//
//   rows = floor((wrap - toolbar - header - pagination - 2px card border) / row)
//
// This app has no client-side router or in-memory row array —
// every list page here is a full server render with GET-based
// LIMIT/OFFSET pagination. So unlike the React original (which just
// re-slices an array already in memory), "auto" here means: measure
// on load, and if the computed size is not exactly what the server
// rendered with (?per_page=), reload with the corrected value. The
// measured size is also kept in a cookie, which the page reads on its
// next plain visit, so after the first measurement the server already
// renders the right row count and no reload happens at all. A
// ResizeObserver keeps it in sync with real window resizes after that.
const AutoPageSize = (() => {
    const MIN_ROWS = 3;
    const MAX_ROWS = 100;
    const FALLBACK_ROW_HEIGHT = 56;
    const FALLBACK_HEADER_HEIGHT = 40;
    const FALLBACK_FOOTER_HEIGHT = 44;
    // Gap between the card and the pagination bar (the wrap's 8px gap).
    const FOOTER_GAP = 8;
    // The card's 1px top and bottom border (lakbay's CARD_BORDER).
    const CARD_BORDER = 2;
    const STORAGE_KEY_PREFIX = 'plp_auto_page_size:';

    function cssVar(name, fallback) {
        const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const n = Number.parseInt(raw, 10);
        return Number.isNaN(n) ? fallback : n;
    }

    // Rows that fit = wrap height - toolbar - header - pagination bar -
    // card border, divided by the fixed row height. The toolbar is
    // measured live because its filters can wrap onto a second line.
    function computeRows(wrap, toolbar) {
        const availableH = wrap.getBoundingClientRect().height;
        const toolbarH = toolbar ? toolbar.getBoundingClientRect().height : 0;
        const rowH = cssVar('--height-table-row', FALLBACK_ROW_HEIGHT);
        const headerH = cssVar('--height-table-header', FALLBACK_HEADER_HEIGHT);
        // --height-table-footer is a calc() of the shared control height, which
        // getPropertyValue returns unresolved, so build it from its parts.
        const footerH = cssVar('--control-height', FALLBACK_FOOTER_HEIGHT - FOOTER_GAP) + FOOTER_GAP;

        const usable = availableH - toolbarH - headerH - footerH - CARD_BORDER;
        const rows = Math.floor(usable / rowH);
        return Math.max(MIN_ROWS, Math.min(MAX_ROWS, rows));
    }

    function init() {
        const root = document.querySelector('[data-auto-page-size]');
        if (!root) return;

        const toolbar = root.querySelector('.auto-table-toolbar');

        const storageKey = STORAGE_KEY_PREFIX + (root.dataset.autoPageSize || 'default');
        // Cookie name the server reads (modules/audit/log.php): the storage
        // key with everything but letters, digits and _ turned into _.
        const cookieKey = storageKey.replace(/[^a-zA-Z0-9_]/g, '_');
        const reloadCountKey = storageKey + ':reloads';
        const currentPerPage = Number.parseInt(root.dataset.currentPerPage || '0', 10);
        // Safety valve: allow a couple of auto-corrections per page load,
        // then stop — never reload forever.
        const MAX_AUTO_RELOADS = 3;

        function rememberSize(perPage) {
            document.cookie = `${cookieKey}=${perPage}; path=/; max-age=31536000; samesite=lax`;
        }

        function reloadWithPerPage(perPage) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', String(perPage));
            // Preserve pagination clicks. If the resized page number is no
            // longer valid, the server clamps it to the last available page.
            rememberSize(perPage);
            window.location.href = url.toString();
        }

        function measureAndMaybeReload() {
            const reloadsSoFar = Number.parseInt(sessionStorage.getItem(reloadCountKey) || '0', 10);
            if (reloadsSoFar >= MAX_AUTO_RELOADS) return false;

            const rows = computeRows(root, toolbar);
            // Any difference means the table is either overflowing or
            // leaving a row's worth of empty space at the bottom, so fix
            // it. The measured value is deterministic (it does not depend
            // on how many rows were rendered), so after one reload it
            // matches and this settles.
            if (rows !== currentPerPage) {
                sessionStorage.setItem(reloadCountKey, String(reloadsSoFar + 1));
                reloadWithPerPage(rows);
                return true;
            }
            rememberSize(rows);
            sessionStorage.removeItem(reloadCountKey); // settled — reset for next time
            return false;
        }

        if (measureAndMaybeReload()) return; // navigating away

        // Keep it in sync with real resizes (sidebar collapse, window
        // resize, orientation change) and with the toolbar wrapping to
        // another line — debounced.
        let resizeTimer = null;
        const observer = new ResizeObserver(() => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(measureAndMaybeReload, 250);
        });
        observer.observe(root);
        if (toolbar) observer.observe(toolbar);

        recheckFn = () => { sessionStorage.removeItem(reloadCountKey); measureAndMaybeReload(); };
    }

    let recheckFn = null;
    function recheck() { if (recheckFn) recheckFn(); }

    return { init, recheck };
})();
window.AutoPageSize = AutoPageSize;

// ============================================================
// FilterBar — toolbar forms have no Filter button.
//
// Selects and date inputs submit the form themselves (onchange). The
// search box submits on Enter. A form with no submit button only does that
// natively when it has a single text-like field, and several toolbars pair
// the search box with a date input, so Enter is handled here for all of them.
// ============================================================
const FilterBar = (() => {
    const TEXT_TYPES = ['text', 'search'];

    function init() {
        document.querySelectorAll('form.auto-table-toolbar').forEach((form) => {
            form.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' || e.isComposing) return;
                const el = e.target;
                if (!el || el.tagName !== 'INPUT' || !TEXT_TYPES.includes(el.type)) return;
                e.preventDefault();
                form.requestSubmit ? form.requestSubmit() : form.submit();
            });
        });
    }

    return { init };
})();
window.FilterBar = FilterBar;

// ============================================================
// ScrollTable — a table card that scrolls instead of paginating.
//
// Same card / toolbar / table look as the auto-paginated tables, but no
// AutoPageSize and no server paging: every row is in the page, the card is
// height-capped (.auto-table-card--scroll) and scrolls inside, and the first
// `data-limit` matching rows show with a "Show more" bar at the end of the
// scrolling body that reveals `data-batch` more each click.
//
// Markup (see modules/settings/admin_courses.php):
//   [data-scroll-table data-limit="10" data-batch="10"]   the card
//     [data-st-search]                       search input (lowercased text in
//                                            each row's data-search)
//     [data-st-filter="strand"]              select; matches the row's
//                                            data-strand (values split by |)
//     [data-st-clear]                        Clear button (start it display:none),
//                                            shown while filtering
//     tr[data-st-row]                        rows
//     [data-st-empty]                        "nothing matches" state
//     [data-st-more] [data-st-count] [data-st-more-btn]   the Show more bar
//
// Filtering is live (no Filter button). A row with .is-editing stays visible.
// Dispatch "st:refresh" on the card to re-run after changing rows.
// ============================================================
const ScrollTable = (() => {
    function setup(card) {
        const limit   = Math.max(1, Number.parseInt(card.dataset.limit || '10', 10));
        const batch   = Math.max(1, Number.parseInt(card.dataset.batch || String(limit), 10));
        const rows    = Array.from(card.querySelectorAll('tr[data-st-row]'));
        const search  = card.querySelector('[data-st-search]');
        const filters = Array.from(card.querySelectorAll('[data-st-filter]'));
        const clear   = card.querySelector('[data-st-clear]');
        const empty   = card.querySelector('[data-st-empty]');
        const more    = card.querySelector('[data-st-more]');
        const count   = card.querySelector('[data-st-count]');
        const moreBtn = card.querySelector('[data-st-more-btn]');
        let shown = limit;

        function query() { return search ? search.value.trim().toLowerCase() : ''; }

        function isFiltering() {
            return query() !== '' || filters.some((f) => f.value !== '');
        }

        function matches(row) {
            const q = query();
            if (q && !(row.dataset.search || '').includes(q)) return false;
            return filters.every((f) => {
                if (f.value === '') return true;
                const have = (row.dataset[f.dataset.stFilter] || '').split('|');
                return have.includes(f.value);
            });
        }

        function render() {
            let matched = 0;
            let visible = 0;
            let last = null;
            rows.forEach((row) => {
                const editing = row.classList.contains('is-editing');
                const ok = editing || matches(row);
                if (ok) matched++;
                const show = ok && (editing || visible < shown);
                row.hidden = !show;
                delete row.dataset.stLast;
                if (show) { visible++; last = row; }
            });
            if (last) last.dataset.stLast = '';
            if (empty) empty.hidden = rows.length === 0 || matched > 0;
            if (more) {
                more.hidden = visible >= matched;
                if (count) count.innerHTML = 'Showing <strong>' + visible + '</strong> of <strong>' + matched + '</strong>';
            }
            // .btn sets its own display, so toggle the inline style, not [hidden].
            if (clear) clear.style.display = isFiltering() ? '' : 'none';
        }

        function reset() { shown = limit; render(); }

        if (search) search.addEventListener('input', reset);
        filters.forEach((f) => f.addEventListener('change', reset));
        if (clear) {
            clear.addEventListener('click', () => {
                if (search) search.value = '';
                filters.forEach((f) => { f.value = ''; });
                reset();
            });
        }
        if (moreBtn) moreBtn.addEventListener('click', () => { shown += batch; render(); });
        card.addEventListener('st:refresh', render);

        render();
    }

    function init() {
        document.querySelectorAll('[data-scroll-table]').forEach(setup);
    }

    return { init };
})();
window.ScrollTable = ScrollTable;

// ============================================================
// SelectMenu — one dropdown list for every <select>
//
// The closed <select> keeps its place in the page (layout, width, name,
// value, form submit, onchange handlers, .value set by scripts all work
// untouched). Opening it shows the shared list panel (.app-dropdown-panel,
// the same look as the dashboard date-range menu) instead of the browser's
// native list. Choosing an item sets the select and fires input + change.
//
// Opt out per select with data-native. Multi-selects and selects with a
// size attribute stay native.
// ============================================================
const SelectMenu = (() => {
    let panel = null;       // the floating list, created on first open
    let current = null;     // the <select> it belongs to
    let entries = [];       // [{ el, option }] for the rendered items
    let active = -1;        // keyboard / hover highlight (index into entries)
    let typed = '', typedTimer = null;

    function eligible(el) {
        return !!el && el.tagName === 'SELECT' && !el.multiple
            && !el.hasAttribute('size') && !el.dataset.native;
    }

    function isOpen() { return !!panel && panel.classList.contains('open'); }

    // Keys while the list is open. Same keys and behavior as before.
    function handleOpenKey(e) {
        switch (e.key) {
            case 'ArrowDown': e.preventDefault(); move(1); return;
            case 'ArrowUp':   e.preventDefault(); move(-1); return;
            case 'Home':      e.preventDefault(); setActive(entries.findIndex((en) => !en.option.disabled), true); return;
            case 'End': {
                e.preventDefault();
                for (let i = entries.length - 1; i >= 0; i--) {
                    if (!entries[i].option.disabled) { setActive(i, true); break; }
                }
                return;
            }
            case 'Enter':
            case ' ':
                e.preventDefault();
                if (active >= 0) choose(entries[active]); else close();
                return;
            case 'Escape': e.preventDefault(); e.stopPropagation(); close(); return;
            case 'Tab': close(); return;
            default:
                if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    e.preventDefault();
                    typeahead(e.key);
                }
        }
    }

    function ensurePanel() {
        if (panel) return panel;
        panel = document.createElement('div');
        panel.className = 'app-dropdown-panel select-menu';
        panel.setAttribute('role', 'listbox');
        // Keep focus on the select while clicking inside the list.
        panel.addEventListener('mousedown', (e) => e.preventDefault());
        panel.addEventListener('click', (e) => {
            const item = e.target.closest('.app-dropdown-item');
            if (!item || item.disabled) return;
            choose(entries[+item.dataset.i]);
        });
        panel.addEventListener('mouseover', (e) => {
            const item = e.target.closest('.app-dropdown-item');
            if (item && !item.disabled) setActive(+item.dataset.i, false);
        });
        document.body.appendChild(panel);
        return panel;
    }

    function build(sel) {
        panel.textContent = '';
        entries = [];
        const addOption = (opt) => {
            if (opt.hidden) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.tabIndex = -1;
            btn.className = 'app-dropdown-item' + (opt.selected ? ' active' : '');
            btn.setAttribute('role', 'option');
            btn.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
            btn.dataset.i = String(entries.length);
            btn.disabled = opt.disabled;
            btn.textContent = opt.textContent.trim() || ' ';
            panel.appendChild(btn);
            entries.push({ el: btn, option: opt });
        };
        Array.from(sel.children).forEach((node) => {
            if (node.tagName === 'OPTGROUP') {
                const label = document.createElement('div');
                label.className = 'app-dropdown-label';
                label.textContent = node.label;
                panel.appendChild(label);
                Array.from(node.children).forEach((o) => { if (o.tagName === 'OPTION') addOption(o); });
            } else if (node.tagName === 'OPTION') {
                addOption(node);
            }
        });
    }

    function position() {
        const r = current.getBoundingClientRect();
        const margin = 8, gap = 4;
        panel.style.minWidth = r.width + 'px';
        panel.style.maxHeight = '';
        panel.style.left = '0px';
        panel.style.top = '0px';
        const pw = panel.offsetWidth;
        const ph = panel.scrollHeight;
        const below = window.innerHeight - r.bottom - margin - gap;
        const above = r.top - margin - gap;
        const openUp = ph > below && above > below;
        const room = Math.max(120, openUp ? above : below);
        const h = Math.min(ph, 288, room);
        panel.style.maxHeight = Math.min(288, room) + 'px';
        let left = r.left;
        if (left + pw > window.innerWidth - margin) left = window.innerWidth - margin - pw;
        panel.style.left = Math.max(margin, left) + 'px';
        panel.style.top = (openUp ? r.top - gap - h : r.bottom + gap) + 'px';
    }

    function open(sel) {
        if (sel.disabled) return;
        if (isOpen()) close();
        current = sel;
        ensurePanel();
        build(sel);
        panel.classList.add('open');
        position();
        const sIdx = entries.findIndex((en) => en.option.selected);
        setActive(sIdx >= 0 ? sIdx : entries.findIndex((en) => !en.option.disabled), true);
        sel.classList.add('is-open');
    }

    function close() {
        if (!panel) return;
        panel.classList.remove('open');
        if (current) current.classList.remove('is-open');
        current = null;
        active = -1;
    }

    function setActive(i, scroll) {
        if (active >= 0 && entries[active]) entries[active].el.classList.remove('is-hover');
        active = i;
        if (i >= 0 && entries[i]) {
            entries[i].el.classList.add('is-hover');
            if (scroll) entries[i].el.scrollIntoView({ block: 'nearest' });
        }
    }

    function move(step) {
        if (!entries.length) return;
        let i = active;
        for (let n = 0; n < entries.length; n++) {
            i = (i + step + entries.length) % entries.length;
            if (!entries[i].option.disabled) { setActive(i, true); return; }
        }
    }

    function choose(entry) {
        if (!entry || !current) return;
        const sel = current;
        const changed = !entry.option.selected;
        sel.selectedIndex = entry.option.index;
        close();
        sel.focus();
        if (changed) {
            sel.dispatchEvent(new Event('input',  { bubbles: true }));
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function typeahead(key) {
        typed += key.toLowerCase();
        clearTimeout(typedTimer);
        typedTimer = setTimeout(() => { typed = ''; }, 600);
        const start = typed.length === 1 ? active + 1 : active;
        for (let n = 0; n < entries.length; n++) {
            const i = (start + n + entries.length) % entries.length;
            if (!entries[i].option.disabled
                && entries[i].el.textContent.trim().toLowerCase().startsWith(typed)) {
                setActive(i, true);
                return;
            }
        }
    }

    function init() {
        // Capture phase, so this runs before any page handler and before
        // the browser opens its own list.
        document.addEventListener('mousedown', (e) => {
            const sel = e.target.closest && e.target.closest('select');
            if (eligible(sel)) {
                if (e.button !== 0) return;
                e.preventDefault();               // no native list
                sel.focus();
                if (isOpen() && current === sel) close(); else open(sel);
                return;
            }
            if (isOpen() && !panel.contains(e.target)) close();
        }, true);

        document.addEventListener('keydown', (e) => {
            if (isOpen()) { handleOpenKey(e); return; }
            const sel = e.target;
            if (eligible(sel) && !e.ctrlKey && !e.metaKey
                && (e.key === 'Enter' || e.key === ' ' || e.key === 'F4'
                    || (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp')))) {
                e.preventDefault();
                open(sel);
            }
        }, true);

        // A floating list must not drift away from its select.
        window.addEventListener('resize', close);
        window.addEventListener('scroll', (e) => {
            if (isOpen() && !(panel && panel.contains(e.target))) close();
        }, true);
        window.addEventListener('blur', close);
    }

    return { init, close };
})();
window.SelectMenu = SelectMenu;

// ============================================================
// SettingsModal — Settings in a modal
//
// The modal (views/partials/settings_modal.php) holds a section list and an
// iframe. The iframe loads the role's settings page with ?embed=1&section=<key>
// (the layout then draws only that page's content, and only that card).
// Open it from any [data-settings-open] link, or with ?settings=1 in the URL
// (settings URLs redirect to the home page with it). The embedded page tells
// us when something was saved; the page behind reloads on close so the name,
// branding and theme shown there are current.
// ============================================================
const SettingsModal = (() => {
    let modal, frame, dirty = false, section = null;

    function frameUrl(key) {
        return frame.dataset.base + '?embed=1&section=' + encodeURIComponent(key);
    }

    function show(key) {
        section = key;
        modal.querySelectorAll('[data-settings-nav]').forEach((b) => {
            const on = b.dataset.settingsNav === key;
            b.classList.toggle('active', on);
            if (on) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
        });
        frame.src = frameUrl(key);
    }

    function open(key) {
        key = key || frame.dataset.first;
        document.querySelectorAll('.dropdown.open').forEach((d) => d.classList.remove('open'));
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (section !== key || !frame.getAttribute('src')) show(key);
    }

    function isOpen() { return modal.style.display === 'flex'; }

    function close() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        if (dirty) { dirty = false; window.location.reload(); }
    }

    function init() {
        modal = document.getElementById('settings-modal');
        if (!modal) return;
        frame = document.getElementById('settings-modal-frame');

        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-settings-open]');
            if (!link) return;
            e.preventDefault();
            open();
        });
        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('[data-settings-close]')) { close(); return; }
            const nav = e.target.closest('[data-settings-nav]');
            if (nav) show(nav.dataset.settingsNav);
        });
        // Capture phase: runs before the global Escape-to-close, which would
        // hide the modal without the reload-on-save step in close().
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isOpen()) { e.stopPropagation(); close(); }
        }, true);
        window.addEventListener('message', (e) => {
            if (e.origin !== window.location.origin || e.source !== frame.contentWindow) return;
            if (e.data && e.data.type === 'settings:saved') dirty = true;
            if (e.data && e.data.type === 'settings:close' && isOpen()) close();
        });

        const params = new URLSearchParams(window.location.search);
        if (params.has('settings')) {
            params.delete('settings');
            const qs = params.toString();
            history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
            open();
        }
    }

    return { init, open, close };
})();
window.SettingsModal = SettingsModal;

// ============================================================
// Bootstrap
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    Theme.init();
    FontSize.init();
    Dropdown.init();
    SelectMenu.init();
    SettingsModal.init();
    Sidebar.init();
    FileDropZone.init();
    initAlerts();
    initForms();
    initConfirm();
    AutoPageSize.init();
    FilterBar.init();
    ScrollTable.init();

    // Poll for new notifications every 30 seconds
    if (document.getElementById('notif-dropdown')) {
        setInterval(pollNotifications, 30000);
    }
});

// The settings modal's iframe changes theme / text size in its own document.
// localStorage writes there fire 'storage' here, so the page behind follows
// (this also keeps other open tabs in step).
window.addEventListener('storage', (e) => {
    if (window.PLP_FORCE_LIGHT || !e.newValue) return;
    if (e.key === 'plp_theme') {
        document.documentElement.dataset.theme = e.newValue;
        document.querySelectorAll('.theme-pill').forEach((p) => { p.dataset.theme = e.newValue; });
    } else if (e.key === 'plp_font_size') {
        FontSize.apply(e.newValue);
    }
});

// Expose globals needed by inline scripts
window.Theme       = Theme;
window.initExamTimer = initExamTimer;
window.setAccentColor = setAccentColor;
window.markAllRead = markAllRead;

// ── Shared "select mode" widget for card-grid bulk delete ──────
// Used by exam slot cards and interview session cards: a toggle
// button puts the grid into select mode, clicking a card checks it,
// a bulk bar shows the running count, and a confirm dialog gates
// the delete submit. Each page keeps its own thin wrapper functions
// (same global names as before) so no HTML/CSS/onclick markup has
// to change; the wrappers just delegate here.
function createCardBulkSelector(cfg) {
    // cfg: { gridSel, cardSel, checkboxSel, barId, toggleBtnId,
    //        countId, deleteBtnId, idsInputId, confirmMsg(count) }
    function grid()  { return document.querySelector(cfg.gridSel); }
    function bar()   { return document.getElementById(cfg.barId); }
    function tBtn()  { return document.getElementById(cfg.toggleBtnId); }

    function updateCount() {
        const g = grid(); if (!g) return;
        const n = g.querySelectorAll(cfg.checkboxSel + ':checked').length;
        const c = document.getElementById(cfg.countId);
        if (c) c.textContent = n + ' selected';
        const btn = document.getElementById(cfg.deleteBtnId);
        if (btn) btn.disabled = (n === 0);
    }

    function cancelSelectMode() {
        const g = grid(); if (!g) return;
        g.classList.remove('is-selecting');
        g.querySelectorAll(cfg.checkboxSel).forEach(function (cb) { cb.checked = false; });
        g.querySelectorAll(cfg.cardSel).forEach(function (c) { c.classList.remove('is-selected'); });
        const btn = tBtn(); if (btn) btn.textContent = 'Select';
        const b = bar(); if (b) b.classList.remove('is-visible');
    }

    function toggleSelectMode() {
        const g = grid(); if (!g) return;
        if (g.classList.contains('is-selecting')) {
            cancelSelectMode();
        } else {
            g.classList.add('is-selecting');
            const btn = tBtn(); if (btn) btn.textContent = 'Done';
            const b = bar(); if (b) b.classList.add('is-visible');
            updateCount();
        }
    }

    function onCheckboxChange(cb) {
        const card = cb.closest(cfg.cardSel);
        if (card) card.classList.toggle('is-selected', cb.checked);
        updateCount();
    }

    // Called as onclick="return ...confirmBulkDelete(this)". The dialog is async, so the
    // first call returns false, asks, then clicks the button again with a flag set.
    function confirmBulkDelete(btn) {
        if (btn && btn.dataset.bulkOk) { delete btn.dataset.bulkOk; return true; }
        const g = grid(); if (!g) return false;
        const ids = Array.from(g.querySelectorAll(cfg.checkboxSel + ':checked'))
            .map(function (cb) { return cb.value; });
        if (ids.length === 0) return false;
        void ui.confirm(cfg.confirmMsg(ids.length), { danger: true, ok: 'Remove' }).then(function (ok) {
            if (!ok) return;
            document.getElementById(cfg.idsInputId).value = ids.join(',');
            btn.dataset.bulkOk = '1';
            btn.click();
        }).catch(function (err) { console.error(err); });
        return false;
    }

    return {
        toggleSelectMode: toggleSelectMode,
        cancelSelectMode: cancelSelectMode,
        onCheckboxChange: onCheckboxChange,
        confirmBulkDelete: confirmBulkDelete,
        updateCount: updateCount,
    };
}
window.createCardBulkSelector = createCardBulkSelector;

// ============================================================
// PLP shared utilities (used by page-level inline scripts)
// Call these lazily (from handlers); page scripts that sit above
// this file in the layout run before it has loaded.
// ============================================================
window.PLP = (function () {
    // One HTML escape for both text and attribute values.
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function csrf() {
        const el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    // POST form-encoded data with the CSRF token added. Resolves with the parsed JSON
    // body; a network or parse failure resolves { ok:false, error:'...' } instead of throwing.
    function post(url, data) {
        const params = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) {
            if (data[k] != null) params.append(k, data[k]);
        });
        if (!params.has('_csrf')) params.append('_csrf', csrf());
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: params.toString()
        }).then(function (r) { return r.json(); })
          .catch(function () { return { ok: false, error: 'Request failed. Try again.' }; });
    }

    // Memoised pdf.js loader. opts: { src, worker } (defaults to the cdnjs 3.11.174 build).
    // Resolves with window.pdfjsLib. A failed load is not cached, so a later call retries.
    let pdfPromise = null;
    function loadPdfJs(opts) {
        if (window.pdfjsLib && window.pdfjsLib.GlobalWorkerOptions && window.pdfjsLib.GlobalWorkerOptions.workerSrc) {
            return Promise.resolve(window.pdfjsLib);
        }
        if (pdfPromise) return pdfPromise;
        opts = opts || {};
        const src = opts.src || 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        const worker = opts.worker || 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        pdfPromise = new Promise(function (resolve, reject) {
            function ready() {
                if (!window.pdfjsLib) return reject(new Error('pdf.js missing'));
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = worker;
                resolve(window.pdfjsLib);
            }
            if (window.pdfjsLib) return ready();
            let s = document.createElement('script');
            s.src = src;
            s.onload = ready;
            s.onerror = function () { reject(new Error('pdf.js failed to load')); };
            document.head.appendChild(s);
        });
        pdfPromise.catch(function () { pdfPromise = null; });
        return pdfPromise;
    }

    // Strip a Markdown code fence (```json ... ```) that an AI reply may be wrapped in.
    // Plain string ops instead of regexes anchored on \s*...$ (those backtrack badly).
    function stripCodeFence(raw) {
        let s = String(raw == null ? '' : raw).trim();
        if (s.startsWith('```')) {
            s = s.slice(3);
            if (s.slice(0, 4).toLowerCase() === 'json') s = s.slice(4);
            s = s.trimStart();
        }
        if (s.endsWith('```')) s = s.slice(0, -3);
        return s.trim();
    }

    let modal = {
        open: function (id)  { document.getElementById(id).style.display = 'flex'; },
        close: function (id) { document.getElementById(id).style.display = 'none'; }
    };

    return { esc: esc, csrf: csrf, post: post, loadPdfJs: loadPdfJs, stripCodeFence: stripCodeFence, modal: modal };
})();
