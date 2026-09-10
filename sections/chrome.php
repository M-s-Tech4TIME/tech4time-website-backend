<?php
/**
 * Tech4TIME — the header, footer and dock editor.
 *
 * The furniture around every page of the public site: the logo lockups and the
 * main navigation, the footer's four columns and its bottom bar, and the
 * small-screen dock. Stored in content/chrome.json; there is no database.
 *
 * It was literal markup in seventeen page files until 2026-09-10 -- about
 * 6,800 lines of duplication -- and nothing in it could be changed without a
 * developer and a deploy: not a nav link, not the tagline, not a phone number,
 * not the copyright name. ADR 0023.
 *
 * FOUR SCREENS, NOT ONE, AND THE SPLIT IS BY PART.
 *
 *   ?s=chrome                the index: the three parts, and what is in them
 *   ?s=chrome&part=header    the logo, the brand label, the nav rows
 *   ?s=chrome&part=footer    the brand block, the four columns, the bottom bar
 *   ?s=chrome&part=dock      the panel rows and the four bar keys
 *
 * Split for the reason the SEO editor is split: max_input_vars defaults to
 * 1000 and PHP drops the tail of a larger POST in silence. The footer screen
 * is the big one -- nine contact rows at five fields each, plus two link
 * columns -- and it posts about a hundred and forty fields today. That is
 * comfortable, and admin_form_truncated() is still called on every screen
 * either way, because "it fits today" is the sentence that stops being true
 * when somebody adds rows.
 *
 * A LINK PICKS A PAGE; IT NEVER TYPES AN ADDRESS. Every destination is a key
 * of chrome_targets() -- a route or a service -- drawn as a <select>. The nav
 * is the one component on every page of the site, and a nav that can point
 * anywhere can point at a 404. It also means renaming a service renames its
 * footer link by itself, which is one of the three defects this replaced.
 *
 * TWO COLUMNS OF THE FOOTER HAVE NO ROWS HERE, AND ARE NOT MISSING. The
 * services list is read from content/services.json as the page renders and the
 * social links from the SEO screen's profiles, so both are edited in one place
 * and neither can go stale. Only their headings are here, and each says where
 * its rows come from.
 *
 * THE CONTACT ROWS ARE THE FOOTER'S OWN, deliberately, and the notice above
 * them reports what the Contact page says differently. It never refuses a
 * save: after an office moves, whichever page you edited first would be
 * unsavable if agreement were a rule.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/chrome.php';
require_once __DIR__ . '/../lib/seo.php';

/**
 * What each part is called on the index, and in the message after a save.
 *
 * Here rather than in lib/contract.php because it is editor copy: the contract
 * says a part is called 'header', and what a person should read is "the header
 * and the main navigation". CHROME_PARTS is the list; this is the wording.
 */
const CHROME_PART_SCREENS = [
    'header' => [
        'title' => 'The header',
        'label' => 'header',
        'blurb' => 'The logo lockup, what a screen reader announces the logo link as, '
                 . 'and the main navigation. Below 64em the navigation is hidden and '
                 . 'the dock takes over, so these are the links a laptop sees.',
    ],
    'footer' => [
        'title' => 'The footer',
        'label' => 'footer',
        'blurb' => 'The brand block and its tagline, the Quick Links column, the '
                 . 'headings above the two derived columns, the contact details this '
                 . 'footer carries, and the bottom bar — the copyright line and the '
                 . 'links beside it.',
    ],
    'dock' => [
        'title' => 'The mobile dock',
        'label' => 'dock',
        'blurb' => 'The bar within thumb reach on a phone, and the card of sections '
                 . 'that rises above it. Four keys, fixed: the grid is written for '
                 . 'four and a fifth would break a layout nobody has measured.',
    ],
];

/* ---------------------------------------------------------------- reading */

/** A field as it was typed, trimmed. */
function chrome_post_text(mixed $value): string
{
    return trim((string)(is_scalar($value) ? $value : ''));
}

/** A field checked against what the model offers rather than trusted. */
function chrome_post_choice(mixed $value, array $allowed, string $fallback): string
{
    $value = chrome_post_text($value);

    return in_array($value, $allowed, true) ? $value : $fallback;
}

/** A textarea typed one entry per line, as a list with the blanks dropped. */
function chrome_post_lines(mixed $value): array
{
    $lines = preg_split('/\R/', (string)(is_scalar($value) ? $value : '')) ?: [];

    return array_values(array_filter(array_map('trim', $lines),
                                     static fn(string $l): bool => $l !== ''));
}

/**
 * One band of link rows, rebuilt from the form.
 *
 * The id travels as a hidden field so a row keeps the id it was minted with
 * through a reorder -- contract_identify_rows() only fills an id that is
 * empty, which is what makes an id permanent once a row has one.
 */
function chrome_link_rows_from_post(string $band): array
{
    $rows = [];

    foreach ((array)($_POST[$band]['items'] ?? []) as $row) {
        $rows[] = chrome_link_defaults([
            'id'     => chrome_post_text($row['id'] ?? ''),
            'target' => chrome_post_text($row['target'] ?? ''),
            'label'  => chrome_post_text($row['label'] ?? ''),
            'status' => chrome_post_text($row['status'] ?? 'shown'),
        ]);
    }

    return $rows;
}

