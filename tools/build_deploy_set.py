#!/usr/bin/env python3
"""
Build the set of files that goes to the web server, and prove what is in it.

Build/deploy tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/build_deploy_set.py --check        # assert, print, change nothing
    python3 tools/build_deploy_set.py --out _deploy  # build it

WHY THIS EXISTS
An upload set described by --exclude flags is correct only as long as everybody
types all of them, and the flags are not equally important: most save
bandwidth, and one is the only thing standing between a deploy and every job
post the client has written. There is no way to tell them apart by looking, and
the day one is dropped everything keeps working and the loss is silent.

So the set is built here instead, and CI rsyncs a directory rather than
assembling a rule. What may be uploaded stops being something to remember.

WHAT THE TARGET IS, AND WHY IT IS NOT THE DOCUMENT ROOT
This half deploys to /home/USER/admin.tech4time.bd/, and admin.tech4time.bd's document
root is /home/USER/admin.tech4time.bd/public/ — one level inside it. So the upload set
carries lib/ and sections/ as well as public/, and none of them is reachable
over HTTP because none of them is inside the document root. See ADR 0018.

That also means rsync --delete runs against a directory holding content/, the
system of record. It is protected the same way the frontend's is: never synced,
seeded once with --ignore-existing, and named in the deploy's protect list.

WHY AN ALLOW LIST, NOT AN IGNORE LIST
The two fail in opposite directions. Under an ignore list a new file in the
repository root ships unless somebody thought to exclude it, and the day that
file is a key, a dump or a note-to-self, it is on the internet. Under an allow
list it stays behind unless somebody thought to include it, and the day that
file is a new page, the page 404s.

One of those is discovered by a visitor; the other by a stranger. UPLOAD is
therefore exhaustive, and anything not named in it does not go.

CONTENT IS NOT PART OF THE SET
content/ is the client's data — job posts and contact details typed into
the admin — and the repository's copy is test data. It is
never synced. But the first deploy has to put something there or the two
dynamic pages have nothing to render, so it is built separately, into seed/,
and CI copies that with rsync --ignore-existing: it creates what is absent and
overwrites nothing. A file that exists on the host has been edited by somebody
and wins, permanently, without anyone deciding so on the day.
"""

import argparse
import fnmatch
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Everything that goes to the document root, named. Nothing else does.
# A directory here brings its contents, minus DENY below.
UPLOAD = [
    "public/",            # THE DOCUMENT ROOT — its .htaccess, the six entry
                          # points, and the assets a browser fetches
    "lib/",               # outside it: the sign-in, the contract, the client
    "sections/",          # outside it: included by public/index.php, never fetched
]

# Refused anywhere inside the above. Each is a thing that would otherwise be
# carried along by the directory it sits in.
DENY = [
    "*.md",               # documentation
    "*.py",               # tools that happen to sit beside site files
    "*.key",              # secret.key or publish.key, if one strays into the tree
    "admins.json",        # password hashes, likewise
    "setup-token.txt",
    "public/uploads/*",   # every picture the editor has ever accepted. This
                          # host is where they are AUTHORED, so the repository
                          # has none of them — and public/ ships wholesale, so
                          # without this line a deploy with --delete would
                          # remove the lot and report success. ADR 0019.
    "*.bak",              # content backups written by store_write()
    "*.tmp",
    ".DS_Store",
    "__pycache__/*",
]

# Absence is a broken site rather than a missing feature, so it is an error
# and not a warning. .htaccess is first for a reason: it is a dotfile, and
# both FTP clients and zip tools have been seen to drop it silently, taking
# the block on lib/ and content/ with it and leaving a site that looks fine.
REQUIRED = [
    "public/.htaccess",              # headers, and the blanket noindex
    "public/index.php",
    "public/login.php",
    "public/setup.php",
    "public/assets/css/admin.css",
    "public/assets/icons/sprite.svg",   # read from disk and inlined by lib/admin.php
    # The scripts, named one by one rather than left to the walk over public/.
    # Every one of them is an ENHANCEMENT, so the admin still works with any of
    # them missing — which is exactly why nothing would fail if a deploy
    # dropped one. Losing admin-swap.js puts every link back to a full page
    # load and reports success.
    "public/assets/js/theme-init.js",
    "public/assets/js/theme-toggle.js",
    "public/assets/js/admin-nav.js",
    "public/assets/js/editor.js",
    "public/assets/js/admin-outline.js",
    "public/assets/js/admin-swap.js",
    "public/assets/js/admin-toast.js",
    "public/assets/js/admin-dialog.js",
    "public/assets/js/admin-forms.js",
    "public/assets/js/admin-init.js",
    "lib/private.php",
    "lib/auth.php",
    "lib/contract.php",              # the shape both halves agree on
    "lib/publish.php",               # and the format they agree it travels in
    "lib/about.php",                 # the about editor's model
    "lib/home.php",                  # and the home editor's
    "lib/publish_client.php",        # without it a save writes and never sends
    "sections/careers.php",
    "sections/contact.php",
    "sections/company.php",
    "sections/about.php",
    "sections/home.php",
    # These four were never added as each editor landed. They still shipped --
    # sections/ is not in FORBIDDEN_TREES, so the sweep picked them up -- but
    # the assertion read as though it covered every editor and did not. A list
    # that is silently incomplete is worse than no list.
    "sections/services.php",
    "sections/certifications.php",
    "sections/branding.php",
    "sections/privacy.php",
    "lib/privacy.php",               # the privacy editor's model
]

