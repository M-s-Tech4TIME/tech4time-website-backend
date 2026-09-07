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
declares is both editable and rendered — which it cannot do here, because both
walk the lists in loops and the field names are expressions rather than
literals. So this proves it by round trip instead.

THREE LISTS DEEP, WHICH IS ONE DEEPER THAN ANY EDITOR BEFORE IT
Sections hold blocks; a list, an address or a table holds rows. So most of what
follows is not that a button worked — it is that it worked on the row it named
and left the other two levels alone.

AND THE ROWS ARE TYPED, WHICH IS NEW
A block declares which of six kinds it is, and the renderer owns the markup for
that kind. Changing a kind must narrow the block to the fields that kind uses,
or a block that was a list keeps its rows for ever: invisible on the page,
carried in the document, published every time.

THE ANCHOR RULE IS THE ONE WITH TEETH
A section's id is its web address. Somebody may have linked to it from an email
this repository cannot see, so a section that has been named keeps its fragment
— even when a new section with the same heading is added above it. That case is
checked directly, because the obvious one-pass implementation gets it wrong and
the failure is silent.

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
The sign-in itself, which is tools/test_admin_auth.py's subject.
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
    DOES with the document — the sections, the six block kinds, the anchors —
    is proved in tech4time-website-frontend, by test_publish.py. The model they
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


def kinds(page, s):
    return re.findall(
        r'name="sections\[' + str(s) + r'\]\[blocks\]\[\d+\]\[kind\]">(.*?)</select>',
        page, re.S)


def chosen_kinds(page, s):
    """The kind each block of section s currently is, in order."""
    out = []
    for body in kinds(page, s):
        m = re.search(r'<option value="([^"]*)"[^>]*\bselected', body)
        out.append(m.group(1) if m else "")
    return out


