# 0021 — Google Analytics is a field, and the policy opens only while it holds one

**Status:** accepted · **Applies to:** both

## Decision

`content/seo.json`'s `crawl.analytics_id` holds a Google measurement id, edited at
`https://admin.tech4time.bd/?s=seo&site=crawl`. While it holds one, every page loads
Google's tag loader from `googletagmanager.com` and reports to that property. While it is empty — which is
how the site ships — **no page reaches another origin at all**, and the Content Security Policy the
page sends is byte-for-byte the one it has always sent.

This is a deliberate exception to [0004](0004-self-hosted-strict-csp.md), and it is the only one.

## Context

The site has been fully self-hosted since it was built: no CDN, no third-party fonts, no embeds, no
trackers. That is a real property and not a preference — a third-party origin is an outage this
site cannot fix, a request it cannot see the contents of, and a name in the CSP that weakens the
policy for everything else on the page.

It is also why nobody can see how the site performs. Search Console reports what a *search engine*
did; analytics reports what *people* did, and the two answer different questions. The owner asked
for it explicitly, having had it on the previous site, and the request is reasonable: a business
that cannot see which pages are read cannot decide what to write next.

## What was built, and why it is shaped like this

**The id is validated as a shape, not escaped as a string.** `SEO_ANALYTICS_ID` in
`lib/contract.php` accepts `G-`, `GT-`, `UA-` or `AW-` followed by letters, digits and dashes, and
`contract_normalise()` replaces anything else with the empty string. This value is interpolated
into the `src` of a `<script>` pointing at another origin — the one editable field on this site
that ends up inside a URL a browser will execute — so it is refused rather than escaped. The
editor also *says* it refused, because a pasted id with a stray character that silently becomes an
empty field is a bug report nobody can write.

**The configuration half is this site's own file.** Google's snippet is two `<script>` blocks —
a loader they serve, and a configuration block that is inline — `script-src 'self'` refuses inline scripts with no error a visitor sees. A
page carrying that snippet would look correct and measure nothing.
`tech4time-website-frontend/assets/js/analytics.js` is the same two lines in a file this host serves, reading the id from a
`data-ga` attribute on its own tag.

**Two policies, and the strict one is the one that decides.** `tech4time-website-frontend/.htaccess` names the Google origins
unconditionally, because a header cannot read a JSON file — that is what makes the field *able* to
work without a deploy. What actually keeps them shut is the `<meta>` policy `tech4time-website-frontend/lib/head.php` sends
with every page, which stays `script-src 'self'` until an id is set. A browser enforces every
policy it is given, so the intersection applies and the stricter one wins.

The consequence worth stating: with the field empty, the header would allow a script from
`googletagmanager.com` that the page-level policy then refuses. One layer of defence in depth is
spent, for those origins only, to buy an operator control that does not need a developer.

## Consequences

- **Clearing the field switches everything off on the next page load** — no deploy, no release, no
  code change. That is the property the whole design is for.
- **`tech4time-website-frontend/tools/audit_pages.py` asserts it.** With no id, *any* external origin in a rendered page is a
  failure. With one, that single origin is expected and everything else still fails. It reads the
  document rather than a list, so the check and the switch cannot disagree.
- **The privacy policy stops being true the moment this is switched on.** It says, today, "No
  cookies, no analytics, no tracking". The SEO screen carries a standing notice saying so, but
  nothing can correct the policy automatically — that is a statement a person has to write.
- **EU visitors generally have to be asked first.** One of the three offices is in Brussels. This
  decision adds the mechanism; it does not add consent, and consent is not something the codebase
  can decide on the owner's behalf.
- **`og:updated_time`, the graph, the sitemap and every other head line are unaffected.** The only
  difference between the two states is two `<script>` tags and three CSP directives.

## Alternatives considered

**Proxy the measurement requests through this host** so no third-party origin is ever contacted.
It works, it is what a privacy-first site does, and it was rejected as disproportionate: it needs a
PHP endpoint that forwards to Google, keeps working when Google changes the protocol, and is a
piece of infrastructure nobody here would maintain. The failure mode is silent under-reporting,
which is worse than no analytics.

**Store the id but emit nothing until a deploy widens the CSP.** That is a switch that does not
switch anything, and the operator would have to ask a developer anyway — which is the thing this
was asked for in order to avoid.

**Widen only `tech4time-website-frontend/.htaccess` and leave the meta policy alone.** Then the page-level policy would refuse
the script whatever the header said, and the field would appear to do nothing. The two have to move
together, and only one of them can read the document.
