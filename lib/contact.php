<?php
/**
 * Tech4TIME — contact page data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of the contact page is
 * lib/contract.php, which the frontend and the backend hold byte-identical.
 * What is left here is this side's own business with that shape.
 *
 * On THIS side that is: validation, the flag picker, and the save that
 * publishes. The ContactPage structured data, the flag <picture> and the
 * reach-row hrefs are the frontend's, because the frontend renders the page.
 *
 * WHAT THE SHAPE IS
 *   {
 *     "updated":        set on every save
 *     "revision":       monotonic; see contract.php
 *     "meta":    { title, description, share_title }
 *     "hero":    { title, subtitle }
 *     "form":    { title, lead, subject_hint, note, service_types[] }
 *     "reach":   { status, title,
 *                  items[ { icon, label, type, values[], text, status } ] }
 *     "offices": { status, eyebrow, title, lead,
 *                  items[ { name, flag, image{}, address, phones[], hours,
 *                  languages[], status, schema{} } ] }
 *
 *   A band's status and a row's are separate switches and both are honoured:
 *   contact_shown_reach() and contact_shown_offices() answer for both, so the
 *   structured data cannot advertise a band the page does not draw.
 *
 *   An office has a flag TWICE: 'flag' is a slug naming a file that ships with
 *   the public site, and 'image' is an uploaded picture. The upload wins when
 *   it is set; the slug is what the three original offices still use.
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const CONTACT_FILE = __DIR__ . '/../content/contact.json';
const CONTACT_FLAG_DIR = __DIR__ . '/../public/assets/images/flags';

/* Raster formats a flag may be supplied in. A matching .webp beside it is used
   automatically when it exists; there is no build step on the host, so one
   dropped into the folder by hand has to work on its own. */
const CONTACT_FLAG_FORMATS = ['jpg', 'jpeg', 'png'];

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws. A contact page showing the addresses it was deployed with is
 * wrong only if they have since changed; a contact page showing a PHP error
 * gives a visitor no way to reach anyone at all.
 */
function contact_load(): array
{
    return contact_normalise(store_read(CONTACT_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then publish it. Returns whether the WRITE succeeded.
 *
 * Same shape as careers_save(), and the same reasons — see the long note
 * there.
 *
 * THERE USED TO BE A SECOND WRITE HERE. The site-wide footers repeated these
 * details as literal markup on sixteen pages, so they went stale the moment an
 * address changed here and stayed stale until the frontend was rebuilt and
 * deployed. Only the frontend knew what its own footers said, so it reported a
 * fingerprint in every publish response and this recorded it — a second
 * store_write, after the publish, so the editor could show a banner.
 *
 * None of that exists now. The frontend's footer is rendered from
 * content/chrome.json on the request, its contact rows are the footer's own
 * and are edited on the Header & Footer screen, and what keeps them honest is
 * a notice drawn from the two documents rather than a digest carried over the
 * wire. ADR 0023.
 */
function contact_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(CONTACT_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('contact', $data));

    return true;
}

/* --------------------------------------------------------- the flag picker */

/** The flag images available to choose from, by basename. */
function contact_flags(): array
{
    $found = [];
    foreach (CONTACT_FLAG_FORMATS as $ext) {
        foreach (glob(CONTACT_FLAG_DIR . '/*.' . $ext) ?: [] as $path) {
            $found[pathinfo($path, PATHINFO_FILENAME)] = true;
        }
    }
    ksort($found);
    return array_keys($found);
}

/**
 * The web path of a built-in flag, or '' when there is no such file.
 *
 * The editor draws this rather than describing it. Found by looking on disk in
 * the same order the public renderer looks, so the thumbnail beside an office
 * and the flag on the live page are the same file — a slug naming something
 * that is not there answers '', and the editor then says there is no flag,
 * which is the truth.
 */
function contact_flag_file(string $slug): string
{
    $slug = trim($slug);

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        return '';
    }

    foreach (CONTACT_FLAG_FORMATS as $ext) {
        if (is_file(CONTACT_FLAG_DIR . '/' . $slug . '.' . $ext)) {
            return '/assets/images/flags/' . $slug . '.' . $ext;
        }
    }

    return '';
}

/* ------------------------------------------------------------- validation */

/**
 * Validate the whole document. Returns a list of human-readable problems.
 *
 * Deliberately permissive about prose: an empty lead is a plainer page, not an
 * invalid one. What it does insist on is anything that would render as a
 * broken link or an address a search engine will reject, because those fail
 * silently rather than visibly.
 */
function contact_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page title in the banner is required.';
    }
    /* NOTHING ABOUT THE meta BAND IS CHECKED HERE ANY MORE. Its title,
       description, breadcrumb and crawl setting are edited on the SEO screen
       and validated by seo_validate(), which is the only place that can see
       all of them at once -- two pages sharing a title is a fault neither page
       can detect on its own. Leaving the checks here as well would refuse a
       save on THIS page for a field this page no longer offers, which is a
       dead end rather than a warning. */

    foreach ($data['reach']['items'] as $i => $item) {
        $where = 'Reach row ' . ($i + 1);
        $type = (string)($item['type'] ?? '');

        if (!isset(CONTACT_REACH_TYPES[$type])) {
            $errors[] = "$where has no valid kind.";
        }
        if (trim((string)($item['label'] ?? '')) === '') {
            $errors[] = "$where needs a label.";
        }
        if (($item['icon'] ?? '') !== '' && !isset(CONTACT_ICONS[$item['icon']])) {
            $errors[] = "$where has an icon that is not in the list.";
        }
        if (!$item['values']) {
            $errors[] = "$where needs at least one value.";
            continue;
        }

        /* Every line, not just the first: a row of four numbers with a typo in
           the third is a row with a dead link in it. */
        foreach ($item['values'] as $value) {
            if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "$where is marked as an email address but “{$value}” is not one.";
            }
            if ($type === 'url' && rt_safe_href($value) === null) {
                $errors[] = "$where: “{$value}” must be a full web address starting with https://";
            }
        }
    }

    foreach ($data['offices']['items'] as $i => $office) {
        $where = 'Office ' . ($i + 1);
        if (trim((string)$office['name']) === '') {
            $errors[] = "$where needs a name.";
        }
        $country = trim((string)$office['schema']['country']);
        if ($country !== '' && !preg_match('/^[A-Za-z]{2}$/', $country)) {
            $errors[] = "$where: the country code must be two letters, like BD or MY.";
        }
        if (!in_array($office['status'], ['shown', 'hidden'], true)) {
            $errors[] = "$where must be either shown or hidden.";
        }
    }

    return $errors;
}
