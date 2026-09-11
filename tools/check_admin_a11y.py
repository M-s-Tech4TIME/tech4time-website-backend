#!/usr/bin/env python3
"""
Crawl the SIGNED-IN admin for the accessibility faults a static check cannot see.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_admin_a11y.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing, the way the other browser tools here do.

WHY THIS EXISTS
`check_focus.py`, `check_dark_mode.py`, `check_responsive.py` and
`check_hover.py` crawl a list of PUBLIC pages and never sign in. They went to
tech4time-website-frontend at the split, with the pages they were written for,
and testing.md has said ever since -- in as many words -- that the admin "has
never been checked for focus visibility, tap targets at 320px, or how it paints
in dark mode".

That was true, and it is the gap this closes. Everything here happens behind
the sign-in, which is the half nobody has ever looked at.

WHY ONE FILE AND NOT FOUR
Over there, four files is right: sixteen public pages, and each tool carries a
lot of crawl machinery for its own question. Here it is a dozen screens, which
SIGNED_IN_SCREENS lists. Four copies of "start PHP, start geckodriver, sign in,
walk the screens" would be four copies of the sign-in -- and the sign-in is the
part most likely to need changing, because it is the part that depends on the
login page's markup.

So: one crawl, four families of assertion. The families are kept visibly
separate below, and each names the success criterion it is about, so a failure
says what is wrong rather than merely that something is.

WHAT IT ASSERTS

  focus       Tab through every screen. The focused element must have a
              visible indicator (:focus-visible plus an outline or a shadow --
              WCAG 2.2 SC 2.4.7) and must not be entirely hidden behind
              something else (SC 2.4.11 Focus Not Obscured, Minimum).

  reflow      At 320px the document must not scroll sideways (SC 1.4.10), and
              every interactive control must be at least 24x24 CSS pixels
              (SC 2.5.8 Target Size, Minimum). The inline-link exception is
              honoured: a link inside a sentence is exempt, and the check knows
              the difference by asking whether the anchor's parent holds text
              either side of it.

  dark        In dark mode the page must actually be dark -- body background
              resolved and opaque, not inherited from whatever is behind it --
              and no element may end up drawing its text in the colour it is
              sitting on.

  hover       Every kind of interactive control must react to a pointer.
              Sampled by KIND rather than per element, because forty identical
              rows react identically and forty findings about them would bury
              the one that matters.

WHY REDUCED MOTION IS REQUESTED
The same reason check_focus.py gives, and it is worth repeating because it is
the thing that would make this file lie rather than fail: the admin scrolls a
focused element into view, and measuring mid-scroll reports positions the page
is still travelling through. Reduced motion makes scrolling instant. It is
proved at startup rather than assumed -- if it silently failed, the findings
would look real.

WHAT IT DELIBERATELY DOES NOT DO
Contrast ratios. `check_contrast.py` already reads every pair in theme.css and
is exact about it; sampling rendered pixels here would be a worse measurement
of the same thing. This asks the questions that only a laid-out page can
answer.
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

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"

sys.path.insert(0, str(ROOT / "tools"))
import admin_session  # noqa: E402

# 1200 is the admin as it is used. 320 is the reflow bar -- the narrowest width
# WCAG asks about, and the one the rail and the editor rows have never faced.
WIDTHS = [(1200, "desktop"), (320, "narrow")]

MAX_TABS = 60

# Signed out first: these are reachable by anyone who finds the URL, and
# reset.php is three screens that were rebuilt recently.
PUBLIC_SCREENS = ["/login.php", "/forgot.php", "/reset.php"]

SIGNED_IN_SCREENS = ["/", "/?s=home", "/?s=careers", "/?s=contact", "/?s=company",
                     "/?s=about", "/?s=services", "/?s=certifications",
                     "/?s=branding", "/?s=privacy",
                     # Both services screens, because they are different pages
                     # rather than the same one with a filter: one lists the
                     # services and the other edits one, and only the second
                     # has the deeply nested cards.
                     "/?s=services&service=cybersecurity",
                     # All four shapes the SEO editor takes. The index is a
                     # list of links with no form at all; a page screen and the
                     # 404's are different forms; and the identity screen is
                     # the largest on this site, with two repeatable lists and
                     # two file inputs.
                     "/?s=seo", "/?s=seo&page=about", "/?s=seo&page=notfound",
                     "/?s=seo&site=identity", "/?s=seo&site=crawl",
                     # All four shapes the Header & Footer editor takes, for
                     # the same reason. The index is cards and links; the
                     # footer screen is the longest form on this site after
                     # the SEO identity one, and the only one that draws a
                     # standing notice with a list inside it.
                     "/?s=chrome", "/?s=chrome&part=header",
                     "/?s=chrome&part=footer", "/?s=chrome&part=dock",
                     # All five shapes the Settings editor takes. The colour
                     # screen is the reason all five are listed rather than a
                     # sample: twenty-eight colour inputs is more controls of
                     # one kind than anything else in this panel, each needing
                     # its own name, and a native colour picker is one of the
                     # few inputs whose focus ring the browser draws itself.
                     "/?s=settings", "/?s=settings&part=logo",
                     "/?s=settings&part=icon", "/?s=settings&part=colour",
                     "/?s=settings&part=mail",
                     "/?s=account"]

MIN_TARGET = 24          # SC 2.5.8, CSS pixels


# --------------------------------------------------------------- the probes

FOCUS = r"""
var el = document.activeElement;
if (!el || el === document.body || el === document.documentElement)
  return {done: true};

