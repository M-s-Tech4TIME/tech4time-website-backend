#!/usr/bin/env python3
"""
Prove that the tool which repairs a host can actually repair it.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_reconcile.py
Requires the PHP CLI, and PHP's GD extension for the picture half.

WHY THIS EXISTS
tools/reconcile.py is the only thing that fixes a live site whose content or
pictures went missing — the push failed, the tab was closed, and nobody was
there to press "send again". It runs by hand, out of band, on the day something
is already wrong. Nothing ran it here, and it had two defects that had been
there since it was written:

  - ITS PICTURE HALF COULD NOT RUN AT ALL. The probe required lib/upload.php,
    which requires publish.php — where publish_asset_TYPE() and
    publish_asset_NAME() live, and not publish_asset(), the one that sends. So
    the first picture of every full run raised "Call to undefined function".
  - IT KNEW FIVE DOCUMENTS OUT OF ELEVEN. services, certifications, branding,
    privacy, seo and chrome each answered "No model here", so more than half of
    what a host holds could not be reconciled — and it said so only to somebody
    who ran it and read the line.

Both are the same shape of failure: a tool nobody exercises, whose gaps are
invisible until the day it is needed. So this exercises it, against
tools/publish_stub.py — which had to grow a picture endpoint of its own before
it could, which is the third thing nothing had noticed.

WHAT IT PROVES
  - every name in CONTRACT_DOCUMENTS has a model, and every model is a
    document — the table cannot fall behind the contract in either direction;
  - every function each probe calls exists once its requires have run, which is
    the check the missing require would have failed;
  - a full run against the stub sends every document AND every picture;
  - the picture half PACES ITSELF and can be RESUMED: --assets-limit stops and
    prints the cursor, --assets-after carries on from it, and a refusal
    mid-run prints the cursor too;
  - a second run sends nothing, because a picture is content-addressed and a
    document at the same revision is refused as 'not-newer'.

Every run works against COPIES of content/ and public/uploads/, restored
afterwards whether it passes or fails. Reconciling writes to both: a document
that has never been published is saved here before it is sent.
"""

import json
import os
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from publish_stub import PublishStub                       # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
CONTENT = ROOT / "content"
UPLOADS = ROOT / "public" / "uploads"
RECONCILE = ROOT / "tools" / "reconcile.py"

# Words that look like a call and are not one. Everything else a probe names
# has to exist by the time that probe runs.
NOT_CALLS = {"if", "elseif", "for", "foreach", "while", "switch", "echo", "print",
             "return", "exit", "die", "require", "require_once", "include",
             "include_once", "array", "isset", "unset", "empty", "list", "fn",
             "function", "static", "new", "catch", "match"}

# A call, and not: a variable one ($load()), a method (->x()), or a name inside
# a comment. The comments here are long and they NAME functions — "the reason
# contract_normalise() gives at length" — so scanning them asks whether a
# sentence compiles. The first version of this file did, and reported four
# functions missing from code that was correct.
CALL = re.compile(r"(?<![$\w>*])([a-z_][a-z0-9_]*)\s*\(")
COMMENT = re.compile(r"/\*.*?\*/|//[^\n]*", re.S)


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []
        self.skipped = 0

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    def skip(self, case):
        self.skipped += 1
        print(f"  --    {case}  (needs GD)")


def probe(name: str) -> str:
    """One of reconcile.py's PHP snippets, read out of the file itself."""
    text = RECONCILE.read_text()
    m = re.search(rf'^{name} = """(.*?)"""', text, re.S | re.M)
    if not m:
        raise SystemExit(f"test_reconcile: no {name} in tools/reconcile.py")
    return m.group(1)


def php(code: str, **env) -> dict:
    out = subprocess.run(["php", "-r", code], cwd=str(ROOT), capture_output=True,
                         text=True, env=dict(os.environ, **env))
    try:
        return json.loads(out.stdout.strip() or "{}")
    except ValueError:
        return {"fatal": (out.stderr or out.stdout).strip()[:400]}


def has_gd() -> bool:
    out = subprocess.run(["php", "-r", "echo extension_loaded('gd') ? '1' : '';"],
                         capture_output=True, text=True)
    return out.stdout.strip() == "1"


