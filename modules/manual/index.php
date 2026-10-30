<?php
// ============================================================
// modules/manual/index.php
// The staff user manual page. Opened from the profile menu.
// Text lives in content.php, parsed by _parse.php. Students do not
// see it: they follow the steps on their own pages.
// Wide screens: contents list beside the text. Phones: a Jump to select.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireStaffPlus();

require_once __DIR__ . '/_parse.php';

$manual = manual_parse((string) require __DIR__ . '/content.php');

// Tables with fewer rows than this are drawn as a list, not a table.
const MANUAL_LIST_LIMIT = 5;

if (!function_exists('manual_render_block')) {
    function manual_render_block(array $b): string
    {
        switch ($b['type']) {
            case 'h3':
                return '<h3 class="manual-h3">' . manual_inline($b['text']) . '</h3>';

            case 'p':
                return '<p class="manual-p">' . manual_inline($b['text']) . '</p>';

            case 'ul':
            case 'ol':
                $html = '<' . $b['type'] . ' class="manual-list">';
                foreach ($b['items'] as $item) {
                    $html .= '<li>' . manual_inline($item['text']);
                    if ($item['sub']) {
                        $html .= '<ul class="manual-list manual-list-sub">';
                        foreach ($item['sub'] as $sub) {
                            $html .= '<li>' . manual_inline($sub) . '</li>';
                        }
                        $html .= '</ul>';
                    }
                    $html .= '</li>';
                }
                return $html . '</' . $b['type'] . '>';

            case 'table':
                if (count($b['rows']) < MANUAL_LIST_LIMIT) {
                    $html = '<div class="manual-cards">';
                    foreach ($b['rows'] as $row) {
                        $html .= '<div class="manual-card"><div class="manual-card-title">'
                               . manual_inline($row[0] ?? '') . '</div>';
                        foreach (array_slice($row, 1, null, true) as $n => $cell) {
                            if ($cell === '') continue;
                            $html .= '<div class="manual-card-line"><span class="manual-card-label">'
                                   . e($b['head'][$n] ?? '') . ':</span> ' . manual_inline($cell) . '</div>';
                        }
                        $html .= '</div>';
                    }
                    return $html . '</div>';
                }
                $html = '<div class="table-wrap manual-table"><table class="table"><thead><tr>';
                foreach ($b['head'] as $cell) {
                    $html .= '<th>' . e($cell) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($b['rows'] as $row) {
                    $html .= '<tr>';
                    foreach ($row as $cell) {
                        $html .= '<td>' . manual_inline($cell) . '</td>';
                    }
                    $html .= '</tr>';
                }
                return $html . '</tbody></table></div>';
        }
        return '';
    }
}

ob_start();
?>
<div class="manual">

    <nav class="manual-index" aria-label="Manual sections">
        <div class="manual-index-label">Contents</div>
        <?php foreach ($manual['sections'] as $n => $section): ?>
            <a class="manual-index-link<?= $n === 0 ? ' is-active' : '' ?>" href="#<?= e($section['id']) ?>"
               data-manual-link="<?= e($section['id']) ?>"><?= e($section['title']) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="manual-body">

        <div class="manual-jump">
            <label class="form-label" for="manual-jump">Jump to</label>
            <select id="manual-jump" class="form-select">
                <option value="">Choose a section</option>
                <?php foreach ($manual['sections'] as $section): ?>
                    <option value="<?= e($section['id']) ?>"><?= e($section['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php foreach ($manual['intro'] as $block): ?>
            <?= manual_render_block($block) ?>
        <?php endforeach; ?>

        <?php foreach ($manual['sections'] as $section): ?>
            <section class="manual-section" id="<?= e($section['id']) ?>" data-manual-section>
                <h2 class="manual-h2"><?= e($section['title']) ?></h2>
                <?php foreach ($section['blocks'] as $block): ?>
                    <?= manual_render_block($block) ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

    </div>
</div>
<?php
$content = ob_get_clean();

$pageTitle      = 'User Manual';
$pageStyles     = ['css/pages/manual.css'];
$pageScripts    = ['js/pages/manual.js'];
require_once VIEWS_PATH . '/partials/page_header.php';
$pageBreadcrumb = [page_home_crumb()];
$activeNav      = 'manual';
include VIEWS_PATH . '/layouts/app.php';
