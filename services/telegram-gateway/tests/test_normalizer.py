from __future__ import annotations

import unittest

from app.normalizer import TelegramUpdateNormalizer


class TelegramUpdateNormalizerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.normalizer = TelegramUpdateNormalizer(
            manager_account_external_id="telegram-manager-account",
            crm_provider="bitrix",
        )

    def test_normalizes_private_text_message(self) -> None:
        message = {
            "id": 12345,
            "chat_id": 777,
            "date": 1_712_472_000,
            "sender_id": {"@type": "messageSenderUser", "user_id": 500},
            "content": {
                "@type": "messageText",
                "text": {"@type": "formattedText", "text": "Hello"},
            },
        }
        chat = {
            "id": 777,
            "title": "Alice Example",
            "type": {"@type": "chatTypePrivate", "user_id": 500},
        }

        dto = self.normalizer.normalize(message, chat)

        self.assertIsNotNone(dto)
        assert dto is not None
        self.assertEqual("tdlib:new_message:777:12345", dto.event_id)
        self.assertEqual("telegram", dto.channel_provider)
        self.assertEqual("bitrix", dto.crm_provider)
        self.assertEqual("telegram-manager-account", dto.manager_account_external_id)
        self.assertEqual("777", dto.contact_external_chat_id)
        self.assertEqual("500", dto.contact_external_user_id)
        self.assertEqual("Alice Example", dto.contact_display_name)
        self.assertEqual("12345", dto.external_message_id)
        self.assertEqual("Hello", dto.body)
        self.assertEqual([], dto.attachments)

    def test_skips_non_private_chats(self) -> None:
        message = {
            "id": 12345,
            "chat_id": 777,
            "date": 1_712_472_000,
            "sender_id": {"@type": "messageSenderUser", "user_id": 500},
            "content": {"@type": "messageText", "text": {"text": "Hello"}},
        }
        chat = {
            "id": 777,
            "title": "Some Group",
            "type": {"@type": "chatTypeSupergroup", "supergroup_id": 42},
        }

        dto = self.normalizer.normalize(message, chat)

        self.assertIsNone(dto)

    def test_extracts_document_attachment(self) -> None:
        message = {
            "id": 333,
            "chat_id": 888,
            "date": 1_712_472_000,
            "sender_id": {"@type": "messageSenderUser", "user_id": 501},
            "content": {
                "@type": "messageDocument",
                "caption": {"@type": "formattedText", "text": "See file"},
                "document": {
                    "file_name": "contract.pdf",
                    "mime_type": "application/pdf",
                    "document": {"remote": {"id": "remote-file-1"}},
                },
            },
        }
        chat = {
            "id": 888,
            "title": "Bob Example",
            "type": {"@type": "chatTypePrivate", "user_id": 501},
        }

        dto = self.normalizer.normalize(message, chat)

        self.assertIsNotNone(dto)
        assert dto is not None
        self.assertEqual("See file", dto.body)
        self.assertEqual(1, len(dto.attachments))
        self.assertEqual("document", dto.attachments[0].type)
        self.assertEqual("contract.pdf", dto.attachments[0].file_name)
        self.assertEqual("remote-file-1", dto.attachments[0].external_file_id)

    def test_normalizes_outgoing_private_message_with_peer_user(self) -> None:
        message = {
            "id": 999,
            "chat_id": 501,
            "date": 1_712_472_000,
            "is_outgoing": True,
            "sender_id": {"@type": "messageSenderUser", "user_id": 111111},
            "content": {
                "@type": "messageText",
                "text": {"@type": "formattedText", "text": "Outgoing text"},
            },
        }
        chat = {
            "id": 501,
            "title": "Peer User",
            "type": {"@type": "chatTypePrivate", "user_id": 222222},
        }

        dto = self.normalizer.normalize(message, chat)

        self.assertIsNotNone(dto)
        assert dto is not None
        self.assertEqual("222222", dto.contact_external_user_id)
        self.assertEqual("501", dto.contact_external_chat_id)
        self.assertEqual("Outgoing text", dto.body)


if __name__ == "__main__":
    unittest.main()
