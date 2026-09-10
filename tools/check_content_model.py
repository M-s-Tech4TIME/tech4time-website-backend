#!/usr/bin/env python3
"""
Prove the editor, the data and the page still describe the same thing.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_content_model.py

WHY THIS EXISTS
An editable page is three things that have to agree:

    the model      lib/contract.php  — what a field is called and what it holds
    the form       sections/         — where somebody types it
    the renderer   pages/…/index.php — where it comes out

The model moved to lib/contract.php with the repository split: it is the one
file the frontend and the backend hold byte-identical, because a field they
disagree about is a field one of them loses. The helpers that read it stayed
where they were, so "the renderer" is now the page plus lib/contact.php.

Nothing forces them to. Add a band to the contact page and forget the editor,
and the band is unmanageable; drop a band from the page and leave the field in
the editor, and somebody types into a box that changes nothing they can see.
Neither failure raises an error. Both are found here.

This is a structural check, not a taste one: it asserts that every field in the
model is written by the form and read by the page, and that neither of the
other two reaches for a field the model does not define. It cannot tell you
whether the band looks right — that is what the screenshots are for.

TWO REPOSITORIES, ONE CHECK, HALF EACH
Since the split there is no repository holding both the form and the page. The
frontend has the renderer; the backend has the editor; both hold the model,
byte-identical, which is what makes the two halves add up to the whole check.

So this runs whichever half is present and SAYS which one, rather than quietly
checking less than it used to. It refuses to run at all if neither is here,
because "nothing to check" and "everything passed" must not print the same
thing.

It also asserts that it is being asked about every editor there is. Comparing
source text only works while the fields appear in the source as themselves; a
form or a page that loops over its fields hides them from a regex, and careers
does exactly that on both sides. Those are proved by round trip instead, and
named in COVERED_ELSEWHERE — so an editor checked by neither route fails here
rather than being quietly absent, which is what it was until 2026-08-23.
"""

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Which half this repository is, worked out from what is on disk rather than
# from a constant somebody has to remember to set when copying the file.
#
#   sections/         the backend, whose admin is its whole document root
#   admin/sections/   the monolith, before the split
FORM_DIR = next(
    (d for d in (ROOT / "sections", ROOT / "admin" / "sections") if d.is_dir()),
    None,
)
PAGE_DIR = ROOT / "pages" if (ROOT / "pages" / "contact").is_dir() else None

SIDE = ("both" if FORM_DIR and PAGE_DIR else
        "backend" if FORM_DIR else
        "frontend" if PAGE_DIR else None)

# One entry per editable page. Adding a section to the admin means adding it
# here, which is deliberate: the check has to be told what to check.
SUBJECTS = [
    {
        "name": "contact",
        "model": ROOT / "lib" / "contract.php",
        # TWO FORMS, NOT ONE. The contact editor writes the page's bands;
        # sections/seo.php writes its meta band, together with every other
        # page's — see ADR 0020. A field is editable if EITHER writes it, and
        # naming only the first would report the whole meta band as
        # uneditable, which is exactly backwards.
        "form": [FORM_DIR / "contact.php", FORM_DIR / "seo.php"] if FORM_DIR else [],
        "page": (PAGE_DIR / "contact" / "index.php") if PAGE_DIR else None,
        # Where a field may be read on the way to the page. The model file is
        # in here too: contact_shown_offices() and contact_email() read fields
        # the page then never names itself.
        #
        # lib/head.php and lib/seo.php exist only in the frontend, which is
        # the half that has pages to read -- and they are here because the
        # meta band is no longer read by a page at all. Every page's <head> is
        # emitted by seo_head(), handed $data['meta'], so the title, the
        # description, the share title and the breadcrumb are read there once
        # for all seventeen pages instead of seventeen times in seventeen
        # files. Without them this check would report the whole meta band as
        # edited-but-never-rendered, which is exactly backwards. A helper that
        # is not present is skipped, so naming them costs the backend nothing.
        "helpers": [ROOT / "lib" / "contact.php", ROOT / "lib" / "contract.php",
                    ROOT / "lib" / "head.php", ROOT / "lib" / "seo.php"],
        # Where the other half of this check lives, named so that a run which
        # can only do one direction says who does the other.
        "other_half": {"frontend": "tech4time-website-backend",
                       "backend": "tech4time-website-frontend"}.get(SIDE, ""),
        # Fields nothing renders and nothing edits beyond the bookkeeping,
        # which is read from CONTRACT_BOOKKEEPING and added to both sets below
        # rather than written out twice here.
        "page_indirect": set(),
        "form_exempt": {"offices.items.id"},
    },
]

