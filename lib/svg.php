<?php
/**
 * Tech4TIME — the SVG sanitiser.
 *
 * SHARED — byte-identical in tech4time-website-frontend and
 * tech4time-website-backend, checked by tools/check_shared_lib.py and
 * tools/check_shared_repos.py. Both hosts must agree on what a safe vector
 * file is, or the one that sends and the one that receives disagree about what
 * was published. See lib/publish.php for the wire format that carries it.
 *
 * WHY THIS EXISTS, AND WHAT IT IS ANSWERING
 * ADR 0019 refused SVG outright, and its reasoning was right: "an SVG is a
 * document: it can carry script, external references and entities, and
 * re-encoding does not make it not a document." The branding page needs vector
 * logos, so that has to be answered rather than ignored. It is answered twice.
 *
 * FIRST: THE SAME RULE THE RASTER PATH USES. An upload is not a file to be
 * checked and then saved. It is untrusted input to be READ and then REPLACED.
 * gd does that by decoding to pixels and re-encoding; this does it by parsing
 * to a DOM, applying an allow-list, and serialising THAT. What is stored is
 * this function's output — never the bytes that arrived. A validator can only
 * decide it did not find what it knew to look for; this is a list of what
 * survives, and everything else stops existing.
 *
 * SECOND: IT IS NEVER SERVED AS A DOCUMENT. Sanitising leaves a document, and
 * a document in our origin is a document that could act in it. So .htaccess on
 * both hosts serves an .svg under /uploads/ with Content-Disposition:
 * attachment and Content-Security-Policy: default-src 'none'; sandbox. A file
 * that always downloads and never renders cannot execute, whatever it holds.
 * The public page links to it and never draws it.
 *
 * REFUSED, NOT STRIPPED
 * Anything outside the allow-list makes the whole file refused, with a
 * sentence naming what was found. Silently dropping an element would change
 * the artwork somebody uploaded without telling them — they would publish a
 * logo and get a different logo. The only things quietly removed are ones that
 * cannot affect the drawing: comments, processing instructions and whitespace
 * between tags.
 *
 * IDEMPOTENT, AND THAT IS LOAD-BEARING
 * svg_sanitise(svg_sanitise(x)) == svg_sanitise(x). The receiving host relies
 * on it: it runs this over what arrived and refuses anything that is not
 * already its own output, which proves the bytes are sanitised WITHOUT having
 * to change them — and changing them would break the content-addressed name
 * both hosts compute independently. tools/test_svg.py asserts it directly.
 */

declare(strict_types=1);

/**
 * Elements that may appear. Everything a flat vector logo is made of.
 *
 * NOT here, deliberately:
 *   script, foreignObject   the two ways a document becomes a program
 *   image                   a raster inside a vector: a second file's worth of
 *                           bytes nobody inspected, in a wrapper nobody expects
 *   animate, animateTransform, animateMotion, set, discard
 *                           SMIL, which carries its own href and its own
 *                           attributeName -- a scripting surface in all but name
 *   filter and its primitives
 *                           feImage fetches; the rest are a large surface for a
 *                           kind of artwork this page does not publish
 *   metadata, switch, marker, textPath, a
 *                           nothing a logo needs, and a format nobody uses is
 *                           an attack surface nobody watches
 */
const SVG_ELEMENTS = [
    'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
    'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
    'text', 'tspan',
    'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask',
];

/**
 * Attributes that may appear, on any of the above.
 *
 * Presentation attributes rather than a stylesheet is the normal way an SVG
 * says what colour something is. 'style' is here too -- see SVG_CSS_REFUSED
 * for what is then checked inside it.
 *
 * href and xlink:href are here because <use href="#thing"> is how one shape is
 * drawn twice, which real artwork does constantly. They are checked separately
 * and may ONLY be a same-document fragment; see svg_reference_problem().
 */
