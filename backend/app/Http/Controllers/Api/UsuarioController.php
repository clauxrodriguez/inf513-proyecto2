<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarUsuarioRequest;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU1 - Administración de usuarios y roles (permiso USUARIOS_GESTIONAR) y perfil propio.
 */
class UsuarioController extends Controller
{
    public function __construct(private UsuarioService $usuarios) {}

    public function index(): JsonResponse
    {
        return response()->json($this->usuarios->listado());
    }

    public function store(GuardarUsuarioRequest $request): JsonResponse
    {
        $usuario = $this->usuarios->registrar($request->validated());

        return response()->json(['message' => 'Usuario registrado correctamente.', 'usuario' => $usuario], 201);
    }

    public function update(GuardarUsuarioRequest $request, int $id): JsonResponse
    {
        $usuario = $this->usuarios->actualizar($id, $request->user()->id, $request->validated());

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
        $datos = $request->validate([
            'telefono' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8', 'max:100', 'confirmed'],
        ], ['password.confirmed' => 'La confirmación de la contraseña no coincide.'], ['telefono' => 'teléfono', 'password' => 'contraseña']);

        $usuario = $this->usuarios->actualizarPerfil($request->user(), $datos);

        return response()->json(['message' => 'Perfil actualizado correctamente.', 'user' => $usuario->only(['id', 'nombres', 'apellidos', 'email', 'telefono'])]);
    }
}
