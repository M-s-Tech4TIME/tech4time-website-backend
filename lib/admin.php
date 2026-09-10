<?php
/**
 * Tech4TIME — the admin shell.
 *
 * Everything every section of /admin/ needs before it can do anything: the
 * check that somebody is signed in, the session and CSRF token, the section
 * registry the icon rail is drawn from, and the page furniture around whichever
 * section is showing.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 *
 * PROTECTING THE ADMIN
 * This used to be cPanel's job. Directory Privacy put HTTP Basic auth in front
 * of the directory and admin_require_auth() checked only that Apache had filled
 * in REMOTE_USER — PHP never saw a password and never verified one.
 *
 * It is the application's own job now: lib/auth.php holds the accounts, the
 * password hashes and the second factor, and /admin/login.php is the way in.
 * What survives from before is the principle, not the mechanism —
 * admin_require_auth() still refuses to run at all when the thing protecting it
 * is not in working order, because an editor that quietly works without a
 * password is worse than one that visibly does not work.
 *
 * What counts as "not in working order" has moved with the mechanism: it is now
 * a missing or web-reachable private store, or a page being served over plain
 * http, rather than an absent REMOTE_USER. See auth_problem().
 */

declare(strict_types=1);

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/publish_client.php';
require_once __DIR__ . '/upload.php';

/**
 * What the rail lists, in the order it lists them.
 *
 * Adding a page to the admin is adding a row here and a file in
 * sections/. Nothing else in the shell needs to know about it.
 *
 *   label  the name in the rail
 *   icon   a symbol id from public/assets/icons/sprite.svg
 *   desc   one line, shown when the rail is wide
 *   view   the public page this section edits, as a PATH ON THE PUBLIC SITE,
 *          or '' for none. Root-relative used to be right and stopped being:
 *          on this host '/' is the admin, so '/pages/careers/' would link the
 *          editor to a page of itself that does not exist. public_url() puts
 *          the other half's origin in front. See ADR 0011.
 */
const ADMIN_SECTIONS = [
    'overview' => [
        'label' => 'Overview',
        /* A grid of panels, which is what this screen draws: one card per
           editable page. It was 'home' until the home page got an editor of
           its own, at which point the rail had the same glyph twice and the
           two entries beside each other. 'home' belongs to the home page. */
        'icon'  => 'th-large',
        'desc'  => 'What can be changed here',
        'view'  => '',
    ],
    'home' => [
        'label' => 'Home',
        'icon'  => 'home',
        'desc'  => 'The hero, the services and the cards on the front page',
        'view'  => '/',                 // resolved through public_url()
    ],
    'about' => [
        'label' => 'About Us',
        'icon'  => 'users',
        'desc'  => 'The story, specialities and why-us cards',
        'view'  => '/pages/about/',
    ],
    'services' => [
        'label' => 'Services',
        'icon'  => 'layer-group',
        'desc'  => 'The services page and the six pages under it',
        'view'  => '/pages/services/',
    ],
    'company' => [
        'label' => 'Company Profile',
        'icon'  => 'building',
        'desc'  => 'Milestones, clients, technology',
        'view'  => '/pages/company-profile/',
    ],
    'careers' => [
        'label' => 'Careers',
        'icon'  => 'briefcase',
        'desc'  => 'Job posts and the CV link',
        'view'  => '/pages/careers/',   // resolved through public_url()
    ],
    'contact' => [
        'label' => 'Contact',
        'icon'  => 'envelope',
        'desc'  => 'Offices, numbers, the form',
        'view'  => '/pages/contact/',
    ],
    'certifications' => [
        'label' => 'Resource Certifications',
        'icon'  => 'certificate',
        'desc'  => 'Role groups and the qualifications inside them',
        'view'  => '/pages/resource-certifications/',
    ],
    'branding' => [
        'label' => 'Branding & Advertisement',
        'icon'  => 'palette',
        'desc'  => 'The logo files people download, and the terms of use',
        'view'  => '/pages/branding-and-advertisement/',
    ],
    'privacy' => [
        'label' => 'Privacy Policy',
        'icon'  => 'user-lock',
        'desc'  => 'What the site collects, why, and what people can ask for',
        'view'  => '/pages/privacy-policy/',
    ],
    'chrome' => [
        'label' => 'Header & Footer',
        /* Bands stacked down a page, which is what this screen edits: the
           bar across the top, the bar across the bottom, and the card that
           rises over the bottom one on a phone. Every more obvious glyph is
           already a rail row -- 'th-large' is the Overview, 'layer-group' is
           Services, 'building' is the Company Profile. */
        'icon'  => 'stream',
        'desc'  => 'The navigation, the footer and the mobile dock',
        /* No single page: this screen is on all of them. */
        'view'  => '',
    ],
    'seo' => [
        'label' => 'SEO Management',
        'icon'  => 'globe',
        'desc'  => 'Titles, descriptions and how each page appears in search',
        /* No single page: this screen edits all of them. */
        'view'  => '',
    ],
    'account' => [
        'label' => 'Account',
        'icon'  => 'user-shield',
        'desc'  => 'Your password and sign-in',
        'view'  => '',
    ],
];

/**
 * The rail's rows, in the order they appear.
 *
 * NOT THE SAME LIST AS ADMIN_SECTIONS, and the difference is one entry: the
 * account is a section but not a rail row. It is reached from the avatar menu
 * pinned to the foot of the rail, which is where the account belongs -- it is
 * the one thing on the screen that is about the person rather than the page,
 * and every tool with a rail like this one puts it there.
 *
 * IT CANNOT SIMPLY BE DROPPED FROM THE REGISTRY INSTEAD. admin_section()
 * returns 'overview' for any name ADMIN_SECTIONS does not list, so removing
 * the entry would make ?s=account land silently on the Overview and put the
 * password and two-factor screens out of reach.
 *
 * Adding a section means adding it in BOTH places, and its label has to fit
 * the rail on one line -- see .rail__label in public/assets/css/admin.css and
 * the assertion in tools/check_admin_a11y.py.
 */
const ADMIN_RAIL_SECTIONS = ['overview', 'home', 'about', 'services', 'company',
                             'careers', 'contact', 'certifications', 'branding',
                             'privacy', 'chrome', 'seo'];

/**
 * Sections that edit a page of the website, in rail order.
 *
 * ADMIN_SECTIONS also carries the ones that do not — the overview and the
 * account — so anything counting or listing "the pages you can edit" asks here
 * rather than filtering the registry by hand in three places.
 */
const ADMIN_PAGE_SECTIONS = ['home', 'about', 'services', 'company', 'careers',
                             'contact', 'certifications', 'branding', 'privacy'];

/* The marker admin_form_tail() writes and admin_form_truncated() looks for. */
const ADMIN_TAIL_FIELD = '__tail';

/* Where the rail's width is remembered. Written by admin-nav.js, read by
   admin_rail_state(), and named in both places — change one and change the
   other. */
const ADMIN_RAIL_COOKIE = 't4t_rail';

/* Symbols inlined into every admin page: the rail, the controls, and every
   icon the contact and company editors offer, since both render a live
   preview of them. */
