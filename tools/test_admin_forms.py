#!/usr/bin/env python3
"""
Drive the admin in a real browser and prove a click never throws the page away.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_admin_forms.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing, so it does not block a machine that only has PHP.

WHY A BROWSER
The whole subject is what a click does to the page, and none of it is visible
from the server: the request the admin sends is byte for byte the request the
browser would have sent, and the response is the same page. What changed is
that the answer is put back in place instead of replacing the document — so the
things worth asserting are the scroll position, where the focus went, which
element survived, and that the document was never navigated. A PHP test cannot
see any of those.

THE TWO HALVES OF THE SAME SUBJECT, IN ONE FILE
    the buttons   admin-forms.js — add a row, move one, remove one, save
    the links     admin-swap.js — the rail, "Edit", "Cancel", "Discard", Back

They share a harness because they share a mechanism: admin-swap.js does the
putting-back for both. Two files here would mean two sign-ins, two browsers and
two copies of 200 lines that exist to get as far as an editor.

WHAT IS BEING PROTECTED
Every control in these editors is a submit button, and every way between them
is a link. Before this, each was a full navigation, and a navigation lands at
the top: pressing "Move down" on the fiftieth technology logo returned you to
the page title, thousands of pixels away. The effect was bad enough that the
company editor was reported as not containing its own data — the only part
anybody ever saw was the first field group. Following a rail item was worse
still: it tore down and rebuilt the whole shell, so the rail was rendered wide
and then snapped back to the width it had been left at, visibly, every time.

AND THAT IT ALL STILL WORKS WITHOUT SCRIPT
The last group here disables JavaScript and does the same edits, and the same
moves between screens, again. That is the hard rule this project holds to, and
it is the reason this was built as a swap over the ordinary form post and the
ordinary link rather than as an endpoint of its own.
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
from http.cookiejar import CookieJar
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"
DATA = ROOT / "content" / "company.json"
CONTACT = ROOT / "content" / "contact.json"


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

    def section(self, name):
        print(f"\n{name}")


def fresh_code(secret: str, used: str) -> str:
    """Wait until the authenticator says something new.

    lib/auth.php refuses a six-digit code that has already been presented
    inside the thirty seconds it is valid for — deliberately, and it is worth
    keeping. This file signs in twice, once per browser, so the second
    sign-in has to wait for the window to roll over. Up to thirty seconds,
    once per run.
    """
    while True:
        code = admin_session.totp(secret)
        if code != used:
            return code
        time.sleep(1)


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def rq(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as err:
        # geckodriver puts the fault in the BODY and only the code in the
        # status line, so an unread error reads as "HTTP Error 500" and says
        # nothing at all. "unexpected alert open" — a dialog left standing by
        # the step before — is the one that matters here, and it cost a
        # debugging round to find the first time. Folded into err.msg rather
        # than raised as something else, because alert() below catches
        # HTTPError to mean "there is no dialog".
        err.msg = f"{err.msg} — {err.read().decode(errors='replace')[:300]}"
        raise


def wait_for(port, tries=120) -> bool:
    for _ in range(tries):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.3):
                return True
        except OSError:
            time.sleep(0.25)
    return False


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """admin_session.sign_in() asserts on the 302 the second step answers
    with, so the opener it is given must not swallow it."""

    def redirect_request(self, *_args, **_kwargs):
        return None


class Browser:
    def __init__(self, drv_port, script=True):
        self.base = f"http://127.0.0.1:{drv_port}"
        prefs = {} if script else {"javascript.enabled": False}
        r = rq("POST", self.base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {"args": ["-headless"], "prefs": prefs}}}})
        self.s = f"{self.base}/session/{r['value']['sessionId']}"
        rq("POST", self.s + "/window/rect",
           {"width": 1400, "height": 900, "x": 0, "y": 0})

    def go(self, url, settle=1.6):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(settle)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def click(self, css):
        """Click the first match, through the driver, the way a person does."""
        rq("POST", self.s + f"/element/{self._one(css)}/click", {})
        time.sleep(2.0)

    def sign_in(self, web_port, secret):
        """Through the real login page, in two steps.

        Filling and submitting from script rather than driving the fields:
        the point here is to arrive at an editor, and the login page's own
        behaviour is the subject of tools/test_admin_auth.py. It is also the
        one part of this file that cannot be done with JavaScript off, so the
        no-script browser signs in with script and has it switched off after.
        """
        base = f"http://127.0.0.1:{web_port}"

        self.go(base + "/login.php")
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#user').value = {json.dumps(admin_session.USER)};"
            f"f.querySelector('#password').value = {json.dumps(admin_session.PASSWORD)};"
            "f.submit();")
        time.sleep(1.6)

        code = admin_session.totp(secret)
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#code').value = {json.dumps(code)};"
            "f.submit();")
        time.sleep(1.6)
        return code

    def adopt_session(self, base: str, cookies) -> None:
        """Carry a session in from outside, rather than signing in here.

        The no-script browser cannot use sign_in() above — that fills the
        fields from JavaScript — and driving the login form through the driver
        is a fight with element interactability that proves nothing about this
        file's subject. So the session is made over plain HTTP by
        admin_session, which is what every other test here uses, and its
        cookie is handed to the browser.

        What is being tested below is what the EDITORS do with script off. How
        somebody signs in with script off is tools/test_admin_auth.py's
        subject, and it covers it.
        """
        self.go(base + "/login.php", settle=0.6)
        rq("DELETE", self.s + "/cookie")
        for c in cookies:
            rq("POST", self.s + "/cookie", {"cookie": {
                "name": c.name, "value": c.value, "path": "/",
                "domain": c.domain or "127.0.0.1"}})

    def rect(self, css):
        """Where an element is and how big, straight from the driver.

        Not through JavaScript: half of what is asserted below is asserted in
        a browser with JavaScript switched off, and that is exactly the half
        where a measurement taken by script would be worthless.
        """
        return rq("GET", self.s + f"/element/{self._one(css)}/rect")["value"]

    def text(self, css):
        return rq("GET", self.s + f"/element/{self._one(css)}/text")["value"]

    def _one(self, css):
        found = rq("POST", self.s + "/elements",
                   {"using": "css selector", "value": css})["value"]
        if not found:
            raise AssertionError(f"nothing matched {css}")
        return found[0]["element-6066-11e4-a52e-4f735466cecf"]

    def type(self, css, text):
        """Send keys the way a keyboard does — appended, and raising the
        `input` events the unsaved-changes guard listens for."""
        rq("POST", self.s + f"/element/{self._one(css)}/value", {"text": text})
        time.sleep(0.4)

    def asked(self):
        """What the admin's own question box says, or None if none is open.

        Not /alert/text: these are the page's own <dialog>, not the browser's
        box, which is the whole point of admin-dialog.js. The one prompt the
        driver's alert API would still see is beforeunload's, and that one is
        the browser's by design and cannot be anything else.
        """
        return self.js(
            "var d = document.querySelector('dialog.dialog[open]');"
            "return d ? d.textContent.replace(/\\s+/g, ' ').trim() : null;")

    def answer(self, yes):
        self.click('.dialog__actions button[data-answer="%s"]'
                   % ("yes" if yes else "no"))

    def cookie(self, name, value):
        rq("POST", self.s + "/cookie", {"cookie": {
            "name": name, "value": value, "path": "/", "domain": "127.0.0.1"}})

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


# How many rows a band has, what order they are in, and where the page is.
STATE = """
function vals(sel) {
  return Array.prototype.map.call(document.querySelectorAll(sel),
                                  function (e) { return e.value; });
}
return {
  y: Math.round(window.scrollY),
  clients: document.querySelectorAll('input[name^="clients[items]["][name$="[name]"]').length,
  years: vals('input[name^="milestones[items]["][name$="[year]"]'),
  focus: (document.activeElement.getAttribute('value') ||
          document.activeElement.getAttribute('name') || ''),
  marked: window.__stillHere === true
};
"""

# Left on the window object. It does not survive a navigation, so it is the
# thing that says whether one happened.
MARK = "window.__stillHere = true; return true;"

# And on the rail element itself. A property set on a DOM node dies with the
# node, so this says something the one above cannot: not merely that the
# document survived, but that the rail was never rebuilt inside it. That is the
# difference between a page that flashes its menu and one that does not.
TAG_RAIL = "document.getElementById('admin-rail').__t4t = true; return true;"

# LINKS THAT WOULD STILL BE A FULL PAGE LOAD.
#
# Not a list of the links there are today — a list of the ones admin-swap.js
# would decline, which is the same question asked of a screen that does not
# exist yet. Three answers are fine and the fourth is the swap:
#
#   #anchor            the "On this page" column; the browser's own job
#   another origin     the public site, which is a different host entirely
#   target=_blank      a new tab, and a new tab is a new document by right
#   ?s= on this path   swapped
#
# Anything else is a link somebody added without noticing that it throws the
# shell away. This is the check that makes the rule hold for the next editor
# rather than only for the five that exist.
STRAGGLERS = """
var out = [];
var links = document.querySelectorAll('#admin-body a[href], .rail a[href]');

