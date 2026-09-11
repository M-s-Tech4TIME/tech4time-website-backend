#!/usr/bin/env python3
"""
Exercise the header, footer and dock editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_chrome_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/chrome.php writes content/chrome.json, which is the furniture around
EVERY page of the public site -- the navigation, the footer, the mobile dock.
A bug in this save path does not take one page down, it takes the site's
navigation with it, so it is worth more of a test than most editors get.

WHAT IT PROVES, in the order the groups run:

  every field round-trips        set each one, save, read it back off the form
  the row bands work             add, remove, reorder, hide, on all five
  a new row arrives hidden       an empty entry must not appear in a live nav
  an id is permanent             a row keeps it through a reorder
  a save leaves the rest alone   saving the header cannot empty the footer
  a destination is checked       a target that is not a route is refused
  the import fills and saves nothing
  the drift notice appears, and NEVER blocks a save

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself -- tools/test_admin_auth.py is the subject of that -- and
what the saved document RENDERS as, which is the frontend's
tools/test_publish.py, where a published chrome document is read back out of an
ordinary page.
"""

import json
import os
import re
import shutil
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
ROUTER = ROOT / "tools" / "dev-router.php"
DATA = ROOT / "content" / "chrome.json"
CONTACT = ROOT / "content" / "contact.json"

MARK = "ZQX"


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

    def section(self, name):
        print(f"\n{name}")


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


def unescape(value: str) -> str:
    return (value.replace("&lt;", "<").replace("&gt;", ">")
                 .replace("&quot;", '"').replace("&#039;", "'")
                 .replace("&amp;", "&"))


def form_fields(html: str) -> dict:
    """Every named control in the editor, as the browser would submit it.

    Read out of the page rather than written by hand, which is what makes this
    a test of the EDITOR: a field the form stops rendering disappears from the
    submission here too, and whatever depended on it fails.
    """
    fields = {}

    for tag in re.findall(r"<input\b[^>]*>", html):
        name = re.search(r'name="([^"]+)"', tag)
        if not name or 'type="submit"' in tag:
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
            (chosen or first).group(1) if (chosen or first) else "")

    return fields


def stored() -> dict:
    return json.loads(DATA.read_text())


def published(site) -> dict:
    return site.documents.get("chrome", {})


def stop(proc):
    for attempt in (proc.terminate, proc.kill):
        try:
            attempt()
            proc.wait(timeout=5)
            return
        except Exception:
            continue


# ------------------------------------------------------------------- tests


