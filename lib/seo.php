<?php
/**
 * Tech4TIME — SEO and page metadata: the model this side writes.
 *
 * Reading and writing the file is lib/store.php; the SHAPE is lib/contract.php,
 * which the frontend and the backend hold byte-identical. What is left here is
 * this side's own business with that shape: validation, and the two saves.
 *
 * TWO SAVES, BECAUSE THERE ARE TWO KINDS OF THING ON THIS SCREEN.
 *
 *   seo_edit()       writes content/seo.json -- the site-wide half: the
 *                    Organization graph, the default share card, the colours,
 *                    robots.txt, the manifest, and the 404's own record.
 *
 *   seo_meta_edit()  writes ONE OTHER DOCUMENT's meta band -- the About page's
 *                    title lives in content/about.json, beside the About page's
 *                    content, and always has. Only the editing moved here.
 *
 * WHY A PAGE'S METADATA DID NOT MOVE INTO THIS DOCUMENT. content/ is never
 * synced by a deploy and is seeded with --ignore-existing, so a seo.json
 * assembled from the repository's committed seeds would carry SEED titles onto
 * a host whose live documents hold titles edited since -- and the reversion
 * would be silent. Leaving the values where they are removes that failure mode
 * rather than managing it. See ADR 0020.
 *
 * BOTH SAVES LOCK. Each screen holds one page or one band of a shared
 * document, so a save has to merge the rest back from the file. A
 * read-modify-write without a lock loses one of two concurrent edits to
 * DIFFERENT pages, which is the normal case here rather than an edge one --
 * the same reasoning services_edit() is built on.
 */

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish_client.php';
require_once __DIR__ . '/certifications.php';
require_once __DIR__ . '/services.php';

const SEO_FILE = __DIR__ . '/../content/seo.json';

/* ------------------------------------------------------------------- read */

/** The site-wide document, or the shipped defaults if it is missing. */
function seo_load(): array
{
    return seo_normalise(store_read(SEO_FILE) ?? []);
}

/**
 * Every page the SEO screen lists, in screen order.
 *
 * The ten routes that are files, from SEO_ROUTES, then every service, from
 * content/services.json -- so a service added this morning has a card this
 * afternoon and nothing has to be kept in step by hand.
 *
 * Each row is what the index needs to draw itself and no more:
 *   key       'about', or 'service:<id>'
 *   name      what to call it
 *   route     its address, '' for the 404
 *   document  which document holds its meta band, '' for the 404
 *   meta      the band itself
 *   service   true when this is a row rather than a file
 */
