#!/usr/bin/env python3
"""
Prove that nothing a browser sends is ever what gets written.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_upload.py
Requires the PHP CLI, and PHP's GD extension for most of it.

WHY THIS EXISTS
lib/upload.php is the only code in either repository that takes a file from
somebody's computer and puts it on a web server. The rule it works to is not
"check the file and then save it" — it is that the file is READ AND THEN
REPLACED. Every accepted picture is decoded by GD and re-encoded from the pixel
data, so what lands on disk is bytes that library wrote.

That single step is what removes EXIF (including the coordinates a phone puts
in a photograph), anything appended after the image data, and a file that is a
valid JPEG *and* a valid PHP script. A validator cannot do any of that: it can
only fail to find what it knew to look for. So most of what follows is not
"was this refused" but "is what came out still carrying what went in".

WITHOUT GD
The re-encoding cases are skipped with a notice, the way test_qr.py skips
without qrencode. The house-keeping cases still run, because they are the ones
that decide whether a file gets deleted. CI installs php-gd so the whole of it
runs there — .github/workflows/test.yml.
"""

import base64
import json
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
UPLOADS = ROOT / "public" / "uploads"


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


def php(code: str) -> dict:
    """Run a snippet against lib/upload.php and read back what it printed."""
    out = subprocess.run(
        ["php", "-r", "require 'lib/upload.php';" + code],
        cwd=ROOT, capture_output=True, text=True,
    )
    if out.returncode != 0:
        return {"fatal": (out.stderr or out.stdout).strip()[:400]}
    try:
        return json.loads(out.stdout)
    except json.JSONDecodeError:
        return {"fatal": "not JSON: " + out.stdout.strip()[:400]}


def has_gd() -> bool:
    out = subprocess.run(["php", "-r", "echo extension_loaded('gd') ? '1' : '';"],
                         capture_output=True, text=True)
    return out.stdout.strip() == "1"


# --- pictures, built here so the test needs no image library of its own ------

def png_chunk(kind: bytes, payload: bytes) -> bytes:
    body = kind + payload
    return struct.pack(">I", len(payload)) + body + struct.pack(">I", zlib.crc32(body))


def png(width: int, height: int) -> bytes:
    """A real, decodable PNG of a solid colour."""
    raw = b"".join(b"\x00" + bytes([200, 60, 60]) * width for _ in range(height))
    return (b"\x89PNG\r\n\x1a\n"
            + png_chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + png_chunk(b"IDAT", zlib.compress(raw))
            + png_chunk(b"IEND", b""))


def png_rgba(width: int, height: int) -> bytes:
    """A real PNG with an alpha channel: opaque left half, transparent right.

    A logo is the picture most likely to be uploaded with transparency and the
    one where losing it is most visible — a mark on a black rectangle. Every
    rung is a separate imagescale(), and imagescale() hands back an image with
    saving OFF, so this is the thing a ladder is most likely to break.
    """
    rows = []
    for _ in range(height):
        row = b""
        for x in range(width):
            row += bytes([200, 60, 60, 255 if x < width // 2 else 0])
        rows.append(b"\x00" + row)
    return (b"\x89PNG\r\n\x1a\n"
            + png_chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0))
            + png_chunk(b"IDAT", zlib.compress(b"".join(rows)))
            + png_chunk(b"IEND", b""))


# A real 1x1 JPEG, and the same JPEG with an EXIF block spliced in.
JPEG = base64.b64decode(
    "/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a"
    "HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA"
    "AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==")

EXIF_MARKER = b"SECRETLOCATION"


def jpeg_with_exif() -> bytes:
    """The same JPEG with an APP1 EXIF segment carrying a recognisable string.

    Spliced immediately after the SOI marker, which is where a camera puts it.
    Built here rather than written as a literal so that it is a REAL JPEG: a
    malformed one is refused for being malformed, and the test would then
    report that EXIF had been stripped when nothing had been decoded at all.
    That is exactly what the first version of this did.
    """
    payload = b"Exif\x00\x00" + EXIF_MARKER + b"\x00" * 32
    segment = b"\xff\xe1" + struct.pack(">H", len(payload) + 2) + payload
    return JPEG[:2] + segment + JPEG[2:]


