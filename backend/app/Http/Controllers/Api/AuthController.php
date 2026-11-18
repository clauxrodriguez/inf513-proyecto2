<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        return response()->json($this->auth->iniciarSesion($credenciales['email'], $credenciales['password'], $this->origen($request)));
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json(['user' => $usuario, 'menus' => $this->auth->menus($usuario)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->cerrarSesion($request->user(), $this->origen($request));

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function registrarVisita(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ruta' => ['required', 'string', 'max:150'],
            'titulo_pagina' => ['required', 'string', 'max:120'],
        ]);

        return response()->json($this->auth->registrarVisita($data['ruta'], $data['titulo_pagina']));
    }

    /**
     * @return array{ip: ?string, user_agent: ?string}
     */
    private function origen(Request $request): array
    {
        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