/** One logo lockup, rebuilt from the form. */
function chrome_logo_from_post(string $part, array $current): array
{
    $logo = $current;

    $logo['alt']    = chrome_post_text($_POST[$part]['logo']['alt'] ?? '');
    $logo['sizes']  = chrome_post_text($_POST[$part]['logo']['sizes'] ?? '');
    $logo['width']  = (int)chrome_post_text($_POST[$part]['logo']['width'] ?? '0');
    $logo['height'] = (int)chrome_post_text($_POST[$part]['logo']['height'] ?? '0');

    foreach (['light', 'dark'] as $mode) {
        foreach (['src', 'srcset', 'webp'] as $field) {
            $logo[$mode][$field] = chrome_post_text(
                $_POST[$part]['logo'][$mode][$field] ?? '');
        }
    }

    return $logo;
}

/** The header band, over what is stored. */
function chrome_header_from_post(array $data): array
{
    $data['header']['brand_label'] = chrome_post_text($_POST['header']['brand_label'] ?? '');
    $data['header']['logo']        = chrome_logo_from_post('header', $data['header']['logo']);
    $data['header']['nav']['items'] = chrome_link_rows_from_post('nav');

    return $data;
}

/** The footer band, over what is stored. */
function chrome_footer_from_post(array $data): array
{
    $footer = $data['footer'];

    $footer['brand_label'] = chrome_post_text($_POST['footer']['brand_label'] ?? '');
    $footer['logo']        = chrome_logo_from_post('footer', $footer['logo']);
    $footer['tagline']     = chrome_post_text($_POST['footer']['tagline'] ?? '');
    $footer['description'] = chrome_post_text($_POST['footer']['description'] ?? '');

    $footer['links']['heading']        = chrome_post_text($_POST['links']['heading'] ?? '');
    $footer['links']['items']          = chrome_link_rows_from_post('links');
    $footer['services']['heading']     = chrome_post_text($_POST['services']['heading'] ?? '');
    $footer['services']['index_label'] = chrome_post_text($_POST['services']['index_label'] ?? '');
    $footer['contact']['heading']      = chrome_post_text($_POST['contact']['heading'] ?? '');
    $footer['legal']['items']          = chrome_link_rows_from_post('legal');

    $footer['copyright']['name']   = chrome_post_text($_POST['copyright']['name'] ?? '');
    $footer['copyright']['rights'] = chrome_post_text($_POST['copyright']['rights'] ?? '');

    $rows = [];
    foreach ((array)($_POST['contact']['items'] ?? []) as $row) {
        $rows[] = chrome_contact_defaults([
            'id'     => chrome_post_text($row['id'] ?? ''),
            'kind'   => chrome_post_text($row['kind'] ?? ''),
            'label'  => chrome_post_text($row['label'] ?? ''),
            'lines'  => chrome_post_lines($row['lines'] ?? ''),
            'note'   => chrome_post_text($row['note'] ?? ''),
            'status' => chrome_post_text($row['status'] ?? 'shown'),
        ]);
    }
    $footer['contact']['items'] = $rows;

    $data['footer'] = $footer;

    return $data;
}

/** The dock band, over what is stored. */
function chrome_dock_from_post(array $data): array
{
    $data['dock']['menu_label'] = chrome_post_text($_POST['dock']['menu_label'] ?? '');

    $panel = [];
    foreach ((array)($_POST['panel']['items'] ?? []) as $row) {
        $panel[] = chrome_panel_defaults([
            'id'          => chrome_post_text($row['id'] ?? ''),
            'target'      => chrome_post_text($row['target'] ?? ''),
            'label'       => chrome_post_text($row['label'] ?? ''),
            'description' => chrome_post_text($row['description'] ?? ''),
            'status'      => chrome_post_text($row['status'] ?? 'shown'),
        ]);
    }
    $data['dock']['panel']['items'] = $panel;

    $bar = [];
    foreach ((array)($_POST['bar']['items'] ?? []) as $row) {
        $bar[] = chrome_bar_defaults([
            'id'       => chrome_post_text($row['id'] ?? ''),
            'target'   => chrome_post_text($row['target'] ?? ''),
            'label'    => chrome_post_text($row['label'] ?? ''),
            'icon'     => chrome_post_text($row['icon'] ?? ''),
            'emphasis' => chrome_post_text($row['emphasis'] ?? 'plain'),
        ]);
    }
    $data['dock']['bar']['items'] = $bar;

    return $data;
}

/**
 * Add, remove, reorder, or copy the contact rows in.
 *
 * Returns the changed document and what to tell the person who pressed the
 * button, or null when the press cannot be honoured. NOTHING HERE SAVES: every
 * one of these submits the form, changes the rows in it, and redraws — so
 * anything typed and not yet saved survives being reordered, and pressing Add
 * writes nothing to the live site.
 */
