<?php
// ============================================================
// modules/exam/_slots_view.php
// View for the exam room slots screen. Included by modules/exam/staff_slots.php after its
// controller logic, inside the ob_start() buffer, so it shares that
// file's variables. HTML only: no request handling lives here.
// ============================================================
?>


<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($err) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<?php if ($mode === 'colleges'): ?>

    <!-- ============================================================
         MODE 1 - COLLEGE SELECTOR
         (Admins land here. Staff are auto-routed to their college.)
    ============================================================ -->
    <?php if (empty($departments)): ?>
        <div class="select-empty">No colleges / departments configured in the system.</div>
    <?php else: ?>
        <div class="select-grid college-grid">
            <?php foreach ($departments as $dept):
                $info = $collegeCounts[$dept] ?? [
                    'total_slots' => 0, 'upcoming_slots' => 0,
                    'total_seats' => 0, 'filled_seats' => 0,
                ];
                $deptNeedsSetup = ((int)$info['upcoming_slots']) === 0;
            ?>
                <a href="<?= e(url('/staff/exam/slots') . '?college=' . urlencode($dept)) ?>" class="select-card college-card">
                    <?php if ($deptNeedsSetup): ?>
                        <span class="select-card-dot" title="No upcoming slots - needs setup"></span>
                    <?php endif; ?>
                    <div class="select-card-title"><?= e($dept) ?></div>
                    <div class="select-card-meta">
                        <div class="select-card-meta-row">
                            <?= (int)$info['upcoming_slots'] ?> upcoming slot<?= (int)$info['upcoming_slots'] === 1 ? '' : 's' ?>
                        </div>
                        <div class="select-card-meta-row">
                            <?= (int)$info['filled_seats'] ?> / <?= (int)$info['total_seats'] ?> seats filled
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($mode === 'slots'): ?>

    <!-- ============================================================
         MODE 2 - SLOT CARD GRID FOR ONE COLLEGE
    ============================================================ -->
    <div data-page-actions>
    <?php if ($canManage && !empty($slotsForCollege)): ?>
        <?= ui_button('Select', ['variant' => 'ghost', 'attrs' => ['id' => 'es-select-toggle', 'onclick' => 'toggleEsSelectMode()']]) ?>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <?= ui_button('Export Lists', ['href' => url('/staff/exam/export-rooms'), 'icon' => 'ic_fluent_document_24_regular', 'attrs' => ['target' => '_blank', 'rel' => 'noopener', 'title' => 'Printable per-room sheets to post on the school board']]) ?>
        <?= ui_button('Config', ['variant' => 'ghost', 'icon' => 'ic_fluent_settings_24_regular', 'attrs' => ['onclick' => "document.getElementById('exam-config-modal').style.display='flex'"]]) ?>
    <?php else: ?>
        <span class="badge badge-neutral">
            <?= icon('ic_fluent_eye_24_regular', 12) ?> Read-only
        </span>
    <?php endif; ?>
    </div>

    <?php
    $today      = now_date();
    $totalCap   = array_sum(array_column($slotsForCollege, 'capacity'));
    $totalFil   = array_sum(array_column($slotsForCollege, 'filled'));

    // Build a date map for the toolbar filter dropdown. One entry per
    // distinct exam date, with a count of slots on that date and a
    // "past" flag for dates earlier than today. Filtering is pure
    // client-side via data-date on each card — no extra DB queries.
    $slotDateMap = [];
    foreach ($slotsForCollege as $_s) {
        $d = (string)($_s['exam_date'] ?? '');
        if ($d === '') continue;
        if (!isset($slotDateMap[$d])) {
            $slotDateMap[$d] = ['date' => $d, 'count' => 0, 'is_past' => $d < $today];
        }
        $slotDateMap[$d]['count']++;
    }
    usort($slotDateMap, fn($a, $b) => $a['date'] <=> $b['date']);
    ?>
    <div class="select-toolbar">
        <?php if (count($slotDateMap) > 1): ?>
            <select id="es-date-filter" class="form-control" title="Filter by exam date" aria-label="Filter by exam date">
                <option value="" data-count="<?= count($slotsForCollege) ?>">
                    All dates · <?= count($slotsForCollege) ?> slot<?= count($slotsForCollege) === 1 ? '' : 's' ?>
                </option>
                <?php foreach ($slotDateMap as $_d): ?>
                    <option value="<?= e($_d['date']) ?>" data-count="<?= (int)$_d['count'] ?>">
                        <?= format_date($_d['date']) ?><?= $_d['is_past'] ? ' (Past)' : '' ?>
                        · <?= (int)$_d['count'] ?> slot<?= (int)$_d['count'] === 1 ? '' : 's' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <span id="es-slot-count">
            <?= count($slotsForCollege) ?> slot<?= count($slotsForCollege) === 1 ? '' : 's' ?>
            &nbsp;·&nbsp; <?= $totalFil ?> / <?= $totalCap ?> seats filled
        </span>
    </div>

    <div class="select-grid es-slot-grid">
        <?php foreach ($slotsForCollege as $slot):
            $sid       = (int)$slot['id'];
            $filled    = (int)$slot['filled'];
            $cap       = (int)$slot['capacity'];
            $isFull    = $filled >= $cap;
            $isPast    = $slot['exam_date'] < $today;
            $isToday   = $slot['exam_date'] === $today;

            $cardClass = 'select-card es-slot-card';
            if ($isToday) $cardClass .= ' is-today';
            if ($isPast)  $cardClass .= ' is-muted';
            if ($isFull)  $cardClass .= ' is-full';

            $editPayload = json_encode([
                'id'         => $sid,
                'exam_date'  => $slot['exam_date'],
                'slot_time'  => substr($slot['slot_time'] ?? '', 0, 5),
                'end_time'   => substr($slot['end_time']  ?? '', 0, 5),
                'room_label' => $slot['room_label'] ?? '',
                'department' => $slot['department'] ?? '',
                'capacity'   => $cap,
                'filled'     => $filled,
            ], JSON_HEX_APOS | JSON_HEX_QUOT);
            // Slots with applicants assigned can't be deleted in bulk either —
            // mirrors the single-row delete rule.
            $esUndeletable = $filled > 0;
            if ($esUndeletable) $cardClass .= ' is-undeletable';
        ?>
            <a href="<?= e(url('/staff/exam/slots') . '?slot=' . $sid) ?>"
               class="<?= $cardClass ?>"
               data-slot-id="<?= $sid ?>"
               data-date="<?= e($slot['exam_date'] ?? '') ?>"
               data-filled="<?= $filled ?>"
               data-capacity="<?= $cap ?>"
               onclick="return onEsCardClick(event, this)">
                <?php if ($canManage): ?>
                    <input type="checkbox" class="select-card-check es-select-checkbox" aria-label="Select slot"
                           value="<?= $sid ?>"
                           <?= $esUndeletable ? 'disabled title="Has applicants - cannot remove"' : '' ?>
                           onclick="event.stopPropagation();onEsCheckboxChange(this)">
                <?php endif; ?>

                <!-- Edit pencil — stops navigation to the roster -->
                <?php if ($canManage && !$isPast): ?>
                    <button type="button" class="es-card-edit-btn"
                            title="Edit slot"
                            onclick='event.preventDefault();event.stopPropagation();openEditExamSlot(<?= $editPayload ?>)'>
                        <?= icon('ic_fluent_edit_24_regular', 14) ?>
                    </button>
                <?php endif; ?>

                <!-- Status badge top-right (offset to leave room for edit button) -->
                <div class="select-card-badge<?= ($isPast || !$canManage) ? '' : ' has-edit' ?>">
                    <?php if ($isPast): ?>
                        <span class="badge badge-neutral">Ended</span>
                    <?php elseif ($isFull): ?>
                        <span class="badge badge-rejected">Full</span>
                    <?php elseif ($isToday): ?>
                        <span class="badge badge-info">Today</span>
                    <?php else: ?>
                        <span class="badge badge-approved">Open</span>
                    <?php endif; ?>
                </div>


                <div class="select-card-title">
                    <?= e($slot['room_label'] ?? '') ?>
                </div>

                <div class="select-card-meta">
                    <div class="select-card-meta-row">
                        <?= e(format_date($slot['exam_date'], 'D, M j, Y')) ?>
                    </div>
                    <div class="select-card-meta-row">
                        <?= e(format_time($slot['slot_time'])) ?>
                        <?php if (!empty($slot['end_time'])): ?>
                            &ndash; <?= e(format_time($slot['end_time'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="select-card-meta-row">
                        <strong style="<?= $isFull ? 'color:var(--error)' : 'color:var(--text-secondary)' ?>">
                            <?= $filled ?> / <?= $cap ?>
                        </strong>
                        seats filled
                    </div>
                    <?php
                        $cardSecsLeft = (int)($slot['pw_secs_left'] ?? 0);
                        $cardCodeActive = !empty($slot['access_password']) && $cardSecsLeft > 0;
                    ?>
                    <?php if ($cardCodeActive): ?>
                        <div class="select-card-meta-row">
                            Code
                            <span class="es-card-code-badge<?= $cardSecsLeft <= 60 ? ' is-warn' : '' ?>"
                                  data-card-pw-end="<?= time() + $cardSecsLeft ?>">
                                <?= e($slot['access_password']) ?>
                                · <span class="card-pw-timer"><?= gmdate('i:s', $cardSecsLeft) ?></span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="select-card-footer">
                    <span><?= e($slot['department']) ?></span>
                    <span class="select-card-action">
                        View
                    </span>
                </div>
            </a>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
            <!-- Add Slot dashed card -->
            <div class="select-card select-card--add es-add-card"
                 role="button" tabindex="0"
                 onclick="document.getElementById('add-slot-modal').style.display='flex'"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                <div class="select-card-title">Add Slot</div>
                <div class="select-card-desc">Create a new exam room slot</div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canManage && !empty($slotsForCollege)): ?>
    <!-- Floating bar for bulk delete (visible while in select mode). -->
    <div id="es-bulk-bar" class="select-bulk-bar es-bulk-bar">
        <span id="es-bulk-count" style="font-weight:var(--weight-medium)">0 selected</span>
        <form method="POST" id="es-bulk-form"
              style="display:flex;gap:var(--space-2);align-items:center;margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action"      value="delete_slots_bulk">
            <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
            <input type="hidden" name="ids"         id="es-bulk-ids" value="">
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="cancelEsSelectMode()">Cancel</button>
            <button type="submit" class="btn btn-sm" id="es-bulk-delete-btn"
                    style="background:var(--error);color:var(--white);border-color:var(--error)"
                    onclick="return confirmEsBulkDelete(this)">
                Delete Selected
            </button>
        </form>
    </div>
    <?php endif; ?>



<?php elseif ($mode === 'roster'): ?>

    <!-- ============================================================
         MODE 3 - SLOT ROSTER (full-page table)
    ============================================================ -->
    <?php
    $sid       = (int)$slotDetail['id'];
    $filled    = (int)$slotDetail['filled'];
    $cap       = (int)$slotDetail['capacity'];
    $isPast    = $slotDetail['exam_date'] < now_date();

    $editPayload = json_encode([
        'id'         => $sid,
        'exam_date'  => $slotDetail['exam_date'],
        'slot_time'  => substr($slotDetail['slot_time'] ?? '', 0, 5),
        'end_time'   => substr($slotDetail['end_time']  ?? '', 0, 5),
        'room_label' => $slotDetail['room_label'] ?? '',
        'department' => $slotDetail['department'] ?? '',
        'capacity'   => $cap,
        'filled'     => $filled,
    ], JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>

    <!-- Slot summary strip -->
    <div style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3) var(--space-4);
                margin-bottom:var(--space-4);background:var(--bg-elevated);border:1px solid var(--border);
                border-radius:var(--radius);font-size:var(--text-sm);flex-wrap:wrap;flex-shrink:0">
        <?= icon('ic_fluent_location_24_regular', 14, 'color:var(--text-tertiary);flex-shrink:0') ?>

        <?php if ($canManage && !$isPast): ?>
            <!-- Inline-editable room label (Admin/SSO only). Click the pencil to rename. -->
            <span id="room-label-view" style="display:inline-flex;align-items:center;gap:6px">
                <span id="room-label-text" style="font-weight:var(--weight-medium)"><?= e($slotDetail['room_label']) ?></span>
                <button type="button" class="btn-icon" title="Rename room"
                        onclick="startRoomLabelEdit()"
                        style="padding:2px;color:var(--text-tertiary)">
                    <?= icon('ic_fluent_edit_24_regular', 12) ?>
                </button>
            </span>
            <span id="room-label-edit" style="display:none;align-items:center;gap:4px">
                <input type="text" id="room-label-input" aria-label="Room label" class="form-control"
                       value="<?= e($slotDetail['room_label']) ?>"
                       maxlength="80"
                       style="font-size:var(--text-sm);padding:2px 8px;height:auto;min-height:0;width:240px">
                <button type="button" class="btn btn-primary btn-sm"
                        onclick="saveRoomLabel(<?= $sid ?>)"
                        style="font-size:var(--text-xs);padding:2px 8px;height:auto;min-height:0">Save</button>
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="cancelRoomLabelEdit()"
                        style="font-size:var(--text-xs);padding:2px 8px;height:auto;min-height:0">Cancel</button>
            </span>
        <?php else: ?>
            <span style="font-weight:var(--weight-medium)"><?= e($slotDetail['room_label']) ?></span>
        <?php endif; ?>

        <span style="color:var(--text-tertiary)">
            <?= e(format_date($slotDetail['exam_date'], 'D, M j, Y')) ?>
            · <?= e(format_time($slotDetail['slot_time'])) ?><?php if (!empty($slotDetail['end_time'])): ?>–<?= e(format_time($slotDetail['end_time'])) ?><?php endif; ?>
            · <?= e($slotDetail['department']) ?>
        </span>
        <span style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary)">
            <strong style="<?= $filled >= $cap ? 'color:var(--error)' : '' ?>">
                <?= $filled ?> / <?= $cap ?>
            </strong>
            seats filled
            <?php if ($isPast): ?>
                <span class="badge badge-neutral" style="margin-left:var(--space-2)">Ended</span>
            <?php endif; ?>
        </span>
    </div>

    <?php
        $rosterCanGenerate = $canGenerateCodeFor((string) $slotDetail['department']);
        $rosterSecsLeft    = (int)($slotDetail['pw_secs_left'] ?? 0);
        $rosterCodeActive  = !empty($slotDetail['access_password']) && $rosterSecsLeft > 0;
    ?>

    <!-- ============================================================
         Per-room access code panel
         (proctor-facing: announce the code to the room and start the
         5-minute window. Read-only for Dean and out-of-dept staff.)
    ============================================================ -->
    <?php if (!$isPast): ?>
    <div class="es-code-panel" id="slot-code-panel" data-slot-id="<?= $sid ?>">
        <span class="es-code-panel-label">
            <?= icon('ic_fluent_lock_closed_24_regular', 14) ?>
            Access Code
        </span>

        <span id="slot-code-display"
              class="es-code-display<?= $rosterCodeActive ? '' : ' is-empty' ?>">
            <?= $rosterCodeActive ? e($slotDetail['access_password']) : 'No active code' ?>
        </span>

        <span id="slot-code-timer" class="es-code-timer"
              style="<?= $rosterCodeActive ? '' : 'display:none' ?>"
              data-secs-left="<?= $rosterSecsLeft ?>">
            Expires in <?= gmdate('i:s', $rosterSecsLeft) ?>
        </span>

        <?php if ($rosterCanGenerate): ?>
            <div style="margin-left:auto;display:flex;gap:var(--space-2)">
                <button type="button" id="slot-code-extend-btn"
                        class="btn btn-ghost btn-sm"
                        onclick="extendSlotCode()"
                        style="<?= $rosterCodeActive ? '' : 'display:none' ?>;font-size:var(--text-xs)">
                    <?= icon('ic_fluent_clock_24_regular', 12) ?> Extend
                </button>
                <button type="button" id="slot-code-generate-btn"
                        class="btn btn-primary btn-sm"
                        onclick="generateSlotCode()"
                        style="<?= $rosterCodeActive ? '' : 'display:none' ?>;font-size:var(--text-xs)">
                    <?= icon('ic_fluent_arrow_sync_24_regular', 12) ?> New
                </button>
            </div>
        <?php else: ?>
            <span style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary)">
                <?= icon('ic_fluent_eye_24_regular', 12) ?> Read-only
            </span>
        <?php endif; ?>
    </div>
    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin:-2px 0 var(--space-4) 2px;flex-shrink:0">
        <?php if ($rosterCodeActive): ?>
            Codes are valid for 5 minutes. Use <strong>Extend</strong> to give late
            but legitimate applicants another 5 minutes from now, or <strong>New</strong>
            to issue a fresh password and invalidate the current one.
        <?php elseif ($rosterCanGenerate): ?>
            A fresh access code is issued automatically the moment you open this
            page on the slot's exam date. After that, use <strong>New</strong> or
            <strong>Extend</strong> to manage the 5-minute window.
        <?php else: ?>
            Codes are valid for 5 minutes and are issued by this room's proctor.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // URL helper — keeps the slot + current filters + per_page while overriding
    // just the given keys (page links, the Clear link). Same shape as the audit
    // log's auditUrl().
    $rosterUrl = function (array $merge = []) use ($sid, $rosterSearch, $rosterType, $rosterPerPage): string {
        $base = [
            'slot'     => $sid,
            'q'        => $rosterSearch,
            'type'     => $rosterType,
            'per_page' => $rosterPerPage,
            'page'     => 1,
        ];
        return url('/staff/exam/slots') . '?' . http_build_query(array_filter(
            array_merge($base, $merge),
            fn($v) => $v !== '' && $v !== null
        ));
    };
    $rosterHasFilters = ($rosterSearch !== '' || $rosterType !== '');
    $collegeUrl       = url('/staff/exam/slots') . '?college=' . urlencode($slotDetail['department']);
    ?>

    <?php
    // Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
    // .auto-table-wrap that AutoPageSize measures, the card with the toolbar and
    // the fixed-height table fused together, and the pagination bar under the card.
    ?>
    <div class="auto-table-wrap" data-auto-page-size="exam-slot-roster" data-current-per-page="<?= (int)$rosterPerPage ?>">

    <div class="auto-table-card">

        <!-- Toolbar: search + filter, one row, inside the card -->
        <form method="GET" action="<?= e(url('/staff/exam/slots')) ?>" class="auto-table-toolbar">
            <input type="hidden" name="slot"     value="<?= $sid ?>">
            <input type="hidden" name="per_page" value="<?= (int)$rosterPerPage ?>">

            <div class="auto-table-search">
                <?= icon('ic_fluent_search_24_filled', 14) ?>
                <input type="text" id="roster_q" name="q" aria-label="Search" class="form-input" placeholder="Search name, email, course…" value="<?= e($rosterSearch) ?>">
            </div>

            <select name="type" class="form-input" style="width:160px" onchange="this.form.submit()" aria-label="Applicant type">
                <option value="">All Types</option>
                <option value="freshman"   <?= $rosterType === 'freshman'   ? 'selected' : '' ?>>Freshman</option>
                <option value="transferee" <?= $rosterType === 'transferee' ? 'selected' : '' ?>>Transferee</option>
                <option value="foreign"    <?= $rosterType === 'foreign'    ? 'selected' : '' ?>>Foreign Student</option>
            </select>

            <?php if ($rosterHasFilters): ?>
                <a href="<?= e($rosterUrl(['q' => '', 'type' => '', 'page' => 1])) ?>" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
             only scrolls as a safety net (e.g. a very narrow window) -->
        <div class="auto-table-body">
            <table class="auto-table">
                <thead>
                    <tr>
                        <th style="min-width:220px">Applicant</th>
                        <th style="width:240px">Email</th>
                        <th style="width:240px">Course</th>
                        <th style="width:120px">Type</th>
                        <th style="width:160px">Assigned</th>
                        <?php if ($canManage && !$isPast): ?>
                            <th style="width:100px">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <?php if (!empty($slotRoster)): ?>
                <tbody>
                <?php foreach ($slotRoster as $row): ?>
                    <tr>
                        <td style="font-size:var(--text-sm)" title="<?= e(format_full_name($row)) ?>">
                            <span class="auto-table-clip" style="font-weight:var(--weight-medium)"><?= e(format_full_name($row)) ?></span>
                        </td>
                        <td style="font-size:var(--text-xs);color:var(--text-tertiary)" title="<?= e($row['student_email'] ?? '') ?>">
                            <span class="auto-table-clip"><?= e(!empty($row['student_email']) ? $row['student_email'] : '-') ?></span>
                        </td>
                        <td style="font-size:var(--text-sm)" title="<?= e($row['course_applied'] ?? '') ?>">
                            <span class="auto-table-clip"><?= e($row['course_applied'] ?: '-') ?></span>
                        </td>
                        <td>
                            <span class="badge badge-neutral">
                                <?= e(ucfirst($row['applicant_type'] ?? '')) ?>
                            </span>
                        </td>
                        <td style="font-size:var(--text-xs);color:var(--text-tertiary)">
                            <span class="auto-table-clip">
                            <?= $row['assigned_at']
                                ? e(date('M j, g:i A', strtotime($row['assigned_at'])))
                                : '-' ?>
                            </span>
                        </td>
                        <?php if ($canManage && !$isPast): ?>
                            <td>
                                <form method="POST" style="margin:0"
                                      data-confirm="Remove this applicant from the slot?" data-confirm-danger data-confirm-ok="Remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"       value="unassign">
                                    <input type="hidden" name="ctx_slot"     value="<?= $sid ?>">
                                    <input type="hidden" name="applicant_id" value="<?= (int)$row['applicant_id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"
                                            style="color:var(--error);font-size:var(--text-xs)">
                                        Remove
                                    </button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>

            <?php if (empty($slotRoster)): ?>
            <div class="auto-table-empty">
                <?= icon('ic_fluent_people_24_regular', 32) ?>
                <?php if ($rosterHasFilters): ?>
                    <div>No applicants match your filters.</div>
                <?php else: ?>
                    <div style="max-width:420px">
                        No applicants assigned to this slot yet. Use the
                        <a href="<?= e($collegeUrl) ?>">college view</a>
                        to assign applicants from the awaiting list.
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /.auto-table-card -->

    <!-- Pagination bar — under the card, inside the wrap. Its 40px is reserved in
         AutoPageSize's math whether or not it renders. -->
    <?php if ($rosterResult['last_page'] > 1): ?>
        <?= auto_table_footer($rosterResult, fn(int $p): string => $rosterUrl(['page' => $p]), 'applicants') ?>
    <?php endif; ?>

    </div><!-- /.auto-table-wrap -->

