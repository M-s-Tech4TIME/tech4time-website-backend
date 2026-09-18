# Routine deploys

**Applies to:** backend

Pushing an update to an editor that is already live, without destroying anything people have written.

> **This page used to describe a different server.** Before the split it was one document for one
> host, and it told you to `rsync --delete` into `~/public_html/` — which is now the **public
> site's** document root. Running the old procedure from this repository would have deleted
> tech4time.bd and put the admin in its place. The correct target is `~/admin.tech4time.bd/`, and
> everything below says so.

---

## The one rule

> **The host's `content/` and `public/uploads/` are the real data. Yours is test data.**

Job posts and contact details are written by people using this editor, and the pictures behind them
are uploaded through it. Neither is in your working copy. A deploy that includes `content/` destroys
the words; one that deletes `public/uploads/` destroys every picture ever accepted. Both losses are
silent — the admin keeps working, showing older content, until somebody notices their job post is
gone or a badge has turned into a broken image.

That is doubly true here: this repository holds the **system of record**. The public site's copy is
a replica it can be sent again; this one cannot be recovered from anywhere but a backup.

---

## What to upload, and what never to

`tools/build_deploy_set.py` is the authority — the lists below are what it holds, written out.

```
UPLOAD                            NEVER UPLOAD
  public/    ← the document root    content/          ← live data, system of record
  lib/                              public/uploads/   ← live pictures
  sections/                         tools/            ← scripts, incl. admin-cli.php
                                    docs/
                                    .git/   .claude/
                                    *.md   *.py   *.key
                                    admins.json   setup-token.txt
```

`public/uploads/` is inside a directory that *is* uploaded, which is what makes it the easy one to
get wrong: `build_deploy_set.py` leaves it out of the set, and the protect list is what stops
`--delete` removing the host's copy.

**`lib/` and `sections/` go up, but not inside `public/`.** They sit beside it, outside the document
root, which is the whole of how they are protected — [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md).
Uploading them *into* `public/` would publish the sign-in and the password hashes' reader.

> **Do not leave cPanel's Directory Privacy on `public/`.** It writes its own `.htaccess` there, and
> every deploy ships ours over it — removing the password silently, with nothing in the output to
> say so. [admin-activation.md](admin-activation.md)

---

## Before you upload

```bash
python3 tools/check_contrast.py
python3 tools/check_content_model.py
python3 tools/check_secrets.py
python3 tools/check_docs.py
python3 tools/check_shared_lib.py
python3 tools/build_deploy_set.py --check
```

And if the change touched anything server-side:

```bash
python3 tools/test_admin_auth.py
python3 tools/test_careers_admin.py
python3 tools/test_contact_admin.py
python3 tools/test_publish_client.py
```

The same list is in [testing.md](../10-development/testing.md), which is what
`.github/workflows/test.yml` runs. Reaching for these by hand is the fallback; the pipeline is the
procedure.

---

## After you upload

- [ ] The editor still signs in
- [ ] A job post saves, and the public site shows the change — that is the publish working
- [ ] `content/` still holds what it held — **live content intact**
- [ ] `lib/`, `sections/`, `content/` and `tools/` return **404**

**That last check is 404, not 403, and the difference is the finding.** A 403 would mean those
directories are *inside* the document root and merely blocked by a rule — one `.htaccess` mistake
away from being readable. A 404 means the web server cannot see them at all, which is what
[0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) is for. `tools/verify_live.py`
asserts exactly this and says so at length.

---

## Cache busting

Asset filenames are not content-hashed — there is no build step to hash them — and `public/.htaccess`
caches CSS, JS, fonts and images for a year, with `immutable`.

**`admin_asset()` in `lib/admin.php` handles this, and there is nothing to remember.** Every
`<link>`, `<script>` and `<img>` the shell writes carries the file's own modification time as a
query string, so changing a file changes its URL. `check_secrets.py` fails on an asset URL written
any other way.

This section used to say, correctly, that a changed `admin.css` would not reach returning editors on
its own, and asked whoever changed one to append a version by hand. That is not a procedure, it is a
thing to forget — and it was forgotten. A deploy shipped a new admin shell and the browsers went on
painting it with the previous stylesheet: the rail toggle, the account menu and the outline column
all arrived unstyled, and the page looked broken. `immutable` is the part that makes it stick: it
tells the browser not to revalidate on an ordinary reload, so pressing F5 changes nothing and only a
forced reload clears it.

