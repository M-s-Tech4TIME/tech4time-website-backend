<?php
/**
 * Tech4TIME — signing in.
 *
 * Two steps: the password, then the six digits from the authenticator app. They
 * are separate screens rather than one form because the password must not be
 * posted a second time to answer the second question — once it has been proven,
 * the only thing that needs to travel again is the code.
 *
 * WHAT THIS PAGE WILL NOT TELL YOU
 * Whether the username exists. A wrong name and a wrong password produce the
 * same words, in the same time (auth_attempt() hashes against a dummy when the
 * account is not there, so the two paths cost the same). Anything else turns
 * this into a way to find out which accounts are worth attacking.
 *
 * COUNTED, NOT JUST CHECKED
 * Failures are counted against the account and against the address, and past a
 * handful each further attempt waits longer than the last. The wait applies
 * BEFORE the password is verified, so a locked-out account cannot be used to
 * test whether a guess happened to be right.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';

admin_start_session();

/* Nothing to sign in to yet. */
if (!auth_has_accounts()) {
    header('Location: setup.php');
    exit;
}

$next = admin_safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ADMIN_BASE));

/* Already signed in — do not make somebody who pressed Back type it again. */
if (auth_session_user() !== null) {
    header('Location: ' . $next);
    exit;
}

const LOGIN_PENDING_TTL = 300;   // five minutes to answer the second question
const LOGIN_2FA_TRIES   = 5;

$error = '';
$note  = '';

if (isset($_GET['signed-out'])) {
    $note = 'You are signed out.';
}
if (isset($_GET['reset'])) {
    $note = 'Your password has been changed. Sign in with the new one.';
}
if (($_SESSION['ended'] ?? '') === 'idle') {
    $note = 'You were signed out after a spell of inactivity.';
    unset($_SESSION['ended']);
}
if (($_SESSION['ended'] ?? '') === 'superseded') {
    $note = 'Your password changed elsewhere, so this session was ended.';
    unset($_SESSION['ended']);
}

/* A half-finished sign-in lives in the session, never in a hidden field: the
   browser is holding "this password was accepted", which is not something a
   form post should be able to assert on its own. */
$pending = $_SESSION['pending'] ?? null;

if (!is_array($pending) || time() - (int)($pending['at'] ?? 0) > LOGIN_PENDING_TTL) {
    unset($_SESSION['pending']);
    $pending = null;
}

$stage = $pending === null ? 'password' : 'second';

