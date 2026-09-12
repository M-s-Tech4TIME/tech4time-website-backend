# Repository map

**Applies to:** both

Every directory: what it holds, who owns it, and what must never happen to it.

**This is the backend.** The public site's sixteen pages, its assets and its renderers are in
`tech4time-website-frontend`, which has its own copy of this map.

---

## Top level

```
tech4time-website-backend/
├── public/                 ← THE DOCUMENT ROOT. Everything a browser may ask for
├── lib/                    ← outside it. Cannot be requested at all
├── sections/               ← outside it. Included by public/index.php
├── content/                ← outside it. The system of record
├── tools/                  build, audit and test scripts — NEVER DEPLOYED
├── docs/                   this documentation
├── deploy/seed/            what a brand-new install starts with
├── .gitattributes          line endings and diff behaviour
└── .gitignore              includes both private stores, as a backstop
```

**Three of those four are outside the document root, and that is the shape of
this repository.** `admin.tech4time.bd` points at `public/`, so `lib/`,
`sections/` and `content/` are not blocked by a rule — no URL maps to them.
[0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) explains
why, and `tools/verify_live.py` asserts it after every deploy by requiring a
**404** rather than a 403.

The deploy target is `/home/USER/admin.tech4time.bd/`, one level *outside* the document
root. The public site is a different repository at a different document root —
`tech4time-website-frontend`, at `/home/USER/public_html/`.

---

## `public/` — the document root

```
public/
├── .htaccess               HEADERS ONLY. Nothing here keeps anything secret
├── robots.txt              Disallow: / — correct here, unlike on the public site
├── index.php               the shell and the router; sections load through here
├── login.php               password, then six digits from an authenticator app
├── logout.php              POST only, with a token
├── forgot.php              asks for a reset code
├── reset.php               the emailed code, then a new password, then the app
├── setup.php               creates the first account; refuses ever after
└── assets/
    ├── css/                base.css theme.css layout.css components.css admin.css
    ├── js/                 theme-init.js theme-toggle.js admin-init.js admin-nav.js
    │                       editor.js admin-swap.js admin-forms.js
    │                       admin-outline.js admin-toast.js admin-dialog.js
    │                       admin-password.js
    ├── fonts/              Inter, self-hosted
    ├── icons/sprite.svg    read from disk by lib/admin.php and inlined
    └── images/             favicon, the logo, and the office flags
```

Six entry points, and everything else here is fetched by a browser. `public/.htaccess`
carries the CSP, HSTS and a **blanket** `X-Robots-Tag` — blanket because the
public site's rule is scoped to `^/admin(/|$)` and on this host the URI is `/`,
so a copied rule would match nothing and fail silently.
[0011](../90-decisions/0011-two-repositories.md) catalogued that before the
move; `tools/check_secrets.py` asserts the fix.

> **Do not leave cPanel's Directory Privacy on this directory.** It writes its
> own `public/.htaccess` here, and every deploy ships ours over it — which would
> remove the password silently. The application's own sign-in is the lock; see
> the note at the end of `public/.htaccess`.

`assets/` is only what the editor needs. The public site's fourteen other
pages, their CSS and their ninety-odd images are not here.

---

## `sections/` — the editors

```
sections/
├── overview.php        what can be edited, and plainly what cannot
├── careers.php         the job post editor      → content/careers.json
├── contact.php         the contact page editor  → content/contact.json
├── company.php         the company profile      → content/company.json
├── about.php           the about page editor    → content/about.json
├── home.php            the home page editor     → content/home.json
├── certifications.php  the certifications page  → content/certifications.json
├── branding.php        the branding page        → content/branding.json
├── privacy.php         the privacy policy       → content/privacy.json
├── services.php        the services editor, and each service page beneath it
│                                                → content/services.json
├── chrome.php          the header, footer and mobile dock every page carries
├── settings.php        the logo, the tab icon, the brand colours, the enquiry address
│                                                → content/chrome.json
├── seo.php             every page's search and share metadata, and the
│                       site-wide graph          → content/seo.json AND the
│                                                  meta band of nine others
└── account.php         password, second factor, recovery codes, the log
```

The rail draws itself from `ADMIN_RAIL_SECTIONS`, and each entry's label, icon
and description from `ADMIN_SECTIONS` — both in `lib/admin.php`:

