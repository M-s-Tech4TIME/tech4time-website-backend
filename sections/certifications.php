<?php
/**
 * Tech4TIME — resource certifications editor.
 *
 * Everything on /pages/resource-certifications/ that is words rather than
 * structure: the banner, the band around the list, the role groups, the role
 * names and certifications inside each of them, and the closing band. Stored
 * in content/certifications.json; there is no database.
 *
 * ONE FORM, NOT A LIST AND AN EDIT SCREEN — the same call sections/about.php
 * makes, for the same reason. This page is a lot of short fields usually
 * changed together, so the whole page is one form, and the add, remove and
 * reorder buttons submit it WITHOUT saving so nothing typed is lost.
 *
 * IT FITS IN ONE FORM, AND THAT WAS CHECKED. admin_form_truncated() exists
 * because max_input_vars defaults to 1000 and PHP drops the tail of a larger
 * POST in silence. This page posts about 240 fields — 54 certifications at
 * three each is most of it — so unlike the services editor it does not need
 * splitting by row. It would take roughly 250 more certifications to reach the
 * limit, and admin_form_truncated() is still called either way.
 *
 * THREE LISTS DEEP. A role group holds roles AND certifications, so the row
 * buttons carry the group they belong to in their own name: "cert-2-up:5" is
 * the sixth certification of the third group. admin_card_head() builds that
 * from the band it is handed, which is why the band is "cert-2" and not
 * "cert" — the shared helper is reused rather than forked.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row at every level. Hiding is not
 * deleting: a hidden thing keeps its place and its contents and does not
 * render. It also stops counting — see the note on the counts below.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/certifications.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed here rather than trusted: the form's own
 * constraints are a convenience for whoever is typing, and this is the code
 * that decides what gets stored.
 */
