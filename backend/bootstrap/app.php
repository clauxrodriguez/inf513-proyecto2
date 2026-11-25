<?php

use App\Exceptions\ReglaNegocioException;
use App\Http\Middleware\EjecutarTransaccionAuditada;
use App\Http\Middleware\RegistrarAccesoWeb;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Auth\AuthenticationException;
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
            'permiso' => VerificarPermiso::class,
            'transaccion' => EjecutarTransaccionAuditada::class,
        ]);

        // Bitácora de accesos web (ACCESO_RECURSO) para toda la API
        $middleware->api(append: [RegistrarAccesoWeb::class]);

        // API sin pantalla de login: sin sesión responde 401 en JSON en lugar de redirigir a route('login')
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Reglas de negocio lanzadas por los servicios
        // Una regla de negocio incumplida es una respuesta esperada (4xx), no un fallo a registrar en el log
        $exceptions->dontReport(ReglaNegocioException::class);
        $exceptions->render(fn (ReglaNegocioException $e) => response()->json(['message' => $e->getMessage()], $e->estado));
        $exceptions->render(fn (AuthenticationException $e) => response()->json(['message' => 'Su sesión no está iniciada o expiró. Inicie sesión nuevamente.'], 401));

        // Texto del RAISE EXCEPTION, sin el prefijo "ERROR:" ni el contexto que agrega PostgreSQL
        $mensajeDeTrigger = static function (QueryException $e): string {
            $mensaje = $e->getPrevious()?->getMessage() ?? $e->getMessage();

            if (str_contains($mensaje, 'ERROR:')) {
                $mensaje = explode('ERROR:', $mensaje, 2)[1];
            }

            return trim(explode("\n", $mensaje)[0]) ?: 'La operación no cumple una regla del sistema.';
        };

        // Reglas que hace cumplir PostgreSQL: RAISE EXCEPTION de los triggers (ya en español) y restricciones
        $exceptions->render(function (QueryException $e, Request $request) use ($mensajeDeTrigger) {
            if (! $request->is('api/*')) {
                return null;
            }

            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

            $mensaje = match (true) {
                $sqlState === 'P0001' => $mensajeDeTrigger($e),
                $sqlState === '23505' => 'Ya existe un registro con ese dato. Verifique que no esté duplicado.',
                $sqlState === '23503' => 'La operación no es posible: el registro está relacionado con otros datos o hace referencia a uno inexistente.',
                $sqlState === '23502' => 'Falta completar un dato obligatorio.',
                $sqlState === '23514' => 'Uno de los datos ingresados no cumple las reglas del sistema.',
                str_starts_with($sqlState, '22') => 'Uno de los datos tiene un formato o valor no válido.',
                default => null,
            };

            return $mensaje === null ? null : response()->json(['message' => $mensaje], 422);
        });
    })->create();
