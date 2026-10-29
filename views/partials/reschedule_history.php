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
        <summary style="cursor:pointer;font-size:var(--text-xs);color:var(--text-tertiary)">
            Past reschedule requests (<?= count($pastReschedules) ?>)
        </summary>
        <div style="margin-top:var(--space-3);display:flex;flex-direction:column;gap:var(--space-2)">
            <?php foreach ($pastReschedules as $h): ?>
                <div style="padding:var(--space-3);background:var(--bg-subtle);border-radius:var(--radius);font-size:var(--text-xs)">
                    <div style="display:flex;justify-content:space-between;gap:var(--space-3);align-items:flex-start">
                        <div style="color:var(--text-tertiary)">
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
                        <div style="margin-top:var(--space-2);color:var(--text-secondary);white-space:pre-line">
                            <?= e($h['reason']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($h['status'] === 'denied' && !empty($h['deny_reason'])): ?>
                        <div style="margin-top:var(--space-2);color:var(--text-tertiary)">
                            Staff: <?= e($h['deny_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>
