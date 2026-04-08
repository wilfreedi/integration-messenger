# Architecture

## Recommended solution

Use a modular integration platform with a dedicated Telegram gateway service and a PHP core platform.

- `telegram-gateway`:
  - owns MTProto/user-account authorization
  - normalizes Telegram updates into channel events
  - receives outbound send commands from the core
- `core-platform`:
  - owns domain model and sync orchestration
  - owns idempotency, deduplication, mapping tables, audit logging
  - routes channel events to CRM connectors and CRM events back to channel connectors
- `crm-connectors`:
  - Bitrix Open Lines adapter now
  - amoCRM adapter later without changing domain logic

## Why this is the right boundary

- Telegram personal-account sync requires MTProto client behavior, session handling, and authorization flow that should not contaminate the PHP business core.
- Bitrix Open Lines is only one CRM adapter. Conversation is the main domain concept, not Bitrix dialog.
- Future channels and CRMs add adapters, not rewrites of the core domain.

## Layering

### Domain

- `Manager`
- `ManagerAccount`
- `Contact`
- `ContactIdentity`
- `Conversation`
- `Message`
- `Attachment`
- `Delivery`
- `CRMThread`
- `IntegrationSettings`

### Application

- use cases / commands / handlers
- port interfaces for repositories, connectors, clock, id generator, audit logger
- idempotent orchestration of sync flows

### Infrastructure

- persistence implementations
- queue producers/consumers
- connector SDK clients
- webhook consumers

### Admin/API

- thin controllers
- validation and DTO mapping
- no business logic

## MVP flow

### Inbound from Telegram to Bitrix

1. Telegram gateway receives MTProto update from authorized manager account.
2. Gateway normalizes update and sends `SyncInboundChannelMessageCommand` into the core.
3. Core deduplicates event and external message id.
4. Core resolves `ManagerAccount`, `ContactIdentity`, `Conversation`.
5. Core ensures `CRMThread` in Bitrix Open Lines.
6. Core sends message to Bitrix connector.
7. Core stores delivery, mappings, and audit log.

### Outbound from Bitrix to Telegram

1. Bitrix webhook hits the core.
2. Core deduplicates event and CRM external message id.
3. Core resolves `CRMThread`, `Conversation`, `ManagerAccount`, `ContactIdentity`.
4. Core sends message to Telegram channel connector.
5. Core stores delivery, mappings, and audit log.

## MVP runtime recommendation

- HTTP for inbound webhook entrypoints is fine for the first manual test.
- PostgreSQL for normalized storage.
- Redis or queue broker is optional for the first sync demo, but inbox/outbox tables should already exist.
- Move connector dispatch to async workers before scaling or onboarding multiple managers.

