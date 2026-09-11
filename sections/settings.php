<?php
/**
 * Tech4TIME — the site's identity: the mark, the icons, the colours, the address.
 *
 * The four things every page of the public site depends on and no page owns.
 * Stored in content/settings.json; there is no database.
 *
 * Until this existed none of it could be changed without a developer: the logo
 * was twelve committed files and a Python script, the favicon another eight and
 * another script, the colours were tokens in a stylesheet, and where the
 * contact form's mail goes was a constant in the handler. Every one of them is
 * a thing a company changes and none of them is a thing a company should need a
 * deploy for.
 *
 * FIVE SCREENS, NOT ONE, AND THE SPLIT IS BY PART.
 *
 *   ?s=settings                 the index: the four parts, and what is set
 *   ?s=settings&part=logo       the wordmark, light and dark
 *   ?s=settings&part=icon       the square mark the favicons are made from
 *   ?s=settings&part=colour     the fourteen tokens, light and dark
 *   ?s=settings&part=mail       where the enquiry form sends
 *
 * Split for the reason ?s=chrome and ?s=seo are: max_input_vars defaults to
 * 1000 and PHP drops the tail of a larger POST in silence. The colour screen
 * alone is twenty-eight fields, and a form that is quietly truncated saves what
 * arrived and loses the rest — which looks exactly like a save that worked.
 *
 * THE READERS WERE CONVERTED BEFORE ANY OF THIS COULD WRITE. The document, the
 * road it travels and this shell landed first, on purpose: every consumer of
 * the logo, the icons and the colours had to be reading from here before a save
 * was allowed to change any of it, or the first save would have moved one of
 * the places a mark appears and left the other eight showing the old one.
 *
 * ONE SCREEN REFUSES; THE REST ONLY WARN. A colour pair below WCAG AA is turned
 * away by settings_validate(), because an unreadable site is not a matter of
 * taste. Everything else here -- a missing dark mark, a half-replaced pair, and
 * the icon or share card a new logo leaves behind -- is a standing notice,
 * because each is a legitimate answer that only the person who drew the mark
 * can judge.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/seo.php';   /* for the share card's state, read never written */

/**
 * What each part is called on the index, and what it holds.
 *
 * Beside the screens rather than in the contract: the contract says a document
 * has a 'colours' band, and what a person should read is "Brand colours". The
 * same reason CHROME_PART_SCREENS is here and not there.
 */
const SETTINGS_PART_SCREENS = [
    'logo' => [
        'title' => 'The logo',
        'blurb' => 'The wordmark in the header, the footer, the About page and this '
                 . 'panel — and the one a search engine is shown as the company\'s. '
                 . 'A light version and, if the mark needs one, a dark version.',
    ],
    'icon' => [
        'title' => 'The tab icon',
        'blurb' => 'One square mark, from which the server makes every favicon a '
                 . 'browser asks for and the tile a phone puts on its home screen. '
                 . 'Separate from the logo because a wordmark is illegible at '
                 . 'sixteen pixels.',
    ],
    'colour' => [
        'title' => 'Brand colours',
        'blurb' => 'The fourteen colours the whole site is drawn from, in both light '
                 . 'and dark mode. Every one of them is checked against the '
                 . 'readability standard before it can be saved.',
    ],
    'mail' => [
        'title' => 'Where enquiries go',
        'blurb' => 'The address the contact form sends to, and the subject line it '
                 . 'puts on the message.',
    ],
];

/**
 * One line saying what is set in a part, for the index.
 *
 * Says what is TRUE NOW rather than what the part is for — the blurb above
 * already says what it is for. "The mark that ships" and "Uploaded" are
 * different states and somebody arriving at this screen wants to know which
 * one they are in.
 */
function settings_part_summary(array $data, string $part): string
{
    return match ($part) {
        'logo' => (settings_logo_is_uploaded($data) ? 'An uploaded mark' : 'The mark that ships')
                . (settings_logo_is_shared($data)
                    ? ', used in both light and dark mode'
                    : ', with a separate dark version'),
        'icon' => trim((string)($data['icon']['master']['src'] ?? '')) === ''
                ? 'The icons that ship'
                : 'Generated from an uploaded mark',
        'colour' => count(SETTINGS_COLOURS['light']) . ' colours, light and dark'
                  . (settings_colours_changed($data) ? ', edited' : ', as they ship'),
        'mail' => (string)($data['contact']['mail_to'] ?? ''),
    };
}

