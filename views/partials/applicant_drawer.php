<?php
// ============================================================
// views/partials/applicant_drawer.php
//
// Slide-in drawer container + JS opener. Include this once per
// page that wants to show applicant detail panels. Then trigger
// the drawer with:
//
//     <a href="#" onclick="openApplicantPanel(123); return false">…</a>
//     ui_button('Open', ['attrs' => ['onclick' => 'openApplicantPanel(123)']])
//
// or by adding a `data-applicant-panel="123"` attribute to any
// element — the IIFE below auto-wires those click handlers too.
//
// The drawer fetches /api/applicant-panel?id=… (returns the
// rendered applicant_panel partial as HTML).
// ============================================================
?>
<div class="ap-drawer-backdrop" id="apDrawerBackdrop" aria-hidden="true"
     onclick="closeApplicantPanel()"></div>

<aside class="ap-drawer" id="apDrawer" aria-hidden="true" role="dialog"
       aria-labelledby="apDrawerTitle"
       data-print-url="<?= e(url('/print/admission?id=')) ?>"
       data-panel-url="<?= e(url('/api/applicant-panel')) ?>">
    <div class="ap-drawer-head">
        <span id="apDrawerTitle" class="ap-drawer-title">Applicant detail</span>
        <div>
        <?php if (Auth::isSSO() || Auth::isAdmin()): ?>
        <a id="apDrawerPrint" class="ap-drawer-close" href="#" target="_blank" rel="noopener"
           title="Print admission" aria-label="Print admission">
            <?= icon('printer', 16) ?>
        </a>
        <?php endif; ?>
        <button type="button" class="ap-drawer-close"
                onclick="closeApplicantPanel()" aria-label="Close panel">
            <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
        </button>
        </div>
    </div>
    <div class="ap-drawer-body" id="apDrawerBody">
        <div class="ap-drawer-loading">Loading…</div>
    </div>
</aside>

<script src="<?= e(asset('js/applicant-drawer.js')) ?>"></script>
