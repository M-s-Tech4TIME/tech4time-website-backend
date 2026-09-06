#!/usr/bin/env python3
"""
Exercise the job post editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_careers_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
admin/index.php writes content/careers.json, and pages/careers/index.php
renders whatever it finds there. Code that writes files is worth a test: a bug
in the save path does not show up as an error, it shows up as a job post that
quietly disappeared.

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
DATA = ROOT / "content" / "careers.json"

# The admin gained a second editor and an icon rail, so / is now the
# overview and each editor has its own address. The job posts are here.
ADMIN = "/?s=careers"

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


def start_server(port: int, private: Path, public_site: str):
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(DOCROOT), str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(private),
                 T4T_PUBLIC_URL=public_site, T4T_PUBLISH_URL=""),
    )
    for _ in range(50):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return proc
        except OSError:
            if proc.poll() is not None:
                raise SystemExit("php exited:\n" + proc.stderr.read().decode("utf-8", "replace"))
            time.sleep(0.1)
    raise SystemExit("php server did not come up")


class Client:
    """Keeps the session cookie, which is what carries the CSRF token."""

    def __init__(self, port):
        self.base = f"http://127.0.0.1:{port}"
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(CookieJar()),
            NoRedirect(),
        )

    def get(self, path):
        try:
            with self.opener.open(self.base + path) as r:
                return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def post(self, path, fields):
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base + path, data=data, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """A 302 after a save is the thing being asserted, so do not follow it."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

    http_error_302 = http_error_301 = http_error_303 = http_error_307 = \
        lambda self, req, fp, code, msg, headers: None


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    return m.group(1) if m else ""


def job_ids() -> list:
    return [j.get("id") for j in json.loads(DATA.read_text())["jobs"]]


def statuses() -> dict:
    return {j["id"]: j.get("status") for j in json.loads(DATA.read_text())["jobs"]}


SANITISER_CASES = [
    ("a script tag and its contents", '<p>ok</p><script>alert(1)</script>', 'alert'),
    ("an event handler", '<p onclick="steal()">hi</p>', 'onclick'),
    ("an inline style", '<p style="text-align:center">hi</p>', 'style'),
    ("a javascript: link", '<a href="javascript:alert(1)">x</a>', 'javascript'),
    ("a tab-obfuscated javascript: link", '<a href="java&#09;script:alert(1)">x</a>', 'script:'),
    ("a data: link", '<a href="data:text/html,<b>x</b>">y</a>', 'data:'),
    ("an iframe", '<iframe src="//evil.test"></iframe>', 'iframe'),
    ("an img onerror", '<img src=x onerror=alert(1)>', 'onerror'),
    ("an svg animate payload", '<svg><animate onbegin=alert(1)></svg>', 'onbegin'),
    ("a form", '<form action="//evil.test"><input name=p></form>', '<form'),
    ("a class that is not an alignment", '<p class="evil ta-center">x</p>', 'evil'),
    ("a style block and its contents", '<style>body{display:none}</style><p>x</p>', 'display:none'),
    ("a meta refresh", '<meta http-equiv="refresh" content="0;url=//e.test">', 'refresh'),
    ("a base tag", '<base href="//evil.test/">', '<base'),
]

KEEP_CASES = [
    ("plain text in a paragraph", '<p>plain</p>', '<p>plain</p>'),
    ("bold, italic and underline", '<p><strong>b</strong><em>i</em><u>u</u></p>',
     '<p><strong>b</strong><em>i</em><u>u</u></p>'),
    ("a bulleted list", '<ul><li>one</li></ul>', '<ul><li>one</li></ul>'),
    ("a numbered list", '<ol><li>one</li></ol>', '<ol><li>one</li></ol>'),
    ("an alignment class", '<p class="ta-center">x</p>', '<p class="ta-center">x</p>'),
    ("b and i normalised to strong and em", '<p><b>x</b><i>y</i></p>',
     '<p><strong>x</strong><em>y</em></p>'),
    ("an unclosed tag is balanced", '<p>x', '<p>x</p>'),
    ("entities are not double-encoded", '<p>a &amp; b</p>', '<p>a &amp; b</p>'),
]


