<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Requests;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Support\HtmlText;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Support\SdpFields;

/**
 * The conversation of a request (e-mails and notes) as the requester would see it.
 *
 * Only items ServiceDesk Plus marks show_to_requester are returned, so technicians' internal notes
 * and e-mails never reach the portal. The caller must have checked that the user may see the
 * request (its requester, or the approver of one of its approvals).
 */
final class ConversationReader
{
    /** Newest items read per request; each needs one extra call for its text (sent in parallel). */
    public const MAX_ITEMS = 20;

    /**
     * @param  \Closure(): SdpClient  $clientFactory
     */
    public function __construct(
        private readonly \Closure $clientFactory,
        private readonly UserCacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Oldest first. Problems reading the conversation are logged and give what could be read.
     *
     * @return list<ConversationEntry>
     *
     * @throws SdpException  network, authentication and configuration problems
     */
    public function forRequest(Identity $identity, string $requestId): array
    {
        $requestId = SdpClient::id($requestId);
        $cacheKey  = 'conversation:' . $requestId;
        $cached    = $this->cache->get($identity, $cacheKey);

        if ($cached !== null) {
            return array_map(static fn (array $a) => ConversationEntry::fromArray($a), $cached);
        }

        $client = ($this->clientFactory)();
        $fatal  = [SdpException::NETWORK, SdpException::AUTH, SdpException::NOT_CONFIGURED];

        try {
            $items = $client->getRequestConversations($requestId, self::MAX_ITEMS);
        } catch (SdpException $e) {
            if (\in_array($e->getKind(), $fatal, true)) {
                throw $e;
            }

            $this->logger->warning(\sprintf('Conversation of request %s could not be read (%s)', $requestId, $e->getKind()));

            return [];
        }

        // Only items marked for the requester are read in full, all at once.
        $visible = array_values(array_filter(\array_slice($items, 0, self::MAX_ITEMS), static fn ($i): bool => \is_array($i) && self::visibleToRequester($i)));
        $fulls   = $client->getConversationItems($requestId, array_map(static fn (array $i): string => SdpFields::string($i, 'content_url'), $visible));
        $entries = [];

        foreach ($visible as $n => $item) {
            $full = $fulls[$n] ?? null;

            if ($full instanceof SdpException) {
                if (\in_array($full->getKind(), $fatal, true)) {
                    throw $full;
                }

                $this->logger->warning(\sprintf('A conversation item of request %s could not be read (%s)', $requestId, $full->getKind()));

                continue;
            }

            if (!\is_array($full)) {
                continue;
            }

            $isNote = strtolower(SdpFields::string($item, 'type')) === 'notes';

            // The full item must agree: a note or e-mail not meant for the requester is never shown.
            if (\array_key_exists('show_to_requester', $full) && !filter_var($full['show_to_requester'], FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $entries[] = $isNote
                ? new ConversationEntry(
                    ConversationEntry::TYPE_NOTE,
                    SdpFields::name($full, 'added_by') ?: SdpFields::name($item, 'performed_by'),
                    SdpFields::timestamp($full, 'added_time') ?? SdpFields::timestamp($item, 'performed_time'),
                    '',
                    HtmlText::toPlain(SdpFields::string($full, 'description'))
                )
                : new ConversationEntry(
                    ConversationEntry::TYPE_EMAIL,
                    SdpFields::name($full, 'sender') ?: SdpFields::name($item, 'from'),
                    SdpFields::timestamp($full, 'sent_time') ?? SdpFields::timestamp($item, 'sent_time'),
                    SdpFields::string($full, 'subject'),
                    HtmlText::toPlain(SdpFields::string($full, 'description'))
                );
        }

        // SDP lists newest first; a conversation reads oldest first.
        usort($entries, static fn (ConversationEntry $a, ConversationEntry $b): int => ($a->time ?? 0) <=> ($b->time ?? 0));

        $this->cache->set($identity, $cacheKey, array_map(static fn (ConversationEntry $e) => $e->toArray(), $entries));

        return $entries;
    }

    /** @param array<string, mixed> $item */
    private static function visibleToRequester(array $item): bool
    {
        return filter_var($item['show_to_requester'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
