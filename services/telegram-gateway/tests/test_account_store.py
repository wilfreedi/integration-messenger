from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from app.gateway import AccountRecord, AccountStore


class AccountStoreTest(unittest.TestCase):
    def test_roundtrip_save_and_load(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            storage_path = Path(directory) / "accounts.json"
            store = AccountStore(storage_path)
            records = {
                "acc-1": AccountRecord(
                    account_id="acc-1",
                    phone_number="+79990000001",
                    manager_account_external_id="manager-1",
                    created_at="2026-04-07T10:00:00+00:00",
                )
            }

            store.save(records)
            loaded = store.load()

            self.assertIn("acc-1", loaded)
            self.assertEqual("+79990000001", loaded["acc-1"].phone_number)
            self.assertEqual("manager-1", loaded["acc-1"].manager_account_external_id)

    def test_load_ignores_invalid_entries(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            storage_path = Path(directory) / "accounts.json"
            storage_path.write_text(
                """
{
  "accounts": [
    {"account_id":"acc-ok","phone_number":"+7","manager_account_external_id":"m1","created_at":"2026"},
    {"account_id":"","manager_account_external_id":"m2","created_at":"2026"},
    {"account_id":"acc-bad","manager_account_external_id":"","created_at":"2026"}
  ]
}
""".strip(),
                encoding="utf-8",
            )
            store = AccountStore(storage_path)

            loaded = store.load()

            self.assertEqual(["acc-ok"], sorted(loaded.keys()))


if __name__ == "__main__":
    unittest.main()
