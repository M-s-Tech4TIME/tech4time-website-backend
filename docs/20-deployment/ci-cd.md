# Continuous integration and deployment

**Applies to:** both

Every change reaching the server the same way, through checks that cannot be skipped.

---

## What this replaces

The first deploy was a zip built by hand and uploaded through cPanel's File Manager. That worked,
and it does not scale past one person doing it carefully: the set of files that may go to the
server was a sentence in [routine-deploys.md](routine-deploys.md) — an `rsync` line with eight
`--exclude` flags — and seven of those flags save bandwidth while the eighth,
`--exclude='content/'`, is the only thing between a deploy and every job post the client has
written.

Nothing about the two kinds of flag looks different. That is the problem being fixed.

---

## The pieces

| | |
|---|---|
| `tools/build_deploy_set.py` | builds the upload set, and asserts what is in it |
| `.github/workflows/test.yml` | every check this repository has, on every push |
| `.github/workflows/deploy.yml` | push to `main` → checks → dry run → gate → sync → seed → verify |
| `tools/verify_live.py` | asks the deployed site whether its protections are still there |

---

## The upload set

`build_deploy_set.py` produces two directories:

```bash
python3 tools/build_deploy_set.py --out _deploy
#   _deploy/site/   → the document root
#   _deploy/seed/   → content/, and only where content/ is empty

python3 tools/build_deploy_set.py --check    # assert it, build nothing
```

**It is an allow list, not an ignore list.** `UPLOAD` names every top-level entry that goes, and
anything not named stays behind. The two fail in opposite directions and only one of them fails
safely:

| | a new file is added and nobody thinks about deployment | |
|---|---|---|
| ignore list | it ships | a stranger finds it |
| **allow list** | it does not ship | a visitor finds a 404 |

`DENY` then removes things carried along by a directory that is otherwise wanted — `admin/.htaccess`
above all, because cPanel writes its own there and ours would fight it.

`REQUIRED` is the other direction: files whose *absence* is a broken site rather than a missing
feature. `public/.htaccess` heads that list because it is a dotfile, and both FTP clients and zip tools
have been seen to drop it silently — taking the rules that block `lib/` and `content/` with it, and
leaving a site that looks completely normal.

### Content is not in the set

`content/` holds the client's data: job posts and contact details typed into the admin on the live
server. The repository's copy is test data. It is **never** synced.

But a brand-new host has nothing there and the two dynamic pages need something to render, so the
seed directory is copied with `rsync --ignore-existing`: it creates what is absent and overwrites
nothing. A file already on the host has been edited by somebody and wins, permanently, without
anyone having to decide so on the day.

**The seed is built from `CONTRACT_DOCUMENTS`, not from a list of copy calls.** For every document
the contract defines, `build_deploy_set.py` takes `deploy/seed/<name>.json` if there is one and
`content/<name>.json` otherwise, and refuses to build if there is neither.

`deploy/seed/careers.json` carries `jobs: []` — a new host must not launch advertising the test
vacancies — while keeping the real `cv_form_url`. That is the only document with a hand-written
seed. `contact.json` and `company.json` seed from `content/`, which is genuine content and, for
contact, the same file the page footers were built from; seeding either from anywhere else would
make the two disagree, and seeding them empty would give a new host a page of headings with nothing
under them.

### It was a list, and the list went out of step

The company profile shipped with its seed line in **this** repository and never added to the other
one. Nothing failed. `content/company.json` simply never reached the admin host, `company_load()`
fell back to `company_defaults()`, and the editor came up rendering an empty form — over a live page
holding seventy-seven rows. One press of Save would have published the empty one over it.

That is why the loop replaced the list, in both halves, and why `--check` now asserts that every
document in the contract has a seed. There is also a warning in the editor itself now: a section
whose `content/<name>.json` is missing says so before anything can be saved.

---

## The test workflow

Runs on every push to `dev` and `main`, and on every pull request to `main`. Four jobs, in
parallel:

