<?php
/**
 * Tech4TIME — pushing content to the public site.
 *
 * BACKEND ONLY. The public site holds lib/publish.php, which is the format;
 * this is the half that sends. It is not on the frontend deliberately — the
 * public site has no business making outbound signed requests, and the split
 * exists so that each side ships only what it needs.
 *
 * WHEN IT RUNS
 * On every save, straight after the backend has written its own record. The
 * backend's copy is the system of record and is written first: if the push
 * then fails, the edit is safe here and can be sent again. Publishing first
 * would mean a live site ahead of the record it is supposed to replicate.
 *
 * WHEN IT FAILS
 * The editor says so, plainly, with a Publish again control. Never a silent
 * gap — a save that appeared to work and did not reach the site is the one
 * failure nobody investigates, because nothing asked them to.
 *
 * tools/reconcile.py sends everything that is behind, out of band. It exists
 * for the case where nobody was watching: the push failed, the tab was closed,
 * and the two have disagreed ever since.
 *
 * WHERE IT SENDS
 * PUBLIC_SITE below, or $T4T_PUBLIC_URL when it is set — which is how both
 * halves are run side by side on a development machine. $T4T_PUBLISH_URL
 * overrides the endpoint more narrowly still, for a test that wants to point
 * one document at somewhere else.
 *
 * Not reachable over HTTP: lib/ is outside this host's document root.
 */

declare(strict_types=1);

require_once __DIR__ . '/publish.php';

/**
 * The public site. One constant, because three things need it: the endpoint
 * content is pushed to, the "view the page" links in the rail, and the
 * "open the site" link beside them. Root-relative URLs used to do that job and
 * cannot any more — on this host `/` is the admin.
 *
 * Overridden by $T4T_PUBLIC_URL, which is how both halves are run side by side
 * on a development machine.
 */
const PUBLIC_SITE = 'https://tech4time.bd';

/** Where a document is posted. Overridden more narrowly by $T4T_PUBLISH_URL. */
const PUBLISH_PATH = '/api/publish.php';

/** Where a picture is posted. Derived from PUBLISH_PATH, never set apart from
    it: the two endpoints are always deployed together, and a separate override
    is a way to point them at different hosts by accident. */
const PUBLISH_ASSET_PATH = '/api/publish-asset.php';

/** Seconds to wait for the connection, and for the whole exchange. */
const PUBLISH_CONNECT_TIMEOUT = 5;
const PUBLISH_TIMEOUT = 15;

/** The public site's origin, without a trailing slash. */
function public_site(): string
{
    $url = trim((string)(getenv('T4T_PUBLIC_URL') ?: ($_SERVER['T4T_PUBLIC_URL'] ?? '')));

    return rtrim($url !== '' ? $url : PUBLIC_SITE, '/');
}

/** A URL on the public site: public_url('/pages/careers/'). */
function public_url(string $path = '/'): string
{
    return public_site() . '/' . ltrim($path, '/');
}

function publish_endpoint(): string
{
    $url = trim((string)(getenv('T4T_PUBLISH_URL') ?: ($_SERVER['T4T_PUBLISH_URL'] ?? '')));

    return $url !== '' ? $url : public_url(PUBLISH_PATH);
}

/**
 * Where a picture is posted.
 *
 * Follows publish_endpoint(), so pointing the content channel at a development
 * server points this one there too. Doing it by string replacement rather than
 * by a second environment variable is deliberate: two variables is two chances
 * to set only one, and a site whose text went to localhost while its pictures
 * went to production is a bad afternoon.
 */
function publish_asset_endpoint(): string
{
    $content = publish_endpoint();

    if (str_ends_with($content, PUBLISH_PATH)) {
        return substr($content, 0, -strlen(PUBLISH_PATH)) . PUBLISH_ASSET_PATH;
    }

    /* $T4T_PUBLISH_URL was set to something that is not the standard path —
       a stub in a test, usually. Put the asset path beside it. */
    return preg_replace('~/[^/]*$~', PUBLISH_ASSET_PATH, $content) ?? $content;
}

/**
 * Send one picture. Returns what the editor should show.
 *
 *   ['ok' => true,  'asset' => 'a1b2….webp', 'held' => false]
 *   ['ok' => false, 'code' => 'not-an-image', 'error' => '…']
 *
 * The body is the bytes themselves, signed exactly as a document is. There is
 * no envelope and no revision: a picture is content-addressed, so re-sending
 * one is a no-op rather than something that could roll anything back, and
 * there is nothing for a replay to undo.
 */
function publish_asset(string $bytes, string $mime): array
{
    $problem = publish_problem();
    if ($problem !== '') {
        return ['ok' => false, 'code' => 'not-configured', 'error' => $problem];
    }

    $timestamp = time();

    $headers = [
        'Content-Type: ' . $mime,
        PUBLISH_TIMESTAMP_HEADER . ': ' . $timestamp,
        PUBLISH_SIGNATURE_HEADER . ': ' . publish_sign($bytes, $timestamp),
    ];

    [$status, $answer, $transport] = function_exists('curl_init')
        ? publish_post_curl(publish_asset_endpoint(), $headers, $bytes)
        : publish_post_stream(publish_asset_endpoint(), $headers, $bytes);

    if ($transport !== '') {
        return [
            'ok'    => false,
            'code'  => 'unreachable',
            'error' => 'The live site could not be reached: ' . $transport,
        ];
    }

    $decoded = json_decode($answer, true);

    if (!is_array($decoded)) {
        return [
            'ok'    => false,
            'code'  => 'bad-answer',
            'error' => 'The live site answered ' . $status . ' with something that '
                     . 'was not JSON. Check that ' . publish_asset_endpoint()
                     . ' is deployed.',
        ];
    }

    if (($decoded['ok'] ?? false) === true) {
        return [
            'ok'    => true,
            'asset' => (string)($decoded['asset'] ?? ''),
            'held'  => (bool)($decoded['held'] ?? false),
        ];
    }

    $code = (string)($decoded['code'] ?? 'refused');

    return [
        'ok'     => false,
        'code'   => $code,
        'error'  => (string)($decoded['error'] ?? publish_reason($code)),
        'status' => $status,
    ];
}

