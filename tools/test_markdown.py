#!/usr/bin/env python3
"""
Prove the legal-Markdown renderer only lets prose through.

Test. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_markdown.py

WHY THIS EXISTS
lib/markdown.php draws the legal pages, which carry the most consequential
words on the site, from operator-typed source. It is new, hand-written, and a
security boundary in the same sense lib/svg.php is: whatever it emits reaches
a visitor. So it is tested as one -- exact outputs for the dialect, refusals
for everything outside it, and adversarial inputs that must degrade to visible
literal text rather than markup or a hang.

THIS FILE IS SHARED
lib/markdown.php is byte-identical in both repositories, so this suite is too.
It must pass on both: digests prove the files match, and this proves the
matching files are right. A hang fails rather than hanging CI -- every case
runs under a timeout, because an input that never returns is the worst
possible answer from a renderer and two such inputs were found while writing
it (an unknown ::: fence that never advanced, and a lone + with no branch).

WITHOUT PHP
Nothing runs: the renderer is PHP and there is no second implementation to
check against. Exits 0 with a notice, the way test_qr.py does without
qrencode -- the failure mode being measured is silence either way.
"""

import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TIMEOUT = 30

ALLOWED_TAGS = {
    "p", "br", "strong", "em", "u", "a", "ul", "ol", "li",
    "h2", "h3", "h4", "h5", "h6",
    "table", "thead", "tbody", "tr", "th", "td", "div",
}
TAG_RE = re.compile(r"</?([a-zA-Z][a-zA-Z0-9]*)[^>]*>")


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


def render(src: str) -> str:
    """One document through lib/markdown.php. A hang is an answer, not an
    accident: it raises, and the caller fails the case."""
    code = ("require 'lib/html.php'; require 'lib/markdown.php';"
            "echo md_render(file_get_contents('php://stdin'));")
    out = subprocess.run(["php", "-r", code], cwd=ROOT, input=src.encode(),
                         capture_output=True, timeout=TIMEOUT)
    if out.returncode != 0:
        raise RuntimeError("php failed: " + out.stderr.decode()[:300])
    return out.stdout.decode()


def headings(src: str) -> list:
    """The TOC list for a document, in order."""
    code = ("require 'lib/html.php'; require 'lib/markdown.php';"
            "echo json_encode(md_headings(file_get_contents('php://stdin')));")
    out = subprocess.run(["php", "-r", code], cwd=ROOT, input=src.encode(),
                         capture_output=True, timeout=TIMEOUT)
    if out.returncode != 0:
        raise RuntimeError("php failed: " + out.stderr.decode()[:300])
    import json as _json
    return _json.loads(out.stdout.decode())


def vocab_ok(html: str):
    """Every tag in the output is one the dialect may emit, and no tag
    carries anything executable. Text -- including escaped attack source and
    literal Markdown the renderer refused -- is character data, and checking
    it for substrings would fail safe text for containing the words of the
    attack it visibly did not become. So the refusals are asserted on tags,
    where execution lives, and on raw tag openings, which is where a
    pass-through would show."""
    tags = list(TAG_RE.finditer(html))
    for m in tags:
        if m.group(1).lower() not in ALLOWED_TAGS:
            return False, "tag outside the vocabulary: " + m.group(0)[:60]
        low = m.group(0).lower()
        if re.search(r"\son\w+\s*=", low) or "style=" in low or "src=" in low:
            return False, "executable attribute: " + m.group(0)[:80]
        if "javascript:" in low or "data:" in low:
            return False, "executable URL: " + m.group(0)[:80]
    for raw in ("<script", "<img", "<iframe", "<svg", "<style", "<object",
                "<embed", "<form", "<input", "<button", "<select",
                "<textarea", "<link", "<meta"):
        if raw in html.lower():
            return False, "raw pass-through: " + raw
    return True, ""


