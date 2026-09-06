<?php
/**
 * Tech4TIME admin — the privacy policy.
 *
 * The last page on the site to come under management, and the one whose words
 * carry the most weight. Twelve headed sections, a summary box, a retention
 * table and an address block, all of it editable here and none of it editable
 * anywhere before.
 *
 * THREE LISTS DEEP, WHICH IS ONE DEEPER THAN ANY EDITOR BEFORE IT. Sections
 * hold blocks; a list block, an address and a table hold rows. The parent
 * indices go in the BAND NAME and never in the index -- "block-3-up:2" is the
 * third section's block two -- because admin_card_head() builds
 * "<band>-<verb>:<index>" and the index is cast to an int. That is the trick
 * certifications proved for two levels and branding repeated; this extends it
 * by one.
 *
 * STRUCTURE IS A KIND, NOT MARKUP. rt_sanitise_html() allows nine tags and no
 * heading, no <address> and no <table> among them, so a person typing <h3>
 * into a rich field would watch it disappear on save with no way to tell that
 * from a bug. Each block declares what it IS and the renderer owns the markup.
 * See PRIVACY_BLOCK_KINDS.
 *
 * NOTHING HERE REFUSES A SAVE BECAUSE OF THE CONTACT PAGE. The policy repeats
 * the offices, the email and the telephone, and whether it still states the
 * current ones is drawn as a standing notice. It is never a refusal: after an
 * office move whichever page you edited first could not be saved, and an
 * unrelated typo fix would be blocked by an address that drifted months ago.
 * See privacy_facts() and privacy_validate().
 *
 * ONE SCREEN, AND THAT IS MEASURED. This form posts roughly 370 inputs against
 * a max_input_vars of 1000 -- more than certifications' 240, less than the
 * company profile's 550. Unlike those, though, it grows with PROSE, which is
 * the thing a legal editor adds most freely: about 150 more paragraphs would
 * reach the limit. admin_form_truncated() is in place regardless, because a
 * truncated POST would let this file rebuild the document from a short $_POST,
 * decide the missing sections had been removed, save that and report success.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/privacy.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Everything is re-trimmed here rather than trusted: the form's own
 * constraints are a convenience for whoever is typing, and this is the code
 * that decides what gets stored.
 *
 * It ends in privacy_normalise() rather than privacy_identify(), and that is
 * deliberate: normalising also NARROWS a block to the fields its kind uses, so
 * changing a block from a list to a paragraph and pressing any button redraws
 * it with the right fields immediately rather than on some later save.
 */
