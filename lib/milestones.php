<?php
/**
 * Tech4TIME — the milestones document's data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of the document is lib/contract.php,
 * which the frontend and the backend hold byte-identical. What is left here is
 * this side's own business with that shape: validation, and the save that
 * publishes. The two pages that render it are the frontend's.
 *
 * WHY THIS IS NOT A BAND OF content/company.json ANY MORE — see the long note
 * at the top of section 4 of lib/contract.php. Briefly: the history is a page
 * now, a page needs a meta band, a meta band is per document, and a document
 * whose lastmod moves when a milestone is added is the only honest one to put
 * in a sitemap.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, milestones_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/publish_client.php';

const MILESTONES_FILE = __DIR__ . '/../content/milestones.json';

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the reason company_load() does not.
 *
 * THE READ-THROUGH IS THE SAME ONE THE FRONTEND HAS, deliberately. Both halves
 * have to agree about what the timeline is before the first save, or the
 * editor would come up empty over a page still showing seven entries — and one
 * press of Save would publish the empty one over it. That has happened once on
 * this project already, to the company profile, and the note in
 * contract_path() records it.
 *
 * It keys on the REVISION, not on a missing file and not on an empty list. A
 * fresh host is seeded with content/milestones.json, so the file exists from
 * the first day; and an operator who deliberately removes every entry must not
 * be given them all back on the next request. Revision 0 means nobody has ever
 * saved this, which is exactly the condition — milestones_save() mints 1 on
 * the first save and the read-through never fires again.
 *
 * THE WHOLE BAND MOVES, not just the rows: the heading, the eyebrow and the
 * introduction are part of the timeline and are edited on this screen now.
 * company_milestone_defaults() and milestones_entry_defaults() carry the same
 * five fields, so the rows need no translating on the way across.
 */
function milestones_load(): array
{
    $data = milestones_normalise(store_read(MILESTONES_FILE) ?? []);

    if ((int)$data['revision'] === 0) {
        $company = company_normalise(store_read(COMPANY_FILE) ?? []);
        $band    = $company['milestones'] ?? [];

        $data['timeline'] = [
            'status'  => ($band['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            'eyebrow' => (string)($band['eyebrow'] ?? $data['timeline']['eyebrow']),
            'title'   => (string)($band['title'] ?? $data['timeline']['title']),
            'lead'    => (string)($band['lead'] ?? $data['timeline']['lead']),
            'items'   => array_map('milestones_entry_defaults',
                                   $band['items'] ?? []),
        ];

        $data = milestones_identify($data);
    }

    return $data;
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then send a copy to the live site.
 *
 * The record here is written FIRST, always: it is the system of record and the
 * live site holds a replica (ADR 0010). A failed publish leaves this file
 * correct and the site behind, which is recoverable; the other order is not.
 *
 * The return value is "did the write work", not "did the publish work" — see
 * company_save(), which this follows exactly.
 */
function milestones_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(MILESTONES_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('milestones', $data));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with this document, as sentences somebody can act on.
 *
 * THE YEAR RULE IS THE COMPANY EDITOR'S, MOVED — the same pattern, the same
 * message. It matters more here than it did there: milestones_recent() sorts
 * the company profile's window by reading a four-digit year out of this field,
 * and a row it cannot read is always kept rather than dropped. Refusing the
 * unreadable ones at the point somebody types them is what stops that
 * fallback from ever being the thing holding the page together.
 */
function milestones_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page needs a heading — the banner title cannot be empty.';
    }

    foreach (MILESTONES_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    foreach ($data['timeline']['items'] as $i => $row) {
        $where = 'Milestone ' . ($i + 1);

        if (trim((string)$row['title']) === '' && trim((string)$row['year']) === '') {
            $errors[] = "$where has neither a year nor a title. Give it one, or remove it.";
        }

        $year = trim((string)$row['year']);
        if ($year !== '' && !preg_match('/^[0-9]{4}(\s*[–—-]\s*[0-9]{4})?$/u', $year)) {
            $errors[] = "$where: “{$year}” is not a year. Use 2024, or 2024–2025.";
        }
    }

    return $errors;
}
