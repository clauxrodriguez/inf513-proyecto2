<?php

namespace App\Http\Middleware;

use App\Repositories\UnidadDeTrabajo;
use App\Services\AuditoriaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ejecuta cada petición autenticada dentro de una transacción y, antes de operar, fija con
 * set_config(..., true) el usuario que leen los triggers (bitácora, registrado_por, QR).
 * Si la petición lanza un error, la transacción completa se revierte.
 */
class EjecutarTransaccionAuditada
{
    public function __construct(
        private UnidadDeTrabajo $unidadDeTrabajo,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->unidadDeTrabajo->ejecutar(function () use ($request, $next): Response {
            if ($usuario = $request->user()) {
                $this->auditoria->establecerContexto($usuario->id);
            }

            return $next($request);
        });
    }
}
