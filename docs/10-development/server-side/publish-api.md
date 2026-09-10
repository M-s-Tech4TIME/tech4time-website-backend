# The publish API

**Applies to:** both

How content gets from `admin.tech4time.bd` to `tech4time.bd`. This is the only route it takes, and
the only endpoint on the public site that writes anything.

Decisions behind it: [0010 — the backend pushes content](../../90-decisions/0010-backend-pushes-content.md)
and [0011 — two repositories](../../90-decisions/0011-two-repositories.md).

---

## The shape of it

```
SAVE     admin writes its own record  →  POST (signed)  →  frontend verifies,
                                                            re-sanitises, writes
VISIT    visitor  →  frontend reads its local file  →  renders
                                        ✗ never calls the backend
```

The backend's copy is the **system of record** and is written **first**. If the push then fails, the
edit is safe and can be sent again. Publishing first would mean a live site ahead of the record it
replicates.

The public site never calls the backend during a request — not for content, not for the header, not
for anything. That is [0003](../../90-decisions/0003-server-rendered-content.md) and
[0010](../../90-decisions/0010-backend-pushes-content.md), and it is why the admin host going down
does not take the public site's content with it.

---

## On the wire

```http
POST https://tech4time.bd/api/publish.php
Content-Type:     application/json; charset=utf-8
X-T4T-Timestamp:  1756199643
X-T4T-Signature:  4d3f0075b40b7fb4:9a1c…64 hex chars…
```

```json
{
  "contract_version": 1,
  "document": "careers",
  "revision": 12,
  "published": "2026-08-26T09:14:03+00:00",
  "data": { "…the whole document…": "" }
}
```

The signed string is `"<timestamp>.<body>"` — the timestamp **inside** the signature, not merely
beside it, or moving it would cost an attacker nothing.

**The URL carries its `.php`.** [0010](../../90-decisions/0010-backend-pushes-content.md) wrote it as
`/api/publish`; it is the plain path instead, so the one route content travels does not depend on a
rewrite rule. `public/.htaccess` is not read by the local dev server, would have to be reproduced on any
server this moves to, and is the single file most likely to arrive damaged or not at all.

### What comes back

```json
{"ok": true,  "document": "careers", "revision": 12}
{"ok": false, "code": "not-newer", "error": "The live site already holds…", "revision": 12}
```

Every answer the caller is **entitled to** carries the revision the live site now holds — the
refusals as well as the acceptance. That is what lets `reconcile.py` work without a second endpoint:
an attempt *is* the question, and an attempt refused as `not-newer` has changed nothing.

A stranger gets the refusal and nothing about what is here: `revision` is withheld until the
signature has verified.

---

## The four checks, and why none of them replaces another

| check | answers | if it were the only one |
|---|---|---|
| **signature** | this came from something holding the key | a compromised backend still signs perfectly, so this alone lets it write script to the public site |
| **timestamp** | it was sent in the last five minutes | a captured request stays useful forever |
| **revision** | it is strictly newer than what is here | **a replay inside the window is signed perfectly well** — this is the only thing that makes it a no-op rather than a rollback |
| **`contract_version`** | this side implements the shape it is written in | a document gets written in a shape this side then mis-renders |

Five minutes either way, because the two clocks are on different machines and a window shorter than
their drift is an outage rather than a defence.

**And then it re-sanitises.** A signature proves origin, not safety. Every rich field goes back
through the frontend's own `html.php` before it is written, so a compromised admin host still cannot
put a `<script>` on the public site. `contract_sanitise()` drives that off `CAREERS_RICH_FIELDS` /
`CONTACT_RICH_FIELDS`, so a rich field added to the contract is covered by having been added.

### Every refusal

| code | HTTP | means |
|---|---|---|
| `method-not-allowed` | 405 | not a POST — what a browser gets, and what `verify_live.py` asserts |
| `too-large` | 413 | past `PUBLISH_MAX_BYTES` (1 MB) |
| `not-configured` | 503 | no `publish.key`, or the private store is unusable, **on the live site** |
| `no-signature` | 401 | no headers at all |
| `bad-signature-format` / `bad-timestamp-format` | 401 | headers malformed |
| `unknown-key` | 401 | **the two stores hold different keys** |
| `stale-timestamp` | 401 | the clocks disagree by more than five minutes |
| `bad-signature` | 401 | the body changed after signing |
| `bad-json` / `bad-document` / `bad-revision` | 400 | the envelope is not readable |
| `revision-mismatch` | 400 | the envelope and the document disagree about the revision |
| `unknown-document` | 400 | not in `CONTRACT_DOCUMENTS` |
| `contract-mismatch` | 422 | **the two repositories are out of step** — deploy both |
| `not-newer` | 409 | the live site already holds this revision or later. Nothing changed |
| `write-failed` | 500 | the live site could not write the file |

