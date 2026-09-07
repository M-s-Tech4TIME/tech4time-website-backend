<?php
/**
 * Tech4TIME — SEO and page metadata editor.
 *
 * Every search-and-sharing setting on the whole site, on one screen: what each
 * page's browser tab says, what a search result shows under it, what appears
 * when somebody pastes a link into a chat, whether the page is indexed at all,
 * where it sits in a breadcrumb trail, how it is described in the sitemap, and
 * the site-wide record every page carries — the Organization graph, the share
 * card, the colours, robots.txt and the web manifest.
 *
 * MANY SCREENS, NOT ONE, AND THE SPLIT IS BY PAGE.
 *
 *   ?s=seo                        the index: every page, and what it says
 *   ?s=seo&page=<key>             one page, and only that one
 *   ?s=seo&page=service:<id>      a service page, keyed by its row's id
 *   ?s=seo&site=identity          the Organization graph and the share card
 *   ?s=seo&site=crawl             robots.txt, the manifest, site verification
 *
 * Seventeen pages of ten fields on one form would be a screen nobody could
 * find anything on, and it is not what the index is for: the index exists to
 * show all the titles TOGETHER, because "two pages share a title" is the one
 * fault no page can see on its own. Editing happens a page at a time.
 *
 * All of them are ordinary admin_url() links, so admin-swap.js handles them
 * and nothing reloads.
 *
 * THE VALUES ARE NOT STORED HERE. A page's meta band lives in that page's own
 * content document — the About page's title is in content/about.json, beside
 * the About page's content, and always has been. Only the editing moved. See
 * ADR 0020 and the page metadata block in lib/contract.php. The 404 is the one
 * exception, because it renders no document; its record is in
 * content/seo.json.
 *
 * SO A SAVE ON A PAGE SCREEN WRITES THAT PAGE'S DOCUMENT AND PUBLISHES IT, and
 * it does that under a lock: the screen holds one band of a document whose
 * other twenty were never in the form. seo_meta_edit() merges the rest back.
 *
 * THE CRAWL CONTROLS ARE NOT GUARDED, DELIBERATELY. Any page may be set to
 * noindex and any path disallowed. That was chosen over a safe list: the
 * operator has the whole control, and this editor's job is to say plainly what
 * it does before they confirm it — data-confirm on the control, and a standing
 * notice on the index listing every page currently not indexed, so a mistake
 * stays visible on every later visit rather than only at the moment it is
 * made. The single exception is a rule that would remove the entire site,
 * which seo_validate() refuses; that is not a narrower rule to weigh up, it is
 * the off switch.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/upload.php';

/* ---------------------------------------------------------------- reading */

/** A field as it was typed, trimmed. */
function seo_post_text(mixed $value): string
{
    return trim((string)(is_scalar($value) ? $value : ''));
}

/** A field checked against what the model offers rather than trusted. */
function seo_post_choice(mixed $value, array $allowed, string $fallback): string
{
    $value = seo_post_text($value);

    return in_array($value, $allowed, true) ? $value : $fallback;
}

/**
 * One page's meta band, rebuilt from the form over what is stored.
 *
 * Starts from $current, so a field this screen does not offer — a service
 * page has no separate sitemap row of its own to tune beyond these — keeps
 * whatever it had rather than being defaulted away.
 */
function seo_meta_from_post(array $current, bool $notfound): array
{
    $meta = $current;

    /* Named one by one rather than looped, and that is not verbosity. It is
       what lets tools/check_content_model.py read this file and see which
       fields the form actually writes -- a loop over a list of names hides
       them behind a variable, and the check then reports the whole meta band
       as uneditable. Three fields is cheap; the alternative is an exemption
       that would also exempt the next field somebody forgets. */
    $meta['title']       = seo_post_text($_POST['meta']['title'] ?? '');
    $meta['description'] = seo_post_text($_POST['meta']['description'] ?? '');
    $meta['share_title'] = seo_post_text($_POST['meta']['share_title'] ?? '');

    /* keywords, breadcrumb and the sitemap fields are written below, after the
       404 has returned: it has no place in a hierarchy, is never in the
       sitemap, and a page nobody may index has nothing to be found by. Its
       screen does not render any of them either -- a field that is drawn and
       then dropped on save is the trap tools/check_content_model.py exists
       to catch. */

    if ($notfound) {
        /* No breadcrumb: it is a page with no place in a hierarchy. No
           changefreq or priority: it is never in the sitemap. No crawl
           setting: an error page that could be indexed is a bug. */
        $meta['robots'] = 'noindex';

        return $meta;
    }

    $meta['keywords']   = seo_post_text($_POST['meta']['keywords'] ?? '');
    $meta['breadcrumb'] = seo_post_text($_POST['meta']['breadcrumb'] ?? '');
    $meta['robots']     = seo_post_choice($_POST['meta']['robots'] ?? '',
                                          CONTRACT_ROBOTS, 'index');
    $meta['changefreq'] = seo_post_choice($_POST['meta']['changefreq'] ?? '',
                                          CONTRACT_CHANGEFREQ, 'monthly');
    $meta['priority']   = seo_post_text($_POST['meta']['priority'] ?? '0.5');
    $meta['share_alt']  = seo_post_text($_POST['meta']['share_alt'] ?? '');

    return $meta;
}

