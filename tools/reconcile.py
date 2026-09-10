#!/usr/bin/env python3
"""
Make the live site agree with this one, when a publish went missing.

Operations tool. NOT deployed to the web server (see tools/README.md).
Belongs to the BACKEND.

    python3 tools/reconcile.py                     # from the repository, locally
    python3 ~/reconcile.py ~/admin.tech4time.bd    # uploaded, on the host
    python3 tools/reconcile.py careers             # one document
    python3 ~/reconcile.py --assets-after 3f2a…    # carry on where one stopped

A full run also re-sends any uploaded picture the live site is missing. Content
and pictures travel separately (ADR 0019), so they go missing separately.

THE PICTURE HALF PACES ITSELF, and has to. Every picture is one signed POST,
a picture is now stored at three widths in two formats, and the host's firewall
drops an IP that makes about a hundred requests in a few minutes — at the TCP
layer, so it reads as an outage rather than as a limit. See ASSET_PACE. A run
prints each name as it goes and, however it stops, prints the command that
carries on from there.

IT MUST RUN ON PYTHON 3.9
That is what the cPanel host has, and this is the only tool here that runs
there rather than on a development machine. Nothing in it may use syntax newer
than 3.9 — no match statements, and annotations only under the __future__
import below. A tool that cannot start is worse than no tool, and the day it
would be discovered is the day content has gone missing.

UPLOADED AND RUN, LIKE admin-cli.php
tools/ is never deployed, so this is not on the host — and the host is the only
place it is useful, because it reads THAT machine's content/ and THAT machine's
private store. Running it from a development clone would publish development
content to whatever it is pointed at.

So it takes the site root as an argument, the same way tools/admin-cli.php
does, and lives in the HOME directory above the deploy target rather than
inside it. Upload it, run it, delete it.

WHY THIS EXISTS
Content reaches the public site by being pushed on save. Most of the time that
works and the editor says so; when it does not, the editor says that too and
offers to send it again. This is for the case where nobody was there to press
it — the push failed, the tab was closed, and the two have quietly disagreed
ever since.

It runs OUT OF BAND. Never during a page render, never on a schedule that
could collide with a save. Somebody runs it, reads what it says, and acts.

HOW IT KNOWS WITHOUT ASKING
There is no status endpoint, on purpose: a second route into the public site is
a second thing to secure and a second thing to keep in step. Instead every
answer from api/publish.php carries the revision that host now holds — the
refusals as well as the acceptance — so an attempt IS the question.

An attempt that is refused as 'not-newer' has changed nothing, which is what
makes it safe to use as a probe.

    never published     this host's document carries no revision, because it
                        was put here by hand rather than saved. The save
                        functions mint one and send it — that IS a first
                        publish, and it is the case this tool exists for on a
                        newly built host.
    accepted            the live site was behind. It is not any more.
    not-newer, equal    the two are in step. Nothing to do.
    not-newer, higher   the LIVE SITE is ahead of this one. Do not force it;
                        something published from somewhere else, or this
                        host's record was restored from a backup. A person
                        has to decide which copy is right.
    anything else       the failure, in the words the editor would use.
"""

# The host runs Python 3.9, which reads annotations at definition time and does
# not know "str | None". This is the one tool here that runs on the host rather
# than on a development machine, so it is the one that has to say so. Without
# it, the failure is a TypeError on import, on the day content is missing.
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

# HOW LONG TO WAIT BETWEEN SENDING ONE PICTURE AND THE NEXT.
#
# The host's LiteSpeed/cPanel firewall drops an IP that makes roughly a hundred
# requests in a few minutes, and it drops it at the TCP layer: connections open
# and then hang, which reads as an outage rather than as a limit. Every picture
# here is one signed POST whether the live site keeps it or answers 'held', so
# a store that used to hold one pair per picture and now holds a ladder of
# three is three times as many requests for the same artwork.
#
# Three seconds means a three-minute window holds sixty, which is comfortably
# under. A full run over a few hundred files takes minutes rather than seconds,
# and that is the right trade for a repair tool somebody starts by hand and
# watches: the failure it replaces is an IP that cannot reach the site at all
# for the next while, on the day content is already missing.
ASSET_PACE = 3.0


def locate_root(explicit: str | None) -> Path:
    """The site root: where lib/ is.

    Named the same way admin-cli.php names it, and found the same way — an
    explicit argument first, then the places this file might have been put.
    """
    tried = []

    for candidate in filter(None, [
        explicit,
        str(Path(__file__).resolve().parent.parent),   # tools/, in the repository
        str(Path.home() / "admin.tech4time.bd"),       # cPanel, after the split
        str(Path(__file__).resolve().parent / "admin.tech4time.bd"),
    ]):
        here = Path(candidate).expanduser()
        tried.append(str(here))
        if (here / "lib" / "publish_client.php").is_file():
            return here

    raise SystemExit(
        "Could not find lib/publish_client.php.\n\n"
        "Pass the site root as the first argument:\n"
        "  python3 reconcile.py ~/admin.tech4time.bd\n\n"
        "Looked in:\n  " + "\n  ".join(tried)
    )


