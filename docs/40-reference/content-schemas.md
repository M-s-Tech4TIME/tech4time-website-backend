# Content schemas

**Applies to:** both

Three of the eleven JSON files the dynamic pages render from, field by field: the ones whose shape
this half needs spelled out. **The full set is in
`tech4time-website-frontend/docs/40-reference/content-schemas.md`**, which documents all eleven —
`CONTRACT_DOCUMENTS` is the list — including `company`, `home`, `services`, `certifications`,
`branding`, `privacy`, `seo` and `chrome`.

**The defaults functions are the definition of the shape**, not these files —
`careers_load()` in `lib/careers.php`, and `contact_defaults()` / `contact_office_defaults()` /
`contact_reach_defaults()` in `lib/contact.php`. A JSON file is one instance of a shape, and an
optional field that happens to be absent from it is still a field.

Changing a shape means changing three things together — the model, the form and the renderer.
[content-model.md](../10-development/server-side/content-model.md).

---

## `content/careers.json`

```json
{
  "cv_form_url": "https://forms.gle/…",
  "updated": "2026-08-23T02:10:00+00:00",
  "jobs": [ { … } ]
}
```

| Field | Type | |
|---|---|---|
| `cv_form_url` | string | one link for the whole page, for speculative applications |
| `updated` | string | ISO 8601, written on save. Bookkeeping — nothing renders it |
| `jobs` | array | job posts, **in display order** |

### A job

| Field | Type | |
|---|---|---|
| `id` | string | generated on creation, never typed |
| `title` | string | the role |
| `employment_type` | string | full-time, part-time, contract… |
| `work_arrangement` | string | on-site, hybrid, remote |
| `location` | string | |
| `salary` | string | free text — may be a range or blank |
| `posted` | string | date |
| `closes` | string | date; drives `careers_open_jobs()` |
| `status` | string | `shown` or hidden |
| `apply_url` | string | the role's own application form — applications never post to this site |
| `about` | rich text | the description |
| `responsibilities` | rich text | |
| `requirements` | rich text | |
| `must_have` | rich text | |
| `certifications` | rich text | |
| `offers` | rich text | what the company offers |

Rich-text fields go through `rt_sanitise_html()` on save. `careers_job_posting()` emits `JobPosting`
structured data from these.

---

## `content/contact.json`

```json
{
  "updated": "…",
  "meta":    { … },
  "hero":    { "title": "…", "subtitle": "…" },
  "form":    { "title": "…", "lead": "…", "subject_hint": "…", "note": "…",
               "service_types": [] },
  "reach":   { "status": "shown", "title": "…", "items": [] },
  "offices": { "status": "shown", "eyebrow": "…", "title": "…", "lead": "…",
               "items": [] }
}
```

