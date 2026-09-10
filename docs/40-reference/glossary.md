# Glossary

**Applies to:** both

Terms used in this documentation and in the code's comments, in the sense this project uses them.

---

### argon2id
The password-hashing algorithm used here, at 32 MB and three passes. Deliberately slow and memory-
hungry, so guessing costs an attacker real hardware. bcrypt at cost 12 is the fallback where a host
lacks it.

### audit log
`t4t-private-admin/audit.log`. One JSON line per event — sign-ins successful and not, password changes,
recovery codes spent. Records; nothing watches it.

### bootstrap window
The gap between deploying the admin and creating the first account, during which the setup page is
reachable and whoever uses it owns the site. Closed by the **setup token**.

### CSP — Content-Security-Policy
The header that tells the browser which origins may load. Here it is `'self'` for everything, which
forbids inline styles and scripts entirely.

### document root
The directory Apache serves a domain from — `/home/USER/public_html`. Nothing above it has a URL,
which is why the **private store** lives beside it rather than inside it.

### drift
Two things that must agree quietly ceasing to. The header copied into sixteen pages; the docs and
the code; the footer and `contact.json`. Most checks in `tools/` exist to catch a kind of drift.

### fail closed / fail open
Whether a component refuses or continues when it cannot do its job. **Authority fails closed** — the
admin refuses to run without its store. **Convenience fails open** — the contact form still works if
its rate-limit counter is unreadable.

### master key
`t4t-private-admin/secret.key`. 32 random bytes from which every other key is derived by purpose. Losing
it invalidates every password hash and every recovery code.

### pepper
A secret mixed into a password before hashing, stored **in a different file** from the hash. Unlike
a salt it is not per-password and not stored alongside — which is the point: a stolen `admins.json`
cannot be attacked without also stealing `secret.key`.

### private store
`/home/USER/t4t-private-admin/`, `../t4t-private-admin` locally. Password hashes, the master key, authenticator
secrets, sessions, counters and the audit log. Never committed, never deployed, never inside the
document root.

### progressive enhancement
Building so the page works without JavaScript and improves with it. A hard rule here, not an
aspiration.

### recovery code
One of ten single-use codes issued at enrolment, each good for one sign-in in place of the
authenticator. Stored hashed under a key derived from the master key.

### replica
The frontend's copy of content it does not own. After the split, the backend is the source of truth
and pushes a copy; the frontend renders from the copy and never asks at render time.

### reveal
The scroll animation that fades sections in. Marked by rule via `tech4time-website-frontend/tools/apply_reveals.py`, and
carefully designed never to leave anything hidden.

### salt
Random bytes mixed into a password before hashing, different for every password, **stored inside the
hash string** by `password_hash()`. That is why there is no separate salt field to manage.

### section
One editable page in the admin — a row in `ADMIN_SECTIONS` plus a file in `sections/`. Reached
at `/?s=<name>`.

### setup token
A value written to `t4t-private-admin/setup-token.txt` that `public/setup.php` demands. Readable only with
server access; destroyed the moment an account exists.

### chrome
The furniture around every page of the public site: the header, the footer and the small-screen
dock. One document, `tech4time-website-frontend/content/chrome.json`, edited on the **Header &
Footer** screen. It was literal markup in seventeen page files until
[ADR 0023](../90-decisions/0023-the-header-and-footer-are-emitted-once.md).

### shared markup
Markup that must be byte-identical on every page of the public site because it is still *copied*
into every page. Only the hero circuit and the script tags are left: the head became `tech4time-website-frontend/lib/head.php`
and the chrome became `tech4time-website-frontend/lib/body.php`, both in tech4time-website-frontend.

### sprite
`public/assets/icons/sprite.svg`, the master icon set. Pages **inline** the symbols they use rather than
linking to it, because Chromium and WebKit do not resolve `<use>` across documents.

### token_version
A counter on each account, recorded in every session. Bumping it on a password change ends every
other session at once.

### TOTP — Time-based One-Time Password
RFC 6238. The six digits an authenticator app shows, derived from a shared secret and the current
30-second window. Implemented by hand in `lib/totp.php` and checked against the RFC's own test
vectors.

### throttle
The counters in `t4t-private-admin/throttle.json` that make guessing cost something. Applied **before** the
password is verified, so a lockout cannot be used as an oracle.

### watchdog
The fallback in `theme-init.js` that lifts the scroll-reveal's hidden state at the load event, in
case the scroll-reveal script never arrives. The reason a failed script cannot leave content
invisible.

That script is `tech4time-website-frontend/assets/js/animations.js`, and it is not on this host —
the editor has no scroll reveal. `theme-init.js` is near enough the same file on both sides, so the
watchdog comes along with it and finds nothing to lift here, which costs one event listener and
keeps the two copies from diverging.
