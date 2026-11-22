<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * List endpoints of the ServiceDesk Plus On-Premises REST API v3 that Find Endpoints knows.
 *
 * ServiceDesk Plus offers no machine-readable API list on the server, so the list is built in from
 * ManageEngine's "ServiceDesk Plus On Premise" API collection (Postman workspace "servicedeskplus",
 * published at https://servicedeskplus.docs.buildwithfern.com): the endpoints this portal reads,
 * then other top-level "View all" / "Get all" endpoints, with their names and sections there.
 * Not every build or edition has every one; "Check which endpoints answer" shows which exist on
 * this server and whether the API technician may read them.
 */
final class EndpointCatalog
{
    /** Allowed API paths: /api/v3 and up to six lower-case segments (ids are digits). */
    public const PATH = '#^/api/v3(/[a-z0-9_]{1,60}){1,6}$#';

    /** Base of the documentation pages (the "doc" of each endpoint is relative to it). */
    public const DOCS = 'https://servicedeskplus.docs.buildwithfern.com/servicedesk-plus-on-premise/';

    /**
     * path => [name in the API collection, section, documentation page ('' = not in the collection),
     * portal pages that read it ('' = none)].
     */
    private const ENDPOINTS = [
        '/api/v3/requests'              => ['View all Requests', 'Request', 'request/request/view-all-requests', 'My Requests, Dashboard, My Approvals'],
        '/api/v3/approvals'             => ['Get Pending Approvals', 'Common', 'common/approvals/get-pending-approvals', 'My Approvals, Dashboard'],
        '/api/v3/changes'               => ['View all Changes', 'Change', 'change/change/view-all-changes', 'My Approvals'],
        '/api/v3/releases'              => ['View all Releases', 'Release', 'release/release/view-all-releases', 'My Approvals'],
        '/api/v3/purchase_orders'       => ['View all purchase orders', 'Purchase', 'purchase/purchase-order/view-all-purchase-orders', 'My Approvals'],
        '/api/v3/assets'                => ['Get all assets', 'Asset (old)', 'asset/asset-old/get-all-assets', 'My Loans, Dashboard'],
        '/api/v3/asset_loans'           => ['Asset loans', 'Asset', '', 'My Loans (Returned), Dashboard'],
        '/api/v3/announcements'         => ['View All Announcements', 'Common', 'common/announcements/view-all-announcements', 'Announcements, Dashboard'],
        '/api/v3/users'                 => ['Get all users', 'Common', 'common/user/get-all-users', 'User Mappings, sign-in matching'],
        '/api/v3/request_templates'     => ['Get Incident Templates / Get Service Templates', 'Request', 'request/request-templates/get-incident-templates', ''],
        '/api/v3/request_types'         => ['View all Request Types', 'Request', 'request/request-type/view-all-request-types', ''],
        '/api/v3/request_closure_codes' => ['View all Request Closure Codes', 'Request', 'request/request-closure-code/view-all-request-closure-codes', ''],
        '/api/v3/resolution_templates'  => ['View All Resolution Templates', 'Request', 'request/resolution-template/view-all-resolution-templates', ''],
        '/api/v3/tags'                  => ['View all tags', 'Request', 'request/tag/view-all-tags', ''],
        '/api/v3/problems'              => ['View All Problem', 'Problem', 'problem/problem-api/view-all-problem', ''],
        '/api/v3/problem_templates'     => ['View All Problem Templates', 'Problem', 'problem/problem-templates/view-all-problem-templates', ''],
        '/api/v3/change_types'          => ['View all Change Types', 'Change', 'change/change-type/view-all-change-types', ''],
        '/api/v3/change_statuses'       => ['View all Change Statuses', 'Change', 'change/change-status/view-all-change-statuses', ''],
        '/api/v3/change_stages'         => ['View all Change Stages', 'Change', 'change/change-stage/view-all-change-stages', ''],
        '/api/v3/reasons_for_change'    => ['View All Reason for Change', 'Change', 'change/reason-for-change/view-all-reason-for-change', ''],
        '/api/v3/risks'                 => ['View All Risk', 'Change', 'change/risk/view-all-risk', ''],
        '/api/v3/cabs'                  => ['View All CABs', 'Change', 'change/cab/view-all-ca-bs', ''],
        '/api/v3/release_stages'        => ['Get All Release Stages', 'Release', 'release/release-stage/get-all-release-stages', ''],
        '/api/v3/projects'              => ['View all projects', 'Project', 'project/project/view-all-projects', ''],
        '/api/v3/tasks'                 => ['View All Tasks', 'Common', 'common/general-task/view-all-tasks', ''],
        '/api/v3/solutions'             => ['View all solutions', 'Solutions', 'solutions/solutions/view-all-solutions', ''],
        '/api/v3/topics'                => ['View all topics', 'Solutions', 'solutions/topic/view-all-topics', ''],
        '/api/v3/technicians'           => ['Get all technicians', 'Common', 'common/technician/get-all-technicians', ''],
        '/api/v3/orgusers'              => ['Get all orgusers', 'Common', 'common/orguser/get-all-orgusers', ''],
        '/api/v3/support_groups'        => ['View all support groups', 'Common', 'common/support-group/view-all-support-groups', ''],
        '/api/v3/sites'                 => ['View all sites', 'Common', 'common/site/view-all-sites', ''],
        '/api/v3/regions'               => ['View all Regions', 'Common', 'common/region/view-all-regions', ''],
        '/api/v3/departments'           => ['Get all departments', 'Common', 'common/department/get-all-departments', ''],
        '/api/v3/categories'            => ['View All Categories', 'Common', 'common/category/view-all-categories', 'My Requests (Category filter)'],
        '/api/v3/items'                 => ['View All Items', 'Common', 'common/item/view-all-items', ''],
        '/api/v3/statuses'              => ['View All Statuses', 'Common', 'common/status/view-all-statuses', ''],
        '/api/v3/priorities'            => ['View All Priorities', 'Common', 'common/priority/view-all-priorities', ''],
        '/api/v3/urgencies'             => ['View all Urgencies', 'Common', 'common/urgency/view-all-urgencies', ''],
        '/api/v3/impacts'               => ['View all Impacts', 'Common', 'common/impact/view-all-impacts', ''],
        '/api/v3/levels'                => ['View All Levels', 'Common', 'common/level/view-all-levels', ''],
        '/api/v3/modes'                 => ['View All Modes', 'Common', 'common/mode/view-all-modes', ''],
        '/api/v3/asset_assets'          => ['Get all assets', 'Asset', 'asset/asset-asset/get-all-assets', ''],
        '/api/v3/asset_computers'       => ['Get all computers', 'Asset', 'asset/asset-computer/get-all-computers', ''],
        '/api/v3/asset_printers'        => ['Get all printers', 'Asset', 'asset/asset-printer/get-all-printers', ''],
        '/api/v3/workstations'          => ['Get all workstations', 'Asset (old)', 'asset/workstation-old/get-all-workstations', ''],
        '/api/v3/asset_states'          => ['Get all asset states', 'Asset', 'asset/asset-state/get-all-asset-states', ''],
        '/api/v3/asset_modules'         => ['Get all product types', 'Asset', 'asset/product-type/get-all-product-types', ''],
        '/api/v3/products'              => ['Get all products', 'Asset', 'asset/product/get-all-products', ''],
        '/api/v3/vendors'               => ['Get all vendors', 'Asset', 'asset/vendor/get-all-vendors', ''],
        '/api/v3/softwares'             => ['View All Software', 'Software', 'software/software/view-all-software', ''],
        '/api/v3/contracts'             => ['View All Contracts', 'Contract', 'contract/contract/view-all-contracts', ''],
        '/api/v3/contract_types'        => ['View All Contract Types', 'Contract', 'contract/contract-type/view-all-contract-types', ''],
        '/api/v3/reports'               => ['View all Query Reports', 'Reports', 'reports/query-reports/view-all-query-reports', ''],
    ];

