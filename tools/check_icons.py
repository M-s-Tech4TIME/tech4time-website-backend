#!/usr/bin/env python3
"""
Refuse to ship an icon name that draws nothing.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/check_icons.py

WHY THIS EXISTS
An icon is drawn as `<use href="#name">` against a sprite of symbols. A name
the sprite does not carry is not an error: the browser resolves the reference
to nothing and paints nothing, with no console line and no failed request. The
markup is there, the box is there, and the glyph is simply absent.

That shipped. `ADMIN_SECTIONS` named `cog` for the Settings screen; the sprite
had `cogs` and no `cog`; and the rail row and the Overview tile were blank for
a release. Nobody could have found it by reading the code -- every file was
individually right, and the fault was only in whether three lists agreed.

THREE LISTS HAVE TO AGREE, AND THIS ASKS ALL THREE
    the sprite      the symbol exists at all
    *_ICONS         the editor may choose it, or a renderer may draw it
    ADMIN_ICONS     the admin inlines it into the page  (backend only)

A name missing from the first draws nothing anywhere. A name missing from the
third draws nothing IN THE ADMIN ONLY -- the live preview beside a picker comes
up empty while the public page is fine, which is the harder half to notice.

RUNS IN BOTH HALVES, FROM ONE FILE
The sprite sits at a different path in each and only the backend has an admin,
so this probes for both rather than being two files that drift apart. It is the
same file in both repositories and tools/check_shared_repos.py holds it there.
"""

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# The frontend serves the sprite from assets/; the backend from public/assets/.
SPRITE_PATHS = ["assets/icons/sprite.svg", "public/assets/icons/sprite.svg"]

# Every list in the shared contract that names an icon. The pickers are
# name => human label, so the KEY is the icon; the last three are the other way
# round -- a field kind or a hostname => the icon it draws -- so the VALUE is.
BY_KEY = ["CONTACT_ICONS", "COMPANY_ICONS", "ABOUT_ICONS", "HOME_ICONS",
          "SERVICES_ICONS", "CERTIFICATIONS_ICONS", "CHROME_BAR_ICONS"]
BY_VALUE = ["CHROME_CONTACT_ICONS", "CHROME_SOCIAL_ICONS"]
SINGLE = ["CHROME_SOCIAL_FALLBACK"]


def php(code: str) -> str:
    """Ask lib/ for a list rather than keeping a second copy of it here."""
    result = subprocess.run(
        ["php", "-r", "declare(strict_types=1); " + code],
        capture_output=True, text=True, cwd=str(ROOT),
    )
    if result.returncode != 0:
        raise SystemExit("php could not read a list this check needs:\n"
                         + result.stderr.strip()[:400])
    return result.stdout.strip()


def sprite_symbols() -> tuple[Path, set[str]]:
    for rel in SPRITE_PATHS:
        path = ROOT / rel
        if path.is_file():
            return path, set(re.findall(r'<symbol id="([^"]+)"', path.read_text()))
    raise SystemExit("no icon sprite found at " + " or ".join(SPRITE_PATHS))


def contract_lists() -> dict[str, list[str]]:
    code = (
        "require 'lib/contract.php'; $o = [];"
        " foreach ([" + ",".join(f"'{n}'" for n in BY_KEY) + "] as $n) {"
        "   $a = (array)constant($n);"
        "   $o[$n] = array_is_list($a) ? $a : array_keys($a); }"
        " foreach ([" + ",".join(f"'{n}'" for n in BY_VALUE) + "] as $n) {"
        "   $o[$n] = array_values((array)constant($n)); }"
        " foreach ([" + ",".join(f"'{n}'" for n in SINGLE) + "] as $n) {"
        "   $o[$n] = [(string)constant($n)]; }"
        " echo json_encode($o);"
    )
    return json.loads(php(code))


def admin_lists() -> tuple[list[str], dict[str, str]] | None:
    """ADMIN_ICONS and the rail/tile registry, or None in the frontend."""
    if not (ROOT / "lib" / "admin.php").is_file():
        return None
    inlined = json.loads(php("require 'lib/admin.php'; echo json_encode(ADMIN_ICONS);"))
    registry = json.loads(php(
        "require 'lib/admin.php'; $o = [];"
        " foreach (ADMIN_SECTIONS as $k => $v) { $o[$k] = (string)($v['icon'] ?? ''); }"
        " echo json_encode($o);"))
    return inlined, registry


def main() -> int:
    sprite, have = sprite_symbols()
    lists = contract_lists()
    admin = admin_lists()
    half = "backend" if admin else "frontend"

    problems: list[str] = []
    asked = 0

    print(f"{sprite.relative_to(ROOT)}: {len(have)} symbols  ({half})\n")

    for name, icons in lists.items():
        asked += len(icons)
        gone = [i for i in icons if i not in have]
        print(f"  {'FAIL' if gone else 'ok  '}  {name:<24} {len(icons):>3} names"
              + (f"   not in the sprite: {', '.join(gone)}" if gone else ""))
        for icon in gone:
            problems.append(f"{name} offers {icon!r}, which the sprite does not carry")

    if admin:
        inlined, registry = admin
        asked += len(inlined)
        gone = sorted(i for i in inlined if i not in have)
        print(f"  {'FAIL' if gone else 'ok  '}  {'ADMIN_ICONS':<24} {len(inlined):>3} names"
              + (f"   not in the sprite: {', '.join(gone)}" if gone else ""))
        problems += [f"ADMIN_ICONS inlines {i!r}, which the sprite does not carry"
                     for i in gone]

        # A rail row's icon has to be in BOTH: the sprite carries the glyph,
        # ADMIN_ICONS is what puts it on the page. Missing from either and the
        # row and its Overview tile are blank.
        bad = []
        for section, icon in registry.items():
            if not icon:
                bad.append(f"{section} names no icon at all")
            elif icon not in have:
                bad.append(f"{section} names {icon!r}, which the sprite does not carry")
            elif icon not in inlined:
                bad.append(f"{section} names {icon!r}, which ADMIN_ICONS does not inline")
        print(f"  {'FAIL' if bad else 'ok  '}  {'ADMIN_SECTIONS':<24} "
              f"{len(registry):>3} rail rows and tiles")
        for line in bad:
            print(f"          {line}")
        problems += bad

        # Every icon a picker offers gets a live preview beside it in the
        # editor, and a preview is drawn from ADMIN_ICONS rather than from the
        # sprite directly. A name the editor can choose but the admin does not
        # inline is an empty box in the admin and a correct glyph on the site.
        for name, icons in lists.items():
            gone = [i for i in icons if i not in inlined]
            if gone:
                print(f"  FAIL  {name:<24} not inlined by the admin: {', '.join(gone)}")
                problems += [f"{name} offers {i!r}, which ADMIN_ICONS does not inline"
                             for i in gone]

    print(f"\ncheck_icons: {asked} names asked of {len(have)} symbols")
    if problems:
        print(f"\n{len(problems)} icon(s) would draw nothing:\n")
        for line in problems:
            print(f"  FAIL  {line}")
        print("\nAn icon name the sprite does not carry paints NOTHING, with no\n"
              "error and no console line. Add the symbol in\n"
              "tools/build_icon_sprite.py (EXTRA_NAMES) and rebuild, or use a\n"
              "name the sprite already has.")
        return 1

    print("Every icon name resolves to a symbol.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
