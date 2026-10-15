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
        if (!zone.hasAttribute('data-no-auto-click')) {
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
        const ms = parseInt(el.dataset.autoDismiss, 10) || 4000;
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
// Confirm dialogs (data-confirm attribute)
// ============================================================
function initConfirm() {
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-confirm]');
        if (!btn) return;
        const message = btn.dataset.confirm || 'Are you sure?';
        if (!confirm(message)) e.preventDefault();
    });
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
    const csrf = document.querySelector('input[name="_csrf"]')?.value || '';
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
            const max = parseInt(slider.max || String(SIZES.length - 1), 10) || SIZES.length - 1;
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
                const index = Math.max(0, Math.min(SIZES.length - 1, parseInt(slider.value, 10) || 0));
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
// pagination bar is always reserved (--height-table-footer, 40px =
// 32px bar + 8px gap) whether or not it is showing:
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
    const FALLBACK_FOOTER_HEIGHT = 40;
    // The card's 1px top and bottom border (lakbay's CARD_BORDER).
    const CARD_BORDER = 2;
    const STORAGE_KEY_PREFIX = 'plp_auto_page_size:';

    function cssVar(name, fallback) {
        const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        const n = parseInt(raw, 10);
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
        const footerH = cssVar('--height-table-footer', FALLBACK_FOOTER_HEIGHT);

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
        const currentPerPage = parseInt(root.dataset.currentPerPage || '0', 10);
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
            const reloadsSoFar = parseInt(sessionStorage.getItem(reloadCountKey) || '0', 10);
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
// SelectMenu — every <select> gets the dashboard dropdown look
//
// The native <select> stays in the DOM (hidden), so form posts, `required`,
// inline onchange="…", .value / .selectedIndex and option changes keep working.
// A button + popover menu (same look as the dashboard's date picker) is shown
// instead. Opt out with data-native on a <select>; <select multiple> and
// <select size="n"> are left alone.
// ============================================================
const SelectMenu = (() => {
    const SKIP      = 'select[multiple], select[size]:not([size="1"]), select[data-native]';
    const enhanced  = new WeakMap();           // select -> { wrap, trigger, refresh }
    const valueDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    const indexDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
    let   open      = null;                    // { sel, trigger, menu, items, hl }

    function optText(o) { return (o.label || o.textContent || '').replace(/\s+/g, ' ').trim(); }

    function enhance(sel) {
        if (enhanced.has(sel) || sel.matches(SKIP)) return;
        sel.classList.remove('dd-native');

        const cs      = getComputedStyle(sel);
        const isField = sel.classList.contains('form-control') || sel.classList.contains('form-select');
        const upper   = sel.classList.contains('form-control');

        const wrap = document.createElement('div');
        wrap.className = 'dd' + (isField ? ' dd--field' : '') + (upper ? ' dd--upper' : '');
        ['width', 'minWidth', 'maxWidth', 'flex', 'flexShrink', 'alignSelf', 'gridColumn',
         'margin', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight'].forEach(function (k) {
            if (sel.style[k]) wrap.style[k] = sel.style[k];
        });
        if (!sel.style.width) {
            if (isField) wrap.style.width = '100%';
            else if (cs.width && cs.width !== 'auto') wrap.style.width = cs.width;
        }

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'dd-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="dd-label"></span><i class="ph-bold ph-caret-down dd-caret" aria-hidden="true"></i>';
        wrap.appendChild(trigger);

        sel.classList.add('dd-native');
        sel.tabIndex = -1;
        sel.after(wrap);

        const labelEl = trigger.querySelector('.dd-label');
        function refresh() {
            const o = sel.options[sel.selectedIndex];
            labelEl.textContent = o ? optText(o) : '';
            trigger.disabled = sel.disabled;
            wrap.classList.toggle('dd--error', sel.classList.contains('error'));
            wrap.style.display = (sel.hidden || sel.style.display === 'none') ? 'none' : '';
            const aria = sel.getAttribute('aria-label'); if (aria) trigger.setAttribute('aria-label', aria);
            if (sel.title) trigger.title = sel.title;
        }
        refresh();

        // Keep the label in step when scripts assign .value / .selectedIndex directly.
        Object.defineProperty(sel, 'value', {
            configurable: true,
            get: function () { return valueDesc.get.call(this); },
            set: function (v) { valueDesc.set.call(this, v); refresh(); },
        });
        Object.defineProperty(sel, 'selectedIndex', {
            configurable: true,
            get: function () { return indexDesc.get.call(this); },
            set: function (v) { indexDesc.set.call(this, v); refresh(); },
        });

        new MutationObserver(refresh).observe(sel, {
            childList: true, subtree: true, characterData: true,
            attributes: true, attributeFilter: ['disabled', 'class', 'style', 'hidden', 'label'],
        });
        sel.addEventListener('change', refresh);
        sel.addEventListener('focus',  function () { trigger.focus(); });
        sel.addEventListener('invalid', function () { wrap.classList.add('dd--error'); trigger.focus(); });
        if (sel.form) sel.form.addEventListener('reset', function () { setTimeout(refresh, 0); });

        trigger.addEventListener('click', function () {
            if (open && open.sel === sel) closeMenu(); else openMenu(sel, trigger);
        });
        trigger.addEventListener('keydown', function (e) { onKey(e, sel, trigger); });

        enhanced.set(sel, { wrap: wrap, trigger: trigger, refresh: refresh });
    }

    function buildMenu(sel) {
        const menu = document.createElement('div');
        menu.className = 'dd-menu' + (sel.classList.contains('form-control') ? ' dd-menu--upper' : '');
        menu.setAttribute('role', 'listbox');
        const items = [];
        function addOption(o) {
            if (o.hidden) return;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'dd-item' + (o.index === sel.selectedIndex ? ' active' : '');
            b.setAttribute('role', 'option');
            b.setAttribute('aria-selected', o.index === sel.selectedIndex ? 'true' : 'false');
            b.disabled = o.disabled;
            b.dataset.index = String(o.index);
            b.textContent = optText(o) || '\u00a0';
            menu.appendChild(b);
            items.push(b);
        }
        Array.from(sel.children).forEach(function (ch) {
            if (ch.tagName === 'OPTGROUP') {
                const g = document.createElement('div');
                g.className = 'dd-group';
                g.textContent = ch.label;
                menu.appendChild(g);
                Array.from(ch.children).forEach(function (o) { if (o.tagName === 'OPTION') addOption(o); });
            } else if (ch.tagName === 'OPTION') {
                addOption(ch);
            }
        });
        return { menu: menu, items: items };
    }

    function place(menu, trigger) {
        const r      = trigger.getBoundingClientRect();
        const gap    = 4;
        menu.style.minWidth  = r.width + 'px';
        menu.style.left      = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        const below  = window.innerHeight - r.bottom - gap - 8;
        const above  = r.top - gap - 8;
        const h      = Math.min(menu.scrollHeight, 320);
        if (h > below && above > below) {
            menu.style.maxHeight = Math.min(320, above) + 'px';
            menu.style.top = Math.max(8, r.top - gap - Math.min(h, above)) + 'px';
        } else {
            menu.style.maxHeight = Math.min(320, below) + 'px';
            menu.style.top = (r.bottom + gap) + 'px';
        }
    }

    function openMenu(sel, trigger) {
        closeMenu();
        if (sel.disabled) return;
        const built = buildMenu(sel);
        document.body.appendChild(built.menu);
        place(built.menu, trigger);
        const cur = built.items.findIndex(function (b) { return b.classList.contains('active'); });
        open = { sel: sel, trigger: trigger, menu: built.menu, items: built.items, hl: cur };
        enhanced.get(sel).wrap.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        highlight(cur);
        built.menu.addEventListener('mousedown', function (e) { e.preventDefault(); });
        built.menu.addEventListener('click', function (e) {
            const b = e.target.closest('.dd-item');
            if (b && !b.disabled) choose(sel, parseInt(b.dataset.index, 10));
        });
    }

    function closeMenu() {
        if (!open) return;
        const o = open; open = null;
        o.menu.remove();
        const e = enhanced.get(o.sel);
        if (e) e.wrap.classList.remove('is-open');
        o.trigger.setAttribute('aria-expanded', 'false');
    }

    function highlight(i) {
        if (!open) return;
        open.items.forEach(function (b, k) { b.classList.toggle('hl', k === i); });
        open.hl = i;
        if (i >= 0 && open.items[i]) open.items[i].scrollIntoView({ block: 'nearest' });
    }

    function choose(sel, index) {
        const changed = index !== sel.selectedIndex;
        const e = enhanced.get(sel);
        const trigger = e && e.trigger;
        closeMenu();
        if (!changed) return;
        indexDesc.set.call(sel, index);
        if (e) e.refresh();
        sel.dispatchEvent(new Event('input',  { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        if (trigger) trigger.focus();
    }

    function step(dir) {
        if (!open) return;
        const n = open.items.length; if (!n) return;
        let i = open.hl;
        for (let k = 0; k < n; k++) {
            i = (i + dir + n) % n;
            if (!open.items[i].disabled) break;
        }
        highlight(i);
    }

    function onKey(e, sel, trigger) {
        const k = e.key;
        if (!open || open.sel !== sel) {
            if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'Enter' || k === ' ') {
                e.preventDefault(); openMenu(sel, trigger);
            }
            return;
        }
        if (k === 'ArrowDown')      { e.preventDefault(); step(1); }
        else if (k === 'ArrowUp')   { e.preventDefault(); step(-1); }
        else if (k === 'Home')      { e.preventDefault(); highlight(0); }
        else if (k === 'End')       { e.preventDefault(); highlight(open.items.length - 1); }
        else if (k === 'Enter' || k === ' ') {
            e.preventDefault();
            const b = open.items[open.hl];
            if (b && !b.disabled) choose(sel, parseInt(b.dataset.index, 10)); else closeMenu();
        }
        else if (k === 'Escape')    { e.preventDefault(); closeMenu(); }
        else if (k === 'Tab')       { closeMenu(); }
        else if (k.length === 1) {
            const c = k.toLowerCase();
            const start = open.hl + 1;
            for (let j = 0; j < open.items.length; j++) {
                const idx = (start + j) % open.items.length;
                if (open.items[idx].textContent.trim().toLowerCase().startsWith(c)) { highlight(idx); break; }
            }
        }
    }

    function scan(root) {
        if (root.nodeType !== 1) return;
        if (root.matches && root.matches('select')) enhance(root);
        if (root.querySelectorAll) root.querySelectorAll('select').forEach(enhance);
    }

    function init() {
        scan(document.body);

        // Selects added later (modals built in JS, cloned rows, …) and removed ones.
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType === 1 && !n.classList.contains('dd') && !n.classList.contains('dd-menu')) scan(n);
                });
                m.removedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) return;
                    const list = n.matches && n.matches('select') ? [n] : Array.from(n.querySelectorAll ? n.querySelectorAll('select') : []);
                    list.forEach(function (s) {
                        const e = enhanced.get(s);
                        if (e && e.wrap.isConnected && !s.isConnected) { e.wrap.remove(); enhanced.delete(s); }
                    });
                });
            });
        }).observe(document.body, { childList: true, subtree: true });

        document.addEventListener('mousedown', function (e) {
            if (open && !e.target.closest('.dd-menu') && !e.target.closest('.dd.is-open')) closeMenu();
        });
        window.addEventListener('resize', closeMenu);
        window.addEventListener('scroll', function (e) {
            if (open && !(e.target.closest && e.target.closest('.dd-menu'))) closeMenu();
        }, true);
    }

    return { init: init, scan: scan };
})();
window.SelectMenu = SelectMenu;

