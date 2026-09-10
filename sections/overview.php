<?php
/**
 * Tech4TIME — admin overview.
 *
 * What /admin/ opens on. It edits nothing; it says what can be edited, how
 * much of it there is, and when each part was last changed, so that whoever
 * signs in knows where they are before they change anything.
 *
 * Included by admin/index.php, which has already checked that somebody is
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
require_once __DIR__ . '/../lib/seo.php';

/** "3 minutes ago", or the date once that stops being useful. */
function admin_when(string $iso): string
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

$careers = careers_load();
$contact = contact_load();
$company = company_load();
$about   = about_load();
$home    = home_load();

/* How much of the company profile is actually on the page. It is nine bands
   and six lists, so "3 milestones" on its own says very little — what somebody
   wants to know from here is whether a band is switched off, because that is
   the state where the editor is full and the page is empty. */
$company_rows = 0;
foreach (array_keys(COMPANY_LISTS) as $band) {
    $company_rows += count(company_shown($company, $band));
}
$company_hidden = count(array_filter(
    COMPANY_BANDS,
    static fn(string $band): bool => !company_band_shown($company, $band)
));

/* The same two numbers for the about page, and for the same reason: what
   somebody wants to know from here is whether a section is switched off. */
$about_rows = 0;
foreach (array_keys(ABOUT_LISTS) as $band) {
    $about_rows += count(about_shown($about, $band));
}
$about_hidden = count(array_filter(
    ABOUT_BANDS,
    static fn(string $band): bool => !about_band_shown($about, $band)
));

/* And again for the home page, which has more lists than any other screen —
   six — so the total says more here than any single one of them would. */
$home_rows = 0;
foreach (array_keys(HOME_LISTS) as $band) {
    $home_rows += count(home_shown($home, $band));
}
$home_hidden = count(array_filter(
    HOME_BANDS,
    static fn(string $band): bool => !home_band_shown($home, $band)
));

$cards = [
    [
        'section' => 'home',
        'title'   => 'Home page',
        'lines'   => [
            $home_rows . ' entr' . ($home_rows === 1 ? 'y' : 'ies') . ' shown across '
                . count(HOME_LISTS) . ' lists — badges, tags, terminal lines, '
                . 'domains, services and cards',
            $home_hidden === 0
                ? 'Every section of the page is showing'
                : $home_hidden . ' section' . ($home_hidden === 1 ? '' : 's')
                    . ' of the page switched off',
        ],
        'saved'   => (string)($home['updated'] ?? ''),
        'file'    => 'content/home.json',
    ],
    [
        'section' => 'about',
        'title'   => 'About Us',
        'lines'   => [
            $about_rows . ' entr' . ($about_rows === 1 ? 'y' : 'ies') . ' shown across '
                . count(ABOUT_LISTS) . ' lists — the sections, the specialities '
                . 'and the why-us cards',
            $about_hidden === 0
                ? 'Every section of the page is showing'
                : $about_hidden . ' section' . ($about_hidden === 1 ? '' : 's')
                    . ' of the page switched off',
        ],
        'saved'   => (string)($about['updated'] ?? ''),
        'file'    => 'content/about.json',
    ],
    [
        'section' => 'company',
        'title'   => 'Company profile',
        'lines'   => [
            $company_rows . ' entr' . ($company_rows === 1 ? 'y' : 'ies') . ' shown across '
                . count(COMPANY_LISTS) . ' lists — milestones, statistics, clients, '
                . 'photographs, technology and principles',
            $company_hidden === 0
                ? 'Every section of the page is showing'
                : $company_hidden . ' section' . ($company_hidden === 1 ? '' : 's')
                    . ' of the page switched off',
        ],
        'saved'   => (string)($company['updated'] ?? ''),
        'file'    => 'content/company.json',
    ],
    [
        'section' => 'careers',
        'title'   => 'Job posts',
        'lines'   => [
            count($careers['jobs']) . ' post' . (count($careers['jobs']) === 1 ? '' : 's') . ', '
                . count(careers_open_jobs($careers)) . ' live on the site',
            trim((string)($careers['cv_form_url'] ?? '')) !== ''
                ? 'Speculative applications have a form link'
                : 'No form link for speculative applications',
        ],
        'saved'   => (string)($careers['updated'] ?? ''),
        'file'    => 'content/careers.json',
    ],
    [
        'section' => 'contact',
        'title'   => 'Contact page',
        'lines'   => [
            count(contact_shown_offices($contact)) . ' office'
                . (count(contact_shown_offices($contact)) === 1 ? '' : 's') . ' shown, '
                . count($contact['reach']['items']) . ' direct contact row'
                . (count($contact['reach']['items']) === 1 ? '' : 's'),
            count($contact['form']['service_types']) . ' services offered in the enquiry form',
        ],
        'saved'   => (string)($contact['updated'] ?? ''),
        'file'    => 'content/contact.json',
        'warn'    => '',
    ],
    [
        'section' => 'seo',
        'title'   => 'SEO & metadata',
        'lines'   => [
            $seo_pages . ' page' . ($seo_pages === 1 ? '' : 's') . ' with a title, a '
                . 'search description and a place in the sitemap',
            $seo_hidden === 0
                ? 'Every page is listed in search results'
                : $seo_hidden . ' page' . ($seo_hidden === 1 ? ' is' : 's are')
                    . ' set to Not indexed',
        ],
        'saved'   => (string)($seo['updated'] ?? ''),
        'file'    => 'content/seo.json',
    ],
];

