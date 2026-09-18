# Turning the admin on

**Applies to:** backend

The order on this page is the safety property. It exists so that the window in which somebody else
could create the first admin account never opens.

Do not improvise it.

---

## The problem it solves

`/setup.php` creates the first account. **Whoever creates the first account owns the website.**

Between the upload finishing and somebody getting round to setup, that page is reachable by anyone
who finds the URL — and the gap can be days. Being first is not a defence.

Two things close it, and using both costs nothing:

| | |
|---|---|
| **The setup key** | ships with the code, is created and destroyed by the code, and cannot be forgotten |
| **Directory Privacy** | a strong Apache-level lock, set by hand in cPanel, that no test can assert |

---

## The order

### 1. Deploy, and only then switch Directory Privacy on

**In that order, and this page said the opposite until 2026-09-18.** It read *"Deploy with Directory
Privacy on"*, which cannot work on this host: cPanel writes Directory Privacy's rules into the
`.htaccess` of the directory it protects, that directory is the backend's document root, and **this
repository ships `public/.htaccess` on every deploy**. So the deploy overwrites cPanel's file and
removes the password — silently, and in the middle of the one window this page exists to close. The
reasoning is in section 4 of `public/.htaccess`, which has always said to switch it off before the
next deploy; the two documents disagreed and this one was wrong.

So: **deploy first.** Then, if you want the extra lock for the minutes before the first account
exists, cPanel → Directory Privacy → the backend's document root → set a user and password. Switch
it **off again before the next deploy**, because the next deploy will remove it whether or not you
remember.

There is **no `/admin` directory on this host** — that was the monolith's layout, and the frontend's
`admin/` is the case ADR 0016 is about. What you protect here is the subdomain's document root,
`public/`.

> Directory Privacy protects **directories, not files**. There is no way to point it at `setup.php`
> alone; protecting the document root is the way to express it.
>
> **The setup key does not depend on any of this.** It is created and destroyed by the code, it
> survives a deploy, and it is what actually closes the window. Directory Privacy is the second
> lock, and it is the one that can quietly fall off.

### 2. Read the setup key off the server

```bash
cat ~/t4t-private-admin/setup-token.txt
```

Or open that file in cPanel's File Manager. It is created the first time `/setup.php` is
loaded, so load the page once if the file is not there yet.

Reading it requires SSH, Terminal or File Manager — the access whoever is setting this up has, and a
stranger does not.

### 3. Create the account

Open `https://admin.tech4time.bd/setup.php` and work through three screens.

**Details** — username, email, password.

- The email is where a reset code goes. It must be a mailbox **you can open**. Use
  `admin@tech4time.bd` if it exists; otherwise `info@tech4time.bd`.
- The password must be at least 12 characters and must not contain `password`, `12345678`,
  `tech4time`, `qwerty` or `admin`.
- Paste the setup key here.

**Enrol** — pair an authenticator app. Type the grouped key into Google Authenticator, Aegis,
1Password, Bitwarden — anything that does TOTP — and enter a code to prove it works.

> The account is not written until a code has verified. An admin enrolled but unable to produce a
> code is an admin locked out on the first sign-in, and this is the one moment that is still free to
> put right.

**Codes** — ten recovery codes, shown **once**.

### 4. Save the recovery codes properly

Not in a browser tab. Not in a screenshot on the machine you are using. A password manager, or paper
in a drawer.

They are your way back in when the phone is lost and email is down. They are hashed on the server;
nobody can show them to you again.

The setup key is deleted automatically the moment the account exists. `setup.php` refuses to run
from then on.

### 5. Prove signing in works

- [ ] Sign out
- [ ] Sign back in with the password and a code
- [ ] Change the password on the Account page
- [ ] Confirm that ended your other sessions

### 6. Prove recovery works — do not skip this

**This is the step that matters**, because it is the one you will need on the day you cannot sign
in, and the day you find out it does not work should not be that day.

- [ ] `/forgot.php` → enter the username
- [ ] The code arrives at the mailbox on the account
- [ ] Enter the code
- [ ] Choose a new password — the screen says it is not set yet, and it means it
- [ ] Enter an authenticator code; **this** is the step that sets the password
- [ ] Sign in with the new password

If the code never arrives, `mail()` is the problem, not the admin. Re-run `tools/host-probe.php`.

### 7. Remove Directory Privacy

**Only now.** cPanel → Directory Privacy → `the backend's document root` → unset.

Keep the old Directory Privacy credentials until step 6 has passed once.

---

## The checklist

```
[ ] Deployed FIRST, then Directory Privacy switched on (a deploy removes it)
[ ] Setup key read from ~/t4t-private-admin/setup-token.txt
[ ] Account created; setup key auto-deleted
[ ] Authenticator paired and proven
[ ] TEN RECOVERY CODES SAVED SOMEWHERE REAL
[ ] Signed out and back in
[ ] Password changed; other sessions ended
[ ] FULL PASSWORD RECOVERY PROVEN END TO END
[ ] Directory Privacy removed
```

---

## Afterwards

**Back up `secret.key`.** It is 65 bytes and it is the one file whose loss forces every password to
be reset and every recovery code to be reissued. A password manager is fine.

**Check the backup covers the home directory.** `t4t-private-admin` lives at `/home/USER/`, so a
`public_html`-only backup silently omits it — a backup that restores your whole site except the
ability to log into it. [backups.md](../30-operations/backups.md)

---

## If something goes wrong

| | |
|---|---|
| The setup key does not match | Whitespace. It is compared case- and punctuation-insensitively, so retype rather than paste if unsure. |
| `setup.php` redirects to `login.php` | An account already exists. If it is not yours, `php ~/admin-cli.php list` — and treat it as a compromise. |
| The admin refuses to load entirely | The private store is unreachable. The message names the path. [troubleshooting.md](../30-operations/troubleshooting.md) |
| The authenticator code is always wrong | The server's clock. `TOTP_DRIFT` allows one 30-second step either side; more drift than that needs the clock fixed. |
| Locked out during setup | `php ~/admin-cli.php unlock` |

Everything else: [secrets-recovery.md](../30-operations/secrets-recovery.md).
