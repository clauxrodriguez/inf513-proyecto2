<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Firma digital en origen, individual o por lote, con el PIN del certificado.
 */
class FirmarOrigenRequest extends FormRequest
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
            'documentos' => ['required', 'array', 'min:1', 'max:50'],
            'documentos.*' => ['required', 'integer', 'distinct'],
            'pin' => ['required', 'string', 'min:6', 'max:64'],
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
            'documentos.required' => 'Seleccione al menos un documento para firmar.',
            'ubicacion.required_if' => 'Indique su ubicación actual para la firma remota.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'documentos' => 'documentos',
            'pin' => 'PIN del certificado',
            'modalidad' => 'modalidad de firma',
            'ubicacion' => 'ubicación',
        ];
    }
}
