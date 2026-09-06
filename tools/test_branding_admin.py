#!/usr/bin/env python3
"""
Exercise the branding & advertisement editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_branding_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/branding.php writes content/branding.json, and the frontend's
pages/branding-and-advertisement/index.php renders whatever it finds there.
Code that writes files is worth a test: a bug in the save path does not
announce itself, it shows up as a logo card that quietly lost its download.

It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because the
form and the page both walk the lists in loops and the field names are
expressions rather than literals. So this proves it by round trip instead.

TWO LISTS DEEP, AND TWO KINDS OF PICTURE
A logo variant holds the files it offers, and both levels have to add, remove,
reorder and hide independently. The variant also carries TWO picture records
that are not the same picture — the preview drawn on the card, and the file a
visitor downloads — and most of the confusion this page could cause is the two
being mixed up. Several of the checks below are exactly that.

WHAT IS DERIVED IS CHECKED FOR NOT BEING STORED
The size in a meta line, the format on a download button: both come off the
file. A test that only proved they appeared would pass just as well if they
were typed, so what is asserted is that the stored row does NOT hold them and
the screen shows them anyway.

The point of the rest is not that the editor accepted a change — it is that the
change reached the LIVE SITE, in the right shape. This half ends at the
publish; what the frontend then renders is proved there, by
tools/test_publish.py.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself, which is tools/test_admin_auth.py's subject. And an actual
upload: a file input needs a multipart POST that this harness does not build,
so what a stored picture record becomes is proved by tools/test_upload.py and
tools/test_svg.py, and what the page does with one by tools/test_publish.py.
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
DATA = ROOT / "content" / "branding.json"

ADMIN = "/?s=branding"

ROUTER = ROOT / "tools" / "dev-router.php"


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []
        self.skipped = 0

    def skip(self, case):
        self.skipped += 1
        print(f"  --    {case}  (needs GD)")

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))


def has_gd() -> bool:
    return subprocess.run(["php", "-r", "exit(extension_loaded('gd') ? 0 : 1);"],
                          cwd=ROOT, capture_output=True).returncode == 0


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
    """The branding document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend then
    DOES with the document — the cards, the derived meta lines, the download
    buttons — is proved in tech4time-website-frontend, by test_publish.py,
    which publishes a document and reads the rendered page. The model they
    share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("branding", {})


def assets_sent(site) -> list:
    return published(site).get("assets", {}).get("items", [])


def press(client, r, fields, do, what):
    """Press one of the row buttons, and hand back the redrawn form."""
    status, _headers, page = client.post(ADMIN, {**fields, "do": do})
    r.check(f"{what}: the form comes back", status == 200, f"status {status}")
    return page


def save(client, fields):
    return client.post(ADMIN, {**fields, "do": "save"})


