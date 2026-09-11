#!/usr/bin/env python3
"""
Check the Tech4TIME palette against WCAG 2.1 AA.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_contrast.py

Thresholds
  4.5:1  normal text (1.4.3)
  3.0:1  large text, and non-text UI component boundaries / focus indicators
         (1.4.11, 2.4.11)

Purely decorative surfaces -- hairline dividers between already-visible blocks,
gradient sweeps that never sit under text -- carry no contrast requirement and
are listed under DECORATIVE for information only.

Two values here differ from the palette in the project plan, because the plan's
originals fail AA and the plan also requires AA:

  --text-muted (light)  #8A8A8E -> #6A6A6E   (was 3.29:1 on bg-base)
  --text-muted (dark)   #7A7A7E -> #8A8A8E   (was 4.27:1 on bg-surface)

The plan's original #8A8A8E / #7A7A7E greys are retained, reassigned to
--border-strong, where the 3:1 component-boundary bar is the applicable one.

Link and focus colour in light mode is --accent-text #6A6C71 rather than the
gradient's end stop #6E7075, which lands at 4.39:1 on bg-surface. The end stop
keeps its plan value and is still what the gradient fills use.

THE PALETTE AND THE PAIRS COME FROM lib/contract.php
Both were typed here once, under a note reading "keep this in sync with
assets/css/theme.css". They are SETTINGS_COLOURS and SETTINGS_CONTRAST_PAIRS
now, because a person can change a colour from the editor and the editor has to
judge one too -- and two lists of "which pairs matter" is one more than the
number that can be right.

THE ARITHMETIC IS STILL THIS FILE'S OWN, DELIBERATELY. The data is shared; the
sums are not. That is the rule tools/publish_stub.py works to -- each half
checked against an independent implementation written from the description,
never against its own counterpart -- and it is why the last group below asks
the contract for its answer on all 38 pairs and compares it with the answer
worked out here. A shared list cannot drift. A shared bug could.
"""

import json
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

AA_TEXT = 4.5
AA_LARGE = 3.0

def contract(code: str):
    """Ask lib/contract.php for something, and refuse to guess if it cannot."""
    if not shutil.which("php"):
        raise SystemExit("php not found, and the palette lives in lib/contract.php:\n"
                         "  sudo apt install php-cli")

    out = subprocess.run(["php", "-r", "require 'lib/contract.php'; " + code],
                         cwd=ROOT, capture_output=True, text=True)

    if out.returncode != 0 or not out.stdout.strip():
        raise SystemExit("could not read the palette from lib/contract.php:\n"
                         + (out.stderr or out.stdout)[:400])

    return json.loads(out.stdout)


PALETTE = contract("echo json_encode(SETTINGS_COLOURS);")
LIGHT, DARK = PALETTE["light"], PALETTE["dark"]

# (foreground, backgrounds, role, threshold), in the contract's own order.
PAIRS = [(p["fg"], p["on"], p["role"], float(p["ratio"]))
         for p in contract("echo json_encode(SETTINGS_CONTRAST_PAIRS);")]

# No contrast requirement; reported so regressions stay visible.
DECORATIVE = [(p["fg"], p["on"], p["role"])
              for p in contract("echo json_encode(SETTINGS_CONTRAST_DECORATIVE);")]


def srgb_to_linear(c: float) -> float:
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def luminance(hex_colour: str) -> float:
    h = hex_colour.lstrip("#")
    r, g, b = (int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))
    return (
        0.2126 * srgb_to_linear(r)
        + 0.7152 * srgb_to_linear(g)
        + 0.0722 * srgb_to_linear(b)
    )


def ratio(fg: str, bg: str) -> float:
    a, b = luminance(fg), luminance(bg)
    lo, hi = sorted((a, b))
    return (hi + 0.05) / (lo + 0.05)


def check(name: str, t: dict) -> list[str]:
    print(f"\n{name}")
    print("-" * 76)
    failures = []

    for fg, bgs, role, threshold in PAIRS:
        kind = "AA" if threshold == AA_TEXT else "AA-large"
        worst = min(ratio(t[fg], t[bg]) for bg in bgs)
        for bg in bgs:
            r = ratio(t[fg], t[bg])
            ok = r >= threshold
            if not ok:
                failures.append(
                    f"{name}: {fg} on {bg} ({role}) = {r:.2f}:1, needs {threshold}"
                )
        status = "PASS" if worst >= threshold else "FAIL"
        print(f"  [{status}] worst {worst:5.2f}:1  (needs {threshold} {kind:8s})  "
              f"{fg}  — {role}")

    print("\n  decorative (no requirement):")
    for fg, bgs, role in DECORATIVE:
        worst = min(ratio(t[fg], t[bg]) for bg in bgs)
        print(f"         {worst:5.2f}:1   {fg}  — {role}")

    return failures


def agree() -> list[str]:
    """Every pair, worked out twice, by two implementations that share no code.

    The shared list is what stops the two going out of step about WHICH pairs
    matter. This is what stops them going out of step about the answer.
    """
    print("\nTHE SAME SUMS, DONE TWICE")
    print("-" * 76)

    theirs = contract(
        "$o = [];"
        "foreach (['light', 'dark'] as $m) {"
        "  foreach (SETTINGS_CONTRAST_PAIRS as $p) {"
        "    foreach ($p['on'] as $g) {"
        "      $o[$m . '/' . $p['fg'] . '/' . $g] = round(contract_contrast_ratio("
        "        SETTINGS_COLOURS[$m][$p['fg']], SETTINGS_COLOURS[$m][$g]), 4); } } }"
        "echo json_encode($o);")

    mine = {}
    for mode, table in (("light", LIGHT), ("dark", DARK)):
        for fg, bgs, _role, _threshold in PAIRS:
            for bg in bgs:
                mine[f"{mode}/{fg}/{bg}"] = round(ratio(table[fg], table[bg]), 4)

    if set(theirs) != set(mine):
        return [f"the two implementations disagree about which pairs exist: "
                f"{sorted(set(theirs) ^ set(mine))[:4]}"]

    apart = [f"{k}: contract says {theirs[k]}, this file says {mine[k]}"
             for k in mine if abs(theirs[k] - mine[k]) > 0.0001]

    print(f"  [{'PASS' if not apart else 'FAIL'}] {len(mine)} pairs, "
          f"contract_contrast_ratio() against this file's own ratio()")

    return apart


def main() -> None:
    failures = check("LIGHT MODE", LIGHT) + check("DARK MODE", DARK) + agree()

    print("\n" + "=" * 76)
    if failures:
        print(f"{len(failures)} pair(s) below AA:\n")
        for f in failures:
            print(f"  - {f}")
        sys.exit(1)
    print("All functional colour pairs meet WCAG AA in both modes,\n"
          "and both implementations of the sum agree on every one of them.")


if __name__ == "__main__":
    main()
