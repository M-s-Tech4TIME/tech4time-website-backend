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

    return $errors;
}

/* ----------------------------------------------------------- what is set */

