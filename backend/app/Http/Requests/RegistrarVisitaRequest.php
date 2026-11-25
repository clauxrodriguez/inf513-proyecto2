<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ruta' => ['required', 'string', 'max:150', 'starts_with:/'],
            'titulo_pagina' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ruta' => 'ruta de la página',
            'titulo_pagina' => 'título de la página',
        ];
    }
}
