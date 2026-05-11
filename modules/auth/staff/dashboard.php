<?php
// ============================================================
// modules/auth/staff/dashboard.php
// Staff dashboard — pipeline bars with visible left-side labels
// ============================================================
require_once ROOT_PATH . '/core/Auth.php';
Auth::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

// Professors (ROLE_STAFF) go straight to the Interview Queue, which is
// their primary workpage. Proctors go straight to Exam Slots — no
// dashboard for them.
if (Auth::role() === ROLE_STAFF) {
    redirect('/staff/interviews/queue');
}
if (Auth::role() === ROLE_PROCTOR) {
    redirect('/staff/exam/slots');
}

// ── A4: Dashboard POST handlers ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $staffId = Auth::id();

    if ($action === 'approve_all_pending_docs') {
        $db = db();
        $stmt = $db->prepare(
            "UPDATE documents SET status='approved', reviewed_by=?
             WHERE status IN ('uploaded','under_review')"
        );
        $stmt->execute([$staffId]);
        $count = $stmt->rowCount();
        // Advance applicants whose docs are all approved
        $db->query(
            "UPDATE applicants a SET a.overall_status = 'exam', a.documents_approved_at = NOW()
             WHERE a.overall_status IN ('submitted','documents','pending')
               AND NOT EXISTS (
                   SELECT 1 FROM documents d WHERE d.applicant_id = a.id AND d.status NOT IN ('approved','pending')
               )
               AND EXISTS (
                   SELECT 1 FROM documents d WHERE d.applicant_id = a.id AND d.status = 'approved'
               )"
        );

        // Backfill exam-slot assignments for everyone who just got
        // advanced (and anyone who'd been stuck at exam-with-no-slot
        // from a previous run). Without this, a bulk approval can
        // leave dozens of students sitting on the "Awaiting Slot
        // Assignment" page.
        $assigned = backfill_exam_slot_assignments();

        audit_log('bulk_approve_docs', "Approved all pending documents ({$count} docs); assigned exam slot to {$assigned} applicant(s)");
        Session::flash(
            'success',
            "Approved {$count} pending document(s)."
            . ($assigned > 0 ? " Auto-assigned exam slots to {$assigned} applicant(s)." : '')
        );
        redirect('/staff/dashboard');
    }

    if ($action === 'reschedule_absent') {
        $db = db();
        $stmt = $db->query(
            "SELECT DISTINCT iq.applicant_id
             FROM interview_queue iq
             WHERE iq.status = 'no_show'
               AND NOT EXISTS (
                   SELECT 1 FROM interview_queue iq2
                   WHERE iq2.applicant_id = iq.applicant_id
                     AND iq2.status IN ('scheduled','checked_in','in_progress','completed')
               )"
        );
        $rescheduled = 0;
        foreach ($stmt->fetchAll() as $row) {
            $result = auto_reschedule_noshow((int)$row['applicant_id'], $staffId);
            if ($result) $rescheduled++;
        }
        Session::flash('success', "Rescheduled {$rescheduled} absent student(s).");
        redirect('/staff/dashboard');
    }

    if ($action === 'send_doc_reminders') {
        $count = send_document_reminders();
        Session::flash('success', "Sent document reminders to {$count} applicant(s).");
        redirect('/staff/dashboard');
    }

    if ($action === 'close_expired_sessions') {
        $count = auto_close_expired_sessions();
        Session::flash('success', "Closed {$count} expired interview session(s).");
        redirect('/staff/dashboard');
    }
}

// All roles see global stats (no department scoping on dashboard)
$deptFilter = '';
$deptParams = [];
$deptWhere = '';

