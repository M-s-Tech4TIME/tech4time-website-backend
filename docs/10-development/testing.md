# Testing

**Applies to:** both

What each check proves, when to run it, and how to read a failure.

There is no test runner and no config file. Every check is a Python script you run directly, exits
non-zero on failure, and prints what failed rather than that something did.

---

## Before every commit

Fast, no browser needed. Run all seven.

```bash
python3 tools/check_contrast.py        # WCAG AA in both colour modes
python3 tools/inject_icons.py --check  # every page's inlined icon block is current
python3 tools/check_shared_markup.py   # no page's copied markup has drifted  (frontend)
python3 tools/check_content_model.py   # model, form and renderer still agree
python3 tools/check_secrets.py         # nothing secret committed; no protection removed
python3 tools/check_docs.py            # the docs still describe the code
python3 tools/audit_pages.py           # SEO, accessibility, structure, internal links
python3 tools/build_deploy_set.py --check   # nothing secret or local is bound for the server
python3 tools/check_shared_lib.py
python3 tools/check_shared_facts.py    # the offices, email and phone the policy repeats from the contact page
python3 tools/check_shared_repos.py      # the four files both halves hold identically
python3 tools/check_form_dom.py        # no script reads a property a form's own control hides
```

> **Half the suite is in the other repository.** The public site's pages, its markup auditors, its
> icon injection and the browser crawls over it went with the pages they were written for. Neither
> list is the whole suite any more, and neither pretends to be — `check_content_model.py` and
> `check_docs.py` each print which half they ran and name the repository that does the other.

## When you touched the admin, the auth, or an editor

```bash
python3 tools/test_admin_auth.py        # the whole sign-in cycle, over HTTP
python3 tools/test_publish_client.py    # the push, and the save that calls it
python3 tools/test_careers_admin.py     # the job post editor
python3 tools/test_contact_admin.py     # the contact page editor
python3 tools/test_home_admin.py        # the home page editor — six lists
python3 tools/test_about_admin.py       # the about page editor
python3 tools/test_services_admin.py    # the services editor — two screens, one document
python3 tools/test_certifications_admin.py  # the certifications editor — three lists deep
python3 tools/test_branding_admin.py    # the branding editor — logos and the files in them
python3 tools/test_privacy_admin.py     # the privacy editor — three lists deep, and the anchor rule
python3 tools/test_seo_admin.py         # the SEO editor — and that no other editor blanks a meta band
python3 tools/test_svg.py               # the SVG sanitiser, which is a security boundary
python3 tools/test_store.py             # the JSON store itself
python3 tools/test_qr.py                # the pairing code, against libqrencode
```

`test_qr.py` needs `qrencode` installed (`sudo apt install qrencode`) and exits 0 with a notice if
it is not. It is not a dependency of anything that ships — it is the second implementation
`lib/qr.php` is checked against, and a QR encoder checked only against itself is not checked.

Touched `lib/contract.php`, `lib/publish.php`, `lib/html.php` or the icon sprite? Then also:

```bash
python3 tools/check_shared_lib.py --update    # re-record the digests
# then copy the changed file AND tools/shared-lib.sha256 into tech4time-website-frontend,
# and bump CONTRACT_VERSION if the SHAPE of a document changed
```

## When you touched the rich-text editor

Needs Firefox and geckodriver. Slower.

```bash
python3 tools/test_editor.py           # the toolbar, driven as a person drives it
python3 tools/test_admin_forms.py     # nothing in the admin reloads the page
```

```bash
python3 tools/check_admin_a11y.py     # the signed-in admin, keyboard and pointer
```

**This is the gap that used to be named here.** `check_focus.py`, `check_dark_mode.py`,
`check_responsive.py` and `check_hover.py` crawl a list of *public* pages and never sign in, so they
never covered these screens — before the split as well as after it. They went to
`tech4time-website-frontend` with the pages they were written for, and for a while this paragraph
said the admin had never been checked for focus visibility, tap targets at 320px, or dark mode.

