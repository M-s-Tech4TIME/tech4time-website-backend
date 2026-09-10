<?php
/**
 * Tech4TIME — admin overview.
 *
 * What the admin opens on. It edits nothing; it says what can be edited, how
 * much of it there is, and when each part was last changed, so that whoever
 * signs in knows where they are before they change anything.
 *
 * ONE TILE PER RAIL ROW, AND THE LIST IS DERIVED. $cards is built by walking
 * ADMIN_RAIL_SECTIONS, so a section added to the rail gets a tile the same
 * day. It was a hand-written list and it had drifted: nine editors, six tiles.
 * The services, certifications, branding and privacy pages had been editable
 * for weeks with nothing here saying so, which is the exact failure this
 * screen exists to prevent -- somebody hunting for a screen, or not knowing
 * one is there. A section with no summary written for it still gets a tile
 * now, saying it has none, rather than silently not appearing.
 *
 * EVERYTHING DERIVED IS COMPUTED BEFORE $facts, and that is not style. The SEO
 * tile read $seo_pages, $seo_hidden and $seo out of an array literal that was
 * evaluated BEFORE the three were assigned, forty lines further down. PHP
 * warned and carried on with null, so the tile said "0 pages", "Every page is
 * listed in search results" and "Last saved never" whatever the data held --
 * every time, for anybody, and looking exactly like a working tile.
 *
 * Included by public/index.php, which has already checked that somebody is
 * signed in and started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/careers.php';
require_once __DIR__ . '/../lib/contact.php';
require_once __DIR__ . '/../lib/company.php';
require_once __DIR__ . '/../lib/about.php';
require_once __DIR__ . '/../lib/home.php';
require_once __DIR__ . '/../lib/services.php';
require_once __DIR__ . '/../lib/certifications.php';
require_once __DIR__ . '/../lib/branding.php';
require_once __DIR__ . '/../lib/privacy.php';
require_once __DIR__ . '/../lib/chrome.php';
require_once __DIR__ . '/../lib/seo.php';

/* THESE FOUR ARE PREFIXED overview_ AND NOT admin_, though they sit beside a
   screen full of admin_ helpers. Those belong to lib/admin.php and are shared
   by every section; these are this screen's own, and a section file that
   defines an admin_ function is a collision waiting for the day lib/admin.php
   grows one of the same name. overview_when() was admin_when() until this
   file was rewritten, and was the only one of its kind. */

/** "3 minutes ago", or the date once that stops being useful. */
function overview_when(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') {
        return 'never';
    }

    $at = strtotime($iso);
    if ($at === false) {
        return $iso;
    }

    $ago = time() - $at;
    if ($ago < 90) {
        return 'just now';
    }
    if ($ago < 3600) {
        return (int)round($ago / 60) . ' minutes ago';
    }
    if ($ago < 86400) {
        $hours = (int)round($ago / 3600);
        return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    }
    if ($ago < 7 * 86400) {
        $days = (int)round($ago / 86400);
        return $days . ($days === 1 ? ' day ago' : ' days ago');
    }
    return date('j F Y', $at);
}

$careers        = careers_load();
$contact        = contact_load();
$company        = company_load();
$about          = about_load();
$home           = home_load();
$services       = services_load();
$certifications = certifications_load();
$branding       = branding_load();
$privacy        = privacy_load();
$chrome         = chrome_load();
$seo            = seo_load();

/** How many rows of a document's lists a visitor would actually see. */
function overview_rows(array $data, array $lists, callable $shown): int
{
    $rows = 0;
    foreach (array_keys($lists) as $band) {
        $rows += count($shown($data, $band));
    }

    return $rows;
}

/** How many of a document's bands are switched off. */
function overview_hidden(array $data, array $bands, callable $band_shown): int
{
    return count(array_filter($bands,
        static fn(string $band): bool => !$band_shown($data, $band)));
}

/** "3 sections of the page switched off", or that they are all showing. */
function overview_bands_line(int $hidden): string
{
    return $hidden === 0
        ? 'Every section of the page is showing'
        : $hidden . ' section' . ($hidden === 1 ? '' : 's') . ' of the page switched off';
}

/* TWO NUMBERS PER PAGE, AND THE SECOND IS THE USEFUL ONE. "3 milestones" says
   very little on its own; what somebody wants to know from here is whether a
   band is switched OFF, because that is the state where the editor is full and
   the page is empty. Four documents answer it the same way, so they are asked
   the same way -- the same walk written out four times is four places for the
   next list to be forgotten. */
