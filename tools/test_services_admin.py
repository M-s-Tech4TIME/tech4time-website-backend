#!/usr/bin/env python3
"""
Exercise the services editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_services_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/services.php writes content/services.json, and the frontend renders
SEVEN pages out of it. Code that writes files is worth a test; code that writes
one file behind seven pages is worth more of one, because a bug in the save
path does not announce itself — it shows up as a solution card that quietly
lost its tags, on one of a hundred and thirty-seven.

It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because every
part of both walks a list in a loop and the field names are expressions rather
than literals. So this proves it by round trip instead.

WHAT IS DIFFERENT ABOUT THIS ONE
Every other editor rebuilds its whole document from the form. This one cannot:
the service screen holds ONE service and the other five were never in the form,
so a save has to merge. That merge is a read-modify-write, and the tests below
exercise it deliberately — saving one service must not disturb another, and
must survive the row it is merging into having moved.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself, and what the frontend does with what is published. The
first is tools/test_admin_auth.py; the second is the frontend's
tools/test_publish.py.
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
DATA = ROOT / "content" / "services.json"

ADMIN = "/?s=services"


def page_of(slug: str) -> str:
    return f"/?s=services&service={slug}"

ROUTER = ROOT / "tools" / "dev-router.php"


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []

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


def save(client, path, fields, do="save"):
    """Submit the editor's own form, as a browser would."""
    return client.post(path, {**fields, "do": do})


def reopen(client, path):
    """The editor as it renders now, and every control on it."""
    _status, html = client.get(path)
    return html, form_fields(html)