def run(client, r, site):
    header = "/?s=chrome&part=header"
    footer = "/?s=chrome&part=footer"
    dock   = "/?s=chrome&part=dock"

    # ------------------------------------------------------------ it loads
    r.section("the four screens")
    for what, path in [("the index", "/?s=chrome"), ("the header", header),
                       ("the footer", footer), ("the dock", dock)]:
        status, html = client.get(path)
        r.check(f"{what} opens", status == 200, f"status {status}")
        r.check(f"{what} names itself", "Header &amp; Footer" in html or "Header & Footer" in html)

    _, index = client.get("/?s=chrome")
    r.check("the index says what is in each part",
            "navigation link" in index and "contact row" in index and "key" in index,
            "the counts are not on the index")
    r.check("and says where the derived columns come from",
            "Read from the Services editor" in index
            and "Read from the profiles on the SEO screen" in index)

    # --------------------------------------------------------- every field
    r.section("every field the header holds")

    _, html = client.get(header)
    fields = form_fields(html)
    fields.update({
        "do": "save",
        "header[brand_label]": f"{MARK}-brand",
        "header[logo][alt]":   f"{MARK}-alt",
        "nav[items][0][label]": f"{MARK}-navlabel",
    })
    status, headers, _ = client.post(header, fields)
    r.check("the header saves", status == 302, f"status {status}")

    _, html = client.get(header)
    back = form_fields(html)
    for name, want in [("header[brand_label]", f"{MARK}-brand"),
                       ("header[logo][alt]", f"{MARK}-alt"),
                       ("nav[items][0][label]", f"{MARK}-navlabel")]:
        r.check(f"  {name} came back", back.get(name) == want, repr(back.get(name)))

    r.check("and it reached the live site", published(site).get("header", {}).get("brand_label")
            == f"{MARK}-brand", "the publish stub was not sent the new header")

    r.section("every field the footer holds")

    _, html = client.get(footer)
    fields = form_fields(html)
    fields.update({
        "do": "save",
        "footer[brand_label]": f"{MARK}-fbrand",
        "footer[tagline]":     f"{MARK}-tagline",
        "footer[description]": f"{MARK}-description",
        "links[heading]":      f"{MARK}-links",
        "services[heading]":   f"{MARK}-services",
        "services[index_label]": f"{MARK}-all",
        "contact[heading]":    f"{MARK}-contact",
        "copyright[name]":     f"{MARK}-name",
        "copyright[rights]":   f"{MARK}-rights",
        "contact[items][0][label]": f"{MARK}-office",
        "contact[items][0][lines]": f"{MARK}-one\n\n   \n{MARK}-two",
        "contact[items][0][note]":  f"{MARK}-note",
    })
    status, _, body = client.post(footer, fields)
    r.check("the footer saves", status == 302, f"status {status}  {body[:200]}")

    _, html = client.get(footer)
    back = form_fields(html)
    for name in ["footer[brand_label]", "footer[tagline]", "footer[description]",
                 "links[heading]", "services[heading]", "services[index_label]",
                 "contact[heading]", "copyright[name]", "copyright[rights]",
                 "contact[items][0][label]", "contact[items][0][note]"]:
        r.check(f"  {name} came back", MARK in str(back.get(name)), repr(back.get(name)))

    r.check("  a details textarea keeps its lines and drops the blank ones",
            back.get("contact[items][0][lines]") == f"{MARK}-one\n{MARK}-two",
            repr(back.get("contact[items][0][lines]")))

    r.section("every field the dock holds")

    _, html = client.get(dock)
    fields = form_fields(html)
    fields.update({
        "do": "save",
        "dock[menu_label]":            f"{MARK}-menu",
        "panel[items][0][label]":       f"{MARK}-panellabel",
        "panel[items][0][description]": f"{MARK}-paneldesc",
        "bar[items][0][label]":         f"{MARK}-bar",
        "bar[items][0][icon]":          "server",
        "bar[items][0][emphasis]":      "disc",
    })
    status, _, body = client.post(dock, fields)
    r.check("the dock saves", status == 302, f"status {status}  {body[:200]}")

    _, html = client.get(dock)
    back = form_fields(html)
    for name, want in [("dock[menu_label]", f"{MARK}-menu"),
                       ("panel[items][0][label]", f"{MARK}-panellabel"),
                       ("panel[items][0][description]", f"{MARK}-paneldesc"),
                       ("bar[items][0][label]", f"{MARK}-bar"),
                       ("bar[items][0][icon]", "server"),
                       ("bar[items][0][emphasis]", "disc")]:
        r.check(f"  {name} came back", back.get(name) == want, repr(back.get(name)))

    # ------------------------------------------- one screen, one part saved
    r.section("a save touches only the part that was on the screen")

    doc = stored()
    r.check("the header still holds what the header screen saved",
            doc["header"]["brand_label"] == f"{MARK}-brand")
    r.check("and the footer still holds what the footer screen saved",
            doc["footer"]["tagline"] == f"{MARK}-tagline")
    r.check("and the dock still holds what the dock screen saved",
            doc["dock"]["menu_label"] == f"{MARK}-menu",
            "one screen's save emptied another's part")