function certifications_from_post(array $current): array
{
    $data = $current;

    foreach (CERTIFICATIONS_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    foreach (CERTIFICATIONS_BANDS as $band) {
        $data[$band]['status'] =
            ($_POST[$band]['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    $data['cta']['items'] = [];
    foreach (array_values((array)($_POST['cta']['items'] ?? [])) as $row) {
        if (is_array($row)) {
            $data['cta']['items'][] = certifications_button_defaults([
                'id'     => trim((string)($row['id'] ?? '')),
                'label'  => trim((string)($row['label'] ?? '')),
                'href'   => trim((string)($row['href'] ?? '')),
                'style'  => ($row['style'] ?? 'primary') === 'ghost' ? 'ghost' : 'primary',
                'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    $data['certs']['items'] = [];
    foreach (array_values((array)($_POST['certs']['items'] ?? [])) as $group) {
        if (is_array($group)) {
            $data['certs']['items'][] = certifications_group_from_post($group);
        }
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return certifications_identify($data);
}

/** One role group, with the two lists inside it. */
function certifications_group_from_post(array $group): array
{
    $roles = [];
    foreach (array_values((array)($group['roles'] ?? [])) as $role) {
        if (is_array($role)) {
            $roles[] = certifications_role_defaults([
                'id'     => trim((string)($role['id'] ?? '')),
                'name'   => trim((string)($role['name'] ?? '')),
                'status' => ($role['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    $certs = [];
    foreach (array_values((array)($group['items'] ?? [])) as $cert) {
        if (is_array($cert)) {
            $certs[] = certifications_cert_defaults([
                'id'     => trim((string)($cert['id'] ?? '')),
                'name'   => trim((string)($cert['name'] ?? '')),
                'status' => ($cert['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    return certifications_group_defaults([
        'id'    => trim((string)($group['id'] ?? '')),
        /* The slug is the anchor, and it is posted as a hidden field rather
           than re-derived: renaming a role must not move the page's own
           fragment out from under every link anyone has made to it. */
        'slug'  => trim((string)($group['slug'] ?? '')),
        'icon'  => isset(CERTIFICATIONS_ICONS[$group['icon'] ?? ''])
                       ? (string)$group['icon'] : '',
        'blurb' => trim((string)($group['blurb'] ?? '')),
        'open'   => ($group['open'] ?? '') === 'open',
        'status' => ($group['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        'roles'  => $roles,
        'items'  => $certs,
    ]);
}

/* ---------------------------------------------------------------- actions */

$data = certifications_load();
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
        publish_note(publish_push('certifications', $data));

        $note = publish_note();
        admin_redirect('certifications', ($note['ok'] ?? false)
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
        $posted = certifications_from_post($data);
    }

    if ($do === 'save') {
        $errors = array_merge($errors, certifications_validate($posted));
        if (!$errors) {
            if (certifications_save($posted)) {
                admin_redirect('certifications', 'Saved the certifications page.');
            }
            $errors[] = 'Could not write content/certifications.json. Check the file '
                      . 'is writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do !== 'nothing') {
        $applied = certifications_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Apply an add / remove / move button to one of the four lists.
 *
 * The button's value carries what to do and to which row. A top-level list
 * reads "group-up:3"; a list inside a group carries the group as well, as
 * "cert-2-up:5" — the sixth certification of the third group. Both shapes come
 * out of admin_card_head(), which builds "<band>-<verb>:<index>" from the band
 * it is handed; the nested bands are handed "cert-2" rather than "cert".
 *
 * Returns the new document and a sentence saying what happened, or null when
 * the instruction did not name anything that exists.
 */
function certifications_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    /* The two top-level lists. */
    if (preg_match('/^(group|button)-(add|remove|up|down)$/', $verb, $m)) {
        $path = $m[1] === 'group' ? ['certs', 'items'] : ['cta', 'items'];
        $rows = $data[$path[0]][$path[1]];

        if ($m[2] === 'add') {
            /* A new row arrives HIDDEN. It has nothing in it yet, and a blank
               group appearing on the live site the moment somebody presses Add
               is not what pressing Add means. */
            $rows[] = $m[1] === 'group'
                ? certifications_group_defaults(['status' => 'hidden'])
                : certifications_button_defaults(['status' => 'hidden']);

            $data[$path[0]][$path[1]] = $rows;

            return [certifications_identify($data),
                    $m[1] === 'group'
                        ? 'Added a role group. It is hidden until you show it — give it '
                          . 'roles and certifications, then save.'
                        : 'Added a button. It is hidden until you show it.'];
        }

        $out = certifications_move($rows, $m[2], $index);
        if ($out === null) {
            return null;
        }
        $data[$path[0]][$path[1]] = $out[0];

        return [$data, $out[1]];
    }

    /* The two lists inside a group. */
    if (preg_match('/^(role|cert)-(\d+)-(add|remove|up|down)$/', $verb, $m)) {
        $g    = (int)$m[2];
        $list = $m[1] === 'role' ? 'roles' : 'items';

        if (!isset($data['certs']['items'][$g])) {
            return null;
        }

        $rows = $data['certs']['items'][$g][$list];

        if ($m[3] === 'add') {
            /* NOT hidden, unlike a group. A role or a certification is one
               short line inside a group that is already on the page or already
               hidden, and adding one that then has to be switched on as well
               is a second step for no protection: an empty name is refused by
               certifications_validate() before it can be saved at all. */
            $rows[] = $m[1] === 'role'
                ? certifications_role_defaults([])
                : certifications_cert_defaults([]);

            $data['certs']['items'][$g][$list] = $rows;

            return [certifications_identify($data),
                    $m[1] === 'role'
                        ? 'Added a role. Name it, then save.'
                        : 'Added a certification. Name it, then save.'];
        }

        $out = certifications_move($rows, $m[3], $index);
        if ($out === null) {
            return null;
        }
        $data['certs']['items'][$g][$list] = $out[0];

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
function certifications_move(array $rows, string $what, int $index): ?array
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
function certifications_band_header(array $data, string $band, string $legend,
                                    string $blurb, string $add = '',
                                    string $addverb = ''): void
{
    admin_band_head(
        $legend,
        $blurb,
        $add !== '' ? ['do' => $addverb . '-add:0', 'label' => $add] : [],
        ['name'  => $band . '[status]',
         'value' => (string)($data[$band]['status'] ?? 'shown'),
         'noun'  => 'this section']
    );
}

/**
 * What the tokens in a field currently come out as.
 *
 * Printed under every field that may hold one, with the live figures beside
 * the names and the finished sentence underneath. Nobody has to remember the
 * syntax or imagine the result: both are on the screen while they type.
 *
 * There is no JavaScript in this. The values are what the document holds on
 * the request that drew the form, which is what the page would say if it were
 * published now — and after a save the form is redrawn, so they follow.
 */
function certifications_token_hint(string $field, string $text, array $counts): void
{
    ?>
      <?php /* OUTSIDE the <label> above, deliberately. A <button> inside a
               <label> activates the label as well as itself, so every press
               would also put the cursor in the textarea it just edited and
               scroll the page to it. */ ?>
      <p class="admin__hint">
        Counts that keep themselves right — press one to put it in the box
        above, and the page fills in the number:
<?php foreach (CERTIFICATIONS_TOKENS as $token): ?>
<?php foreach (['', CERTIFICATIONS_TOKEN_WORD_SUFFIX] as $suffix): ?>
        <button class="btn btn--ghost" type="button"
                data-token-insert="{<?= h($token . $suffix) ?>}"
                data-token-field="<?= h($field) ?>">{<?= h($token . $suffix) ?>}</button>
        <?= $suffix === '' ? (int)$counts[$token]
                           : h(certifications_word((int)$counts[$token])) ?><?php
        ?><?= $token === 'roles' && $suffix !== '' ? '' : ' ·' ?>
<?php endforeach; ?>
<?php endforeach; ?>
      </p>
<?php if (str_contains($text, '{')): ?>
      <p class="admin__hint"><strong>Reads as:</strong>
        <?= h(certifications_fill($text, $counts)) ?></p>
<?php endif; ?>
    <?php
}

/** One icon picker, with the live preview beside it. */
function certifications_icon_field(string $name, string $value): void
{
    ?>
      <label class="admin__field">
        <span class="admin__label">Icon</span>
        <span class="admin__icon-row">
          <select class="admin__input" name="<?= h($name) ?>">
<?php foreach (CERTIFICATIONS_ICONS as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $value === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__icon-preview"><?= admin_icon($value, 'icon') ?></span>
        </span>
      </label>
    <?php
}

/* What the rail lists under "Certifications". The keys are the ids on the
   <fieldset>s below and the order is the order of the page, so this doubles as
   the table of contents for a form that is otherwise several screens of
   scrolling with no way to see what is in it. Add a band, add a line here. */
const CERTIFICATIONS_OUTLINE = [
    'band-hero'  => 'The banner',
    'band-certs' => 'Role groups',
    'band-cta'   => 'The closing band',
    'band-meta'  => 'Search and sharing',
];

$counts = certifications_counts($data);

admin_head('certifications', $user,
    'Editing <code>content/certifications.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/resource-certifications/'))
    . '">the certifications page</a> within a second — as soon as the live '
    . 'site accepts the publish.',
    CERTIFICATIONS_OUTLINE,
    ['form' => 'certifications-form', 'label' => 'Save the certifications page',
     'discard' => admin_url('certifications')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<form class="admin__form" id="certifications-form" method="post" data-async
      action="<?= h(admin_url('certifications')) ?>">
  <?= admin_form_fields('certifications') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be "Add a role
           group". This is that first button, and it saves. */ ?>
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

  <!-- ======================= the role groups ====================== -->
  <fieldset class="admin__block" id="band-certs">
    <?php certifications_band_header($data, 'certs', 'Role groups',
        'The list itself. Each group is one collapsible panel on the page, '
      . 'holding the roles it covers and the certifications those people hold.',
        'Add a role group', 'group'); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Eyebrow</span>
        <input class="admin__input" type="text" name="certs[eyebrow]"
               value="<?= h($data['certs']['eyebrow']) ?>">
        <span class="admin__hint">The small line above the heading.</span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="certs[title]"
               value="<?= h($data['certs']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Introduction</span>
        <textarea class="admin__input" name="certs[lead]" rows="3"><?= h($data['certs']['lead']) ?></textarea>
      </label>
      <div class="admin__field admin__field--wide">
        <?php certifications_token_hint('certs[lead]',
                                        (string)$data['certs']['lead'], $counts); ?>
      </div>
    </div>

<?php if (!$data['certs']['items']): ?>
    <p class="admin__empty">No role groups yet. Add one to start the list.</p>
<?php endif; ?>

<?php foreach ($data['certs']['items'] as $g => $group): ?>
<?php
    $shown_certs = count(certifications_rows_shown($group['items']));
    $heading     = implode(' / ', array_map(
        static fn(array $r): string => (string)$r['name'],
        certifications_rows_shown($group['roles'])));
?>
    <div class="admin-card">
      <?php admin_card_head('group', $g, count($data['certs']['items']), [
          'label'  => $heading,
          'noun'   => 'role group',
          'detail' => $shown_certs . ' certification' . ($shown_certs === 1 ? '' : 's'),
          'icon'   => (string)$group['icon'],
          'status' => (string)$group['status'],
      ]); ?>

      <input type="hidden" name="certs[items][<?= $g ?>][id]" value="<?= h($group['id']) ?>">
      <?php /* The anchor, kept as it is. It is minted from the first role name
               the day the group is created and then left alone: a link into
               this page is a promise, and renaming a role must not break it. */ ?>
      <input type="hidden" name="certs[items][<?= $g ?>][slug]" value="<?= h($group['slug']) ?>">

      <div class="admin__grid">
        <?php certifications_icon_field("certs[items][$g][icon]", (string)$group['icon']); ?>

        <?php admin_status_field("certs[items][$g][status]", (string)$group['status'],
                                 'this group'); ?>

        <?php /* A select and not a checkbox, for the reason every other yes/no
                 on this screen is one: a bare checkbox is about 13px square,
                 which is under the 24x24 minimum target size at 320px
                 (WCAG 2.2 SC 2.5.8), and there is no styling for one here
                 because nothing else in the admin has ever used one.
                 check_admin_a11y.py measures this and said so. */ ?>
        <label class="admin__field">
          <span class="admin__label">When the page loads</span>
          <select class="admin__input" name="certs[items][<?= $g ?>][open]">
            <option value="closed"<?= $group['open'] ? '' : ' selected' ?>>Closed — a visitor opens it</option>
            <option value="open"<?= $group['open'] ? ' selected' : '' ?>>Open — the list is showing</option>
          </select>
          <span class="admin__hint">More than one group may start open; none
            has to.</span>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Blurb</span>
          <textarea class="admin__input" name="certs[items][<?= $g ?>][blurb]"
                    rows="2"><?= h($group['blurb']) ?></textarea>
          <span class="admin__hint">The line inside the panel, above the list.</span>
        </label>
      </div>

      <?php /* ------------------------- the roles ------------------------- */ ?>
      <div class="admin-card__nested">
        <?php admin_band_head('Roles',
            'The names in the panel heading, shown separated by a slash. '
          . 'The first one gives a new group its web address.',
            ['do' => "role-$g-add:0", 'label' => 'Add a role']); ?>

<?php foreach ($group['roles'] as $r => $role): ?>
        <div class="admin-card admin-card--slim">
          <?php admin_card_head("role-$g", $r, count($group['roles']), [
              'label'  => (string)$role['name'],
              'noun'   => 'role',
              'status' => (string)$role['status'],
          ]); ?>

          <input type="hidden" name="certs[items][<?= $g ?>][roles][<?= $r ?>][id]"
                 value="<?= h($role['id']) ?>">

          <div class="admin__grid">
            <label class="admin__field admin__field--wide">
              <span class="admin__label">Role name</span>
              <input class="admin__input" type="text"
                     name="certs[items][<?= $g ?>][roles][<?= $r ?>][name]"
                     value="<?= h($role['name']) ?>">
            </label>

            <?php admin_status_field("certs[items][$g][roles][$r][status]",
                                     (string)$role['status'], 'this role'); ?>
          </div>
        </div>
<?php endforeach; ?>
      </div>

      <?php /* -------------------- the certifications -------------------- */ ?>
      <div class="admin-card__nested">
        <?php admin_band_head('Certifications',
            'One per qualification. The count in the panel heading is however '
          . 'many of these are shown, so it can never disagree with the list.',
            ['do' => "cert-$g-add:0", 'label' => 'Add a certification']); ?>

<?php foreach ($group['items'] as $c => $cert): ?>
        <div class="admin-card admin-card--slim">
          <?php admin_card_head("cert-$g", $c, count($group['items']), [
              'label'  => (string)$cert['name'],
              'noun'   => 'certification',
              'status' => (string)$cert['status'],
          ]); ?>

          <input type="hidden" name="certs[items][<?= $g ?>][items][<?= $c ?>][id]"
                 value="<?= h($cert['id']) ?>">

          <div class="admin__grid">
            <label class="admin__field admin__field--wide">
              <span class="admin__label">Certification</span>
              <input class="admin__input" type="text"
                     name="certs[items][<?= $g ?>][items][<?= $c ?>][name]"
                     value="<?= h($cert['name']) ?>">
            </label>

            <?php admin_status_field("certs[items][$g][items][$c][status]",
                                     (string)$cert['status'], 'this certification'); ?>
          </div>
        </div>
<?php endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ======================= the closing band ===================== -->
  <fieldset class="admin__block" id="band-cta">
    <?php certifications_band_header($data, 'cta', 'The closing band',
        'The band at the foot of the page, and the buttons on it.',
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
    <p class="admin__empty">No buttons. The band will show its heading and text only.</p>
<?php endif; ?>

<?php foreach ($data['cta']['items'] as $b => $button): ?>
    <div class="admin-card">
      <?php admin_card_head('button', $b, count($data['cta']['items']), [
          'label'  => (string)$button['label'],
          'noun'   => 'button',
          'detail' => (string)$button['href'],
          'status' => (string)$button['status'],
      ]); ?>

      <input type="hidden" name="cta[items][<?= $b ?>][id]" value="<?= h($button['id']) ?>">

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Label</span>
          <input class="admin__input" type="text" name="cta[items][<?= $b ?>][label]"
                 value="<?= h($button['label']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Link</span>
          <input class="admin__input" type="text" name="cta[items][<?= $b ?>][href]"
                 value="<?= h($button['href']) ?>">
          <span class="admin__hint">A path on this site, such as
            <code>/pages/contact/</code>.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Style</span>
          <select class="admin__input" name="cta[items][<?= $b ?>][style]">
            <option value="primary"<?= $button['style'] === 'primary' ? ' selected' : '' ?>>Solid</option>
            <option value="ghost"<?= $button['style'] === 'ghost' ? ' selected' : '' ?>>Outlined</option>
          </select>
        </label>

        <?php admin_status_field("cta[items][$b][status]", (string)$button['status'],
                                 'this button'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ====================== search and sharing ==================== -->
  <fieldset class="admin__block" id="band-meta">
    <?php admin_band_head('Search and sharing',
        'What a search engine lists and what a shared link shows. Not on the '
      . 'page itself.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Browser tab title</span>
        <input class="admin__input" type="text" name="meta[title]" required
               value="<?= h($data['meta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Search description</span>
        <textarea class="admin__input" name="meta[description]" rows="3"><?= h($data['meta']['description']) ?></textarea>
        <span class="admin__hint">Up to 320 characters once the counts are
          filled in — measured on what gets published, not on what is typed.</span>
      </label>
      <div class="admin__field admin__field--wide">
        <?php certifications_token_hint('meta[description]',
                                        (string)$data['meta']['description'], $counts); ?>
      </div>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Title when shared</span>
        <input class="admin__input" type="text" name="meta[share_title]"
               value="<?= h($data['meta']['share_title']) ?>">
        <span class="admin__hint">Shown on a social card instead of the tab title.</span>
      </label>
    </div>
  </fieldset>

  <?php /* Last, so a form posted with the fields above truncated still carries
           it and its ABSENCE is readable. See admin_form_tail(). */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/certifications.json.bak</code>.</p>');