def check_sanitiser(r: Results):
    """
    Run careers_sanitise_html() directly.

    This is the one boundary that matters: whatever it returns is printed on
    the public page without escaping. The editor's own restrictions are a
    convenience for whoever is typing and are trivially bypassed by posting to
    the endpoint directly, so the assertions belong here.
    """
    print("\nsanitiser — what must not survive")

    script = (
        "<?php require 'lib/careers.php';\n"
        "$in = stream_get_contents(STDIN);\n"
        "echo careers_sanitise_html($in);"
    )

    def clean(markup: str) -> str:
        out = subprocess.run(
            ["php", "-r", script.replace("<?php ", "")],
            input=markup, capture_output=True, text=True, cwd=str(ROOT),
        )
        return out.stdout

    for label, payload, forbidden in SANITISER_CASES:
        out = clean(payload)
        r.check(f"{label} is removed", forbidden.lower() not in out.lower(),
                f"survived as: {out}")

    print("\nsanitiser — what must survive")
    for label, payload, expected in KEEP_CASES:
        out = clean(payload)
        r.check(f"{label} is kept", out == expected, f"got: {out}")


# ------------------------------------------------- every field, end to end

# The NEW literal above names every field there is today, and it will go on
# passing on the day somebody adds an eighteenth. That is the gap: a field
# nothing posts is a field no test renders, and a field no test renders is one
# the page can quietly stop reading — the editor keeps a box for it, somebody
# types into the box, and nothing they typed ever appears anywhere.
#
# check_content_model.py closes exactly this for the contact page, and cannot
# close it here. Both sides of careers are loops: the editor writes its seven
# body fields as name="<?= h($field) ?>", and the page renders them by walking
# CAREERS_SECTIONS. A regex over the source finds "h" and "field", not "about"
# and "offers" — so a static check would have to exempt the seven fields most
# likely to drift, and would then announce that all is well.
#
# So this asks PHP what the model holds and puts a distinct marker through
# every field of it, editor to visitor, over HTTP. A field added to
# lib/careers.php is covered the moment it exists, and covered strictly: the
# default is that it must come back verbatim, and a field that cannot carry a
# marker has to be written into SHAPED_FIELDS with the reason.

MARK = "Z7QF"


def marker(field: str) -> str:
    """A value that can only have come from this field, on this run."""
    return f"{MARK}-{field}"


# field -> (what to post, what must then appear on the page, why not a marker)
#
# Only for fields whose shape is not free text. Everything else in the model
# gets marker() and is expected back unchanged, which is the strict default —
# a new field nothing renders fails here rather than passing unnoticed.
SHAPED_FIELDS = {
    "id": (
        "",
        f'"{MARK.lower()}-title"',
        "Never typed. careers_slug() derives it from the title, so what proves "
        "it arrived is the slug itself, in the document that was published.",
    ),
    "status": (
        "open",
        None,
        "A control, not content: nothing renders it. What it does is decide "
        "whether the post renders at all, which every check below depends on.",
    ),
    "posted": (
        "2026-08-21",
        '"posted": "2026-08-21"',
        "careers_validate() requires YYYY-MM-DD, so it cannot carry a marker. "
        "What the visitor eventually does with it is the frontend's business.",
    ),
    "closes": (
        "2026-12-31",
        '"closes": "2026-12-31"',
        "YYYY-MM-DD again.",
    ),
    "apply_url": (
        f"https://example.com/apply-{MARK}",
        f"https://example.com/apply-{MARK}",
        "careers_validate() requires a full URL, so the marker rides in its "
        "path instead.",
    ),
    "cv_form_url": (
        f"https://example.com/cv-{MARK}",
        f"https://example.com/cv-{MARK}",
        "A site-wide setting, saved by a different form than the post editor.",
    ),
}


def escaped(value: str) -> str:
    """A label as h() will have written it.

    Python's html.escape() is close but not the same: it spells an apostrophe
    &#x27; where PHP's ENT_QUOTES spells it &#039;. Close enough to pass today
    and fail the day a heading gains one, which is the worst kind of helper.
    """
    for raw, entity in (("&", "&amp;"), ("<", "&lt;"), (">", "&gt;"),
                        ('"', "&quot;"), ("'", "&#039;")):
        value = value.replace(raw, entity)
    return value


