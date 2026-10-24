<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VendorAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_unless(Access::isVendor($r) && Access::can($r, 'vendor.portal'), 403, 'Akses portal vendor diperlukan.');

        return $next($r);
    }
}
