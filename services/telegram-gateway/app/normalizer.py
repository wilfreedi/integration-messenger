from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from app.models import AttachmentDto, InboundChannelMessageDto


class TelegramUpdateNormalizer:
    def __init__(self, manager_account_external_id: str, crm_provider: str) -> None:
        self._manager_account_external_id = manager_account_external_id
        self._crm_provider = crm_provider

    def normalize(self, message: dict[str, Any], chat: dict[str, Any]) -> InboundChannelMessageDto | None:
        chat_type = (chat.get("type") or {}).get("@type")
        if chat_type != "chatTypePrivate":
            return None

        content = message.get("content") or {}
        body, attachments = self._extract_body_and_attachments(content)
        is_outgoing = bool(message.get("is_outgoing", False))

        sender_id = message.get("sender_id") or {}
        contact_user_id = None
        if is_outgoing:
            user_id = (chat.get("type") or {}).get("user_id")
            if user_id is not None:
                contact_user_id = str(user_id)
        elif sender_id.get("@type") == "messageSenderUser":
            contact_user_id = str(sender_id.get("user_id"))
        elif (chat.get("type") or {}).get("@type") == "chatTypePrivate":
            user_id = (chat.get("type") or {}).get("user_id")
            if user_id is not None:
                contact_user_id = str(user_id)

        occurred_at = datetime.fromtimestamp(message["date"], tz=timezone.utc).isoformat()
        chat_id = str(message["chat_id"])
        message_id = str(message["id"])

        return InboundChannelMessageDto(
            event_id=f"tdlib:new_message:{chat_id}:{message_id}",
            channel_provider="telegram",
            crm_provider=self._crm_provider,
            manager_account_external_id=self._manager_account_external_id,
            contact_external_chat_id=chat_id,
            contact_external_user_id=contact_user_id,
            contact_display_name=(chat.get("title") or "").strip() or chat_id,
            external_message_id=message_id,
            body=body,
            occurred_at=occurred_at,
            attachments=attachments,
        )

    def _extract_body_and_attachments(self, content: dict[str, Any]) -> tuple[str, list[AttachmentDto]]:
        content_type = content.get("@type")

        if content_type == "messageText":
            text = (((content.get("text") or {}).get("text")) or "").strip()
            return text, []

        if content_type == "messagePhoto":
            caption = (((content.get("caption") or {}).get("text")) or "").strip()
            photo = content.get("photo") or {}
            sizes = photo.get("sizes") or []
            remote_id = ""
            if sizes:
                largest = sizes[-1]
                remote_id = (((largest.get("photo") or {}).get("remote")) or {}).get("id", "")

            return caption or "[photo]", [
                AttachmentDto(
                    type="photo",
                    external_file_id=remote_id,
                    file_name="photo",
                    mime_type="image/jpeg",
                )
            ]

        if content_type == "messageDocument":
            caption = (((content.get("caption") or {}).get("text")) or "").strip()
            document_wrapper = content.get("document") or {}
            document = document_wrapper.get("document") or {}
            file_name = document_wrapper.get("file_name", "") or "document"
            mime_type = document_wrapper.get("mime_type", "") or "application/octet-stream"
            remote_id = ((document.get("remote") or {}).get("id")) or ""

            return caption or f"[document] {file_name}", [
                AttachmentDto(
                    type="document",
                    external_file_id=remote_id,
                    file_name=file_name,
                    mime_type=mime_type,
                )
            ]

        if content_type == "messageSticker":
            return "[sticker]", []

        if content_type == "messageVoiceNote":
            return "[voice_note]", []

        if content_type == "messageAnimation":
            caption = (((content.get("caption") or {}).get("text")) or "").strip()
            return caption or "[animation]", []

        return f"[{content_type or 'unsupported'}]", []
