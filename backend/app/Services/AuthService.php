<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\AccesoRepository;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticación con rol activo, menú dinámico por permisos, bitácora de accesos y contador de visitas.
 */
class AuthService
{
    public function __construct(
        private UsuarioRepository $usuarios,
        private AccesoRepository $accesos,
    ) {}

    /**
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     * @return array{token: string, user: array<string, mixed>, roles: Collection, permisos: list<string>, menus: list<array<string, mixed>>}
     */
    public function iniciarSesion(string $email, string $password, ?string $rolSolicitado, array $origen): array
    {
        $usuario = $this->usuarios->buscarPorEmail($email);

        if (! $usuario || ! Hash::check($password, $usuario->getAuthPassword())) {
            $this->registrarAcceso($usuario?->id, $email, 'LOGIN_FALLIDO', '/api/login', 'POST', $origen);
            throw new ReglaNegocioException('Las credenciales ingresadas no son válidas.', 401);
        }

        if (! $usuario->activo) {
            $this->registrarAcceso($usuario->id, $email, 'LOGIN_FALLIDO', '/api/login', 'POST', $origen);
            throw ReglaNegocioException::prohibido('Su cuenta institucional se encuentra inactiva.');
        }

        $roles = $this->accesos->rolesDeUsuario($usuario->id);
        if ($roles->isEmpty()) {
            $this->registrarAcceso($usuario->id, $email, 'LOGIN_FALLIDO', '/api/login', 'POST', $origen);
            throw ReglaNegocioException::prohibido('Su cuenta no tiene un rol asignado. Consulte con el administrador.');
        }

        $rol = $rolSolicitado ?? $roles->first()->codigo;
        $this->exigirRolAsignado($roles, $rol);

        $this->registrarAcceso($usuario->id, $usuario->email, 'LOGIN_OK', '/api/login', 'POST', $origen);

        return [
            'token' => $usuario->createToken('spa-token', [User::PREFIJO_ROL.$rol])->plainTextToken,
            ...$this->sesion($usuario, $rol, $roles),
        ];
    }

    /**
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     */
    public function cerrarSesion(User $usuario, array $origen): void
    {
        $this->registrarAcceso($usuario->id, $usuario->email, 'LOGOUT', '/api/logout', 'POST', $origen);

        $token = $usuario->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
     * Cambia el rol activo de la sesión (para usuarios con más de un rol) sin volver a iniciar sesión.
     *
     * @return array{user: array<string, mixed>, roles: Collection, permisos: list<string>, menus: list<array<string, mixed>>}
     */
    public function cambiarRol(User $usuario, string $rol): array
    {
        $roles = $this->accesos->rolesDeUsuario($usuario->id);
        $this->exigirRolAsignado($roles, $rol);

        $token = $usuario->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['abilities' => [User::PREFIJO_ROL.$rol]])->save();
        }

        return $this->sesion($usuario, $rol, $roles);
    }

    /**
     * Datos de la sesión: usuario con su rol activo, roles disponibles, permisos y menú.
     *
     * @return array{user: array<string, mixed>, roles: Collection, permisos: list<string>, menus: list<array<string, mixed>>}
     */
    public function sesionActual(User $usuario): array
    {
        return $this->sesion($usuario, $this->rolDeSesion($usuario));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function menu(User $usuario): array
    {
        return $this->armarMenu($this->accesos->opcionesMenuDeRol($this->rolDeSesion($usuario)));
    }

    /**
     * @return list<string>
     */
    public function permisos(User $usuario): array
    {
        return $this->accesos->permisosDeRol($this->rolDeSesion($usuario));
    }

    /**
     * Rol activo guardado en el token; si el token no lo tiene, el primer rol del usuario.
     */
    public function rolDeSesion(User $usuario): string
    {
        $rol = $usuario->rolActivo() ?? $this->accesos->rolesDeUsuario($usuario->id)->first()?->codigo;

        if ($rol === null) {
            throw ReglaNegocioException::prohibido('Su cuenta no tiene un rol asignado. Consulte con el administrador.');
        }

        return $rol;
    }

    /**
     * @return object{ruta: string, titulo_pagina: string, visitas: int}
     */
    public function registrarVisita(string $ruta, string $tituloPagina): object
    {
        return $this->accesos->registrarVisita($ruta, $tituloPagina);
    }

    /**
     * Acceso a un recurso de la API (lo invoca el middleware al terminar cada petición).
     *
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     */
    public function registrarAccesoARecurso(?int $usuarioId, string $ruta, string $metodo, array $origen): void
    {
        $this->registrarAcceso($usuarioId, null, 'ACCESO_RECURSO', $ruta, $metodo, $origen);
    }

    /**
     * @return array{user: array<string, mixed>, roles: Collection, permisos: list<string>, menus: list<array<string, mixed>>}
     */
    private function sesion(User $usuario, string $rol, ?Collection $roles = null): array
    {
        return [
            'user' => [
                'id' => $usuario->id,
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'ci' => $usuario->ci,
                'registro' => $usuario->registro,
                'email' => $usuario->email,
                'telefono' => $usuario->telefono,
                'cargo' => $usuario->cargo,
                'rol' => $rol,
            ],
            'roles' => $roles ?? $this->accesos->rolesDeUsuario($usuario->id),
            'permisos' => $this->accesos->permisosDeRol($rol),
            'menus' => $this->armarMenu($this->accesos->opcionesMenuDeRol($rol)),
        ];
    }

    private function exigirRolAsignado(Collection $roles, string $rol): void
    {
        if (! $roles->contains('codigo', $rol)) {
            throw new ReglaNegocioException('El rol seleccionado no está asignado a su cuenta.');
        }
    }

    /**
     * Convierte las opciones planas en árbol por padre_id. Una opción agrupadora (sin ruta)
     * solo se incluye si el rol puede ver al menos una de sus opciones hijas.
     *
     * @param  Collection<int, object{id: int, padre_id: ?int, titulo: string, ruta: ?string, icono: ?string, orden: int}>  $opciones
     * @return list<array<string, mixed>>
     */
    private function armarMenu(Collection $opciones): array
    {
        $porPadre = $opciones->groupBy(fn (object $opcion): int => $opcion->padre_id ?? 0);

        $construir = function (int $padreId) use (&$construir, $porPadre): array {
            $nodos = [];

            foreach ($porPadre->get($padreId, collect()) as $opcion) {
                $hijos = $construir($opcion->id);

                if ($opcion->ruta === null && $hijos === []) {
                    continue;
                }

                $nodos[] = [
                    'id' => $opcion->id,
                    'titulo' => $opcion->titulo,
                    'ruta' => $opcion->ruta,
                    'icono' => $opcion->icono,
                    'hijos' => $hijos,
                ];
            }

            return $nodos;
        };

        return $construir(0);
    }

    /**
     * @param  array{ip: ?string, user_agent: ?string}  $origen
     */
    private function registrarAcceso(?int $usuarioId, ?string $email, string $accion, string $ruta, string $metodo, array $origen): void
    {
        $this->accesos->registrarAcceso([
            'usuario_id' => $usuarioId,
            'email_intento' => $email,
            'accion' => $accion,
            'ruta' => mb_substr($ruta, 0, 255),
            'metodo_http' => $metodo,
            'ip_origen' => $origen['ip'],
            'user_agent' => $origen['user_agent'],
        ]);
    }
}