/** The site-wide document, rebuilt from the identity screen. */
function seo_identity_from_post(array $current): array
{
    $data = $current;

    foreach (['site', 'identity'] as $band) {
        foreach (SEO_TEXT_FIELDS[$band] as $field) {
            $data[$band][$field] = seo_post_text($_POST[$band][$field] ?? '');
        }
    }

    foreach (SEO_LINE_FIELDS['identity'] as $field) {
        $data['identity'][$field] = seo_lines($_POST['identity'][$field] ?? '');
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    foreach (SEO_LISTS as $band => $filler) {
        $data[$band]['items'] = [];
        foreach (array_values((array)($_POST[$band]['items'] ?? [])) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $data[$band]['items'][] = $filler([
                'id'     => seo_post_text($row['id'] ?? ''),
                'label'  => seo_post_text($row['label'] ?? ''),
                'url'    => seo_post_text($row['url'] ?? ''),
                /* The day selects post one entry per day, "Sunday" or "".
                   The empties are dropped here so seo_hours_defaults() sees a
                   plain list of the days that were chosen. */
                'days'   => array_values(array_filter(
                    array_map('strval', (array)($row['days'] ?? [])),
                    static fn(string $d): bool => $d !== ''
                )),
                'opens'  => seo_post_text($row['opens'] ?? ''),
                'closes' => seo_post_text($row['closes'] ?? ''),
                'status' => seo_post_text($row['status'] ?? 'shown') === 'hidden'
                    ? 'hidden' : 'shown',
            ]);
        }
    }

    return seo_identify($data);
}

/** The site-wide document, rebuilt from the crawl screen. */
function seo_crawl_from_post(array $current): array
{
    $data = $current;

    foreach (SEO_TEXT_FIELDS['crawl'] as $field) {
        $data['crawl'][$field] = seo_post_text($_POST['crawl'][$field] ?? '');
    }
    $data['crawl']['robots_extra'] = seo_lines($_POST['crawl']['robots_extra'] ?? '');

    foreach (SEO_TEXT_FIELDS['manifest'] as $field) {
        $data['manifest'][$field] = seo_post_text($_POST['manifest'][$field] ?? '');
    }
    $data['manifest']['display'] = seo_post_choice($_POST['manifest']['display'] ?? '',
                                                   SEO_DISPLAY, 'standalone');

    return $data;
}

/** Add, remove or reorder a row of the two site-wide lists. */
function seo_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    if (!preg_match('/^(sameas|hours)-(add|remove|up|down)$/', $verb, $m)) {
        return null;
    }

    $band = $m[1];
    $rows = $data[$band]['items'];

    if ($m[2] === 'add') {
        /* A new row arrives HIDDEN. It has nothing in it yet, and an empty
           profile appearing in the Organization graph the moment somebody
           presses Add is not what pressing Add means. */
        $rows[] = ($band === 'sameas' ? 'seo_link_defaults' : 'seo_hours_defaults')
            (['status' => 'hidden']);
        $data[$band]['items'] = $rows;

        return [seo_identify($data), $band === 'sameas'
            ? 'Added a profile. It is hidden until you show it — give it a name and '
              . 'an address, then save.'
            : 'Added an opening-hours row. It is hidden until you show it — name it '
              . 'after the office it describes, then save.'];
    }

    $out = admin_move_row($rows, $m[2], $index);
    if ($out === null) {
        return null;
    }
    $data[$band]['items'] = $out[0];

    return [$data, $out[1]];
}

/* ------------------------------------------------------------ which screen */

$requested = seo_post_text($_GET['page'] ?? '');
$siteView  = seo_post_choice($_GET['site'] ?? '', ['identity', 'crawl'], '');

$pages  = seo_pages();
$screen = 'index';

if ($requested !== '' && isset($pages[$requested])) {
    $screen = 'page';
} elseif ($siteView !== '') {
    $screen = $siteView;
}

