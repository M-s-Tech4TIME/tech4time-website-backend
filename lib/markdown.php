<?php
/**
 * Tech4TIME — legal documents as Markdown, rendered to HTML.
 *
 * SHARED FILE. Byte-identical in tech4time-website-frontend and
 * tech4time-website-backend; tools/check_shared_lib.py compares it against a
 * committed digest, and tools/test_markdown.py asserts byte-identical OUTPUT
 * from both copies on the same vectors. Digests prove sameness, vectors prove
 * correctness: one library agreeing with itself would prove nothing, which is
 * why the suite runs in both repositories rather than one.
 *
 * WHY THIS IS WRITTEN BY HAND
 * No DOM extension on this host and no package manager in this project, same
 * as lib/html.php -- so this parses the source itself, line by line, with no
 * recursion deeper than inline nesting (capped) and no pattern that can
 * backtrack. A 10MB input is linear scans, not a hang.
 *
 * HOW IT STAYS SAFE WITHOUT A PARSER
 * The same discipline as rt_sanitise_html(): it never passes anything
 * through. Every text run is entity-decoded and re-escaped, every link URL is
 * validated against the same shape rt_safe_href() accepts, and every tag in
 * the output is written by this file from a fixed vocabulary -- p, br,
 * strong, em, u, a, ul, ol, li, table, thead, tbody, tr, th, td, and divs
 * carrying exactly legal__notice or ta-center. Anything else in the source --
 * a tag, raw HTML, an unknown container -- is escaped to visible text. The
 * output cannot contain a construct this file does not explicitly emit.
 *
 * WHAT IT IS NOT
 * Not CommonMark: no headings (a # renders literally -- headings are section
 * fields, and heading levels are a landmark contract), no nested lists, no
 * code spans, no autolinks, no hard breaks. Not GFM either, except tables,
 * which are strict: a malformed table renders as literal pipe-text rather
 * than a guessed table, because a mangled schedule on a legal page must be
 * visible, not silently repaired. The frozen dialect, with every edge ruled,
 * is tech4time-website-frontend/plans/legal-markdown-syntax.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';

/** Inline nesting deeper than this renders literally rather than recursing. */
const MD_MAX_DEPTH = 16;

/** Container names the dialect knows. Anything else degrades to text. */
const MD_CONTAINERS = ['note' => true, 'center' => true];

/**
 * Render a Markdown document to HTML.
 *
 * Blocks are flat siblings, the way privacy_block() emits them: no wrapper
 * per section, because assets/css/pages/legal.css zeroes the first heading's
 * top margin with a child combinator a wrapper would silently break.
 */
function md_render(string $src): string
{
    $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];
    $i = 0;
    $out = md_blocks($lines, $i);
    return trim(implode("\n", $out));
}

/**
 * Render blocks from lines starting at $i, advancing it past what was read.
 *
 * $stopAtFence makes a ::: line end the run instead of opening a container,
 * which is what keeps containers from nesting: the inner fence is left for
 * the caller to meet, and it meets it as literal text.
 */