def run_reconcile(private: Path, site: str, *args) -> str:
    env = dict(os.environ, T4T_PRIVATE=str(private), T4T_PUBLIC_URL=site,
               T4T_PUBLISH_URL="")
    out = subprocess.run([sys.executable, str(RECONCILE), *args], cwd=str(ROOT),
                         capture_output=True, text=True, env=env)
    return out.stdout + out.stderr


def cursor_in(output: str) -> str:
    for line in output.splitlines():
        if "--assets-after" in line:
            return line.split("--assets-after")[1].strip()
    return ""


def make_pictures(n: int) -> list:
    """n distinct pictures in public/uploads/, through the real uploader."""
    code = ("require 'lib/upload.php';"
            "$o = []; for ($i = 0; $i < %d; $i++) {"
            "  $im = imagecreatetruecolor(40 + $i, 30);"
            "  imagefilledrectangle($im, 0, 0, 39 + $i, 29,"
            "     imagecolorallocate($im, $i * 7 %% 255, 90, 160));"
            "  ob_start(); imagepng($im); $b = ob_get_clean(); imagedestroy($im);"
            "  $o[] = upload_store($b);"
            "} echo json_encode($o);" % n)
    return php(code) or []


# --------------------------------------------------------------- the cases

def the_table(r: Results) -> None:
    print("every document this host holds can be reconciled")

    known = php("require 'lib/contract.php'; echo json_encode(CONTRACT_DOCUMENTS);")
    known = known if isinstance(known, list) else []

    # Read out of the probe rather than typed here: a second list would be a
    # second thing to keep in step, which is the bug this check exists for.
    # The $models block only — the refusal below it builds a 'result' => [...]
    # that looks exactly like an entry.
    table = re.search(r"\$models = \[(.*?)^\];", probe("PROBE"), re.S | re.M)
    modelled = re.findall(r"^\s*'([a-z_-]+)'\s*=>\s*\[",
                          table.group(1) if table else "", re.M)

    missing = [d for d in known if d not in modelled]
    extra = [d for d in modelled if d not in known]

    r.check("every name in CONTRACT_DOCUMENTS has a model",
            not missing, f"no model for {missing}")
    r.check("and every model is a name in CONTRACT_DOCUMENTS",
            not extra, f"models nothing: {extra}")
    # A count, not a number written here: this said "eleven, not five" and was
    # wrong within a day of being written, which is the whole failure it exists
    # to catch, one level up.
    r.check(f"which is all {len(known)} of them, and not the five it knew",
            len(modelled) == len(known),
            f"{len(modelled)} models, {len(known)} documents")


def the_functions(r: Results) -> None:
    print("\neverything each probe calls exists by the time it runs")
    # The missing require would have failed exactly this: the picture half
    # called publish_asset(), which lib/upload.php does not bring in.
    for name in ("PROBE", "ASSET_PROBE"):
        body = probe(name)
        requires = "\n".join(re.findall(r"^\s*require(?:_once)?\s+'[^']+';", body, re.M))
        calls = sorted({c for c in CALL.findall(COMMENT.sub(" ", body))
                        if c not in NOT_CALLS})

        answer = php(requires + "\n$missing = [];"
                     "foreach (" + json.dumps(calls) + " as $f) {"
                     "  if (!function_exists($f)) { $missing[] = $f; } }"
                     "echo json_encode(['missing' => $missing, 'asked' => count("
                     + json.dumps(calls) + ")]);")

        r.check(f"{name}: all {answer.get('asked', '?')} of them are defined",
                answer.get("missing") == [], str(answer)[:250])


