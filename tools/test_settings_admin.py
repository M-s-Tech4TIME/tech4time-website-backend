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


def php(code: str) -> dict:
    out = subprocess.run(["php", "-r", "require 'lib/settings.php';" + code],
                         cwd=str(ROOT), capture_output=True, text=True)
    try:
        return json.loads(out.stdout.strip() or "{}")
    except ValueError:
        return {"fatal": (out.stderr or out.stdout).strip()[:400]}


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


def the_notices(client, r) -> None:
    print("\nthe two standing notices, each on its own condition")

    # As shipped: a dark half IS set, and the logo is not an upload.
    _, index = client.get("/?s=settings")
    r.check("as it ships, neither notice is drawn",
            "dark mode shows the light one" not in index
            and "has been replaced and the tab icon has not" not in index,
            index[:300])

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
    write(lambda d: d["logo"]["light"].__setitem__("src", "/uploads/00112233aabbccdd.png"))
    _, index = client.get("/?s=settings")
    r.check("a replaced logo with the shipped icon under it says so",
            "has been replaced and the tab icon has not" in index, "notice missing")

    write(lambda d: d["icon"]["master"].__setitem__("src", "/uploads/44556677eeff0011.png"))
    _, index = client.get("/?s=settings")
    r.check("and stops saying it once an icon is uploaded",
            "has been replaced and the tab icon has not" not in index,
            "the notice outlived its condition")

    # Neither is an error: nothing on this screen is refused over either.
    _, index = client.get("/?s=settings")
    r.check("no notice is drawn as an error",
            "admin__notice--error" not in index, "a notice was drawn as an error")


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
    r.check("and so was every part this screen does not hold",
            after["colours"]["light"]["accent-text"] == "#123456"
            and after["contact"] == before["contact"] and after["icon"] == before["icon"],
            str(after["colours"]["light"])[:160])

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

    fields = form_fields(client.get("/?s=settings&part=logo")[1])
    for key in ("src", "webp", "width", "height", "srcset", "webp_srcset"):
        fields[f"logo[dark][{key}]"] = ""

    status, _headers, _body = client.post("/?s=settings&part=logo", fields)
    r.check("clearing the DARK mark is allowed", status == 302, f"status {status}")
    r.check("and it is really gone", stored()["logo"]["dark"]["src"] == "",
            str(stored()["logo"]["dark"])[:160])


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
