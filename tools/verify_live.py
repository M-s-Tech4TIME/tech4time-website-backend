#!/usr/bin/env python3
"""
Ask the live admin whether the deploy actually landed.

Deploy tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/verify_live.py https://admin.tech4time.bd

WHY THIS EXISTS
A deploy can succeed at the transport and still leave a broken or exposed site,
and the two failures look nothing alike. A missing page is loud: somebody
clicks it and gets a 404. A document root pointed one level too high is silent
— the editor still works, every asset still loads, and the only difference is
that lib/auth.php is now a text file anybody can read.

THE 404s ARE THE POINT, AND THEY MUST BE 404s
On this host lib/, sections/ and content/ are OUTSIDE the document root, so a
request for them does not reach a rule that refuses — it reaches nothing at
all. That is ADR 0018, and it is why the expected answer is 404 and not 403.

A 403 here would be a finding, not a pass. It would mean those directories are
inside the document root after all and something is choosing to block them,
which is exactly the weaker arrangement this repository is shaped to avoid.
So they are asserted as 404 alone.

IT LOOKS TWICE BEFORE IT FAILS
A deploy that has just finished rsyncing is a deploy the web server has not
finished reading. LiteSpeed re-reads .htaccess and rebuilds the vhost after
files under it change, and for a few seconds in that window the host answers
every path with 200 and none of the headers — a cPanel default page, not this
site. This check runs within a second of the last file landing, so it sees that
window sometimes.

That happened once, on 2026-08-26: 6 of 22, with /no-such-page-here answering
200 and every header absent. The deploy was fine; the site was fine a minute
later; the red X was on a good release.

So a failing check is looked at again after RETRY_AFTER seconds, and only a
failure that survives the second look is a failure. The distinction is real: a
server mid-reload recovers in seconds and a broken .htaccess never does.

A check that only passed the second time is reported as such rather than
quietly counted as a pass — "the site needed N seconds to settle" is worth
knowing, and a check that started needing two looks every time would be telling
you something.

WHAT IT DOES NOT DO
It does not sign in. It answers one question — did the files and the shape that
protects them reach this host — and gives it back as an exit code CI can act on.
"""

import argparse
import ssl
import sys
import time
import urllib.error
import urllib.request

TIMEOUT = 20

# How long to give the server to finish reloading before believing a failure.
RETRY_AFTER = 20

# (path, what it must answer, why it matters)
EXPECT = [
    ("/login.php",            (200,),      "the sign-in is served"),
    ("/",                     (200, 302),  "the front door answers, signed in or not"),
    ("/assets/css/admin.css", (200,),      "assets are served"),
    ("/assets/icons/sprite.svg", (200,),   "and the sprite the editor draws from"),
    ("/robots.txt",           (200,),      "crawlers are told to go away"),
    ("/no-such-page-here",    (404,),      "a miss is a miss"),

    # 404 exactly. See the note above: a 403 would mean these are inside the
    # document root and merely blocked, which is the arrangement ADR 0018
    # exists to replace.
    ("/lib/auth.php",         (404,),      "lib/ is OUTSIDE the document root, not blocked inside it"),
    ("/lib/private.php",      (404,),      "likewise the store locator"),
    ("/lib/publish.php",      (404,),      "likewise the publish key's reader"),
    ("/sections/careers.php", (404,),      "sections/ is outside it too"),
    # Named as well as careers.php, which proves the same fact about the same
    # directory: this is the screen that can set any page of the public site
    # to noindex, and one reachable without signing in is the worst reachable
    # file on this host.
    ("/sections/seo.php",     (404,),      "including the one that controls indexing"),
    ("/content/careers.json", (404,),      "and the system of record"),
    ("/content/seo.json",     (404,),      "including the site-wide SEO document"),
    ("/tools/admin-cli.php",  (404,),      "tools/ is not deployed at all"),

    ("/t4t-private-admin/secret.key", (403, 404), "a store dropped in the web root is refused"),
    ("/.git/HEAD",            (403, 404),  "a directory whose name starts with a dot"),
    ("/.env",                 (403, 404),  "and every file inside one"),
    ("/README.md",            (403, 404),  "documentation is not the application"),

    # uploads/ is the only directory here holding files that came from
    # somebody's computer, and the only one that is both written and served.
    # .htaccess serves exactly sixteen hex characters and three raster
    # extensions there — the third of ADR 0019's three layers, and the only one
    # that still holds if the other two are wrong. Not testable against the dev
    # server, which does not read .htaccess, so this is the only place it is
    # ever checked.
    ("/uploads/",             (403, 404),  "uploads/ does not list its contents"),
    ("/uploads/x.php",        (403, 404),  "and a .php there is refused before any handler sees it"),
    ("/uploads/notahexname.webp", (403, 404), "a name this host did not mint is refused"),
    ("/uploads/0123456789abcdef.gif", (403, 404), "and so is an extension it does not serve"),
    ("/uploads/0123456789abcdef.svg", (404,), "but a vector file is allowed through to be served"),
]

# (path, header, what must be in its value)
HEADERS = [
    ("/login.php", "content-security-policy",   "script-src"),
    ("/login.php", "x-content-type-options",    "nosniff"),
    ("/login.php", "strict-transport-security", "max-age="),
    # A BLANKET rule. The public site's is scoped to ^/admin(/|$), and on this
    # host the URI is "/" — so a copied rule would match nothing and fail
    # silently. Checked on two different paths for exactly that reason.
    ("/login.php", "x-robots-tag",              "noindex"),
    ("/",          "x-robots-tag",              "noindex"),
    ("/login.php", "cache-control",             "no-store"),
]


