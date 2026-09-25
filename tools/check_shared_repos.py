#!/usr/bin/env python3
"""
Prove the two repositories still hold the same copy of everything shared.

Build/audit tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/check_shared_repos.py
    python3 tools/check_shared_repos.py --clone   # fetch the sibling (CI)

WHY THIS EXISTS, AND WHAT check_shared_lib.py CANNOT DO
`tools/check_shared_lib.py` compares each repository's files against a digest
committed in *that same repository*. Its own docstring is honest about the
consequence: edit `lib/html.php` in the backend, run `--update` there, and the
backend passes; the frontend passes too, against its own unchanged copy; and
the two now hold different sanitisers with every check green.

That is not a flaw in it -- a per-repo digest cannot see across repositories,
and it says so. But it means the *actual* property we care about, "these two
files are the same file", has never been checked anywhere. This checks it, the
only way it can be checked: by having both copies present at once.

So the two are complements, not rivals. Keep both:

    check_shared_lib.py    caught an accidental local edit, offline, in one repo
    check_shared_repos.py  catches the halves drifting apart, needing both

WHAT IT COMPARES

    The shared runtime files    html.php, contract.php, publish.php, svg.php,
                                markdown.php, store.php, throttle.php,
                                sprite.svg, base.css
                                -- read from check_shared_lib.py's own SHARED
                                map, so this file cannot fall behind that one.
                                COUNT THEM THERE, NOT HERE: this line said
                                "four" and omitted svg.php while the code was
                                already reading the map correctly, which is the
                                kind of drift that teaches people to distrust
                                the comment instead of the count.

    Every same-named tool       Any script OR README that exists in tools/ on
                                both sides must be byte-identical UNLESS it is
                                listed in DIVERGENT below with a reason.
                                See TOOL_SUFFIXES.

The second rule is deliberately the wrong way round from a list of "files to
keep in step". A list like that has to be remembered; this has to be argued
with. Copy a tool to the other repository and it is covered from that moment,
with no edit here. Let one drift and the run fails until somebody either fixes
it or writes down why the two differ.

WHICH COPY OF THE SIBLING IT COMPARES AGAINST
Beside this one, if it is there -- your working tree, uncommitted changes and
all, which is what you want while working. With --clone it is THE SAME BRANCH
in the sibling: a dev build compares against the sibling's dev, a main build
against its main.

That is not what the first version of this file did, and the difference cost a
red run. It cloned without naming a branch, which gets the repository's DEFAULT
branch -- main here -- so a push to dev compared new work against the last
RELEASED state of the other half and reported drift that was not drift. Two
branches that are supposed to differ are not evidence of anything.

If the sibling has no branch of that name, this falls back to its default and
says so in the output. A comparison against a different branch is worth having
and is worth not mistaking for the real one.

There is still an ordering consequence, and it is the honest one: a change
touching both halves fails here until BOTH are pushed to that branch. At that
moment the two halves genuinely disagree, and a publish between them would be
refused by CONTRACT_VERSION. Push the other half and it goes green. If a
two-repo change could pass with one half landed, this check would be saying the
halves match when it means "I only looked at one of them".

WHEN THE SIBLING IS NOT THERE
Exits 0 with a notice, the way the browser tests and test_qr.py do. This has to
be runnable on a machine with one repository checked out; it is CI that has
both, and CI is where the answer matters.
"""

import argparse
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ORG = "https://github.com/M-s-Tech4TIME"

# Same-named tools that are SUPPOSED to differ, and why. A reason is required:
# an entry with no argument behind it is how "these two drifted" becomes "these
# two are meant to be different" without anybody noticing it happened.
# What counts as a tool for the comparison below. .md is in here because
# tools/README.md was not: the backend's copy was the frontend's file with one
# line changed, and so it told you to run three scripts that do not exist there
# and listed two subdirectories it does not have. Nothing compared them,
# because prose was not a tool.
TOOL_SUFFIXES = {".py", ".php", ".md"}

