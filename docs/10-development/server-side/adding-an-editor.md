# Making a page editable

**Applies to:** backend

Turning a static page into one the admin can manage. This is the recipe for the fourteen pages that
are still hand-edited HTML.

**Do this only when the page genuinely changes without a redeploy.** Two pages qualify today: job
posts appear and expire, and contact details change. A page whose copy is revised twice a year is
better as HTML — an editor is a form, a model, a renderer and a test suite to maintain forever.

---

## What you are building

Five pieces:

| | |
|---|---|
| a **model** | `lib/<name>.php` — the fields, their defaults, their validation |
| **data** | `content/<name>.json` |
| a **renderer** | `pages/<name>/index.php` — replaces `tech4time-website-frontend/index.html` |
| a **form** | `sections/<name>.php` |
| a **registry entry** | a row in `ADMIN_SECTIONS`, and usually one in `ADMIN_RAIL_SECTIONS` |

The shell needs to know nothing else. The rail draws itself from the registry.

---

**The example below is a page that does not exist**, deliberately: the four editors that do —
careers, contact, company profile and about — are the ones to copy from, and an example that named
one of them would drift out of step with it. `sections/about.php` is the most recent and the
closest to this recipe.

## 1. The model — `lib/<name>.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/html.php';

function partners_defaults(): array
{
    return [
        'updated' => '',
        'hero'    => ['title' => '', 'lede' => ''],
        'values'  => ['items' => []],
    ];
}

function partners_value_defaults(): array
{
    return ['id' => '', 'title' => '', 'body' => '', 'icon' => ''];
}

function partners_load(): array   { /* store_read + defaults */ }
function partners_save(array $d): bool { /* validate + store_write */ }
function partners_validate(array $d): array { /* → field => message */ }
```

`*_defaults()` **is** the shape. Everything else reads it — see
[content-model.md](content-model.md).

## 2. The data — `content/<name>.json`

Seed it with the page's current content, so the first render is identical to what was there before.

## 3. The renderer — `pages/<name>/index.php`

Rename `tech4time-website-frontend/index.html` to `index.php` and replace the editable copy with values from the model.

```php
<?php
require_once __DIR__ . '/../../lib/partners.php';
$data = partners_load();
?>
…
<h1><?= h($data['hero']['title']) ?></h1>
```

**Everything through `h()`.** Rich text is emitted already sanitised by `rt_sanitise_html()` on save.

Keep the markup otherwise identical — `check_shared_markup.py` still applies, and the head, header
and footer must stay byte-identical to the templates.

## 4. The form — `sections/<name>.php`

Start by copying `sections/contact.php`. It is the fuller of the two and demonstrates
repeatable rows, reordering, validation display and the save cycle.

The obligations:

```php
<?php
if (!defined('T4T_ADMIN')) { http_response_code(403); exit; }   // required
```

- `admin_check_csrf()` on every POST
- validate through the model, never in the form
- `admin_redirect()` after a successful save, so a refresh does not re-post
- render errors beside the field they belong to
- **`data-async` on every `<form>`**, and an `id` on the one the page-level Save belongs to
- **every link between screens written as `?s=<section>`** — see below
- **`$action` assigned locally**, before the POST block:
  `$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');`

### Nothing in an editor may reload the page

Two files see to it, and both work by doing what the browser would have done. `admin-forms.js`
posts the form and puts `#admin-main` back; `admin-swap.js` follows the link and puts `#admin-body`
back. Neither has a server side, so there is nothing to add in PHP — but there are two things not
to get wrong:

**Forms:** `data-async`. Without it the form navigates, and a navigation lands at the top of the
document, which on a long editor means the row somebody was arranging scrolls off the screen.

**Links:** `?s=<section>` on the admin's own path — `admin_url()` writes exactly that, so use it.
`admin-swap.js` swaps anything matching that shape and leaves everything else to the browser, which
is the right answer for the three kinds of link that are not a move between screens: an in-page
anchor (`#…`), another origin (`public_url()`, the live site), and anything opening in a new tab.
A link that is *none* of those four — `setup.php`, a bare path, an absolute URL back to this host —
tears the shell down and rebuilds the rail with it.

