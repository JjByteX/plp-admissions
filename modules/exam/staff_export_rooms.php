<?php
// ============================================================
// modules/exam/staff_export_rooms.php
// Printable / exportable room-assignment sheets for the entrance exam.
//
// Use case: print these and post them on the school board so students
// who forgot their phone (or the system) can see which room they're in.
//
// Output options (via ?format=):
//   • (default)  → Styled HTML page with @media print rules and a Print button.
//                  Staff hits Ctrl+P → "Save as PDF" or sends to a physical printer.
//   • format=csv → A flat CSV download for record-keeping / mail-merge.
//
// Filters (via query string):
//   • date=YYYY-MM-DD      → just one exam day. Default: all upcoming.
//   • dept=<college name>  → just one college (admins only). Default: all.
//   • slot=<id>            → just one room slot.
//
// Permissions: staff sees only their own department; admin sees everything.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db        = db();
$staffId   = Auth::id();
$isAdmin   = Auth::role() === ROLE_ADMIN;
$staffDept = $isAdmin ? '' : user_department($staffId);

$schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$schoolName = school_setting('school_name', 'PLP Admissions');
$schoolLogo = school_setting('school_logo', '');

// ── Filters ────────────────────────────────────────────────
$filterDate   = trim($_GET['date'] ?? '');
$filterDept   = trim($_GET['dept'] ?? '');
$filterSlotId = (int) ($_GET['slot'] ?? 0);
$format       = $_GET['format'] ?? 'html';

if (!$isAdmin) {
    // Staff is locked to their department.
    $filterDept = $staffDept;
}

// ── Build slot query ───────────────────────────────────────
$where  = ['s.school_year = ?'];
$params = [$schoolYear];

if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $where[]  = 's.exam_date = ?';
    $params[] = $filterDate;
} else {
    // Default: today + future. Past dates are useless for posting.
    $where[]  = 's.exam_date >= CURDATE()';
}

if ($filterDept !== '') {
    $where[]  = 's.department = ?';
    $params[] = $filterDept;
}

if ($filterSlotId > 0) {
    $where[]  = 's.id = ?';
    $params[] = $filterSlotId;
}

$sql = 'SELECT s.id, s.exam_date, s.slot_time, s.room_label, s.department, s.capacity,
               (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = s.id) AS filled
          FROM exam_slot_schedule s
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY s.exam_date ASC, s.slot_time ASC, s.department ASC, s.room_label ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$slots = $stmt->fetchAll();

// ── Pull rosters in a single query, then bucket by slot id ─
$rosterBySlot = [];
if ($slots) {
    $slotIds = array_column($slots, 'id');
    $in      = implode(',', array_fill(0, count($slotIds), '?'));
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

/**
 * Format a name as: "SURNAME SUFFIX, FIRST MIDDLE" — all caps.
 * Falls back to splitting `users.name` if structured columns are empty.
 */
function format_exam_roster_name(array $r): string {
    $last   = trim($r['last_name']   ?? '');
    $first  = trim($r['first_name']  ?? '');
    $middle = trim($r['middle_name'] ?? '');
    $suffix = trim($r['suffix']      ?? '');

    if ($last === '' && $first === '') {
        // Legacy fallback — split the consolidated `users.name` on the last space.
        $full  = trim((string) ($r['student_name'] ?? ''));
        $parts = preg_split('/\s+/', $full) ?: [];
        if (count($parts) >= 2) {
            $last  = array_pop($parts);
            $first = implode(' ', $parts);
        } else {
            $last  = $full;
        }
    }

    $surnamePart = strtoupper(trim($last . ($suffix !== '' ? ' ' . $suffix : '')));
    $firstPart   = strtoupper(trim($first . ($middle !== '' ? ' ' . $middle : '')));
    return $surnamePart . ($firstPart !== '' ? ', ' . $firstPart : '');
}

// ── CSV branch ─────────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'exam-room-assignments-'
        . ($filterDate !== '' ? $filterDate : date('Y-m-d'))
        . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM so Excel opens UTF-8 cleanly.
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Time', 'College', 'Room', 'Capacity', 'Seat #', 'Name (Surname, First Middle)']);
    foreach ($slots as $slot) {
        $sid   = (int) $slot['id'];
        $list  = $rosterBySlot[$sid] ?? [];
        $i     = 0;
        if (!$list) {
            fputcsv($out, [
                date('Y-m-d', strtotime($slot['exam_date'])),
                date('H:i',   strtotime($slot['slot_time'])),
                $slot['department'] ?: '—',
                $slot['room_label'],
                $slot['capacity'],
                '', '(empty)',
            ]);
            continue;
        }
        foreach ($list as $r) {
            $i++;
            fputcsv($out, [
                date('Y-m-d', strtotime($slot['exam_date'])),
                date('H:i',   strtotime($slot['slot_time'])),
                $slot['department'] ?: '—',
                $slot['room_label'],
                $slot['capacity'],
                $i,
                format_exam_roster_name($r),
            ]);
        }
    }
    fclose($out);
    audit_log('exam_rooms_export_csv', 'Exported room assignments as CSV', null, null);
    exit;
}

