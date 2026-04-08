# Платформа синхронизации переписки менеджеров

MVP-контур платформы синхронизации переписки из внешних каналов в CRM с архитектурой, в которой:

- доменное ядро не зависит от Telegram, Bitrix, SDK и HTTP framework
- каналы и CRM подключаются как адаптеры
- Telegram personal account логика не тащится в PHP core
- уже есть двусторонняя orchestration-логика, idempotency, deduplication и mapping tables

Текущий runnable-контур ориентирован на ручную проверку потока:

- inbound: `Telegram gateway -> PHP core -> Bitrix connector`
- outbound: `Bitrix webhook -> PHP core -> Telegram connector`

Сейчас runtime для ручной проверки использует `stub`-адаптеры Bitrix и Telegram. Это сделано специально: ядро уже интеграционно готово, но реальные transport-specific адаптеры не нужно писать "на глаз" без отдельной верификации контрактов.

## Что уже есть

- production-oriented каркас на `PHP 8.3+`
- hexagonal/clean boundaries
- нормализованная PostgreSQL-схема
- HTTP entrypoints для ручной проверки
- Docker Compose для быстрого запуска
- отдельный `telegram-gateway` сервис на официальном TDLib
- smoke script для прогона inbound/outbound сценария
- unit tests на ключевую orchestration-логику
- action/task logs

## Структура проекта

```text
src/
  App/
    Bootstrap/
    Http/
    Infrastructure/
    Integration/
    Query/
  Core/
    Application/
    Domain/
  Shared/
database/
docker/
docs/
public/
scripts/
services/
  telegram-gateway/
tests/
```

## Быстрый старт через Docker

1. Создать локальный env:

```bash
cp .env.example .env
```

2. Поднять окружение:

```bash
docker compose up --build -d
```

3. Проверить health:

```bash
curl http://127.0.0.1:8080/health
```

4. Прогнать smoke check:

```bash
docker compose exec app php scripts/smoke_test.php
```

5. Посмотреть текущее состояние:

```bash
docker compose exec app php scripts/inspect_state.php
```

6. Проверить состояние Telegram gateway:

```bash
curl http://127.0.0.1:8090/health
```

7. Открыть локальную UI-панель Telegram gateway:

```bash
open http://127.0.0.1:8090/
```

8. Открыть отдельную UI-страницу Bitrix integration panel:

```bash
open http://127.0.0.1:8080/panel/bitrix
```

## HTTP endpoints для ручной проверки

### `GET /health`

Проверка, что приложение поднято.

### `POST /api/webhooks/channel-message`

Нормализованный inbound event из channel connector/gateway в core.

Пример:

```bash
curl -X POST http://127.0.0.1:8080/api/webhooks/channel-message \
  -H 'Content-Type: application/json' \
  -d '{
    "event_id": "telegram-event-1",
    "channel_provider": "telegram",
    "crm_provider": "bitrix",
    "manager_account_external_id": "telegram-manager-account",
    "contact_external_chat_id": "telegram-chat-42",
    "contact_external_user_id": "telegram-user-7",
    "contact_display_name": "Alice Example",
    "external_message_id": "telegram-message-1",
    "body": "Привет из Telegram",
    "occurred_at": "2026-04-07T12:00:00+05:00",
    "attachments": []
  }'
```

### `POST /api/webhooks/crm-message`

Нормализованный inbound event из CRM connector/webhook в core.

Пример:

```bash
curl -X POST http://127.0.0.1:8080/api/webhooks/crm-message \
  -H 'Content-Type: application/json' \
  -d '{
    "event_id": "bitrix-event-1",
    "crm_provider": "bitrix",
    "channel_provider": "telegram",
    "external_thread_id": "bitrix-thread-<conversation_uuid>",
    "external_message_id": "bitrix-message-1",
    "body": "Ответ из Bitrix",
    "occurred_at": "2026-04-07T12:05:00+05:00",
    "attachments": []
  }'
```

### `GET /api/debug/state`

Возвращает snapshot таблиц для ручной проверки потока.

## Telegram gateway

