#!/usr/bin/env python3
"""
Exercise the SEO editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_seo_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/seo.php is the only screen that edits any page's title, search
description, share card, crawl setting or sitemap row — and unlike every other
editor it does not write one document. A page's meta band lives in that page's
OWN document (content/about.json holds the About page's title, beside the About
page's content), so saving here writes that file and publishes it. The site-wide
half — the Organization graph, the default share card, robots.txt, the manifest,
the 404's record — is content/seo.json.

So the checks that matter most are about what a save did NOT touch:

  - saving one page's settings leaves every other band of that document alone,
    because the screen held one band of a document whose other twenty were
    never in the form;
  - saving one service leaves the other five services alone, for the same
    reason one level deeper;
  - and, the other way round, saving a PAGE editor leaves its meta band alone —
    which is asserted in that editor's own suite, and is the failure
    contract_page_bands() exists to prevent.

IT IS ALSO WHAT check_content_model.py POINTS AT. That check reads the model,
the form and the renderer as text and asks whether every field the model
declares is both editable and rendered. It cannot do that here: this form loops
over routes and its field names are expressions rather than literals. So it is
proved by round trip instead.

WHAT IT CANNOT COVER
The sign-in, which is tools/test_admin_auth.py's subject; an actual upload,
which needs a multipart POST this harness does not build (tools/test_upload.py);
and what the frontend RENDERS from any of it, which is proved in
tech4time-website-frontend by tools/test_publish.py and tools/test_sitemap.py.

Every test runs against COPIES of the real data files, restored afterwards
whether the run passes or fails.
"""
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402
from publish_stub import PublishStub  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
# EVERY document this suite can write, because it writes several: a page's
# meta band lives in that page's own document, so saving the About screen
# writes content/about.json. All of them are backed up and restored.
DATA = [ROOT / "content" / f"{name}.json"
        for name in ("seo", "about", "services", "careers", "certifications")]

ADMIN = "/?s=seo"

