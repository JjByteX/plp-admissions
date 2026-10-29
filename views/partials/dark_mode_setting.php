<?php
// ============================================================
// views/partials/dark_mode_setting.php
// Dark Mode row for Settings > Appearance (student, staff, admin).
// Sits under the text-size slider, so it carries its own divider.
// Uses window.Theme from app.js (saves to localStorage 'plp_theme'); the
// settings modal's parent page follows through the 'storage' event.
// Include once per page.
// ============================================================
?>
<div data-dark-mode-setting
     style="display:flex;align-items:center;justify-content:space-between;margin-top:var(--space-5);padding-top:var(--space-5);border-top:1px solid var(--border)">
    <div>
        <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Dark Mode</div>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Saved to this device</div>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" data-dark-mode-btn aria-pressed="false">
        <span data-dark-mode-label>Switch to Dark</span>
    </button>
</div>
<script src="<?= e(asset('js/dark-mode-setting.js')) ?>"></script>
