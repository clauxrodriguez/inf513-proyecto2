<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\AccesoRepository;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Autenticación, menú dinámico por rol, bitácora de accesos y contador de visitas.
 */
class AuthService
{
    public function __construct(
        private UsuarioRepository $usuarios,
        private AccesoRepository $accesos,
    ) {}

    /**
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     * @return array{token: string, user: User, menus: Collection}
     */
    public function iniciarSesion(string $email, string $password, array $origen): array
    {
        $usuario = $this->usuarios->buscarPorEmail($email);

        if (! $usuario || ! Hash::check($password, $usuario->password)) {
            $this->registrarAcceso($usuario?->id, $email, 'LOGIN_FALLIDO', '/api/login', $origen);
            throw new ReglaNegocioException('Las credenciales ingresadas no son válidas.', 401);
        }

        if (! $usuario->activo) {
            throw ReglaNegocioException::prohibido('Su cuenta institucional se encuentra inactiva.');
        }

        $this->registrarAcceso($usuario->id, $usuario->email, 'LOGIN_EXITOSO', '/api/login', $origen);

        return [
            'token' => $usuario->createToken('spa-token')->plainTextToken,
            'user' => $usuario,
            'menus' => $this->menus($usuario),
        ];
    }

    /**
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     */
    public function cerrarSesion(User $usuario, array $origen): void
    {
        $this->registrarAcceso($usuario->id, $usuario->email, 'LOGOUT', '/api/logout', $origen);
        $usuario->currentAccessToken()->delete();
    }

    public function menus(User $usuario): Collection
    {
        return $this->accesos->menusPorRol($usuario->rol);
    }

    public function registrarVisita(string $ruta, string $tituloPagina): ?object
    {
        return $this->accesos->registrarVisita($ruta, $tituloPagina);
    }

    /**
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     */
    private function registrarAcceso(?int $usuarioId, string $email, string $accion, string $ruta, array $origen): void
    {
        $this->accesos->registrarAcceso([
            'usuario_id' => $usuarioId,
            'email_intento' => $email,
            'accion' => $accion,
            'ruta' => $ruta,
            'metodo' => 'POST',
            'ip_origen' => $origen['ip'],
            'user_agent' => $origen['user_agent'],
        ]);
    }
}
