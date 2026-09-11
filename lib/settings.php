<?php
/**
 * Tech4TIME — the site's identity: the mark, the icons, the colours, this
 * side's half.
 *
 * Reading and writing the file is lib/store.php; the SHAPE is lib/contract.php,
 * which the frontend and the backend hold byte-identical. What is left here is
 * this side's own business with that shape: the read, the save that publishes,
 * and the questions the screens ask about what is set.
 *
 * THIS SIDE HAS NO RENDERER. tech4time-website-frontend/lib/settings.php is the
 * other half of this file and holds the reverse — settings_logo(),
 * settings_colours() and the resolution the pages need, and none of the
 * writing below. The split lib/chrome.php and lib/company.php already use.
 *
 * WHY THE IDENTITY IS ITS OWN DOCUMENT. Everything in it is read by several
 * pages and owned by none: the logo is drawn in the header, the footer, the
 * About page and this panel's own rail, and named in Organization.logo, in
 * JobPosting's hiring organisation, in the favicon set and in the branding kit.
 * Putting it in any one page's document would make the other eight consumers
 * read a document about something else.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, settings_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';
/* The icon generator is image work: it needs GD through upload_problem(),
   and it writes its output through upload_write(), which is what puts a
   generated file under the same content-addressed name and the same
   signed channel as an uploaded one. */
require_once __DIR__ . '/upload.php';

const SETTINGS_FILE = __DIR__ . '/../content/settings.json';

/**
 * The parts of the document, each its own screen and its own form.
 *
 * FOUR FORMS AND NOT ONE, for the reason ?s=chrome split into three:
 * max_input_vars. PHP drops the tail of a long POST silently, and a colour
 * picker alone is twenty-eight fields. A form that is quietly truncated saves
 * what arrived and loses the rest, which is the worst failure a settings
 * screen can have — it looks like it worked.
 */
const SETTINGS_PARTS = ['logo', 'icon', 'colour', 'mail'];

/* ------------------------------------------------------------------- read */