const ADMIN_ICONS = [
    'home', 'th-large', 'briefcase', 'envelope', 'sun', 'moon', 'chevron-left',
    'chevron-right', 'arrow-up', 'arrow-down', 'arrow-right', 'link', 'user',
    'times', 'check', 'eye', 'lock', 'cogs', 'info-circle',
    'phone', 'mobile-alt', 'clock', 'map-marker-alt', 'building', 'globe',
    'headset', 'comment-alt', 'paper-plane', 'calendar-alt', 'linkedin',
    'github',
    /* Signing in: the rail's account entry, the sign-out control, and the
       enrolment and recovery panels on the account page. */
    'user-shield', 'user-lock', 'shield-alt', 'check-circle',
    'exclamation-circle', 'question-circle', 'angle-right',
    /* The header, footer and dock: the rail's own row, and every mark the
       chrome can be told to draw. Kept in step with CHROME_BAR_ICONS,
       CHROME_CONTACT_ICONS, CHROME_SOCIAL_ICONS and CHROME_SOCIAL_FALLBACK --
       tools/check_content_model.py asserts it, because the dock-key picker
       draws a live preview of whichever icon is chosen. */
    'stream',
    /* The company profile: the icons a principle card may carry, plus the one
       its picture rows use for "upload". Kept in step with COMPANY_ICONS —
       every name there must be inlined here, or the editor's live preview
       draws an empty box for it. */
    'lightbulb', 'handshake', 'calendar-check', 'cloud-upload-alt',
    /* The about page: every icon a speciality card, a why-us card or its
       closing button may carry. Kept in step with ABOUT_ICONS the same way,
       and for the same reason. */
    'code', 'cloud', 'users', 'server', 'graduation-cap', 'trophy',
    'layer-group', 'project-diagram',
    /* The home page: every icon a badge, a tag, a domain card, a service card
       or its closing panel may carry. Kept in step with HOME_ICONS the same
       way, and for the same reason. It is the longest addition because the
       home page is the widest summary of what the company does — the hero
       alone offers thirteen tags. */
    'shield-halved', 'shield-virus', 'bug', 'search', 'crosshairs', 'desktop',
    'first-aid', 'network-wired', 'file-contract', 'laptop-code', 'boxes',
    'chalkboard-teacher', 'sitemap', 'clipboard-check', 'rocket',
    /* The services pages: every icon a nav card, a service block, a core
       card, a layer, a solution card, an OSSF stage or a button may carry.
       Kept in step with SERVICES_ICONS the same way, and for the same reason.
       It is the longest addition of all because it covers seven pages -- the
       services index and its six detail pages -- and a hundred and
       thirty-seven solution cards between them. */
    'balance-scale', 'ban', 'bolt', 'brain', 'certificate', 'chart-bar',
    'chart-line', 'check-double', 'code-branch', 'cubes', 'database',
    'dharmachakra', 'dumbbell', 'exchange-alt', 'gavel', 'hdd', 'infinity',
    'list-alt', 'microscope', 'money-bill-wave', 'palette', 'people-arrows',
    'redo', 'robot', 'search-minus', 'search-plus', 'shield-cross',
    'tachometer-alt', 'tasks', 'tools', 'user-check', 'user-ninja',
    'user-secret', 'user-tie', 'users-cog', 'vial', 'virus', 'wrench',
];

/* ------------------------------------------------------------------- auth */

/**
 * Get ready to serve an admin page: check the setup, then start the session.
 *
 * Every page under /admin/ calls this, signed in or not — the login page needs
 * a session for its CSRF token just as much as the editors do.
 */
function admin_start_session(): void
{
    $problem = auth_problem();

    if ($problem !== '') {
        admin_refuse($problem);
    }

    auth_boot();

    /* A signed-in page left in a shared browser's cache is a signed-in page
       somebody else can press Back into. */
    header('Cache-Control: no-store, max-age=0');
}

/**
 * The account editing this, or a redirect to the login page.
 *
 * Returns the whole record rather than a name: sections want the display name,
 * the account page wants the second-factor state, and passing the record round
 * beats looking it up again in each of them.
 */
function admin_require_auth(): array
{
    admin_start_session();

    /* Nobody has been created yet — on a fresh install that is the setup page's
       job, not a login failure, and saying so beats a login form no password
       can ever satisfy. */
    if (!auth_has_accounts()) {
        header('Location: ' . ADMIN_BASE . 'setup.php');
        exit;
    }

    $account = auth_session_user();

    if ($account === null) {
        admin_go_to_login();
    }

    return $account;
}

/** Send an unauthenticated visitor to the login page, and back here after. */
function admin_go_to_login(): never
{
    $next = (string)($_SERVER['REQUEST_URI'] ?? ADMIN_BASE);

    header('Location: ' . ADMIN_BASE . 'login.php?next=' . rawurlencode($next));
    exit;
}

/**
 * Where the login page may send somebody once they are in.
 *
 * Only a path on this host. Without this check the next= parameter is an open
 * redirect: a link to our own login page that lands on somebody else's copy of
 * it, with our domain in the part of the URL people look at.
 *
 * THIS GOT WEAKER WHEN THE ADMIN MOVED, AND HAD TO BE REBUILT.
 * While the editor was a folder of the public site, ADMIN_BASE was '/admin/'
 * and "starts with ADMIN_BASE" did most of the work — a value had to begin
 * with those seven characters to survive. On admin.tech4time.bd the admin IS
 * the document root, ADMIN_BASE is '/', and that same test now accepts every
 * absolute path there is. What was a narrow allow-list quietly became "starts
 * with a slash", which "//evil.example" also does.
 *
 * So the shape of the check is different here: an explicit refusal of every
 * form a browser can read as another host, and then a positive test that what
 * is left looks like a path.
 */
function admin_safe_next(string $next): string
{
    $next = trim($next);

    /* A positive shape rather than a list of things to refuse: one leading
       slash, then a character that is NOT another slash or a backslash, then
       nothing a header could be split with.

       "//evil.example" and "/\evil.example" are both read as another host by a
       browser and fail the lookahead. "https://evil.example" has no leading
       slash. A value carrying CR or LF fails the character class. And bare "/"
       matches, which is what an empty next should become. */
    if (!preg_match('~^/(?![/\\\\])[^\x00-\x1f\x7f]*$~', $next)) {
        return ADMIN_BASE;
    }

    /* The encoded spellings of those same two characters. This value goes into
       a Location header and is decoded by the browser, not here, so "/%2f" is
       "//" by the time it matters. */
    $lower = strtolower($next);
    if (str_starts_with($lower, '/%2f') || str_starts_with($lower, '/%5c')) {
        return ADMIN_BASE;
    }

    return $next;
}


/**
 * Stop, and say what is wrong, when the admin is not safe to run.
 *
 * The Directory Privacy check used to live here and did the same thing for a
 * different reason. What is checked has changed; that it refuses rather than
 * degrades has not.
 */
function admin_refuse(string $problem): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>The admin cannot start</title>'
       . '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/base.css')) . '">'
       . '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/theme.css')) . '">'
       . '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/layout.css')) . '">'
       . '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/components.css')) . '">'
       . '<link rel="stylesheet" href="' . h(admin_asset('/assets/css/admin.css')) . '">'
       . '</head><body class="page"><main class="admin"><div class="admin__inner">'
       . '<div class="admin__notice admin__notice--error">'
       . '<h1>The admin cannot start safely</h1>'
       . '<p>It has refused to load rather than let anyone edit your website.</p>'
       . '<p><strong>' . h($problem) . '</strong></p>'
       . '<p class="admin__fineprint">The private directory holds the password '
       . 'hashes and the sign-in sessions, and must sit beside the document root '
       . 'rather than inside it, writable by PHP. Set <code>T4T_PRIVATE</code> to '
       . 'its full path if it is anywhere other than <code>t4t-private-admin</code> '
       . 'beside the repository, two levels up from the document root. '
       . 'Upload <code>tools/host-probe.php</code>, '
       . 'load it once and delete it to see what this host reports.</p>'
       . '</div></div></main></body></html>';

    exit;
}

/* --------------------------------------------------------- truncated forms */

/**
 * The last control in a long form, and the check that it arrived.
 *
 * PHP stops parsing a request body after max_input_vars fields and DOES NOT
 * SAY SO to the script — it drops the rest and carries on. For a form the size
 * of the company profile that is a real limit rather than a theoretical one:
 * it posts around five hundred and fifty fields today, against a default of a
 * thousand, and every row added moves it closer.
 *
 * What makes it dangerous is the shape of the failure. The editor would rebuild
 * the document from a truncated $_POST, decide the missing rows had been
 * removed, save that, publish it, and report success. Somebody would find out
 * when a client noticed their logo was gone.
 *
 * So the last thing every long form renders is this marker. Truncation always
 * drops the tail, so its absence is exactly the condition — no counting, no
 * guessing at what should have arrived, and nothing to keep in step.
 */
function admin_form_tail(): string
{
    return '<input type="hidden" name="' . ADMIN_TAIL_FIELD . '" value="1">';
}

/** Whether this POST was cut short before the form ended. */
function admin_form_truncated(): bool
{
    return ($_POST[ADMIN_TAIL_FIELD] ?? '') !== '1';
}

/** What to tell somebody whose save was refused for that reason. */
function admin_truncated_message(): string
{
    return 'This page is too big for the server to accept in one go: it sent '
         . 'more fields than PHP\'s max_input_vars setting allows, so the end of '
         . 'the form was silently dropped. NOTHING HAS BEEN SAVED, which is the '
         . 'right outcome — saving would have deleted whatever came after the '
         . 'cut. Remove some entries, or ask whoever runs the server to raise '
         . 'max_input_vars (it is currently ' . (int)ini_get('max_input_vars')
         . ').';
}

