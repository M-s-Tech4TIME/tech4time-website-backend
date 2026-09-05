# 0019 — Uploaded images travel their own signed channel

**Status:** accepted · **Applies to:** both

## Decision

A picture added through the admin does **not** go inside the content document. It travels on a
second endpoint, `tech4time-website-frontend/api/publish-asset.php`, signed exactly as a document is, with the bytes as the
body.

Three independent things make that safe, and each is meant to hold on its own:

| | |
|---|---|
| **the bytes are ours** | the backend decodes the upload with GD and re-encodes from the pixel data — or, for a vector, parses it and re-serialises it through `lib/svg.php`. What is sent is that library's output — never the file somebody chose |
| **the receiver proves it again** | the public site does not take the admin's word for any of that. It re-derives the type from the bytes, and for a vector re-runs the sanitiser and refuses anything that is not already its output |
| **the name is ours** | sixteen hex characters of SHA-256 of those bytes, plus an extension read from the image header. The sender never sends a filename |
| **the server serves nothing else** | an `.htaccess` allow-list on both hosts: `^/uploads/[0-9a-f]{16}\.(webp|jpe?g|png|svg)$`, and everything else under `uploads/` is refused before a handler sees it |
| **a vector is never a document here** | an `.svg` under `uploads/` is served `Content-Disposition: attachment` with `default-src 'none'; sandbox`, on both hosts. It downloads; it does not render |

`uploads/` is on the deploy **protect list** in both repositories, for the same reason `content/`
is — and it is the more dangerous of the two to get wrong.

## Context

Two of the sixteen public pages were editable when this was written, and neither carried artwork.
The company profile carries sixty-two pictures across three galleries, and "add a client" has to
mean adding one without a developer and a deploy. That is what forced the question.

### Why not in the document

The content channel is one signed JSON POST capped at `PUBLISH_MAX_BYTES`, a megabyte. Sixty
logos base64'd into it would blow that several times over — and, worse, would re-send every
picture on every save of a single word. A separate channel costs one endpoint and keeps documents
the size they are.

### Why re-encode rather than validate

This is the part worth writing down, because the tempting version is wrong.

An upload is not a file to be checked and then saved. It is untrusted input to be *read* and then
*replaced*. Decoding and re-encoding removes, without knowing it is doing so:

- EXIF, including the coordinates a phone puts in a photograph
- anything appended after the image data, which is how a polyglot is built
- a file that is a valid JPEG *and* a valid PHP script, or ZIP, or HTML
- colour profiles and comment blocks nobody asked for

A validator can do none of that. It can only decide it did not find what it knew to look for. The
list above is not the list of things checked; it is the list of things that stop existing.

GIF and BMP are refused because nothing needs them, and a format nobody uses is an attack surface
nobody watches.

### SVG — refused when this was written, and now allowed under conditions

**Amended 2026-09-05.** This record said, and meant:

> SVG is refused outright, and that is not an oversight. An SVG is a document: it can carry script,
> external references and entities, and re-encoding does not make it not a document.

That reasoning was right and is not withdrawn. What changed is the requirement: the branding page
exists to hand people usable logo files, and a branding page with no vector logo is a branding page
designers cannot use. So the objection is answered rather than overruled, in two parts.

**Re-encoding became re-serialising.** The rule above — untrusted input is *read and replaced*, not
*checked and kept* — is not specific to pixels. `lib/svg.php` parses the file into a DOM, walks it
against an allow-list of what a drawing may contain, and stores the serialiser's output. Script,
event handlers, entity declarations, `<foreignObject>`, embedded rasters, SMIL animation and any
reference that leaves the file are each **refused outright**, not stripped: silently dropping an
element would hand somebody back different artwork than the one they published, which is its own
kind of wrong. It is a shared file for the same reason `html.php` is — two sanitisers that disagree
mean the second one is not a check.

**And the remaining objection — that the result is still a document — is answered by never serving
it as one.** `/uploads/*.svg` goes out with `Content-Disposition: attachment` and
`Content-Security-Policy: default-src 'none'; sandbox` on both hosts. A file that always downloads
and never renders in our origin cannot act in it, whatever it contains. No page draws one: the
branding page's previews are raster, and the vector file is only ever the target of a link.

**Two properties make this checkable rather than merely argued.** The sanitiser is *idempotent*, so
the receiving host can prove bytes are clean by sanitising them again and finding nothing moved —
without editing bytes whose SHA-256 is their filename. And it needs `ext-dom`, which
`svg_problem()` reports plainly; a host without it refuses every vector file rather than guessing,
while the byte-level refusals above still hold. `tools/test_svg.py`, in both repositories, is where
all of that is asserted.

### Why the name is computed

A filename from the sender is the classic way into a directory that is served. Computing it
removes the whole question: there is nothing for a traversal to ride in on, no extension a handler
would claim, and no way for two uploads to collide with different contents. Content-addressing
also makes the channel **idempotent** — the same picture twice is one file, a re-send after a lost
response is a no-op, and the backend's `reconcile.py` is therefore safe to run whenever.

There is no revision on this channel, and none is needed. A document can be rolled backwards by a
replay, which is what `contract_next_revision()` guards; a picture named after its own contents
cannot be anything other than itself.

### What this did NOT make safe, and why that is acceptable

A real PNG with a payload appended is still a real PNG, and this endpoint accepts it —
`tech4time-website-frontend/tools/test_publish_asset.py` records that outright rather than pretending otherwise. It does not
matter, three times over: the backend re-encodes before sending, so those bytes never leave it;
the name ends `.png`, chosen from the header rather than from the sender; and the allow-list
serves that shape as an image, so nothing on either host can be asked to run it.

## Consequences

**A second endpoint to keep deployed.** `verify_live.py` asserts it answers 405 to a GET on both
hosts, beside the existing assertion for the content endpoint.

**`uploads/` must never be synced.** It holds files that exist in no repository. A deploy without
the protect-list entry would delete every picture the editor has ever added, on the first run, and
report success. Both `build_deploy_set.py` files refuse to put it in the set, both `deploy.yml`
files protect it, and both `verify_live.py` files check what the directory serves.

**The admin's CSP names one outside origin.** Most of the artwork the company editor previews
ships with the public site and exists nowhere else, so `img-src` on `admin.tech4time.bd` includes
`https://tech4time.bd`. Images only — `script-src`, `style-src`, `connect-src`, `font-src` and
`default-src` are still `'self'` and nothing else, and `check_secrets.py` asserts each of them
individually so that this stays exactly as wide as it is.

**`ext-dom` becomes a requirement of both hosts, for vector files only.** Both have it
(`docs/40-reference/host-facts.md`). Ubuntu's `php-cli` does not, so CI installs `php-xml` rather
than letting `tools/test_svg.py` skip the cases that matter — the same arrangement GD already has.

**GD becomes a requirement of the admin host.** It is present on this one (8.2.33, with WebP).
`upload_problem()` says so plainly when it is not, and the rest of the editor keeps working.
Ubuntu's `php-cli` does not include it, so CI installs `php-gd` rather than letting
`test_upload.py` skip the cases that matter.

**Orphans are possible and are not swept automatically.** A picture uploaded and then abandoned
stays on disk. The editor lists what nothing references and offers to delete it; it never does so
on its own, because a reference count taken from a document somebody is halfway through editing is
not a fact.