$data    = seo_load();
$errors  = [];
$pending = '';
$page    = $screen === 'page' ? $pages[$requested] : null;

/* ----------------------------------------------------------------- saving */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
    } elseif ($screen === 'page') {
        $meta = seo_meta_from_post($page['meta'], $page['key'] === 'notfound');

        $others = [];
        foreach ($pages as $key => $row) {
            if ($key !== $page['key']) {
                $others[$key] = $row['meta'];
            }
        }

        $errors = seo_validate_meta($page['key'], $meta, $others);

        if (!$errors) {
            $saved = $page['service']
                ? seo_service_meta_edit(substr($page['key'], 8),
                    static fn(array $was): array => $meta)
                : seo_meta_edit($page['document'],
                    static fn(array $was): array => $meta);

            if ($saved) {
                admin_redirect('seo', 'Saved the settings for ' . $page['name'] . '.',
                               ['page' => $page['key']]);
            }
            $errors[] = 'Could not write the page\'s document. Check the file is '
                      . 'writable by PHP.';
        }
        $page['meta'] = $meta;
    } else {
        $do     = (string)($_POST['do'] ?? 'save');
        $posted = $screen === 'identity'
            ? seo_identity_from_post($data)
            : seo_crawl_from_post($data);

        if ($screen === 'identity') {
            $posted = seo_take_uploads($posted, $errors);
        }

        if ($do === 'save') {
            $errors = array_merge($errors, seo_validate($posted));
            if (!$errors) {
                if (seo_edit(static fn(array $was): array => $posted)) {
                    admin_redirect('seo', 'Saved the site-wide settings.',
                                   ['site' => $screen]);
                }
                $errors[] = 'Could not write content/seo.json. Check the file is '
                          . 'writable by PHP.';
            }
            $data = $posted;
        } else {
            $applied = seo_apply_row_action($posted, $do);
            $data    = $applied[0] ?? $posted;
            $pending = $applied[1] ?? '';
        }
    }
}

/**
 * Take whatever pictures were attached, store them, and send them on.
 *
 * Two slots on the identity screen: the default share card every page falls
 * back to, and the logo the Organization graph points at.
 */
function seo_take_uploads(array $data, array &$errors): array
{
    foreach (admin_uploaded_files() as [$band, $_index, $file]) {
        if (!in_array($band, ['share', 'logo'], true)) {
            continue;
        }

        $where  = $band === 'share' ? 'The share card' : 'The logo';
        $stored = upload_accept($file, UPLOAD_MAX_DIMENSION);

        if (isset($stored['error'])) {
            $errors[] = $where . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = $where . ': ' . $sent;
            continue;
        }

        if ($band === 'share') {
            $data['site']['share'] = contract_image_defaults($stored);
        } else {
            $data['identity']['logo'] = contract_image_defaults($stored);
        }
    }

    return $data;
}

/* --------------------------------------------------------------- fields */

/** A single-line field. */
function seo_text_field(string $name, string $label, string $value,
                        string $hint = '', bool $wide = false,
                        bool $required = false): void
{
    ?>
      <label class="admin__field<?= $wide ? ' admin__field--wide' : '' ?>">
        <span class="admin__label"><?= h($label) ?></span>
        <input class="admin__input" type="text" name="<?= h($name) ?>"<?= $required ? ' required' : '' ?>
               value="<?= h($value) ?>">
<?php if ($hint !== ''): ?>
        <span class="admin__hint"><?= h($hint) ?></span>
<?php endif; ?>
      </label>
    <?php
}

/** A paragraph. */
function seo_area_field(string $name, string $label, string $value,
                        string $hint = '', int $rows = 3): void
{
    ?>
      <label class="admin__field admin__field--wide">
        <span class="admin__label"><?= h($label) ?></span>
        <textarea class="admin__input" name="<?= h($name) ?>" rows="<?= $rows ?>"><?= h($value) ?></textarea>
<?php if ($hint !== ''): ?>
        <span class="admin__hint"><?= h($hint) ?></span>
<?php endif; ?>
      </label>
    <?php
}

/** A list typed one entry per line. */
function seo_list_field(string $name, string $label, array $items, string $hint): void
{
    seo_area_field($name, $label, implode("\n", $items), $hint,
                   max(3, min(14, count($items) + 1)));
}

/**
 * A choice between named options.
 *
 * A <select> and not radio buttons or a checkbox, for the reason every yes/no
 * on this site is a <select>: a checkbox measured under the 24x24 minimum
 * (WCAG 2.2 SC 2.5.8) the last time one was tried here.
 */
