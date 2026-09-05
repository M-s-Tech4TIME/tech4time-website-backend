<?php
/**
 * Tech4TIME — branding & advertisement editor.
 *
 * Everything on /pages/branding-and-advertisement/ that is words or artwork
 * rather than structure: the banner, the band around the logos, each logo
 * variant with its preview and the files people download, the disclaimer, and
 * the closing band. Stored in content/branding.json; there is no database.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/about.php
 * and sections/certifications.php make. This page is a lot of short fields
 * usually changed together, so the whole page is one form, and the add, remove
 * and reorder buttons submit it WITHOUT saving so nothing typed is lost.
 *
 * IT FITS IN ONE FORM, AND THAT WAS CHECKED. admin_form_truncated() exists
 * because max_input_vars defaults to 1000 and PHP drops the tail of a larger
 * POST in silence. This page posts roughly ninety fields, so it is nowhere
 * near it — and admin_form_truncated() is still called either way.
 *
 * TWO LISTS DEEP. A logo variant holds the files it offers, so the row buttons
 * carry the variant they belong to in their own name: "file-2-up:1" is the
 * second download of the third logo. admin_card_head() builds that from the
 * band it is handed, which is why the band is "file-2" and not "file" — the
 * shared helper is reused rather than forked. The file inputs do the same
 * thing for the same reason: admin_uploaded_files() casts its index to an int,
 * so the variant goes in the band name, as upload[file-2][1].
 *
 * TWO PICTURES PER VARIANT, AND THEY ARE NOT THE SAME PICTURE. The preview is
 * what the card shows; the files under it are what a visitor came for. On the
 * page as it ships those are an 800px preview and a 1600px download of the
 * same mark. See branding_asset_defaults() in lib/contract.php.
 *
 * A DOWNLOAD MAY BE A VECTOR. That is why the download slots pass $vector to
 * admin_image_fields() and the preview slot does not: the public page LINKS to
 * a vector file and never draws one. lib/svg.php is what makes it publishable
 * and the amendment to ADR 0019 is why it is allowed at all.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row at both levels. Hiding is not
 * deleting: a hidden thing keeps its place and its contents and does not
 * render.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/branding.php';
require_once __DIR__ . '/../lib/upload.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed here rather than trusted: the form's own
 * constraints are a convenience for whoever is typing, and this is the code
 * that decides what gets stored.
 */
