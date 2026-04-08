<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Validator;

use ChatSync\App\Integration\Bitrix\UpsertManagerBitrixBindingCommand;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use InvalidArgumentException;

final class ManagerBitrixBindingValidator
{
    private const DEFAULT_CONNECTOR_ID = 'chat_sync';

    /**
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): UpsertManagerBitrixBindingCommand
    {
        $channelProvider = $this->optionalString($payload, 'channel_provider') ?? ChannelProvider::TELEGRAM->value;

        return new UpsertManagerBitrixBindingCommand(
            channelProvider: ChannelProvider::from($channelProvider),
            managerAccountExternalId: $this->requiredString($payload, 'manager_account_external_id'),
            portalDomain: $this->requiredString($payload, 'portal_domain'),
            connectorId: $this->optionalString($payload, 'connector_id') ?? self::DEFAULT_CONNECTOR_ID,
            lineId: $this->requiredString($payload, 'line_id'),
            operatorUserId: $this->optionalString($payload, 'operator_user_id'),
            isEnabled: $this->optionalBool($payload, 'is_enabled', true),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" is required and must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string or null.', $key));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalBool(array $payload, string $key, bool $default): bool
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 't', 'y'], true);
        }

        throw new InvalidArgumentException(sprintf('Field "%s" must be a boolean-like value.', $key));
    }
}
