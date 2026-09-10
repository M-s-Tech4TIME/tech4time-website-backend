# Tech4TIME — Documentation (backend)

Everything about this project that is not the code itself: how to set it up, how it is built, where
to change things, how to deploy it, and what to do when something breaks.

**This is the editor.** The public website — its sixteen pages, its CSS, its JavaScript, its
markup — is in **`tech4time-website-frontend`**, which has its own copy of this documentation covering that
half. Entries that read *(in tech4time-website-frontend)* are there rather than here: the document exists,
in the other repository.

**New here?** Read [00-orientation/README.md](00-orientation/README.md), then
[10-development/setup.md](10-development/setup.md). About an hour, and you will be able to work.

---

## Find what you need

### I have just been assigned this project

| | |
|---|---|
| **The first hour** | [10-development/README.md](10-development/README.md) |
| What is this thing? | [00-orientation/README.md](00-orientation/README.md) |
| How does it work, end to end? | [00-orientation/architecture.md](00-orientation/architecture.md) |
| What is in all these directories? | [00-orientation/repository-map.md](00-orientation/repository-map.md) |
| Why is it built so strangely? | [00-orientation/conventions.md](00-orientation/conventions.md) |
| Get it running on my machine | [10-development/setup.md](10-development/setup.md) |

### I need to change something

| | |
|---|---|
| **Where does this live?** — start here | [10-development/where-to-change-things.md](10-development/where-to-change-things.md) |
| Colours, spacing, typography | *10-development/frontend/css.md* (in tech4time-website-frontend) |
| Behaviour in the browser | *10-development/frontend/javascript.md* (in tech4time-website-frontend) |
| Animation, sliders, the reveal | *10-development/frontend/motion.md* (in tech4time-website-frontend) |
| Icons | [10-development/server-side/icons.md](10-development/server-side/icons.md) |
| The header, footer or dock | their **words and links** are edited at `/?s=chrome`; the markup is *10-development/frontend/shared-markup.md* (in tech4time-website-frontend) |
| Add a whole new page | *10-development/frontend/adding-a-page.md* (in tech4time-website-frontend) |
| Server-side code | [10-development/server-side/libraries.md](10-development/server-side/libraries.md) |
| Make a page editable in `/admin` | [10-development/server-side/adding-an-editor.md](10-development/server-side/adding-an-editor.md) |
| The sign-in, sessions, passwords | [10-development/server-side/authentication.md](10-development/server-side/authentication.md) |
| Run the tests | [10-development/testing.md](10-development/testing.md) |

### I am deploying

| | |
|---|---|
| How deployment works here | [20-deployment/README.md](20-deployment/README.md) |
| Never deployed this before | [20-deployment/first-deploy.md](20-deployment/first-deploy.md) |
| Setting up the cPanel host | [20-deployment/cpanel-host-setup.md](20-deployment/cpanel-host-setup.md) |
| Turning on the admin sign-in | [20-deployment/admin-activation.md](20-deployment/admin-activation.md) |
| Pushing an update to a live site | [20-deployment/routine-deploys.md](20-deployment/routine-deploys.md) |
| Automating the checks and the deploy | [20-deployment/ci-cd.md](20-deployment/ci-cd.md) |
| Where the private store goes | [20-deployment/environments.md](20-deployment/environments.md) |

### Something is wrong

| | |
|---|---|
| Running the site day to day | [30-operations/README.md](30-operations/README.md) |
| **Symptom → cause → fix** | [30-operations/troubleshooting.md](30-operations/troubleshooting.md) |
| I cannot sign in to the admin | [30-operations/secrets-recovery.md](30-operations/secrets-recovery.md) |
| The secrets are lost or corrupted | [30-operations/secrets-recovery.md](30-operations/secrets-recovery.md) |
| What should be backed up? | [30-operations/backups.md](30-operations/backups.md) |

### Day to day