**Every band's head comes from `admin_band_head()`** — the legend, the blurb,
the "Add a …" button and the shown/hidden switch, in that order. The button is
up here rather than under the rows because a band can be fifty rows long, and
the band's name is where "On this page" lands you.

Its `do` value must begin with the same word the band's fields are named with:
`technology-add:0` beside `technology[items][…]`. `admin-forms.js` finds the
row it just added by that name and puts the focus in it, and a band whose
button and fields disagree adds a row while the screen appears not to move.

**A form with a file input needs `enctype="multipart/form-data"`.** Without it
the browser posts the *filename* — PHP finds nothing in `$_FILES`, the save
reports success, and the picture never leaves the machine. Nothing errors, so
nothing catches it except `test_admin_forms.py`, which now checks every form on
every screen.

**Unsaved work:** nothing to do. Typing anywhere in `#admin-main` marks the screen, and every way
out of it — a link, Back, a reload, signing out — asks before it goes. A save lifts the mark; a row
added and not saved keeps it, because that row is in the form and not in `content/*.json`.

`test_admin_forms.py` walks every screen and fails with the offending `href` in the message, so
this is caught rather than discovered. It also asserts the rail element itself survives a move: the
rail is outside `#admin-body` on purpose, and it is what carries the account menu, the scroll
position and the width somebody chose.

### The shell gives you three things — ask for them

`admin_head()` takes two optional arguments beyond the lede, and a page with a long form wants
both:

```php
admin_head('<name>', $user, $lede,
    NAME_OUTLINE,                                    // "On this page", down the right
    ['form'    => '<name>-form',                     // the id on your <form>
     'label'   => 'Save the <thing>',
     'short'   => 'Save',                            // shown when the bar is under 44rem
     'discard' => admin_url('<name>')]);
```

The outline is `['anchor-id' => 'Label', …]` in page order, and each key must be the `id` on the
matching `<fieldset>`. `admin_head()` holds it and `admin_foot()` writes it, in a column to the
right of the form — you pass it once and there is nothing to close. It is the only thing that makes
a form of this size legible: the company editor is 282 rows and it was reported as not containing
its own data, because the only way to learn a band existed was to scroll to it.

It spent one release nested inside the rail, under the current section, which is the wrong column —
the rail is a list of places to go and this is a map of where you already are.

**Spacing is not yours to choose.** `admin.css` sets one rhythm for every form in the admin —
0.5rem inside a field (label, control, hint), 1.5rem between fields, 2rem between a field and the
next block — and `check_admin_a11y.py` measures it on every screen and fails on anything tighter. Use
`.admin__field`, `.admin__grid`, `.admin__block` and `.admin-card` and you get it; a margin of your
own is how one page ends up spaced differently from the next.

**There is no row head to write either.** `admin_card_head()` in `lib/admin.php` draws it — the
row's number, a one-line preview of what is in it, a Shown/Hidden pill, and the move and remove
controls at the right end of the line:

```php
admin_card_head('<band>', $i, $total, [
    'label'  => $row['title'],      // '' renders "Untitled <noun>"
    'noun'   => 'milestone',        // names the row for a screen reader
    'detail' => $row['year'],       // one line of its content, optional
    'icon'   => '',                 // a sprite id, optional
    'status' => $row['status'],     // '' for a row with no shown/hidden setting
]);
```

The button values are `<band>-up:<index>`, `-down:` and `-remove:`, which is the contract with your
POST handler.

**Do not copy this markup into your section.** It was copied once — contact had it, company made a
near-copy that left the buttons outside the flex line the stylesheet pushes them along, and the two
editors laid the same row out differently for a fortnight with no check able to see it. Every
repeatable row in the admin comes from this one function, and anything else a second editor needs
belongs beside it rather than in both.