function name(e) {
  var cls = (e.className && e.className.baseVal !== undefined
               ? e.className.baseVal : e.className || '').toString().trim();
  return e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : '');
}

var cs = getComputedStyle(el);
var r  = el.getBoundingClientRect();

/* Is any of it actually reachable by a click? The centre and four inset
   corners. If none of them hit the element or something inside it, the focus
   ring is behind another layer and SC 2.4.11 is not met. */
var pts = [[r.left + r.width / 2, r.top + r.height / 2],
           [r.left + 2, r.top + 2], [r.right - 2, r.top + 2],
           [r.left + 2, r.bottom - 2], [r.right - 2, r.bottom - 2]];

var hits = 0, sampled = 0, coveredBy = '';
for (var i = 0; i < pts.length; i++) {
  var x = pts[i][0], y = pts[i][1];
  if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) continue;
  sampled++;
  var top = document.elementFromPoint(x, y);
  if (top && (top === el || el.contains(top) || top.contains(el))) hits++;
  else if (top && !coveredBy) coveredBy = name(top);
}

var visible = true;
try { visible = el.matches(':focus-visible'); } catch (e) { visible = null; }

function drawn(style) {
  /* outlineWidth is a length even when outlineStyle is none, and the shorthand
     `outline: var(--focus-ring)` -- where the token is a COLOUR -- computes to
     a colour and a width with style none. That combination draws nothing and
     reads, in a dump, exactly like a ring. Style is the part that decides. */
  return (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0)
      || (style.boxShadow && style.boxShadow !== 'none');
}

/* The ring does not have to be on the focused element. .rte__surface takes
   focus and says `outline: none` on purpose, because the ring belongs on the
   wrapper -- otherwise the toolbar sits outside the highlighted area. That is
   the right call and this asked the wrong question about it on its first run.
   An ancestor's ring counts, PROVIDED the ancestor is drawing it because of
   this element: :focus-within is what makes that true rather than assumed. */
var ring = drawn(cs), ringOn = ring ? 'itself' : '';
if (!ring) {
  var up = el.parentElement;
  for (var d = 0; d < 4 && up; d++, up = up.parentElement) {
    var ups = getComputedStyle(up);
    var within = false;
    try { within = up.matches(':focus-within'); } catch (e) { within = false; }
    if (within && drawn(ups)) { ring = true; ringOn = name(up); break; }
  }
}

return {
  name: name(el),
  text: (el.textContent || el.value || '').trim().slice(0, 34),
  top: Math.round(r.top), height: Math.round(r.height),
  sampled: sampled, hits: hits, coveredBy: coveredBy,
  focusVisible: visible, ring: !!ring, ringOn: ringOn, outline: cs.outline
};
"""

# Runs in the OUTER window. Puts a screen into a frame of the requested width
# and returns {"loading": true} until it is ready, so the caller polls rather
# than sleeping a guessed amount. The measuring scripts below are then run
# INSIDE that frame.
#
# WHY A FRAME AND NOT THE WINDOW — this is the whole reason the reflow walk is
# shaped the way it is, and it is ADR 0015 arriving in this repository.
#
# Firefox will not make a window narrower than about 500px, headless included;
# measured on this machine, asking for 320, 360 and 400 all returned 500. Ask
# WebDriver for 320 and the call succeeds, returns no error, and you measure
# 500. That is worse than having no check: it leaves a record saying the
# narrowest phones were covered by a run that never went near them. This check
# did exactly that until it was noticed — and then began FAILING at 500,
# reporting a width it had not tested either way.
#
# A frame establishes its own viewport, so 320 means 320. It works here because
# the admin's frame-ancestors comes from public/.htaccess, which the PHP dev
# server does not read; the real header is asserted where it belongs, by
# check_secrets.py and by verify_live.py against the live host.
FRAME = r"""
var width = arguments[0], url = arguments[1];

var frame = document.getElementById('probe');
if (!frame) {
  frame = document.createElement('iframe');
  frame.id = 'probe';
  frame.style.border = '0';
  frame.style.height = '900px';
  document.body.innerHTML = '';
  document.body.style.margin = '0';
  document.body.appendChild(frame);
}
frame.style.width = width + 'px';

var want = url + '#' + width;
if (frame.getAttribute('data-showing') !== want) {
  frame.setAttribute('data-showing', want);
  frame.src = url;
  return {loading: true};
}

var doc = frame.contentDocument;
if (!doc || doc.readyState !== 'complete' || !doc.body) return {loading: true};

