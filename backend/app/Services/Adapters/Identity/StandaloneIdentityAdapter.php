<?php

namespace App\Services\Adapters\Identity;

use Illuminate\Http\Request;

class StandaloneIdentityAdapter implements IdentityPortInterface
{
    public function resolveContext(Request $request): array
    {
        $tenantId = $request->header('X-Tenant-Id', 'demo');

        return [
            'tenant_id' => $tenantId,
            'user_id' => 'usr-local-dispatcher',
            'user_name' => 'Local Dispatcher (Standalone)',
            'role' => 'Dispatcher',
            'mode' => 'standalone',
            'is_sso' => false,
        ];
    }
}
