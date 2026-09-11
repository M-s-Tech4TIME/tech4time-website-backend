# 0024 — One mark, many renditions: the site's identity is a document

**Status:** accepted · **Applies to:** both

## Decision

The logo, the favicon set, the brand colours and the address the enquiry form sends to are held in
`content/settings.json`, edited at `https://admin.tech4time.bd/?s=settings`, and read at render
time by every consumer. None of them is a file in a repository any more, and none of them is typed
into a second document.

**One mark, nine renditions, and the renditions are derived.** The logo is drawn in the site
header, the site footer, the About page's logo row, the admin's own rail and its sign-in page; it
is named in `Organization.logo`, in `JobPosting.hiringOrganization.logo`, and in the branding kit;
and it is what the panel shows the operator while they are choosing it. All nine read one record.
The alt text does **not** move with it: the header's and the footer's are legitimately different
sentences about the same picture, so they stay in `?s=chrome`.

**`identity.logo` on the SEO screen is an override, not a copy.** Empty means "the site's mark";
filled wins. Google renders `Organization.logo` in a near-square slot and this lockup is nearly
three to one, so the escape hatch is real rather than theoretical. The same fallback rule as a
chrome nav label.

**Each upload slot declares the width it is displayed at, and that one number feeds both the ladder
and the `sizes` attribute.** `CONTRACT_IMAGE_SLOTS` is the table. The uploader stores that width at
1x, 2x and 3x, never upscaling past the source; the renderer builds `sizes=` from the same row. Two
slots ladder deliberately not at all — the branding download is a deliverable rather than a
rendered image, and the OG share card is consumed by scrapers that do not implement `srcset`.

**Every width in that table is measured, not estimated.** Two of the seven are widest on a phone or
a tablet rather than on a desktop.

**`sizes` is code, and must be.** Without it a browser assumes the picture fills the viewport and
takes the *largest* candidate — so a ladder shipped without `sizes` is worse than no ladder. It is
a fact about the layout, so it lives beside the markup rather than in the document.

**The favicon is generated from its own square master, and `favicon.ico` is written by hand.** GD
has no ICO writer; the container is a six-byte header, a sixteen-byte entry per image, and PNG
payloads. `/favicon.ico` is served from the generated set — an address that had no answer at all
before this, and the first thing a browser asks for.

**The share card is a standing notice, never a generation.** See below.

**A colour pair below WCAG AA is refused, not warned about.** See below.

## Context

### Why the logo had to become a document

It was in twenty-one places. Eleven of them were text fields in `content/chrome.json`, typed twice
— once for the header and once for the footer. The rest named committed files that no editor could
reach at all. And the SEO screen already had a working logo upload that was **completely
disconnected from the header**: change one and the other silently kept the old mark, with nothing
in either repository comparing them.

That is the same defect this project had already fixed twice, in the footer's service list and in
its contact rows, and the rule that came out of those is ADR 0023's: anything derivable from
another document's own editor is read at render time, never duplicated. A company's mark is the
most visible thing it owns and was the worst remaining case.

### Why the uploader had to right-size

`upload_accept()` stored every picture at one 1600px ceiling while `tech4time-website-frontend/tools/build_images.py` had
deliberately chosen 160–1200 per kind. So nothing on the site was wrong, and the *first* use of the
admin as intended would have made it wrong: replacing an office flag turned a 666-byte picture into
a 1600px one, still drawn at 56px. A ladder without a declared display width has the same shape of
bug at a different scale.

### Why the share card is a notice and not a generation

The card is 1200x630 with the mark drawn into it and type set beside it. Generating it would mean
reimplementing typography against a font stack the server does not have — no FreeType, no
guarantee the brand face is installed — and a card with the wrong kerning is worse than one made by
the person who owns the brand. So it stays its own upload.

That leaves exactly one failure, and it is reported rather than prevented: the logo changes, the
card does not, and every link shared from the site keeps showing the previous mark. **Nobody sees
that on the site itself.** It is visible only in somebody else's chat window, which is the last
place anyone looks — which is precisely why it needs saying out loud on the screen where the logo
was changed.

### Why colours are a refusal and not a notice

