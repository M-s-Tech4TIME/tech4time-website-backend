<?php
/**
 * Tech4TIME — privacy policy page data access.
 *
 * Reading and writing the file is lib/store.php; the SHAPE of the page is
 * lib/contract.php, which the frontend and the backend hold byte-identical.
 * What is left here is this side's own business with that shape: validation,
 * the save that publishes, and the comparison against the contact page.
 *
 * Body text is Markdown, rendered by the shared lib/markdown.php. Nothing
 * here sanitises it and nothing here renders it: the source is stored
 * verbatim (trimmed), and safety lives in the renderer, which escapes every
 * text run and emits only a fixed tag vocabulary -- proven by
 * tools/test_markdown.py in both repositories. Sanitising Markdown with an
 * HTML sanitiser would entity-mangle it, so the one thing this file must
 * never do is pass a body through rt_sanitise_*().
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
    /* NOTHING ABOUT THE meta BAND IS CHECKED HERE ANY MORE. Its title,
       description, breadcrumb and crawl setting are edited on the SEO screen
       and validated by seo_validate(), which is the only place that can see
       all of them at once -- two pages sharing a title is a fault neither page
       can detect on its own. Leaving the checks here as well would refuse a
       save on THIS page for a field this page no longer offers, which is a
       dead end rather than a warning. */

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
        /* Rendered, for the same reason sections are: Markdown that is only
           syntax is empty too. */
        if (trim(strip_tags(md_render((string)$item['text']))) === '') {
            $errors[] = 'Summary point ' . ($i + 1) . ' is empty.';
        }
    }

    $anchors = [];
    foreach ($data['policy']['sections'] as $s => $section) {
        $where = 'Section ' . ($s + 1);

        if (trim((string)$section['heading']) === '') {
            /* A section with no heading renders an <h2> with nothing in it,
               and the Table of Contents links to an anchor with no name --
               which audit_pages.py checks for on the public side, after it
               has already shipped. */
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

        /* A body that renders to nothing is a section that says nothing: the
           heading and its anchor ship, and the reader lands on an empty
           heading. Rendered, not trimmed -- Markdown that is only syntax
           (`**`, empty fences, a header row with no body) is empty too. */
        require_once __DIR__ . '/markdown.php';
        if (trim(strip_tags(md_render((string)($section['body'] ?? '')))) === '') {
            $errors[] = "$where has no words in it.";
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
