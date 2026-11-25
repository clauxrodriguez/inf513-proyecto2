<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DelegacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CU2 - Delegación de autoridad, certificados digitales e indicadores (permiso DELEGACION_GESTIONAR).
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
        $datos = $request->validate([
            'titular_id' => ['required', 'integer', 'exists:usuario,id'],
            'suplente_id' => ['required', 'integer', 'exists:usuario,id', 'different:titular_id'],
            'nro_resolucion' => ['required', 'string', 'max:50'],
            'fecha_resolucion' => ['required', 'date', 'before_or_equal:today'],
            'motivo' => ['required', Rule::in(['VIAJE', 'COMISION_OFICIAL', 'AUSENCIA', 'VACACION', 'OTRO'])],
            'detalle_motivo' => ['nullable', 'string', 'max:255', 'required_if:motivo,OTRO'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ], [
            'suplente_id.different' => 'El suplente no puede ser el mismo titular.',
            'detalle_motivo.required_if' => 'Describa el motivo cuando selecciona «Otro».',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la de inicio.',
            'fecha_resolucion.before_or_equal' => 'La fecha de la resolución no puede ser posterior a hoy.',
        ], [
            'titular_id' => 'titular', 'suplente_id' => 'suplente', 'nro_resolucion' => 'número de resolución',
            'fecha_resolucion' => 'fecha de la resolución', 'motivo' => 'motivo', 'detalle_motivo' => 'detalle del motivo',
            'fecha_inicio' => 'fecha de inicio', 'fecha_fin' => 'fecha de fin',
        ]);

        $id = $this->delegaciones->registrar($datos, $request->user()->id);

        return response()->json(['message' => 'Delegación de autoridad registrada y activada.', 'id' => $id], 201);
    }

    public function revocar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(
            ['motivo_revocacion' => ['required', 'string', 'min:5', 'max:255']],
            [],
            ['motivo_revocacion' => 'motivo de la revocación'],
        );

        $this->delegaciones->revocar($id, $datos['motivo_revocacion']);

        return response()->json(['message' => 'Delegación revocada: la firma vuelve a ser exclusiva del titular.']);
    }

    public function registrarCertificado(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario_id' => ['required', 'integer', 'exists:usuario,id'],
            'dias_vigencia' => ['required', 'integer', 'between:30,1825'],
            'pin' => ['required', 'string', 'min:6', 'max:64', 'confirmed'],
        ], [
            'pin.confirmed' => 'La confirmación del PIN no coincide.',
            'dias_vigencia.between' => 'La vigencia debe estar entre 30 y 1825 días.',
        ], ['usuario_id' => 'titular del certificado', 'dias_vigencia' => 'vigencia', 'pin' => 'PIN']);

        $emitido = $this->delegaciones->emitirCertificado((int) $datos['usuario_id'], (int) $datos['dias_vigencia'], $datos['pin']);

        return response()->json([
            'message' => "Certificado digital N° {$emitido['numeroSerie']} emitido por {$emitido['emisor']} y activado. Entregue el PIN al titular: lo necesita para firmar.",
            'numero_serie' => $emitido['numeroSerie'],
        ], 201);
    }

    /**
     * Certificado raíz de la AC de prueba (para marcarlo como confiable en el lector de PDF).
     */
    public function certificadoRaiz(): Response
    {
        return response($this->delegaciones->certificadoRaiz(), 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="ac-raiz-prueba-uagrm.crt"',
        ]);
    }
}
