<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UsuarioRepository
{
    public function buscar(int $id): ?User
    {
        return User::find($id);
    }

    public function buscarPorEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function listarConCarrera(): Collection
    {
        return DB::table('usuario as u')
            ->leftJoin('carrera as c', 'c.id', '=', 'u.carrera_id')
            ->leftJoin('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->select(
                'u.id', 'u.nombres', 'u.apellidos', 'u.ci', 'u.registro',
                'u.email', 'u.telefono', 'u.direccion', 'u.rol', 'u.cargo',
                'u.carrera_id', 'u.activo',
                'c.nombre as carrera_nombre', 'f.nombre as facultad_nombre'
            )
            ->orderBy('u.id')
            ->get();
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
     * Usuario con el nombre de su facultad (para emitir su certificado de firma).
     */
    public function buscarConFacultad(int $id): ?object
    {
        return DB::table('usuario as u')
            ->leftJoin('carrera as c', 'c.id', '=', 'u.carrera_id')
            ->leftJoin('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->where('u.id', $id)
            ->select('u.*', 'f.nombre as facultad')
            ->first();
    }

    public function vicerrectoresActivos(): Collection
    {
        return DB::table('usuario')
            ->where('rol', 'VICERRECTOR')
            ->where('activo', true)
            ->select('id', 'nombres', 'apellidos', 'email')
            ->get();
    }

    /**
     * Decanos activos ordenados por la prelación estatutaria de su facultad (Art. 46).
     */
    public function decanosActivos(): Collection
    {
        return DB::table('usuario as u')
            ->leftJoin('carrera as c', 'c.id', '=', 'u.carrera_id')
            ->leftJoin('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->where('u.cargo', 'DECANO')
            ->where('u.activo', true)
            ->select('u.id', 'u.nombres', 'u.apellidos', 'u.rol', 'f.nombre as facultad', 'f.orden_prelacion')
            ->orderBy('f.orden_prelacion')
            ->get();
    }

    public function estudiantesActivos(): Collection
    {
        return DB::table('usuario as u')
            ->leftJoin('carrera as c', 'c.id', '=', 'u.carrera_id')
            ->where('u.rol', 'ESTUDIANTE')
            ->where('u.activo', true)
            ->select('u.id', 'u.nombres', 'u.apellidos', 'u.ci', 'u.registro', 'u.carrera_id', 'c.nombre as carrera')
            ->orderBy('u.apellidos')
            ->get();
    }
}