function seo_choice_field(string $name, string $label, string $value,
                          array $options, string $hint = '',
                          string $confirm = ''): void
{
    ?>
      <label class="admin__field">
        <span class="admin__label"><?= h($label) ?></span>
        <select class="admin__input" name="<?= h($name) ?>"<?=
          $confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '' ?>>
<?php foreach ($options as $option => $text): ?>
          <option value="<?= h((string)$option) ?>"<?= (string)$option === $value ? ' selected' : '' ?>>
            <?= h((string)$text) ?>
          </option>
<?php endforeach; ?>
        </select>
<?php if ($hint !== ''): ?>
        <span class="admin__hint"><?= h($hint) ?></span>
<?php endif; ?>
      </label>
    <?php
}

/** How long a field is, and whether that is a good length. */
function seo_length_hint(string $value, int $min, int $max): string
{
    $n = strlen(trim($value));

    if ($n === 0) {
        return 'Empty.';
    }
    if ($n > $max) {
        return $n . ' characters — over ' . $max . ', so it will be cut off.';
    }
    if ($min > 0 && $n < $min) {
        return $n . ' characters — under ' . $min . ', so a search engine may write '
             . 'its own instead.';
    }

    return $n . ' characters.';
}

/* --------------------------------------------------------------- screens */

if ($screen === 'index') {
    $notindexed = array_values(array_filter(
        $pages,
        static fn(array $row): bool => ($row['meta']['robots'] ?? 'index') === 'noindex'
                                       && $row['key'] !== 'notfound'
    ));

    admin_head('seo', $user,
        'Every page\'s title, search description and share card. The words '
        . 'live with the page they describe; this is where they are edited.',
        ['band-pages' => 'Pages', 'band-site' => 'The whole site']);

    admin_notices($errors);

    admin_standing_notice(
        'A page marked “Not indexed” is removed from the sitemap and asks Google to '
        . 'drop it. Undoing that needs a re-crawl, which happens on Google’s '
        . 'schedule and can take weeks.');

    if ($notindexed !== []) {
        echo '<p class="admin__notice admin__notice--warn">',
             h(count($notindexed) === 1
                 ? 'One page is currently set to Not indexed: '
                 : count($notindexed) . ' pages are currently set to Not indexed: '),
             h(implode(', ', array_column($notindexed, 'name'))), '.</p>';
    }
    ?>

<section class="admin__block" id="band-pages">
  <?php admin_band_head('Pages',
      'One row per page of the site, including every service. A service added '
      . 'in the Services editor appears here by itself.'); ?>

<?php foreach ($pages as $row): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h($row['name']) ?></strong>
          <span class="admin-card__value"><?= h($row['route'] !== '' ? $row['route'] : 'served at every address that does not exist') ?></span>
        </span>
        <span class="admin-row__status admin-row__status--<?=
          ($row['meta']['robots'] ?? 'index') === 'noindex' ? 'draft' : 'open' ?>">
          <?= ($row['meta']['robots'] ?? 'index') === 'noindex' ? 'Not indexed' : 'Indexed' ?>
        </span>
        <a class="btn btn--secondary" href="<?= h(admin_url('seo', ['page' => $row['key']])) ?>">
          Edit
        </a>
      </div>

      <p class="admin__fineprint">
        <strong><?= h((string)($row['meta']['title'] ?? '')) ?></strong><br>
        <?= h(seo_length_hint((string)($row['meta']['title'] ?? ''), 0, SEO_TITLE_MAX)) ?>
        Description: <?= h(seo_length_hint(
            seo_effective_description($row['key'], (string)($row['meta']['description'] ?? '')),
            SEO_DESC_MIN, SEO_DESC_MAX)) ?>
      </p>
    </div>
<?php endforeach; ?>
</section>

<section class="admin__block" id="band-site">
  <?php admin_band_head('The whole site',
      'What every page carries, whichever page it is.'); ?>

    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>Identity and sharing</strong>
          <span class="admin-card__value">The company record search engines read, the default share card, the colours</span>
        </span>
        <a class="btn btn--secondary" href="<?= h(admin_url('seo', ['site' => 'identity'])) ?>">Edit</a>
      </div>
    </div>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>Crawling and the installed app</strong>
          <span class="admin-card__value">robots.txt, the web manifest, and search-console verification</span>
        </span>
        <a class="btn btn--secondary" href="<?= h(admin_url('seo', ['site' => 'crawl'])) ?>">Edit</a>
      </div>
    </div>
</section>
    <?php
    admin_foot();
    return;
}

/* --------------------------------------------------------- one page's own */

