<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_unless(Access::isCustomer($r) && Access::can($r, 'customer.portal'), 403, 'Akses portal pelanggan diperlukan.');

        return $next($r);
    }
}