function privacy_from_post(array $current): array
{
    $data = $current;

    foreach (PRIVACY_TEXT_FIELDS as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    $data['cta']['status'] =
        ($_POST['cta']['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';

    /* The summary box. */
    $callout = (array)($_POST['callout'] ?? []);
    $data['policy']['callout'] = [
        'status' => ($callout['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        'title'  => trim((string)($callout['title'] ?? '')),
        'note'   => rt_sanitise_inline((string)($callout['note'] ?? '')),
        'items'  => privacy_items_from_post($callout['items'] ?? []),
    ];

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    $data['policy']['sections'] = [];
    foreach (array_values((array)($_POST['sections'] ?? [])) as $section) {
        if (!is_array($section)) {
            continue;
        }

        $blocks = [];
        foreach (array_values((array)($section['blocks'] ?? [])) as $block) {
            if (is_array($block)) {
                $blocks[] = privacy_block_from_post($block);
            }
        }

        $data['policy']['sections'][] = [
            'id'      => trim((string)($section['id'] ?? '')),
            'heading' => trim((string)($section['heading'] ?? '')),
            'status'  => ($section['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            'blocks'  => $blocks,
        ];
    }

    $data['cta']['items'] = [];
    foreach (array_values((array)($_POST['cta']['items'] ?? [])) as $row) {
        if (is_array($row)) {
            $data['cta']['items'][] = privacy_button_defaults([
                'id'     => trim((string)($row['id'] ?? '')),
                'label'  => trim((string)($row['label'] ?? '')),
                'href'   => trim((string)($row['href'] ?? '')),
                'style'  => ($row['style'] ?? 'primary') === 'ghost' ? 'ghost' : 'primary',
                'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    /* Ids are minted here rather than trusted, so two sections cannot be given
       the same anchor by editing the page's HTML. */
    return privacy_normalise($data);
}

/**
 * One block, read as the kind it says it is.
 *
 * Every field of every kind is read, and privacy_block_defaults() throws away
 * the ones this kind does not use. Reading selectively here instead would mean
 * that changing a block's kind and changing its text in the same press lost
 * one of the two.
 */
function privacy_block_from_post(array $block): array
{
    $kind = (string)($block['kind'] ?? 'paragraph');

    /* Rich text is sanitised on the way in here and AGAIN on the far side by
       contract_sanitise(), because a signature proves where a document came
       from and not what is inside it. A list row is sanitised INLINE-only: it
       renders inside an <li>, where a <p> or a <ul> from a stray Enter is a
       list nested in a list item rather than emphasis somebody meant. */
    $rich = in_array($kind, PRIVACY_RICH_BLOCKS, true);
    $text = (string)($block['text'] ?? '');

    $rows = [];
    foreach (array_values((array)($block['rows'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'id'     => trim((string)($row['id'] ?? '')),
            'text'   => rt_sanitise_inline((string)($row['text'] ?? '')),
            'label'  => trim((string)($row['label'] ?? '')),
            'value'  => trim((string)($row['value'] ?? '')),
            'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        ];
    }

    $columns = (array)($block['columns'] ?? []);

    return privacy_block_defaults([
        'id'      => trim((string)($block['id'] ?? '')),
        'kind'    => $kind,
        'status'  => ($block['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        'text'    => $rich ? rt_sanitise_inline($text) : trim($text),
        'caption' => trim((string)($block['caption'] ?? '')),
        'columns' => [trim((string)($columns[0] ?? '')), trim((string)($columns[1] ?? ''))],
        'rows'    => $rows,
    ]);
}

/** The summary box's bullets. Inline rich text: each one renders in an <li>. */
function privacy_items_from_post(mixed $posted): array
{
    $items = [];

    foreach (array_values((array)$posted) as $row) {
        if (is_array($row)) {
            $items[] = privacy_item_defaults([
                'id'     => trim((string)($row['id'] ?? '')),
                'text'   => rt_sanitise_inline((string)($row['text'] ?? '')),
                'status' => ($row['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
            ]);
        }
    }

    return $items;
}

/* ---------------------------------------------------------------- actions */

$data = privacy_load();
$errors = [];
$pending = '';   /* an unsaved change made by a row button */

/* Named here, not read inline below. See the note in sections/contact.php: a
   $_POST key that is only ever compared reads exactly like one that was
   assigned, and this was that bug. */
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    if ($action === 'republish') {
        publish_note(publish_push('privacy', $data));

        $note = publish_note();
        admin_redirect('privacy', ($note['ok'] ?? false)
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
        $posted = privacy_from_post($data);
    }

    if ($do === 'save') {
        $errors = array_merge($errors, privacy_validate($posted));
        if (!$errors) {
            if (privacy_save($posted)) {
                admin_redirect('privacy', 'Saved the privacy policy.');
            }
            $errors[] = 'Could not write content/privacy.json. Check the file '
                      . 'is writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do !== 'nothing') {
        $applied = privacy_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Add, remove or reorder a row, at whichever of the three levels it names.
 *
 * The verb space, in full:
 *
 *   section-add:0        section-up:3        section-remove:3
 *   block-3-add:0        block-3-up:2        block-3-remove:2
 *   row-3-2-add:0        row-3-2-up:1        row-3-2-remove:1
 *   point-add:0          point-up:1          button-remove:0
 *
 * Returns null when the press cannot be honoured — a row that is not there, a
 * move off the end — and the page redraws unchanged, which is what a button
 * pressed twice before the page caught up should do.
 */
function privacy_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    /* The two flat lists: the summary box's bullets, and the closing band. */
    if (preg_match('/^(point|button)-(add|remove|up|down)$/', $verb, $m)) {
        $rows = $m[1] === 'point'
            ? $data['policy']['callout']['items']
            : $data['cta']['items'];

        if ($m[2] === 'add') {
            /* Hidden, as everywhere else here: a half-written clause is never
               live, and this is the page where that matters most. */
            $rows[] = $m[1] === 'point'
                ? privacy_item_defaults(['status' => 'hidden'])
                : privacy_button_defaults(['status' => 'hidden']);

            $out = [$rows, $m[1] === 'point'
                ? 'Added a summary point. It is hidden until you show it.'
                : 'Added a button. It is hidden until you show it.'];
        } else {
            $out = admin_move_row($rows, $m[2], $index);
            if ($out === null) {
                return null;
            }
        }

        if ($m[1] === 'point') {
            $data['policy']['callout']['items'] = $out[0];
        } else {
            $data['cta']['items'] = $out[0];
        }

        return [privacy_identify($data), $out[1]];
    }

    /* The sections. */
    if (preg_match('/^section-(add|remove|up|down)$/', $verb, $m)) {
        $rows = $data['policy']['sections'];

        if ($m[1] === 'add') {
            $rows[] = privacy_section_defaults(['status' => 'hidden']);
            $out = [$rows, 'Added a section. It is hidden until you show it — give it a '
                         . 'heading, which is also what its web address is made from.'];
        } else {
            $out = admin_move_row($rows, $m[1], $index);
            if ($out === null) {
                return null;
            }
        }

        $data['policy']['sections'] = $out[0];

        return [privacy_identify($data), $out[1]];
    }

    /* The blocks inside one section. */
    if (preg_match('/^block-(\d+)-(add|remove|up|down)$/', $verb, $m)) {
        $s = (int)$m[1];
        if (!isset($data['policy']['sections'][$s])) {
            return null;
        }

        $rows = $data['policy']['sections'][$s]['blocks'];

        if ($m[2] === 'add') {
            /* NOT hidden, unlike a section. A block sits inside a section that
               is already shown or already hidden, and switching it on as well
               is a second step for no protection: an empty block is refused by
               privacy_validate() before it could be saved. */
            $kind = (string)($_POST['addkind'][$s] ?? 'paragraph');
            $rows[] = privacy_block_defaults(['kind' => $kind]);

            $out = [$rows, 'Added a ' . strtolower(PRIVACY_BLOCK_KINDS[$kind]
                        ?? PRIVACY_BLOCK_KINDS['paragraph']) . '.'];
        } else {
            $out = admin_move_row($rows, $m[2], $index);
            if ($out === null) {
                return null;
            }
        }

        $data['policy']['sections'][$s]['blocks'] = $out[0];

        return [privacy_identify($data), $out[1]];
    }

    /* The rows inside one block: a bullet, or a line of the table. */
    if (preg_match('/^row-(\d+)-(\d+)-(add|remove|up|down)$/', $verb, $m)) {
        [$s, $b] = [(int)$m[1], (int)$m[2]];
        if (!isset($data['policy']['sections'][$s]['blocks'][$b]['rows'])) {
            return null;
        }

        $rows = $data['policy']['sections'][$s]['blocks'][$b]['rows'];
        $kind = (string)$data['policy']['sections'][$s]['blocks'][$b]['kind'];

        if ($m[3] === 'add') {
            $rows[] = $kind === 'table'
                ? privacy_cell_defaults([])
                : privacy_item_defaults([]);
            $out = [$rows, $kind === 'table' ? 'Added a table row.' : 'Added a bullet.'];
        } else {
            $out = admin_move_row($rows, $m[3], $index);
            if ($out === null) {
                return null;
            }
        }

        $data['policy']['sections'][$s]['blocks'][$b]['rows'] = $out[0];

        return [privacy_identify($data), $out[1]];
    }

    return null;
}

/* ---------------------------------------------------------------- helpers */

/**
 * A card head's summary of a block, which is not the block.
 *
 * A paragraph card labelled with its whole paragraph is a card head several
 * lines deep, and at 320px it was the widest thing on the page. Bounded at a
 * word boundary, so a head stays one line of summary — which is what a card
 * head is for.
 */
function privacy_card_label(string $text, int $max = 52): string
{
    $text = trim(rt_plain($text));

    if ($text === '' || strlen($text) <= $max) {
        return $text;
    }

    $cut   = substr($text, 0, $max);
    $space = strrpos($cut, ' ');

    return rtrim($space === false ? $cut : substr($cut, 0, $space), " ,.;:") . '…';
}

/**
 * The fields one block shows, which are the fields its kind uses.
 *
 * A block is a card inside a card, so this is the third level of nesting on
 * the page. The band name carries both parents: "row-<section>-<block>".
 */
function privacy_block_fields(int $s, int $b, array $block): void
{
    $name = "sections[$s][blocks][$b]";
    $kind = (string)$block['kind'];
    ?>
    <input type="hidden" name="<?= h($name) ?>[id]" value="<?= h($block['id']) ?>">

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">What this is</span>
        <select class="admin__input" name="<?= h($name) ?>[kind]">
<?php foreach (PRIVACY_BLOCK_KINDS as $value => $label): ?>
          <option value="<?= h($value) ?>"<?= $value === $kind ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
        </select>
        <span class="admin__hint">
          Change this and press any button to redraw the fields below.
        </span>
      </label>

      <?php admin_status_field($name . '[status]', (string)$block['status'], 'this block'); ?>
    </div>

<?php if ($kind === 'subheading'): ?>
    <label class="admin__field admin__field--wide">
      <span class="admin__label">Subheading</span>
      <input class="admin__input" type="text" name="<?= h($name) ?>[text]"
             value="<?= h((string)($block['text'] ?? '')) ?>">
      <span class="admin__hint">
        A smaller heading inside this section. It is plain text — no emphasis
        and no links, because a heading is a landmark and not a sentence.
      </span>
    </label>
<?php elseif (array_key_exists('text', $block)): ?>
    <?php /* A <div>, not a <label>, and deliberately: a <label> forwards a
             click from anywhere inside it to its first labelable descendant,
             and editor.js puts its toolbar BEFORE the textarea — so every
             click in the text would press Bold. */ ?>
    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="block-<?= $s ?>-<?= $b ?>-text">The words</label>
      <textarea class="admin__input admin__textarea" id="block-<?= $s ?>-<?= $b ?>-text"
                name="<?= h($name) ?>[text]" rows="<?= $kind === 'address' ? 6 : 4 ?>"
                data-editor><?= h((string)($block['text'] ?? '')) ?></textarea>
      <span class="admin__hint">
<?php   if ($kind === 'address'): ?>
        The address block. Use the line break button for each line; emphasis
        and links are allowed, which is how the email and telephone here are
        the ones a visitor can actually press.
<?php   elseif ($kind === 'note'): ?>
        Drawn in a tinted box, for something a reader must not skim past.
<?php   else: ?>
        One paragraph. Emphasis and links are allowed.
<?php   endif; ?>
      </span>
    </div>
<?php endif; ?>

<?php if ($kind === 'table'): ?>
    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Caption</span>
        <input class="admin__input" type="text" name="<?= h($name) ?>[caption]"
               value="<?= h((string)($block['caption'] ?? '')) ?>">
        <span class="admin__hint">
          Read out before the table and shown to nobody. Without it a screen
          reader announces this as just "table".
        </span>
      </label>

      <label class="admin__field">
        <span class="admin__label">First column</span>
        <input class="admin__input" type="text" name="<?= h($name) ?>[columns][0]"
               value="<?= h((string)($block['columns'][0] ?? '')) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Second column</span>
        <input class="admin__input" type="text" name="<?= h($name) ?>[columns][1]"
               value="<?= h((string)($block['columns'][1] ?? '')) ?>">
      </label>
    </div>
<?php endif; ?>

<?php if (isset($block['rows'])): ?>
    <div class="admin-card__nested">
      <?php admin_band_head($kind === 'table' ? 'Rows' : 'Bullets', '',
          ['do'    => "row-$s-$b-add:0",
           'label' => $kind === 'table' ? 'Add a row' : 'Add a bullet',
           'rows'  => "sections[$s][blocks][$b][rows]["]); ?>

<?php   foreach ($block['rows'] as $r => $row): ?>
<?php     $rname = "sections[$s][blocks][$b][rows][$r]"; ?>
      <div class="admin-card admin-card--slim">
        <?php admin_card_head("row-$s-$b", $r, count($block['rows']), [
            'label'  => $kind === 'table'
                      ? (string)($row['label'] ?? '')
                      : privacy_card_label((string)($row['text'] ?? '')),
            'noun'   => $kind === 'table' ? 'row' : 'bullet',
            'status' => (string)$row['status'],
        ]); ?>
        <input type="hidden" name="<?= h($rname) ?>[id]" value="<?= h($row['id']) ?>">

<?php     if ($kind === 'table'): ?>
        <div class="admin__grid">
          <label class="admin__field">
            <span class="admin__label">What</span>
            <input class="admin__input" type="text" name="<?= h($rname) ?>[label]"
                   value="<?= h((string)($row['label'] ?? '')) ?>">
          </label>

          <label class="admin__field">
            <span class="admin__label">How long</span>
            <input class="admin__input" type="text" name="<?= h($rname) ?>[value]"
                   value="<?= h((string)($row['value'] ?? '')) ?>">
          </label>

          <?php admin_status_field($rname . '[status]', (string)$row['status'], 'this row'); ?>
        </div>
<?php     else: ?>
        <div class="admin__field admin__field--wide">
          <label class="admin__label" for="row-<?= $s ?>-<?= $b ?>-<?= $r ?>">The bullet</label>
          <textarea class="admin__input admin__textarea" id="row-<?= $s ?>-<?= $b ?>-<?= $r ?>"
                    name="<?= h($rname) ?>[text]" rows="2" data-editor><?= h((string)($row['text'] ?? '')) ?></textarea>
        </div>
        <?php admin_status_field($rname . '[status]', (string)$row['status'], 'this bullet'); ?>
<?php     endif; ?>
      </div>
<?php   endforeach; ?>
    </div>
<?php endif;
}

/* What the rail lists under "Privacy". The keys are the ids on the
   <fieldset>s below and the order is the order of the page. */
const PRIVACY_OUTLINE = [
    'band-hero'     => 'The banner',
    'band-facts'    => 'Also on the contact page',
    'band-callout'  => 'The short version',
    'band-sections' => 'The policy',
    'band-cta'      => 'The closing band',
    'band-meta'     => 'Search and sharing',
];

admin_head('privacy', $user,
    'Editing <code>content/privacy.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/privacy-policy/'))
    . '">the privacy policy</a> within a second — as soon as the live '
    . 'site accepts the publish.',
    PRIVACY_OUTLINE,
    ['form' => 'privacy-form', 'label' => 'Save the privacy policy',
     'discard' => admin_url('privacy')]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<?php admin_standing_notice(
    'Every word on this page is a legal statement about what the company does '
  . 'with people\'s data, and it publishes straight to the live site. Check '
  . 'with whoever is responsible for it before changing the wording — and if '
  . 'what the policy PROMISES changes, change the effective date too.'); ?>

<form class="admin__form" id="privacy-form" method="post" data-async
      action="<?= h(admin_url('privacy')) ?>">
  <?= admin_form_fields('privacy') ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise add a row.
           This is that first button, and it saves. */ ?>
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
      </label>

      <label class="admin__field">
        <span class="admin__label">Effective date</span>
        <input class="admin__input" type="text" name="policy[effective]" required
               value="<?= h($data['policy']['effective']) ?>">
        <span class="admin__hint">
          The whole line, as it appears at the top of the policy — "Effective
          21 August 2026". The wording is yours: "Effective" and "Last updated"
          do not mean the same thing. <strong>Nothing changes this for you.</strong>
          Fixing a typo is not a new policy.
        </span>
      </label>

      <label class="admin__field">
        <span class="admin__label">Name for screen readers</span>
        <input class="admin__input" type="text" name="policy[label]" required
               value="<?= h($data['policy']['label']) ?>">
        <span class="admin__hint">
          The heading nobody sees and everybody using a screen reader hears
          when they reach the policy.
        </span>
      </label>
    </div>
  </fieldset>

  <!-- ======================= what it repeats ====================== -->
  <fieldset class="admin__block" id="band-facts">
    <?php admin_band_head('Also on the contact page',
        'This policy states the offices, the email and the telephone, and so '
      . 'does the contact page. They are kept separately on purpose — a '
      . 'controller\'s details are a legal statement, and one that changed '
      . 'because somebody edited another page would be a statement nobody '
      . 'made. This is only a comparison. Nothing here stops you saving.'); ?>

    <ul class="admin__list">
<?php foreach (privacy_facts($data) as $fact): ?>
      <li class="admin__list-item">
        <strong><?= h($fact['label']) ?></strong>
        <code><?= h($fact['value']) ?></code>
<?php   if ($fact['found']): ?>
        — the policy still says this.
<?php   else: ?>
        — <strong>the policy does not say this.</strong> It may still carry the
        old one. Change it below, or change it on the
        <a href="<?= h(admin_url('contact')) ?>">contact page</a>.
<?php   endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
  </fieldset>

  <!-- ====================== the summary box ====================== -->
  <fieldset class="admin__block" id="band-callout">
    <?php admin_band_head('The short version',
        'The box at the top that says the same things in brief. Every bullet '
      . 'is repeated in full further down; this is the part most people read.',
        ['do' => 'point-add:0', 'label' => 'Add a point', 'rows' => 'callout[items]['],
        ['name'  => 'callout[status]',
         'value' => (string)$data['policy']['callout']['status'],
         'noun'  => 'this box']); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="callout[title]"
               value="<?= h($data['policy']['callout']['title']) ?>">
      </label>
    </div>

<?php if (!$data['policy']['callout']['items']): ?>
    <p class="admin__empty">No points yet. Add one to start the list.</p>
<?php endif; ?>

<?php foreach ($data['policy']['callout']['items'] as $i => $point): ?>
    <div class="admin-card">
      <?php admin_card_head('point', $i, count($data['policy']['callout']['items']), [
          'label'  => privacy_card_label((string)$point['text']),
          'noun'   => 'point',
          'status' => (string)$point['status'],
      ]); ?>
      <input type="hidden" name="callout[items][<?= $i ?>][id]" value="<?= h($point['id']) ?>">

      <div class="admin__field admin__field--wide">
        <label class="admin__label" for="point-<?= $i ?>">The point</label>
        <textarea class="admin__input admin__textarea" id="point-<?= $i ?>"
                  name="callout[items][<?= $i ?>][text]" rows="3" data-editor><?= h($point['text']) ?></textarea>
      </div>

      <?php admin_status_field("callout[items][$i][status]", (string)$point['status'], 'this point'); ?>
    </div>
<?php endforeach; ?>

    <div class="admin__grid">
      <div class="admin__field admin__field--wide">
        <label class="admin__label" for="callout-note">The line under the list</label>
        <textarea class="admin__input admin__textarea" id="callout-note"
                  name="callout[note]" rows="2" data-editor><?= h($data['policy']['callout']['note']) ?></textarea>
      </div>
    </div>
  </fieldset>

  <!-- ========================= the policy ========================= -->
  <fieldset class="admin__block" id="band-sections">
    <?php admin_band_head('The policy',
        'One card per headed section, in the order they appear. A section\'s '
      . 'heading is also what its web address is made from — and once a '
      . 'section has an address it keeps it, because somebody may have linked '
      . 'to it.',
        ['do' => 'section-add:0', 'label' => 'Add a section', 'rows' => 'sections[']); ?>

<?php if (!$data['policy']['sections']): ?>
    <p class="admin__empty">No sections yet. Add one to start the policy.</p>
<?php endif; ?>

<?php foreach ($data['policy']['sections'] as $s => $section): ?>
<?php   $shown = count(privacy_rows_shown($section['blocks'])); ?>
    <div class="admin-card">
      <?php admin_card_head('section', $s, count($data['policy']['sections']), [
          'label'  => (string)$section['heading'],
          'noun'   => 'section',
          'detail' => $shown . ' block' . ($shown === 1 ? '' : 's'),
          'status' => (string)$section['status'],
      ]); ?>
      <input type="hidden" name="sections[<?= $s ?>][id]" value="<?= h($section['id']) ?>">

      <div class="admin__grid">
        <label class="admin__field admin__field--wide">
          <span class="admin__label">Heading</span>
          <input class="admin__input" type="text" name="sections[<?= $s ?>][heading]"
                 value="<?= h($section['heading']) ?>">
          <span class="admin__hint">
            Its web address on the page ends
            <code>#<?= h($section['id']) ?></code>, and renaming the heading
            does not change it — somebody may have linked to it.
          </span>
        </label>

        <?php admin_status_field("sections[$s][status]", (string)$section['status'], 'this section'); ?>
      </div>

      <div class="admin-card__nested">
        <?php admin_band_head('Blocks', '',
            ['do' => "block-$s-add:0", 'label' => 'Add a block',
             'rows' => "sections[$s][blocks]["]); ?>

        <label class="admin__field">
          <span class="admin__label">Add which kind</span>
          <select class="admin__input" name="addkind[<?= $s ?>]">
<?php foreach (PRIVACY_BLOCK_KINDS as $value => $label): ?>
            <option value="<?= h($value) ?>"><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>

<?php   foreach ($section['blocks'] as $b => $block): ?>
        <div class="admin-card admin-card--slim">
          <?php admin_card_head("block-$s", $b, count($section['blocks']), [
              'label'  => privacy_card_label((string)($block['text'] ?? $block['caption'] ?? '')),
              'noun'   => strtolower(PRIVACY_BLOCK_KINDS[$block['kind']]),
              'status' => (string)$block['status'],
          ]); ?>
          <?php privacy_block_fields($s, $b, $block); ?>
        </div>
<?php   endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ====================== the closing band ====================== -->
  <fieldset class="admin__block" id="band-cta">
    <?php admin_band_head('The closing band',
        'The band at the foot of the page, and the ways it offers to get in touch.',
        ['do' => 'button-add:0', 'label' => 'Add a button', 'rows' => 'cta[items]['],
        ['name'  => 'cta[status]',
         'value' => (string)$data['cta']['status'],
         'noun'  => 'this section']); ?>

    <div class="admin__grid">
      <label class="admin__field">
        <span class="admin__label">Heading</span>
        <input class="admin__input" type="text" name="cta[title]"
               value="<?= h($data['cta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Under it</span>
        <textarea class="admin__input" name="cta[text]" rows="2"><?= h($data['cta']['text']) ?></textarea>
      </label>
    </div>

<?php foreach ($data['cta']['items'] as $i => $button): ?>
    <div class="admin-card">
      <?php admin_card_head('button', $i, count($data['cta']['items']), [
          'label'  => (string)$button['label'],
          'noun'   => 'button',
          'status' => (string)$button['status'],
      ]); ?>
      <input type="hidden" name="cta[items][<?= $i ?>][id]" value="<?= h($button['id']) ?>">

      <div class="admin__grid">
        <label class="admin__field">
          <span class="admin__label">Words on it</span>
          <input class="admin__input" type="text" name="cta[items][<?= $i ?>][label]"
                 value="<?= h($button['label']) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Where it goes</span>
          <input class="admin__input" type="text" name="cta[items][<?= $i ?>][href]"
                 value="<?= h($button['href']) ?>">
          <span class="admin__hint">A path like <code>/pages/contact/</code>, or a
            <code>mailto:</code> address.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Look</span>
          <select class="admin__input" name="cta[items][<?= $i ?>][style]">
            <option value="primary"<?= $button['style'] === 'primary' ? ' selected' : '' ?>>Solid</option>
            <option value="ghost"<?= $button['style'] === 'ghost' ? ' selected' : '' ?>>Outlined</option>
          </select>
        </label>

        <?php admin_status_field("cta[items][$i][status]", (string)$button['status'], 'this button'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ===================== search and sharing ==================== -->
  <fieldset class="admin__block" id="band-meta">
    <?php admin_band_head('Search and sharing',
        'What search engines and messaging apps show when this page is found '
      . 'or linked.'); ?>

    <div class="admin__grid">
      <label class="admin__field admin__field--wide">
        <span class="admin__label">Browser tab title</span>
        <input class="admin__input" type="text" name="meta[title]" required
               value="<?= h($data['meta']['title']) ?>">
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Search description</span>
        <textarea class="admin__input" name="meta[description]" rows="3"><?= h($data['meta']['description']) ?></textarea>
        <span class="admin__hint">Up to 320 characters. Longer and it is cut off.</span>
      </label>

      <label class="admin__field admin__field--wide">
        <span class="admin__label">Title when shared</span>
        <input class="admin__input" type="text" name="meta[share_title]"
               value="<?= h($data['meta']['share_title']) ?>">
      </label>

      <label class="admin__field">
        <span class="admin__label">Name in a breadcrumb trail</span>
        <input class="admin__input" type="text" name="meta[breadcrumb]"
               value="<?= h($data['meta']['breadcrumb']) ?>">
      </label>
    </div>
  </fieldset>

  <?php /* LAST, and the marker admin_form_truncated() looks for. It has to be
           the final field in the form so that PHP dropping the tail of an
           oversized POST takes it with them and its ABSENCE is readable. */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/privacy.json.bak</code>.</p>');
