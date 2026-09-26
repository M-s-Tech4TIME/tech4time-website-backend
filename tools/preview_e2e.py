#!/usr/bin/env python3
"""
Watch a publish land, locally, with no chance of touching production.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:

    python3 tools/preview_e2e.py              # backend :8125, frontend :8126
    python3 tools/preview_e2e.py legal        # land on the legal hub
    python3 tools/preview_e2e.py 8135 8136 privacy

WHAT THIS IS FOR
tools/preview.py is for looking: it points publishing at a dead port so a
glance can never push anything anywhere. This is for the next step, watching
a save travel from the admin to the public site and render there — the loop
the legal phases repeat for every document. One command instead of two
terminals, one shared throwaway key, and the same guarantees.

WHAT IT IS NOT
A suite (that is tools/test_end_to_end.py, which asserts) and not a deploy
(the deploy reads real stores over SSH). Nothing here runs in CI.

HOW IT STAYS SAFE
Three rules, all structural rather than careful:

  1. Everything is loopback. Both servers bind 127.0.0.1, both URLs are
     built from it, and no argument can name another host — ports and a
     screen are all it accepts, so a typo cannot become a push to
     tech4time.bd.
  2. Both private stores are throwaway, under one temporary directory. The
     single publish.key is minted fresh per run and shared between them;
     your real ../t4t-private-admin and ../t4t-private are never read,
     never written, and never named in an environment variable here.
  3. Both content/ trees are copied out before anything starts and copied
     back at the end. A save in the admin writes the backend copy and the
     publish writes the frontend one; closing up restores both, so looking
     and pushing cannot commit anything either.

Close the browser window, or press Ctrl+C here, and both halves stop, both
stores vanish, and both content trees are as they were.
"""

import json
import os
import re
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"
CONTENT = ROOT / "content"

FE = ROOT.parent / "tech4time-website-frontend"

DEFAULT_BE_PORT = 8125
DEFAULT_FE_PORT = 8126

SESSION_TIMEOUT = 60


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def wait_for(port: int, tries: int = 120) -> bool:
    for _ in range(tries):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return True
        except OSError:
            time.sleep(0.15)
    return False


def rq(method: str, url: str, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=SESSION_TIMEOUT) as r:
        return json.loads(r.read().decode() or "{}")


class Browser:
    """A real Firefox window, not a headless one. The point is to look at it."""

    def __init__(self, drv_port: int):
        self.base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", self.base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox"}}})
        self.s = f"{self.base}/session/{r['value']['sessionId']}"
        rq("POST", self.s + "/window/rect",
           {"width": 1500, "height": 950, "x": 0, "y": 0})

    def go(self, url: str) -> None:
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(1.2)

    def js(self, script: str):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def alive(self) -> bool:
        try:
            rq("GET", self.s + "/url")
            return True
        except Exception:
            return False

    def sign_in(self, base: str, secret: str) -> None:
        """Both steps of the real login form, filled in rather than skipped."""
        self.go(base + "/login.php")
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#user').value = {json.dumps(admin_session.USER)};"
            f"f.querySelector('#password').value = {json.dumps(admin_session.PASSWORD)};"
            "f.submit();"
        )
        time.sleep(1.5)
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#code').value = {json.dumps(admin_session.totp(secret))};"
            "f.submit();"
        )
        time.sleep(1.5)

    def quit(self) -> None:
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def stop_on_signal() -> None:
    """Make a signal leave the same way Ctrl+C does.

    Everything this cleans up -- two servers, two stores, two content trees --
    is cleaned up in a finally block, and a default SIGTERM does not run one.
    """
    def raise_it(_signum, _frame):
        raise KeyboardInterrupt

    for sig in (signal.SIGTERM, signal.SIGHUP):
        signal.signal(sig, raise_it)


