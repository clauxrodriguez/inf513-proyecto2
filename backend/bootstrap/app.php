<?php

use App\Exceptions\ReglaNegocioException;
use App\Http\Middleware\VerificarRol;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'rol' => VerificarRol::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Reglas de negocio lanzadas por los servicios
        $exceptions->render(fn (ReglaNegocioException $e) => response()->json(['message' => $e->getMessage()], $e->estado));

        // Reglas que PostgreSQL hace cumplir (RAISE EXCEPTION, CHECK, UNIQUE, FK): se devuelven como 422 legibles
        $exceptions->render(function (QueryException $e, Request $request) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            if (! $request->is('api/*') || ! in_array(substr($sqlState, 0, 2), ['23', '22', 'P0'], true)) {
                return null;
            }

            $mensaje = $e->getPrevious()?->getMessage() ?? 'Error de validación en PostgreSQL.';
            if (str_contains($mensaje, 'ERROR:')) {
                $mensaje = trim(explode("\n", explode('ERROR:', $mensaje)[1])[0]);
            }

            return response()->json(['message' => $mensaje], 422);
        });
    })->create();
