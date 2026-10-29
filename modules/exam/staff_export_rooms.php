<?php
// ============================================================
// modules/exam/staff_export_rooms.php
// Exam room roster — on-screen table + printable / exportable
// room-assignment sheets for the entrance exam.
//
// Use case: print the sheets and post them on the school board so
// students who forgot their phone (or the system) can see which
// room they're in.
//
// Views (via ?format=):
//   • (default)    → Roster table inside the app layout: one row per
//                    seated applicant, with search + date / college /
//                    room filters and auto-fit pagination (same
//                    AutoPageSize scheme as the audit log).
//   • format=print → The styled per-room sheets with @media print rules
//                    and a Print button. Always lists every room that
//                    matches the filters, never paginated, so what gets
//                    posted on the board is complete. Ctrl+P → "Save as
//                    PDF" or send to a physical printer.
//   • format=csv   → A flat CSV download for record-keeping / mail-merge.
//
// Filters (via query string):
//   • date=YYYY-MM-DD      → just one exam day. Default: all upcoming.
//   • dept=<college name>  → just one college (admins only). Default: all.
//   • slot=<id>            → just one room slot.
//   • q=<text>             → table only: name / course / room / college search.
//
// Permissions: staff sees only their own department; admin sees everything.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db        = db();
$staffId   = Auth::id();
$isAdmin   = Auth::role() === ROLE_ADMIN;
$staffDept = $isAdmin ? '' : user_department($staffId);

$schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$schoolName = school_setting('school_name', 'PLP Admissions');
$schoolLogo = school_setting('school_logo', '');

// ── Filters ────────────────────────────────────────────────
$filterDate   = get_str('date');
$filterDept   = get_str('dept');
$filterSlotId = get_int('slot');
$search       = get_str('q');
$format       = $_GET['format'] ?? 'html';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}

if (!$isAdmin) {
    // Staff is locked to their department.
    $filterDept = $staffDept;
}

// ── Slot filter (shared by the table, the sheets and the CSV) ──
// Named placeholders throughout so the same params feed paginate().
$roomWhere  = ['s.school_year = :sy'];
$roomParams = [':sy' => $schoolYear];

if ($filterDate !== '') {
    $roomWhere[]          = 's.exam_date = :exam_date';
    $roomParams[':exam_date'] = $filterDate;
} else {
    // Default: today + future. Past dates are useless for posting.
    $roomWhere[] = 's.exam_date >= CURRENT_DATE';
}

if ($filterDept !== '') {
    $roomWhere[]          = 's.department = :dept';
    $roomParams[':dept']  = $filterDept;
}

// Same filter, narrowed to one room — used by the table / sheets / CSV.
$slotWhere  = $roomWhere;
$slotParams = $roomParams;
if ($filterSlotId > 0) {
    $slotWhere[]          = 's.id = :slot_id';
    $slotParams[':slot_id'] = $filterSlotId;
}

// ── Sheets + CSV: every matching room with its full roster ──
if ($format === 'csv' || $format === 'print') {
    $stmt = $db->prepare(
        'SELECT s.id, s.exam_date, s.slot_time, s.room_label, s.department, s.capacity,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = s.id) AS filled
           FROM exam_slot_schedule s
          WHERE ' . implode(' AND ', $slotWhere) . '
          ORDER BY s.exam_date ASC, s.slot_time ASC, s.department ASC, s.room_label ASC'
    );
    $stmt->execute($slotParams);
    $slots = $stmt->fetchAll();

    // Pull rosters in a single query, then bucket by slot id.
    $rosterBySlot = [];
    if ($slots) {
        $slotIds = array_column($slots, 'id');
        [$in]    = sql_in($slotIds);
        $stmt = $db->prepare(
            "SELECT aes.slot_id, aes.applicant_id,
                    u.name        AS student_name,
                    u.first_name  AS first_name,
                    u.middle_name AS middle_name,
                    u.last_name   AS last_name,
                    u.suffix      AS suffix,
                    a.course_applied, a.applicant_type
               FROM applicant_exam_slots aes
               JOIN applicants a ON a.id = aes.applicant_id
               JOIN users      u ON u.id = a.user_id
              WHERE aes.slot_id IN ({$in})
              ORDER BY u.last_name ASC, u.first_name ASC, u.middle_name ASC, u.name ASC"
        );
        $stmt->execute($slotIds);
        foreach ($stmt->fetchAll() as $row) {
            $rosterBySlot[(int) $row['slot_id']][] = $row;
        }
    }
}

