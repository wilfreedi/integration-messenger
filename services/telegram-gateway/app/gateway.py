from __future__ import annotations

import json
import logging
import queue
import threading
import time
import uuid
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib import error, request

from app.config import GatewayConfig
from app.models import InboundChannelMessageDto
from app.normalizer import TelegramUpdateNormalizer
from app.tdjson import Tdjson


class GatewayError(RuntimeError):
    pass


@dataclass
class PendingResponse:
    event: threading.Event
    response: dict[str, Any] | None = None


@dataclass(frozen=True)
class AccountRecord:
    account_id: str
    phone_number: str
    manager_account_external_id: str
    created_at: str

    @classmethod
    def from_dict(cls, payload: dict[str, Any]) -> "AccountRecord | None":
        account_id = payload.get("account_id")
        manager_account_external_id = payload.get("manager_account_external_id")
        created_at = payload.get("created_at")
        phone_number = payload.get("phone_number", "")
        if not isinstance(account_id, str) or account_id.strip() == "":
            return None
        if not isinstance(manager_account_external_id, str) or manager_account_external_id.strip() == "":
            return None
        if not isinstance(created_at, str) or created_at.strip() == "":
            return None
        if not isinstance(phone_number, str):
            phone_number = ""

        return cls(
            account_id=account_id.strip(),
            phone_number=phone_number.strip(),
            manager_account_external_id=manager_account_external_id.strip(),
            created_at=created_at.strip(),
        )

    def to_dict(self) -> dict[str, Any]:
        return {
            "account_id": self.account_id,
            "phone_number": self.phone_number,
            "manager_account_external_id": self.manager_account_external_id,
            "created_at": self.created_at,
        }