def rows(client, r, site):
    """Add, remove, reorder and hide, on every band that has rows."""
    header = "/?s=chrome&part=header"
    footer = "/?s=chrome&part=footer"
    dock   = "/?s=chrome&part=dock"

    r.section("adding a row")
    for what, path, band in [("a navigation link", header, "nav"),
                             ("a quick link", footer, "links"),
                             ("a bottom-bar link", footer, "legal"),
                             ("a contact detail", footer, "contact"),
                             ("a dock section", dock, "panel")]:
        _, html = client.get(path)
        fields = form_fields(html)
        before = len([k for k in fields if k.startswith(f"{band}[items][")
                      and k.endswith("][id]")])

        fields["do"] = f"{band}-add:0"
        _, _, body = client.post(path, fields)
        after = len(re.findall(rf'name="{re.escape(band)}\[items\]\[\d+\]\[id\]"', body))

        r.check(f"{what}: the band gains a row", after == before + 1,
                f"{before} -> {after}")

        # A NEW ROW ARRIVES HIDDEN. An empty entry appearing in the navigation
        # of every page of the site the moment somebody presses Add is not what
        # pressing Add means.
        last = re.findall(
            rf'name="{re.escape(band)}\[items\]\[{after - 1}\]\[status\]".*?</select>',
            body, re.S)
        r.check(f"{what}: and it arrives hidden",
                bool(last) and 'value="hidden" selected' in last[0],
                "a new row is shown by default")

        r.check(f"{what}: and nothing was written to the site yet",
                f"{band}-add" not in json.dumps(stored()),
                "pressing Add must not save")

    r.section("removing and reordering")

    # Two rows with tellable-apart labels, saved, then moved.
    _, html = client.get(footer)
    fields = form_fields(html)
    fields.update({"do": "save",
                   "legal[items][0][label]": f"{MARK}-first",
                   "legal[items][1][target]": "privacy",
                   "legal[items][1][label]": f"{MARK}-second",
                   "legal[items][1][status]": "shown"})
    client.post(footer, fields)

    _, html = client.get(footer)
    fields = form_fields(html)
    ids = [fields[f"legal[items][{i}][id]"] for i in (0, 1)]
    fields["do"] = "legal-down:0"
    _, _, body = client.post(footer, fields)

    after = re.findall(r'name="legal\[items\]\[\d+\]\[id\]" value="([^"]*)"', body)
    r.check("moving a row down swaps it with the next", after[:2] == ids[::-1],
            f"{ids} -> {after[:2]}")

    # AN ID IS PERMANENT. contract_identify_rows() only fills an id that is
    # empty, and the form carries it in a hidden field, so a row keeps the id
    # it was minted with through any number of reorders.
    r.check("and both rows keep the ids they were minted with",
            sorted(after[:2]) == sorted(ids), f"{ids} -> {after[:2]}")

    fields = form_fields(body)
    fields["do"] = "legal-remove:0"
    _, _, body = client.post(footer, fields)
    after = re.findall(r'name="legal\[items\]\[\d+\]\[id\]" value="([^"]*)"', body)
    r.check("removing a row takes it out of the form", ids[1] not in after, str(after))

    r.section("the dock bar is four keys, and stays four")

    _, html = client.get(dock)
    fields = form_fields(html)
    r.check("there is no Add button for the bar", 'value="bar-add:0"' not in html)
    r.check("and no Remove button on a key", 'value="bar-remove:0"' not in html)
    r.check("but the keys can be reordered",
            'value="bar-down:0"' in html and 'value="bar-up:1"' in html)

    ids = re.findall(r'name="bar\[items\]\[\d+\]\[id\]" value="([^"]*)"', html)
    fields["do"] = "bar-down:0"
    _, _, body = client.post(dock, fields)
    after = re.findall(r'name="bar\[items\]\[\d+\]\[id\]" value="([^"]*)"', body)
    r.check("a key moves", after[:2] == ids[:2][::-1], f"{ids} -> {after}")
    r.check("and there are still four", len(after) == 4, str(after))

    # A press this side cannot honour redraws unchanged rather than erroring.
    fields = form_fields(body)
    fields["do"] = "bar-remove:0"
    _, _, body = client.post(dock, fields)
    r.check("a remove the bar does not offer changes nothing",
            len(re.findall(r'name="bar\[items\]\[\d+\]\[id\]"', body)) == 4)

    r.section("hiding a row")

    _, html = client.get(header)
    fields = form_fields(html)
    fields.update({"do": "save", "nav[items][0][status]": "hidden"})
    client.post(header, fields)

    doc = stored()
    r.check("a hidden navigation row is kept, not deleted",
            doc["header"]["nav"]["items"][0]["status"] == "hidden"
            and doc["header"]["nav"]["items"][0]["target"] != "")
    r.check("and it is what was published",
            published(site)["header"]["nav"]["items"][0]["status"] == "hidden")


