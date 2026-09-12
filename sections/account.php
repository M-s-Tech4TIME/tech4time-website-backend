<?php
/**
 * Tech4TIME — your own account.
 *
 * The password, the authenticator app, the recovery codes, and a look at what
 * has been happening at the sign-in page.
 *
 * Included by admin/index.php, which has already checked that somebody is
 * signed in and started the session. $account is the whole record; $user is the
 * name to print.
 *
 * WHY EVERY CHANGE HERE ASKS FOR THE PASSWORD AGAIN
 * A signed-in browser left alone for a minute is the oldest way in there is.
 * Without re-asking, walking past an unlocked screen would be enough to move
 * the authenticator to a new phone and take the account for good. The token
 * covers the request being deliberate; the password covers it being you.
 */

declare(strict_types=1);

if (!defined('T4T_ADMIN')) {
    http_response_code(403);
    exit('Not a page.');
}

require_once __DIR__ . '/../lib/mailer.php';

/* Things shown exactly once — a new setup key mid-enrolment, or a fresh set of
   recovery codes. Kept in the session so a reload does not lose them and the
   back button does not repost anything to get them. */
$shown = $_SESSION['account_shown'] ?? [];
$shown = is_array($shown) ? $shown : [];

$saved = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    $do      = (string)($_POST['do'] ?? '');
    $current = (string)($_POST['current'] ?? '');

    /* One gate for all of them, and it is counted: this form is behind the
       sign-in, but a password prompt that can be guessed at without limit is
       not a password prompt. */
    $gate = throttle_key('reauth', $account['user']);
    $wait = throttle_retry_after($gate);

    if ($wait > 0) {
        $errors[] = 'Too many wrong passwords. Try again in ' . throttle_wait_text($wait) . '.';
    } elseif (!auth_password_verify($current, $account['hash'])) {
        throttle_fail($gate, AUTH_ALLOW);
        $errors[] = 'That is not your current password.';
        auth_log('reauth-failed', ['user' => $account['user']]);
    } else {
        throttle_clear($gate);

        if ($do === 'password') {
            $new   = (string)($_POST['password'] ?? '');
            $again = (string)($_POST['password2'] ?? '');
            $bad   = auth_password_problem($new);

            if ($bad !== '') {
                $errors[] = $bad;
            } elseif (!hash_equals($new, $again)) {
                $errors[] = 'The two new passwords are not the same.';
            } elseif (hash_equals($new, $current)) {
                $errors[] = 'That is the password you already have.';
            } else {
                $account['hash']             = auth_password_hash($new);
                $account['password_changed'] = gmdate('c');
                auth_invalidate_sessions($account);

                if (auth_put($account)) {
                    /* Every other session is now stale. This one is carried
                       forward deliberately — signing somebody out of the page
                       they just used to change their password is a puzzle, not
                       a protection. */
                    $_SESSION['auth']['ver'] = (int)$account['token_version'];
                    auth_log('password-changed', ['user' => $account['user']]);

                    mail_send(
                        $account['email'],
                        'Your Tech4TIME admin password was changed',
                        "The password for \"{$account['user']}\" was changed from the\n"
                        . "account page, and every other signed-in session was ended.\n\n"
                        . 'When: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
                        . 'From: ' . throttle_ip() . "\n\n"
                        . "If this was not you, reset the password now.\n"
                    );

                    admin_redirect('account', 'Password changed. Other devices have been signed out.');
                }

                $errors[] = 'The new password could not be saved.';
            }
        }

        if ($do === 'totp-begin') {
            $_SESSION['account_shown'] = ['totp' => totp_secret()];
            admin_redirect('account', '');
        }

        if ($do === 'totp-confirm') {
            $secret = (string)($shown['totp'] ?? '');
            $code   = (string)($_POST['code'] ?? '');

            if ($secret === '') {
                $errors[] = 'That setup key has expired. Start again.';
            } elseif (totp_verify($secret, $code) === null) {
                $errors[] = 'That code is not right. If it keeps failing, check the '
                          . 'clock on the phone — the codes are worked out from it.';
            } else {
                $account['totp']      = $secret;
                $account['totp_last'] = 0;

                if (auth_put($account)) {
                    unset($_SESSION['account_shown']);
                    auth_log('totp-enrolled', ['user' => $account['user']]);
                    admin_redirect('account', 'Authenticator app paired.');
                }

                $errors[] = 'It could not be saved.';
            }
        }

        if ($do === 'recovery') {
            [$plain, $hashes] = auth_recovery_make();
            $account['recovery'] = $hashes;

            if (auth_put($account)) {
                $_SESSION['account_shown'] = ['codes' => $plain];
                auth_log('recovery-codes-issued', ['user' => $account['user']]);
                admin_redirect('account', 'New recovery codes. The old ones no longer work.');
            }

            $errors[] = 'They could not be saved.';
        }

        if ($do === 'sign-out-others') {
            auth_invalidate_sessions($account);

            if (auth_put($account)) {
                $_SESSION['auth']['ver'] = (int)$account['token_version'];
                auth_log('sessions-ended', ['user' => $account['user']]);
                admin_redirect('account', 'Every other device has been signed out.');
            }

            $errors[] = 'It could not be saved.';
        }
    }
}

