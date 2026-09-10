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


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")
    if not DATA.exists():
        raise SystemExit(f"Missing {DATA.relative_to(ROOT)}")

    backup = DATA.read_bytes()
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
            print(f"\n{DATA.relative_to(ROOT)} restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        raise SystemExit(1)

    print(f"\n{total}/{total} checks passed")


if __name__ == "__main__":
    main()
