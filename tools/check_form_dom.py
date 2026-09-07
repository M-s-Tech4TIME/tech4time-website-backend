#!/usr/bin/env python3
"""
A form's named controls hide the form's own DOM properties. Find where that bites.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/check_form_dom.py
    python3 tools/check_form_dom.py -v     # list the collisions that are harmless

WHAT THIS IS ABOUT
HTMLFormElement is declared [LegacyOverrideBuiltIns] in the HTML specification.
That one word means a control's name wins over the interface's own property of
the same name -- not the other way round, and with no warning of any kind:

    <form action="?s=careers">
      <input type="hidden" name="action" value="save">
    </form>

    form.action                 -> the INPUT element, not a URL
    String(form.action)         -> "[object HTMLInputElement]"
    fetch(form.action, ...)     -> POST /[object%20HTMLInputElement]  ->  404

Ordinary submission is untouched, because the browser submits from the
attribute and never consults the property. So a form can be correct for years
and break on the day a script starts posting it -- which is exactly what
happened here on 2026-08-28: every button on the admin's careers screen posted
to an address no server has ever served, and the editor answered 404 to saving,
publishing, unpublishing, reordering and deleting alike. Nothing in the PHP was
wrong. No PHP path in either half returns 404 at all.

It got past a 215-check browser suite and an 1845-check accessibility suite
because both drive the markup and neither reads a property off a form.

WHAT IT REFUSES
1. A member of ALWAYS read straight off a form. Those eight decide where a
   request goes, how it is sent, and whether the typing survives -- and every
   one of them can be reached safely through the prototype instead, which is
   what both halves now do. This holds even where nothing is named after them
   today, because "nothing is named that yet" is not a property of the code
   that reads it.
2. Any OTHER member read off a form that some control in this repository is
   actually named after. This half needs no list to maintain: it re-derives
   the collision from the markup on every run, so a field added tomorrow that
   shadows something a script already reads fails tomorrow.

WHAT IT ONLY REPORTS
Control names that shadow a form property nothing currently reads. They are
not faults -- $_POST['action'] is a perfectly good thing to send -- but they
are the loaded half of the mechanism, and somebody about to write
form.something should be able to find out which names are already taken.

WHAT IT CANNOT SEE
An identifier it cannot tell is a form. It looks for names that look like one
(form, forms[i], theForm, formEl) because that is what both halves call them.
A form reached as event.target, or handed in as an unhelpfully named argument,
is invisible here -- which is why tools/test_admin_forms.py also presses a real
button on the screen this broke and asserts the answer was not an error.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Never read off a form, whatever anything happens to be named. Each is
# reachable through Object.getOwnPropertyDescriptor(HTMLFormElement.prototype,
# name) or HTMLFormElement.prototype.<name>.call(form), which is what the two
# submitting modules do.
ALWAYS = {
    "action":        "where the request goes",
    "method":        "how it is sent",
    "enctype":       "how the body is encoded",
    "target":        "which browsing context answers",
    "elements":      "every control in it",
    "submit":        "sending it",
    "requestSubmit": "sending it through validation",
    "reset":         "emptying it afterwards",
}

# The property surface a control name can hide. HTMLFormElement's own
# interface, plus the members of Element, HTMLElement and Node that these two
# codebases actually use on a form. It is a list because deriving it needs a
# browser, and a browser is a poor thing to need in order to read some markup.
SHADOWABLE = set(ALWAYS) | {
    "acceptCharset", "autocomplete", "encoding", "length", "name", "noValidate",
    "rel", "relList", "checkValidity", "reportValidity",
    "id", "title", "className", "classList", "dataset", "children", "hidden",
    "matches", "closest", "getAttribute", "setAttribute", "removeAttribute",
    "hasAttribute", "querySelector", "querySelectorAll", "addEventListener",
    "dispatchEvent", "focus", "blur", "remove", "before", "after", "append",
    "prepend", "replaceWith", "scrollIntoView",
}

# An identifier that holds a form, by the only means available to a text file:
# it is called one. Both halves name theirs `form`.
FORMISH = re.compile(r"\b((?:[a-z_$][\w$]*)?[Ff]orms?)(?:\[[^\]]*\])?\.([A-Za-z_$][\w$]*)")

# name="x", name='x', and the array shapes the editors post: name="offices[3][city]"
# and name="hero[<?= $i ?>][alt]" alike -- the property that gets shadowed is the
# base, because that is the name the DOM indexes the form by.
CONTROL = re.compile(r"""\bname\s*=\s*["']\s*([A-Za-z_][\w-]*)""")