/** True when any colour differs from the one the site ships with. */
function settings_colours_changed(array $data): bool
{
    foreach (['light', 'dark'] as $mode) {
        foreach (SETTINGS_COLOURS[$mode] as $token => $shipped) {
            if (($data['colours'][$mode][$token] ?? $shipped) !== $shipped) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The standing notices about the state of the identity.
 *
 * NOTICES, NEVER REFUSALS. All four of the things reported here are legitimate
 * answers that only the person who drew the mark can judge, and a settings
 * screen that refused a save over any of them would be unusable halfway through
 * replacing a pair of files.
 *
 * They are four because there are four ways an identity goes half-changed: no
 * dark mark at all, which renders the light one and is often right; one half
 * replaced and the other left holding the PREVIOUS mark, which renders two
 * different logos and is never right; and the two pictures a new logo leaves
 * behind, the tab icon and the share card, neither of which can be made from a
 * wordmark.
 *
 * Drawn on every render rather than after a save, because each reports a state
 * of the document rather than something that just happened.
 */
function settings_notices(array $data, array $seo, bool $linked): void
{
    if (settings_logo_is_shared($data)) {
        admin_standing_notice(
            'There is no separate dark-mode logo, so dark mode shows the light one. '
            . 'That is right for a mark that reads on both a pale and a dark '
            . 'ground, and wrong for one drawn in dark ink — which would be very '
            . 'nearly invisible.');
    }

    if (settings_logo_is_mismatched($data)) {
        ?>
        <div class="admin__notice admin__notice--warn">
          <p class="admin__notice-line"><?= admin_icon('info-circle', 'icon icon--sm') ?>
             <strong>One half of the logo was replaced and the other was not.</strong></p>
          <p>
            The two halves are separate pictures, so the site is showing the new
            mark in one colour mode and the previous one in the other. Whoever
            is reading this is in one mode and will not see the other &mdash;
            which is why it is said here rather than left to be noticed.
          </p>
<?php if ($linked): ?>
          <p class="admin__fineprint">
            Replace the other half on the
            <a href="<?= h(admin_url('settings', ['part' => 'logo'])) ?>">logo</a>
            screen, or clear it: an empty half draws the one that is set, which
            is right for a mark that reads on both grounds.
          </p>
<?php endif; ?>
        </div>
        <?php
    }

    if (settings_icon_is_stale($data)) {
        ?>
        <div class="admin__notice admin__notice--warn">
          <p class="admin__notice-line"><?= admin_icon('info-circle', 'icon icon--sm') ?>
             <strong>The logo has been replaced and the tab icon has not.</strong></p>
          <p>
            They are separate pictures on purpose: the logo is a wordmark about
            three times as wide as it is tall, and a favicon is a sixteen-pixel
            square. Squeezing one into the other gives a smear. So changing the
            logo cannot change what a browser tab shows, and the tab is still
            showing the mark that ships.
          </p>
<?php if ($linked): ?>
          <p class="admin__fineprint">
            Upload a square mark on the
            <a href="<?= h(admin_url('settings', ['part' => 'icon'])) ?>">tab icon</a>
            screen and every favicon is made from it.
          </p>
<?php endif; ?>
        </div>
        <?php
    }

    if (settings_share_is_stale($data, $seo)) {
        ?>
        <div class="admin__notice admin__notice--warn">
          <p class="admin__notice-line"><?= admin_icon('info-circle', 'icon icon--sm') ?>
             <strong>The share card still carries the previous mark.</strong></p>
          <p>
            That is the picture somebody sees when a link to this site is pasted
            into a chat or posted somewhere — 1200 by 630, with the mark drawn
            into it. Nothing here can redraw it: the card has type set beside the
            logo, and a card this panel generated would be a worse card. It is
            uploaded, like the logo is.
          </p>
<?php if ($linked): ?>
          <p class="admin__fineprint">
            Replace it on the
            <a href="<?= h(admin_url('seo', ['site' => 'share'])) ?>">share card</a>
            screen. Until then every link shared from the site shows the old one,
            which is visible in somebody else's window and nowhere on the site.
          </p>
<?php endif; ?>
        </div>
        <?php
    }
}

/**
 * Take whatever pictures were attached, and put each on the half it belongs to.
 *
 * A picture is stored here and sent to the live site immediately rather than at
 * save time, for the reason every other screen does it: the operator finds out
 * at once if the channel is broken, while they still know what they were doing.
 *
 * An orphan is possible — a mark uploaded and the save then abandoned — and is
 * the right trade. It costs disk; the other order costs an edit.
 */
function settings_take_uploads(array $data, array &$errors): array
{
    foreach (admin_uploaded_files() as [$band, $_index, $file]) {
        if (!in_array($band, ['light', 'dark'], true)) {
            continue;
        }

        $where  = $band === 'light' ? 'The light-mode logo' : 'The dark-mode logo';
        $stored = upload_accept($file, 'settings.logo');

        if (isset($stored['error'])) {
            $errors[] = $where . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = $where . ': ' . $sent;
            continue;
        }

        $data['logo'][$band] = contract_image_defaults($stored);
    }

    return $data;
}

/**
 * Take the square master, make every icon from it, and send the lot.
 *
 * ONE UPLOAD, NINE FILES. The master itself is stored -- it is what a
 * regeneration would start from, and what the screen shows back -- and the
 * eight the server draws from it go with it. Every one of them travels before
 * the document names any of them, because a <link rel="icon"> pointing at a
 * file the live site has not got is a tab with no mark in it.
 */
function settings_take_icon(array $data, array &$errors): array
{
    foreach (admin_uploaded_files() as [$band, $_index, $file]) {
        if ($band !== 'icon') {
            continue;
        }

        $stored = upload_accept($file, 'settings.icon');

        if (isset($stored['error'])) {
            $errors[] = 'The tab icon: ' . $stored['error'];
            continue;
        }

        $bytes = @file_get_contents(UPLOAD_DIR . '/' . basename((string)$stored['src']));

        if ($bytes === false) {
            $errors[] = 'The tab icon: the picture was not where it had just been written.';
            continue;
        }

        $made = settings_icon_generate($bytes);

        if (isset($made['error'])) {
            $errors[] = 'The tab icon: ' . $made['error'];
            continue;
        }

        $sent = admin_send_files([...contract_image_paths($stored), ...array_values($made)]);

        if ($sent !== '') {
            $errors[] = 'The tab icon: ' . $sent;
            continue;
        }

        $data['icon'] = ['master' => contract_image_defaults($stored),
                         'generated' => $made];
    }

    return $data;
}

/**
 * What each colour token is FOR, in words somebody picking one can use.
 *
 * Beside the screen and not in the contract, for the reason
 * SETTINGS_PART_SCREENS is here: the contract says a document holds a token
 * called 'silver-accent-mid', and what a person needs to read is "the middle
 * of the gradient buttons are filled with".
 *
 * Every one of the fourteen is here. A picker with an unlabelled box in it is
 * a picker nobody will touch.
 */
const SETTINGS_COLOUR_LABELS = [
    'bg-base'             => ['The page', 'Behind everything.'],
    'bg-surface'          => ['Cards and panels', 'The ground most blocks sit on.'],
    'bg-elevated'         => ['Raised surfaces', 'Menus, dialogs, anything lifted off the page.'],
    'text-primary'        => ['Body text', 'Headings and paragraphs.'],
    'text-secondary'      => ['Subtext', 'The line under a heading.'],
    'text-muted'          => ['Captions', 'Fine print and placeholder text.'],
    'border-subtle'       => ['Hairlines', 'Dividers and card edges. Decorative: no contrast bar.'],
    'border-strong'       => ['Control edges', 'The outline of a field or a button.'],
    'silver-accent-start' => ['Gradient, start', 'The light end of the brand sweep.'],
    'silver-accent-mid'   => ['Gradient, middle', 'What a primary button is mostly filled with.'],
    'silver-accent-end'   => ['Gradient, end', 'The dark end. Decorative: no contrast bar.'],
    'accent-text'         => ['Links and accents', 'Link text and icon strokes.'],
    'focus-ring'          => ['Focus ring', 'The outline around whatever the keyboard is on.'],
    'on-accent'           => ['Ink on the gradient', 'The label on a primary button.'],
];

/**
 * What each pair currently measures, so the screen can show it.
 *
 * THE READOUT IS THE POINT OF THE SCREEN. A hex value tells nobody whether the
 * result is readable; "4.83:1, needs 4.5" does. It is worked out on the server
 * because the answer must be the same one the save is judged by — a number
 * drawn by a script and a refusal decided in PHP that disagreed would be worse
 * than no number at all.
 */
function settings_contrast_report(array $colours): array
{
    $rows = [];

    foreach (SETTINGS_CONTRAST_PAIRS as $pair) {
        $worst = null;

        foreach ($pair['on'] as $ground) {
            $ratio = contract_contrast_ratio(
                (string)($colours[$pair['fg']] ?? ''), (string)($colours[$ground] ?? ''));

            if ($worst === null || $ratio < $worst['ratio']) {
                $worst = ['ratio' => $ratio, 'on' => $ground];
            }
        }

        $rows[] = [
            'fg'    => $pair['fg'],
            'on'    => $worst['on'],
            'role'  => $pair['role'],
            'ratio' => $worst['ratio'],
            'needs' => (float)$pair['ratio'],
            'ok'    => $worst['ratio'] + 0.005 >= $pair['ratio'],
        ];
    }

    return $rows;
}

/* ------------------------------------------------------------ which screen */

$part   = in_array((string)($_GET['part'] ?? ''), SETTINGS_PARTS, true)
        ? (string)$_GET['part'] : '';
$screen = $part === '' ? 'index' : $part;

$data   = settings_load();
$seo    = seo_load();     /* read-only: one notice here is about the share card */
$errors = [];

/* ----------------------------------------------------------------- saving */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $screen === 'mail') {
    admin_check_csrf();

    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
    } else {
        $posted = $data;
        $posted['contact'] = [
            'mail_to'      => trim((string)($_POST['contact']['mail_to'] ?? '')),
            'mail_subject' => trim((string)($_POST['contact']['mail_subject'] ?? '')),
        ];

        /* JUDGED BEFORE IT IS NORMALISED, WHICH IS THE OPPOSITE OF THE OTHER
           THREE, and the difference matters. settings_normalise() falls back
           to the shipped address for anything that is not one -- which is
           right for a document arriving over the wire, where the alternative
           is a contact form posting into nowhere. It is wrong for somebody
           typing: a save that silently replaced what they wrote with
           info@tech4time.bd would look exactly like a save that worked. */
        $errors = settings_validate($posted, 'mail');

        if (!$errors) {
            $posted = settings_normalise($posted);

            $saved = settings_edit(static fn(array $was): array => array_replace(
                $was, ['contact' => $posted['contact']]));

            if ($saved) {
                admin_redirect('settings', 'Saved where enquiries go.',
                               ['part' => 'mail']);
            }
            $errors[] = 'Could not write content/settings.json. Check the file is '
                      . 'writable by PHP.';
        }

        $data = $posted;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $screen === 'colour') {
    admin_check_csrf();

    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
    } else {
        $posted = $data;

        foreach (['light', 'dark'] as $mode) {
            foreach (SETTINGS_COLOURS[$mode] as $token => $_shipped) {
                $posted['colours'][$mode][$token] =
                    (string)($_POST['colours'][$mode][$token] ?? '');
            }
        }

        /* NORMALISED BEFORE IT IS JUDGED. A value that is not six hex digits
           falls back to the shipped one here, so what the gate measures is
           what would actually be stored -- judging the raw POST would refuse a
           palette nobody was trying to save, or pass one nobody could read. */
        $posted = settings_normalise($posted);
        $errors = settings_validate($posted, 'colour');

        if (!$errors) {
            $saved = settings_edit(static fn(array $was): array => array_replace(
                $was, ['colours' => $posted['colours']]));

            if ($saved) {
                admin_redirect('settings', 'Saved the colours.', ['part' => 'colour']);
            }
            $errors[] = 'Could not write content/settings.json. Check the file is '
                      . 'writable by PHP.';
        }

        $data = $posted;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $screen === 'icon') {
    admin_check_csrf();

    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
    } else {
        $posted = settings_take_icon($data, $errors);

        if (!$errors) {
            $posted = settings_normalise($posted);

            $saved = settings_edit(static fn(array $was): array => array_replace(
                $was, ['icon' => $posted['icon']]));

            if ($saved) {
                admin_redirect('settings', 'Saved the tab icon.', ['part' => 'icon']);
            }
            $errors[] = 'Could not write content/settings.json. Check the file is '
                      . 'writable by PHP.';
        }

        $data = $posted;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $screen === 'logo') {
    admin_check_csrf();

    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
    } else {
        /* ONLY THIS PART IS REBUILT FROM THE FORM. The other three were never
           in it, and settings_edit() merges them back from the file under the
           lock — so saving the logo cannot reset the colours, and two people on
           two screens cannot lose each other's work. */
        $posted = $data;

        foreach (['light', 'dark'] as $mode) {
            $posted['logo'][$mode] =
                admin_image_from_post($_POST['logo'][$mode] ?? []);
        }

        /* Uploads are applied AFTER the form is read back, so a picture chosen
           in this very request wins over the hidden inputs describing the one
           it replaces. */
        $posted = settings_take_uploads($posted, $errors);

        /* NORMALISED BEFORE IT IS JUDGED, because normalising decides what
           would actually be stored: a path this site will not serve is emptied
           here, and validating the raw POST would pass a document and then
           store a different one. */
        $posted = settings_normalise($posted);
        $errors = array_merge($errors, settings_validate($posted, 'logo'));

        if (!$errors) {
            $saved = settings_edit(static fn(array $was): array => array_replace(
                $was, ['logo' => $posted['logo']]));

            if ($saved) {
                admin_redirect('settings', 'Saved the logo.', ['part' => 'logo']);
            }
            $errors[] = 'Could not write content/settings.json. Check the file is '
                      . 'writable by PHP.';
        }

        $data = $posted;
    }
}

/* ------------------------------------------------------------------ index */

if ($screen === 'index') {
    admin_head('settings', $user,
        'The mark, the icons, the colours and the address behind every page of the '
        . 'site. None of it belongs to a single page, so none of it is on a page\'s '
        . 'own screen. Editing <code>content/settings.json</code>.',
        ['band-parts' => 'The four parts', 'band-elsewhere' => 'What is not here']);

    admin_notices($errors);
    settings_notices($data, $seo, true);
    ?>

<section class="admin__block" id="band-parts">
  <?php admin_band_head('The four parts',
      'Each is one screen, because each is a form of its own — and a form that '
      . 'outgrows what PHP will accept loses its tail in silence.'); ?>

<?php foreach (SETTINGS_PARTS as $key): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h(SETTINGS_PART_SCREENS[$key]['title']) ?></strong>
          <span class="admin-card__value"><?= h(settings_part_summary($data, $key)) ?></span>
        </span>
        <a class="btn btn--secondary" href="<?= h(admin_url('settings', ['part' => $key])) ?>">
          Open
        </a>
      </div>
      <p class="admin__fineprint"><?= h(SETTINGS_PART_SCREENS[$key]['blurb']) ?></p>
    </div>
<?php endforeach; ?>
</section>

<section class="admin__block" id="band-elsewhere">
  <?php admin_band_head('What is not here, and why',
      'Three things people look for on a screen like this one are edited '
      . 'elsewhere, each beside the thing it belongs with.'); ?>

  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong>The company name, description and social profiles</strong>
        <span class="admin-card__value">On the SEO screen</span>
      </span>
      <a class="btn btn--secondary"
         href="<?= h(admin_url('seo', ['site' => 'identity'])) ?>">Identity</a>
    </div>
    <p class="admin__fineprint">
      They are what a search engine is told the company is, and they sit with the
      rest of what it is told. The footer's social links read the same list, so
      there is one address in one place.
    </p>
  </div>

  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong>What the logo link says out loud</strong>
        <span class="admin-card__value">On the header and footer screens</span>
      </span>
      <a class="btn btn--secondary" href="<?= h(admin_url('chrome')) ?>">Header &amp; Footer</a>
    </div>
    <p class="admin__fineprint">
      The picture is here; the words a screen reader announces it as stay there,
      because the header's and the footer's are legitimately different sentences.
    </p>
  </div>

  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong>The logo files people download</strong>
        <span class="admin-card__value">On the Branding screen</span>
      </span>
      <a class="btn btn--secondary" href="<?= h(admin_url('branding')) ?>">Branding</a>
    </div>
    <p class="admin__fineprint">
      Those are a deliverable rather than something a page draws — full-size files
      with terms of use beside them — so they are kept where the terms are.
    </p>
  </div>
</section>
    <?php
    admin_foot();
    return;
}

/* ------------------------------------------------------------ one part */

$screens = SETTINGS_PART_SCREENS[$part];

/* ----------------------------------------------------------- the colours */

if ($screen === 'colour') {
    admin_head('settings', $user,
        $screens['blurb'] . ' <a href="' . h(admin_url('settings'))
        . '">Back to Settings</a>.',
        ['band-light' => 'Light mode', 'band-dark' => 'Dark mode',
         'band-readable' => 'What this palette measures'],
        ['form' => 'settings-form', 'label' => 'Save the colours',
         'discard' => admin_url('settings', ['part' => 'colour'])]);

    admin_notices($errors);

    admin_standing_notice(
        'A colour that would make something unreadable is refused, not warned about. '
        . 'That is the one refusal in this editor, and it is arithmetic rather than '
        . 'taste: WCAG says what the bar is, and text nobody can read is not a '
        . 'matter of opinion.');
    ?>

<form class="admin__form" id="settings-form" method="post" data-async
      action="<?= h(admin_url('settings', ['part' => 'colour'])) ?>">
  <?= admin_form_fields('settings') ?>

<?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $title): ?>
<section class="admin__block" id="band-<?= h($mode) ?>">
  <?php admin_band_head($title,
      $mode === 'light'
          ? 'What somebody sees by default, and what most visitors see.'
          : 'What a visitor whose device asks for dark mode sees, and what the '
            . 'theme switch draws.'); ?>

  <div class="admin__grid">
<?php foreach (SETTINGS_COLOUR_LABELS as $token => [$label, $hint]): ?>
    <label class="admin__field">
      <span class="admin__label"><?= h($label) ?></span>
      <input class="admin__input" type="color"
             name="colours[<?= h($mode) ?>][<?= h($token) ?>]"
             value="<?= h((string)$data['colours'][$mode][$token]) ?>">
      <span class="admin__hint">
        <code><?= h($token) ?></code> — <?= h($hint) ?>
      </span>
    </label>
<?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<section class="admin__block" id="band-readable">
  <?php admin_band_head('What this palette measures',
      'The worst ground each colour is drawn on, which is what decides it: a '
      . 'colour legible on two surfaces and not the third is illegible somewhere '
      . 'on the site. Saved values, not the boxes above — change one and save to '
      . 'see it move.'); ?>

<?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $title): ?>
  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong><?= h($title) ?></strong>
        <span class="admin-card__value">