**There is no save bar to write.** The Save button is drawn by `admin_head()` into the bar across
the top and reaches your form through the HTML `form` attribute. A section that draws its own is a
section whose button is somewhere else from every other one, and `html{scroll-padding}` in
`admin.css` is measured against that bar — `check_admin_a11y.py` fails if the two disagree, at 1200px
and again at 320px, where the bar wraps to two rows.

**And no `<link>` or `<script>` to write either.** `admin_head()` writes them, through
`admin_asset()`, which puts the file's modification time on the end of the URL. Assets here are
served with `max-age=31536000, immutable`, so an unversioned URL is a stale file the browser will not
revalidate on an ordinary reload — `check_secrets.py` fails on one.

## 5. The registry — `lib/admin.php`

```php
const ADMIN_SECTIONS = [
    …
    'partners' => [
        'label' => 'Partners',
        'icon'  => 'building',
        'desc'  => 'The partner list',
        'view'  => '/pages/partners/',
    ],
    …
];

const ADMIN_PAGE_SECTIONS = ['careers', 'contact', 'company', 'about', 'partners'];

const ADMIN_RAIL_SECTIONS = [ …, 'privacy', 'partners', 'seo'];
```

If the icon is not already in `ADMIN_ICONS`, add it there too — the admin inlines that whole list on
every page.

**Three constants, and they are not the same list.**

| | |
|---|---|
| `ADMIN_SECTIONS` | every section that exists — the label, icon, description and view link |
| `ADMIN_RAIL_SECTIONS` | **which of them the rail shows, and in what order** |
| `ADMIN_PAGE_SECTIONS` | the subset that edits a page of the website; what anything counting "the pages you can edit" asks |

Registry order is **not** rail order any more. `ADMIN_RAIL_SECTIONS` decides that, and it holds
eleven of the twelve sections: `account` is registered and deliberately absent, because it is about
the person rather than a page and is reached from the avatar menu at the foot of the rail.

**Do not "simplify" that by deleting the entry from the registry.** `admin_section()` returns
`'overview'` for any name `ADMIN_SECTIONS` does not list, so `?s=account` would land silently on the
Overview and the password and two-factor screens would become unreachable. A new section must be in
`ADMIN_SECTIONS`, and in `ADMIN_RAIL_SECTIONS` if it should appear in the rail.

### The label has to fit on one line

`.rail__label` is `white-space: nowrap`, and `--rail-wide` is sized to the longest label there is —
*Resource Certifications*, at present. A longer one is not ellipsised into something a person can
still guess at: it is a rail row that does not say what it is.

`check_admin_a11y.py` measures it. Every rail label must render as exactly one line box whose
`scrollWidth` does not exceed its `clientWidth`, at both rail widths and in the 320px chip strip. So
a label that no longer fits fails a check instead of being noticed by eye — and if a name genuinely
needs the room, `--rail-wide` in `admin.css` is what to widen. That token is the grid's first
column, so widening it moves the editing column too; re-run the 320px reflow measurement after.

## 6. Teach the checks

**`tools/check_content_model.py`** — add a `SUBJECTS` entry:

```python
{
    "name":  "partners",
    "model": ROOT / "lib" / "partners.php",
    "form":  ROOT / "sections" / "partners.php",
    "page":  ROOT / "pages" / "partners" / "index.php",
    "page_indirect": {"updated"},
    "form_exempt":   {"updated", "values.items.id"},
}
```

The check has to be told what to check — deliberately, so a new editor is never silently unverified.
It reads `ADMIN_PAGE_SECTIONS` and fails until the new section appears in `SUBJECTS` or in
`COVERED_ELSEWHERE`, so you cannot get past this step by forgetting it.

**If the form or the page consumes its fields in a loop, take `COVERED_ELSEWHERE` instead.** The
extraction here is regex over source: a `name="<?= h($field) ?>"` gives it `h` and `field`, not the
field names, and exempting the difference would leave the loop-driven fields — the ones most likely
to drift — unchecked while the check reported success. Name the round-trip test instead, and write
the reason beside it. `test_careers_admin.py` is the worked example.