Every other thing this editor reports is a standing notice that never blocks a save, and that is
deliberate: a missing dark logo half, a tab icon left behind, a footer that disagrees with the
contact page — each is a legitimate answer that only the person editing knows, and a save refused
over one would make the editor unusable halfway through a change.

Contrast is not like that. `tools/check_contrast.py` had already implemented sRGB relative
luminance and the WCAG AA thresholds over exactly these token pairs, and the site is required to
meet AA. **A standing notice can be ignored, and an unreadable site is not a matter of taste** — so
`settings_validate()` refuses a save that drops any functional pair below 4.5:1 for text or 3:1 for
boundaries and focus. It is the one refusal in the whole editor that is about the *result* rather
than about the data being well-formed.

The arithmetic is shared as **data**, not as code: `SETTINGS_COLOURS`, `SETTINGS_CONTRAST_PAIRS`
and `SETTINGS_CONTRAST_DECORATIVE` moved into the contract, and `check_contrast.py` keeps its own
Python implementation of the sums and compares all thirty-eight pairs against the PHP one. Two
implementations agreeing is evidence; one library agreeing with itself is not.

### Why the pair notices are two notices and not one

An **empty** dark half renders the light mark in both modes. That is often right — plenty of marks
are single-colour and read on both grounds — and it is reported only so that somebody whose mark is
dark ink knows what will happen.

A dark half still holding the **previous** mark is a different and worse thing: the site then shows
two different logos depending on a setting the person who uploaded it is not in, and nothing
anywhere says so. It is symmetric — replacing only the dark half is the rarer order and exactly as
wrong — and it is the fault that the end-to-end run walked straight into.

## Consequences

- **`CONTRACT_VERSION` stays at 1**, through a change that is not purely additive: the chrome's
  logo record lost six fields. An older frontend meeting the smaller record degrades to the shipped
  lockup rather than refusing the document, and the frontend deploys first, so the window in which
  that matters does not arise.
- **The frontend deploys first**, as in ADR 0023 and for the same reason. Merge the backend first
  so its run goes red on `check_shared_repos.py --clone` and `test_end_to_end.py` alone with
  `deploy` skipped; merge the frontend, which deploys; re-run the backend's failed run.
- **Deleted:** `--logo-src` in `tech4time-website-frontend/assets/css/theme.css`, declared three times and consumed nowhere;
  `chrome.json`'s eleven logo fields and `chrome_validate()`'s "type a path like…" refusal;
  `ABOUT_LOGO_WIDTH` / `ABOUT_LOGO_HEIGHT`; `COMPANY_FOUNDED`, which duplicated the editable
  `identity.founded`; and the four page-level JSON-LD blocks' hard-coded site name.
- **`tech4time-website-frontend/tools/build_logos.py` and `tech4time-website-frontend/tools/build_favicons.py` are KEPT**, against the original plan,
  which said the server does this now. It does not do *their* job: they build the committed **seed**
  from the master artwork in `tech4time-website-frontend/tools/masters/`, and the server makes renditions of an **upload** into
  `/uploads/`. Different input, different output, different lifecycle. Their docblocks now say
  which.
- **A logo is something an operator can now make too wide.** The header sizes the mark by its
  height and `.site-header__brand` is `flex-shrink: 0`, so the width it occupies is height times
  aspect ratio and nothing downstream can take it back. Measured at 320px before the fix: 8:1 held,
  10:1 pushed the page 56px sideways. Both lockups carry a `max-width` with `object-fit: contain`,
  and `check_responsive.py` asserts that no aspect ratio overflows.
- **`tools/test_end_to_end.py` exists because of this work.** Each half is tested against an
  independent implementation of the wire format, which is the right shape — but a picture is a
  ladder of renditions, each its own signed POST, and *a partial publish is a broken image* was a
  risk neither half could see alone.
- **`/favicon.ico` and `/assets/css/brand.css` are two more generated files served at static-looking
  addresses**, joining the sitemap, `robots.txt` and the manifest. Both fail safe to what ships when
  the document is missing; `tech4time-website-frontend/assets/css/brand.css` with the shipped palette is an **empty file**.