/* Re-read: a failed POST above may have changed nothing, but a redirect that
   did not happen leaves $account as it was loaded. */
$shown = is_array($_SESSION['account_shown'] ?? null) ? $_SESSION['account_shown'] : [];

$enrolled  = $account['totp'] !== '';
$codes_left = count((array)$account['recovery']);
$nudge     = isset($_GET['enrol']) || !$enrolled;

/** Machine event names, in words. */
function account_event(string $event): string
{
    return [
        'login'                   => 'Signed in',
        'logout'                  => 'Signed out',
        'login-failed'            => 'Wrong password',
        'login-throttled'         => 'Blocked — too many attempts',
        'second-factor-failed'    => 'Wrong authenticator code',
        'second-factor-exhausted' => 'Too many wrong codes',
        'recovery-code-used'      => 'Recovery code used',
        'recovery-codes-issued'   => 'New recovery codes made',
        'totp-enrolled'           => 'Authenticator app paired',
        'password-changed'        => 'Password changed',
        'password-reset'          => 'Password reset by email code',
        'reset-code-sent'         => 'Reset code emailed',
        'reset-code-failed'       => 'Wrong reset code',
        'reset-request-unknown'   => 'Reset asked for an unknown account',
        'reset-request-throttled' => 'Reset asked for too often',
        'reset-code-failed-send'  => 'Reset code could not be emailed',
        'reauth-failed'           => 'Wrong password on the account page',
        'sessions-ended'          => 'All other devices signed out',
        'setup-complete'          => 'Account created',
        'setup-token-failed'      => 'Wrong setup key',
    ][$event] ?? $event;
}

/** "3 minutes ago", from an ISO timestamp. */
function account_when(string $iso): string
{
    $then = strtotime($iso);

    if ($then === false) {
        return '';
    }

    $ago = max(0, time() - $then);

    if ($ago < 90) {
        return 'just now';
    }
    if ($ago < 3600) {
        return (int)round($ago / 60) . ' minutes ago';
    }
    if ($ago < 172800) {
        return (int)round($ago / 3600) . ' hours ago';
    }

    return (int)round($ago / 86400) . ' days ago';
}

admin_head(
    'account',
    $user,
    'Signed in as <strong>' . h($account['user']) . '</strong>. '
    . 'Changing anything here asks for your password again.'
);

admin_notices($errors);
?>

<?php if ($nudge && !$enrolled): ?>
<div class="admin__notice admin__notice--warn">
  <p class="admin__notice-line"><?= admin_icon('exclamation-circle', 'icon icon--sm') ?>
     <strong>There is no second factor on this account.</strong></p>
  <p>A password on its own is one guessed, phished or reused password away from
     somebody else editing your website. Pairing an authenticator app takes a
     minute and is the single biggest difference you can make here.</p>
</div>
<?php endif; ?>

<?php if (isset($shown['codes'])): ?>
<div class="admin__notice admin__notice--warn">
  <p><strong>Your new recovery codes — shown only now.</strong></p>
  <p>Each signs you in once, in place of the app. Keep them somewhere that is
     not the phone holding the authenticator.</p>
  <ul class="signin__codes" role="list">
<?php foreach ((array)$shown['codes'] as $code): ?>
    <li><?= h((string)$code) ?></li>
<?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="admin__grid admin__grid--account">

  <?php /* ---------------------------------------------------- password */ ?>
  <section class="admin__block">
    <h2 class="admin__section-title"><?= admin_icon('lock', 'icon icon--sm') ?> Password</h2>
    <p class="admin__blurb">
      Stored as a salted argon2id hash, never as itself — nothing here, on the
      server or in a backup, can be read back into your password.
<?php if ($account['password_changed'] !== ''): ?>
      Last changed <?= h(account_when($account['password_changed'])) ?>.
