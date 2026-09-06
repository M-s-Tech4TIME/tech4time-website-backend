<?php
/**
 * Tech4TIME — services editor.
 *
 * Everything on /pages/services/ and on the six pages beneath it that is words
 * rather than structure. Stored in content/services.json; there is no database.
 *
 * TWO SCREENS, NOT ONE, AND THAT IS A HARD CONSTRAINT RATHER THAN A CHOICE.
 * PHP's max_input_vars defaults to 1000 and SILENTLY DROPS the tail of a larger
 * POST — see admin_form_truncated(), which exists because of it.
 *
 * MEASURED, not estimated. The seven screens render 308, 371, 333, 279, 276,
 * 243 and 175 inputs — the largest is the HRaaS page, thirty-three solution
 * cards of nine fields each. Each fits with room to spare; all of them on one
 * screen would be about 1985, TWICE the limit, and the tail would be dropped
 * with no error at all. Two of them would happen to fit today, which is the
 * reason the split is by service rather than by "as many as fit": the second
 * is a rule that stops being true the moment somebody adds cards. So:
 *
 *   ?s=services                    the index: its bands, and the list of services
 *   ?s=services&service=<slug>     one service, and only that one
 *
 * Both are ordinary admin_url() links, so admin-swap.js handles them and
 * neither reloads the page.
 *
 * ONE DEVIATION FROM EVERY OTHER EDITOR, AND IT IS DELIBERATE.
 * about_from_post() and its siblings rebuild the WHOLE document from the form,
 * so two people saving at once means the later save wins and nothing else is
 * lost. This editor cannot do that: the service screen holds one service and
 * the other five were never in the form, so they have to be merged back from
 * the file. A read-modify-write without a lock loses one of two concurrent
 * edits to DIFFERENT services — which is the normal case here, not an edge
 * one. Both save paths therefore go through services_edit(), which locks.
 *
 * EVERY BAND CAN BE HIDDEN, and so can every row, every service and every
 * solution. Hiding is not deleting: a hidden thing keeps its place and its
 * contents and simply does not render. Hiding a SERVICE hides the whole of it —
 * its page answers 404 and its block leaves the index — because hiding that
 * left a live link to a dead page would not be hiding.
 *
 * Included by public/index.php, which has already checked the password and
 * started the session.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/services.php';

/* ---------------------------------------------------------------- reading */

/** A field as it was typed, trimmed. */
function services_post_text(mixed $value): string
{
    return trim((string)(is_scalar($value) ? $value : ''));
}

/** An icon field, checked against what the model offers rather than trusted. */
function services_post_icon(mixed $value): string
{
    $value = services_post_text($value);
    return isset(SERVICES_ICONS[$value]) ? $value : '';
}

/** A shown/hidden field. Anything that is not 'hidden' is shown. */
function services_post_status(mixed $value): string
{
    return services_post_text($value) === 'hidden' ? 'hidden' : 'shown';
}

/**
 * A list typed one entry per line.
 *
 * Tags and features are short strings and there are eleven hundred of them
 * across the six pages. As one input each that is eleven hundred inputs, which
 * is over max_input_vars on its own; as a textarea it is one, and it is also
 * how somebody actually wants to type a list. Blank lines are dropped, so the
 * usual trailing newline is not an empty tag.
 */
