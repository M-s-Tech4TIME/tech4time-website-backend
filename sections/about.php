<?php
/**
 * Tech4TIME — about page editor.
 *
 * Everything on /pages/about/ that is words or pictures rather than structure:
 * the banner, the five image-and-prose sections, the specialities slideshow,
 * the why-us cards and the closing band. Stored in content/about.json; there
 * is no database.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/contact.php
 * and sections/company.php make, for the same reason. This page is a lot of
 * short fields that are usually changed together, so the whole page is one
 * form, and the add, remove and reorder buttons submit it WITHOUT saving so
 * that nothing typed is lost on the way.
 *
 * WHY THE FIVE SECTIONS ARE ONE LIST AND NOT FIVE BANDS
 * The Company, Our Goal, Our Mission, Our Vision and Our Ambition are the same
 * shape: a heading, some prose and a picture. Written as five bands, a sixth
 * would need a deploy and reordering them would need a developer. Written as
 * one list they are content, which is the whole point of this screen.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row. Hiding is not deleting: a
 * hidden thing keeps its place and its contents and simply does not render.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/about.php';
require_once __DIR__ . '/../lib/upload.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-sanitised here rather than trusted: the
 * form's own constraints are a convenience for whoever is typing, and this is
 * the code that decides what gets stored.
 */