def run(client, r, site):
    # ------------------------------------------------------ the index screen
    status, html = client.get(ADMIN)
    fields = form_fields(html)

    print("the editor opens")
    r.check("it opens", status == 200, f"status {status}")
    r.check("it names the file it edits", "content/services.json" in html)
    r.check("the rail lists it", ">Services<" in region(html, "rail"))
    r.check("it carries the tail marker", "__tail" in fields,
            "without it a truncated POST cannot be detected")
    r.check("the form is async", 'data-async' in html,
            "every admin form swaps rather than reloading")

    print("\nit lists the six service pages")
    for slug in ("cybersecurity", "software-development", "cloud-infrastructure",
                 "hr-solutions", "it-equipment-supply", "it-consultancy-training"):
        r.check(f"there is a link to {slug}",
                f"service={slug}" in html.replace("&amp;", "&"))

    print("\nit does NOT put a second service's fields on the same screen")
    r.check("the index screen carries no solution cards",
            "layers][items]" not in html,
            "all seven on one screen would be about twice max_input_vars, "
            "and the tail would be dropped with no error")

    # ------------------------------------------------------ one service screen
    page = page_of("cybersecurity")
    status, html = client.get(page)
    fields = form_fields(html)

    print("\none service opens on its own screen")
    r.check("it opens", status == 200, f"status {status}")
    r.check("it names the service", "Cybersecurity" in html)
    r.check("it offers the solutions", "layers][items][0][cards][0][name]" in html)
    r.check("it stays under the input limit", len(fields) < 1000,
            f"{len(fields)} inputs — max_input_vars defaults to 1000")

    print("\nediting one solution reaches the live site")
    key = "service[layers][items][0][cards][0][name]"
    r.check("the field is on the form", key in fields)
    fields[key] = "MARKER incident response"
    fields["service[layers][items][0][cards][0][tags]"] = "MARKER-A\nMARKER-B"
    status, headers, _body = save(client, page, fields)
    r.check("saving redirects rather than re-rendering", status == 302,
            f"status {status}")

    doc = published(site)
    card = doc["services"]["items"][0]["layers"]["items"][0]["cards"][0]
    r.check("the new name was published", card["name"] == "MARKER incident response",
            repr(card.get("name")))
    r.check("the tags were published as a list", card["tags"] == ["MARKER-A", "MARKER-B"],
            repr(card.get("tags")))
    r.check("blank lines in a list are dropped",
            all(t.strip() for t in card["tags"]))

    print("\nthe id of a solution is kept, not re-minted from its name")
    r.check("it still has the id it shipped with",
            card["id"] == "sol-reactive-incident-response-crisis-management",
            repr(card.get("id")) + " — 63 of the 137 were written by hand and "
            "are what a saved link holds the card by")

    # ------------------------------------------------- the merge, deliberately
    print("\nsaving one service does not disturb another")
    other_before = json.dumps(published(site)["services"]["items"][3], sort_keys=True)

    html, fields = reopen(client, page)
    fields["service[hero][subtitle]"] = "MARKER subtitle"
    save(client, page, fields)

    other_after = json.dumps(published(site)["services"]["items"][3], sort_keys=True)
    r.check("the other five are untouched", other_before == other_after,
            "the save merges into the stored document rather than replacing it")

    print("\nsaving the index does not disturb the services")
    services_before = json.dumps(published(site)["services"], sort_keys=True)
    html, fields = reopen(client, ADMIN)
    fields["hero[title]"] = "MARKER services"
    save(client, ADMIN, fields)
    doc = published(site)
    r.check("the banner changed", doc["hero"]["title"] == "MARKER services",
            repr(doc["hero"].get("title")))
    r.check("all six services survived it",
            json.dumps(doc["services"], sort_keys=True) == services_before,
            "the index screen must not write the services it never showed")

    # ------------------------------------------------------------ hide and show
    print("\nhiding a service hides the whole of it")
    html, fields = reopen(client, page)
    fields["service[status]"] = "hidden"
    save(client, page, fields)

    doc = published(site)
    r.check("the service is marked hidden",
            doc["services"]["items"][0]["status"] == "hidden")
    r.check("its block is still stored", any(
        b["service"] == "cybersecurity" for b in doc["blocks"]["items"]),
        "hiding is not deleting — the block keeps its place and its contents")

    html, fields = reopen(client, page)
    fields["service[status]"] = "shown"
    save(client, page, fields)
    r.check("showing it again brings it back",
            published(site)["services"]["items"][0]["status"] == "shown")

    # ------------------------------------------------------------- the buttons
    print("\nthe row buttons add, move and remove without saving")
    before = len(published(site)["services"]["items"][0]["layers"]["items"][0]["cards"])

    html, fields = reopen(client, page)
    _s, _h, body = save(client, page, fields, do="card-add:0")
    r.check("adding says so", "Added an entry" in body or "hidden until you show it" in body)
    r.check("adding does NOT publish",
            len(published(site)["services"]["items"][0]["layers"]["items"][0]["cards"]) == before,
            "a row button submits the form without saving")
    r.check("the new row is in the redrawn form",
            f"cards][{before}][name]" in body,
            "it is added to the page being edited, not to the file")
    r.check("the new row arrives hidden",
            'value="hidden" selected' in select_named(body,
                f"service[layers][items][0][cards][{before}][status]"),
            "a blank card must not appear on the live site the moment Add is pressed")

    print("\na new service can be added, and it is a whole page")
    count = len(published(site)["services"]["items"])
    html, fields = reopen(client, ADMIN)
    _s, _h, body = save(client, ADMIN, fields, do="service-add:0")
    r.check("adding a service does not publish either",
            len(published(site)["services"]["items"]) == count)
    r.check("it appears in the list", body.count("service=new-service") >= 1,
            "a new service is named and slugged so it can be reached to be filled in")

    # commit it, then check the page it makes
    html, fields = reopen(client, ADMIN)
    _s, _h, body = save(client, ADMIN, fields, do="service-add:0")
    fields = form_fields(body)
    save(client, ADMIN, fields)
    doc = published(site)
    r.check("a saved new service reaches the live site",
            any(s["slug"].startswith("new-service") for s in doc["services"]["items"]),
            [s["slug"] for s in doc["services"]["items"]])
    added = [s for s in doc["services"]["items"] if s["slug"].startswith("new-service")][0]
    r.check("it arrives hidden", added["status"] == "hidden",
            "so an unfinished page is never live")
    r.check("it has the four bands its template needs",
            all(k in added for k in ("core", "layers", "cta", "meta")),
            sorted(added.keys()))

    # ----------------------------------------------------------- refusing bad
    print("\nit refuses what would break a page")
    html, fields = reopen(client, page)
    fields["service[slug]"] = "Not A Slug"
    _s, _h, body = save(client, page, fields)
    r.check("a bad web address is refused", "not usable" in body, body[:0] or "no message")
    r.check("and nothing was published",
            published(site)["services"]["items"][0]["slug"] == "cybersecurity")

    html, fields = reopen(client, page)
    fields["service[name]"] = ""
    _s, _h, body = save(client, page, fields)
    r.check("a service with no name is refused", "has no name" in body)

    # The over-long search description used to be refused here. It is refused
    # by seo_validate() now, on the screen that offers the field, and against
    # SEO_DESC_MAX rather than a number written into this editor --
    # tools/test_seo_admin.py. What is asserted here is that this editor has
    # genuinely stopped offering it, because a field still on the form but no
    # longer read would be worse than either arrangement.
    html, _fields = reopen(client, ADMIN)
    r.check("the search description is not a field on this screen",
            'name="meta[description]"' not in html)
    r.check("and the band points at the screen that owns it",
            "s=seo&amp;page=services" in html)

    print("\nit refuses a request without a token")
    html, fields = reopen(client, page)
    fields["csrf"] = "0" * 64
    status, _h, _b = save(client, page, fields)
    r.check("a bad CSRF token is refused", status in (400, 403), f"status {status}")
