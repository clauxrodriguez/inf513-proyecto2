<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\CatalogoRepository;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Collection;

/**
 * CU1 - Gestión de usuarios y perfil propio.
 */
class UsuarioService
{
    public const ROLES = ['ADMINISTRADOR', 'VICERRECTOR', 'RECTORADO', 'RECEPCION', 'SUPLENTE', 'ESTUDIANTE'];

    public const CARGOS = ['VICERRECTOR', 'RECTOR', 'DECANO', 'ENCARGADO_LEGALIZACIONES', 'FUNCIONARIO_VENTANILLA'];

    public function __construct(
        private UsuarioRepository $usuarios,
        private CatalogoRepository $catalogos,
    ) {}

    /**
     * @return array{usuarios: Collection, carreras: Collection}
     */
    public function listado(): array
    {
        return [
            'usuarios' => $this->usuarios->listarConCarrera(),
            'carreras' => $this->catalogos->carreras(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function registrar(array $datos): User
    {
        $this->validarReglasInstitucionales($datos);

        return $this->usuarios->crear($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(int $id, array $datos): User
    {
        $usuario = $this->obtener($id);
        $this->validarReglasInstitucionales($datos);
        if (empty($datos['password'])) {
            unset($datos['password']);
        }

        return $this->usuarios->actualizar($usuario, $datos);
    }

    public function alternarEstado(int $id, int $actorId): User
    {
        $usuario = $this->obtener($id);
        if ($usuario->id === $actorId) {
            throw new ReglaNegocioException('No puede desactivar su propia cuenta activa.');
        }

        return $this->usuarios->actualizar($usuario, ['activo' => ! $usuario->activo]);
    }

    /**
     * @param  array{telefono?: ?string, direccion?: ?string, password?: ?string}  $datos
     */
    public function actualizarPerfil(User $usuario, array $datos): User
    {
        if (empty($datos['password'])) {
            unset($datos['password']);
        }

        return $this->usuarios->actualizar($usuario, $datos);
    }

    public function asegurarExiste(int $id): void
    {
        $this->obtener($id);
    }

    private function obtener(int $id): User
    {
        return $this->usuarios->buscar($id) ?? throw ReglaNegocioException::noEncontrado('El usuario no existe.');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function validarReglasInstitucionales(array $datos): void
    {
        $rol = $datos['rol'];
        $cargo = $datos['cargo'] ?? null;

        if ($rol === 'ESTUDIANTE' && ! empty($cargo)) {
            throw new ReglaNegocioException('Un usuario con rol ESTUDIANTE no puede tener cargo administrativo.');
        }
        if ($rol !== 'ESTUDIANTE' && empty($cargo)) {
            throw new ReglaNegocioException('Los usuarios administrativos o autoridades deben tener un cargo institucional.');
        }
        if ($rol === 'SUPLENTE' && ($cargo !== 'DECANO' || empty($datos['carrera_id']))) {
            throw new ReglaNegocioException('El rol SUPLENTE corresponde exclusivamente a un DECANO con carrera/facultad asignada.');
        }
        if ($rol === 'RECEPCION' && ! in_array($cargo, ['ENCARGADO_LEGALIZACIONES', 'FUNCIONARIO_VENTANILLA'], true)) {
            throw new ReglaNegocioException('El rol RECEPCION requiere el cargo ENCARGADO_LEGALIZACIONES o FUNCIONARIO_VENTANILLA.');
        }
    }
}