return {loading: false, inner: doc.documentElement.clientWidth};
"""

# Run one of the measuring scripts below inside the frame instead of the page.
#
# window.document, not document: `var document` below is hoisted to the top of
# the function WebDriver wraps this in, so a bare `document` on this line is
# the undefined shadow rather than the outer one. That cost a 500 from
# geckodriver and no explanation whatsoever.
IN_FRAME = r"""
var frame = window.document.getElementById('probe');
var view = frame.contentWindow;
var document = frame.contentDocument;
var innerWidth = document.documentElement.clientWidth;
var getComputedStyle = view.getComputedStyle.bind(view);
"""

REFLOW = r"""
var doc = document.documentElement;
var over = [];
var all = doc.querySelectorAll('*');
for (var i = 0; i < all.length; i++) {
  var e = all[i], r = e.getBoundingClientRect();
  if (r.width === 0 || r.height === 0) continue;
  var cs = getComputedStyle(e);
  if (cs.visibility === 'hidden' || cs.display === 'none') continue;
  /* Something inside its own scroller is fine -- that is what an
     overflow-x: auto box is for. Only report what pushes the DOCUMENT wide. */
  var p = e.parentElement, scrolled = false;
  while (p) {
    var pcs = getComputedStyle(p);
    if (pcs.overflowX === 'auto' || pcs.overflowX === 'scroll') { scrolled = true; break; }
    p = p.parentElement;
  }
  if (scrolled) continue;
  if (r.right > innerWidth + 1) {
    var cls = (e.className && e.className.baseVal !== undefined
                 ? e.className.baseVal : e.className || '').toString().trim();
    over.push(e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : '')
              + ' to ' + Math.round(r.right));
  }
}
return {
  scrollWidth: doc.scrollWidth,
  inner: innerWidth,
  overflowing: over.slice(0, 6)
};
"""

TARGETS = r"""
var small = [];
var sel = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';
var all = document.querySelectorAll(sel);

for (var i = 0; i < all.length; i++) {
  var e = all[i];
  var cs = getComputedStyle(e);
  if (cs.display === 'none' || cs.visibility === 'hidden') continue;
  if (e.type === 'hidden') continue;
  var r = e.getBoundingClientRect();
  if (r.width === 0 || r.height === 0) continue;

  /* A visually-hidden control is not a target -- it is the no-JavaScript
     fallback sitting behind an enhanced one, clipped to a pixel on purpose.
     SC 2.5.8 is about things a finger has to hit; this is not one. Reported
     as a 1x1 "Save" button on the first run, which was the check being wrong
     rather than the admin. */
  if (cs.clipPath && cs.clipPath !== 'none' && r.width <= 2 && r.height <= 2) continue;
  if (/(^|\s)(visually-hidden|sr-only)(\s|$)/.test(
        (e.className && e.className.baseVal !== undefined
           ? e.className.baseVal : e.className || '').toString())) continue;

  /* SC 2.5.8 exempts a link sitting in a run of text: making it 24px tall
     would mean spacing out the sentence around it. The test for "in a
     sentence" is whether the parent holds text on either side. */
  if (e.tagName === 'A') {
    var p = e.parentElement;
    if (p) {
      var t = p.textContent.trim();
      var own = (e.textContent || '').trim();
      if (t.length > own.length + 3) continue;
    }
  }

  if (r.width < MIN || r.height < MIN) {
    var cls = (e.className && e.className.baseVal !== undefined
                 ? e.className.baseVal : e.className || '').toString().trim();
    small.push(e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : '')
               + ' ' + Math.round(r.width) + 'x' + Math.round(r.height)
               + ' "' + (e.textContent || e.value || e.getAttribute('aria-label') || '').trim().slice(0, 20) + '"');
  }
}
return small.slice(0, 8);
""".replace("MIN", str(MIN_TARGET))

DARK = r"""
function rgb(s) {
  var m = /rgba?\(([^)]+)\)/.exec(s || '');
  if (!m) return null;
  var p = m[1].split(',').map(parseFloat);
  return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1};
}

/* What is actually behind this element, walking up until something paints. */
function behind(e) {
  var n = e;
  while (n && n !== document.documentElement) {
    var c = rgb(getComputedStyle(n).backgroundColor);
    if (c && c.a > 0.9) return c;
    n = n.parentElement;
  }
  var c2 = rgb(getComputedStyle(document.documentElement).backgroundColor);
  return c2 && c2.a > 0.9 ? c2 : null;
}

var body = rgb(getComputedStyle(document.body).backgroundColor);
var invisible = [];

var all = document.querySelectorAll('p, span, a, h1, h2, h3, h4, label, li, td, th, button, div');
for (var i = 0; i < all.length && invisible.length < 6; i++) {
  var e = all[i];
  var own = (e.childNodes.length && [].filter.call(e.childNodes,
      function (n) { return n.nodeType === 3 && n.textContent.trim(); }).length);
  if (!own) continue;
  var cs = getComputedStyle(e);
  if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') continue;
  var r = e.getBoundingClientRect();
  if (r.width === 0 || r.height === 0) continue;

  var fg = rgb(cs.color), bg = behind(e);
  if (!fg || !bg) continue;
  if (fg.a < 0.1) continue;
  if (fg.r === bg.r && fg.g === bg.g && fg.b === bg.b) {
    var cls = (e.className && e.className.baseVal !== undefined
                 ? e.className.baseVal : e.className || '').toString().trim();
    invisible.push(e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : '')
                   + ' "' + e.textContent.trim().slice(0, 24) + '"');
  }
}

