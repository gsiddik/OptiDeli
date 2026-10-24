<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_if(Access::isVendor($r) || ! Access::can($r, 'operations.view'), 403, 'Gunakan portal vendor.');

        return $next($r);
    }
}
