<?php
/**
 * Tech4TIME — the chrome: header, footer and dock, the model this side writes.
 *
 * Reading and writing the file is lib/store.php; the SHAPE is lib/contract.php,
 * which the frontend and the backend hold byte-identical. What is left here is
 * this side's own business with that shape: validation, the save, the import,
 * and the list of destinations a link may point at.
 *
 * THE CHROME IS THE FURNITURE AROUND EVERY PAGE. It was literal markup in
 * seventeen page files of the public site -- about 6,800 lines of duplication
 * -- and nothing in it could be changed without a developer and a deploy.
 * ADR 0023.
 *
 * THIS SIDE HAS NO RENDERER, so there is nothing here that draws a header.
 * tech4time-website-frontend/lib/chrome.php is the other half of this file and
 * holds the reverse: chrome_link(), chrome_social(), chrome_sprite() and the
 * rest of the resolution the emitter needs, and none of the writing below.
 * That split is the one lib/company.php and lib/privacy.php already use.
 *
 * A LINK POINTS AT A ROUTE, NEVER AT A URL. Every destination is a key of
 * chrome_targets() -- 'about', 'service:cybersecurity' -- and chrome_validate()
 * refuses anything else. SEO_ROUTES already says routes are code and cannot be
 * added, renamed or removed from the editor; this follows from it. The nav is
 * the one component on every page of the site, and a nav that can point
 * anywhere can point at a 404.
 *
 * TWO COLUMNS OF THE FOOTER ARE NOT EDITABLE AND ARE NOT MISSING. The services
 * list is read from content/services.json as the page renders and the social
 * links from the SEO document's sameas rows, so both are edited in one place
 * and neither can go stale. Only their heading is here.
 *
 * AND THE CONTACT ROWS DELIBERATELY ARE. They are the footer's own -- added,
 * worded, ordered, shown and hidden here -- and owe nothing to
 * content/contact.json. What keeps the two honest is chrome_contact_drift() in
 * the contract, which the editor draws as a standing NOTICE. Nothing here
 * refuses a save over it, and nothing may be made to: after an office moves,
 * whichever page you edited first would be unsavable if agreement were a rule.
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/contact.php';

const CHROME_FILE = __DIR__ . '/../content/chrome.json';

/* ------------------------------------------------------------------- read */

/** The document, or the shipped defaults if it is missing. */
function chrome_load(): array
{
    return chrome_normalise(store_read(CHROME_FILE) ?? []);
}

/**
 * Every destination a link row may point at, worked out once per request.
 *
 * The same function the frontend's picker-free renderer resolves against, in
 * the contract, so the editor cannot offer a destination the site cannot draw.
 * It is handed the services document rather than reading one, which is what
 * keeps the contract a pure function of its input.
 */
function chrome_target_list(): array
{
    static $targets = null;

    if ($targets === null) {
        $targets = chrome_targets(services_load());
    }

    return $targets;
}

/* ------------------------------------------------------------------ write */

/**
 * Change the document under a lock, then publish it.
 *
 * $mutate is handed the normalised document and returns the new one, or null
 * to abandon the write. Modelled line for line on seo_edit().
 *
 * IT LOCKS FOR THE SAME REASON SEO DOES. Each screen holds one PART of this
 * document -- the header screen never sees the footer's rows -- so a save has
 * to merge the rest back from the file. A read-modify-write without a lock
 * loses one of two concurrent edits to different parts, which here is the
 * normal case rather than an edge one.
 */