function about_from_post(array $current): array
{
    $data = $current;

    foreach (contract_page_bands(ABOUT_TEXT_FIELDS) as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (ABOUT_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    $data['specialties']['interval'] = (int)($_POST['specialties']['interval'] ?? 10000);

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    foreach (ABOUT_LISTS as $band => $filler) {
        $data[$band]['items'] = [];
        foreach (array_values((array)($_POST[$band]['items'] ?? [])) as $row) {
            if (is_array($row)) {
                $data[$band]['items'][] = $filler(about_row_from_post($band, $row));
            }
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return about_identify($data);
}

/** One row, cleaned, in whichever shape its list uses. */
function about_row_from_post(string $band, array $row): array
{
    $common = [
        'id'     => trim((string)($row['id'] ?? '')),
        'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
    ];

    if ($band === 'story') {
        return $common + [
            'heading' => trim((string)($row['heading'] ?? '')),
            'body'    => rt_sanitise_html((string)($row['body'] ?? '')),
            'layout'  => isset(ABOUT_LAYOUTS[$row['layout'] ?? '']) ? (string)$row['layout'] : 'photograph',
            'side'    => isset(ABOUT_SIDES[$row['side'] ?? '']) ? (string)$row['side'] : 'left',
            'alt'        => trim((string)($row['alt'] ?? '')),
            'image'      => about_image_from_post($row['image'] ?? []),
            'image_dark' => about_image_from_post($row['image_dark'] ?? []),
        ];
    }

    /* Specialities and why-us are the same shape: an icon, a title, a line. */
    return $common + [
        'icon'  => isset(ABOUT_ICONS[$row['icon'] ?? '']) ? (string)$row['icon'] : '',
        'title' => trim((string)($row['title'] ?? '')),
        'text'  => trim((string)($row['text'] ?? '')),
    ];
}

/**
 * One picture, from the hidden fields the row carries.
 *
 * The paths are checked against CONTRACT_IMAGE_ROOTS rather than taken as sent.
 * These are hidden inputs, so the browser is not offering a choice here — but
 * a hidden input is a text field with the label removed, and anything a form
 * posts can be posted with something else in it. A src pointing at another
 * origin would put a third party's server in every visitor's page load.
 */
function about_image_from_post(mixed $image): array
{
    return admin_image_from_post($image);
}

/* ---------------------------------------------------------------- actions */

$data = about_load();
$pending = '';   /* an unsaved change made by a row button */

/* Named here, not read inline below. See the note in sections/contact.php:
   a $_POST key that is only ever compared reads exactly like one that was
   assigned, and this was that bug. */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    /* Sent again by hand, from the notice a failed publish leaves. Nothing is
       re-saved and no revision is minted: the record here is already right,
       and what failed was only getting it to the other host. */
    if ($action === 'republish') {
        publish_note(publish_push('about', $data));

        $note = publish_note();
        admin_redirect('about', ($note['ok'] ?? false)
            ? 'Sent to the live site — it now holds revision '
              . (int)($note['revision'] ?? 0) . '.'
            : '');
    }

    /* Before anything is read out of $_POST, because everything read out of a
       truncated $_POST is a lie by omission — see admin_form_truncated(). */
    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
        $do = 'nothing';
        $posted = $data;
    } else {
        $do = (string)($_POST['do'] ?? 'save');
        $posted = about_from_post($data);
        $posted = about_take_uploads($posted, $errors);
    }

    if ($do === 'save') {
        /* MERGED, not assigned. $errors already holds anything about_take_uploads()
           put there, and an assignment here would throw away the one message
           explaining why the picture the operator just chose is not on the row. */
        $errors = array_merge($errors, about_validate($posted));
        if (!$errors) {
            if (about_save($posted)) {
                admin_redirect('about', 'Saved the about page.');
            }
            $errors[] = 'Could not write content/about.json. Check the file is '
                      . 'writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do === 'sweep-uploads') {
        $gone = 0;
        foreach (upload_unused(upload_in_use('about', $posted)) as $name) {
            $gone += upload_delete($name) ? 1 : 0;
        }
        $data = $posted;
        $pending = $gone === 1
            ? 'Deleted one picture nothing was using.'
            : "Deleted $gone pictures nothing was using.";
    } elseif ($do !== 'nothing') {
        $applied = about_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Take whatever pictures were attached, and put each on the row it belongs to.
 *
 * Runs on EVERY submit, not only a save. The row buttons submit the form
 * without saving, so a picture chosen and then followed by "move up" would
 * otherwise be dropped on the floor — and the file input would come back
 * empty, with nothing to say why.
 *
 * A picture is stored here and sent to the live site immediately, rather than
 * at save time, so the operator finds out at once if the channel is broken
 * while they still know what they were doing.
 */
function about_take_uploads(array $data, array &$errors): array
{
    /* Two file inputs can land on one row: every row has a light half and a
       dark one, whichever layout it uses. admin_uploaded_files() keys them by
       the name the input was given, so they arrive as two pseudo-bands and land
       in two fields. */
    $slots = ['story' => 'image', 'story_dark' => 'image_dark'];

    foreach (admin_uploaded_files() as [$band, $index, $file]) {
        if (!isset($slots[$band]) || !isset($data['story']['items'][$index])) {
            continue;
        }

        $where = 'Section ' . ($index + 1)
               . ($band === 'story_dark' ? ' (dark mode)' : '');

        $stored = upload_accept($file, 'about.story');

        if (isset($stored['error'])) {
            $errors[] = $where . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = $where . ': ' . $sent;
            continue;
        }

        $data['story']['items'][$index][$slots[$band]] = contract_image_defaults($stored);
    }

    return $data;
}

/**
 * Apply an add / remove / move button to one of the three lists.
 *
 * The button's value carries what to do and to which row, as "story-up:3".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function about_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    foreach (ABOUT_LISTS as $band => $filler) {
        if (!str_starts_with($verb, $band . '-')) {
            continue;
        }

        $rows = $data[$band]['items'];
        $what = substr($verb, strlen($band) + 1);

        if ($what === 'add') {
            /* A new row arrives HIDDEN. It has nothing in it yet, and a blank
               card appearing on the live site the moment somebody presses Add
               is not what pressing Add means. */
            $rows[] = $filler(['status' => 'hidden']);
            $data[$band]['items'] = $rows;

            return [about_identify($data),
                    'Added an entry. It is hidden until you show it — fill it in, '
                    . 'then save.'];
        }

        if (!isset($rows[$index])) {
            return null;
        }

        if ($what === 'remove') {
            array_splice($rows, $index, 1);
            $data[$band]['items'] = array_values($rows);

            return [$data, 'Removed. Nothing is written to the site until you save.'];
        }

        if ($what === 'up' || $what === 'down') {
            $to = $index + ($what === 'up' ? -1 : 1);
            if ($to < 0 || $to >= count($rows)) {
                return null;
            }
            [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];
            $data[$band]['items'] = $rows;

            return [$data, 'Moved. Nothing is written to the site until you save.'];
        }
    }

    return null;
}

/* ---------------------------------------------------------------- helpers */

/** A band's heading, its show/hide switch, and the blurb under it. */
function about_band_header(array $data, string $band, string $legend,
                           string $blurb, string $add = ''): void
{
    admin_band_head(
        $legend,
        $blurb,
        $add !== '' ? ['do' => $band . '-add:0', 'label' => $add] : [],
        ['name'  => $band . '[status]',
         'value' => (string)($data[$band]['status'] ?? 'shown'),
         'noun'  => 'this section']
    );
}

/** One icon picker, with the live preview beside it. */
function about_icon_field(string $name, string $value): void
{
    ?>
        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="<?= h($name) ?>">
            <option value=""<?= $value === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (ABOUT_ICONS as $icon => $label): ?>
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

/* What the rail lists under "About Us". The keys are the ids on the
   <fieldset>s below and the order is the order of the page, so this doubles as
   the table of contents for a form that is otherwise several screens of
   scrolling with no way to see what is in it. Add a band, add a line here. */
const ABOUT_OUTLINE = [
    'band-hero'        => 'The banner',
    'band-story'       => 'The sections',
    'band-specialties' => 'Our Specialities',
    'band-whyus'       => 'Why Us?',
    'band-cta'         => 'The closing band',
    'band-uploads'     => 'Stored pictures',
    'band-meta'        => 'Search and sharing',
];

admin_head('about', $user,
    'Editing <code>content/about.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/about/')) . '">the about page</a> '
    . 'within a second — as soon as the live site accepts the publish.',
    ABOUT_OUTLINE,
    ['form' => 'about-form', 'label' => 'Save the about page',
     'discard' => admin_url('about')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<?php /* multipart, because this form carries pictures. Everything else about
         it is an ordinary POST. */ ?>
<form class="admin__form" id="about-form" method="post"
      enctype="multipart/form-data" data-async
      action="<?= h(admin_url('about')) ?>">
  <?= admin_form_fields('about') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add a
           section". This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <!-- ========================= the banner ========================= -->
  <fieldset class="admin__block" id="band-hero">
    <?php admin_band_head('The banner',
        'The band at the top of the page, with the circuitry around it.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Page title</span>
        <input class="admin__input" type="text" name="hero[title]" required
               value="<?= h($data['hero']['title']) ?>">
        <span class="admin__hint">The big heading. It is the page's only h1.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under it</span>
        <input class="admin__input" type="text" name="hero[subtitle]"
               value="<?= h($data['hero']['subtitle']) ?>">
        <span class="admin__hint">Leave empty to show nothing under the heading.</span>
      </label>
    </div>
  </fieldset>

  <!-- ======================== the sections ======================== -->
  <fieldset class="admin__block" id="band-story">
    <?php about_band_header($data, 'story', 'The sections',
        'The image-and-prose sections down the page — The Company, Our Goal, '
        . 'and so on. They alternate light and shaded backgrounds by position, '
        . 'so reordering them keeps the stripe.',
        'Add a section'); ?>

<?php $rows = $data['story']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="story[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('story', $i, $total, [
          'label'  => $row['heading'],
          'noun'   => 'section',
          'detail' => ABOUT_LAYOUTS[$row['layout']] ?? '',
          'status' => $row['status'],
      ]); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="story[items][<?= $i ?>][heading]"
               value="<?= h($row['heading']) ?>">
        <span class="admin__hint">
          What the section is called. It is also what the section is announced
          as, so it cannot be empty.
        </span>
      </label>

      <?php /* A <div>, not a <label>, and deliberately: a <label> forwards a
               click from anywhere inside it to its first labelable descendant,
               and editor.js puts its toolbar BEFORE the textarea — so every
               click in the text would press Bold. The plain fields above wrap
               their input in a <label> because there the forwarding is exactly
               what you want; here it is a trap. */ ?>
      <div class="admin__field admin__field--wide">
        <label class="admin__label" for="story-<?= $i ?>-body">The text</label>
        <textarea class="admin__input admin__textarea" id="story-<?= $i ?>-body"
                  name="story[items][<?= $i ?>][body]" rows="6" data-editor><?= h($row['body']) ?></textarea>
        <span class="admin__hint">
          One or two paragraphs. Each paragraph fades in on its own as the
          reader reaches it.
        </span>
      </div>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Picture</span>
          <select class="admin__input" name="story[items][<?= $i ?>][layout]">
<?php foreach (ABOUT_LAYOUTS as $layout => $label): ?>
            <option value="<?= h($layout) ?>"<?= $row['layout'] === $layout ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">
            The logo lockup ships with the site and swaps itself for light and
            dark mode. Choosing it ignores any picture below.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Which side</span>
          <select class="admin__input" name="story[items][<?= $i ?>][side]">
<?php foreach (ABOUT_SIDES as $side => $label): ?>
            <option value="<?= h($side) ?>"<?= $row['side'] === $side ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">On a narrow screen the picture is always on top.</span>
        </label>
      </div>

<?php if ($row['layout'] === 'logo'): ?>
      <?php /* A logo row carries a pair. Each half falls back to the lockup
               that ships with the site, so a row switched to this layout works
               with nothing uploaded — and a new mark can be put here without a
               deploy, which is the whole point of the slot existing. */ ?>
      <p class="admin__label">Logo for light mode</p>
      <?php admin_image_fields(
          "story[items][$i][image]",
          "upload[story][$i]",
          $row['image'],
          'logo',
          '',
          ['src'  => '/assets/images/logo/logo-light-540.png',
           'note' => 'The logo that ships with the site. Upload one to replace it here.']
      ); ?>

      <p class="admin__label">Logo for dark mode</p>
      <?php admin_image_fields(
          "story[items][$i][image_dark]",
          "upload[story_dark][$i]",
          $row['image_dark'],
          'dark-mode logo',
          '',
          trim((string)$row['image']['src']) !== ''
              ? ['src'  => (string)$row['image']['src'],
                 'note' => 'Using the light-mode logo above. Upload one here if '
                         . 'it does not read on a dark background.']
              : ['src'  => '/assets/images/logo/logo-dark-540.png',
                 'note' => 'The logo that ships with the site.']
      ); ?>

      <p class="admin__hint">
        This replaces the logo <strong>in this section only</strong>. The one in
        the header, the footer, the browser tab and on a shared link is part of
        the site itself and still needs a developer.
      </p>
<?php else: ?>
      <?php /* A photograph row carries a pair too. The dark half is optional
               and almost always empty: the illustrations are line drawings that
               sit on a white plate in both colour modes by design, so one
               picture is the normal case and uploading a second is what
               switches that off for this row. Same control, same rules, as the
               Get to Know Us cards on the home page. */ ?>
      <p class="admin__label">Picture</p>
      <?php admin_image_fields("story[items][$i][image]",
                                "upload[story][$i]",
                                $row['image']); ?>

      <p class="admin__label">Picture for dark mode</p>
      <?php admin_image_fields(
          "story[items][$i][image_dark]",
          "upload[story_dark][$i]",
          $row['image_dark'],
          'dark-mode picture',
          '',
          trim((string)$row['image']['src']) !== ''
              ? ['src'  => (string)$row['image']['src'],
                 'note' => 'Using the picture above in both colour modes, which '
                         . 'is how these sections are designed. Upload one here '
                         . 'only if you have artwork made for a dark page.']
              : []
      ); ?>
<?php endif; ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">What is in the picture</span>
        <input class="admin__input" type="text" name="story[items][<?= $i ?>][alt]"
               value="<?= h($row['alt']) ?>">
        <span class="admin__hint">
          Describe it for somebody who cannot see it. Not “photo” or “image” —
          say what it shows.
        </span>
      </label>

      <?php admin_status_field("story[items][$i][status]",
          (string)$row['status'], 'this section'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ====================== the specialities ====================== -->
  <fieldset class="admin__block" id="band-specialties">
    <?php about_band_header($data, 'specialties', 'Our Specialities',
        'The slideshow. Without JavaScript every card is on screen at once, so '
        . 'the order is the reading order either way.',
        'Add a speciality'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Block heading</span>
        <input class="admin__input" type="text" name="specialties[title]"
               value="<?= h($data['specialties']['title']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Time on each card</span>
        <input class="admin__input" type="number" name="specialties[interval]"
               min="2000" max="60000" step="500"
               value="<?= (int)$data['specialties']['interval'] ?>">
        <span class="admin__hint">
          In milliseconds. 10000 is ten seconds. It never advances on its own
          for somebody who has asked for reduced motion.
        </span>
      </label>
    </div>

<?php $rows = $data['specialties']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="specialties[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('specialties', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'speciality',
          'detail' => $row['icon'],
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <?php about_icon_field("specialties[items][$i][icon]", (string)$row['icon']); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="specialties[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <div class="admin__field admin__field--wide">
          <label class="admin__label" for="specialties-<?= $i ?>-text">The text</label>
          <textarea class="admin__input admin__textarea" id="specialties-<?= $i ?>-text"
                    name="specialties[items][<?= $i ?>][text]" rows="5"><?= h($row['text']) ?></textarea>
          <span class="admin__hint">
            One paragraph. Plain text — the card is a single paragraph and
            cannot hold a list or a link.
          </span>
        </div>
      </div>

      <?php admin_status_field("specialties[items][$i][status]",
          (string)$row['status'], 'this speciality'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ========================== why us ========================== -->
  <fieldset class="admin__block" id="band-whyus">
    <?php about_band_header($data, 'whyus', 'Why Us?',
        'The grid of short reasons near the bottom of the page. One icon, one '
        . 'title and one line each.',
        'Add a reason'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="whyus[title]"
             value="<?= h($data['whyus']['title']) ?>">
    </label>

<?php $rows = $data['whyus']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="whyus[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('whyus', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'reason',
          'detail' => $row['icon'],
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <?php about_icon_field("whyus[items][$i][icon]", (string)$row['icon']); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="whyus[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a sentence</span>
          <input class="admin__input" type="text" name="whyus[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php admin_status_field("whyus[items][$i][status]",
          (string)$row['status'], 'this reason'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ======================= the closing band ======================= -->
  <fieldset class="admin__block" id="band-cta">
    <?php about_band_header($data, 'cta', 'The closing band',
        'The band at the foot of the page, with one button.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="cta[title]"
               value="<?= h($data['cta']['title']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Button label</span>
        <input class="admin__input" type="text" name="cta[label]"
               value="<?= h($data['cta']['label']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Where it goes</span>
        <input class="admin__input" type="text" name="cta[href]"
               value="<?= h($data['cta']['href']) ?>">
        <span class="admin__hint">
          A path on this site starting with /, or a full https:// address.
        </span>
      </label>

      <?php about_icon_field('cta[icon]', (string)$data['cta']['icon']); ?>
    </div>
  </fieldset>

  <!-- ========================== stored pictures ========================== -->
<?php $unused = upload_problem() === '' ? upload_unused(upload_in_use('about', $data)) : []; ?>
  <fieldset class="admin__block" id="band-uploads">
    <?php admin_band_head('Stored pictures',
        'Every picture uploaded through this page is kept here, named after '
        . 'its own contents. Uploading the same one twice stores it once.'); ?>

<?php if (upload_problem() !== ''): ?>
    <p class="admin__hint"><?= h(upload_problem()) ?></p>
<?php elseif (!$unused): ?>
    <p class="admin__hint">
      <?= count(upload_held()) ?> stored, and every one of them is in use.
    </p>
<?php else: ?>
    <p class="admin__hint">
      <?= count($unused) ?> of <?= count(upload_held()) ?> are not used by any row
      above. That is normal just after replacing a picture, and normal while a
      row you have not saved yet still refers to one.
    </p>
    <?php /* Never swept on its own. A count taken from a document somebody is
             halfway through editing is not a fact, and acting on it would
             delete the picture belonging to the row they are about to save.

             The count is across EVERY editor's pictures, not just this page's
             — the store is shared. Sweeping from here deletes what no page
             refers to, which is why it is never automatic. */ ?>
    <div class="admin__actions">
      <button class="btn btn--secondary" type="submit" name="do" value="sweep-uploads">
        Delete the <?= count($unused) ?> unused
      </button>
    </div>
<?php endif; ?>
  </fieldset>

  <!-- ============================ search ============================ -->
  <?php admin_meta_band('about'); ?>


  <?php /* The last control in the form, and the only reason it exists is that
           its ABSENCE is readable. See admin_form_tail(). */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/about.json.bak</code>.</p>'
);
