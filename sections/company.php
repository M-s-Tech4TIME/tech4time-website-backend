<?php
/**
 * Tech4TIME — company profile editor.
 *
 * Everything on /pages/company-profile/ that is words or pictures rather than
 * structure: the banner, the milestone timeline, the figures, the client
 * logos, the photographs, the technology list, the principles, and the copy
 * around all of them. Stored in content/company.json; there is no database.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/contact.php
 * makes, for the same reason. This page is a lot of short fields that are
 * usually changed together, so the whole page is one form, and the add, remove
 * and reorder buttons submit it WITHOUT saving so that nothing typed is lost
 * on the way.
 *
 * WHY SO MUCH OF THIS IS A LOOP
 * The page has six repeatable lists. Written out one at a time this file would
 * be four times the length and the fifth copy would be the one that forgot the
 * hidden id field. So the lists are driven from COMPANY_LISTS in the contract,
 * and each row SHAPE has one function that draws it.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row. Hiding is not deleting: a
 * hidden thing keeps its place and its contents and simply does not render.
 * That is what somebody wants when a client asks to be taken off the page for
 * a quarter, and it is the difference between this editor and a text file.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/company.php';
require_once __DIR__ . '/../lib/upload.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-sanitised here rather than trusted: the
 * form's own constraints are a convenience for whoever is typing, and this is
 * the code that decides what gets stored.
 */
