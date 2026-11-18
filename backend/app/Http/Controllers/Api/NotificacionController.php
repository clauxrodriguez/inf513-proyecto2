<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RF-19 - Bandeja de notificaciones del usuario autenticado.
 */
class NotificacionController extends Controller
{
    public function __construct(private NotificacionService $notificaciones) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notificaciones->bandeja($request->user()->id));
    }

    public function marcarLeida(Request $request, int $id): JsonResponse
    {
        $this->notificaciones->marcarLeida($id, $request->user()->id);

        return response()->json(['message' => 'Notificación marcada como leída.']);
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $total = $this->notificaciones->marcarTodasLeidas($request->user()->id);

        return response()->json(['message' => "{$total} notificación(es) marcada(s) como leída(s)."]);
    }
}
