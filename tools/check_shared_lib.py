#!/usr/bin/env python3
"""
Prove the files both repositories must hold identically have not been edited.

Build/audit tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/check_shared_lib.py            # assert
    python3 tools/check_shared_lib.py --update   # re-record, after a deliberate change

WHAT IS SHARED, AND WHY EACH ONE IS

    lib/html.php      the sanitiser. The backend sanitises what it stores; the
                      frontend sanitises again what it receives, because a
                      signature proves origin and not safety. Two sanitisers
                      that disagree mean the second one is not a check.

    lib/contract.php  the shape of a document. A field the two spell
                      differently is a field one of them loses on the next save.

    lib/publish.php   the wire format. Disagree here and nothing publishes at
                      all — which is the least bad of the five, because it
                      fails loudly on the first attempt.

    lib/svg.php       what a safe vector file is. The backend sanitises an
                      uploaded SVG and sends its own output; the frontend
                      proves the bytes are already that output by sanitising
                      them again and checking nothing moved. Two sanitisers
                      that disagree mean the second one is not a check — and,
                      worse than html.php, mean the receiving host either
                      rejects a legitimate logo or stores bytes under a name
                      computed from different ones.

    sprite.svg        the icon set. CONTACT_ICONS is in the contract, so the
                      backend offers a fixed list of icons and the frontend
                      has to be able to DRAW every one of them. A sprite that
                      has drifted means a reach row the editor previewed
                      correctly and the public page renders as an empty box —
                      which nothing else here would notice, because both
                      halves would be behaving exactly as written.

WHAT THIS CHECK IS WORTH, HONESTLY
Not much on its own, and it is important to say so where somebody will read it.

Each repository compares its own files against its own committed digest. Edit
lib/html.php in the backend, run --update there, and the backend passes; the
frontend also passes, against its own unchanged copy; and the two now hold
different sanitisers with every check green. A digest compared separately on
each side can only catch an ACCIDENTAL local edit — a hand-patch on a server,
a merge that went sideways, a file half-copied.

The real guarantee is at run time, on the real path, on the day: every
published payload carries contract_version, and the receiving side refuses a
version it does not implement rather than writing a document it would then
mis-render. That check fires against what was actually sent, by the side that
would suffer from the mismatch. See lib/publish.php and
docs/90-decisions/0011-two-repositories.md.

So: this is hygiene. Do not let it stand in for the version.
"""

import argparse
import hashlib
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MANIFEST = ROOT / "tools" / "shared-lib.sha256"

# Byte-identical in tech4time-website-frontend and tech4time-website-backend. Adding a file
# here means adding it to BOTH repositories and re-recording in both.
#
# Recorded by a LOGICAL NAME, with the places it may live listed after it. The
# two halves do not put everything in the same place — the frontend's document
# root is the repository and the backend's is public/, so the sprite is at
# assets/icons/ in one and public/assets/icons/ in the other. Recording the
# path would make the two manifests differ and the comparison meaningless;
# recording the name keeps this file itself identical in both.
SHARED = {
    "html.php":     ["lib/html.php"],
    "contract.php": ["lib/contract.php"],
    "publish.php":  ["lib/publish.php"],
    "svg.php":      ["lib/svg.php"],
    "sprite.svg":   ["assets/icons/sprite.svg", "public/assets/icons/sprite.svg"],
}


def located() -> dict[str, Path]:
    """Where each shared file is in THIS repository."""
    out = {}
    for name, places in SHARED.items():
        here = next((ROOT / p for p in places if (ROOT / p).is_file()), None)
        if here is None:
            raise SystemExit(
                f"{name} is named as a shared file and is in none of "
                f"{places} in this repository.\n"
                f"Both halves must hold it, or neither should list it."
            )
        out[name] = here
    return out


def digests() -> dict[str, str]:
    return {name: hashlib.sha256(path.read_bytes()).hexdigest()
            for name, path in located().items()}


def recorded() -> dict[str, str]:
    if not MANIFEST.is_file():
        return {}
    out = {}
    for line in MANIFEST.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        digest, _, rel = line.partition("  ")
        out[rel.strip()] = digest.strip()
    return out


def write(now: dict[str, str]) -> None:
    lines = [
        "# The files tech4time-website-frontend and tech4time-website-backend hold identically.",
        "# Re-record with: python3 tools/check_shared_lib.py --update",
        "# Then copy the changed file AND this manifest to the other repository.",
        "#",
        "# This catches an accidental edit and nothing more — see the note at the",
        "# top of tools/check_shared_lib.py. The guarantee is CONTRACT_VERSION,",
        "# checked at run time by the side receiving the payload.",
        "",
    ]
    lines += [f"{now[name]}  {name}" for name in SHARED]
    MANIFEST.write_text("\n".join(lines) + "\n")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--update", action="store_true",
                    help="re-record the digests after a deliberate change")
    args = ap.parse_args()

    now = digests()

    if args.update:
        write(now)
        print(f"Recorded {len(SHARED)} digests in {MANIFEST.relative_to(ROOT)}\n")
        for name in SHARED:
            print(f"  {now[name][:16]}…  {name}")
        print("\nNow copy the changed file AND this manifest into the other")
        print("repository, and bump CONTRACT_VERSION if the SHAPE changed.")
        return

    was = recorded()

    if not was:
        raise SystemExit(
            f"{MANIFEST.relative_to(ROOT)} is missing. Run --update to create it."
        )

    problems = []

    where = located()

    for name in SHARED:
        if name not in was:
            problems.append(f"{name} is shared but not recorded — run --update")
        elif was[name] != now[name]:
            problems.append(
                f"{name} has changed since it was recorded\n"
                f"          at        {where[name].relative_to(ROOT)}\n"
                f"          recorded  {was[name][:16]}…\n"
                f"          now       {now[name][:16]}…\n"
                f"          If this was deliberate: run --update, copy the file and\n"
                f"          the manifest to the other repository, and bump\n"
                f"          CONTRACT_VERSION if the SHAPE of a document changed."
            )

    for name in sorted(set(was) - set(SHARED)):
        problems.append(f"{name} is recorded but no longer listed as shared — run --update")

    for name in SHARED:
        print(f"  {now[name][:16]}…  {name:<13} {where[name].relative_to(ROOT)}"
              + ("" if was.get(name) == now[name] else "   CHANGED"))

    if problems:
        print(f"\ncheck_shared_lib: {len(problems)} problem(s)\n")
        for p in problems:
            print(f"  FAIL  {p}")
        sys.exit(1)

    print(f"\nThe {len(SHARED)} shared files are as recorded.")


if __name__ == "__main__":
    main()
