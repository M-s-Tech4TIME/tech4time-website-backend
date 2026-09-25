#!/usr/bin/env python3
"""
Exercise the legal hub against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_legal_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
sections/legal.php lists the three legal documents with one switch each, and
that switch writes and publishes. A toggle that wrote without publishing
would leave the live page live while the hub said hidden; a toggle that
published without writing would un-hide itself on the next save. Both
failures look like success, so both are checked: the document on disk and
the document the stub received.

Planned documents are listed, not linked: terms and cookies gain editors in
their own phases, and a hub that linked to a screen that does not exist yet
would land on the overview with no explanation. What the hub must not do is
offer to show or hide a document it cannot write -- those rows carry no
switch, only the phase that brings one.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself, which is tools/test_admin_auth.py's subject. The editors
behind the Edit links, which are tools/test_privacy_admin.py's.
"""
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

ADMIN = "/?s=legal"

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
    """One browser: keeps the session cookie, and does not follow redirects."""

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
        raise SystemExit("No CSRF token in the hub — it did not render.")
    return m.group(1)


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


def run(client, r, site):
    print("the hub lists")

    status, page = client.get(ADMIN)
    r.check("the screen is served", status == 200, f"status {status}")
    for name in ("Privacy Policy", "Terms of Service", "Cookie Policy"):
        r.check(f"it lists {name}", name in page)
    for route in ("/pages/privacy-policy/", "/pages/terms-of-service/",
                  "/pages/cookie-policy/"):
        r.check(f"with the route {route}", route in page)
    pill = re.search(r">\s*(Shown|Hidden)\s*<", page)
    r.check("privacy carries its state", pill is not None,
            "no status pill on the privacy row")
    r.check("privacy links to its editor",
            "?s=privacy" in page, "no Edit link to the privacy screen")
    r.check("terms has no editor yet and says which phase brings one",
            "Phase 3" in page and page.count("?s=legal&amp;doc=") == 0
            and "?s=legal&doc=" not in page,
            "a link to a screen that does not exist")
    r.check("nor does cookies", "Phase 4" in page)
    r.check("the hub holds no words itself, so it holds no Save button",
            'value="save"' not in page,
            "a Save button with nothing to save")
    r.check("hidden beats noindex, in words on the screen",
            "Stronger than" in page)

    print("\nhiding the privacy policy writes and publishes")

    csrf = csrf_of(page)
    status, _headers, body = client.post(
        ADMIN, {"csrf": csrf, "s": "legal", "do": "hide:privacy"})
    r.check("the toggle answers", status == 200, f"status {status}")
    r.check("saying what hidden means",
            "answers 404" in body, "no consequence stated")
    sent = site.documents.get("privacy", {})
    r.check("the live site holds it hidden",
            sent.get("status") == "hidden", str(sent.get("status")))
    r.check("with every word still in it",
            len(sent.get("policy", {}).get("sections", [])) == 12,
            "hiding dropped the policy")
    on_disk = json_loads(DATA)
    r.check("and the file on disk agrees",
            on_disk.get("status") == "hidden", str(on_disk.get("status")))

    _status, page = client.get(ADMIN)
    r.check("the hub now reads Hidden",
            re.search(r">\s*Hidden\s*<", page) is not None)
    r.check("offering Show instead of Hide",
            'value="show:privacy"' in page and 'value="hide:privacy"' not in page)

    print("\nshowing it again restores the page")

    csrf = csrf_of(page)
    status, _headers, _body = client.post(
        ADMIN, {"csrf": csrf, "s": "legal", "do": "show:privacy"})
    r.check("the toggle answers", status == 200, f"status {status}")
    sent = site.documents.get("privacy", {})
    r.check("the live site holds it shown again",
            sent.get("status") == "shown", str(sent.get("status")))

    print("\nwhat the hub refuses")

    csrf = csrf_of(page)
    _status, _headers, body = client.post(
        ADMIN, {"csrf": csrf, "s": "legal", "do": "hide:terms"})
    r.check("a document with no editor cannot be toggled",
            "no editor yet" in body, "it toggled nothing and said nothing")
    sent = site.documents.get("terms", None)
    r.check("and nothing arrived for it", sent is None, str(sent)[:80])

    _status, _headers, body = client.post(
        ADMIN, {"csrf": csrf, "s": "legal", "do": "hide"})
    r.check("a verb with no target does nothing",
            "did nothing" in body)


def json_loads(path):
    import json
    return json.loads(path.read_bytes())


def main():
    backup = DATA.read_bytes()
    port = free_port()

    work = Path(tempfile.mkdtemp(prefix="t4t-legal-"))
    private = work / "private"

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
