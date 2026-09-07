#!/usr/bin/env python3
"""
Exercise the company profile editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_contact_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/company.php writes content/company.json, and the frontend's
pages/company-profile/index.php renders whatever it finds there. Code that writes files
is worth a test: a bug in the save path does not announce itself, it shows up
as an office that quietly lost its phone number.

The point of most of what follows is not that the editor accepted a change —
it is that the change reached the LIVE SITE, in the right shape. This half ends
at the publish; what the frontend then renders is proved there, by
tools/test_publish.py.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself. This harness creates an admin account in a throwaway
private directory and signs in through the real login page — so what is tested
here is the editor's behaviour once past it. The sign-in is the subject of
tools/test_admin_auth.py.
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
DATA = ROOT / "content" / "company.json"

ADMIN = "/?s=company"

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


def run(client, r, site):
    status, html = client.get(ADMIN)
    token = csrf_of(html)

    print("the editor opens")
    r.check("it opens", status == 200, f"status {status}")
    r.check("it names the file it edits", "content/company.json" in html)
    r.check("the rail lists it", ">Company Profile<" in region(html, "rail"),
            "the editor is unreachable if the rail does not carry it")
    r.check("and marks the one showing",
            'aria-current="page"' in html
            and "Company Profile" in html[html.index('aria-current="page"'):][:300])

    print("\nevery band of the page is in the form")
    for band, needle in [
        ("banner", 'name="hero[title]"'),
        ("milestones", 'name="milestones[title]"'),
        ("background", 'name="background[title]"'),
        ("figures", 'name="experience[title]"'),
        ("clients", 'name="clients[title]"'),
        ("photographs", 'name="journey[interval]"'),
        ("excellence", 'name="excellence[lead]"'),
        ("technology", 'name="technology[title]"'),
        ("principles", 'name="principles[title]"'),
        ("closing band", 'name="cta[label]"'),
        # NOT a field any more: the meta band is a link to the SEO screen,
        # which edits every page's title and description in one place. What is
        # asserted is that the band is still THERE and still points somewhere,
        # because the outline column still lists it and a person who knows
        # where that setting used to be should be told where it went.
        # NOT a field any more. The meta band is a link to the SEO screen,
        # which edits every page's title and description in one place. What is
        # asserted is that the band is still THERE and still points somewhere:
        # the outline column still lists it, and somebody who knows where that
        # setting used to be should be told where it went.
        ("search wording", "s=seo&amp;page="),
    ]:
        r.check(f"the {band} band", needle in html, needle)

    print("\nand every row of every list")
    for band, count in [("milestones", 7), ("experience", 4), ("clients", 9),
                        ("journey", 3), ("technology", 50), ("principles", 4)]:
        n = html.count(f'name="{band}[items][')
        r.check(f"{band}: {count} rows are in the form",
                html.count(f'[id]" value=') >= count and n > count,
                f"{n} inputs")

    r.check("pressing Enter would save, not add a row",
            html.index('value="save"') < html.index('-add:0'),
            "the first submit button in the document is the one Enter presses")

    # ------------------------------------------------------------ saving
    print("\nwhat a save sends to the live site")
    good = dict(form_fields(html), csrf=token, do="save")

    saved = dict(good)
    saved["hero[title]"] = "Who We Are"
    saved["milestones[items][0][year]"] = "2017"
    saved["milestones[items][0][title]"] = "It began"
    status, _, _ = client.post(ADMIN, saved)
    r.check("saving redirects rather than re-rendering", status == 302, f"status {status}")

    doc = published(site)
    r.check("the new banner reaches the live site", doc["hero"]["title"] == "Who We Are",
            str(doc["hero"]))
    r.check("so does the edited milestone",
            rows_sent(site, "milestones")[0]["year"] == "2017"
            and rows_sent(site, "milestones")[0]["title"] == "It began",
            str(rows_sent(site, "milestones")[0]))
    r.check("and nothing else moved",
            len(rows_sent(site, "technology")) == 50)
    r.check("a revision was minted", doc["revision"] >= 1, str(doc.get("revision")))

    # ---------------------------------------------------------- add a row
    print("\nadding an entry")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="milestones-add:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("adding re-renders rather than redirecting", status == 200, f"status {status}")
    r.check("the new row is in the form", body.count('name="milestones[items][') >
            html.count('name="milestones[items]['))
    r.check("it arrives hidden, so a blank card never reaches the site",
            'name="milestones[items][7][status]"' in body
            and 'value="hidden" selected' in region(body, "admin-card--hidden"),
            "a row with nothing in it must not be shown by pressing Add")
    r.check("and nothing is published until the page is saved",
            len(rows_sent(site, "milestones")) == 7,
            str(len(rows_sent(site, "milestones"))))

    print("\nfilling it in and saving")
    fields = dict(form_fields(body), csrf=token, do="save")
    fields["milestones[items][7][year]"] = "2025"
    fields["milestones[items][7][title]"] = "A new thing"
    fields["milestones[items][7][text]"] = "It happened."
    fields["milestones[items][7][status]"] = "shown"
    status, _, _ = client.post(ADMIN, fields)
    r.check("it saves", status == 302, f"status {status}")
    r.check("and reaches the live site", len(rows_sent(site, "milestones")) == 8,
            str(len(rows_sent(site, "milestones"))))
    r.check("carrying what was typed",
            rows_sent(site, "milestones")[7]["title"] == "A new thing",
            str(rows_sent(site, "milestones")[7]))
    r.check("and an id it was given rather than one it chose",
            rows_sent(site, "milestones")[7]["id"] == "2025-a-new-thing",
            str(rows_sent(site, "milestones")[7].get("id")))

    # ------------------------------------------------------------- hiding
    print("\nhiding, which is not deleting")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["clients[items][0][status]"] = "hidden"
    client.post(ADMIN, fields)

    hidden = rows_sent(site, "clients")[0]
    r.check("a hidden client is published carrying its status",
            hidden["status"] == "hidden", str(hidden))
    r.check("with everything it had", hidden["name"] != "" and hidden["image"]["src"] != "",
            "hiding must not empty the row")
    r.check("the others are untouched",
            [c["status"] for c in rows_sent(site, "clients")[1:]] == ["shown"] * 8)

    _, html = client.get(ADMIN)
    r.check("and it is still in the editor, marked hidden",
            'name="clients[items][0][status]"' in html
            and "admin-card--hidden" in html)

    fields = dict(form_fields(html), csrf=token, do="save")
    fields["clients[items][0][status]"] = "shown"
    client.post(ADMIN, fields)
    r.check("showing it again publishes it as shown",
            rows_sent(site, "clients")[0]["status"] == "shown")

    print("\nhiding a whole band")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["journey[status]"] = "hidden"
    client.post(ADMIN, fields)
    r.check("the band is published as hidden", band_status(site, "journey") == "hidden")
    r.check("its photographs are still there", len(rows_sent(site, "journey")) == 3,
            "hiding a band must not throw away what is in it")

    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["journey[status]"] = "shown"
    client.post(ADMIN, fields)
    r.check("and it comes back", band_status(site, "journey") == "shown")

    # ---------------------------------------------------------- reordering
    print("\nreordering")
    _, html = client.get(ADMIN)
    before = names_sent(site, "technology", "name")[:2]
    fields = dict(form_fields(html), csrf=token, do="technology-down:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("moving re-renders rather than redirecting", status == 200)
    r.check("a move is NOT saved until the page is",
            names_sent(site, "technology", "name")[:2] == before,
            "nothing may reach the site until Save is pressed")

    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("saving the move reorders the published document",
            names_sent(site, "technology", "name")[:2] == before[::-1],
            str(names_sent(site, "technology", "name")[:2]))
    r.check("and the sphere still has every logo",
            len(rows_sent(site, "technology")) == 50)

    # ------------------------------------------------------------ removing
    print("\nremoving")
    _, html = client.get(ADMIN)
    doomed = names_sent(site, "principles", "title")[3]
    fields = dict(form_fields(html), csrf=token, do="principles-remove:3")
    status, _, body = client.post(ADMIN, fields)
    r.check("removing re-renders", status == 200)
    r.check("and the row is gone from the form",
            body.count('name="principles[items][') < html.count('name="principles[items]['))

    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("saving publishes the removal", len(rows_sent(site, "principles")) == 3)
    r.check("and it was the right one",
            doomed not in names_sent(site, "principles", "title"))

    # ---------------------------------------------------------- the picture
    print("\nwhat a row may point at for a picture")
    _, html = client.get(ADMIN)
    for name, sent in [
        ("another origin", "https://evil.example/logo.png"),
        ("a protocol-relative URL", "//evil.example/logo.png"),
        ("a path climbing out of the site", "/assets/images/../../../etc/passwd"),
        ("somewhere that is not artwork", "/lib/contract.php"),
    ]:
        fields = dict(form_fields(html), csrf=token, do="save")
        fields["clients[items][1][image][src]"] = sent
        client.post(ADMIN, fields)
        got = rows_sent(site, "clients")[1]["image"]["src"]
        r.check(f"{name} is refused", got != sent, got)

    print("\nwhat it refuses to save")
    for case, field, value, expect in [
        ("an empty banner title", "hero[title]", "", "banner title cannot be empty"),
        ("a figure that does not start with a digit", "experience[items][0][figure]",
         "Over 100", "must start with a digit"),
        ("a year that is not a year", "milestones[items][0][year]", "ages ago", "not a year"),
        ("a client with no name", "clients[items][2][name]", "", "has no name"),
        ("a photograph with no description", "journey[items][0][alt]", "", "no description"),
        ("a javascript: link on the button", "cta[href]", "javascript:alert(1)",
         "not one this site will publish"),
    ]:
        _, html = client.get(ADMIN)
        fields = dict(form_fields(html), csrf=token, do="save")
        fields[field] = value
        status, _, body = client.post(ADMIN, fields)
        r.check(f"{case} is refused",
                status == 200 and expect in body, f"status {status}")

    r.check("and what was typed is still in the form after a refusal",
            'value="javascript:alert(1)"' in body)

    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token)
    status, _, _ = client.post(ADMIN, dict(fields, csrf="wrong", do="save"))
    r.check("a request without the token is refused", status == 400)

    sent = json.dumps(published(site))
    r.check("none of the refused values were published",
            "javascript:alert" not in sent and "evil.example" not in sent
            and "Over 100" not in sent,
            "a refused save must not reach the live site at all")

    # -------------------------------------------------------- republishing
    print("\nthe retry the failed-publish notice offers")
    before = json.loads(DATA.read_text())
    status, _, body = client.post(ADMIN, {
        "csrf": token, "s": "company", "action": "republish"})
    r.check("it redirects rather than re-rendering", status == 302, f"status {status}")
    r.check("so the operator is never shown an emptied form", "Not saved" not in body)
    after = json.loads(DATA.read_text())
    r.check("and the record is untouched", after == before,
            "a retry must not be able to empty the record it is retrying")

    # --------------------------------------------------------- empty state
    print("\nwhen every list is emptied")
    _, html = client.get(ADMIN)
    empty = dict(form_fields(html), csrf=token, do="save")
    removed = 0
    for key in list(empty):
        if re.match(r"(milestones|experience|clients|journey|technology|principles)"
                    r"\[items\]", key):
            del empty[key]
            removed += 1
    r.check("there were rows in the form to remove", removed > 100, f"{removed} fields")
    status, _, _ = client.post(ADMIN, empty)
    r.check("removing every row is allowed", status == 302, f"status {status}")
    for band in ("milestones", "experience", "clients", "journey",
                 "technology", "principles"):
        r.check(f"{band} publishes as empty", rows_sent(site, band) == [])
    r.check("and the page's own copy survives, so the live site still has a page",
            published(site)["hero"]["title"] != "", str(published(site)["hero"]))

    # ---------------------------------------------------- a missing data file
    print("\nwhen the data file is unreadable")
    DATA.rename(DATA.with_suffix(".json.moved"))
    try:
        status, html = client.get(ADMIN)
        r.check("the editor still answers", status == 200, f"status {status}")
        r.check("and falls back to the copy it shipped with",
                "Company Profile" in html,
                "company_load() must never throw — a missing file is an empty page, "
                "not a broken one")
    finally:
        DATA.with_suffix(".json.moved").rename(DATA)



def published(site) -> dict:
    """The company document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend
    then DOES with the document — the timeline, the <picture> tags, the
    AboutPage graph — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered page.

    Splitting it this way is not a loss of coverage so much as an honest
    statement of where each half's responsibility ends. What it does cost is
    that neither test alone proves a field survives the whole trip; the model
    they share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("company", {})


def region(page: str, css_class: str) -> str:
    """The markup of one element, by class, as far as its next close tag.

    Rough on purpose. It is used to ask "is this assertion true INSIDE this
    card" rather than anywhere on a page that repeats every heading in a form
    of its own, which is how a check passes by finding the right words in the
    wrong place.
    """
    m = re.search(r'<[a-z]+ class="[^"]*' + re.escape(css_class)
                  + r'[^"]*"[^>]*>(.*?)</\1>', page, re.S)
    if m:
        return m.group(1)
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
    work = Path(tempfile.mkdtemp(prefix="t4t-contact-"))
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