function chrome_apply_row_action(array $data, string $do): ?array
{
    /* Where each band's rows live, and what a new one starts as. The dock bar
       is absent on purpose: it is exactly CHROME_BAR_SLOTS keys, that number
       is code, and its rows reorder rather than come and go. */
    $bands = [
        'nav'     => [['header', 'nav', 'items'],     'chrome_link_defaults', 'link'],
        'links'   => [['footer', 'links', 'items'],   'chrome_link_defaults', 'link'],
        'legal'   => [['footer', 'legal', 'items'],   'chrome_link_defaults', 'link'],
        'contact' => [['footer', 'contact', 'items'], 'chrome_contact_defaults', 'detail'],
        'panel'   => [['dock', 'panel', 'items'],     'chrome_panel_defaults', 'section'],
        'bar'     => [['dock', 'bar', 'items'],       '', ''],
    ];

    if ($do === 'contact-import') {
        $data['footer']['contact']['items'] = chrome_contact_import();

        return [chrome_identify($data),
                'Filled the contact rows in from the Contact page. Nothing is written '
                . 'to the site until you save — read them over first, and add any '
                . 'wording of their own they should carry.'];
    }

    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    if (!preg_match('/^([a-z]+)-(add|remove|up|down)$/', $verb, $m)
        || !isset($bands[$m[1]])
    ) {
        return null;
    }

    [$path, $factory, $noun] = $bands[$m[1]];
    $rows = $data[$path[0]][$path[1]][$path[2]];

    if ($m[2] === 'add' || $m[2] === 'remove') {
        /* The dock bar's four keys are the grid, and the grid is code. */
        if ($factory === '') {
            return null;
        }
    }

    if ($m[2] === 'add') {
        /* A NEW ROW ARRIVES HIDDEN. It has nothing in it yet, and an empty
           entry appearing in the navigation of every page of the site the
           moment somebody presses Add is not what pressing Add means. */
        $rows[] = $factory(['status' => 'hidden']);
        $data[$path[0]][$path[1]][$path[2]] = $rows;

        return [chrome_identify($data),
                'Added a ' . $noun . '. It is hidden until you show it — fill it in, '
                . 'then save.'];
    }

    $out = admin_move_row($rows, $m[2], $index);
    if ($out === null) {
        return null;
    }
    $data[$path[0]][$path[1]][$path[2]] = $out[0];

    return [$data, $out[1]];
}

/* --------------------------------------------------------------- fields */

/**
 * Every destination a link row may be pointed at, as the picker draws them.
 *
 * Keyed by target, valued by the label to show. Built from seo_pages() rather
 * than from chrome_targets() directly, for one reason: a page's name here has
 * to be the name a VISITOR sees, and that is its breadcrumb — the field edited
 * on the SEO screen — falling back to the constant. Offering "Contact" in the
 * picker while the site draws "Contact Us" is a small lie that a person would
 * then try to fix by typing a label.
 *
 * A HIDDEN SERVICE IS STILL OFFERED, and says so. Hiding one takes its page
 * out of the sitemap and the renderer skips any link to it, so the row would
 * simply not draw — but a row that vanished from a nav with no explanation is
 * worse than one that says why.
 */
function chrome_target_options(): array
{
    static $options = null;

    if ($options !== null) {
        return $options;
    }

    $options = [];

    foreach (seo_pages() as $key => $page) {
        /* The 404 has no address of its own — it is served at every address
           that does not exist — so nothing may be pointed at it. */
        if ($key === 'notfound' || $page['route'] === '') {
            continue;
        }

        $name = chrome_post_text($page['meta']['breadcrumb'] ?? '') ?: $page['name'];

        $options[$key] = $page['service']
            ? '    ' . $name . (($page['hidden'] ?? false) ? '  (hidden)' : '')
            : $name;
    }

    return $options;
}

/**
 * The destination picker.
 *
 * A stored target the list no longer answers to is added to the list and
 * marked, rather than silently reset to the first option. The row round-trips,
 * the editor shows what is wrong, and chrome_validate() refuses the save until
 * somebody decides — which is the whole reason chrome_link_defaults() keeps an
 * unknown target instead of dropping it.
 */
