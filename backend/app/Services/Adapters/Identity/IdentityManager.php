<?php

namespace App\Services\Adapters\Identity;

class IdentityManager
{
    public static function create(): IdentityPortInterface
    {
        $driver = config('optideli.identity.driver', 'standalone');

        return match ($driver) {
            'optinexus' => new OptiNexusIdentityAdapter,
            default => new StandaloneIdentityAdapter,
        };
    }
}
