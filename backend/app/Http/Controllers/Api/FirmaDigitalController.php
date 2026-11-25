<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmarFirmaRequest;
use App\Services\FirmaDigitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Firma digital con Jacobitus en las tres etapas (ORIGEN, DESPACHO, RECTORADO): preparación de los PDF,
 * confirmación del lote co-firmado y registro del certificado del token del firmante.
 */
class FirmaDigitalController extends Controller
{
    public function __construct(private FirmaDigitalService $firmas) {}

    public function preparar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'etapa' => ['required', Rule::in(FirmaDigitalService::ETAPAS)],
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ], ['ids.required' => 'Seleccione al menos un documento para firmar.'], ['etapa' => 'etapa de firma']);

        return response()->json($this->firmas->preparar($request->user(), $datos['etapa'], array_map('intval', $datos['ids'])));
    }

    public function confirmarLote(ConfirmarFirmaRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $resultado = $this->firmas->confirmarLote(
            $request->user(), $datos['etapa'], $datos['documentos'], $datos['modalidad'] === 'REMOTA', $datos['ubicacion'] ?? null, $request->ip(),
        );

        $mensaje = $resultado['total'] === 1 ? 'Firma digital verificada y registrada.' : "{$resultado['total']} firmas verificadas y registradas en un solo lote.";
        if (! empty($resultado['ingresados'])) {
            $mensaje .= ' Completaron su cadena de firmas e ingresaron a Ventanilla: '.implode(', ', $resultado['ingresados']).'.';
        }

        return response()->json(['message' => $mensaje, ...$resultado]);
    }

    public function registrarCertificado(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'numero_serie' => ['required', 'string', 'max:80'],
            'emisor' => ['required', 'string', 'max:150'],
            'fecha_emision' => ['required', 'date'],
            'fecha_expiracion' => ['required', 'date', 'after:fecha_emision'],
        ], ['fecha_expiracion.after' => 'La fecha de expiración del certificado debe ser posterior a su emisión.'], [
            'numero_serie' => 'número de serie', 'emisor' => 'emisor', 'fecha_emision' => 'fecha de emisión', 'fecha_expiracion' => 'fecha de expiración',
        ]);

        $this->firmas->registrarCertificado($request->user(), $datos);

        return response()->json(['message' => "Certificado N° {$datos['numero_serie']} registrado como su certificado de firma activo."], 201);
    }
}
