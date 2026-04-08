from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from app.config import GatewayConfig
from app.gateway import GatewayError, TelegramGateway


class TelegramGatewayManagerRebindTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        base = Path(self._tmp.name)
        self._gateway = TelegramGateway(
            GatewayConfig(
                api_id="",
                api_hash="",
                manager_account_external_id="telegram-manager-account",
                default_crm_provider="bitrix",
                gateway_port=8090,
                core_webhook_url="",
                core_webhook_token="",
                tdjson_lib_path="/tmp/libtdjson.so",
                tdlib_db_dir=str(base / "tdlib"),
                tdlib_files_dir=str(base / "files"),
                log_level="INFO",
                sync_manager_outgoing=True,
            )
        )
        self._gateway.start()

    def tearDown(self) -> None:
        self._gateway.stop()
        self._tmp.cleanup()

    def test_update_manager_account_rebinds_existing_account(self) -> None:
        created = self._gateway.create_account(
            phone_number="+79990000001",
            manager_account_external_id="manager-old",
        )
        account_id = created["account"]["account_id"]

        updated = self._gateway.update_manager_account(account_id, "manager-new")

        self.assertEqual("manager-new", updated["account"]["manager_account_external_id"])
        items = self._gateway.list_accounts()
        self.assertEqual("manager-new", items[0]["manager_account_external_id"])

    def test_update_manager_account_rejects_duplicate_manager(self) -> None:
        first = self._gateway.create_account(
            phone_number="+79990000001",
            manager_account_external_id="manager-1",
        )
        second = self._gateway.create_account(
            phone_number="+79990000002",
            manager_account_external_id="manager-2",
        )

        with self.assertRaises(GatewayError):
            self._gateway.update_manager_account(second["account"]["account_id"], "manager-1")

        self.assertEqual("manager-1", first["account"]["manager_account_external_id"])


if __name__ == "__main__":
    unittest.main()
