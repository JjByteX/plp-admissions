<?php
// ============================================================
// core/helpers/ui.php
// Page UI helpers: icons, pagination, segmented tabs, sortable headers, auto-paginated tables.
// Loaded by core/helpers.php; plain functions, unchanged.
// ============================================================

// -- Icon helper (Phosphor, CDN) --------------------------------
// <link> tags for the two Phosphor weights we use: bold + fill.
// Echo phosphor_head() inside the <head> of every layout.
function phosphor_head(): string
{
    $b = 'https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src';
    // The icon fonts are font-display:block, so icons stay invisible until the
    // font arrives. Preloading them starts that download with the page, not
    // after the CSS is parsed, so icons are there on first paint.
    return '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>' . "\n"
         . '    <link rel="preload" as="font" type="font/woff2" '
         . 'href="' . $b . '/bold/Phosphor-Bold.woff2" crossorigin>' . "\n"
         . '    <link rel="preload" as="font" type="font/woff2" '
         . 'href="' . $b . '/fill/Phosphor-Fill.woff2" crossorigin>' . "\n"
         . '    <link rel="stylesheet" href="' . $b . '/bold/style.css">' . "\n"
         . '    <link rel="stylesheet" href="' . $b . '/fill/style.css">';
}

// icon('bell', 18)            -> bold  (default)
// icon('bell:fill', 18)       -> fill
// icon('ic_fluent_..._24_regular') still works: mapped to Phosphor below.
// $extra = extra HTML attributes e.g. 'id="foo" class="bar"'
function icon(string $name, int $size = 16, string $style = '', string $extra = ''): string
{
    // Fluent name => Phosphor name (kept so the ~250 existing call sites don't change)
    static $map = [
        'add' => 'plus', 'arrow_down' => 'arrow-down', 'arrow_up' => 'arrow-up',
        'arrow_left' => 'arrow-left', 'arrow_download' => 'download-simple',
        'arrow_upload' => 'upload-simple', 'arrow_sync' => 'arrows-clockwise',
        'arrow_undo' => 'arrow-u-up-left', 'building_bank' => 'bank',
        'calendar' => 'calendar-blank', 'calendar_ltr' => 'calendar-blank',
        'calendar_add' => 'calendar-plus', 'calendar_cancel' => 'calendar-x',
        'calendar_checkmark' => 'calendar-check', 'calendar_sync' => 'calendar-dots',
        'checkmark' => 'check', 'checkmark_circle' => 'check-circle',
        'chevron_left' => 'caret-left', 'chevron_right' => 'caret-right',
        'chevron_up_down' => 'caret-up-down', 'clipboard' => 'clipboard-text',
        'clock' => 'clock', 'delete' => 'trash', 'dismiss' => 'x',
        'dismiss_circle' => 'x-circle', 'document' => 'file-text',
        'document_checkmark' => 'file-check', 'edit' => 'pencil-simple',
        'eye' => 'eye', 'eye_show' => 'eye', 'filter' => 'funnel',
        'home' => 'house', 'hourglass' => 'hourglass', 'image' => 'image',
        'info' => 'info', 'library' => 'books', 'location' => 'map-pin',
        'lock_closed' => 'lock', 'mail' => 'envelope-simple',
        'more_horizontal' => 'dots-three', 'people' => 'users',
        'question_circle' => 'question', 'ribbon_star' => 'medal',
        'save' => 'floppy-disk', 'search' => 'magnifying-glass',
        'settings' => 'gear', 'shield' => 'shield-check', 'sign_out' => 'sign-out',
        'signed' => 'signature', 'sparkle' => 'sparkle', 'subtract' => 'minus',
        'text_bullet_list_add' => 'list-plus', 'warning' => 'warning',
        'weather_moon' => 'moon', 'weather_sunny' => 'sun',
    ];
    // Legacy names that read better filled (status / emphasis icons)
    static $fill = [
        'checkmark_circle', 'dismiss_circle', 'warning', 'info', 'question_circle',
        'ribbon_star', 'sparkle', 'building_bank', 'shield', 'lock_closed', 'filter',
    ];

    if (preg_match('/^ic_fluent_(.+)_24_(?:regular|filled)$/', $name, $m)) {
        if (!isset($map[$m[1]])) {
            return '';
        }
        $ph     = $map[$m[1]];
        $weight = in_array($m[1], $fill, true) ? 'fill' : 'bold';
    } else {
        [$ph, $weight] = array_pad(explode(':', $name, 2), 2, 'bold');
        if ($weight !== 'fill') {
            $weight = 'bold';
        }
    }

    $cls = 'ph-' . $weight . ' ph-' . $ph;
    if (preg_match('/\bclass="([^"]*)"/', $extra, $c)) {
        $cls  .= ' ' . $c[1];
        $extra = str_replace($c[0], '', $extra);
    }
    $out = '<i class="' . $cls . '" aria-hidden="true" style="font-size:' . $size . 'px'
         . ($style ? ';' . $style : '') . '"';
    if (trim($extra) !== '') {
        $out .= ' ' . trim($extra);
    }
    return $out . '></i>';
}

