<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum ContactIdentityType: string
{
    case PHONE = 'phone';
    case CHANNEL_USER_ID = 'channel_user_id';
    case CHANNEL_CHAT_ID = 'channel_chat_id';
    case CRM_CONTACT_ID = 'crm_contact_id';
    case CRM_THREAD_ID = 'crm_thread_id';
}

