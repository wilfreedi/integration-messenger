<?php

declare(strict_types=1);

namespace ChatSync\App\Bootstrap;

use ChatSync\App\Http\Controller\ChannelMessageWebhookController;
use ChatSync\App\Http\Controller\CrmMessageWebhookController;
use ChatSync\App\Http\Controller\DebugStateController;
use ChatSync\App\Http\Controller\HealthController;
use ChatSync\App\Http\Controller\BitrixAppInstallController;
use ChatSync\App\Http\Controller\BitrixConnectProfileController;
use ChatSync\App\Http\Controller\BitrixIntegrationCheckController;
use ChatSync\App\Http\Controller\BitrixLogsController;
use ChatSync\App\Http\Controller\BitrixPortalsController;
use ChatSync\App\Http\Controller\BitrixTelegramGatewayAccountsController;
use ChatSync\App\Http\Controller\BitrixOpenLinesWebhookController;
use ChatSync\App\Http\Controller\BitrixSetupGenerateTokensController;
use ChatSync\App\Http\Controller\BitrixSetupProfileController;
use ChatSync\App\Http\Controller\ManagerAccountsController;
use ChatSync\App\Http\Controller\ManagerBitrixBindingController;
use ChatSync\App\Http\Controller\ManagerBitrixBindingsController;
use ChatSync\App\Http\Validator\BitrixAppInstallValidator;
use ChatSync\App\Http\Validator\BitrixOpenLinesWebhookValidator;
use ChatSync\App\Http\Validator\InboundChannelMessageValidator;
use ChatSync\App\Http\Validator\InboundCrmMessageValidator;
use ChatSync\App\Http\Validator\ManagerBitrixBindingValidator;
use ChatSync\App\Infrastructure\Logging\PdoExternalOperationLogger;
use ChatSync\App\Infrastructure\Persistence\PdoAttachmentRepository;
use ChatSync\App\Infrastructure\Persistence\PdoBitrixPortalInstallRepository;
use ChatSync\App\Infrastructure\Persistence\PdoContactIdentityRepository;
use ChatSync\App\Infrastructure\Persistence\PdoContactRepository;
use ChatSync\App\Infrastructure\Persistence\PdoConversationRepository;
use ChatSync\App\Infrastructure\Persistence\PdoCrmThreadRepository;
use ChatSync\App\Infrastructure\Persistence\PdoDeliveryRepository;
use ChatSync\App\Infrastructure\Persistence\PdoManagerAccountRepository;
use ChatSync\App\Infrastructure\Persistence\PdoManagerBitrixBindingRepository;
use ChatSync\App\Infrastructure\Persistence\PdoMessageReferenceRepository;
use ChatSync\App\Infrastructure\Persistence\PdoMessageRepository;
use ChatSync\App\Infrastructure\Persistence\PdoProcessedEventRepository;
use ChatSync\App\Integration\Bitrix\BitrixTokenManager;
use ChatSync\App\Integration\Bitrix\RegisterBitrixPortalInstallHandler;
use ChatSync\App\Integration\Bitrix\RefreshingBitrixTokenManager;
use ChatSync\App\Integration\Bitrix\StreamBitrixOAuthClient;
use ChatSync\App\Integration\Bitrix\UpsertManagerBitrixBindingHandler;
use ChatSync\App\Integration\Connector\GatewayTelegramChannelConnector;
use ChatSync\App\Integration\Connector\BitrixOpenLinesConnector;
use ChatSync\App\Integration\Connector\BitrixOpenLinesConnectorLifecycle;
use ChatSync\App\Integration\Connector\RestBitrixOpenLinesConnectorLifecycle;
use ChatSync\App\Integration\Connector\StreamBitrixRestClient;
use ChatSync\App\Integration\Connector\StreamTelegramGatewayHttpClient;
use ChatSync\App\Integration\Connector\StubBitrixOpenLinesConnector;
use ChatSync\App\Integration\Connector\StubTelegramChannelConnector;
use ChatSync\App\Query\BitrixIntegrationQuery;
use ChatSync\App\Query\ManagerAccountsQuery;
use ChatSync\App\Query\RuntimeStateInspector;
use ChatSync\App\Query\MessageMappingLookup;
use ChatSync\App\Query\IntegrationLogsQuery;
use ChatSync\Core\Application\Handler\SyncInboundChannelMessageHandler;
use ChatSync\Core\Application\Handler\SyncOutboundCrmMessageHandler;
use ChatSync\Core\Application\Port\Connector\ChannelConnector;
use ChatSync\Core\Application\Port\Connector\CrmConnector;
use ChatSync\Core\Application\Service\ArrayChannelConnectorRegistry;
use ChatSync\Core\Application\Service\ArrayCrmConnectorRegistry;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;
use ChatSync\Shared\Infrastructure\Clock\SystemClock;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Shared\Infrastructure\Id\UuidGenerator;
use ChatSync\Shared\Infrastructure\Persistence\PdoConnectionFactory;
use PDO;
use RuntimeException;