Array.prototype.forEach.call(links, function (a) {
  var written = a.getAttribute('href') || '';
  if (written.charAt(0) === '#') { return; }
  if (a.target && a.target !== '_self') { return; }

  var url = new URL(a.href, location.href);
  if (url.origin !== location.origin) { return; }
  if (url.pathname === location.pathname &&
      new URLSearchParams(url.search).has('s')) { return; }

  out.push(written);
});

return out;
"""

# What screen this is, and what came through the move with it.
SHELL = """
var rail = document.getElementById('admin-rail');
var current = document.querySelectorAll('.rail__item[aria-current="page"]');
var toggle = document.querySelector('.admin-bar__actions [data-theme-toggle]');
var title = document.querySelector('.admin-bar__title');
return {
  heading:  title ? title.textContent.trim() : '',
  title:    document.title,
  search:   location.search,
  hash:     location.hash,
  current:  current.length === 1
              ? current[0].getAttribute('href')
              : '(' + current.length + ' marked current)',
  rail:     rail ? rail.getAttribute('data-rail') : '(no rail)',
  sameRail: rail ? rail.__t4t === true : false,
  themeLabel: toggle ? toggle.getAttribute('aria-label') : '',
  menuOpen: !!document.querySelector('details[data-account][open]'),
  focus:    document.activeElement ? document.activeElement.id : '',
  status:   !!document.querySelector('[data-toasts]'),
  marked:   window.__stillHere === true
};
"""


def run(b: Browser, base: str, r: Results) -> None:
    r.section("every button in the shell asks to be sent this way")
    # STRICTER THAN "every form carries data-async", and the difference is not
    # theoretical: the Save button lives in the bar and reaches its form with
    # the `form` attribute, so a button and the form it submits need not be
    # anywhere near each other. This resolves each button to the form it will
    # actually post and asks about THAT.
    #
    # Signing out is the one exception, and it has to be: the session ends, so
    # there is nothing on the page worth preserving and a new document is the
    # correct answer.
    for screen in ("/?s=overview", "/?s=careers", "/?s=careers&action=new",
                   "/?s=contact", "/?s=company", "/?s=about", "/?s=home",
                   "/?s=services", "/?s=services&service=cybersecurity",
                   "/?s=certifications", "/?s=branding", "/?s=privacy",
                   # Every shape the SEO editor takes: the index, a page
                   # screen, and the two site-wide ones.
                   "/?s=seo", "/?s=seo&page=about",
                   "/?s=seo&site=identity", "/?s=seo&site=crawl",
                   "/?s=account"):
        b.go(base + screen)
        loud = b.js("""
        var out = [];
        var buttons = document.querySelectorAll(
          'button[type=submit], button:not([type]), input[type=submit]');

        Array.prototype.forEach.call(buttons, function (button) {
          var form = button.form;
          if (!form) { return; }
          if (form.classList.contains('account__signout')) { return; }
          if (!form.hasAttribute('data-async')) {
            out.push((button.name || '(unnamed)') + '=' + (button.value || ''));
          }
        });
        return out;""")
        r.check(f"{screen}: no button posts by navigating",
                loud == [],
                f"{loud} — each of these reloads the document and lands back "
                f"at the top of it")

        # AND THAT SOMETHING IS ACTUALLY READING THE ATTRIBUTE.
        #
        # data-async is a request, not a behaviour. The branding editor once
        # carried it on a form whose page had no JavaScript on it at all: the
        # section forgot to call admin_foot(), so the document ended after
        # </form> and the nine scripts below it were never emitted. Every
        # button on that screen was a full page load, and the check above
        # passed the whole time, because the attribute was exactly where it
        # should be.
        #
        # This asks the browser instead of the markup.
        wired = b.js("return !!(window.Tech4Time && window.Tech4Time.adminForms "
                     "&& window.Tech4Time.adminForms.wired);")
        r.check(f"{screen}: and the script that honours it is loaded",
                wired is True,
                "Tech4Time.adminForms is not wired on this screen — the page's "
                "scripts did not load. Does the section call admin_foot()?")

    r.section("every form in the shell asks to be sent this way")
    for screen in ("/?s=careers", "/?s=contact", "/?s=company", "/?s=about",
                   "/?s=home", "/?s=services",
                   "/?s=services&service=cybersecurity",
                   "/?s=certifications", "/?s=branding", "/?s=privacy",
                   # Every SEO screen that posts something. The 404's screen
                   # is a different form from an ordinary page's -- it has no
                   # canonical and no sitemap band -- so both are listed. The
                   # index is not here: it edits nothing, and is checked for
                   # exactly that below.
                   "/?s=seo&page=about", "/?s=seo&page=notfound",
                   "/?s=seo&site=identity", "/?s=seo&site=crawl",
                   "/?s=account"):
        b.go(base + screen)
        counts = b.js(
            "var all = document.querySelectorAll('#admin-main form');"
            "var async = document.querySelectorAll('#admin-main form[data-async]');"
            "return {all: all.length, async: async.length};")
        r.check(f"{screen}: all {counts['all']} forms carry data-async",
                counts["all"] == counts["async"] and counts["all"] > 0,
                f"{counts['async']} of {counts['all']} — a form without it "
                f"navigates, and lands back at the top of the page")

    # The index is the one screen in the shell that edits nothing, so the loop
    # above cannot speak for it: "no form is missing data-async" is true of a
    # page with no forms, which is why that loop insists on finding at least
    # one. Assert what the index actually is instead.
    b.go(base + "/?s=seo")
    index = b.js(
        "var rows = document.querySelectorAll('#band-pages .admin-card');"
        "var linked = 0;"
        "rows.forEach(function (row) {"
        "  if (row.querySelector('a[href*=\"page=\"]')) { linked += 1; }"
        "});"
        "return {forms: document.querySelectorAll('#admin-main form').length,"
        " rows: rows.length, linked: linked};")
    r.check("/?s=seo: the index posts nothing",
            index["forms"] == 0,
            f"{index['forms']} forms on a screen that only lists pages — if it "
            f"has grown one, list it in the roster above instead")
    r.check(f"/?s=seo: each of its {index['rows']} rows opens its own screen",
            index["rows"] > 0 and index["linked"] == index["rows"],
            f"{index['linked']} of {index['rows']} rows carry a link — a row "
            f"nobody can open is a page nobody can edit")

    b.go(base + "/?s=company")
    b.js(MARK)

    r.section("adding a row")
    b.js("window.scrollTo({top: 4000, behavior: 'instant'});")
    time.sleep(0.4)
    before = b.js(STATE)
    b.click('button[name="do"][value="clients-add:0"]')
    after = b.js(STATE)

    r.check("a client row is added", after["clients"] == before["clients"] + 1,
            f"{before['clients']} -> {after['clients']}")
    r.check("the page was not navigated", after["marked"] is True,
            "window.__stillHere was lost, so the document was replaced")
    r.check("focus lands in the new row",
            after["focus"] == f"clients[items][{after['clients'] - 1}][name]",
            f"focus went to {after['focus']!r}")

    r.section("moving a row, twice")
    # base.css sets scroll-behavior: smooth, so this has to be told not to
    # animate — otherwise the read below gets the position it is leaving.
    b.js("window.scrollTo({top: 1200, behavior: 'instant'});")
    time.sleep(0.4)
    start = b.js(STATE)
    b.click('button[name="do"][value="milestones-down:0"]')
    once = b.js(STATE)

    r.check("the row moved", once["years"][:2] == start["years"][:2][::-1],
            f"{start['years'][:3]} -> {once['years'][:3]}")
    r.check("the scroll position is kept", abs(once["y"] - start["y"]) <= 4,
            f"was at {start['y']}, now at {once['y']}")
    r.check("focus follows the row that moved",
            once["focus"] == "milestones-down:1",
            f"focus is on {once['focus']!r}, so a second press would move a "
            f"different row")

    # The point of following it: press again without touching the mouse.
    b.click('button[name="do"][value="milestones-down:1"]')
    twice = b.js(STATE)
    r.check("pressing again moves the same row again",
            twice["years"][:3] == [start["years"][1], start["years"][2],
                                   start["years"][0]],
            f"{start['years'][:3]} -> {twice['years'][:3]}")

    r.section("removing a row")
    b.click(f'button[name="do"][value="clients-remove:{after["clients"] - 1}"]')
    gone = b.js(STATE)
    r.check("the row is removed", gone["clients"] == before["clients"],
            f"{after['clients']} -> {gone['clients']}")
    r.check("still no navigation", gone["marked"] is True)

    r.section("saving")
    # The save button lives in .admin-bar and reaches the form with the
    # `form` attribute, so this is also the assertion that that works.
    b.click('.admin-bar__actions button[name="do"][value="save"]')
    saved = b.js(STATE)
    r.check("saving does not navigate either", saved["marked"] is True,
            "the redirect after a save was followed by the document, not by "
            "fetch()")
    r.check("the address bar was updated",
            "saved" in (b.js("return location.search;") or ""),
            "history.replaceState did not run, so a reload would show the "
            "state before the save")
    r.check("it says what happened",
            "Saved" in (b.js(
                "var n = document.querySelector('.admin__notice');"
                "return n ? n.textContent : '';") or ""))

    # The save button is the one submitter the swap does NOT replace: it lives
    # in the title bar, above <main>, and reaches the form with form=. So the
    # disable that guards against a double-press had nothing to undo it, and
    # the button stayed dead until the operator navigated away or reloaded --
    # which is exactly the state somebody is in after their first save.
    r.check("and the save button still works afterwards",
            b.js("var b = document.querySelector('.admin-bar__actions "
                 "button[name=do][value=save]');"
                 "return !!b && b.disabled === false;"),
            "a save must not be the last thing you can do on a screen")
    r.check("the form is not left marked busy either",
            b.js("var f = document.querySelector('form[data-async]');"
                 "return !!f && !f.hasAttribute('aria-busy');"))

    # getElementsByName, not a selector: a field name holding brackets, inside
    # a JS string, inside a Python string is three levels of escaping, and
    # this file has got one of them wrong before.
    b.js("var i = document.getElementsByName('hero[title]')[0];"
         "if (i) { i.value = 'Saved twice'; }")
    b.click('.admin-bar__actions button[name="do"][value="save"]')
    twice = b.js(STATE)
    r.check("a second save on the same screen goes through",
            twice["marked"] is True
            and "Saved" in (b.js("var n = document.querySelector('.admin__notice');"
                                 "return n ? n.textContent : '';") or ""),
            "the second save never left the browser")

    r.section("the account menu")
    r.check("it is a <details>, so it opens with no script",
            b.js("return !!document.querySelector('.rail__foot details.account "
                 "> summary.account__toggle');"),
            "a scripted dropdown would be empty with JavaScript off")
    r.check("the save button is in the bar and names its form",
            b.js("var b = document.querySelector('.admin-bar__actions "
                 "button[name=do][value=save]');"
                 "return !!(b && b.form && b.form.id === 'company-form');"),
            "the `form` attribute is what lets it sit outside the form")
    r.check("signing out is a POST with a token",
            b.js("var f = document.querySelector('.account__signout');"
                 "return !!(f && f.method === 'post' && f.querySelector("
                 "'input[name=csrf]'));"))


def navigate(b: Browser, base: str, r: Results) -> None:
    """The links: the rail, the account menu, the outline, Back and Forward."""

    r.section("every link on every screen is one the swap will answer")
    for screen in ("/?s=overview", "/?s=careers", "/?s=careers&action=new",
                   "/?s=contact", "/?s=company", "/?s=about", "/?s=home",
                   "/?s=services", "/?s=services&service=cybersecurity",
                   "/?s=certifications", "/?s=branding", "/?s=privacy",
                   # Every shape the SEO editor takes: the index, a page
                   # screen, and the two site-wide ones.
                   "/?s=seo", "/?s=seo&page=about",
                   "/?s=seo&site=identity", "/?s=seo&site=crawl",
                   "/?s=account"):
        b.go(base + screen)
        stragglers = b.js(STRAGGLERS)
        r.check(f"{screen}: no link falls through to a full page load",
                stragglers == [],
                f"{stragglers} — each of these tears the document down and "
                f"rebuilds the rail with it. Either give it ?s= on this same "
                f"path, or make it plainly something else: an in-page anchor, "
                f"another origin, or target=\"_blank\"")

    # And the one screen the list above cannot name, because its URL carries
    # the id of a job post. It is also the screen with the most links on it.
    b.go(base + "/?s=careers")
    editing = b.js("var a = document.querySelector('a[href*=\"action=edit\"]');"
                   "return a ? a.getAttribute('href') : '';")
    r.check("there is a job post to open", editing != "",
            "no Edit link on the careers screen, so the check below tested "
            "nothing")
    if editing:
        b.go(base + "/" + editing)
        stragglers = b.js(STRAGGLERS)
        r.check("editing a job post: no link falls through either",
                stragglers == [], f"{stragglers}")

    r.section("every screen can say what it is doing")
    for screen in ("/?s=overview", "/?s=careers", "/?s=contact",
                   "/?s=company", "/?s=about", "/?s=home", "/?s=services",
                   "/?s=services&service=cybersecurity",
                   "/?s=certifications", "/?s=branding", "/?s=privacy",
                   # Every shape the SEO editor takes: the index, a page
                   # screen, and the two site-wide ones.
                   "/?s=seo", "/?s=seo&page=about",
                   "/?s=seo&site=identity", "/?s=seo&site=crawl",
                   "/?s=account"):
        b.go(base + screen)
        r.check(f"{screen}: there is somewhere to say it",
                b.js(SHELL)["status"],
                "nothing on this screen can report a slow fetch or a failed "
                "one, so a post that never arrived looks exactly like a post "
                "that did")

    r.section("following a link in the rail")
    b.go(base + "/?s=overview")
    b.js(MARK)
    b.js(TAG_RAIL)
    start = b.js(SHELL)

    b.click('.rail__item[href="?s=contact"]')
    moved = b.js(SHELL)

    r.check("the document was not replaced", moved["marked"] is True,
            "window.__stillHere was lost, so this was a full page load")
    r.check("the rail is the same element it was", moved["sameRail"] is True,
            "the rail was torn down and rebuilt — which is what made it flash "
            "open and shut on the way to the page you asked for")
    r.check("the bar says where you are now", moved["heading"] == "Contact",
            f"the heading reads {moved['heading']!r}")
    r.check("so does the browser tab",
            "Contact" in moved["title"] and moved["title"] != start["title"],
            f"{start['title']!r} -> {moved['title']!r}")
    r.check("and so does the address bar", moved["search"] == "?s=contact",
            f"location.search is {moved['search']!r}, so a reload would show "
            f"the screen before this one")
    r.check("exactly one rail row is marked current, and it is this one",
            moved["current"] == "?s=contact",
            f"aria-current is on {moved['current']!r}")
    r.check("focus lands on the editing column",
            moved["focus"] == "admin-main",
            f"focus is on {moved['focus']!r} — a screen reader would be left "
            f"in the rail, and the next Tab would not be this page's first "
            f"control")

    r.section("what the browser knows and the server does not")
    b.click("[data-rail-toggle]")
    narrow = b.js(SHELL)
    r.check("the toggle narrows the rail", narrow["rail"] == "narrow",
            f"data-rail is {narrow['rail']!r}")
    r.check("and writes the cookie the server reads back",
            "t4t_rail=narrow" in (b.js("return document.cookie;") or ""),
            "without the cookie the width is decided in the browser, after "
            "the page has been painted — which is the flash")

    b.click('.rail__item[href="?s=company"]')
    kept = b.js(SHELL)
    r.check("the rail keeps its width across a move", kept["rail"] == "narrow",
            f"data-rail is {kept['rail']!r} after moving screens")
    r.check("and did not have to be rebuilt to keep it",
            kept["sameRail"] is True)

    # The theme button lives in the bar, which IS replaced. Its label says
    # which mode a press would move to, and nothing the server sends can know
    # that — so if it is not carried across, it starts lying after one move.
    before = b.js(SHELL)["themeLabel"]
    b.js("Tech4Time.theme.toggle(); return true;")
    flipped = b.js(SHELL)["themeLabel"]
    b.click('.rail__item[href="?s=careers"]')
    after = b.js(SHELL)["themeLabel"]
    r.check("the theme button's label survives a move",
            flipped != before and after == flipped,
            f"{before!r} -> pressed -> {flipped!r} -> moved -> {after!r}")
    b.js("Tech4Time.theme.toggle(); return true;")
    b.click("[data-rail-toggle]")

    r.section("Back and Forward")
    b.js("window.history.back(); return true;")
    time.sleep(1.6)
    back = b.js(SHELL)
    r.check("Back returns to the screen before", back["search"] == "?s=company",
            f"location.search is {back['search']!r}")
    r.check("and does it without a full load", back["marked"] is True,
            "popstate was answered by the document reloading itself")
    r.check("the rail row follows Back too", back["current"] == "?s=company",
            f"aria-current is on {back['current']!r}")

    b.js("window.history.forward(); return true;")
    time.sleep(1.6)
    forward = b.js(SHELL)
    r.check("Forward goes back to where Back came from",
            forward["search"] == "?s=careers",
            f"location.search is {forward['search']!r}")

    r.section("links that must be left alone")
    b.go(base + "/?s=company")
    b.js(MARK)
    b.click(".outline__link")
    anchored = b.js(SHELL)
    r.check("an in-page anchor is still an in-page anchor",
            anchored["search"] == "?s=company" and anchored["hash"] != "",
            f"search {anchored['search']!r}, hash {anchored['hash']!r} — an "
            f"anchor resolves to a URL with no ?s= at all, so treating it as "
            f"a link between screens lands on the overview")
    r.check("and it did not reload to get there", anchored["marked"] is True)

    r.check("a link to the public site is not swapped in",
            b.js("var a = document.querySelector('.admin-bar__view');"
                 "return !!(a && a.target === '_blank' && "
                 "new URL(a.href).origin !== location.origin);"),
            "it opens in a new tab and points at the other host, so it must "
            "reach the browser untouched")

    r.section("leaving a screen with something unsaved")
    YEAR = 'input[name="milestones[items][0][year]"]'

    b.go(base + "/?s=careers")
    b.click('.rail__item[href="?s=contact"]')
    r.check("an untouched screen does not ask", b.asked() is None,
            "a question nobody needs is a question people learn to click "
            "through, and then it is not a safeguard any more")

    b.go(base + "/?s=company")
    b.type(YEAR, "9")
    b.click('.rail__item[href="?s=contact"]')
    asked = b.asked()
    r.check("a screen with something typed into it asks first",
            asked is not None and "have not saved" in asked,
            f"the dialog said {asked!r} — following a rail item throws the "
            f"form away, and the move is instant now")

    b.answer(False)
    stayed = b.js(SHELL)
    r.check("saying no stays where you were",
            stayed["heading"] == "Company Profile",
            f"the heading reads {stayed['heading']!r}")
    r.check("with what was typed still there",
            (b.js("return document.querySelector('" + YEAR + "').value;") or "")
            .endswith("9"),
            "the answer was taken and the page reloaded anyway")

    b.click('.rail__item[href="?s=contact"]')
    b.answer(True)
    r.check("saying yes goes", b.js(SHELL)["heading"] == "Contact")

    # A row added is in the form and nowhere else until Save is pressed, so it
    # is unsaved work even though nothing was typed.
    b.go(base + "/?s=company")
    b.click('button[name="do"][value="clients-add:0"]')
    b.click('.rail__item[href="?s=careers"]')
    r.check("a row added but not saved counts too", b.asked() is not None,
            "adding a row rewrites the form and not the file, so leaving "
            "loses it exactly as typing does")
    b.answer(True)

    # BACK IS A WAY OUT OF A SCREEN TOO. It is answered by a swap, so no
    # document is unloaded and the browser's own beforeunload prompt could
    # never fire here — this is the only thing standing between the Back
    # button and an hour of typing.
    b.go(base + "/?s=company")
    b.click('.rail__item[href="?s=contact"]')
    b.type('input[name="reach[title]"]', " x")
    # The entry number has to BE a number. It was
    # "[object HTMLDivElement]1" for one run, because apply() has a local
    # `here` and the counter was called `here` too — so no two entries ever
    # compared unequal and Back never asked. Asserted rather than assumed,
    # because that failure is silent in every other way.
    r.check("history entries are numbered",
            isinstance((b.js("return history.state && history.state.i;")), int),
            f"history.state.i is "
            f"{b.js('return JSON.stringify(history.state);')!r}")

    b.js("window.history.back(); return true;")
    time.sleep(1.2)

    asked_back = b.asked()
    r.check("pressing Back with something unsaved asks as well",
            asked_back is not None and "have not saved" in asked_back,
            f"the dialog said {asked_back!r} — clicking a rail item asks and "
            f"Back did not, which is the worse of the two to lose work to")

    b.answer(False)
    held = b.js(SHELL)
    r.check("refusing puts the entry back", held["search"] == "?s=contact",
            f"location.search is {held['search']!r} — the address bar and the "
            f"page have to agree, or a reload lands somewhere else")
    r.check("and the screen is untouched", held["heading"] == "Contact")
    r.check("with the edit still in it",
            (b.js("return document.querySelector('input[name=\"reach[title]\"]').value;")
             or "").endswith(" x"))

    b.js("window.history.back(); return true;")
    time.sleep(1.2)
    b.answer(True)
    went = b.js(SHELL)
    r.check("accepting goes back", went["heading"] == "Company Profile",
            f"the heading reads {went['heading']!r}")

    # A field the model will accept, so that what is proved here is the guard
    # and not the validator. A save that FAILS leaves the form holding
    # something the file does not, so the guard is right to keep asking — and
    # a test that typed "9" onto a year proved that instead, twice, before the
    # message was read.
    b.go(base + "/?s=company")
    b.type('input[name="hero[subtitle]"]', " and one more word")
    b.click('.admin-bar__actions button[name="do"][value="save"]')

    notice = b.js("var n = document.querySelector('.admin__notice');"
                  "return n ? n.textContent.trim() : '';") or ""
    r.check("the save went through", notice.startswith("Saved"),
            f"the page says {notice[:90]!r} — the check below means nothing "
            f"unless this one holds")

    b.click('.rail__item[href="?s=careers"]')
    r.check("and a save stops it asking", b.asked() is None,
            "the file and the form agree now, so there is nothing to lose "
            "and nothing to ask about")

    # THE EXITS NOTHING IN THE PAGE CAN INTERCEPT — a reload, the tab closing,
    # signing out. Asserted by dispatching the event and reading
    # defaultPrevented rather than by provoking the real dialog: what is ours
    # is the decision to interrupt, and the dialog itself belongs to the
    # browser and cannot be driven reliably.
    ASK_ON_UNLOAD = ("var e = new Event('beforeunload', {cancelable: true});"
                     "window.dispatchEvent(e);"
                     "return e.defaultPrevented;")

    b.go(base + "/?s=company")
    r.check("reloading an untouched screen is not interrupted",
            b.js(ASK_ON_UNLOAD) is False,
            "a page that asks on every exit is one people learn to click "
            "through, and then no guard here means anything")

    b.type('input[name="hero[subtitle]"]', " x")
    r.check("reloading one with unsaved work is",
            b.js(ASK_ON_UNLOAD) is True,
            "F5 is the way out no link handler can see — nothing of ours runs "
            "after it")

    b.click('.admin-bar__actions button[name="do"][value="save"]')
    r.check("and saving lifts it again", b.js(ASK_ON_UNLOAD) is False)

    r.section("the account menu")
    b.go(base + "/?s=company")
    b.click(".account__toggle")
    r.check("it opens", b.js(SHELL)["menuOpen"] is True)

    b.click('.account__menu .account__item[href="?s=account"]')
    left = b.js(SHELL)
    r.check("a link inside it moves screens", left["heading"] == "Account",
            f"the heading reads {left['heading']!r}")
    r.check("and the menu does not stay open behind you",
            left["menuOpen"] is False,
            "the rail is not replaced, so an open menu outlives the press "
            "that used it unless it is closed")


def improvements(b: Browser, base: str, r: Results) -> None:
    """The seven things reported after the first asynchronous release.

    Every group here reproduces something that was seen on the live admin and
    not here, and the reason each was invisible is written beside it. That is
    the useful half: a check that only proves today's code works is a check
    that would not have caught the thing it is named after.
    """

    r.section("adding and removing on EVERY editor, not just the biggest")
    # THE GAP THAT LET ALL OF THIS HIDE. The suite drove the company editor
    # and only asserted that the other screens' forms CARRIED data-async — a
    # DOM read that passes whether or not a single line of script is running.
    for screen, band, field in (
        ("/?s=contact", "reach", "reach[items]"),
        ("/?s=contact", "offices", "offices[items]"),
        ("/?s=company", "clients", "clients[items]"),
        ("/?s=about", "story", "story[items]"),
        ("/?s=certifications", "certs", "certs[items]"),
        ("/?s=branding", "assets", "assets[items]"),
        ("/?s=privacy", "sections", "sections"),
        ("/?s=about", "whyus", "whyus[items]"),
        ("/?s=home", "tags", "tags[items]"),
        ("/?s=home", "destinations", "destinations[items]"),
        ("/?s=seo&site=identity", "sameas", "sameas[items]"),
        ("/?s=seo&site=identity", "hours", "hours[items]"),
        ("/?s=services", "ossf", "ossf[items]"),
        ("/?s=services", "nav", "nav[items]"),
        # The nested case, which nothing else in this list covers: the rows
        # inside a row. Its button carries two coordinates rather than one.
        ("/?s=services&service=cybersecurity", "layers",
         "service[layers][items]"),
    ):
        b.go(base + screen)
        b.js(MARK)
        count = f'return document.querySelectorAll(\'input[name^="{field}["]\').length;'
        before = b.js(count)
        b.click(f'#band-{band} .admin__band-add')
        after = b.js(count)

        r.check(f"{screen} {band}: a row is added without navigating",
                after > before and b.js(SHELL)["marked"] is True,
                f"{before} -> {after} fields, document replaced: "
                f"{b.js(SHELL)['marked'] is not True}")

    r.section("a standing warning does not steal the page")
    # THE BUG BEHIND "the async never landed". The contact editor carries a
    # warning whenever the site's footers have drifted from the record, and the
    # swap used to scroll to the first error OR warning it found afterwards —
    # so every press on that screen jumped to the top and looked like a reload.
    # It could not be reproduced here because the development copy is in step.
    import json as _json
    record = _json.loads(CONTACT.read_text())
    record["footer_synced"] = "deliberately-out-of-step"
    CONTACT.write_text(_json.dumps(record, indent=4))

    b.go(base + "/?s=contact")
    r.check("the standing warning is on the page",
            b.js("return document.querySelectorAll('.admin__notice--warn').length;") >= 1,
            "the state this group is about could not be set up, so the check "
            "below proves nothing")

    # A ROW ACTION, NOT A SAVE. Saving also publishes, and publishing from a
    # test has no key and fails — which raises a warning that genuinely IS the
    # answer to what was just pressed, and being taken to that one is correct.
    # Moving a row publishes nothing, so what is left on the page is only the
    # standing advisory, which is the case this group is about. It is also the
    # case that was reported: "add anything, or remove, or anything".
    b.js("window.scrollTo({top: 2500, behavior: 'instant'});")
    time.sleep(0.4)
    was = b.js("return Math.round(window.scrollY);")
    b.click('button[name="do"][value="reach-down:0"]')
    now = b.js("return Math.round(window.scrollY);")

    # NOT "the scroll is identical": a move deliberately follows the row it
    # moved, which shifts the page a few hundred pixels. What must not happen
    # is being taken to the top — so the assertion is that the warning is not
    # what you are looking at, which is the thing that was reported.
    warning = b.js("""
    var w = document.querySelector('.admin__notice--warn');
    return w ? Math.round(w.getBoundingClientRect().bottom) : null;""")

    r.check("a row action does not take you to the standing warning",
            now > 1000 and warning is not None and warning < 0,
            f"was at {was}px, now at {now}px, and the warning's bottom edge is "
            f"at {warning}px — at or below zero means it is above the screen "
            f"and you were left where you were working. It used to scroll to "
            f"it after every single press, which is what made the editor look "
            f"like it was reloading")

    # THE OTHER HALF OF THE SAME CONTRACT. A problem the press actually caused
    # must still take the page to it, or the fix above would have traded one
    # silence for another. The failed publish is that problem here.
    b.js("window.scrollTo({top: 2500, behavior: 'instant'});")
    time.sleep(0.4)
    b.click('.admin-bar__actions button[name="do"][value="save"]')

    r.check("but a problem the press CAUSED still shows itself",
            b.js("return Math.round(window.scrollY);") < 600,
            "a publish that failed is news, and it was not on the page before "
            "the button was pressed")

    CONTACT.write_text(_json.dumps(record, indent=4).replace(
        '"deliberately-out-of-step"', '""'))

    r.section("what the server said, said in the corner")
    b.go(base + "/?s=company")
    b.type('input[name="hero[subtitle]"]', " x")
    b.click('.admin-bar__actions button[name="do"][value="save"]')

    r.check("a confirmation becomes a toast",
            "Saved" in (b.js("var t=document.querySelector('.toast');"
                             "return t ? t.textContent : '';") or ""),
            "the message is still a paragraph at the top of a document that is "
            "several screens long")
    r.check("and it has slid in rather than appeared",
            b.js("var t=document.querySelector('.toast');"
                 "return !!t && t.classList.contains('toast--in');"))
    r.check("the toast is on the right, near the foot",
            b.js("""
            var t = document.querySelector('.toast').getBoundingClientRect();
            return t.right > window.innerWidth * 0.6
                && t.top > window.innerHeight * 0.5;"""),
            "it was asked for on that side of the page, rising from the bottom")
    r.check("and the page is not left holding a copy of it",
            b.js("return document.querySelectorAll("
                 "'#admin-main .admin__notice--ok').length === 0;"))

    r.section("the question box is the page's own")
    # getElementsByName rather than a selector: a field name holding brackets
    # and quotes inside a Python string inside a JS string is three levels of
    # escaping, and it got one of them wrong.
    b.js("var f = document.getElementsByName('hero[title]')[0];"
         "f.value += 'x'; f.dispatchEvent(new Event('input', {bubbles: true}));")
    b.click('.rail__item[href="?s=contact"]')

    r.check("it is a <dialog>, not the browser's box",
            b.js("var d=document.querySelector('dialog.dialog');"
                 "return !!d && d.open === true;"),
            "window.confirm() cannot be styled, never learned the dark theme, "
            "and prints the site's own domain above the question")
    r.check("with a blurred backdrop",
            "blur" in (b.js("var d=document.querySelector('dialog.dialog');"
                            "return getComputedStyle(d, '::backdrop').backdropFilter;") or ""))
    r.check("the safe answer holds the focus",
            b.js("return document.activeElement.getAttribute('data-answer');") == "no",
            "Enter on a dialog nobody read must not throw work away")

    b.click('.dialog__actions button[data-answer="no"]')
    r.check("saying no keeps the screen and clears the box",
            b.js(SHELL)["heading"] == "Company Profile"
            and b.js("return document.querySelectorAll('dialog.dialog').length === 0;"))

    r.section("the outline column")
    b.go(base + "/?s=company")
    top = b.js("return Math.round(window.scrollY);")
    b.click('.outline__link[href="#band-technology"]')
    landed = b.js("""
    var e = document.getElementById('band-technology');
    return {y: Math.round(window.scrollY), top: Math.round(e.getBoundingClientRect().top),
            pad: Math.round(parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop))};""")

    r.check("an anchor actually moves the page",
            landed["y"] > top + 500,
            f"scrollY went {top} -> {landed['y']}. popstate fires for an "
            f"in-page anchor as well as for Back, and answering it with a swap "
            f"re-fetched the screen and landed at the top")
    r.check("and lands the band clear of the bar",
            abs(landed["top"] - landed["pad"]) <= 4,
            f"band top at {landed['top']}px against a {landed['pad']}px bar")

    marked = "var a=document.querySelector('.outline__link[aria-current]');" \
             "return a ? a.textContent.trim() : '(none)';"
    r.check("and the column marks where you are", b.js(marked) == "Technology",
            f"marked {b.js(marked)!r}")

    b.js("window.scrollTo({top: 1500, behavior: 'instant'});")
    time.sleep(0.5)
    r.check("the mark follows the scroll", b.js(marked) == "Milestones",
            f"marked {b.js(marked)!r} at 1500px")
    r.check("exactly one entry is ever marked",
            b.js("return document.querySelectorAll("
                 "'.outline__link[aria-current]').length;") == 1)

    r.section("a form that takes a file says so")
    # A file input in a form that is not multipart posts the FILENAME and not
    # the file. Nothing errors: PHP simply finds nothing in $_FILES, and the
    # editor reports a save that worked while the picture never left the
    # machine. The contact editor was in exactly that state.
    # Every screen with a file input, not a sample of them: about, home and
    # branding each carry one and were missing from this list, which is the
    # same way the contact editor came to be in the state described above.
    for screen in ("/?s=contact", "/?s=company", "/?s=account", "/?s=careers",
                   "/?s=about", "/?s=home", "/?s=branding",
                   "/?s=seo&site=identity"):
        # The privacy editor is deliberately absent: it has no file input at
        # all, so it would pass this vacuously and read as though it did. So
        # are the SEO editor's other screens, for the same reason -- only the
        # identity screen takes a file, and it takes two.
        b.go(base + screen)
        bad = b.js("""
        var out = [];
        Array.prototype.forEach.call(document.querySelectorAll('form'), function (f) {
          if (f.querySelector('input[type=file]') &&
              (f.getAttribute('enctype') || '') !== 'multipart/form-data') {
            out.push(f.id || f.className || '(a form)');
          }
        });
        return out;""")
        r.check(f"{screen}: every form holding a file input is multipart",
                bad == [], f"{bad} — a file input here posts its filename only")

    r.section("no page editor still offers a field it no longer writes")
    # Every page's title, description, share title and breadcrumb are edited on
    # ?s=seo now. The values still LIVE in each page's own document and are
    # still published with it — only the editing moved. So the fault to look
    # for is a field left behind: an input a person can type into that nothing
    # reads any more looks exactly like one that works.
    #
    # That the values SURVIVE a save is asserted in tools/test_seo_admin.py,
    # which can read the documents; this is the half a browser can see.
    for screen in ("/?s=home", "/?s=about", "/?s=services",
                   "/?s=services&service=cybersecurity", "/?s=company",
                   "/?s=contact", "/?s=certifications", "/?s=branding",
                   "/?s=privacy"):
        b.go(base + screen)
        left = b.js("""
        return Array.prototype.map.call(
            document.querySelectorAll('[name^="meta["], [name*="[meta]["]'),
            function (el) { return el.name; });""")
        r.check(f"{screen}: no meta field is left on the form", left == [],
                f"{left} — these post over the SEO screen's values or, worse, "
                f"look editable and change nothing")
        r.check(f"{screen}: and the band points at the screen that owns them",
                b.js("return !!document.querySelector("
                     "'#band-meta a[href*=\"s=seo\"]');") is True,
                "the outline still lists band-meta, so it has to lead somewhere")

    r.section("things are where they are supposed to be")
    # MEASURED, NOT EYEBALLED. Both of these shipped and were reported from
    # screenshots, and neither is something a check about focus rings, contrast
    # or reflow would ever notice — the page was perfectly accessible with its
    # headings sitting outside their cards.
    for screen in ("/?s=contact", "/?s=company", "/?s=careers", "/?s=account"):
        b.go(base + screen)
        strays = b.js(r"""
        var out = [];
        document.querySelectorAll('.admin__block').forEach(function (block) {
          /* Either shape: a <legend> in the editors, an <h2> on the account
             page. Only a legend can escape its box, but measuring both means
             the check does not have to know which screen it is on. */
          var title = block.querySelector(
            ':scope > legend, :scope > h2, :scope > .admin__section-title');
          if (!title) { return; }
          var box = block.getBoundingClientRect();
          var head = title.getBoundingClientRect();
          var pad = parseFloat(getComputedStyle(block).paddingTop);
          if (head.top < box.top + pad - 2 ||
              head.left < box.left - 2 || head.right > box.right + 2) {
            out.push(title.textContent.replace(/\s+/g, ' ').trim().slice(0, 24)
                     + ' sits ' + Math.round(head.top - box.top)
                     + 'px into a ' + Math.round(pad) + 'px pad');
          }
        });
        return out;""")

        r.check(f"{screen}: every band heading is inside its card",
                strays == [],
                f"{strays} — a <legend> is laid out ON its fieldset's top "
                f"border unless something puts it back in flow, so a heading "
                f"at 0px into the padding is a heading hanging out of the box")

    b.go(base + "/?s=contact")
    b.type('input[name="hero[title]"]', "x")
    b.click('.rail__item[href="?s=company"]')

    where = b.js("""
    var d = document.querySelector('dialog.dialog');
    var r = d.getBoundingClientRect();
    var pair = document.querySelectorAll('.dialog__actions .btn');
    var a = pair[0].getBoundingClientRect(), c = pair[1].getBoundingClientRect();
    return {x: Math.round((r.left + r.right) / 2 - window.innerWidth / 2),
            y: Math.round((r.top + r.bottom) / 2 - window.innerHeight / 2),
            oneLine: Math.abs((a.top + a.bottom) / 2 - (c.top + c.bottom) / 2) < 6};""")

    r.check("the question box is in the middle of the screen",
            abs(where["x"]) <= 20 and abs(where["y"]) <= 20,
            f"it is {where['x']}px and {where['y']}px off centre. A modal "
            f"<dialog> is centred by margin:auto from the browser's own "
            f"stylesheet, and base.css's `* {{ margin: 0 }}` takes that away")
    r.check("and both answers sit on one line", where["oneLine"] is True,
            "wrapped into a stack, which reads as two unrelated buttons "
            "rather than one choice")
    b.click('.dialog__actions button[data-answer="no"]')

    r.section("switching mode does not animate the whole document")
    b.go(base + "/?s=company")
    cost = b.js("""
    var n = 0;
    document.querySelectorAll('*').forEach(function (e) {
      var t = getComputedStyle(e).transitionProperty;
      if (t && t.indexOf('background-color') !== -1) { n += 1; }
    });
    var t0 = performance.now();
    Tech4Time.theme.toggle();
    document.documentElement.getBoundingClientRect();
    return {matched: n, ms: Math.round(performance.now() - t0),
            total: document.querySelectorAll('*').length};""")

    r.check("the mode change is not a per-element animation",
            cost["matched"] < cost["total"] / 4,
            f"{cost['matched']} of {cost['total']} elements carry a "
            f"background-color transition. It was every one of them, five "
            f"properties each, and that is what made switching mode crawl")
    r.check("and it does not block", cost["ms"] <= 30,
            f"{cost['ms']}ms of blocked scripting to flip the mode")
    b.js("Tech4Time.theme.toggle(); return true;")


def run_without_script(b: Browser, base: str, r: Results) -> None:
    """The same edits, with JavaScript switched off in the browser."""
    r.section("with JavaScript off")

    b.go(base + "/?s=company")
    r.check("the editor renders at all",
            _count(b, 'input[name^="clients[items]["]') > 0,
            "the page did not render, so nothing below means anything")

    rows = _count(b, 'input[name^="clients[items]["][name$="[name]"]')
    _submit_without_script(b, "clients-add:0")
    r.check("adding a row still works",
            _count(b, 'input[name^="clients[items]["][name$="[name]"]') == rows + 1,
            "the form did not post, so the fallback is gone")

    _submit_without_script(b, f"clients-remove:{rows}")
    r.check("removing a row still works",
            _count(b, 'input[name^="clients[items]["][name$="[name]"]') == rows)

    r.check("the account menu still opens",
            _count(b, "details.account > summary") == 1,
            "there is nothing to press, and no way to sign out")

    r.section("moving between screens with JavaScript off")

    b.click('.rail__item[href="?s=contact"]')
    r.check("a rail item is still a link",
            b.text(".admin-bar__title") == "Contact",
            f"the heading reads {b.text('.admin-bar__title')!r} — with no "
            f"script this is an ordinary navigation, and it has to be")

    b.click('.rail__item[href="?s=overview"]')
    r.check("and so is the way back",
            b.text(".admin-bar__title") == "Overview")

    # THE FLASH, PROVED BY THE ONE BROWSER THAT CANNOT CAUSE IT.
    #
    # The rail's width used to be a localStorage value read by a deferred
    # script, so the browser painted a wide rail and then snapped it shut. With
    # JavaScript off that same rail could only ever be wide — there was nothing
    # to read the value. It is a cookie now, so the server decides the width
    # before it sends the rail, and this browser gets it right with no script
    # at all. If these two measurements are equal, the decision has moved back
    # into the browser and the flash is back with it.
    wide = b.rect(".rail")["width"]

    b.cookie("t4t_rail", "narrow")
    b.go(base + "/?s=overview")
    narrow = b.rect(".rail")["width"]

    r.check("the server draws the rail narrow, with no script to do it",
            narrow < wide / 2,
            f"{wide:.0f}px by default, {narrow:.0f}px with t4t_rail=narrow — "
            f"equal widths mean the server ignored the cookie and the browser "
            f"is deciding again, one frame after the paint")

    b.cookie("t4t_rail", "wide")


def _count(b: Browser, css: str) -> int:
    found = rq("POST", b.s + "/elements", {"using": "css selector", "value": css})
    return len(found["value"])


def _submit_without_script(b: Browser, value: str) -> None:
    """Click a submit button through the driver. With script off this is an
    ordinary navigation, which is exactly the point."""
    b.click(f'button[name="do"][value="{value}"]')


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        print("This test needs Firefox and geckodriver as well as the PHP CLI.")
        return

    backup = DATA.read_bytes()
    contact_backup = CONTACT.read_bytes()
    web_port, drv_port = free_port(), free_port()
    work = Path(tempfile.mkdtemp(prefix="t4t-admin-forms-"))
    private = work / "private"

    php = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(DOCROOT), str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(private)))
    drv = subprocess.Popen(
        ["geckodriver", "--port", str(drv_port)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)

    if not (wait_for(web_port) and wait_for(drv_port)):
        raise SystemExit("php or geckodriver did not start")

    base = f"http://127.0.0.1:{web_port}"
    print(f"firefox (headless) against {base}")

    r = Results()
    scripted = plain = None
    try:
        secret = admin_session.make_account(private)

        scripted = Browser(drv_port, script=True)
        used = scripted.sign_in(web_port, secret)
        agent = scripted.js("return navigator.userAgent;")
        run(scripted, base, r)
        navigate(scripted, base, r)
        improvements(scripted, base, r)
        scripted.quit()
        scripted = None

        # THE SESSION IS BOUND TO THE USER-AGENT (auth_fingerprint), so the
        # cookie made over HTTP only works in the browser if the two present
        # the same one. Both Firefox sessions send the same string, so it is
        # read from the one that can still run script and handed to urllib.
        jar = CookieJar()
        opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(jar), NoRedirect())
        opener.addheaders = [("User-Agent", agent)]
        fresh_code(secret, used)
        admin_session.sign_in(opener, base, secret)

        plain = Browser(drv_port, script=False)
        plain.adopt_session(base, list(jar))
        run_without_script(plain, base, r)
    finally:
        for b in (scripted, plain):
            if b:
                b.quit()
        for proc in (drv, php):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass
        shutil.rmtree(work, ignore_errors=True)
        DATA.write_bytes(backup)
        CONTACT.write_bytes(contact_backup)
        for stray in (DATA.with_suffix(".json.bak"),):
            stray.unlink(missing_ok=True)
        print(f"\n{DATA.relative_to(ROOT)} restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    print(f"\n{total}/{total} checks passed")


if __name__ == "__main__":
    main()
