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
 * THE PART SCREENS SHOW WHAT IS SET AND DO NOT YET CHANGE IT. The document,
 * the road it travels and this shell landed first, on purpose: every consumer
 * of the logo, the icons and the colours has to be converted to read from here
 * before anything is allowed to write to it, or a save would change one of the
 * places a mark appears and leave the other eight showing the old one. Each
 * screen says which stage brings its own controls.
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
 * NOTICES, NEVER REFUSALS. Both of the things reported here are legitimate
 * answers that only the person who drew the mark can judge, and a settings
 * screen that refused a save over either would be unusable halfway through
 * replacing a pair of files.
 *
 * Drawn on every render rather than after a save, because each reports a state
 * of the document rather than something that just happened.
 */
function settings_notices(array $data, bool $linked): void
{
    if (settings_logo_is_shared($data)) {
        admin_standing_notice(
            'There is no separate dark-mode logo, so dark mode shows the light one. '
            . 'That is right for a mark that reads on both a pale and a dark '
            . 'ground, and wrong for one drawn in dark ink — which would be very '
            . 'nearly invisible.');
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
}

/* ------------------------------------------------------------ which screen */

$part   = in_array((string)($_GET['part'] ?? ''), SETTINGS_PARTS, true)
        ? (string)$_GET['part'] : '';
$screen = $part === '' ? 'index' : $part;

$data   = settings_load();
$errors = [];

/* ------------------------------------------------------------------ index */

if ($screen === 'index') {
    admin_head('settings', $user,
        'The mark, the icons, the colours and the address behind every page of the '
        . 'site. None of it belongs to a single page, so none of it is on a page\'s '
        . 'own screen. Editing <code>content/settings.json</code>.',
        ['band-parts' => 'The four parts', 'band-elsewhere' => 'What is not here']);

    admin_notices($errors);
    settings_notices($data, true);
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

admin_head('settings', $user,
    $screens['blurb'] . ' <a href="' . h(admin_url('settings'))
    . '">Back to Settings</a>.',
    ['band-now' => 'What is set now']);

admin_notices($errors);
settings_notices($data, false);
?>

<section class="admin__block" id="band-now">
  <?php admin_band_head('What is set now',
      'The document exists and travels to the live site; the controls that change '
      . 'this part arrive with the stage that converts everything reading it. '
      . 'Until then this says what the site is using.'); ?>

<?php if ($part === 'logo'): ?>
<?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $label):
        $image = $data['logo'][$mode];
        $shown = trim((string)$image['src']); ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h($label) ?></strong>
          <span class="admin-card__value">
            <?= $shown === '' ? 'Not set — dark mode shows the light mark' : h($shown) ?>
          </span>
        </span>
      </div>
<?php if ($shown !== ''): ?>
      <p class="admin__fineprint">
        <?= (int)$image['width'] ?> &times; <?= (int)$image['height'] ?> pixels<?php
        $rungs = $image['srcset'] === '' ? 0 : count(explode(',', $image['srcset']));
        echo $rungs > 1 ? ', stored at ' . $rungs . ' widths' : ''; ?>.
      </p>
<?php endif; ?>
    </div>
<?php endforeach; ?>

<?php elseif ($part === 'icon'): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>The square mark</strong>
          <span class="admin-card__value">
            <?= trim((string)$data['icon']['master']['src']) === ''
                ? 'Not set — the icons that ship are being used'
                : h((string)$data['icon']['master']['src']) ?>
          </span>
        </span>
      </div>
      <p class="admin__fineprint">
        <?= count(SETTINGS_ICON_SIZES) ?> sizes are made from it, plus a
        <code>favicon.ico</code> for browsers that still ask for one.
      </p>
    </div>

<?php elseif ($part === 'colour'): ?>
<?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $mode => $label): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h($label) ?></strong>
          <span class="admin-card__value"><?= count($data['colours'][$mode]) ?> colours</span>
        </span>
      </div>
      <p class="admin__fineprint">
<?php foreach ($data['colours'][$mode] as $token => $value): ?>
        <?= h($token) ?> <code><?= h($value) ?></code><?= $token === array_key_last($data['colours'][$mode]) ? '' : ' · ' ?>
<?php endforeach; ?>
      </p>
    </div>
<?php endforeach; ?>

<?php else: ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong>Enquiries are sent to</strong>
          <span class="admin-card__value"><?= h((string)$data['contact']['mail_to']) ?></span>
        </span>
      </div>
      <p class="admin__fineprint">
        With the subject line <strong><?= h((string)$data['contact']['mail_subject']) ?></strong>,
        followed by whatever the sender typed.
      </p>
    </div>
<?php endif; ?>
</section>
<?php
admin_foot();
