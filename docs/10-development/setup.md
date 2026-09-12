# Setting up, from nothing

**Applies to:** both

Assumes a clean machine and no knowledge of the project. About twenty minutes, most of it waiting
for packages.

---

## 1. What you need

Four things, and one of them is optional.

| | Why | Needed for |
|---|---|---|
| **PHP 8.1+** (CLI) | four pages are PHP | running the site at all |
| **Python 3.10+** | every tool and test is Python | the checks |
| **Firefox + geckodriver** | the browser suites drive a real browser | the browser tests only |
| **Pillow** | image processing | the asset builders only |

**There is nothing else.** No Node, no Composer, no `npm install`, no virtualenv required. The
Python tools are standard library apart from Pillow, and the browser tests talk to geckodriver over
its wire protocol with `urllib` — there is no Selenium to install.

### Debian / Ubuntu

```bash
sudo apt update
sudo apt install -y php-cli python3 python3-pip firefox

# geckodriver is not in apt on most releases — take the release binary
GV=v0.36.0
curl -fsSL "https://github.com/mozilla/geckodriver/releases/download/${GV}/geckodriver-${GV}-linux64.tar.gz" \
  | sudo tar -xz -C /usr/local/bin
geckodriver --version

# only if you will regenerate images, favicons or the OG card
pip3 install --user Pillow
```

### macOS

```bash
brew install php python firefox geckodriver
pip3 install --user Pillow
```

### Check

```bash
php --version          # 8.1 or newer
python3 --version      # 3.10 or newer
geckodriver --version  # optional
firefox --version      # optional
```

> **Why PHP 8.1:** the code uses the `never` return type. It was developed against 8.3, which is
> what the host runs. Anything older than 8.1 will not parse.

---

## 2. Clone

```bash
git clone <repository-url> tech4time-website-backend
cd tech4time-website-backend
git checkout dev
```

Work happens on `dev`. `main` is what gets deployed, and pull requests to it need explicit approval.
Afterwards `main` is merged back into `dev`, which fast-forwards and leaves the two branches
identical -- see [ci-cd.md](../20-deployment/ci-cd.md#the-deploy-workflow).

---

## 3. Run it

```bash
python3 tools/serve.py
# → http://localhost:8000
```

That is the whole setup. There is nothing to build and no dependency to fetch.

> **Do not use `python3 -m http.server`.** It will show you the *source* of the careers page, the
> the editors instead of their output. `serve.py` runs PHP's built-in server with a
> router that resolves the same clean URLs Apache does on the host.

Open the site and click around. Every page should work.

---

## 4. Create an admin account

The sign-in is real locally — nothing is faked, because on the host there is nothing to fake it
against any more.

1. Go to **http://localhost:8001/setup.php**
2. Fill in a username, an email and a password.
   *No setup key is asked for*, because the request comes from your own machine. On the host it is
   required — see [admin-activation.md](../20-deployment/admin-activation.md).
3. Pair an authenticator app. Type the grouped key into Google Authenticator, Aegis, 1Password,
   Bitwarden — anything that does TOTP — then enter a code to prove it works.
4. **Save the ten recovery codes.** They are shown once. Locally they matter more than on the host,
   because a password reset email cannot be delivered on a machine with no mail server.

You now have `../t4t-private-admin/` beside your clone, holding your account.

```
CodeSpace/
├── tech4time-website-backend/       ← your clone
├── tech4time-website-frontend/      ← the other half, worth cloning beside it
└── t4t-private-admin/       ← created on first run. NEVER commit it
└── t4t-private-admin/           ← your local secrets, never committed
```

Delete that directory to start over.

---

## 5. Prove it works

```bash
python3 tools/check_contrast.py
python3 tools/check_shared_markup.py
python3 tools/audit_pages.py
python3 tools/check_content_model.py
python3 tools/check_secrets.py
python3 tools/check_docs.py
```

All should pass and none needs a browser. Then, if you installed Firefox and geckodriver:

```bash
python3 tools/test_admin_auth.py       # the whole sign-in cycle, over HTTP
python3 tools/test_contact_handler.py  # the enquiry form's handler
python3 tools/test_nav.py              # a real browser
```

`test_admin_auth.py` uses a throwaway private directory under `/tmp`, so it cannot disturb the
account you just made.

> **Housekeeping:** the browser suites can leave `geckodriver` processes behind if a run is
> interrupted. `pkill firefox geckodriver` clears them.

---

## 6. Read these next

- [running-locally.md](running-locally.md) — the dev server in detail, and what cannot work locally
- [where-to-change-things.md](where-to-change-things.md) — the file that owns the thing you want to change
- [testing.md](testing.md) — what each check proves

---

## If something went wrong

| Symptom | Cause |
|---|---|
| Pages show PHP source | You used `python3 -m http.server`. Use `tools/serve.py`. |
| `php: command not found` | `php-cli` is not installed — step 1 |
| Assets 404, page unstyled | You opened the file over `file://`. Every path is root-relative; use the server. |
| The admin refuses to load | The private store cannot be created. The message names the path — check permissions on the directory *above* your clone. |
| Browser tests skip with a notice | Firefox or geckodriver is missing. That is by design — they exit 0 rather than fail. |
| `ModuleNotFoundError: PIL` | Only the asset builders need Pillow: `pip3 install --user Pillow` |

More: [troubleshooting.md](../30-operations/troubleshooting.md).