    /**
     * @return list<array{path: string, summary: string, section: string, doc: string, portal: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::ENDPOINTS as $path => [$summary, $section, $doc, $portal]) {
            $out[] = ['path' => $path, 'summary' => $summary, 'section' => $section, 'doc' => $doc !== '' ? self::DOCS . $doc : '', 'portal' => $portal];
        }

        return $out;
    }

    /**
     * Paths the portal itself reads, for Diagnostics.
     *
     * @return list<string>
     */
    public static function portalPaths(): array
    {
        return array_keys(array_filter(self::ENDPOINTS, static fn (array $e): bool => $e[3] !== ''));
    }

    /**
     * A typed path in canonical form: "requests", "/requests" and "api/v3/requests/" all become
     * /api/v3/requests. Null when the result is not an allowed path.
     */
    public static function normalize(string $path): ?string
    {
        $path = strtolower(trim($path));
        $path = (string) preg_replace('#[?\#].*$#', '', $path);
        $path = '/' . trim($path, '/');

        if (str_starts_with($path . '/', '/api/') && !str_starts_with($path . '/', '/api/v3/')) {
            return null;
        }

        if (!str_starts_with($path . '/', '/api/v3/')) {
            $path = '/api/v3' . $path;
        }

        return preg_match(self::PATH, $path) ? $path : null;
    }
}
