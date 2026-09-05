<?php
/**
 * Tech4TIME — the wire between the two halves.
 *
 * SHARED FILE. Byte-identical in tech4time-website-frontend and tech4time-website-backend.
 * tools/check_shared_lib.py compares the two against a committed digest.
 *
 * WHAT THIS IS
 * The backend owns the content. On save it writes its own record and pushes a
 * signed copy to the public site, which verifies it, re-sanitises it and writes
 * its replica. The public site never calls the backend during a request —
 * docs/90-decisions/0010-backend-pushes-content.md for why.
 *
 * This file is only the format: how a document is wrapped, signed and checked.
 * Sending it is the backend's lib/publish_client.php; receiving it is the
 * frontend's api/publish.php. Both are one-sided, and neither belongs here.
 *
 * THE ENVELOPE
 *   {
 *     "contract_version": 1,        the shape; see lib/contract.php
 *     "document":         "careers",
 *     "revision":         12,       monotonic per document
 *     "published":        "2026-08-26T09:14:03+00:00",
 *     "data":             { … }     the document itself
 *   }
 *
 * over HTTP as
 *   POST /api/publish
 *   Content-Type:     application/json
 *   X-T4T-Timestamp:  1756199643
 *   X-T4T-Signature:  <key fingerprint>:<hex hmac-sha256>
 *
 * and the signed string is "<timestamp>.<body>" — the timestamp inside the
 * signature and not merely beside it, or moving it would cost an attacker
 * nothing.
 *
 * WHAT EACH CHECK IS FOR, BECAUSE THEY ARE NOT INTERCHANGEABLE
 *
 *   the signature   proves the payload came from something holding the key.
 *                   It says nothing about whether the payload is safe, which
 *                   is why the receiver re-sanitises every rich field through
 *                   its own lib/html.php afterwards.
 *
 *   the timestamp   bounds how long a captured request stays useful. Five
 *                   minutes, either way, because the two clocks are different
 *                   machines and a window shorter than their drift is an
 *                   outage rather than a defence.
 *
 *   the revision    is what actually stops a replay from doing damage. Inside
 *                   the window a captured request is still perfectly signed —
 *                   but it carries a revision the receiver already holds, and
 *                   a document must be STRICTLY newer to be written. So the
 *                   replay is a no-op rather than a rollback of the live site
 *                   to whatever was published five minutes ago.
 *
 *   the version     refuses a shape this side does not implement, rather than
 *                   writing a document it would then mis-render. This is the
 *                   real guarantee that the two repositories are in step; the
 *                   committed digest is only hygiene.
 *
 * THE KEY
 * publish.key in the private store, 32 random bytes as 64 hex characters, and
 * THE SAME BYTES on both hosts. It is never derived from secret.key: the two
 * sides have separate stores and separate master keys, so anything derived
 * would differ by construction and every publish would fail.
 *
 * It is never created on demand either, for the same reason a derived one
 * would not do. A key that appears by itself appears differently on each host,
 * and the failure would read as "signature rejected" for as long as it took
 * somebody to think of it. Both sides refuse to start without one and say what
 * to do — tools/make_publish_key.py.
 *
 * Every signature carries the key's fingerprint, per
 * docs/90-decisions/0014-derived-secrets-name-their-key.md, so a receiver
 * holding a different key answers "that is not the key I have" instead of
 * "wrong signature". The two send you to completely different places.
 */

declare(strict_types=1);

require_once __DIR__ . '/private.php';
require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/svg.php';

/** How far apart the two clocks may be, in seconds, in either direction. */
const PUBLISH_SKEW = 300;

/** The largest payload the endpoint will read. The contact page is ~8 KB. */
const PUBLISH_MAX_BYTES = 1048576;

const PUBLISH_SIGNATURE_HEADER = 'X-T4T-Signature';
const PUBLISH_TIMESTAMP_HEADER = 'X-T4T-Timestamp';

/**
 * Why publishing cannot work, or '' when it can.
 *
 * Separate from publish_secret() because the admin wants to explain this to a
 * person and the endpoint wants to answer without dying.
 */
function publish_problem(): string
{
    $store = t4t_private_problem();
    if ($store !== '') {
        return $store;
    }

    $path = t4t_private_path('publish');

    if (!is_file($path)) {
        return 'There is no publish key at ' . $path . '. Make one with '
             . 'tools/make_publish_key.py and put the SAME value in the other '
             . 'half\'s private store.';
    }

    $raw = trim((string)@file_get_contents($path));

    if (strlen($raw) < 64 || !ctype_xdigit($raw)) {
        return 'The publish key at ' . $path . ' is not 64 or more hex '
             . 'characters. It should be exactly what tools/make_publish_key.py '
             . 'printed, on both hosts.';
    }

    return '';
}

