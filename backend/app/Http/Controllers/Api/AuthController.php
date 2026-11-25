<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarRolRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegistrarVisitaRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesión (login con rol activo, cambio de rol, logout), menú dinámico y contador de visitas.
 */
class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->auth->iniciarSesion(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('rol'),
            $this->origen($request),
        ));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->auth->sesionActual($request->user()));
    }

    public function menu(Request $request): JsonResponse
    {
        return response()->json(['menus' => $this->auth->menu($request->user())]);
    }

    public function cambiarRol(CambiarRolRequest $request): JsonResponse
    {
        return response()->json($this->auth->cambiarRol($request->user(), $request->validated('rol')));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->cerrarSesion($request->user(), $this->origen($request));

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function registrarVisita(RegistrarVisitaRequest $request): JsonResponse
    {
        return response()->json($this->auth->registrarVisita(
            $request->validated('ruta'),
            $request->validated('titulo_pagina'),
        ));
    }

    /**
     * @return array{ip: ?string, user_agent: ?string}
     */
    private function origen(Request $request): array
    {
        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
