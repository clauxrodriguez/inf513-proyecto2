<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expediente digital versionado: una sola versión vigente por trámite.
 */
class ExpedienteRepository
{
    /**
     * Registra una nueva versión vigente (versión anterior + 1) y deja las anteriores como históricas.
     * Debe ejecutarse dentro de una transacción.
     *
     * @param  array{tramite_id: int, ruta_pdf: string, total_fojas: int, hash_sha256: string, compilado_por: ?int}  $datos
     */
    public function crearVersion(array $datos): object
    {
        DB::table('tramite')->where('id', $datos['tramite_id'])->lockForUpdate()->first(['id']);
        $version = (int) DB::table('expediente')->where('tramite_id', $datos['tramite_id'])->max('version') + 1;

        DB::table('expediente')->where('tramite_id', $datos['tramite_id'])->update(['vigente' => false]);
        $id = DB::table('expediente')->insertGetId([...$datos, 'version' => $version, 'vigente' => true]);

        return DB::table('expediente')->where('id', $id)->first();
    }

    public function buscar(int $id): ?object
    {
        return DB::table('expediente')->where('id', $id)->first();
    }

    public function vigente(int $tramiteId): ?object
    {
        return DB::table('expediente')->where('tramite_id', $tramiteId)->where('vigente', true)->first();
    }

    /**
     * @param  iterable<int>  $tramiteIds
     */
    public function vigentesDe(iterable $tramiteIds): Collection
    {
        return DB::table('expediente')->whereIn('tramite_id', $tramiteIds)->where('vigente', true)->get()->keyBy('tramite_id');
    }

    public function historialDe(int $tramiteId): Collection
    {
        return DB::table('expediente')->where('tramite_id', $tramiteId)->orderByDesc('version')->get();
    }
}