function md_blocks(array $lines, int &$i, bool $stopAtFence = false): array
{
    $out = [];
    $n = count($lines);

    while ($i < $n) {
        $line = $lines[$i];

        if (trim($line) === '') {
            $i++;
            continue;
        }

        /* A closing fence is bare -- ::: and nothing else. Inside a container,
           a NAMED fence is an opener that must not nest, so it stays literal
           text and gathering continues; only the bare fence ends the run. */
        if (preg_match('/^\s{0,3}:::\s*([A-Za-z]*)\s*$/', $line, $m)) {
            if ($stopAtFence && strtolower($m[1]) === '') {
                return $out;
            }
            if ($stopAtFence) {
                $out[] = '<p>' . md_inline($line, 0) . '</p>';
                $i++;
                continue;
            }
            $name = strtolower($m[1]);
            $i++;
            if ($name === '' || !isset(MD_CONTAINERS[$name])) {
                /* A bare fence, or one this dialect does not know: literal,
                   fences included, so the failure is visible. */
                $out[] = '<p>' . md_inline($line, 0) . '</p>';
                continue;
            }
            $inner = md_blocks($lines, $i, true);
            if ($i >= $n) {
                /* Never closed: the opener was text after all. */
                $out[] = '<p>' . md_inline($line, 0) . '</p>';
                foreach ($inner as $block) {
                    $out[] = $block;
                }
                continue;
            }
            /* Consume the closing fence and wrap what was gathered. */
            $i++;
            $class = $name === 'note' ? 'legal__notice' : 'ta-center';
            $out[] = '<div class="' . $class . '">' . "\n"
                   . implode("\n", $inner) . "\n" . '</div>';
            continue;
        }

        /* A table: a pipe line immediately followed by a delimiter row. A
           row count that disagrees makes md_table return null -- and that
           line is then literal text, not a second chance at prose: without
           consuming it here the paragraph loop below would refuse it for the
           same reason and spin forever. Strict means literal, whole. */
        if (str_contains($line, '|') && $i + 1 < $n && md_delimiter($lines[$i + 1]) !== null) {
            $table = md_table($lines, $i);
            if ($table !== null) {
                $out[] = $table;
            } else {
                $out[] = '<p>' . md_inline($line, 0) . '</p>';
                $i++;
            }
            continue;
        }

        /* A list: markers at column 0-3, one level, blank line ends it. */
        if (preg_match('/^( {0,3})(-|\d+\.)[ \t]+(.*)$/', $line, $m)) {
            $ordered = $m[2] !== '-';
            $items = [];
            while ($i < $n && preg_match('/^( {0,3})(-|\d+\.)[ \t]+(.*)$/', $lines[$i], $mm)) {
                $items[] = $mm[3];
                $i++;
            }
            $tag = $ordered ? 'ol' : 'ul';
            $lis = '';
            foreach ($items as $item) {
                $lis .= '<li>' . md_inline($item, 0) . '</li>';
            }
            $out[] = '<' . $tag . '>' . $lis . '</' . $tag . '>';
            continue;
        }

        /* A paragraph: consecutive plain lines, single newlines joining. A
           pipe line followed by a delimiter row starts a table, not a longer
           paragraph -- the delimiter is what decides, so it is checked before
           the line is consumed. */
        $buf = [];
        while ($i < $n
            && trim($lines[$i]) !== ''
            && !preg_match('/^\s{0,3}:::\s*([A-Za-z]*)\s*$/', $lines[$i])
            && !preg_match('/^( {0,3})(-|\d+\.)[ \t]+/', $lines[$i])
            && !(str_contains($lines[$i], '|') && $i + 1 < $n && md_delimiter($lines[$i + 1]) !== null)
        ) {
            $buf[] = $lines[$i];
            $i++;
        }
        if ($buf !== []) {
            $out[] = '<p>' . md_inline(implode("\n", $buf), 0) . '</p>';
        }
    }

    return $out;
}

/**
 * Parse a delimiter row, returning per-column alignment or null.
 *
 * Columns are left (default, no class), center, or right. At least one
 * --- group of three or more dashes, and nothing but pipes, colons, dashes
 * and spaces, or this is not a delimiter row at all.
 */
function md_delimiter(string $line): ?array
{
    $cells = md_split_row($line);
    if ($cells === null || $cells === []) {
        return null;
    }
    $align = [];
    foreach ($cells as $cell) {
        $cell = trim($cell);
        if (!preg_match('/^:?-+-+:?$/', $cell) || strlen(trim($cell, ':')) < 3) {
            return null;
        }
        $left = str_starts_with($cell, ':');
        $right = str_ends_with($cell, ':');
        $align[] = ($left && $right) ? 'ta-center' : ($left ? 'ta-left' : ($right ? 'ta-right' : ''));
    }
    return $align;
}

/**
 * Split a table row on pipes. Outer pipes make empty edge cells, which are
 * dropped; a missing outer pipe is fine. Returns null when there is no pipe
 * to split on. Pipes cannot be escaped -- a cell needing one is prose that
 * should not be a table, and the row will mismatch the header and render
 * literally rather than half-built.
 */
function md_split_row(string $line): ?array
{
    if (!str_contains($line, '|')) {
        return null;
    }
    $cells = explode('|', $line);
    if (trim($cells[0]) === '') {
        array_shift($cells);
    }
    if ($cells !== [] && trim($cells[count($cells) - 1]) === '') {
        array_pop($cells);
    }
    return $cells;
}

/**
 * Render a table starting at $i (header) and $i+1 (delimiter), advancing $i
 * past the last body row. Returns null -- leaving $i untouched -- when any
 * row's cell count differs from the header's: strict means literal, whole.
 */
