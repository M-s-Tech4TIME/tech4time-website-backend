<?php
/**
 * Tech4TIME — company profile page data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of the page is lib/contract.php, which
 * the frontend and the backend hold byte-identical. What is left here is this
 * side's own business with that shape.
 *
 * On THIS side that is: validation and the save that publishes. The AboutPage
 * structured data and the <picture> a row of artwork turns into are the
 * frontend's, because the frontend renders the page.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, company_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const COMPANY_FILE = __DIR__ . '/../content/company.json';

/**
 * Bands of the company document THIS SIDE NO LONGER EDITS OR COUNTS.
 *
 * ONE ENTRY, AND IT IS LOAD-BEARING. The milestones moved to their own screen
 * and their own document — sections/milestones.php, lib/milestones.php — and
 * the fieldset that used to edit them on the company form is gone. The band is still in COMPANY_TEXT_FIELDS,
 * COMPANY_RICH_FIELDS, COMPANY_BANDS and COMPANY_LISTS, deliberately: removing
 * it from the contract would change the meaning of every company.json already
 * written, and lib/milestones.php reads through to it until the new document
 * has been saved once.
 *
 * So every loop over COMPANY_BANDS, COMPANY_LISTS, COMPANY_TEXT_FIELDS or
 * COMPANY_RICH_FIELDS on this side has to skip it, and this is why. Every *_from_post()
 * starts from the stored document and overwrites each band it names from
 * $_POST. A band nothing renders but everything still names reads as absent,
 * ?? '' supplies an empty string, and the band is BLANKED on every save —
 * silently, because empty is a valid value and nothing throws. That is exactly
 * the failure contract_page_bands() exists to prevent for the meta band; this
 * is the same failure with a different band's name on it.
 */
const COMPANY_MOVED_BANDS = ['milestones'];

/** A by-band list with the moved bands taken out. */
function company_here(array $by_band): array
{
    foreach (COMPANY_MOVED_BANDS as $band) {
        unset($by_band[$band]);
    }
    return $by_band;
}

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the same reason contact_load() does not: a page showing
 * last week's milestones is wrong only if they changed, and a page showing a
 * PHP error is wrong for everybody.
 */
function company_load(): array
{
    return company_normalise(store_read(COMPANY_FILE) ?? []);
}

/* ------------------------------------------------------------------ write */

/**
 * Write the record, then send a copy to the live site.
 *
 * THIS RECORD IS WRITTEN FIRST, ALWAYS. It is the system of record; the live
 * site holds a replica — ADR 0010. A publish that fails leaves this file
 * correct and the site behind, which is recoverable. The other order is not.
 *
 * THE PUBLISH IS IN HERE RATHER THAN AT THE CALL SITES because the editor
 * saves from a dozen branches, and a publish added to eleven of them is a
 * publish somebody forgets at the twelfth.
 *
 * The return value is "did the write work", not "did the publish work". A
 * failed publish is reported through publish_note(), which admin_redirect()
 * turns into the retry notice — it must not send the operator back to a form
 * they have already successfully saved.
 */
function company_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(COMPANY_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('company', $data));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with this document, as sentences somebody can act on.
 *
 * Permissive about prose and strict about anything that would render as a dead
 * link, an empty heading or a picture the browser cannot size. The rule is the
 * same one careers and contact follow: refuse what would break the page, and
 * let the author write what they like otherwise.
 */
function company_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['hero']['title']) === '') {
        $errors[] = 'The page needs a heading — the banner title cannot be empty.';
    }
    /* NOTHING ABOUT THE meta BAND IS CHECKED HERE ANY MORE. Its title,
       description, breadcrumb and crawl setting are edited on the SEO screen
       and validated by seo_validate(), which is the only place that can see
       all of them at once -- two pages sharing a title is a fault neither page
       can detect on its own. Leaving the checks here as well would refuse a
       save on THIS page for a field this page no longer offers, which is a
       dead end rather than a warning. */

    foreach (COMPANY_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    /* NOTHING ABOUT THE milestones BAND IS CHECKED HERE ANY MORE, and the
       argument is contract_page_bands()' one about the meta band: the company
       form does not render it, so a fault in it is one the operator cannot
       fix on this screen — and refusing their save for it would be a dead end
       rather than a warning. The timeline is sections/milestones.php, and
       milestones_validate() carries the same year rule it used to carry here.
       See COMPANY_MOVED_BANDS. */

    /* Each list, by its own rules. */
    foreach ($data['experience']['items'] as $i => $row) {
        $where  = 'Figure ' . ($i + 1);
        $figure = trim((string)$row['figure']);

        if ($figure === '') {
            $errors[] = "$where has no number.";
        } elseif (!preg_match('/^\d/', $figure)) {
            /* animations.js counts up from /^\s*(\d+)(.*)$/ and keeps the rest
               as a suffix. "Over 100" matches nothing, so the figure silently
               never animates. Said here rather than left to be noticed. */
            $errors[] = "$where: “{$figure}” must start with a digit — the count-up "
                      . 'animation reads the number off the front. "100+" works, '
                      . '"Over 100" does not.';
        }
        if (trim((string)$row['label']) === '') {
            $errors[] = "$where has no label saying what it counts.";
        }
    }

    foreach (['clients' => 'Client', 'technology' => 'Technology'] as $band => $noun) {
        foreach ($data[$band]['items'] as $i => $row) {
            $where = "$noun " . ($i + 1);
            if (trim((string)$row['name']) === '') {
                $errors[] = "$where has no name. The name is what a screen reader "
                          . 'announces for the logo, so it cannot be blank.';
            }
            $errors = array_merge($errors, company_validate_image($row['image'], $where));
        }
    }

    foreach ($data['journey']['items'] as $i => $row) {
        $where = 'Photograph ' . ($i + 1);
        if (trim((string)$row['alt']) === '') {
            $errors[] = "$where has no description. Say what is in the picture, "
                      . 'so somebody who cannot see it is not left out.';
        }
        $errors = array_merge($errors, company_validate_image($row['image'], $where));
    }

    foreach ($data['principles']['items'] as $i => $row) {
        $where = 'Principle ' . ($i + 1);
        if (trim((string)$row['title']) === '') {
            $errors[] = "$where has no title.";
        }
        $icon = trim((string)$row['icon']);
        if ($icon !== '' && !isset(COMPANY_ICONS[$icon])) {
            $errors[] = "$where: “{$icon}” is not one of the icons this page can draw.";
        }
    }

    /* The button. Either it is complete or it is not there. */
    $label = trim((string)$data['cta']['label']);
    $href  = trim((string)$data['cta']['href']);

    if ($label !== '' && $href === '') {
        $errors[] = 'The closing button has a label but nowhere to go.';
    }
    if ($href !== '' && rt_safe_href($href) === null) {
        $errors[] = 'The closing button\'s link is not one this site will publish. '
                  . 'Use https://, mailto:, tel:, or a path starting with /.';
    }
    $icon = trim((string)$data['cta']['icon']);
    if ($icon !== '' && !isset(COMPANY_ICONS[$icon])) {
        $errors[] = "The closing button's icon “{$icon}” is not one this page can draw.";
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
function company_validate_image(array $image, string $where): array
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
