# 0001 — No build step, no framework

**Status:** accepted · **Applies to:** both

## Decision

Plain HTML, CSS and JavaScript. No bundler, no transpiler, no package manager, no framework. The
files in the repository are the files that run on the server.

## Context

The site is deployed by uploading files to cPanel shared hosting. The company's earlier site was a
NextJS application, and maintaining it required a working Node toolchain — which meant that fixing a
typo, three years from now, would begin with resurrecting a build environment.

The site is sixteen mostly-static pages. Almost none of what a framework provides is needed.

## Consequences

**Good.** Deployment is a file copy. There is no dependency tree to audit, no lockfile to update, no
build to break. Anyone with an editor can fix a typo. Onboarding is `git clone` and a server.

**Costs.** No component reuse *in the browser*. What is shared between pages is composed on the
server instead — `tech4time-website-frontend/lib/head.php` for the `<head>`,
`tech4time-website-frontend/lib/body.php` for the header, footer and dock —
which is not a build step: it runs on the request, and the page a visitor receives is still one
complete document. What genuinely cannot be composed that way, like the hero circuit's SVG, is
copied into every page and mitigated by `tools/templates/` and `check_shared_markup.py`. No asset
hashing, so cache busting is manual. No minification.

**Forbids.** Adding any dependency that requires a build step. If a tool is genuinely needed, it goes
in `tools/` as a Python script that a developer runs and whose *output* is committed — that is what
the icon sprite, the images and the shared markup already do.