ROOT = Path(__file__).resolve().parent.parent   # replaced in main()

PROBE = """
require_once 'lib/publish_client.php';
require_once 'lib/careers.php';
require_once 'lib/contact.php';
require_once 'lib/company.php';
require_once 'lib/about.php';
require_once 'lib/home.php';
require_once 'lib/services.php';
require_once 'lib/certifications.php';
require_once 'lib/branding.php';
require_once 'lib/privacy.php';
require_once 'lib/seo.php';
require_once 'lib/chrome.php';

/* A TABLE, AND NOT A TERNARY, for the reason contract_normalise() gives at
   length. What stood here was

       $data = $document === 'careers' ? careers_load() : contact_load();

   three times over, and the documents come from CONTRACT_DOCUMENTS just below
   — so reconciling 'company' loaded the CONTACT page, saved it, and published
   it under the name 'company'. The one tool that exists to repair a host was
   able to overwrite a second document while doing it. The refusal has to be
   the default, not the fallthrough. */
/* EVERY NAME IN CONTRACT_DOCUMENTS, and tools/test_reconcile.py asserts that
   in both directions. It held five of eleven: services, certifications,
   branding, privacy, seo and chrome each answered "No model here", so the one
   tool that repairs a host after a failed publish could repair fewer than half
   of what a host holds — and said so only to somebody who ran it and read the
   line. The refusal above is still right; a table that quietly falls behind
   the contract is not. */
$models = [
    'careers'        => ['careers_load',        'careers_save'],
    'contact'        => ['contact_load',        'contact_save'],
    'company'        => ['company_load',        'company_save'],
    'about'          => ['about_load',          'about_save'],
    'home'           => ['home_load',           'home_save'],
    'services'       => ['services_load',       'services_save'],
    'certifications' => ['certifications_load', 'certifications_save'],
    'branding'       => ['branding_load',       'branding_save'],
    'privacy'        => ['privacy_load',        'privacy_save'],

    /* These two have no *_save(): their screens edit one band at a time, so
       what they have is *_edit(), which takes the change rather than the
       document. Only the never-published branch below calls the save half, and
       an edit that changes nothing is exactly what a first publish is — write
       the record with a revision on it and push it. The $data it is handed is
       ignored on purpose: *_edit() re-reads inside store_edit(), which is the
       copy that gets written. */
    'seo'    => ['seo_load',
                 static fn(array $_d): bool => seo_edit(static fn(array $h): array => $h)],
    'chrome' => ['chrome_load',
                 static fn(array $_d): bool => chrome_edit(static fn(array $h): array => $h)],
];

$document = $argv[1];
if (!isset($models[$document])) {
    echo json_encode(['mine' => 0, 'first' => false,
                      'result' => ['ok' => false, 'code' => 'unknown-document',
                                   'error' => 'No model here for ' . $document . '.']]);
    exit;
}
[$load, $save] = $models[$document];

$data = $load();

/* A document that has never been saved carries revision 0, and the receiving
   side refuses anything below 1 — so a host whose content/ was put in place by
   hand could never publish it, which is exactly the case this tool exists for.

   Minting a revision is each model's *_save()'s job and nobody else's, so this
   asks THEM rather than doing it here. They write the record and publish it in
   one step, which is what a first publish is. */
if ((int)($data['revision'] ?? 0) < 1) {
    $ok = $save($data);
    $after = $load();

    echo json_encode([
        'mine'    => (int)($after['revision'] ?? 0),
        'first'   => true,
        'result'  => $ok ? (publish_note() ?? ['ok' => false, 'code' => 'no-attempt'])
                         : ['ok' => false, 'code' => 'write-failed',
                            'error' => 'Could not write ' . $document . '.json here.'],
    ]);
    exit;
}

echo json_encode([
    'mine'   => (int)($data['revision'] ?? 0),
    'first'  => false,
    'result' => publish_push($document, $data),
]);
"""


