#!/usr/bin/env python3
"""
Exercise the contact page editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_contact_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/contact.php writes content/contact.json, and
pages/contact/index.php renders whatever it finds there. Code that writes files
is worth a test: a bug in the save path does not announce itself, it shows up
as an office that quietly lost its phone number.

The point of most of what follows is not that the editor accepted a change —
it is that the change reached the page a visitor sees, and reached it in the
right shape. So nearly every case saves through the editor and then reads
/pages/contact/ back.

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
DATA = ROOT / "content" / "contact.json"

ADMIN = "/?s=contact"

ROUTER = ROOT / "tools" / "dev-router.php"


def registered_sections() -> list[str]:
    """Every section lib/admin.php knows about, in registry order.

    A number written into this file is a second copy of the registry, and it is
    the copy that goes stale: adding an editor would fail this test in the
    editor that was not touched, which reads as a regression in the wrong
    place. Parsed rather than run through PHP, the way publish_stub.py reads
    CONTRACT_DOCUMENTS.
    """
    text = (ROOT / "lib" / "admin.php").read_text()
    m = re.search(r"const ADMIN_SECTIONS\s*=\s*\[(.*?)\n\];", text, re.S)
    return re.findall(r"^\s{4}'([a-z_]+)'\s*=>", m.group(1), re.M) if m else []


def rail_sections() -> list[str]:
    """The rail's ROWS, which are not the same list.

    The account is a section but not a rail row: it is reached from the avatar
    menu at the foot of the rail. Two lists mean a new way to be wrong -- a
    rail row naming a section that does not exist -- so that is asserted below
    rather than left to the day somebody notices a blank row.
    """
    text = (ROOT / "lib" / "admin.php").read_text()
    m = re.search(r"const ADMIN_RAIL_SECTIONS\s*=\s*\[(.*?)\];", text, re.S)
    return re.findall(r"'([a-z_]+)'", m.group(1)) if m else []


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
    # ---------------------------------------------------------------- loads
    print("\nthe editor")
    status, html = client.get(ADMIN)
    r.check("it opens", status == 200, f"status {status}")
    r.check("it names the file it edits", "content/contact.json" in html)
    # The count is asserted rather than the names, so that adding a section
    # without adding it to the rail — which would leave it unreachable — shows
    # up here.
    sections = registered_sections()
    rail = rail_sections()
    r.check("the rail lists every row ADMIN_RAIL_SECTIONS names",
            html.count('class="rail__item"') == len(rail),
            f'{len(rail)} in ADMIN_RAIL_SECTIONS, '
            f'{html.count(chr(34) + "rail__item")} in the rail')
    r.check("every rail row names a section that exists",
            set(rail) <= set(sections),
            f"not in ADMIN_SECTIONS: {sorted(set(rail) - set(sections))}")
    # The account is deliberately NOT a rail row — it is reached from the
    # avatar menu at the foot of the rail, which is the one thing on the screen
    # that is about the person rather than the page. Asserted, because
    # dropping it from the registry instead would send ?s=account silently to
    # the Overview and put the password screen out of reach.
    r.check("the account is a section but not a rail row",
            "account" in sections and "account" not in rail)
    r.check("and the avatar menu still reaches it",
            's=account' in html)
    r.check("and marks the one showing",
            html.count('aria-current="page"') == 1)

    token = csrf_of(html)
    base = form_fields(html)
    r.check("the offices are all in the form",
            all(f'offices[items][{i}][name]' in base for i in range(3)))
    r.check("so are the reach rows",
            all(f'reach[items][{i}][label]' in base for i in range(4)))

    # The save button must be the first submit in the document, or pressing
    # Enter in any text field would fire "Add a row" instead.
    submits = re.findall(r'<button[^>]*type="submit"[^>]*value="([^"]*)"', html)
    r.check("pressing Enter would save, not add a row",
            submits and submits[0] == "save",
            f"first submit is {submits[0] if submits else 'none'!r}")

    # ------------------------------------------------------------- editing
    print("\nediting the page")
    fields = dict(base, csrf=token, do="save")
    fields["hero[title]"] = "Talk To Us"
    fields["offices[items][0][phones]"] = "+880 1111111111\n+880 2222222222"
    fields["offices[items][0][hours]"] = "Sat – Wed: 8:00 AM – 4:00 PM"

    status, headers, body = client.post(ADMIN, fields)
    r.check("saving redirects rather than re-rendering",
            status == 302, f"status {status}: {body[:160]}")

    doc = published(site)
    r.check("the new banner reaches the live site",
            doc.get("hero", {}).get("title") == "Talk To Us", str(doc.get("hero")))
    r.check("the old banner is gone", doc["hero"]["title"] != "Contact Us")

    first = offices_sent(site)[0]
    r.check("the new numbers reach the live site",
            "+880 1111111111" in first["phones"], str(first["phones"]))
    # Scoped to the card that was edited: the Brussels office is reached on
    # the Dhaka numbers, so one of them is still in the document, correctly.
    r.check("the number it replaced is gone from that card",
            not any("1320571562" in p for p in first["phones"]), str(first["phones"]))
    r.check("the new opening hours travel with it",
            first["hours"] == "Sat – Wed: 8:00 AM – 4:00 PM", str(first["hours"]))

    # ------------------------------------------------------------ the head
    #
    # THIS EDITOR NO LONGER WRITES THE meta BAND, and that is what is asserted.
    # Every page's title, search description and share title are edited on the
    # SEO screen; the values still live in content/contact.json, beside the
    # rest of this page, and are still published with it.
    #
    # The check is the dangerous direction, not the harmless one. Every
    # *_from_post() rebuilds each band named in its *_TEXT_FIELDS from $_POST,
    # so a form that has stopped RENDERING the meta fields while still naming
    # them in that loop would read them as absent, take '' for each, and blank
    # the page's title on every save -- silently, because empty is a valid
    # value and nothing throws. contract_page_bands() is what stops that, and
    # this is what would notice if it were undone.
    print("\nthe meta band survives a save that never touched it")
    before = published(site)["meta"]
    fields["hero[title]"] = "Contact Us"
    client.post(ADMIN, fields)
    after = published(site)["meta"]
    r.check("the browser tab title is not blanked", after["title"] == before["title"],
            f'{before["title"]!r} -> {after["title"]!r}')
    r.check("nor the search description", after["description"] == before["description"])
    r.check("nor anything else in the band", after == before,
            f"{before} -> {after}")
    # That the SEO screen can CHANGE them is proved in tools/test_seo_admin.py;
    # that the frontend renders them is proved in tech4time-website-frontend by
    # tools/test_publish.py.

    # -------------------------------------------------------- hidden office
    print("\nhiding an office")
    fields["offices[items][2][status]"] = "hidden"
    client.post(ADMIN, fields)
    sent = offices_sent(site)
    hidden = [o for o in sent if "Avenue Louise" in o.get("address", "")]
    r.check("a hidden office is published carrying its status",
            hidden and hidden[0]["status"] == "hidden", str(hidden)[:160])
    r.check("the others are untouched",
            any(o["status"] == "shown" and "Batu Caves" in o.get("address", "")
                for o in sent), str([o["status"] for o in sent]))

    _, html = client.get(ADMIN)
    r.check("but it is still in the editor, marked hidden",
            "Avenue Louise" in html
            and re.search(r"admin-row__status--draft[^>]*>\s*Hidden", html) is not None)

    fields["offices[items][2][status]"] = "shown"
    client.post(ADMIN, fields)
    r.check("showing it again publishes it as shown",
            all(o["status"] == "shown" for o in offices_sent(site)),
            str([o["status"] for o in offices_sent(site)]))

    # ------------------------------------------------- hiding a reach row
    print("\nhiding a reach row, and a whole band")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token)

    r.check("every reach row has a visibility control",
            all(f"reach[items][{i}][status]" in fields
                for i in range(len(reach_sent(site)))),
            str([k for k in fields if k.startswith("reach[items]")])[:200])

    fields["reach[items][0][status]"] = "hidden"
    client.post(ADMIN, fields)
    sent = reach_sent(site)
    r.check("a hidden reach row is published carrying its status",
            sent and sent[0]["status"] == "hidden",
            str([x.get("status") for x in sent]))
    r.check("the rows beside it are untouched",
            all(x["status"] == "shown" for x in sent[1:]),
            str([x.get("status") for x in sent]))

    _, html = client.get(ADMIN)
    r.check("and it is still in the editor, marked hidden",
            re.search(r"admin-row__status--draft[^>]*>\s*Hidden", html) is not None)

    fields["reach[items][0][status]"] = "shown"
    client.post(ADMIN, fields)
    r.check("showing it again publishes it as shown",
            all(x["status"] == "shown" for x in reach_sent(site)),
            str([x.get("status") for x in reach_sent(site)]))

    # A band's own switch. Separate from a row's: hiding every row one at a
    # time is not the same instruction as switching the section off, and only
    # one of them survives somebody adding a row later.
    for band in ("reach", "offices"):
        fields[f"{band}[status]"] = "hidden"
        client.post(ADMIN, fields)
        doc = published(site)
        r.check(f"the {band} band publishes its own hidden status",
                doc[band]["status"] == "hidden", str(doc[band].get("status")))
        r.check(f"and its rows are kept, not deleted",
                len(doc[band]["items"]) > 0, str(len(doc[band]["items"])))

        fields[f"{band}[status]"] = "shown"
        client.post(ADMIN, fields)
        r.check(f"switching the {band} band back on publishes it as shown",
                published(site)[band]["status"] == "shown")

    # ------------------------------------------------ an office's own flag
    print("\nthe flag an office can be given")
    _, html = client.get(ADMIN)

    # A host without GD cannot accept a picture and says so instead of offering
    # a control that could not work. That is the behaviour, not a gap in it --
    # upload_problem() is checked before the input is drawn -- so the assertion
    # follows the host rather than insisting on one shape of it.
    no_gd = "GD image library" in html
    if no_gd:
        r.check("an office says why it cannot take a flag, on a host without GD",
                'name="upload[offices][0]"' not in html,
                "a file input was offered on a host that cannot store one")
    else:
        r.check("every office offers a file input for a flag",
                'name="upload[offices][0]"' in html,
                "no upload control on the first office")
    r.check("and carries the four fields a stored picture needs",
            all(f'name="offices[items][0][image][{k}]"' in html
                for k in ("src", "webp", "width", "height")))

    fields = dict(form_fields(html), csrf=token)
    fields["offices[items][0][image][src]"] = "/uploads/abcdef0123456789.png"
    fields["offices[items][0][image][webp]"] = "/uploads/abcdef0123456789.webp"
    fields["offices[items][0][image][width]"] = "120"
    fields["offices[items][0][image][height]"] = "80"
    client.post(ADMIN, fields)

    first = offices_sent(site)[0]
    r.check("an uploaded flag is published with the office",
            first.get("image", {}).get("src") == "/uploads/abcdef0123456789.png",
            str(first.get("image")))
    r.check("with the size the file reported, so the card cannot jump",
            (first["image"]["width"], first["image"]["height"]) == (120, 80),
            str(first.get("image")))

    # The path is re-checked against CONTRACT_IMAGE_ROOTS on the way in: a
    # hidden input is a text field with the label taken off.
    fields["offices[items][0][image][src]"] = "https://evil.example/x.png"
    client.post(ADMIN, fields)
    r.check("a flag path pointing off this site is refused",
            offices_sent(site)[0]["image"]["src"] == "",
            str(offices_sent(site)[0]["image"]))

    fields["offices[items][0][image][src]"] = ""
    fields["offices[items][0][image][webp]"] = ""
    fields["offices[items][0][image][width]"] = "0"
    fields["offices[items][0][image][height]"] = "0"
    client.post(ADMIN, fields)

    # ------------------------------------------------------------- reorder
    print("\nreordering and removing")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="offices-down:0")
    client.post(ADMIN, fields)
    _, html = client.get(ADMIN)
    r.check("a move is NOT saved until the page is",
            first_office(html) == "Bangladesh",
            f"first office is {first_office(html)!r}")

    status, _, body = client.post(ADMIN, dict(form_fields(html), csrf=token,
                                              do="offices-down:0"))
    r.check("moving re-renders rather than redirecting", status == 200)
    r.check("and the move shows in the form", first_office(body) == "Malaysia",
            f"first office is {first_office(body)!r}")

    moved = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, moved)
    order = [o["name"] for o in offices_sent(site)]
    r.check("saving the move reorders the published document",
            order.index("Malaysia") < order.index("Bangladesh"), str(order))

    _, html = client.get(ADMIN)
    status, _, body = client.post(ADMIN, dict(form_fields(html), csrf=token,
                                              do="reach-remove:0"))
    r.check("removing a row re-renders", status == 200)
    r.check("and the row is gone from the form",
            body.count('name="reach[items][0][label]"') == 1
            and "info@tech4time.bd" not in form_fields(body).get("reach[items][0][values]", ""))

    added = dict(form_fields(body), csrf=token, do="reach-add")
    _, _, body = client.post(ADMIN, added)
    fields = form_fields(body)
    last = max(int(m) for m in re.findall(r"reach\[items\]\[(\d+)\]", body))
    r.check("adding a row appends an empty one",
            fields.get(f"reach[items][{last}][label]") == "")

    fields[f"reach[items][{last}][label]"] = "WhatsApp"
    fields[f"reach[items][{last}][type]"] = "phone"
    fields[f"reach[items][{last}][values]"] = "+880 1999999999"
    fields[f"reach[items][{last}][icon]"] = "mobile-alt"
    client.post(ADMIN, dict(fields, csrf=token, do="save"))
    row = next((i for i in reach_sent(site) if i["label"] == "WhatsApp"), None)
    r.check("the new row reaches the live site", row is not None,
            str([i["label"] for i in reach_sent(site)]))
    r.check("carrying the kind that decides how it links",
            row and row["type"] == "phone", str(row))
    r.check("and the number itself", row and row["values"] == ["+880 1999999999"],
            str(row))
    r.check("with the icon it was given", row and row["icon"] == "mobile-alt", str(row))
    # The icon list is in lib/contract.php and the sprite is shared, so an
    # icon offered here must be one the frontend can actually draw. That is
    # the whole reason CONTACT_ICONS is in the contract rather than here.
    r.check("and the sprite this editor draws from has that symbol",
            '<symbol id="mobile-alt"' in (DOCROOT / "assets" / "icons" / "sprite.svg").read_text())

    print("\nseveral numbers under one heading")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    phone_row = next(
        i for i in range(20)
        if fields.get(f"reach[items][{i}][type]") == "phone"
        and "1111111111" in fields.get(f"reach[items][{i}][values]", "")
        or fields.get(f"reach[items][{i}][label]") == "Phone"
    )
    fields[f"reach[items][{phone_row}][values]"] = (
        "+880 3333333333\n+880 4444444444\n+880 5555555555")
    status, _, _ = client.post(ADMIN, fields)
    r.check("three numbers in one row save", status == 302)

    row = next((i for i in reach_sent(site)
                if any("3333333333" in v for v in i["values"])), None)
    r.check("all three travel in one row",
            row is not None and len(row["values"]) == 3, str(row))
    r.check("each as its own value, so each can become its own link",
            row is not None
            and all(any(n in v for v in row["values"])
                    for n in ("3333333333", "4444444444", "5555555555")),
            str(row))
    r.check("under a single label", row is not None and row["label"] != "", str(row))

    # ---------------------------------------------------------- validation
    print("\nwhat it refuses")
    _, html = client.get(ADMIN)
    good = dict(form_fields(html), csrf=token, do="save")

    status, _, body = client.post(ADMIN, dict(good, **{"hero[title]": ""}))
    r.check("an empty banner title is refused", status == 200 and "Not saved" in body)

    bad_email = dict(good)
    bad_email["reach[items][0][type]"] = "email"
    bad_email["reach[items][0][values]"] = "not-an-address"
    status, _, body = client.post(ADMIN, bad_email)
    r.check("a value that is not an email address is refused",
            status == 200 and "is not one" in body)

    bad_url = dict(good)
    bad_url["reach[items][0][type]"] = "url"
    bad_url["reach[items][0][values]"] = "javascript:alert(1)"
    status, _, body = client.post(ADMIN, bad_url)
    r.check("a javascript: link is refused",
            status == 200 and "full web address" in body)

    bad_country = dict(good, **{"offices[items][0][schema][country]": "Bangladesh"})
    status, _, body = client.post(ADMIN, bad_country)
    r.check("a country that is not a two-letter code is refused",
            status == 200 and "two letters" in body)

    r.check("and what was typed is still in the form after a refusal",
            'value="Bangladesh"' in body)

    status, _, _ = client.post(ADMIN, dict(good, csrf="wrong"))
    r.check("a request without the token is refused", status == 400)

    sent = json.dumps(published(site))
    r.check("none of the refused values were published",
            "not-an-address" not in sent and "javascript:alert" not in sent,
            "a refused save must not reach the live site at all")

    # ------------------------------------------------------ sanitising HTML
    print("\nwhat it stores from the rich fields")
    for name, sent, expect_absent in [
        ("a script tag", "<p>Hi</p><script>alert(1)</script>", "alert(1)"),
        ("an event handler", '<p onclick="steal()">Hi</p>', "onclick"),
        ("an inline style", '<p style="color:red">Hi</p>', "style="),
        ("a javascript: link", '<p><a href="javascript:x()">Hi</a></p>', "javascript:"),
    ]:
        client.post(ADMIN, dict(good, **{"form[lead]": sent}))
        lead = published(site).get("form", {}).get("lead", "")
        r.check(f"{name} is not published", expect_absent not in lead, lead[:120])

    client.post(ADMIN, dict(good, **{
        "form[lead]": '<p>Ask about <strong>anything</strong>.</p>'
                      '<ul><li>Security</li><li>Cloud</li></ul>'}))
    lead = published(site).get("form", {}).get("lead", "")
    r.check("but ordinary formatting is",
            "<strong>anything</strong>" in lead and "<li>Security</li>" in lead, lead[:160])

    # ------------------------------------------------------ publishing again
    print("\nthe retry the failed-publish notice offers")

    # That notice posts three fields and nothing else: csrf, s and
    # action=republish. It must re-send what is ON FILE. It must not travel
    # through the save path, because the save path rebuilds the document out of
    # the form -- and out of THIS form there is no document to rebuild.
    before = json.loads(DATA.read_text())
    r.check("there is something on file to re-send",
            before["offices"]["items"] and before["reach"]["items"],
            "this case proves nothing against an empty document")

    status, _, body = client.post(ADMIN, {
        "csrf": token, "s": "contact", "action": "republish"})
    r.check("it redirects rather than re-rendering", status == 302, f"status {status}")
    r.check("so the operator is never shown an emptied form",
            "Not saved" not in body,
            "falling through to the save path rebuilds the document out of a "
            "form that has three fields in it")

    after = json.loads(DATA.read_text())
    r.check("the offices are still on file", after["offices"] == before["offices"],
            "a retry must not be able to empty the record it is retrying")
    r.check("so are the reach rows", after["reach"] == before["reach"])
    r.check("and the page copy", after["hero"] == before["hero"])
    r.check("no revision was minted", after["revision"] == before["revision"],
            "nothing was saved, so nothing new was published")

    r.check("the live site still holds every office",
            [o["name"] for o in offices_sent(site)]
            == [o["name"] for o in before["offices"]["items"]],
            str([o["name"] for o in offices_sent(site)]))

    _, html = client.get(ADMIN)
    r.check("and the editor is not showing an error", "Not saved" not in html)

    # --------------------------------------------------------- empty state
    print("\nwhen everything is emptied")
    _, html = client.get(ADMIN)
    empty = dict(form_fields(html), csrf=token, do="save")
    for key in list(empty):
        if re.match(r"(reach|offices)\[items\]", key):
            del empty[key]
    status, _, _ = client.post(ADMIN, empty)
    r.check("removing every row is allowed", status == 302)

    r.check("an empty document is still published", offices_sent(site) == [], str(offices_sent(site)))
    r.check("with no reach rows either", reach_sent(site) == [], str(reach_sent(site)))
    r.check("and the page's own copy survives, so the live site still has a page",
            published(site)["hero"]["title"] != "", str(published(site)["hero"]))

    # ---------------------------------------------------- a missing data file
    print("\nwhen the data file is unreadable")
    DATA.rename(DATA.with_suffix(".json.moved"))
    try:
        status, html = client.get(ADMIN)
        r.check("the editor still answers", status == 200, f"status {status}")
        r.check("and falls back to the copy it shipped with",
                "Contact Us" in html,
                "contact_load() must never throw — a missing file is an empty page, "
                "not a broken one")
    finally:
        DATA.with_suffix(".json.moved").rename(DATA)


def published(site) -> dict:
    """The contact document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend
    then DOES with the document — the <h1>, the tel: hrefs, the ContactPage
    graph, the office cards — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered page.

    Splitting it this way is not a loss of coverage so much as an honest
    statement of where each half's responsibility ends. What it does cost is
    that neither test alone proves a field survives the whole trip; the model
    they share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("contact", {})


