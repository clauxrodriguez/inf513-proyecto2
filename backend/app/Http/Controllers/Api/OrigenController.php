<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FirmarOrigenRequest;
use App\Services\OrigenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja facultativa: emisión de documentos y firma digital en cadena en el origen
 * (Carrera, Decanato, Admisiones, DAGA y Vicerrectorado para sus resoluciones).
 */
class OrigenController extends Controller
{
    public function __construct(private OrigenService $origen) {}

    public function bandeja(Request $request): JsonResponse
    {
        return response()->json($this->origen->bandeja($request->user()));
    }

    public function emitir(Request $request, int $documentoId): JsonResponse
    {
        $datos = $request->validate(
            ['archivo' => ['required', 'file', 'mimes:pdf', 'max:10240']],
            ['archivo.required' => 'Seleccione el PDF del documento emitido.', 'archivo.mimes' => 'El documento emitido debe ser un archivo PDF.'],
            ['archivo' => 'documento PDF'],
        );

        $nombre = $this->origen->emitir($request->user(), $documentoId, $datos['archivo']);

        return response()->json(['message' => "Documento «{$nombre}» emitido. Ya puede firmarlo digitalmente."]);
    }

    public function firmar(FirmarOrigenRequest $request): JsonResponse
    {
        $resultado = $this->origen->firmar($request->user(), $request->validated(), $request->ip());

        $mensaje = $resultado['total'] === 1 ? 'Documento firmado digitalmente.' : "{$resultado['total']} documentos firmados en un solo lote.";
        if ($resultado['ingresados'] !== []) {
            $mensaje .= ' Completaron su cadena de firmas e ingresaron a Ventanilla: '.implode(', ', $resultado['ingresados']).'.';
        }

        return response()->json(['message' => $mensaje, ...$resultado]);
    }
}
