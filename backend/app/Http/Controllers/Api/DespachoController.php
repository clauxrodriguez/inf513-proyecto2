<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DespachoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU5 - Despacho del Vicerrectorado y firma institucional del Rectorado.
 */
class DespachoController extends Controller
{
    public function __construct(private DespachoService $despacho) {}

    public function bandeja(Request $request): JsonResponse
    {
        return response()->json($this->despacho->bandeja($request->user()));
    }

    /**
     * Visor integrado: PDF vigente del expediente (legajo compilado o ya firmado).
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
        $datos = $request->validate([
            'tramites' => ['required', 'array', 'min:1', 'max:50'],
            'tramites.*' => ['required', 'integer', 'distinct'],
            'pin' => ['required', 'string', 'min:6', 'max:64'],
            'modalidad' => ['required', Rule::in(['PRESENCIAL', 'REMOTA'])],
            'ubicacion' => ['required_if:modalidad,REMOTA', 'nullable', 'string', 'max:120'],
        ], [
            'tramites.required' => 'Seleccione al menos un expediente para firmar.',
            'ubicacion.required_if' => 'La firma remota exige registrar la ubicación desde la que firma.',
        ], ['pin' => 'PIN del certificado', 'modalidad' => 'modalidad de firma', 'ubicacion' => 'ubicación']);

        $lote = $this->despacho->firmar($request->user(), $datos, $request->ip());

        $derivados = $lote['estados']->filter(fn (string $estado) => $estado === 'DERIVADO_RECTORADO')->count();
        $nacionales = $lote['estados']->count() - $derivados;
        $partes = array_filter([
            $nacionales ? "{$nacionales} listo(s) para el certificado oficial con QR" : null,
            $derivados ? "{$derivados} derivado(s) a Rectorado" : null,
        ]);

        return response()->json([
            'message' => sprintf('Lote #%d firmado en calidad %s%s: %d expediente(s). %s.', $lote['lote_id'], $lote['calidad'],
                $lote['es_remota'] ? ' (firma remota)' : '', $lote['total'], implode('; ', $partes)),
            'lote_id' => $lote['lote_id'],
            'estados' => $lote['estados'],
        ]);
    }

    public function bandejaRectorado(Request $request): JsonResponse
    {
        return response()->json($this->despacho->bandejaRectorado($request->user()));
    }

    public function firmarRectorado(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(
            ['pin' => ['required', 'string', 'min:6', 'max:64']],
            [],
            ['pin' => 'PIN del certificado'],
        );

        $codigo = $this->despacho->firmarRectorado($request->user(), $id, $datos, $request->ip());

        return response()->json([
            'message' => "Firma institucional del Rectorado aplicada al expediente {$codigo}. Queda listo para el certificado con QR y la Apostilla.",
        ]);
    }

    public function verificar(int $id): JsonResponse
    {
        return response()->json($this->despacho->verificar($id));
    }
}
