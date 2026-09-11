#!/usr/bin/env python3
"""
Both halves, two ports, one key: the whole chain, with nothing stubbed.

Development tool. NOT deployed (see tools/README.md).
Run from the repo root:  python3 tools/test_end_to_end.py

    python3 tools/test_end_to_end.py            # against the frontend beside this repo
    python3 tools/test_end_to_end.py --clone    # fetch it instead (what CI does)

WHY THIS EXISTS, WHEN BOTH HALVES ARE ALREADY TESTED

Each half is tested against a stand-in for the other, and deliberately so.
tools/test_settings_admin.py drives the real admin and catches the signed POST
in tools/publish_stub.py; the frontend's tools/test_publish.py signs an envelope
in Python and posts it to the real api/publish.php. Two independent
implementations of one format, which is worth more than one shared library
would be: when they agree, the agreement is evidence.

What neither can show is FILES MOVING. A logo is not a document. Uploading one
stores a ladder of renditions, sends each as its own signed POST, and only then
writes the document that names them -- and the plan for that work named the
risk in one line: a partial publish is a broken image, because a <source
srcset> pointing at a rung that never arrived breaks the picture for everyone
whose browser prefers WebP, which is nearly everyone. A stub receiver cannot
fail that way, and a hand-made document never had rungs to lose.

So this drives the real admin against the real public site: upload a mark with
JavaScript off, then ask the OTHER PORT whether the header, the footer, the
About row and both schema graphs moved together; upload a square icon and fetch
/favicon.ico, which is assembled on the public host out of PNGs generated on
the admin host; change a colour and fetch the generated stylesheet; and try a
colour nobody could read, which must never reach the wire at all.

IT IS NOT A REPLACEMENT FOR EITHER SUITE, AND IT IS THE SLOWEST THING HERE.
Two PHP servers, a real session, real image processing. Everything it can prove
cheaply is proved cheaply somewhere else. What is left is the chain.

WHAT IT DOES TO THE OTHER REPOSITORY, WHICH IS THE REASON FOR THE GUARD BELOW

It publishes into the frontend's content/ and writes into its uploads/, which
is what those directories are for -- content/ is a replica written by
api/publish.php and by nothing else, and uploads/ is ignored by git in its
entirety. Both are put back afterwards.

But "afterwards" assumes the run finishes. So it REFUSES TO START if the
frontend's content/ has uncommitted changes: that way a run killed halfway
leaves a dirty content/, the next run says so and stops, and recovery is the
git checkout it names. Without the guard, a half-finished run would be
indistinguishable from work somebody had not committed yet, and the restore
would be the thing that destroyed it.

--clone sidesteps all of that by fetching a throwaway copy, on the SAME BRANCH
as this one. That is what CI does, and it is why this can fail honestly during
the window when one half is merged and the other is not.
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
import urllib.request
import uuid
from http.cookiejar import CookieJar
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))

import admin_session                                    # noqa: E402
from check_shared_repos import sibling                  # noqa: E402

# A colour that passes AA against every surface, and one that does not. The
# second is #f0f0f0 text on the #fafafa page, which is about 1.1:1.
GOOD_COLOUR = "#3d5a80"
BAD_COLOUR = "#f0f0f0"


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
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


class Client:
    """The admin, as a browser with JavaScript off would see it."""

    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(CookieJar()), NoRedirect())

    def get(self, path):
        with self.opener.open(self.base + path, timeout=30) as r:
            return r.status, r.read().decode("utf-8", "replace")

    def post(self, path, fields, files=None):
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

        req = urllib.request.Request(self.base + path, data=b"".join(out), method="POST")
        req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
        try:
            with self.opener.open(req, timeout=60) as r:
                return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")


def fetch(url: str) -> tuple[int, bytes]:
    """The public site, as anybody would see it: no session, no cookie."""
    try:
        with urllib.request.urlopen(url, timeout=30) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def artwork(width: int, height: int, rgb=(110, 112, 117)) -> bytes:
    """A real PNG of the size asked for, drawn by the same GD the uploader uses."""
    out = subprocess.run(["php", "-r", f"""
        $im = imagecreatetruecolor({width}, {height});
        $c = imagecolorallocate($im, {rgb[0]}, {rgb[1]}, {rgb[2]});
        imagefilledrectangle($im, 0, 0, {width} - 1, {height} - 1, $c);
        ob_start(); imagepng($im); echo base64_encode(ob_get_clean());
    """], capture_output=True, text=True)
    if out.returncode != 0:
        raise SystemExit("could not draw the test artwork:\n" + out.stderr[:400])
    import base64
    return base64.b64decode(out.stdout.strip())


def form_fields(html: str) -> dict:
    """Every field a browser would post back, so a save changes one thing."""
    got = {}
    for m in re.finditer(r'<input\b[^>]*>', html, re.S):
        tag = m.group(0)
        if 'type="file"' in tag:
            continue
        name = re.search(r'name="([^"]+)"', tag)
        kind = re.search(r'type="([^"]+)"', tag)
        value = re.search(r'value="([^"]*)"', tag)
        if not name:
            continue
        if kind and kind.group(1) in ("checkbox", "radio") and "checked" not in tag:
            continue
        got[name.group(1)] = unescape(value.group(1) if value else "")
    for m in re.finditer(r'<select\b[^>]*name="([^"]+)"[^>]*>(.*?)</select>', html, re.S):
        chosen = (re.search(r'<option[^>]*selected[^>]*value="([^"]*)"', m.group(2))
                  or re.search(r'<option[^>]*value="([^"]*)"', m.group(2)))
        got[m.group(1)] = chosen.group(1) if chosen else ""
    for m in re.finditer(r'<textarea\b[^>]*name="([^"]+)"[^>]*>(.*?)</textarea>', html, re.S):
        got[m.group(1)] = unescape(m.group(2))
    return got


def unescape(s: str) -> str:
    return (s.replace("&amp;", "&").replace("&lt;", "<").replace("&gt;", ">")
             .replace("&quot;", '"').replace("&#039;", "'"))


def paths_named(image: dict) -> set:
    """Every web path one picture record points at, deduplicated.

    contract_image_paths() in PHP answers the same question, and this is
    deliberately a second implementation of it rather than a call into the
    first: what is being checked is that the files a record NAMES are the files
    that ARRIVED, and asking the sender's own code which files those are would
    be asking the accused to hold the evidence.
    """
    out = set()
    for field in ("src", "webp"):
        value = str(image.get(field, "")).strip()
        if value:
            out.add(value)
    for field in ("srcset", "webp_srcset"):
        for entry in str(image.get(field, "")).split(","):
            entry = entry.strip()
            if entry:
                out.add(entry.split(" ")[0])
    return out


def marks(page: str, css_class: str) -> list:
    """Every <img> drawn with that class, one string each.

    BY CLASS, ONE LOCKUP AT A TIME. Counting a filename across the whole page
    proves nothing: the header names its mark twice, in a <source srcset> and
    an <img src>, so a footer that had stopped reading the document would leave
    any count satisfied -- and every page carries the header and footer, so
    "the About page mentions it" is true while the About row itself is stale.
    """
    return re.findall(r'<img\s+class="' + re.escape(css_class) + r'".*?>', page, re.S)


# ------------------------------------------------------------------ the walks

def the_logo(c: Client, site: str, fe: Path, r: Results) -> None:
    print("\na logo is uploaded on the admin, with JavaScript off")

    _status, page = c.get("/?s=settings&part=logo")
    status, _body = c.post("/?s=settings&part=logo", form_fields(page),
                           {"upload[light][0]": ("mark.png", artwork(900, 320),
                                                 "image/png")})
    r.check("the admin accepts the upload", status in (200, 302), f"status {status}")

    doc = json.loads((fe / "content" / "settings.json").read_text())
    light = doc["logo"]["light"]
    r.check("and the PUBLIC site's replica names the new mark",
            light["src"].startswith("/uploads/"), str(light)[:200])
    r.check("with a ladder rather than one file",
            len(light["srcset"].split(",")) >= 2, light["srcset"])

    # THE ONE THE PLAN NAMED, AND IT IS EVERY PATH THE RECORD HOLDS, NOT THE
    # LADDER. This read light["srcset"] alone at first, and a deliberately
    # broken sender that dropped the last file did not fail it -- because the
    # last file is a WebP rung, which lives in webp_srcset. That is the half
    # that matters: a <source srcset> is what a browser preferring WebP takes,
    # which is nearly every browser, so a missing WebP rung breaks the picture
    # for almost everyone while the PNG ladder it fell back from looks perfect.
    named = paths_named(light)
    r.check("the record names both ladders and both single files",
            len(named) >= 6, str(sorted(named)))
    missing = sorted(p for p in named if not (fe / p.lstrip("/")).is_file())
    r.check("and EVERY FILE IT NAMES ARRIVED", not missing, str(missing))

    _s, home = fetch(site + "/")
    _s, about = fetch(site + "/pages/about/")
    _s, careers = fetch(site + "/pages/careers/")
    home, about, careers = (b.decode("utf-8", "replace") for b in (home, about, careers))

    # One half was uploaded, so one half moved. The other still holds the mark
    # that ships, which is correct -- and is the thing the admin has to say out
    # loud, because the site now shows two different logos depending on a
    # setting the person who uploaded it is not in.
    for what, page, css in (("header", home, "site-header__logo"),
                            ("footer", home, "site-footer__logo"),
                            ("About row", about,
                             "about-split__image about-split__image--contain")):
        got = marks(page, css)
        r.check(f"the {what}'s light half is the new mark",
                len(got) == 2 and "/uploads/" in got[0], str(got)[:300])
        r.check(f"  and its dark half is untouched, which is the fault to report",
                len(got) == 2 and "/uploads/" not in got[1], str(got)[:300])

    org = re.search(r'"logo":\s*\{[^}]*"url":\s*"([^"]+)"', home)
    r.check("Organization.logo names it", org and "/uploads/" in org.group(1),
            org.group(1) if org else "no Organization.logo at all")
    hiring = re.findall(r'"hiringOrganization"\s*:\s*\{.*?\}', careers, re.S)
    r.check("and the job post names the same file, not a second one",
            hiring and all("/uploads/" in node for node in hiring), str(hiring)[:200])

    _s, index = c.get("/?s=settings")
    r.check("and the ADMIN says the pair now disagrees",
            "One half of the logo was replaced and the other was not" in index,
            "the operator was told nothing")

    print("\nthe other half is uploaded, and the pair agrees again")
    _s, page = c.get("/?s=settings&part=logo")
    status, _body = c.post("/?s=settings&part=logo", form_fields(page),
                           {"upload[dark][0]": ("dark.png",
                                                artwork(900, 320, (240, 240, 244)),
                                                "image/png")})
    r.check("the admin accepts the dark half", status in (200, 302), f"status {status}")

    _s, home = fetch(site + "/")
    got = marks(home.decode("utf-8", "replace"), "site-header__logo")
    r.check("both halves of the header are the uploaded pair now",
            len(got) == 2 and all("/uploads/" in tag for tag in got), str(got)[:300])
    _s, index = c.get("/?s=settings")
    r.check("and the admin stops saying they disagree",
            "One half of the logo was replaced and the other was not" not in index,
            "the notice outlived its condition")


def the_icons(c: Client, site: str, fe: Path, r: Results) -> None:
    print("\nan icon is uploaded, and /favicon.ico answers with it")

    shipped = (fe / "assets" / "images" / "favicon" / "favicon.ico").read_bytes()

    _s, page = c.get("/?s=settings&part=icon")
    status, _body = c.post("/?s=settings&part=icon", form_fields(page),
                           {"upload[icon][0]": ("square.png",
                                                artwork(512, 512, (200, 30, 30)),
                                                "image/png")})
    r.check("the admin accepts the square master", status in (200, 302), f"status {status}")

    # THE ONE FILE THAT COULD NOT TRAVEL THE ASSET CHANNEL. An .ico is not
    # something getimagesizefromstring() recognises, and widening that would
    # widen what an editor may upload as page artwork. So the PNGs cross the
    # wire and the public site assembles the container itself.
    status, ico = fetch(site + "/favicon.ico")
    r.check("/favicon.ico answers at the root", status == 200, f"status {status}")
    r.check("it is a real ICO container", ico[:4] == b"\x00\x00\x01\x00", ico[:8].hex())
    r.check("and it is no longer the one that ships", ico != shipped,
            "the generated set never reached the public site")

    generated = json.loads((fe / "content" / "settings.json").read_text())["icon"]["generated"]
    _s, home = fetch(site + "/")
    r.check("the browser tab's icons are the generated ones",
            generated.get("png32") and generated["png32"] in home.decode("utf-8", "replace"),
            str(generated)[:200])
    _s, manifest = fetch(site + "/site.webmanifest")
    r.check("and so are the manifest's",
            generated.get("png512")
            and generated["png512"] in manifest.decode("utf-8", "replace"),
            manifest.decode("utf-8", "replace")[:200])


def the_colours(c: Client, site: str, r: Results) -> None:
    print("\na colour is changed, and the generated stylesheet carries it")

    status, before = fetch(site + "/assets/css/brand.css")
    r.check("brand.css is served at all", status == 200, f"status {status}")
    r.check("and with the shipped palette it is EMPTY", before.strip() == b"",
            before[:160].decode("utf-8", "replace"))

    _s, page = c.get("/?s=settings&part=colour")
    fields = form_fields(page)
    fields["colours[light][accent-text]"] = GOOD_COLOUR
    status, _body = c.post("/?s=settings&part=colour", fields)
    r.check("a colour that passes AA is accepted", status in (200, 302), f"status {status}")

    _s, css = fetch(site + "/assets/css/brand.css")
    r.check("the public site's stylesheet carries it",
            GOOD_COLOUR.encode() in css, css[:200].decode("utf-8", "replace"))
    r.check("and holds ONLY what was changed",
            css.count(b"#") <= 2, css.decode("utf-8", "replace")[:300])

    # It cannot be an inline style: the CSP is style-src 'self'. A stylesheet
    # behind a year-long cache needs the document's revision on it or a colour
    # change reaches new visitors only.
    _s, home = fetch(site + "/")
    r.check("and the page asks for it with the document's revision on it",
            b"/assets/css/brand.css?v=" in home, "the stylesheet is not versioned")

    print("\na colour nobody could read is refused, before it reaches the wire")
    _s, page = c.get("/?s=settings&part=colour")
    fields = form_fields(page)
    fields["colours[light][text-primary]"] = BAD_COLOUR
    status, body = c.post("/?s=settings&part=colour", fields)
    r.check("the admin refuses it",
            status == 200 and "admin__notice--error" in body, f"status {status}")
    _s, css = fetch(site + "/assets/css/brand.css")
    r.check("and the public site never saw it", BAD_COLOUR.encode() not in css,
            css.decode("utf-8", "replace")[:200])


# ------------------------------------------------------------ what it may touch

def dirty_content(repo: Path) -> list[str]:
    """Tracked files under content/ that differ from what is committed."""
    out = subprocess.run(["git", "status", "--porcelain", "--", "content/"],
                         cwd=str(repo), capture_output=True, text=True)
    if out.returncode != 0:
        return []
    return [line[3:] for line in out.stdout.splitlines() if line.strip()]


def refuse_if_dirty(fe: Path) -> None:
    """Stop rather than publish over work somebody has not committed.

    THE RESTORE IS THE DANGEROUS PART, NOT THE PUBLISH. This puts the frontend's
    content/ back from bytes taken before the run -- which is right when those
    bytes were the committed seed and wrong when they were somebody's
    uncommitted edit, because then a run killed halfway has already been
    restored over once and the second run would restore the wrong thing.

    Refusing here makes a killed run self-reporting: content/ is left dirty, the
    next run says so, and the fix is the one command named below.
    """
    dirty = dirty_content(fe)
    if not dirty:
        return

    print("The frontend's content/ has uncommitted changes:\n")
    for path in dirty[:10]:
        print(f"  {path}")
    print(f"""
