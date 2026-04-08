from __future__ import annotations


def dashboard_html() -> str:
    return """<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Telegram Gateway Console</title>
  <style>
    :root {
      --bg-0: #f7f7f5;
      --bg-1: #fcfbf8;
      --panel: #ffffff;
      --ink: #1f2329;
      --muted: #5a6472;
      --accent: #0c7a6c;
      --accent-2: #145db8;
      --warn: #a64d00;
      --error: #b42318;
      --line: #d6dce5;
      --shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
      --radius: 14px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      font: 15px/1.45 "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif;
      background:
        radial-gradient(1200px 500px at -10% -20%, #d7efe8 0%, transparent 62%),
        radial-gradient(1000px 460px at 110% 0%, #dde8f9 0%, transparent 60%),
        linear-gradient(180deg, var(--bg-1), var(--bg-0));
      min-height: 100vh;
    }
    .shell {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: 18px;
      max-width: 1360px;
      margin: 0 auto;
      padding: 20px;
    }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    .left {
      display: flex;
      flex-direction: column;
      gap: 14px;
      min-height: calc(100vh - 40px);
    }
    .right {
      display: grid;
      grid-template-rows: auto auto 1fr;
      gap: 14px;
      min-height: calc(100vh - 40px);
    }
    .section {
      padding: 14px;
    }
    h1 {
      margin: 0;
      font-size: 20px;
      letter-spacing: 0.2px;
    }
    h2 {
      margin: 0 0 10px;
      font-size: 14px;
      color: var(--muted);
      letter-spacing: 0.28px;
      text-transform: uppercase;
    }
    .toolbar {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-top: 10px;
    }
    button {
      border: 1px solid var(--line);
      background: #f4f7fb;
      color: var(--ink);
      border-radius: 9px;
      padding: 8px 12px;
      cursor: pointer;
      font-weight: 600;
    }
    button.primary {
      background: linear-gradient(135deg, #0b8f7d, var(--accent));
      border-color: #0b8475;
      color: #fff;
    }
    button.secondary {
      background: linear-gradient(135deg, #2e6cc8, var(--accent-2));
      border-color: #2b62b3;
      color: #fff;
    }
    button:disabled {
      opacity: 0.6;
      cursor: default;
    }
    .linkbtn {
      display: inline-flex;
      align-items: center;
      border: 1px solid var(--line);
      border-radius: 9px;
      background: #f4f7fb;
      color: var(--ink);
      padding: 8px 12px;
      font-weight: 600;
      text-decoration: none;
    }
    input {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 9px;
      padding: 8px 10px;
      font: inherit;
      color: var(--ink);
      background: #fff;
    }
    .stack { display: grid; gap: 8px; }
    .hint { color: var(--muted); font-size: 12px; }
    .accounts {
      display: flex;
      flex-direction: column;
      gap: 8px;
      max-height: 48vh;
      overflow: auto;
      padding-right: 2px;
    }
    .account {
      border: 1px solid var(--line);
      border-radius: 11px;
      padding: 9px;
      cursor: pointer;
      background: #fff;
      transition: 120ms ease background;
    }
    .account:hover { background: #f7fbff; }
    .account.selected {
      border-color: #91b7f0;
      background: #f1f7ff;
    }
    .account .name {
      font-weight: 700;
      font-size: 13px;
      margin-bottom: 4px;
      word-break: break-all;
    }
    .badge {
      display: inline-block;
      font-size: 11px;
      border-radius: 999px;
      border: 1px solid var(--line);
      padding: 2px 8px;
      color: var(--muted);
      background: #fff;
    }
    .badge.ok {
      color: var(--accent);
      border-color: #9ad8ce;
      background: #eaf9f6;
    }
    .badge.warn {
      color: var(--warn);
      border-color: #f4c999;
      background: #fff4e8;
    }
    .row {
      display: grid;
      gap: 10px;
      grid-template-columns: 320px 1fr;
      align-items: start;
    }
    .card {
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      padding: 10px;
    }
    .chats {
      max-height: 58vh;
      overflow: auto;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .chat {
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 8px;
      cursor: pointer;
      background: #fff;
    }
    .chat.selected {
      border-color: #88b2f1;
      background: #eff5ff;
    }
    .messages {
      max-height: 58vh;
      overflow: auto;
      display: flex;
      flex-direction: column;
      gap: 8px;
      padding-right: 2px;
    }
    .msg {
      border-radius: 10px;
      border: 1px solid var(--line);
      padding: 8px 10px;
      background: #fff;
      word-break: break-word;
    }
    .msg.out {
      border-color: #8ecfc7;
      background: #ebfaf6;
      align-self: flex-end;
      max-width: 88%;
    }
    .msg.in {
      border-color: #c8d4e8;
      background: #f5f8fd;
      align-self: flex-start;
      max-width: 88%;
    }
    .meta {
      font-size: 11px;
      color: var(--muted);
      margin-bottom: 2px;
    }
    .status {
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 8px 10px;
      background: #fff;
      font-size: 13px;
      color: var(--muted);
      min-height: 40px;
    }
    .status.error {
      color: var(--error);
      border-color: #f2b8b5;
      background: #fff4f4;
    }
    @media (max-width: 1024px) {
      .shell {
        grid-template-columns: 1fr;
      }
      .left, .right {
        min-height: auto;
      }
      .row {
        grid-template-columns: 1fr;
      }
      .chats, .messages, .accounts {
        max-height: 38vh;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <section class="left">
      <div class="panel section">
        <h1>Telegram Gateway</h1>
        <div class="toolbar">
          <button class="secondary" id="refreshAccountsBtn">Обновить</button>
          <a class="linkbtn" href="http://127.0.0.1:8080/panel/bitrix.html" target="_blank" rel="noopener">Bitrix Panel</a>
        </div>
        <div class="hint" style="margin-top:8px;">Без регистрации и авторизации. Локальная панель для ручного тестирования TDLib-сессий.</div>
      </div>

      <div class="panel section">
        <h2>Добавить Аккаунт</h2>
        <div class="stack">
          <input id="newPhone" placeholder="+79991234567" />
          <input id="newManager" placeholder="manager external id (опционально)" />
          <button class="primary" id="addAccountBtn">Добавить</button>
        </div>
      </div>

      <div class="panel section">
        <h2>Аккаунты</h2>
        <div class="accounts" id="accountsList"></div>
      </div>
    </section>

    <section class="right">
      <div class="panel section">
        <h2>Авторизация Аккаунта</h2>
        <div class="row">
          <div class="stack">
            <input id="codeInput" placeholder="Код из Telegram" />
            <button id="sendCodeBtn">Подтвердить Код</button>
          </div>
          <div class="stack">
            <input id="passwordInput" type="password" placeholder="2FA пароль (если включен)" />
            <button id="sendPasswordBtn">Подтвердить Пароль</button>
          </div>
        </div>
      </div>

      <div class="panel section">
        <h2>Статус</h2>
        <div id="statusBox" class="status">Выбери аккаунт или создай новый.</div>
      </div>

      <div class="panel section">
        <h2>Чаты И Сообщения</h2>
        <div class="row">
          <div class="card">
            <div class="toolbar" style="margin-top:0;">
              <button id="refreshChatsBtn">Загрузить Чаты</button>
            </div>
            <div class="chats" id="chatsList" style="margin-top:8px;"></div>
          </div>
          <div class="card">
            <div class="messages" id="messagesList"></div>
            <div class="stack" style="margin-top:8px;">
              <input id="messageInput" placeholder="Текст сообщения" />
              <button class="primary" id="sendMessageBtn">Отправить В Чат</button>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <script>
    const state = {
      accounts: [],
      selectedAccountId: null,
      selectedChatId: null,
    };

    const statusBox = document.getElementById("statusBox");
    const accountsList = document.getElementById("accountsList");
    const chatsList = document.getElementById("chatsList");
    const messagesList = document.getElementById("messagesList");

    function setStatus(message, isError = false) {
      statusBox.textContent = message;
      statusBox.className = isError ? "status error" : "status";
    }

    async function api(path, options = {}) {
      const response = await fetch(path, {
        headers: {"Content-Type": "application/json"},
        ...options
      });
      const payload = await response.json();
      if (!response.ok) {
        throw new Error(payload.message || "Request failed");
      }
      return payload;
    }

    function authBadgeClass(stateText) {
      return stateText === "authorizationStateReady" ? "badge ok" : "badge warn";
    }

    function renderAccounts() {
      accountsList.innerHTML = "";
      state.accounts.forEach((account) => {
        const item = document.createElement("div");
        item.className = "account" + (state.selectedAccountId === account.account_id ? " selected" : "");
        item.innerHTML = `
          <div class="name">${account.manager_account_external_id}</div>
          <div class="${authBadgeClass(account.authorization_state)}">${account.authorization_state}</div>
          <div class="hint" style="margin-top:5px;">${account.phone_number || "телефон не указан"}</div>
        `;
        item.onclick = () => {
          state.selectedAccountId = account.account_id;
          state.selectedChatId = null;
          renderAccounts();
          renderChats([]);
          renderMessages([]);
          setStatus("Аккаунт выбран: " + account.manager_account_external_id);
        };
        accountsList.appendChild(item);
      });
    }

    function renderChats(chats) {
      chatsList.innerHTML = "";
      chats.forEach((chat) => {
        const item = document.createElement("div");
        item.className = "chat" + (String(state.selectedChatId) === String(chat.chat_id) ? " selected" : "");
        item.innerHTML = `
          <div style="font-weight:700; font-size:13px;">${chat.title}</div>
          <div class="hint">${chat.chat_id}</div>
          <div class="hint">${chat.last_message_text || ""}</div>
        `;
        item.onclick = async () => {
          state.selectedChatId = chat.chat_id;
          renderChats(chats);
          await loadMessages();
        };
        chatsList.appendChild(item);
      });
    }

    function renderMessages(messages) {
      messagesList.innerHTML = "";
      messages.forEach((message) => {
        const item = document.createElement("div");
        item.className = "msg " + (message.is_outgoing ? "out" : "in");
        item.innerHTML = `
          <div class="meta">${message.date || ""} ${message.sender || ""}</div>
          <div>${message.text || ""}</div>
        `;
        messagesList.appendChild(item);
      });
      messagesList.scrollTop = messagesList.scrollHeight;
    }

    async function refreshAccounts() {
      const payload = await api("/v1/accounts");
      state.accounts = payload.accounts || [];
      if (state.selectedAccountId && !state.accounts.find((x) => x.account_id === state.selectedAccountId)) {
        state.selectedAccountId = null;
      }
      renderAccounts();
    }

    async function createAccount() {
      const phone = document.getElementById("newPhone").value.trim();
      const manager = document.getElementById("newManager").value.trim();
      if (!phone) {
        setStatus("Укажи телефон в международном формате.", true);
        return;
      }

      const payload = await api("/v1/accounts", {
        method: "POST",
        body: JSON.stringify({
          phone_number: phone,
          manager_account_external_id: manager || undefined
        })
      });
      state.selectedAccountId = payload.account.account_id;
      setStatus("Аккаунт создан. Проверь код в Telegram и введи его в поле подтверждения.");
      document.getElementById("newPhone").value = "";
      await refreshAccounts();
    }

    function requireAccount() {
      if (!state.selectedAccountId) {
        throw new Error("Сначала выбери аккаунт.");
      }
      return state.selectedAccountId;
    }

    async function submitCode() {
      const accountId = requireAccount();
      const code = document.getElementById("codeInput").value.trim();
      if (!code) {
        setStatus("Введите код подтверждения.", true);
        return;
      }
      const payload = await api(`/v1/accounts/${encodeURIComponent(accountId)}/auth/code`, {
        method: "POST",
        body: JSON.stringify({code})
      });
      setStatus("Код отправлен. Текущее состояние: " + payload.account.authorization_state);
      document.getElementById("codeInput").value = "";
      await refreshAccounts();
    }

    async function submitPassword() {
      const accountId = requireAccount();
      const password = document.getElementById("passwordInput").value.trim();
      if (!password) {
        setStatus("Введите пароль 2FA.", true);
        return;
      }
      const payload = await api(`/v1/accounts/${encodeURIComponent(accountId)}/auth/password`, {
        method: "POST",
        body: JSON.stringify({password})
      });
      setStatus("Пароль отправлен. Текущее состояние: " + payload.account.authorization_state);
      document.getElementById("passwordInput").value = "";
      await refreshAccounts();
    }

    async function loadChats() {
      const accountId = requireAccount();
      const payload = await api(`/v1/accounts/${encodeURIComponent(accountId)}/chats`);
      renderChats(payload.chats || []);
      setStatus("Чаты загружены: " + (payload.chats || []).length);
    }

    async function loadMessages() {
      const accountId = requireAccount();
      if (!state.selectedChatId) {
        setStatus("Выбери чат в списке.", true);
        return;
      }
      const payload = await api(`/v1/accounts/${encodeURIComponent(accountId)}/chats/${encodeURIComponent(state.selectedChatId)}/messages`);
      renderMessages(payload.messages || []);
    }

    async function sendMessage() {
      const accountId = requireAccount();
      if (!state.selectedChatId) {
        setStatus("Выбери чат перед отправкой.", true);
        return;
      }
      const body = document.getElementById("messageInput").value.trim();
      if (!body) {
        setStatus("Введите текст сообщения.", true);
        return;
      }
      await api(`/v1/accounts/${encodeURIComponent(accountId)}/chats/${encodeURIComponent(state.selectedChatId)}/messages`, {
        method: "POST",
        body: JSON.stringify({body})
      });
      document.getElementById("messageInput").value = "";
      await loadMessages();
      setStatus("Сообщение отправлено.");
    }

    document.getElementById("refreshAccountsBtn").onclick = () => withError(refreshAccounts);
    document.getElementById("addAccountBtn").onclick = () => withError(createAccount);
    document.getElementById("sendCodeBtn").onclick = () => withError(submitCode);
    document.getElementById("sendPasswordBtn").onclick = () => withError(submitPassword);
    document.getElementById("refreshChatsBtn").onclick = () => withError(loadChats);
    document.getElementById("sendMessageBtn").onclick = () => withError(sendMessage);

    async function withError(fn) {
      try {
        await fn();
      } catch (error) {
        setStatus(error.message || "Ошибка запроса", true);
      }
    }

    withError(refreshAccounts);
  </script>
</body>
</html>
"""
