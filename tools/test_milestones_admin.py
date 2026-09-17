#!/usr/bin/env python3
"""
Exercise the milestones editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_milestones_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/milestones.php writes content/milestones.json, and TWO pages on the
live site render what it finds there — /pages/milestones/ shows the whole
timeline and /pages/company-profile/ shows the most recent MILESTONES_WINDOW
years of it. Code that writes files is worth a test; code that writes a file
two pages read is worth a careful one.

It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because the
form and both pages walk MILESTONES_LISTS in a loop and the field names are
expressions rather than literals. So this proves it by round trip instead.

THE CASE THIS SUITE EXISTS FOR ABOVE ALL THE OTHERS is the read-through. Until
the first save the timeline still lives in content/company.json, where it was
written, and milestones_load() reads through to it — so that no deploy can land
in a state where the company profile has no timeline on it. That fallback has
to fire exactly until the first save and never again, and both halves of that
are asserted below.

Every test runs against a COPY of the two data files it touches, restored
afterwards whether the run passes or fails — including the case that matters
here, where content/milestones.json did not exist when the run started and must
not exist when it ends.

WHAT IT CANNOT COVER
The sign-in itself, and what the frontend then renders. The first is the
subject of tools/test_admin_auth.py; the second of the frontend's
tools/test_publish.py. This half ends at the publish.
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
DATA = ROOT / "content" / "milestones.json"
COMPANY = ROOT / "content" / "company.json"

ADMIN = "/?s=milestones"

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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


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


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    if not m:
        raise SystemExit("No CSRF token in the editor — it did not render.")
    return m.group(1)


def unescape(value: str) -> str:
    return (value.replace("&lt;", "<").replace("&gt;", ">")
                 .replace("&quot;", '"').replace("&#039;", "'")
                 .replace("&amp;", "&"))


def form_fields(html: str) -> dict:
    """Every named control in the editor, as the browser would submit it.

    Reading them out of the page rather than writing them by hand is what makes
    this a test of the editor: a field the form stops rendering disappears from
    the submission here too, and whatever depended on it fails.
    """
    fields = {}

    for tag in re.findall(r"<input\b[^>]*>", html):
        name = re.search(r'name="([^"]+)"', tag)
        if not name or 'type="submit"' in tag:
            continue
        # The failed-publish notice is a SEPARATE form on the same page, and
        # its only field is action=republish. A browser would never send it
        # with the editor's form; scraping the whole document would, and then
        # every save after a failed publish would silently become a republish.
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


def select_named(page: str, name: str) -> str:
    m = re.search(r'<select\b[^>]*name="' + re.escape(name) + r'"[^>]*>(.*?)</select>',
                  page, re.S)
    return m.group(1) if m else ""


def region(page: str, css_class: str) -> str:
    """The markup of one element, by class, as far as its next close tag."""
    m = re.search(r'<([a-z]+) class="[^"]*' + re.escape(css_class)
                  + r'[^"]*"[^>]*>(.*?)</\1>', page, re.S)
    if m:
        return m.group(2)
    i = page.find(css_class)
    return page[i:i + 4000] if i >= 0 else ""


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


def published(site) -> dict:
    """The milestones document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend then
    DOES with the document — the two pages, the five-year window, the
    CollectionPage graph — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered pages.
    """
    return site.documents.get("milestones", {})


def rows_sent(site) -> list:
    return published(site).get("timeline", {}).get("items", [])


def company_rows() -> list:
    return json.loads(COMPANY.read_text())["milestones"]["items"]


# ------------------------------------------------------------------- tests


def run(client, r, site):
    before = company_rows()

    status, html = client.get(ADMIN)
    token = csrf_of(html)

    print("the editor opens")
    r.check("it opens", status == 200, f"status {status}")
    r.check("it names the file it edits", "content/milestones.json" in html)
    r.check("the rail lists it", ">Milestones<" in region(html, "rail"),
            "the editor is unreachable if the rail does not carry it")
    r.check("and marks the one showing",
            'aria-current="page"' in html
            and "Milestones" in html[html.index('aria-current="page"'):][:300])

    print("\nthe read-through, before anything has been saved")
    r.check("content/milestones.json does not exist yet", not DATA.exists(),
            "this suite is about the state BEFORE the first save")
    r.check("the form holds every entry the company document does",
            html.count('name="timeline[items][') > 0
            and html.count(f'name="timeline[items][{len(before) - 1}][id]"') == 1
            and f'name="timeline[items][{len(before)}][id]"' not in html,
            f"{len(before)} entries in content/company.json")
    r.check("with their years",
            all(f'value="{row["year"]}"' in html for row in before),
            str([row["year"] for row in before]))
    r.check("and the heading came across too, not just the rows",
            f'value="{json.loads(COMPANY.read_text())["milestones"]["title"]}"' in html,
            "carrying the entries and leaving the words behind would have "
            "published the shipped default over an edited heading")

    print("\nevery band of the page is in the form")
    for band, needle in [
        ("banner", 'name="hero[title]"'),
        ("the timeline's heading", 'name="timeline[title]"'),
        ("its eyebrow", 'name="timeline[eyebrow]"'),
        ("its introduction", 'name="timeline[lead]"'),
        ("an entry's year", 'name="timeline[items][0][year]"'),
        # NOT a field. The meta band is a link to the SEO screen, which edits
        # every page's title and description in one place.
        ("search wording", "s=seo&amp;page="),
    ]:
        r.check(f"the {band}", needle in html, needle)

    print("\nthe controls the shell needs")
    r.check("pressing Enter would save, not add a row",
            html.index('value="save"') < html.index('-add:0'),
            "the first submit button in the document is the one Enter presses")
    r.check("the form is async", 'id="milestones-form"' in html and "data-async" in html,
            "without data-async every button reloads the whole admin")
    r.check("every band the outline names has a fieldset",
            all(f'id="{a}"' in html for a in
                ("band-hero", "band-timeline", "band-meta")))
    r.check("the row buttons are named for their band",
            'value="timeline-add:0"' in html,
            "admin-forms.js finds a new row by matching this prefix to the field names")
    r.check("it does NOT accept a file",
            'enctype="multipart/form-data"' not in html,
            "an entry carries no picture, so the form should not claim to take one")

    # ------------------------------------------------------------ saving
    print("\nwhat a save sends to the live site")
    good = dict(form_fields(html), csrf=token, do="save")

    saved = dict(good)
    saved["hero[title]"] = "Our Milestones"
    saved["timeline[items][1][title]"] = "First Government Contract"
    status, _, _ = client.post(ADMIN, saved)
    r.check("saving redirects rather than re-rendering", status == 302, f"status {status}")

    doc = published(site)
    r.check("the new banner reaches the live site", doc["hero"]["title"] == "Our Milestones",
            str(doc["hero"]))
    r.check("so does the edited entry",
            rows_sent(site)[1]["title"] == "First Government Contract",
            str(rows_sent(site)[1]))
    r.check("every entry travelled, not just the edited one",
            len(rows_sent(site)) == len(before), str(len(rows_sent(site))))
    r.check("the introduction survived the round trip as markup",
            rows_sent(site) and doc["timeline"]["lead"].startswith("<p>"),
            doc["timeline"]["lead"][:60])
    r.check("a revision was minted", doc["revision"] >= 1, str(doc.get("revision")))

    print("\nand the read-through has stopped")
    r.check("the file exists now", DATA.exists())
    stored = json.loads(DATA.read_text())
    r.check("it holds the rows", len(stored["timeline"]["items"]) == len(before),
            str(len(stored["timeline"]["items"])))
    r.check("the company document was not touched", company_rows() == before,
            "the band it read through to is deprecated, not migrated — "
            "deleting it would change the meaning of every company.json "
            "already written")

    _, html = client.get(ADMIN)
    r.check("the editor now shows the saved entry, not the company one",
            'value="First Government Contract"' in html)

    # ---------------------------------------------------------- add a row
    print("\nadding an entry")
    _, html = client.get(ADMIN)
    token = csrf_of(html)
    fields = dict(form_fields(html), csrf=token, do="timeline-add:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("adding re-renders rather than redirecting", status == 200, f"status {status}")
    r.check("the new row is in the form",
            body.count('name="timeline[items][') > html.count('name="timeline[items]['))
    r.check("it arrives hidden, so a blank entry never reaches either page",
            'value="hidden" selected'
            in select_named(body, f"timeline[items][{len(before)}][status]"),
            "a blank entry appearing on two live pages is not what Add means")
    r.check("and nothing is written until the save",
            len(json.loads(DATA.read_text())["timeline"]["items"]) == len(before))

    # -------------------------------------------------------- the year rule
    print("\nwhat the editor refuses")
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["timeline[items][0][year]"] = "the year we started"
    status, _, body = client.post(ADMIN, fields)
    r.check("a year that is not a year is refused", status == 200, f"status {status}")
    r.check("and it says which entry and why",
            "Milestone 1" in body and "is not a year" in body)
    r.check("nothing was published", rows_sent(site)[0]["year"] == before[0]["year"],
            str(rows_sent(site)[0]["year"]))

    for good_year in ("2024", "2024–2025", "2024-2025"):
        fields = dict(form_fields(html), csrf=token, do="save")
        fields["timeline[items][0][year]"] = good_year
        status, _, _ = client.post(ADMIN, fields)
        r.check(f"“{good_year}” is accepted", status == 302, f"status {status}")

    fields = dict(form_fields(html), csrf=token, do="save")
    fields["timeline[items][0][year]"] = ""
    fields["timeline[items][0][title]"] = ""
    status, _, body = client.post(ADMIN, fields)
    r.check("an entry with neither a year nor a title is refused",
            status == 200 and "neither a year nor a title" in body, f"status {status}")

    # ------------------------------------------------------------ reorder
    print("\nreordering and hiding")
    _, html = client.get(ADMIN)
    token = csrf_of(html)
    # Read the order out of what the site currently holds rather than out of
    # `before`: the year rule above deliberately rewrote the first entry's
    # year, so the company document's order is no longer this document's.
    was = [row["year"] for row in rows_sent(site)][:2]
    fields = dict(form_fields(html), csrf=token, do="timeline-down:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("moving re-renders rather than redirecting", status == 200, f"status {status}")

    fields = dict(form_fields(body), csrf=token, do="save")
    status, _, _ = client.post(ADMIN, fields)
    r.check("the new order reaches the live site",
            [row["year"] for row in rows_sent(site)][:2] == [was[1], was[0]],
            f"{was} became {[row['year'] for row in rows_sent(site)][:2]}")

    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=csrf_of(html), do="save")
    fields["timeline[items][0][status]"] = "hidden"
    client.post(ADMIN, fields)
    r.check("a hidden entry is still published, marked hidden",
            rows_sent(site)[0]["status"] == "hidden",
            "hiding is not deleting — the row keeps its place and its contents")

    print("\nhiding the whole band")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=csrf_of(html), do="save")
    fields["timeline[status]"] = "hidden"
    client.post(ADMIN, fields)
    r.check("the band's own switch travels",
            published(site)["timeline"]["status"] == "hidden",
            "it takes the timeline off BOTH pages, which is what the blurb says")

    # ------------------------------------------------------------- remove
    print("\nremoving an entry")
    _, html = client.get(ADMIN)
    token = csrf_of(html)
    held = len(json.loads(DATA.read_text())["timeline"]["items"])
    fields = dict(form_fields(html), csrf=token, do="timeline-remove:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("removing re-renders rather than redirecting", status == 200, f"status {status}")
    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("one fewer reaches the live site", len(rows_sent(site)) == held - 1,
            f"{len(rows_sent(site))} of {held}")

    # THE LAST CASE ON PURPOSE: it empties the list, and nothing after it would
    # have anything to work on. Keying the read-through on an empty list rather
    # than on the revision would hand every entry straight back here, which is
    # the editor refusing to do what it was told.
    print("\nan emptied timeline stays empty")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=csrf_of(html), do="save")
    for key in list(fields):
        if key.startswith("timeline[items]["):
            del fields[key]
    status, _, _ = client.post(ADMIN, fields)
    r.check("emptying it saves", status == 302, f"status {status}")
    r.check("and nothing came back", rows_sent(site) == [], str(rows_sent(site)))
    _, html = client.get(ADMIN)
    r.check("the form is empty too", 'name="timeline[items][0][id]"' not in html)


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")
    if not COMPANY.exists():
        raise SystemExit(f"Missing {COMPANY.relative_to(ROOT)} — the read-through "
                         f"reads it, so this suite cannot run without it")

    # THE ABSENCE IS THE BACKUP. Unlike every other editor suite, the file this
    # one writes may legitimately not exist when the run starts — that is the
    # state the read-through is for — so "restore" has to be able to mean
    # "delete it again".
    had = DATA.exists()
    backup = DATA.read_bytes() if had else b""
    company_backup = COMPANY.read_bytes()

    port = free_port()

    work = Path(tempfile.mkdtemp(prefix="t4t-milestones-"))
    private = work / "private"

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
            if had:
                DATA.write_bytes(backup)
            else:
                DATA.unlink(missing_ok=True)
            COMPANY.write_bytes(company_backup)
            for stray in (Path(str(DATA) + ".bak"), Path(str(COMPANY) + ".bak")):
                stray.unlink(missing_ok=True)
            print(f"\n{DATA.relative_to(ROOT)} restored"
                  f" ({'contents' if had else 'removed again'})")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    print(f"\n{r.passed}/{total} checks passed")


if __name__ == "__main__":
    main()