if ($screen === 'page') {
    $meta     = $page['meta'];
    $notfound = $page['key'] === 'notfound';
    $where    = $page['route'] !== ''
        ? '<a href="' . h(public_url($page['route'])) . '">' . h($page['name']) . '</a>'
        : h($page['name']);

    admin_head('seo', $user,
        'Search and sharing for ' . $where . ', stored in <code>content/'
        . h($page['document'] !== '' ? $page['document'] : 'seo') . '.json</code>. '
        . '<a href="' . h(admin_url('seo')) . '">All pages</a>.',
        $notfound
            ? ['band-search' => 'In a search result']
            : ['band-search'    => 'In a search result',
               'band-share'     => 'When the link is shared',
               'band-crawl'     => 'Crawling and the trail',
               'band-sitemap'   => 'In the sitemap'],
        ['form' => 'seo-page-form', 'label' => 'Save these settings',
         'discard' => admin_url('seo', ['page' => $page['key']])]);

    admin_notices($errors);

    if ($page['service'] && ($page['hidden'] ?? false)) {
        admin_standing_notice(
            'This service is hidden, so its page answers 404 and is absent from the '
            . 'sitemap whatever is set here. Show it in the Services editor to put '
            . 'it back on the site.');
    }
    ?>

<form class="admin__form" id="seo-page-form" method="post" data-async
      action="<?= h(admin_url('seo', ['page' => $page['key']])) ?>">
  <?= admin_form_fields('seo') ?>

  <fieldset class="admin__block" id="band-search">
    <?php admin_band_head('In a search result',
        'The blue line somebody clicks, and the two lines under it.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('meta[title]', 'Browser tab title', (string)$meta['title'],
          seo_length_hint((string)$meta['title'], 0, SEO_TITLE_MAX)
          . ' Google cuts titles off at about ' . SEO_TITLE_MAX . '.',
          true, true); ?>

      <?php seo_area_field('meta[description]', 'Search description',
          (string)$meta['description'],
          seo_length_hint(seo_effective_description($page['key'], (string)$meta['description']),
                          SEO_DESC_MIN, SEO_DESC_MAX)
          . ' Aim for ' . SEO_DESC_IDEAL[0] . '–' . SEO_DESC_IDEAL[1] . '.'); ?>

<?php if (!$notfound): /* A page nobody may index has nothing to be found by. */ ?>
      <?php seo_text_field('meta[keywords]', 'Keywords', (string)($meta['keywords'] ?? ''),
          'Separated by commas. Google has ignored this tag since 2009 and Bing '
          . 'treats a stuffed one as spam, so a handful of words this page is '
          . 'genuinely about is worth more than a long list — some smaller and '
          . 'regional engines do still read it. Left empty, the tag is not sent '
          . 'at all.', true); ?>
<?php endif; ?>
    </div>
  </fieldset>

<?php if (!$notfound): ?>
  <fieldset class="admin__block" id="band-share">
    <?php admin_band_head('When the link is shared',
        'What appears when somebody pastes a link to this page into a chat or '
        . 'posts it. Left empty, the site-wide share card is used.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('meta[share_title]', 'Title on a shared link',
          (string)$meta['share_title'],
          'Empty means use the browser tab title.', true); ?>

      <?php seo_text_field('meta[share_alt]', 'Description of the share picture',
          (string)($meta['share_alt'] ?? ''),
          'Only used when this page has a share card of its own.', true); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-crawl">
    <?php admin_band_head('Crawling and the trail',
        'Whether search engines list this page, and what they call it in a '
        . 'breadcrumb trail.'); ?>

    <div class="admin__grid">
      <?php seo_choice_field('meta[robots]', 'In search results',
          (string)$meta['robots'],
          ['index' => 'Indexed — listed in search results',
           'noindex' => 'Not indexed — ask search engines to drop it'],
          'A page that is not indexed also leaves the sitemap. There is one '
          . 'control, not two, so the two cannot disagree.',
          // Named, not "this page". The admin swaps screens without reloading,
          // so a box that says "this page" is a box whose subject is whatever
          // the reader last looked at.
          'Setting ' . $page['name'] . ' to Not indexed asks Google to drop it '
          . 'from search results and removes it from the sitemap. Putting it '
          . 'back needs a re-crawl, which can take weeks. Continue?'); ?>

      <?php seo_text_field('meta[breadcrumb]', 'Name in a breadcrumb trail',
          (string)($meta['breadcrumb'] ?? ''),
          'What a search result calls this page in a trail — often shorter than '
          . 'the heading on the page.', true, true); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-sitemap">
    <?php admin_band_head('In the sitemap',
        'Hints for how often a crawler should come back. Google treats both as '
        . 'advisory and mostly ignores them; they cost nothing and are read by '
        . 'other engines.'); ?>

    <div class="admin__grid">
      <?php seo_choice_field('meta[changefreq]', 'How often it changes',
          (string)$meta['changefreq'],
          array_combine(CONTRACT_CHANGEFREQ, array_map('ucfirst', CONTRACT_CHANGEFREQ))); ?>

      <?php seo_choice_field('meta[priority]', 'Priority within this site',
          (string)$meta['priority'],
          ['1.0' => '1.0 — the most important page', '0.9' => '0.9',
           '0.8' => '0.8', '0.7' => '0.7', '0.6' => '0.6',
           '0.5' => '0.5 — the middle', '0.4' => '0.4', '0.3' => '0.3',
           '0.2' => '0.2', '0.1' => '0.1', '0.0' => '0.0 — the least'],
          'Relative to this site only. It says nothing to Google about how this '
          . 'site compares with any other.'); ?>
    </div>
  </fieldset>
<?php else: ?>
  <?php admin_standing_notice(
      'The error page is served at every address that does not exist, so it has no '
      . 'address of its own: no canonical, no breadcrumb, and never in the sitemap. '
      . 'It is always Not indexed, which is what an error page must be.'); ?>
<?php endif; ?>

  <?= admin_form_tail() ?>
</form>
    <?php
    admin_foot();
    return;
}