| URL | Section | Edits |
|---|---|---|
| `/` | `overview` | nothing — one tile per rail row, derived from `ADMIN_RAIL_SECTIONS`, and a plain list of what a redeploy still owns |
| `/?s=careers` | `careers` | `content/careers.json`, then publishes |
| `/?s=contact` | `contact` | `content/contact.json`, then publishes |
| `/?s=company` | `company` | `content/company.json`, then publishes |
| `/?s=about` | `about` | `content/about.json`, then publishes |
| `/?s=home` | `home` | `content/home.json`, then publishes |
| `/?s=services` | `services` | `content/services.json` — the index and the list of services |
| `/?s=services&service=<slug>` | `services` | one service page, in the same document |
| `/?s=certifications` | `certifications` | `content/certifications.json`, then publishes |
| `/?s=branding` | `branding` | `content/branding.json`, then publishes |
| `/?s=privacy` | `privacy` | `content/privacy.json`, then publishes |
| `/?s=chrome` | `chrome` | nothing — it lists the three parts and links to each |
| `/?s=chrome&part=header` | `chrome` | the `header` band of `content/chrome.json`, then publishes |
| `/?s=chrome&part=footer` | `chrome` | the `footer` band, likewise |
| `/?s=chrome&part=dock` | `chrome` | the `dock` band, likewise |
| `/?s=seo` | `seo` | nothing — it lists every page and links to its screen |
| `/?s=seo&page=<key>` | `seo` | the `meta` band of that page's own document |
| `/?s=seo&page=service:<id>` | `seo` | the `meta` band of one service row |
| `/?s=seo&site=identity` | `seo` | `content/seo.json` — the Organization graph and the share card |
| `/?s=seo&site=crawl` | `seo` | `content/seo.json` — robots rules, the manifest, verification |
| `/?s=account` | `account` | your own password, second factor and recovery codes |

`ADMIN_PAGE_SECTIONS` names the subset that edits a page of the public website
— everything but `overview`, `chrome`, `seo` and `account` — so anything counting
"the pages you can edit" asks there rather than filtering the registry by hand.
`chrome` is not one of them for the same reason `seo` is not: it does not edit a
page, it edits something on **every** page.

**`account` is in the registry and not in the rail.** `ADMIN_RAIL_SECTIONS`
holds the rail's twelve rows and their order; `ADMIN_SECTIONS` holds every
section there is. They differ by exactly one entry, because the account is
about the person rather than about a page — it is reached from the avatar menu
at the foot of the rail. Deleting it from the registry instead would not hide
it: `admin_section()` returns `'overview'` for a name it does not know, so
`?s=account` would land silently on the Overview and the password screen would
become unreachable.

**`seo` edits ten documents and owns one.** `content/seo.json` holds only what
belongs to the whole site — the Organization graph, the default share card, the
crawl rules, the manifest, the 404's own record. Every *page's* title,
description, breadcrumb and crawl directive stay in that page's own document,
where they have always been; this screen is where they are edited, and the nine
page editors no longer render those fields at all. See
[ADR 0020](../90-decisions/0020-page-metadata-is-content.md).

**`services` is one section over two screens, and one document over seven
pages.** `content/services.json` holds the services index *and* all six service
pages, because a seventh service has to be addable from the editor and
`CONTRACT_DOCUMENTS` is a constant in code — so a service is a row in a list.
The screen is split because PHP's `max_input_vars` defaults to 1000 and
silently drops the tail of a larger POST. Measured, the seven screens render
308, 371, 333, 279, 276, 243 and 175 inputs: each fits with room to spare, and
all of them together would be about **1985** — twice the limit, losing the tail
with no error.

Each file refuses to run unless `T4T_ADMIN` is defined. That guard is
unnecessary while the document root is `public/`, and it is kept for exactly
that reason: a document root pointed one level too high is a configuration
mistake, and this is what stands between that mistake and a section file
running on its own. `check_secrets.py` asserts every one of them still has it.

---

## `lib/` — server-side PHP

Never reachable over HTTP: it is outside the document root.

