<?php
/**
 * Tech4TIME — turning a file somebody chose into a picture this site will show.
 *
 * BACKEND ONLY. The frontend has no upload form and must never gain one.
 *
 * THE ONE RULE HERE: NOTHING THE BROWSER SENT IS EVER WRITTEN.
 *
 * An upload is not a file to be checked and then saved. It is untrusted input
 * to be *read* and then *replaced*. Every accepted picture is decoded by gd and
 * re-encoded from the pixel data, so what lands on disk is bytes that library
 * produced. That single step is what removes:
 *
 *   - EXIF, including GPS coordinates somebody did not know were in a photo
 *   - anything appended after the image data, which is how a polyglot is built
 *   - a file that is a valid JPEG *and* a valid PHP script or ZIP archive
 *   - colour profiles and comment blocks nobody asked for
 *
 * A validator that inspects and approves cannot do any of that: it can only
 * decide it did not find anything it knew to look for.
 *
 * WHAT IS ACCEPTED
 * JPEG, PNG and WebP, decided from the file's own header and never from its
 * name, its extension or the Content-Type the browser attached. No SVG: an SVG
 * is a document, it can carry script and external references, and re-encoding
 * does not make it not a document. No GIF or BMP, because nothing needs them
 * and a format nobody uses is an attack surface nobody watches.
 *
 * WHERE IT GOES
 * public/uploads/, under a name computed from the re-encoded bytes. This host
 * is the system of record (ADR 0010); the public site holds a replica, sent by
 * publish_asset() in lib/publish_client.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/publish.php';
require_once __DIR__ . '/svg.php';
require_once __DIR__ . '/store.php';

/** Where the canonical copy of every uploaded picture lives. */
const UPLOAD_DIR = __DIR__ . '/../public/uploads';

/** The path the public site will serve it from. */
const UPLOAD_URL_ROOT = '/uploads/';

/** Bigger than this and it is refused before anything decodes it. */
const UPLOAD_MAX_BYTES = 5242880;

/**
 * The longest side a stored picture may have.
 *
 * A phone photograph is four thousand pixels across and is shown here at three
 * hundred. Storing the original costs the visitor the whole download for no
 * visible gain, and costs this account the disk. 1600 is comfortably above any
 * size the site displays, including on a 2x screen.
 */
const UPLOAD_MAX_DIMENSION = 1600;

/**
 * The longest side a picture may have when it is the thing being DOWNLOADED.
 *
 * The bound above is about what a page displays. The branding page is the one
 * place where the file is not decoration — it is the deliverable, and somebody
 * putting the mark on a banner needs more than a screen's worth of pixels.
 * The files that page ships today are exactly 1600 wide, so nothing regresses
 * either way; this is headroom above them rather than a change to them.
 *
 * Everything else about the path is unchanged: still decoded, still
 * re-encoded, still refused if the result is over PUBLISH_ASSET_MAX_BYTES.
 * A larger ceiling is not a looser one.
 */
const UPLOAD_MAX_DOWNLOAD_DIMENSION = 3000;

/** Quality for the two lossy encoders. High enough that a logo stays crisp. */
const UPLOAD_WEBP_QUALITY = 82;
const UPLOAD_JPEG_QUALITY = 86;

/**
 * Why uploads cannot work right now, or '' if they can.
 *
 * Said plainly and early, the way auth_problem() is, because "the picture did
 * not appear" and "this server has no image library" are different problems
 * with different fixes, and only one of them is the operator's.
 */
function upload_problem(): string
{
    if (!extension_loaded('gd')) {
        return 'This server has no GD image library, so pictures cannot be '
             . 'accepted. Everything else on this page still works.';
    }

    foreach (['imagecreatefromstring', 'imagewebp', 'imagejpeg', 'imagepng'] as $fn) {
        if (!function_exists($fn)) {
            return 'This server\'s GD build is missing ' . $fn . '(), so pictures '
                 . 'cannot be accepted.';
        }
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        return 'The uploads directory does not exist and could not be created.';
    }

    if (!is_writable(UPLOAD_DIR)) {
        return 'The uploads directory is not writable by PHP.';
    }

    return '';
}

/**
 * Accept one uploaded picture: read it, replace it, store it.
 *
 * Returns the image record the content model wants — src, webp, width, height
 * — or a one-sentence error under 'error'. Never throws, and never leaves a
 * partial file behind.
 *
 * TWO FILES ARE WRITTEN, NOT ONE. A WebP, which is what nearly every visitor
 * will be served, and a fallback in the original raster family for the ones
 * that will not. Both come from the same decoded pixels, so they cannot
 * disagree about what the picture is.
 *
 * @param array $file one entry of $_FILES
 */
