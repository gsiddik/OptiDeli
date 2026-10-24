<?php

namespace App\Services\Adapters\Identity;

use Illuminate\Http\Request;

class StandaloneIdentityAdapter implements IdentityPortInterface
{
    public function resolveContext(Request $request): array
    {
        $tenant = $request->attributes->get('tenant');

        return [
            'tenant_id' => (string) $tenant->id,
            'user_id' => (string) $request->user()->id,
            'user_name' => $request->user()->name,
            'role' => (string) $request->attributes->get('role'),
            'mode' => 'standalone',
            'is_sso' => false,
        ];
    }
}