DIVERGENT = {
    "build_deploy_set.py":
        "different upload sets -- the frontend ships pages/ and assets/, the "
        "backend ships public/, lib/ and sections/",
    "README.md":
        "each half's own gate and its own subdirectories -- the frontend has "
        "pages to audit and artwork to build, the backend has neither, and "
        "only the backend has admin-cli.php",
    "check_secrets.py":
        "asserts different things -- the frontend proves it has no NAME for a "
        "password hash, the backend proves the accounts are unreachable",
    "dev-router.php":
        "different document roots: the repository here, public/ there",
    "host-probe.php":
        "different private store name and path arithmetic -- t4t-private one "
        "level up, t4t-private-admin two",
    "serve.py":
        "different ports, and a different list of pages to print",
    "verify_live.py":
        "the frontend expects 403 for lib/ and the backend expects 404, which "
        "is the whole of ADR 0018 and must never be reconciled",
}


def half() -> str:
    """Which repository this is, by looking rather than by being told."""
    if (ROOT / "lib" / "admin.php").is_file():
        return "backend"
    if (ROOT / "pages").is_dir():
        return "frontend"
    raise SystemExit("neither an admin nor any pages here -- this is not one of the two")


def shared_map() -> dict:
    """check_shared_lib.py's SHARED, read from the file rather than copied.

    Importing it would be tidier and would also make this tool fail to load if
    that one is mid-edit. Reading the literal keeps them coupled in the one
    direction that matters -- add a file there, it is compared here -- without
    coupling their execution.
    """
    src = (ROOT / "tools" / "check_shared_lib.py").read_text(encoding="utf-8")
    start = src.index("SHARED = {")
    end = src.index("}", start) + 1
    namespace: dict = {}
    exec(src[start:end], namespace)          # noqa: S102 -- a dict literal we just sliced
    return namespace["SHARED"]


def locate(root: Path, places) -> Path | None:
    return next((root / p for p in places if (root / p).is_file()), None)


def branch() -> str:
    """The branch whose work this is, which is not always the ref being built.

    On a pull request GITHUB_REF_NAME is "<number>/merge" -- a synthetic ref
    that exists in no other repository. Cloning it from the sibling fails, the
    fallback fetches the sibling's default branch, and a PR from dev into main
    ends up comparing new work against the other half's last RELEASE. That is
    the same mistake as cloning without a branch at all, arriving by a
    different road.

    GITHUB_HEAD_REF is the branch the pull request came FROM, and it is the one
    that carries the matching change on the other side. So:

        push to dev            -> the sibling's dev
        push to main           -> the sibling's main
        pull request dev->main -> the sibling's dev

    The third is the useful one: at that moment the sibling's main has not been
    merged yet by definition, and comparing against it could only ever fail.
    """
    head = os.environ.get("GITHUB_HEAD_REF", "").strip()
    if head:
        return head
    ref = os.environ.get("GITHUB_REF_NAME", "").strip()
    if ref:
        return ref
    r = subprocess.run(["git", "rev-parse", "--abbrev-ref", "HEAD"],
                       capture_output=True, cwd=ROOT)
    return r.stdout.decode().strip() or "HEAD"


