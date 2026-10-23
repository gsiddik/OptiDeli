<?php

namespace App\Services\Adapters\Identity;

use Illuminate\Http\Request;

interface IdentityPortInterface
{
    /**
     * Memecahkan konteks governance pengguna aktif (tenant, user, role).
     *
     * @return array{
     *   tenant_id: string,
     *   user_id: string,
     *   user_name: string,
     *   role: string,
     *   mode: string,
     *   is_sso: bool
     * }
     */
    public function resolveContext(Request $request): array;
}