final class ApplicationContainer
{
    private ?PDO $pdo = null;
    private ?Clock $clock = null;
    private ?IdGenerator $idGenerator = null;
    private ?HealthController $healthController = null;
    private ?ChannelMessageWebhookController $channelMessageWebhookController = null;
    private ?CrmMessageWebhookController $crmMessageWebhookController = null;
    private ?BitrixOpenLinesWebhookController $bitrixOpenLinesWebhookController = null;
    private ?BitrixSetupProfileController $bitrixSetupProfileController = null;
    private ?BitrixSetupGenerateTokensController $bitrixSetupGenerateTokensController = null;
    private ?BitrixAppInstallController $bitrixAppInstallController = null;
    private ?BitrixConnectProfileController $bitrixConnectProfileController = null;
    private ?BitrixIntegrationCheckController $bitrixIntegrationCheckController = null;
    private ?BitrixLogsController $bitrixLogsController = null;
    private ?BitrixTelegramGatewayAccountsController $bitrixTelegramGatewayAccountsController = null;
    private ?BitrixPortalsController $bitrixPortalsController = null;
    private ?ManagerAccountsController $managerAccountsController = null;
    private ?ManagerBitrixBindingController $managerBitrixBindingController = null;
    private ?ManagerBitrixBindingsController $managerBitrixBindingsController = null;
    private ?DebugStateController $debugStateController = null;
    private ?StreamBitrixRestClient $bitrixRestClient = null;
    private ?StreamBitrixOAuthClient $bitrixOAuthClient = null;
    private ?BitrixTokenManager $bitrixTokenManager = null;
    private ?BitrixOpenLinesConnectorLifecycle $bitrixOpenLinesLifecycle = null;

    public function __construct(private readonly AppConfig $config)
    {
    }