// -- Pagination -------------------------------------------------
function paginate(PDO $pdo, string $countSql, string $dataSql, array $params, int $page, int $perPage = 20): array
{
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pages = (int) ceil($total / $perPage);
    $page  = max(1, min($page, $pages ?: 1));

    $stmt = $pdo->prepare($dataSql . " LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'data'         => $stmt->fetchAll(),
        'total'        => $total,
        'current_page' => $page,
        'last_page'    => $pages,
        'per_page'     => $perPage,
    ];
}

/**
 * Segmented control used for page tabs. Renders as links so every tab is a
 * normal page load / bookmarkable URL. Put it as the FIRST item of a table
 * toolbar (left of the search box).
 *
 * $tabs = [ key => ['label' => 'Absent', 'href' => '?tab=absent',
 *                   'count' => 3 (optional), 'tone' => 'warning' (optional)] ]
 */
function segmented_tabs(array $tabs, string $active, string $ariaLabel = 'Views'): string
{
    $html = '<div class="seg-control" role="tablist" aria-label="' . htmlspecialchars($ariaLabel) . '">';
    foreach ($tabs as $key => $t) {
        $isActive = ((string)$key === $active);
        $count = $t['count'] ?? null;
        $html .= '<a href="' . htmlspecialchars($t['href']) . '" role="tab" '
               . 'aria-selected="' . ($isActive ? 'true' : 'false') . '"'
               . ' class="seg-control-item' . ($isActive ? ' active' : '') . '">'
               . htmlspecialchars($t['label']);
        if ($count !== null && (int)$count > 0) {
            $tone = !empty($t['tone']) ? ' seg-control-count--' . htmlspecialchars($t['tone']) : '';
            $html .= '<span class="seg-control-count' . $tone . '">' . (int)$count . '</span>';
        }
        $html .= '</a>';
    }
    return $html . '</div>';
}

/**
 * Sortable table header link, shared by any staff listing page with
 * click-to-sort columns (results, documents, etc). $extraParams carries
 * whatever filters that page needs to preserve in the URL (search term,
 * status filter, page number, ...) alongside the sort change itself.
 */
function sortable_th(
    string $col,
    string $label,
    string $currentCol,
    string $currentDir,
    array $extraParams = [],
    string $thStyle = ''
): string
{
    $isActive = ($currentCol === $col);
    $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $params   = array_merge($extraParams, ['sort_col' => $col, 'sort_dir' => $isActive ? $nextDir : 'asc']);
    $url      = '?' . http_build_query($params);

    $sortIcon  = icon('ic_fluent_chevron_up_down_24_filled', 13);
    $sortColor = $isActive ? 'var(--accent)' : 'var(--text-tertiary)';

    return '<th' . ($thStyle !== '' ? ' style="' . htmlspecialchars($thStyle) . '"' : '') . '>'
         . '<a href="' . $url . '" '
         . 'style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;'
         . 'color:inherit;white-space:nowrap;">'
         . htmlspecialchars($label)
         . '<span class="flex align-items-center" style="color:' . $sortColor . ';margin-left:2px">'
         . $sortIcon . '</span>'
         . '</a></th>';
}