/* ------------------------------------------------------------------ posted */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_check_csrf();

    $do = (string)($_POST['do'] ?? 'password');

    if ($do === 'restart') {
        unset($_SESSION['pending']);
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }

    if ($do === 'password') {
        /* A fresh password supersedes any half-finished attempt. Without this,
           a wrong password typed while an abandoned attempt is still in the
           session leaves the code form on screen — the error says the password
           was wrong and the page goes on asking for six digits. */
        unset($_SESSION['pending']);
        $pending = null;
        $stage   = 'password';

        $who      = trim((string)($_POST['user'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $by_user = throttle_key('login', $who);
        $by_ip   = throttle_key('login-ip', throttle_ip());

        $wait = max(throttle_retry_after($by_user), throttle_retry_after($by_ip));

        if ($wait > 0) {
            /* Refused before anything is verified, so this cannot be used to
               tell a right password from a wrong one while locked out. */
            $error = 'Too many attempts. Try again in ' . throttle_wait_text($wait) . '.';
            auth_log('login-throttled', ['who' => substr($who, 0, 60)]);
        } elseif ($who === '' || $password === '') {
            $error = 'Enter your username and password.';
        } else {
            $account = auth_attempt($who, $password);

            if ($account === null) {
                throttle_fail($by_user, AUTH_ALLOW);
                throttle_fail($by_ip, AUTH_ALLOW * 2);
                $error = 'That username and password do not match.';
                auth_log('login-failed', ['who' => substr($who, 0, 60)]);
            } elseif ($account['totp'] === '') {
                /* No second factor on the account. Let them in — they cannot
                   enrol one otherwise — and the account page insists on it. */
                throttle_clear($by_user);
                throttle_clear($by_ip);
                auth_login($account);
                header('Location: ' . ADMIN_BASE . '?s=account&enrol=1');
                exit;
            } else {
                $_SESSION['pending'] = ['user' => $account['user'], 'at' => time(), 'tries' => 0];
                $stage   = 'second';
                $pending = $_SESSION['pending'];
            }
        }
    }

    if ($do === 'second' && $pending !== null) {
        $code    = (string)($_POST['code'] ?? '');
        $account = auth_find((string)$pending['user']);

        $tries = (int)($pending['tries'] ?? 0) + 1;
        $_SESSION['pending']['tries'] = $tries;

        if ($account === null || $account['disabled']) {
            unset($_SESSION['pending']);
            $stage = 'password';
            $error = 'That username and password do not match.';
        } elseif ($tries > LOGIN_2FA_TRIES) {
            unset($_SESSION['pending']);
            $stage = 'password';
            $error = 'Too many wrong codes. Start again.';
            throttle_fail(throttle_key('login', $account['user']), AUTH_ALLOW);
            auth_log('second-factor-exhausted', ['user' => $account['user']]);
        } elseif (auth_second_factor($account, $code)) {
            unset($_SESSION['pending']);
            throttle_clear(throttle_key('login', $account['user']));
            throttle_clear(throttle_key('login-ip', throttle_ip()));
            auth_login($account);
            header('Location: ' . $next);
            exit;
        } else {
            $left  = LOGIN_2FA_TRIES - $tries;
            $error = $left > 0
                ? 'That code is not right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'That code is not right.';
            auth_log('second-factor-failed', ['user' => $account['user']]);
        }
    }
}

/* ----------------------------------------------------------------- the page */

if ($stage === 'second') {
    admin_shell_head(
        'Two-step check',
        'Enter the six digits from your authenticator app.',
        'mobile-alt'
    );
} else {
    admin_shell_head('Sign in', 'The Tech4TIME website editor.', 'user-lock');
}

admin_shell_error($error);
admin_shell_note($error === '' ? $note : '');
?>

<?php if ($stage === 'second'): ?>

<form class="signin__form" method="post" action="login.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <input type="hidden" name="do" value="second">

  <div class="admin__field">
    <label class="admin__label" for="code">Six-digit code</label>
    <?php /* inputmode/autocomplete let a phone show a number pad and let a
             password manager fill the code without being asked twice. */ ?>
    <input class="admin__input signin__code" id="code" name="code" type="text"
           inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"
           maxlength="14" required autofocus>
    <p class="admin__hint">
      Lost the phone? Type one of your recovery codes here instead.
    </p>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Sign in</button>
</form>

<form class="signin__restart" method="post" action="login.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <button class="signin__link" type="submit" name="do" value="restart">
    Start again
  </button>
</form>

<?php else: ?>

<form class="signin__form" method="post" action="login.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <input type="hidden" name="do" value="password">

  <div class="admin__field">
    <label class="admin__label" for="user">Username or email</label>
    <input class="admin__input" id="user" name="user" type="text"
           autocomplete="username" required autofocus
           value="<?= h((string)($_POST['user'] ?? '')) ?>">
  </div>

  <div class="admin__field">
    <label class="admin__label" for="password">Password</label>
    <div class="admin__password">
      <input class="admin__input" id="password" name="password" type="password"
             autocomplete="current-password" required>
      <?= admin_password_toggle('password') ?>
    </div>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Continue</button>
</form>

<p class="signin__aside">
  <a href="forgot.php">I have forgotten my password</a>
</p>

<?php endif; ?>

<?php
admin_shell_foot(
    '<p>' . admin_icon('lock', 'icon icon--sm')
    . ' This page is for the people who run tech4time.bd. '
    . 'Every attempt to sign in is recorded.</p>'
);
