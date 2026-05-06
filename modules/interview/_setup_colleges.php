<?php
// _setup_colleges.php — College card grid for admin
// Variables: $departments, $collegeCounts
?>

<div style="display:flex;align-items:center;margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
    <div style="flex:1"></div>
</div>

<style>
    .college-grid {
        display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
        gap:var(--space-4);max-width:900px;margin:0 auto;
    }
    .college-card {
        display:flex;flex-direction:column;align-items:center;text-align:center;
        gap:var(--space-3);padding:var(--space-8) var(--space-5);
        background:var(--bg-elevated);border:1.5px solid var(--border);
        border-radius:var(--radius-lg);text-decoration:none;color:var(--text-primary);
        transition:border-color .18s,box-shadow .18s,transform .15s;cursor:pointer;
    }
    .college-card:hover {
        border-color:var(--accent);box-shadow:0 6px 20px rgba(0,0,0,.07);transform:translateY(-3px);
    }
    .college-card-icon {
        width:50px;height:50px;border-radius:var(--radius-lg);
        background:var(--accent-muted);color:var(--accent);
        display:flex;align-items:center;justify-content:center;
    }
    .college-card-name {
        font-size:var(--text-sm);font-weight:var(--weight-semibold);
        color:var(--text-primary);line-height:1.3;
    }
    .college-card-meta {
        font-size:var(--text-xs);color:var(--text-tertiary);
        display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap;justify-content:center;
    }
</style>

<?php if (empty($departments)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm)">
        No colleges / departments configured in the system.
    </div>
<?php else: ?>
    <div class="college-grid">
        <?php foreach ($departments as $dept):
            $info = $collegeCounts[$dept] ?? ['desks' => 0, 'upcoming' => 0];
        ?>
            <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($dept) ?>"
               class="college-card">
                <div class="college-card-icon">
                    <?= icon('ic_fluent_building_bank_24_regular', 24) ?>
                </div>
                <div class="college-card-name"><?= e($dept) ?></div>
                <div class="college-card-meta">
                    <?= icon('ic_fluent_library_24_regular', 12) ?>
                    <?= $info['desks'] ?> desk<?= $info['desks'] !== 1 ? 's' : '' ?>
                    &nbsp;·&nbsp;
                    <?= $info['upcoming'] ?> upcoming
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
