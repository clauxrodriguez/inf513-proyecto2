<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solicitud de trámite por el estudiante: legalización (documentos del catálogo) o descuento de diplomado.
 */
class RegistrarTramiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['LEGALIZACION', 'DESCUENTO_DIPLOMADO'])],
            'carrera_id' => ['nullable', 'integer', 'exists:carrera,id'],
            'destino' => ['required_if:tipo,LEGALIZACION', 'nullable', Rule::in(['NACIONAL', 'EXTERIOR'])],
            'pais_destino' => ['required_if:destino,EXTERIOR', 'nullable', 'string', 'max:80'],
            'institucion_destino' => ['nullable', 'string', 'max:200'],

            'documentos' => ['required_if:tipo,LEGALIZACION', 'array', 'max:20'],
            'documentos.*.catalogo_documento_id' => ['required', 'integer', 'distinct', 'exists:catalogo_documento,id'],
            'documentos.*.cantidad_paginas' => ['nullable', 'integer', 'min:1', 'max:500'],
            'documentos.*.cantidad_materias' => ['nullable', 'integer', 'min:1', 'max:200'],

            'descuento' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'array'],
            'descuento.unidad_postgrado' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:150'],
            'descuento.nombre_diplomado' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:200'],
            'descuento.nro_avance_academico' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'string', 'max:20'],
            'descuento.fecha_ultima_nota' => ['required_if:tipo,DESCUENTO_DIPLOMADO', 'nullable', 'date', 'before_or_equal:today'],

            'respaldo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentos.required_if' => 'Agregue al menos un documento a legalizar.',
            'documentos.*.catalogo_documento_id.distinct' => 'Cada documento del catálogo solo puede agregarse una vez.',
            'pais_destino.required_if' => 'Indique el país de destino para una legalización al exterior.',
            'descuento.fecha_ultima_nota.before_or_equal' => 'La fecha de la última nota no puede ser posterior a hoy.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de trámite',
            'carrera_id' => 'carrera',
            'destino' => 'destino',
            'pais_destino' => 'país de destino',
            'institucion_destino' => 'institución de destino',
            'documentos' => 'documentos',
            'documentos.*.catalogo_documento_id' => 'documento',
            'documentos.*.cantidad_paginas' => 'cantidad de páginas',
            'documentos.*.cantidad_materias' => 'cantidad de materias',
            'descuento.unidad_postgrado' => 'unidad de postgrado',
            'descuento.nombre_diplomado' => 'nombre del diplomado',
            'descuento.nro_avance_academico' => 'número de avance académico',
            'descuento.fecha_ultima_nota' => 'fecha de la última nota',
            'respaldo' => 'respaldo PDF',
        ];
    }
}
