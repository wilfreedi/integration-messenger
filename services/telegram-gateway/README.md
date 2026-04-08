# Telegram Gateway

Отдельный сервис для Telegram personal account integration на официальном TDLib.

## Почему так

- для personal account sync нельзя опираться на Bot API
- MTProto/authorization/session storage не должны жить в PHP core
- официальный Telegram client stack для этого сценария: `TDLib`

Сервис использует официальный `tdjson` interface TDLib и предоставляет API + встроенную UI-панель:

- список Telegram-аккаунтов и создание нового аккаунта
- ручная авторизация по телефону / коду / 2FA
- сохранение TDLib-сессий на диск (переживает перезапуск контейнера)
- просмотр чатов и чтение истории сообщений
- отправка outbound сообщений из core
- прием `updateNewMessage` из TDLib и forwarding в PHP core

Docker-образ сервиса использует системный пакет `libtdjson` из Debian-репозитория, а не собирает TDLib из исходников при каждом `docker compose build`.

## Endpoints

### `GET /`

Веб-панель для ручного тестирования:

- список аккаунтов
- добавление аккаунта
- ввод кода/2FA
- список чатов
- чтение/отправка сообщений

В панели есть быстрый переход на отдельную страницу Bitrix integration panel:

- `http://127.0.0.1:8080/panel/bitrix`

### `GET /health`

Техническое состояние сервиса.

### `GET /v1/accounts`

Список аккаунтов и их текущих authorization-state.

### `POST /v1/accounts`

```json
{
  "phone_number": "+79991234567",
  "manager_account_external_id": "telegram-manager-1"
}
```

### `POST /v1/accounts/{account_id}/manager`

Перепривязка уже авторизованного Telegram-аккаунта к другому `manager_account_external_id` без переавторизации:

```json
{
  "manager_account_external_id": "telegram-manager-account"
}
```

### `POST /v1/accounts/{account_id}/auth/code`

```json
{
  "code": "12345"
}
```

### `POST /v1/accounts/{account_id}/auth/password`

```json
{
  "password": "your-2fa-password"
}
```

### `GET /v1/accounts/{account_id}/chats`

Список чатов выбранного аккаунта.

### `GET /v1/accounts/{account_id}/chats/{chat_id}/messages`

История сообщений выбранного чата.

### `POST /v1/accounts/{account_id}/chats/{chat_id}/messages`

```json
{
  "body": "Привет из UI"
}
```

### `POST /v1/messages/send` (legacy для PHP core connector)

```json
{
  "manager_account_external_id": "telegram-manager-account",
  "external_chat_id": "123456789",
  "body": "Привет",
  "correlation_id": "corr-123",
  "attachments": []
}
```

### Legacy auth endpoints

- `GET /v1/auth/state`
- `POST /v1/auth/phone`
- `POST /v1/auth/code`
- `POST /v1/auth/password`

Оставлены для обратной совместимости со старым runtime-сценарием.

## Ограничения текущего MVP

- поддержка inbound ориентирована на private chats
- outbound сейчас отправляет текст; attachments пока не реализованы
- для реального использования нужно указать `TELEGRAM_API_ID` и `TELEGRAM_API_HASH`

## Локальные тесты

```bash
cd services/telegram-gateway
PYTHONPATH=. python tests/run.py
```
