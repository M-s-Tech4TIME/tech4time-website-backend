# Tools

**Applies to:** both

Every script in `tools/`. **None of them is deployed** — `public/.htaccess` blocks `/tools/` as a backstop,
but the real rule is that the directory never gets uploaded.

Two exceptions are uploaded by hand, run, and deleted: `host-probe.php` and `admin-cli.php`.

Everything is Python 3 standard library, apart from the asset builders, which need **Pillow**. The
browser tests speak to geckodriver over its wire protocol — there is no Selenium.

---

## Running the admin

```bash
python3 tools/serve.py            # http://localhost:8001
python3 tools/serve.py 8080       # a different port
```

It serves **`public/`**, not the repository, because that is what the host serves. A request that
escapes it 404s here exactly as it would there — `tools/dev-router.php` sees to that, and a
development machine on which `/../lib/auth.php` resolves would teach the wrong lesson.

To watch content actually travel, run `tech4time-website-frontend` beside it and point this at it:

```bash
T4T_PUBLIC_URL=http://localhost:8000 python3 tools/serve.py 8001
```

Both private stores need the **same** `publish.key`, or every publish is refused as `unknown-key`.

[running-locally.md](../10-development/running-locally.md)

### Looking at it without signing in

```bash
python3 tools/preview.py            # opens a browser, already signed in
python3 tools/preview.py --no-browser
python3 tools/preview.py 8124       # a different port
```

For reviewing the editors rather than using them. `serve.py` gives you the admin behind its real
password and its real second factor, which is right — and means the cost of looking at a change to
the rail is finding the phone with the authenticator on it.

**Nothing is bypassed.** `preview.py` creates a real account in a *throwaway* private store under
`/tmp`, signs in through the real `/login.php` with the real password and a real time-based code,
and leaves the browser sitting in the session that comes back. There is no flag in `lib/` it sets
and no branch in the application that knows it exists; delete the file and the admin is exactly as
protected as before. The account cannot be signed into again once the process stops, because the
directory it lived in is deleted.

It also does two things `serve.py` does not, and both matter:

- `content/` is **copied out before the server starts and copied back on the way out**, so pressing
  Save in a preview cannot leave a change in the repository.
- publishing is pointed at a **closed port on localhost**. `lib/publish_client.php` falls back to
  `PUBLIC_SITE` — `https://tech4time.bd` — when nothing overrides it, and a preview that quietly
  pushed a document to the live website would be a bad way to learn that. Save writes the record and
  the editor says the live site does not have it, which is true.

Firefox and geckodriver open the window for you. Without them it prints the credentials and a code
that reticks every thirty seconds, and you sign in yourself.

---

## Checks — run these before committing

