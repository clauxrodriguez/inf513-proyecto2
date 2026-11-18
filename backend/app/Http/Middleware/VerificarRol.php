<?php

namespace App\Http\Middleware;

use App\Exceptions\ReglaNegocioException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control de acceso por rol en la entrada de la capa de negocio.
 * Uso en rutas: ->middleware('rol:RECEPCION,ESTUDIANTE')
 */
class VerificarRol
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->rol, $roles, true)) {
            throw ReglaNegocioException::prohibido('Acceso denegado: su rol no tiene permiso para realizar esta acción.');
        }

        return $next($request);
    }
}
