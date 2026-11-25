<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeguimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU6 - Endpoints públicos (sin sesión): portal de seguimiento estudiantil y validador de certificados.
 */
class PortalPublicoController extends Controller
{
    public function __construct(private SeguimientoService $seguimiento) {}

    public function seguimiento(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registro' => ['required', 'string', 'max:20'],
            'ci' => ['required', 'string', 'max:20'],
        ], [], ['registro' => 'Registro Universitario', 'ci' => 'C.I.']);

        return response()->json($this->seguimiento->consultar($data['registro'], $data['ci']));
    }

    public function verificar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:64'],
            'hash' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->seguimiento->verificar($data['codigo'], $data['hash'] ?? null));
    }

    public function validarPdf(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'codigo' => ['nullable', 'string', 'max:64'],
        ], [
            'pdf.required' => 'Seleccione el archivo PDF a validar.',
            'pdf.mimes' => 'El archivo debe ser un PDF.',
            'pdf.max' => 'El PDF no puede superar los 20 MB.',
        ]);

        return response()->json($this->seguimiento->validarPdf($data['pdf'], $data['codigo'] ?? null));
    }

    public function descargar(string $codigo): BinaryFileResponse
    {
        $documento = $this->seguimiento->documentoPublico($codigo);

        return response()->file($documento['ruta'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento['numero'].'.pdf"',
        ]);
    }
}
