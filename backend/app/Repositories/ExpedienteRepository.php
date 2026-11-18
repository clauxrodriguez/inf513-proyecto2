<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expediente digital versionado (trg_expediente_version marca la versión vigente).
 */
class ExpedienteRepository
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): void
    {
        DB::table('expediente')->insert($datos);
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

    public function registrarLegajoCompilado(int $expedienteId, string $rutaPdf, string $hashSha256): ?object
    {
        DB::table('expediente')->where('id', $expedienteId)->update(['ruta_pdf' => $rutaPdf, 'hash_sha256' => $hashSha256]);

        return DB::table('expediente')->where('id', $expedienteId)->first();
    }
}
