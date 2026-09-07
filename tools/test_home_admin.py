#!/usr/bin/env python3
"""
Drive the home page editor through a real browser session, and prove that what
it saved reached the live site.

Test. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_home_admin.py

WHY THIS EXISTS
It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because both
the form and the page walk HOME_LISTS in a loop and the field names are
expressions rather than literals. So this proves it by round trip instead.

SIX LISTS, which is more than any other editor here: the hero's badges and
tags, the terminal's lines, the technical domains, the service cards and the
Get to Know Us cards. Add, remove, reorder and hide are exercised across them
rather than on one, because the mechanics are shared and a break in the shared
part would otherwise show up in whichever list happened to be tested.

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
DATA = ROOT / "content" / "home.json"

ADMIN = "/?s=home"

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
    r.check("it names the file it edits", "content/home.json" in html)
    r.check("the rail lists it", ">Home<" in region(html, "rail"),
            "the editor is unreachable if the rail does not carry it")
    r.check("and marks the one showing",
            'aria-current="page"' in html
            and "Home" in html[html.index('aria-current="page"'):][:300])

    print("\nevery band of the page is in the form")
    for band, needle in [
        ("hero", 'name="hero[title]"'),
        ("the highlighted phrase", 'name="hero[accent]"'),
        ("hero badges", 'name="badges[items][0][label]"'),
        ("hero tags", 'name="tags[items][0][label]"'),
        ("the terminal", 'name="terminal[summary]"'),
        ("its lines", 'name="terminal[items][0][text]"'),
        ("technical domains", 'name="capabilities[title]"'),
        ("service cards", 'name="services[items][0][href]"'),
        ("the structured data", 'name="services[schema_name]"'),
        ("get to know us", 'name="destinations[items][0][alt]"'),
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
    for band, count in [("badges", 4), ("tags", 13), ("terminal", 8),
                        ("capabilities", 6), ("services", 6), ("destinations", 3)]:
        n = html.count(f'name="{band}[items][')
        r.check(f"{band}: {count} rows are in the form",
                html.count(f'name="{band}[items][{count - 1}][id]"') == 1
                and f'name="{band}[items][{count}][id]"' not in html,
                f"{n} inputs")

    print("\nthe controls the shell needs")
    r.check("pressing Enter would save, not add a row",
            html.index('value="save"') < html.index('-add:0'),
            "the first submit button in the document is the one Enter presses")
    r.check("the form is async", 'id="home-form"' in html and "data-async" in html,
            "without data-async every button reloads the whole admin")
    r.check("it accepts a file", 'enctype="multipart/form-data"' in html,
            "without this a file input posts its filename and nothing else")
    r.check("every band the outline names has a fieldset",
            all(f'id="{a}"' in html for a in
                ("band-hero", "band-badges", "band-tags", "band-terminal",
                 "band-capabilities", "band-services", "band-destinations",
                 "band-cta", "band-uploads", "band-meta")))
    r.check("the row buttons are named for their band",
            all(f'value="{b}-add:0"' in html for b in
                ("badges", "tags", "terminal", "capabilities", "services",
                 "destinations")),
            "admin-forms.js finds a new row by matching this prefix to the field names")

    # ------------------------------------------------------------ saving
    print("\nwhat a save sends to the live site")
    good = dict(form_fields(html), csrf=token, do="save")

    saved = dict(good)
    saved["hero[title]"] = "Orchestrating Technology with Time"
    saved["services[items][1][title]"] = "Software Engineering"
    status, _, _ = client.post(ADMIN, saved)
    r.check("saving redirects rather than re-rendering", status == 302, f"status {status}")

    doc = published(site)
    r.check("the hero reaches the live site",
            doc["hero"]["title"] == "Orchestrating Technology with Time",
            str(doc["hero"]))
    r.check("so does the edited service card",
            rows_sent(site, "services")[1]["title"] == "Software Engineering",
            str(rows_sent(site, "services")[1].get("title")))
    r.check("its link survived the round trip",
            rows_sent(site, "services")[1]["href"].endswith("/software-development/"),
            rows_sent(site, "services")[1]["href"])
    r.check("nothing else moved", len(rows_sent(site, "tags")) == 13)
    r.check("a revision was minted", doc["revision"] >= 1, str(doc.get("revision")))

    print("\nthe fields that are neither prose nor a picture")
    r.check("the accent phrase travels", doc["hero"]["accent"] == "Technology",
            str(doc["hero"].get("accent")))
    r.check("every badge kept its icon",
            names_sent(site, "badges", "icon")
            == ["shield-alt", "code", "cloud", "users"],
            str(names_sent(site, "badges", "icon")))
    r.check("the terminal keeps each line's kind",
            [x["kind"] for x in rows_sent(site, "terminal")]
            == ["command", "output", "output", "output",
                "command", "output", "output", "output"],
            str([x["kind"] for x in rows_sent(site, "terminal")]))
    r.check("and each line's colour",
            [x["tone"] for x in rows_sent(site, "terminal")]
            == ["plain", "plain", "success", "plain",
                "plain", "alert", "plain", "plain"],
            str([x["tone"] for x in rows_sent(site, "terminal")]))
    r.check("the closing heading keeps its line break",
            "\n" in doc["cta"]["title"], repr(doc["cta"]["title"]))
    r.check("the screen-reader tail on each service link travels",
            names_sent(site, "services", "link_hint")[0] == "for Cybersecurity",
            str(names_sent(site, "services", "link_hint")[:2]))
    r.check("and the wording the structured data uses",
            doc["services"]["schema_name"] == "Tech4TIME technology services",
            str(doc["services"].get("schema_name")))

    # ---------------------------------------------------------- add a row
    print("\nadding a service card")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="services-add:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("adding re-renders rather than redirecting", status == 200, f"status {status}")
    r.check("the new row is in the form",
            body.count('name="services[items][') > html.count('name="services[items]['))
    r.check("it arrives hidden, so a blank card never reaches the site",
            'value="hidden" selected' in select_named(body, "services[items][6][status]"),
            "a row with nothing in it must not be shown by pressing Add")
    r.check("and nothing is published until the page is saved",
            len(rows_sent(site, "services")) == 6,
            str(len(rows_sent(site, "services"))))

    print("\nfilling it in and saving")
    fields = dict(form_fields(body), csrf=token, do="save")
    fields["services[items][6][title]"] = "Managed Detection"
    fields["services[items][6][text]"] = "Round-the-clock monitoring."
    fields["services[items][6][href]"] = "/pages/services/managed-detection/"
    fields["services[items][6][label]"] = "View Services"
    fields["services[items][6][icon]"] = "eye"
    fields["services[items][6][status]"] = "shown"
    status, _, _ = client.post(ADMIN, fields)
    r.check("it saves", status == 302, f"status {status}")
    r.check("and reaches the live site", len(rows_sent(site, "services")) == 7,
            str(len(rows_sent(site, "services"))))
    r.check("carrying what was typed",
            rows_sent(site, "services")[6]["title"] == "Managed Detection")
    r.check("and an id it was given rather than one it chose",
            rows_sent(site, "services")[6]["id"] == "managed-detection",
            str(rows_sent(site, "services")[6].get("id")))

    print("\nadding a terminal line, which is the list with the most shapes")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="terminal-add:0")
    _, _, body = client.post(ADMIN, fields)
    fields = dict(form_fields(body), csrf=token, do="save")
    fields["terminal[items][8][text]"] = "! disk 91% full on log-01"
    fields["terminal[items][8][kind]"] = "output"
    fields["terminal[items][8][tone]"] = "alert"
    fields["terminal[items][8][status]"] = "shown"
    client.post(ADMIN, fields)
    r.check("the new line reaches the site", len(rows_sent(site, "terminal")) == 9)
    r.check("as an alert", rows_sent(site, "terminal")[8]["tone"] == "alert",
            str(rows_sent(site, "terminal")[8]))

    # ------------------------------------------------------------- hiding
    print("\nhiding, which is not deleting")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["tags[items][0][status]"] = "hidden"
    client.post(ADMIN, fields)

    hidden = rows_sent(site, "tags")[0]
    r.check("a hidden tag is published carrying its status",
            hidden["status"] == "hidden", str(hidden))
    r.check("with everything it had",
            hidden["label"] != "" and hidden["icon"] != "",
            "hiding must not empty the row")
    r.check("the others are untouched",
            [c["status"] for c in rows_sent(site, "tags")[1:]] == ["shown"] * 12)

    print("\nhiding a whole band")
    for band in ("terminal", "capabilities", "destinations"):
        _, html = client.get(ADMIN)
        fields = dict(form_fields(html), csrf=token, do="save")
        fields[f"{band}[status]"] = "hidden"
        client.post(ADMIN, fields)
        r.check(f"{band} travels as hidden", band_status(site, band) == "hidden",
                band_status(site, band))
        r.check(f"and {band} keeps its rows", len(rows_sent(site, band)) > 0,
                "hiding a band must not empty it")

        _, html = client.get(ADMIN)
        fields = dict(form_fields(html), csrf=token, do="save")
        fields[f"{band}[status]"] = "shown"
        client.post(ADMIN, fields)
        r.check(f"and {band} comes back", band_status(site, band) == "shown")

    # ---------------------------------------------------------- reordering
    print("\nreordering")
    _, html = client.get(ADMIN)
    before = names_sent(site, "capabilities", "title")[:2]
    fields = dict(form_fields(html), csrf=token, do="capabilities-down:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("moving re-renders rather than redirecting", status == 200)
    r.check("a move is NOT saved until the page is",
            names_sent(site, "capabilities", "title")[:2] == before,
            "nothing may reach the site until Save is pressed")

    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("saving the move reorders the published document",
            names_sent(site, "capabilities", "title")[:2] == before[::-1],
            str(names_sent(site, "capabilities", "title")[:2]))
    r.check("and every domain is still there", len(rows_sent(site, "capabilities")) == 6)

    # ------------------------------------------------------------ removing
    print("\nremoving")
    _, html = client.get(ADMIN)
    doomed = names_sent(site, "tags", "label")[3]
    fields = dict(form_fields(html), csrf=token, do="tags-remove:3")
    status, _, body = client.post(ADMIN, fields)
    r.check("removing re-renders", status == 200)
    r.check("and the row is gone from the form",
            body.count('name="tags[items][') < html.count('name="tags[items]['))

    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("saving publishes the removal", len(rows_sent(site, "tags")) == 12)
    r.check("and it was the right one",
            doomed not in names_sent(site, "tags", "label"))

    # ---------------------------------------------------------- the picture
    print("\nwhat a card may point at for a picture")
    _, html = client.get(ADMIN)
    for name, sent in [
        ("another origin", "https://evil.example/art.png"),
        ("a protocol-relative URL", "//evil.example/art.png"),
        ("a path climbing out of the site", "/assets/images/../../../etc/passwd"),
        ("somewhere that is not artwork", "/lib/contract.php"),
    ]:
        fields = dict(form_fields(html), csrf=token, do="save")
        fields["destinations[items][0][image][src]"] = sent
        client.post(ADMIN, fields)
        got = rows_sent(site, "destinations")[0]["image"]["src"]
        r.check(f"{name} is refused", got != sent, got)

    print("\na card can be given artwork for each colour mode")
    _, html = client.get(ADMIN)
    # A host without GD cannot accept a picture at all, and admin_image_fields()
    # replaces the file input with the sentence saying so. That is the state of
    # most development machines and of none of the servers, so this asks for
    # whichever is right here rather than failing on a difference that is not
    # the editor's.
    can_upload = 'type="file"' in html
    r.check("it offers a slot for each colour mode",
            ('name="upload[destinations][0]"' in html
             and 'name="upload[destinations_dark][0]"' in html) if can_upload
            else ('name="destinations[items][0][image][src]"' in html
                  and 'name="destinations[items][0][image_dark][src]"' in html),
            "the dark half is optional and almost always empty"
            + ("" if can_upload else "  [no GD here: checked the record halves, "
                                     "not the file inputs]"))
    r.check("and says one picture is the normal case",
            "in both colour modes" in html,
            "the artwork is designed to sit on a light plate in both modes")

    fields = dict(form_fields(html), csrf=token, do="save")
    fields["destinations[items][0][image][src]"] = "/uploads/1111111111111111.webp"
    fields["destinations[items][0][image][width]"] = "800"
    fields["destinations[items][0][image][height]"] = "658"
    fields["destinations[items][0][image_dark][src]"] = "/uploads/2222222222222222.webp"
    fields["destinations[items][0][image_dark][width]"] = "800"
    fields["destinations[items][0][image_dark][height]"] = "658"
    status, _, _ = client.post(ADMIN, fields)
    row = rows_sent(site, "destinations")[0]
    r.check("both halves reach the live site",
            row["image"]["src"].endswith("1111111111111111.webp")
            and row["image_dark"]["src"].endswith("2222222222222222.webp"),
            str([row["image"], row["image_dark"]]))

    print("\nand a dark half with no dimensions is refused, like any other picture")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["destinations[items][0][image_dark][width]"] = "0"
    fields["destinations[items][0][image_dark][height]"] = "0"
    status, _, body = client.post(ADMIN, fields)
    r.check("it is refused", status == 200 and "no width and height" in body,
            f"status {status}")
    r.check("and the message says which half", "(dark mode)" in body,
            "two pictures on one card need telling apart")

    print("\nclearing the dark half puts the card back to one picture")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    for key in ("src", "webp"):
        fields[f"destinations[items][0][image_dark][{key}]"] = ""
    for key in ("width", "height"):
        fields[f"destinations[items][0][image_dark][{key}]"] = "0"
    client.post(ADMIN, fields)
    row = rows_sent(site, "destinations")[0]
    r.check("the light half is kept and the dark one is empty",
            row["image"]["src"] != "" and row["image_dark"]["src"] == "",
            str([row["image"], row["image_dark"]]))

    print("\nwhat it refuses to save")
    for case, field, value, expect in [
        ("an empty hero title", "hero[title]", "", "hero title cannot be empty"),
        ("a highlighted phrase that is not in the title", "hero[accent]", "Nonsense",
         "does not appear in the hero title"),
        ("a badge with no wording", "badges[items][0][label]", "", "has no wording"),
        ("an empty terminal line", "terminal[items][0][text]", "", "is empty"),
        ("a domain with no title", "capabilities[items][0][title]", "", "has no title"),
        ("a service with no text", "services[items][0][text]", "", "has no text"),
        ("a card with no picture description", "destinations[items][1][alt]", "",
         "no picture description"),
        ("a javascript: link on a card", "destinations[items][1][href]",
         "javascript:alert(1)", "not one this site will publish"),
        ("a javascript: link on the hero button", "hero[cta_href]",
         "javascript:alert(1)", "not one this site will publish"),
    ]:
        _, html = client.get(ADMIN)
        fields = dict(form_fields(html), csrf=token, do="save")
        fields[field] = value
        status, _, body = client.post(ADMIN, fields)
        r.check(f"{case} is refused",
                status == 200 and expect in body, f"status {status}")

    r.check("and what was typed is still in the form after a refusal",
            'value="javascript:alert(1)"' in body)

    print("\nan icon, a kind and a tone the page cannot draw")
    for case, field, value in [
        ("an icon that is not in the list", "capabilities[items][0][icon]", "skull"),
        ("a terminal line kind that does not exist", "terminal[items][0][kind]", "shout"),
        ("a colour that does not exist", "terminal[items][0][tone]", "puce"),
    ]:
        _, html = client.get(ADMIN)
        fields = dict(form_fields(html), csrf=token, do="save")
        fields[field] = value
        client.post(ADMIN, fields)
        sent = json.dumps(published(site))
        r.check(f"{case} never reaches the live site", value not in sent,
                "the value is coerced back to a safe default rather than stored")

    print("\nthe request itself")
    _, html = client.get(ADMIN)
    # A WRONG token, not a missing one. form_fields() scrapes the hidden csrf
    # input along with everything else, so leaving the keyword off still sends
    # the real token and the check passes without testing anything.
    fields = dict(form_fields(html), csrf="wrong", do="save")
    status, _, _ = client.post(ADMIN, fields)
    r.check("a request carrying the wrong token is refused", status == 400,
            f"status {status}")

    sent = json.dumps(published(site))
    r.check("none of the refused values were published",
            "javascript:alert" not in sent and "evil.example" not in sent
            and "<script" not in sent and "skull" not in sent,
            "a refused save must not reach the live site at all")

    # -------------------------------------------------------- republishing
    print("\nthe retry the failed-publish notice offers")
    before = json.loads(DATA.read_text())
    status, _, body = client.post(ADMIN, {
        "csrf": token, "s": "home", "action": "republish"})
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
        if re.match(r"(badges|tags|terminal|capabilities|services|destinations)"
                    r"\[items\]", key):
            del empty[key]
            removed += 1
    r.check("there were rows in the form to remove", removed > 80, f"{removed} fields")
    status, _, _ = client.post(ADMIN, empty)
    r.check("removing every row is allowed", status == 302, f"status {status}")
    for band in ("badges", "tags", "terminal", "capabilities", "services",
                 "destinations"):
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
                "Orchestrating Technology with Time" in html,
                "home_load() must never throw — a missing file is an empty page, "
                "not a broken one")
    finally:
        DATA.with_suffix(".json.moved").rename(DATA)


def published(site) -> dict:
    """The home document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend
    then DOES with the document — the hero accent, the <picture> tags, the
    terminal lines and the Service ItemList — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered page.

    Splitting it this way is not a loss of coverage so much as an honest
    statement of where each half's responsibility ends. What it does cost is
    that neither test alone proves a field survives the whole trip; the model
    they share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("home", {})


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
    work = Path(tempfile.mkdtemp(prefix="t4t-about-"))
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
