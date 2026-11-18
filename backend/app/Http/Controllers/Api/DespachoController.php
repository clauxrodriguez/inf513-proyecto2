<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DespachoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU5 - Despachar, previsualizar legajos y ejecutar firma digital consolidada o remota.
 */
class DespachoController extends Controller
{
    public function __construct(private DespachoService $despacho) {}

    public function bandeja(Request $request): JsonResponse
    {
        return response()->json($this->despacho->bandeja($request->user()));
    }

    /**
     * Visor integrado: PDF (legajo foliado, firmado o sellado) para mostrarlo en línea.
     */
    public function legajo(int $id): BinaryFileResponse
    {
        $documento = $this->despacho->documentoParaVisualizar($id);

        return response()->file($documento['ruta'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento['codigo'].'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function firmar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tramites' => ['required', 'array', 'min:1', 'max:50'],
            'tramites.*' => ['required', 'integer', 'distinct'],
            'pin' => ['required', 'string', 'min:6', 'max:64'],
            'modalidad' => ['required', Rule::in(['PRESENCIAL', 'REMOTA'])],
            'ubicacion' => ['required_if:modalidad,REMOTA', 'nullable', 'string', 'max:120'],
        ], [
            'ubicacion.required_if' => 'La firma remota exige registrar la ubicación desde la que firma.',
        ]);

        $lote = $this->despacho->firmar($request->user(), $data, $request->ip());

        $derivados = $lote['estados']->filter(fn ($estado) => $estado === 'DERIVADO_RECTORADO')->count();
        $nacionales = $lote['estados']->count() - $derivados;
        $partes = array_filter([
            $nacionales ? "{$nacionales} listo(s) para el certificado oficial con QR (CU6)" : null,
            $derivados ? "{$derivados} derivado(s) a Rectorado para el visado previo a Apostilla" : null,
        ]);

        return response()->json([
            'message' => sprintf(
                'Lote #%d firmado digitalmente en calidad %s%s: %d expediente(s). %s.',
                $lote['lote_id'],
                $lote['calidad'],
                $lote['es_remota'] ? ' (firma remota)' : '',
                $lote['total'],
                implode('; ', $partes)
            ),
            'lote_id' => $lote['lote_id'],
            'calidad' => $lote['calidad'],
            'estados' => $lote['estados'],
        ]);
    }

    public function bandejaRectorado(): JsonResponse
    {
        return response()->json($this->despacho->bandejaRectorado());
    }

    public function visar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['observacion' => ['nullable', 'string', 'max:300']]);

        $codigo = $this->despacho->visar($request->user(), $id, $data['observacion'] ?? null);

        return response()->json([
            'message' => "Sello institucional aplicado al expediente {$codigo}. Queda listo para la emisión del certificado con QR y la Apostilla.",
        ]);
    }

    public function verificar(int $id): JsonResponse
    {
        return response()->json($this->despacho->verificar($id));
    }
}