function upload_accept(array $file, int $maxSide = UPLOAD_MAX_DIMENSION): array
{
    /* NOT upload_problem() here. That asks whether GD is present, and until
       the bytes have been read nothing knows whether GD is the library this
       file needs -- a vector one does not touch it. The raster branch of
       upload_store() asks, where the answer is relevant, so a host missing one
       library does not refuse the format that works. */
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        return ['error' => upload_error_reason($code)];
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    /* The one thing that makes this an upload rather than any file on the
       server. Without it, a tmp_name of /etc/passwd would be read and
       published. */
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'That did not arrive as an upload.'];
    }

    if ((int)($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        return ['error' => 'That picture is larger than '
                         . (int)(UPLOAD_MAX_BYTES / 1048576) . ' MB.'];
    }

    $bytes = (string)@file_get_contents($tmp, false, null, 0, UPLOAD_MAX_BYTES + 1);
    if ($bytes === '' || strlen($bytes) > UPLOAD_MAX_BYTES) {
        return ['error' => 'That picture is larger than '
                         . (int)(UPLOAD_MAX_BYTES / 1048576) . ' MB.'];
    }

    return upload_store($bytes, $maxSide);
}

/**
 * Re-encode bytes and store the pair. Separated from upload_accept() so that
 * the part worth testing does not need a real HTTP upload to reach.
 */
function upload_store(string $bytes, int $maxSide = UPLOAD_MAX_DIMENSION): array
{
    /* A vector file is not decoded, because there is nothing to decode. It
       goes down its own path, which does the same job a different way: parse,
       allow-list, re-serialise, store THAT. See lib/svg.php, and the
       amendment to ADR 0019 for why this stopped being a refusal. */
    if (svg_looks_like($bytes)) {
        return upload_store_svg($bytes);
    }

    $problem = upload_problem();
    if ($problem !== '') {
        return ['error' => $problem];
    }

    /* Decided from the header, never from a name or a Content-Type. */
    $kind = publish_asset_type($bytes);
    if ($kind === null) {
        return ['error' => 'That file is not a JPEG, PNG, WebP or SVG picture.'];
    }

    [$ext] = $kind;

    /* The decode. Everything that was not pixel data stops existing here. */
    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        return ['error' => 'That picture could not be read. It may be damaged.'];
    }

    try {
        $image = upload_fit($image, $maxSide);

        /* Transparency survives the copy in upload_fit(); these tell the two
           encoders that can carry it to do so. */
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $webp = upload_encode($image, 'webp');
        $raster = upload_encode($image, $ext === 'webp' ? 'png' : $ext);

        $width  = imagesx($image);
        $height = imagesy($image);
    } finally {
        imagedestroy($image);
    }

    if ($webp === null || $raster === null) {
        return ['error' => 'That picture could not be re-encoded.'];
    }

    if (strlen($webp) > PUBLISH_ASSET_MAX_BYTES
            || strlen($raster) > PUBLISH_ASSET_MAX_BYTES) {
        return ['error' => 'That picture is still too large after being '
                         . 'reduced. Try a smaller one.'];
    }

    $names = [
        'webp' => publish_asset_name($webp, 'webp'),
        'src'  => publish_asset_name($raster, $ext === 'webp' ? 'png' : $ext),
    ];

    foreach (['src' => $raster, 'webp' => $webp] as $which => $blob) {
        if (!upload_write($names[$which], $blob)) {
            return ['error' => 'The picture could not be saved on this server.'];
        }
    }

    return [
        'src'    => UPLOAD_URL_ROOT . $names['src'],
        'webp'   => UPLOAD_URL_ROOT . $names['webp'],
        'width'  => $width,
        'height' => $height,
    ];
}

/**
 * Store a vector file, having first made it one.
 *
 * The raster path's rule, applied to a format that has no pixels to re-encode:
 * the file is parsed, walked against an allow-list and re-serialised, and what
 * is written is THAT — never the bytes that arrived. svg_sanitise() carries
 * the whole argument, including why anything unrecognised is refused outright
 * rather than quietly dropped.
 *
 * ONE FILE, NOT TWO. The raster path writes a WebP beside its fallback because
 * nearly every visitor is better served by the WebP. A vector file has no
 * better version of itself, so 'webp' is left empty — which the renderers
 * already read as "emit a bare <img>, no <picture>", the same as it has always
 * meant. Nothing on the public page draws it in any case; it is a download.
 *
 * NO DIMENSION CEILING, and none is needed. A vector has no resolution to
 * reduce, and SVG_MAX_BYTES bounds the file itself.
 */
function upload_store_svg(string $bytes): array
{
    $clean = svg_sanitise($bytes);
    if (isset($clean['error'])) {
        return ['error' => $clean['error']];
    }

    $svg = (string)$clean['svg'];

    /* The public site refuses anything over this after re-encoding, so
       refusing it here says so to the person who can do something about it,
       rather than letting the publish fail later with nobody watching. */
    if (strlen($svg) > PUBLISH_ASSET_MAX_BYTES) {
        return ['error' => 'That vector file is too large to publish.'];
    }

    $name = publish_asset_name($svg, 'svg');
    if (!upload_write($name, $svg)) {
        return ['error' => 'The picture could not be saved on this server.'];
    }

    return [
        'src'    => UPLOAD_URL_ROOT . $name,
        'webp'   => '',
        'width'  => (int)$clean['width'],
        'height' => (int)$clean['height'],
    ];
}