# (name, source, exact expected output)
EXACT = [
    ("empty", "", ""),
    ("plain", "hello", "<p>hello</p>"),
    ("entities not doubled", "a &amp; b &lt;c&gt;",
     "<p>a &amp; b &lt;c&gt;</p>"),
    ("raw html escaped", '<script>alert(1)</script>',
     "<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>"),
    ("no headings", "# Title", "<p># Title</p>"),
    ("strong", "**b**", "<p><strong>b</strong></p>"),
    ("emphasis", "*e*", "<p><em>e</em></p>"),
    ("underline", "++u++", "<p><u>u</u></p>"),
    ("unpaired strong literal", "**open", "<p>**open</p>"),
    ("empty strong literal", "****", "<p>****</p>"),
    ("unpaired em literal", "a * b", "<p>a * b</p>"),
    ("underline never spans a break", "a ++x\nb++ c",
     "<p>a ++x b++ c</p>"),
    ("empty underline literal", "++ ++", "<p>++ ++</p>"),
    ("lone plus is text", "call +880 1", "<p>call +880 1</p>"),
    ("lone backslash is text", "a\\b", "<p>a\\b</p>"),
    ("escapes", "\\*x\\* \\+\\+y\\+\\+",
     "<p>*x* ++y++</p>"),
    ("external link", "[x](https://a.b/c)",
     '<p><a href="https://a.b/c" target="_blank" rel="noopener noreferrer">x</a></p>'),
    ("site link", "[x](/pages/a/)", '<p><a href="/pages/a/">x</a></p>'),
    ("mailto link", "[x](mailto:a@b.c)", '<p><a href="mailto:a@b.c">x</a></p>'),
    ("bad scheme literal", "[x](javascript:alert(1))",
     "<p>[x](javascript:alert(1))</p>"),
    ("data url literal", "[x](data:text/html,1)",
     "<p>[x](data:text/html,1)</p>"),
    ("empty label literal", "[](https://a.b)", "<p>[](https://a.b)</p>"),
    ("no nesting links", "[a [b](https://c.d)](https://e.f)",
     "<p>[a <a href=\"https://c.d\" target=\"_blank\""
     " rel=\"noopener noreferrer\">b</a>](https://e.f)</p>"),
    ("label is inline", "[**b** and *e*](https://a.b)",
     '<p><a href="https://a.b" target="_blank" rel="noopener noreferrer">'
     "<strong>b</strong> and <em>e</em></a></p>"),
    ("bullets", "- a\n- b", "<ul><li>a</li><li>b</li></ul>"),
    ("numbered", "1. a\n2. b", "<ol><li>a</li><li>b</li></ol>"),
    ("indented item", "  - a", "<ul><li>a</li></ul>"),
    ("nested marker literal", "    - a", "<p>    - a</p>"),
    ("blank ends list", "- a\n\n- b",
     "<ul><li>a</li></ul>\n<ul><li>b</li></ul>"),
    ("table", "| A | B |\n|---|:---:|\n| **c** | d |",
     '<div class="legal__table-wrap"><table class="legal__table">'
     "<thead><tr><th scope=\"col\">A</th>"
     "<th scope=\"col\" class=\"ta-center\">B</th></tr></thead>"
     "<tbody><tr><td><strong>c</strong></td>"
     "<td class=\"ta-center\">d</td></tr></tbody></table></div>"),
    ("ragged table literal", "| A | B |\n|---|---|\n| x | y | z |",
     "<p>| A | B |</p>\n<p>|---|---| | x | y | z |</p>"),
    ("no delimiter, no table", "| A | B |\nplain",
     "<p>| A | B | plain</p>"),
    ("note", ":::note\nHi *there*\n:::",
     '<div class="legal__notice">\n<p>Hi <em>there</em></p>\n</div>'),
    ("center", ":::center\nHi\n:::",
     '<div class="ta-center">\n<p>Hi</p>\n</div>'),
    ("h2", "## Second", '<h2 class="legal__heading" id="second">Second</h2>'),
    ("h3-h6", "### T\n#### F\n##### V\n###### S",
     '<h3 class="legal__subheading" id="t">T</h3>\n'
     '<h4 class="legal__subheading" id="f">F</h4>\n'
     '<h5 class="legal__subheading" id="v">V</h5>\n'
     '<h6 class="legal__subheading" id="s">S</h6>'),
    ("h1 stays literal", "# Top", "<p># Top</p>"),
    ("seven hashes literal", "####### x", "<p>####### x</p>"),
    ("no space literal", "###x", "<p>###x</p>"),
    ("closing run stripped", "### Done ###",
     '<h3 class="legal__subheading" id="done">Done</h3>'),
    ("explicit id", "## Who {#who}",
     '<h2 class="legal__heading" id="who">Who</h2>'),
    ("explicit id kept on reword", "## Whatever {#who}",
     '<h2 class="legal__heading" id="who">Whatever</h2>'),
    ("duplicate slugs dedupe", "## Terms\n## Terms",
     '<h2 class="legal__heading" id="terms">Terms</h2>\n'
     '<h2 class="legal__heading" id="terms-2">Terms</h2>'),
    ("invalid suffix literal, slug id", "## {#bad id}",
     '<h2 class="legal__heading" id="bad-id">{#bad id}</h2>'),
    ("empty suffix literal", "## {#}",
     '<h2 class="legal__heading" id="section">{#}</h2>'),
    ("heading inline", "## A **b** and [c](/d)",
     '<h2 class="legal__heading" id="a-b-and-c">A <strong>b</strong> and '
     '<a href="/d">c</a></h2>'),
    ("unknown container literal", ":::nope\nx\n:::",
     "<p>:::nope</p>\n<p>x</p>\n<p>:::</p>"),
    ("unclosed container literal", ":::note\nunclosed",
     "<p>:::note</p>\n<p>unclosed</p>"),
    ("nested opener literal", ":::note\n:::note\ninner\n:::\nafter",
     '<div class="legal__notice">\n<p>:::note</p>\n<p>inner</p>\n</div>\n<p>after</p>'),
    ("list in note", ":::note\n- a\n- b\n:::",
     '<div class="legal__notice">\n<ul><li>a</li><li>b</li></ul>\n</div>'),
    ("paragraphs join", "one\ntwo\n\nthree", "<p>one two</p>\n<p>three</p>"),
]

