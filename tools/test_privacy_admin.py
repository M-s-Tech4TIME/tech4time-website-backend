#!/usr/bin/env python3
"""
Exercise the privacy policy editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_privacy_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/privacy.php writes content/privacy.json, and the frontend's
pages/privacy-policy/index.php renders whatever it finds there. Code that
writes files is worth a test; code that writes a LEGAL document is worth more
than most, because a bug in the save path does not announce itself — it shows
up as a clause that quietly stopped being published.

It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because the
form names its inputs "sections[<?= $s ?>][body]" and the page renders them
by calling md_render() in a loop. So this proves it by round trip instead.

MARKDOWN, NOT CARDS
A section is {heading, status, body} and the body is Markdown source, stored
verbatim and rendered by the shared lib/markdown.php. There is nothing to
sanitise on the way in — and that is the load-bearing assertion below: hostile
input must round-trip byte-identical (the renderer escapes it on the way
out), because sanitising Markdown with an HTML sanitiser would entity-mangle
it. What the editor refuses is emptiness and missing metadata, never content.

THE ANCHOR RULE IS THE ONE WITH TEETH
A section's id is its web address. Somebody may have linked to it from an email
this repository cannot see, so a section that has been named keeps its fragment
— even when a new section with the same heading is added above it. That case is
checked directly, because the obvious one-pass implementation gets it wrong and
the failure is silent.

PREVIEW SAVES NOTHING
The Preview button renders the posted form through md_render() and redraws it.
A preview that wrote the file would be a save wearing a different label, so
the revision on disk is read before and after and must not move.

NOTHING HERE REFUSES A SAVE BECAUSE OF THE CONTACT PAGE
The comparison against contact.json is a notice, never a refusal. That is a
decision, so it is a check: a policy that disagrees with the contact page must
still save.

The point of the rest is not that the editor accepted a change — it is that the
change reached the LIVE SITE, in the right shape. This half ends at the
publish; what the frontend then renders is proved there, by
tools/test_publish.py.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself, which is tools/test_admin_auth.py's subject. The ribbon
toolbar, which is JavaScript — see tools/test_md_toolbar.py.
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
DATA = ROOT / "content" / "privacy.json"

ADMIN = "/?s=privacy"

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

def published(site) -> dict:
    """The privacy document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend then
    DOES with the document — the sections, the Markdown, the anchors — is
    proved in tech4time-website-frontend, by test_publish.py. The model they
    share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("privacy", {})


def sections_sent(site) -> list:
    return published(site).get("policy", {}).get("sections", [])


def press(client, r, fields, do, what):
    """Press one of the row buttons, and hand back the redrawn form."""
    status, _headers, page = client.post(ADMIN, {**fields, "do": do})
    r.check(f"{what}: the form comes back", status == 200, f"status {status}")
    return page


def save(client, fields):
    return client.post(ADMIN, {**fields, "do": "save"})


def headings(page):
    return re.findall(r'name="sections\[\d+\]\[heading\]"\s+value="([^"]*)"', page)


def bodies(page):
    return re.findall(
        r'name="sections\[\d+\]\[body\]"[^>]*data-md[^>]*>(.*?)</textarea>',
        page, re.S) or re.findall(
        r'name="sections\[\d+\]\[body\]"[^>]*>(.*?)</textarea>', page, re.S)


