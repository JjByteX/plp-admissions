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

// Finalize closed exams nobody submitted (one cheap EXISTS when there is no work).
finalize_closed_exam_drafts();

// ── A4: Dashboard POST handlers ──────────────────────────────
if (($action = require_post_action()) !== null) {
    $staffId = Auth::id();

    if ($action === 'approve_all_pending_docs') {
        $db = db();
        // Same rules as the Approve Docs button on the applicants list, run
        // per applicant: AI-flagged documents are skipped until staff confirm
        // the category, and an applicant only advances to exam when every
        // required document is approved (not just the ones uploaded so far).
        $count    = 0;
        $skipped  = 0;
        $advanced = 0;
        $aids = $db->query(
            "SELECT DISTINCT applicant_id FROM documents
              WHERE status IN ('uploaded','under_review')"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($aids as $aid) {
            $aid = (int)$aid;
            $res = doc_approve_unflagged($db, $aid, $staffId);
            $count   += $res['approved'];
            $skipped += $res['skipped'];
            if (advance_to_exam_if_docs_approved($db, $aid)) {
                $advanced++;
                notify_stage_transition($aid, 'exam');
            }
        }

        // Backfill exam-slot assignments for everyone who just got
        // advanced (and anyone who'd been stuck at exam-with-no-slot
        // from a previous run). Without this, a bulk approval can
        // leave dozens of students sitting on the "Awaiting Slot
        // Assignment" page.
        $assigned = backfill_exam_slot_assignments();

        audit_log(AuditAction::BULK_APPROVE_DOCS, "Approved all pending documents ({$count} docs); {$advanced} advanced to exam, {$skipped} AI-flagged skipped; assigned exam slot to {$assigned} applicant(s)");
        Session::flash(
            'success',
            "Approved {$count} pending document(s). {$advanced} applicant(s) advanced to exam."
            . ($assigned > 0 ? " Auto-assigned exam slots to {$assigned} applicant(s)." : '')
            . ($skipped > 0 ? " {$skipped} AI-flagged document" . ($skipped != 1 ? 's were' : ' was') . ' skipped. Open the applicant to confirm the category.' : '')
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
       SUM(CASE WHEN a.overall_status IN ('submitted','exam','interview','released') THEN 1 ELSE 0 END) AS docs_submitted,
       SUM(CASE WHEN a.overall_status IN ('exam','interview','released') THEN 1 ELSE 0 END)             AS docs_approved,
       SUM(CASE WHEN EXISTS (
           SELECT 1 FROM exam_results er WHERE er.applicant_id = a.id
       ) THEN 1 ELSE 0 END)                                                  AS exam_taken,
       SUM(CASE WHEN EXISTS (
           SELECT 1 FROM interview_queue iq
            WHERE iq.applicant_id = a.id
              AND iq.interview_status IN ('pending','completed')
       ) THEN 1 ELSE 0 END)                                                  AS interviewed,
       SUM(CASE WHEN EXISTS (
           SELECT 1 FROM admission_results ar WHERE ar.applicant_id = a.id
       ) THEN 1 ELSE 0 END)                                                  AS results_released,

       /* applicant types */
       SUM(CASE WHEN a.applicant_type = 'freshman'   THEN 1 ELSE 0 END)     AS cnt_freshman,
       SUM(CASE WHEN a.applicant_type = 'transferee' THEN 1 ELSE 0 END)     AS cnt_transferee,
       SUM(CASE WHEN a.applicant_type = 'foreign'    THEN 1 ELSE 0 END)     AS cnt_foreign,

       /* result breakdown from admission_results */
       SUM(CASE WHEN (SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'accepted' THEN 1 ELSE 0 END) AS cnt_accepted,
       /* Withdrawn is tracked on applicants.overall_status, not admission_results.
          The legacy 'waitlisted' tier was retired in the role redesign, so the
          third KPI tile now shows Withdrawn - a live operational signal. */
       SUM(CASE WHEN a.overall_status = 'withdrawn' THEN 1 ELSE 0 END)       AS cnt_withdrawn,
       SUM(CASE WHEN (SELECT ar2.result FROM admission_results ar2
             WHERE ar2.applicant_id = a.id LIMIT 1) = 'rejected' THEN 1 ELSE 0 END) AS cnt_rejected
     FROM applicants a $deptWhere"
);
$statsStmt->execute($deptParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Document-status breakdown — count document rows by status.
// Joined onto applicants so the same Dean / Professor dept filter
// applied to the pipeline numbers also narrows this side card.
$docStmt = db()->prepare(
    "SELECT
        SUM(CASE WHEN d.status = 'approved'     THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN d.status = 'under_review' THEN 1 ELSE 0 END) AS under_review,
        SUM(CASE WHEN d.status = 'rejected'     THEN 1 ELSE 0 END) AS rejected
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
    ['label' => 'Declined',      'count' => (int) ($docStats['rejected']     ?? 0), 'color' => 'var(--chart-red)'],
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
            MAX(CURRENT_DATE - CAST(a.updated_at AS date)) AS max_days
     FROM applicants a
     WHERE a.overall_status NOT IN ('released','withdrawn')
       AND (CURRENT_DATE - CAST(a.updated_at AS date)) >= ?
       $deptFilter
     GROUP BY a.overall_status
     ORDER BY COUNT(*) DESC"
);
$idleStmt->execute(array_merge([$idleDays], $deptParams));
$idleSummary = $idleStmt->fetchAll(PDO::FETCH_ASSOC);
$totalIdle   = array_sum(array_column($idleSummary, 'count'));

// B6: Check if there are interview sessions today
$hasTodayStmt = db()->prepare('SELECT COUNT(*) FROM interview_slots WHERE slot_date = ? AND status = \'open\'');
$hasTodayStmt->execute([now_date()]);
$hasToday = (int)$hasTodayStmt->fetchColumn() > 0;

$activeNav = 'dashboard';
$pageTitle = 'Dashboard';
ob_start();
?>

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
        <div class="stat-label">Withdrawn</div>
        <div class="stat-value"><?= number_format((int) $stats['cnt_withdrawn']) ?></div>
        <div class="stat-badge badge-neutral"><?= pct((int) $stats['cnt_withdrawn'], $total) ?>%</div>
    </div>
</div>

<!-- ── Idle Applicant Alerts ─────────────────────────────── -->
<?php if ($totalIdle > 0): ?>
<div class="card p-4 mb-6">
    <div class="flex align-items-center gap-3 mb-3">
        <?= icon('warning-circle:fill', 18, 'color:#f97316') ?>
        <strong class="text-sm">Idle Applicants (<?= $totalIdle ?> waiting ><?= $idleDays ?> days)</strong>
    </div>
    <div class="flex flex-wrap gap-3">
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
                <div class="fw-medium"><?= e($label) ?></div>
                <div class="text-xs text-tertiary">Max <?= (int)$idle['max_days'] ?> days idle</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Actions ─ -->
<div class="card p-4 mb-6">
    <strong class="text-sm mb-3" style="display:block">Quick Actions</strong>
    <div class="flex flex-wrap gap-3">
        <?php if (Auth::role() === ROLE_ADMIN): ?>
        <form method="POST" action="<?= url('/staff/results/auto-release') ?>" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm"
                    data-confirm="Auto-release results for all eligible applicants?" data-confirm-ok="Release">
                <?= icon('ic_fluent_ribbon_star_24_regular', 14) ?>
                Auto-Release Results
            </button>
        </form>
        <?php endif; ?>
        <a href="<?= url('/staff/interviews') ?>?view=sessions" class="btn btn-sm">
            <?= icon('ic_fluent_calendar_add_24_regular', 14) ?>
            Batch Create Interviews
        </a>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_all_pending_docs">
            <button type="submit" class="btn btn-sm"
                    data-confirm="Approve all pending documents?" data-confirm-ok="Approve">
                <?= icon('ic_fluent_document_checkmark_24_regular', 14) ?>
                Approve All Pending Docs
            </button>
        </form>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reschedule_absent">
            <button type="submit" class="btn btn-sm"
                    data-confirm="Reschedule all no-show students?" data-confirm-ok="Reschedule">
                <?= icon('ic_fluent_calendar_sync_24_regular', 14) ?>
                Reschedule Absent Students
            </button>
        </form>
        <a href="<?= url('/staff/applicants') ?>?status=pending&sort_col=applied&sort_dir=asc" class="btn btn-sm">
            <?= icon('ic_fluent_clock_24_regular', 14) ?>
            View Idle Applicants
        </a>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_doc_reminders">
            <button type="submit" class="btn btn-sm"
                    data-confirm="Send reminders to students with incomplete documents?" data-confirm-ok="Send">
                <?= icon('ic_fluent_mail_24_regular', 14) ?>
                Send Doc Reminders
            </button>
        </form>
        <form method="POST" action="<?= url('/staff/dashboard') ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close_expired_sessions">
            <button type="submit" class="btn btn-sm"
                    data-confirm="Close expired sessions and mark no-shows?" data-confirm-ok="Close sessions">
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

        <h2 class="chart-title mt-6">Applicant type</h2>
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


<?php
$content = ob_get_clean();
// B6: real-time dashboard polling on interview days
if ($hasToday ?? false) {
    $pageScripts = ['js/pages/staff-dashboard.js'];
}
include VIEWS_PATH . '/layouts/app.php';