<?php $report = settings_contrast_report($data['colours'][$mode]);
      $bad = count(array_filter($report, static fn(array $r): bool => !$r['ok'])); ?>
          <?= $bad === 0 ? 'Every pair meets AA'
              : $bad . ' pair' . ($bad === 1 ? '' : 's') . ' below AA' ?>
        </span>
      </span>
    </div>
    <p class="admin__fineprint">
<?php foreach ($report as $row): ?>
      <?= $row['ok'] ? '' : '✕ ' ?><?= h($row['fg']) ?> on <?= h($row['on']) ?>
      <strong><?= h(number_format($row['ratio'], 2)) ?>:1</strong>
      (needs <?= h(number_format($row['needs'], 1)) ?>)<?=
        $row === $report[count($report) - 1] ? '' : ' · ' ?>
<?php endforeach; ?>
    </p>
  </div>
<?php endforeach; ?>

  <p class="admin__fineprint">
    Two pairs carry no requirement and are not listed: the hairline dividers and
    the gradient's end stop. Neither ever sits under text, and asking anything of
    them would refuse a palette that is perfectly legible.
  </p>
</section>

  <?= admin_form_tail() ?>
</form>
    <?php
    admin_foot();
    return;
}

/* ------------------------------------------------------------- the icon */

if ($screen === 'icon') {
    admin_head('settings', $user,
        $screens['blurb'] . ' <a href="' . h(admin_url('settings'))
        . '">Back to Settings</a>.',
        ['band-icon' => 'The square mark'],
        ['form' => 'settings-form', 'label' => 'Save the tab icon',
         'discard' => admin_url('settings', ['part' => 'icon'])]);

    admin_notices($errors);
    settings_notices($data, $seo, false);
    ?>

<form class="admin__form" id="settings-form" method="post" data-async
      enctype="multipart/form-data"
      action="<?= h(admin_url('settings', ['part' => 'icon'])) ?>">
  <?= admin_form_fields('settings') ?>

<section class="admin__block" id="band-icon">
  <?php admin_band_head('The square mark',
      'One picture, square, and ideally the mark on its own rather than the '
      . 'wordmark — a logo three times as wide as it is tall is an illegible '
      . 'smear at sixteen pixels, which is why this is a separate upload.'); ?>

    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>The master</strong>
          <span class="admin-card__value">
            <?= trim((string)$data['icon']['master']['src']) === ''
                ? 'Not set — the icons that ship are being used'
                : 'Uploaded' ?>
          </span>
        </span>
      </div>
      <?php admin_image_fields('icon[master]', 'upload[icon][0]',
          $data['icon']['master'], 'square mark',
          'No square mark. Every browser tab and phone home screen shows the '
          . 'one that ships with the site.'); ?>
    </div>

    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>What gets made from it</strong>
          <span class="admin-card__value">
            <?= count(SETTINGS_ICON_SIZES) + 1 ?> files
          </span>
        </span>
      </div>
      <p class="admin__fineprint">
<?php foreach (SETTINGS_ICON_SIZES as $name => $spec): ?>
        <?= (int)$spec['size'] ?>px<?= $spec['pad'] > 0 ? ' (tile)' : '' ?><?=
            $name === array_key_last(SETTINGS_ICON_SIZES) ? '' : ' · ' ?>
<?php endforeach; ?>
        · <code>favicon.ico</code> holding
        <?= implode(', ', array_map(static fn(int $s): string => $s . 'px',
            SETTINGS_ICON_ICO)) ?>.
      </p>
      <p class="admin__fineprint">
        The four smallest are <strong>transparent</strong>, because a browser tab
        draws its own background and a mark with a plate behind it would be a
        rectangle floating in it. The three largest are <strong>tiles</strong> on a
        dark ground with room around them, because a phone home screen puts them
        against a photograph nobody can predict.
      </p>
    </div>
</section>

  <?= admin_form_tail() ?>
</form>
    <?php
    admin_foot();
    return;
}

