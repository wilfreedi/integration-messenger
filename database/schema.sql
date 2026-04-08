CREATE TABLE IF NOT EXISTS managers (
    id UUID PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS manager_accounts (
    id UUID PRIMARY KEY,
    manager_id UUID NOT NULL REFERENCES managers (id),
    channel_provider VARCHAR(32) NOT NULL,
    external_account_id VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (channel_provider, external_account_id)
);

CREATE TABLE IF NOT EXISTS contacts (
    id UUID PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL,
    primary_phone VARCHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS contact_identities (
    id UUID PRIMARY KEY,
    contact_id UUID NOT NULL REFERENCES contacts (id),
    provider VARCHAR(32) NOT NULL,
    identity_type VARCHAR(64) NOT NULL,
    identity_value VARCHAR(255) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (provider, identity_type, identity_value)
);

CREATE INDEX IF NOT EXISTS idx_contact_identities_contact_provider
    ON contact_identities (contact_id, provider, identity_type);

CREATE TABLE IF NOT EXISTS conversations (
    id UUID PRIMARY KEY,
    manager_account_id UUID NOT NULL REFERENCES manager_accounts (id),
    contact_id UUID NOT NULL REFERENCES contacts (id),
    status VARCHAR(32) NOT NULL,
    opened_at TIMESTAMPTZ NOT NULL,
    last_activity_at TIMESTAMPTZ NOT NULL,
    UNIQUE (manager_account_id, contact_id)
);

CREATE INDEX IF NOT EXISTS idx_conversations_manager_account
    ON conversations (manager_account_id);

CREATE TABLE IF NOT EXISTS crm_threads (
    id UUID PRIMARY KEY,
    conversation_id UUID NOT NULL REFERENCES conversations (id),
    crm_provider VARCHAR(32) NOT NULL,
    external_thread_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (crm_provider, external_thread_id),
    UNIQUE (conversation_id, crm_provider)
);

CREATE INDEX IF NOT EXISTS idx_crm_threads_conversation
    ON crm_threads (conversation_id);

CREATE TABLE IF NOT EXISTS messages (
    id UUID PRIMARY KEY,
    conversation_id UUID NOT NULL REFERENCES conversations (id),
    direction VARCHAR(32) NOT NULL,
    body TEXT NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_messages_conversation
    ON messages (conversation_id, occurred_at);

CREATE TABLE IF NOT EXISTS attachments (
    id UUID PRIMARY KEY,
    message_id UUID NOT NULL REFERENCES messages (id),
    attachment_type VARCHAR(64) NOT NULL,
    external_file_id VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_attachments_message
    ON attachments (message_id);

CREATE TABLE IF NOT EXISTS deliveries (
    id UUID PRIMARY KEY,
    message_id UUID NOT NULL REFERENCES messages (id),
    system_type VARCHAR(32) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    direction VARCHAR(32) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_deliveries_message
    ON deliveries (message_id);

CREATE INDEX IF NOT EXISTS idx_deliveries_external
    ON deliveries (provider, external_id);

CREATE TABLE IF NOT EXISTS message_mappings (
    id UUID PRIMARY KEY,
    system_type VARCHAR(32) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    scope_id UUID NOT NULL,
    external_message_id VARCHAR(255) NOT NULL,
    internal_message_id UUID NOT NULL REFERENCES messages (id),
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (system_type, provider, scope_id, external_message_id)
);

CREATE INDEX IF NOT EXISTS idx_message_mappings_internal_message
    ON message_mappings (internal_message_id);

CREATE TABLE IF NOT EXISTS processed_events (
    source VARCHAR(64) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    processed_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (source, event_id)
);

CREATE TABLE IF NOT EXISTS integration_settings (
    id UUID PRIMARY KEY,
    owner_type VARCHAR(32) NOT NULL,
    owner_id UUID NOT NULL,
    provider VARCHAR(32) NOT NULL,
    endpoint_url TEXT NOT NULL,
    auth_scheme VARCHAR(32) NOT NULL,
    auth_token TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_integration_settings_owner
    ON integration_settings (owner_type, owner_id, provider);

CREATE TABLE IF NOT EXISTS audit_logs (
    id UUID PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    direction VARCHAR(32) NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    operation VARCHAR(64) NOT NULL,
    payload JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_lookup
    ON audit_logs (provider, direction, correlation_id);

CREATE TABLE IF NOT EXISTS outbox_messages (
    id UUID PRIMARY KEY,
    topic VARCHAR(128) NOT NULL,
    payload JSONB NOT NULL,
    available_at TIMESTAMPTZ NOT NULL,
    processed_at TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS bitrix_portals (
    id UUID PRIMARY KEY,
    portal_domain VARCHAR(255) NOT NULL,
    member_id VARCHAR(255) NULL,
    app_status VARCHAR(32) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    UNIQUE (portal_domain),
    UNIQUE (member_id)
);

CREATE TABLE IF NOT EXISTS bitrix_app_installs (
    id UUID PRIMARY KEY,
    portal_id UUID NOT NULL REFERENCES bitrix_portals (id),
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    scope TEXT NOT NULL,
    application_token VARCHAR(255) NOT NULL,
    rest_base_url TEXT NOT NULL,
    oauth_client_id VARCHAR(255) NULL,
    oauth_client_secret VARCHAR(255) NULL,
    oauth_server_endpoint TEXT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    UNIQUE (portal_id)
);

CREATE INDEX IF NOT EXISTS idx_bitrix_app_installs_active
    ON bitrix_app_installs (active, portal_id);

CREATE TABLE IF NOT EXISTS manager_bitrix_bindings (
    id UUID PRIMARY KEY,
    manager_account_id UUID NOT NULL REFERENCES manager_accounts (id),
    portal_id UUID NOT NULL REFERENCES bitrix_portals (id),
    connector_id VARCHAR(128) NOT NULL,
    line_id VARCHAR(64) NOT NULL,
    operator_user_id VARCHAR(64) NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    UNIQUE (manager_account_id, portal_id, connector_id)
);

CREATE INDEX IF NOT EXISTS idx_manager_bitrix_bindings_lookup
    ON manager_bitrix_bindings (manager_account_id, is_enabled);
