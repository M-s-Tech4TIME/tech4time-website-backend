#!/usr/bin/env python3
"""
Prove this half can put content on the public site, and says so when it cannot.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_publish_client.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
Every save here writes a record and then pushes it. The push is the only route
content takes to the public site, and the two failures it can have are opposite
in kind:

  it does not send        the live site silently keeps yesterday's content
  it sends and nobody     the editor reports success over a save that never
  is told it failed       arrived — the worst of the two, because nothing asks
                          anyone to look

So this checks both: that publish_push() produces a payload an INDEPENDENT
verifier accepts, and that every way it can fail comes back as something the
editor can put in front of a person.

THE VERIFIER IS IN PYTHON, DELIBERATELY
tools/publish_stub.py implements lib/publish.php's checks a second time, from
the written description. Testing this client against the real endpoint in
tech4time-website-frontend would check the two against each other and neither against
the format — a bug they shared would pass. The frontend's test_publish.py is
the mirror: it signs in Python and posts to the real PHP endpoint.

Neither side is ever checked against its own counterpart.

Every test runs against a COPY of the real data files, restored afterwards
whether the run passes or fails.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from publish_stub import PublishStub, fingerprint  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
CAREERS = ROOT / "content" / "careers.json"
CONTACT = ROOT / "content" / "contact.json"

MARK = "CLIENTMARK"


class Results:
    def __init__(self) -> None:
        self.passed = 0
        self.failed: list[str] = []

    def check(self, case: str, ok: bool, detail: str = "") -> bool:
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))
        return ok

    def report(self) -> int:
        total = self.passed + len(self.failed)
        print(f"\n{self.passed}/{total} checks passed")
        if self.failed:
            print("\nfailed:")
            for case in self.failed:
                print(f"  - {case}")
        return 1 if self.failed else 0


def php(code: str, private: Path, site: str | None = None,
        args: list[str] | None = None, **env) -> dict:
    """Run a snippet with lib/ loaded and read its JSON back."""
    environ = dict(os.environ, T4T_PRIVATE=str(private), **env)
    if site is not None:
        environ["T4T_PUBLIC_URL"] = site
    out = subprocess.run(["php", "-r", code, "--", *(args or [])], cwd=str(ROOT),
                         capture_output=True, text=True, env=environ)
    try:
        return json.loads(out.stdout.strip() or "{}")
    except ValueError:
        return {"_raw": (out.stdout + out.stderr)[:400]}


PUSH = """
require_once 'lib/publish_client.php';
require_once 'lib/careers.php';
$d = careers_load();
$d['revision'] = (int)$argv[1];
$d['jobs'][0]['title'] = $argv[2];
echo json_encode(publish_push('careers', $d));
"""

SAVE = """
require_once 'lib/careers.php';
require_once 'lib/publish_client.php';
$d = careers_load();
$d['jobs'][0]['title'] = $argv[1];
$ok = careers_save($d);
echo json_encode(['written' => $ok, 'note' => publish_note(),
                  'revision' => careers_load()['revision']]);