// ── HTML branch ────────────────────────────────────────────
audit_log('exam_rooms_export_html', 'Viewed exam room assignments printable view', null, null);

// Distinct dropdown values for the filter bar
$datesAvail = [];
$deptsAvail = [];
$datesStmt  = $db->prepare(
    'SELECT DISTINCT exam_date FROM exam_slot_schedule
      WHERE school_year = ? AND exam_date >= CURDATE()
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

// Resolve school logo to a usable URL (handles both absolute and relative paths)
$logoUrl = '';
if ($schoolLogo !== '') {
    $logoUrl = str_starts_with($schoolLogo, 'http') ? $schoolLogo : url($schoolLogo);
}

// Page title
$pageTitleSheet = 'Exam Room Assignments';
$subtitle = 'School Year ' . $schoolYear;
if ($filterDate !== '') {
    $subtitle .= ' · ' . date('l, F j, Y', strtotime($filterDate));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitleSheet) ?> — <?= e($schoolName) ?></title>
<style>
  /* ── Screen styles ──────────────────────────────────────── */
  :root {
    --ink: #111827;
    --muted: #6b7280;
    --line: #d1d5db;
    --accent: <?= e(school_setting('accent_color', '#2d6a4f')) ?>;
    --bg: #f9fafb;
  }
  * { box-sizing: border-box; }
  body { margin:0; padding:0; background:var(--bg); color:var(--ink);
         font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Helvetica, Arial, sans-serif;
         font-size: 12pt; line-height: 1.45; }
  .toolbar {
    position: sticky; top: 0; z-index: 5;
    background: #fff; border-bottom: 1px solid var(--line);
    padding: 12px 24px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
  }
  .toolbar .left { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .toolbar .right { margin-left:auto; display:flex; gap:8px; }
  .btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:8px 16px; border:1px solid var(--line); border-radius:6px;
    background:#fff; color:var(--ink); cursor:pointer;
    font-size: 11pt; font-family: inherit; text-decoration: none;
  }
  .btn-primary { background: var(--accent); color:#fff; border-color: var(--accent); }
  .btn:hover { filter: brightness(.96); }
  select, input[type=date] {
    padding:7px 10px; border:1px solid var(--line); border-radius:6px;
    background:#fff; font-size:11pt; font-family: inherit; color: var(--ink);
  }
  /* Pagewrap sized to render close to actual A4 width (210mm ≈ 794px @ 96dpi).
     Inner padding mirrors print margins so on-screen preview matches paper. */
  .pagewrap { max-width: 800px; margin: 32px auto; padding: 0 16px; }
  .empty { padding: 64px 24px; text-align:center; color: var(--muted); }
  .room-sheet {
    background:#fff; padding: 32px 40px 40px;
    margin-bottom: 24px; border:1px solid var(--line); border-radius:8px;
  }
  .sheet-header {
    display:flex; align-items:center; gap:20px;
    border-bottom: 2px solid var(--accent); padding-bottom: 16px; margin-bottom: 18px;
  }
  .sheet-header img { width:56px; height:56px; object-fit: contain; flex-shrink:0; }
  .sheet-header .meta { flex:1; }
  .sheet-header h1 { margin:0; font-size: 16pt; }
  .sheet-header .school { margin:2px 0 0; font-size:10pt; color: var(--muted); }
  .sheet-header .sy { margin:4px 0 0; font-size: 9pt; color: var(--muted); letter-spacing: .04em; text-transform: uppercase; }
  /* Asymmetric grid: College gets the most width (longest text);
     Room slightly wider so the big number breathes. */
  .room-info {
    display:grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(0, 1.1fr) minmax(0, 0.9fr) minmax(0, 1.3fr);
    gap: 12px;
    margin-bottom: 20px; padding: 14px 16px;
    background: #f3f4f6; border-radius: 6px;
  }
  .room-info .label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
  .room-info .value { font-size: 12pt; font-weight: 600; margin-top: 2px; line-height: 1.25; }
  .room-info .value.lg { font-size: 18pt; color: var(--accent); }
  table.roster { width:100%; border-collapse: collapse; }
  table.roster th, table.roster td {
    padding: 8px 10px; border-bottom: 1px solid var(--line);
    font-size: 11pt; text-align: left; vertical-align: top;
  }
  table.roster th {
    font-size: 9pt; text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); background: #f9fafb; font-weight: 600;
  }
  table.roster td.num { width: 36px; color: var(--muted); text-align: right; }
  table.roster td.name { letter-spacing: .01em; }
  table.roster .empty-row td { color: var(--muted); font-style: italic; padding: 24px 12px; text-align:center; }

  /* ── Print styles ───────────────────────────────────────── */
  @media print {
    body { background: #fff; }
    .toolbar, .no-print { display: none !important; }
    .pagewrap { margin: 0; padding: 0; max-width: none; }
    .room-sheet {
      border: none; border-radius: 0; padding: 0;
      margin: 0; page-break-after: always;
    }
    .room-sheet:last-child { page-break-after: auto; }
    @page { size: A4; margin: 18mm 16mm; }
  }
</style>
</head>
<body>

<!-- ── Toolbar (hidden when printing) ──────────────────────── -->
<div class="toolbar no-print">
    <div class="left">
        <a href="<?= url('/staff/exam/slots') ?>" class="btn">← Back to Slots</a>
        <form method="GET" action="" style="display:flex;gap:8px;align-items:center;margin-left:8px">
            <label style="font-size:10pt;color:var(--muted)">Date:</label>
            <select name="date" onchange="this.form.submit()">
                <option value="">All upcoming</option>
                <?php foreach ($datesAvail as $d): ?>
                    <option value="<?= e($d) ?>" <?= $filterDate === $d ? 'selected' : '' ?>>
                        <?= e(date('M j, Y (D)', strtotime($d))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($isAdmin && $deptsAvail): ?>
                <label style="font-size:10pt;color:var(--muted);margin-left:8px">College:</label>
                <select name="dept" onchange="this.form.submit()">
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
        <a class="btn"
           href="?<?= http_build_query(array_filter(['date' => $filterDate, 'dept' => $filterDept, 'slot' => $filterSlotId, 'format' => 'csv'])) ?>"
           title="Download CSV (for record-keeping / mail merge)">
            ↓ CSV
        </a>
        <button class="btn btn-primary" onclick="window.print()">
            🖨 Print / Save as PDF
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
                <img src="<?= e($logoUrl) ?>" alt="" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="meta">
                <h1>Entrance Exam — Room Assignment</h1>
                <p class="school"><?= e($schoolName) ?></p>
                <p class="sy">School Year <?= e($schoolYear) ?></p>
            </div>
        </div>

        <div class="room-info">
            <div>
                <div class="label">College</div>
                <div class="value"><?= e($slot['department'] ?: '—') ?></div>
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

</body>
</html>
