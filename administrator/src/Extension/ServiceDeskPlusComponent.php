<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Announcements\AnnouncementService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Assets\AssetService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditLogInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\IdentityResolver;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\MappingManager;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\UserMapRepositoryInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\ConversationReader;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestApprovalsReader;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\DecisionLock;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimiter;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\SubmissionGuard;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Service\SdpClientFactory;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Settings\SettingsRepository;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Users\UserProfileService;

/**
 * Component class. Gives controllers and models access to the services registered in
 * services/provider.php.
 */
final class ServiceDeskPlusComponent extends MVCComponent implements RouterServiceInterface
{
    use RouterServiceTrait;

    private ?ContainerInterface $services = null;

    public function setServiceContainer(ContainerInterface $services): void
    {
        $this->services = $services;
    }

    public function getSdpClientFactory(): SdpClientFactory
    {
        return $this->service(SdpClientFactory::class);
    }

    public function getSettingsRepository(): SettingsRepository
    {
        return $this->service(SettingsRepository::class);
    }

    public function getIdentityResolver(): IdentityResolver
    {
        return $this->service(IdentityResolver::class);
    }

    public function getUserMapRepository(): UserMapRepositoryInterface
    {
        return $this->service(UserMapRepositoryInterface::class);
    }

    public function getMappingManager(): MappingManager
    {
        return $this->service(MappingManager::class);
    }

    public function getDecisionLock(): DecisionLock
    {
        return $this->service(DecisionLock::class);
    }

    public function getRequestService(): RequestService
    {
        return $this->service(RequestService::class);
    }

    public function getConversationReader(): ConversationReader
    {
        return $this->service(ConversationReader::class);
    }

    public function getUserProfileService(): UserProfileService
    {
        return $this->service(UserProfileService::class);
    }

    public function getAnnouncementService(): AnnouncementService
    {
        return $this->service(AnnouncementService::class);
    }

    public function getRequestApprovalsReader(): RequestApprovalsReader
    {
        return $this->service(RequestApprovalsReader::class);
    }

    public function getAssetService(): AssetService
    {
        return $this->service(AssetService::class);
    }

    public function getApprovalService(): ApprovalService
    {
        return $this->service(ApprovalService::class);
    }

    public function getAuditLog(): AuditLogInterface
    {
        return $this->service(AuditLogInterface::class);
    }

    public function getUserCache(): UserCacheInterface
    {
        return $this->service(UserCacheInterface::class);
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->service(RateLimiter::class);
    }

    public function getSubmissionGuard(): SubmissionGuard
    {
        return $this->service(SubmissionGuard::class);
    }

    /**
     * @template T of object
     * @param  class-string<T>  $id
     * @return T
     */
    private function service(string $id): object
    {
        if ($this->services === null) {
            throw new \LogicException('Service container not set');
        }

        return $this->services->get($id);
    }
}
