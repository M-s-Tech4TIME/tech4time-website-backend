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
a signed-in browser, against the real privacy screen -- including the table
and link dialogs, which are driven like a person drives them, and the guards
on bad answers.

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

    def toolbar_button(self, bar, label):
        found = rq("POST", self.s + "/elements",
                   {"using": "css selector",
                    "value": f'#{bar} .rte__toolbar button[aria-label="{label}"]'})["value"]
        assert found, f"no ribbon button {label!r} on {bar}"
        rq("POST", self.s + f"/element/{found[0][W3C]}/click", {})
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


# Ribbon buttons, located by accessible name -- never by index, which menu
# triggers interleaved with plain buttons would silently shift. Separators
# carry no button.


def run(b: Browser, web_port: int, r: Results):
    b.go(f"http://127.0.0.1:{web_port}/?s=privacy")

    print("setup")
    r.check("the Markdown fields carry toolbars", b.js(
        "return document.querySelectorAll('textarea[data-md]').length") == 2)
    r.check("and stay plain textareas underneath", b.js(
        "return document.querySelectorAll('textarea[data-md].rte__source').length") == 0)
    r.check("the HTML editor left them alone", b.js(
        "return [...document.querySelectorAll('textarea[data-md]')]"
        ".every(t => !t.hasAttribute('hidden'))"))
    r.check("ribbon and field read as one box", b.js(
        "var t = document.querySelector('textarea[name=\"policy[body]\"]');"
        "return t.parentNode.classList.contains('md')"))
    r.check("with icon buttons, not text ones", b.js(
        "return [...document.querySelectorAll('#band-policy .rte__toolbar')]"
        ".every(bar => [...bar.children]"
        ".filter(el => el.tagName === 'BUTTON' || el.classList.contains('rte__menu-wrap'))"
        ".every(el => {"
        " const btn = el.tagName === 'BUTTON' ? el : el.querySelector('button');"
        " return btn.querySelector('svg') !== null || btn.textContent.trim().length <= 2; }))"))
    r.check("plus heading and alignment menus, icon-only", b.js(
        "return [...document.querySelectorAll('#band-policy .rte__toolbar')]"
        ".slice(0, 1).flatMap(bar => [...bar.querySelectorAll("
        "':scope > button, :scope > .rte__menu-wrap > button')]"
        ".map(el => el.getAttribute('aria-label'))).join('|')")
        == "Heading level|Bold|Italic|Underline|Bulleted list|Numbered list|"
           "Text alignment|Insert link|Insert table|Highlight box",
        "menu triggers missing or mislabelled")
    r.check("and the menus hide until opened", b.js(
        "return [...document.querySelectorAll('#band-policy .rte__menu')]"
        ".every(m => m.hasAttribute('hidden'))"))

    def open_menu(label):
        b.js("[...document.querySelector('#band-policy .rte__toolbar').querySelectorAll('button')]"
             ".find("
             "el => el.getAttribute('aria-label') === " + json.dumps(label)
             + ").click()")
        time.sleep(0.4)

    def pick(value):
        # Two statements, not one chained call: geckodriver's execute throws
        # SyntaxError on document.querySelector('...:not([hidden])...').click()
        # in a single expression, while var + click proves the selector parses.
        b.js("var el = document.querySelector('#band-policy .rte__menu:not([hidden]) "
             "[role=menuitem][data-value=\"" + value + "\"]'); el.click()")
        time.sleep(0.4)

    # No prompt stubbing: questions are asked in the page's own dialog,
    # which the test drives like a person would -- fill the field, press
    # the primary button, or dismiss with Cancel/Escape.
    def answer(text):
        b.js("document.querySelector('.dialog__input').value = "
             + json.dumps(text) + ";")
        b.js("document.querySelector('.dialog .btn--primary').click();")
        time.sleep(0.5)

    def dismiss():
        b.js("document.querySelector('.dialog .btn--ghost').click();")
        time.sleep(0.4)

    # Selects "data controller" inside the single policy body.
    sel = ("var t = document.querySelector('textarea[name=\"policy[body]\"]');"
           "t.focus(); t.setSelectionRange(t.value.indexOf('data controller'),"
           " t.value.indexOf('data controller') + 15);")
    sel25 = ("var t = document.querySelector('textarea[name=\"policy[body]\"]');"
             "t.focus();"
             "var i = t.value.indexOf('**data controller**');"
             "t.setSelectionRange(i, i + 21);")

    print("\ninline ribbons wrap the selection")
    b.js(sel + "t.value")
    b.toolbar_button("band-policy", "Bold")
    r.check("Bold wraps in asterisks", b.js(
        "return document.querySelector('textarea[name=\"policy[body]\"]').value"
        ".includes('**data controller**')"))
    b.js(sel25)
    b.toolbar_button("band-policy", "Italic")
    r.check("Italic nests inside", b.js(
        "var v = document.querySelector('textarea[name=\"policy[body]\"]').value;"
        "return v.indexOf('***data controller***') !== -1"))
    b.js("location.reload();")
    time.sleep(1.5)

    print("\nblock ribbons stand on their own lines")
    b.js(sel)
    b.toolbar_button("band-policy", "Highlight box")
    v = b.js("return document.querySelector('textarea[name=\"policy[body]\"]').value")
    r.check("a note wraps the selection on its own lines",
            ":::note\ndata controller\n:::" in v, v[:80])
    b.js("location.reload();")
    time.sleep(1.5)

    b.js(sel)
    b.toolbar_button("band-policy", "Insert table")
    r.check("a dialog asks, in the page and not the browser",
            b.js("return document.querySelector('.dialog__input') !== null"))
    answer("3")
    v = b.js("return document.querySelector('textarea[name=\"policy[body]\"]').value")
    r.check("a table arrives square: header, rule, one row",
            "| --- | --- | --- |" in v
            and len([ln for ln in v.split("\n") if ln.strip().startswith("|")]) >= 3,
            v[:120])

    print("\na bad column count is refused in the same dialog")
    b.js("location.reload();")
    time.sleep(1.5)

    before = b.js("return (document.querySelector('textarea[name=\"policy[body]\"]').value.match(/\\| --- \\|/g) || []).length")
    b.js(sel)
    b.toolbar_button("band-policy", "Insert table")
    answer("9")
    r.check("nine columns are refused with words",
            b.js("return [...document.querySelectorAll('.dialog__text')].some("
                 "el => el.textContent.indexOf('2 to 6') !== -1)"))
    dismiss()
    r.check("and no skeleton was added by the refused attempt", b.js(
        "return (document.querySelector('textarea[name=\"policy[body]\"]').value.match(/\\| --- \\|/g) || []).length"
        ) == before)

    print("\nthe dropdowns rewrite whole lines and blocks")
    b.js(sel)
    open_menu("Heading level")
    pick("h3")
    r.check("a heading prefixes the whole line", b.js(
        "return document.querySelector('textarea[name=\"policy[body]\"]').value"
        ".includes('### Tech4TIME decides why and how')"))
    b.js("location.reload();")
    time.sleep(1.5)

    b.js(sel)
    open_menu("Text alignment")
    pick("right")
    r.check("an alignment wraps the selection in fences", b.js(
        "return document.querySelector('textarea[name=\"policy[body]\"]').value"
        ".includes(':::right\\ndata controller\\n:::')"))
    b.js("location.reload();")
    time.sleep(1.5)

    print("\nthe menus behave like menus")
    open_menu("Heading level")
    r.check("one open at a time",
            b.js("return document.querySelectorAll('#band-policy .rte__menu:not([hidden])').length") == 1)
    r.check("its options run in a horizontal strip, not a column",
            b.js("var m = document.querySelector('#band-policy .rte__menu:not([hidden])');"
                 "return getComputedStyle(m).flexDirection === 'row'"
                 " && new Set([...m.children].map(el => el.offsetTop)).size === 1"))
    open_menu("Text alignment")
    r.check("opening another closes the first",
            b.js("return document.querySelectorAll('#band-policy .rte__menu:not([hidden])').length") == 1)
    b.js("var item = document.querySelector('#band-policy .rte__menu:not([hidden]) [role=menuitem]');"
         "item.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}))")
    time.sleep(0.3)
    r.check("Escape closes",
            b.js("return document.querySelectorAll('#band-policy .rte__menu:not([hidden])').length") == 0)
    b.js("location.reload();")
    time.sleep(1.5)

    print("\nlists take whole lines, links take the dialog's answer")
    b.js("location.reload();")
    time.sleep(1.5)

    b.js("var t = document.querySelector('textarea[name=\"policy[body]\"]');"
         "t.focus(); t.setSelectionRange(0, t.value.indexOf('\\n'));")
    b.toolbar_button("band-policy", "Bulleted list")
    r.check("a bullet prefixes the line", b.js(
        "return document.querySelector('textarea[name=\"policy[body]\"]').value"
        ".startsWith('- ## Who is responsible')"))
    b.js("location.reload();")
    time.sleep(1.5)

    b.js(sel)
    b.toolbar_button("band-policy", "Insert link")
    answer("https://example.example/x")
    r.check("a link wraps with the answered address", b.js(
        "return document.querySelector('textarea[name=\"policy[body]\"]').value"
        ".includes('[data controller](https://example.example/x)')"))
    b.js("location.reload();")
    time.sleep(1.5)

    print("\na bad address is refused before it reaches the field")
    b.js(sel)
    b.toolbar_button("band-policy", "Insert link")
    answer("javascript:alert(1)")
    r.check("the guard speaks in the page",
            b.js("return [...document.querySelectorAll('.dialog__text')].some("
                 "el => el.textContent.indexOf('https://') !== -1)"))
    dismiss()
    r.check("and the field is untouched",
            b.js("return document.querySelector('textarea[name=\"policy[body]\"]').value"
                 ".indexOf('[Tech4TIME') === -1"))

    print("\nthe field still saves what the ribbons wrote")
    r.check("no navigation happened pressing any of it",
            b.js("return location.search") == "?s=privacy")

    print("\narriving by rail-click builds them too, not just direct loads")

    b.go(f"http://127.0.0.1:{web_port}/?s=company")
    r.check("no Markdown fields on the way in",
            b.js("return document.querySelectorAll('textarea[data-md]').length") == 0)
    # The rail lists the hub, not the editor: Legal -> Edit -> privacy screen,
    # every step swapped in place rather than loaded. The failure this guards
    # against built toolbars on direct loads and none on swapped ones, because
    # the swap re-ran every init except the new one.
    b.js("document.querySelector('.rail a[href=\"?s=legal\"]').click();")
    time.sleep(2.0)
    r.check("the hub swapped in",
            b.js("return location.search") == "?s=legal")
    b.js("document.querySelector('#admin-body a[href=\"?s=privacy\"]').click();")
    time.sleep(2.0)
    r.check("the editor swapped in after it",
            b.js("return location.search") == "?s=privacy")
    n = b.js("return document.querySelectorAll('#admin-body .rte__toolbar').length")
    r.check("the twice-swapped screen carries its ribbons", n == 2,
            f"{n} toolbars after the swaps")


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