SKIP_DIRS = {"tools", "docs", "plans", "content", "node_modules", ".git", "uploads"}


def js_files() -> list[Path]:
    """Wherever this half keeps its scripts: assets/js here, public/assets/js there."""
    found: list[Path] = []
    for base in (ROOT / "assets" / "js", ROOT / "public" / "assets" / "js"):
        if base.is_dir():
            found += sorted(base.glob("*.js"))
    return found


def markup_files() -> list[Path]:
    found: list[Path] = []
    for path in sorted(ROOT.rglob("*")):
        if path.suffix not in {".php", ".html"} or not path.is_file():
            continue
        if SKIP_DIRS & set(path.relative_to(ROOT).parts[:-1]):
            continue
        found.append(path)
    return found


def control_names() -> dict[str, list[str]]:
    """Every control name in the markup, and which files use it."""
    names: dict[str, list[str]] = {}
    for path in markup_files():
        text = path.read_text(encoding="utf-8", errors="replace")
        for found in set(CONTROL.findall(text)):
            names.setdefault(found, []).append(str(path.relative_to(ROOT)))
    return names


def strip_comments(text: str) -> str:
    """Block and line comments out, so prose about form.action is not a finding.

    Both files explain this bug at length in a comment directly above the fix,
    and a check that failed on its own explanation would be uncommentable. The
    newlines inside a block comment are kept, so a finding still names the line
    the reader will find it on."""
    text = re.sub(r"/\*.*?\*/",
                  lambda m: "\n" * m.group(0).count("\n"), text, flags=re.S)
    return re.sub(r"^\s*//.*$", "", text, flags=re.M)


def reads() -> list[tuple[Path, int, str, str]]:
    """(file, line, identifier, member) for every member read off a form."""
    found: list[tuple[Path, int, str, str]] = []
    for path in js_files():
        text = strip_comments(path.read_text(encoding="utf-8"))
        for number, line in enumerate(text.splitlines(), 1):
            for ident, member in FORMISH.findall(line):
                if member in SHADOWABLE:
                    found.append((path, number, ident, member))
    return found


def main() -> int:
    verbose = "-v" in sys.argv
    names = control_names()
    problems: list[str] = []
    harmless: list[tuple[Path, int, str, str]] = []

    for path, number, ident, member in reads():
        where = f"{path.relative_to(ROOT)}:{number}"

        if member in ALWAYS:
            problems.append(
                f"{where}: {ident}.{member} -- {ALWAYS[member]} is read off the form "
                f"itself. A control named \"{member}\" would replace it silently. "
                f"Read it through HTMLFormElement.prototype instead."
            )
        elif member in names:
            problems.append(
                f"{where}: {ident}.{member} is NOT the form's {member} -- "
                f"{names[member][0]} has a control of that name, so this is that "
                f"element. Read it through the prototype, or rename the control."
            )
        else:
            harmless.append((path, number, ident, member))

    loaded = sorted(set(names) & SHADOWABLE)

    print(f"  scanned  {len(js_files())} scripts, {len(markup_files())} markup files\n")

    if loaded:
        print("  Control names that shadow a form property. Not faults -- but these")
        print("  are the names a script must not read straight off the form:\n")
        for member in loaded:
            files = ", ".join(sorted(names[member])[:3])
            more = "" if len(names[member]) <= 3 else f" (+{len(names[member]) - 3} more)"
            print(f"    {member:<16} {files}{more}")
        print()

    if verbose:
        for path, number, ident, member in harmless:
            print(f"  ok    {path.relative_to(ROOT)}:{number}  {ident}.{member}")
        if harmless:
            print()

    if problems:
        print(f"check_form_dom: {len(problems)} problem(s)\n")
        for line in problems:
            print(f"  FAIL  {line}")
        print(
            "\nEvery one of these is silent in the browser, invisible in a diff, and\n"
            "works perfectly with JavaScript off -- which is how the last one lived\n"
            "for ten days in production."
        )
        return 1

    print(f"check_form_dom: no script reads a shadowed property off a form "
          f"({len(harmless)} safe reads, {len(loaded)} loaded names)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
