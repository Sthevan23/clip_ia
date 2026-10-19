#!/usr/bin/env python3
"""Testes offline de regras de seguranca do worker (sem HTTP, sem arquivos reais de producao)."""
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from clipia_worker.security import (
    UnauthorizedSourceError,
    redact_sensitive_text,
    validate_authorized_local_media_file,
    validate_backend_api_url,
)


class BackendUrlValidationTest(unittest.TestCase):
    def test_remote_http_blocked(self) -> None:
        with self.assertRaises(PermissionError):
            validate_backend_api_url(
                "http://clipia.example.com/pages/worker-api.php",
                require_https_for_remote=True,
            )

    def test_remote_https_allowed(self) -> None:
        url = validate_backend_api_url(
            "https://clipia.sthevandev.com.br/pages/worker-api.php",
            require_https_for_remote=True,
        )
        self.assertIn("worker-api.php", url)

    def test_loopback_http_allowed(self) -> None:
        url = validate_backend_api_url(
            "http://127.0.0.1:8099/pages/worker-api.php",
            require_https_for_remote=True,
        )
        self.assertTrue(url.startswith("http://127.0.0.1"))


class LocalMediaPolicyTest(unittest.TestCase):
    def test_rejects_https_url_as_path(self) -> None:
        with self.assertRaises(UnauthorizedSourceError):
            validate_authorized_local_media_file("https://evil.example/video.mp4")

    def test_accepts_existing_local_file(self) -> None:
        with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp:
            tmp.write(b"\x00\x00\x00\x18ftypmp42")
            tmp_path = tmp.name
        try:
            resolved = validate_authorized_local_media_file(tmp_path)
            self.assertTrue(resolved.is_file())
        finally:
            Path(tmp_path).unlink(missing_ok=True)


class RedactionTest(unittest.TestCase):
    def test_redacts_openai_sk_pattern(self) -> None:
        dirty = "erro com sk-abcdefghijklmnopqrstuvwxyz123456 no log"
        clean = redact_sensitive_text(dirty)
        self.assertNotIn("sk-abcdefghijklmnopqrstuvwxyz123456", clean)
        self.assertIn("REDACTED", clean)


if __name__ == "__main__":
    unittest.main()