/* THE SEO SCREEN COVERS EVERY PAGE, including the six services, which are
   rows rather than files -- so the count comes from seo_pages() rather than
   from ADMIN_PAGE_SECTIONS. The 404 is in it too: it has a title and a
   description like any other page, and is the one page whose record lives in
   content/seo.json rather than beside its own content. */
$seo       = seo_load();
$seo_rows  = seo_pages();
$seo_pages = count($seo_rows);
$seo_hidden = count(array_filter(
    $seo_rows,
    static fn(array $row): bool => ($row['meta']['robots'] ?? 'index') === 'noindex'
                                   && $row['key'] !== 'notfound'
));

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
      <li class="admin-tile__saved">Last saved <?= h(admin_when($card['saved'])) ?></li>
    </ul>

<?php if (($card['warn'] ?? '') !== ''): ?>
    <p class="admin-tile__warn"><?= admin_icon('info-circle', 'icon icon--sm') ?> <?= h($card['warn']) ?></p>
<?php endif; ?>

    <div class="admin-tile__actions">
      <a class="btn btn--primary" href="<?= h(admin_url($card['section'])) ?>">Edit</a>
      <a class="btn btn--ghost" href="<?= h(public_url(ADMIN_SECTIONS[$card['section']]['view'])) ?>"
         target="_blank" rel="noopener">View the page</a>
    </div>

    <p class="admin-tile__file"><code><?= h($card['file']) ?></code></p>
  </li>
<?php endforeach; ?>
</ul>

<section class="admin__block">
  <h2 class="admin__section-title">What is not editable here</h2>
  <p class="admin__blurb">
    Said plainly, so nobody hunts for a screen that does not exist. Everything
    below is part of the pages themselves and changes with a redeploy.
  </p>
  <ul class="admin__notes">
    <li>
      <strong>The other pages.</strong> Home, About, Services and the rest are
      static files. They are also the pages whose wording almost never changes.
      The three above are not among them — careers, contact and the company
      profile are edited here and published to the live site on save.
    </li>
    <li>
      <strong>The footer on every page.</strong> It repeats the email address,
      phone numbers, addresses and opening hours. The project rules out
      fetching shared pieces at run time, so the footer is pasted into each
      page rather than read from a file — the contact editor says so when the
      two have parted.
    </li>
    <li>
      <strong>The enquiry form's own fields</strong> and where it sends. Those
      live in <code>contact-handler.php</code>, which validates each one.
    </li>
    <li>
      <strong>Images on the pages that are not edited here.</strong> Those are
      built from <code>tools/masters/</code> and uploaded with a deploy. The
      company profile's client logos, journey photographs and technology marks,
      and an office's flag, are all uploaded from the editor itself — they go
      through the same signed channel as the text and land on the live site
      without one.
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