| File | Owns |
|---|---|
| `html.php` **shared** | escaping, and the rich-text sanitiser |
| `contract.php` **shared** | the shape of every editable document, and `CONTRACT_VERSION` |
| `publish.php` **shared** | how a document is signed, and how a signature is checked |
| `svg.php` **shared** | the SVG sanitiser, which is a security boundary |
| `publish_client.php` | sending one, and where the public site is |
| `store.php` | reading and writing a JSON file atomically, with a lock |
| `careers.php` | validation, and the save that publishes |
| `contact.php` | the same, plus the flag picker |
| `company.php` | the same again, for six repeatable lists and their artwork |
| `about.php` `home.php` `services.php` | likewise, one editable page each — services holds the index and every detail page |
| `certifications.php` `branding.php` `privacy.php` | likewise |
| `chrome.php` | the header, footer and dock: what may be edited, and what the footer's rows are compared against |
| `settings.php` | the site's identity: the logo, the tab icon, the brand colours, and where enquiries go |
| `seo.php` | every page's metadata, the Organization graph, robots and the manifest |
| `upload.php` | a picture arriving: what is accepted, re-encoded and named |
| `qr.php` | the enrolment code an authenticator app scans |
| `private.php` | where the secrets are, and the keys derived from them |
| `auth.php` | accounts, hashing, sessions, the audit log |
| `totp.php` | RFC 6238, hand-written, checked against its published vectors |
| `reset.php` | the emailed one-time code |
| `throttle.php` | counting attempts, so guessing costs something |
| `mailer.php` | the one place mail leaves this site |
| `admin.php` | the section registry, the icon rail, the page furniture |

**Shared** means byte-identical with `tech4time-website-frontend` —
`tools/check_shared_lib.py` compares four things against a committed digest,
those three plus the icon sprite. The real guarantee is `CONTRACT_VERSION`,
checked at run time by the endpoint this half posts to.

Detail on each: [libraries.md](../10-development/server-side/libraries.md).

---

## `content/` — the system of record

```
content/
├── about.json           the about page
├── branding.json        the branding & advertisement page
├── careers.json         job posts and the CV form link
├── certifications.json  the resource certifications page
├── chrome.json          the header, footer and dock every page carries
├── company.json         the company profile page
├── contact.json         offices, phone numbers, the enquiry form's copy
├── home.json            the home page
├── privacy.json         the privacy policy
├── seo.json             every page's <head>, the sitemap, robots and the manifest
└── services.json        the services index and all seven detail pages
```

**This is the real data, and the public site holds a replica of it.** Every
save here writes the file first, then pushes a signed copy to
`tech4time.bd/api/publish.php`. A deploy that overwrote this directory would
destroy live job posts — so it is never synced, seeded once with
`--ignore-existing`, and named in the deploy's protect list.

> **Rule:** never upload `content/` to a server that already has one.
> [routine-deploys.md](../20-deployment/routine-deploys.md)

Field-by-field: [content-schemas.md](../40-reference/content-schemas.md), and
how it travels: [publish-api.md](../10-development/server-side/publish-api.md).

---

## `tools/` — never deployed

The editors' tests, the auditors, and the two tools that reach the host. Not reachable over HTTP for
the same reason `lib/` is not — this directory is outside the document root — and not uploaded at
all either.

Two files there are exceptions, uploaded by hand and then deleted:

- `tools/host-probe.php` — answers what can only be answered on the host
- `tools/admin-cli.php` — the break-glass path when every way into the admin is shut

Full list: [tools.md](../40-reference/tools.md).

---

## Not in the repository at all

### The private store

`/home/USER/t4t-private-admin/` on the host, `../t4t-private-admin` beside your clone locally.
Password hashes, the master key, authenticator secrets, sessions, counters, the audit log — and
`publish.key`.

Never committed, never deployed, never inside the document root, and never inside the **deploy
target** either: it is two levels up from `public/`, beside the repository rather than inside it,
because `rsync --delete` empties what is inside. `.gitignore` lists both store names as a backstop;
`lib/private.php` refuses to start if it finds itself in the web root.

The public site has its own, `t4t-private/`, holding three files and no name for an account.
Neither host can read the other's. [0017](../90-decisions/0017-two-private-stores.md)

[security-model.md](../40-reference/security-model.md) ·
[secrets-recovery.md](../30-operations/secrets-recovery.md) ·
[publish-api.md](../10-development/server-side/publish-api.md)

### Generated and ignored

| | |
|---|---|
| `content/*.json.bak` | one generation of backup, written on every save |
| `__pycache__/`, `*.pyc` | Python bytecode |
| `Tech4TIME-Static-Website-Plan_v3.md` | the original working brief, kept locally |