def careers_model() -> dict:
    """The fields lib/careers.php defines — asked of PHP, not parsed out of it.

    Parsing is what check_content_model.py does, and for a file whose fields
    are consumed in loops it reads the loop variable rather than the fields.
    PHP already knows; the settings are whatever careers_load() returns at the
    top level that is not a job or the bookkeeping beside it — and the
    bookkeeping is asked for by name too, because each setting is posted on its
    own and a form posting a field the editor does not own blanks the ones it
    does. 'revision' arrived and cleared cv_form_url exactly that way.

    CONTRACT_META_BAND is excluded for the same reason and not a different one.
    The careers page gained a meta band -- its title and description were
    literal strings in the page file and were the only ones on the site nobody
    could change -- but this editor does not own it: the SEO screen does. It is
    a top-level key here, so without this line it would be posted as a setting
    and blanked.
    """
    out = subprocess.run(
        ["php", "-r",
         "require 'lib/careers.php';"
         "echo json_encode(["
         "'text' => CAREERS_TEXT_FIELDS,"
         "'rich' => CAREERS_RICH_FIELDS,"
         "'sections' => CAREERS_SECTIONS,"
         "'settings' => array_values(array_diff("
         "    array_keys(careers_load()), ['jobs', CONTRACT_META_BAND],"
         "    CONTRACT_BOOKKEEPING)),"
         "]);"],
        cwd=ROOT, capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        raise SystemExit("could not read the careers model from PHP:\n"
                         + (out.stderr or out.stdout)[:400])
    return json.loads(out.stdout)


def check_every_field_reaches_the_live_site(client: Client, r: Results,
                                            token: str, site) -> None:
    model = careers_model()
    known = set(model["text"]) | set(model["rich"]) | set(model["settings"])

    # Two statements of the same thing, and the page renders bodies by walking
    # the second. If they part, either a stored field is never shown or a
    # heading appears with nothing under it.
    r.check("every body field has a section heading",
            sorted(model["rich"]) == sorted(model["sections"]),
            f"rich={model['rich']} sections={sorted(model['sections'])}")

    stale = sorted(set(SHAPED_FIELDS) - known)
    r.check("nothing here describes a field the model has dropped", not stale,
            f"{stale} — drop from SHAPED_FIELDS in this file")

    post: dict = {}
    needles: dict = {}
    for field in model["text"] + model["rich"]:
        if field in SHAPED_FIELDS:
            value, needle, _ = SHAPED_FIELDS[field]
        elif field in model["rich"]:
            # Wrapped, because a body field is stored as sanitised HTML and
            # what the page prints is the markup, not the words alone.
            value = needle = f"<p>{marker(field)}</p>"
        else:
            value = needle = marker(field)
        post[field] = value
        if needle is not None:
            needles[field] = needle

    status, _, html = client.post(ADMIN, dict(post, action="save", csrf=token, id=""))
    r.check("a post carrying every field in the model saves",
            status == 302, f"{status} — {re.sub(r'<[^>]+>', ' ', html)[:200].strip()}")

    for field in model["settings"]:
        value, needle, _ = SHAPED_FIELDS.get(field, (marker(field), marker(field), ""))
        client.post(ADMIN, {"action": "settings", "csrf": token, field: value})
        needles[field] = needle

    published = json.dumps(site.documents.get("careers", {}),
                           ensure_ascii=False)

    r.check("the post reaches the live site at all",
            f'"{MARK.lower()}-title"' in published,
            "nothing below can pass without this — the save did not publish")

    for field in sorted(needles):
        r.check(f"'{field}' reaches the live site", needles[field] in published,
                f"expected {needles[field]!r} in the published document — the "
                f"model defines this field, so either the save must carry it or "
                f"SHAPED_FIELDS must say why it cannot carry a marker")

    r.check("every body field the model declares has a section heading",
            sorted(model["rich"]) == sorted(model["sections"]),
            "the frontend renders bodies by walking CAREERS_SECTIONS; a field "
            "without a heading is stored and never shown")


def run(client: Client, r: Results, site):
    check_sanitiser(r)

    NEW = {
        "title": "Test Automation Engineer",
        "employment_type": "Full-Time",
        "work_arrangement": "Remote",
        "location": "Dhaka, Bangladesh",
        "salary": "Negotiable",
        "posted": "2026-08-21",
        "closes": "",
        "status": "open",
        "apply_url": "https://forms.gle/exampleTEST123",
        "about": "<p>First paragraph about the role.</p>"
                 "<p class=\"ta-center\">Second paragraph, centred.</p>",
        "responsibilities": "<ul><li>Write <strong>tests</strong>.</li>"
                            "<li>Run tests.</li><li>Read the failures.</li></ul>",
        "requirements": "<ol><li>Patience.</li></ol>",
        "must_have": "",
        "nice_to_have": "",
        "certifications": "<p>An <em>example</em> with a "
                          "<a href=\"https://example.com\">link</a>.</p>",
        "offers": "<ul><li>Coffee.</li></ul>",
    }

    print("\nreading")
    status, html = client.get(ADMIN)
    r.check("the editor loads once authenticated", status == 200 and "Job posts" in html,
            f"{status}")
    token = csrf_of(html)
    r.check("it issues a CSRF token", len(token) == 64, token[:20])

    before = job_ids()

    print("\ncreating")
    status, headers, _ = client.post(ADMIN, dict(NEW, action="save", csrf=token, id=""))
    r.check("a new post redirects rather than re-rendering",
            status == 302, f"{status}")
    ids = job_ids()
    r.check("it is written to careers.json", len(ids) == len(before) + 1, str(ids))
    r.check("its id is derived from the title",
            "test-automation-engineer" in ids, str(ids))

    # What was published, rather than what a visitor sees: the page that
    # renders this is in tech4time-website-frontend, and its half of the journey —
    # a published document arriving and being rendered — is proved there by
    # test_publish.py. This end asserts that the right document left.
    sent = json.dumps(site.documents.get("careers", {}), ensure_ascii=False)
    r.check("it reaches the live site", "Test Automation Engineer" in sent,
            "the save did not publish, or published something else")
    r.check("the live site holds the revision the record does",
            site.revisions["careers"] == json.loads(DATA.read_text())["revision"],
            f"stub {site.revisions['careers']}")

    r.check("its bullets survive the sanitiser as list items", sent.count("<li>") >= 4)
    r.check("its paragraphs survive the round trip",
            "<p>First paragraph about the role.</p>" in sent)
    r.check("bold survives the round trip", "<strong>tests</strong>" in sent)
    r.check("a numbered list stays numbered", "<ol><li>Patience.</li></ol>" in sent)
    r.check("an alignment class survives",
            'class=\\"ta-center\\"' in sent,
            "alignment must arrive as a class, not a style")
    r.check("no inline style is published (the frontend's CSP is style-src 'self')",
            not re.search(r"<[^>]+\\?\s+style=", sent),
            "an inline style would be refused by the browser over there")
    r.check("an author link is published safely",
            'rel=\\"noopener noreferrer\\"' in sent)

    print("\nvalidating")
    status, _, html = client.post(ADMIN, dict(NEW, action="save", csrf=token, id="",
                                                  title=""))
    r.check("a post with no title is refused", "A job title is required" in html)
    status, _, html = client.post(ADMIN, dict(NEW, action="save", csrf=token, id="",
                                                  apply_url="not-a-url"))
    r.check("a post with a broken apply link is refused",
            "must be a full URL" in html, html[:200])
    status, _, html = client.post(ADMIN, dict(NEW, action="save", csrf=token, id="",
                                                  closes="31-10-2026"))
    r.check("a misformatted closing date is refused", "YYYY-MM-DD" in html)
    r.check("none of those wrote anything", len(job_ids()) == len(before) + 1)

    print("\npublishing")
    client.post(ADMIN, {"action": "toggle", "csrf": token,
                            "id": "test-automation-engineer"})
    r.check("unpublishing sets the post to draft",
            statuses().get("test-automation-engineer") == "draft", str(statuses()))
    # A draft IS published — the whole document travels, and the live site
    # filters on status when it renders. That is on purpose: the frontend's
    # copy is a replica, and a projection would make the two revisions
    # incomparable. It does mean an unpublished job post's text sits on the
    # public server, in content/, protected by .htaccess. Same as it was
    # before the split, and stated rather than assumed.
    sent = json.loads(json.dumps(site.documents["careers"]))
    draft = next(j for j in sent["jobs"] if j["id"] == "test-automation-engineer")
    r.check("a draft is published, carrying its status", draft["status"] == "draft",
            str(draft["status"]))
    r.check("so hiding it is the live site's job, on one field",
            all(j.get("status") in ("open", "draft") for j in sent["jobs"]))

    client.post(ADMIN, {"action": "toggle", "csrf": token,
                            "id": "test-automation-engineer"})
    r.check("publishing brings it back",
            statuses().get("test-automation-engineer") == "open")

    print("\nordering")
    ids = job_ids()
    if len(ids) >= 2:
        last = ids[-1]
        client.post(ADMIN, {"action": "move", "csrf": token, "id": last,
                                "direction": "up"})
        r.check("moving up reorders the file", job_ids()[-2] == last, str(job_ids()))
        client.post(ADMIN, {"action": "move", "csrf": token, "id": ids[0],
                                "direction": "up"})
        r.check("moving the first post up is a no-op, not an error",
                job_ids()[0] == ids[0], str(job_ids()))

    print("\nCSRF")
    status, _, _ = client.post(ADMIN, {"action": "delete", "csrf": "wrong",
                                           "id": "test-automation-engineer"})
    r.check("a request with a bad token is rejected", status == 400, f"{status}")
    r.check("and nothing was deleted", "test-automation-engineer" in job_ids())

    print("\ndeleting")
    client.post(ADMIN, {"action": "delete", "csrf": token,
                            "id": "test-automation-engineer"})
    r.check("the post is removed", "test-automation-engineer" not in job_ids())
    r.check("the others are untouched", job_ids() and len(job_ids()) == len(before),
            str(job_ids()))
    r.check("a backup of the previous version exists",
            (DATA.parent / "careers.json.bak").is_file())

    print("\nempty state")
    for jid in list(job_ids()):
        client.post(ADMIN, {"action": "delete", "csrf": token, "id": jid})
    sent = site.documents["careers"]
    r.check("with no posts an empty document is still published", sent["jobs"] == [],
            str(sent["jobs"])[:120])
    r.check("and the site-wide settings survive with it",
            "cv_form_url" in sent, str(sorted(sent)))
    r.check("deleting the last post still advanced the revision",
            site.revisions["careers"] == json.loads(DATA.read_text())["revision"])

    print("\nevery field, editor to live site")
    check_every_field_reaches_the_live_site(client, r, token, site)


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found. This test needs the PHP CLI:\n"
                         "  sudo apt install php-cli")
    if not DATA.is_file():
        raise SystemExit(f"{DATA} not found")

    backup = DATA.read_bytes()
    port = free_port()

    # The accounts, sessions and counters go somewhere disposable, so this run
    # cannot disturb whatever account is used locally.
    work = Path(tempfile.mkdtemp(prefix="t4t-careers-"))
    private = work / "private"

    # The far side. A stub rather than the real endpoint, because the real one
    # is in the other repository — and because checking this half against that
    # half would check the two against each other rather than against the
    # format they both implement. See tools/publish_stub.py.
    key = bytes.fromhex("5e" * 32)
    private.mkdir(mode=0o700, parents=True, exist_ok=True)
    (private / "publish.key").write_text(key.hex() + "\n")

    results = Results()

    with PublishStub(key) as site:
        print(f"php -S 127.0.0.1:{port}   (content/careers.json is restored afterwards)")
        print(f"publishing to {site.url}   (a stub, in this process)")
        proc = start_server(port, private, site.url)
        try:
            secret = admin_session.make_account(private)
            client = Client(port)
            admin_session.sign_in(client.opener, client.base, secret)
            run(client, results, site)
        finally:
            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            proc.wait(timeout=5)
            shutil.rmtree(work, ignore_errors=True)
            DATA.write_bytes(backup)
            (DATA.parent / "careers.json.bak").unlink(missing_ok=True)
            print("\ncontent/careers.json restored")

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")

    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