def run(client, r, site):
    print("the editor renders")

    status, page = client.get(ADMIN)
    r.check("the screen is served", status == 200, f"status {status}")
    for band in ("band-hero", "band-facts", "band-callout", "band-sections",
                 "band-cta", "band-meta"):
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

    print("\nthe twelve sections it starts with")

    heads = headings(page)
    r.check("all twelve are on the screen", len(heads) == 12, f"{len(heads)} headings")
    r.check("the first is the one the page opens with",
            heads[0] == "Who is responsible for your data", heads[0] if heads else "")
    r.check("each carries its own anchor",
            fields.get("sections[6][id]") == "your-rights",
            fields.get("sections[6][id]", "missing"))
    r.check("the anchor is shown beside the heading it will not follow",
            "<code>#your-rights</code>" in page)

    print("\nall six block kinds are represented, and each says what it is")

    present = {k for s in range(12) for k in chosen_kinds(page, s)}
    for kind in ("paragraph", "list", "subheading", "note", "address", "table"):
        r.check(f"a {kind} block is in the document", kind in present)

    r.check("the table carries its caption",
            fields.get("sections[4][blocks][0][caption]")
            == "Retention periods by type of data",
            fields.get("sections[4][blocks][0][caption]", "missing"))
    r.check("and two column headings",
            [fields.get("sections[4][blocks][0][columns][0]"),
             fields.get("sections[4][blocks][0][columns][1]")] == ["What", "How long"])
    r.check("the address block kept its telephone link",
            'tel:+8801320571562' in fields.get("sections[0][blocks][1][text]", ""),
            fields.get("sections[0][blocks][1][text]", "")[:80])

    print("\nthe effective date is a field, and only a field")

    r.check("it is on the screen", fields.get("policy[effective]") == "Effective 21 August 2026",
            fields.get("policy[effective]", "missing"))
    r.check("the form says nothing changes it for you",
            "Fixing a typo is not a new policy" in page)

    print("\nediting a paragraph reaches the live site")

    fields["sections[9][blocks][0][text]"] = "<p>Children marker <strong>A7</strong>.</p>"
    status, headers, _ = save(client, fields)
    r.check("the save redirected", status in (302, 303), f"status {status}")
    sent = sections_sent(site)
    r.check("the live site was sent the document", len(sent) == 12, f"{len(sent)} sections")
    r.check("with the edited paragraph in it",
            "Children marker <strong>A7</strong>" in sent[9]["blocks"][0]["text"],
            sent[9]["blocks"][0].get("text", "")[:90])

    print("\nadding a section, and the anchor rule that has teeth")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    page = press(client, r, fields, "section-add:0", "adding a section")
    fields = form_fields(page)
    r.check("there are thirteen now", len(headings(page)) == 13)
    r.check("the new one arrives hidden",
            fields.get("sections[12][status]") == "hidden",
            fields.get("sections[12][status]", "missing"))

    # Name it exactly as an existing section, and move it above that one.
    fields["sections[12][heading]"] = "Your rights"
    fields["sections[12][status]"] = "shown"
    # The index moves with the row. Pressing "section-up:12" a second time
    # would move whatever landed at 12, not the row being dragged.
    for at in range(12, 6, -1):
        page = press(client, r, fields, f"section-up:{at}", f"moving it up from {at}")
        fields = form_fields(page)

    heads = headings(page)
    r.check("it sits above the section it was named after",
            heads.index("Your rights") < len(heads) - 1
            and heads[6] == "Your rights" and heads[7] == "Your rights",
            str(heads[5:9]))
    r.check("the SECTION THAT ALREADY HAD THE ANCHOR KEEPS IT",
            fields.get("sections[7][id]") == "your-rights",
            f'the incumbent became {fields.get("sections[7][id]")!r} — a link that was '
            f'a promise now lands on the wrong section')
    r.check("and the newcomer is given a different one",
            fields.get("sections[6][id]") == "your-rights-2",
            fields.get("sections[6][id]", "missing"))

    page = press(client, r, fields, "section-remove:6", "removing the newcomer")
    fields = form_fields(page)
    r.check("twelve again", len(headings(page)) == 12)
    r.check("and the original still holds the anchor",
            fields.get("sections[6][id]") == "your-rights")

    print("\nblocks: added at the kind you asked for, in the section you named")

    before = len(chosen_kinds(page, 3))
    fields["addkind[3]"] = "table"
    page = press(client, r, fields, "block-3-add:0", "adding a table to section 3")
    fields = form_fields(page)
    now = chosen_kinds(page, 3)
    r.check("section 3 gained a block", len(now) == before + 1, f"{len(now)} vs {before}")
    r.check("and it is the kind that was asked for", now[-1] == "table", now[-1])
    r.check("no other section was touched",
            len(chosen_kinds(page, 4)) == 3, str(len(chosen_kinds(page, 4))))

    page = press(client, r, fields, f"block-3-remove:{before}", "removing it again")
    fields = form_fields(page)
    r.check("section 3 is as it was", len(chosen_kinds(page, 3)) == before)

    print("\nchanging a block's kind narrows it to that kind's fields")

    r.check("the list block has rows to begin with",
            "sections[1][blocks][3][rows][0][text]" in fields)
    fields["sections[1][blocks][3][kind]"] = "paragraph"
    page = press(client, r, fields, "block-1-up:3", "changing kind, then pressing a button")
    fields = form_fields(page)
    r.check("it is drawn as a paragraph now",
            chosen_kinds(page, 1)[2] == "paragraph", str(chosen_kinds(page, 1)[:4]))
    r.check("and its rows are gone rather than carried invisibly",
            "sections[1][blocks][2][rows][0][text]" not in fields)

    print("\nrows inside a block move without disturbing their neighbours")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    first = fields["sections[1][blocks][3][rows][0][text]"]
    second = fields["sections[1][blocks][3][rows][1][text]"]
    other = fields["sections[2][blocks][1][rows][0][text]"]

    page = press(client, r, fields, "row-1-3-down:0", "moving a bullet down")
    fields = form_fields(page)
    r.check("the two bullets swapped",
            fields["sections[1][blocks][3][rows][0][text]"] == second
            and fields["sections[1][blocks][3][rows][1][text]"] == first)
    r.check("a list in another section is untouched",
            fields["sections[2][blocks][1][rows][0][text]"] == other)

    print("\nhiding happens at every level, and hiding is not deleting")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["sections[8][status]"] = "hidden"
    fields["sections[2][blocks][1][status]"] = "hidden"
    fields["sections[2][blocks][1][rows][0][status]"] = "hidden"
    fields["callout[items][0][status]"] = "hidden"
    save(client, fields)

    sent = sections_sent(site)
    r.check("the hidden section is still IN the document",
            len(sent) == 12 and sent[8]["status"] == "hidden",
            f'{len(sent)} sections, section 8 is {sent[8].get("status") if len(sent) > 8 else "gone"}')
    r.check("so is the hidden block", sent[2]["blocks"][1]["status"] == "hidden")
    r.check("and the hidden row", sent[2]["blocks"][1]["rows"][0]["status"] == "hidden")
    r.check("and the hidden summary point",
            published(site)["policy"]["callout"]["items"][0]["status"] == "hidden")

    print("\nwhat the editor refuses, and what it does not")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["policy[effective]"] = ""
    _status, _h, body = save(client, fields)
    r.check("a policy with no effective date is refused",
            "no effective date" in body, "it saved without one")

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["sections[5][heading]"] = ""
    _status, _h, body = save(client, fields)
    r.check("a section with no heading is refused", "has no heading" in body)

    _status, page = client.get(ADMIN)
    fields = form_fields(page)
    fields["sections[4][blocks][0][caption]"] = ""
    _status, _h, body = save(client, fields)
    r.check("a table with no caption is refused, because a screen reader needs it",
            "no caption" in body)

    print("\nthe comparison with the contact page is a notice and never a refusal")

    _status, page = client.get(ADMIN)
    r.check("the comparison is drawn on the screen",
            "the policy still says this" in page)
    r.check("and it says plainly that it does not block a save",
            "Nothing here stops you saving" in page)

    fields = form_fields(page)
    # Rewrite the address block so the policy no longer states the Dhaka office.
    fields["sections[0][blocks][1][text]"] = "<strong>Somebody Else</strong><br>Nowhere at all"
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
