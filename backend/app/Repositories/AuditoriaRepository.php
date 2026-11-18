<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora inalterable con cadena de hash (CU4) y accesos recientes.
 */
class AuditoriaRepository
{
    public function paginarBitacora(?int $tramiteId, int $porPagina): LengthAwarePaginator
    {
        return DB::table('bitacora as b')
            ->leftJoin('usuario as u', 'u.id', '=', 'b.usuario_id')
            ->when($tramiteId !== null, fn ($query) => $query->where('b.tramite_id', $tramiteId))
            ->select('b.*', DB::raw("u.nombres || ' ' || u.apellidos as usuario_nombre"))
            ->orderByDesc('b.id')
            ->paginate($porPagina, ['*'], 'pagina');
    }

    public function accesosRecientes(int $limite = 100): Collection
    {
        return DB::table('bitacora_acceso as a')
            ->leftJoin('usuario as u', 'u.id', '=', 'a.usuario_id')
            ->select('a.*', DB::raw("u.nombres || ' ' || u.apellidos as usuario_nombre"))
            ->orderByDesc('a.id')
            ->limit($limite)
            ->get();
    }

    /**
     * Id del primer registro alterado, o null si la cadena de hash está íntegra.
     */
    public function primerRegistroAlterado(): ?int
    {
        $resultado = DB::selectOne('SELECT fn_verificar_bitacora() AS primer_id_alterado');

        return $resultado->primer_id_alterado === null ? null : (int) $resultado->primer_id_alterado;
    }
}
