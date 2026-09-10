# 0023 — The header, footer and dock are emitted once, from a document anybody can edit

**Status:** accepted · **Applies to:** both

## Decision

The header, the footer and the small-screen dock are rendered on the request by
`tech4time-website-frontend/lib/body.php` from `tech4time-website-frontend/content/chrome.json`, the
way the `<head>` has been rendered by `tech4time-website-frontend/lib/head.php` since ADR 0020. They
are no longer literal markup in seventeen page files, and
`tech4time-website-frontend/tools/propagate_shared.py` no longer copies them.

Four rules shape what that document holds.

**A link points at a route, never at a URL.** Every destination is a key — `about`, `services`,
`service:cybersecurity` — resolved through `chrome_targets()` in `lib/contract.php`. `SEO_ROUTES`
already says routes are code and cannot be added, renamed or removed from the editor
([ADR 0020](0020-page-metadata-is-content.md)); this follows from it. The nav is the one component
on every page of the site, and a nav that can point anywhere can point at a 404. A label may be typed;
leaving it empty means "whatever that page calls itself", which is `seo_route_name()`.

**Two columns of the footer store nothing.** The services list is read from
`tech4time-website-frontend/content/services.json` at render time, so a seventh service appears by
itself and a hidden one goes. The social links are read from the SEO document's `sameas` rows, so a
profile URL is changed in one place and the footer can never disagree with the Organization graph.

**The footer's contact rows are its own, and are deliberately not synced.** The contact page holds
everything, in full; the footer holds the part worth putting in a footer, in whatever order and
wording suits it, with rows that can be hidden without hiding anything on the contact page. What
keeps the two honest is a **notice** in the editor, never a refusal. This is the same bargain the
privacy policy struck for the same reason — see "THE POLICY STATES FACTS ANOTHER DOCUMENT ALREADY
MANAGES" in `lib/contract.php`.

**The structure is code; the contents are content.** Four footer columns, four dock keys, one nav.
Their headings and their rows are editable; their number and order are not, because a footer that
can be given a fifth column is a footer that can be broken at a width nobody tested.

## Context

The `<head>` stopped being pasted into seventeen pages in September. The chrome did not. It was 74 +
136 + 191 template lines per page — about **6,800 lines of duplication** — kept in step by
`tech4time-website-frontend/tools/propagate_shared.py` and policed by
`tech4time-website-frontend/tools/check_shared_markup.py`. Nothing in it could be changed without a
developer and a deploy: not a nav link, not the tagline, not a phone number, not a social URL, not
the copyright name.

That is not a theoretical cost. It had produced three live defects by the time it was replaced, all
found while planning this:

| Defect | Why it existed |
|---|---|
| The footer's service list said **"Human Resource Provision"**; `tech4time-website-frontend/content/services.json` said something else | Two copies, and nothing compared them |
| A service added in the editor **never appeared in the footer** | The footer could not read `tech4time-website-frontend/content/services.json` |
| The Brussels telephone numbers went stale for weeks | `sync_site_contact.py` had to be run by hand before a deploy |

A fourth was mechanical. `propagate_shared.py` re-marked every `<a>` whose href a page already
marked, so on `tech4time-website-frontend/index.php` the **logo link carried `aria-current="page"`**
beside the Home nav link. Nobody designed that; it fell out of a regex.

### The blocker, and how it was answered

`tech4time-website-frontend/tools/inject_icons.py` scans a page's **source** for
`<use href="#name">` and writes the matching `<symbol>`s into a block after `<body>`, because
Chromium and WebKit do not resolve `<use>` into another document. When the chrome left the source, those
references vanished with it: **eleven of the seventeen pages** would have received an empty block,
every icon they carry coming from the three shared blocks.

The repository already answered this exact problem twice, for content icons chosen at render time —
`services_sprite()` and `certifications_sprite()`, which wrote a second block marked
`content-sprite:start/end`. Those two were byte-identical, and a third copy was one too many, so the
one copy is `sprite_block()` in `tech4time-website-frontend/lib/sprite.php` and `chrome_sprite()`
hands it the chrome's set. `inject_icons.py` needed **no change at all**: it simply stops finding
those references, and on the eleven pages whose only icons were the chrome's it drops its block
entirely.

The rejected alternative was a `CHROME_ICONS` constant inlined on every page. It was written,
measured against the codebase and reverted: it costs symbols on all seventeen pages against a set
that is fifteen today, and constrains the picker for no gain.
`tech4time-website-frontend/lib/services.php` records the same trade being measured at **+7 to +10
KB gzipped per page**.

### The proof

Every page was rendered before the conversion and after it and compared, whitespace-normalised, with
the symbol sets compared as sets. Seventeen pages differed by exactly four things and nothing else:

1. `aria-current="page"` is no longer on the brand link on `tech4time-website-frontend/index.php` —
   the regex accident, fixed;
2. the footer's staffing service reads "Human Resource as a Service", which is what its own
   `<title>` and share title already said — the first defect above, fixed by construction;
3. the footer's address rows lost the `<br>` between them. Rows are separated by nothing now and a
   row's own lines by `<br>`, one rule for all four kinds. The old markup put a `<br>` between
   address rows and none between hours rows, an artefact of `sync_site_contact.py` having been
   written a section at a time; `.contact-item__label` is `display:block` and carries
   `margin-block-start` for exactly this, which
   `tech4time-website-frontend/assets/css/layout.css` says beside the rule;
4. the `<!--dock:start-->` and `<!--dock:end-->` marker comments are gone, with the tool that read
   them.

The symbol set on every page is identical: the chrome's fifteen moved from one block to the other.

## Consequences

- **`CLAUDE.md` rule 10 is retired.** "Never edit a header or footer in a page file. Edit
  `tools/templates/`, then `propagate_shared.py`" describes an arrangement that no longer exists.
- **Deleted:** `sync_site_contact.py` (376 lines), `footer-fingerprint.php`,
  `contact_fingerprint()`, `contact_footer_in_step()`, the `footer_synced` bookkeeping field, its
  place in the publish response and the second `store_write()` in the backend's `contact_save()`
  that recorded it, the editor's footer-drift banner, `check_shared_facts.py`'s `social_notice()`,
  and the three templates. `propagate_shared.py` and `check_shared_markup.py` are reduced to the
  hero circuit and the script tags.
- **`CONTRACT_VERSION` stays at 1.** `chrome` is a new document, which an older frontend refuses by
  name with `unknown-document` while continuing to render from its own seed; `footer_synced` was
  bookkeeping no page rendered. Bumping would guarantee a publish outage across the deploy window
  while protecting against nothing.
- **The frontend deploys first**, which is the reverse of the usual order. It renders from a seeded
  `tech4time-website-frontend/content/chrome.json` that is today's markup, so the site is unchanged
  the moment it lands. Were the backend to go first it could publish a `chrome` document to a
  frontend that does not know the name.
- **`tech4time-website-frontend/lib/body.php` is now a single point of failure for the whole site's
  navigation**, which the old arrangement was not. It is answered the way
  `tech4time-website-frontend/lib/head.php` answers it: `chrome_normalise()` fills from
  `chrome_defaults()`, so a host that has never received a publish — or one whose
  `tech4time-website-frontend/content/chrome.json` did not arrive — still renders a correct header,
  footer and dock rather than an empty page.
- **`check_shared_markup.py` gains an assertion of its own**:
  `tech4time-website-frontend/lib/body.php` still emits the six `data-` hooks the scripts bind to.
  `audit_pages.py`
  reads rendered output and would find a page with a header, a footer and every link resolving; the
  feature behind a missing hook would simply be gone.