/* ------------------------------------------------------------------- CSRF */

/* The session and its token belong to lib/auth.php now, which is what sets the
   cookie flags and regenerates the id on sign-in. These stay as the names the
   editors have always called, so nothing in sections/ had to change. */

function admin_csrf(): string
{
    return auth_csrf();
}

/**
 * Being signed in proves who you are, not that you meant to click this. Without
 * a token, a page on another site could post here using the browser's live
 * session and delete a job post.
 */
function admin_check_csrf(): void
{
    auth_check_csrf();
}

/**
 * The hidden inputs every form in the admin needs: the token, and which
 * section is posting, so the router knows where to send it.
 */
function admin_form_fields(string $section): string
{
    return '<input type="hidden" name="csrf" value="' . h(admin_csrf()) . '">'
         . '<input type="hidden" name="s" value="' . h($section) . '">';
}

/* ---------------------------------------------------------------- routing */

/** Which section is showing. Anything unrecognised falls back to the overview. */
function admin_section(): string
{
    $name = (string)($_GET['s'] ?? $_POST['s'] ?? 'overview');
    return isset(ADMIN_SECTIONS[$name]) ? $name : 'overview';
}

/** A link within the admin: admin_url('careers', ['action' => 'new']). */
function admin_url(string $section, array $params = []): string
{
    return '?' . http_build_query(['s' => $section] + $params);
}

/**
 * Finish a POST by redirecting, so a reload does not repeat it.
 *
 * It also carries the publish outcome across the redirect. Every save
 * publishes, and a save that reached this file but not the live site must not
 * report itself as done — so the check is here, where every section already
 * ends up, rather than in each of them.
 *
 * The failure goes in the session rather than the query string: the message is
 * a sentence, sometimes two, and a URL is not where a person should meet it.
 */
function admin_redirect(string $section, string $message = '', array $params = []): never
{
    if ($message !== '') {
        $params['saved'] = $message;
    }

    $note = publish_note();

    if ($note !== null && ($note['ok'] ?? false) !== true) {
        $_SESSION['publish_failed'] = [
            'section' => $section,
            'code'    => (string)($note['code'] ?? 'refused'),
            'error'   => (string)($note['error'] ?? publish_reason((string)($note['code'] ?? ''))),
        ];
    } else {
        unset($_SESSION['publish_failed']);
    }

    header('Location: ' . admin_url($section, $params));
    exit;
}

/* ------------------------------------------------------------------ icons */

/**
 * Inline the sprite symbols the admin uses.
 *
 * Pages under pages/ get theirs from tools/inject_icons.py, which does not
 * walk this directory. Reading them straight from the sprite keeps them in
 * step with it without adding the admin to a build step, and a <use href>
 * pointing at an external file does not resolve cross-document in Chromium or
 * WebKit — which is why they have to be inlined at all.
 */
function admin_icons(array $names): string
{
    $sprite = @file_get_contents(__DIR__ . '/../public/assets/icons/sprite.svg');
    if ($sprite === false) {
        return '';
    }

    $symbols = '';
    foreach (array_unique($names) as $name) {
        $pattern = '#<symbol id="' . preg_quote((string)$name, '#') . '".*?</symbol>#s';
        if (preg_match($pattern, $sprite, $m)) {
            $symbols .= $m[0];
        }
    }

    return $symbols === ''
        ? ''
        : '<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          . $symbols . '</svg>';
}

function admin_icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . h($class) . '" aria-hidden="true" focusable="false">'
         . '<use href="#' . h($name) . '"></use></svg>';
}

/* --------------------------------------------------------- cache-busting */

/**
 * An asset URL with a version on the end.
 *
 * THE ADMIN'S OWN STYLESHEET WAS BEING SERVED FROM CACHE FOR A YEAR.
 *
 * public/.htaccess sends every .css and .js here with
 * `Cache-Control: public, max-age=31536000, immutable`, and the filenames
 * carry no version because there is no build step to put one there. `immutable`
 * is the part that turns a stale asset into a stuck one: it tells the browser
 * not to revalidate on an ordinary reload, so pressing F5 -- the one thing a
 * person tries -- does nothing. Only a forced reload clears it.
 *
 * So a deploy would change the markup and leave the stylesheet behind it. The
 * shell would come down with a rail toggle, an account menu, an outline and a
 * two-label save button, and be painted by a stylesheet that had never heard of
 * any of them — an admin that looked, correctly, broken. It is not a theoretical
 * failure; it is what the last deploy did to the person using this.
 *
 * The version is the file's own modification time. Change the file and the URL
 * changes with it, which is what makes a year-long cache safe rather than
 * dangerous. It costs one stat() per asset per page, and nothing at all on the
 * cached hit.
 */
function admin_asset(string $path): string
{
    $file = __DIR__ . '/../public' . $path;
    $stamp = @filemtime($file);

    return $stamp === false ? $path : $path . '?v=' . dechex($stamp);
}

/* ------------------------------------------------------- pictures on rows */

/**
 * Where the editor previews a stored picture from.
 *
 * An uploaded picture is served by THIS host, which holds the canonical copy
 * (ADR 0010) — the preview must not depend on a publish having succeeded, or
 * the operator cannot see what they just chose until the other half agrees.
 * Everything else is artwork that ships with the public site and exists only
 * there, so it is fetched from there.
 */
function admin_preview_src(string $path): string
{
    if (str_starts_with($path, UPLOAD_URL_ROOT)) {
        return $path;
    }

    /* A PICTURE THIS HOST ALSO HAS is served from this host. The office flags
       ship with both halves, so previewing them from the public site was a
       cross-origin request for a file sitting in public/ right here — and one
       that draws nothing at all in a local preview, where the public site is a
       closed port. Anything only the public site has, like the technology
       logos, still comes from there. */
    if (is_file(__DIR__ . '/../public' . $path)) {
        return $path;
    }

    return public_url($path);
}

/**
 * The picture on one row: what is there, and how to replace it.
 *
 * $field is the name prefix — "clients[items][3][image]" — and $upload is the
 * name of the file input, which admin_uploaded_files() reads back. They are
 * separate arguments because they are separate shapes: one is where the record
 * lives in the document, the other is where the browser puts the bytes.
 */
