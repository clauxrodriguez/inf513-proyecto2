<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\CatalogoRepository;
use App\Repositories\UnidadDeTrabajo;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Collection;

/**
 * CU1 - Gestión de usuarios (roles en usuario_rol, carreras en usuario_carrera) y perfil propio.
 */
class UsuarioService
{
    /** Roles que exigen al menos una carrera asignada (definen el alcance de su bandeja o su solicitud). */
    private const ROLES_CON_CARRERA = ['ESTUDIANTE', 'DIRECTOR_CARRERA', 'DECANO'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private UsuarioRepository $usuarios,
        private CatalogoRepository $catalogos,
    ) {}

    /**
     * @return array{usuarios: Collection, carreras: Collection, roles: Collection}
     */
    public function listado(): array
    {
        return [
            'usuarios' => $this->usuarios->listar(),
            'carreras' => $this->catalogos->carreras(),
            'roles' => $this->usuarios->rolesActivos(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos  incluye roles (códigos) y carreras (ids)
     */
    public function registrar(array $datos): User
    {
        $this->validarReglas($datos);

        return $this->transaccion->ejecutar(function () use ($datos): User {
            $usuario = $this->usuarios->crear($this->datosPersonales($datos));
            $this->usuarios->sincronizarRoles($usuario, $datos['roles']);
            $this->usuarios->sincronizarCarreras($usuario, $datos['carreras'] ?? []);

            return $usuario;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(int $id, int $actorId, array $datos): User
    {
        $usuario = $this->obtener($id);
        $this->validarReglas($datos);
        if ($id === $actorId && ! in_array('ADMIN', $datos['roles'], true)) {
            throw new ReglaNegocioException('No puede quitarse a sí mismo el rol de Administrador.');
        }

        return $this->transaccion->ejecutar(function () use ($usuario, $datos): User {
            $usuario = $this->usuarios->actualizar($usuario, $this->datosPersonales($datos));
            $this->usuarios->sincronizarRoles($usuario, $datos['roles']);
            $this->usuarios->sincronizarCarreras($usuario, $datos['carreras'] ?? []);

            return $usuario;
        });
    }

    public function alternarEstado(int $id, int $actorId): User
    {
        $usuario = $this->obtener($id);
        if ($usuario->id === $actorId) {
            throw new ReglaNegocioException('No puede desactivar su propia cuenta.');
        }

        return $this->usuarios->actualizar($usuario, ['activo' => ! $usuario->activo]);
    }

    /**
     * @param  array{telefono?: ?string, password?: ?string}  $datos
     */
    public function actualizarPerfil(User $usuario, array $datos): User
    {
        $cambios = ['telefono' => $datos['telefono'] ?? null];
        if (! empty($datos['password'])) {
            $cambios['password_hash'] = $datos['password'];
        }

        return $this->usuarios->actualizar($usuario, $cambios);
    }

    public function asegurarExiste(int $id): void
    {
        $this->obtener($id);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosPersonales(array $datos): array
    {
        $personales = collect($datos)->only(['nombres', 'apellidos', 'ci', 'registro', 'email', 'telefono', 'cargo', 'activo'])->all();
        if (! empty($datos['password'])) {
            $personales['password_hash'] = $datos['password'];
        }

        return $personales;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function validarReglas(array $datos): void
    {
        $roles = $datos['roles'];
        if (in_array('ESTUDIANTE', $roles, true) && empty($datos['registro'])) {
            throw new ReglaNegocioException('Un estudiante debe tener Registro Universitario.');
        }
        if (in_array('ESTUDIANTE', $roles, true) && count($roles) > 1) {
            throw new ReglaNegocioException('El rol Estudiante no puede combinarse con roles administrativos.');
        }
        if (! in_array('ESTUDIANTE', $roles, true) && empty($datos['cargo'])) {
            throw new ReglaNegocioException('Las autoridades y el personal administrativo deben tener un cargo institucional.');
        }
        if (array_intersect($roles, self::ROLES_CON_CARRERA) !== [] && empty($datos['carreras'])) {
            throw new ReglaNegocioException('Los roles Estudiante, Director de Carrera y Decano requieren al menos una carrera asignada.');
        }
    }

    private function obtener(int $id): User
    {
        return $this->usuarios->buscar($id) ?? throw ReglaNegocioException::noEncontrado('El usuario no existe.');
    }
}