**The public site still has this problem.** Its pages are static HTML with no PHP to stamp a version
into, so `tech4time.bd`'s own `assets/css/*.css` remain unversioned behind the same year-long cache.
Changing one there still needs a version appended by hand, or a lower `max-age` in that repository's
`.htaccess`.

---

## Changing the footer's contact details

**Not a deploy of anything, and not something you do here.** It is a save, on the **Header & Footer**
screen — `/?s=chrome`.

The public site's sixteen footers used to repeat the contact details as literal markup, so they went
stale the moment an address was edited in this editor and stayed stale until those pages were
rebuilt by a script in the other repository and deployed. The editor knew when that was needed
because the frontend returned a footer fingerprint in every publish response.

None of that exists now. The footer renders from `tech4time-website-frontend/content/chrome.json`,
and its contact rows are the footer's **own** — not a copy of the contact page's — so there is
nothing to push and nothing to fall behind. What keeps the two honest is a standing notice in the
editor that never blocks a save.
[ADR 0023](../90-decisions/0023-the-header-and-footer-are-emitted-once.md)

---

## Rolling back

There is no deploy history — the server holds one copy of the editor.

- **Code** is in git. Check out the previous commit and let the pipeline deploy it.
- **Content** has one generation of backup on the host: `content/careers.json.bak`, written on every
  save. Restore by renaming it.
- **Anything older** comes from the cPanel backup. [backups.md](../30-operations/backups.md)

Rolling code back does **not** roll content back, and it must not: the two are on separate clocks by
design.

---

## Doing it by hand, when the pipeline cannot

A push to `main` does all of this through `.github/workflows/deploy.yml`, with a protect list and a
gate that reads a dry run before anything is written — [ci-cd.md](ci-cd.md). Reach for the commands
below only when that is broken or unavailable.

**Type the protect list first, before either `rsync`.** `_deploy/site/` holds no `content/` and no
`public/uploads/`, so a bare `--delete` into the live directory removes both — and `content/` here is
the **system of record**, which is not a replica anybody can send again. These are the same rules
`deploy.yml` merges; they are written out because this path has no pipeline to carry them.

```bash
python3 tools/build_deploy_set.py --check     # what would go, and what must not
python3 tools/build_deploy_set.py --out _deploy

cat > /tmp/protect.rules <<'RULES'
P /content/
P /public/uploads/
P /.well-known/
P /public/.well-known/
P /cgi-bin/
P error_log
P /.user.ini
P /php.ini
P .htpasswd
RULES

# ALWAYS dry-run first. Read every "deleting" line, not just content/.
rsync -avz --delete --dry-run --filter='merge /tmp/protect.rules' \
  _deploy/site/ user@tech4time.bd:~/admin.tech4time.bd/

rsync -avz --delete --filter='merge /tmp/protect.rules' \
  _deploy/site/ user@tech4time.bd:~/admin.tech4time.bd/
rsync -av --ignore-existing _deploy/seed/ user@tech4time.bd:~/admin.tech4time.bd/content/
```

The SSH host is still `tech4time.bd` — both halves share one cPanel account. **The directory is what
differs, and it is the whole safety property.** `~/public_html/` is the public site.

`.well-known` is protected in both places on purpose: the subdomain's document root moved one level
in, to `public/`, and which of the two AutoSSL writes to next depends on when it last read the
configuration. Deleting a live ACME challenge fails a renewal months later with nothing to connect it
to. `public/.htaccess` is deliberately **not** protected — this repository ships it, and a copy
written by cPanel's Directory Privacy is a setting to switch off before deploying rather than a file
to keep.

That last `rsync` is the content rule: `--ignore-existing` creates what is absent and overwrites
nothing, so a job post on the host always wins.

These still carry **none** of the pipeline's other safeguards — there is no gate reading the dry run
back, so you are the gate. Then run the verification by hand:

```bash
python3 tools/verify_live.py https://admin.tech4time.bd
```
