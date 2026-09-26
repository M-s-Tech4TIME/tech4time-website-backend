#!/usr/bin/env python3
"""
Open the admin, already signed in, on a throwaway account.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:

    python3 tools/preview.py             # opens a browser, signed in
    python3 tools/preview.py --no-browser    # just the server and the codes
    python3 tools/preview.py 8123        # a different port
    python3 tools/preview.py legal       # land on the legal hub instead
    python3 tools/preview.py 8123 legal  # both: a port and a landing screen

WHAT THIS IS FOR
Looking at the editors. Reviewing a change to the shell means seeing the rail,
the bar, the outline column and the account menu, and all of them are behind a
password and a second factor — so the cost of a glance is finding the phone
with the authenticator on it. This removes that, without removing the password.

NOTHING IS BYPASSED. This creates a real account in a throwaway private store,
signs in through the real /login.php with the real password and a real
time-based code, and hands the browser the session that comes back. Take this
file away and the admin is exactly as protected as it was; there is no flag in
lib/ that it sets and no branch in the application that knows it exists.

THE ACCOUNT LASTS AS LONG AS THE PROCESS
Its private store is a temporary directory, deleted on the way out. It is not
../t4t-private-admin, so whatever accounts you already have locally are neither
read nor touched — and it cannot be signed into again once this stops.

TWO THINGS IT MAKES SAFE THAT ARE NOT SAFE BY DEFAULT

  content/  is copied out before the server starts and copied back on the way
  out, so pressing Save in a preview cannot leave a change in the repository.

  Publishing is pointed at a closed port on localhost. lib/publish_client.php
  falls back to PUBLIC_SITE — https://tech4time.bd — when nothing overrides it,
  and a preview that quietly pushed a document to the live website would be a
  bad way to find that out. Save works, the record is written, and the editor
  says the live site does not have it. That is the truth, and it is also what
  that path looks like when it genuinely fails.

WHAT IT NEEDS
The PHP CLI. Firefox and geckodriver as well, for the browser to be opened for
you — without them it prints the credentials and a live code instead, and you
sign in yourself in whatever browser you like.
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
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"
CONTENT = ROOT / "content"

DEFAULT_PORT = 8123

# Long, because starting a real Firefox window is not a fast operation on a
# machine that has not started one recently.
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


def dead_port() -> int:
    """A port on loopback with nothing listening on it.

    Where publishing is pointed. Bound and released, so it is free and almost
    certainly still free a second later — and if something does take it, the
    worst case is a connection to another local process, not to the live site.
    """
    return free_port()


# --------------------------------------------------------------- WebDriver

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


# ------------------------------------------------------------------- main

def sections() -> list[tuple[str, str]]:
    """The editor screens, read from the rail's own registry.

    This used to be a list typed out below, and it went stale the moment an
    editor was added: the services screen existed for a while and this tool
    would not tell you where it was. ADMIN_SECTIONS is what the rail itself is
    built from, so reading it means the next editor appears here with nobody
    having to remember. An empty list is not fatal — the admin's own URL is
    printed regardless, and the rail is right there once you are in.
    """
    try:
        out = subprocess.run(
            ["php", "-r",
             "require 'lib/admin.php'; echo json_encode(ADMIN_SECTIONS);"],
            cwd=ROOT, capture_output=True, text=True, timeout=20, check=True,
        ).stdout
        return [(name, meta["label"]) for name, meta in json.loads(out).items()]
    except Exception:
        return []


def banner(base: str, secret: str, drove: bool) -> None:
    print()

    rows = sections()
    # One column for every row, the admin's own URL included.
    width = max([len("the admin")] + [len(label) for _, label in rows]) + 4

    print(f"  {'the admin':<{width}}{base}/")

    for name, label in rows:
        print(f"  {label.lower():<{width}}{base}/?s={name}")

    print()

    if drove:
        print("  A browser is open and signed in. Close it, or press Ctrl+C here,")
        print("  and the account stops existing.")
    else:
        print("  Sign in at the URL above with:")

    print()
    print(f"    user       {admin_session.USER}")
    print(f"    password   {admin_session.PASSWORD}")
    print()
    print("  The six-digit code is below and changes every 30 seconds.")
    print("  Ctrl+C to stop.\n")


def watch(secret: str, browser) -> None:
    """Print a live authenticator code until interrupted, or the window closes.

    The code is printed even when a browser was opened: a second window, or a
    phone on the same machine, wants one too, and there is nowhere else to get
    it — the account exists only in a directory that is about to be deleted.
    """
    last = ""
    checked = 0.0

    while True:
        code = admin_session.totp(secret)
        left = 30 - int(time.time()) % 30

        if code != last:
            last = code
            print()

        print(f"\r    code       {code}   ({left:>2}s) ", end="", flush=True)

        time.sleep(1)

        # Cheap enough once every few seconds, and it is what makes closing the
        # window a way to stop this rather than something that leaves it
        # printing codes for an account nobody can reach.
        if browser is not None and time.time() - checked > 5:
            checked = time.time()
            if not browser.alive():
                print("\n\n  the browser was closed")
                return


def stop_on_signal() -> None:
    """Make a signal leave the same way Ctrl+C does.

    Everything this cleans up -- the throwaway account, the PHP process, the
    copy of content/ -- is cleaned up in a finally block, and a default SIGTERM
    does not run one. So `timeout`, `kill`, and closing the terminal all left a
    server running and a temporary directory behind. Found by running it under
    `timeout` and looking.
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

    port = DEFAULT_PORT
    section = "company"
    for arg in args:
        if arg.isdigit():
            port = int(arg)
        elif re.fullmatch(r"[a-z]+", arg):
            section = arg
        else:
            raise SystemExit(f"cannot tell if {arg!r} is a port or a screen -- "
                             f"ports are digits, screens are ?s=<name>")
    base = f"http://127.0.0.1:{port}"

    want_browser = "--no-browser" not in flags
    have_browser = bool(shutil.which("geckodriver") and shutil.which("firefox"))

    if want_browser and not have_browser:
        print("Firefox or geckodriver is missing, so the browser will not be "
              "opened for you.\nEverything else works; sign in yourself with "
              "what is printed below.")

    work = Path(tempfile.mkdtemp(prefix="t4t-preview-"))
    private = work / "private"
    saved = work / "content"

    # Copied out before anything can be saved over it, and copied back at the
    # end. A preview is for looking; it must not be able to commit anything.
    shutil.copytree(CONTENT, saved)

    php = drv = browser = None

    try:
        secret = admin_session.make_account(private)

        php = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(DOCROOT), str(ROUTER)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
            env=dict(os.environ,
                     T4T_PRIVATE=str(private),
                     # Never the live site. See the header.
                     T4T_PUBLIC_URL=f"http://127.0.0.1:{dead_port()}"))

        if not wait_for(port):
            raise SystemExit(
                f"php did not start on {port}. Something else may be using it "
                f"— pass a different port:  python3 tools/preview.py 8124")

        drove = False

        if want_browser and have_browser:
            drv_port = free_port()
            drv = subprocess.Popen(
                ["geckodriver", "--port", str(drv_port)],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                start_new_session=True)

            if wait_for(drv_port):
                browser = Browser(drv_port)
                browser.sign_in(base, secret)
                browser.go(base + f"/?s={section}")

                # Asked rather than assumed: a sign-in that silently did not
                # take leaves a browser sitting on the login page, and saying
                # "signed in" over the top of that is the one thing this must
                # not do.
                landed = browser.js("return location.pathname;") or ""
                drove = "login.php" not in landed

                if not drove:
                    print("The sign-in did not take. Sign in by hand with "
                          "what is printed below.")

        banner(base, secret, drove)
        watch(secret, browser if drove else None)

    except KeyboardInterrupt:
        print()
    finally:
        if browser:
            browser.quit()

        for proc in (drv, php):
            if proc is None:
                continue
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass

        # Back the way it was, whatever was pressed in there. Removed and
        # recopied rather than file by file, so a document created during the
        # preview goes as well -- the next editor added here will write one.
        if saved.is_dir():
            shutil.rmtree(CONTENT, ignore_errors=True)
            shutil.copytree(saved, CONTENT)

        shutil.rmtree(work, ignore_errors=True)
        print("  the throwaway account is gone, and content/ is as it was")


if __name__ == "__main__":
    main()
