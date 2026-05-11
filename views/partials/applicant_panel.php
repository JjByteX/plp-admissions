<?php
// ============================================================
// views/partials/applicant_panel.php
//
// Reusable applicant detail card. Renders a self-contained block
// of HTML for one applicant — header, documents, exam, interview
// + inline evaluation editor, result, audit trail.
//
// Caller must define:
//   $applicantId   (int)  — applicants.id
// Optional:
//   $panelStandalone (bool) — if true, render only the inner sections
//                             (used by the drawer fetch endpoint).
//                             Caller is responsible for the wrapper.
//
// Read-only everywhere except the Evaluation section, which posts
// to the existing /staff/interviews/{queue_id} action endpoints
// (`complete_with_evaluation`).
// ============================================================

$panelDb = db();
$panelApplicantId = (int) ($applicantId ?? 0);

// ── Applicant + user ────────────────────────────────────────
$_aStmt = $panelDb->prepare(
    'SELECT a.*, u.name AS student_name, u.email,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            u.phone AS student_phone, u.address AS student_address,
            u.department AS student_department
       FROM applicants a JOIN users u ON u.id = a.user_id
      WHERE a.id = ?'
);
$_aStmt->execute([$panelApplicantId]);
$_app = $_aStmt->fetch();

if (!$_app):
?>
    <div class="applicant-panel-empty">
        <p>Applicant not found.</p>
    </div>
<?php
    return;
endif;

$_fullName = format_full_name($_app, $_app['student_name'] ?? '—');
$_initials = strtoupper(substr($_fullName, 0, 1));

// ── Documents ───────────────────────────────────────────────
$_required = docs_for_type($_app['applicant_type']);
$_dStmt = $panelDb->prepare('SELECT * FROM documents WHERE applicant_id = ?');
$_dStmt->execute([$panelApplicantId]);
$_docRows = array_column($_dStmt->fetchAll(), null, 'doc_type');

// ── Exam ────────────────────────────────────────────────────
$_eStmt = $panelDb->prepare(
    'SELECT er.*, e.title AS exam_title, e.passing_score
       FROM exam_results er
       LEFT JOIN exams e ON e.id = er.exam_id
      WHERE er.applicant_id = ? LIMIT 1'
);
$_eStmt->execute([$panelApplicantId]);
$_exam = $_eStmt->fetch() ?: null;

// Exam slot assignment (if any)
$_esStmt = $panelDb->prepare(
    'SELECT s.exam_date, s.slot_time, s.end_time, s.room_label
       FROM applicant_exam_slots aes
       JOIN exam_slot_schedule  s ON s.id = aes.slot_id
      WHERE aes.applicant_id = ? LIMIT 1'
);
$_esStmt->execute([$panelApplicantId]);
$_examSlot = $_esStmt->fetch() ?: null;

// Per-course pass threshold (for context)
$_psStmt = $panelDb->prepare(
    'SELECT pass_from FROM course_passing_scores WHERE course_name = ? LIMIT 1'
);
$_psStmt->execute([$_app['course_applied']]);
$_passFrom = (int) ($_psStmt->fetchColumn() ?: 4);

// ── Interview ───────────────────────────────────────────────
$_iStmt = $panelDb->prepare(
    'SELECT q.*, s.slot_date, s.slot_time, s.end_time, s.department AS slot_department,
            s.location_label, s.location_notes, s.assigned_to,
            COALESCE(au.name, cu.name) AS interviewer_name
       FROM interview_queue q
       JOIN interview_slots s ON s.id = q.slot_id
       JOIN users           cu ON cu.id = s.created_by
       LEFT JOIN users      au ON au.id = s.assigned_to
      WHERE q.applicant_id = ?
      ORDER BY q.id DESC LIMIT 1'
);
$_iStmt->execute([$panelApplicantId]);
$_int = $_iStmt->fetch() ?: null;

