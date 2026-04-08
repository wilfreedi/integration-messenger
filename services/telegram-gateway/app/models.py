from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any


@dataclass(frozen=True)
class AttachmentDto:
    type: str
    external_file_id: str
    file_name: str
    mime_type: str


@dataclass(frozen=True)
class InboundChannelMessageDto:
    event_id: str
    channel_provider: str
    crm_provider: str
    manager_account_external_id: str
    contact_external_chat_id: str
    contact_external_user_id: str | None
    contact_display_name: str
    external_message_id: str
    body: str
    occurred_at: str
    attachments: list[AttachmentDto] = field(default_factory=list)

    def to_payload(self) -> dict[str, Any]:
        payload = asdict(self)
        payload["attachments"] = [asdict(attachment) for attachment in self.attachments]
        return payload