It has now. `check_admin_a11y.py` signs in the way `test_editor.py` does and walks every screen —
the ones anyone can reach, the ones behind the sign-in, and all five of the SEO editor's — asserting
four families of thing at 1200px and 320px. It also measures the rail: **every label must render as
one line box that is not cut off**, at both rail widths and in the 320px chip strip, so renaming a
section to something too long fails a check instead of being noticed by eye. It is one file rather than four because there are nine screens here and
four copies of the sign-in would be four things to fix when the login markup moves.

**It was not a formality.** The first run found that five `admin.css` rules wrote
`outline: var(--focus-ring)` — a shorthand taking a colour token, which resets `outline-style` to
`none` — so the rail, every input, the accordions and every editor button drew **no focus ring**,
each having overridden the correct rule in `base.css`. It also found the sticky save bar sitting on
top of any field tabbed to near the foot of the contact form. Both are fixed; the check is what
keeps them fixed.

> Interrupted browser runs leave processes behind. `pkill firefox geckodriver` clears them.

---

## What each one actually proves

### The static checks

| Script | Proves |
|---|---|
| `check_contrast.py` | every text/background pair in `theme.css` meets WCAG AA, in both modes, including the 3:1 bar for component boundaries |
| `inject_icons.py --check` | each page inlines exactly the icon symbols it references — no missing symbol, no dead weight |
| `check_shared_markup.py` | *(frontend)* every page's hero circuit and script block is byte-identical to `tools/templates/`, and `tech4time-website-frontend/lib/head.php` and `tech4time-website-frontend/lib/body.php` still emit the script hooks whose absence nothing else would notice |
| `check_content_model.py` | the model, the editor form and the page renderer describe the same fields — **in both directions**, so a field dropped from the page but left in the form is caught; and that every editor in `ADMIN_PAGE_SECTIONS` is checked either here or by a named test that exists |
| `check_secrets.py` | no secret is committed; the private store still refuses the web root; no auth bypass constant has returned; cookie flags intact; no password reachable by the audit log; every admin page shape noindexed |
| `check_docs.py` | every tool, library and admin section is documented; no doc cites a path that does not exist; no internal link is broken; no doc quotes a constant that has changed |
| `audit_pages.py` | per page: title and meta description, heading order, `alt` text, landmark roles, no repeated `id`, a label on every form control and an accessible name on every link and button, canonical URL, structured data, internal links resolve |
| `build_deploy_set.py --check` | the upload set holds no `content/`, `tools/`, `docs/` or key, keeps the `public/.htaccess` that blocks them, and carries a seed for **every** document the contract defines, with no job posts in the careers one |
| `verify_live.py <url>` | run **after** a deploy, against the real host: the pages answer 200, `lib/`, `content/`, `tools/` and `/.git/` answer 403, and the security headers are present |

### The HTTP tests

These start a real PHP server on a spare port and drive it over HTTP.

