<?php
/**
 * Tech4TIME — branding & advertisement page data access.
 *
 * Reading and writing the file is lib/store.php; escaping is lib/html.php; the
 * SHAPE of the page is lib/contract.php, which the frontend and the backend
 * hold byte-identical. What is left here is this side's own business with that
 * shape: validation, and the save that publishes.
 *
 * The renderers are the frontend's, because the frontend renders the page.
 * What is NOT split that way is branding_meta_line() and the format and label
 * derived beside it, which live in contract.php on purpose: the editor
 * previews a line and the public page renders it, and the two disagreeing
 * about what a file is called or how big it is would be exactly the fault a
 * shared file prevents by construction.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, branding_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const BRANDING_FILE = __DIR__ . '/../content/branding.json';

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the reason certifications_load() does not: a page showing
 * last week's logos is wrong only if they changed, and a page showing a PHP
 * error is wrong for everybody.
 */
function branding_load(): array
{
    return branding_normalise(store_read(BRANDING_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then send a copy to the live site.
 *
 * THIS RECORD IS WRITTEN FIRST, ALWAYS. It is the system of record; the live
 * site holds a replica (ADR 0010). A publish that fails leaves the edit safely
 * here to be pushed again, which is the right way round -- the other order
 * would put a page on the internet that this host has no copy of.
 */
function branding_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(BRANDING_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('branding', $data));

    return true;
}

/* --------------------------------------------------------------- validate */

/**
 * Everything that would make the page wrong rather than merely empty.
 *
 * An empty list is allowed throughout: a logo variant with no files on it yet
 * is one somebody is part way through adding, and refusing to save it would
 * mean the only way to build one is in a single sitting. A variant added here
 * arrives hidden, so a half-filled card is never live in the meantime.
 */
function branding_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page needs a heading — the banner title cannot be empty.';
    }
    if (trim((string)$data['meta']['title']) === '') {
        $errors[] = 'The browser tab title cannot be empty.';
    }
    if (trim((string)$data['meta']['breadcrumb']) === '') {
        $errors[] = 'The breadcrumb name cannot be empty — it is what search results '
                  . 'call this page in a trail, and what the footer link says.';
    }

    /* strlen and not mb_strlen: bytes, as about_validate() counts them. It
       undercounts the characters allowed in a description with accents, which
       for a soft search-engine limit is the safe direction to be wrong in. */
    if (strlen(trim((string)$data['meta']['description'])) > 320) {
        $errors[] = 'The search description is longer than 320 characters. Search '
                  . 'engines will cut it off.';
    }

    foreach (BRANDING_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    foreach ($data['assets']['items'] as $i => $asset) {
        $where = 'Logo ' . ($i + 1);

        if (trim((string)$asset['title']) === '') {
            $errors[] = "$where has no name. The card needs a heading.";
        }

        if (!array_key_exists((string)$asset['plate'], BRANDING_PLATES)) {
            $errors[] = "$where sits on a background that is not one of the ones offered.";
        }

        /* A preview with no alt text is a picture a screen reader announces as
           a filename, or not at all. The mark is the whole content of the
           card, so this is not decoration that could be left silent. */
        if (trim((string)$asset['image']['src']) !== ''
                && trim((string)$asset['alt']) === '') {
            $errors[] = "$where has a preview picture with no description. Say what "
                      . 'the mark looks like and what it sits on.';
        }

        foreach ($asset['files'] as $j => $file) {
            $spot = "$where, download " . ($j + 1);

            if (trim((string)$file['file']['src']) === '') {
                $errors[] = "$spot has no file. Upload the one people should get, or "
                          . 'remove the row.';
                continue;
            }

            /* branding_safe_filename() empties anything it will not carry, so
               an empty value here means either nothing was typed or what was
               typed had a separator in it. Both need saying. */
            if (trim((string)$file['filename']) === '') {
                $errors[] = "$spot has no saved-as name, so a browser would file it "
                          . 'under the site\'s own name for it. Give it something '
                          . 'with no slashes in it, like tech4time-logo.png.';
            }
        }
    }

    foreach ($data['legal']['items'] as $i => $note) {
        if (trim(strip_tags((string)$note['text'])) === '') {
            $errors[] = 'Disclaimer paragraph ' . ($i + 1) . ' is empty.';
        }
    }

    foreach ($data['cta']['items'] as $i => $button) {
        $where = 'Button ' . ($i + 1);

        if (trim((string)$button['label']) === '') {
            $errors[] = "$where has no label. A button with no words on it is invisible.";
        }
        if (trim((string)$button['href']) === '') {
            $errors[] = "$where has no link. A button that goes nowhere is a dead end.";
        }
    }

    return $errors;
}
