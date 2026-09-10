<?php
/**
 * Tech4TIME — the contract between the two repositories.
 *
 * SHARED FILE. Byte-identical in tech4time-website-frontend and tech4time-website-backend.
 * Change it in one and you must change it in the other in the same breath;
 * tools/check_shared_lib.py compares the two against a committed digest and
 * fails the build in both when they part.
 *
 * WHAT BELONGS HERE
 * The shape of a document, and nothing else. Field lists, the defaults a
 * missing key falls back to, the normalising that turns whatever arrived into
 * that shape, and the queries that read it. Both sides must agree on all of
 * this or they are not describing the same job post.
 *
 * WHAT DOES NOT BELONG HERE
 *   authoring   validation with human-readable messages, the form model, the
 *               editor's pickers — the backend's business, and the frontend
 *               has no form to validate
 *   rendering   JobPosting and ContactPage structured data, flag pictures,
 *               tel: hrefs — the frontend's business, and the backend does
 *               not render the public page
 *
 * The line is: if the two sides disagreeing about it would corrupt a document,
 * it is here. If disagreeing would only make one side's own page look wrong,
 * it is not.
 *
 * CONTRACT_VERSION
 * Every published payload carries it, and the receiving side refuses a version
 * it does not implement — see lib/publish.php. That runtime check is the real
 * guarantee, because it fires on the real path on the day, and it refuses
 * rather than writing a document it would then mis-render.
 *
 * The digest comparison is hygiene for accidental local edits and no more. It
 * cannot catch a deliberate change: bump the version in both repositories and
 * both digests agree again while the two hold different code. Only the
 * receiver checking what it was actually sent can catch that.
 *
 * BUMP IT when a change would make a document written by one version render
 * wrongly under the other: a field renamed, a field's meaning changed, a list
 * that becomes a scalar. Do NOT bump it for a new optional field that older
 * code simply ignores, or for anything in the two lists above.
 *
 * Not reachable over HTTP: the frontend's .htaccess forbids /lib/, and the
 * backend's lib/ sits outside its document root.
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';

/** The shape both repositories implement. See the header before changing it. */
const CONTRACT_VERSION = 1;

/** Every document that is published, by name. The endpoint refuses any other. */
const CONTRACT_DOCUMENTS = ['careers', 'contact', 'company', 'about', 'home', 'services',
                            'certifications', 'branding', 'privacy', 'seo', 'chrome'];

/**
 * Where a document's record lives, on either host.
 *
 * content/<name>.json, and the same in both repositories -- the backend's copy
 * is the system of record and the frontend's is the replica it is sent
 * (ADR 0010), but the path is one rule. lib/careers.php, lib/contact.php and
 * lib/company.php each still write their own constant, because they are read
 * far more often than this is; what this exists for is everything that has to
 * work over ALL the documents without knowing their names in advance.
 *
 * The deploy is the reason it exists. A new document gets a model, an editor, a
 * renderer, tests and documentation, and the one line that seeds it onto a
 * fresh host is in a file nobody opens for any of that -- so it gets left out,
 * and the failure is silent: the editor comes up showing defaults, which look
 * like a page nobody has filled in yet rather than like a missing file. That
 * happened to the company profile. It reached production with the admin
 * offering an empty form over a live page holding seventy-seven rows, and one
 * press of Save would have published the empty one over it.
 *
 * @throws RuntimeException on a name CONTRACT_DOCUMENTS does not list.
 */
function contract_path(string $document): string
{
    if (!in_array($document, CONTRACT_DOCUMENTS, true)) {
        throw new RuntimeException('unknown document: ' . $document);
    }

    return __DIR__ . '/../content/' . $document . '.json';
}

/**
 * Fields a document keeps about itself, rather than about the page.
 *
 * No form posts them and no page renders them: they are how a document
 * describes its own history. Named once because three separate checks would
 * otherwise each carry a copy of the list, and the one that forgets a new
 * entry reports it as "a field nobody edits" — which is true, and not the
 * point. That is exactly how 'revision' announced itself.
 */
const CONTRACT_BOOKKEEPING = ['updated', 'revision'];

/* ---------------------------------------------------- page metadata

   THE meta BAND IS THE SAME SHAPE IN EVERY DOCUMENT, AND ONE SCREEN EDITS
   IT. These live up here rather than beside their functions because a
   file-scope const is evaluated where it stands, and the first document to
   name CONTRACT_META_TEXT is the contact page a couple of hundred lines
   below. The functions that use them are further down, with the other
   cross-document helpers -- those are hoisted and do not care. */

/** The band that belongs to the SEO screen rather than to the page's editor. */
const CONTRACT_META_BAND = 'meta';

/** Its free-text fields, the same five in every document. */
const CONTRACT_META_TEXT = ['title', 'description', 'share_title', 'breadcrumb',
                            'keywords'];

/**
 * What a page tells a crawler, as the two states somebody chooses between.
 *
 * The directive STRING is the renderer's business and is longer than this: an
 * indexed page also asks for large image previews and full snippets. Two
 * states rather than five directives, because SITEMAP MEMBERSHIP IS DERIVED
 * FROM THIS -- so there is no second switch that can be set to contradict it,
 * and a noindex URL cannot end up in the sitemap, which is a Search Console
 * warning against the whole file.
 */
const CONTRACT_ROBOTS = ['index', 'noindex'];

/** The sitemap's changefreq vocabulary, fixed by the sitemap schema. */
const CONTRACT_CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly',
                             'yearly', 'never'];


/* ==========================================================================
   1. Careers — the shape of a job post
   ========================================================================== */

/* Free-text single-line fields. */
const CAREERS_TEXT_FIELDS = [
    'id', 'title', 'employment_type', 'work_arrangement',
    'location', 'salary', 'posted', 'closes', 'status', 'apply_url',
];

/* Body fields, each stored as one sanitised HTML string. They were arrays of
   plain text until the editor gained formatting; careers_migrate() below still
   understands the old shape, so an older backup loads without ceremony. */
const CAREERS_RICH_FIELDS = [
    'about', 'responsibilities', 'requirements',
    'must_have', 'nice_to_have', 'certifications', 'offers',
];

/* Which of the old fields were bullets rather than paragraphs. Only used when
   migrating; nothing writes this shape any more. */
const CAREERS_LEGACY_LIST_FIELDS = [
    'responsibilities', 'requirements', 'must_have', 'nice_to_have', 'offers',
];

/* Section heading -> field, in the order a job renders. Changing a label here
   changes it on the page; changing a key would orphan existing data, which is
   why a key change is a CONTRACT_VERSION bump. */
const CAREERS_SECTIONS = [
    'about'            => 'About the Role',
    'responsibilities' => 'Key Responsibilities',
    'requirements'     => 'Required Skills & Experience',
    'must_have'        => 'Must Have',
    'nice_to_have'     => 'Nice to Have',
    'certifications'   => 'Certifications',
    'offers'           => 'What We Offer',
];