def fetch(url: str):
    """(status, headers) — an HTTP error is an answer, not an exception."""
    req = urllib.request.Request(url, method="GET", headers={
        "User-Agent": "tech4time-verify-live/1",
        "Cache-Control": "no-cache",
        "Pragma": "no-cache",
    })
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.status, {k.lower(): v for k, v in r.headers.items()}
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}
    except (urllib.error.URLError, ssl.SSLError, TimeoutError, OSError) as e:
        return None, {"error": str(e)}


def check_path(origin, path, allowed):
    """(passed, description of what was got)."""
    status, info = fetch(origin + path)
    if status in allowed:
        return True, str(status)
    got = status if status is not None else info.get("error", "no answer")
    return False, f"wanted {' or '.join(str(a) for a in allowed)}, got {got}"


def check_header(origin, path, header, needle):
    status, info = fetch(origin + path)
    value = info.get(header, "")
    if value and needle.lower() in value.lower():
        return True, value[:60]
    return False, f"got {value[:80]!r}" if value else "(absent)"


def main() -> None:
    ap = argparse.ArgumentParser(description="Check a deployed admin over HTTP.")
    ap.add_argument("origin", help="e.g. https://admin.tech4time.bd")
    ap.add_argument("--no-retry", action="store_true",
                    help="fail on the first look; do not give the server time to settle")
    args = ap.parse_args()

    origin = args.origin.rstrip("/")
    print(f"{origin}\n{len(EXPECT)} paths, {len(HEADERS)} headers\n")

    # (kind, key, run-it) — one list so the retry does not have to know which
    # sort of check it is looking at again.
    checks = ([("path", (p, a, why), (lambda p=p, a=a: check_path(origin, p, a)))
               for p, a, why in EXPECT]
              + [("header", (p, h, n), (lambda p=p, h=h, n=n: check_header(origin, p, h, n)))
                 for p, h, n in HEADERS])

    results = {}
    for kind, key, run in checks:
        ok, detail = run()
        results[key] = (ok, detail)
        label = key[0] if kind == "path" else f"{key[1]}: {detail}"
        print(f"  {'ok  ' if ok else 'FAIL'}  {detail + '  ' + key[0] if kind == 'path' else label}")

    failed = [(kind, key, run) for kind, key, run in checks if not results[key][0]]

    # A server that has just been rsynced over may still be reloading. Look
    # again before believing it — see the note at the top of this file.
    settled = []
    if failed and not args.no_retry:
        print(f"\n  {len(failed)} did not pass. The server may still be reloading after the")
        print(f"  deploy; looking again in {RETRY_AFTER} seconds before calling it a failure.\n")
        time.sleep(RETRY_AFTER)

        still = []
        for kind, key, run in failed:
            ok, detail = run()
            results[key] = (ok, detail)
            if ok:
                settled.append(key[0])
                print(f"  ok    {detail}  {key[0]}   (only on the second look)")
            else:
                still.append((kind, key))
                print(f"  FAIL  {key[0]}  {detail}")
        failed = still

    passed = sum(1 for ok, _ in results.values() if ok)
    print(f"\n{passed}/{len(results)} checks passed")

    if settled:
        print(f"\n{len(settled)} needed a second look, {RETRY_AFTER}s apart: "
              + ", ".join(settled))
        print("The deploy is fine. If this becomes every run rather than an "
              "occasional one,\nthe server is taking longer to settle than it "
              "used to and that is worth knowing.")

    if failed:
        print("\nfailed:")
        for kind, key in failed:
            print(f"  - {key[0]}" + ("" if kind == "path" else f" {key[1]}"))
        # Before blaming the deploy: if a URL that should not exist at all came
        # back 200, and the headers we always set are missing, then whatever
        # answered was not this application. A misconfigured document root
        # cannot do that -- a nonsense path would still 404. A host security
        # layer challenging the caller can, and it returns the same 200 page
        # for every request with none of our headers on it.
        #
        # This happened on 2026-08-27 and the message below sent somebody to
        # check the document root, which was correct all along.
        decoys = [key for kind, key in failed
                  if kind == "path" and "no-such-page" in key[0]]
        headers_gone = sum(1 for kind, key in failed if kind == "header")
        if decoys and headers_gone:
            print("\nREAD THIS FIRST: a path that should not exist answered anyway,\n"
                  "and the headers this site always sets are absent. That is not a\n"
                  "broken deploy -- a wrong document root would still 404 a nonsense\n"
                  "URL. Something other than the application replied: a host security\n"
                  "layer or WAF challenging this caller, a parking page, or a proxy.\n"
                  "\n"
                  "Check by hand from somewhere else before changing anything:\n"
                  f"    python3 tools/verify_live.py {origin}\n"
                  "\n"
                  "If it passes from your machine and fails only from CI, the deploy\n"
                  "is fine and the host is filtering the runner.")
            sys.exit(1)
        print("\nThe deploy reached the server; what it left there is not right.\n"
              "A 404 that became a 403 means lib/, sections/ and content/ are INSIDE\n"
              "the document root and merely blocked — point the subdomain at\n"
              "admin.tech4time.bd/public/ and see ADR 0018.")
        sys.exit(1)

    print("\nThe admin is served, and nothing beside it is.")


if __name__ == "__main__":
    main()
