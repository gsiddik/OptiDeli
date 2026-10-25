<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Access\SystemRoles;
use App\Domain\Platform\PlatformAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates the OptiDeli tenant of an OptiNexus tenant the moment OptiDeli is assigned to it (`cgo.tenant.application_assigned`,
 * published by OptiNexus through the event broker), so nobody has to create and link it in the Superadmin console before the first
 * person signs in. The tenant is linked to the OptiNexus tenant from the start; its slug comes from the tenant's name.
 *
 * Idempotent and additive: a tenant that is already linked is left exactly as it is (a Superadmin's name, status or slug are never
 * overwritten), a repeated or concurrent delivery creates nothing twice (the link is unique), and nothing is ever deleted or
 * deactivated when the application is later removed from the tenant. A standalone deployment serves one fixed tenant and is left alone.
 */
class TenantProvisioner
{
    public const ASSIGNED = 'cgo.tenant.application_assigned';

    public const CREATED = 'tenant_created';

    public const EXISTS = 'tenant_exists';

    public const IGNORED = 'ignored';

    public function __construct(private readonly SystemRoles $roles) {}

    /** Whether the event says that this application was assigned to a tenant. */
    public function handles(array $event): bool
    {
        return ($event['event_type'] ?? null) === self::ASSIGNED
            && ($event['data']['payload']['application_code'] ?? null) === OptinexusSettings::applicationCode();
    }

    /** @return 'tenant_created'|'tenant_exists'|'ignored' */
    public function provision(array $event): string
    {
        $nexusTenantId = strtolower((string) ($event['tenant_id'] ?? ''));
        if (! Str::isUuid($nexusTenantId)) {
            return self::IGNORED;
        }
        if (config('optideli.deployment_mode') === 'standalone') {
            // Only the one configured tenant can sign in here, so a second tenant would be useless. Say why nothing happened.
            Log::notice('OptiNexus assigned OptiDeli to a tenant, but this deployment is standalone (one tenant): set OPTIDELI_DEPLOYMENT_MODE=saas to create tenants automatically.', ['optinexus_tenant_id' => $nexusTenantId]);

            return self::IGNORED;
        }

        $payload = (array) ($event['data']['payload'] ?? []);

        try {
            return DB::transaction(function () use ($nexusTenantId, $payload) {
                if (DB::table('tenants')->where('optinexus_tenant_id', $nexusTenantId)->exists()) {
                    return self::EXISTS;
                }

                $code = trim((string) ($payload['tenant_code'] ?? ''));
                $name = Str::limit(trim((string) ($payload['tenant_name'] ?? '')), 150, '');
                if ($name === '') {
                    $name = $code !== '' ? $code : $nexusTenantId;
                }
                $slug = $this->uniqueSlug($name);
                $id = (string) Str::uuid();

                DB::table('tenants')->insert([
                    'id' => $id, 'slug' => $slug, 'name' => $name, 'active' => true, 'minimum_margin_bps' => 1500, 'policy_version' => 1,
                    'optinexus_tenant_id' => $nexusTenantId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->roles->syncPermissions();
                $this->roles->syncTenant($id);
                PlatformAccess::audit(null, 'platform.tenant_provisioned_by_optinexus', $id, ['slug' => $slug, 'name' => $name, 'optinexus_tenant_id' => $nexusTenantId]);

                return self::CREATED;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event won the race: the tenant exists.
            return self::EXISTS;
        }
    }

    /** A slug in the format the Superadmin console enforces (lower case words joined by single dashes, 2-80 characters), free of clashes. */
    private function uniqueSlug(string $source): string
    {
        $base = trim(Str::limit(Str::slug($source), 70, ''), '-');
        $base = strlen($base) >= 2 ? $base : 'tenant';
        $slug = $base;
        for ($n = 2; DB::table('tenants')->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