/** The shared secret, as raw bytes. Throws rather than inventing one. */
function publish_secret(): string
{
    static $secret = null;

    if ($secret !== null) {
        return $secret;
    }

    $problem = publish_problem();
    if ($problem !== '') {
        throw new RuntimeException($problem);
    }

    return $secret = (string)hex2bin(trim((string)file_get_contents(
        t4t_private_path('publish')
    )));
}

/**
 * A short name for the publish key, which identifies it without revealing it.
 *
 * Sixteen hex characters of an HMAC of a fixed label under the key. Reversing
 * it is the same problem as reversing the key.
 */
function publish_fingerprint(): string
{
    return substr(
        bin2hex(hash_hmac('sha256', 'publish-key-fingerprint', publish_secret(), true)),
        0,
        16
    );
}

/** Wrap a document for sending. */
function publish_envelope(string $document, array $data): array
{
    return [
        'contract_version' => CONTRACT_VERSION,
        'document'         => $document,
        'revision'         => max(0, (int)($data['revision'] ?? 0)),
        'published'        => gmdate('c'),
        'data'             => $data,
    ];
}

/**
 * The body exactly as it goes on the wire.
 *
 * The signature is over these bytes, so the sender must transmit what this
 * returned and the receiver must verify what it actually read — never a
 * re-encoding of the decoded value. json_encode() is not required to be
 * stable across versions, and a signature over a second spelling of the same
 * document is a signature over different bytes.
 */
function publish_body(array $envelope): string
{
    $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('The document could not be encoded: '
            . json_last_error_msg());
    }

    return $json;
}

/** The X-T4T-Signature value for a body and the timestamp sent with it. */
function publish_sign(string $body, int $timestamp): string
{
    return publish_fingerprint() . ':'
         . hash_hmac('sha256', $timestamp . '.' . $body, publish_secret());
}

/**
 * Check a received body against the headers that came with it.
 *
 * Returns '' when the payload is authentic, or a short code naming what was
 * wrong. Codes rather than sentences because the endpoint answers a machine
 * and the machine's operator needs the difference between "the other side has
 * a different key" and "the clocks have drifted" to survive the round trip.
 *
 * Ordered cheapest-first, and the order is also the order that leaks least: a
 * malformed header is answered before any comparison against the secret is
 * made at all.
 */
function publish_verify(string $body, string $signature, string $timestamp): string
{
    if (trim($signature) === '' || trim($timestamp) === '') {
        return 'no-signature';
    }

    if (!preg_match('/^([0-9a-f]{16}):([0-9a-f]{64})$/', trim($signature), $m)) {
        return 'bad-signature-format';
    }

    if (!preg_match('/^\d{1,12}$/', trim($timestamp))) {
        return 'bad-timestamp-format';
    }

    /* Named before compared, so a receiver holding the wrong key says so.
       hash_equals on the fingerprint too: it is derived from the secret, and
       comparing it with == would leak it a byte at a time to anyone willing to
       measure. */
    if (!hash_equals(publish_fingerprint(), $m[1])) {
        return 'unknown-key';
    }

    if (abs(time() - (int)$timestamp) > PUBLISH_SKEW) {
        return 'stale-timestamp';
    }

    $expected = hash_hmac('sha256', (int)$timestamp . '.' . $body, publish_secret());

    if (!hash_equals($expected, $m[2])) {
        return 'bad-signature';
    }

    return '';
}

/**
 * Check a verified envelope's shape. Returns '' or a code.
 *
 * Runs after publish_verify() and never instead of it: this reads values out
 * of the payload, and reading anything out of an unauthenticated payload is
 * how a checked request becomes an unchecked one.
 */
function publish_check_envelope(mixed $envelope): string
{
    if (!is_array($envelope)) {
        return 'bad-json';
    }

    if ((int)($envelope['contract_version'] ?? 0) !== CONTRACT_VERSION) {
        return 'contract-mismatch';
    }

    if (!in_array((string)($envelope['document'] ?? ''), CONTRACT_DOCUMENTS, true)) {
        return 'unknown-document';
    }

    if (!is_array($envelope['data'] ?? null)) {
        return 'bad-document';
    }

    if ((int)($envelope['revision'] ?? 0) < 1) {
        return 'bad-revision';
    }

    /* The envelope's revision and the document's own must agree, or the
       receiver's monotonic check is guarding a number the document does not
       carry. */
    if ((int)$envelope['revision'] !== (int)($envelope['data']['revision'] ?? 0)) {
        return 'revision-mismatch';
    }

    return '';
}