# Never in the set, whatever else changes. Stated separately from "not in
# UPLOAD" because that is the claim worth failing on out loud.
FORBIDDEN_TREES = ["content", "tools", "docs", "references", ".git", ".claude",
                   "deploy", "pages", "api", "public/uploads"]

SEED = ROOT / "deploy" / "seed"
CONTRACT = ROOT / "lib" / "contract.php"


def documents() -> list[str]:
    """Every document there is, read out of lib/contract.php.

    NOT A LIST KEPT HERE. A second list is a list that goes out of step, and
    this one went out of step in the way that does not announce itself: the
    company profile got a model, an editor, a renderer, tests and six documents,
    and the one line that put it in the seed was never written. Nothing failed.
    The admin on the live host simply came up with an empty company form over a
    page holding seventy-seven rows, and Save would have published the empty one
    over it.

    So the set of documents comes from the file that defines the set of
    documents, and adding one to CONTRACT_DOCUMENTS is the whole of it.
    """
    text = CONTRACT.read_text(encoding="utf-8")
    found = re.search(r"const\s+CONTRACT_DOCUMENTS\s*=\s*\[(.*?)\]", text, re.S)

    if not found:
        raise SystemExit(
            "lib/contract.php: could not find CONTRACT_DOCUMENTS. The seed is "
            "built from it, so this cannot be guessed at.")

    names = re.findall(r"'([a-z0-9_-]+)'", found.group(1))

    if not names:
        raise SystemExit("lib/contract.php: CONTRACT_DOCUMENTS is empty")

    return names


def seed_source(name: str) -> Path:
    """Which file seeds a fresh host with this document.

    deploy/seed/<name>.json when there is one, and that is the exception rather
    than the rule: careers has one because a new host must start with NO job
    posts while keeping the site-wide settings around them, so its seed is a
    deliberately emptied document that is committed and reviewed.

    Everything else seeds from content/<name>.json — the real thing. A contact
    page or a company profile has no meaningful empty state: the page renders
    either way, and rendering it empty is not a fresh start, it is a blank page
    where the site used to be.
    """
    special = SEED / f"{name}.json"
    return special if special.is_file() else ROOT / "content" / f"{name}.json"


def denied(rel: str) -> bool:
    return any(fnmatch.fnmatch(rel, p) or fnmatch.fnmatch(Path(rel).name, p)
               for p in DENY)


def members() -> list[str]:
    """Every path in the upload set, relative to the document root."""
    out = []

    for entry in UPLOAD:
        src = ROOT / entry.rstrip("/")

        if not src.exists():
            raise SystemExit(f"UPLOAD names {entry!r}, which is not in the repository.")

        if src.is_file():
            if not denied(entry):
                out.append(entry)
            continue

        for path in sorted(src.rglob("*")):
            if not path.is_file():
                continue
            rel = path.relative_to(ROOT).as_posix()
            if not denied(rel):
                out.append(rel)

    return out


def build(out_dir: Path) -> list[str]:
    site = out_dir / "site"
    seed = out_dir / "seed"

    for d in (site, seed):
        if d.exists():
            shutil.rmtree(d)
        d.mkdir(parents=True)

    paths = members()

    for rel in paths:
        dst = site / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / rel, dst)

    # A BRAND NEW backend starts empty. This is not the migration path — if
    # the public site already has content, that content is the record and must
    # be copied into this host's content/ before the first save, or the first
    # save will publish an empty document over it. See
    # docs/20-deployment/first-deploy.md.
    #
    # Every document, from the contract. See documents() for why this is not a
    # list of three copy calls, one of which was missing for a fortnight.
    for name in documents():
        source = seed_source(name)

        if not source.is_file():
            raise SystemExit(
                f"{name} is in CONTRACT_DOCUMENTS but neither "
                f"deploy/seed/{name}.json nor content/{name}.json exists. A "
                f"fresh host would have nothing to render it from, and the "
                f"first save in the editor would publish an empty document "
                f"over the live page.")

        shutil.copy2(source, seed / f"{name}.json")

    return paths