// ── Result ──────────────────────────────────────────────────
$_rStmt = $panelDb->prepare(
    'SELECT * FROM admission_results WHERE applicant_id = ? LIMIT 1'
);
$_rStmt->execute([$panelApplicantId]);
$_res = $_rStmt->fetch() ?: null;

// ── Audit (last 12 events for this applicant) ───────────────
$_audStmt = $panelDb->prepare(
    "SELECT * FROM audit_logs
      WHERE (entity_type = 'applicant'      AND entity_id = ?)
         OR (entity_type = 'interview_queue' AND entity_id IN (
                SELECT id FROM interview_queue WHERE applicant_id = ?))
         OR (entity_type = 'document'       AND entity_id IN (
                SELECT id FROM documents WHERE applicant_id = ?))
         OR (entity_type = 'admission_result' AND entity_id IN (
                SELECT id FROM admission_results WHERE applicant_id = ?))
      ORDER BY created_at DESC
      LIMIT 12"
);
try {
    $_audStmt->execute([$panelApplicantId, $panelApplicantId, $panelApplicantId, $panelApplicantId]);
    $_audit = $_audStmt->fetchAll();
} catch (\Throwable $e) {
    $_audit = [];
}

// Helpers (panel-scoped)
$_panelStatusColor = function (string $s): string {
    return match ($s) {
        'documents'  => 'badge-warning',
        'submitted'  => 'badge-info',
        'exam'       => 'badge-info',
        'interview'  => 'badge-primary',
        'released'   => 'badge-success',
        'withdrawn'  => 'badge-error',
        default      => 'badge-secondary',
    };
};
?>
<div class="applicant-panel">

    <!-- ─── Header ─────────────────────────────────────────── -->
    <header class="ap-header">
        <div class="ap-avatar"><?= e($_initials) ?></div>
        <div class="ap-header-meta">
            <div class="ap-name"><?= e($_fullName) ?></div>
            <div class="ap-sub">
                <span><?= e(ucfirst($_app['applicant_type'])) ?></span>
                <span class="ap-dot">·</span>
                <span><?= e($_app['course_applied']) ?></span>
            </div>
            <div class="ap-contact">
                <a href="mailto:<?= e($_app['email']) ?>"><?= e($_app['email']) ?></a>
                <?php if (!empty($_app['student_phone'])): ?>
                    <span class="ap-dot">·</span>
                    <span><?= e($_app['student_phone']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <span class="badge <?= $_panelStatusColor($_app['overall_status']) ?> ap-status-badge">
            <?= e(ucfirst(str_replace('_', ' ', $_app['overall_status']))) ?>
        </span>
    </header>

    <!-- ─── Documents ──────────────────────────────────────── -->
    <section class="ap-section">
        <div class="ap-section-title">Documents</div>
        <div class="ap-doc-list">
            <?php foreach ($_required as $slug => $label):
                $_doc    = $_docRows[$slug] ?? null;
                $_status = $_doc['status'] ?? 'pending';
                $_badge  = match ($_status) {
                    'approved'     => 'badge-approved',
                    'rejected'     => 'badge-rejected',
                    'under_review' => 'badge-review',
                    'uploaded'     => 'badge-uploaded',
                    default        => 'badge-pending',
                };
            ?>
                <div class="ap-doc-row">
                    <span class="ap-doc-label"><?= e($label) ?></span>
                    <span class="badge <?= $_badge ?>"><?= e(ucfirst(str_replace('_',' ',$_status))) ?></span>
                    <?php if ($_doc && $_doc['file_path']): ?>
                        <a href="<?= e(file_url($_doc['file_path'])) ?>" target="_blank" rel="noopener"
                           class="ap-doc-link" title="Open file">
                            <?= icon('ic_fluent_eye_show_24_regular', 14) ?>
                        </a>
                    <?php else: ?>
                        <span class="ap-muted">—</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="<?= url('/staff/applicants/' . $panelApplicantId) ?>"
           class="ap-section-link">Open full document review →</a>
    </section>

    <!-- ─── Exam ───────────────────────────────────────────── -->
    <section class="ap-section">
        <div class="ap-section-title">Entrance Exam</div>
        <?php if (!$_exam): ?>
            <div class="ap-muted">Not yet taken.</div>
            <?php if ($_examSlot): ?>
                <div class="ap-kv">
                    <div><span class="ap-k">Slot</span> <?= e(format_date($_examSlot['exam_date'])) ?>
                        · <?= e(format_time($_examSlot['slot_time'])) ?></div>
                    <?php if (!empty($_examSlot['room_label'])): ?>
                        <div><span class="ap-k">Room</span> <?= e($_examSlot['room_label']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else:
            $_pct  = $_exam['total_items'] > 0
                ? round(($_exam['score'] / $_exam['total_items']) * 100)
                : 0;
            $_rank = (int) ($_exam['rank_score'] ?? 0);
            $_passed = (int) ($_exam['passed'] ?? 0) === 1;
        ?>
            <div class="ap-kv">
                <div><span class="ap-k">Score</span>
                    <?= (int)$_exam['score'] ?> / <?= (int)$_exam['total_items'] ?>
                    <span class="ap-muted">(<?= $_pct ?>%)</span>
                </div>
                <div><span class="ap-k">Rank</span>
                    <?= $_rank ?> / 10
                    <span class="ap-muted">(course needs ≥<?= $_passFrom ?>)</span>
                </div>
                <div><span class="ap-k">Result</span>
                    <span class="badge <?= $_passed ? 'badge-success' : 'badge-error' ?>">
                        <?= $_passed ? 'Passed' : 'Did not pass' ?>
                    </span>
                </div>
                <div><span class="ap-k">Submitted</span>
                    <?= e(date('M j, Y g:i A', strtotime($_exam['submitted_at']))) ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- ─── Interview ──────────────────────────────────────── -->
    <section class="ap-section">
        <div class="ap-section-title">Interview</div>
        <?php if (!$_int): ?>
            <div class="ap-muted">No interview slot yet.</div>
        <?php else:
            $_qStatus = $_int['status'] ?? 'scheduled';
            $_isFinal = in_array($_qStatus, ['completed', 'no_show'], true);
            $_evalRes = $_int['evaluation_result'] ?? null;
        ?>
            <div class="ap-kv">
                <div><span class="ap-k">When</span>
                    <?= e(format_date($_int['slot_date'])) ?>
                    <?php if (!empty($_int['slot_time'])): ?>
                        · <?= e(format_time($_int['slot_time'])) ?>
                        <?php if (!empty($_int['end_time'])): ?>
                            – <?= e(format_time($_int['end_time'])) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($_int['location_label'])): ?>
                <div><span class="ap-k">Location</span>
                    <?= e($_int['location_label']) ?>
                    <?php if (!empty($_int['location_notes'])): ?>
                        <span class="ap-muted">· <?= e($_int['location_notes']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($_int['interviewer_name'])): ?>
                <div><span class="ap-k">Interviewer</span> <?= e($_int['interviewer_name']) ?></div>
                <?php endif; ?>
                <?php if ($_int['queue_number']): ?>
                <div><span class="ap-k">Queue #</span> <?= (int)$_int['queue_number'] ?></div>
                <?php endif; ?>
                <div><span class="ap-k">Status</span>
                    <span class="badge <?= match($_qStatus) {
                        'completed'   => 'badge-success',
                        'no_show'     => 'badge-error',
                        'in_progress' => 'badge-primary',
                        'checked_in'  => 'badge-info',
                        default       => 'badge-secondary',
                    } ?>"><?= e(ucfirst(str_replace('_',' ',$_qStatus))) ?></span>
                    <?php if ($_evalRes): ?>
                        <span class="badge <?= $_evalRes === 'pass' ? 'badge-success' : 'badge-error' ?>"
                              style="margin-left:6px"><?= e(ucfirst($_evalRes)) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ─── Edit Evaluation ─────────────────────────── -->
            <?php if ($_qStatus !== 'no_show'): ?>
            <form class="ap-eval-form"
                  method="POST"
                  action="<?= e(url('/staff/interviews/' . (int)$_int['id'])) ?>"
                  onsubmit="return apEvalConfirm(this)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="complete_with_evaluation">
                <input type="hidden" name="evaluation_result" class="ap-eval-input"
                       value="<?= e($_evalRes ?: '') ?>">

                <label class="ap-eval-label">Interview notes</label>
                <textarea name="interview_notes"
                          class="form-control ap-eval-notes"
                          rows="4"
                          placeholder="Interview notes / evaluation remarks…"><?= e($_int['interview_notes'] ?? '') ?></textarea>

                <div class="ap-eval-actions">
                    <?php if ($_isFinal && $_evalRes): ?>
                        <span class="ap-eval-hint">
                            Finalized as <strong><?= e(ucfirst($_evalRes)) ?></strong>
                            on <?= e(date('M j, g:i A', strtotime($_int['evaluated_at'] ?? 'now'))) ?>.
                            Updating will re-affirm the same decision with new notes.
                        </span>
                        <button type="submit" class="btn btn-secondary btn-sm"
                                onclick="this.form.querySelector('.ap-eval-input').value='<?= e($_evalRes) ?>'">
                            Update notes
                        </button>
                    <?php else: ?>
                        <span class="ap-eval-hint">Choose Pass or Decline to finalize.</span>
                        <button type="submit" class="btn btn-reject btn-sm"
                                onclick="this.form.querySelector('.ap-eval-input').value='reject'">
                            <?= icon('ic_fluent_dismiss_24_regular', 13) ?> Decline
                        </button>
                        <button type="submit" class="btn btn-pass btn-sm"
                                onclick="this.form.querySelector('.ap-eval-input').value='pass'">
                            <?= icon('ic_fluent_checkmark_24_regular', 13) ?> Pass
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- ─── Result ─────────────────────────────────────────── -->
    <section class="ap-section">
        <div class="ap-section-title">Admission Result</div>
        <?php if (!$_res): ?>
            <div class="ap-muted">Not released.</div>
        <?php else:
            $_resBadge = match ($_res['result']) {
                'accepted'   => 'badge-success',
                'waitlisted' => 'badge-warning',
                'rejected'   => 'badge-error',
                default      => 'badge-secondary',
            };
        ?>
            <div class="ap-kv">
                <div><span class="ap-k">Decision</span>
                    <span class="badge <?= $_resBadge ?>"><?= e(ucfirst($_res['result'])) ?></span>
                </div>
                <div><span class="ap-k">Released</span>
                    <?= e(date('M j, Y g:i A', strtotime($_res['released_at']))) ?>
                </div>
                <?php if (!empty($_res['enrollment_intent'])): ?>
                <div><span class="ap-k">Intent</span>
                    <?= e(ucfirst($_res['enrollment_intent'])) ?>
                    <?php if (!empty($_res['intent_submitted_at'])): ?>
                        <span class="ap-muted">·
                        <?= e(date('M j, g:i A', strtotime($_res['intent_submitted_at']))) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($_res['remarks'])): ?>
                <div><span class="ap-k">Remarks</span> <?= e($_res['remarks']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ─── Audit trail ────────────────────────────────────── -->
    <section class="ap-section">
        <div class="ap-section-title">Recent Activity</div>
        <?php if (empty($_audit)): ?>
            <div class="ap-muted">No recorded activity.</div>
        <?php else: ?>
            <ul class="ap-audit">
                <?php foreach ($_audit as $_a): ?>
                <li>
                    <div class="ap-audit-when">
                        <?= e(date('M j, g:i A', strtotime($_a['created_at']))) ?>
                    </div>
                    <div class="ap-audit-text">
                        <strong><?= e(ucwords(str_replace('_', ' ', $_a['action']))) ?></strong>
                        <?php if (!empty($_a['description'])): ?>
                            <span class="ap-muted"> — <?= e($_a['description']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($_a['user_name'])): ?>
                            <div class="ap-audit-by">by <?= e($_a['user_name']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

</div>