function branding_from_post(array $current): array
{
    $data = $current;

    foreach (BRANDING_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (BRANDING_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    $data['legal']['items'] = [];
    foreach (array_values((array)($_POST['legal']['items'] ?? [])) as $row) {
        if (is_array($row)) {
            $data['legal']['items'][] = branding_note_defaults([
                'id' => trim((string)($row['id'] ?? '')),
                /* Rich text, and the only rich text on this page. Sanitised on
                   the way in here and AGAIN on the far side by
                   contract_sanitise(), because a signature proves where a
                   document came from and not what is inside it. */
                'text'   => rt_sanitise_html((string)($row['text'] ?? '')),
                'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    $data['cta']['items'] = [];
    foreach (array_values((array)($_POST['cta']['items'] ?? [])) as $row) {
        if (is_array($row)) {
            $data['cta']['items'][] = branding_button_defaults([
                'id'     => trim((string)($row['id'] ?? '')),
                'label'  => trim((string)($row['label'] ?? '')),
                'href'   => trim((string)($row['href'] ?? '')),
                'style'  => ($row['style'] ?? 'primary') === 'ghost' ? 'ghost' : 'primary',
                'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    $data['assets']['items'] = [];
    foreach (array_values((array)($_POST['assets']['items'] ?? [])) as $asset) {
        if (is_array($asset)) {
            $data['assets']['items'][] = branding_asset_from_post($asset);
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return branding_identify($data);
}

/** One logo variant, with the list of files inside it. */
function branding_asset_from_post(array $asset): array
{
    $files = [];
    foreach (array_values((array)($asset['files'] ?? [])) as $file) {
        if (is_array($file)) {
            $files[] = branding_file_defaults([
                'id'    => trim((string)($file['id'] ?? '')),
                'label' => trim((string)($file['label'] ?? '')),
                /* branding_safe_filename() in the model empties anything with
                   a separator or a control character in it, and says so
                   through branding_validate(). Trimmed here, judged there. */
                'filename' => trim((string)($file['filename'] ?? '')),
                'status' => ($file['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
                'file'   => branding_image_from_post($file['file'] ?? []),
            ]);
        }
    }

    return branding_asset_defaults([
        'id'     => trim((string)($asset['id'] ?? '')),
        'title'  => trim((string)($asset['title'] ?? '')),
        'text'   => trim((string)($asset['text'] ?? '')),
        'alt'    => trim((string)($asset['alt'] ?? '')),
        'plate'  => isset(BRANDING_PLATES[$asset['plate'] ?? ''])
                        ? (string)$asset['plate'] : 'neutral',
        'status' => ($asset['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        'image'  => branding_image_from_post($asset['image'] ?? []),
        'files'  => $files,
    ]);
}

/**
 * A picture record as it comes back from the form.
 *
 * The four fields are hidden inputs, which is to say text fields with the
 * label taken off: every path is re-checked against CONTRACT_IMAGE_ROOTS,
 * because a src pointing at another origin would put a third party's server in
 * every visitor's page load. Same function as about_image_from_post().
 */
function branding_image_from_post(mixed $image): array
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

$data = branding_load();
$pending = '';   /* an unsaved change made by a row button */

/* Named here, not read inline below. See the note in sections/contact.php: a
   $_POST key that is only ever compared reads exactly like one that was
   assigned, and this was that bug. */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    /* Sent again by hand, from the notice a failed publish leaves. Nothing is
       re-saved and no revision is minted: the record here is already right,
       and what failed was only getting it to the other host. */
    if ($action === 'republish') {
        publish_note(publish_push('branding', $data));

        $note = publish_note();
        admin_redirect('branding', ($note['ok'] ?? false)
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
        $posted = branding_from_post($data);
        $posted = branding_take_uploads($posted, $errors);
    }

    if ($do === 'save') {
        $errors = array_merge($errors, branding_validate($posted));
        if (!$errors) {
            if (branding_save($posted)) {
                admin_redirect('branding', 'Saved the branding page.');
            }
            $errors[] = 'Could not write content/branding.json. Check the file '
                      . 'is writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do !== 'nothing') {
        $applied = branding_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Take whatever files were attached, store them, and send them on.
 *
 * TWO KINDS OF SLOT, AND THEY DO NOT GET THE SAME CEILING. A preview is drawn
 * on the page, so it is reduced to UPLOAD_MAX_DIMENSION like every other
 * picture on the site. A download IS the deliverable — somebody putting the
 * mark on a banner needs more than a screen's worth — so it is allowed up to
 * UPLOAD_MAX_DOWNLOAD_DIMENSION. Nothing else about the path differs: both are
 * still read and replaced rather than checked and kept.
 *
 * The band names carry the nesting, because admin_uploaded_files() casts its
 * index to an int and a download needs two of them: "asset" with the variant's
 * index, "file-<variant>" with the file's.
 */
function branding_take_uploads(array $data, array &$errors): array
{
    foreach (admin_uploaded_files() as [$band, $index, $file]) {
        /* Where it goes, worked out first and written down as indices rather
           than taken as a reference into $data. A reference would have to be
           released on every path out of this loop or the next iteration writes
           through the last one's -- and this is the one function here that no
           local test can reach, because it needs GD. Something that cannot be
           run should at least not need tracing. */
        if ($band === 'asset') {
            $a = $index;
            $f = null;
            $where = 'Logo ' . ($a + 1) . ' preview';
            $max   = UPLOAD_MAX_DIMENSION;
        } elseif (preg_match('/^file-(\d+)$/', $band, $m)) {
            $a = (int)$m[1];
            $f = $index;
            $where = 'Logo ' . ($a + 1) . ', download ' . ($f + 1);
            /* The one difference between the two slots: a preview is drawn on
               the page and a download is the thing somebody came for. */
            $max = UPLOAD_MAX_DOWNLOAD_DIMENSION;
        } else {
            continue;
        }

        $exists = $f === null
            ? isset($data['assets']['items'][$a])
            : isset($data['assets']['items'][$a]['files'][$f]);

        if (!$exists) {
            continue;
        }

        $stored = upload_accept($file, $max);

        if (isset($stored['error'])) {
            $errors[] = $where . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = $where . ': ' . $sent;
            continue;
        }

        $picture = contract_image_defaults($stored);

        if ($f === null) {
            $data['assets']['items'][$a]['image'] = $picture;
        } else {
            $data['assets']['items'][$a]['files'][$f]['file'] = $picture;
        }
    }

    return $data;
}

/**
 * Apply an add / remove / move button to one of the four lists.
 *
 * The button's value carries what to do and to which row. A top-level list
 * reads "asset-up:3"; the list inside a variant carries the variant as well,
 * as "file-2-up:1" — the second download of the third logo. Both shapes come
 * out of admin_card_head(), which builds "<band>-<verb>:<index>" from the band
 * it is handed; the nested band is handed "file-2" rather than "file".
 *
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function branding_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    /* The three top-level lists. */
    if (preg_match('/^(asset|note|button)-(add|remove|up|down)$/', $verb, $m)) {
        $band = ['asset' => 'assets', 'note' => 'legal', 'button' => 'cta'][$m[1]];
        $rows = $data[$band]['items'];

        if ($m[2] === 'add') {
            /* A new variant arrives HIDDEN. It has no picture and no files
               yet, and a blank card appearing on the live site the moment
               somebody presses Add is not what pressing Add means.

               A paragraph and a button arrive hidden for the same reason: both
               are one thing a visitor would read, and an empty one is refused
               by branding_validate() before it could be saved anyway. */
            $rows[] = match ($m[1]) {
                'asset'  => branding_asset_defaults(['status' => 'hidden']),
                'note'   => branding_note_defaults(['status' => 'hidden']),
                'button' => branding_button_defaults(['status' => 'hidden']),
            };

            $data[$band]['items'] = $rows;

            return [branding_identify($data), match ($m[1]) {
                'asset'  => 'Added a logo. It is hidden until you show it — name it, '
                          . 'give it a preview and at least one file, then save.',
                'note'   => 'Added a disclaimer paragraph. It is hidden until you show it.',
                'button' => 'Added a button. It is hidden until you show it.',
            }];
        }

        $out = branding_move($rows, $m[2], $index);
        if ($out === null) {
            return null;
        }
        $data[$band]['items'] = $out[0];

        return [$data, $out[1]];
    }

    /* The list inside a variant. */
    if (preg_match('/^file-(\d+)-(add|remove|up|down)$/', $verb, $m)) {
        $a = (int)$m[1];

        if (!isset($data['assets']['items'][$a])) {
            return null;
        }

        $rows = $data['assets']['items'][$a]['files'];

        if ($m[2] === 'add') {
            /* NOT hidden, unlike a variant. A download is one button inside a
               card that is already on the page or already hidden, and adding
               one that then has to be switched on as well is a second step for
               no protection: a file row with nothing attached is refused by
               branding_validate() before it can be saved at all. */
            $rows[] = branding_file_defaults([]);
            $data['assets']['items'][$a]['files'] = $rows;

            return [branding_identify($data),
                    'Added a download. Attach the file and give it a saved-as '
                  . 'name, then save.'];
        }

        $out = branding_move($rows, $m[2], $index);
        if ($out === null) {
            return null;
        }
        $data['assets']['items'][$a]['files'] = $out[0];

        return [$data, $out[1]];
    }

    return null;
}

/**
 * Remove or reorder one row of a list.
 *
 * Named once because four lists need it and four copies of an array_splice is
 * four places for an off-by-one to live.
 */
function branding_move(array $rows, string $what, int $index): ?array
{
    if (!isset($rows[$index])) {
        return null;
    }

    if ($what === 'remove') {
        array_splice($rows, $index, 1);

        return [array_values($rows), 'Removed. Nothing is written to the site until you save.'];
    }

    $to = $index + ($what === 'up' ? -1 : 1);
    if ($to < 0 || $to >= count($rows)) {
        return null;
    }

    [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];

    return [$rows, 'Moved. Nothing is written to the site until you save.'];
}

/* ---------------------------------------------------------------- helpers */

/** A band's heading, its show/hide switch, and the blurb under it. */
function branding_band_header(array $data, string $band, string $legend,
                              string $blurb, string $add = '',
                              string $addverb = ''): void
{
    admin_band_head(
        $legend,
        $blurb,
        /* data-rows because the verb and the band are not spelled the same:
           the button says "asset-add" and the fields are "assets[items][".
           admin-forms.js derives the second from the first to put the cursor
           in the row it just made, and without this it looks for
           "asset[items][", finds nothing, and leaves the cursor where it
           was. See nextFocus() in admin-forms.js. */
        $add !== '' ? ['do'    => $addverb . '-add:0',
                       'label' => $add,
                       'rows'  => $band . '[items]['] : [],
        ['name'  => $band . '[status]',
         'value' => (string)($data[$band]['status'] ?? 'shown'),
         'noun'  => 'this section']
    );
}

/* What the rail lists under "Branding". The keys are the ids on the
   <fieldset>s below and the order is the order of the page, so this doubles as
   the table of contents for a form that is otherwise several screens of
   scrolling with no way to see what is in it. Add a band, add a line here. */
const BRANDING_OUTLINE = [
    'band-hero'   => 'The banner',
    'band-assets' => 'Logos and downloads',
    'band-legal'  => 'The disclaimer',
    'band-cta'    => 'The closing band',
    'band-meta'   => 'Search and sharing',
];

admin_head('branding', $user,
    'Editing <code>content/branding.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/branding-and-advertisement/'))
    . '">the branding page</a> within a second — as soon as the live '
    . 'site accepts the publish.',
    BRANDING_OUTLINE,
    ['form' => 'branding-form', 'label' => 'Save the branding page',
     'discard' => admin_url('branding')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<form class="admin__form" id="branding-form" method="post" data-async
      enctype="multipart/form-data"
      action="<?= h(admin_url('branding')) ?>">
  <?= admin_form_fields('branding') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add a
           logo". This is that first button, and it saves. */ ?>
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

  <!-- ===================== logos and downloads ==================== -->
  <fieldset class="admin__block" id="band-assets">
    <?php branding_band_header($data, 'assets', 'Logos and downloads',
        'One card per version of the mark. The preview is what the page shows; '
      . 'the files under it are what a visitor actually gets.',
        'Add a logo', 'asset'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="assets[eyebrow]"
               value="<?= h($data['assets']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="assets[title]"
               value="<?= h($data['assets']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Introduction</span>
        <textarea class="admin__input" name="assets[lead]" rows="3"><?= h($data['assets']['lead']) ?></textarea>
      </label>
    </div>

<?php if (!$data['assets']['items']): ?>
    <p class="admin__empty">No logos yet. Add one to start the list.</p>
<?php endif; ?>

<?php foreach ($data['assets']['items'] as $a => $asset): ?>
<?php $shown_files = count(branding_rows_shown($asset['files'])); ?>
    <div class="admin-card">
      <?php admin_card_head('asset', $a, count($data['assets']['items']), [
          'label'  => (string)$asset['title'],
          'noun'   => 'logo',
          'detail' => $shown_files . ' download' . ($shown_files === 1 ? '' : 's'),
          'status' => (string)$asset['status'],
      ]); ?>

      <input type="hidden" name="assets[items][<?= $a ?>][id]" value="<?= h($asset['id']) ?>">

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Name</span>
          <input class="admin__input" type="text"
                 name="assets[items][<?= $a ?>][title]"
                 value="<?= h($asset['title']) ?>">
          <span class="admin__hint">The heading on the card, like "Light Theme Logo".</span>
        </label>

        <?php admin_status_field("assets[items][$a][status]", (string)$asset['status'],
                                 'this logo'); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Description</span>
          <textarea class="admin__input" name="assets[items][<?= $a ?>][text]"
                    rows="2"><?= h($asset['text']) ?></textarea>
          <span class="admin__hint">One line saying what this version is for.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Background behind the preview</span>
          <select class="admin__input" name="assets[items][<?= $a ?>][plate]">
<?php foreach (BRANDING_PLATES as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $asset['plate'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">
            This does not follow the site's light and dark modes, on purpose: a
            mark in dark ink has to be shown on something pale in BOTH, or it
            is black on black for half the visitors.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Preview description</span>
          <input class="admin__input" type="text"
                 name="assets[items][<?= $a ?>][alt]"
                 value="<?= h($asset['alt']) ?>">
          <span class="admin__hint">
            Read aloud instead of the picture. Say what the mark looks like and
            what it sits on.
          </span>
        </label>

        <div class="admin__field admin__field--wide">
          <span class="admin__label">Preview shown on the card</span>
          <?php admin_image_fields("assets[items][$a][image]", "upload[asset][$a]",
                                   $asset['image'], 'preview',
                                   'No preview yet. The card shows nothing until '
                                 . 'one is uploaded.'); ?>
        </div>
      </div>

      <?php /* ----------------------- the downloads ---------------------- */ ?>
      <div class="admin-card__nested">
        <?php admin_band_head('Downloads',
            'The files this card offers. One button each, in this order. A '
          . 'vector file may go here — the page links to it and never draws it.',
            ['do' => "file-$a-add:0", 'label' => 'Add a download']); ?>

<?php if (!$asset['files']): ?>
        <p class="admin__empty">Nothing to download yet.</p>
<?php endif; ?>

<?php foreach ($asset['files'] as $f => $file): ?>
        <div class="admin-card admin-card--slim">
          <?php admin_card_head("file-$a", $f, count($asset['files']), [
              'label'  => branding_meta_line($file),
              'noun'   => 'download',
              'status' => (string)$file['status'],
          ]); ?>

          <input type="hidden" name="assets[items][<?= $a ?>][files][<?= $f ?>][id]"
                 value="<?= h($file['id']) ?>">

          <div class="admin__grid">
            <label class="admin__field">
              <span class="admin__label">What it is</span>
              <input class="admin__input" type="text"
                     name="assets[items][<?= $a ?>][files][<?= $f ?>][label]"
                     value="<?= h($file['label']) ?>">
              <span class="admin__hint">
                Like "Transparent PNG". The size beside it on the page is not
                typed — it is read off the file, so it cannot go stale. Leave
                this empty and the page just says what format it is.
              </span>
            </label>

            <label class="admin__field">
              <span class="admin__label">Saved as</span>
              <input class="admin__input" type="text"
                     name="assets[items][<?= $a ?>][files][<?= $f ?>][filename]"
                     value="<?= h($file['filename']) ?>">
              <span class="admin__hint">
                What the visitor's computer calls it. No slashes. Without one
                they get the site's own name for the file, which is sixteen
                characters of gibberish.
              </span>
            </label>

            <?php admin_status_field("assets[items][$a][files][$f][status]",
                                     (string)$file['status'], 'this download'); ?>

            <div class="admin__field admin__field--wide">
              <span class="admin__label">The file people get</span>
              <?php admin_image_fields(
                  "assets[items][$a][files][$f][file]", "upload[file-$a][$f]",
                  $file['file'], 'file',
                  'Nothing attached yet. This row does nothing until there is.',
                  [], true, UPLOAD_MAX_DOWNLOAD_DIMENSION); ?>
            </div>
          </div>
        </div>
<?php endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ======================= the disclaimer ======================= -->
  <fieldset class="admin__block" id="band-legal">
    <?php branding_band_header($data, 'legal', 'The disclaimer',
        'The notice about trademarks and permitted use.',
        'Add a paragraph', 'note'); ?>

    <?php /* SAID ONCE, PLAINLY. This is the one band on the site whose words
             have legal weight, and the person editing it is not necessarily
             the person who chose them. */ ?>
    <p class="admin__notice">
      <?= admin_icon('info-circle', 'icon icon--sm') ?>
      This is legal text. It names the Copyright Act 2000 and the Trademarks
      Act 2009 and sets out what may be done with the marks above. Check with
      whoever is responsible for it before changing the wording — this box
      publishes straight to the live site.
    </p>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="legal[title]"
               value="<?= h($data['legal']['title']) ?>">
      </label>
    </div>

<?php if (!$data['legal']['items']): ?>
    <p class="admin__empty">No paragraphs yet.</p>
<?php endif; ?>

<?php foreach ($data['legal']['items'] as $n => $note): ?>
    <div class="admin-card">
      <?php admin_card_head('note', $n, count($data['legal']['items']), [
          'label'  => rt_plain((string)$note['text']),
          'noun'   => 'paragraph',
          'status' => (string)$note['status'],
      ]); ?>

      <input type="hidden" name="legal[items][<?= $n ?>][id]" value="<?= h($note['id']) ?>">

      <div class="admin__grid">
        <?php /* A <div>, not a <label>, and deliberately: a <label> forwards a
                 click from anywhere inside it to its first labelable
                 descendant, and editor.js puts its toolbar BEFORE the textarea
                 — so every click in the text would press Bold. */ ?>
        <div class="admin__field admin__field--wide">
          <label class="admin__label" for="note-<?= $n ?>-text">The paragraph</label>
          <textarea class="admin__input admin__textarea" id="note-<?= $n ?>-text"
                    name="legal[items][<?= $n ?>][text]" rows="5" data-editor><?= h($note['text']) ?></textarea>
          <span class="admin__hint">
            Emphasis and links are allowed here and nowhere else on this page.
          </span>
        </div>

        <?php admin_status_field("legal[items][$n][status]", (string)$note['status'],
                                 'this paragraph'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ====================== the closing band ====================== -->
  <fieldset class="admin__block" id="band-cta">
    <?php branding_band_header($data, 'cta', 'The closing band',
        'The dark band at the foot of the page.',
        'Add a button', 'button'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="cta[title]"
               value="<?= h($data['cta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Text</span>
        <textarea class="admin__input" name="cta[text]" rows="2"><?= h($data['cta']['text']) ?></textarea>
      </label>
    </div>

<?php if (!$data['cta']['items']): ?>
    <p class="admin__empty">No buttons yet.</p>
<?php endif; ?>

<?php foreach ($data['cta']['items'] as $b => $button): ?>
    <div class="admin-card admin-card--slim">
      <?php admin_card_head('button', $b, count($data['cta']['items']), [
          'label'  => (string)$button['label'],
          'noun'   => 'button',
          'status' => (string)$button['status'],
      ]); ?>

      <input type="hidden" name="cta[items][<?= $b ?>][id]" value="<?= h($button['id']) ?>">

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Label</span>
          <input class="admin__input" type="text"
                 name="cta[items][<?= $b ?>][label]" value="<?= h($button['label']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Link</span>
          <input class="admin__input" type="text"
                 name="cta[items][<?= $b ?>][href]" value="<?= h($button['href']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Style</span>
          <select class="admin__input" name="cta[items][<?= $b ?>][style]">
            <option value="primary"<?= $button['style'] === 'primary' ? ' selected' : '' ?>>Filled</option>
            <option value="ghost"<?= $button['style'] === 'ghost' ? ' selected' : '' ?>>Outlined</option>
          </select>
        </label>

        <?php admin_status_field("cta[items][$b][status]", (string)$button['status'],
                                 'this button'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ===================== search and sharing ===================== -->
  <fieldset class="admin__block" id="band-meta">
    <?php admin_band_head('Search and sharing',
        'What a search result and a shared link say. None of this appears on '
      . 'the page itself.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Browser tab title</span>
        <input class="admin__input" type="text" name="meta[title]" required
               value="<?= h($data['meta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Search description</span>
        <textarea class="admin__input" name="meta[description]" rows="3"><?= h($data['meta']['description']) ?></textarea>
        <span class="admin__hint">Up to about 320 characters. Longer and it is cut off.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Title when shared</span>
        <input class="admin__input" type="text" name="meta[share_title]"
               value="<?= h($data['meta']['share_title']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Name in a breadcrumb trail</span>
        <input class="admin__input" type="text" name="meta[breadcrumb]"
               value="<?= h($data['meta']['breadcrumb']) ?>">
        <span class="admin__hint">
          What the site calls this page rather than what the page calls itself
          — the footer link says "Branding &amp; Advertisement" while the
          heading above says something longer. Search results show this one.
        </span>
      </label>
    </div>
  </fieldset>

  <?php /* LAST, and the marker admin_form_truncated() looks for. It has to be
           the final field in the form so that PHP dropping the tail of an
           oversized POST takes it with them and its ABSENCE is readable. See
           admin_form_tail(). */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/branding.json.bak</code>.</p>');
