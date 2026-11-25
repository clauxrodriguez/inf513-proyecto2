<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarComprobanteRequest;
use App\Http\Requests\RegistrarTramiteRequest;
use App\Services\TramiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU3 - Solicitud de trámites (estudiante) y operaciones de Ventanilla: recibos, verificación de firmas y expediente.
 */
class TramiteController extends Controller
{
    public function __construct(private TramiteService $tramites) {}

    public function catalogos(Request $request): JsonResponse
    {
        return response()->json($this->tramites->catalogos($request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $estados = array_values(array_filter(explode(',', (string) $request->query('estado', ''))));

        return response()->json($this->tramites->listar($request->user(), $estados));
    }

    public function store(RegistrarTramiteRequest $request): JsonResponse
    {
        $tramite = $this->tramites->registrar($request->user(), $request->validated(), $request->file('respaldo'));

        return response()->json([
            'message' => $tramite->tipo === 'LEGALIZACION'
                ? "Trámite {$tramite->codigo} registrado. Las unidades emisoras prepararán y firmarán sus documentos."
                : "Trámite {$tramite->codigo} registrado e ingresado a la Ventanilla del Vicerrectorado.",
            'tramite' => $tramite,
        ], 201);
    }

    public function registrarComprobante(RegistrarComprobanteRequest $request, int $id): JsonResponse
    {
        $tramite = $this->tramites->registrarComprobante($request->user(), $id, $request->validated());

        return response()->json([
            'message' => "Recibo registrado. Importe pagado: Bs {$tramite->importe_pagado} de Bs {$tramite->importe_esperado}.",
            'tramite' => $tramite,
        ], 201);
    }

    public function eliminarComprobante(int $comprobanteId): JsonResponse
    {
        $this->tramites->eliminarComprobante($comprobanteId);

        return response()->json(['message' => 'Recibo eliminado del trámite.']);
    }

    public function compilarExpediente(Request $request, int $id): JsonResponse
    {
        $expediente = $this->tramites->compilarExpediente($request->user(), $id);

        return response()->json([
            'message' => "Expediente compilado (versión {$expediente->version}, {$expediente->total_fojas} fojas).",
            'expediente' => $expediente,
        ], 201);
    }

    public function verificarDocumento(int $documentoId): JsonResponse
    {
        return response()->json($this->tramites->verificarDocumento($documentoId));
    }
}
