# Tech4TIME — backend

The editor behind **`admin.tech4time.bd`**: its own sign-in — argon2id, an authenticator app, a
lockout and an audit log — and the content of record for **every page of the public website**. All
seventeen of them render from documents kept here, so a correction anywhere on the site is a save on
this host rather than a redeploy of the other one.

**No framework, bundler or build step.** The files here are the files that run on the server.

**The public site is [`tech4time-website-frontend`](https://github.com/M-s-Tech4TIME/tech4time-website-frontend).**
This half owns the content and pushes a signed copy to it on every save; that half renders from the
replica it is sent and never calls this one during a request.

---

## Quick start

```bash
python3 tools/serve.py          # http://localhost:8001
```

Needs the PHP CLI (`sudo apt install php-cli`). It serves **`public/`**, not the repository — the
same document root the host serves, so a path that escapes it 404s here too.

The sign-in is real locally: visit `/setup.php` once, create an account, pair an authenticator app.

Full setup: **[docs/10-development/setup.md](docs/10-development/setup.md)**

---

## Documentation

Everything is in **[docs/](docs/)**, organised by what you are trying to do.
Start at **[docs/README.md](docs/README.md)**, which routes by intent.

| I want to | |
|---|---|
| Understand this project | [docs/00-orientation/](docs/00-orientation/) |
| Set it up and change things | [docs/10-development/](docs/10-development/) |
| Deploy it | [docs/20-deployment/](docs/20-deployment/) |
| Fix something that broke | [docs/30-operations/](docs/30-operations/) |
| Look up a fact | [docs/40-reference/](docs/40-reference/) |
| Know why it is built this way | [docs/90-decisions/](docs/90-decisions/) |

**The three pages worth reading first:**

- [What this project is](docs/00-orientation/README.md) — ten minutes
- [Where to change things](docs/10-development/where-to-change-things.md) — "I want to change X,
  which file do I open?"
- [The publish API](docs/10-development/server-side/publish-api.md) — how content reaches the
  public site, and what happens when it does not

---

## The shape of it

```
public/                   ← THE DOCUMENT ROOT. Everything a browser may ask for
├── .htaccess               headers only — nothing here keeps anything secret
├── index.php login.php …   six entry points
└── assets/                 css, js, fonts, the icon sprite, flags

lib/                      ← outside it. The sign-in, the contract, the publish client
sections/                 ← outside it. Fifteen screens: ten page editors, three
                            site-wide (chrome, seo, settings), the dashboard and the account
content/                  ← outside it. THE SYSTEM OF RECORD
tools/                    build, audit and test scripts — never deployed
docs/                     the documentation
```

**Three of those four are outside the document root, and that is the design.** `lib/`, `sections/`
and `content/` are not blocked by a rule — no URL maps to them. Delete `public/.htaccess` and the
admin becomes indexable and unhardened; it does not become readable.
[ADR 0018](docs/90-decisions/0018-the-backend-serves-from-a-subdirectory.md)

Not in this repository: **the private store** — password hashes, the master key, authenticator
secrets, sessions, the audit log and `publish.key` — at `/home/USER/t4t-private-admin/`, and
`../t4t-private-admin` locally. Two levels up from the document root, beside the repository rather
than inside it, because the repository is what `rsync --delete` empties.
[ADR 0017](docs/90-decisions/0017-two-private-stores.md)

Full map: [docs/00-orientation/repository-map.md](docs/00-orientation/repository-map.md)

---

## Before committing

```bash
python3 tools/check_contrast.py        python3 tools/check_content_model.py
python3 tools/check_secrets.py         python3 tools/check_docs.py
python3 tools/build_deploy_set.py --check
python3 tools/check_shared_lib.py
```

What each proves, and which tests to run when:
[docs/10-development/testing.md](docs/10-development/testing.md)

---

## Deploying

**A push to `main` deploys it.** `tools/build_deploy_set.py` builds the upload set from an explicit
allow list, CI rsyncs it over SSH to `/home/USER/admin.tech4time.bd/`, and the running admin is then asked
whether `lib/`, `sections/` and `content/` still answer **404** — not 403, which would mean the
document root is pointed one level too high.

**`content/` is never synced.** It is the system of record: seeded once with `--ignore-existing`,
and named in the deploy's protect list.

How it works: [docs/20-deployment/ci-cd.md](docs/20-deployment/ci-cd.md)
Standing the host up: [docs/20-deployment/admin-activation.md](docs/20-deployment/admin-activation.md)

---

## Status

**All seventeen of the public site's pages are editable here** — their copy, their pictures, their
metadata, the header and footer around them, and the colour tokens — as are `robots.txt`, the
sitemap's hints and the web manifest. The admin's own screens are crawled by
`tools/check_admin_a11y.py`, which signs in and walks **twenty-seven** of them; the frontend's
accessibility crawlers still cover only the public site, which is what they are for.
