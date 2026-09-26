<?php
/**
 * Tech4TIME — the legal hub.
 *
 * Privacy, terms and cookie policies under one hood: what can be edited, in
 * what state each document is, and whether the whole page is shown or gone.
 * Modelled on the SEO hub (?s=seo without a page): a list of cards, each with
 * an Edit link to its own screen. Like that hub it edits no words itself --
 * except one switch per document, which is the whole point of gathering them.
 *
 * TWO SCREENS IN ONE FILE, EVENTUALLY THREE DOCS. ?s=legal lists; the
 * per-document editors live where their documents live (?s=privacy today,
 * ?s=legal&doc=terms tomorrow). The hub links, it does not include: an
 * editor that rendered inside the hub's form would post two documents at
 * once, and the save that forgot half a POST is the failure
 * admin_form_truncated() exists to catch.
 *
 * SHOWING AND HIDING IS A SAVE, NOT A PREFERENCE. Toggling writes the
 * document (status + revision) and publishes it, exactly as an edit does --
 * because a hidden page that the live site never heard about is still live.
 * Hidden answers 404, leaves the sitemap and the pills, and keeps every
 * word for re-showing. Stronger than noindex, which keeps a live page out
 * of search; when both are set, hidden wins without a word, because there
 * is no page to index.
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

/**
 * The documents under this hood, in the order they list.
 *
 * 'editor' is where its Edit link goes; a document with none is planned but
 * not editable yet, and the hub says so plainly rather than linking
 * somewhere that does not exist.
 */
const LEGAL_DOCS = [
    'privacy' => [
        'name'   => 'Privacy Policy',
        'route'  => '/pages/privacy-policy/',
        'editor' => 'privacy',
        'load'   => 'privacy_load',
        'save'   => 'privacy_save',
    ],
    'terms' => [
        'name'   => 'Terms of Service',
        'route'  => '/pages/terms-of-service/',
        'editor' => '',
        'phase'  => 'Phase 3',
    ],
    'cookies' => [
        'name'   => 'Cookie Policy',
        'route'  => '/pages/cookie-policy/',
        'editor' => '',
        'phase'  => 'Phase 4',
    ],
];

/**
 * Show or hide one document: write the status, bump the revision, publish.
 *
 * Returns [ok, message]. A document that cannot be loaded is not toggled --
 * writing a bare status over an unreadable file would replace the policy
 * with defaults and report success.
 */
function legal_toggle(string $doc, string $to): array
{
    if (!isset(LEGAL_DOCS[$doc]) || LEGAL_DOCS[$doc]['editor'] === '') {
        return [false, 'That document has no editor yet.'];
    }
    if ($to !== 'hidden' && $to !== 'shown') {
        return [false, 'A page is shown or hidden, nothing in between.'];
    }

    $load = LEGAL_DOCS[$doc]['load'];
    $save = LEGAL_DOCS[$doc]['save'];
    /* No readability gate: the loader normalises whatever is there (missing
       file included) to the shipped shape, the write is atomic with a .bak
       beside it, and refusing a toggle on an unreadable file would leave a
       page nobody can hide. */
    $data = $load();
    $data['status'] = $to;

    if (!$save($data)) {
        return [false, 'Could not write the document. Check it is writable by PHP.'];
    }

    $note = publish_note();
    $where = $to === 'hidden'
        ? 'Hidden: the page now answers 404 and leaves the sitemap.'
        : 'Shown again: the page is live.';

    if (!($note['ok'] ?? false)) {
        return [false, $where . ' But the live site did not confirm — use Publish again.'];
    }
    return [true, $where];
}

/* ---------------------------------------------------------------- actions */

$errors = [];
$pending = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    $do = (string)($_POST['do'] ?? '');
    if (preg_match('/^(hide|show):([a-z]+)$/', $do, $m)) {
        [$ok, $pending] = legal_toggle($m[2], $m[1] === 'hide' ? 'hidden' : 'shown');
        if (!$ok) {
            $errors[] = $pending;
            $pending = '';
        }
    } elseif ($do !== '') {
        /* The hub holds no words, so there is nothing to save: a Save button
           is not rendered (no $save passed to admin_head below), and a stray
           save posts here only if markup drifted. */
        $errors[] = 'That button did nothing. Press it again if you meant it.';
    }
}

/* ------------------------------------------------------------------ shell */

const LEGAL_OUTLINE = [
    'band-documents' => 'The documents',
];

admin_head('legal', $user,
    'Privacy, terms and cookie policies. Showing and hiding happens here, '
    . 'one switch per page; the words themselves are edited on each page\'s '
    . 'own screen.',
    LEGAL_OUTLINE);

admin_notices($errors);

if ($pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<form class="admin__form" id="legal-form" method="post" data-async
      action="<?= h(admin_url('legal')) ?>">
  <?= admin_form_fields('legal') ?>

  <section class="admin__block" id="band-documents">
    <?php admin_band_head('The documents',
        'One row per legal page. Shown means live; hidden means the page '
        . 'answers 404 and leaves the sitemap and the pills, with every word '
        . 'kept for re-showing. Stronger than the SEO screen\'s Not indexed, '
        . 'which keeps a live page out of search.'); ?>

<?php foreach (LEGAL_DOCS as $key => $doc): ?>
    <div class="admin-card">
      <div class="admin-card__head">
        <span class="admin-card__preview">
          <strong><?= h($doc['name']) ?></strong>
          <span class="admin-card__value"><?= h($doc['route']) ?></span>
        </span>
<?php if ($doc['editor'] !== ''): ?>
<?php   $live = LEGAL_DOCS[$key]['load'](); ?>
        <span class="admin-row__status admin-row__status--<?= ($live['status'] ?? 'shown') === 'hidden' ? 'draft' : 'open' ?>">
          <?= ($live['status'] ?? 'shown') === 'hidden' ? 'Hidden' : 'Shown' ?>
        </span>
<?php   if (($live['status'] ?? 'shown') === 'hidden'): ?>
        <button class="btn btn--secondary" type="submit" name="do" value="show:<?= h($key) ?>">Show</button>
<?php   else: ?>
        <button class="btn btn--secondary" type="submit" name="do" value="hide:<?= h($key) ?>">Hide</button>
<?php   endif; ?>
        <a class="btn btn--secondary" href="<?= h(admin_url($doc['editor'])) ?>">Edit</a>
<?php else: ?>
        <span class="admin-row__status admin-row__status--draft">Planned</span>
        <span class="admin__fineprint">Editor lands in <?= h($doc['phase']) ?>.</span>
<?php endif; ?>
      </div>

<?php if ($doc['editor'] !== ''): ?>
      <p class="admin__fineprint">
        Last saved <?= h((string)($live['updated'] ?: 'never')) ?>,
        revision <?= (int)($live['revision'] ?? 0) ?>.
      </p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>

  <?php /* LAST, and the marker admin_form_truncated() looks for. */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot();
