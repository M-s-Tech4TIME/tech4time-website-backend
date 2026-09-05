<?php
/**
 * Tech4TIME backend — the local server's router.
 *
 * Development tool. NOT deployed to the web server (see tools/README.md).
 * Start it with tools/serve.py rather than by hand.
 *
 * WHAT IT REPRODUCES
 * The document root. On the host, admin.tech4time.bd points at public/ — so
 * lib/, sections/ and content/ are not merely blocked, they are outside the
 * tree a URL can reach. This serves public/ and only public/, so a path that
 * escapes it 404s here exactly as it would 404 there.
 *
 * That is the whole reason this file is not two lines: PHP's built-in server
 * would happily serve the repository root, and a development machine on which
 * /../lib/auth.php resolves is a development machine that teaches the wrong
 * lesson.
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

/* Resolve before comparing. A request for /../lib/auth.php arrives as a path
   with .. in it, and only the resolved form can be checked against the root. */
$target = $root . rawurldecode($path);

if (is_dir($target)) {
    $index = rtrim($target, '/') . '/index.php';
    if (is_file($index)) {
        $target = $index;
    }
}

/* The uploads allow-list, and the headers that make a vector file safe.
   public/.htaccess is the authoritative copy; this is the same rule for the
   machine the editor is actually being written on. Without it the built-in
   server hands over ANY name under uploads/ as a static file — the one
   directory here holding bytes that arrived over the network — and it would
   serve an .svg as a document, which the host deliberately does not. A route
   that behaves differently in only one of the two places is exactly what this
   router exists to prevent. */
if (str_starts_with($path, '/uploads/')) {
    if (!preg_match('#^/uploads/[0-9a-f]{16}\.(webp|jpe?g|png|svg)$#', $path)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n";
        return true;
    }
    /* Served HERE rather than by returning false, which is the whole reason
       this branch exists. PHP's built-in server discards any header the router
       set when it goes on to serve a static file itself — so returning false
       would send the bytes with none of the three below, and the one thing
       that makes publishing a vector file safe would be missing on exactly the
       machine somebody is testing it on. */
    if (str_ends_with($path, '.svg') && is_file($root . $path)) {
        header('Content-Type: image/svg+xml');
        header('Content-Disposition: attachment');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Content-Type-Options: nosniff');
        readfile($root . $path);
        return true;
    }
}

$real = realpath($target);

if ($real === false || !str_starts_with($real, realpath($root) . '/')) {
    http_response_code(404);
    echo "Not found.\n";
    return true;
}

if (str_ends_with($real, '.php')) {
    require $real;
    return true;
}

/* Anything else: let the built-in server serve the file, or 404 it. */
return false;