def offices_sent(site) -> list:
    return published(site).get("offices", {}).get("items", [])


def reach_sent(site) -> list:
    return published(site).get("reach", {}).get("items", [])


def region(page: str, css_class: str) -> str:
    """One band of the rendered page.

    Assertions are made against a band rather than the whole document for a
    reason worth stating: the footer repeats an address and a telephone number
    of its own, from content/chrome.json, which this editor does not write and
    is not meant to (ADR 0023). Searching the whole page would find them there
    and conclude the office card had not changed — or that a hidden office was
    still being shown.
    """
    m = re.search(r'<ul class="' + re.escape(css_class) + r'"[^>]*>(.*?)</ul>',
                  page, re.S)
    return m.group(1) if m else ""


def offices(page: str) -> str:
    return region(page, "offices__grid")


def reach(page: str) -> str:
    return region(page, "reach")


def first_office(html: str) -> str:
    m = re.search(r'name="offices\[items\]\[0\]\[name\]"\s+value="([^"]*)"', html)
    return unescape(m.group(1)) if m else ""


def contact_schema(page: str) -> str:
    """The generated ContactPage block, which is the only structured data on
    this page that this editor writes. The Organization graph above it is
    built by seo_graph() from this same document, so it follows too."""
    for body in re.findall(
        r'<script type="application/ld\+json">\s*(\{.*?\})\s*</script>', page, re.S
    ):
        if '"ContactPage"' in body:
            return body
    return ""


def valid_contact_schema(page: str) -> bool:
    import json
    body = contact_schema(page)
    if not body:
        return False
    try:
        doc = json.loads(body)
    except json.JSONDecodeError:
        return False
    return isinstance(doc.get("mainEntity"), dict)


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
