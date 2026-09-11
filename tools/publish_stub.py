#!/usr/bin/env python3
"""
A stand-in for the public site's api/publish.php, for tests.

Development tool. NOT deployed to the web server (see tools/README.md).
Imported by the tests; not run on its own.

WHY A STUB AND NOT THE REAL ENDPOINT
The real endpoint is in tech4time-website-frontend, and this repository does not have
it. That is not a limitation to work around — it is the point.

If this repository tested its client against the other repository's endpoint,
the two would be checked against each other and neither against the format they
are both supposed to implement. A bug they shared would pass. So each half is
checked against an INDEPENDENT implementation written from the description:
here, the verifier below, in Python; and over there, test_publish.py signs its
requests in Python and posts them to the real PHP endpoint.

Neither side is ever checked against its own counterpart.

WHAT IT IMPLEMENTS
The four checks lib/publish.php describes, in the same order and with the same
codes, plus the monotonic revision. It does NOT re-sanitise — that is the real
endpoint's business and nothing here would be proved by imitating it.

It can also be told to fail on purpose, which is how the editor's "the live site
did not take it" path is exercised without unplugging anything.

TWO ENDPOINTS, TOLD APART BY THEIR PATH. Documents go to /api/publish.php and
pictures to /api/publish-asset.php, and they are different formats: a document
is a signed JSON envelope with a revision, a picture is the signed BYTES with
no envelope at all — content-addressed, so re-sending one is answered 'held'
rather than being anything a replay could undo. This stub answered every POST
as if it were a document, which is why nothing here had ever exercised the
picture half of publish_client.php or of reconcile.py.
"""

import hashlib
import hmac
import json
import re
import threading
import time
from pathlib import Path
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SKEW = 300
MAX_BYTES = 1048576
ASSET_MAX_BYTES = 2097152      # PUBLISH_ASSET_MAX_BYTES in lib/publish.php
CONTRACT_VERSION = 1

# Where each format is posted. lib/publish_client.php derives the second from
# the first by swapping the last path segment, so a stub that answered both at
# one address would prove nothing about that derivation.
ASSET_PATH = "/api/publish-asset.php"

# What the receiving side will accept, by the first bytes of the file rather
# than by anything a caller says it is — the same four publish_asset_type()
# knows. The extension is what the stored name gets, so a wrong one here would
# be a wrong name coming back.
ASSET_KINDS = (
    (b"\x89PNG\r\n\x1a\n", "png"),
    (b"\xff\xd8\xff", "jpg"),
    (b"RIFF", "webp"),                 # with "WEBP" at offset 8; checked below
    (b"<svg", "svg"),
    (b"<?xml", "svg"),
)
def _documents() -> tuple[str, ...]:
    """The document names, read out of lib/contract.php rather than copied.

    A tuple written here is a second list of documents, and the copy that is
    not the contract is the one that goes stale: a new document would be
    refused by this stub with 'unknown-document' while the real endpoint
    accepted it, and the test would report a failure in the wrong half.

    Parsed rather than imported, the way check_shared_repos.py reads
    check_shared_lib.py's SHARED — no PHP process, and a contract mid-edit
    cannot stop this file loading.
    """
    text = (Path(__file__).resolve().parent.parent / "lib" / "contract.php").read_text()
    m = re.search(r"const CONTRACT_DOCUMENTS\s*=\s*\[(.*?)\];", text, re.S)
    if not m:
        raise SystemExit("publish_stub: could not find CONTRACT_DOCUMENTS in lib/contract.php")
    return tuple(re.findall(r"'([a-z_-]+)'", m.group(1)))


DOCUMENTS = _documents()


def fingerprint(key: bytes) -> str:
    return hmac.new(key, b"publish-key-fingerprint", hashlib.sha256).hexdigest()[:16]


