<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edición de un usuario por el Administrador.
 */
class GuardarUsuarioRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:20', Rule::unique('usuario', 'ci')->ignore($id)],
            'registro' => ['nullable', 'string', 'max:20', Rule::unique('usuario', 'registro')->ignore($id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('usuario', 'email')->ignore($id)],
            'telefono' => ['nullable', 'string', 'max:30'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
            'activo' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('rol', 'codigo')->where('activo', true)],
            'carreras' => ['nullable', 'array'],
            'carreras.*' => ['integer', 'distinct', 'exists:carrera,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Asigne al menos un rol al usuario.',
            'ci.unique' => 'Ya existe un usuario con ese C.I.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'registro.unique' => 'Ya existe un usuario con ese Registro Universitario.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombres' => 'nombres', 'apellidos' => 'apellidos', 'ci' => 'C.I.', 'registro' => 'Registro Universitario',
            'email' => 'correo', 'telefono' => 'teléfono', 'cargo' => 'cargo', 'password' => 'contraseña',
            'roles' => 'roles', 'roles.*' => 'rol', 'carreras' => 'carreras', 'carreras.*' => 'carrera',
        ];
    }
}