"""


def run(r: Results, private: Path) -> None:
    key = bytes.fromhex("7c" * 32)
    (private / "publish.key").write_text(key.hex() + "\n")

    print("\na push the far side accepts")

    with PublishStub(key) as site:
        # T4T_PUBLISH_URL is cleared so the endpoint is derived from the origin,
        # which is the path the host actually takes.
        result = php(PUSH, private, site.url, ["9", MARK], T4T_PUBLISH_URL="")
        r.check("and the endpoint was derived from the public site's origin",
                len(site.received) == 1,
                "nothing reached the stub — T4T_PUBLIC_URL was not used")
        r.check("publish_push() is accepted", result.get("ok") is True, str(result))
        r.check("the far side now holds the revision it was sent",
                result.get("revision") == site.revisions["careers"], str(result))
        r.check("and nothing but ok and the revision comes back",
                sorted(result) == ["ok", "revision"], str(result))

        sent = site.documents["careers"]
        r.check("the document arrived whole", sent["jobs"][0]["title"] == MARK, str(sent)[:160])

        last = site.received[-1]
        r.check("the signature names the key it was made with",
                last["signature"].split(":")[0] == fingerprint(key), last["signature"])
        r.check("and the timestamp travels in its own header",
                last["timestamp"].isdigit(), last["timestamp"])

        envelope = json.loads(last["body"])
        r.check("the envelope carries the contract version",
                envelope["contract_version"] == 1, str(envelope)[:120])
        r.check("and the envelope's revision matches the document's",
                envelope["revision"] == envelope["data"]["revision"], str(envelope)[:120])

    print("\nevery refusal reaches the editor as a sentence")

    with PublishStub(key) as site:
        php(PUSH, private, site.url, ["7", MARK], T4T_PUBLISH_URL="")   # revision 7 lands
        result = php(PUSH, private, site.url, ["7", MARK], T4T_PUBLISH_URL="")
        r.check("a stale push is refused, not silently dropped",
                result.get("ok") is False and result.get("code") == "not-newer", str(result))
        r.check("and carries what the far side holds",
                isinstance(result.get("revision"), int), str(result))

    with PublishStub(bytes.fromhex("11" * 32)) as site:
        result = php(PUSH, private, site.url, ["3", MARK], T4T_PUBLISH_URL="")
        r.check("a far side holding a different key answers unknown-key",
                result.get("code") == "unknown-key", str(result))

    with PublishStub(key) as site:
        site.fail_with = "not-json"
        result = php(PUSH, private, site.url, ["3", MARK], T4T_PUBLISH_URL="")
        r.check("an answer that is not JSON is reported as such",
                result.get("code") == "bad-answer", str(result))
        r.check("and the message names the endpoint to check",
                "publish.php" in str(result.get("error", "")), str(result))

    result = php(PUSH, private, "http://127.0.0.1:1", ["3", MARK], T4T_PUBLISH_URL="")
    r.check("an unreachable site is reported, not thrown",
            result.get("code") == "unreachable", str(result))

    empty = private.parent / "no-key"
    empty.mkdir(mode=0o700, exist_ok=True)
    result = php(PUSH, empty, "http://127.0.0.1:1", ["3", MARK], T4T_PUBLISH_URL="")
    r.check("no publish key is 'not configured', not 'signature rejected'",
            result.get("code") == "not-configured", str(result))
    r.check("and the message names the file and the tool that makes it",
            "publish.key" in str(result.get("error", ""))
            and "make_publish_key" in str(result.get("error", "")), str(result))

    print("\nthe save publishes, without the caller asking")

    with PublishStub(key) as site:
        before = json.loads(CAREERS.read_text()).get("revision", 0)
        result = php(SAVE, private, site.url, [f"{MARK}-saved"], T4T_PUBLISH_URL="")

        r.check("careers_save() wrote the record", result.get("written") is True, str(result))
        r.check("and published it in the same breath",
                (result.get("note") or {}).get("ok") is True, str(result))
        r.check("the revision advanced by exactly one",
                result.get("revision") == before + 1,
                f"{before} -> {result.get('revision')}")
        r.check("and the far side holds that same revision",
                site.revisions["careers"] == result.get("revision"),
                f"stub {site.revisions['careers']} vs record {result.get('revision')}")
        r.check("the published document is the one that was saved",
                site.documents["careers"]["jobs"][0]["title"] == f"{MARK}-saved",
                str(site.documents["careers"])[:160])

    print("\na failed publish does not lose the edit")

    with PublishStub(key) as site:
        site.fail_with = "write-failed"
        before = json.loads(CAREERS.read_text())["revision"]
        result = php(SAVE, private, site.url, ["KEPT"], T4T_PUBLISH_URL="")

        r.check("the write still succeeds", result.get("written") is True, str(result))
        r.check("the failure is recorded for the editor to show",
                (result.get("note") or {}).get("ok") is False, str(result))
        r.check("and the record here moved on regardless",
                result.get("revision") == before + 1,
                f"{before} -> {result.get('revision')}")

        on_disk = json.loads(CAREERS.read_text())
        r.check("the edit is on disk, so it can be sent again",
                on_disk["jobs"][0]["title"] == "KEPT", str(on_disk)[:160])


def main() -> None:
    if not shutil.which("php"):
        print("php not found — skipping. sudo apt install php-cli")
        return

    careers_backup = CAREERS.read_text() if CAREERS.is_file() else None
    contact_backup = CONTACT.read_text() if CONTACT.is_file() else None

    r = Results()
    try:
        with tempfile.TemporaryDirectory() as tmp:
            private = Path(tmp) / "t4t-private-admin"
            private.mkdir(mode=0o700)
            run(r, private)
    finally:
        for path, backup in ((CAREERS, careers_backup), (CONTACT, contact_backup)):
            if backup is not None:
                path.write_text(backup)
            bak = Path(str(path) + ".bak")
            if bak.is_file():
                bak.unlink()
        print("\ncontent/ restored")

    sys.exit(r.report())


if __name__ == "__main__":
    main()