function admin_image_fields(string $field, string $upload, array $image,
                            string $noun = 'picture', string $empty = '',
                            array $fallback = [], bool $vector = false,
                            int $maxSide = UPLOAD_MAX_DIMENSION): void
{
    $image += ['src' => '', 'webp' => '', 'width' => 0, 'height' => 0];

    /* A vector file is not drawn here, and that is deliberate rather than a
       limitation. /uploads/*.svg is served with Content-Disposition:
       attachment on both hosts -- which is the thing that makes publishing one
       safe at all -- so an <img> pointing at it is asking the browser to
       render something the server has just said to download. It is a FILE on
       this screen: its name, its size and how big it draws. */
    $isVector = str_ends_with(strtolower((string)$image['src']), '.svg');
    ?>
        <div class="admin-card__media">
<?php if ($image['src'] !== '' && !$isVector): ?>
          <img class="admin-card__thumb" src="<?= h(admin_preview_src((string)$image['src'])) ?>"
               alt="" width="<?= (int)$image['width'] ?>" height="<?= (int)$image['height'] ?>"
               loading="lazy" decoding="async">
          <p class="admin__fineprint">
            <code><?= h(basename((string)$image['src'])) ?></code>
            &middot; <?= (int)$image['width'] ?>&times;<?= (int)$image['height'] ?>
<?php if ($image['webp'] !== ''): ?>
            &middot; with a WebP version
<?php endif; ?>
          </p>
<?php elseif ($image['src'] !== ''): ?>
          <p class="admin__fineprint">
            <code><?= h(basename((string)$image['src'])) ?></code>
            &middot; vector
<?php if ((int)$image['width'] > 0 && (int)$image['height'] > 0): ?>
            &middot; draws at <?= (int)$image['width'] ?>&times;<?= (int)$image['height'] ?>
<?php endif; ?>
          </p>
<?php elseif ($fallback !== []): ?>
          <?php /* A ROW WITH A PICTURE THIS RECORD DID NOT PUT THERE — an
                   office still using one of the flags that ship with the site.
                   It used to be described in a sentence and not shown, so the
                   one question somebody actually has here, "which flag is on
                   this office", could only be answered by opening the live
                   page. It is the same thumbnail an uploaded one gets, because
                   it is the same question. */ ?>
          <img class="admin-card__thumb" src="<?= h(admin_preview_src((string)$fallback['src'])) ?>"
               alt="" loading="lazy" decoding="async">
          <p class="admin__hint"><?= h((string)($fallback['note'] ?? '')) ?></p>
<?php else: ?>
          <p class="admin__hint">
            <?= $empty !== '' ? h($empty) : 'No ' . h($noun) . ' yet.' ?>
          </p>
<?php endif; ?>
        </div>
        <?php /* Carried rather than edited. The paths and the size come from
                 the file itself when it is uploaded, and a size typed by hand
                 is a size that is wrong — which moves the page as it loads.
                 The model re-checks all four against CONTRACT_IMAGE_ROOTS
                 anyway, because a hidden input is a text field with the label
                 taken off. */ ?>
        <input type="hidden" name="<?= h($field) ?>[src]" value="<?= h((string)$image['src']) ?>">
        <input type="hidden" name="<?= h($field) ?>[webp]" value="<?= h((string)$image['webp']) ?>">
        <input type="hidden" name="<?= h($field) ?>[width]" value="<?= (int)$image['width'] ?>">
        <input type="hidden" name="<?= h($field) ?>[height]" value="<?= (int)$image['height'] ?>">

<?php $problem = upload_problem(); ?>
<?php if ($problem !== ''): ?>
        <p class="admin__hint"><?= admin_icon('info-circle', 'icon icon--sm') ?>
           <?= h($problem) ?></p>
<?php else: ?>
        <label class="admin__field admin__field--wide">
          <span class="admin__label">
            <?= $image['src'] === '' ? 'Choose a ' . h($noun) : 'Replace it' ?>
          </span>
          <input class="admin__input admin__file" type="file"
                 name="<?= h($upload) ?>"
                 accept="image/jpeg,image/png,image/webp<?= $vector ? ',image/svg+xml' : '' ?>">
          <span class="admin__hint">
            JPEG, PNG or WebP<?= $vector ? ' or SVG' : '' ?>, up to
            <?= (int)(UPLOAD_MAX_BYTES / 1048576) ?> MB.
            It is re-encoded here — which is what removes the location and the
            camera details a photograph carries — reduced to
            <?= (int)$maxSide ?> pixels on its longest side, given a WebP
            version, and sent to the live site straight away.
<?php if ($vector): ?>
            An SVG is not re-encoded but rewritten: it is read, checked against
            a list of what a drawing may contain, and saved as the result. Any
            script, embedded picture, animation or reference to another file
            makes it refused rather than quietly stripped, so what you publish
            is the artwork you chose or nothing at all.
<?php endif; ?>
          </span>
        </label>
<?php endif; ?>
    <?php
}

/**
 * $_FILES, flattened.
 *
 * PHP turns upload[clients][3] inside out: rather than one entry per file it
 * gives $_FILES['upload']['name']['clients'][3] and four more arrays shaped
 * the same way. This puts each file back together.
 *
 * @return list<array{0:string,1:int,2:array}>  list name, row index, one $_FILES entry
 */
function admin_uploaded_files(): array
{
    $found = [];
    $upload = $_FILES['upload'] ?? null;

    if (!is_array($upload) || !isset($upload['name']) || !is_array($upload['name'])) {
        return $found;
    }

    foreach ($upload['name'] as $band => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach (array_keys($rows) as $index) {
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $file[$key] = $upload[$key][$band][$index] ?? null;
            }
            /* An untouched file input still posts, with error NO_FILE. */
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $found[] = [(string)$band, (int)$index, $file];
        }
    }

    return $found;
}

/**
 * Send a stored picture and its WebP sibling to the live site.
 *
 * Returns '' or a sentence. Both files go, because the page names both and a
 * <source> pointing at a picture the other host does not have is a broken
 * image for everybody whose browser prefers WebP — which is nearly everybody.
 */
function admin_send_picture(array $stored): string
{
    foreach (['src', 'webp'] as $which) {
        $name = basename((string)($stored[$which] ?? ''));
        if ($name === '') {
            continue;
        }

        $path = UPLOAD_DIR . '/' . $name;
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return 'The picture was not where it had just been written.';
        }

        $kind = publish_asset_type($bytes);
        $result = publish_asset($bytes, $kind[1] ?? 'application/octet-stream');

        if (($result['ok'] ?? false) !== true) {
            return 'Saved here, but the live site did not take the picture: '
                 . (string)($result['error'] ?? 'it refused.');
        }
    }

    return '';
}

/* -------------------------------------------------------- repeatable rows */

/**
 * The shown/hidden control every band and every row carries.
 *
 * A <select> and not a checkbox, and the reason is not taste: an unticked
 * checkbox is not posted at all, so "switched off" and "the field never
 * reached the server" arrive identically — and PHP's max_input_vars silently
 * drops the tail of a long form, which is the second of those. A <select>
 * always posts a value.
 */
/**
 * The head of one band: its name, what it is for, and how to add to it.
 *
 * WHY "ADD" IS UP HERE. It was the last thing in the band, under the rows. On
 * the technology band that is more than thirty pictures, so adding a
 * thirty-first meant scrolling past all thirty to reach the button — and then,
 * because the new row is appended, scrolling back to it. The band's name is
 * where you arrive when you follow "On this page", so the button that adds to
 * the band belongs beside it. admin-forms.js moves the focus to the new row,
 * which is what stops a press up here looking like a press that did nothing.
 *
 * IT IS INSIDE THE <legend>. That element has to be the first child of the
 * fieldset to be its accessible name, and a heading with a control beside it
 * has to be one row — so the row goes in the legend rather than the legend
 * going in the row. <legend> takes phrasing content, and a <button> is
 * phrasing content, so this is ordinary HTML rather than a trick.
 *
 * $add    ['do' => 'technology-add:0', 'label' => 'Add a technology']
 * $status ['name' => 'technology[status]', 'value' => …, 'noun' => 'this section']
 *
 * The `do` value must begin with the same word the row's own fields are named
 * with — technology[items][…] goes with technology-add. admin-forms.js finds
 * the new row by that name, and a band whose button and fields disagree adds a
 * row and leaves the focus where it was.
 *
 * WHEN THEY CANNOT AGREE, say so with 'rows'. A nested list has no such word:
 * the services editor's solution cards are named
 * service[layers][items][0][cards][…], which begins with neither the band nor
 * anything else useful. Passing
 * ['rows' => 'service[layers][items][0][cards]['] writes it on the button as
 * data-rows, and admin-forms.js looks there instead of guessing.
 */
function admin_band_head(string $legend, string $blurb = '',
                         array $add = [], array $status = []): void
{
    ?>
    <legend class="admin__band">
      <span class="admin__band-row">
        <span class="admin__section-title"><?= h($legend) ?></span>
<?php if ($add): ?>
        <button class="btn btn--secondary admin__band-add" type="submit"
                name="do" value="<?= h((string)$add['do']) ?>"<?=
          isset($add['rows']) ? ' data-rows="' . h((string)$add['rows']) . '"' : '' ?>>
          <?= h((string)$add['label']) ?>
        </button>
<?php endif; ?>
      </span>
    </legend>
<?php if ($blurb !== ''): ?>
    <p class="admin__blurb"><?= h($blurb) ?></p>
<?php endif; ?>
<?php if ($status): ?>
    <div class="admin__grid">
      <?php admin_status_field((string)$status['name'], (string)$status['value'],
                               (string)($status['noun'] ?? 'this section')); ?>
    </div>
<?php endif; ?>
    <?php
}

function admin_status_field(string $name, string $status, string $noun): void
{
    ?>
        <label class="admin__field">
          <span class="admin__label">Shown on the page</span>
          <select class="admin__input" name="<?= h($name) ?>">
            <option value="shown"<?= $status !== 'hidden' ? ' selected' : '' ?>>Shown — visitors see <?= h($noun) ?></option>
            <option value="hidden"<?= $status === 'hidden' ? ' selected' : '' ?>>Hidden — kept, not shown</option>
          </select>
        </label>
    <?php
}

