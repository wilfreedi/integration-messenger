# Установка Bitrix24: один простой путь

Ниже только один вариант подключения. Делай шаги по порядку.

## 1. Подготовь проект

В `.env` укажи:

```bash
BITRIX_CONNECTOR_MODE=rest
BITRIX_WEBHOOK_TOKEN=your_webhook_token
BITRIX_MANAGEMENT_TOKEN=your_management_token
BITRIX_DEFAULT_CHANNEL_PROVIDER=telegram
SITE_DOMAIN=chat.example.com
ACME_EMAIL=admin@example.com
```

Перезапусти приложение:

```bash
docker compose up --build -d
```

Важно для сертификата:
- `SITE_DOMAIN` должен смотреть DNS-записью на IP сервера.
- Порты `80` и `443` должны быть открыты.
- Сертификат выпускается и продлевается автоматически сервисом `caddy`.

## 2. Что сделать в самом Bitrix24

1. Открой `Контакт-центр -> Открытые линии`.
2. Создай линию (или выбери существующую), назначь операторов.
3. Возьми `line_id` этой линии.
4. В настройке внешнего коннектора/приложения укажи webhook:

```text
https://<SITE_DOMAIN>/api/webhooks/bitrix/open-lines?token=<BITRIX_WEBHOOK_TOKEN>
```

5. В разделе `Разработчикам` установи приложение с правами `imconnector`.
6. После установки получи 5 значений:
- `domain`
- `access_token`
- `refresh_token`
- `application_token`
- `client_endpoint` (обычно `https://<domain>/rest`)

## 3. Подключи портал в нашей панели

1. Открой:

```text
https://<SITE_DOMAIN>/panel/bitrix
```

2. Заполни поля:
- `Менеджер`
- `line_id`
- `domain`
- `access_token`
- `refresh_token`
- `application_token`

3. Нажми `Подключить`.

Готово. На этом установка закончена.

## 4. Проверка (обязательно)

1. Отправь сообщение из Telegram клиенту.
2. Убедись, что оно появилось в Open Lines.
3. Ответь оператором в Open Lines.
4. Убедись, что ответ вернулся в Telegram.

Если это работает, интеграция настроена правильно.
