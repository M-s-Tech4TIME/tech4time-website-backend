#!/usr/bin/env python3
"""
Exercise the site-identity editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_settings_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/settings.php holds the four things every page of the public site
depends on and no page owns: the logo, the square mark the favicons are made
from, the colour tokens the site is drawn from, and the address the contact
form sends to. A bug in this save path does not take one page down — it takes
the mark off every page, or the readability of all of them, or the enquiries.

WHAT IT PROVES TODAY
The document and the shell landed before any of the controls, on purpose:
every consumer of the logo, the icons and the colours has to be converted to
read from this document before anything is allowed to write to it, or a save
would change one of the nine places a mark appears and leave the other eight
showing the old one. So what is proved here is the shell and the road:

  the rail and the Overview     a Settings row, and a tile that says what is set
  five screens render           the index and each of the four parts
  an unknown part is the index  ?s=settings&part=nonsense must not be an error
  the index reaches the parts   every part is linked from it
  the notices                   each appears on its OWN condition, and neither
                                blocks anything — an empty dark logo half, and a
                                replaced logo with the shipped tab icon under it
  the road                      settings_edit() writes, publishes, and merges
                                rather than rebuilding

Every test runs against a COPY of the real data file, restored afterwards
whether the run passes or fails.

WHAT IT CANNOT COVER
What the saved document RENDERS as. That is the frontend's — its
tools/test_settings.py reads the mark back off an ordinary page, and its
tools/test_publish.py drives a signed settings document through the endpoint.
"""

import json
import os
import re
import shutil
import struct
import uuid
import zlib
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
DATA = ROOT / "content" / "settings.json"
# The share-card notice is the one thing on these screens that reads another
# document, so this suite has to be able to move it and put it back.
SEO  = ROOT / "content" / "seo.json"
UPLOADS = ROOT / "public" / "uploads"

PARTS = ["logo", "icon", "colour", "mail"]


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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(CookieJar()), NoRedirect())

    def get(self, path):
        with self.opener.open(self.base + path, timeout=20) as r:
            return r.status, r.read().decode("utf-8", "replace")

    def post(self, path, fields, files=None):
        """A real multipart POST, because a file input needs one.

        NOTHING IN EITHER REPOSITORY HAD EVER DONE THIS. Every admin suite
        checks that a screen says enctype="multipart/form-data" and stops
        there, because the harness could not build the body — so the whole
        upload path, from the browser's bytes through upload_accept() to the
        signed asset POST, had never been driven through a form. It matters
        most here: upload_accept() refuses anything is_uploaded_file() does not
        recognise, which is exactly the thing a hand-built POST cannot fake and
        a genuine multipart one does not have to.
        """
        boundary = "----t4t" + uuid.uuid4().hex
        out = []

        for name, value in fields.items():
            out.append(f"--{boundary}\r\n".encode())
            out.append(f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode())
            out.append(str(value).encode() + b"\r\n")

        for name, (filename, blob, kind) in (files or {}).items():
            out.append(f"--{boundary}\r\n".encode())
            out.append(f'Content-Disposition: form-data; name="{name}"; '
                       f'filename="{filename}"\r\n'.encode())
            out.append(f"Content-Type: {kind}\r\n\r\n".encode())
            out.append(blob + b"\r\n")

        out.append(f"--{boundary}--\r\n".encode())
        body = b"".join(out)

        req = urllib.request.Request(self.base + path, data=body, method="POST")
        req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


def png_chunk(kind: bytes, payload: bytes) -> bytes:
    body = kind + payload
    return struct.pack(">I", len(payload)) + body + struct.pack(">I", zlib.crc32(body))


def png(width: int, height: int) -> bytes:
    """A real, decodable PNG of a solid colour — no image library needed."""
    raw = b"".join(b"\x00" + bytes([30, 90, 200]) * width for _ in range(height))
    return (b"\x89PNG\r\n\x1a\n"
            + png_chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + png_chunk(b"IDAT", zlib.compress(raw))
            + png_chunk(b"IEND", b""))


def form_fields(html: str) -> dict:
    """Every named control on the screen, as the browser would submit it.

    Read out of the page rather than written here, which is what makes this a
    test of the SCREEN: a field the form stops rendering disappears from the
    submission too, and whatever depended on it fails.
    """
    fields = {}

    for tag in re.findall(r"<input\b[^>]*>", html):
        name = re.search(r'name="([^"]+)"', tag)
        if not name or 'type="submit"' in tag or 'type="file"' in tag:
            continue
        value = re.search(r'value="([^"]*)"', tag)
        raw = value.group(1) if value else ""
        fields[name.group(1)] = (raw.replace("&lt;", "<").replace("&gt;", ">")
                                   .replace("&quot;", '"').replace("&#039;", "'")
                                   .replace("&amp;", "&"))

    return fields


def stored() -> dict:
    return json.loads(DATA.read_text())