| Script | Proves |
|---|---|
| `check_contrast.py` | the palette meets WCAG 2.1 AA in both modes |
| `check_css.py` | every stylesheet's comments and braces balance, and no shorthand (`outline`, `border`) is handed a bare colour token — which parses, computes to a colour and a width, and draws nothing, because the shorthand reset the style to `none`. Five rules in `admin.css` had exactly that, and the admin had no focus ring because of it |
| `check_content_model.py` | the model and the editor still describe the same thing, and no editor is unchecked |
| `check_secrets.py` | nothing protecting the admin has quietly stopped protecting it — including that `lib/`, `sections/` and `content/` are still outside the document root, that no markup carries an inline handler the CSP will refuse, and that every asset URL goes through `admin_asset()` rather than being pinned unversioned behind a year-long `immutable` cache |
| `check_docs.py` | the documentation still describes the code: undocumented tools, libraries and **assets**; dead links; cited paths that have gone; constants whose documented values drifted. The asset and tool checks run **both** ways — a stylesheet or script named in the prose but absent from disk fails too, unless some document says in full which repository it moved to |
| `build_deploy_set.py --check` | the set of files bound for the server holds nothing it must not, and nothing is missing |
| `check_shared_lib.py` | the four files both repositories hold identically have not been edited here |
| `check_form_dom.py` | no script reads a property off a `<form>` that one of the form's own controls has hidden. `HTMLFormElement` is `[LegacyOverrideBuiltIns]`, so a control's **name wins over the interface's own property of that name**: a form holding `<input name="action">` answers `form.action` with that input, `fetch()` posts to `/[object%20HTMLInputElement]`, and the server answers 404. Ordinary submission is unaffected, which is why it can appear years after the field was added — on the day a script starts posting the form. It refuses eight members outright (`action`, `method`, `enctype`, `target`, `elements`, `submit`, `requestSubmit`, `reset`) and re-derives the rest from the markup, so a field added tomorrow that shadows something a script already reads fails tomorrow. Control names that shadow a property nothing reads are listed as a standing notice, not a fault |
| `check_shared_facts.py` | facts stated in two documents at once — the offices, the email and the telephone are authored in **both** `content/privacy.json` and `content/contact.json`, on purpose, so this asks whether the policy still states what the contact page manages. Compared on a normalised form, so it reports a different street and stays quiet about a different comma. It can only see the **seed**: content edited in the admin never passes through git, and that half is covered by the standing notice the privacy editor draws from the same function |
| `check_shared_repos.py` | the same files, compared against **the other repository** rather than a local digest — plus every same-named tool, which must match unless `DIVERGENT` says why not. This is the only check anywhere that can see the two halves drift apart; `check_shared_lib.py` structurally cannot. Needs both repos present, or `--clone` |

**Half the suite is in the other repository.** The public site's pages, its markup auditors, its
icon injection and the browser crawls over it went with the pages they were written for.
`check_content_model.py` and `check_docs.py` each print which half they ran and name the repository
that does the other, rather than quietly checking less than they used to.

---

## Tests

### Over HTTP, against a real PHP server