// ── Fetch summary counts ─────────────────────────────────────
$statsStmt = db()->prepare(
    "SELECT
       COUNT(*)                                                              AS total,

       /* pipeline steps — derived from overall_status + related tables */
       SUM(a.overall_status IN ('submitted','exam','interview','released'))  AS docs_submitted,
       SUM(a.overall_status IN ('exam','interview','released'))              AS docs_approved,
       SUM(EXISTS (
           SELECT 1 FROM exam_results er WHERE er.applicant_id = a.id
       ))                                                                    AS exam_taken,
       SUM(EXISTS (
           SELECT 1 FROM interview_queue iq
            WHERE iq.applicant_id = a.id
              AND iq.interview_status IN ('pending','completed')
       ))                                                                    AS interviewed,
       SUM(EXISTS (
           SELECT 1 FROM admission_results ar WHERE ar.applicant_id = a.id
       ))                                                                    AS results_released,

       /* applicant types */
       SUM(a.applicant_type = 'freshman')                                    AS cnt_freshman,
       SUM(a.applicant_type = 'transferee')                                  AS cnt_transferee,
       SUM(a.applicant_type = 'foreign')                                     AS cnt_foreign,

       /* result breakdown from admission_results */
       SUM((SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'accepted')            AS cnt_accepted,
       SUM((SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'waitlisted')          AS cnt_waitlisted,
       SUM((SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'rejected')            AS cnt_rejected
     FROM applicants a $deptWhere"
);
$statsStmt->execute($deptParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Document-status breakdown — count document rows by status.
// Joined onto applicants so the same Dean / Professor dept filter
// applied to the pipeline numbers also narrows this side card.
$docStmt = db()->prepare(
    "SELECT
        SUM(d.status = 'approved')     AS approved,
        SUM(d.status = 'under_review') AS under_review,
        SUM(d.status = 'rejected')     AS rejected
     FROM documents d
     JOIN applicants a ON a.id = d.applicant_id
     $deptWhere"
);
$docStmt->execute($deptParams);
$docStatsRow = $docStmt->fetch(PDO::FETCH_ASSOC);

$docStats = [
    'approved'     => (int)($docStatsRow['approved']     ?? 0),
    'under_review' => (int)($docStatsRow['under_review'] ?? 0),
    'rejected'     => (int)($docStatsRow['rejected']     ?? 0),
];

// Convenience helper: percentage relative to total registrants
function pct(int $part, int $total): int {
    return $total > 0 ? (int) round($part / $total * 100) : 0;
}

$total = (int) $stats['total'];

// Build pipeline rows (label, count, colour)
$pipeline = [
    ['label' => 'Registered',        'count' => $total,                          'color' => 'var(--chart-blue)'],
    ['label' => 'Docs submitted',     'count' => (int) $stats['docs_submitted'],  'color' => 'var(--chart-green)'],
    ['label' => 'Docs approved',      'count' => (int) $stats['docs_approved'],   'color' => 'var(--chart-green)'],
    ['label' => 'Exam taken',         'count' => (int) $stats['exam_taken'],      'color' => 'var(--chart-amber)'],
    ['label' => 'Interviewed',        'count' => (int) $stats['interviewed'],     'color' => 'var(--chart-purple)'],
    ['label' => 'Results released',   'count' => (int) $stats['results_released'],'color' => 'var(--chart-red)'],
];

$docPipeline = [
    ['label' => 'Approved',      'count' => (int) ($docStats['approved']     ?? 0), 'color' => 'var(--chart-lime)'],
    ['label' => 'Under review',  'count' => (int) ($docStats['under_review'] ?? 0), 'color' => 'var(--chart-amber)'],
    ['label' => 'Rejected',      'count' => (int) ($docStats['rejected']     ?? 0), 'color' => 'var(--chart-red)'],
];

$typePipeline = [
    ['label' => 'Freshman',   'count' => (int) $stats['cnt_freshman'],   'color' => 'var(--chart-blue)'],
    ['label' => 'Transferee', 'count' => (int) $stats['cnt_transferee'], 'color' => 'var(--chart-purple)'],
    ['label' => 'Foreign',    'count' => (int) $stats['cnt_foreign'],    'color' => 'var(--chart-pink)'],
];

// Idle applicant alerts — dept-scoped for Dean / Professor.
// Re-implements get_idle_summary() inline so the same viewer_course_filter()
// used elsewhere on this page narrows the alert counts too.
$idleDays = (int) school_setting('idle_applicant_days', '7');
$idleStmt = db()->prepare(
    "SELECT a.overall_status AS stage,
            COUNT(*) AS count,
            MAX(DATEDIFF(NOW(), a.updated_at)) AS max_days
     FROM applicants a
     WHERE a.overall_status NOT IN ('released','withdrawn')
       AND DATEDIFF(NOW(), a.updated_at) >= ?
       $deptFilter
     GROUP BY a.overall_status
     ORDER BY count DESC"
);
$idleStmt->execute(array_merge([$idleDays], $deptParams));
$idleSummary = $idleStmt->fetchAll(PDO::FETCH_ASSOC);
$totalIdle   = array_sum(array_column($idleSummary, 'count'));

// B6: Check if there are interview sessions today
$hasTodayStmt = db()->prepare('SELECT COUNT(*) FROM interview_slots WHERE slot_date = ? AND status = "open"');
$hasTodayStmt->execute([date('Y-m-d')]);
$hasToday = (int)$hasTodayStmt->fetchColumn() > 0;

$activeNav = 'dashboard';
$pageTitle = 'Dashboard';
ob_start();
?>

<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">
        Academic Year <?= date('Y') ?>–<?= date('Y') + 1 ?> &middot; Admission overview
    </p>
</div>

<!-- ── Stat cards ───────────────────────────────────────── -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total applicants</div>
        <div class="stat-value"><?= number_format($total) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Exam takers</div>
        <div class="stat-value"><?= number_format((int) $stats['exam_taken']) ?></div>
        <div class="stat-badge badge-blue"><?= pct((int) $stats['exam_taken'], $total) ?>%</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Accepted</div>
        <div class="stat-value"><?= number_format((int) $stats['cnt_accepted']) ?></div>
        <div class="stat-badge badge-green"><?= pct((int) $stats['cnt_accepted'], $total) ?>%</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Waitlisted</div>
        <div class="stat-value"><?= number_format((int) $stats['cnt_waitlisted']) ?></div>
        <div class="stat-badge badge-amber"><?= pct((int) $stats['cnt_waitlisted'], $total) ?>%</div>
    </div>
</div>

<!-- ── Idle Applicant Alerts ─────────────────────────────── -->
<?php if ($totalIdle > 0): ?>
<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-6)">
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-3)">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="#f97316" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <strong style="font-size:var(--text-sm)">Idle Applicants (<?= $totalIdle ?> waiting ><?= $idleDays ?> days)</strong>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
        <?php
        $stageLabels = [
            'pending' => 'Pending registration',
            'documents' => 'Waiting for document review',
            'submitted' => 'Docs submitted, awaiting review',
            'exam' => 'Waiting for exam slot',
            'interview' => 'Waiting for interview',
        ];
        foreach ($idleSummary as $idle):
            $label = $stageLabels[$idle['stage']] ?? ucfirst($idle['stage']);
        ?>
        <div class="idle-alert">
            <div class="idle-alert-count"><?= (int)$idle['count'] ?></div>
            <div>
                <div style="font-weight:var(--weight-medium)"><?= e($label) ?></div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Max <?= (int)$idle['max_days'] ?> days idle</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Actions ─ -->
<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-6)">
    <strong style="font-size:var(--text-sm);display:block;margin-bottom:var(--space-3)">Quick Actions</strong>
    <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
        <form method="POST" action="<?= url('/staff/results/auto-release') ?>" style="margin:0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm"
                    onclick="return confirm('Auto-release results for all eligible applicants based on score thresholds?')">
                <?= icon('ic_fluent_ribbon_star_24_regular', 14) ?>
                Auto-Release Results
            </button>
        </form>
        <a href="<?= url('/staff/interviews') ?>?view=sessions" class="btn btn-sm">
            <?= icon('ic_fluent_calendar_add_24_regular', 14) ?>
            Batch Create Interviews
        </a>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_all_pending_docs">
            <button type="submit" class="btn btn-sm"
                    onclick="return confirm('Approve all uploaded documents currently pending review?')">
                <?= icon('ic_fluent_document_checkmark_24_regular', 14) ?>
                Approve All Pending Docs
            </button>
        </form>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reschedule_absent">
            <button type="submit" class="btn btn-sm"
                    onclick="return confirm('Reschedule all no-show students to available interview slots?')">
                <?= icon('ic_fluent_calendar_sync_24_regular', 14) ?>
                Reschedule Absent Students
            </button>
        </form>
        <a href="<?= url('/staff/applicants') ?>?status=pending&sort_col=applied&sort_dir=asc" class="btn btn-sm">
            <?= icon('ic_fluent_clock_24_regular', 14) ?>
            View Idle Applicants
        </a>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_doc_reminders">
            <button type="submit" class="btn btn-sm"
                    onclick="return confirm('Send reminder notifications to students with incomplete documents?')">
                <?= icon('ic_fluent_mail_24_regular', 14) ?>
                Send Doc Reminders
            </button>
        </form>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close_expired_sessions">
            <button type="submit" class="btn btn-sm"
                    onclick="return confirm('Auto-close all interview sessions past their end time and mark remaining as no-shows?')">
                <?= icon('ic_fluent_calendar_cancel_24_regular', 14) ?>
                Close Expired Sessions
            </button>
        </form>
    </div>
</div>

<!-- ── Pipeline charts ────────────────────────────────── -->
<div class="dashboard-grid">

    <!-- Admission pipeline -->
    <div class="chart-card">
        <h2 class="chart-title">Admission pipeline</h2>
        <div class="bar-list">
            <?php foreach ($pipeline as $row):
                $pctVal = pct($row['count'], $total);
            ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($row['label']) ?></span>
                <div class="bar-track">
                    <div class="bar-fill"
                         style="width:<?= $pctVal ?>%; background:<?= $row['color'] ?>"></div>
                </div>
                <span class="bar-count"><?= number_format($row['count']) ?></span>
                <span class="bar-pct"><?= $pctVal ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Document status + applicant type -->
    <div class="chart-card">
        <h2 class="chart-title">Document status</h2>
        <div class="bar-list">
            <?php
            $docTotal = array_sum(array_column($docPipeline, 'count'));
            foreach ($docPipeline as $row):
                $pctVal = pct($row['count'], $docTotal ?: 1);
            ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($row['label']) ?></span>
                <div class="bar-track">
                    <div class="bar-fill"
                         style="width:<?= $pctVal ?>%; background:<?= $row['color'] ?>"></div>
                </div>
                <span class="bar-count"><?= number_format($row['count']) ?></span>
                <span class="bar-pct"><?= $pctVal ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>

        <h2 class="chart-title" style="margin-top:var(--space-6)">Applicant type</h2>
        <div class="bar-list">
            <?php foreach ($typePipeline as $row):
                $pctVal = pct($row['count'], $total);
            ?>
            <div class="bar-row">
                <span class="bar-label"><?= e($row['label']) ?></span>
                <div class="bar-track">
                    <div class="bar-fill"
                         style="width:<?= $pctVal ?>%; background:<?= $row['color'] ?>"></div>
                </div>
                <span class="bar-count"><?= number_format($row['count']) ?></span>
                <span class="bar-pct"><?= $pctVal ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- ── Step summary strip ────────────────────────────────── -->
<div class="step-strip">
    <?php
    $steps = [
        ['label' => 'Registered', 'count' => $total,                          'color' => 'var(--chart-blue)'],
        ['label' => 'Documents',  'count' => (int) $stats['docs_submitted'],  'color' => 'var(--chart-green)'],
        ['label' => 'Exam',       'count' => (int) $stats['exam_taken'],      'color' => 'var(--chart-amber)'],
        ['label' => 'Interview',  'count' => (int) $stats['interviewed'],     'color' => 'var(--chart-purple)'],
        ['label' => 'Results',    'count' => (int) $stats['results_released'],'color' => 'var(--chart-lime)'],
    ];
    foreach ($steps as $step):
        $pctVal = pct($step['count'], $total);
    ?>
    <div class="step-card">
        <div class="step-count"><?= number_format($step['count']) ?></div>
        <div class="step-card-label"><?= e($step['label']) ?></div>
        <div class="step-bar">
            <div class="step-bar-fill"
                 style="width:<?= $pctVal ?>%; background:<?= $step['color'] ?>"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- B6: Real-time dashboard polling on interview days -->
<?php if ($hasToday ?? false): ?>
<script>
(function(){
    var POLL_INTERVAL = 30000;
    var statsArea = document.querySelector('.stat-grid');
    var pipelineArea = document.querySelector('.dashboard-grid');
    if (!statsArea && !pipelineArea) return;

    function pollDashboard() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            if (xhr.status === 200) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(xhr.responseText, 'text/html');
                var newStats = doc.querySelector('.stat-grid');
                var newPipeline = doc.querySelector('.dashboard-grid');
                if (newStats && statsArea) {
                    statsArea.innerHTML = newStats.innerHTML;
                }
                if (newPipeline && pipelineArea) {
                    pipelineArea.innerHTML = newPipeline.innerHTML;
                }
            }
        };
        xhr.send();
    }

    setInterval(pollDashboard, POLL_INTERVAL);
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include VIEWS_PATH . '/layouts/app.php';
