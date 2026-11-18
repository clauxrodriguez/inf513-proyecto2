<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DelegacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU2 - Delegación de autoridad, certificados digitales e indicadores.
 * Registrar/revocar delegaciones y emitir certificados: solo RECTORADO (controlado en las rutas).
 */
class DelegacionController extends Controller
{
    public function __construct(private DelegacionService $delegaciones) {}

    public function index(): JsonResponse
    {
        return response()->json($this->delegaciones->panel());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'titular_id' => ['required', 'integer', 'exists:usuario,id'],
            'suplente_id' => ['required', 'integer', 'exists:usuario,id', 'different:titular_id'],
            'tipo_resolucion' => ['required', Rule::in(['RECTORAL', 'ICU'])],
            'nro_resolucion' => ['required', 'string', 'max:50'],
            'fecha_resolucion' => ['required', 'date'],
            'motivo' => ['required', Rule::in(['VIAJE', 'COMISION_OFICIAL', 'AUSENCIA', 'VACACION', 'LICENCIA', 'BAJA_MEDICA', 'ASUME_RECTORADO', 'OTRO'])],
            'detalle_motivo' => ['nullable', 'string', 'max:255'],
            'justificacion_prelacion' => ['nullable', 'string', 'max:255'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'archivo_resolucion' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ], [
            'archivo_resolucion.required' => 'La suplencia carece de sustento legal: adjunte el PDF de la resolución firmada.',
        ]);
        $resolucion = $request->file('archivo_resolucion');
        unset($data['archivo_resolucion']);

        $id = $this->delegaciones->registrar($data, $resolucion, $request->user()->id);

        return response()->json(['message' => 'Delegación de autoridad registrada y activada correctamente.', 'id' => $id], 201);
    }

    public function revocar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo_revocacion' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->delegaciones->revocar($id, $data['motivo_revocacion'], $request->user()->id);

        return response()->json(['message' => 'Delegación revocada y exclusividad restituida al titular.']);
    }

    public function registrarCertificado(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usuario_id' => ['required', 'integer', 'exists:usuario,id'],
            'dias_vigencia' => ['required', 'integer', 'between:30,1825'],
            'pin' => ['required', 'string', 'min:6', 'max:64', 'confirmed'],
        ], [
            'pin.confirmed' => 'La confirmación del PIN no coincide.',
        ]);

        $emitido = $this->delegaciones->emitirCertificado((int) $data['usuario_id'], (int) $data['dias_vigencia'], $data['pin']);

        return response()->json([
            'message' => "Certificado digital N° {$emitido['numeroSerie']} emitido por la {$emitido['emisor']} y activado. "
                .'Entregue el PIN al titular: lo necesitará para firmar.',
            'numero_serie' => $emitido['numeroSerie'],
        ], 201);
    }

    public function resolucion(int $id): BinaryFileResponse
    {
        $archivo = $this->delegaciones->archivoResolucion($id);

        return response()->file($archivo['ruta'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Certificado raíz de la AC de prueba (para marcarlo como confiable en Adobe Reader).
     */
    public function certificadoRaiz(): Response
    {
        return response($this->delegaciones->certificadoRaiz(), 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="ac-raiz-prueba-uagrm.crt"',
        ]);
    }
}
