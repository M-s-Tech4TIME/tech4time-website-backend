<?php
/**
 * Tech4TIME — creating the first account.
 *
 * Runs once, on a fresh install, and refuses ever after.
 *
 * THE WINDOW THIS PAGE OPENS, AND WHAT CLOSES IT
 * Between deploying the admin and creating an account, a page that creates the
 * administrator is reachable — and whoever creates it owns the website. Being
 * first is not a defence; the gap between an upload finishing and somebody
 * getting round to setting up can be days.
 *
 * So this asks for a value that exists only in the private directory on the
 * server's own disk, which no URL maps to. Reading it takes SSH, cPanel's
 * Terminal or its File Manager — the access that whoever is setting this up
 * has, and a stranger does not. The token is created on demand and destroyed
 * the moment an account exists, so the window is shut by the code rather than
 * by a step somebody has to remember.
 *
 * Skipped when the request comes from the machine itself, by peer address and
 * not by any header, so local development and the test suite are not made to
 * fetch a file they could simply have read.
 *
 * ORDER OF EVENTS
 * The account is not written until the authenticator app has been proven to
 * work. An admin enrolled but unable to produce a code is an admin locked out
 * on the first sign-in, and this is the one moment when that is still free to
 * put right.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';

admin_start_session();

$state = $_SESSION['setup'] ?? [];
$state = is_array($state) ? $state : [];

/* Done, and not mid-flow. Nothing more to do here, ever. */
if (auth_has_accounts() && ($state['stage'] ?? '') !== 'codes') {
    unset($_SESSION['setup']);
    header('Location: login.php');
    exit;
}

$need_token = !auth_is_loopback() && !auth_has_accounts();
$error      = '';
$stage      = (string)($state['stage'] ?? 'details');

/* Asking for the key is what creates it. The operator has to read the file
   before they can type its contents, so it must exist by the time the page
   that demands it has rendered — not first appear once they have already
   guessed wrong. Called for that side effect; the value is never shown here,
   because a page that displays the token proves nothing about who is reading
   it.

   auth_has_accounts() is in that condition because of what it costs to leave
   out. The redirect above lets one case through on purpose — stage 'codes',
   so the recovery codes can be shown after the account exists — and this line
   ran on that render too. auth_setup_done() had just deleted the token; this
   put it straight back, seconds later, and it then sat in the private store
   indefinitely. Found on the live host: setup-complete logged at 14:13:40 and
   setup-token.txt still there, mtime 14:13.

   The token was inert — with an account present and no 'codes' stage in the
   session, a stranger is redirected to login.php before it is ever compared —
   but the file's own header promises it is "destroyed the moment an account
   exists", and it was not. */
if ($need_token) {
    auth_setup_token();
}

/* ------------------------------------------------------------------ posted */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_check_csrf();

    $do = (string)($_POST['do'] ?? '');

    if ($do === 'details') {
        $token    = (string)($_POST['token'] ?? '');
        $user     = strtolower(trim((string)($_POST['user'] ?? '')));
        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $again    = (string)($_POST['password2'] ?? '');

        $problem = auth_password_problem($password);

        if ($need_token && !auth_setup_token_check($token)) {
            $error = 'That setup key does not match the one on the server.';
            auth_log('setup-token-failed');
        } elseif (!preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $user)) {
            $error = 'The username should be 3 to 32 characters: letters, digits, '
                   . 'dots, dashes or underscores.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'That does not look like an email address. It is where a reset '
                   . 'code would be sent, so it has to be one you can read.';
        } elseif ($problem !== '') {
            $error = $problem;
        } elseif (!hash_equals($password, $again)) {
            $error = 'The two passwords are not the same.';
        } else {
            /* Hashed here rather than carried in plain to the next step: the
               session is on disk, and there is no reason for it to hold a
               password when it can hold the same thing already hashed. */
            $_SESSION['setup'] = [
                'stage' => 'enrol',
                'user'  => $user,
                'name'  => $name !== '' ? $name : $user,
                'email' => $email,
                'hash'  => auth_password_hash($password),
                'totp'  => totp_secret(),
            ];

            header('Location: setup.php');
            exit;
        }
    }

    if ($do === 'enrol' && ($state['stage'] ?? '') === 'enrol') {
        $code = (string)($_POST['code'] ?? '');

        if (totp_verify((string)$state['totp'], $code) === null) {
            $error = 'That code is not right. Check the time on the phone if it '
                   . 'keeps failing — the codes are worked out from the clock.';
        } else {
            [$plain, $hashes] = auth_recovery_make();

            $ok = auth_put(auth_defaults([
                'user'             => $state['user'],
                'name'             => $state['name'],
                'email'            => $state['email'],
                'hash'             => $state['hash'],
                'totp'             => $state['totp'],
                'recovery'         => $hashes,
                'created'          => gmdate('c'),
                'password_changed' => gmdate('c'),
            ]));

            if (!$ok) {
                $error = 'The account could not be saved. Check that the private '
                       . 'directory is writable by PHP.';
            } else {
                auth_setup_done();
                auth_log('setup-complete', ['user' => $state['user']]);

                $_SESSION['setup'] = ['stage' => 'codes', 'codes' => $plain];

                header('Location: setup.php');
                exit;
            }
        }
    }

    if ($do === 'finish') {
        unset($_SESSION['setup']);
        header('Location: login.php');
        exit;
    }
}

$state = $_SESSION['setup'] ?? [];
$state = is_array($state) ? $state : [];
$stage = (string)($state['stage'] ?? 'details');