return {
  theme: document.documentElement.getAttribute('data-theme'),
  bodyBg: getComputedStyle(document.body).backgroundColor,
  bodyOpaque: !!(body && body.a > 0.9),
  invisible: invisible
};
"""

# One representative of each KIND, and what counts as reacting.
HOVER_KINDS = r"""
var kinds = {};
var sel = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';
var all = document.querySelectorAll(sel);
for (var i = 0; i < all.length; i++) {
  var e = all[i];
  var cs = getComputedStyle(e);
  if (cs.display === 'none' || cs.visibility === 'hidden') continue;
  var r = e.getBoundingClientRect();
  if (r.width < 4 || r.height < 4) continue;
  if (r.top < 0 || r.bottom > innerHeight || r.left < 0 || r.right > innerWidth) continue;
  var cls = (e.className && e.className.baseVal !== undefined
               ? e.className.baseVal : e.className || '').toString().trim();
  var kind = e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : '');
  /* Up to three of each kind, not one. The first .rail__item on the overview
     is the CURRENT section, which already carries the elevated background its
     own hover rule would apply -- so hovering it changes nothing and the rail
     was reported dead when it is not. One sample of a kind is one sample of
     whichever state happened to come first. */
  if (!kinds[kind]) kinds[kind] = [];
  if (kinds[kind].length >= 3) continue;
  kinds[kind].push({x: Math.round(r.left + r.width / 2),
                    y: Math.round(r.top + r.height / 2)});
}
return kinds;
"""

# elementFromPoint returns the INNERMOST element under the pointer, which for a
# rail item is the <span> holding the label. If the hover style lives on the
# anchor -- and it usually does, because that is what the pointer is over -- the
# span's computed style does not move and the control is reported as dead.
#
# The first run said .rail__item and .admin__input do not react. The rail does;
# this was reading the wrong node. So: read the element AND its ancestors, four
# deep, and treat any change in the chain as the control reacting.
STYLE_AT = r"""
var e = document.elementFromPoint(arguments[0], arguments[1]);
if (!e) return null;
var out = [];
for (var i = 0; i < 4 && e; i++, e = e.parentElement) {
  var cs = getComputedStyle(e);
  out.push([cs.backgroundColor, cs.color, cs.borderColor, cs.transform,
            cs.boxShadow, cs.opacity, cs.textDecorationLine,
            cs.filter, cs.outlineColor].join(','));
}
return out.join('|');
"""

REDUCED = r"""
return {media: matchMedia('(prefers-reduced-motion: reduce)').matches,
        scroll: getComputedStyle(document.documentElement).scrollBehavior};
