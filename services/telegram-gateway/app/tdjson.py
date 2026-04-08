from __future__ import annotations

import ctypes
import ctypes.util
import json
from typing import Any


class Tdjson:
    def __init__(self, library_path: str) -> None:
        self._library = self._load_library(library_path)
        self._library.td_json_client_create.restype = ctypes.c_void_p
        self._library.td_json_client_send.argtypes = [ctypes.c_void_p, ctypes.c_char_p]
        self._library.td_json_client_receive.argtypes = [ctypes.c_void_p, ctypes.c_double]
        self._library.td_json_client_receive.restype = ctypes.c_char_p
        self._library.td_json_client_destroy.argtypes = [ctypes.c_void_p]
        self._library.td_set_log_verbosity_level.argtypes = [ctypes.c_int]

    def _load_library(self, library_path: str) -> ctypes.CDLL:
        candidates: list[str] = []
        if library_path.strip() != "":
            candidates.append(library_path.strip())

        discovered = ctypes.util.find_library("tdjson")
        if discovered:
            candidates.append(discovered)

        candidates.extend(
            [
                "/usr/local/lib/libtdjson.so",
                "/usr/lib/x86_64-linux-gnu/libtdjson.so.1.8.38",
                "/usr/lib/aarch64-linux-gnu/libtdjson.so.1.8.38",
            ]
        )

        errors: list[str] = []
        for candidate in dict.fromkeys(candidates):
            try:
                return ctypes.CDLL(candidate)
            except OSError as exc:
                errors.append(f"{candidate}: {exc}")

        attempted = ", ".join(dict.fromkeys(candidates))
        details = "; ".join(errors)
        raise OSError(f"Unable to load TDLib tdjson library. Tried: {attempted}. Details: {details}")

    def set_log_verbosity(self, level: int) -> None:
        self._library.td_set_log_verbosity_level(level)

    def create_client(self) -> ctypes.c_void_p:
        return self._library.td_json_client_create()

    def send(self, client: ctypes.c_void_p, query: dict[str, Any]) -> None:
        payload = json.dumps(query, separators=(",", ":")).encode("utf-8")
        self._library.td_json_client_send(client, payload)

    def receive(self, client: ctypes.c_void_p, timeout: float) -> dict[str, Any] | None:
        payload = self._library.td_json_client_receive(client, timeout)
        if payload is None:
            return None

        return json.loads(payload.decode("utf-8"))

    def destroy(self, client: ctypes.c_void_p) -> None:
        self._library.td_json_client_destroy(client)