| Script | Exercises |
|---|---|
| `test_admin_auth.py` | the whole sign-in cycle: first-run setup; **the setup key demanded of a request from off the machine**; signing in and out; a code works once; the lockout; the emailed reset cycle; recovery codes; the audit log; the refusal to run unsafely. Includes the RFC 6238 test vectors, so the TOTP implementation is checked against the specification rather than against itself |
| `test_publish_client.py` | `publish_push()` and the save that calls it: a payload an independent verifier accepts, and every way it can fail arriving as something the editor can show |
| `test_careers_admin.py` | the job post editor: add, edit, reorder, delete, validation, CSRF, the atomic write — and that **every field the model declares reaches the live site**, by pushing a marker through each one and reading it out of the published document |
| `test_contact_admin.py` | the contact page editor, its row buttons, and the same field round trip |
| `test_chrome_admin.py` | the header, footer and dock editor, all four screens: that every field round-trips through a save; that add, remove, reorder and **hide** work on all five row bands and that a new row **arrives hidden**, because an empty entry appearing in the navigation of every page of the site is not what pressing Add means; that a row keeps the id it was minted with through a reorder; that saving one part leaves the other two **exactly as they were**, since each screen merges them back from the file; that the dock bar stays at four keys, with no Add and no Remove; that a destination which is not a route is refused and a row with none at all likewise, while a **hidden** row may be half-finished; and that the standing notice about the footer's contact rows appears, names the values it means, ignores a hidden row, and **never blocks a save** |
| `test_settings_admin.py` | the site-identity editor: the rail row and the Overview tile, the index and its four part screens, an unknown part landing on the index rather than raising, and the **four** standing notices — an empty dark logo half; a pair with one half replaced and the other still holding the previous mark, which renders two different logos and is checked in **both** orders; a replaced logo with the shipped tab icon still under it; and a share card left behind by a new logo, which is the only one that reads another document — each appearing on its **own** condition, none drawn as an error, and none blocking a save. Plus the panel's own mark, which is the ninth place the logo appears: the rail **and the sign-in page**, at the size the document gives, served from this host rather than fetched across the wire from a public site that may be a closed port. Plus the road: `settings_edit()` writes, publishes, and **merges** rather than rebuilding, proved against a value in a part the save does not touch. And **a real upload, through the form, as a browser sends it** — the first multipart POST in either repository: every other admin suite checks that a screen says `enctype="multipart/form-data"` and stops, because the harness could not build the body, so the path from the browser's bytes through `upload_accept()` to the signed asset POST had never been driven. The mark lands at 180/360/540 in both formats, all six files are on this host and all six reach the live one, the other half is untouched, and clearing the **light** mark is refused while clearing the **dark** one is not |
| `test_home_admin.py` | the home page editor: six lists — the hero's badges and tags, the terminal's lines, the technical domains, the service cards and the Get to Know Us cards — add, remove, **hide** and reorder across them, each terminal line's kind and colour, the light/dark picture pair on a card, and that a picture may only point inside this site |
| `test_about_admin.py` | the about page editor: the story sections, the specialities and the why-us cards — add, remove, **hide** and reorder on each, the layout and side of a section, and that a picture may only point inside this site |
| `test_certifications_admin.py` | the certifications editor: role groups, and the roles and certifications inside them — add, remove, **hide** and reorder at all three levels, that a button pressed on one group leaves the others alone, and that a group's web address survives its roles being renamed |
| `test_branding_admin.py` | the branding editor: logo variants and the files inside them — add, remove, **hide** and reorder at both levels, that a button pressed on one variant leaves the others alone, that the preview and the download stay separate records, and that the size on the page is read off the file rather than typed |
| `test_seo_admin.py` | the SEO editor, all five of its screens: that every field round-trips; that the index lists every route **and** every service, so a service added in the services editor gets a card without anyone registering a key; that a page screen's save leaves **the rest of that document untouched** — it writes one band of a file another editor owns; that add, remove, reorder and **hide** work on `sameas` and `hours`; that an over-long title and a title duplicating another page's are both refused; that a page set to `noindex` leaves the sitemap; and that the certifications description is measured **after** its `{certifications}` token is filled, not before. Also the assertion no other suite here can make: **saving any of the nine page editors leaves that document's `meta` band exactly as it was** — those forms no longer render those fields, and a `*_from_post()` that still named the band would blank a title on every save, silently, because an empty string is a valid title |
| `test_privacy_admin.py` | the privacy editor: sections, blocks and the rows inside them — add, remove, **hide** and reorder at all **three** levels, that a press on one leaves the other two alone, that changing a block's kind narrows it to that kind's fields, that a section which has been named keeps its anchor when one with the same heading is added above it, and that a policy disagreeing with the contact page **still saves** |
| `test_svg.py` **shared** | the SVG sanitiser, tested as the security boundary it is: a real logo survives and still draws, sanitising it twice changes nothing — which is what lets the receiving host prove bytes are clean without editing them — and script, event handlers, entities, embedded rasters, animation, filters and any reference off the file are each refused rather than quietly stripped |
| `test_services_admin.py` | the services editor, which is the only one split across two screens and the only one whose save MERGES rather than replaces. Covers both halves of that: saving one service must not disturb another, saving the index must not write the six pages it never showed, and a service added on the list screen must survive the round trip. Plus add, remove, **hide** and reorder on the lists — including the nested one, a solution inside a group — and that a hand-written solution id is kept rather than re-minted from its name |
| `test_company_admin.py` | the company profile editor: all six lists, and add, remove, **hide** and reorder on each — plus the rule that hiding is not deleting, and that a picture may only point inside this site |
| `test_upload.py` | `lib/upload.php`, and mostly not by asking what it refused: it checks what came OUT still carrying what went in. EXIF stripped, an appended payload gone, an oversized picture reduced. Also **which pictures count as in use** — every name in `CONTRACT_DOCUMENTS` must answer `contract_images()`, an office photograph must be among them, which it was not, and a ladder's rungs must be among them in each of the twelve seats a document can hold a picture in. And **which widths get stored**: thirteen slot-and-source cases against the measured table, the alpha channel read back off every rung, and the two slot lists compared in both directions so a misspelt slot cannot pass as a picture that simply does not ladder. Those cases sit **before** the GD gate on purpose: nothing in them decodes a picture, so gating them would mean the check that caught a real deletion bug never ran on a machine without the extension. Skips the re-encoding cases with a notice where PHP has no GD; CI installs `php-gd` so they always run there. **It restores `public/uploads/` by contents, not by name** — it deletes that directory outright to prove `upload_store()` recreates it, which also destroyed the committed `.gitignore`, and a cleanup that only removed unrecognised files could not put that back |
| `test_qr.py` | `lib/qr.php`, against **libqrencode** — every module compared at a matched mask, then our own symbol read back and checked to say what went in, then the SVG parsed to confirm it draws that symbol and carries nothing the CSP refuses. Skips with a notice if `qrencode` is not installed |
| `test_end_to_end.py` | **the only thing that runs both halves at once.** The real admin on one port, the real public site on the other, one `publish.key` in two private stores, and nothing stubbed: a logo uploaded through the form with JavaScript off, then the OTHER port asked whether the header, the footer, the About row and both schema graphs moved together; a square mark uploaded and `/favicon.ico` fetched, which is assembled on the public host out of PNGs generated on the admin host; a colour changed and the generated stylesheet fetched; and a colour below AA refused before it reaches the wire. What it proves that neither half can alone is **files moving**: a picture is a ladder of renditions, each its own signed POST, and the check is that every path the record names arrived — both ladders and both single files, because the WebP half is the one nearly every browser takes and a missing rung there breaks the picture while the PNG ladder it fell back from looks perfect. It **refuses to start** when the frontend's `content/` has uncommitted changes, since it publishes into that directory and puts it back afterwards; `--clone` fetches a copy of its own on the same branch, which is what CI does |
| `test_store.py` | `lib/store.php`: telling apart missing, unreadable and corrupt; the atomic write; and the rule that a damaged file is never copied over a good `.bak`, because the backup is what damage is recovered from |
| `admin_session.py` | *(not run directly)* gives a test an admin account and signs it in — `preview.py` uses it too |
| `publish_stub.py` | *(not run directly)* the far side, implemented a second time in Python — **both** of its endpoints, told apart by their path: a document is a signed JSON envelope with a revision, a picture is the signed bytes and nothing else |
| `test_reconcile.py` | `reconcile.py`, against that stub. That every name in `CONTRACT_DOCUMENTS` has a model and every model is a document; that every function each of its PHP probes calls exists once that probe's `require`s have run; that a full run sends both halves and a second run sends nothing; that `--assets-limit` stops with a cursor, `--assets-after` carries on from it, and a refusal says which of those two to do; and that `--pace` is a real pause. It is the tool that repairs a live site, and until this existed nothing had ever run it |

