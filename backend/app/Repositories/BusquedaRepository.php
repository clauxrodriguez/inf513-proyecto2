<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda global: trámites (código, estudiante, C.I., registro) y certificados (número).
 */
class BusquedaRepository
{
    public function tramites(string $texto, ?int $solicitanteId, int $limite = 8): Collection
    {
        $patron = '%'.addcslashes($texto, '%_\\').'%';

        return DB::table('tramite as t')
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->join('usuario as u', 'u.id', '=', 't.solicitante_id')
            ->when($solicitanteId !== null, fn ($q) => $q->where('t.solicitante_id', $solicitanteId))
            ->where(fn ($q) => $q->where('t.codigo', 'ILIKE', $patron)
                ->orWhere('u.ci', 'ILIKE', $patron)
                ->orWhere('u.registro', 'ILIKE', $patron)
                ->orWhereRaw("u.nombres || ' ' || u.apellidos ILIKE ?", [$patron]))
            ->select('t.id', 't.codigo', 't.estado', 'tt.nombre as tipo', DB::raw("u.nombres || ' ' || u.apellidos as estudiante"), 'u.ci')
            ->orderByDesc('t.id')
            ->limit($limite)
            ->get();
    }

    public function certificados(string $texto, int $limite = 5): Collection
    {
        return DB::table('certificado_oficial as co')
            ->join('tramite as t', 't.id', '=', 'co.tramite_id')
            ->where('co.numero', 'ILIKE', '%'.addcslashes($texto, '%_\\').'%')
            ->select('co.id', 'co.numero', 'co.estado', 'co.tramite_id', 't.codigo as tramite_codigo')
            ->orderByDesc('co.id')
            ->limit($limite)
            ->get();
    }
}