This publishes into that directory and puts it back afterwards, so it will not
run while there is something there it did not write.

If that is work in progress, commit or stash it. If it is what a run of this
killed halfway left behind, it is safe to throw away -- content/ is a replica,
written by api/publish.php and by nothing else:

    git -C {fe} checkout -- content/

Or use --clone, which fetches a copy of its own and never touches this one.""")
    raise SystemExit(1)


def refuse_if_too_old(fe: Path) -> None:
    """Stop with an explanation when the frontend does not know this document yet.

    THIS IS THE HALF-MERGED WINDOW, AND IT MUST NOT LOOK LIKE A BUG. Work that
    touches both halves is red here until both are pushed to the branch, which
    is the same window check_shared_repos.py --clone reports and is the reason
    the deploy order is backend, frontend, re-run backend.

    Without this the run got as far as uploading a logo and then died on a
    FileNotFoundError for content/settings.json -- a stack trace, which reads
    as "the tool is broken" rather than "the other half is not merged yet". A
    check that cannot tell those apart costs somebody an afternoon.
    """
    contract = fe / "lib" / "contract.php"
    if not contract.is_file():
        print(f"\nThe frontend at {fe} has no lib/contract.php. That is not a half-merged")
        print("branch; it is not the frontend.")
        raise SystemExit(1)

    out = subprocess.run(
        ["php", "-r", f"require '{contract}';"
                      "echo json_encode(CONTRACT_DOCUMENTS);"],
        capture_output=True, text=True)
    try:
        known = json.loads(out.stdout.strip())
    except ValueError:
        known = []

    wanted = {"settings": "the site's identity",
              "chrome": "the header, footer and dock"}
    absent = {name: what for name, what in wanted.items() if name not in known}
    if not absent:
        return

    print("\nThe frontend on this branch does not know these documents yet:\n")
    for name, what in sorted(absent.items()):
        print(f"  {name:10} {what}")
    print("""