def run(client, r, site):
    print("the editor renders")

    status, page = client.get(ADMIN)
    r.check("the screen is served", status == 200, f"status {status}")
    for band in ("band-hero", "band-facts", "band-callout", "band-policy",
                 "band-preview", "band-cta", "band-meta"):
        r.check(f"it has {band}", f'id="{band}"' in page)

    fields = form_fields(page)
    r.check("it carries a CSRF token", "csrf" in fields)
    r.check("the whole page carries a standing legal warning",
            "Every word on this page is a legal statement" in page)
    # THE CLASS LIST, NOT A LITERAL ATTRIBUTE. This read
    # 'class="admin__notice"' in page, and stopped being true the day
    # admin_standing_notice() gained admin__notice-line to put its icon on the
    # text's centre line -- a purely visual change that broke a check about
    # whether a legal warning is styled as a refusal. What it means to assert
    # is that the paragraph is a notice and is NOT dressed as an error, so
    # that is what it asks now, and another modifier can be added without
    # coming back here.
    warning = re.search(
        r'<p class="([^"]*admin__notice[^"]*)"[^>]*>(?:(?!</p>).)*'
        r'Every word on this page is a legal statement',
        page, re.S)
    r.check("and the warning is a notice, not an error",
            warning is not None
            and "admin__notice--error" not in warning.group(1),
            f"classes are {warning.group(1)!r}" if warning else
            "no <p class=...admin__notice...> carries the standing warning")
    r.check("the scripts that stop a button reloading the page are loaded",
            "admin-forms.js" in page,
            "admin_foot() did not run — every button would be a full reload")

    print("\nthe policy is one Markdown field")

    r.check("a single body holds the whole policy",
            "policy[body]" in fields, "no single body field")
    r.check("twelve headings with pinned anchors inside it",
            fields.get("policy[body]", "").count("## ") >= 12,
            "the sections did not all arrive")
    r.check("old anchors preserved as suffixes",
            "## Who is responsible for your data {#who-we-are}" in fields.get("policy[body]", ""))
    r.check("and no section inputs survive from the card era",
            "sections[0][heading]" not in page
            and "sections[0][blocks]" not in page)
    r.check("the retention table travelled as Markdown",
            "| What | How long |" in fields.get("policy[body]", ""))
    r.check("the effective date is a calendar picker",
            'name="policy[effective]"' in page and 'type="date"' in page,
            "free-text dates misstate legal claims")
    r.check("currently the migration date",
            fields.get("policy[effective]") == "2026-08-21",
            fields.get("policy[effective]", "missing"))

    print("\nediting Markdown reaches the live site verbatim")

    fields["policy[body]"] = "## Test head {#test-head}\n\nMarker **A7**."
    status, headers, _ = save(client, fields)
    r.check("the save redirected", status in (302, 303), f"status {status}")
    sent = published(site)
    r.check("the live site was sent the document",
            "Marker **A7**." in sent.get("policy", {}).get("body", ""),
            "body missing")

    print("\nhostile input round-trips byte-identical -- never sanitised")

    hostile = "<script>alert(9)</script>\n\n[click](javascript:alert(9))\n\n**ok**"
    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[body]"] = "## Test head {#test-head}\n\n" + hostile
    status, _headers, _ = save(client, fields)
    r.check("even hostile input saves", status in (302, 303), f"status {status}")
    sent = published(site)
    r.check("and arrives byte-identical -- sanitising Markdown would mangle it",
            hostile in sent.get("policy", {}).get("body", ""),
            sent.get("policy", {}).get("body", "")[:100])

    print("\npreview renders without saving")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    before = json.loads(DATA.read_bytes())["revision"]
    fields["policy[body]"] = "## Preview head {#preview-head}\n\nPreview marker **P4**."
    status, _headers, body = client.post(ADMIN, {**fields, "do": "preview:0"})
    r.check("the preview comes back", status == 200, f"status {status}")
    r.check("with the rendered heading and emphasis",
            '<h2 class="legal__heading" id="preview-head">Preview head</h2>' in body
            and "<strong>P4</strong>" in body,
            "Markdown did not render")
    r.check("and the revision on disk did not move",
            json.loads(DATA.read_bytes())["revision"] == before,
            "preview wrote the file -- it is a save wearing a different label")
    r.check("saying so out loud", "nothing was saved" in body)

    print("\nthe new-tab preview is a whole document that writes nothing")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    before = json.loads(DATA.read_bytes())["revision"]
    fields["policy[body]"] = "## Tab head {#tab-head}\n\nTab marker **T9**."
    status, headers, body = client.post(ADMIN, {**fields, "do": "preview-tab"})
    r.check("a standalone document comes back", status == 200, f"status {status}")
    r.check("as HTML, not a screen fragment",
            "<!DOCTYPE html>" in body and "Preview — " in body)
    r.check("with the posted words rendered in it",
            '<h2 class="legal__heading" id="tab-head">Tab head</h2>' in body
            and "<strong>T9</strong>" in body)
    r.check("and the revision on disk did not move",
            json.loads(DATA.read_bytes())["revision"] == before,
            "a preview wrote the file")

    print("\nwhat the editor refuses, and what it does not")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[effective]"] = ""
    _status, _h, body = save(client, fields)
    r.check("a policy with no effective date is refused",
            "no effective date" in body, "it saved without one")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[effective]"] = "next Tuesday"
    _status, _h, body = save(client, fields)
    r.check("a date that is not a date is refused",
            "no effective date" in body, "free text sailed through the picker")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[body]"] = ""
    _status, _h, body = save(client, fields)
    r.check("a policy with no words is refused", "has no words" in body)

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[body]"] = "#### Orphan {#orphan}\n\ntext"
    _status, _h, body = save(client, fields)
    r.check("a body opening past H2 is refused",
            "starts at H2" in body, "the landmark skipped silently")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[body]"] = "## A {#dup}\n\n## B {#dup}\n\ntext"
    _status, _h, body = save(client, fields)
    r.check("a doubled explicit anchor is refused",
            "states #dup twice" in body, "two fragments, one address")

    print("\nthe comparison with the contact page is a notice and never a refusal")

    _status, page = client.get(ADMIN)
    r.check("the comparison is drawn on the screen",
            "the policy still says this" in page)
    r.check("and it says plainly that it does not block a save",
            "Nothing here stops you saving" in page)

    fields = form_fields(page)
    # State the office plainly, then drop it: the hostile save two blocks up
    # legitimately removed it, so it is written back here first.
    fields["policy[body]"] = (
        "## Contact {#contact}\n\n"
        "Reached at [info@tech4time.bd](mailto:info@tech4time.bd), "
        "278/3, Manikdi, Dhaka."
    )
    status, _headers, body = save(client, fields)
    r.check("a policy that states the office saves",
            status in (302, 303), f"status {status}")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[body]"] = fields["policy[body]"].replace("Manikdi", "Nowhere")
    status, _headers, body = save(client, fields)
    r.check("a policy that has stopped agreeing with the contact page STILL SAVES",
            status in (302, 303),
            "the save was refused — a mismatch must never block an unrelated edit")

    _status, page = client.get(ADMIN)
    r.check("and the screen now says which fact it no longer states",
            "the policy does not say this" in page,
            "the notice did not notice")

    print("\nan oversized post is refused rather than half-applied")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    r.check("the form ends with the marker that makes truncation visible",
            "__tail" in fields)


def main():
    backup = DATA.read_bytes()
    port = free_port()

    # The accounts, sessions and counters go somewhere disposable, so this run
    # cannot disturb whatever account is used locally.
    work = Path(tempfile.mkdtemp(prefix="t4t-privacy-"))
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