/** The document as it is when there is nothing in it. */
function careers_defaults(): array
{
    return [
        'cv_form_url' => '',
        'updated'     => '',
        'revision'    => 0,
        /* THE CAREERS PAGE WAS THE ONE PAGE NOBODY COULD RETITLE. Its <title>
           and description were literal strings in pages/careers/index.php,
           because this document grew around a list of job posts and never had
           a band for the page itself. It has the same meta band as every other
           document now, and the same screen edits it. */
        'meta'        => [
            'title'       => 'Careers | Tech4TIME',
            'description' => 'Open roles at Tech4TIME in cybersecurity, software and '
                           . 'infrastructure. See what is available now, or send us '
                           . 'your CV for the roles we open next.',
            'share_title' => 'Careers | Tech4TIME',
            'breadcrumb'  => 'Careers',
'keywords'    => 'IT jobs Bangladesh, cybersecurity careers, '
               . 'software developer jobs Dhaka, IT recruitment, '
               . 'Tech4TIME careers',
            'robots'      => 'index',
            'changefreq'  => 'weekly',
            'priority'    => '0.7',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'jobs'        => [],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * Called by both sides on load, and by the frontend again on receipt — a
 * payload's origin says nothing about the shape of what is inside it.
 */
function careers_normalise(array $data): array
{
    $data += careers_defaults();

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                           careers_defaults()['meta']);

    $data['revision'] = max(0, (int)($data['revision'] ?? 0));
    $data['jobs'] = is_array($data['jobs'] ?? null) ? array_values($data['jobs']) : [];
    $data['jobs'] = array_map(
        'careers_migrate',
        array_filter($data['jobs'], 'is_array')
    );
    $data['jobs'] = array_values($data['jobs']);

    return $data;
}

/**
 * Bring a job forward from the plain-text schema.
 *
 * Runs on every load rather than as a one-off script, so an older
 * careers.json.bak restored by hand still works. Idempotent: a field that is
 * already a string is left exactly as it is.
 */
function careers_migrate(array $job): array
{
    foreach (CAREERS_RICH_FIELDS as $field) {
        $value = $job[$field] ?? '';

        if (is_string($value)) {
            continue;
        }
        if (!is_array($value) || !$value) {
            $job[$field] = '';
            continue;
        }

        $items = array_map(static fn($v): string => h((string)$v), $value);

        $job[$field] = in_array($field, CAREERS_LEGACY_LIST_FIELDS, true)
            ? '<ul><li>' . implode('</li><li>', $items) . '</li></ul>'
            : '<p>' . implode('</p><p>', $items) . '</p>';
    }

    return $job;
}

/** Only the posts a visitor should see. */
function careers_open_jobs(array $data): array
{
    return array_values(array_filter(
        $data['jobs'],
        static fn(array $job): bool => ($job['status'] ?? 'open') === 'open'
    ));
}

function careers_find(array $data, string $id): ?array
{
    foreach ($data['jobs'] as $job) {
        if (($job['id'] ?? '') === $id) {
            return $job;
        }
    }
    return null;
}

/** The one-line summary a listing shows: "Full-Time · On-site · Dhaka". */
function careers_meta_line(array $job): array
{
    return array_values(array_filter([
        trim((string)($job['employment_type'] ?? '')),
        trim((string)($job['work_arrangement'] ?? '')),
        trim((string)($job['location'] ?? '')),
    ], static fn(string $v): bool => $v !== ''));
}

/** A URL-safe id from a title, unique against the ids already in use. */
function careers_slug(string $title, array $taken = []): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: 'role';

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/* ==========================================================================
   2. Contact — the shape of the contact page
   ========================================================================== */

/**
 * How a "Reach Us Directly" row turns each of its values into a link.
 *
 * text is the one that deliberately makes no link — "Within one working day"
 * is a fact, not a destination.
 */
const CONTACT_REACH_TYPES = [
    'email' => 'Email address',
    'phone' => 'Phone number',
    'url'   => 'Web address',
    'text'  => 'Plain text (no link)',
];

/**
 * The icons a reach row may use.
 *
 * A fixed list rather than the whole sprite, for a reason that is easy to
 * miss: tools/inject_icons.py inlines the symbols a page references by
 * scanning it for literal href="#name". A name chosen at run time is invisible
 * to that scan, so every icon offered here is also listed in a comment in the
 * frontend's pages/contact/index.php where the scanner can see it. Adding one
 * here means adding it there too, and inject_icons.py --check will say so if
 * it is forgotten.
 *
 * Shared rather than backend-only because the backend offers the choice and
 * the frontend has to be able to draw whatever was chosen. A row carrying an
 * icon the frontend has never heard of renders as an empty box.
 */
const CONTACT_ICONS = [
    'envelope'        => 'Envelope',
    'phone'           => 'Phone',
    'mobile-alt'      => 'Mobile',
    'clock'           => 'Clock',
    'map-marker-alt'  => 'Map pin',
    'building'        => 'Building',
    'globe'           => 'Globe',
    'headset'         => 'Headset',
    'comment-alt'     => 'Speech bubble',
    'paper-plane'     => 'Paper plane',
    'calendar-alt'    => 'Calendar',
    'info-circle'     => 'Information',
    'linkedin'        => 'LinkedIn',
    'github'          => 'GitHub',
];

/* Free-text single-line fields, by section. */
const CONTACT_TEXT_FIELDS = [
    'meta'    => CONTRACT_META_TEXT,
    'hero'    => ['title', 'subtitle'],
    'form'    => ['title', 'subject_hint', 'note'],
    'reach'   => ['title'],
    'offices' => ['eyebrow', 'title'],
];

/* Fields stored as sanitised HTML, so they can carry a link or emphasis. This
   is also the list the frontend re-sanitises on receipt: a signature proves
   where a payload came from, not that what is in it is safe. */
const CONTACT_RICH_FIELDS = [
    'form'    => ['lead'],
    'offices' => ['lead'],
];

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every key the renderer reads exists here, so a truncated or hand-edited
 * contact.json degrades to the shipped copy field by field rather than
 * emptying the page.
 */
function contact_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'Contact Us | Tech4TIME',
            'description' => 'Get in touch with Tech4TIME.',
            'share_title' => 'Ask for a quote or just contact us',
            'breadcrumb'  => 'Contact Us',
'keywords'    => 'contact Tech4TIME, IT company Dhaka, IT support Bangladesh, '
               . 'request a quote, IT services enquiry',
            'robots'      => 'index',
            'changefreq'  => 'yearly',
            'priority'    => '0.7',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero' => [
            'title'    => 'Contact Us',
            'subtitle' => 'Ask for a quote, or just get in touch',
        ],
        'form' => [
            'title'         => 'Ask for a quote or just contact us',
            'lead'          => '',
            'subject_hint'  => 'Pick one of ours or describe your own.',
            'note'          => 'Sent over an encrypted connection and used only to answer your enquiry.',
            'service_types' => [],
        ],
        'reach' => [
            'status' => 'shown',
            'title'  => 'Reach Us Directly',
            'items'  => [],
        ],
        'offices' => [
            'status'  => 'shown',
            'eyebrow' => 'Where We Are',
            'title'   => 'Our Offices',
            'lead'    => '',
            'items'   => [],
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per section, which is all the shape has: scalars fall
 * back individually, lists are taken whole or not at all.
 */
function contact_normalise(array $data): array
{
    $defaults = contact_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    /* Clamped the same way COMPANY_BANDS are, and for the same reason: this
       arrives from a file as often as from a form, and "banana" is not a
       visibility. Anything that is not the word 'hidden' shows. */
    foreach (CONTACT_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    $data['form']['service_types'] = contact_string_list($data['form']['service_types'] ?? []);
    $data['reach']['items'] = array_map(
        'contact_reach_defaults',
        array_values(array_filter(
            is_array($data['reach']['items']) ? $data['reach']['items'] : [],
            'is_array'
        ))
    );
    $data['offices']['items'] = array_map(
        'contact_office_defaults',
        array_values(array_filter(
            is_array($data['offices']['items']) ? $data['offices']['items'] : [],
            'is_array'
        ))
    );

    return $data;
}

/**
 * Fill in a reach row, whatever it arrived with.
 *
 * A row holds a LIST of values, so that three numbers can sit under one
 * "Phone" heading rather than as three rows each headed "Phone" — which is
 * how the office cards already read, and the two should not disagree.
 *
 * Rows were a single "value" before that, so one is migrated here rather than
 * by a script: an older contact.json restored by hand still loads. Idempotent,
 * and a row that already has a list is left exactly as it is.
 */
function contact_reach_defaults(array $item): array
{
    $item += [
        'icon'   => '',
        'label'  => '',
        'type'   => 'text',
        'values' => [],
        'text'   => '',
        'status' => 'shown',
    ];

    /* Anything that is not the word 'hidden' is shown. A row that arrives from
       an older document has no status at all and must not vanish because of
       it -- which is the whole reason this defaults the way round it does. */
    $item['status'] = $item['status'] === 'hidden' ? 'hidden' : 'shown';

    if (!$item['values'] && isset($item['value'])) {
        $item['values'] = [(string)$item['value']];
    }
    unset($item['value']);

    $item['values'] = contact_string_list($item['values']);

    return $item;
}

/** Fill in an office record, whatever it arrived with. */
function contact_office_defaults(array $office): array
{
    $office += [
        'id'      => '',
        'name'    => '',
        'flag'    => '',
        'address' => '',
        'phones'  => [],
        'hours'   => '',
        'status'  => 'shown',
        'languages' => [],
        'image'   => [],
    ];

    $office['status'] = $office['status'] === 'hidden' ? 'hidden' : 'shown';

    /* THE FLAG, TWICE OVER, AND BOTH ARE NEEDED.

       'flag' is a slug -- 'bangladesh', 'belgium' -- naming a file that ships
       with the public site in assets/images/flags/. It works, and it is why
       the three offices that exist have flags at all. What it cannot do is let
       somebody add a fourth office: there is no file for their country and no
       way to put one there without a developer and a deploy.

       'image' is an uploaded picture, the same record shape the company
       profile's logos use, travelling the same signed asset channel. When it
       is set it wins; when it is not, the slug still renders. So nothing that
       works today stops working, and a new office is no longer a request to
       somebody with a git remote. */
    $office['image'] = contract_image_defaults($office['image']);

    $office['phones'] = contact_string_list($office['phones']);
    $office['languages'] = contact_string_list($office['languages']);
    $office['schema'] = (is_array($office['schema'] ?? null) ? $office['schema'] : []) + [
        'street'      => '',
        'locality'    => '',
        'region'      => '',
        'postal_code' => '',
        'country'     => '',
    ];

    if ($office['id'] === '') {
        $office['id'] = contact_slug($office['name']);
    }

    return $office;
}

/**
 * The bands of the contact page a visitor can be shown or not shown.
 *
 * The banner and the enquiry form are not here on purpose: a contact page with
 * no way to make contact is not a page anybody meant to publish, and a switch
 * that can produce one is a switch somebody will eventually flip by accident.
 * Everything below the form is optional; the form is the page.
 */
const CONTACT_BANDS = ['reach', 'offices'];

/** Trim a list of strings and drop the blanks, whatever shape it arrived in. */
function contact_string_list(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
    }
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter(
        array_map(static fn($v): string => trim((string)$v), $value),
        static fn(string $v): bool => $v !== ''
    ));
}

/** Only the offices a visitor should see. */
function contact_shown_offices(array $data): array
{
    if (($data['offices']['status'] ?? 'shown') === 'hidden') {
        return [];
    }

    return array_values(array_filter(
        $data['offices']['items'],
        static fn(array $o): bool => ($o['status'] ?? 'shown') === 'shown'
    ));
}

/**
 * The reach rows a visitor should see, and none when the band is switched off.
 *
 * The band's own switch is checked HERE rather than only where the markup is
 * written, because a hidden band must also be absent from the structured data
 * — and the JSON-LD is built from a different function in a different file. A
 * band that disappears visually and goes on being advertised to search engines
 * is not hidden, it is only invisible. contact_shown_offices() above answers
 * for the same reason.
 */
function contact_shown_reach(array $data): array
{
    if (($data['reach']['status'] ?? 'shown') === 'hidden') {
        return [];
    }

    return array_values(array_filter(
        $data['reach']['items'] ?? [],
        static fn(array $r): bool => ($r['status'] ?? 'shown') === 'shown'
    ));
}

function contact_find_office(array $data, string $id): ?array
{
    foreach ($data['offices']['items'] as $office) {
        if (($office['id'] ?? '') === $id) {
            return $office;
        }
    }
    return null;
}

/** A URL-safe id from a name, unique against the ids already in use. */
function contact_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: 'office';

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/** The email address the page publishes, taken from the reach rows. */
function contact_email(array $data): string
{
    foreach ($data['reach']['items'] as $item) {
        if (($item['type'] ?? '') === 'email' && $item['values']) {
            return trim((string)$item['values'][0]);
        }
    }
    return '';
}

/** A phone number as a dialler wants it: digits, and a leading + if it had one. */
function contact_tel(string $number): string
{
    $digits = preg_replace('/[^0-9]/', '', $number) ?? '';
    return (str_starts_with(trim($number), '+') ? '+' : '') . $digits;
}

/* ==========================================================================
   3. Company profile — the shape of the company page
   ========================================================================== */

/**
 * The icons a principle card may use.
 *
 * Fixed for the same reason CONTACT_ICONS is: tools/inject_icons.py inlines the
 * symbols a page references by scanning it for a literal href="#name", and a
 * name chosen at run time is invisible to that scan. Every icon offered here is
 * therefore also listed in a comment in the frontend's
 * pages/company-profile/index.php, where the scanner can see it. Add one here
 * and add it there; inject_icons.py --check says so if it is forgotten.
 */
const COMPANY_ICONS = [
    'shield-alt'     => 'Shield',
    'lightbulb'      => 'Lightbulb',
    'handshake'      => 'Handshake',
    'clock'          => 'Clock',
    'cogs'           => 'Cogs',
    'building'       => 'Building',
    'globe'          => 'Globe',
    'headset'        => 'Headset',
    'comment-alt'    => 'Speech bubble',
    'calendar-check' => 'Calendar tick',
    'check-circle'   => 'Tick in a circle',
    'user-shield'    => 'Person and shield',
    'eye'            => 'Eye',
    'info-circle'    => 'Information',
];

/* Free-text single-line fields, by band. */
const COMPANY_TEXT_FIELDS = [
    'meta'       => CONTRACT_META_TEXT,
    'hero'       => ['title', 'subtitle'],
    'milestones' => ['eyebrow', 'title'],
    'background' => ['eyebrow', 'title'],
    'experience' => ['title'],
    'clients'    => ['title'],
    'journey'    => ['title'],
    'excellence' => ['eyebrow', 'title'],
    'technology' => ['title'],
    'principles' => ['title'],
    'cta'        => ['title', 'label', 'href', 'icon'],
];

/* Fields stored as sanitised HTML, so a lead can carry a link or emphasis.
   This is also the list the frontend re-sanitises on receipt. */
const COMPANY_RICH_FIELDS = [
    'milestones' => ['lead'],
    'journey'    => ['lead'],
    'excellence' => ['lead'],
    'cta'        => ['text'],
];

/**
 * Every band of the page that can be hidden whole, in the order it renders.
 *
 * Two of these CONTAIN others on the page: 'background' is the surface the
 * experience, clients and journey blocks sit on, and 'excellence' is the one
 * holding technology and principles. Hiding a container hides what is inside
 * it; hiding one of the inner blocks leaves the others where they were. The
 * shape here is flat because the form is flat — the nesting is the renderer's,
 * and company_band_shown() is what both sides ask.
 */
const COMPANY_BANDS = [
    'milestones', 'background', 'experience', 'clients', 'journey',
    'excellence', 'technology', 'principles', 'cta',
];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * Named once so company_normalise() can drive itself off it. A list added to
 * the page is normalised by being added here, rather than by somebody also
 * remembering to add a line further down — the same argument CONTRACT_BOOKKEEPING
 * makes, for the same reason.
 */
const COMPANY_LISTS = [
    'milestones' => 'company_milestone_defaults',
    'experience' => 'company_stat_defaults',
    'clients'    => 'company_logo_defaults',
    'journey'    => 'company_photo_defaults',
    'technology' => 'company_logo_defaults',
    'principles' => 'company_principle_defaults',
];

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * company.json degrades to the shipped headings rather than emptying the page.
 * The lists default to empty, which is the same bargain the contact page makes:
 * the page still has a shape, it just has nothing in it.
 */
function company_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'Company Profile | Tech4TIME',
            'description' => 'Our milestones, the clients we serve, and the technology our engagements are built on.',
            'share_title' => 'Milestones in Technological Excellence',
            'breadcrumb'  => 'Company Profile',
'keywords'    => 'Tech4TIME company profile, IT company Bangladesh, '
               . 'technology partners, corporate clients, '
               . 'engineering capability',
            'robots'      => 'index',
            'changefreq'  => 'monthly',
            'priority'    => '0.7',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero' => [
            'title'    => 'Company Profile',
            'subtitle' => 'Milestones, Clients and the Technology We Work With',
        ],
        'milestones' => [
            'status'  => 'shown',
            'eyebrow' => 'Our Journey',
            'title'   => 'Milestones in Technological Excellence',
            'lead'    => '',
            'items'   => [],
        ],
        'background' => [
            'status'  => 'shown',
            'eyebrow' => 'Who We Are',
            'title'   => 'Our Background',
        ],
        'experience' => [
            'status' => 'shown',
            'title'  => 'Experience',
            'items'  => [],
        ],
        'clients' => [
            'status' => 'shown',
            'title'  => 'Proud Clients',
            'items'  => [],
        ],
        'journey' => [
            'status'   => 'shown',
            'title'    => 'Our Journey of Growth',
            'lead'     => '',
            'interval' => 6000,
            'items'    => [],
        ],
        'excellence' => [
            'status'  => 'shown',
            'eyebrow' => 'Work & Expertise',
            'title'   => 'Our Professional Excellence',
            'lead'    => '',
        ],
        'technology' => [
            'status' => 'shown',
            'title'  => 'The Technology We Work With',
            'items'  => [],
        ],
        'principles' => [
            'status' => 'shown',
            'title'  => 'The Principles That Guide Us',
            'items'  => [],
        ],
        'cta' => [
            'status' => 'shown',
            'title'  => 'Want to be the next name on this page?',
            'text'   => '',
            'label'  => 'Talk to Us',
            'href'   => '/pages/contact/',
            'icon'   => 'calendar-check',
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per band, as the contact page does, then every list
 * through its own row-filler. Rows are renumbered with array_values() because
 * the editor posts them keyed by position and a removed row leaves a hole.
 */
function company_normalise(array $data): array
{
    $defaults = company_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (COMPANY_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* A slideshow that advances every 40 milliseconds is not a slideshow, and
       one that waits an hour has stopped. Clamped rather than refused: this
       arrives from a file as often as from a form. */
    $data['journey']['interval'] =
        min(60000, max(2000, (int)($data['journey']['interval'] ?? 6000)));

    foreach (COMPANY_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return company_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Rows are addressed by position in the form and by id everywhere else — a
 * fragment link, an upload that has to find the row it belongs to, a test that
 * wants to name one. Minted here rather than in the editor so a row that
 * arrived from a hand-edited file has one too.
 */
function company_identify(array $data): array
{
    foreach (COMPANY_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = company_row_name($band, $row);

            /* A row added by the Add button has nothing in it yet, so there is
               nothing to name it after and it gets the placeholder. Once it
               HAS a name, the placeholder is replaced — otherwise every row
               ever created through the editor would be called "row", "row-2",
               "row-3" for the rest of its life. A real id is never re-minted:
               it is the handle everything else holds the row by. */
            $provisional = $id === ''
                || preg_match('/^' . COMPANY_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;

            if (($provisional && $name !== '') || in_array($id, $taken, true)) {
                $id = company_slug($name, $taken);
            } elseif ($id === '') {
                $id = company_slug('', $taken);
            }

            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    return $data;
}

/**
 * What a row's id is minted from, whichever list it is in.
 *
 * A photograph is named after its file rather than its alt text: alt is a
 * sentence, and a sentence makes an id nobody can read or type. The file is
 * short, already unique, and describes the same thing.
 */
function company_row_name(string $band, array $row): string
{
    if ($band === 'journey') {
        $file = pathinfo((string)($row['image']['src'] ?? ''), PATHINFO_FILENAME);
        return $file !== '' ? $file : 'photo';
    }

    return trim((string)match ($band) {
        'milestones' => ($row['year'] ?? '') . ' ' . ($row['title'] ?? ''),
        'experience' => $row['label'] ?? '',
        'principles' => $row['title'] ?? '',
        default      => $row['name'] ?? '',
    });
}

function company_milestone_defaults(array $row): array
{
    return $row + [
        'id' => '', 'year' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

function company_stat_defaults(array $row): array
{
    return $row + [
        'id' => '', 'figure' => '', 'label' => '', 'status' => 'shown',
    ];
}

/** A logo: the clients grid and the technology sphere hold the same shape. */
function company_logo_defaults(array $row): array
{
    $row += ['id' => '', 'name' => '', 'status' => 'shown'];
    $row['image'] = contract_image_defaults($row['image'] ?? []);

    return $row;
}

function company_photo_defaults(array $row): array
{
    $row += ['id' => '', 'alt' => '', 'status' => 'shown'];
    $row['image'] = contract_image_defaults($row['image'] ?? []);

    return $row;
}

function company_principle_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/**
 * Where a picture may live, as path prefixes.
 *
 * assets/ is artwork that ships with the site and changes with a deploy;
 * uploads/ is what the editor put there. Nothing else is a picture this site
 * will point at.
 *
 * THIS IS ENFORCED ON BOTH SIDES, and that is the point of it being here. The
 * editor checks it because a hidden input is a text field with the label taken
 * off. The frontend checks it AGAIN on receipt, because a signature proves
 * where a document came from and not what is inside it — the same argument
 * contract_sanitise() makes about rich text. An <img src> pointing somewhere
 * else would put a third party's server into every visitor's page load, and
 * tell them who is reading the page.
 */
/* Named for the contract rather than for the company profile, because the
   contact page's offices carry a picture too now. A picture record is one
   shape, checked one way, wherever it hangs. */
const CONTRACT_IMAGE_ROOTS = ['/assets/images/', '/uploads/'];

/* What a row is called before it is called anything. See company_identify(). */
const COMPANY_ID_PLACEHOLDER = 'row';

/**
 * A picture path, or '' if it is not one this site will publish.
 *
 * Rejects anything with a backslash, a control character or "..", before the
 * prefix test rather than after: "/assets/images/../../etc/passwd" starts with
 * an allowed prefix and is not an allowed path.
 */
function contract_safe_image_path(string $path): string
{
    $path = trim($path);

    if ($path === '' || preg_match('~[\\x00-\\x1f\\x7f\\\\]|\\.\\.~', $path)) {
        return '';
    }

    foreach (CONTRACT_IMAGE_ROOTS as $root) {
        if (str_starts_with($path, $root) && strlen($path) > strlen($root)) {
            return $path;
        }
    }

    return '';
}

/**
 * Fill in a picture, whatever it arrived with.
 *
 * width and height are not decoration. They are what lets the browser reserve
 * the right box before the bytes arrive, and this site's Cumulative Layout
 * Shift is zero rather than nearly zero. A row whose dimensions are missing or
 * nonsense renders without them, which is honest; a row that guessed would
 * move the page.
 *
 * webp is optional and empty is meaningful: it says "there is no WebP sibling,
 * emit a bare <img> and no <picture> wrapper". That is how the SVG and AVIF
 * entries have always rendered.
 */
function contract_image_defaults(mixed $image): array
{
    $image = is_array($image) ? $image : [];
    $image += ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0];

    $image['src']    = contract_safe_image_path((string)$image['src']);
    $image['webp']   = contract_safe_image_path((string)$image['webp']);
    $image['width']  = max(0, (int)$image['width']);
    $image['height'] = max(0, (int)$image['height']);

    return $image;
}

/* ------------------------------------------------- rows, ids and bands

   SIX DOCUMENTS ASKED THE SAME FOUR QUESTIONS, so they are asked once here.

   Whether a band is shown; which rows of a list a visitor sees; whether an id
   is one somebody chose; what a name slugs to. Every document since the company
   profile has carried its own copy of all four, and every copy was
   character-for-character the same once the document's own prefix was taken
   off -- checked, not assumed. A seventh copy is not a seventh answer.

   The PLACEHOLDER stays a per-document constant and is passed in, because that
   part really is the document's own: an id vocabulary that moved because
   another page changed would be a fragment link broken by a page nobody
   touched. Each document keeps its named wrapper too, so no renderer, no
   editor and no test has to learn a new name for something it already calls. */

/** Whether a band of the page is shown at all. */
function contract_band_shown(array $data, string $band): bool
{
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

/**
 * Only the rows of a list a visitor should see, wherever the list is.
 *
 * Takes the rows rather than the band, because by the sixth document the lists
 * that matter are no longer all one level down: a list inside a block inside a
 * section of the privacy policy is three deep, and there is no band to name it
 * by.
 */
function contract_rows_shown(mixed $rows): array
{
    return array_values(array_filter(
        is_array($rows) ? $rows : [],
        static fn($row): bool => is_array($row) && ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** An id nobody has chosen: empty, or the placeholder the Add button leaves. */
function contract_provisional(string $id, string $placeholder): bool
{
    return $id === '' || preg_match('/^' . $placeholder . '(-\d+)?$/', $id) === 1;
}

/** The same id, suffixed until nothing else in the list has it. */
function contract_unique(string $slug, array $taken): string
{
    $base = $slug;
    $n    = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/** A URL-safe id from a name, falling back to the document's placeholder. */
function contract_slug(string $name, string $placeholder, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: $placeholder;

    return contract_unique($slug, $taken);
}

/**
 * Keep a real id; replace a placeholder once there is a name to replace it with.
 *
 * THE FREEZE RULE, and the reason this is worth having in one place. A row that
 * has been named keeps its id for good: it is the handle a fragment link, an
 * upload and a test all hold the row by, and re-minting it because somebody
 * reworded a heading breaks a link that was a promise.
 */
function contract_mint(string $id, string $name, string $placeholder, array $taken): string
{
    $id   = trim($id);
    $name = trim($name);

    if ((contract_provisional($id, $placeholder) && $name !== '') || in_array($id, $taken, true)) {
        return contract_slug($name, $placeholder, $taken);
    }
    if ($id === '') {
        return contract_slug('', $placeholder, $taken);
    }
    return $id;
}

/**
 * Ids for a whole list at once, in two passes, so a newcomer cannot take one.
 *
 * THE ONE-PASS VERSION HAS A BUG, and it is the kind that only bites a page
 * whose ids are anchors. Add a section titled "Your rights" above the existing
 * one and mint in row order: the newcomer is provisional, so it slugs to
 * "your-rights" and claims it; the real section then finds its own id already
 * taken and is renamed to "your-rights-2". The incumbent loses the anchor, the
 * empty newcomer inherits it, and every link anyone ever made lands in the
 * wrong place.
 *
 * So everything already named claims its id first, and only then is anything
 * provisional minted around what is left. A published fragment is a promise,
 * and the row that made the promise keeps it.
 *
 * $name is asked for a row's name rather than given a field, because what
 * names a row differs by list -- a heading here, a label there, the words
 * themselves in a bullet.
 */
function contract_identify_rows(array $rows, string $placeholder, callable $name): array
{
    $ids   = [];
    $taken = [];

    foreach ($rows as $i => $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== ''
            && !contract_provisional($id, $placeholder)
            && !in_array($id, $taken, true)
        ) {
            $ids[$i] = $id;
            $taken[] = $id;
        }
    }

    foreach ($rows as $i => $row) {
        if (isset($ids[$i])) {
            continue;
        }
        $ids[$i] = contract_mint((string)($row['id'] ?? ''), (string)$name($row),
                                 $placeholder, $taken);
        $taken[] = $ids[$i];
    }

    ksort($ids);

    return $ids;
}


/* ------------------------------------------------------ page metadata

   THE meta BAND IS THE SAME SHAPE IN EVERY DOCUMENT, AND ONE SCREEN EDITS IT.

   Every page's title, description, share title, breadcrumb, crawl directive
   and sitemap tuning live in that page's OWN document, beside its content, and
   are edited at admin.tech4time.bd/?s=seo -- one screen for the whole site.
   The values stayed where they were; only the typing moved. See
   docs/40-reference/seo.md and ADR 0020.

   THE meta BAND IS THEREFORE NOT A BAND THE PAGE'S OWN EDITOR WRITES, and that
   distinction is load-bearing rather than tidy. Every *_from_post() starts from
   the stored document and then overwrites each band named in its *_TEXT_FIELDS
   from $_POST. A form that has stopped RENDERING the meta fieldset while still
   naming it in that loop reads $_POST['meta']['title'] as absent, ?? ''
   supplies an empty string, and the page's title is blanked on every save --
   silently, because empty is a valid value and nothing throws.
   contract_page_bands() is what the page editors iterate instead, and
   sections/seo.php iterates the meta band alone. */

/**
 * Bring a meta band to the current shape, whatever it arrived as.
 *
 * $fallback is the document's own defaults, so a key that has never been
 * written comes back as the value the page ships with rather than as a blank --
 * the same floor every other band already has.
 */
function contract_meta_defaults(mixed $meta, array $fallback): array
{
    $meta = is_array($meta) ? $meta : [];
    $meta += $fallback;

    foreach (CONTRACT_META_TEXT as $field) {
        $meta[$field] = is_string($meta[$field] ?? null)
            ? trim($meta[$field])
            : (string)($fallback[$field] ?? '');
    }

    $meta['robots'] = in_array($meta['robots'] ?? '', CONTRACT_ROBOTS, true)
        ? $meta['robots']
        : (string)($fallback['robots'] ?? 'index');

    $meta['changefreq'] = in_array($meta['changefreq'] ?? '', CONTRACT_CHANGEFREQ, true)
        ? $meta['changefreq']
        : (string)($fallback['changefreq'] ?? 'monthly');

    /* One decimal, clamped. A priority outside 0.0-1.0 is a schema error
       against the whole sitemap, not a bad value on one line of it. */
    $meta['priority'] = number_format(
        min(1.0, max(0.0, (float)($meta['priority'] ?? $fallback['priority'] ?? 0.5))), 1);

    /* A comma-separated list, kept as the string it is edited and emitted as.
       Turning it into an array here and back again in the form would be one
       more shape for the two ends to disagree about, and there is nothing to
       address a single keyword by. Tidied rather than validated: empties and
       repeats dropped, one space after each comma. */
    $meta['keywords'] = contract_keywords((string)($meta['keywords'] ?? ''));

    /* Empty means "use the site-wide share card", which is what all seventeen
       pages do today -- so a document that has never been given one renders
       exactly the bytes it renders now. */
    $meta['share']     = contract_image_defaults($meta['share'] ?? []);
    $meta['share_alt'] = is_string($meta['share_alt'] ?? null)
        ? trim($meta['share_alt']) : '';

    return $meta;
}

/**
 * A keyword list, tidied into the one form the page will emit.
 *
 * Case-insensitively de-duplicated, because "Cloud" and "cloud" in one list is
 * a typo rather than two keywords, and the first spelling is the one kept.
 *
 * strtolower() and not mb_strtolower(), for the reason privacy_fold() already
 * records further down: nothing in either repository requires mbstring, and it
 * is not loaded where the tests run. Byte-wise folding leaves non-ASCII alone,
 * and it leaves it alone identically for every entry in the list, which is all
 * a de-duplication key asks of it.
 */
function contract_keywords(string $value): string
{
    $kept = [];
    foreach (explode(',', $value) as $word) {
        $word = trim((string)preg_replace('/\s+/', ' ', $word));
        if ($word === '') {
            continue;
        }
        $kept[strtolower($word)] ??= $word;
    }

    return implode(', ', $kept);
}

/**
 * Every band of a document the PAGE's own editor writes.
 *
 * Which is all of them except meta. See the note above: this is what stops a
 * form that no longer renders a field from posting an empty string over it.
 */
function contract_page_bands(array $text_fields): array
{
    unset($text_fields[CONTRACT_META_BAND]);
    return $text_fields;
}

/**
 * The share-card override a meta band points at, as web paths.
 *
 * Every document has one now, including the five that carry no other artwork,
 * so every document needs an arm in contract_images() -- see the note there
 * about one screen offering to delete another's uploads.
 */
function contract_meta_images(mixed $meta): array
{
    $meta = is_array($meta) ? $meta : [];
    $seen = [];

    foreach ([$meta['share']['src'] ?? '', $meta['share']['webp'] ?? ''] as $path) {
        $path = trim((string)$path);
        if ($path !== '') {
            $seen[$path] = true;
        }
    }

    return array_keys($seen);
}

/** Only the rows of a list a visitor should see. */
function company_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the page is shown at all. */
function company_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);}

function company_find(array $data, string $band, string $id): ?array
{
    foreach ($data[$band]['items'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/** Every picture the document points at, as web paths, without duplicates. */
function company_images(array $data): array
{
    $seen = [];

    foreach (COMPANY_LISTS as $band => $_filler) {
        foreach ($data[$band]['items'] ?? [] as $row) {
            foreach ([$row['image']['src'] ?? '', $row['image']['webp'] ?? ''] as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $seen[$path] = true;
                }
            }
        }
    }

    return array_keys($seen);
}

/** A URL-safe id from a name, unique against the ids already in use. */
function company_slug(string $name, array $taken = []): string
{
    return contract_slug($name, COMPANY_ID_PLACEHOLDER, $taken);}

/* ==========================================================================
   4. About page — the shape of the about page
   ========================================================================== */

/**
 * The icons a specialty card, a why-us card or the closing button may use.
 *
 * Fixed for the same reason CONTACT_ICONS and COMPANY_ICONS are:
 * tools/inject_icons.py inlines the symbols a page references by scanning it
 * for a literal href="#name", and a name chosen at run time is invisible to
 * that scan. Every icon offered here is therefore also listed in a comment in
 * the frontend's pages/about/index.php, where the scanner can see it. Add one
 * here and add it there; inject_icons.py --check says so if it is forgotten.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it.
 */
const ABOUT_ICONS = [
    'shield-alt'      => 'Shield',
    'code'            => 'Code',
    'cloud'           => 'Cloud',
    'users'           => 'People',
    'server'          => 'Server',
    'graduation-cap'  => 'Graduation cap',
    'trophy'          => 'Trophy',
    'layer-group'     => 'Stacked layers',
    'lightbulb'       => 'Lightbulb',
    'handshake'       => 'Handshake',
    'cogs'            => 'Cogs',
    'lock'            => 'Padlock',
    'project-diagram' => 'Project diagram',
    'eye'             => 'Eye',
    'arrow-right'     => 'Arrow',
    'check-circle'    => 'Tick in a circle',
];

/**
 * How a story row draws its picture.
 *
 * 'logo' draws a light/dark pair rather than one picture. The Tech4TIME
 * lockup that ships with the site is the fallback, so a row switched to this
 * layout works with nothing uploaded — but a new logo CAN be uploaded, per
 * row, because a company that changes its mark should not need a deploy to
 * show it.
 *
 * WHAT THIS DOES NOT CHANGE: the logo in the header, the footer, the browser
 * tab, the social share card and the Organization structured data. Those are
 * shared markup and build artefacts, not content — see
 * docs/40-reference/content-schemas.md.
 */
const ABOUT_LAYOUTS = [
    'photograph' => 'A photograph',
    'logo'       => 'The Tech4TIME logo lockup',
];

/** Which side of a story row the picture sits on. */
const ABOUT_SIDES = [
    'left'  => 'Picture on the left',
    'right' => 'Picture on the right',
];

/* Free-text single-line fields, by band. The story band has none: every
   heading on that part of the page belongs to a row, not to the band. */
const ABOUT_TEXT_FIELDS = [
    'meta'        => CONTRACT_META_TEXT,
    'hero'        => ['title', 'subtitle'],
    'specialties' => ['title'],
    'whyus'       => ['title'],
    'cta'         => ['title', 'label', 'href', 'icon'],
];

/**
 * Rich fields that live on a ROW rather than on a band.
 *
 * The about page has no band-level rich text — its one rich field is a story
 * section's prose, and there are five of those. Careers is shaped the same way
 * (CAREERS_RICH_FIELDS is applied per job), and contract_sanitise() already
 * knows how to walk a list; this just says which list and which fields.
 */
const ABOUT_ROW_RICH_FIELDS = ['story' => ['body']];

/* Every band of the page that can be hidden whole, in the order it renders.
   The hero is not here, for the reason the contact page's hero is not in
   CONTACT_BANDS: a page with no title is not a page with a section switched
   off, it is a broken page. */
const ABOUT_BANDS = ['story', 'specialties', 'whyus', 'cta'];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * Named once so about_normalise() can drive itself off it, exactly as
 * COMPANY_LISTS does. A list added to the page is normalised by being added
 * here rather than by somebody also remembering a line further down.
 */
const ABOUT_LISTS = [
    'story'       => 'about_story_defaults',
    'specialties' => 'about_specialty_defaults',
    'whyus'       => 'about_reason_defaults',
];

/* What a row is called before it is called anything. Deliberately the same
   value as COMPANY_ID_PLACEHOLDER and deliberately a separate constant: each
   document owns its own id vocabulary, and one changing must not move the
   other. See about_identify(). */
const ABOUT_ID_PLACEHOLDER = 'row';

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * about.json degrades to the shipped headings rather than emptying the page.
 * The lists default to empty, which is the same bargain the contact and
 * company pages make: the page still has a shape, it just has nothing in it.
 */
function about_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'About Tech4TIME | Trusted IT & Cybersecurity Solutions',
            'description' => 'Founded in 2018, Tech4TIME delivers cybersecurity, software development, cloud infrastructure, HRaaS and IT training — orchestrating technology with time.',
            'share_title' => 'About Tech4TIME',
            'breadcrumb'  => 'About Us',
'keywords'    => 'about Tech4TIME, IT company Bangladesh, '
               . 'cybersecurity company Dhaka, managed IT services, '
               . 'technology consultancy',
            'robots'      => 'index',
            'changefreq'  => 'monthly',
            'priority'    => '0.8',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero' => [
            'title'    => 'About Us',
            'subtitle' => 'Orchestrating Technology with Time',
        ],
        'story' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'specialties' => [
            'status'   => 'shown',
            'title'    => 'Our Specialities',
            'interval' => 10000,
            'items'    => [],
        ],
        'whyus' => [
            'status' => 'shown',
            'title'  => 'Why Us?',
            'items'  => [],
        ],
        'cta' => [
            'status' => 'shown',
            'title'  => 'Curious About our Services?',
            'label'  => 'Explore All Services',
            'href'   => '/pages/services/',
            'icon'   => 'arrow-right',
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per band, then every list through its own row-filler.
 * Rows are renumbered with array_values() because the editor posts them keyed
 * by position and a removed row leaves a hole.
 */
function about_normalise(array $data): array
{
    $defaults = about_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (ABOUT_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Clamped rather than refused, for the reason journey.interval is: this
       arrives from a file as often as from a form. */
    $data['specialties']['interval'] =
        min(60000, max(2000, (int)($data['specialties']['interval'] ?? 10000)));

    foreach (ABOUT_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return about_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Same contract as company_identify(): a row added by the Add button has
 * nothing to be named after and gets the placeholder; once it has a name the
 * placeholder is replaced; a real id is never re-minted, because it is the
 * handle a fragment link, an upload and a test all hold the row by.
 */
function about_identify(array $data): array
{
    foreach (ABOUT_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = about_row_name($band, $row);

            $provisional = $id === ''
                || preg_match('/^' . ABOUT_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;

            if (($provisional && $name !== '') || in_array($id, $taken, true)) {
                $id = about_slug($name, $taken);
            } elseif ($id === '') {
                $id = about_slug('', $taken);
            }

            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    return $data;
}

/** What a row's id is minted from, whichever list it is in. */
function about_row_name(string $band, array $row): string
{
    return trim((string)match ($band) {
        'story' => $row['heading'] ?? '',
        default => $row['title'] ?? '',
    });
}

/**
 * One image-and-prose section of the page.
 *
 * 'body' is sanitised HTML, not plain text: it is one or two paragraphs and
 * the editor writes it with the rich-text control. See ABOUT_ROW_RICH_FIELDS.
 */
function about_story_defaults(array $row): array
{
    $row += [
        'id'      => '',
        'heading' => '',
        'body'    => '',
        'layout'  => 'photograph',
        'side'    => 'left',
        'alt'     => '',
        'status'  => 'shown',
    ];

    $row['layout'] = isset(ABOUT_LAYOUTS[$row['layout']]) ? $row['layout'] : 'photograph';
    $row['side']   = isset(ABOUT_SIDES[$row['side']]) ? $row['side'] : 'left';
    $row['image']  = contract_image_defaults($row['image'] ?? []);

    /* The second half of the pair, for whichever layout the row uses: the dark
       lockup on a logo row, the dark artwork on a photograph one. Optional and
       usually empty — the illustrations sit on a white plate in both colour
       modes by design, so one picture is the normal case and a second is what
       switches that off for that row. Kept rather than cleared when the layout
       changes, for the same reason the first picture is: it can be switched
       back. */
    $row['image_dark'] = contract_image_defaults($row['image_dark'] ?? []);

    return $row;
}

/** A specialty card: the slider holds these. */
function about_specialty_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/** A why-us card. Same shape as a specialty, one line of text rather than a paragraph. */
function about_reason_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/** Only the rows of a list a visitor should see. */
function about_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the page is shown at all. */
function about_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);}

function about_find(array $data, string $band, string $id): ?array
{
    foreach ($data[$band]['items'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * Every picture the document points at, as web paths, without duplicates.
 *
 * Both halves of every row, whichever layout it uses. A row laid out as the
 * logo lockup still has its picture record counted: the layout can be switched
 * back, and a sweep that deleted the file the moment somebody chose 'logo'
 * would lose it for good.
 */
function about_images(array $data): array
{
    $seen = [];

    foreach ($data['story']['items'] ?? [] as $row) {
        foreach ([$row['image']['src'] ?? '',      $row['image']['webp'] ?? '',
                  $row['image_dark']['src'] ?? '', $row['image_dark']['webp'] ?? ''] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $seen[$path] = true;
            }
        }
    }

    return array_keys($seen);
}

/** A URL-safe id from a name, unique against the ids already in use. */
function about_slug(string $name, array $taken = []): string
{
    return contract_slug($name, ABOUT_ID_PLACEHOLDER, $taken);}

/* ==========================================================================
   5. Home page — the shape of the home page
   ========================================================================== */

/**
 * The icons a badge, a tag, a capability, a service card or a button may use.
 *
 * Fixed for the same reason CONTACT_ICONS, COMPANY_ICONS and ABOUT_ICONS are:
 * tools/inject_icons.py inlines the symbols a page references by scanning it
 * for a literal href="#name", and a name chosen at run time is invisible to
 * that scan. Every icon offered here is therefore also listed in a comment in
 * the frontend's index.php, where the scanner can see it. Add one here and add
 * it there; inject_icons.py --check says so if it is forgotten.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it.
 *
 * This is the longest of the four lists because the home page is the widest
 * summary of what the company does: the hero alone offers thirteen tags.
 */
const HOME_ICONS = [
    'shield-alt'          => 'Shield',
    'shield-halved'       => 'Shield, half filled',
    'shield-virus'        => 'Shield with a virus',
    'bug'                 => 'Bug',
    'search'              => 'Magnifying glass',
    'crosshairs'          => 'Crosshairs',
    'desktop'             => 'Monitor',
    'first-aid'           => 'First-aid kit',
    'cogs'                => 'Cogs',
    'server'              => 'Server',
    'network-wired'       => 'Network',
    'file-contract'       => 'Document',
    'graduation-cap'      => 'Graduation cap',
    'laptop-code'         => 'Laptop with code',
    'mobile-alt'          => 'Mobile phone',
    'code'                => 'Code',
    'cloud'               => 'Cloud',
    'users'               => 'People',
    'boxes'               => 'Boxes',
    'chalkboard-teacher'  => 'Teacher at a board',
    'sitemap'             => 'Sitemap',
    'clipboard-check'     => 'Clipboard with a tick',
    'lightbulb'           => 'Lightbulb',
    'eye'                 => 'Eye',
    'rocket'              => 'Rocket',
    'arrow-right'         => 'Arrow',
];

/**
 * What a line of the hero terminal is.
 *
 * A 'command' line is typed out character by character by
 * assets/js/terminal.js and carries a prompt in front of it; an 'output' line
 * arrives whole, the way a shell prints. That distinction is the whole effect,
 * so it is a field and not a guess made from the text.
 */
const HOME_LINE_KINDS = [
    'command' => 'A typed command',
    'output'  => 'Output from the command',
];

/**
 * How a line of output is coloured. Ignored on a 'command' line.
 *
 * The tick and the exclamation mark that begin the success and alert lines are
 * part of the text, not added by CSS, so an operator writes them and can write
 * something else. This only picks the colour.
 */
const HOME_LINE_TONES = [
    'plain'   => 'Plain',
    'success' => 'Success',
    'alert'   => 'Alert',
];

/* The prompt a command line shows when it has none of its own. */
const HOME_PROMPT_DEFAULT = 'tech4time@soc:~$';

/* Free-text single-line fields, by band. The list bands carry only their own
   headings here; everything inside them belongs to a row. */
const HOME_TEXT_FIELDS = [
    'meta'         => CONTRACT_META_TEXT,
    'hero'         => ['title', 'accent', 'cta_label', 'cta_href'],
    'terminal'     => ['title', 'summary'],
    'capabilities' => ['title', 'lead'],
    'services'     => ['eyebrow', 'title', 'lead', 'schema_name', 'schema_description'],
    'destinations' => ['eyebrow', 'title', 'lead'],
    'cta'          => ['title', 'text', 'label', 'href', 'icon'],
];

/**
 * The home page has NO rich text, deliberately.
 *
 * Every lead and every card body on it is a single styled <p> — .section__lead,
 * .service-card__text, .destination-card__text. A rich field would emit a <div>
 * full of paragraphs where one <p> is styled, so the control would offer
 * formatting the page cannot show. The one field that needs two lines is the
 * closing title, and it gets them from a newline rather than from markup; see
 * home_cta_title() in the frontend's lib/home.php.
 *
 * Named as an empty constant rather than left out so that the question "where
 * is the home page's rich text?" has an answer in the file.
 */
const HOME_ROW_RICH_FIELDS = [];

/* Every band of the page that can be hidden whole, in the order it renders.
   The hero itself is not here, for the reason the contact and about heroes are
   not: a page with no title is not a page with a section switched off, it is a
   broken page. Its badges, tags and terminal ARE here — they decorate the hero
   and the hero reads perfectly well without any of them. */
const HOME_BANDS = [
    'badges', 'tags', 'terminal', 'capabilities', 'services', 'destinations', 'cta',
];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * Six of them — the most of any document here. Named once so home_normalise()
 * drives itself off it, exactly as ABOUT_LISTS and COMPANY_LISTS do.
 *
 * The hero's badges and tags are top-level bands rather than nested under
 * 'hero' so that every list on the page has the same $data[$band]['items']
 * shape. Nesting two of the six would mean home_identify(), home_shown() and
 * home_find() each carrying a special case for exactly those two, which is
 * three places for the same exception to be forgotten.
 */
const HOME_LISTS = [
    'badges'       => 'home_badge_defaults',
    'tags'         => 'home_tag_defaults',
    'terminal'     => 'home_line_defaults',
    'capabilities' => 'home_capability_defaults',
    'services'     => 'home_service_defaults',
    'destinations' => 'home_destination_defaults',
];

/* What a row is called before it is called anything. Deliberately the same
   value as ABOUT_ID_PLACEHOLDER and deliberately a separate constant: each
   document owns its own id vocabulary. See home_identify(). */
const HOME_ID_PLACEHOLDER = 'row';

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * home.json degrades to the shipped headings rather than emptying the site's
 * front door. The lists default to empty, which is the same bargain the other
 * three pages make: the page still has a shape, it just has nothing in it.
 */
function home_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'Tech4TIME | Orchestrating Technology with Time',
            'description' => 'Enterprise-grade cybersecurity, software development, cloud infrastructure and HR solutions from Tech4TIME. Orchestrate, build, maintain and protect your business.',
            'share_title' => 'Tech4TIME | Orchestrating Technology with Time',
            'breadcrumb'  => 'Home',
'keywords'    => 'IT services Bangladesh, cybersecurity, '
               . 'software development, cloud infrastructure, HRaaS, '
               . 'IT consultancy, Tech4TIME',
            'robots'      => 'index',
            'changefreq'  => 'weekly',
            'priority'    => '1.0',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero' => [
            'title'     => 'Orchestrating Technology with Time',
            /* The phrase drawn in the accent colour. See home_hero_title(). */
            'accent'    => 'Technology',
            'cta_label' => 'Explore All Services',
            'cta_href'  => '/pages/services/',
        ],
        'badges' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'tags' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'terminal' => [
            'status'  => 'shown',
            'title'   => 'tech4time@soc:~',
            /* The one-line description that stands in for the panel, which is
               aria-hidden. It is not decoration: it is what a screen reader
               reads instead of the whole session. */
            'summary' => 'Illustration: a security operations console showing twelve connected agents and two high-severity alerts in the last 24 hours.',
            'items'   => [],
        ],
        'capabilities' => [
            'status' => 'shown',
            'title'  => 'Our Technical Domains',
            'lead'   => 'The principles and practices we cherish from our roots throughout the endeavour of time.',
            'items'  => [],
        ],
        'services' => [
            'status'  => 'shown',
            'eyebrow' => 'Our Services',
            'title'   => 'Complete Technology Solutions',
            'lead'    => 'Tech4TIME provides end-to-end IT services from Software Development, Cybersecurity and Cloud Infrastructure, along with Human Resource Provisioning — which we also call Human Resource as a Service (HRaaS).',
            /* What the Service ItemList in the <head> calls itself. Separate
               from the band's own heading because it is addressed to a search
               engine rather than to a reader, and the two have never said the
               same thing. Each Service node inside the list takes its name,
               description and url from the card — one source, so the six cards
               and the six schema entries cannot drift apart again. They had:
               the card read "SOC & CIRT" while the schema read "SOC and
               CIRT". */
            'schema_name'        => 'Tech4TIME technology services',
            'schema_description' => 'End-to-end IT services from software development, cybersecurity and cloud infrastructure to human resource provisioning.',
            'items'   => [],
        ],
        'destinations' => [
            'status'  => 'shown',
            'eyebrow' => 'Explore Tech4TIME',
            'title'   => 'Get to Know Us',
            'lead'    => 'Three ways in — who we are, what we deliver, and the track record behind it.',
            'items'   => [],
        ],
        'cta' => [
            'status' => 'shown',
            'icon'   => 'rocket',
            /* Two lines. The newline becomes a <br>; see home_cta_title(). */
            'title'  => "Transform Your Digital Landscape\nwith Expert Technology Solutions",
            'text'   => 'From software development to cybersecurity — complete IT services for your business growth.',
            'label'  => 'Start Your Project',
            'href'   => '/pages/contact/',
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * One level of merge per band, then every list through its own row-filler.
 * Rows are renumbered with array_values() because the editor posts them keyed
 * by position and a removed row leaves a hole.
 */
function home_normalise(array $data): array
{
    $defaults = home_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (HOME_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    foreach (HOME_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return home_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Same contract as about_identify(): a row added by the Add button has nothing
 * to be named after and gets the placeholder; once it has a name the
 * placeholder is replaced; a real id is never re-minted, because it is the
 * handle a fragment link, an upload and a test all hold the row by.
 */
function home_identify(array $data): array
{
    foreach (HOME_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = home_row_name($band, $row);

            $provisional = $id === ''
                || preg_match('/^' . HOME_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;

            if (($provisional && $name !== '') || in_array($id, $taken, true)) {
                $id = home_slug($name, $taken);
            } elseif ($id === '') {
                $id = home_slug('', $taken);
            }

            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    return $data;
}

/** What a row's id is minted from, whichever list it is in. */
function home_row_name(string $band, array $row): string
{
    return trim((string)match ($band) {
        'badges', 'tags' => $row['label'] ?? '',
        'terminal'       => $row['text'] ?? '',
        default          => $row['title'] ?? '',
    });
}

/** A hero badge: the four headline disciplines under the title. */
function home_badge_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'label' => '', 'status' => 'shown',
    ];
}

/** A hero tag: the smaller pills under the badges. Same shape. */
function home_tag_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'label' => '', 'status' => 'shown',
    ];
}

/**
 * One line of the hero terminal.
 *
 * The blinking caret at the end of the session is NOT one of these. It is
 * emitted by the renderer after the last line, so an operator cannot delete it
 * or end up with two — see home_terminal_lines() in the frontend's lib/home.php.
 */
function home_line_defaults(array $row): array
{
    $row += [
        'id'     => '',
        'kind'   => 'output',
        'tone'   => 'plain',
        'prompt' => HOME_PROMPT_DEFAULT,
        'text'   => '',
        'status' => 'shown',
    ];

    $row['kind'] = isset(HOME_LINE_KINDS[$row['kind']]) ? $row['kind'] : 'output';
    $row['tone'] = isset(HOME_LINE_TONES[$row['tone']]) ? $row['tone'] : 'plain';

    return $row;
}

/** A capability card: an icon and a title, nothing else. */
function home_capability_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'status' => 'shown',
    ];
}

/**
 * A service card.
 *
 * 'link_hint' is the visually-hidden tail on the card's link — "for
 * Cybersecurity" — which turns six identical "View Services" links into six
 * distinguishable ones for anyone listing the links on the page. It is a field
 * rather than something derived from the title because the wording differs
 * from it: the card titled "IT Consultancy & Training" reads "and", not "&".
 */
function home_service_defaults(array $row): array
{
    return $row + [
        'id'        => '',
        'icon'      => '',
        'title'     => '',
        'text'      => '',
        'href'      => '',
        'label'     => 'View Services',
        'link_hint' => '',
        'status'    => 'shown',
    ];
}

/**
 * A "Get to Know Us" card: a picture, a title, a line and a button.
 *
 * Two picture records, not one. The illustrations are black line art on white
 * and the page keeps them on a light plate in both colour modes, so the dark
 * half is usually empty and the light one is used for both — exactly the
 * fallback a story row's logo takes. Uploading a dark half is what switches
 * that off, per card. See home_theme_pair() in the frontend's lib/home.php.
 */
function home_destination_defaults(array $row): array
{
    $row += [
        'id'        => '',
        'title'     => '',
        'text'      => '',
        'href'      => '',
        'label'     => 'Learn more',
        'link_hint' => '',
        'alt'       => '',
        'status'    => 'shown',
    ];

    $row['image']      = contract_image_defaults($row['image'] ?? []);
    $row['image_dark'] = contract_image_defaults($row['image_dark'] ?? []);

    return $row;
}

/** Only the rows of a list a visitor should see. */
function home_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the page is shown at all. */
function home_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);}

function home_find(array $data, string $band, string $id): ?array
{
    foreach ($data[$band]['items'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * Every picture the document points at, as web paths, without duplicates.
 *
 * Both halves of every destination card, for the reason about_images() counts
 * both halves of a logo row: an unused-file sweep that ignored the dark half
 * would offer to delete a dark image the moment it was uploaded, because
 * nothing else in the document mentions it.
 */
function home_images(array $data): array
{
    $seen = [];

    foreach ($data['destinations']['items'] ?? [] as $row) {
        foreach ([$row['image']['src'] ?? '',      $row['image']['webp'] ?? '',
                  $row['image_dark']['src'] ?? '', $row['image_dark']['webp'] ?? ''] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $seen[$path] = true;
            }
        }
    }

    return array_keys($seen);
}

/** A URL-safe id from a name, unique against the ids already in use. */
function home_slug(string $name, array $taken = []): string
{
    return contract_slug($name, HOME_ID_PLACEHOLDER, $taken);}

/* ==========================================================================
   6. Services — the shape of the services index AND its detail pages
   ========================================================================== */

/**
 * The icons a nav card, a service block, a core card, a layer, a solution
 * card, an OSSF stage or a button may use.
 *
 * Fixed for the same reason CONTACT_ICONS, COMPANY_ICONS, ABOUT_ICONS and
 * HOME_ICONS are: tools/inject_icons.py inlines the symbols a page references
 * by scanning it for a literal href="#name", and a name chosen at run time is
 * invisible to that scan. Every icon offered here is therefore also listed in
 * a comment in the frontend's pages/services/index.php and in lib/services.php,
 * where the scanner can see it. Add one here and add it there;
 * inject_icons.py --check says so if it is forgotten.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it.
 *
 * These 74 are the ones the six pages actually use, read off the markup at the
 * migration rather than guessed. 'check' and 'arrow-right' are deliberately
 * NOT here: they are drawn by the renderer as furniture -- the tick beside a
 * feature, the chevron on a button -- and are not a choice anybody makes.
 */
const SERVICES_ICONS = [
    'balance-scale'     => 'Scales',
    'ban'               => 'Prohibited',
    'bolt'              => 'Lightning bolt',
    'boxes'             => 'Boxes',
    'brain'             => 'Brain',
    'bug'               => 'Bug',
    'building'          => 'Building',
    'calendar-alt'      => 'Calendar',
    'calendar-check'    => 'Calendar with a tick',
    'certificate'       => 'Certificate',
    'chalkboard-teacher'=> 'Teacher at a board',
    'chart-bar'         => 'Bar chart',
    'chart-line'        => 'Line chart',
    'check-double'      => 'Double tick',
    'clipboard-check'   => 'Clipboard with a tick',
    'clock'             => 'Clock',
    'cloud'             => 'Cloud',
    'code'              => 'Code',
    'code-branch'       => 'Branch',
    'cogs'              => 'Cogs',
    'crosshairs'        => 'Crosshairs',
    'cubes'             => 'Cubes',
    'database'          => 'Database',
    'desktop'           => 'Desktop computer',
    'dharmachakra'      => 'Ship wheel',
    'dumbbell'          => 'Dumbbell',
    'exchange-alt'      => 'Exchange arrows',
    'eye'               => 'Eye',
    'file-contract'     => 'Contract',
    'first-aid'         => 'First aid kit',
    'gavel'             => 'Gavel',
    'globe'             => 'Globe',
    'graduation-cap'    => 'Graduation cap',
    'hdd'               => 'Hard disk',
    'headset'           => 'Headset',
    'infinity'          => 'Infinity',
    'laptop-code'       => 'Laptop with code',
    'layer-group'       => 'Stacked layers',
    'link'              => 'Chain link',
    'list-alt'          => 'List',
    'lock'              => 'Padlock',
    'microscope'        => 'Microscope',
    'mobile-alt'        => 'Mobile phone',
    'money-bill-wave'   => 'Banknote',
    'network-wired'     => 'Network',
    'palette'           => 'Palette',
    'people-arrows'     => 'People exchanging',
    'project-diagram'   => 'Project diagram',
    'redo'              => 'Redo arrow',
    'robot'             => 'Robot',
    'rocket'            => 'Rocket',
    'search'            => 'Magnifying glass',
    'search-minus'      => 'Zoom out',
    'search-plus'       => 'Zoom in',
    'server'            => 'Server',
    'shield-alt'        => 'Shield',
    'shield-cross'      => 'Shield with a cross',
    'shield-halved'     => 'Half shield',
    'shield-virus'      => 'Shield with a virus',
    'sitemap'           => 'Sitemap',
    'tachometer-alt'    => 'Speedometer',
    'tasks'             => 'Task list',
    'tools'             => 'Tools',
    'user-check'        => 'Person with a tick',
    'user-lock'         => 'Person with a padlock',
    'user-ninja'        => 'Ninja',
    'user-secret'       => 'Person in disguise',
    'user-shield'       => 'Person with a shield',
    'user-tie'          => 'Person in a tie',
    'users'             => 'People',
    'users-cog'         => 'People with a cog',
    'vial'              => 'Test tube',
    'virus'             => 'Virus',
    'wrench'            => 'Wrench',
];

/**
 * How a group of check-items sits in a service block on the INDEX page.
 *
 * 'wide' spans the row and splits its list into two columns; 'normal' is one
 * column in the grid. Authored rather than derived: the cybersecurity block
 * and the software-development block both hold three groups and lay them out
 * differently, so no rule over the count reproduces what is there.
 */
const SERVICES_GROUP_WIDTHS = [
    'normal' => 'One column',
    'wide'   => 'Full width, list split in two',
];

/* Free-text single-line fields of the INDEX document, by band. */
const SERVICES_TEXT_FIELDS = [
    'meta' => CONTRACT_META_TEXT,
    'hero' => ['title', 'subtitle'],
    'nav'  => ['eyebrow', 'title', 'lead'],
    'ossf' => ['eyebrow', 'title', 'lead'],
    'cta'  => ['title', 'text', 'label', 'href', 'icon'],
];

/* Free-text single-line fields of ONE SERVICE, by band. */
const SERVICES_PAGE_TEXT_FIELDS = [
    'service' => ['name', 'slug', 'schema_type', 'schema_description'],
    'meta'   => CONTRACT_META_TEXT,
    'hero'   => ['title', 'subtitle'],
    'core'   => ['eyebrow', 'title', 'lead'],
    'layers' => ['eyebrow', 'title', 'lead'],
    'cta'    => ['title', 'text', 'label', 'href', 'icon'],
];

/* Every band of the INDEX that can be hidden whole, in the order it renders.
   The hero is not here, for the reason the about page's hero is not in
   ABOUT_BANDS: a page with no title is not a page with a section switched off,
   it is a broken page. */
const SERVICES_BANDS = ['nav', 'blocks', 'ossf', 'cta'];

/* Every band of ONE SERVICE PAGE that can be hidden whole, in render order. */
const SERVICES_PAGE_BANDS = ['core', 'layers', 'cta'];

/**
 * The INDEX bands that hold a list, and the function that fills one of their
 * rows. Same contract as ABOUT_LISTS and HOME_LISTS.
 */
const SERVICES_LISTS = [
    'nav'    => 'services_nav_defaults',
    'blocks' => 'services_block_defaults',
    'ossf'   => 'services_ossf_defaults',
];

/* What a row is called before it is called anything. Deliberately the same
   value as ABOUT_ID_PLACEHOLDER and HOME_ID_PLACEHOLDER, and deliberately a
   separate constant: each document owns its own id vocabulary, and one
   changing must not move the others. See services_identify(). */
const SERVICES_ID_PLACEHOLDER = 'row';

/**
 * The prefix every solution card's id carries.
 *
 * The 137 cards that shipped are all 'sol-...', and that id is load-bearing
 * three times over: it is the card's HTML id, the fragment a link can hold it
 * by, and the data-solution attribute the ring node uses to bring the card up.
 * A new card is minted into the same vocabulary so the page stays one thing.
 */
const SERVICES_CARD_PREFIX = 'sol-';

/**
 * The prefix the renderer puts in front of a layer's id in the markup.
 *
 * A layer is stored bare -- 'reactive' -- and rendered as id="layer-reactive",
 * which is what the tab links to. Stored that way, and not with the prefix in
 * the file, for the same reason a service block is stored as 'cybersecurity'
 * and rendered with both id="cybersecurity" and
 * aria-labelledby="cybersecurity-heading": the id is the name of the thing,
 * and the decorations around it belong to whoever draws it.
 */
const SERVICES_LAYER_PREFIX = 'layer-';

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * services.json degrades to the shipped headings rather than emptying the
 * page. The lists default to empty, which is the same bargain every other
 * document makes: the page still has a shape, it just has nothing in it.
 *
 * WHY ONE DOCUMENT AND NOT SEVEN. CONTRACT_DOCUMENTS is a constant in code, so
 * a document name cannot be invented at run time -- and a seventh service has
 * to be addable from the editor. A service is therefore a ROW IN A LIST, which
 * puts the index and all six detail pages in one file. That is also why this
 * section is longer than the others: it models seven pages, not one.
 */
function services_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta' => [
            'title'       => 'Services — Cybersecurity, Software, Cloud & HRaaS | Tech4TIME',
            'description' => "Software development, cybersecurity and SOC build-out, private cloud on OpenStack, HRaaS, IT equipment supply, consultancy and training — Tech4TIME's six practices.",
            'share_title' => 'Our Comprehensive Technological Solutions',
            'breadcrumb'  => 'Services',
'keywords'    => 'IT services, cybersecurity services, software development, '
               . 'cloud infrastructure, HRaaS, IT consultancy and training, '
               . 'IT equipment supply',
            'robots'      => 'index',
            'changefreq'  => 'weekly',
            'priority'    => '0.9',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero' => [
            'title'    => 'Services',
            'subtitle' => 'Our Comprehensive Technological Solutions',
        ],
        'nav' => [
            'status'  => 'shown',
            'eyebrow' => '',
            'title'   => '',
            'lead'    => '',
            'items'   => [],
        ],
        'blocks' => [
            'status' => 'shown',
            'items'  => [],
        ],
        'ossf' => [
            'status'  => 'shown',
            'eyebrow' => 'How We Work',
            'title'   => 'Our OSSF Framework',
            'lead'    => 'Every security engagement runs through the same eight stages, from first measuring the threat landscape to keeping the business running after an incident.',
            'items'   => [],
        ],
        'cta' => [
            'status' => 'shown',
            'title'  => 'You want to try our service? Get in touch!',
            'text'   => 'Tell us what you are protecting, building or running. We will come back with a scope, a timeline and a straight answer on cost.',
            'label'  => 'Contact Us Now',
            'href'   => '/pages/contact/',
            'icon'   => 'calendar-check',
        ],
        /* The six detail pages. Not a band -- it has no heading and no place on
           the index -- so it is not in SERVICES_BANDS and cannot be hidden as a
           whole. A single service is hidden by its own status. */
        'services' => [
            'items' => [],
        ],
    ];
}

/**
 * Bring a document to the current shape, whatever it arrived as.
 *
 * DEVIATES FROM about_normalise() AND home_normalise(), DELIBERATELY. Those
 * walk one level: band -> items[]. This document is four deep --
 * services[] -> layers[] -> cards[] -> tags[] -- because a service is a whole
 * page rather than a card. The walk is written out rather than driven off a
 * constant, because a constant that expressed this would be harder to read
 * than the walk it replaced, and the walk is the thing that has to be right.
 */
function services_normalise(array $data): array
{
    $defaults = services_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (SERVICES_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    foreach (SERVICES_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    $services = is_array($data['services']['items'] ?? null)
        ? $data['services']['items'] : [];
    $data['services']['items'] = array_map(
        'services_service_defaults',
        array_values(array_filter($services, 'is_array'))
    );

    return services_identify($data);
}

/**
 * One whole detail page: its own meta, hero, four bands and everything under
 * them. Every field the renderer reads is defaulted here, so a service added
 * by the Add button renders a real page rather than a fatal.
 */
function services_service_defaults(array $row): array
{
    $row += [
        'id'     => '',
        'slug'   => '',
        'name'   => '',
        'status' => 'shown',
        /* The Service structured data. 'schema_type' is schema.org's word for
           the practice and is NOT the name: the HRaaS page is 'IT Staffing'
           and the consultancy page is 'IT Consulting', because those are the
           terms a search engine has a definition for. The rest of the block is
           generated -- the offer catalogue IS the layers -- so it cannot drift
           from the page the way the home page's did before
           home_service_schema() took it over. */
        'schema_type'        => '',
        'schema_description' => '',
    ];

    /* A service page's own metadata, edited on the SEO screen like every other
       page's. THE BREADCRUMB FALLS BACK TO THE SERVICE'S NAME, which is what
       services_breadcrumbs() used before this field existed -- so the six
       services that have never been given one render the trail they render
       today, and a seventh added by the Add button gets a sensible one without
       anybody visiting a second screen. changefreq and priority are the values
       sitemap.php applied to every service from one pair of constants; they
       are per-service now because a seventh service is not obliged to be worth
       the same as the first six. */
    $row['meta'] = contract_meta_defaults($row['meta'] ?? [], [
        'title'       => '',
        'description' => '',
        'share_title' => '',
        'breadcrumb'  => (string)$row['name'],
        /* Its own name, so a seventh service arrives with a keyword list that
           says something rather than an empty field nobody will notice. */
        'keywords'    => (string)$row['name'],
        'robots'      => 'index',
        'changefreq'  => 'monthly',
        'priority'    => '0.9',
        'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
        'share_alt'   => '',
    ]);

    $row['hero'] = is_array($row['hero'] ?? null) ? $row['hero'] : [];
    $row['hero'] += ['title' => '', 'subtitle' => ''];

    /* The two core cards and the note under them. */
    $row['core'] = is_array($row['core'] ?? null) ? $row['core'] : [];
    $row['core'] += [
        'status' => 'shown', 'eyebrow' => '', 'title' => '', 'lead' => '',
    ];
    $row['core']['note'] = is_array($row['core']['note'] ?? null)
        ? $row['core']['note'] : [];
    $row['core']['note'] += ['text' => '', 'link_label' => '', 'link_href' => ''];
    $core = is_array($row['core']['items'] ?? null) ? $row['core']['items'] : [];
    $row['core']['items'] = array_map(
        'services_core_defaults',
        array_values(array_filter($core, 'is_array'))
    );

    /* The tabbed layers, each holding its own solution cards. */
    $row['layers'] = is_array($row['layers'] ?? null) ? $row['layers'] : [];
    $row['layers'] += [
        'status' => 'shown', 'eyebrow' => '', 'title' => '', 'lead' => '',
    ];
    /* The two list headings a solution card shows, and the noun under a layer's
       card count. Constant for a whole page rather than per card, which is how
       they were authored: every card on the cloud page says "What it includes"
       and "Technologies", every card on the equipment page says "Scope". An
       empty features label means the page's cards have no features block at
       all -- three of the six do not. */
    $row['layers']['labels'] = is_array($row['layers']['labels'] ?? null)
        ? $row['layers']['labels'] : [];
    $row['layers']['labels'] += [
        'purpose'    => 'Purpose',
        'features'   => '',
        'tags'       => '',
        'count_one'  => 'Solution',
        'count_many' => 'Solutions',
    ];
    $layers = is_array($row['layers']['items'] ?? null) ? $row['layers']['items'] : [];
    $row['layers']['items'] = array_map(
        'services_layer_defaults',
        array_values(array_filter($layers, 'is_array'))
    );

    $row['cta'] = is_array($row['cta'] ?? null) ? $row['cta'] : [];
    $row['cta'] += [
        'status' => 'shown', 'title' => '', 'text' => '',
        'label'  => '', 'href' => '/pages/contact/', 'icon' => 'calendar-check',
    ];

    foreach (SERVICES_PAGE_BANDS as $band) {
        $row[$band]['status'] =
            ($row[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    return $row;
}

/** One of the two cards at the top of a detail page. */
function services_core_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/**
 * One tab of a detail page, and the solution cards under it.
 *
 * 'hub_label' is the word at the centre of the ring. Stored rather than
 * derived from the title: the ring says REACTIVE where the tab says "Reactive
 * Response", and no rule over the title produces that.
 */
function services_layer_defaults(array $row): array
{
    $row += [
        'id'        => '',
        'icon'      => '',
        'title'     => '',
        'tab_text'  => '',
        'text'      => '',
        'hub_label' => '',
        'status'    => 'shown',
    ];

    $cards = is_array($row['cards'] ?? null) ? $row['cards'] : [];
    $row['cards'] = array_map(
        'services_card_defaults',
        array_values(array_filter($cards, 'is_array'))
    );

    return $row;
}

/**
 * One solution card.
 *
 * 'features' and 'tags' are plain lists of short strings -- the ticked list and
 * the pill list. Both are optional: half the pages have no features block.
 * Nothing here is rich text; see the note on contract_sanitise().
 */
function services_card_defaults(array $row): array
{
    $row += [
        'id'       => '',
        'icon'     => '',
        'name'     => '',
        'category' => '',
        'desc'     => '',
        'purpose'  => '',
        'status'   => 'shown',
    ];

    $row['features'] = services_strings($row['features'] ?? []);
    $row['tags']     = services_strings($row['tags'] ?? []);

    return $row;
}

/** A nav card at the top of the index: one per block, linking down the page. */
function services_nav_defaults(array $row): array
{
    return $row + [
        /* 'block', not 'service': this card jumps DOWN THE INDEX to a service
           block, and the two are not the same name. The HRaaS block is
           id="hraas" and the service it belongs to is slug "hr-solutions". */
        'id' => '', 'block' => '', 'icon' => '',
        'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/**
 * One service block on the index.
 *
 * 'service' names the row in services.items[] this block belongs to, which is
 * what its buttons link to. The groups are authored here rather than derived
 * from that service's layers: the index says "Offensive Security & Penetration
 * Testing (Metasploit, Burp Suite)" where the detail page says "Offensive
 * Security & Penetration Testing", and the HRaaS block lists four engagement
 * models against the detail page's thirty-three resource types. They are two
 * different summaries of the same practice, and flattening them into one would
 * lose the shorter.
 */
function services_block_defaults(array $row): array
{
    $row += [
        'id'      => '',
        'service' => '',
        'icon'    => '',
        'title'   => '',
        'intro'   => '',
        'status'  => 'shown',
    ];

    $groups = is_array($row['groups'] ?? null) ? $row['groups'] : [];
    $row['groups'] = array_map(
        'services_group_defaults',
        array_values(array_filter($groups, 'is_array'))
    );

    $buttons = is_array($row['buttons'] ?? null) ? $row['buttons'] : [];
    $row['buttons'] = array_map(
        'services_button_defaults',
        array_values(array_filter($buttons, 'is_array'))
    );

    return $row;
}

/** A titled list of check-items inside a service block. */
function services_group_defaults(array $row): array
{
    $row += ['id' => '', 'title' => '', 'width' => 'normal', 'status' => 'shown'];

    $row['width'] = isset(SERVICES_GROUP_WIDTHS[$row['width']])
        ? $row['width'] : 'normal';
    $row['items'] = services_strings($row['items'] ?? []);

    return $row;
}

/**
 * A button under a service block.
 *
 * A list rather than one button, because the HRaaS block has two: through to
 * the detail page, and out to the resource certification list.
 */
function services_button_defaults(array $row): array
{
    return $row + [
        'id' => '', 'label' => '', 'href' => '',
        'icon' => '', 'style' => 'secondary', 'status' => 'shown',
    ];
}

/** One stage of the OSSF framework. The number beside it is its position. */
function services_ossf_defaults(array $row): array
{
    return $row + [
        'id' => '', 'icon' => '', 'title' => '', 'text' => '', 'status' => 'shown',
    ];
}

/** A list of short plain strings, holes closed and blanks dropped. */
function services_strings(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        if (is_string($item) && trim($item) !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * Give every row an id, unique within its own list.
 *
 * Same contract as about_identify() and home_identify(): a row added by the
 * Add button has nothing to be named after and gets the placeholder; once it
 * has a name the placeholder is replaced; A REAL ID IS NEVER RE-MINTED,
 * because it is the handle a fragment link, a ring node and a test all hold
 * the row by.
 *
 * That last clause is doing more work here than anywhere else. Sixty-three of
 * the hundred and thirty-seven solution cards that shipped carry an id that
 * does NOT follow from the card's name -- 'sol-cloud-design-private-cloud' on
 * a card called "Private Cloud Design & Implementation". They were written by
 * hand. Re-minting them would break every deep link into the services pages
 * that anyone has ever saved, so the migration stores them verbatim and this
 * function leaves them alone.
 */
function services_identify(array $data): array
{
    foreach (SERVICES_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $id = services_mint((string)($row['id'] ?? ''), (string)($row['title'] ?? ''), $taken);
            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    /* The groups and buttons of a block are numbered within that block, not
       across the page: they are form handles, never fragment targets. */
    foreach ($data['blocks']['items'] as $b => $block) {
        foreach (['groups', 'buttons'] as $list) {
            $taken = [];
            foreach ($block[$list] as $i => $row) {
                $name = (string)($row['title'] ?? $row['label'] ?? '');
                $id   = services_mint((string)($row['id'] ?? ''), $name, $taken);
                $data['blocks']['items'][$b][$list][$i]['id'] = $id;
                $taken[] = $id;
            }
        }
    }

    $slugs = [];
    foreach ($data['services']['items'] as $s => $service) {
        $name = (string)($service['name'] ?? $service['hero']['title'] ?? '');

        $id = services_mint((string)($service['id'] ?? ''), $name, $slugs);
        $data['services']['items'][$s]['id'] = $id;
        $slugs[] = $id;

        /* The slug is the URL. It follows the id when it has never been set,
           and is otherwise left alone: changing it moves the page. */
        $slug = trim((string)($service['slug'] ?? ''));
        $data['services']['items'][$s]['slug'] = $slug !== '' ? $slug : $id;

        $taken = [];
        foreach ($service['core']['items'] as $i => $row) {
            $cid = services_mint((string)($row['id'] ?? ''), (string)($row['title'] ?? ''), $taken);
            $data['services']['items'][$s]['core']['items'][$i]['id'] = $cid;
            $taken[] = $cid;
        }

        /* Layer ids and card ids share one namespace per page, because both
           end up as HTML ids on the same document. */
        $layers = [];
        $cards  = [];
        foreach ($service['layers']['items'] as $l => $layer) {
            /* A layer that arrives carrying the rendered prefix is stored
               without it, so a hand-edited file and a published one agree. */
            $bare = (string)($layer['id'] ?? '');
            if (str_starts_with($bare, SERVICES_LAYER_PREFIX)) {
                $bare = substr($bare, strlen(SERVICES_LAYER_PREFIX));
            }

            $lid = services_mint($bare, (string)($layer['title'] ?? ''), $layers);
            $data['services']['items'][$s]['layers']['items'][$l]['id'] = $lid;
            $layers[] = $lid;

            foreach ($layer['cards'] as $c => $card) {
                $given = trim((string)($card['id'] ?? ''));
                $kid   = $given !== '' && !services_provisional($given)
                    ? services_unique($given, $cards)
                    : services_unique(
                        SERVICES_CARD_PREFIX . $lid . '-' . services_slug((string)($card['name'] ?? '')),
                        $cards
                    );
                $data['services']['items'][$s]['layers']['items'][$l]['cards'][$c]['id'] = $kid;
                $cards[] = $kid;
            }
        }
    }

    return $data;
}

/** Whether an id is one this file minted as a placeholder rather than a name. */
function services_provisional(string $id): bool
{
    return contract_provisional($id, SERVICES_ID_PLACEHOLDER);}

/** Keep a real id, replace a placeholder once there is a name to replace it with. */
function services_mint(string $id, string $name, array $taken): string
{
    return contract_mint($id, $name, SERVICES_ID_PLACEHOLDER, $taken);}

/** A URL-safe id from a name. */
function services_slug(string $name, array $taken = []): string
{
    return contract_slug($name, SERVICES_ID_PLACEHOLDER, $taken);}

/** The same id, suffixed until nothing else in the list has it. */
function services_unique(string $slug, array $taken): string
{
    return contract_unique($slug, $taken);}

/** Only the rows of an index list a visitor should see. */
function services_shown(array $data, string $band): array
{
    return array_values(array_filter(
        $data[$band]['items'] ?? [],
        static fn(array $row): bool => ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

/** Whether a band of the index is shown at all. */
function services_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);}

/** Only the rows of a list a visitor should see, wherever the list is. */
function services_rows_shown(array $rows): array
{
    return contract_rows_shown($rows);}

/**
 * Every picture this document points at, as web paths, without duplicates.
 *
 * Which today means the share-card override on each of the seven pages it
 * holds -- the index and the six services -- and nothing else: the services
 * pages draw icons from the sprite, not uploads. It still has to exist and
 * still has to be complete, because contract_images() is what tells the sweep
 * on every OTHER screen which uploads are spoken for.
 */
function services_images(array $data): array
{
    $seen = [];

    foreach (services_all($data) as $service) {
        foreach (contract_meta_images($service['meta'] ?? []) as $path) {
            $seen[$path] = true;
        }
    }

    return array_keys($seen);
}

/** Every service, hidden ones included. The editor lists these. */
function services_all(array $data): array
{
    return is_array($data['services']['items'] ?? null) ? $data['services']['items'] : [];
}

/** One service by its slug, or null. The renderer 404s on the null. */
function services_by_slug(array $data, string $slug): ?array
{
    foreach (services_all($data) as $service) {
        if (($service['slug'] ?? '') === $slug) {
            return $service;
        }
    }
    return null;
}

/**
 * The blocks on the index a visitor should see.
 *
 * A block is hidden when ITS OWN status says so, and ALSO when the service it
 * belongs to is hidden or gone. That second rule is what stops the index
 * advertising a page that answers 404: hiding a service has to hide the whole
 * of it, or the only thing hiding achieves is a broken link. A block naming no
 * service is left alone -- it may point anywhere.
 */
function services_blocks_visible(array $data): array
{
    $out = [];
    foreach (services_shown($data, 'blocks') as $block) {
        $slug = trim((string)($block['service'] ?? ''));
        if ($slug !== '') {
            $service = services_by_slug($data, $slug);
            if ($service === null || ($service['status'] ?? 'shown') === 'hidden') {
                continue;
            }
        }
        $out[] = $block;
    }
    return $out;
}

/**
 * The nav cards a visitor should see.
 *
 * One per block still on the page. A card whose block has gone would scroll a
 * reader to nothing, so it goes with it.
 */
function services_nav_visible(array $data): array
{
    $blocks = [];
    foreach (services_blocks_visible($data) as $block) {
        $blocks[] = $block['id'];
    }

    $out = [];
    foreach (services_shown($data, 'nav') as $row) {
        $target = trim((string)($row['block'] ?? ''));
        if ($target !== '' && !in_array($target, $blocks, true)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/** One service by its id, or null. */
function services_by_id(array $data, string $id): ?array
{
    foreach (services_all($data) as $service) {
        if (($service['id'] ?? '') === $id) {
            return $service;
        }
    }
    return null;
}

/* ==========================================================================
   7. Resource certifications — the shape of the certifications page
   ========================================================================== */

/**
 * Icons a role group may carry.
 *
 * The four the page ships with are the first four; the rest are offered
 * because a new role group needs something to wear and picking from a list is
 * the only way to add one without a deploy.
 *
 * A certification does NOT choose an icon. All 54 on the page carry the same
 * glyph and always did, so it is a constant in the renderer
 * (CERTIFICATIONS_CERT_GLYPH) rather than 54 copies of one answer.
 *
 * tools/inject_icons.py scans the MARKUP for a literal href="#name", and a
 * name chosen at run time is invisible to that scan. Every icon offered here
 * is therefore also listed in a comment in the frontend's
 * pages/resource-certifications/index.php, where the scanner can see it.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it.
 */
const CERTIFICATIONS_ICONS = [
    'shield-halved'  => 'Shield',
    'crosshairs'     => 'Crosshairs',
    'first-aid'      => 'First aid',
    'cogs'           => 'Cogs',
    'certificate'    => 'Certificate',
    'users'          => 'People',
    'server'         => 'Server',
    'lock'           => 'Padlock',
    'eye'            => 'Eye',
    'graduation-cap' => 'Graduation cap',
    'code'           => 'Code',
    'cloud'          => 'Cloud',
];

/**
 * The glyph every certification carries.
 *
 * Not a field. All 54 certifications on the page use #certificate, so storing
 * it 54 times would be 54 chances for them to stop matching each other and no
 * chance of the page looking better for it. The renderer emits it.
 */
const CERTIFICATIONS_CERT_GLYPH = 'certificate';

/* Free-text single-line fields, by band. A group's own heading belongs to the
   group, not to the band, so 'certs' carries only the band header. */
const CERTIFICATIONS_TEXT_FIELDS = [
    'meta'  => CONTRACT_META_TEXT,
    'hero'  => ['title', 'subtitle'],
    'certs' => ['eyebrow', 'title', 'lead'],
    'cta'   => ['title', 'text'],
];

/* Every band that can be hidden whole, in the order it renders. The hero is
   not here, for the reason the about page's hero is not in ABOUT_BANDS: a page
   with no title is not a page with a section switched off, it is a broken
   page. */
const CERTIFICATIONS_BANDS = ['certs', 'cta'];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * 'certs' is not here. Its rows are role groups, which nest two further lists
 * of their own, so they are normalised by certifications_group_defaults()
 * rather than by a flat map — the same reason services.items is handled apart
 * from SERVICES_LISTS.
 */
const CERTIFICATIONS_LISTS = [
    'cta' => 'certifications_button_defaults',
];

/* What a row is called before it is called anything. Deliberately its own
   constant: each document owns its id vocabulary, and one changing must not
   move another. See certifications_identify(). */
const CERTIFICATIONS_ID_PLACEHOLDER = 'row';

/**
 * The tokens any text field may carry, and what each counts.
 *
 * The page states its own totals in prose -- "54 certifications across the
 * four specialist roles we staff" -- and a typed number is wrong the moment
 * somebody adds a certification. Nothing on the page would notice, and no
 * check could: it is a true sentence that has quietly stopped being true.
 *
 * So the numbers are not typed. A field holds a token, and the renderer puts
 * the live figure in as it draws.
 *
 * WATCH THE ARITHMETIC. The page's "four specialist roles" is the number of
 * GROUPS, not the number of role names -- there are ten of those, spread two,
 * three, four and one across the four groups. Both counts are offered because
 * the page's own copy means different things by "role" in different sentences,
 * and the editor shows what each one resolves to so nobody has to guess.
 */
const CERTIFICATIONS_TOKENS = ['certifications', 'groups', 'roles'];

/**
 * The same three counts, spelled out.
 *
 * The page does not write its two numbers the same way. The lead says
 * "54 certifications across the four specialist roles" -- a numeral and then a
 * word, which is ordinary English and how a person would type it. A token that
 * could only produce digits would quietly reword that sentence to "the 4
 * specialist roles" the first time it rendered, and the page would have been
 * edited by the migration rather than by anybody who meant to.
 *
 * So every token has a -word form: {groups} is 4 and {groups-word} is "four".
 * Above twenty it gives up and returns the numeral, because "fifty-four
 * certifications" is a style decision no one asked this file to make, and a
 * count that far up is being read, not narrated.
 */
const CERTIFICATIONS_TOKEN_WORD_SUFFIX = '-word';

const CERTIFICATIONS_NUMBER_WORDS = [
    0  => 'no',       1  => 'one',      2  => 'two',       3  => 'three',
    4  => 'four',     5  => 'five',     6  => 'six',       7  => 'seven',
    8  => 'eight',    9  => 'nine',     10 => 'ten',       11 => 'eleven',
    12 => 'twelve',   13 => 'thirteen', 14 => 'fourteen',  15 => 'fifteen',
    16 => 'sixteen',  17 => 'seventeen',18 => 'eighteen',  19 => 'nineteen',
    20 => 'twenty',
];

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * certifications.json degrades to the shipped headings rather than emptying
 * the page. The lists default to empty: the page keeps its shape and has
 * nothing in it, which is the same bargain about and company make.
 */
function certifications_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta'     => [
            'title'       => 'Resource Certifications | Tech4TIME',
            'description' => 'The {certifications} security certifications our analysts, '
                           . 'incident responders, threat hunters and security engineers hold, '
                           . 'grouped by the role they are deployed in.',
            'share_title' => 'Resource Certifications',
            'breadcrumb'  => 'Resource Certifications',
'keywords'    => 'security certifications, certified security analysts, '
               . 'incident response, threat hunting, security engineering, '
               . 'Tech4TIME',
            'robots'      => 'index',
            'changefreq'  => 'monthly',
            'priority'    => '0.6',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero'     => [
            'title'    => 'Resource Certifications',
            'subtitle' => 'The Qualifications Our People Hold, by Role',
        ],
        'certs'    => [
            'status'  => 'shown',
            'eyebrow' => 'Our People',
            'title'   => 'Certifications by Role',
            'lead'    => '{certifications} certifications across the {groups-word} specialist '
                       . 'roles we staff, from the vendors and standards bodies that set them.',
            'items'   => [],
        ],
        'cta'      => [
            'status' => 'shown',
            'title'  => 'Need a certified resource on your team?',
            'text'   => '',
            'items'  => [],
        ],
    ];
}

/**
 * Fill in everything the renderer reads, whatever the file happens to hold.
 */
function certifications_normalise(array $data): array
{
    $defaults = certifications_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (CERTIFICATIONS_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    foreach (CERTIFICATIONS_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    $groups = is_array($data['certs']['items'] ?? null) ? $data['certs']['items'] : [];
    $data['certs']['items'] = array_map(
        'certifications_group_defaults',
        array_values(array_filter($groups, 'is_array'))
    );

    return certifications_identify($data);
}

/**
 * One role group: a <details> on the page, holding role names and the
 * certifications the people in those roles hold.
 *
 * 'open' is the group that starts expanded. It is authored rather than
 * derived -- the page ships with the first group open and the other three
 * shut, and which one greets a visitor is an editorial decision, not an
 * accident of ordering.
 *
 * 'slug' is the anchor the <details> carries. It is minted once from the
 * first role name and then left alone, because a link into the page is a
 * promise and renaming a role must not break it.
 */
function certifications_group_defaults(array $row): array
{
    $row += [
        'id'     => '',
        'slug'   => '',
        'icon'   => '',
        'blurb'  => '',
        'status' => 'shown',
        'open'   => false,
    ];

    $row['open'] = (bool)$row['open'];

    $roles = is_array($row['roles'] ?? null) ? $row['roles'] : [];
    $row['roles'] = array_map(
        'certifications_role_defaults',
        array_values(array_filter($roles, 'is_array'))
    );

    $certs = is_array($row['items'] ?? null) ? $row['items'] : [];
    $row['items'] = array_map(
        'certifications_cert_defaults',
        array_values(array_filter($certs, 'is_array'))
    );

    return $row;
}

/** One role name inside a group. The page prints these separated by a slash. */
function certifications_role_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'name'   => '',
        'status' => 'shown',
    ];
}

/**
 * One certification.
 *
 * A name and nothing else. There is no icon field: see
 * CERTIFICATIONS_CERT_GLYPH.
 */
function certifications_cert_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'name'   => '',
        'status' => 'shown',
    ];
}

/** One button in the closing band. The page ships with two. */
function certifications_button_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'label'  => '',
        'href'   => '',
        'icon'   => '',
        'style'  => 'primary',
        'status' => 'shown',
    ];
}

/**
 * Give every row an id, and every group a stable anchor.
 *
 * Group ids and slugs share the page's fragment namespace, because a group is
 * a <details id="..."> a visitor can be linked straight to. Roles and
 * certifications are numbered WITHIN their group: they are form handles and
 * never fragment targets, so two groups may each hold a "cissp" without
 * either having to be renamed.
 */
function certifications_identify(array $data): array
{
    foreach (CERTIFICATIONS_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $name = (string)($row['label'] ?? $row['title'] ?? '');
            $id   = certifications_mint((string)($row['id'] ?? ''), $name, $taken);
            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    $anchors = [];
    foreach ($data['certs']['items'] as $g => $group) {
        /* A group is named by its first role -- "Security Analyst / Threat
           Analyst" is headed security-analyst -- because a group has no title
           of its own. It is the roles, and the first one is the one that
           names it. */
        $first = '';
        foreach ($group['roles'] as $role) {
            $first = (string)($role['name'] ?? '');
            if (trim($first) !== '') {
                break;
            }
        }

        $id = certifications_mint((string)($group['id'] ?? ''), $first, $anchors);
        $data['certs']['items'][$g]['id'] = $id;
        $anchors[] = $id;

        /* The slug is the anchor. It follows the id until the id is a real
           one, and is left alone from then on: changing it breaks every link
           anyone has ever made into this page.

           "Until the id is REAL" is the whole subtlety. A group added by the
           Add button has no roles yet, so there is no name to mint from and
           the id is the placeholder. Freezing the slug to that would leave a
           group answering to #row for the rest of its life, named after
           nothing, and the first person to add a second one would get #row-2.
           A placeholder slug is therefore still up for grabs; a slug somebody
           has actually been given is not. */
        $slug = trim((string)($group['slug'] ?? ''));
        $data['certs']['items'][$g]['slug'] =
            ($slug !== '' && !certifications_provisional($slug)) ? $slug : $id;

        foreach (['roles', 'items'] as $list) {
            $taken = [];
            foreach ($group[$list] as $i => $row) {
                $rid = certifications_mint(
                    (string)($row['id'] ?? ''), (string)($row['name'] ?? ''), $taken);
                $data['certs']['items'][$g][$list][$i]['id'] = $rid;
                $taken[] = $rid;
            }
        }
    }

    return $data;
}

/** An id nobody has chosen: empty, or the placeholder the Add button leaves. */
function certifications_provisional(string $id): bool
{
    return contract_provisional($id, CERTIFICATIONS_ID_PLACEHOLDER);}

/** Keep a real id, replace a placeholder once there is a name to replace it with. */
function certifications_mint(string $id, string $name, array $taken): string
{
    return contract_mint($id, $name, CERTIFICATIONS_ID_PLACEHOLDER, $taken);}

/** A URL-safe id from a name. */
function certifications_slug(string $name, array $taken = []): string
{
    return contract_slug($name, CERTIFICATIONS_ID_PLACEHOLDER, $taken);}

/** The same id, suffixed until nothing else in the list has it. */
function certifications_unique(string $slug, array $taken): string
{
    return contract_unique($slug, $taken);}

/** Whether a band is shown at all. */
function certifications_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);}

/** Only the rows of a list a visitor should see, wherever the list is. */
function certifications_rows_shown(array $rows): array
{
    return contract_rows_shown($rows);}

/** Every role group, hidden ones included. The editor lists these. */
function certifications_groups(array $data): array
{
    return is_array($data['certs']['items'] ?? null) ? $data['certs']['items'] : [];
}

/**
 * What the page currently holds, for the tokens to resolve to.
 *
 * SHOWN ROWS ONLY, and that is the whole point. A hidden certification is not
 * on the page, so a sentence saying how many certifications there are must not
 * count it -- and the "27 certifications" label the renderer puts on each
 * group counts the same way. Two numbers on one page derived from two
 * different rules is worse than no numbers at all.
 *
 * 'groups' and 'roles' are genuinely different figures: four groups, ten role
 * names. The page's own lead means the first when it says "the four specialist
 * roles we staff".
 */
function certifications_counts(array $data): array
{
    $groups = certifications_rows_shown(certifications_groups($data));

    $certifications = 0;
    $roles          = 0;

    foreach ($groups as $group) {
        $certifications += count(certifications_rows_shown($group['items'] ?? []));
        $roles          += count(certifications_rows_shown($group['roles'] ?? []));
    }

    return [
        'certifications' => $certifications,
        'groups'         => count($groups),
        'roles'          => $roles,
    ];
}

/**
 * Put the live figures into a piece of text.
 *
 * THIS LIVES HERE, IN THE SHARED FILE, ON PURPOSE. The public page renders it
 * and the editor previews it, and lib/contract.php is byte-identical across
 * both repositories -- checked by tools/check_shared_lib.py and
 * tools/check_shared_repos.py. So the preview an editor is shown and the
 * sentence a visitor reads cannot disagree about what a token means. Two
 * copies of this function in two repositories could, and one day would.
 *
 * SUBSTITUTE FIRST, ESCAPE AFTER. Every value is a decimal integer, so the
 * order is safe either way -- but one order has to be chosen, and this is it:
 * callers pass the result through h() exactly as they would any other stored
 * string, and nothing about a token changes that habit.
 *
 * An unknown token is left exactly as it was typed. Somebody experimenting
 * with {certificates} gets their own text back rather than an empty space
 * where a word used to be.
 */
function certifications_word(int $n): string
{
    return CERTIFICATIONS_NUMBER_WORDS[$n] ?? (string)$n;
}

function certifications_fill(string $text, array $counts): string
{
    foreach (CERTIFICATIONS_TOKENS as $token) {
        $n = (int)($counts[$token] ?? 0);

        /* The spelled form first. Replacing {groups} before
           {groups-word} would leave the string holding "4-word". */
        $text = str_replace(
            '{' . $token . CERTIFICATIONS_TOKEN_WORD_SUFFIX . '}',
            certifications_word($n),
            $text
        );
        $text = str_replace('{' . $token . '}', (string)$n, $text);
    }

    return $text;
}

/* ==========================================================================
   8. Branding & advertisement — the shape of the branding page
   ========================================================================== */

/**
 * The plate a logo preview sits on.
 *
 * Not a theme token, and deliberately so. assets/css/pages/branding.css
 * hard-codes the light and dark plates because the ink in a transparent logo
 * does NOT change with the site's colour mode: put the light-theme mark on a
 * dark plate in dark mode and it is black ink on black. Each preview shows the
 * background the file is actually for, in both modes, which is what makes the
 * preview honest -- you are seeing where it works.
 *
 * So this is an authored property of the artwork, not a display preference,
 * and it belongs in the document.
 */
const BRANDING_PLATES = [
    'light'   => 'Pale plate, for a mark in dark ink',
    'dark'    => 'Dark plate, for a mark in pale ink',
    'neutral' => 'Neutral, for a file that carries its own background',
];

/**
 * The glyph on every download button.
 *
 * Not a field. All four buttons on the page carry #arrow-down and always did,
 * so it is a constant in the renderer rather than four copies of one answer --
 * the same call CERTIFICATIONS_CERT_GLYPH makes.
 *
 * Because it is constant it also stays a literal <use href="#arrow-down"> in
 * pages/branding-and-advertisement/index.php, where tools/inject_icons.py can
 * see it. Nothing on this page picks an icon at run time, so unlike the
 * certifications page it needs no second sprite.
 */
const BRANDING_DOWNLOAD_GLYPH = 'arrow-down';

/* Free-text single-line fields, by band. An asset's own heading belongs to the
   asset, not to the band, so 'assets' carries only the band header; the legal
   band carries only its title, because its paragraphs are rows. */
const BRANDING_TEXT_FIELDS = [
    'meta'   => CONTRACT_META_TEXT,
    'hero'   => ['title', 'subtitle'],
    'assets' => ['eyebrow', 'title', 'lead'],
    'legal'  => ['title'],
    'cta'    => ['title', 'text'],
];

/**
 * Rich fields that live on a ROW rather than on a band.
 *
 * The disclaimer's paragraphs. They are plain prose today, but this is a legal
 * notice: a bolded clause, or a link to the contact page from the sentence
 * that asks a rights holder to get in touch, is exactly what someone will
 * eventually want, and rt_sanitise_html() already decides what is allowed.
 * Shaped like ABOUT_ROW_RICH_FIELDS, which contract_sanitise() already knows
 * how to walk.
 */
const BRANDING_ROW_RICH_FIELDS = ['legal' => ['text']];

/* Every band that can be hidden whole, in the order it renders. The hero is
   not here, for the reason the about page's hero is not in ABOUT_BANDS: a page
   with no title is not a page with a section switched off, it is a broken
   page. */
const BRANDING_BANDS = ['assets', 'legal', 'cta'];

/**
 * The bands that hold a list, and the function that fills one of its rows.
 *
 * 'assets' is not here. Its rows nest a further list of their own -- the files
 * a visitor can download -- so they are normalised by branding_asset_defaults()
 * rather than by a flat map, the same reason certifications.certs is handled
 * apart from CERTIFICATIONS_LISTS.
 */
const BRANDING_LISTS = [
    'legal' => 'branding_note_defaults',
    'cta'   => 'branding_button_defaults',
];

/* What a row is called before it is called anything. Deliberately its own
   constant: each document owns its id vocabulary, and one changing must not
   move another. See branding_identify(). */
const BRANDING_ID_PLACEHOLDER = 'row';

/**
 * The longest a download's saved-as name may be.
 *
 * It goes in a download="" attribute, which is a suggestion to the visitor's
 * operating system rather than a path here, but a name of unbounded length is
 * still a thing this document should not carry.
 */
const BRANDING_FILENAME_MAX = 120;

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * Every scalar the renderer reads exists here, so a truncated or hand-edited
 * branding.json degrades to the shipped headings rather than emptying the
 * page. The lists default to empty: the page keeps its shape and has nothing
 * in it, which is the same bargain about and certifications make.
 */
function branding_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta'     => [
            'title'       => 'Branding Assets & Guidelines | Tech4TIME',
            'description' => 'Download the Tech4TIME logo in four variants for light and '
                           . 'dark backgrounds, transparent or plated, with the terms '
                           . 'covering their use.',
            'share_title' => 'Branding Assets & Guidelines | Tech4TIME',

            /* WHAT THE SITE CALLS THIS PAGE, WHICH IS NOT WHAT THE PAGE CALLS
               ITSELF. The hero is titled "Branding Assets & Guidelines"; every
               link to it -- the footer's, and this breadcrumb -- says
               "Branding & Advertisement". That is a real distinction and an
               authored one: a breadcrumb names a place in a hierarchy, and a
               heading introduces a page.

               This page and the privacy policy were the only two that carried
               the field, because they were the only two whose two strings
               differed; the about, company and certifications pages let their
               breadcrumb follow hero.title instead. Every page has the field
               now -- the SEO screen edits one shape, not seven -- and each of
               those three was seeded with the string it was already
               rendering. */
            'breadcrumb'  => 'Branding & Advertisement',
'keywords'    => 'Tech4TIME logo, brand assets, logo download, brand guidelines, press kit',
            'robots'      => 'index',
            'changefreq'  => 'yearly',
            'priority'    => '0.4',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero'     => [
            'title'    => 'Branding Assets & Guidelines',
            'subtitle' => 'Our Logo, and How to Use It',
        ],
        'assets'   => [
            'status'  => 'shown',
            'eyebrow' => 'Downloads',
            'title'   => 'Tech4TIME Logos for Branding & Advertisement',
            'lead'    => 'Four variants of the mark. Pick the one that matches the '
                       . 'background it will sit on.',
            'items'   => [],
        ],
        'legal'    => [
            'status' => 'shown',
            'title'  => 'Disclaimer',
            'items'  => [],
        ],
        'cta'      => [
            'status' => 'shown',
            'title'  => 'Need something not listed here?',
            'text'   => '',
            'items'  => [],
        ],
    ];
}

/**
 * Fill in everything the renderer reads, whatever the file happens to hold.
 */
function branding_normalise(array $data): array
{
    $defaults = branding_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (BRANDING_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    foreach (BRANDING_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    $assets = is_array($data['assets']['items'] ?? null) ? $data['assets']['items'] : [];
    $data['assets']['items'] = array_map(
        'branding_asset_defaults',
        array_values(array_filter($assets, 'is_array'))
    );

    return branding_identify($data);
}

/**
 * One logo variant: a card on the page, with a preview and the files to take.
 *
 * TWO PICTURES, AND THEY ARE NOT THE SAME PICTURE. 'image' is the preview
 * drawn on the card -- small, lazy-loaded, with a WebP sibling, and never
 * larger than it needs to be. The files under 'files' are what a visitor
 * actually downloads, and on the page as it ships those are a different and
 * much larger set: an 800px preview against a 1600px download.
 *
 * Collapsing the two would mean either serving the big file to everybody who
 * merely looks at the page, or handing out the small one to everybody who came
 * for the logo. Both are real losses, so both slots exist.
 */
function branding_asset_defaults(array $row): array
{
    $row += [
        'id'     => '',
        'title'  => '',
        'text'   => '',
        'alt'    => '',
        'plate'  => 'neutral',
        'status' => 'shown',
    ];

    $row['plate'] = isset(BRANDING_PLATES[$row['plate']]) ? $row['plate'] : 'neutral';
    $row['image'] = contract_image_defaults($row['image'] ?? []);

    $files = is_array($row['files'] ?? null) ? $row['files'] : [];
    $row['files'] = array_map(
        'branding_file_defaults',
        array_values(array_filter($files, 'is_array'))
    );

    return $row;
}

/**
 * One downloadable file belonging to a logo variant.
 *
 * A list rather than a single slot, so one mark can offer a raster AND a
 * vector -- and, later, whatever else somebody needs -- without the page being
 * rebuilt for it.
 *
 * 'label' is the adjective in the meta line, "Transparent PNG". The dimensions
 * beside it are NOT stored: branding_meta_line() reads them off the file, so
 * they cannot claim 1600 x 570 about a file that is no longer that size.
 *
 * 'filename' is the download="" attribute -- what the visitor's computer calls
 * the file once it lands. Without it a browser saves an uploaded file under
 * its content-addressed name, and somebody's Downloads folder fills up with
 * a1b2c3d4e5f60718.png.
 */
function branding_file_defaults(array $row): array
{
    $row += [
        'id'       => '',
        'label'    => '',
        'filename' => '',
        'status'   => 'shown',
    ];

    $row['filename'] = branding_safe_filename((string)$row['filename']);
    $row['file']     = contract_image_defaults($row['file'] ?? []);

    return $row;
}

/** One paragraph of the disclaimer. 'text' is sanitised HTML; see BRANDING_ROW_RICH_FIELDS. */
function branding_note_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'text'   => '',
        'status' => 'shown',
    ];
}

/** One button in the closing band. The page ships with one. */
function branding_button_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'label'  => '',
        'href'   => '',
        'style'  => 'primary',
        'status' => 'shown',
    ];
}

/**
 * A saved-as name, or '' if it is not one this page will offer.
 *
 * This never touches a filesystem on either host -- it is a hint to the
 * visitor's browser -- but it is still author-supplied text that ends up in an
 * attribute, so it is bounded here rather than trusted. A separator would let
 * a name read as a path when it reaches the other end; a control character has
 * no business in a filename anywhere.
 */
function branding_safe_filename(string $name): string
{
    $name = trim($name);

    if ($name === '' || strlen($name) > BRANDING_FILENAME_MAX) {
        return '';
    }
    if (preg_match('~[\x00-\x1f\x7f/\\\\]|\.\.~', $name)) {
        return '';
    }
    if ($name === '.' || str_starts_with($name, '.')) {
        return '';
    }

    return $name;
}

/**
 * Give every row an id.
 *
 * Assets are numbered across the band; the files inside one are numbered
 * WITHIN it, exactly as certifications numbers roles within a group. Nothing
 * on this page is a fragment target -- an asset card carries no anchor -- so
 * two variants may each offer a "png" without either being renamed.
 */
function branding_identify(array $data): array
{
    foreach (BRANDING_LISTS as $band => $_filler) {
        $taken = [];
        foreach ($data[$band]['items'] as $i => $row) {
            $name = (string)($row['label'] ?? $row['title'] ?? '');
            $id   = branding_mint((string)($row['id'] ?? ''), $name, $taken);
            $data[$band]['items'][$i]['id'] = $id;
            $taken[] = $id;
        }
    }

    $taken = [];
    foreach ($data['assets']['items'] as $a => $asset) {
        $id = branding_mint((string)($asset['id'] ?? ''), (string)($asset['title'] ?? ''), $taken);
        $data['assets']['items'][$a]['id'] = $id;
        $taken[] = $id;

        $held = [];
        foreach ($asset['files'] as $f => $file) {
            /* A file is named for the format it is, which is the one thing
               about it that is always known -- the label may be empty on a row
               somebody has only just added. */
            $name = (string)($file['label'] ?? '');
            if (trim($name) === '') {
                $name = branding_file_ext($file);
            }
            $fid = branding_mint((string)($file['id'] ?? ''), $name, $held);
            $data['assets']['items'][$a]['files'][$f]['id'] = $fid;
            $held[] = $fid;
        }
    }

    return $data;
}

/** An id nobody has chosen: empty, or the placeholder the Add button leaves. */
function branding_provisional(string $id): bool
{
    return contract_provisional($id, BRANDING_ID_PLACEHOLDER);}

/** Keep a real id, replace a placeholder once there is a name to replace it with. */
function branding_mint(string $id, string $name, array $taken): string
{
    return contract_mint($id, $name, BRANDING_ID_PLACEHOLDER, $taken);}

/** A URL-safe id from a name. */
function branding_slug(string $name, array $taken = []): string
{
    return contract_slug($name, BRANDING_ID_PLACEHOLDER, $taken);}

/** The same id, suffixed until nothing else in the list has it. */
function branding_unique(string $slug, array $taken): string
{
    return contract_unique($slug, $taken);}

/** Whether a band is shown at all. */
function branding_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);}

/** Only the rows of a list a visitor should see, wherever the list is. */
function branding_rows_shown(array $rows): array
{
    return contract_rows_shown($rows);}

/** Every logo variant, hidden ones included. The editor lists these. */
function branding_assets(array $data): array
{
    return is_array($data['assets']['items'] ?? null) ? $data['assets']['items'] : [];
}

/**
 * Every picture this document points at, as web paths, without duplicates.
 *
 * BOTH SLOTS OF EVERY ROW. A card's preview and each of its downloads are
 * separate files, and a hidden row's are counted too: hiding is not deleting,
 * and a sweep that dropped the file the moment somebody hid the card would
 * lose it for good.
 */
function branding_images(array $data): array
{
    $seen = [];

    foreach ($data['assets']['items'] ?? [] as $asset) {
        $paths = [$asset['image']['src'] ?? '', $asset['image']['webp'] ?? ''];

        foreach ($asset['files'] ?? [] as $file) {
            $paths[] = $file['file']['src'] ?? '';
            $paths[] = $file['file']['webp'] ?? '';
        }

        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $seen[$path] = true;
            }
        }
    }

    return array_keys($seen);
}

/**
 * The format a downloadable file is, in upper case, read from the file itself.
 *
 * From the stored path rather than from anything typed. Both hosts compute
 * that path from the bytes -- publish_asset_name() gives it the extension the
 * header said it was -- so the extension here is as trustworthy as the file.
 */
function branding_file_ext(array $file): string
{
    $src = (string)($file['file']['src'] ?? $file['src'] ?? '');
    $ext = strtoupper(pathinfo($src, PATHINFO_EXTENSION));

    return $ext === 'JPG' ? 'JPEG' : $ext;
}

/**
 * The line above a download button: what the file is, and how big.
 *
 * DERIVED, and that is the point. The page says "Transparent PNG - 1600 x 570"
 * today and the number is typed, so it is wrong the moment the file behind it
 * is replaced -- and nothing on the page or in any check would notice. The
 * adjective stays authored, because "Transparent" is editorial; the dimensions
 * come off the record.
 *
 * They are omitted rather than guessed when the file does not carry them,
 * which is the rule about_picture() already follows for width and height. A
 * line that says less is better than one that says something untrue.
 */
function branding_meta_line(array $file): string
{
    $label = trim((string)($file['label'] ?? ''));
    if ($label === '') {
        $label = branding_file_ext($file);
    }

    $width  = (int)($file['file']['width'] ?? 0);
    $height = (int)($file['file']['height'] ?? 0);

    if ($width > 0 && $height > 0) {
        $size = $width . ' × ' . $height;
        return $label === '' ? $size : $label . ' · ' . $size;
    }

    return $label;
}

/**
 * The words on a download button.
 *
 * Derived, not stored: "Download PNG" states the file's own format, and a
 * stored copy is one more thing that can stop matching the file it describes.
 */
function branding_download_label(array $file): string
{
    $ext = branding_file_ext($file);

    return $ext === '' ? 'Download' : 'Download ' . $ext;
}

/* ==========================================================================
   9. Privacy policy — the shape of a legal document
   ========================================================================== */

/**
 * The kinds of block a section may hold, and what the editor calls each one.
 *
 * A CLOSED SET, and forced as much as chosen. rt_sanitise_html() allows nine
 * tags -- p, br, strong, em, u, ul, ol, li, a -- and no heading, no <address>
 * and no <table> among them. Structure therefore cannot live in a rich-text
 * field: somebody typing <h3> into one would watch it disappear on save, with
 * no way to tell that from a bug. So structure is a KIND, and the renderer
 * owns the markup for it; the rich field carries only what may appear inside a
 * paragraph.
 *
 * The list is what the page already contains and nothing more. A seventh shape
 * costs a row here and an arm in privacy_block_defaults(), which is the whole
 * price of adding one.
 */
const PRIVACY_BLOCK_KINDS = [
    'paragraph'  => 'Paragraph',
    'list'       => 'Bulleted list',
    'subheading' => 'Subheading',
    'note'       => 'Highlighted note',
    'address'    => 'Address block',
    'table'      => 'Two-column table',
];

/**
 * The kinds whose own 'text' is markup rather than plain words.
 *
 * INLINE MARKUP ONLY, THROUGH rt_sanitise_inline(). Every one of these is
 * rendered INSIDE an element the renderer supplies -- a <p>, a
 * <p class="legal__notice">, an <address> -- so a <p> arriving from the editor
 * is not emphasis somebody added, it is a paragraph inside a paragraph. And
 * pressing Enter in a textarea is how it would arrive, which is not a corner
 * case. The same goes for a list row and a summary point, both of which land
 * in an <li>.
 *
 * 'address' is here, and that is the interesting one. Two of its five lines
 * carry links -- a mailto: and a tel: -- and rt_safe_href() permits exactly
 * those schemes, so the block round-trips as written, <strong>, <br> and all.
 * Holding it as a list of plain lines instead would have thrown both links
 * away.
 */
const PRIVACY_RICH_BLOCKS = ['paragraph', 'note', 'address'];

/** 'subheading' is the one kind whose text is plain: it becomes an <h3>. */
const PRIVACY_PLAIN_BLOCKS = ['subheading'];

/** The kinds that hold rows of their own, and the function that fills one row. */
const PRIVACY_ROW_BLOCKS = [
    'list'  => 'privacy_item_defaults',
    'table' => 'privacy_cell_defaults',
];

/* Free-text single-line fields, by band. The sections carry their own
   headings, so 'policy' holds only the effective date and the callout. */
const PRIVACY_TEXT_FIELDS = [
    'meta'   => CONTRACT_META_TEXT,
    'hero'   => ['title', 'subtitle'],
    'policy' => ['label', 'effective'],
    'cta'    => ['title', 'text'],
];

/**
  * Every band that can be hidden whole, in the order it renders.
  *
  * NEITHER THE HERO NOR THE POLICY IS HERE. The hero is excluded for the
  * reason it is excluded from ABOUT_BANDS and BRANDING_BANDS -- a page with no
  * title is not a page with a section switched off, it is a broken page.
  *
  * The policy band is excluded for a stronger reason. Hiding it would leave a
  * page headed "Privacy Policy" with no policy on it, still linked from the
  * footer of all sixteen pages and still in the sitemap. That is not a
  * configuration anybody wants; it is a compliance incident with a switch. The
  * callout inside it can be hidden, and so can any single section.
  */
const PRIVACY_BANDS = ['cta'];

/* The bands that hold a flat list. 'policy' is not here: its sections nest
   blocks, and blocks nest rows, so they are normalised by
   privacy_section_defaults() rather than by a flat map -- the same reason
   branding.assets and certifications.certs are handled apart. */
const PRIVACY_LISTS = ['cta' => 'privacy_button_defaults'];

/* What a block or a row is called before it is called anything. */
const PRIVACY_ID_PLACEHOLDER = 'row';

/**
 * What a SECTION is called before it is called anything, and a separate
 * constant on purpose.
 *
 * A section's id is its anchor. #row-4 is a fragment somebody could link to
 * and then find renamed; #section-4 at least says what it is while it waits
 * for a heading. Blocks and rows are not fragment targets and keep the plain
 * placeholder.
 */
const PRIVACY_SECTION_PLACEHOLDER = 'section';

/**
 * The page as it ships, and the fallback for anything missing from the file.
 *
 * The sections default to EMPTY, as every list-bearing document's do: a
 * truncated or hand-edited privacy.json degrades to a page with its headings
 * and no policy in it, which is visibly broken, rather than to a page carrying
 * a stale policy nobody can see is stale. For a legal document that is the
 * safer of the two failures.
 */
function privacy_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,
        'meta'     => [
            'title'       => 'Privacy Policy | Tech4TIME',
            'description' => 'What Tech4TIME collects, why, how long it is kept and what '
                           . 'you can ask us to do about it. No cookies, no analytics, no '
                           . 'tracking.',
            'share_title' => 'Privacy Policy | Tech4TIME',
            'breadcrumb'  => 'Privacy Policy',
'keywords'    => 'privacy policy, data protection, personal data, GDPR, Tech4TIME privacy',
            'robots'      => 'index',
            'changefreq'  => 'yearly',
            'priority'    => '0.3',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
        'hero'     => [
            'title'    => 'Privacy Policy',
            'subtitle' => 'What We Collect, Why, and What You Can Ask Us to Do About It',
        ],
        'policy'   => [
            /* THE NAME OF THE SECTION, READ OUT AND SHOWN TO NOBODY. The
               <section> is labelled by a visually-hidden <h2>, which is how a
               screen reader announces the region and the only heading on the
               page that a sighted reader never sees. It is authored text bound
               to an id, so it is a field: hard-coding it in the renderer would
               put a string on the page that nothing in the model accounts for,
               which is exactly what check_content_model.py exists to notice. */
            'label'     => 'Privacy policy',

            /* THE DATE THE POLICY TOOK EFFECT, AND NEVER A STAMP. 'updated'
               already records when this document was last published. An
               effective date is a different claim -- when the policy itself
               changed -- and a save that quietly moved it would misstate the
               one fact a reader checks first. Fixing a typo is not a new
               policy. */
            'effective' => '',
            'callout'   => [],
            'sections'  => [],
        ],
        'cta'      => [
            'status' => 'shown',
            'title'  => 'Questions about your data?',
            'text'   => '',
            'items'  => [],
        ],
    ];
}

/** Fill in everything the renderer reads, whatever the file happens to hold. */
function privacy_normalise(array $data): array
{
    $defaults = privacy_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    $data['meta'] = contract_meta_defaults($data['meta'] ?? [],
                                          $defaults['meta']);

    foreach (PRIVACY_BANDS as $band) {
        $data[$band]['status'] =
            ($data[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    foreach (PRIVACY_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    $data['policy']['callout'] = privacy_callout_defaults($data['policy']['callout'] ?? []);

    $sections = is_array($data['policy']['sections'] ?? null) ? $data['policy']['sections'] : [];
    $data['policy']['sections'] = array_map(
        'privacy_section_defaults',
        array_values(array_filter($sections, 'is_array'))
    );

    return privacy_identify($data);
}

/**
 * The summary box at the top, "The short version".
 *
 * Its own structure rather than a section, because it is not one: it has no
 * anchor, it renders inside .legal__callout, and its heading is an <h2> that
 * is deliberately NOT a direct child of .legal__body. That last point is
 * load-bearing -- assets/css/pages/legal.css zeroes the top margin of the
 * first direct-child heading, and a flattened callout would steal the match
 * from the first real section.
 */
function privacy_callout_defaults(mixed $callout): array
{
    $callout = is_array($callout) ? $callout : [];
    $callout += [
        'status' => 'shown',
        'title'  => '',
        'note'   => '',
    ];

    $items = is_array($callout['items'] ?? null) ? $callout['items'] : [];
    $callout['items'] = array_map(
        'privacy_item_defaults',
        array_values(array_filter($items, 'is_array'))
    );

    return $callout;
}

/** One headed section of the policy: an <h2> with an anchor, and its blocks. */
function privacy_section_defaults(array $row): array
{
    $row += [
        'id'      => '',
        'heading' => '',
        'status'  => 'shown',
    ];

    $blocks = is_array($row['blocks'] ?? null) ? $row['blocks'] : [];
    $row['blocks'] = array_map(
        'privacy_block_defaults',
        array_values(array_filter($blocks, 'is_array'))
    );

    return $row;
}

/**
 * One block, normalised down to the fields its kind actually uses.
 *
 * NARROWED, not merely filled. A block that was a list and is now a paragraph
 * would otherwise keep its items[] for ever -- invisible on the page, carried
 * in the document, and published every time. Keeping only what the kind reads
 * means what is stored is what is rendered, which is the same bargain
 * upload_store() makes with a picture.
 *
 * An unknown kind becomes a paragraph rather than being dropped. Dropping it
 * would lose words somebody wrote; a paragraph shows them, which is the
 * failure that can be seen and fixed.
 */
function privacy_block_defaults(array $row): array
{
    $kind = (string)($row['kind'] ?? '');
    $kind = isset(PRIVACY_BLOCK_KINDS[$kind]) ? $kind : 'paragraph';

    $block = [
        'id'     => (string)($row['id'] ?? ''),
        'kind'   => $kind,
        'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
    ];

    if (in_array($kind, PRIVACY_RICH_BLOCKS, true) || in_array($kind, PRIVACY_PLAIN_BLOCKS, true)) {
        $block['text'] = (string)($row['text'] ?? '');
    }

    if (isset(PRIVACY_ROW_BLOCKS[$kind])) {
        $rows = is_array($row['rows'] ?? null) ? $row['rows'] : [];
        $block['rows'] = array_map(
            PRIVACY_ROW_BLOCKS[$kind],
            array_values(array_filter($rows, 'is_array'))
        );
    }

    if ($kind === 'table') {
        /* The caption is read out before the table and shown to nobody. It is
           not decoration: a table with no caption is announced as "table" and
           the listener has to infer what it holds from the first cell. */
        $block['caption'] = (string)($row['caption'] ?? '');
        $columns = is_array($row['columns'] ?? null) ? array_values($row['columns']) : [];
        $block['columns'] = [
            (string)($columns[0] ?? ''),
            (string)($columns[1] ?? ''),
        ];
    }

    return $block;
}

/** One bullet, in a list block or in the callout. 'text' is sanitised HTML. */
function privacy_item_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'text'   => '',
        'status' => 'shown',
    ];
}

/**
 * One row of a two-column table: the <th scope="row"> and the <td> beside it.
 *
 * Both plain. A retention period is a fact, and the one thing a legal table
 * should not invite is a link or an emphasis that changes what the row appears
 * to promise.
 */
function privacy_cell_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'label'  => '',
        'value'  => '',
        'status' => 'shown',
    ];
}

/** One button in the closing band. The page ships with two. */
function privacy_button_defaults(array $row): array
{
    return $row + [
        'id'     => '',
        'label'  => '',
        'href'   => '',
        'style'  => 'primary',
        'status' => 'shown',
    ];
}

/**
 * Give every row an id.
 *
 * Through contract_identify_rows(), which claims every id somebody already
 * chose before it mints anything new. That matters here more than anywhere
 * else on the site: a SECTION's id is its anchor, and the one-pass version
 * lets a section added above an existing one take the existing one's fragment
 * and rename it.
 *
 * Sections are numbered across the policy, because two of them may not answer
 * to the same fragment. Blocks are numbered within their section and rows
 * within their block, as certifications numbers roles within a group -- two
 * sections may each hold a "paragraph-2" without either being renamed, because
 * neither is a link target.
 *
 * A block is named for its KIND rather than for its words. Minting an id out
 * of a sentence gives the longest field on the page the ugliest handle, and
 * then freezes it.
 */
function privacy_identify(array $data): array
{
    foreach (PRIVACY_LISTS as $band => $_filler) {
        $ids = contract_identify_rows($data[$band]['items'], PRIVACY_ID_PLACEHOLDER,
                                      static fn(array $r): string => (string)($r['label'] ?? ''));
        foreach ($ids as $i => $id) {
            $data[$band]['items'][$i]['id'] = $id;
        }
    }

    $ids = contract_identify_rows(
        $data['policy']['callout']['items'], PRIVACY_ID_PLACEHOLDER,
        static fn(array $r): string => privacy_row_name((string)($r['text'] ?? ''))
    );
    foreach ($ids as $i => $id) {
        $data['policy']['callout']['items'][$i]['id'] = $id;
    }

    $sections = contract_identify_rows(
        $data['policy']['sections'], PRIVACY_SECTION_PLACEHOLDER,
        static fn(array $r): string => (string)($r['heading'] ?? '')
    );

    foreach ($sections as $s => $id) {
        $data['policy']['sections'][$s]['id'] = $id;

        $blocks = contract_identify_rows(
            $data['policy']['sections'][$s]['blocks'], PRIVACY_ID_PLACEHOLDER,
            static fn(array $r): string => (string)($r['kind'] ?? '')
        );

        foreach ($blocks as $b => $bid) {
            $data['policy']['sections'][$s]['blocks'][$b]['id'] = $bid;

            if (!isset($data['policy']['sections'][$s]['blocks'][$b]['rows'])) {
                continue;
            }

            $rows = contract_identify_rows(
                $data['policy']['sections'][$s]['blocks'][$b]['rows'], PRIVACY_ID_PLACEHOLDER,
                static function (array $r): string {
                    $name = trim((string)($r['label'] ?? ''));
                    return $name !== '' ? $name : privacy_row_name((string)($r['text'] ?? ''));
                }
            );
            foreach ($rows as $r => $rid) {
                $data['policy']['sections'][$s]['blocks'][$b]['rows'][$r]['id'] = $rid;
            }
        }
    }

    return $data;
}

/**
 * A short name for a row that has only prose to be named after.
 *
 * Bounded, because contract_slug() will happily turn a forty-word bullet into
 * a forty-word id and then that id is frozen for good.
 */
function privacy_row_name(string $text): string
{
    $words = preg_split('/\s+/', trim(rt_plain($text))) ?: [];

    return implode(' ', array_slice($words, 0, 6));
}

/** Whether a band of the page is shown at all. */
function privacy_band_shown(array $data, string $band): bool
{
    return contract_band_shown($data, $band);
}

/** Only the rows of a list a visitor should see, wherever the list is. */
function privacy_rows_shown(mixed $rows): array
{
    return contract_rows_shown($rows);
}

/** Every section, hidden ones included. The editor lists these. */
function privacy_sections(array $data): array
{
    return is_array($data['policy']['sections'] ?? null) ? $data['policy']['sections'] : [];
}

/**
 * Re-sanitise every rich field this document carries.
 *
 * Its own function rather than a branch inside contract_sanitise(), because
 * the walk is three levels deep and the knowledge of which of six kinds hold
 * markup belongs beside the constant that says so.
 */
function privacy_sanitise(array $data): array
{
    $callout = $data['policy']['callout'] ?? [];
    $data['policy']['callout']['note'] = rt_sanitise_inline((string)($callout['note'] ?? ''));

    foreach ($callout['items'] ?? [] as $i => $row) {
        $data['policy']['callout']['items'][$i]['text'] =
            rt_sanitise_inline((string)($row['text'] ?? ''));
    }

    foreach ($data['policy']['sections'] ?? [] as $s => $section) {
        foreach ($section['blocks'] ?? [] as $b => $block) {
            $kind = (string)($block['kind'] ?? '');

            if (in_array($kind, PRIVACY_RICH_BLOCKS, true)) {
                $data['policy']['sections'][$s]['blocks'][$b]['text'] =
                    rt_sanitise_inline((string)($block['text'] ?? ''));
            }

            if ($kind !== 'list') {
                continue;
            }

            foreach ($block['rows'] ?? [] as $r => $row) {
                $data['policy']['sections'][$s]['blocks'][$b]['rows'][$r]['text'] =
                    rt_sanitise_inline((string)($row['text'] ?? ''));
            }
        }
    }

    return $data;
}

/* ------------------------------------------------ what this page repeats

   THE POLICY STATES FACTS ANOTHER DOCUMENT ALREADY MANAGES: the offices, the
   email, the telephone. They are authored here on purpose -- a controller's
   details are a legal statement, and one that changed because somebody edited
   the contact page would be a statement nobody made. But two copies of a fact
   drift, and this page has already done it: the telephone reads
   "+880 1320 571562" here and "+880 1320571562" there, and the Brussels office
   has a comma on one page and not on the other. Nothing told anyone.

   So the copies stay and the DIVERGENCE is reported. What follows answers one
   question -- does the policy still state the current value? -- and answers it
   by containment rather than by field, because these facts live inside prose
   and an address block, not in slots of their own.

   IT IS A NOTICE, NOT A REFUSAL. The editor draws it; nothing here blocks a
   save. Requiring the two to agree before either could be saved would mean
   that after an office move, whichever page you edited first could not be
   saved -- and there is no order that avoids that. */

/**
 * A string reduced to what a comparison should care about.
 *
 * Tags go, entities are decoded, a non-breaking space becomes a space, runs of
 * space collapse, commas and full stops go, and case stops mattering. That is
 * what makes this useful rather than noisy: it reports a different street and
 * stays quiet about a different comma.
 */
function privacy_fact_key(string $text): string
{
    $text = rt_plain($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\u{00a0}", ',', '.'], [' ', '', ''], $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    /* strtolower() and not mb_strtolower(): this is the only place in either
       repository that would have needed mbstring, and the host having it is
       not a reason to require it -- a check that cannot run where the tests
       run is a check that stops being run. Byte-wise folding leaves non-ASCII
       alone, and it leaves it alone identically on both sides of a comparison,
       which is all a containment test asks of it. */
    return trim(strtolower($text));
}

/**
 * Every telephone number in a string, as bare digits.
 *
 * Run by run rather than by stripping every non-digit from the whole document,
 * which would join the "12 months" of one paragraph to the "2000" of the next
 * and find numbers nobody wrote.
 */
function privacy_fact_numbers(string $text): array
{
    $text = html_entity_decode(strip_tags($text, '<a>'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    /* A LIST AND NOT A SET, and that is not a style choice. Using the digits
       as an array key makes PHP cast "8801320571562" to an int, and the
       caller's strict in_array() against a string then never matches. The
       comparison silently reported that the policy had lost the telephone it
       was in fact still printing. */
    $found = [];

    if (preg_match_all('/\+?[0-9][0-9\s\x{00a0}()\-]{6,}/u', $text, $m)) {
        foreach ($m[0] as $run) {
            $digits = preg_replace('/\D+/', '', $run) ?? '';
            if (strlen($digits) >= 7) {
                $found[] = $digits;
            }
        }
    }

    /* tel: hrefs too. strip_tags() above keeps <a> for exactly this: the
       number a visitor presses is in the attribute, not in the words. */
    if (preg_match_all('/tel:([+0-9()\s\x{00a0}\-]+)/ui', $text, $m)) {
        foreach ($m[1] as $run) {
            $digits = preg_replace('/\D+/', '', $run) ?? '';
            if (strlen($digits) >= 7) {
                $found[] = $digits;
            }
        }
    }

    return array_values(array_unique($found));
}

/** Every word of the policy a reader sees, markup included, in one string. */
function privacy_source_text(array $data): string
{
    $parts = [(string)($data['policy']['effective'] ?? '')];

    $callout = $data['policy']['callout'] ?? [];
    $parts[] = (string)($callout['title'] ?? '');
    $parts[] = (string)($callout['note'] ?? '');
    foreach ($callout['items'] ?? [] as $row) {
        $parts[] = (string)($row['text'] ?? '');
    }

    foreach ($data['policy']['sections'] ?? [] as $section) {
        $parts[] = (string)($section['heading'] ?? '');
        foreach ($section['blocks'] ?? [] as $block) {
            $parts[] = (string)($block['text'] ?? '');
            $parts[] = (string)($block['caption'] ?? '');
            foreach ($block['columns'] ?? [] as $column) {
                $parts[] = (string)$column;
            }
            foreach ($block['rows'] ?? [] as $row) {
                $parts[] = (string)($row['text'] ?? '');
                $parts[] = (string)($row['label'] ?? '');
                $parts[] = (string)($row['value'] ?? '');
            }
        }
    }

    $parts[] = (string)($data['cta']['text'] ?? '');
    foreach ($data['cta']['items'] ?? [] as $row) {
        $parts[] = (string)($row['label'] ?? '');
        $parts[] = (string)($row['href'] ?? '');
    }

    return implode(' ', $parts);
}

/**
 * Which facts the contact document manages, and whether the policy still says
 * them.
 *
 * Read off the contact document rather than listed here, so an office added
 * there is a fact checked here without anybody remembering to add it. An email
 * is the reach value with an "@" and no slash -- the LinkedIn address has
 * both; a telephone is one that is mostly digits. Neither is found by its
 * label, because a label is editable text and renaming "Phone" to "Call us"
 * must not switch a check off.
 *
 * Returns one row per fact: what it is, the current value, where it is
 * managed, and whether this policy still contains it.
 */
function privacy_shared_facts(array $privacy, array $contact): array
{
    $text    = privacy_source_text($privacy);
    $haystack = privacy_fact_key($text);
    $numbers = privacy_fact_numbers($text);
    $facts   = [];

    /* ONE ROW PER ROUTE, NOT ONE PER VALUE. The contact page lists three
       telephone numbers; the policy prints one, and that is correct -- a
       privacy policy names a way to reach the controller, not the whole
       switchboard. Reporting the other two as missing would be an alarm about
       something nobody did wrong, and an alarm nobody can silence is an alarm
       everybody learns to ignore. So a route is satisfied by any of its
       values, and what is shown is the first. */
    $emails = [];
    $phones = [];

    foreach (contract_rows_shown($contact['reach']['items'] ?? []) as $row) {
        foreach ($row['values'] ?? [] as $value) {
            $value = trim((string)$value);

            /* Classified by SHAPE, never by label. A label is editable text,
               and renaming "Phone" to "Call us" must not switch a check off.
               The slash keeps the LinkedIn address out of the emails. */
            if (str_contains($value, '@') && !str_contains($value, '/')) {
                $emails[] = $value;
                continue;
            }
            if (preg_match('/^[+0-9()\s\-]+$/', $value)
                && strlen((string)preg_replace('/\D+/', '', $value)) >= 7
            ) {
                $phones[] = $value;
            }
        }
    }

    if ($emails !== []) {
        $facts[] = [
            'label' => 'Email address',
            'value' => $emails[0],
            'found' => (bool)array_filter(
                $emails,
                static fn(string $e): bool => str_contains($haystack, privacy_fact_key($e))
            ),
        ];
    }

    if ($phones !== []) {
        $facts[] = [
            'label' => 'Telephone',
            'value' => $phones[0],
            'found' => (bool)array_filter(
                $phones,
                static fn(string $p): bool =>
                    in_array((string)preg_replace('/\D+/', '', $p), $numbers, true)
            ),
        ];
    }

    foreach (contract_rows_shown($contact['offices']['items'] ?? []) as $office) {
        $address = trim((string)($office['address'] ?? ''));
        if ($address === '') {
            continue;
        }
        $facts[] = [
            'label' => trim((string)($office['name'] ?? '')) !== ''
                     ? 'Office — ' . $office['name']
                     : 'Office',
            'value' => $address,
            'found' => str_contains($haystack, privacy_fact_key($address)),
        ];
    }

    return $facts;
}

/* ==========================================================================
   10. SEO — the site-wide half, and the map of what a page is
   ========================================================================== */

/*
   WHAT IS HERE, AND WHAT IS DELIBERATELY NOT.

   A PAGE's own metadata is not here. Its title, description, share title,
   breadcrumb, crawl directive and sitemap tuning live in that page's own
   document, in the meta band every document has -- see the page metadata block
   near the top of this file. One screen edits all of them, and the values stay
   with the page they describe. Moving them into this document was considered
   and rejected: content/ is never synced by a deploy, so a document assembled
   from the repository's committed seeds would carry SEED titles onto a host
   whose live copies hold titles edited since, and the reversion would be
   silent. Leaving them where they are removes that failure mode rather than
   managing it.

   What IS here is everything that belongs to the SITE rather than to any one
   page: the Organization graph, the default share card, the colours, the
   crawl file and the web manifest -- plus the 404 page's record, because that
   page renders no content document and never will.

   ROUTES ARE CODE. SEO_ROUTES below cannot be added to, renamed, removed or
   reordered from the editor. Adding a page is a code change, as it always was,
   and its card then appears by itself -- which is what makes it impossible to
   orphan a record or point one at a URL that does not resolve.
*/

/** Where the public site lives. The canonical of every page is built on it. */
const SEO_ORIGIN = 'https://tech4time.bd';

/**
 * Every page that is a FILE in the frontend repository: key => [route, name,
 * document].
 *
 *   route     the address, with its trailing slash. '' for a page that has no
 *             canonical URL and must not be given one -- the 404, which is
 *             served at every address that does not exist.
 *   name      what the SEO screen calls it, and the fallback breadcrumb.
 *   document  which content document holds this page's meta band. '' means
 *             this document holds it, which is true of the 404 alone.
 *
 * THE SERVICE PAGES ARE NOT HERE, and that is the point of them. A service is
 * a row of content/services.json, a seventh can be added in the editor, and
 * its metadata is its row's own meta band -- so there is no key to keep in
 * step, and renaming a slug cannot orphan a record because there is no record
 * apart from the row.
 *
 * Order is sitemap order and screen order.
 */
const SEO_ROUTES = [
    'home'           => ['/',                                  'Home',                    'home'],
    'services'       => ['/pages/services/',                   'Services',                'services'],
    'about'          => ['/pages/about/',                      'About Us',                'about'],
    'company'        => ['/pages/company-profile/',            'Company Profile',         'company'],
    'careers'        => ['/pages/careers/',                    'Careers',                 'careers'],
    'contact'        => ['/pages/contact/',                    'Contact',                 'contact'],
    'certifications' => ['/pages/resource-certifications/',    'Resource Certifications', 'certifications'],
    'branding'       => ['/pages/branding-and-advertisement/', 'Branding & Advertisement','branding'],
    'privacy'        => ['/pages/privacy-policy/',             'Privacy Policy',          'privacy'],
    'notfound'       => ['',                                   'Page not found',          ''],
];

/*
   HOW LONG A TITLE AND A DESCRIPTION MAY BE, IN ONE PLACE.

   tools/audit_pages.py enforced 65 / 50 / 165 as literals of its own while
   docs/10-development/frontend/adding-a-page.md advised 150-160, and the two
   had drifted apart with nothing to notice. The audit reads these now, the way
   tools/check_content_model.py already reads CONTRACT_BOOKKEEPING, and the
   editor refuses outside the hard range and hints outside the ideal one.
*/
const SEO_TITLE_MAX  = 65;
const SEO_DESC_MIN   = 50;
const SEO_DESC_MAX   = 165;
const SEO_DESC_IDEAL = [150, 160];

/** Free-text single-line fields of the site-wide document, by band. */
const SEO_TEXT_FIELDS = [
    'site'     => ['name', 'description', 'locale', 'lang', 'og_type', 'twitter_card',
                   'theme_light', 'theme_dark', 'share_alt'],
    'identity' => ['legal_name', 'alternate_name', 'slogan', 'description',
                   'founded', 'price_range', 'area_served'],
    'crawl'    => ['verify_google', 'verify_bing', 'analytics_id'],
    'manifest' => ['short_name', 'background', 'theme', 'display'],
    'notfound' => ['title', 'description', 'share_title'],
];

/**
 * Fields typed one entry per line into a textarea, and stored as a list.
 *
 * Same reasoning as SERVICES_LINE_FIELDS: twenty short strings as twenty
 * inputs is twenty inputs, and it is not how anybody wants to type a list.
 */
const SEO_LINE_FIELDS = [
    'identity' => ['service_types', 'knows_about'],
    'crawl'    => ['robots_extra'],
];

/**
 * What a Google measurement id may look like.
 *
 * NARROW ON PURPOSE. This string is interpolated into the src of a <script>
 * tag pointing at another origin, so it is the one editable value on this site
 * that ends up inside a URL a browser will execute. Four prefixes Google
 * actually issues, then letters, digits and dashes -- anything else is refused
 * rather than escaped, because there is no legitimate id this rejects and no
 * safe way to carry one that needs escaping into that position.
 */
const SEO_ANALYTICS_ID = '/^(G|GT|UA|AW)-[A-Z0-9][A-Z0-9-]{3,20}$/i';

/** The bands that hold a list, and the function that fills one of their rows. */
const SEO_LISTS = ['sameas' => 'seo_link_defaults', 'hours' => 'seo_hours_defaults'];

/** What a row is called before it is called anything. See seo_identify(). */
const SEO_ID_PLACEHOLDER = 'row';

/** schema.org's day names, in week order, which is the order they render. */
const SEO_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday',
                  'Friday', 'Saturday'];

/** The display modes a web manifest understands. */
const SEO_DISPLAY = ['standalone', 'fullscreen', 'minimal-ui', 'browser'];

/**
 * What a page tells a crawler, as the string that actually goes in the tag.
 *
 * Two states in, two strings out, and both are the exact bytes the seventeen
 * hand-written heads carried before this file emitted them -- which is what
 * lets the conversion be proved byte-identical. An indexed page also asks for
 * large image previews and full snippets, so a rich result may use the branded
 * share card; a noindex page asks only not to be listed, and still follows its
 * links, because a page nobody indexes is still a page whose links matter.
 */
function seo_robots_directive(string $robots): string
{
    return $robots === 'noindex'
        ? 'noindex, follow'
        : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
}

/**
 * The route keys above this route, outermost first.
 *
 * Found by prefix match over SEO_ROUTES, so a breadcrumb trail is never
 * hand-numbered and a page added to the constant joins the trails below it by
 * having been added. A service page passes its own address and gets
 * ['home', 'services'] without being in the constant at all.
 */
function seo_ancestors(string $route): array
{
    $found = [];

    foreach (SEO_ROUTES as $key => [$path, $_name, $_document]) {
        if ($path !== '' && $path !== $route && str_starts_with($route, $path)) {
            $found[$key] = strlen($path);
        }
    }

    asort($found);

    return array_keys($found);
}

/** A list typed one entry per line. Blank lines are dropped. */
function seo_lines(mixed $value): array
{
    if (is_array($value)) {
        $value = implode("\n", array_map(static fn($v) => (string)$v, $value));
    }

    $out = [];
    foreach (preg_split('/\R/', (string)(is_scalar($value) ? $value : '')) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }

    return $out;
}

/** One profile the Organization says is also it. */
function seo_link_defaults(array $row): array
{
    $row += ['id' => '', 'label' => '', 'url' => '', 'status' => 'shown'];

    $row['id']     = trim((string)$row['id']);
    $row['label']  = trim((string)$row['label']);
    $row['url']    = rt_safe_href(trim((string)$row['url']));
    $row['status'] = $row['status'] === 'hidden' ? 'hidden' : 'shown';

    return $row;
}

/**
 * One opening-hours block of the ProfessionalService graph.
 *
 * The hours on the contact page are free prose -- "Sun - Thu: 9:00 AM - 6:00
 * PM" -- because that is what reads well to a person. schema.org wants days
 * and 24-hour times, so they are authored here rather than parsed out of a
 * sentence somebody is free to reword. tools/check_shared_facts.py reports it
 * when the two stop agreeing; it never refuses a save over it.
 */
function seo_hours_defaults(array $row): array
{
    $row += ['id' => '', 'label' => '', 'days' => [], 'opens' => '',
             'closes' => '', 'status' => 'shown'];

    $row['id']    = trim((string)$row['id']);
    $row['label'] = trim((string)$row['label']);

    $days = is_array($row['days']) ? $row['days'] : [];
    $row['days'] = array_values(array_filter(
        SEO_DAYS,
        static fn(string $day): bool => in_array($day, $days, true)
    ));

    foreach (['opens', 'closes'] as $field) {
        $time = trim((string)$row[$field]);
        $row[$field] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '';
    }

    $row['status'] = $row['status'] === 'hidden' ? 'hidden' : 'shown';

    return $row;
}

/**
 * The site-wide document as it ships, and the fallback for anything missing.
 *
 * THESE ARE THE REAL VALUES, NOT PLACEHOLDERS, and that is the safety net for
 * the deploy: if content/seo.json is ever missing on the host, every page
 * still emits the correct Organization graph, the correct share card and the
 * correct colours -- exactly as contact_defaults() and company_defaults()
 * already do for their pages. Every string below was read out of the rendered
 * <head> the seventeen pages carried before this file replaced them.
 */
function seo_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,

        /* What every page says about the site rather than about itself. */
        'site' => [
            'name'         => 'Tech4TIME',
            'description'  => 'Cybersecurity, software development, cloud infrastructure and HR solutions.',
            'locale'       => 'en_US',
            'lang'         => 'en',
            'og_type'      => 'website',
            'twitter_card' => 'summary_large_image',
            'theme_light'  => '#fafafa',
            'theme_dark'   => '#0b0b0c',
            /* The default share card. A page may override it in its own meta
               band; none does today, which is why all seventeen carry these
               same bytes. */
            'share'        => [
                'src'    => '/assets/images/og/tech4time-og.png',
                'webp'   => '',
                'width'  => 1200,
                'height' => 630,
            ],
            'share_alt'    => 'Tech4TIME — Orchestrating Technology with Time',
        ],

        /* The Organization, WebSite and ProfessionalService graph. Addresses
           and telephone numbers are NOT here: they belong to the contact page
           and are read from content/contact.json at render time, which is what
           stopped this graph going stale on sixteen pages at once. */
        'identity' => [
            'legal_name'     => 'Tech4TIME',
            'alternate_name' => 'M/s. Tech4TIME',
            'slogan'         => 'Orchestrating Technology with Time',
            'description'    => 'Open-Source and enterprise-grade cybersecurity, software '
                              . 'development, cloud infrastructure and IT solutions. '
                              . 'Orchestrate, build, maintain and protect your business.',
            'founded'        => '2018-05-15',
            'price_range'    => '$$',
            'area_served'    => 'Worldwide',
            'logo'           => [
                'src'    => '/assets/images/logo/logo-light-540.png',
                'webp'   => '',
                'width'  => 540,
                'height' => 192,
            ],
            'service_types'  => [
                'Cybersecurity Services',
                'Software Development',
                'Cloud Infrastructure',
                'IT Consulting',
                'Managed Services',
                'Human Resources as a Service',
                'DevOps Services',
                'Security Operations Center',
            ],
            'knows_about'    => [
                'Cybersecurity',
                'Penetration Testing',
                'Security Operations Center',
                'Incident Response',
                'Digital Forensics',
                'Software Development',
                'DevSecOps',
                'Cloud Computing',
                'OpenStack',
                'Kubernetes',
                'IT Staffing',
                'HR as a Service',
            ],
        ],

        /* The profiles the Organization says are also it. The footer links to
           the same two in literal markup on every page; check_shared_facts.py
           reports it when they part. */
        'sameas' => [
            'items' => [
                ['id' => 'linkedin', 'label' => 'LinkedIn',
                 'url' => 'https://www.linkedin.com/company/tech4time-bd/', 'status' => 'shown'],
                ['id' => 'github', 'label' => 'GitHub',
                 'url' => 'https://github.com/M-s-Tech4TIME', 'status' => 'shown'],
            ],
        ],

        'hours' => [
            'items' => [
                ['id' => 'bangladesh', 'label' => 'Bangladesh office',
                 'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'],
                 'opens' => '09:00', 'closes' => '18:00', 'status' => 'shown'],
                ['id' => 'malaysia', 'label' => 'Malaysia office',
                 'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                 'opens' => '09:00', 'closes' => '18:00', 'status' => 'shown'],
            ],
        ],

        /* robots.txt, and the two tags that let somebody claim this site in a
           search engine's console. Empty means the tag is not emitted at all,
           which is the right default: a verification tag naming nobody is
           noise in every head on the site. */
        'crawl' => [
            'verify_google' => '',
            'verify_bing'   => '',
            /* Google Analytics. Empty means no measurement code is emitted and
               no external origin is reached at all, which is the state this
               site shipped in and the state it returns to the moment this is
               cleared. See ADR 0021. */
            'analytics_id'  => '',
            /* The form endpoint has nothing to index. This is the one rule
               robots.txt carried before it was rendered. */
            'robots_extra'  => ['/contact-handler.php'],
        ],

        /* site.webmanifest. name and description come from the site band, so
           the app name and the share card cannot disagree; the icon list stays
           in code because it names files that must exist. */
        'manifest' => [
            'short_name' => 'Tech4TIME',
            'background' => '#0b0b0c',
            'theme'      => '#0b0b0c',
            'display'    => 'standalone',
        ],

        /* The 404 page's own record. It is the one page with no content
           document to keep its meta band in, and it never will have one: there
           is nothing on it to edit but the words in this block. */
        'notfound' => [
            'title'       => 'Page Not Found | Tech4TIME',
            'description' => "The page you are looking for could not be found. Browse "
                           . "Tech4TIME's cybersecurity, software development, cloud and "
                           . "HR services, or contact our team.",
            'share_title' => 'Page Not Found',
            'breadcrumb'  => 'Page not found',
'keywords'    => '',
            'robots'      => 'noindex',
            'changefreq'  => 'yearly',
            'priority'    => '0.0',
            'share'       => ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0],
            'share_alt'   => '',
        ],
    ];
}

/** Bring the site-wide document to the current shape, whatever it arrived as. */
function seo_normalise(array $data): array
{
    $defaults = seo_defaults();

    foreach ($defaults as $key => $value) {
        if ($key === 'revision') {
            $data[$key] = max(0, (int)($data[$key] ?? 0));
            continue;
        }
        if (!is_array($value)) {
            $data[$key] = is_string($data[$key] ?? null) ? $data[$key] : $value;
            continue;
        }
        $data[$key] = is_array($data[$key] ?? null) ? $data[$key] + $value : $value;
    }

    foreach (SEO_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = is_string($data[$band][$field] ?? null)
                ? trim($data[$band][$field])
                : (string)($defaults[$band][$field] ?? '');
        }
    }

    foreach (SEO_LINE_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = seo_lines($data[$band][$field] ?? []);
        }
    }

    /* REFUSED HERE, NOT ONLY IN THE EDITOR. This value is interpolated into a
       <script src> pointing at another origin, and contract_normalise() is what
       every reader goes through -- the page, the publish endpoint, and the
       editor alike. A malformed id therefore becomes no id at all rather than
       a malformed URL, on both sides of the wire and however it got there. */
    if (preg_match(SEO_ANALYTICS_ID, $data['crawl']['analytics_id']) !== 1) {
        $data['crawl']['analytics_id'] = '';
    }

    $data['site']['share']     = contract_image_defaults($data['site']['share'] ?? []);
    $data['identity']['logo']  = contract_image_defaults($data['identity']['logo'] ?? []);

    /* The 404's record is a full meta band, the same shape every page's is, so
       the head emitter takes any of them and does not need to know which. Only
       three of its fields are editable -- see SEO_TEXT_FIELDS -- because a page
       with no address has no breadcrumb, no canonical and no sitemap row. */
    $data['notfound'] = contract_meta_defaults($data['notfound'] ?? [],
                                               $defaults['notfound']);
    $data['notfound']['robots'] = 'noindex';

    if (!in_array($data['manifest']['display'], SEO_DISPLAY, true)) {
        $data['manifest']['display'] = 'standalone';
    }

    foreach (SEO_LISTS as $band => $filler) {
        $rows = is_array($data[$band]['items'] ?? null) ? $data[$band]['items'] : [];
        $data[$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    return seo_identify($data);
}

/** Give every row an id, unique within its own list. Same contract as the rest. */
function seo_identify(array $data): array
{
    foreach (SEO_LISTS as $band => $_filler) {
        $rows = $data[$band]['items'] ?? [];
        $ids  = contract_identify_rows(
            $rows,
            SEO_ID_PLACEHOLDER,
            static fn(array $row): string => (string)($row['label'] ?? '')
        );

        foreach ($ids as $i => $id) {
            $data[$band]['items'][$i]['id'] = $id;
        }
    }

    return $data;
}

/** Only the rows of a list a visitor should see. */
function seo_shown(array $data, string $band): array
{
    return contract_rows_shown($data[$band]['items'] ?? []);
}

/** Every picture this document points at, as web paths, without duplicates. */
function seo_images(array $data): array
{
    $seen = [];

    foreach ([$data['site']['share'] ?? [], $data['identity']['logo'] ?? []] as $image) {
        foreach ([$image['src'] ?? '', $image['webp'] ?? ''] as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $seen[$path] = true;
            }
        }
    }

    foreach (contract_meta_images($data['notfound'] ?? []) as $path) {
        $seen[$path] = true;
    }

    return array_keys($seen);
}

/* ==========================================================================
   11. Chrome — the shape of the header, footer and dock
   ========================================================================== */

/*
   THE CHROME'S ICONS ARE INLINED AT RENDER TIME, NOT SCANNED FOR.

   tools/inject_icons.py finds the symbols a page needs by scanning its source
   for a literal href="#name". Once lib/body.php emits the header, footer and
   dock there is no such literal in any page's source, and the block that tool
   writes would come back empty on eleven of the seventeen pages -- every icon
   they carry comes from those three blocks.

   The answer is the one lib/services.php and lib/certifications.php already
   use for content icons: the renderer works out its own set and writes a
   SECOND sprite, marked content-sprite rather than icon-sprite so the tool's
   non-greedy match is left alone. Two <symbol> elements sharing an id overlap
   harmlessly -- tools/audit_pages.py exempts symbol ids from its duplicate-id
   check for exactly this reason -- and it audits RENDERED output, so "every
   <use> resolves to an inlined <symbol>" is already proved on the only text a
   visitor receives.

   THE ALTERNATIVE WAS MEASURED AND REJECTED, in lib/services.php, on a model
   offering seventy-six icons: naming them all up front cost +7 to +10 KB
   gzipped per page. The chrome is smaller, but the argument is the same and
   the conclusion is stronger here, because this list is inlined on ALL
   seventeen pages rather than seven. A page carries the icons it draws.

   So what follows is the model's business only -- which icons may be CHOSEN,
   and which mark stands for what. Nothing here decides what gets inlined.
*/

/**
 * The icons the dock bar's four slots may choose from.
 *
 * A fixed list rather than the whole sprite, for the reason CONTACT_ICONS is:
 * the backend offers the choice and the frontend has to be able to draw
 * whatever was chosen. A slot carrying an icon the frontend has never heard of
 * renders as an empty box.
 *
 * Deliberately short. The bar is four buttons wide at the bottom of a phone
 * screen, and the list only has to cover the kinds of place a slot can point
 * at -- the site's own pages and its services. One mark per kind, not one per
 * taste.
 *
 * Every name here must also be in ADMIN_ICONS in the backend's lib/admin.php,
 * or the editor's live preview draws an empty box for it;
 * tools/check_content_model.py says so when one is missing.
 *
 * Order is picker order, and it runs the way somebody choosing one thinks:
 * home, then the sections, then the ways to get in touch.
 */
const CHROME_BAR_ICONS = [
    'home'        => 'Home',
    'cogs'        => 'Cogs',
    'building'    => 'Building',
    'users'       => 'People',
    'briefcase'   => 'Briefcase',
    'certificate' => 'Certificate',
    'shield-alt'  => 'Shield',
    'cloud'       => 'Cloud',
    'code'        => 'Code',
    'server'      => 'Server',
    'chart-line'  => 'Chart',
    'comment-alt' => 'Speech bubble',
    'envelope'    => 'Envelope',
    'phone'       => 'Phone',
    'globe'       => 'Globe',
];

/**
 * The mark a footer contact row draws, by what kind of thing it holds.
 *
 * Not a picker, and deliberately not one. The kind already decides how the row
 * links -- tel:, mailto:, or no link at all -- so letting it decide the icon
 * too is one fewer field to fill in and one fewer way for a phone number to
 * end up beside a clock. These are the four marks the footer's <address>
 * block has carried since it was written by hand.
 */
const CHROME_CONTACT_ICONS = [
    'phone'   => 'phone',
    'email'   => 'envelope',
    'address' => 'map-marker-alt',
    'hours'   => 'clock',
];

/**
 * The mark a footer social link draws, by the host its URL points at.
 *
 * The footer's social links are derived from the SEO document's `sameas` rows,
 * which hold a URL and a label and nothing about how to draw one -- so the
 * host is what there is to go on. Matched as a suffix of the hostname, so
 * www.linkedin.com and linkedin.com are the same site, which is how anybody
 * pasting a profile URL would expect it to behave.
 *
 * Two entries, because assets/icons/sprite.svg holds exactly two brand marks.
 * Anything else gets CHROME_SOCIAL_FALLBACK, which is why a Facebook row added
 * tomorrow renders as a globe rather than as nothing at all.
 */
const CHROME_SOCIAL_ICONS = [
    'linkedin.com' => 'linkedin',
    'github.com'   => 'github',
];

/** What a social link draws when CHROME_SOCIAL_ICONS does not know its host. */
const CHROME_SOCIAL_FALLBACK = 'globe';


/* -------------------------------------------------------- the document

   THE CHROME IS THE FURNITURE AROUND EVERY PAGE: the header, the footer and
   the small-screen dock. It was literal markup in seventeen page files, kept
   in step by tools/propagate_shared.py, and that arrangement had produced
   three live defects by the time it was replaced -- a service list that said
   "Human Resource Provision" where the services document said something else,
   a seventh service that could never appear in the footer at all, and phone
   numbers that went stale for weeks because a script had to be run by hand
   before a deploy.

   A LINK POINTS AT A ROUTE, NEVER AT A URL. Every destination here is a key:
   'about', 'services', 'service:cybersecurity'. SEO_ROUTES already says routes
   are code and cannot be added, renamed or removed from the editor, and this
   follows from that -- the nav is the one component on every page of the site,
   and a nav that can point anywhere can point at a 404. Picking also means a
   service renamed in the editor renames its footer link by itself, which is
   the first of the three defects above, fixed by construction.

   THE FOOTER'S CONTACT ROWS ARE ITS OWN AND ARE NOT SYNCED. That is a
   deliberate choice and the opposite of the services column beside them. The
   contact page holds everything, in full; the footer holds the part worth
   putting in a footer, in whatever order and wording suits it, with rows that
   can be hidden without hiding anything on the contact page. What keeps the
   two honest is a NOTICE rather than a rule -- the editor draws it and nothing
   here refuses a save. The privacy policy struck the same bargain for the same
   reason -- see "THE POLICY STATES FACTS ANOTHER DOCUMENT ALREADY MANAGES" in
   section 9 -- and it is the pattern tools/check_shared_facts.py reports on.

   WHAT IS DELIBERATELY NOT STORED HERE:

     the services column   derived from content/services.json, so a seventh
                           service appears by itself and a hidden one goes
     the social links      derived from the SEO document's sameas rows, so a
                           URL is changed in one place and the footer can never
                           disagree with the structured data
     the four columns      the grid is code. Their HEADINGS and CONTENTS are
                           here; their number and order are not, because a
                           footer that can be given a fifth column is a footer
                           that can be broken at a width nobody tested
     the dock's circuit    decoration with nothing to say, and no content
*/

/** The three parts of the chrome, in the order they render. */
const CHROME_PARTS = ['header', 'footer', 'dock'];

/**
 * Free-text single-line fields, by the path that holds them.
 *
 * A DOTTED PATH, unlike every other document here, because the chrome is two
 * levels deep where the others are one: a heading belongs to a column, which
 * belongs to the footer. Every path is exactly one or two segments, which is
 * what chrome_normalise() relies on.
 */
const CHROME_TEXT_FIELDS = [
    'header'            => ['brand_label'],
    'footer'            => ['brand_label', 'tagline', 'description'],
    'footer.links'      => ['heading'],
    'footer.services'   => ['heading', 'index_label'],
    'footer.contact'    => ['heading'],
    'footer.copyright'  => ['name', 'rights'],
    'dock'              => ['menu_label'],
];

/**
 * The lists, and the function that fills one of their rows.
 *
 * Named once so chrome_normalise() and chrome_identify() drive themselves off
 * it, exactly as ABOUT_LISTS and COMPANY_LISTS do. A list added to the chrome
 * is normalised and given ids by being added here, not by somebody also
 * remembering two lines further down.
 */
const CHROME_LISTS = [
    'header.nav'     => 'chrome_link_defaults',
    'footer.links'   => 'chrome_link_defaults',
    'footer.legal'   => 'chrome_link_defaults',
    'footer.contact' => 'chrome_contact_defaults',
    'dock.panel'     => 'chrome_panel_defaults',
    'dock.bar'       => 'chrome_bar_defaults',
];

/* What a row is called before it is called anything. Deliberately the same
   value as the other documents' and deliberately a separate constant: each
   document owns its own id vocabulary. See chrome_identify(). */
const CHROME_ID_PLACEHOLDER = 'row';

/**
 * What a footer contact row holds, which decides how it links and what it draws.
 *
 * 'address' and 'hours' deliberately make no link -- a street and an opening
 * time are facts, not destinations. Same reasoning as CONTACT_REACH_TYPES'
 * 'text' entry, and the marks are in CHROME_CONTACT_ICONS.
 */
const CHROME_CONTACT_KINDS = [
    'phone'   => 'Phone number',
    'email'   => 'Email address',
    'address' => 'Address',
    'hours'   => 'Opening hours',
];

/**
 * How many keys the dock's bar has, and it is not a preference.
 *
 * The bar is a fixed grid at the bottom of a phone screen: four destinations
 * and the menu button that opens the panel. A fifth would not wrap, it would
 * shrink the other four below a thumb's width. tools/test_nav.py asserts this
 * number, and chrome_normalise() pads or truncates to it rather than trusting
 * whatever arrived -- a document is a file as often as it is a form.
 */
const CHROME_BAR_SLOTS = 4;

/** How a bar key is drawn. One of the four may be given the filled disc. */
const CHROME_BAR_EMPHASIS = [
    'plain' => 'Plain',
    'disc'  => 'Filled disc',
];

/**
 * The chrome as it ships, and the fallback for anything missing from the file.
 *
 * EXTRACTED FROM THE MARKUP, NOT TYPED. Every value below was read out of
 * tools/templates/header.html, footer.html and dock.html by a script, so a
 * host with no content/chrome.json renders the site exactly as it rendered
 * before any of this existed. That is the whole safety property of the
 * conversion, and it is proved by rendering all seventeen pages before and
 * after and comparing.
 *
 * A LABEL LEFT EMPTY MEANS "whatever that page calls itself". Every one of the
 * nav, quick-link, legal and panel rows below ships with an empty label,
 * because every one of them already agreed with its route's own name --
 * checked, not assumed. So renaming a page in the SEO screen renames it in the
 * header, the footer and the dock at once, and the operator has to type a
 * label only where they want the chrome to disagree on purpose.
 */
function chrome_defaults(): array
{
    return [
        'updated'  => '',
        'revision' => 0,

        'header' => [
            /* The accessible name of the logo link. It is not the alt text:
               the picture says "Tech4TIME" and the link says where it goes. */
            'brand_label' => 'Tech4TIME — home',
            'logo' => [
                'alt'    => 'Tech4TIME',
                /* Three widths behind one displayed size. The header shows the
                   lockup at 140px on a phone and 180px above 48em. */
                'sizes'  => '(max-width: 48em) 140px, 180px',
                'width'  => 360,
                'height' => 128,
                'light'  => [
                    'src'    => '/assets/images/logo/logo-light-360.png',
                    'srcset' => '/assets/images/logo/logo-light-180.png 180w, /assets/images/logo/logo-light-360.png 360w, /assets/images/logo/logo-light-540.png 540w',
                    'webp'   => '/assets/images/logo/logo-light-180.webp 180w, /assets/images/logo/logo-light-360.webp 360w, /assets/images/logo/logo-light-540.webp 540w',
                ],
                'dark'   => [
                    'src'    => '/assets/images/logo/logo-dark-360.png',
                    'srcset' => '/assets/images/logo/logo-dark-180.png 180w, /assets/images/logo/logo-dark-360.png 360w, /assets/images/logo/logo-dark-540.png 540w',
                    'webp'   => '/assets/images/logo/logo-dark-180.webp 180w, /assets/images/logo/logo-dark-360.webp 360w, /assets/images/logo/logo-dark-540.webp 540w',
                ],
            ],
            'nav' => ['items' => [
                ['id' => 'home',     'target' => 'home',     'label' => '', 'status' => 'shown'],
                ['id' => 'about',    'target' => 'about',    'label' => '', 'status' => 'shown'],
                ['id' => 'services', 'target' => 'services', 'label' => '', 'status' => 'shown'],
                ['id' => 'company',  'target' => 'company',  'label' => '', 'status' => 'shown'],
                ['id' => 'careers',  'target' => 'careers',  'label' => '', 'status' => 'shown'],
                ['id' => 'contact',  'target' => 'contact',  'label' => '', 'status' => 'shown'],
            ]],
        ],

        'footer' => [
            'brand_label' => 'Tech4TIME — home',
            'logo' => [
                'alt'    => 'Tech4TIME',
                /* No sizes and no srcset: the footer draws one width and
                   always has. An empty srcset is not a missing value, it says
                   "emit no srcset attribute". */
                'sizes'  => '',
                'width'  => 360,
                'height' => 128,
                'light'  => [
                    'src'    => '/assets/images/logo/logo-light-360.png',
                    'srcset' => '',
                    'webp'   => '/assets/images/logo/logo-light-360.webp',
                ],
                'dark'   => [
                    'src'    => '/assets/images/logo/logo-dark-360.png',
                    'srcset' => '',
                    'webp'   => '/assets/images/logo/logo-dark-360.webp',
                ],
            ],
            'tagline'     => 'Orchestrating Technology with Time',
            'description' => 'Open-Source & Enterprise-grade cybersecurity, software '
                           . 'development, and IT solutions. Orchestrate, build, maintain '
                           . 'and protect your business with our profound solutions.',

            'links' => [
                'heading' => 'Quick Links',
                'items'   => [
                    ['id' => 'home',           'target' => 'home',           'label' => '', 'status' => 'shown'],
                    ['id' => 'about',          'target' => 'about',          'label' => '', 'status' => 'shown'],
                    ['id' => 'company',        'target' => 'company',        'label' => '', 'status' => 'shown'],
                    ['id' => 'careers',        'target' => 'careers',        'label' => '', 'status' => 'shown'],
                    ['id' => 'certifications', 'target' => 'certifications', 'label' => '', 'status' => 'shown'],
                    ['id' => 'branding',       'target' => 'branding',       'label' => '', 'status' => 'shown'],
                    ['id' => 'contact',        'target' => 'contact',        'label' => '', 'status' => 'shown'],
                ],
            ],

            /* No items. The rows ARE content/services.json, read at render
               time, which is the whole point of this column. index_label names
               the row above them that goes to the index itself -- "All
               Services" rather than the index's own name, because it is
               introducing the list under it rather than naming a page. */
            'services' => [
                'heading'     => 'Our Services',
                'index_label' => 'All Services',
            ],

            /* THE FOOTER'S OWN CONTACT DETAILS. Not read from
               content/contact.json and not kept in step with it -- see the
               note at the top of this section. Consecutive rows sharing a kind
               render inside ONE .contact-item, under one icon, which is what
               the CSS's `.contact-item__label ~ .contact-item__label` rule is
               written against. */
            'contact' => [
                'heading' => 'Contact Info',
                'items'   => [
                    ['id' => 'phone-bangladesh', 'kind' => 'phone', 'label' => 'Bangladesh',
                     'lines' => ['+880 1320571562', '+880 1881873463', '+880 1847313835'],
                     'note' => 'Sunday – Thursday', 'status' => 'shown'],
                    ['id' => 'phone-malaysia', 'kind' => 'phone', 'label' => 'Malaysia',
                     'lines' => ['+60 198527096'],
                     'note' => 'Monday – Friday', 'status' => 'shown'],
                    ['id' => 'phone-belgium', 'kind' => 'phone', 'label' => 'Belgium',
                     'lines' => ['+32 2 555 75 25', '+32 2 999 55 75'],
                     'note' => '', 'status' => 'shown'],
                    ['id' => 'email', 'kind' => 'email', 'label' => '',
                     'lines' => ['info@tech4time.bd'],
                     'note' => '', 'status' => 'shown'],
                    ['id' => 'address-bangladesh', 'kind' => 'address', 'label' => 'Bangladesh',
                     'lines' => ['278/3, Manikdi, Dhaka - 1206'],
                     'note' => '', 'status' => 'shown'],
                    ['id' => 'address-malaysia', 'kind' => 'address', 'label' => 'Malaysia',
                     'lines' => ['68100 Batu Caves, Selangor, Malaysia'],
                     'note' => '', 'status' => 'shown'],
                    ['id' => 'address-belgium', 'kind' => 'address', 'label' => 'Belgium',
                     'lines' => ['367, Avenue Louise, Brussels, Belgium'],
                     'note' => '', 'status' => 'shown'],
                    ['id' => 'hours-bangladesh-office', 'kind' => 'hours', 'label' => 'Bangladesh Office',
                     'lines' => ['Sun – Thu: 9:00 AM – 6:00 PM'],
                     'note' => '', 'status' => 'shown'],
                    ['id' => 'hours-malaysia-office', 'kind' => 'hours', 'label' => 'Malaysia Office',
                     'lines' => ['Mon – Fri: 9:00 AM – 6:00 PM'],
                     'note' => '', 'status' => 'shown'],
                ],
            ],

            'legal' => ['items' => [
                ['id' => 'privacy', 'target' => 'privacy', 'label' => '', 'status' => 'shown'],
            ]],

            /* The year is not here. It is stamped by the page as it renders,
               and refreshCopyrightYear() in assets/js/main.js corrects it in a
               tab left open across midnight on 31 December. */
            'copyright' => [
                'name'   => 'Tech4TIME',
                'rights' => 'All rights reserved.',
            ],
        ],

        'dock' => [
            'panel' => ['items' => [
                ['id' => 'home', 'target' => 'home', 'label' => '',
                 'description' => 'Start here', 'status' => 'shown'],
                ['id' => 'about', 'target' => 'about', 'label' => '',
                 'description' => 'Who we are and how we work', 'status' => 'shown'],
                ['id' => 'services', 'target' => 'services', 'label' => '',
                 'description' => 'Security, development, cloud and people', 'status' => 'shown'],
                ['id' => 'company', 'target' => 'company', 'label' => '',
                 'description' => 'Milestones, clients and the technology we use', 'status' => 'shown'],
                ['id' => 'careers', 'target' => 'careers', 'label' => '',
                 'description' => 'Open roles, and speculative applications', 'status' => 'shown'],
                ['id' => 'contact', 'target' => 'contact', 'label' => '',
                 'description' => 'Reach us any way you prefer', 'status' => 'shown'],
            ]],

            /* Exactly CHROME_BAR_SLOTS of these, and their labels are typed
               rather than left to the route: "Profile" and "Contact" are what
               fits under a 44px key, where "Company Profile" and "Contact Us"
               are what fits in a nav. */
            'bar' => ['items' => [
                ['id' => 'home', 'target' => 'home', 'label' => 'Home',
                 'icon' => 'home', 'emphasis' => 'plain'],
                ['id' => 'services', 'target' => 'services', 'label' => 'Services',
                 'icon' => 'cogs', 'emphasis' => 'plain'],
                ['id' => 'contact', 'target' => 'contact', 'label' => 'Contact',
                 'icon' => 'comment-alt', 'emphasis' => 'disc'],
                ['id' => 'company', 'target' => 'company', 'label' => 'Profile',
                 'icon' => 'building', 'emphasis' => 'plain'],
            ]],

            'menu_label' => 'Menu',
        ],
    ];
}

/* ----------------------------------------------------------- the rows */

/**
 * One link: where it goes, what to call it, and whether anybody sees it.
 *
 * 'target' is a key from chrome_targets(), never a URL. It is not validated
 * here -- a key whose page has since been removed still round-trips, so the
 * editor can show it and the operator can fix it, where dropping it would lose
 * the row silently. The renderer skips what it cannot resolve.
 */
function chrome_link_defaults(array $row): array
{
    $row += ['id' => '', 'target' => '', 'label' => '', 'status' => 'shown'];

    $row['id']     = trim((string)$row['id']);
    $row['target'] = trim((string)$row['target']);
    $row['label']  = trim((string)$row['label']);
    $row['status'] = $row['status'] === 'hidden' ? 'hidden' : 'shown';

    return ['id' => $row['id'], 'target' => $row['target'],
            'label' => $row['label'], 'status' => $row['status']];
}

/**
 * One footer contact row: a labelled group under one of the four marks.
 *
 * 'lines' is a list because a group is usually more than one thing -- three
 * numbers for Dhaka, two for Brussels -- and the renderer joins them with
 * <br>. An empty line is dropped rather than rendered: a stray blank in the
 * middle of an address is a gap nobody typed on purpose.
 *
 * 'note' is the quiet second line under a group, "Sunday – Thursday".
 */
function chrome_contact_defaults(array $row): array
{
    $row += ['id' => '', 'kind' => 'phone', 'label' => '', 'lines' => [],
             'note' => '', 'status' => 'shown'];

    $lines = is_array($row['lines']) ? $row['lines'] : [];
    $lines = array_values(array_filter(
        array_map(static fn($l): string => trim((string)$l), $lines),
        static fn(string $l): bool => $l !== ''
    ));

    return [
        'id'     => trim((string)$row['id']),
        'kind'   => isset(CHROME_CONTACT_KINDS[$row['kind']]) ? (string)$row['kind'] : 'phone',
        'label'  => trim((string)$row['label']),
        'lines'  => $lines,
        'note'   => trim((string)$row['note']),
        'status' => $row['status'] === 'hidden' ? 'hidden' : 'shown',
    ];
}

/** One row of the dock's panel: a link with a line of explanation under it. */
function chrome_panel_defaults(array $row): array
{
    $row += ['id' => '', 'target' => '', 'label' => '', 'description' => '',
             'status' => 'shown'];

    return [
        'id'          => trim((string)$row['id']),
        'target'      => trim((string)$row['target']),
        'label'       => trim((string)$row['label']),
        'description' => trim((string)$row['description']),
        'status'      => $row['status'] === 'hidden' ? 'hidden' : 'shown',
    ];
}

/**
 * One key of the dock's bar.
 *
 * No status. There are exactly CHROME_BAR_SLOTS keys and a hidden one would
 * leave a hole in a fixed grid; a slot that is not wanted is pointed somewhere
 * else instead.
 *
 * The label is NOT optional here, unlike every other row: it is what fits
 * under a 44px key, and falling back to a route called "Branding &
 * Advertisement" would overflow the bar rather than rename it.
 */
function chrome_bar_defaults(array $row): array
{
    $row += ['id' => '', 'target' => '', 'label' => '', 'icon' => '',
             'emphasis' => 'plain'];

    $icon = trim((string)$row['icon']);

    return [
        'id'       => trim((string)$row['id']),
        'target'   => trim((string)$row['target']),
        'label'    => trim((string)$row['label']),
        'icon'     => isset(CHROME_BAR_ICONS[$icon]) ? $icon : '',
        'emphasis' => isset(CHROME_BAR_EMPHASIS[$row['emphasis']])
                      ? (string)$row['emphasis'] : 'plain',
    ];
}

/**
 * One logo lockup: the pair of pictures, and the box they are drawn in.
 *
 * NOT contract_image_defaults(). That one validates 'webp' as a single path,
 * and the header's is a three-width srcset list -- so it would empty the field
 * and the header would lose its WebP on the next normalise. The paths are
 * checked here the same way, one entry at a time.
 *
 * width and height are the intrinsic size of the file, not the displayed one.
 * They are what lets the browser reserve the box before the bytes arrive, and
 * this site's Cumulative Layout Shift is zero rather than nearly zero.
 */
function chrome_logo_defaults(mixed $logo, array $fallback): array
{
    $logo = is_array($logo) ? $logo : [];
    $logo += $fallback;

    $out = [
        'alt'    => trim((string)$logo['alt']),
        'sizes'  => trim((string)$logo['sizes']),
        'width'  => max(0, (int)$logo['width']),
        'height' => max(0, (int)$logo['height']),
    ];

    foreach (['light', 'dark'] as $mode) {
        $image = is_array($logo[$mode] ?? null) ? $logo[$mode] : [];
        $image += ['src' => '', 'srcset' => '', 'webp' => ''];

        $out[$mode] = [
            'src'    => contract_safe_image_path((string)$image['src']),
            'srcset' => chrome_srcset((string)$image['srcset']),
            'webp'   => chrome_srcset((string)$image['webp']),
        ];
    }

    return $out;
}

/**
 * A srcset list with every path checked, or as much of one as survives.
 *
 * "url 180w, url 360w" and a bare "url" are both valid srcset syntax, which is
 * why one field carries the header's three widths and the footer's single
 * file. An entry whose path is not under CONTRACT_IMAGE_ROOTS is dropped
 * rather than escaped -- this ends up inside an attribute the browser fetches
 * from, and there is no legitimate logo this rejects.
 */
function chrome_srcset(string $value): string
{
    $out = [];

    foreach (explode(',', $value) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        $bits       = preg_split('/\s+/', $entry) ?: [];
        $path       = contract_safe_image_path((string)array_shift($bits));
        $descriptor = trim(implode(' ', $bits));

        if ($path === '' || !preg_match('/^(\d+(\.\d+)?[wx])?$/', $descriptor)) {
            continue;
        }

        $out[] = $descriptor === '' ? $path : $path . ' ' . $descriptor;
    }

    return implode(', ', $out);
}

/* ------------------------------------------------------ normalising */

/**
 * Bring the chrome to the current shape, whatever it arrived as.
 *
 * Explicit rather than a recursive merge. The document is two levels deep and
 * one of those levels holds LISTS, which must not be filled from the defaults
 * entry by entry -- a six-row nav cut to four would silently grow its last two
 * rows back. So the scalars are filled from CHROME_TEXT_FIELDS, the pictures
 * from chrome_logo_defaults(), and every list is rebuilt from what arrived.
 */
function chrome_normalise(array $data): array
{
    $defaults = chrome_defaults();

    $data['updated']  = is_string($data['updated'] ?? null) ? $data['updated'] : '';
    $data['revision'] = max(0, (int)($data['revision'] ?? 0));

    foreach (CHROME_PARTS as $part) {
        $data[$part] = is_array($data[$part] ?? null) ? $data[$part] : [];
    }

    foreach (CHROME_TEXT_FIELDS as $path => $fields) {
        $bits = explode('.', $path);
        $part = $bits[0];
        $band = $bits[1] ?? '';

        foreach ($fields as $field) {
            if ($band === '') {
                $was = $data[$part][$field] ?? null;
                $data[$part][$field] = is_string($was) ? trim($was)
                                                       : $defaults[$part][$field];
                continue;
            }
            $data[$part][$band] = is_array($data[$part][$band] ?? null)
                                  ? $data[$part][$band] : [];
            $was = $data[$part][$band][$field] ?? null;
            $data[$part][$band][$field] = is_string($was) ? trim($was)
                                          : $defaults[$part][$band][$field];
        }
    }

    foreach (['header', 'footer'] as $part) {
        $data[$part]['logo'] = chrome_logo_defaults($data[$part]['logo'] ?? null,
                                                    $defaults[$part]['logo']);
    }

    foreach (CHROME_LISTS as $path => $filler) {
        [$part, $band] = explode('.', $path);

        $rows = is_array($data[$part][$band]['items'] ?? null)
                ? $data[$part][$band]['items'] : [];
        $data[$part][$band]['items'] = array_map(
            $filler,
            array_values(array_filter($rows, 'is_array'))
        );
    }

    /* The bar is a fixed grid, so it is padded and truncated rather than
       trusted. A short document gets the shipped keys back; a long one loses
       the overflow, which is the only outcome that keeps the bar usable. */
    $bar = $data['dock']['bar']['items'];
    for ($i = count($bar); $i < CHROME_BAR_SLOTS; $i++) {
        $bar[$i] = $defaults['dock']['bar']['items'][$i]
                   ?? chrome_bar_defaults([]);
    }
    $data['dock']['bar']['items'] = array_slice($bar, 0, CHROME_BAR_SLOTS);

    /* The services column stores no rows and must not be able to grow any:
       they are content/services.json, read as the footer renders. */
    unset($data['footer']['services']['items']);

    return chrome_identify($data);
}

/**
 * Give every row an id, unique within its own list.
 *
 * Through contract_identify_rows(), so everything already named claims its id
 * before anything provisional is minted around it. A contact row is named
 * after its label AND its kind, because three offices contribute a row to each
 * of three kinds and "bangladesh" cannot be all of them -- the phone row is
 * 'phone-bangladesh' and the address row is 'address-bangladesh'.
 */
function chrome_identify(array $data): array
{
    foreach (CHROME_LISTS as $path => $_filler) {
        [$part, $band] = explode('.', $path);

        $ids = contract_identify_rows(
            $data[$part][$band]['items'],
            CHROME_ID_PLACEHOLDER,
            static fn(array $row): string => chrome_row_name($band, $row)
        );

        foreach ($ids as $i => $id) {
            $data[$part][$band]['items'][$i]['id'] = $id;
        }
    }

    return $data;
}

/** What a row's id is minted from, whichever list it is in. */
function chrome_row_name(string $band, array $row): string
{
    if ($band === 'contact') {
        $label = trim((string)($row['label'] ?? ''));
        $kind  = trim((string)($row['kind'] ?? ''));
        return $label === '' ? $kind : $kind . ' ' . $label;
    }

    /* A link is named after its destination and not its label, because the
       label is usually empty on purpose -- it means "whatever that page calls
       itself" -- and a list of rows all named '' would be row, row-2, row-3. */
    $target = trim((string)($row['target'] ?? ''));

    return $target !== '' ? str_replace(':', '-', $target)
                          : trim((string)($row['label'] ?? ''));
}

/* --------------------------------------------------------- queries */

/**
 * Every destination a chrome link may point at, in nav order.
 *
 * The nine routes that resolve to an address, then every service -- so a
 * service added this morning is in the picker this afternoon, and the footer's
 * services column is built from the same enumeration that offers it.
 *
 * TAKES THE SERVICES DOCUMENT RATHER THAN LOADING IT. This file has no reader:
 * services_load() lives in each repository's own lib/services.php and they are
 * not the same function. Handing it in is what keeps the contract pure, and it
 * is what services_all() beside it already does.
 *
 * The 404 is not here. Its route is '' because it is served at every address
 * that does not exist, so there is nothing to link to.
 *
 *   route     the address, with its trailing slash
 *   name      what the page calls itself, as the constant has it
 *   hidden    a service switched off in the editor: offered, but not rendered
 *   service   true when this is a row of a document rather than a file
 */
function chrome_targets(array $services): array
{
    $out = [];

    foreach (SEO_ROUTES as $key => [$route, $name, $_document]) {
        if ($route === '') {
            continue;
        }

        $out[$key] = ['route' => $route, 'name' => $name,
                      'hidden' => false, 'service' => false];

        /* The services sit directly under their index, which is where they sit
           on the site, in the sitemap and on the SEO screen. */
        if ($key !== 'services') {
            continue;
        }

        foreach (services_all($services) as $service) {
            $id   = trim((string)($service['id'] ?? ''));
            $slug = trim((string)($service['slug'] ?? ''));

            if ($id === '' || $slug === '') {
                continue;
            }

            $out['service:' . $id] = [
                'route'   => '/pages/services/' . $slug . '/',
                'name'    => trim((string)($service['name'] ?? '')) ?: $slug,
                'hidden'  => ($service['status'] ?? 'shown') === 'hidden',
                'service' => true,
            ];
        }
    }

    return $out;
}

/** Only the rows of a chrome list a visitor should see. */
function chrome_rows_shown(mixed $rows): array
{
    return contract_rows_shown($rows);}

/**
 * Every picture the chrome points at, as web paths, without duplicates.
 *
 * Both logos, both modes, and every entry of every srcset -- the 540px file is
 * only ever named inside a srcset, and a sweep that counted the <img src>
 * alone would offer to delete it.
 */
function chrome_images(array $data): array
{
    $seen = [];

    foreach (['header', 'footer'] as $part) {
        foreach (['light', 'dark'] as $mode) {
            $image = $data[$part]['logo'][$mode] ?? [];

            foreach ([$image['src'] ?? ''] as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $seen[$path] = true;
                }
            }

            foreach ([$image['srcset'] ?? '', $image['webp'] ?? ''] as $list) {
                foreach (explode(',', (string)$list) as $entry) {
                    $path = trim((string)(preg_split('/\s+/', trim($entry))[0] ?? ''));
                    if ($path !== '') {
                        $seen[$path] = true;
                    }
                }
            }
        }
    }

    return array_keys($seen);
}

/* ------------------------------------------------------- the drift notice

   THE FOOTER'S CONTACT ROWS ARE A SECOND COPY, ON PURPOSE. That is settled
   above and in ADR 0023: the contact page holds every detail in full and the
   footer holds the part worth putting in a footer, worded and ordered to suit
   it, with rows that can be hidden without hiding anything on the contact
   page.

   What a deliberate second copy still needs is somewhere the difference is
   VISIBLE. This is that. It reports; it never refuses, and nothing that calls
   it may make it refuse -- which is the same bargain privacy_shared_facts()
   struck for the same reason, and for a second reason worth stating: after an
   office moves, whichever of the two pages you edit first would be unsavable
   if agreement were a rule.
   -------------------------------------------------------------------------- */

/**
 * Every footer contact value the contact page does not carry.
 *
 * Returns [['kind' => 'phone'|'email'|'address', 'label' => …, 'value' => …], …]
 * in the footer's own row order. Empty means the two agree.
 *
 * ONE DIRECTION, AND THAT IS THE WHOLE DESIGN. What this exists to catch is a
 * footer value that has gone STALE -- the Brussels telephone numbers were wrong
 * for weeks under the old arrangement, and nothing said so. The other
 * direction, "the contact page has something the footer does not", is not
 * drift: it is what a footer IS. The contact page holds three offices in full
 * and the footer holds the part worth putting in a footer, so reporting the
 * remainder would fire on every correctly-short footer there has ever been,
 * and an alarm nobody can silence is an alarm everybody learns to ignore.
 *
 * An operator who wants the rest can press Copy from the Contact page.
 *
 * COMPARED BY VALUE, NEVER BY LABEL OR BY ROW. A footer row called "Head
 * office" and a contact office called "Bangladesh" are the same office if they
 * carry the same address, and renaming either is not drift. Telephone numbers
 * are compared as bare digits -- "+880 1320571562" and "+880 1320 571562" are
 * one number -- and everything else through privacy_fact_key(), which folds
 * case, collapses whitespace and drops commas and full stops. That is what
 * makes this useful rather than noisy: it reports a different street and stays
 * quiet about a different comma.
 *
 * A HIDDEN FOOTER ROW IS NOT COMPARED. Hiding one is how an operator says "not
 * in the footer", and it cannot be stale if nobody can read it.
 *
 * HOURS ARE NOT COMPARED EITHER, deliberately. The footer writes "Sun - Thu:
 * 9:00 AM - 6:00 PM" as one line of prose, the contact page writes the same
 * words in its own field, and the SEO screen holds the machine-readable
 * version in days and 24-hour times. Three spellings of one fact would report
 * each other forever; tools/check_shared_facts.py is where that pair is looked
 * at, and it looks at the two that are meant to be the same words.
 */
function chrome_contact_drift(array $chrome, array $contact): array
{
    $theirs = ['phone' => [], 'email' => [], 'address' => []];

    /* The contact page's reach rows are classified by SHAPE rather than by
       their label, exactly as privacy_shared_facts() classifies them, so
       renaming "Phone" to "Call us" does not switch a comparison off. */
    foreach (contract_rows_shown($contact['reach']['items'] ?? []) as $row) {
        foreach ($row['values'] ?? [] as $value) {
            $value = trim((string)$value);
            $kind  = '';

            if (str_contains($value, '@') && !str_contains($value, '/')) {
                $kind = 'email';
            } elseif (preg_match('/^[+0-9()\s\-]+$/', $value)
                      && strlen((string)preg_replace('/\D+/', '', $value)) >= 7) {
                $kind = 'phone';
            }

            $key = $kind === '' ? '' : chrome_drift_key($kind, $value);
            if ($key !== '') {
                $theirs[$kind][$key] = true;
            }
        }
    }

    foreach (contract_rows_shown($contact['offices']['items'] ?? []) as $office) {
        $key = chrome_drift_key('address', (string)($office['address'] ?? ''));
        if ($key !== '') {
            $theirs['address'][$key] = true;
        }

        foreach ($office['phones'] ?? [] as $phone) {
            $key = chrome_drift_key('phone', (string)$phone);
            if ($key !== '') {
                $theirs['phone'][$key] = true;
            }
        }
    }

    $out = [];

    foreach (contract_rows_shown($chrome['footer']['contact']['items'] ?? []) as $row) {
        $kind = (string)($row['kind'] ?? '');
        if (!isset($theirs[$kind])) {
            continue;
        }

        foreach ($row['lines'] ?? [] as $line) {
            $key = chrome_drift_key($kind, (string)$line);
            if ($key !== '' && !isset($theirs[$kind][$key])) {
                $out[] = ['kind'  => $kind,
                          'label' => trim((string)($row['label'] ?? '')),
                          'value' => trim((string)$line)];
            }
        }
    }

    return $out;
}

/** One contact value as the two sides can be compared on. '' means "skip". */
function chrome_drift_key(string $kind, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    /* A telephone number is its digits. contact_tel() is the rule the tel:
       href uses, so the comparison and the link cannot disagree about what
       two spellings of one number are. */
    return $kind === 'phone'
        ? contact_tel($value)
        : privacy_fact_key($value);
}

/* ==========================================================================
   12. Revisions
   ========================================================================== */

/**
 * The revision a save should carry: one past whatever is on file.
 *
 * Monotonic per document, and the only thing standing between the live site
 * and a reordered or replayed publish. The receiving side accepts a payload
 * strictly greater than what it holds and refuses everything else, so a retry
 * of an older save cannot roll the public page backwards — which is the
 * failure a signature alone does not prevent, because a replayed request is
 * signed perfectly well.
 *
 * A count, not a clock. Two saves inside the same second are two revisions;
 * two servers with drifting clocks are not a consideration because only one
 * side ever mints these.
 */
function contract_next_revision(array $data): int
{
    return max(0, (int)($data['revision'] ?? 0)) + 1;
}

/* ==========================================================================
   13. Normalising and re-sanitising on receipt
   ========================================================================== */

/**
 * Bring a document of any kind to the current shape.
 *
 * THIS IS A MATCH AND NOT A TERNARY, DELIBERATELY. What stood here was
 *
 *     $document === 'careers' ? careers_normalise(...) : contact_normalise(...)
 *
 * written three times over in the frontend's api/publish.php, and it had a
 * default: anything that was not careers was treated as contact. A third
 * document would have passed every check the endpoint makes -- signature,
 * timestamp, revision, contract version -- and then overwritten the contact
 * page with itself. The refusal has to be the default, not the fallthrough.
 *
 * @throws RuntimeException on a name CONTRACT_DOCUMENTS does not list.
 */
function contract_normalise(string $document, array $data): array
{
    return match ($document) {
        'careers'  => careers_normalise($data),
        'contact'  => contact_normalise($data),
        'company'  => company_normalise($data),
        'about'    => about_normalise($data),
        'home'     => home_normalise($data),
        'services' => services_normalise($data),
        'certifications' => certifications_normalise($data),
        'branding' => branding_normalise($data),
        'privacy'  => privacy_normalise($data),
        'seo'      => seo_normalise($data),
        'chrome'   => chrome_normalise($data),
        default    => throw new RuntimeException('Unknown document: ' . $document),
    };
}

/**
 * Every picture a document points at, whichever document it is.
 *
 * WHY THIS EXISTS, AND WHY IT IS NOT OPTIONAL. public/uploads/ is ONE
 * directory shared by every editor, but each editor used to ask
 * upload_unused() with only its own document's pictures — so the about screen
 * counted the home page's uploads as "not used by any row" and its sweep
 * button offered to delete them. Three editors, each able to delete the other
 * two's artwork, and nothing anywhere said so.
 *
 * The set of pictures in use is a property of the SITE, not of one screen. So
 * it is asked here, over every document, and a document with no pictures
 * answers with none rather than being left out — which is what keeps a new
 * document from being a new way to lose files.
 */
function contract_images(string $document, array $data): array
{
    /* EVERY DOCUMENT CARRIES ARTWORK NOW. The meta band gained a per-page
       share-card override, so the five documents that used to fall through to
       the empty default would each have had one picture nothing claimed --
       and an unclaimed upload is one the sweep on another screen offers to
       delete. There is no default any more, and there must not be: a new
       document must be listed here or fail loudly rather than quietly lose a
       file. */
    $meta = contract_meta_images($data['meta'] ?? []);

    return match ($document) {
        'company'  => array_values(array_unique([...company_images($data), ...$meta])),
        'about'    => array_values(array_unique([...about_images($data), ...$meta])),
        'home'     => array_values(array_unique([...home_images($data), ...$meta])),
        'branding' => array_values(array_unique([...branding_images($data), ...$meta])),
        'services' => array_values(array_unique([...services_images($data), ...$meta])),
        'careers', 'contact', 'certifications', 'privacy' => $meta,
        'seo'      => seo_images($data),
        /* No meta band: the chrome is not a page and has no <head> of its own.
           Its pictures are the two logo lockups, srcsets included. */
        'chrome'   => chrome_images($data),
        default    => throw new RuntimeException('Unknown document: ' . $document),
    };
}

/**
 * Run every rich field of a document back through the sanitiser.
 *
 * The receiving side calls this on a payload it has just verified, because a
 * signature proves where something came from and not what is inside it. If the
 * backend is ever compromised, the public site should still not render script.
 *
 * Driven off CAREERS_RICH_FIELDS, CONTACT_RICH_FIELDS, COMPANY_RICH_FIELDS and
 * ABOUT_ROW_RICH_FIELDS rather than a list of
 * its own, so a rich field added to the contract is sanitised on receipt by
 * having been added — not by somebody also remembering to add it here. That is
 * the whole reason this lives in the contract and not in the endpoint.
 *
 * Idempotent: rt_sanitise_html() over already-sanitised markup returns it
 * unchanged, which is what makes it safe for the sender to call as well.
 */
function contract_sanitise(string $document, array $data): array
{
    if ($document === 'careers') {
        foreach ($data['jobs'] as $i => $job) {
            foreach (CAREERS_RICH_FIELDS as $field) {
                $data['jobs'][$i][$field] =
                    rt_sanitise_html((string)($job[$field] ?? ''));
            }
        }
        return $data;
    }

    if ($document === 'contact') {
        foreach (CONTACT_RICH_FIELDS as $section => $fields) {
            foreach ($fields as $field) {
                $data[$section][$field] =
                    rt_sanitise_html((string)($data[$section][$field] ?? ''));
            }
        }
        return $data;
    }

    if ($document === 'company') {
        foreach (COMPANY_RICH_FIELDS as $section => $fields) {
            foreach ($fields as $field) {
                $data[$section][$field] =
                    rt_sanitise_html((string)($data[$section][$field] ?? ''));
            }
        }
        return $data;
    }

    /* The about page's rich text hangs off rows, not bands — one prose block
       per story section. Same walk as careers, one level deeper because the
       list it belongs to is named rather than assumed. */
    if ($document === 'about') {
        foreach (ABOUT_ROW_RICH_FIELDS as $band => $fields) {
            foreach ($data[$band]['items'] ?? [] as $i => $row) {
                foreach ($fields as $field) {
                    $data[$band]['items'][$i][$field] =
                        rt_sanitise_html((string)($row[$field] ?? ''));
                }
            }
        }
        return $data;
    }

    /* The home page has no rich text at all — see HOME_ROW_RICH_FIELDS. It
       still needs a branch, and the branch still has to be explicit: the
       default below is a refusal, so "nothing to sanitise" and "document I do
       not know" must not arrive at the same line. A publish of a document this
       file has never heard of is a bug or an attack, and is refused; a publish
       of the home page is neither, and passes through untouched. */
    if ($document === 'home') {
        return $data;
    }

    /* The services document has no rich text either, and for a stronger reason
       than the home page's: it is seven pages of headings, one-line summaries
       and short list entries, and not one of the hundred and thirty-seven
       solution cards holds a paragraph anybody would want a link or an emphasis
       in. The branch is still explicit, for the reason home's is -- the default
       below is a refusal, and "nothing to sanitise" must not arrive at the same
       line as "document I do not know". */
    if ($document === 'services') {
        return $data;
    }

    /* The certifications page has none either, and the check is easy to make:
       there is not one <strong>, <em> or <br> anywhere in its body. It is a
       hero, three headings, four short blurbs and fifty-four proper nouns.
       The branch is still explicit, for the reason home's and services' are --
       the default below is a refusal, and "nothing to sanitise" must not
       arrive at the same line as "document I do not know".

       The tokens are not an exception to this. {certifications} is stored as
       the literal characters somebody typed and stays text all the way to
       certifications_fill(), which puts a decimal integer in its place. There
       is no markup in the substitution and nothing here to strip. */
    if ($document === 'certifications') {
        return $data;
    }

    /* The branding page's disclaimer is the one place on these three static
       pages that genuinely wants markup: it is a legal notice, and the
       sentence asking a rights holder to get in touch is a link waiting to
       happen. Its paragraphs are rows rather than a band field, so this is the
       same walk as the about page's -- see BRANDING_ROW_RICH_FIELDS. Nothing
       else on the page is rich: an asset's title, blurb and meta label are all
       plain text and go out through h(). */
    if ($document === 'branding') {
        foreach (BRANDING_ROW_RICH_FIELDS as $band => $fields) {
            foreach ($data[$band]['items'] ?? [] as $i => $row) {
                foreach ($fields as $field) {
                    $data[$band]['items'][$i][$field] =
                        rt_sanitise_html((string)($row[$field] ?? ''));
                }
            }
        }
        return $data;
    }

    /* The walk is three levels deep and reaches four different fields, so it
       lives beside the constants that say which kinds hold markup rather than
       being spelled out again here. A rich field this function fails to reach
       is a rich field published unsanitised, which is the one thing it exists
       to prevent -- so test_publish.py asserts that every field the model
       calls rich is a field this reaches. */
    if ($document === 'privacy') {
        return privacy_sanitise($data);
    }

    /* The site-wide SEO document has no rich text and cannot grow any: a meta
       description that carried markup would be printed as characters in a
       search result, and the Organization graph is JSON, not HTML. The branch
       is still explicit, for the reason home's and services' are -- the throw
       below is a refusal, and "nothing to sanitise" must not arrive at the
       same line as "document I do not know". */
    if ($document === 'seo') {
        return $data;
    }

    /* The chrome has no rich text and must not grow any. A nav label, a
       tagline and a phone number are words, and the one place markup could
       plausibly be wanted -- the footer's description -- is a paragraph the
       renderer already wraps in <p>. The branch is explicit for the reason
       seo's is: "nothing to sanitise" must not arrive at the same line as
       "document I do not know". */
    if ($document === 'chrome') {
        return $data;
    }

    throw new RuntimeException('Unknown document: ' . $document);
}