**`tools/test_<name>_admin.py`** — copy `test_contact_admin.py`. It signs in through
`tools/admin_session.py`, so you inherit a real sign-in rather than faking one.

## 7. Document it

- [00-orientation/repository-map.md](../../00-orientation/repository-map.md) — the page is now `.php`
- [40-reference/content-schemas.md](../../40-reference/content-schemas.md) — the new schema
- [10-development/where-to-change-things.md](../where-to-change-things.md) — "change X" now means the admin
- [30-operations/content-runbook.md](../../30-operations/content-runbook.md) — how to use it

`check_docs.py` fails until the section appears in the docs.

---

## The checklist

- [ ] `lib/<name>.php` with `*_defaults()`, `*_load()`, `*_save()`, `*_validate()`
- [ ] `content/<name>.json` seeded with the current content
- [ ] `pages/<name>/index.html` → `index.php`, rendering from the model, everything through `h()`
- [ ] `sections/<name>.php` with the `T4T_ADMIN` guard, CSRF on POST and `$action` assigned
- [ ] the document name is in **`CONTRACT_DOCUMENTS`** — the deploy seeds every name it lists, and a page missing from it reaches the host with no record at all
- [ ] `data-async` on every form; an `id` on the main one; `NAME_OUTLINE` passed to `admin_head()`
- [ ] every link between screens is `admin_url()` — anything else is a full page load
- [ ] every band head is `admin_band_head()`, with the add button's prefix matching the field names
- [ ] `enctype="multipart/form-data"` on any form holding a file input
- [ ] an `id` on every `<fieldset>` the outline names
- [ ] `ADMIN_SECTIONS`, `ADMIN_RAIL_SECTIONS` and `ADMIN_PAGE_SECTIONS` updated; icon in `ADMIN_ICONS`
- [ ] the rail label fits on one line — `check_admin_a11y.py` measures it
- [ ] a card in `sections/overview.php`'s `$cards`, in the same order. **Nothing checks this** — the Overview would simply not mention the new editor
- [ ] **no `meta` fieldset in the form.** A new page's title and description are edited on the SEO screen; the editor renders `admin_meta_band()` in that place instead, and its `*_from_post()` iterates `contract_page_bands()` so a save cannot blank them
- [ ] `check_content_model.py`: a `SUBJECTS` entry, or a `COVERED_ELSEWHERE` one naming the test
- [ ] `test_<name>_admin.py`
- [ ] `test_admin_forms.py` still passes — it asserts every form in the shell is async, and every link in it swappable
- [ ] Docs updated
- [ ] `.gitignore` covers `content/<name>.json.bak`

---

## Three things the privacy editor learnt, if yours nests

**A list inside a list inside a list works, and the parent indices go in the BAND name.** The verb
that `admin_card_head()` builds is `<band>-<verb>:<index>`, and the index is cast to an `int` — so
two coordinates cannot both live there. `block-3-up:2` and `row-3-2-remove:1` are what the privacy
editor sends; `$band` is a free string and carries as many parents as you need.

**Move a row with `admin_move_row()`, not with your own `array_splice`.** Three editors had written
that function before it was one function, and each copy carried the same comment saying four copies
of an `array_splice` is four places for an off-by-one to live.

**If a row's id is an ANCHOR, assign ids with `contract_identify_rows()`.** It claims every id
somebody already chose before it mints anything new. Minting in row order instead lets a row added
above an existing one with the same name take that row's id, silently renaming the incumbent — and
if the id is a fragment somebody linked to, that link now lands in the wrong place.

---

## Two things that will catch you out

**The deploy must not overwrite the new JSON.** The moment a page becomes editable, its
`content/*.json` on the host is live data written by other people. Add it to the exclude list —
[routine-deploys.md](../../20-deployment/routine-deploys.md).

**The footer problem, if the page carries contact details.** Anything repeated in every page's
footer is markup, not content, and the editor cannot reach it. That is what
`tech4time-website-frontend/tools/sync_site_contact.py` exists for, and it needs a deploy to take effect.
*shared-markup.md* (in tech4time-website-frontend)