**`publish_stub.py` is the point, not a shortcut.** The real endpoint is `tech4time-website-frontend/api/publish.php`, and testing this half against it would check the two halves against each other
rather than against the format they both implement — a bug they shared would pass. So each side is
checked against an independent implementation written from the description: this stub here, and over
there `tech4time-website-frontend/tools/test_publish.py`, which signs in Python and posts to the real PHP endpoint. **Neither side is
ever checked against its own counterpart.**

Every test runs against a **copy** of the real data files, restored afterwards whether the run
passes or fails, and against a private store in a throwaway directory under `/tmp`.

### In a real browser

| Script | Proves |
|---|---|
| `test_editor.py` | the rich-text editor driven as a person drives it, including a real sign-in: the toolbar, the selection, and that alignment is a class and never an inline style |
| `test_admin_forms.py` | that nothing in the admin **navigates** — a row added, moved, removed and saved, each keeping the scroll position and moving the focus to the right place, and a move between screens that changes the bar and the address bar without rebuilding the rail. Also that no link on any screen falls through to a full page load, which is the assertion that holds for editors not written yet. Then the same edits and moves again with JavaScript switched off in the browser. It also drives **every** editor rather than only the largest — the group that was missing is how a bug that made the contact screen jump to the top after every press survived a green run. It also stubs `fetch` on every screen and asserts the address each form is **actually handed** — not the one a helper says it would return — and presses a real careers button, checking that `content/careers.json` moved. That pair is what catches a form property hidden by one of the form's own controls ([ADR 0022](../90-decisions/0022-form-properties-are-read-off-the-prototype.md)) |
| `check_admin_a11y.py` | the **signed-in** admin: that a focus ring can be seen at every tab stop and is not hidden behind the bar across the top (SC 2.4.7, 2.4.11), that `html{scroll-padding}` still matches the bar it was measured against — at 1200px, and again at 320px where the bar wraps to two rows and `admin.css` carries a second number — that 320px neither scrolls sideways nor leaves a control under 24×24 (SC 1.4.10, 2.5.8), that dark mode paints, that nothing in a form is touching the thing above it — the vertical rhythm is one stylesheet's, so it is measured rather than eyeballed — and that every kind of control answers a pointer |