def run(client, r, site):
    print("the editor renders")

    status, page = client.get(ADMIN)
    r.check("the screen is served", status == 200, f"status {status}")
    for band in ("band-hero", "band-assets", "band-legal", "band-cta", "band-meta"):
        r.check(f"it has {band}", f'id="{band}"' in page)

    fields = form_fields(page)
    r.check("it carries a CSRF token", "csrf" in fields)
    r.check("it posts as multipart, or no file could ever be attached",
            'enctype="multipart/form-data"' in page)
    r.check("the disclaimer carries a standing warning",
            "This is legal text" in page)

    print("\nthe four logos it starts with")

    r.check("all four are on the screen",
            len(re.findall(r'name="assets\[items\]\[\d+\]\[title\]"', page)) == 4)
    r.check("each carries its own plate",
            [fields[f"assets[items][{i}][plate]"] for i in range(4)]
            == ["light", "dark", "neutral", "neutral"],
            str([fields.get(f"assets[items][{i}][plate]") for i in range(4)]))
    r.check("each has exactly one download",
            len(re.findall(r'name="assets\[items\]\[\d+\]\[files\]\[\d+\]\[label\]"',
                           page)) == 4)
    r.check("a preview and a download are separate records",
            "assets[items][0][image][src]" in fields
            and "assets[items][0][files][0][file][src]" in fields)
    r.check("and they are different files",
            fields["assets[items][0][image][src]"]
            != fields["assets[items][0][files][0][file][src]"],
            "the preview and the download point at the same file")

    print("\nwhat the page draws is not what the document stores")

    r.check("the size is not typed into the label",
            fields["assets[items][0][files][0][label]"] == "Transparent PNG",
            fields["assets[items][0][files][0][label]"])
    r.check("but the editor shows the finished line",
            "Transparent PNG · 1600 × 570" in page,
            "the derived meta line is not on the screen")
    r.check("the preview's own size is not what it quotes",
            "800 × 285" not in page,
            "the meta line is quoting the preview instead of the download")
    r.check("the saved-as name is stored, because nothing can derive it",
            fields["assets[items][0][files][0][filename]"]
            == "tech4time-logo-light-theme.png",
            fields["assets[items][0][files][0][filename]"])

    print("\nadding a logo")

    page = press(client, r, fields, "asset-add:0", "add a logo")
    fields = form_fields(page)
    r.check("there are five", len(re.findall(
        r'name="assets\[items\]\[\d+\]\[title\]"', page)) == 5)
    r.check("the new one is hidden, so a blank card is never live",
            fields["assets[items][4][status]"] == "hidden")
    r.check("and it has no downloads yet",
            "assets[items][4][files][0][label]" not in fields)

    fields["assets[items][4][title]"] = "Monochrome Logo"
    fields["assets[items][4][text]"] = "One colour, for stamping and etching."
    fields["assets[items][4][plate]"] = "light"
    fields["assets[items][4][alt]"] = "Tech4TIME logo in a single colour"
    fields["assets[items][4][status]"] = "shown"

    page = press(client, r, fields, "file-4-add:0", "add a download to it")
    fields = form_fields(page)
    r.check("the download landed on the fifth logo and no other",
            "assets[items][4][files][0][label]" in fields
            and "assets[items][3][files][1][label]" not in fields)
    r.check("a download is NOT hidden — the card it is in already decides that",
            fields["assets[items][4][files][0][status]"] == "shown")

    print("\nwhat will not save")

    fields["assets[items][4][files][0][filename]"] = "tech4time-logo-mono.png"
    status, _h, page = save(client, fields)
    r.check("a download with no file attached is refused",
            status == 200 and "has no file" in page, f"status {status}")

    # Give it the one thing it is missing, the way an upload would.
    fields = form_fields(page)
    fields["assets[items][4][files][0][file][src]"] = \
        "/assets/images/branding/logo-light-transparent-full.png"
    fields["assets[items][4][files][0][file][width]"] = "1600"
    fields["assets[items][4][files][0][file][height]"] = "570"
    fields["assets[items][4][files][0][label]"] = "Single-colour PNG"

    # Only now can the saved-as name be the thing that fails: a row with no
    # file is refused for that first and never reaches the name at all.
    slashed = dict(fields)
    slashed["assets[items][4][files][0][filename]"] = "logos/tech4time.png"
    status, _h, page = save(client, slashed)
    r.check("a saved-as name with a slash in it is refused",
            status == 200 and "saved-as name" in page, f"status {status}")

    blank = dict(fields)
    blank["assets[items][4][alt]"] = ""
    blank["assets[items][4][image][src]"] = "/assets/images/branding/logo-light-transparent.png"
    status, _h, page = save(client, blank)
    r.check("a preview with no description is refused",
            status == 200 and "no description" in page, f"status {status}")

    hostile = dict(fields)
    hostile["assets[items][4][files][0][file][src]"] = "https://evil.example/logo.png"
    status, _h, page = save(client, hostile)
    r.check("a file path pointing at another origin does not survive the save",
            status in (200, 303), f"status {status}")

    print("\nsaving, and what reaches the live site")

    status, headers, page = save(client, fields)
    r.check("the save redirects rather than re-rendering",
            status in (302, 303),
            f"status {status}: " + (page[:200] if status == 200 else ""))

    sent = assets_sent(site)
    r.check("five logos reached the site", len(sent) == 5, f"{len(sent)} sent")
    if len(sent) == 5:
        new = sent[4]
        r.check("the new one carries what was typed",
                new["title"] == "Monochrome Logo" and new["plate"] == "light")
        r.check("its download went with it",
                len(new["files"]) == 1
                and new["files"][0]["filename"] == "tech4time-logo-mono.png",
                str(new["files"]))
        r.check("the row that pointed at another origin was emptied, not stored",
                "evil.example" not in json.dumps(published(site)))
        r.check("nothing stores the derived size as text",
                "1600 × 570" not in json.dumps(published(site)),
                "a meta line was stored rather than derived")

    print("\nthe disclaimer keeps its markup and loses script")

    status, page = client.get(ADMIN)
    fields = form_fields(page)
    r.check("it starts with four paragraphs",
            len(re.findall(r'name="legal\[items\]\[\d+\]\[text\]"', page)) == 4)

    fields["legal[items][0][text]"] = (
        "<p>Marks are <strong>protected</strong> — "
        '<a href="/pages/contact/">write to us</a>.<script>alert(1)</script></p>')
    status, _h, _page = save(client, fields)
    r.check("the save went through", status in (302, 303), f"status {status}")

    note = published(site)["legal"]["items"][0]["text"]
    r.check("emphasis survives", "<strong>protected</strong>" in note, note[:160])
    r.check("a link survives", 'href="/pages/contact/"' in note, note[:160])
    r.check("script does not", "<script" not in note and "alert(1)" not in note,
            note[:160])

    print("\nreordering and removing, at both levels")

    status, page = client.get(ADMIN)
    fields = form_fields(page)
    first = fields["assets[items][0][title]"]
    second = fields["assets[items][1][title]"]

    page = press(client, r, fields, "asset-down:0", "move the first logo down")
    fields = form_fields(page)
    r.check("the two swapped",
            fields["assets[items][0][title]"] == second
            and fields["assets[items][1][title]"] == first)

    page = press(client, r, fields, "asset-up:1", "move it back")
    fields = form_fields(page)
    r.check("and back again", fields["assets[items][0][title]"] == first)

    page = press(client, r, fields, "file-0-add:0", "add a second download")
    fields = form_fields(page)
    r.check("logo one now has two downloads",
            "assets[items][0][files][1][label]" in fields)
    r.check("and no other logo gained one",
            "assets[items][1][files][1][label]" not in fields)

    fields["assets[items][0][files][1][label]"] = "SVG"
    fields["assets[items][0][files][1][filename]"] = "tech4time-logo.svg"
    fields["assets[items][0][files][1][file][src]"] = "/uploads/" + "a" * 16 + ".svg"

    page = press(client, r, fields, "file-0-down:0", "move the first download down")
    fields = form_fields(page)
    r.check("the downloads swapped inside their own logo",
            fields["assets[items][0][files][0][label]"] == "SVG",
            fields["assets[items][0][files][0][label]"])

    page = press(client, r, fields, "file-0-remove:0", "remove one download")
    fields = form_fields(page)
    r.check("logo one is back to one download",
            "assets[items][0][files][1][label]" not in fields)

    page = press(client, r, fields, "asset-remove:4", "remove the fifth logo")
    fields = form_fields(page)
    r.check("four are left",
            len(re.findall(r'name="assets\[items\]\[\d+\]\[title\]"', page)) == 4)

    print("\na vector download is allowed, and offered")

    # admin_image_fields() replaces the whole file input with a notice when GD
    # is missing, so there is no accept="" to read. Skipped rather than failed,
    # the way test_upload.py skips its re-encoding cases; CI installs php-gd.
    if not has_gd():
        for case in ("the download slot accepts an SVG",
                     "the preview slot does not",
                     "and it says what happens to one"):
            r.skip(case)
    else:
      r.check("the download slot accepts an SVG",
              'accept="image/jpeg,image/png,image/webp,image/svg+xml"' in page,
              "the file input does not offer SVG")
      r.check("the preview slot does not",
              'accept="image/jpeg,image/png,image/webp">' in page,
              "the preview input offers SVG, which the page never draws")
      r.check("and it says what happens to one",
              "read, checked against a list of what a drawing may contain" in page)

    print("\nhiding is not deleting")

    # A fresh GET, so this starts from what was last SAVED — the row buttons
    # above were pressed and never saved, which is exactly what they promise.
    status, page = client.get(ADMIN)
    fields = form_fields(page)
    before = len(re.findall(r'name="assets\[items\]\[\d+\]\[title\]"', page))
    notes_before = len(re.findall(r'name="legal\[items\]\[\d+\]\[text\]"', page))

    fields["assets[items][1][status]"] = "hidden"
    fields["legal[items][3][status]"] = "hidden"
    fields["cta[status]"] = "hidden"
    status, _h, _page = save(client, fields)
    r.check("the save went through", status in (302, 303), f"status {status}")

    out = published(site)
    r.check("the hidden logo is still in the document, and still hidden",
            len(out["assets"]["items"]) == before
            and out["assets"]["items"][1]["status"] == "hidden",
            f'{len(out["assets"]["items"])} rows, wanted {before}')
    r.check("so is the hidden paragraph",
            len(out["legal"]["items"]) == notes_before
            and out["legal"]["items"][3]["status"] == "hidden")
    r.check("and the whole closing band can be switched off",
            out["cta"]["status"] == "hidden")

    print("\nsearch and sharing, which this screen no longer owns")

    # The breadcrumb, the title and the description are edited on the SEO
    # screen now, together with every other page's -- which is the only place
    # two pages sharing a title can be seen. They are still STORED here, in
    # content/branding.json, and still published with the page; only the
    # editing moved. tools/test_seo_admin.py proves the field still works and
    # still cannot be emptied.
    #
    # What is asserted here is that this screen has genuinely let go of them.
    # A field left on the form that nothing reads any more would be worse than
    # either arrangement: it would look editable and change nothing.
    status, page = client.get(ADMIN)
    r.check("the breadcrumb is not a field on this screen",
            'name="meta[breadcrumb]"' not in page)
    r.check("nor the search description",
            'name="meta[description]"' not in page)
    r.check("but the band is still there, pointing at the screen that owns it",
            'id="band-meta"' in page and "s=seo&amp;page=branding" in page)

    # And the value survived, which is the failure this could quietly cause:
    # every *_from_post() rebuilds the bands named in *_TEXT_FIELDS from
    # $_POST, so a form that stopped rendering these while still naming them
    # would blank the breadcrumb on every save. See contract_page_bands().
    fields = form_fields(page)
    save(client, fields)
    r.check("and a save that never touched it leaves it alone",
            published(site)["meta"]["breadcrumb"] == "Branding & Advertisement",
            str(published(site)["meta"]))


def main():
    backup = DATA.read_bytes()
    port = free_port()

    # The accounts, sessions and counters go somewhere disposable, so this run
    # cannot disturb whatever account is used locally.
    work = Path(tempfile.mkdtemp(prefix="t4t-branding-"))
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

    tail = f" ({r.skipped} skipped, no GD)" if r.skipped else ""
    print(f"\n{r.passed}/{total} checks passed{tail}")


if __name__ == "__main__":
    main()
