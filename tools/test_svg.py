#!/usr/bin/env python3
"""
Prove the SVG sanitiser only lets a drawing through.

Test. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_svg.py

WHY THIS EXISTS
lib/svg.php is a security boundary, so it is tested as one. ADR 0019 refused
SVG outright because "an SVG is a document: it can carry script, external
references and entities, and re-encoding does not make it not a document". The
branding page needs vector logos, so that had to be answered rather than
ignored, and this is where the answer is checked.

The rule is the raster path's rule: an upload is untrusted input to be READ and
then REPLACED. What is stored is the sanitiser's own output. So the cases below
are not only "was this refused" — they are also "is what came out still the
drawing that went in", and "does running it again change anything".

IDEMPOTENCE IS NOT A NICETY
The receiving host proves bytes are clean by sanitising them and checking
nothing moved. If sanitising were not idempotent it would have to store
something different from what it was sent, and the content-addressed name the
two hosts compute independently would stop matching. Asserted directly.

THIS FILE IS SHARED
lib/svg.php is byte-identical in both repositories, so this suite is too. It
must pass on both.

WITHOUT ext-dom
The parsing cases are skipped with a notice, the way test_upload.py skips
without GD. The cases that run before the parser — a DOCTYPE, an entity, a
processing instruction, the size cap — still run, because they are refusals
that must hold on any host. CI installs php-xml so the whole of it runs there;
locally, `sudo apt-get install php-xml`.
"""

import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

SVG_NS = 'xmlns="http://www.w3.org/2000/svg"'

# A small but real logo: a title, a gradient in <defs>, a shape that paints
# itself with it, and a <use> that draws something twice. Everything a vector
# mark actually needs, and nothing else.
CLEAN = (
    f'<svg {SVG_NS} viewBox="0 0 1600 570">'
    "<title>Tech4TIME</title>"
    '<defs><linearGradient id="ink" x1="0" y1="0" x2="1" y2="0">'
    '<stop offset="0" stop-color="#0b0b0c"/><stop offset="1" stop-color="#4a4a52"/>'
    "</linearGradient>"
    '<path id="bar" d="M0 0H400V80H0Z"/></defs>'
    '<g transform="translate(40,60)">'
    '<path d="M10 10H1590V560H10Z" fill="url(#ink)" stroke="#000" stroke-width="4"/>'
    '<use href="#bar" fill="#fff" opacity="0.85"/>'
    '<text x="20" y="120" font-family="Inter" font-size="48" fill="#fff">Tech4TIME</text>'
    "</g></svg>"
)


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []
        self.skipped = 0

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    def skip(self, case):
        self.skipped += 1
        print(f"  --    {case}  (needs ext-dom)")


def sanitise(svg: str) -> dict:
    """Run one document through lib/svg.php and read back what it decided."""
    code = (
        "require 'lib/svg.php';"
        "$in = file_get_contents('php://stdin');"
        "echo json_encode(svg_sanitise($in), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);"
    )
    out = subprocess.run(["php", "-r", code], cwd=ROOT, input=svg.encode(),
                         capture_output=True)
    if out.returncode != 0:
        return {"error": "php failed: " + out.stderr.decode()[:400]}
    return json.loads(out.stdout.decode())


def has_dom() -> bool:
    out = subprocess.run(
        ["php", "-r", "exit(class_exists('DOMDocument') ? 0 : 1);"],
        cwd=ROOT, capture_output=True)
    return out.returncode == 0