def documents() -> list[str]:
    out = subprocess.run(
        ["php", "-r", "require 'lib/contract.php'; echo json_encode(CONTRACT_DOCUMENTS);"],
        cwd=str(ROOT), capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        raise SystemExit("could not read CONTRACT_DOCUMENTS from lib/contract.php:\n"
                         + (out.stderr or out.stdout)[:400])
    return json.loads(out.stdout)


def endpoint() -> str:
    out = subprocess.run(
        ["php", "-r", "require 'lib/publish_client.php'; echo publish_endpoint();"],
        cwd=str(ROOT), capture_output=True, text=True,
    )
    return out.stdout.strip() or "(unknown)"


def reconcile(document: str) -> bool:
    """Returns True when the two are in step afterwards."""
    out = subprocess.run(
        ["php", "-r", PROBE, "--", document],
        cwd=str(ROOT), capture_output=True, text=True,
    )

    try:
        answer = json.loads(out.stdout.strip())
    except ValueError:
        print(f"  FAIL  {document}: could not run the push\n"
              f"          {(out.stderr or out.stdout)[:300].strip()}")
        return False

    mine = answer["mine"]
    result = answer["result"]

    if result.get("ok") and answer.get("first"):
        print(f"  sent  {document}: never published before — minted revision {mine} "
              f"and sent it")
        return True

    if result.get("ok"):
        print(f"  sent  {document}: the live site was behind, and now holds {mine}")
        return True

    code = result.get("code", "refused")
    theirs = result.get("revision")

    if code == "not-newer" and theirs == mine:
        print(f"  ok    {document}: in step at revision {mine}")
        return True

    if code == "not-newer" and isinstance(theirs, int) and theirs > mine:
        print(f"  FAIL  {document}: the LIVE SITE is ahead — it holds {theirs}, "
              f"this host holds {mine}")
        print( "          Do not overwrite it. Something published from elsewhere, or")
        print( "          this host's record was restored from an older backup.")
        print(f"          Compare the two before deciding which is right.")
        return False

    print(f"  FAIL  {document}: {result.get('error', code)}")
    if isinstance(theirs, int):
        print(f"          the live site holds revision {theirs}; this host holds {mine}")
    return False


ASSET_PROBE = """
/* Every stored file, whichever editor put it there — upload_held() is the
   store, not one page's view of it. No per-document model is needed. It comes
   back sorted, which is what makes a name a resumable cursor. */
require 'lib/upload.php';

/* AND THE THING THAT SENDS ONE. publish_asset() is in publish_client.php, and
   upload.php requires publish.php — which holds publish_asset_TYPE() and
   publish_asset_NAME() and not the sender. So this whole half fatalled on its
   first picture, every time, from the day it was written: "Call to undefined
   function publish_asset()". Nothing caught it because nothing ran it. */
require 'lib/publish_client.php';

$after = (string)getenv('T4T_ASSET_AFTER');
$limit = (int)getenv('T4T_ASSET_LIMIT');
$pause = (float)getenv('T4T_ASSET_PAUSE');

$done = 0;
$last = $after;

/* ONE LINE PER PICTURE, AS IT GOES, rather than one answer at the end. A paced
   run takes minutes, and a run that is interrupted — a dropped connection, an
   impatient ^C — must still have said how far it got, or the operator has no
   cursor to resume from and starts again from the beginning. */
foreach (upload_held() as $name) {
    if ($after !== '' && strcmp($name, $after) <= 0) { continue; }

    if ($limit > 0 && $done >= $limit) {
        echo json_encode(['stop' => 'limit', 'after' => $last]), "\n";
        exit;
    }

    /* Before the request, not after it, and not before the first: the pause is
       there to space REQUESTS, and pausing after the last one only makes the
       run longer. */
    if ($done > 0 && $pause > 0) { usleep((int)round($pause * 1000000)); }

    $bytes = @file_get_contents(UPLOAD_DIR . '/' . $name);

    if ($bytes === false) {
        echo json_encode(['name' => $name, 'state' => 'failed',
                          'why' => 'could not be read on this host']), "\n";
        flush();
        exit;
    }

    $kind = publish_asset_type($bytes);

    if ($kind === null) {
        echo json_encode(['name' => $name, 'state' => 'failed',
                          'why' => 'is not a picture this site publishes']), "\n";
        flush();
        exit;
    }

    $r = publish_asset($bytes, $kind[1]);
    $done++;
    $last = $name;

    if (($r['ok'] ?? false) !== true) {
        echo json_encode(['name' => $name, 'state' => 'failed',
                          'why' => (string)($r['error'] ?? 'refused')]), "\n";
        flush();
        exit;
    }

    echo json_encode(['name' => $name,
                      'state' => ($r['held'] ?? false) ? 'held' : 'sent']), "\n";
    flush();
}

echo json_encode(['stop' => 'done', 'after' => $last]), "\n";
"""


def reconcile_assets(pace: float, limit: int, after: str) -> bool:
    """Send every stored picture the live site does not already hold.

    Content and pictures travel separately (ADR 0019), so they can go missing
    separately: a publish that failed halfway leaves the document naming a file
    the other host has not got, and the page draws a broken image with no
    warning anywhere. This is the repair for that half.

    Safe to run whenever. An asset is content-addressed, so re-sending one the
    live site already has is answered 'held' and writes nothing — there is no
    revision to roll back and nothing a replay could undo.

    PACED, AND RESUMABLE, because a picture is now stored at several widths.
    See ASSET_PACE for what the host does to an IP that hurries. Every way this
    can stop — a limit, a refusal, a ^C, a dropped connection — prints the
    command that continues from where it got to, because a repair tool that
    can only start from the beginning is one nobody dares interrupt.
    """
    env = dict(os.environ,
               T4T_ASSET_AFTER=after,
               T4T_ASSET_LIMIT=str(limit),
               T4T_ASSET_PAUSE=str(pace))

    proc = subprocess.Popen(["php", "-r", ASSET_PROBE], cwd=str(ROOT), env=env,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            universal_newlines=True, bufsize=1)

    sent = 0
    held = 0
    last = after
    stopped = ""
    failure = ""

    try:
        for line in proc.stdout:
            line = line.strip()
            if not line:
                continue
            try:
                row = json.loads(line)
            except ValueError:
                continue

            if "stop" in row:
                stopped = row["stop"]
                last = row.get("after") or last
                continue

            if row.get("state") == "failed":
                failure = row["name"] + ": " + row.get("why", "refused")
                break

            last = row["name"]
            if row["state"] == "sent":
                sent += 1
                print("  sent  " + row["name"])
            else:
                held += 1
    except KeyboardInterrupt:
        proc.terminate()
        stopped = "interrupted"

    proc.wait()
    tail = (proc.stderr.read() or "").strip()

    if not stopped and not failure and proc.returncode != 0:
        print("  FAIL  pictures: could not run the push\n          " + tail[:300])
        return False

    if failure:
        print("  FAIL  " + failure)
        resume(last)
        return False

    if stopped in ("limit", "interrupted"):
        print("  ..    pictures: stopped after " + str(sent + held)
              + (" (the limit)" if stopped == "limit" else " (interrupted)"))
        resume(last)
        return False

    if sent:
        print("  sent  pictures: the live site was missing " + str(sent)
              + " of " + str(sent + held))
    else:
        print("  ok    pictures: all " + str(held) + " are already there")
    return True


def resume(after: str) -> None:
    """How to carry on from where a run stopped.

    Both branches say something. "Nothing was sent" and "seven were sent" need
    different next steps, and a run that stops with a failure and no advice
    leaves somebody guessing whether starting again would send everything
    twice. (It would not — a picture is content-addressed — but that is not
    obvious at the moment it matters.)
    """
    if after:
        print("          to carry on:  python3 " + sys.argv[0]
              + " --assets-after " + after)
    else:
        print("          no picture had been sent yet — run it again once the "
              "cause is fixed")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("root", nargs="?",
                    help="the site root, where lib/ is. Needed when this file has "
                         "been uploaded rather than run from the repository")
    ap.add_argument("document", nargs="?", help="just this one")
    ap.add_argument("--pace", type=float, default=ASSET_PACE, metavar="SECONDS",
                    help="seconds between one picture and the next (default "
                         + str(ASSET_PACE) + "). Lower it only against a host "
                         "you know does not rate-limit")
    ap.add_argument("--assets-limit", type=int, default=0, metavar="N",
                    help="stop after N pictures and say how to carry on")
    ap.add_argument("--assets-after", default="", metavar="NAME",
                    help="carry on from after this picture, as a previous run "
                         "printed it")
    args = ap.parse_args()

    # "reconcile.py careers" means the document, not a directory. Told apart by
    # asking whether it names a document rather than by counting arguments,
    # because guessing would make "reconcile.py contact" try to cd into it.
    global ROOT
    if args.root and args.document is None and not Path(args.root).expanduser().is_dir():
        args.root, args.document = None, args.root

    ROOT = locate_root(args.root)

    known = documents()

    if args.document and args.document not in known:
        raise SystemExit(f"{args.document!r} is not published. Known: {', '.join(known)}")

    wanted = [args.document] if args.document else known

    print(f"{endpoint()}\n")

    ok = all([reconcile(d) for d in wanted])

    # Only on a full run: asking for one document is asking about that
    # document, and walking every picture would be a surprise.
    if not args.document:
        ok = reconcile_assets(args.pace, args.assets_limit,
                              args.assets_after) and ok

    print("\nBoth halves agree." if ok else
          "\nSomething is out of step. Read the lines above before forcing anything.")

    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