function md_table(array $lines, int &$i): ?string
{
    $align = md_delimiter($lines[$i + 1]);
    $head = md_split_row($lines[$i]);
    if ($align === null || $head === null || count($head) !== count($align) || $head === []) {
        return null;
    }

    $rows = [];
    $j = $i + 2;
    $n = count($lines);
    while ($j < $n && trim($lines[$j]) !== '' && str_contains($lines[$j], '|')) {
        $cells = md_split_row($lines[$j]);
        if ($cells === null || count($cells) !== count($head)) {
            return null;
        }
        $rows[] = $cells;
        $j++;
    }

    $out = '<div class="legal__table-wrap"><table class="legal__table"><thead><tr>';
    foreach ($head as $c => $cell) {
        $out .= '<th scope="col"' . md_align_attr($align[$c]) . '>'
              . md_inline(trim($cell), 0) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $cells) {
        $out .= '<tr>';
        foreach ($cells as $c => $cell) {
            $out .= '<td' . md_align_attr($align[$c]) . '>'
                  . md_inline(trim($cell), 0) . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';

    $i = $j;
    return $out;
}

/** A class attribute for a delimiter alignment, or nothing for default. */
function md_align_attr(string $align): string
{
    return $align === '' ? '' : ' class="' . $align . '"';
}

/**
 * Render inline Markdown: emphasis, links, underline, escapes.
 *
 * Single pass with an explicit depth cap instead of open recursion: nesting
 * deeper than MD_MAX_DEPTH renders literally rather than recursing. Newlines
 * join as spaces, except inside ++underline++ -- an underline spanning a line
 * break is a typo, and rendering it would join the paragraphs.
 */
function md_inline(string $src, int $depth): string
{
    if ($depth > MD_MAX_DEPTH) {
        return h($src);
    }

    $out = '';
    $i = 0;
    $n = strlen($src);

    while ($i < $n) {
        $c = $src[$i];

        /* Escapes: \*, \_, \+, \[, \], \|, \\, \`, \#, \! -- the characters
           this dialect gives meaning, so prose needing them literally has a
           way. Anything else keeps its backslash. */
        if ($c === '\\' && $i + 1 < $n && str_contains('*_+[]|\\`#!', $src[$i + 1])) {
            $out .= h($src[$i + 1]);
            $i += 2;
            continue;
        }

        /* Strong. */
        if ($c === '*' && $i + 1 < $n && $src[$i + 1] === '*') {
            $span = md_span($src, $i + 2, '**', $depth);
            if ($span !== null) {
                $out .= '<strong>' . $span[0] . '</strong>';
                $i = $span[1];
                continue;
            }
            $out .= h('**');
            $i += 2;
            continue;
        }

        /* Emphasis. A lone * that opens ** belongs to the pair above. */
        if ($c === '*') {
            $span = md_span($src, $i + 1, '*', $depth);
            if ($span !== null) {
                $out .= '<em>' . $span[0] . '</em>';
                $i = $span[1];
                continue;
            }
            $out .= h('*');
            $i++;
            continue;
        }

        /* Underline. Never spans a line break; empty never opens. A lone +
           is text, not the start of markup. */
        if ($c === '+' && $i + 1 < $n && $src[$i + 1] === '+') {
            $span = md_span($src, $i + 2, '++', $depth);
            if ($span !== null && !str_contains($span[2], "\n")) {
                $out .= '<u>' . $span[0] . '</u>';
                $i = $span[1];
                continue;
            }
            $out .= h('++');
            $i += 2;
            continue;
        }
        if ($c === '+' || $c === '\\') {
            $out .= h($c);
            $i++;
            continue;
        }

        /* Links. */
        if ($c === '[') {
            $link = md_link($src, $i, $depth);
            if ($link !== null) {
                $out .= $link[0];
                $i = $link[1];
                continue;
            }
            $out .= h('[');
            $i++;
            continue;
        }

        /* A newline joins the paragraph with a space. */
        if ($c === "\n") {
            $out .= ' ';
            $i++;
            continue;
        }

        /* Text to the next interesting character, decoded then re-escaped so
           an entity in the source is not encoded twice and a raw bracket in
           the source cannot survive. */
        $j = $i;
        while ($j < $n && $src[$j] !== '\\' && $src[$j] !== '*'
            && $src[$j] !== '+' && $src[$j] !== '[' && $src[$j] !== "\n") {
            $j++;
        }
        $out .= h(html_entity_decode(substr($src, $i, $j - $i), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $i = $j;
    }

    return $out;
}

/**
 * Read a span closed by $marker from $from, returning [rendered, end, raw].
 *
 * The opener must not be followed by whitespace and the closer must not be
 * preceded by it; an empty span never opens. For '*' the closer must not be
 * part of a '**' pair in either direction, so bold and italic cannot steal
 * each other's asterisks. Raw is the unrendered middle, which is what lets
 * ++ reject a span crossing a line break.
 */
function md_span(string $src, int $from, string $marker, int $depth): ?array
{
    $n = strlen($src);
    $mlen = strlen($marker);

    if ($from >= $n || $src[$from] === ' ' || $src[$from] === "\t" || $src[$from] === "\n") {
        return null;
    }

    $j = $from;
    while (true) {
        $at = strpos($src, $marker[0], $j);
        if ($at === false) {
            return null;
        }
        if ($mlen === 2) {
            if ($at + 1 >= $n || $src[$at + 1] !== $marker[1]) {
                $j = $at + 1;
                continue;
            }
            $end = $at + 2;
        } else {
            /* Single *: skip a pair's halves -- "**" opens strong above, and
               a closer glued to one ("a**") belongs to nothing. */
            if (($at > 0 && $src[$at - 1] === '*') || ($at + 1 < $n && $src[$at + 1] === '*')) {
                $j = $at + 1;
                continue;
            }
            $end = $at + 1;
        }
        $prev = $src[$end - $mlen - 1] ?? ' ';
        if ($prev === ' ' || $prev === "\t" || $prev === "\n") {
            $j = $end;
            continue;
        }
        $raw = substr($src, $from, $end - $mlen - $from);
        if ($raw === '') {
            return null;
        }
        return [md_inline($raw, $depth + 1), $end, $raw];
    }
}

/**
 * Read [label](url) from $i, returning [anchor, end] or null.
 *
 * Brackets nest by depth: in [a [b](c)](d) the FIRST ] belongs to the inner
 * pair, so the outer close is the ] that returns depth to zero. A label that
 * still holds link syntax then keeps the whole span literal instead of
 * nesting anchors, which HTML forbids. The URL admits exactly what
 * rt_safe_href() would keep -- https?, mailto:, tel:, a same-document
 * fragment, or a site-relative path -- after control characters are
 * stripped. Anything else, and the whole span stays literal text, brackets
 * included, which is what the editor's link guard promises the save keeps.
 */
function md_link(string $src, int $i, int $depth): ?array
{
    $n = strlen($src);
    $level = 0;
    $close = null;
    for ($j = $i + 1; $j < $n; $j++) {
        if ($src[$j] === '[') {
            $level++;
        } elseif ($src[$j] === ']') {
            if ($level === 0) {
                $close = $j;
                break;
            }
            $level--;
        }
    }
    if ($close === null || $close + 1 >= $n || $src[$close + 1] !== '(') {
        return null;
    }
    $end = strpos($src, ')', $close + 2);
    if ($end === false) {
        return null;
    }

    $href = preg_replace('/[\x00-\x20\x7F]/', '', (string)html_entity_decode(
        substr($src, $close + 2, $end - $close - 2), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
    if ($href === '' || !md_safe_href($href)) {
        return null;
    }

    $label = substr($src, $i + 1, $close - $i - 1);
    if ($label === '') {
        return null;
    }
    /* A label with its own link syntax keeps the outer span literal instead
       of nesting anchors, which HTML forbids. */
    if (str_contains($label, '](')) {
        return null;
    }

    $anchor = '<a href="' . h($href) . '"';
    if (preg_match('#^https?://#i', $href)) {
        $anchor .= ' target="_blank" rel="noopener noreferrer"';
    }
    $anchor .= '>' . md_inline($label, $depth + 1) . '</a>';

    return [$anchor, $end + 1];
}

/** Whether an href survives -- the same shape rt_safe_href() keeps. */
function md_safe_href(string $href): bool
{
    if (preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
        return true;
    }
    if (preg_match('/^#[A-Za-z][A-Za-z0-9_:.-]*$/', $href)) {
        return true;
    }
    return str_starts_with($href, '/') && !str_starts_with($href, '//');
}
