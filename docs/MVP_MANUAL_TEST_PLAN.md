# MVP Manual Test Plan

## Scope

Manual verification without admin panel:

- authorize one manager Telegram personal account manually in the gateway
- connect one Bitrix Open Lines workspace
- verify two-way message sync

## Recommended MVP split

- PHP core platform in this repository
- dedicated Telegram gateway service for MTProto/user-account access
- Bitrix Open Lines connector as CRM adapter

## Manual preparation

1. Create a `Manager` and `ManagerAccount` in the database for the manager Telegram account.
2. Configure Bitrix credentials and Open Lines connector settings in `integration_settings`.
3. Authorize Telegram personal account manually in the gateway using phone, code, and optional 2FA password.
4. Register webhook/event delivery from the gateway to the PHP core.
5. Register Bitrix webhook delivery to the PHP core.

## Expected checks

1. New Telegram inbound message creates or resolves:
   - contact
   - contact identities
   - conversation
   - CRM thread
2. The message appears in Bitrix Open Lines once.
3. Bitrix reply reaches the same Telegram chat once.
4. Duplicate gateway or Bitrix retries do not create duplicate messages.

## Critical edge cases

- same event delivered twice
- same external message delivered with a different event id
- out-of-order retries after a timeout
- Bitrix thread created but local transaction failed
- Telegram send succeeded but mapping write failed

## Before production rollout

- replace synchronous sends with inbox/outbox workers
- add retries with exponential backoff
- add dead-letter handling
- add per-connector health checks
- add encrypted credential storage

