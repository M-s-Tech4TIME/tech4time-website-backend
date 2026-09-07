# Decision records

**Applies to:** both

One file per decision that constrains future work — the kind somebody arriving cold would otherwise
re-litigate, or reverse without knowing what it was holding up.

Each is short and has the same three parts:

- **Decision** — what was decided
- **Context** — what made it necessary
- **Consequences** — what it costs, and what it now forbids

A decision recorded here is not permanent. It is *deliberate*. If the context has changed, write a
new record superseding the old one rather than quietly doing the opposite.

---

| | | |
|---|---|---|
| [0001](0001-no-build-step.md) | No build step, no framework | accepted |
| [0002](0002-no-database.md) | No database — flat JSON files | accepted |
| [0003](0003-server-rendered-content.md) | Content renders on the server, never by `fetch()` | accepted |
| [0004](0004-self-hosted-strict-csp.md) | Self-hosted assets and a strict CSP | accepted |
| [0005](0005-own-authentication.md) | Own authentication, not Directory Privacy | accepted |
| [0006](0006-argon2id-with-pepper.md) | argon2id over a peppered pre-hash | accepted |
| [0007](0007-totp-not-email-otp.md) | TOTP as the second factor, email only for recovery | accepted |
| [0008](0008-private-store-outside-docroot.md) | The private store lives outside the document root | accepted |
| [0009](0009-setup-token.md) | A setup token closes the bootstrap window | accepted |
| [0010](0010-backend-pushes-content.md) | The backend pushes content; the frontend never fetches | accepted, **not built** |
| [0011](0011-two-repositories.md) | Two repositories, two hosts | accepted, **not built** |
| *0012* (in tech4time-website-frontend) | Motion may decorate, never gate | accepted |
| [0013](0013-damaged-stores-refuse.md) | A damaged store refuses; it never looks empty | accepted |
| [0014](0014-derived-secrets-name-their-key.md) | A value derived from the master key carries the key's name | accepted |
| [0015](0015-narrow-widths-need-a-frame.md) | Narrow widths are tested in a frame, not in the window | accepted |
| [0016](0016-a-deploy-protects-what-the-panel-owns.md) | A deploy protects what the panel owns | accepted |
| [0017](0017-two-private-stores.md) | Two private stores, one per half | accepted |
| [0018](0018-the-backend-serves-from-a-subdirectory.md) | The backend serves from a subdirectory | accepted |
| [0019](0019-uploaded-images-travel-their-own-channel.md) | Uploaded images travel their own signed channel | accepted |
| [0020](0020-page-metadata-is-content.md) | A page's metadata is that page's content, and one screen edits all of it | accepted |
| [0021](0021-analytics-is-off-until-somebody-turns-it-on.md) | Google Analytics is a field, and the policy opens only while it holds one | accepted |
| [0022](0022-form-properties-are-read-off-the-prototype.md) | A form's properties are read off the prototype, never off the form | accepted |
