<?php

namespace App\Http\Middleware;

use App\Services\AuditoriaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Informa a PostgreSQL qué usuario hace la petición, para que los triggers de auditoría lo registren.
 */
class SetPostgresAuditUser
{
    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($usuario = $request->user()) {
            $this->auditoria->establecerUsuarioActual($usuario->id);
        }

        return $next($request);
    }
}