/**
 * Scale to fit $maxSide, or return the image untouched.
 *
 * The caller says how big is big enough, because that depends on what the
 * picture is FOR: UPLOAD_MAX_DIMENSION for something a page displays,
 * UPLOAD_MAX_DOWNLOAD_DIMENSION for something a visitor takes away.
 *
 * Aspect ratio is preserved, so the stored width and height are always the
 * real ones — which is what lets the page reserve the right box and keeps
 * Cumulative Layout Shift at zero.
 */
function upload_fit(GdImage $image, int $maxSide = UPLOAD_MAX_DIMENSION): GdImage
{
    $w = imagesx($image);
    $h = imagesy($image);
    $longest = max($w, $h);

    if ($longest <= $maxSide) {
        return $image;
    }

    $scale = $maxSide / $longest;
    $scaled = imagescale($image, (int)round($w * $scale), (int)round($h * $scale));

    if ($scaled === false) {
        return $image;
    }

    imagedestroy($image);
    return $scaled;
}

/** One encoding, as bytes. */
function upload_encode(GdImage $image, string $ext): ?string
{
    ob_start();

    $ok = match ($ext) {
        'webp' => imagewebp($image, null, UPLOAD_WEBP_QUALITY),
        'jpg'  => imagejpeg($image, null, UPLOAD_JPEG_QUALITY),
        'png'  => imagepng($image, null, 8),
        default => false,
    };

    $bytes = (string)ob_get_clean();

    return ($ok && $bytes !== '') ? $bytes : null;
}

/** Write one file, atomically, unless it is already there byte for byte. */
function upload_write(string $name, string $bytes): bool
{
    /* Here rather than only in upload_problem(), which upload_accept() calls
       and upload_store() does not. The directory is not in either repository —
       it holds nothing that is committed — so on a fresh host it does not exist
       until something makes it. A function that works only when its caller
       happened to do that first is a function that breaks the day somebody
       calls it the other way. tools/reconcile.py is that other way, and so is
       every test that reaches upload_store() directly. */
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        return false;
    }

    $path = UPLOAD_DIR . '/' . $name;

    if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $bytes)) {
        return true;
    }

    $tmp = UPLOAD_DIR . '/.' . bin2hex(random_bytes(8)) . '.tmp';

    if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, 0644);
    return true;
}

/** What PHP's own upload failure codes mean, for a person. */
function upload_error_reason(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'That picture is larger than this server accepts.',
        UPLOAD_ERR_PARTIAL   => 'The upload was cut off. Try again.',
        UPLOAD_ERR_NO_FILE   => 'No picture was chosen.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            'This server could not hold the upload while it was being read.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
        default              => 'The upload did not arrive.',
    };
}

/* --------------------------------------------------------------- house-keeping */

/** Every stored picture, by name. */
function upload_held(): array
{
    $found = [];
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $path) {
        $name = basename($path);
        if (is_file($path) && publish_asset_name_valid($name)) {
            $found[] = $name;
        }
    }
    sort($found);
    return $found;
}

/**
 * Every picture ANY document points at, with one document overridden.
 *
 * THE DIRECTORY IS SHARED AND THE DOCUMENTS ARE NOT. public/uploads/ holds the
 * artwork of every editor together, so "is this file still used" can only be
 * answered by asking all of them. It used to be asked of one: about.php passed
 * about_images(), so the home page's uploads came back as unused and its sweep
 * button offered to delete them. Three editors, each able to delete the other
 * two's pictures, and nothing said so.
 *
 * $document and $data are the screen that is asking, passed in rather than
 * read off disk, because the edit in front of somebody has not been saved yet
 * — and a picture attached a moment ago must not count as unused for the
 * length of one page render.
 */
function upload_in_use(string $document, array $data): array
{
    $used = contract_images($document, $data);

    foreach (CONTRACT_DOCUMENTS as $name) {
        if ($name === $document) {
            continue;
        }
        $held = store_read(contract_path($name));
        if (is_array($held)) {
            $used = array_merge($used, contract_images($name, contract_normalise($name, $held)));
        }
    }

    return array_values(array_unique($used));
}

/**
 * The stored pictures nothing in $used points at.
 *
 * $used is every web path the SITE references — upload_in_use(). Asking with
 * one document's worth is how the bug above happened; the parameter is still a
 * list rather than a document so that a caller has to have thought about it.
 *
 * Never swept automatically: a reference count taken from a document somebody
 * is halfway through editing is not a fact, and deleting on it would remove a
 * picture whose row is about to be saved.
 */
function upload_unused(array $used): array
{
    $referenced = [];
    foreach ($used as $path) {
        $referenced[basename((string)$path)] = true;
    }

    return array_values(array_filter(
        upload_held(),
        static fn(string $name): bool => !isset($referenced[$name])
    ));
}

/** Delete one stored picture, by name. Refuses any name it did not mint. */
function upload_delete(string $name): bool
{
    if (!publish_asset_name_valid($name)) {
        return false;
    }

    $path = UPLOAD_DIR . '/' . $name;

    return is_file($path) && @unlink($path);
}
