<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\ParallelTransportInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportResponse;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;

/**
 * The single gateway to ServiceDesk Plus On-Premises REST API v3.
 *
 * Handles authentication headers, input_data encoding, error mapping, pagination and logging.
 * Controllers and views never call SDP directly; models call this class.
 *
 * Every call uses the service (technician) key. SDP therefore sees all actions as that
 * technician; the services in this component enforce per-user ownership before each call.
 */
final class SdpClient
{
    public const ACCEPT = 'application/vnd.manageengine.sdp.v3+json';

    /** Extra attempts for a GET that fails to connect, and the pause before it (microseconds). */
    private const GET_RETRIES    = 1;
    private const RETRY_DELAY_US = 300_000;

    /** Reads sent at once by getMany() unless the caller says otherwise. */
    public const DEFAULT_CONCURRENCY = 6;

    /** Safety cap for listAll() so a bad has_more_rows value cannot loop forever. */
    private const MAX_PAGES = 50;

    /** @var list<string> */
    private array $secrets;

    /** @var array{dns: int, connect: int, tls: int, server: int, total: int, reused: bool}|null */
    private ?array $lastTiming = null;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ClientConfig $config,
        #[\SensitiveParameter] private readonly string $serviceKey,
        private readonly LoggerInterface $logger
    ) {
        if (trim($serviceKey) === '') {
            throw new SdpException(SdpException::NOT_CONFIGURED, 'The service API key is not configured');
        }

        $this->secrets = [$serviceKey];
        PageStats::start($logger);
    }

    /**
     * @param  array<string, mixed>|null  $input  Decoded input_data, sent as a query parameter
     *
     * @return array<string, mixed>
     */
    public function get(string $path, ?array $input = null): array
    {
        return $this->call('GET', $path, $input, $this->serviceKey);
    }

    /**
     * @param  array<string, mixed>  $input  Decoded input_data, sent as a form field
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $input): array
    {
        return $this->call('POST', $path, $input, $this->serviceKey);
    }

    /**
     * Fetch one page of a list endpoint.
     *
     * @param  string                $collectionKey  Response key holding the list, e.g. "requests"
     * @param  array<string, mixed>  $extra          Extra top-level input_data keys (e.g. fields_required)
     */
    public function listPage(string $path, string $collectionKey, ListInfo $listInfo, array $extra = []): ListPage
    {
        $input = ['list_info' => $listInfo->toArray()] + $extra;
        $data  = $this->get($path, $input);
        $items = $data[$collectionKey] ?? [];

        if (!\is_array($items) || !array_is_list($items)) {
            throw new SdpException(SdpException::BAD_RESPONSE, 'Unexpected list response shape');
        }

        $meta = \is_array($data['list_info'] ?? null) ? $data['list_info'] : [];

        return new ListPage(
            array_values(array_filter($items, 'is_array')),
            self::toBool($meta['has_more_rows'] ?? false),
            isset($meta['total_count']) && is_numeric($meta['total_count']) ? (int) $meta['total_count'] : null
        );
    }

    /**
     * Fetch pages until has_more_rows is false or $maxItems is reached.
     *
     * Pagination per the docs: next start_index = previous start_index + row_count.
     *
     * @param  array<string, mixed>  $extra
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(string $path, string $collectionKey, ListInfo $listInfo, int $maxItems = 500, array $extra = []): array
    {
        $all      = [];
        $listInfo = $listInfo->withRowCount(ListInfo::MAX_ROW_COUNT);

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->listPage($path, $collectionKey, $listInfo, $extra);

            foreach ($result->items as $item) {
                $all[] = $item;
            }

            if (!$result->hasMoreRows || $result->items === [] || \count($all) >= $maxItems) {
                break;
            }

            $listInfo = $listInfo->withStartIndex($listInfo->startIndex + $listInfo->rowCount);
        }

        return \array_slice($all, 0, $maxItems);
    }

    /**
     * Harmless read used by "Test connection": GET /api/v3/requests with one row and only the id.
     *
     * @throws SdpException
     */
    public function testConnection(): void
    {
        $this->listPage('/api/v3/requests', 'requests', new ListInfo(rowCount: 1));
    }

    /**
     * Read-only check of any v3 path for Find Endpoints: GET only. A list path is sent list_info
     * (with get_total_count); a path ending in an id is read as one record. The rows are taken from
     * the first list in the answer, else from its first object.
     *
     * @throws SdpException
     */
    public function probe(string $path, ?ListInfo $listInfo = null): ProbeResult
    {
        $single = (bool) preg_match('#/[0-9]{1,20}$#', $path);
        $data   = $this->get($path, $single || $listInfo === null ? null : ['list_info' => $listInfo->toArray()]);
        $meta   = \is_array($data['list_info'] ?? null) ? $data['list_info'] : [];
        $total  = isset($meta['total_count']) && is_numeric($meta['total_count']) ? (int) $meta['total_count'] : null;
        $more   = self::toBool($meta['has_more_rows'] ?? false);

        foreach ($data as $key => $value) {
            if (\is_string($key) && !\in_array($key, ['response_status', 'list_info'], true) && \is_array($value) && array_is_list($value)) {
                return new ProbeResult($key, array_values(array_filter($value, 'is_array')), false, $total, $more);
            }
        }

        foreach ($data as $key => $value) {
            if (\is_string($key) && !\in_array($key, ['response_status', 'list_info'], true) && \is_array($value)) {
                return new ProbeResult($key, [$value], true);
            }
        }

        return new ProbeResult('', [], $single, $total, $more);
    }

    /**
     * GET /api/v3/requests (requests/request.html, "Get List Request").
     */
    public function listRequests(ListInfo $listInfo): ListPage
    {
        return $this->listPage('/api/v3/requests', 'requests', $listInfo);
    }

    /**
     * GET /api/v3/approvals: approvals of all modules in one list. The documented filter
     * "my_pending_approvals" returns the approvals of the key's owner; ApprovalService asks for
     * one approver with search_criteria and checks every returned item itself.
     *
     * @param  array<string, mixed>  $listInfo  list_info as sent to SDP
     *
     * @return list<array<string, mixed>>
     */
    public function listApprovals(array $listInfo): array
    {
        return $this->collection($this->get('/api/v3/approvals', ['list_info' => $listInfo]), 'approvals');
    }

    /**
     * GET /api/v3/{requests|changes|releases|purchase_orders}: one page of a module's records.
     */
    public function listModule(string $module, ListInfo $listInfo): ListPage
    {
        $collection = ApprovalModule::collection($module);

        return $this->listPage('/api/v3/' . $collection, $collection, $listInfo);
    }

    /**
     * GET /api/v3/{module}/{id}/approval_levels.
     *
     * @return list<array<string, mixed>>
     */
    public function getApprovalLevels(string $entityId, string $module = ApprovalModule::REQUEST): array
    {
        $data = $this->get(
            self::modulePath($module, $entityId) . '/approval_levels',
            ['list_info' => ['row_count' => ListInfo::MAX_ROW_COUNT, 'start_index' => 1]]
        );

        return $this->collection($data, 'approval_levels');
    }

    /**
     * GET /api/v3/{module}/{id}/approval_levels/{level_id}/approvals.
     *
     * @return list<array<string, mixed>>
     */
    public function getApprovals(string $entityId, string $levelId, string $module = ApprovalModule::REQUEST): array
    {
        $data = $this->get(
            self::modulePath($module, $entityId) . '/approval_levels/' . self::id($levelId) . '/approvals',
            ['list_info' => ['row_count' => ListInfo::MAX_ROW_COUNT, 'start_index' => 1]]
        );

        return $this->collection($data, 'approvals');
    }

    /**
     * getApprovalLevels() for several records at once.
     *
     * @param  list<string>  $entityIds
     *
     * @return array<string, list<array<string, mixed>>|SdpException>  entity id => levels or the error
     */
    public function getApprovalLevelsMany(array $entityIds, string $module = ApprovalModule::REQUEST, int $concurrency = self::DEFAULT_CONCURRENCY): array
    {
        $calls = [];

        foreach ($entityIds as $entityId) {
            $calls[self::id($entityId)] = [
                self::modulePath($module, $entityId) . '/approval_levels',
                ['list_info' => ['row_count' => ListInfo::MAX_ROW_COUNT, 'start_index' => 1]],
            ];
        }

        return $this->collectionsOf($this->getMany($calls, $concurrency), 'approval_levels');
    }

    /**
     * getApprovals() for several records at once, one level each.
     *
     * @param  array<string, string>  $levelByEntity  entity id => level id
     *
     * @return array<string, list<array<string, mixed>>|SdpException>  entity id => approvals or the error
     */
    public function getApprovalsMany(array $levelByEntity, string $module = ApprovalModule::REQUEST, int $concurrency = self::DEFAULT_CONCURRENCY): array
    {
        $calls = [];

        foreach ($levelByEntity as $entityId => $levelId) {
            $calls[self::id((string) $entityId)] = [
                self::modulePath($module, (string) $entityId) . '/approval_levels/' . self::id($levelId) . '/approvals',
                ['list_info' => ['row_count' => ListInfo::MAX_ROW_COUNT, 'start_index' => 1]],
            ];
        }

        return $this->collectionsOf($this->getMany($calls, $concurrency), 'approvals');
    }

    /**
     * @param  array<int|string, array<string, mixed>|SdpException>  $results
     *
     * @return array<string, list<array<string, mixed>>|SdpException>
     */
    private function collectionsOf(array $results, string $key): array
    {
        $out = [];

        foreach ($results as $id => $result) {
            try {
                $out[(string) $id] = $result instanceof SdpException ? $result : $this->collection($result, $key);
            } catch (SdpException $e) {
                $out[(string) $id] = $e;
            }
        }

        return $out;
    }

    /**
     * GET /api/v3/{module}/{id}: the request, change, release or purchase order itself.
     *
     * @return array<string, mixed>
     */
    public function getModuleEntity(string $module, string $entityId): array
    {
        return $this->entity($this->get(self::modulePath($module, $entityId)), ApprovalModule::entityKey($module));
    }

    /** Modules with a list of attachments in the v3 API (a purchase order carries its own). */
    public const ATTACHMENT_MODULES = [ApprovalModule::REQUEST, ApprovalModule::CHANGE, ApprovalModule::RELEASE];

    /**
     * Files attached to a request, change or release: GET /api/v3/{module}/{id}/attachments.
     * Each row has id, name, content_type and size {value, display_value}.
     *
     * @return list<array<string, mixed>>  Empty for modules without attachments
     */
    public function getAttachments(string $module, string $entityId): array
    {
        if (!\in_array($module, self::ATTACHMENT_MODULES, true)) {
            return [];
        }

        return $this->collection($this->get(self::modulePath($module, $entityId) . '/attachments'), 'attachments');
    }

    /**
     * Content of one attachment: GET /api/v3/{module}/{id}/attachments/{attachment_id}/download
     * (releases: /_download; the other spelling is tried when ServiceDesk Plus does not know one).
     *
     * @return string  The file's bytes
     *
     * @throws SdpException  also when the file is larger than $maxBytes
     */
    public function downloadAttachment(string $module, string $entityId, string $attachmentId, int $maxBytes): string
    {
        $base  = self::modulePath($module, $entityId) . '/attachments/' . self::id($attachmentId) . '/';
        $names = $module === ApprovalModule::RELEASE ? ['_download', 'download'] : ['download', '_download'];

        try {
            $body = $this->getRaw($base . $names[0]);
        } catch (SdpException $e) {
            if ($e->getKind() !== SdpException::NOT_FOUND) {
                throw $e;
            }

            $body = $this->getRaw($base . $names[1]);
        }

        if (\strlen($body) > $maxBytes) {
            throw new SdpException(SdpException::BAD_RESPONSE, 'Attachment larger than allowed');
        }

        return $body;
    }

    /**
     * GET that returns the body as it is (a file), not decoded JSON. An HTTP error, or a JSON body
     * with a failed response_status, is an SdpException like for every other call.
     */
    private function getRaw(string $path): string
    {
        [$url, $headers] = $this->request('GET', $path, null, $this->serviceKey);
        $headers['Accept'] = self::ACCEPT . ', */*';

        $started  = hrtime(true);
        $response = $this->transmit('GET', $path, $url, $headers, null, $this->secrets);
        $elapsed  = (int) ((hrtime(true) - $started) / 1_000_000);

        // Errors come as JSON (or an HTTP error): finish() reads, logs and throws them.
        if ($response->status >= 400 || $this->readStatus($this->decode($response->body))[0]) {
            $this->finish('GET', $path, $response, $elapsed, $this->secrets);
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new SdpException(SdpException::BAD_RESPONSE, 'Unexpected response to a download', $response->status);
        }

        $ms = $response->timing['total'] ?? $elapsed;
        PageStats::add($path, $ms, true);
        $this->lastTiming = $response->timing;
        $this->logger->info(\sprintf('SDP GET %s -> HTTP %d, %d bytes, %d ms%s', $path, $response->status, \strlen($response->body), $ms, self::timing($response->timing)));

        return $response->body;
    }

    /**
     * The approval comment with <, > and & written as entities, at most $max characters, cut
     * between characters (never inside an entity). ServiceDesk Plus may show the comment as HTML
     * to technicians, so a comment or a Joomla name with markup must not become markup there.
     * The portal reads comments back through HtmlText::toPlain, which decodes them again.
     */
    public static function encodeComment(string $plain, int $max): string
    {
        $out    = '';
        $length = 0;

        foreach (mb_str_split($plain) as $char) {
            $piece = match ($char) {
                '<'     => '&lt;',
                '>'     => '&gt;',
                '&'     => '&amp;',
                default => $char,
            };

            $size = mb_strlen($piece);

            if ($length + $size > $max) {
                break;
            }

            $out    .= $piece;
            $length += $size;
        }

        return $out;
    }

    /**
     * Approve or reject one approval with the service (technician) key.
     *
     * PUT /api/v3/{module}/{id}/approval_levels/{level_id}/approvals/{approval_id}/approve|reject
     * (or _approve|_reject) with input_data {"approval": {"comments": "..."}}.
     *
     * SDP records the technician as the actor; ApprovalService only calls this after confirming
     * that the logged-in user is the approver, and puts the user's name in the comment.
     *
     * @param  'approve'|'reject'  $decision
     *
     * @return array<string, mixed>
     */
    public function decideApproval(string $entityId, string $levelId, string $approvalId, string $decision, string $comment, string $module = ApprovalModule::REQUEST): array
    {
        if (!\in_array($decision, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('Decision must be approve or reject');
        }

        $base     = self::modulePath($module, $entityId) . '/approval_levels/' . self::id($levelId) . '/approvals/' . self::id($approvalId) . '/';
        $approval = $comment !== '' ? ['comments' => self::encodeComment($comment, 250)] : [];
        $attempts = array_map(
            static fn (string $segment): array => [$base . $segment, ['approval' => (object) $approval], []],
            ApprovalModule::decisionSegments($module, $decision)
        );

        return $this->firstFound('PUT', $attempts);
    }

    /**
     * Add an approver to an approval level: POST /api/v3/{module}/{id}/approval_levels/{level_id}/approvals.
     *
     * @return array<string, mixed>  The added approval
     */
    public function addApproval(string $entityId, string $levelId, string $approverId, string $module = ApprovalModule::REQUEST): array
    {
        $path     = self::modulePath($module, $entityId) . '/approval_levels/' . self::id($levelId) . '/approvals';
        $attempts = array_map(
            static fn (array $input): array => [$path, $input, []],
            ApprovalModule::addApprovalInputs($module, self::id($approverId))
        );

        $data = $this->firstFound('POST', $attempts, true);

        // Builds answer with {"approval": {...}} or, for list adds, {"approvals": [{...}]}.
        if (\is_array($data['approvals'] ?? null) && array_is_list($data['approvals']) && \is_array($data['approvals'][0] ?? null)) {
            return $data['approvals'][0];
        }

        return $this->entity($data, 'approval');
    }

    /**
     * Send the approval e-mail for one approval. SDP accepts no decision on an approval whose
     * e-mail has not been sent ("Recommendation mail is not yet sent.").
     *
     *  1. On-Premises: PUT .../approval_levels/{level_id}/approvals/send_notification?ids={approval_id}
     *     (or _send_notification) with {"approval": {"notification": {"title", "description"}}};
     *  2. requests only, Cloud: PUT .../approvals/{approval_id}/_send_notification with
     *     {"notification": {"subject", "description"}}.
     */
    public function sendApprovalNotification(string $entityId, string $levelId, string $approvalId, string $subject, string $descriptionHtml, string $module = ApprovalModule::REQUEST): array
    {
        $level    = self::modulePath($module, $entityId) . '/approval_levels/' . self::id($levelId) . '/approvals';
        $approval = $level . '/' . self::id($approvalId);
        $levelIn  = ['approval' => ['notification' => ['title' => $subject, 'description' => $descriptionHtml]]];
        $cloudIn  = ['notification' => ['subject' => $subject, 'description' => $descriptionHtml]];
        $attempts = [];

        foreach (ApprovalModule::notificationSegments($module) as $segment) {
            $attempts[] = [$level . '/' . $segment, $levelIn, ['ids' => self::id($approvalId)]];
        }

        if ($module === ApprovalModule::REQUEST) {
            $attempts[] = [$approval . '/_send_notification', $cloudIn, []];
            $attempts[] = [$approval . '/send_notification', $cloudIn, []];
        }

        return $this->firstFound('PUT', $attempts);
    }

    /**
     * DELETE /api/v3/{module}/{id}/approval_levels/{level_id}/approvals/{approval_id}.
     */
    public function deleteApproval(string $entityId, string $levelId, string $approvalId, string $module = ApprovalModule::REQUEST): void
    {
        $this->call(
            'DELETE',
            self::modulePath($module, $entityId) . '/approval_levels/' . self::id($levelId) . '/approvals/' . self::id($approvalId),
            null,
            $this->serviceKey
        );
    }

    /**
     * GET /api/v3/requests/{request_id}/conversations with notes (Request_Notifications, "View All
     * Conversations"): e-mails and notes, newest first, each with show_to_requester and a
     * content_url for the full text.
     *
     * @return list<array<string, mixed>>
     */
    public function getRequestConversations(string $requestId, int $max = 50): array
    {
        $data = $this->get('/api/v3/requests/' . self::id($requestId) . '/conversations', [
            'list_info' => ['start_index' => 1, 'sort_order' => 'desc', 'row_count' => max(1, min(100, $max))],
            'notes'     => true,
        ]);

        return $this->collection($data, 'conversations');
    }

    /**
     * Full text of one conversation item from its content_url: GET /api/v3/requests/{id}/notifications/{nid}
     * or /api/v3/requests/{id}/notes/{nid}. Only those two paths of the given request are read.
     *
     * @return array<string, mixed>  The notification or note
     */
    public function getConversationItem(string $requestId, string $contentUrl): array
    {
        $prefix = '/api/v3/requests/' . self::id($requestId) . '/';
        $path   = (string) parse_url($contentUrl, PHP_URL_PATH);

        if (!preg_match('#^' . preg_quote($prefix, '#') . '(notifications|notes)/[0-9]{1,20}$#', $path, $m)) {
            throw new \InvalidArgumentException('Unexpected conversation content_url');
        }

        return $this->entity($this->get($path), $m[1] === 'notes' ? 'note' : 'notification');
    }

    /**
     * Private note on a request, change or release: POST /api/v3/{module}/{id}/notes.
     * Purchase orders have no notes in the v3 API.
     *
     * @param  string  $descriptionHtml  Already escaped HTML
     *
     * @return array<string, mixed>
     */
    public function addModuleNote(string $module, string $entityId, string $descriptionHtml): array
    {
        if ($module === ApprovalModule::REQUEST) {
            return $this->addRequestNote($entityId, $descriptionHtml);
        }

        if ($module === ApprovalModule::PURCHASE_ORDER) {
            throw new SdpException(SdpException::NOT_FOUND, 'Purchase orders have no notes');
        }

        return $this->post(self::modulePath($module, $entityId) . '/notes', ['note' => ['description' => $descriptionHtml]]);
    }

    /**
     * Run the first of several equivalent calls that SDP knows. A "not found" answer (unknown
     * path) moves on to the next form; with $onValidation also a "validation" answer (other
     * input shape). Any other error, or the last attempt's error, is thrown.
     *
     * @param  list<array{0: string, 1: array<string, mixed>|null, 2: array<string, string>}>  $attempts
     *
     * @return array<string, mixed>
     */
    private function firstFound(string $method, array $attempts, bool $onValidation = false): array
    {
        foreach ($attempts as $n => [$path, $input, $query]) {
            try {
                return $this->call($method, $path, $input, $this->serviceKey, null, $query);
            } catch (SdpException $e) {
                $retry = $e->getKind() === SdpException::NOT_FOUND
                    || \in_array($e->getHttpStatus(), [404, 405], true)
                    || ($onValidation && $e->getKind() === SdpException::VALIDATION);

                if (!$retry || $n === \count($attempts) - 1) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('No attempts given');
    }

    private static function modulePath(string $module, string $entityId): string
    {
        return '/api/v3/' . ApprovalModule::collection($module) . '/' . self::id($entityId);
    }

    /**
     * POST /api/v3/requests/{request_id}/notes (requests/request_note.html, "Add Note").
     *
     * @param  string  $descriptionHtml  Already escaped HTML
     *
     * @return array<string, mixed>
     */
    public function addRequestNote(string $requestId, string $descriptionHtml, bool $showToRequester = false): array
    {
        return $this->post('/api/v3/requests/' . self::id($requestId) . '/notes', ['note' => [
            'description'            => $descriptionHtml,
            'show_to_requester'      => $showToRequester,
            'mark_first_response'    => false,
            'add_to_linked_requests' => false,
        ]]);
    }

    /**
     * All pages of GET /api/v3/assets (On Premise API collection, Asset > "Asset (old)" >
     * "Get all assets"). Only these methods name the path.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllAssets(ListInfo $listInfo, int $maxItems): array
    {
        return $this->listAll('/api/v3/assets', 'assets', $listInfo, $maxItems);
    }

    /**
     * GET /api/v3/asset_loans: loans with loaned_to, start_time, end_time, extended_to,
     * returned_on, status ("On Loan", "Closed") and the loaned assets.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllAssetLoans(ListInfo $listInfo, int $maxItems): array
    {
        return $this->listAll('/api/v3/asset_loans', 'asset_loans', $listInfo, $maxItems);
    }

    /**
     * GET /api/v3/assets/{asset_id}. See listAllAssets().
     *
     * @return array<string, mixed>
     */
    public function getAsset(string $assetId): array
    {
        return $this->entity($this->get('/api/v3/assets/' . self::id($assetId)), 'asset');
    }

    /**
     * GET /api/v3/announcements (Announcements folder of the On-Premises Postman collection,
     * "View All Announcements").
     */
    public function listAnnouncements(ListInfo $listInfo): ListPage
    {
        return $this->listPage('/api/v3/announcements', 'announcements', $listInfo);
    }

    /**
     * GET /api/v3/announcements/{announcement_id} ("View Announcement").
     *
     * @return array<string, mixed>
     */
    public function getAnnouncement(string $announcementId): array
    {
        return $this->entity($this->get('/api/v3/announcements/' . self::id($announcementId)), 'announcement');
    }

    /**
     * GET /api/v3/requests/{request_id} (requests/request.html, "Get Request").
     *
     * @return array<string, mixed>
     */
    public function getRequest(string $requestId): array
    {
        return $this->entity($this->get('/api/v3/requests/' . self::id($requestId)), 'request');
    }

    /**
     * Attach one file to an existing request.
     *
     * Two forms are tried in order, and the one that worked is logged:
     *   1. PUT  /api/v3/requests/{id}/upload    multipart field "input_file" (On Premise API
     *      collection, Request > Request Attachment > "Add and associate attachment")
     *   2. POST /api/v3/requests/{id}/_uploads  multipart field "filename" (undocumented, older builds)
     * The second is only tried when the first answers "not found" / "method not allowed".
     *
     * @return array<string, mixed>  SDP response
     *
     * @throws SdpException
     */
    public function uploadRequestAttachment(string $requestId, string $filename, string $contentType, string $content): array
    {
        $id = self::id($requestId);

        try {
            $data = $this->call('PUT', '/api/v3/requests/' . $id . '/upload', null, $this->serviceKey, self::multipart('input_file', $filename, $contentType, $content));
            $this->logger->info('Attachment uploaded with PUT /api/v3/requests/{id}/upload');

            return $data;
        } catch (SdpException $e) {
            $fallback = $e->getKind() === SdpException::NOT_FOUND
                || \in_array($e->getHttpStatus(), [404, 405], true);

            if (!$fallback) {
                throw $e;
            }
        }

        $data = $this->call('POST', '/api/v3/requests/' . $id . '/_uploads', null, $this->serviceKey, self::multipart('filename', $filename, $contentType, $content));
        $this->logger->info('Attachment uploaded with POST /api/v3/requests/{id}/_uploads');

        return $data;
    }

    /**
     * GET /api/v3/requests/{request_id}/resolutions (requests/request.html, "Get Resolution").
     *
     * @return array<string, mixed>
     */
    public function getResolution(string $requestId): array
    {
        return $this->entity($this->get('/api/v3/requests/' . self::id($requestId) . '/resolutions'), 'resolution');
    }

    /**
     * POST /api/v3/requests (requests/request.html, "Add Request").
     * Only subject, description and requester are sent; SDP defaults apply to everything else.
     * Never retried automatically, so a timeout cannot create duplicates.
     *
     * @return array<string, mixed>  The created request
     */
    public function createRequest(string $subject, string $descriptionHtml, string $requesterId): array
    {
        $data = $this->post('/api/v3/requests', ['request' => [
            'subject'     => $subject,
            'description' => $descriptionHtml,
            'requester'   => ['id' => self::id($requesterId)],
        ]]);

        return $this->entity($data, 'request');
    }

    /**
     * Users whose email matches, per GET /api/v3/users with list_info.search_fields.email_id
     * (admin/user.html, "Get List User"). SDP may return partial matches, so callers must compare
     * email_id exactly; IdentityResolver does.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersByEmail(string $email): array
    {
        $email = trim($email);

        if ($email === '' || \strlen($email) > 320) {
            throw new \InvalidArgumentException('Invalid email');
        }

        return $this->listAll(
            '/api/v3/users',
            'users',
            new ListInfo(rowCount: ListInfo::MAX_ROW_COUNT, searchFields: ['email_id' => $email]),
            // SDP may also return partial matches; read far enough to reach the exact address.
            5 * ListInfo::MAX_ROW_COUNT,
            ['fields_required' => ['id', 'name', 'email_id']]
        );
    }

    /**
     * GET /api/v3/users/{user_id} (admin/user.html, "Get User").
     *
     * @return array<string, mixed>
     */
    public function getUser(string $sdpUserId): array
    {
        return $this->entity($this->get('/api/v3/users/' . self::id($sdpUserId)), 'user');
    }

    /**
     * Validate an SDP id taken from a route or from SDP itself before putting it in a path.
     */
    public static function id(string|int $id): string
    {
        $id = (string) $id;

        if (!preg_match('/^[0-9]{1,20}$/', $id)) {
            throw new \InvalidArgumentException('Invalid ServiceDesk Plus id');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return list<array<string, mixed>>
     */
    private function collection(array $data, string $key): array
    {
        $items = $data[$key] ?? [];

        if (!\is_array($items) || !array_is_list($items)) {
            throw new SdpException(SdpException::BAD_RESPONSE, 'Unexpected response shape for ' . $key);
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function entity(array $data, string $key): array
    {
        $entity = $data[$key] ?? null;

        if (!\is_array($entity) || array_is_list($entity) && $entity !== []) {
            throw new SdpException(SdpException::BAD_RESPONSE, 'Unexpected response shape for ' . $key);
        }

        return $entity;
    }

    /**
     * Timing of the last single call, when the transport measured it (Diagnostics).
     *
     * @return array{dns: int, connect: int, tls: int, server: int, total: int, reused: bool}|null
     */
    public function lastTiming(): ?array
    {
        return $this->lastTiming;
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->config->baseUrl, 'serviceKey' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('SdpClient cannot be serialised');
    }

    /**
     * Build a multipart/form-data body with one file part.
     *
     * @return array{0: string, 1: string}  [body, Content-Type header]
     */
    private static function multipart(string $field, string $filename, string $contentType, string $content): array
    {
        $boundary = '----SdpPortal' . bin2hex(random_bytes(12));
        $name     = str_replace(['"', '\\', "\r", "\n"], '_', $filename);

        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $name . '"' . "\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $content . "\r\n"
            . '--' . $boundary . "--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * Several reads at once (GET only), at most $concurrency at a time when the transport can.
     * Each read is checked like get(): a failed one gives its SdpException in place of the data,
     * and a read that could not connect is tried once more on its own.
     *
     * @param  array<int|string, array{0: string, 1: array<string, mixed>|null}>  $calls  key => [path, input_data]
     *
     * @return array<int|string, array<string, mixed>|SdpException>  Same keys
     */
    public function getMany(array $calls, int $concurrency = self::DEFAULT_CONCURRENCY): array
    {
        $out = [];

        if (!$this->transport instanceof ParallelTransportInterface || $concurrency <= 1 || \count($calls) <= 1) {
            foreach ($calls as $key => [$path, $input]) {
                try {
                    $out[$key] = $this->get($path, $input);
                } catch (SdpException $e) {
                    $out[$key] = $e;
                }
            }

            return $out;
        }

        $requests = [];
        $paths    = [];

        foreach ($calls as $key => [$path, $input]) {
            [$url, $headers]  = $this->request('GET', $path, $input, $this->serviceKey);
            $requests[$key] = [$url, $headers];
            $paths[$key]    = $path;
        }

        $started = hrtime(true);
        $secrets = $this->secrets;

        try {
            $responses = $this->transport->getAll($requests, $concurrency);
        } catch (TransportException $e) {
            // The batch could not start (e.g. cURL refused the TLS settings): every read failed.
            $responses = array_fill_keys(array_keys($requests), $e);
        }

        // Reads that could not connect are tried once more, together (as a single read would be
        // tried once more). If ServiceDesk Plus is down this costs one extra round, not one per read.
        $failed = array_filter($responses, static fn ($r): bool => $r instanceof TransportException);

        if ($failed !== []) {
            $this->logger->warning(\sprintf('SDP %d of %d parallel reads failed to connect (trying them again): %s', \count($failed), \count($calls), Redactor::scrub((string) reset($failed)->getMessage(), $secrets)));
            usleep(self::RETRY_DELAY_US);

            try {
                foreach ($this->transport->getAll(array_intersect_key($requests, $failed), $concurrency) as $key => $retry) {
                    $responses[$key] = $retry;
                }
            } catch (TransportException) {
                // Still failing: the first errors are kept.
            }
        }

        foreach ($responses as $key => $response) {
            if ($response instanceof TransportException) {
                $this->logger->warning(\sprintf('SDP GET %s failed: %s', $paths[$key], Redactor::scrub($response->getMessage(), $secrets)));
                $out[$key] = new SdpException(SdpException::NETWORK, 'Could not reach ServiceDesk Plus', 0, null, '', $response);

                continue;
            }

            try {
                $out[$key] = $this->finish('GET', $paths[$key], $response, null, $secrets, true);
            } catch (SdpException $e) {
                $out[$key] = $e;
            }
        }

        $batchMs = (int) ((hrtime(true) - $started) / 1_000_000);
        PageStats::waited($batchMs);

        $this->logger->info(\sprintf('SDP %d reads in parallel (up to %d at a time): %d ms in total', \count($calls), $concurrency, $batchMs));

        return $out;
    }

    /**
     * @param  array<string, mixed>|null               $input
     * @param  array{0: string, 1: string}|null        $raw    Prebuilt [body, Content-Type] (uploads)
     * @param  array<string, string>                   $query  Extra query parameters (e.g. ids=...)
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $input, #[\SensitiveParameter] string $authtoken, ?array $raw = null, array $query = []): array
    {
        [$url, $headers, $body] = $this->request($method, $path, $input, $authtoken, $raw, $query);

        $secrets  = array_values(array_unique([...$this->secrets, $authtoken]));
        $started  = hrtime(true);
        $response = $this->transmit($method, $path, $url, $headers, $body, $secrets);

        return $this->finish($method, $path, $response, (int) ((hrtime(true) - $started) / 1_000_000), $secrets);
    }

    /**
     * Send one call. A read that fails to connect is tried once more; writes never are.
     *
     * @param  array<string, string>  $headers
     * @param  list<string>           $secrets
     */
    private function transmit(string $method, string $path, string $url, array $headers, ?string $body, array $secrets): TransportResponse
    {
        // A read that fails to connect (e.g. a dropped TLS handshake) is tried once more.
        // Writes are never repeated: the first attempt may have reached SDP.
        $attempts = $method === 'GET' ? 1 + self::GET_RETRIES : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->transport->send($method, $url, $headers, $body);
            } catch (TransportException $e) {
                $retry = $attempt < $attempts;

                $this->logger->warning(\sprintf(
                    'SDP %s %s failed%s: %s',
                    $method,
                    $path,
                    $retry ? ' (trying again)' : '',
                    Redactor::scrub($e->getMessage(), $secrets)
                ));

                if (!$retry) {
                    throw new SdpException(SdpException::NETWORK, 'Could not reach ServiceDesk Plus', 0, null, '', $e);
                }

                usleep(self::RETRY_DELAY_US);
            }
        }
    }

    /**
     * URL, headers and body of one call.
     *
     * @param  array<string, mixed>|null         $input
     * @param  array{0: string, 1: string}|null  $raw
     * @param  array<string, string>             $query
     *
     * @return array{0: string, 1: array<string, string>, 2: ?string}
     */
    private function request(string $method, string $path, ?array $input, #[\SensitiveParameter] string $authtoken, ?array $raw = null, array $query = []): array
    {
        if (!preg_match('#^/api/v3(/[a-z0-9_]+)+$#', $path)) {
            throw new \InvalidArgumentException('Invalid SDP API path');
        }

        $url     = $this->config->baseUrl . $path;
        $headers = ['Accept' => self::ACCEPT, 'authtoken' => $authtoken];
        $body    = null;

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($raw !== null) {
            [$body, $headers['Content-Type']] = $raw;
        } else {
            if ($input !== null) {
                $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($method === 'GET') {
                    $url .= ($query !== [] ? '&' : '?') . http_build_query(['input_data' => $json], '', '&', PHP_QUERY_RFC3986);
                } else {
                    $body = http_build_query(['input_data' => $json], '', '&', PHP_QUERY_RFC1738);
                }
            }

            if ($method !== 'GET') {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }

        return [$url, $headers, $body];
    }

    /**
     * Check one response, log it with its timing and count it for the page summary.
     *
     * @param  ?int          $elapsed  Measured by the caller (ms); null = use the transport's timing
     * @param  list<string>  $secrets
     *
     * @return array<string, mixed>
     */
    private function finish(string $method, string $path, TransportResponse $response, ?int $elapsed, array $secrets, bool $batched = false): array
    {
        $ms = $response->timing['total'] ?? $elapsed ?? 0;
        PageStats::add($path, $ms, !$batched);
        $this->lastTiming = $response->timing;

        $timing  = self::timing($response->timing);
        $decoded = $this->decode($response->body);
        [$failed, $sdpCode, $sdpMessage] = $this->readStatus($decoded);

        if ($response->status >= 400 || $failed) {
            $sdpMessage = Redactor::scrub($sdpMessage, $secrets);
            $exception  = $decoded === null && $response->status < 400
                ? new SdpException(SdpException::BAD_RESPONSE, 'ServiceDesk Plus returned an unreadable response', $response->status)
                : SdpException::fromResponse($response->status, $sdpCode, $sdpMessage);

            $this->logger->warning(\sprintf(
                'SDP %s %s -> HTTP %d, code %s, kind %s, %d ms%s: %s',
                $method,
                $path,
                $response->status,
                $sdpCode ?? '-',
                $exception->getKind(),
                $ms,
                $timing,
                $sdpMessage
            ));

            throw $exception;
        }

        if ($decoded === null) {
            $this->logger->warning(\sprintf('SDP %s %s -> HTTP %d with non-JSON body', $method, $path, $response->status));

            throw new SdpException(SdpException::BAD_RESPONSE, 'ServiceDesk Plus returned an unreadable response', $response->status);
        }

        $this->logger->info(\sprintf('SDP %s %s -> HTTP %d, %d ms%s', $method, $path, $response->status, $ms, $timing));

        return $decoded;
    }

    /**
     * " (connect 12 ms, TLS 85 ms, server 140 ms)" or " (reused connection, server 140 ms)".
     *
     * @param  array{dns: int, connect: int, tls: int, server: int, total: int, reused: bool}|null  $t
     */
    private static function timing(?array $t): string
    {
        if ($t === null) {
            return '';
        }

        if ($t['reused']) {
            return ' (reused connection, server ' . $t['server'] . ' ms)';
        }

        return ' (DNS ' . $t['dns'] . ' ms, connect ' . $t['connect'] . ' ms' . ($t['tls'] > 0 ? ', TLS ' . $t['tls'] . ' ms' : '') . ', server ' . $t['server'] . ' ms)';
    }

    /**
     * @return array<string, mixed>|null  null when the body is not a JSON object
     */
    private function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * Read response_status, which SDP returns either as an object or as an array of objects.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @return array{0: bool, 1: ?int, 2: string}  [failed, first message status_code, message text]
     */
    private function readStatus(?array $data): array
    {
        $raw = $data['response_status'] ?? null;

        if (!\is_array($raw)) {
            return [false, null, ''];
        }

        $statuses = array_is_list($raw) ? $raw : [$raw];
        $failed   = false;
        $code     = null;
        $messages = [];

        foreach ($statuses as $status) {
            if (!\is_array($status)) {
                continue;
            }

            if (strtolower((string) ($status['status'] ?? '')) === 'failed') {
                $failed = true;
            }

            foreach ((array) ($status['messages'] ?? []) as $message) {
                if (!\is_array($message)) {
                    continue;
                }

                if ($code === null && isset($message['status_code']) && is_numeric($message['status_code'])) {
                    $candidate = (int) $message['status_code'];
                    $code      = $candidate >= 4000 ? $candidate : null;
                }

                if (isset($message['message']) && \is_string($message['message'])) {
                    $messages[] = $message['message'];
                }

                if (strtolower((string) ($message['type'] ?? '')) === 'failed') {
                    $failed = true;
                }
            }

            if ($code === null && isset($status['status_code']) && is_numeric($status['status_code'])) {
                $candidate = (int) $status['status_code'];
                $code      = $candidate >= 4000 && $candidate < 10000 ? $candidate : null;
            }
        }

        return [$failed, $code, implode(' ', $messages)];
    }

    private static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
