<?php
/**
 * Tech4TIME — using a reset code.
 *
 * Three steps, in this order and no other:
 *
 *   1. the six digits emailed to the address on the account
 *   2. the new password, twice
 *   3. the authenticator app — and only now does the password take effect
 *
 * Step three is why a breached mailbox does not cost you the website. If the
 * emailed code were sufficient on its own, whoever reads that mailbox would
 * hold the admin password, and the second factor would be protecting nothing at
 * the one moment it matters most. A recovery code is accepted in the app's
 * place, for a phone that has been lost.
 *
 * WHY THE PASSWORD IS CHOSEN BEFORE THE LAST CHECK, NOT BESIDE IT
 * These were one screen once: an authenticator field sitting above two password
 * boxes. Nothing about what is required changed when they were split — both are
 * still demanded, and the write still happens only after both. What changed is
 * what the screens can say. On the combined form the second factor read as a
 * third thing to fill in, the form either took or it did not, and which of the
 * three fields was wrong arrived as a sentence at the top. Split, the last
 * screen asks one question, and answering it IS the moment the password
 * changes. That is the truth about this flow, so it is what the flow should
 * look like.
 *
 * A cost comes with it, and it is paid in the right currency: the chosen
 * password has to survive from step two to step three, and it survives as an
 * argon2id HASH, computed the instant it is accepted. Nothing reversible is
 * ever at rest. See reset_finish(), which takes the hash for that reason.
 *
 * Between the steps, what has been proven lives in the session — never in a
 * hidden field. A form post should not be able to assert "the emailed code was
 * accepted" on its own say-so, and it certainly should not be the thing
 * carrying the new password back and forth through the browser.
 *
 * The last step is COUNTED, not merely checked. Five wrong codes tear the whole
 * reset up, emailed code and chosen password together. Without that, arriving
 * at step three with a stolen mailbox would buy unlimited guesses at six digits
 * behind a screen that no longer asks for anything else.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';
require __DIR__ . '/../lib/reset.php';

admin_start_session();

if (!auth_has_accounts()) {
    header('Location: setup.php');
    exit;
}

/** How long the whole of steps two and three may take, from the emailed code. */
const RESET_FINISH_TTL = 900;

/** Guesses at the authenticator before the reset is torn up. */
const RESET_2FA_TRIES = 5;

$error = '';
$note  = '';

if (isset($_GET['sent'])) {
    $note = 'If that account exists, a code is on its way. It lasts ten minutes.';
}

/* What has been proven so far, if anything:
     ['user' => ..., 'at' => ..., 'hash' => ?, 'tries' => ?]
   'at' is the moment the emailed code was accepted and is NOT refreshed by
   step two. The window covers the whole rest of the reset, deliberately —
   otherwise sitting on the password screen would extend it indefinitely. */
$proven = $_SESSION['reset'] ?? null;

if (!is_array($proven) || time() - (int)($proven['at'] ?? 0) > RESET_FINISH_TTL) {
    unset($_SESSION['reset']);
    $proven = null;
}

/* ------------------------------------------------------------------ posted */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_check_csrf();

    $do = (string)($_POST['do'] ?? '');

    if ($do === 'code') {
        $account = reset_verify((string)($_POST['code'] ?? ''));

        if ($account === null) {
            $left  = reset_tries_left();
            $error = $left > 0
                ? 'That code is not right, or it has expired. '
                  . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'That code is not right, or it has expired. Ask for a new one.';
            auth_log('reset-code-failed');
        } else {
            /* Regenerate: the browser is about to hold "this person proved a
               reset code", which is authority it did not have a moment ago. */
            session_regenerate_id(true);

            $_SESSION['reset'] = ['user' => $account['user'], 'at' => time()];
            reset_forget();

            header('Location: reset.php');
            exit;
        }
    }

    if ($do === 'password' && $proven !== null && ($proven['hash'] ?? '') === '') {
        $account  = auth_find((string)$proven['user']);
        $password = (string)($_POST['password'] ?? '');
        $again    = (string)($_POST['password2'] ?? '');

        $problem = auth_password_problem($password);

        if ($account === null || $account['disabled']) {
            unset($_SESSION['reset']);
            $error = 'That account is no longer available.';
        } elseif ($problem !== '') {
            $error = $problem;
        } elseif (!hash_equals($password, $again)) {
            $error = 'The two passwords are not the same.';
        } else {
            /* Hashed here, at the moment it is accepted, so that what waits for
               step three is one-way. A session file read off the disk inside
               the window yields a digest, not a password that would also open
               this person's email. */
            $_SESSION['reset']['hash']  = auth_password_hash($password);
            $_SESSION['reset']['tries'] = 0;

            header('Location: reset.php');
            exit;
        }
    }

    /* Back from step three to step two. A change of state, so it is a post
       with a token, not a link. */
    if ($do === 'again' && $proven !== null) {
        unset($_SESSION['reset']['hash'], $_SESSION['reset']['tries']);
        header('Location: reset.php');
        exit;
    }

    if ($do === 'second' && $proven !== null && ($proven['hash'] ?? '') !== '') {
        $account = auth_find((string)$proven['user']);
        $second  = (string)($_POST['second'] ?? '');

        $tries = (int)($proven['tries'] ?? 0) + 1;
        $_SESSION['reset']['tries'] = $tries;

        if ($account === null || $account['disabled']) {
            unset($_SESSION['reset']);
            $error = 'That account is no longer available.';
        } elseif ($tries > RESET_2FA_TRIES) {
            /* The whole reset, not just this step. The emailed code was spent
               when it was accepted, so starting again means asking for a new
               one — which is rationed, and that is the point. */
            unset($_SESSION['reset']);
            $error = 'Too many wrong codes. Ask for a new reset code and start again.';
            auth_log('reset-second-factor-exhausted', ['user' => $account['user']]);
        } elseif (!auth_second_factor($account, $second)) {
            $left  = RESET_2FA_TRIES - $tries;
            $error = $left > 0
                ? 'That authenticator code is not right. '
                  . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'That authenticator code is not right.';
            auth_log('reset-second-factor-failed', ['user' => $account['user']]);
        } elseif (!reset_finish($account, (string)$proven['hash'])) {
            $error = 'The new password could not be saved. Check that the private '
                   . 'directory is writable and try again.';
        } else {
            unset($_SESSION['reset']);
            session_regenerate_id(true);
            header('Location: login.php?reset=1');
            exit;
        }
    }
}