const SVG_ATTRIBUTES = [
    /* identity and geometry of the canvas */
    'id', 'class', 'version', 'viewBox', 'preserveAspectRatio',
    'width', 'height', 'x', 'y', 'transform', 'overflow',
    /* shapes */
    'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'points',
    'pathLength', 'dx', 'dy',
    /* paint */
    'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask',
    'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
    'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
    'stroke-opacity', 'opacity', 'color', 'stop-color', 'stop-opacity',
    'offset', 'display', 'visibility', 'paint-order', 'vector-effect',
    /* gradients, patterns, clips and masks */
    'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy',
    'patternUnits', 'patternContentUnits', 'patternTransform',
    'clipPathUnits', 'maskUnits', 'maskContentUnits',
    /* type */
    'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
    'text-anchor', 'dominant-baseline', 'letter-spacing', 'word-spacing',
    'white-space', 'xml:space',
    /* what a screen reader is told */
    'role', 'aria-hidden', 'aria-label', 'aria-labelledby',
    /* checked separately, fragment only */
    'href', 'xlink:href',
    /* checked separately, see SVG_CSS_REFUSED */
    'style',
];

/**
 * What may not appear inside a style attribute or a <style> element.
 *
 * url() is the one that matters: CSS is the other place an SVG can reach off
 * the machine it is on. A fragment -- url(#gradient) -- is a reference to
 * something in the same file and is allowed; anything else is not.
 *
 * expression() and behavior: are dead in every browser that matters and cost
 * nothing to keep refusing. @import is a fetch by another name.
 */
const SVG_CSS_REFUSED = ['@import', 'expression(', 'javascript:', 'behavior:', '-moz-binding'];

/** The largest vector file this site will publish. A logo is a few kilobytes. */
const SVG_MAX_BYTES = 524288;

/** How deep a document may nest, so a pathological file cannot walk us over. */
const SVG_MAX_DEPTH = 40;

/**
 * Why this server cannot check a vector file at all, or '' if it can.
 *
 * ext-dom is present on the live hosts (docs/40-reference/host-facts.md) and
 * absent from Ubuntu's php-cli, which is exactly where gd stands — see
 * upload_problem() in the backend's lib/upload.php. Said plainly here rather
 * than discovered as a fatal error, so a host without it refuses vector
 * uploads and keeps working, and CI installs php-xml rather than letting
 * tools/test_svg.py skip the cases that matter.
 */
function svg_problem(): string
{
    if (!class_exists('DOMDocument')) {
        return 'This server cannot read XML (ext-dom is not installed), so a '
             . 'vector file cannot be checked and will not be published.';
    }

    return '';
}

/**
 * Clean a vector file, or say why it cannot be published.
 *
 * @return array{error:string}|array{svg:string,width:int,height:int}
 */
