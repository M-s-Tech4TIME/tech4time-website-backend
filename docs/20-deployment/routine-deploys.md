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

> **The host's `content/` is the real data. Yours is test data.**

Job posts and contact details are written by people using this editor. They are not in your working
copy. A deploy that includes `content/` destroys them, and the loss is silent — the admin keeps
working, showing older content, until somebody notices their job post is gone.

That is doubly true here: this repository holds the **system of record**. The public site's copy is
a replica it can be sent again; this one cannot be recovered from anywhere but a backup.

---

## What to upload, and what never to

`tools/build_deploy_set.py` is the authority — the lists below are what it holds, written out.

```
UPLOAD                            NEVER UPLOAD
  public/    ← the document root    content/          ← live data, system of record
  lib/                              tools/            ← scripts, incl. admin-cli.php
  sections/                         docs/
                                    .git/
                                    *.md   *.py   *.key
                                    admins.json   setup-token.txt
```

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

```bash
python3 tools/build_deploy_set.py --check     # what would go, and what must not
python3 tools/build_deploy_set.py --out _deploy

# ALWAYS dry-run first, and read the output for "deleting content/"
rsync -avz --delete --dry-run _deploy/site/ user@tech4time.bd:~/admin.tech4time.bd/

rsync -avz --delete _deploy/site/ user@tech4time.bd:~/admin.tech4time.bd/
rsync -av --ignore-existing _deploy/seed/ user@tech4time.bd:~/admin.tech4time.bd/content/
```

The SSH host is still `tech4time.bd` — both halves share one cPanel account. **The directory is what
differs, and it is the whole safety property.** `~/public_html/` is the public site.

That second `rsync` is the content rule: `--ignore-existing` creates what is absent and overwrites
nothing, so a job post on the host always wins.

These carry **none** of the pipeline's safeguards: no gate, and the protect list is whatever you
remember to type. Then run the verification by hand:

```bash
python3 tools/verify_live.py https://admin.tech4time.bd
```