function seo_pages(): array
{
    $out = [];

    foreach (SEO_ROUTES as $key => [$route, $name, $document]) {
        $out[$key] = [
            'key'      => $key,
            'name'     => $name,
            'route'    => $route,
            'document' => $document,
            'meta'     => seo_page_meta($key),
            'service'  => false,
        ];

        /* The services sit directly under their index, which is where they
           sit on the site and in the sitemap. */
        if ($key !== 'services') {
            continue;
        }

        foreach (services_all(services_load()) as $service) {
            $id = trim((string)($service['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $slug = trim((string)($service['slug'] ?? ''));
            $out['service:' . $id] = [
                'key'      => 'service:' . $id,
                'name'     => trim((string)$service['name']) ?: $slug ?: $id,
                'route'    => $slug === '' ? '' : '/pages/services/' . $slug . '/',
                'document' => 'services',
                'meta'     => $service['meta'],
                'service'  => true,
                'hidden'   => ($service['status'] ?? 'shown') === 'hidden',
            ];
        }
    }

    return $out;
}

/** One page's meta band, whichever document holds it. */
function seo_page_meta(string $page): array
{
    if (str_starts_with($page, 'service:')) {
        $service = services_by_id(services_load(), substr($page, 8));
        return $service === null ? [] : $service['meta'];
    }

    if (!isset(SEO_ROUTES[$page])) {
        return [];
    }

    [$_route, $_name, $document] = SEO_ROUTES[$page];

    if ($document === '') {
        return seo_load()['notfound'];
    }

    return contract_normalise($document, store_read(contract_path($document)) ?? [])['meta'];
}

/**
 * The description as it will actually be published.
 *
 * THE CERTIFICATIONS PAGE COUNTS ITSELF. Its description is authored as
 * "The {certifications} security certifications ..." and the token is replaced
 * with a number before it reaches a search result -- so the stored string is
 * 165 characters and what ships is 151. Measuring the stored one would refuse
 * a description that fits comfortably, which is exactly the wrong direction
 * for a length limit to be wrong in.
 *
 * One page has tokens, so one page is named. A second would join it here.
 */
function seo_effective_description(string $page, string $description): string
{
    if ($page !== 'certifications') {
        return $description;
    }

    $data = certifications_load();

    return certifications_fill($description, certifications_counts($data));
}

/* ------------------------------------------------------------------ write */

/**
 * Change the site-wide document under a lock, then publish it.
 *
 * $mutate is handed the normalised document and returns the new one, or null
 * to abandon the write.
 */
function seo_edit(callable $mutate): bool
{
    $written = store_edit(SEO_FILE, static function (array &$data) use ($mutate): ?array {
        $data = seo_normalise($data);
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

    publish_note(publish_push('seo', $written));

    return true;
}

/**
 * Change ONE page's meta band, in whichever document holds it, then publish
 * that document.
 *
 * $mutate is handed the current meta band and returns the new one.
 *
 * THE REST OF THE DOCUMENT IS NOT TOUCHED. This screen holds one band of a
 * document whose other twenty bands were never in the form, so they are merged
 * back from the file under the lock rather than rebuilt from a POST that never
 * carried them. A whole-document rebuild here would empty the About page.
 */
function seo_meta_edit(string $document, callable $mutate): bool
{
    if ($document === '') {
        /* The 404's record lives in the site-wide document. */
        return seo_edit(static function (array $data) use ($mutate): array {
            $data['notfound'] = contract_meta_defaults(
                $mutate($data['notfound']), seo_defaults()['notfound']);
            $data['notfound']['robots'] = 'noindex';
            return $data;
        });
    }

    $file = contract_path($document);

    $written = store_edit($file, static function (array &$data) use ($document, $mutate): ?array {
        $data = contract_normalise($document, $data);
        $data['meta'] = $mutate($data['meta']);
        $data = contract_normalise($document, $data);

        $data['updated']  = gmdate('c');
        $data['revision'] = contract_next_revision($data);

        return $data;
    });

    if ($written === null) {
        return false;
    }

    publish_note(publish_push($document, $written));

    return true;
}

/**
 * Change ONE service row's meta band, then publish the services document.
 *
 * Separate from seo_meta_edit() because a service is a row: six others sit
 * beside it in the same file and must come back untouched.
 */
function seo_service_meta_edit(string $id, callable $mutate): bool
{
    $file = contract_path('services');

    $written = store_edit($file, static function (array &$data) use ($id, $mutate): ?array {
        $data  = services_normalise($data);
        $found = false;

        foreach ($data['services']['items'] as $i => $service) {
            if (($service['id'] ?? '') !== $id) {
                continue;
            }
            $data['services']['items'][$i]['meta'] = $mutate($service['meta']);
            $found = true;
            break;
        }

        if (!$found) {
            return null;
        }

        $data = services_normalise($data);

        $data['updated']  = gmdate('c');
        $data['revision'] = contract_next_revision($data);

        return $data;
    });

    if ($written === null) {
        return false;
    }

    publish_note(publish_push('services', $written));

    return true;
}

/* ------------------------------------------------------------- validation */

/**
 * What is wrong with one page's search and sharing settings.
 *
 * $others is every OTHER page's meta band, keyed by page, so that a duplicate
 * title can be found -- which is the one fault no page can detect on its own
 * and the reason these checks moved off the nine page editors and onto this
 * one screen.
 *
 * Permissive about wording and strict about anything that would publish a
 * broken record: a title nobody can read because it is cut off, a description
 * a search engine will truncate, a priority outside the range the sitemap
 * schema allows, two pages claiming the same title.
 */
function seo_validate_meta(string $page, array $meta, array $others): array
{
    $errors = [];

    $title = trim((string)($meta['title'] ?? ''));
    $description = trim(seo_effective_description(
        $page, (string)($meta['description'] ?? '')));

    if ($title === '') {
        $errors[] = 'The browser tab title cannot be empty — it is the line a '
                  . 'search result is built around.';
    } elseif (strlen($title) > SEO_TITLE_MAX) {
        $errors[] = 'The browser tab title is ' . strlen($title) . ' characters. '
                  . 'Google cuts titles off at about ' . SEO_TITLE_MAX . '.';
    }

    /* strlen and not mb_strlen: bytes, as every other validator on this host
       counts them, and as lib/html.php explains -- mbstring is not relied on
       anywhere here. It undercounts the characters allowed in a description
       with accents, which for a search-engine limit is the safe direction. */
    if ($description !== '' && strlen($description) < SEO_DESC_MIN) {
        $errors[] = 'The search description is ' . strlen($description)
                  . ' characters. Under ' . SEO_DESC_MIN . ', Google usually writes '
                  . 'its own from the page instead.';
    }
    if (strlen($description) > SEO_DESC_MAX) {
        $errors[] = 'The search description is ' . strlen($description)
                  . ' characters. Search engines cut it off at about '
                  . SEO_DESC_MAX . '.';
    }

    if (!in_array($meta['robots'] ?? '', CONTRACT_ROBOTS, true)) {
        $errors[] = 'A page was given a crawl setting that is neither indexed nor '
                  . 'not indexed.';
    }
    if (!in_array($meta['changefreq'] ?? '', CONTRACT_CHANGEFREQ, true)) {
        $errors[] = 'The sitemap change frequency is not one the sitemap schema '
                  . 'allows.';
    }

    $priority = (string)($meta['priority'] ?? '');
    if (preg_match('/^(0\.\d|1\.0)$/', $priority) !== 1) {
        $errors[] = 'The sitemap priority must be between 0.0 and 1.0.';
    }

    /* TWO PAGES WITH THE SAME TITLE ARE TWO PAGES COMPETING FOR ONE RESULT,
       and neither page's own editor could ever have noticed. tools/audit_pages.py
       enforces site-uniqueness too, but only after a deploy -- and this value
       can change without one. This is the gate; that is the backstop. */
    foreach ($others as $key => $other) {
        if ($key === $page) {
            continue;
        }
        $name = SEO_ROUTES[$key][1] ?? $key;

        if ($title !== '' && strcasecmp($title, trim((string)($other['title'] ?? ''))) === 0) {
            $errors[] = 'That browser tab title is already used by ' . $name
                      . '. Two pages with one title compete for the same result.';
        }
        if ($description !== ''
            && strcasecmp($description, trim((string)($other['description'] ?? ''))) === 0) {
            $errors[] = 'That search description is already used by ' . $name . '.';
        }
    }

    return $errors;
}

/**
 * What is wrong with the site-wide document.
 *
 * NOTHING HERE REFUSES A CRAWL SETTING. Any page may be set to noindex and any
 * path may be disallowed, because that was the decision taken: the operator
 * has the whole control, and the editor's job is to say plainly what it does
 * before they confirm it, not to decide for them. The one exception is a rule
 * that would remove the entire site, which is below and is not a judgement
 * about how much rope to give -- "Disallow: /" is not a narrower rule, it is
 * the off switch, and robots.php writes "Allow: /" above it either way, so the
 * two would contradict each other in the same file.
 */
function seo_validate(array $data): array
{
    $errors = [];

    if (trim((string)$data['site']['name']) === '') {
        $errors[] = 'The site name cannot be empty — it is what a shared link is '
                  . 'labelled with.';
    }
    if (trim((string)$data['site']['lang']) === '') {
        $errors[] = 'The page language cannot be empty. It is what <html lang> says, '
                  . 'and a screen reader chooses its voice from it.';
    }

    foreach (['theme_light' => 'light', 'theme_dark' => 'dark'] as $field => $which) {
        $colour = trim((string)$data['site'][$field]);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1) {
            $errors[] = 'The ' . $which . ' browser theme colour must be a six-digit '
                      . 'hex colour, like #0b0b0c.';
        }
    }
    foreach (['background' => 'background', 'theme' => 'theme'] as $field => $which) {
        $colour = trim((string)$data['manifest'][$field]);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1) {
            $errors[] = 'The installed app\'s ' . $which . ' colour must be a '
                      . 'six-digit hex colour.';
        }
    }

    if (trim((string)$data['identity']['legal_name']) === '') {
        $errors[] = 'The organisation name cannot be empty — it is what search '
                  . 'engines record the company as.';
    }
    $founded = trim((string)$data['identity']['founded']);
    if ($founded !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $founded) !== 1) {
        $errors[] = 'The founding date must be written as YYYY-MM-DD, or left empty.';
    }

    foreach ($data['sameas']['items'] as $i => $row) {
        $where = 'Profile ' . ($i + 1);
        if (trim((string)$row['label']) === '') {
            $errors[] = "$where has no name.";
        }
        if (trim((string)$row['url']) === '') {
            $errors[] = "$where has no address, or one this site will not link to.";
        }
    }

    foreach ($data['hours']['items'] as $i => $row) {
        $where = 'Opening hours row ' . ($i + 1);
        if (trim((string)$row['label']) === '') {
            $errors[] = "$where has no name. Name it after the office it describes — "
                      . 'that is how it is matched to one.';
        }
        if ($row['days'] === []) {
            $errors[] = "$where names no days.";
        }
        if ($row['opens'] === '' || $row['closes'] === '') {
            $errors[] = "$where needs both an opening and a closing time, as HH:MM.";
        }
    }

    /* THE ONE RULE THAT IS REFUSED. Everything else about crawling is the
       operator's to decide; this is not a narrower rule to weigh up, it is the
       whole site switched off, and it would sit in the same file as the
       "Allow: /" robots.php always writes. */
    foreach ($data['crawl']['robots_extra'] as $path) {
        $path = trim((string)$path);
        if ($path === '/' || $path === '*' || $path === '/*') {
            $errors[] = 'A rule blocking "' . $path . '" would remove the whole site '
                      . 'from every search engine. Name the paths you mean instead.';
        }
        if ($path !== '' && !str_starts_with($path, '/')) {
            $errors[] = 'A crawl rule must be a path beginning with "/" — "'
                      . $path . '" is not one.';
        }
    }

    return $errors;
}
