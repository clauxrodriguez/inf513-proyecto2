<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Usuarios con sus roles (usuario_rol) y carreras (usuario_carrera).
 */
class UsuarioRepository
{
    /** Roles que firman digitalmente y reciben certificado de firma. */
    public const ROLES_FIRMANTES = ['DIRECTOR_CARRERA', 'DECANO', 'ADMISIONES', 'DAGA', 'VICERRECTOR', 'RECTOR'];

    public function buscar(int $id): ?User
    {
        return User::find($id);
    }

    public function buscarPorEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * Usuarios con sus códigos de rol y de carrera agregados.
     */
    public function listar(): Collection
    {
        return DB::table('usuario as u')
            ->select(
                'u.id', 'u.nombres', 'u.apellidos', 'u.ci', 'u.registro', 'u.email', 'u.telefono', 'u.cargo', 'u.activo', 'u.fecha_creacion',
                DB::raw("COALESCE((SELECT json_agg(r.codigo ORDER BY r.id) FROM usuario_rol ur JOIN rol r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id), '[]') as roles"),
                DB::raw("COALESCE((SELECT json_agg(uc.carrera_id) FROM usuario_carrera uc WHERE uc.usuario_id = u.id), '[]') as carreras"),
                DB::raw("(SELECT string_agg(c.nombre, ', ') FROM usuario_carrera uc JOIN carrera c ON c.id = uc.carrera_id WHERE uc.usuario_id = u.id) as carreras_nombre")
            )
            ->orderBy('u.id')
            ->get()
            ->map(function (object $usuario): object {
                $usuario->roles = json_decode($usuario->roles, true);
                $usuario->carreras = json_decode($usuario->carreras, true);

                return $usuario;
            });
    }

    public function rolesActivos(): Collection
    {
        return DB::table('rol')->where('activo', true)->orderBy('id')->get(['codigo', 'nombre']);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): User
    {
        return User::create($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(User $usuario, array $datos): User
    {
        $usuario->update($datos);

        return $usuario->fresh();
    }

    /**
     * @param  list<string>  $codigosRol
     */
    public function sincronizarRoles(User $usuario, array $codigosRol): void
    {
        $usuario->roles()->sync(DB::table('rol')->whereIn('codigo', $codigosRol)->pluck('id')->all());
    }

    /**
     * @param  list<int>  $carreraIds
     */
    public function sincronizarCarreras(User $usuario, array $carreraIds): void
    {
        $usuario->carreras()->sync($carreraIds);
    }

    /**
     * @return list<string>
     */
    public function codigosDeRol(int $usuarioId): array
    {
        return DB::table('usuario_rol as ur')->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $usuarioId)->pluck('r.codigo')->all();
    }

    /**
     * Usuarios activos que tienen el rol indicado.
     */
    public function conRol(string $rol): Collection
    {
        return DB::table('usuario as u')
            ->join('usuario_rol as ur', 'ur.usuario_id', '=', 'u.id')
            ->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->where('r.codigo', $rol)
            ->where('u.activo', true)
            ->select('u.id', 'u.nombres', 'u.apellidos', 'u.email', 'u.cargo')
            ->orderBy('u.id')
            ->get();
    }

    /**
     * Decanos activos ordenados por la prelación (antigüedad) de su facultad: el primero suple al Vicerrector.
     */
    public function decanosPorPrelacion(): Collection
    {
        return DB::table('usuario as u')
            ->join('usuario_rol as ur', 'ur.usuario_id', '=', 'u.id')
            ->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->join('usuario_carrera as uc', 'uc.usuario_id', '=', 'u.id')
            ->join('carrera as c', 'c.id', '=', 'uc.carrera_id')
            ->join('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->where('r.codigo', 'DECANO')
            ->where('u.activo', true)
            ->selectRaw('DISTINCT ON (u.id) u.id, u.nombres, u.apellidos, u.email, f.nombre as facultad, f.orden_prelacion')
            ->orderBy('u.id')
            ->orderBy('f.orden_prelacion')
            ->get()
            ->sortBy('orden_prelacion')
            ->values();
    }

    /**
     * Usuarios activos con algún rol firmante, con sus roles y la facultad de su primera carrera.
     */
    public function firmantes(): Collection
    {
        return DB::table('usuario as u')
            ->where('u.activo', true)
            ->whereExists(fn ($consulta) => $consulta->selectRaw('1')->from('usuario_rol as ur')->join('rol as r', 'r.id', '=', 'ur.rol_id')
                ->whereColumn('ur.usuario_id', 'u.id')->whereIn('r.codigo', self::ROLES_FIRMANTES))
            ->select(
                'u.id', 'u.nombres', 'u.apellidos', 'u.email', 'u.cargo',
                DB::raw('(SELECT string_agg(r.codigo, \', \' ORDER BY r.id) FROM usuario_rol ur JOIN rol r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id) as roles'),
                DB::raw('(SELECT f.nombre FROM usuario_carrera uc JOIN carrera c ON c.id = uc.carrera_id JOIN facultad f ON f.id = c.facultad_id WHERE uc.usuario_id = u.id ORDER BY f.orden_prelacion LIMIT 1) as facultad')
            )
            ->orderBy('u.apellidos')
            ->get();
    }
}