# Inputs that must terminate and must carry no executable trace. Expected
# outputs are asserted structurally (vocabulary + absence), not byte-exact,
# because the point is the refusal, not the wording.
ADVERSARIAL = [
    "adversarial script/img/event",
    "<script>alert(1)</script><img src=x onerror=alert(2)>"
    "<svg onload=alert(3)><a href=\"javascript:alert(4)\">x</a>",
    "adversarial entities",
    "&lt;script&gt; &colon; &lpar;alert(1)&rpar;",
    "adversarial link tricks",
    "[x](JaVaScRiPt:alert(1)) [y](java\tscript:alert(1)) [z](//evil.example/)",
    "adversarial table bomb",
    "| H |\n|---|\n" + "| r |\n" * 500,
    "adversarial unclosed emphasis",
    "*" * 2000,
    "adversarial unclosed fences",
    ":::note\n" * 200,
    "adversarial deep nesting",
    "**a " * 40 + "b",
    "adversarial mixed markers",
    "**a *b ++c [d](https://e.f) :::note\n**" * 60,
    "adversarial megabyte",
    "lorem ipsum dolor sit amet. " * 40000,
    "adversarial pipes",
    ("| a |\n" * 200) + "|---|\n" + ("| b |\n" * 200),
]


def main() -> int:
    if not shutil.which("php"):
        print("test_markdown: no PHP CLI -- nothing runs, nothing proved "
              "(install php-cli)")
        return 0

    r = Results()

    for name, src, want in EXACT:
        try:
            got = render(src)
        except Exception as e:  # noqa: BLE001 -- a hang is a FAIL, see module doc
            r.check(name, False, f"did not return: {e}")
            continue
        r.check(name, got == want,
                f"wanted: {want[:200]}\n          got:    {got[:200]}")

    for name, src in zip(ADVERSARIAL[0::2], ADVERSARIAL[1::2]):
        try:
            got = render(src)
        except Exception as e:  # noqa: BLE001
            r.check(name, False, f"did not return: {e}")
            continue
        ok, detail = vocab_ok(got)
        r.check(name, ok, detail + " :: " + got[:200])

    # The output vocabulary, over everything above: no tag outside the set
    # the dialect may emit, on any input this suite tried.
    try:
        corpus = [s for _, s, _ in EXACT] + ADVERSARIAL[1::2]
        bad = [(s, d) for s in corpus
               for _, d in [vocab_ok(render(s))] if d]
        r.check("output vocabulary holds over the whole corpus",
                not bad, "; ".join(f"{s[:40]}: {d}" for s, d in bad[:3]))
    except Exception as e:  # noqa: BLE001
        r.check("output vocabulary holds over the whole corpus", False, str(e)[:200])

    print("\nthe table of contents reads the same resolution as the page")
    try:
        got = headings("## Alpha {#a}\n\ntext\n\n### Beta\n\n## Alpha\n\n#### Deep")
        want = [
            {"level": 2, "id": "a", "text": "Alpha"},
            {"level": 3, "id": "beta", "text": "Beta"},
            {"level": 2, "id": "alpha", "text": "Alpha"},
            {"level": 4, "id": "deep", "text": "Deep"},
        ]
        r.check("levels, ids and texts in order", got == want,
                f"got {got!r:.200}")
        r.check("a rail link can never miss its anchor",
                all(h["id"] for h in got) and len({h["id"] for h in got}) == len(got))
    except Exception as e:  # noqa: BLE001
        r.check("the table of contents reads the same resolution as the page",
                False, f"did not return: {e}")

    print(f"\n{r.passed}/{r.passed + len(r.failed)} checks passed")
    return 1 if r.failed else 0


if __name__ == "__main__":
    sys.exit(main())
