<?php
/**
 * Tech4TIME — home page editor.
 *
 * Everything on the site's front door that is words or pictures rather than
 * structure: the hero and its badges, tags and terminal; the technical
 * domains; the six service cards; the three "Get to Know Us" cards; and the
 * closing band. Stored in content/home.json; there is no database.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/contact.php,
 * sections/company.php and sections/about.php make, for the same reason. This
 * page is a lot of short fields that are usually changed together, so the whole
 * page is one form, and the add, remove and reorder buttons submit it WITHOUT
 * saving so that nothing typed is lost on the way.
 *
 * SIX LISTS, WHICH IS MORE THAN ANY OTHER SCREEN HERE. Badges, tags, terminal
 * lines, domains, services and destination cards. They are six because the
 * home page is six repeating shapes, and every one of them is content: a
 * fourteenth tag or a seventh service card is a thing to type, not a thing to
 * deploy.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row. Hiding is not deleting: a
 * hidden thing keeps its place and its contents and simply does not render.
 * The hero itself is the one thing that cannot be hidden — a front page with
 * no heading is not a page with a section switched off, it is a broken page.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/home.php';
require_once __DIR__ . '/../lib/upload.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-checked here rather than trusted: the form's
 * own constraints are a convenience for whoever is typing, and this is the code
 * that decides what gets stored.
 */
