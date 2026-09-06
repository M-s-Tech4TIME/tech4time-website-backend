# SEO and page metadata

**Applies to:** both

Everything a search engine is told about this site: where each value lives, who edits it, and what
is deliberately not editable. This is the doc that owns the subject — before it existed the
knowledge was spread across `shared-markup.md`, `adding-a-page.md`, `content-schemas.md` and
`tools.md`, and none of them owned it.

The decision behind the arrangement is
[ADR 0020](../90-decisions/0020-page-metadata-is-content.md).

---

## Where each value lives

There are two places, and the split is not arbitrary.

| | Lives in | Edited at |
|---|---|---|
| A page's title, search description, keywords, share title, breadcrumb, crawl setting, sitemap row, share-card override | **that page's own document** — `content/about.json`, `content/privacy.json`, … in the `meta` band every document has | `?s=seo&page=<key>` |
| A service page's, which is a row rather than a file | the row, in `content/services.json` | `?s=seo&page=service:<id>` |
| The 404's | `content/seo.json`, under `notfound` — it renders no content document | `?s=seo&page=notfound` |
| The Organization graph, the default share card, the colours, `<html lang>`, the locale, the card shape | `content/seo.json` | `?s=seo&site=identity` |
| `robots.txt`'s extra rules, the search-console tokens, the web manifest | `content/seo.json` | `?s=seo&site=crawl` |
| **Whether Google Analytics runs, and against which property** | `content/seo.json`, `crawl.analytics_id` | `?s=seo&site=crawl` |
| The offices' addresses and telephone numbers in the graph | `content/contact.json` | `?s=contact` |

**A page's metadata did not move.** It has always been in that page's document, beside that page's
content; what moved is the editing. The reason is in ADR 0020 and is worth reading before proposing
to tidy it into one file: `content/` is never synced by a deploy, so a single file assembled from
the committed seeds would silently revert titles edited on the host.

---

## What is not editable, and why

| | Why |
|---|---|
| **The canonical** | Derived from the page's own address. An editable canonical pointing at another page tells Google to index that one and drop this one, and nothing on this end would show it |
| **Routes** | `SEO_ROUTES` in `lib/contract.php`. Adding a page is a code change, as it always was, and its card then appears by itself — which is what makes it impossible to orphan a record or point one at a URL that does not resolve |
| **The CSP, the favicon list, the font preload, the stylesheet order** | Code. An editor able to break the Content Security Policy is a hazard, not a feature |
| **`Allow: /` and the `Sitemap:` line in `robots.txt`** | Written by `tech4time-website-frontend/robots.php`. Only extra `Disallow` paths are editable, and a rule that would block the whole site is refused |
| **The manifest's icon list** | It names files that must exist. A manifest pointing at an icon that is not there is an install prompt that fails silently on a stranger's phone |
| **The page-specific schemas** — `Service`, `OfferCatalog`, `JobPosting`, `ContactPage`, `AboutPage` | Already generated from the same documents the page bodies render from, so they cannot drift from the visible page |

---

## How a page's head is built

`tech4time-website-frontend/lib/head.php`, once, for all seventeen page files. A page hands in its
own address, its own `meta` band and its own publish stamp:

```php
require __DIR__ . '/../../lib/head.php';
require __DIR__ . '/../../lib/about.php';
$data = about_load();
?>
<html lang="<?= h(seo_lang()) ?>">
<head>
<?php seo_head('/pages/about/', $data['meta'], ['pages/about.css'], $data['updated']); ?>
<?php seo_jsonld('/pages/about/', $data['meta'], $data['updated']); ?>
```

The third argument is that page's own stylesheet — `tech4time-website-frontend/assets/css/pages/about.css` — which is why
the cache-bust version query for it is bumped here and nowhere else.

The **address** rather than a route key, because a service page's address is a row's slug and is in
no constant — and because the canonical is then the argument itself, which `audit_pages.py` checks
against the directory the file actually sits in. A copied `seo_head()` call with the argument left
behind is the one mistake an emitted head makes possible, and that is the check that catches it.

The **`meta` band** rather than a lookup, because the page has already loaded its document to
render its body. No second read, no second source.

### What comes out

