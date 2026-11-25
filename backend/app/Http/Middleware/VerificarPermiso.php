<?php

namespace App\Http\Middleware;

use App\Exceptions\ReglaNegocioException;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matriz de acceso: permite la ruta si el rol activo tiene al menos uno de los permisos indicados
 * (rol_permiso en la base de datos). Uso: ->middleware('permiso:TRAMITES_RECEPCION,DESPACHO_FIRMAR')
 */
class VerificarPermiso
{
    public function __construct(private AuthService $auth) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $usuario = $request->user();

        if (! $usuario || array_intersect($permisos, $this->auth->permisos($usuario)) === []) {
            throw ReglaNegocioException::prohibido('Acceso denegado: su rol no tiene permiso para realizar esta acción.');
        }

        return $next($request);
    }
}
