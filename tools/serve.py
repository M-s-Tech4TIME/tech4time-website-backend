#!/usr/bin/env python3
"""
Run the admin locally, the way it runs on its own host.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/serve.py            # http://localhost:8001
    python3 tools/serve.py 8080       # a different port

It listens on all interfaces (0.0.0.0), so the admin is also reachable at
this machine's LAN address -- e.g. from a phone on the same Wi-Fi, or from
outside a virtual machine over a bridged network. This is a development
server for trusted local networks only: never expose it to the internet,
and it is never deployed (see tools/README.md).

Requires the PHP CLI:  sudo apt install php-cli

IT SERVES public/, NOT THE REPOSITORY
On the host, admin.tech4time.bd's document root is public/ — so lib/,
sections/ and content/ are outside anything a URL can reach, rather than merely
blocked by a rule. tools/dev-router.php reproduces exactly that here and
refuses a path that escapes public/. A development machine on which
/../lib/auth.php resolves would teach the wrong lesson. See ADR 0018.

THE SIGN-IN IS REAL, LOCALLY TOO
Nothing is faked. Visit /setup.php once to create an account and pair an
authenticator app, then sign in as you would on the host.

The accounts, sessions and audit log go in ../t4t-private-admin — beside this
repository, never inside it, the same shape as /home/USER/t4t-private-admin on
the server. Delete that directory to start over.

It binds all interfaces on a trusted local network only, but it is still a
real sign-in on a real port: do not run it where anyone untrusted can reach
it. The sign-in, the lockout and the audit log all run exactly as on
the host.

PUBLISHING NEEDS THE OTHER HALF
Every save pushes to the public site. With nothing to push to, the editor says
so on every save — which is correct, and is what it would do on the host. To
make it work, run tech4time-website-frontend beside this and point this at it:

    T4T_PUBLIC_URL=http://localhost:8000 python3 tools/serve.py 8001

Both stores need the SAME publish.key, or every publish is refused as
unknown-key. tools/make_publish_key.py prints one; put the same value in both.

WHAT STILL WILL NOT WORK LOCALLY
mail(). A password reset code has nowhere to go, because there is no mail
server here. Use a recovery code from setup instead. The mail path is verified
on the host with tools/host-probe.php.
"""

import os
import shutil
import signal
import socket
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"

PAGES = [
    ("Sign in", "/login.php"),
    ("First-run setup", "/setup.php"),
    ("Overview", "/"),
    ("Job posts       (writes content/careers.json, then publishes)", "/?s=careers"),
    ("Contact page    (writes content/contact.json, then publishes)", "/?s=contact"),
    ("Your account", "/?s=account"),
]


def port_is_free(port: int) -> bool:
    with socket.socket() as s:
        try:
            s.bind(("0.0.0.0", port))
            return True
        except OSError:
            return False


def lan_ip() -> str | None:
    """This machine's LAN address, without sending any traffic. None when
    there is no route out -- localhost still works then."""
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        try:
            s.connect(("8.8.8.8", 80))
            return s.getsockname()[0]
        except OSError:
            return None


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit(
            "php not found. The site needs it for the careers page, the editor\n"
            "and the contact handler:\n"
            "  sudo apt install php-cli"
        )

    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
    if not port_is_free(port):
        raise SystemExit(f"port {port} is already in use — try: python3 tools/serve.py {port + 1}")

    base = f"http://localhost:{port}"
    width = max(len(label) for label, _ in PAGES)

    print(f"\n  Serving {DOCROOT}\n  (lib/, sections/ and content/ are OUTSIDE it, as on the host)\n")
    for label, path in PAGES:
        print(f"    {label.ljust(width)}   {base}{path}")
    lan = lan_ip()
    if lan and not lan.startswith("127."):
        print(f"\n  On this machine's network (phones, VMs over bridge):"
              f" {base.replace('localhost', lan)}/")
    private = ROOT.parent / "t4t-private-admin"
    first_run = not (private / "admins.json").is_file()

    if first_run:
        print(
            "\n  No admin account yet. Open /admin/setup.php to make one — you will\n"
            "  need an authenticator app to hand. Nothing is faked locally; this is\n"
            "  the same sign-in that runs on the host.\n"
        )
    else:
        print(f"\n  Signing in uses the account in {private}\n")

    print(
        "  Editing writes content/careers.json and content/contact.json for real.\n"
        "  Restore them with:\n"
        "    git checkout content/careers.json content/contact.json\n"
        "\n  Ctrl-C to stop.\n"
    )

    proc = subprocess.Popen(
        ["php", "-S", f"0.0.0.0:{port}", "-t", str(DOCROOT), str(ROUTER)],
        start_new_session=True,
    )

    try:
        proc.wait()
    except KeyboardInterrupt:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        print("\nstopped")


if __name__ == "__main__":
    main()
