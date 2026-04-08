from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class GatewayConfig:
    api_id: str
    api_hash: str
    manager_account_external_id: str
    default_crm_provider: str
    gateway_port: int
    core_webhook_url: str
    core_webhook_token: str
    tdjson_lib_path: str
    tdlib_db_dir: str
    tdlib_files_dir: str
    log_level: str
    sync_manager_outgoing: bool

    @property
    def is_tdlib_configured(self) -> bool:
        return self.api_id.strip() != "" and self.api_hash.strip() != ""

    @classmethod
    def from_env(cls) -> "GatewayConfig":
        return cls(
            api_id=os.getenv("TELEGRAM_API_ID", "").strip(),
            api_hash=os.getenv("TELEGRAM_API_HASH", "").strip(),
            manager_account_external_id=os.getenv(
                "TELEGRAM_MANAGER_ACCOUNT_EXTERNAL_ID",
                "telegram-manager-account",
            ).strip(),
            default_crm_provider=os.getenv("TELEGRAM_DEFAULT_CRM_PROVIDER", "bitrix").strip(),
            gateway_port=int(os.getenv("TELEGRAM_GATEWAY_PORT", "8090")),
            core_webhook_url=os.getenv(
                "TELEGRAM_GATEWAY_CORE_WEBHOOK_URL",
                "http://app:8080/api/webhooks/channel-message",
            ).strip(),
            core_webhook_token=os.getenv("TELEGRAM_GATEWAY_CORE_WEBHOOK_TOKEN", "").strip(),
            tdjson_lib_path=os.getenv(
                "TELEGRAM_GATEWAY_TDJSON_LIB_PATH",
                "/usr/local/lib/libtdjson.so",
            ).strip(),
            tdlib_db_dir=os.getenv("TELEGRAM_GATEWAY_TDLIB_DB_DIR", "/data/tdlib").strip(),
            tdlib_files_dir=os.getenv("TELEGRAM_GATEWAY_TDLIB_FILES_DIR", "/data/files").strip(),
            log_level=os.getenv("TELEGRAM_GATEWAY_LOG_LEVEL", "INFO").strip() or "INFO",
            sync_manager_outgoing=os.getenv("TELEGRAM_GATEWAY_SYNC_MANAGER_OUTGOING", "1").strip().lower()
            not in {"0", "false", "no", "off"},
        )