<?php endif; ?>
    </p>

    <form class="admin__form admin__form--stack" data-async method="post" action="<?= h(admin_url('account')) ?>">
      <?= admin_form_fields('account') ?>
      <input type="hidden" name="do" value="password">

      <div class="admin__field">
        <label class="admin__label" for="pw-current">Your current password</label>
        <div class="admin__password">
          <input class="admin__input" id="pw-current" name="current" type="password"
                 autocomplete="current-password" required>
          <?= admin_password_toggle('pw-current') ?>
        </div>
      </div>

      <div class="admin__field">
        <label class="admin__label" for="pw-new">New password</label>
        <div class="admin__password">
          <input class="admin__input" id="pw-new" name="password" type="password"
                 autocomplete="new-password" required minlength="12">
          <?= admin_password_toggle('pw-new') ?>
        </div>
        <p class="admin__hint">At least 12 characters.</p>
      </div>

      <div class="admin__field">
        <label class="admin__label" for="pw-again">New password again</label>
        <div class="admin__password">
          <input class="admin__input" id="pw-again" name="password2" type="password"
                 autocomplete="new-password" required minlength="12">
          <?= admin_password_toggle('pw-again') ?>
        </div>
      </div>

      <div class="admin__actions">
        <button class="btn btn--primary" type="submit">Change the password</button>
      </div>
    </form>
  </section>

  <?php /* ------------------------------------------------ authenticator */ ?>
  <section class="admin__block">
    <h2 class="admin__section-title">
      <?= admin_icon('mobile-alt', 'icon icon--sm') ?> Authenticator app
    </h2>

<?php if (isset($shown['totp'])): ?>

    <p class="admin__blurb">
      Scan this with your app, or add the key by hand, then type what it shows.
      Nothing changes until you do — the app you have now goes on working until
      this is confirmed.
    </p>

    <div class="signin__pair">
      <div class="signin__qr">
        <?= totp_qr_svg('Tech4TIME', $account['user'] . '@tech4time.bd', (string)$shown['totp'], 'new-setup-key') ?>
      </div>

      <div class="signin__secret" id="new-setup-key">
        <p class="signin__secret-label">Or type the setup key</p>
        <p class="signin__secret-value"><?= h(totp_format((string)$shown['totp'])) ?></p>
      </div>
    </div>

    <details class="admin__details">
      <summary class="admin__summary">Paste a link into the app instead</summary>
      <p class="signin__uri"><?= h(totp_uri('Tech4TIME', $account['user'] . '@tech4time.bd', (string)$shown['totp'])) ?></p>
    </details>

    <form class="admin__form admin__form--stack" data-async method="post" action="<?= h(admin_url('account')) ?>">
      <?= admin_form_fields('account') ?>
      <input type="hidden" name="do" value="totp-confirm">

      <div class="admin__field">
        <label class="admin__label" for="totp-code">The six digits it shows</label>
        <input class="admin__input signin__code" id="totp-code" name="code" type="text"
               inputmode="numeric" autocomplete="one-time-code" maxlength="8" required>
      </div>

      <div class="admin__field">
        <label class="admin__label" for="totp-current">Your password</label>
        <div class="admin__password">
          <input class="admin__input" id="totp-current" name="current" type="password"
                 autocomplete="current-password" required>
          <?= admin_password_toggle('totp-current') ?>
        </div>
      </div>

      <div class="admin__actions">
        <button class="btn btn--primary" type="submit">Confirm</button>
      </div>
    </form>

<?php else: ?>

    <p class="admin__blurb">
<?php if ($enrolled): ?>
      <?= admin_icon('check-circle', 'icon icon--sm') ?>
      Paired. Signing in asks for six digits from your app after the password.
      Pair a new phone below — the old one stops working the moment you confirm.
<?php else: ?>
      Not set up. Signing in asks only for your password.
<?php endif; ?>
    </p>

    <form class="admin__form admin__form--stack" data-async method="post" action="<?= h(admin_url('account')) ?>">
      <?= admin_form_fields('account') ?>
      <input type="hidden" name="do" value="totp-begin">

      <div class="admin__field">
        <label class="admin__label" for="totp-begin-pw">Your password</label>
        <div class="admin__password">
          <input class="admin__input" id="totp-begin-pw" name="current" type="password"
                 autocomplete="current-password" required>
          <?= admin_password_toggle('totp-begin-pw') ?>
        </div>
      </div>

      <div class="admin__actions">
        <button class="btn <?= $enrolled ? 'btn--secondary' : 'btn--primary' ?>" type="submit">
          <?= $enrolled ? 'Pair a different phone' : 'Set up an authenticator app' ?>
        </button>
      </div>
    </form>

