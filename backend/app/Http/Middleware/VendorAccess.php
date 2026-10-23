<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VendorAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_unless($r->attributes->get('role') === 'vendor', 403, 'Akses portal vendor diperlukan.');

        return $next($r);
    }
}