def refusals(client, r):
    """What the editor will not save, and what it deliberately will."""
    header = "/?s=chrome&part=header"
    footer = "/?s=chrome&part=footer"
    dock   = "/?s=chrome&part=dock"

    r.section("a destination is picked, never typed")

    _, html = client.get(header)
    r.check("the picker offers every route and every service",
            html.count('<option value="service:') >= 6
            and 'value="about"' in html and 'value="contact"' in html)
    r.check("and does not offer the 404, which has no address of its own",
            'value="notfound"' not in html)

    fields = form_fields(html)
    fields.update({"do": "save", "nav[items][0][target]": "no-such-page",
                   "nav[items][0][status]": "shown"})
    status, _, body = client.post(header, fields)
    r.check("a destination that is not a page is refused",
            status == 200 and "no longer exists" in body, f"status {status}")

    fields = form_fields(html)
    fields.update({"do": "save", "nav[items][0][target]": "",
                   "nav[items][0][status]": "shown"})
    status, _, body = client.post(header, fields)
    r.check("and so is a row with no destination at all",
            status == 200 and "has no destination" in body, f"status {status}")

    r.check("but the refused row is still in the form afterwards",
            'name="nav[items][0][target]"' in body,
            "a refusal must not lose what was typed")

    r.section("what a heading may not be")

    _, html = client.get(footer)
    fields = form_fields(html)
    fields.update({"do": "save", "links[heading]": ""})
    status, _, body = client.post(footer, fields)
    r.check("an empty column heading is refused",
            status == 200 and "cannot be empty" in body, f"status {status}")

    # THE PICTURE IS NOT TYPED HERE ANY MORE, so a path pointing at another
    # site cannot be typed here either. This screen used to hold eleven text
    # fields per part describing the mark — two paths, two srcset lists, a WebP
    # list, a width, a height and a displayed size — and refused a foreign
    # address among them. The mark is uploaded on ?s=settings now, where the
    # same refusal lives beside the field somebody would have to fix, and
    # tools/test_settings_admin.py asserts it.
    _, html = client.get(header)
    r.check("the logo's picture is not typed on this screen at all",
            "header[logo][light][src]" not in html
            and "header[logo][width]" not in html,
            "a picture field is still here")
    # Nor how wide it is DRAWN. That is a fact about the layout — a clamp() in
    # layout.css — and what had been typed in this box said 140/180px for a
    # lockup measured at 79-113. It is CONTRACT_IMAGE_SLOTS now.
    r.check("nor how wide it is drawn",
            "header[logo][sizes]" not in html, "the sizes field is still here")
    r.check("and it says where the picture is instead",
            "?s=settings&amp;part=logo" in html, "no pointer to the settings screen")

    r.section("what it deliberately does not refuse")

    _, html = client.get(header)
    fields = form_fields(html)
    fields.update({"do": "save", "nav[items][0][target]": "",
                   "nav[items][0][status]": "hidden"})
    status, _, _ = client.post(header, fields)
    r.check("a HIDDEN row may be half-finished", status == 302,
            "hiding is how somebody parks a row they are still working on")

    _, html = client.get(dock)
    fields = form_fields(html)
    fields.update({"do": "save", "bar[items][0][label]": ""})
    status, _, body = client.post(dock, fields)
    r.check("but a dock key may not be, because a key has no fallback",
            status == 200 and "has no label" in body, f"status {status}")


