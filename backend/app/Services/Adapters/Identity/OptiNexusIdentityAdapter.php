<?php

namespace App\Services\Adapters\Identity;

use Illuminate\Http\Request;

class OptiNexusIdentityAdapter implements IdentityPortInterface
{
    public function resolveContext(Request $request): array
    {
        // OptiNexus SSO is not wired yet: the context is still the server-side session (tenant + membership),
        // never a client-supplied header. is_sso turns true once the OIDC login sets it on the session.
        $tenant = $request->attributes->get('tenant');

        return [
            'tenant_id' => (string) $tenant->id,
            'user_id' => (string) $request->user()->id,
            'user_name' => $request->user()->name,
            'role' => (string) $request->attributes->get('role'),
            'mode' => 'saas',
            'is_sso' => (bool) $request->session()->get('optinexus_sso', false),
        ];
    }
}
