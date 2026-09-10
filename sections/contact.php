<?php
/**
 * Tech4TIME — contact page editor.
 *
 * Everything on /pages/contact/ that is words rather than structure: the
 * banner, the copy around the form, the list of services the form offers, the
 * "Reach Us Directly" rows, and the offices with their addresses, numbers,
 * opening hours and flags. Stored in content/contact.json; there is no
 * database.
 *
 * WHY ONE FORM RATHER THAN A LIST AND AN EDIT SCREEN
 * The careers editor lists posts and opens one at a time, because a job post
 * is a page's worth of writing. A contact page is a handful of short fields
 * that are usually changed together — a new office arrives with its address,
 * its numbers and its flag at once — so the whole page is one form, and the
 * add, remove and reorder buttons submit it without saving so that nothing
 * typed is lost on the way.
 *
 * THE SHAPE OF THIS FORM FOLLOWS THE SHAPE OF THE PAGE
 * Each fieldset below is one band of /pages/contact/, in the order a visitor
 * meets them. If that page gains or loses a band, this form and lib/contact.php
 * change with it — tools/check_content_model.py fails if one of the three is
 * left behind.
 *
 * Included by admin/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/contact.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-sanitised here rather than trusted: the
 * form's own constraints are a convenience for whoever is typing, and this is
 * the code that decides what gets stored.
 */