$company_rows   = overview_rows($company, COMPANY_LISTS, 'company_shown');
$company_hidden = overview_hidden($company, COMPANY_BANDS, 'company_band_shown');

$about_rows   = overview_rows($about, ABOUT_LISTS, 'about_shown');
$about_hidden = overview_hidden($about, ABOUT_BANDS, 'about_band_shown');

$home_rows   = overview_rows($home, HOME_LISTS, 'home_shown');
$home_hidden = overview_hidden($home, HOME_BANDS, 'home_band_shown');

$services_rows   = overview_rows($services, SERVICES_LISTS, 'services_shown');
$services_hidden = overview_hidden($services, SERVICES_BANDS, 'services_band_shown');
$services_live   = count(services_rows_shown(services_all($services)));
$services_total  = count(services_all($services));

/* The certifications page counts itself -- its own lead says "the four
   specialist roles we staff" and its description carries a {certifications}
   token -- so these are the numbers the PAGE will show, shown rows only. */
$cert_counts = certifications_counts($certifications);
$cert_hidden = overview_hidden($certifications, CERTIFICATIONS_BANDS,
                                  'certifications_band_shown');

$branding_all   = branding_assets($branding);
$branding_live  = contract_rows_shown($branding_all);
$branding_files = 0;
foreach ($branding_live as $asset) {
    $branding_files += count(contract_rows_shown($asset['files'] ?? []));
}
$branding_hidden = overview_hidden($branding, BRANDING_BANDS, 'branding_band_shown');

$privacy_sections = count(contract_rows_shown(privacy_sections($privacy)));

/* THE SEO SCREEN COVERS EVERY PAGE, including the six services, which are rows
   rather than files -- so the count comes from seo_pages() rather than from
   ADMIN_PAGE_SECTIONS. The 404 is in it too: it has a title and a description
   like any other page, and is the one page whose record lives in
   content/seo.json rather than beside its own content. */
$seo_rows   = seo_pages();
$seo_pages  = count($seo_rows);
$seo_hidden = count(array_filter(
    $seo_rows,
    static fn(array $row): bool => ($row['meta']['robots'] ?? 'index') === 'noindex'
                                   && $row['key'] !== 'notfound'
));

/* What the footer says that the contact page does not. A notice, never a
   refusal -- see chrome_contact_drift(). */
$chrome_drift = chrome_contact_drift($chrome, $contact);

/* ONE ENTRY PER SECTION THAT HAS A SUMMARY, keyed by section rather than
   listed, so that the walk below can look each one up and a section with none
   is visible rather than absent. */