function chrome_target_field(string $name, string $value): void
{
    $options = chrome_target_options();
    $missing = $value !== '' && !isset($options[$value]);
    ?>
        <label class="admin__field">
          <span class="admin__label">Goes to</span>
          <select class="admin__input" name="<?= h($name) ?>" required>
            <option value=""<?= $value === '' ? ' selected' : '' ?>>Pick a page…</option>
<?php if ($missing): ?>
            <option value="<?= h($value) ?>" selected><?= h($value) ?> — this page no longer exists</option>
<?php endif; ?>
<?php foreach ($options as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $key === $value ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
<?php if ($missing): ?>
          <span class="admin__hint">Pick another page, or remove this row.</span>
<?php endif; ?>
        </label>
    <?php
}

/** One line of text. */
function chrome_text_field(string $name, string $label, string $value,
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
function chrome_area_field(string $name, string $label, string $value,
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
function chrome_lines_field(string $name, string $label, array $items, string $hint): void
{
    chrome_area_field($name, $label, implode("\n", $items), $hint,
                      max(2, min(8, count($items) + 1)));
}

/**
 * A choice between named options.
 *
 * A <select> and not radio buttons or a checkbox, for the reason every yes/no
 * in this admin is a <select>: a checkbox measured under the 24x24 minimum
 * (WCAG 2.2 SC 2.5.8) the last time one was tried here — and, for a status,
 * because an unticked box is not posted at all, so "off" and "never arrived"
 * would be the same thing on the receiving side.
 */
function chrome_choice_field(string $name, string $label, string $value,
                             array $options, string $hint = ''): void
{
    ?>
      <label class="admin__field">
        <span class="admin__label"><?= h($label) ?></span>
        <select class="admin__input" name="<?= h($name) ?>">
<?php foreach ($options as $option => $text): ?>
          <option value="<?= h((string)$option) ?>"<?= (string)$option === $value ? ' selected' : '' ?>><?= h((string)$text) ?></option>
<?php endforeach; ?>
        </select>
<?php if ($hint !== ''): ?>
        <span class="admin__hint"><?= h($hint) ?></span>
<?php endif; ?>
      </label>
    <?php
}

/** One dock-key icon picker, with the live preview beside it. */
function chrome_icon_field(string $name, string $value): void
{
    ?>
        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="<?= h($name) ?>">
<?php foreach (CHROME_BAR_ICONS as $icon => $label): ?>
            <option value="<?= h($icon) ?>"<?= $value === $icon ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <div class="admin__field">
          <span class="admin__label">As it will look</span>
          <p class="admin-card__icon">
            <?= $value !== '' ? admin_icon($value, 'icon') : '' ?>
          </p>
          <span class="admin__hint">Save to change what is drawn here.</span>
        </div>
    <?php
}

/** One logo lockup's fields — the same three slots in the header and footer. */
function chrome_logo_fields(string $part, array $logo, bool $responsive): void
{
    ?>
    <div class="admin__grid">
      <?php chrome_text_field($part . '[logo][alt]', 'Alt text', (string)$logo['alt'],
          'Read aloud where the picture cannot be seen. It describes the logo; '
          . 'where the link GOES is the description above.', false, true); ?>
      <?php chrome_text_field($part . '[logo][width]', 'Width', (string)$logo['width'],
          'The file\'s own pixel width. It reserves the space so the page does not jump.'); ?>
      <?php chrome_text_field($part . '[logo][height]', 'Height', (string)$logo['height']); ?>
<?php if ($responsive): ?>
      <?php chrome_text_field($part . '[logo][sizes]', 'Displayed size',
          (string)$logo['sizes'],
          'How wide the lockup is drawn, per screen width — a CSS “sizes” list.', true); ?>
<?php endif; ?>
    </div>

<?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $title): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h($title) ?></strong>
          <span class="admin-card__value"><?= h((string)$logo[$mode]['src']) ?></span>
        </span>
      </div>
      <div class="admin__grid">
        <?php chrome_text_field($part . "[logo][$mode][src]", 'Picture',
            (string)$logo[$mode]['src'],
            'A path on the public site, like /assets/images/logo/logo-light-360.png. '
            . 'Another site\'s address is refused.', true, true); ?>
<?php if ($responsive): ?>
        <?php chrome_text_field($part . "[logo][$mode][srcset]", 'Other widths',
            (string)$logo[$mode]['srcset'],
            'Comma-separated, each “path 360w”. Leave empty to draw one width.', true); ?>
<?php endif; ?>
        <?php chrome_text_field($part . "[logo][$mode][webp]", 'WebP',
            (string)$logo[$mode]['webp'],
            $responsive
                ? 'The same widths as WebP files, in the same form. Browsers that can '
                  . 'read them take these instead.'
                : 'The same picture as a WebP file. Browsers that can read it take this '
                  . 'instead.', true); ?>
      </div>
    </div>
<?php endforeach; ?>
    <?php
}

/**
 * What a link row is called in its collapsed head.
 *
 * The typed label if there is one, and otherwise the name of the page it
 * points at -- which is what the site will draw, so the row reads the same
 * collapsed as it will on the page. A row with neither falls through to
 * admin_card_head()'s "Untitled link".
 */
function chrome_row_preview(array $row): string
{
    $label = trim((string)($row['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return trim(chrome_target_options()[$row['target'] ?? ''] ?? '');
}

/**
 * One line saying what is in a part, for the index.
 *
 * Counts what a VISITOR would see, not what is stored: a hidden row is kept
 * and does not render, so counting it would report a nav of six where the site
 * draws five. Every count in this admin is drawn that way.
 */
function chrome_part_summary(array $data, string $part): string
{
    $n = static fn(int $count, string $one, string $many): string =>
        $count . ' ' . ($count === 1 ? $one : $many);

    return match ($part) {
        'header' => $n(count(chrome_rows_shown($data['header']['nav']['items'])),
                       'navigation link', 'navigation links'),
        'footer' => $n(count(chrome_rows_shown($data['footer']['links']['items'])),
                       'quick link', 'quick links')
                  . ', ' . $n(count(chrome_rows_shown($data['footer']['contact']['items'])),
                              'contact row', 'contact rows')
                  . ', ' . $n(count(chrome_rows_shown($data['footer']['legal']['items'])),
                              'link in the bottom bar', 'links in the bottom bar'),
        'dock'   => $n(count(chrome_rows_shown($data['dock']['panel']['items'])),
                       'section in the panel', 'sections in the panel')
                  . ', ' . $n(count($data['dock']['bar']['items']), 'key', 'keys')
                  . ' in the bar',
    };
}

/**
 * The standing notice about the footer's contact rows.
 *
 * A NOTICE, NEVER A REFUSAL, and it is drawn on every render rather than after
 * a save — the difference it reports is a state of the two documents, not
 * something that just happened. admin_standing_notice() is the plain one and
 * this is the same shape with a list under it.
 *
 * IT ONLY EVER LOOKS ONE WAY: what the footer says that the Contact page does
 * not. The other direction is not drift, it is what a footer is — see
 * chrome_contact_drift() in lib/contract.php.
 */
function chrome_drift_notice(array $drift, bool $linked): void
{
    if ($drift === []) {
        admin_standing_notice(
            'These are the footer\'s own contact details, not a copy of the Contact '
            . 'page\'s — and everything in them is on the Contact page too.');

        return;
    }
    ?>
    <div class="admin__notice admin__notice--warn">
      <p class="admin__notice-line"><?= admin_icon('info-circle', 'icon icon--sm') ?>
         <strong>The footer says
         <?= count($drift) === 1 ? 'something' : count($drift) . ' things' ?>
         the Contact page does not.</strong></p>
      <ul>
<?php foreach ($drift as $row): ?>
        <li>
          <?= h(CHROME_CONTACT_KINDS[$row['kind']] ?? $row['kind']) ?><?=
            $row['label'] !== '' ? h(' — ' . $row['label']) : '' ?>:
          <strong><?= h($row['value']) ?></strong>
        </li>
<?php endforeach; ?>
      </ul>
      <p>
        This is not necessarily wrong. The footer\'s contact rows are its own: the
        Contact page holds every detail in full, and a footer holds the part worth
        putting in a footer, worded to suit it. Nothing here refuses a save over it.
      </p>
      <p class="admin__fineprint">
        It is worth a look when an office has moved or a number has changed, because
        this is the only place that difference is visible.
<?php if ($linked): ?>
        The footer\'s rows are on the <a href="<?= h(admin_url('chrome', ['part' => 'footer'])) ?>">footer
        screen</a>; the details they are compared against are on the
        <a href="<?= h(admin_url('contact')) ?>">Contact page</a>.
<?php else: ?>
        They are compared against the <a href="<?= h(admin_url('contact')) ?>">Contact page</a>,
        and <strong>Copy from the Contact page</strong> below fills these rows in from it.
<?php endif; ?>
      </p>
    </div>
    <?php
}

/* ------------------------------------------------------------ which screen */

$part   = chrome_post_choice($_GET['part'] ?? '', CHROME_PARTS, '');
$screen = $part === '' ? 'index' : $part;

$data    = chrome_load();
$errors  = [];
$pending = '';

/* ----------------------------------------------------------------- saving */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $screen !== 'index') {
    admin_check_csrf();

    $do = (string)($_POST['do'] ?? 'save');

    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
    } else {
        /* ONLY THIS PART IS REBUILT FROM THE FORM. The other two were never in
           it, and chrome_edit() merges them back from the file under the lock
           — so saving the header cannot empty the footer, and two people on
           two screens cannot lose each other's work. */
        $posted = match ($screen) {
            'header' => chrome_header_from_post($data),
            'footer' => chrome_footer_from_post($data),
            'dock'   => chrome_dock_from_post($data),
        };

        if ($do === 'save') {
            /* NORMALISED BEFORE IT IS JUDGED, because normalising is what
               decides what would actually be stored: a logo pointing at
               another site is emptied by contract_safe_image_path(), a bar of
               six keys is cut to four, a contact kind nothing offers falls
               back. Validating the raw POST would pass a document and then
               store a different one. */
            $posted = chrome_identify(chrome_normalise($posted));
            $errors = chrome_validate($posted, $screen);

            if (!$errors) {
                $saved = chrome_edit(static fn(array $was): array => array_replace(
                    $was, [$screen => $posted[$screen]]));

                if ($saved) {
                    admin_redirect('chrome',
                        'Saved the ' . CHROME_PART_SCREENS[$screen]['label'] . '.',
                        ['part' => $screen]);
                }
                $errors[] = 'Could not write content/chrome.json. Check the file is '
                          . 'writable by PHP.';
            }
            $data = $posted;
        } else {
            $applied = chrome_apply_row_action($posted, $do);
            $data    = $applied[0] ?? $posted;
            $pending = $applied[1] ?? '';
        }
    }
}

/* ------------------------------------------------------------------ index */

if ($screen === 'index') {
    $drift = chrome_contact_drift($data, contact_load());

    admin_head('chrome', $user,
        'Everything that wraps every page: the logo and the navigation at the top, '
        . 'the four columns at the bottom, and the bar a phone shows within thumb '
        . 'reach. Editing <code>content/chrome.json</code>.',
        ['band-parts' => 'The three parts', 'band-derived' => 'What is not here']);

    admin_notices($errors);
    chrome_drift_notice($drift, true);
    ?>

<section class="admin__block" id="band-parts">
  <?php admin_band_head('The three parts',
      'Each is one screen, because each is a form of its own — and a form that '
      . 'outgrows what PHP will accept loses its tail in silence.'); ?>

<?php foreach (CHROME_PARTS as $key): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h(CHROME_PART_SCREENS[$key]['title']) ?></strong>
          <span class="admin-card__value"><?= h(chrome_part_summary($data, $key)) ?></span>
        </span>
        <a class="btn btn--secondary" href="<?= h(admin_url('chrome', ['part' => $key])) ?>">
          Edit
        </a>
      </div>
      <p class="admin__fineprint"><?= h(CHROME_PART_SCREENS[$key]['blurb']) ?></p>
    </div>
<?php endforeach; ?>
</section>

<section class="admin__block" id="band-derived">
  <?php admin_band_head('What is not here, and why',
      'Two parts of the footer store nothing at all. They are read as the page '
      . 'renders, from the document that owns them, so there is one copy of each '
      . 'and neither can go stale.'); ?>

  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong>The services column</strong>
        <span class="admin-card__value">Read from the Services editor</span>
      </span>
      <a class="btn btn--secondary" href="<?= h(admin_url('services')) ?>">Services</a>
    </div>
    <p class="admin__fineprint">
      A service added there appears in the footer by itself, under the name it is
      given there, and a hidden one disappears from it. Only the column's heading
      is on the footer screen.
    </p>
  </div>

  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong>The social links</strong>
        <span class="admin-card__value">Read from the profiles on the SEO screen</span>
      </span>
      <a class="btn btn--secondary" href="<?= h(admin_url('seo', ['site' => 'identity'])) ?>">Profiles</a>
    </div>
    <p class="admin__fineprint">
      The same list a search engine is told is also this company. One address, in
      one place, so the footer and the structured data cannot disagree.
    </p>
  </div>
</section>
    <?php
    admin_foot();
    return;
}

/* ----------------------------------------------------------- the header */

if ($screen === 'header') {
    $header = $data['header'];

    admin_head('chrome', $user,
        'The top of every page: the logo, what a screen reader announces the logo '
        . 'link as, and the main navigation. <a href="' . h(admin_url('chrome'))
        . '">Back to Header &amp; Footer</a>.',
        ['band-brand' => 'The logo', 'band-nav' => 'The navigation'],
        ['form' => 'chrome-form', 'label' => 'Save the header',
         'discard' => admin_url('chrome', ['part' => 'header'])]);

    admin_notices($errors);

    if (!$errors && $pending !== '') {
        echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
    }
    ?>

<form class="admin__form" id="chrome-form" method="post" data-async
      action="<?= h(admin_url('chrome', ['part' => 'header'])) ?>">
  <?= admin_form_fields('chrome') ?>

  <?php /* Pressing Enter in a text field submits using the first submit button
           in the document, which would otherwise be "Add a link". This is that
           first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <fieldset class="admin__block" id="band-brand">
    <?php admin_band_head('The logo',
        'Two lockups, one per colour mode, switched by CSS rather than by the '
        . 'operating system — so a visitor who picks the other mode with the toggle '
        . 'gets the right one. The link goes to the home page and always will.'); ?>

    <div class="admin__grid">
      <?php chrome_text_field('header[brand_label]', 'What the logo link is called',
          (string)$header['brand_label'],
          'Announced in place of the picture, and it describes where the link GOES '
          . '— “Tech4TIME — home”, not just the company name.', true, true); ?>
    </div>

    <?php chrome_logo_fields('header', $header['logo'], true); ?>
  </fieldset>

  <fieldset class="admin__block" id="band-nav">
    <?php admin_band_head('The navigation',
        'The links across the top. Each picks a page rather than typing an address, '
        . 'so none of them can point at a page that does not exist. Six is what fits '
        . 'and stays legible; the rest of the site is reachable from the footer.',
        ['do' => 'nav-add:0', 'label' => 'Add a link', 'rows' => 'nav']); ?>

<?php foreach ($header['nav']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('nav', $i, count($header['nav']['items']), [
          'label'  => chrome_row_preview($row),
          'noun'   => 'link',
          'detail' => chrome_target_options()[$row['target']] ?? '',
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="nav[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php chrome_target_field("nav[items][$i][target]", (string)$row['target']); ?>
        <?php chrome_text_field("nav[items][$i][label]", 'Called', (string)$row['label'],
            'Leave empty to use whatever that page calls itself — which is its '
            . 'breadcrumb on the SEO screen, so renaming it there renames this too.'); ?>
        <?php admin_status_field("nav[items][$i][status]", (string)$row['status'], 'this link'); ?>
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

/* ----------------------------------------------------------- the footer */

if ($screen === 'footer') {
    $footer = $data['footer'];
    $drift  = chrome_contact_drift($data, contact_load());

    admin_head('chrome', $user,
        'The bottom of every page: the brand block, the four columns, and the bar '
        . 'under them. <a href="' . h(admin_url('chrome')) . '">Back to Header &amp; '
        . 'Footer</a>.',
        ['band-brand'     => 'The brand block',
         'band-links'     => 'Quick Links',
         'band-services'  => 'Services',
         'band-contact'   => 'Contact details',
         'band-bottom'    => 'The bottom bar'],
        ['form' => 'chrome-form', 'label' => 'Save the footer',
         'discard' => admin_url('chrome', ['part' => 'footer'])]);

    admin_notices($errors);

    if (!$errors && $pending !== '') {
        echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
    }
    ?>

<form class="admin__form" id="chrome-form" method="post" data-async
      action="<?= h(admin_url('chrome', ['part' => 'footer'])) ?>">
  <?= admin_form_fields('chrome') ?>

  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <fieldset class="admin__block" id="band-brand">
    <?php admin_band_head('The brand block',
        'The first column: the logo, the line under it, and the paragraph under '
        . 'that. The social icons beside them are NOT here — they are the profiles '
        . 'on the SEO screen, so there is one address for each.'); ?>

    <div class="admin__grid">
      <?php chrome_text_field('footer[brand_label]', 'What the logo link is called',
          (string)$footer['brand_label'], '', true, true); ?>
      <?php chrome_text_field('footer[tagline]', 'Tagline', (string)$footer['tagline'],
          'The short line directly under the logo.', true); ?>
      <?php chrome_area_field('footer[description]', 'Paragraph',
          (string)$footer['description'],
          'Two or three sentences. It is on every page, so it is the sentence the '
          . 'site repeats most often.'); ?>
    </div>

    <?php chrome_logo_fields('footer', $footer['logo'], false); ?>
  </fieldset>

  <fieldset class="admin__block" id="band-links">
    <?php admin_band_head('Quick Links',
        'The second column. Each row picks a page, the same way the navigation does.',
        ['do' => 'links-add:0', 'label' => 'Add a link', 'rows' => 'links']); ?>

    <div class="admin__grid">
      <?php chrome_text_field('links[heading]', 'Heading',
          (string)$footer['links']['heading'],
          'The column is labelled by this heading for a screen reader, so it cannot '
          . 'be empty.', false, true); ?>
    </div>

<?php foreach ($footer['links']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('links', $i, count($footer['links']['items']), [
          'label'  => chrome_row_preview($row),
          'noun'   => 'link',
          'detail' => chrome_target_options()[$row['target']] ?? '',
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="links[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php chrome_target_field("links[items][$i][target]", (string)$row['target']); ?>
        <?php chrome_text_field("links[items][$i][label]", 'Called', (string)$row['label'],
            'Leave empty to use whatever that page calls itself.'); ?>
        <?php admin_status_field("links[items][$i][status]", (string)$row['status'], 'this link'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <fieldset class="admin__block" id="band-services">
    <?php admin_band_head('Services',
        'The third column. Its rows are not here and cannot be: they are read from '
        . 'the Services editor as the page renders, so a service added there appears '
        . 'in the footer by itself and a hidden one disappears from it.'); ?>

    <div class="admin__grid">
      <?php chrome_text_field('services[heading]', 'Heading',
          (string)$footer['services']['heading'], '', false, true); ?>
      <?php chrome_text_field('services[index_label]', 'The link to all of them',
          (string)$footer['services']['index_label'],
          'The row above the list, pointing at the services index. It introduces the '
          . 'list rather than naming a page, which is why it is not that page\'s own '
          . 'name.', false, true); ?>
    </div>
  </fieldset>

  <fieldset class="admin__block" id="band-contact">
    <?php admin_band_head('Contact details',
        'The fourth column. These are the footer\'s OWN rows — the Contact page holds '
        . 'every detail in full, and a footer holds the part worth putting in one. '
        . 'Rows next to each other that are the same kind share one icon.',
        ['do' => 'contact-add:0', 'label' => 'Add a detail', 'rows' => 'contact']); ?>

    <?php chrome_drift_notice($drift, false); ?>

    <div class="admin__grid">
      <?php chrome_text_field('contact[heading]', 'Heading',
          (string)$footer['contact']['heading'], '', false, true); ?>
    </div>

    <p class="admin__fineprint">
      <button class="btn btn--secondary" type="submit" name="do" value="contact-import"
              data-rows="contact"
              data-confirm="Replace every contact row below with what the Contact page says? Nothing is saved until you press Save, but anything typed in these rows and not yet saved is lost.">
        Copy from the Contact page
      </button>
      Fills these rows in from the Contact page — every telephone, the email, every
      address, then the opening hours. It writes nothing to the site: read them over,
      add any wording of their own, and save.
    </p>

<?php foreach ($footer['contact']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('contact', $i, count($footer['contact']['items']), [
          'label'  => (string)$row['label'] !== '' ? (string)$row['label']
                      : (CHROME_CONTACT_KINDS[$row['kind']] ?? ''),
          'noun'   => 'detail',
          'detail' => implode('  ·  ', $row['lines']),
          'icon'   => CHROME_CONTACT_ICONS[$row['kind']] ?? '',
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="contact[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php chrome_choice_field("contact[items][$i][kind]", 'What this is',
            (string)$row['kind'], CHROME_CONTACT_KINDS,
            'Decides the icon, and whether the lines below become a dialling or an '
            . 'email link.'); ?>
        <?php chrome_text_field("contact[items][$i][label]", 'Labelled',
            (string)$row['label'],
            'The small heading above it — an office name, usually. Leave empty for '
            . 'a detail that needs none, like the email address.'); ?>
        <?php admin_status_field("contact[items][$i][status]", (string)$row['status'], 'this detail'); ?>
      </div>
      <div class="admin__grid">
        <?php chrome_lines_field("contact[items][$i][lines]", 'The details themselves',
            $row['lines'],
            'One per line. Three telephone numbers under one label are three lines.'); ?>
        <?php chrome_text_field("contact[items][$i][note]", 'Note under it',
            (string)$row['note'],
            'Smaller and italic — “Sunday – Thursday”, for instance.', true); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <fieldset class="admin__block" id="band-bottom">
    <?php admin_band_head('The bottom bar',
        'The line under the four columns. The YEAR is not here: it is stamped as the '
        . 'page renders, and a script corrects it in a tab left open across midnight '
        . 'on 31 December.',
        ['do' => 'legal-add:0', 'label' => 'Add a link', 'rows' => 'legal']); ?>

    <div class="admin__grid">
      <?php chrome_text_field('copyright[name]', 'Copyright name',
          (string)$footer['copyright']['name'],
          'Drawn as “© 2026 <name>. <rights>”.'); ?>
      <?php chrome_text_field('copyright[rights]', 'Rights sentence',
          (string)$footer['copyright']['rights']); ?>
    </div>

<?php foreach ($footer['legal']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('legal', $i, count($footer['legal']['items']), [
          'label'  => chrome_row_preview($row),
          'noun'   => 'link',
          'detail' => chrome_target_options()[$row['target']] ?? '',
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="legal[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php chrome_target_field("legal[items][$i][target]", (string)$row['target']); ?>
        <?php chrome_text_field("legal[items][$i][label]", 'Called', (string)$row['label'],
            'Leave empty to use whatever that page calls itself.'); ?>
        <?php admin_status_field("legal[items][$i][status]", (string)$row['status'], 'this link'); ?>
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

/* ------------------------------------------------------------- the dock */

$dock = $data['dock'];

admin_head('chrome', $user,
    'The small-screen navigation: a bar within thumb reach, and a card of sections '
    . 'that rises above it. It replaces the header navigation below 64em. '
    . '<a href="' . h(admin_url('chrome')) . '">Back to Header &amp; Footer</a>.',
    ['band-bar' => 'The four keys', 'band-panel' => 'The panel'],
    ['form' => 'chrome-form', 'label' => 'Save the dock',
     'discard' => admin_url('chrome', ['part' => 'dock'])]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}

admin_standing_notice(
    'Every key in the bar is a real link, so the dock still works with JavaScript '
    . 'switched off. Only the panel needs a script to open — which is why the footer '
    . 'carries the same links again.');
?>

<form class="admin__form" id="chrome-form" method="post" data-async
      action="<?= h(admin_url('chrome', ['part' => 'dock'])) ?>">
  <?= admin_form_fields('chrome') ?>

  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <fieldset class="admin__block" id="band-bar">
    <?php admin_band_head('The four keys',
        'Always four. The grid is written for four, and a fifth would break a layout '
        . 'nobody has measured — so these reorder rather than come and go. Their '
        . 'labels are typed rather than taken from the page, because “Profile” is '
        . 'what fits under a 44px key where “Company Profile” is what fits in a nav.'); ?>

    <div class="admin__grid">
      <?php chrome_text_field('dock[menu_label]', 'The menu button\'s label',
          (string)$dock['menu_label'],
          'The fifth key, which opens the panel. It is a button rather than a link '
          . 'and is not one of the four.', false, true); ?>
    </div>

<?php foreach ($dock['bar']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('bar', $i, count($dock['bar']['items']), [
          'label'    => (string)$row['label'],
          'noun'     => 'key',
          'detail'   => chrome_target_options()[$row['target']] ?? '',
          'icon'     => (string)$row['icon'],
          'controls' => ['up', 'down'],
      ]); ?>
      <input type="hidden" name="bar[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php chrome_target_field("bar[items][$i][target]", (string)$row['target']); ?>
        <?php chrome_text_field("bar[items][$i][label]", 'Called', (string)$row['label'],
            'One short word. It sits under the icon.', false, true); ?>
        <?php chrome_icon_field("bar[items][$i][icon]", (string)$row['icon']); ?>
        <?php chrome_choice_field("bar[items][$i][emphasis]", 'Emphasis',
            (string)$row['emphasis'], CHROME_BAR_EMPHASIS,
            'One key may be drawn on a filled disc. It is still a link.'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <fieldset class="admin__block" id="band-panel">
    <?php admin_band_head('The panel',
        'The card that rises above the bar. One row per section, each with a line '
        . 'saying what is there — which is the one place on the site those lines '
        . 'appear.',
        ['do' => 'panel-add:0', 'label' => 'Add a section', 'rows' => 'panel']); ?>

<?php foreach ($dock['panel']['items'] as $i => $row): ?>
    <div class="admin-card">
      <?php admin_card_head('panel', $i, count($dock['panel']['items']), [
          'label'  => chrome_row_preview($row),
          'noun'   => 'section',
          'detail' => (string)$row['description'],
          'status' => (string)$row['status'],
      ]); ?>
      <input type="hidden" name="panel[items][<?= $i ?>][id]" value="<?= h((string)$row['id']) ?>">
      <div class="admin__grid">
        <?php chrome_target_field("panel[items][$i][target]", (string)$row['target']); ?>
        <?php chrome_text_field("panel[items][$i][label]", 'Called', (string)$row['label'],
            'Leave empty to use whatever that page calls itself.'); ?>
        <?php admin_status_field("panel[items][$i][status]", (string)$row['status'], 'this section'); ?>
        <?php chrome_text_field("panel[items][$i][description]", 'The line under it',
            (string)$row['description'],
            'What is there, in a few words.', true); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <?= admin_form_tail() ?>
</form>
<?php
admin_foot();
