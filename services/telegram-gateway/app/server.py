from __future__ import annotations

import json
import logging
import signal
import sys
import uuid
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any
from urllib.parse import parse_qs, urlparse

from app.config import GatewayConfig
from app.gateway import GatewayError, TelegramGateway
from app.ui import dashboard_html


CONFIG = GatewayConfig.from_env()
GATEWAY = TelegramGateway(CONFIG)


class GatewayRequestHandler(BaseHTTPRequestHandler):
    server_version = "TelegramGateway/0.2"

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        query = parse_qs(parsed.query)
        parts = self._path_parts(path)

        try:
            if path == "/":
                self._respond_html(HTTPStatus.OK, dashboard_html())
                return

            if path == "/health":
                self._respond_json(HTTPStatus.OK, GATEWAY.health())
                return

            if path == "/v1/auth/state":
                account_id = self._optional_string(query, "account_id")
                self._respond_json(HTTPStatus.OK, GATEWAY.auth_state(account_id))
                return

            if path == "/v1/debug/events":
                self._respond_json(HTTPStatus.OK, {"events": GATEWAY.recent_events()})
                return

            if path == "/v1/accounts":
                self._respond_json(HTTPStatus.OK, {"accounts": GATEWAY.list_accounts()})
                return

            if len(parts) == 3 and parts[0] == "v1" and parts[1] == "accounts":
                self._respond_json(HTTPStatus.OK, GATEWAY.get_account(parts[2]))
                return

            if len(parts) == 4 and parts[0] == "v1" and parts[1] == "accounts" and parts[3] == "chats":
                limit = self._optional_int(query, "limit", default=50)
                self._respond_json(HTTPStatus.OK, GATEWAY.list_chats(parts[2], limit=limit))
                return

            if (
                len(parts) == 6
                and parts[0] == "v1"
                and parts[1] == "accounts"
                and parts[3] == "chats"
                and parts[5] == "messages"
            ):
                limit = self._optional_int(query, "limit", default=50)
                self._respond_json(
                    HTTPStatus.OK,
                    GATEWAY.list_messages(
                        account_id=parts[2],
                        external_chat_id=parts[4],
                        limit=limit,
                    ),
                )
                return

            self._not_found("GET", path)
        except ValueError as exc:
            self._respond_json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "validation_error", "message": str(exc)})
        except GatewayError as exc:
            self._respond_json(HTTPStatus.CONFLICT, {"error": "gateway_error", "message": str(exc)})

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        parts = self._path_parts(path)

        try:
            payload = self._read_json()

            if path == "/v1/accounts":
                result = GATEWAY.create_account(
                    phone_number=self._required_string(payload, "phone_number"),
                    manager_account_external_id=self._optional_string_from_payload(payload, "manager_account_external_id"),
                )
                self._respond_json(HTTPStatus.CREATED, result)
                return

            if len(parts) == 5 and parts[0] == "v1" and parts[1] == "accounts" and parts[3] == "auth":
                account_id = parts[2]
                action = parts[4]
                if action == "phone":
                    self._respond_json(
                        HTTPStatus.ACCEPTED,
                        GATEWAY.update_phone(account_id, self._required_string(payload, "phone_number")),
                    )
                    return
                if action == "code":
                    self._respond_json(
                        HTTPStatus.ACCEPTED,
                        GATEWAY.submit_code(account_id, self._required_string(payload, "code")),
                    )
                    return
                if action == "password":
                    self._respond_json(
                        HTTPStatus.ACCEPTED,
                        GATEWAY.submit_password(account_id, self._required_string(payload, "password")),
                    )
                    return

            if len(parts) == 4 and parts[0] == "v1" and parts[1] == "accounts" and parts[3] == "manager":
                self._respond_json(
                    HTTPStatus.OK,
                    GATEWAY.update_manager_account(
                        account_id=parts[2],
                        manager_account_external_id=self._required_string(payload, "manager_account_external_id"),
                    ),
                )
                return

            if (
                len(parts) == 6
                and parts[0] == "v1"
                and parts[1] == "accounts"
                and parts[3] == "chats"
                and parts[5] == "messages"
            ):
                self._respond_json(
                    HTTPStatus.ACCEPTED,
                    GATEWAY.send_chat_message(
                        account_id=parts[2],
                        external_chat_id=parts[4],
                        body=self._required_string(payload, "body"),
                        correlation_id=self._optional_string_from_payload(payload, "correlation_id")
                        or f"ui:{uuid.uuid4().hex}",
                        attachments=self._attachments(payload.get("attachments")),
                    ),
                )
                return

            if path == "/v1/messages/send":
                self._respond_json(
                    HTTPStatus.ACCEPTED,
                    GATEWAY.send_message(
                        manager_account_external_id=self._required_string(payload, "manager_account_external_id"),
                        external_chat_id=self._required_string(payload, "external_chat_id"),
                        body=self._required_string(payload, "body"),
                        correlation_id=self._required_string(payload, "correlation_id"),
                        attachments=self._attachments(payload.get("attachments")),
                    ),
                )
                return

            if path == "/v1/auth/phone":
                self._respond_json(
                    HTTPStatus.ACCEPTED,
                    GATEWAY.legacy_submit_phone(self._required_string(payload, "phone_number")),
                )
                return

            if path == "/v1/auth/code":
                self._respond_json(
                    HTTPStatus.ACCEPTED,
                    GATEWAY.legacy_submit_code(self._required_string(payload, "code")),
                )
                return

            if path == "/v1/auth/password":
                self._respond_json(
                    HTTPStatus.ACCEPTED,
                    GATEWAY.legacy_submit_password(self._required_string(payload, "password")),
                )
                return

            self._not_found("POST", path)
        except ValueError as exc:
            self._respond_json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "validation_error", "message": str(exc)})
        except GatewayError as exc:
            self._respond_json(HTTPStatus.CONFLICT, {"error": "gateway_error", "message": str(exc)})

    def log_message(self, format: str, *args: Any) -> None:
        logging.getLogger("telegram_gateway.http").info("%s - %s", self.address_string(), format % args)

    def _path_parts(self, path: str) -> list[str]:
        return [part for part in path.strip("/").split("/") if part]

    def _read_json(self) -> dict[str, Any]:
        content_length = int(self.headers.get("Content-Length", "0"))
        if content_length == 0:
            return {}

        raw = self.rfile.read(content_length)
        if raw == b"":
            return {}

        try:
            payload = json.loads(raw.decode("utf-8"))
        except json.JSONDecodeError as exc:
            raise ValueError("Request body must be valid JSON.") from exc

        if not isinstance(payload, dict):
            raise ValueError("JSON body must be an object.")

        return payload

    def _required_string(self, payload: dict[str, Any], key: str) -> str:
        value = payload.get(key)
        if not isinstance(value, str) or value.strip() == "":
            raise ValueError(f"Field '{key}' is required and must be a non-empty string.")
        return value.strip()

    def _optional_string_from_payload(self, payload: dict[str, Any], key: str) -> str | None:
        value = payload.get(key)
        if value is None:
            return None
        if not isinstance(value, str):
            raise ValueError(f"Field '{key}' must be a string.")
        trimmed = value.strip()
        return trimmed if trimmed != "" else None

    def _optional_string(self, query: dict[str, list[str]], key: str) -> str | None:
        values = query.get(key)
        if values is None or len(values) == 0:
            return None
        value = values[0].strip()
        return value if value != "" else None

    def _optional_int(self, query: dict[str, list[str]], key: str, default: int) -> int:
        value = self._optional_string(query, key)
        if value is None:
            return default
        try:
            return int(value)
        except ValueError as exc:
            raise ValueError(f"Query parameter '{key}' must be an integer.") from exc

    def _attachments(self, value: Any) -> list[dict[str, Any]]:
        if value is None:
            return []
        if not isinstance(value, list):
            raise ValueError("Field 'attachments' must be an array.")
        result: list[dict[str, Any]] = []
        for item in value:
            if not isinstance(item, dict):
                raise ValueError("Each attachment must be an object.")
            result.append(item)
        return result

    def _not_found(self, method: str, path: str) -> None:
        self._respond_json(
            HTTPStatus.NOT_FOUND,
            {"error": "not_found", "message": f"Route {method} {path} is not defined."},
        )

    def _respond_json(self, status: HTTPStatus, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status.value)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _respond_html(self, status: HTTPStatus, html: str) -> None:
        body = html.encode("utf-8")
        self.send_response(status.value)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def main() -> None:
    logging.basicConfig(
        level=getattr(logging, CONFIG.log_level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )

    GATEWAY.start()
    server = ThreadingHTTPServer(("0.0.0.0", CONFIG.gateway_port), GatewayRequestHandler)

    def _shutdown(_signum: int, _frame: Any) -> None:
        logging.getLogger("telegram_gateway").info("Shutting down Telegram gateway.")
        server.shutdown()
        GATEWAY.stop()
        sys.exit(0)

    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT, _shutdown)

    logging.getLogger("telegram_gateway").info("Telegram gateway listening on 0.0.0.0:%s", CONFIG.gateway_port)
    server.serve_forever()


if __name__ == "__main__":
    main()
