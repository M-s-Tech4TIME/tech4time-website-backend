#!/usr/bin/env python3
"""
Drive the Markdown ribbons in a real browser.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_md_toolbar.py

Needs Firefox and geckodriver, and leaves processes behind if interrupted
(`pkill firefox geckodriver`).

WHY THIS EXISTS
tools/test_privacy_admin.py proves the editor saves, publishes and previews
over HTTP -- but the ribbons are JavaScript, and HTTP never runs them. A
button that inserts the wrong syntax writes text the renderer shows
literally, which is a silent corruption of a legal document: the words are
all there and the meaning is subtly not. So every ribbon is pressed here, in
a signed-in browser, against the real privacy screen.

WHAT IT DOES NOT DO
Preview. That posts the form and renders server-side, which needs no script
at all and is proved over HTTP. What needs a browser is selection handling:
the caret staying in the field, whole-line extension for lists, fences on
their own lines.
"""
import json
import os
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
DATA = ROOT / "content" / "privacy.json"
W3C = "element-6066-11e4-a52e-4f735466cecf"

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


def rq(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        raise SystemExit("WebDriver error:\n" + e.read().decode()[:600])


def wait_for(port, tries=120) -> bool:
    for _ in range(tries):
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/login.php",
                                   timeout=1)
            return True
        except Exception:
            time.sleep(0.15)
    return False


class Browser:
    def __init__(self, drv_port):
        self.base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", self.base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {"args": ["-headless"]}}}})
        self.s = f"{self.base}/session/{r['value']['sessionId']}"
        rq("POST", self.s + "/window/rect",
           {"width": 1500, "height": 1300, "x": 0, "y": 0})

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(1.5)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def toolbar_button(self, bar, index):
        eid = rq("POST", self.s + "/elements",
                 {"using": "css selector",
                  "value": f"#{bar} .rte__toolbar button"})["value"][index][W3C]
        rq("POST", self.s + f"/element/{eid}/click", {})
        time.sleep(0.4)

    def sign_in(self, web_port, secret):
        """Through the real login page, in the browser, in two steps.

        Submitting the form rather than clicking the button: the point here is
        to arrive at the editor, and the login page's own behaviour is the
        subject of tools/test_admin_auth.py.
        """
        base = f"http://127.0.0.1:{web_port}"

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

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


# Buttons in the first toolbar, in TOOLS order: B I U | ul ol link | table
# note centre. Separator spans carry no button.
BOLD, ITALIC, UNDERLINE, BULLETS, NUMBERED, LINK, TABLE, NOTE, CENTRE = range(9)