def write(change) -> None:
    """Change the document on disk, the way another screen or a publish would."""
    data = json.loads(DATA.read_text())
    change(data)
    DATA.write_text(json.dumps(data, indent=4))


def write_seo(change) -> None:
    """Change seo.json the way ?s=seo&site=share would."""
    data = json.loads(SEO.read_text())
    change(data)
    SEO.write_text(json.dumps(data, indent=4))


def php(code: str) -> dict:
    out = subprocess.run(["php", "-r", "require 'lib/settings.php';" + code],
                         cwd=str(ROOT), capture_output=True, text=True)
    try:
        return json.loads(out.stdout.strip() or "{}")
    except ValueError:
        return {"fatal": (out.stderr or out.stdout).strip()[:400]}


def icon_master(width: int, height: int, mark=None) -> bytes:
    """Artwork: a transparent canvas with one solid mark somewhere in it.

    `mark` is the mark's own width and height, and DEFAULTS TO NON-SQUARE on
    purpose. A mark that happens to be square after trimming never reaches the
    padding branch of settings_icon_square(), so a fixture built that way
    reports a pass on code it did not run — which is what the first version of
    this did, and why breaking the padding failed nothing.
    """
    mw, mh = mark or (int(width * 0.7), int(height * 0.3))
    return bytes.fromhex(php(
        "$im = imagecreatetruecolor(%d, %d);"
        "imagealphablending($im, false); imagesavealpha($im, true);"
        "imagefilledrectangle($im, 0, 0, %d, %d, imagecolorallocatealpha($im, 0, 0, 0, 127));"
        "imagefilledellipse($im, %d, %d, %d, %d, imagecolorallocate($im, 240, 240, 245));"
        "ob_start(); imagepng($im); $b = ob_get_clean(); imagedestroy($im);"
        "echo json_encode(['hex' => bin2hex($b)]);"
        % (width, height, width - 1, height - 1,
           width // 2, height // 2, mw, mh)
    )["hex"])


def stop(proc):
    for attempt in (proc.terminate, proc.kill):
        try:
            attempt()
            proc.wait(timeout=5)
            return
        except Exception:
            continue


# ------------------------------------------------------------------- tests


def the_shell(client, r) -> None:
    print("the panel knows the screen exists")

    status, overview = client.get("/?s=overview")
    r.check("the Overview renders", status == 200, f"status {status}")
    # admin_url() emits "?s=settings" — relative, no leading slash, because
    # the panel is one script and every screen is a query on it.
    r.check("the rail carries a Settings row",
            'href="?s=settings"' in overview, "no rail link")
    r.check("and there is a tile for it",
            "The site&#039;s identity" in overview or "The site's identity" in overview,
            "no Overview tile")

    status, index = client.get("/?s=settings")
    r.check("the index renders", status == 200, f"status {status}")

    for part in PARTS:
        r.check(f"and links to the {part} screen",
                f'href="?s=settings&amp;part={part}"' in index,
                "not linked from the index")

    for part in PARTS:
        status, page = client.get(f"/?s=settings&part={part}")
        r.check(f"the {part} screen renders", status == 200, f"status {status}")
        # The link in the lede, not the rail's — the rail is on every screen,
        # so looking for the address alone would pass on a screen with no way
        # back in its own body.
        r.check(f"and the {part} screen gets back to the index",
                'href="?s=settings">Back to Settings</a>' in page, "no way back")

    # A part nobody offers must land somewhere, not raise. The same rule
    # admin_section() applies to an unknown section.
    status, page = client.get("/?s=settings&part=nonsense")
    r.check("an unknown part falls back to the index rather than failing",
            status == 200 and "The four parts" in page, f"status {status}")

    the_panels_own_mark(client, r)


def the_panels_own_mark(client, r) -> None:
    """The ninth place the logo appears, and the one nobody would think to check.

    A company that replaces its mark gets a new website and, if this is not
    wired, an admin panel still wearing the old one -- on the rail of every
    screen and on the sign-in page, which is the one place they see it daily.
    It was two pairs of hard-coded paths before this document existed.

    THE UPLOAD IS SHOWN FROM THIS HOST, NOT FETCHED FROM THE PUBLIC SITE. The
    backend holds the canonical copy, so the panel must not be waiting on a
    publish to have succeeded before it can draw what was just chosen -- and in
    local development the public site is a closed port, so a cross-origin
    preview draws nothing at all.
    """
    print("\nthe panel wears the same mark")

    held = stored()
    mark = "/uploads/0f0f0f0f0f0f0f0f.png"
    try:
        write(lambda d: d["logo"]["light"].update(
            {"src": mark, "webp": "", "width": 400, "height": 100,
             "srcset": "", "webp_srcset": ""}))

        _, page = client.get("/?s=overview")
        rail = re.findall(r'<img class="rail__logo".*?>', page, re.S)
        r.check("the rail draws the published mark",
                rail and any(mark in tag for tag in rail),
                str(rail)[:200] or "no rail logo at all")
        r.check("at the size the document gives, not a remembered one",
                any('width="400"' in tag and 'height="100"' in tag for tag in rail),
                str(rail)[:200])
        r.check("and from this host, not across the wire to the public site",
                all("http://" not in tag and "https://" not in tag for tag in rail),
                str(rail)[:200])

        # The sign-in page draws it too, and is reached without a session.
        page = urllib.request.urlopen(client.base + "/login.php",
                                      timeout=20).read().decode("utf-8", "replace")
        signin = re.findall(r'<img class="signin__logo".*?>', page, re.S)
        r.check("the sign-in page draws it as well",
                signin and any(mark in tag for tag in signin),
                str(signin)[:200] or "no sign-in logo at all")
    finally:
        DATA.write_text(json.dumps(held, indent=4))


SHARE_NOTICE = "share card still carries the previous mark"
DARK_NOTICE  = "dark mode shows the light one"
ICON_NOTICE  = "has been replaced and the tab icon has not"
PAIR_NOTICE  = "One half of the logo was replaced and the other was not"


def the_notices(client, r) -> None:
    print("\nthe four standing notices, each on its own condition")

    # As shipped: a dark half IS set, the logo is not an upload, and so the
    # share card cannot be behind one.
    _, index = client.get("/?s=settings")
    r.check("as it ships, no notice is drawn",
            DARK_NOTICE not in index and ICON_NOTICE not in index
            and SHARE_NOTICE not in index and PAIR_NOTICE not in index,
            index[:300])

    the_mismatched_pair(client, r)

    write(lambda d: d["logo"].__setitem__("dark", {
        "src": "", "webp": "", "width": 0, "height": 0,
        "srcset": "", "webp_srcset": ""}))
    _, index = client.get("/?s=settings")
    r.check("an empty dark half says dark mode will show the light mark",
            "dark mode shows the light one" in index, "notice missing")
    r.check("and does not claim the tab icon is stale",
            "has been replaced and the tab icon has not" not in index,
            "the wrong notice appeared")

    # An uploaded logo with no uploaded icon: the tab is still the shipped one.
    r.check("and does not claim the share card is behind either",
            SHARE_NOTICE not in index, "the wrong notice appeared")

    # An uploaded logo with no uploaded icon: the tab is still the shipped one.
    write(lambda d: d["logo"]["light"].__setitem__("src", "/uploads/00112233aabbccdd.png"))
    _, index = client.get("/?s=settings")
    r.check("a replaced logo with the shipped icon under it says so",
            ICON_NOTICE in index, "notice missing")

    # ... and the share card is a second thing the new logo left behind. It is
    # a separate condition on a separate document, so it gets its own notice.
    r.check("the same replaced logo says the share card is behind too",
            SHARE_NOTICE in index, "share notice missing")

    write(lambda d: d["icon"]["master"].__setitem__("src", "/uploads/44556677eeff0011.png"))
    _, index = client.get("/?s=settings")
    r.check("and stops saying it once an icon is uploaded",
            ICON_NOTICE not in index, "the notice outlived its condition")
    r.check("uploading an icon does not answer the share card",
            SHARE_NOTICE in index, "one upload silenced two notices")

    # The card is not generated here; it is replaced at ?s=seo&site=share, and
    # replacing it is what ends this notice.
    r.check("the share notice points at the screen that owns the card",
            "site=share" in index.replace("&amp;", "&"),
            "no link to the share screen")

    seo_backup = SEO.read_bytes()
    try:
        write_seo(lambda d: d["site"]["share"].__setitem__(
            "src", "/uploads/8899aabbccddeeff.png"))
        _, index = client.get("/?s=settings")
        r.check("replacing the card ends the notice",
                SHARE_NOTICE not in index, "the notice outlived its condition")

        # A save is never blocked by any of this. The mail screen is the
        # cheapest one to prove it on: two fields and no upload.
        write_seo(lambda d: d["site"]["share"].__setitem__(
            "src", "/assets/images/og/tech4time-og.png"))
        _, form = client.get("/?s=settings&part=mail")
        r.check("the notice stands on the part screen as well",
                SHARE_NOTICE in form, "notice missing from the part screen")

        fields = form_fields(form)
        fields["contact[mail_to]"] = "someone@tech4time.bd"
        status, _headers, _body = client.post("/?s=settings&part=mail", fields)
        r.check("and a save goes through with it standing",
                status == 302, f"status {status} -- a refusal answers 200")
        r.check("the save actually landed",
                stored()["contact"]["mail_to"] == "someone@tech4time.bd",
                str(stored()["contact"]))
    finally:
        SEO.write_bytes(seo_backup)

    # None of the three is an error: nothing here is refused over any of them.
    _, index = client.get("/?s=settings")
    r.check("no notice is drawn as an error",
            "admin__notice--error" not in index, "a notice was drawn as an error")


def the_mismatched_pair(client, r) -> None:
    """One half replaced, the other not — the quiet way a pair goes wrong.

    An empty dark half renders the light one, so both modes agree. A dark half
    still holding the PREVIOUS mark renders that, so the site shows two
    different logos depending on a setting the operator is not in. Nothing on
    the site says so and nothing on the site could.
    """
    held = stored()
    try:
        # Light replaced, dark still the mark that ships.
        write(lambda d: d["logo"]["light"].__setitem__(
            "src", "/uploads/1234567890abcdef.png"))
        _, index = client.get("/?s=settings")
        r.check("  replacing only the light half is reported",
                PAIR_NOTICE in index, "notice missing")
        r.check("  and not as the empty-dark-half one, which is a different fault",
                DARK_NOTICE not in index, "the wrong notice appeared")

        # THE SAME FAULT THE OTHER WAY ROUND. Rarer order, identical result.
        write(lambda d: (d["logo"]["light"].__setitem__(
                             "src", "/assets/images/logo/logo-light-360.png"),
                         d["logo"]["dark"].__setitem__(
                             "src", "/uploads/fedcba0987654321.png")))
        _, index = client.get("/?s=settings")
        r.check("  and so is replacing only the dark half",
                PAIR_NOTICE in index, "the notice only looks one way")

        # Both replaced: they agree again.
        write(lambda d: d["logo"]["light"].__setitem__(
            "src", "/uploads/1234567890abcdef.png"))
        _, index = client.get("/?s=settings")
        r.check("  replacing both ends it",
                PAIR_NOTICE not in index, "the notice outlived its condition")

        # An empty dark half is the other condition, and must not raise this
        # one as well: two notices about one field is noise.
        write(lambda d: d["logo"]["dark"].__setitem__("src", ""))
        _, index = client.get("/?s=settings")
        r.check("  an empty dark half raises the OTHER notice and not this one",
                DARK_NOTICE in index and PAIR_NOTICE not in index,
                "both notices fired for one fault")

        # And it never blocks a save.
        _, form = client.get("/?s=settings&part=mail")
        fields = form_fields(form)
        fields["contact[mail_to]"] = "pair@tech4time.bd"
        status, _headers, _body = client.post("/?s=settings&part=mail", fields)
        r.check("  and no notice here blocks a save", status == 302,
                f"status {status} -- a refusal answers 200")
    finally:
        DATA.write_text(json.dumps(held, indent=4))


def the_road(r, site) -> None:
    print("\nthe document travels the road")

    # A value in a part this save does NOT touch, and one that is not the
    # shipped one. Without it "merged the rest back" and "rebuilt from the
    # defaults" produce the same document and the last check below cannot
    # fail — which is exactly what it did until this line existed.
    write(lambda d: d["colours"]["light"].__setitem__("accent-text", "#123456"))

    before = stored()

    answer = php("echo json_encode(['ok' => settings_edit(static function (array $d): array {"
                 "  $d['contact']['mail_subject'] = 'MARKED subject';"
                 "  return $d; }), 'note' => publish_note()]);")
    r.check("settings_edit() writes", answer.get("ok") is True, str(answer)[:250])

    after = stored()
    r.check("the change is on disk", after["contact"]["mail_subject"] == "MARKED subject",
            str(after["contact"])[:160])
    r.check("the revision moved", after["revision"] == before["revision"] + 1,
            f'{before["revision"]} -> {after["revision"]}')
    r.check("and it reached the live site",
            site.documents.get("settings", {}).get("contact", {}).get("mail_subject")
            == "MARKED subject", str(sorted(site.documents)))

    # A part screen saves one part. Everything else must survive it, which is
    # what store_edit()'s lock and the merge are for.
    r.check("a value in another part survives the save",
            after["colours"]["light"]["accent-text"] == "#123456",
            str(after["colours"]["light"])[:200])
    r.check("EVERY OTHER PART OF THE DOCUMENT IS UNTOUCHED",
            {k: v for k, v in before.items() if k not in ("contact", "updated", "revision")}
            == {k: v for k, v in after.items() if k not in ("contact", "updated", "revision")},
            "the save rebuilt the document instead of merging into it")


def the_upload(client, r, site) -> None:
    print("\na logo somebody chose, through the form, as a browser sends it")

    status, page = client.get("/?s=settings&part=logo")
    r.check("the logo screen is a form that accepts a file",
            'enctype="multipart/form-data"' in page, "no enctype")
    r.check("with a file input for each half",
            'name="upload[light][0]"' in page and 'name="upload[dark][0]"' in page,
            "a half has no file input")

    fields = form_fields(page)
    r.check("and hidden inputs carrying the ladder it already has",
            "logo[light][srcset]" in fields and "logo[light][webp_srcset]" in fields,
            str(sorted(k for k in fields if k.startswith("logo[light]"))))

    # A value in a part this screen does not hold. Saving the logo must not
    # touch it: the form never carried the colours, so a save that rebuilt the
    # whole document from the form would quietly reset them.
    write(lambda d: d["colours"]["light"].__setitem__("accent-text", "#123456"))

    before = stored()
    status, headers, body = client.post(
        "/?s=settings&part=logo", fields,
        {"upload[light][0]": ("mark.png", png(900, 320), "image/png")})

    r.check("the save redirects rather than re-rendering",
            status == 302, f"status {status}  {body[:200]}")

    after = stored()
    light = after["logo"]["light"]

    r.check("the uploaded mark replaced the one that shipped",
            light["src"].startswith("/uploads/"), str(light)[:200])
    r.check("and it was stored at the widths the slot declares",
            [int(w) for w in re.findall(r"\s(\d+)w", light["srcset"])] == [180, 360, 540],
            light["srcset"])
    r.check("with a WebP ladder to match",
            [int(w) for w in re.findall(r"\s(\d+)w", light["webp_srcset"])] == [180, 360, 540],
            light["webp_srcset"])

    # Six files for one half, and every one of them has to be on both hosts or
    # the <source srcset> names something that is not there.
    names = {Path(p).name for p in
             re.findall(r"(/uploads/[A-Za-z0-9]+\.[a-z]+)", json.dumps(light))}
    r.check("every file it names is on this host",
            all((ROOT / "public" / "uploads" / n).is_file() for n in names),
            str(sorted(names)))
    r.check("and every one of them reached the live site",
            names <= set(site.assets), f"{sorted(names - set(site.assets))} missing")

    r.check("the dark half was left exactly as it was",
            after["logo"]["dark"] == before["logo"]["dark"], str(after["logo"]["dark"])[:160])
    # NINE PLACES DRAW THIS MARK AND ONE OF THEM IS THIS PANEL. It was four
    # committed files with their paths written out twice — once in the rail,
    # once on the sign-in page — so a company that replaced its logo got a new
    # website and an editor still wearing the old one, which is the copy they
    # would be looking at every day.
    _, rail = client.get("/?s=overview")
    r.check("the panel's own rail wears the new mark",
            light["src"] in rail, "the rail did not follow")
    r.check("served from this host, not fetched from the public site",
            f'src="{light["src"]}"' in rail, "the rail went cross-origin for it")

    r.check("and so was every part this screen does not hold",
            after["colours"]["light"]["accent-text"] == "#123456"
            and after["contact"] == before["contact"] and after["icon"] == before["icon"],
            str(after["colours"]["light"])[:160])

    print("\na square mark, through the icon screen")

    status, page = client.get("/?s=settings&part=icon")
    r.check("the icon screen is a form that accepts a file",
            'enctype="multipart/form-data"' in page and 'name="upload[icon][0]"' in page,
            "no file input")

    before = stored()
    fields = form_fields(page)
    status, _headers, body = client.post(
        "/?s=settings&part=icon", fields,
        {"upload[icon][0]": ("mark.png", icon_master(600, 600, mark=(500, 500)), "image/png")})

    import re as _re
    i = body.find("Not saved")
    said = _re.sub(r"<[^>]+>", " ", body[i:i + 700]) if i >= 0 else body[:300]
    said = _re.sub(r"\s+", " ", said).strip()[:400]
    r.check("the save redirects rather than re-rendering",
            status == 302, f"status {status} — {_re.sub(r'<[^>]+>|\s+', ' ', said).strip()}")

    after = stored()
    r.check("the master is stored", after["icon"]["master"]["src"].startswith("/uploads/"),
            str(after["icon"]["master"])[:160])
    r.check("and every icon the shape declares was generated",
            all(v.startswith("/uploads/") for v in after["icon"]["generated"].values()),
            str(after["icon"]["generated"])[:250])

    files = {Path(v).name for v in after["icon"]["generated"].values()}
    files |= {Path(after["icon"]["master"]["src"]).name}
    r.check("every one of them is on this host",
            all((UPLOADS / n).is_file() for n in files),
            str(sorted(n for n in files if not (UPLOADS / n).is_file())))
    # A <link rel="icon"> pointing at a file the live site has not got is a
    # browser tab with no mark in it, so all nine travel before the document
    # names any of them.
    r.check("and every one of them reached the live site",
            files <= set(site.assets), f"{sorted(files - set(site.assets))} missing")

    r.check("the logo was left exactly as it was",
            after["logo"] == before["logo"], "the icon save touched the logo")

    print("\nwhere enquiries go")

    status, page = client.get("/?s=settings&part=mail")
    r.check("the mail screen renders", status == 200, f"status {status}")
    r.check("with an address and a subject line to type in",
            'name="contact[mail_to]"' in page and 'name="contact[mail_subject]"' in page,
            "a field is missing")
    # The one field somebody might look for and must not find. Absent with no
    # explanation reads as an oversight; the explanation is the point.
    r.check("and says what messages are sent AS, and why that is not a field",
            "no-reply@tech4time.bd" in page and "SPF" in page,
            "the From address is not explained")

    fields = form_fields(page)
    fields["contact[mail_to]"] = "enquiries@example.org"
    fields["contact[mail_subject]"] = "A marked subject"
    status, _headers, _body = client.post("/?s=settings&part=mail", fields)

    r.check("a real address is saved", status == 302, f"status {status}")
    r.check("and reached the document",
            stored()["contact"]["mail_to"] == "enquiries@example.org",
            str(stored()["contact"]))
    r.check("and the live site",
            site.documents.get("settings", {}).get("contact", {}).get("mail_to")
            == "enquiries@example.org", "the publish stub was not sent it")

    # AN ADDRESS THAT IS NOT ONE IS REFUSED HERE AND FALLEN BACK ON ELSEWHERE.
    # settings_normalise() meets a document off the wire, where the alternative
    # is a form posting into nowhere. This meets somebody typing, where falling
    # back silently would replace what they wrote and look like a save.
    held = stored()
    for bad, what in (("not an address", "something that is not an address"),
                      ("", "an empty address")):
        fields = form_fields(client.get("/?s=settings&part=mail")[1])
        fields["contact[mail_to]"] = bad
        status, _headers, body = client.post("/?s=settings&part=mail", fields)
        r.check(f"{what} is refused rather than quietly replaced", status == 200,
                f"status {status}")
        r.check(f"  and nothing was written",
                stored()["contact"] == held["contact"], "it saved anyway")

    fields = form_fields(client.get("/?s=settings&part=mail")[1])
    fields["contact[mail_subject]"] = ""
    status, _headers, _body = client.post("/?s=settings&part=mail", fields)
    r.check("an empty subject line is refused too", status == 200, f"status {status}")

    print("\nthe colours, and the one refusal in this editor")

    status, page = client.get("/?s=settings&part=colour")
    r.check("the colour screen renders", status == 200, f"status {status}")
    r.check("with a picker for every token in both modes",
            all(f'name="colours[{mode}][{token}]"' in page
                for mode in ("light", "dark")
                for token in php("echo json_encode(array_keys(SETTINGS_COLOURS['light']));")),
            "a token has no picker")
    r.check("and says what each pair currently measures",
            ":1" in page and "needs" in page, "no contrast readout")

    fields = form_fields(page)
    before = stored()

    # A real change that stays readable: a deeper accent on the same grounds.
    fields["colours[light][accent-text]"] = "#2a4d8f"
    status, _headers, body = client.post("/?s=settings&part=colour", fields)
    r.check("a colour that stays readable is saved", status == 302, f"status {status}")
    r.check("and it reached the document",
            stored()["colours"]["light"]["accent-text"] == "#2a4d8f",
            str(stored()["colours"]["light"])[:160])
    r.check("and the live site", site.documents.get("settings", {})
            .get("colours", {}).get("light", {}).get("accent-text") == "#2a4d8f",
            "the publish stub was not sent the new palette")

    # THE ONE REFUSAL. Everything else in this editor is a standing notice,
    # because everything else is a judgement only the operator can make. A
    # contrast ratio is arithmetic: WCAG says what the bar is, and text nobody
    # can read is not a matter of taste.
    held = stored()
    fields = form_fields(client.get("/?s=settings&part=colour")[1])
    fields["colours[light][text-muted]"] = "#a0a0a4"
    status, _headers, body = client.post("/?s=settings&part=colour", fields)

    r.check("a colour that would be unreadable is REFUSED, not warned about",
            status == 200, f"status {status}")
    r.check("and the sentence names the pair, what it measures and what it needs",
            "text-muted on bg-base" in body and "needs 4.5:1" in body,
            _re.sub(r"<[^>]+>", " ", body[body.find("Not saved"):][:300]))
    r.check("and nothing was written",
            stored()["colours"] == held["colours"], "it saved anyway")

    # A DECORATIVE pair carries no requirement, and asking anything of it would
    # refuse a palette that is perfectly legible.
    fields = form_fields(client.get("/?s=settings&part=colour")[1])
    fields["colours[light][border-subtle]"] = "#f4f4f5"
    status, _headers, _body = client.post("/?s=settings&part=colour", fields)
    r.check("a hairline nobody reads text against is not refused",
            status == 302, f"status {status}")

    print("\nwhat the logo screen refuses")
    # An empty light half is the company's mark missing from every page. An
    # empty DARK half is a legitimate answer and must never be refused.
    fields = form_fields(client.get("/?s=settings&part=logo")[1])
    for key in ("src", "webp", "width", "height", "srcset", "webp_srcset"):
        fields[f"logo[light][{key}]"] = ""

    status, _headers, body = client.post("/?s=settings&part=logo", fields)
    r.check("clearing the light mark is refused", status == 200, f"status {status}")
    r.check("and says why, in words",
            "no light-mode picture" in body, body[:300])
    r.check("and the document is untouched",
            stored()["logo"]["light"] == after["logo"]["light"], "it saved anyway")

    # A picture on somebody else's server. The hidden inputs are text fields
    # with the label taken off, so this is the one thing a POST can still try —
    # and it used to be a typed field on ?s=chrome, where that screen refused
    # it. The refusal travels with the field.
    fields = form_fields(client.get("/?s=settings&part=logo")[1])
    fields["logo[light][src]"] = "https://evil.example/logo.png"
    fields["logo[light][srcset]"] = ("https://evil.example/logo.png 180w, "
                                     "/assets/images/logo/logo-light-360.png 360w")
    status, _headers, body = client.post("/?s=settings&part=logo", fields)
    r.check("a mark on another origin is refused rather than stored",
            status == 200 and "no light-mode picture" in body, f"status {status}")
    r.check("and the rung that WAS a path this site serves is not lost with it",
            "/assets/images/logo/logo-light-360.png 360w" in body, "the good rung went too")

    fields = form_fields(client.get("/?s=settings&part=logo")[1])
    for key in ("src", "webp", "width", "height", "srcset", "webp_srcset"):
        fields[f"logo[dark][{key}]"] = ""

    status, _headers, _body = client.post("/?s=settings&part=logo", fields)
    r.check("clearing the DARK mark is allowed", status == 302, f"status {status}")
    r.check("and it is really gone", stored()["logo"]["dark"]["src"] == "",
            str(stored()["logo"]["dark"])[:160])


def the_icons(r: Results) -> None:
    print("every favicon a browser or a phone asks for, from one square master")

    blob = icon_master(900, 600)
    r.check("the master for this test really is a picture", len(blob) > 100, str(len(blob)))

    got = php("echo json_encode(settings_icon_generate(base64_decode('%s')));"
              % __import__("base64").b64encode(blob).decode())
    r.check("the set is generated", "error" not in got, str(got)[:250])
    if "error" in got:
        return

    wanted = php("echo json_encode(array_keys(settings_defaults()['icon']['generated']));")
    r.check("one file for every name the shape declares",
            sorted(got) == sorted(wanted), f"{sorted(got)} vs {sorted(wanted)}")
    r.check("and no favicon.ico among them, because that is assembled where it is served",
            "ico" not in got, str(sorted(got)))

    on_disk = {n: UPLOADS / Path(path).name for n, path in got.items()}
    r.check("and every one of them is on disk",
            all(f.is_file() for f in on_disk.values()),
            str([n for n, f in on_disk.items() if not f.is_file()]))

    # TWO KINDS, AND THE DIFFERENCE IS NOT COSMETIC. A browser favicon is drawn
    # against the browser's own chrome and must be transparent; an app icon
    # goes on a home screen against a photograph nobody can predict, and a
    # transparent one there is a mark on somebody's wallpaper.
    facts = php("$o = [];"
                "foreach (%s as $n => $f) {"
                "  $im = imagecreatefrompng($f); $c = imagecolorat($im, 0, 0);"
                "  $o[$n] = ['w' => imagesx($im), 'a' => ($c >> 24) & 0x7F,"
                "            'rgb' => sprintf('%%d,%%d,%%d', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255)];"
                "  imagedestroy($im); }"
                "echo json_encode($o);"
                % php_array({n: str(f) for n, f in on_disk.items()}))

    spec = php("echo json_encode(SETTINGS_ICON_SIZES);")

    for name, want in spec.items():
        made = facts.get(name, {})
        r.check(f"{name} is {want['size']} pixels square",
                made.get("w") == want["size"], str(made))
        if want["pad"] == 0:
            r.check(f"  and transparent, because a tab draws its own background",
                    made.get("a") == 127, str(made))
        else:
            r.check(f"  and opaque on the ground, because a home screen does not",
                    made.get("a") == 0 and made.get("rgb") == "11,11,12", str(made))

    print("\nthe .ico, written by hand around PNG payloads")
    # ASSEMBLED, NOT STORED. The asset channel carries what
    # getimagesizefromstring() recognises and an .ico is not among them, so the
    # public site builds the container from the three PNGs it already holds.
    # Here that is the same function, asked directly.
    ico = bytes.fromhex(php(
        "$p = %s; $o = [];"
        "foreach (SETTINGS_ICON_ICO as $s) { $o[$s] = file_get_contents($p['png' . $s]); }"
        "echo json_encode(['hex' => bin2hex(contract_ico_container($o))]);"
        % php_array({n: str(UPLOADS / Path(got[n]).name)
                     for n in ("png16", "png32", "png48")})
    )["hex"])

    res, kind, count = struct.unpack("<HHH", ico[:6])
    r.check("it is an icon directory, not a cursor", res == 0 and kind == 1,
            f"reserved={res} type={kind}")
    r.check("holding the sizes the shape declares",
            count == len(php("echo json_encode(SETTINGS_ICON_ICO);")), f"{count} entries")

    entries = []
    for i in range(count):
        entries.append(struct.unpack("<BBBBHHII", ico[6 + i * 16:22 + i * 16]))

    r.check("each entry names its own size",
            [e[0] for e in entries] == php("echo json_encode(SETTINGS_ICON_ICO);"),
            str([e[0] for e in entries]))

    # THE OFFSET ARITHMETIC IS THE WHOLE OF WHAT A CONTAINER IS. An entry that
    # points at the wrong byte is a file every tool still calls an .ico and no
    # browser can draw, which is exactly the failure this format invites.
    bad = []
    for w, h, _c, _r, _p, _bc, length, offset in entries:
        payload = ico[offset:offset + length]
        if len(payload) != length:
            bad.append(f"{w}x{h}: runs past the end of the file")
        elif payload[:8] != b"\x89PNG\r\n\x1a\n":
            bad.append(f"{w}x{h}: offset {offset} is not the start of a PNG")

    r.check("and every offset and length points at its own payload",
            not bad, "; ".join(bad))
    r.check("with nothing left over at the end",
            entries and entries[-1][6] + entries[-1][7] == len(ico),
            f"{entries[-1][6] + entries[-1][7]} vs {len(ico)} bytes")

    print("\nwhat happens to a mark that is not square")
    # Trimming first is what stops a wide transparent margin becoming a tiny
    # mark in the middle of every tile; padding back to a square is what stops
    # the trim turning a circular dial into an oval.
    # A CIRCULAR mark on a wide canvas, because a circle is the one shape that
    # says whether it was distorted. Asking "is there a transparent band at the
    # top" does not: a source with its own margin keeps a band either way, and
    # a check built that way passes on code that squashed the mark. This
    # measures how far the mark reaches across the tile and how far down it,
    # and a circle reaches equally.
    wide = php("echo json_encode(settings_icon_generate(base64_decode('%s')));"
               % __import__("base64").b64encode(
                   icon_master(1200, 800, mark=(400, 400))).decode())
    r.check("a circular mark on a 3:2 canvas still makes square icons",
            "error" not in wide, str(wide)[:200])

    if "error" not in wide:
        shape = php(
            "$im = imagecreatefrompng('%s');"
            "$across = 0; $down = 0;"
            "for ($x = 0; $x < 96; $x++) { if (((imagecolorat($im, $x, 48) >> 24) & 0x7F) < 60) { $across++; } }"
            "for ($y = 0; $y < 96; $y++) { if (((imagecolorat($im, 48, $y) >> 24) & 0x7F) < 60) { $down++; } }"
            "echo json_encode(['w' => imagesx($im), 'h' => imagesy($im),"
            "  'across' => $across, 'down' => $down]);"
            % (UPLOADS / Path(wide["png96"]).name))

        r.check("  96 pixels by 96", shape.get("w") == 96 and shape.get("h") == 96, str(shape))
        r.check("  and the mark is still round, not squashed to fit the canvas",
                shape.get("across") and abs(shape["across"] - shape["down"]) <= 2,
                f'{shape.get("across")} across, {shape.get("down")} down')

    print("\nwhat the generator refuses")
    r.check("something that is not a picture at all",
            "error" in php("echo json_encode(settings_icon_generate('not a picture'));"))


def php_array(mapping: dict) -> str:
    inner = ", ".join(f"{json.dumps(k)} => {json.dumps(v)}" for k, v in mapping.items())
    return "[" + inner + "]"


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")
    if not DATA.exists():
        raise SystemExit(f"Missing {DATA.relative_to(ROOT)}")

    backup = DATA.read_bytes()
    # The upload group writes real files here, through the real uploader. They
    # are not committed, but leaving six of them behind after every run turns
    # public/uploads/ into a pile nobody can tell apart from real artwork.
    UPLOADS.mkdir(parents=True, exist_ok=True)
    uploads = {f.name: f.read_bytes() for f in UPLOADS.iterdir() if f.is_file()}
    port = free_port()
    work = Path(tempfile.mkdtemp(prefix="t4t-settings-"))
    private = work / "private"

    key = bytes.fromhex("c3" * 32)
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

            the_icons(r)
            the_shell(client, r)
            the_notices(client, r)
            the_upload(client, r, site)

            # settings_edit() publishes through the private store, which the
            # server process was given; this runs it in a process of its own.
            os.environ["T4T_PRIVATE"] = str(private)
            os.environ["T4T_PUBLIC_URL"] = site.url
            os.environ["T4T_PUBLISH_URL"] = ""
            the_road(r, site)
        finally:
            stop(server)
            shutil.rmtree(work, ignore_errors=True)
            DATA.write_bytes(backup)
            for stray in (DATA.with_suffix(".json.bak"), DATA.with_suffix(".json.moved")):
                stray.unlink(missing_ok=True)
            for f in list(UPLOADS.iterdir()):
                if f.is_file() and f.name not in uploads:
                    f.unlink()
            for name, blob in uploads.items():
                (UPLOADS / name).write_bytes(blob)
            print(f"\n{DATA.relative_to(ROOT)} and public/uploads/ restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        raise SystemExit(1)

    print(f"\n{total}/{total} checks passed")


if __name__ == "__main__":
    main()
