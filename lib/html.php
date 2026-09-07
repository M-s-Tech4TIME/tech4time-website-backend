<?php
/**
 * Tech4TIME — escaping and rich-text sanitising.
 *
 * Shared by every server-rendered page and by the admin. Not reachable over
 * HTTP: .htaccess forbids /lib/.
 *
 * This started inside lib/careers.php and moved here when the contact page
 * gained an editor of its own. Nothing about it was ever specific to job
 * posts; careers.php keeps its old function names as one-line aliases so the
 * move changed no caller.
 *
 * WHY THIS IS WRITTEN BY HAND
 * No DOM extension on this host — DOMDocument does not exist, the same way
 * mb_strlen did not. So this parses the markup itself.
 *
 * HOW IT STAYS SAFE WITHOUT A PARSER
 * It never passes anything through. It walks the input, and for each tag it
 * recognises it WRITES A NEW ONE from an allow-list of names and attributes.
 * Anything it does not recognise — a tag, an attribute, a stray angle bracket
 * — is discarded rather than copied. So the output cannot contain a construct
 * this file does not explicitly know how to emit, which is a much smaller
 * thing to get right than trying to spot every dangerous input.
 *
 * WHY NO style ATTRIBUTE
 * The site's CSP is style-src 'self', which blocks inline styles. An editor
 * that wrote style="text-align:center" would look correct in the admin and do
 * nothing at all on the public page. Alignment is therefore a class from a
 * fixed list, which is also why the class attribute is allow-listed by value
 * and not merely by name.
 */

declare(strict_types=1);

/* Tag => whether it may contain text (block/inline) rather than being empty. */
const RT_ALLOWED_TAGS = [
    'p' => true, 'br' => false, 'strong' => true, 'em' => true, 'u' => true,
    'ul' => true, 'ol' => true, 'li' => true, 'a' => true,
];

/* Editors emit these; they mean the same thing as the tags we keep. */
const RT_TAG_ALIASES = ['b' => 'strong', 'i' => 'em', 'div' => 'p'];

/**
 * Elements whose CONTENT must go with them.
 *
 * Dropping the tags of <script>alert(1)</script> and keeping what was between
 * them leaves "alert(1)" sitting in the page as visible text. Escaped, so it
 * cannot run — but it is not text anyone wrote, and it should not appear.
 * These are skipped whole, contents included.
 */
const RT_DROP_CONTENT_TAGS = [
    'script', 'style', 'textarea', 'title', 'noscript', 'template',
    'iframe', 'object', 'embed', 'svg', 'math', 'head', 'select', 'option',
];

/** The only class values that survive, and the only ones the CSS styles. */
/**
 * The tags allowed where a BLOCK would be wrong.
 *
 * A list item's text is rendered inside an <li>, an address's inside an
 * <address>, and a paragraph's inside a <p> the renderer supplies. In all
 * three, a <p> or a <ul> arriving from the editor is not emphasis somebody
 * added -- it is a stray Enter, and it nests a list inside a list item or a
 * paragraph inside a paragraph. Pressing Enter is the normal thing to do, so
 * this is not a corner case; it is Tuesday.
 */
const RT_INLINE_TAGS = ['br' => false, 'strong' => true, 'em' => true, 'u' => true, 'a' => true];

const RT_ALLOWED_CLASSES = ['ta-left', 'ta-center', 'ta-right', 'ta-justify'];

/** Tags that may carry an alignment class. */
const RT_ALIGNABLE = ['p', 'li', 'ul', 'ol'];

/* ----------------------------------------------------------------- escaping */

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* --------------------------------------------------------------------- URLs */

function rt_safe_href(string $href): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    /* Control characters are how "java\tscript:" gets past a naive check. */
    $href = preg_replace('/[\x00-\x20\x7F]/', '', $href) ?? '';

    if ($href === '') {
        return null;
    }
    if (preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
        return $href;
    }
    /* A SAME-DOCUMENT FRAGMENT. It navigates nowhere and cannot execute, and
       without it an in-page link does not merely lose its href -- it keeps the
       <a>, so .legal__body a still paints it underlined and accent-coloured
       and it looks exactly like a link that does nothing. The privacy policy
       is the first page here whose prose links into itself. */
    if (preg_match('/^#[A-Za-z][A-Za-z0-9_:.-]*$/', $href)) {
        return $href;
    }
    /* Site-relative links are fine; anything else — javascript:, data:, vbscript: — is not. */
    if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
        return $href;
    }
    return null;
}

/* --------------------------------------------------------------- sanitising */