/* ==========================================================================
   Assets — the second thing that travels this road
   ========================================================================== */

/**
 * WHY PICTURES DO NOT GO IN THE DOCUMENT.
 *
 * The content channel is one signed JSON POST capped at PUBLISH_MAX_BYTES.
 * Sixty logos base64'd into it would blow that cap several times over, and
 * would re-send every picture on every save of a single word. So an asset
 * travels on its own: same key, same fingerprint, same timestamp window, same
 * refusal vocabulary — a different endpoint and a body that is bytes.
 *
 * See docs/90-decisions/0019-uploaded-images-travel-their-own-channel.md.
 *
 * THE NAME IS COMPUTED, NEVER SENT. Both sides derive it from the bytes, so
 * the sender's idea of what a file is called never reaches a filesystem call.
 * A name that cannot be influenced cannot carry a traversal, an extension the
 * server will execute, or a collision with somebody else's picture.
 */

/** After re-encoding, a picture this site will publish is smaller than this. */
const PUBLISH_ASSET_MAX_BYTES = 2097152;

/**
 * The three RASTER formats that may cross the wire, and the extension each gets.
 *
 * GIF and BMP are absent because nothing needs them, and a format nobody uses
 * is an attack surface nobody watches.
 *
 * A FOURTH FORMAT TRAVELS SEPARATELY. SVG used to be refused outright here,
 * and the reason was right: an SVG is a document, it can carry script,
 * external references and entities, and no amount of re-encoding makes it not
 * a document. The branding page needs vector logos, so that is now answered
 * rather than avoided -- see publish_asset_svg() below, lib/svg.php, and the
 * amendment to ADR 0019. It is not in this list because getimagesizefromstring()
 * cannot speak for it: a vector file has no header to read, so it is decided a
 * different way and kept a different way.
 */
const PUBLISH_ASSET_TYPES = [
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
    IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
    IMAGETYPE_PNG  => ['png',  'image/png'],
];

/**
 * The largest picture this site will publish, per side and in total.
 *
 * The per-side bound is not tidiness. getimagesizefromstring() reads the
 * header and stops -- it does not decode -- so eight bytes of PNG signature
 * followed by anything at all is reported as a PNG, with whatever the next
 * four bytes happen to spell as its width. A file like that was accepted here
 * and stored with a width of 1937007981, which would have gone into the
 * document and out as <img width="1937007981">.
 *
 * The total bound is the other half of the same idea: a picture declaring
 * 60000x60000 is eleven bytes on the wire and three and a half gigabytes to
 * anything that tries to decode it.
 *
 * Both are far above anything real. The backend reduces to 1600 on its longest
 * side before sending, so nothing legitimate comes close.
 */
const PUBLISH_ASSET_MAX_SIDE = 10000;
const PUBLISH_ASSET_MAX_PIXELS = 40000000;

/**
 * What these bytes actually are, or null if they are not a picture we publish.
 *
 * Decided from the CONTENT — getimagesizefromstring() reads the header — and
 * never from a filename, an extension or a Content-Type. Those are all things
 * the sender chose.
 *
 * @return array{0:string,1:string,2:int,3:int}|null  ext, mime, width, height
 */
function publish_asset_type(string $bytes): ?array
{
    $size = @getimagesizefromstring($bytes);

    if ($size === false || !isset(PUBLISH_ASSET_TYPES[$size[2]])) {
        return null;
    }
    $width  = (int)$size[0];
    $height = (int)$size[1];

    if ($width <= 0 || $height <= 0
            || $width > PUBLISH_ASSET_MAX_SIDE || $height > PUBLISH_ASSET_MAX_SIDE
            || $width * $height > PUBLISH_ASSET_MAX_PIXELS) {
        return null;
    }

    [$ext, $mime] = PUBLISH_ASSET_TYPES[$size[2]];

    return [$ext, $mime, $width, $height];
}

/** The one vector format, and what it is called on the wire. */
const PUBLISH_ASSET_SVG_TYPE = ['svg', 'image/svg+xml'];

/**
 * A vector file's type, or null if these bytes are not one this site publishes.
 *
 * THE RECEIVING HOST DOES NOT TRUST THE SENDING ONE. The backend sanitises an
 * upload and sends its own output; this runs on what actually arrived and
 * proves it independently. A signature says where bytes came from, not what is
 * in them, and if the admin host is ever compromised the public site should
 * still not be storing somebody's document.
 *
 * IT PROVES IT WITHOUT CHANGING ANYTHING, and that is the whole trick. The
 * bytes are sanitised again and the result compared with what came in. Equal
 * means they were already the sanitiser's own output; different means they
 * were not, and they are refused rather than quietly cleaned -- because the
 * name is computed from the bytes on BOTH hosts, so a host that stored
 * something it had edited would file it under a name the document does not
 * point at. svg_sanitise() is idempotent so that this comparison is possible;
 * tools/test_svg.py asserts it directly.
 *
 * @return array{0:string,1:string,2:int,3:int}|null  ext, mime, width, height
 */
