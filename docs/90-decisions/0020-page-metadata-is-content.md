# 0020 — A page's metadata is that page's content, and one screen edits all of it

**Status:** accepted · **Applies to:** both

## Decision

Every SEO value on the site is editable at `https://admin.tech4time.bd/?s=seo`, and **only** there.
Four things follow from that, and each was a choice with a live alternative.

**1. A page's own metadata stays in that page's own document.** The About page's title, search
description, share title, breadcrumb, crawl setting and sitemap row are in `content/about.json`, in
the `meta` band every document has, beside the About page's content — where they already
were. Only the editing moved.

**2. The site-wide half is a new document, `content/seo.json`.** The Organization / WebSite /
ProfessionalService graph, the default share card, the colours, `<html lang>`, `robots.txt`'s extra
rules, the search-console verification tokens, the web manifest — and the 404's own record,
because that page renders no content document and never will.

**3. The `<head>` is emitted once, by `tech4time-website-frontend/lib/head.php`, not pasted into every page.**

**4. Routes are code and content is content.** `SEO_ROUTES` in `lib/contract.php` names every page
that is a file. The editor cannot add, rename, remove or reorder one.

## Context

Titles, descriptions and share titles became editable page by page as each page came under
management. Nothing else in the head ever did: canonicals, `robots`, the share card, `og:type`,
`twitter:card`, `<html lang>`, `theme-color`, the whole Organization graph, the BreadcrumbList
labels, the sitemap's `changefreq` and `priority`, `robots.txt` and `site.webmanifest` were all
hand-written into files and needed a developer and a deploy.

And the head was pasted. Seventeen copies, 222–308 lines each — about 4,250 lines — with **no
propagation tool and no drift check over any of it**. `check_shared_markup.py` holds the header,
footer, dock and hero-circuit byte-identical; the head was never in that set, and the template it
came from — tools/templates/head.html, deleted by this decision — was read exactly once per page,
at birth, by `assemble_page.py`.

It drifted exactly as that arrangement guarantees. The Organization graph carried three office
addresses and four telephone numbers as literal JSON in sixteen of the seventeen heads; only
`tech4time-website-frontend/pages/contact/index.php` rendered them from `content/contact.json`. Editing an office in the admin
left sixteen pages advertising the old one — and a build script, `sync_site_contact.py`, existed to
paste the new values back in before a deploy, which is a workaround describing a defect. (That
script is deleted; see [ADR 0023](0023-the-header-and-footer-are-emitted-once.md).)

The measurement that settled it: the Belgium office had been given three telephone numbers in
`content/contact.json` and the contact page had been rendering them all along. The pasted graph on
the other sixteen pages listed **four** contact points and did not mention that office at all.

## Why a page's metadata did not move into `content/seo.json`

This was the decision the whole design turned on, and the tidier answer is the wrong one.

`content/` is **never synced by a deploy** and is seeded with `--ignore-existing` (ADR 0016). A
`seo.json` assembled from the repository's committed seeds would therefore carry **seed** titles
onto a host whose live documents hold titles edited since — and the reversion would be silent:
nothing breaks, no page 404s, the titles simply go back to what they were months ago. Closing that
would have meant a migration step on the host that must not be skipped, or a "dormant fallback"
copy, which is two sources for one value with a nicer name.

Leaving the values where they are removes the failure mode rather than managing it. There is no
migration, no adoption step, no deploy order that must be got right, and no moment at which a live
title has to survive a move between files. Every contract change was **additive**, and
`contract_normalise()` fills a missing key from `*_defaults()`, whose values are the ones the site
ships with.

It also settles the services. A service is a row of `content/services.json` and a seventh can be
added in the editor at any time. Keyed into `seo.json` its record would have to be found by slug —
and the slug is editable, so renaming one would silently orphan that page's title. Its record is
its row; there is nothing to orphan.

## The trap this creates, and how it is closed

Every `*_from_post()` starts from the stored document and then overwrites each band named in its
`*_TEXT_FIELDS` from `$_POST`. A form that has stopped **rendering** the meta fieldset while still
naming the band in that loop reads `$_POST['meta']['title']` as absent, `?? ''` supplies an empty
string, and **the page's title is blanked on every save** — silently, because empty is a valid
value and nothing throws.

`contract_page_bands()` is what stops it: the page editors iterate that, and
`sections/seo.php` iterates the `meta` band alone.
`tools/test_seo_admin.py` opens each page editor, saves it without
touching anything, and requires every value that was in the meta band to still be there. With
`contract_page_bands()` deliberately broken, that check reports four fields blanked on every
document — which is what it is for.

## Consequences

- **`sitemap.xml`, `robots.txt` and `site.webmanifest` are rendered**, by `tech4time-website-frontend/sitemap.php`,
  `tech4time-website-frontend/robots.php` and `tech4time-website-frontend/manifest.php`, reached by internal rewrites so no address changes. The static
  files are gone.
- **Sitemap membership is derived from `robots`.** One control, not two, so a page cannot be in the
  sitemap and asking not to be indexed at once.
- **`lastmod` is each document's publish stamp, or absent.** The ten dates typed into `tech4time-website-frontend/sitemap.php`
  were already months stale. A page that has never been published makes no claim, because today's
  date on every request is how a site teaches Google to stop believing its `lastmod` at all.
- **Canonicals are derived from the route and are never editable.** An editable canonical is the
  most destructive field in SEO. `audit_pages.py` asserts each page's canonical equals the
  directory the file sits in, which catches a copied `seo_head()` call — the one mistake an
  emitted head makes possible.
- **The crawl controls are otherwise unguarded**, behind a confirmation that names the consequence,
  and the SEO index carries a standing notice listing every page currently set to noindex. The one
  refusal is a `robots.txt` rule that would block the whole site: that is not a narrower rule to
  weigh up, it is the off switch, and `tech4time-website-frontend/robots.php` writes `Allow: /` above it either way.
- **`404.html` became `tech4time-website-frontend/404.php`**, so the site has no static page left. It has no canonical and no
  `og:url`: it is served at every address that does not exist and has none of its own.
- **`sync_site_contact.py` lost its JSON-LD half** and kept only the footer block, which was still
  literal markup on all sixteen pages. It has since lost that half too, and the file with it —
  [ADR 0023](0023-the-header-and-footer-are-emitted-once.md).
- **The cache-bust version query for the five shared stylesheets is bumped in one file**, not in
  sixteen pages.

## What was added while the head was being emitted rather than pasted

Four things the site had never carried, each measured as absent and each built from data that
already existed: a `WebPage` node per page (with `dateModified` from the document's publish stamp,
also emitted as `og:updated_time`), one `LocalBusiness` node per office, and two search-console
verification tags, which emit nothing until a token is entered.

## Alternatives considered

**Leave the head pasted and add a drift check.** That is what `check_shared_markup.py` does for the
header and footer, and it would have worked — but the head is the one shared block with genuinely
per-page lines in it, so the check would have had to know which lines may differ, and every
per-page line is exactly where a mistake hides.

**Make the canonical editable.** Rejected outright. A canonical pointing at another page tells
Google to index that one and drop this one, and nothing on this end would show it.

**Guard the crawl controls with a safe list.** Rejected in favour of the confirmation and the
standing notice: the operator has the whole control, and the editor's job is to say plainly what it
does, not to decide for them.
