# 0011 — Two repositories, two hosts

**Status:** accepted, **built** · **Applies to:** both

## Decision

Split into **`tech4time-website-frontend`** (`tech4time.bd`) and **`tech4time-website-backend`**
(`admin.tech4time.bd`), communicating over a signed API.

> **Note from building it.** The names above are the ones in use, unchanged. They were briefly
> shortened to `tech4time-frontend` and `tech4time-backend` on 2026-08-27 and put back the same day —
> recorded because the shorter pair appears in that day's commits and a reader finding them there
> should not think a decision was reversed.
>
> `tech4time-website-frontend` is a **rename of `tech4time-website`**, not a new repository, so it
> keeps the history, the pipeline and the deploy secrets already proven against the live host. Only
> the backend is new, and it was cloned rather than created empty so that it keeps the history too.

## Context

The admin is a different application with a different audience, a different risk profile and a
different deploy cadence from a public brochure site. Today they share a document root, so a mistake
in one can affect the other, and the public site carries code that only the admin needs.

## Consequences

**Good.** The public site stops shipping authentication code entirely. The two deploy independently.
The blast radius of an admin vulnerability shrinks to a subdomain. Each gets a CI pipeline suited to
what it is.

**Costs.** Two repositories to keep in step. Deliberately minimised: the backend sanitises and
normalises at write time and publishes complete, schema-versioned documents, so the frontend needs
only three shared files — `lib/html.php` (the sanitiser, as defence in depth), `lib/contract.php`
(the shape of a document) and `lib/publish.php` (the wire format). All three are byte-identical
across the repositories, with a CI step in each comparing a SHA-256 against a committed value.

> **Amended since.** It is **five** now, and the count is the thing to watch rather than the names.
> `lib/svg.php` joined when vector logos became uploadable — the sanitiser is the boundary, so the
> receiving host must run the same one — and `public/assets/icons/sprite.svg` joined because both halves
> draw from it and a symbol present in one and not the other is an icon that silently does not
> render. `tools/check_shared_lib.py` reports the count on every run; if this paragraph and that
> number ever disagree, the number is right.

> **Amended when built — and this is the important one.** *"A mismatch fails both builds"* is not
> true, and believing it would be worse than not having the check at all.
>
> Each repository compares **its own** files against **its own** committed digest. Edit
> `lib/html.php` in the backend, run `check_shared_lib.py --update` there, and the backend passes;
> the frontend also passes, against its own unchanged copy; and the two now hold different
> sanitisers with every check green. A digest compared separately on each side can only catch an
> *accidental* local edit — a hand-patch on a server, a merge that went sideways, a file half
> copied.
>
> So the guarantee moved to the runtime path. **Every published payload carries `contract_version`,
> and the receiving side refuses a version it does not implement** rather than writing a document it
> would then mis-render. That fires automatically, on the real path, on the day, against what was
> actually sent, checked by the side that would suffer from the mismatch. The checksum stays as
> hygiene, and `tools/check_shared_lib.py` says so in its own docstring so that nobody re-derives
> the wrong confidence from it.

**What breaks on a subdomain, catalogued:**

- `public/.htaccess` matches `expr=%{REQUEST_URI} =~ m#^/admin(/|$)#`. On the subdomain the URI is `/`, so
  the rule **silently stops firing** — the backend needs its own blanket `X-Robots-Tag`.
- `ADMIN_SECTIONS['view']` links `/pages/contact/` root-relative; those become absolute.
- `RewriteRule ^lib/` and `^content/` are docroot-relative — resolved by moving both outside the
  document root, which is stronger than the rewrite ever was.
- HSTS `includeSubDomains` must stay **off** until AutoSSL has issued for the subdomain.
- **The subdomain's document root must be outside `public_html`** —
  [environments.md](../20-deployment/environments.md). Resolved by
  [0018](0018-the-backend-serves-from-a-subdirectory.md), which puts it at `admin.tech4time.bd/public/` and so
  moves `lib/`, `sections/` and `content/` out of reach by construction rather than by rule.

Two questions this record left open are now settled: the stores are separate
([0017](0017-two-private-stores.md)), and the backend serves from a subdirectory
([0018](0018-the-backend-serves-from-a-subdirectory.md)).

**This documentation is built for it.** Every file carries an **Applies to:** line, so the split is a
move rather than a rewrite.