function svg_sanitise(string $bytes): array
{
    if ($bytes === '') {
        return ['error' => 'That file is empty.'];
    }
    if (strlen($bytes) > SVG_MAX_BYTES) {
        return ['error' => 'That vector file is larger than '
                         . (int)(SVG_MAX_BYTES / 1024) . ' KB.'];
    }

    /* Before the parser sees it. A DOCTYPE is how an XML document declares
       entities, which is how it reads a file off the server (XXE) or expands
       to gigabytes from a few hundred bytes. Nothing a drawing program exports
       needs one, so this is a refusal rather than a thing to configure around.
       libxml is also told not to load them, below -- two answers to one
       question, because this one is worth being sure about. */
    if (preg_match('/<!DOCTYPE/i', $bytes)) {
        return ['error' => 'That file declares a DOCTYPE. Export it without one.'];
    }
    if (preg_match('/<!ENTITY/i', $bytes)) {
        return ['error' => 'That file declares XML entities, which cannot be published.'];
    }
    /* A processing instruction that is not the XML declaration. <?php in a
       file that some other server might one day hand to an interpreter. */
    if (preg_match('/<\?(?!xml[\s?])/i', $bytes)) {
        return ['error' => 'That file carries a processing instruction.'];
    }

    /* AFTER the refusals above and before the parser. Everything up to here is
       decided on the raw bytes, so it holds on a host with no XML support at
       all -- which is the host most likely to be tempted to skip a check. Only
       from here on is a parser needed, and a server without one refuses rather
       than guesses. */
    $problem = svg_problem();
    if ($problem !== '') {
        return ['error' => $problem];
    }

    /* The return value is NOT the previous resolver before PHP 8.4 -- it is a
       bool -- so there is nothing to capture and restore. Cleared in the
       finally instead, which is what "put it back" means here. */
    $previous = libxml_use_internal_errors(true);
    libxml_set_external_entity_loader(static fn() => null);

    try {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;

        /* LIBXML_NONET: no network, ever. LIBXML_NOENT is deliberately NOT
           passed -- it means "substitute entities", which is the opposite of
           what its name suggests to most people reading it. */
        $ok = $doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();

        if (!$ok || $doc->documentElement === null) {
            return ['error' => 'That file is not well-formed XML, so it is not an SVG.'];
        }

        $root = $doc->documentElement;
        if (strtolower($root->localName ?? '') !== 'svg') {
            return ['error' => 'That file is XML, but its root element is not <svg>.'];
        }

        $problem = svg_walk($root, 1);
        if ($problem !== '') {
            return ['error' => $problem];
        }

        [$width, $height] = svg_dimensions($root);

        /* The documentElement rather than the whole document: no XML
           declaration, no trailing newline, nothing that varies with how
           libxml felt about the input. The output has to be identical for
           identical artwork, because its SHA-256 is its name. */
        $out = $doc->saveXML($root);
        if ($out === false || $out === '') {
            return ['error' => 'That file could not be re-serialised.'];
        }

        return ['svg' => $out, 'width' => $width, 'height' => $height];
    } finally {
        libxml_set_external_entity_loader(null);
        libxml_use_internal_errors($previous);
    }
}

/**
 * Walk one element and everything under it, refusing anything unrecognised.
 *
 * Comments and processing instructions are removed rather than refused: they
 * cannot affect the drawing, so taking them out is not editing somebody's
 * artwork. Everything that CAN affect the drawing either survives untouched or
 * stops the whole file.
 *
 * @return string '' if the subtree is clean, otherwise why it is not
 */
function svg_walk(DOMElement $el, int $depth): string
{
    if ($depth > SVG_MAX_DEPTH) {
        return 'That file nests more than ' . SVG_MAX_DEPTH . ' levels deep.';
    }

    $name = $el->localName ?? '';
    if (!in_array($name, SVG_ELEMENTS, true)) {
        return 'That file uses <' . $name . '>, which cannot be published. '
             . 'Export it as flat vector artwork: no script, no embedded '
             . 'images, no filters and no animation.';
    }

    /* Attributes first, and over a copy of the list: removing one while
       iterating a live DOMNamedNodeMap skips the next. */
    foreach (iterator_to_array($el->attributes ?? []) as $attr) {
        $problem = svg_attribute_problem($el, $attr);
        if ($problem !== '') {
            return $problem;
        }
    }

    if ($name === 'style') {
        $problem = svg_css_problem($el->textContent);
        if ($problem !== '') {
            return $problem;
        }
    }

    foreach (iterator_to_array($el->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            $problem = svg_walk($child, $depth + 1);
            if ($problem !== '') {
                return $problem;
            }
            continue;
        }
        /* Says nothing, draws nothing, and is where a payload hides. */
        if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
            $el->removeChild($child);
        }
    }

    return '';
}