/**
 * The head of one repeatable row: what it is, whether it shows, and the
 * controls that move or remove it.
 *
 * ONE COMPONENT, NOT ONE PER EDITOR. This markup lived in sections/contact.php
 * and again in sections/company.php, and they were not the same. Contact wrapped
 * the row in .admin-card__head -- a flex line -- so .admin-card__controls'
 * margin-inline-start:auto pushed the buttons to the right end of it, with the
 * row's number and a preview of its content on the left. Company emitted the
 * same three buttons as a bare child of .admin-card, where that rule has
 * nothing to push against: they sat top-left, above the fields, with no head at
 * all. Same classes, same stylesheet, different page.
 *
 * That is what copying markup between sections does, and it is not a thing a
 * check can catch by looking at either file. So it is here, both of them call
 * it, and the next editor gets the layout by asking for it rather than by
 * remembering how the last one did it.
 *
 * $card takes:
 *   label   what the row is called. Empty renders "Untitled <noun>".
 *   noun    'office', 'figure', 'milestone' -- names the row for a screen
 *           reader when it has no label of its own, and in "Untitled …".
 *   detail  one line of the row's content, so a collapsed list is readable.
 *   icon    a sprite id to show before the label, or ''.
 *   status  'shown' or 'hidden' for the pill; '' for rows that have no such
 *           setting, which is why it is not simply a boolean.
 *   controls which of ['up', 'down', 'remove'] to draw. All three by default.
 *
 * WHY 'controls' EXISTS. The dock bar is exactly CHROME_BAR_SLOTS keys, and
 * that number is code: the grid is written for four, tools/test_nav.py asserts
 * four, and a fifth would break a layout nobody has measured. So those rows
 * reorder but do not add or remove -- and the alternative to saying so here
 * was a second head laid out by hand in one section, which is the exact
 * failure this function was extracted to stop. A caller that says nothing gets
 * what every caller got before.
 *
 * The button VALUES are the contract with each section's POST handler --
 * "<band>-up:<index>" -- and are the reason $band and $index are separate
 * arguments rather than one formatted string.
 */
function admin_card_head(string $band, int $index, int $total, array $card): void
{
    $card += ['label' => '', 'noun' => 'row', 'detail' => '', 'icon' => '',
              'status' => '', 'controls' => ['up', 'down', 'remove']];

    $label = (string)$card['label'];
    $noun  = (string)$card['noun'];

    /* Two different fallbacks on purpose. What is SHOWN says the row has no
       name yet; what is ANNOUNCED has to tell three "Remove" buttons apart, so
       it uses the position. "Remove Untitled office" three times over is a
       menu of identical controls. */
    $shown = $label !== '' ? $label : 'Untitled ' . $noun;
    $named = $label !== '' ? $label : $noun . ' ' . ($index + 1);
    ?>
      <div class="admin-card__head">
        <span class="admin-card__index"><?= $index + 1 ?></span>
        <span class="admin-card__preview">
<?php if ($card['icon'] !== ''): ?>
          <?= admin_icon((string)$card['icon'], 'icon') ?>
<?php endif; ?>
          <strong><?= h($shown) ?></strong>
<?php if ($card['detail'] !== ''): ?>
          <span class="admin-card__value"><?= h((string)$card['detail']) ?></span>
<?php endif; ?>
        </span>
<?php if ($card['status'] !== ''): ?>
        <span class="admin-row__status admin-row__status--<?= $card['status'] === 'shown' ? 'open' : 'draft' ?>">
          <?= $card['status'] === 'shown' ? 'Shown' : 'Hidden' ?>
        </span>
<?php endif; ?>
        <?php /* margin-inline-start:auto in admin.css puts these at the right
                 end of the line. That only works because they are inside the
                 flex row above -- see the note at the top of this function. */ ?>
        <div class="admin-card__controls">
<?php if (in_array('up', $card['controls'], true)): ?>
          <button class="btn btn--ghost" type="submit" name="do" value="<?= h($band) ?>-up:<?= $index ?>"
                  aria-label="Move <?= h($named) ?> up"<?= $index === 0 ? ' disabled' : '' ?>>&uarr;</button>
<?php endif; ?>
<?php if (in_array('down', $card['controls'], true)): ?>
          <button class="btn btn--ghost" type="submit" name="do" value="<?= h($band) ?>-down:<?= $index ?>"
                  aria-label="Move <?= h($named) ?> down"<?= $index === $total - 1 ? ' disabled' : '' ?>>&darr;</button>
<?php endif; ?>
<?php if (in_array('remove', $card['controls'], true)): ?>
          <button class="btn btn--ghost admin-row__delete" type="submit"
                  name="do" value="<?= h($band) ?>-remove:<?= $index ?>"
                  aria-label="Remove <?= h($named) ?>">Remove</button>
<?php endif; ?>
        </div>
      </div>
<?php
}

/**
 * Remove or reorder one row of a list.
 *
 * HERE BECAUSE IT WAS ABOUT TO BE COPIED A THIRD TIME. The docblock on the
 * copy in sections/branding.php says it best -- "four copies of an
 * array_splice is four places for an off-by-one to live" -- and there were by
 * then two copies of that sentence, in branding and in certifications,
 * byte-identical. The privacy editor moves rows at three levels of nesting and
 * would have made a third.
 *
 * Returns the new list and what to tell the person who pressed the button, or
 * null when the press cannot be honoured -- a row that is not there, or a move
 * off either end. Null is not an error: it is a button pressed twice before
 * the page caught up, and the right answer is to redraw unchanged.
 */
function admin_move_row(array $rows, string $what, int $index): ?array
{
    if (!isset($rows[$index])) {
        return null;
    }

    if ($what === 'remove') {
        array_splice($rows, $index, 1);

        return [array_values($rows), 'Removed. Nothing is written to the site until you save.'];
    }

    $to = $index + ($what === 'up' ? -1 : 1);
    if ($to < 0 || $to >= count($rows)) {
        return null;
    }

    [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];

    return [$rows, 'Moved. Nothing is written to the site until you save.'];
}

/**
 * A warning that is always there, because what it warns about is always true.
 *
 * NOT A FLASH, AND THAT IS THE POINT. admin_notices() reports what just went
 * wrong and admin_publish_notice() reports what just failed; both answer "what
 * happened?". This one answers "what should you know before you start?", so it
 * is drawn on every render and never dismissed. The branding editor wrote one
 * inline for its disclaimer band; the privacy editor needs one for the whole
 * page, and two inline copies of a component is how a component stops looking
 * the same in both places.
 *
 * Bare admin__notice, with no --warn or --error modifier: nothing is wrong.
 */
function admin_standing_notice(string $text): void
{
    ?>
    <p class="admin__notice admin__notice-line">
      <?= admin_icon('info-circle', 'icon icon--sm') ?>
      <span><?= h($text) ?></span>
    </p>
    <?php
}

/**
 * Where a page's search and sharing settings are edited, which is not here.
 *
 * EVERY PAGE EDITOR USED TO CARRY A "How it appears elsewhere" FIELDSET: a tab
 * title, a search description and a share title, nine near-identical copies of
 * the same three boxes. They are edited on one screen now -- ?s=seo -- together
 * with the crawl setting, the breadcrumb, the share card and the sitemap
 * tuning that never had a box at all, and beside every other page's, which is
 * the only way a person can see that two pages share a title.
 *
 * THE VALUES DID NOT MOVE. They are still in this page's own document, in the
 * meta band every document has, and are still published with it. Only the
 * typing moved. See the page metadata block in lib/contract.php.
 *
 * A fieldset with nothing in it but a link, rather than nothing at all,
 * because the outline column on the right lists band-meta and a person who
 * knows where that setting used to be should find out where it went rather
 * than find a gap.
 *
 * $page is the SEO screen's key for this page: a route key from SEO_ROUTES, or
 * "service:<id>" for one of the service pages.
 */
function admin_meta_band(string $page): void
{
    ?>
    <fieldset class="admin__block" id="band-meta">
      <?php admin_band_head('How it appears elsewhere',
          'The browser tab title, the search description and the share card '
          . 'for this page are edited with every other page\'s, in one place.'); ?>

      <p class="admin__notes">
        <a href="<?= h(admin_url('seo', ['page' => $page])) ?>">
          Open SEO Management for this page
        </a>
      </p>
    </fieldset>
    <?php
}

/* --------------------------------------------------------------- the page */

