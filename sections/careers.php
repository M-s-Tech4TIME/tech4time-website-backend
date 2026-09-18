<?php
/**
 * Tech4TIME — job post editor.
 *
 * Add, edit, reorder and remove the posts that appear on /pages/careers/.
 * Everything is stored in content/careers.json; there is no database.
 *
 * Included by admin/index.php, which has already checked the password and
 * started the session. Refuses to run on its own so that it cannot be reached
 * by asking for the file directly, however the server is configured.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/careers.php';

/* ---------------------------------------------------------------- actions */

$data = careers_load();

/** Collect one job from the submitted form. */
function admin_job_from_post(array $existing = []): array
{
    $job = $existing;

    foreach (CAREERS_TEXT_FIELDS as $field) {
        if ($field === 'id') {
            continue;
        }
        $job[$field] = trim((string)($_POST[$field] ?? ''));
    }

    /* Whatever the browser sent is re-sanitised here. The editor's own
       allow-list is a convenience for whoever is typing; this is the one that
       decides what gets stored. */
    foreach (CAREERS_RICH_FIELDS as $field) {
        $job[$field] = careers_sanitise_html((string)($_POST[$field] ?? ''));
    }

    return $job;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
$editId = (string)($_GET['id'] ?? $_POST['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    if ($action === 'save') {
        $existing = $editId !== '' ? careers_find($data, $editId) : null;
        $job = admin_job_from_post($existing ?? []);
        $errors = careers_validate($job);

        if (!$errors) {
            if ($existing) {
                $job['id'] = $existing['id'];
                foreach ($data['jobs'] as $i => $row) {
                    if (($row['id'] ?? '') === $existing['id']) {
                        $data['jobs'][$i] = $job;
                        break;
                    }
                }
                $notice = 'Saved “' . $job['title'] . '”.';
            } else {
                $taken = array_map(static fn($j) => (string)($j['id'] ?? ''), $data['jobs']);
                $job['id'] = careers_slug($job['title'], $taken);
                if (($job['posted'] ?? '') === '') {
                    $job['posted'] = gmdate('Y-m-d');
                }
                $data['jobs'][] = $job;
                $notice = 'Added “' . $job['title'] . '”.';
            }

            if (careers_save($data)) {
                admin_redirect('careers', $notice);
            }
            $errors[] = 'Could not write content/careers.json. Check the file is writable by PHP.';
        }

        /* Fall through and redraw the form with what was typed. */
        $action = 'edit';
        $formJob = $job;
    }

    /* Sent again by hand, from the notice a failed publish leaves. Nothing is
       re-saved and no revision is minted: the record here is already right, and
       what failed was only getting it to the other host. */
    if ($action === 'republish') {
        publish_note(publish_push('careers', $data));

        $note = publish_note();
        admin_redirect('careers', ($note['ok'] ?? false)
            ? 'Sent to the live site — it now holds revision '
              . (int)($note['revision'] ?? 0) . '.'
            : '');
    }

    if ($action === 'delete') {
        $job = careers_find($data, $editId);
        $data['jobs'] = array_values(array_filter(
            $data['jobs'],
            static fn($j) => ($j['id'] ?? '') !== $editId
        ));
        careers_save($data);
        admin_redirect('careers', 'Deleted “' . ($job['title'] ?? $editId) . '”.');
    }

    if ($action === 'toggle') {
        foreach ($data['jobs'] as $i => $row) {
            if (($row['id'] ?? '') === $editId) {
                $now = ($row['status'] ?? 'open') === 'open' ? 'draft' : 'open';
                $data['jobs'][$i]['status'] = $now;
                careers_save($data);
                admin_redirect('careers',
                    '“' . ($row['title'] ?? '') . '” is now ' .
                    ($now === 'open' ? 'live on the site' : 'a draft, hidden from visitors') . '.');
            }
        }
    }

    if ($action === 'move') {
        $step = ($_POST['direction'] ?? '') === 'up' ? -1 : 1;
        foreach ($data['jobs'] as $i => $row) {
            if (($row['id'] ?? '') === $editId) {
                $to = $i + $step;
                if ($to >= 0 && $to < count($data['jobs'])) {
                    [$data['jobs'][$i], $data['jobs'][$to]] =
                        [$data['jobs'][$to], $data['jobs'][$i]];
                    careers_save($data);
                }
                break;
            }
        }
        admin_redirect('careers');
    }

    if ($action === 'settings') {
        $url = trim((string)($_POST['cv_form_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'The CV form link must be a full URL starting with https://';
        } else {
            $data['cv_form_url'] = $url;
            careers_save($data);
            admin_redirect('careers', 'Saved the CV form link.');
        }
    }
}

/* ---------------------------------------------------------------- helpers */

function admin_textarea_value(array $job, string $field): string
{
    $value = $job[$field] ?? '';
    return is_string($value) ? $value : '';
}

$editing = null;
if ($action === 'edit' || $action === 'new') {
    $editing = $formJob ?? ($editId !== '' ? careers_find($data, $editId) : null) ?? [];
}

$fieldLabels = [
    'about'            => ['About the Role', ''],
    'responsibilities' => ['Key Responsibilities', 'Use the bulleted list button for the points.'],
    'requirements'     => ['Required Skills & Experience', 'Use the bulleted list button for the points.'],
    'must_have'        => ['Must Have', 'Leave empty to hide this section from the post.'],
    'nice_to_have'     => ['Nice to Have', 'Leave empty to hide this section from the post.'],
    'certifications'   => ['Certifications', ''],
    'offers'           => ['What We Offer', 'Use the bulleted list button for the points.'],
];

/* Two screens, not one with a filter: the list of posts, and one post being
   written. Only the list has bands to point at — a post's own form is a single
   run of fields — so the outline is the list's and the editor gets none, which
   is what it had before. */
$outline = $editing === null
    ? ['band-posts'    => 'The job posts',
       'band-settings' => 'Speculative applications',
       'band-meta'     => 'Search and sharing']
    : [];

admin_head('careers', $user,
    'Editing <code>content/careers.json</code>. Changes go live on '
    . '<a href="' . h(public_url('/pages/careers/')) . '">the careers page</a> '
    . 'within a second — as soon as the live site accepts the publish.',
    $outline);

admin_notices($errors);
?>

<?php if ($editing !== null): ?>
    <!-- ============================ editor ============================ -->
    <form class="admin__form" data-async method="post" action="<?= h(admin_url('careers')) ?>">
      <?= admin_form_fields('careers') ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h((string)($editing['id'] ?? '')) ?>">

      <h2 class="admin__section-title">
        <?= ($editing['id'] ?? '') !== '' ? 'Edit post' : 'New post' ?>
      </h2>

      <div class="admin__grid">
        <label class="admin__field admin__field--wide">
          <span class="admin__label">Job title</span>
          <input class="admin__input" type="text" name="title" required
                 value="<?= h((string)($editing['title'] ?? '')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Employment type</span>
          <input class="admin__input" type="text" name="employment_type"
                 list="employment-types" placeholder="Full-Time"
                 value="<?= h((string)($editing['employment_type'] ?? '')) ?>">
          <span class="admin__hint">Full-Time, Part-Time, Contractor, Intern…</span>
        </label>
        <datalist id="employment-types">
          <option value="Full-Time"><option value="Part-Time"><option value="Contractor">
          <option value="Temporary"><option value="Intern">
        </datalist>

        <label class="admin__field">
          <span class="admin__label">Work arrangement</span>
          <input class="admin__input" type="text" name="work_arrangement"
                 placeholder="On-site"
                 value="<?= h((string)($editing['work_arrangement'] ?? '')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Location</span>
          <input class="admin__input" type="text" name="location"
                 placeholder="Dhaka, Bangladesh"
                 value="<?= h((string)($editing['location'] ?? 'Dhaka, Bangladesh')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Salary</span>
          <input class="admin__input" type="text" name="salary" placeholder="Negotiable"
                 value="<?= h((string)($editing['salary'] ?? '')) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Posted</span>
          <input class="admin__input" type="date" name="posted"
                 value="<?= h((string)($editing['posted'] ?? gmdate('Y-m-d'))) ?>">
        </label>

        <label class="admin__field">
          <span class="admin__label">Applications close</span>
          <input class="admin__input" type="date" name="closes"
                 value="<?= h((string)($editing['closes'] ?? '')) ?>">
          <span class="admin__hint">Optional. Google drops a posting once this date passes, so leave it empty rather than guessing.</span>
        </label>

        <label class="admin__field admin__field--wide">
          <span class="admin__label">Apply link</span>
          <input class="admin__input" type="url" name="apply_url" required
                 placeholder="https://forms.gle/…"
                 value="<?= h((string)($editing['apply_url'] ?? '')) ?>">
          <span class="admin__hint">The Google Form for this role. Applicants go straight here.</span>
        </label>

        <label class="admin__field">
          <span class="admin__label">Status</span>
          <select class="admin__input" name="status">
            <option value="open"<?= ($editing['status'] ?? 'open') === 'open' ? ' selected' : '' ?>>Open — visible on the site</option>
            <option value="draft"<?= ($editing['status'] ?? '') === 'draft' ? ' selected' : '' ?>>Draft — hidden from visitors</option>
          </select>
        </label>
      </div>

      <?php /* A <div>, not a <label>, and deliberately.

         A <label> forwards a click from anywhere inside it to its first
         labelable descendant. editor.js inserts its toolbar BEFORE the
         textarea, so that descendant would be the Bold button — and every
         click in the text would silently press it. The other fields on this
         page wrap their input in a <label> because there the forwarding is
         exactly what you want; here it is a trap. */ ?>
      <?php foreach ($fieldLabels as $field => [$label, $hint]): ?>
        <div class="admin__field admin__field--wide">
          <label class="admin__label" for="field-<?= h($field) ?>"><?= h($label) ?></label>
          <textarea class="admin__input admin__textarea" id="field-<?= h($field) ?>"
                    name="<?= h($field) ?>"
                    rows="8" data-editor><?= h(admin_textarea_value($editing, $field)) ?></textarea>
          <?php if ($hint !== ''): ?><span class="admin__hint"><?= h($hint) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="admin__actions">
        <button class="btn btn--primary btn--lg" type="submit">Save post</button>
        <a class="btn btn--ghost btn--lg" href="<?= h(admin_url('careers')) ?>">Cancel</a>
      </div>
    </form>

<?php else: ?>
    <!-- ============================= list ============================= -->
    <div class="admin__toolbar">
      <a class="btn btn--primary" href="<?= h(admin_url('careers', ['action' => 'new'])) ?>">Add a job post</a>
      <span class="admin__count">
        <?= count($data['jobs']) ?> post<?= count($data['jobs']) === 1 ? '' : 's' ?>,
        <?= count(careers_open_jobs($data)) ?> live
      </span>
    </div>

    <?php if (!$data['jobs']): ?>
      <p class="admin__empty">
        No posts yet. The careers page is showing its “Stay Tuned for
        Opportunities” state and inviting visitors to send a CV instead.
      </p>
    <?php endif; ?>

    <ul class="admin__list" id="band-posts">
      <?php foreach ($data['jobs'] as $index => $job): ?>
        <li class="admin-row">
          <div class="admin-row__main">
            <h2 class="admin-row__title"><?= h((string)($job['title'] ?? 'Untitled')) ?></h2>
            <p class="admin-row__meta">
              <?= h(implode(' · ', careers_meta_line($job))) ?>
              <?php if (($job['closes'] ?? '') !== ''): ?>
                · closes <?= h((string)$job['closes']) ?>
              <?php endif; ?>
            </p>
          </div>

          <span class="admin-row__status admin-row__status--<?= h((string)($job['status'] ?? 'open')) ?>">
            <?= ($job['status'] ?? 'open') === 'open' ? 'Live' : 'Draft' ?>
          </span>

          <div class="admin-row__actions">
            <a class="btn btn--secondary" href="<?= h(admin_url('careers', ['action' => 'edit', 'id' => (string)($job['id'] ?? '')])) ?>">Edit</a>

            <form data-async method="post" action="<?= h(admin_url('careers')) ?>">
              <?= admin_form_fields('careers') ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= h((string)($job['id'] ?? '')) ?>">
              <button class="btn btn--ghost" type="submit">
                <?= ($job['status'] ?? 'open') === 'open' ? 'Unpublish' : 'Publish' ?>
              </button>
            </form>

            <form data-async method="post" action="<?= h(admin_url('careers')) ?>">
              <?= admin_form_fields('careers') ?>
              <input type="hidden" name="action" value="move">
              <input type="hidden" name="id" value="<?= h((string)($job['id'] ?? '')) ?>">
              <button class="btn btn--ghost" type="submit" name="direction" value="up"
                      aria-label="Move up"<?= $index === 0 ? ' disabled' : '' ?>>↑</button>
              <button class="btn btn--ghost" type="submit" name="direction" value="down"
                      aria-label="Move down"<?= $index === count($data['jobs']) - 1 ? ' disabled' : '' ?>>↓</button>
            </form>

            <?php /* data-confirm, and not an inline handler attribute. This
                     carried one from before the CSP existed, calling
                     confirm() from the markup, and script-src 'self' refuses
                     exactly that: the browser dropped it, the question was
                     never asked, and Delete has been deleting on the first
                     press ever since. admin-forms.js asks now. */ ?>
            <form data-async method="post" action="<?= h(admin_url('careers')) ?>"
                  data-confirm="Delete this post permanently?">
              <?= admin_form_fields('careers') ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string)($job['id'] ?? '')) ?>">
              <button class="btn btn--ghost admin-row__delete" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- =========================== settings =========================== -->
    <form class="admin__settings" id="band-settings" data-async method="post" action="<?= h(admin_url('careers')) ?>">
      <?= admin_form_fields('careers') ?>
      <input type="hidden" name="action" value="settings">

      <h2 class="admin__section-title">Speculative applications</h2>
      <label class="admin__field admin__field--wide">
        <span class="admin__label">CV form link</span>
        <input class="admin__input" type="url" name="cv_form_url"
               placeholder="https://forms.gle/…"
               value="<?= h((string)($data['cv_form_url'] ?? '')) ?>">
        <span class="admin__hint">
          Where “Ready to take the chance?” sends people. Shown on the careers
          page whether or not any roles are open — and it is the only thing on
          the page when none are.
        </span>
      </label>
      <div class="admin__actions">
        <button class="btn btn--secondary" type="submit">Save link</button>
      </div>

      <?php /* THE ONE EDITOR THAT HAD NO WAY TO ITS OWN SEO SCREEN. Ten of the
               eleven carry this band; careers did not, so the careers page's
               browser tab title and search description were editable at
               ?s=seo&page=careers and reachable from nowhere in the editor that
               owns the page. lib/contract.php records the older half of the
               same story under "THE CAREERS PAGE WAS THE ONE PAGE NOBODY COULD
               RETITLE". It sits after the Save button because that button saves
               the CV link and nothing here — this is a link out, not a field. */ ?>
      <?php admin_meta_band('careers'); ?>
    </form>
<?php endif; ?>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?? 'never')) . '. '
    . 'A backup of the previous version is kept as '
    . '<code>content/careers.json.bak</code>.</p>'
);
