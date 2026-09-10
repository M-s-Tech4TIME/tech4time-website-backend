# 0003 — Content renders on the server

**Status:** accepted · **Applies to:** both

## Decision

The careers and contact pages are PHP and render their JSON server-side, in one request. No page
fetches content at runtime.

## Context

The obvious alternative — ship static HTML and `fetch()` the JSON — was rejected early. Search
engines index JavaScript-rendered content unreliably, and the contact page is the one most often
searched for by name.

## Consequences

**Good.** Content is in the initial HTML, so it is indexable, readable without JavaScript, and needs
no loading state. Two of sixteen pages pay for PHP; the rest stay flat files.

**Costs.** Those two pages require PHP on the host, and cannot be previewed with a plain static
server — hence `tools/serve.py`.

**Forbids.** Runtime `fetch()` for content, including for the header and footer — which are
composed by PHP on the request ([0023](0023-the-header-and-footer-are-emitted-once.md)) rather than
assembled in the browser. Markup that cannot be composed that way is copied into every page and kept
honest by a check, rather than included at runtime.

**Extends to the split.** The same reasoning is why the frontend will hold a replica and never call
the backend at render time — [0010](0010-backend-pushes-content.md).