$facts = [
    'home' => [
        'title' => 'Home page',
        'lines' => [
            $home_rows . ' entr' . ($home_rows === 1 ? 'y' : 'ies') . ' shown across '
                . count(HOME_LISTS) . ' lists — badges, tags, terminal lines, '
                . 'domains, services and cards',
            overview_bands_line($home_hidden),
        ],
        'saved' => (string)($home['updated'] ?? ''),
        'file'  => 'content/home.json',
    ],
    'about' => [
        'title' => 'About Us',
        'lines' => [
            $about_rows . ' entr' . ($about_rows === 1 ? 'y' : 'ies') . ' shown across '
                . count(ABOUT_LISTS) . ' lists — the sections, the specialities '
                . 'and the why-us cards',
            overview_bands_line($about_hidden),
        ],
        'saved' => (string)($about['updated'] ?? ''),
        'file'  => 'content/about.json',
    ],
    'services' => [
        'title' => 'Services',
        'lines' => [
            $services_live . ' service' . ($services_live === 1 ? '' : 's') . ' live'
                . ($services_total === $services_live
                    ? '' : ' of ' . $services_total . ' — the rest are hidden')
                . ', each with a page of its own',
            $services_rows . ' entr' . ($services_rows === 1 ? 'y' : 'ies')
                . ' on the index above them; ' . strtolower(overview_bands_line($services_hidden)),
        ],
        'saved' => (string)($services['updated'] ?? ''),
        'file'  => 'content/services.json',
    ],
    'company' => [
        'title' => 'Company profile',
        'lines' => [
            $company_rows . ' entr' . ($company_rows === 1 ? 'y' : 'ies') . ' shown across '
                . count(COMPANY_LISTS) . ' lists — milestones, statistics, clients, '
                . 'photographs, technology and principles',
            overview_bands_line($company_hidden),
        ],
        'saved' => (string)($company['updated'] ?? ''),
        'file'  => 'content/company.json',
    ],
    'careers' => [
        'title' => 'Job posts',
        'lines' => [
            count($careers['jobs']) . ' post' . (count($careers['jobs']) === 1 ? '' : 's') . ', '
                . count(careers_open_jobs($careers)) . ' live on the site',
            trim((string)($careers['cv_form_url'] ?? '')) !== ''
                ? 'Speculative applications have a form link'
                : 'No form link for speculative applications',
        ],
        'saved' => (string)($careers['updated'] ?? ''),
        'file'  => 'content/careers.json',
    ],
    'contact' => [
        'title' => 'Contact page',
        'lines' => [
            count(contact_shown_offices($contact)) . ' office'
                . (count(contact_shown_offices($contact)) === 1 ? '' : 's') . ' shown, '
                . count($contact['reach']['items']) . ' direct contact row'
                . (count($contact['reach']['items']) === 1 ? '' : 's'),
            count($contact['form']['service_types']) . ' services offered in the enquiry form',
        ],
        'saved' => (string)($contact['updated'] ?? ''),
        'file'  => 'content/contact.json',
    ],
    'certifications' => [
        'title' => 'Resource certifications',
        'lines' => [
            $cert_counts['certifications'] . ' certification'
                . ($cert_counts['certifications'] === 1 ? '' : 's') . ' across '
                . $cert_counts['groups'] . ' role group'
                . ($cert_counts['groups'] === 1 ? '' : 's') . ', naming '
                . $cert_counts['roles'] . ' role' . ($cert_counts['roles'] === 1 ? '' : 's'),
            'The page counts itself from these — hiding one changes what it says',
        ],
        'saved' => (string)($certifications['updated'] ?? ''),
        'file'  => 'content/certifications.json',
    ],
    'branding' => [
        'title' => 'Branding & advertisement',
        'lines' => [
            count($branding_live) . ' logo' . (count($branding_live) === 1 ? '' : 's')
                . ' shown' . (count($branding_all) === count($branding_live)
                    ? '' : ' of ' . count($branding_all))
                . ', with ' . $branding_files . ' file'
                . ($branding_files === 1 ? '' : 's') . ' to download between them',
            overview_bands_line($branding_hidden),
        ],
        'saved' => (string)($branding['updated'] ?? ''),
        'file'  => 'content/branding.json',
    ],
    'privacy' => [
        'title' => 'Privacy policy',
        'lines' => [
            $privacy_sections . ' section' . ($privacy_sections === 1 ? '' : 's') . ' shown',
            trim((string)($privacy['policy']['effective'] ?? '')) !== ''
                ? (string)$privacy['policy']['effective']
                : 'No effective date set',
        ],
        'saved' => (string)($privacy['updated'] ?? ''),
        'file'  => 'content/privacy.json',
    ],
    'chrome' => [
        'title' => 'Header & footer',
        'lines' => [
            count(chrome_rows_shown($chrome['header']['nav']['items'])) . ' link'
                . (count(chrome_rows_shown($chrome['header']['nav']['items'])) === 1 ? '' : 's')
                . ' in the navigation, '
                . count(chrome_rows_shown($chrome['footer']['contact']['items']))
                . ' contact row'
                . (count(chrome_rows_shown($chrome['footer']['contact']['items'])) === 1 ? '' : 's')
                . ' in the footer, '
                . count($chrome['dock']['bar']['items']) . ' keys in the mobile dock',
            'On every page of the site — the services column and the social icons '
                . 'are read from their own editors',
        ],
        'saved' => (string)($chrome['updated'] ?? ''),
        'file'  => 'content/chrome.json',
        'warn'  => $chrome_drift === [] ? ''
                 : 'The footer says ' . count($chrome_drift)
                   . ' thing' . (count($chrome_drift) === 1 ? '' : 's')
                   . ' the contact page does not. Not necessarily wrong — the '
                   . 'footer\'s contact rows are its own.',
    ],
    'seo' => [
        'title' => 'SEO & metadata',
        'lines' => [
            $seo_pages . ' page' . ($seo_pages === 1 ? '' : 's') . ' with a title, a '
                . 'search description and a place in the sitemap',
            $seo_hidden === 0
                ? 'Every page is listed in search results'
                : $seo_hidden . ' page' . ($seo_hidden === 1 ? ' is' : 's are')
                    . ' set to Not indexed',
        ],
        'saved' => (string)($seo['updated'] ?? ''),
        'file'  => 'content/seo.json',
    ],
];

