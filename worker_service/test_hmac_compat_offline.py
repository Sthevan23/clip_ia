#!/usr/bin/env python3
"""
Testes offline de compatibilidade HMAC PHP ↔ Python (sem HTTP, sem segredos reais).
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import subprocess
from pathlib import Path
import time
import unittest
from unittest.mock import patch

from clipia_worker.config import (
    _DEV_HMAC_FALLBACK,
    _resolve_worker_shared_secret,
)
from clipia_worker.security import build_hmac_headers


class HmacCompatOfflineTest(unittest.TestCase):
    def test_signing_payload_timestamp_dot_raw_body(self) -> None:
        """Mesma formula que ExternalWorkerClient::computeSignature no PHP."""
        secret = "offline-test-secret-min-16-chars"
        raw_body = b'{"action":"claim_next_job"}'
        ts = str(int(time.time()))
        signing = ts.encode("utf-8") + b"." + raw_body
        expected = hmac.new(secret.encode("utf-8"), signing, hashlib.sha256).hexdigest()
        headers = build_hmac_headers(secret, raw_body)
        self.assertEqual(headers["X-ClipIA-Timestamp"], ts)
        self.assertEqual(headers["X-ClipIA-Signature"], expected)

    def test_signature_matches_php_hash_hmac_cli(self) -> None:
        """Interoperabilidade offline: hash_hmac PHP == HMAC Python (sem HTTP)."""
        secret = "offline-test-secret-min-16-chars"
        raw_body = b'{"action":"claim_next_job"}'
        ts = "1700000000"
        signing_string = ts + "." + raw_body.decode("utf-8")
        py_sig = hmac.new(
            secret.encode("utf-8"),
            signing_string.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        php_script = (
            "echo hash_hmac('sha256', "
            + json.dumps(signing_string)
            + ", "
            + json.dumps(secret)
            + ");"
        )
        proc = subprocess.run(
            ["php", "-r", php_script],
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        if proc.returncode != 0:
            self.skipTest("php CLI indisponivel para teste PHP/Python HMAC")
        self.assertEqual(proc.stdout.strip(), py_sig)

    def test_build_hmac_headers_format(self) -> None:
        secret = "offline-test-secret-min-16-chars"
        headers = build_hmac_headers(secret, b'{"action":"ping"}')
        self.assertTrue(headers["X-ClipIA-Timestamp"].isdigit())
        self.assertRegex(headers["X-ClipIA-Signature"], r"^[0-9a-f]{64}$")

    def test_rejects_short_secret(self) -> None:
        with self.assertRaises(ValueError):
            build_hmac_headers("too-short", b"{}")

    def test_signature_changes_with_body(self) -> None:
        secret = "offline-test-secret-min-16-chars"
        h1 = build_hmac_headers(secret, b'{"a":1}')
        h2 = build_hmac_headers(secret, b'{"a":2}')
        self.assertNotEqual(h1["X-ClipIA-Signature"], h2["X-ClipIA-Signature"])


class WorkerSharedSecretResolveTest(unittest.TestCase):
    def test_empty_dotenv_value_remote_backend_stays_empty(self) -> None:
        with patch.dict(
            os.environ,
            {
                "CLIPIA_WORKER_SHARED_SECRET": "",
                "CLIPIA_BACKEND_API_URL": "https://clipia.sthevandev.com.br/pages/worker-api.php",
            },
            clear=False,
        ):
            resolved = _resolve_worker_shared_secret(
                "https://clipia.sthevandev.com.br/pages/worker-api.php"
            )
        self.assertEqual(resolved, "")

    def test_empty_dotenv_value_local_backend_uses_dev_fallback(self) -> None:
        with patch.dict(os.environ, {"CLIPIA_WORKER_SHARED_SECRET": ""}, clear=False):
            resolved = _resolve_worker_shared_secret(
                "http://127.0.0.1:8099/pages/worker-api.php"
            )
        self.assertGreaterEqual(len(resolved), 16)

    def test_dev_secret_on_remote_backend_is_rejected(self) -> None:
        remote = "https://clipia.sthevandev.com.br/pages/worker-api.php"
        with patch.dict(
            os.environ,
            {"CLIPIA_WORKER_SHARED_SECRET": _DEV_HMAC_FALLBACK},
            clear=False,
        ):
            resolved = _resolve_worker_shared_secret(remote)
        self.assertEqual(resolved, "")

    def test_php_production_blocklist_rejects_dev_secret_offline(self) -> None:
        """Política PHP clipia_is_disallowed_worker_secret_for_production (sem HTTP)."""
        loader = Path(__file__).resolve().parent.parent / "config" / "clipia_env_loader.php"
        if not loader.is_file():
            self.skipTest("clipia_env_loader.php nao encontrado")
        php_body = (
            "require "
            + json.dumps(str(loader))
            + ";"
            + "$dev = "
            + json.dumps(_DEV_HMAC_FALLBACK)
            + ";"
            + "$ok = 'segredo-forte-de-producao-com-32-bytes';"
            + "echo (clipia_is_disallowed_worker_secret_for_production($dev) ? '1' : '0')"
            + " . (clipia_is_disallowed_worker_secret_for_production($ok) ? '1' : '0');"
        )
        proc = subprocess.run(
            ["php", "-r", php_body],
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        if proc.returncode != 0:
            self.skipTest("php CLI indisponivel para politica de producao")
        self.assertEqual(proc.stdout.strip(), "10")

    def test_php_verify_incoming_blocks_dev_and_short_in_production(self) -> None:
        """ExternalWorkerClient::verifyIncomingRequest em APP_ENV=production (sem HTTP)."""
        root = Path(__file__).resolve().parent.parent
        php_body = (
            "chdir("
            + json.dumps(str(root))
            + ");"
            + "putenv('APP_ENV=production');"
            + "$_ENV['APP_ENV']='production';"
            + "require 'includes/worker_client.php';"
            + "$dev="
            + json.dumps(_DEV_HMAC_FALLBACK)
            + ";"
            + "$rDev=ExternalWorkerClient::verifyIncomingRequest([],'{}',$dev);"
            + "$rShort=ExternalWorkerClient::verifyIncomingRequest([],'{}','short');"
            + "echo ((!$rDev['ok']&&($rDev['code']??0)===503)?'1':'0')"
            + ".((!$rShort['ok']&&($rShort['code']??0)===503)?'1':'0');"
        )
        proc = subprocess.run(
            ["php", "-r", php_body],
            capture_output=True,
            text=True,
            timeout=20,
            check=False,
        )
        if proc.returncode != 0:
            self.skipTest("php CLI indisponivel para verifyIncomingRequest")
        self.assertEqual(proc.stdout.strip(), "11")


if __name__ == "__main__":
    unittest.main()
