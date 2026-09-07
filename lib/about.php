<?php
/**
 * Tech4TIME — about page data access.
 *
 * Reading and writing the file is lib/store.php; escaping and rich-text
 * sanitising is lib/html.php; the SHAPE of the page is lib/contract.php, which
 * the frontend and the backend hold byte-identical. What is left here is this
 * side's own business with that shape.
 *
 * On THIS side that is: validation and the save that publishes. The AboutPage
 * structured data, the <picture> a story row turns into and the scroll-reveal
 * markers its prose carries are the frontend's, because the frontend renders
 * the page.
 *
 * WHAT THE SHAPE IS — see lib/contract.php, about_defaults().
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';

const ABOUT_FILE = __DIR__ . '/../content/about.json';

/* ------------------------------------------------------------------- read */

/**
 * Load the file, or the shipped defaults if it is missing or unreadable.
 *
 * Never throws, for the same reason company_load() does not: a page showing
 * last week's prose is wrong only if it changed, and a page showing a PHP
 * error is wrong for everybody.
 */
function about_load(): array
{
    return about_normalise(store_read(ABOUT_FILE) ?? []);
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
function about_save(array $data): bool
{
    $data['updated']  = gmdate('c');
    $data['revision'] = contract_next_revision($data);

    if (!store_write(ABOUT_FILE, $data)) {
        return false;
    }

    publish_note(publish_push('about', $data));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with this document, as sentences somebody can act on.
 *
 * Permissive about prose and strict about anything that would render as a dead
 * link, an empty heading or a picture the browser cannot size — the same rule
 * careers, contact and the company profile follow.
 */
function about_validate(array $data): array
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

    foreach (ABOUT_BANDS as $band) {
        if (!in_array($data[$band]['status'] ?? 'shown', ['shown', 'hidden'], true)) {
            $errors[] = 'A section was given a state that is neither shown nor hidden.';
            break;
        }
    }

    /* The story sections. */
    foreach ($data['story']['items'] as $i => $row) {
        $where = 'Section ' . ($i + 1);

        if (trim((string)$row['heading']) === '') {
            $errors[] = "$where has no heading. The heading is what the section is "
                      . 'announced as, so it cannot be blank.';
        }
        if (rt_plain((string)$row['body']) === '') {
            $errors[] = "$where has no text.";
        }
        if (!isset(ABOUT_LAYOUTS[(string)$row['layout']])) {
            $errors[] = "$where was given a layout this page cannot draw.";
        }
        if (!isset(ABOUT_SIDES[(string)$row['side']])) {
            $errors[] = "$where was given a side that is neither left nor right.";
        }

        /* Whatever the layout, the picture has to be described: a logo row
           announces its lockup the same way a photograph row announces its
           photograph. */
        if (trim((string)$row['alt']) === '') {
            $errors[] = "$where has no picture description. Say what is in the "
                      . 'picture, so somebody who cannot see it is not left out.';
        }

        if ((string)$row['layout'] === 'logo') {
            /* A logo row needs NO picture — it falls back to the lockup that
               ships with the site. But one it has been given is checked like
               any other, because a logo with no dimensions shifts the page as
               it loads exactly as a photograph does. */
            foreach (['image' => $where, 'image_dark' => "$where (dark mode)"] as $field => $label) {
                if (trim((string)$row[$field]['src']) !== '') {
                    $errors = array_merge($errors, about_validate_image($row[$field], $label));
                }
            }
        } else {
            /* A photograph row needs its light half and may have a dark one.
               The dark half is optional and almost always empty: the
               illustrations sit on a white plate in both colour modes by
               design, so one picture is the normal case. Whichever halves are
               present are checked the same way, because a picture with no
               dimensions shifts the page as it loads whichever mode it is
               for. */
            $errors = array_merge($errors, about_validate_image($row['image'], $where));

            if (trim((string)$row['image_dark']['src']) !== '') {
                $errors = array_merge(
                    $errors,
                    about_validate_image($row['image_dark'], "$where (dark mode)")
                );
            }
        }
    }

    /* The two card lists, by the same rules. */
    foreach (['specialties' => 'Speciality', 'whyus' => 'Reason'] as $band => $noun) {
        foreach ($data[$band]['items'] as $i => $row) {
            $where = "$noun " . ($i + 1);

            if (trim((string)$row['title']) === '') {
                $errors[] = "$where has no title.";
            }
            if (trim((string)$row['text']) === '') {
                $errors[] = "$where has no text.";
            }
            $icon = trim((string)$row['icon']);
            if ($icon !== '' && !isset(ABOUT_ICONS[$icon])) {
                $errors[] = "$where: “{$icon}” is not one of the icons this page can draw.";
            }
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
    if ($icon !== '' && !isset(ABOUT_ICONS[$icon])) {
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
function about_validate_image(array $image, string $where): array
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
