<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bitácora de accesos web: registra ACCESO_RECURSO por cada petición a la API.
 * Se escribe al terminar la petición, fuera de su transacción, para que no se pierda si esta se revierte.
 * El login y el logout registran su propio evento (LOGIN_OK, LOGIN_FALLIDO, LOGOUT).
 */
class RegistrarAccesoWeb
{
    /** @var list<string> */
    private const RUTAS_CON_EVENTO_PROPIO = ['api/login', 'api/logout'];

    public function __construct(private AuthService $auth) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethod('OPTIONS') || in_array($request->path(), self::RUTAS_CON_EVENTO_PROPIO, true)) {
            return;
        }

        // Patrón de la ruta (api/tramites/{id}) para que el ranking agrupe por recurso y no por id
        $ruta = '/'.($request->route()?->uri() ?? $request->path());

        $this->auth->registrarAccesoARecurso(
            Auth::guard('sanctum')->id(),
            $ruta,
            $request->method(),
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        );
    }
}