| Script | Proves |
|---|---|
| `test_admin_auth.py` | first-run setup; **the setup key demanded of a request from off the machine**; signing in and out; a code works once; the lockout; the emailed reset cycle; recovery codes; the audit log; the refusal to run unsafely. Includes the RFC 6238 test vectors, so the TOTP implementation is checked against the specification rather than against itself |
| `test_qr.py` | `lib/qr.php` against **libqrencode**, module for module at a matched mask; then our own symbol decoded back to what went in; then the SVG parsed to confirm it draws that symbol and carries no inline style or script. The two encoders choose different masks and that is allowed — the ISO 18004 penalty rule for the 1:1:3:1:1 pattern is not what libqrencode implements — so the comparison is made at a mask both were told to use |
| `test_publish_client.py` | `publish_push()` and the save that calls it: a payload an **independent** verifier accepts, and every way it can fail arriving as something the editor can show |
| `test_careers_admin.py` | the job post editor: add, edit, reorder, delete, validation, CSRF, the atomic write — and that **every field the model declares reaches the live site**, by pushing a marker through each one and reading it out of the published document |
| `test_contact_admin.py` | the contact page editor, and the icon rail |
| `test_home_admin.py` | the home page editor: **six** lists, so add, remove, hide and reorder are exercised across them rather than on one — the mechanics are shared, and a break in the shared part would otherwise surface only in whichever list happened to be tested. Also each terminal line's kind and colour, the light/dark picture pair on a card, and the accent phrase that has to appear in the headline |
| `test_services_admin.py` | the services editor — the only one split across two screens, and the only one whose save **merges** rather than replaces. Both halves of that are covered: saving one service must not disturb another, saving the index must not write the six pages it never showed, and a service added on the list screen must survive the round trip. Plus add, remove, hide and reorder on the lists — including the **nested** one, a solution inside a group, which nothing else exercises — and that a hand-written solution id is kept rather than re-minted from its name |
| `test_certifications_admin.py` | the certifications editor — the only one with a list **inside a list**, so add, remove, hide and reorder are exercised at all three levels and, more to the point, a button pressed on one role group is checked to have left the others alone. Also that a new group arrives hidden, that its web address is minted from its first role and then **survives that role being renamed**, and that the counts shown beside the prose are the live ones rather than anything stored |
| `test_branding_admin.py` | the branding editor: logo variants and the files inside them, so add, remove, hide and reorder are exercised at both levels and a button pressed on one variant is checked to have left the others alone. Also that a new variant arrives hidden, that a card's preview and its download stay two separate records rather than one, and that the size shown beside a download is read off the file rather than typed |
| `test_privacy_admin.py` | the privacy editor, which is **three** lists deep: sections hold blocks and blocks hold rows, so every verb is exercised at all three levels and checked to have left the other two alone. Also that all six block kinds are present, that changing a kind narrows the block to that kind's fields rather than carrying the old ones invisibly, that a section which has been named keeps its anchor when a section with the same heading is added above it — the case the obvious implementation gets silently wrong — and that a policy disagreeing with the contact page still saves, because that comparison is a notice and never a refusal |
| `test_svg.py` **shared** | the SVG sanitiser, as the security boundary it is: a real logo survives and still draws, sanitising it twice changes nothing — which is what lets the receiving host prove bytes are clean without editing them — and script, event handlers, entities, embedded rasters, animation, filters and any reference off the file are each refused rather than quietly stripped |
| `test_store.py` | `lib/store.php`: telling apart missing, unreadable and corrupt; the atomic write; and the rule that a damaged file is never copied over a good `.bak`, because the backup is what damage is recovered from |

**The three that publish do so to a stub, and that is the point.** `tools/publish_stub.py`
implements the wire format a second time, in Python, from its written description. Pointing them at
the real endpoint in `tech4time-website-frontend` would check the two halves against each other rather than
against the format they both implement — a bug they shared would pass. The frontend has the mirror:
`test_publish.py` signs in Python and posts to the real PHP endpoint. **Neither side is ever checked
against its own counterpart.**

The admin tests sign in for real, through `tools/admin_session.py`, against a throwaway account in a
private directory under `/tmp` — so a test run cannot disturb your own local account. Every test
runs against a copy of the real data files, restored afterwards whether the run passes or fails.

### The browser tests

| Script | Proves |
|---|---|
| `test_editor.py` | the rich-text editor driven as a person drives it, including a real sign-in: the toolbar, the selection, and that alignment is a class and never an inline style |
| `test_seo_admin.py` | all five screens of the SEO editor: every field round-trips; the index lists every route **and** every service, so a service added in the services editor gets a card with no key registered anywhere; a page screen's save leaves **the rest of that document untouched**, because it writes one band of a file another editor owns; add, remove, reorder and hide on `sameas` and `hours`; an over-long title and a duplicate title are both refused; a page set to `noindex` leaves the sitemap; and the certifications description is measured **after** its `{certifications}` token is filled. **Its most important assertion is about the other nine editors**: saving any of them leaves that document's `meta` band exactly as it was. Those forms stopped rendering those fields, and a `*_from_post()` still naming the band would blank a title on every save — silently, because an empty string is a valid title. Broken on purpose, the check reports four fields emptied on every document |
| `test_admin_forms.py` | that nothing in the admin throws the document away. Every form carries `data-async` and every link is one `admin-swap.js` will answer; adding, moving, removing and saving each leave the page where it was and the focus where it is wanted; following a rail item changes the bar, the tab, the address and `aria-current` while leaving the rail element itself standing; Back and Forward work. Then the same edits **and the same moves** with **JavaScript switched off** — including the one measurement only that browser can make, that the server draws a narrow rail on its own, which is what stopped the rail flashing open and shut on every load. Then, on every screen, the address each form is **actually handed** by `fetch` — which is not the same question as what the module would answer, and is how a control named `action` took the careers screen down for ten days — and one real press on that screen, asserting the document on disk moved |
| `check_hover.py` | every interactive element visibly responds to a real pointer |
| `check_dark_mode.py` | every page in both themes, as painted — catching what a CSS reader cannot, like a token that resolves to the same colour as its background |
| `check_responsive.py` | every page at 320, 360, 414, 640, 768, 1024 and 1440px: the document does not scroll sideways, no link, button or field is wider than the screen, and no tap target is under 24px. Each width is a frame, not a window — see *0015* (in tech4time-website-frontend), because Firefox silently clamps a window at about 500px and a check written the obvious way reports widths it never tested |
| `check_focus.py` | every page tabbed one stop at a time, at desktop and mobile widths: each focused element has a visible ring (SC 2.4.7) and is not entirely covered by the sticky header or the fixed dock (SC 2.4.11). Runs with reduced motion so scrolling is instant, and **refuses to run** if that preference did not take effect — otherwise every position it reads is mid-scroll |

