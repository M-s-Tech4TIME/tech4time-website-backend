<?php
/**
 * Tech4TIME — resource certifications page data access.
 *
 * Reading and writing the file is lib/store.php; escaping is lib/html.php; the
 * SHAPE of the page is lib/contract.php, which the frontend and the backend
 * hold byte-identical. What is left here is this side's own business with that
 * shape: validation, and the save that publishes.
 *
 * The renderers are the frontend's, because the frontend renders the page.
 * The one thing that is NOT split that way is certifications_fill() and the
 * counting behind it, which live in contract.php on purpose: the editor
 * previews a sentence and the public page renders it, and a token meaning two
 * different things in the two places is exactly the fault a shared file
 * prevents by construction.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, certifications_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const CERTIFICATIONS_FILE = __DIR__ . '/../content/certifications.json';

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the reason about_load() does not: a page showing last
 * week's list is wrong only if it changed, and a page showing a PHP error is
 * wrong for everybody.
 */
function certifications_load(): array
{
    return certifications_normalise(store_read(CERTIFICATIONS_FILE) ?? []);
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
function certifications_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(CERTIFICATIONS_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('certifications', $data));

    return true;
}

/* --------------------------------------------------------------- validate */

/**
 * Everything that would make the page wrong rather than merely empty.
 *
 * An empty list is allowed throughout: a group with no certifications yet is a
 * group somebody is part way through filling in, and refusing to save it would
 * mean the only way to build one is in a single sitting.
 */
function certifications_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page needs a heading — the banner title cannot be empty.';
    }
    if (trim((string)$data['meta']['title']) === '') {
        $errors[] = 'The browser tab title cannot be empty.';
    }

    /* strlen and not mb_strlen: bytes, as about_validate() counts them. It
       undercounts the characters allowed in a description with accents, which
       for a soft search-engine limit is the safe direction to be wrong in.

       Counted AFTER the tokens are filled in, because {certifications} is
       fifteen characters that will be published as two. Measuring the stored
       string would refuse a description that fits and accept one that does
       not. */
    $filled = certifications_fill(
        (string)$data['meta']['description'], certifications_counts($data));

    if (strlen(trim($filled)) > 320) {
        $errors[] = 'The search description is longer than 320 characters once its '
                  . 'counts are filled in. Search engines will cut it off.';
    }

    foreach (CERTIFICATIONS_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    $slugs = [];
    foreach ($data['certs']['items'] as $i => $group) {
        $where = 'Role group ' . ($i + 1);

        $named = array_filter(
            $group['roles'],
            static fn(array $r): bool => trim((string)$r['name']) !== ''
        );
        if (!$named) {
            $errors[] = "$where has no role names. A group is its roles — the heading is "
                      . 'built from them, so it cannot have none.';
        }

        if (!array_key_exists((string)$group['icon'], CERTIFICATIONS_ICONS)) {
            $errors[] = "$where has an icon that is not one of the ones offered.";
        }

        /* Two groups sharing an anchor means one of them cannot be linked to,
           and which one wins is whichever the browser happens to find first. */
        $slug = (string)$group['slug'];
        if ($slug !== '' && in_array($slug, $slugs, true)) {
            $errors[] = "$where shares its web address (#$slug) with another group.";
        }
        $slugs[] = $slug;

        foreach ($group['items'] as $j => $cert) {
            if (trim((string)$cert['name']) === '') {
                $errors[] = "$where, certification " . ($j + 1) . ' has no name.';
            }
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