# Editors this file cannot check this way, and what proves them instead.
#
# An admin section with a page to view is an editor over a content model, so
# every one of them has to be accounted for — here or in SUBJECTS. That is the
# point of the pairing: "the check covers one of the two editable pages" was
# true for a long time and nothing said so.
#
# The named test differs per half, because the round trip it stands in for is
# only half here. The backend drives the editor; the frontend drives a signed
# document through api/publish.php and reads the page. Both walk every field
# the model declares, which is the property this entry is claiming.
COVERED_ELSEWHERE = {
    "careers": (
        "tools/test_careers_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "Both sides of the careers page are loops. The editor posts its seven "
        "body fields as name=\"<?= h($field) ?>\" and the page renders them by "
        "walking CAREERS_SECTIONS, so the regexes below read the loop variable "
        "and find 'h' and 'field' rather than 'about' and 'offers'. Adding it "
        "to SUBJECTS would mean exempting exactly the seven fields most likely "
        "to drift, and then reporting that all is well. It is proved by round "
        "trip instead: a marker through every field the model declares, over "
        "HTTP, through whichever half of the journey this repository owns.",
    ),
    "services": (
        "tools/test_services_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The services document is seven pages, not one, and every part of all "
        "seven is a loop: six service blocks over their groups, six detail "
        "pages over their layers, twenty-four layers over a hundred and "
        "thirty-seven solution cards. The renderer names nothing it draws — it "
        "walks $card['name'] and $group['items'] — so the regexes below would "
        "read the loop variables and report on 'card' and 'group'. Same "
        "bargain the careers, company, about and home pages already take, and "
        "for a stronger reason: this is the largest document in the contract "
        "and the one where a per-field allowlist would be least honest. It is "
        "proved by round trip instead — a marker through every field the model "
        "declares, over HTTP, through whichever half of the journey this "
        "repository owns.",
    ),
    "company": (
        "tools/test_company_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The company profile is six repeatable lists, and both the editor and "
        "the renderer walk them. The editor names its inputs "
        "\"<?= $band ?>[items][<?= $i ?>][name]\" and the page renders each "
        "list with foreach over company_shown(), so the regexes below read the "
        "loop variables rather than the fields. Worse than careers: the bands "
        "themselves are a loop too, over COMPANY_LISTS, so a SUBJECTS entry "
        "would have to exempt nearly the whole model and would then report a "
        "pass on a model it had not looked at. Proved by round trip instead — "
        "every field set through the editor and read back off the wire, plus "
        "add, remove, hide and reorder on all six lists.",
    ),
    "about": (
        "tools/test_about_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The about page is three repeatable lists — the story sections, the "
        "specialities and the why-us cards — and both the editor and the "
        "renderer walk them. The editor names its inputs "
        "\"<?= $band ?>[items][<?= $i ?>][title]\" and the page renders each "
        "list with foreach over about_shown(), so the regexes below read the "
        "loop variables rather than the fields. The same argument as the "
        "company profile, one list shorter: a SUBJECTS entry would have to "
        "exempt nearly the whole model and would then report a pass on a model "
        "it had not looked at. Proved by round trip instead — every field set "
        "through the editor and read back off the wire, plus add, remove, hide "
        "and reorder on all three lists.",
    ),
    "certifications": (
        "tools/test_certifications_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The certifications page is a list inside a list: four role groups, "
        "each walking its own roles and its own certifications. Both halves "
        "loop over all three levels — the editor names its inputs "
        "\"certs[items][<?= $g ?>][items][<?= $c ?>][name]\" and the page "
        "renders with foreach over certifications_rows_shown() — so the "
        "regexes below read the loop variables rather than the fields. The "
        "same argument as the about page, one level deeper. It is proved by "
        "round trip instead: every field set through the editor and read back "
        "off the wire, add, remove, hide and reorder at all three levels, and "
        "the two things the model deliberately does NOT store — the count on "
        "each group heading and the totals filled into the prose — checked "
        "against what the page actually renders.",
    ),
    "branding": (
        "tools/test_branding_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The branding page is a list inside a list: four logo variants, each "
        "walking the files a visitor can download. Both halves loop over both "
        "levels — the editor names its inputs "
        "\"assets[items][<?= $a ?>][files][<?= $f ?>][label]\" and the page "
        "renders with foreach over branding_rows_shown() — so the regexes "
        "below read the loop variables rather than the fields. The same "
        "argument as the certifications page. It is proved by round trip "
        "instead: every field set through the editor and read back off the "
        "wire, add, remove, hide and reorder at both levels, and the three "
        "things the model deliberately does NOT store — the dimensions in a "
        "meta line, the format on a download button and the glyph beside it — "
        "checked against what the page actually renders.",
    ),
    "privacy": (
        "tools/test_privacy_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The privacy policy is three lists deep — sections hold blocks, and a "
        "list, an address or a table holds rows — and it is the only document "
        "whose rows are TYPED: a block declares which of six kinds it is and "
        "the renderer owns the markup for that kind, because rt_sanitise_html() "
        "allows no heading, no <address> and no <table> and structure therefore "
        "cannot live in a rich field. Neither half can be read field by field: "
        "the editor names its inputs "
        "\"sections[<?= $s ?>][blocks][<?= $b ?>][rows][<?= $r ?>][text]\" and "
        "the page renders with foreach over privacy_rows_shown(). It is proved "
        "by round trip instead: every field set through the editor and read "
        "back off the wire, add, remove, hide and reorder at all three levels, "
        "all six block kinds rendering their own markup, and the anchor rule "
        "that a section which has been named keeps its fragment when a section "
        "with the same heading is added above it.",
    ),
    "home": (
        "tools/test_home_admin.py" if SIDE in ("backend", "both")
        else "tools/test_publish.py",
        "The home page is SIX repeatable lists — the hero's badges and tags, "
        "the terminal's lines, the technical domains, the service cards and "
        "the Get to Know Us cards — which is more than any other document "
        "here. Both the editor and the renderer walk every one of them, "
        "naming inputs \"<?= $band ?>[items][<?= $i ?>][title]\" and "
        "rendering with foreach over home_shown(), so the regexes below read "
        "the loop variables rather than the fields. Same argument as the "
        "company profile, at its strongest: a SUBJECTS entry would exempt "
        "almost the entire model and then report a pass on a model it had not "
        "looked at. Proved by round trip instead — every field set through the "
        "editor and read back off the wire, plus add, remove, hide and reorder "
        "on all six lists, and the light/dark picture pair on a card.",
    ),
}


