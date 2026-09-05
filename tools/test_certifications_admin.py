#!/usr/bin/env python3
"""
Exercise the resource certifications editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_certifications_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/certifications.php writes content/certifications.json, and the
frontend's pages/resource-certifications/index.php renders whatever it finds
there. Code that writes files is worth a test: a bug in the save path does not
announce itself, it shows up as a role group that quietly lost half its list.

It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because the
form and the page both walk the lists in loops and the field names are
expressions rather than literals. So this proves it by round trip instead.

THREE LISTS DEEP, WHICH IS THE POINT
This is the first editor with a list inside a list. A role group holds roles
AND certifications, and every one of the three levels has to add, remove,
reorder and hide independently of the other two. Most of what follows is that:
not that a button worked, but that it worked on the row it named and left the
other levels alone.

The point of the rest is not that the editor accepted a change — it is that the
change reached the LIVE SITE, in the right shape. This half ends at the
publish; what the frontend then renders is proved there, by
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
DATA = ROOT / "content" / "certifications.json"

ADMIN = "/?s=certifications"

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



# ------------------------------------------------------------------- tests

def groups_sent(site) -> list:
    return published(site).get("certs", {}).get("items", [])


def press(client, r, fields, do, what):
    """Press one of the row buttons, and hand back the redrawn form."""
    status, _headers, page = client.post(ADMIN, {**fields, "do": do})
    r.check(f"{what}: the form comes back", status == 200, f"status {status}")
    return page


def run(client, r, site):
    print("the editor renders")

    status, page = client.get(ADMIN)
    r.check("the screen is served", status == 200, f"status {status}")
    for band in ("band-hero", "band-certs", "band-cta", "band-meta"):
        r.check(f"it has {band}", f'id="{band}"' in page)

    fields = form_fields(page)
    r.check("it carries a CSRF token", "csrf" in fields)

    print("\nthe counts are shown, not remembered")

    r.check("the lead is stored with a token, not a number",
            "{certifications}" in fields["certs[lead]"], fields["certs[lead]"])
    r.check("and the editor says what it comes out as",
            "54 certifications across the four specialist roles" in page,
            "the resolved sentence is not on the screen")
    flat = re.sub(r"\s+", " ", page)
    for token in ("{certifications}", "{groups}", "{roles}",
                  "{certifications-word}", "{groups-word}", "{roles-word}"):
        r.check(f"{token} can be inserted without typing it",
                f'data-token-insert="{token}"' in page)

    r.check("each token says what it currently comes to",
            "{groups}</button> 4" in flat, "the live value is not beside the token")
    r.check("including the spelled form",
            "{groups-word}</button> four" in flat)

    print("\nthe four groups it starts with")

    r.check("all four are on the screen",
            len(re.findall(r'name="certs\[items\]\[\d+\]\[slug\]"', page)) == 4)
    r.check("the first is open and the others are not",
            len(re.findall(r'<option value="open" selected', page)) == 1,
            "exactly one group should start expanded")

    print("\nadding a role group")

    page = press(client, r, fields, "group-add:0", "add a group")
    fields = form_fields(page)
    slugs = re.findall(r'name="certs\[items\]\[(\d+)\]\[slug\]"', page)
    r.check("there are five now", len(slugs) == 5, str(slugs))
    r.check("and it arrived hidden",
            fields["certs[items][4][status]"] == "hidden",
            fields.get("certs[items][4][status]", "missing"))
    r.check("it says so", "hidden until you show it" in page)

    print("\nadding a role and a certification inside it")

    # Press Add first, THEN name the row it created. Naming a row that does
    # not exist yet posts a field the form never rendered, which the editor
    # reads as another row -- so the group would end up with two of everything
    # and one of each blank.
    page = press(client, r, fields, "role-4-add:0", "add a role")
    fields = form_fields(page)
    r.check("the new group has a role",
            "certs[items][4][roles][0][name]" in fields)
    fields["certs[items][4][roles][0][name]"] = "Quantum Analyst"

    page = press(client, r, fields, "cert-4-add:0", "add a certification")
    fields = form_fields(page)
    r.check("and a certification",
            "certs[items][4][items][0][name]" in fields)
    fields["certs[items][4][items][0][name]"] = "Post-Quantum Practitioner"

    print("\na button on one group leaves the others alone")

    before = fields["certs[items][0][items][0][name]"]
    second = fields["certs[items][0][items][1][name]"]
    page = press(client, r, fields, "cert-0-down:0", "move a certification down")
    fields = form_fields(page)
    r.check("the two swapped in the group named",
            fields["certs[items][0][items][0][name]"] == second
            and fields["certs[items][0][items][1][name]"] == before,
            f"{fields['certs[items][0][items][0][name]']!r}")
    r.check("and the next group is untouched",
            fields["certs[items][1][items][0][name]"]
            == form_fields(client.get(ADMIN)[1])["certs[items][1][items][0][name]"])

    page = press(client, r, fields, "cert-0-up:1", "move it back")
    fields = form_fields(page)
    r.check("moving it back restores the order",
            fields["certs[items][0][items][0][name]"] == before)

    print("\nremoving")

    # A second role, so removing one leaves the group still valid.
    page = press(client, r, fields, "role-4-add:0", "add a second role")
    fields = form_fields(page)
    fields["certs[items][4][roles][1][name]"] = "Lattice Reviewer"
    r.check("there are two roles to choose between",
            "certs[items][4][roles][1][name]" in fields)

    page = press(client, r, fields, "role-4-remove:1", "remove the second role")
    fields = form_fields(page)
    r.check("the second role is gone", "certs[items][4][roles][1][name]" not in fields)
    r.check("the first is not",
            fields.get("certs[items][4][roles][0][name]") == "Quantum Analyst",
            fields.get("certs[items][4][roles][0][name]", "missing"))
    r.check("and the certification beside them is not either",
            "certs[items][4][items][0][name]" in fields)

    print("\nsaving what is left")

    fields["certs[items][4][icon]"] = "cloud"
    fields["certs[items][4][status]"] = "shown"

    status, headers, page = client.post(ADMIN, {**fields, "do": "save"})
    r.check("saving redirects rather than redrawing", status in (302, 303),
            f"status {status}: " + (page[:200] if status == 200 else ""))

    sent = groups_sent(site)
    r.check("five groups reached the live site", len(sent) == 5, str(len(sent)))
    r.check("the new one kept its role",
            [x["name"] for x in sent[4]["roles"]] == ["Quantum Analyst"],
            str(sent[4]["roles"]))
    r.check("and its certification",
            [x["name"] for x in sent[4]["items"]] == ["Post-Quantum Practitioner"],
            str(sent[4]["items"]))
    r.check("it was given an anchor of its own",
            sent[4]["slug"] == "quantum-analyst", sent[4]["slug"])
    r.check("the four it started with are still whole",
            [len(g["items"]) for g in sent[:4]] == [27, 11, 11, 5],
            str([len(g["items"]) for g in sent[:4]]))
    r.check("only the first group is open",
            [g["open"] for g in sent] == [True, False, False, False, False],
            str([g["open"] for g in sent]))

    print("\nthe anchor survives a rename")

    status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["certs[items][4][roles][0][name]"] = "Cryptography Analyst"
    status, headers, page = client.post(ADMIN, {**fields, "do": "save"})
    r.check("the rename saves", status in (302, 303), f"status {status}")

    sent = groups_sent(site)
    r.check("the role is renamed",
            sent[4]["roles"][0]["name"] == "Cryptography Analyst",
            sent[4]["roles"][0]["name"])
    r.check("and the web address did NOT move with it",
            sent[4]["slug"] == "quantum-analyst",
            "a link into the page would have broken: " + sent[4]["slug"])

    print("\nwhat it refuses")

    status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["certs[items][4][items][0][name]"] = ""
    status, headers, page = client.post(ADMIN, {**fields, "do": "save"})
    r.check("a certification with no name is refused", status == 200, f"status {status}")
    r.check("and it says which one",
            "certification 1 has no name" in page.lower(),
            "no message naming the row")

    status, page = client.get(ADMIN)
    fields = form_fields(page)
    for key in list(fields):
        if re.match(r"certs\[items\]\[4\]\[roles\]\[\d+\]\[name\]", key):
            fields[key] = ""
    status, headers, page = client.post(ADMIN, {**fields, "do": "save"})
    r.check("a group with no role names is refused", status == 200, f"status {status}")
    r.check("and says why", "no role names" in page, "no message about the roles")

    print("\ntidying up")

    status, page = client.get(ADMIN)
    fields = form_fields(page)
    status, headers, page = client.post(client_path := ADMIN,
                                        {**fields, "do": "group-remove:4"})
    fields = form_fields(page)
    status, headers, page = client.post(client_path, {**fields, "do": "save"})
    r.check("the added group can be removed again", status in (302, 303), f"status {status}")
    r.check("four groups remain", len(groups_sent(site)) == 4,
            str(len(groups_sent(site))))
    r.check("with every certification still on them",
            [len(g["items"]) for g in groups_sent(site)] == [27, 11, 11, 5],
            str([len(g["items"]) for g in groups_sent(site)]))


def published(site) -> dict:
    """The about document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend
    then DOES with the document — the <details> panels, the derived counts, the
    tokens filled into the prose — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered page.

    Splitting it this way is not a loss of coverage so much as an honest
    statement of where each half's responsibility ends. What it does cost is
    that neither test alone proves a field survives the whole trip; the model
    they share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("certifications", {})


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
    work = Path(tempfile.mkdtemp(prefix="t4t-certs-"))
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