class PublishStub:
    """A running endpoint. Use as a context manager.

        with PublishStub(key) as site:
            ...
            site.url                 where to point $T4T_PUBLIC_URL
            site.documents["careers"]  the last accepted payload
            site.revisions["careers"]  what it now holds
            site.received            every request, accepted or not
            site.fail_with = "unreachable" | a code | None
    """

    def __init__(self, key: bytes) -> None:
        self.key = key
        self.documents: dict[str, dict] = {}
        self.revisions: dict[str, int] = {d: 0 for d in DOCUMENTS}
        self.assets: dict[str, bytes] = {}
        self.received: list[dict] = []
        self.fail_with: str | None = None
        self._server: ThreadingHTTPServer | None = None
        self._thread: threading.Thread | None = None

    # ------------------------------------------------------------- the check

    def verify(self, body: bytes, signature: str, timestamp: str) -> str:
        """'' when authentic, or the code lib/publish.php would answer."""
        if not signature.strip() or not timestamp.strip():
            return "no-signature"

        parts = signature.strip().split(":")
        if len(parts) != 2 or len(parts[0]) != 16 or len(parts[1]) != 64:
            return "bad-signature-format"
        if not all(c in "0123456789abcdef" for c in parts[0] + parts[1]):
            return "bad-signature-format"

        if not timestamp.strip().isdigit():
            return "bad-timestamp-format"

        if not hmac.compare_digest(fingerprint(self.key), parts[0]):
            return "unknown-key"

        if abs(int(time.time()) - int(timestamp)) > SKEW:
            return "stale-timestamp"

        want = hmac.new(self.key, f"{int(timestamp)}.".encode() + body,
                        hashlib.sha256).hexdigest()
        if not hmac.compare_digest(want, parts[1]):
            return "bad-signature"

        return ""

    def asset_kind(self, body: bytes) -> str:
        """The extension this file would be stored under, or '' if it is none.

        From the file's own first bytes, never from a Content-Type header —
        which is what the real endpoint does and the only reason a signed POST
        of arbitrary bytes is safe to accept at all.
        """
        for magic, ext in ASSET_KINDS:
            if not body.startswith(magic):
                continue
            if ext == "webp" and body[8:12] != b"WEBP":
                continue
            return ext
        return ""

    def envelope_fault(self, envelope) -> str:
        if not isinstance(envelope, dict):
            return "bad-json"
        if envelope.get("contract_version") != CONTRACT_VERSION:
            return "contract-mismatch"
        if envelope.get("document") not in DOCUMENTS:
            return "unknown-document"
        if not isinstance(envelope.get("data"), dict):
            return "bad-document"
        if int(envelope.get("revision", 0)) < 1:
            return "bad-revision"
        if int(envelope["revision"]) != int(envelope["data"].get("revision", 0)):
            return "revision-mismatch"
        return ""

    # ------------------------------------------------------------ the server

    def _handler(stub):
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def _answer(self, status, body):
                raw = json.dumps(body).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def do_GET(self):
                self.send_response(405)
                self.end_headers()

            def do_POST(self):
                asset = self.path.split("?")[0].endswith(ASSET_PATH)
                cap = ASSET_MAX_BYTES if asset else MAX_BYTES
                length = int(self.headers.get("Content-Length") or 0)
                body = self.rfile.read(min(length, cap + 1))

                stub.received.append({
                    "signature": self.headers.get("X-T4T-Signature", ""),
                    "timestamp": self.headers.get("X-T4T-Timestamp", ""),
                    "body": body,
                    "asset": asset,
                })

                if asset:
                    return self._asset(body, cap)

                if stub.fail_with == "not-json":
                    self.send_response(200)
                    self.send_header("Content-Type", "text/html")
                    self.end_headers()
                    self.wfile.write(b"<html>a host error page</html>")
                    return

                if stub.fail_with:
                    return self._answer(500, {"ok": False, "code": stub.fail_with,
                                              "error": "refused on purpose"})

                if len(body) > MAX_BYTES:
                    return self._answer(413, {"ok": False, "code": "too-large",
                                              "error": "too large"})

                fault = stub.verify(body,
                                    self.headers.get("X-T4T-Signature", ""),
                                    self.headers.get("X-T4T-Timestamp", ""))
                if fault:
                    return self._answer(401, {"ok": False, "code": fault,
                                              "error": fault})

                try:
                    envelope = json.loads(body)
                except ValueError:
                    envelope = None

                fault = stub.envelope_fault(envelope)
                if fault:
                    return self._answer(422 if fault == "contract-mismatch" else 400,
                                        {"ok": False, "code": fault, "error": fault})

                document = envelope["document"]
                held = stub.revisions[document]

                if int(envelope["revision"]) <= held:
                    return self._answer(409, {"ok": False, "code": "not-newer",
                                              "error": "already holds this revision or later",
                                              "revision": held})

                stub.documents[document] = envelope["data"]
                stub.revisions[document] = int(envelope["revision"])

                self._answer(200, {"ok": True, "document": document,
                                   "revision": stub.revisions[document]})

            def _asset(self, body, cap):
                """A picture: signed bytes, no envelope, no revision."""
                if stub.fail_with == "not-json":
                    self.send_response(200)
                    self.send_header("Content-Type", "text/html")
                    self.end_headers()
                    self.wfile.write(b"<html>a host error page</html>")
                    return

                if stub.fail_with:
                    return self._answer(500, {"ok": False, "code": stub.fail_with,
                                              "error": "refused on purpose"})

                if len(body) > cap:
                    return self._answer(413, {"ok": False, "code": "too-large",
                                              "error": "too large"})

                fault = stub.verify(body,
                                    self.headers.get("X-T4T-Signature", ""),
                                    self.headers.get("X-T4T-Timestamp", ""))
                if fault:
                    return self._answer(401, {"ok": False, "code": fault,
                                              "error": fault})

                ext = stub.asset_kind(body)
                if not ext:
                    return self._answer(415, {"ok": False, "code": "not-an-image",
                                              "error": "not a picture this site publishes"})

                # Content-addressed, exactly as publish_asset_name() computes
                # it: the first sixteen hex characters of the SHA-256 of the
                # bytes, and the extension the header said they were.
                name = hashlib.sha256(body).hexdigest()[:16] + "." + ext
                held = name in stub.assets
                stub.assets[name] = body

                self._answer(200, {"ok": True, "asset": name, "held": held})
        return Handler

    def __enter__(self):
        self._server = ThreadingHTTPServer(("127.0.0.1", 0), self._handler())
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)
        self._thread.start()
        return self

    def __exit__(self, *_exc):
        if self._server:
            self._server.shutdown()
            self._server.server_close()

    @property
    def url(self) -> str:
        host, port = self._server.server_address
        return f"http://{host}:{port}"
