<?php
/**
 * Tech4TIME — privacy policy editor.
 *
 * Everything on /pages/privacy-policy/ that is words rather than structure:
 * the banner, the summary box, and one Markdown document per headed section.
 * Stored in content/privacy.json; there is no database.
 *
 * MARKDOWN, NOT CARDS. Sections used to hold blocks, and blocks held rows --
 * three levels of cards for prose. A section is now {heading, status, body},
 * and the body is Markdown in the frozen dialect, edited with the ribbon
 * toolbar (public/assets/js/md-editor.js) or typed by hand. Structure lives
 * in the section; the renderer owns all markup. See ADR 0025 and
 * tech4time-website-frontend/plans/legal-markdown-syntax.md.
 *
 * NOTHING HERE SANITISES A BODY. Markdown source must never meet an HTML
 * sanitiser, which would entity-mangle it; safety lives in the shared
 * lib/markdown.php, which escapes every text run and emits only a fixed tag
 * vocabulary. The one thing this file must never do is pass a body through
 * rt_sanitise_*() -- and test_legal_admin.py asserts the round trip is
 * byte-identical for hostile input, not merely safe-looking.
 *
 * PREVIEW IS THE RENDERER, IN A NEW TAB. The Preview button posts the form
 * and gets back HTML from the same md_render() the page prints -- there is
 * no second implementation in JavaScript to keep identical for ever, and no
 * preview pane inside the editorial page. Previewing saves nothing and
 * publishes nothing.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/privacy.php';
require_once __DIR__ . '/../lib/markdown.php';

/* ---------------------------------------------------------------- reading */

/**
 * Rebuild the whole document from what the browser sent.
 *
 * Bodies are re-trimmed and stored VERBATIM: no sanitising, no normalising
 * of the Markdown itself. The form's constraints are a convenience for
 * whoever is typing, and this is the code that decides what gets stored --
 * which, for source text, is what was typed.
 */
function privacy_from_post(array $current): array
{
    $data = $current;

    foreach (contract_page_bands(PRIVACY_TEXT_FIELDS) as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = trim((string)($_POST[$band][$field] ?? ''));
        }
    }

    $data['cta']['status'] =
        ($_POST['cta']['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown';

    /* The summary box: title and note only. The bullets lived here as rows;
       they live in the note itself now, as a Markdown list -- one field,
       nothing to add, remove or reorder. */
    $callout = (array)($_POST['callout'] ?? []);
    $data['policy']['callout'] = [
        'status' => ($callout['status'] ?? 'shown') === 'hidden' ? 'hidden' : 'shown',
        'title'  => trim((string)($callout['title'] ?? '')),
        'note'   => trim((string)($callout['note'] ?? '')),
    ];

    /* The policy: one Markdown body. Rows arrived keyed by position nowhere
       now -- there is a single field, so there is nothing to renumber and
       nothing that truncating a POST could silently delete a part of. */
    $data['policy']['body'] = trim((string)($_POST['policy']['body'] ?? ''));

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
 * The posted body rendered the way the pane showed it, as a standalone page
 * in a new tab -- preview lives here now, not in the editorial page. Same
 * md_render(), same admin__preview dressing the pane wore, so the tab shows
 * what the pane showed, only full-tab.
 *
 * Never saved, never published: it exits before the shell prints.
 */
function privacy_preview_doc(array $data): void
{
    $policy = $data['policy'] ?? [];
    $hero = $data['hero'] ?? [];

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html>', "\n",
        '<html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow">',
        '<title>Preview — ' . h((string)($hero['title'] ?? 'Privacy Policy')) . '</title>',
        '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/base.css')) . '">',
        '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/theme.css')) . '">',
        '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/admin.css')) . '">',
        '</head><body class="page"><main class="admin__preview">',
        '<p class="legal__eyebrow">Legal · preview, not published — nothing was saved.</p>',
        md_render((string)($policy['body'] ?? '')),
        '</main></body></html>';
    exit;
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
    } elseif ($do === 'preview-tab') {
        /* The rendered body in a new tab (formtarget="_blank" on the
           button): the same render the pane showed, as a standalone page,
           then stop before the shell prints. Saves and publishes nothing. */
        privacy_preview_doc($posted);
    } elseif ($do !== 'nothing') {
        $applied = privacy_apply_row_action($posted, $do);
        $data = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

/**
 * Add, remove or reorder a closing-band button -- the only rows left now
 * that sections and points are prose.
 *
 * Returns null when the press cannot be honoured -- a row that is not there, a
 * move off the end -- and the page redraws unchanged, which is what a button
 * pressed twice before the page caught up should do.
 */
function privacy_apply_row_action(array $data, string $do): ?array
{
    [$verb, $index] = array_pad(explode(':', $do, 2), 2, '');
    $index = (int)$index;

    if (!preg_match('/^button-(add|remove|up|down)$/', $verb, $m)) {
        return null;
    }

    $rows = $data['cta']['items'];
    if ($m[1] === 'add') {
        /* Hidden, as everywhere else here: a half-written clause is never
           live, and this is the page where that matters most. */
        $rows[] = privacy_button_defaults(['status' => 'hidden']);
        $out = [$rows, 'Added a button. It is hidden until you show it.'];
    } else {
        $out = admin_move_row($rows, $m[1], $index);
        if ($out === null) {
            return null;
        }
    }

    $data['cta']['items'] = $out[0];
    return [privacy_identify($data), $out[1]];
}
/* ---------------------------------------------------------------- helpers */

/* What the rail lists under "Privacy". The keys are the ids on the
   <fieldset>s below and the order is the order of the page. */
const PRIVACY_OUTLINE = [
    'band-hero'     => 'The banner',
    'band-facts'    => 'Also on the contact page',
    'band-callout'  => 'The short version',
    'band-policy'   => 'The policy',
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
        <input class="admin__input" type="date" name="policy[effective]" required
               value="<?= h($data['policy']['effective']) ?>">
        <span class="admin__hint">
          When the policy took effect — picked from the calendar, stored as a
          date, printed as "Effective 21 August 2026". <strong>Nothing changes
          this for you.</strong> Fixing a typo is not a new policy.
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

    <div class="admin__grid">
      <div class="admin__field admin__field--wide">
        <label class="admin__label" for="callout-note">The short version, in Markdown</label>
        <textarea class="admin__input admin__textarea" id="callout-note"
                  name="callout[note]" rows="6" data-md><?= h($data['policy']['callout']['note']) ?></textarea>
        <span class="admin__hint">Bullets live here as a Markdown list — one field, nothing to add or remove.</span>
      </div>
    </div>
  </fieldset>

  <!-- ========================= the policy ========================= -->
  <fieldset class="admin__block" id="band-policy">
    <?php admin_band_head('The policy',
        'The whole policy in one Markdown field. Headings mint anchors: '
      . 'a {#custom} suffix pins one, otherwise the heading words do. '
      . 'The ribbon above inserts the syntax.'); ?>

    <div class="admin__field admin__field--wide">
      <label class="admin__label" for="policy-body">The policy, in Markdown</label>
      <textarea class="admin__input admin__textarea admin__textarea--tall" id="policy-body"
                name="policy[body]" rows="40" data-md><?= h((string)($data['policy']['body'] ?? '')) ?></textarea>
    </div>

    <div class="admin__actions">
      <button class="btn btn--secondary" type="submit" name="do" value="preview-tab"
              formtarget="_blank">Preview in new tab</button>
    </div>
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
  <?php admin_meta_band('privacy'); ?>

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