def main() -> int:
    r = Results()
    dom = has_dom()

    # ------------------------------------------------ before the parser runs
    # These are refusals made on the raw bytes, so they hold on any host.
    print("\nrefused before parsing")

    for case, doc in [
        ("a DOCTYPE",
         f'<!DOCTYPE svg><svg {SVG_NS} viewBox="0 0 10 10"><path d="M0 0"/></svg>'),
        ("an entity declaration (XXE)",
         '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
         f'<svg {SVG_NS}><text>&xxe;</text></svg>'),
        ("a billion-laughs expansion",
         '<!DOCTYPE lol [<!ENTITY a "aa"><!ENTITY b "&a;&a;">]>'
         f'<svg {SVG_NS}><text>&b;</text></svg>'),
        ("a PHP processing instruction",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><?php echo 1; ?><path d="M0 0"/></svg>'),
        ("a DOCTYPE with an external DTD",
         '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" '
         '"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
         f'<svg {SVG_NS}><path d="M0 0"/></svg>'),
        ("an empty file", ""),
    ]:
        got = sanitise(doc)
        r.check(case, "error" in got, f"accepted: {str(got)[:120]}")

    got = sanitise(f'<svg {SVG_NS} viewBox="0 0 10 10">' + '<path d="M0 0"/>' * 40000 + "</svg>")
    r.check("a file over the 512 KB cap", "error" in got and "KB" in got["error"],
            str(got)[:160])

    # ------------------------------------------------------ what may survive
    print("\naccepted, and unchanged by a second pass")

    if not dom:
        for case in ("a real logo survives", "the drawing is still there",
                     "dimensions come from viewBox", "sanitising is idempotent",
                     "a <style> block of plain rules survives",
                     "url(#gradient) survives", "a comment is removed",
                     "width and height stand in for a missing viewBox",
                     "no intrinsic size is reported as none"):
            r.skip(case)
    else:
        got = sanitise(CLEAN)
        r.check("a real logo survives", "svg" in got, str(got)[:200])

        if "svg" in got:
            out = got["svg"]
            r.check("the drawing is still there",
                    all(bit in out for bit in
                        ("M10 10H1590V560H10Z", 'id="ink"', 'href="#bar"',
                         "Tech4TIME", 'stroke-width="4"')),
                    out[:200])
            r.check("dimensions come from viewBox",
                    (got["width"], got["height"]) == (1600, 570),
                    f'{got["width"]}x{got["height"]}')

            again = sanitise(out)
            r.check("sanitising is idempotent",
                    again.get("svg") == out,
                    "a second pass changed the bytes, which would break the "
                    "content-addressed name")

        got = sanitise(f'<svg {SVG_NS} viewBox="0 0 100 50">'
                       "<style>.a{fill:#0b0b0c;stroke-width:2}</style>"
                       '<path class="a" d="M0 0H10"/></svg>')
        r.check("a <style> block of plain rules survives",
                "svg" in got and "fill:#0b0b0c" in got.get("svg", ""),
                str(got)[:160])

        got = sanitise(f'<svg {SVG_NS} viewBox="0 0 10 10">'
                       '<defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs>'
                       '<path d="M0 0" style="fill:url(#g)"/></svg>')
        r.check("url(#gradient) survives", "svg" in got, str(got)[:160])

        got = sanitise(f'<svg {SVG_NS} viewBox="0 0 10 10">'
                       "<!-- a comment --><path d=\"M0 0\"/></svg>")
        r.check("a comment is removed",
                "svg" in got and "a comment" not in got.get("svg", ""),
                str(got)[:160])

        got = sanitise(f'<svg {SVG_NS} width="512" height="128"><path d="M0 0"/></svg>')
        r.check("width and height stand in for a missing viewBox",
                got.get("width") == 512 and got.get("height") == 128, str(got)[:160])

        got = sanitise(f'<svg {SVG_NS}><path d="M0 0"/></svg>')
        r.check("no intrinsic size is reported as none",
                got.get("width") == 0 and got.get("height") == 0, str(got)[:160])

    # ------------------------------------------------------ what may not
    print("\nrefused by the allow-list")

    hostile = [
        ("a <script> element",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><script>alert(1)</script></svg>'),
        ("an onload handler",
         f'<svg {SVG_NS} viewBox="0 0 10 10" onload="alert(1)"><path d="M0 0"/></svg>'),
        ("an onclick on a child",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><path d="M0 0" onclick="alert(1)"/></svg>'),
        ("a <foreignObject>",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><foreignObject width="10" height="10">'
         '<body xmlns="http://www.w3.org/1999/xhtml">hi</body></foreignObject></svg>'),
        ("an embedded raster",
         f'<svg {SVG_NS} viewBox="0 0 10 10">'
         '<image href="data:image/png;base64,iVBORw0KGgo="/></svg>'),
        ("a SMIL animation",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><path d="M0 0">'
         '<animate attributeName="fill" values="red;blue"/></path></svg>'),
        ("an <a> wrapping the mark",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><a href="https://evil.example">'
         '<path d="M0 0"/></a></svg>'),
        ("a use pointing off the file",
         '<svg xmlns="http://www.w3.org/2000/svg" '
         'xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">'
         '<use xlink:href="https://evil.example/x.svg#a"/></svg>'),
        ("a javascript: reference",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><use href="javascript:alert(1)"/></svg>'),
        ("@import in a <style> block",
         f'<svg {SVG_NS} viewBox="0 0 10 10">'
         "<style>@import url(https://evil.example/x.css);</style></svg>"),
        ("url() reaching off the file, in a style attribute",
         f'<svg {SVG_NS} viewBox="0 0 10 10">'
         '<path d="M0 0" style="fill:url(https://evil.example/a.png)"/></svg>'),
        ("url() reaching off the file, in a presentation attribute",
         f'<svg {SVG_NS} viewBox="0 0 10 10">'
         '<path d="M0 0" fill="url(https://evil.example/a.png)"/></svg>'),
        ("-moz-binding",
         f'<svg {SVG_NS} viewBox="0 0 10 10">'
         '<path d="M0 0" style="-moz-binding:url(#x)"/></svg>'),
        ("a filter",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><filter id="f">'
         '<feGaussianBlur stdDeviation="2"/></filter></svg>'),
        ("an unknown attribute",
         f'<svg {SVG_NS} viewBox="0 0 10 10"><path d="M0 0" formaction="x"/></svg>'),
        ("HTML with an <svg> somewhere in it",
         '<html><body><svg xmlns="http://www.w3.org/2000/svg"/></body></html>'),
        ("something that is not XML at all", "just some text"),
    ]

    for case, doc in hostile:
        if not dom:
            r.skip(case)
            continue
        got = sanitise(doc)
        r.check(case, "error" in got, f"ACCEPTED: {str(got)[:160]}")

    # ------------------------------------------------------------- routing
    print("\nrecognising a vector file at all")

    def looks(doc: str) -> bool:
        out = subprocess.run(
            ["php", "-r", "require 'lib/svg.php';"
             "exit(svg_looks_like(file_get_contents('php://stdin')) ? 0 : 1);"],
            cwd=ROOT, input=doc.encode(), capture_output=True)
        return out.returncode == 0

    for case, doc, want in [
        ("a bare <svg>", f'<svg {SVG_NS}/>', True),
        ("an XML declaration first", f'<?xml version="1.0"?><svg {SVG_NS}/>', True),
        ("a comment first", f'<!-- hi --><svg {SVG_NS}/>', True),
        # Matched here and REFUSED by svg_sanitise(), which reads backwards but
        # is not: a file claiming to be a vector has to reach the sanitiser to
        # be told what is wrong with it. Without this it went down the raster
        # path and came back "not a JPEG, PNG, WebP or SVG picture", which is
        # both unhelpful and untrue.
        ("a DOCTYPE first — routed, then refused",
         f'<!DOCTYPE svg><svg {SVG_NS}/>', True),
        ("a PNG is not one", "\x89PNG\r\n\x1a\n", False),
        ("HTML is not one", "<html><svg/></html>", False),
        ("empty is not one", "", False),
    ]:
        r.check(f"svg_looks_like: {case}", looks(doc) == want)

    # ---------------------------------------------------------------- report
    print()
    if r.failed:
        print(f"{len(r.failed)} of {r.passed + len(r.failed)} checks FAILED\n")
        for case in r.failed:
            print(f"  {case}")
        return 1

    tail = f", {r.skipped} skipped (no ext-dom — install php-xml)" if r.skipped else ""
    print(f"{r.passed}/{r.passed} checks passed{tail}\n")
    print("A vector file is read and replaced, never checked and kept.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