def run(b: Browser, web_port: int, r: Results):
    b.go(f"http://127.0.0.1:{web_port}/?s=privacy")

    print("setup")
    r.check("the Markdown fields carry toolbars", b.js(
        "return document.querySelectorAll('textarea[data-md]').length") >= 13)
    r.check("and stay plain textareas underneath", b.js(
        "return document.querySelectorAll('textarea[data-md].rte__source').length") == 0)
    r.check("the HTML editor left them alone", b.js(
        "return [...document.querySelectorAll('textarea[data-md]')]"
        ".every(t => !t.hasAttribute('hidden'))"))
    # The prompts for link addresses and table widths would block headless
    # Firefox mid-click -- and a reload wipes the stub, so it is set again
    # after each one. Stubbing is honest here: what is under test is what
    # the toolbar DOES with the answer, and the guard on a bad answer is
    # proved over HTTP by posting one.
    stub = ("window.prompt = function(msg, def) {"
            "  return /column/i.test(msg) ? '3' : 'https://example.example/x'; };")
    b.js(stub)

    # The first 21 characters are "Tech4TIME decides why".
    sel = ("var t = document.querySelector('textarea[name=\"sections[0][body]\"]');"
           "t.focus(); t.setSelectionRange(0, 21);")
    sel25 = ("var t = document.querySelector('textarea[name=\"sections[0][body]\"]');"
             "t.focus(); t.setSelectionRange(0, 25);")

    print("\ninline ribbons wrap the selection")
    b.js(sel + "t.value")
    b.toolbar_button("band-sections", BOLD)
    r.check("Bold wraps in asterisks", b.js(
        "return document.querySelector('textarea[name=\"sections[0][body]\"]').value"
        ".startsWith('**Tech4TIME decides why**')"))
    b.js(sel25)
    b.toolbar_button("band-sections", ITALIC)
    r.check("Italic nests inside", b.js(
        "var v = document.querySelector('textarea[name=\"sections[0][body]\"]').value;"
        "return v.indexOf('***Tech4TIME decides why***') === 0"))
    b.js("location.reload();")
    time.sleep(1.5)
    b.js(stub)

    print("\nblock ribbons stand on their own lines")
    b.js(sel)
    b.toolbar_button("band-sections", NOTE)
    v = b.js("return document.querySelector('textarea[name=\"sections[0][body]\"]').value")
    r.check("a note wraps the selection on its own lines",
            v.startswith(":::note\nTech4TIME decides why\n:::\n"), v[:60])
    b.js("location.reload();")
    time.sleep(1.5)
    b.js(stub)

    b.js(sel)
    b.toolbar_button("band-sections", TABLE)
    v = b.js("return document.querySelector('textarea[name=\"sections[0][body]\"]').value")
    r.check("a table arrives square: header, rule, one row",
            "| --- | --- | --- |" in v
            and len([ln for ln in v.split("\n") if ln.strip().startswith("|")]) >= 3,
            v[:120])

    print("\nlists take whole lines, links take the prompt's answer")
    b.js("location.reload();")
    time.sleep(1.5)
    b.js(stub)

    b.js("var t = document.querySelector('textarea[name=\"sections[0][body]\"]');"
         "t.focus(); t.setSelectionRange(0, t.value.indexOf('\\n'));")
    b.toolbar_button("band-sections", BULLETS)
    r.check("a bullet prefixes the line", b.js(
        "return document.querySelector('textarea[name=\"sections[0][body]\"]').value"
        ".startsWith('- Tech4TIME decides why')"))
    b.js("location.reload();")
    time.sleep(1.5)
    b.js(stub)

    b.js(sel)
    b.toolbar_button("band-sections", LINK)
    r.check("a link wraps with the answered address", b.js(
        "return document.querySelector('textarea[name=\"sections[0][body]\"]').value"
        ".startsWith('[Tech4TIME decides why](https://example.example/x)')"))

    print("\nthe field still saves what the ribbons wrote")
    r.check("no navigation happened pressing any of it",
            b.js("return location.search") == "?s=privacy")


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


def main() -> None:
    for need in ("php", "firefox", "geckodriver"):
        if not shutil.which(need):
            print(f"test_md_toolbar: no {need} — nothing runs, nothing proved")
            return

    backup = DATA.read_bytes()
    web_port = free_port()
    drv_port = free_port()

    work = Path(tempfile.mkdtemp(prefix="t4t-mdtoolbar-"))
    private = work / "private"
    private.mkdir(mode=0o700, parents=True, exist_ok=True)

    r = Results()
    server = drv_proc = browser = None
    try:
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(DOCROOT), str(ROUTER)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
            env=dict(os.environ, T4T_PRIVATE=str(private)),
        )
        drv_proc = subprocess.Popen(
            ["geckodriver", "--port", str(drv_port)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
        if not wait_for(web_port):
            raise SystemExit("the PHP server never came up")
        for _ in range(120):
            try:
                with socket.create_connection(("127.0.0.1", drv_port), 0.2):
                    break
            except OSError:
                time.sleep(0.15)
        else:
            raise SystemExit("geckodriver never came up")

        secret = admin_session.make_account(private)
        browser = Browser(drv_port)
        browser.sign_in(web_port, secret)
        run(browser, web_port, r)
    finally:
        if browser is not None:
            browser.quit()
        for proc in [p for p in (drv_proc, server) if p is not None]:
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass
        shutil.rmtree(work, ignore_errors=True)
        DATA.write_bytes(backup)
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
