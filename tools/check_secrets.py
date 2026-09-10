#!/usr/bin/env python3
"""
Prove that nothing which protects the admin has quietly stopped protecting it.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_secrets.py

WHY THIS EXISTS
The failures this looks for share one property: everything goes on working.
A master key committed by accident, a private store that turns out to be
web-reachable, a bypass flag left in for one afternoon's convenience, a
password written into the audit log — none of them break a page, fail a save
or raise an error. The site is exactly as usable the day after as the day
before, and the only difference is that somebody else can sign in.

So these are asserted mechanically, on every run, rather than remembered.

WHAT IS CHECKED BY BEHAVIOUR RATHER THAN BY READING
Where a check can run the real code, it does — pointing lib/private.php at a
directory inside the web root and insisting it refuses is worth more than
grepping for the function that refuses, because the grep goes on passing when
the call to it is deleted.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Names that must never be committed, wherever they turn up.
SECRET_NAMES = [
    "secret.key", "publish.key", "admins.json", "audit.log", "audit.log.1",
    "throttle.json", "resets.json", "setup-token.txt",
]
SECRET_DIRS = ["t4t-private", "t4t-private-admin", ".dev-private"]

# Every file that can emit an HTML page. They are the document root now, not a
# folder inside somebody else's site.
ADMIN_PAGES = [
    "public/login.php", "public/forgot.php", "public/reset.php", "public/setup.php",
]

# What belongs to the frontend and must not reappear here. Each was deleted in
# the split; each would work perfectly well if somebody copied it back, and the
# admin host would then be serving a public website nobody asked it to serve.
FRONTEND_ONLY = [
    "pages",
    "api",
    "contact-handler.php",
    "index.html",
]

problems: list[str] = []
notes: list[str] = []


def ok(label: str) -> None:
    print(f"  ok    {label}")


def bad(label: str, detail: str = "") -> None:
    print(f"  FAIL  {label}" + (f"\n          {detail}" if detail else ""))
    problems.append(label)


def log_contexts(text: str):
    """Every auth_log() call's context argument, with its line number.

    Brace counting rather than a regex, because the context is an array literal
    that contains its own brackets and commas, and a lazy match would stop at
    the first one.
    """
    for m in re.finditer(r"auth_log\(", text):
        i, depth = m.end(), 1

        while i < len(text) and depth:
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
            i += 1

        args = text[m.end():i - 1]
        # Drop the event name — the first quoted literal — and keep the rest.
        context = re.sub(r"^\s*'[^']*'\s*,?", "", args, count=1)

        yield text.count("\n", 0, m.start()) + 1, context


def tracked() -> list[str]:
    out = subprocess.run(["git", "ls-files"], cwd=ROOT,
                         capture_output=True, text=True)
    return out.stdout.splitlines() if out.returncode == 0 else []


# ------------------------------------------------------- nothing is committed


def check_nothing_committed() -> None:
    print("\nnothing secret is in git")

    files = tracked()
    if not files:
        notes.append("not a git checkout — the commit checks were skipped")
        print("  --    skipped: git said nothing")
        return

    found = [f for f in files
             if Path(f).name in SECRET_NAMES
             or any(part in SECRET_DIRS for part in Path(f).parts)
             or f.endswith(".key")]

    if found:
        bad("no secret file is tracked", ", ".join(found))
    else:
        ok("no secret file is tracked")

    # .gitignore has to catch them where they would actually land.
    samples = [f"{d}/{n}" for d in SECRET_DIRS for n in SECRET_NAMES[:2]]
    missed = []

    for sample in samples:
        done = subprocess.run(["git", "check-ignore", "-q", sample], cwd=ROOT)
        if done.returncode != 0:
            missed.append(sample)

    if missed:
        bad("a stray private store would be ignored by git", ", ".join(missed))
    else:
        ok("a stray private store would be ignored by git")


# ------------------------------------------------- the store stays out of reach


def check_store_refuses_web_root() -> None:
    print("\nthe private store refuses to be reachable")

    # Inside the DOCUMENT ROOT, which on this host is public/ — not the
    # repository. content/ used to be the obvious "inside the website" and
    # stopped being it when the admin gained a public/ root: a store there is
    # now correctly outside the web root, and pointing at it would assert that
    # the check passes, which is the opposite of the point.
    inside = ROOT / "public" / "would-be-web-readable"

    done = subprocess.run(
        ["php", "-r",
         "require 'lib/private.php';"
         "try { t4t_private_dir(); echo 'ACCEPTED'; }"
         "catch (RuntimeException $e) { echo 'REFUSED'; }"],
        cwd=str(ROOT), capture_output=True, text=True,
        env=dict(os.environ, T4T_PRIVATE=str(inside)),
    )

    if done.stdout.strip() == "REFUSED":
        ok("a store inside the document root is refused")
    else:
        bad("a store inside the document root is refused",
            f"php said {done.stdout.strip()!r} {done.stderr.strip()[:200]}")

    if inside.exists():
        bad("and refusing it creates nothing",
            f"{inside.relative_to(ROOT)} was created anyway")
        try:
            inside.rmdir()
        except OSError:
            pass
    else:
        ok("and refusing it creates nothing")

    # The admin must consult that refusal rather than merely have it available.
    admin = (ROOT / "lib" / "admin.php").read_text()
    start = re.search(r"function admin_start_session\(\).*?\n}", admin, re.S)

    if start and "auth_problem()" in start.group(0) and "admin_refuse(" in start.group(0):
        ok("the admin stops when the store is not sound")
    else:
        bad("the admin stops when the store is not sound",
            "admin_start_session() no longer calls auth_problem() then admin_refuse()")


def check_setup_window_closes() -> None:
    """A setup token and an account must never exist at the same time.

    admin/setup.php promises the window "is shut by the code rather than by a
    step somebody has to remember". It was not: the recovery-codes screen
    survives the "setup is over" redirect on purpose, and it re-created the
    token auth_setup_done() had just deleted. Found on the live host, where
    setup-token.txt sat in the private store beside a working account.

    Driven through the real functions in a throwaway store, because the
    interesting question is what the code does, not what it says. A grep for
    the guard passes the moment somebody keeps the guard and adds a second way
    in beside it.
    """
    print("\nthe setup window shuts behind itself")

    work = Path(tempfile.mkdtemp(prefix="t4t-setupwin-"))
    private = work / "private"
    env = dict(os.environ, T4T_PRIVATE=str(private))

    def php(code: str) -> str:
        done = subprocess.run(
            ["php", "-r", "require 'lib/auth.php';" + code],
            cwd=str(ROOT), capture_output=True, text=True, env=env, timeout=60,
        )
        return (done.stdout + done.stderr).strip()

    try:
        # Before any account: minting one is the whole point, and must work.
        php("auth_setup_token();")
        token_file = private / "setup-token.txt"

        if token_file.exists():
            ok("a fresh store still hands out a setup key")
        else:
            bad("a fresh store still hands out a setup key",
                "auth_setup_token() wrote nothing — setup would be impossible")

        minted = token_file.read_text().strip() if token_file.exists() else ""

        php("var_export(auth_put(auth_defaults(["
            "'user' => 'someone', 'hash' => 'x', 'totp' => 'y'])));")

        if not (private / "admins.json").exists():
            bad("the setup window shuts once an account exists",
                "could not create a test account, so nothing below was proved")
            return

        # The token is deleted the way setup.php deletes it, then everything
        # that could bring it back is asked to.
        php("auth_setup_done();")

        if token_file.exists():
            bad("auth_setup_done() removes the key file",
                "it is still there")
        else:
            ok("auth_setup_done() removes the key file")

        php("auth_setup_token();")

        if token_file.exists():
            bad("and nothing re-creates it once an account exists",
                "auth_setup_token() minted a new one — this is the live defect")
        else:
            ok("and nothing re-creates it once an account exists")

        # The empty-token trap: with no file to read, a comparison against an
        # empty submission must not agree with itself.
        for given, label in ((minted, "the real key"), ("", "an empty key")):
            said = php(f"var_export(auth_setup_token_check({given!r}));")
            if said == "false":
                ok(f"and {label} no longer opens setup")
            else:
                bad(f"and {label} no longer opens setup", f"php said {said!r}")
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ------------------------------------------------------------- no way around it


def check_no_bypass() -> None:
    print("\nthere is no way past the sign-in")

    shipped = [p for p in ROOT.rglob("*.php")
               if "tools" not in p.parts and p.is_file()]

    # The old escape hatch: a constant whose false value granted full access.
    hits = [str(p.relative_to(ROOT)) for p in shipped
            if "ADMIN_REQUIRE_HTTP_AUTH" in p.read_text()]
    if hits:
        bad("the old Basic-auth bypass constant is gone", ", ".join(hits))
    else:
        ok("the old Basic-auth bypass constant is gone")

    # Identity must not come from a request header ever again.
    hits = []
    for p in shipped:
        text = p.read_text()
        for m in re.finditer(r"\$_SERVER\[['\"](REMOTE_USER|REDIRECT_REMOTE_USER|PHP_AUTH_USER)", text):
            hits.append(f"{p.relative_to(ROOT)}: {m.group(1)}")
    if hits:
        bad("nothing takes its identity from the web server", "; ".join(hits))
    else:
        ok("nothing takes its identity from the web server")

    # Every page that can be reached signed out must start the session through
    # admin_start_session(), which is what runs the refusal check.
    for name in ADMIN_PAGES:
        text = (ROOT / name).read_text()
        if "admin_start_session()" in text:
            ok(f"{name} goes through the shell's checks")
        else:
            bad(f"{name} goes through the shell's checks",
                "it does not call admin_start_session()")

    # And every page behind it must require an account.
    index = (ROOT / "public" / "index.php").read_text()
    if "admin_require_auth()" in index:
        ok("public/index.php requires an account")
    else:
        bad("public/index.php requires an account")


# --------------------------------------------------------------- what is stored


def check_nothing_leaks() -> None:
    print("\nsecrets stay out of the places we write to")

    auth = (ROOT / "lib" / "auth.php").read_text()

    # Session cookie flags. A cookie carrying a signed-in session without
    # HttpOnly is readable by any script that gets onto the page.
    boot = re.search(r"function auth_boot\(\).*?\n}", auth, re.S)
    boot = boot.group(0) if boot else ""

    for flag, label in [("'httponly' => true", "HttpOnly"),
                        ("'samesite' => 'Lax'", "SameSite"),
                        ("auth_is_https()", "Secure when the connection is")]:
        if flag in boot:
            ok(f"the session cookie is set {label}")
        else:
            bad(f"the session cookie is set {label}", f"{flag} missing from auth_boot()")

    if "session_regenerate_id(true)" in auth:
        ok("the session id is replaced when signing in")
    else:
        bad("the session id is replaced when signing in")

    # Nothing that would help an attacker may reach the audit log.
    #
    # Only the CONTEXT is examined, never the event name: 'password-reset' and
    # 'totp-enrolled' are things that happened, and a check that cannot tell
    # those from a logged password is a check that gets switched off.
    hits = []
    for p in ROOT.rglob("*.php"):
        if "tools" in p.parts or not p.is_file():
            continue
        for line, context in log_contexts(p.read_text()):
            if re.search(r"password|passwd|secret|\btotp\b|\$code\b|'code'", context, re.I):
                hits.append(f"{p.relative_to(ROOT)}:{line} {context.strip()[:60]}")

    if hits:
        bad("nothing secret is written to the audit log", "; ".join(hits))
    else:
        ok("nothing secret is written to the audit log")

    # A password must never be stored, only its hash.
    if "password_hash(" in auth and "'hash'" in auth:
        ok("accounts store a hash, not a password")
    else:
        bad("accounts store a hash, not a password")

    if "hash_equals(" in auth:
        ok("comparisons that matter are constant time")
    else:
        bad("comparisons that matter are constant time")


# ------------------------------------------------------------- staying unindexed


def check_unindexed() -> None:
    print("\nthe admin stays out of search results")

    for name in ADMIN_PAGES:
        text = (ROOT / name).read_text()
        if "admin_shell_head(" in text:
            ok(f"{name} renders through the noindexed shell")
        else:
            bad(f"{name} renders through the noindexed shell")

    shell = (ROOT / "lib" / "admin.php").read_text()
    count = len(re.findall(r'name="robots"', shell))

    # admin_head(), admin_refuse() and admin_shell_head() each emit their own.
    if count >= 3:
        ok(f"lib/admin.php marks all {count} of its page shapes noindex")
    else:
        bad("lib/admin.php marks every page shape noindex",
            f"found {count} robots tags, expected at least 3")

    # A BLANKET header, not a path match. The public site marks the editor
    # noindex with expr=%{REQUEST_URI} =~ m#^/admin(/|$)#, and on this host the
    # URI is "/" — so that same rule would match nothing and fail silently,
    # leaving the editor quietly indexable. ADR 0011 catalogued it; this is the
    # check that it was actually fixed rather than copied.
    htaccess = (ROOT / "public" / ".htaccess").read_text()

    blanket = re.search(r'^\s*Header always set X-Robots-Tag "[^"]*noindex[^"]*"\s*$',
                        htaccess, re.M)
    if blanket:
        ok("public/.htaccess noindexes the WHOLE host, not a path")
    else:
        bad("public/.htaccess noindexes the WHOLE host, not a path",
            "a rule with an expr= condition would match nothing here")

    if re.search(r'^\s*Header always set Cache-Control "no-store[^"]*"\s*$', htaccess, re.M):
        ok("and keeps it out of shared caches")
    else:
        bad("and keeps it out of shared caches")

    # An ALLOW-list, not a block: uploads/ has to be served, because the editor
    # draws every row's picture from it. It is the one directory here holding
    # files that came from somebody's computer, and this rule is the third of
    # ADR 0019's three layers -- the only one that still holds if the bytes were
    # not re-encoded and the name was not computed. Removing it is silent until
    # somebody notices /uploads/x.php answering 200.
    if re.search(r"\^/uploads/\[0-9a-f\]\{16\}", htaccess):
        ok("uploads/ serves the shape it mints, and nothing else")
    else:
        bad("uploads/ serves the shape it mints, and nothing else",
            "public/.htaccess has no allow-list for uploads/")

    # The one origin the admin's CSP names besides itself, and only for images:
    # most of the artwork the company editor previews ships with the public site
    # and exists nowhere else. Asserted so that it stays exactly this wide.
    csp = re.search(r'Content-Security-Policy "([^"]*)"', htaccess)
    directives = dict(
        (part.split(" ", 1) + [""])[:2]
        for part in (csp.group(1).split("; ") if csp else [])
        if part
    )
    if directives.get("img-src", "") == "'self' data: https://tech4time.bd":
        ok("the CSP names one outside origin, for images only")
    else:
        bad("the CSP names one outside origin, for images only",
            f"img-src is {directives.get('img-src')!r}")

    for name in ("script-src", "style-src", "connect-src", "font-src", "default-src"):
        if directives.get(name, "").strip() == "'self'":
            ok(f"and {name} is still nothing but 'self'")
        else:
            bad(f"and {name} is still nothing but 'self'",
                f"{name} is {directives.get(name)!r}")

    if re.search(r"^\s*User-agent: \*\s*$", (ROOT / "public" / "robots.txt").read_text(), re.M):
        ok("robots.txt asks as well, which is the weaker half of the pair")
    else:
        bad("robots.txt asks as well")


# ------------------------------------- nothing outside public/ is reachable


def check_nothing_below_the_docroot() -> None:
    """The reason this repository has the shape it has.

    lib/, sections/ and content/ are outside public/, so no URL maps to them.
    That is stronger than a rewrite rule, and it is the whole argument of
    ADR 0018 — so it is asserted rather than assumed, because a document root
    pointed one level too high on the host would undo all of it silently.
    """
    print("\nnothing but public/ can be requested")

    for name in ("lib", "sections", "content"):
        here = ROOT / name
        if here.is_dir() and not (ROOT / "public" / name).exists():
            ok(f"{name}/ is outside the document root")
        else:
            bad(f"{name}/ is outside the document root",
                f"{name}/ is missing, or a copy has appeared inside public/")

    front = [p for p in FRONTEND_ONLY if (ROOT / p).exists()]
    if front:
        bad("nothing belonging to the public site is here", ", ".join(front))
    else:
        ok("nothing belonging to the public site is here")

    # The guard on each section file. Unnecessary while the document root is
    # public/ -- and kept exactly because that is a configuration, and this is
    # what stands between a docroot set one level too high and a section file
    # running on its own.
    unguarded = [p.name for p in sorted((ROOT / "sections").glob("*.php"))
                 if "T4T_ADMIN" not in p.read_text()]
    if unguarded:
        bad("every section refuses to run unless T4T_ADMIN is defined",
            ", ".join(unguarded))
    else:
        ok("every section refuses to run unless T4T_ADMIN is defined")


def check_nothing_inline() -> None:
    """No markup that the CSP will silently refuse to run.

    The Content-Security-Policy here is script-src 'self'; style-src 'self',
    with no 'unsafe-inline' on either. A browser does not warn about an
    attribute it will not honour -- it drops the handler and carries on, so
    the page looks right and one behaviour is simply gone.

    That is not hypothetical. sections/careers.php asked
    "Delete this post permanently?" from an inline submit handler written
    before the CSP existed. Every browser refused it, nobody was ever asked,
    and Delete deleted on the first press for as long as the policy has been
    in place. Nothing failed; the confirmation just was not there.

    THE PHP COMES OUT FIRST, and that is not tidiness. Attributes here are
    written `action="<?= h(...) ?>"`, and `?>` contains a `>`: a pattern that
    scans from `<tag` to the attribute with [^>]* stops dead at the first one
    and matches nothing at all. The first version of this check passed a file
    with the very handler it was written for still in it. Removing every
    `<?…?>` span leaves the markup, and takes the PHP comments with it — so a
    comment that quotes a handler while explaining why it was removed does not
    read as one.
    """
    php = re.compile(r"<\?(?:.*?\?>|.*\Z)", re.S)
    handler = re.compile(
        r"<[a-zA-Z][^>]*?\son(?:click|submit|change|input|load|error|focus|blur"
        r"|keydown|keyup|mouseover|mouseout)\s*=", re.S)
    inline_style = re.compile(r"<[a-zA-Z][^>]*?\sstyle\s*=", re.S)

    found = []

    for rel in tracked():
        if not rel.endswith((".php", ".html")):
            continue

        raw = (ROOT / rel).read_text(encoding="utf-8", errors="replace")
        # Keep the line count honest: one space per character removed.
        text = php.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), raw)

        for pattern, what in ((handler, "an inline event handler"),
                              (inline_style, "an inline style attribute")):
            for m in pattern.finditer(text):
                line = text.count("\n", 0, m.start()) + 1
                found.append(f"{rel}:{line} has {what}")

    if found:
        for one in found:
            bad("markup the CSP refuses to run", one)
        return

    ok("no inline handlers or styles anywhere in the markup")


def check_assets_versioned() -> None:
    """Every stylesheet, script and image the admin serves carries a version.

    public/.htaccess sends assets with `max-age=31536000, immutable`, and the
    filenames never change because there is no build step. `immutable` is the
    dangerous half: it tells the browser not to revalidate on an ordinary
    reload, so once a stale copy is cached, pressing F5 will not clear it.

    That is not a risk, it is a report. A deploy shipped a new admin shell -- a
    rail toggle, an account menu, an outline column, a two-label save button --
    and the browsers went on painting it with the stylesheet from before. The
    markup was right, the page looked broken, and pressing reload changed
    nothing.

    admin_asset() puts the file's own mtime on the end of the URL, which is what
    makes the year safe. This asserts that nothing writes an asset URL past it,
    and that the header it is paired with is still the one described.

    Images count too: the logo is cached the same way, and a wordmark that
    changes is a wordmark nobody sees change.
    """
    literal = re.compile(
        r"""(?:href|src|srcset)\s*=\s*["'](/assets/[^"']+"""
        r"""\.(?:css|js|png|jpe?g|webp|svg|ico|woff2?))["']""")

    found = []

    for rel in tracked():
        if not rel.endswith(".php") or rel.startswith("tools/"):
            continue

        text = (ROOT / rel).read_text(encoding="utf-8", errors="replace")
        for m in literal.finditer(text):
            line = text.count("\n", 0, m.start()) + 1
            found.append(f"{rel}:{line} serves {m.group(1)} with no version — "
                         f"wrap it in admin_asset()")

    if found:
        for one in found:
            bad("an asset URL a year-long cache will pin", one)
    else:
        ok("every asset URL the admin writes goes through admin_asset()")

    htaccess = ROOT / "public" / ".htaccess"
    text = htaccess.read_text(encoding="utf-8") if htaccess.is_file() else ""

    if "immutable" not in text:
        # Not a failure. Dropping immutable is a safe direction to move in; it
        # costs a revalidation and nothing else. This says only that the pairing
        # above has stopped describing what is served.
        notes.append("public/.htaccess no longer sends immutable for assets — "
                     "the note above admin_asset() describes a header that is "
                     "gone")
    else:
        ok("the assets header and admin_asset() still describe each other")


def main() -> None:
    check_nothing_committed()
    check_store_refuses_web_root()
    check_no_bypass()
    check_setup_window_closes()
    check_nothing_leaks()
    check_unindexed()
    check_nothing_below_the_docroot()
    check_nothing_inline()
    check_assets_versioned()

    for note in notes:
        print(f"\nnote: {note}")

    if problems:
        print(f"\n{len(problems)} problem(s):\n")
        for p in problems:
            print(f"  - {p}")
        print("\nEach of these fails silently in production: the site goes on\n"
              "working and the only difference is who can sign in.")
        sys.exit(1)

    print("\nThe admin's protections are all still in place.")


if __name__ == "__main__":
    main()