function services_post_lines(mixed $value): array
{
    $out = [];
    foreach (preg_split('/\R/', (string)(is_scalar($value) ? $value : '')) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** The same list, back in the box. */
function services_lines_value(array $items): string
{
    return implode("\n", array_map('strval', $items));
}

/**
 * Rebuild the INDEX bands from what the browser sent.
 *
 * services.items is not touched: it was not in this form, and the services
 * screen owns it.
 */
function services_index_from_post(array $data): array
{
    foreach (contract_page_bands(SERVICES_TEXT_FIELDS) as $band => $fields) {
        foreach ($fields as $field) {
            $data[$band][$field] = services_post_text($_POST[$band][$field] ?? '');
        }
    }
    $data['cta']['icon'] = services_post_icon($_POST['cta']['icon'] ?? '');

    foreach (SERVICES_BANDS as $band) {
        $data[$band]['status'] = services_post_status($_POST[$band]['status'] ?? 'shown');
    }

    /* Rows arrive keyed by their position in the form. Removing one leaves a
       hole in those keys, so they are renumbered rather than trusted. */
    $data['nav']['items'] = [];
    foreach (array_values((array)($_POST['nav']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data['nav']['items'][] = services_nav_defaults([
            'id'     => services_post_text($row['id'] ?? ''),
            'block'  => services_post_text($row['block'] ?? ''),
            'icon'   => services_post_icon($row['icon'] ?? ''),
            'title'  => services_post_text($row['title'] ?? ''),
            'text'   => services_post_text($row['text'] ?? ''),
            'status' => services_post_status($row['status'] ?? 'shown'),
        ]);
    }

    $data['ossf']['items'] = [];
    foreach (array_values((array)($_POST['ossf']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $data['ossf']['items'][] = services_ossf_defaults([
            'id'     => services_post_text($row['id'] ?? ''),
            'icon'   => services_post_icon($row['icon'] ?? ''),
            'title'  => services_post_text($row['title'] ?? ''),
            'text'   => services_post_text($row['text'] ?? ''),
            'status' => services_post_status($row['status'] ?? 'shown'),
        ]);
    }

    $data['blocks']['items'] = [];
    foreach (array_values((array)($_POST['blocks']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $groups = [];
        foreach (array_values((array)($row['groups'] ?? [])) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groups[] = services_group_defaults([
                'id'     => services_post_text($group['id'] ?? ''),
                'title'  => services_post_text($group['title'] ?? ''),
                'width'  => isset(SERVICES_GROUP_WIDTHS[$group['width'] ?? ''])
                    ? (string)$group['width'] : 'normal',
                'items'  => services_post_lines($group['items'] ?? ''),
                'status' => services_post_status($group['status'] ?? 'shown'),
            ]);
        }

        $buttons = [];
        foreach (array_values((array)($row['buttons'] ?? [])) as $button) {
            if (!is_array($button)) {
                continue;
            }
            $buttons[] = services_button_defaults([
                'id'     => services_post_text($button['id'] ?? ''),
                'label'  => services_post_text($button['label'] ?? ''),
                'href'   => services_post_text($button['href'] ?? ''),
                'icon'   => services_post_icon($button['icon'] ?? ''),
                'style'  => in_array($button['style'] ?? '', ['secondary', 'ghost'], true)
                    ? (string)$button['style'] : 'secondary',
                'status' => services_post_status($button['status'] ?? 'shown'),
            ]);
        }

        $data['blocks']['items'][] = services_block_defaults([
            'id'      => services_post_text($row['id'] ?? ''),
            'service' => services_post_text($row['service'] ?? ''),
            'icon'    => services_post_icon($row['icon'] ?? ''),
            'title'   => services_post_text($row['title'] ?? ''),
            'intro'   => services_post_text($row['intro'] ?? ''),
            'status'  => services_post_status($row['status'] ?? 'shown'),
            'groups'  => $groups,
            'buttons' => $buttons,
        ]);
    }

    /* THE SERVICE LIST, from the hidden inputs beside each row's buttons.
       Only its MEMBERSHIP and ORDER come from this form — a service's contents
       are not on this screen and are taken from the document as it stands, by
       id. That is what makes "add", "remove" and "move" work here without this
       screen being able to damage a page it never showed.

       A row whose id is not in the document is one added by the button on this
       page and not yet saved; it starts from the defaults, with the name and
       address that were typed beside it. */
    $existing = [];
    foreach (services_all($data) as $row) {
        $existing[(string)$row['id']] = $row;
    }

    $data['services']['items'] = [];
    foreach (array_values((array)($_POST['services']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id      = services_post_text($row['id'] ?? '');
        $service = $existing[$id] ?? [];

        $service['id']     = $id;
        $service['name']   = services_post_text($row['name'] ?? '');
        $service['slug']   = strtolower(services_post_text($row['slug'] ?? ''));
        $service['status'] = services_post_status($row['status'] ?? 'shown');

        $data['services']['items'][] = services_service_defaults($service);
    }

    /* Ids are minted here rather than trusted, so two rows cannot be given the
       same one by editing the page's HTML. */
    return services_identify($data);
}

/**
 * Rebuild ONE service from what the browser sent, and put it back in place.
 *
 * $index is where it sits in services.items. Everything else in the document
 * is left exactly as it was found — which is the whole reason this function
 * exists rather than a whole-document rebuild like every other editor's.
 */
function services_one_from_post(array $data, int $index): array
{
    $current = $data['services']['items'][$index] ?? [];
    $post    = (array)($_POST['service'] ?? []);

    $service = $current;
    $service['name']   = services_post_text($post['name'] ?? '');
    $service['slug']   = strtolower(services_post_text($post['slug'] ?? ''));
    $service['status'] = services_post_status($post['status'] ?? 'shown');
    $service['schema_type']        = services_post_text($post['schema_type'] ?? '');
    $service['schema_description'] = services_post_text($post['schema_description'] ?? '');

    /* No 'meta' here. A service page's title, description, share title,
       breadcrumb, crawl setting and sitemap tuning are edited on the SEO
       screen, and a form that no longer renders those fields must not post an
       empty string over them -- see contract_page_bands(). */
    foreach (['hero' => ['title', 'subtitle']] as $band => $fields) {
        foreach ($fields as $field) {
            $service[$band][$field] = services_post_text($post[$band][$field] ?? '');
        }
    }

    foreach (['core', 'layers'] as $band) {
        foreach (['eyebrow', 'title', 'lead'] as $field) {
            $service[$band][$field] = services_post_text($post[$band][$field] ?? '');
        }
        $service[$band]['status'] = services_post_status($post[$band]['status'] ?? 'shown');
    }

    foreach (['text', 'link_label', 'link_href'] as $field) {
        $service['core']['note'][$field] =
            services_post_text($post['core']['note'][$field] ?? '');
    }

    $service['core']['items'] = [];
    foreach (array_values((array)($post['core']['items'] ?? [])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $service['core']['items'][] = services_core_defaults([
            'id'     => services_post_text($row['id'] ?? ''),
            'icon'   => services_post_icon($row['icon'] ?? ''),
            'title'  => services_post_text($row['title'] ?? ''),
            'text'   => services_post_text($row['text'] ?? ''),
            'status' => services_post_status($row['status'] ?? 'shown'),
        ]);
    }

    foreach (['purpose', 'features', 'tags', 'count_one', 'count_many'] as $field) {
        $service['layers']['labels'][$field] =
            services_post_text($post['layers']['labels'][$field] ?? '');
    }

    $service['layers']['items'] = [];
    foreach (array_values((array)($post['layers']['items'] ?? [])) as $layer) {
        if (!is_array($layer)) {
            continue;
        }

        $cards = [];
        foreach (array_values((array)($layer['cards'] ?? [])) as $card) {
            if (!is_array($card)) {
                continue;
            }
            $cards[] = services_card_defaults([
                'id'       => services_post_text($card['id'] ?? ''),
                'icon'     => services_post_icon($card['icon'] ?? ''),
                'name'     => services_post_text($card['name'] ?? ''),
                'category' => services_post_text($card['category'] ?? ''),
                'desc'     => services_post_text($card['desc'] ?? ''),
                'purpose'  => services_post_text($card['purpose'] ?? ''),
                'features' => services_post_lines($card['features'] ?? ''),
                'tags'     => services_post_lines($card['tags'] ?? ''),
                'status'   => services_post_status($card['status'] ?? 'shown'),
            ]);
        }

        $service['layers']['items'][] = services_layer_defaults([
            'id'        => services_post_text($layer['id'] ?? ''),
            'icon'      => services_post_icon($layer['icon'] ?? ''),
            'title'     => services_post_text($layer['title'] ?? ''),
            'tab_text'  => services_post_text($layer['tab_text'] ?? ''),
            'text'      => services_post_text($layer['text'] ?? ''),
            'hub_label' => services_post_text($layer['hub_label'] ?? ''),
            'status'    => services_post_status($layer['status'] ?? 'shown'),
            'cards'     => $cards,
        ]);
    }

    foreach (['title', 'text', 'label', 'href'] as $field) {
        $service['cta'][$field] = services_post_text($post['cta'][$field] ?? '');
    }
    $service['cta']['icon']   = services_post_icon($post['cta']['icon'] ?? '');
    $service['cta']['status'] = services_post_status($post['cta']['status'] ?? 'shown');

    $data['services']['items'][$index] = services_service_defaults($service);

    return services_identify($data);
}

/* --------------------------------------------------------- row operations */

/**
 * Apply an add / remove / move button to one of the index's lists.
 *
 * The button's value carries what to do and to which row, as "nav-up:3".
 * Nested lists inside a block are addressed with the block's index in the
 * verb — "group-add:2" adds a group to block 2, "group-remove:2.1" removes the
 * second group of it. Returns the new document and a sentence saying what
 * happened, or null when the instruction did not name anything that exists.
 */
function services_index_row_action(array $data, string $do): ?array
{
    [$verb, $where] = array_pad(explode(':', $do, 2), 2, '');

    foreach (['nav' => 'services_nav_defaults', 'ossf' => 'services_ossf_defaults',
              'blocks' => 'services_block_defaults'] as $band => $filler) {
        if (!str_starts_with($verb, $band . '-')) {
            continue;
        }
        $what = substr($verb, strlen($band) + 1);
        $out  = services_list_action($data[$band]['items'], $what, (int)$where, $filler);
        if ($out === null) {
            return null;
        }
        $data[$band]['items'] = $out[0];
        return [services_identify($data), $out[1]];
    }

    /* The lists that live inside one block. */
    foreach (['group' => ['groups', 'services_group_defaults'],
              'button' => ['buttons', 'services_button_defaults']] as $noun => [$list, $filler]) {
        if (!str_starts_with($verb, $noun . '-')) {
            continue;
        }
        [$block, $row] = array_pad(explode('.', $where, 2), 2, '0');
        $block = (int)$block;
        if (!isset($data['blocks']['items'][$block])) {
            return null;
        }
        $what = substr($verb, strlen($noun) + 1);
        $out  = services_list_action($data['blocks']['items'][$block][$list],
                                     $what, (int)$row, $filler);
        if ($out === null) {
            return null;
        }
        $data['blocks']['items'][$block][$list] = $out[0];
        return [services_identify($data), $out[1]];
    }

    return null;
}

/**
 * Apply a button to one of a service's lists.
 *
 * Same address scheme: "card-add:1" adds a solution to group 1,
 * "card-remove:1.4" removes the fifth solution of it.
 */
function services_page_row_action(array $data, int $index, string $do): ?array
{
    $service = $data['services']['items'][$index] ?? null;
    if ($service === null) {
        return null;
    }

    [$verb, $where] = array_pad(explode(':', $do, 2), 2, '');

    if (str_starts_with($verb, 'core-')) {
        $out = services_list_action($service['core']['items'], substr($verb, 5),
                                    (int)$where, 'services_core_defaults');
        if ($out === null) {
            return null;
        }
        $service['core']['items'] = $out[0];
        $data['services']['items'][$index] = $service;
        return [services_identify($data), $out[1]];
    }

    if (str_starts_with($verb, 'layers-')) {
        $out = services_list_action($service['layers']['items'], substr($verb, 7),
                                    (int)$where, 'services_layer_defaults');
        if ($out === null) {
            return null;
        }
        $service['layers']['items'] = $out[0];
        $data['services']['items'][$index] = $service;
        return [services_identify($data), $out[1]];
    }

    if (str_starts_with($verb, 'card-')) {
        [$layer, $row] = array_pad(explode('.', $where, 2), 2, '0');
        $layer = (int)$layer;
        if (!isset($service['layers']['items'][$layer])) {
            return null;
        }
        $out = services_list_action($service['layers']['items'][$layer]['cards'],
                                    substr($verb, 5), (int)$row, 'services_card_defaults');
        if ($out === null) {
            return null;
        }
        $service['layers']['items'][$layer]['cards'] = $out[0];
        $data['services']['items'][$index] = $service;
        return [services_identify($data), $out[1]];
    }

    return null;
}

/**
 * Add, remove or move one row of one list.
 *
 * Written once because there are ELEVEN lists across the two screens — the nav
 * cards, the blocks, a block's groups and buttons, the OSSF stages, the
 * services themselves, a service's core cards, its layers, and a layer's
 * solutions — and eleven copies of "is the index in range, splice, renumber"
 * is eleven chances to get one of them wrong.
 *
 * Returns [rows, what happened] or null when the index names nothing.
 */
function services_list_action(array $rows, string $what, int $index, callable $filler): ?array
{
    $rows = array_values($rows);

    if ($what === 'add') {
        /* A new row arrives HIDDEN. It has nothing in it yet, and a blank card
           appearing on the live site the moment somebody presses Add is not
           what pressing Add means. */
        $rows[] = $filler(['status' => 'hidden']);

        return [$rows, 'Added an entry. It is hidden until you show it — fill it in, '
                     . 'then save.'];
    }

    if (!isset($rows[$index])) {
        return null;
    }

    if ($what === 'remove') {
        array_splice($rows, $index, 1);

        return [array_values($rows),
                'Removed. Nothing is written to the site until you save.'];
    }

    if ($what === 'up' || $what === 'down') {
        $to = $index + ($what === 'up' ? -1 : 1);
        if ($to < 0 || $to >= count($rows)) {
            return null;
        }
        [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];

        return [$rows, 'Moved. Nothing is written to the site until you save.'];
    }

    return null;
}

/**
 * Add, remove or move a whole SERVICE.
 *
 * Handled apart from the lists above because a new service is not a blank row:
 * it is a whole page, and a page with no name has no address, so it cannot be
 * reached even to be filled in. It arrives named and hidden, with the four
 * bands its template has, and the operator renames it.
 */
function services_service_action(array $data, string $do): ?array
{
    [$verb, $where] = array_pad(explode(':', $do, 2), 2, '');

    if (!str_starts_with($verb, 'service-')) {
        return null;
    }
    $what = substr($verb, strlen('service-'));

    if ($what === 'add') {
        $taken = [];
        foreach (services_all($data) as $row) {
            $taken[] = (string)$row['slug'];
        }
        $name = 'New service';
        $slug = services_slug($name, $taken);

        $data['services']['items'][] = services_service_defaults([
            'name'   => $name,
            'slug'   => $slug,
            'status' => 'hidden',
        ]);

        return [services_identify($data),
                'Added a service. It is hidden until you show it — give it a name '
                . 'and a web address, fill it in, then save.'];
    }

    $out = services_list_action($data['services']['items'], $what, (int)$where,
                                'services_service_defaults');
    if ($out === null) {
        return null;
    }
    $data['services']['items'] = $out[0];

    return [services_identify($data), $out[1]];
}

/* ---------------------------------------------------------------- actions */

$data    = services_load();
$pending = '';   /* an unsaved change made by a row button */

/* Which screen. An unknown slug falls back to the index rather than 404ing:
   the likely way to get one is a stale link to a service somebody deleted, and
   the useful answer to that is the list it used to be in. */
$slug  = trim((string)($_GET['service'] ?? $_POST['service_slug'] ?? ''));
$index = null;
foreach (services_all($data) as $i => $row) {
    if ((string)$row['slug'] === $slug) {
        $index = $i;
        break;
    }
}
if ($index === null) {
    $slug = '';
}

/** Where this screen posts to and returns to. */
function services_here(string $slug): string
{
    return $slug === ''
        ? admin_url('services')
        : admin_url('services', ['service' => $slug]);
}

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
        publish_note(publish_push('services', $data));

        $note = publish_note();
        admin_redirect('services', ($note['ok'] ?? false)
            ? 'Sent to the live site — it now holds revision '
              . (int)($note['revision'] ?? 0) . '.'
            : '', $slug === '' ? [] : ['service' => $slug]);
    }

    /* Before anything is read out of $_POST, because everything read out of a
       truncated $_POST is a lie by omission — see admin_form_truncated(). This
       screen is the reason that function exists in the shape it does. */
    if (admin_form_truncated()) {
        $errors[] = admin_truncated_message();
        $do = 'nothing';
        $posted = $data;
    } else {
        $do = (string)($_POST['do'] ?? 'save');
        $posted = $index === null
            ? services_index_from_post($data)
            : services_one_from_post($data, $index);
    }

    if ($do === 'save') {
        $errors = array_merge($errors, $index === null
            ? services_validate($posted)
            : services_validate_one($posted['services']['items'][$index],
                                    'This page'));

        if (!$errors) {
            /* THROUGH THE LOCK, not store_write(). See the note at the top of
               this file and on services_edit(): this is the one editor whose
               save merges rather than replaces, so two people on two different
               services must not overwrite each other. */
            $slug_after = $index === null
                ? ''
                : (string)$posted['services']['items'][$index]['slug'];

            $row = $index === null ? [] : $posted['services']['items'][$index];

            $ok = services_edit(static function (array $stored) use ($posted, $index, $row): array {
                if ($index === null) {
                    /* The index screen owns the service LIST -- which services
                       there are, in what order, called what, at what address,
                       shown or hidden -- and nothing INSIDE one. So the list
                       comes from the form and each service's contents come
                       from the file, matched by id. Taking the whole of
                       services from either side would be wrong in one
                       direction or the other: from the form it would blank
                       every page, from the file it would undo the add. */
                    $kept = [];
                    foreach ($stored['services']['items'] as $row) {
                        $kept[(string)$row['id']] = $row;
                    }

                    $merged = [];
                    foreach ($posted['services']['items'] as $row) {
                        $id   = (string)$row['id'];
                        $full = $kept[$id] ?? $row;

                        /* The four the list screen does own. */
                        $full['id']     = $id;
                        $full['name']   = $row['name'];
                        $full['slug']   = $row['slug'];
                        $full['status'] = $row['status'];

                        $merged[] = $full;
                    }

                    $posted['services']['items'] = $merged;

                    return $posted;
                }

                /* BY ID, NOT BY POSITION. The row's index in the form is its
                   index in the document as it was READ; between then and this
                   lock, somebody on the other screen may have added, removed
                   or reordered a service, and writing back by position would
                   put this page's contents on top of a different one. Matched
                   by id, a reorder is harmless and a delete is a no-op. */
                foreach ($stored['services']['items'] as $i => $stored_row) {
                    if ((string)$stored_row['id'] === (string)$row['id']) {
                        $stored['services']['items'][$i] = $row;
                        return $stored;
                    }
                }

                /* The service was deleted while this form was open. Put it
                   back rather than dropping what was typed: undoing an unwanted
                   revival is one press, retyping a page is not. */
                $stored['services']['items'][] = $row;

                return $stored;
            });

            if ($ok) {
                admin_redirect('services',
                    $index === null
                        ? 'Saved the services page.'
                        : 'Saved ' . ($posted['services']['items'][$index]['name'] ?: 'the service') . '.',
                    $slug_after === '' ? [] : ['service' => $slug_after]);
            }
            $errors[] = 'Could not write content/services.json. Check the file is '
                      . 'writable by PHP.';
        }
        /* Redraw with what was typed rather than throwing it away. */
        $data = $posted;
    } elseif ($do !== 'nothing') {
        $applied = $index === null
            ? (services_service_action($posted, $do) ?? services_index_row_action($posted, $do))
            : services_page_row_action($posted, $index, $do);

        $data    = $applied[0] ?? $posted;
        $pending = $applied[1] ?? '';
    }
}

$service = $index === null ? null : ($data['services']['items'][$index] ?? null);
if ($service === null) {
    $index = null;
    $slug  = '';
}

/* ---------------------------------------------------------------- helpers */

/** A band's heading, its show/hide switch, and the blurb under it. */
function services_band_header(array $band, string $name, string $legend,
                              string $blurb, string $add = '', string $do = '',
                              string $rows = ''): void
{
    admin_band_head(
        $legend,
        $blurb,
        $add !== ''
            ? ['do'    => ($do !== '' ? $do : $name . '-add:0'),
               'label' => $add]
              + ($rows !== '' ? ['rows' => $rows] : [])
            : [],
        ['name'  => $name . '[status]',
         'value' => (string)($band['status'] ?? 'shown'),
         'noun'  => 'this section']
    );
}

/** One icon picker, with the live preview beside it. */
function services_icon_field(string $name, string $value): void
{
    ?>
        <label class="admin__field">
          <span class="admin__label">Icon</span>
          <select class="admin__input" name="<?= h($name) ?>">
            <option value=""<?= $value === '' ? ' selected' : '' ?>>No icon</option>
<?php foreach (SERVICES_ICONS as $icon => $label): ?>
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

/** One line of text. */
function services_text_field(string $name, string $label, string $value,
                             string $hint = '', bool $wide = false,
                             bool $required = false): void
{
    ?>
      <label class="admin__field<?= $wide ? ' admin__field--wide' : '' ?>">
        <span class="admin__label"><?= h($label) ?></span>
        <input class="admin__input" type="text" name="<?= h($name) ?>"<?= $required ? ' required' : '' ?>
               value="<?= h($value) ?>">
<?php if ($hint !== ''): ?>
        <span class="admin__hint"><?= h($hint) ?></span>
<?php endif; ?>
      </label>
    <?php
}

/** A paragraph. */
function services_area_field(string $name, string $label, string $value,
                             string $hint = '', int $rows = 3): void
{
    ?>
      <label class="admin__field admin__field--wide">
        <span class="admin__label"><?= h($label) ?></span>
        <textarea class="admin__input" name="<?= h($name) ?>" rows="<?= $rows ?>"><?= h($value) ?></textarea>
<?php if ($hint !== ''): ?>
        <span class="admin__hint"><?= h($hint) ?></span>
<?php endif; ?>
      </label>
    <?php
}

/** A list typed one entry per line. */
function services_list_field(string $name, string $label, array $items,
                             string $hint): void
{
    services_area_field($name, $label, services_lines_value($items), $hint,
                        max(3, min(14, count($items) + 1)));
}

/* ------------------------------------------------------------ the screens */

/* What the rail lists. Two outlines, because there are two screens and an
   outline naming bands that are not on the one you are looking at is a table
   of contents for a different document. */
const SERVICES_OUTLINE = [
    'band-hero'     => 'The banner',
    'band-nav'      => 'Select a Category',
    'band-blocks'   => 'The service blocks',
    'band-services' => 'The service pages',
    'band-ossf'     => 'The OSSF framework',
    'band-cta'      => 'The closing band',
    'band-meta'     => 'Search and sharing',
];

const SERVICES_PAGE_OUTLINE = [
    'band-page'    => 'Name and address',
    'band-hero'    => 'The banner',
    'band-core'    => 'The two lead cards',
    'band-layers'  => 'The groups and their solutions',
    'band-cta'     => 'The closing band',
    'band-meta'    => 'Search and sharing',
];

$view = $slug === '' ? '/pages/services/' : '/pages/services/' . $slug . '/';

admin_head('services', $user,
    ($slug === ''
        ? 'Editing <code>content/services.json</code> — the services page itself. '
          . 'Each service page has its own screen, listed below. '
        : 'Editing the <strong>' . h((string)$service['name']) . '</strong> page. '
          . '<a href="' . h(admin_url('services')) . '">Back to all services</a>. ')
    . 'Changes go live on <a href="' . h(public_url($view)) . '">the site</a> '
    . 'within a second — as soon as the live site accepts the publish.',
    $slug === '' ? SERVICES_OUTLINE : SERVICES_PAGE_OUTLINE,
    ['form' => 'services-form',
     'label' => $slug === '' ? 'Save the services page' : 'Save this service',
     'discard' => services_here($slug)]);

admin_notices($errors);

if (!$errors && $pending !== '') {
    echo '<p class="admin__notice admin__notice--ok">' . h($pending) . '</p>';
}
?>

<form class="admin__form" id="services-form" method="post" data-async
      action="<?= h(services_here($slug)) ?>">
  <?= admin_form_fields('services') ?>
<?php if ($slug !== ''): ?>
  <input type="hidden" name="service_slug" value="<?= h($slug) ?>">
<?php endif; ?>

  <?php /* Pressing Enter in a text field submits the form using the first
           submit button in the document, which would otherwise be whichever
           "Add" comes first. This is that first button, and it saves. */ ?>
  <button class="visually-hidden" type="submit" name="do" value="save"
          tabindex="-1" aria-hidden="true">Save</button>

<?php if ($slug === ''): /* ================= the index screen ================= */ ?>

  <!-- ========================= the banner ========================= -->
  <fieldset class="admin__block" id="band-hero">
    <?php admin_band_head('The banner',
        'The band at the top of the services page, with the circuitry around it.'); ?>
    <div class="admin__grid">
      <?php services_text_field('hero[title]', 'Page title', $data['hero']['title'],
          "The big heading. It is the page's only h1.", true, true); ?>
      <?php services_text_field('hero[subtitle]', 'Under it', $data['hero']['subtitle'],
          'Leave empty to show nothing under the heading.', true); ?>
    </div>
  </fieldset>

  <!-- ====================== select a category ===================== -->
  <fieldset class="admin__block" id="band-nav">
    <?php services_band_header($data['nav'], 'nav', 'Select a Category',
        'The cards at the top that jump down to a service block. A card whose '
        . 'block is hidden or gone is not shown, so it can never scroll a '
        . 'visitor to nothing.', 'Add a card'); ?>

<?php $rows = $data['nav']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="nav[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('nav', $i, $total, [
          'label' => $row['title'], 'noun' => 'card',
          'detail' => $row['text'], 'icon' => $row['icon'],
          'status' => $row['status']]); ?>
      <div class="admin__grid">
        <?php services_text_field("nav[items][$i][title]", 'Title', $row['title']); ?>
        <?php services_text_field("nav[items][$i][text]", 'One line under it', $row['text']); ?>
        <?php services_icon_field("nav[items][$i][icon]", $row['icon']); ?>
        <label class="admin__field">
          <span class="admin__label">Jumps to</span>
          <select class="admin__input" name="nav[items][<?= $i ?>][block]">
            <option value=""<?= $row['block'] === '' ? ' selected' : '' ?>>Nothing</option>
<?php foreach ($data['blocks']['items'] as $block): ?>
            <option value="<?= h($block['id']) ?>"<?= $row['block'] === $block['id'] ? ' selected' : '' ?>><?= h($block['title'] ?: $block['id']) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">Which block further down the page this card scrolls to.</span>
        </label>
        <?php admin_status_field("nav[items][$i][status]", $row['status'], 'this card'); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ====================== the service blocks ==================== -->
  <fieldset class="admin__block" id="band-blocks">
    <?php services_band_header($data['blocks'], 'blocks', 'The service blocks',
        'One block per service, down the page. They alternate light and shaded '
        . 'backgrounds by position, so reordering them keeps the stripe — and a '
        . 'hidden one is skipped rather than leaving a gap in it.', 'Add a block'); ?>

<?php $rows = $data['blocks']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="blocks[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('blocks', $i, $total, [
          'label' => $row['title'], 'noun' => 'block',
          'detail' => $row['intro'], 'icon' => $row['icon'],
          'status' => $row['status']]); ?>
      <div class="admin__grid">
        <?php services_text_field("blocks[items][$i][title]", 'Heading', $row['title']); ?>
        <?php services_icon_field("blocks[items][$i][icon]", $row['icon']); ?>
        <label class="admin__field">
          <span class="admin__label">The page it belongs to</span>
          <select class="admin__input" name="blocks[items][<?= $i ?>][service]">
            <option value=""<?= $row['service'] === '' ? ' selected' : '' ?>>None</option>
<?php foreach (services_all($data) as $svc): ?>
            <option value="<?= h($svc['slug']) ?>"<?= $row['service'] === $svc['slug'] ? ' selected' : '' ?>><?= h($svc['name'] ?: $svc['slug']) ?></option>
<?php endforeach; ?>
          </select>
          <span class="admin__hint">Hiding that page hides this block too, so the index never links to a 404.</span>
        </label>
        <?php admin_status_field("blocks[items][$i][status]", $row['status'], 'this block'); ?>
        <?php services_area_field("blocks[items][$i][intro]", 'Introduction', $row['intro'],
            'The paragraph under the heading.'); ?>
      </div>

      <?php /* The lists inside this block. Their buttons carry the block's
               index as well as the row's — "group-remove:2.1" — because a
               nested list has two coordinates and one number cannot say both. */ ?>
      <div class="admin__grid">
        <div class="admin__field admin__field--wide">
          <span class="admin__label">Groups of items</span>
          <span class="admin__hint">The ticked lists inside this block. They are written separately from the service page's own solutions: the index gives the short version.</span>
        </div>
      </div>

<?php $groups = $row['groups']; $gtotal = count($groups); ?>
<?php foreach ($groups as $g => $group): ?>
      <div class="admin-card<?= $group['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
        <input type="hidden" name="blocks[items][<?= $i ?>][groups][<?= $g ?>][id]" value="<?= h($group['id']) ?>">
        <?php admin_card_head("group", $g, $gtotal, [
            'label' => $group['title'], 'noun' => 'group',
            'detail' => count($group['items']) . ' item(s)',
            'status' => $group['status']]); ?>
        <div class="admin__grid">
          <?php services_text_field("blocks[items][$i][groups][$g][title]", 'Group heading', $group['title']); ?>
          <label class="admin__field">
            <span class="admin__label">Width</span>
            <select class="admin__input" name="blocks[items][<?= $i ?>][groups][<?= $g ?>][width]">
<?php foreach (SERVICES_GROUP_WIDTHS as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= $group['width'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
            </select>
          </label>
          <?php admin_status_field("blocks[items][$i][groups][$g][status]", $group['status'], 'this group'); ?>
          <?php services_list_field("blocks[items][$i][groups][$g][items]", 'The items',
              $group['items'], 'One per line. Each gets a tick in front of it.'); ?>
        </div>
      </div>
<?php endforeach; ?>
      <p class="admin__actions">
        <button class="btn btn--secondary" type="submit" name="do" value="group-add:<?= $i ?>"
                data-rows="blocks[items][<?= $i ?>][groups][">Add a group</button>
      </p>

<?php $buttons = $row['buttons']; $btotal = count($buttons); ?>
<?php foreach ($buttons as $b => $button): ?>
      <div class="admin-card<?= $button['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
        <input type="hidden" name="blocks[items][<?= $i ?>][buttons][<?= $b ?>][id]" value="<?= h($button['id']) ?>">
        <?php admin_card_head("button", $b, $btotal, [
            'label' => $button['label'], 'noun' => 'button',
            'detail' => $button['href'], 'icon' => $button['icon'],
            'status' => $button['status']]); ?>
        <div class="admin__grid">
          <?php services_text_field("blocks[items][$i][buttons][$b][label]", 'Label', $button['label']); ?>
          <?php services_text_field("blocks[items][$i][buttons][$b][href]", 'Links to', $button['href'],
              'A path on this site, like /pages/services/cybersecurity/.'); ?>
          <?php services_icon_field("blocks[items][$i][buttons][$b][icon]", $button['icon']); ?>
          <label class="admin__field">
            <span class="admin__label">Style</span>
            <select class="admin__input" name="blocks[items][<?= $i ?>][buttons][<?= $b ?>][style]">
              <option value="secondary"<?= $button['style'] === 'secondary' ? ' selected' : '' ?>>Outlined</option>
              <option value="ghost"<?= $button['style'] === 'ghost' ? ' selected' : '' ?>>Plain</option>
            </select>
          </label>
          <?php admin_status_field("blocks[items][$i][buttons][$b][status]", $button['status'], 'this button'); ?>
        </div>
      </div>
<?php endforeach; ?>
      <p class="admin__actions">
        <button class="btn btn--secondary" type="submit" name="do" value="button-add:<?= $i ?>"
                data-rows="blocks[items][<?= $i ?>][buttons][">Add a button</button>
      </p>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ====================== the service pages ===================== -->
  <fieldset class="admin__block" id="band-services">
    <?php admin_band_head('The service pages',
        'Each has a screen of its own — this list is where you add, remove, '
        . 'reorder and hide them. Hiding one makes its page answer 404 and '
        . 'takes its block off the index.',
        ['do' => 'service-add:0', 'label' => 'Add a service']); ?>

<?php $rows = services_all($data); $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <?php /* The service's CONTENTS are edited on its own screen and are not
               here. These four carry its identity and its place, so that
               adding, removing, reordering and hiding work on this screen
               without it being able to write a page it never showed. */ ?>
      <input type="hidden" name="services[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('service', $i, $total, [
          'label' => $row['name'], 'noun' => 'service',
          'detail' => count($row['layers']['items']) . ' group(s), '
                    . array_sum(array_map(static fn(array $l): int => count($l['cards']),
                                          $row['layers']['items'])) . ' solution(s)',
          'status' => $row['status']]); ?>
      <div class="admin__grid">
        <?php services_text_field("services[items][$i][name]", 'Name', $row['name']); ?>
        <?php services_text_field("services[items][$i][slug]", 'Web address', $row['slug'],
            'The page is at /pages/services/<this>/.'); ?>
        <?php admin_status_field("services[items][$i][status]", $row['status'], 'this whole page'); ?>
      </div>
      <p class="admin__actions">
        <a class="btn btn--secondary" href="<?= h(admin_url('services', ['service' => $row['slug']])) ?>">
          Edit <?= h($row['name'] ?: $row['slug']) ?>
        </a>
        <a class="btn btn--ghost" href="<?= h(public_url('/pages/services/' . $row['slug'] . '/')) ?>">View the page</a>
      </p>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ====================== the OSSF framework ==================== -->
  <fieldset class="admin__block" id="band-ossf">
    <?php services_band_header($data['ossf'], 'ossf', 'The OSSF framework',
        'The numbered stages near the bottom. The number on each is its '
        . 'position, so reordering them renumbers them.', 'Add a stage'); ?>

    <div class="admin__grid">
      <?php services_text_field('ossf[eyebrow]', 'Small label above', $data['ossf']['eyebrow']); ?>
      <?php services_text_field('ossf[title]', 'Heading', $data['ossf']['title']); ?>
      <?php services_area_field('ossf[lead]', 'Introduction', $data['ossf']['lead']); ?>
    </div>

<?php $rows = $data['ossf']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="ossf[items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('ossf', $i, $total, [
          'label' => $row['title'], 'noun' => 'stage',
          'detail' => $row['text'], 'icon' => $row['icon'],
          'status' => $row['status']]); ?>
      <div class="admin__grid">
        <?php services_text_field("ossf[items][$i][title]", 'Stage', $row['title']); ?>
        <?php services_icon_field("ossf[items][$i][icon]", $row['icon']); ?>
        <?php admin_status_field("ossf[items][$i][status]", $row['status'], 'this stage'); ?>
        <?php services_area_field("ossf[items][$i][text]", 'What happens in it', $row['text'], '', 2); ?>
      </div>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ======================= the closing band ===================== -->
  <fieldset class="admin__block" id="band-cta">
    <?php services_band_header($data['cta'], 'cta', 'The closing band',
        'The band at the foot of the page.'); ?>
    <div class="admin__grid">
      <?php services_text_field('cta[title]', 'Heading', $data['cta']['title'], '', true); ?>
      <?php services_area_field('cta[text]', 'Under it', $data['cta']['text'], '', 2); ?>
      <?php services_text_field('cta[label]', 'Button label', $data['cta']['label']); ?>
      <?php services_text_field('cta[href]', 'Button links to', $data['cta']['href']); ?>
      <?php services_icon_field('cta[icon]', $data['cta']['icon']); ?>
    </div>
  </fieldset>

  <!-- ====================== search and sharing ==================== -->
  <?php admin_meta_band('services'); ?>

<?php else: /* ================= one service's screen ================= */ ?>

  <!-- ====================== name and address ====================== -->
  <fieldset class="admin__block" id="band-page">
    <?php admin_band_head('Name and address',
        'What this service is called, where its page lives, and how a search '
        . 'engine is told about it.',
        [],
        ['name' => 'service[status]', 'value' => (string)$service['status'],
         'noun' => 'this whole page']); ?>
    <div class="admin__grid">
      <?php services_text_field('service[name]', 'Name', $service['name'],
          'The heading, the breadcrumb and the structured data all use it.', false, true); ?>
      <?php services_text_field('service[slug]', 'Web address', $service['slug'],
          'The page is at /pages/services/<this>/. Lower-case letters, digits and hyphens. '
          . 'Changing it moves the page and breaks saved links to it.', false, true); ?>
      <?php services_text_field('service[schema_type]', 'What a search engine calls it',
          $service['schema_type'],
          'schema.org\'s word for the practice — "IT Staffing", "IT Consulting". '
          . 'Leave empty to use the name.'); ?>
      <?php services_area_field('service[schema_description]', 'Description for search engines',
          $service['schema_description'],
          'One or two sentences, in the structured data rather than on the page.', 2); ?>
    </div>
  </fieldset>

  <!-- ========================= the banner ========================= -->
  <fieldset class="admin__block" id="band-hero">
    <?php admin_band_head('The banner',
        'The band at the top of this page, with the circuitry around it.'); ?>
    <div class="admin__grid">
      <?php services_text_field('service[hero][title]', 'Page title', $service['hero']['title'],
          "The big heading. It is the page's only h1.", true, true); ?>
      <?php services_text_field('service[hero][subtitle]', 'Under it', $service['hero']['subtitle'],
          '', true); ?>
    </div>
  </fieldset>

  <!-- ======================= the lead cards ======================= -->
  <fieldset class="admin__block" id="band-core">
    <?php services_band_header($service['core'], 'service[core]', 'The two lead cards',
        'The cards at the top of the page, and the note under them.',
        'Add a card', 'core-add:0', 'service[core][items]['); ?>

    <div class="admin__grid">
      <?php services_text_field('service[core][eyebrow]', 'Small label above', $service['core']['eyebrow']); ?>
      <?php services_text_field('service[core][title]', 'Heading', $service['core']['title']); ?>
      <?php services_area_field('service[core][lead]', 'Introduction', $service['core']['lead']); ?>
    </div>

<?php $rows = $service['core']['items']; $total = count($rows); ?>
<?php foreach ($rows as $i => $row): ?>
    <div class="admin-card<?= $row['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="service[core][items][<?= $i ?>][id]" value="<?= h($row['id']) ?>">
      <?php admin_card_head('core', $i, $total, [
          'label' => $row['title'], 'noun' => 'card',
          'detail' => $row['text'], 'icon' => $row['icon'],
          'status' => $row['status']]); ?>
      <div class="admin__grid">
        <?php services_text_field("service[core][items][$i][title]", 'Heading', $row['title']); ?>
        <?php services_icon_field("service[core][items][$i][icon]", $row['icon']); ?>
        <?php admin_status_field("service[core][items][$i][status]", $row['status'], 'this card'); ?>
        <?php services_area_field("service[core][items][$i][text]", 'The paragraph', $row['text']); ?>
      </div>
    </div>
<?php endforeach; ?>

    <div class="admin__grid">
      <?php services_area_field('service[core][note][text]', 'The note under the cards',
          $service['core']['note']['text'],
          'Leave empty to show no note at all.', 2); ?>
      <?php services_text_field('service[core][note][link_label]', 'The link in it',
          $service['core']['note']['link_label'],
          'Leave empty for a note with no link.'); ?>
      <?php services_text_field('service[core][note][link_href]', 'That link goes to',
          $service['core']['note']['link_href']); ?>
    </div>
  </fieldset>

  <!-- ================== the groups and solutions =================== -->
  <fieldset class="admin__block" id="band-layers">
    <?php services_band_header($service['layers'], 'service[layers]',
        'The groups and their solutions',
        'The tabs down the page. Each is a ring of solutions with a grid under '
        . 'it. The card beside the ring, the ring itself and the "N Solutions" '
        . 'count are all drawn from the solutions below — they are not typed, '
        . 'so they cannot disagree with them.',
        'Add a group', 'layers-add:0', 'service[layers][items]['); ?>

    <div class="admin__grid">
      <?php services_text_field('service[layers][eyebrow]', 'Small label above', $service['layers']['eyebrow']); ?>
      <?php services_text_field('service[layers][title]', 'Heading', $service['layers']['title']); ?>
      <?php services_area_field('service[layers][lead]', 'Introduction', $service['layers']['lead']); ?>
    </div>

    <?php /* One set of headings for the whole page, because that is how they
             were written: every card on this page says the same two words over
             its two lists. Per card they would be a hundred and thirty-seven
             chances to type one of them differently. */ ?>
    <div class="admin__grid">
      <?php services_text_field('service[layers][labels][purpose]', 'Heading over "purpose"',
          $service['layers']['labels']['purpose'], 'On every solution card on this page.'); ?>
      <?php services_text_field('service[layers][labels][features]', 'Heading over the ticked list',
          $service['layers']['labels']['features'],
          'Leave empty and no solution on this page shows a ticked list.'); ?>
      <?php services_text_field('service[layers][labels][tags]', 'Heading over the tag list',
          $service['layers']['labels']['tags'],
          'Leave empty and no solution on this page shows tags.'); ?>
      <?php services_text_field('service[layers][labels][count_one]', 'The word for one',
          $service['layers']['labels']['count_one'], 'As in "1 Solution".'); ?>
      <?php services_text_field('service[layers][labels][count_many]', 'The word for more',
          $service['layers']['labels']['count_many'], 'As in "12 Solutions".'); ?>
    </div>

<?php $layers = $service['layers']['items']; $ltotal = count($layers); ?>
<?php foreach ($layers as $l => $layer): ?>
    <div class="admin-card<?= $layer['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
      <input type="hidden" name="service[layers][items][<?= $l ?>][id]" value="<?= h($layer['id']) ?>">
      <?php admin_card_head('layers', $l, $ltotal, [
          'label' => $layer['title'], 'noun' => 'group',
          'detail' => count($layer['cards']) . ' solution(s)',
          'icon' => $layer['icon'], 'status' => $layer['status']]); ?>
      <div class="admin__grid">
        <?php services_text_field("service[layers][items][$l][title]", 'Name', $layer['title'],
            'The tab and the heading both use it.'); ?>
        <?php services_text_field("service[layers][items][$l][tab_text]", 'One line on the tab', $layer['tab_text']); ?>
        <?php services_icon_field("service[layers][items][$l][icon]", $layer['icon']); ?>
        <?php services_text_field("service[layers][items][$l][hub_label]", 'Word at the centre of the ring',
            $layer['hub_label'], 'Short — it sits inside the ring. Drawn in capitals.'); ?>
        <?php admin_status_field("service[layers][items][$l][status]", $layer['status'], 'this group'); ?>
        <?php services_area_field("service[layers][items][$l][text]", 'The paragraph under the heading', $layer['text']); ?>
      </div>

<?php $cards = $layer['cards']; $ctotal = count($cards); ?>
<?php foreach ($cards as $c => $card): ?>
      <div class="admin-card<?= $card['status'] === 'hidden' ? ' admin-card--hidden' : '' ?>">
        <input type="hidden" name="service[layers][items][<?= $l ?>][cards][<?= $c ?>][id]" value="<?= h($card['id']) ?>">
        <?php admin_card_head('card', $c, $ctotal, [
            'label' => $card['name'], 'noun' => 'solution',
            'detail' => $card['category'], 'icon' => $card['icon'],
            'status' => $card['status']]); ?>
        <div class="admin__grid">
          <?php services_text_field("service[layers][items][$l][cards][$c][name]", 'Name', $card['name']); ?>
          <?php services_text_field("service[layers][items][$l][cards][$c][category]", 'Category', $card['category'],
              'The small line under the name.'); ?>
          <?php services_icon_field("service[layers][items][$l][cards][$c][icon]", $card['icon']); ?>
          <?php admin_status_field("service[layers][items][$l][cards][$c][status]", $card['status'], 'this solution'); ?>
          <?php services_area_field("service[layers][items][$l][cards][$c][desc]", 'What it is', $card['desc'], '', 2); ?>
          <?php services_area_field("service[layers][items][$l][cards][$c][purpose]", 'What it is for', $card['purpose'], '', 2); ?>
          <?php services_list_field("service[layers][items][$l][cards][$c][features]", 'The ticked list',
              $card['features'], 'One per line.'); ?>
          <?php services_list_field("service[layers][items][$l][cards][$c][tags]", 'The tags',
              $card['tags'], 'One per line.'); ?>
        </div>
      </div>
<?php endforeach; ?>
      <p class="admin__actions">
        <button class="btn btn--secondary" type="submit" name="do" value="card-add:<?= $l ?>"
                data-rows="service[layers][items][<?= $l ?>][cards][">Add a solution</button>
      </p>
    </div>
<?php endforeach; ?>
  </fieldset>

  <!-- ======================= the closing band ===================== -->
  <fieldset class="admin__block" id="band-cta">
    <?php services_band_header($service['cta'], 'service[cta]', 'The closing band',
        'The band at the foot of this page.'); ?>
    <div class="admin__grid">
      <?php services_text_field('service[cta][title]', 'Heading', $service['cta']['title'], '', true); ?>
      <?php services_area_field('service[cta][text]', 'Under it', $service['cta']['text'], '', 2); ?>
      <?php services_text_field('service[cta][label]', 'Button label', $service['cta']['label']); ?>
      <?php services_text_field('service[cta][href]', 'Button links to', $service['cta']['href']); ?>
      <?php services_icon_field('service[cta][icon]', $service['cta']['icon']); ?>
    </div>
  </fieldset>

  <!-- ====================== search and sharing ==================== -->
  <?php admin_meta_band('service:' . $service['id']); ?>

<?php endif; ?>

  <?php /* The last control in the form, and the only reason it exists is that
           its ABSENCE is readable. See admin_form_tail(). */ ?>
  <?= admin_form_tail() ?>
</form>

<?php
admin_foot(
    '<p>Last saved ' . h((string)($data['updated'] ?: 'never')) . '. '
    . 'The services index and all ' . count(services_all($data)) . ' service pages '
    . 'are one document, <code>content/services.json</code>.</p>'
);
