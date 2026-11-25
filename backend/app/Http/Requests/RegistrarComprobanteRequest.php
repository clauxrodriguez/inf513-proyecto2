<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recibo emitido por Caja/Tesorería que Ventanilla registra en el trámite.
 */
class RegistrarComprobanteRequest extends FormRequest
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
            'codigo_recibo' => ['required', 'string', 'max:30', 'unique:comprobante_caja,codigo_recibo'],
            'concepto' => ['required', Rule::in(['EMISION_ORIGEN', 'LEGALIZACION', 'AVANCE_ACADEMICO'])],
            'importe' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'fecha_pago' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo_recibo.unique' => 'Ese número de recibo ya fue registrado en otro trámite.',
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser posterior a hoy.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'codigo_recibo' => 'número de recibo',
            'concepto' => 'concepto',
            'importe' => 'importe',
            'fecha_pago' => 'fecha de pago',
        ];
    }
}
