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
        // Sync toggle icons
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
            const openDropdown = document.querySelector('.dropdown.open');

            if (trigger) {
                const dropdown = trigger.closest('.dropdown');
                const isOpen = dropdown.classList.contains('open');
                closeAll();
                if (!isOpen) dropdown.classList.add('open');
            } else {
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
// Mobile sidebar
// ============================================================
const Sidebar = (() => {
    let overlay = null;

    function init() {
        const toggle = document.getElementById('sidebar-toggle');
        if (!toggle) return;

        toggle.addEventListener('click', open);
    }

    function open() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;
        sidebar.classList.add('open');

        overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.3);
            z-index: 49;
            backdrop-filter: blur(2px);
        `;
        overlay.addEventListener('click', close);
        document.body.appendChild(overlay);
    }

    function close() {
        document.querySelector('.sidebar')?.classList.remove('open');
        overlay?.remove();
        overlay = null;
    }

    return { init };
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
// Bootstrap
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    Theme.init();
    Dropdown.init();
    Sidebar.init();
    FileDropZone.init();
    initAlerts();
    initForms();
    initConfirm();
});

// Expose globals needed by inline scripts
window.Theme       = Theme;
window.initExamTimer = initExamTimer;
window.setAccentColor = setAccentColor;