// ============================================================
// Bootstrap
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    Theme.init();
    FontSize.init();
    Dropdown.init();
    Sidebar.init();
    FileDropZone.init();
    initAlerts();
    initForms();
    initConfirm();
    AutoPageSize.init();
    SelectMenu.init();

    // Poll for new notifications every 30 seconds
    if (document.getElementById('notif-dropdown')) {
        setInterval(pollNotifications, 30000);
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
        var g = grid(); if (!g) return;
        var n = g.querySelectorAll(cfg.checkboxSel + ':checked').length;
        var c = document.getElementById(cfg.countId);
        if (c) c.textContent = n + ' selected';
        var btn = document.getElementById(cfg.deleteBtnId);
        if (btn) btn.disabled = (n === 0);
    }

    function cancelSelectMode() {
        var g = grid(); if (!g) return;
        g.classList.remove('is-selecting');
        g.querySelectorAll(cfg.checkboxSel).forEach(function (cb) { cb.checked = false; });
        g.querySelectorAll(cfg.cardSel).forEach(function (c) { c.classList.remove('is-selected'); });
        var btn = tBtn(); if (btn) btn.textContent = 'Select';
        var b = bar(); if (b) b.classList.remove('is-visible');
    }

    function toggleSelectMode() {
        var g = grid(); if (!g) return;
        if (g.classList.contains('is-selecting')) {
            cancelSelectMode();
        } else {
            g.classList.add('is-selecting');
            var btn = tBtn(); if (btn) btn.textContent = 'Done';
            var b = bar(); if (b) b.classList.add('is-visible');
            updateCount();
        }
    }

    function onCheckboxChange(cb) {
        var card = cb.closest(cfg.cardSel);
        if (card) card.classList.toggle('is-selected', cb.checked);
        updateCount();
    }

    function confirmBulkDelete() {
        var g = grid(); if (!g) return false;
        var ids = Array.from(g.querySelectorAll(cfg.checkboxSel + ':checked'))
            .map(function (cb) { return cb.value; });
        if (ids.length === 0) return false;
        if (!confirm(cfg.confirmMsg(ids.length))) return false;
        document.getElementById(cfg.idsInputId).value = ids.join(',');
        return true;
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