/**
 * The one or two letters that stand for a name.
 *
 * "Syed Golam Abid" -> SG, "testadmin" -> TE. Every tool with a rail like this
 * one draws the account as initials rather than as a generic silhouette,
 * because the point of the avatar is to say WHICH account is signed in --
 * which matters on a host where more than one person can be.
 *
 * NO mb_* HERE, and that is not an oversight. This host has mbstring and the
 * next one may not; lib/html.php makes the same choice for the same reason.
 * PCRE's /u and \X are part of the regex engine rather than an extension, and
 * \X takes a whole grapheme -- so an accented letter or a Bengali cluster
 * comes back whole instead of as the first byte of one, which renders as a
 * replacement glyph in a circle.
 *
 * strtoupper() is ASCII-only on purpose: it leaves a script that has no case
 * exactly as it was, which is the right answer for one.
 *
 * Returns '' when there is nothing to take, and the caller falls back to the
 * icon.
 */
function admin_initials(string $name): string
{
    if (!preg_match_all('/[^\s._-]+/u', $name, $found) || !$found[0]) {
        return '';
    }

    $words = $found[0];

    /* Two words give one letter each; one word gives its first two, so a
       single-word login still reads as a mark rather than as one lonely
       letter. */
    $letters = count($words) > 1
        ? admin_graphemes($words[0], 1) . admin_graphemes($words[1], 1)
        : admin_graphemes($words[0], 2);

    return strtoupper($letters);
}

/** The first $n characters of a word, counting characters and not bytes. */
function admin_graphemes(string $word, int $n): string
{
    return preg_match('/^\X{1,' . $n . '}/u', $word, $m) ? $m[0] : '';
}

/**
 * How wide the rail should be drawn: 'wide' or 'narrow'.
 *
 * WHY THE SERVER KNOWS THIS AT ALL. It was a localStorage value applied by
 * admin-nav.js, which is deferred — so the browser parsed and painted a rail
 * at its full width and then, a frame or two later, snapped it shut. Every
 * load, on every screen, visibly.
 *
 * A cookie arrives with the request, so the attribute is written before the
 * rail is, and the first frame is already the right shape. That is the only
 * reason this is a cookie: it is a width, it is not a secret, and nothing in
 * the application branches on it.
 *
 * Anything that is not one of the two names is the wide rail — the labelled
 * one, which is what somebody who has never pressed the button should get.
 */
function admin_rail_state(): string
{
    return ($_COOKIE[ADMIN_RAIL_COOKIE] ?? '') === 'narrow' ? 'narrow' : 'wide';
}

/**
 * The current section's table of contents, remembered between the two halves
 * of the shell.
 *
 * admin_head() is given it and admin_foot() writes it, and there is nothing
 * between them to pass it through — the section's own markup is what sits in
 * the middle. Passing it to admin_foot() as well would mean every caller
 * repeating the constant, and one of them eventually not.
 */
function admin_outline(?array $set = null): array
{
    static $outline = [];

    if ($set !== null) {
        $outline = $set;
    }

    return $outline;
}

/**
 * Everything from <!DOCTYPE> down to the opening of the section's own markup:
 * the head, the icon rail, and the header strip above the section.
 */