That is the window where one half is merged and the other is not, and this is
red on purpose -- the same window check_shared_repos.py --clone reports, for the
same reason. It is not a fault in either half.

Merge the frontend, then re-run this. The order and why it is that way are in
docs/20-deployment/ci-cd.md.""")
    raise SystemExit(1)


def main() -> None:
    use_clone = "--clone" in sys.argv[1:]

    missing = [n for n in ("php", "git") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        return

    found = sibling("frontend", clone=use_clone)
    if found is None:
        print("Skipping: the frontend is not beside this repository.")
        print("Put it at ../tech4time-website-frontend, or pass --clone to fetch it.")
        return
    fe, where = found

    print(f"backend   {ROOT}")
    print(f"frontend  {where}")

    # ON WHAT IT GOT, NOT ON WHAT WAS ASKED FOR. sibling() prefers the
    # repository beside this one and only clones when there is none -- so
    # --clone on a machine that has both is still the real checkout, and
    # keying the guard off the flag would have been a way to skip it.
    if fe.resolve() == (ROOT.parent / "tech4time-website-frontend").resolve():
        refuse_if_dirty(fe)

    refuse_if_too_old(fe)

    be_port, fe_port = free_port(), free_port()
    be_url, fe_url = f"http://127.0.0.1:{be_port}", f"http://127.0.0.1:{fe_port}"

    work = Path(tempfile.mkdtemp(prefix="t4t-e2e-"))
    be_private, fe_private = work / "admin", work / "site"
    for store in (be_private, fe_private):
        store.mkdir(mode=0o700, parents=True)

    # ONE KEY IN TWO STORES. That is the entire trust relationship between the
    # halves: the backend signs with it, the frontend verifies with it, and
    # neither has any other way to know the other exists.
    key = "5e" * 32
    for store in (be_private, fe_private):
        (store / "publish.key").write_text(key + "\n")

    documents = sorted(list((fe / "content").glob("*.json"))
                       + list((ROOT / "content").glob("*.json")))
    held = {path: path.read_bytes() for path in documents}

    uploads = [fe / "uploads", ROOT / "public" / "uploads"]
    for where_ in uploads:
        where_.mkdir(parents=True, exist_ok=True)
    before = {w: {f.name for f in w.iterdir() if f.is_file()} for w in uploads}

    site = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{fe_port}", "-t", str(fe),
         str(fe / "tools" / "dev-router.php")], cwd=str(fe),
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(fe_private)))
    admin = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{be_port}", "-t", str(ROOT / "public"),
         str(ROOT / "tools" / "dev-router.php")], cwd=str(ROOT),
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(be_private), T4T_PUBLIC_URL=fe_url,
                 T4T_PUBLISH_URL=fe_url + "/api/publish.php"))

    r = Results()
    try:
        if not (wait_for(fe_url + "/") and wait_for(be_url + "/login.php")):
            raise SystemExit("one of the two servers never came up")

        print(f"\nadmin on {be_port}, site on {fe_port}, one publish.key in two stores")
        secret = admin_session.make_account(be_private)
        client = Client(be_url)
        admin_session.sign_in(client.opener, be_url, secret)

        the_logo(client, fe_url, fe, r)
        the_icons(client, fe_url, fe, r)
        the_colours(client, fe_url, r)
    finally:
        for proc in (admin, site):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=10)
            except Exception:
                pass
        for path, blob in held.items():
            path.write_bytes(blob)
        for repo in (fe, ROOT):
            for stray in (repo / "content").glob("*.json.bak"):
                stray.unlink(missing_ok=True)
        for where_, names in before.items():
            for stray in where_.iterdir():
                if stray.is_file() and stray.name not in names:
                    stray.unlink()
        shutil.rmtree(work, ignore_errors=True)
        print("\nboth content/ replicas and both uploads/ restored")

    total = r.passed + len(r.failed)
    print(f"\n{r.passed}/{total} checks passed")
    if r.failed:
        print("\nfailed:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)


def wait_for(url: str, tries: int = 120) -> bool:
    for _ in range(tries):
        try:
            urllib.request.urlopen(url, timeout=1)
            return True
        except urllib.error.HTTPError:
            return True
        except OSError:
            time.sleep(0.1)
    return False


if __name__ == "__main__":
    main()
