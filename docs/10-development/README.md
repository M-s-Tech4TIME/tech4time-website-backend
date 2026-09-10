# Development

**Applies to:** both

You have just been handed this project. This page is the first hour.

---

## The first hour

1. **Ten minutes** — [00-orientation/README.md](../00-orientation/README.md). What this is and why
   it has no build step.
2. **Twenty minutes** — [setup.md](setup.md). Install four things, clone, serve, create an admin
   account. You will have the site running.
3. **Ten minutes** — [00-orientation/architecture.md](../00-orientation/architecture.md). How a
   request is served and where the data lives.
4. **Twenty minutes** — [where-to-change-things.md](where-to-change-things.md). Skim it. You do not
   need to remember it; you need to know it exists, because it is the page that answers "which file
   do I open?"

Then make a change and run [the checks](testing.md).

---

## The five things that will surprise you

**There is no build step.** Edit a file, refresh the browser. No watcher, no compile, no install.

**`python3 -m http.server` is not enough.** Four things need PHP: the careers page, the contact page,
the admin, and the contact form's handler. Use `python3 tools/serve.py`.

**The admin sign-in is real, locally too.** Nothing is faked. Visit `/setup.php` once, create
an account, pair an authenticator app. See [running-locally.md](running-locally.md).

**Your secrets live outside the repository**, at `../t4t-private-admin`, beside your clone — the same
shape as `/home/USER/t4t-private-admin` on the host, so nothing about the layout differs in development.

**The public site's header, footer and dock are emitted, not copied.** `tech4time-website-frontend/lib/body.php` renders them
from `content/chrome.json` on the request, and every link, label and contact row in them is edited
here, at `/?s=chrome` — **a screen that is not built yet**, so until it is, that document is what
the public site shipped with. What is still copied into every page is the hero circuit and the
script tags.
*shared-markup.md* (in tech4time-website-frontend)

---

## In this section

| | |
|---|---|
| [setup.md](setup.md) | from nothing to a running site |
| [running-locally.md](running-locally.md) | the dev server, signing in, what cannot work locally |
| [where-to-change-things.md](where-to-change-things.md) | **"I want to change X" → the file that owns it** |
| [testing.md](testing.md) | every check, what it proves, how to read a failure |
| [server-side/](server-side/) | CSS, JavaScript, motion, icons, shared markup, adding a page |
| [backend/](server-side/) | the libraries, the content model, editors, authentication |

---

## Before you commit

```bash
python3 tools/check_contrast.py        # WCAG AA, both modes
python3 tools/inject_icons.py --check  # every page's icon block is current
python3 tools/check_shared_markup.py   # no header/footer has drifted
python3 tools/check_content_model.py   # model, form and renderer still agree
python3 tools/check_secrets.py         # nothing secret committed, no protection removed
python3 tools/check_docs.py            # the docs still describe the code
python3 tools/audit_pages.py           # SEO, accessibility, structure, links
```

What each proves, and which of the browser suites to run when: [testing.md](testing.md).
