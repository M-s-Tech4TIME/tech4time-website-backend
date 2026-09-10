# Troubleshooting

**Applies to:** both

Indexed by what you actually see, not by what is actually wrong.

Cannot sign in to the admin? → [secrets-recovery.md](secrets-recovery.md).

---

## Content saved here has not appeared on the public site

This is the publish path, and it fails in a way that is meant to be visible: the editor says so, in
words, with a **Publish again** control. If nobody saw that, start here.

### The editor named a reason

The ones that mean something is genuinely wrong:

| the editor said | what it means |
|---|---|
| *…holds a different publish key…* | the two private stores have parted. `publish.key` must be **the same bytes** on both hosts |
| *…disagree about the time by more than five minutes…* | one of the two clocks is wrong |
| *…implements a different content shape…* | the halves are out of step. Deploy both |
| *…could not be reached…* | the public site was down, or DNS/TLS failed from this host |
| *…answered … with something that was not JSON* | `tech4time-website-frontend/api/publish.php` is not deployed. Run `tech4time-website-frontend`'s `verify_live.py` |
| *Publishing is not set up…* | there is no `publish.key` in **this** host's store — `tools/make_publish_key.py` |

Full table: [publish-api.md](../10-development/server-side/publish-api.md).

### Nobody saw the message, and the two have disagreed since

`tools/` is not deployed, so this is uploaded to the host's home directory, run, and deleted — the
same way `admin-cli.php` is. It has to run **there**: it reads that machine's `content/` and that
machine's private store.

```bash
scp tools/reconcile.py techtime@HOST:~/
ssh techtime@HOST 'python3 ~/reconcile.py ~/admin.tech4time.bd'
ssh techtime@HOST 'rm ~/reconcile.py'
```

It reports one of four things per document. **Stop at "the live site is ahead"** — that means the
public site holds a revision this host does not, so either something published from elsewhere or
this host's record was restored from an older backup. Do not force it; compare the two first.

### Every publish is refused as `unknown-key`

The two stores hold different bytes. Fix it by making one authoritative:

```bash
python3 tools/make_publish_key.py --show      # here
# then put exactly that value in the frontend's ../t4t-private/publish.key
```

Between the two writes, every publish is refused. That is visible and recoverable, which is why the
key is copied by hand rather than derived — a derived one would differ by construction and fail the
same way for a reason nobody could see. [0017](../90-decisions/0017-two-private-stores.md)

### The first save published an empty document over the live page

The backend was stood up beside a public site that already had content, and its `content/` was
seeded empty instead of inheriting. The seed only creates what is absent, so it did not overwrite
anything *here* — but the first save then published this host's empty document, and the live site
accepted it because the revision was newer.

Recover from the public site's `.bak`, or from a backup, into **this** host's `content/`, then save
once to republish. And read [first-deploy.md](../20-deployment/first-deploy.md), which says to copy
the content across before the first save for exactly this reason.

---

## The admin

### It refuses to load and shows a message about the private directory

Working as designed. The admin refuses rather than running without its store — an editor that
quietly works without a password is worse than one that visibly does not work.

The message names the path. Usually:

| | |
|---|---|
| Not writable | `chmod 700 ~/t4t-private-admin` |
| Cannot be created | the directory **above** the document root is not writable |
| "inside the document root" | it is in the wrong place — [environments.md](../20-deployment/environments.md) |

### "That setup key does not match"

Retype rather than paste. It is compared case- and punctuation-insensitively, so the usual cause is
an invisible character.

```bash
cat ~/t4t-private-admin/setup-token.txt
```

**If `cat` shows a different key each time you run it**, the file is not being recognised on
re-reading and a fresh one is minted on every call — so the key you are shown is never the key you
are checked against, and no amount of careful typing will work. The length a stored key must have is
derived from `AUTH_SETUP_BYTES` in `lib/auth.php`; if the format is ever changed, that is where the
two must stay in step. `test_admin_auth.py` covers this.