function chrome_edit(callable $mutate): bool
{
    $written = store_edit(CHROME_FILE, static function (array &$data) use ($mutate): ?array {
        $data = chrome_normalise($data);
        $next = $mutate($data);

        if ($next === null) {
            return null;
        }

        $next = chrome_normalise($next);
        $next['updated']  = gmdate('c');
        $next['revision'] = contract_next_revision($next);

        $data = $next;

        return $next;
    });

    if ($written === null) {
        return false;
    }

    publish_note(publish_push('chrome', $written));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with a document, as sentences for the editor.
 *
 * REFUSES ONLY WHAT WOULD RENDER WRONG, which is a shorter list than it looks.
 * chrome_normalise() has already dropped an unknown icon, capped the bar at
 * CHROME_BAR_SLOTS and fallen back on an unknown contact kind, so nothing here
 * repeats that work. What is left is the two things normalising cannot decide:
 * a link that goes nowhere, and a heading or label that would render as an
 * empty element on every page of the site.
 *
 * A HIDDEN ROW IS NOT VALIDATED. Hiding is how somebody parks a row they are
 * still working on, and a half-finished row that cannot be saved is a row that
 * has to be finished or deleted -- which is the opposite of what hiding is
 * for. Nothing hidden reaches a visitor, so nothing hidden can be wrong.
 *
 * ONE PART AT A TIME, and that is not a convenience. Each screen holds one
 * part and merges the other two back from the file, so judging the whole
 * document would let a fault in the header -- typed by somebody else, an hour
 * ago, on another screen -- refuse a save on the footer. The person at the
 * footer screen cannot see it, cannot fix it from there, and is simply stuck.
 * Pass '' to judge all three, which is what a check outside the editor wants.
 */
function chrome_validate(array $data, string $part = ''): array
{
    $errors  = [];
    $targets = chrome_target_list();
    $wanted  = $part === '' ? CHROME_PARTS : [$part];

    /* Every link band, by the name a person would recognise it under, and by
       the part whose screen holds it. */
    $bands = [];
    foreach ([
        ['header', 'The main navigation',  $data['header']['nav']['items']],
        ['footer', 'Quick Links',          $data['footer']['links']['items']],
        ['footer', 'The bottom-bar links', $data['footer']['legal']['items']],
        ['dock',   'The dock panel',       $data['dock']['panel']['items']],
        ['dock',   'The dock bar',         $data['dock']['bar']['items']],
    ] as [$owner, $where, $rows]) {
        if (in_array($owner, $wanted, true)) {
            $bands[$where] = $rows;
        }
    }

    foreach ($bands as $where => $rows) {
        foreach (chrome_rows_shown($rows) as $i => $row) {
            $target = trim((string)($row['target'] ?? ''));

            if ($target === '') {
                $errors[] = $where . ': row ' . ($i + 1) . ' has no destination. '
                          . 'Pick the page it should go to, or hide the row.';
                continue;
            }

            if (!isset($targets[$target])) {
                $errors[] = $where . ': row ' . ($i + 1) . ' points at a page that no '
                          . 'longer exists. Pick another, or remove the row.';
            }
        }
    }

    /* The dock bar's labels are typed rather than left to the route, because
       "Profile" is what fits under a 44px key where "Company Profile" is what
       fits in a nav. So an empty one is an empty key, not a fallback. */
    foreach (in_array('dock', $wanted, true) ? $data['dock']['bar']['items'] : [] as $i => $row) {
        if (trim((string)($row['label'] ?? '')) === '') {
            $errors[] = 'The dock bar: key ' . ($i + 1) . ' has no label.';
        }
        if (trim((string)($row['icon'] ?? '')) === '') {
            $errors[] = 'The dock bar: key ' . ($i + 1) . ' has no icon.';
        }
    }

    /* Headings, which are <h2>s that two of the columns are labelled BY --
       aria-labelledby points at them, so an empty one leaves a navigation
       landmark with no accessible name on every page of the site. */
    foreach (in_array('footer', $wanted, true) ? [
        'The Quick Links heading' => $data['footer']['links']['heading'],
        'The services heading'    => $data['footer']['services']['heading'],
        'The services link'       => $data['footer']['services']['index_label'],
        'The contact heading'     => $data['footer']['contact']['heading'],
    ] : [] as $what => $value) {
        if (trim((string)$value) === '') {
            $errors[] = $what . ' cannot be empty.';
        }
    }

    /* The two accessible names on the logo links, which are what a screen
       reader announces in place of a picture. */
    foreach (['header' => 'The header', 'footer' => 'The footer'] as $owner => $what) {
        if (!in_array($owner, $wanted, true)) {
            continue;
        }

        if (trim((string)$data[$owner]['brand_label']) === '') {
            $errors[] = $what . '\'s logo link has no description.';
        }
        if (trim((string)$data[$owner]['logo']['alt']) === '') {
            $errors[] = $what . '\'s logo has no alt text.';
        }
        foreach (['light' => 'light mode', 'dark' => 'dark mode'] as $mode => $when) {
            /* Empty covers two cases and the message has to cover both: a
               field left blank, and a field filled in with somebody else's
               address, which contract_safe_image_path() refuses and empties
               before this ever sees it. */
            if (trim((string)$data[$owner]['logo'][$mode]['src']) === '') {
                $errors[] = $what . '\'s logo has no picture for ' . $when . '. '
                          . 'It must be a path on this site, like '
                          . '/assets/images/logo/logo-light-360.png — another '
                          . 'site\'s address is refused.';
            }
        }
    }

    /* A shown contact row with nothing in it is an icon beside white space. */
    foreach (in_array('footer', $wanted, true)
             ? chrome_rows_shown($data['footer']['contact']['items']) : [] as $i => $row) {
        if (($row['lines'] ?? []) === []) {
            $errors[] = 'Contact row ' . ($i + 1) . ' has no details in it. '
                      . 'Type at least one line, or hide the row.';
        }
    }

    return $errors;
}

/* --------------------------------------------------------------- the copy */

/**
 * The footer's contact rows, as content/contact.json would write them.
 *
 * Returns rows in the shape chrome_contact_defaults() gives them, with no ids:
 * chrome_identify() mints those from the labels, which is how a row imported
 * today gets the same id the shipped one has.
 *
 * IT SEEDS; IT DOES NOT SYNC. What comes back is one reading of the contact
 * page, in the order a footer wants it -- every telephone, the email, every
 * address, then the opening hours. What it cannot bring is the wording that
 * makes a footer a footer: the shipped rows carry notes like "Sunday –
 * Thursday" that the contact page has no field for. So the button that calls
 * this fills the FORM and saves nothing, and the operator edits from there.
 *
 * A HIDDEN OFFICE IS NOT IMPORTED. It is not on the contact page either.
 */
function chrome_contact_import(): array
{
    $contact = contact_load();
    $offices = contract_rows_shown($contact['offices']['items'] ?? []);
    $rows    = [];

    foreach ($offices as $office) {
        $phones = array_values(array_filter(array_map(
            static fn($p): string => trim((string)$p), $office['phones'] ?? [])));

        if ($phones !== []) {
            $rows[] = ['kind' => 'phone', 'label' => trim((string)$office['name']),
                       'lines' => $phones, 'note' => '', 'status' => 'shown'];
        }
    }

    $email = contact_email($contact);
    if ($email !== '') {
        $rows[] = ['kind' => 'email', 'label' => '', 'lines' => [$email],
                   'note' => '', 'status' => 'shown'];
    }

    foreach ($offices as $office) {
        $address = trim((string)($office['address'] ?? ''));
        if ($address !== '') {
            $rows[] = ['kind' => 'address', 'label' => trim((string)$office['name']),
                       'lines' => [$address], 'note' => '', 'status' => 'shown'];
        }
    }

    foreach ($offices as $office) {
        $hours = trim((string)($office['hours'] ?? ''));
        if ($hours !== '') {
            /* "Bangladesh Office" rather than "Bangladesh": the label sits
               above opening hours here, where the office's name alone reads
               as an address. */
            $rows[] = ['kind' => 'hours',
                       'label' => trim(trim((string)$office['name']) . ' Office'),
                       'lines' => [$hours], 'note' => '', 'status' => 'shown'];
        }
    }

    return array_map('chrome_contact_defaults', $rows);
}
