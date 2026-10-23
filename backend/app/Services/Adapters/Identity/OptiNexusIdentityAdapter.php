<?php

namespace App\Services\Adapters\Identity;

use Illuminate\Http\Request;

class OptiNexusIdentityAdapter implements IdentityPortInterface
{
    public function resolveContext(Request $request): array
    {
        // Dalam implementasi SaaS penuh, token diverifikasi via OptiNexus SSO / Sanctum
        $authHeader = $request->header('Authorization', '');
        $tenantId = $request->header('X-Tenant-Id', 'nexus-org-01');

        return [
            'tenant_id' => $tenantId,
            'user_id' => 'usr-nexus-sso',
            'user_name' => 'Federated Enterprise User (OptiNexus SSO)',
            'role' => 'LogisticsManager',
            'mode' => 'saas',
            'is_sso' => ! empty($authHeader),
        ];
    }
}
