#!/usr/bin/env python3
"""
Facts stated in two documents must still agree.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_shared_facts.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
The privacy policy states the offices, the email and the telephone, and so does
the contact page. They are authored separately on purpose: a controller's
details are a legal statement, and one that changed because somebody edited
another page would be a statement nobody made. But two copies of a fact drift,
and these two already had -- the telephone read "+880 1320 571562" on one page
and "+880 1320571562" on the other, and the Brussels office had a comma on one
and not the other. Nothing told anybody.

WHAT IT CAN AND CANNOT SEE
Only the SEED. Content edited in the admin never passes through git -- the
tracked content/*.json are what a fresh deploy starts from, and the host's
copies always win. So this catches a developer committing a drifted seed and
nothing an editor ever does. The half it cannot see is covered where it can be:
the privacy editor draws the same comparison as a standing notice, on every
render, from the same function.

WHY IT SHELLS OUT TO PHP
privacy_shared_facts() lives in lib/contract.php, which is byte-identical
across both repositories. A second implementation of "does the policy still say
this" in Python is exactly the disagreement that shared file exists to prevent
-- it would be the third place the same question was answered, and the first to
answer it differently.

The comparison is on a NORMALISED form: non-breaking spaces and runs of
whitespace collapsed, commas and full stops dropped, case folded. That is what
makes it useful rather than noisy -- it reports a different street and stays
quiet about a different comma.

THERE USED TO BE A SECOND COMPARISON HERE and it has stopped having anything to
compare. The Organization graph's sameAs list -- the profiles that are this
company elsewhere -- was edited on the SEO screen while the footer linked to
the same profiles in literal markup, so the two could part and nobody reading
one was looking at the other. The footer's social links are DERIVED from those
rows now (chrome_social() in the frontend's lib/chrome.php), so there is one
copy and no comparison to draw. ADR 0023.
"""
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

PHP = r"""
require __DIR__ . '/lib/contract.php';

$privacy = json_decode(file_get_contents(__DIR__ . '/content/privacy.json'), true);
$contact = json_decode(file_get_contents(__DIR__ . '/content/contact.json'), true);

if (!is_array($privacy) || !is_array($contact)) {
    fwrite(STDERR, "could not read both documents\n");
    exit(2);
}

echo json_encode([
    'facts' => privacy_shared_facts(
        contract_normalise('privacy', $privacy),
        contract_normalise('contact', $contact)
    ),
]);
"""


def main() -> int:
    for name in ("privacy", "contact"):
        if not (ROOT / "content" / f"{name}.json").is_file():
            print(f"content/{name}.json is missing — nothing to compare.")
            return 0

    run = subprocess.run(["php", "-r", PHP], cwd=ROOT, capture_output=True, text=True)
    if run.returncode != 0:
        print(run.stderr.strip() or "php failed")
        return 1

    payload = json.loads(run.stdout)
    facts = payload["facts"]
    if not facts:
        print("The contact document states no facts the policy repeats.")
        return 0

    missing = []
    for fact in facts:
        mark = "ok   " if fact["found"] else "FAIL "
        print(f"  {mark} {fact['label']:<24} {fact['value']}")
        if not fact["found"]:
            missing.append(fact)

    print()
    if not missing:
        print(f"The privacy policy still states all {len(facts)} facts the contact page manages.")
        return 0

    for fact in missing:
        print(f"  - {fact['label']}: the seed privacy policy does not state "
              f"{fact['value']!r}, which is what the contact page manages")
    print()
    print("Either the policy is carrying an older value, or the contact page changed and the")
    print("policy has not been reviewed. Both are decisions for a person: edit content/privacy.json")
    print("through the admin, not by hand — content/ on the host is live data and the seed here is")
    print("only what a fresh deploy starts from.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