/* ----------------------------------------------------------------- the page */

if ($stage === 'enrol') {
    admin_shell_head(
        'Set up your authenticator',
        'Step 2 of 3 — pair the app that will supply your codes.',
        'mobile-alt'
    );
} elseif ($stage === 'codes') {
    admin_shell_head(
        'Save your recovery codes',
        'Step 3 of 3 — the way back in if you lose the phone.',
        'shield-alt'
    );
} else {
    admin_shell_head(
        'Set up the admin',
        'Step 1 of 3 — nobody can edit this website yet.',
        'user-shield'
    );
}

admin_shell_error($error);
?>

<?php if ($stage === 'enrol'): ?>

<?php $uri = totp_uri('Tech4TIME', (string)$state['user'] . '@tech4time.bd', (string)$state['totp']); ?>

<ol class="signin__steps">
  <li>Install an authenticator app if you have none — Google Authenticator,
      Authy, Microsoft Authenticator and 1Password all work.</li>
  <li><strong>Scan the code below</strong>, or add the account by entering the
      setup key by hand.</li>
  <li>Type the six digits it shows, to prove the pairing worked.</li>
</ol>

<div class="signin__pair">
  <div class="signin__qr">
    <?= totp_qr_svg('Tech4TIME', (string)$state['user'] . '@tech4time.bd', (string)$state['totp'], 'setup-key') ?>
  </div>

  <div class="signin__secret" id="setup-key">
    <p class="signin__secret-label">Or type the setup key</p>
    <p class="signin__secret-value"><?= h(totp_format((string)$state['totp'])) ?></p>
    <p class="admin__hint">
      Account name: <strong><?= h((string)$state['user']) ?>@tech4time.bd</strong>,
      issuer <strong>Tech4TIME</strong>, time-based, six digits.
    </p>
  </div>
</div>

<details class="admin__details signin__details">
  <summary class="admin__summary">Paste a link into the app instead</summary>
  <p class="admin__hint">Some apps take the whole thing at once:</p>
  <p class="signin__uri"><?= h($uri) ?></p>
</details>

<form class="signin__form" method="post" action="setup.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="enrol">

  <div class="admin__field">
    <label class="admin__label" for="code">The six digits it shows now</label>
    <input class="admin__input signin__code" id="code" name="code" type="text"
           inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"
           maxlength="8" required autofocus>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Confirm and create the account</button>
</form>

<?php elseif ($stage === 'codes'): ?>

<div class="admin__notice admin__notice--warn signin__notice">
  <p><strong>This is the only time these are shown.</strong></p>
  <p>Each one signs you in once, in place of the app. Print them, or put them
     in a password manager — somewhere that is not the phone holding the
     authenticator, since the point of them is that the phone is gone.</p>
</div>

<ul class="signin__codes" role="list">
<?php foreach ((array)($state['codes'] ?? []) as $code): ?>
  <li><?= h((string)$code) ?></li>
<?php endforeach; ?>
</ul>

<form class="signin__form" method="post" action="setup.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="finish">
  <button class="btn btn--primary btn--block" type="submit">
    I have saved them — go to sign in
  </button>
</form>

<?php else: ?>

<form class="signin__form" method="post" action="setup.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="details">

<?php if ($need_token): ?>
  <div class="admin__field">
    <label class="admin__label" for="token">Setup key from the server</label>
    <input class="admin__input" id="token" name="token" type="text"
           autocomplete="off" spellcheck="false" required autofocus>
    <p class="admin__hint">
      On the server, read it with<br>
      <code>cat ~/t4t-private-admin/setup-token.txt</code><br>
      over SSH, or open that file in cPanel's File Manager. It proves you are
      the person who runs this server rather than somebody who found this page.
    </p>
  </div>
<?php endif; ?>

  <div class="admin__field">
    <label class="admin__label" for="user">Username</label>
    <input class="admin__input" id="user" name="user" type="text"
           autocomplete="username" required <?= $need_token ? '' : 'autofocus' ?>
           value="<?= h((string)($_POST['user'] ?? '')) ?>">
    <p class="admin__hint">Lower case. This is what you will sign in with.</p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="name">Your name</label>
    <input class="admin__input" id="name" name="name" type="text"
           autocomplete="name" value="<?= h((string)($_POST['name'] ?? '')) ?>">
    <p class="admin__hint">Shown in the corner of the editor. Optional.</p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="email">Email address</label>
    <input class="admin__input" id="email" name="email" type="email"
           autocomplete="email" required
           value="<?= h((string)($_POST['email'] ?? 'admin@tech4time.bd')) ?>">
    <p class="admin__hint">
      Where a reset code is sent if you forget the password. It must be a mailbox
      you can actually open — check it exists in cPanel before relying on it.
    </p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="password">Password</label>
    <div class="admin__password">
      <input class="admin__input" id="password" name="password" type="password"
             autocomplete="new-password" required minlength="12">
      <?= admin_password_toggle('password') ?>
    </div>
    <p class="admin__hint">
      At least 12 characters. Three or four unrelated words beat one clever word.
    </p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="password2">Password again</label>
    <div class="admin__password">
      <input class="admin__input" id="password2" name="password2" type="password"
             autocomplete="new-password" required minlength="12">
      <?= admin_password_toggle('password2') ?>
    </div>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Continue</button>
</form>

<?php endif; ?>

<?php
admin_shell_foot(
    '<p>' . admin_icon('lock', 'icon icon--sm')
    . ' The password is stored as a salted argon2id hash, never as itself. '
    . 'This page stops working the moment an account exists.</p>'
);