function publish_asset_svg(string $bytes): ?array
{
    if (!svg_looks_like($bytes)) {
        return null;
    }

    $clean = svg_sanitise($bytes);
    if (isset($clean['error']) || !isset($clean['svg'])) {
        return null;
    }
    if (!hash_equals($clean['svg'], $bytes)) {
        return null;
    }

    [$ext, $mime] = PUBLISH_ASSET_SVG_TYPE;

    return [$ext, $mime, (int)$clean['width'], (int)$clean['height']];
}

/**
 * What these bytes are, raster or vector, or null if they are neither.
 *
 * The one question both hosts ask. publish_asset_type() answers for the three
 * raster formats from the image header; publish_asset_svg() answers for the
 * one vector format by re-sanitising. Kept together so that a caller cannot
 * accidentally ask only half the question, which is how a second format gets
 * added and then silently refused everywhere that forgot about it.
 *
 * @return array{0:string,1:string,2:int,3:int}|null  ext, mime, width, height
 */
function publish_asset_any(string $bytes): ?array
{
    return publish_asset_type($bytes) ?? publish_asset_svg($bytes);
}

/**
 * The name these bytes get, on either host.
 *
 * Content-addressed: the same picture uploaded twice is one file, and two
 * people uploading at once cannot land on the same name with different
 * contents. Sixteen hex characters of SHA-256 — 64 bits, which for a few
 * hundred pictures is not a collision anybody will see.
 *
 * The shape is also the .htaccess allow-list on both hosts: sixteen hex
 * characters, a dot, and one of four extensions. Nothing else under
 * /uploads/ is served at all — and an .svg among them is served as an
 * attachment, sandboxed, so it downloads and never renders. See ADR 0019.
 */
function publish_asset_name(string $bytes, string $ext): string
{
    return substr(hash('sha256', $bytes), 0, 16) . '.' . $ext;
}

/** Whether a name is one this scheme could have produced. */
function publish_asset_name_valid(string $name): bool
{
    return preg_match('/^[0-9a-f]{16}\.(webp|jpg|png|svg)$/', $name) === 1;
}

/**
 * What each code means, for a person.
 *
 * The endpoint answers the code; the admin shows this. Kept together so that
 * adding a refusal without a sentence to go with it is visibly incomplete.
 */
const PUBLISH_REASONS = [
    'no-signature'          => 'The request arrived without a signature.',
    'bad-signature-format'  => 'The signature was not in the expected form.',
    'bad-timestamp-format'  => 'The timestamp was not in the expected form.',
    'unknown-key'           => 'The live site holds a different publish key than this one. '
                             . 'Both private stores must carry the same publish.key.',
    'stale-timestamp'       => 'The two servers disagree about the time by more than five '
                             . 'minutes. Check the clock on both.',
    'bad-signature'         => 'The signature did not match the payload.',
    'too-large'             => 'The document was larger than the endpoint will accept.',
    'bad-json'              => 'The payload was not readable as JSON.',
    'contract-mismatch'     => 'The live site implements a different content shape. Deploy '
                             . 'both halves — they are out of step.',
    'unknown-document'      => 'The live site does not publish a document by that name.',
    'bad-document'          => 'The payload carried no document.',
    'bad-revision'          => 'The payload carried no usable revision number.',
    'revision-mismatch'     => 'The envelope and the document disagree about the revision.',
    'not-newer'             => 'The live site already holds this revision or a later one. '
                             . 'Nothing was changed there.',
    'no-destination'        => 'The live site implements that document but has nowhere to '
                             . 'put it. Its api/publish.php is behind the shared contract — '
                             . 'deploy the frontend.',
    'write-failed'          => 'The live site could not write the file. Check that its '
                             . 'content directory is writable.',
    'not-configured'        => 'Publishing is not set up on the live site.',
    'not-an-image'          => 'That file is not a picture the live site will accept. '
                             . 'JPEG, PNG and WebP, or an SVG that is already '
                             . 'the sanitiser\'s own output.',
    'asset-too-large'       => 'The picture was larger than the endpoint will accept, '
                             . 'even after being re-encoded.',
    'asset-write-failed'    => 'The live site could not save the picture. Check that its '
                             . 'uploads directory is writable.',
];

function publish_reason(string $code): string
{
    return PUBLISH_REASONS[$code] ?? 'The live site refused the update (' . $code . ').';
}