<?php endif; ?>


<!-- ============================================================
     MODALS - shared across all modes that may need them.
============================================================ -->

<?php if ($mode !== 'colleges'): ?>

    <!-- ── Add Slot Modal (Single + Recurring) ────────────────── -->
    <div id="add-slot-modal" class="modal-backdrop" style="display:none">
        <div class="modal" style="max-width:480px">
            <div class="modal-header">
                <div class="modal-title">Add Slot</div>
                <button class="btn-icon" type="button"
                        onclick="document.getElementById('add-slot-modal').style.display='none'">
                    <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
                </button>
            </div>
            <form method="POST" id="add-slot-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      id="add-slot-action" value="add_slot">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">

                    <!-- Mode toggle -->
                    <div class="seg-control" style="align-self:flex-start">
                        <button type="button" id="add-slot-mode-single"
                                class="seg-control-item active"
                                onclick="setSlotMode('single')">Single slot</button>
                        <button type="button" id="add-slot-mode-batch"
                                class="seg-control-item"
                                onclick="setSlotMode('batch')">Recurring (multiple)</button>
                    </div>

                    <!-- College is taken from the page context (?college=…). The
                         JS toggle swaps this hidden input's `name` between
                         `department` and `batch_department` so the two POST
                         handlers stay unchanged. -->
                    <input type="hidden" id="add-slot-dept" name="department"
                           value="<?= e($canManage ? $collegeParam : $staffDept) ?>">

                    <!-- ── Single slot fields ─────────────────── -->
                    <div id="add-slot-single-fields" style="display:flex;flex-direction:column;gap:var(--space-4)">
                        <div>
                            <label class="form-label" for="add-slot-date">Exam Date <span style="color:var(--error)">*</span></label>
                            <input type="date" id="add-slot-date" name="exam_date" class="form-control" required
                                   value="<?= e($defaultDate) ?>" min="<?= now_date() ?>">
                        </div>
                        <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-3);--bs-gutter-y:var(--space-3)">
                            <div>
                                <label class="form-label" for="add-slot-time">Opens <span style="color:var(--error)">*</span></label>
                                <input type="time" name="slot_time" id="add-slot-time"
                                       class="form-control" value="08:00" required>
                            </div>
                            <div>
                                <label class="form-label" for="add-slot-end">Closes <span style="color:var(--error)">*</span></label>
                                <input type="time" name="end_time" id="add-slot-end"
                                       class="form-control" value="09:30" required>
                            </div>
                        </div>
                        <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-3);--bs-gutter-y:var(--space-3)">
                            <div>
                                <label class="form-label" for="add-slot-cap">Capacity <span style="color:var(--error)">*</span></label>
                                <input type="number" id="add-slot-cap" name="capacity" class="form-control"
                                       value="<?= $examRoomCap ?>" min="1" max="500" required>
                            </div>
                            <div>
                                <label class="form-label" for="add-slot-room">Room Label <span style="color:var(--error)">*</span></label>
                                <input type="text" id="add-slot-room" name="room_label" class="form-control" required
                                       placeholder="e.g. Room 101" maxlength="80">
                            </div>
                        </div>
                    </div>

                    <!-- ── Recurring (batch) fields ───────────── -->
                    <div id="add-slot-batch-fields" style="display:none;flex-direction:column;gap:var(--space-4)">
                        <p class="text-secondary-sm">
                            One slot is created per room per selected weekday in the date range.
                        </p>
                        <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-3);--bs-gutter-y:var(--space-3)">
                            <div>
                                <label class="form-label" for="add-batch-start">Start Date <span style="color:var(--error)">*</span></label>
                                <input type="date" id="add-batch-start" name="batch_start_date" class="form-control"
                                       value="<?= e($defaultDate) ?>" required>
                            </div>
                            <div>
                                <label class="form-label" for="add-batch-end">End Date <span style="color:var(--error)">*</span></label>
                                <input type="date" id="add-batch-end" name="batch_end_date" class="form-control"
                                       value="<?= e(date('Y-m-d', strtotime($defaultDate . ' +6 days'))) ?>" required>
                            </div>
                        </div>
                        <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-3);--bs-gutter-y:var(--space-3)">
                            <div>
                                <label class="form-label" for="batch-time">Opens <span style="color:var(--error)">*</span></label>
                                <input type="time" name="batch_time" id="batch-time"
                                       class="form-control" value="08:00" required>
                            </div>
                            <div>
                                <label class="form-label" for="batch-end-time">Closes <span style="color:var(--error)">*</span></label>
                                <input type="time" name="batch_end_time" id="batch-end-time"
                                       class="form-control" value="09:30" required>
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="add-batch-cap">Seats per Slot</label>
                            <input type="number" id="add-batch-cap" name="batch_capacity" class="form-control"
                                   value="<?= $examRoomCap ?>" min="1" max="500">
                        </div>
                        <div>
                            <span class="form-label">Rooms</span>
                            <div id="batch-rooms-list" style="display:flex;flex-direction:column;gap:var(--space-2)">
                                <input type="text" name="batch_rooms[]" aria-label="Room" class="form-control"
                                       placeholder="e.g. Room 101" required>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    style="margin-top:var(--space-2);font-size:var(--text-xs)"
                                    onclick="addBatchRoom()">+ Add another room</button>
                        </div>
                        <div>
                            <span class="form-label">Days of Week</span>
                            <div style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
                                <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',0=>'Sun'] as $dv => $dl): ?>
                                    <label style="display:flex;align-items:center;gap:4px;font-size:var(--text-sm)">
                                        <input type="checkbox" name="batch_days[]" value="<?= $dv ?>"
                                            <?= $dv >= 1 && $dv <= 5 ? 'checked' : '' ?>>
                                        <?= $dl ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:-2px">
                        Exam closes at <strong>Closes</strong>. Duration is the difference between the two.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('add-slot-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="add-slot-submit">Add Slot</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Edit Slot Modal ────────────────────────────────────── -->
    <div id="edit-slot-modal" class="modal-backdrop" style="display:none">
        <div class="modal" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">Edit Slot</div>
                <button class="btn-icon" type="button"
                        onclick="document.getElementById('edit-slot-modal').style.display='none'">
                    <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
                </button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      value="edit_slot">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <input type="hidden" name="ctx_slot"    id="edit-slot-ctx-slot" value="<?= $slotIdParam ?: '' ?>">
                <input type="hidden" name="slot_id"     id="edit-slot-id">
                <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                    <div>
                        <label class="form-label" for="edit-slot-dept">College / Department <span style="color:var(--error)">*</span></label>
                        <?php if ($canManage): ?>
                            <select name="department" id="edit-slot-dept" class="form-control" required>
                                <option value="">- Select college -</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" class="form-control" aria-label="College / Department" value="<?= e($staffDept ?: 'Not assigned') ?>" disabled>
                            <input type="hidden" name="department" value="<?= e($staffDept) ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label" for="edit-slot-date">Exam Date <span style="color:var(--error)">*</span></label>
                        <input type="date" name="exam_date" id="edit-slot-date"
                               class="form-control" required min="<?= now_date() ?>">
                    </div>
                    <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-3);--bs-gutter-y:var(--space-3)">
                        <div>
                            <label class="form-label" for="edit-slot-time">Opens <span style="color:var(--error)">*</span></label>
                            <input type="time" name="slot_time" id="edit-slot-time" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label" for="edit-slot-end">Closes <span style="color:var(--error)">*</span></label>
                            <input type="time" name="end_time" id="edit-slot-end" class="form-control" required>
                        </div>
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:-2px">
                        Exam closes at <strong>Closes</strong>. Duration is the difference between the two.
                    </div>
                    <div class="row row-cols-1 row-cols-md-2" style="--bs-gutter-x:var(--space-3);--bs-gutter-y:var(--space-3)">
                        <div>
                            <label class="form-label" for="edit-slot-cap">Capacity <span style="color:var(--error)">*</span></label>
                            <input type="number" name="capacity" id="edit-slot-cap"
                                   class="form-control" min="1" max="500" required>
                        </div>
                        <div>
                            <label class="form-label" for="edit-slot-room">Room Label <span style="color:var(--error)">*</span></label>
                            <input type="text" name="room_label" id="edit-slot-room"
                                   class="form-control" required maxlength="80">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:space-between">
                    <?php // Inline delete (only when slot is empty - server still validates) ?>
                    <button type="submit" form="delete-slot-form" class="btn btn-ghost"
                            id="edit-slot-delete-btn"
                            style="color:var(--error)"
                            data-confirm="Delete this slot? Only allowed if it has no applicants." data-confirm-danger data-confirm-ok="Delete">
                        Delete
                    </button>
                    <div style="display:flex;gap:var(--space-2)">
                        <button type="button" class="btn btn-ghost"
                                onclick="document.getElementById('edit-slot-modal').style.display='none'">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
            <!-- Out-of-form delete: just submits action=delete_slot for the same slot id -->
            <form method="POST" id="delete-slot-form" style="display:none">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      value="delete_slot">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <input type="hidden" name="slot_id"     id="delete-slot-id">
            </form>
        </div>
    </div>

    <!-- ── Exam Config Modal ──────────────────────────────────── -->
    <div id="exam-config-modal" class="modal-backdrop" style="display:none">
        <div class="modal" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">Exam Config</div>
                <button class="btn-icon" type="button"
                        onclick="document.getElementById('exam-config-modal').style.display='none'">
                    <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
                </button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      value="update_exam_config">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                    <div>
                        <label class="form-label" for="cfg-room-cap">Default Seats per Room</label>
                        <input type="number" id="cfg-room-cap" name="exam_room_capacity" class="form-control"
                               value="<?= $examRoomCap ?>" min="1" max="200">
                    </div>
                    <div>
                        <label class="form-label" for="cfg-daily-cap">Max Applicants per Exam Day</label>
                        <input type="number" id="cfg-daily-cap" name="exam_daily_cap" class="form-control"
                               value="<?= $examDailyCap ?>" min="1" max="10000">
                    </div>
                    <?php $roomsNeeded = $examRoomCap > 0 ? ceil($examDailyCap / $examRoomCap) : '-'; ?>
                    <div style="background:var(--bg-subtle);border-radius:var(--radius);
                                padding:var(--space-3) var(--space-4);font-size:var(--text-xs);
                                color:var(--text-secondary)">
                        <?= $examDailyCap ?> applicants &divide; <?= $examRoomCap ?>/room
                        = <strong>~<?= $roomsNeeded ?> rooms/day</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('exam-config-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Config</button>
                </div>
            </form>
        </div>
    </div>


<?php endif; ?>


