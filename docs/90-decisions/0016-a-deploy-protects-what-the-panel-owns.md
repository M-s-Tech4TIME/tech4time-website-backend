# 0016 — A deploy protects what the panel owns

**Status:** accepted · **Applies to:** both

## Decision

`rsync --delete` into a cPanel document root runs with an explicit **protect list**, and a separate
gate reads the dry run and fails the job if it proposes deleting anything on that list.

```
P /content/            THE SYSTEM OF RECORD -- every job post and contact detail
P /public/uploads/     pictures the editor stored, which are not in the repository
P /.well-known/        AutoSSL's ACME challenges
P /public/.well-known/ and here too: which one AutoSSL writes to depends on when
                       it last read the subdomain's configuration
P /cgi-bin/            created by cPanel
P error_log            written by the server -- AT ANY DEPTH, see below
P /.user.ini           MultiPHP INI Editor
P /php.ini             MultiPHP INI Editor
```

`public/.htaccess` is deliberately **not** here: this repository ships it.

**`error_log` is the one rule with no leading slash, and that is deliberate.** A pattern
containing no `/` is matched by rsync against the final component of a name at any depth, and PHP
writes its log beside whichever script raised the error. This half serves from `public/`, so its
log is `public/error_log` -- which `P /error_log` did not match at all, and every deploy deleted
it. Seen happening in the 2026-09-10 deploy log, in the run that shipped ADR 0023.

The filters prevent the deletions. **The gate is a separate check that they did**, and it is not
redundant.

## Context

The document root is not ours alone. The repository is one contributor to it; cPanel is another,
and the server itself is a third. None of what those two write is in git, so `--delete` — which
exists to remove files dropped from the repository — treats every one of them as stale.

The costs are not equal, and that is what makes this worth a record:

- **`content/`** is the client's data. Deleting it destroys job posts and contact details typed
  into the admin, and the loss is silent: the site keeps working, showing whatever the seed put
  there, until somebody notices their vacancy is gone.
- **`.well-known/`** is how AutoSSL answers ACME challenges. Deleting it breaks certificate
  renewal, and that failure surfaces *months later* as an expired certificate on a site nobody
  changed. It was not present on the host when this was written, which is exactly the trap: it
  appears at renewal time, so a deploy is only dangerous during the window that matters.
- The rest are recoverable, and are on the list because the list should not require judgement at
  three in the morning.

## Consequences

**The gate stays even though the filters make it "redundant".** They are not the same claim. The
filters are a rule the deploy is asked to follow; the gate is a reading of what the deploy actually
proposed. A typo in a filter path produces a rule that matches nothing and reports no error — the
protection silently disappears and every run goes green. Only reading the plan catches that.

That is not hypothetical: **the gate's own pattern was wrong when first written.** It matched
`^\*deleting ` with a single space, and `rsync --itemize-changes` pads the flag field, so the real
line reads `*deleting   content/careers.json` with three. The gate matched nothing, passed
everything, and looked exactly like a gate that was working. It was found by running it against a
deliberately unprotected dry run — which is now how it is verified, and the only way this kind of
check can be believed.

**Verification is a negative test.** Any change to the filters or the gate is checked by removing
the filters and confirming the gate fires. A gate that has never been seen to fail has not been
tested; it has been admired.

**Content is seeded, never synced.** `deploy/seed/` goes across with `--ignore-existing`, which
creates what is absent and overwrites nothing — so a file on the host always wins, permanently,
without anyone deciding so on the day. See [ci-cd.md](../20-deployment/ci-cd.md).

**This list grows with the host.** A panel feature that writes into the document root — Directory
Privacy, a hotlink rule, a cron that drops a file — belongs here on the day it is switched on, not
on the day a deploy removes it.

---

## Amendment — the backend, 2026-08-27

The subdomain's deploy target is `~/admin.tech4time.bd/` and its document root
is `public/` **inside** it, so `rsync --delete` runs over a directory cPanel
also writes to. The protect list is therefore the same shape as the frontend's,
with one difference worth recording.

**`.well-known` is protected in both places** — at the target root and under
`public/`. cPanel created the subdomain with its document root at
`~/admin.tech4time.bd` and put `.well-known` there; the document root then
moved one level in. Which of the two AutoSSL writes to next depends on when it
last read the subdomain's configuration, and being wrong about it is a
certificate that quietly stops renewing — discovered months later, with nothing
to connect it to.

Found by listing the target over SSH before the first deploy rather than
assuming the layout. The protect list had only `public/.well-known/`, and the
existing directory would have been deleted on the first run.

`public/.htaccess` is deliberately **not** protected here, unlike the
frontend's `admin/.htaccess`. This repository ships that file. If cPanel's
Directory Privacy has written its own there, that is a setting to switch off
before deploying, not a file to preserve — see the note at the end of
`public/.htaccess`.