"""


# ------------------------------------------------------------- the plumbing


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []

    def section(self, title):
        print(f"\n{title}")

    def check(self, name, ok, detail=""):
        if ok:
            self.passed += 1
        else:
            self.failed.append(name)
            print(f"  FAIL  {name}")
            if detail:
                print(f"        {detail}")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def rq(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def wait_for(port, tries=120) -> bool:
    for _ in range(tries):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return True
        except OSError:
            time.sleep(0.1)
    return False


class Browser:
    def __init__(self, drv_port):
        self.base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", self.base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {
                "args": ["-headless"],
                "prefs": {"ui.prefersReducedMotion": 1},
            }}}})
        self.s = f"{self.base}/session/{r['value']['sessionId']}"

    def size(self, w, h=900):
        rq("POST", self.s + "/window/rect", {"width": w, "height": h, "x": 0, "y": 0})
        time.sleep(0.3)

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(0.9)

    def js(self, script, *args):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": list(args)})["value"]

    def tab(self):
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "key", "id": "kb",
            "actions": [{"type": "keyDown", "value": ""},
                        {"type": "keyUp", "value": ""}]}]})

    def point(self, x, y):
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "pointer", "id": "mouse", "parameters": {"pointerType": "mouse"},
            "actions": [{"type": "pointerMove", "duration": 0,
                         "origin": "viewport", "x": x, "y": y}]}]})
        time.sleep(0.35)

    def sign_in(self, base, secret):
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

    def set_theme(self, theme):
        self.js("try { window.localStorage.setItem('tech4time-theme', arguments[0]); }"
                "catch (e) {}", theme)

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


# -------------------------------------------------------------- the crawl


def prove_reduced_motion(b: Browser, base: str) -> None:
    b.go(base + "/login.php")
    state = b.js(REDUCED)
    if not state["media"]:
        raise SystemExit(
            "Reduced motion did not take effect. Every focus position measured "
            "here would then be read mid-scroll, and elements would be reported "
            "as covered because the page had not finished moving. Refusing to "
            "run -- see the note at the top of this file."
        )
    print("reduced motion is on; scrolling is instant")


def walk_focus(b: Browser, base: str, screens, label, r: Results) -> None:
    for screen in screens:
        b.go(base + screen)
        b.js(OPEN_DISCLOSURES)
        stops = 0
        for _ in range(MAX_TABS):
            b.tab()
            seen = b.js(FOCUS)
            if not seen or seen.get("done"):
                break
            stops += 1
            where = f"{seen['name']} {seen['text']!r} at y={seen['top']}"

            if seen["sampled"]:
                r.check(
                    f"{label} {screen} stop {stops}: focus is not hidden",
                    seen["hits"] > 0,
                    f"{where} — entirely covered by "
                    f"{seen['coveredBy'] or 'something'} (SC 2.4.11)")

            r.check(
                f"{label} {screen} stop {stops}: focus can be seen",
                seen["focusVisible"] is not False and seen["ring"],
                f"{where} — :focus-visible={seen['focusVisible']}, "
                f"outline={seen['outline']!r} (SC 2.4.7)")

        ceiling = "  (hit the ceiling — raise MAX_TABS)" if stops >= MAX_TABS else ""
        print(f"  {stops:>3} focus stops   {label} {screen}{ceiling}")


REFLOW_WIDTH = 320


def walk_reflow(b: Browser, base: str, screens, r: Results) -> None:
    """SC 1.4.10, at the width the criterion is actually defined at.

    Every assertion here names the width it MEASURED, and the first thing
    checked is that the measurement is the width that was asked for. See the
    note above FRAME: this check spent its whole first life reporting 320 and
    measuring 500.
    """
    b.size(1200, 950)
    b.go(base + "/")

    for screen in screens:
        for _ in range(60):
            state = b.js(FRAME, REFLOW_WIDTH, base + screen)
            if not state.get("loading"):
                break
            time.sleep(0.25)
        else:
            r.check(f"{REFLOW_WIDTH}px {screen}: loads in a frame", False,
                    "the frame never finished loading")
            continue

        # The frame carries a scrollbar, so a few pixels under is expected and
        # is what the frontend's check_responsive.py allows too. WIDER than
        # asked for is the clamp, and it is the thing this line exists to
        # catch: it invalidates every assertion below it, so nothing below it
        # runs. ADR 0015.
        got = int(state["inner"])
        slack = REFLOW_WIDTH - got
        if not 0 <= slack <= 40:
            r.check(f"{REFLOW_WIDTH}px {screen}: the viewport is the width asked for",
                    False,
                    f"asked for {REFLOW_WIDTH}, measured {got} — the frame is "
                    f"being clamped, so nothing checked at this width can be "
                    f"believed")
            continue

        flow = b.js(IN_FRAME + REFLOW)
        r.check(f"{REFLOW_WIDTH}px {screen}: the page does not scroll sideways",
                flow["scrollWidth"] <= flow["inner"] + 1,
                f"scrollWidth {flow['scrollWidth']} > viewport {flow['inner']}; "
                f"widened by {', '.join(flow['overflowing']) or 'something'} "
                f"(SC 1.4.10)")

        small = b.js(IN_FRAME + TARGETS)
        r.check(f"{REFLOW_WIDTH}px {screen}: every control is at least "
                f"{MIN_TARGET}x{MIN_TARGET}",
                not small,
                "; ".join(small) + "  (SC 2.5.8)")

        print(f"  {'ok  ' if not small else 'FAIL'}  {REFLOW_WIDTH}px {screen}"
              f"  (measured {got}px)")


# --------------------------------------------------------------- spacing

MIN_FIELD_GAP = 6        # between a label, its control, and its hint
MIN_STACK_GAP = 12       # between two things stacked in a band or a card

SPACING = r"""
/* The vertical rhythm of the forms, measured rather than eyeballed.

   Four pixels between a bold label and the box it names reads as one
   undifferentiated stripe -- label, control and hint all touching equally --
   and a band whose last control is a single full-width field ran into the
   first row card under it at exactly 0px. Both were reported, from
   screenshots, by somebody using it. Neither is visible in a diff and neither
   is something a stylesheet can be asked about: the gaps come from four
   different rules interacting, so the only honest way to know is to measure
   the boxes.

   FLOATS ARE SKIPPED. A <legend> is floated -- see .admin__block >
   .admin__section-title -- so the element after it overlaps it by design and
   measures as a large negative gap. That is not a spacing fault; it is the
   only way to stop a legend cutting a notch in its own fieldset's border. */
function boxes(parent) {
  return Array.prototype.filter.call(parent.children, function (e) {
    var r = e.getBoundingClientRect();
    if (r.height === 0 || r.width === 0) return false;
    var cs = getComputedStyle(e);
    return cs.display !== 'none' && cs.float === 'none' && cs.position === 'static';
  });
}

/* ONLY THINGS THAT ARE ACTUALLY STACKED. .admin__grid puts two fields side by
   side, and DOM order says nothing about which of those is "above" the other:
   measuring top-minus-bottom across a grid ROW gives a large negative number
   and reads as a catastrophic overlap. The first run of this check reported
   -102px on the company editor and -73px on contact, both of them two fields
   sitting happily beside each other.

   So a pair is only compared when their horizontal ranges overlap -- same
   column -- and the second one starts at or below the first. Everything else
   is a row, and a row's spacing is the grid's column-gap, not this. */
function tight(parent, floor, out, where) {
  var kids = boxes(parent);
  for (var i = 0; i < kids.length - 1; i++) {
    var a = kids[i].getBoundingClientRect();
    var b = kids[i + 1].getBoundingClientRect();

    var sameColumn = Math.min(a.right, b.right) - Math.max(a.left, b.left) > 0;
    if (!sameColumn || b.top < a.bottom) continue;

    var gap = Math.round(b.top - a.bottom);
    if (gap < floor) {
      out.push(where + ': ' + (kids[i].className || kids[i].tagName) + ' -> '
             + (kids[i + 1].className || kids[i + 1].tagName)
             + ' = ' + gap + 'px (needs ' + floor + ')');
    }
  }
}