ROUTER = ROOT / "tools" / "dev-router.php"


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []
        self.skipped = 0

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Client:
    """One browser: keeps the session cookie, and does not follow redirects so
    that a save can be seen to have redirected rather than re-rendered."""

    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(CookieJar()), NoRedirect()
        )

    def get(self, path):
        with self.opener.open(self.base + path, timeout=20) as r:
            return r.status, r.read().decode("utf-8", "replace")

    def post(self, path, fields):
        body = urllib.parse.urlencode(fields, doseq=True).encode()
        req = urllib.request.Request(self.base + path, data=body, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with self.opener.open(req, timeout=20) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    if not m:
        raise SystemExit("No CSRF token in the editor — it did not render.")
    return m.group(1)


def form_fields(html: str) -> dict:
    """Every named control in the editor, as the browser would submit it.

    Reading them out of the page rather than writing them by hand is what
    makes this a test of the editor: a field the form stops rendering
    disappears from the submission here too, and whatever depended on it
    fails.
    """
    fields = {}

    for tag in re.findall(r"<input\b[^>]*>", html):
        name = re.search(r'name="([^"]+)"', tag)
        if not name or 'type="submit"' in tag:
            continue
        # The failed-publish notice is a SEPARATE form on the same page, and
        # its only field is action=republish. A browser would never send it
        # with the editor's form; scraping the whole document would, and then
        # every save after a failed publish would silently become a republish
        # instead -- redirecting, changing nothing, and looking like a pass.
        if name.group(1) == "action":
            continue
        value = re.search(r'value="([^"]*)"', tag)
        fields[name.group(1)] = unescape(value.group(1) if value else "")

    for tag, body in re.findall(r"<textarea\b([^>]*)>(.*?)</textarea>", html, re.S):
        name = re.search(r'name="([^"]+)"', tag)
        if name:
            fields[name.group(1)] = unescape(body)

    for tag, body in re.findall(r"<select\b([^>]*)>(.*?)</select>", html, re.S):
        name = re.search(r'name="([^"]+)"', tag)
        if not name:
            continue
        chosen = re.search(r'<option value="([^"]*)"[^>]*\bselected', body)
        first = re.search(r'<option value="([^"]*)"', body)
        fields[name.group(1)] = unescape(
            (chosen or first).group(1) if (chosen or first) else ""
        )

    return fields


def unescape(value: str) -> str:
    return (value.replace("&lt;", "<").replace("&gt;", ">")
                 .replace("&quot;", '"').replace("&#039;", "'")
                 .replace("&amp;", "&"))


def stop(proc):
    for attempt in (proc.terminate, proc.kill):
        try:
            attempt()
            proc.wait(timeout=5)
            return
        except Exception:
            continue
    try:
        os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
    except Exception:
        pass




# ------------------------------------------------------------------- tests

def doc(site, name) -> dict:
    """A document as the live site last received it."""
    return site.documents.get(name, {})


def stored(name) -> dict:
    return json.loads((ROOT / "content" / f"{name}.json").read_text())


def still_holds(before, after, where="") -> str:
    """'' if everything `before` held is still in `after`, or what changed.

    NOT equality, and the difference is the whole point of the function. A save
    re-normalises the document it writes, so one stored before the contract
    grew a field comes back with that field filled in — content/about.json has
    carried image records with no srcset since before a picture could be stored
    at several widths, and content/*.json is a seed rather than a living copy.
    That is the shape catching up, and it says nothing about this screen.

    What this screen could do wrong is lose a row, empty a heading, or rewrite
    a band it does not own — and every one of those changes a value that WAS
    there rather than adding one that was not. So a value present before must
    be present after and equal, a list must still have the same number of
    entries, and a filled-in default is allowed to appear.
    """
    if isinstance(before, dict):
        if not isinstance(after, dict):
            return f"{where}: was an object, is now {type(after).__name__}"
        for key, held in before.items():
            if key not in after:
                return f"{where}.{key} is gone"
            bad = still_holds(held, after[key], f"{where}.{key}")
            if bad:
                return bad
        return ""

    if isinstance(before, list):
        if not isinstance(after, list):
            return f"{where}: was a list, is now {type(after).__name__}"
        if len(before) != len(after):
            return f"{where}: held {len(before)} entries, now holds {len(after)}"
        for i, held in enumerate(before):
            bad = still_holds(held, after[i], f"{where}[{i}]")
            if bad:
                return bad
        return ""

    return "" if before == after else f"{where}: {before!r} became {after!r}"


def save(client, path, fields):
    return client.post(path, {**fields, "do": "save"})


def press(client, r, path, fields, do, what):
    status, _headers, page = client.post(path, {**fields, "do": do})
    r.check(f"{what}: the form comes back", status == 200, f"status {status}")
    return page


def open_screen(client, r, path, what):
    status, page = client.get(path)
    r.check(f"{what} opens", status == 200, f"status {status}")
    return page, form_fields(page)


def run(client, r, site):
    # ------------------------------------------------------------- the index
    print("the index lists every page there is")

    page, _ = open_screen(client, r, ADMIN, "the index")

    routes = subprocess.run(
        ["php", "-r", "require 'lib/contract.php'; echo json_encode(SEO_ROUTES);"],
        cwd=ROOT, capture_output=True, text=True)
    names = [row[1] for row in json.loads(routes.stdout).values()]

    # Escaped as the page escapes it: "Branding & Advertisement" is written
    # "Branding &amp; Advertisement" in the HTML, and a check that missed that
    # would be testing the test.
    def shown(text: str) -> str:
        return (text.replace("&", "&amp;").replace("<", "&lt;")
                    .replace(">", "&gt;").replace('"', "&quot;"))

    for name in names:
        r.check(f"it lists {name}", shown(name) in page, name)

    services = [s["name"] for s in stored("services")["services"]["items"]]
    for name in services:
        r.check(f"and the {name} service, which is a row and not a file",
                shown(name) in page, name)

    r.check("the 404 is listed too, and says it has no address of its own",
            "served at every address that does not exist" in page)
    r.check("it says what happens when a page is not indexed",
            "re-crawl" in page)
    r.check("and links to the two site-wide screens",
            "site=identity" in page and "site=crawl" in page)

    # ------------------------------------------------------- one page's own
    print("\nediting one page, and only that one")

    about_before = stored("about")
    path = "/?s=seo&page=about"
    page, fields = open_screen(client, r, path, "the About page's settings")

    r.check("it says which file the words live in",
            "content/about.json" in page,
            "the screen should say the value is stored with the page")
    for band in ("band-search", "band-share", "band-crawl", "band-sitemap"):
        r.check(f"it has {band}", f'id="{band}"' in page)
    r.check("the title it shows is the one the document holds",
            fields["meta[title]"] == about_before["meta"]["title"],
            f'{fields.get("meta[title]")!r}')

    fields["meta[title]"] = "A Marked About Title"
    fields["meta[description]"] = "A marked description, long enough to be a real one and past the fifty characters a short one is refused under."
    fields["meta[breadcrumb]"] = "Marked Crumb"
    # Deliberately untidy: a repeat in a different case, an empty entry, and
    # runs of space. What comes back says whether contract_keywords() ran.
    fields["meta[keywords]"] = "Marked Word,, marked word ,  second   keyword , Marked Word"
    fields["meta[changefreq]"] = "daily"
    fields["meta[priority]"] = "0.4"
    status, headers, _body = save(client, path, fields)

    r.check("saving redirects rather than re-rendering", status == 302, f"status {status}")
    after = stored("about")
    r.check("the title reached content/about.json",
            after["meta"]["title"] == "A Marked About Title", str(after["meta"]))
    r.check("the keywords reached it, tidied into the one form the page emits",
            after["meta"]["keywords"] == "Marked Word, second keyword",
            f'{after["meta"]["keywords"]!r} — empties and repeats should be gone, '
            f'one space after each comma, the first spelling kept')
    r.check("so did the breadcrumb", after["meta"]["breadcrumb"] == "Marked Crumb")
    r.check("and the sitemap settings",
            after["meta"]["changefreq"] == "daily" and after["meta"]["priority"] == "0.4",
            str(after["meta"]))
    r.check("it reached the live site", doc(site, "about")["meta"]["title"] == "A Marked About Title")
    r.check("and only the about document was published",
            set(site.documents) == {"about"}, str(sorted(site.documents)))

    drift = still_holds(
        {k: v for k, v in about_before.items() if k not in ("meta", "updated", "revision")},
        {k: v for k, v in after.items() if k not in ("meta", "updated", "revision")})
    r.check("EVERY OTHER BAND OF THE DOCUMENT IS UNTOUCHED", drift == "",
            f"the screen held one band and rebuilt the whole document — {drift}")
    r.check("and the revision moved, so the live site knows it is newer",
            after["revision"] == about_before["revision"] + 1)

    # -------------------------------------------------------- one service
    print("\nediting one service, which is a row and not a file")

    services_before = stored("services")
    first = services_before["services"]["items"][0]
    path = f"/?s=seo&page=service:{first['id']}"
    page, fields = open_screen(client, r, path, "a service page's settings")

    r.check("it shows that service's own title",
            fields["meta[title]"] == first["meta"]["title"], fields.get("meta[title]"))

    fields["meta[title]"] = "A Marked Service Title"
    save(client, path, fields)

    after = stored("services")
    r.check("the title reached the service's row",
            after["services"]["items"][0]["meta"]["title"] == "A Marked Service Title")
    r.check("THE OTHER FIVE SERVICES KEPT THEIR TITLES",
            [s["meta"]["title"] for s in after["services"]["items"][1:]]
            == [s["meta"]["title"] for s in services_before["services"]["items"][1:]],
            "editing one service rewrote another's metadata")
    r.check("and that service's own content is untouched",
            {k: v for k, v in first.items() if k != "meta"}
            == {k: v for k, v in after["services"]["items"][0].items() if k != "meta"})

    # ----------------------------------------------------------- refusals
    print("\nwhat it refuses, and what it allows")

    path = "/?s=seo&page=careers"
    careers_before = (ROOT / "content" / "careers.json").read_bytes()
    page, fields = open_screen(client, r, path, "the Careers page's settings")

    r.check("the careers page has settings at all, which it never used to",
            fields["meta[title]"] != "",
            "its title and description were literal strings in the page file")

    limits = json.loads(subprocess.run(
        ["php", "-r", "require 'lib/contract.php'; echo json_encode(["
         "'title' => SEO_TITLE_MAX, 'min' => SEO_DESC_MIN, 'max' => SEO_DESC_MAX]);"],
        cwd=ROOT, capture_output=True, text=True).stdout)

    bad = dict(fields, **{"meta[title]": "x" * (limits["title"] + 1)})
    _s, _h, body = save(client, path, bad)
    r.check("an over-long title is refused", "cuts titles off" in body, body[:0] or "")

    bad = dict(fields, **{"meta[description]": "x" * (limits["max"] + 1)})
    _s, _h, body = save(client, path, bad)
    r.check("an over-long description is refused", "cut it off" in body)

    bad = dict(fields, **{"meta[description]": "x" * (limits["min"] - 1)})
    _s, _h, body = save(client, path, bad)
    r.check("and a description too short to be used is refused",
            "writes its own" in body)

    bad = dict(fields, **{"meta[title]": ""})
    _s, _h, body = save(client, path, bad)
    r.check("an empty title is refused", "cannot be empty" in body)

    bad = dict(fields, **{"meta[title]": stored("about")["meta"]["title"]})
    _s, _h, body = save(client, path, bad)
    r.check("A TITLE ALREADY USED BY ANOTHER PAGE IS REFUSED",
            "already used by" in body,
            "two pages with one title compete for the same search result, and "
            "no page's own editor could ever notice")

    # The whole file, not one field: a refusal that wrote SOMETHING would be
    # worse than one that wrote the wrong thing, and comparing one key would
    # not see it. The document has no stored meta band at all until something
    # saves it -- contract_normalise() supplies one on load -- which is exactly
    # why the comparison is of raw bytes.
    r.check("and none of those refusals wrote anything",
            (ROOT / "content" / "careers.json").read_bytes() == careers_before,
            "a refused save still touched the file")

    # ------------------------------------------------------------- noindex
    print("\ntaking a page out of search results")

    r.check("the control says what it does before it is used",
            "data-confirm=" in page and "re-crawl" in page,
            "an unguarded control has to explain itself")

    # And says WHICH page. The shell swaps screens without reloading, so a box
    # reading "this page" names whatever the reader happens to be looking at.
    r.check("and names the page it would take out of search",
            "Setting Careers to Not indexed" in page,
            "the confirmation says 'this page' rather than naming one")

    noindexed = dict(fields, **{"meta[robots]": "noindex"})
    status, _h, _b = save(client, path, noindexed)
    r.check("a page CAN be set to noindex — this is not a guarded control",
            status == 302, f"status {status}")
    r.check("and the document records it", stored("careers")["meta"]["robots"] == "noindex")

    _page2, _f = open_screen(client, r, ADMIN, "the index after")
    status, index_page = client.get(ADMIN)
    r.check("the index says so, on every later visit",
            "set to Not indexed" in index_page and "Careers" in index_page)

    # -------------------------------------------------------- the 404's own
    print("\nthe error page, which is the one page with no document")

    path = "/?s=seo&page=notfound"
    page, fields = open_screen(client, r, path, "the error page's settings")

    r.check("its record lives in the site-wide document",
            "content/seo.json" in page)
    r.check("it has no breadcrumb field: it has no place in a hierarchy",
            "meta[breadcrumb]" not in fields)
    r.check("nor a sitemap row to tune", "meta[changefreq]" not in fields)
    r.check("nor a crawl setting, because an error page that could be indexed "
            "is a bug", "meta[robots]" not in fields)
    r.check("and it says why", "served at every address that does not exist" in page)

    fields["meta[title]"] = "A Marked Not-Found Title"
    save(client, path, fields)
    r.check("its title reached content/seo.json",
            stored("seo")["notfound"]["title"] == "A Marked Not-Found Title")
    r.check("and it is still noindex, whatever was posted",
            stored("seo")["notfound"]["robots"] == "noindex")

    # ------------------------------------------------------ the site's own
    print("\nthe site-wide record every page carries")

    path = "/?s=seo&site=identity"
    page, fields = open_screen(client, r, path, "the identity screen")

    for band in ("band-site", "band-identity", "band-sameas", "band-hours"):
        r.check(f"it has {band}", f'id="{band}"' in page)
    r.check("it posts as multipart, or no share card could ever be attached",
            'enctype="multipart/form-data"' in page)
    r.check("it says the addresses are not here",
            "come from the Contact editor" in page)

    r.check("the two profiles it ships with are on the screen",
            len(re.findall(r'name="sameas\[items\]\[\d+\]\[url\]"', page)) == 2)
    r.check("and the two opening-hours rows",
            len(re.findall(r'name="hours\[items\]\[\d+\]\[opens\]"', page)) == 2)

    fields["site[name]"] = "Marked Site Name"
    fields["identity[slogan]"] = "A marked slogan"
    fields["identity[service_types]"] = "One service\nAnother service"
    save(client, path, fields)

    seo = stored("seo")
    r.check("the site name reached content/seo.json",
            seo["site"]["name"] == "Marked Site Name")
    r.check("a list typed one per line became a list",
            seo["identity"]["service_types"] == ["One service", "Another service"],
            str(seo["identity"]["service_types"]))
    r.check("and it reached the live site",
            doc(site, "seo")["site"]["name"] == "Marked Site Name")

    print("\nadding, hiding and reordering a profile")

    page, fields = open_screen(client, r, path, "the identity screen")
    before = len(stored("seo")["sameas"]["items"])
    page = press(client, r, path, fields, "sameas-add:0", "adding a profile")
    fields = form_fields(page)
    r.check("a row is added to the form",
            len(re.findall(r'name="sameas\[items\]\[\d+\]\[url\]"', page)) == before + 1)
    r.check("and it arrives hidden, so nothing empty is published",
            fields[f"sameas[items][{before}][status]"] == "hidden",
            fields.get(f"sameas[items][{before}][status]"))
    r.check("pressing Add did not write anything",
            len(stored("seo")["sameas"]["items"]) == before)

    fields[f"sameas[items][{before}][label]"] = "Marked Profile"
    fields[f"sameas[items][{before}][url]"] = "https://example.com/marked"
    fields[f"sameas[items][{before}][status]"] = "shown"
    save(client, path, fields)
    rows = stored("seo")["sameas"]["items"]
    r.check("saving keeps it", len(rows) == before + 1)
    r.check("and it is given an id of its own",
            rows[-1]["id"] not in ("", None) and rows[-1]["id"] != rows[0]["id"],
            str([row["id"] for row in rows]))

    page, fields = open_screen(client, r, path, "the identity screen")
    page = press(client, r, path, fields, f"sameas-up:{before}", "moving it up")
    fields = form_fields(page)
    r.check("the row moved",
            fields[f"sameas[items][{before - 1}][label]"] == "Marked Profile",
            fields.get(f"sameas[items][{before - 1}][label]"))

    page = press(client, r, path, fields, f"sameas-remove:{before - 1}", "removing it")
    r.check("and it can be removed",
            len(re.findall(r'name="sameas\[items\]\[\d+\]\[url\]"', page)) == before)

    print("\nwhat the identity screen refuses")

    page, fields = open_screen(client, r, path, "the identity screen")
    _s, _h, body = save(client, path, dict(fields, **{"site[name]": ""}))
    r.check("an empty site name is refused", "site name cannot be empty" in body)
    _s, _h, body = save(client, path, dict(fields, **{"site[theme_light]": "white"}))
    r.check("a colour that is not a hex colour is refused", "hex colour" in body)
    _s, _h, body = save(client, path, dict(fields, **{"identity[founded]": "2018"}))
    r.check("a founding date that is not a date is refused", "YYYY-MM-DD" in body)

    # ---------------------------------------------------------- the crawl
    print("\nrobots.txt and the installed app")

    path = "/?s=seo&site=crawl"
    page, fields = open_screen(client, r, path, "the crawl screen")

    for band in ("band-verify", "band-analytics", "band-robots", "band-manifest"):
        r.check(f"it has {band}", f'id="{band}"' in page)
    r.check("it says why a search console matters",
            "Search Console" in page)
    r.check("and that blocking a page is not the same as hiding it",
            "is never read" in page)

    fields["crawl[verify_google]"] = "marked-google-token"
    fields["crawl[robots_extra]"] = "/contact-handler.php\n/private-thing"
    save(client, path, fields)

    seo = stored("seo")
    r.check("the verification token is stored",
            seo["crawl"]["verify_google"] == "marked-google-token")
    r.check("and the extra rules, one per line",
            seo["crawl"]["robots_extra"] == ["/contact-handler.php", "/private-thing"],
            str(seo["crawl"]["robots_extra"]))

    _s, _h, body = save(client, path, dict(fields, **{"crawl[robots_extra]": "/"}))
    r.check("A RULE THAT WOULD BLOCK THE WHOLE SITE IS REFUSED",
            "remove the whole site" in body,
            "every other crawl control is unguarded; this one is the off switch")
    _s, _h, body = save(client, path, dict(fields, **{"crawl[robots_extra]": "admin"}))
    r.check("and a rule that is not a path is refused", "beginning with" in body)

    # ------------------------------------------------------ google analytics
    #
    # THE ONE FIELD ON THIS SITE THAT REACHES ANOTHER COMPANY'S SERVERS. What
    # is checked is the whole of that: that a real id is stored, that a wrong
    # one is REFUSED OUT LOUD rather than quietly blanked, and that nothing
    # shaped like an escape from the <script src> it lands in survives.
    r.check("the screen says what turning analytics on costs",
            "No cookies, no analytics, no tracking" in page and "EU" in page,
            "the privacy policy says one thing and this would make it another; "
            "the screen has to say so before somebody finds out later")

    save(client, path, dict(fields, **{"crawl[analytics_id]": "G-ABC1234567"}))
    r.check("a measurement id is stored",
            stored("seo")["crawl"]["analytics_id"] == "G-ABC1234567",
            str(stored("seo")["crawl"]["analytics_id"]))

    for bad in ('G-A"onload=x', "https://evil.example/x", "G-<script>", "nonsense"):
        _s, _h, body = save(client, path, dict(fields, **{"crawl[analytics_id]": bad}))
        r.check(f"{bad!r} is refused, and said so",
                "does not look like a Google measurement id" in body,
                "a rejected id must not be swallowed in silence")
        r.check(f"and {bad!r} did not reach the document",
                stored("seo")["crawl"]["analytics_id"] == "G-ABC1234567",
                "a refused save must leave what was there")

    save(client, path, dict(fields, **{"crawl[analytics_id]": ""}))
    r.check("and clearing it switches analytics off again",
            stored("seo")["crawl"]["analytics_id"] == "",
            "empty is the state the site ships in, and the way back to it")

    print("\nno OTHER editor writes the meta band any more")
    # THE FAILURE THIS PREVENTS IS SILENT AND TOTAL. Every *_from_post()
    # rebuilds each band named in its *_TEXT_FIELDS from $_POST. A page editor
    # that has stopped RENDERING the meta fieldset while still naming the band
    # in that loop reads $_POST['meta']['title'] as absent, takes '' for it,
    # and blanks the page's title on every save -- silently, because empty is a
    # valid value and nothing throws. contract_page_bands() is what stops that.
    #
    # So: open each page editor, save it without touching anything, and require
    # its document's meta band to come back byte for byte.
    # KEPT, not identical. A document that has not been saved since the meta
    # band grew has none of the new keys on disk -- contract_normalise() gives
    # them their defaults on load -- so the first save through any editor
    # writes them out. That is the additive change materialising, and it is
    # correct. What must never happen is a value that WAS there coming back
    # different, which is what blanking looks like.
    def kept(before: dict, after: dict) -> list[str]:
        return [k for k, v in before.items() if after.get(k, "\0GONE") != v]

    for screen, document in (("/?s=about", "about"),
                             ("/?s=certifications", "certifications"),
                             ("/?s=services", "services")):
        before = stored(document)["meta"]
        page, fields = open_screen(client, r, screen, f"the {document} editor")
        client.post(screen, {**fields, "do": "save"})
        after = stored(document)["meta"]
        lost = kept(before, after)
        r.check(f"{document}: saving its own editor changes nothing in the meta band",
                lost == [], f"{lost} changed: {before} -> {after}")

    # And one level deeper: a service row's meta, from the services editor.
    before = stored("services")["services"]["items"][0]["meta"]
    screen = "/?s=services&service=" + stored("services")["services"]["items"][0]["slug"]
    page, fields = open_screen(client, r, screen, "one service's editor")
    client.post(screen, {**fields, "do": "save"})
    after = stored("services")["services"]["items"][0]["meta"]
    lost = kept(before, after)
    r.check("a service: saving its own editor changes nothing in its meta band",
            lost == [], f"{lost} changed: {before} -> {after}")

    print("\nand it refuses a request without a token")
    status, _h, _b = client.post(path, {**fields, "do": "save", "csrf": "0" * 64})
    r.check("a bad CSRF token is refused", status in (400, 403), f"status {status}")


def main():
    backup = {p: p.read_bytes() for p in DATA if p.is_file()}
    port = free_port()

    # The accounts, sessions and counters go somewhere disposable, so this run
    # cannot disturb whatever account is used locally.
    work = Path(tempfile.mkdtemp(prefix="t4t-seo-"))
    private = work / "private"

    # The far side. A stub, not the other repository's endpoint — see
    # tools/publish_stub.py for why that distinction is the point.
    key = bytes.fromhex("a4" * 32)
    private.mkdir(mode=0o700, parents=True, exist_ok=True)
    (private / "publish.key").write_text(key.hex() + "\n")

    r = Results()

    with PublishStub(key) as site:
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(DOCROOT), str(ROUTER)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
            env=dict(os.environ, T4T_PRIVATE=str(private),
                     T4T_PUBLIC_URL=site.url, T4T_PUBLISH_URL=""),
        )
        try:
            base = f"http://127.0.0.1:{port}"
            for _ in range(80):
                try:
                    urllib.request.urlopen(base + "/login.php", timeout=1)
                    break
                except Exception:
                    time.sleep(0.15)

            secret = admin_session.make_account(private)
            client = Client(base)
            admin_session.sign_in(client.opener, base, secret)
            run(client, r, site)
        finally:
            stop(server)
            shutil.rmtree(work, ignore_errors=True)
            for path, bytes_ in backup.items():
                path.write_bytes(bytes_)
                for stray in (path.with_suffix(".json.bak"),
                              path.with_suffix(".json.moved")):
                    stray.unlink(missing_ok=True)
            print("\n" + ", ".join(sorted(p.relative_to(ROOT).as_posix()
                                          for p in backup)) + " restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    print(f"\n{r.passed}/{total} checks passed")


if __name__ == "__main__":
    main()