Отдельный сервис лежит в [services/telegram-gateway/README.md](/Users/user/Desktop/work/chat/services/telegram-gateway/README.md#L1) и построен вокруг официального TDLib `tdjson`.

Контейнер gateway использует пакетную поставку `libtdjson` из Debian-репозитория, чтобы локальная разработка и CI не зависели от тяжелой сборки TDLib из исходников внутри Docker.

Для ручной проверки без отдельной админки есть встроенный сайт `http://127.0.0.1:8090/`:

- список аккаунтов
- добавление нового аккаунта
- ввод кода и 2FA-пароля
- список чатов
- чтение/отправка сообщений

## Bitrix Open Lines (REST)

Подробная пошаговая инструкция: [docs/BITRIX_SETUP_RU.md](/Users/user/Desktop/work/chat/docs/BITRIX_SETUP_RU.md#L1).

Для реальной интеграции Bitrix включи `rest`-адаптер.

Минимальная конфигурация:

```bash
BITRIX_CONNECTOR_MODE=rest
BITRIX_WEBHOOK_TOKEN=<optional_shared_token>
BITRIX_MANAGEMENT_TOKEN=<optional_token_for_/api/bitrix/*>
```

Что делает `rest`-адаптер:

- `channel -> CRM`: отправляет сообщения в Open Lines методом `imconnector.send.messages`
- `CRM -> channel`: принимает операторские события через `/api/webhooks/bitrix/open-lines`
- отправляет delivery confirmation обратно в Bitrix методом `imconnector.send.status.delivery` (если включен `rest` и есть соответствующие message mappings)

Webhook URL для Bitrix события оператора:

```text
POST https://<your-host>/api/webhooks/bitrix/open-lines?token=<BITRIX_WEBHOOK_TOKEN>
```

Если `BITRIX_WEBHOOK_TOKEN` пустой, параметр `token` не обязателен.

Ограничение Bitrix: по официальной документации `imconnector.*` методы в текущей версии не работают через webhook-контекст. Если получаешь `WRONG_AUTH_TYPE`, потребуется app/OAuth контекст и отдельный auth-слой для Bitrix адаптера.

### API управления Bitrix-интеграцией

Для app/OAuth сценария и маршрутизации по менеджерам доступны API endpoint'ы:

- `POST /api/bitrix/app/install` - upsert установки портала (`access_token`, `refresh_token`, `client_endpoint`, `domain`).
- `POST /api/bitrix/connect-profile` - one-shot подключение: install + binding (аналог "подключить профиль").
- `GET /api/bitrix/portals` - список подключенных порталов.
- `POST /api/bitrix/bindings` - привязка manager account к порталу и Open Line.
- `GET /api/bitrix/bindings` - список активных привязок.
- `GET /api/manager-accounts` - список manager accounts для панели подключения.

Если задан `BITRIX_MANAGEMENT_TOKEN`, вызовы `/api/bitrix/*` должны содержать token:
- `X-Integration-Token: <token>` (рекомендуется)
- или `Authorization: Bearer <token>`
- или query-параметр `?token=<token>`

В `BITRIX_CONNECTOR_MODE=rest` коннектор сначала ищет binding по `manager_account_external_id`.
Если binding найден, отправка в Bitrix идет по `rest_base_url + access_token` из таблиц интеграции.
Если binding не найден, событие не отправляется и возвращается понятная ошибка конфигурации binding для manager account.

`connector_id` в binding фиксирован по умолчанию (`chat_sync`) и не требует настройки в `.env`.

Чтобы включить реальный outbound из core в gateway:

1. В `.env` переключить:

```bash
TELEGRAM_CONNECTOR_MODE=gateway
```

2. Заполнить Telegram credentials:

```bash
TELEGRAM_API_ID=...
TELEGRAM_API_HASH=...
```

3. Перезапустить Docker Compose:

```bash
docker compose up --build -d
```

4. Пройти ручную авторизацию:

```bash
curl -X POST http://127.0.0.1:8090/v1/auth/phone \
  -H 'Content-Type: application/json' \
  -d '{"phone_number":"+79991234567"}'
```

```bash
curl -X POST http://127.0.0.1:8090/v1/auth/code \
  -H 'Content-Type: application/json' \
  -d '{"code":"12345"}'
```

При включенной 2FA:

```bash
curl -X POST http://127.0.0.1:8090/v1/auth/password \
  -H 'Content-Type: application/json' \
  -d '{"password":"your-2fa-password"}'
```

Проверить статус:

```bash
curl http://127.0.0.1:8090/v1/auth/state
```

## Что важно понимать про текущий runtime

- `BITRIX_CONNECTOR_MODE=stub`
- `TELEGRAM_CONNECTOR_MODE=stub`
- для реального Telegram gateway нужен `TELEGRAM_CONNECTOR_MODE=gateway`
- demo manager account создается автоматически:
  - `external_account_id = telegram-manager-account`
- PostgreSQL schema автоматически накатывается при старте контейнера

Это значит, что прямо сейчас можно проверить:

- идемпотентность событий
- создание contact/conversation/crm thread/message/delivery/mappings
- двусторонний flow внутри core
- корректность Docker-окружения и ручного integration contour

Но нельзя честно утверждать, что уже сделана production-ready работа с:

- реальным Telegram MTProto gateway
- автоматическим refresh/access rotation для Bitrix OAuth токенов
- retries/outbox workers

## Полезные команды

Если удобно, можно использовать `make`:

```bash
make up
make smoke
make state
make reset
make test
make down
```

## Тесты и проверки

Локально без Docker:

```bash
composer dump-autoload
php tests/run.php
```

Проверено в репозитории:

- `composer dump-autoload`
- `php tests/run.php`
- `PYTHONPATH=. python tests/run.py` в `services/telegram-gateway`
- `docker compose config`
- `php -l` на `src`, `public`, `scripts`, `tests`

## Документация

- [PROJECT_RULES.md](/Users/user/Desktop/work/chat/PROJECT_RULES.md)
- [docs/ARCHITECTURE.md](/Users/user/Desktop/work/chat/docs/ARCHITECTURE.md)
- [docs/RUNBOOK_RU.md](/Users/user/Desktop/work/chat/docs/RUNBOOK_RU.md)
- [database/schema.sql](/Users/user/Desktop/work/chat/database/schema.sql)
- [services/telegram-gateway/README.md](/Users/user/Desktop/work/chat/services/telegram-gateway/README.md)
- [ACTION_LOG.md](/Users/user/Desktop/work/chat/ACTION_LOG.md)
- [TASK_LOG.md](/Users/user/Desktop/work/chat/TASK_LOG.md)

## Следующий правильный шаг

Следующие шаги для production-hardening:

1. Добавить refresh OAuth токенов Bitrix (и безопасное хранение секретов).
2. Вынести отправку интеграций в outbox + retries workers.
3. Добавить метрики/алерты по webhook latency, delivery ack и ошибкам connector API.