/* ------------------------------------------------------------- the logo */

if ($screen === 'logo') {
    admin_head('settings', $user,
        $screens['blurb'] . ' <a href="' . h(admin_url('settings'))
        . '">Back to Settings</a>.',
        ['band-logo' => 'The mark'],
        ['form' => 'settings-form', 'label' => 'Save the logo',
         'discard' => admin_url('settings', ['part' => 'logo'])]);

    admin_notices($errors);
    settings_notices($data, $seo, false);
    ?>

<form class="admin__form" id="settings-form" method="post" data-async
      enctype="multipart/form-data"
      action="<?= h(admin_url('settings', ['part' => 'logo'])) ?>">
  <?= admin_form_fields('settings') ?>

<section class="admin__block" id="band-logo">
  <?php admin_band_head('The mark',
      'Drawn in the header and the footer of every page, on the About page, and '
      . 'in this panel\'s own rail — and named in the structured data a search '
      . 'engine reads. One upload reaches all of them.'); ?>

<?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $label): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h($label) ?></strong>
          <span class="admin-card__value">
<?php if (trim((string)$data['logo'][$mode]['src']) === ''): ?>
            Not set — dark mode shows the light mark
<?php else: ?>
            <?php $rungs = $data['logo'][$mode]['srcset'] === '' ? 1
                         : count(explode(',', $data['logo'][$mode]['srcset'])); ?>
            <?= $rungs > 1 ? 'Stored at ' . $rungs . ' widths' : 'One width' ?>