Two more are produced by the client rather than the endpoint: `unreachable` (network) and
`bad-answer` (a reply that was not JSON — usually a host error page, meaning the endpoint is not
deployed).

`unknown-key` is a separate code from `bad-signature` on purpose. Every signature carries the key's
16-hex-character fingerprint, per
*0014* (in tech4time-website-backend), so the live site can say *"that
is not the key I have"* rather than *"wrong signature"*. The two send you to completely different
places.

---

## The key

`publish.key` in the private store: 32 random bytes as 64 hex characters, and **the same bytes on
both hosts**.

```
frontend   /home/USER/t4t-private/publish.key
backend    /home/USER/t4t-private-admin/publish.key
```

**It is never derived from `secret.key`.** The two halves have separate stores and separate master
keys, so anything derived would differ by construction and every publish would be refused. It would
also mean *rotating the master key* (in tech4time-website-backend) silently broke
publishing.

**It is never created on demand either**, which is the one place this differs from every other
secret in the project. A key that appears by itself appears *differently* on each host, and the
failure reads as "signature rejected" for as long as it takes somebody to think of it. Both sides
refuse to start without one and say what to do:

```bash
python3 tools/make_publish_key.py       # on one side; copy the printed value to the other
```

Rotating it means writing the new value on **both** hosts. Between the two writes, every publish is
refused as `unknown-key` — which is visible, recoverable, and much better than the alternative.

---

## Revisions

A count, not a clock. `contract_next_revision()` gives one past whatever is on file; the backend
mints it inside `careers_save()` / `contact_save()` rather than at a call site, because six call
sites are six chances to forget, and a save that forgot to advance it is a save the live site
refuses as stale — silently, from the operator's point of view.

The live site accepts **strictly greater** and refuses everything else.

On a document that has never carried one, `revision` normalises to `0`, so the first save mints `1`
and is accepted. Nothing needs migrating.

---

## When it fails

**In the editor.** The section says so, with the sentence from the table above and a *Publish again*
control. Never a silent gap: a save that appeared to work and did not reach the site is the one
failure nobody investigates, because nothing asked them to.

**Out of band**, for the times nobody was watching:

```bash
# uploaded to the host and run there — tools/ is never deployed, and it reads
# THAT machine's content/ and THAT machine's private store
python3 ~/reconcile.py ~/admin.tech4time.bd            # every document
python3 ~/reconcile.py ~/admin.tech4time.bd careers    # one of them
```

It reports one of four things per document — sent, in step, **the live site is ahead**, or the
failure. The third is the one to stop at: something published from elsewhere, or this host's record
was restored from an older backup, and a person has to decide which copy is right.

---

## Nothing travels back the other way

The answer to a publish is `{"ok": true, "document": "about", "revision": 12}` and nothing else.

**There used to be a fingerprint in it.** The public site's footers repeated the contact details as
literal markup in all sixteen pages, so they went stale the moment an address was edited here and
stayed stale until those pages were rebuilt and deployed. Only the frontend knew what its own
footers said, so it reported a digest of them in every publish response, `contact_save()` recorded
what it was told with a second `store_write()`, and the editor drew a banner when the two parted.

The `<head>` shed the same problem first — `seo_graph()` builds the Organization graph from
`content/contact.json` on the request, so the structured data is right the moment the publish lands
([ADR 0020](../../90-decisions/0020-page-metadata-is-content.md)). The footer shed it next: it
renders from `tech4time-website-frontend/content/chrome.json`, its contact rows are the footer's own
and are edited on the **Header & Footer** screen, and what keeps them honest is a notice drawn from
two documents this side holds rather than a digest carried over the wire
([ADR 0023](../../90-decisions/0023-the-header-and-footer-are-emitted-once.md)).

So `footer-fingerprint.php`, `contact_fingerprint()`, `contact_footer_in_step()`, the
`footer_synced` bookkeeping field and the second write that recorded it are all deleted. The wire
is one-way again.

---

## Proving it

```bash
python3 tools/test_publish.py          # 400+ checks, over real HTTP with real signatures
```

It drives the real endpoint and then tries every way past it that does not involve holding the key:
no signature, another key's signature, a tampered body, an old timestamp, a replay, a lower
revision, a different contract version, and a `<script>` from a sender that signed correctly.

The last two are the ones a signature does not answer, which is why they are checked separately. Both
guards have been watched to fail: removing the revision check breaks five of them, removing the
re-sanitise breaks two.

```bash
python3 tools/check_shared_lib.py      # the three shared files are as recorded
python3 tools/verify_live.py <url>     # /api/publish.php answers 405 to GET
```

`check_shared_lib.py` is **hygiene, not a guarantee** — each repository compares its own files
against its own committed digest, so a deliberate edit plus `--update` passes on both sides while
they hold different code. The guarantee is `contract_version`, checked at run time by the side that
would suffer from the mismatch.