| Job | What runs | Needs |
|---|---|---|
| `checks` | the seven static checks, plus `build_deploy_set.py --check` | python, php |
| `php` | every suite that drives a real PHP server and a real sign-in, then a verdict | php, php-gd, php-xml, qrencode |
| `both halves` | `test_end_to_end.py --clone` — this repository against the frontend, nothing stubbed | php, php-gd, network |
| `firefox` | the eight browser suites, all of them, then a verdict | firefox, geckodriver, Pillow |

### A suite on disk and not on that list is a suite that does not exist

Three of them were: `test_chrome_admin.py` shipped with the header-and-footer work,
`test_settings_admin.py` and `test_reconcile.py` with the settings work, and none had ever been run
by anything that cannot forget — 230 checks that passed on a laptop and were asserted nowhere. The
frontend had the same gap, with `test_settings.py` and `test_pictures.py`.

Nothing catches this automatically. Adding a suite means adding it here, in the same commit, for
the same reason adding a tool means documenting it.

### `both halves` is expected to be red in one window, and only one

It fetches the sibling **on this branch**, so work touching both halves fails here until both are
pushed — the same window, and the same expected red, as `check_shared_repos.py --clone`. That is
not a flaw in either: a check that quietly compared against the other half's last release instead
would call a legitimate difference drift.

It is also why the deploy order below is what it is. Merge the **backend** first and its run goes
red on these two alone, with `deploy` skipped; merge the **frontend**, which deploys; then re-run
the backend's failed run, which now finds the frontend it expects and deploys second.

It is deliberately the **same list** as the pre-commit set in
[testing.md](../10-development/testing.md). What gates a merge and what gates a release are one set
of checks, so that "it passed on my machine" and "it is safe to put on the server" stop being two
different claims.

### All eight suites run before the job reports

The `firefox` job runs the browser suites in **one step**, collects the failures and reports at the
end, rather than giving each suite a step of its own.

A step per suite stops at the first failure, and that hid more than it looked like it would:
`test_motion.py` failed on its third check, so `test_editor.py`, `check_hover.py`,
`check_dark_mode.py`, `check_responsive.py` and `check_focus.py` — five suites, most of the
coverage — had **never executed in CI at all**. Fixing them would have meant one three-minute round
trip per suite to discover the next problem.

### The silent-pass trap, and the guard against it

Every browser suite calls `shutil.which("firefox")` and, finding nothing, prints a notice and
**exits 0**. That is right on a laptop without geckodriver installed. It is wrong in CI, where a
failed install would turn eight suites into eight green ticks that proved nothing.

So the workflow requires `php`, `firefox` and `geckodriver` to be on `PATH` in a step of its own,
before any suite runs. Note the exact name: `firefox-esr` from apt installs a binary called
`firefox-esr`, which is not what the suites look for — which is why Firefox is installed from
Mozilla's tarball and symlinked, rather than from apt.

---

## The deploy workflow

`.github/workflows/deploy.yml` runs on push to `main`, and on demand. Merging `dev` into `main` is
the approval step; there is no staging site.

**Then merge `main` back into `dev` and push it.** That step is not optional tidying and it is not
what it looks like: the merge commit a pull request creates is created on `main` ALONE, so without
this `dev` falls exactly one commit behind per release. It carries no change of its own -- every
real commit and every file is already on `dev` -- but the counter climbs, and "21 commits behind
main" reads like released work that never came home. 21 releases are how it got there.

```bash
git checkout dev && git fetch origin
git merge origin/main     # FAST-FORWARDS, if nothing landed on dev meanwhile
git push origin dev
```

It fast-forwards because `dev`'s tip is a PARENT of the merge commit `main` just received, so there
is nothing to join -- `dev` simply moves onto the same commit and the two branches become identical,
0 ahead and 0 behind. If somebody committed to `dev` in between it makes an ordinary merge instead
and `dev` reads 1 ahead, which is the normal resting state and not a fault.

