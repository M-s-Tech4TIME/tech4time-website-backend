#!/usr/bin/env python3
"""
Exercise the about page editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_about_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/about.php writes content/about.json, and the frontend's
pages/about/index.php renders whatever it finds there. Code that writes files
is worth a test: a bug in the save path does not announce itself, it shows up
as a section that quietly lost its picture.

It is also what tools/check_content_model.py points at. That check reads the
model, the form and the renderer as text and asks whether every field the model
declares is both editable and rendered — which it cannot do here, because both
the form and the page walk ABOUT_LISTS in a loop and the field names are
expressions rather than literals. So this proves it by round trip instead.

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
DATA = ROOT / "content" / "about.json"

ADMIN = "/?s=about"

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
    r.check("it names the file it edits", "content/about.json" in html)
    r.check("the rail lists it", ">About Us<" in region(html, "rail"),
            "the editor is unreachable if the rail does not carry it")
    r.check("and marks the one showing",
            'aria-current="page"' in html
            and "About Us" in html[html.index('aria-current="page"'):][:300])

    print("\nevery band of the page is in the form")
    for band, needle in [
        ("banner", 'name="hero[title]"'),
        ("the sections", 'name="story[items][0][heading]"'),
        ("specialities", 'name="specialties[title]"'),
        ("the slideshow timing", 'name="specialties[interval]"'),
        ("why us", 'name="whyus[title]"'),
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
    for band, count in [("story", 5), ("specialties", 6), ("whyus", 9)]:
        n = html.count(f'name="{band}[items][')
        r.check(f"{band}: {count} rows are in the form",
                html.count(f'name="{band}[items][{count - 1}][id]"') == 1
                and f'name="{band}[items][{count}][id]"' not in html,
                f"{n} inputs")

    print("\nthe controls the shell needs")
    r.check("pressing Enter would save, not add a row",
            html.index('value="save"') < html.index('-add:0'),
            "the first submit button in the document is the one Enter presses")
    r.check("the form is async", 'id="about-form"' in html and "data-async" in html,
            "without data-async every button reloads the whole admin")
    r.check("it accepts a file", 'enctype="multipart/form-data"' in html,
            "without this a file input posts its filename and nothing else")
    r.check("every band the outline names has a fieldset",
            all(f'id="{a}"' in html for a in
                ("band-hero", "band-story", "band-specialties", "band-whyus",
                 "band-cta", "band-uploads", "band-meta")))
    r.check("the row buttons are named for their band",
            'value="story-add:0"' in html and 'value="whyus-add:0"' in html,
            "admin-forms.js finds a new row by matching this prefix to the field names")

    # ------------------------------------------------------------ saving
    print("\nwhat a save sends to the live site")
    good = dict(form_fields(html), csrf=token, do="save")

    saved = dict(good)
    saved["hero[title]"] = "Who We Are"
    saved["story[items][1][heading]"] = "Our Purpose"
    status, _, _ = client.post(ADMIN, saved)
    r.check("saving redirects rather than re-rendering", status == 302, f"status {status}")

    doc = published(site)
    r.check("the new banner reaches the live site", doc["hero"]["title"] == "Who We Are",
            str(doc["hero"]))
    r.check("so does the edited section",
            rows_sent(site, "story")[1]["heading"] == "Our Purpose",
            str(rows_sent(site, "story")[1].get("heading")))
    r.check("its prose survived the round trip",
            rows_sent(site, "story")[1]["body"].startswith("<p>Our goal is to reshape"),
            rows_sent(site, "story")[1]["body"][:60])
    r.check("and its picture with it",
            rows_sent(site, "story")[1]["image"]["src"].endswith("our-goal.jpg"),
            str(rows_sent(site, "story")[1]["image"]))
    r.check("nothing else moved", len(rows_sent(site, "whyus")) == 9)
    r.check("a revision was minted", doc["revision"] >= 1, str(doc.get("revision")))

    print("\nthe fields that are neither text nor a picture")
    r.check("the layout of each section travels",
            [x["layout"] for x in rows_sent(site, "story")]
            == ["logo", "photograph", "photograph", "photograph", "photograph"],
            str([x["layout"] for x in rows_sent(site, "story")]))
    r.check("so does which side the picture is on",
            [x["side"] for x in rows_sent(site, "story")]
            == ["left", "right", "left", "right", "left"],
            str([x["side"] for x in rows_sent(site, "story")]))
    r.check("and the slideshow interval",
            published(site)["specialties"]["interval"] == 10000,
            str(published(site)["specialties"].get("interval")))
    r.check("every speciality kept its icon",
            names_sent(site, "specialties", "icon")
            == ["shield-alt", "code", "cloud", "users", "server", "graduation-cap"],
            str(names_sent(site, "specialties", "icon")))

    # ---------------------------------------------------------- add a row
    print("\nadding a section")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="story-add:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("adding re-renders rather than redirecting", status == 200, f"status {status}")
    r.check("the new row is in the form",
            body.count('name="story[items][') > html.count('name="story[items]['))
    r.check("it arrives hidden, so a blank section never reaches the site",
            'value="hidden" selected' in select_named(body, "story[items][5][status]"),
            "a row with nothing in it must not be shown by pressing Add")
    r.check("and nothing is published until the page is saved",
            len(rows_sent(site, "story")) == 5,
            str(len(rows_sent(site, "story"))))

    print("\nfilling it in and saving")
    fields = dict(form_fields(body), csrf=token, do="save")
    fields["story[items][5][heading]"] = "Our Promise"
    fields["story[items][5][body]"] = "<p>We keep it.</p>"
    fields["story[items][5][alt]"] = "A photograph of the team"
    fields["story[items][5][layout]"] = "logo"
    fields["story[items][5][status]"] = "shown"
    status, _, _ = client.post(ADMIN, fields)
    r.check("it saves", status == 302, f"status {status}")
    r.check("and reaches the live site", len(rows_sent(site, "story")) == 6,
            str(len(rows_sent(site, "story"))))
    r.check("carrying what was typed",
            rows_sent(site, "story")[5]["heading"] == "Our Promise")
    r.check("and an id it was given rather than one it chose",
            rows_sent(site, "story")[5]["id"] == "our-promise",
            str(rows_sent(site, "story")[5].get("id")))

    # ------------------------------------------------------------- hiding
    print("\nhiding, which is not deleting")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["whyus[items][0][status]"] = "hidden"
    client.post(ADMIN, fields)

    hidden = rows_sent(site, "whyus")[0]
    r.check("a hidden reason is published carrying its status",
            hidden["status"] == "hidden", str(hidden))
    r.check("with everything it had",
            hidden["title"] != "" and hidden["text"] != "" and hidden["icon"] != "",
            "hiding must not empty the row")
    r.check("the others are untouched",
            [c["status"] for c in rows_sent(site, "whyus")[1:]] == ["shown"] * 8)

    print("\nhiding a whole band")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["specialties[status]"] = "hidden"
    client.post(ADMIN, fields)
    r.check("the band travels as hidden", band_status(site, "specialties") == "hidden",
            band_status(site, "specialties"))
    r.check("and its rows are all still there",
            len(rows_sent(site, "specialties")) == 6,
            "hiding a band must not empty it")

    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["specialties[status]"] = "shown"
    client.post(ADMIN, fields)
    r.check("and it comes back", band_status(site, "specialties") == "shown")

    # ---------------------------------------------------------- reordering
    print("\nreordering")
    _, html = client.get(ADMIN)
    before = names_sent(site, "story", "heading")[:2]
    fields = dict(form_fields(html), csrf=token, do="story-down:0")
    status, _, body = client.post(ADMIN, fields)
    r.check("moving re-renders rather than redirecting", status == 200)
    r.check("a move is NOT saved until the page is",
            names_sent(site, "story", "heading")[:2] == before,
            "nothing may reach the site until Save is pressed")

    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("saving the move reorders the published document",
            names_sent(site, "story", "heading")[:2] == before[::-1],
            str(names_sent(site, "story", "heading")[:2]))
    r.check("and every section is still there", len(rows_sent(site, "story")) == 6)

    # ------------------------------------------------------------ removing
    print("\nremoving")
    _, html = client.get(ADMIN)
    doomed = names_sent(site, "specialties", "title")[3]
    fields = dict(form_fields(html), csrf=token, do="specialties-remove:3")
    status, _, body = client.post(ADMIN, fields)
    r.check("removing re-renders", status == 200)
    r.check("and the row is gone from the form",
            body.count('name="specialties[items][')
            < html.count('name="specialties[items]['))

    fields = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, fields)
    r.check("saving publishes the removal", len(rows_sent(site, "specialties")) == 5)
    r.check("and it was the right one",
            doomed not in names_sent(site, "specialties", "title"))

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
        fields["story[items][1][image][src]"] = sent
        client.post(ADMIN, fields)
        got = rows_sent(site, "story")[1]["image"]["src"]
        r.check(f"{name} is refused", got != sent, got)

    print("\na photograph section can carry artwork for each colour mode")
    _, html = client.get(ADMIN)
    photo = next(i for i, x in enumerate(rows_sent(site, "story"))
                 if x["layout"] == "photograph")
    can_upload = 'type="file"' in html
    r.check("it offers a slot for each colour mode",
            (f'name="upload[story][{photo}]"' in html
             and f'name="upload[story_dark][{photo}]"' in html) if can_upload
            else (f'name="story[items][{photo}][image][src]"' in html
                  and f'name="story[items][{photo}][image_dark][src]"' in html),
            "the same control the home page's cards have"
            + ("" if can_upload else "  [no GD here: checked the record halves, "
                                     "not the file inputs]"))
    r.check("and says one picture is the normal case",
            "in both colour modes" in html,
            "the illustrations sit on a white plate in both modes by design")

    fields = dict(form_fields(html), csrf=token, do="save")
    fields[f"story[items][{photo}][image_dark][src]"] = "/uploads/4444444444444444.webp"
    fields[f"story[items][{photo}][image_dark][width]"] = "818"
    fields[f"story[items][{photo}][image_dark][height]"] = "810"
    client.post(ADMIN, fields)
    row = rows_sent(site, "story")[photo]
    r.check("both halves reach the live site",
            row["image"]["src"] != ""
            and row["image_dark"]["src"].endswith("4444444444444444.webp"),
            str([row["image"], row["image_dark"]]))

    print("\nand a dark half with no dimensions is refused, like any other")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields[f"story[items][{photo}][image_dark][width]"] = "0"
    fields[f"story[items][{photo}][image_dark][height]"] = "0"
    status, _, body = client.post(ADMIN, fields)
    r.check("it is refused", status == 200 and "no width and height" in body,
            f"status {status}")
    r.check("and the message says which half", "(dark mode)" in body,
            "two pictures on one row need telling apart")

    print("\nclearing the dark half puts the section back to one picture")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields[f"story[items][{photo}][image_dark][src]"] = ""
    fields[f"story[items][{photo}][image_dark][webp]"] = ""
    fields[f"story[items][{photo}][image_dark][width]"] = "0"
    fields[f"story[items][{photo}][image_dark][height]"] = "0"
    client.post(ADMIN, fields)
    row = rows_sent(site, "story")[photo]
    r.check("the light half is kept and the dark one is empty",
            row["image"]["src"] != "" and row["image_dark"]["src"] == "",
            str([row["image"], row["image_dark"]]))

    print("\nwhat it refuses to save")
    for case, field, value, expect in [
        ("an empty banner title", "hero[title]", "", "banner title cannot be empty"),
        ("a section with no heading", "story[items][2][heading]", "", "has no heading"),
        ("a section with no text", "story[items][2][body]", "", "has no text"),
        ("a section with no picture description", "story[items][2][alt]", "",
         "no picture description"),
        ("a speciality with no title", "specialties[items][0][title]", "", "has no title"),
        ("a reason with no text", "whyus[items][2][text]", "", "has no text"),
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

    print("\nthe logo section can be given a logo of its own")
    _, html = client.get(ADMIN)
    logo = next(i for i, x in enumerate(rows_sent(site, "story"))
                if x["layout"] == "logo")
    # A host without GD cannot accept a picture at all, and admin_image_fields()
    # replaces the file input with the sentence saying so. That is the state of
    # most development machines and of none of the servers, so this asks for
    # whichever is right here rather than failing on a difference that is not
    # the editor's.
    can_upload = 'type="file"' in html
    r.check("it offers a slot for each colour mode",
            (f'name="upload[story][{logo}]"' in html
             and f'name="upload[story_dark][{logo}]"' in html) if can_upload
            else (f'name="story[items][{logo}][image][src]"' in html
                  and f'name="story[items][{logo}][image_dark][src]"' in html),
            "a company that changes its mark should not need a deploy"
            + ("" if can_upload else "  [no GD here: checked the record halves, "
                                     "not the file inputs]"))
    r.check("and says the rest of the site's logo is not this control's job",
            "in this section only" in html,
            "the header, footer, tab icon and share card are still markup")

    fields = dict(form_fields(html), csrf=token, do="save")
    fields[f"story[items][{logo}][image][src]"] = "/uploads/1111111111111111.webp"
    fields[f"story[items][{logo}][image][width]"] = "540"
    fields[f"story[items][{logo}][image][height]"] = "192"
    fields[f"story[items][{logo}][image_dark][src]"] = "/uploads/2222222222222222.webp"
    fields[f"story[items][{logo}][image_dark][width]"] = "540"
    fields[f"story[items][{logo}][image_dark][height]"] = "192"
    status, _, _ = client.post(ADMIN, fields)
    r.check("both halves save", status == 302, f"status {status}")
    row = rows_sent(site, "story")[logo]
    r.check("and both reach the live site",
            row["image"]["src"].endswith("1111111111111111.webp")
            and row["image_dark"]["src"].endswith("2222222222222222.webp"),
            str([row["image"], row["image_dark"]]))

    print("\nand is checked like any other picture")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields[f"story[items][{logo}][image_dark][width]"] = "0"
    fields[f"story[items][{logo}][image_dark][height]"] = "0"
    status, _, body = client.post(ADMIN, fields)
    r.check("a logo with no dimensions is refused",
            status == 200 and "no width and height" in body,
            "a logo with no size shifts the page exactly as a photograph does")
    r.check("and the refusal names the half it means",
            "(dark mode)" in body)

    print("\na logo section still needs no picture at all")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    for half in ("image", "image_dark"):
        for k in ("src", "webp", "width", "height"):
            fields[f"story[items][{logo}][{half}][{k}]"] = "" if k in ("src", "webp") else "0"
    status, _, _ = client.post(ADMIN, fields)
    r.check("clearing both is allowed", status == 302, f"status {status}")
    row = rows_sent(site, "story")[logo]
    r.check("and it falls back to the lockup that ships with the site",
            row["image"]["src"] == "" and row["image_dark"]["src"] == "",
            str([row["image"], row["image_dark"]]))

    print("\nvalues the form never offers")
    _, html = client.get(ADMIN)

    # A row that already has a picture. Coercing the LOGO row's layout to
    # 'photograph' would make a picture required, the save would be refused,
    # and this check would be reading the previous publish rather than this
    # one -- a pass or a fail for the wrong reason either way.
    photo = next(i for i, x in enumerate(rows_sent(site, "story"))
                 if x["image"]["src"] != "")

    for case, field, value, fallback in [
        ("an icon this page cannot draw", "specialties[items][0][icon]", "skull", ""),
        ("a layout that does not exist", f"story[items][{photo}][layout]",
         "collage", "photograph"),
        ("a side that is neither", f"story[items][{photo}][side]", "middle", "left"),
    ]:
        fields = dict(form_fields(html), csrf=token, do="save")
        fields[field] = value
        client.post(ADMIN, fields)
        band, i, key = field.split("[")[0], int(field.split("][")[1]), field.rstrip("]").split("[")[-1]
        got = rows_sent(site, band)[i].get(key, "")
        r.check(f"{case} is dropped", got == fallback, f"got {got!r}")

    print("\nthe prose is sanitised, not trusted")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    fields["story[items][1][body]"] = ('<p>Fine.</p><script>alert(1)</script>'
                                       '<p onclick="x()">Also fine.</p>')
    client.post(ADMIN, fields)
    body_sent = rows_sent(site, "story")[1]["body"]
    r.check("a script tag never reaches the record", "<script" not in body_sent, body_sent)
    r.check("nor an event handler", "onclick" not in body_sent, body_sent)
    r.check("and the text around it survives",
            "Fine." in body_sent and "Also fine." in body_sent, body_sent)

    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token)
    status, _, _ = client.post(ADMIN, dict(fields, csrf="wrong", do="save"))
    r.check("a request without the token is refused", status == 400)

    sent = json.dumps(published(site))
    r.check("none of the refused values were published",
            "javascript:alert" not in sent and "evil.example" not in sent
            and "<script" not in sent and "skull" not in sent,
            "a refused save must not reach the live site at all")

    # -------------------------------------------------------- republishing
    print("\nthe retry the failed-publish notice offers")
    before = json.loads(DATA.read_text())
    status, _, body = client.post(ADMIN, {
        "csrf": token, "s": "about", "action": "republish"})
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
        if re.match(r"(story|specialties|whyus)\[items\]", key):
            del empty[key]
            removed += 1
    r.check("there were rows in the form to remove", removed > 40, f"{removed} fields")
    status, _, _ = client.post(ADMIN, empty)
    r.check("removing every row is allowed", status == 302, f"status {status}")
    for band in ("story", "specialties", "whyus"):
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
                "About Us" in html,
                "about_load() must never throw — a missing file is an empty page, "
                "not a broken one")
    finally:
        DATA.with_suffix(".json.moved").rename(DATA)


def published(site) -> dict:
    """The about document as the live site last received it.

    This is where the editor's half of the journey ends. What the frontend
    then DOES with the document — the sections, the <picture> tags, the
    AboutPage graph — is proved in tech4time-website-frontend, by
    test_publish.py, which publishes a document and reads the rendered page.

    Splitting it this way is not a loss of coverage so much as an honest
    statement of where each half's responsibility ends. What it does cost is
    that neither test alone proves a field survives the whole trip; the model
    they share, lib/contract.php, is what makes the two ends meet.
    """
    return site.documents.get("about", {})


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
