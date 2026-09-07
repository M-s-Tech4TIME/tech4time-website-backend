<?php
/**
 * Tech4TIME — services data access, on the editing side.
 *
 * Reading and writing the file is lib/store.php; the SHAPE of the pages is
 * lib/contract.php, which this repository and the frontend hold byte-identical.
 * What is left here is this side's own business with that shape: loading it,
 * saving it, sending it, and saying what is wrong with it.
 *
 * The frontend has a file of the same name and it is NOT the same file. That
 * one draws seven pages; this one has no renderer in it at all, because
 * nothing here draws the public site. check_shared_repos.py knows they differ
 * by design.
 *
 * ONE DOCUMENT, SEVEN PAGES: the services index and all six detail pages are
 * rows of content/services.json, because a seventh service has to be addable
 * from the editor and CONTRACT_DOCUMENTS is a constant in code. See the note
 * over services_defaults() in contract.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const SERVICES_FILE = __DIR__ . '/../content/services.json';

/* ------------------------------------------------------------------- read */

/**
 * The document as it should be edited.
 *
 * Never throws, for the same reason about_load() does not: an editor showing
 * last week's prose is wrong only if it changed, and one showing a PHP error
 * is wrong for everybody.
 */
function services_load(): array
{
    return services_normalise(store_read(SERVICES_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then send a copy to the live site.
 *
 * THIS RECORD IS WRITTEN FIRST, ALWAYS. It is the system of record; the live
 * site holds a replica — ADR 0010. A publish that fails leaves this file
 * correct and the site behind, which is recoverable. The other order is not.
 *
 * The return value is "did the write work", not "did the publish work".
 */
function services_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(SERVICES_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('services', $data));

    return true;
}

/**
 * Save ONE service back into the stored document, under a lock.
 *
 * THIS IS THE ONE PLACE IN THE ADMIN THAT DOES A READ-MODIFY-WRITE, and it has
 * to, so it goes through store_edit() rather than store_read() then
 * store_write().
 *
 * Every other editor rebuilds its whole document from the form, so two people
 * saving at once means the later save wins entirely — bad, but not silently
 * destructive of anything the form did not contain. This editor is split by
 * service, because a form holding two of them would exceed max_input_vars and
 * drop its own tail (see admin_form_truncated()). So the form carries ONE
 * service and the other five have to be merged back from the file — and if
 * that file is read, edited and written without a lock, two people editing two
 * DIFFERENT services at the same time lose one of the two edits. That is not a
 * hypothetical: it is the normal case for this screen.
 *
 * $mutate is handed the STORED document, already normalised, and returns the
 * one to write — or null to leave the file alone. The callback below takes its
 * argument BY REFERENCE because that is store_edit()'s contract: it writes back
 * the variable it passed in, not what the callback returned, and a by-value
 * signature here would lock the file, do the work and save nothing.
 *
 * Returns whether it published, so the caller can report a failed send. The
 * publish happens outside the lock: it is an HTTP request to another host, and
 * holding an exclusive lock on the document across it would make every other
 * editor wait on that host being up.
 */
function services_edit(callable $mutate): bool
{
    $written = store_edit(SERVICES_FILE, static function (array &$data) use ($mutate): ?array {
        $data = services_normalise($data);
        $next = $mutate($data);

        if ($next === null) {
            return null;
        }

        $next['updated']  = gmdate('c');
        $next['revision'] = contract_next_revision($next);

        $data = $next;

        return $next;
    });

    if ($written === null) {
        return false;
    }

    publish_note(publish_push('services', $written));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with this document, as sentences somebody can act on.
 *
 * Permissive about prose and strict about anything that would render as a dead
 * link, an empty heading or a page that cannot be reached — the same rule
 * careers, contact, the company profile and the about page follow.
 */
function services_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The services page needs a heading — the banner title cannot be empty.';
    }
    /* NOTHING ABOUT THE meta BAND IS CHECKED HERE ANY MORE. Its title,
       description, breadcrumb and crawl setting are edited on the SEO screen
       and validated by seo_validate(), which is the only place that can see
       all of them at once -- two pages sharing a title is a fault neither page
       can detect on its own. Leaving the checks here as well would refuse a
       save on THIS page for a field this page no longer offers, which is a
       dead end rather than a warning. */

    foreach (SERVICES_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    foreach ($data['blocks']['items'] as $i => $block) {
        $where = 'Service block ' . ($i + 1);
        if (trim((string)$block['title']) === '') {
            $errors[] = "$where has no heading. The heading is what the block is "
                      . 'announced as, so it cannot be blank.';
        }
        foreach ($block['buttons'] as $j => $button) {
            if (trim((string)$button['href']) === '') {
                $errors[] = "$where, button " . ($j + 1) . ' has no link. A button '
                          . 'that goes nowhere is a dead end on a live page.';
            }
        }
    }

    $errors = array_merge($errors, services_validate_list($data));

    return $errors;
}

/**
 * What is wrong with the LIST of services — not with any of their contents.
 *
 * The index screen owns which services there are, in what order, called what
 * and at what address. It does not show what is on any of them, so it does not
 * judge it: a service added a moment ago and not yet filled in would otherwise
 * make the index unsaveable, reporting faults on a page the operator is not
 * looking at and cannot see from here. It arrives hidden, so an unfinished page
 * is never live, and its own screen refuses to save it until it is whole.
 */
function services_validate_list(array $data): array
{
    $errors = [];
    $slugs  = [];

    foreach (services_all($data) as $i => $service) {
        $where = trim((string)$service['name']) !== ''
            ? 'The ' . $service['name'] . ' page'
            : 'Service ' . ($i + 1);

        if (trim((string)$service['name']) === '') {
            $errors[] = "$where has no name.";
        }

        $slug = trim((string)$service['slug']);
        if ($slug === '') {
            $errors[] = "$where has no web address. Without one the page cannot "
                      . 'be reached at all.';
        } elseif (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors[] = "$where has a web address that is not usable: \"$slug\". "
                      . 'Use lower-case letters, digits and single hyphens.';
        } elseif (in_array($slug, $slugs, true)) {
            $errors[] = "$where has the same web address as another service. "
                      . 'Two pages cannot live at one address — change one of them.';
        }
        $slugs[] = $slug;
    }

    return $errors;
}

/** What is wrong with one detail page. */
function services_validate_one(array $service, string $where): array
{
    $errors = [];

    if (trim((string)$service['name']) === '') {
        $errors[] = "$where has no name. The name is the heading, the breadcrumb "
                  . 'and the structured data, so it cannot be blank.';
    }
    if (trim((string)$service['hero']['title']) === '') {
        $errors[] = "$where has no banner title.";
    }
    /* A service's meta band is not checked here either. Same reason as the
       index's above: the SEO screen owns it. */

    $slug = trim((string)$service['slug']);
    if ($slug === '') {
        $errors[] = "$where has no web address. Without one the page cannot be "
                  . 'reached at all.';
    } elseif (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
        $errors[] = "$where has a web address that is not usable: \"$slug\". Use "
                  . 'lower-case letters, digits and single hyphens.';
    }

    $note = $service['core']['note'];
    if (trim((string)$note['link_label']) !== '' && trim((string)$note['link_href']) === '') {
        $errors[] = "$where has a note whose link has a label but no address.";
    }

    if (trim((string)$service['cta']['label']) !== ''
            && trim((string)$service['cta']['href']) === '') {
        $errors[] = "$where has a closing button with no link.";
    }

    $labels = $service['layers']['labels'];

    foreach ($service['layers']['items'] as $i => $layer) {
        $lwhere = "$where, group " . ($i + 1);

        if (trim((string)$layer['title']) === '') {
            $errors[] = "$lwhere has no name. It is the tab and the heading, so it "
                      . 'cannot be blank.';
        }

        foreach ($layer['cards'] as $j => $card) {
            $cwhere = "$lwhere, solution " . ($j + 1);

            if (trim((string)$card['name']) === '') {
                $errors[] = "$cwhere has no name. Every card is announced by its "
                          . 'name and reached by it from the ring, so it cannot be blank.';
            }
            /* A list with no heading over it renders as loose text with no
               explanation, so the two have to arrive together. */
            if ($card['features'] && trim((string)$labels['features']) === '') {
                $errors[] = "$cwhere lists what it includes, but this page has no "
                          . 'heading set for that list, so it would not be shown. '
                          . 'Set one at the top of the page, or clear the list.';
                break;
            }
            if ($card['tags'] && trim((string)$labels['tags']) === '') {
                $errors[] = "$cwhere lists tools, but this page has no heading set "
                          . 'for that list, so it would not be shown. Set one at the '
                          . 'top of the page, or clear the list.';
                break;
            }
        }
    }

    return $errors;
}
