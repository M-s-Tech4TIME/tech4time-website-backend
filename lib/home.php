<?php
/**
 * Tech4TIME — home page data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of the page is lib/contract.php, which
 * the frontend and the backend hold byte-identical. What is left here is this
 * side's own business with that shape.
 *
 * On THIS side that is: validation and the save that publishes. The Service
 * ItemList structured data, the hero title's accent span, the terminal's lines
 * and the <picture> a destination card turns into are the frontend's, because
 * the frontend renders the page.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, home_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const HOME_FILE = __DIR__ . '/../content/home.json';

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the same reason about_load() does not: a page showing last
 * week's wording is wrong only if it changed, and a page showing a PHP error is
 * wrong for everybody. On this page that argument is at its strongest, because
 * this page is the site's front door.
 */
function home_load(): array
{
    return home_normalise(store_read(HOME_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then send a copy to the live site.
 *
 * THIS RECORD IS WRITTEN FIRST, ALWAYS. It is the system of record; the live
 * site holds a replica — ADR 0010. A publish that fails leaves this file
 * correct and the site behind, which is recoverable. The other order is not.
 *
 * The return value is "did the write work", not "did the publish work". A
 * failed publish is reported through publish_note(), which admin_redirect()
 * turns into the retry notice — it must not send the operator back to a form
 * they have already successfully saved.
 */
function home_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(HOME_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('home', $data));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with this document, as sentences somebody can act on.
 *
 * Permissive about prose and strict about anything that would render as a dead
 * link, an empty heading or a picture the browser cannot size — the same rule
 * careers, contact, the company profile and about follow.
 */
function home_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page needs a heading — the hero title cannot be empty.';
    }
    /* NOTHING ABOUT THE meta BAND IS CHECKED HERE ANY MORE. Its title,
       description, breadcrumb and crawl setting are edited on the SEO screen
       and validated by seo_validate(), which is the only place that can see
       all of them at once -- two pages sharing a title is a fault neither page
       can detect on its own. Leaving the checks here as well would refuse a
       save on THIS page for a field this page no longer offers, which is a
       dead end rather than a warning. */

    /* A WARNING AND NOT AN ERROR would be wrong here, because there is nowhere
       to put a warning: the accent silently doing nothing is exactly the
       failure somebody would not notice for months. A phrase that is not in
       the title is refused, and the message says both halves so the mismatch
       is obvious without hunting. */
    $accent = trim((string)$data['hero']['accent']);
    $title  = (string)$data['hero']['title'];
    if ($accent !== '' && !str_contains($title, $accent)) {
        $errors[] = "The highlighted phrase “{$accent}” does not appear in the hero "
                  . "title “" . trim($title) . "”, so nothing would be highlighted. "
                  . 'It has to match the title exactly, including capitals.';
    }

    $href = trim((string)$data['hero']['cta_href']);
    if (trim((string)$data['hero']['cta_label']) !== '' && $href === '') {
        $errors[] = 'The hero button has a label but nowhere to go.';
    }
    if ($href !== '' && rt_safe_href($href) === null) {
        $errors[] = 'The hero button\'s link is not one this site will publish. '
                  . 'Use https://, mailto:, tel:, or a path starting with /.';
    }

    foreach (HOME_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    /* The two icon-and-label lists in the hero. */
    foreach (['badges' => 'Badge', 'tags' => 'Tag'] as $band => $noun) {
        foreach ($data[$band]['items'] as $i => $row) {
            $where = "$noun " . ($i + 1);

            if (trim((string)$row['label']) === '') {
                $errors[] = "$where has no wording.";
            }
            $errors = array_merge($errors, home_validate_icon($row['icon'], $where));
        }
    }

    /* The terminal. Its text may say anything at all — it is a picture of a
       shell — but its kind and tone have to be ones the page can draw. */
    foreach ($data['terminal']['items'] as $i => $row) {
        $where = 'Terminal line ' . ($i + 1);

        if (trim((string)$row['text']) === '') {
            $errors[] = "$where is empty. Remove it, or give it something to say.";
        }
        if (!isset(HOME_LINE_KINDS[(string)$row['kind']])) {
            $errors[] = "$where was given a kind that is neither a command nor output.";
        }
        if (!isset(HOME_LINE_TONES[(string)$row['tone']])) {
            $errors[] = "$where was given a colour this page cannot draw.";
        }
    }
    if (trim((string)$data['terminal']['summary']) === ''
        && $data['terminal']['items'] !== []) {
        $errors[] = 'The terminal has no one-line description. The panel itself is '
                  . 'hidden from screen readers, so that sentence is all they get.';
    }

    /* The capability cards: an icon and a title, nothing else. */
    foreach ($data['capabilities']['items'] as $i => $row) {
        $where = 'Domain ' . ($i + 1);

        if (trim((string)$row['title']) === '') {
            $errors[] = "$where has no title.";
        }
        $errors = array_merge($errors, home_validate_icon($row['icon'], $where));
    }

    /* The service cards. */
    foreach ($data['services']['items'] as $i => $row) {
        $errors = array_merge(
            $errors,
            home_validate_link_card($row, 'Service ' . ($i + 1), true)
        );
    }

    /* The destination cards, which additionally carry artwork. */
    foreach ($data['destinations']['items'] as $i => $row) {
        $where  = 'Card ' . ($i + 1);
        $errors = array_merge($errors, home_validate_link_card($row, $where, false));

        if (trim((string)$row['alt']) === '') {
            $errors[] = "$where has no picture description. Say what is in the "
                      . 'picture, so somebody who cannot see it is not left out.';
        }

        /* The light half is required and the dark half is not: a card with one
           picture shows it in both colour modes, which is every card today.
           Each half that IS there is checked the same way, because a picture
           with no dimensions shifts the page as it loads whichever mode it is
           for. */
        $errors = array_merge($errors, home_validate_image($row['image'], $where));

        if (trim((string)$row['image_dark']['src']) !== '') {
            $errors = array_merge(
                $errors,
                home_validate_image($row['image_dark'], "$where (dark mode)")
            );
        }
    }

    /* The closing panel. */
    $label = trim((string)$data['cta']['label']);
    $href  = trim((string)$data['cta']['href']);

    if ($label !== '' && $href === '') {
        $errors[] = 'The closing button has a label but nowhere to go.';
    }
    if ($href !== '' && rt_safe_href($href) === null) {
        $errors[] = 'The closing button\'s link is not one this site will publish. '
                  . 'Use https://, mailto:, tel:, or a path starting with /.';
    }
    $errors = array_merge(
        $errors, home_validate_icon($data['cta']['icon'], 'The closing panel')
    );

    return $errors;
}

/** One icon name, against the list the page can actually draw. */
function home_validate_icon(mixed $icon, string $where): array
{
    $icon = trim((string)$icon);

    if ($icon === '' || isset(HOME_ICONS[$icon])) {
        return [];
    }

    return ["$where: “{$icon}” is not one of the icons this page can draw."];
}

/**
 * A card that carries a title, some text and a link — a service or a
 * destination. Both shapes are the same in every way that can be wrong.
 */
function home_validate_link_card(array $row, string $where, bool $needsIcon): array
{
    $errors = [];

    if (trim((string)$row['title']) === '') {
        $errors[] = "$where has no title.";
    }
    if (trim((string)$row['text']) === '') {
        $errors[] = "$where has no text.";
    }

    if ($needsIcon) {
        $errors = array_merge($errors, home_validate_icon($row['icon'], $where));
    }

    $label = trim((string)$row['label']);
    $href  = trim((string)$row['href']);

    if ($label !== '' && $href === '') {
        $errors[] = "$where has a button label but nowhere to go.";
    }
    if ($href !== '' && rt_safe_href($href) === null) {
        $errors[] = "$where: the link is not one this site will publish. "
                  . 'Use https://, mailto:, tel:, or a path starting with /.';
    }

    return $errors;
}

/**
 * What is wrong with one picture.
 *
 * The dimensions are checked because they are what keeps Cumulative Layout
 * Shift at zero: a picture with no size reserves no box, and the page moves
 * under the reader when it loads. Every upload sets them from the file itself,
 * so a row without them came from somewhere else.
 */
function home_validate_image(array $image, string $where): array
{
    $errors = [];
    $src = trim((string)$image['src']);

    if ($src === '') {
        $errors[] = "$where has no picture.";
        return $errors;
    }

    if (!str_starts_with($src, '/')) {
        $errors[] = "$where: the picture must be a path on this site, starting with /.";
    }
    if ((int)$image['width'] <= 0 || (int)$image['height'] <= 0) {
        $errors[] = "$where: the picture has no width and height. Without them the "
                  . 'page shifts as it loads. Re-upload it and they are read from '
                  . 'the file.';
    }

    $webp = trim((string)$image['webp']);
    if ($webp !== '' && !str_starts_with($webp, '/')) {
        $errors[] = "$where: the WebP version must be a path on this site.";
    }

    return $errors;
}
