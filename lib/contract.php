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
                            'certifications'];

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
const CONTRACT_BOOKKEEPING = ['updated', 'revision', 'footer_synced'];

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
    'meta'    => ['title', 'description', 'share_title'],
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
        'updated'       => '',
        'revision'      => 0,
        'footer_synced' => '',
        'meta' => [
            'title'       => 'Contact Us | Tech4TIME',
            'description' => 'Get in touch with Tech4TIME.',
            'share_title' => 'Ask for a quote or just contact us',
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

/* ------------------------------------------------------------ footer drift

   The same email, phone numbers, addresses and opening hours appear in the
   site footer, which is pasted into every page as literal markup — the project
   forbids runtime partials, so there is no include to point at contact.json.

   The contact page updates the moment it is saved; the footer does not, and
   cannot, until the frontend's pages are rebuilt and deployed. Rather than let
   that difference go unnoticed, the details that appear in both places are
   fingerprinted here.

   AFTER THE SPLIT the two halves of that comparison live on different hosts.
   The frontend's tools/sync_site_contact.py rebuilds the footers and writes
   the fingerprint into lib/footer.php, which deploys with the site; the
   frontend reports it back in every publish response; the backend records what
   it was told and the editor compares. So the warning is still answered by the
   side that actually knows, rather than by the side that would like to.
   -------------------------------------------------------------------------- */

/**
 * A stable digest of exactly the facts the site-wide footer repeats.
 *
 * Deliberately a delimited string rather than json_encode(): the same digest
 * has to be computed by the frontend's tools/sync_site_contact.py in Python,
 * and the two languages do not agree on how a JSON document is spelled — PHP
 * escapes the slash in "278/3" by default and Python does not. A string with
 * fixed separators is the same bytes in both.
 */
function contact_fingerprint(array $data): string
{
    $parts = ['email=' . contact_email($data)];

    foreach (contact_shown_offices($data) as $office) {
        $parts[] = implode('|', [
            trim((string)$office['name']),
            trim((string)$office['address']),
            implode(';', $office['phones']),
            trim((string)$office['hours']),
            trim((string)$office['schema']['street']),
            trim((string)$office['schema']['locality']),
            trim((string)$office['schema']['region']),
            trim((string)$office['schema']['postal_code']),
            strtoupper(trim((string)$office['schema']['country'])),
        ]);
    }

    return hash('sha256', implode("\n", $parts));
}

function contact_footer_in_step(array $data): bool
{
    return trim((string)($data['footer_synced'] ?? '')) === contact_fingerprint($data);
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
    'meta'       => ['title', 'description', 'share_title'],
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
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

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
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: COMPANY_ID_PLACEHOLDER;

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

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
    'meta'        => ['title', 'description', 'share_title'],
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
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

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
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: ABOUT_ID_PLACEHOLDER;

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

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
    'meta'         => ['title', 'description', 'share_title'],
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
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

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
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: HOME_ID_PLACEHOLDER;

    $base = $slug;
    $n = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

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
    'meta' => ['title', 'description', 'share_title'],
    'hero' => ['title', 'subtitle'],
    'nav'  => ['eyebrow', 'title', 'lead'],
    'ossf' => ['eyebrow', 'title', 'lead'],
    'cta'  => ['title', 'text', 'label', 'href', 'icon'],
];

/* Free-text single-line fields of ONE SERVICE, by band. */
const SERVICES_PAGE_TEXT_FIELDS = [
    'service' => ['name', 'slug', 'schema_type', 'schema_description'],
    'meta'   => ['title', 'description', 'share_title'],
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

    $row['meta'] = is_array($row['meta'] ?? null) ? $row['meta'] : [];
    $row['meta'] += ['title' => '', 'description' => '', 'share_title' => ''];

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
    return $id === ''
        || preg_match('/^' . SERVICES_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;
}

/** Keep a real id, replace a placeholder once there is a name to replace it with. */
function services_mint(string $id, string $name, array $taken): string
{
    $id   = trim($id);
    $name = trim($name);

    if ((services_provisional($id) && $name !== '') || in_array($id, $taken, true)) {
        return services_slug($name, $taken);
    }
    if ($id === '') {
        return services_slug('', $taken);
    }
    return $id;
}

/** A URL-safe id from a name. */
function services_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: SERVICES_ID_PLACEHOLDER;

    return services_unique($slug, $taken);
}

/** The same id, suffixed until nothing else in the list has it. */
function services_unique(string $slug, array $taken): string
{
    $base = $slug;
    $n    = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

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
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

/** Only the rows of a list a visitor should see, wherever the list is. */
function services_rows_shown(array $rows): array
{
    return array_values(array_filter(
        is_array($rows) ? $rows : [],
        static fn($row): bool => is_array($row) && ($row['status'] ?? 'shown') !== 'hidden'
    ));
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
    'meta'  => ['title', 'description', 'share_title'],
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
    return $id === ''
        || preg_match('/^' . CERTIFICATIONS_ID_PLACEHOLDER . '(-\d+)?$/', $id) === 1;
}

/** Keep a real id, replace a placeholder once there is a name to replace it with. */
function certifications_mint(string $id, string $name, array $taken): string
{
    $id   = trim($id);
    $name = trim($name);

    if ((certifications_provisional($id) && $name !== '') || in_array($id, $taken, true)) {
        return certifications_slug($name, $taken);
    }
    if ($id === '') {
        return certifications_slug('', $taken);
    }
    return $id;
}

/** A URL-safe id from a name. */
function certifications_slug(string $name, array $taken = []): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-') ?: CERTIFICATIONS_ID_PLACEHOLDER;

    return certifications_unique($slug, $taken);
}

/** The same id, suffixed until nothing else in the list has it. */
function certifications_unique(string $slug, array $taken): string
{
    $base = $slug;
    $n    = 2;
    while (in_array($slug, $taken, true)) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/** Whether a band is shown at all. */
function certifications_band_shown(array $data, string $band): bool
{
    return ($data[$band]['status'] ?? 'shown') !== 'hidden';
}

/** Only the rows of a list a visitor should see, wherever the list is. */
function certifications_rows_shown(array $rows): array
{
    return array_values(array_filter(
        is_array($rows) ? $rows : [],
        static fn($row): bool => is_array($row) && ($row['status'] ?? 'shown') !== 'hidden'
    ));
}

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
   8. Revisions
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
   9. Normalising and re-sanitising on receipt
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

    throw new RuntimeException('Unknown document: ' . $document);
}