/* --------------------------------------------------- the site-wide screens */

if ($screen === 'identity') {
    admin_head('seo', $user,
        'What every page says about the company, stored in '
        . '<code>content/seo.json</code>. '
        . '<a href="' . h(admin_url('seo')) . '">All pages</a>.',
        ['band-site'     => 'The site',
         'band-identity' => 'The company',
         'band-sameas'   => 'Profiles',
         'band-hours'    => 'Opening hours'],
        ['form' => 'seo-site-form', 'label' => 'Save the site-wide settings',
         'discard' => admin_url('seo', ['site' => 'identity'])]);

    admin_notices($errors);

    if (!$errors && $pending !== '') {
        echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
    }

    $site     = $data['site'];
    $identity = $data['identity'];
    ?>

<form class="admin__form" id="seo-site-form" method="post" data-async
      enctype="multipart/form-data"
      action="<?= h(admin_url('seo', ['site' => 'identity'])) ?>">
  <?= admin_form_fields('seo') ?>

  <?php /* Pressing Enter in a text field submits using the first submit button
           in the document, which would otherwise be "Add a profile". This is
           that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <fieldset class="admin__block" id="band-site">
    <?php admin_band_head('The site',
        'The name a shared link is labelled with, the language a screen reader '
        . 'chooses its voice from, and the colour a phone paints around the page.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('site[name]', 'Site name', (string)$site['name'], '', false, true); ?>
      <?php seo_text_field('site[lang]', 'Page language', (string)$site['lang'],
          'A language code, like “en”. It becomes <html lang>.', false, true); ?>
      <?php seo_text_field('site[locale]', 'Sharing locale', (string)$site['locale'],
          'Language and country, like “en_US”, for Facebook and LinkedIn.'); ?>
      <?php seo_choice_field('site[og_type]', 'What kind of thing the site is',
          (string)$site['og_type'],
          ['website' => 'Website', 'article' => 'Article', 'profile' => 'Profile']); ?>
      <?php seo_choice_field('site[twitter_card]', 'Share card shape',
          (string)$site['twitter_card'],
          ['summary_large_image' => 'Large picture above the text',
           'summary'             => 'Small picture beside the text']); ?>
      <?php seo_text_field('site[theme_light]', 'Browser colour, light mode',
          (string)$site['theme_light'], 'A hex colour, like #fafafa.'); ?>
      <?php seo_text_field('site[theme_dark]', 'Browser colour, dark mode',
          (string)$site['theme_dark'], 'A hex colour, like #0b0b0c.'); ?>
      <?php seo_area_field('site[description]', 'What the site is',
          (string)$site['description'],
          'One sentence. Used by the installed app and in the site record search '
          . 'engines hold — not as any page\'s search description.'); ?>
    </div>

    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>The default share card</strong>
          <span class="admin-card__value">Used by every page that has none of its own — which today is all of them</span>
        </span>
      </div>
      <?php admin_image_fields('site[share]', 'upload[share][0]', $site['share'],
          'share card', 'No share card. Links to this site will appear without a picture.'); ?>
      <div class="admin__grid">
        <?php seo_text_field('site[share_alt]', 'Description of the picture',
            (string)$site['share_alt'],
            'Read aloud where the picture cannot be seen.', true); ?>
      </div>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-identity">
    <?php admin_band_head('The company',
        'The record a search engine keeps about the organisation itself. The '
        . 'addresses and telephone numbers are NOT here — they come from the '
        . 'Contact editor, so there is one copy of them.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('identity[legal_name]', 'Organisation name',
          (string)$identity['legal_name'], '', false, true); ?>
      <?php seo_text_field('identity[alternate_name]', 'Also known as',
          (string)$identity['alternate_name']); ?>
      <?php seo_text_field('identity[slogan]', 'Slogan', (string)$identity['slogan']); ?>
      <?php seo_text_field('identity[founded]', 'Founded',
          (string)$identity['founded'], 'As YYYY-MM-DD.'); ?>
      <?php seo_text_field('identity[price_range]', 'Price range',
          (string)$identity['price_range'], 'Conventionally $ to $$$$.'); ?>
      <?php seo_text_field('identity[area_served]', 'Area served',
          (string)$identity['area_served']); ?>
      <?php seo_area_field('identity[description]', 'What the company does',
          (string)$identity['description']); ?>
      <?php seo_list_field('identity[service_types]', 'Services offered',
          $identity['service_types'], 'One per line.'); ?>
      <?php seo_list_field('identity[knows_about]', 'Subjects the company knows',
          $identity['knows_about'], 'One per line.'); ?>
    </div>

    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>The logo search engines use</strong>
          <span class="admin-card__value">Shown beside the company in a knowledge panel</span>
        </span>
      </div>
      <?php admin_image_fields('identity[logo]', 'upload[logo][0]', $identity['logo'],
          'logo', 'No logo. The company record will carry no picture.'); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-sameas">
    <?php admin_band_head('Profiles',
        'The accounts a search engine should understand are also this company.',
        ['do' => 'sameas-add:0', 'label' => 'Add a profile',
         'rows' => 'sameas']); ?>

<?php foreach ($data['sameas']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('sameas', $i, count($data['sameas']['items']), [
          'label'  => (string)$row['label'],
          'noun'   => 'profile',
          'detail' => (string)$row['url'],
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="sameas[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php seo_text_field("sameas[items][$i][label]", 'Name', (string)$row['label']); ?>
        <?php seo_text_field("sameas[items][$i][url]", 'Address', (string)$row['url'],
            'The full https:// address of the profile.', true); ?>
        <?php admin_status_field("sameas[items][$i][status]", (string)$row['status'], 'profile'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <fieldset class="admin__block" id="band-hours">
    <?php admin_band_head('Opening hours',
        'When each office is open, in the form a search engine reads. The hours '
        . 'shown to a person are on the Contact page; these are matched to an '
        . 'office by name, so name a row after the office it describes.',
        ['do' => 'hours-add:0', 'label' => 'Add opening hours',
         'rows' => 'hours']); ?>

<?php foreach ($data['hours']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('hours', $i, count($data['hours']['items']), [
          'label'  => (string)$row['label'],
          'noun'   => 'row',
          'detail' => $row['days'] === [] ? '' :
              implode(', ', array_map(static fn($d) => substr((string)$d, 0, 3), $row['days']))
              . '  ' . $row['opens'] . '–' . $row['closes'],
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="hours[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php seo_text_field("hours[items][$i][label]", 'Which office',
            (string)$row['label'],
            'Name it after the office — that is how it is matched to one.', true); ?>
        <?php seo_text_field("hours[items][$i][opens]", 'Opens',
            (string)$row['opens'], 'As HH:MM, 24-hour.'); ?>
        <?php seo_text_field("hours[items][$i][closes]", 'Closes',
            (string)$row['closes'], 'As HH:MM, 24-hour.'); ?>
        <?php admin_status_field("hours[items][$i][status]", (string)$row['status'], 'row'); ?>
      </div>

      <?php /* Seven selects rather than seven checkboxes, for the reason every
               yes/no in this admin is a select: a checkbox measured under the
               24x24 minimum (WCAG 2.2 SC 2.5.8) the last time one was tried. */ ?>
      <div class="admin__grid">
<?php foreach (SEO_DAYS as $day): ?>
          <?php seo_choice_field("hours[items][$i][days][$day]", $day,
              in_array($day, $row['days'], true) ? $day : '',
              ['' => 'Closed', $day => 'Open']); ?>
<?php endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <?= admin_form_tail() ?>
</form>
    <?php
    admin_foot();
    return;
}