function admin_head(string $section, string $user, string $lede = '',
                    array $outline = [], array $save = []): void
{
    $meta = ADMIN_SECTIONS[$section];
    $title = $meta['label'] . ' | Tech4TIME admin';

    /* Held for admin_foot(), which writes it: the outline is a column beside
       the editing column, so it has to be emitted after the section's own
       markup has closed. */
    admin_outline($outline);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="icon" href="<?= h(admin_asset('/assets/images/favicon/favicon.ico')) ?>" sizes="any">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/theme.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/components.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/admin.css')) ?>">
<script src="<?= h(admin_asset('/assets/js/theme-init.js')) ?>"></script>
</head>
<body class="page admin-page">
<?= admin_icons(ADMIN_ICONS) ?>

<a class="skip-link" href="#admin-main">Skip to the editor</a>

<?php /* WHERE THE ADMIN SAYS WHAT JUST HAPPENED.

         Outside .admin-shell, and therefore outside the part admin-swap.js
         replaces: a message about a move between screens must not be removed
         by the move it is about.

         Empty, and it stays empty without JavaScript — the server still prints
         "Saved the …" into the page itself, exactly where it always did, and
         admin-toast.js lifts it out of there into this. So the confirmation
         exists either way; the enhancement is only where it is shown.

         aria-live on the region rather than on each message, because the
         region is what is there when the announcement arrives. */ ?>
<div class="toasts" data-toasts role="status" aria-live="polite"></div>

<div class="admin-shell">

  <?php /* The rail. Its default state is the wide one, so it is fully
           labelled with no JavaScript at all; admin-nav.js adds the button
           that narrows it to icons and writes the cookie admin_rail_state()
           reads back. The width is decided HERE, before a byte of the rail is
           sent, which is what stops it being drawn wide and then snapped shut
           a frame later. Below 60em the CSS turns it into a strip across the
           top instead — a fixed column down the side of a phone leaves no
           room for the thing being edited. */ ?>
  <aside class="rail" data-rail="<?= h(admin_rail_state()) ?>" id="admin-rail">
    <?php /* THE BRAND AND THE CONTROL THAT NARROWS THE RAIL SHARE THIS ROW.

             The control used to live in the bar across the top, on the
             reasoning that it would then be in one place whatever shape the
             rail was in. In use that reads as a button belonging to the page
             rather than to the menu it operates, and it is nowhere near the
             thing it changes. It is here now, at the rail's own top edge,
             which is where a rail's collapse control is looked for.

             Below 60em the whole head is display:none — the rail is a
             horizontal strip there and there is no width to narrow. */ ?>
    <div class="rail__head">
      <a class="rail__brand" href="<?= h(public_url('/')) ?>" aria-label="Tech4TIME — view the site">
        <picture class="rail__logo-wrap theme-swap--light">
          <source srcset="<?= h(admin_asset('/assets/images/logo/logo-light-180.webp')) ?>" type="image/webp">
          <img class="rail__logo" src="<?= h(admin_asset('/assets/images/logo/logo-light-180.png')) ?>"
               alt="Tech4TIME" width="180" height="64" decoding="async">
        </picture>
        <picture class="rail__logo-wrap theme-swap--dark">
          <source srcset="<?= h(admin_asset('/assets/images/logo/logo-dark-180.webp')) ?>" type="image/webp">
          <img class="rail__logo" src="<?= h(admin_asset('/assets/images/logo/logo-dark-180.png')) ?>"
               alt="Tech4TIME" width="180" height="64" loading="lazy" decoding="async">
        </picture>
        <span class="rail__kicker">Admin</span>
      </a>

      <?php /* Starts hidden and admin-nav.js unhides it: with no script the
               rail cannot narrow, and a control that does nothing is worse
               than no control.

               .btn--icon is the round outlined control the theme toggle uses.
               It was a bare 2rem square, which at the top of a rail full of
               labelled rows read as a stray chevron rather than a button. */ ?>
      <button class="btn btn--icon btn--icon-sm rail__toggle" type="button" hidden
              data-rail-toggle aria-controls="admin-rail" aria-expanded="true">
        <?= admin_icon('chevron-left', 'icon rail-toggle__icon--narrow') ?>
        <?= admin_icon('chevron-right', 'icon rail-toggle__icon--wide') ?>
        <span class="visually-hidden">Narrow the menu</span>
      </button>
    </div>

    <nav class="rail__nav" aria-label="Pages you can edit">
      <ul class="rail__list" role="list">
<?php foreach (ADMIN_RAIL_SECTIONS as $key): $item = ADMIN_SECTIONS[$key]; ?>
<?php $current = $key === $section; ?>
        <li>
          <a class="rail__item" href="<?= h(admin_url($key)) ?>"<?= $current ? ' aria-current="page"' : '' ?>>
            <span class="rail__icon"><?= admin_icon($item['icon']) ?></span>
            <span class="rail__text">
              <span class="rail__label"><?= h($item['label']) ?></span>
              <span class="rail__desc"><?= h($item['desc']) ?></span>
            </span>
          </a>
        </li>
<?php endforeach; ?>
      </ul>
    </nav>

    <?php /* THE FOOT OF THE RAIL IS WHO YOU ARE.

             It held the two links to the public site, which are about the page
             being edited and now sit beside its heading where that is what
             they read as. What belongs at the bottom of a rail is the account:
             it is the one thing on this screen that is about the person rather
             than the page, and the bottom of the menu is where every tool that
             has one puts it.

             The menu opens UPWARDS, because there is nothing below it. */ ?>
    <div class="rail__foot">
<?php if ($user !== ''): ?>
      <?php /* A <details>, not a scripted dropdown. The browser opens it,
               moves focus into it and closes it on Escape with no JavaScript
               whatsoever, which is what lets a menu exist at all under the
               rule that every page works with script off. admin-nav.js adds
               only the one thing <details> does not do by itself: closing when
               a click lands outside. Take the script away and it still opens
               and still signs out; it merely waits to be pressed again. */ ?>
      <details class="account" data-account>
<?php $initials = admin_initials($user); ?>
        <summary class="account__toggle" title="<?= h($user) ?>">
          <span class="account__avatar">
<?php if ($initials !== ''): ?>
            <span class="account__initials" aria-hidden="true"><?= h($initials) ?></span>
<?php else: ?>
            <?= admin_icon('user', 'icon') ?>
<?php endif; ?>
          </span>
          <span class="account__text">
            <span class="account__label"><?= h($user) ?></span>
            <span class="account__hint">Signed in</span>
          </span>
          <?= admin_icon('angle-right', 'icon account__caret') ?>
          <span class="visually-hidden">Account menu</span>
        </summary>

        <div class="account__menu">
          <div class="account__who">
            <span class="account__name"><?= h($user) ?></span>
            <span class="account__hint">Signed in</span>
          </div>

          <a class="account__item" href="<?= h(admin_url('account')) ?>">
            <?= admin_icon('user-shield', 'icon icon--sm') ?>
            <span>Your account</span>
          </a>

          <?php /* A form, not a link. A GET that ends a session can be fired
                   by any <img src> on any page the browser happens to load, so
                   signing out is a POST with a token like every other
                   action. */ ?>
          <form class="account__signout" method="post" action="<?= h(ADMIN_BASE) ?>logout.php">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
            <button class="account__item account__item--danger" type="submit">
              <?= admin_icon('lock', 'icon icon--sm') ?>
              <span>Sign out</span>
            </button>
          </form>
        </div>
      </details>
<?php endif; ?>
    </div>
  </aside>

  <?php /* EVERYTHING THAT CHANGES BETWEEN SCREENS, AND NOTHING THAT DOES NOT.

           admin-swap.js replaces this element's contents when a link in the
           rail is followed, so the rail itself — its width, its open account
           menu, its own scroll position — survives the move. The id is the
           whole of that contract: rename it here and navigation quietly goes
           back to being a full page load. */ ?>
  <div class="admin-shell__body" id="admin-body">
    <?php /* THE BAR IS TWO ROWS: WHAT THIS IS, AND WHAT YOU CAN DO TO IT.

             It was one row, and the line explaining what the section edits had
             been pushed down into the scrolling column to keep the bar short.
             That put the one sentence saying WHERE THE CHANGES GO — and the
             link to the live page they go to — below the fold on a phone and
             out of mind everywhere else. It belongs with the title.

             The cost is about twenty pixels, at --text-xs on its own row. The
             bar it replaced was 208px. */ ?>
    <header class="admin-bar">
      <div class="admin-bar__row">
        <?php /* The heading, and the two ways to look at what is being edited.

                 Icons alone, in the same round outlined control as the theme
                 toggle beside them. As labelled links they wrapped onto their
                 own line under a heading they are meant to sit beside, and two
                 underlined phrases next to an <h1> read as body text rather
                 than as controls. The name is still there for a screen reader,
                 and a hover reveals it for everyone else. */ ?>
        <div class="admin-bar__titles">
          <h1 class="admin-bar__title"><?= h($meta['label']) ?></h1>

          <div class="admin-bar__views">
<?php if ($meta['view'] !== ''): ?>
            <a class="btn btn--icon admin-bar__view" href="<?= h(public_url($meta['view'])) ?>"
               target="_blank" rel="noopener" title="View the page"
               aria-label="View the page — opens in a new tab">
              <?= admin_icon('eye', 'icon icon--sm') ?>
            </a>
<?php endif; ?>
            <a class="btn btn--icon admin-bar__view" href="<?= h(public_url('/')) ?>"
               target="_blank" rel="noopener" title="Open the site"
               aria-label="Open the site — opens in a new tab">
              <?= admin_icon('link', 'icon icon--sm') ?>
            </a>
          </div>
        </div>

        <?php /* SAVE LIVES HERE, AND THE FORM IS SEVERAL SCREENS BELOW.

                 It was a bar pinned across the foot of the editing column,
                 which cost 101px of every screen to hold one button — on a
                 page whose difficulty is that there is not enough room to see
                 the form. Up here it costs nothing: the bar was already
                 pinned, and the right end of it was holding a name that does
                 not change.

                 The button reaches its form with the `form` attribute, which
                 is plain HTML and needs no script: a submit button anywhere in
                 the document may name the form it belongs to. So this still
                 works with JavaScript off, and admin-forms.js does not have to
                 know that the button is not inside the form it submits. */ ?>
        <div class="admin-bar__actions">
<?php if ($save): ?>
<?php if (!empty($save['discard'])): ?>
          <a class="btn btn--ghost" href="<?= h($save['discard']) ?>">Discard</a>
<?php endif; ?>
          <?php /* Two labels, one shown. "Save the company profile" is 200px of
                   a 320px screen and the bar cannot wrap a single word, so at
                   that width the button says "Save" instead. display:none on
                   the other means a screen reader is offered one name, not
                   two. */ ?>
          <button class="btn btn--primary" type="submit" name="do" value="save"
                  form="<?= h($save['form']) ?>">
            <span class="admin-bar__save-long"><?= h($save['label']) ?></span>
            <span class="admin-bar__save-short"><?= h($save['short'] ?? 'Save') ?></span>
          </button>
<?php endif; ?>
          <button class="btn btn--icon" type="button" data-theme-toggle
                  aria-label="Switch to dark mode" aria-pressed="false">
            <?= admin_icon('moon', 'icon theme-toggle__icon--moon') ?>
            <?= admin_icon('sun', 'icon theme-toggle__icon--sun') ?>
          </button>
        </div>
      </div>

<?php if ($lede !== ''): ?>
      <?php /* Trusted markup, not text: every caller writes it as a literal in
               its own file and it carries the link to the page being edited.
               Nothing from a request reaches here. */ ?>
      <p class="admin-bar__lede"><?= $lede ?></p>
<?php endif; ?>
    </header>

    <?php /* tabindex="-1" so it can be FOCUSED without being TABBED TO. Two
             things land here: the skip link at the top of the document, which
             in Firefox and Safari moves the caret but not the focus without
             this, and admin-swap.js after it has swapped a screen in. Landing
             on <main> rather than on the heading is what makes the next Tab
             the first control of the new screen rather than wherever the rail
             had got to. */ ?>
    <main class="admin" id="admin-main" tabindex="-1">
      <div class="admin__inner<?= $outline ? ' admin__inner--outlined' : '' ?>">
        <div class="admin__col">
<?php
}

/** The notice strip: whatever the last redirect said, or what just failed. */
function admin_notices(array $errors): void
{
    $saved = trim((string)($_GET['saved'] ?? ''));

    admin_missing_record_notice();
    admin_publish_notice();

    if ($errors) {
        echo '<div class="admin__notice admin__notice--error"><p><strong>Not saved.</strong></p><ul>';
        foreach ($errors as $error) {
            echo '<li>' . h((string)$error) . '</li>';
        }
        echo '</ul></div>';
        return;
    }

    if ($saved !== '') {
        echo '<p class="admin__notice admin__notice--ok">' . h($saved) . '</p>';
    }
}

/**
 * Say so when this host has no record for the page being edited.
 *
 * THE FORM BELOW IS NOT THE WEBSITE. When content/<name>.json is absent,
 * <name>_load() falls back to the defaults in lib/contract.php, and those
 * defaults are a shape rather than a page: headings, no rows. The editor then
 * renders perfectly, every field works, and every one of them is empty --
 * which reads as a page nobody has filled in yet, not as a file that never
 * arrived.
 *
 * It reached production exactly that way. The company profile's seed line was
 * never added to tools/build_deploy_set.py, so the admin came up offering an
 * empty company form over a live page holding seventy-seven rows. The person
 * who found it was one press of Save away from publishing the empty one over
 * the real one, and nothing on the screen would have warned them.
 *
 * So this says it, on every editor, for every document, without any section
 * having to remember to ask. It does NOT disable Save: a genuinely fresh host
 * has to be able to start somewhere, and a control that refuses with no way
 * past it is how somebody ends up editing the JSON by hand.
 */
