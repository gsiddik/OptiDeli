<?php
use App\Http\Middleware\TenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php',commands: __DIR__.'/../routes/console.php',health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant'=>TenantContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn(Request $request)=>$request->is('api/*')||$request->expectsJson());
        $exceptions->render(function (QueryException $e,Request $request) {
            if ($request->is('api/*') && in_array((string)$e->getCode(),['23505','23000'],true)) {
                return response()->json(['message'=>'Data telah digunakan atau alokasi bersamaan terdeteksi. Muat ulang dan periksa data.'],409);
            }
        });
    })->create();
