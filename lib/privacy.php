<?php
/**
 * Tech4TIME — privacy policy page data access.
 *
 * Reading and writing the file is lib/store.php; escaping is lib/html.php; the
 * SHAPE of the page is lib/contract.php, which the frontend and the backend
 * hold byte-identical. What is left here is this side's own business with that
 * shape: validation, the save that publishes, and the comparison against the
 * contact page.
 *
 * The renderers are the frontend's, because the frontend renders the page.
 * What is NOT split that way is privacy_shared_facts(), which lives in
 * contract.php: what counts as "the policy still says this" must be one
 * answer, or the editor's notice and any check of the same thing could
 * disagree about whether the site is consistent with itself.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, privacy_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/publish_client.php';

const PRIVACY_FILE = __DIR__ . '/../content/privacy.json';

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the reason branding_load() does not: a page showing last
 * month's policy is wrong only if it changed, and a page showing a PHP error
 * is wrong for everybody -- and this is the page a regulator reads.
 */
function privacy_load(): array
{
    return privacy_normalise(store_read(PRIVACY_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then send a copy to the live site.
 *
 * THIS RECORD IS WRITTEN FIRST, ALWAYS. It is the system of record; the live
 * site holds a replica (ADR 0010). A publish that fails leaves the edit safely
 * here to be pushed again, which is the right way round.
 *
 * 'updated' moves on every save. THE EFFECTIVE DATE DOES NOT, and nothing here
 * touches it: it is a claim about when the policy changed, and fixing a typo
 * is not a new policy. Whoever decides the policy changed types the new date.
 */
function privacy_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(PRIVACY_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('privacy', $data));

    return true;
}

/* --------------------------------------------------------------- validate */

/**
 * Everything that would make the page wrong rather than merely empty.
 *
 * NOTHING HERE COMPARES THIS PAGE WITH THE CONTACT PAGE. A mismatch is
 * reported by privacy_shared_facts() and drawn as a standing notice; it is
 * never a refusal. After an office move, whichever of the two pages somebody
 * edited first could not be saved, and there is no order that avoids it --
 * so an unrelated typo fix would be blocked by an address that drifted months
 * ago. A fault in a field is a refusal; a disagreement with another page is a
 * notice.
 */
function privacy_validate(array $data): array
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
                  . 'call this page in a trail.';
    }
    if (strlen(trim((string)$data['meta']['description'])) > 320) {
        $errors[] = 'The search description is longer than 320 characters. Search '
                  . 'engines will cut it off.';
    }

    /* THE ONE FIELD ON THIS PAGE THAT IS A LEGAL CLAIM ON ITS OWN. A policy
       with no effective date does not say when it started applying, which is
       the first thing anybody checks and the first thing anybody disputes. */
    if (trim((string)$data['policy']['effective']) === '') {
        $errors[] = 'The policy has no effective date. Say when it took effect — it is '
                  . 'the line at the top of the page.';
    }
    if (trim((string)$data['policy']['label']) === '') {
        $errors[] = 'The section needs a name for screen readers. It is the heading '
                  . 'nobody sees and everybody using a screen reader hears.';
    }

    $callout = $data['policy']['callout'];
    if (($callout['status'] ?? 'shown') !== 'hidden'
            && trim((string)$callout['title']) === '') {
        $errors[] = 'The summary box is shown but has no heading.';
    }
    foreach ($callout['items'] as $i => $item) {
        if (trim(strip_tags((string)$item['text'])) === '') {
            $errors[] = 'Summary point ' . ($i + 1) . ' is empty.';
        }
    }

    $anchors = [];
    foreach ($data['policy']['sections'] as $s => $section) {
        $where = 'Section ' . ($s + 1);

        if (trim((string)$section['heading']) === '') {
            /* A section with no heading renders an <h2> with nothing in it,
               and the <h3> subheadings under it become a heading level with
               no parent -- which audit_pages.py checks for on the public
               side, after it has already shipped. */
            $errors[] = "$where has no heading.";
        }

        /* An anchor is a promise. Two sections answering to one fragment means
           a link that used to land somewhere now lands somewhere else, and
           the page cannot tell you which. */
        $anchor = (string)$section['id'];
        if (in_array($anchor, $anchors, true)) {
            $errors[] = "$where has the same web address as another section (#$anchor).";
        }
        $anchors[] = $anchor;

        foreach ($section['blocks'] as $b => $block) {
            $spot = "$where, block " . ($b + 1);
            $kind = (string)$block['kind'];

            if (!array_key_exists($kind, PRIVACY_BLOCK_KINDS)) {
                $errors[] = "$spot is of a kind this page does not know how to draw.";
                continue;
            }

            if (array_key_exists('text', $block)
                    && trim(strip_tags((string)$block['text'])) === '') {
                $errors[] = "$spot is empty.";
            }

            if ($kind === 'table') {
                foreach ($block['columns'] as $c => $column) {
                    if (trim((string)$column) === '') {
                        $errors[] = "$spot has no heading for column " . ($c + 1) . '.';
                    }
                }
                if (trim((string)$block['caption']) === '') {
                    $errors[] = "$spot has no caption. A screen reader announces a table "
                              . 'with no caption as just "table".';
                }
            }

            foreach ($block['rows'] ?? [] as $r => $row) {
                $at = "$spot, row " . ($r + 1);

                if ($kind === 'table') {
                    if (trim((string)$row['label']) === '' || trim((string)$row['value']) === '') {
                        $errors[] = "$at is missing one of its two cells.";
                    }
                    continue;
                }
                if (trim(strip_tags((string)$row['text'])) === '') {
                    $errors[] = "$at is empty.";
                }
            }
        }
    }

    if (!in_array($data['cta']['status'] ?? 'shown', ['shown', 'hidden'], true)) {
        $errors[] = 'A section was given a state that is neither shown nor hidden.';
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

/* ------------------------------------------------------- what it repeats */

/**
 * How this policy's contact details compare with the contact page's.
 *
 * A thin wrapper so the editor does not have to know which document holds the
 * other half. The comparison itself is privacy_shared_facts() in the shared
 * contract, so there is one answer to what "still says this" means.
 */
function privacy_facts(array $data): array
{
    return privacy_shared_facts($data, contact_load());
}