<?php endif; ?>
          </span>
        </span>
      </div>
      <?php admin_image_fields("logo[$mode]", "upload[$mode][0]",
          $data['logo'][$mode], 'logo',
          $mode === 'dark'
              ? 'No dark-mode logo. Dark mode shows the light one.'
              : 'No logo.'); ?>
    </div>
<?php endforeach; ?>

  <p class="admin__fineprint">
    The mark is stored at <?= implode(', ', contract_slot_widths('settings.logo',
        UPLOAD_MAX_DIMENSION, UPLOAD_MAX_DIMENSION)) ?> pixels wide, so a phone
    downloads the small one and a high-resolution screen the large one. The words
    a screen reader announces the logo link as are on the
    <a href="<?= h(admin_url('chrome')) ?>">Header &amp; Footer</a> screen, because
    the header's and the footer's are legitimately different sentences.
  </p>
</section>

  <?= admin_form_tail() ?>
</form>
    <?php
    admin_foot();
    return;
}

admin_head('settings', $user,
    $screens['blurb'] . ' <a href="' . h(admin_url('settings'))
    . '">Back to Settings</a>.',
    ['band-now' => 'Where the enquiry form sends'],
    ['form' => 'settings-form', 'label' => 'Save where enquiries go',
     'discard' => admin_url('settings', ['part' => 'mail'])]);

