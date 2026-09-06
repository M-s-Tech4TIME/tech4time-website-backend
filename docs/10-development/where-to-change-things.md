# Where to change things

**Applies to:** both

"I want to change X — which file do I open?" This page answers that and nothing else. If you read
one page in this documentation, make it this one.

**This is the backend.** It is the editor at `admin.tech4time.bd`, and it owns the content. The
public website — its sixteen pages, its CSS, its JavaScript, its markup — is `tech4time-website-frontend`,
which has its own copy of this page for all of that.

**The general rule here:** if it is *content*, it is typed into this admin and you should not touch
a file. If it is *the editor's own behaviour*, it is in `sections/` or `lib/`. If it is *the shape
of a document*, it is `lib/contract.php` — **and the same file in the other repository, in the same
breath**.

---

## Content — do not edit files for these

| I want to change | Where |
|---|---|
| A job post — add, edit, remove, reorder | `/?s=careers` |
| The CV / application form link | `/?s=careers` |
| An office address, phone number, email | `/?s=contact` |
| The contact page's headings and copy | `/?s=contact` |
| The about page's sections, specialities and why-us cards | `/?s=about` |
| The home page's hero, badges, tags, terminal and cards | `/?s=home` |
| What the enquiry form says | `/?s=contact` |
| Anything the privacy policy says — a clause, the retention table, the effective date | `/?s=privacy` |

Saving writes `content/careers.json` or `content/contact.json` here — **the system of record** —
and then pushes a signed copy to the public site, which verifies it, re-sanitises it and writes its
replica. If that push fails the editor says so, in words, with a **Publish again** control.
[publish-api.md](server-side/publish-api.md)

> **The footer is the exception.** The contact details repeated in every page's footer are *markup*
> on the public site, not content, so this editor cannot reach them. After changing an address here,
> run `python3 tools/sync_site_contact.py` **in tech4time-website-frontend** and deploy it. The banner in
> this editor clears on the next save, when the public site reports its new fingerprint back.

---

## The editor's own look and behaviour

The public site's palette, layout, motion, JavaScript modules and page markup are all in
`tech4time-website-frontend`. What is here is the editor's own.

| I want to change | Where |
|---|---|
| A colour | `public/assets/css/theme.css` — tokens only, never a hex elsewhere |
| The editor's appearance | `public/assets/css/admin.css` |
| The icon rail's width or behaviour | `public/assets/css/admin.css`, `public/assets/js/admin-nav.js` |
| The account menu at the foot of the rail | `admin_head()` in `lib/admin.php`; it is a `<details>` |
| What "On this page" lists beside an editor | the `*_OUTLINE` constant in that `sections/*.php` |
| The Save button, and Discard beside it | the `$save` argument to `admin_head()` — **not** per section |
| Whether a form posts without navigating | `data-async` on the `<form>`; `public/assets/js/admin-forms.js` |
| Whether a link moves screens without reloading | write it as `?s=<section>`; `public/assets/js/admin-swap.js` |
| What survives a move between screens | anything outside `#admin-body` — the rail, and nothing else |
| The rich-text toolbar | `public/assets/js/editor.js` |
| Where "Saved …" appears | `public/assets/js/admin-toast.js` — the server still prints it into the page |
| What a confirmation question looks like | `public/assets/js/admin-dialog.js`; the `<dialog>` styles are in `admin.css` |
| The "On this page" column, and its marker | `admin_foot()` in `lib/admin.php`; `public/assets/js/admin-outline.js` |
| A band's heading and its "Add a …" button | `admin_band_head()` in `lib/admin.php` — **not** per section |
| The theme switch | `public/assets/js/theme-init.js`, `theme-toggle.js` |
| An icon the editor offers | `CONTACT_ICONS` in `lib/contract.php` — **and the other repository** |
| The icon artwork itself | `public/assets/icons/sprite.svg` — **shared; rebuild it in tech4time-website-frontend and copy it here** |

> `theme.css` is the same palette the public site uses, on purpose: the editor should read as the
> same product. `tools/check_contrast.py` checks it here as well, because the admin is where
> somebody works for an hour at a time.

---

## Server-side rules

| I want to change | Where |
|---|---|
| Who the contact form emails | `MAIL_TO` in `tech4time-website-frontend/contact-handler.php` |
| The envelope sender for the mail THIS half sends | `MAIL_FROM_ADDRESS` in `lib/mailer.php` — reset codes and change notices |
| Contact form validation | `tech4time-website-frontend/contact-handler.php` — the server side is the real one |
| The contact form's rate limit | `tech4time-website-frontend/contact-handler.php`, using `lib/throttle.php` |
| **The shape of a document** | `lib/contract.php` — **and the byte-identical copy in tech4time-website-frontend** |
| What this side does with that shape | `lib/careers.php`, `lib/contact.php` — validation and the save |
| What HTML is allowed in rich text | `lib/html.php` — **shared, both repositories** |
| How a document is signed | `lib/publish.php` — **shared, both repositories** |
| Where the public site is | `PUBLIC_SITE` in `lib/publish_client.php`, or `$T4T_PUBLIC_URL` |
| How JSON is read and written | `lib/store.php` |

