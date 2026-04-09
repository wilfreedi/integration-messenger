# Runbook MVP

## Цель

Поднять локальный runnable-контур для ручной проверки логики синхронизации:

- Telegram-like inbound event
- Bitrix-like outbound reply
- запись всех ключевых сущностей в PostgreSQL

## 1. Подготовка

```bash
cp .env.example .env
```

Если нужен другой порт или имя базы, меняй `.env`.

## 2. Запуск

```bash
docker compose up --build -d
```

Проверка:

```bash
curl http://127.0.0.1:8080/health
```

Ожидается JSON со статусом `ok`.

Проверка Telegram gateway:

```bash
curl http://127.0.0.1:8090/health
```

Открыть UI-панель Telegram gateway:

```bash
open http://127.0.0.1:8090/
```

Открыть UI-панель Bitrix integration:

```bash
open http://127.0.0.1:8080/panel/bitrix
```

В панели доступен один простой сценарий подключения:
- заполняешь данные портала, менеджера и `line_id`
- нажимаешь `Подключить`
- система делает подключение за один шаг.

## 3. Автоматический smoke сценарий

```bash
docker compose exec app php scripts/smoke_test.php
```

Сценарий:

1. Делает `GET /health`
2. Шлет inbound channel event
3. Читает созданный `crm_thread`
4. Шлет outbound CRM event
5. Возвращает summary по таблицам

## 4. Ручной inbound test

```bash
curl -X POST http://127.0.0.1:8080/api/webhooks/channel-message \
  -H 'Content-Type: application/json' \
  -d '{
    "event_id": "manual-telegram-event-1",
    "channel_provider": "telegram",
    "crm_provider": "bitrix",
    "manager_account_external_id": "telegram-manager-account",
    "contact_external_chat_id": "telegram-chat-501",
    "contact_external_user_id": "telegram-user-501",
    "contact_display_name": "Manual Contact",
    "external_message_id": "telegram-message-501",
    "body": "Тестовое сообщение в CRM",
    "occurred_at": "2026-04-07T13:00:00+05:00",
    "attachments": []
  }'
```

После этого проверь состояние:

```bash
curl http://127.0.0.1:8080/api/debug/state
```

Или:

```bash
docker compose exec app php scripts/inspect_state.php
```

## 5. Ручной outbound test

Сначала найди `external_thread_id`:

```bash
docker compose exec app php scripts/inspect_state.php
```

Потом отправь CRM reply:

```bash
curl -X POST http://127.0.0.1:8080/api/webhooks/crm-message \
  -H 'Content-Type: application/json' \
  -d '{
    "event_id": "manual-bitrix-event-1",
    "crm_provider": "bitrix",
    "channel_provider": "telegram",
    "external_thread_id": "bitrix-thread-<conversation_uuid>",
    "external_message_id": "bitrix-message-501",
    "body": "Ответ из CRM",
    "occurred_at": "2026-04-07T13:05:00+05:00",
    "attachments": []
  }'
```

## 6. Проверка идемпотентности

Повтори тот же запрос, но:

- оставь тот же `external_message_id`
- поменяй `event_id`

Ожидаемое поведение:

- статус `skipped`
- причина `duplicate_external_message`
- новые `messages` и `deliveries` не создаются

## 7. Что смотреть в базе

- `contacts`
- `contact_identities`
- `conversations`
- `crm_threads`
- `messages`
- `attachments`
- `deliveries`
- `message_mappings`
- `processed_events`
- `audit_logs`

## 7.1. Как очистить состояние между ручными прогонами

Если ты специально хочешь начать с чистого состояния application-таблиц:

```bash
make reset
```

Команда очищает application data и заново добавляет demo manager account.

## 8. Ограничения текущей версии

Этот runbook проверяет core platform и операционный контур, но не заменяет:

- реальную авторизацию Telegram personal account
- реальный transport adapter Bitrix Open Lines
- очереди, retries и outbox workers

## 9. Ручная авторизация Telegram gateway

Чтобы использовать отдельный сервис на TDLib:

1. Заполни в `.env`:

```bash
TELEGRAM_CONNECTOR_MODE=gateway
TELEGRAM_API_ID=...
TELEGRAM_API_HASH=...
```

2. Перезапусти стек:

```bash
docker compose up --build -d
```

3. Отправь телефон:

