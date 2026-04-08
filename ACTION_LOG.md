# Action Log

| Date | Action | Result |
| --- | --- | --- |
| 2026-04-07 | Inspected workspace contents and tooling | Repository is empty except IDE files; PHP 8.4 and Composer available |
| 2026-04-07 | Established project rules and operational logs | Added project-wide architectural constraints and tracking files |
| 2026-04-07 | Built PHP core skeleton and documentation | Domain model, application handlers, ports, schema, and MVP docs added |
| 2026-04-07 | Generated Composer autoload files | Autoload bootstrapped successfully |
| 2026-04-07 | Executed local test runner | Inbound and outbound sync tests passed |
| 2026-04-07 | Added Docker runtime, HTTP entrypoints, PDO repositories, and smoke scripts | Runnable MVP contour prepared for manual verification |
| 2026-04-07 | Validated Docker Compose configuration | `docker compose config` succeeded |
| 2026-04-07 | Started Docker Desktop and launched project containers | PostgreSQL and app services started successfully |
| 2026-04-07 | Diagnosed and fixed app container startup failure | Replaced direct script entrypoint with `sh /app/docker/php/entrypoint.sh` for bind-mounted runtime |
| 2026-04-07 | Diagnosed and fixed PostgreSQL boolean binding error | `is_primary` now persists as explicit `true/false` strings |
| 2026-04-07 | Executed containerized smoke flow | Health, inbound channel flow, outbound CRM flow, and state inspection succeeded |
| 2026-04-07 | Added separate Telegram gateway on official TDLib | Python service, HTTP auth/send API, PHP gateway connector, tests, and docs added |
| 2026-04-07 | Diagnosed TDLib Docker build instability | Source build failed due container OOM while compiling TDLib |
| 2026-04-07 | Reworked Telegram gateway image to use packaged `libtdjson` | Docker build no longer depends on compiling TDLib from source |
| 2026-04-07 | Implemented Telegram gateway web console and multi-account runtime | Added account list/create/auth flows, chat listing, message reading/sending, and session persistence |
| 2026-04-07 | Preserved legacy gateway contract for PHP core | Kept `/v1/messages/send` and legacy auth endpoints for backward compatibility |
| 2026-04-07 | Implemented Bitrix Open Lines REST adapter | Added `BITRIX_CONNECTOR_MODE=rest`, REST client, Open Lines API calls, and connector wiring |
| 2026-04-07 | Added Bitrix operator webhook ingestion | Added `/api/webhooks/bitrix/open-lines` parser/controller with dedup-aware sync into channel |
| 2026-04-07 | Added Bitrix delivery status acknowledgements | Added mapping lookup and `imconnector.send.status.delivery` calls after successful CRM->channel sync |
| 2026-04-07 | Hardened Bitrix webhook compatibility | Added `DATA`/`MESSAGES` parsing, `im.chat_id` propagation for delivery ack, and form-data fallback in HTTP route |
| 2026-04-07 | Added full Bitrix setup guide in Russian | Created dedicated Bitrix runbook with `.env` mapping, portal steps, checks, and troubleshooting |
| 2026-04-07 | Stabilized Bitrix REST connector wiring and routing | Fixed constructor mismatch in container, added per-manager routing via bindings/install tables, and fallback to global `.env` route |
| 2026-04-07 | Exposed Bitrix integration management HTTP endpoints | Added `/api/bitrix/app/install`, `/api/bitrix/portals`, `/api/bitrix/bindings` routes in public router |
| 2026-04-07 | Added unit tests for Bitrix connector routing behavior | Covered default fallback route and binding-based route with OAuth auth payload |
| 2026-04-07 | Added token-expiry guard for Bitrix per-manager routing | Connector now fails fast on expired OAuth token and avoids sending to stale route |
| 2026-04-07 | Added shared-token guard for Bitrix management endpoints | `/api/bitrix/*` now supports `X-Integration-Token`/Bearer/query token checks via constant-time compare |
| 2026-04-07 | Hardened Bitrix install payload validator | Enforced valid portal domain, HTTPS client endpoint, host match, and blocked credentials/query/fragment in endpoint URL |
| 2026-04-07 | Expanded unit-test coverage for Bitrix validation and config changes | Added `BitrixAppInstallValidatorTest` and updated manual `AppConfig` test constructor usage |
| 2026-04-07 | Removed hard dependency of delivery ack on global Bitrix `.env` route | Webhook ack now resolves manager binding route first and uses global webhook/line params only as fallback |
| 2026-04-07 | Added dedicated Bitrix web panel in app | Implemented `/panel/bitrix.html` with install/binding forms, token-aware API calls, and portals/bindings viewers |
| 2026-04-07 | Added Bitrix panel shortcut in Telegram gateway UI | Added top-bar button to open `http://127.0.0.1:8080/panel/bitrix.html` |
| 2026-04-07 | Added convenience route for Bitrix panel | `GET /panel/bitrix` now redirects to `/panel/bitrix.html` |
| 2026-04-07 | Expanded Bitrix setup documentation with portal UI workflow | Added full checklist for actions inside Bitrix portal, OAuth data extraction, and panel-first setup path |
| 2026-04-07 | Added one-shot Bitrix connect flow (install + binding) | Implemented `/api/bitrix/connect-profile` and manager accounts listing endpoint for panel autoselect |
| 2026-04-07 | Upgraded Bitrix web panel to wizard-like flow | Added auto-fill from URL auth params, manager account dropdown, and quick connect button |
| 2026-04-07 | Removed fallback Bitrix route behavior from runtime | Connector and webhook delivery-ack now rely only on manager binding route; missing binding returns explicit error |
| 2026-04-07 | Simplified manager binding validation | `connector_id` became optional with internal default `chat_sync` to reduce setup complexity |
| 2026-04-07 | Simplified Bitrix panel fields and docs | Removed connector_id inputs from panel and rewrote setup docs to one clear install+binding flow |
| 2026-04-07 | Simplified user-facing Bitrix API payloads | Removed `connector_id` from connect/binding responses and bindings list query output |
| 2026-04-07 | Re-ran PHP and gateway test suites | `php tests/run.php` and `services/telegram-gateway/tests/run.py` passed |
| 2026-04-08 | Rebuilt Bitrix panel to one-form flow | Removed separate install/binding sections and SaaS wording; now one connect action with optional advanced fields |
| 2026-04-08 | Aligned docs with simplified panel flow | Updated Bitrix setup and runbook to describe a single-step portal connection path |
| 2026-04-08 | Re-ran PHP tests after UI/docs refactor | `php tests/run.php` passed |
| 2026-04-08 | Added domain/TLS ingress service | Added Caddy in Compose with `SITE_DOMAIN`/`ACME_EMAIL` env, reverse proxy to app, and persistent cert storage |
| 2026-04-08 | Added env and docs for automatic cert issue/renew | Updated `.env.example`, README, and Bitrix setup guide with DNS/ports/domain requirements |
| 2026-04-08 | Validated compose and tests after ingress changes | `docker compose config` and `php tests/run.php` passed |
| 2026-04-08 | Routed Telegram gateway through HTTPS ingress paths | Caddy now proxies `/telegram/*` and `/v1/*` to internal gateway service |
| 2026-04-08 | Closed public direct HTTP ports for app/gateway | Updated compose ports to `127.0.0.1` bindings for `8080/8090` to stop external TLS-to-HTTP hits |
| 2026-04-08 | Implemented Bitrix setup wizard page | Replaced old panel with one-page flow that generates tokens, all required URLs, ready `.env` block, Bitrix local app fields, and copy buttons |
| 2026-04-08 | Updated Bitrix setup docs to wizard-first flow | `docs/BITRIX_SETUP_RU.md` now describes a single setup path directly from `/panel/bitrix` |
| 2026-04-08 | Verified backend tests after panel/docs refactor | `php tests/run.php` passed |
| 2026-04-08 | Added setup profile endpoint for panel autofill | Added `GET /api/bitrix/setup/profile` returning current env-backed Bitrix/domain settings and derived URLs |
| 2026-04-08 | Refined panel behavior for missing-token generation | Panel now auto-fills existing tokens and only generates `BITRIX_WEBHOOK_TOKEN`/`BITRIX_MANAGEMENT_TOKEN` when fields are empty |
| 2026-04-08 | Added SITE_DOMAIN into app runtime config | App now receives `SITE_DOMAIN` via compose/env and uses it in setup profile output |
