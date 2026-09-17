<?php
/**
 * Tech4TIME — the milestones editor.
 *
 * The company's timeline: a heading, an introduction, and one dated entry per
 * thing that happened. Stored in content/milestones.json; there is no database.
 *
 * TWO PAGES RENDER WHAT IS EDITED HERE, and only one of them is the milestones
 * page. /pages/company-profile/ shows the most recent MILESTONES_WINDOW years
 * of this same list and links to the full history for the rest — so an entry
 * added here can appear on both pages, on one, or on neither if it is hidden.
 * The blurbs below say so, because it is the one thing about this screen that
 * is not obvious from looking at it.
 *
 * IT USED TO BE A FIELDSET ON THE COMPANY PROFILE FORM. Moving it out is what
 * takes about 140 fields off a form that was posting around 550 against a
 * default max_input_vars of 1000 — see the note in lib/admin.php on
 * admin_form_truncated(). Milestones are the band that grows forever, so they
 * are the band that had to leave.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/company.php
 * and sections/contact.php make. The add, remove and reorder buttons submit
 * the form WITHOUT saving, so nothing typed is lost on the way.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/milestones.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed and re-sanitised here rather than trusted: the
 * form's own constraints are a convenience for whoever is typing, and this is
 * the code that decides what gets stored.
 */