def drift_and_import(client, r):
    """The notice that reports, and the button that seeds."""
    footer = "/?s=chrome&part=footer"

    r.section("the standing notice about the contact rows")

    _, html = client.get(footer)
    r.check("it says the rows are the footer's own",
            "footer&#039;s own contact details" in html or "footer's own contact details" in html,
            "the standing notice is not on the footer screen")

    # Make the footer say something the Contact page does not.
    fields = form_fields(html)
    fields.update({"do": "save",
                   "contact[items][0][kind]": "phone",
                   "contact[items][0][status]": "shown",
                   "contact[items][0][lines]": "+880 1000000000"})
    status, _, _ = client.post(footer, fields)

    # IT NEVER BLOCKS A SAVE. That is the whole bargain: after an office moves,
    # whichever of the two pages you edited first would be unsavable if
    # agreement were a rule.
    r.check("a footer detail the Contact page does not have still saves",
            status == 302, f"status {status}")

    _, html = client.get(footer)
    r.check("and the notice then says so",
            "the Contact page does not" in html, "no drift was reported")
    r.check("naming the value it means",
            "+880 1000000000" in html)

    _, index = client.get("/?s=chrome")
    r.check("the index carries the same notice",
            "the Contact page does not" in index)

    # A HIDDEN ROW IS NOT COMPARED: hiding is how an operator says "not in the
    # footer", and it cannot be stale if nobody can read it.
    fields = form_fields(html)
    fields.update({"do": "save", "contact[items][0][status]": "hidden"})
    client.post(footer, fields)
    _, html = client.get(footer)
    r.check("a hidden row is not reported as drift",
            "the Contact page does not" not in html,
            "hiding a row must not raise the notice")

    r.section("copy from the Contact page")

    contact = json.loads(CONTACT.read_text())
    office  = contact["offices"]["items"][0]["name"]

    _, html = client.get(footer)
    before = stored()

    fields = form_fields(html)
    fields["do"] = "contact-import"
    status, _, body = client.post(footer, fields)

    r.check("the button fills the rows in", status == 200 and office in body,
            f"status {status}")
    r.check("with a row per kind, in footer order",
            body.index('value="phone" selected') < body.index('value="email" selected')
            < body.index('value="address" selected') < body.index('value="hours" selected'),
            "the imported rows are not in the order a footer wants")
    r.check("and every imported row arrives shown",
            'value="hidden" selected' not in
            body[body.index('id="band-contact"'):body.index('id="band-bottom"')],
            "an imported detail should be ready to save, not parked")

    # A ROW ACTION SAVES NOTHING. Every add, remove, move and this share that
    # contract: they submit the form, change what is in it, and redraw.
    r.check("but it wrote nothing to the site", stored() == before,
            "the import must fill the form and save nothing")

    fields = form_fields(body)
    fields["do"] = "save"
    status, _, _ = client.post(footer, fields)
    r.check("and saving the imported rows works", status == 302, f"status {status}")

    _, html = client.get(footer)
    r.check("after which there is nothing left to report",
            "the Contact page does not" not in html,
            "importing then saving should clear the notice")


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")
    if not DATA.exists():
        raise SystemExit(f"Missing {DATA.relative_to(ROOT)}")

    backup = DATA.read_bytes()
    port = free_port()

    work = Path(tempfile.mkdtemp(prefix="t4t-chrome-"))
    private = work / "private"

    key = bytes.fromhex("b7" * 32)
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
            rows(client, r, site)
            refusals(client, r)
            drift_and_import(client, r)
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
