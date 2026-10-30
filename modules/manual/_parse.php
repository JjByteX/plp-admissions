<?php
// ============================================================
// modules/manual/_parse.php
// Turns the manual's markdown text (content.php) into plain blocks,
// and turns inline **bold** and [text](target) into safe HTML.
// Handles only what the manual uses: headings, paragraphs, bullet and
// numbered lists (one nested level), pipe tables, bold and links.
// ============================================================

if (!function_exists('manual_slug')) {

    function manual_slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }

    function manual_split_cells(string $line): array
    {
        $line = trim($line);
        $line = (string) preg_replace('/^\||\|$/', '', $line);
        return array_map('trim', explode('|', $line));
    }

    function manual_list_match(string $line): ?array
    {
        if (preg_match('/^(\s*)(-|\d+\.)\s+(\S.*)?$/', $line, $m)) {
            return [strlen($m[1]), $m[2], $m[3] ?? ''];
        }
        return null;
    }

    function manual_starts_block(string $line): bool
    {
        $t = trim($line);
        return $t === '' || $t === '---' || str_starts_with($t, '#')
            || str_starts_with($t, '|') || manual_list_match($line) !== null;
    }

    function manual_blocks(string $source): array
    {
        $lines  = explode("\n", str_replace("\r\n", "\n", $source));
        $count  = count($lines);
        $blocks = [];
        $i      = 0;

        while ($i < $count) {
            $line = $lines[$i];
            $t    = trim($line);

            if ($t === '' || $t === '---') {
                $i++;
            } elseif (str_starts_with($line, '# ')) {
                $i++; // the page has its own heading, so the title line is dropped
            } elseif (str_starts_with($line, '## ')) {
                $text     = trim(substr($line, 3));
                $blocks[] = ['type' => 'h2', 'text' => $text, 'id' => manual_slug($text)];
                $i++;
            } elseif (str_starts_with($line, '### ')) {
                $blocks[] = ['type' => 'h3', 'text' => trim(substr($line, 4))];
                $i++;
            } elseif (str_starts_with($t, '|')) {
                $rows = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                    $rows[] = $lines[$i];
                    $i++;
                }
                $body = [];
                foreach (array_slice($rows, 2) as $row) {
                    $body[] = manual_split_cells($row);
                }
                $blocks[] = ['type' => 'table', 'head' => manual_split_cells($rows[0] ?? ''), 'rows' => $body];
            } elseif (manual_list_match($line) !== null) {
                $kind  = ctype_digit($t[0]) ? 'ol' : 'ul';
                $items = [];
                while ($i < $count) {
                    $m = manual_list_match($lines[$i]);
                    if ($m === null) break;
                    [$indent, $marker, $text] = $m;
                    $last = count($items) - 1;
                    if ($indent > 0 && $last >= 0) {
                        $items[$last]['sub'][] = $text;
                    } elseif ((ctype_digit($marker[0]) ? 'ol' : 'ul') === $kind) {
                        $items[] = ['text' => $text, 'sub' => []];
                    } else {
                        break;
                    }
                    $i++;
                }
                $blocks[] = ['type' => $kind, 'items' => $items];
            } else {
                $parts = [];
                while ($i < $count && !manual_starts_block($lines[$i])) {
                    $parts[] = trim($lines[$i]);
                    $i++;
                }
                $blocks[] = ['type' => 'p', 'text' => implode(' ', $parts)];
            }
        }

        return $blocks;
    }

    /** Returns ['intro' => blocks before the first section, 'sections' => [['id','title','blocks']]]. */
    function manual_parse(string $source): array
    {
        $intro    = [];
        $sections = [];

        foreach (manual_blocks($source) as $block) {
            if ($block['type'] === 'h2') {
                $sections[] = ['id' => $block['id'], 'title' => $block['text'], 'blocks' => []];
            } elseif ($sections) {
                $sections[count($sections) - 1]['blocks'][] = $block;
            } else {
                $intro[] = $block;
            }
        }

        return ['intro' => $intro, 'sections' => $sections];
    }

    /** **bold** and [text](#section) or [text](/page), everything else escaped. */
    function manual_inline(string $text): string
    {
        $parts = preg_split('/(\*\*.+?\*\*|\[[^\]]+\]\([^)]+\))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';

        foreach ($parts as $n => $part) {
            if ($n % 2 === 0) {
                $out .= e($part);
            } elseif (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $part, $m)) {
                $href = $m[2];
                if (str_starts_with($href, '/')) {
                    $href = url($href);
                } elseif (!str_starts_with($href, '#')) {
                    $out .= e($part);
                    continue;
                }
                $out .= '<a href="' . e($href) . '">' . e($m[1]) . '</a>';
            } else {
                $out .= '<strong>' . e(substr($part, 2, -2)) . '</strong>';
            }
        }

        return $out;
    }
}