function company_from_post(array $current): array
{
    $data = $current;

    foreach (COMPANY_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (COMPANY_RICH_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = rt_sanitise_html((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (COMPANY_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    $data['journey']['interval'] = (int)($_POST['journey']['interval'] ?? 6000);

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    foreach (COMPANY_LISTS as $band => $filler) {
        $data[$band]['items'] = [];
        foreach (array_values((array)($_POST[$band]['items'] ?? [])) as $row) {
            if (is_array($row)) {
                $data[$band]['items'][] = $filler(company_row_from_post($band, $row));
            }
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return company_identify($data);
}

/** One row, cleaned, in whichever shape its list uses. */
function company_row_from_post(string $band, array $row): array
{
    $common = [
        'id'     => trim((string)($row['id'] ?? '')),
        'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
    ];

    return match ($band) {
        'milestones' => $common + [
            'year'  => trim((string)($row['year'] ?? '')),
            'title' => trim((string)($row['title'] ?? '')),
            'text'  => trim((string)($row['text'] ?? '')),
        ],
        'experience' => $common + [
            'figure' => trim((string)($row['figure'] ?? '')),
            'label'  => trim((string)($row['label'] ?? '')),
        ],
        'journey' => $common + [
            'alt'   => trim((string)($row['alt'] ?? '')),
            'image' => company_image_from_post($row['image'] ?? []),
        ],
        'principles' => $common + [
            'icon'  => isset(COMPANY_ICONS[$row['icon'] ?? '']) ? (string)$row['icon'] : '',
            'title' => trim((string)($row['title'] ?? '')),
            'text'  => trim((string)($row['text'] ?? '')),
        ],
        default => $common + [
            'name'  => trim((string)($row['name'] ?? '')),
            'image' => company_image_from_post($row['image'] ?? []),
        ],
    };
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
function company_image_from_post(mixed $image): array
{
    $image = is_array($image) ? $image : [];

    return contract_image_defaults([
        'src'    => contract_safe_image_path((string)($image['src'] ?? '')),
        'webp'   => contract_safe_image_path((string)($image['webp'] ?? '')),
        'width'  => (int)($image['width'] ?? 0),
        'height' => (int)($image['height'] ?? 0),
    ]);
}

/* ---------------------------------------------------------------- actions */

$data = company_load();
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
        publish_note(publish_push('company', $data));

        $note = publish_note();
        admin_redirect('company', ($note['ok'] ?? false)
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
        $posted = company_from_post($data);
        $posted = company_take_uploads($posted, $errors);
    }

    if ($do === 'save') {
        /* MERGED, not assigned. $errors already holds anything
           company_take_uploads() put there, and an assignment here threw away
           the one message explaining why the picture the operator just chose
           is not on the row. sections/contact.php always merged; this did not. */
        $errors = array_merge($errors, company_validate($posted));
        if (!$errors) {
            if (company_save($posted)) {
                admin_redirect('company', 'Saved the company profile.');
            }
            $errors[] = 'Could not write content/company.json. Check the file is '
                      . 'writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do === 'sweep-uploads') {
        $gone = 0;
        foreach (upload_unused(upload_in_use('company', $posted)) as $name) {
            $gone += upload_delete($name) ? 1 : 0;
        }
        $data = $posted;
        $pending = $gone === 1
            ? 'Deleted one picture nothing was using.'
            : "Deleted $gone pictures nothing was using.";
    } elseif ($do !== 'nothing') {
        $applied = company_apply_row_action($posted, $do);
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
 * at save time. Two reasons: the operator finds out at once if the channel is
 * broken, while they still know what they were doing; and a save that carried
 * sixty pictures would re-send every one of them for a one-word edit.
 *
 * An orphan is possible — a picture uploaded and the save then abandoned — and
 * is the right trade. It costs disk; the other order costs an edit.
 */
function company_take_uploads(array $data, array &$errors): array
{
    foreach (admin_uploaded_files() as [$band, $index, $file]) {
        if (!isset(COMPANY_LISTS[$band]) || !isset($data[$band]['items'][$index])) {
            continue;
        }

        $stored = upload_accept($file);

        if (isset($stored['error'])) {
            $errors[] = 'Row ' . ($index + 1) . ' of '
                      . ADMIN_SECTIONS['company']['label'] . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = 'Row ' . ($index + 1) . ': ' . $sent;
            continue;
        }

        $data[$band]['items'][$index]['image'] = contract_image_defaults($stored);
    }

    return $data;
}



/**
 * Apply an add / remove / move button to one of the six lists.
 *
 * The button's value carries what to do and to which row, as "clients-up:3".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function company_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    foreach (COMPANY_LISTS as $band => $filler) {
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

            return [company_identify($data),
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

/* The row head — number, preview, status, and the move/remove controls — is
   admin_card_head() in lib/admin.php, which sections/contact.php calls too.
   This file used to emit the three buttons on their own, outside the flex line
   the stylesheet expects them in, so they sat top-left above the fields
   instead of at the right end of a row head. Same classes, different page. */




/** A band's heading, its show/hide switch, and the blurb under it. */
function company_band_header(array $data, string $band, string $legend,
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

/* What the rail lists under "Company Profile". The keys are the ids on the
   <fieldset>s below and the order is the order of the page, so this doubles as
   the table of contents for a form that is otherwise ten screens of scrolling
   with no way to see what is in it. Add a band, add a line here. */
const COMPANY_OUTLINE = [
    'band-hero'        => 'The banner',
    'band-milestones'  => 'Milestones',
    'band-background'  => 'Our Background',
    'band-experience'  => 'The figures',
    'band-clients'     => 'Proud Clients',
    'band-journey'     => 'Our Journey of Growth',
    'band-excellence'  => 'Professional Excellence',
    'band-technology'  => 'Technology',
    'band-principles'  => 'Principles',
    'band-cta'         => 'The closing band',
    'band-uploads'     => 'Stored pictures',
    'band-meta'        => 'Search and sharing',
];

admin_head('company', $user,
    'Editing <code>content/company.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/company-profile/')) . '">the company profile</a> '
    . 'within a second — as soon as the live site accepts the publish.',
    COMPANY_OUTLINE,
    ['form' => 'company-form', 'label' => 'Save the company profile',
     'discard' => admin_url('company')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<?php /* multipart, because this form carries pictures. Everything else about
         it is an ordinary POST. */ ?>
<form class="admin__form" id="company-form" method="post"
      enctype="multipart/form-data" data-async
      action="<?= h(admin_url('company')) ?>">
  <?= admin_form_fields('company') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add an
           entry". This is that first button, and it saves. */ ?>
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

  <!-- ========================= milestones ========================= -->
  <fieldset class="admin__block" id="band-milestones">
    <?php company_band_header($data, 'milestones', 'Milestones',
        'The timeline. Entries alternate left and right down the page, so the '
        . 'order decides which side each one lands on.',
        'Add a milestone'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="milestones[eyebrow]"
               value="<?= h($data['milestones']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="milestones[title]"
               value="<?= h($data['milestones']['title']) ?>">
      </label>
    </div>

    <?php /* A <div>, not a <label>, and deliberately: a <label> forwards a
             click from anywhere inside it to its first labelable descendant,
             and editor.js puts its toolbar BEFORE the textarea — so every
             click in the text would press Bold. The plain fields above wrap
             their input in a <label> because there the forwarding is exactly
             what you want; here it is a trap. */ ?>
    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="milestones-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="milestones-lead"
                name="milestones[lead]" rows="3" data-editor><?= h($data['milestones']['lead']) ?></textarea>
      <span class="admin__hint">Leave empty to show nothing under the heading.</span>
    </div>

<?php $rows = $data['milestones']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="milestones[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('milestones', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'entry',
          'detail' => $row['year'],
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Year</span>
          <input class="admin__input" type="text" name="milestones[items][<?= $i ?>][year]"
                 value="<?= h($row['year']) ?>" placeholder="2024">
        </label>

        <label class="admin__field">
          <span class="admin__label">What happened</span>
          <input class="admin__input" type="text" name="milestones[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a sentence</span>
          <input class="admin__input" type="text" name="milestones[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php admin_status_field("milestones[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ===================== the background band ===================== -->
  <fieldset class="admin__block" id="band-background">
    <?php company_band_header($data, 'background', 'Our Background',
        'The surface the three blocks below sit on. Hiding this hides all '
        . 'three of them, whatever their own switches say.'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="background[eyebrow]"
               value="<?= h($data['background']['eyebrow']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="background[title]"
               value="<?= h($data['background']['title']) ?>">
      </label>
    </div>
  </fieldset>

  <!-- ========================= the figures ========================= -->
  <fieldset class="admin__block" id="band-experience">
    <?php company_band_header($data, 'experience', 'The figures',
        'The four numbers that count up as the block comes into view.',
        'Add a figure'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="experience[title]"
             value="<?= h($data['experience']['title']) ?>">
    </label>

<?php $rows = $data['experience']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="experience[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('experience', $i, $total, [
          'label'  => $row['label'],
          'noun'   => 'figure',
          'detail' => $row['figure'],
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">The number</span>
          <input class="admin__input" type="text" name="experience[items][<?= $i ?>][figure]"
                 value="<?= h($row['figure']) ?>" placeholder="100+">
          <span class="admin__hint">
            Must start with a digit. The animation counts the number off the
            front and keeps whatever follows — “100+” and “99%” work,
            “Over 100” does not.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">What it counts</span>
          <input class="admin__input" type="text" name="experience[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>">
        </label>
      </div>

      <?php admin_status_field("experience[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ========================= the clients ========================= -->
  <fieldset class="admin__block" id="band-clients">
    <?php company_band_header($data, 'clients', 'Proud Clients',
        'The wall of logos. The name is what a screen reader announces, so it '
        . 'is not optional.',
        'Add a client'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="clients[title]"
             value="<?= h($data['clients']['title']) ?>">
    </label>

<?php $rows = $data['clients']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="clients[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('clients', $i, $total, [
          'label'  => $row['name'],
          'noun'   => 'client',
          'status' => $row['status'],
      ]); ?>

      <?php admin_image_fields("clients[items][$i][image]",
                                "upload[clients][$i]",
                                $row['image']); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Name</span>
        <input class="admin__input" type="text" name="clients[items][<?= $i ?>][name]"
               value="<?= h($row['name']) ?>">
      </label>

      <?php admin_status_field("clients[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ======================= the photographs ======================= -->
  <fieldset class="admin__block" id="band-journey">
    <?php company_band_header($data, 'journey', 'Our Journey of Growth',
        'The slideshow. One photograph at a time, and the whole row without '
        . 'JavaScript.',
        'Add a photograph'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Block heading</span>
        <input class="admin__input" type="text" name="journey[title]"
               value="<?= h($data['journey']['title']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Seconds on each photograph</span>
        <input class="admin__input" type="number" name="journey[interval]"
               min="2000" max="60000" step="500"
               value="<?= (int)$data['journey']['interval'] ?>">
        <span class="admin__hint">
          In milliseconds. 6000 is six seconds. It never advances on its own for
          somebody who has asked for reduced motion.
        </span>
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="journey-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="journey-lead"
                name="journey[lead]" rows="3" data-editor><?= h($data['journey']['lead']) ?></textarea>
    </div>

<?php $rows = $data['journey']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="journey[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('journey', $i, $total, [
          'label'  => $row['alt'],
          'noun'   => 'photograph',
          'status' => $row['status'],
      ]); ?>

      <?php admin_image_fields("journey[items][$i][image]",
                                "upload[journey][$i]",
                                $row['image']); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">What is in the picture</span>
        <input class="admin__input" type="text" name="journey[items][<?= $i ?>][alt]"
               value="<?= h($row['alt']) ?>">
        <span class="admin__hint">
          Describe it for somebody who cannot see it. Not “photo” or “image” —
          say what is happening.
        </span>
      </label>

      <?php admin_status_field("journey[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ==================== the excellence band ==================== -->
  <fieldset class="admin__block" id="band-excellence">
    <?php company_band_header($data, 'excellence', 'Our Professional Excellence',
        'The band holding the technology list and the principles. Hiding this '
        . 'hides both of them.'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="excellence[eyebrow]"
               value="<?= h($data['excellence']['eyebrow']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="excellence[title]"
               value="<?= h($data['excellence']['title']) ?>">
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="excellence-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="excellence-lead"
                name="excellence[lead]" rows="3" data-editor><?= h($data['excellence']['lead']) ?></textarea>
    </div>
  </fieldset>

  <!-- ======================== the technology ======================== -->
  <fieldset class="admin__block" id="band-technology">
    <?php company_band_header($data, 'technology', 'The Technology We Work With',
        'The logos that become the rotating sphere on a wide screen, and an '
        . 'ordinary grid on a narrow one or with JavaScript off. Position '
        . 'decides where a logo sits on the sphere, so reordering moves '
        . 'everything.',
        'Add a technology'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="technology[title]"
             value="<?= h($data['technology']['title']) ?>">
    </label>

<?php $rows = $data['technology']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="technology[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('technology', $i, $total, [
          'label'  => $row['name'],
          'noun'   => 'entry',
          'status' => $row['status'],
      ]); ?>

      <?php admin_image_fields("technology[items][$i][image]",
                                "upload[technology][$i]",
                                $row['image']); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Name</span>
        <input class="admin__input" type="text" name="technology[items][<?= $i ?>][name]"
               value="<?= h($row['name']) ?>">
      </label>

      <?php admin_status_field("technology[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ======================== the principles ======================== -->
  <fieldset class="admin__block" id="band-principles">
    <?php company_band_header($data, 'principles', 'The Principles That Guide Us',
        'Four cards, each with an icon.',
        'Add a principle'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Block heading</span>
      <input class="admin__input" type="text" name="principles[title]"
             value="<?= h($data['principles']['title']) ?>">
    </label>

<?php $rows = $data['principles']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="principles[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('principles', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'principle',
          'detail' => $row['icon'],
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="principles[items][<?= $i ?>][icon]">
            <option value=""<?= $row['icon'] === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (COMPANY_ICONS as $name => $label): ?>
            <option value="<?= h($name) ?>"<?= $row['icon'] === $name ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <div class="admin__field">
          <span class="admin__label">As it will look</span>
          <p class="admin-card__icon">
            <?= $row['icon'] !== '' ? admin_icon($row['icon'], 'icon') : '' ?>
          </p>
          <span class="admin__hint">Save to change what is drawn here.</span>
        </div>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="principles[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a sentence</span>
          <input class="admin__input" type="text" name="principles[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php admin_status_field("principles[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ========================= the closing band ========================= -->
  <fieldset class="admin__block" id="band-cta">
    <?php company_band_header($data, 'cta', 'The closing band',
        'The dark strip at the bottom with the button.'); ?>

    <label class="admin__field admin__field--wide">
      <span class="admin__label">Heading</span>
      <input class="admin__input" type="text" name="cta[title]"
             value="<?= h($data['cta']['title']) ?>">
    </label>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="cta-text">The line under it</label>
      <textarea class="admin__input admin__textarea" id="cta-text"
                name="cta[text]" rows="3" data-editor><?= h($data['cta']['text']) ?></textarea>
    </div>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Button</span>
        <input class="admin__input" type="text" name="cta[label]"
               value="<?= h($data['cta']['label']) ?>">
        <span class="admin__hint">Leave empty to show no button at all.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Where it goes</span>
        <input class="admin__input" type="text" name="cta[href]"
               value="<?= h($data['cta']['href']) ?>" placeholder="/pages/contact/">
        <span class="admin__hint">A path on this site, or a full https:// address.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Button icon</span>
        <select class="admin__input" name="cta[icon]">
          <option value=""<?= $data['cta']['icon'] === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (COMPANY_ICONS as $name => $label): ?>
          <option value="<?= h($name) ?>"<?= $data['cta']['icon'] === $name ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
    </div>
  </fieldset>

  <!-- ========================== stored pictures ========================== -->
<?php $unused = upload_problem() === '' ? upload_unused(upload_in_use('company', $data)) : []; ?>
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
             delete the picture belonging to the row they are about to save. */ ?>
    <div class="admin__actions">
      <button class="btn btn--secondary" type="submit" name="do" value="sweep-uploads">
        Delete the <?= count($unused) ?> unused
      </button>
    </div>
<?php endif; ?>
  </fieldset>

  <!-- ============================ search ============================ -->
  <fieldset class="admin__block" id="band-meta">
    <?php admin_band_head('How it appears elsewhere',
        'What a search engine shows, and what appears when somebody pastes a '
        . 'link to this page into a chat.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Browser tab title</span>
        <input class="admin__input" type="text" name="meta[title]" required
               value="<?= h($data['meta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Search description</span>
        <input class="admin__input" type="text" name="meta[description]"
               value="<?= h($data['meta']['description']) ?>">
        <span class="admin__hint">Aim for 150–160 characters.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Title on a shared link</span>
        <input class="admin__input" type="text" name="meta[share_title]"
               value="<?= h($data['meta']['share_title']) ?>">
      </label>
    </div>
  </fieldset>


  <?php /* The last control in the form, and the only reason it exists is that
           its ABSENCE is readable. See admin_form_tail(). */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/company.json.bak</code>.</p>'
);
