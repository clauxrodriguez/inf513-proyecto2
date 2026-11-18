<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU6 / RF-16 - Portal de seguimiento estudiantil (Registro Universitario + C.I.).
 */
class SeguimientoRepository
{
    /**
     * fn_consultar_seguimiento: estado legible, última actualización, observaciones pendientes,
     * financiamiento y certificado vigente de cada trámite del estudiante.
     */
    public function consultar(string $registro, string $ci): Collection
    {
        return collect(DB::select('SELECT * FROM fn_consultar_seguimiento(?, ?)', [$registro, $ci]));
    }

    /**
     * SF-2: semáforo y horas hábiles consumidas del SLA de 72 h, por código de trámite.
     *
     * @param  iterable<string>  $codigos
     */
    public function slaDe(iterable $codigos): Collection
    {
        return DB::table('v_semaforo_sla')
            ->whereIn('codigo', $codigos)
            ->select('codigo', 'estado', 'semaforo', 'horas_consumidas', 'plazo_horas', 'porcentaje', 'es_final')
            ->get()
            ->keyBy('codigo');
    }

    /**
     * SF-2: pasos del flujo con la fase alcanzada, agrupados por código de trámite.
     *
     * @param  iterable<string>  $codigos
     */
    public function flujoDe(iterable $codigos): Collection
    {
        return DB::table('v_tramite_flujo')->whereIn('codigo', $codigos)->get()->groupBy('codigo');
    }
}