// ================================================================
// Auto-paginated tables (.auto-table-*): shared helpers
// ================================================================

/**
 * Rows per page for an auto-paginated table. AutoPageSize (app.js) measures
 * how many fixed-height rows fit and reloads with ?per_page=<rows that fit>.
 *
 * $key is the table's data-auto-page-size value (e.g. 'doc-review'). Order:
 *   1. ?per_page (AutoPageSize's own reload, carried by every page link)
 *   2. the plp_auto_page_size_<key> cookie, the last size AutoPageSize
 *      measured in this browser, so a plain visit renders at the right size
 *      instead of at the fallback and then reloading
 *   3. $fallback, only the very first visit
 * The cookie name is built exactly as app.js builds it: 'plp_auto_page_size:'
 * + key, with every character but letters, digits and _ turned into _.
 * Clamped to [3, 100], the range AutoPageSize enforces, so a hand-edited URL
 * or cookie can't request an unbounded row count.
 */
function auto_per_page(string $key, int $fallback = 50): int
{
    if (isset($_GET['per_page'])) {
        $n = (int) $_GET['per_page'];
    } else {
        $cookie = preg_replace('/[^a-zA-Z0-9_]/', '_', 'plp_auto_page_size:' . $key);
        $n = (int) ($_COOKIE[$cookie] ?? 0);
    }
    if ($n <= 0) {
        $n = $fallback;
    }
    return max(3, min(100, $n));
}

/**
 * Pagination bar for an auto-paginated table: "Showing X–Y of Z" + prev / next.
 * Render it UNDER the .auto-table-card but INSIDE the .auto-table-wrap, and only
 * when there is more than one page (its 40px is reserved by AutoPageSize either way).
 * $result = paginate()'s return value. $urlFor = fn(int $page): string.
 */
function auto_table_footer(array $result, callable $urlFor, string $noun = 'records'): string
{
    $total = (int) $result['total'];
    $page  = (int) $result['current_page'];
    $pages = max(1, (int) $result['last_page']);
    $per   = (int) $result['per_page'];
    $from  = $total === 0 ? 0 : ($page - 1) * $per + 1;
    $to    = min($page * $per, $total);

    $btn = function (bool $enabled, int $target, string $iconName, string $label) use ($urlFor): string {
        $ic = icon($iconName, 16);
        return $enabled
            ? '<a href="' . htmlspecialchars($urlFor($target)) . '" class="auto-table-page-btn" '
              . 'aria-label="' . $label . '">' . $ic . '</a>'
            : '<span class="auto-table-page-btn" aria-disabled="true" aria-label="' . $label . '">' . $ic . '</span>';
    };

    $strong = 'style="color:var(--text-primary);font-weight:var(--weight-semibold)"';
    return '<div class="auto-table-pagination">'
         . '<p class="auto-table-pagination-info m-0">Showing <strong>'
         . number_format($from) . '–' . number_format($to)
         . '</strong> of <strong>' . number_format($total) . '</strong> ' . htmlspecialchars($noun) . '</p>'
         . '<div class="auto-table-pagination-controls">'
         . $btn($page > 1, $page - 1, 'ic_fluent_chevron_left_24_regular', 'Previous page')
         . '<span class="py-0 px-1 text-sm text-nowrap" style="'
         . 'color:var(--text-tertiary)">'
         . 'Page <strong ' . $strong . '>' . $page . '</strong> of '
         . '<strong ' . $strong . '>' . $pages . '</strong></span>'
         . $btn($page < $pages, $page + 1, 'ic_fluent_chevron_right_24_regular', 'Next page')
         . '</div></div>';
}