function contact_from_post(array $current): array
{
    $data = $current;

    foreach (contract_page_bands(CONTACT_TEXT_FIELDS) as $section => $fields) {
        foreach ($fields as $field) {
            $data[$section][$field] = trim((string)($_POST[$section][$field] ?? ''));
        }
    }

    foreach (CONTACT_RICH_FIELDS as $section => $fields) {
        foreach ($fields as $field) {
            $data[$section][$field] = rt_sanitise_html((string)($_POST[$section][$field] ?? ''));
        }
    }

    $data['form']['service_types'] =
        contact_string_list((string)($_POST['form']['service_types'] ?? ''));

    foreach (CONTACT_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    $data['reach']['items'] = [];
    foreach (array_values((array)($_POST['reach']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data['reach']['items'][] = contact_reach_defaults([
            'icon'   => isset(CONTACT_ICONS[$row['icon'] ?? '']) ? (string)$row['icon'] : '',
            'label'  => trim((string)($row['label'] ?? '')),
            'type'   => isset(CONTACT_REACH_TYPES[$row['type'] ?? '']) ? (string)$row['type'] : 'text',
            'values' => contact_string_list((string)($row['values'] ?? '')),
            'text'   => trim((string)($row['text'] ?? '')),
            'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        ]);
    }

    $taken = [];
    $data['offices']['items'] = [];
    foreach (array_values((array)($_POST['offices']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '' || in_array($id, $taken, true)) {
            $id = contact_slug($name, $taken);
        }
        $taken[] = $id;

        $flag = trim((string)($row['flag'] ?? ''));

        $data['offices']['items'][] = contact_office_defaults([
            'id'        => $id,
            'name'      => $name,
            'flag'      => in_array($flag, contact_flags(), true) ? $flag : '',
            'address'   => trim((string)($row['address'] ?? '')),
            'phones'    => contact_string_list((string)($row['phones'] ?? '')),
            'hours'     => trim((string)($row['hours'] ?? '')),
            'languages' => contact_string_list(
                str_replace(',', "\n", (string)($row['languages'] ?? ''))
            ),
            'status'    => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            /* Re-checked against CONTRACT_IMAGE_ROOTS by the model rather than
               taken as sent: these are hidden inputs, which is a text field
               with the label taken off. */
            'image'     => contract_image_defaults($row['image'] ?? []),
            'schema'    => [
                'street'      => trim((string)($row['schema']['street'] ?? '')),
                'locality'    => trim((string)($row['schema']['locality'] ?? '')),
                'region'      => trim((string)($row['schema']['region'] ?? '')),
                'postal_code' => trim((string)($row['schema']['postal_code'] ?? '')),
                'country'     => strtoupper(trim((string)($row['schema']['country'] ?? ''))),
            ],
        ]);
    }

    return $data;
}

/**
 * Apply an add / remove / move button to one of the two lists.
 *
 * The button's value carries what to do and to which row, as "reach-up:2".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function contact_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    $lists = [
        'reach'  => ['reach', 'items'],
        'offices' => ['offices', 'items'],
    ];

    foreach ($lists as $prefix => [$outer, $inner]) {
        if (!str_starts_with($verb, $prefix . '-')) {
            continue;
        }
        $rows = $data[$outer][$inner];
        $what = substr($verb, strlen($prefix) + 1);

        if ($what === 'add') {
            $rows[] = $prefix === 'reach'
                ? contact_reach_defaults(['icon' => 'envelope', 'type' => 'text'])
                : contact_office_defaults(['name' => '', 'status' => 'hidden']);
            $data[$outer][$inner] = $rows;
            return [$data, $prefix === 'reach'
                ? 'Added a row. Fill it in and save.'
                : 'Added an office. It is hidden until you show it — fill it in, then save.'];
        }

        if (!isset($rows[$index])) {
            return null;
        }

        if ($what === 'remove') {
            array_splice($rows, $index, 1);
            $data[$outer][$inner] = array_values($rows);
            return [$data, 'Removed. Nothing is written to the site until you save.'];
        }

        $to = $index + ($what === 'up' ? -1 : 1);
        if ($what === 'up' || $what === 'down') {
            if ($to < 0 || $to >= count($rows)) {
                return null;
            }
            [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];
            $data[$outer][$inner] = $rows;
            return [$data, 'Moved. Nothing is written to the site until you save.'];
        }
    }

    return null;
}

/* ---------------------------------------------------------------- actions */

$data = contact_load();
$pending = '';   /* an unsaved change made by a row button */

/* The retry that the failed-publish notice posts, named here rather than read
   inline below. It was undefined: a $_POST key that is only ever compared
   reads exactly like one that was assigned. The comparison was therefore
   always false, the retry fell through to the save path, and that path
   rebuilds the whole document from the form — which on this three-field retry
   meant an emptied page and a wall of validation errors, on the one route
   somebody reaches for when a publish has already failed. */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

/* Filled by contact_take_uploads(). Declared here because a row button that
   fails to store a picture has to report it too, and that path does not go
   near $errors. */
$upload_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    /* Sent again by hand, from the notice a failed publish leaves. Nothing is
       re-saved and no revision is minted: the record here is already right, and
       what failed was only getting it to the other host. */
    if ($action === 'republish') {
        publish_note(publish_push('contact', $data));

        $note = publish_note();
        admin_redirect('contact', ($note['ok'] ?? false)
            ? 'Sent to the live site — it now holds revision '
              . (int)($note['revision'] ?? 0) . '.'
            : '');
    }


    $do = (string)($_POST['do'] ?? 'save');
    $posted = contact_from_post($data);

    /* BEFORE the branch below, not inside the save. The row buttons submit
       without saving, so a flag chosen and then followed by "move up" would be
       dropped on the floor — and the file input would come back empty with
       nothing to say why. Same reasoning as the company profile's, which is
       why the machinery is shared. */
    $posted = contact_take_uploads($posted, $upload_errors);

    if ($do === 'save') {
        $errors = array_merge($upload_errors, contact_validate($posted));
        if (!$errors) {
            if (contact_save($posted)) {
                admin_redirect('contact', 'Saved the contact page.');
            }
            $errors[] = 'Could not write content/contact.json. Check the file is writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } else {
        $applied = contact_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
        $errors = $upload_errors;
    }
}

/**
 * Take whatever flags were attached, and put each on the office it belongs to.
 *
 * A picture is stored here and sent to the live site immediately rather than
 * at save time, for the two reasons the company profile's does it: the
 * operator finds out at once if the channel is broken, while they still know
 * what they were doing; and a save carrying every flag would re-send all of
 * them for a one-word edit.
 *
 * An orphan is possible — a flag uploaded and the save then abandoned — and is
 * the right trade. It costs disk; the other order costs an edit.
 */
function contact_take_uploads(array $data, array &$errors): array
{
    foreach (admin_uploaded_files() as [$band, $index, $file]) {
        if ($band !== 'offices' || !isset($data['offices']['items'][$index])) {
            continue;
        }

        $stored = upload_accept($file, 'contact.offices');

        if (isset($stored['error'])) {
            $errors[] = 'Office ' . ($index + 1) . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = 'Office ' . ($index + 1) . ': ' . $sent;
            continue;
        }

        $data['offices']['items'][$index]['image'] = contract_image_defaults($stored);
    }

    return $data;
}

/* ---------------------------------------------------------------- helpers */

/* The row head — number, preview, status, and the move/remove controls — is
   admin_card_head() in lib/admin.php. It was a copy of this file's markup that
   sections/company.php did not quite make, which is how the two editors ended
   up laying the same row out differently. */

/* The "On this page" column beside this editor — see COMPANY_OUTLINE. */
const CONTACT_OUTLINE = [
    'band-hero'    => 'The banner',
    'band-form'    => 'The enquiry form',
    'band-reach'   => 'Reach us directly',
    'band-offices' => 'Our offices',
    'band-meta'    => 'Search and sharing',
];

admin_head('contact', $user,
    'Editing <code>content/contact.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/contact/')) . '">the contact page</a> '
    . 'within a second — as soon as the live site accepts the publish.',
    CONTACT_OUTLINE,
    ['form' => 'contact-form', 'label' => 'Save the contact page',
     'discard' => admin_url('contact')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<?php /* enctype, because an office can carry an uploaded flag. Without it a
         file input posts the FILENAME and not the file, silently — the browser
         sends it as an ordinary text field, PHP puts nothing in $_FILES, and
         the editor reports a save that worked while the picture never left the
         machine. It was missing here for exactly as long as the flag upload
         has existed. */ ?>
<form class="admin__form" id="contact-form" method="post" data-async
      enctype="multipart/form-data"
      action="<?= h(admin_url('contact')) ?>">
  <?= admin_form_fields('contact') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add a row".
           This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <!-- ========================= banner ========================= -->
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
        <span class="admin__label">Tagline</span>
        <input class="admin__input" type="text" name="hero[subtitle]"
               value="<?= h($data['hero']['subtitle']) ?>">
        <span class="admin__hint">The line under it. Leave empty to drop it.</span>
      </label>
    </div>
  </fieldset>

  <!-- ========================== form ========================== -->
  <fieldset class="admin__block" id="band-form">
    <?php admin_band_head('The enquiry form',
        'The words around the form. The fields themselves — name, phone, '
        . 'email, service, message — are part of the page and of '
        . 'contact-handler.php, which validates them; they are not editable '
        . 'here.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="form[title]"
               value="<?= h($data['form']['title']) ?>">
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="field-form-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="field-form-lead"
                name="form[lead]" rows="4" data-editor><?= h($data['form']['lead']) ?></textarea>
    </div>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Services offered in the “Type of Service” box</span>
        <textarea class="admin__input admin__textarea" name="form[service_types]"
                  rows="7"><?= h(implode("\n", $data['form']['service_types'])) ?></textarea>
        <span class="admin__hint">
          One per line. They are suggestions, not a fixed list — a visitor can
          type anything, which is deliberate: a closed list turns away work we
          do but have not named.
        </span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Hint under that box</span>
        <input class="admin__input" type="text" name="form[subject_hint]"
               value="<?= h($data['form']['subject_hint']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Reassurance under the Send button</span>
        <input class="admin__input" type="text" name="form[note]"
               value="<?= h($data['form']['note']) ?>">
        <span class="admin__hint">Shown beside a padlock. Leave empty to drop it.</span>
      </label>
    </div>
  </fieldset>

  <!-- ========================== reach ========================== -->
  <fieldset class="admin__block" id="band-reach">
    <?php admin_band_head('Reach us directly',
        'The short list beside the form. A row becomes a link when its kind '
        . 'says it should — an email address opens a mail app, a phone number '
        . 'dials.',
        ['do' => 'reach-add:0', 'label' => 'Add a row'],
        ['name'  => 'reach[status]',
         'value' => $data['reach']['status'],
         'noun'  => 'this section']); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="reach[title]"
               value="<?= h($data['reach']['title']) ?>">
      </label>
    </div>

<?php $rows = $data['reach']['items']; $total = count($rows); ?>
<?php if (!$rows): ?>
    <p class="admin__empty">No rows. The panel beside the form is empty.</p>
<?php endif; ?>

<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <?php admin_card_head('reach', $i, $total, [
          'label'  => $row['label'],
          'noun'   => 'row',
          'detail' => implode(' · ', $row['values']),
          'icon'   => isset(CONTACT_ICONS[$row['icon']]) ? $row['icon'] : '',
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Label</span>
          <input class="admin__input" type="text" name="reach[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>" placeholder="Email">
        </label>

        <label class="admin__field">
          <span class="admin__label">Kind</span>
          <select class="admin__input" name="reach[items][<?= $i ?>][type]">
<?php foreach (CONTACT_REACH_TYPES as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $row['type'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="reach[items][<?= $i ?>][icon]">
            <option value="">No icon</option>
<?php foreach (CONTACT_ICONS as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $row['icon'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Value</span>
          <textarea class="admin__input admin__textarea" rows="3"
                    name="reach[items][<?= $i ?>][values]"><?= h(implode("\n", $row['values'])) ?></textarea>
          <span class="admin__hint">
            The address, number, web address or sentence — whichever the kind
            says. <strong>One per line if there is more than one</strong>: three
            numbers here become three dialling links under a single “Phone”
            heading, rather than three separate rows. Use “Add a row” instead
            when the numbers deserve headings of their own.
          </span>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Link text</span>
          <input class="admin__input" type="text" name="reach[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
          <span class="admin__hint">
            Optional, and only useful for a web address with one value:
            “Tech4TIME” reads better than the whole LinkedIn URL. Ignored when
            the row has several values, since they would all read alike.
          </span>
        </label>
      </div>

      <?php admin_status_field("reach[items][$i][status]",
          $row['status'], 'this row'); ?>
    </div>
<?php endforeach; ?>

    <div class="admin__actions">
    </div>
  </fieldset>

  <!-- ========================= offices ========================= -->
  <fieldset class="admin__block" id="band-offices">
    <?php admin_band_head('Our offices',
        'The band at the foot of the page, one card per office.',
        ['do' => 'offices-add:0', 'label' => 'Add an office'],
        ['name'  => 'offices[status]',
         'value' => $data['offices']['status'],
         'noun'  => 'this section']); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="offices[eyebrow]"
               value="<?= h($data['offices']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="offices[title]"
               value="<?= h($data['offices']['title']) ?>">
      </label>
    </div>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="field-offices-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="field-offices-lead"
                name="offices[lead]" rows="3" data-editor><?= h($data['offices']['lead']) ?></textarea>
    </div>

<?php $flags = contact_flags(); ?>
<?php $rows = $data['offices']['items']; $total = count($rows); ?>
<?php if (!$rows): ?>
    <p class="admin__empty">No offices. The band at the foot of the page is empty.</p>
<?php endif; ?>

<?php foreach ($rows as $i => $office): ?>
    <div class="admin-card<?= $office['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <?php admin_card_head('offices', $i, $total, [
          'label'  => $office['name'],
          'noun'   => 'office',
          'detail' => $office['address'],
          'status' => $office['status'],
      ]); ?>

      <input type="hidden" name="offices[items][<?= $i ?>][id]" value="<?= h($office['id']) ?>">

      <?php /* The built-in flag is SHOWN, not merely named. contact_flag_file()
               finds what the public site would actually draw, so the thumbnail
               here and the flag on the page are the same file rather than two
               guesses at it. */ ?>
      <?php $builtIn = contact_flag_file($office['flag']); ?>
      <?php admin_image_fields(
          "offices[items][$i][image]",
          "upload[offices][$i]",
          $office['image'],
          'flag',
          '',
          $builtIn !== ''
              ? ['src'  => $builtIn,
                 'note' => 'The built-in '
                     . ucwords(str_replace('-', ' ', $office['flag']))
                     . ' flag. Upload one to replace it.']
              : []
      ); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Name</span>
          <input class="admin__input" type="text" name="offices[items][<?= $i ?>][name]"
                 value="<?= h($office['name']) ?>" placeholder="Bangladesh">
          <span class="admin__hint">The card's heading, and the flag's alt text.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Choose from existing flags</span>
          <select class="admin__input" name="offices[items][<?= $i ?>][flag]">
            <option value="">None</option>
<?php foreach ($flags as $flag): ?>
            <option value="<?= h($flag) ?>"<?= $office['flag'] === $flag ? ' selected' : '' ?>><?= h(ucfirst(str_replace('-', ' ', $flag))) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">
<?php if ($office['image']['src'] !== ''): ?>
            <strong>Not in use.</strong> This office has an uploaded flag above,
            and an uploaded one always wins. Remove it to fall back to this
            list.
<?php else: ?>
            The three that were built into the site. Uploading a flag above is
            the way to add any other country — this list cannot grow without a
            developer and a deploy.
<?php endif; ?>
          </span>
        </label>

        <?php admin_status_field("offices[items][$i][status]",
            $office['status'], 'this office'); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Address as it should read</span>
          <input class="admin__input" type="text" name="offices[items][<?= $i ?>][address]"
                 value="<?= h($office['address']) ?>" placeholder="278/3, Manikdi, Dhaka - 1206">
          <span class="admin__hint">One line, exactly as it appears on the card.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Phone numbers</span>
          <textarea class="admin__input admin__textarea" rows="4"
                    name="offices[items][<?= $i ?>][phones]"><?= h(implode("\n", $office['phones'])) ?></textarea>
          <span class="admin__hint">
            One per line, written the way they should be read. The dialling
            link strips the spaces itself.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Opening hours</span>
          <input class="admin__input" type="text" name="offices[items][<?= $i ?>][hours]"
                 value="<?= h($office['hours']) ?>" placeholder="Sun – Thu: 9:00 AM – 6:00 PM">
          <span class="admin__hint">Optional. Leave empty to drop the line.</span>
        </label>
      </div>

      <?php /* Closed by default. These change once, when an office opens, and
               a visitor never sees them — putting them in line with the
               address would make every card look like a form to fill in. */ ?>
      <details class="admin__details">
        <summary class="admin__summary">Address for search engines</summary>
        <p class="admin__blurb">
          Not shown to visitors. Google reads these to put the office on a map
          and into a knowledge panel, and it wants the parts separately —
          which is why they are here as well as in the line above.
        </p>
        <div class="admin__grid">
          <label class="admin__field">
            <span class="admin__label">Street</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][street]"
                   value="<?= h($office['schema']['street']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">City</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][locality]"
                   value="<?= h($office['schema']['locality']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">Region or state</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][region]"
                   value="<?= h($office['schema']['region']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">Postcode</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][postal_code]"
                   value="<?= h($office['schema']['postal_code']) ?>">
          </label>
          <label class="admin__field">
            <span class="admin__label">Country code</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][schema][country]"
                   value="<?= h($office['schema']['country']) ?>" maxlength="2" placeholder="BD">
            <span class="admin__hint">Two letters: BD, MY, BE.</span>
          </label>
          <label class="admin__field">
            <span class="admin__label">Languages spoken</span>
            <input class="admin__input" type="text" name="offices[items][<?= $i ?>][languages]"
                   value="<?= h(implode(', ', $office['languages'])) ?>" placeholder="English, Bengali">
            <span class="admin__hint">Comma separated. Defaults to English.</span>
          </label>
        </div>
      </details>
    </div>
<?php endforeach; ?>

    <div class="admin__actions">
    </div>
  </fieldset>

  <!-- ==================== search and sharing ==================== -->
  <?php admin_meta_band('contact'); ?>

</form>

<?php
admin_foot(
    '<p>Last saved ' . h($data['updated'] !== '' ? $data['updated'] : 'never') . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/contact.json.bak</code>.</p>'
);