### `setup.php` redirects to `login.php`

An account already exists. If it is not yours, treat it as a compromise:

```bash
php ~/admin-cli.php list
php ~/admin-cli.php log 100
```

[Rung 8](secrets-recovery.md#8-suspected-compromise).

### The admin refuses: "The account file is present but cannot be read"

`admins.json` is corrupted, not missing. The admin stops rather than continue, because from there a
damaged file is indistinguishable from a site nobody has set up — and going through setup would
copy the damage over `admins.json.bak`, which may be the only intact copy left.

A good backup is sitting beside the broken file. Restore it:

```bash
cd ~/t4t-private-admin
cp admins.json admins.json.broken        # keep the evidence
cp admins.json.bak admins.json
```

[Rung 6](secrets-recovery.md#6-adminsjson-corrupted).

> **If you are on a version that offers setup instead of refusing**, do not go through it — restore
> the `.bak` as above. That was the behaviour before the refusal existed.

### The authenticator code is always wrong

- **Clock drift.** `TOTP_DRIFT` allows one 30-second step either side. More than that needs the
  server's clock corrected.
- **A code works only once.** Wait for the next one rather than retyping the same digits.
- **Wrong account** in the app, if you have several paired.

### Recovery codes do not work, but `admin-cli list` says there are ten

`secret.key` was lost or replaced. The codes were hashed under a key derived from it.

```bash
php ~/admin-cli.php codes
```

[Rung 5](secrets-recovery.md#5-secretkey-lost-or-corrupted).

### "Try again in …"

The lockout. Five failures are free, then each attempt waits longer, up to an hour.

```bash
php ~/admin-cli.php unlock
```

### `login-failed` in the audit log — what it does and does not tell you

**It means "that combination did not work". It never means "that user does not exist."**

`auth_attempt()` returns `null` for a wrong password and for an unknown username alike, and
`public/login.php` logs one `login-failed` event for both, carrying only what was typed. The sign-in
answers the browser identically too — *"That username and password do not match"* — which is the
point: an error that distinguished the two would let anyone with the login page enumerate which
accounts exist, one guess at a time. `test_admin_auth.py` proves the two answers stay identical.

So a run of `login-failed` against an address you do not recognise is **not** evidence that somebody
tried an account you do not have. It is equally consistent with you mistyping your own password.

Two things that look alarming and are not:

- **The sign-in accepts a username *or* the account's email address.** `login-failed who=you@example.com`
  may well be your own account, found correctly, with the password fumbled.
- **Every password fails immediately after `secret.key` is rotated.** The stored hash was made under
  a key the server no longer has, so it cannot be verified and the attempt is refused — correctly, and
  indistinguishably from a wrong password. Expect exactly one of these per person who had not yet been
  told. [secrets-recovery.md rung 9](secrets-recovery.md#9-the-master-key-was-exposed-and-nothing-else-was)

What the log *can* tell you is the address it came from. A failure from an IP you do not recognise is
worth attention; the same failure from your own is worth none.

### Signed out constantly

`AUTH_IDLE` is one hour, `AUTH_ABSOLUTE` twelve. If it is faster than that, the session directory is
not writable, so no session is persisting:

```bash
ls -la ~/t4t-private-admin/sessions/
```

### The reset code never arrives

`mail()` — as above. Check the spam folder, confirm the mailbox on the account is one you can open,
and confirm SPF/DKIM/DMARC. A recovery code will sign you in meanwhile.

### A notice says the footer's contact details differ from the contact page's

**Nothing is broken and nothing needs doing unless you want it to.** The footer's contact rows are
its own — added, worded, ordered, shown and hidden on the **Header & Footer** screen — and are
deliberately not a copy of the contact page's. The contact page holds every detail in full; a footer
holds the part worth putting in a footer.

The notice exists so the difference is never *invisible*, not to say it is wrong. It never blocks a
save. If the difference is an oversight rather than a choice, edit the footer's rows at `/?s=chrome`,
or press **Copy from the Contact page** to reseed them, and save.
[ADR 0023](../90-decisions/0023-the-header-and-footer-are-emitted-once.md)

> **Not built yet:** the `/?s=chrome` screen. The public site already renders its header,
> footer and dock from that document; the editor that will change them is the next piece of
> work.

There used to be a different banner here saying the footer was *out of step*, which meant something
else entirely: the footer was markup in sixteen of the public site's pages, a build script had to
push the details into them, and closing the gap was a deploy of that repository. That is gone.

---

## The tests

### The browser test fails intermittently

Leaked processes from an interrupted run.

```bash
pkill firefox geckodriver
```

### The browser test skips with a notice

Firefox or geckodriver is missing. By design — it exits 0 rather than failing, so a machine without
a browser can still run everything else. CI asserts the binaries are on PATH first, because there a
skip would be a silent pass.

### A test hangs after an interrupted run

A leaked PHP server holding the port.

```bash
pkill -f 'php -S'
```

### `check_content_model.py` fails after adding a field

You changed the shape in one of the three places it lives. The message names the field and the
missing layer. [content-model.md](../10-development/server-side/content-model.md)

### `check_secrets.py` fails

Read the message before "fixing" it. It only fails for things that would silently weaken the admin,
and every one of its checks was verified against a deliberate breakage.

### `check_docs.py` fails

Code and documentation disagree. Usually a file added and not documented, or a constant changed that
the prose quotes. The message names both sides.

---

## Known traps

Current behaviour, documented because it is surprising rather than because it is right.

| Trap | Consequence | Until it is fixed |
|---|---|---|
| The containment check compares against the *requesting* document root | a store inside a **sibling** docroot would pass and be web-reachable | set `T4T_PRIVATE` explicitly. This host's document root is `admin.tech4time.bd/public/`, so nothing beside `public_html` is inside it — [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) removes the layout that would exercise the gap rather than the gap itself |
| `publish.key` is copied between hosts by hand | a mistake places it somewhere readable, or the two drift apart | the fingerprint on every signature makes a drift say so in one attempt; the file is 0600 in a 0700 directory outside both document roots |
| A draft job post is published | its text sits on the public server, in `content/`, protected by `.htaccess` alone | the whole document travels so the two revisions stay comparable, and the public site filters on `status` when it renders. Unchanged from before the split, and stated rather than assumed — [publish-api.md](../10-development/server-side/publish-api.md) |

**Fixed 2026-08-23, on the public site** — the whole repository, unpacked into its document root.
The first deploy was an upload of everything, and it left `docs/`, `tools/`, `references/`, `.git/`,
`.claude/`, the `.md` files and a **63 MB zip of the repository** beside the homepage.

Worth reading here because it is the argument this repository is shaped around: the rule that was
supposed to prevent it did not.

`tech4time-website-frontend`'s `.htaccess` section 8 exists for exactly this — *"if the whole tree is ever uploaded, these must not
be readable over HTTP"* — and did not deliver it. `<FilesMatch "^\.">` matches the **filename**, so
`/.git/HEAD` was the file `HEAD` to it: no leading dot, no blocked extension, straight through. And
nothing covered `.zip` at all, so the entire source and its commit history were downloadable by
anyone who guessed the filename. The host answered 403 for `.git` by a rule of its own — not ours,
and not present on a server we run.

Now: a rewrite rule blocks any path segment beginning with a dot (exempting `.well-known/`, which
AutoSSL needs), archives and dumps join the extension rule, `references/` is blocked, and
`verify_live.py` asserts all of it against the running site after every deploy. The deploy set is
built from an allow list, so none of it can be uploaded again.

**Fixed 2026-08-23** — the setup token outlived setup. `public/setup.php` promises the bootstrap
window is *"shut by the code rather than by a step somebody has to remember"*, and on the live host
`setup-token.txt` sat in the private store beside a working account.

`auth_setup_done()` had deleted it correctly. The recovery-codes screen — which skips the "setup is
over" redirect on purpose, so it can show the codes — then fell through to `auth_setup_token()` and
re-minted it, seconds later. The token was inert, because a stranger with no `codes` stage in
session is redirected to `login.php` before it is ever compared, but the guarantee was false.

The guard now lives in `auth_setup_token()` itself rather than at the call site, so the file cannot
come back whoever asks; `auth_setup_token_check()` refuses outright once an account exists, so an
empty token can never meet an empty stored value in `hash_equals()` and agree with it. Both halves
are asserted by `check_secrets.py`.

The test that should have caught it *existed and passed vacuously*: it ran from 127.0.0.1, where
`auth_is_loopback()` is true, no key is ever demanded and no file is ever written — so "the setup
key file is gone" was true because nothing had created it. `test_admin_auth.py` now carries the
remote setup flow through to the codes screen, which is the only branch that can prove it.

**Fixed 2026-08-23** — tabbing on a phone put the focus ring underneath the dock. The browser
scrolls a focused element into view and was happy to land it exactly where the fixed bar covers it,
on footer links, phone numbers and the end of the contact form — twelve stops across three pages.
`html` now carries a `scroll-padding-bottom` below 64em, the mirror of the `scroll-padding-top` that
already kept the sticky header clear.

**Fixed 2026-08-23** — the certification accordions and the job posts had no focus indicator at all.
`<summary>` will not take an outline in Firefox: the computed style reports the right colour while
`outline-style` stays `none`, and an explicit `summary:focus-visible { outline: … }` changes
nothing. A screenshot settled it — zero pixels differ when a summary is focused, against 307 for an
ordinary link. It is drawn with a `box-shadow` now.

**Fixed 2026-08-23** — the contact form's consent checkbox was a 23px tap target at the widths where
its label fits on one line, one pixel under WCAG 2.2 SC 2.5.8. The box is now `1.5rem`, which is 24
exactly. Reading the stylesheet would never have shown it: the box was 18px, the label 23, and the
target is the two together.

**Fixed 2026-08-23** — the About page scrolled sideways on a 320px screen, and its call to action
was cut off. The specialities slider's control row is eight 44px tap targets, centred, which is
wider than the screen and overhangs both edges; and `.btn` had an unconditional `white-space:
nowrap`, so a 34-character label became a 351px button that `.cta-band` clipped. Neither was
visible to any check, because Firefox will not size a window below about 488px and nothing here had
ever measured a narrower one. `tech4time-website-frontend/tools/check_responsive.py` now does, in a frame.

**Fixed 2026-08-23** — a careers field drifting between model, form and renderer unnoticed.
`check_content_model.py` could never have caught it: both sides of that page are loops, so its
regexes read the loop variable rather than the fields. `tools/test_careers_admin.py` proves it by
round trip instead — a marker through every field the model declares, editor to visitor — and
`check_content_model.py` now fails if an editor is in neither `SUBJECTS` nor `COVERED_ELSEWHERE`.

**Fixed 2026-08-23** — recovery codes dying silently with `secret.key`. Stored codes carry the
fingerprint of the key that made them, so `admin-cli list` prints `10 DEAD` and says what to run
instead of counting entries. Covered by `tools/test_admin_auth.py`.

**Fixed 2026-08-23** — `store_read()` answering `null` for both a missing and a corrupt file. They
are told apart by `store_state()` now: the admin refuses to start on a damaged account file instead
of offering setup, and `store_write()` will not let a damaged file become the `.bak`. Covered by
`tools/test_store.py` and `tools/test_admin_auth.py`.

---

## Getting more detail

```bash
php ~/admin-cli.php log 100     # the audit log
php ~/admin-cli.php where       # which files the admin is using
tail -f ~/logs/error_log        # PHP errors, path varies by host
```

The audit log records every sign-in attempt, successful or not, with the IP. It is the first place
to look when something about access is unclear.