    public function config(): AppConfig
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = (new PdoConnectionFactory())->create($this->config);
        }

        return $this->pdo;
    }

    public function healthController(): HealthController
    {
        return $this->healthController ??= new HealthController($this->config);
    }

    public function channelMessageWebhookController(): ChannelMessageWebhookController
    {
        return $this->channelMessageWebhookController ??= new ChannelMessageWebhookController(
            new InboundChannelMessageValidator(),
            new SyncInboundChannelMessageHandler(
                new PdoManagerAccountRepository($this->pdo()),
                new PdoContactRepository($this->pdo()),
                new PdoContactIdentityRepository($this->pdo()),
                new PdoConversationRepository($this->pdo()),
                new PdoMessageRepository($this->pdo()),
                new PdoAttachmentRepository($this->pdo()),
                new PdoCrmThreadRepository($this->pdo()),
                new PdoDeliveryRepository($this->pdo()),
                new PdoMessageReferenceRepository($this->pdo(), $this->idGenerator()),
                new PdoProcessedEventRepository($this->pdo(), $this->clock()),
                new ArrayCrmConnectorRegistry([
                    CrmProvider::BITRIX->value => $this->bitrixConnector(),
                ]),
                new PdoExternalOperationLogger($this->pdo(), $this->idGenerator(), $this->clock()),
                $this->idGenerator(),
                $this->clock(),
            ),
        );
    }

    public function crmMessageWebhookController(): CrmMessageWebhookController
    {
        return $this->crmMessageWebhookController ??= new CrmMessageWebhookController(
            new InboundCrmMessageValidator(),
            new SyncOutboundCrmMessageHandler(
                new PdoCrmThreadRepository($this->pdo()),
                new PdoConversationRepository($this->pdo()),
                new PdoManagerAccountRepository($this->pdo()),
                new PdoContactIdentityRepository($this->pdo()),
                new PdoMessageRepository($this->pdo()),
                new PdoAttachmentRepository($this->pdo()),
                new PdoDeliveryRepository($this->pdo()),
                new PdoMessageReferenceRepository($this->pdo(), $this->idGenerator()),
                new PdoProcessedEventRepository($this->pdo(), $this->clock()),
                new ArrayChannelConnectorRegistry([
                    ChannelProvider::TELEGRAM->value => $this->telegramConnector(),
                ]),
                new PdoExternalOperationLogger($this->pdo(), $this->idGenerator(), $this->clock()),
                $this->idGenerator(),
                $this->clock(),
            ),
        );
    }

    public function bitrixOpenLinesWebhookController(): BitrixOpenLinesWebhookController
    {
        return $this->bitrixOpenLinesWebhookController ??= new BitrixOpenLinesWebhookController(
            new BitrixOpenLinesWebhookValidator(
                ChannelProvider::from($this->config->bitrixDefaultChannelProvider),
            ),
            new SyncOutboundCrmMessageHandler(
                new PdoCrmThreadRepository($this->pdo()),
                new PdoConversationRepository($this->pdo()),
                new PdoManagerAccountRepository($this->pdo()),
                new PdoContactIdentityRepository($this->pdo()),
                new PdoMessageRepository($this->pdo()),
                new PdoAttachmentRepository($this->pdo()),
                new PdoDeliveryRepository($this->pdo()),
                new PdoMessageReferenceRepository($this->pdo(), $this->idGenerator()),
                new PdoProcessedEventRepository($this->pdo(), $this->clock()),
                new ArrayChannelConnectorRegistry([
                    ChannelProvider::TELEGRAM->value => $this->telegramConnector(),
                ]),
                new PdoExternalOperationLogger($this->pdo(), $this->idGenerator(), $this->clock()),
                $this->idGenerator(),
                $this->clock(),
            ),
            new MessageMappingLookup($this->pdo()),
            new PdoManagerBitrixBindingRepository($this->pdo()),
            $this->bitrixTokenManager(),
            $this->bitrixRestClient(),
            $this->config->bitrixWebhookToken,
        );
    }

    public function bitrixSetupProfileController(): BitrixSetupProfileController
    {
        return $this->bitrixSetupProfileController ??= new BitrixSetupProfileController($this->config);
    }

    public function bitrixSetupGenerateTokensController(): BitrixSetupGenerateTokensController
    {
        return $this->bitrixSetupGenerateTokensController ??= new BitrixSetupGenerateTokensController();
    }

    public function debugStateController(): DebugStateController
    {
        return $this->debugStateController ??= new DebugStateController(new RuntimeStateInspector($this->pdo()));
    }

    public function bitrixAppInstallController(): BitrixAppInstallController
    {
        return $this->bitrixAppInstallController ??= new BitrixAppInstallController(
            new BitrixAppInstallValidator(),
            new RegisterBitrixPortalInstallHandler(
                new PdoBitrixPortalInstallRepository($this->pdo()),
                $this->idGenerator(),
                $this->clock(),
            ),
        );
    }

    public function bitrixConnectProfileController(): BitrixConnectProfileController
    {
        return $this->bitrixConnectProfileController ??= new BitrixConnectProfileController(
            new BitrixAppInstallValidator(),
            new RegisterBitrixPortalInstallHandler(
                new PdoBitrixPortalInstallRepository($this->pdo()),
                $this->idGenerator(),
                $this->clock(),
            ),
            new ManagerBitrixBindingValidator(),
            new UpsertManagerBitrixBindingHandler(
                new PdoManagerAccountRepository($this->pdo()),
                new PdoBitrixPortalInstallRepository($this->pdo()),
                new PdoManagerBitrixBindingRepository($this->pdo()),
                $this->idGenerator(),
                $this->clock(),
            ),
        );
    }

    public function bitrixIntegrationCheckController(): BitrixIntegrationCheckController
    {
        return $this->bitrixIntegrationCheckController ??= new BitrixIntegrationCheckController(
            new PdoBitrixPortalInstallRepository($this->pdo()),
            new PdoManagerBitrixBindingRepository($this->pdo()),
            $this->bitrixTokenManager(),
            $this->bitrixOpenLinesLifecycle(),
            $this->bitrixRestClient(),
            $this->config->telegramGatewayBaseUrl,
            $this->config->telegramGatewayToken,
        );
    }

    public function bitrixTelegramGatewayAccountsController(): BitrixTelegramGatewayAccountsController
    {
        return $this->bitrixTelegramGatewayAccountsController ??= new BitrixTelegramGatewayAccountsController(
            $this->config->telegramGatewayBaseUrl,
            $this->config->telegramGatewayToken,
        );
    }

    public function bitrixLogsController(): BitrixLogsController
    {
        return $this->bitrixLogsController ??= new BitrixLogsController(
            new IntegrationLogsQuery($this->pdo()),
            $this->config->telegramGatewayBaseUrl,
            $this->config->telegramGatewayToken,
        );
    }

    public function bitrixPortalsController(): BitrixPortalsController
    {
        return $this->bitrixPortalsController ??= new BitrixPortalsController(
            new BitrixIntegrationQuery($this->pdo()),
        );
    }

    public function managerAccountsController(): ManagerAccountsController
    {
        return $this->managerAccountsController ??= new ManagerAccountsController(
            new ManagerAccountsQuery($this->pdo()),
        );
    }

    public function managerBitrixBindingController(): ManagerBitrixBindingController
    {
        return $this->managerBitrixBindingController ??= new ManagerBitrixBindingController(
            new ManagerBitrixBindingValidator(),
            new UpsertManagerBitrixBindingHandler(
                new PdoManagerAccountRepository($this->pdo()),
                new PdoBitrixPortalInstallRepository($this->pdo()),
                new PdoManagerBitrixBindingRepository($this->pdo()),
                $this->idGenerator(),
                $this->clock(),
            ),
        );
    }

    public function managerBitrixBindingsController(): ManagerBitrixBindingsController
    {
        return $this->managerBitrixBindingsController ??= new ManagerBitrixBindingsController(
            new BitrixIntegrationQuery($this->pdo()),
        );
    }

    public function clock(): Clock
    {
        return $this->clock ??= new SystemClock();
    }

    public function idGenerator(): IdGenerator
    {
        return $this->idGenerator ??= new UuidGenerator();
    }

    private function telegramConnector(): ChannelConnector
    {
        return match ($this->config->telegramConnectorMode) {
            'stub' => new StubTelegramChannelConnector(),
            'gateway' => new GatewayTelegramChannelConnector(
                $this->config,
                new StreamTelegramGatewayHttpClient(),
            ),
            default => throw new RuntimeException(sprintf(
                'Unsupported TELEGRAM_CONNECTOR_MODE "%s".',
                $this->config->telegramConnectorMode,
            )),
        };
    }

    private function bitrixConnector(): CrmConnector
    {
        return match ($this->config->bitrixConnectorMode) {
            'stub' => new StubBitrixOpenLinesConnector(),
            'rest' => new BitrixOpenLinesConnector(
                $this->bitrixRestClient(),
                new PdoManagerBitrixBindingRepository($this->pdo()),
                $this->bitrixTokenManager(),
                $this->bitrixOpenLinesLifecycle(),
            ),
            default => throw new RuntimeException(sprintf(
                'Unsupported BITRIX_CONNECTOR_MODE "%s".',
                $this->config->bitrixConnectorMode,
            )),
        };
    }

    private function bitrixRestClient(): StreamBitrixRestClient
    {
        return $this->bitrixRestClient ??= new StreamBitrixRestClient();
    }

    private function bitrixOAuthClient(): StreamBitrixOAuthClient
    {
        return $this->bitrixOAuthClient ??= new StreamBitrixOAuthClient();
    }

    private function bitrixTokenManager(): BitrixTokenManager
    {
        return $this->bitrixTokenManager ??= new RefreshingBitrixTokenManager(
            $this->bitrixOAuthClient(),
            new PdoBitrixPortalInstallRepository($this->pdo()),
            $this->clock(),
        );
    }

    private function bitrixOpenLinesLifecycle(): BitrixOpenLinesConnectorLifecycle
    {
        return $this->bitrixOpenLinesLifecycle ??= new RestBitrixOpenLinesConnectorLifecycle(
            $this->bitrixRestClient(),
            $this->placementHandlerUrl(),
        );
    }

    private function placementHandlerUrl(): string
    {
        if ($this->config->siteDomain !== '') {
            return 'https://' . $this->config->siteDomain . '/panel/bitrix';
        }

        return 'https://example.com/panel/bitrix';
    }
}
