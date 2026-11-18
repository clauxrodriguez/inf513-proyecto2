<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU1 - Administración de usuarios (rol ADMINISTRADOR, controlado en las rutas) y perfil propio.
 */
class UsuarioController extends Controller
{
    public function __construct(private UsuarioService $usuarios) {}

    public function index(): JsonResponse
    {
        return response()->json($this->usuarios->listado());
    }

    public function store(Request $request): JsonResponse
    {
        $usuario = $this->usuarios->registrar($this->validarDatosUsuario($request));

        return response()->json(['message' => 'Usuario registrado correctamente.', 'usuario' => $usuario], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->usuarios->asegurarExiste($id);
        $usuario = $this->usuarios->actualizar($id, $this->validarDatosUsuario($request, $id));

        return response()->json(['message' => 'Datos del usuario actualizados.', 'usuario' => $usuario]);
    }

    public function toggleEstado(Request $request, int $id): JsonResponse
    {
        $usuario = $this->usuarios->alternarEstado($id, $request->user()->id);

        return response()->json([
            'message' => $usuario->activo ? 'Cuenta activada.' : 'Cuenta desactivada.',
            'activo' => $usuario->activo,
        ]);
    }

    public function actualizarPerfil(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        return response()->json([
            'message' => 'Perfil actualizado correctamente.',
            'user' => $this->usuarios->actualizarPerfil($request->user(), $data),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarDatosUsuario(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:20', Rule::unique('usuario', 'ci')->ignore($id)],
            'registro' => ['nullable', 'string', 'max:20', Rule::unique('usuario', 'registro')->ignore($id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('usuario', 'email')->ignore($id)],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8'],
            'rol' => ['required', Rule::in(UsuarioService::ROLES)],
            'cargo' => ['nullable', Rule::in(UsuarioService::CARGOS)],
            'carrera_id' => ['nullable', 'integer', 'exists:carrera,id'],
            'activo' => ['boolean'],
        ]);
    }
}