**This used to say the admin had never been checked.** `tech4time-website-frontend/tools/check_focus.py`,
`tech4time-website-frontend/tools/check_dark_mode.py`, `tech4time-website-frontend/tools/check_responsive.py` and
`tech4time-website-frontend/tools/check_hover.py` crawl a list of public pages and never sign in, so
they never covered the editor — before the split as well as after it. They went to
`tech4time-website-frontend` with the pages they were written for.

`check_admin_a11y.py` closes that gap. It is one file rather than four because there are nine
screens here, not sixteen pages, and four copies of "start PHP, sign in, walk the screens" would be
four copies of the sign-in — the part most likely to need changing.

It found, on its first run, that five rules in `admin.css` wrote `outline: var(--focus-ring)` where
the token is a *colour*: the shorthand resets `outline-style` to `none`, so the rail, every text
input, the accordions and every editor button had **no focus ring at all**, having overridden the
correct one in `base.css`. And that the sticky save bar covered any field tabbed to near the bottom
of the contact form. Both are fixed.

---

## Publishing

The two halves and the one route between them — [the publish API](../10-development/server-side/publish-api.md).

| Script | Does |
|---|---|
| `make_publish_key.py` | Create the key both halves sign content with. Run **once**, then copy the printed value into the other half's private store by hand |
| `reconcile.py` | *(uploaded and run on the host — see below)* Send anything the public site is behind on, and say plainly when the public site is **ahead**. The picture half **paces itself and can be resumed** — see below |
| `check_shared_lib.py` | Assert the four shared files against a committed digest. `--update` re-records after a deliberate change |

`make_publish_key.py` is deliberately not automatic. Every other secret here creates itself on first
use; this one must not, because a key that appears by itself appears **differently** on each host and
the failure reads as "signature rejected" until somebody thinks of it.

`reconcile.py` needs no status endpoint: every answer from the public site's endpoint carries the
revision that host holds — the refusals as well as the acceptance — so an attempt *is* the question,
and an attempt refused as `not-newer` has changed nothing.

**It is uploaded, run and deleted, like `admin-cli.php`.** `tools/` is never deployed, and the host
is the only place it is useful — it reads *that* machine's `content/` and *that* machine's private
store. Run from a development clone it would publish development content to whatever it was pointed
at. So it takes the site root as an argument and lives in the HOME directory, above the deploy
target rather than inside it.

`check_shared_lib.py` covers the icon sprite as well as the three PHP files. `CONTACT_ICONS` is in
the contract, so an icon this editor offers must be one the public page can actually draw; a drifted
sprite renders as an empty box with both halves behaving exactly as written.