def against_the_site(r: Results, private: Path) -> None:
    key = bytes.fromhex("3d" * 32)
    (private / "publish.key").write_text(key.hex() + "\n")

    made = make_pictures(6)
    if any("error" in m for m in made):
        r.check("the pictures for this test were stored", False, str(made)[:200])
        return

    held = sorted(p.name for p in UPLOADS.iterdir()
                  if p.is_file() and p.name != ".gitignore")

    print(f"\na full run, with {len(held)} pictures in the store")
    with PublishStub(key) as site:
        out = run_reconcile(private, site.url, "--pace", "0")

        r.check("every document reaches the live site",
                len(site.documents) == len(site.revisions),
                f"{sorted(site.documents)}")
        r.check("and so does every picture",
                sorted(site.assets) == held,
                f"{len(site.assets)} of {len(held)}")
        r.check("and it says both halves agree", "Both halves agree" in out,
                out[-300:])

        print("\nand again, which must change nothing")
        out = run_reconcile(private, site.url, "--pace", "0")
        r.check("a document at the same revision is refused as not-newer",
                "in step at revision" in out, out[-300:])
        r.check("and every picture is answered 'held'",
                f"all {len(held)} are already there" in out, out[-300:])

    print("\nstopping, and carrying on from where it stopped")
    with PublishStub(key) as site:
        out = run_reconcile(private, site.url, "--pace", "0", "--assets-limit", "2")
        mark = cursor_in(out)

        r.check("a limit stops the run", "(the limit)" in out, out[-400:])
        r.check("after exactly that many pictures", len(site.assets) == 2,
                f"{len(site.assets)}")
        r.check("and it prints the cursor to carry on from",
                mark in held, f"{mark!r}")
        r.check("which is the last one it sent",
                mark == sorted(site.assets)[-1], f"{mark!r} vs {sorted(site.assets)}")

        out = run_reconcile(private, site.url, "--pace", "0", "--assets-after", mark)
        r.check("carrying on from it finishes the rest",
                sorted(site.assets) == held, f"{len(site.assets)} of {len(held)}")
        r.check("without sending the ones already done again",
                f"missing {len(held) - 2} of {len(held) - 2}" in out, out[-300:])

    print("\nwhen the live site refuses one")
    with PublishStub(key) as site:
        site.fail_with = "not-an-image"

        out = run_reconcile(private, site.url, "--pace", "0")
        r.check("the run stops rather than pressing on", "FAIL" in out, out[-300:])
        # Nothing had gone, so there is no cursor and the honest thing to say
        # is "run it again", not "--assets-after " with nothing after it.
        r.check("and says to run it again, having sent nothing",
                "run it again" in out and "--assets-after" not in out, out[-400:])

        # Refused partway through, where there IS somewhere to carry on from.
        out = run_reconcile(private, site.url, "--pace", "0",
                            "--assets-after", held[1])
        r.check("refused partway, it names the place to carry on from",
                cursor_in(out) == held[1], f"{cursor_in(out)!r} vs {held[1]!r}")

    print("\nthe pace is a real pause, not a comment")
    # The whole point: the host's firewall drops an IP that hurries, and a
    # laddered store is three times as many requests for the same artwork.
    with PublishStub(key) as site:
        began = time.time()
        run_reconcile(private, site.url, "--pace", "0.4", "--assets-limit", "4")
        took = time.time() - began

    # Three gaps between four pictures. Generous either side: this is asserting
    # that a pause happens at all, not timing the machine.
    r.check("four pictures at --pace 0.4 take about three gaps' worth",
            1.0 < took < 6.0, f"{took:.1f}s")


def run(r: Results, gd: bool) -> None:
    the_table(r)
    the_functions(r)

    if not gd:
        for case in ("a full run", "stopping and carrying on",
                     "a refusal", "the pace"):
            r.skip(case)
        return

    with tempfile.TemporaryDirectory() as tmp:
        against_the_site(r, Path(tmp))


def main() -> None:
    UPLOADS.mkdir(parents=True, exist_ok=True)
    content = {p.name: p.read_bytes() for p in CONTENT.glob("*.json")}
    uploads = {p.name: p.read_bytes() for p in UPLOADS.iterdir() if p.is_file()}

    r = Results()
    try:
        run(r, has_gd())
    finally:
        # Reconciling a document that has never been published SAVES it here
        # before sending it — a revision minted, the shape brought up to date.
        # These files are committed, so putting them back is not tidiness.
        for name, blob in content.items():
            (CONTENT / name).write_bytes(blob)
        for p in list(UPLOADS.iterdir()):
            if p.is_file() and p.name not in uploads:
                p.unlink()
        for name, blob in uploads.items():
            (UPLOADS / name).write_bytes(blob)
        print("\ncontent/ and public/uploads/ restored")

    if r.failed:
        print(f"\n{len(r.failed)} of {r.passed + len(r.failed)} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        raise SystemExit(1)

    print(f"\n{r.passed}/{r.passed} checks passed"
          + (f", {r.skipped} skipped" if r.skipped else ""))


if __name__ == "__main__":
    main()