/* ------------------------------------------------- crawling and the manifest */

admin_head('seo', $user,
    'What crawlers are told, and what an installed copy of the site is called. '
    . '<a href="' . h(admin_url('seo')) . '">All pages</a>.',
    ['band-verify'    => 'Proving the site is yours',
     'band-analytics' => 'Google Analytics',
     'band-robots'    => 'What crawlers may not fetch',
     'band-manifest'  => 'The installed app'],
    ['form' => 'seo-crawl-form', 'label' => 'Save these settings',
     'discard' => admin_url('seo', ['site' => 'crawl'])]);

admin_notices($errors);

$crawl    = $data['crawl'];
$manifest = $data['manifest'];
?>

<form class="admin__form" id="seo-crawl-form" method="post" data-async
      action="<?= h(admin_url('seo', ['site' => 'crawl'])) ?>">
  <?= admin_form_fields('seo') ?>

  <fieldset class="admin__block" id="band-verify">
    <?php admin_band_head('Proving the site is yours',
        'Paste the token a search console gives you and save; the tag appears in '
        . 'every page\'s head, and verification can then be completed there. '
        . 'Empty means no tag is emitted at all.'); ?>

    <?php admin_standing_notice(
        'Until the site is verified in Google Search Console there is no way to see '
        . 'which searches it appears in, which pages Google refused to index, or '
        . 'whether its structured data has an error. Nothing on this screen reports '
        . 'any of that on its own.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('crawl[verify_google]', 'Google Search Console token',
          (string)$crawl['verify_google'],
          'The content of the meta tag Google offers, not the whole tag.', true); ?>
      <?php seo_text_field('crawl[verify_bing]', 'Bing Webmaster Tools token',
          (string)$crawl['verify_bing'],
          'The content of the meta tag Bing offers, not the whole tag.', true); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-analytics">
    <?php admin_band_head('Google Analytics',
        'Paste the measurement id from your Google Analytics property and every '
        . 'page starts reporting to it. This is the only thing on this site that '
        . 'loads anything from another company\'s servers, and it does so only '
        . 'while this field has something in it.'); ?>

    <?php admin_standing_notice(
        'Turning this on changes two things beyond measurement. The site begins '
        . 'sending visitors’ data to Google, so the privacy policy — which today '
        . 'says “No cookies, no analytics, no tracking” — stops being true and '
        . 'must be corrected on the Privacy Policy screen. And visitors in the EU, '
        . 'which includes the Brussels office’s, generally have to be asked before '
        . 'analytics runs. Clearing this field stops all of it on the next page load.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('crawl[analytics_id]', 'Measurement id',
          (string)($crawl['analytics_id'] ?? ''),
          'Looks like G-XXXXXXXXXX. A property id from Google Analytics, a '
          . 'GT- container, or an older UA- id. Anything that is not one of '
          . 'those shapes is refused rather than sent.', true); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-robots">
    <?php admin_band_head('What crawlers may not fetch',
        'One path per line. Everything else stays crawlable: robots.txt always '
        . 'begins by allowing the whole site and always names the sitemap, and '
        . 'neither of those can be removed here.'); ?>

    <?php admin_standing_notice(
        'A rule here stops a page being FETCHED, which is not the same as stopping '
        . 'it being listed — a page that is never fetched is a page whose “do not '
        . 'index” is never read. To keep a page out of search results, set it to '
        . 'Not indexed on its own screen instead.'); ?>

    <div class="admin__grid">
      <?php seo_list_field('crawl[robots_extra]', 'Paths crawlers should skip',
          $crawl['robots_extra'],
          'One per line, each beginning with “/”. A rule blocking “/” alone is '
          . 'refused: that is the whole site.'); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-manifest">
    <?php admin_band_head('The installed app',
        'What the site is called when somebody adds it to a phone\'s home screen. '
        . 'The name and the description come from the site settings, so they '
        . 'cannot disagree with the share card.'); ?>

    <div class="admin__grid">
      <?php seo_text_field('manifest[short_name]', 'Short name',
          (string)$manifest['short_name'],
          'What fits under an icon — a dozen characters or so.', false, true); ?>
      <?php seo_choice_field('manifest[display]', 'How it opens',
          (string)$manifest['display'],
          ['standalone' => 'Like an app, with no browser bar',
           'fullscreen' => 'Full screen',
           'minimal-ui' => 'With a minimal browser bar',
           'browser'    => 'In an ordinary browser tab']); ?>
      <?php seo_text_field('manifest[background]', 'Colour while it loads',
          (string)$manifest['background'], 'A hex colour, like #0b0b0c.'); ?>
      <?php seo_text_field('manifest[theme]', 'Colour of the app bar',
          (string)$manifest['theme'], 'A hex colour, like #0b0b0c.'); ?>
    </div>
  </fieldset>

  <?= admin_form_tail() ?>
</form>
<?php
admin_foot();