/** One attribute: allowed at all, and then allowed to say what it says. */
function svg_attribute_problem(DOMElement $el, DOMAttr $attr): string
{
    $name = $attr->nodeName;

    /* A namespace declaration is not an attribute anybody set; it is how the
       document says it is SVG. Left alone. */
    if ($name === 'xmlns' || str_starts_with($name, 'xmlns:')) {
        return '';
    }

    /* Said explicitly rather than left to the allow-list, because this is the
       single most common way an SVG carries script and the message should say
       so rather than reading like a typo. */
    if (preg_match('/^on/i', $name)) {
        return 'That file carries an event handler (' . $name . '), which cannot '
             . 'be published.';
    }

    if (!in_array($name, SVG_ATTRIBUTES, true)) {
        return 'That file uses the attribute "' . $name . '" on <'
             . ($el->localName ?? '?') . '>, which cannot be published.';
    }

    if ($name === 'href' || $name === 'xlink:href') {
        return svg_reference_problem($attr->value);
    }

    if ($name === 'style') {
        return svg_css_problem($attr->value);
    }

    /* url(...) can appear in a presentation attribute too -- fill="url(...)"
       is how a gradient is applied -- so the same rule holds there. */
    return svg_css_problem($attr->value);
}

/**
 * A reference: same-document fragment, or nothing.
 *
 * "#gradient" is one shape drawn twice. Anything else is this file asking for
 * something else, which is the property that makes an SVG a document rather
 * than a picture.
 */
function svg_reference_problem(string $value): string
{
    if (str_starts_with(trim($value), '#') && !str_contains($value, '(')) {
        return '';
    }

    return 'That file references something outside itself (' . trim($value)
         . '). Only same-file references like "#logo" can be published.';
}

/** CSS, wherever it appears: no fetching, and no historical script vectors. */
function svg_css_problem(string $css): string
{
    $flat = strtolower($css);

    foreach (SVG_CSS_REFUSED as $needle) {
        if (str_contains($flat, $needle)) {
            return 'That file uses "' . $needle . '" in its styling, which '
                 . 'cannot be published.';
        }
    }

    /* url() is allowed only as a same-file reference. url(#fade) applies a
       gradient defined above it; url(https://…) and url(data:…) are this file
       reaching for something nobody checked. */
    if (preg_match('/url\(\s*[\'"]?\s*(?!#)/i', $flat)) {
        return 'That file loads something through url() in its styling. Only '
             . 'same-file references like url(#logo) can be published.';
    }

    return '';
}

/**
 * How big the drawing is, for the record the page stores.
 *
 * viewBox first, because that is the size the artwork is drawn AT; width and
 * height on the root are how big somebody last placed it. Either may be
 * missing, and neither is invented: a file with no intrinsic size gets zeroes,
 * and branding_meta_line() then says less rather than something untrue.
 *
 * @return array{0:int,1:int}
 */
function svg_dimensions(DOMElement $root): array
{
    $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];
    if (count($box) === 4 && (float)$box[2] > 0 && (float)$box[3] > 0) {
        return [(int)round((float)$box[2]), (int)round((float)$box[3])];
    }

    $width  = (float)$root->getAttribute('width');
    $height = (float)$root->getAttribute('height');

    return ($width > 0 && $height > 0)
        ? [(int)round($width), (int)round($height)]
        : [0, 0];
}

/**
 * Whether these bytes are claiming to be an SVG at all.
 *
 * Cheap, and only used to decide WHICH check to run -- svg_sanitise() is what
 * decides whether the file is publishable. Deliberately not a validation:
 * getimagesizefromstring() answers for the three raster formats and returns
 * false here, so something has to route a vector file to the right place.
 */
function svg_looks_like(string $bytes): bool
{
    /* Past a BOM, whitespace, an XML declaration, any comments and a DOCTYPE,
       the first thing must be <svg. Bounded so a megabyte of leading space is
       not a search.

       THE DOCTYPE IS MATCHED HERE AND REFUSED BY svg_sanitise(), which reads
       backwards but is not. This answers "is this claiming to be a vector
       file", so that one which IS gets routed to the sanitiser and is told
       what is actually wrong with it. Leaving it out sent a DOCTYPE-bearing
       SVG down the raster path instead, where it came back as "that file is
       not a JPEG, PNG, WebP or SVG picture" -- which is both unhelpful and
       untrue. */
    return preg_match('/^\s*(\xEF\xBB\xBF)?\s*(<\?xml[^>]*\?>\s*)?'
                    . '(<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<svg[\s>]/is',
                      substr($bytes, 0, 4096)) === 1;
}
