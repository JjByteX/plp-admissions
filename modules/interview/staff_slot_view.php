<?php
// ============================================================
// modules/interview/staff_slot_view.php
//
// Slot detail page — shows the students assigned to this slot
// and lets staff mark attendance + record Pass/Reject evaluations
// in a single submission.
//
// The roster is auto-paginated like the audit log (fixed-height rows,
// search + status filter fused into the table card, pagination bar
// under it). Paging is client-side here on purpose: ONE form holds
// every row, so Save and the "everyone present needs a Pass/Decline"
// check still cover the whole roster whichever page is showing.
//
// URL:
//   GET  /staff/interviews/{id}/roster
//   POST /staff/interviews/{id}/roster   (action=save_evaluations)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::user()['role'] ?? '';
$slotId  = (int)($_GET['id'] ?? 0);

if ($slotId <= 0) { redirect('/staff/interviews'); }

// ----------------------------------------------------------------
// Load slot + ownership check.
// After the desk/session merge the interviewer + location both live on
// the slot itself (assigned_to + location_label), so no JOIN to a
// separate desks table is needed.
// ----------------------------------------------------------------
$stmt = $db->prepare(
    "SELECT s.*,
            COALESCE(au.name, cu.name)                           AS staff_name,
            COALESCE(NULLIF(s.location_label, ''), cu.desk_label) AS desk_label
       FROM interview_slots s
       JOIN users           cu ON cu.id = s.created_by
       LEFT JOIN users      au ON au.id = s.assigned_to
      WHERE s.id = ?
      LIMIT 1"
);
$stmt->execute([$slotId]);
$slot = $stmt->fetch();
if (!$slot) {
    Session::flash('error', 'Slot not found.');
    redirect('/staff/interviews');
}

$isAdmin   = ($role === ROLE_ADMIN);
$isSSO     = ($role === ROLE_SSO);
$isDean    = ($role === ROLE_DEAN);
// Admin and SSO can open any roster. Dean can open any roster whose
// slot belongs to their own department. Professor must be the
// session owner.
$ownerId   = (int)($slot['assigned_to'] ?? 0) ?: (int)$slot['created_by'];
$isOwner   = ($ownerId === $staffId);
$slotDept  = (string)($slot['department'] ?? '');
$staffDept = (string) user_department($staffId);
$canView   = $isAdmin
           || $isSSO
           || ($isDean && $staffDept !== '' && $slotDept === $staffDept)
           || $isOwner;
// Only the session owner (Professor) and Admin can save evaluations.
// SSO and Dean view rosters in read-only mode.
$canEvaluate = $isAdmin || ($isOwner && !$isSSO && !$isDean);

if (!$canView) {
    Session::flash('error', 'You can only view rosters for sessions in your scope.');
    redirect('/staff/interviews');
}

$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST — save evaluations
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if (!$canEvaluate) {
        Session::flash('error', 'Read-only access — only the session interviewer can record evaluations.');
        redirect('/staff/interviews/' . $slotId . '/roster');
    }

    if ($action === 'save_evaluations') {
        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows)) { $rows = []; }

        // Validate first, so we don't leave the batch half-saved.
        $toSave = [];
        foreach ($rows as $queueIdStr => $rowData) {
            $queueId = (int)$queueIdStr;
            if ($queueId <= 0) continue;

            $absent = !empty($rowData['absent']);
            $result = isset($rowData['result']) ? (string)$rowData['result'] : '';
            $result = strtolower(trim($result));

            if (!$absent) {
                if ($result !== 'pass' && $result !== 'reject') {
                    $errors[] = 'Every present student needs a Pass/Decline evaluation.';
                    $toSave = [];
                    break;
                }
            } else {
                $result = '';
            }

            $toSave[] = [
                'queue_id' => $queueId,
                'absent'   => $absent,
                'result'   => $result ?: null,
            ];
        }

        if (empty($errors) && !empty($toSave)) {
            $saved = 0;
            foreach ($toSave as $item) {
                $ok = record_interview_evaluation(
                    $item['queue_id'],
                    (bool)$item['absent'],
                    $item['result'],
                    $staffId
                );
                if ($ok) $saved++;
            }
            if ($saved > 0) {
                Session::flash('success', "Saved attendance + evaluation for {$saved} student(s).");
            } else {
                Session::flash('error', 'No evaluations were saved.');
            }
            redirect('/staff/interviews/' . $slotId . '/roster');
        }
        if (empty($errors) && empty($toSave)) {
            $errors[] = 'Nothing to save — no students on this slot.';
        }
    }
}

