<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TramiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU3 - Gestión de trámites, documentos, requisitos y expediente.
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
        return response()->json($this->tramites->listar($request->user(), $request->filled('estado') ? $request->string('estado')->toString() : null));
    }

    public function store(Request $request): JsonResponse
    {
        $esRecepcion = $request->user()->rol === 'RECEPCION';
        $data = $request->validate([
            'solicitante_id' => [$esRecepcion ? 'required' : 'nullable', 'integer', 'exists:usuario,id'],
            'carrera_id' => ['nullable', 'integer', 'exists:carrera,id'],
            'tipo' => ['required', Rule::in(['LEGALIZACION', 'DESCUENTO_DIPLOMADO'])],
            'destino' => ['required', Rule::in(['NACIONAL', 'EXTERIOR'])],
            'pais_destino' => ['required_if:destino,EXTERIOR', 'nullable', 'string', 'max:80'],
            'institucion_destino' => ['nullable', 'string', 'max:200'],
            'finalidad' => ['nullable', 'string', 'max:255'],
            'prioridad' => ['nullable', Rule::in(['NORMAL', 'ALTA', 'URGENTE'])],

            // LEGALIZACION
            'documentos' => ['required_if:tipo,LEGALIZACION', 'array'],
            'documentos.*.catalogo_documento_id' => ['required', 'integer', 'exists:catalogo_documento,id'],
            'documentos.*.cantidad_paginas' => ['required', 'integer', 'min:1'],
            'documentos.*.cantidad_materias' => ['nullable', 'integer', 'min:1'],
            'documentos.*.emitido_por_uagrm' => ['nullable', 'boolean'],
            'documentos.*.firmas_verificadas' => ['nullable', 'boolean'],
            'documentos.*.nro_valorado' => ['nullable', 'string', 'max:40'],
            'documentos.*.importe_valorado' => ['nullable', 'numeric', 'min:0.01'],

            // DESCUENTO_DIPLOMADO
            'descuento.unidad_postgrado' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:150'],
            'descuento.nombre_diplomado' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:200'],
            'descuento.gestion_diplomado' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:10'],
            'descuento.fecha_inicio_diplomado' => ['nullable', 'date'],
            'descuento.nro_avance_academico' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:20'],
            'descuento.regimen' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', Rule::in(['SEMESTRAL', 'ANUAL'])],
            'descuento.anio_ultima_nota' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'integer', 'min:1950', 'max:2100'],
            'descuento.periodo_ultima_nota' => ['nullable', Rule::in([1, 2, '1', '2'])],
            'descuento.modulo_reprobado' => ['nullable', 'string', 'max:150'],

            // Expediente digital
            'archivos.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $tramite = $this->tramites->registrar($request->user(), $data, $request->file('archivos', []));

        return response()->json([
            'message' => "Trámite {$tramite->codigo} registrado y expediente compilado correctamente.",
            'tramite' => $tramite,
        ], 201);
    }

    public function validarDocumento(Request $request, int $documentoId): JsonResponse
    {
        $data = $request->validate([
            'firmas_verificadas' => ['required', 'boolean'],
            'nro_valorado' => ['nullable', 'string', 'max:40'],
            'importe_valorado' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $this->tramites->validarDocumento($documentoId, $data);

        return response()->json(['message' => 'Documento, valorado y firmas precedentes actualizados.']);
    }
}