| Field | |
|---|---|
| `updated` | ISO 8601, written on save. Bookkeeping |
| `meta` | everything the `<head>` says about this page — see [The `meta` band](#the-meta-band-on-every-document) |
| `hero` | the page's heading and subheading |
| `form` | the enquiry form's copy, and `service_types` — the subject options offered |
| `reach` | direct contact methods. `status` switches the whole band off |
| `offices` | the office list. `status` switches the whole band off |

**A band's `status` and a row's are separate switches, and both are honoured.**
`contact_shown_reach()` and `contact_shown_offices()` answer for both, which is why the structured
data cannot advertise a band the page does not draw. Only these two bands have a switch: the banner
and the enquiry form do not, because a contact page with no way to make contact is not a page
anybody meant to publish.

### A reach item

| Field | |
|---|---|
| `icon` | an icon name from `ADMIN_ICONS` |
| `label` | "Phone", "Email", … |
| `type` | `phone`, `email`, `url` or `text` — decides how `contact_reach_href()` links it |
| `values` | array of strings; several numbers under one label |
| `text` | free text, when `type` is `text` |
| `status` | `shown` or `hidden` — `contact_shown_reach()` filters on it |

### An office

| Field | |
|---|---|
| `id` | generated on creation, never typed |
| `name` | the city or office name |
| `flag` | a slug naming a flag that ships with the public site — `bangladesh`, `belgium`, `malaysia`. Cannot grow without a deploy, which is what `image` is for |
| `image` | an uploaded flag: `src`, `webp`, `width`, `height`, the same record a company logo uses. **Wins over `flag` when set.** Paths are checked against `CONTRACT_IMAGE_ROOTS` |
| `address` | |
| `phones` | array of strings |
| `hours` | opening hours |
| `languages` | array of strings |
| `status` | `shown` or hidden — `contact_shown_offices()` filters on it |
| `schema` | `street`, `locality`, `region`, `postal_code`, `country` — for `PostalAddress` structured data |

**`contact_images()` counts an office picture when the unused-upload sweep asks what is in use** —
and it did not exist until it had to. `contract_images()` put `contact` in the arm that returns the
`meta` band alone, so an office photograph was claimed by nothing: every other screen's sweep
counted it unused and offered to delete a picture that was on this page. The `flag` slug is
deliberately not counted, because it names a file that ships with the site rather than one anybody
uploaded.

`contact_page_schema()` emits `ContactPage` and `PostalAddress` from these, and so does the
`Organization` graph at the top of `tech4time-website-frontend/pages/contact/index.php` — `contact_addresses()` and
`contact_points()` are spliced into it. That block used to write the three offices out by hand,
which meant hiding an office took its card off the page and left its address being advertised to
Google. `contact_points()` emits one point per **phone**, not per office: an office listing three
numbers had two of them reachable on the page and invisible to a search engine.

---

## `content/about.json`

```json
{
  "updated":  "…",
  "revision": 0,
  "meta":        { … },
  "hero":        { "title": "…", "subtitle": "…" },
  "story":       { "status": "shown", "items": [] },
  "specialties": { "status": "shown", "title": "…", "interval": 10000, "items": [] },
  "whyus":       { "status": "shown", "title": "…", "items": [] },
  "cta":         { "status": "shown", "title": "…", "label": "…", "href": "…", "icon": "…" }
}
```

| Field | |
|---|---|
| `updated` · `revision` | bookkeeping — see *Rules that apply to both* |
| `meta` | everything the `<head>` says about this page — see [The `meta` band](#the-meta-band-on-every-document) |
| `hero` | the page's heading and subheading. No `status`: a page with no title is not a page with a section switched off |
| `story` | the image-and-prose sections. `status` switches the whole run of them off |
| `specialties` | the slideshow. `interval` is milliseconds, clamped 2000–60000 |
| `whyus` | the grid of short reasons |
| `cta` | the closing band and its one button |

**`story` has no `title` of its own.** Every heading on that part of the page belongs to a row,
which is what lets a section be added, reordered or hidden on its own.

### A story section

| Field | |
|---|---|
| `id` | minted from the heading; also the `<h2>`'s id and what the section's `aria-labelledby` points at |
| `heading` | the section's `<h2>` |
| `body` | sanitised HTML — one or two `<p>`. The only rich field on this page |
| `layout` | `photograph`, or `logo` for the light/dark wordmark lockup |
| `side` | `left` or `right`; `right` renders `.about-split--reverse` |
| `alt` | what the picture shows. Required even for `logo`, because the lockup is what gets announced |
| `image` | `{ src, webp, width, height }`. The picture, or the light half of a logo pair |
| `image_dark` | the dark half of a logo pair. Only `layout: "logo"` draws it |
| `status` | `shown` or `hidden` |

**A `logo` row draws a pair, and each half falls back on its own:**

| uploaded | light mode | dark mode |
|---|---|---|
| nothing | the shipped lockup | the shipped lockup |
| `image` only | the upload | **the same upload** |
| both | `image` | `image_dark` |

The middle row is the one worth explaining. Falling back to the shipped *dark* logo there would
put the old mark beside the new one, which is the one outcome nobody wants from "we changed our
logo". A new light logo may read poorly on a dark background; the previous brand does not read
poorly, it is wrong. The editor says so and offers the second slot.

**This is the logo in that section and nowhere else.** The header, the footer, the browser tab,
the social share card and `Organization.logo` in the structured data are build artefacts, not
content, and still need a developer and a deploy. The header's and the footer's are fields of
`tech4time-website-frontend/content/chrome.json`, edited on the **Header & Footer** screen.

A picture record is kept rather than cleared on a row whose layout is not `logo`, so switching back
does not lose it — which is also why `about_images()` counts both halves when the unused-upload
sweep asks what is in use.

**The light and shaded backgrounds alternate by position, not by a stored field.** It is a rhythm
down the page, so a reordered or added section keeps the stripe instead of carrying a stale copy
of it.

### A speciality, and a why-us card

The same shape.

| Field | |
|---|---|
| `id` | minted from the title |
| `icon` | a name from `ABOUT_ICONS`. Anything else is dropped on save and on receipt |
| `title` | the card's heading |
| `text` | one paragraph, plain text |
| `status` | `shown` or `hidden` |

**The specialities repeat the six service names that also appear on the home page and
`/pages/services/`.** Neither of those has a content source yet, so this document is not the owner
of that taxonomy — it is the first place it was written down. To be reconciled when the services
page comes under management.

**About's why-us cards and the company profile's `principles` express overlapping ideas** — Robust
Security and Security First, Client-Centric Approach and Client Partnership — and are deliberately
separate: different wording, different icons, different markup, on different pages. Editing one is
worth a look at the other.

## The `meta` band, on every document

Every document has one, including `content/careers.json`, which never used to — its title and
description were literal strings in the page file, and the only two on the site nobody could change.

```json
"meta": {
  "title":       "About Tech4TIME | Trusted IT & Cybersecurity Solutions",
  "description": "Founded in 2018, Tech4TIME delivers …",
  "share_title": "About Tech4TIME",
  "keywords":    "about Tech4TIME, IT company Bangladesh, cybersecurity company Dhaka",
  "breadcrumb":  "About Us",
  "robots":      "index",
  "changefreq":  "monthly",
  "priority":    "0.8",
  "share":       { "src": "", "webp": "", "width": 0, "height": 0 },
  "share_alt":   ""
}
```

| Field | |
|---|---|
| `title` | the browser tab and the search result's heading. At most `SEO_TITLE_MAX` (65) characters |
| `description` | the search result's paragraph. `SEO_DESC_MIN`–`SEO_DESC_MAX` (50–165); 150–160 is the ideal the editor hints at and nothing refuses |
| `keywords` | a comma-separated list, tidied on save: empties and case-insensitive repeats dropped, one space after each comma. **Omitted from the page entirely when empty.** Google has ignored the tag since 2009 and Bing treats a stuffed one as spam — a handful of true words is worth more than a long list |
| `share_title` | the heading on a shared link |
| `breadcrumb` | the page's name in the BreadcrumbList. **Pure SEO** — there is no visible breadcrumb anywhere on the site |
| `robots` | `index` or `noindex`. **Also decides the sitemap**: one control, not two |
| `changefreq`, `priority` | the sitemap's hints for this page |
| `share` | a per-page share card. Empty means the site-wide one in `content/seo.json` |
| `share_alt` | its alt text |

A service row carries the same band, so a seventh service arrives with sensible defaults.

### Only `?s=seo` writes it

The nine page editors do **not**. Each renders a link to that page's SEO screen where its meta
fieldset used to be, and each `*_from_post()` iterates `contract_page_bands()` — `*_TEXT_FIELDS`
**minus** `meta`.

That subtraction is the whole safety property. A form that stops *rendering* a field while its band
is still named in the loop reads `$_POST['meta']['title']` as absent, `?? ''` supplies an empty
string, and the page's title is blanked on every save — silently, because empty is a valid title
and nothing throws. `tools/test_seo_admin.py` saves each page editor untouched and requires every
meta value to survive; with `contract_page_bands()` deliberately broken it reports four fields
blanked on every document.

[seo.md](seo.md) · [ADR 0020](../90-decisions/0020-page-metadata-is-content.md)

---

## `content/seo.json`

The site-wide half: what is true of the whole site rather than of one page, plus the 404's own
record, because that page renders no content document and never will.

| Band | | Edited at |
|---|---|---|
| `site` | the defaults every page inherits: `<html lang>`, `og:locale`, `og:type`, the card shape, the theme colours, the default share card | `?s=seo&site=identity` |
| `identity` | the Organization node — legal name, slogan, founding year, the services it offers, what it knows about | `?s=seo&site=identity` |
| `sameas` | the profiles that are this company elsewhere. Rows: add, reorder, **hide** | `?s=seo&site=identity` |
| `hours` | opening hours as machine-readable rows — `days[]`, `opens`, `closes`. The office rows in `content/contact.json` carry hours as prose, which a search engine cannot read | `?s=seo&site=identity` |
| `crawl` | extra `Disallow` paths, the Search Console and Bing verification tokens, and **`analytics_id`** — a Google measurement id. Empty means no analytics and no external origin; anything that is not the shape Google issues is refused rather than escaped, because it lands inside a `<script src>`. [ADR 0021](../90-decisions/0021-analytics-is-off-until-somebody-turns-it-on.md) | `?s=seo&site=crawl` |
| `manifest` | what the web manifest says. The **icon list is not here** — it names files that must exist | `?s=seo&site=crawl` |
| `notfound` | the 404's title, description and crawl directive. No canonical and no `og:url`, by design | `?s=seo&page=notfound` |

**The offices are not here.** The addresses and telephone numbers in the Organization graph come
from `content/contact.json`, through the same functions the contact page renders from — so the
graph and the visible page cannot disagree.

---

## `content/chrome.json`

The furniture around every page of the public site: the header, the footer and the small-screen
dock. Rendered by `tech4time-website-frontend/lib/body.php` on the request. It was literal markup in
seventeen page files until 2026-09-10 — about 6,800 lines of duplication kept in step by
`propagate_shared.py` —
[ADR 0023](../90-decisions/0023-the-header-and-footer-are-emitted-once.md).

```json
{
  "updated":  "…",
  "revision": 0,
  "header": { "brand_label": "…",
              "logo": { "alt": "…", "sizes": "…", "width": 360, "height": 128,
                        "light": { "src": "…", "srcset": "…", "webp": "…" },
                        "dark":  { "src": "…", "srcset": "…", "webp": "…" } },
              "nav":  { "items": [] } },
  "footer": { "brand_label": "…", "logo": {}, "tagline": "…", "description": "…",
              "links":     { "heading": "…", "items": [] },
              "services":  { "heading": "…", "index_label": "…" },
              "contact":   { "heading": "…", "items": [] },
              "legal":     { "items": [] },
              "copyright": { "name": "…", "rights": "…" } },
  "dock":   { "panel": { "items": [] },
              "bar":   { "items": [] },
              "menu_label": "…" }
}
```

| Band | | Edited at |
|---|---|---|
| `header` | the logo lockup, the link's accessible name, and the six-row main nav | `?s=chrome&part=header` |
| `footer.links` | the "Quick Links" column: a heading and rows | `?s=chrome&part=footer` |
| `footer.services` | a heading and the label of the row above the list. **The rows are not here** | `?s=chrome&part=footer` |
| `footer.contact` | the footer's **own** contact rows — see below | `?s=chrome&part=footer` |
| `footer.legal` | the bottom bar's links | `?s=chrome&part=footer` |
| `footer.copyright` | the name and the rights sentence. **The year is not here** — it is stamped as the page renders, and `refreshCopyrightYear()` in `main.js` corrects it for a tab left open across midnight on 31 December | `?s=chrome&part=footer` |
| `dock.panel` | the card that rises above the bar: a row per section, each with a line of explanation | `?s=chrome&part=dock` |
| `dock.bar` | exactly four keys, each with a destination, a short label, an icon and an emphasis | `?s=chrome&part=dock` |

### A link row points at a route, never at a URL

```json
{ "id": "about", "target": "about", "label": "", "status": "shown" }
```

`target` is a key of `chrome_targets()` — one of the nine routes that resolve to an address, or
`service:<id>` for a row of `content/services.json`. There is no way to type an address into a nav
link, which is what makes it impossible for one to 404 in the single component that appears on
every page of the site. `SEO_ROUTES` already says routes are code; this follows from it.

**An empty label means "whatever that page calls itself".** Every link the site ships with has
one, because every one already agreed with its route's own name. So renaming a page in `?s=seo`
renames it in the header, the footer and the dock at once, and a label is typed only where the
chrome should disagree on purpose. The dock's bar is the exception: its labels are always typed,
because what fits under a 44px key is not what fits in a nav — "Profile", not "Company Profile".

### A footer contact row

```json
{ "id": "phone-bangladesh", "kind": "phone", "label": "Bangladesh",
  "lines": ["+880 1320571562", "+880 1881873463"],
  "note": "Sunday – Thursday", "status": "shown" }
```

`kind` is `phone`, `email`, `address` or `hours`. It decides both the mark drawn beside the row and
how the lines link — `tel:`, `mailto:`, or not at all, because a street and an opening time are
facts rather than destinations. **Consecutive rows sharing a kind render inside one
`.contact-item`, under one icon**, which is what the CSS's `.contact-item__label ~
.contact-item__label` rule is written against.

### Two columns store nothing, and are derived

**The services list** is read from `content/services.json` as the footer renders, so a seventh
service appears in the footer by itself and a hidden one disappears. Before this, the footer's copy
said "Human Resource Provision" where the services document said something else, and a service
added in the editor could never appear in the footer at all.

**The social links** are read from the SEO document's `sameas` rows, so a profile URL is changed in
one place and the footer cannot disagree with the Organization graph. The mark comes from the URL's
host — `CHROME_SOCIAL_ICONS` — with a globe behind anything the sprite has no brand mark for.

### The footer's contact rows deliberately are not

They are the footer's own: added, worded, ordered, shown and hidden on the footer screen, owing
nothing to `content/contact.json`. The contact page holds every detail in full; a footer holds the
part worth putting in a footer, in whatever wording suits it.

What keeps the two honest is a **notice, never a refusal** — for the reason the privacy policy's
duplicated facts are reported rather than forbidden: requiring the two to agree before either could
be saved means that after an office move, whichever page you edited first could not be saved, and
there is no order that avoids it.

### The four columns and the four keys are code

Their headings and contents are here; their number is not. A footer that can be given a fifth
column is a footer that can be broken at a width nobody tested, and a fifth key would not wrap —
it would shrink the other four below a thumb's width. `chrome_normalise()` pads and truncates the
bar to `CHROME_BAR_SLOTS` rather than trusting what arrived, because a document is a file as often
as it is a form.

### `chrome_defaults()` is the markup, extracted rather than typed

Every value in it was read out of the three templates by a script before they were deleted, so a
host with no `content/chrome.json` renders the site exactly as it rendered before any of this
existed — which is the whole safety property of the conversion, and the reason the header, footer
and dock cannot vanish because one file failed to arrive.

**A band that is absent and a band that is empty are not the same thing.** A missing document, or
one that predates a band, falls back to `chrome_defaults()` for that band — that is what makes the
paragraph above true. A band that arrived as an empty list is an operator who hid or removed every
row, and it stays empty; filling it back in would resurrect links somebody had deliberately taken
away, on every page at once. `chrome_normalise()` tells them apart by asking whether the key is
an array at all, not whether it is truthy, and `tech4time-website-frontend/tools/test_chrome.py`
holds both halves down: every page renders with no document at all, and a nav emptied on purpose
stays empty while the other bands keep their rows.

---

## Rules that apply to both

**Written atomically.** `store_write()` writes a temp file and renames it over the target, keeping
one `.bak`. A visitor loading the page mid-save reads either the old file or the new one.

**Rich text is sanitised on save** by `rt_sanitise_html()`, which writes new tags from an allow-list
rather than passing anything through. There is no `style` attribute — the CSP blocks inline styles,
so alignment is a class from a fixed list.

**Everything is escaped on output** with `h()`, regardless of having been sanitised on the way in.

**Bookkeeping fields** — `updated`, `revision` — are exempt from the content-model check in
both directions. Nothing renders them and the form does not write them.

**Ids are generated, not typed.** `careers_slug()` and `contact_slug()` make them.

---

## On the host, these files are the real data

Written by people through the admin. **Never upload them to a live server** —
[routine-deploys.md](../20-deployment/routine-deploys.md).

The repository's copies are development data, kept deliberately rich because an empty file exercises
no renderer. [environments.md](../20-deployment/environments.md)
