<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CertificadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU6 - Emisión de certificados oficiales con QR (Ventanilla) y anulación (Ventanilla y autoridades).
 */
class CertificadoController extends Controller
{
    public function __construct(private CertificadoService $certificados) {}

    public function index(): JsonResponse
    {
        return response()->json($this->certificados->bandeja());
    }

    public function emitir(int $tramiteId): JsonResponse
    {
        $certificado = $this->certificados->emitir($tramiteId);
        $documento = $certificado->tipo === 'CONSTANCIA_DIPLOMADO' ? 'Constancia de descuento' : 'Certificado oficial';
        $emitido = $certificado->tipo === 'CONSTANCIA_DIPLOMADO' ? 'emitida' : 'emitido';

        return response()->json([
            'message' => "{$documento} {$certificado->numero} {$emitido} en PDF/A con QR. El trámite {$certificado->tramite_codigo} quedó CONCLUIDO y se notificó al estudiante.",
            'certificado' => $certificado,
        ], 201);
    }

    public function pdf(int $id): BinaryFileResponse
    {
        $documento = $this->certificados->documento($id);

        return response()->file($documento['ruta'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento['numero'].'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(
            ['motivo_anulacion' => ['required', 'string', 'min:10', 'max:300']],
            ['motivo_anulacion.min' => 'Explique el motivo de la anulación (al menos 10 caracteres).'],
            ['motivo_anulacion' => 'motivo de la anulación'],
        );

        $certificado = $this->certificados->anular($id, $datos['motivo_anulacion']);

        return response()->json([
            'message' => "Certificado {$certificado->numero} ANULADO de forma definitiva. Se notificó al solicitante.",
            'certificado' => $certificado,
        ]);
    }
}