/**
 * Send one document. Returns what the editor should show.
 *
 *   ['ok' => true,  'revision' => 12]
 *   ['ok' => false, 'code' => 'not-newer', 'error' => '…', 'revision' => 12]
 *
 * Never throws for a network problem: an unreachable site is a thing to report
 * in the editor, not a stack trace over a form somebody has just filled in.
 * It does throw when this host is misconfigured — no key, no private store —
 * because that is not a transient failure and should not be reported as one.
 */
function publish_push(string $document, array $data): array
{
    if (!in_array($document, CONTRACT_DOCUMENTS, true)) {
        throw new RuntimeException('Unknown document: ' . $document);
    }

    $problem = publish_problem();
    if ($problem !== '') {
        return ['ok' => false, 'code' => 'not-configured', 'error' => $problem];
    }

    $body      = publish_body(publish_envelope($document, $data));
    $timestamp = time();

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        PUBLISH_TIMESTAMP_HEADER . ': ' . $timestamp,
        PUBLISH_SIGNATURE_HEADER . ': ' . publish_sign($body, $timestamp),
    ];

    [$status, $answer, $transport] = function_exists('curl_init')
        ? publish_post_curl(publish_endpoint(), $headers, $body)
        : publish_post_stream(publish_endpoint(), $headers, $body);

    if ($transport !== '') {
        return [
            'ok'    => false,
            'code'  => 'unreachable',
            'error' => 'The live site could not be reached: ' . $transport,
        ];
    }

    $decoded = json_decode($answer, true);

    if (!is_array($decoded)) {
        /* A 200 that is not JSON is almost always a host error page or an
           interstitial, and saying which status it carried is what tells the
           difference between "the endpoint is missing" and "the endpoint
           broke". */
        return [
            'ok'    => false,
            'code'  => 'bad-answer',
            'error' => 'The live site answered ' . $status . ' with something that '
                     . 'was not JSON. Check that ' . publish_endpoint()
                     . ' is deployed.',
        ];
    }

    if (($decoded['ok'] ?? false) === true) {
        return [
            'ok'       => true,
            'revision' => (int)($decoded['revision'] ?? 0),
        ];
    }

    $code = (string)($decoded['code'] ?? 'refused');

    return [
        'ok'       => false,
        'code'     => $code,
        'error'    => (string)($decoded['error'] ?? publish_reason($code)),
        'revision' => isset($decoded['revision']) ? (int)$decoded['revision'] : null,
        'status'   => $status,
    ];
}

/**
 * POST with curl. Returns [status, body, transport error].
 *
 * The certificate is verified, and there is no option here to turn that off.
 * The whole point of the signature is that the payload cannot be forged; the
 * whole point of TLS is that the content of an edit is nobody else's business
 * on the way. Turning off verification is how "it would not connect" gets
 * fixed at the cost of both.
 */
function publish_post_curl(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => PUBLISH_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => PUBLISH_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        /* Not followed. A redirect on this route means something is in front
           of the endpoint that should not be, and following it would post a
           signed document wherever it pointed. */
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $answer = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return $answer === false || $error !== ''
        ? [0, '', $error !== '' ? $error : 'the request failed']
        : [$status, (string)$answer, ''];
}

/** The same, without curl. Some PHP builds do not have it. */
function publish_post_stream(string $url, array $headers, string $body): array
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => PUBLISH_TIMEOUT,
            'ignore_errors' => true,   /* read the body of a 4xx, do not throw */
            'follow_location' => 0,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $answer = @file_get_contents($url, false, $context);

    if ($answer === false) {
        $last = error_get_last();
        $why  = trim((string)($last['message'] ?? 'the request failed'));

        /* PHP prefixes the message with the function that produced it and the
           whole URL, which puts "file_get_contents(https://…): Failed to open
           stream:" in front of the only part that means anything. The person
           reading this is looking at an editor, not a stack trace. */
        $why = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $why) ?? $why;
        $why = preg_replace('/^Failed to open stream:\s*/', '', $why) ?? $why;

        return [0, '', $why !== '' ? $why : 'the request failed'];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }

    return [$status, (string)$answer, ''];
}

/* ------------------------------------------------------- carrying the news

   careers_save() and contact_save() publish; admin_redirect() reports. The two
   are several frames apart on the stack, with a redirect between them, so the
   outcome is left here rather than threaded through every call site — which is
   the same reasoning that put the publish inside the save.
   -------------------------------------------------------------------------- */

/**
 * Set or read the outcome of the last publish in this request.
 *
 * Call with a result to record it; call with nothing to read it. Returns null
 * when nothing has published, which is not the same as a publish that
 * succeeded — a section that never saved must not report "sent to the live
 * site".
 */
function publish_note(?array $result = null): ?array
{
    static $last = null;

    if ($result !== null) {
        $last = $result;
    }

    return $last;
}