| | From |
|---|---|
| `<title>`, `description`, `og:title`, `og:description`, `twitter:*` | the page's `meta` |
| `keywords` | the page's `meta.keywords` — **omitted entirely when empty**. Google has ignored this tag since 2009 and Bing treats a stuffed one as spam; it is emitted because it was asked for, and it is honest when it is short |
| `canonical`, `og:url` | the address argument — **omitted entirely for the 404** |
| `robots` | the page's `meta.robots`, expanded to the full directive by `seo_robots_directive()` |
| `og:image`, `og:image:alt` | the page's `meta.share` override, or the site's default card |
| `og:updated_time` | the document's `updated` stamp, or omitted when it has never been published |
| `og:site_name`, `og:locale`, `og:type`, `twitter:card`, `theme-color`, `<html lang>` | `content/seo.json`, `site` band |
| the search-console verification tags | `content/seo.json`, `crawl` band — omitted when empty |
| Google's tag loader and `tech4time-website-frontend/assets/js/analytics.js` | `content/seo.json`, `crawl.analytics_id` — **the only thing on this site that reaches another origin, and only while that field holds an id**. [ADR 0021](../90-decisions/0021-analytics-is-off-until-somebody-turns-it-on.md) |
| Organization / WebSite / ProfessionalService, and one `LocalBusiness` per office | `content/seo.json` plus `content/contact.json` |
| `BreadcrumbList` | prefix match over `SEO_ROUTES`, using each ancestor's `meta.breadcrumb` |
| `WebPage` | the address, the `meta`, the trail and the publish stamp |

Two pages get no `BreadcrumbList`, both deliberately: the home page, because a one-item trail says
nothing a crawler cannot read off the URL, and the 404, because it has no address to be a place in.

---

## The three files that are addresses rather than pages

`/sitemap.xml`, `/robots.txt` and `/site.webmanifest` are rendered by `tech4time-website-frontend/sitemap.php`, `tech4time-website-frontend/robots.php`
and `tech4time-website-frontend/manifest.php`, reached by **internal** rewrites in `.htaccess` so no address changes. Each
also has a `[R=301]` twin so the `.php` file is not a second address for the same thing. Locally,
`tools/dev-router.php` does the same three rewrites — `.htaccess` is never read by the dev server.

**Sitemap membership is derived from `robots`.** One control, not two, so a page cannot be listed
and asking not to be indexed at the same time — which is a warning raised against the whole file.
A hidden service leaves the sitemap for the same reason its page answers 404.

**`lastmod` is each document's `updated` stamp, or absent.** The ten dates that used to be typed
into `tech4time-website-frontend/sitemap.php` were already months stale. A page that has never been published makes no claim,
because today's date on every request is how a site teaches Google to stop believing its `lastmod`
at all.

---

## What is checked, and by what

| | |
|---|---|
| `tech4time-website-frontend/tools/audit_pages.py` | every page has a unique title and description within the model's length limits; **the canonical equals the directory the file sits in**; the 404 has none; the sitemap and `robots.txt` never name the editor |
| `tech4time-website-frontend/tools/test_sitemap.py` | all three files answer with the right `Content-Type`; the sitemap is well-formed and lists exactly the indexable routes; noindex and hidden services leave it; `robots.txt` cannot lose `Allow: /` |
| `tech4time-website-frontend/tools/test_publish.py` | the `seo` document round-trips, and so does every new `meta` field on every document |
| `tools/test_seo_admin.py` | the editor; and that **no other editor writes the `meta` band** |
| `tools/check_admin_a11y.py` | the five SEO screens, and that no rail label wraps or is cut off |

The length limits are `SEO_TITLE_MAX`, `SEO_DESC_MIN`, `SEO_DESC_MAX` and `SEO_DESC_IDEAL` in
`lib/contract.php`. `audit_pages.py` reads them from PHP rather than carrying its own copy — they
had drifted from the advice in `adding-a-page.md` before that.

---

## What no codebase can do

Position on a competitive search term is decided mostly off-page: links from other sites, brand
searches, the depth and freshness of what is published, and Google's read of expertise. The code
sets the ceiling; it does not set the position.

The one thing on this list that is a text box and is worth more than any further code change:
**paste a Google Search Console verification token into `?s=seo&site=crawl`, save, verify the
property and submit `https://tech4time.bd/sitemap.xml`.** Until that is done there is no way to see
which searches the site appears in, which pages Google refused to index, or whether its structured
data has an error — and nothing in this repository reports any of it.