> Changing a content shape means changing three things together — the model, the form and the
> renderer — and two of them are in different repositories now. `check_shared_lib.py` tells you
> when you have touched the shared file; `check_content_model.py` checks the model against the form
> **here** and against the renderer **there**, and each says which half it ran.
> [content-model.md](server-side/content-model.md) · [publish-api.md](server-side/publish-api.md)
>
> **Bump `CONTRACT_VERSION`** when a change would make a document written by one version render
> wrongly under the other. The public site refuses a version it does not implement, which is what
> stops a half-deployed change from corrupting the live page.

---

## The admin and the sign-in

| I want to change | Where |
|---|---|
| **Add an editable page to the admin** | `ADMIN_SECTIONS` in `lib/admin.php` + a file in `sections/` + a name in `CONTRACT_DOCUMENTS` **in both repositories** — [adding-an-editor.md](server-side/adding-an-editor.md) |
| The icon rail's rows, or their order | `ADMIN_RAIL_SECTIONS` in `lib/admin.php`. **Registry order is not rail order**, and `account` is registered but deliberately not in the rail — deleting it from the registry instead would make `?s=account` land on the Overview and put the password screen out of reach |
| A rail label | `ADMIN_SECTIONS`; new icons go in `ADMIN_ICONS`, same file. It must fit on one line — `check_admin_a11y.py` measures that, and `--rail-wide` in `admin.css` is what to widen |
| **A page's title, search description, share card, breadcrumb or crawl setting** | `sections/seo.php`. It writes the `meta` band of that page's own document; the page's own editor does not, and must not — [seo.md](../40-reference/seo.md) |
| The Organization graph, robots rules, the manifest, verification tokens | `sections/seo.php`, writing `content/seo.json` |
| How long a session lasts | `AUTH_IDLE` (1 hour idle) and `AUTH_ABSOLUTE` (12 hours) in `lib/auth.php` |
| How many failures before a lockout | `AUTH_ALLOW` in `lib/auth.php`; the backoff is in `lib/throttle.php` |
| The longest lockout | `THROTTLE_MAX_BLOCK` in `lib/throttle.php` |
| How many recovery codes | `AUTH_RECOVERY` in `lib/auth.php` |
| Password rules | `auth_password_problem()` in `lib/auth.php` — currently 12 characters minimum |
| Password hashing cost | `AUTH_ARGON` / `AUTH_BCRYPT` in `lib/auth.php` |
| How long a reset code lives | `RESET_TTL` in `lib/reset.php` (10 minutes) |
| How often a reset may be asked for | `RESET_PER_ACCOUNT` / `RESET_PER_IP` / `RESET_GLOBAL` in `lib/reset.php` |
| Authenticator drift tolerance | `TOTP_DRIFT` in `lib/totp.php` |
| Where the private store lives | `T4T_PRIVATE`, or the default in `lib/private.php` |

> **These constants are quoted in the documentation.** `tools/check_docs.py` fails if you change one
> without updating the prose. [authentication.md](server-side/authentication.md)

---

## Server configuration

| I want to change | Where |
|---|---|
| Security headers (CSP, HSTS, X-Frame-Options…) | `public/.htaccess` section 1 |
| Caching of the editor's assets | `public/.htaccess` section 1 |
| Forcing https, refusing dotted paths | `public/.htaccess` section 2 |
| Extensions that are never part of a site | `public/.htaccess` section 3 |
| **What is blocked over HTTP** | **Nothing needs to be.** `lib/`, `sections/` and `content/` are outside the document root — [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) |
| Keeping the WHOLE host out of search results | `public/.htaccess` section 1 — a blanket header, not a path match |
| Crawl rules | `public/robots.txt` — `Disallow: /`, which is correct here and would not be on the public site |
| The public site's sitemap and crawl rules | `tech4time-website-frontend` |

> `public/.htaccess` is not read by the local dev server. Changes there can only be verified on the
> host, with `python3 tools/verify_live.py https://admin.tech4time.bd`.
> [security-model.md](../40-reference/security-model.md)
>
> **Nothing in that file keeps anything secret.** Delete it and the admin becomes indexable and
> unhardened; it does not become readable. That is deliberate — see the header of the file itself.

---

## Deploying and operating

| I want to | Where |
|---|---|
| Deploy for the first time | [first-deploy.md](../20-deployment/first-deploy.md) |
| Push an update | [routine-deploys.md](../20-deployment/routine-deploys.md) |
| Stand this host up for the first time | [admin-activation.md](../20-deployment/admin-activation.md) |
| Re-send content the public site is missing | upload `tools/reconcile.py` to the host and run it — [tools.md](../40-reference/tools.md) |
| Recover a lost password or secret | [secrets-recovery.md](../30-operations/secrets-recovery.md) |
| Diagnose something broken | [troubleshooting.md](../30-operations/troubleshooting.md) |

---

## Things you should not change without reading first

| | Read this first |
|---|---|
| Anything in `lib/private.php` | [security-model.md](../40-reference/security-model.md) |
| Anything in `lib/auth.php` | [authentication.md](server-side/authentication.md) |
| The `public/.htaccess` blocking rules | [security-model.md](../40-reference/security-model.md) |
| `lib/html.php`, `lib/contract.php`, `lib/publish.php` | They are **byte-identical** with the other repository. [publish-api.md](server-side/publish-api.md) |
| `content/*.json` on a live server | It is the system of record. [routine-deploys.md](../20-deployment/routine-deploys.md) |
| Where the document root points | It must be `admin.tech4time.bd/public/`. [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) |