function rt_sanitise_html(string $html, ?array $allowed = null): string
{
    $allowed = $allowed ?? RT_ALLOWED_TAGS;
    $out = '';
    $open = [];
    $skip = '';      /* set to a tag name while its content is being discarded */
    $depth = 0;

    /* Split on tags, keeping them, so text and markup alternate.
       The quoted-string alternation matters: without it this splits at the
       first ">" even when it sits inside an attribute value, and the tail of
       the tag spills out as text. It would still be escaped — this parser
       degrades to inert text, never to markup — but it would be text nobody
       typed. */
    $tokens = preg_split(
        '/(<[^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>)/',
        $html,
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
    ) ?: [];

    foreach ($tokens as $token) {
        /* Inside a dropped element: consume everything to its closing tag. */
        if ($skip !== '') {
            if ($token !== '' && $token[0] === '<'
                && preg_match('#^</?\s*([a-zA-Z][a-zA-Z0-9]*)#', $token, $t)
                && strtolower($t[1]) === $skip
            ) {
                $depth += $token[1] === '/' ? -1 : 1;
                if ($depth <= 0) {
                    $skip = '';
                }
            }
            continue;
        }

        if ($token === '' || $token[0] !== '<') {
            /* Text. Decode first so an already-encoded entity is not encoded
               twice, then re-encode so no raw bracket can survive. */
            $out .= htmlspecialchars(
                html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            continue;
        }

        if (!preg_match('#^</?\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>$#', $token, $m)) {
            continue;   /* comment, doctype, malformed — drop it */
        }

        $raw = strtolower($m[1]);
        $name = RT_TAG_ALIASES[$raw] ?? $raw;
        $closing = $token[1] === '/';

        /* Self-closing forms of these carry no content to skip. */
        if (!$closing
            && in_array($raw, RT_DROP_CONTENT_TAGS, true)
            && !str_ends_with(rtrim($token, '>'), '/')
        ) {
            $skip = $raw;
            $depth = 1;
            continue;
        }

        if (!isset($allowed[$name])) {
            continue;
        }

        if ($closing) {
            /* Close only if it is actually open, and unwind anything left
               open inside it, so the output stays balanced. */
            $at = array_search($name, $open, true);
            if ($at === false) {
                continue;
            }
            while (count($open) > $at) {
                $out .= '</' . array_pop($open) . '>';
            }
            continue;
        }

        if ($name === 'br') {
            $out .= '<br>';
            continue;
        }

        $out .= '<' . $name . rt_attributes($name, $m[2]) . '>';
        $open[] = $name;
    }

    while ($open) {
        $out .= '</' . array_pop($open) . '>';
    }

    /* An empty paragraph is what a stray Enter leaves behind. */
    $out = preg_replace('#<p[^>]*>(\s|&nbsp;|<br>)*</p>#', '', $out) ?? $out;

    return trim($out);
}

/**
 * The same walker, for a field that is rendered inside something else.
 *
 * Same refusals, same attribute rebuilding, same escaping -- only the set of
 * tags that may survive is smaller. A <p> or a <ul> in here is dropped and its
 * words are kept, which is the failure that can be seen and corrected rather
 * than the one that quietly changes the shape of the page.
 */
function rt_sanitise_inline(string $html): string
{
    /* A block boundary is worth a space once the tag itself is gone.
       Without this "<p>one</p><p>two</p>" comes back as "onetwo", which is
       two words somebody wrote joined into one they did not. */
    $html = preg_replace('#</(p|li|ul|ol|div|h[1-6])\s*>#i', ' ', $html) ?? $html;

    return rt_sanitise_html($html, RT_INLINE_TAGS);
}

/** Rebuild the attributes a tag is allowed to keep, from scratch. */
function rt_attributes(string $tag, string $raw): string
{
    $attrs = '';

    preg_match_all(
        '/([a-zA-Z-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/',
        $raw,
        $found,
        PREG_SET_ORDER
    );

    $seen = [];
    foreach ($found as $pair) {
        $key = strtolower($pair[1]);
        $value = trim($pair[2], "\"'");

        if (isset($seen[$key])) {
            continue;
        }

        if ($key === 'href' && $tag === 'a') {
            $href = rt_safe_href($value);
            if ($href !== null) {
                $attrs .= ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
                /* An external link the author cannot vet opens safely or not
                   at all. */
                if (preg_match('#^https?://#i', $href)) {
                    $attrs .= ' target="_blank" rel="noopener noreferrer"';
                }
                $seen[$key] = true;
            }
            continue;
        }

        if ($key === 'class' && in_array($tag, RT_ALIGNABLE, true)) {
            $keep = array_values(array_intersect(
                preg_split('/\s+/', strtolower($value)) ?: [],
                RT_ALLOWED_CLASSES
            ));
            if ($keep) {
                $attrs .= ' class="' . implode(' ', $keep) . '"';
                $seen[$key] = true;
            }
            continue;
        }

        /* Everything else — style, onclick, id, data-*, srcset — is dropped. */
    }

    return $attrs;
}

/**
 * Strip every tag, leaving readable text.
 *
 * For places that take a rich field and need a plain one: a <meta
 * description>, an og:description, the text of a JSON-LD string. Entities are
 * decoded so "&amp;" becomes "&" — whoever consumes the result escapes it
 * again for its own context.
 */
function rt_plain(string $html): string
{
    $text = preg_replace('#<(br|/p|/li|/ul|/ol)\s*/?>#i', ' ', $html) ?? $html;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}
