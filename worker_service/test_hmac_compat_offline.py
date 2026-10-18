#!/usr/bin/env python3
"""
Testes offline de compatibilidade HMAC PHP ↔ Python (sem HTTP, sem segredos reais).
"""
from __future__ import annotations

import hashlib
import hmac
import time
import unittest

from clipia_worker.security import build_hmac_headers


class HmacCompatOfflineTest(unittest.TestCase):
    def test_signing_payload_timestamp_dot_raw_body(self) -> None:
        secret = "offline-test-secret-min-16-chars"
        raw_body = b'{"action":"claim_next_job"}'
        ts = str(int(time.time()))
        signing = ts.encode("utf-8") + b"." + raw_body
        expected = hmac.new(secret.encode("utf-8"), signing, hashlib.sha256).hexdigest()
        headers = build_hmac_headers(secret, raw_body)
        self.assertEqual(headers["X-ClipIA-Timestamp"], ts)
        self.assertEqual(headers["X-ClipIA-Signature"], expected)

    def test_rejects_short_secret(self) -> None:
        with self.assertRaises(ValueError):
            build_hmac_headers("too-short", b"{}")

    def test_signature_changes_with_body(self) -> None:
        secret = "offline-test-secret-min-16-chars"
        h1 = build_hmac_headers(secret, b'{"a":1}')
        h2 = build_hmac_headers(secret, b'{"a":2}')
        self.assertNotEqual(h1["X-ClipIA-Signature"], h2["X-ClipIA-Signature"])


if __name__ == "__main__":
    unittest.main()