var found = [];
document.querySelectorAll('.admin__field').forEach(function (f) {
  tight(f, FIELD, found, 'inside a field');
});
document.querySelectorAll('.admin__block, .admin-card, .admin__grid').forEach(function (b) {
  tight(b, STACK, found, 'stacked');
});

/* One of each shape is enough to act on; a hundred lines of the same fault is
   not more information. */
var seen = {}, unique = [];
found.forEach(function (line) {
  var shape = line.replace(/= -?\d+px/, '');
  if (!seen[shape]) { seen[shape] = true; unique.push(line); }
});
return unique;
""".replace("FIELD", str(MIN_FIELD_GAP)).replace("STACK", str(MIN_STACK_GAP))


def check_spacing(b: Browser, base: str, screens, r: Results) -> None:
    """Nothing in a form touches the thing above it.

    The forms are the admin. Every fault this looks for is one somebody
    reported from a screenshot -- a label four pixels off its own control, a
    field flush against the card beneath it -- and every one of them came from
    a rule that was correct on the page it was written for and had nothing to
    say about the page it was reused on. One stylesheet, so one measurement.
    """
    b.size(1200)

    for screen in screens:
        b.go(base + screen)
        found = b.js(SPACING) or []

        r.check(f"{screen}: nothing in the form is touching",
                not found,
                "; ".join(found[:6]))

        print(f"  {'ok  ' if not found else 'FAIL'}  {screen}"
              f"  ({len(found)} tight)")


def walk_dark(b: Browser, base: str, screens, r: Results) -> None:
    b.size(1200)
    b.go(base + "/login.php")
    b.set_theme("dark")

    for screen in screens:
        b.go(base + screen)
        seen = b.js(DARK)

        r.check(f"dark {screen}: the theme is applied",
                seen["theme"] == "dark",
                f"data-theme={seen['theme']!r} — theme-init.js did not run, or "
                f"localStorage was not readable")

        r.check(f"dark {screen}: the body paints its own background",
                seen["bodyOpaque"],
                f"body background is {seen['bodyBg']!r}; a transparent body "
                f"borrows whatever is behind the page")

        r.check(f"dark {screen}: no text is drawn in its own background colour",
                not seen["invisible"],
                "; ".join(seen["invisible"]))

        print(f"  {'ok  ' if seen['bodyOpaque'] and not seen['invisible'] else 'FAIL'}"
              f"  dark {screen}  body={seen['bodyBg']}")

    b.go(base + "/login.php")
    b.set_theme("light")


OPEN_DISCLOSURES = r"""
/* Open every <details> before sampling.

   A control inside a closed disclosure has hover and focus rules like any
   other, and no pointer can reach it to prove they work: the account menu
   reported "nothing computed changed under the pointer" for both of its items
   and the reason was that the menu was shut. Skipping them instead would mean
   the one part of the chrome built out of a disclosure is the one part nobody
   checks -- and the account menu holds Sign out.

   Returns how many were opened so the caller can say so. */
var shut = document.querySelectorAll('details:not([open])');
for (var i = 0; i < shut.length; i++) { shut[i].open = true; }
return shut.length;
"""


MEASURE_BARS = r"""
/* The two bars that sit on top of the scrollport, and what html's
   scroll-padding claims about them.

   admin.css carries measured numbers here, with a comment saying to measure
   again if either bar is restyled. That instruction has to be enforced by
   something: a padding smaller than the bar puts fields back underneath it for
   anyone arriving by keyboard (SC 2.4.11), and a padding much larger than the
   bar scrolls every anchor to the wrong place. Neither is visible until
   somebody tabs or follows a link into the middle of the form. */
function h(sel) {
  var e = document.querySelector(sel);
  return e ? Math.round(e.getBoundingClientRect().height) : 0;
}
var root = getComputedStyle(document.documentElement);
return {
  top: h('.admin-bar'),
  bottom: h('.admin__actions--sticky'),
  padTop: Math.round(parseFloat(root.scrollPaddingTop) || 0),
  padBottom: Math.round(parseFloat(root.scrollPaddingBottom) || 0)
};
"""


def judge_bars(where: str, tallest: dict, pads: dict, slack: int, r: Results) -> None:
    """One width's worth of bar measurements, against its own padding."""
    for edge in ("top", "bottom"):
        bar, pad = tallest[edge], pads[edge]
        if bar == 0:
            continue

        r.check(
            f"{where}: scroll-padding clears the tallest {edge} bar",
            pad >= bar,
            f"the tallest is {bar}px and scroll-padding-{edge} is {pad}px — "
            f"a field scrolled to that edge lands under it (SC 2.4.11)")

        # Generous is safe; wildly generous means the number is stale, and
        # every anchor then overshoots by the difference.
        r.check(
            f"{where}: scroll-padding is not stale at the {edge}",
            pad - bar <= slack,
            f"the tallest is {bar}px and scroll-padding-{edge} is {pad}px — "
            f"{pad - bar}px of overshoot. Measure again and update the "
            f"html{{scroll-padding}} rule in admin.css.")