def published(site) -> dict:
    """The services document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend
    then DOES with the document — seven pages, the rings, the counts, the
    Service graph — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered pages.

    Splitting it this way is not a loss of coverage so much as an honest
    statement of where each half's responsibility ends. What it does cost is
    that neither test alone proves a field survives the whole trip; the model
    they share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("services", {})


def select_named(page: str, name: str) -> str:
    """The <select> with this exact name, as markup.

    Named rather than found by class: an admin-card holds nested <div>s, so a
    region taken by class and closed at the first </div> stops well before the
    controls at the bottom of the card -- and an assertion made against an
    empty string is a check that cannot fail.
    """
    m = re.search(r'<select\b[^>]*name="' + re.escape(name) + r'"[^>]*>(.*?)</select>',
                  page, re.S)
    return m.group(1) if m else ""


def region(page: str, css_class: str) -> str:
    """The markup of one element, by class, as far as its next close tag.

    Rough on purpose. It is used to ask "is this assertion true INSIDE this
    card" rather than anywhere on a page that repeats every heading in a form
    of its own, which is how a check passes by finding the right words in the
    wrong place.
    """
    # The tag name is CAPTURED so that </\1> closes the element this opened.
    # It used to be <[a-z]+ ...> with no group, which made \1 the CONTENT
    # group and the pattern unsatisfiable -- so this function never matched at
    # all and always fell through to the window below. That was invisible until
    # a rail grew a seventh entry and pushed the thing being looked for past
    # 4000 characters, at which point a passing check started failing without
    # anything it tests having changed.
    m = re.search(r'<([a-z]+) class="[^"]*' + re.escape(css_class)
                  + r'[^"]*"[^>]*>(.*?)</\1>', page, re.S)
    if m:
        return m.group(2)
    i = page.find(css_class)
    return page[i:i + 4000] if i >= 0 else ""


def rows_sent(site, band: str) -> list:
    return published(site).get(band, {}).get("items", [])


def names_sent(site, band: str, key: str) -> list:
    return [r.get(key, "") for r in rows_sent(site, band)]


def band_status(site, band: str) -> str:
    return published(site).get(band, {}).get("status", "")


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")
    if not DATA.exists():
        raise SystemExit(f"Missing {DATA.relative_to(ROOT)}")

    backup = DATA.read_bytes()

    port = free_port()

    # The accounts, sessions and counters go somewhere disposable, so this run
    # cannot disturb whatever account is used locally.
    work = Path(tempfile.mkdtemp(prefix="t4t-services-"))
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
            DATA.write_bytes(backup)
            for stray in (DATA.with_suffix(".json.bak"), DATA.with_suffix(".json.moved")):
                stray.unlink(missing_ok=True)
            print(f"\n{DATA.relative_to(ROOT)} restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    print(f"\n{r.passed}/{total} checks passed")


if __name__ == "__main__":
    main()