---

## Deploying

| Script | Does |
|---|---|
| `build_deploy_set.py` | Builds the upload set from an explicit allow list — `public/`, `lib/`, `sections/` — and asserts what is and is not in it |
| `verify_live.py <url>` | Asks the running admin whether the deploy landed: the pages answer, the headers are there, and `lib/`, `sections/` and `content/` answer **404** |

`verify_live.py` requires 404 and not 403 for those three, and the distinction is the whole point. A
403 would mean they are inside the document root and something is choosing to block them — the
weaker arrangement [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) exists to
replace. It would be a finding, not a pass.

---

## Host tools — upload, run, delete

### `host-probe.php`

Answers what can only be answered on the host: the PHP version, whether argon2id is available, where
the private store resolves to, and whether `mail()` actually sends. Upload it to the document root,
request it with its token, read the output, **delete it**.

### `admin-cli.php`

The break-glass path when every way into the admin is shut. Upload it to the **home directory**,
above `~/admin.tech4time.bd` — never into the document root.

```bash
php ~/admin-cli.php list          # what accounts exist
php ~/admin-cli.php passwd        # set a new password; ends every session
php ~/admin-cli.php codes         # issue ten new recovery codes
php ~/admin-cli.php totp-clear    # unpair the authenticator
php ~/admin-cli.php unlock        # clear a lockout
php ~/admin-cli.php log 25        # the audit log
php ~/admin-cli.php where         # which files it is working on
```

It asks for no password, because anyone who can run it can already read the accounts file. Delete it
when you are done. [secrets-recovery.md](../30-operations/secrets-recovery.md)

### `reconcile.py`

Uploaded to the **home directory** the same way, and run against the site root:

```bash
python3 ~/reconcile.py ~/admin.tech4time.bd            # every document
python3 ~/reconcile.py ~/admin.tech4time.bd careers    # one of them
```

With no argument it looks for `lib/publish_client.php` beside itself and then at
`~/admin.tech4time.bd`, and refuses with the list of places it tried rather than guessing. Delete it
when you are done — the next deploy would not, because it is outside the target.

**The picture half paces itself, and has to.** Every picture is one signed POST, a picture is stored
at three widths in two formats, and the host's firewall drops an IP that makes roughly a hundred
requests in a few minutes — at the TCP layer, so it reads as an outage rather than as a limit. Three
seconds between requests puts sixty in a three-minute window, so a few hundred files take minutes
rather than seconds. That is the right trade for a repair tool somebody starts by hand and watches.

Each name is printed as it goes, and **however a run stops it says how to carry on** — a limit
reached, a refusal, a `^C`, a dropped connection:

```bash
python3 ~/reconcile.py ~/admin.tech4time.bd --assets-limit 40    # stop after forty
python3 ~/reconcile.py ~/admin.tech4time.bd --assets-after 3f2a…  # carry on from there
python3 ~/reconcile.py ~/admin.tech4time.bd --pace 0             # only against a host you know
```

Re-sending is always safe: a picture is content-addressed, so one the live site already holds is
answered `held` and writes nothing.

> Run `where` first, always. It prints the private store it resolved to, and a rescue tool pointed
> at the wrong directory reports an account file that does not exist while the real one sits
> untouched — which is precisely the answer a rescue tool must not give. It hands
> `lib/private.php` a `DOCUMENT_ROOT` of `<root>/public`, not `<root>`, for that reason.

---

## Adding a tool

Every script in `tools/` must carry, in its docstring: what it is for, that it is **not deployed**,
and how to run it. Then add a row to this page — `tools/check_docs.py` fails if a script here is
undocumented, or if this page names one that no longer exists.

A tool that belongs to the other half is named with the repository in front —
`tech4time-website-frontend/tools/inject_icons.py` — and that full path has to appear at least once,
which is what stops "it is in the other one" from keeping a dead name in the prose forever.