def check_bars(b: Browser, base: str, screens, r: Results) -> None:
    """Prove scroll-padding still clears the bars it was measured against.

    ONE PADDING PER BREAKPOINT, SO ONE MEASUREMENT PER BREAKPOINT.
    html{scroll-padding} is a single pair of numbers for the whole admin, and
    the bars are not the same height on every screen -- the account page has no
    save button and a shorter heading. So what matters is the tallest each bar
    ever gets: the padding has to clear that, and it should not clear it by so
    much that every anchor overshoots.

    AND THE NARROW ONE IS MEASURED TOO. The bar wraps to two rows below 40em
    and the lede under it runs to three lines, so it is 181px there against
    106px on a desktop. admin.css carries a second --admin-bar-h for that
    range, and a number nothing measures is a number that goes stale -- which
    is the fault this whole function exists to catch. 320 has to be measured in
    a frame, for the reason written above FRAME.
    """
    b.size(1200)

    tallest = {"top": 0, "bottom": 0}
    pads = {"top": 0, "bottom": 0}

    for screen in screens:
        b.go(base + screen)
        m = b.js(MEASURE_BARS)
        if not m:
            continue

        tallest["top"] = max(tallest["top"], m["top"])
        tallest["bottom"] = max(tallest["bottom"], m["bottom"])
        pads["top"] = m["padTop"]
        pads["bottom"] = m["padBottom"]

        print(f"  1200px {screen}: top {m['top']}px, bottom {m['bottom']}px")

    print(f"  tallest at 1200px: top {tallest['top']}px (pad {pads['top']}px), "
          f"bottom {tallest['bottom']}px (pad {pads['bottom']}px)")
    judge_bars("1200px", tallest, pads, 32, r)

    # ------------------------------------------------------- and at 320px
    b.size(1200, 950)
    b.go(base + "/")

    narrow = {"top": 0, "bottom": 0}
    narrow_pads = {"top": 0, "bottom": 0}
    measured = False

    for screen in screens:
        for _ in range(60):
            state = b.js(FRAME, REFLOW_WIDTH, base + screen)
            if not state.get("loading"):
                break
            time.sleep(0.25)
        else:
            continue

        if not 0 <= REFLOW_WIDTH - int(state["inner"]) <= 40:
            continue    # clamped; walk_reflow reports that, and loudly

        m = b.js(IN_FRAME + MEASURE_BARS)
        if not m:
            continue

        measured = True
        narrow["top"] = max(narrow["top"], m["top"])
        narrow["bottom"] = max(narrow["bottom"], m["bottom"])
        narrow_pads["top"] = m["padTop"]
        narrow_pads["bottom"] = m["padBottom"]

        print(f"  {REFLOW_WIDTH}px {screen}: top {m['top']}px, bottom {m['bottom']}px")

    if measured:
        # Looser than 32 on purpose: one padding covers both the account page,
        # whose bar has no save button and a one-line lede, and the company
        # editor, whose bar is 77px taller. The spread is real and no single
        # number can be tight against both.
        print(f"  tallest at {REFLOW_WIDTH}px: top {narrow['top']}px "
              f"(pad {narrow_pads['top']}px)")
        judge_bars(f"{REFLOW_WIDTH}px", narrow, narrow_pads, 96, r)


def walk_hover(b: Browser, base: str, screens, r: Results) -> None:
    b.size(1200)
    seen_kinds: set[str] = set()

    for screen in screens:
        b.go(base + screen)
        b.js(OPEN_DISCLOSURES)
        kinds = b.js(HOVER_KINDS) or {}

        for kind, spots in kinds.items():
            if kind in seen_kinds:
                continue
            seen_kinds.add(kind)

            reacted, looked = False, 0
            for at in spots:
                b.point(5, 5)
                before = b.js(STYLE_AT, at["x"], at["y"])
                b.point(at["x"], at["y"])
                after = b.js(STYLE_AT, at["x"], at["y"])

                if before is None or after is None:
                    continue
                looked += 1
                if before != after:
                    reacted = True
                    break

            if not looked:
                continue

            r.check(f"hover: {kind} reacts to a pointer",
                    reacted,
                    f"on {screen}, {looked} of them looked at and nothing "
                    f"computed changed under the pointer")

    print(f"  {len(seen_kinds)} kinds of control sampled")


def check_rail_labels(b: Browser, base: str, r: Results) -> None:
    """Every rail label sits on ONE LINE and is fully readable.

    NOT A TASTE CHECK. .rail__label sets white-space: nowrap, so a label the
    rail is too narrow for does not wrap -- it is CUT OFF by text-overflow:
    ellipsis, and "Resource Certificat…" is a menu item nobody can be sure of.
    The rail's wide width is set by the longest label, so renaming a section is
    a change to that width, and this is what says so.

    Measured three ways, because the rail has three shapes: wide, narrowed to
    icons (where the text is hidden the accessible way and has no box to
    measure, so it is skipped), and the horizontal chip strip below 60em.
    """
    for width, what in ((1200, "the wide rail"), (600, "the chip strip")):
        b.size(width)
        b.go(base + "/?s=seo")

        found = b.js("""
            const out = [];
            for (const el of document.querySelectorAll('.rail__label')) {
                const box = el.getBoundingClientRect();
                if (box.width === 0) { continue; }
                const line = parseFloat(getComputedStyle(el).lineHeight) || box.height;
                out.push({
                    text:  el.textContent.trim(),
                    lines: Math.round(box.height / line),
                    over:  el.scrollWidth - el.clientWidth,
                });
            }
            return out;
        """) or []

        r.check(f"{what}: the rail has labels to measure", len(found) > 0,
                "no .rail__label was visible, so nothing was checked")

        for row in found:
            r.check(f"{what}: “{row['text']}” is on one line",
                    row["lines"] <= 1, f"{row['lines']} lines")
            # One pixel of slack: sub-pixel text metrics round either way, and
            # a label that fits is sometimes reported one pixel over.
            r.check(f"{what}: “{row['text']}” is not cut off",
                    row["over"] <= 1,
                    f"{row['over']}px wider than the space it has — widen "
                    f"--rail-wide in public/assets/css/admin.css")


