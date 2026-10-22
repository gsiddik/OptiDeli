<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_if($r->attributes->get('role') === 'vendor', 403, 'Gunakan portal vendor.');

        return $next($r);
    }
}
