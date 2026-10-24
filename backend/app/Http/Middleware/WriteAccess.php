<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WriteAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_if($r->attributes->get('role') === 'viewer', 403, 'Akses hanya baca.');

        return $next($r);
    }
}
