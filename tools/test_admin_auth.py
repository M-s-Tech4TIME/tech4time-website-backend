#!/usr/bin/env python3
"""
Exercise the admin's sign-in against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_admin_auth.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
/admin used to be protected by cPanel Directory Privacy, which meant Apache
did the checking and there was nothing here to test. It has its own accounts
now, and code that decides who may edit the website is the code most worth
proving — a mistake in it does not look like an error, it looks like a stranger
signing in.

So this drives the real flow over HTTP: first-run setup, the password, the
authenticator app, the lockout, signing out, and a whole password reset by
emailed code. The codes are generated here in Python from the same secret the
server stores, which is the only honest way to test a second factor.

WHAT IT PROVES THAT IS EASY TO GET WRONG
  - a request from off the machine must produce the setup key, and the key
    the server stores is the key it later accepts
  - a wrong password and an unknown username give the SAME answer
  - being locked out refuses even the RIGHT password
  - the emailed code alone cannot set a new password
  - a code cannot be used twice, or in another browser
  - the stored file never contains the password

Everything runs against a private directory in /tmp that is created and thrown
away, so the account you use locally is untouched.
"""

import base64
import hashlib
import hmac
import http.client
import json
import os
import re
import shutil
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import admin_session  # noqa: E402  -- needs the path line above

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"

USER = "testadmin"
EMAIL = "testadmin@tech4time.bd"
PASSWORD = "a long enough test passphrase"
NEWPASSWORD = "another entirely different passphrase"

# Mirrors RESET_2FA_TRIES in public/reset.php. If they drift, the loop below
# stops one short or one long and the test says so rather than passing.
RESET_2FA_TRIES = 5


# --------------------------------------------------------------------- TOTP