// ── CSV branch ─────────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'exam-room-assignments-'
        . ($filterDate !== '' ? $filterDate : now_date())
        . '.csv';

    $csvRows = (function () use ($slots, $rosterBySlot) {
        foreach ($slots as $slot) {
            $list = $rosterBySlot[(int) $slot['id']] ?? [];
            $base = [
                date('Y-m-d', strtotime($slot['exam_date'])),
                date('H:i',   strtotime($slot['slot_time'])),
                $slot['department'] ?: '-',
                $slot['room_label'],
                $slot['capacity'],
            ];
            if (!$list) {
                yield [...$base, '', '(empty)'];
                continue;
            }
            $i = 0;
            foreach ($list as $r) {
                $i++;
                yield [...$base, $i, format_exam_roster_name($r)];
            }
        }
    })();
    csv_download(
        $filename,
        ['Date', 'Time', 'College', 'Room', 'Capacity', 'Seat #', 'Name (Surname, First Middle)'],
        $csvRows
    );
    audit_log(AuditAction::EXAM_ROOMS_EXPORT_CSV, 'Exported room assignments as CSV', null, null);
    exit;
}

// ── Print branch: standalone per-room sheets ───────────────
if ($format === 'print') {
    audit_log(AuditAction::EXAM_ROOMS_EXPORT_HTML, 'Viewed exam room assignments printable view', null, null);

    // Resolve school logo to a usable URL (handles both absolute and relative paths)
    $logoUrl = '';
    if ($schoolLogo !== '') {
        $logoUrl = str_starts_with($schoolLogo, 'http') ? $schoolLogo : url($schoolLogo);
    }

    $pageTitleSheet = 'Exam Room Assignments';

    // Back to the roster table with the same date / college / room filters.
    $backUrl = url('/staff/exam/export-rooms') . '?' . http_build_query(array_filter([
        'date' => $filterDate,
        'dept' => $isAdmin ? $filterDept : '',
        'slot' => $filterSlotId,
    ]));
    $csvUrl = url('/staff/exam/export-rooms') . '?' . http_build_query(array_filter([
        'date'   => $filterDate,
        'dept'   => $isAdmin ? $filterDept : '',
        'slot'   => $filterSlotId,
        'format' => 'csv',
    ]));
    $sheetAccent = school_setting('accent_color', '#2d6a4f');
?>
<!DOCTYPE html>
<html lang="en" style="--accent: <?= e($sheetAccent) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitleSheet) ?> - <?= e($schoolName) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/pages/exam-export-rooms.css')) ?>">
</head>
<body>

<!-- ── Toolbar (hidden when printing) ──────────────────────── -->
<div class="toolbar no-print">
    <div class="left">
        <a href="<?= url('/staff/exam/slots') ?>" class="btn">← Back to Slots</a>
        <form method="GET" action="" style="display:flex;gap:8px;align-items:center;margin-left:8px">
            <label for="room_date" style="font-size:10pt;color:var(--muted)">Date:</label>
            <select id="room_date" name="date" class="toolbar-select" onchange="this.form.submit()">
                <option value="">All upcoming</option>
                <?php foreach ($datesAvail as $d): ?>
                    <option value="<?= e($d) ?>" <?= $filterDate === $d ? 'selected' : '' ?>>
                        <?= e(date('M j, Y (D)', strtotime($d))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($isAdmin && $deptsAvail): ?>
                <label for="room_dept" style="font-size:10pt;color:var(--muted);margin-left:8px">College:</label>
                <select id="room_dept" name="dept" class="toolbar-select" onchange="this.form.submit()">
                    <option value="">All colleges</option>
                    <?php foreach ($deptsAvail as $d): ?>
                        <option value="<?= e($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>>
                            <?= e($d) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <noscript><button class="btn" type="submit">Apply</button></noscript>
        </form>
    </div>
    <div class="right">
        <a class="btn" href="<?= e($csvUrl) ?>"
           title="Download CSV (for record-keeping / mail merge)">
            ↓ CSV
        </a>
        <button class="btn btn-primary" onclick="window.print()">
            <?= icon('printer', 14) ?>
            Print / Save as PDF
        </button>
    </div>
</div>

<div class="pagewrap">

<?php if (!$slots): ?>
    <div class="empty">
        <h2 style="margin:0 0 8px;font-size:14pt">No exam slots match these filters.</h2>
        <p style="margin:0;color:var(--muted)">Try a different date or college, or create slots first.</p>
    </div>
<?php else: ?>
    <?php foreach ($slots as $slot):
        $sid    = (int) $slot['id'];
        $roster = $rosterBySlot[$sid] ?? [];
        $filled = (int) $slot['filled'];
        $cap    = (int) $slot['capacity'];
    ?>
    <section class="room-sheet">
        <div class="sheet-header">
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="">
            <?php endif; ?>
            <div class="meta">
                <h1>Entrance Exam - Room Assignment</h1>
                <p class="school"><?= e($schoolName) ?></p>
                <p class="sy">School Year <?= e($schoolYear) ?></p>
            </div>
        </div>

        <div class="room-info">
            <div>
                <div class="label">College</div>
                <div class="value"><?= e($slot['department'] ?: '-') ?></div>
            </div>
            <div>
                <div class="label">Date</div>
                <div class="value"><?= e(date('M j, Y', strtotime($slot['exam_date']))) ?></div>
                <div style="font-size:9pt;color:var(--muted)"><?= e(date('l', strtotime($slot['exam_date']))) ?></div>
            </div>
            <div>
                <div class="label">Time</div>
                <div class="value"><?= e(date('g:i A', strtotime($slot['slot_time']))) ?></div>
            </div>
            <div>
                <div class="label">Room</div>
                <div class="value lg"><?= e($slot['room_label']) ?></div>
                <div style="font-size:9pt;color:var(--muted)"><?= $filled ?> / <?= $cap ?> seats filled</div>
            </div>
        </div>

        <table class="roster">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Name (Surname, First Middle)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$roster): ?>
                <tr class="empty-row"><td colspan="2">No applicants assigned to this room yet.</td></tr>
            <?php else: ?>
                <?php foreach ($roster as $i => $r): ?>
                    <tr>
                        <td class="num"><?= $i + 1 ?>.</td>
                        <td class="name"><?= e(format_exam_roster_name($r)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endforeach; ?>
<?php endif; ?>

</div>

<script src="<?= e(asset('js/pages/exam-export-rooms.js')) ?>"></script>
</body>
</html>
<?php
    exit;
}

// ── Table branch: roster inside the app layout ─────────────
// Pagination — same auto-fit scheme as the audit log: AutoPageSize (app.js)
// measures how many fixed-height rows fit and reloads with ?per_page=; see
// auto_per_page() in helpers.php.
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = auto_per_page('exam-export-rooms');

// One row per seated applicant. Seat # is numbered inside each room BEFORE the
// search narrows the rows, so a searched row keeps the seat number it has on
// the printed sheet (same ordering as the sheets and the CSV).
$innerSql = 'SELECT s.id AS slot_id, s.exam_date, s.slot_time, s.room_label, s.department, s.capacity,
                    u.name AS student_name, u.first_name, u.middle_name, u.last_name, u.suffix,
                    a.course_applied,
                    ROW_NUMBER() OVER (
                        PARTITION BY s.id
                        ORDER BY u.last_name ASC, u.first_name ASC, u.middle_name ASC, u.name ASC
                    ) AS seat_no
               FROM exam_slot_schedule s
               JOIN applicant_exam_slots aes ON aes.slot_id = s.id
               JOIN applicants a ON a.id = aes.applicant_id
               JOIN users      u ON u.id = a.user_id
              WHERE ' . implode(' AND ', $slotWhere);

$outerWhere  = '1=1';
$tableParams = $slotParams;
if ($search !== '') {
    // Distinct placeholder per column (emulated prepares + the pooler).
    $cols  = ['r.student_name', 'r.first_name', 'r.last_name',
              'r.course_applied', 'r.room_label', 'r.department'];
    $likes = [];
    foreach ($cols as $i => $col) {
        $likes[]                  = "{$col} ILIKE :q{$i}";
        $tableParams[":q{$i}"]    = '%' . $search . '%';
    }
    $outerWhere = '(' . implode(' OR ', $likes) . ')';
}

$result = paginate(
    $db,
    "SELECT COUNT(*) FROM ({$innerSql}) r WHERE {$outerWhere}",
    "SELECT r.* FROM ({$innerSql}) r WHERE {$outerWhere}
      ORDER BY r.exam_date ASC, r.slot_time ASC, r.department ASC,
               r.room_label ASC, r.slot_id ASC, r.seat_no ASC",
    $tableParams, $page, $perPage
);
$rosterRows = $result['data'];
$page       = $result['current_page'];

// Distinct dropdown values for the filter bar
$datesAvail = [];
$deptsAvail = [];
$datesStmt  = $db->prepare(
    'SELECT DISTINCT exam_date FROM exam_slot_schedule
      WHERE school_year = ? AND exam_date >= CURRENT_DATE
      ORDER BY exam_date ASC'
);
$datesStmt->execute([$schoolYear]);
foreach ($datesStmt->fetchAll() as $r) $datesAvail[] = $r['exam_date'];

if ($isAdmin) {
    $deptsStmt = $db->prepare(
        "SELECT DISTINCT department FROM exam_slot_schedule
          WHERE school_year = ? AND department <> ''
          ORDER BY department ASC"
    );
    $deptsStmt->execute([$schoolYear]);
    foreach ($deptsStmt->fetchAll() as $r) $deptsAvail[] = $r['department'];
}

// Room dropdown — every room matching the date / college filters (not the
// room filter itself, so the list never collapses to the one picked room).
$roomStmt = $db->prepare(
    'SELECT s.id, s.exam_date, s.slot_time, s.room_label, s.department
       FROM exam_slot_schedule s
      WHERE ' . implode(' AND ', $roomWhere) . '
      ORDER BY s.exam_date ASC, s.slot_time ASC, s.department ASC, s.room_label ASC'
);
$roomStmt->execute($roomParams);
$roomOptions = $roomStmt->fetchAll();

// URL helper — keeps every current filter + per_page while overriding just
// the given keys (page links). Same shape as the audit log's auditUrl().
$rosterUrl = function (array $merge = []) use ($search, $filterDate, $filterDept, $filterSlotId, $isAdmin, $perPage): string {
    $base = [
        'q'        => $search,
        'date'     => $filterDate,
        'dept'     => $isAdmin ? $filterDept : '',
        'slot'     => $filterSlotId > 0 ? $filterSlotId : '',
        'per_page' => $perPage,
        'page'     => 1,
    ];
    return url('/staff/exam/export-rooms') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
};

// CSV and the printable sheets are per ROOM (date / college / room filters
// only) — the search narrows the on-screen table, not what gets posted.
$exportQuery = array_filter([
    'date' => $filterDate,
    'dept' => $isAdmin ? $filterDept : '',
    'slot' => $filterSlotId > 0 ? $filterSlotId : '',
]);
$csvUrl   = url('/staff/exam/export-rooms') . '?' . http_build_query($exportQuery + ['format' => 'csv']);
$printUrl = url('/staff/exam/export-rooms') . '?' . http_build_query($exportQuery + ['format' => 'print']);

$hasFilters = ($search !== '' || $filterDate !== '' || ($isAdmin && $filterDept !== '') || $filterSlotId > 0);

ob_start();
?>

<?php
// Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
// .auto-table-wrap that AutoPageSize measures, the card with the toolbar and
// the fixed-height table fused together, and the pagination bar under the card.
?>
<div class="auto-table-wrap" data-auto-page-size="exam-export-rooms" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + filters + export actions, inside the card -->
    <form method="GET" action="<?= url('/staff/exam/export-rooms') ?>" class="auto-table-toolbar">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" id="room_q" name="q" aria-label="Search" class="form-input" placeholder="Search name, course, room…" value="<?= e($search) ?>">
        </div>

        <select name="date" class="form-input" style="width:180px" aria-label="Exam date"
                onchange="this.form.slot.value='';this.form.submit()">
            <option value="">All upcoming</option>
            <?php foreach ($datesAvail as $d): ?>
                <option value="<?= e($d) ?>" <?= $filterDate === $d ? 'selected' : '' ?>>
                    <?= e(date('M j, Y (D)', strtotime($d))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($isAdmin && $deptsAvail): ?>
        <select name="dept" class="form-input" style="width:200px" aria-label="College"
                onchange="this.form.slot.value='';this.form.submit()">
            <option value="">All colleges</option>
            <?php foreach ($deptsAvail as $d): ?>
                <option value="<?= e($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="slot" class="form-input" style="width:200px" aria-label="Room" onchange="this.form.submit()">
            <option value="">All rooms</option>
            <?php foreach ($roomOptions as $ro):
                $roomLabel = ($filterDate === '' ? date('M j', strtotime($ro['exam_date'])) . ' · ' : '')
                           . date('g:i A', strtotime($ro['slot_time'])) . ' · ' . $ro['room_label']
                           . ($isAdmin && $filterDept === '' && $ro['department'] ? ' · ' . $ro['department'] : '');
            ?>
                <option value="<?= (int)$ro['id'] ?>" <?= $filterSlotId === (int)$ro['id'] ? 'selected' : '' ?>><?= e($roomLabel) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($hasFilters): ?>
            <a href="<?= e(url('/staff/exam/export-rooms') . '?' . http_build_query(['per_page' => $perPage])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>

        <span class="toolbar-spacer"></span>

        <a href="<?= e($csvUrl) ?>" class="btn btn-secondary btn-sm"
           title="Download CSV of the rooms matching the date / college / room filters (record-keeping / mail merge)">
            <?= icon('download-simple', 14) ?> CSV
        </a>
        <a href="<?= e($printUrl) ?>" class="btn btn-primary btn-sm"
           title="Printable per-room sheets to post on the school board">
            <?= icon('printer', 14) ?> Print sheets
        </a>
    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <th style="width:130px">Date</th>
                    <th style="width:100px">Time</th>
                    <th style="width:220px">College</th>
                    <th style="width:120px">Room</th>
                    <th style="width:64px">#</th>
                    <th style="min-width:240px">Name (Surname, First Middle)</th>
                    <th style="width:260px">Course</th>
                </tr>
            </thead>
            <?php if (!empty($rosterRows)): ?>
            <tbody>
                <?php foreach ($rosterRows as $r): ?>
                <tr>
                    <td style="font-size:var(--text-sm)">
                        <span class="auto-table-clip" title="<?= e(date('l', strtotime($r['exam_date']))) ?>"><?= e(date('M j, Y', strtotime($r['exam_date']))) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)">
                        <span class="auto-table-clip"><?= e(date('g:i A', strtotime($r['slot_time']))) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)" title="<?= e($r['department'] ?: '-') ?>">
                        <span class="auto-table-clip"><?= e($r['department'] ?: '-') ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)" title="<?= e($r['room_label']) ?> (<?= (int)$r['capacity'] ?> seats)">
                        <span class="auto-table-clip" style="font-weight:var(--weight-medium)"><?= e($r['room_label']) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)"><?= (int)$r['seat_no'] ?>.</td>
                    <td style="font-size:var(--text-sm)" title="<?= e(format_exam_roster_name($r)) ?>">
                        <span class="auto-table-clip" style="font-weight:var(--weight-medium)"><?= e(format_exam_roster_name($r)) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)" title="<?= e($r['course_applied'] ?? '') ?>">
                        <span class="auto-table-clip"><?= e(($r['course_applied'] ?? '') !== '' ? $r['course_applied'] : '-') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($rosterRows)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_people_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No assigned applicants match your filters.' : 'No applicants are assigned to upcoming exam rooms yet.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap. Its 40px is reserved in
     AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $rosterUrl(['page' => $p]), 'applicants') ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->

<?php
$content   = ob_get_clean();
$pageTitle = 'Exam Room Roster';
$pageBreadcrumb = [['label' => 'Exam Slots', 'to' => '/staff/exam/slots']];
$activeNav = 'exam';
$pageWide  = true; // table-heavy page - match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';
