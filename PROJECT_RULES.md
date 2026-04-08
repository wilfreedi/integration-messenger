# Project Rules

## Core principles

- Core platform language: PHP 8.3+.
- Every PHP file must use `declare(strict_types=1);`.
- Domain core must not depend on SDKs, ORM, HTTP frameworks, TDLib, Bitrix SDK, or transport details.
- Telegram, Bitrix, amoCRM, WhatsApp, MAX and future systems are adapters behind interfaces.
- Follow SOLID, DRY, KISS, YAGNI.
- Business logic must live in domain/application layers, never in controllers, SDK callbacks, or ORM models.

## Architecture boundaries

- `Channel Connectors`: external channel-specific gateways and adapters.
- `Core Conversation Domain`: managers, contacts, conversations, messages, mappings, idempotency.
- `CRM Connectors`: adapters for Bitrix Open Lines, amoCRM, other CRMs.
- `Admin/API layer`: thin controllers, request validation, orchestration entrypoints.

## Mandatory modeling rules

- Keep separate identities for phone, channel user, chat, CRM dialog/thread, and CRM entity identifiers.
- Do not reuse external identifiers as internal domain identifiers.
- Maintain normalized mapping tables for:
  - contact identities
  - CRM threads
  - external message references
  - processed inbound/outbound events
- Store raw payloads only in audit/integration logs, not as a substitute for normalized business tables.

## Reliability rules

- Every inbound and outbound event must be idempotent.
- Deduplicate by event id and by provider-specific external message id.
- External operations must log:
  - `correlation_id`
  - `external_id`
  - `direction`
  - `provider`
- No repeated send to CRM or channel unless explicitly triggered by business logic.

## Coding rules

- Prefer small final classes and small methods.
- Prefer DTOs and value objects over raw associative arrays beyond the transport boundary.
- Use enums, readonly objects, typed properties, and explicit interfaces where they improve clarity.
- Avoid god services and static helpers for business logic.

## Testing rules

- Unit tests for domain and application handlers.
- Integration tests for connector adapters.
- End-to-end tests for critical sync flows.
- For each flow, consider:
  - duplicate update
  - reordered events
  - partial failure
  - retry after timeout

## MVP constraint

- No admin panel is required for the first MVP.
- Telegram personal-account authorization is done manually in the dedicated gateway service.
- The first verification goal is a working Telegram personal account to Bitrix Open Lines conversation loop.