admin_notices($errors);
settings_notices($data, $seo, false);
?>

<form class="admin__form" id="settings-form" method="post" data-async
      action="<?= h(admin_url('settings', ['part' => 'mail'])) ?>">
  <?= admin_form_fields('settings') ?>

<section class="admin__block" id="band-now">
  <?php admin_band_head('Where the enquiry form sends',
      'Every message the contact page\'s form takes arrives at this address, '
      . 'with the sender\'s own address on Reply-To so answering reaches them.'); ?>

  <div class="admin__grid">
    <label class="admin__field admin__field--wide">
      <span class="admin__label">Address</span>
      <input class="admin__input" type="email" required
             name="contact[mail_to]"
             value="<?= h((string)$data['contact']['mail_to']) ?>">
      <span class="admin__hint">
        Where enquiries arrive. It can be anywhere — it does not have to be at
        this site's domain.
      </span>
    </label>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Subject line</span>
      <input class="admin__input" type="text" required
             name="contact[mail_subject]"
             value="<?= h((string)$data['contact']['mail_subject']) ?>">
      <span class="admin__hint">
        What every message starts its subject with. Whatever the sender typed
        follows it, after a colon.
      </span>
    </label>
  </div>

  <div class="admin-card">
    <div class="admin-card__head">
      <span class="admin-card__preview">
        <strong>What the messages are sent AS</strong>
        <span class="admin-card__value"><?= h(SETTINGS_MAIL_FROM) ?></span>
      </span>
    </div>
    <p class="admin__fineprint">
      Not a field, and it must not become one. A message has to be sent from an
      address at this site's own domain or it fails SPF — the record that says
      which servers may send as <code>tech4time.bd</code> — and is filed as spam.
      That record lives with the domain and nothing on this screen can change it,
      so a box here would let somebody make every enquiry disappear into a spam
      folder with nothing to say why. The sender's own address goes on Reply-To,
      so hitting reply still reaches them.
    </p>
  </div>
</section>

  <?= admin_form_tail() ?>
</form>
<?php
admin_foot();
