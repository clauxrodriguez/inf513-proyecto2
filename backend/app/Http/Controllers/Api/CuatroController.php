<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarObservacionRequest;
use App\Services\AuditoriaService;
use App\Services\FlujoTramiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU4 - Enrutar flujo, guía procesal, observaciones y bitácora.
 */
class CuatroController extends Controller
{
    public function __construct(
        private FlujoTramiteService $flujo,
        private AuditoriaService $auditoria,
    ) {}

    public function guia(): JsonResponse
    {
        return response()->json($this->flujo->guia());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->flujo->detalle($request->user(), $id));
    }

    public function registrarObservacion(RegistrarObservacionRequest $request, int $id): JsonResponse
    {
        $resultado = $this->flujo->registrarObservacion($request->user(), $id, $request->validated());

        return response()->json([
            'message' => match ($resultado['etapa']) {
                'DESPACHO' => 'Expediente devuelto desde despacho con observación. El SLA queda detenido hasta su subsanación.',
                'ORIGEN' => 'Observación registrada en origen. El estudiante será notificado para subsanarla.',
                default => 'Observación registrada. El SLA queda detenido hasta su subsanación.',
            },
            'observacion_id' => $resultado['observacion_id'],
        ], 201);
    }

    public function subsanarObservacion(Request $request, int $observacionId): JsonResponse
    {
        $data = $request->validate(
            ['archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']],
            ['archivo.mimes' => 'El respaldo debe ser un PDF o una imagen JPG o PNG.', 'archivo.max' => 'El respaldo no puede superar los 10 MB.'],
        );

        $estado = $this->flujo->subsanarObservacion($request->user(), $observacionId, $data['archivo'] ?? null);

        return response()->json([
            'message' => match ($estado) {
                'EN_REVISION' => 'Subsanación registrada. El trámite volvió a revisión en Ventanilla.',
                'EN_FIRMA_ORIGEN' => 'Subsanación registrada. El trámite volvió a la firma en origen.',
                default => 'Subsanación registrada. Quedan observaciones pendientes.',
            },
            'estado' => $estado,
        ]);
    }

    public function actualizarRequisito(Request $request, int $id, int $requisitoId): JsonResponse
    {
        $data = $request->validate(['cumplido' => ['required', 'boolean']]);

        $this->flujo->actualizarRequisito($request->user(), $id, $requisitoId, (bool) $data['cumplido']);

        return response()->json(['message' => 'Estado del requisito actualizado.']);
    }

    public function iniciarRevision(int $id): JsonResponse
    {
        $this->flujo->iniciarRevision($id);

        return response()->json(['message' => 'Trámite recibido: inició la revisión de requisitos.']);
    }

    public function archivoDocumento(Request $request, int $documentoId): BinaryFileResponse
    {
        return $this->enLinea($this->flujo->archivoDocumento($request->user(), $documentoId));
    }

    public function archivoExpediente(Request $request, int $expedienteId): BinaryFileResponse
    {
        return $this->enLinea($this->flujo->archivoExpediente($request->user(), $expedienteId));
    }

    /**
     * @param  array{ruta: string, nombre: string}  $archivo
     */
    private function enLinea(array $archivo): BinaryFileResponse
    {
        return response()->file($archivo['ruta'], [
            'Content-Disposition' => 'inline; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function enviarADespacho(int $id): JsonResponse
    {
        $this->flujo->enviarADespacho($id);

        return response()->json(['message' => 'Revisión confirmada. El expediente se envió a la bandeja de despacho.']);
    }

    public function bitacora(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tramite_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->auditoria->bitacora(
            isset($data['tramite_id']) ? (int) $data['tramite_id'] : null,
            (int) ($data['per_page'] ?? 50)
        ));
    }
}
