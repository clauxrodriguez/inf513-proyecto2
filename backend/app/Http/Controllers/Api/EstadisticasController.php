<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BusquedaService;
use App\Services\EstadisticasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard, reportes estadísticos (negocio y tráfico web) y búsqueda global.
 */
class EstadisticasController extends Controller
{
    public function __construct(
        private EstadisticasService $estadisticas,
        private BusquedaService $busqueda,
    ) {}

    public function resumen(Request $request): JsonResponse
    {
        return response()->json($this->estadisticas->resumen($request->user()));
    }

    public function reporte(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
        ]);

        return response()->json($this->estadisticas->reporte($datos['desde'] ?? null, $datos['hasta'] ?? null));
    }

    public function buscar(Request $request): JsonResponse
    {
        $datos = $request->validate(
            ['q' => ['required', 'string', 'min:2', 'max:60']],
            ['q.min' => 'Escriba al menos 2 caracteres para buscar.', 'q.required' => 'Escriba lo que desea buscar.'],
        );

        return response()->json($this->busqueda->buscar($request->user(), $datos['q']));
    }
}