def run(r: Results, gd: bool) -> None:
    print("what the server can do")
    problem = php("echo json_encode(['p' => upload_problem()]);")
    if gd:
        r.check("uploads are available", problem.get("p") == "", str(problem))
    else:
        r.check("and says plainly when it cannot",
                "GD" in (problem.get("p") or ""), str(problem))

    print("\nwhat is refused outright")
    for label, blob in [
        ("a PHP script", b"<?php system($_GET['c']); ?>"),
        ("an SVG, which is a document and can carry script",
         b'<svg xmlns="http://www.w3.org/2000/svg"><script>x()</script></svg>'),
        ("a GIF", base64.b64decode("R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7")),
        ("nothing at all", b""),
    ]:
        got = php("echo json_encode(upload_store(base64_decode('%s')));"
                  % base64.b64encode(blob).decode())
        r.check(f"{label} is refused", "error" in got, str(got)[:200])

    # BEFORE THE GD GATE, DELIBERATELY. Nothing here decodes a picture -- it
    # asks which paths a document CLAIMS -- so gating it behind GD would mean
    # the one check that caught a real deletion bug never ran on a machine
    # without the extension, which is most of them.
    # ALSO BEFORE THE GD GATE. This is arithmetic over a table, not a picture
    # being decoded, and it decides how many files every future upload writes.
    print("\nwhat widths a slot stores")
    ladder = php("$out = [];"
                 "foreach ([['about.story',4000],['about.story',900],['about.story',500],"
                 "         ['company.clients',1200],['company.clients',300],"
                 "         ['contact.offices',1600],['contact.offices',40],"
                 "         ['branding.file',3000],['seo.share',1200],"
                 "         ['about.story',0],['not.a.slot',1200]] as [$s,$n]) {"
                 "  $out[$s.'@'.$n] = contract_slot_widths($s,$n,UPLOAD_MAX_DIMENSION); }"
                 "echo json_encode($out);")

    r.check("a big photograph ladders to 1x, 2x and the ceiling",
            ladder.get("about.story@4000") == [700, 1400, 1600], str(ladder)[:200])
    # Capping each density is what makes both directions one rule: above 3x the
    # extra pixels go, below it the higher rungs collapse onto the source.
    r.check("a source below 2x collapses rather than upscaling",
            ladder.get("about.story@900") == [700, 900], str(ladder)[:200])
    r.check("a source below the slot itself stores only itself",
            ladder.get("about.story@500") == [500], str(ladder)[:200])
    # The rung nothing can draw from is the one worth catching: a 1600px flag
    # in a 56px slot must not carry a 1600px file to every phone that asks.
    r.check("a flag never stores a rung no screen can use",
            ladder.get("contact.offices@1600") == [56, 112, 168], str(ladder)[:200])
    r.check("nor does a large logo in a small tile",
            ladder.get("company.clients@1200") == [250, 500, 750], str(ladder)[:200])
    r.check("a download does not ladder", ladder.get("branding.file@3000") == [])
    r.check("nor does the share card", ladder.get("seo.share@1200") == [])
    r.check("nothing arrived, nothing stored", ladder.get("about.story@0") == [])
    r.check("an unknown slot ladders nothing rather than guessing",
            ladder.get("not.a.slot@1200") == [])

    for key, widths in (ladder or {}).items():
        if not isinstance(widths, list) or not widths:
            continue
        source = int(key.rsplit("@", 1)[1])
        r.check(f"{key}: no rung is wider than what arrived",
                max(widths) <= min(source, 1600), str(widths))
        r.check(f"{key}: rungs ascend and do not repeat",
                widths == sorted(set(widths)), str(widths))

    # A LADDER WITHOUT sizes= IS WORSE THAN NO LADDER. With no sizes attribute a
    # browser assumes the picture fills the viewport and picks the widest
    # candidate, so a slot that ladders and says nothing about its width would
    # send every phone the 3x file. One table, both halves, checked together.
    slots = php("echo json_encode(CONTRACT_IMAGE_SLOTS);")
    for name, row in (slots or {}).items():
        if row.get("width", 0) > 0:
            r.check(f"'{name}' ladders, so it declares sizes=",
                    (row.get("sizes") or "").strip() != "",
                    "a ladder with no sizes= sends every screen the widest rung")

    print("\nwhat counts as a picture in use")
    used = php(
        "$d = contact_normalise(contact_defaults());"
        "$d['offices']['items'][0]['image'] = contract_image_defaults(["
        "  'src' => '/uploads/00112233aabbccdd.png',"
        "  'webp' => '/uploads/44556677eeff0011.webp',"
        "  'width' => 800, 'height' => 600]);"
        "echo json_encode(contract_images('contact', contact_normalise($d)));"
    )
    # An office photograph is a real upload on the same signed channel as every
    # other. It was invisible here: contract_images() fell through to the
    # meta-only branch for 'contact', so the sweep on ANY other screen counted
    # it unused and offered to delete a picture that was on the contact page.
    r.check("an office photograph counts as used",
            "/uploads/00112233aabbccdd.png" in (used if isinstance(used, list) else []),
            str(used)[:200])
    r.check("and so does its WebP sibling",
            "/uploads/44556677eeff0011.webp" in (used if isinstance(used, list) else []),
            str(used)[:200])

    share = php(
        "$d = contact_normalise(contact_defaults());"
        "$d['meta']['share'] = contract_image_defaults(["
        "  'src' => '/assets/images/og/tech4time-og.png',"
        "  'width' => 1200, 'height' => 630]);"
        "echo json_encode(contract_images('contact', contact_normalise($d)));"
    )
    # The half that already worked must not have been traded for the half that
    # did not: this branch used to return the meta band and only the meta band.
    r.check("without losing the share card the meta band already claimed",
            "/assets/images/og/tech4time-og.png" in (share if isinstance(share, list) else []),
            str(share)[:200])

    # Every document must answer, or a new one silently loses its files. This is
    # contract_images()' own promise -- it throws on a name it does not know --
    # and asking it here is what turns that promise into something checked.
    for name in php("echo json_encode(CONTRACT_DOCUMENTS);") or []:
        got = php("echo json_encode(['n' => count(contract_images(%r, "
                  "contract_normalise(%r, [])))]);" % (name, name))
        r.check(f"contract_images() answers for '{name}'", "fatal" not in got,
                str(got)[:200])

    print("\nwhere a picture comes from says which width it is stored at")
    # A slot is a string, and a string can be misspelt. A misspelt one is not
    # an error anywhere at run time — contract_slot_widths() answers with no
    # ladder, the upload succeeds, and the picture is quietly stored at one
    # width forever. So the two lists are compared instead, in both directions:
    # every slot the contract declares is named by a screen, and every slot a
    # screen names is one the contract declares.
    declared = set(php("echo json_encode(array_keys(CONTRACT_IMAGE_SLOTS));") or [])
    named = {}
    for f in sorted((ROOT / "sections").glob("*.php")):
        for lit in re.findall(r"'([a-z][a-z_]*\.[a-z][a-z_]*)'", f.read_text()):
            named.setdefault(lit, f.name)

    r.check("every slot a screen names is one the contract declares",
            not (set(named) - declared), f"unknown: {sorted(set(named) - declared)}")
    r.check("and every slot the contract declares is named by a screen",
            not (declared - set(named)), f"never used: {sorted(declared - set(named))}")

    print("\na ladder's rungs count as pictures in use")
    # A laddered picture keeps most of its files inside srcset and nowhere
    # else: only the top rung is also the src. A collector reading src and webp
    # alone reports the other four as unused, and the sweep on ANY screen then
    # offers to delete the widths every phone is served -- leaving a <source
    # srcset> naming files that are not there, which is a broken image for
    # everybody whose browser prefers WebP. chrome_images() has walked srcset
    # since the header lockup was the only laddered picture on the site; this
    # is that walk, asked of every document, before anything starts writing
    # ladders.
    LADDER = (
        "contract_image_defaults(["
        "  'src' => '/uploads/3333333333333333.png',"
        "  'webp' => '/uploads/4444444444444444.webp',"
        "  'width' => 168, 'height' => 126,"
        "  'srcset' => '/uploads/1111111111111111.png 56w,"
        " /uploads/2222222222222222.png 112w, /uploads/3333333333333333.png 168w',"
        "  'webp_srcset' => '/uploads/5555555555555555.webp 56w,"
        " /uploads/6666666666666666.webp 112w, /uploads/4444444444444444.webp 168w',"
        "])")

    every = ["/uploads/1111111111111111.png", "/uploads/2222222222222222.png",
             "/uploads/3333333333333333.png", "/uploads/4444444444444444.webp",
             "/uploads/5555555555555555.webp", "/uploads/6666666666666666.webp"]

    got = php("echo json_encode(contract_image_paths(%s));" % LADDER)
    have = got if isinstance(got, list) else []
    r.check("a picture record answers with every file it names",
            sorted(have) == sorted(every), str(got)[:300])
    r.check("and names the top rung once, not twice", len(have) == 6, str(got)[:300])
    r.check("a record with no ladder answers with its two files",
            php("echo json_encode(contract_image_paths(['src' => '/uploads/a.png',"
                " 'webp' => '/uploads/a.webp']));") == ["/uploads/a.png", "/uploads/a.webp"])
    r.check("and one with nothing in it answers with none",
            php("echo json_encode(['n' => contract_image_paths([])]);").get("n") == [])

    # Only the rungs named NOWHERE but srcset. src and webp came back before
    # this change too, so asking about them would not tell us anything.
    only_in_srcset = [p for p in every
                      if p not in ("/uploads/3333333333333333.png",
                                   "/uploads/4444444444444444.webp")]

    for doc, seat in (
            ("about",    "$d['story']['items'][0]['image']"),
            ("about",    "$d['story']['items'][0]['image_dark']"),
            ("home",     "$d['destinations']['items'][0]['image']"),
            ("home",     "$d['destinations']['items'][0]['image_dark']"),
            ("company",  "$d['journey']['items'][0]['image']"),
            ("company",  "$d['clients']['items'][0]['image']"),
            ("company",  "$d['technology']['items'][0]['image']"),
            ("branding", "$d['assets']['items'][0]['image']"),
            ("branding", "$d['assets']['items'][0]['files'][0]['file']"),
            ("contact",  "$d['offices']['items'][0]['image']"),
            ("seo",      "$d['site']['share']"),
            ("seo",      "$d['identity']['logo']")):
        got = php("$d = contract_normalise(%r, %s_defaults());"
                  "%s = %s;"
                  "echo json_encode(contract_images(%r, contract_normalise(%r, $d)));"
                  % (doc, doc, seat, LADDER, doc, doc))
        have = got if isinstance(got, list) else []
        missing = [p for p in only_in_srcset if p not in have]
        r.check(f"{doc}: every rung of {seat[2:]} counts as used",
                not missing, f"missing {missing} from {str(have)[:160]}")

    if not gd:
        for case in ("EXIF is gone from what was written", "a picture is re-encoded",
                     "a payload appended to a picture does not survive",
                     "an oversized picture is reduced",
                     "the stored size is the real one",
                     "the same picture twice is one file"):
            r.skip(case)
        return

    print("\nwhat happens to a picture that is accepted")
    small = png(4, 4)
    got = php("echo json_encode(upload_store(base64_decode('%s')));"
              % base64.b64encode(small).decode())
    r.check("a picture is re-encoded", "error" not in got, str(got)[:300])
    r.check("into a WebP and a fallback",
            got.get("webp", "").endswith(".webp") and got.get("src", "").endswith(".png"),
            str(got))
    r.check("the stored size is the real one",
            got.get("width") == 4 and got.get("height") == 4, str(got))
    r.check("both are named after their own contents",
            all(len(Path(got[k]).stem) == 16 for k in ("src", "webp")), str(got))

    for key in ("src", "webp"):
        path = UPLOADS / Path(got[key]).name
        r.check(f"the {key} is on disk", path.is_file(), str(path))

    again = php("echo json_encode(upload_store(base64_decode('%s')));"
                % base64.b64encode(small).decode())
    r.check("the same picture twice is one file", again.get("src") == got.get("src"),
            f"{got.get('src')} vs {again.get('src')}")

    print("\nwhat does NOT come out the other side")
    payload = b"<?php system($_GET['c']); ?>"
    polyglot = small + payload
    got = php("echo json_encode(upload_store(base64_decode('%s')));"
              % base64.b64encode(polyglot).decode())
    r.check("a picture with a payload appended is accepted", "error" not in got,
            str(got)[:200])
    stored = (UPLOADS / Path(got["src"]).name).read_bytes()
    r.check("but the payload is not in what was written", payload not in stored,
            "re-encoding is what removes it — a check could only have looked for it")
    r.check("and neither is anything else that was after the image data",
            len(stored) < len(polyglot) + 200, f"{len(stored)} vs {len(polyglot)}")

    carrying = jpeg_with_exif()
    r.check("the EXIF sample really does carry it", EXIF_MARKER in carrying)
    got = php("echo json_encode(upload_store(base64_decode('%s')));"
              % base64.b64encode(carrying).decode())
    r.check("a photograph with EXIF is accepted", "error" not in got, str(got)[:200])
    if "error" not in got:
        stored = (UPLOADS / Path(got["src"]).name).read_bytes()
        r.check("and EXIF is gone from what was written", EXIF_MARKER not in stored,
                "a photograph's coordinates must not travel with it onto a "
                "public web server")

    print("\nwhat happens to something too big")
    wide = png(2000, 10)
    got = php("echo json_encode(upload_store(base64_decode('%s')));"
              % base64.b64encode(wide).decode())
    r.check("an oversized picture is reduced", got.get("width") == 1600, str(got))
    r.check("and keeps its shape", got.get("height") == 8, str(got))

    print("\nhow many widths get stored, and how wide the file itself is")
    # THE OTHER HALF OF THE CHANGE, and the one that was a live defect: a flag
    # arrived at 1600 and STAYED 1600 however small it is drawn, so the site
    # got worse the first time somebody used the editor as intended. src now
    # names the top rung — the width the slot is drawn at — and never the width
    # that happened to arrive.
    #
    # Every expectation here was measured off the rendered pages, not guessed:
    # see the docblock on CONTRACT_IMAGE_SLOTS.
    for slot, w, h, ceiling, src_w, rungs in [
            ("contact.offices",    1600, 1600, "",   168, [56, 112, 168]),
            ("contact.offices",      40,   40, "",    40, []),
            ("about.story",        2000, 1000, "",  1600, [700, 1400, 1600]),
            ("about.story",         900,  600, "",   900, [700, 900]),
            ("about.story",         500,  400, "",   500, []),
            ("company.technology", 1200,  400, "",   360, [120, 240, 360]),
            ("company.clients",     300,  300, "",   300, [250, 300]),
            # Portrait, and the point of it: the ladder is cut from the WIDTH,
            # which is what a sizes= attribute is about. Fitting is the longest
            # side; these are not the same number and this is where that shows.
            ("home.destinations",   600, 1400, "",   600, [400, 600]),
            # The three that do not ladder, each for a reason recorded in the
            # contract, plus a slot nobody declared and no slot at all.
            ("branding.file",      2000, 1000, ", UPLOAD_MAX_DOWNLOAD_DIMENSION",
                                                2000, []),
            ("seo.share",          1200,  630, "",  1200, []),
            ("seo.logo",           1200,  630, "",  1200, []),
            ("",                   2000,   10, "",  1600, []),
            ("not.a.slot",         1200,  800, "",  1200, [])]:
        got = php("echo json_encode(upload_store(base64_decode('%s'), '%s'%s));"
                  % (base64.b64encode(png(w, h)).decode(), slot, ceiling))
        where = f"{slot or '(no slot)'} at {w}x{h}"

        r.check(f"{where}: src is stored {src_w}px wide",
                got.get("width") == src_w, str(got)[:220])

        stored = [int(e.strip().split(" ")[1][:-1])
                  for e in got.get("srcset", "").split(",") if e.strip()]
        r.check(f"{where}: {'stores ' + '/'.join(map(str, rungs)) if rungs else 'stores one width, no ladder'}",
                stored == rungs, f"got {stored} from {str(got.get('srcset'))[:160]}")

        webp_stored = [int(e.strip().split(" ")[1][:-1])
                       for e in got.get("webp_srcset", "").split(",") if e.strip()]
        r.check(f"{where}: the WebP ladder matches the fallback's",
                webp_stored == stored, f"{webp_stored} vs {stored}")

        # A rung named and not written is a broken picture for everybody whose
        # browser prefers WebP, which is nearly everybody.
        paths = php("echo json_encode(contract_image_paths(%s));"
                    % json.dumps(got).replace("\\/", "/")
                          .replace('"', "'").replace("{", "[").replace("}", "]")
                          .replace("':", "' =>"))
        missing = [p for p in (paths if isinstance(paths, list) else [])
                   if not (UPLOADS / Path(p).name).is_file()]
        r.check(f"{where}: every file it names is on disk",
                isinstance(paths, list) and not missing, f"missing {missing}")

    print("\nwhat a rescale must not lose")
    # imagescale() hands back an image with alpha saving OFF. A ladder is three
    # of them, so a mark uploaded on transparency would come back on a black
    # rectangle on every screen but the one that takes the top rung.
    got = php("echo json_encode(upload_store(base64_decode('%s'), 'company.technology'));"
              % base64.b64encode(png_rgba(600, 200)).decode())
    r.check("a transparent logo ladders", len(got.get("srcset", "").split(",")) == 3,
            str(got)[:220])
    for entry in [e.strip() for e in got.get("srcset", "").split(",") if e.strip()]:
        name = Path(entry.split(" ")[0]).name
        alpha = php("$im = imagecreatefrompng(UPLOAD_DIR . '/%s');"
                    "echo json_encode(['a' => (imagecolorat($im, imagesx($im) - 1, 0)"
                    " >> 24) & 0x7F, 'w' => imagesx($im)]);" % name)
        r.check(f"and its {alpha.get('w')}px rung is still transparent",
                (alpha.get("a") or 0) > 120, str(alpha))

    print("\nsending a picture to the live site")
    # All of it goes or the caller must not save: a document naming a rung that
    # never arrived is a broken image. The record below names a rung that is
    # not on disk, so this must stop before anything travels rather than send
    # the two files it can find and report success.
    ghost = php(
        "define('T4T_ADMIN', true); require 'lib/admin.php';"
        "echo json_encode(['e' => admin_send_picture(["
        "  'src' => '%s', 'webp' => '%s',"
        "  'srcset' => '/uploads/deadbeefdeadbeef.png 56w, %s 112w'])]);"
        % (got.get("src", ""), got.get("webp", ""), got.get("src", "")))
    r.check("a set with a file missing from it is not sent at all",
            "not where it had just been written" in (ghost.get("e") or ""),
            str(ghost)[:220])

    print("\nwhen the directory is not there yet")
    # It is in neither repository -- it holds nothing that is committed -- so on
    # a fresh host it does not exist until something makes it. upload_accept()
    # makes it via upload_problem(); upload_store() and reconcile.py do not go
    # through that path, and on the live host this was the difference between
    # a picture being saved and "could not be saved on this server".
    import shutil as _sh
    _sh.rmtree(UPLOADS, ignore_errors=True)
    got = php("echo json_encode(upload_store(base64_decode('%s')));"
              % base64.b64encode(png(3, 3)).decode())
    r.check("upload_store makes it rather than failing", "error" not in got,
            str(got)[:200])
    r.check("and the picture is there", UPLOADS.is_dir()
            and (UPLOADS / Path(got.get("src", "x")).name).is_file(), str(got)[:200])

    print("\nhouse-keeping")
    held = php("echo json_encode(upload_held());")
    r.check("every stored name is one this scheme could have minted",
            all(len(Path(n).stem) == 16 for n in held), str(held)[:200])

    unused = php("echo json_encode(upload_unused(['/uploads/' . (upload_held()[0] ?? 'x')]));")
    r.check("a picture in use is not listed as unused",
            (held[0] if held else "x") not in unused, str(unused)[:200])

    r.check("a name this scheme did not mint is never deleted",
            php("echo json_encode(['d' => upload_delete('../../lib/company.php')]);")
            .get("d") is False)
    r.check("nor one with a path in it",
            php("echo json_encode(['d' => upload_delete('a/b.png')]);").get("d") is False)
    r.check("and the file it was aimed at is still there",
            (ROOT / "lib" / "upload.php").is_file(),
            "upload_delete() takes a name and never a path — this is the file "
            "the traversal above was pointed at")


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")

    gd = has_gd()
    if not gd:
        print("PHP has no GD extension — the re-encoding cases are skipped.")
        print("  sudo apt install php-gd\n")

    UPLOADS.mkdir(parents=True, exist_ok=True)

    # THE WHOLE DIRECTORY, CONTENTS AND ALL, not a list of names. run() deletes
    # this directory outright to prove upload_store() recreates it, which also
    # destroys the committed .gitignore sitting in it -- and a cleanup that only
    # removes files it did not recognise cannot put that back. It did not: the
    # suite deleted a tracked file every run and printed "restored" afterwards.
    #
    # It stayed invisible because the rmtree is past the GD gate, so it never
    # fired on a machine without the extension. CI has GD and did the same, but
    # CI never commits. Had the deletion ever been committed, public/uploads/*
    # would have stopped being ignored -- on a public repository, with real
    # uploaded pictures in that directory.
    before = {p.name: p.read_bytes() for p in UPLOADS.iterdir() if p.is_file()}

    r = Results()
    try:
        run(r, gd)
    finally:
        UPLOADS.mkdir(parents=True, exist_ok=True)
        for p in list(UPLOADS.iterdir()):
            if p.is_file() and p.name not in before:
                p.unlink()
        for name, blob in before.items():
            target = UPLOADS / name
            if not target.is_file() or target.read_bytes() != blob:
                target.write_bytes(blob)
        print("\npublic/uploads/ restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    tail = f" ({r.skipped} skipped, no GD)" if r.skipped else ""
    print(f"\n{r.passed}/{total} checks passed{tail}")


if __name__ == "__main__":
    main()
