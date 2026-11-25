<?php

namespace App\Http\Requests;

use App\Services\FirmaDigitalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lote de PDF co-firmados por Jacobitus que el navegador devuelve al backend.
 */
class ConfirmarFirmaRequest extends FormRequest
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
            'etapa' => ['required', Rule::in(FirmaDigitalService::ETAPAS)],
            'documentos' => ['required', 'array', 'min:1', 'max:50'],
            'documentos.*.id' => ['required', 'integer', 'distinct'],
            'documentos.*.pdf_base64' => ['required', 'string'],
            'modalidad' => ['required', Rule::in(['PRESENCIAL', 'REMOTA'])],
            'ubicacion' => ['required_if:modalidad,REMOTA', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentos.required' => 'No se recibieron documentos firmados.',
            'documentos.*.pdf_base64.required' => 'Falta el PDF firmado de uno de los documentos.',
            'ubicacion.required_if' => 'Indique su ubicación actual para la firma remota.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['etapa' => 'etapa de firma', 'modalidad' => 'modalidad de firma', 'ubicacion' => 'ubicación'];
    }
}
