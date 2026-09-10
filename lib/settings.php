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
/* For UPLOAD_URL_ROOT: telling an uploaded mark from the one that ships
   is a question about where the file came from, and that constant is
   where the answer lives. */
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

/* ----------------------------------------------------------- what is set */

/**
 * True when the mark shown in dark mode is the light one, because nothing
 * else was uploaded.
 *
 * ASKED SO THAT THE SCREEN CAN SAY SO. An empty dark half is a legitimate
 * answer — plenty of marks are a single colour and read on both grounds — but
 * it is indistinguishable, in the document, from somebody having meant to
 * upload one and not got there. Only the person who drew the mark knows which,
 * so the editor states the consequence and lets them decide. It never refuses
 * a save over it.
 */
function settings_logo_is_shared(array $settings): bool
{
    return trim((string)($settings['logo']['dark']['src'] ?? '')) === '';
}

/**
 * True when the logo has been replaced but the icons have not.
 *
 * The favicon is generated from its own square master and NOT from the logo,
 * because a wordmark three times as wide as it is tall becomes an illegible
 * smear at sixteen pixels. So changing the logo cannot change the tab icon,
 * and somebody who has just replaced their mark will expect it to have. The
 * screen says which is which rather than leaving them to notice.
 */
function settings_icon_is_stale(array $settings): bool
{
    return settings_logo_is_uploaded($settings)
        && trim((string)($settings['icon']['master']['src'] ?? '')) === '';
}

/** True when the light mark is an upload rather than the one that ships. */
function settings_logo_is_uploaded(array $settings): bool
{
    return str_starts_with(
        trim((string)($settings['logo']['light']['src'] ?? '')),
        UPLOAD_URL_ROOT
    );
}