// ----------------------------------------------------------------
// Roster — everyone assigned to this slot
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT q.id          AS queue_id,
            q.status,
            q.attendance_status,
            q.evaluation_result,
            q.interview_status,
            q.evaluated_at,
            a.id           AS applicant_id,
            a.course_applied,
            u.name         AS student_name,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            u.email        AS student_email,
            u.department   AS student_department
       FROM interview_queue q
       JOIN applicants a ON a.id = q.applicant_id
       JOIN users u      ON u.id = a.user_id
      WHERE q.slot_id = ?
      ORDER BY u.last_name ASC, u.first_name ASC, u.name ASC'
);
$stmt->execute([$slotId]);
$roster = $stmt->fetchAll();

// ----------------------------------------------------------------
// Render
// ----------------------------------------------------------------
$timeLabel = 'All day';
if ($slot['slot_time']) {
    $timeLabel = format_time($slot['slot_time']);
    if ($slot['end_time']) {
        $timeLabel .= ' – ' . format_time($slot['end_time']);
    }
}

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($s) ?></div>
<?php endforeach; ?>

<!-- Breadcrumb -->
<div style="margin-bottom:var(--space-4);font-size:var(--text-sm);flex-shrink:0">
    <a href="<?= url('/staff/interviews') ?>" style="color:var(--text-secondary)">
        ← Back to sessions
    </a>
</div>