function admin_missing_record_notice(): void
{
    $section = admin_section();

    if (!in_array($section, CONTRACT_DOCUMENTS, true)
            || is_file(contract_path($section))) {
        return;
    }

    $meta = ADMIN_SECTIONS[$section] ?? [];
    ?>
<div class="admin__notice admin__notice--warn">
  <p><strong>This server has no saved copy of this page yet, so the form below
     is showing defaults — not what the website is currently showing.</strong></p>
<?php if (!empty($meta['view'])): ?>
  <p>
    Compare it with
    <a href="<?= h(public_url($meta['view'])) ?>" target="_blank" rel="noopener">the live page</a>
    before you change anything.
  </p>
<?php endif; ?>
  <p>
    <strong>Saving now would publish these defaults over it.</strong> If the
    live page has content this form does not, stop and say so — the record can
    be copied across without losing anything, and the next deploy seeds it
    automatically.
  </p>
  <p class="admin__hint">
    Expected at <code>content/<?= h($section) ?>.json</code> on this server.
    Nothing is broken and nothing has been lost; this host has simply never
    been given the file.
  </p>
</div>
    <?php
}

/**
 * Say so when the live site did not take the last save, and offer to send it
 * again.
 *
 * Shown ABOVE the ordinary "Saved" notice and not instead of it, because both
 * are true: the record here is written, and the public site does not have it.
 * Telling somebody only the first is how a gap goes uninvestigated — nothing
 * asked them to look.
 *
 * The retry is a form with a token, not a link. It writes to another host.
 */
function admin_publish_notice(): void
{
    $failed = $_SESSION['publish_failed'] ?? null;

    if (!is_array($failed)) {
        return;
    }

    unset($_SESSION['publish_failed']);

    $section = (string)($failed['section'] ?? '');
    ?>
<div class="admin__notice admin__notice--warn">
  <p><strong>Saved here, but the live site does not have it yet.</strong></p>
  <p><?= h((string)($failed['error'] ?? '')) ?></p>
<?php if (in_array($section, CONTRACT_DOCUMENTS, true)): ?>
  <form method="post" data-async action="<?= h(admin_url($section)) ?>">
    <?= admin_form_fields($section) ?>
    <input type="hidden" name="action" value="republish">
    <button class="btn btn--primary" type="submit">Publish again</button>
  </form>
<?php endif; ?>
  <p class="admin__hint">
    Nothing was lost. This record is the one that counts, and it can be sent
    again at any time — from here, or with
    <code>python3 tools/reconcile.py</code> on this server.
  </p>
</div>
    <?php
}

function admin_foot(string $note = ''): void
{
    $outline = admin_outline();
    ?>
<?php if ($note !== ''): ?>
          <footer class="admin__footer"><?= $note ?></footer>
<?php endif; ?>
        </div>
<?php if ($outline): ?>
        <?php /* WHAT THIS PAGE CONTAINS, LISTED WHERE IT CAN BE SEEN.

                 The company editor is a quarter of a megabyte of form: ten
                 bands, 282 rows, 448 fields, one under the next. All of it was
                 always there and none of it was visible, because the only way
                 to learn that a section existed was to scroll far enough to
                 reach it. Somebody reasonably concluded the data was missing.

                 It spent one release nested inside the rail, under the current
                 section. That is the wrong column: the rail is a list of
                 places to go, this is a map of where you already are, and
                 twelve extra lines in a menu of five made the menu look like
                 the thing that had gone wrong. It is the right-hand column
                 every documentation site puts it in now.

                 AFTER the editing column in the source, so tabbing into the
                 page reaches the fields rather than twelve anchors first. Grid
                 placement puts it on the right regardless; below the
                 breakpoint `order` lifts it above the form, where it becomes
                 one scrolling row of chips.

                 Plain in-page anchors, so it works with script off. */ ?>
        <nav class="outline" aria-labelledby="outline-title">
          <p class="outline__title" id="outline-title">On this page</p>
          <ul class="outline__list" role="list">
<?php foreach ($outline as $anchor => $label): ?>
            <li>
              <a class="outline__link" href="#<?= h((string)$anchor) ?>"><?= h((string)$label) ?></a>
            </li>
<?php endforeach; ?>
          </ul>
        </nav>
<?php endif; ?>
      </div>
    </main>
  </div>
</div>

<script src="<?= h(admin_asset('/assets/js/theme-toggle.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-nav.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/editor.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-outline.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-toast.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-dialog.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-swap.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-forms.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-init.js')) ?>" defer></script>
</body>
</html>
<?php
}

/* ------------------------------------------------------- signed-out pages */

/**
 * The page around signing in, asking for a reset code, or first-run setup.
 *
 * A separate shell from admin_head() because these have no rail: there is
 * nothing to navigate to until somebody is signed in, and offering a menu of
 * pages that all bounce back here is worse than offering none. It is also why
 * admin_head() cannot serve — it looks its section up in ADMIN_SECTIONS and
 * there is no section to be on.
 *
 * $title is the heading, and the <title>. $lede is one line under it.
 */
function admin_shell_head(string $title, string $lede = '', string $icon = 'user-lock'): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | Tech4TIME admin</title>
<link rel="icon" href="<?= h(admin_asset('/assets/images/favicon/favicon.ico')) ?>" sizes="any">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/base.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/theme.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/layout.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/components.css')) ?>">
<link rel="stylesheet" href="<?= h(admin_asset('/assets/css/admin.css')) ?>">
<script src="<?= h(admin_asset('/assets/js/theme-init.js')) ?>"></script>
</head>
<body class="page admin-page">
<?= admin_icons(ADMIN_ICONS) ?>

<main class="signin">
  <div class="signin__card">

    <div class="signin__top">
      <a class="signin__brand" href="<?= h(public_url('/')) ?>" aria-label="Tech4TIME — view the site">
        <picture class="signin__logo-wrap theme-swap--light">
          <source srcset="<?= h(admin_asset('/assets/images/logo/logo-light-180.webp')) ?>" type="image/webp">
          <img class="signin__logo" src="<?= h(admin_asset('/assets/images/logo/logo-light-180.png')) ?>"
               alt="Tech4TIME" width="180" height="64" decoding="async">
        </picture>
        <picture class="signin__logo-wrap theme-swap--dark">
          <source srcset="<?= h(admin_asset('/assets/images/logo/logo-dark-180.webp')) ?>" type="image/webp">
          <img class="signin__logo" src="<?= h(admin_asset('/assets/images/logo/logo-dark-180.png')) ?>"
               alt="Tech4TIME" width="180" height="64" loading="lazy" decoding="async">
        </picture>
      </a>
      <button class="btn btn--icon" type="button" data-theme-toggle
              aria-label="Switch to dark mode" aria-pressed="false">
        <?= admin_icon('moon', 'icon theme-toggle__icon--moon') ?>
        <?= admin_icon('sun', 'icon theme-toggle__icon--sun') ?>
      </button>
    </div>

    <div class="signin__head">
      <span class="signin__mark"><?= admin_icon($icon, 'icon') ?></span>
      <h1 class="signin__title"><?= h($title) ?></h1>
<?php if ($lede !== ''): ?>
      <p class="signin__lede"><?= h($lede) ?></p>
<?php endif; ?>
    </div>
<?php
}

/** Close the signed-out shell. $note is trusted markup, like admin_foot()'s. */
function admin_shell_foot(string $note = ''): void
{
    ?>
<?php if ($note !== ''): ?>
    <footer class="signin__foot"><?= $note ?></footer>
<?php endif; ?>
  </div>
</main>

<script src="<?= h(admin_asset('/assets/js/theme-toggle.js')) ?>" defer></script>
<script src="<?= h(admin_asset('/assets/js/admin-init.js')) ?>" defer></script>
</body>
</html>
<?php
}

/**
 * The error strip on a signed-out page.
 *
 * Separate from admin_notices() because these pages have no ?saved= flash and
 * because what goes wrong here is a sentence rather than a list of fields.
 */
function admin_shell_error(string $message): void
{
    if ($message === '') {
        return;
    }

    echo '<div class="admin__notice admin__notice--error signin__notice">'
       . '<p class="admin__notice-line">' . admin_icon('exclamation-circle', 'icon icon--sm')
       . '<span>' . h($message) . '</span></p>'
       . '</div>';
}

function admin_shell_note(string $message): void
{
    if ($message === '') {
        return;
    }

    echo '<div class="admin__notice admin__notice--ok signin__notice">'
       . '<p>' . admin_icon('check-circle', 'icon icon--sm') . ' ' . h($message) . '</p>'
       . '</div>';
}
