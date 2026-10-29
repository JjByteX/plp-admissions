<?php
// ============================================================
// views/partials/reschedule_history.php
// Collapsed "Past reschedule requests" list shown to students on the
// exam and interview pages. Shows each earlier attempt with its status
// and, when staff denied it, the reason they gave.
//
// Expects: $pastReschedules (array of reschedule rows)
// Optional: $historyExtraStyle (extra inline CSS for the <details> tag)
// ============================================================
$historyExtraStyle = $historyExtraStyle ?? '';
?>
<?php if (!empty($pastReschedules)): ?>
    <details style="margin-top:var(--space-4);<?= e($historyExtraStyle) ?>">
        <summary class="pointer text-xs text-tertiary">
            Past reschedule requests (<?= count($pastReschedules) ?>)
        </summary>
        <div class="mt-3 flex flex-column gap-2">
            <?php foreach ($pastReschedules as $h): ?>
                <div class="p-3 bg-subtle rounded text-xs">
                    <div class="flex justify-content-between gap-3 align-items-start">
                        <div class="text-tertiary">
                            <?= date('M j, Y · g:i A', strtotime($h['created_at'])) ?>
                        </div>
                        <?php
                            $sLabel = $h['status'];
                            $sClass = $sLabel === 'approved' ? 'badge-success'
                                    : ($sLabel === 'denied' ? 'badge-error' : 'badge-warning');
                        ?>
                        <span class="badge <?= $sClass ?>">
                            <?= e(ucfirst($sLabel)) ?>
                        </span>
                    </div>
                    <?php if (!empty($h['reason'])): ?>
                        <div class="mt-2 text-secondary pre-line">
                            <?= e($h['reason']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($h['status'] === 'denied' && !empty($h['deny_reason'])): ?>
                        <div class="mt-2 text-tertiary">
                            Staff: <?= e($h['deny_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>