def check_collapsed(b: Browser, base: str, screens, r: Results) -> None:
    """Nothing in the editing column is squeezed to no width at all.

    THE SHAPE OF A REAL BUG, NOT A TIDINESS RULE. admin_band_head() floats its
    legend, because a <legend> is otherwise laid out on its fieldset's border.
    A float escapes any ancestor that is not a block formatting context, and it
    is 100% wide, so a field placed after one inside a plain <div> is laid out
    BESIDE it with nothing left to occupy. On the privacy editor that field
    measured 0px wide inside a 582px card: its label wrapped onto four lines,
    the select under it was a stub, and the control still worked perfectly --
    which is why no functional suite noticed and a person had to.

    So the signature is measured rather than the cause: an element that is
    displayed, has height, and has NO WIDTH. Nothing legitimate on these
    screens is shaped like that, and anything that becomes so is either
    unreachable or unreadable.

    Hidden things are excluded by offsetParent, which is null for anything
    display:none or inside it -- their boxes are zero by right and mean nothing.
    """
    for screen in screens:
        b.size(1200)
        b.go(base + screen)

        found = b.js("""
            const out = [];
            const main = document.getElementById('admin-main');
            if (!main) { return out; }
            // <br> and <wbr> are zero-wide by definition -- they are line
            // behaviour, not boxes, and they are not what this is looking for.
            const shapeless = { BR: 1, WBR: 1 };
            for (const el of main.querySelectorAll('*')) {
                if (el.offsetParent === null || shapeless[el.tagName]) { continue; }
                const box = el.getBoundingClientRect();
                if (box.width >= 1 || box.height < 1) { continue; }
                const parent = el.parentElement
                    ? Math.round(el.parentElement.getBoundingClientRect().width) : 0;
                if (parent < 40) { continue; }
                out.push({
                    tag: el.tagName.toLowerCase(),
                    cls: (el.getAttribute('class') || '').split(' ')[0],
                    height: Math.round(box.height),
                    parent: parent,
                });
            }
            return out;
        """) or []

        r.check(f"{screen}: nothing is collapsed to no width",
                found == [],
                "; ".join(
                    f"{row['tag']}.{row['cls']} is 0x{row['height']}px inside a "
                    f"{row['parent']}px parent" for row in found[:4]))


def run(b: Browser, base: str, secret: str, r: Results) -> None:
    prove_reduced_motion(b, base)

    r.section("focus, signed out")
    b.size(1200)
    walk_focus(b, base, PUBLIC_SCREENS, "desktop", r)

    b.sign_in(base, secret)

    landed = b.js("return location.pathname + location.search;")
    if "login.php" in (landed or ""):
        raise SystemExit(
            "The sign-in did not take, so every check below would be measuring "
            "the login page eight times over. Refusing to report that as a pass."
        )
    print(f"\nsigned in; landed on {landed}")

    r.section("focus, signed in")
    b.size(1200)
    walk_focus(b, base, SIGNED_IN_SCREENS, "desktop", r)

    r.section("reflow and target size at 320px")
    walk_reflow(b, base, SIGNED_IN_SCREENS, r)

    r.section("dark mode")
    walk_dark(b, base, SIGNED_IN_SCREENS, r)

    r.section("the sticky bars")
    check_bars(b, base, SIGNED_IN_SCREENS, r)

    r.section("spacing")
    check_spacing(b, base, SIGNED_IN_SCREENS, r)

    r.section("the rail's labels")
    check_rail_labels(b, base, r)

    r.section("nothing is squeezed to nothing")
    check_collapsed(b, base, SIGNED_IN_SCREENS, r)

    r.section("hover")
    walk_hover(b, base, SIGNED_IN_SCREENS, r)


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        print("This check needs Firefox and geckodriver as well as the PHP CLI.")
        return

    web_port, drv_port = free_port(), free_port()
    work = Path(tempfile.mkdtemp(prefix="t4t-admin-a11y-"))
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

    results = Results()
    browser = None
    try:
        browser = Browser(drv_port)
        run(browser, base, admin_session.make_account(private), results)
    finally:
        if browser:
            browser.quit()
        for proc in (drv, php):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass
        shutil.rmtree(work, ignore_errors=True)

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")

    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)

    print("\nThe admin is keyboard-reachable, survives 320px, paints in dark "
          "mode and answers a pointer.")


if __name__ == "__main__":
    main()