function home_from_post(array $current): array
{
    $data = $current;

    foreach (HOME_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    /* The closing heading is the one field that keeps its line breaks: it is
       two lines on the page and a two-line textarea here. Carriage returns are
       dropped because a browser posts a textarea with CRLF and the renderer
       looks for "\n"; runs of blank lines collapse, because three <br>s in a
       heading is a mistake rather than a choice. */
    $title = str_replace("\r\n", "\n", (string)($_POST['cta']['title'] ?? ''));
    $title = preg_replace('/\n{2,}/', "\n", $title) ?? $title;
    $data['cta']['title'] = trim($title);

    foreach (HOME_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    foreach (HOME_LISTS as $band => $filler) {
        $data[$band]['items'] = [];
        foreach (array_values((array)($_POST[$band]['items'] ?? [])) as $row) {
            if (is_array($row)) {
                $data[$band]['items'][] = $filler(home_row_from_post($band, $row));
            }
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return home_identify($data);
}

/** One row, cleaned, in whichever shape its list uses. */
function home_row_from_post(string $band, array $row): array
{
    $common = [
        'id'     => trim((string)($row['id'] ?? '')),
        'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
    ];

    $icon = static fn(mixed $v): string =>
        isset(HOME_ICONS[$v ?? '']) ? (string)$v : '';

    if ($band === 'badges' || $band === 'tags') {
        return $common + [
            'icon'  => $icon($row['icon'] ?? ''),
            'label' => trim((string)($row['label'] ?? '')),
        ];
    }

    if ($band === 'terminal') {
        return $common + [
            'kind'   => isset(HOME_LINE_KINDS[$row['kind'] ?? '']) ? (string)$row['kind'] : 'output',
            'tone'   => isset(HOME_LINE_TONES[$row['tone'] ?? '']) ? (string)$row['tone'] : 'plain',
            /* Not trimmed on the right: a shell line can end in a space and
               the panel renders it, because .terminal__line is pre-wrap. */
            'prompt' => trim((string)($row['prompt'] ?? '')),
            'text'   => rtrim(ltrim((string)($row['text'] ?? ''), " \t"), "\r\n"),
        ];
    }

    if ($band === 'capabilities') {
        return $common + [
            'icon'  => $icon($row['icon'] ?? ''),
            'title' => trim((string)($row['title'] ?? '')),
        ];
    }

    if ($band === 'services') {
        return $common + [
            'icon'      => $icon($row['icon'] ?? ''),
            'title'     => trim((string)($row['title'] ?? '')),
            'text'      => trim((string)($row['text'] ?? '')),
            'href'      => trim((string)($row['href'] ?? '')),
            'label'     => trim((string)($row['label'] ?? '')),
            'link_hint' => trim((string)($row['link_hint'] ?? '')),
        ];
    }

    /* destinations */
    return $common + [
        'title'      => trim((string)($row['title'] ?? '')),
        'text'       => trim((string)($row['text'] ?? '')),
        'href'       => trim((string)($row['href'] ?? '')),
        'label'      => trim((string)($row['label'] ?? '')),
        'link_hint'  => trim((string)($row['link_hint'] ?? '')),
        'alt'        => trim((string)($row['alt'] ?? '')),
        'image'      => home_image_from_post($row['image'] ?? []),
        'image_dark' => home_image_from_post($row['image_dark'] ?? []),
    ];
}

/**
 * One picture, from the hidden fields the row carries.
 *
 * The paths are checked against CONTRACT_IMAGE_ROOTS rather than taken as sent.
 * These are hidden inputs, so the browser is not offering a choice here — but
 * a hidden input is a text field with the label removed, and anything a form
 * posts can be posted with something else in it. A src pointing at another
 * origin would put a third party's server in every visitor's page load, and on
 * this page that is every visitor the site has.
 */
function home_image_from_post(mixed $image): array
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

$data = home_load();
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
        publish_note(publish_push('home', $data));

        $note = publish_note();
        admin_redirect('home', ($note['ok'] ?? false)
            ? 'Sent to the live site — it now holds revision '
              . (int)($note['revision'] ?? 0) . '.'
            : '');
    }

    /* Before anything is read out of $_POST, because everything read out of a
       truncated $_POST is a lie by omission — see admin_form_truncated(). This
       screen is the one most likely to hit it: six lists, and thirteen tags in
       one of them before anybody adds a fourteenth. */
    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
        $do = 'nothing';
        $posted = $data;
    } else {
        $do = (string)($_POST['do'] ?? 'save');
        $posted = home_from_post($data);
        $posted = home_take_uploads($posted, $errors);
    }

    if ($do === 'save') {
        /* MERGED, not assigned. $errors already holds anything home_take_uploads()
           put there, and an assignment here would throw away the one message
           explaining why the picture the operator just chose is not on the row. */
        $errors = array_merge($errors, home_validate($posted));
        if (!$errors) {
            if (home_save($posted)) {
                admin_redirect('home', 'Saved the home page.');
            }
            $errors[] = 'Could not write content/home.json. Check the file is '
                      . 'writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do === 'sweep-uploads') {
        $gone = 0;
        foreach (upload_unused(upload_in_use('home', $posted)) as $name) {
            $gone += upload_delete($name) ? 1 : 0;
        }
        $data = $posted;
        $pending = $gone === 1
            ? 'Deleted one picture nothing was using.'
            : "Deleted $gone pictures nothing was using.";
    } elseif ($do !== 'nothing') {
        $applied = home_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Take whatever pictures were attached, and put each on the card it belongs to.
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
function home_take_uploads(array $data, array &$errors): array
{
    /* Two file inputs can land on one card: a light half and a dark one.
       admin_uploaded_files() keys them by the name the input was given, so they
       arrive as two pseudo-bands and land in two fields. */
    $slots = ['destinations' => 'image', 'destinations_dark' => 'image_dark'];

    foreach (admin_uploaded_files() as [$band, $index, $file]) {
        if (!isset($slots[$band]) || !isset($data['destinations']['items'][$index])) {
            continue;
        }

        $where = 'Card ' . ($index + 1)
               . ($band === 'destinations_dark' ? ' (dark mode)' : '');

        $stored = upload_accept($file);

        if (isset($stored['error'])) {
            $errors[] = $where . ': ' . $stored['error'];
            continue;
        }

        $sent = admin_send_picture($stored);
        if ($sent !== '') {
            $errors[] = $where . ': ' . $sent;
            continue;
        }

        $data['destinations']['items'][$index][$slots[$band]] =
            contract_image_defaults($stored);
    }

    return $data;
}

/**
 * Apply an add / remove / move button to one of the six lists.
 *
 * The button's value carries what to do and to which row, as "services-up:3".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function home_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    foreach (HOME_LISTS as $band => $filler) {
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

            return [home_identify($data),
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
function home_band_header(array $data, string $band, string $legend,
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
function home_icon_field(string $name, string $value): void
{
    ?>
        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="<?= h($name) ?>">
            <option value=""<?= $value === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (HOME_ICONS as $icon => $label): ?>
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

/** The label-and-icon pair a badge or a tag is made of. */
function home_pill_rows(array $data, string $band, string $noun): void
{
    $rows  = $data[$band]['items'];
    $total = count($rows);

    foreach ($rows as $i => $row) {
        ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="<?= h($band) ?>[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head($band, $i, $total, [
          'label'  => $row['label'],
          'noun'   => $noun,
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <?php home_icon_field("{$band}[items][$i][icon]", (string)$row['icon']); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Wording</span>
          <input class="admin__input" type="text" name="<?= h($band) ?>[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>">
        </label>
      </div>

      <?php admin_status_field("{$band}[items][$i][status]",
          (string)$row['status'], "this $noun"); ?>
    </div>
        <?php
    }
}

/* What the rail lists under "Home Page". The keys are the ids on the
   <fieldset>s below and the order is the order of the page, so this doubles as
   the table of contents for a form that is otherwise several screens of
   scrolling with no way to see what is in it. Add a band, add a line here. */
const HOME_OUTLINE = [
    'band-hero'         => 'The hero',
    'band-badges'       => 'Hero badges',
    'band-tags'         => 'Hero tags',
    'band-terminal'     => 'The terminal',
    'band-capabilities' => 'Technical domains',
    'band-services'     => 'Service cards',
    'band-destinations' => 'Get to Know Us',
    'band-cta'          => 'The closing band',
    'band-uploads'      => 'Stored pictures',
    'band-meta'         => 'Search and sharing',
];

admin_head('home', $user,
    'Editing <code>content/home.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/')) . '">the home page</a> '
    . 'within a second — as soon as the live site accepts the publish.',
    HOME_OUTLINE,
    ['form' => 'home-form', 'label' => 'Save the home page',
     'discard' => admin_url('home')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<?php /* multipart, because this form carries pictures. Everything else about
         it is an ordinary POST. */ ?>
<form class="admin__form" id="home-form" method="post"
      enctype="multipart/form-data" data-async
      action="<?= h(admin_url('home')) ?>">
  <?= admin_form_fields('home') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add a
           badge". This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <!-- =========================== the hero =========================== -->
  <fieldset class="admin__block" id="band-hero">
    <?php admin_band_head('The hero',
        'The top of the page: the headline and the button under it. This band '
        . 'cannot be hidden — a front page with no heading is a broken page, '
        . 'not a page with a section switched off.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Headline</span>
        <input class="admin__input" type="text" name="hero[title]" required
               value="<?= h($data['hero']['title']) ?>">
        <span class="admin__hint">The big heading. It is the page's only h1.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Highlighted phrase</span>
        <input class="admin__input" type="text" name="hero[accent]"
               value="<?= h($data['hero']['accent']) ?>">
        <span class="admin__hint">
          A word or phrase from the headline above, drawn in the accent colour.
          It has to match exactly, capitals included, or saving will say so.
          Leave it empty for a headline in one colour.
        </span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Button label</span>
        <input class="admin__input" type="text" name="hero[cta_label]"
               value="<?= h($data['hero']['cta_label']) ?>">
        <span class="admin__hint">Leave empty to show no button.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Where it goes</span>
        <input class="admin__input" type="text" name="hero[cta_href]"
               value="<?= h($data['hero']['cta_href']) ?>">
        <span class="admin__hint">
          A path on this site starting with /, or a full https:// address.
        </span>
      </label>
    </div>
  </fieldset>

  <!-- ========================== hero badges ========================== -->
  <fieldset class="admin__block" id="band-badges">
    <?php home_band_header($data, 'badges', 'Hero badges',
        'The four headline disciplines directly under the headline. They are '
        . 'the largest pills, so keep the wording short.',
        'Add a badge'); ?>

    <?php home_pill_rows($data, 'badges', 'badge'); ?>
  </fieldset>

  <!-- =========================== hero tags =========================== -->
  <fieldset class="admin__block" id="band-tags">
    <?php home_band_header($data, 'tags', 'Hero tags',
        'The smaller pills under the badges — the specific things the company '
        . 'does. They wrap onto as many lines as they need.',
        'Add a tag'); ?>

    <?php home_pill_rows($data, 'tags', 'tag'); ?>
  </fieldset>

  <!-- ========================= the terminal ========================= -->
  <fieldset class="admin__block" id="band-terminal">
    <?php home_band_header($data, 'terminal', 'The terminal',
        'The console beside the headline. It is a picture of a shell, not a '
        . 'real one: nothing here runs, and the lines are typed out in order '
        . 'by the browser. Hiding this band leaves the headline full width.',
        'Add a line'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Window title</span>
        <input class="admin__input" type="text" name="terminal[title]"
               value="<?= h($data['terminal']['title']) ?>">
        <span class="admin__hint">The text in the bar at the top of the window.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Described in one line</span>
        <input class="admin__input" type="text" name="terminal[summary]"
               value="<?= h($data['terminal']['summary']) ?>">
        <span class="admin__hint">
          The panel itself is hidden from screen readers, because reading shell
          output line by line is noise. This sentence is read instead, so it has
          to say what the console shows.
        </span>
      </label>
    </div>

<?php $rows = $data['terminal']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="terminal[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('terminal', $i, $total, [
          'label'  => $row['text'],
          'noun'   => 'line',
          'detail' => HOME_LINE_KINDS[$row['kind']] ?? '',
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Kind of line</span>
          <select class="admin__input" name="terminal[items][<?= $i ?>][kind]">
<?php foreach (HOME_LINE_KINDS as $kind => $label): ?>
            <option value="<?= h($kind) ?>"<?= $row['kind'] === $kind ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">
            A command is typed out character by character and carries a prompt
            in front of it. Output arrives whole, the way a shell prints.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Colour</span>
          <select class="admin__input" name="terminal[items][<?= $i ?>][tone]">
<?php foreach (HOME_LINE_TONES as $tone => $label): ?>
            <option value="<?= h($tone) ?>"<?= $row['tone'] === $tone ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">
            Ignored on a command. The tick and the exclamation mark are part of
            the text, so type whichever you want at the start of the line.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Prompt</span>
          <input class="admin__input" type="text" name="terminal[items][<?= $i ?>][prompt]"
                 value="<?= h($row['prompt']) ?>">
          <span class="admin__hint">
            Shown in front of a command. The waiting cursor at the end of the
            session follows the last command's prompt.
          </span>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">The line</span>
          <input class="admin__input" type="text" name="terminal[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php admin_status_field("terminal[items][$i][status]",
          (string)$row['status'], 'this line'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ======================= technical domains ======================= -->
  <fieldset class="admin__block" id="band-capabilities">
    <?php home_band_header($data, 'capabilities', 'Technical domains',
        'The grid of icon-and-title cards. One line each, no prose.',
        'Add a domain'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="capabilities[title]"
               value="<?= h($data['capabilities']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under the heading</span>
        <input class="admin__input" type="text" name="capabilities[lead]"
               value="<?= h($data['capabilities']['lead']) ?>">
      </label>
    </div>

<?php $rows = $data['capabilities']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="capabilities[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('capabilities', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'domain',
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <?php home_icon_field("capabilities[items][$i][icon]", (string)$row['icon']); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="capabilities[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>
      </div>

      <?php admin_status_field("capabilities[items][$i][status]",
          (string)$row['status'], 'this domain'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ========================= service cards ========================= -->
  <fieldset class="admin__block" id="band-services">
    <?php home_band_header($data, 'services', 'Service cards',
        'The six cards that summarise what the company sells. These are also '
        . 'what the page tells a search engine it offers, so a card added here '
        . 'is added to both.',
        'Add a service'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="services[eyebrow]"
               value="<?= h($data['services']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="services[title]"
               value="<?= h($data['services']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under the heading</span>
        <input class="admin__input" type="text" name="services[lead]"
               value="<?= h($data['services']['lead']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">What a search engine calls this list</span>
        <input class="admin__input" type="text" name="services[schema_name]"
               value="<?= h($data['services']['schema_name']) ?>">
        <span class="admin__hint">
          Not shown on the page. It names the list of services in the page's
          structured data, which is what a search result can quote.
        </span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">…and how it describes it</span>
        <input class="admin__input" type="text" name="services[schema_description]"
               value="<?= h($data['services']['schema_description']) ?>">
        <span class="admin__hint">
          Also not shown on the page. Each card's own title and text are used
          for its entry, so those need no second copy here.
        </span>
      </label>
    </div>

<?php $rows = $data['services']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="services[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('services', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'service',
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <?php home_icon_field("services[items][$i][icon]", (string)$row['icon']); ?>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="services[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">What it covers</span>
          <input class="admin__input" type="text" name="services[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
          <span class="admin__hint">
            One or two sentences. This is also the description a search engine
            is given for the service.
          </span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Link label</span>
          <input class="admin__input" type="text" name="services[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Where it goes</span>
          <input class="admin__input" type="text" name="services[items][<?= $i ?>][href]"
                 value="<?= h($row['href']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Said in full for a screen reader</span>
          <input class="admin__input" type="text" name="services[items][<?= $i ?>][link_hint]"
                 value="<?= h($row['link_hint']) ?>">
          <span class="admin__hint">
            Added silently after the link label, so six links reading “View
            Services” are told apart when they are listed on their own. Write it
            to follow the label: “for Cybersecurity”.
          </span>
        </label>
      </div>

      <?php admin_status_field("services[items][$i][status]",
          (string)$row['status'], 'this service'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ======================== get to know us ======================== -->
  <fieldset class="admin__block" id="band-destinations">
    <?php home_band_header($data, 'destinations', 'Get to Know Us',
        'The cards with artwork that send a reader to another page.',
        'Add a card'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="destinations[eyebrow]"
               value="<?= h($data['destinations']['eyebrow']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="destinations[title]"
               value="<?= h($data['destinations']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under the heading</span>
        <input class="admin__input" type="text" name="destinations[lead]"
               value="<?= h($data['destinations']['lead']) ?>">
      </label>
    </div>

<?php $rows = $data['destinations']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="destinations[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('destinations', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'card',
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field admin__field--wide">
          <span class="admin__label">Title</span>
          <input class="admin__input" type="text" name="destinations[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a few words</span>
          <input class="admin__input" type="text" name="destinations[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Button label</span>
          <input class="admin__input" type="text" name="destinations[items][<?= $i ?>][label]"
                 value="<?= h($row['label']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Where it goes</span>
          <input class="admin__input" type="text" name="destinations[items][<?= $i ?>][href]"
                 value="<?= h($row['href']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Said in full for a screen reader</span>
          <input class="admin__input" type="text" name="destinations[items][<?= $i ?>][link_hint]"
                 value="<?= h($row['link_hint']) ?>">
          <span class="admin__hint">
            Added silently after the button label: “about our services”.
          </span>
        </label>
      </div>

      <?php /* A card carries a pair. The dark half is optional and almost
               always empty: the artwork is line drawings that sit on a light
               plate in both colour modes by design, so one picture is the
               normal case and uploading a second is what switches that off for
               this card. */ ?>
      <p class="admin__label">Picture</p>
      <?php admin_image_fields("destinations[items][$i][image]",
                                "upload[destinations][$i]",
                                $row['image']); ?>

      <p class="admin__label">Picture for dark mode</p>
      <?php admin_image_fields(
          "destinations[items][$i][image_dark]",
          "upload[destinations_dark][$i]",
          $row['image_dark'],
          'dark-mode picture',
          '',
          trim((string)$row['image']['src']) !== ''
              ? ['src'  => (string)$row['image']['src'],
                 'note' => 'Using the picture above in both colour modes, which '
                         . 'is how these cards are designed. Upload one here '
                         . 'only if you have artwork made for a dark page.']
              : []
      ); ?>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">What is in the picture</span>
        <input class="admin__input" type="text" name="destinations[items][<?= $i ?>][alt]"
               value="<?= h($row['alt']) ?>">
        <span class="admin__hint">
          Describe it for somebody who cannot see it. Not “photo” or “image” —
          say what it shows. Both halves are described by this one sentence,
          because only one of them is ever on screen.
        </span>
      </label>

      <?php admin_status_field("destinations[items][$i][status]",
          (string)$row['status'], 'this card'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ======================= the closing band ======================= -->
  <fieldset class="admin__block" id="band-cta">
    <?php home_band_header($data, 'cta', 'The closing band',
        'The panel at the foot of the page, with one button.'); ?>

    <div class="admin__grid">
      <div class="admin__field admin__field--wide">
        <label class="admin__label" for="cta-title">Heading</label>
        <textarea class="admin__input admin__textarea" id="cta-title"
                  name="cta[title]" rows="2"><?= h($data['cta']['title']) ?></textarea>
        <span class="admin__hint">
          Two lines. Press Enter where the heading should break; the break is
          kept exactly where you put it.
        </span>
      </div>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under the heading</span>
        <input class="admin__input" type="text" name="cta[text]"
               value="<?= h($data['cta']['text']) ?>">
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

      <?php home_icon_field('cta[icon]', (string)$data['cta']['icon']); ?>
    </div>
  </fieldset>

  <!-- ========================== stored pictures ========================== -->
<?php $unused = upload_problem() === '' ? upload_unused(upload_in_use('home', $data)) : []; ?>
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
  <fieldset class="admin__block" id="band-meta">
    <?php admin_band_head('How it appears elsewhere',
        'What a search engine shows, and what appears when somebody pastes a '
        . 'link to this page into a chat. This is the site\'s front door, so '
        . 'these are the words most people see first.'); ?>

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
    . '<code>content/home.json.bak</code>.</p>'
);
