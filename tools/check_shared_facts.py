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

THE SECOND COMPARISON, AND WHY IT ONLY NOTICES
The Organization graph carries a sameAs list -- the profiles that are this
company elsewhere -- and it is edited on the SEO screen. The footer links to
the same profiles, in literal markup, in tools/templates/footer.html. Two
copies again, and this time neither is wrong when they differ: a profile can
legitimately be in the graph and not in the footer (an old account a search
engine should still connect) or in the footer and not the graph (a link added
for readers, not for machines).

So this half REPORTS and never refuses. It exists because the disagreement is
invisible otherwise -- the footer is markup and the graph is a document, and
nobody reading one is looking at the other. It cannot affect the exit code, on
purpose: a check that fails on a decision somebody is entitled to make is a
check people learn to skip.
"""
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FOOTER = ROOT / "tools" / "templates" / "footer.html"

# The footer's social list, and the hrefs inside it. Scoped to the list so the
# brand link, the two link columns and the legal line are not read as profiles.
SOCIAL_LIST = re.compile(r'<ul class="site-footer__social">(.*?)</ul>', re.S)
SOCIAL_HREF = re.compile(r'href="(https?://[^"]+)"')

PHP = r"""
require __DIR__ . '/lib/contract.php';

$privacy = json_decode(file_get_contents(__DIR__ . '/content/privacy.json'), true);
$contact = json_decode(file_get_contents(__DIR__ . '/content/contact.json'), true);

if (!is_array($privacy) || !is_array($contact)) {
    fwrite(STDERR, "could not read both documents\n");
    exit(2);
}

$seo = json_decode(file_get_contents(__DIR__ . '/content/seo.json'), true);
$same = [];
if (is_array($seo)) {
    foreach (contract_normalise('seo', $seo)['sameas']['items'] as $row) {
        if (($row['status'] ?? 'shown') === 'shown' && trim((string)$row['url']) !== '') {
            $same[] = ['label' => $row['label'], 'url' => trim((string)$row['url'])];
        }
    }
}

echo json_encode([
    'facts'  => privacy_shared_facts(
        contract_normalise('privacy', $privacy),
        contract_normalise('contact', $contact)
    ),
    'sameas' => $same,
]);
"""


def social_notice(sameas: list[dict]) -> None:
    """Say whether the graph's sameAs and the footer's links agree.

    Prints. Returns nothing, and the caller ignores it -- see the docstring.
    """
    print()
    if not FOOTER.is_file():
        # This file is byte-identical in both repositories, and only one of
        # them has a footer to compare against: the markup lives with the
        # pages. Said plainly rather than skipped in silence, so a run here
        # does not read as "the two agree".
        print("sameAs — not checked here; the footer is the frontend's.")
        return

    block = SOCIAL_LIST.search(FOOTER.read_text())
    if block is None:
        print("sameAs — the footer template has no <ul class=\"site-footer__social\">.")
        print("         It has been restructured; update SOCIAL_LIST in this file.")
        return

    footer = {url.rstrip("/") for url in SOCIAL_HREF.findall(block.group(1))}
    graph = {row["url"].rstrip("/") for row in sameas}

    if footer == graph:
        print(f"sameAs — the graph and the footer name the same "
              f"{len(graph)} profile(s).")
        return

    print("sameAs — the graph and the footer do not name the same profiles.")
    print("         Not a failure: either may legitimately carry one the other does not.")
    for url in sorted(graph - footer):
        print(f"    in the graph only    {url}")
    for url in sorted(footer - graph):
        print(f"    in the footer only   {url}")
    print("         The graph is edited on the SEO screen; the footer is markup,")
    print("         in tools/templates/footer.html.")


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
        social_notice(payload["sameas"])
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
        social_notice(payload["sameas"])
        return 0

    for fact in missing:
        print(f"  - {fact['label']}: the seed privacy policy does not state "
              f"{fact['value']!r}, which is what the contact page manages")
    print()
    print("Either the policy is carrying an older value, or the contact page changed and the")
    print("policy has not been reviewed. Both are decisions for a person: edit content/privacy.json")
    print("through the admin, not by hand — content/ on the host is live data and the seed here is")
    print("only what a fresh deploy starts from.")
    social_notice(payload["sameas"])
    return 1


if __name__ == "__main__":
    sys.exit(main())