class AccountStore:
    def __init__(self, storage_path: Path) -> None:
        self._storage_path = storage_path
        self._lock = threading.Lock()

    def load(self) -> dict[str, AccountRecord]:
        with self._lock:
            if not self._storage_path.exists():
                return {}

            try:
                payload = json.loads(self._storage_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                return {}

        if not isinstance(payload, dict):
            return {}

        items = payload.get("accounts")
        if not isinstance(items, list):
            return {}

        records: dict[str, AccountRecord] = {}
        for item in items:
            if not isinstance(item, dict):
                continue
            record = AccountRecord.from_dict(item)
            if record is None:
                continue
            records[record.account_id] = record

        return records

    def save(self, records: dict[str, AccountRecord]) -> None:
        with self._lock:
            self._storage_path.parent.mkdir(parents=True, exist_ok=True)
            payload = {
                "accounts": [record.to_dict() for record in records.values()],
            }
            temp_path = self._storage_path.with_suffix(".tmp")
            temp_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
            temp_path.replace(self._storage_path)


class AccountSession:
    def __init__(
        self,
        config: GatewayConfig,
        account: AccountRecord,
        logger: logging.Logger,
    ) -> None:
        self._config = config
        self._account = account
        self._logger = logger
        self._normalizer = TelegramUpdateNormalizer(
            manager_account_external_id=account.manager_account_external_id,
            crm_provider=config.default_crm_provider,
        )
        self._transport: Tdjson | None = None
        self._client: Any = None
        self._receiver_thread: threading.Thread | None = None
        self._dispatch_thread: threading.Thread | None = None
        self._stop_event = threading.Event()
        self._pending_lock = threading.Lock()
        self._pending: dict[str, PendingResponse] = {}
        self._dispatch_queue: queue.Queue[dict[str, Any]] = queue.Queue()
        self._recent_events: list[dict[str, Any]] = []
        self._recent_events_lock = threading.Lock()
        self._authorization_state = "not_started"
        self._authorization_meta: dict[str, Any] = {}
        self._last_error = ""
        self._last_dispatch_status = "not_started"
        self._configured = config.is_tdlib_configured

    @property
    def account(self) -> AccountRecord:
        return self._account

    def update_account(self, account: AccountRecord) -> None:
        self._account = account

    def start(self) -> None:
        if not self._configured:
            self._authorization_state = "credentials_missing"
            self._logger.warning("Telegram account session started without TELEGRAM_API_ID / TELEGRAM_API_HASH.")
            return

        try:
            database_directory, files_directory = self._account_directories()
            database_directory.mkdir(parents=True, exist_ok=True)
            files_directory.mkdir(parents=True, exist_ok=True)
            self._transport = Tdjson(self._config.tdjson_lib_path)
            self._transport.set_log_verbosity(2)
            self._client = self._transport.create_client()
            self._receiver_thread = threading.Thread(target=self._receiver_loop, name=f"tdlib-{self._account.account_id}", daemon=True)
            self._dispatch_thread = threading.Thread(target=self._dispatch_loop, name=f"dispatch-{self._account.account_id}", daemon=True)
            self._receiver_thread.start()
            self._dispatch_thread.start()
        except Exception as exc:
            self._authorization_state = "startup_failed"
            self._last_error = str(exc)
            self._logger.exception("Telegram session failed to initialize TDLib: %s", exc)
            self._transport = None
            self._client = None
            self._receiver_thread = None
            self._dispatch_thread = None

    def stop(self) -> None:
        self._stop_event.set()
        if self._receiver_thread is not None:
            self._receiver_thread.join(timeout=2)
        if self._dispatch_thread is not None:
            self._dispatch_thread.join(timeout=2)
        if self._transport is not None and self._client is not None:
            self._transport.destroy(self._client)

    def snapshot(self) -> dict[str, Any]:
        return {
            "account_id": self._account.account_id,
            "phone_number": self._account.phone_number,
            "manager_account_external_id": self._account.manager_account_external_id,
            "created_at": self._account.created_at,
            "configured": self._configured,
            "authorization_state": self._authorization_state,
            "authorization_meta": self._authorization_meta,
            "last_error": self._last_error,
            "last_dispatch_status": self._last_dispatch_status,
        }

    def recent_events(self) -> list[dict[str, Any]]:
        with self._recent_events_lock:
            return list(self._recent_events)

    def wait_until_interactive(self, timeout_seconds: float = 12.0) -> None:
        terminal_states = {
            "authorizationStateWaitPhoneNumber",
            "authorizationStateWaitCode",
            "authorizationStateWaitPassword",
            "authorizationStateWaitRegistration",
            "authorizationStateWaitOtherDeviceConfirmation",
            "authorizationStateReady",
            "credentials_missing",
            "startup_failed",
            "authorizationStateClosed",
        }
        deadline = time.time() + timeout_seconds
        while time.time() < deadline:
            if self._authorization_state in terminal_states:
                return
            time.sleep(0.2)

    def submit_phone_number(self, phone_number: str) -> dict[str, Any]:
        self._ensure_ready_for_interaction()
        response = self._call(
            {
                "@type": "setAuthenticationPhoneNumber",
                "phone_number": phone_number,
                "settings": {
                    "@type": "phoneNumberAuthenticationSettings",
                    "allow_flash_call": False,
                    "allow_missed_call": False,
                    "is_current_phone_number": False,
                    "allow_sms_retriever_api": False,
                },
            }
        )
        return {"status": "submitted", "result": response}

    def submit_code(self, code: str) -> dict[str, Any]:
        self._ensure_ready_for_interaction()
        response = self._call({"@type": "checkAuthenticationCode", "code": code})
        return {"status": "submitted", "result": response}

    def submit_password(self, password: str) -> dict[str, Any]:
        self._ensure_ready_for_interaction()
        response = self._call({"@type": "checkAuthenticationPassword", "password": password})
        return {"status": "submitted", "result": response}

    def list_chats(self, limit: int = 50) -> dict[str, Any]:
        self._ensure_authorized()
        safe_limit = max(1, min(limit, 100))
        response = self._call(
            {
                "@type": "getChats",
                "chat_list": {"@type": "chatListMain"},
                "limit": safe_limit,
            },
            timeout=25.0,
        )
        chat_ids = response.get("chat_ids", [])
        if not isinstance(chat_ids, list):
            chat_ids = []

        chats: list[dict[str, Any]] = []
        for raw_chat_id in chat_ids:
            chat_id = str(raw_chat_id)
            chat = self._call({"@type": "getChat", "chat_id": int(chat_id)}, timeout=20.0)
            last_message = chat.get("last_message") or {}
            chats.append(
                {
                    "chat_id": chat_id,
                    "title": str(chat.get("title", chat_id)),
                    "type": ((chat.get("type") or {}).get("@type")) or "unknown",
                    "unread_count": int(chat.get("unread_count", 0)),
                    "last_message_id": str(last_message.get("id", "")),
                    "last_message_at": self._timestamp_to_iso(last_message.get("date")),
                    "last_message_text": self._message_preview(last_message),
                }
            )

        return {"chats": chats}

    def list_messages(self, external_chat_id: str, limit: int = 50) -> dict[str, Any]:
        self._ensure_authorized()
        safe_limit = max(1, min(limit, 100))
        response = self._call(
            {
                "@type": "getChatHistory",
                "chat_id": int(external_chat_id),
                "from_message_id": 0,
                "offset": 0,
                "limit": safe_limit,
                "only_local": False,
            },
            timeout=25.0,
        )
        messages = response.get("messages", [])
        if not isinstance(messages, list):
            messages = []

        mapped: list[dict[str, Any]] = []
        for message in messages:
            if not isinstance(message, dict):
                continue
            content = message.get("content") or {}
            sender_id = message.get("sender_id") or {}
            mapped.append(
                {
                    "message_id": str(message.get("id", "")),
                    "chat_id": str(message.get("chat_id", external_chat_id)),
                    "is_outgoing": bool(message.get("is_outgoing", False)),
                    "date": self._timestamp_to_iso(message.get("date")),
                    "sender": self._sender_label(sender_id),
                    "content_type": str(content.get("@type", "unknown")),
                    "text": self._content_text(content),
                }
            )
        mapped.reverse()
        return {"messages": mapped}

    def send_message(
        self,
        external_chat_id: str,
        body: str,
        correlation_id: str,
        attachments: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        self._ensure_authorized()
        if attachments:
            self._logger.warning("Attachments are not implemented yet in Telegram gateway send_message; sending text only.")

        response = self._call(
            {
                "@type": "sendMessage",
                "chat_id": int(external_chat_id),
                "input_message_content": {
                    "@type": "inputMessageText",
                    "text": {
                        "@type": "formattedText",
                        "text": body,
                    },
                },
            }
        )

        external_message_id = str(response["id"])
        self._append_recent_event(
            {
                "account_id": self._account.account_id,
                "direction": "outbound",
                "correlation_id": correlation_id,
                "external_chat_id": external_chat_id,
                "external_message_id": external_message_id,
                "body": body,
            }
        )

        return {
            "status": "sent",
            "external_message_id": external_message_id,
        }

    def _ensure_ready_for_interaction(self) -> None:
        if not self._configured:
            raise GatewayError("TELEGRAM_API_ID and TELEGRAM_API_HASH must be configured before authorization.")

    def _ensure_authorized(self) -> None:
        self._ensure_ready_for_interaction()
        if self._authorization_state != "authorizationStateReady":
            raise GatewayError(
                f"Telegram account is not authorized yet. Current state: {self._authorization_state}."
            )

    def _receiver_loop(self) -> None:
        assert self._transport is not None
        assert self._client is not None

        while not self._stop_event.is_set():
            try:
                update = self._transport.receive(self._client, 1.0)
                if update is None:
                    continue

                extra = update.get("@extra")
                if extra is not None:
                    self._resolve_pending_response(str(extra), update)
                    continue

                self._handle_update(update)
            except Exception as exc:  # pragma: no cover - long-running loop protection
                self._last_error = str(exc)
                self._logger.exception("TDLib receiver loop failed: %s", exc)
                time.sleep(1)

    def _dispatch_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                message = self._dispatch_queue.get(timeout=0.5)
            except queue.Empty:
                continue

            try:
                dto = self._normalize_message(message)
                if dto is None:
                    continue

                self._post_to_core(dto)
                self._last_dispatch_status = "ok"
                self._append_recent_event(
                    {
                        "account_id": self._account.account_id,
                        "direction": "inbound",
                        "event_id": dto.event_id,
                        "external_chat_id": dto.contact_external_chat_id,
                        "external_message_id": dto.external_message_id,
                        "body": dto.body,
                    }
                )
            except Exception as exc:  # pragma: no cover - long-running worker protection
                self._last_error = str(exc)
                self._last_dispatch_status = "failed"
                self._logger.exception("Inbound dispatch failed: %s", exc)

    def _resolve_pending_response(self, extra: str, update: dict[str, Any]) -> None:
        with self._pending_lock:
            pending = self._pending.get(extra)
        if pending is None:
            return
        pending.response = update
        pending.event.set()

    def _handle_update(self, update: dict[str, Any]) -> None:
        update_type = update.get("@type")
        if update_type == "updateAuthorizationState":
            self._handle_authorization_state(update.get("authorization_state") or {})
            return

        if update_type == "updateNewMessage":
            message = update.get("message")
            if isinstance(message, dict) and not message.get("is_outgoing", False):
                self._dispatch_queue.put(message)

    def _handle_authorization_state(self, authorization_state: dict[str, Any]) -> None:
        state_type = authorization_state.get("@type", "unknown")
        self._authorization_state = state_type
        self._authorization_meta = self._extract_authorization_meta(authorization_state)

        database_directory, files_directory = self._account_directories()

        if state_type == "authorizationStateWaitTdlibParameters":
            self._send(
                {
                    "@type": "setTdlibParameters",
                    "use_test_dc": False,
                    "database_directory": str(database_directory),
                    "files_directory": str(files_directory),
                    "use_file_database": True,
                    "use_chat_info_database": True,
                    "use_message_database": True,
                    "use_secret_chats": False,
                    "api_id": int(self._config.api_id),
                    "api_hash": self._config.api_hash,
                    "system_language_code": "en",
                    "device_model": "chat-sync-gateway",
                    "system_version": "docker",
                    "application_version": "0.2.0",
                }
            )
            return

        if state_type == "authorizationStateWaitEncryptionKey":
            self._send({"@type": "checkDatabaseEncryptionKey", "encryption_key": ""})

    def _extract_authorization_meta(self, authorization_state: dict[str, Any]) -> dict[str, Any]:
        state_type = authorization_state.get("@type")
        if state_type == "authorizationStateWaitCode":
            code_info = authorization_state.get("code_info") or {}
            return {
                "phone_number": code_info.get("phone_number", ""),
                "timeout": code_info.get("timeout", 0),
                "type": (code_info.get("type") or {}).get("@type", ""),
            }

        if state_type == "authorizationStateWaitOtherDeviceConfirmation":
            return {"link": authorization_state.get("link", "")}

        return {}

    def _normalize_message(self, message: dict[str, Any]) -> InboundChannelMessageDto | None:
        chat = self._call({"@type": "getChat", "chat_id": int(message["chat_id"])})
        return self._normalizer.normalize(message, chat)

    def _post_to_core(self, dto: InboundChannelMessageDto) -> None:
        if self._config.core_webhook_url == "":
            return

        payload = json.dumps(dto.to_payload()).encode("utf-8")
        headers = {"Content-Type": "application/json"}
        if self._config.core_webhook_token != "":
            headers["X-Webhook-Token"] = self._config.core_webhook_token

        http_request = request.Request(
            self._config.core_webhook_url,
            data=payload,
            method="POST",
            headers=headers,
        )

        try:
            with request.urlopen(http_request, timeout=10) as response:
                response.read()
        except error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")
            raise GatewayError(f"Core webhook returned HTTP {exc.code}: {body}") from exc
        except error.URLError as exc:
            raise GatewayError(f"Core webhook request failed: {exc.reason}") from exc

    def _send(self, query: dict[str, Any]) -> None:
        assert self._transport is not None
        assert self._client is not None
        self._transport.send(self._client, query)

    def _call(self, query: dict[str, Any], timeout: float = 15.0) -> dict[str, Any]:
        self._ensure_ready_for_interaction()
        extra = str(uuid.uuid4())
        pending = PendingResponse(event=threading.Event())
        with self._pending_lock:
            self._pending[extra] = pending

        try:
            request_payload = dict(query)
            request_payload["@extra"] = extra
            self._send(request_payload)

            if not pending.event.wait(timeout):
                raise GatewayError(f"TDLib request timed out for {query.get('@type', 'unknown')}.")

            response = pending.response or {}
            if response.get("@type") == "error":
                raise GatewayError(
                    f"TDLib error {response.get('code')}: {response.get('message', 'unknown error')}"
                )

            return response
        finally:
            with self._pending_lock:
                self._pending.pop(extra, None)

    def _append_recent_event(self, event: dict[str, Any]) -> None:
        with self._recent_events_lock:
            self._recent_events.append(event)
            if len(self._recent_events) > 100:
                self._recent_events = self._recent_events[-100:]

    def _account_directories(self) -> tuple[Path, Path]:
        database_directory = Path(self._config.tdlib_db_dir) / self._account.account_id
        files_directory = Path(self._config.tdlib_files_dir) / self._account.account_id
        return database_directory, files_directory

    def _message_preview(self, message: dict[str, Any]) -> str:
        content = message.get("content") or {}
        return self._content_text(content)

    def _content_text(self, content: dict[str, Any]) -> str:
        content_type = content.get("@type")
        if content_type == "messageText":
            return str((((content.get("text") or {}).get("text")) or "")).strip()
        if content_type == "messagePhoto":
            caption = str((((content.get("caption") or {}).get("text")) or "")).strip()
            return caption or "[photo]"
        if content_type == "messageDocument":
            caption = str((((content.get("caption") or {}).get("text")) or "")).strip()
            file_name = str(((content.get("document") or {}).get("file_name")) or "document")
            return caption or f"[document] {file_name}"
        if content_type == "messageSticker":
            return "[sticker]"
        if content_type == "messageVoiceNote":
            return "[voice_note]"
        if content_type == "messageAnimation":
            caption = str((((content.get("caption") or {}).get("text")) or "")).strip()
            return caption or "[animation]"
        if content_type is None:
            return "[unknown]"
        return f"[{content_type}]"

    def _sender_label(self, sender_id: dict[str, Any]) -> str:
        sender_type = sender_id.get("@type")
        if sender_type == "messageSenderUser":
            return f"user:{sender_id.get('user_id')}"
        if sender_type == "messageSenderChat":
            return f"chat:{sender_id.get('chat_id')}"
        return "unknown"

    def _timestamp_to_iso(self, value: Any) -> str:
        try:
            if value is None:
                return ""
            return datetime.fromtimestamp(int(value), tz=timezone.utc).isoformat()
        except (TypeError, ValueError, OSError):
            return ""


class TelegramGateway:
    def __init__(self, config: GatewayConfig) -> None:
        self._config = config
        self._logger = logging.getLogger("telegram_gateway")
        self._store = AccountStore(Path(config.tdlib_db_dir) / "accounts.json")
        self._accounts_lock = threading.Lock()
        self._accounts: dict[str, AccountRecord] = {}
        self._sessions: dict[str, AccountSession] = {}
        self._started = False

    def start(self) -> None:
        Path(self._config.tdlib_db_dir).mkdir(parents=True, exist_ok=True)
        Path(self._config.tdlib_files_dir).mkdir(parents=True, exist_ok=True)
        loaded = self._store.load()
        with self._accounts_lock:
            self._accounts = loaded
            records = list(self._accounts.values())
            self._started = True

        for record in records:
            self._start_session(record)

    def stop(self) -> None:
        with self._accounts_lock:
            sessions = list(self._sessions.values())
            self._sessions = {}
            self._started = False
        for session in sessions:
            session.stop()

    def health(self) -> dict[str, Any]:
        accounts = self.list_accounts()
        ready_accounts = sum(1 for account in accounts if account["authorization_state"] == "authorizationStateReady")
        return {
            "status": "ok",
            "configured": self._config.is_tdlib_configured,
            "accounts_total": len(accounts),
            "accounts_ready": ready_accounts,
            "core_webhook_url": self._config.core_webhook_url,
        }

    def list_accounts(self) -> list[dict[str, Any]]:
        with self._accounts_lock:
            records = list(self._accounts.values())
            sessions = dict(self._sessions)

        result: list[dict[str, Any]] = []
        for record in sorted(records, key=lambda item: item.created_at):
            session = sessions.get(record.account_id)
            if session is None:
                result.append(
                    {
                        "account_id": record.account_id,
                        "phone_number": record.phone_number,
                        "manager_account_external_id": record.manager_account_external_id,
                        "created_at": record.created_at,
                        "configured": self._config.is_tdlib_configured,
                        "authorization_state": "not_started",
                        "authorization_meta": {},
                        "last_error": "Session is not initialized.",
                        "last_dispatch_status": "not_started",
                    }
                )
                continue
            result.append(session.snapshot())
        return result

    def get_account(self, account_id: str) -> dict[str, Any]:
        session = self._require_session(account_id)
        return session.snapshot()

    def create_account(self, phone_number: str, manager_account_external_id: str | None = None) -> dict[str, Any]:
        phone = phone_number.strip()
        if phone == "":
            raise GatewayError("Field 'phone_number' must be a non-empty string.")

        account_id = self._next_account_id()
        manager_id = (manager_account_external_id or "").strip()
        if manager_id == "":
            manager_id = f"telegram-manager-{account_id[-6:]}"
        manager_id = self._sanitize_manager_external_id(manager_id)
        if manager_id == "":
            raise GatewayError("Field 'manager_account_external_id' must contain at least one valid character.")

        with self._accounts_lock:
            if any(item.manager_account_external_id == manager_id for item in self._accounts.values()):
                raise GatewayError(f"Manager account '{manager_id}' already exists.")

            record = AccountRecord(
                account_id=account_id,
                phone_number=phone,
                manager_account_external_id=manager_id,
                created_at=datetime.now(timezone.utc).isoformat(),
            )
            self._accounts[record.account_id] = record
            self._store.save(self._accounts)

        session = self._start_session(record)
        session.wait_until_interactive()

        phone_submission: dict[str, Any]
        try:
            phone_submission = session.submit_phone_number(phone)
        except GatewayError as exc:
            phone_submission = {
                "status": "deferred",
                "message": str(exc),
            }

        return {
            "account": session.snapshot(),
            "phone_submission": phone_submission,
        }

    def update_phone(self, account_id: str, phone_number: str) -> dict[str, Any]:
        phone = phone_number.strip()
        if phone == "":
            raise GatewayError("Field 'phone_number' must be a non-empty string.")

        with self._accounts_lock:
            record = self._accounts.get(account_id)
            if record is None:
                raise GatewayError(f"Account '{account_id}' does not exist.")
            self._accounts[account_id] = replace(record, phone_number=phone)
            self._store.save(self._accounts)

        session = self._require_session(account_id)
        result = session.submit_phone_number(phone)
        with self._accounts_lock:
            current = self._accounts.get(account_id)
        if current is not None:
            session.update_account(current)

        return {
            "account": session.snapshot(),
            "result": result,
        }

    def submit_code(self, account_id: str, code: str) -> dict[str, Any]:
        if code.strip() == "":
            raise GatewayError("Field 'code' must be a non-empty string.")

        session = self._require_session(account_id)
        result = session.submit_code(code.strip())
        return {
            "account": session.snapshot(),
            "result": result,
        }

    def submit_password(self, account_id: str, password: str) -> dict[str, Any]:
        if password.strip() == "":
            raise GatewayError("Field 'password' must be a non-empty string.")

        session = self._require_session(account_id)
        result = session.submit_password(password.strip())
        return {
            "account": session.snapshot(),
            "result": result,
        }

    def list_chats(self, account_id: str, limit: int = 50) -> dict[str, Any]:
        session = self._require_session(account_id)
        payload = session.list_chats(limit=limit)
        payload["account"] = session.snapshot()
        return payload

    def list_messages(self, account_id: str, external_chat_id: str, limit: int = 50) -> dict[str, Any]:
        session = self._require_session(account_id)
        payload = session.list_messages(external_chat_id=external_chat_id, limit=limit)
        payload["account"] = session.snapshot()
        payload["external_chat_id"] = external_chat_id
        return payload

    def send_chat_message(
        self,
        account_id: str,
        external_chat_id: str,
        body: str,
        correlation_id: str,
        attachments: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        if body.strip() == "":
            raise GatewayError("Field 'body' must be a non-empty string.")
        session = self._require_session(account_id)
        result = session.send_message(
            external_chat_id=external_chat_id,
            body=body.strip(),
            correlation_id=correlation_id,
            attachments=attachments,
        )
        return {
            "account": session.snapshot(),
            "result": result,
        }

    def send_message(
        self,
        manager_account_external_id: str,
        external_chat_id: str,
        body: str,
        correlation_id: str,
        attachments: list[dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        session = self._session_by_manager_account(manager_account_external_id)
        return session.send_message(
            external_chat_id=external_chat_id,
            body=body,
            correlation_id=correlation_id,
            attachments=attachments,
        )

    def auth_state(self, account_id: str | None = None) -> dict[str, Any]:
        if account_id is not None and account_id.strip() != "":
            return self.get_account(account_id.strip())
        session = self._default_session_or_none()
        if session is None:
            return {
                "status": "ok",
                "configured": self._config.is_tdlib_configured,
                "authorization_state": "not_initialized",
                "authorization_meta": {},
            }
        return session.snapshot()

    def recent_events(self) -> list[dict[str, Any]]:
        with self._accounts_lock:
            sessions = list(self._sessions.values())

        events: list[dict[str, Any]] = []
        for session in sessions:
            events.extend(session.recent_events())
        return events[-200:]

    def legacy_submit_phone(self, phone_number: str) -> dict[str, Any]:
        account_id = self._ensure_default_account()
        return self.update_phone(account_id, phone_number)

    def legacy_submit_code(self, code: str) -> dict[str, Any]:
        account_id = self._ensure_default_account()
        return self.submit_code(account_id, code)

    def legacy_submit_password(self, password: str) -> dict[str, Any]:
        account_id = self._ensure_default_account()
        return self.submit_password(account_id, password)

    def _start_session(self, record: AccountRecord) -> AccountSession:
        session = AccountSession(
            config=self._config,
            account=record,
            logger=self._logger.getChild(record.account_id),
        )
        session.start()
        with self._accounts_lock:
            self._sessions[record.account_id] = session
        return session

    def _require_session(self, account_id: str) -> AccountSession:
        with self._accounts_lock:
            session = self._sessions.get(account_id)
        if session is None:
            raise GatewayError(f"Account '{account_id}' is not initialized.")
        return session

    def _session_by_manager_account(self, manager_account_external_id: str) -> AccountSession:
        manager = manager_account_external_id.strip()
        if manager == "":
            raise GatewayError("Field 'manager_account_external_id' must be a non-empty string.")
        with self._accounts_lock:
            account_id: str | None = None
            for item in self._accounts.values():
                if item.manager_account_external_id == manager:
                    account_id = item.account_id
                    break
            session = self._sessions.get(account_id or "")
        if session is None:
            raise GatewayError(f"Manager account '{manager}' is not connected.")
        return session

    def _default_session_or_none(self) -> AccountSession | None:
        with self._accounts_lock:
            for record in self._accounts.values():
                if record.manager_account_external_id == self._config.manager_account_external_id:
                    return self._sessions.get(record.account_id)
            if self._accounts:
                first = next(iter(self._accounts.values()))
                return self._sessions.get(first.account_id)
        return None

    def _ensure_default_account(self) -> str:
        with self._accounts_lock:
            for record in self._accounts.values():
                if record.manager_account_external_id == self._config.manager_account_external_id:
                    return record.account_id

            account_id = "legacy-default"
            while account_id in self._accounts:
                account_id = f"legacy-{uuid.uuid4().hex[:8]}"

            record = AccountRecord(
                account_id=account_id,
                phone_number="",
                manager_account_external_id=self._config.manager_account_external_id,
                created_at=datetime.now(timezone.utc).isoformat(),
            )
            self._accounts[account_id] = record
            self._store.save(self._accounts)

        self._start_session(record)
        return account_id

    def _next_account_id(self) -> str:
        while True:
            account_id = f"acc-{uuid.uuid4().hex[:12]}"
            with self._accounts_lock:
                if account_id not in self._accounts:
                    return account_id

    def _sanitize_manager_external_id(self, value: str) -> str:
        allowed = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-"
        result = "".join(character for character in value if character in allowed)
        return result[:64]
