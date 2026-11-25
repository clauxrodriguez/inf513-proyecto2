<?php

namespace App\Http\Requests;

use App\Services\FlujoTramiteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarObservacionRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(FlujoTramiteService::TIPOS_OBSERVACION)],
            'descripcion' => ['required', 'string', 'min:5', 'max:500'],
            'tramite_documento_id' => ['nullable', 'integer', 'exists:tramite_documento,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'categoría de la observación',
            'descripcion' => 'descripción',
            'tramite_documento_id' => 'documento relacionado',
        ];
    }
}