/** The document, or the shipped identity if it is missing. */
function settings_load(): array
{
    return settings_normalise(store_read(SETTINGS_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Change the document under a lock, then publish it.
 *
 * $mutate is handed the normalised document and returns the new one, or null
 * to abandon the write. Modelled line for line on chrome_edit(), and it locks
 * for the same reason: each screen holds one PART of this document — the
 * colour screen never sees the logo — so a save has to merge the rest back
 * from the file. A read-modify-write without a lock loses one of two
 * concurrent edits to different parts, which here is the normal case.
 */
function settings_edit(callable $mutate): bool
{
    $written = store_edit(SETTINGS_FILE, static function (array &$data) use ($mutate): ?array {
        $data = settings_normalise($data);
        $next = $mutate($data);

        if ($next === null) {
            return null;
        }

        $next = settings_normalise($next);
        $next['updated']  = gmdate('c');
        $next['revision'] = contract_next_revision($next);

        $data = $next;

        return $next;
    });

    if ($written === null) {
        return false;
    }

    publish_note(publish_push('settings', $written));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with the settings, as sentences for the editor.
 *
 * REFUSES ONLY WHAT WOULD BE WRONG ON THE SITE, which is a short list.
 * settings_normalise() has already dropped a picture path this site would not
 * serve, fallen back on a colour that is not six hex digits and on an address
 * that is not one, so nothing here repeats that work.
 *
 * WHAT IS LEFT FOR THE LOGO IS ONE THING NORMALISING CANNOT DECIDE: whether
 * there is a light mark at all. An empty DARK half is a legitimate answer and
 * is never refused — a single-colour mark reads on both grounds, and the
 * screen says in words what an empty one means. An empty LIGHT half is not an
 * answer: it is the company's mark missing from the header and the footer of
 * every page of the site, and from the structured data a search engine reads.
 *
 * ONE PART AT A TIME, for the reason chrome_validate() takes a part: each
 * screen holds one and merges the rest back from the file, so judging the
 * whole document would let a fault in the colours — set by somebody else, an
 * hour ago, on another screen — refuse a save on the logo. Pass '' to judge
 * all four, which is what a check outside the editor wants.
 */
function settings_validate(array $data, string $part = ''): array
{
    $errors = [];
    $wanted = $part === '' ? SETTINGS_PARTS : [$part];

    if (in_array('logo', $wanted, true)
            && trim((string)($data['logo']['light']['src'] ?? '')) === '') {
        $errors[] = 'The logo has no light-mode picture. That is the mark in the '
                  . 'header and the footer of every page, so it cannot be empty. '
                  . 'Upload one, or leave the one that is there.';
    }

    /* AN ADDRESS THAT IS NOT ONE IS REFUSED HERE AND FALLEN BACK ON
       ELSEWHERE, and the difference is who is asking. settings_normalise()
       meets a document arriving over the wire, where the alternative to the
       shipped address is a contact form posting into nowhere -- so it falls
       back. This meets somebody TYPING, where falling back silently would
       replace what they wrote with info@tech4time.bd and look exactly like a
       save that worked. The same value, refused in one direction and repaired
       in the other, on purpose.

       An empty subject line likewise: it is what every enquiry's subject
       starts with, and "": something they typed" is not a subject. */
    if (in_array('mail', $wanted, true)) {
        $to = trim((string)($data['contact']['mail_to'] ?? ''));

        if ($to === '') {
            $errors[] = 'Enquiries need an address to go to. Without one the '
                      . 'contact form has nowhere to send.';
        } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '"' . $to . '" is not an email address. Enquiries would '
                      . 'have nowhere to go.';
        }

        if (trim((string)($data['contact']['mail_subject'] ?? '')) === '') {
            $errors[] = 'The subject line cannot be empty: it is what every '
                      . 'enquiry\'s subject starts with, before whatever the '
                      . 'sender typed.';
        }
    }

    /* THE COLOURS ARE THE ONE PLACE IN THIS EDITOR WHERE A REFUSAL IS RIGHT.
       Everything else here is a standing notice, because everything else here
       is a judgement only the person who drew the mark can make. This is not a
       judgement: a contrast ratio is arithmetic, WCAG says what the bar is,
       and text nobody can read is not a matter of taste. A notice can be
       dismissed; an unreadable site cannot.

       The pairs and the sums are SETTINGS_CONTRAST_PAIRS and
       contract_contrast_ratio() in the contract, which is also where
       tools/check_contrast.py gets them -- so what this refuses and what that
       refuses cannot come apart. */
    if (in_array('colour', $wanted, true)) {
        foreach (['light', 'dark'] as $mode) {
            $errors = array_merge($errors, settings_contrast_faults(
                is_array($data['colours'][$mode] ?? null) ? $data['colours'][$mode] : [],
                $mode));
        }
    }

    return $errors;
}

/* ----------------------------------------------------------- what is set */


/* --------------------------------------------------------- the icon set */

/**
 * Every favicon PNG a browser or a phone asks for, made from one square master.
 *
 * WHY THE SERVER DOES THIS AND NOT A SCRIPT. The set is eight files -- seven
 * PNGs and an ICO -- and every one of them has to be the same mark. That was a
 * developer running tools/build_favicons.py with Pillow and committing the
 * result, so a company could not change what its own browser tab shows.
 *
 * Returns the 'generated' band, or ['error' => 'a sentence']. It writes
 * through upload_write(), so each file lands under a content-addressed name
 * beside the uploads and travels the same signed channel.
 *
 * NO favicon.ico IS WRITTEN HERE. The asset channel carries what
 * getimagesizefromstring() recognises and an .ico is not among them; widening
 * that list to carry one file would also widen what an editor can upload as
 * page artwork. The public site holds the three PNGs an .ico needs already, so
 * it assembles the container itself -- contract_ico_container(), served at
 * /favicon.ico.
 *
 * IT NEVER WRITES A PARTIAL SET. Everything is encoded before anything is
 * written, for the reason upload_store() does it: a set with three of eight
 * files on disk is a site with three of eight icons, and the other five are
 * <link> elements pointing at nothing.
 *
 * @param string $bytes the master, as it was stored
 */
function settings_icon_generate(string $bytes): array
{
    $problem = upload_problem();
    if ($problem !== '') {
        return ['error' => $problem];
    }

    $master = @imagecreatefromstring($bytes);
    if ($master === false) {
        return ['error' => 'That picture could not be read. It may be damaged.'];
    }

    $made = [];

    try {
        $square = settings_icon_square($master);

        try {
            foreach (SETTINGS_ICON_SIZES as $name => $spec) {
                $png = settings_icon_render($square, $spec['size'], (float)$spec['pad']);

                if ($png === null) {
                    return ['error' => 'The icons could not be drawn at '
                                     . $spec['size'] . ' pixels.'];
                }

                $made[$name] = $png;
            }

        } finally {
            if ($square !== $master) {
                imagedestroy($square);
            }
        }
    } finally {
        imagedestroy($master);
    }

    $out = [];

    foreach ($made as $name => $blob) {
        $file = publish_asset_name($blob, 'png');

        if (!upload_write($file, $blob)) {
            return ['error' => 'The icons could not be saved on this server.'];
        }

        $out[$name] = UPLOAD_URL_ROOT . $file;
    }

    return $out;
}

/**
 * The master, trimmed of empty edges and padded back to a square.
 *
 * A MARK IS RARELY SQUARE AND AN ICON ALWAYS IS. Trimming first is what stops
 * a picture with a wide transparent margin becoming a tiny mark in the middle
 * of every tile; padding back to a square is what stops the trim turning a
 * circular dial into an oval. Both are what build_favicons.py does with the
 * artwork this site ships, so a generated set sits where the committed one did.
 *
 * Hands the image back untouched when it is already a trimmed square, and the
 * caller must not destroy what it did not get a new image of.
 */
function settings_icon_square(GdImage $image): GdImage
{
    $trimmed = @imagecropauto($image, IMG_CROP_TRANSPARENT);

    if ($trimmed === false) {
        /* Nothing to trim -- an opaque picture -- which is not a failure. */
        $trimmed = $image;
    }

    $side = max(imagesx($trimmed), imagesy($trimmed));

    if (imagesx($trimmed) === $side && imagesy($trimmed) === $side) {
        return $trimmed;
    }

    $square = imagecreatetruecolor($side, $side);
    upload_keep_alpha($square);
    imagefilledrectangle($square, 0, 0, $side - 1, $side - 1,
        imagecolorallocatealpha($square, 0, 0, 0, 127));

    imagecopy($square, $trimmed,
        (int)(($side - imagesx($trimmed)) / 2), (int)(($side - imagesy($trimmed)) / 2),
        0, 0, imagesx($trimmed), imagesy($trimmed));

    if ($trimmed !== $image) {
        imagedestroy($trimmed);
    }

    return $square;
}

/**
 * One icon, as PNG bytes: transparent when $pad is zero, an opaque tile when
 * it is not.
 *
 * See SETTINGS_ICON_SIZES for why those are two different things.
 */
function settings_icon_render(GdImage $square, int $size, float $pad): ?string
{
    $inner = (int)round($size * (1 - 2 * $pad));

    if ($size < 1 || $inner < 1) {
        return null;
    }

    $canvas = imagecreatetruecolor($size, $size);
    upload_keep_alpha($canvas);

    [$r, $g, $b] = SETTINGS_ICON_GROUND;

    imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1,
        $pad > 0.0
            ? imagecolorallocate($canvas, $r, $g, $b)
            : imagecolorallocatealpha($canvas, 0, 0, 0, 127));

    /* Blending ON for the paste, so a mark with soft edges lands ON the
       ground rather than punching its own alpha through it -- and off again
       before the encoder, so what alpha is left is what gets written. */
    imagealphablending($canvas, true);

    $offset = (int)(($size - $inner) / 2);
    $ok = imagecopyresampled($canvas, $square, $offset, $offset, 0, 0,
        $inner, $inner, imagesx($square), imagesy($square));

    imagealphablending($canvas, false);

    $png = $ok ? upload_encode($canvas, 'png') : null;

    imagedestroy($canvas);

    return $png;
}