def totp(secret: str, at: float | None = None, step: int = 30, digits: int = 6) -> str:
    """RFC 6238, independently of the PHP that will be checking it.

    Written out here rather than imported so that the two implementations are
    genuinely separate: if lib/totp.php drifts, this disagrees with it.
    """
    clean = re.sub(r"[^A-Za-z2-7]", "", secret).upper()
    key = base64.b32decode(clean + "=" * (-len(clean) % 8))
    counter = int((time.time() if at is None else at) // step)
    mac = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = mac[-1] & 0x0F
    number = struct.unpack(">I", mac[offset:offset + 4])[0] & 0x7FFFFFFF
    return str(number % (10 ** digits)).zfill(digits)


# ------------------------------------------------------------------ harness


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []
        self.skipped = []

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    def skip(self, case, why):
        """
        Announced, never silent. A check that quietly vanishes on a machine
        that cannot run it is a check the suite claims to have and has not.
        """
        self.skipped.append(f"{case} — {why}")
        print(f"  SKIP  {case}\n          {why}")

    def section(self, name):
        print(f"\n{name}")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def start_server(port: int, private: Path, sendmail: Path):
    env = dict(os.environ, T4T_PRIVATE=str(private))
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(DOCROOT),
         "-d", f"sendmail_path={sendmail}", str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
        start_new_session=True, env=env,
    )
    for _ in range(60):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return proc
        except OSError:
            if proc.poll() is not None:
                raise SystemExit("php exited:\n"
                                 + proc.stderr.read().decode("utf-8", "replace"))
            time.sleep(0.1)
    raise SystemExit("php server did not come up")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """A 302 is usually the thing being asserted, so do not follow it."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

    http_error_302 = http_error_301 = http_error_303 = http_error_307 = \
        lambda self, req, fp, code, msg, headers: None


class FromAddress(urllib.request.HTTPHandler):
    """
    Dial from a chosen local address, so REMOTE_ADDR is something we pick.

    The server's bind address does not decide this — the kernel picks a source
    for the connection, and for a server on 127.0.0.1 it picks 127.0.0.1. The
    client is the end that chooses, so the choice is made here.
    """

    def __init__(self, source_ip: str):
        super().__init__()
        self.source_ip = source_ip

    def http_open(self, req):
        return self.do_open(
            lambda host, **kw: http.client.HTTPConnection(
                host, source_address=(self.source_ip, 0), **kw
            ),
            req,
        )


class Client:
    """One browser: its own cookie jar, so two of these are two browsers."""

    def __init__(self, port, source_ip: str | None = None):
        self.base = f"http://127.0.0.1:{port}"
        self.jar = CookieJar()
        handlers = [urllib.request.HTTPCookieProcessor(self.jar), NoRedirect()]

        if source_ip is not None:
            handlers.append(FromAddress(source_ip))

        self.opener = urllib.request.build_opener(*handlers)

    def get(self, path):
        req = urllib.request.Request(self.base + path)
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")

    def post(self, path, fields):
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base + path, data=data, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")

    def session_id(self):
        for c in self.jar:
            if c.name == "t4tadm":
                return c.value
        return None


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ""


def setup_key_of(html: str) -> str:
    m = re.search(r'class="signin__secret-value">([^<]+)<', html)
    return re.sub(r"\s+", "", m.group(1)) if m else ""


def recovery_codes_of(html: str) -> list[str]:
    block = re.search(r'<ul class="signin__codes"[^>]*>(.*?)</ul>', html, re.S)
    return re.findall(r"<li>([A-Z0-9-]+)</li>", block.group(1)) if block else []


class Mailbox:
    def __init__(self, path: Path):
        self.path = path

    def all(self) -> list[str]:
        return [p.read_text("utf-8", "replace") for p in sorted(self.path.glob("*.txt"))]

    def latest(self) -> str:
        got = self.all()
        return got[-1] if got else ""

    def clear(self):
        for p in self.path.glob("*.txt"):
            p.unlink()

    def code(self) -> str:
        m = re.search(r"Code:\s+(\d{6})", self.latest())
        return m.group(1) if m else ""


# -------------------------------------------------------------------- tests


def test_setup(c: Client, r: Results, private: Path) -> tuple[str, list[str]]:
    r.section("first run")

    status, _, _ = c.get("/")
    r.check("with no account, / sends you to setup", status == 302)

    status, _, page = c.get("/setup.php")
    r.check("setup opens", status == 200 and "Set up the admin" in page)
    r.check("no setup key is demanded from the machine itself",
            'name="token"' not in page)

    token = csrf_of(page)
    status, _, page = c.post("/setup.php", {
        "csrf": token, "do": "details", "user": USER, "name": "Test Admin",
        "email": EMAIL, "password": "short", "password2": "short",
    })
    r.check("a short password is refused", "at least 12 characters" in page.lower())

    status, _, page = c.post("/setup.php", {
        "csrf": csrf_of(page), "do": "details", "user": USER, "name": "Test Admin",
        "email": EMAIL, "password": PASSWORD, "password2": PASSWORD + "x",
    })
    r.check("two different passwords are refused", "not the same" in page)

    status, headers, _ = c.post("/setup.php", {
        "csrf": csrf_of(page), "do": "details", "user": USER, "name": "Test Admin",
        "email": EMAIL, "password": PASSWORD, "password2": PASSWORD,
    })
    r.check("good details move on to the authenticator", status == 302)

    status, _, page = c.get("/setup.php")
    secret = setup_key_of(page)
    r.check("a setup key is shown", len(secret) >= 16, secret)
    r.check("with a link for apps that take one", "otpauth://totp/" in page)

    status, _, page = c.post("/setup.php", {
        "csrf": csrf_of(page), "do": "enrol", "code": "000000",
    })
    r.check("a wrong code does not create the account",
            "not right" in page and not (private / "admins.json").exists())

    status, _, page = c.post("/setup.php", {
        "csrf": csrf_of(page), "do": "enrol", "code": totp(secret),
    })
    r.check("the right code creates it", status == 302)

    status, _, page = c.get("/setup.php")
    codes = recovery_codes_of(page)
    r.check("ten recovery codes are shown once", len(codes) == 10, str(codes))

    stored = (private / "admins.json").read_text()
    r.check("the account file exists", USER in stored)
    r.check("and holds no plaintext password", PASSWORD not in stored)
    r.check("and holds an argon2id hash", "$argon2id$" in stored)
    r.check("and holds no plaintext recovery code",
            all(code not in stored for code in codes))
    r.check("the setup key file is gone", not (private / "setup-token.txt").exists())

    status, _, _ = c.post("/setup.php", {"csrf": csrf_of(page), "do": "finish"})
    r.check("finishing sends you to sign in", status == 302)

    status, _, _ = c.get("/setup.php")
    r.check("setup refuses to run a second time", status == 302)

    return secret, codes


def sign_in(c: Client, secret: str, password: str = PASSWORD, code: str | None = None):
    """Both steps, as (status, headers, page).

    status is None when the password step never got as far as asking for a
    code — which is itself the assertion in several tests below.
    """
    _, _, page = c.get("/login.php")
    _, _, page = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "password", "user": USER, "password": password,
    })
    if "Two-step check" not in page:
        return None, {}, page
    return c.post("/login.php", {
        "csrf": csrf_of(page), "do": "second",
        "code": fresh_code(secret) if code is None else code,
    })


def test_login(c: Client, r: Results, secret: str):
    r.section("signing in")

    status, _, page = c.get("/")
    r.check("signed out, / sends you to the login page", status == 302)

    _, _, page = c.get("/login.php")
    r.check("the login page opens", "Sign in" in page)
    r.check("and offers a way through a forgotten password", "forgot.php" in page)

    _, _, bad_user = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "password",
        "user": "nobody-at-all", "password": "whatever it is",
    })
    _, _, bad_pass = c.post("/login.php", {
        "csrf": csrf_of(bad_user), "do": "password",
        "user": USER, "password": "not the password",
    })
    wrong = "do not match"
    r.check("an unknown username is refused", wrong in bad_user)
    r.check("a wrong password is refused", wrong in bad_pass)
    r.check("and the two say exactly the same thing",
            bad_user.count(wrong) == bad_pass.count(wrong) == 1)

    _, _, page = c.post("/login.php", {
        "csrf": csrf_of(bad_pass), "do": "password",
        "user": USER, "password": PASSWORD,
    })
    r.check("the right password asks for the app", "Two-step check" in page)
    r.check("and does not sign you in on its own", "admin-bar" not in page)

    _, _, page = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "second", "code": "000000",
    })
    r.check("a wrong code is refused", "not right" in page)

    before = c.session_id()
    status, headers, _ = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "second", "code": fresh_code(secret),
    })
    r.check("the right code signs you in", status == 302)
    r.check("the session id is replaced on the way", c.session_id() != before)

    cookie = headers.get("Set-Cookie", "")
    r.check("the session cookie is HttpOnly", "HttpOnly" in cookie, cookie)
    r.check("and SameSite", "SameSite" in cookie, cookie)

    status, headers, page = c.get("/")
    r.check("the overview now opens", status == 200 and "admin-bar" in page)
    r.check("it is not stored in a shared cache",
            "no-store" in headers.get("Cache-Control", ""))
    r.check("it names who is signed in", USER in page)

    for path, want in [("/?s=careers", "Job posts"),
                       ("/?s=contact", "Reach us directly"),
                       ("/?s=account", "Recovery codes")]:
        status, _, page = c.get(path)
        r.check(f"{path} opens", status == 200 and want.lower() in page.lower())


def test_password_switches(c: Client, r: Results) -> None:
    """The sign-in page's show/hide switch, as the server actually sends it.

    tools/check_password_fields.py reads the same requirement out of the
    source, and this is the half it structurally cannot see: what comes down
    the wire. The markup is built by admin_password_toggle(), which inlines two
    sprite symbols -- so a renamed icon, a sprite that failed to load, or a
    helper that threw would leave every source file correct and the page bare.

    ONLY THE SIGN-IN PAGE IS ASKED HERE, and deliberately. setup.php stops
    working the moment an account exists, and reset.php only reaches its
    password stage at the end of an emailed-code flow -- test_reset() below
    walks that flow for its own reasons and this would be a poor passenger on
    it. Their markup is covered by check_password_fields.py, which is the check
    written for exactly that: fields on pages that are hard to get back to.

    MUST RUN SIGNED OUT. /login.php redirects when a session is live, and the
    first version of this ran straight after test_login() and reported the
    switch missing from a page it was never served.
    """
    r.section("the show/hide switch, as the server sends it")

    status, _, page = c.get("/login.php")
    r.check("the sign-in page is what came back",
            status == 200 and 'class="signin__form"' in page,
            f"status {status} -- signed in, this redirects, and every check "
            f"below would be reading the wrong page")

    found = re.findall(r'data-password-toggle="([^"]+)"', page)
    r.check("the password field has a switch, and it names that field",
            found == ["password"], f"found {found}")
    r.check("which ships both marks, so it can say which state it is in",
            page.count("admin__password-icon--show") == 1
            and page.count("admin__password-icon--hide") == 1)
    r.check("it is a button, so it cannot submit the form by accident",
            'class="admin__password-toggle" type="button"' in page)
    r.check("it starts unpressed and hidden, so no script means no dead control",
            'aria-pressed="false" hidden>' in page)
    r.check("and the field has the room reserved for it before first paint",
            '<div class="admin__password">' in page)


def test_signout(c: Client, r: Results, secret: str):
    r.section("signing out")

    status, _, page = c.get("/")
    token = csrf_of(page)

    status, _, _ = c.get("/logout.php")
    r.check("a link cannot sign you out", status in (302, 405))
    status, _, _ = c.get("/")
    r.check("so you are still signed in", status == 200)

    status, _, _ = c.post("/logout.php", {"csrf": "wrong"})
    r.check("signing out without a token is refused", status == 400)

    status, _, _ = c.post("/logout.php", {"csrf": token})
    r.check("signing out with one works", status == 302)

    status, _, _ = c.get("/")
    r.check("and / sends you back to the login page", status == 302)


_spent = {"counter": -1}


def fresh_code(secret: str) -> str:
    """A code the server has not already accepted.

    Now that a code really is good only once, any test that signs in twice
    inside the same thirty seconds would be refused — correctly, and for a
    reason that has nothing to do with what it was checking. This waits for a
    new step when the last one has been used, so a failure here always means
    what it says.
    """
    counter = int(time.time() // 30)

    if counter <= _spent["counter"]:
        next_step()
        counter = int(time.time() // 30)

    _spent["counter"] = counter
    return totp(secret, at=counter * 30)


def next_step():
    """Wait for a new 30-second step to begin.

    Two reasons, and both would otherwise make this test lie. An earlier test
    has already signed in during the current step, so its code is spent and a
    replay test starting here would fail for the wrong reason. And a step that
    is nearly over would see the second attempt refused because time passed
    rather than because the code was used — which would go on passing with the
    replay defence taken out.

    Costs up to thirty seconds, once, and leaves a full window to work in.
    """
    time.sleep(30 - (time.time() % 30) + 0.2)


def test_totp_replay(c: Client, r: Results, secret: str, private: Path):
    r.section("a code is good once")

    (private / "throttle.json").unlink(missing_ok=True)
    next_step()

    code = fresh_code(secret)
    status, _, _ = sign_in(c, secret, code=code)
    r.check("a fresh code signs you in", status == 302)

    c.post("/logout.php", {"csrf": csrf_of(c.get("/")[2])})
    (private / "throttle.json").unlink(missing_ok=True)

    status, _, _ = sign_in(c, secret, code=code)
    r.check("the very same code will not do it again",
            status != 302, "a captured code could be replayed inside its 30 seconds")


def test_csrf_and_redirect(c: Client, r: Results):
    r.section("tokens and redirects")

    status, _, _ = c.post("/login.php", {
        "do": "password", "user": USER, "password": PASSWORD,
    })
    r.check("posting to the login page without a token is refused", status == 400)

    _, _, page = c.get("/login.php?next=https://example.com/")
    r.check("an off-site next= is dropped", "https://example.com" not in page)

    _, _, page = c.get("/login.php?next=//example.com/")
    r.check("a protocol-relative next= is dropped", "//example.com" not in page)

    _, _, page = c.get("/login.php?next=%2F%3Fs%3Dcontact")
    r.check("an in-admin next= is kept", "/?s=contact" in page)


def test_lockout(c: Client, r: Results, secret: str, private: Path):
    r.section("guessing costs something")

    (private / "throttle.json").unlink(missing_ok=True)

    _, _, page = c.get("/login.php")

    # AUTH_ALLOW failures are free; the wait starts on the one after.
    for _ in range(6):
        _, _, page = c.post("/login.php", {
            "csrf": csrf_of(page), "do": "password",
            "user": USER, "password": "wrong every time",
        })
    r.check("six wrong passwords are each just refused", "do not match" in page)

    _, _, page = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "password",
        "user": USER, "password": "wrong every time",
    })
    r.check("the seventh is made to wait", "Try again in" in page)

    _, _, page = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "password", "user": USER, "password": PASSWORD,
    })
    r.check("and the RIGHT password is refused while locked out",
            "Try again in" in page and "Two-step check" not in page)

    (private / "throttle.json").unlink(missing_ok=True)
    _, _, page = c.post("/login.php", {
        "csrf": csrf_of(page), "do": "password", "user": USER, "password": PASSWORD,
    })
    r.check("once the wait is over it works again", "Two-step check" in page)


def test_reset(c: Client, r: Results, mail: Mailbox, secret: str, private: Path):
    r.section("forgetting the password")

    (private / "throttle.json").unlink(missing_ok=True)
    mail.clear()

    _, _, page = c.get("/forgot.php")
    r.check("the forgotten-password page opens", "Forgotten password" in page)

    status, headers, _ = c.post("/forgot.php", {
        "csrf": csrf_of(page), "who": "somebody-who-does-not-exist",
    })
    unknown_to = headers.get("Location", "")
    r.check("an unknown account is accepted without comment", status == 302)
    r.check("and no mail is sent for it", mail.all() == [])

    _, _, page = c.get("/forgot.php")
    status, headers, _ = c.post("/forgot.php", {
        "csrf": csrf_of(page), "who": USER,
    })
    r.check("a real account is answered identically",
            status == 302 and headers.get("Location", "") == unknown_to)
    r.check("and a code is emailed", len(mail.all()) == 1)

    body = mail.latest()
    code = mail.code()
    r.check("the message carries six digits", len(code) == 6)
    r.check("it goes to the address on the account, not the one typed",
            EMAIL in body)
    r.check("it is sent from our own domain, for SPF",
            "no-reply@tech4time.bd" in body)
    r.check("and says the code alone is not enough",
            "cannot change your password" in body.lower())

    stored = (private / "resets.json").read_text()
    r.check("the code is stored only as a hash", code not in stored)

    _, _, page = c.get("/reset.php?sent=1")
    r.check("the reset page says a code is on its way", "on its way" in page)

    _, _, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "code", "code": "000000",
    })
    r.check("a wrong code is refused", "not right" in page)
    r.check("and says how many tries are left", "tries left" in page)

    # A second browser, which never asked for this code.
    other = Client(int(c.base.rsplit(":", 1)[1]))
    _, _, opage = other.get("/reset.php")
    _, _, opage = other.post("/reset.php", {
        "csrf": csrf_of(opage), "do": "code", "code": code,
    })
    r.check("the code does not work in another browser", "not right" in opage)

    status, _, _ = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "code", "code": code,
    })
    r.check("the right code is accepted", status == 302)

    # ---- step 2: the password, on a screen of its own -------------------
    _, _, page = c.get("/reset.php")
    r.check("which asks for a new password, and nothing else",
            "New password" in page and "Authenticator code" not in page)
    r.check("and says where in the sequence it is", "Step 2 of 3" in page)

    _, _, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "password",
        "password": "short", "password2": "short",
    })
    r.check("a weak new password is refused", "at least 12" in page.lower())

    _, _, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "password",
        "password": NEWPASSWORD, "password2": NEWPASSWORD + " typo",
    })
    r.check("two that do not match are refused", "not the same" in page.lower())

    status, _, _ = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "password",
        "password": NEWPASSWORD, "password2": NEWPASSWORD,
    })
    r.check("a good one is accepted", status == 302)

    # ---- step 3: the app, and only now does anything change -------------
    _, _, page = c.get("/reset.php")
    r.check("which then asks for the app, and nothing else",
            "Authenticator code" in page and "New password" not in page)
    r.check("and says this is the step that sets it", "Step 3 of 3" in page)

    r.check("the chosen password is not carried in the page",
            NEWPASSWORD not in page)
    r.check("nor written anywhere in the accounts file",
            NEWPASSWORD not in (private / "admins.json").read_text())

    # Going back is a post with a token, not a link: it changes state.
    _, _, page = c.post("/reset.php", {"csrf": csrf_of(page), "do": "again"})
    _, _, page = c.get("/reset.php")
    r.check("\"choose a different password\" returns to step 2",
            "Step 2 of 3" in page)

    status, _, _ = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "password",
        "password": NEWPASSWORD, "password2": NEWPASSWORD,
    })
    r.check("and the password can be chosen again", status == 302)

    _, _, page = c.get("/reset.php")

    # THE property this whole sequence exists for. Everything up to here has
    # been done: the mailbox was read, the code was accepted, a password was
    # chosen and accepted. The account must still be untouched.
    before = (private / "admins.json").read_text()

    _, _, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "second", "second": "000000",
    })
    r.check("a wrong authenticator code is refused",
            "authenticator code is not right" in page.lower())
    r.check("and says how many tries are left", "tries left" in page)
    r.check("an emailed code plus a chosen password changes NOTHING",
            (private / "admins.json").read_text() == before)

    mail.clear()
    status, headers, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "second", "second": fresh_code(secret),
    })
    r.check("the app is what finally sets it",
            status == 302 and "reset=1" in headers.get("Location", ""))
    r.check("and a notice is emailed about it",
            "was changed" in mail.latest())

    # A fresh page: the successful reset replaced the session id, so the token
    # from before it is no longer the one this session carries.
    _, _, page = c.get("/reset.php")
    _, _, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "code", "code": code,
    })
    r.check("the used code cannot be used again", "not right" in page)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, secret, password=PASSWORD)
    r.check("the old password no longer works", status is None)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, secret, password=NEWPASSWORD)
    r.check("the new one does", status == 302)

    test_reset_guess_limit(r, mail, secret, private, int(c.base.rsplit(":", 1)[1]))


def test_reset_guess_limit(r: Results, mail: Mailbox, secret: str,
                           private: Path, port: int):
    """Five wrong authenticator codes tear up the whole reset.

    A fresh browser, because the one that ran the reset above is signed in now,
    and login.php sends a signed-in visitor straight on -- which would make the
    last check here pass without proving anything.

    Worth its own test rather than a line in the one above: the last step of the
    reset asks a single six-digit question, and an unlimited number of guesses
    at six digits is not a second factor. This is the counting.
    """
    r.section("guessing at the last step")

    c = Client(port)

    (private / "throttle.json").unlink(missing_ok=True)
    mail.clear()

    _, _, page = c.get("/forgot.php")
    c.post("/forgot.php", {"csrf": csrf_of(page), "who": USER})
    code = mail.code()
    r.check("a second reset can be asked for", len(code) == 6)

    _, _, page = c.get("/reset.php")
    c.post("/reset.php", {"csrf": csrf_of(page), "do": "code", "code": code})

    _, _, page = c.get("/reset.php")
    # PASSWORD is the OLD one, chosen here on purpose: if this abandoned reset
    # wrongly took effect, the sign-in at the end would succeed with it.
    c.post("/reset.php", {"csrf": csrf_of(page), "do": "password",
                          "password": PASSWORD, "password2": PASSWORD})

    _, _, page = c.get("/reset.php")
    r.check("the second reset reaches step 3", "Step 3 of 3" in page)

    for _ in range(RESET_2FA_TRIES):
        _, _, page = c.post("/reset.php", {
            "csrf": csrf_of(page), "do": "second", "second": "000000",
        })
    r.check(f"{RESET_2FA_TRIES} wrong codes are each refused",
            "not right" in page.lower())

    _, _, page = c.post("/reset.php", {
        "csrf": csrf_of(page), "do": "second", "second": "000000",
    })
    r.check("the next one tears the whole reset up", "start again" in page.lower())
    r.check("and it is back to asking for an emailed code",
            "Step 1 of 3" in page)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, secret, password=PASSWORD)
    r.check("the password from the abandoned reset was never set",
            status is None)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, secret, password=NEWPASSWORD)
    r.check("and the one from the reset that finished still stands",
            status == 302)


def test_recovery_code(c: Client, r: Results, codes: list[str], private: Path):
    r.section("recovery codes")

    (private / "throttle.json").unlink(missing_ok=True)
    c.post("/logout.php", {"csrf": csrf_of(c.get("/")[2])})

    status, _, _ = sign_in(c, "", password=NEWPASSWORD, code=codes[0])
    r.check("a recovery code stands in for the app", status == 302)

    status, _, _ = c.get("/")
    r.check("and really signs you in", status == 200)

    c.post("/logout.php", {"csrf": csrf_of(c.get("/")[2])})
    (private / "throttle.json").unlink(missing_ok=True)

    status, _, _ = sign_in(c, "", password=NEWPASSWORD, code=codes[0])
    r.check("the same code will not work twice", status != 302)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, "", password=NEWPASSWORD, code=codes[1])
    r.check("but the next one does", status == 302)


def test_audit(r: Results, private: Path, codes: list[str], secret: str):
    r.section("the record")

    lines = [json.loads(l) for l in
             (private / "audit.log").read_text().strip().splitlines() if l.strip()]
    events = {row.get("event") for row in lines}

    for want in ["setup-complete", "login", "login-failed", "logout",
                 "login-throttled", "second-factor-failed", "reset-code-sent",
                 "reset-request-unknown", "password-reset", "recovery-code-used",
                 "reset-second-factor-failed", "reset-second-factor-exhausted"]:
        r.check(f"records {want}", want in events, str(sorted(events)))

    raw = (private / "audit.log").read_text()
    r.check("holds no password", PASSWORD not in raw and NEWPASSWORD not in raw)
    r.check("holds no recovery code", all(c not in raw for c in codes))
    r.check("holds no authenticator secret", secret not in raw)


REMOTE = "127.0.0.2"


def can_dial_from(ip: str) -> bool:
    """Whether this machine lets a client socket claim that source address."""
    try:
        with socket.socket() as sock:
            sock.bind((ip, 0))
        return True
    except OSError:
        return False


def test_codes_die_with_the_key(r: Results):
    """
    Losing secret.key kills every recovery code, and that has to be visible.

    Recovery codes are hashed under a key derived from secret.key. Lose that
    file and all ten become permanently unverifiable — but the account still
    holds ten entries, and counting entries is what the CLI did. The one place
    a person checks whether they still have a way in reported that they did,
    right up until they tried one.

    Stored codes now carry the fingerprint of the key that made them, so a dead
    code is recognisable as dead rather than merely failing to match. This
    checks both halves: that it is reported, and that it is actually refused.

    No HTTP server here — the CLI is the surface this shows up on, and it is
    also the surface somebody reaches for once they cannot sign in.
    """
    r.section("recovery codes after the key is lost")

    work = Path(tempfile.mkdtemp(prefix="t4t-keyloss-"))
    private = work / "private"
    env = dict(os.environ, T4T_PRIVATE=str(private))

    def cli(*args) -> str:
        done = subprocess.run(
            ["php", str(ROOT / "tools" / "admin-cli.php"), *args],
            capture_output=True, text=True, timeout=60, cwd=str(ROOT), env=env,
        )
        return done.stdout + done.stderr

    def accepts(code: str) -> str:
        """Whether auth_recovery_use() would spend this code. 'true'/'false'."""
        done = subprocess.run(
            ["php", "-r",
             "require 'lib/auth.php'; $a = auth_find($argv[1]); "
             "var_export(auth_recovery_use($a, $argv[2]));",
             "--", USER, code],
            capture_output=True, text=True, timeout=60, cwd=str(ROOT), env=env,
        )
        return (done.stdout + done.stderr).strip()

    try:
        admin_session.make_account(private, user=USER, password=PASSWORD)
        issued = cli("codes", USER)
        codes = re.findall(r"\b[0-9A-F]{5}-[0-9A-F]{5}\b", issued)

        r.check("ten codes are issued", len(codes) == 10, str(len(codes)))

        stored = json.loads((private / "admins.json").read_text())["accounts"][0]["recovery"]
        r.check("each is stored with the key that made it",
                len(stored) == 10 and all(":" in h for h in stored), str(stored[:1]))

        out = cli("list")
        r.check("the cli counts ten while the key is intact",
                re.search(r"\s10\s", out) is not None and "DEAD" not in out, out)
        r.check("and a code is accepted", accepts(codes[0]) == "true")

        # Lose the key. The next call mints a fresh one, which is the moment
        # every derived secret quietly stops being verifiable.
        (private / "secret.key").unlink()

        out = cli("list")
        r.check("with the key gone the cli reports them dead", "10 DEAD" in out, out)
        r.check("and never shows a usable count beside it",
                re.search(r"\s10\s+(?!DEAD)", out) is None, out)
        r.check("and says the password went with them",
                "password cannot be either" in out, out)
        r.check("and says what to do instead",
                "admin-cli.php passwd" in out and "admin-cli.php codes" in out, out)

        r.check("and a dead code is refused, not merely reported",
                accepts(codes[1]) == "false")

        # Issuing new ones under the key we now have puts it right.
        cli("codes", USER)
        fresh = re.findall(r"\b[0-9A-F]{5}-[0-9A-F]{5}\b", cli("codes", USER))
        out = cli("list")
        r.check("new codes are live again", "DEAD" not in out and "10" in out, out)
        r.check("and one of them works", accepts(fresh[0]) == "true")
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_setup_key_demanded_remotely(r: Results, sendmail: Path):
    """
    The gate the setup key exists for: a request that did not come from this
    machine must produce the key before it can create the first account.

    WHY THIS IS SEPARATE FROM test_setup
    Every other test here dials from 127.0.0.1, where the key is deliberately
    skipped — so none of them can tell auth_is_loopback() from auth_is_local().
    Those two sit seventeen lines apart in lib/auth.php, are named almost the
    same, and only one is safe here: auth_is_local() reads the Host header,
    which the client chooses, so with it in place anyone sending
    "Host: localhost" to the live server would be handed the first account.
    Swapping them changes nothing that the rest of this file observes.

    So this one dials from 127.0.0.2 instead. Still loopback as far as the
    kernel is concerned — nothing leaves the machine — but not one of the two
    addresses auth_is_loopback() accepts, which is the whole point: to the
    application the request looks like it came from somewhere else.
    """
    r.section("the setup key, from somewhere that is not this machine")

    if not can_dial_from(REMOTE):
        r.skip("a remote request must produce the setup key",
               f"this machine cannot dial from {REMOTE}; the check needs the "
               "whole 127.0.0.0/8 range on the loopback interface, as Linux has")
        return

    work = Path(tempfile.mkdtemp(prefix="t4t-setupkey-"))
    private = work / "private"
    port = free_port()
    proc = start_server(port, private, sendmail)

    try:
        away = Client(port, source_ip=REMOTE)
        here = Client(port)

        status, _, page = away.get("/setup.php")
        r.check("setup opens for a remote request", status == 200)
        r.check("and demands the setup key", 'name="token"' in page)
        r.check("and says where to read it on the server",
                "setup-token.txt" in page)

        r.check("the key file was created on demand",
                (private / "setup-token.txt").exists())

        # The other half of the same branch, on the same server: from this
        # machine there is no key to produce, because reading the file and
        # reading the disk are the same act.
        #
        # Asked here, while no account exists. Once one does, setup.php
        # redirects everyone to login.php and a 200 is no longer the right
        # answer for anybody — which would make this a check of the redirect
        # rather than of the loopback branch it is written to cover.
        status, _, home = here.get("/setup.php")
        r.check("and no key is demanded from the machine itself",
                status == 200 and 'name="token"' not in home)

        fields = {
            "do": "details", "user": USER, "name": "Test Admin",
            "email": EMAIL, "password": PASSWORD, "password2": PASSWORD,
        }

        status, _, page = away.post("/setup.php",
                                    {**fields, "csrf": csrf_of(page), "token": ""})
        r.check("no key does not create the account",
                "does not match" in page and not (private / "admins.json").exists())

        status, _, page = away.post(
            "/setup.php",
            {**fields, "csrf": csrf_of(page), "token": "AAAA-BBBB-CCCC"},
        )
        r.check("a wrong key does not create the account",
                "does not match" in page and not (private / "admins.json").exists())

        # Read tolerantly: if the gate above has failed, nothing was refused and
        # there is no log to read. That must arrive as a failed check like any
        # other, not as a traceback that abandons the rest of the run.
        log = private / "audit.log"
        raw = log.read_text() if log.exists() else ""
        events = [json.loads(l).get("event")
                  for l in raw.strip().splitlines() if l.strip()]
        r.check("a refused key is recorded", "setup-token-failed" in events,
                str(events) if events else "no audit log was written")

        key = private / "setup-token.txt"
        token = key.read_text().strip() if key.exists() else ""
        status, _, page = away.post("/setup.php",
                                    {**fields, "csrf": csrf_of(page), "token": token})
        r.check("the right key moves on to the authenticator", status == 302,
                f"status {status}" if token else "no key file to read")

        # Carried to the end, and this is the part that matters.
        #
        # test_setup() already asserts the key file is gone once the account
        # exists — and it asserts it from 127.0.0.1, where auth_is_loopback()
        # is true, no key is ever demanded and no file is ever written. The
        # check passed because there was nothing there to begin with, which is
        # the most comfortable kind of green tick and worth nothing at all.
        #
        # Only this branch creates the file, so only this branch can prove it
        # is removed. It was not: auth_setup_done() unlinked it, and the very
        # next render — the recovery-codes screen, which survives the "setup is
        # over" redirect on purpose — called auth_setup_token() again and put
        # it straight back. Seen on the live host before it was seen here.
        status, _, page = away.get("/setup.php")
        secret = setup_key_of(page)
        r.check("the authenticator secret is shown to a remote setup",
                len(secret) >= 16, secret)

        status, _, page = away.post("/setup.php", {
            "csrf": csrf_of(page), "do": "enrol", "code": totp(secret),
        })
        r.check("the right code creates the account remotely", status == 302)

        r.check("and the setup key file is gone the moment it does",
                not key.exists(),
                "auth_setup_done() removed it, then something re-created it")

        status, _, page = away.get("/setup.php")
        r.check("ten recovery codes are shown", len(recovery_codes_of(page)) == 10)
        r.check("and rendering that screen does not re-mint the key",
                not key.exists(),
                "the codes stage skips the 'setup is over' redirect, so it must "
                "not fall through to auth_setup_token()")
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        shutil.rmtree(work, ignore_errors=True)


def test_refuses_damaged_accounts(r: Results, sendmail: Path):
    """
    A damaged account file must not be mistaken for a site nobody has set up.

    WHAT GOES WRONG WITHOUT THIS
    store_read() answers null for a file that is absent and for one that will
    not parse, so auth_has_accounts() says "no accounts" to both. The admin
    then offers setup on a site that already has an administrator — and the
    first save copies the damaged file over admins.json.bak, which may be the
    only intact copy left. The screen suggests the one action that destroys
    what you would have recovered from.

    So the test is not only that it refuses. It is that the .bak is still
    there afterwards, and that restoring it puts everything back.
    """
    r.section("a damaged account file")

    work = Path(tempfile.mkdtemp(prefix="t4t-damaged-"))
    private = work / "private"
    port = free_port()
    proc = start_server(port, private, sendmail)

    try:
        c = Client(port)
        c.get("/setup.php")          # the store is created on first use

        accounts = private / "admins.json"
        backup = Path(str(accounts) + ".bak")
        good = json.dumps({
            "updated": "2026-08-23T00:00:00+00:00",
            "accounts": [{"user": USER, "hash": "x", "totp": "x"}],
        }, indent=2)

        accounts.write_text(good)
        backup.write_text(good)

        status, headers, _ = c.get("/")
        r.check("with a readable account file the admin runs",
                status == 302 and "login" in headers.get("Location", ""),
                f"status {status}")

        for name, damaged in [("truncated", good[: len(good) // 2]),
                              ("empty", ""),
                              ("not json at all", "<html>404</html>")]:
            accounts.write_text(damaged)

            status, _, page = c.get("/")
            r.check(f"{name}: the admin refuses",
                    status == 503 and "cannot start safely" in page,
                    f"status {status}")

            status, _, page = c.get("/setup.php")
            r.check(f"{name}: and does not offer to set up a new account",
                    status == 503 and "Set up the admin" not in page,
                    f"status {status}")

            r.check(f"{name}: the backup is left alone",
                    backup.read_text() == good)

        # The way out that the refusal itself prescribes. A recovery nobody has
        # walked is a recovery nobody should be told to rely on.
        accounts.write_text(backup.read_text())

        status, headers, _ = c.get("/")
        r.check("restoring the .bak brings the admin back",
                status == 302 and "login" in headers.get("Location", ""),
                f"status {status}")
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        shutil.rmtree(work, ignore_errors=True)


def test_refuses_bad_setup(r: Results, sendmail: Path):
    r.section("refusing to run unsafely")

    port = free_port()

    # Inside the DOCUMENT ROOT, which on this host is public/ — not the
    # repository. content/ used to be the obvious "inside the website" and
    # stopped being it the day the admin moved to a public/ root: a store
    # there is now correctly outside the web root, and pointing at it would
    # test that the admin starts, which is the opposite of the point.
    inside = DOCROOT / ".test-private"
    env = dict(os.environ, T4T_PRIVATE=str(inside))
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(DOCROOT),
         "-d", f"sendmail_path={sendmail}", str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        start_new_session=True, env=env,
    )
    try:
        for _ in range(60):
            try:
                with socket.create_connection(("127.0.0.1", port), 0.2):
                    break
            except OSError:
                time.sleep(0.1)

        status, _, page = Client(port).get("/")
        r.check("a private directory inside the web root is refused",
                status == 503, f"status {status}")
        r.check("and it says why", "cannot start safely" in page)
        r.check("and stays out of search results", 'content="noindex' in page)
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        shutil.rmtree(inside, ignore_errors=True)


# --------------------------------------------------------------------- main


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found. This test needs the PHP CLI:\n"
                         "  sudo apt install php-cli")

    work = Path(tempfile.mkdtemp(prefix="t4t-auth-"))
    private = work / "private"
    maildir = work / "mail"
    maildir.mkdir(parents=True)

    sendmail = work / "sendmail.sh"
    sendmail.write_text(
        "#!/bin/sh\n"
        f'cat > "{maildir}/mail-$$-$(date +%s%N).txt"\n'
    )
    sendmail.chmod(0o755)

    port = free_port()
    print(f"php -S 127.0.0.1:{port}   (private store in {private})")

    proc = start_server(port, private, sendmail)
    r = Results()
    mail = Mailbox(maildir)

    try:
        c = Client(port)
        secret, codes = test_setup(c, r, private)
        test_login(c, r, secret)
        test_signout(c, r, secret)
        # After the sign-out, because /login.php redirects while a session is
        # live and this would be reading the admin instead.
        test_password_switches(c, r)
        test_totp_replay(c, r, secret, private)
        test_csrf_and_redirect(c, r)
        test_lockout(c, r, secret, private)
        test_reset(c, r, mail, secret, private)
        test_recovery_code(c, r, codes, private)
        test_audit(r, private, codes, secret)
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)

    test_setup_key_demanded_remotely(r, sendmail)
    test_codes_die_with_the_key(r)
    test_refuses_damaged_accounts(r, sendmail)
    test_refuses_bad_setup(r, sendmail)

    shutil.rmtree(work, ignore_errors=True)

    total = r.passed + len(r.failed)
    print(f"\n{r.passed}/{total} checks passed")

    if r.skipped:
        print("\nskipped:")
        for name in r.skipped:
            print(f"  - {name}")

    if r.failed:
        print("\nfailed:")
        for name in r.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
