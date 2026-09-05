# The libraries — `lib/`

**Applies to:** both

Eleven PHP files. What each owns, and which one to open.

None of them is reachable over HTTP: `public/.htaccess` has `RewriteRule ^lib/ - [F,L]`, and the private
store they read from is outside the document root entirely.

---

## At a glance

| File | Owns | Depends on |
|---|---|---|
| [`html.php`](#htmlphp) **shared** | escaping, and the rich-text sanitiser | — |
| [`store.php`](#storephp) | reading and writing JSON atomically | — |
| [`contract.php`](#contractphp) **shared** | the shape of every editable document | `html` |
| [`careers.php`](#careersphp) | what this side does with a job post | `contract`, `store` |
| [`contact.php`](#contactphp) | what this side does with the contact page | `contract`, `store` |
| [`company.php`](#companyphp) | what this side does with the company profile | `contract`, `store` |
| [`about.php`](#aboutphp) | what this side does with the about page | `contract`, `store` |
| [`certifications.php`](#certificationsphp) | what this side does with the certifications page | `contract`, `store` |
| [`home.php`](#homephp) | what this side does with the home page | `contract`, `store` |
| [`services.php`](#servicesphp) | what this side does with the services document | `contract`, `store`, `publish_client` |
| [`upload.php`](#uploadphp) *(backend)* | a file somebody chose, turned into a picture this site will show | `publish` |
| [`publish.php`](#publishphp) **shared** | how a document is signed and checked on the wire | `private`, `contract` |
| [`publish_client.php`](#publish_clientphp) *(backend)* | sending one | `publish` |
| [`footer-fingerprint.php`](#footer-fingerprintphp) *(frontend, generated)* | what this site's footers currently say | — |
| [`private.php`](#privatephp) | where the secrets are, and key derivation | — |
| [`totp.php`](#totpphp) | RFC 6238 authenticator codes | `qr` |
| [`qr.php`](#qrphp) | the pairing code, as SVG | — |
| [`throttle.php`](#throttlephp) | counting attempts | `private`, `store` |
| [`mailer.php`](#mailerphp) | the one place mail leaves this site | — |
| [`auth.php`](#authphp) | accounts, hashing, sessions, the audit log | `private`, `totp`, `store` |
| [`reset.php`](#resetphp) | the emailed one-time code | `auth`, `mailer`, `throttle` |
| [`admin.php`](#adminphp) | the section registry and page furniture | `auth`, `html` |

Roughly bottom-up: `html` and `store` know nothing about anything; `admin` sits on top of all of it.

---

## Content and rendering

### `html.php`

`h()` · `rt_sanitise_html()` · `rt_safe_href()` · `rt_plain()`

Escaping on output, and the sanitiser that decides what HTML an editor may write.

**Written by hand because there is no DOM extension on this host** — `DOMDocument` does not exist.
So it parses the markup itself, and the way it stays safe without a parser is worth understanding:

> It never passes anything through. It walks the input and, for each tag it recognises, **writes a
> new one** from an allow-list of names and attributes. Anything unrecognised — a tag, an attribute,
> a stray angle bracket — is discarded rather than copied.

So the output cannot contain a construct this file does not explicitly know how to emit. That is a
much smaller thing to get right than trying to spot every dangerous input.

**No `style` attribute, ever.** The CSP is `style-src 'self'`, so an editor that wrote
`style="text-align:center"` would look correct in the admin and do nothing on the public page.
Alignment is a class from a fixed list — which is why `class` is allow-listed *by value*, not merely
by name.

`h()` is what you call on every value you print. Always. Do not assume something was cleaned earlier.

### `store.php`

`store_read()` · `store_write()` · `store_edit()`

Reading and writing a JSON file.

`store_write()` is **atomic**: it writes a temp file in the same directory and renames it over the
target. A rename within a filesystem is atomic, so a visitor loading the page mid-save reads either
the old file or the new one, never half of one. It also keeps one generation of `.bak`.

`store_edit()` is read-modify-write **under a single exclusive lock**. Use it for anything that
counts.

> `store_read()` then `store_write()` is two steps with a gap. That is fine for a person saving a
> form and wrong for a counter: two failures landing together would each read 3, each write 4, and
> one would vanish. That is not a rounding error — it is the attacker's best move.

`store_read()` returns `null` for a missing file **and** for malformed JSON — the right shape for
site copy, where both mean "fall back to defaults" and the page still renders. Callers that must
tell them apart use **`store_state()`**, which answers `ok`, `missing`, `unreadable` or `corrupt`.

`auth_problem()` uses it to refuse rather than present a damaged account file as a fresh install,
and `store_write()` uses it to make sure a damaged file never becomes the `.bak` — the copy that
damage is recovered from. `tools/test_store.py` covers both.

### `contract.php`

**Shared — byte-identical in `tech4time-website-frontend` and `tech4time-website-backend`.**

`CONTRACT_VERSION` · `CONTRACT_DOCUMENTS` · `CONTRACT_BOOKKEEPING` · `careers_normalise()` ·
`contact_normalise()` · `contact_defaults()` · `contact_fingerprint()` · `contract_sanitise()` ·
`contract_next_revision()` · …

**The shape of a document, and nothing else.** Field lists, the defaults a missing key falls back
to, the normalising that turns whatever arrived into that shape, and the queries that read it. Both
halves must agree on all of it or they are not describing the same job post.

What is deliberately *not* here:

| | goes to | because |
|---|---|---|
| validation with readable messages, the form model, the flag picker | backend | the frontend has no form to validate |
| `JobPosting` / `ContactPage` schema, flag `<picture>`, `tel:` hrefs | frontend | the backend does not render the public page |

The line is: **if the two sides disagreeing about it would corrupt a document, it is here.** If
disagreeing would only make one side's own page look wrong, it is not.

`contact_defaults()` and `contact_office_defaults()` are **the definition of the shape** —
`check_content_model.py` reads the field list out of those functions rather than out of
`content/contact.json`, because the file is one instance of the shape and an optional field that
happens to be absent from it is still a field.

`CONTRACT_BOOKKEEPING` names the fields a document keeps about *itself* — `updated`, `revision`,
`footer_synced`. Nothing edits them and nothing renders them, so both directions of
`check_content_model.py` and the round trip in `test_careers_admin.py` exempt them, and all three
read the one list. They did not, once: `revision` was added, the careers test treated it as a
site-wide setting, posted it on its own, and blanked `cv_form_url` doing so.

`contract_sanitise()` runs every rich field back through `html.php`, driven off
`CAREERS_RICH_FIELDS` / `CONTACT_RICH_FIELDS` rather than a list of its own — so a rich field added
to the contract is sanitised on receipt *by having been added*.

**Bump `CONTRACT_VERSION`** when a change would make a document written by one version render
wrongly under the other: a field renamed, a field's meaning changed, a list that becomes a scalar.
Not for a new optional field older code simply ignores.

### `careers.php`

`careers_load()` · `careers_save()` · `careers_validate()` *(backend)* · `careers_job_posting()` *(frontend)* · …

What **this side** does with the shape `contract.php` defines. `careers_sanitise_html()` and
`careers_safe_href()` are one-line aliases kept from before that code moved to `html.php`, so the
move changed no caller.

`careers_save()` mints the next `revision` itself rather than trusting a caller to. On the backend it
also publishes — a save that wrote the record and forgot to send it is a save nobody would
investigate.

### `contact.php`

`contact_load()` · `contact_save()` · `contact_validate()` · `contact_flags()` *(backend)* ·
`contact_page_schema()` · `contact_flag_picture()` · `contact_reach_href()` *(frontend)* · …

The same division for the contact page.

The footer-drift banner is powered by `contact_footer_in_step()` in `contract.php`, comparing the
details now held against `footer_synced` — which after the split is **what the frontend reported in
the last publish response**, not something this side computed. See
[`footer-fingerprint.php`](#footer-fingerprintphp).

### `company.php`

`company_load()` · `company_save()` · `company_validate()`

The same division again, for the company profile — the largest of the three shapes, and the only
one carrying artwork. Six repeatable lists live in `contract.php`: milestones, figures, clients,
photographs, technology and principles, each row with a `status` so it can be **hidden without
being deleted**.

Two rules in `company_validate()` are worth knowing before changing either half:

**A figure must start with a digit.** `animations.js` counts it up by reading the number off the
front, so `"Over 100"` silently never animates. The editor says so rather than letting somebody
find out.

**A picture may only point inside this site** — `company_safe_image_path()` in `contract.php`,
against `COMPANY_IMAGE_ROOTS`. The editor checks it because a hidden input is a text field with the
label taken off; the frontend checks it again on receipt, because a signature proves where a
document came from and not what is in it.

### `home.php`

`home_load()` · `home_save()` · `home_validate()` · `home_validate_icon()` ·
`home_validate_link_card()` · `home_validate_image()`

The same division once more, for the home page — the widest shape here, with **six** repeatable
lists in `contract.php`: the hero's badges and tags, the terminal's lines, the technical domains,
the service cards and the Get to Know Us cards. Every row carries a `status` so it can be **hidden
without being deleted**.

Three rules in `home_validate()` are worth knowing before changing either half:

**The highlighted phrase must appear in the hero title.** `hero.accent` is the phrase the renderer
draws in the accent colour, matched literally against `hero.title`. A phrase that is not in the
title highlights nothing, and nothing about the rendered page would say so — which is exactly the
kind of mistake nobody notices for months. It is refused, and the message quotes both halves.

**The dark half of a card's picture is optional; the light half is not.** A card with one picture
shows it in both colour modes, which is every card today. Whichever halves are present are checked
the same way, because a picture with no dimensions shifts the page as it loads whichever mode it
belongs to. The message says `(dark mode)` so two pictures on one card can be told apart.

**A picture may only point inside this site** — `contract_safe_image_path()`, against
`CONTRACT_IMAGE_ROOTS`. The editor checks it because a hidden input is a text field with the label
taken off; the frontend checks it again on receipt, because a signature proves where a document came
from and not what is in it. On this page that matters most: a third-party `src` here is in every
visitor's first page load.

### `services.php`

`services_load()` · `services_save()` · `services_edit()` · `services_validate()` ·
`services_validate_list()` · `services_validate_one()`

**One document, seven pages.** `content/services.json` holds the services index *and* the six
service pages under it. That is forced rather than chosen: a seventh service has to be addable from
the editor, and `CONTRACT_DOCUMENTS` is a constant in code — so a service cannot be its own document
and has to be a row in a list. See the note over `services_defaults()` in `contract.php`.

**`services_edit()` is the only locked read-modify-write in the admin, and it has to be.** Every
other editor rebuilds its whole document from the form, so two people saving at once means the
later save wins entirely — bad, but not silently destructive of anything the form did not contain.
This editor is split across two screens, because PHP's `max_input_vars` defaults to 1000 and the
seven screens render 308, 371, 333, 279, 276, 243 and 175 inputs — each fits, all of them together
would be about 1985. So a form carries **one** service and the other five are merged back from the
file — and a read-modify-write without a lock loses one of two concurrent
edits to *different* services, which is the normal case for this screen rather than an edge one.
It goes through `store_edit()`; the publish happens outside the lock, because holding an exclusive
lock across an HTTP request to another host would make every other editor wait on that host.

**The merge is by id, never by position.** A row's index in the form is its index in the document
as it was *read*; between then and the lock, somebody on the other screen may have added, removed or
reordered a service. Matched by id, a reorder is harmless and a delete is a no-op — and a service
deleted while its form was open is put back rather than dropping what was typed, because undoing an
unwanted revival is one press and retyping a page is not.

**Validation is split, and the split is the point.** `services_validate_list()` judges only which
services there are, what they are called and where they live — that is all the index screen shows,
and a service added a moment ago and not yet filled in must not make the index unsaveable.
`services_validate_one()` judges a whole page and runs on that page's own screen. A new service
arrives **hidden**, so an unfinished page is never live either way.

**A list needs a heading before it can be shown.** A solution card's ticked list and tag list are
headed once for the whole page, not per card — every card on the cloud page says *"What it
includes"*. So a card that lists things on a page with no heading set for that list would render
loose text with no explanation, and it is refused with the two ways out named.

### `certifications.php`

`certifications_load()` · `certifications_save()` · `certifications_validate()`

**A list inside a list.** `content/certifications.json` holds four role groups; each group holds its
own role names and its own certifications. All three levels add, remove, reorder and hide
independently, and a group added in the editor arrives hidden so a half-filled category is never
live.

**It fits in one form, and that was measured.** `admin_form_truncated()` exists because
`max_input_vars` defaults to 1000 and PHP drops the tail of a larger POST in silence. This page
posts about 240 inputs — 54 certifications at three each is most of it — so unlike the services
editor it is not split by row. Roughly 250 more certifications would be needed to reach the limit.

**Validation counts the description AFTER the tokens are filled in.** `{certifications}` is fifteen
characters that publish as two, so measuring the stored string would refuse a description that fits
and accept one that does not.

**A group's anchor is minted once and then frozen.** It comes from the first role name, because a
group has no title of its own — it *is* its roles. Renaming a role afterwards does not move it: a
link into the page is a promise. The one exception is a group still carrying the placeholder id it
was created with, which has never been a real address and is still up for grabs.

### `upload.php`

**Backend only.** The frontend has no upload form and must never gain one.

`upload_problem()` · `upload_accept()` · `upload_store()` · `upload_held()` ·
`upload_unused()` · `upload_delete()`

The only code in either repository that takes a file from somebody's computer and puts it on a web
server. **The rule it works to is that nothing the browser sent is ever written.** An upload is
read and then *replaced*: decoded by GD and re-encoded from the pixel data, so what lands on disk
is that library's output.

That one step is what removes EXIF — including the coordinates a phone puts in a photograph —
anything appended after the image data, and a file that is a valid JPEG *and* a valid PHP script.
A validator could do none of it: it can only decide it did not find what it knew to look for.

JPEG, PNG and WebP, decided from the file's own header. **No SVG:** an SVG is a document, it can
carry script, and re-encoding does not make it not a document. Full reasoning in
[0019](../../90-decisions/0019-uploaded-images-travel-their-own-channel.md); the proof is
[`test_upload.py`](../../40-reference/tools.md).

`upload_unused()` never deletes anything on its own. A reference count taken from a document
somebody is halfway through editing is not a fact.

### `publish.php`

**Shared — byte-identical in both repositories.**

`publish_problem()` · `publish_fingerprint()` · `publish_envelope()` · `publish_body()` ·
`publish_sign()` · `publish_verify()` · `publish_check_envelope()` · `publish_reason()`

The format content travels in, and only the format — sending is
[`publish_client.php`](#publish_clientphp), receiving is the frontend's `tech4time-website-frontend/api/publish.php`. Full
description: [the publish API](publish-api.md).

The four checks are not interchangeable, and it is worth knowing which does what:

| check | answers |
|---|---|
| the signature | this came from something holding the key — **not** that it is safe |
| the timestamp | it was sent in the last five minutes |
| the revision | it is newer than what is here — this is what makes a replay a no-op |
| `contract_version` | this side implements the shape it is written in |

The key is `publish.key` in the private store: 32 random bytes, **the same bytes on both hosts**,
never derived from `secret.key` (the two stores have different master keys, so anything derived
would differ by construction). It is never created on demand — see
[`make_publish_key.py`](../../40-reference/tools.md).

### `publish_client.php`

**Backend only.** `publish_push()` · `publish_endpoint()`

Sends one document and returns what the editor should show. Never throws for a network problem: an
unreachable site is a thing to report in the editor, not a stack trace over a form somebody has just
filled in.

The certificate is verified and there is no option to turn that off; redirects are not followed,
because a redirect on this route would post a signed document wherever it pointed.

`$T4T_PUBLISH_URL` overrides the endpoint — how `test_publish.py` points it at a local server.

### `footer-fingerprint.php`

**Frontend only, and generated** by `tech4time-website-frontend/tools/sync_site_contact.py`. One constant,
`FOOTER_FINGERPRINT`.

The footer's contact details are literal markup in all sixteen pages, because the project forbids
runtime partials. So the moment somebody edits an address in the admin, the contact page is right
and the footers are behind — until the pages are rebuilt and deployed.

This records the fingerprint the footers were last rebuilt **for**. It used to be stamped into
`contact.json`, which stopped being possible when the backend took ownership of that file: the
frontend's copy is a replica, and the next publish overwrites anything written into it. So the
frontend keeps its own record, reports it in every publish response, and the backend compares. The
side that knows what its own footers say is the side that answers.

---

## The sign-in

Full design: [authentication.md](authentication.md).

### `private.php`

`t4t_private_dir()` · `t4t_private_path()` · `t4t_master_key()` · `t4t_key()` · `t4t_assert_outside_document_root()`

Where the secrets are, and where every key comes from.

`t4t_private_dir()` resolves the store, **refuses if it is inside the document root**, creates it
0700, and caches the result. The containment check runs *before* `mkdir` — a safety check that
leaves a new folder in the web root on its way out is doing the opposite of its job — and again on
the resolved path, because `realpath()` follows symlinks.

`t4t_master_key()` creates `secret.key` with `fopen(…, 'x')`, which fails if the file exists. That
makes "create only if absent" one atomic step, and the creation path is written to **lose** a race
rather than win one: regenerating the key would invalidate every stored password at a stroke.

`t4t_key($purpose)` derives a per-purpose key by HMAC. The key that peppers passwords is not the key
that hashes reset codes, and neither is the key that will sign a publish request — so a weakness in
how one is used cannot be carried into another.

### `totp.php`

`totp_secret()` · `totp_code()` · `totp_verify()` · `totp_uri()` · `totp_qr_svg()` · `totp_format()` · base32 both ways

RFC 6238, about ninety lines: base32, HMAC-SHA1 dynamic truncation, a 30-second step, 6 digits, and
one step of drift either side for a phone clock that is slightly out.

Hand-written for the same reason `html.php` is — there is nothing to install on this host and no
build step to install it with. **It is checked against all six test vectors published in the RFC**,
including the one past 2^32 that catches a 32-bit counter. That is the only reason to trust an
implementation like this one.

### `qr.php`

`qr_matrix()` · `qr_svg()` · and the pieces underneath, which the test drives directly

A QR encoder: byte mode, error-correction level M, versions 1 to 10 — up to 213 bytes, where an
`otpauth://` URI is about 130. Longer than that and it throws, rather than emitting a code that
looks right and scans as nothing.

It exists because pairing an authenticator meant typing a 32-character key into a phone. `totp.php`
carried a note for months saying a QR code was "several hundred lines for a picture of a string
every app will also accept typed in". It is still several hundred lines; scanning is simply how
people pair a phone, and the typed key is the fallback.

Written here for the same reason `totp.php` and `html.php` are: nothing to install, and no build
step to install it with.

**Output is SVG with `fill="currentColor"` and no `<style>` block** — the CSP is `style-src 'self'`,
and the transformed markup would be refused otherwise. Following `currentColor` also means dark mode
needs no rule of its own. The quiet zone is inside the `viewBox`, so the white margin a scanner
needs travels with the image instead of depending on the layout.

**It is checked against libqrencode, module for module, at a matched mask** —
`tools/test_qr.py`. A QR code that is subtly wrong still looks exactly like a QR code, so the only
useful test is a second implementation disagreeing. The two do disagree about *which* mask to
choose, which is allowed: the penalty rule for the 1:1:3:1:1 finder-lookalike is implemented here as
ISO 18004 describes it and in libqrencode as libqrencode does. Every mask yields a valid symbol.

### `auth.php`

The largest file here. Accounts, hashing, sessions, the audit log, and the setup token.

```
accounts    auth_accounts  auth_find  auth_put  auth_defaults  auth_has_accounts
passwords   auth_pepper  auth_password_hash/verify/needs_rehash/dummy/problem
recovery    auth_recovery_make/hash/use
sessions    auth_boot  auth_session_user  auth_login  auth_logout
            auth_invalidate_sessions  auth_sweep_sessions  auth_end_session
requests    auth_csrf  auth_check_csrf  auth_fingerprint
            auth_is_https  auth_is_local  auth_is_loopback
the log     auth_log  auth_recent
setup       auth_setup_token  auth_setup_token_check  auth_setup_done
gates       auth_problem  auth_attempt  auth_second_factor
```

> **`auth_second_factor()` takes the account by reference.** It spends a recovery code and advances
> the TOTP counter on the caller's copy. It took it by value once, and `auth_login()` wrote its own
> stale copy over the top one line later — silently restoring the spent code and the old counter.
> Recovery codes worked forever and a captured code could be replayed. If you refactor here, keep
> the reference.

### `throttle.php`

`throttle_ip()` · `throttle_key()` · `throttle_fail()` · `throttle_retry_after()` · `throttle_quota()` · `throttle_clear()`

Counting attempts, so guessing costs something. Five failures are free, then each waits longer than
the last, capped at `THROTTLE_MAX_BLOCK` (one hour).

`throttle_ip()` reads `REMOTE_ADDR` and **never** `X-Forwarded-For`, which a stranger sets.
`throttle_key()` HMACs the identifier, so usernames never land on disk in the counter file.

### `reset.php`

`reset_begin()` · `reset_verify()` · `reset_finish()` · `reset_forget()` · `reset_tries_left()`

The emailed one-time code: ten minutes, five guesses, single use, and bound to the browser that
asked for it. Rationed three times an hour per account, five per address, twenty overall — the last
because cPanel caps outbound mail per hour and somebody hammering the page could use the allowance
up, stopping the genuine reset from being delivered.

`reset_finish()` takes the **hash**, not the password. `public/reset.php` asks for the new password
at one step and the authenticator at the next, so the choice has to survive the gap — it survives as
argon2id output, computed the moment it was accepted. Anything that calls this has already hashed.
[authentication.md](authentication.md#forgetting-the-password)

### `mailer.php`

`mail_send()` · `mail_problem()` · `mail_header_safe()`

The one place mail leaves this site, so the envelope sender is set in one place. It sends with
`-f no-reply@tech4time.bd` and retries once without it, because some hosts refuse the flag outright.

> The `-f` envelope sender is what SPF and DMARC are checked against. The `From:` header is not.

---

## The admin shell

### `admin.php`

`admin_start_session()` · `admin_require_auth()` · `admin_section()` · `admin_head()` / `admin_foot()` · `admin_shell_head()` / `admin_shell_foot()` · `admin_icons()` · `admin_asset()` · `admin_outline()` · `admin_initials()` · `admin_card_head()` · `admin_status_field()` · `admin_image_fields()` · `admin_uploaded_files()` · `admin_send_picture()` · `admin_csrf()`

The section registry, the icon rail, the page furniture, and the gate.

`ADMIN_SECTIONS` is the registry the rail draws itself from — adding an editable page is a row here
plus a file beside the others. `ADMIN_PAGE_SECTIONS` names the subset that edits a page of the
website, so anything counting "the pages you can edit" asks here rather than filtering the registry
by hand in three places.

`admin_shell_*` are the furniture for the pages that have **no** session yet — login, forgot, reset,
setup. They exist because `admin_head()` fatals on a section that is not in the registry, and those
pages are not sections.

`admin_asset()` is how every stylesheet, script and image URL in both shells is written. It appends
the file's own modification time. `public/.htaccess` serves assets with
`max-age=31536000, immutable` and there is no build step to change a filename, so without a version
in the URL a deploy leaves every returning browser on the previous stylesheet — and `immutable`
means an ordinary reload will not fix it. That has happened. `check_secrets.py` fails on an asset URL written
any other way.

`contract_path()` gives a document's record path — `content/<name>.json`, the same rule in both
repositories. `lib/careers.php`, `lib/contact.php`, `lib/company.php` and `lib/about.php` each still write their own
constant; this exists for the things that have to work over *all* the documents without knowing
their names: the deploy's seed, and the editor's warning when a host has no record for the page
being edited.

`admin_card_head()` draws the head of one repeatable row — its number, a preview of its content, a
Shown/Hidden pill and the move/remove controls — for **every** editor. It is here rather than in
each `sections/*.php` because it was in each of them, and they were not the same: contact put the
controls inside the `.admin-card__head` flex line, where `margin-inline-start: auto` pushes them
right; company emitted them as a bare child of `.admin-card`, where that rule has nothing to push
against. Same classes, same stylesheet, different page.

`admin_status_field()`, `admin_image_fields()`, `admin_uploaded_files()` and
`admin_send_picture()` are the rest of what a repeatable row needs: the Shown/Hidden control, the
thumbnail and file input, `$_FILES` put back the right way round, and the push of a stored picture
to the live site. They were the company editor's, written for its six lists; the contact page's
offices needed all four the day they gained a flag anybody can upload. **Both editors call these —
do not copy them into a section.**

`admin_initials()` turns the signed-in name into the one or two letters the avatar at the foot of
the rail draws — "Syed Golam Abid" becomes SG. It uses PCRE's `\X` rather than `mb_substr()`: `\X`
is part of the regex engine and takes a whole grapheme, so an accented letter or a Bengali cluster
survives instead of arriving as the first byte of one. `lib/html.php` avoids mbstring for the same
reason.

`admin_outline()` holds the current section's table of contents between the two halves of the shell:
`admin_head()` is given it, `admin_foot()` writes it as the "On this page" column to the right of
the form. There is nothing between them to pass it through — the section's own markup is what sits
in the middle.

[adding-an-editor.md](adding-an-editor.md)

---

## Adding a library

Rare. Most things belong in an existing file.

If you do: a header comment saying **what it owns and why it exists**, `declare(strict_types=1)`,
functions prefixed with the file's concern, no global state beyond a `static` cache, and no output.
Then add it to the table at the top of this page — `check_docs.py` fails until you do.
