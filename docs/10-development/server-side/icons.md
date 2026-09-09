# Icons

**Applies to:** both

Self-hosted SVG symbols, inlined per page. Not a webfont, not a CDN, not a shared sprite file.

---

## How it works

`public/assets/icons/sprite.svg` holds the master set — 119 symbols, cut from Font Awesome Free metadata by
`tech4time-website-frontend/tools/build_icon_sprite.py`.

Pages do **not** link to it. Each page carries the handful of symbols it actually uses, inlined at
the top of `<body>` between two markers:

```html
<!-- icon-sprite:start -->
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
  <symbol id="shield-alt" viewBox="0 0 512 512">…</symbol>
  <symbol id="envelope"   viewBox="0 0 512 512">…</symbol>
</svg>
<!-- icon-sprite:end -->
```

and reference them as same-document fragments:

```html
<svg class="icon" aria-hidden="true"><use href="#shield-alt"></use></svg>
```

`tech4time-website-frontend/tools/inject_icons.py` keeps the block in sync with what the page references.

---

## Why not a shared sprite file

The obvious approach:

```html
<svg><use href="/assets/icons/sprite.svg#shield-alt"></use></svg>
```

**Chromium and WebKit do not resolve `<use>` across documents.** That markup renders nothing outside
Firefox. The workarounds are a JavaScript polyfill — which makes icons vanish without script,
breaking the progressive-enhancement rule — or inlining.

Inlining wins on every axis that matters here: no extra request, no script dependency, and each page
carries only what it uses. A page with 30 icons costs roughly 15 KB before gzip, against 64 KB for
the full set.

---

## Adding an icon to a page

1. Reference it in the markup:
   ```html
   <svg class="icon" aria-hidden="true"><use href="#calendar-alt"></use></svg>
   ```
2. Inject:
   ```bash
   python3 tools/inject_icons.py
   ```
3. Verify:
   ```bash
   python3 tools/inject_icons.py --check
   ```

The script reads every `<use href="#…">`, pulls the matching `<symbol>` out of the master sprite,
and rewrites the block. It also removes symbols no longer referenced, so the block never accumulates
dead weight.

**If the symbol is not in the master sprite**, the script says so. Add it with
`tech4time-website-frontend/tools/build_icon_sprite.py`, then inject.

---

## Icons in the admin

The admin's pages are PHP, so their icon block is generated at request time by `admin_icons()` in
`lib/admin.php` rather than injected by the script. The set is the `ADMIN_ICONS` constant in the same
file.

**Adding an icon to an admin page means adding its name to `ADMIN_ICONS`.** Nothing else — the shell
inlines the whole list on every admin page, because the contact editor renders a live preview of
every icon it offers and cannot know in advance which will be used.

### The allow-lists an editor offers

Which icons a document may offer is part of the shared model, in `lib/contract.php`:
`CONTACT_ICONS`, `COMPANY_ICONS`, `ABOUT_ICONS`, `HOME_ICONS`, `SERVICES_ICONS`, and
`CHROME_BAR_ICONS` with the fixed marks beside it (`CHROME_CONTACT_ICONS`, `CHROME_SOCIAL_ICONS`).
They are in the contract rather than here because this side offers the choice and the public site
has to be able to draw whatever was chosen — a row carrying an icon the frontend has never heard of
renders there as an empty box.

`python3 tools/check_content_model.py` asserts every name in all of them is in the master sprite
**and** in `ADMIN_ICONS`. That second half only runs here, where the editor lives; an icon the model
offers that `ADMIN_ICONS` does not inline draws an empty box in the picker, and nothing else would
notice.

How the public site inlines the ones chosen at run time is its own problem and is written up in
`tech4time-website-frontend/docs/10-development/frontend/icons.md`.

---

## Accessibility

**Decorative** — the icon repeats adjacent text:

```html
<svg class="icon" aria-hidden="true"><use href="#phone"></use></svg>
<span>+880 …</span>
```

**Meaningful** — the icon is the only label:

```html
<button aria-label="Close">
  <svg class="icon" aria-hidden="true"><use href="#times"></use></svg>
</button>
```

The icon itself is always `aria-hidden="true"`; the accessible name goes on the control. A bare
`<svg>` with no name inside an interactive element is a finding `audit_pages.py` reports.

---

## Checks

```bash
python3 tools/inject_icons.py --check   # every page's block is current
python3 tools/audit_pages.py            # icon accessibility, among much else
```

`inject_icons.py --check` is in the pre-commit list. It fails when a page references a symbol it does
not carry — which renders as an empty box, and is very easy to miss by eye.