def check(paths: list[str], out_dir: Path) -> tuple[int, int]:
    """Assert everything, and report (run, failed).

    It used to report only the failures and let main() work the total out with
    arithmetic over the constant lists. That is a count of the checks somebody
    remembered to include in the sum, not of the checks that ran — and it went
    wrong the moment a loop was added, quietly reporting fewer than it did. The
    counter is where the counting happens now.
    """
    failed = []
    run = 0

    def assert_(case: str, ok: bool, detail: str = "") -> None:
        nonlocal run
        run += 1
        if ok:
            return
        failed.append(case)
        print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    for tree in FORBIDDEN_TREES:
        inside = [p for p in paths if p == tree or p.startswith(tree + "/")]
        assert_(f"{tree}/ is not in the upload set", not inside,
                f"{len(inside)} file(s), first: {inside[0] if inside else ''}")

    for rel in REQUIRED:
        assert_(f"{rel} is in the upload set", rel in paths)

    for pattern in DENY:
        hit = [p for p in paths if denied(p) and fnmatch.fnmatch(p, pattern)]
        assert_(f"nothing matching {pattern!r} survived", not hit,
                f"first: {hit[0] if hit else ''}")

    # Asked of the repository rather than listed above, so a library added
    # tomorrow is covered without anyone editing this file. A missing lib/ is
    # a 500 on the page that requires it and nothing at all on the others.
    for php in sorted((ROOT / "lib").glob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        assert_(f"{rel} is in the upload set", rel in paths)

    for php in sorted((ROOT / "admin").rglob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        assert_(f"{rel} is in the upload set", rel in paths)

    # EVERY document is seeded, not just the ones somebody remembered. The
    # company profile shipped without this and the failure was invisible from
    # here: the editor rendered, the form worked, and every field in it was
    # empty because the file it reads had never reached the host.
    for name in documents():
        seeded = out_dir / "seed" / f"{name}.json"
        assert_(f"a fresh host is seeded with {name}", seeded.is_file(),
                f"nothing would create content/{name}.json, so the editor "
                f"would open on defaults and the first save would publish "
                f"them over the live page")

        if not seeded.is_file():
            continue

        try:
            json.loads(seeded.read_text())
            assert_(f"the {name} seed is readable JSON", True)
        except (OSError, ValueError) as exc:
            assert_(f"the {name} seed is readable JSON", False, str(exc))

    try:
        data = json.loads((out_dir / "seed" / "careers.json").read_text())
        assert_("the careers seed carries no job posts", data.get("jobs") == [],
                f"jobs: {len(data.get('jobs', []))} — a new host would launch "
                f"advertising test vacancies")
        assert_("the careers seed keeps the site-wide settings",
                "cv_form_url" in data)
    except (OSError, ValueError) as exc:
        assert_("the careers seed is readable JSON", False, str(exc))

    # Every shipped .php file must COMPILE with short_open_tag=On.
    #
    # It is off by default in php-cli and on by default on the host, as on most
    # cPanel installs, and the difference is invisible until a deploy: with it
    # on, PHP reads the "<?" of a literal "<?xml" as an open tag and tries to
    # run the rest as code. The file then fails to compile, so the response is
    # a 500 with an empty body and nothing to read anywhere.
    #
    # This is here because it happened to the other half: the public site's
    # sitemap.php shipped that way and answered 500 until it was found from
    # outside. Nothing in this repository parses differently today, and the
    # point of the check is that it stays that way -- a guard on one side of a
    # pair is the arrangement that let content/company.json go missing from
    # this host for a fortnight.
    php = shutil.which("php")
    if php is None:
        assert_("php is available to parse the shipped files", False,
                "install php-cli — this check cannot run without it")
    else:
        for rel in sorted(m for m in paths if m.endswith(".php")):
            result = subprocess.run(
                [php, "-d", "short_open_tag=1", "-l", str(ROOT / rel)],
                capture_output=True, text=True)
            assert_(f"{rel} parses with short_open_tag=On",
                    result.returncode == 0,
                    result.stdout.strip().splitlines()[0]
                    if result.stdout.strip() else result.stderr.strip())

    return run, len(failed)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--out", metavar="DIR",
                    help="build into DIR/site and DIR/seed")
    ap.add_argument("--check", action="store_true",
                    help="build into a temporary directory and assert; change nothing")
    args = ap.parse_args()

    if not args.out and not args.check:
        ap.error("give --out DIR, or --check")

    with tempfile.TemporaryDirectory() as tmp:
        out_dir = Path(args.out).resolve() if args.out else Path(tmp)
        paths = build(out_dir)

        size = sum((out_dir / "site" / p).stat().st_size for p in paths)
        print(f"{len(paths)} files, {size / 1_048_576:.1f} MB")

        if args.out:
            print(f"  site  {out_dir / 'site'}")
            print(f"  seed  {out_dir / 'seed'}")

        if not args.check:
            return

        total, bad = check(paths, out_dir)

    print(f"\n{total - bad}/{total} checks passed")

    if bad:
        sys.exit(1)


if __name__ == "__main__":
    main()