def sibling(other: str, clone: bool) -> tuple[Path, str] | None:
    """The other repository: beside this one, or cloned into a temp directory."""
    name = f"tech4time-website-{other}"

    beside = ROOT.parent / name
    if (beside / "tools").is_dir():
        return beside, f"{beside}"

    if not clone:
        return None

    tmp = Path(tempfile.mkdtemp(prefix="t4t-sibling-"))
    url = f"{ORG}/{name}.git"
    want = branch()

    # The SAME branch, named explicitly. Cloning without --branch gets the
    # repository's default, which is main -- so a dev build would compare its
    # new work against the other half's last release and call the difference
    # drift. That is the bug this argument exists to prevent.
    r = subprocess.run(
        ["git", "clone", "--depth", "1", "--quiet", "--branch", want,
         url, str(tmp / name)],
        capture_output=True,
    )
    if r.returncode == 0:
        return tmp / name, f"{url} ({want})"

    # No such branch over there. Falling back to its default is still worth
    # something, but it is a different comparison and must not read like the
    # one that was asked for.
    r = subprocess.run(
        ["git", "clone", "--depth", "1", "--quiet", url, str(tmp / name)],
        capture_output=True,
    )
    if r.returncode != 0:
        print(f"could not clone {url}:\n{r.stderr.decode()[:400]}")
        return None

    print(f"NOTE: the {other} has no branch {want!r}; comparing against its")
    print("      default branch instead. This is a weaker check than the one")
    print("      asked for -- two different branches may differ legitimately.\n")
    return tmp / name, f"{url} (default branch, {want!r} not found)"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--clone", action="store_true",
                    help="fetch the sibling repository if it is not beside this one")
    args = ap.parse_args()

    this = half()
    other = "frontend" if this == "backend" else "backend"

    found = sibling(other, args.clone)
    if found is None:
        print(f"The {other} is not beside this repository, so there is nothing to")
        print("compare against. This check needs both halves present.")
        print(f"\n  git clone {ORG}/tech4time-website-{other}.git ../tech4time-website-{other}")
        print("  or run with --clone, which is what CI does.")
        return 0

    there, where = found
    print(f"this is the {this}; comparing against {where}\n")

    problems: list[str] = []
    compared = 0

    # ---------------------------------------------- the shared runtime files
    for name, places in sorted(shared_map().items()):
        here = locate(ROOT, places)
        yonder = locate(there, places)

        if here is None or yonder is None:
            missing = "here" if here is None else f"in the {other}"
            problems.append(f"{name}: named as shared, and is not {missing}")
            continue

        if here.read_bytes() != yonder.read_bytes():
            problems.append(
                f"{name}: DIFFERS between the halves "
                f"({here.relative_to(ROOT)} vs {yonder.relative_to(there)})"
            )
        else:
            compared += 1
            print(f"  ok    {name:<16} {here.relative_to(ROOT)}")

    # ------------------------------------------------- every same-named tool
    print()
    mine = {p.name for p in (ROOT / "tools").iterdir()
            if p.is_file() and p.suffix in TOOL_SUFFIXES}
    theirs = {p.name for p in (there / "tools").iterdir()
              if p.is_file() and p.suffix in TOOL_SUFFIXES}

    for name in sorted(mine & theirs):
        a = (ROOT / "tools" / name).read_bytes()
        b = (there / "tools" / name).read_bytes()
        same = a == b

        if name in DIVERGENT:
            if same:
                problems.append(
                    f"tools/{name}: listed in DIVERGENT but the two copies are now "
                    f"identical. Either the difference was resolved -- remove the "
                    f"entry -- or a copy overwrote one half."
                )
            else:
                print(f"  ok    tools/{name:<24} differs, by design")
            continue

        if same:
            compared += 1
            print(f"  ok    tools/{name:<24} identical")
        else:
            problems.append(
                f"tools/{name}: the two copies have DRIFTED. Either copy the "
                f"intended one across, or add it to DIVERGENT in this file with "
                f"the reason they are meant to differ."
            )

    print()
    if problems:
        print(f"check_shared_repos: {len(problems)} problem(s)\n")
        for line in problems:
            print(f"  FAIL  {line}")
        print(
            "\nThe two halves disagree about a file they are supposed to hold\n"
            "identically. Fix the file, not this check -- and remember that\n"
            "check_shared_lib.py --update has to be re-run in BOTH repositories\n"
            "when one of the shared runtime files changes.\n"
        )
        return 1

    declared = len(set(DIVERGENT) & mine & theirs)
    print(f"check_shared_repos: {compared} files identical across both halves, "
          f"{declared} divergent by design")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
