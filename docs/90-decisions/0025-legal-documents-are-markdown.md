# 0025 — Legal documents are Markdown, rendered by one shared library

**Status:** accepted · **Applies to:** both

## Decision

The body text of the legal documents — privacy policy, terms of service, cookie policy — is
authored as Markdown and stored as Markdown. A single hand-written renderer, planned as lib/markdown.php and
byte-identical in both repositories, turns it into HTML: on the backend for the editor's preview,
on the frontend for the page. There is no client-side copy; a preview that disagrees with the
page is impossible because they are the same code path.

The dialect is strict CommonMark core (paragraphs, emphasis, strong, links, lists) plus exactly
three extensions, each specified in `tech4time-website-frontend/plans/legal-markdown-syntax.md`:

- `++underline++` — Markdown has no underline, and the toolbar keeps its U button.
- `:::note` / `:::center` fenced containers — one mechanism for highlight boxes and alignment,
  both of which Markdown otherwise lacks. An unknown container name renders as plain text.
- Strict GFM tables — a malformed table renders as literal pipe-text, never a guessed table.

Raw HTML in the source is **escaped, never passed through**. The sanitizer allow-list does not
grow by a single tag: every tag the renderer can emit is one `rt_sanitise_html()` already
approves, and every rich field is re-sanitised through it after rendering, on both sides of the
publish wire.

Structure stays structural. A section is still `{id, heading, status, body}` — headings remain
editor fields (never `#` in text: heading levels are a landmark contract `audit_pages.py`
enforces), anchors stay stable, per-section hide/show stays, and the callout box, CTA band, hero,
SEO meta, and facts-comparison are untouched. What retires for legal documents is the
three-level block-kind card UI for prose: with tables and notes in the dialect, a section needs
no kinds. The table/address block machinery stays for nobody — legal was its last consumer —
and is deleted with the old privacy editor at cutover rather than left to rot.

## Context

### Why Markdown, and why now

The privacy editor's section→block→row cards are precise and, for a twelve-section policy with
forty-four blocks, punishing: every paragraph is a card, every correction is archaeology. The
owner asked for Word-like ribbons over a Markdown document — toolbar buttons inserting syntax,
server-rendered preview — and accepted the real cost: a new shared renderer, a contract bump,
and test surface to match. The ribbons were specified first (`editor.js` already carries
bold/italic/underline/lists/links/alignment); the dialect follows what ribbons can honestly
insert, not the other way round.

### Why one renderer and not two

A client-side preview renderer would be a second implementation of a security boundary to keep
identical forever. The preview posts through the existing `data-async` form machinery and gets
back HTML from the same shared renderer the page renders — preview IS the renderer, the way
`seo_head()` is the only head. The toolbar inserts syntax as text; it understands nothing.

### Why the contract bumps

New documents (`terms`, `cookies`) alone would not bump it: an old frontend refuses an unknown
document, and the frontend deploys first. But the privacy migration rewrites `privacy.json`
bodies from HTML-subset to Markdown, and an old renderer would print Markdown source as escaped
literal text — readable, but a legal page reading `**retention**` verbatim is a mis-render of
exactly the kind `CONTRACT_VERSION` exists to refuse. So the version goes to 2 and both halves
deploy in lockstep; inside the window a publish is refused loudly rather than rendered wrongly.

## Consequences

- **`CONTRACT_VERSION` becomes 2**, and the two `dev` branches land together or
  `tools/check_shared_repos.py` fails both. Frontend `main` first, backend second, as usual.
- **The shared renderer joins the byte-identical set** (as lib/markdown.php) in `tools/check_shared_lib.py` (now nine
  files), in both repositories, with a behavioral vector suite asserting identical output both
  sides — digests prove sameness, vectors prove correctness.
- **New suites ship run in the same commit** (`test_markdown.py` both halves,
  `test_legal_admin.py` backend, publish round-trips): nothing asserted nowhere, per the rule
  three orphaned suites taught.
- **The old privacy editor is deleted at cutover**, with its block machinery, after a one-way
  HTML→Markdown migration of the live `privacy.json` that is diff-reviewed and then deleted
  itself. Two editors for one page — even briefly in one branch — is how content gets saved
  through the wrong one.
- **Toolbar scope is frozen with the dialect.** A ribbon for syntax the renderer does not know
  is a button that writes text the page shows literally; `test_legal_admin.py` presses every
  ribbon and reads back rendered markup for each.
- **What this forbids:** raw HTML in legal source (escaped, always); headings in body text
  (`#` renders as a literal `#`, since heading levels belong to fields); a second renderer in
  JavaScript; per-document Markdown flavors.

## Amendment — one body, mintable headings, picked dates

The section-per-card editor proved to be archaeology for prose, exactly as suspected, so the
shape simplified one step further before terms or cookies existed to copy it: the policy is a
single Markdown `body`, the callout points folded into the note as a list, and headings moved
into the text with it.

- **H2–H6 in bodies, H1 in its field.** The page title owns H1; the ribbon offers the rest
  through a dropdown, and `#` alone stays literal. A body opens on H2 and never skips down —
  refused at save, naming the heading — because the renderer is line-blind and the rule is
  about a document.
- **Anchors declared or derived, never stored.** `## Heading {#custom}` pins; otherwise the
  heading words slug, deduped `-2`. One shared resolver serves renderer and rail, explicit
  doubles are refused, and the migration wrote `{#old-id}` onto every heading so no anchor
  changed hands.
- **Alignment joins the containers** (`:::left/right/justify` beside `note`/`center`), and
  the effective date is a calendar picker storing ISO beside the auto `updated` stamp —
  "Effective …" authored, "Last updated …" minted, never confused.