They skip with a notice and exit 0 when Firefox or geckodriver is missing, rather than failing —
so a machine without a browser can still run everything else.

---

## Reading a failure

**`check_shared_markup.py` fails** — someone edited the hero circuit in a page instead of in
`tools/templates/`. Fix the template, then `python3 tools/propagate_shared.py`. If it names
`tech4time-website-frontend/lib/body.php` instead, a `data-` hook has been dropped from the emitter and a browser behaviour has
silently gone with it; the message says which one.

**`check_content_model.py` fails** — you changed a content shape in one of the three places it
lives. The message names the field and which layer is missing it.
[content-model.md](server-side/content-model.md)

**`check_docs.py` fails** — the code and the documentation disagree. Usually you added a file and
have not documented it, or changed a constant the prose quotes. The message names both sides.

**`check_secrets.py` fails** — read the message carefully before "fixing" it. It only fails for
things that would silently weaken the admin, and every one of its checks was verified against a
deliberate breakage before being trusted.

**`test_admin_auth.py` hangs or fails oddly** — most often a leaked PHP server from a previous run
holding the port, or a stale `/tmp` private directory. It uses a fresh one each run; if in doubt,
`pkill -f 'php -S'`.

**A browser test fails only sometimes** — check for leaked `geckodriver` processes first. That is
the usual cause of a flake here.

---

## Adding a test

Match the existing shape. Each script is standalone, starts what it needs, cleans up after itself,
prints one line per check, and exits non-zero if any failed. Do not add a framework — there is no
build step, and a dependency is a thing that has to be resurrected before a typo can be fixed.

If your change makes a protection that could be removed without anything failing, add the check to
`check_secrets.py` — and **prove the check works by deliberately breaking the thing it guards**
before you trust it.

### If you are measuring time

The performance checks in `test_motion.py` cost two failed CI runs and one failed merge before they
were written correctly. The rules that came out of it:

**A threshold needs more margin than the machine has noise.** The frame budget was `baseline + 3ms`
and passed locally by 1ms. On a slower CPU the same code measured 7ms over, and failed. A threshold
that passes by 1ms is not a threshold; it is a coincidence waiting to be reported as a regression.

**Never assert on a maximum.** The stall check compared the single worst frame of about a hundred
against 50ms. Two runs of identical code on the same runner measured 44ms and 55ms — one passed,
one failed. A maximum is the most outlier-sensitive number available, and on a shared runner one
stretched frame is as likely to be the hypervisor as the page. Use a percentile for "is it steady",
and keep the maximum only for a ceiling so high that nothing but a real freeze reaches it.

**Say what drew the page.** Headless Firefox software-rasterises everywhere, including on a
workstation with a good graphics card — it reports `llvmpipe` on a developer laptop and a CI runner
alike. A whole afternoon went into a fix premised on one having a GPU and the other not. These
numbers are CPU-bound on every machine that will ever run them, and say nothing about what a visitor
with hardware compositing sees.

**Two claims, not one.** "Janky" and "frozen" are different failures. They deserve different
statistics and different numbers.