def bookkeeping() -> set[str]:
    """The fields a document keeps about itself — asked of PHP, not listed here.

    They are neither edited nor rendered, so both directions of the check have
    to exempt them, and a second copy of the list is a second thing to keep
    true. lib/contract.php owns it; 'revision' was added there and this file
    needed no edit.
    """
    if not shutil.which("php"):
        return set()

    out = subprocess.run(
        ["php", "-r", "require 'lib/contract.php'; echo json_encode(CONTRACT_BOOKKEEPING);"],
        cwd=ROOT, capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        raise SystemExit("could not read CONTRACT_BOOKKEEPING from lib/contract.php:\n"
                         + (out.stderr or out.stdout)[:400])

    import json
    return set(json.loads(out.stdout))


def editors() -> list[str]:
    """The admin sections that edit a page of the website.

    ADMIN_PAGE_SECTIONS already means exactly this, so it is asked rather than
    re-derived: a second definition of "an editor" is a second thing to keep
    true, and this one would be wrong the moment a section gained a page to
    view without gaining a content model.
    """
    if not shutil.which("php") or not (ROOT / "lib" / "admin.php").is_file():
        return []

    out = subprocess.run(
        ["php", "-r", "require 'lib/admin.php'; echo json_encode(ADMIN_PAGE_SECTIONS);"],
        cwd=ROOT, capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        return []

    import json
    return json.loads(out.stdout)


# ---------------------------------------------------------------- the model


def model_fields(php: str) -> set[str]:
    """Every field contact_defaults() and contact_office_defaults() promise.

    Read out of the two functions that define the shape, rather than out of
    content/contact.json — the file is one instance of the shape, and an
    optional field that happens to be absent from it is still a field.
    """
    fields: set[str] = set()

    body = block(php, "function contact_defaults(): array")
    section = None
    for line in body.splitlines():
        top = re.match(r"\s{8}'(\w+)' => \[", line)
        if top:
            section = top.group(1)
            continue
        if re.match(r"\s{8}\],", line):
            section = None
            continue
        pair = re.match(r"\s+'(\w+)'\s*=>", line)
        if pair:
            fields.add(f"{section}.{pair.group(1)}" if section else pair.group(1))

    office = block(php, "function contact_office_defaults(array $office): array")
    for name in re.findall(r"'(\w+)'\s*=>", office):
        if name in ("street", "locality", "region", "postal_code", "country"):
            fields.add(f"offices.items.schema.{name}")
        else:
            fields.add(f"offices.items.{name}")

    reach = block(php, "function contact_reach_defaults(array $item): array")
    for name in re.findall(r"'(\w+)'\s*=>", reach):
        fields.add(f"reach.items.{name}")

    return fields


def block(php: str, signature: str) -> str:
    """The body of one PHP function, by brace counting from its signature."""
    at = php.index(signature)
    start = php.index("{", at)
    depth = 0
    for i in range(start, len(php)):
        if php[i] == "{":
            depth += 1
        elif php[i] == "}":
            depth -= 1
            if depth == 0:
                return php[start:i]
    raise SystemExit(f"Unbalanced braces after {signature!r}")


# --------------------------------------------------------------- the users


def leaf(field: str) -> str:
    return field.rsplit(".", 1)[-1]


def form_writes(php: str) -> set[str]:
    """The input names the editor posts, as leaf names.

    Matched on name="…" so that a field rendered but never given a name — a
    box that looks editable and saves nothing — does not count as written.

    A name reaches the markup two ways. Most sections write it there
    literally; sections/seo.php and sections/services.php hand it to a field
    helper instead — seo_text_field('meta[title]', …) — and the helper prints
    the name="…". So a quoted string shaped like a field name counts as well.
    It is the same evidence one indirection away: that string is the name
    attribute, and a helper that did not print it would fail every other
    admin suite. Recognising it is what keeps this check from going quiet on
    a whole section merely because it renders its fields through a function.
    """
    names = set()
    for value in re.findall(r'name="([^"]+)"', php):
        for part in re.findall(r"[\w]+", value):
            names.add(part)
    # 'meta[title]', "sameas[items][$i][label]" — a bare word followed by at
    # least one subscript, inside quotes. The subscript may not contain a
    # quote, so this cannot run from the end of one string into the next.
    for value in re.findall(r"""['"](\w+(?:\[[^\]'"]*\])+)['"]""", php):
        for part in re.findall(r"[\w]+", value):
            names.add(part)
    # PHP-side reads, for anything assembled rather than posted one-to-one.
    names |= set(re.findall(r"\$row\['(\w+)'\]", php))
    names |= set(re.findall(r"\$_POST\['(\w+)'\]", php))
    return names


def page_reads(php: str) -> set[str]:
    """The field names the renderer takes out of the data.

    Run over the page and over the helpers in lib/, because a field the page
    hands to a helper whole — an office to contact_flag_picture() — is read
    there rather than in the page. Those helpers are named by the subject, so
    that splitting a helper out of the model file does not silently stop the
    fields it reads from counting as read.

    Any variable subscripted by a string is counted, not a fixed list of
    variable names. That is deliberately generous: contact_addresses() copies
    the record into $s before reading it, and the next helper will use some
    other name. A list of blessed variables is the same kind of thing that
    goes stale as the drift this file exists to catch — so the check errs
    towards accepting a read, and stays strict about the absence of one.
    """
    found: set[str] = set()
    for chain in re.findall(r"\$\w+((?:\['\w+'\])+)", php):
        found |= set(re.findall(r"'(\w+)'", chain))
    return found


# -------------------------------------------------------------------- main


def icons_are_drawable() -> list[str]:
    """Every icon the model offers can actually be drawn, on this side.

    CONTACT_ICONS, COMPANY_ICONS, ABOUT_ICONS and HOME_ICONS are chosen at run
    time, which is exactly
    what neither repository's icon tooling can see. So each half has to inline
    the whole list up front, and each half does it differently:

        backend    ADMIN_ICONS in lib/admin.php, inlined on every admin page,
                   because the editor draws a live preview of the icon a row
                   was given
        frontend   a comment block in the page listing each name literally,
                   which is the only thing tools/inject_icons.py can scan

    inject_icons.py --check covers the frontend half. Nothing covered the
    backend half, and the failure is silent: an icon in the model that the
    admin does not inline renders in the editor as an empty box, and only
    somebody looking at that one row would ever notice.

    THE CHROME IS HERE FOR THE BACKEND'S HALF ONLY. The header, footer and
    dock inline what they draw as they render it, the way lib/services.php
    does, so the frontend half of this needs no comment block and
    inject_icons.py never sees those names -- but the sprite still has to hold
    a symbol for every one of them, and the editor still previews the ones a
    picker offers. Both halves of that are checked here.
    """
    problems = []

    php = subprocess.run(
        ["php", "-r",
         "require 'lib/contract.php';"
         "echo json_encode(['contact' => array_keys(CONTACT_ICONS),"
         "                  'company' => array_keys(COMPANY_ICONS),"
         "                  'about'   => array_keys(ABOUT_ICONS),"
         "                  'home'    => array_keys(HOME_ICONS),"
         "                  'services' => array_keys(SERVICES_ICONS),"
         # The chrome names icons three ways: a picker for the dock bar, a
         # fixed mark per kind of footer contact row, and a mark per social
         # host with a fallback for the rest. All three end up as a <use> in
         # the header, footer or dock, so all three are checked.
         "                  'chrome'  => array_values(array_unique(array_merge("
         "                                   array_keys(CHROME_BAR_ICONS),"
         "                                   array_values(CHROME_CONTACT_ICONS),"
         "                                   array_values(CHROME_SOCIAL_ICONS),"
         "                                   [CHROME_SOCIAL_FALLBACK])))]);"],
        cwd=ROOT, capture_output=True, text=True,
    )
    if php.returncode != 0:
        return [f"could not read the icon lists from lib/contract.php: {php.stderr.strip()}"]

    offered = json.loads(php.stdout)

    sprite = next((p for p in (ROOT / "assets" / "icons" / "sprite.svg",
                               ROOT / "public" / "assets" / "icons" / "sprite.svg")
                   if p.is_file()), None)
    if sprite is None:
        return ["no assets/icons/sprite.svg to check the icon lists against"]

    drawable = set(re.findall(r'<symbol id="([^"]+)"', sprite.read_text()))

    for document, names in sorted(offered.items()):
        for name in names:
            if name not in drawable:
                problems.append(
                    f"{document}: the model offers the icon '{name}', which is "
                    f"not in {sprite.relative_to(ROOT)} — a row given it renders "
                    f"as nothing"
                )

    if FORM_DIR is not None:
        php = subprocess.run(
            ["php", "-r", "require 'lib/admin.php'; echo json_encode(ADMIN_ICONS);"],
            cwd=ROOT, capture_output=True, text=True,
        )
        if php.returncode != 0:
            return problems + ["could not read ADMIN_ICONS from lib/admin.php"]

        inlined = set(json.loads(php.stdout))
        for document, names in sorted(offered.items()):
            for name in names:
                if name not in inlined:
                    problems.append(
                        f"{document}: the model offers the icon '{name}', which "
                        f"ADMIN_ICONS does not inline — the editor's preview of "
                        f"that row draws an empty box"
                    )

    if not problems:
        where = "the sprite and ADMIN_ICONS" if FORM_DIR else "the sprite"
        n = sum(len(v) for v in offered.values())
        print(f"icons        —  all {n} the model offers are in {where}")

    return problems


def main() -> None:
    problems: list[str] = []

    if SIDE is None:
        raise SystemExit(
            "This repository holds neither an editor (sections/) nor a renderer\n"
            "(pages/), so there is nothing here to compare the model against.\n"
            "Run it in tech4time-website-frontend or tech4time-website-backend."
        )

    print({
        "frontend": "the frontend half  —  model against the renderer",
        "backend":  "the backend half   —  model against the editor",
        "both":     "both halves        —  model, editor and renderer together",
    }[SIDE])

    problems.extend(icons_are_drawable())

    accounted = {s["name"] for s in SUBJECTS} | set(COVERED_ELSEWHERE)
    for name in editors():
        if name not in accounted:
            problems.append(
                f"'{name}' is an editor in lib/admin.php that nothing here "
                f"checks — add it to SUBJECTS, or to COVERED_ELSEWHERE naming "
                f"the test that proves its fields reach the page instead"
            )
    for name, (where, _) in sorted(COVERED_ELSEWHERE.items()):
        # A pointer at a test is only worth what the test is worth, and a
        # pointer at a test that no longer exists reads exactly like coverage.
        if not (ROOT / where).is_file():
            # The reason travels with the problem, because the first instinct
            # on reading this will be to add a SUBJECTS entry instead, and
            # that is the thing that does not work.
            problems.append(
                f"'{name}' is said to be covered by {where}, which does not "
                f"exist — the field check for that editor is gone. It is not "
                f"checked here because: {COVERED_ELSEWHERE[name][1]}"
            )
        else:
            print(f"{name}  —  checked by {where}, not here")

    for subject in SUBJECTS:
        for key in ("model", "form", "page"):
            here = subject[key]
            for one in (here if isinstance(here, list) else [here]):
                if one is not None and not one.is_file():
                    raise SystemExit(f"Missing {one.relative_to(ROOT)}")

        keep = bookkeeping()
        model_php = subject["model"].read_text()

        model = model_fields(model_php)
        form = set()
        for one in subject["form"]:
            form |= form_writes(one.read_text())

        # The renderer is the page plus the helpers it renders through:
        # contact_flag_picture() reads the flag, contact_reach_href() reads the
        # kind and the value. A field used only inside one of those is still a
        # field the visitor sees, so both files count as the page side.
        page = page_reads(subject["page"].read_text()) if subject["page"] else set()
        for helper in subject["helpers"]:
            if helper.is_file():
                page |= page_reads(helper.read_text())

        print(f"{subject['name']}  —  {len(model)} fields in the model")

        exempt_form = subject["form_exempt"] | keep
        exempt_page = subject["page_indirect"] | keep

        missing_form = sorted(
            f for f in model
            if f not in exempt_form and leaf(f) not in exempt_form and leaf(f) not in form
        ) if subject["form"] else []

        missing_page = sorted(
            f for f in model
            if f not in exempt_page and leaf(f) not in exempt_page and leaf(f) not in page
        ) if subject["page"] else []

        for field in missing_form:
            problems.append(
                f"{subject['name']}: '{field}' is in the model but nothing in "
                + " or ".join(sorted(one.relative_to(ROOT).as_posix()
                                    for one in subject["form"]))
                + " writes it — it cannot be edited"
            )
        for field in missing_page:
            problems.append(
                f"{subject['name']}: '{field}' is in the model but "
                f"{subject['page'].relative_to(ROOT)} never reads it — editing it "
                f"changes nothing a visitor sees"
            )

        # And the other direction: the form must not promise a field the model
        # does not keep, because contact_from_post() would silently drop it.
        model_leaves = {leaf(f) for f in model}
        posted = set()
        for one in subject["form"]:
            posted |= set(re.findall(
                r'name="(?:reach|offices|form|meta|hero)\[[^"]*?(\w+)\]"',
                one.read_text()))
        stray = sorted(n for n in posted if n not in model_leaves)
        for name in stray:
            problems.append(
                f"{subject['name']}: the form posts '{name}', which the model "
                f"does not define — it is discarded on save"
            )

        if missing_form or missing_page or stray:
            continue

        if subject["form"] and subject["page"]:
            print("           every field is edited, stored and rendered")
        elif subject["page"]:
            print("           every field the model defines is rendered")
            print(f"           that it is also EDITABLE is proved in "
                  f"{subject['other_half']}")
        else:
            print("           every field the model defines is editable")
            print(f"           that it also REACHES A VISITOR is proved in "
                  f"{subject['other_half']}")

    if problems:
        print(f"\n{len(problems)} problem(s):\n")
        for p in problems:
            print(f"  - {p}")
        print(
            "\nThe page's shape and the editor's shape have parted. Bring the "
            "model, the form and the renderer back into line."
        )
        if SIDE != "both":
            print(
                "lib/contract.php is byte-identical in both repositories, so a "
                "change to\nthe model is a change to the other half as well — "
                "and check_shared_lib.py\nwill say so there."
            )
        sys.exit(1)

    print({
        "frontend": "\nEvery field the model defines reaches a visitor.",
        "backend":  "\nEvery field the model defines can be edited.",
        "both":     "\nThe editors and the pages they edit describe the same thing.",
    }[SIDE])


if __name__ == "__main__":
    main()
