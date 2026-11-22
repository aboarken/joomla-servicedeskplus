<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Announcements\AnnouncementService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Assets\AssetService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Assets\LoanAssetConfig;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditLogInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\DbAuditLog;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\DbUserCache;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Extension\ServiceDeskPlusComponent;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\DbUserMapRepository;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\IdentityResolver;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\MappingManager;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\UserMapRepositoryInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Log\JoomlaLogger;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\ConversationReader;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestApprovalsReader;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\DecisionLock;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimiter;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\SecretProvider;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\SubmissionGuard;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Service\SdpClientFactory;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Settings\SettingsRepository;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Users\UserProfileService;

return new class () implements ServiceProviderInterface {
    private const TOKENS_SESSION_KEY = 'com_servicedeskplus.submissions';

    public function register(Container $container): void
    {
        $namespace = '\\SdpPortal\\Component\\ServiceDeskPlus';

        $container->registerServiceProvider(new MVCFactory($namespace));
        $container->registerServiceProvider(new ComponentDispatcherFactory($namespace));
        $container->registerServiceProvider(new RouterFactory($namespace));

        $container->share(JoomlaLogger::class, static fn (): JoomlaLogger => new JoomlaLogger());

        $container->share(
            SettingsRepository::class,
            static fn (Container $c): SettingsRepository => new SettingsRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            SecretProvider::class,
            static fn (): SecretProvider => new SecretProvider((string) Factory::getApplication()->get('secret', ''))
        );

        $container->share(
            SdpClientFactory::class,
            static fn (Container $c): SdpClientFactory => new SdpClientFactory(
                $c->get(SettingsRepository::class),
                $c->get(SecretProvider::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            DbAuditLog::class,
            static fn (Container $c): DbAuditLog => new DbAuditLog($c->get(DatabaseInterface::class), $c->get(JoomlaLogger::class))
        );
        $container->alias(AuditLogInterface::class, DbAuditLog::class);

        $container->share(
            UserMapRepositoryInterface::class,
            static fn (Container $c): UserMapRepositoryInterface => new DbUserMapRepository($c->get(DatabaseInterface::class))
        );

        $container->share(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(DbAuditLog::class)));

        // Per-user cache in #__sdp_cache: survives logout, and parallel requests do not overwrite it.
        $container->share(
            UserCacheInterface::class,
            static fn (Container $c): UserCacheInterface => new DbUserCache(
                $c->get(DatabaseInterface::class),
                $c->get(SettingsRepository::class)->load()->cacheTtl(),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            SubmissionGuard::class,
            static fn (): SubmissionGuard => new SubmissionGuard(
                static fn (): array => (array) Factory::getApplication()->getSession()->get(self::TOKENS_SESSION_KEY, []),
                static fn (array $data) => Factory::getApplication()->getSession()->set(self::TOKENS_SESSION_KEY, $data)
            )
        );

        $serviceClient = static fn (Container $c): \Closure => static fn () => $c->get(SdpClientFactory::class)->createServiceClient();

        $container->share(
            IdentityResolver::class,
            static fn (Container $c): IdentityResolver => new IdentityResolver(
                $c->get(UserMapRepositoryInterface::class),
                $serviceClient($c),
                $c->get(AuditLogInterface::class),
                $c->get(JoomlaLogger::class),
                cache: $c->get(UserCacheInterface::class),
                autoApprove: $c->get(SettingsRepository::class)->load()->autoApproveMatches(),
                apiAccountEmail: $c->get(SettingsRepository::class)->load()->approvalAccountEmail()
            )
        );

        $container->share(
            MappingManager::class,
            static fn (Container $c): MappingManager => new MappingManager(
                $c->get(UserMapRepositoryInterface::class),
                $serviceClient($c),
                $c->get(AuditLogInterface::class),
                cache: $c->get(UserCacheInterface::class),
                apiAccountEmail: $c->get(SettingsRepository::class)->load()->approvalAccountEmail()
            )
        );

        $container->share(
            RequestService::class,
            static function (Container $c) use ($serviceClient): RequestService {
                $settings = $c->get(SettingsRepository::class)->load();

                return new RequestService(
                    $serviceClient($c),
                    $c->get(UserCacheInterface::class),
                    $c->get(AuditLogInterface::class),
                    $c->get(RateLimiter::class),
                    $c->get(JoomlaLogger::class),
                    $settings->requestStatuses(),
                    $settings->rateCreatePerHour()
                );
            }
        );

        $container->share(
            ConversationReader::class,
            static fn (Container $c): ConversationReader => new ConversationReader(
                $serviceClient($c),
                $c->get(UserCacheInterface::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            UserProfileService::class,
            static fn (Container $c): UserProfileService => new UserProfileService(
                $serviceClient($c),
                $c->get(UserCacheInterface::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            AnnouncementService::class,
            static fn (Container $c): AnnouncementService => new AnnouncementService(
                $serviceClient($c),
                $c->get(UserCacheInterface::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            RequestApprovalsReader::class,
            static fn (Container $c): RequestApprovalsReader => new RequestApprovalsReader(
                $serviceClient($c),
                $c->get(UserCacheInterface::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            AssetService::class,
            static fn (Container $c): AssetService => new AssetService(
                $serviceClient($c),
                new LoanAssetConfig(),
                $c->get(UserCacheInterface::class),
                $c->get(AuditLogInterface::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            ApprovalService::class,
            static function (Container $c) use ($serviceClient): ApprovalService {
                $settings = $c->get(SettingsRepository::class)->load();

                return new ApprovalService(
                    $serviceClient($c),
                    $c->get(UserCacheInterface::class),
                    $c->get(AuditLogInterface::class),
                    $c->get(DbAuditLog::class),
                    $c->get(RateLimiter::class),
                    $c->get(JoomlaLogger::class),
                    $settings->rateDecidePerHour(),
                    approvalAccountEmail: $settings->approvalAccountEmail(),
                    modules: $settings->approvalModules(),
                    concurrency: $settings->parallelRequests(),
                    lock: new DecisionLock((string) (Factory::getApplication()->get('tmp_path') ?: sys_get_temp_dir()))
                );
            }
        );

        $container->set(
            ComponentInterface::class,
            static function (Container $c): ServiceDeskPlusComponent {
                $component = new ServiceDeskPlusComponent($c->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($c->get(MVCFactoryInterface::class));
                $component->setRouterFactory($c->get(RouterFactoryInterface::class));
                $component->setServiceContainer($c);

                return $component;
            }
        );
    }
};