```bash
curl -X POST http://127.0.0.1:8090/v1/auth/phone \
  -H 'Content-Type: application/json' \
  -d '{"phone_number":"+79991234567"}'
```

4. Отправь код:

```bash
curl -X POST http://127.0.0.1:8090/v1/auth/code \
  -H 'Content-Type: application/json' \
  -d '{"code":"12345"}'
```

5. Если включена 2FA:

```bash
curl -X POST http://127.0.0.1:8090/v1/auth/password \
  -H 'Content-Type: application/json' \
  -d '{"password":"your-2fa-password"}'
```

6. Проверь состояние:

```bash
curl http://127.0.0.1:8090/v1/auth/state
```

Когда сервис дойдет до `authorizationStateReady`, inbound сообщения из private chats начнут форвардиться в PHP core webhook.

Примечание: Docker-образ gateway ставит `libtdjson` из системного Debian-пакета. Это сокращает время сборки и убирает нестабильность из-за компиляции TDLib внутри контейнера.

## 10. Быстрый ручной flow через UI

1. Открой `http://127.0.0.1:8090/`
2. Добавь аккаунт по номеру телефона
3. Введи код из Telegram
4. Если нужно, введи пароль 2FA
5. Загрузи список чатов
6. Выбери чат, прочитай историю
7. Отправь тестовое сообщение

## 11. Реальная интеграция с Bitrix Open Lines

Детальная настройка Bitrix по шагам: [BITRIX_SETUP_RU.md](/Users/user/Desktop/work/chat/docs/BITRIX_SETUP_RU.md#L1).

1. В `.env` включи `rest` режим:

```bash
BITRIX_CONNECTOR_MODE=rest
BITRIX_WEBHOOK_TOKEN=<optional_shared_token>
BITRIX_MANAGEMENT_TOKEN=<optional_token_for_/api/bitrix/*>
TELEGRAM_GATEWAY_SYNC_MANAGER_OUTGOING=0
```

2. Перезапусти приложение:

```bash
docker compose up --build -d app
```

3. В настройках Bitrix Open Lines custom connector укажи webhook для операторских сообщений:

```text
POST https://<your-host>/api/webhooks/bitrix/open-lines?token=<BITRIX_WEBHOOK_TOKEN>
```

4. Для app/OAuth сценария зарегистрируй портал и binding:

```bash
curl -X POST http://127.0.0.1:8080/api/bitrix/app/install \
  -H 'X-Integration-Token: <BITRIX_MANAGEMENT_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{
    "auth": {
      "domain": "<portal>.bitrix24.ru",
      "client_endpoint": "https://<portal>.bitrix24.ru/rest",
      "access_token": "<oauth_access_token>",
      "refresh_token": "<oauth_refresh_token>",
      "application_token": "<application_token>"
    }
  }'
```

```bash
curl -X POST http://127.0.0.1:8080/api/bitrix/bindings \
  -H 'X-Integration-Token: <BITRIX_MANAGEMENT_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{
    "channel_provider": "telegram",
    "manager_account_external_id": "telegram-manager-account",
    "portal_domain": "<portal>.bitrix24.ru",
    "line_id": "<open_line_id>",
    "is_enabled": true
  }'
```

`connector_id` в этом запросе можно не передавать: используется внутреннее значение по умолчанию `chat_sync`.

5. Проверка маршрута вручную (пример payload):

```bash
curl -X POST 'http://127.0.0.1:8080/api/webhooks/bitrix/open-lines?token=dev-token' \
  -H 'Content-Type: application/json' \
  -d '{
    "event": "OnSendMessageCustom",
    "data": {
      "CONNECTOR": "chat_sync",
      "LINE": "1",
      "DATA": [
        {
          "im": {"chat_id": "bitrix-thread-<conversation_uuid>", "message_id": "im-msg-1"},
          "chat": {"id": "bitrix-thread-<conversation_uuid>"},
          "message": {"id": ["bitrix-msg-1"], "text": "Ответ оператора", "date_create": "2026-04-07T18:30:00+05:00"}
        }
      ]
    }
  }'
```

6. Ожидаемое поведение:

- сообщение оператора попадает в `SyncOutboundCrmMessageHandler`
- уходит в Telegram gateway
- в таблицах появляются `messages`, `deliveries`, `message_mappings`
- при `BITRIX_CONNECTOR_MODE=rest` приложение отправляет delivery status обратно в Bitrix