function milestones_from_post(array $current): array
{
    $data = $current;

    foreach (contract_page_bands(MILESTONES_TEXT_FIELDS) as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (MILESTONES_RICH_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = rt_sanitise_html((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (MILESTONES_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    foreach (MILESTONES_LISTS as $band => $filler) {
        $data[$band]['items'] = [];
        foreach (array_values((array)($_POST[$band]['items'] ?? [])) as $row) {
            if (is_array($row)) {
                $data[$band]['items'][] = $filler([
                    'id'     => trim((string)($row['id'] ?? '')),
                    'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
                    'year'   => trim((string)($row['year'] ?? '')),
                    'title'  => trim((string)($row['title'] ?? '')),
                    'text'   => trim((string)($row['text'] ?? '')),
                ]);
            }
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return milestones_identify($data);
}

/**
 * Apply an add / remove / move button to the list.
 *
 * The button's value carries what to do and to which row, as "timeline-up:3".
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function milestones_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    foreach (MILESTONES_LISTS as $band => $filler) {
        if (!str_starts_with($verb, $band . '-')) {
            continue;
        }

        $rows = $data[$band]['items'];
        $what = substr($verb, strlen($band) + 1);

        if ($what === 'add') {
            /* A new row arrives HIDDEN. It has nothing in it yet, and a blank
               entry appearing on two live pages the moment somebody presses
               Add is not what pressing Add means. */
            $rows[] = $filler(['status' => 'hidden']);
            $data[$band]['items'] = $rows;

            return [milestones_identify($data),
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

/* ---------------------------------------------------------------- actions */

$data = milestones_load();
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
        publish_note(publish_push('milestones', $data));

        $note = publish_note();
        admin_redirect('milestones', ($note['ok'] ?? false)
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
        $posted = milestones_from_post($data);
    }

    if ($do === 'save') {
        $errors = array_merge($errors, milestones_validate($posted));
        if (!$errors) {
            if (milestones_save($posted)) {
                admin_redirect('milestones', 'Saved the milestones.');
            }
            $errors[] = 'Could not write content/milestones.json. Check the file is '
                      . 'writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do !== 'nothing') {
        $applied = milestones_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/* What the rail lists under "Milestones". The keys are the ids on the
   <fieldset>s below and the order is the order of the page. */
const MILESTONES_OUTLINE = [
    'band-hero'     => 'The banner',
    'band-timeline' => 'The timeline',
    'band-meta'     => 'Search and sharing',
];

admin_head('milestones', $user,
    'Editing <code>content/milestones.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/milestones/')) . '">the milestones page</a> '
    . 'and on <a href="' . h(public_url('/pages/company-profile/')) . '">the company '
    . 'profile</a> within a second — as soon as the live site accepts the publish.',
    MILESTONES_OUTLINE,
    ['form' => 'milestones-form', 'label' => 'Save the milestones',
     'discard' => admin_url('milestones')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<form class="admin__form" id="milestones-form" method="post" data-async
      action="<?= h(admin_url('milestones')) ?>">
  <?= admin_form_fields('milestones') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add an
           entry". This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

  <!-- ========================= the banner ========================= -->
  <fieldset class="admin__block" id="band-hero">
    <?php admin_band_head('The banner',
        'The band at the top of the milestones page, with the circuitry around '
        . 'it. The company profile has a banner of its own and does not use '
        . 'this one.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Page title</span>
        <input class="admin__input" type="text" name="hero[title]" required
               value="<?= h($data['hero']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under the title</span>
        <input class="admin__input" type="text" name="hero[subtitle]"
               value="<?= h($data['hero']['subtitle']) ?>">
      </label>
    </div>
  </fieldset>

  <!-- ========================= the timeline ========================= -->
  <fieldset class="admin__block" id="band-timeline">
    <?php admin_band_head('The timeline',
        'Entries alternate left and right down the page, so the order decides '
        . 'which side each one lands on. The company profile shows only the '
        . 'five most recent years of this list and links here for the rest, so '
        . 'an older entry is on the milestones page alone. Hiding this section '
        . 'takes the timeline off BOTH pages.',
        ['do' => 'timeline-add:0', 'label' => 'Add a milestone'],
        ['name'  => 'timeline[status]',
         'value' => (string)($data['timeline']['status'] ?? 'shown'),
         'noun'  => 'this section']); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="timeline[eyebrow]"
               value="<?= h($data['timeline']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="timeline[title]"
               value="<?= h($data['timeline']['title']) ?>">
      </label>
    </div>

    <?php /* A <div>, not a <label>, and deliberately: a <label> forwards a
             click from anywhere inside it to its first labelable descendant,
             and editor.js puts its toolbar BEFORE the textarea — so every
             click in the text would press Bold. The plain fields above wrap
             their input in a <label> because there the forwarding is exactly
             what you want; here it is a trap. */ ?>
    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="timeline-lead">Introduction</label>
      <textarea class="admin__input admin__textarea" id="timeline-lead"
                name="timeline[lead]" rows="3" data-editor><?= h($data['timeline']['lead']) ?></textarea>
      <span class="admin__hint">Leave empty to show nothing under the heading.</span>
    </div>

<?php $rows = $data['timeline']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="timeline[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('timeline', $i, $total, [
          'label'  => $row['title'],
          'noun'   => 'entry',
          'detail' => $row['year'],
          'status' => $row['status'],
      ]); ?>

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Year</span>
          <input class="admin__input" type="text" name="timeline[items][<?= $i ?>][year]"
                 value="<?= h($row['year']) ?>" placeholder="2024">
          <?php /* The year is what the company profile's five-year window is
                   sorted by, so it is the one field on this card that is read
                   rather than only printed. milestones_validate() refuses
                   anything that is not a year or a span of two. */ ?>
          <span class="admin__hint">2024, or 2024–2025.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">What happened</span>
          <input class="admin__input" type="text" name="timeline[items][<?= $i ?>][title]"
                 value="<?= h($row['title']) ?>">
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">In a sentence</span>
          <input class="admin__input" type="text" name="timeline[items][<?= $i ?>][text]"
                 value="<?= h($row['text']) ?>">
        </label>
      </div>

      <?php admin_status_field("timeline[items][$i][status]",
          (string)$row['status'], 'this entry'); ?>
    </div>
<?php endforeach; ?>

  </fieldset>

  <!-- ============================ search ============================ -->
  <?php admin_meta_band('milestones'); ?>


  <?php /* The last control in the form, and the only reason it exists is that
           its ABSENCE is readable. See admin_form_tail(). */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/milestones.json.bak</code>.</p>'
);
