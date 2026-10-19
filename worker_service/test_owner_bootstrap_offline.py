#!/usr/bin/env python3
"""Dispara testes PHP de bootstrap do administrador (processo isolado por caso)."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


class OwnerBootstrapOfflineTest(unittest.TestCase):
    def test_php_owner_bootstrap_suite(self) -> None:
        root = Path(__file__).resolve().parent.parent
        script = root / "tests" / "owner_bootstrap_offline.php"
        if not script.is_file():
            self.skipTest("tests/owner_bootstrap_offline.php ausente")
        proc = subprocess.run(
            ["php", str(script), "all"],
            capture_output=True,
            text=True,
            timeout=120,
            cwd=str(root),
            check=False,
        )
        if proc.returncode != 0:
            self.fail(
                "owner_bootstrap_offline falhou\n"
                f"stdout:\n{proc.stdout}\nstderr:\n{proc.stderr}"
            )
        self.assertIn("bootstrap_enabled_creates_user=OK", proc.stdout)


if __name__ == "__main__":
    unittest.main()