| | |
|---|---|
| Post a job, change a phone number | [30-operations/content-runbook.md](30-operations/content-runbook.md) |
| What does this script do? | [40-reference/tools.md](40-reference/tools.md) |
| What fields does this JSON have? | [40-reference/content-schemas.md](40-reference/content-schemas.md) |
| Where does a page's title live, and who can change it? | [40-reference/seo.md](40-reference/seo.md) |
| What protects what? | [40-reference/security-model.md](40-reference/security-model.md) |
| Live host facts — mail, DNS, PHP | [40-reference/host-facts.md](40-reference/host-facts.md) |
| What does that word mean? | [40-reference/glossary.md](40-reference/glossary.md) |
| Why was it done this way? | [90-decisions/README.md](90-decisions/README.md) |

---

## The shape of this directory

```
docs/
├── 00-orientation/   what the project is          read once, first
├── 10-development/   working on it                read while working
├── 20-deployment/    getting it onto a server     read before deploying
├── 30-operations/    keeping it running           read when something breaks
├── 40-reference/     look-up material             read when you need a fact
└── 90-decisions/     why it is the way it is      read when you disagree with it
```

Numbered so the reading order is obvious and so the folders sort usefully. `00` and `10` are for
people building; `20` and `30` are for people running; `40` and `90` are consulted, not read.

---

## Keeping this true

Documentation that is not maintained is worse than none, because it is believed. Two mechanisms
keep this honest.

**The rule.** Change the code, update the doc that owns it, *in the same commit*. The table below
says which doc that is.

**The check.** `python3 tools/check_docs.py` fails the build when a documented file no longer
exists, a tool or library is undocumented, an internal link is broken, or a doc quotes a value that
no longer matches the code. It cannot read prose — it catches the mechanical half, which is the
half that rots silently.

### Which doc owns what

| Change this | Update this |
|---|---|
| Add or remove a file in `tools/` | [40-reference/tools.md](40-reference/tools.md) |
| Add or remove a `lib/*.php` | [10-development/server-side/libraries.md](10-development/server-side/libraries.md) |
| Add a section to `ADMIN_SECTIONS` | [10-development/server-side/adding-an-editor.md](10-development/server-side/adding-an-editor.md) |
| Add or remove a page under `pages/` | [00-orientation/repository-map.md](00-orientation/repository-map.md) |
| Change a field in `content/*.json` | [40-reference/content-schemas.md](40-reference/content-schemas.md) |
| Change anything a search engine reads | [40-reference/seo.md](40-reference/seo.md) |
| Change a constant in `lib/auth.php`, `lib/reset.php`, `lib/throttle.php` | [10-development/server-side/authentication.md](10-development/server-side/authentication.md) and [40-reference/security-model.md](40-reference/security-model.md) |
| Change `public/.htaccess` | [40-reference/security-model.md](40-reference/security-model.md) |
| Change the deploy procedure | [20-deployment/](20-deployment/) |
| Change a CSS or JS convention | [10-development/server-side/](10-development/server-side/) |
| Make a decision that constrains future work | a new file in [90-decisions/](90-decisions/) |
| Discover a new failure mode | [30-operations/troubleshooting.md](30-operations/troubleshooting.md) |

---

## A note on the two repositories

The project is split. **`tech4time-website-backend`** is this one, serving `admin.tech4time.bd` and owning
the content; **`tech4time-website-frontend`** serves `tech4time.bd` and renders a replica it is sent.

Every document opens with an **Applies to:** line — `frontend`, `backend`, or `both` — which is what
made the split a move rather than a rewrite. A document marked `both` exists in both repositories
and describes each half from its own side.

**A path in backticks always means *this* repository.** A file in the other half is written with the
repository name in front: `tech4time-website-frontend/pages/careers/index.php`. `tools/check_docs.py`
enforces it, so the two can never be confused in prose.

The decision records are numbered for the project, not for this repository, so
[90-decisions/](90-decisions/) has gaps where a record belongs to the other half. Each gap names
where it went.

See [90-decisions/0011-two-repositories.md](90-decisions/0011-two-repositories.md),
[0017](90-decisions/0017-two-private-stores.md) and
[0018](90-decisions/0018-the-backend-serves-from-a-subdirectory.md).