/* THE TILES ARE THE RAIL, IN RAIL ORDER, so this screen reads down in the same
   order as the column beside it and a section added to the rail gets a tile
   without anybody remembering to add one. The Overview does not get a tile of
   itself.
   A section with no entry above still appears, saying it has no summary --
   which is a legible gap somebody will close, where a missing tile is an
   editor nobody knows exists. That is not hypothetical: this list was written
   by hand and had drifted to six tiles against nine editors. */
$cards = [];
foreach (ADMIN_RAIL_SECTIONS as $section) {
    if ($section === 'overview') {
        continue;
    }

    $cards[] = ($facts[$section] ?? [
        'title' => ADMIN_SECTIONS[$section]['label'],
        'lines' => [ADMIN_SECTIONS[$section]['desc'],
                    'No summary is written for this screen yet.'],
        'saved' => '',
        'file'  => '',
    ]) + ['section' => $section];
}

admin_head('overview', $user,
    'Everything on the site that can be changed without a redeploy.');

admin_notices($errors);
?>

<ul class="admin__cards" role="list">
<?php foreach ($cards as $card): ?>
  <li class="admin-tile">
    <div class="admin-tile__head">
      <span class="admin-tile__icon"><?= admin_icon(ADMIN_SECTIONS[$card['section']]['icon']) ?></span>
      <h2 class="admin-tile__title"><?= h($card['title']) ?></h2>
    </div>

    <ul class="admin-tile__facts" role="list">
<?php foreach ($card['lines'] as $line): ?>
      <li><?= h($line) ?></li>
<?php endforeach; ?>
      <li class="admin-tile__saved">Last saved <?= h(overview_when($card['saved'])) ?></li>
    </ul>

<?php if (($card['warn'] ?? '') !== ''): ?>
    <p class="admin-tile__warn"><?= admin_icon('info-circle', 'icon icon--sm') ?> <?= h($card['warn']) ?></p>
<?php endif; ?>

    <div class="admin-tile__actions">
      <a class="btn btn--primary" href="<?= h(admin_url($card['section'])) ?>">Edit</a>
<?php /* Only for a section that edits ONE page. The SEO and Header & Footer
         screens are on all of them, and 'view' is '' for both -- public_url('')
         would offer "View the page" pointing at the home page, which is a
         wrong answer rather than a missing one. */ ?>
<?php if (ADMIN_SECTIONS[$card['section']]['view'] !== ''): ?>
      <a class="btn btn--ghost" href="<?= h(public_url(ADMIN_SECTIONS[$card['section']]['view'])) ?>"
         target="_blank" rel="noopener">View the page</a>
<?php endif; ?>
    </div>

<?php if ($card['file'] !== ''): ?>
    <p class="admin-tile__file"><code><?= h($card['file']) ?></code></p>
<?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>

<section class="admin__block">
  <h2 class="admin__section-title">What is not editable here</h2>
  <p class="admin__blurb">
    Said plainly, so nobody hunts for a screen that does not exist. Everything
    below is part of the site itself and changes with a redeploy.
  </p>
  <ul class="admin__notes">
    <li>
      <strong>A page&rsquo;s address, and whether it exists at all.</strong> Adding,
      renaming or removing a page is a code change. That is what makes it
      impossible to point a link at an address nothing answers to &mdash; every
      link on the Header &amp; Footer screen picks from this list rather than
      typing one.
    </li>
    <li>
      <strong>The shape of a page.</strong> Which bands it has and in what order,
      the footer&rsquo;s four columns, the four keys in the mobile dock. The
      contents of each are edited here; their number and order are the layout,
      and a layout that could be changed from a form is a layout that could be
      broken at a width nobody has tested.
    </li>
    <li>
      <strong>The enquiry form&rsquo;s own fields</strong> and where it sends.
      Those live in <code>contact-handler.php</code> on the public site, which
      validates each one.
    </li>
    <li>
      <strong>Pictures that are part of the design.</strong> The favicon, the
      share card, the hero artwork and the icons are built from
      <code>tools/masters/</code> and land with a deploy. Everything a person
      uploads &mdash; a client logo, a journey photograph, an office flag, a
      logo file to download &mdash; goes through the same signed channel as the
      text and reaches the live site without one.
    </li>
  </ul>
</section>

<?php
admin_foot(
    '<p>Signed in as <strong>' . h($account['user']) . '</strong>. '
    . 'Your password, the authenticator app and the recovery codes are on the '
    . '<a href="' . h(admin_url('account')) . '">Account</a> page, along with a '
    . 'record of every attempt to sign in.</p>'
);