/* ----------------------------------------------------------------- the page */

/* Re-read: a post may have moved the flow on, or thrown it back to the start.
   The session is the only thing that decides which question is being asked. */
$proven = $_SESSION['reset'] ?? null;

$stage = $proven === null
    ? 'code'
    : ((($proven['hash'] ?? '') === '') ? 'password' : 'second');

if ($stage === 'second') {
    admin_shell_head(
        'Confirm with your app',
        'Step 3 of 3 — this is what sets the new password.',
        'user-shield'
    );
} elseif ($stage === 'password') {
    admin_shell_head(
        'Choose a new password',
        'Step 2 of 3 — pick it now; it is not set until step 3.',
        'lock'
    );
} else {
    admin_shell_head(
        'Enter your code',
        'Step 1 of 3 — the six digits we emailed you.',
        'envelope'
    );
}

admin_shell_error($error);
admin_shell_note($error === '' ? $note : '');
?>

<?php if ($stage === 'second'): ?>

<form class="signin__form" method="post" action="reset.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="second">

  <div class="admin__field">
    <label class="admin__label" for="second">Authenticator code</label>
    <input class="admin__input signin__code" id="second" name="second" type="text"
           inputmode="numeric" autocomplete="one-time-code" maxlength="14"
           required autofocus>
    <p class="admin__hint">
      Six digits from your app, or one of your recovery codes.
    </p>
  </div>

  <button class="btn btn--primary btn--block" type="submit">
    Set the new password
  </button>
</form>

<form class="signin__restart" method="post" action="reset.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <button class="signin__link" type="submit" name="do" value="again">
    Choose a different password
  </button>
</form>

<p class="signin__aside">
  Your password has not changed yet. It changes when this code is accepted, and
  that signs out every device currently signed in.
</p>

<?php elseif ($stage === 'password'): ?>

<form class="signin__form" method="post" action="reset.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="password">

  <div class="admin__field">
    <label class="admin__label" for="password">New password</label>
    <div class="admin__password">
      <input class="admin__input" id="password" name="password" type="password"
             autocomplete="new-password" required minlength="12" autofocus>
      <?= admin_password_toggle('password') ?>
    </div>
    <p class="admin__hint">
      At least 12 characters. Three or four unrelated words beat one clever word.
    </p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="password2">New password again</label>
    <div class="admin__password">
      <input class="admin__input" id="password2" name="password2" type="password"
             autocomplete="new-password" required minlength="12">
      <?= admin_password_toggle('password2') ?>
    </div>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Continue</button>
</form>

<p class="signin__aside">
  One more step after this: your authenticator app. Nothing is saved until then.
</p>

<?php else: ?>

<form class="signin__form" method="post" action="reset.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="code">

  <div class="admin__field">
    <label class="admin__label" for="code">Six-digit code</label>
    <input class="admin__input signin__code" id="code" name="code" type="text"
           inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"
           maxlength="8" required autofocus>
    <p class="admin__hint">
      Use the same browser you asked from — the code is tied to it.
    </p>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Continue</button>
</form>

<p class="signin__aside">
  <a href="forgot.php">Ask for another code</a> &middot;
  <a href="login.php">Back to signing in</a>
</p>

<?php endif; ?>

<?php
admin_shell_foot(
    '<p>' . admin_icon('info-circle', 'icon icon--sm')
    . ' Each code works once and lasts ten minutes.</p>'
);
