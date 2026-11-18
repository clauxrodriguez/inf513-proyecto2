<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU7 - Monitoreo SLA (72 h hábiles), desglose por fase, alertas y parametrización.
 */
class SlaController extends Controller
{
    public function __construct(private SlaService $sla) {}

    public function monitor(Request $request): JsonResponse
    {
        return response()->json($this->sla->monitor($request->boolean('incluir_atendidas')));
    }

    public function desglose(int $tramiteId): JsonResponse
    {
        return response()->json($this->sla->desglose($tramiteId));
    }

    public function atenderAlerta(Request $request, int $alertaId): JsonResponse
    {
        $this->sla->atenderAlerta($alertaId, $request->user()->id);

        return response()->json(['message' => 'Alerta SLA marcada como atendida.']);
    }

    public function configuracion(): JsonResponse
    {
        return response()->json($this->sla->configuracion());
    }

    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['nullable', Rule::in(SlaService::TIPOS_TRAMITE)],
            'plazo_horas' => ['required', 'numeric', 'min:1', 'max:9999'],
            'porcentaje_alerta' => ['required', 'numeric', 'between:1,99'],
        ]);

        $this->sla->guardarConfiguracion($data['tipo'] ?? null, (float) $data['plazo_horas'], (float) $data['porcentaje_alerta']);

        return response()->json([
            'message' => 'Parámetros SLA '.($data['tipo'] ?? 'generales').' actualizados. El semáforo se recalcula de inmediato.',
            'configuracion' => $this->sla->configuracion(),
        ]);
    }

    public function eliminarConfiguracion(string $tipo): JsonResponse
    {
        $this->sla->eliminarConfiguracion($tipo);

        return response()->json([
            'message' => "Se eliminó la configuración específica de {$tipo}: se aplica la configuración general.",
            'configuracion' => $this->sla->configuracion(),
        ]);
    }
}
