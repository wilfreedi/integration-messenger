# Task Log

| Date | Task | Status | Notes |
| --- | --- | --- | --- |
| 2026-04-07 | Initialize production-grade architecture skeleton for channel-agnostic CRM sync platform | Done | MVP focus: Telegram personal account -> Bitrix Open Lines |
| 2026-04-07 | Define project rules, action log, and task log discipline | Done | Operational files created |
| 2026-04-07 | Create PHP core platform skeleton with domain/application boundaries | Done | Includes idempotency, mappings, connector ports, and tests |
| 2026-04-07 | Prepare manual MVP verification plan without admin panel | Done | Manual Telegram auth and Bitrix integration steps documented |
| 2026-04-07 | Add executable unit tests for inbound and outbound sync orchestration | Done | Local runner passes for both directions |
| 2026-04-07 | Add Docker runtime and manual integration contour | Done | Docker Compose, PostgreSQL, HTTP entrypoints, scripts, and Makefile added |
| 2026-04-07 | Verify containerized MVP runtime end-to-end | Done | `docker compose up` and `scripts/smoke_test.php` passed after startup/runtime fixes |
| 2026-04-07 | Add separate Telegram gateway service on official TDLib | Done | Dedicated Python service with auth endpoints, inbound forwarding, outbound send, and PHP connector added |
| 2026-04-07 | Stabilize Telegram gateway Docker build | Done | Replaced TDLib source compilation with packaged `libtdjson` runtime dependency |
| 2026-04-07 | Build simple web UI without auth for Telegram manual testing | Done | Account list/create, code/password verification, chats list, messages read/send implemented |
| 2026-04-07 | Add multi-account TDLib session persistence | Done | Account metadata stored in `accounts.json`, TDLib directories isolated per account and reused after restart |
| 2026-04-07 | Integrate Bitrix Open Lines in non-stub mode | Done | Added REST-based connector, configuration, and runtime wiring |
| 2026-04-07 | Add native Bitrix Open Lines webhook endpoint | Done | Added payload validator/parser and operator message sync route |
| 2026-04-07 | Add delivery status callback to Bitrix after channel send | Done | Added message mapping lookup and delivery acknowledgement calls |
| 2026-04-07 | Fix Bitrix webhook DTO/controller consistency and payload coverage | Done | Added `imChatId`, `DATA`/`MESSAGES` support, and form-data route fallback |
| 2026-04-07 | Prepare complete Bitrix setup instruction (RU) | Done | Added `docs/BITRIX_SETUP_RU.md` and linked it from README and runbook |
| 2026-04-07 | Harden Bitrix integration runtime and management APIs | Done | Fixed REST connector DI mismatch, enabled manager-based Bitrix routing, exposed install/binding routes, and added connector routing tests |
| 2026-04-07 | Add Bitrix OAuth expiry guard in routing connector | Done | Added fail-fast behavior for expired access tokens to prevent silent misrouting/fallback |
| 2026-04-07 | Secure Bitrix management APIs and install payload validation | Done | Added shared-token protection for `/api/bitrix/*`, constant-time token checks, stricter `client_endpoint` validation, and validator tests |
| 2026-04-07 | Remove hard global-route dependency for Bitrix delivery ack | Done | Delivery ack now uses manager binding routing first; global webhook/line values are only optional fallback |
| 2026-04-07 | Add dedicated Bitrix portal registration web page | Done | Added `/panel/bitrix.html` UI for install/binding management and linked it from Telegram gateway panel |
| 2026-04-07 | Expand Bitrix portal-side setup manual | Done | Added full in-portal checklist for Open Lines, webhook wiring, OAuth install data, and operational validation |
| 2026-04-07 | Add one-shot "connect profile" integration flow | Done | Added API and panel wizard to register Bitrix portal and bind manager in one action (wappi-like UX) |
| 2026-04-07 | Simplify Bitrix integration to binding-only routing | Done | Removed fallback usage from runtime/docs and made connector id defaulted internally (`chat_sync`) |
| 2026-04-07 | Simplify Bitrix panel UX for non-technical setup | Done | Removed manual connector_id fields; primary flow now requires only portal install + manager + line_id |
| 2026-04-07 | Simplify Bitrix management API responses | Done | Removed connector_id from user-facing binding responses to keep API surface simpler |
| 2026-04-08 | Flatten Bitrix panel to one-step connection UX | Done | Removed split install/binding blocks and replaced with one "Подключить" form for manager + line + portal auth |
| 2026-04-08 | Add domain-based HTTPS ingress with auto certificate renewal | Done | Added Caddy service with env-driven domain, automatic Let's Encrypt issue/renew, and updated setup docs |
| 2026-04-08 | Prevent direct public access to internal HTTP services | Done | Bound `app` and `telegram-gateway` host ports to `127.0.0.1`, keeping external access only through HTTPS reverse proxy |
| 2026-04-08 | Build Bitrix connection wizard page with data generator | Done | Added one-page setup wizard generating tokens, `.env`, Bitrix app paths, webhook URL, and copy actions |
| 2026-04-08 | Refactor Bitrix page to instruction-first autofill flow | Done | Added server-backed setup profile autofill and changed token generation to "only if missing" |
