<?php

namespace App\Domain\Access;

/**
 * Tenant permission registry (code = resource.action) and the system role templates. Authorization checks permissions only;
 * the templates are plain permission collections that reproduce the access of the former hard-coded membership roles, so an
 * existing membership keeps exactly what it had. Add permissions here additively and run `php artisan optideli:sync-access`.
 */
final class PermissionCatalog
{
    public const DEFINITIONS = [
        'Operations' => [
            'operations.view' => 'View orders, dispatch, fleet, trips, yard, vendors, freight and enterprise data',
            'operations.manage' => 'Create and progress orders, dispatch plans, fleet, trips, yard and vendors',
            'enterprise.manage' => 'Use rate cards, tenders, telematics, ESG, EDI and accounting sync actions',
            'execution.verify' => 'Verify proof of delivery and operational evidence',
        ],
        'Dispatch approval' => [
            'dispatch.approve.level1' => 'Approve a dispatch plan below the margin guardrail (level 1)',
            'dispatch.approve.level2' => 'Approve a dispatch plan at margin level 2',
            'dispatch.approve.level3' => 'Approve a dispatch plan at margin level 3 (critical)',
        ],
        'Finance' => [
            'finance.access' => 'Register and review freight invoices and accessorial evidence',
            'freight.approve.finance' => 'Approve freight invoices at the Finance tier',
            'freight.approve.finance_supervisor' => 'Approve dual-tier freight invoices as Finance Supervisor',
            'freight.approve.logistics_manager' => 'Approve dual-tier freight invoices as Logistics Manager',
            'freight.approve.cfo' => 'Approve CFO-tier freight invoices',
            'freight.escalation.resolve' => 'Resolve critical freight escalations',
        ],
        'Commercial' => [
            'policy.manage' => 'Change the margin policy',
            'vendor.portal.provision' => 'Provision vendor portal accounts',
        ],
        'Vendor portal' => [
            'vendor.portal' => 'Use the vendor portal (requires a vendor-bound membership)',
        ],
        'Access' => [
            'access.role.view' => 'View roles and permissions',
            'access.role.manage' => 'Create, change and delete custom roles',
            'access.user.manage' => 'Assign roles to members',
        ],
    ];

    /**
     * Sellable modules for OptiNexus (capability code = "<application>.<module>"). Permissions not listed here belong to the core
     * that comes with the application. In OptiNexus mode a permission of a module the tenant is not entitled to is dropped.
     */
    public const MODULES = [
        'enterprise' => ['name' => 'Enterprise logistics', 'description' => 'Rate cards, tenders, load optimizer, telematics, ESG and EDI.', 'permissions' => ['enterprise.manage']],
        'vendor_portal' => ['name' => 'Vendor portal', 'description' => 'Carrier and vendor self-service portal.', 'permissions' => ['vendor.portal', 'vendor.portal.provision']],
    ];

    /** Permissions an Admin Tenant may not hand out from OptiNexus (vendor access needs a vendor-bound membership; access is governed centrally). */
    public const NOT_DELEGABLE = ['vendor.portal', 'access.role.manage', 'access.user.manage'];

    /** Module (without application prefix) of a permission, or null for the core. */
    public static function moduleOf(string $code): ?string
    {
        foreach (self::MODULES as $module => $definition) {
            if (in_array($code, $definition['permissions'], true)) {
                return $module;
            }
        }

        return null;
    }

    /** System role templates: name => permission codes. Names match the former membership roles. */
    public const SYSTEM_ROLES = [
        'viewer' => ['operations.view'],
        'dispatcher' => ['operations.view', 'operations.manage', 'enterprise.manage'],
        'supervisor' => ['operations.view', 'operations.manage', 'enterprise.manage', 'execution.verify', 'dispatch.approve.level1', 'freight.approve.logistics_manager'],
        'head_of_logistics' => ['operations.view', 'operations.manage', 'enterprise.manage', 'execution.verify', 'dispatch.approve.level1', 'dispatch.approve.level2', 'freight.approve.logistics_manager'],
        'cfo' => ['operations.view', 'operations.manage', 'enterprise.manage', 'execution.verify', 'dispatch.approve.level1', 'dispatch.approve.level2', 'dispatch.approve.level3', 'finance.access', 'freight.approve.cfo', 'freight.escalation.resolve', 'policy.manage', 'vendor.portal.provision'],
        'finance' => ['operations.view', 'enterprise.manage', 'finance.access', 'freight.approve.finance'],
        'finance_supervisor' => ['operations.view', 'enterprise.manage', 'finance.access', 'freight.approve.finance', 'freight.approve.finance_supervisor'],
        'vendor' => ['vendor.portal'],
        'tenant_administrator' => ['*'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::DEFINITIONS)));
    }

    /** @return list<array{code:string,group:string,description:string}> */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $group => $permissions) {
            foreach ($permissions as $code => $description) {
                $rows[] = compact('code', 'group', 'description');
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public static function templateCodes(string $role): array
    {
        $codes = self::SYSTEM_ROLES[$role] ?? [];

        return $codes === ['*'] ? array_values(array_diff(self::codes(), ['vendor.portal'])) : $codes;
    }
}