**Use "Create a merge commit" on the pull request, never "Rebase and merge".** Rebasing writes the
commits onto `main` with NEW hashes while the originals stay on `dev` for ever, which manufactures
the same drift permanently and cannot be reconciled by the merge above.

```
test       .github/workflows/test.yml, called as a reusable workflow
build      python3 tools/build_deploy_set.py --out _deploy
ssh        write the deploy key, pin the host key, prove the connection
dry run    rsync --delete --itemize-changes --dry-run  →  /tmp/plan.txt
gate       read /tmp/plan.txt; fail the job if it deletes anything protected
sync       rsync --delete             _deploy/site/  →  ~/admin.tech4time.bd/
seed       rsync --ignore-existing    _deploy/seed/  →  ~/admin.tech4time.bd/content/
verify     python3 tools/verify_live.py https://admin.tech4time.bd
```

Transport is **rsync over SSH**, using a deploy-only key. The host key is pinned in
`known_hosts` from a secret rather than accepted with `ssh-keyscan` on each run — keyscanning into
`known_hosts` accepts whatever answers, which is a formality rather than a check.

### The protect list, and why the gate is not redundant

`rsync --delete` into a cPanel document root is destructive by default, and what it would destroy
is not in the repository: `content/`, `public/uploads/`, `.well-known/`, `cgi-bin/`, `error_log`,
the MultiPHP ini files. The full list and its reasoning is
[ADR 0016](../90-decisions/0016-a-deploy-protects-what-the-panel-owns.md).

The filters prevent those deletions. The gate then reads the dry run and fails the job if any were
proposed anyway. That is deliberately two mechanisms for one rule, because a typo in a filter path
produces a rule that matches nothing, reports no error, and leaves every run green.

**The gate's own pattern was wrong when it was written** — it expected `*deleting ` with one space
where `--itemize-changes` emits three — so it matched nothing and passed everything while looking
entirely healthy. It was caught by running it against a dry run with the filters removed. That is
now how any change to either is verified:

```bash
rsync -a --delete --itemize-changes --dry-run SRC/ DST/ > plan.txt   # no filters
grep -E '^\*deleting[[:space:]]+(content/|\.well-known/|cgi-bin/|([^[:space:]]*/)?error_log)' plan.txt
```

If that prints nothing, the gate is broken. A gate that has never been seen to fail has not been
tested.

**`error_log` matches at any depth on purpose.** PHP writes its log beside the script that raised
the error, so a rule anchored to the root protected one file and let every other one be deleted.
Both halves were wrong until 2026-09-10; the backend's log lives at `public/error_log` and was
being removed by every deploy. The fix was proved the way this section asks for — the pattern run
against a plan carrying `error_log` at three depths, and the filter against a real
`rsync --delete` into a tree holding them.

### Secrets

Set under Settings → Secrets and variables → Actions:

| Secret | What |
|---|---|
| `SSH_HOST` | the hostname or IP cPanel gives for SSH |
| `SSH_PORT` | cPanel often uses something other than 22 |
| `SSH_USER` | the cPanel account name — `techtime` |
| `SSH_KEY` | the **private** half of a deploy-only key, no passphrase |
| `SSH_HOST_KEY` | one line of `ssh-keyscan -p PORT HOST`, pinned |

Generate the key in cPanel → SSH Access → Manage SSH Keys, and authorize it there. Use a key made
for this and nothing else: it is stored by GitHub, used unattended, and should be revocable without
disturbing anything a person signs in with.

Every secret reaches the shell through `env:` rather than `${{ }}` interpolation. The values here
are trusted; the habit is not about these values.

---

## Cost

GitHub Actions is free for public repositories and metered for private ones. The `checks` and `php`
jobs take about a minute between them. The `firefox` job is the expensive one — `check_focus.py`
alone makes 1846 assertions across 22 page loads. If minutes become tight, move the `firefox` job to
pull requests and pushes to `main` only, and leave `dev` covered by the other two.
