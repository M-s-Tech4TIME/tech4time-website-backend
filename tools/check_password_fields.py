#!/usr/bin/env python3
"""
Refuse to ship a password field with no show/hide switch.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/check_password_fields.py
    python3 tools/check_password_fields.py -v    # list every field it found

WHY THIS EXISTS
A password field with no switch beside it looks exactly like a password field
that never had one. There is no error, no blank box, nothing in the console --
the field simply works the way fields worked before, and the only way to notice
is for somebody to go looking for a control they expected and not find it.

That is the same shape of fault as an icon name the sprite does not carry, and
it is caught the same way: by asking whether every member of a set has the
thing the set is supposed to have, mechanically, on every push.

THE ADMIN HAS TWELVE OF THEM, ACROSS FOUR FILES, and three of those files are
the sign-in, the first-run setup and the password reset -- pages a person meets
once or twice and never returns to, which is precisely where a missing control
goes unreported for a release. The seventh field on the account screen was
itself missed by a hand count during planning.

WHAT IT REQUIRES
Every `type="password"` input must

  1. have an id, because the switch is wired to it by id and nothing else;
  2. sit inside a <div class="admin__password"> wrapper, which is what
     reserves the room on the trailing edge before first paint; and
  3. be followed by admin_password_toggle('<that same id>') inside the wrapper.

THE ID IS MATCHED, NOT MERELY COUNTED. A wrapper holding a toggle that names a
different field is the failure that looks most like success: the page renders,
the button appears in the right place, and it reveals the wrong box -- or
nothing, because admin-password.js leaves a switch hidden when the id it is
given is not there. Counting would pass that. Comparing the names does not.

WHAT IT DELIBERATELY DOES NOT DO
It does not parse PHP. It reads the markup as text, which is what the markup is
-- every one of these fields is a literal in a template, not something built at
run time, and if that ever stops being true this check should be the thing that
notices rather than the thing that quietly stops looking.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Where a password field can legitimately live: the four entry points under
# public/ and the fourteen screens. Everything else is library code.
TREES = ("public", "sections")

PASSWORD_INPUT = re.compile(r"<input\b[^>]*\btype=\"password\"[^>]*>", re.S)
ID_ATTR = re.compile(r"\bid=\"([^\"]+)\"")
WRAPPER_OPEN = '<div class="admin__password">'
TOGGLE_CALL = re.compile(r"admin_password_toggle\(\s*'([^']+)'\s*\)")


def php_files() -> list[Path]:
    out: list[Path] = []
    for tree in TREES:
        out += sorted((ROOT / tree).rglob("*.php"))
    return out


def line_of(text: str, index: int) -> int:
    return text.count("\n", 0, index) + 1


def main() -> int:
    verbose = "-v" in sys.argv
    problems: list[str] = []
    found = 0

    for path in php_files():
        text = path.read_text()
        where = path.relative_to(ROOT)

        for m in PASSWORD_INPUT.finditer(text):
            found += 1
            line = line_of(text, m.start())
            ident = ID_ATTR.search(m.group(0))

            if not ident:
                problems.append(
                    f"{where}:{line} a password field with no id -- the switch "
                    f"is wired by id and has nothing to point at")
                continue
            ident = ident.group(1)

            # The wrapper must open before the input, with nothing but
            # whitespace between the two.
            before = text[:m.start()]
            opened = before.rstrip().endswith(WRAPPER_OPEN)

            # The toggle must follow inside the same wrapper: the next thing
            # after the input, before the wrapper closes.
            after = text[m.end():]
            closes = after.find("</div>")
            inside = after[:closes] if closes != -1 else after
            call = TOGGLE_CALL.search(inside)

            if not opened:
                problems.append(
                    f"{where}:{line} #{ident} is not inside a "
                    f"<div class=\"admin__password\"> wrapper")
            if not call:
                problems.append(
                    f"{where}:{line} #{ident} has no admin_password_toggle() "
                    f"beside it -- the field ships with no switch")
            elif call.group(1) != ident:
                problems.append(
                    f"{where}:{line} #{ident} is wrapped with a switch naming "
                    f"{call.group(1)!r} -- it would reveal the wrong field, or "
                    f"nothing at all")
            elif verbose:
                print(f"  ok    {where}:{line}  #{ident}")

    print(f"\ncheck_password_fields: {found} password field(s) "
          f"in {len(php_files())} file(s)")

    if problems:
        print(f"\n{len(problems)} password field(s) would ship without a "
              f"working switch:\n")
        for line in problems:
            print(f"  FAIL  {line}")
        print("\nWrap the input and put the switch beside it:\n"
              "\n"
              "    <div class=\"admin__password\">\n"
              "      <input class=\"admin__input\" id=\"pw-new\" name=\"password\"\n"
              "             type=\"password\" autocomplete=\"new-password\" required>\n"
              "      <?= admin_password_toggle('pw-new') ?>\n"
              "    </div>\n"
              "\n"
              "admin_password_toggle() is in lib/admin.php, beside admin_icon().")
        return 1

    print("Every password field carries a switch naming its own input.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