<!-- Slot summary -->
<div class="card" style="padding:var(--space-5);margin-bottom:var(--space-5);flex-shrink:0">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-3)">
        <div>
            <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg);margin-bottom:2px">
                <?= format_date($slot['slot_date'], 'l, F j, Y') ?> &nbsp;·&nbsp; <?= e($timeLabel) ?>
            </div>
            <div style="font-size:var(--text-sm);color:var(--text-tertiary)">
                <?= e($slot['department'] ?: 'Any department') ?>
                &nbsp;·&nbsp; Staff: <?= e($slot['staff_name']) ?>
                <?php if ($slot['desk_label']): ?>
                    &nbsp;·&nbsp; <?= e($slot['desk_label']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:var(--space-2)">
            <span class="badge <?= $slot['status'] === 'open' ? 'badge-approved' : 'badge-neutral' ?>">
                <?= e(ucfirst($slot['status'])) ?>
            </span>
            <span class="badge badge-neutral">
                <?= count($roster) ?> / <?= (int)$slot['capacity'] ?> booked
            </span>
        </div>
    </div>
</div>

<?php if (empty($roster)): ?>
    <div class="auto-table-wrap">
        <div class="auto-table-card">
            <div class="auto-table-body">
                <div class="auto-table-empty">
                    <?= icon('ic_fluent_people_24_regular', 32) ?>
                    <div>No students have been assigned to this slot yet.</div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php
    // Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
    // .auto-table-wrap, the card holding the toolbar fused to the fixed-height
    // table, and the pagination bar under the card. The wrap IS the form, so
    // the Save button lives in the toolbar and every row — on any page — is
    // submitted together. The pager script at the bottom works out how many
    // rows fit (same math as AutoPageSize in app.js) and shows one page of
    // them; the other rows stay in the DOM, just hidden, so they still submit.
    ?>
    <form method="POST" id="eval-form" class="auto-table-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_evaluations">

        <div class="auto-table-card">

            <!-- Toolbar: search + status filter + save, one row, inside the card.
                 No name= on the controls — they are not part of the submission. -->
            <div class="auto-table-toolbar" id="sv-toolbar">
                <div class="auto-table-search">
                    <?= icon('ic_fluent_search_24_filled', 14) ?>
                    <input type="text" id="sv-search" class="form-input" placeholder="Search by name, course or department…" autocomplete="off">
                </div>

                <select id="sv-status" class="form-input" style="width:170px" title="Filter by status">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="absent">Absent</option>
                    <option value="rescheduled">Rescheduled</option>
                </select>

                <button type="button" id="sv-clear" class="btn btn-ghost btn-sm" style="display:none">Clear</button>

                <span class="toolbar-spacer"></span>

                <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">Cancel</a>
                <?php if ($canEvaluate): ?>
                    <button type="submit" class="btn btn-primary btn-sm">Save evaluations</button>
                <?php else: ?>
                    <span class="badge badge-neutral" style="font-size:var(--text-xs)"
                          title="Read-only — only the session interviewer can record evaluations">
                        Read-only
                    </span>
                <?php endif; ?>
            </div>

            <!-- Table body — sized so the rows the pager picks fit exactly; it
                 only scrolls as a safety net -->
            <div class="auto-table-body">
                <table class="auto-table" id="sv-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th style="width:150px">Department</th>
                            <th style="width:80px;text-align:center">Absent</th>
                            <th style="width:190px">Evaluation</th>
                            <th style="width:130px">Current status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roster as $row):
                        $isLocked = in_array($row['interview_status'], ['completed','absent','rescheduled'], true);
                        $absentChecked = ($row['attendance_status'] ?? '') === 'absent';
                        $evalPass      = ($row['evaluation_result'] ?? '') === 'pass';
                        $evalReject    = ($row['evaluation_result'] ?? '') === 'reject';
                        $fullName      = format_full_name($row);
                        $rowStatus     = in_array($row['interview_status'], ['completed','absent','rescheduled'], true)
                                       ? $row['interview_status'] : 'pending';
                        $searchHay     = mb_strtolower($fullName . ' ' . ($row['course_applied'] ?? '') . ' ' . ($row['student_department'] ?? ''));
                    ?>
                        <tr data-queue-id="<?= (int)$row['queue_id'] ?>"
                            data-student="<?= e($fullName) ?>"
                            data-search="<?= e($searchHay) ?>"
                            data-status="<?= e($rowStatus) ?>">
                            <td style="font-size:var(--text-sm)">
                                <?php // Single-line row — email shows as tooltip on hover. ?>
                                <span class="auto-table-clip" style="font-weight:var(--weight-medium)"
                                      title="<?= e($row['student_email']) ?>"><?= e($fullName) ?></span>
                            </td>
                            <td style="font-size:var(--text-sm)" title="<?= e($row['course_applied'] ?: '') ?>">
                                <span class="auto-table-clip"><?= e($row['course_applied'] ?: '—') ?></span>
                            </td>
                            <td style="font-size:var(--text-sm)" title="<?= e($row['student_department'] ?: '') ?>">
                                <span class="auto-table-clip"><?= e($row['student_department'] ?: '—') ?></span>
                            </td>
                            <td style="text-align:center">
                                <label style="display:inline-flex;align-items:center;cursor:<?= $isLocked ? 'default' : 'pointer' ?>">
                                    <input type="checkbox"
                                           name="rows[<?= (int)$row['queue_id'] ?>][absent]"
                                           value="1"
                                           class="js-absent-toggle"
                                           <?= $absentChecked ? 'checked' : '' ?>
                                           <?= $isLocked ? 'disabled' : '' ?>>
                                </label>
                            </td>
                            <td style="font-size:var(--text-sm)">
                                <div style="display:flex;gap:var(--space-3)">
                                    <label style="display:inline-flex;align-items:center;gap:var(--space-1);cursor:pointer">
                                        <input type="radio"
                                               name="rows[<?= (int)$row['queue_id'] ?>][result]"
                                               value="pass"
                                               class="js-result-radio"
                                               <?= $evalPass ? 'checked' : '' ?>
                                               <?= ($isLocked || $absentChecked) ? 'disabled' : '' ?>>
                                        Pass
                                    </label>
                                    <label style="display:inline-flex;align-items:center;gap:var(--space-1);cursor:pointer">
                                        <input type="radio"
                                               name="rows[<?= (int)$row['queue_id'] ?>][result]"
                                               value="reject"
                                               class="js-result-radio"
                                               <?= $evalReject ? 'checked' : '' ?>
                                               <?= ($isLocked || $absentChecked) ? 'disabled' : '' ?>>
                                        Decline
                                    </label>
                                </div>
                            </td>
                            <td>
                                <?php if ($row['interview_status'] === 'completed'): ?>
                                    <?php // Eval result lives in the adjacent column;
                                          // showing "(Pass)" here would just repeat it. ?>
                                    <span class="badge badge-approved">Completed</span>
                                <?php elseif ($row['interview_status'] === 'absent'): ?>
                                    <span class="badge badge-rejected">Absent</span>
                                <?php elseif ($row['interview_status'] === 'rescheduled'): ?>
                                    <span class="badge badge-neutral">Rescheduled</span>
                                <?php else: ?>
                                    <span class="badge badge-review">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="auto-table-empty" id="sv-empty" style="display:none">
                    <?= icon('ic_fluent_people_24_regular', 32) ?>
                    <div>No students match your filters.</div>
                </div>
            </div>
        </div><!-- /.auto-table-card -->

        <!-- Pagination bar — under the card, inside the wrap. Built by the pager
             script; hidden while everything fits on one page. -->
        <div class="auto-table-pagination" id="sv-pagination" style="display:none">
            <p class="auto-table-pagination-info" id="sv-info" style="margin:0"></p>
            <div class="auto-table-pagination-controls">
                <button type="button" class="auto-table-page-btn" id="sv-prev" aria-label="Previous page">
                    <?= icon('ic_fluent_chevron_left_24_regular', 16) ?>
                </button>
                <span style="padding:0 var(--space-1);font-size:var(--text-sm);color:var(--text-tertiary);white-space:nowrap">
                    Page <strong id="sv-page" style="color:var(--text-primary);font-weight:var(--weight-semibold)">1</strong>
                    of <strong id="sv-pages" style="color:var(--text-primary);font-weight:var(--weight-semibold)">1</strong>
                </span>
                <button type="button" class="auto-table-page-btn" id="sv-next" aria-label="Next page">
                    <?= icon('ic_fluent_chevron_right_24_regular', 16) ?>
                </button>
            </div>
        </div>
    </form>

    <script>
        // When the "Absent" checkbox is toggled on:
        //   - uncheck + disable the Pass/Reject radios on that row
        // When toggled off:
        //   - re-enable the radios (staff will still need to pick one)
        document.querySelectorAll('.js-absent-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                const row = cb.closest('tr');
                row.querySelectorAll('.js-result-radio').forEach(function (r) {
                    if (cb.checked) {
                        r.checked = false;
                        r.disabled = true;
                    } else {
                        r.disabled = false;
                    }
                });
            });
        });

        // ------------------------------------------------------------
        // Pager — search + status filter + auto page size, all client-side.
        // Same math as AutoPageSize (app.js): rows are a fixed height
        // (--height-table-row / --height-table-header), so the number that
        // fit is worked out from the wrap's height alone:
        //   rows = floor((wrap - toolbar - header - footer - 2px border) / row)
        // Rows that are filtered out or on another page are display:none,
        // never removed, so the form still submits every one of them.
        // ------------------------------------------------------------
        (function () {
            var wrap      = document.getElementById('eval-form');
            var toolbar   = document.getElementById('sv-toolbar');
            var searchEl  = document.getElementById('sv-search');
            var statusEl  = document.getElementById('sv-status');
            var clearEl   = document.getElementById('sv-clear');
            var emptyEl   = document.getElementById('sv-empty');
            var pagerEl   = document.getElementById('sv-pagination');
            var infoEl    = document.getElementById('sv-info');
            var pageEl    = document.getElementById('sv-page');
            var pagesEl   = document.getElementById('sv-pages');
            var prevEl    = document.getElementById('sv-prev');
            var nextEl    = document.getElementById('sv-next');
            var allRows   = Array.prototype.slice.call(document.querySelectorAll('#sv-table tbody tr'));
            var page      = 1;
            var perPage   = 10;

            function cssPx(name, fallback) {
                var raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                var n = parseInt(raw, 10);
                return isNaN(n) ? fallback : n;
            }

            function computePerPage() {
                var avail   = wrap.getBoundingClientRect().height;
                var toolH   = toolbar.getBoundingClientRect().height;
                var rowH    = cssPx('--height-table-row', 56);
                var headerH = cssPx('--height-table-header', 40);
                var footerH = cssPx('--height-table-footer', 40);
                var rows    = Math.floor((avail - toolH - headerH - footerH - 2) / rowH);
                return Math.max(3, Math.min(100, rows));
            }

            function matching() {
                var term   = searchEl.value.toLowerCase().trim();
                var status = statusEl.value;
                return allRows.filter(function (tr) {
                    return (!term   || (tr.dataset.search || '').indexOf(term) !== -1)
                        && (!status || tr.dataset.status === status);
                });
            }

            function render() {
                var list  = matching();
                var total = list.length;
                var pages = Math.max(1, Math.ceil(total / perPage));
                if (page > pages) page = pages;
                if (page < 1) page = 1;
                var from = (page - 1) * perPage;
                var to   = Math.min(from + perPage, total);

                var shown = {};
                list.slice(from, to).forEach(function (tr) { shown[tr.dataset.queueId] = true; });
                allRows.forEach(function (tr) {
                    tr.style.display = shown[tr.dataset.queueId] ? '' : 'none';
                });

                emptyEl.style.display = total === 0 ? '' : 'none';
                clearEl.style.display = (searchEl.value.trim() || statusEl.value) ? '' : 'none';

                // Bar only when there is more than one page; its 40px is
                // reserved in computePerPage() either way.
                pagerEl.style.display = pages > 1 ? '' : 'none';
                infoEl.innerHTML = 'Showing <strong>' + (total ? from + 1 : 0) + '–' + to
                                 + '</strong> of <strong>' + total + '</strong> students';
                pageEl.textContent  = page;
                pagesEl.textContent = pages;
                prevEl.setAttribute('aria-disabled', page > 1 ? 'false' : 'true');
                nextEl.setAttribute('aria-disabled', page < pages ? 'false' : 'true');
            }

            // Re-measure. Keeps the first row that was on screen on screen, so a
            // resize does not throw the reader to a different part of the list.
            function remeasure() {
                var next = computePerPage();
                if (next === perPage) return;
                var firstIndex = (page - 1) * perPage;
                perPage = next;
                page = Math.floor(firstIndex / perPage) + 1;
                render();
            }

            function go(p) { page = p; render(); }
            prevEl.addEventListener('click', function () { if (page > 1) go(page - 1); });
            nextEl.addEventListener('click', function () {
                if (page < Math.ceil(matching().length / perPage)) go(page + 1);
            });

            searchEl.addEventListener('input', function () { page = 1; render(); });
            // Enter in the search box must not submit the evaluation form.
            searchEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') e.preventDefault();
            });
            statusEl.addEventListener('change', function () { page = 1; render(); });
            clearEl.addEventListener('click', function () {
                searchEl.value = '';
                statusEl.value = '';
                page = 1;
                render();
            });

            // Window / sidebar resizes and the toolbar wrapping onto a second
            // line change the wrap's size; a Settings > font size change moves
            // the row-height vars without resizing anything, so watch that too.
            var timer = null;
            function schedule() { clearTimeout(timer); timer = setTimeout(remeasure, 100); }
            new ResizeObserver(schedule).observe(wrap);
            new ResizeObserver(schedule).observe(toolbar);
            new MutationObserver(schedule).observe(document.documentElement,
                { attributes: true, attributeFilter: ['data-font-size'] });

            perPage = computePerPage();
            render();

            // Exposed for the submit check below: jump to the page holding a row.
            window.svShowRow = function (tr) {
                searchEl.value = '';
                statusEl.value = '';
                var idx = allRows.indexOf(tr);
                page = Math.floor(idx / perPage) + 1;
                render();
            };
        })();

        // Client-side validation mirrors the server rule — every non-absent
        // student must have a Pass/Reject selected. It checks ALL rows, not just
        // the page on screen, and jumps to the first one that is missing.
        document.getElementById('eval-form').addEventListener('submit', function (evt) {
            const missing = [];
            let firstRow = null;
            document.querySelectorAll('tr[data-queue-id]').forEach(function (tr) {
                const absent = tr.querySelector('.js-absent-toggle');
                if (absent && absent.checked) return;
                const radios = tr.querySelectorAll('.js-result-radio');
                const checked = Array.from(radios).some(function (r) { return r.checked; });
                if (!checked && radios.length > 0 && !radios[0].disabled) {
                    missing.push(tr.dataset.student);
                    if (!firstRow) firstRow = tr;
                }
            });
            if (missing.length > 0) {
                evt.preventDefault();
                if (firstRow && window.svShowRow) window.svShowRow(firstRow);
                alert('Please select Pass or Decline for every present student:\n\n' + missing.join('\n'));
            }
        });
    </script>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Roster';
$activeNav = 'interviews';
$pageWide  = true; // table-heavy page — same as the other auto-table pages
include VIEWS_PATH . '/layouts/app.php';