def main() -> None:
    stop_on_signal()

    args = [a for a in sys.argv[1:] if not a.startswith("-")]
    flags = {a for a in sys.argv[1:] if a.startswith("-")}

    if "--help" in flags or "-h" in flags:
        print(__doc__)
        return

    if not shutil.which("php"):
        raise SystemExit("This needs the PHP CLI:  sudo apt install php-cli")
    if not FE.is_dir():
        raise SystemExit(
            "The frontend is not beside this repository.\n"
            "This watches a publish travel between the two halves; with one\n"
            "half missing there is nothing to watch. Put it at ../.."
            "/tech4time-website-frontend.")

    be_port, fe_port, section = DEFAULT_BE_PORT, DEFAULT_FE_PORT, "legal"
    ports_seen = 0
    for arg in args:
        if arg.isdigit():
            if ports_seen == 0:
                be_port = int(arg)
            elif ports_seen == 1:
                fe_port = int(arg)
            else:
                raise SystemExit(f"two ports at most, not {arg!r}")
            ports_seen += 1
        elif re.fullmatch(r"[a-z]+", arg):
            section = arg
        else:
            raise SystemExit(f"cannot tell if {arg!r} is a port or a screen -- "
                             f"ports are digits, screens are ?s=<name>")

    be_base = f"http://127.0.0.1:{be_port}"
    fe_base = f"http://127.0.0.1:{fe_port}"

    want_browser = "--no-browser" not in flags
    have_browser = bool(shutil.which("geckodriver") and shutil.which("firefox"))

    if want_browser and not have_browser:
        print("Firefox or geckodriver is missing, so the browser will not be "
              "opened for you.\nEverything else works; sign in yourself with "
              "what is printed below.")

    work = Path(tempfile.mkdtemp(prefix="t4t-e2e-"))
    be_private = work / "admin-private"
    fe_private = work / "site-private"
    be_saved = work / "be-content"
    fe_saved = work / "fe-content"

    # Copied out before anything can be saved over them, and copied back at
    # the end: looking and pushing must not be able to commit anything, on
    # either half.
    shutil.copytree(CONTENT, be_saved)
    shutil.copytree(FE / "content", fe_saved)

    php_be = php_fe = drv = browser = None

    try:
        # One key, minted fresh, shared between the two throwaway stores and
        # nowhere else. 64 hex characters, the format make_publish_key.py
        # writes; the frontend provisions its own secret.key on demand.
        key = secrets.token_hex(32)
        be_private.mkdir(mode=0o700, parents=True)
        fe_private.mkdir(mode=0o700, parents=True)
        (be_private / "publish.key").write_text(key + "\n")
        os.chmod(be_private / "publish.key", 0o600)
        (fe_private / "publish.key").write_text(key + "\n")
        os.chmod(fe_private / "publish.key", 0o600)

        secret = admin_session.make_account(be_private)

        php_be = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{be_port}", "-t", str(DOCROOT), str(ROUTER)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
            env=dict(os.environ, T4T_PRIVATE=str(be_private),
                     T4T_PUBLIC_URL=fe_base),
        )
        php_fe = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{fe_port}", "-t", str(FE),
             str(FE / "tools" / "dev-router.php")],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
            env=dict(os.environ, T4T_PRIVATE=str(fe_private)),
        )

        if not wait_for(be_port):
            raise SystemExit(
                f"the admin did not start on {be_port} — pass another: "
                f" python3 tools/preview_e2e.py {be_port + 1}")
        if not wait_for(fe_port):
            raise SystemExit(
                f"the public site did not start on {fe_port} — pass another pair.")

        drove = False

        if want_browser and have_browser:
            drv_port = free_port()
            drv = subprocess.Popen(
                ["geckodriver", "--port", str(drv_port)],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                start_new_session=True)

            if wait_for(drv_port):
                browser = Browser(drv_port)
                browser.sign_in(be_base, secret)
                browser.go(be_base + f"/?s={section}")

                # Asked rather than assumed: a sign-in that silently did not
                # take leaves a browser sitting on the login page, and saying
                # "signed in" over the top of that is the one thing this must
                # not do.
                landed = browser.js("return location.pathname;") or ""
                drove = "login.php" not in landed

                if not drove:
                    print("The sign-in did not take. Sign in by hand with "
                          "what is printed below.")

        print()
        print(f"  watching   {be_base}/  ->  {fe_base}/")
        print(f"  admin      {be_base}/?s={section}")
        print(f"  public     {fe_base}/pages/privacy-policy/")
        print()
        print("  One throwaway signing key, both halves, loopback only.")
        print("  Save in the admin and watch it land on the public site.")
        print()

        if drove:
            print("  A browser is open and signed in. Close it, or press Ctrl+C here,")
            print("  and both halves stop.")
        else:
            print("  Sign in at the admin URL above with:")
            print()
            print(f"    user       {admin_session.USER}")
            print(f"    password   {admin_session.PASSWORD}")
            print()
            print("  The six-digit code is below and changes every 30 seconds.")
            print("  Ctrl+C to stop.\n")

        last = ""
        while True:
            code = admin_session.totp(secret)
            if code != last:
                last = code
                print()
            left = 30 - int(time.time()) % 30
            print(f"\r    code       {code}   ({left:>2}s) ", end="", flush=True)
            time.sleep(1)
            if browser is not None and int(time.time()) % 5 == 0:
                if not browser.alive():
                    print("\n\n  the browser was closed")
                    return

    except KeyboardInterrupt:
        print()
    finally:
        if browser:
            browser.quit()

        for proc in (drv, php_be, php_fe):
            if proc is None:
                continue
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass

        # Back the way both were, whatever was pressed in either. Removed and
        # recopied rather than file by file, so a document created during the
        # watch goes as well.
        if be_saved.is_dir():
            shutil.rmtree(CONTENT, ignore_errors=True)
            shutil.copytree(be_saved, CONTENT)
        fe_content = FE / "content"
        if fe_saved.is_dir():
            shutil.rmtree(fe_content, ignore_errors=True)
            shutil.copytree(fe_saved, fe_content)

        shutil.rmtree(work, ignore_errors=True)
        print("  both halves stopped, both stores gone, both content trees as they were")


if __name__ == "__main__":
    main()
