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
       the first thing anybody checks and the first thing anybody disputes.
       A calendar date, not a sentence: the field is a date picker, and
       anything that is not YYYY-MM-DD is refused rather than guessed at. */
    $effective = trim((string)$data['policy']['effective']);
    $parts = [];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $effective, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
    ) {
        $errors[] = 'The policy has no effective date. Pick it from the calendar — '
                  . 'it is the line at the top of the page.';
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

    /* The policy is one body. It keeps the landmark order the whole page
       promises: the hero is the h1, so a body opens on h2 and never skips
       a level down. The renderer is line-blind by design, so this rule
       lives here, where the body is visible whole. An explicitly doubled
       {#id} is refused for the same reason a doubled section anchor always
       was; slugged twins get -2 silently, because nothing stated is wrong. */
    $body = (string)($data['policy']['body'] ?? '');
    require_once __DIR__ . '/markdown.php';
    if (trim(strip_tags(md_render($body))) === '') {
        /* Rendered, not trimmed -- Markdown that is only syntax (`**`,
           empty fences, a header row with no body) is empty too. A policy
           that renders to nothing is a page with a title and no policy. */
        $errors[] = 'The policy has no words in it.';
    } else {
        $prev = 1;
        $stated = [];
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        foreach ($lines as $line) {
            $heading = md_parse_heading($line);
            if ($heading === null) {
                continue;
            }
            [$level, $text, $explicit] = $heading;
            if ($prev === 1 && $level !== 2) {
                $errors[] = "The policy opens on an H$level heading — the page "
                          . 'title is the H1, so it starts at H2.';
            } elseif ($level > $prev + 1) {
                $errors[] = "The policy jumps from H$prev to H$level in \"$text\" — "
                          . 'fill the level between, or the page skips a landmark.';
            }
            $prev = $level;
            if ($explicit !== null) {
                if (isset($stated[$explicit])) {
                    $errors[] = "The policy states #$explicit twice — one of "
                              . 'them must be renamed.';
                }
                $stated[$explicit] = true;
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