<?php endif; ?>
  </section>

  <?php /* --------------------------------------------------- recovery */ ?>
  <section class="admin__block">
    <h2 class="admin__section-title">
      <?= admin_icon('shield-alt', 'icon icon--sm') ?> Recovery codes
    </h2>
    <p class="admin__blurb">
<?php if ($codes_left > 0): ?>
      <strong><?= (int)$codes_left ?></strong> unused
      <?= $codes_left === 1 ? 'code' : 'codes' ?> left. Each one signs you in
      once when the phone is not to hand.
<?php if ($codes_left <= 3): ?>
      That is getting low — worth making a new set.
<?php endif; ?>
<?php else: ?>
      None left. If you lose the phone with the authenticator on it, the only
      way back in is a password reset by email, and that asks for a code from
      the app as well. Make a set now.
<?php endif; ?>
    </p>

    <form class="admin__form admin__form--stack" data-async method="post" action="<?= h(admin_url('account')) ?>">
      <?= admin_form_fields('account') ?>
      <input type="hidden" name="do" value="recovery">

      <div class="admin__field">
        <label class="admin__label" for="rec-current">Your password</label>
        <div class="admin__password">
          <input class="admin__input" id="rec-current" name="current" type="password"
                 autocomplete="current-password" required>
          <?= admin_password_toggle('rec-current') ?>
        </div>
      </div>

      <div class="admin__actions">
        <button class="btn btn--secondary" type="submit">
          Make <?= $codes_left > 0 ? 'a new set' : 'a set' ?>
        </button>
      </div>
      <p class="admin__hint">Making a new set cancels every old code at once.</p>
    </form>
  </section>

  <?php /* --------------------------------------------------- sessions */ ?>
  <section class="admin__block">
    <h2 class="admin__section-title">
      <?= admin_icon('user-lock', 'icon icon--sm') ?> Signed-in devices
    </h2>
    <p class="admin__blurb">
      A session lasts <?= (int)(AUTH_IDLE / 60) ?> minutes of inactivity, and
      <?= (int)(AUTH_ABSOLUTE / 3600) ?> hours at the outside. If you have signed
      in somewhere you no longer trust — a borrowed laptop, a phone you have
      sold — end them all here. You will stay signed in on this one.
    </p>

    <form class="admin__form admin__form--stack" data-async method="post" action="<?= h(admin_url('account')) ?>">
      <?= admin_form_fields('account') ?>
      <input type="hidden" name="do" value="sign-out-others">

      <div class="admin__field">
        <label class="admin__label" for="so-current">Your password</label>
        <div class="admin__password">
          <input class="admin__input" id="so-current" name="current" type="password"
                 autocomplete="current-password" required>
          <?= admin_password_toggle('so-current') ?>
        </div>
      </div>

      <div class="admin__actions">
        <button class="btn btn--secondary" type="submit">Sign out every other device</button>
      </div>
    </form>
  </section>

</div>

<?php /* ------------------------------------------------------- the log */ ?>
<section class="admin__block">
  <h2 class="admin__section-title">
    <?= admin_icon('clock', 'icon icon--sm') ?> Recent activity
  </h2>
  <p class="admin__blurb">
    Every attempt to sign in, whether it worked or not. Failures you do not
    recognise are worth noticing: a handful means somebody is guessing.
  </p>

<?php $log = auth_recent(15); ?>
<?php if ($log === []): ?>
  <p class="admin__empty">Nothing recorded yet.</p>
<?php else: ?>
  <ul class="admin__list admin__log" role="list">
<?php foreach ($log as $row): ?>
    <li class="admin__log-row">
      <span class="admin__log-event"><?= h(account_event((string)($row['event'] ?? ''))) ?></span>
      <span class="admin__log-meta">
        <?= h(account_when((string)($row['at'] ?? ''))) ?>
        &middot; <?= h((string)($row['ip'] ?? 'unknown')) ?>
<?php if (($row['user'] ?? '') !== ''): ?>
        &middot; <?= h((string)$row['user']) ?>
<?php endif; ?>
      </span>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<?php
admin_foot(
    '<p>Passwords are hashed with argon2id and a secret held in a file outside '
    . 'the website, so a copy of the accounts file on its own cannot be attacked '
    . 'offline. Codes come from your app, not from us — nothing is sent when you '
    . 'sign in.</p>'
);
